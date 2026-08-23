<?php
// ============================================================
//  CRECER — LA FUNDACION DEL IDIOMA, A SOLAS
//  tests/test_i18n_fundacion_unidad.php
//
//  POR QUE ESTE LOTE VA SOLO, Y ANTES QUE NADA
//
//  El 22 de agosto de 2026 includes/i18n.php estreno dos `require_once` a
//  secas de core/I18n/. Los archivos no llegaron al servidor y murio
//  PRODUCCION ENTERA — publico y panel, con sesion y sin ella, en todos los
//  idiomas— porque i18n.php lo carga db.php, y db.php lo hace TODA pagina.
//
//    Failed opening required '.../core/I18n/Locale.php' in i18n.php:46
//    #0 includes/db.php(122): require_once()
//
//  Asi que los archivos suben SOLOS, sin que nadie los llame, se comprueba en
//  el servidor que llegaron y cargan, y solo entonces se conecta el motor (3B).
//
//  Y AL DESPLEGAR 3A APARECIO LA CAUSA DE VERDAD: la extension `intl` declara
//  una clase GLOBAL llamada Locale. Hostinger la tiene, este XAMPP no. «Cannot
//  declare class Locale» NO es un Throwable —es un E_ERROR de declaracion— y
//  ningun try/catch lo atrapa. De ahi el namespace Crecer\I18n.
//
//  ESTA PRUEBA CUBRE LA FUNDACION EN SI:
//   · que las dos clases carguen por su cuenta y no arrastren nada al hacerlo;
//   · que NO choquen con una clase global del mismo nombre;
//   · que los catalogos existan, se lean y cuadren entre si;
//   · que SOLO i18n.php las use — una pantalla que llamara a Locale por su
//     cuenta se saltaria la guardia de carga defensiva;
//   · y que esconderlas devuelva el español de siempre, no una pagina rota.
//
//  La guardia en si y sus cuatro modos de fallo se prueban aparte, en
//  tests/test_i18n_carga_defensiva.php.
//
//  CERO base de datos para lo de las clases, cero red.
// ============================================================

require_once __DIR__ . '/_sin_gasto.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

/** El namespace real. Ver la cabecera de core/I18n/Locale.php. */
const NS = 'Crecer\\I18n\\';

$RAIZ    = str_replace('\\', '/', dirname(__DIR__));
$DIR_I18N = $RAIZ . '/core/I18n';
$DIR_LANG = $RAIZ . '/lang';

echo "\nLA FUNDACION DEL IDIOMA · 3A inerte, 3B conectada\n" . str_repeat('=', 58) . "\n";

// ══════════════════════════════════════════════════════════════
//  1 · LOS ARCHIVOS ESTAN Y CARGAN DE VERDAD
// ══════════════════════════════════════════════════════════════
echo "\n  — 1 · cargan por su cuenta —\n";
foreach (['Locale', 'Catalogo'] as $c) {
    $p = $DIR_I18N . '/' . $c . '.php';
    ok("core/I18n/{$c}.php existe", is_file($p), $p);
    if (is_file($p)) require_once $p;
    ok("la clase Crecer\\I18n\\{$c} se declara", class_exists(NS . $c, false),
       'si no carga aqui, no cargara en el servidor');
}

//  NO PUEDEN ARRASTRAR MEDIA APLICACION AL INCLUIRSE. Un archivo que en el
//  `require` ya toca base de datos o abre sesion deja de ser inerte, y este
//  lote entero pierde el sentido.
//  SE MIRA EL CODIGO, NO LOS COMENTARIOS. La primera version buscaba
//  «session_start» en la cabecera entera y salio roja por el parrafo que
//  explica justamente que session_start() vive en auth.php: la afirmacion se
//  estaba midiendo contra su propia documentacion.
$sinComentarios = function (string $t): string {
    $t = preg_replace('~/\*.*?\*/~s', '', $t) ?? $t;
    return implode("\n", array_filter(explode("\n", $t),
        fn($l) => !preg_match('~^\s*(//|\*|#)~', $l)));
};
foreach (['Locale', 'Catalogo'] as $c) {
    $src = (string)file_get_contents($DIR_I18N . '/' . $c . '.php');
    $cab = $sinComentarios(substr($src, 0, (int)(strpos($src, 'final class ' . $c) ?: 3000)));
    ok("{$c} no abre conexiones al cargarse", strpos($cab, 'new PDO') === false, trim($cab));
    ok("{$c} no arranca sesion al cargarse", strpos($cab, 'session_start') === false, trim($cab));
    ok("{$c} no requiere nada al cargarse",
       preg_match('/^\s*require(_once)?\s/m', $cab) === 0,
       'una dependencia que viaja en el MISMO despliegue puede no llegar');
}

// ══════════════════════════════════════════════════════════════
//  1b · NO CHOCA CON UNA CLASE GLOBAL DEL MISMO NOMBRE
// ══════════════════════════════════════════════════════════════
//  ESTA ES LA PRUEBA QUE FALTABA EL 22 DE AGOSTO, y la que explica por que el
//  archivo llegaba entero al servidor y aun asi la pagina moria al cargarlo.
//
//  La extension `intl` declara una clase GLOBAL llamada Locale. Hostinger la
//  tiene cargada; este XAMPP no —por eso la matriz de arranque salia 24/24
//  limpia en local mientras produccion se caia—. Sin namespace, incluir el
//  archivo daba:
//
//    Fatal error: Cannot declare class Locale, because the name is already
//    in use in core/I18n/Locale.php
//
//  Y ese fatal NO ES ATRAPABLE: es un E_ERROR de declaracion, no un Throwable.
//  Por eso el runner va en su propio proceso: si chocara, el proceso muere y
//  no llega a imprimir nada — que es exactamente la señal que se mide.
echo "\n  — 1b · con una clase global Locale delante (como intl) —\n";
ok('extension intl en esta maquina: ' . (extension_loaded('intl') ? 'SI' : 'NO'), true,
   '');   // informativo: el runner simula intl declarando la clase a mano
$sal = trim((string)shell_exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/_colision_runner.php')
    . ' ' . escapeshellarg($RAIZ) . ' 2>&1'));
$ult = trim(array_slice(array_filter(explode("\n", $sal), fn($l) => trim($l) !== ''), -1)[0] ?? '');
ok('el proceso sobrevive a la colision', strpos($ult, 'OK|') === 0,
   $sal . "\n         si muere sin imprimir, es «Cannot declare class» — el fatal "
 . 'que ningun try/catch atrapa');
ok('nuestras clases quedan declaradas', strpos($ult, 'declaradas=si') !== false, $ult);
ok('y las globales siguen intactas', strpos($ult, 'globales_intactas=si') !== false,
   $ult . ' · ocupar el nombre global romperia a intl en el resto de la app');
ok('y las nuestras funcionan', strpos($ult, 'funcionan=si') !== false, $ult);

//  Y que el namespace este declarado de verdad, no solo que no choque hoy.
foreach (['Locale', 'Catalogo'] as $c) {
    $src = (string)file_get_contents($DIR_I18N . '/' . $c . '.php');
    ok("{$c}.php declara namespace Crecer\\I18n",
       preg_match('/^\s*namespace\s+Crecer\\\\I18n\s*;/m', $src) === 1,
       'sin namespace propio, el siguiente choque es cuestion de tiempo');
}

// ══════════════════════════════════════════════════════════════
//  2 · LOS CATALOGOS SE LEEN Y CUADRAN
// ══════════════════════════════════════════════════════════════
//  «Cargan» no es «sirven». Un catalogo con un `return` roto se incluye sin
//  quejarse y devuelve 1 en vez de un array — y eso se descubriria en pantalla.
echo "\n  — 2 · los catalogos —\n";
\Crecer\I18n\Catalogo::usarRaiz($DIR_LANG);
$dom = \Crecer\I18n\Catalogo::DOMINIOS;
ok('hay dominios declarados', $dom !== [], json_encode($dom));

foreach (['es', 'en'] as $l) {
    foreach ($dom as $d) {
        $p = $DIR_LANG . '/' . $l . '/' . $d . '.php';
        ok("lang/{$l}/{$d}.php existe", is_file($p), $p);
        if (!is_file($p)) continue;
        $cargado = require $p;
        ok("lang/{$l}/{$d}.php devuelve un array", is_array($cargado),
           gettype($cargado) . ' · un return roto se incluye sin quejarse');
    }
}

$es = \Crecer\I18n\Catalogo::mapa('es');
$en = \Crecer\I18n\Catalogo::mapa('en');
ok('el mapa español tiene claves', $es !== [], count($es) . ' claves');
ok('el mapa ingles tiene claves',  $en !== [], count($en) . ' claves');

//  PARIDAD. Es la razon de que exista un catalogo español explicito: sin un
//  lado contra el que comparar, una clave que falta en ingles es
//  indistinguible de una que nunca se declaro.
$soloEs = array_diff(array_keys($es), array_keys($en));
$soloEn = array_diff(array_keys($en), array_keys($es));
ok('cada clave española tiene su inglesa', $soloEs === [],
   count($soloEs) . ': ' . implode(' · ', array_slice($soloEs, 0, 6)));
ok('y no sobra ninguna inglesa', $soloEn === [],
   count($soloEn) . ': ' . implode(' · ', array_slice($soloEn, 0, 6)));

$vacias = [];
foreach ($en as $k => $v) if (trim((string)$v) === '') $vacias[] = $k;
ok('ninguna traduccion esta vacia', $vacias === [],
   implode(' · ', array_slice($vacias, 0, 6))
 . ' · una cadena vacia deja un hueco en pantalla, que es peor que el español');

//  Los %s tienen que cuadrar en los dos lados, o vsprintf lanza en produccion.
$desiguales = [];
foreach ($es as $k => $_) {
    $a = substr_count((string)$k, '%s');
    $b = substr_count((string)($en[$k] ?? ''), '%s');
    if ($a !== $b) $desiguales[] = "{$k} (es:{$a} en:{$b})";
}
ok('los %s cuadran entre los dos idiomas', $desiguales === [],
   implode(' · ', array_slice($desiguales, 0, 5))
 . ' · un %s de mas revienta al rellenar la frase');

// ══════════════════════════════════════════════════════════════
//  3 · LOCALE SE COMPORTA, SIN BASE Y SIN SESION
// ══════════════════════════════════════════════════════════════
echo "\n  — 3 · Locale a solas —\n";
\Crecer\I18n\Locale::olvidar(); \Crecer\I18n\Locale::montar(null);
$_GET = []; $_COOKIE = [];
ok('sin nada, el idioma es español', \Crecer\I18n\Locale::interfaz() === 'es');
ok('y no se esta traduciendo', \Crecer\I18n\Locale::traduciendo() === false);

\Crecer\I18n\Locale::olvidar(); $_GET = ['lang' => 'en'];
ok('con ?lang=en, ingles', \Crecer\I18n\Locale::interfaz() === 'en');
\Crecer\I18n\Locale::olvidar(); $_GET = ['lang' => 'klingon'];
ok('un idioma inventado cae a español', \Crecer\I18n\Locale::interfaz() === 'es',
   'una url manipulada no puede dejar la interfaz en un idioma que no existe');
\Crecer\I18n\Locale::olvidar(); $_GET = []; $_COOKIE = ['crecer_lang' => 'en'];
ok('la cookie de quien no ha entrado sigue valiendo', \Crecer\I18n\Locale::interfaz() === 'en',
   'tirarla le cambiaria el idioma a quien ya lo habia puesto');

//  EL CONTRATO QUE DE VERDAD IMPORTA: el idioma del CONTENIDO no mira el
//  request. Si lo mirara, un admin entrando en ingles a revisar la cuenta de
//  una reposteria de Bayamon haria que su proximo caption naciera en ingles.
\Crecer\I18n\Locale::olvidar(); \Crecer\I18n\Locale::montar(null); $_GET = ['lang' => 'en']; $_COOKIE = ['crecer_lang' => 'en'];
ok('el idioma de CONTENIDO ignora ?lang y la cookie', \Crecer\I18n\Locale::contenido(7) === 'es',
   \Crecer\I18n\Locale::contenido(7) . ' · el contenido pertenece a la marca, no a quien mira');
ok('y sin marca tampoco inventa', \Crecer\I18n\Locale::contenido(null) === 'es');
$_GET = []; $_COOKIE = []; \Crecer\I18n\Locale::olvidar();

ok('normalizar acepta lo que existe', \Crecer\I18n\Locale::normalizar('EN') === 'en');
ok('y rechaza lo que no', \Crecer\I18n\Locale::normalizar('xx') === null);

$_SERVER['REQUEST_URI'] = '/crecer/panel/meta.php?marca=7&vista=plan';
$u = \Crecer\I18n\Locale::url('en');
ok('la url del interruptor conserva la marca', strpos($u, 'marca=7') !== false, $u);
ok('y el resto del query', strpos($u, 'vista=plan') !== false, $u);
ok('y pone el idioma', strpos($u, 'lang=en') !== false, $u);

// ══════════════════════════════════════════════════════════════
//  4 · NADIE LOS LLAMA TODAVIA
// ══════════════════════════════════════════════════════════════
//  ESTA ES LA AFIRMACION QUE DEFINE 3A. Mientras nadie los mencione,
//  desplegarlos no puede romper nada — y por eso esconderlos, abajo, tampoco.
//  En 3B se pondra roja a proposito: la conectara includes/i18n.php.
echo "\n  — 4 · quien los usa, y solo quien debe —\n";
//  EN 3A ESTA AFIRMACION DECIA «NADIE», y era la que definia aquel lote. Quedo
//  escrito que en 3B se pondria roja a proposito, y asi fue: cazo
//  includes/i18n.php, que es justo quien tiene que conectarlos.
//
//  Ahora dice otra cosa y sigue siendo la misma vigilancia: EL UNICO QUE LOS
//  TOCA ES i18n.php. Si mañana una pantalla llamara a Locale por su cuenta, se
//  saltaria la guardia de carga defensiva — y volveriamos a tener una pagina
//  que muere porque falta un archivo de idioma.
$permitido = ['_cache.php',   // el diagnostico los NOMBRA para verificarlos
              'i18n.php'];    // la unica puerta, y la que lleva la guardia
$usos = [];
foreach (array_merge(glob($RAIZ . '/panel/*.php') ?: [], glob($RAIZ . '/includes/*.php') ?: [],
                     glob($RAIZ . '/core/Meta/*.php') ?: [], glob($RAIZ . '/*.php') ?: [],
                     glob($RAIZ . '/scripts/*.php') ?: []) as $f) {
    if (in_array(basename($f), $permitido, true)) continue;
    $s = (string)file_get_contents($f);
    if (preg_match('/\b(Locale|Catalogo)::|core\/I18n/', $s)) {
        $usos[] = ltrim(str_replace($RAIZ, '', str_replace('\\', '/', $f)), '/');
    }
}
sort($usos);
ok('solo i18n.php los usa; ninguna pantalla por su cuenta', $usos === [],
   implode(' · ', $usos) . "\n         una pantalla que llame a Locale directamente se salta la guardia");
ok('y i18n.php si los usa: 3B esta conectado',
   strpos((string)file_get_contents($RAIZ . '/includes/i18n.php'), 'Crecer\\I18n\\Locale::') !== false,
   'si no, la maquinaria estaria desplegada y nadie la llamaria');
ok('el diagnostico si los nombra (por eso esta exceptuado)',
   strpos((string)file_get_contents($RAIZ . '/_cache.php'), 'core/I18n') !== false,
   'la excepcion de _cache.php sobra: quitala en vez de dejarla tapando');

//  Y la puerta de entrada de todas las paginas sigue limpia.
$db = (string)file_get_contents($RAIZ . '/includes/db.php');
ok('db.php NO requiere nada de core/I18n', strpos($db, 'core/I18n') === false,
   'es el require que mato produccion el 22 de agosto');
ok('y sigue arrancando i18n como siempre', strpos($db, 'i18n_arrancar()') !== false,
   'i18n_arrancar($pdo) es del lote 3B, no de este');

// ══════════════════════════════════════════════════════════════
//  5 · ESCONDERLOS NO ROMPE NADA
// ══════════════════════════════════════════════════════════════
//  La prueba dura de la inercia: se sacan del disco los dos directorios y se
//  piden las paginas de verdad, en procesos aparte. Es el estado exacto en que
//  quedo el servidor el 22 de agosto — con la diferencia de que ahora nadie
//  depende de ellos y por tanto no puede pasar nada.
echo "\n  — 5 · el estado de la caida, sin nadie que dependa —\n";
$OFF_I = $RAIZ . '/core/_I18n_prueba_oculta';
$OFF_L = $RAIZ . '/_lang_prueba_oculta';

$fx = Fixture::crear($pdo, 'i18n3a', true, 'admin');
$pedir = function (string $pag, array $get = [], int $uid = 0, int $marca = 0) use ($RAIZ): array {
    $cfg = __DIR__ . '/_fund3a_' . getmypid() . '.json';
    file_put_contents($cfg, json_encode(['raiz' => $RAIZ, 'pagina' => $pag, 'get' => $get,
        'cookie' => [], 'uid' => $uid, 'marca' => $marca, 'panel' => ($uid > 0)]));
    $sal = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(__DIR__ . '/_celda_arranque.php') . ' ' . escapeshellarg($cfg) . ' 2>&1'));
    @unlink($cfg);
    $ls = array_values(array_filter(explode("\n", $sal), fn($x) => trim($x) !== ''));
    $p = explode('|', trim($ls ? end($ls) : ''), 3);
    return ['bytes' => (int)($p[0] ?? 0), 'estado' => $p[1] ?? 'SIN-SALIDA', 'crudo' => $sal];
};
//  La ultima columna dice CONTRA QUE se compara cada fila, y no es un detalle:
//  con la maquinaria puesta, «panel/meta.php ?lang=en» de verdad se traduce.
//  Al esconderla cae a español y cambia de tamaño — que es el comportamiento
//  correcto, no un defecto. Asi que su referencia es la version ESPAÑOLA de esa
//  misma pagina, no la inglesa. Comparar cada fila consigo misma daba un rojo
//  que no señalaba nada.
$PAGS = [
    ['crecer.php', [],               0, 'crecer.php[]'],
    ['login.php',  [],               0, 'login.php[]'],
    ['crecer.php', ['lang' => 'en'], 0, 'crecer.php[]'],
    ['index.php',  [],               (int)$fx['usuario_id'], 'index.php[]'],
    ['meta.php',   ['lang' => 'en'], (int)$fx['usuario_id'], 'meta.php[]'],
];

try {
    //  La referencia se toma SIEMPRE en español: es lo que tiene que salir
    //  cuando el idioma no esta disponible.
    $conEllos = [];
    foreach ([['crecer.php', 0], ['login.php', 0], ['index.php', 1], ['meta.php', 1]] as [$p, $s]) {
        $r = $pedir($p, [], $s ? (int)$fx['usuario_id'] : 0, (int)$fx['marca_id']);
        $conEllos[$p . '[]'] = $r['bytes'];
    }
    ok('se pueden esconder los dos directorios',
       @rename($DIR_I18N, $OFF_I) && @rename($DIR_LANG, $OFF_L),
       'sin esto la prueba no prueba nada');
    ok('y de verdad no estan', !is_dir($DIR_I18N) && !is_dir($DIR_LANG));

    $rotas = 0;
    foreach ($PAGS as [$p, $g, $u, $refKey]) {
        $r = $pedir($p, $g, $u, (int)$fx['marca_id']);
        $bien = ($r['bytes'] > 400 && $r['estado'] === 'limpio');
        if (!$bien) $rotas++;
        ok(($u ? 'panel/' : '') . $p . ($g ? ' ?' . http_build_query($g) : '') . ' sigue en pie',
           $bien, $r['estado'] . ' · ' . mb_substr($r['crudo'], 0, 220));
        //  Y no solo «no revienta»: sale exactamente lo mismo. Cualquier
        //  diferencia significaria que la ausencia altera lo que ve el cliente.
        ok('  y sale la version española de siempre',
           $r['bytes'] === ($conEllos[$refKey] ?? -1),
           $r['bytes'] . ' vs ' . ($conEllos[$refKey] ?? -1)
         . ' · el respaldo es el español que Crecer servia antes del idioma');
    }
    ok('NINGUNA pagina cayo', $rotas === 0,
       $rotas . ' de ' . count($PAGS) . ' · esto es lo que tumbo produccion');
} finally {
    if (is_dir($OFF_I) && !is_dir($DIR_I18N)) @rename($OFF_I, $DIR_I18N);
    if (is_dir($OFF_L) && !is_dir($DIR_LANG)) @rename($OFF_L, $DIR_LANG);
    Fixture::limpiar($pdo, (int)$fx['marca_id']);
}

echo "\n  — 6 · todo volvio a su sitio —\n";
ok('core/I18n restaurado', is_dir($DIR_I18N), 'la prueba no puede dejar el repo roto');
ok('lang restaurado', is_dir($DIR_LANG));
ok('y no quedaron directorios de prueba', !is_dir($OFF_I) && !is_dir($OFF_L));

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  FUNDACION LISTA Y CONECTADA · {$n} afirmaciones\n\n"
    : "  {$fallos} de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
