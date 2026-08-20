<?php
// ============================================================
//  CRECER — LA VENTANA DE OBSERVACIÓN
//  tests/test_meta_state_ventana.php
//
//  Los tres defectos de alcance que encontró la revisión:
//   · una meta cerrada daba A ("no tienes meta") en vez de M;
//   · sin plan activo se mezclaban las jugadas de TODOS los planes viejos;
//   · K miraba cualquier pieza, no las del plan que de verdad se está midiendo.
//
//  Aquí se prueba el COMPOSITOR con snapshots. La parte del lector —que es
//  donde vivían dos de los tres— se prueba contra la base en
//  test_meta_reader_integracion.php.
// ============================================================

require_once __DIR__ . '/../core/Meta/MetaStateComposer.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}
function base(array $extra = []): array {
    return array_replace([
        'marca_id' => 126,
        'meta' => ['id' => 2, 'objetivo' => 'pedidos', 'cantidad' => 25.0, 'estado' => 'activa'],
        'progreso' => ['actual' => 3.0, 'vencida' => false],
        'plan' => ['id' => 4, 'version' => 4],
        'jugadas' => [], 'piezas' => [], 'jobs' => [],
        'observacion' => null, 'plan_cerrado' => null, 'semana_actual' => 2,
    ], $extra);
}
function pieza(array $x = []): array {
    return array_replace(['id' => 1, 'tactica_id' => 0, 'tipo' => 'post', 'estado' => 'publicado',
                          'necesita_material' => null, 'guion' => null, 'fecha_programada' => null,
                          'publicado_at' => '2026-08-14 10:00:00', 'tiene_metricas' => false], $x);
}

echo "\nLA VENTANA DE OBSERVACIÓN\n" . str_repeat('=', 52) . "\n";

// ── Meta cerrada de verdad ──────────────────────────────────
echo "\n  — una meta cerrada es M, nunca A —\n";
foreach (['lograda', 'cancelada', 'vencida'] as $cerrada) {
    $s = base(); $s['meta']['estado'] = $cerrada;
    $e = MetaStateComposer::componer($s);
    ok("meta {$cerrada} → M", $e->estado === MetaState::M_CERRADA, "obtenido: {$e->estado}");
}
$e = MetaStateComposer::componer(base(['meta' => null]));
ok('meta null (nunca hubo) → A', $e->estado === MetaState::A_SIN_META);
ok('y son estados distintos: A no es M', MetaState::A_SIN_META !== MetaState::M_CERRADA);

// ── K solo mira el plan en observación ──────────────────────
echo "\n  — K lee exclusivamente el plan en observación —\n";

$s = base([
    'plan' => null, 'jugadas' => [], 'piezas' => [],
    'observacion' => ['plan' => ['id' => 3, 'version' => 3, 'estado' => 'reemplazado'],
                      'piezas' => [pieza(['id' => 301]), pieza(['id' => 302, 'tiene_metricas' => true])]],
]);
$e = MetaStateComposer::componer($s);
ok('plan cerrado con piezas sin métricas → K', $e->estado === MetaState::K_MIDIENDO,
   "obtenido: {$e->estado}/{$e->razon}");
ok('K dice de qué plan habla', (int)$e->evidencia['plan_id'] === 3);
ok('K cuenta solo las piezas de ese plan',
   (int)$e->evidencia['publicadas'] === 2 && (int)$e->evidencia['sin_metricas'] === 1,
   json_encode($e->evidencia));
ok('K declara que viene del plan en observación', $e->evidencia['de_observacion'] === true);

$s = base([
    'plan' => null,
    'observacion' => ['plan' => ['id' => 3, 'version' => 3, 'estado' => 'reemplazado'],
                      'piezas' => [pieza(['id' => 301, 'tiene_metricas' => true])]],
    'plan_cerrado' => ['id' => 3, 'leccion' => 'Los reels midieron mejor.',
                       'funciono' => 1, 'dias_desde_cierre' => 3],
]);
$e = MetaStateComposer::componer($s);
ok('plan cerrado CON todas las métricas y con lección → L, no K',
   $e->estado === MetaState::L_APRENDIZAJE, "obtenido: {$e->estado}/{$e->razon}");

// El trabajo vivo del plan activo bloquea K aunque haya observación.
$s = base([
    'jugadas' => [['id' => 31, 'orden' => 1, 'semana' => 1, 'clase' => 'produccion',
                   'estado' => 'pendiente', 'inversion' => null, 'titulo' => 'Pendiente', 'que_hacer' => '']],
    'observacion' => ['plan' => ['id' => 3, 'version' => 3, 'estado' => 'reemplazado'],
                      'piezas' => [pieza(['id' => 301])]],
]);
$e = MetaStateComposer::componer($s);
ok('con trabajo vivo en el plan activo, K no puede decir "ya salió todo"',
   $e->estado !== MetaState::K_MIDIENDO, "obtenido: {$e->estado}/{$e->razon}");

// ── Lo histórico no compite ─────────────────────────────────
echo "\n  — lo de un plan reemplazado no domina el hoy —\n";

//  El lector ya no trae esas piezas; aquí se prueba el efecto: si las
//  colecciones del plan activo están vacías, ningún borrador viejo puede
//  aparecer por la puerta de atrás, porque no está en el snapshot.
$s = base([
    'plan' => null, 'jugadas' => [], 'piezas' => [], 'jobs' => [],
    'observacion' => ['plan' => ['id' => 3, 'version' => 3, 'estado' => 'reemplazado'],
                      'piezas' => [pieza(['id' => 301, 'estado' => 'borrador', 'publicado_at' => null]),
                                   pieza(['id' => 302])]],
]);
$e = MetaStateComposer::componer($s);
ok('un borrador del plan en observación NO dispara F',
   $e->estado !== MetaState::F_APROBACION, "obtenido: {$e->estado}/{$e->razon}");
ok('un borrador viejo tampoco dispara G',
   $e->estado !== MetaState::G_MATERIAL);

$s = base([
    'plan' => null, 'jugadas' => [], 'piezas' => [], 'jobs' => [],
    'observacion' => ['plan' => ['id' => 3, 'version' => 3, 'estado' => 'reemplazado'],
                      'piezas' => [pieza(['id' => 301, 'estado' => 'fallido', 'publicado_at' => null]),
                                   pieza(['id' => 302])]],
]);
$e = MetaStateComposer::componer($s);
ok('una pieza FALLIDA de un plan reemplazado no dispara D',
   $e->estado !== MetaState::D_ERROR, "obtenido: {$e->estado}/{$e->razon}");

$s = base([
    'plan' => null, 'jugadas' => [], 'piezas' => [], 'jobs' => [],
    'observacion' => ['plan' => ['id' => 3, 'version' => 3, 'estado' => 'reemplazado'],
                      'piezas' => [pieza(['id' => 301, 'estado' => 'programado',
                                          'fecha_programada' => '2026-09-01 10:00:00', 'publicado_at' => null]),
                                   pieza(['id' => 302])]],
]);
$e = MetaStateComposer::componer($s);
ok('una pieza PROGRAMADA de un plan viejo no dispara J',
   $e->estado !== MetaState::J_PROGRAMADO, "obtenido: {$e->estado}/{$e->razon}");

echo "\n" . str_repeat('=', 52) . "\n";
echo $fallos === 0 ? "  TODO OK · {$n} pruebas\n\n" : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
