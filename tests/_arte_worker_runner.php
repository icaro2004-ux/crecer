<?php
// ============================================================
//  CRECER — LANZADOR DEL WORKER DE ARTE PARA PRUEBAS
//  tests/_arte_worker_runner.php
//
//  No es una prueba: es el envoltorio que deja correr
//  panel/arte_worker.php DE VERDAD desde la línea de comandos.
//
//  Existe porque el worker termina con exit() en cada uno de sus
//  caminos: dentro del proceso de la prueba, el primer escenario
//  mataría a los demás. Cada escenario se corre aquí, en su propio
//  proceso, contra el archivo real — con su bucle, sus salidas y
//  sus notificaciones — y la prueba mira lo que quedó en la base.
//
//    php tests/_arte_worker_runner.php <marca> <pieza> <sondeos> [fb] [sim]
//
//  <sim> sustituye SOLO el borde de red al crear el job (ver _sin_gasto.php).
// ============================================================

$mid = (int)($argv[1] ?? 0);
$pid = (int)($argv[2] ?? 0);
$max = (int)($argv[3] ?? 3);
$fb  = (string)($argv[4] ?? '');
$GLOBALS['AW_SIM'] = (string)($argv[5] ?? '');

if (!$mid || !$pid) { fwrite(STDERR, "uso: runner <marca> <pieza> <sondeos> [fb] [sim]\n"); exit(2); }

// Anula las llaves y, si toca, sustituye el borde de red. Va ANTES de db.php.
require __DIR__ . '/_sin_gasto.php';

// ARTE_WORKER_TEST salta el apretón de manos HTTP y la llave del worker; el
// resto del archivo —que es lo que se quiere probar— corre igual que en prod.
define('ARTE_WORKER_TEST', true);
define('ARTE_POLL_MAX', $max);
define('ARTE_POLL_ESPERA', 0);   // sin esperas: el bucle es lo que importa, no el reloj

$_GET = ['marca' => (string)$mid, 'id' => (string)$pid, 'key' => 'prueba'];
if ($fb === '1') $_GET['fb'] = '1';

require dirname(__DIR__) . '/panel/arte_worker.php';
