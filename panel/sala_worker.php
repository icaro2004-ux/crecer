<?php
// ============================================================
//  CRECER — Worker de La Sala async  (panel/sala_worker.php)
//  Disparado por sala_disparar(). Responde 'ok' AL INSTANTE
//  (fastcgi_finish_request) y corre la cadena de agentes por detrás,
//  aunque nginx corte. Gated por llave fija (no es público).
// ============================================================
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/sala_async.php';

$id  = (int)($_GET['id'] ?? 0);
$key = (string)($_GET['key'] ?? '');
if (!$id || !hash_equals(SALA_WORKER_KEY, $key)) { http_response_code(403); exit('no'); }

// Responde YA al que disparó; el trabajo sigue por detrás.
header('Content-Type: text/plain; charset=utf-8');
echo "ok\n";
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
@ignore_user_abort(true);
@set_time_limit(0);

try { sala_procesar($pdo, $id); }
catch (Throwable $e) {
    error_log('sala_worker #' . $id . ': ' . $e->getMessage());
    _sala_set($pdo, $id, ['estado' => 'failed', 'error_msg' => 'worker: ' . substr($e->getMessage(), 0, 300)]);
}
