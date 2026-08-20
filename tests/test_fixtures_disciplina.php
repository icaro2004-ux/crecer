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
//  6 · EL SMOKE DEL MODELO VIVO NO ES REGRESION
// ══════════════════════════════════════════════════════════════
echo "\n  — lo que cuesta dinero se corre a proposito —\n";
$sp = $archivos['smoke_pipeline_tesis_funcional.php'] ?? '';
ok('smoke_pipeline se omite sin --vivo', strpos($sp, "in_array('--vivo', \$argv, true)") !== false,
   'no puede ser requisito de la suite algo que llama al modelo y cobra');
ok('y sin marca no inventa una', strpos($sp, 'Falta el id de la marca') !== false);

echo "\n" . str_repeat('=', 52) . "\n";
echo $fallos === 0 ? "  TODO OK · {$n} pruebas\n\n" : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
