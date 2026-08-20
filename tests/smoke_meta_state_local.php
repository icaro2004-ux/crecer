<?php
// ============================================================
//  CRECER — SMOKE · el lector contra la base LOCAL
//  tests/smoke_meta_state_local.php
//
//  SMOKE, no integración congelada: mira la cuenta viva y comprueba que el par
//  lector+compositor no se rompe con datos reales, SIN afirmar identificadores.
//  Los ids de la base local cambian cuando se resiembra; el caso del contrato
//  vive congelado en tests/test_meta_state_fixture.php, que no depende de nada.
//
//  Lo que sí se exige aquí, porque no depende de los datos:
//   · siempre sale un estado válido con razón;
//   · el lector no escribe;
//   · el job VIGENTE manda (un failed viejo no gana a un done posterior);
//   · cada marca apunta a sus propias URLs.
//
//  Si no hay base, se salta: la suite no puede depender de que XAMPP esté vivo.
// ============================================================

require_once __DIR__ . '/../core/Meta/MetaStateComposer.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nSMOKE · BASE LOCAL\n" . str_repeat('=', 52) . "\n\n";

try {
    require_once __DIR__ . '/../includes/db.php';
    if (!isset($pdo) || !($pdo instanceof PDO)) throw new RuntimeException('sin conexión');
    $pdo->query('SELECT 1');
} catch (Throwable $e) {
    echo "  SALTADO · no hay base local (" . $e->getMessage() . ")\n";
    echo "  Las pruebas del compositor no la necesitan.\n\n";
    exit(0);
}
require_once __DIR__ . '/../core/Meta/MetaSnapshotReader.php';
echo "  base: " . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";

// Todas las marcas del dueño de pruebas, sin nombrar ids en las afirmaciones.
$marcas = $pdo->query("SELECT id, nombre_negocio FROM crecer_marca WHERE usuario_id = 7 ORDER BY id")
              ->fetchAll(PDO::FETCH_ASSOC);
echo "  marcas del usuario de pruebas: " . count($marcas) . "\n\n";
ok('hay al menos una marca con la que probar', count($marcas) > 0);

foreach ($marcas as $m) {
    $mid = (int)$m['id'];
    $s = MetaSnapshotReader::leer($pdo, $mid);
    $e = MetaStateComposer::componer($s);
    echo "  ── marca {$mid} · {$m['nombre_negocio']}\n";
    echo "     meta: " . ($s['meta']['objetivo'] ?? '(ninguna)')
       . " · jugadas: " . count($s['jugadas']) . " · piezas: " . count($s['piezas'])
       . " · jobs vigentes: " . count($s['jobs']) . "\n";
    echo "     estado: {$e->estado} · razón: {$e->razon}\n";
    echo "     " . $e->titulo . "\n";
    if ($e->accion) echo "     → {$e->accion['etiqueta']} · {$e->accion['destino']}\n";

    ok("marca {$mid} · devuelve un estado válido", $e instanceof MetaState && $e->razon !== '');
    ok("marca {$mid} · el título no viene vacío", trim($e->titulo) !== '');
    if ($e->accion) {
        ok("marca {$mid} · su acción apunta a SU marca",
           strpos($e->accion['destino'], 'marca=' . $mid) !== false, $e->accion['destino']);
    }
    ok("marca {$mid} · el lector no inventa lo no observable",
       $s['plan_generandose'] === false && isset($s['no_observables']['presentado_at']));
    echo "\n";
}

// ── El job vigente manda ────────────────────────────────────
//  Se comprueba contra los datos que haya: para cada jugada con jobs, el que
//  llega al snapshot tiene que ser el ÚLTIMO, y solo si sigue vivo o falló.
echo "  — solo el job vigente de cada jugada —\n";
$coherente = true; $revisadas = 0;
foreach ($marcas as $m) {
    $mid = (int)$m['id'];
    $s = MetaSnapshotReader::leer($pdo, $mid);
    foreach ($s['jobs'] as $j) {
        $revisadas++;
        $q = $pdo->prepare("SELECT id, estado FROM crecer_meta_jobs
                             WHERE tactica_id = ? AND marca_id = ? ORDER BY id DESC LIMIT 1");
        $q->execute([(int)$j['tactica_id'], $mid]);
        $ultimo = $q->fetch(PDO::FETCH_ASSOC);
        if (!$ultimo || (int)$ultimo['id'] !== (int)$j['id']) { $coherente = false; }
        if ($ultimo && in_array((string)$ultimo['estado'], ['done'], true)) { $coherente = false; }
    }
}
ok("los {$revisadas} jobs del snapshot son el último de su jugada y ninguno está 'done'", $coherente);

// ── La regla del job vigente, ejercida contra el SQL de verdad ──────────────
//  La base local no tiene jobs, así que se siembran unos dentro de una
//  TRANSACCIÓN y se deshace todo al final: se prueba la consulta real del
//  lector sin dejar una fila. Se invoca el método privado por reflexión para
//  no abrir una puerta pública que solo existiría para las pruebas.
echo "\n  — la regla del job vigente, contra el SQL real —\n";
// Se usan marca y jugadas REALES: crecer_meta_jobs tiene llaves foráneas a
// ambas. Todo ocurre dentro de la transacción que se deshace al final.
$M = (int)$pdo->query("SELECT id FROM crecer_marca WHERE usuario_id=7 ORDER BY id DESC LIMIT 1")->fetchColumn();
$tt = $pdo->query("SELECT id FROM crecer_meta_tactica WHERE marca_id={$M} ORDER BY id DESC LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
$T_A = (int)($tt[0] ?? 0); $T_B = (int)($tt[1] ?? 0);
$sembrado = false;
try {
    $pdo->beginTransaction();
    $ins = $pdo->prepare("INSERT INTO crecer_meta_jobs (marca_id, tactica_id, estado) VALUES (?,?,?)");
    $ins->execute([$M, $T_A, 'failed']);      // falló…
    $ins->execute([$M, $T_A, 'done']);        // …y después salió bien
    $ins->execute([$M, $T_B, 'failed']);      // esta sí sigue rota
    $sembrado = true;

    $m = new ReflectionMethod('MetaSnapshotReader', 'jobs');
    $m->setAccessible(true);
    $jobs = $m->invoke(null, $pdo, $M, [$T_A, $T_B]);

    $porTactica = [];
    foreach ($jobs as $j) $porTactica[(int)$j['tactica_id']] = (string)$j['estado'];

    ok('un failed VIEJO con un done posterior NO llega al snapshot',
       !isset($porTactica[$T_A]), 'llegó: ' . ($porTactica[$T_A] ?? '—'));
    ok('un failed que sigue siendo el último SÍ llega',
       ($porTactica[$T_B] ?? '') === 'failed', 'llegó: ' . ($porTactica[$T_B] ?? '—'));

    // Y el efecto en la decisión. OJO: solo los jobs de T_A, cuyo fallo YA se
    // resolvió. Si se pasaran también los de T_B (que sigue roto de verdad),
    // D ganaría con razón y la prueba no probaría nada.
    $solo_A = array_values(array_filter($jobs, fn($j) => (int)$j["tactica_id"] === $T_A));
    $base = ['marca_id' => $M, 'meta' => ['id' => 1, 'objetivo' => 'pedidos', 'estado' => 'activa'],
             'progreso' => ['actual' => 0.0, 'vencida' => false], 'plan' => ['id' => 1, 'version' => 1],
             'jugadas' => [['id' => $T_A, 'orden' => 1, 'semana' => 1, 'clase' => 'produccion',
                            'estado' => 'pendiente', 'inversion' => null, 'titulo' => 'X', 'que_hacer' => '']],
             'piezas' => [], 'jobs' => $solo_A, 'semana_actual' => 1];
    $e = MetaStateComposer::componer($base);
    ok('con el fallo de esa jugada ya resuelto, el estado NO es D',
       $e->estado !== MetaState::D_ERROR, "obtenido: {$e->estado}/{$e->razon}");
} catch (Throwable $ex) {
    ok('la siembra temporal de jobs funcionó', false, $ex->getMessage());
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
$quedaron = 0;
try {
    $q = $pdo->prepare("SELECT COUNT(*) FROM crecer_meta_jobs WHERE tactica_id IN (?,?)");
    $q->execute([$T_A, $T_B]); $quedaron = (int)$q->fetchColumn();
} catch (Throwable $e) {}
ok('la prueba no dejó ni una fila sembrada', $quedaron === 0, "quedaron: {$quedaron}");

// Prueba directa de la regla, sin depender de que existan jobs en la base.
$sintetico = [
    'marca_id' => 1, 'meta' => ['id' => 1, 'objetivo' => 'pedidos', 'estado' => 'activa'],
    'progreso' => ['actual' => 0.0, 'vencida' => false], 'plan' => ['id' => 1, 'version' => 1],
    'jugadas' => [['id' => 1, 'orden' => 1, 'semana' => 1, 'clase' => 'produccion',
                   'estado' => 'pendiente', 'inversion' => null, 'titulo' => 'X', 'que_hacer' => '']],
    'piezas' => [], 'jobs' => [], 'semana_actual' => 1,
];
$e = MetaStateComposer::componer($sintetico);
ok('sin jobs vigentes, un fallo viejo NO puede dominar (el compositor ve E, no D)',
   $e->estado === MetaState::E_CRECER_TRABAJA, "obtenido: {$e->estado}/{$e->razon}");

// ── El lector solo lee ──────────────────────────────────────
echo "\n  — el lector solo lee —\n";
$antes = [];
foreach (['crecer_meta','crecer_meta_plan','crecer_meta_tactica','crecer_contenido','crecer_meta_jobs'] as $t) {
    try { $antes[$t] = (int)$pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn(); } catch (Throwable $e) { $antes[$t] = -1; }
}
foreach ($marcas as $m) { MetaSnapshotReader::leer($pdo, (int)$m['id']); }
$igual = true;
foreach ($antes as $t => $c) {
    try { $ahora = (int)$pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn(); } catch (Throwable $e) { $ahora = -1; }
    if ($ahora !== $c) { $igual = false; echo "     cambió {$t}: {$c} → {$ahora}\n"; }
}
ok('recorrer todas las marcas no cambió ni una fila', $igual);

echo "\n" . str_repeat('=', 52) . "\n";
echo $fallos === 0 ? "  TODO OK · {$n} pruebas\n\n" : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
