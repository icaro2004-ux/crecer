<?php
// ============================================================
//  CRECER — EL WIZARD DE LA META, MEDIDO Y RECORRIDO EN CHROME
//  tests/test_meta_wizard_navegador.php
//
//  La paridad (test_meta_wizard_paridad.php) protege lo que el wizard RECOGE y
//  lo que ESCRIBE. Esto protege lo otro: que se pueda usar y que se comporte.
//
//  DOS MITADES.
//
//  1. COMO SE VE. Los cuatro pasos, en tres anchos, plegado y abierto: nada
//     tapado por una capa fija, nada por debajo de 14px, ningun objetivo por
//     debajo de 44x44, una sola voz grande, cero scroll horizontal. Y a los
//     pasos 2, 3 y 4 se llega CONTESTANDO —pulsando el objetivo, escribiendo
//     el numero, pulsando Siguiente—, no encendiendo clases: si el camino se
//     rompe, la sonda se queda corta y aqui se ve.
//
//  2. COMO SE COMPORTA. Las cinco promesas del contrato que no se pueden
//     comprobar mirando una captura:
//
//       · atras y adelante sin perder una sola respuesta;
//       · salir a mitad no escribe NADA en la base;
//       · tres clics seguidos crean UNA meta, no tres;
//       · si el servidor dice que no, el fallo se queda dentro de la pantalla
//         —con su boton de reintentar y con las respuestas puestas— y NO sale
//         ni un alert() del navegador;
//       · y la unica escritura de todo el wizard es la confirmacion final.
//
//  EL CANDADO DEL SERVIDOR SE PRUEBA POR LOS DOS LADOS: que bloquea el segundo
//  POST igual, y —control positivo— que despues de cerrar la meta el mismo
//  objetivo SI vuelve a crear. Un candado que bloquea siempre no es un candado,
//  es una pared.
//
//  CERO PROVEEDORES DE IMAGEN. La escena que crea de verdad llama a la
//  Estratega (texto); si no hay credenciales, la meta se escribe igual y eso es
//  lo que se asierta.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nEL WIZARD DE LA META · medido y recorrido en Chrome\n" . str_repeat('=', 58) . "\n";

if (!is_file('C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe')) {
    echo "\n  SALTADA: no hay Chrome en esta maquina\n\n"; exit(2);
}
@mkdir(__DIR__ . '/_capturas', 0775, true);

$fx = Fixture::crear($pdo, 'wznav', true, 'admin');
$M  = (int)$fx['marca_id'];
$sid  = 'wn' . bin2hex(random_bytes(8));
$ruta = session_save_path() ?: sys_get_temp_dir();
file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
    'usuario_id|i:' . (int)$fx['usuario_id'] . ';');

/** Corre una sonda y devuelve su linea de JSON, o null con la salida cruda. */
$sonda = function (string $script, array $args, array &$crudo = null) {
    $cmd = 'node ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . $script);
    foreach ($args as $a) $cmd .= ' ' . escapeshellarg((string)$a);
    //  Un reintento, y SOLO por no poder medir: con la suite entera hay varios
    //  Chrome a la vez y de vez en cuando uno no levanta su puerto. Si mide y
    //  sale mal, no se reintenta — eso seria esconder el fallo.
    for ($k = 0; $k < 2; $k++) {
        if ($k > 0) usleep(1500000);
        $sal = []; exec($cmd . ' 2>&1', $sal);
        $crudo = $sal;
        $j = json_decode((string)end($sal), true);
        if (is_array($j) && !isset($j['error'])) return $j;
    }
    return null;
};

/** El POST de crear, tal cual lo manda la pantalla. */
$postear = function (array $campos) use ($sid, $M): array {
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 30,
        'header' => "Cookie: PHPSESSID={$sid}\r\n"]]);
    $html = @file_get_contents('http://localhost/crecer/panel/meta.php?marca=' . $M, false, $ctx);
    if (!preg_match('~CSRF\s*=\s*"([a-f0-9]+)"~i', (string)$html, $m)) {
        return ['ok' => false, 'err' => 'no encontre el csrf'];
    }
    $ctx2 = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 180,
        'header' => "Cookie: PHPSESSID={$sid}\r\n"
                  . "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query(['csrf' => $m[1]] + $campos), 'ignore_errors' => true]]);
    $r = @file_get_contents('http://localhost/crecer/panel/meta.php?marca=' . $M, false, $ctx2);
    $j = json_decode((string)$r, true);
    return is_array($j) ? $j : ['ok' => false, 'err' => 'no-JSON: ' . substr((string)$r, 0, 160)];
};

$filas = function () use ($pdo, $M): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta WHERE marca_id={$M}")->fetchColumn();
};

try {
    //  El wizard solo sale SIN meta activa. La fixture trae una.
    $pdo->prepare("DELETE FROM crecer_meta WHERE marca_id=?")->execute([$M]);

    // ══════════════════════════════════════════════════════════
    //  1 · COMO SE VE — cuatro pasos, tres anchos
    // ══════════════════════════════════════════════════════════
    echo "\n  — 1 · los cuatro pasos, medidos —\n";
    $capturas = [
        '360-1'  => 'tumeta_wizard-1-objetivo_movil',
        '360-4'  => 'tumeta_wizard-4-repaso_movil',
        '1440-1' => 'tumeta_wizard-1-objetivo_escritorio',
        '1440-4' => 'tumeta_wizard-4-repaso_escritorio',
    ];
    foreach ([[360, 800], [414, 896], [1440, 900]] as [$w, $hgt]) {
        foreach ([1, 2, 3, 4] as $p) {
            $etq = "{$w} paso {$p}";
            //  El glosario se abre a proposito: lo que aparece al desplegar es
            //  justo donde se escondian los controles que caian bajo la barra.
            $cap = $capturas["{$w}-{$p}"] ?? '';
            $crudo = null;
            $j = $sonda('_navegador_estados.mjs', [$sid, $M, $w, $hgt, 'abrir', $cap, 'wizard', $p], $crudo);
            if (!is_array($j)) {
                ok("{$etq} · el navegador midio", false, implode(' | ', array_slice((array)$crudo, -2)));
                continue;
            }
            ok("{$etq} · es el wizard", ($j['contenedor'] ?? '') === '.wz',
               'contenedor=' . ($j['contenedor'] ?? '?') . ' · url=' . ($j['url'] ?? '?'));
            //  Llegar contestando: si el paso no avanzo, algo se perdio por el
            //  camino y todo lo que venga detras mide otra pantalla.
            ok("{$etq} · se llego contestando", (int)($j['paso'] ?? 0) === $p,
               'quedo en el paso ' . ($j['paso'] ?? '?') . ' · ' . ($j['paso_et'] ?? ''));
            ok("{$etq} · lo dice en cristiano", strpos((string)($j['paso_et'] ?? ''), "Paso {$p} de 4") === 0,
               (string)($j['paso_et'] ?? '—') . ' · sin eso el tren es decoracion');
            ok("{$etq} · ningun control bajo una capa fija", count($j['tapados']) === 0,
               json_encode($j['tapados'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            ok("{$etq} · ningun objetivo bajo 44x44", count($j['chicos']) === 0,
               json_encode($j['chicos'], JSON_UNESCAPED_UNICODE));
            ok("{$etq} · ningun texto bajo 14px", count($j['bajo14']) === 0,
               json_encode($j['bajo14'], JSON_UNESCAPED_UNICODE));
            ok("{$etq} · una sola voz grande", count($j['titulares']) === 1,
               json_encode($j['titulares'], JSON_UNESCAPED_UNICODE));
            ok("{$etq} · una sola accion primaria", (int)$j['primarias'] === 1, 'hay ' . $j['primarias']);
            ok("{$etq} · sin scroll horizontal", empty($j['scroll_h']), 'doc mide ' . ($j['doc'] ?? '?'));

            if ($p === 4) {
                //  EL REPASO TIENE QUE SER CONCRETO. Un resumen con guiones es
                //  pedir una decision a ciegas, que es justo lo que este paso
                //  existe para evitar.
                $r = $j['repaso'] ?? [];
                //  Tres, no seis. El presupuesto y el contexto se fueron a la
                //  capa opcional -no todo el mundo tiene que decidirlos- y
                //  «como se mide» se dice en el paso donde se escribe el
                //  numero, que es donde importa. Lo que NO puede faltar es que
                //  vea sus tres respuestas antes de crear.
                foreach (['rObj' => 'que quiere lograr', 'rCant' => 'cuanto',
                          'rFecha' => 'para cuando'] as $id => $qs) {
                    ok("{$etq} · el repaso dice {$qs}",
                       isset($r[$id]) && trim($r[$id]) !== '' && trim($r[$id]) !== '—',
                       $id . '=' . json_encode($r[$id] ?? null, JSON_UNESCAPED_UNICODE));
                }
                //  El texto largo se comprueba en el recorrido que de verdad
                //  lo escribe -el de «atrás», más abajo-: esta medida entra al
                //  paso 4 por su cuenta y no abre la capa de ajustes, así que
                //  aquí el contexto está vacío con razón.
            }
            if ($p === 1) {
                //  La salida, en la primera pantalla y sin ambiguedad sobre si
                //  lo escrito se guarda.
                $d = implode(' ', $j['destinos'] ?? []);
                ok("{$etq} · hay salida a Tu Meta conservando la marca",
                   strpos($d, 'meta.php?marca=' . $M) !== false, $d);
            }
        }
    }

    // ══════════════════════════════════════════════════════════
    //  2 · ATRAS Y ADELANTE SIN PERDER NADA
    // ══════════════════════════════════════════════════════════
    echo "\n  — 2 · volver entre pasos conservando datos —\n";
    $crudo = null;
    $j = $sonda('_navegador_wizard.mjs', [$sid, $M, 'atras', 360, 800], $crudo);
    if (!is_array($j)) {
        ok('el recorrido de atras corrio', false, implode(' | ', array_slice((array)$crudo, -2)));
    } else {
        $esperado = ['obj' => 'pedidos', 'cant' => '25', 'dias' => '60', 'pauta' => '20'];
        foreach (['enElRepaso' => 4, 'alPrincipio' => 1, 'deVuelta' => 4, 'alCambiar' => 2] as $mom => $paso) {
            $e = $j[$mom] ?? [];
            ok("{$mom} · esta en el paso {$paso}", (int)($e['paso'] ?? 0) === $paso,
               'esta en el ' . ($e['paso'] ?? '?'));
            $mal = [];
            foreach ($esperado as $k => $v) if ((string)($e[$k] ?? '') !== $v) $mal[] = "{$k}=" . ($e[$k] ?? 'nada');
            ok("{$mom} · conserva las cuatro respuestas", $mal === [], implode(' · ', $mal));
        }
        ok('y el contexto tampoco se pierde',
           strpos((string)($j['alPrincipio']['ctx'] ?? ''), 'brazo gitano') !== false,
           'ctx=' . mb_substr((string)($j['alPrincipio']['ctx'] ?? '—'), 0, 40));
        //  Y ENTERO, de principio a fin: un textarea largo escrito en la capa
        //  opcional no puede volver recortado por ningún extremo. Se comprueba
        //  con las dos puntas del texto que la sonda escribió — buscar una
        //  frase que no esté en ese texto sería una afirmación inventada.
        $ctx4 = (string)($j['enElRepaso']['ctx'] ?? '');
        ok('el texto largo se conserva entero',
           strpos($ctx4, '[prueba] Tengo el combo') === 0
           && substr($ctx4, -strlen('fiestas del pueblo.')) === 'fiestas del pueblo.',
           'quedó: «' . mb_substr($ctx4, 0, 24) . ' … ' . mb_substr($ctx4, -24) . '»');
        //  Ir hacia adelante SIN volver a contestar solo funciona si el boton
        //  Siguiente sigue habilitado, que es la prueba de que nada se borro.
        ok('el repaso se rehace con lo guardado',
           strpos((string)($j['deVuelta']['repaso']['rCant'] ?? ''), '25 pedidos') !== false,
           json_encode($j['deVuelta']['repaso'] ?? null, JSON_UNESCAPED_UNICODE));
        ok('«Cambiar» lleva al paso que decidio ese dato',
           (int)($j['alCambiar']['paso'] ?? 0) === 2, 'fue al ' . ($j['alCambiar']['paso'] ?? '?'));
    }

    // ══════════════════════════════════════════════════════════
    //  2b · LA REGLA DE AYUDA NO SE MATA SOLA
    //
    //  Salio de aqui: la captura del paso 1 enseñaba el boton de Ayuda encima
    //  de una tarjeta. No era la foto — era que la regla, al recalcularse con
    //  el boton A MEDIO APARTAR, leia su rectangulo fuera de la pantalla, lo
    //  tomaba por «no hay boton» y se iba sin montar el observador. A partir de
    //  ahi Ayuda no se apartaba de nada hasta recargar, en las TRES capas.
    //
    //  El barrido de solapes no lo pillaba: lleva cada control al centro, y en
    //  el centro no hay ningun boton flotante. Hace falta el gesto exacto.
    // ══════════════════════════════════════════════════════════
    echo "\n  — 2b · Ayuda sigue viva tras desplegar —\n";
    $crudo = null;
    $j = $sonda('_navegador_wizard.mjs', [$sid, $M, 'ayuda', 360, 800], $crudo);
    if (!is_array($j)) {
        ok('el recorrido de Ayuda corrio', false, implode(' | ', array_slice((array)$crudo, -2)));
    } else {
        foreach (['alPrincipio' => 'de entrada, Ayuda se aparta de la tarjeta',
                  'trasDesplegar' => 'sigue apartada al abrir el glosario',
                  'sigueViva' => 'y vuelve a apartarse despues — la regla no murio'] as $mom => $qs) {
            $e = $j[$mom] ?? [];
            ok($qs, !empty($e['apartado']),
               'cola=' . var_export($e['cola'] ?? null, true) . ' opacidad=' . ($e['opacidad'] ?? '?')
             . ' y=' . ($e['y'] ?? '?') . ' · con la regla muerta el boton tapa lo que haya debajo');
        }
    }

    // ══════════════════════════════════════════════════════════
    //  3 · EL SERVIDOR DICE QUE NO
    // ══════════════════════════════════════════════════════════
    echo "\n  — 3 · el fallo se queda dentro —\n";
    $antes = $filas();
    $crudo = null;
    $j = $sonda('_navegador_wizard.mjs', [$sid, $M, 'error', 360, 800], $crudo);
    if (!is_array($j)) {
        ok('el recorrido del fallo corrio', false, implode(' | ', array_slice((array)$crudo, -2)));
    } else {
        $f = $j['alFallar'] ?? [];
        ok('cero alert() del navegador', (int)($f['alertas'] ?? -1) === 0,
           'salieron ' . ($f['alertas'] ?? '?') . ' · un alert a mitad de un formulario de cuatro '
         . 'pasos se lleva la pantalla y no dice que hacer');
        ok('el fallo se ve en la propia pantalla', !empty($f['err_visible']));
        ok('con el mensaje que dio el servidor',
           strpos((string)($f['err_txt'] ?? ''), 'La Estratega no contesto') !== false,
           (string)($f['err_txt'] ?? '—'));
        ok('y con el foco encima', !empty($f['err_enfocado']),
           'quien no ve la pantalla tambien tiene que enterarse');
        ok('se queda en el repaso, no en el limbo', (int)($f['paso'] ?? 0) === 4, 'paso=' . ($f['paso'] ?? '?'));
        ok('el reloj se apaga', empty($f['cargando']));
        ok('las respuestas siguen puestas', (string)($f['cant'] ?? '') === '25', 'cant=' . ($f['cant'] ?? '—'));
        ok('hay boton para reintentar', !empty($f['hay_reintentar']),
           '«intenta otra vez» sin donde pulsar no es una salida, es una queja');
        ok('y reintentar vuelve a intentarlo de verdad', (int)($j['posts'] ?? 0) === 2,
           'POSTs de crear: ' . ($j['posts'] ?? '?'));
    }
    ok('un fallo no escribe nada', $filas() === $antes, 'habia ' . $antes . ', hay ' . $filas());

    // ══════════════════════════════════════════════════════════
    //  4 · SALIR A MITAD NO ESCRIBE NADA
    // ══════════════════════════════════════════════════════════
    echo "\n  — 4 · cancelar no guarda —\n";
    $crudo = null;
    $j = $sonda('_navegador_wizard.mjs', [$sid, $M, 'salir', 360, 800], $crudo);
    if (!is_array($j)) {
        ok('el recorrido de salir corrio', false, implode(' | ', array_slice((array)$crudo, -2)));
    } else {
        ok('sale a Tu Meta', strpos((string)($j['url'] ?? ''), 'meta.php?marca=' . $M) !== false
            && strpos((string)($j['url'] ?? ''), 'vista=wizard') === false, (string)($j['url'] ?? '—'));
        ok('habiendo contestado dos pasos', (int)($j['antes']['paso'] ?? 0) === 3,
           'estaba en el ' . ($j['antes']['paso'] ?? '?'));
    }
    ok('y la base sigue vacia', $filas() === 0, 'hay ' . $filas() . ' metas');

    // ══════════════════════════════════════════════════════════
    //  5 · TRES CLICS, UNA META
    // ══════════════════════════════════════════════════════════
    echo "\n  — 5 · doble clic sin duplicar —\n";
    $crudo = null;
    $j = $sonda('_navegador_wizard.mjs', [$sid, $M, 'doble', 360, 800], $crudo);
    if (!is_array($j)) {
        ok('el recorrido del doble clic corrio', false, implode(' | ', array_slice((array)$crudo, -2)));
    } else {
        ok('el POST de crear sale UNA vez', (int)($j['posts'] ?? 0) === 1,
           'salio ' . ($j['posts'] ?? '?') . ' veces con tres clics');
        ok('cero alert() tambien al crear', (int)($j['alertas'] ?? -1) === 0, 'salieron ' . ($j['alertas'] ?? '?'));
        ok('y al terminar vuelve a Tu Meta recalculada',
           strpos((string)($j['url'] ?? ''), 'meta.php?marca=' . $M) !== false
        && strpos((string)($j['url'] ?? ''), 'vista=wizard') === false, (string)($j['url'] ?? '—'));
    }
    ok('en la base hay UNA meta, no tres', $filas() === 1, 'hay ' . $filas());

    $q = $pdo->prepare("SELECT * FROM crecer_meta WHERE marca_id=? ORDER BY id DESC LIMIT 1");
    $q->execute([$M]); $meta = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    ok('con lo que se escogio: objetivo', (string)($meta['objetivo'] ?? '') === 'pedidos', (string)($meta['objetivo'] ?? '—'));
    ok('con lo que se escogio: cantidad', (float)($meta['cantidad'] ?? 0) === 25.0, (string)($meta['cantidad'] ?? '—'));
    ok('con lo que se escogio: inversion', (float)($meta['presupuesto_pauta'] ?? -1) === 20.0, (string)($meta['presupuesto_pauta'] ?? '—'));
    ok('con lo que se escogio: plazo de 60 dias',
       (string)($meta['fecha_limite'] ?? '') === date('Y-m-d', strtotime('+60 days')),
       (string)($meta['fecha_limite'] ?? '—') . ' · esperaba ' . date('Y-m-d', strtotime('+60 days')));
    ok('y con el contexto del dueno', strpos((string)($meta['contexto'] ?? ''), 'brazo gitano') !== false,
       mb_substr((string)($meta['contexto'] ?? '—'), 0, 40));
    ok('la meta nace activa', (string)($meta['estado'] ?? '') === 'activa', (string)($meta['estado'] ?? '—'));

    // ══════════════════════════════════════════════════════════
    //  6 · EL CANDADO DEL SERVIDOR, POR LOS DOS LADOS
    // ══════════════════════════════════════════════════════════
    echo "\n  — 6 · el candado del servidor —\n";
    $r = $postear(['accion' => 'crear', 'objetivo' => 'pedidos', 'cantidad' => '99',
                   'fecha_limite' => date('Y-m-d', strtotime('+30 days')), 'presupuesto' => '0']);
    ok('el segundo POST igual no crea otra',
       !empty($r['ok']) && !empty($r['repetido']) && $filas() === 1,
       json_encode($r, JSON_UNESCAPED_UNICODE) . ' · filas=' . $filas());
    ok('y devuelve la meta que ya existia', (int)($r['meta_id'] ?? 0) === (int)($meta['id'] ?? -1),
       'devolvio ' . ($r['meta_id'] ?? '?') . ', la buena es ' . ($meta['id'] ?? '?'));

    //  CONTROL POSITIVO. Un candado que bloquea siempre no protege: impide.
    //  Cerrada la meta, el mismo objetivo tiene que volver a crear.
    $pdo->prepare("UPDATE crecer_meta SET estado='lograda' WHERE marca_id=?")->execute([$M]);
    $r2 = $postear(['accion' => 'crear', 'objetivo' => 'pedidos', 'cantidad' => '40',
                    'fecha_limite' => date('Y-m-d', strtotime('+30 days')), 'presupuesto' => '0']);
    ok('cerrada la anterior, la proxima meta SI se crea',
       !empty($r2['ok']) && empty($r2['repetido']) && $filas() === 2,
       json_encode($r2, JSON_UNESCAPED_UNICODE) . ' · filas=' . $filas());
    $q->execute([$M]); $m2 = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    ok('y es la nueva, con su numero', (float)($m2['cantidad'] ?? 0) === 40.0, (string)($m2['cantidad'] ?? '—'));

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
