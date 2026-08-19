<?php
// ============================================================
//  CRECER — PUREZA DEL COMPOSITOR
//  tests/test_meta_state_pureza.php  ·  php tests/test_meta_state_pureza.php
//
//  El compositor decide qué se le pide al dueño. Si además pudiera escribir,
//  encolar o gastar, un simple refresco de pantalla movería dinero. Esta
//  prueba defiende esa frontera POR ESTRUCTURA, no por buena intención:
//   1. la firma no admite PDO;
//   2. el archivo no incluye nada que abra conexión;
//   3. cargado a solas, sin base de datos, compone igual.
// ============================================================

require_once __DIR__ . '/../core/Meta/MetaStateComposer.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nPUREZA DEL COMPOSITOR\n" . str_repeat('=', 52) . "\n\n";

// ── 1 · La firma ────────────────────────────────────────────
$r = new ReflectionMethod('MetaStateComposer', 'componer');
$params = $r->getParameters();
ok('componer() recibe exactamente un parámetro', count($params) === 1);
ok('ese parámetro es array, no PDO',
   $params[0]->getType() && (string)$params[0]->getType() === 'array');

$tiene_pdo = false;
foreach ($r->getDeclaringClass()->getMethods() as $m) {
    foreach ($m->getParameters() as $p) {
        if ($p->getType() && stripos((string)$p->getType(), 'PDO') !== false) $tiene_pdo = true;
    }
}
ok('NINGÚN método de la clase acepta PDO', $tiene_pdo === false);

// ── 2 · El archivo ──────────────────────────────────────────
$src = file_get_contents(__DIR__ . '/../core/Meta/MetaStateComposer.php');
ok('no incluye db.php', strpos($src, "db.php") === false);
ok('no menciona PDO en ninguna parte', stripos($src, 'PDO') === false);

foreach (['INSERT', 'UPDATE', 'DELETE', '->prepare(', '->query(', '->exec('] as $prohibido) {
    ok("no contiene «{$prohibido}»", stripos($src, $prohibido) === false);
}
foreach (['file_put_contents', 'fopen', 'curl_', 'mail(', 'header('] as $prohibido) {
    ok("no contiene «{$prohibido}»", stripos($src, $prohibido) === false);
}

// Nada de relojes ni azar: el mismo snapshot debe dar el mismo estado siempre.
ok('no usa time() ni date() (el "hoy" viaja en el snapshot)',
   !preg_match('/\b(time|date|mktime|strtotime)\s*\(/', $src));
ok('no usa rand ni random', !preg_match('/\b(rand|mt_rand|random_int)\s*\(/', $src));

// ── 3 · Corre sin base de datos ─────────────────────────────
$snapshot = [
    'marca_id' => 126,
    'meta' => ['id' => 2, 'objetivo' => 'pedidos', 'cantidad' => 25.0, 'estado' => 'activa'],
    'progreso' => ['actual' => 3.0, 'vencida' => false],
    'plan' => ['id' => 4, 'version' => 4],
    'jugadas' => [['id' => 31, 'orden' => 1, 'semana' => 1, 'clase' => 'produccion',
                   'estado' => 'pendiente', 'inversion' => null, 'titulo' => 'X']],
    'piezas' => [], 'jobs' => [], 'semana_actual' => 1,
];
$a = MetaStateComposer::componer($snapshot);
ok('compone sin conexión a base de datos', $a instanceof MetaState);

// ── 4 · Determinismo e inmutabilidad de la entrada ──────────
$copia = $snapshot;
$b = MetaStateComposer::componer($snapshot);
ok('mismo snapshot → mismo estado y misma razón',
   $a->estado === $b->estado && $a->razon === $b->razon);
ok('el snapshot de entrada NO se modifica', $snapshot === $copia);

// Cien composiciones seguidas no cambian nada (ni caché ni estado global).
$estable = true;
for ($i = 0; $i < 100; $i++) {
    $x = MetaStateComposer::componer($snapshot);
    if ($x->razon !== $a->razon) { $estable = false; break; }
}
ok('100 composiciones seguidas dan el mismo resultado', $estable);

echo "\n" . str_repeat('=', 52) . "\n";
echo $fallos === 0 ? "  TODO OK · {$n} pruebas\n\n" : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
