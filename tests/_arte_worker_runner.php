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
//    php tests/_arte_worker_runner.php <marca> <pieza> <sondeos> [fb]
// ============================================================

$mid = (int)($argv[1] ?? 0);
$pid = (int)($argv[2] ?? 0);
$max = (int)($argv[3] ?? 3);
$fb  = (string)($argv[4] ?? '');

if (!$mid || !$pid) { fwrite(STDERR, "uso: runner <marca> <pieza> <sondeos> [fb]\n"); exit(2); }

// ── SIN CRÉDITOS, PASE LO QUE PASE ───────────────────────────
//  El defecto que se está probando es "llama al proveedor cuando no debe", y
//  config.local.php tiene llaves de verdad. Si la prueba corriera con el
//  defecto presente, lo demostraría gastando dinero.
//  Estas constantes se definen ANTES de que db.php cargue el config: define()
//  es primero-gana, así que las del config quedan en nada y todo camino que
//  intente cobrar falla en seco, sin red. La prueba puede fallar; no puede
//  facturar.
define('OPENAI_API_KEY', '');
define('GEMINI_API_KEY', '');
define('GCP_PROJECT_ID', '');
define('GOOGLE_APPLICATION_CREDENTIALS', '');

// ARTE_WORKER_TEST salta el apretón de manos HTTP y la llave del worker; el
// resto del archivo —que es lo que se quiere probar— corre igual que en prod.
define('ARTE_WORKER_TEST', true);
define('ARTE_POLL_MAX', $max);
define('ARTE_POLL_ESPERA', 0);   // sin esperas: el bucle es lo que importa, no el reloj

$_GET = ['marca' => (string)$mid, 'id' => (string)$pid, 'key' => 'prueba'];
if ($fb === '1') $_GET['fb'] = '1';

require dirname(__DIR__) . '/panel/arte_worker.php';
