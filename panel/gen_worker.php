<?php
// ============================================================
//  CRECER — Worker de generación V3 async  (panel/gen_worker.php)
//  Disparado por gen_disparar(). Responde 'ok' AL INSTANTE (fastcgi_finish_request)
//  y sigue corriendo gpt-5.5 → gpt-image-1 por detrás, aunque nginx corte.
//  Gated por la llave fija (no es público).
// ============================================================
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/worker_key.php';   // CR-F01b: falla cerrado sin llave
require_once __DIR__ . '/../includes/gen_async.php';

$id  = (int)($_GET['id'] ?? 0);
$key = (string)($_GET['key'] ?? '');
if (!$id) { http_response_code(403); exit('no'); }
worker_autorizar($key, 'gen');

// Responde YA al que disparó; el trabajo sigue por detrás.
header('Content-Type: text/plain; charset=utf-8');
echo "ok\n";
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
@ignore_user_abort(true);
@set_time_limit(0);

try { gen_procesar($pdo, $id); }
catch (Throwable $e) {
    error_log('gen_worker #' . $id . ': ' . $e->getMessage());
    _gen_set($pdo, $id, ['estado'=>'failed', 'error_msg'=>'worker: ' . substr($e->getMessage(),0,300)]);
}
