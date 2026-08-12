<?php
// ============================================================
//  CRECER — Worker de las jugadas del plan  (panel/meta_worker.php)
//  Disparado por meta_job_disparar(). Responde 'ok' AL INSTANTE
//  (fastcgi_finish_request) y produce el contenido de la jugada por
//  detrás, aunque el navegador se cierre. Gated por llave (no es público).
// ============================================================
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/worker_key.php';   // falla cerrado sin llave
require_once __DIR__ . '/../includes/meta_async.php';

$id  = (int)($_GET['id'] ?? 0);
$key = (string)($_GET['key'] ?? '');
if (!$id) { http_response_code(403); exit('no'); }
worker_autorizar($key, 'meta');

// Responde YA al que disparó; el trabajo sigue por detrás.
header('Content-Type: text/plain; charset=utf-8');
echo "ok\n";
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
@ignore_user_abort(true);
@set_time_limit(0);

try { meta_job_procesar($pdo, $id); }
catch (Throwable $e) {
    error_log('meta_worker #' . $id . ': ' . $e->getMessage());
    _mjob_set($pdo, $id, ['estado' => 'failed', 'error_msg' => 'worker: ' . substr($e->getMessage(), 0, 300)]);
}
