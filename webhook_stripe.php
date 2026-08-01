<?php
// ============================================================
//  CRECER — Webhook de Stripe (fuente de verdad de los pagos)
//  webhook_stripe.php  (público; Stripe le hace POST)
//
//  NO depende del redirect: aquí se confirma el estado real.
//  Local: usa el Stripe CLI →
//    stripe listen --forward-to localhost/crecer/webhook_stripe.php
//  Eso te da el STRIPE_WEBHOOK_SECRET (whsec_...) para config.
//
//  Eventos manejados:
//   checkout.session.completed          → enlazar suscripción a la marca
//   customer.subscription.updated       → sincronizar estado/periodo
//   customer.subscription.deleted       → cancelada
//   customer.subscription.trial_will_end→ email recordatorio (3 días antes)
//   invoice.paid / payment_succeeded    → registrar ingreso en `pagos`
//   invoice.payment_failed              → vencida
// ============================================================
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/stripe.php';
@require_once __DIR__ . '/includes/notificaciones.php'; // email recordatorio (opcional)

// ── Verificar firma ─────────────────────────────────────────
$payload = file_get_contents('php://input');
$sig     = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$secret  = defined('STRIPE_WEBHOOK_SECRET') ? STRIPE_WEBHOOK_SECRET : '';

if ($secret === '') { http_response_code(500); exit('Webhook no configurado.'); }
try {
    $evento = stripe_verificar_webhook($payload, $sig, $secret);
} catch (StripeError $e) {
    error_log('[crecer webhook] ' . $e->getMessage());
    http_response_code(400); exit('Firma inválida.');
}

$tipo = $evento['type'] ?? '';
$obj  = $evento['data']['object'] ?? [];

// ── Helpers ─────────────────────────────────────────────────

/** Mapea el status de Stripe al estado de crecer_suscripciones. */
function estado_desde_stripe(string $status): string {
    switch ($status) {
        case 'trialing':                return 'trial';
        case 'active':                  return 'activa';
        case 'canceled':                return 'cancelada';
        case 'past_due': case 'unpaid':
        case 'incomplete': case 'incomplete_expired': default:
            return 'vencida';
    }
}

/** Sincroniza crecer_suscripciones desde un objeto subscription de Stripe. */
function sincronizar_suscripcion(PDO $pdo, array $sub): void {
    $marca_id = (int)($sub['metadata']['marca_id'] ?? 0);
    if (!$marca_id) return; // sin marca no podemos enlazar
    $usuario_id = (int)($sub['metadata']['usuario_id'] ?? 0);
    $plan_id    = (int)($sub['metadata']['plan_id'] ?? 0) ?: null;

    $estado    = estado_desde_stripe($sub['status'] ?? '');
    $trial_fin = !empty($sub['trial_end'])          ? date('Y-m-d H:i:s', (int)$sub['trial_end']) : null;
    $per_fin   = !empty($sub['current_period_end']) ? date('Y-m-d', (int)$sub['current_period_end']) : null;
    $per_ini   = !empty($sub['current_period_start'])? date('Y-m-d', (int)$sub['current_period_start']) : null;
    $cancel_fin = !empty($sub['cancel_at_period_end']) ? 1 : 0;
    $cancelada_at = ($estado === 'cancelada') ? date('Y-m-d H:i:s') : null;

    $pdo->prepare(
        "INSERT INTO crecer_suscripciones
            (marca_id, usuario_id, plan_id, estado, stripe_subscription_id, stripe_customer_id,
             trial_fin, periodo_inicio, periodo_fin, cancelar_al_fin, cancelada_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            usuario_id=COALESCE(VALUES(usuario_id), usuario_id),
            plan_id=COALESCE(VALUES(plan_id), plan_id),
            estado=VALUES(estado),
            stripe_subscription_id=VALUES(stripe_subscription_id),
            stripe_customer_id=COALESCE(VALUES(stripe_customer_id), stripe_customer_id),
            trial_fin=VALUES(trial_fin),
            periodo_inicio=VALUES(periodo_inicio),
            periodo_fin=VALUES(periodo_fin),
            cancelar_al_fin=VALUES(cancelar_al_fin),
            cancelada_at=VALUES(cancelada_at),
            updated_at=NOW()"
    )->execute([
        $marca_id, $usuario_id ?: 0, $plan_id, $estado,
        $sub['id'] ?? null, $sub['customer'] ?? null,
        $trial_fin, $per_ini, $per_fin, $cancel_fin, $cancelada_at,
    ]);
}

/** Registra un ingreso de Crecer en la tabla `pagos` (libro de ingresos). */
function registrar_pago(PDO $pdo, array $invoice): void {
    $invoice_id = $invoice['id'] ?? null;
    if (!$invoice_id) return;
    // Idempotencia: no duplicar si ya registramos esta factura.
    $dup = $pdo->prepare("SELECT 1 FROM pagos WHERE stripe_invoice_id=? LIMIT 1");
    $dup->execute([$invoice_id]);
    if ($dup->fetchColumn()) return;

    $sub_id   = $invoice['subscription'] ?? null;
    $cust_id  = $invoice['customer'] ?? null;
    $monto    = isset($invoice['amount_paid']) ? (int)$invoice['amount_paid'] / 100 : 0;
    $moneda   = strtoupper($invoice['currency'] ?? 'usd');

    // Recuperar marca/plan desde la suscripción local
    $su = null;
    if ($sub_id) {
        $s = $pdo->prepare("SELECT su.*, p.slug AS plan_slug FROM crecer_suscripciones su
            LEFT JOIN crecer_planes p ON p.id=su.plan_id WHERE su.stripe_subscription_id=?");
        $s->execute([$sub_id]); $su = $s->fetch() ?: null;
    }
    if (!$su) return;
    $plan_slug = $su['plan_slug'] ?: 'crecer';

    $linea = $invoice['lines']['data'][0]['period'] ?? [];
    $p_ini = !empty($linea['start']) ? date('Y-m-d', (int)$linea['start']) : null;
    $p_fin = !empty($linea['end'])   ? date('Y-m-d', (int)$linea['end'])   : null;

    $pdo->prepare(
        "INSERT INTO pagos
            (usuario_id, producto, marca_id, plan, monto, moneda, estado, tipo,
             stripe_invoice_id, stripe_subscription_id, stripe_customer_id, periodo_inicio, periodo_fin)
         VALUES (?, 'crecer', ?, ?, ?, ?, 'completado', 'suscripcion', ?, ?, ?, ?, ?)"
    )->execute([
        (int)$su['usuario_id'], (int)$su['marca_id'], $plan_slug, $monto, $moneda,
        $invoice_id, $sub_id, $cust_id, $p_ini, $p_fin,
    ]);

    // Marcar la suscripción como activa y extender el período pagado.
    $pdo->prepare("UPDATE crecer_suscripciones
        SET estado='activa', periodo_inicio=?, periodo_fin=? WHERE id=?")
        ->execute([$p_ini, $p_fin, (int)$su['id']]);
}

// ── Enrutar el evento ───────────────────────────────────────
try {
    switch ($tipo) {

        case 'checkout.session.completed':
            $sub_id = $obj['subscription'] ?? null;
            if ($sub_id) {
                $sub = stripe_api('GET', "subscriptions/$sub_id");
                // Asegurar metadata de la marca (viene de subscription_data[metadata])
                if (empty($sub['metadata']['marca_id']) && !empty($obj['metadata']['marca_id'])) {
                    $sub['metadata'] = $obj['metadata'];
                }
                sincronizar_suscripcion($pdo, $sub);
            }
            break;

        case 'customer.subscription.updated':
        case 'customer.subscription.created':
        case 'customer.subscription.deleted':
            sincronizar_suscripcion($pdo, $obj);
            break;

        case 'customer.subscription.trial_will_end':
            sincronizar_suscripcion($pdo, $obj);
            $marca_id = (int)($obj['metadata']['marca_id'] ?? 0);
            if ($marca_id && function_exists('crecer_email_trial_termina')) {
                crecer_email_trial_termina($pdo, $marca_id, $obj);
            }
            break;

        case 'invoice.paid':
        case 'invoice.payment_succeeded':
            registrar_pago($pdo, $obj);
            break;

        case 'invoice.payment_failed':
            $sub_id = $obj['subscription'] ?? null;
            if ($sub_id) {
                // ¿Es la PRIMERA falla? (para avisar una sola vez, no en cada reintento de Stripe)
                $q = $pdo->prepare(
                    "SELECT su.estado, su.marca_id, m.nombre_negocio, u.email
                       FROM crecer_suscripciones su
                       JOIN crecer_marca m ON m.id = su.marca_id
                       LEFT JOIN usuarios u ON u.id = m.usuario_id
                      WHERE su.stripe_subscription_id = ?");
                $q->execute([$sub_id]);
                $info = $q->fetch();
                $era_primera = $info && $info['estado'] !== 'vencida';

                $pdo->prepare("UPDATE crecer_suscripciones SET estado='vencida' WHERE stripe_subscription_id=?")
                    ->execute([$sub_id]);

                // Aviso con marca al dueño (solo en la 1ra falla; Stripe reintenta varias veces).
                $correo = (string)($info['email'] ?? '');
                if ($era_primera && $correo && filter_var($correo, FILTER_VALIDATE_EMAIL) && function_exists('crecer_enviar_email')) {
                    $portal = 'https://encuentraloahora.com/crecer/panel/precios.php?marca=' . (int)$info['marca_id'];
                    $cuerpo = 'Tu pago mensual de Crecer no entró (tarjeta vencida o sin fondos, suele ser eso). '
                            . 'Tu corillo quedó <b>en pausa</b> hasta que actualices el pago — tu contenido y tu marca están guardados, no se pierde nada. '
                            . 'Actualiza tu tarjeta y sigues donde quedaste.';
                    $html = function_exists('crecer_email_shell')
                        ? crecer_email_shell('Tu pago no entró', $cuerpo, [
                            'eyebrow' => (string)($info['nombre_negocio'] ?? 'Crecer'),
                            'cta_txt' => 'Actualizar mi pago',
                            'cta_url' => $portal,
                            'footer'  => 'Si ya lo resolviste, ignora este correo · Crecer by Encuéntralo.',
                          ])
                        : '<div>' . htmlspecialchars($cuerpo, ENT_QUOTES, 'UTF-8') . '</div>';
                    crecer_enviar_email($correo, 'Tu pago no entró — reactiva tu corillo', $html);
                }
            }
            break;

        default:
            // Evento no manejado: lo ignoramos pero respondemos 200.
            break;
    }
} catch (Throwable $e) {
    error_log('[crecer webhook ' . $tipo . '] ' . $e->getMessage());
    http_response_code(500); exit('Error procesando el evento.');
}

http_response_code(200);
echo 'ok';
