<?php
// ============================================================
//  CRECER — Webhook de WhatsApp Cloud API
//  webhook_whatsapp.php  (público — Meta lo llama)
//
//  GET  : verificación del webhook (hub.challenge) con
//         WHATSAPP_VERIFY_TOKEN.
//  POST : mensajes entrantes → wa_procesar_entrante() (el Conserje
//         de WhatsApp decide y responde). Firma X-Hub-Signature-256
//         verificada con META_APP_SECRET — nadie más puede inyectar.
//
//  Config del webhook en Meta (app → WhatsApp → Configuration):
//    Callback URL : https://encuentraloahora.com/crecer/webhook_whatsapp.php
//    Verify token : el valor de WHATSAPP_VERIFY_TOKEN
//    Suscribirse a: messages
// ============================================================

require __DIR__ . '/includes/db.php';

// ── GET: el apretón de manos de Meta al configurar el webhook ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode  = $_GET['hub_mode']         ?? ($_GET['hub.mode'] ?? '');
    $tok   = $_GET['hub_verify_token'] ?? ($_GET['hub.verify_token'] ?? '');
    $chal  = $_GET['hub_challenge']    ?? ($_GET['hub.challenge'] ?? '');
    $esperado = defined('WHATSAPP_VERIFY_TOKEN') ? WHATSAPP_VERIFY_TOKEN : '';
    if ($mode === 'subscribe' && $esperado !== '' && hash_equals($esperado, (string)$tok)) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $chal;
        exit;
    }
    http_response_code(403);
    echo 'verificación fallida';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

// El proceso NO muere si Meta corta la conexión: se contesta 200 rápido igual.
@ignore_user_abort(true);
@set_time_limit(60);

$raw = (string)file_get_contents('php://input');

// ── Firma: solo Meta (con nuestro APP_SECRET) puede hablarle a este endpoint ──
if (defined('META_APP_SECRET') && META_APP_SECRET !== '') {
    $sig = (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
    $esperada = 'sha256=' . hash_hmac('sha256', $raw, META_APP_SECRET);
    if ($sig === '' || !hash_equals($esperada, $sig)) {
        http_response_code(403);
        exit;
    }
}

http_response_code(200);           // responder rápido: Meta reintenta si no ve 200
header('Content-Type: text/plain; charset=utf-8');
echo 'ok';
// (el trabajo sigue después de contestar; ignore_user_abort lo protege)
if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }

require_once __DIR__ . '/includes/whatsapp.php';
if (!wa_configurado()) { error_log('webhook_whatsapp: sin configurar (WHATSAPP_TOKEN/PHONE_ID/MARCA_ID)'); exit; }

$data = json_decode($raw, true) ?: [];
foreach (($data['entry'] ?? []) as $entry) {
    foreach (($entry['changes'] ?? []) as $ch) {
        $val = $ch['value'] ?? [];
        // Nombre del contacto (si viene).
        $nombres = [];
        foreach (($val['contacts'] ?? []) as $c) {
            $nombres[(string)($c['wa_id'] ?? '')] = (string)($c['profile']['name'] ?? '');
        }
        foreach (($val['messages'] ?? []) as $m) {
            if (($m['type'] ?? '') !== 'text') continue;   // V1: solo texto (audio/imagen = fase 2)
            $wamid = (string)($m['id'] ?? '');
            $tel   = (string)($m['from'] ?? '');
            $txt   = (string)($m['text']['body'] ?? '');
            if ($wamid === '' || $tel === '' || $txt === '') continue;
            try {
                wa_procesar_entrante($pdo, $wamid, $tel, $nombres[$tel] ?? '', $txt);
            } catch (Throwable $e) {
                error_log('webhook_whatsapp procesar: ' . $e->getMessage());
            }
        }
    }
}
