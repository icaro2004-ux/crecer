<?php
// ============================================================
//  CRECER — A QUE HOST APUNTARIA UN DISPARADOR  ·  tests/_worker_host_runner.php
//
//  Corre SIN CRECER_TEST_MODE a proposito: hace falta comprobar que la eleccion
//  de destino es sana tambien cuando el cierre duro no esta puesto. Es lo unico
//  que se hace aqui — se PREGUNTA el host y se imprime. No se arma ninguna URL,
//  no se abre ningun curl, no se despierta a nadie.
//
//    php tests/_worker_host_runner.php <caso>
//
//  casos:  cli       sin cabecera Host (el caso de los crons y de las pruebas)
//          valido    Host que esta en la lista
//          puerto    Host de la lista con puerto
//          forjado   Host de un tercero (el ataque que la lista existe para parar)
// ============================================================

$caso = (string)($argv[1] ?? 'cli');

switch ($caso) {
    case 'valido':  $_SERVER['HTTP_HOST'] = 'localhost';        break;
    case 'puerto':  $_SERVER['HTTP_HOST'] = 'localhost:8080';   break;
    case 'forjado': $_SERVER['HTTP_HOST'] = 'servidor-ajeno.example'; break;
    default:        unset($_SERVER['HTTP_HOST']);               break;
}

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/worker_key.php';

echo 'MODO_PRUEBA=' . ((defined('CRECER_TEST_MODE') && CRECER_TEST_MODE) ? '1' : '0') . "\n";
echo 'BASE_URL=' . (defined('BASE_URL') ? BASE_URL : '') . "\n";
try {
    echo 'HOST=' . worker_host() . "\n";
    echo 'ESQUEMA=' . worker_esquema(worker_host()) . "\n";
} catch (Throwable $e) {
    echo 'LANZO=' . get_class($e) . "\n";
}
