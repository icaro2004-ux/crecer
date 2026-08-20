<?php
// ============================================================
//  CRECER — LANZADOR DE panel/gateway_post.php PARA PRUEBAS
//  tests/_gateway_runner.php
//
//  Corre el endpoint de verdad —con su login, su router de gateway y
//  su acción 'regenerar_imagen'— e imprime el JSON que devuelve.
//
//  Ahí estaba el tercer camino que podía generar dos imágenes: si
//  encolar no devolvía id, caía al motor viejo en el acto.
//
//  La sesión se deja escrita ANTES de que auth.php la arranque: al
//  fijar session_id en este proceso, el session_start() de auth.php
//  retoma esta misma y encuentra al usuario ya dentro.
//
//    php tests/_gateway_runner.php <usuario> <marca> <pieza> <sim>
// ============================================================

$uid = (int)($argv[1] ?? 0);
$mid = (int)($argv[2] ?? 0);
$pid = (int)($argv[3] ?? 0);
$GLOBALS['AW_SIM'] = (string)($argv[4] ?? '');
if (!$uid || !$mid || !$pid) { fwrite(STDERR, "uso: gateway_runner <usuario> <marca> <pieza> <sim>\n"); exit(2); }

require __DIR__ . '/_sin_gasto.php';

$csrf = bin2hex(random_bytes(16));
session_id('pruebagw' . getmypid());
session_start();
$_SESSION['usuario_id'] = $uid;
$_SESSION['gw_test']    = 1;     // ?gw=1 pegajoso: camina el gateway aunque sea admin
$_SESSION['csrf']       = $csrf;
session_write_close();

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI']    = '/crecer/panel/gateway_post.php';
$_GET  = ['marca' => (string)$mid, 'gw' => '1'];
$_POST = ['accion' => 'regenerar_imagen', 'con_texto' => '0', 'csrf' => $csrf];

require dirname(__DIR__) . '/panel/gateway_post.php';
