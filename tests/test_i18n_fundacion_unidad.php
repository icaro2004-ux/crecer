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
//  ESTA PRUEBA CUBRE LA MITAD QUE CORRESPONDE A 3A:
//   · que las dos clases carguen por su cuenta y no arrastren nada al hacerlo;
//   · que los catalogos existan, se lean y cuadren entre si;
//   · que NADIE del producto los mencione todavia;
//   · y —lo que de verdad importa hoy— que ESCONDERLOS NO ROMPA NADA, porque
//     ninguna linea depende de ellos.
//
//  LO QUE NO CUBRE, Y VA EN 3B: la guardia de carga defensiva dentro de
//  i18n.php y sus tres casos (archivo ausente, clase ausente, archivo presente
//  pero invalido). Esa guardia no existe todavia — aqui no hay nada que
//  proteger porque no hay nada conectado.
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

$RAIZ    = str_replace('\\', '/', dirname(__DIR__));
$DIR_I18N = $RAIZ . '/core/I18n';
$DIR_LANG = $RAIZ . '/lang';

echo "\nLA FUNDACION DEL IDIOMA, A SOLAS · 3A\n" . str_repeat('=', 58) . "\n";

// ══════════════════════════════════════════════════════════════
//  1 · LOS ARCHIVOS ESTAN Y CARGAN DE VERDAD
// ══════════════════════════════════════════════════════════════
echo "\n  — 1 · cargan por su cuenta —\n";
foreach (['Locale', 'Catalogo'] as $c) {
    $p = $DIR_I18N . '/' . $c . '.php';
    ok("core/I18n/{$c}.php existe", is_file($p), $p);
    if (is_file($p)) require_once $p;
    ok("la clase {$c} se declara", class_exists($c, false),
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
//  2 · LOS CATALOGOS SE LEEN Y CUADRAN
// ══════════════════════════════════════════════════════════════
//  «Cargan» no es «sirven». Un catalogo con un `return` roto se incluye sin
//  quejarse y devuelve 1 en vez de un array — y eso se descubriria en pantalla.
echo "\n  — 2 · los catalogos —\n";
Catalogo::usarRaiz($DIR_LANG);
$dom = Catalogo::DOMINIOS;
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

$es = Catalogo::mapa('es');
$en = Catalogo::mapa('en');
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
Locale::olvidar(); Locale::montar(null);
$_GET = []; $_COOKIE = [];
ok('sin nada, el idioma es español', Locale::interfaz() === 'es');
ok('y no se esta traduciendo', Locale::traduciendo() === false);

Locale::olvidar(); $_GET = ['lang' => 'en'];
ok('con ?lang=en, ingles', Locale::interfaz() === 'en');
Locale::olvidar(); $_GET = ['lang' => 'klingon'];
ok('un idioma inventado cae a español', Locale::interfaz() === 'es',
   'una url manipulada no puede dejar la interfaz en un idioma que no existe');
Locale::olvidar(); $_GET = []; $_COOKIE = ['crecer_lang' => 'en'];
ok('la cookie de quien no ha entrado sigue valiendo', Locale::interfaz() === 'en',
   'tirarla le cambiaria el idioma a quien ya lo habia puesto');

//  EL CONTRATO QUE DE VERDAD IMPORTA: el idioma del CONTENIDO no mira el
//  request. Si lo mirara, un admin entrando en ingles a revisar la cuenta de
//  una reposteria de Bayamon haria que su proximo caption naciera en ingles.
Locale::olvidar(); Locale::montar(null); $_GET = ['lang' => 'en']; $_COOKIE = ['crecer_lang' => 'en'];
ok('el idioma de CONTENIDO ignora ?lang y la cookie', Locale::contenido(7) === 'es',
   Locale::contenido(7) . ' · el contenido pertenece a la marca, no a quien mira');
ok('y sin marca tampoco inventa', Locale::contenido(null) === 'es');
$_GET = []; $_COOKIE = []; Locale::olvidar();

ok('normalizar acepta lo que existe', Locale::normalizar('EN') === 'en');
ok('y rechaza lo que no', Locale::normalizar('xx') === null);

$_SERVER['REQUEST_URI'] = '/crecer/panel/meta.php?marca=7&vista=plan';
$u = Locale::url('en');
ok('la url del interruptor conserva la marca', strpos($u, 'marca=7') !== false, $u);
ok('y el resto del query', strpos($u, 'vista=plan') !== false, $u);
ok('y pone el idioma', strpos($u, 'lang=en') !== false, $u);

// ══════════════════════════════════════════════════════════════
//  4 · NADIE LOS LLAMA TODAVIA
// ══════════════════════════════════════════════════════════════
//  ESTA ES LA AFIRMACION QUE DEFINE 3A. Mientras nadie los mencione,
//  desplegarlos no puede romper nada — y por eso esconderlos, abajo, tampoco.
//  En 3B se pondra roja a proposito: la conectara includes/i18n.php.
echo "\n  — 4 · inertes: nadie los menciona —\n";
$permitido = ['_cache.php'];   // el diagnostico los NOMBRA para verificarlos
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
ok('ningun archivo del producto los usa', $usos === [],
   implode(' · ', $usos) . "\n         si algo los usa, 3A ya NO es inerte");
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
$PAGS = [['crecer.php', [], 0], ['login.php', [], 0],
         ['crecer.php', ['lang' => 'en'], 0],
         ['index.php', [], (int)$fx['usuario_id']],
         ['meta.php', ['lang' => 'en'], (int)$fx['usuario_id']]];

try {
    $conEllos = [];
    foreach ($PAGS as [$p, $g, $u]) {
        $r = $pedir($p, $g, $u, (int)$fx['marca_id']);
        $conEllos[$p . json_encode($g)] = $r['bytes'];
    }
    ok('se pueden esconder los dos directorios',
       @rename($DIR_I18N, $OFF_I) && @rename($DIR_LANG, $OFF_L),
       'sin esto la prueba no prueba nada');
    ok('y de verdad no estan', !is_dir($DIR_I18N) && !is_dir($DIR_LANG));

    $rotas = 0;
    foreach ($PAGS as [$p, $g, $u]) {
        $r = $pedir($p, $g, $u, (int)$fx['marca_id']);
        $bien = ($r['bytes'] > 400 && $r['estado'] === 'limpio');
        if (!$bien) $rotas++;
        ok(($u ? 'panel/' : '') . $p . ($g ? ' ?' . http_build_query($g) : '') . ' sigue en pie',
           $bien, $r['estado'] . ' · ' . mb_substr($r['crudo'], 0, 220));
        //  Y no solo «no revienta»: sale exactamente lo mismo. Cualquier
        //  diferencia significaria que la ausencia altera lo que ve el cliente.
        ok('  y con los mismos bytes', $r['bytes'] === ($conEllos[$p . json_encode($g)] ?? -1),
           $r['bytes'] . ' vs ' . ($conEllos[$p . json_encode($g)] ?? -1));
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
    ? "  FUNDACION LISTA E INERTE · {$n} afirmaciones\n\n"
    : "  {$fallos} de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
