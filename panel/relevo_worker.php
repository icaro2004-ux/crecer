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
require_once __DIR__ . '/../includes/worker_key.php';   // CR-F01b: falla cerrado sin llave
require __DIR__ . '/../includes/agentes.php';
require __DIR__ . '/../includes/relevo_demo.php';

$mid = (int)($_GET['marca'] ?? 0);
$key = (string)($_GET['key'] ?? '');
if (!$mid) { http_response_code(403); exit('no'); }
worker_autorizar($key, 'relevo');

// Responde YA al que disparó; el corillo sigue por detrás.
header('Content-Type: text/plain; charset=utf-8');
echo "ok\n";
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
@ignore_user_abort(true);
@set_time_limit(0);

relevo_marcar($pdo, $mid, 'relevo_inicio');
$creadas = 0;
try {
    //  POR EL LIBRO DE CORRIDAS (core/Meta/MetaAutoRunner.php). Este worker se
    //  dispara desde la pantalla: dos clics o un cron solapado corrian el equipo
    //  dos veces. El candado es una llave unica, no un «mira si hay otro».
    require_once __DIR__ . '/../core/Meta/MetaAutoRunner.php';
    require_once __DIR__ . '/../includes/meta_negocio.php';
    $plan_vig = 0;
    try {
        $mt = meta_activa($pdo, $mid);
        if ($mt) $plan_vig = (int)(meta_plan_activo($pdo, (int)$mt['id'])['id'] ?? 0);
    } catch (Throwable $e) {}

    $env = MetaAutoRunner::envolver($pdo, $mid, $plan_vig, 'worker',
        fn(callable $latir) => relevo_del_corillo($pdo, $mid, $latir));
    $creadas = (int)$env['creadas'];
    relevo_marcar($pdo, $mid, 'relevo_fin', json_encode(
        ['creadas' => $creadas, 'corrio' => $env['corrio'], 'motivo' => $env['motivo']],
        JSON_UNESCAPED_UNICODE));
} catch (Throwable $e) {
    error_log('relevo_worker #' . $mid . ': ' . $e->getMessage());
    relevo_marcar($pdo, $mid, 'relevo_fin', json_encode(['creadas' => 0, 'error' => mb_substr($e->getMessage(), 0, 200)], JSON_UNESCAPED_UNICODE));
}
