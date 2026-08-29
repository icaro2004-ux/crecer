<?php
// ============================================================
//  CRECER - LANZADOR DE panel/gateway_post.php EN GET, PARA PRUEBAS
//  tests/_preparacion_gateway_runner.php
//
//  Corre la PAGINA de verdad -con su login, su router de gateway y la puerta
//  del momento de venta- e imprime el HTML que sirve. Es lo unico que prueba
//  que la pantalla de preparacion llega al dueño: la vista se puede renderizar
//  sola y aun asi no servirse nunca si la puerta no la elige.
//
//  Corre en un proceso aparte a proposito: la pagina hace exit().
//
//    php tests/_preparacion_gateway_runner.php <usuario> <marca>
// ============================================================
$uid = (int)($argv[1] ?? 0);
$mid = (int)($argv[2] ?? 0);
if (!$uid || !$mid) { fwrite(STDERR, "uso: _preparacion_gateway_runner <usuario> <marca>\n"); exit(2); }

//  Sin llave de worker el disparo no sale y muestra_arrancar suelta el lock; da
//  igual para lo que se mide aqui (que la PUERTA elija la pantalla), y garantiza
//  que este runner no encola trabajo de verdad.
define('CRECER_WORKER_KEY', '');
require __DIR__ . '/_sin_gasto.php';

$csrf = bin2hex(random_bytes(16));
session_id('prepgw' . getmypid());
session_start();
$_SESSION['usuario_id'] = $uid;
$_SESSION['csrf']       = $csrf;
session_write_close();

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/crecer/panel/gateway_post.php';
$_GET  = ['marca' => (string)$mid];
$_POST = [];

require dirname(__DIR__) . '/panel/gateway_post.php';
