<?php
// ============================================================
//  CRECER — LANZADOR DEL BARRIDO PARA PRUEBAS
//  tests/_sweep_runner.php
//
//  Corre img_sweep_pendientes() de verdad, con las llaves anuladas
//  y el error_log desviado a un archivo.
//
//  Ese desvío es el punto: cuando el barrido decide rescatar una
//  pieza escribe "rescate #<id>: cae a GEMINI" ANTES de llamar al
//  proveedor. Sin llaves la llamada falla y no deja ni rastro en la
//  base, así que mirar la base no distingue "no la rescató" de "la
//  rescató y no pudo". La línea del log sí.
//
//    php tests/_sweep_runner.php <marca> <archivo_log>
// ============================================================

$mid = (int)($argv[1] ?? 0);
$log = (string)($argv[2] ?? '');
if (!$mid || $log === '') { fwrite(STDERR, "uso: sweep_runner <marca> <archivo_log>\n"); exit(2); }

define('OPENAI_API_KEY', '');
define('GEMINI_API_KEY', '');
define('GCP_PROJECT_ID', '');
define('GOOGLE_APPLICATION_CREDENTIALS', '');

@ini_set('log_errors', '1');
@ini_set('error_log', $log);

require dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/img_responses.php';

img_sweep_pendientes($pdo, $mid);
