<?php
// ============================================================
//  CRECER Kernel v1 - inspector CLI/URL protegido
//
//  CLI:
//    C:\xampp\php\php.exe scripts/kernel_inspect.php user_login 1 --debug
//
//  URL:
//    /crecer/scripts/kernel_inspect.php?key=CRON_TOKEN&type=user_login&marca=1&debug=1
// ============================================================

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../core/bootstrap.php';

$isCli = PHP_SAPI === 'cli';
if ($isCli) {
    $type = $argv[1] ?? 'user_login';
    $marcaId = (int)($argv[2] ?? 0);
    $debug = in_array('--debug', $argv, true);
    $confirmed = in_array('--confirmed', $argv, true);
    $runWorker = in_array('--run-worker', $argv, true);
} else {
    $token = defined('CRON_TOKEN') ? CRON_TOKEN : '';
    if ($token === '' || !hash_equals($token, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "403 - kernel inspector no autorizado.\n";
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    $type = (string)($_GET['type'] ?? 'user_login');
    $marcaId = (int)($_GET['marca'] ?? 0);
    $debug = !empty($_GET['debug']);
    $confirmed = !empty($_GET['confirmed']);
    $runWorker = !empty($_GET['run_worker']);
}

if ($marcaId <= 0) {
    $marcaId = (int)$pdo->query("SELECT id FROM crecer_marca ORDER BY id LIMIT 1")->fetchColumn();
} else {
    $chk = $pdo->prepare("SELECT id FROM crecer_marca WHERE id=? LIMIT 1");
    $chk->execute([$marcaId]);
    if (!$chk->fetchColumn()) {
        $marcaId = (int)$pdo->query("SELECT id FROM crecer_marca ORDER BY id LIMIT 1")->fetchColumn();
    }
}
if ($marcaId <= 0) {
    http_response_code(400);
    echo $isCli ? "No hay marca para inspeccionar.\n" : json_encode(['ok'=>false,'error'=>'No hay marca para inspeccionar.']);
    exit(1);
}

$payload = [];
if ($confirmed) $payload['confirmed'] = true;
if ($runWorker) $payload['run_worker'] = true;

$event = new BusinessEvent($type, $marcaId, $payload);
$response = CrecerKernel::dispatch($event, $pdo)->toArray($debug);

if ($isCli) {
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
