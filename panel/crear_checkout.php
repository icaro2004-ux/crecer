<?php
// ============================================================
//  CRECER — Crea la sesión de Stripe Checkout y redirige
//  panel/crear_checkout.php  (POST desde precios.php)
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/suscripcion.php';
require __DIR__ . '/../includes/stripe.php';
requiere_login();

$usuario = usuario_actual($pdo);

function volver_con_error(int $marca_id, string $msg): void {
    error_log('[crecer checkout error] marca=' . $marca_id . ' :: ' . $msg);
    header('Location: /crecer/panel/precios.php?marca=' . $marca_id . '&error=' . urlencode($msg));
    exit;
}

// Validaciones
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok()) {
    http_response_code(400); exit('Solicitud inválida.');
}
$marca_id  = (int)($_POST['marca'] ?? 0);
$plan_slug = $_POST['plan'] ?? '';

// La marca tiene que ser del usuario logueado
$m = marca_del_usuario($pdo, (int)$usuario['id'], $marca_id);
if (!$m || (int)$m['id'] !== $marca_id) { http_response_code(403); exit('No autorizado.'); }

if (!in_array($plan_slug, ['crecer', 'despegar'], true)) {
    volver_con_error($marca_id, 'Plan no válido.');
}
if (!stripe_configurado()) {
    volver_con_error($marca_id, 'Los pagos todavía no están activos. Inténtalo en un ratito.');
}

// Plan + price_id
$ps = $pdo->prepare("SELECT * FROM crecer_planes WHERE slug=? AND activo=1");
$ps->execute([$plan_slug]);
$plan = $ps->fetch();
if (!$plan) volver_con_error($marca_id, 'Ese plan no está disponible.');
if (empty($plan['stripe_price_id'])) {
    volver_con_error($marca_id, 'Este plan aún no está conectado a Stripe (falta el price_id).');
}

// Reusar el customer de Stripe si la marca ya tiene uno
$su = suscripcion_de_marca($pdo, $marca_id);
$customer = $su['stripe_customer_id'] ?? null;
$email    = $usuario['email'] ?? null;

$base = defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'http://localhost/crecer';
$meta = [
    'marca_id'   => $marca_id,
    'usuario_id' => (int)$usuario['id'],
    'plan_slug'  => $plan_slug,
    'plan_id'    => (int)$plan['id'],
];

try {
    $sesion = stripe_crear_checkout(
        $plan['stripe_price_id'],
        (int)$plan['trial_dias'],
        $base . '/panel/checkout_ok.php?marca=' . $marca_id . '&session_id={CHECKOUT_SESSION_ID}',
        $base . '/panel/precios.php?marca=' . $marca_id . '&cancelado=1',
        $meta,
        $customer,
        $email
    );
} catch (StripeError $e) {
    error_log('[crecer checkout] ' . $e->getMessage());
    volver_con_error($marca_id, 'No pudimos abrir el pago. Inténtalo de nuevo.');
}

if (empty($sesion['url'])) {
    volver_con_error($marca_id, 'Stripe no devolvió una URL de pago.');
}

// NO otorgamos acceso aquí: la suscripción se crea/activa SOLO cuando el
// webhook recibe checkout.session.completed (fuente de verdad del pago).
// Guardamos únicamente la referencia de la sesión en curso, sin estado activo.
$pdo->prepare(
    "INSERT INTO crecer_suscripciones (marca_id, usuario_id, plan_id, estado, stripe_session_id, stripe_customer_id)
     VALUES (?,?,?,'incompleta',?,?)
     ON DUPLICATE KEY UPDATE stripe_session_id=VALUES(stripe_session_id),
        stripe_customer_id=COALESCE(VALUES(stripe_customer_id), stripe_customer_id), updated_at=NOW()"
)->execute([$marca_id, (int)$usuario['id'], (int)$plan['id'], $sesion['id'] ?? null, $customer]);

header('Location: ' . $sesion['url']);
exit;
