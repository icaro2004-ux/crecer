<?php
// ============================================================
//  CRECER — LOS DOS WIZARDS DE 7a, MEDIDOS Y RECORRIDOS EN CHROME
//  tests/test_meta_7a_navegador.php
//
//  Los contratos (test_meta_ajuste_contrato.php y ..._sustitucion_...) prueban
//  QUE ESCRIBE cada capacidad. Esto prueba lo otro: que se puedan usar.
//
//  Y una cosa mas que solo se ve aqui: que el token de concurrencia VIAJE. Se
//  puede tener un bloqueo optimista perfecto en el servidor y una pantalla que
//  no lo manda — y entonces no protege a nadie. La escena `ajuste-viejo`
//  cambia la meta por detras mientras el wizard esta abierto y comprueba que
//  la pantalla lo dice sin escribir nada.
//
//  CERO PROVEEDORES DE IMAGEN. La escena que sustituye llama a la Estratega
//  (texto) para pedir la alternativa; si no hay credenciales, se dice.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/meta_negocio.php';
require_once __DIR__ . '/../includes/meta_cambio.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nLOS DOS WIZARDS DE 7a · en Chrome\n" . str_repeat('=', 58) . "\n";

if (!is_file('C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe')) {
    echo "\n  SALTADA: no hay Chrome en esta maquina\n\n"; exit(2);
}
@mkdir(__DIR__ . '/_capturas', 0775, true);

$fx = Fixture::crear($pdo, 'nav7a', true, 'admin');
$M = (int)$fx['marca_id']; $META = (int)$fx['meta_id']; $PLAN = (int)$fx['plan_id'];
$sid  = 'n7' . bin2hex(random_bytes(8));
$ruta = session_save_path() ?: sys_get_temp_dir();
file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
    'usuario_id|i:' . (int)$fx['usuario_id'] . ';');

$sonda = function (string $script, array $args, array &$crudo = null) {
    $cmd = 'node ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . $script);
    foreach ($args as $a) $cmd .= ' ' . escapeshellarg((string)$a);
    for ($k = 0; $k < 2; $k++) {
        if ($k > 0) usleep(1500000);
        $sal = []; exec($cmd . ' 2>&1', $sal);
        $crudo = $sal;
        $j = json_decode((string)end($sal), true);
        if (is_array($j) && !isset($j['error'])) return $j;
    }
    return null;
};

/** Retrato completo: si cambia, algo escribio. */
$retrato = function () use ($pdo, $M): string {
    $t = [];
    foreach (['crecer_meta', 'crecer_meta_plan', 'crecer_meta_tactica',
              'crecer_contenido', 'crecer_meta_cambio'] as $tabla) {
        $t[$tabla] = $pdo->query("SELECT * FROM {$tabla} WHERE marca_id={$M} ORDER BY id")
                         ->fetchAll(PDO::FETCH_ASSOC);
    }
    return md5(json_encode($t));
};

try {
    $pdo->prepare("UPDATE crecer_meta_plan SET presentado_at=NOW() WHERE meta_id=? AND estado='activo'")
        ->execute([$META]);
    //  La jugada imposible: un reel que pide video del dueño.
    $JUG = (int)$pdo->query("SELECT id FROM crecer_meta_tactica
                              WHERE meta_id={$META} AND plan_id={$PLAN} AND estado='pendiente'
                              ORDER BY orden LIMIT 1")->fetchColumn();
    $pdo->prepare("UPDATE crecer_meta_tactica SET clase='produccion', formato='reel',
                          piezas_meta=1, titulo=? WHERE id=?")
        ->execute(['[prueba] Reel del combo en la plaza', $JUG]);

    // ══════════════════════════════════════════════════════════
    //  1 · COMO SE VEN
    // ══════════════════════════════════════════════════════════
    echo "\n  — 1 · los dos wizards, paso a paso —\n";
    $capturas = [
        'ajustar-360-3'    => 'tumeta_ajustar-3-repaso_movil',
        'ajustar-1440-3'   => 'tumeta_ajustar-3-repaso_escritorio',
        'sustituir-360-2'  => 'tumeta_sustituir-2-alternativa_movil',
        'sustituir-1440-2' => 'tumeta_sustituir-2-alternativa_escritorio',
    ];
    foreach (['ajustar' => 3, 'sustituir' => 3] as $vista => $pasos) {
        foreach ([[360, 800], [414, 896], [1440, 900]] as [$w, $hgt]) {
            foreach (range(1, $pasos) as $p) {
                $etq = "{$vista} {$w} paso {$p}";
                $cap = $capturas["{$vista}-{$w}-{$p}"] ?? '';
                $url = $vista === 'sustituir' ? $vista . '&jugada=' . $JUG : $vista;
                $crudo = null;
                $j = $sonda('_navegador_estados.mjs', [$sid, $M, $w, $hgt, 'abrir', $cap, $url, $p], $crudo);
                if (!is_array($j)) {
                    ok("{$etq} · el navegador midio", false, implode(' | ', array_slice((array)$crudo, -2)));
                    continue;
                }
                ok("{$etq} · es el wizard que toca",
                   ($j['contenedor'] ?? '') === '.wz' && ($j['flujo'] ?? '') === $vista,
                   'contenedor=' . ($j['contenedor'] ?? '?') . ' flujo=' . ($j['flujo'] ?? '?')
                 . ' url=' . ($j['url'] ?? '?'));
                ok("{$etq} · se llego contestando", (int)($j['paso'] ?? 0) === $p,
                   'quedo en ' . ($j['paso'] ?? '?') . ' · ' . ($j['paso_et'] ?? ''));
                ok("{$etq} · ningun control bajo una capa fija", count($j['tapados']) === 0,
                   json_encode($j['tapados'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
                ok("{$etq} · ningun objetivo bajo 44x44", count($j['chicos']) === 0,
                   json_encode($j['chicos'], JSON_UNESCAPED_UNICODE));
                ok("{$etq} · ningun texto bajo 14px", count($j['bajo14']) === 0,
                   json_encode($j['bajo14'], JSON_UNESCAPED_UNICODE));
                ok("{$etq} · una sola voz grande", count($j['titulares']) === 1,
                   json_encode($j['titulares'], JSON_UNESCAPED_UNICODE));
                ok("{$etq} · sin scroll horizontal", empty($j['scroll_h']), 'doc ' . ($j['doc'] ?? '?'));
                if ($p === 1) {
                    ok("{$etq} · la salida conserva la marca",
                       strpos(implode(' ', $j['destinos'] ?? []), 'meta.php?marca=' . $M) !== false,
                       implode(' ', $j['destinos'] ?? []));
                }
            }
        }
    }

    // ══════════════════════════════════════════════════════════
    //  2 · SALIR EN CADA PASO NO ESCRIBE NADA
    // ══════════════════════════════════════════════════════════
    echo "\n  — 2 · salir en cualquier paso no muta nada —\n";
    foreach (['ajustar', 'sustituir'] as $vista) {
        foreach ([1, 2, 3] as $p) {
            $huella = $retrato();
            $crudo = null;
            $url = $vista === 'sustituir' ? $vista . '&jugada=' . $JUG : $vista;
            $j = $sonda('_navegador_wizard.mjs', [$sid, $M, 'a7-salir', 360, 800, $url, $p], $crudo);
            if (!is_array($j)) {
                ok("{$vista} · salir desde el paso {$p}", false, implode(' | ', array_slice((array)$crudo, -2)));
                continue;
            }
            ok("{$vista} · desde el paso {$p} sale de vuelta",
               strpos((string)($j['url'] ?? ''), 'meta.php?marca=' . $M) !== false
               && strpos((string)($j['url'] ?? ''), 'vista=' . $vista) === false,
               (string)($j['url'] ?? '—'));
            ok("{$vista} · y no escribio una sola fila", $retrato() === $huella,
               'la base cambio saliendo del paso ' . $p);
        }
    }

    // ══════════════════════════════════════════════════════════
    //  3 · ATRAS CONSERVA
    // ══════════════════════════════════════════════════════════
    echo "\n  — 3 · atras conserva las respuestas —\n";
    foreach (['ajustar', 'sustituir'] as $vista) {
        $huella = $retrato();
        $crudo = null;
        $url = $vista === 'sustituir' ? $vista . '&jugada=' . $JUG : $vista;
        $j = $sonda('_navegador_wizard.mjs', [$sid, $M, 'a7-atras', 360, 800, $url], $crudo);
        if (!is_array($j)) {
            ok("{$vista} · el recorrido corrio", false, implode(' | ', array_slice((array)$crudo, -2)));
            continue;
        }
        foreach (['alFinal' => 3, 'alPrincipio' => 1, 'deVuelta' => 3] as $mom => $esp) {
            $e = $j[$mom] ?? [];
            ok("{$vista} · {$mom}: paso {$esp}", (int)($e['paso'] ?? 0) === $esp, 'esta en ' . ($e['paso'] ?? '?'));
            ok("{$vista} · {$mom}: conserva la eleccion", (string)($e['eleccion'] ?? '') !== '',
               'eleccion=' . ($e['eleccion'] ?? 'nada'));
        }
        ok("{$vista} · recorrerlo entero no escribio nada", $retrato() === $huella);
    }

    // ══════════════════════════════════════════════════════════
    //  4 · EL TOKEN VIAJA DE VERDAD
    //
    //  Se cambia la meta POR DETRAS con el wizard ya abierto. Si la pantalla no
    //  mandara el token, el ajuste entraria pisando el cambio de la otra
    //  pestaña — y ese es justo el fallo que el bloqueo existe para evitar.
    // ══════════════════════════════════════════════════════════
    echo "\n  — 4 · la meta cambia mientras el dueño decide —\n";
    $crudo = null;
    $j = $sonda('_navegador_wizard.mjs', [$sid, $M, 'a7-token', 360, 800, 'ajustar'], $crudo);
    if (!is_array($j)) {
        ok('el recorrido del token corrio', false, implode(' | ', array_slice((array)$crudo, -2)));
    } else {
        ok('el wizard avisa dentro de la pantalla', !empty($j['tras']['err_visible']),
           json_encode($j['tras'] ?? null, JSON_UNESCAPED_UNICODE));
        ok('y lo cuenta como lo que es, no como un error',
           stripos((string)($j['tras']['err_titulo'] ?? ''), 'cambió mientras') !== false,
           (string)($j['tras']['err_titulo'] ?? '—')
         . ' · confundirlo con un fallo deja al dueño creyendo que se rompió algo');
        ok('cero alert()', (int)($j['tras']['alertas'] ?? -1) === 0);
    }
    ok('y la cantidad que puso la otra pestaña sigue en pie',
       (float)$pdo->query("SELECT cantidad FROM crecer_meta WHERE id={$META}")->fetchColumn() === 77.0,
       (string)$pdo->query("SELECT cantidad FROM crecer_meta WHERE id={$META}")->fetchColumn()
     . ' · si saliera otra, el ajuste habria pisado el cambio ajeno');
    ok('el intento rechazado quedo registrado',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_cambio
                          WHERE meta_id={$META} AND resultado='rechazado_concurrencia'")->fetchColumn() > 0,
       'sin eso, «¿por qué no se guardó mi cambio?» no tiene respuesta');

    // ══════════════════════════════════════════════════════════
    //  5 · UN AJUSTE DE VERDAD, DESDE LA PANTALLA
    // ══════════════════════════════════════════════════════════
    echo "\n  — 5 · ajustar de verdad —\n";
    $planAntes  = (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_plan WHERE meta_id={$META}")->fetchColumn();
    $piezasAntes = $pdo->query("SELECT id, estado FROM crecer_contenido WHERE marca_id={$M} ORDER BY id")
                       ->fetchAll(PDO::FETCH_ASSOC);
    $baseAntes  = (string)$pdo->query("SELECT base_inicial FROM crecer_meta WHERE id={$META}")->fetchColumn();
    $objAntes   = (string)$pdo->query("SELECT objetivo FROM crecer_meta WHERE id={$META}")->fetchColumn();
    $crudo = null;
    $j = $sonda('_navegador_wizard.mjs', [$sid, $M, 'a7-guardar', 360, 800, 'ajustar'], $crudo);
    if (!is_array($j)) {
        ok('el ajuste corrio', false, implode(' | ', array_slice((array)$crudo, -2)));
    } else {
        ok('el POST sale UNA vez con tres clics', (int)($j['posts'] ?? 0) === 1, 'salio ' . ($j['posts'] ?? '?'));
        ok('cero alert()', (int)($j['alertas'] ?? -1) === 0);
        ok('y vuelve al plan de la misma marca',
           strpos((string)($j['url'] ?? ''), 'meta.php?marca=' . $M) !== false, (string)($j['url'] ?? '—'));
    }
    ok('la cantidad quedo en 33',
       (float)$pdo->query("SELECT cantidad FROM crecer_meta WHERE id={$META}")->fetchColumn() === 33.0,
       (string)$pdo->query("SELECT cantidad FROM crecer_meta WHERE id={$META}")->fetchColumn());
    ok('base_inicial NO se movio',
       (string)$pdo->query("SELECT base_inicial FROM crecer_meta WHERE id={$META}")->fetchColumn() === $baseAntes,
       'era ' . $baseAntes);
    ok('el objetivo tampoco',
       (string)$pdo->query("SELECT objetivo FROM crecer_meta WHERE id={$META}")->fetchColumn() === $objAntes);
    ok('con la casilla apagada NO se rehizo el plan',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_plan WHERE meta_id={$META}")->fetchColumn() === $planAntes,
       'habia ' . $planAntes . ' · la Estratega cuesta dinero: no se llama sin que la pidan');
    ok('los posts intactos',
       $pdo->query("SELECT id, estado FROM crecer_contenido WHERE marca_id={$M} ORDER BY id")
           ->fetchAll(PDO::FETCH_ASSOC) === $piezasAntes);
    ok('y quedo su fila en el libro de cambios',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_cambio
                          WHERE meta_id={$META} AND campo='cantidad' AND resultado='aplicado'")->fetchColumn() > 0);

    // ══════════════════════════════════════════════════════════
    //  6 · SUSTITUIR DE VERDAD, DESDE LA PANTALLA
    // ══════════════════════════════════════════════════════════
    echo "\n  — 6 · sustituir de verdad —\n";
    $jugadasAntes = (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica WHERE meta_id={$META}")->fetchColumn();
    $crudo = null;
    $j = $sonda('_navegador_wizard.mjs', [$sid, $M, 'a7-guardar', 360, 800, 'sustituir&jugada=' . $JUG], $crudo);
    if (!is_array($j)) {
        ok('la sustitucion corrio', false, implode(' | ', array_slice((array)$crudo, -2)));
    } else {
        ok('el POST de sustituir sale UNA vez', (int)($j['posts'] ?? 0) === 1, 'salio ' . ($j['posts'] ?? '?'));
        ok('cero alert()', (int)($j['alertas'] ?? -1) === 0);
    }
    $o = $pdo->query("SELECT * FROM crecer_meta_tactica WHERE id={$JUG}")->fetch(PDO::FETCH_ASSOC) ?: [];
    $conEstratega = !empty($o['sustituida_at']);
    if (!$conEstratega) {
        echo "  ·    la Estratega no propuso alternativa — se comprueba que NO se rompio nada\n";
        ok('sin alternativa, la jugada sigue como estaba',
           (string)($o['estado'] ?? '') === 'pendiente' && empty($o['sustituida_at']));
        ok('y no nacio ninguna jugada suelta',
           (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica WHERE meta_id={$META}")->fetchColumn()
           === $jugadasAntes);
    } else {
        ok('la original queda descartada', (string)$o['estado'] === 'descartada', (string)$o['estado']);
        ok('NO queda hecha', (string)$o['estado'] !== 'hecha',
           'marcarla hecha inflaria el progreso con trabajo que nunca ocurrio');
        ok('con su sello y su razon', !empty($o['sustituida_at']) && (string)$o['motivo_sustitucion'] !== '',
           (string)($o['motivo_sustitucion'] ?? '—'));
        $NUEVA = (int)$o['sustituida_por_id'];
        ok('apuntando a la nueva', $NUEVA > 0);
        $nu = $pdo->query("SELECT * FROM crecer_meta_tactica WHERE id={$NUEVA}")->fetch(PDO::FETCH_ASSOC) ?: [];
        ok('la nueva nace pendiente y enlazada',
           (string)($nu['estado'] ?? '') === 'pendiente' && (int)($nu['sustituye_a_id'] ?? 0) === $JUG,
           json_encode(['estado' => $nu['estado'] ?? null, 'sustituye_a' => $nu['sustituye_a_id'] ?? null]));
        ok('y nacio UNA sola, no dos',
           (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica WHERE meta_id={$META}")->fetchColumn()
           === $jugadasAntes + 1,
           'habia ' . $jugadasAntes);

        echo "\n  — y el plan lo cuenta —\n";
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 30,
            'header' => "Cookie: PHPSESSID={$sid}\r\n"]]);
        $plan = (string)@file_get_contents('http://localhost/crecer/panel/meta.php?marca=' . $M . '&vista=plan',
                                           false, $ctx);
        ok('la sustituida se dice «Sustituida», no se esconde',
           strpos($plan, 'jg-sust') !== false,
           'una jugada apagada sin explicacion deja al dueño preguntandose que paso');
    }

} finally {
    try { $pdo->prepare("DELETE FROM crecer_meta_cambio WHERE marca_id=?")->execute([$M]); }
    catch (Throwable $e) {}
    @unlink($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid);
    Fixture::limpiar($pdo, $M);
    echo "\n  (fixture limpiada)\n";
}

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  TODO OK · {$n} afirmaciones\n\n"
    : "  {$fallos} de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
