<?php
// ============================================================
//  CRECER — Cron del agente PUBLICADOR
//  scripts/cron_publicar.php
//
//  Corre el loop que publica a IG/FB lo aprobado cuya fecha llegó.
//  Pensado para un cron de Hostinger cada ~10 min. Dos formas de
//  invocarlo:
//
//   1) CLI (preferido):
//        php /ruta/crecer/scripts/cron_publicar.php
//
//   2) URL protegida (si el host solo permite cron por HTTP):
//        https://tu-dominio/crecer/scripts/cron_publicar.php?key=XXXX
//      donde XXXX === CRON_TOKEN (definir en config.local.php).
//
//  Si CRON_TOKEN no está definido, el acceso por HTTP se bloquea
//  (solo CLI), para no dejar el endpoint abierto.
// ============================================================

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/publicador.php';

$es_cli = (PHP_SAPI === 'cli');

if (!$es_cli) {
    $token = defined('CRON_TOKEN') ? CRON_TOKEN : '';
    $key   = $_GET['key'] ?? '';
    if ($token === '' || !hash_equals($token, (string)$key)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "403 — cron no autorizado.\n";
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
}

$inicio = microtime(true);
try {
    $res = correr_publicador($pdo, 25);
    $res['ms'] = (int)round((microtime(true) - $inicio) * 1000);
    if ($es_cli) {
        echo "[" . date('Y-m-d H:i:s') . "] publicador: "
           . "revisadas={$res['revisadas']} publicadas={$res['publicadas']} fallidas={$res['fallidas']} "
           . "({$res['ms']}ms)\n";
        foreach ($res['detalle'] as $d) {
            echo "   #{$d['contenido_id']} → {$d['estado']}"
               . ($d['motivo'] ? " ({$d['motivo']})" : '') . "\n";
        }
    } else {
        echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
} catch (Throwable $e) {
    error_log('cron_publicar: ' . $e->getMessage());
    if ($es_cli) { fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n"); exit(1); }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
