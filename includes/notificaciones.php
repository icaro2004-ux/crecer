<?php
// ============================================================
//  CRECER — Notificaciones por email (voz boricua)
//  includes/notificaciones.php
//
//  Nota: en local (XAMPP) mail() no sale; en prod (Hostinger) sí.
//  Stripe además manda su propio recordatorio de fin-de-trial si
//  está activado en el dashboard — esto es el toque de la casa.
// ============================================================

/**
 * Envío básico de email. Devuelve true/false. Nunca lanza (no debe
 * tumbar el webhook). Loguea siempre para tener rastro en local.
 */
function crecer_enviar_email(string $para, string $asunto, string $cuerpo_html): bool {
    if (!$para || !filter_var($para, FILTER_VALIDATE_EMAIL)) return false;
    $de = 'Crecer · Encuéntralo <hola@encuentraloahora.com>'; // dominio real → pasa SPF en Hostinger
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: $de\r\n";
    error_log("[crecer email] -> $para | $asunto");
    try {
        return @mail($para, '=?UTF-8?B?' . base64_encode($asunto) . '?=', $cuerpo_html, $headers);
    } catch (Throwable $e) {
        error_log('[crecer email error] ' . $e->getMessage());
        return false;
    }
}

/**
 * Correo de ACTIVACIÓN (verificar que es humano). Se envía al registrarse.
 * Sin confirmar el correo, la cuenta no entra ni ve el post de muestra.
 */
function crecer_email_activacion(string $email, string $nombre, string $link): bool {
    $n = htmlspecialchars(trim($nombre) !== '' ? $nombre : 'mi gente', ENT_QUOTES, 'UTF-8');
    $lk = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
    $asunto = 'Activa tu cuenta de Crecer 🌱';
    $cuerpo =
      '<div style="font-family:Arial,Helvetica,sans-serif;max-width:480px;margin:0 auto;color:#1b1622">'
    . '<h2 style="font-family:Arial;color:#e3683f">¡Wepa, ' . $n . '! 👋</h2>'
    . '<p style="font-size:15px;line-height:1.55">Un paso y estás dentro. Confirma que eres humano para <b>activar tu cuenta</b> y ver tu primer post de muestra hecho por la IA, en tu voz.</p>'
    . '<p style="text-align:center;margin:26px 0">'
    . '<a href="' . $lk . '" style="display:inline-block;background:#ff2d85;color:#fff;text-decoration:none;font-weight:800;font-size:16px;padding:14px 30px;border-radius:99px">Activar mi cuenta →</a></p>'
    . '<p style="font-size:12.5px;color:#8a7f72">O copia este enlace:<br>' . $lk . '</p>'
    . '<p style="font-size:12px;color:#8a7f72;border-top:1px solid #eee;padding-top:12px;margin-top:18px">Si no creaste esta cuenta, ignora este correo.</p>'
    . '</div>';
    return crecer_enviar_email($email, $asunto, $cuerpo);
}

/**
 * Recordatorio de que la prueba gratis está por terminar (se dispara
 * desde el webhook con el evento customer.subscription.trial_will_end,
 * ~3 días antes del primer cobro). Idempotente vía bandera en DB.
 */
function crecer_email_trial_termina(PDO $pdo, int $marca_id, array $sub): void {
    // No repetir si ya se envió
    $q = $pdo->prepare("SELECT su.*, u.email, u.nombre, m.nombre_negocio, p.nombre AS plan_nombre, p.precio_mensual
        FROM crecer_suscripciones su
        JOIN crecer_marca m ON m.id = su.marca_id
        JOIN usuarios u ON u.id = su.usuario_id
        LEFT JOIN crecer_planes p ON p.id = su.plan_id
        WHERE su.marca_id = ?");
    $q->execute([$marca_id]);
    $r = $q->fetch();
    if (!$r || $r['recordatorio_trial_enviado']) return;

    $email   = $r['email'] ?? '';
    $nombre  = $r['nombre'] ?: 'jefe';
    $negocio = $r['nombre_negocio'] ?: 'tu negocio';
    $plan    = $r['plan_nombre'] ?: 'Crecer';
    $precio  = number_format((float)($r['precio_mensual'] ?? 0), 0);
    $fecha   = !empty($sub['trial_end']) ? date('d/m/Y', (int)$sub['trial_end']) : 'pronto';
    $base    = defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'http://localhost/crecer';
    $panel   = $base . '/panel/index.php?marca=' . $marca_id;

    $asunto = "Tu prueba de Crecer termina el $fecha 🗓️";
    $cuerpo = "
      <div style='font-family:Arial,sans-serif;max-width:520px;margin:auto;color:#1a1a24'>
        <h2 style='font-size:22px'>¡Saludos, $nombre! 👋</h2>
        <p>Tu prueba gratis del corillo digital para <b>" . htmlspecialchars($negocio) . "</b>
           termina el <b>$fecha</b>.</p>
        <p>Si te ha servido y quieres seguir, no tienes que hacer nada — el plan
           <b>$plan</b> (\$$precio/mes) sigue corriendo y el corillo no para.</p>
        <p>Si por ahora no, <b>tranqui</b>: cancela en un toque desde tu panel y
           <b>no te cobramos ni un chavo</b>.</p>
        <p style='margin:26px 0'>
          <a href='$panel' style='background:#1a1a24;color:#fff;text-decoration:none;
             font-weight:bold;padding:13px 22px;border-radius:12px'>Ir a mi panel</a>
        </p>
        <p style='color:#8a8a98;font-size:13px'>Encuéntralo · Crecer — tu corillo digital 🇵🇷</p>
      </div>";

    crecer_enviar_email($email, $asunto, $cuerpo);

    // Marcar como enviado (aunque mail() falle en local, evitamos spam de reintentos)
    $pdo->prepare("UPDATE crecer_suscripciones SET recordatorio_trial_enviado=1 WHERE marca_id=?")
        ->execute([$marca_id]);
}
