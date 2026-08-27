<?php
// ============================================================
//  CRECER — LAS PRUEBAS NO TOCAN LO QUE NO ES SUYO
//  tests/test_fixtures_disciplina.php
//
//  Esto no prueba el producto: prueba a las pruebas. Existe porque una de
//  ellas ADOPTO una marca que ya estaba —le cambio el dueño para conseguir una
//  sesion— y al borrar ese usuario la FK en cascada se llevo la marca con su
//  meta, su plan y sus tacticas. Datos de desarrollo irrepetibles.
//
//  Un archivo de reglas que nadie comprueba es una intencion. Aqui se
//  comprueban, y por eso estan escritas contra el CODIGO de tests/ y no contra
//  un caso: una prueba futura que vuelva a adoptar una marca rompe esta.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nDISCIPLINA DE FIXTURES\n" . str_repeat('=', 52) . "\n";

$archivos = [];
foreach (glob(__DIR__ . '/*.php') as $p) {
    if (basename($p) === basename(__FILE__)) continue;
    $archivos[basename($p)] = (string)file_get_contents($p);
}

// ══════════════════════════════════════════════════════════════
//  1 · NADIE ADOPTA UNA MARCA AJENA
//      Reasignar usuario_id es exactamente lo que borro los datos.
// ══════════════════════════════════════════════════════════════
echo "\n  — nadie le cambia el dueño a una marca —\n";
foreach ($archivos as $f => $s) {
    $adopta = preg_match('/UPDATE\s+crecer_marca\s+SET\s+usuario_id/i', $s) === 1;
    ok("{$f} no reasigna usuario_id de una marca", !$adopta,
       'eso fue lo que, con la FK en cascada, borro la marca al limpiar el usuario');
}

// ══════════════════════════════════════════════════════════════
//  2 · NADIE BORRA MARCAS NI USUARIOS A LO ANCHO
//      Un DELETE por patron de email se lleva lo que no vio venir.
// ══════════════════════════════════════════════════════════════
echo "\n  — nadie borra por patron, solo lo suyo —\n";
foreach ($archivos as $f => $s) {
    $ancho = preg_match('/DELETE\s+FROM\s+usuarios\s+WHERE\s+email\s+LIKE/i', $s) === 1
          || preg_match('/DELETE\s+FROM\s+crecer_marca\s+WHERE\s+nombre_negocio\s+LIKE/i', $s) === 1;
    // _fixture.php si puede: su limpiarHuerfanas() exige el sello en cada fila.
    if ($f === '_fixture.php') continue;
    ok("{$f} no borra usuarios ni marcas por patron", !$ancho,
       'para barrer restos esta Fixture::limpiarHuerfanas(), que comprueba el sello fila a fila');
}

// ══════════════════════════════════════════════════════════════
//  3 · NADIE DEPENDE DE UN ID DE UNA MAQUINA
// ══════════════════════════════════════════════════════════════
echo "\n  — ningun id fijo de la base de nadie —\n";
foreach ($archivos as $f => $s) {
    $fijo = preg_match('/\?\?\s*126\b/', $s) === 1
         || preg_match('/marca_id\s*=\s*126\b/', $s) === 1
         || preg_match('/\$mid\s*=\s*126\b/', $s) === 1;
    ok("{$f} no trae el 126 quemado", !$fijo,
       'el dia que ese id dejo de existir, la suite entera se cayo');
}

// ══════════════════════════════════════════════════════════════
//  4 · EL CANDADO DE VERDAD, EJERCIDO
// ══════════════════════════════════════════════════════════════
echo "\n  — el candado frena de verdad —\n";
$ajena = 0;
try {
    $ajena = (int)$pdo->query("SELECT id FROM crecer_marca
                                WHERE nombre_negocio NOT LIKE '" . Fixture::SELLO . "%'
                             ORDER BY id LIMIT 1")->fetchColumn();
} catch (Throwable $e) {}

if ($ajena) {
    $freno = false;
    try { Fixture::limpiar($pdo, $ajena); }
    catch (RuntimeException $e) { $freno = true; }
    ok("limpiar() se niega a tocar la marca ajena #{$ajena}", $freno,
       'si esto falla, cualquier prueba puede repetir el borrado');
    $q = $pdo->prepare("SELECT COUNT(*) FROM crecer_marca WHERE id=?"); $q->execute([$ajena]);
    ok('y la marca ajena sigue ahi', (int)$q->fetchColumn() === 1);
} else {
    ok('hay alguna marca ajena contra la que ejercer el candado', false,
       'sin una marca sin sello no se puede comprobar que el candado frena');
}

// ══════════════════════════════════════════════════════════════
//  5 · LA FIXTURE ES COMPLETA Y SE VA ENTERA
// ══════════════════════════════════════════════════════════════
echo "\n  — siembra su mundo y no deja rastro —\n";
$fx = Fixture::crear($pdo, 'disciplina');
ok('trae marca, meta, plan, tacticas y piezas',
   $fx['marca_id'] && $fx['meta_id'] && $fx['plan_id']
   && count($fx['tacticas']) === 6 && count($fx['piezas']) === 2);
ok('la marca nace sellada', Fixture::esNuestra($pdo, $fx['marca_id']));

$cuenta = function (string $tabla, string $col, int $id) use ($pdo): int {
    $q = $pdo->prepare("SELECT COUNT(*) FROM {$tabla} WHERE {$col}=?");
    $q->execute([$id]); return (int)$q->fetchColumn();
};
ok('la meta quedo sembrada',     $cuenta('crecer_meta', 'marca_id', $fx['marca_id']) === 1);
ok('las 6 tacticas quedaron',    $cuenta('crecer_meta_tactica', 'marca_id', $fx['marca_id']) === 6);
ok('las 2 piezas quedaron',      $cuenta('crecer_contenido', 'marca_id', $fx['marca_id']) === 2);

Fixture::limpiar($pdo, $fx['marca_id']);
ok('tras limpiar no queda la marca', $cuenta('crecer_marca', 'id', $fx['marca_id']) === 0);
ok('ni su meta',      $cuenta('crecer_meta', 'marca_id', $fx['marca_id']) === 0);
ok('ni sus tacticas', $cuenta('crecer_meta_tactica', 'marca_id', $fx['marca_id']) === 0);
ok('ni sus piezas',   $cuenta('crecer_contenido', 'marca_id', $fx['marca_id']) === 0);

// ══════════════════════════════════════════════════════════════
//  6 · NINGUNA PRUEBA LE CAMBIA LA FORMA A LA BASE COMPARTIDA
//
//      El 20 de agosto una prueba quito presentado_at de la base local para
//      comprobar que el codigo nuevo aguantaba el esquema viejo, y la repuso
//      en un finally. Eso NO es reversible: el finally devuelve la COLUMNA,
//      nunca los VALORES, que se fueron con el DROP. Y hay un segundo dano
//      menos visible: el DDL hace COMMIT implicito en MySQL, asi que cualquier
//      prueba que estuviera dentro de una transaccion se ve confirmada a media
//      faena, justo lo contrario de lo que se busca al usar una.
//
//      La regla: si una prueba necesita otra forma de tabla, se la fabrica en
//      una BASE DESECHABLE (tests/_esquema_desechable.php). Ese archivo es el
//      unico sitio del arnes donde el DDL es legitimo, y solo porque se le
//      exige por escrito que trabaje sobre un nombre con su prefijo.
// ══════════════════════════════════════════════════════════════
echo "\n  — nadie le cambia la forma a la base de todos —\n";
$prohibido = [
    'DROP TABLE'    => '/\bDROP\s+TABLE\b/i',
    'DROP DATABASE' => '/\bDROP\s+DATABASE\b/i',
    'DROP COLUMN'   => '/\bDROP\s+COLUMN\b/i',
    'DROP INDEX'    => '/\bDROP\s+INDEX\b/i',
    'TRUNCATE'      => '/\bTRUNCATE\b/i',
    'ALTER ... DROP'=> '/\bALTER\s+TABLE\b[^;\'"]{0,200}?\bDROP\b/i',
    'RENAME TABLE'  => '/\bRENAME\s+TABLE\b/i',
];
//  La excepcion, nombrada una por una y no por comodin: quien la use tiene que
//  aparecer aqui, y esta lista se lee en la revision.
//
//  `test_arnes_barrido.php` esta aqui porque es LA PRUEBA DEL BARRIDO: para
//  comprobar que el arnés recoge lo suyo y respeta lo ajeno hay que sembrar
//  bases de verdad y verlas caer. No se le da barra libre — abajo se exige
//  que TODOS sus CREATE y DROP de base nombren el prefijo desechable.
$con_permiso = ['_esquema_desechable.php', 'test_arnes_barrido.php'];
//  Lo que NO se prohibe es cambiar la forma de una COPIA. Eso viaja siempre por
//  EsquemaDesechable::ejecutar(), asi que esas lineas se apartan antes de mirar
//  —y solo en archivos que de verdad cargan el ayudante, para que ningun
//  ->ejecutar() de otra clase sirva de coartada.
//  Y lo que se mira es SOLO lo que puede ejecutarse: la línea tiene que llevar
//  una llamada de ejecución (o venir justo debajo de una, porque estas cadenas
//  son multilínea a propósito). Sin ese filtro, la palabra DROP dentro de un
//  patrón regex —o de un array de verbos SQL— acusaba a una prueba que no
//  ejecuta nada. Una regla que se pone roja por algo inofensivo enseña a
//  ignorar el rojo, que es peor que no tenerla.
$solo_compartida = function (string $s): string {
    $usa_desechable = strpos($s, '_esquema_desechable.php') !== false;
    $lineas = explode("\n", $s);
    $vivas  = [];
    foreach ($lineas as $i => $l) {
        if (preg_match('/^\s*(\/\/|\*|\/\*|#)/', $l)) continue;      // comentario
        if ($usa_desechable && strpos($l, '->ejecutar(') !== false) continue;  // va a la copia
        //  ¿Esta línea, o alguna de las 3 de arriba, abre una ejecución?
        $ejecuta = false;
        for ($k = max(0, $i - 3); $k <= $i; $k++) {
            if (preg_match('/->(exec|query|prepare)\s*\(/', $lineas[$k])) { $ejecuta = true; break; }
        }
        if (!$ejecuta) continue;
        //  Si la ejecución de arriba iba a la copia, esta línea también.
        if ($usa_desechable) {
            $a_la_copia = false;
            for ($k = max(0, $i - 3); $k <= $i; $k++) {
                if (strpos($lineas[$k], '->ejecutar(') !== false) { $a_la_copia = true; break; }
            }
            if ($a_la_copia) continue;
        }
        $vivas[] = $l;
    }
    return implode("\n", $vivas);
};
foreach ($archivos as $f => $s) {
    $codigo = $solo_compartida($s);
    foreach ($prohibido as $etq => $re) {
        if (!preg_match($re, $codigo)) continue;
        ok("{$f} no ejecuta {$etq} contra la base compartida", in_array($f, $con_permiso, true),
           'para cambiar la forma de una tabla, clona en una base desechable '
           . '(tests/_esquema_desechable.php): el DROP no se deshace y hace COMMIT implicito');
    }
}
//  LA CONDICION DE LA EXCEPCION. Estar en la lista no es barra libre: quien
//  cree o borre bases tiene que nombrar el prefijo desechable en TODAS sus
//  sentencias. Una excepcion sin condicion es un agujero con nombre bonito.
$prefijo = 'crecer_prueba_';
foreach ($con_permiso as $f) {
    $src = $archivos[$f] ?? '';
    if ($src === '') continue;
    $sueltas = [];
    if (preg_match_all('/\b(?:CREATE|DROP)\s+DATABASE\b[^;\n]{0,120}/i', $src, $mm)) {
        foreach ($mm[0] as $linea) {
            //  Vale nombrar el prefijo literal o la constante que lo guarda.
            if (strpos($linea, $prefijo) !== false) continue;
            if (strpos($linea, 'PREFIJO') !== false) continue;
            //  O una variable que la propia prueba compuso con el prefijo.
            if (preg_match('/`?\{?\$\w+/', $linea)) continue;
            $sueltas[] = trim($linea);
        }
    }
    ok("{$f} solo crea y borra bases del prefijo desechable", $sueltas === [],
       implode(' · ', array_slice($sueltas, 0, 3)));
}

//  Y la comprobacion directa de la suite que causo el incidente: que su DDL
//  vaya SIEMPRE por la copia y nunca por $pdo, que es la conexion de todos.
$tp = $archivos['test_meta_presentacion.php'] ?? '';
ok('la suite de la presentacion carga la base desechable',
   strpos($tp, '_esquema_desechable.php') !== false);
//  Se cuenta sobre el CODIGO, no sobre el archivo: la cabecera explica el
//  incidente y nombra el DROP tres veces sin ejecutarlo ni una.
$tp_codigo = implode("\n", array_filter(explode("\n", $tp),
    fn($l) => !preg_match('/^\s*(\/\/|\*|\/\*|#)/', $l)));
ok('y su unico DROP viaja por la copia',
   substr_count($tp_codigo, 'DROP COLUMN') === 1
   && preg_match('/->ejecutar\("ALTER TABLE[^"]*DROP COLUMN/', $tp_codigo) === 1,
   'era la que quitaba la columna de la base local; si vuelve, vuelve el mismo dano');
ok('no le manda DDL a $pdo',
   preg_match('/\$pdo->(exec|query)\(\s*"(ALTER|DROP|TRUNCATE|RENAME)/i', $tp) === 0,
   '$pdo es la conexion a la base compartida');

$ed = $archivos['_esquema_desechable.php'] ?? '';
ok('la base desechable existe', $ed !== '');
ok('y solo suelta lo que lleva su prefijo',
   strpos($ed, "strpos(\$this->nombre, self::PREFIJO) !== 0") !== false,
   'sin esa guarda, un nombre equivocado tumbaria una base de verdad');
ok('el prefijo no se parece a ninguna base real',
   strpos($ed, "PREFIJO = 'crecer_prueba_'") !== false);
ok('si no puede crear bases, se salta en vez de improvisar',
   strpos($ed, 'return null;') !== false,
   'saltarse una prueba es honesto; tocar la base compartida para no saltarsela, no');

// ══════════════════════════════════════════════════════════════
//  7 · NINGUNA PRUEBA GASTA EN SU PROPIO PROCESO
//
//      El 21 de agosto una prueba llamó a img_gemini_fallback() directamente y
//      generó DOS IMÁGENES REALES en Gemini —$0.268— porque su proceso cargaba
//      db.php y con él config.local.php, o sea las credenciales de verdad. La
//      intención era comprobar un permiso; el efecto fue pagar.
//
//      La regla: lo que llama a un proveedor solo se ejercita en un proceso que
//      cargue _sin_gasto.php, que pone las llaves en blanco. En la práctica eso
//      significa un runner aparte. Aquí se vigila.
// ══════════════════════════════════════════════════════════════
echo "\n  — ninguna prueba llama a un proveedor en su propio proceso —\n";
$gastan = [
    'img_gemini_fallback(', 'generar_grafica(', 'generar_grafica_responses(',
    'crear_post_muestra(', 'relevo_del_corillo(', 'trabajo_autonomo(',
    'openai_imagen(', 'gemini_imagen(', 'openai_responses_imagen(',
    'openai_responses_crear_bg(', 'ia_imagen(', 'ia_ejecutar(',
];
$culpables = 0;
foreach ($archivos as $f => $s) {
    //  Los runners SÍ pueden: son procesos aparte que cargan _sin_gasto.php.
    if (strpos($s, '_sin_gasto.php') !== false) continue;
    //  Y los smokes que declaran su gasto detrás de --vivo también: no corren
    //  en la suite normal, corren cuando alguien lo pide a propósito.
    if (strpos($s, "in_array('--vivo'") !== false) continue;

    //  SE QUITAN LAS CADENAS ANTES DE BUSCAR. Las pruebas del diagnóstico
    //  llevan estos nombres ENTRE COMILLAS, precisamente para afirmar que la
    //  página NO los llama. Acusarlas por eso es el mismo falso positivo que
    //  dio TRUNCATE, y el que dio «caption». Ya van tres: la regla es mirar la
    //  llamada, nunca la palabra.
    $codigo = '';
    foreach (explode("\n", $s) as $l) {
        if (preg_match('/^\s*(\/\/|\*|\/\*|#)/', $l)) continue;      // comentario
        $codigo .= preg_replace('/([\'"]).*?\1/', "''", $l) . "\n";  // sin cadenas
    }
    foreach ($gastan as $fn) {
        if (strpos($codigo, $fn) === false) continue;
        $culpables++;
        ok("{$f} no llama a {$fn} en su proceso", false,
           'esa función sale al proveedor con las credenciales de verdad. '
           . 'Ejercítala en un runner que cargue _sin_gasto.php.');
    }
}
ok('ninguna prueba gasta en su propio proceso', $culpables === 0,
   $culpables . ' llamada(s) — cada una sale nombrada arriba');

$sg = $archivos['_sin_gasto.php'] ?? '';
ok('_sin_gasto.php pone las llaves en blanco',
   strpos($sg, "define('OPENAI_API_KEY', '')") !== false
   && strpos($sg, "define('GEMINI_API_KEY', '')") !== false,
   'define() gana el primero: por eso tiene que cargarse ANTES que config.local');

// ══════════════════════════════════════════════════════════════
//  8 · EL SMOKE DEL MODELO VIVO NO ES REGRESION
// ══════════════════════════════════════════════════════════════
echo "\n  — lo que cuesta dinero se corre a proposito —\n";
$sp = $archivos['smoke_pipeline_tesis_funcional.php'] ?? '';
ok('smoke_pipeline se omite sin --vivo', strpos($sp, "in_array('--vivo', \$argv, true)") !== false,
   'no puede ser requisito de la suite algo que llama al modelo y cobra');
ok('y sin marca no inventa una', strpos($sp, 'Falta el id de la marca') !== false);

echo "\n" . str_repeat('=', 52) . "\n";
echo $fallos === 0 ? "  TODO OK · {$n} pruebas\n\n" : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
