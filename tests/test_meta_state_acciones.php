<?php
// ============================================================
//  CRECER — LAS ACCIONES TIENEN EFECTO
//  tests/test_meta_state_acciones.php
//
//  La revisión encontró acciones que solo recargaban la misma pantalla: el
//  dueño tocaba y no pasaba nada. Aquí se exige que cada estado que ofrece una
//  acción lleve a otro sitio o produzca una mutación — nunca a sí mismo.
//
//  Y se blinda la honestidad del estado H: Crecer NO entra a tu gestor de
//  anuncios. No puede autorizar un gasto ni afirmar que el dinero salió.
// ============================================================

require_once __DIR__ . '/../core/Meta/MetaStateComposer.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}
function sx(array $extra = []): array {
    return array_replace([
        'marca_id' => 3,
        'meta' => ['id' => 9, 'objetivo' => 'conversaciones', 'cantidad' => 300.0, 'estado' => 'activa'],
        'progreso' => ['actual' => 0.0, 'vencida' => false],
        'plan' => ['id' => 90, 'version' => 1],
        'jugadas' => [], 'piezas' => [], 'jobs' => [],
        'observacion' => null, 'plan_cerrado' => null, 'semana_actual' => 1,
    ], $extra);
}
function jx(array $x = []): array {
    return array_replace(['id' => 900, 'orden' => 1, 'semana' => 1, 'clase' => 'produccion',
                          'formato' => 'post', 'piezas_meta' => 1, 'estado' => 'pendiente',
                          'inversion' => null, 'titulo' => 'Historia del café',
                          'que_hacer' => 'Cuenta cómo empezó el negocio.'], $x);
}
function px(array $x = []): array {
    return array_replace(['id' => 950, 'tactica_id' => 900, 'tipo' => 'post', 'estado' => 'borrador',
                          'necesita_material' => null, 'guion' => null, 'fecha_programada' => null,
                          'publicado_at' => null, 'tiene_metricas' => false], $x);
}
/** La URL de la pantalla en la que ya estás. Aterrizar aquí = no pasó nada. */
const AQUI = '/crecer/panel/meta.php?marca=3';

echo "\nLAS ACCIONES TIENEN EFECTO\n" . str_repeat('=', 52) . "\n";

// ── H · Crecer no promociona ni afirma que gastó ────────────
echo "\n  — H · no se afirma un gasto que no podemos ver —\n";
$eH = MetaStateComposer::componer(sx([
    'jugadas' => [jx(['id' => 933, 'clase' => 'accion_dueno', 'inversion' => 15.0,
                      'titulo' => 'Boost al post que mejor va',
                      'que_hacer' => 'Invierte $15 en el post con más alcance.'])],
]));
ok('el estado es H', $eH->estado === MetaState::H_INVERSION);
ok('la acción primaria ENSEÑA cómo, no autoriza',
   $eH->accion['etiqueta'] === 'Ver cómo promocionarlo', $eH->accion['etiqueta']);
foreach (['autoriz', 'gast', 'pagu', 'promocionad'] as $palabra) {
    ok("la etiqueta no dice «{$palabra}…»",
       stripos($eH->accion['etiqueta'], $palabra) === false, $eH->accion['etiqueta']);
    ok("la consecuencia no dice «{$palabra}…»",
       stripos($eH->accion['consecuencia'], $palabra) === false, $eH->accion['consecuencia']);
}
ok('el título es la tarea concreta', $eH->titulo === 'Boost al post que mejor va');
ok('la instrucción dice qué hacer con el dinero',
   $eH->instruccion === 'Invierte $15 en el post con más alcance.');
ok('el monto viaja como dato, no como promesa', (float)$eH->evidencia['inversion'] === 15.0);
ok('la jugada va en la evidencia para poder confirmarla después',
   (int)$eH->evidencia['tactica_id'] === 933);

// ── I · una confirmación que se puede leer ──────────────────
echo "\n  — I · el botón dice lo que hace, en español —\n";
$eI = MetaStateComposer::componer(sx([
    'jugadas' => [jx(['id' => 940, 'clase' => 'accion_dueno', 'inversion' => null,
                      'titulo' => 'Alianza con negocio local',
                      'que_hacer' => 'Habla con la cafetería de al lado.'])],
]));
ok('la etiqueta es «Confirmar que lo hice»',
   $eI->accion['etiqueta'] === 'Confirmar que lo hice', $eI->accion['etiqueta']);
ok('no intenta conjugar el título de la jugada',
   strpos($eI->accion['etiqueta'], 'Alianza') === false);
ok('la instrucción concreta sigue estando', $eI->instruccion === 'Habla con la cafetería de al lado.');

// ── D · el job fallido se REENCOLA ──────────────────────────
echo "\n  — D · reintentar reencola, no recarga —\n";
$eD = MetaStateComposer::componer(sx([
    'jugadas' => [jx(['id' => 900])],
    'jobs' => [['id' => 77, 'tactica_id' => 900, 'estado' => 'failed']],
]));
ok('el estado es D por job fallido', $eD->estado === MetaState::D_ERROR && $eD->razon === 'job_fallido');
ok('el tipo pide una MUTACIÓN, no un enlace', $eD->accion['tipo'] === 'reintento_job',
   $eD->accion['tipo']);
ok('lleva la jugada que hay que reencolar', (int)$eD->evidencia['tactica_id'] === 900);
ok('y el job para poder rastrearlo', (int)$eD->evidencia['job_id'] === 77);

// ── D · pieza fallida: ruta directa a ESA pieza ─────────────
$eDp = MetaStateComposer::componer(sx([
    'jugadas' => [jx()], 'piezas' => [px(['id' => 204, 'estado' => 'fallido'])],
]));
ok('pieza fallida → D', $eDp->estado === MetaState::D_ERROR && $eDp->razon === 'pieza_fallida');
ok('abre la pieza exacta, no una lista',
   $eDp->accion['destino'] === '/crecer/panel/aprobar2.php?marca=3&ver=204', $eDp->accion['destino']);
ok('y no se queda en esta misma pantalla', strpos($eDp->accion['destino'], 'meta.php') === false);

// ── Z · el fallback abre la segunda capa ────────────────────
echo "\n  — Z y L abren la segunda capa —\n";
$eZ = MetaStateComposer::componer(sx([
    'jugadas' => [jx(['clase' => 'regla'])],
]));
ok('es el fallback', $eZ->estado === MetaState::FALLBACK);
ok('su destino NO es esta misma pantalla', $eZ->accion['destino'] !== AQUI, $eZ->accion['destino']);
ok('abre el plan completo', strpos($eZ->accion['destino'], 'vista=plan') !== false,
   $eZ->accion['destino']);

// ── L · abre la segunda capa en el aprendizaje ──────────────
$eL = MetaStateComposer::componer(sx([
    'jugadas' => [jx(['estado' => 'hecha'])],
    'plan_cerrado' => ['id' => 59, 'leccion' => 'Los posts de la tarde midieron mejor.',
                       'funciono' => 1, 'dias_desde_cierre' => 3],
]));
ok('es L', $eL->estado === MetaState::L_APRENDIZAJE);
ok('su destino NO es esta misma pantalla', $eL->accion['destino'] !== AQUI, $eL->accion['destino']);
ok('abre el plan completo', strpos($eL->accion['destino'], 'vista=plan') !== false);
ok('y ancla en el aprendizaje', strpos($eL->accion['destino'], '#aprendizaje') !== false,
   $eL->accion['destino']);
ok('la lección se enseña en la instrucción',
   $eL->instruccion === 'Los posts de la tarde midieron mejor.');

// ── La regla general ────────────────────────────────────────
echo "\n  — ninguna acción es un viaje a ninguna parte —\n";
$casos = [
  'G' => sx(['jugadas' => [jx()], 'piezas' => [px(['necesita_material' => 'video'])]]),
  'F' => sx(['jugadas' => [jx()], 'piezas' => [px()]]),
  'D-pieza' => sx(['jugadas' => [jx()], 'piezas' => [px(['estado' => 'fallido'])]]),
  'Z' => sx(['jugadas' => [jx(['clase' => 'regla'])]]),
  'L' => sx(['jugadas' => [jx(['estado' => 'hecha'])],
             'plan_cerrado' => ['id' => 1, 'leccion' => 'x', 'funciono' => 1, 'dias_desde_cierre' => 1]]),
];
foreach ($casos as $etq => $snap) {
    $e = MetaStateComposer::componer($snap);
    if ($e->accion === null) { ok("{$etq} · sin acción, nada que exigir", true); continue; }
    $tipo_muta = in_array($e->accion['tipo'], ['reintento_job', 'fisica', 'inversion'], true);
    ok("{$etq} · o cambia de pantalla o muta algo",
       $tipo_muta || $e->accion['destino'] !== AQUI,
       "{$e->accion['tipo']} → {$e->accion['destino']}");
}
// H e I sí apuntan a esta pantalla, pero porque su acción es una MUTACIÓN
// que ocurre aquí mismo. Se comprueba que declaran ese tipo.
foreach (['H' => $eH, 'I' => $eI] as $etq => $e) {
    ok("{$etq} · apunta aquí porque muta aquí (tipo {$e->accion['tipo']})",
       in_array($e->accion['tipo'], ['inversion', 'fisica'], true));
}

echo "\n" . str_repeat('=', 52) . "\n";
echo $fallos === 0 ? "  TODO OK · {$n} pruebas\n\n" : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
