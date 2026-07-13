<?php
// ============================================================
//  CRECER — Cron de MÉTRICAS (alcance / interacciones)
//  scripts/cron_metricas.php
//
//  Recorre las marcas con conexión Meta activa y refresca los
//  insights de sus posts publicados (los que faltan o están
//  viejos). Pensado para un cron de Hostinger cada ~1-2 h.
//
//   1) CLI:  php /ruta/crecer/scripts/cron_metricas.php
//   2) HTTP: https://tu-dominio/crecer/scripts/cron_metricas.php?key=XXXX
//            (XXXX === CRON_TOKEN en config.local.php)
//
//  Sin CRON_TOKEN definido, el acceso por HTTP se bloquea (solo CLI).
// ============================================================

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/metricas.php';

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
    // Marcas con conexión Meta activa.
    $marcas = $pdo->query(
        "SELECT marca_id FROM crecer_conexiones
         WHERE estado='activa' AND page_access_token IS NOT NULL"
    )->fetchAll(PDO::FETCH_COLUMN);

    $tot = ['marcas'=>0, 'guardadas'=>0, 'errores'=>0];
    $detalle = [];
    foreach ($marcas as $mid) {
        $r = metricas_refrescar_insights($pdo, (int)$mid, 25, 6);
        $tot['marcas']++;
        $tot['guardadas'] += (int)($r['n'] ?? 0);
        $tot['errores']   += count($r['errores'] ?? []);
        $detalle[] = ['marca'=>(int)$mid] + $r;
    }
    $tot['ms'] = (int)round((microtime(true) - $inicio) * 1000);

    if ($es_cli) {
        echo "[" . date('Y-m-d H:i:s') . "] metricas: "
           . "marcas={$tot['marcas']} guardadas={$tot['guardadas']} errores={$tot['errores']} ({$tot['ms']}ms)\n";
        foreach ($detalle as $d) {
            if (!empty($d['errores'])) {
                foreach ($d['errores'] as $e) echo "   ! marca {$d['marca']}: {$e}\n";
            }
        }
    } else {
        echo json_encode(['ok'=>true, 'total'=>$tot, 'detalle'=>$detalle], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
} catch (Throwable $e) {
    error_log('cron_metricas: ' . $e->getMessage());
    if ($es_cli) { fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n"); exit(1); }
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
