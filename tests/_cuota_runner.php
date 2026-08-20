<?php
// ============================================================
//  CRECER — UN PROCESO QUE PIDE UNA UNIDAD DE CUOTA
//  tests/_cuota_runner.php
//
//  El tope de imagenes solo significa algo si aguanta peticiones SIMULTANEAS.
//  Con la version anterior —INSERT..SELECT..WHERE (SELECT SUM(...))— seis
//  procesos podian leer la misma suma y entrar los seis: el agregado lee una
//  instantanea, no la fila viva. Este runner es el que lo demuestra.
//
//    php tests/_cuota_runner.php <marca> <origen_id> <arranque_unix>
//
//  Imprime una linea: `RESERVO <id>` o `SIN_CUOTA` (o `ERROR: ...`).
// ============================================================

$marca    = (int)($argv[1] ?? 0);
$origen   = (int)($argv[2] ?? 0);
$arranque = (float)($argv[3] ?? 0);

require __DIR__ . '/_sin_gasto.php';
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/cuota_imagenes.php';

// Conectar ANTES de la cita: si no, el coste de conectar los separa y no chocan.
try { $pdo->query('SELECT 1'); } catch (Throwable $e) {}

$espera = $arranque - microtime(true);
if ($espera > 0) usleep((int)($espera * 1000000));

try {
    $r = CuotaImg::reservar($pdo, CuotaCtx::de($pdo, $marca, 'arte_post', 'carrera',
        ['origen_tipo' => 'contenido', 'origen_id' => $origen, 'costo' => 0.17]));
    echo $r['ok'] ? "RESERVO {$r['asiento_id']}\n" : "SIN_CUOTA\n";
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n";
}
