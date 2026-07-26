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
try {
    $row = $pdo->query("SELECT img_job, caption FROM crecer_contenido WHERE id=" . $pid)->fetch(PDO::FETCH_ASSOC);
    if ($row && trim((string)($row['img_job'] ?? '')) === '') {
        img_resp_encolar($pdo, $mid, $pid, (string)($row['caption'] ?? ''), $con_texto, $extra !== '' ? $extra : null);
    }
} catch (Throwable $e) { error_log("arte_worker #{$pid} crear: " . $e->getMessage()); }

$link = '/crecer/panel/propuestas.php?marca=' . $mid;
$estado = 'queued';
for ($i = 0; $i < 60; $i++) {                 // ~3 min máx (gpt-image-1 suele < 1 min)
    try { $r = img_resp_completar($pdo, $mid, $pid); $estado = (string)($r['estado'] ?? 'queued'); }
    catch (Throwable $e) { $estado = 'queued'; }
    if ($estado === 'ok') {
        notif_crear($pdo, $mid, 'arte', 'Tu arte ya está listo',
            'El corillo terminó la imagen de tu post — dale un vistazo.', $link, 'image');
        exit;
    }
    if ($estado === 'error') {
        notif_crear($pdo, $mid, 'arte', 'No se pudo crear el arte',
            'Vuelve a tu post e intenta otra vez.', $link, 'bolt');
        exit;
    }
    sleep(3);
}
// Timeout del worker: el job queda vivo; un poll futuro (o el usuario al volver) lo cierra.
error_log("arte_worker #{$pid}: timeout tras ~3min (job sigue vivo)");
