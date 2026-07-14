<?php
// ============================================================
//  CRECER — Cliente mínimo de Stripe (sin SDK, solo cURL)
//  includes/stripe.php
//
//  Calca el patrón cURL de ia.php. Habla con la REST API de
//  Stripe (form-encoded). Requiere STRIPE_SECRET_KEY en config.
// ============================================================

class StripeError extends RuntimeException {}

/** ¿Hay llaves de Stripe configuradas? (para degradar con gracia) */
function stripe_configurado(): bool {
    return defined('STRIPE_SECRET_KEY') && STRIPE_SECRET_KEY !== '';
}

/**
 * Llamada cruda a la API de Stripe.
 *  $metodo: 'GET' | 'POST'
 *  $path:   ej. 'checkout/sessions'
 *  $params: array (Stripe acepta notación de corchetes vía http_build_query)
 * Devuelve el JSON decodificado; lanza StripeError en fallo.
 */
function stripe_api(string $metodo, string $path, array $params = []): array {
    if (!stripe_configurado()) {
        throw new StripeError('Stripe no está configurado (falta STRIPE_SECRET_KEY).');
    }
    $url = 'https://api.stripe.com/v1/' . ltrim($path, '/');
    $headers = [
        'Authorization: Bearer ' . STRIPE_SECRET_KEY,
        'Content-Type: application/x-www-form-urlencoded',
    ];
    $ch = curl_init();
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ];
    if (strtoupper($metodo) === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
    } elseif (strtoupper($metodo) === 'DELETE') {
        $opts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
        if ($params) $opts[CURLOPT_URL] = $url . '?' . http_build_query($params);
    } else {
        if ($params) $opts[CURLOPT_URL] = $url . '?' . http_build_query($params);
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch); curl_close($ch);
        throw new StripeError('cURL: ' . $err);
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($resp, true);
    if ($code < 200 || $code >= 300) {
        $msg = $data['error']['message'] ?? substr($resp, 0, 300);
        throw new StripeError("Stripe HTTP $code: $msg");
    }
    return is_array($data) ? $data : [];
}

/**
 * Crea una sesión de Checkout en modo suscripción CON período de prueba.
 * La tarjeta es OBLIGATORIA (Checkout la pide); no se cobra durante el trial.
 *
 *  $price_id    : price_xxx del plan en Stripe (de crecer_planes.stripe_price_id)
 *  $trial_dias  : días de prueba antes del primer cobro
 *  $success_url : a dónde vuelve tras pagar/suscribirse
 *  $cancel_url  : a dónde vuelve si cancela el checkout
 *  $meta        : metadata (marca_id, usuario_id, plan_slug) → vuelve en el webhook
 *  $customer    : stripe_customer_id existente (opcional) o $email para crearlo
 */
function stripe_crear_checkout(string $price_id, int $trial_dias, string $success_url,
                               string $cancel_url, array $meta, ?string $customer = null,
                               ?string $email = null): array {
    $p = [
        'mode'                  => 'subscription',
        'line_items[0][price]'  => $price_id,
        'line_items[0][quantity]' => 1,
        'success_url'           => $success_url,
        'cancel_url'            => $cancel_url,
        'allow_promotion_codes' => 'true',       // para cupón founder/early-adopter
        'locale'                => 'es',
    ];
    if ($trial_dias > 0) {
        // (Opcional) período de prueba con tarjeta en archivo, sin cobro inicial.
        $p['subscription_data[trial_period_days]'] = $trial_dias;
        $p['subscription_data[trial_settings][end_behavior][missing_payment_method]'] = 'cancel';
        $p['payment_method_collection'] = 'always';
    }
    foreach ($meta as $k => $v) {
        $p["metadata[$k]"] = $v;
        $p["subscription_data[metadata][$k]"] = $v; // también en la suscripción
    }
    if ($customer)   $p['customer'] = $customer;
    elseif ($email)  $p['customer_email'] = $email;
    return stripe_api('POST', 'checkout/sessions', $p);
}

/**
 * Verifica la firma del webhook (header Stripe-Signature) contra el
 * STRIPE_WEBHOOK_SECRET. Devuelve el evento decodificado o lanza error.
 * Tolerancia de 5 min contra replays.
 */
function stripe_verificar_webhook(string $payload, string $sig_header, string $secret, int $tolerancia = 300): array {
    $t = null; $v1 = [];
    foreach (explode(',', $sig_header) as $parte) {
        [$k, $val] = array_pad(explode('=', trim($parte), 2), 2, '');
        if ($k === 't') $t = $val;
        if ($k === 'v1') $v1[] = $val;
    }
    if (!$t || !$v1) throw new StripeError('Firma de webhook mal formada.');
    $esperado = hash_hmac('sha256', $t . '.' . $payload, $secret);
    $ok = false;
    foreach ($v1 as $firma) { if (hash_equals($esperado, $firma)) { $ok = true; break; } }
    if (!$ok) throw new StripeError('Firma de webhook inválida.');
    if (abs(time() - (int)$t) > $tolerancia) throw new StripeError('Webhook fuera de tiempo (replay?).');
    $data = json_decode($payload, true);
    if (!is_array($data)) throw new StripeError('Payload de webhook ilegible.');
    return $data;
}

// ── ADMIN: reembolsos y cancelación (soporte de Operaciones) ─────────────────

/** Último cargo del cliente (para reembolsar). Devuelve null si no hay ninguno. */
function stripe_ultimo_cargo(string $customer_id): ?array {
    $r = stripe_api('GET', 'charges', ['customer' => $customer_id, 'limit' => 1]);
    $c = $r['data'][0] ?? null;
    if (!$c) return null;
    return [
        'id'          => (string)$c['id'],
        'monto'       => (int)($c['amount'] ?? 0),          // centavos
        'reembolsado' => (int)($c['amount_refunded'] ?? 0), // centavos ya devueltos
        'moneda'      => strtoupper($c['currency'] ?? 'usd'),
        'pagado'      => !empty($c['paid']),
    ];
}

/** Reembolsa un cargo: total (monto null) o parcial (centavos). */
function stripe_reembolsar(string $charge_id, ?int $monto_centavos = null): array {
    $p = ['charge' => $charge_id];
    if ($monto_centavos !== null && $monto_centavos > 0) $p['amount'] = $monto_centavos;
    return stripe_api('POST', 'refunds', $p);
}

/** Cancela la suscripción: al final del período (seguro, default) o de inmediato. */
function stripe_cancelar_suscripcion(string $sub_id, bool $al_final = true): array {
    if ($al_final) {
        return stripe_api('POST', 'subscriptions/' . rawurlencode($sub_id), ['cancel_at_period_end' => 'true']);
    }
    return stripe_api('DELETE', 'subscriptions/' . rawurlencode($sub_id));
}
