<?php
// ============================================================
//  CRECER — PARIDAD Y CONTRATO DE MUTACIONES DE LAS TRES DELICADAS
//  tests/test_meta_opciones_paridad.php
//
//  Escrita ANTES de convertir en wizards «Empezar un plan nuevo», «Cambiar de
//  meta» y la jugada de inversión. Verde contra el código de hoy: si no pasa
//  ahora, no protege nada.
//
//  Aquí no se protege el aspecto — se protege QUÉ FILAS CAMBIAN Y CUÁLES NO.
//  Es el contrato de mutaciones, escrito en la única forma que no se queda
//  vieja: ejecutable.
//
//  ── A · PLAN NUEVO ────────────────────────────────────────────────────
//     CAMBIA:   crecer_meta_plan  el activo → 'reemplazado', con cierre_at y
//                                 sus números congelados; nace uno 'activo'
//                                 con version = max+1.
//               crecer_meta_tactica  jugadas nuevas con el plan_id nuevo.
//               crecer_meta       diagnostico, veredicto, ia_log_id.
//               crecer_ia_log     una llamada a la Estratega.
//     NO CAMBIA: crecer_contenido (los posts), y de la meta: objetivo,
//               cantidad, fecha_limite, presupuesto_pauta y contexto.
//               Las jugadas viejas NO se borran: quedan con su plan cerrado.
//
//  ── B · CAMBIAR / CERRAR META ─────────────────────────────────────────
//     CAMBIA:   crecer_meta.estado de la anterior.
//     NO CAMBIA: crecer_contenido, ni el historial de planes.
//
//  ── C · INVERSIÓN ─────────────────────────────────────────────────────
//     CAMBIA:   crecer_meta_tactica.estado → 'hecha', y SOLO desde la
//               confirmación explícita del dueño.
//     NO CAMBIA: nada más. Crecer no paga, no publica el anuncio y no puede
//               comprobar el cobro — y la pantalla no puede decir que sí.
//
//  CERO PROVEEDORES DE IMAGEN. El plan nuevo llama a la Estratega (texto); si
//  no hay credenciales, se salta lo que dependa de eso y se dice.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/meta_negocio.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nLAS TRES DELICADAS · paridad y contrato de mutaciones\n" . str_repeat('=', 58) . "\n";

$fx = Fixture::crear($pdo, 'opcpar', true, 'admin');
$M = (int)$fx['marca_id']; $META = (int)$fx['meta_id']; $PLAN = (int)$fx['plan_id'];
$sid  = 'op' . bin2hex(random_bytes(8));
$ruta = session_save_path() ?: sys_get_temp_dir();
file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
    'usuario_id|i:' . (int)$fx['usuario_id'] . ';');

$pedir = function (string $q) use ($sid): string {
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 30,
        'header' => "Cookie: PHPSESSID={$sid}\r\n"]]);
    $h = @file_get_contents('http://localhost/crecer/panel/meta.php?' . $q, false, $ctx);
    return is_string($h) ? $h : '';
};
$postear = function (array $campos) use ($sid, $M): array {
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 30,
        'header' => "Cookie: PHPSESSID={$sid}\r\n"]]);
    $html = @file_get_contents('http://localhost/crecer/panel/meta.php?marca=' . $M, false, $ctx);
    if (!preg_match('~CSRF\s*=\s*"([a-f0-9]+)"~i', (string)$html, $m)) {
        return ['ok' => false, 'err' => 'no encontré el csrf'];
    }
    $ctx2 = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 240,
        'header' => "Cookie: PHPSESSID={$sid}\r\n"
                  . "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query(['csrf' => $m[1]] + $campos), 'ignore_errors' => true]]);
    $r = @file_get_contents('http://localhost/crecer/panel/meta.php?marca=' . $M, false, $ctx2);
    $j = json_decode((string)$r, true);
    return is_array($j) ? $j : ['ok' => false, 'err' => 'no-JSON: ' . substr((string)$r, 0, 200)];
};

/** Retrato de lo que NO se puede perder, para compararlo después. */
$retrato = function () use ($pdo, $M, $META): array {
    $c = $pdo->prepare("SELECT id, estado, caption FROM crecer_contenido WHERE marca_id=? ORDER BY id");
    $c->execute([$M]);
    $meta = $pdo->prepare("SELECT objetivo, cantidad, fecha_limite, presupuesto_pauta, contexto, estado
                             FROM crecer_meta WHERE id=?");
    $meta->execute([$META]);
    return [
        'contenido' => $c->fetchAll(PDO::FETCH_ASSOC),
        'meta'      => $meta->fetch(PDO::FETCH_ASSOC) ?: [],
        'planes'    => (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_plan WHERE meta_id={$META}")->fetchColumn(),
        'jugadas'   => (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica WHERE meta_id={$META}")->fetchColumn(),
    ];
};

try {
    // ══════════════════════════════════════════════════════════
    //  LOS CONTROLES EXISTEN Y SE LLEGA A ELLOS
    // ══════════════════════════════════════════════════════════
    echo "\n  — 0 · los tres controles están en pie —\n";
    $plan = $pedir('marca=' . $M . '&vista=plan');
    ok('la vista del plan responde', strpos($plan, '<html') !== false, '¿está Apache arriba?');
    ok('existe «Empezar un plan nuevo»', strpos($plan, 'id="replan"') !== false,
       'es la única forma de pedirle jugadas nuevas a la Estratega');
    ok('existe «Cambiar de meta»', strpos($plan, 'id="cerrar"') !== false,
       'sin eso, una meta equivocada no se puede abandonar');
    ok('y viven en una capa que se puede abrir', strpos($plan, 'plan-ac') !== false);

    // ══════════════════════════════════════════════════════════
    //  A · PLAN NUEVO
    // ══════════════════════════════════════════════════════════
    echo "\n  — A · plan nuevo: qué cambia y qué no —\n";
    $vAntes = (int)$pdo->query("SELECT version FROM crecer_meta_plan WHERE id={$PLAN}")->fetchColumn();

    //  Se le deja una huella medible al plan viejo: si el cierre no congela sus
    //  números, su récord se pierde y el historial deja de servir.
    $pdo->prepare("UPDATE crecer_contenido SET estado='publicado', publicado_at=NOW()
                    WHERE marca_id=? AND plan_id=? LIMIT 1")->execute([$M, $PLAN]);
    //  El retrato se toma DESPUES de esa preparación. Tomarlo antes era
    //  comparar la pantalla con un cambio hecho por la propia prueba y acusar
    //  al producto de tocar posts que había tocado yo.
    $antes = $retrato();
    $pubEsperadas = (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido
                                       WHERE marca_id={$M} AND plan_id={$PLAN} AND estado='publicado'")->fetchColumn();

    $r = $postear(['accion' => 'replan']);
    $conEstratega = !empty($r['ok']);
    if (!$conEstratega) {
        echo "  ·    la Estratega no contestó ({$r['err']}) — se comprueba lo que no depende de ella\n";
    }
    ok('el servidor contesta a replan', array_key_exists('ok', $r), json_encode($r, JSON_UNESCAPED_UNICODE));

    $desp = $retrato();
    //  ESTO SE EXIGE PASE LO QUE PASE: un plan nuevo no puede tocar los posts.
    ok('los posts no se tocan', $desp['contenido'] === $antes['contenido'],
       'antes ' . count($antes['contenido']) . ' filas, ahora ' . count($desp['contenido'])
     . ' · lo que el corillo ya hizo es del dueño, no del plan');
    foreach (['objetivo', 'cantidad', 'fecha_limite', 'presupuesto_pauta', 'contexto'] as $campo) {
        ok("la meta conserva su {$campo}",
           (string)($desp['meta'][$campo] ?? '') === (string)($antes['meta'][$campo] ?? ''),
           'era ' . json_encode($antes['meta'][$campo] ?? null) . ', ahora ' . json_encode($desp['meta'][$campo] ?? null));
    }
    ok('la meta sigue activa', (string)($desp['meta']['estado'] ?? '') === 'activa',
       (string)($desp['meta']['estado'] ?? '—'));

    if ($conEstratega) {
        $viejo = $pdo->query("SELECT * FROM crecer_meta_plan WHERE id={$PLAN}")->fetch(PDO::FETCH_ASSOC) ?: [];
        ok('el plan anterior queda «reemplazado»', (string)($viejo['estado'] ?? '') === 'reemplazado',
           (string)($viejo['estado'] ?? '—') . ' · si se borrara, no se sabría nunca si sirvió');
        ok('y con la hora de su cierre', !empty($viejo['cierre_at']));
        ok('con sus números congelados', (int)($viejo['publicadas'] ?? -1) === $pubEsperadas,
           'publicadas=' . ($viejo['publicadas'] ?? '?') . ', esperaba ' . $pubEsperadas
         . ' · ese es su récord');

        $nuevo = $pdo->query("SELECT * FROM crecer_meta_plan
                               WHERE meta_id={$META} AND estado='activo'
                               ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
        ok('nace un plan activo', !empty($nuevo) && (int)$nuevo['id'] !== $PLAN, json_encode($nuevo ?: null));
        ok('con la versión siguiente', (int)($nuevo['version'] ?? 0) === $vAntes + 1,
           'version=' . ($nuevo['version'] ?? '?') . ', esperaba ' . ($vAntes + 1));
        ok('el historial de planes crece, no se sustituye', $desp['planes'] === $antes['planes'] + 1,
           'antes ' . $antes['planes'] . ', ahora ' . $desp['planes']);

        $viejasVivas = (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica
                                          WHERE meta_id={$META} AND plan_id={$PLAN}")->fetchColumn();
        ok('las jugadas viejas no se borran', $viejasVivas > 0,
           'quedan ' . $viejasVivas . ' · borrarlas dejaría el plan cerrado sin nada que enseñar');
        $nuevas = (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica
                                     WHERE meta_id={$META} AND plan_id=" . (int)$nuevo['id'])->fetchColumn();
        ok('y las nuevas cuelgan del plan nuevo', $nuevas > 0, 'hay ' . $nuevas);
    }

    //  SIN META ACTIVA no hay plan que rehacer, y se dice — no se calla.
    $pdo->prepare("UPDATE crecer_meta SET estado='cancelada' WHERE id=?")->execute([$META]);
    $r2 = $postear(['accion' => 'replan']);
    ok('sin meta activa, replan se niega con motivo',
       empty($r2['ok']) && stripos((string)($r2['err'] ?? ''), 'meta activa') !== false,
       json_encode($r2, JSON_UNESCAPED_UNICODE));
    $pdo->prepare("UPDATE crecer_meta SET estado='activa' WHERE id=?")->execute([$META]);

    // ══════════════════════════════════════════════════════════
    //  B · CAMBIAR / CERRAR META
    // ══════════════════════════════════════════════════════════
    echo "\n  — B · cerrar la meta: qué cambia y qué no —\n";
    $antes2 = $retrato();
    $r3 = $postear(['accion' => 'cerrar']);
    ok('el servidor cierra la meta', !empty($r3['ok']), json_encode($r3, JSON_UNESCAPED_UNICODE));
    $desp2 = $retrato();
    ok('la meta deja de estar activa',
       in_array((string)($desp2['meta']['estado'] ?? ''), ['cancelada','lograda','vencida','pausada'], true),
       (string)($desp2['meta']['estado'] ?? '—'));
    ok('cerrar no toca un solo post', $desp2['contenido'] === $antes2['contenido'],
       'antes ' . count($antes2['contenido']) . ', ahora ' . count($desp2['contenido']));
    ok('ni borra el historial de planes', $desp2['planes'] === $antes2['planes'],
       'antes ' . $antes2['planes'] . ', ahora ' . $desp2['planes']);
    ok('ni las jugadas', $desp2['jugadas'] === $antes2['jugadas'],
       'antes ' . $antes2['jugadas'] . ', ahora ' . $desp2['jugadas']);
    //  Y SU PLAN TAMBIEN SE CIERRA. Antes se quedaba 'activo' colgando de una
    //  meta que ya no existe: un huerfano que ensucia el historial y hace que
    //  meta_plan_activo() devuelva algo que no gobierna nada.
    ok('el plan de esa meta deja de estar activo',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_plan
                          WHERE meta_id={$META} AND estado='activo'")->fetchColumn() === 0,
       'queda un plan activo sin meta que lo mande');
    ok('y ya no hay meta activa que perseguir', meta_activa($pdo, $M) === null,
       'ese es el estado del que el dueño tiene que poder salir escogiendo otra');

    // ══════════════════════════════════════════════════════════
    //  B2 · LA TRANSACCIÓN, DEMOSTRADA CON UN FALLO DE VERDAD
    //
    //  La promesa del cambio de meta es que el negocio no se queda sin norte
    //  ni un instante. Afirmarlo leyendo el código no vale: aquí se PROVOCA el
    //  fallo —una fecha imposible hace estallar meta_crear()— y se comprueba
    //  en la base que la meta de antes sigue activa y que no nació ninguna.
    // ══════════════════════════════════════════════════════════
    echo "
  — B2 · si la creación falla, la meta de antes sigue en pie —
";
    $pdo->prepare("UPDATE crecer_meta SET estado='activa' WHERE id=?")->execute([$META]);
    $metasAntes = (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta WHERE marca_id={$M}")->fetchColumn();
    $roto = meta_cambiar_meta($pdo, $M, $META, 'cancelada', [
        'objetivo'     => 'alcance',
        'titulo'       => '[prueba] La que no debe nacer',
        'cantidad'     => '500',
        'fecha_limite' => 'no-soy-una-fecha',   // revienta dentro de meta_crear()
    ]);
    ok('el cambio se niega en vez de dejar el destrozo', $roto === 0, 'devolvio ' . $roto);
    ok('la meta de antes sigue ACTIVA',
       (string)$pdo->query("SELECT estado FROM crecer_meta WHERE id={$META}")->fetchColumn() === 'activa',
       (string)$pdo->query("SELECT estado FROM crecer_meta WHERE id={$META}")->fetchColumn()
     . ' · sin la transacción quedaría cerrada y sin sustituta: el negocio, sin meta');
    ok('y no nació ninguna meta a medias',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta WHERE marca_id={$M}")->fetchColumn() === $metasAntes,
       'habia ' . $metasAntes . ', hay '
     . $pdo->query("SELECT COUNT(*) FROM crecer_meta WHERE marca_id={$M}")->fetchColumn());
    ok('el negocio sigue teniendo norte', meta_activa($pdo, $M) !== null);

    // ══════════════════════════════════════════════════════════
    //  C · INVERSIÓN
    // ══════════════════════════════════════════════════════════
    echo "\n  — C · la inversión: solo la confirma el dueño —\n";
    $pdo->prepare("UPDATE crecer_meta SET estado='activa' WHERE id=?")->execute([$META]);
    //  H es la regla 9: cualquier cosa pendiente por delante manda sobre ella.
    //  Con la fixture tal cual salía F —una pieza esperando el OK— y la prueba
    //  medía otra pantalla. Se despeja el camino: las piezas publicadas y las
    //  demás jugadas hechas. Es una condición forzada, y se dice.
    $pdo->prepare("UPDATE crecer_contenido SET estado='publicado', publicado_at=NOW(),
                          necesita_material=NULL WHERE marca_id=?")->execute([$M]);
    $pdo->prepare("UPDATE crecer_meta_tactica SET estado='hecha' WHERE meta_id=?")->execute([$META]);
    //  Y el plan nuevo hay que darlo por presentado: sin el sello manda la
    //  pantalla del trato («¿Empezamos?»), que es otra regla anterior a H.
    $pdo->prepare("UPDATE crecer_meta_plan SET presentado_at=NOW()
                    WHERE meta_id=? AND estado='activo'")->execute([$META]);
    //  Y hace falta un plan VIVO: al cerrar la meta se cerro tambien el suyo
    //  (conducta nueva de este commit), asi que sin esto la jugada de pauta
    //  colgaria de un plan cerrado y el lector no la veria siquiera.
    $pdo->prepare("UPDATE crecer_meta_plan SET estado='activo', cierre_at=NULL, presentado_at=NOW()
                    WHERE meta_id=? ORDER BY id DESC LIMIT 1")->execute([$META]);
    //  Una jugada de pauta, del dueño, con su monto: es la que enciende el estado H.
    $act = (int)$pdo->query("SELECT id FROM crecer_meta_plan WHERE meta_id={$META} AND estado='activo'
                             ORDER BY id DESC LIMIT 1")->fetchColumn();
    $pdo->prepare("UPDATE crecer_meta_tactica
                      SET clase='accion_dueno', quien='dueno', tipo='pauta', inversion=15.00,
                          estado='pendiente', titulo=?, que_hacer=?
                    WHERE meta_id=? AND plan_id=? ORDER BY orden LIMIT 1")
        ->execute(['[prueba] Promociona el post del combo',
                   'Ponle $15 al post del combo para que llegue a tu pueblo.',
                   $META, $act ?: $PLAN]);
    $jug = (int)$pdo->query("SELECT id FROM crecer_meta_tactica
                              WHERE meta_id={$META} AND inversion IS NOT NULL
                              ORDER BY id DESC LIMIT 1")->fetchColumn();
    ok('hay una jugada de inversión que probar', $jug > 0);

    $ahora = $pedir('marca=' . $M);
    ok('la pantalla enseña el monto', strpos($ahora, '$15') !== false,
       'sin el número, «promociónalo» no dice cuánto');
    ok('y dice quién paga: el dueño',
       stripos($ahora, 'no puedo promocionarlo por ti') !== false
       || stripos($ahora, 'eso lo haces tú') !== false,
       'Crecer no entra al gestor de anuncios ni ve si el pago salió');
    //  NADA de afirmar lo que no se puede saber.
    foreach (['ya pagué', 'anuncio publicado', 'pago verificado', 'promocioné por ti'] as $mentira) {
        ok("no afirma «{$mentira}»", stripos($ahora, $mentira) === false);
    }
    ok('la confirmación es un paso aparte', strpos($ahora, 'id="ahConfirmar"') !== false);
    ok('y las instrucciones viven en una capa que se abre', strpos($ahora, 'ah-como') !== false,
       'abrirlas tiene que ser gratis: mirar no es hacer');

    //  ABRIR NO MARCA. Se comprueba donde importa: en la base.
    $estAntes = (string)$pdo->query("SELECT estado FROM crecer_meta_tactica WHERE id={$jug}")->fetchColumn();
    $pedir('marca=' . $M . '&jugada=' . $jug);
    ok('mirar las instrucciones no marca la jugada',
       (string)$pdo->query("SELECT estado FROM crecer_meta_tactica WHERE id={$jug}")->fetchColumn() === $estAntes,
       'quedó en ' . $pdo->query("SELECT estado FROM crecer_meta_tactica WHERE id={$jug}")->fetchColumn());

    //  Y SOLO LA CONFIRMACIÓN LA MARCA.
    $antes3 = $retrato();
    $r4 = $postear(['accion' => 'tactica', 'id' => $jug, 'estado' => 'hecha']);
    ok('confirmar sí la marca', !empty($r4['ok']), json_encode($r4, JSON_UNESCAPED_UNICODE));
    ok('la jugada queda hecha',
       (string)$pdo->query("SELECT estado FROM crecer_meta_tactica WHERE id={$jug}")->fetchColumn() === 'hecha');
    ok('y no toca ningún post', $retrato()['contenido'] === $antes3['contenido']);

} finally {
    @unlink($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid);
    Fixture::limpiar($pdo, $M);
    echo "\n  (fixture limpiada)\n";
}

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  PARIDAD COMPLETA · {$n} afirmaciones\n\n"
    : "  {$fallos} de {$n} · algo del contrato no se cumple hoy\n\n";
exit($fallos === 0 ? 0 : 1);
