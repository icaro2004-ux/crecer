<?php
// ============================================================
//  CRECER — RENDERIZA UNA PANTALLA DEL PANEL, DE VERDAD
//  tests/_render_runner.php
//
//  Sirve para probar RECORRIDOS y no cadenas: la pantalla se pide como la
//  pediria un navegador —con su sesion, su router y su marca real— y se
//  imprime el HTML que de verdad salio. Buscar en el fuente solo demuestra que
//  alguien escribio una linea; buscar en la salida demuestra que esa linea se
//  ejecuto para una peticion concreta y con los datos correctos.
//
//  Hace falta un proceso aparte porque estas pantallas terminan en exit() y
//  arrancan sesion.
//
//    php tests/_render_runner.php <usuario> <pantalla.php> <query> [metodo] [post]
//
//  <query> y [post] van como cadena tipo `a=1&b=2`.
// ============================================================

$uid      = (int)($argv[1] ?? 0);
$pantalla = (string)($argv[2] ?? '');
$query    = (string)($argv[3] ?? '');
$metodo   = strtoupper((string)($argv[4] ?? 'GET'));
$cuerpo   = (string)($argv[5] ?? '');

if (!$uid || $pantalla === '' || !preg_match('/^[a-z0-9_]+\.php$/', $pantalla)) {
    fwrite(STDERR, "uso: render_runner <usuario> <pantalla.php> <query> [GET|POST] [post]\n");
    exit(2);
}

require __DIR__ . '/_sin_gasto.php';

$csrf = bin2hex(random_bytes(16));
session_id('render' . getmypid());
session_start();
$_SESSION['usuario_id'] = $uid;
$_SESSION['csrf']       = $csrf;
// OJO: nada de gw_test aqui. Ese flag fuerza caminar el gateway y desvia las
// pantallas del panel a la venta: la peticion se iba en un redirect sin cuerpo
// y la prueba veia una pagina vacia sin saber por que.
session_write_close();

parse_str($query, $_GET);
$_POST = [];
if ($metodo === 'POST') { parse_str($cuerpo, $_POST); $_POST['csrf'] = $csrf; }

$_SERVER['REQUEST_METHOD'] = $metodo;
$_SERVER['REQUEST_URI']    = '/crecer/panel/' . $pantalla . ($query !== '' ? '?' . $query : '');
$_SERVER['HTTP_HOST']      = 'localhost';

require dirname(__DIR__) . '/panel/' . $pantalla;
