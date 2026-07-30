<?php
// ============================================================
//  CRECER — Reels Studio · WORKER  (panel/reel_worker.php)
//  Disparado por reels_disparar(). Responde 'ok' al instante y
//  sigue corriendo por detrás: analiza (Gemini) → arma timeline →
//  renderiza (Shotstack) → poll. Gated por llave fija (no público).
//  Módulo AISLADO. NO toca nada del app existente.
// ============================================================
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/reels.php';
require_once __DIR__ . '/../includes/notif.php';

$id  = (int)($_GET['id'] ?? 0);
$key = (string)($_GET['key'] ?? '');
if (!$id || !hash_equals(REELS_WORKER_KEY, $key)) { http_response_code(403); exit('no'); }

// Responde YA al que disparó; el trabajo sigue por detrás.
header('Content-Type: text/plain; charset=utf-8');
echo "ok\n";
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
@ignore_user_abort(true);
@set_time_limit(0);

$modo = (string)($_GET['modo'] ?? 'crear');
try {
    if ($modo === 'reedit') reels_reprocesar($pdo, $id);
    else                    reels_procesar($pdo, $id);
}
catch (Throwable $e) {
    error_log('reel_worker #' . $id . ': ' . $e->getMessage());
    try { reels_set($pdo, $id, ['estado' => 'failed', 'error_msg' => 'worker: ' . substr($e->getMessage(), 0, 300)]); } catch (Throwable $e2) {}
}

// Notificar el resultado del render (el usuario no se queda esperando).
try {
    $r = $pdo->query("SELECT marca_id, estado FROM crecer_reels WHERE id=" . (int)$id)->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        $mid = (int)$r['marca_id']; $link = '/crecer/panel/reels.php?marca=' . $mid . '&reel=' . (int)$id;
        if ($r['estado'] === 'listo')       notif_crear($pdo, $mid, 'reel_listo', 'Tu reel está listo', 'El corillo lo montó. Míralo y publícalo cuando quieras.', $link, 'camera');
        elseif ($r['estado'] === 'failed')  notif_crear($pdo, $mid, 'reel_error', 'No pude terminar tu reel', 'Algo se atravesó armando el reel. Intenta de nuevo.', $link, 'bolt');
    }
} catch (Throwable $e) {}
