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

// ── MODO PRUEBA (sandbox): activar SIN Stripe ────────────────────────────────
// Aplica solo a cuentas de prueba (ver activacion_de_prueba): CRECER_DEV_ACTIVAR
// (todas, para LOCAL) o CRECER_TEST_EMAILS (solo esos emails, seguro en PROD).
// Los usuarios reales siguen pasando por Stripe.
if (activacion_de_prueba($usuario['email'] ?? null)) {
    $pl = $pdo->prepare("SELECT id FROM crecer_planes WHERE slug=?");
    $pl->execute([$plan_slug]);
    $plan_id = (int)$pl->fetchColumn();
    if ($plan_id) {
        $ex = $pdo->prepare("SELECT id FROM crecer_suscripciones WHERE marca_id=?");
        $ex->execute([$marca_id]);
        if ($sid = (int)$ex->fetchColumn()) {
            $pdo->prepare("UPDATE crecer_suscripciones SET plan_id=?, estado='activa', periodo_inicio=NOW(), periodo_fin=DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id=?")
                ->execute([$plan_id, $sid]);
        } else {
            $pdo->prepare("INSERT INTO crecer_suscripciones (marca_id, usuario_id, plan_id, estado, periodo_inicio, periodo_fin) VALUES (?,?,?, 'activa', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY))")
                ->execute([$marca_id, (int)$usuario['id'], $plan_id]);
        }
    }
    header('Location: /crecer/panel/bienvenida.php?marca=' . $marca_id . '&prueba=1'); exit;
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

// ── CR-F02b · GUARDIÁN DEL PRECIO ───────────────────────────────────────────
// Antes de abrir el cobro, se compara lo que la app le prometió al cliente con lo
// que Stripe le va a cobrar de verdad. Si no cuadra —o si no se puede comprobar—
// NO se cobra. Ante la duda no se cobra: un cliente que no puede pagar hoy es un
// problema; un cliente al que se le cobra de más es una traición.
// El cliente ve SIEMPRE el mismo mensaje: ni montos contradictorios, ni ids, ni
// errores crudos de Stripe. La causa técnica va al log y al aviso del fundador.
require_once __DIR__ . '/../includes/precio_guardian.php';
$chk_precio = precio_verificar($plan);
if (empty($chk_precio['ok'])) {
    error_log('[crecer checkout BLOQUEADO] ' . $chk_precio['motivo']);
    precio_alertar($pdo, $plan, $chk_precio);          // deduplicado 6h por la incidencia
    volver_con_error($marca_id, 'No pudimos preparar el pago ahora. No se realizó ningún cobro.');
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
