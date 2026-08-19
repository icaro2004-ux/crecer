<?php
// ============================================================
//  CRECER — EL COMPOSITOR NUNCA SE QUEDA SIN ESTADO
//  tests/test_meta_state_fallback.php
//
//  Una pantalla en blanco es la peor respuesta posible: el dueño no sabe si
//  falló el sistema o si no hay nada que hacer. Aquí se recorre a lo bruto un
//  producto cartesiano de mundos posibles y se afirma que SIEMPRE sale un
//  estado válido, con razón trazable.
//
//  Además se vigila el fallback: existe como red de seguridad, pero si empieza
//  a saltar en combinaciones normales es que falta una regla. Por eso se
//  imprime cuántas veces salió y con qué forma del mundo.
// ============================================================

require_once __DIR__ . '/../core/Meta/MetaStateComposer.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nNUNCA SIN ESTADO\n" . str_repeat('=', 52) . "\n\n";

// ── Barrido cartesiano ──────────────────────────────────────
$metas    = [null, ['id'=>2,'objetivo'=>'pedidos','cantidad'=>25.0,'estado'=>'activa'],
                   ['id'=>2,'objetivo'=>'pedidos','cantidad'=>25.0,'estado'=>'lograda']];
$planes   = [null, ['id'=>4,'version'=>4]];
$jugadas  = [
    [],
    [['id'=>31,'orden'=>1,'semana'=>1,'clase'=>'produccion','estado'=>'pendiente','inversion'=>null,'titulo'=>'A']],
    [['id'=>31,'orden'=>1,'semana'=>1,'clase'=>'produccion','estado'=>'hecha','inversion'=>null,'titulo'=>'A']],
    [['id'=>33,'orden'=>2,'semana'=>1,'clase'=>'accion_dueno','estado'=>'pendiente','inversion'=>10.0,'titulo'=>'B']],
    [['id'=>36,'orden'=>3,'semana'=>1,'clase'=>'regla','estado'=>'pendiente','inversion'=>null,'titulo'=>'C']],
];
$piezas = [
    [],
    [['id'=>1,'tactica_id'=>31,'tipo'=>'post','estado'=>'borrador','necesita_material'=>null,'guion'=>null,'fecha_programada'=>null,'publicado_at'=>null,'tiene_metricas'=>false]],
    [['id'=>1,'tactica_id'=>31,'tipo'=>'reel','estado'=>'borrador','necesita_material'=>'video','guion'=>'x','fecha_programada'=>null,'publicado_at'=>null,'tiene_metricas'=>false]],
    [['id'=>1,'tactica_id'=>31,'tipo'=>'post','estado'=>'programado','necesita_material'=>null,'guion'=>null,'fecha_programada'=>'2026-08-25 10:00:00','publicado_at'=>null,'tiene_metricas'=>false]],
    [['id'=>1,'tactica_id'=>31,'tipo'=>'post','estado'=>'publicado','necesita_material'=>null,'guion'=>null,'fecha_programada'=>null,'publicado_at'=>'2026-08-15 10:00:00','tiene_metricas'=>false]],
    [['id'=>1,'tactica_id'=>31,'tipo'=>'post','estado'=>'publicado','necesita_material'=>null,'guion'=>null,'fecha_programada'=>null,'publicado_at'=>'2026-08-15 10:00:00','tiene_metricas'=>true]],
    [['id'=>1,'tactica_id'=>31,'tipo'=>'post','estado'=>'fallido','necesita_material'=>null,'guion'=>null,'fecha_programada'=>null,'publicado_at'=>null,'tiene_metricas'=>false]],
];
$jobs = [ [], [['id'=>7,'tactica_id'=>31,'estado'=>'working']], [['id'=>7,'tactica_id'=>31,'estado'=>'failed']] ];
$cerrados = [ null, ['id'=>3,'leccion'=>'Los reels midieron mejor.','funciono'=>1,'dias_desde_cierre'=>2],
                    ['id'=>3,'leccion'=>'Vieja.','funciono'=>0,'dias_desde_cierre'=>90] ];

$validos = [MetaState::A_SIN_META, MetaState::B_PREPARANDO_PLAN, MetaState::C_PLAN_POR_VER,
            MetaState::D_ERROR, MetaState::E_CRECER_TRABAJA, MetaState::F_APROBACION,
            MetaState::G_MATERIAL, MetaState::H_INVERSION, MetaState::I_ACCION_FISICA,
            MetaState::J_PROGRAMADO, MetaState::K_MIDIENDO, MetaState::L_APRENDIZAJE,
            MetaState::M_CERRADA, MetaState::FALLBACK];

$total = 0; $en_fallback = 0; $sin_razon = 0; $invalidos = 0; $formas_fallback = [];
$vistos = [];
foreach ($metas as $me) foreach ($planes as $pl) foreach ($jugadas as $ju)
foreach ($piezas as $pi) foreach ($jobs as $jo) foreach ($cerrados as $ce) {
    $total++;
    $snap = ['marca_id'=>126, 'meta'=>$me,
             'progreso'=>['actual'=>3.0,'pct'=>12,'dias_rest'=>23,'ritmo_dia'=>0.9,'al_dia'=>true,'vencida'=>false],
             'plan'=>$pl, 'jugadas'=>$ju, 'piezas'=>$pi, 'jobs'=>$jo,
             'plan_cerrado'=>$ce, 'semana_actual'=>2];
    $e = MetaStateComposer::componer($snap);
    if (!($e instanceof MetaState))            { $invalidos++; continue; }
    if (!in_array($e->estado, $validos, true)) { $invalidos++; continue; }
    if (trim($e->razon) === '')                { $sin_razon++; }
    $vistos[$e->estado] = ($vistos[$e->estado] ?? 0) + 1;
    if ($e->estado === MetaState::FALLBACK) {
        $en_fallback++;
        $formas_fallback[] = sprintf('meta=%s plan=%s jugadas=%d piezas=%d jobs=%d',
            $me === null ? 'no' : $me['estado'], $pl ? 'sí' : 'no', count($ju), count($pi), count($jo));
    }
}

echo "  mundos probados: {$total}\n\n";
ok('ninguno devolvió algo que no sea un MetaState válido', $invalidos === 0, "inválidos: {$invalidos}");
ok('todos traen razón no vacía', $sin_razon === 0, "sin razón: {$sin_razon}");

echo "\n  estados alcanzados:\n";
ksort($vistos);
foreach ($vistos as $est => $veces) echo "     {$est} · {$veces} veces\n";

echo "\n  — el fallback —\n";
echo "  cayeron en fallback: {$en_fallback} de {$total}\n";
if ($formas_fallback) {
    foreach (array_slice(array_unique($formas_fallback), 0, 5) as $f) echo "     · {$f}\n";
}
ok('el fallback es residual (menos del 10% de los mundos)',
   $en_fallback < ($total * 0.10), "{$en_fallback}/{$total}");

// El fallback SÍ debe existir y ser alcanzable a propósito: es la red.
$e = MetaStateComposer::componer([
    'marca_id' => 126,
    'meta' => ['id'=>2,'objetivo'=>'pedidos','cantidad'=>25.0,'estado'=>'activa'],
    'progreso' => ['actual'=>3.0,'vencida'=>false],
    'plan' => ['id'=>4,'version'=>4],
    'jugadas' => [['id'=>36,'orden'=>1,'semana'=>1,'clase'=>'regla','estado'=>'pendiente','inversion'=>null,'titulo'=>'Regla']],
    'piezas' => [], 'jobs' => [], 'semana_actual' => 1,
]);
ok('un plan de solo reglas cae en el fallback (no hay nada que pedir ni que hacer)',
   $e->estado === MetaState::FALLBACK && $e->razon === 'sin_regla_aplicable');
ok('y aun así ofrece una salida', $e->accion !== null);

echo "\n" . str_repeat('=', 52) . "\n";
echo $fallos === 0 ? "  TODO OK · {$n} pruebas\n\n" : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
