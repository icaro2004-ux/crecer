<?php
// ============================================================
//  CRECER — Worker del ARTE async (panel/arte_worker.php)
//  Disparado por arte_disparar(). Responde 'ok' al instante
//  (fastcgi_finish_request) y sigue por detrás: sondea el job de
//  Responses hasta que la imagen esté y AVISA por notificación con
//  link al post. Así el dueño encola y sigue trabajando / se va.
//  Gated por llave fija (no público).
// ============================================================
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/img_responses.php';
require_once __DIR__ . '/../includes/notif.php';

$mid = (int)($_GET['marca'] ?? 0);
$pid = (int)($_GET['id'] ?? 0);
$key = (string)($_GET['key'] ?? '');
if (!$mid || !$pid || !hash_equals(ARTE_WORKER_KEY, $key)) { http_response_code(403); exit('no'); }

// Responde YA al que disparó; el trabajo sigue por detrás.
header('Content-Type: text/plain; charset=utf-8');
echo "ok\n";
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
@ignore_user_abort(true);
@set_time_limit(0);

// Si aún no hay job de imagen → CREARLO AQUÍ (la llamada lenta a OpenAI corre en el
// worker, NUNCA en la pantalla del dueño → cero timeout / "error de conexión").
$ct_raw = (string)($_GET['ct'] ?? 'x');
$con_texto = ($ct_raw === 'x' ? null : ($ct_raw === '1'));
$extra = trim((string)($_GET['extra'] ?? ''));
$estilo = trim((string)($_GET['est'] ?? 'realista')) ?: 'realista';
$link = '/crecer/panel/propuestas.php?marca=' . $mid;
$row  = $pdo->query("SELECT img_job, caption FROM crecer_contenido WHERE id=" . $pid)->fetch(PDO::FETCH_ASSOC);
$copy = (string)($row['caption'] ?? '');

// avisa ok/respaldo/error por notificación
$notif_ok = function () use ($pdo, $mid, $link) {
    notif_crear($pdo, $mid, 'arte', 'Tu arte ya está listo', 'El corillo terminó la imagen de tu post — dale un vistazo.', $link, 'image');
};
// RESPALDO Gemini (Nano Banana Pro) cuando gpt no puede → avisa ok o error.
$respaldo_gemini = function () use ($pdo, $mid, $pid, $copy, $link, $notif_ok) {
    $u = img_gemini_fallback($pdo, $mid, $pid, $copy);
    if ($u !== '') { $notif_ok(); }
    else { notif_crear($pdo, $mid, 'arte', 'No se pudo crear el arte', 'Vuelve a tu post e intenta otra vez.', $link, 'bolt'); }
    exit;
};

// Re-disparo desde el sweep: ir DIRECTO a Gemini (gpt ya falló antes).
if (($_GET['fb'] ?? '') === '1') { error_log("arte_worker #{$pid}: re-disparo → Gemini directo"); $respaldo_gemini(); }

// 1) CREAR el job de gpt-image-2. Si NO se pudo crear (ej. 429/rate limit) → respaldo Gemini.
if ($row && trim((string)($row['img_job'] ?? '')) === '') {
    $jid = img_resp_encolar($pdo, $mid, $pid, $copy, $con_texto, $extra !== '' ? $extra : null, $estilo);
    if ($jid === '') { error_log("arte_worker #{$pid}: gpt no creó → Gemini"); $respaldo_gemini(); }
}

// 2) Sondea gpt-image-2. ok → avisa. error → Gemini. sigue en progreso → espera.
$estado = 'queued';
for ($i = 0; $i < 80; $i++) {                 // ~4 min de ventana (gpt-image-2 tarda ~3)
    try { $r = img_resp_completar($pdo, $mid, $pid); $estado = (string)($r['estado'] ?? 'queued'); }
    catch (Throwable $e) { $estado = 'queued'; }
    if ($estado === 'ok') { $notif_ok(); exit; }
    if ($estado === 'error') { error_log("arte_worker #{$pid}: gpt error → Gemini"); $respaldo_gemini(); }
    sleep(3);
}
// 3) Timeout: gpt tardó demasiado (si el worker sobrevivió hasta aquí) → respaldo Gemini.
error_log("arte_worker #{$pid}: gpt timeout → Gemini");
$respaldo_gemini();
