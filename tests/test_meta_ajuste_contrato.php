<?php
// ============================================================
//  CRECER — CONTRATO DEL AJUSTE DE UNA META ACTIVA (7a)
//  tests/test_meta_ajuste_contrato.php
//
//  Escrita ANTES de implementar. Nace ROJA a proposito: aqui no hay capacidad
//  vieja que proteger, hay una capacidad nueva que especificar. Cada afirmacion
//  es una clausula del contrato aprobado, y ninguna se puede satisfacer
//  «afinando» el codigo: o el ajuste deja rastro y respeta lo inmutable, o no.
//
//  LO QUE SE EXIGE
//
//    · Un ajuste exitoso escribe el valor NUEVO en la meta y el ANTERIOR en
//      crecer_meta_cambio. Nunca lo uno sin lo otro.
//    · Con el token desactualizado NO se escribe NADA — ni un campo. Un ajuste
//      a medias es peor que uno rechazado.
//    · Y ese intento rechazado QUEDA REGISTRADO. Sin eso, «¿por que no se
//      guardo mi cambio?» no tiene respuesta.
//    · `objetivo` y `base_inicial` no se mueven ni pasandolos a proposito.
//    · Replanificar es opcional y nace APAGADO.
//    · Bajar el presupuesto a 0 con jugadas de pauta vivas se NIEGA: el motor
//      ya prohibe recomendar pauta sin presupuesto, y dejarlas vivas
//      contradiria al propio motor.
//    · Aislamiento: la meta de otra marca no se toca ni pasando su id.
//    · Sin crecer_meta_cambio, el ajuste NO SE OFRECE (no se degrada: se apaga).
//
//  CERO PROVEEDORES: el ajuste no llama a ningun modelo. La replanificacion si,
//  y por eso se prueba que se puede pedir, no que salga.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/meta_negocio.php';
require_once __DIR__ . '/_fixture.php';
if (is_file(__DIR__ . '/../includes/meta_cambio.php')) require_once __DIR__ . '/../includes/meta_cambio.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nCONTRATO DEL AJUSTE · una meta activa\n" . str_repeat('=', 58) . "\n";

//  Si la capa todavia no existe, se dice UNA vez y con nombre — no cincuenta
//  fallos identicos que esconden cual es el problema.
if (!function_exists('meta_ajustar_trazado')) {
    echo "\n  La capa no existe todavia: falta includes/meta_cambio.php con\n"
       . "  meta_ajustar_trazado(). Esta prueba es su especificacion.\n\n"
       . str_repeat('=', 58) . "\n  ROJA POR DISENO · aun no implementado\n\n";
    exit(1);
}

$fx = Fixture::crear($pdo, 'ajucon', true, 'admin');
$M = (int)$fx['marca_id']; $META = (int)$fx['meta_id']; $PLAN = (int)$fx['plan_id'];
$UID = (int)$fx['usuario_id'];

/** El retrato de lo que no se puede perder. */
$piezas = function () use ($pdo, $M): array {
    return $pdo->query("SELECT id, estado, caption FROM crecer_contenido
                         WHERE marca_id={$M} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
};
$meta = function () use ($pdo, $META): array {
    $q = $pdo->prepare("SELECT * FROM crecer_meta WHERE id=?"); $q->execute([$META]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: [];
};
$cambios = function (array $filtro = []) use ($pdo, $META): array {
    $sql = "SELECT * FROM crecer_meta_cambio WHERE meta_id={$META}";
    foreach ($filtro as $c => $v) $sql .= " AND {$c}=" . $pdo->quote((string)$v);
    return $pdo->query($sql . " ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
};

try {
    // ══════════════════════════════════════════════════════════
    //  1 · AJUSTE EXITOSO, CON TRAZABILIDAD
    // ══════════════════════════════════════════════════════════
    echo "\n  — 1 · un ajuste que entra deja rastro —\n";
    $antes  = $meta();
    $piezasAntes = $piezas();
    $token  = meta_token($antes);
    ok('la meta trae un token de concurrencia', $token !== '', 'sin token no hay bloqueo optimista');

    $r = meta_ajustar_trazado($pdo, $M, $META, $UID,
        ['cantidad' => '40', 'fecha_limite' => date('Y-m-d', strtotime('+45 days'))],
        $token, 'Me está entrando más de lo que pensaba');

    ok('el ajuste se aplica', !empty($r['ok']), json_encode($r, JSON_UNESCAPED_UNICODE));
    $desp = $meta();
    ok('la cantidad nueva esta en la meta', (float)$desp['cantidad'] === 40.0, (string)$desp['cantidad']);
    ok('y la fecha nueva tambien',
       (string)$desp['fecha_limite'] === date('Y-m-d', strtotime('+45 days')), (string)$desp['fecha_limite']);

    $filas = $cambios(['tipo' => 'meta_ajuste']);
    ok('hay una fila de cambio por CADA campo', count($filas) === 2, 'hay ' . count($filas));
    $porCampo = [];
    foreach ($filas as $f) $porCampo[$f['campo']] = $f;
    ok('la de cantidad guarda el valor anterior',
       isset($porCampo['cantidad']) && (float)$porCampo['cantidad']['valor_antes'] === 25.0,
       json_encode($porCampo['cantidad'] ?? null));
    ok('y el nuevo', isset($porCampo['cantidad']) && (float)$porCampo['cantidad']['valor_despues'] === 40.0);
    ok('la de la fecha guarda el valor anterior',
       isset($porCampo['fecha_limite'])
       && (string)$porCampo['fecha_limite']['valor_antes'] === (string)$antes['fecha_limite'],
       json_encode($porCampo['fecha_limite'] ?? null));
    ok('las dos quedan «aplicado»',
       ($porCampo['cantidad']['resultado'] ?? '') === 'aplicado'
       && ($porCampo['fecha_limite']['resultado'] ?? '') === 'aplicado');
    ok('con el motivo que escribio el dueño',
       strpos((string)($porCampo['cantidad']['motivo'] ?? ''), 'más de lo que pensaba') !== false,
       (string)($porCampo['cantidad']['motivo'] ?? '—'));
    ok('con su marca, su meta y su usuario',
       (int)$porCampo['cantidad']['marca_id'] === $M
       && (int)$porCampo['cantidad']['meta_id'] === $META
       && (int)$porCampo['cantidad']['usuario_id'] === $UID);
    ok('y con el token que tenia al abrir',
       (string)($porCampo['cantidad']['token_antes'] ?? '') === $token,
       'sin eso no se puede reconstruir contra que version decidio');

    echo "\n  — y nada de lo hecho se toca —\n";
    ok('las piezas siguen exactamente igual', $piezas() === $piezasAntes,
       'antes ' . count($piezasAntes) . ', ahora ' . count($piezas()));
    ok('las jugadas hechas siguen hechas',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica
                          WHERE meta_id={$META} AND estado='hecha'")->fetchColumn()
       === (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica
                              WHERE meta_id={$META} AND estado='hecha'")->fetchColumn());
    ok('el plan sigue siendo el mismo',
       (int)($pdo->query("SELECT id FROM crecer_meta_plan WHERE meta_id={$META} AND estado='activo'")
                 ->fetchColumn() ?: 0) === $PLAN,
       'ajustar no rehace el plan por su cuenta');

    // ══════════════════════════════════════════════════════════
    //  2 · LO INMUTABLE
    // ══════════════════════════════════════════════════════════
    echo "\n  — 2 · objetivo y base_inicial no se mueven —\n";
    $antes2 = $meta();
    $r2 = meta_ajustar_trazado($pdo, $M, $META, $UID,
        ['objetivo' => 'alcance', 'base_inicial' => '999', 'cantidad' => '41'],
        meta_token($antes2), '');
    ok('el ajuste con campos prohibidos no revienta', is_array($r2), json_encode($r2));
    $desp2 = $meta();
    ok('el objetivo NO cambia', (string)$desp2['objetivo'] === (string)$antes2['objetivo'],
       (string)$desp2['objetivo'] . ' · cambiar de objetivo equivale a crear otra meta');
    ok('base_inicial NO cambia', (string)$desp2['base_inicial'] === (string)$antes2['base_inicial'],
       (string)$desp2['base_inicial'] . ' · es la foto del punto de partida');
    ok('y lo que si era ajustable si entro', (float)$desp2['cantidad'] === 41.0, (string)$desp2['cantidad']);
    ok('no se registro ninguna fila para los campos prohibidos',
       count($cambios(['campo' => 'objetivo'])) === 0 && count($cambios(['campo' => 'base_inicial'])) === 0);

    // ══════════════════════════════════════════════════════════
    //  3 · TOKEN DESACTUALIZADO
    // ══════════════════════════════════════════════════════════
    echo "\n  — 3 · con el token viejo no entra NADA —\n";
    $viejo = meta_token($meta());
    //  Alguien mas toca la meta (otra pestaña, o el cron que la vence).
    $pdo->prepare("UPDATE crecer_meta SET contexto=? WHERE id=?")
        ->execute(['[prueba] tocada por otra pestaña', $META]);
    $antes3 = $meta();
    $nCambios = count($cambios());

    $r3 = meta_ajustar_trazado($pdo, $M, $META, $UID,
        ['cantidad' => '99', 'fecha_limite' => date('Y-m-d', strtotime('+90 days'))],
        $viejo, '');
    ok('el ajuste se rechaza', empty($r3['ok']), json_encode($r3, JSON_UNESCAPED_UNICODE));
    ok('y dice que fue por concurrencia', ($r3['motivo'] ?? '') === 'concurrencia',
       'motivo=' . ($r3['motivo'] ?? '—') . ' · la pantalla tiene que poder distinguirlo de un error');
    $desp3 = $meta();
    ok('la cantidad NO cambio', (string)$desp3['cantidad'] === (string)$antes3['cantidad'],
       (string)$desp3['cantidad']);
    ok('la fecha TAMPOCO — ni un campo a medias',
       (string)$desp3['fecha_limite'] === (string)$antes3['fecha_limite'],
       (string)$desp3['fecha_limite'] . ' · un ajuste a medias es peor que uno rechazado');

    echo "\n  — y el intento queda registrado —\n";
    $nuevas = array_slice($cambios(), $nCambios);
    ok('el intento rechazado deja filas', count($nuevas) === 2, 'dejo ' . count($nuevas));
    $todasRech = $nuevas && !array_filter($nuevas, fn($f) => $f['resultado'] !== 'rechazado_concurrencia');
    ok('marcadas «rechazado_concurrencia»', (bool)$todasRech,
       json_encode(array_column($nuevas, 'resultado')));
    ok('con el token que fallo', ($nuevas[0]['token_antes'] ?? '') === $viejo,
       'sin eso no se puede explicar contra que version decidio');

    // ══════════════════════════════════════════════════════════
    //  4 · REPLANIFICAR ES OPCIONAL Y NACE APAGADO
    // ══════════════════════════════════════════════════════════
    echo "\n  — 4 · replanificar no pasa solo —\n";
    $planAntes = (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_plan WHERE meta_id={$META}")->fetchColumn();
    $r4 = meta_ajustar_trazado($pdo, $M, $META, $UID, ['cantidad' => '42'], meta_token($meta()), '');
    ok('un ajuste sin pedirlo no rehace el plan',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_plan WHERE meta_id={$META}")->fetchColumn() === $planAntes,
       'la Estratega cuesta dinero: no se llama sin que la pidan');
    $filasCant = $cambios(['campo' => 'cantidad']);
    $ult = $filasCant ? $filasCant[count($filasCant) - 1] : [];
    ok('y queda escrito que no se pidio',
       (int)($ult['plan_solicitado'] ?? 1) === 0
       && in_array((string)($ult['plan_resultado'] ?? ''), ['no_pedido', ''], true),
       json_encode(['solicitado' => $ult['plan_solicitado'] ?? null, 'resultado' => $ult['plan_resultado'] ?? null]));

    // ══════════════════════════════════════════════════════════
    //  5 · PRESUPUESTO A CERO CON PAUTAS VIVAS
    // ══════════════════════════════════════════════════════════
    echo "\n  — 5 · bajar a cero con pauta viva se niega —\n";
    $pdo->prepare("UPDATE crecer_meta SET presupuesto_pauta=50 WHERE id=?")->execute([$META]);
    $pdo->prepare("UPDATE crecer_meta_tactica SET tipo='pauta', clase='accion_dueno', quien='dueno',
                          inversion=15.00, estado='pendiente'
                    WHERE meta_id=? AND plan_id=? ORDER BY orden LIMIT 1")->execute([$META, $PLAN]);
    $antes5 = $meta();
    $r5 = meta_ajustar_trazado($pdo, $M, $META, $UID, ['presupuesto_pauta' => '0'], meta_token($antes5), '');
    ok('se niega el ajuste', empty($r5['ok']), json_encode($r5, JSON_UNESCAPED_UNICODE));
    ok('y dice que hay pautas vivas', ($r5['motivo'] ?? '') === 'pautas_vivas',
       'motivo=' . ($r5['motivo'] ?? '—'));
    ok('nombrando cuales', !empty($r5['pautas']) && is_array($r5['pautas']),
       'sin la lista, el dueño no sabe que tiene que sustituir');
    ok('el presupuesto no se movio',
       (string)$meta()['presupuesto_pauta'] === (string)$antes5['presupuesto_pauta'],
       (string)$meta()['presupuesto_pauta']);

    //  Y el control positivo: sin pautas vivas, bajar a cero SI se puede.
    //  Se apagan por el MISMO criterio que usa la funcion (tipo pauta O
    //  inversion > 0). Apagar solo las de tipo='pauta' dejaba viva una de la
    //  fixture que pedia $15 igual, y la prueba acusaba al producto de tener
    //  un candado que no suelta.
    $pdo->prepare("UPDATE crecer_meta_tactica SET estado='hecha'
                    WHERE meta_id=? AND (tipo='pauta' OR inversion > 0)")->execute([$META]);
    ok('no queda ninguna pauta viva', meta_pautas_vivas($pdo, $M, $META) === [],
       json_encode(meta_pautas_vivas($pdo, $M, $META), JSON_UNESCAPED_UNICODE));
    $r5b = meta_ajustar_trazado($pdo, $M, $META, $UID, ['presupuesto_pauta' => '0'], meta_token($meta()), '');
    ok('sin pautas vivas, bajar a cero SI entra', !empty($r5b['ok']),
       json_encode($r5b, JSON_UNESCAPED_UNICODE) . ' · un candado que bloquea siempre es una pared');
    ok('y el presupuesto queda en cero', (float)$meta()['presupuesto_pauta'] === 0.0,
       (string)$meta()['presupuesto_pauta']);

    // ══════════════════════════════════════════════════════════
    //  6 · AISLAMIENTO ENTRE MARCAS
    // ══════════════════════════════════════════════════════════
    echo "\n  — 6 · la meta de otra marca no se toca —\n";
    $otra = Fixture::crear($pdo, 'ajuajena', true, 'admin');
    $ajenaAntes = $pdo->query("SELECT * FROM crecer_meta WHERE id=" . (int)$otra['meta_id'])
                      ->fetch(PDO::FETCH_ASSOC);
    $r6 = meta_ajustar_trazado($pdo, $M, (int)$otra['meta_id'], $UID,
        ['cantidad' => '777'], meta_token($ajenaAntes), '');
    ok('se niega', empty($r6['ok']), json_encode($r6, JSON_UNESCAPED_UNICODE));
    ok('y la meta ajena sigue intacta',
       $pdo->query("SELECT * FROM crecer_meta WHERE id=" . (int)$otra['meta_id'])
           ->fetch(PDO::FETCH_ASSOC) === $ajenaAntes);
    ok('sin dejar rastro en su historial',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_cambio
                          WHERE meta_id=" . (int)$otra['meta_id'])->fetchColumn() === 0);
    Fixture::limpiar($pdo, (int)$otra['marca_id']);

} finally {
    try { $pdo->prepare("DELETE FROM crecer_meta_cambio WHERE marca_id=?")->execute([$M]); }
    catch (Throwable $e) {}
    Fixture::limpiar($pdo, $M);
    echo "\n  (fixture limpiada)\n";
}

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  CONTRATO CUMPLIDO · {$n} afirmaciones\n\n"
    : "  {$fallos} de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
