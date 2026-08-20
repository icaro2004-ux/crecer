<?php
// ============================================================
//  CRECER — PRECEDENCIA DEL COMPOSITOR DE ESTADO
//  tests/test_meta_state_unit.php   ·   php tests/test_meta_state_unit.php
//
//  Snapshots armados a mano: ni base de datos ni red. Se afirma contra
//  `razon`, no contra el texto visible — el copy puede cambiar sin romper
//  la prueba; la decisión, no.
// ============================================================

require_once __DIR__ . '/../core/Meta/MetaStateComposer.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

/** Snapshot mínimo viable: meta activa, plan, nada más. */
function snap(array $extra = []): array {
    return array_replace_recursive([
        'marca_id' => 126,
        'hoy'      => '2026-08-19 12:00:00',
        'meta'     => ['id' => 2, 'objetivo' => 'pedidos', 'cantidad' => 25.0,
                       'fecha_inicio' => '2026-08-12', 'fecha_limite' => '2026-09-11',
                       'estado' => 'activa'],
        'progreso' => ['actual' => 3.0, 'pct' => 12, 'dias_rest' => 23,
                       'ritmo_dia' => 0.9, 'al_dia' => true, 'vencida' => false],
        'plan'     => ['id' => 4, 'version' => 4, 'inicio_at' => '2026-08-12 11:06:39'],
        'jugadas'  => [], 'piezas' => [], 'jobs' => [], 'plan_cerrado' => null,
        'semana_actual' => 2, 'plan_generandose' => false,
    ], $extra);
}
function jugada(array $x = []): array {
    return array_replace(['id' => 31, 'orden' => 1, 'semana' => 1, 'clase' => 'produccion',
                          'formato' => 'mixto', 'piezas_meta' => 1, 'estado' => 'pendiente',
                          'inversion' => null, 'titulo' => 'Historia del bizcocho'], $x);
}
function pieza(array $x = []): array {
    return array_replace(['id' => 124, 'tactica_id' => 31, 'tipo' => 'reel', 'estado' => 'borrador',
                          'necesita_material' => null, 'guion' => null, 'fecha_programada' => null,
                          'publicado_at' => null, 'tiene_metricas' => false], $x);
}

echo "\nCOMPOSITOR DE ESTADO · PRECEDENCIA\n" . str_repeat('=', 52) . "\n\n";

// ── Un estado por regla ─────────────────────────────────────
$e = MetaStateComposer::componer(snap(['meta' => null]));
ok('A · sin meta', $e->estado === MetaState::A_SIN_META && $e->razon === 'sin_meta_activa');

$s = snap(); $s['meta']['estado'] = 'lograda';
$e = MetaStateComposer::componer($s);
ok('M · meta lograda', $e->estado === MetaState::M_CERRADA && $e->razon === 'meta_lograda');

$s = snap(); $s['progreso']['vencida'] = true;
$e = MetaStateComposer::componer($s);
ok('M · meta vencida por fecha', $e->estado === MetaState::M_CERRADA && $e->razon === 'meta_vencida');

$e = MetaStateComposer::componer(snap(['jobs' => [['id' => 9, 'tactica_id' => 31, 'estado' => 'failed']]]));
ok('D · job fallido', $e->estado === MetaState::D_ERROR && $e->razon === 'job_fallido');

$e = MetaStateComposer::componer(snap(['piezas' => [pieza(['estado' => 'fallido'])]]));
ok('D · pieza fallida', $e->estado === MetaState::D_ERROR && $e->razon === 'pieza_fallida');

$s = snap(); $s['plan']['presentado_at'] = null;
$e = MetaStateComposer::componer($s);
ok('C · plan sin presentar (snapshot sintético)',
   $e->estado === MetaState::C_PLAN_POR_VER && $e->razon === 'plan_sin_presentar');

$e = MetaStateComposer::componer(snap(['plan_generandose' => true]));
ok('B · plan generándose (snapshot sintético)',
   $e->estado === MetaState::B_PREPARANDO_PLAN && $e->razon === 'plan_generandose');

$e = MetaStateComposer::componer(snap(['jobs' => [['id' => 7, 'tactica_id' => 31, 'estado' => 'working']]]));
ok('E · job trabajando', $e->estado === MetaState::E_CRECER_TRABAJA && $e->razon === 'job_working');

$e = MetaStateComposer::componer(snap([
    'jugadas' => [jugada()],
    'piezas'  => [pieza(['necesita_material' => 'video', 'guion' => 'Clip 1: el horno…'])],
]));
ok('G · pieza necesita material', $e->estado === MetaState::G_MATERIAL && $e->razon === 'pieza_necesita_material');

$e = MetaStateComposer::componer(snap([
    'jugadas' => [jugada()], 'piezas' => [pieza()],
]));
ok('F · pieza espera aprobación', $e->estado === MetaState::F_APROBACION && $e->razon === 'pieza_espera_aprobacion');

$e = MetaStateComposer::componer(snap([
    'jugadas' => [jugada(['id' => 33, 'clase' => 'accion_dueno', 'inversion' => 10.0,
                          'semana' => 2, 'titulo' => 'Boost al post'])],
]));
ok('H · jugada con inversión', $e->estado === MetaState::H_INVERSION && $e->razon === 'jugada_requiere_inversion');

$e = MetaStateComposer::componer(snap([
    'jugadas' => [jugada(['id' => 40, 'clase' => 'accion_dueno', 'inversion' => null,
                          'semana' => 1, 'titulo' => 'Habla con el vecino'])],
]));
ok('I · acción física', $e->estado === MetaState::I_ACCION_FISICA && $e->razon === 'jugada_accion_dueno');

$e = MetaStateComposer::componer(snap(['jugadas' => [jugada()]]));
ok('E · producción pendiente sin piezas ni job (el hueco que faltaba)',
   $e->estado === MetaState::E_CRECER_TRABAJA && $e->razon === 'produccion_pendiente_sin_piezas');

$e = MetaStateComposer::componer(snap([
    'jugadas' => [jugada(['estado' => 'hecha'])],
    'piezas'  => [pieza(['estado' => 'programado', 'fecha_programada' => '2026-08-20 10:00:00'])],
]));
ok('J · todo programado', $e->estado === MetaState::J_PROGRAMADO && $e->razon === 'todo_programado');

$e = MetaStateComposer::componer(snap([
    'jugadas' => [jugada(['estado' => 'hecha'])],
    'piezas'  => [pieza(['estado' => 'publicado', 'publicado_at' => '2026-08-15 10:00:00'])],
]));
ok('K · publicado sin métricas', $e->estado === MetaState::K_MIDIENDO && $e->razon === 'publicado_sin_metricas');

$e = MetaStateComposer::componer(snap([
    'jugadas' => [jugada(['estado' => 'hecha'])],
    'plan_cerrado' => ['id' => 3, 'leccion' => 'Los reels midieron mejor que los posts.',
                       'funciono' => 1, 'dias_desde_cierre' => 2],
]));
ok('L · lección reciente', $e->estado === MetaState::L_APRENDIZAJE && $e->razon === 'leccion_reciente');

// ── Empates: quién gana cuando dos podrían aplicar ──────────
echo "\n  — empates de precedencia —\n";

$e = MetaStateComposer::componer(snap([
    'jugadas' => [jugada()],
    'piezas'  => [pieza(['id' => 200, 'estado' => 'borrador']),
                  pieza(['id' => 124, 'necesita_material' => 'video'])],
]));
ok('G gana a F (el material desbloquea; la aprobación solo adelanta)',
   $e->razon === 'pieza_necesita_material' && (int)$e->evidencia['contenido_id'] === 124);

$s = snap(['piezas' => [pieza(['estado' => 'fallido'])]]);
$s['meta']['estado'] = 'cancelada';
$e = MetaStateComposer::componer($s);
ok('M gana a D (sin meta viva no hay nada que reintentar)', $e->estado === MetaState::M_CERRADA);

$e = MetaStateComposer::componer(snap([
    'jugadas' => [jugada(['id' => 33, 'clase' => 'accion_dueno', 'inversion' => 10.0, 'semana' => 1])],
    'piezas'  => [pieza(['necesita_material' => 'video'])],
]));
ok('G gana a H (primero destrabo, después te pido dinero)', $e->estado === MetaState::G_MATERIAL);

$e = MetaStateComposer::componer(snap([
    'jugadas' => [jugada(['id' => 33, 'clase' => 'accion_dueno', 'inversion' => 10.0, 'semana' => 1]),
                  jugada(['id' => 40, 'clase' => 'accion_dueno', 'inversion' => null, 'semana' => 1])],
]));
ok('H gana a I (el gasto se decide antes que el recado)', $e->estado === MetaState::H_INVERSION);

$e = MetaStateComposer::componer(snap([
    'jugadas' => [jugada(['id' => 33, 'clase' => 'accion_dueno', 'inversion' => 10.0, 'semana' => 5])],
]));
ok('H no aplica si la semana aún no llegó', $e->razon !== 'jugada_requiere_inversion');

// ── Nunca sin estado ────────────────────────────────────────
echo "\n  — el compositor siempre devuelve algo —\n";
$e = MetaStateComposer::componer(snap());
ok('snapshot vacío → cae en el fallback trazable',
   $e->estado === MetaState::FALLBACK && $e->razon === 'sin_regla_aplicable');
ok('el fallback trae evidencia para reproducirlo',
   isset($e->evidencia['jugadas'], $e->evidencia['piezas'], $e->evidencia['plan_id']));

$e = MetaStateComposer::componer([]);
ok('snapshot COMPLETAMENTE vacío no revienta y da estado A',
   $e instanceof MetaState && $e->estado === MetaState::A_SIN_META);

// ── El resumen que consume Home ─────────────────────────────
echo "\n  — contrato del resumen —\n";
$e = MetaStateComposer::componer(snap(['jugadas' => [jugada()], 'piezas' => [pieza()]]));
$r = $e->resumen();
ok('resumen() trae exactamente estado, titulo, accion y razon',
   array_keys($r) === ['estado', 'titulo', 'accion', 'razon']);
ok('resumen() NO expone evidencia ni camino',
   !isset($r['evidencia']) && !isset($r['camino']));

echo "\n" . str_repeat('=', 52) . "\n";
echo $fallos === 0 ? "  TODO OK · {$n} pruebas\n\n" : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
