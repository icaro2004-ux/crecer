<?php
// ============================================================
//  CRECER - INTENTA PUBLICAR EL POST GRATIS, EN PROCESO
//  tests/_publicar_gratis_runner.php
//
//  Llama al endpoint REAL `publicar_manual` de panel/gateway_post.php y escupe
//  su JSON. Sirve para comprobar la puerta del telefono SIN mandar un SMS:
//  quien decide es el servidor (`$necesita_telefono`), no el boton, asi que
//  preguntarle al servidor es la comprobacion honesta. Twilio no se toca.
//
//    php tests/_publicar_gratis_runner.php <usuario> <marca>
// ============================================================
$uid = (int)($argv[1] ?? 0);
$mid = (int)($argv[2] ?? 0);
if (!$uid || !$mid) { fwrite(STDERR, "uso: _publicar_gratis_runner <usuario> <marca>\n"); exit(2); }

define('CRECER_WORKER_KEY', '');
//  SE PRUEBA LA PUERTA DE PRODUCCION, NO EL ATAJO DE DESARROLLO.
//  config.local.php trae CRECER_DEV_ACTIVAR=true para no tener que pagar
//  mientras se desarrolla, y eso da acceso_full a CUALQUIER cuenta local:
//  con el puesto, la puerta del telefono ni se consulta y publicar decia
//  ok sin verificar nada. En produccion crecer_entorno_local() es falso y
//  la puerta si existe. define() es primero-gana, asi que apagarlo aqui
//  -antes de que cargue el config- deja al runner viendo lo que ve un
//  cliente de verdad.
define('CRECER_DEV_ACTIVAR', false);
require __DIR__ . '/_sin_gasto.php';

$csrf = bin2hex(random_bytes(16));
session_id('pubrun' . getmypid());
session_start();
$_SESSION['usuario_id'] = $uid;
$_SESSION['csrf']       = $csrf;
session_write_close();

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI']    = '/crecer/panel/gateway_post.php';
$_SERVER['HTTP_HOST']      = 'localhost';
$_GET  = ['marca' => (string)$mid];
$_POST = ['accion' => 'publicar_manual', 'csrf' => $csrf];

require dirname(__DIR__) . '/panel/gateway_post.php';
