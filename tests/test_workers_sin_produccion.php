<?php
// ============================================================
//  CRECER — UNA PRUEBA NO PUEDE LLAMAR A PRODUCCION
//  tests/test_workers_sin_produccion.php
//
//  EL DEFECTO. worker_host() terminaba en `return 'encuentraloahora.com';`.
//  Esa lista de arriba VALIDA una cabecera Host; ese literal elegia un DESTINO.
//  Son dos decisiones distintas y compartian respuesta, asi que cualquier
//  proceso sin cabecera Host —o sea, TODA la linea de comandos— apuntaba a
//  produccion. Las pruebas locales llevaban meses lanzando HTTPS al dominio real
//  con la llave de desarrollo. Se veia en el log como «HTTP 403» y pasaba por
//  ruido inofensivo.
//
//  NO LO ERA. El 403 no es una defensa, es una coincidencia: depende de que la
//  llave local NO coincida con la de produccion. El dia que coincidieran —una
//  copia de config, un QA que reusa la llave buena— una corrida de pruebas
//  encolaria trabajo REAL y gastaria dinero REAL en la cuenta del negocio. La
//  defensa no puede ser el codigo de respuesta del otro lado.
//
//  EL CIERRE, EN DOS CAPAS Y UNA REGLA:
//
//    · regla   el destino sale de BASE_URL —lo que el operador declaro para
//              ESTA instalacion— y nunca de un literal. Una maquina sin
//              declarar solo puede apuntarse a si misma (db.php cae a
//              localhost). No queda ningun dominio ajeno escrito en el codigo.
//    · capa 1  worker_puede_disparar() dice «no» en modo prueba. Silenciosa:
//              los disparadores se van por su camino de siempre.
//    · capa 2  worker_host() LANZA en modo prueba, antes de armar la URL. Es la
//              que no se puede saltar, ni olvidando preguntar ni escribiendo un
//              disparador nuevo mañana.
//
//  Y SE COMPRUEBA POR CONSTRUCCION, no por buena fe: las dos ultimas secciones
//  leen el CODIGO FUENTE y afirman que no queda ningun dominio externo en el
//  camino de disparo y que todo el que arma una URL de worker pregunta antes.
// ============================================================

//  El modo prueba se declara AQUI, arriba del todo: esta prueba trata
//  precisamente de lo que pasa con el puesto.
if (!defined('CRECER_TEST_MODE')) define('CRECER_TEST_MODE', true);
//  Y se declara tambien el permiso del transporte de IA, a proposito: es la
//  rendija por la que se colaba: certifica que un runner sustituyo
//  ia_http_get_res, que NO es por donde salen los disparadores. Si el cierre
//  aceptara este permiso, lo de abajo volveria a salir a la red.
if (!defined('CRECER_TEST_RED_FALSA')) define('CRECER_TEST_RED_FALSA', true);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/worker_key.php';
require_once __DIR__ . '/../includes/img_responses.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nUNA PRUEBA NO PUEDE LLAMAR A PRODUCCION\n" . str_repeat('=', 60) . "\n";

$RAIZ = dirname(__DIR__);

// ── 1 · EL CIERRE DURO ──────────────────────────────────────────────────────
echo "\n  — en modo prueba no se despierta a nadie —\n";
$lanzo = false;
try { worker_host(); } catch (Throwable $e) { $lanzo = true; $clase = get_class($e); }
ok('worker_host() LANZA en modo prueba', $lanzo,
   'es la capa que no se puede saltar: va antes de armar la URL');
ok('y lanza el tipo de red bloqueada', $lanzo && ($clase ?? '') === 'RedBloqueada',
   'salio ' . ($clase ?? 'nada'));
ok('CRECER_TEST_RED_FALSA NO abre la puerta de los disparadores',
   worker_red_cerrada() === true,
   'ese permiso es del transporte de IA; los workers usan curl a pelo');
ok('worker_puede_disparar() dice que no', worker_puede_disparar('arte') === false);

echo "\n  — y el disparador de verdad se queda quieto —\n";
$r = arte_disparar(1, 1);
ok('arte_disparar no sale a la red',        $r['disparado'] === false);
ok('sin codigo HTTP, porque no hubo curl',  (int)$r['http'] === 0);
ok('y dice el motivo real, no «sin_llave»', $r['err'] === 'red_cerrada',
   "dijo «{$r['err']}» · un diagnostico que miente sobre por que no arranco cuesta una tarde");

// ── 2 · EL DESTINO, FUERA DE MODO PRUEBA ────────────────────────────────────
//  En otro proceso, porque aqui el modo prueba ya esta puesto y no se puede
//  quitar: las constantes no se redefinen.
echo "\n  — sin cabecera Host, el destino sale de BASE_URL —\n";
$RUNNER = __DIR__ . DIRECTORY_SEPARATOR . '_worker_host_runner.php';
$correr = function (string $caso) use ($RUNNER): array {
    $sal = []; exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($RUNNER) . ' '
                    . escapeshellarg($caso) . ' 2>&1', $sal);
    $out = [];
    foreach ($sal as $l) { if (strpos($l, '=') !== false) { [$k, $v] = explode('=', trim($l), 2); $out[$k] = $v; } }
    return $out;
};

$cli = $correr('cli');
ok('el runner corre SIN modo prueba', ($cli['MODO_PRUEBA'] ?? '') === '0',
   'si no, no se estaria probando la eleccion de destino');
$host_base = (string)parse_url((string)($cli['BASE_URL'] ?? ''), PHP_URL_HOST);
ok('sin Host, apunta al host de BASE_URL', ($cli['HOST'] ?? '') === $host_base && $host_base !== '',
   'dio «' . ($cli['HOST'] ?? '?') . '» y BASE_URL es ' . ($cli['BASE_URL'] ?? '?'));
ok('que en esta maquina NO es produccion', ($cli['HOST'] ?? '') === 'localhost',
   'dio «' . ($cli['HOST'] ?? '?') . '» · una instalacion sin declarar solo puede llamarse a si misma');
ok('y por http, no https contra un dominio real', ($cli['ESQUEMA'] ?? '') === 'http');

echo "\n  — la lista de hosts sigue haciendo su trabajo —\n";
$val = $correr('valido');
ok('un Host de la lista se respeta',        ($val['HOST'] ?? '') === 'localhost');
$pue = $correr('puerto');
ok('con puerto tambien',                    ($pue['HOST'] ?? '') === 'localhost:8080');
$for = $correr('forjado');
ok('un Host forjado NO se usa',             ($for['HOST'] ?? '') !== 'servidor-ajeno.example',
   'dio «' . ($for['HOST'] ?? '?') . '» · con esa cabecera se le regalaba la llave a un tercero');
ok('y cae al host declarado, no a un dominio ajeno', ($for['HOST'] ?? '') === 'localhost',
   'dio «' . ($for['HOST'] ?? '?') . '»');

// ── 3 · POR CONSTRUCCION: NO QUEDA NINGUN DOMINIO ESCRITO ───────────────────
//  Esto es lo que convierte el arreglo en una propiedad del codigo y no en una
//  promesa. Si mañana alguien vuelve a escribir el dominio en un disparador,
//  esta prueba lo dice — aunque nadie ejecute ese camino.
echo "\n  — por construccion: ningun dominio ajeno en el camino de disparo —\n";
$DISPARADORES = [
    'includes/worker_key.php', 'includes/img_responses.php', 'includes/carrusel.php',
    'includes/gen_async.php', 'includes/meta_async.php', 'includes/publicador.php',
    'includes/reels.php', 'includes/relevo_demo.php', 'includes/sala_async.php',
    'includes/muestra.php', 'panel/arte_worker.php', 'panel/reels.php',
];
//  LO QUE SE PERSIGUE, CON PRECISION. No «un dominio en el archivo»: los hay
//  legitimos y no tienen nada que ver con esto — la lista blanca de
//  worker_host() VALIDA cabeceras (no elige destino), y publicador/reels arman
//  enlaces publicos para correos y assets. Lo que no puede existir es un
//  dominio escrito en la linea que construye la URL de un WORKER, que es la
//  unica que lleva la llave.
$sucios = [];
foreach ($DISPARADORES as $rel) {
    $abs = $RAIZ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_file($abs)) continue;
    foreach (explode("\n", (string)file_get_contents($abs)) as $i => $linea) {
        if (preg_match('~^\s*(//|\*|#)~', $linea)) continue;      // los comentarios SI lo nombran
        if (strpos($linea, '_worker.php') === false) continue;    // solo la linea que arma la URL
        if (preg_match('~[\'"][^\'"]*\b[a-z0-9-]+\.(com|net|org|io|app)\b~i', $linea)) {
            $sucios[] = $rel . ':' . ($i + 1) . '  ' . trim($linea);
        }
    }
}
ok('ninguna URL de worker lleva un dominio escrito', $sucios === [],
   implode("\n         ", array_slice($sucios, 0, 6)));

//  Y EL RESPALDO DE worker_host() TAMPOCO PUEDE SER UN LITERAL. Este es el
//  defecto exacto que se cierra: `return 'encuentraloahora.com';` al final de
//  la funcion. Se lee su cuerpo y se exige que lo ultimo que devuelve salga de
//  worker_host_declarado(), no de una cadena.
$wk   = (string)file_get_contents($RAIZ . '/includes/worker_key.php');
$ini  = strpos($wk, 'function worker_host(');
$fin  = strpos($wk, 'function worker_host_declarado(');
$cuerpo = ($ini !== false && $fin !== false && $fin > $ini) ? substr($wk, $ini, $fin - $ini) : '';
ok('se pudo leer el cuerpo de worker_host()', $cuerpo !== '');
ok('worker_host() no devuelve ningun dominio literal',
   $cuerpo !== '' && !preg_match('~return\s+[\'"][^\'"]*\.[a-z]{2,}[\'"]~i', $cuerpo),
   'el respaldo tiene que salir de BASE_URL, que la declara el operador');
ok('y su respaldo es worker_host_declarado()',
   $cuerpo !== '' && strpos($cuerpo, 'return worker_host_declarado();') !== false);

// ── 4 · POR CONSTRUCCION: TODO EL QUE ARMA UNA URL, PREGUNTA ANTES ──────────
echo "\n  — por construccion: quien arma una URL de worker pregunta antes —\n";
$sin_puerta = [];
foreach ($DISPARADORES as $rel) {
    $abs = $RAIZ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_file($abs)) continue;
    $src = (string)file_get_contents($abs);
    //  worker_key.php es quien DEFINE las dos funciones: no se pregunta a si mismo.
    if (basename($rel) === 'worker_key.php') continue;
    $lineas = explode("\n", $src);
    foreach ($lineas as $i => $linea) {
        if (!preg_match('~(?<![a-z_])(worker_host|worker_url)\s*\(~', $linea)) continue;
        if (preg_match('~^\s*(//|\*|#)~', $linea)) continue;
        //  La puerta tiene que estar ANTES, en las 25 lineas de arriba: es donde
        //  cabe el cuerpo de un disparador entero.
        $antes = implode("\n", array_slice($lineas, max(0, $i - 25), min(25, $i)));
        if (strpos($antes, 'worker_puede_disparar') === false) {
            $sin_puerta[] = $rel . ':' . ($i + 1) . '  ' . trim($linea);
        }
    }
}
ok('ninguno arma la URL sin pasar por worker_puede_disparar()', $sin_puerta === [],
   implode("\n         ", array_slice($sin_puerta, 0, 6)));

// ── 5 · Y LA PRUEBA NO DEJO NADA SALIR ──────────────────────────────────────
echo "\n  — la cuenta —\n";
ok('modo prueba puesto de principio a fin', defined('CRECER_TEST_MODE') && CRECER_TEST_MODE);
ok('cero DDL', true, 'esta prueba no crea ni altera tablas');

echo "\n" . str_repeat('=', 60) . "\n";
echo $fallos ? "  {$fallos} FALLAS de {$n}\n\n" : "  TODO OK · {$n} pruebas\n\n";
exit($fallos ? 1 : 0);
