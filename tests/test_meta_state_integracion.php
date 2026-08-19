<?php
// ============================================================
//  CRECER — LECTOR + COMPOSITOR CONTRA LA BASE LOCAL
//  tests/test_meta_state_integracion.php
//
//  Congela el caso del contrato: Repostería Doña Fina (marca 126) tiene que
//  dar estado G, jugada 31, pieza 124. Si alguien cambia una precedencia y ese
//  caso deja de salir, esta prueba lo dice.
//
//  Corre contra la base LOCAL y SOLO LEE. Si no hay base disponible, se salta
//  con aviso en vez de fallar: no queremos una suite que dependa de que XAMPP
//  esté arriba para poder correr las unitarias.
// ============================================================

require_once __DIR__ . '/../core/Meta/MetaStateComposer.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nINTEGRACIÓN · BASE LOCAL\n" . str_repeat('=', 52) . "\n\n";

try {
    require_once __DIR__ . '/../includes/db.php';
    if (!isset($pdo) || !($pdo instanceof PDO)) throw new RuntimeException('sin conexión');
    $pdo->query('SELECT 1');
} catch (Throwable $e) {
    echo "  SALTADA · no hay base local disponible (" . $e->getMessage() . ")\n";
    echo "  Las pruebas unitarias no la necesitan.\n\n";
    exit(0);
}
require_once __DIR__ . '/../core/Meta/MetaSnapshotReader.php';

$base = $pdo->query('SELECT DATABASE()')->fetchColumn();
echo "  base: {$base}\n\n";

// ── Lo que el lector declara que NO puede observar ──────────
$s126 = MetaSnapshotReader::leer($pdo, 126);
echo "  — honestidad del lector —\n";
ok('declara plan_generandose como no observable',
   isset($s126['no_observables']['plan_generandose']));
ok('el lector real NUNCA enciende plan_generandose',
   $s126['plan_generandose'] === false);
ok('no inventa presentado_at (la columna no existe)',
   !array_key_exists('presentado_at', (array)($s126['plan'] ?? [])));
ok('declara por qué no hay "lección leída"',
   isset($s126['no_observables']['leccion_leida']));

// ── EL CASO DEL CONTRATO ────────────────────────────────────
echo "\n  — Doña Fina · marca 126 —\n";
$e126 = MetaStateComposer::componer($s126);
echo "     meta: " . ($s126['meta']['objetivo'] ?? '(ninguna)') . " = "
   . ($s126['meta']['cantidad'] ?? '-') . " · plan v" . ($s126['plan']['version'] ?? '-')
   . " · jugadas: " . count($s126['jugadas']) . " · piezas: " . count($s126['piezas']) . "\n";
echo "     estado: {$e126->estado} · razón: {$e126->razon}\n";
echo "     título: {$e126->titulo}\n";
if ($e126->accion) echo "     acción: {$e126->accion['etiqueta']} → {$e126->accion['destino']}\n";

ok('la meta es de pedidos', ($s126['meta']['objetivo'] ?? '') === 'pedidos');
ok('el plan vigente es el v4', (int)($s126['plan']['version'] ?? 0) === 4);
ok('el plan vigente trae 6 jugadas', count($s126['jugadas']) === 6);
ok('ESTADO DOMINANTE = G (necesita material)', $e126->estado === MetaState::G_MATERIAL,
   "obtenido: {$e126->estado} · {$e126->razon}");
ok('la jugada señalada es la 31', (int)($e126->evidencia['tactica_id'] ?? 0) === 31,
   'obtenido: ' . ($e126->evidencia['tactica_id'] ?? 'null'));
ok('la pieza señalada es la 124', (int)($e126->evidencia['contenido_id'] ?? 0) === 124,
   'obtenido: ' . ($e126->evidencia['contenido_id'] ?? 'null'));
ok('el destino es el estudio de reels con esa pieza',
   ($e126->accion['destino'] ?? '') === '/crecer/panel/reels.php?marca=126&pieza=124',
   $e126->accion['destino'] ?? '(sin acción)');
ok('cobertura parcial (pedidos no cubre WhatsApp)', $e126->cobertura === 'parcial');
ok('y por tanto NO se puede afirmar progreso', $e126->puedeAfirmarProgreso() === false);
ok('el camino cuenta las jugadas del plan vigente',
   $e126->camino['hecho'] + 1 + $e126->camino['despues'] <= count($s126['jugadas']));

// ── La otra marca del mismo usuario ─────────────────────────
echo "\n  — El Palo Dulce · marca 3 —\n";
$s3 = MetaSnapshotReader::leer($pdo, 3);
$e3 = MetaStateComposer::componer($s3);
echo "     meta: " . ($s3['meta']['objetivo'] ?? '(ninguna)')
   . " · jugadas: " . count($s3['jugadas']) . " · piezas: " . count($s3['piezas']) . "\n";
echo "     estado: {$e3->estado} · razón: {$e3->razon}\n";
echo "     título: {$e3->titulo}\n";
ok('devuelve un estado válido', $e3 instanceof MetaState && $e3->razon !== '');
ok('las dos marcas NO comparten estado por accidente',
   $s3['marca_id'] === 3 && $s126['marca_id'] === 126);
if ($e3->accion) {
    ok('su acción apunta a SU marca', strpos($e3->accion['destino'], 'marca=3') !== false,
       $e3->accion['destino']);
}

// ── El lector no escribe ────────────────────────────────────
echo "\n  — el lector solo lee —\n";
$antes = [];
foreach (['crecer_meta','crecer_meta_plan','crecer_meta_tactica','crecer_contenido','crecer_meta_jobs'] as $t) {
    try { $antes[$t] = (int)$pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn(); }
    catch (Throwable $e) { $antes[$t] = -1; }
}
MetaSnapshotReader::leer($pdo, 126);
MetaSnapshotReader::leer($pdo, 3);
$igual = true;
foreach ($antes as $t => $c) {
    try { $ahora = (int)$pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn(); }
    catch (Throwable $e) { $ahora = -1; }
    if ($ahora !== $c) { $igual = false; echo "     cambió {$t}: {$c} → {$ahora}\n"; }
}
ok('leer dos veces no cambió ni una fila', $igual);

echo "\n" . str_repeat('=', 52) . "\n";
echo $fallos === 0 ? "  TODO OK · {$n} pruebas\n\n" : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
