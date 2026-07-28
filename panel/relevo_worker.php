<?php
// ============================================================
//  CRECER — Worker del RELEVO en vivo (panel/relevo_worker.php)
//  Disparado por relevo_disparar(). Responde 'ok' al instante
//  (fastcgi_finish_request) y corre el corillo completo por detrás:
//  Aprendiz → Estratega → Creador → Analista. Cada agente loguea en
//  crecer_ia_log → el tablero (evidencia.php) los ve aparecer en vivo.
//  Gated por llave fija (no público). NO toca la lógica del relevo.
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/agentes.php';
require __DIR__ . '/../includes/relevo_demo.php';

$mid = (int)($_GET['marca'] ?? 0);
$key = (string)($_GET['key'] ?? '');
if (!$mid || !hash_equals(RELEVO_WORKER_KEY, $key)) { http_response_code(403); exit('no'); }

// Responde YA al que disparó; el corillo sigue por detrás.
header('Content-Type: text/plain; charset=utf-8');
echo "ok\n";
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
@ignore_user_abort(true);
@set_time_limit(0);

relevo_marcar($pdo, $mid, 'relevo_inicio');
$creadas = 0;
try {
    $res = relevo_del_corillo($pdo, $mid);   // Aprendiz → Estratega → Creador → Analista
    $creadas = (int)($res['creadas'] ?? 0);
    relevo_marcar($pdo, $mid, 'relevo_fin', json_encode(['creadas' => $creadas], JSON_UNESCAPED_UNICODE));
} catch (Throwable $e) {
    error_log('relevo_worker #' . $mid . ': ' . $e->getMessage());
    relevo_marcar($pdo, $mid, 'relevo_fin', json_encode(['creadas' => 0, 'error' => mb_substr($e->getMessage(), 0, 200)], JSON_UNESCAPED_UNICODE));
}
