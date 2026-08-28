<?php
// ============================================================
//  CRECER — CERRAR UNA SEMANA Y ABRIR LA SIGUIENTE
//  tests/test_ciclo_semanal.php
//
//  EL HUECO QUE CIERRA. El plan del mes trae 4-6 jugadas para TODO el mes: la
//  semana 1 se llena y de la 2 en adelante la revision se queda vacia. Repartir
//  esas 4-6 en doce trozos seria fingir un mes de trabajo.
//
//  LO QUE SE PRUEBA:
//    · cerrar la semana 1 crea SOLO la semana 2, en el MISMO plan;
//    · terminar una semana NO cierra el plan ni la meta — son tres hechos;
//    · dos clics, dos crones o una recarga no preparan dos veces (y cada
//      preparacion cuesta una llamada al modelo);
//    · la ultima semana SI cierra el plan, y aun asi deja la meta activa.
//
//  CERO PROVEEDOR. La Estratega va por `ia_ejecutar`, que en modo prueba
//  devuelve su `mock_texto`. Se cuenta al final para poder afirmarlo.
// ============================================================

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/meta_ciclo.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nCERRAR UNA SEMANA Y ABRIR LA SIGUIENTE\n" . str_repeat('=', 58) . "\n";

$cnt = fn(string $t, string $w = '1') => (int)$GLOBALS['pdo']->query(
    "SELECT COUNT(*) FROM {$t} WHERE {$w}")->fetchColumn();
$g0 = ['ia' => $cnt('crecer_ia_log'), 'real' => $cnt('crecer_ia_log', "modelo <> 'mock'")];

echo "\n  — la red, cerrada por construcción —\n";
ok('el modo prueba está puesto', defined('CRECER_TEST_MODE') && CRECER_TEST_MODE);
ok('y sin transporte falso declarado',
   !defined('CRECER_TEST_RED_FALSA') || !CRECER_TEST_RED_FALSA,
   'la Estratega va por ia_ejecutar, que en prueba devuelve su mock');

if (!ciclo_hay_libro($pdo, true)) {
    echo "\n  SALTADA · falta migrations/2026-08-27_crecer_meta_semana.sql\n\n"; exit(0);
}

$limpiar = [];
try {
    $fx = Fixture::crear($pdo, 'ciclo', true, 'admin');
    $limpiar[] = $M = (int)$fx['marca_id'];
    $META = (int)$fx['meta_id']; $PLAN = (int)$fx['plan_id'];
    ok('la fixture trae meta y plan', $META > 0 && $PLAN > 0);

    $meta = meta_por_id($pdo, $META, $M);
    $plan = meta_plan_por_id($pdo, $PLAN, $M);

    //  LA VENTANA MANDA. Se le da a la meta cuatro semanas exactas para poder
    //  afirmar cuándo termina el plan sin depender del día en que se corra.
    $pdo->prepare("UPDATE crecer_meta SET fecha_inicio=CURDATE(),
                          fecha_limite=DATE_ADD(CURDATE(), INTERVAL 28 DAY)
                    WHERE id=?")->execute([$META]);
    $meta = meta_por_id($pdo, $META, $M);
    $SEMANAS = ciclo_semanas_del_plan($meta);
    ok('el plan dura cuatro semanas', $SEMANAS === 4, (string)$SEMANAS);

    $tac = fn(int $sem) => (int)$GLOBALS['pdo']->query(
        "SELECT COUNT(*) FROM crecer_meta_tactica WHERE plan_id={$GLOBALS['PLAN_ID']} AND semana={$sem}")
        ->fetchColumn();
    $GLOBALS['PLAN_ID'] = $PLAN;

    //  Todo lo de la semana 1, resuelto: es la condición para cerrarla.
    $pdo->prepare("UPDATE crecer_meta_tactica SET estado='hecha', semana=1
                    WHERE plan_id=? AND marca_id=?")->execute([$PLAN, $M]);

    // ══════════════════════════════════════════════════════════════
    //  1 · CERRAR LA SEMANA 1
    // ══════════════════════════════════════════════════════════════
    echo "\n  — se cierra la semana 1 —\n";
    $antes_tac = $cnt('crecer_meta_tactica', "plan_id={$PLAN}");
    $c = ciclo_cerrar($pdo, $M, $META, $PLAN, 1, 'mejor', 'El jueves se dañó el horno.');
    ok('la semana se cierra',      !empty($c['ok']), json_encode($c));
    ok('y no estaba cerrada',      empty($c['ya_estaba']));
    ok('queda lo que dijo el dueño',
       (string)($c['fila']['valoracion'] ?? '') === 'mejor'
       && str_contains((string)($c['fila']['comentario'] ?? ''), 'horno'),
       json_encode($c['fila'] ?? null));
    ok('con su fecha',             ($c['fila']['cerrada_at'] ?? null) !== null);
    ok('cerrar no crea jugadas',   $cnt('crecer_meta_tactica', "plan_id={$PLAN}") === $antes_tac,
       'cerrar es cerrar: preparar es otra cosa y cuesta una llamada');

    //  IDEMPOTENTE: el mismo envío otra vez no abre otro cierre.
    $c2 = ciclo_cerrar($pdo, $M, $META, $PLAN, 1, 'peor', 'otra cosa');
    ok('cerrar dos veces no duplica', !empty($c2['ok']) && !empty($c2['ya_estaba']));
    ok('y no pisa lo que ya dijo',
       (string)$pdo->query("SELECT valoracion FROM crecer_meta_semana
                             WHERE plan_id={$PLAN} AND semana=1")->fetchColumn() === 'mejor');
    ok('una sola fila en el libro',
       $cnt('crecer_meta_semana', "plan_id={$PLAN}") === 1);

    // ══════════════════════════════════════════════════════════════
    //  2 · PREPARAR LA SEMANA 2 · el mismo plan, no otro
    // ══════════════════════════════════════════════════════════════
    echo "\n  — y se prepara la semana 2 —\n";
    $ia_antes = $cnt('crecer_ia_log');
    $p = ciclo_preparar($pdo, $M, $META, $PLAN, 1);
    ok('se prepara',            !empty($p['ok']), json_encode($p));
    ok('y es la semana 2',      (int)($p['semana'] ?? 0) === 2);
    ok('con jugadas nuevas',    (int)($p['creadas'] ?? 0) > 0, json_encode($p));

    $s2 = (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica
                             WHERE plan_id={$PLAN} AND semana=2")->fetchColumn();
    ok('las jugadas quedan en la semana 2', $s2 > 0, (string)$s2);
    ok('y en el MISMO plan',
       (int)$pdo->query("SELECT COUNT(DISTINCT plan_id) FROM crecer_meta_tactica
                          WHERE marca_id={$M}")->fetchColumn() === 1,
       'preparar una semana no abre un plan nuevo: el plan es la dirección');
    ok('sin generar la 3 ni la 4',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica
                          WHERE plan_id={$PLAN} AND semana>2")->fetchColumn() === 0,
       'una semana preparada con tres de antelación se prepara sin saber nada');
    ok('la fila queda preparada',
       (string)$pdo->query("SELECT estado FROM crecer_meta_semana
                             WHERE plan_id={$PLAN} AND semana=1")->fetchColumn() === 'preparada');
    ok('con su fecha de preparación',
       $pdo->query("SELECT preparada_at FROM crecer_meta_semana
                     WHERE plan_id={$PLAN} AND semana=1")->fetchColumn() !== null);
    ok('llamó al modelo UNA vez',  $cnt('crecer_ia_log') === $ia_antes + 1,
       'antes ' . $ia_antes . ' · ahora ' . $cnt('crecer_ia_log'));

    // ══════════════════════════════════════════════════════════════
    //  3 · NI EL PLAN NI LA META SE CIERRAN
    // ══════════════════════════════════════════════════════════════
    echo "\n  — terminar una semana no termina el plan —\n";
    ok('el plan sigue activo',
       (string)$pdo->query("SELECT estado FROM crecer_meta_plan WHERE id={$PLAN}")->fetchColumn() === 'activo',
       'un plan de cuatro semanas no se cierra el primer viernes');
    ok('y la meta sigue activa',
       (string)$pdo->query("SELECT estado FROM crecer_meta WHERE id={$META}")->fetchColumn() === 'activa',
       'la meta se logra por sus números o porque el dueño lo diga, nunca por esto');

    // ══════════════════════════════════════════════════════════════
    //  4 · SEGUNDO INTENTO · no duplica ni vuelve a pagar
    // ══════════════════════════════════════════════════════════════
    echo "\n  — y pedirlo otra vez no vuelve a pagar —\n";
    $ia2 = $cnt('crecer_ia_log');
    $tac2 = $cnt('crecer_meta_tactica', "plan_id={$PLAN}");
    $p2 = ciclo_preparar($pdo, $M, $META, $PLAN, 1);
    ok('contesta que ya estaba',  !empty($p2['ok']) && !empty($p2['ya']), json_encode($p2));
    ok('sin jugadas nuevas',      $cnt('crecer_meta_tactica', "plan_id={$PLAN}") === $tac2);
    ok('y SIN llamar al modelo',  $cnt('crecer_ia_log') === $ia2,
       'la reclamación ocurre antes de llamar: por eso un doble clic no cuesta dos');

    // ══════════════════════════════════════════════════════════════
    //  5 · EL ESTADO VISIBLE SALE DE LA BASE
    // ══════════════════════════════════════════════════════════════
    echo "\n  — y la pantalla sabe en qué punto va —\n";
    $meta = meta_por_id($pdo, $META, $M);
    $plan = meta_plan_por_id($pdo, $PLAN, $M);
    $e = ciclo_estado($pdo, $M, $meta, $plan);
    ok('hay semana 2 que revisar', $e['clase'] === 'revisar', $e['clase'] . ' · semana ' . $e['semana']);
    ok('y va por la 2 de 4',       (int)$e['semana'] === 2 && (int)$e['semanas'] === 4,
       $e['semana'] . '/' . $e['semanas']);

    //  MARCAR LAS JUGADAS NO RESUELVE LA SEMANA. Una jugada de produccion sin
    //  su pieza esta «preparando», por muy `hecha` que diga la fila: el dueño
    //  todavia no ha visto nada. Si esto no se sostuviera, la pantalla le
    //  pediria cerrar una semana con publicaciones sin decidir encima.
    $pdo->prepare("UPDATE crecer_meta_tactica SET estado='hecha' WHERE plan_id=? AND semana=2")
        ->execute([$PLAN]);
    ok('las jugadas hechas no bastan',
       ciclo_estado($pdo, $M, $meta, $plan)['clase'] === 'revisar',
       'sin piezas decididas la semana sigue viva');

    //  Ahora sí: el corillo entrega la pieza de cada jugada y el dueño la
    //  aprueba. Eso —y no otra cosa— es una semana resuelta.
    $pz = $pdo->prepare("INSERT INTO crecer_contenido
             (marca_id,plataforma,tipo,caption,estado,meta_id,plan_id,tactica_id,fecha_programada)
           VALUES (?, 'instagram', 'post', 'Pieza entregada.', 'aprobado', ?, ?, ?, CURDATE())");
    foreach (semana_construir($pdo, $M, $meta, $plan, 2)['items'] as $it) {
        if (empty($it['preparando'])) continue;
        $pz->execute([$M, $META, $PLAN, (int)$it['tactica']['id']]);
    }
    $e = ciclo_estado($pdo, $M, $meta, $plan);
    ok('resuelta, toca cerrar',    $e['clase'] === 'cerrar', $e['clase']);

    // ══════════════════════════════════════════════════════════════
    //  5b · EL BARRIDO DEL CORILLO
    // ══════════════════════════════════════════════════════════════
    //  El dueño cierra la semana un domingo por la noche y se va. Si la
    //  preparación solo saliera de su botón, la semana siguiente se quedaría sin
    //  empezar hasta que volviera a abrir la aplicación —y el producto se llama
    //  «el corillo trabaja para ti», no «trabaja cuando lo miras».
    echo "
  — y el cron lo hace solo —
";
    ciclo_cerrar($pdo, $M, $META, $PLAN, 2, 'igual', '');
    $ia_b = $cnt('crecer_ia_log');
    $b = ciclo_barrer($pdo);
    ok('el barrido preparó la que estaba esperando',
       (int)($b['preparadas'] ?? 0) >= 1, json_encode($b));
    ok('y costó una sola llamada', $cnt('crecer_ia_log') === $ia_b + 1,
       'antes ' . $ia_b . ' · ahora ' . $cnt('crecer_ia_log'));
    ok('nació la semana 3',
       $cnt('crecer_meta_tactica', "plan_id={$PLAN} AND semana=3") > 0);
    //  Y NO SE REPITE: pasar otra vez no encuentra nada que hacer, que es lo
    //  que tiene que ocurrir con un cron cada quince minutos.
    $ia_b2 = $cnt('crecer_ia_log');
    $b2 = ciclo_barrer($pdo);
    ok('pasar otra vez no prepara nada', (int)($b2['preparadas'] ?? 0) === 0, json_encode($b2));
    ok('y no vuelve a llamar al modelo', $cnt('crecer_ia_log') === $ia_b2);
    ok('sin adelantar la 4',
       $cnt('crecer_meta_tactica', "plan_id={$PLAN} AND semana>3") === 0);

    // ══════════════════════════════════════════════════════════════
    //  6 · LA ÚLTIMA SEMANA SÍ CIERRA EL PLAN
    // ══════════════════════════════════════════════════════════════
    echo "\n  — y la última semana sí cierra el plan —\n";
    //  Se salta a la última: cerrar la 4 y pedir la 5 no puede abrir nada. Lo
    //  que hay vivo es la semana 3 —la que acaba de traer el barrido—, así que
    //  es esa la que se mueve al final del plan.
    $pdo->prepare("UPDATE crecer_meta_tactica SET semana=4, estado='hecha'
                    WHERE plan_id=? AND semana IN (2,3)")->execute([$PLAN]);
    ciclo_cerrar($pdo, $M, $META, $PLAN, 4);
    $ia3 = $cnt('crecer_ia_log');
    $p4 = ciclo_preparar($pdo, $M, $META, $PLAN, 4);
    ok('no prepara una semana 5',  empty($p4['ok']) && $p4['motivo'] === 'plan_completo',
       json_encode($p4));
    ok('sin llamar al modelo',     $cnt('crecer_ia_log') === $ia3);
    ok('el plan queda completado',
       (string)$pdo->query("SELECT estado FROM crecer_meta_plan WHERE id={$PLAN}")->fetchColumn() === 'completado');
    ok('PERO la meta sigue activa',
       (string)$pdo->query("SELECT estado FROM crecer_meta WHERE id={$META}")->fetchColumn() === 'activa',
       'completar un plan no es lograr una meta');

} catch (Throwable $e) {
    $fallos++; echo "\n  EXCEPCION · " . get_class($e) . ': ' . $e->getMessage()
                  . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    foreach ($limpiar as $mid) {
        try { $pdo->prepare("DELETE FROM crecer_meta_semana WHERE marca_id=?")->execute([$mid]); }
        catch (Throwable $e) {}
        try { Fixture::limpiar($pdo, $mid); } catch (Throwable $e) {}
    }
    echo "\n  (fixtures limpiadas)\n";
}

echo "\n  — el costo —\n";
ok('cero llamadas reales al modelo', $cnt('crecer_ia_log', "modelo <> 'mock'") === $g0['real'],
   ($cnt('crecer_ia_log', "modelo <> 'mock'") - $g0['real']) . ' de verdad');
ok('y no quedó ni una línea de prueba en el log',
   $cnt('crecer_ia_log') === $g0['ia'],
   ($cnt('crecer_ia_log') - $g0['ia']) . ' sobrevivieron · el log de IA es evidencia');

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  UNA SEMANA SE CIERRA Y OTRA SE ABRE · $n afirmaciones\n\n"
    : "  $fallos FALLAS de $n\n\n";
exit($fallos === 0 ? 0 : 1);
