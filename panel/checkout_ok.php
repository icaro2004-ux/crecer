<?php
// ============================================================
//  CRECER — Retorno de Stripe Checkout: VERIFICA y ACTIVA
//  panel/checkout_ok.php?marca=X&session_id={CHECKOUT_SESSION_ID}
//
//  No dependemos solo del webhook: al volver del pago, confirmamos
//  con Stripe y marcamos la suscripción activa de una. Así el pago
//  se reconoce al instante (en local sin listener, y en prod).
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/suscripcion.php';
require __DIR__ . '/../includes/stripe.php';
requiere_login();

$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/intake.php'); exit; }
$marca_id = (int)$marca['id'];
$sid  = $_GET['session_id'] ?? '';
$dest = '/crecer/panel/index.php?marca=' . $marca_id;

if ($sid && stripe_configurado()) {
    try {
        $sess = stripe_api('GET', 'checkout/sessions/' . rawurlencode($sid));
        // Seguridad: la sesión debe ser de ESTA marca
        $meta_marca = (int)($sess['metadata']['marca_id'] ?? 0);
        $pagado = (($sess['payment_status'] ?? '') === 'paid') || (($sess['status'] ?? '') === 'complete');
        if ($pagado && ($meta_marca === 0 || $meta_marca === $marca_id)) {
            $sub_id = $sess['subscription'] ?? null;
            $cust   = $sess['customer'] ?? null;
            $per_fin = null;
            if ($sub_id) {
                $sub = stripe_api('GET', 'subscriptions/' . rawurlencode($sub_id));
                if (!empty($sub['current_period_end'])) $per_fin = date('Y-m-d', (int)$sub['current_period_end']);
            }
            $pdo->prepare(
                "UPDATE crecer_suscripciones
                   SET estado='activa',
                       stripe_subscription_id = COALESCE(?, stripe_subscription_id),
                       stripe_customer_id     = COALESCE(?, stripe_customer_id),
                       periodo_inicio = COALESCE(periodo_inicio, CURDATE()),
                       periodo_fin = ?, updated_at = NOW()
                 WHERE marca_id = ?"
            )->execute([$sub_id, $cust, $per_fin, $marca_id]);
            $dest .= '&pago=ok';
        }
    } catch (Throwable $e) {
        error_log('[checkout_ok] ' . $e->getMessage());
    }
}
unset($_SESSION['plan_intent']);
header('Location: ' . $dest);
exit;
