<?php
// ============================================================
//  CRECER — Portal de facturación de Stripe (gestionar/cancelar)
//  panel/portal.php
//
//  Redirige al cliente al Billing Portal de Stripe, donde puede
//  cancelar, cambiar la tarjeta o ver sus facturas. Sin UI custom.
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/suscripcion.php';
require __DIR__ . '/../includes/stripe.php';
requiere_login();

$usuario = usuario_actual($pdo);
$marca_id = (int)($_GET['marca'] ?? 0);
$m = marca_del_usuario($pdo, (int)$usuario['id'], $marca_id);
if (!$m || (int)$m['id'] !== $marca_id) { http_response_code(403); exit('No autorizado.'); }

$su = suscripcion_de_marca($pdo, $marca_id);
$volver = '/crecer/panel/precios.php?marca=' . $marca_id;

if (!stripe_configurado() || empty($su['stripe_customer_id'])) {
    header('Location: ' . $volver . '&error=' . urlencode('No hay una suscripción que gestionar todavía.'));
    exit;
}

$base = defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'http://localhost/crecer';
try {
    $sesion = stripe_api('POST', 'billing_portal/sessions', [
        'customer'   => $su['stripe_customer_id'],
        'return_url' => $base . '/panel/index.php?marca=' . $marca_id,
    ]);
} catch (StripeError $e) {
    error_log('[crecer portal] ' . $e->getMessage());
    header('Location: ' . $volver . '&error=' . urlencode('No pudimos abrir la gestión del plan.'));
    exit;
}

header('Location: ' . ($sesion['url'] ?? $volver));
exit;
