<?php
// ============================================================
//  CRECER — SIN IDIOMA, EL SITIO SIGUE EN PIE  (lote 3B)
//  tests/test_i18n_carga_defensiva.php
//
//  ESTA PRUEBA NACE DE DOS CAIDAS, y reproduce las dos.
//
//  1) El 22 de agosto includes/i18n.php estreno dos `require_once` a secas de
//     core/I18n/. Ese require lo ejecuta db.php, y db.php lo hace TODA pagina
//     del producto: si algo falla ahi, no cae una pantalla, cae el sitio.
//
//  2) Y al desplegar la fundacion sola aparecio la causa de verdad: la
//     extension `intl` declara una clase GLOBAL llamada Locale. Hostinger la
//     tiene, este XAMPP no. «Cannot declare class Locale» NO es un Throwable
//     —es un E_ERROR de declaracion— y ningun try/catch lo atrapa. Se resolvio
//     con namespace propio: contra eso no hay red, solo no provocarlo.
//
//  LA REGLA: el idioma es una comodidad, no un organo vital. Si su maquinaria
//  falla, Crecer sigue en español —lo que hacia antes de que el idioma
//  existiera— y lo registra. Nunca un fatal global.
//
//  LOS CUATRO MODOS DE FALLO SE PROVOCAN DE VERDAD, uno por uno:
//
//    archivo ausente    se saca el archivo del disco
//    catalogo ausente   se saca lang/ del disco
//    clase ausente      el archivo existe y carga, pero no declara la clase
//    archivo invalido   el archivo tiene un error de sintaxis
//
//  Comprobar que existe un `if` no prueba nada. Lo que prueba es que la pagina
//  sale ENTERA con el defecto puesto — y con los MISMOS BYTES que sin el,
//  porque en español no deberia notarse la diferencia.
//
//  CERO red y cero escrituras: se mueven archivos y se devuelven.
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

$RAIZ = str_replace('\\', '/', dirname(__DIR__));
$LOC  = $RAIZ . '/core/I18n/Locale.php';
$LANG = $RAIZ . '/lang';
$TMP  = $RAIZ . '/core/I18n/_Locale_prueba.bak';
$TLNG = $RAIZ . '/_lang_prueba.bak';

echo "\nSIN IDIOMA, EL SITIO SIGUE EN PIE · 3B\n" . str_repeat('=', 58) . "\n";

$fx = Fixture::crear($pdo, 'defensiva', true, 'admin');

/** Pide una pagina en su propio proceso. Un fatal no contamina al siguiente. */
$pedir = function (string $pag, array $get = [], array $ck = [], int $uid = 0, int $marca = 0) use ($RAIZ): array {
    $cfg = __DIR__ . '/_def3b_' . getmypid() . '.json';
    file_put_contents($cfg, json_encode(['raiz' => $RAIZ, 'pagina' => $pag, 'get' => $get,
        'cookie' => $ck, 'uid' => $uid, 'marca' => $marca, 'panel' => ($uid > 0)]));
    $sal = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(__DIR__ . '/_celda_arranque.php') . ' ' . escapeshellarg($cfg) . ' 2>&1'));
    @unlink($cfg);
    $ls = array_values(array_filter(explode("\n", $sal), fn($x) => trim($x) !== ''));
    $p = explode('|', trim($ls ? end($ls) : ''), 3);
    return ['bytes' => (int)($p[0] ?? 0), 'estado' => $p[1] ?? 'SIN-SALIDA', 'crudo' => $sal];
};

// ══════════════════════════════════════════════════════════════
//  1 · LA MATRIZ COMPLETA, CON TODO EN SU SITIO
// ══════════════════════════════════════════════════════════════
//  Publicas y panel × idioma ausente, es, en y cookie inglesa × sesion abierta
//  y cerrada. Es la referencia contra la que se comparan los fallos.
echo "\n  — 1 · la matriz, con la maquinaria puesta —\n";
$CASOS = [
    'lang ausente'   => [[],               []],
    'lang=es'        => [['lang' => 'es'], []],
    'lang=en'        => [['lang' => 'en'], []],
    'cookie inglesa' => [[],               ['crecer_lang' => 'en']],
];
$PUB   = ['crecer.php', 'login.php'];
$PANEL = ['index.php', 'meta.php'];

$ref = []; $malos = 0;
foreach ($CASOS as $nom => [$g, $ck]) {
    foreach ([[false, $PUB], [true, $PANEL]] as [$conSesion, $pags]) {
        foreach ($pags as $p) {
            $r = $pedir($p, $g, $ck, $conSesion ? (int)$fx['usuario_id'] : 0, (int)$fx['marca_id']);
            $clave = $p . '|' . $nom . '|' . ($conSesion ? 's' : 'n');
            $ref[$clave] = $r['bytes'];
            $bien = ($r['bytes'] > 400 && $r['estado'] === 'limpio');
            if (!$bien) $malos++;
            ok(sprintf('%-16s %-8s %s', $nom, $conSesion ? 'sesion' : 'anonimo',
                       ($conSesion ? 'panel/' : '') . $p),
               $bien, $r['estado'] . ' · ' . mb_substr($r['crudo'], 0, 200));
        }
    }
}
ok('la matriz completa esta limpia', $malos === 0, $malos . ' celdas rotas');

// ══════════════════════════════════════════════════════════════
//  2 · LOS CUATRO MODOS DE FALLO
// ══════════════════════════════════════════════════════════════
//  De cada uno se comprueba lo mismo: las paginas salen enteras Y con los
//  mismos bytes. «No revienta» no basta — si cambiara el tamaño, la ausencia
//  estaria alterando lo que ve el cliente.
//  CONTRA QUE SE COMPARA CADA FILA — y esto se aprendio con un rojo.
//
//  La primera version comparaba cada caso consigo mismo, y «panel/meta.php
//  ?lang=en» salio en rojo por 39 bytes. La prueba tenia razon y la afirmacion
//  estaba mal: con la maquinaria PUESTA esa pagina de verdad se traduce, asi
//  que su referencia son los bytes del INGLES. Cuando la maquinaria falla y
//  cae a español, el tamaño cambia — y ese cambio es exactamente el
//  comportamiento correcto, no un defecto.
//
//  Asi que el contrato de cada fila es distinto:
//    · pedida en español  -> tiene que salir IDENTICA a como salia
//    · pedida en ingles   -> tiene que salir igual que LA VERSION ESPAÑOLA de
//                            esa misma pagina, porque el respaldo es español.
$MUESTRA = [
    // pagina        caso              get                cookie                     sesion  referencia
    ['crecer.php', 'lang ausente',   [],                [],                        0, 'crecer.php|lang ausente|n'],
    ['crecer.php', 'lang=en',        ['lang' => 'en'],  [],                        0, 'crecer.php|lang ausente|n'],
    ['login.php',  'cookie inglesa', [],                ['crecer_lang' => 'en'],   0, 'login.php|lang ausente|n'],
    ['index.php',  'lang ausente',   [],                [],                        1, 'index.php|lang ausente|s'],
    ['meta.php',   'lang=en',        ['lang' => 'en'],  [],                        1, 'meta.php|lang ausente|s'],
];

$probar = function (string $titulo, callable $romper, callable $arreglar)
                   use (&$fallos, &$n, $MUESTRA, $pedir, $ref, $fx) {
    echo "\n  — {$titulo} —\n";
    $ok_romper = $romper();
    ok('el defecto quedo puesto', $ok_romper, 'sin esto la prueba no prueba nada');
    if ($ok_romper) {
        foreach ($MUESTRA as [$p, $caso, $g, $ck, $ses, $clave]) {
            $r = $pedir($p, $g, $ck, $ses ? (int)$fx['usuario_id'] : 0, (int)$fx['marca_id']);
            $bien = ($r['bytes'] > 400 && $r['estado'] === 'limpio');
            ok(sprintf('  %-24s sigue en pie', ($ses ? 'panel/' : '') . $p . ' ' . $caso),
               $bien, $r['estado'] . ' · ' . mb_substr($r['crudo'], 0, 240));
            ok('    y sale la version española de siempre',
               $r['bytes'] === ($ref[$clave] ?? -1),
               $r['bytes'] . ' vs ' . ($ref[$clave] ?? -1)
             . ' · el respaldo es el español que Crecer servia antes del idioma');
        }
    }
    $arreglar();
};

//  a · ARCHIVO AUSENTE
$probar('2a · falta core/I18n/Locale.php',
    fn() => @rename($LOC, $TMP),
    function () use ($LOC, $TMP) { if (is_file($TMP) && !is_file($LOC)) @rename($TMP, $LOC); });
ok('Locale.php volvio a su sitio', is_file($LOC));

//  b · CATALOGO AUSENTE
$probar('2b · falta el directorio lang/',
    fn() => @rename($LANG, $TLNG),
    function () use ($LANG, $TLNG) { if (is_dir($TLNG) && !is_dir($LANG)) @rename($TLNG, $LANG); });
ok('lang/ volvio a su sitio', is_dir($LANG));

//  c · CLASE AUSENTE — el archivo esta, carga, y no declara nada.
//      Es el caso que mas engaña: `is_file` dice que si y el require no se
//      queja. Solo lo caza preguntar por la clase DESPUES de incluir.
$probar('2c · el archivo existe pero no declara la clase',
    function () use ($LOC, $TMP) {
        if (!@copy($LOC, $TMP)) return false;
        return (bool)file_put_contents($LOC,
            "<?php\nnamespace Crecer\\I18n;\n// archivo valido que no declara nada\n");
    },
    function () use ($LOC, $TMP) { if (is_file($TMP)) { @copy($TMP, $LOC); @unlink($TMP); } });
ok('Locale.php volvio a declarar la clase',
   strpos((string)file_get_contents($LOC), 'final class Locale') !== false);

//  d · ARCHIVO INVALIDO — sintaxis rota. En PHP 7+ un `require` de esto lanza
//      ParseError, que SI es Throwable y por tanto se atrapa.
$probar('2d · el archivo tiene la sintaxis rota',
    function () use ($LOC, $TMP) {
        if (!@copy($LOC, $TMP)) return false;
        return (bool)file_put_contents($LOC,
            "<?php\nnamespace Crecer\\I18n;\nfinal class Locale { public function ( }\n");
    },
    function () use ($LOC, $TMP) { if (is_file($TMP)) { @copy($TMP, $LOC); @unlink($TMP); } });
ok('Locale.php volvio a ser valido',
   strpos((string)file_get_contents($LOC), 'final class Locale') !== false);

Fixture::limpiar($pdo, (int)$fx['marca_id']);

// ══════════════════════════════════════════════════════════════
//  3 · TODO EN SU SITIO, Y EL LOG NO FILTRA NADA
// ══════════════════════════════════════════════════════════════
echo "\n  — 3 · la guardia, leida en el fuente —\n";
ok('no quedaron copias de prueba', !is_file($TMP) && !is_dir($TLNG));

$src = (string)file_get_contents($RAIZ . '/includes/i18n.php');
$codigo = implode("\n", array_filter(explode("\n", $src),
    fn($l) => !preg_match('~^\s*(//|\*|/\*)~', $l)));

ok('no hay require a secas de core/I18n',
   preg_match('/^\s*require(_once)?\s+dirname\(__DIR__\)\s*\.\s*[\'"]\/core\/I18n/m', $codigo) === 0,
   'un require sin red aqui mata las 71 pantallas: es lo que paso');
ok('comprueba que el archivo existe', strpos($codigo, 'is_file($__p)') !== false);
ok('atrapa Throwable al incluir', strpos($codigo, 'catch (Throwable $__e)') !== false,
   'cubre el archivo invalido: un ParseError SI es Throwable');
ok('y comprueba la clase DESPUES', strpos($codigo, 'class_exists($__fqn, false)') !== false,
   'un archivo que carga sin declarar nada solo se caza asi');
ok('no se silencia con @', strpos($codigo, '@require') === false,
   'silenciar taparia tambien el archivo a medias');
ok('usa el nombre COMPLETO de las clases',
   substr_count($codigo, 'Crecer\\I18n\\Locale::') >= 5,
   'sin calificar, PHP las buscaria en el ambito global — donde vive la de intl');
ok('deja una bandera para el resto del archivo', strpos($codigo, "define('I18N_MODERNO'") !== false);

//  EL LOG NO PUEDE LLEVAR DATOS. Lo lee quien administre el servidor, y un
//  volcado con el mensaje entero o algo del request dentro es una fuga que
//  nadie pidio.
preg_match_all('/error_log\((.*?)\);/s', $codigo, $mm);
$sucios = [];
foreach ($mm[1] ?? [] as $arg) {
    if (preg_match('/\$_(GET|POST|SESSION|COOKIE|SERVER|REQUEST)|getMessage\(\)|getTraceAsString/', $arg)) {
        $sucios[] = trim(preg_replace('/\s+/', ' ', $arg));
    }
}
ok('el log no expone datos ni el mensaje crudo', $sucios === [],
   implode(' · ', $sucios) . ' · solo QUE pieza falla y POR QUE');
ok('pero si dice cual pieza fallo', strpos($codigo, '$__i18n_por') !== false,
   'un log que solo dice «fallo» obliga a adivinar');

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  EL IDIOMA PUEDE FALLAR SIN LLEVARSE EL SITIO · {$n} afirmaciones\n\n"
    : "  {$fallos} de {$n} · una ausencia todavia tumba la pagina\n\n";
exit($fallos === 0 ? 0 : 1);
