<?php
// ============================================================
//  CRECER - UNA ACCION DEL SONDEO, EN PROCESO
//  tests/_preparacion_post_runner.php
//
//  Hace el POST REAL que hace la pantalla de espera contra
//  panel/gateway_post.php y escupe lo que conteste, tal cual. Sirve para
//  comprobar lo unico que ninguna prueba de HTML puede ver: que la conversacion
//  entre la pantalla y el servidor EXISTE y devuelve JSON.
//
//  Proceso aparte porque el endpoint termina en exit().
//
//    php tests/_preparacion_post_runner.php <usuario> <marca> <accion>
// ============================================================
$uid = (int)($argv[1] ?? 0);
$mid = (int)($argv[2] ?? 0);
$acc = (string)($argv[3] ?? '');
if (!$uid || !$mid || $acc === '') { fwrite(STDERR, "uso: _preparacion_post_runner <usuario> <marca> <accion>\n"); exit(2); }
if (!preg_match('~^[a-z0-9_]+$~', $acc)) { fwrite(STDERR, "accion invalida\n"); exit(2); }

//  Sin llave no se dispara ningun worker por HTTP: aqui se prueba el endpoint,
//  no el worker, y un disparo saldria del arbol de esta prueba.
define('CRECER_WORKER_KEY', '');
//  Que por stdout salga SOLO lo que escriba el endpoint. Los avisos de PHP
//  (constantes redefinidas por config.local.php) se colaban delante del JSON
//  y json_decode los leia como basura: la prueba culpaba al endpoint de un
//  ruido que era del arranque.
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
require __DIR__ . '/_sin_gasto.php';

$csrf = bin2hex(random_bytes(16));
session_id('prep' . getmypid());
session_start();
$_SESSION['usuario_id'] = $uid;
$_SESSION['csrf']       = $csrf;
session_write_close();

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI']    = '/crecer/panel/gateway_post.php';
$_SERVER['HTTP_HOST']      = 'localhost';
$_GET  = ['marca' => (string)$mid];
$_POST = ['accion' => $acc, 'csrf' => $csrf];

require dirname(__DIR__) . '/panel/gateway_post.php';
