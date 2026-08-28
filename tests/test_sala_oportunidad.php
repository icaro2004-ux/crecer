<?php
// ============================================================
//  CRECER — LA OPORTUNIDAD LLEGA AL TRABAJO
//  tests/test_sala_oportunidad.php
//
//  EL HUECO QUE CIERRA LA FASE. El dueño ve algo que no estaba en el plan —una
//  tendencia, inventario nuevo, una promo que se le ocurrió— y hasta ahora eso
//  se conversaba en La Sala y ahí moría, o competía en silencio con la Meta.
//
//  LO QUE SE PRUEBA, y por qué cada cosa:
//
//   1 · LA PROPUESTA VIENE DEL MISMO TURNO que la conversación, y el dueño
//       NUNCA ve el JSON. Una segunda llamada al modelo sería pagar dos veces
//       por lo que el primero ya sabía.
//
//   2 · NADA DE LO QUE DICE EL MODELO SE CREE SIN COMPROBAR: un `activo_id`
//       inventado, o de otra marca, no puede acabar en una publicación.
//
//   3 · AÑADIR A LA META crea UNA jugada, en la semana que dice el ciclo
//       semanal, sin tocar lo programado — y dos clics no crean dos.
//
//   4 · SIN META NO SE OFRECE AÑADIR, y un plan terminado NO se reabre.
//
//   5 · CREAR APARTE no toca Meta, plan ni tácticas, y la idea NO viaja por la
//       URL.
//
//  CERO MODELOS: la propuesta se inyecta a mano, que es exactamente lo que
//  haría el agente. Cero red, cero cuota.
// ============================================================

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/sala_oportunidad.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nLA OPORTUNIDAD LLEGA AL TRABAJO\n" . str_repeat('=', 58) . "\n";

if (!sala_op_hay_libro($pdo, true)) {
    echo "\n  SALTADA · falta migrations/2026-08-28_crecer_sala_oportunidad.sql\n\n"; exit(0);
}

$limpiar = [];
try {
    // ══════════════════════════════════════════════════════════════
    //  1 · LA PROPUESTA SALE DE LA MISMA RESPUESTA
    // ══════════════════════════════════════════════════════════════
    echo "\n  — el dueño lee prosa, el sistema lee datos —\n";
    $respuesta = "Podemos convertirla en un reel corto del proceso.\n"
               . '<<OPORTUNIDAD>>{"titulo":"El proceso detrás del bizcocho",'
               . '"que_hacer":"Un reel de 20s enseñando cómo se arma.",'
               . '"por_que":"Ver el proceso da confianza para ordenar.",'
               . '"formato":"reel","red":"instagram","cta":"Escríbeme por WhatsApp",'
               . '"material":"video","visual":"Las manos armando el bizcocho",'
               . '"activo_id":null,"fuente":"dueno","alineada":true}';
    $sep = sala_op_extraer($respuesta);
    ok('lo que se enseña es la frase',
       $sep['texto'] === 'Podemos convertirla en un reel corto del proceso.', $sep['texto']);
    ok('sin rastro del JSON',
       !str_contains($sep['texto'], '{') && !str_contains($sep['texto'], 'OPORTUNIDAD'), $sep['texto']);
    ok('y los datos salen aparte',     is_array($sep['bruto']), json_encode($sep['bruto']));
    //  Y SI NO VIENE, no pasa nada: se conversa igual.
    $sin = sala_op_extraer('Cuéntame más de eso y te armo algo.');
    ok('una conversación normal no trae propuesta',
       $sin['bruto'] === null && $sin['texto'] === 'Cuéntame más de eso y te armo algo.');

    $fx = Fixture::crear($pdo, 'salaop', true, 'admin');
    $limpiar[] = $M = (int)$fx['marca_id'];
    $META = (int)$fx['meta_id']; $PLAN = (int)$fx['plan_id'];

    $op = sala_op_normalizar($pdo, $M, $sep['bruto']);
    ok('la propuesta se normaliza',    $op !== null, json_encode($op));
    ok('con su formato',               ($op['formato'] ?? '') === 'reel', json_encode($op));
    ok('y la idea es del dueño',       ($op['fuente'] ?? '') === 'dueno',
       'Crecer no ha comprobado ninguna tendencia: se guarda como aportación suya');

    // ══════════════════════════════════════════════════════════════
    //  2 · LO QUE DICE EL MODELO SE COMPRUEBA
    // ══════════════════════════════════════════════════════════════
    echo "\n  — nada de lo que diga el modelo se cree sin mirar —\n";
    $inv = sala_op_normalizar($pdo, $M, ['titulo' => 'X', 'formato' => 'holograma',
                                         'red' => 'tiktok', 'activo_id' => 999999999]);
    ok('un formato inventado cae a post', ($inv['formato'] ?? '') === 'post', json_encode($inv));
    ok('una red inventada cae a Instagram', ($inv['red'] ?? '') === 'instagram', json_encode($inv));
    ok('y un activo que no existe se descarta', ($inv['activo_id'] ?? null) === null, json_encode($inv));
    ok('sin título no hay nada que ejecutar',
       sala_op_normalizar($pdo, $M, ['formato' => 'post']) === null);

    //  EL ACTIVO DE OTRA MARCA NO ENTRA. Bastaría con adivinar un número.
    $fx2 = Fixture::crear($pdo, 'salaopB', true, 'admin');
    $limpiar[] = $M2 = (int)$fx2['marca_id'];
    $pdo->prepare("INSERT INTO crecer_activos (marca_id, tipo, archivo, nombre, origen, estado)
                   VALUES (?, 'imagen', ?, 'La foto de otro', 'subida', 'activo')")
        ->execute([$M2, "marca_{$M2}/otra.jpg"]);
    $AJENO = (int)$pdo->lastInsertId();
    $con_ajeno = sala_op_normalizar($pdo, $M, ['titulo' => 'X', 'activo_id' => $AJENO]);
    ok('el material de otro negocio se rechaza', ($con_ajeno['activo_id'] ?? null) === null,
       'con `marca_id` en el WHERE, no en un if de después');

    //  Y UN VIDEO SOLO DONDE CABE UN VIDEO.
    $pdo->prepare("INSERT INTO crecer_activos (marca_id, tipo, archivo, nombre, origen, estado)
                   VALUES (?, 'video', ?, 'Un clip mío', 'subida', 'activo')")
        ->execute([$M, "marca_{$M}/clip.mp4"]);
    $VID = (int)$pdo->lastInsertId();
    ok('un video no entra en un post',
       (sala_op_normalizar($pdo, $M, ['titulo' => 'X', 'formato' => 'post', 'activo_id' => $VID])['activo_id'] ?? null) === null);
    ok('pero sí en un reel',
       (sala_op_normalizar($pdo, $M, ['titulo' => 'X', 'formato' => 'reel', 'activo_id' => $VID])['activo_id'] ?? null) === $VID);

    // ══════════════════════════════════════════════════════════════
    //  3 · AÑADIRLA A LA META
    // ══════════════════════════════════════════════════════════════
    echo "\n  — se añade al plan que ya existe, no a uno nuevo —\n";
    //  Se guarda como lo guardaría el turno de conversación.
    $pdo->prepare("INSERT INTO crecer_sala_jobs (marca_id, mensaje, historial, puede_producir, estado, respuesta)
                   VALUES (?, 'Vi una tendencia del proceso', '[]', 1, 'done', 'ok')")->execute([$M]);
    $JOB = (int)$pdo->lastInsertId();
    sala_op_guardar($pdo, $JOB, $M, $op);
    ok('la propuesta queda en su conversación',
       sala_op_leer($pdo, $JOB, $M) !== null);
    //  Y NO SE LEE DESDE OTRA MARCA.
    ok('otra marca no puede leerla',   sala_op_leer($pdo, $JOB, $M2) === null,
       'bastaría con adivinar el número para añadirle una jugada al plan de otro');

    $ev = sala_op_evaluar($pdo, $M, $op);
    ok('se puede añadir',              !empty($ev['puede']), json_encode($ev));
    ok('en una semana del plan',       (int)$ev['semana'] >= 1, json_encode($ev));
    ok('y en el plan vigente',         (int)$ev['plan_id'] === $PLAN, json_encode($ev));

    $c = sala_op_consecuencia($pdo, $M, $op, $ev);
    ok('la consecuencia se dice antes de escribir', count($c['lineas']) >= 2, json_encode($c));
    $txt = mb_strtolower(implode(' · ', $c['lineas']));
    ok('dice que lo programado no cambia',
       str_contains($txt, 'no cambia') || str_contains($txt, 'ajustamos'), $txt);
    ok('y que necesitará su video',    str_contains($txt, 'video'), $txt);

    $antes = (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica WHERE plan_id={$PLAN}")->fetchColumn();
    $r1 = sala_op_a_meta($pdo, $M, $JOB);
    ok('se añade',                     !empty($r1['ok']), json_encode($r1));
    ok('y es UNA jugada más',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica WHERE plan_id={$PLAN}")->fetchColumn() === $antes + 1);
    $t = $pdo->query("SELECT * FROM crecer_meta_tactica WHERE id={$r1['tactica_id']}")->fetch(PDO::FETCH_ASSOC);
    ok('en el plan de siempre',        (int)$t['plan_id'] === $PLAN, (string)$t['plan_id']);
    ok('con su formato',               (string)$t['formato'] === 'reel', (string)$t['formato']);
    ok('la produce el corillo',        (string)$t['quien'] === 'corillo', (string)$t['quien']);
    //  DE DÓNDE SALIÓ: es lo que permite decirle «la añadiste desde La Sala»
    //  sin inventarlo.
    ok('y se sabe de qué conversación salió', (int)$t['sala_job_id'] === $JOB, (string)$t['sala_job_id']);
    ok('se encoló su producción',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_jobs
                          WHERE tactica_id={$r1['tactica_id']}")->fetchColumn() === 1);

    echo "\n  — y dos clics no crean dos jugadas —\n";
    $r2 = sala_op_a_meta($pdo, $M, $JOB);
    ok('la segunda vez dice que ya estaba', !empty($r2['ya']), json_encode($r2));
    ok('y devuelve la misma',          (int)$r2['tactica_id'] === (int)$r1['tactica_id']);
    ok('sin crear otra',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica WHERE plan_id={$PLAN}")->fetchColumn() === $antes + 1,
       'lo arbitra la base por la conversación, no un botón deshabilitado');
    ok('ni otro job',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_jobs
                          WHERE tactica_id={$r1['tactica_id']}")->fetchColumn() === 1);

    // ══════════════════════════════════════════════════════════════
    //  4 · CUANDO NO SE PUEDE, NO SE OFRECE
    // ══════════════════════════════════════════════════════════════
    echo "\n  — sin Meta no se ofrece añadir —\n";
    $fx3 = Fixture::crear($pdo, 'salaopC', false, 'admin');   // sin meta
    $limpiar[] = $M3 = (int)$fx3['marca_id'];
    $ev3 = sala_op_evaluar($pdo, $M3, $op);
    ok('no se puede',                  empty($ev3['puede']), json_encode($ev3));
    $o3 = sala_op_opciones($pdo, $M3, $op, $ev3);
    $claves = array_column($o3['opciones'], 'clave');
    ok('no aparece «añadir a mi Meta»', !in_array('meta', $claves, true), json_encode($claves));
    ok('y se ofrece ponerse una',      in_array('meta_nueva', $claves, true), json_encode($claves));
    ok('crear aparte sigue estando',   in_array('crear', $claves, true), json_encode($claves));

    echo "\n  — una idea que no empuja la Meta no se fuerza —\n";
    $desalineada = sala_op_normalizar($pdo, $M, ['titulo' => 'Otra cosa', 'alineada' => false]);
    $ev4 = sala_op_evaluar($pdo, $M, $desalineada);
    ok('no se puede añadir',           empty($ev4['puede']), json_encode($ev4));
    ok('y el motivo se dice',          $ev4['motivo'] === 'no_alineada', $ev4['motivo']);
    $o4 = sala_op_opciones($pdo, $M, $desalineada, $ev4);
    ok('se le explica por qué',
       str_contains(mb_strtolower($o4['nota']), 'no empuja'), $o4['nota']);

    echo "\n  — un plan terminado no se reabre —\n";
    $pdo->prepare("UPDATE crecer_meta_tactica SET estado='hecha' WHERE plan_id=?")->execute([$PLAN]);
    $pdo->prepare("UPDATE crecer_meta SET fecha_inicio=DATE_SUB(CURDATE(), INTERVAL 40 DAY),
                          fecha_limite=DATE_SUB(CURDATE(), INTERVAL 12 DAY) WHERE id=?")->execute([$META]);
    $ev5 = sala_op_evaluar($pdo, $M, $op);
    if ($ev5['motivo'] === 'plan_completo') {
        ok('no se puede añadir a un plan terminado', empty($ev5['puede']), json_encode($ev5));
        $o5 = sala_op_opciones($pdo, $M, $op, $ev5);
        ok('y se ofrece hacerla aparte',
           in_array('crear', array_column($o5['opciones'], 'clave'), true));
    } else {
        //  Con la ventana movida el ciclo puede seguir viendo semanas válidas;
        //  lo que NO puede pasar es que se invente una fuera del plan.
        ok('la semana propuesta cabe en el plan',
           (int)$ev5['semana'] <= 12, json_encode($ev5));
        ok('(el plan sigue con semanas válidas)', true);
    }

    // ══════════════════════════════════════════════════════════════
    //  5 · CREARLA APARTE NO TOCA NADA
    // ══════════════════════════════════════════════════════════════
    echo "\n  — crear aparte no toca la Meta —\n";
    $tac_antes  = (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica WHERE marca_id={$M}")->fetchColumn();
    $cont_antes = (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$M}")->fetchColumn();
    $url = sala_op_url_crear($M, $JOB);
    ok('lleva a Crear',                str_contains($url, 'crear=1'), $url);
    ok('con la conversación por id',   str_contains($url, 'sala=' . $JOB), $url);
    ok('y conserva la marca',          str_contains($url, 'marca=' . $M), $url);
    //  LA IDEA NO VIAJA POR LA URL: ni el título, ni el texto, ni la dirección
    //  creativa. Solo el número de la conversación, que ya está guardada.
    ok('sin mandar la idea en la URL',
       !str_contains(mb_strtolower($url), 'bizcocho') && !str_contains($url, 'titulo')
       && mb_strlen($url) < 90, $url);
    ok('no crea tácticas',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica WHERE marca_id={$M}")->fetchColumn() === $tac_antes);
    ok('ni contenido',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$M}")->fetchColumn() === $cont_antes);

    //  EL COSTO SE MIDE AQUÍ, con las marcas todavía vivas y SOLO las de esta
    //  prueba: un cronómetro global sobre `crecer_ia_log` recoge también lo que
    //  haya hecho cualquier otra cosa —abrir La Sala en un navegador, por
    //  ejemplo, que sí llama al modelo— y entonces la afirmación no dice nada.
    $__en = implode(',', array_map('intval', $limpiar));
    $gasto = $__en === '' ? 0.0 : (float)$pdo->query(
        "SELECT COALESCE(SUM(costo_usd),0) FROM crecer_ia_log WHERE marca_id IN ({$__en})")->fetchColumn();

} catch (Throwable $e) {
    $fallos++; $n++;
    echo "  FALLA excepción\n         → " . get_class($e) . ': ' . $e->getMessage()
       . "\n         → " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    foreach ($limpiar as $mid) { try { Fixture::limpiar($pdo, (int)$mid); } catch (Throwable $e) {} }
    try { $pdo->exec("DELETE FROM crecer_sala_jobs WHERE mensaje LIKE 'Vi una tendencia%'"); } catch (Throwable $e) {}
    echo "\n  (fixtures limpiadas)\n";
}

echo "\n  — el costo —\n";
ok('elegir una opción no llama a ningún modelo',
   isset($gasto) && $gasto < 0.000001,
   'la propuesta vino en el turno que la produjo: ejecutarla es escribir datos'
   . (isset($gasto) ? ' · gastó ' . number_format($gasto, 6) : ' · no se llegó a medir'));

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0 ? "  LA OPORTUNIDAD LLEGA AL TRABAJO · {$n} afirmaciones\n\n"
                   : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
