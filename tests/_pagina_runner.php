<?php
// ============================================================
//  CRECER - LANZADOR DE UNA PAGINA DEL PANEL, EN PROCESO
//  tests/_pagina_runner.php
//
//  Corre una pagina REAL de este arbol y escupe su HTML. Sin Apache y sin
//  navegador, que es justo lo que lo hace seguro: `_sin_gasto.php` cierra la
//  red en ESTE proceso, y como no hay navegador no hay nadie que se escape a
//  rutas absolutas `/crecer/...` -es decir, al OTRO worktree, donde el
//  centinela no esta y las llamadas se pagan de verdad.
//
//  Proceso aparte a proposito: las paginas terminan en exit().
//
//    php tests/_pagina_runner.php <usuario> <marca> <pagina.php> [query]
//
//  Ej: php tests/_pagina_runner.php 12 34 calendario.php "vista=semana"
// ============================================================
$uid  = (int)($argv[1] ?? 0);
$mid  = (int)($argv[2] ?? 0);
$pag  = (string)($argv[3] ?? '');
$qry  = (string)($argv[4] ?? '');
if (!$uid || !$mid || $pag === '') { fwrite(STDERR, "uso: _pagina_runner <usuario> <marca> <pagina.php> [query]\n"); exit(2); }
if (!preg_match('~^[a-z0-9_]+\.php$~', $pag)) { fwrite(STDERR, "pagina invalida\n"); exit(2); }

//  Sin llave de worker no se dispara ningun worker por HTTP. Renderizar una
//  pagina no deberia despertar a nadie, y asi se garantiza.
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
session_id('pagrun' . getmypid());
session_start();
$_SESSION['usuario_id'] = $uid;
$_SESSION['csrf']       = $csrf;
session_write_close();

parse_str($qry, $extra);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/crecer/panel/' . $pag;
$_SERVER['HTTP_HOST']      = 'localhost';
$_GET  = ['marca' => (string)$mid] + (array)$extra;
$_POST = [];

$ruta = dirname(__DIR__) . '/panel/' . $pag;
if (!is_file($ruta)) { fwrite(STDERR, "no existe: {$ruta}\n"); exit(2); }
require $ruta;
