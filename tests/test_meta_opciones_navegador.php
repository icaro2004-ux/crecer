<?php
// ============================================================
//  CRECER — LAS DOS DELICADAS, MEDIDAS Y RECORRIDAS EN CHROME
//  tests/test_meta_opciones_navegador.php
//
//  test_meta_opciones_paridad.php protege QUÉ FILAS CAMBIAN. Esto protege lo
//  otro: que los dos wizards se puedan usar y que se porten.
//
//  Lo que se exige, y por qué cada cosa:
//
//    · SALIR EN CUALQUIER PASO NO MUTA NADA. Se sale desde el 1, el 2, el 3 y
//      el último, y después se compara un retrato completo de la base. Es la
//      promesa central: mirar tiene que ser gratis.
//    · ATRÁS CONSERVA. Si volver pierde el motivo o la meta escogida, el dueño
//      no puede corregirse sin empezar de cero.
//    · UNA SOLA CONFIRMACIÓN, Y EL DOBLE CLIC NO REPITE. Tres clics tienen que
//      dejar UN plan nuevo, no tres — cada uno cuesta una llamada a la
//      Estratega y ensucia el historial.
//    · EL FALLO ES RECUPERABLE Y SE QUEDA DENTRO. Sin alert(), con el botón de
//      reintentar y sin perder una respuesta.
//    · PERTENENCIA Y VIGENCIA. Un id de otra marca no cambia nada. Un plan que
//      ya no es el vigente contesta «repetido» y no vuelve a gastar.
//    · AL CAMBIAR, LA NUEVA QUEDA ACTIVA Y EL NEGOCIO NUNCA SE QUEDA SIN META.
//    · LA INVERSIÓN SIGUE PENDIENTE hasta la confirmación de verdad.
//
//  CERO PROVEEDORES DE IMAGEN. Las escenas que escriben llaman a la Estratega
//  (texto); si no hay credenciales se dice y se comprueba lo que no depende.
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

echo "\nLAS DOS DELICADAS · medidas y recorridas en Chrome\n" . str_repeat('=', 58) . "\n";

if (!is_file('C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe')) {
    echo "\n  SALTADA: no hay Chrome en esta maquina\n\n"; exit(2);
}
@mkdir(__DIR__ . '/_capturas', 0775, true);

$fx = Fixture::crear($pdo, 'opcnav', true, 'admin');
$M = (int)$fx['marca_id']; $META = (int)$fx['meta_id']; $PLAN = (int)$fx['plan_id'];
$sid  = 'on' . bin2hex(random_bytes(8));
$ruta = session_save_path() ?: sys_get_temp_dir();
file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
    'usuario_id|i:' . (int)$fx['usuario_id'] . ';');

$sonda = function (string $script, array $args, array $env = [], array &$crudo = null) {
    $pre = '';
    foreach ($env as $k => $v) $pre .= "set {$k}={$v}&& ";
    $cmd = $pre . 'node ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . $script);
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

$postear = function (array $campos) use ($sid, $M): array {
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 30,
        'header' => "Cookie: PHPSESSID={$sid}\r\n"]]);
    $html = @file_get_contents('http://localhost/crecer/panel/meta.php?marca=' . $M, false, $ctx);
    if (!preg_match('~CSRF\s*=\s*"([a-f0-9]+)"~i', (string)$html, $m)) return ['ok'=>false,'err'=>'sin csrf'];
    $ctx2 = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 240,
        'header' => "Cookie: PHPSESSID={$sid}\r\n"
                  . "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query(['csrf' => $m[1]] + $campos), 'ignore_errors' => true]]);
    $r = @file_get_contents('http://localhost/crecer/panel/meta.php?marca=' . $M, false, $ctx2);
    $j = json_decode((string)$r, true);
    return is_array($j) ? $j : ['ok'=>false,'err'=>'no-JSON: ' . substr((string)$r, 0, 200)];
};

/**  Retrato COMPLETO: si sale distinto es que algo escribio, aunque sea una
 *   columna. Es la unica forma honesta de afirmar «no muto nada». */
$retrato = function () use ($pdo, $M): string {
    $t = [];
    foreach (['crecer_meta' => 'marca_id', 'crecer_meta_plan' => 'marca_id',
              'crecer_meta_tactica' => 'marca_id', 'crecer_contenido' => 'marca_id'] as $tabla => $col) {
        $q = $pdo->query("SELECT * FROM {$tabla} WHERE {$col}={$M} ORDER BY id");
        $t[$tabla] = $q->fetchAll(PDO::FETCH_ASSOC);
    }
    return md5(json_encode($t));
};

try {
    $pdo->prepare("UPDATE crecer_meta_plan SET presentado_at=NOW() WHERE meta_id=? AND estado='activo'")
        ->execute([$META]);

    // ══════════════════════════════════════════════════════════
    //  1 · CÓMO SE VEN — los dos wizards, paso a paso
    // ══════════════════════════════════════════════════════════
    echo "\n  — 1 · los dos wizards, medidos —\n";
    $capturas = [
        'plan-nuevo-360-2'  => 'tumeta_plannuevo-2-quepasa_movil',
        'plan-nuevo-1440-2' => 'tumeta_plannuevo-2-quepasa_escritorio',
        'cambiar-360-4'     => 'tumeta_cambiar-4-repaso_movil',
        'cambiar-1440-4'    => 'tumeta_cambiar-4-repaso_escritorio',
    ];
    foreach (['plan-nuevo' => 3, 'cambiar' => 4] as $vista => $pasos) {
        foreach ([[360, 800], [414, 896], [1440, 900]] as [$w, $hgt]) {
            foreach (range(1, $pasos) as $p) {
                $etq = "{$vista} {$w} paso {$p}";
                $cap = $capturas["{$vista}-{$w}-{$p}"] ?? '';
                $crudo = null;
                $j = $sonda('_navegador_estados.mjs', [$sid, $M, $w, $hgt, 'abrir', $cap, $vista, $p], [], $crudo);
                if (!is_array($j)) {
                    ok("{$etq} · el navegador midio", false, implode(' | ', array_slice((array)$crudo, -2)));
                    continue;
                }
                ok("{$etq} · es el wizard que toca", ($j['contenedor'] ?? '') === '.wz'
                    && ($j['flujo'] ?? '') === $vista,
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
                    //  MISMA MARCA. Salir del wizard tiene que devolver al mismo
                    //  negocio: con varias marcas, perder el parametro te deja
                    //  mirando el plan de otro.
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
    foreach (['plan-nuevo' => 3, 'cambiar' => 4] as $vista => $pasos) {
        foreach (range(1, $pasos) as $p) {
            $huella = $retrato();
            $crudo = null;
            $j = $sonda('_navegador_wizard.mjs', [$sid, $M, 'op-salir', 360, 800, $vista],
                        ['WZ_PASO' => $p], $crudo);
            if (!is_array($j)) {
                ok("{$vista} · salir desde el paso {$p}", false, implode(' | ', array_slice((array)$crudo, -2)));
                continue;
            }
            ok("{$vista} · desde el paso {$p} sale al plan",
               strpos((string)($j['url'] ?? ''), 'vista=plan') !== false
               && strpos((string)($j['url'] ?? ''), 'plan-nuevo') === false,
               (string)($j['url'] ?? '—'));
            ok("{$vista} · y no escribio una sola fila", $retrato() === $huella,
               'la base cambio saliendo del paso ' . $p . ' · eso es exactamente lo que no puede pasar');
        }
    }

    // ══════════════════════════════════════════════════════════
    //  3 · ATRÁS CONSERVA LAS RESPUESTAS
    // ══════════════════════════════════════════════════════════
    echo "\n  — 3 · atras y adelante sin perder nada —\n";
    foreach (['plan-nuevo' => 3, 'cambiar' => 4] as $vista => $pasos) {
        $huella = $retrato();
        $crudo = null;
        $j = $sonda('_navegador_wizard.mjs', [$sid, $M, 'op-atras', 360, 800, $vista], [], $crudo);
        if (!is_array($j)) {
            ok("{$vista} · el recorrido de atras corrio", false, implode(' | ', array_slice((array)$crudo, -2)));
            continue;
        }
        foreach (['alFinal' => $pasos, 'alPrincipio' => 1, 'deVuelta' => $pasos, 'alCambiar' => 1] as $mom => $esp) {
            $e = $j[$mom] ?? [];
            ok("{$vista} · {$mom}: paso {$esp}", (int)($e['paso'] ?? 0) === $esp, 'esta en ' . ($e['paso'] ?? '?'));
            ok("{$vista} · {$mom}: conserva el motivo", (string)($e['motivo'] ?? '') !== '',
               'motivo=' . ($e['motivo'] ?? 'nada') . ' · sin el, el plan nuevo vuelve a ser una tirada de dados');
            //  La sonda devuelve los primeros 40 caracteres, asi que se busca
            //  el principio de la frase — pedir el final era exigirle a la
            //  sonda algo que ella misma corta.
            ok("{$vista} · {$mom}: conserva lo que escribio",
               strpos((string)($e['detalle'] ?? ''), 'Los posts salen bonitos') !== false,
               'detalle=' . ($e['detalle'] ?? '—'));
        }
        if ($vista === 'cambiar') {
            ok('cambiar · al volver sigue escogida la meta nueva',
               (string)($j['alPrincipio']['obj'] ?? '') !== '', 'obj=' . ($j['alPrincipio']['obj'] ?? 'nada'));
            ok('cambiar · y el numero tambien',
               (string)($j['deVuelta']['cant'] ?? '') === '30', 'cant=' . ($j['deVuelta']['cant'] ?? '—'));
            ok('cambiar · «Solo cerrar por ahora» esta a la vista y aparte',
               !empty($j['alFinal']['hay_solo_cerrar']),
               'sin el, para cerrar una meta que sobra habria que inventarse otra');
        }
        ok("{$vista} · recorrerlo entero no escribio nada", $retrato() === $huella);
    }

    // ══════════════════════════════════════════════════════════
    //  4 · EL SERVIDOR DICE QUE NO
    // ══════════════════════════════════════════════════════════
    echo "\n  — 4 · el fallo se queda dentro y se recupera —\n";
    foreach (['plan-nuevo', 'cambiar'] as $vista) {
        $huella = $retrato();
        $crudo = null;
        $j = $sonda('_navegador_wizard.mjs', [$sid, $M, 'op-error', 360, 800, $vista], [], $crudo);
        if (!is_array($j)) {
            ok("{$vista} · el recorrido del fallo corrio", false, implode(' | ', array_slice((array)$crudo, -2)));
            continue;
        }
        $f = $j['alFallar'] ?? [];
        ok("{$vista} · cero alert() del navegador", (int)($f['alertas'] ?? -1) === 0, 'salieron ' . ($f['alertas'] ?? '?'));
        ok("{$vista} · el fallo se ve en la pantalla", !empty($f['err_visible']));
        ok("{$vista} · con el mensaje del servidor",
           strpos((string)($f['err_txt'] ?? ''), 'Nada cambio') !== false, (string)($f['err_txt'] ?? '—'));
        ok("{$vista} · y con el foco encima", !empty($f['err_enfocado']));
        ok("{$vista} · sin perder el motivo", (string)($f['motivo'] ?? '') !== '', 'motivo=' . ($f['motivo'] ?? '—'));
        ok("{$vista} · reintentar vuelve a intentarlo", (int)($j['posts'] ?? 0) === 2,
           'POSTs: ' . ($j['posts'] ?? '?'));
        ok("{$vista} · y un fallo no escribe nada", $retrato() === $huella);
    }

    // ══════════════════════════════════════════════════════════
    //  5 · PERTENENCIA Y VIGENCIA
    // ══════════════════════════════════════════════════════════
    echo "\n  — 5 · pertenencia y plan vigente —\n";
    $otra = Fixture::crear($pdo, 'opcajena', true, 'admin');
    $huella = $retrato();
    $r = $postear(['accion' => 'cambiar', 'meta_actual' => (int)$otra['meta_id'],
                   'objetivo' => 'alcance', 'cantidad' => '500',
                   'fecha_limite' => date('Y-m-d', strtotime('+30 days')), 'presupuesto' => '0']);
    ok('con la meta de otra marca no cambia nada',
       !empty($r['repetido']) && $retrato() === $huella,
       json_encode($r, JSON_UNESCAPED_UNICODE) . ' · un id de fuera no puede mover mi negocio');
    ok('y la meta de la otra marca sigue en pie',
       (string)$pdo->query("SELECT estado FROM crecer_meta WHERE id=" . (int)$otra['meta_id'])->fetchColumn() === 'activa');

    $huella = $retrato();
    $r2 = $postear(['accion' => 'replan', 'plan_actual' => 999999999]);
    ok('con un plan que no es el vigente, no se rehace nada',
       !empty($r2['repetido']) && $retrato() === $huella,
       json_encode($r2, JSON_UNESCAPED_UNICODE) . ' · asi es como el segundo clic no paga otra Estratega');
    Fixture::limpiar($pdo, (int)$otra['marca_id']);

    // ══════════════════════════════════════════════════════════
    //  6 · TRES CLICS, UN PLAN NUEVO
    // ══════════════════════════════════════════════════════════
    echo "\n  — 6 · plan nuevo: doble clic y historial —\n";
    $planesAntes = (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_plan WHERE meta_id={$META}")->fetchColumn();
    $postsAntes  = $pdo->query("SELECT id, estado FROM crecer_contenido WHERE marca_id={$M} ORDER BY id")
                       ->fetchAll(PDO::FETCH_ASSOC);
    $metaAntes   = $pdo->query("SELECT objetivo, cantidad, fecha_limite, estado FROM crecer_meta WHERE id={$META}")
                       ->fetch(PDO::FETCH_ASSOC);
    $crudo = null;
    $j = $sonda('_navegador_wizard.mjs', [$sid, $M, 'op-doble', 360, 800, 'plan-nuevo'], [], $crudo);
    if (!is_array($j)) {
        ok('el recorrido del doble clic corrio', false, implode(' | ', array_slice((array)$crudo, -2)));
    } else {
        ok('el POST sale UNA vez con tres clics', (int)($j['posts'] ?? 0) === 1, 'salio ' . ($j['posts'] ?? '?'));
        ok('cero alert()', (int)($j['alertas'] ?? -1) === 0, 'salieron ' . ($j['alertas'] ?? '?'));
        ok('y termina en el plan de la misma marca',
           strpos((string)($j['url'] ?? ''), 'meta.php?marca=' . $M) !== false, (string)($j['url'] ?? '—'));
    }
    $planesDesp = (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_plan WHERE meta_id={$META}")->fetchColumn();
    ok('nace UN plan, no tres', $planesDesp === $planesAntes + 1,
       'habia ' . $planesAntes . ', hay ' . $planesDesp);
    ok('el historial de posts queda intacto',
       $pdo->query("SELECT id, estado FROM crecer_contenido WHERE marca_id={$M} ORDER BY id")
           ->fetchAll(PDO::FETCH_ASSOC) === $postsAntes);
    ok('y la meta no se toca',
       $pdo->query("SELECT objetivo, cantidad, fecha_limite, estado FROM crecer_meta WHERE id={$META}")
           ->fetch(PDO::FETCH_ASSOC) === $metaAntes,
       'un plan nuevo es para la MISMA meta');
    ok('el plan viejo pasa a historial, no se borra',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_plan
                          WHERE meta_id={$META} AND estado='reemplazado'")->fetchColumn() >= 1);

    // ══════════════════════════════════════════════════════════
    //  7 · CAMBIAR DE META — la nueva queda activa, sin hueco
    // ══════════════════════════════════════════════════════════
    echo "\n  — 7 · cambiar de meta —\n";
    $pdo->prepare("UPDATE crecer_meta_plan SET presentado_at=NOW() WHERE meta_id=? AND estado='activo'")
        ->execute([$META]);
    $postsAntes2 = $pdo->query("SELECT id, estado FROM crecer_contenido WHERE marca_id={$M} ORDER BY id")
                       ->fetchAll(PDO::FETCH_ASSOC);
    $crudo = null;
    $j = $sonda('_navegador_wizard.mjs', [$sid, $M, 'op-doble', 360, 800, 'cambiar'], [], $crudo);
    if (!is_array($j)) {
        ok('el cambio de meta corrio', false, implode(' | ', array_slice((array)$crudo, -2)));
    } else {
        ok('el POST de cambiar sale UNA vez', (int)($j['posts'] ?? 0) === 1, 'salio ' . ($j['posts'] ?? '?'));
        ok('cero alert() al cambiar', (int)($j['alertas'] ?? -1) === 0);
    }
    $act = meta_activa($pdo, $M);
    ok('hay meta activa — el negocio NUNCA se queda sin norte', $act !== null,
       'ese es el hueco que dejaba el camino viejo: cerrar primero y preguntar despues');
    ok('y es la nueva, no la de antes', $act && (int)$act['id'] !== $META,
       'activa=' . ($act['id'] ?? '?') . ' · la de antes era ' . $META);
    ok('la anterior queda cerrada',
       in_array((string)$pdo->query("SELECT estado FROM crecer_meta WHERE id={$META}")->fetchColumn(),
                ['cancelada','lograda'], true),
       (string)$pdo->query("SELECT estado FROM crecer_meta WHERE id={$META}")->fetchColumn());
    ok('no quedan dos metas activas a la vez',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta WHERE marca_id={$M} AND estado='activa'")->fetchColumn() === 1);
    ok('y los posts de la meta vieja siguen ahi',
       $pdo->query("SELECT id, estado FROM crecer_contenido WHERE marca_id={$M} ORDER BY id")
           ->fetchAll(PDO::FETCH_ASSOC) === $postsAntes2,
       'lo que el corillo hizo es del dueño, no de la meta');
    ok('el plan de la meta vieja deja de estar activo',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_plan
                          WHERE meta_id={$META} AND estado='activo'")->fetchColumn() === 0,
       'un plan activo colgando de una meta cerrada es un huerfano');

    // ══════════════════════════════════════════════════════════
    //  8 · «SOLO CERRAR POR AHORA»
    // ══════════════════════════════════════════════════════════
    echo "\n  — 8 · solo cerrar por ahora —\n";
    $metasAntes = (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta WHERE marca_id={$M}")->fetchColumn();
    $crudo = null;
    $j = $sonda('_navegador_wizard.mjs', [$sid, $M, 'op-solo', 360, 800, 'cambiar'], [], $crudo);
    if (!is_array($j)) {
        ok('el recorrido de solo-cerrar corrio', false, implode(' | ', array_slice((array)$crudo, -2)));
    } else {
        ok('vuelve a Tu Meta', strpos((string)($j['url'] ?? ''), 'meta.php?marca=' . $M) !== false,
           (string)($j['url'] ?? '—'));
    }
    ok('cierra la meta', meta_activa($pdo, $M) === null,
       'esa es la diferencia con cambiar: aqui no se escoge otra');
    ok('y NO crea una meta nueva',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta WHERE marca_id={$M}")->fetchColumn() === $metasAntes,
       'si creara una, «solo cerrar» no seria solo cerrar');

    // ══════════════════════════════════════════════════════════
    //  9 · LA INVERSIÓN SIGUE PENDIENTE HASTA LA CONFIRMACIÓN
    // ══════════════════════════════════════════════════════════
    echo "\n  — 9 · la inversion no se marca sola —\n";
    $mid = (int)$pdo->query("SELECT id FROM crecer_meta WHERE marca_id={$M} ORDER BY id DESC LIMIT 1")->fetchColumn();
    $pdo->prepare("UPDATE crecer_meta SET estado='activa' WHERE id=?")->execute([$mid]);
    $pdo->prepare("UPDATE crecer_meta_plan SET estado='activo', cierre_at=NULL, presentado_at=NOW()
                    WHERE meta_id=? ORDER BY id DESC LIMIT 1")->execute([$mid]);
    $pdo->prepare("UPDATE crecer_contenido SET estado='publicado', publicado_at=NOW(),
                          necesita_material=NULL WHERE marca_id=?")->execute([$M]);
    $pdo->prepare("UPDATE crecer_meta_tactica SET estado='hecha' WHERE meta_id=?")->execute([$mid]);
    $pa = (int)$pdo->query("SELECT id FROM crecer_meta_plan WHERE meta_id={$mid} AND estado='activo'
                             ORDER BY id DESC LIMIT 1")->fetchColumn();
    //  SE COGE LA FILA POR SU ID, no por «la que tenga inversion». Buscandola
    //  asi, si el UPDATE no encontraba ninguna la prueba se quedaba con una
    //  jugada YA HECHA y acusaba a la pantalla de marcarla sola.
    $jug = (int)$pdo->query("SELECT id FROM crecer_meta_tactica
                              WHERE meta_id={$mid} AND plan_id={$pa} ORDER BY orden LIMIT 1")->fetchColumn();
    ok('hay una jugada del plan vivo que convertir en inversion', $jug > 0,
       'plan activo=' . $pa . ' · sin jugada no hay nada que medir');
    $pdo->prepare("UPDATE crecer_meta_tactica SET clase='accion_dueno', quien='dueno', tipo='pauta',
                          inversion=15.00, estado='pendiente' WHERE id=?")->execute([$jug]);
    ok('y queda pendiente antes de mirar nada',
       (string)$pdo->query("SELECT estado FROM crecer_meta_tactica WHERE id={$jug}")->fetchColumn() === 'pendiente');

    $crudo = null;
    $j = $sonda('_navegador_estados.mjs', [$sid, $M, 360, 800, 'abrir', 'tumeta_inversion_movil', '', 1], [], $crudo);
    if (!is_array($j)) {
        ok('la pantalla de inversion se midio', false, implode(' | ', array_slice((array)$crudo, -2)));
    } else {
        ok('inversion · ningun control tapado', count($j['tapados']) === 0,
           json_encode($j['tapados'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        ok('inversion · ningun texto bajo 14px', count($j['bajo14']) === 0,
           json_encode($j['bajo14'], JSON_UNESCAPED_UNICODE));
        ok('inversion · ningun objetivo bajo 44x44', count($j['chicos']) === 0,
           json_encode($j['chicos'], JSON_UNESCAPED_UNICODE));
    }
    ok('abrir las instrucciones y mirar NO la marca',
       (string)$pdo->query("SELECT estado FROM crecer_meta_tactica WHERE id={$jug}")->fetchColumn() === 'pendiente',
       'quedo en ' . $pdo->query("SELECT estado FROM crecer_meta_tactica WHERE id={$jug}")->fetchColumn()
     . ' · la sonda abre todos los <details> de la pagina, que es lo que haria quien va a leer');
    $r = $postear(['accion' => 'tactica', 'id' => $jug, 'estado' => 'hecha']);
    ok('y solo la confirmacion la marca', !empty($r['ok'])
        && (string)$pdo->query("SELECT estado FROM crecer_meta_tactica WHERE id={$jug}")->fetchColumn() === 'hecha',
       json_encode($r, JSON_UNESCAPED_UNICODE));

} finally {
    @unlink($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid);
    Fixture::limpiar($pdo, $M);
    echo "\n  (fixture limpiada)\n";
}

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  TODO OK · {$n} afirmaciones\n\n"
    : "  {$fallos} de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
