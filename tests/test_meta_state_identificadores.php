<?php
// ============================================================
//  CRECER — IDENTIFICADORES Y DESTINOS
//  tests/test_meta_state_identificadores.php
//
//  El contrato pide que la acción abra EL OBJETO EXACTO, no una lista. Si el
//  destino apunta al sitio equivocado, el dueño vuelve a buscar a ojo — que es
//  el problema que estamos arreglando. Aquí se afirma que cada estado lleva el
//  id correcto y la URL correcta.
// ============================================================

require_once __DIR__ . '/../core/Meta/MetaStateComposer.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}
function s126(array $extra = []): array {
    return array_replace([
        'marca_id' => 126,
        'meta' => ['id' => 2, 'objetivo' => 'pedidos', 'cantidad' => 25.0, 'estado' => 'activa'],
        'progreso' => ['actual' => 3.0, 'vencida' => false],
        'plan' => ['id' => 4, 'version' => 4],
        'jugadas' => [], 'piezas' => [], 'jobs' => [], 'semana_actual' => 2,
    ], $extra);
}
function jug(array $x = []): array {
    return array_replace(['id' => 31, 'orden' => 1, 'semana' => 1, 'clase' => 'produccion',
                          'formato' => 'mixto', 'piezas_meta' => 1, 'estado' => 'pendiente',
                          'inversion' => null, 'titulo' => 'Historia del bizcocho'], $x);
}
function pz(array $x = []): array {
    return array_replace(['id' => 124, 'tactica_id' => 31, 'tipo' => 'reel', 'estado' => 'borrador',
                          'necesita_material' => null, 'guion' => null, 'fecha_programada' => null,
                          'publicado_at' => null, 'tiene_metricas' => false], $x);
}

echo "\nIDENTIFICADORES Y DESTINOS\n" . str_repeat('=', 52) . "\n\n";

// ── G · el reel va al estudio de reels, con SU pieza ────────
$e = MetaStateComposer::componer(s126([
    'jugadas' => [jug()],
    'piezas'  => [pz(['necesita_material' => 'video', 'guion' => 'Clip 1: el horno abriéndose'])],
]));
ok('G · destino es reels.php con la pieza exacta',
   $e->accion['destino'] === '/crecer/panel/reels.php?marca=126&pieza=124', $e->accion['destino']);
ok('G · evidencia trae contenido_id y tactica_id',
   (int)$e->evidencia['contenido_id'] === 124 && (int)$e->evidencia['tactica_id'] === 31);
ok('G · el guion viaja para poder enseñarlo sin otra consulta',
   strpos((string)$e->evidencia['guion'], 'Clip 1') === 0);
ok('G · tipo de acción es «material»', $e->accion['tipo'] === 'material');

// ── F · el carrusel abre su constructor; el post, su preview ─
$e = MetaStateComposer::componer(s126([
    'jugadas' => [jug()], 'piezas' => [pz(['id' => 490, 'tipo' => 'carrusel'])],
]));
ok('F · carrusel → carrusel.php?id=490',
   $e->accion['destino'] === '/crecer/panel/carrusel.php?marca=126&id=490', $e->accion['destino']);

$e = MetaStateComposer::componer(s126([
    'jugadas' => [jug()], 'piezas' => [pz(['id' => 501, 'tipo' => 'post'])],
]));
ok('F · post → aprobar2.php?ver=501 (la pieza, no la lista)',
   $e->accion['destino'] === '/crecer/panel/aprobar2.php?marca=126&ver=501', $e->accion['destino']);

// ── H · la inversión nombra el monto y lleva a su jugada ────
$e = MetaStateComposer::componer(s126([
    'jugadas' => [jug(['id' => 33, 'clase' => 'accion_dueno', 'inversion' => 10.0,
                       'semana' => 1, 'titulo' => 'Boost al post del combo'])],
]));
ok('H · la etiqueta no promete un gasto que no podemos ver',
   stripos($e->accion['etiqueta'], 'autorizar') === false, $e->accion['etiqueta']);
ok('H · destino lleva la jugada', strpos($e->accion['destino'], 'jugada=33') !== false, $e->accion['destino']);
ok('H · evidencia trae el monto', (float)$e->evidencia['inversion'] === 10.0);
ok('H · el título es la jugada concreta', $e->titulo === 'Boost al post del combo', $e->titulo);

// ── Toda URL es del panel y lleva la marca ─────────────────
echo "\n  — todas las URLs —\n";
$casos = [
    'A' => s126(['meta' => null]),
    'G' => s126(['jugadas' => [jug()], 'piezas' => [pz(['necesita_material' => 'video'])]]),
    'F' => s126(['jugadas' => [jug()], 'piezas' => [pz()]]),
    'H' => s126(['jugadas' => [jug(['clase' => 'accion_dueno', 'inversion' => 5.0, 'semana' => 1])]]),
    'I' => s126(['jugadas' => [jug(['clase' => 'accion_dueno', 'semana' => 1])]]),
    'D' => s126(['piezas' => [pz(['estado' => 'fallido'])]]),
];
foreach ($casos as $etq => $snap) {
    $e = MetaStateComposer::componer($snap);
    if ($e->accion === null) { ok("{$etq} · sin acción (correcto)", true); continue; }
    $u = $e->accion['destino'];
    ok("{$etq} · URL bajo /crecer/panel/ y con marca=126",
       strpos($u, '/crecer/panel/') === 0 && strpos($u, 'marca=126') !== false, $u);
}

// ── Estados que NO deben pedir nada ────────────────────────
echo "\n  — los que no piden nada no traen botón —\n";
$e = MetaStateComposer::componer(s126(['jobs' => [['id' => 7, 'tactica_id' => 31, 'estado' => 'working']],
                                       'jugadas' => [jug()]]));
ok('E · trabajando no lleva acción', $e->accion === null);
ok('E · pero sí dice en qué anda', $e->instruccion !== '');

$e = MetaStateComposer::componer(s126([
    'jugadas' => [jug(['estado' => 'hecha'])],
    'piezas'  => [pz(['estado' => 'programado', 'fecha_programada' => '2026-08-20 10:00:00'])],
]));
ok('J · todo programado no inventa acción', $e->accion === null);
ok('J · trae la fecha de lo próximo',
   ($e->evidencia['proxima_fecha'] ?? '') === '2026-08-20 10:00:00');

$e = MetaStateComposer::componer(s126(['jugadas' => [jug()]]));
ok('E · trabajo por hacer tampoco pide nada al dueño', $e->accion === null);
ok('E · pero deja la jugada en evidencia', (int)$e->evidencia['tactica_id'] === 31);

// ── pideAlgoAlDueno() distingue bien ───────────────────────
echo "\n  — ¿esto le pide algo al dueño? —\n";
$pide = [
    'G' => [s126(['jugadas' => [jug()], 'piezas' => [pz(['necesita_material' => 'video'])]]), true],
    'F' => [s126(['jugadas' => [jug()], 'piezas' => [pz()]]), true],
    'H' => [s126(['jugadas' => [jug(['clase' => 'accion_dueno', 'inversion' => 5.0, 'semana' => 1])]]), true],
    'E' => [s126(['jugadas' => [jug()]]), false],
    'J' => [s126(['jugadas' => [jug(['estado' => 'hecha'])],
                  'piezas' => [pz(['estado' => 'programado', 'fecha_programada' => '2026-08-20 10:00'])]]), false],
];
foreach ($pide as $etq => [$snap, $esperado]) {
    $e = MetaStateComposer::componer($snap);
    ok("{$etq} · pideAlgoAlDueno() === " . ($esperado ? 'true' : 'false'),
       $e->pideAlgoAlDueno() === $esperado);
}

echo "\n" . str_repeat('=', 52) . "\n";
echo $fallos === 0 ? "  TODO OK · {$n} pruebas\n\n" : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
