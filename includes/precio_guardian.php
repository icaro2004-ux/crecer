<?php
// ============================================================
//  CRECER — GUARDIÁN DEL PRECIO  ·  includes/precio_guardian.php
//
//  CR-F02b (2026-08-02). El 2 de agosto se confirmó que la aplicación anunciaba
//  $39 y el Price de Stripe cobraba $49. El defecto vivió semanas sin que nada lo
//  detectara, y no fue descuido: el precio que se PROMETE (crecer_planes.precio_mensual)
//  y el que se COBRA (el Price remoto de Stripe) son dos números en dos sistemas
//  distintos, y nadie los cruzaba nunca.
//
//  Arreglar aquel Price cierra ese caso. Esto cierra la CLASE de defecto: antes de
//  abrir un checkout se compara lo prometido contra lo que Stripe va a cobrar, y si
//  no cuadra —o si no se puede comprobar— NO se cobra.
//
//  Principio: ante la duda, no se cobra. Un cliente que no puede pagar hoy es un
//  problema; un cliente al que se le cobra de más es una traición.
//
//  Este módulo NO modifica precios, NO cae a otro Price y NO "arregla" nada solo.
//  Solo mira y decide si se puede seguir.
// ============================================================

require_once __DIR__ . '/stripe.php';

/** Estados posibles. 'no_verificable' NO es lo mismo que 'no coincide'. */
if (!defined('PRECIO_OK'))    define('PRECIO_OK', 'coincide');
if (!defined('PRECIO_MAL'))   define('PRECIO_MAL', 'no_coincide');
if (!defined('PRECIO_DUDA'))  define('PRECIO_DUDA', 'no_verificable');

/** ¿La llave configurada es de producción? (para exigir coherencia de entorno). */
function precio_entorno_live(): bool {
    return defined('STRIPE_SECRET_KEY') && str_starts_with((string)STRIPE_SECRET_KEY, 'sk_live_');
}

/**
 * Compara lo que la app promete con lo que Stripe cobraría.
 *
 * @param array $plan  fila de crecer_planes (necesita precio_mensual, stripe_price_id, moneda?)
 * @param callable|null $traer_price  cómo se lee el Price remoto. Se inyecta para poder
 *        probar los escenarios (archivado, moneda mala, timeout, inexistente) sin tocar
 *        Stripe ni gastar. Por defecto, la API de verdad.
 * @return array{estado:string, ok:bool, motivo:string, detalle:array}
 *         motivo = causa técnica COMPLETA (para el log y para admin), nunca para el cliente.
 */
function precio_verificar(array $plan, ?callable $traer_price = null): array {
    $slug      = (string)($plan['slug'] ?? '?');
    $esperado  = (int) round(((float)($plan['precio_mensual'] ?? 0)) * 100);   // a centavos
    $moneda    = strtolower((string)($plan['moneda'] ?? 'usd'));
    $price_id  = trim((string)($plan['stripe_price_id'] ?? ''));

    $no = fn(string $estado, string $motivo, array $det = []) =>
        ['estado' => $estado, 'ok' => false, 'motivo' => $motivo, 'detalle' => $det];

    $traer_price = $traer_price ?? fn(string $id) => stripe_api('GET', 'prices/' . rawurlencode($id));

    if (!stripe_configurado())  return $no(PRECIO_DUDA, "plan {$slug}: Stripe no está configurado");
    if ($price_id === '')       return $no(PRECIO_MAL,  "plan {$slug}: no tiene stripe_price_id");
    if ($esperado <= 0)         return $no(PRECIO_MAL,  "plan {$slug}: precio_mensual inválido ({$esperado} centavos)");

    // Recuperar el Price remoto. Si Stripe no contesta, es DUDA — no se asume que está bien.
    try {
        $price = (array)$traer_price($price_id);
    } catch (Throwable $e) {
        return $no(PRECIO_DUDA, "plan {$slug}: no se pudo consultar el Price {$price_id} — "
                                . mb_substr($e->getMessage(), 0, 200));
    }
    if (empty($price['id'])) {
        return $no(PRECIO_DUDA, "plan {$slug}: Stripe no devolvió el Price {$price_id}");
    }

    $real_monto    = (int)($price['unit_amount'] ?? -1);
    $real_moneda   = strtolower((string)($price['currency'] ?? ''));
    $real_intervalo= (string)($price['recurring']['interval'] ?? '');
    $real_activo   = !empty($price['active']);
    $real_live     = !empty($price['livemode']);
    $det = ['price_id' => $price_id, 'esperado_centavos' => $esperado, 'stripe_centavos' => $real_monto,
            'moneda' => $real_moneda, 'intervalo' => $real_intervalo,
            'activo' => $real_activo, 'livemode' => $real_live];

    // Cada comprobación por separado: el motivo tiene que decir QUÉ falló, no "no cuadra".
    $fallas = [];
    if ($real_monto !== $esperado) {
        $fallas[] = sprintf('la app anuncia $%s y Stripe cobraría $%s',
            number_format($esperado / 100, 2), number_format(max($real_monto, 0) / 100, 2));
    }
    if ($real_moneda !== $moneda)          $fallas[] = "moneda {$real_moneda}, se esperaba {$moneda}";
    if ($real_intervalo !== 'month')       $fallas[] = "intervalo '{$real_intervalo}', se esperaba 'month'";
    if (!$real_activo)                     $fallas[] = 'el Price está archivado en Stripe';
    if ($real_live !== precio_entorno_live()) {
        $fallas[] = 'entorno incoherente: el Price es de ' . ($real_live ? 'live' : 'test')
                  . ' y la llave configurada es de ' . (precio_entorno_live() ? 'live' : 'test');
    }

    if ($fallas) return $no(PRECIO_MAL, "plan {$slug}: " . implode(' · ', $fallas), $det);

    return ['estado' => PRECIO_OK, 'ok' => true, 'motivo' => '', 'detalle' => $det];
}

/**
 * Avisa al fundador SIN inundarlo. Reusa la incidencia del Ayudante, que ya trae
 * ventana anti-spam de 6 h por (código, referencia) y sale por email + texto.
 * Si el Ayudante no está disponible, cae a error_log y no rompe el checkout.
 */
function precio_alertar(PDO $pdo, array $plan, array $r): void {
    $slug = (string)($plan['slug'] ?? '?');
    try {
        require_once __DIR__ . '/ayudante.php';
        if (function_exists('ayudante_reportar')) {
            ayudante_reportar($pdo, null, [
                'origen'      => 'ayudante',
                'codigo'      => 'precio_' . ($r['estado'] === PRECIO_DUDA ? 'no_verificable' : 'no_coincide'),
                'ref_tipo'    => 'plan',
                'ref_id'      => (int)($plan['id'] ?? 0),
                'severidad'   => 'alta',
                'titulo'      => 'Checkout BLOQUEADO: el precio de Stripe no cuadra (' . $slug . ')',
                'detalle'     => $r['motivo'] . "\n" . json_encode($r['detalle'], JSON_UNESCAPED_UNICODE),
                'diagnostico' => 'Un cliente intentó pagar y se bloqueó el cobro para no cobrarle algo '
                               . 'distinto a lo que se le prometió. No se creó ninguna sesión de pago ni '
                               . 'se le cobró nada. Hay que cuadrar el Price en Stripe con crecer_planes.',
            ]);
            return;
        }
    } catch (Throwable $e) { /* el aviso nunca puede romper el checkout */ }
    error_log('[crecer precio] ' . $r['motivo']);
}
