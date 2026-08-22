<?php
// ============================================================
//  CRECER — «EMPEZAR UN PLAN NUEVO» NO PUEDE MENTIR
//  tests/test_meta_replan_atomico.php
//
//  EL DEFECTO QUE ORIGINA ESTO (produccion, 2026-08-22)
//  Al completar «Empezar un plan nuevo» el wizard vuelve a Tu Meta y ahi sigue
//  el plan que se acababa de reemplazar. Ni se abre el nuevo ni se avisa de
//  nada: la pantalla dice que si y no paso nada.
//
//  EL CONTRATO. Solo hay dos finales aceptables:
//
//    A) El plan anterior sigue activo Y el wizard muestra un error.
//    B) El anterior queda cerrado Y existe EXACTAMENTE UN plan nuevo activo.
//
//  Nunca dos activos. Nunca cero. Nunca ok:true sin haber hecho nada.
//
//  LO QUE SE MIRO EN EL CODIGO ANTES DE ESCRIBIR ESTO
//  meta_plan_generar() (includes/meta_negocio.php:596-615) hace, dentro de un
//  solo try y SIN transaccion:
//      $ant = meta_plan_activo(...);
//      if ($ant) meta_plan_cerrar(...);     <-- se ignora lo que devuelve
//      $ver = MAX(version)+1;
//      INSERT ... estado='activo';
//  De ahi salen las dos averias que se reproducen abajo: si el cierre falla,
//  el INSERT entra igual y quedan DOS activos; si el INSERT falla, el anterior
//  ya se cerro y quedan CERO.
//
//  Y hay una tercera, en el candado de doble clic de panel/meta.php:120: cuando
//  corta, contesta {"ok":true,"repetido":true}. El wizard solo mira j.ok
//  (_meta_opciones.php:535), asi que se da la vuelta a Tu Meta como si hubiera
//  funcionado. Si ese candado corta en el PRIMER clic, el sintoma es
//  exactamente el reportado.
//
//  CERO GASTO: sin credenciales, ia_ejecutar() cae al mock_texto que el propio
//  meta_plan_generar() le pasa. Es el codigo de produccion, sin proveedor.
//
//  LAS AVERIAS SE PROVOCAN SOBRE UNA COPIA DESECHABLE DEL ESQUEMA. Nunca se
//  hace DDL contra la base compartida: un ALTER hace COMMIT implicito y se
//  llevaria por delante lo que estuviera abierto.
// ============================================================

require_once __DIR__ . '/_sin_gasto.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/meta_negocio.php';
require_once dirname(__DIR__) . '/core/Meta/MetaSnapshotReader.php';
require_once __DIR__ . '/_fixture.php';
require_once __DIR__ . '/_esquema_desechable.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

/** Cuantos planes activos tiene una meta, y cuales. */
function activos(PDO $pdo, int $meta_id): array {
    $q = $pdo->prepare("SELECT id, version, estado FROM crecer_meta_plan
                         WHERE meta_id=? AND estado='activo' ORDER BY version");
    $q->execute([$meta_id]);
    return $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
function planes(PDO $pdo, int $meta_id): array {
    $q = $pdo->prepare("SELECT id, version, estado, cierre_at FROM crecer_meta_plan
                         WHERE meta_id=? ORDER BY version");
    $q->execute([$meta_id]);
    return $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
function pinta(array $ps): string {
    $b = [];
    foreach ($ps as $p) $b[] = '#' . $p['id'] . ' v' . $p['version'] . ' ' . $p['estado'];
    return $b ? implode(' · ', $b) : '(ninguno)';
}

echo "\nEL REPLAN NO PUEDE MENTIR · cinco escenarios\n" . str_repeat('=', 62) . "\n";

// ══════════════════════════════════════════════════════════════
//  1 · EXITO COMPLETO
// ══════════════════════════════════════════════════════════════
echo "\n  — 1 · exito completo —\n";
$fx = Fixture::crear($pdo, 'replan-ok');
try {
    $meta_id = (int)$fx['meta_id'];
    $antes = activos($pdo, $meta_id);
    ok('de partida hay exactamente un plan activo', count($antes) === 1, pinta($antes));
    $v0 = (int)($antes[0]['version'] ?? 0);
    $id0 = (int)($antes[0]['id'] ?? 0);

    $r = meta_plan_generar($pdo, (int)$fx['marca_id'], $meta_id, 'el anterior no movio nada');
    ok('meta_plan_generar dice ok', !empty($r['ok']), json_encode($r['err'] ?? null));

    $desp = activos($pdo, $meta_id);
    ok('queda EXACTAMENTE UNO activo', count($desp) === 1, pinta(planes($pdo, $meta_id)));
    ok('y es uno NUEVO', count($desp) === 1 && (int)$desp[0]['id'] !== $id0,
       pinta($desp) . ' · el anterior era #' . $id0);
    ok('con version mayor', count($desp) === 1 && (int)$desp[0]['version'] > $v0);

    $ant = null;
    foreach (planes($pdo, $meta_id) as $p) if ((int)$p['id'] === $id0) $ant = $p;
    ok('el anterior quedo «reemplazado»', ($ant['estado'] ?? '') === 'reemplazado', $ant['estado'] ?? 'null');
    ok('y con fecha de cierre', !empty($ant['cierre_at']),
       'sin cierre_at no se puede saber cuando dejo de contar');

    // ── 5 · LO QUE LEE LA PANTALLA DESPUES ────────────────────
    //  Es el escenario que el dueño describe: la respuesta fue buena y aun asi
    //  Tu Meta sigue enseñando el de antes.
    echo "\n  — 5 · tras un exito, ¿que lee la pantalla? —\n";
    $vig = meta_plan_activo($pdo, $meta_id);
    ok('meta_plan_activo() devuelve el NUEVO', (int)($vig['id'] ?? 0) === (int)$desp[0]['id'],
       '#' . (int)($vig['id'] ?? 0) . ' en vez de #' . (int)$desp[0]['id']);

    $snap = MetaSnapshotReader::leer($pdo, (int)$fx['marca_id']);
    $sp = (array)($snap['plan'] ?? []);
    ok('MetaSnapshotReader devuelve el NUEVO', (int)($sp['id'] ?? 0) === (int)$desp[0]['id'],
       '#' . (int)($sp['id'] ?? 0) . ' en vez de #' . (int)$desp[0]['id']);
    ok('los dos lectores coinciden', (int)($sp['id'] ?? 0) === (int)($vig['id'] ?? 0),
       'si difieren, el wizard y Tu Meta miran planes distintos y el candado '
     . 'de doble clic corta en el primer clic');

    //  Y las jugadas que se ven tienen que ser las del plan nuevo.
    //
    //  OJO CON COMO SE MIDE ESTO. La primera version preguntaba por
    //  $j['plan_id'] y lo daba por «de otro plan» cuando venia vacio — pero el
    //  lector NO devuelve esa columna (filtra por ella y no la selecciona:
    //  MetaSnapshotReader.php:145). Asi que marcaba en rojo TODAS las jugadas,
    //  incluidas las correctas. Un rojo que no señala ningun defecto enseña a
    //  ignorar el rojo. Ahora se pregunta a la base por los ids que devolvio.
    $jg  = (array)($snap['jugadas'] ?? []);
    $ids = array_map(fn($j) => (int)$j['id'], $jg);
    $ajenas = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $q  = $pdo->prepare("SELECT id, plan_id FROM crecer_meta_tactica WHERE id IN ($in)");
        $q->execute($ids);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $t) {
            if ((int)$t['plan_id'] !== (int)$desp[0]['id']) $ajenas[] = (int)$t['id'];
        }
    }
    ok('las jugadas visibles son las del plan nuevo', $ajenas === [],
       count($ajenas) . ' jugadas de otro plan (' . implode(',', $ajenas)
     . ') · el dueño veria el trabajo viejo');
    ok('y hay alguna que ver', $ids !== [],
       'cero jugadas: el plan nuevo nacio vacio, que es otra forma de mentir');

    // ── 4 · DOBLE CLIC ────────────────────────────────────────
    echo "\n  — 4 · doble clic —\n";
    //  Se reproduce la decision del handler (panel/meta.php:118-123) con el
    //  plan_actual que el wizard tenia pintado ANTES: el del primer clic.
    $vigente2 = meta_plan_activo($pdo, $meta_id);
    $pedido   = $id0;                      // lo que el wizard llevaba en PLAN_ACTUAL
    $corta    = ($pedido > 0 && $vigente2 && (int)$vigente2['id'] !== $pedido);
    ok('el candado corta el segundo clic', $corta,
       'sin corte, el segundo clic vuelve a llamar a la Estratega y deja un plan de mas');

    $cuantos_antes = count(planes($pdo, $meta_id));
    //  El segundo clic NO llega a meta_plan_generar: eso es justo lo que hace
    //  el candado. Se comprueba que el estado no se movio.
    ok('y no nace ningun plan de mas', count(planes($pdo, $meta_id)) === $cuantos_antes);

    //  PERO la respuesta que da es ok:true sin haber hecho nada. Eso solo es
    //  correcto para el SEGUNDO clic. Si el mismo camino se dispara en el
    //  primero, es una confirmacion falsa — y el wizard, que solo mira j.ok,
    //  se da la vuelta a Tu Meta con el plan viejo.
    $respuesta = ['ok' => true, 'repetido' => true, 'plan' => (int)$vigente2['id']];
    ok('la respuesta del candado se distingue de un exito', isset($respuesta['repetido']),
       'lleva «repetido», pero el wizard no lo mira: solo mira j.ok');
    ok('EL WIZARD SABE DISTINGUIRLA', (function (): bool {
            $js = (string)file_get_contents(dirname(__DIR__) . '/panel/_meta_opciones.php');
            //  Se busca que el cliente haga ALGO con «repetido». Hoy no lo hace.
            return strpos($js, 'repetido') !== false;
        })(),
       'panel/_meta_opciones.php no menciona «repetido» ni una vez: trata el '
     . 'corte del candado igual que un exito. Si el candado llega a cortar en '
     . 'el primer clic, el sintoma es exactamente el reportado.');

} finally {
    Fixture::limpiar($pdo, (int)$fx['marca_id']);
}

// ══════════════════════════════════════════════════════════════
//  2 y 3 · LAS DOS MITADES DE LA ESCRITURA, ROTAS A PROPOSITO
// ══════════════════════════════════════════════════════════════
//  Sobre una COPIA del esquema. Se rompe escondiendo una columna: eso falla en
//  cualquier sql_mode, al contrario que meter un valor fuera de rango — este
//  MySQL no es estricto y trunca en silencio.
echo "\n  — 2 y 3 · la escritura, partida por la mitad —\n";
$copia = EsquemaDesechable::crear($pdo);
if ($copia === null) {
    echo "  (saltada: este usuario de base de datos no puede crear bases)\n";
} else {
    try {
        $cpdo = $copia->pdo();

        // ── 2 · FALLA EL CIERRE DEL ANTERIOR ──────────────────
        $f2 = Fixture::crear($cpdo, 'replan-cierre');
        $m2 = (int)$f2['meta_id'];
        $id_ant = (int)(activos($cpdo, $m2)[0]['id'] ?? 0);

        //  meta_plan_cerrar() escribe cierre_at. Sin esa columna, su UPDATE
        //  lanza, el catch de dentro lo traga y devuelve false — que es
        //  exactamente lo que meta_plan_generar() no mira.
        $copia->ejecutar("ALTER TABLE crecer_meta_plan CHANGE cierre_at cierre_at_zz DATETIME NULL");
        $r2 = meta_plan_generar($cpdo, (int)$f2['marca_id'], $m2, 'no funciono');
        $copia->ejecutar("ALTER TABLE crecer_meta_plan CHANGE cierre_at_zz cierre_at DATETIME NULL");

        $act2 = activos($cpdo, $m2);
        echo "         estado tras el fallo: " . pinta(planes($cpdo, $m2)) . "\n";
        ok('con el cierre roto, NO quedan dos planes activos', count($act2) <= 1,
           count($act2) . ' activos · el cierre fallo y el INSERT entro igual porque '
         . 'meta_plan_generar() ignora lo que devuelve meta_plan_cerrar()');
        ok('y si dice ok, es porque de verdad hay uno solo',
           empty($r2['ok']) || count($act2) === 1,
           'dijo ok con ' . count($act2) . ' activos: confirmacion falsa');

        // ── 3 · FALLA EL INSERT DEL NUEVO ─────────────────────
        $f3 = Fixture::crear($cpdo, 'replan-insert');
        $m3 = (int)$f3['meta_id'];
        $id3 = (int)(activos($cpdo, $m3)[0]['id'] ?? 0);

        //  El INSERT del plan nuevo nombra `veredicto`. Sin ella, lanza.
        $copia->ejecutar("ALTER TABLE crecer_meta_plan CHANGE veredicto veredicto_zz VARCHAR(24) NULL");
        $r3 = meta_plan_generar($cpdo, (int)$f3['marca_id'], $m3, 'otra vez no');
        $copia->ejecutar("ALTER TABLE crecer_meta_plan CHANGE veredicto_zz veredicto VARCHAR(24) NULL");

        $act3 = activos($cpdo, $m3);
        echo "         estado tras el fallo: " . pinta(planes($cpdo, $m3)) . "\n";
        ok('con el INSERT roto, el plan anterior SIGUE activo', count($act3) === 1
           && (int)$act3[0]['id'] === $id3,
           pinta($act3) . ' · el anterior ya se habia cerrado y el nuevo no llego: '
         . 'la meta se queda sin ningun plan vivo');
        ok('y el wizard recibe un error, no un ok', empty($r3['ok']),
           'dijo ok:true con ' . count($act3) . ' planes activos');

        //  Y lo peor de esta rama: el catch de meta_plan_generar BORRA las
        //  jugadas pendientes. Una operacion fallida no puede destruir trabajo.
        $q = $cpdo->prepare("SELECT COUNT(*) FROM crecer_meta_tactica WHERE meta_id=?");
        $q->execute([$m3]);
        ok('el fallo no borro las jugadas del plan que sigue vivo', (int)$q->fetchColumn() > 0,
           'el catch hace DELETE de las pendientes: una operacion que falla '
         . 'destruye el trabajo del plan que se queda');

        Fixture::limpiar($cpdo, (int)$f2['marca_id']);
        Fixture::limpiar($cpdo, (int)$f3['marca_id']);
    } finally {
        $copia->soltar($pdo);
    }
}

echo "\n" . str_repeat('=', 62) . "\n";
echo $fallos === 0
    ? "  EL REPLAN CUMPLE EL CONTRATO · {$n} afirmaciones\n\n"
    : "  {$fallos} de {$n} · el replan puede mentir\n\n";
exit($fallos === 0 ? 0 : 1);
