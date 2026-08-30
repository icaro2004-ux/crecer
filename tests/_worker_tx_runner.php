<?php
// ============================================================
//  CRECER — LA PUERTA DEL DISPARO CON UNA TRANSACCION ABIERTA
//  tests/_worker_tx_runner.php
//
//  Corre SIN CRECER_TEST_MODE a proposito: con el modo prueba puesto la puerta
//  se cierra por otra razon y no se estaria comprobando nada. Aqui se pregunta
//  y punto — worker_puede_disparar() no abre ningun curl, solo decide.
//
//  Se responde tres veces la misma pregunta: fuera de transaccion, dentro, y
//  despues de confirmar.
// ============================================================

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/worker_key.php';

$di = fn(string $k, $v) => print($k . '=' . $v . "\n");

$di('MODO_PRUEBA', (defined('CRECER_TEST_MODE') && CRECER_TEST_MODE) ? '1' : '0');
$di('SIN_TX', worker_puede_disparar('prueba_tx') ? '1' : '0');

$pdo->beginTransaction();
$di('CON_TX', worker_puede_disparar('prueba_tx') ? '1' : '0');
$pdo->commit();

$di('TRAS_COMMIT', worker_puede_disparar('prueba_tx') ? '1' : '0');
