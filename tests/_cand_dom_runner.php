<?php
// ============================================================
//  CRECER — UN PROCESO QUE ABRE UNA INTENCION, PARA QUE DOS COMPITAN
//  tests/_cand_dom_runner.php
//
//  Dos llamadas seguidas dentro del mismo PHP no prueban un candado: se ejecutan
//  una detras de otra. Para probar el arbitraje hacen falta dos procesos DE
//  VERDAD pisandose, que es lo que pasa cuando alguien toca el boton dos veces
//  con la señal justa.
//
//  LA CITA. Y ni con dos procesos basta: arrancar PHP cuesta ~200 ms, asi que el
//  primero termina antes de que el segundo empiece y la prueba pasa sin haber
//  concurrido nunca. Los dos esperan al MISMO instante de reloj antes de salir.
//
//  POR QUE CONTRA EL DOMINIO Y NO POR HTTP: dos peticiones con la misma cookie
//  se serializan solas —PHP bloquea el fichero de sesion— asi que por HTTP no
//  llegan a pisarse. Aqui se ataca la funcion directamente, que es donde vive el
//  candado que se quiere probar.
//
//    php tests/_cand_dom_runner.php <marca> <contenido> <cita>
//
//  Imprime una linea de JSON.
// ============================================================

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/candidata.php';

$m = (int)($argv[1] ?? 0);
$c = (int)($argv[2] ?? 0);
$cita = (float)($argv[3] ?? 0);
while (microtime(true) < $cita) usleep(300);

$t0 = microtime(true);
$r = cand_abrir($pdo, $m, $c, CAND_MISMA_IDEA);
echo "\n" . json_encode([
    'ok'      => !empty($r['ok']),
    'gen'     => (int)($r['gen']['id'] ?? 0),
    'reusada' => !empty($r['reusada']),
    //  Cuanto tardo: el que espero al candado tarda mas que el que lo tuvo.
    'ms'      => (int)round((microtime(true) - $t0) * 1000),
]) . "\n";
