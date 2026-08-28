<?php
// ============================================================
//  CRECER — UN PROCESO QUE PREPARA UNA SEMANA, PARA QUE DOS COMPITAN
//  tests/_ciclo_runner.php
//
//  Dos llamadas seguidas dentro del mismo PHP no prueban un candado: se ejecutan
//  una detras de otra. Preparar una semana lo pueden pedir a la vez el boton del
//  dueño y el cron del domingo por la noche, y cada preparacion cuesta una
//  llamada al modelo y una tanda de jugadas. Si los dos entran, el dueño se
//  encuentra la semana duplicada y pagada dos veces.
//
//  LA CITA. Arrancar PHP cuesta ~200 ms, asi que sin un instante de reloj comun
//  el primero termina antes de que el segundo empiece y la prueba pasa sin haber
//  concurrido nunca.
//
//  CONTRA EL DOMINIO, no por HTTP: dos peticiones con la misma cookie se
//  serializan solas —PHP bloquea el fichero de sesion— y no llegarian a pisarse.
//  El candado que se quiere probar vive en ciclo_preparar().
//
//    php tests/_ciclo_runner.php <marca> <meta> <plan> <semana> <cita>
//
//  Imprime una linea de JSON.
// ============================================================

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/meta_ciclo.php';

$m    = (int)($argv[1] ?? 0);
$meta = (int)($argv[2] ?? 0);
$plan = (int)($argv[3] ?? 0);
$sem  = (int)($argv[4] ?? 0);
$cita = (float)($argv[5] ?? 0);
while (microtime(true) < $cita) usleep(300);

$t0 = microtime(true);
$r  = ciclo_preparar($pdo, $m, $meta, $plan, $sem);
echo "\n" . json_encode([
    'ok'      => !empty($r['ok']),
    'ya'      => !empty($r['ya']),
    'creadas' => (int)($r['creadas'] ?? 0),
    'semana'  => (int)($r['semana'] ?? 0),
    'ms'      => (int)round((microtime(true) - $t0) * 1000),
]) . "\n";
