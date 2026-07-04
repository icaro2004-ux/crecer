<?php
// ============================================================
//  CRECER — Suscripción y gating por plan
//  includes/suscripcion.php
//
//  Fuente de verdad del gating. Lee crecer_suscripciones +
//  crecer_planes. NO toca Stripe (eso vive en el checkout/webhook).
//  Funciones puras: reciben $pdo, no asumen sesión.
// ============================================================

// Jerarquía de planes (mayor número = más alto).
const CRECER_PLAN_RANK = ['crecer' => 1, 'despegar' => 2];

// Plan mínimo que exige cada sección/feature del panel.
// 'feature' coincide con las 'key' del nav en _shell.php.
const CRECER_FEATURE_PLAN = [
    'inicio'      => 'crecer',
    'contenido'   => 'crecer',
    'graficas'    => 'crecer',
    'marca'       => 'crecer',
    'ordenes'     => 'crecer',
    'calendario'  => 'crecer',
    'clientela'   => 'crecer',   // ver la lista = Crecer; la retención con IA se gatea dentro de la página (Despegar)
    'cuentas'     => 'despegar',
    'analitica'   => 'despegar',
    'whatsapp'    => 'despegar',
    'publicacion' => 'despegar',
];

/** Suscripción vigente de la marca (con datos del plan) o null. */
function suscripcion_de_marca(PDO $pdo, int $marca_id): ?array {
    $s = $pdo->prepare(
        "SELECT su.*, p.slug AS plan_slug, p.nombre AS plan_nombre, p.precio_mensual
           FROM crecer_suscripciones su
           LEFT JOIN crecer_planes p ON p.id = su.plan_id
          WHERE su.marca_id = ?"
    );
    $s->execute([$marca_id]);
    return $s->fetch() ?: null;
}

/** ¿La suscripción da acceso AHORA? (trial, activa, o cancelada
 *  pero todavía dentro del período ya pagado.) */
function suscripcion_activa(?array $su): bool {
    if (!$su) return false;
    $hoy = date('Y-m-d');
    switch ($su['estado']) {
        case 'trial':
        case 'activa':
            return true;
        case 'cancelada': // mantiene acceso hasta que termine lo pagado
            return !empty($su['periodo_fin']) && $su['periodo_fin'] >= $hoy;
        default:          // vencida, pausada
            return false;
    }
}

/** Plan efectivo de la marca ('crecer'|'despegar') o null si sin acceso. */
function plan_de_marca(PDO $pdo, int $marca_id): ?string {
    $su = suscripcion_de_marca($pdo, $marca_id);
    return suscripcion_activa($su) ? ($su['plan_slug'] ?? null) : null;
}

/** ¿Suscripción PAGADA? Desbloquea descargar/copiar/exportar y quita el
 *  watermark. El trial puede VER y GENERAR, pero NO llevarse nada. */
function es_pagado(?array $su): bool {
    if (!$su) return false;
    if ($su['estado'] === 'activa') return true;
    if ($su['estado'] === 'cancelada') return !empty($su['periodo_fin']) && $su['periodo_fin'] >= date('Y-m-d');
    return false; // trial, incompleta, vencida, pausada
}
function marca_es_pagada(PDO $pdo, int $marca_id): bool {
    return es_pagado(suscripcion_de_marca($pdo, $marca_id));
}

/** ¿Puede GENERAR con la IA? (trial o pagado, con acceso vigente). El tope
 *  del trial se chequea aparte en cada endpoint. */
function puede_generar(?array $su): bool { return suscripcion_activa($su); }

/**
 * ¿Esta cuenta activa en MODO PRUEBA (sin Stripe)? Dos formas:
 *  - CRECER_DEV_ACTIVAR=true en el config  → todas (solo para LOCAL/pruebas).
 *  - CRECER_TEST_EMAILS='a@x.com,b@y.com'   → solo esos emails (seguro en PROD:
 *    los usuarios reales siguen pasando por Stripe).
 */
function activacion_de_prueba(?string $email = null): bool {
    if (defined('CRECER_DEV_ACTIVAR') && CRECER_DEV_ACTIVAR) return true;
    if ($email !== null && $email !== '' && defined('CRECER_TEST_EMAILS') && CRECER_TEST_EMAILS !== '') {
        $lista = array_map('trim', explode(',', strtolower((string)CRECER_TEST_EMAILS)));
        if (in_array(strtolower($email), $lista, true)) return true;
    }
    return false;
}

/** ¿Está en trial (genera pero con candado)? */
function en_trial(?array $su): bool { return $su && $su['estado'] === 'trial'; }

// ── CUOTA GRATIS (sin pagar) ────────────────────────────────
// Modelo: el que no paga recibe 1 post de muestra (1 imagen + 1 caption).
// El LOGO nunca es gratis (0). Pagar desbloquea todo.
const CRECER_FREE = ['imagen' => 1, 'caption' => 1, 'logo' => 0];

/** Cuántas generaciones de este tipo lleva la marca (para la cuota gratis). */
function generaciones_usadas(PDO $pdo, int $marca_id, string $tipo): int {
    if ($tipo === 'logo') {
        $q = $pdo->prepare("SELECT COUNT(*) FROM crecer_logos WHERE marca_id=?");
    } elseif ($tipo === 'imagen') {
        // Solo arte generado por IA (las fotos propias subidas no cuentan).
        $q = $pdo->prepare("SELECT COUNT(*) FROM crecer_graficas WHERE marca_id=? AND (copy_text IS NULL OR copy_text <> '(imagen propia)')");
    } else { // caption
        $q = $pdo->prepare("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id=? AND caption IS NOT NULL AND caption<>''");
    }
    $q->execute([$marca_id]);
    return (int)$q->fetchColumn();
}

/**
 * ¿Puede GENERAR algo de este tipo?  ['imagen'|'caption'|'logo']
 *  - Pagado  → true (el endpoint aplica SU límite normal del plan).
 *  - Gratis  → solo dentro de la cuota CRECER_FREE.
 * Devuelve ['ok'=>bool, 'pagado'=>bool, 'motivo'=>'' | 'logo_pago' | 'cuota'].
 */
function puede_generar_tipo(PDO $pdo, int $marca_id, string $tipo): array {
    $pagado = marca_es_pagada($pdo, $marca_id);
    if ($pagado) return ['ok' => true, 'pagado' => true, 'motivo' => ''];
    $free = CRECER_FREE[$tipo] ?? 0;
    if ($free === 0) return ['ok' => false, 'pagado' => false, 'motivo' => 'logo_pago'];
    if (generaciones_usadas($pdo, $marca_id, $tipo) < $free) return ['ok' => true, 'pagado' => false, 'motivo' => ''];
    return ['ok' => false, 'pagado' => false, 'motivo' => 'cuota'];
}

/** ¿Este plan alcanza para esta feature? (plan puede venir de plan_de_marca) */
function marca_puede(?string $plan, string $feature): bool {
    if ($plan === null) return false;
    $req = CRECER_FEATURE_PLAN[$feature] ?? 'crecer';
    return (CRECER_PLAN_RANK[$plan] ?? 0) >= (CRECER_PLAN_RANK[$req] ?? 99);
}

/** Días que faltan para que termine el trial (o null si no está en trial). */
function trial_dias_restantes(?array $su): ?int {
    if (!$su || $su['estado'] !== 'trial' || empty($su['trial_fin'])) return null;
    $dias = (int)ceil((strtotime($su['trial_fin']) - time()) / 86400);
    return max(0, $dias);
}

/** Etiqueta corta del estado para el panel (ej. "Crecer · prueba: 5 días"). */
function suscripcion_etiqueta(?array $su): string {
    if (!suscripcion_activa($su)) return 'Sin plan activo';
    $nombre = $su['plan_nombre'] ?? 'Crecer';
    if ($su['estado'] === 'trial') {
        $d = trial_dias_restantes($su);
        return "$nombre · prueba: {$d} día" . ($d === 1 ? '' : 's');
    }
    if ($su['estado'] === 'cancelada') {
        return "$nombre · termina " . date('d/m/Y', strtotime($su['periodo_fin']));
    }
    return "$nombre · activo";
}
