<?php
// ============================================================
//  CRECER — Cron del CORILLO AUTÓNOMO
//  scripts/cron_corillo.php
//
//  Corre el trabajo autónomo: para cada marca con piloto automático
//  activo y plan vigente, el corillo planifica y redacta los posts
//  que falten y los deja listos para aprobar. Avisa al dueño por email.
//
//  Cadencia sugerida: 1 vez por semana (ej. lunes 7am) en Hostinger.
//   CLI:  php /ruta/crecer/scripts/cron_corillo.php
//   URL:  https://tu-dominio/crecer/scripts/cron_corillo.php?key=CRON_TOKEN
// ============================================================

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/agentes.php';

$es_cli = (PHP_SAPI === 'cli');
if (!$es_cli) {
    $token = defined('CRON_TOKEN') ? CRON_TOKEN : '';
    if ($token === '' || !hash_equals($token, (string)($_GET['key'] ?? ''))) {
        http_response_code(403); header('Content-Type: text/plain; charset=utf-8');
        echo "403 — cron no autorizado.\n"; exit;
    }
    header('Content-Type: application/json; charset=utf-8');
}

@set_time_limit(0);
$inicio = microtime(true);
try {
    $res = correr_corillo($pdo);
    // ADR-0004: el Analista vigila cada marca procesada y deja señales accionables (autónomo).
    try {
        require_once __DIR__ . '/../includes/analista.php';
        $an_total = 0;
        foreach (($res['detalle'] ?? []) as $d) { $an_total += analista_vigilar($pdo, (int)$d['marca_id']); }
        $res['analista_senales'] = $an_total;
    } catch (Throwable $e) { error_log('cron analista: ' . $e->getMessage()); }
    $res['ms'] = (int)round((microtime(true) - $inicio) * 1000);
    if ($es_cli) {
        echo "[" . date('Y-m-d H:i:s') . "] corillo autónomo: "
           . "marcas={$res['marcas']} posts_creados={$res['creadas']} ({$res['ms']}ms)\n";
        foreach ($res['detalle'] as $d) {
            echo "   marca #{$d['marca_id']} → {$d['creadas']} post(s)"
               . ($d['razon'] ? " ({$d['razon']})" : '') . "\n";
        }
    } else {
        echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
} catch (Throwable $e) {
    error_log('cron_corillo: ' . $e->getMessage());
    if ($es_cli) { fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n"); exit(1); }
    http_response_code(500); echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
