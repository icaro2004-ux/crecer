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

/**
 * Firma del post GRATIS. Las marcas NO pagadas llevan una firma de Crecer al pie
 * del caption (reconoce de dónde salió el trabajo + trae referidos); las marcas
 * PAGADAS salen 100% limpias, sin firma. Cambia el texto en CRECER_FIRMA (una línea)
 * o en config.local.php.
 */
if (!defined('CRECER_FIRMA')) define('CRECER_FIRMA', '— Hecho con Crecer 🌱 encuentraloahora.com');
function firma_publicar(PDO $pdo, int $marca_id, string $caption): string {
    if (CRECER_FIRMA === '' || marca_es_pagada($pdo, $marca_id)) return $caption;
    return rtrim($caption) . "\n\n" . CRECER_FIRMA;
}

/** ¿Puede GENERAR con la IA? (trial o pagado, con acceso vigente). El tope
 *  del trial se chequea aparte en cada endpoint. */
function puede_generar(?array $su): bool { return suscripcion_activa($su); }

/**
 * ¿El entorno es LOCAL/dev? FAIL-SECURE: solo es local si APP_ENV lo dice
 * EXPLÍCITAMENTE. Si APP_ENV falta, está mal escrito o dice 'prod' → se trata
 * como PRODUCCIÓN. Así un flag de dev colado en prod NUNCA baja las defensas.
 */
function crecer_entorno_local(): bool {
    return defined('APP_ENV') && in_array(strtolower((string)APP_ENV), ['local','dev','development','testing'], true);
}

/**
 * ¿Esta cuenta activa en MODO PRUEBA (sin correo de confirmación y sin Stripe)? Dos formas:
 *  - CRECER_DEV_ACTIVAR=true  → todas, PERO SOLO en entorno local (crecer_entorno_local()).
 *    En producción este flag se IGNORA: los usuarios reales SIEMPRE confirman correo y pagan.
 *  - CRECER_TEST_EMAILS='a@x.com,b@y.com' → solo esos emails, en cualquier entorno (seguro en PROD).
 *    Este es el mecanismo correcto para cuentas de prueba en producción.
 */
function activacion_de_prueba(?string $email = null): bool {
    if (defined('CRECER_DEV_ACTIVAR') && CRECER_DEV_ACTIVAR && crecer_entorno_local()) return true;
    if ($email !== null && $email !== '' && defined('CRECER_TEST_EMAILS') && CRECER_TEST_EMAILS !== '') {
        $lista = array_map('trim', explode(',', strtolower((string)CRECER_TEST_EMAILS)));
        if (in_array(strtolower($email), $lista, true)) return true;
    }
    return false;
}

/**
 * ¿Es una CUENTA DE JURADO? (evaluadores del XPRIZE)
 *
 * Existe para separar dos cosas que CRECER_TEST_EMAILS tenía mezcladas y que no
 * son lo mismo:
 *   · cuentas de prueba DEL FUNDADOR → necesitan generación ilimitada y entran
 *     a los laboratorios internos;
 *   · cuenta del JURADO → sus credenciales se PUBLICAN en un repo público, así
 *     que no puede tener nada ilimitado ni tocar herramientas que gastan API.
 *
 * Lo que da esta lista, y nada más: ver la bitácora técnica (evidencia.php) de
 * SU PROPIA marca, que es la prueba del criterio #2. El acceso al app se lo da
 * su suscripción de cortesía, con la MISMA cuota de imágenes que un cliente que
 * paga. Si alguien saca las credenciales del README, el daño tiene techo.
 *
 * Config:  define('CRECER_JUEZ_EMAILS', 'jurado@ejemplo.com, otro@ejemplo.com');
 */
function es_cuenta_juez(?string $email = null): bool {
    if ($email === null || $email === '') return false;
    if (!defined('CRECER_JUEZ_EMAILS') || CRECER_JUEZ_EMAILS === '') return false;
    $lista = array_map('trim', explode(',', strtolower((string)CRECER_JUEZ_EMAILS)));
    return in_array(strtolower($email), $lista, true);
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

// ============================================================
//  CUPO DE PUBLICACIONES — límite semanal de posts que salen a las
//  redes. Cada publicación (o re-publicación) consume 1. Ventana de
//  7 días (rolling), como el tope de imágenes. ~20/mes.
// ============================================================
if (!defined('CRECER_POSTS_SEMANA')) define('CRECER_POSTS_SEMANA', 5);

// ── CUOTA MENSUAL DE IMÁGENES IA (decidida 2026-08-10 con Manuel) ──
// 40/mes por marca: TODO lo que la IA pinta (arte desde cero, realce de fotos,
// slides de carrusel, pruebas de logo, plan semanal automático). Se renueva el
// día 1; NO se acumula. Las fotos/videos propios del dueño NO descuentan.
// El conteo sale de crecer_ia_log (cada imagen ya se registra con su modelo).
if (!defined('CRECER_IMG_MES')) define('CRECER_IMG_MES', 40);
const CRECER_IMG_MODELOS = ['gpt-image-1', 'dall-e-3', 'gemini-3-pro-image', 'gemini-2.5-flash-image'];

function img_cuota_usadas(PDO $pdo, int $marca_id): int {
    try {
        $in = "'" . implode("','", CRECER_IMG_MODELOS) . "'";
        $q = $pdo->prepare(
            "SELECT COUNT(*) FROM crecer_ia_log
              WHERE marca_id=? AND estado='ok' AND modelo IN ({$in})
                AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')");
        $q->execute([$marca_id]);
        return (int)$q->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

function img_cuota_estado(PDO $pdo, int $marca_id, bool $exento = false): array {
    //  EL LIBRO MANDA CUANDO EXISTE. Desde la Fase 3C la cuota se RESERVA en
    //  crecer_img_cuota_cubo antes de llamar al proveedor; contar a posteriori
    //  sobre crecer_ia_log era la version vieja y no veia las reservas en vuelo
    //  ni las liberaciones. Las cinco pantallas que ya llaman aqui pasan a leer
    //  el libro sin tocar una linea de su codigo.
    require_once __DIR__ . '/cuota_imagenes.php';
    if (CuotaImg::disponible($pdo)) return CuotaImg::estado($pdo, $marca_id, $exento);

    $lim    = (int)CRECER_IMG_MES;
    $usadas = img_cuota_usadas($pdo, $marca_id);
    return [
        'usadas'    => $usadas,
        'limite'    => $lim,
        'restantes' => max(0, $lim - $usadas),
        'lleno'     => (!$exento && $usadas >= $lim),
        'exento'    => $exento,
        'reset'     => date('d/m', strtotime('first day of next month')),
    ];
}

/** Posts publicados por la marca en los últimos 7 días (cuentan re-publicaciones). */
function cupo_posts_usados(PDO $pdo, int $marca_id): int {
    try {
        $q = $pdo->prepare(
            "SELECT COUNT(*) FROM crecer_publicacion_cupo
             WHERE marca_id=? AND created_at >= (NOW() - INTERVAL 7 DAY)");
        $q->execute([$marca_id]);
        return (int)$q->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/** Registra una publicación que consume cupo (idempotencia NO — cada llamada = 1). */
function cupo_registrar_publicacion(PDO $pdo, int $marca_id, ?int $contenido_id, string $via = 'api'): void {
    try {
        $pdo->prepare(
            "INSERT INTO crecer_publicacion_cupo (marca_id, contenido_id, via) VALUES (?,?,?)"
        )->execute([$marca_id, $contenido_id, in_array($via,['api','manual'],true)?$via:'api']);
    } catch (Throwable $e) { /* si falta la tabla, no bloquear la publicación */ }
}

/**
 * Estado del cupo para pintar/gatear. $exento=true (admin) => nunca bloquea.
 * @return array ['usados'=>int,'limite'=>int,'restantes'=>int,'lleno'=>bool,'exento'=>bool,'reset'=>?string]
 */
function cupo_estado(PDO $pdo, int $marca_id, bool $exento = false): array {
    $lim = (int)CRECER_POSTS_SEMANA;
    $usados = cupo_posts_usados($pdo, $marca_id);
    $reset = null;
    if ($usados >= $lim && !$exento) {
        // Cuándo se libera un espacio: el más viejo de los últimos 7 días + 7 días.
        try {
            $q = $pdo->prepare(
                "SELECT created_at FROM crecer_publicacion_cupo
                 WHERE marca_id=? AND created_at >= (NOW() - INTERVAL 7 DAY)
                 ORDER BY created_at ASC LIMIT 1");
            $q->execute([$marca_id]);
            if ($f = $q->fetchColumn()) $reset = date('d/m', strtotime($f . ' +7 day'));
        } catch (Throwable $e) {}
    }
    return [
        'usados'    => $usados,
        'limite'    => $lim,
        'restantes' => max(0, $lim - $usados),
        'lleno'     => (!$exento && $usados >= $lim),
        'exento'    => $exento,
        'reset'     => $reset,
    ];
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
