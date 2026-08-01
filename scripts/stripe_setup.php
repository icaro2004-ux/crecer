<?php
// ============================================================
//  CRECER — Crea productos + precios en Stripe y guarda los
//  price_id en crecer_planes. Idempotente (salta si ya existe).
//  Correr una vez por CLI:  php scripts/stripe_setup.php
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/stripe.php';

// ── Candado: CLI libre; por URL exige ?key=CRON_TOKEN (igual que los crons) ──
// Sin esto, cualquiera podría dispararlo por web y crear productos en Stripe.
if (PHP_SAPI !== 'cli') {
    $token = defined('CRON_TOKEN') ? CRON_TOKEN : '';
    if ($token === '' || !hash_equals($token, (string)($_GET['key'] ?? ''))) {
        http_response_code(403); header('Content-Type: text/plain; charset=utf-8');
        echo "403 — no autorizado. Usa ?key=CRON_TOKEN o córrelo por CLI.\n"; exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

if (!stripe_configurado()) { exit("Falta STRIPE_SECRET_KEY en config.local.php\n"); }

$planes = $pdo->query("SELECT * FROM crecer_planes WHERE activo=1 ORDER BY orden")->fetchAll();

foreach ($planes as $p) {
    $slug = $p['slug'];
    if (!empty($p['stripe_price_id'])) {
        echo "· $slug ya tiene price_id ({$p['stripe_price_id']}) — salto.\n";
        continue;
    }
    $monto = (int) round((float)$p['precio_mensual'] * 100); // a centavos

    // 1) Producto
    $prod = stripe_api('POST', 'products', [
        'name'        => 'Crecer · ' . $p['nombre'],
        'description' => $p['descripcion'] ?? '',
        'metadata[slug]' => $slug,
    ]);
    echo "✓ producto $slug → {$prod['id']}\n";

    // 2) Precio mensual recurrente
    $price = stripe_api('POST', 'prices', [
        'product'            => $prod['id'],
        'unit_amount'        => $monto,
        'currency'           => strtolower($p['moneda'] ?? 'usd'),
        'recurring[interval]' => 'month',
        'metadata[slug]'     => $slug,
    ]);
    echo "✓ precio   $slug → {$price['id']} (\${$p['precio_mensual']}/mes)\n";

    // 3) Guardar en la BD
    $pdo->prepare("UPDATE crecer_planes SET stripe_price_id=? WHERE id=?")
        ->execute([$price['id'], (int)$p['id']]);
}

echo "\nListo. Planes con price_id:\n";
foreach ($pdo->query("SELECT slug, nombre, precio_mensual, stripe_price_id FROM crecer_planes ORDER BY orden") as $r) {
    printf("  %-9s %-9s %s\n", $r['slug'], '$'.$r['precio_mensual'], $r['stripe_price_id'] ?: '(sin price_id)');
}
