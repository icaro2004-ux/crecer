<?php
// ============================================================
//  CRECER — UN PROCESO QUE DECIDE, PARA QUE DOS DECIDAN A LA VEZ
//  tests/_cand_decidir_runner.php
//
//  Los dos botones de la comparacion estan en la misma pantalla y un dedo
//  nervioso puede tocar los dos. Aqui se lanzan dos procesos, uno con cada
//  decision, y se comprueba que gana UNA sola y que el otro se entera de cual
//  fue — nunca que se alternen, nunca que se aplique algo ya descartado.
//
//  Este SI va por HTTP a proposito: la carrera de decisiones tiene que
//  aguantar el camino completo, con su csrf y su handler.
//
//    php tests/_cand_decidir_runner.php <sid> <marca> <contenido> <gen> <csrf> <decision>
// ============================================================

$sid   = (string)($argv[1] ?? '');
$marca = (int)($argv[2] ?? 0);
$cid   = (int)($argv[3] ?? 0);
$gen   = (int)($argv[4] ?? 0);
$csrf  = (string)($argv[5] ?? '');
$dec   = (string)($argv[6] ?? '');

$c = stream_context_create(['http' => [
    'method'  => 'POST',
    'header'  => "Cookie: PHPSESSID={$sid}\r\nContent-Type: application/x-www-form-urlencoded\r\n",
    'content' => http_build_query(['ajax' => 1, 'csrf' => $csrf, 'accion' => 'cand_decidir',
                                   'id' => $cid, 'gen' => $gen, 'decision' => $dec]),
    'timeout' => 30, 'ignore_errors' => true]]);
echo "\n" . trim((string)@file_get_contents(
    'http://localhost/crecer/panel/aprobar2.php?marca=' . $marca, false, $c)) . "\n";
