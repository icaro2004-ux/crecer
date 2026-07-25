<?php
// ============================================================
//  CRECER — Reels Studio · WORKER de PUBLICAR  (panel/reel_publicar_worker.php)
//  Publicar un Reel a IG/FB tarda (Meta procesa el video). Hacerlo
//  síncrono tumbaba la petición (~60s timeout). Este worker corre
//  por detrás: responde 'ok' al instante y publica con calma.
//  Gated por llave fija. Módulo AISLADO.
// ============================================================
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/reels.php';
require_once __DIR__ . '/../includes/publicador.php';

$rid   = (int)($_GET['id'] ?? 0);
$cid   = (int)($_GET['cid'] ?? 0);
$key   = (string)($_GET['key'] ?? '');
$redes = array_values(array_intersect(explode(',', (string)($_GET['redes'] ?? '')), ['instagram', 'facebook']));
if (!$redes) $redes = ['instagram', 'facebook'];
if (!$rid || !$cid || !hash_equals(REELS_WORKER_KEY, $key)) { http_response_code(403); exit('no'); }

header('Content-Type: text/plain; charset=utf-8');
echo "ok\n";
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
@ignore_user_abort(true);
@set_time_limit(0);

try {
    $res = publicar_pieza($pdo, $cid, $redes);
    if (!empty($res['ok'])) {
        reels_set($pdo, $rid, ['estado' => 'publicado', 'error_msg' => null]);
    } else {
        // Mensaje claro (primer texto explicativo del publicador).
        $err = $res['motivo'] ?? 'No se pudo publicar.';
        foreach (($res['resultados'] ?? []) as $v) {
            if (is_string($v) && $v !== '' && $v !== 'ya publicada') { $err = $v; break; }
        }
        reels_set($pdo, $rid, ['error_msg' => 'publicar: ' . substr($err, 0, 300)]);  // estado sigue 'listo'
    }
} catch (Throwable $e) {
    error_log('reel_publicar_worker #' . $rid . ': ' . $e->getMessage());
    try { reels_set($pdo, $rid, ['error_msg' => 'publicar: ' . substr($e->getMessage(), 0, 300)]); } catch (Throwable $e2) {}
}
