<?php
// ============================================================
//  CRECER — Cron del CONSERJE (responde comentarios)
//  scripts/cron_conserje.php
//
//  Recorre las marcas con conexión Meta activa y corre la ronda
//  del Conserje: lee comentarios nuevos de los posts publicados,
//  responde en la voz del negocio (con compuerta) o escala al
//  dueño. Pensado para cada ~30 min.
//
//   1) CLI:  php /ruta/crecer/scripts/cron_conserje.php
//   2) HTTP: .../scripts/cron_conserje.php?key=XXXX (CRON_TOKEN)
// ============================================================

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/conserje.php';

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
    $marcas = $pdo->query(
        "SELECT marca_id FROM crecer_conexiones
         WHERE estado='activa' AND page_access_token IS NOT NULL"
    )->fetchAll(PDO::FETCH_COLUMN);

    $tot = ['marcas'=>0, 'nuevos'=>0, 'respondidos'=>0, 'escalados'=>0, 'ignorados'=>0, 'errores'=>0];
    $detalle = [];
    foreach ($marcas as $mid) {
        $r = conserje_correr($pdo, (int)$mid, true);
        $tot['marcas']++;
        foreach (['nuevos','respondidos','escalados','ignorados'] as $k) $tot[$k] += (int)($r[$k] ?? 0);
        $tot['errores'] += count($r['errores'] ?? []);
        $detalle[] = ['marca'=>(int)$mid] + $r;
    }
    $tot['ms'] = (int)round((microtime(true) - $inicio) * 1000);

    if ($es_cli) {
        echo "[" . date('Y-m-d H:i:s') . "] conserje: "
           . "marcas={$tot['marcas']} nuevos={$tot['nuevos']} respondidos={$tot['respondidos']} "
           . "escalados={$tot['escalados']} ignorados={$tot['ignorados']} errores={$tot['errores']} ({$tot['ms']}ms)\n";
        foreach ($detalle as $d) {
            foreach (($d['errores'] ?? []) as $e) echo "   ! marca {$d['marca']}: {$e}\n";
        }
    } else {
        echo json_encode(['ok'=>true, 'total'=>$tot, 'detalle'=>$detalle], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
} catch (Throwable $e) {
    error_log('cron_conserje: ' . $e->getMessage());
    if ($es_cli) { fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n"); exit(1); }
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
