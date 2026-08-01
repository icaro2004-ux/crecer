<?php
// ============================================================
//  CRECER — Crea un CUPÓN + CÓDIGO PROMO en Stripe (LIVE o test).
//  Caso: "founder pricing" para los primeros N clientes → $29 en vez de $39.
//  Idempotente: si el cupón/código ya existen, los reusa (no duplica).
//
//  CLI:  php scripts/stripe_cupon.php
//  URL:  https://TU-DOMINIO/crecer/scripts/stripe_cupon.php?key=CRON_TOKEN
//         &code=PRIMEROS50 &off=10 &max=50 &dur=forever
//
//  Parámetros (todos opcionales, con defaults sensatos):
//   code  = código que escribe el cliente en el checkout   (default PRIMEROS50)
//   off   = dólares de descuento por mes                    (default 10 → $39-$10=$29)
//   max   = cuántos clientes pueden usarlo (los "primeros") (default 50)
//   dur   = forever | once | repeating                      (default forever)
//   months= si dur=repeating, cuántos meses dura el descuento
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/stripe.php';

// ── Candado: CLI libre; por URL exige ?key=CRON_TOKEN ──
if (PHP_SAPI !== 'cli') {
    $token = defined('CRON_TOKEN') ? CRON_TOKEN : '';
    if ($token === '' || !hash_equals($token, (string)($_GET['key'] ?? ''))) {
        http_response_code(403); header('Content-Type: text/plain; charset=utf-8');
        echo "403 — no autorizado. Usa ?key=CRON_TOKEN o córrelo por CLI.\n"; exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}
if (!stripe_configurado()) { exit("Falta STRIPE_SECRET_KEY en config.\n"); }

// ── Parámetros ──
$g = fn($k, $d) => isset($_GET[$k]) ? $_GET[$k] : $d;
$code    = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$g('code', 'PRIMEROS50')));
$off     = max(1, (int)$g('off', 10));           // dólares
$max     = max(1, (int)$g('max', 50));           // primeros N
$dur     = in_array($g('dur','forever'), ['forever','once','repeating'], true) ? $g('dur','forever') : 'forever';
$months  = max(1, (int)$g('months', 3));
$cupon_id = 'crecer_fundador_' . (39 - $off);    // id estable → idempotente (ej. crecer_fundador_29)

echo "CRECER · cupón founder\n" . str_repeat('=', 40) . "\n";
echo "código={$code}  descuento=\${$off}/mes  máx={$max}  duración={$dur}" . ($dur==='repeating' ? " ({$months} meses)" : "") . "\n";
echo "precio resultante: \$" . (39 - $off) . "/mes (sobre el plan de \$39)\n\n";

// ── 1) Cupón (reusar si ya existe por id) ──
$cupon = null;
try {
    $cupon = stripe_api('GET', 'coupons/' . rawurlencode($cupon_id));
    echo "· cupón '{$cupon_id}' ya existe — lo reuso.\n";
} catch (StripeError $e) {
    $params = [
        'id'              => $cupon_id,
        'amount_off'      => $off * 100,   // centavos
        'currency'        => 'usd',
        'duration'        => $dur,
        'max_redemptions' => $max,
        'name'            => "Fundador \$" . (39 - $off),
        'metadata[origen]'=> 'crecer_fundador',
    ];
    if ($dur === 'repeating') $params['duration_in_months'] = $months;
    $cupon = stripe_api('POST', 'coupons', $params);
    echo "✓ cupón creado: {$cupon['id']} (–\${$off}/mes, hasta {$max} usos)\n";
}

// ── 2) Código promo (el que teclea el cliente). Reusar si ya existe. ──
$existe = stripe_api('GET', 'promotion_codes', ['code' => $code, 'limit' => 1]);
if (!empty($existe['data'][0])) {
    $pc = $existe['data'][0];
    echo "· código '{$code}' ya existe ({$pc['id']}) — no lo duplico.\n";
} else {
    $pc = stripe_api('POST', 'promotion_codes', [
        'coupon' => $cupon['id'],
        'code'   => $code,
        'active' => 'true',
    ]);
    echo "✓ código promo creado: {$pc['code']} ({$pc['id']})\n";
}

echo "\nLISTO. El cliente escribe  {$code}  en el checkout (el campo de promo ya está activo).\n";
echo "Se apaga solo al llegar a {$max} usos. Míralo/edítalo en Stripe → Product catalog → Coupons.\n";
