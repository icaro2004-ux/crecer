<?php
// ============================================================
//  CRECER — LA EJECUCIÓN, VISTA POR EL DUEÑO
//  tests/test_ejecucion_navegador.php
//
//  El contrato en PHP dice que las etapas y las cifras son correctas. Esto
//  dice lo otro: que el dueño LO VE, en un Android de 360, y que las tres
//  situaciones que va a vivir —hay algo que decidir, ya está programado, ya
//  salió— se distinguen de un vistazo.
//
//  Se siembran los tres momentos en la base y se mira cada uno. Cero red,
//  cero modelos: solo se cambian estados de piezas.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nLA EJECUCIÓN, EN PANTALLA\n" . str_repeat('=', 58) . "\n";

$ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true]]);
if (@file_get_contents('http://localhost/crecer/login.php', false, $ctx) === false) {
    echo "\n  SALTADO · el servidor local no responde\n\n"; exit(0);
}
$CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
if (!is_file($CHROME)) { echo "\n  SALTADO · no hay Chrome\n\n"; exit(0); }

$SHOTS = __DIR__ . '/_capturas/ejecucion';
if (!is_dir($SHOTS)) @mkdir($SHOTS, 0777, true);

$M = 0;
try {
    $fx = Fixture::crear($pdo, 'ejnav', true, 'admin');
    $M = (int)$fx['marca_id'];
    foreach (['inicio', 'meta', 'semana', 'calendario', 'resultados'] as $p) {
        try { $pdo->prepare("INSERT IGNORE INTO crecer_tour_visto (marca_id, clave, visto_at)
                              VALUES (?,?, NOW())")->execute([$M, $p]); } catch (Throwable $e) {}
    }
    $sid  = 'ejn' . bin2hex(random_bytes(7));
    file_put_contents((session_save_path() ?: sys_get_temp_dir())
                      . DIRECTORY_SEPARATOR . 'sess_' . $sid, 'usuario_id|i:' . (int)$fx['usuario_id'] . ';');

    $mirar = function (string $momento) use ($SHOTS, $sid, $M): array {
        $cmd = 'node ' . escapeshellarg(__DIR__ . '/_ejecucion_probe.mjs') . ' '
             . escapeshellarg($SHOTS) . ' ' . escapeshellarg($sid) . ' ' . $M;
        //  El momento viaja por el entorno: la sonda solo lo usa para nombrar
        //  la captura, no para decidir nada.
        //  Con comillas: `set VAR=valor && ...` en cmd se traga el espacio de
        //  antes del `&&` DENTRO de la variable, y las capturas salian con el
        //  nombre partido («meta_revisando _360.png»).
        $sal = (string)shell_exec('set "CRECER_MOMENTO=' . $momento . '" && ' . $cmd . ' 2>&1');
        $R = ['_raw' => $sal];
        foreach (explode("\n", $sal) as $l) {
            $l = trim($l); $i = strpos($l, '=');
            if ($i > 0) $R[substr($l, 0, $i)] = substr($l, $i + 1);
        }
        return $R;
    };
    //  LAS PIEZAS VAN COLGADAS DEL PLAN VIGENTE. Tu Meta cuenta SOLO las de su
    //  plan —mezclar las de planes viejos infla los números y el dueño no sabe
    //  a qué corresponden—, así que una pieza suelta no aparecería.
    $META = (int)$fx['meta_id']; $PLAN = (int)$fx['plan_id'];
    $borrar = fn() => $pdo->prepare("DELETE FROM crecer_contenido WHERE marca_id=?")->execute([$M]);
    $poner  = function (string $estado, ?string $cuando, array $x = []) use ($pdo, $M, $META, $PLAN) {
        $pdo->prepare("INSERT INTO crecer_contenido
                (marca_id, plataforma, tipo, caption, estado, fecha_programada, publicado_at,
                 meta_id, plan_id)
              VALUES (?, 'instagram','post','[prueba] El combo del sábado', ?, ?, ?, ?, ?)")
            ->execute([$M, $estado, $cuando, $x['pub'] ?? null, $META, $PLAN]);
    };
    //  Y QUIEN DECIDE LA ETAPA ES EL COMPOSITOR, no las piezas: mientras queden
    //  jugadas suyas sin hacer dirá —con razón— que le toca a él. Para mirar
    //  «programado» y «midiendo» el plan tiene que estar sin deberes pendientes,
    //  que es justo la situación que esos momentos describen.
    $sin_deberes = function () use ($pdo, $M) {
        $pdo->prepare("UPDATE crecer_meta_tactica SET estado='hecha'
                        WHERE marca_id=? AND estado IN ('pendiente','en_curso')")->execute([$M]);
    };

    // ══════════════════════════════════════════════════════════════
    //  MOMENTO 1 · HAY ALGO QUE DECIDIR
    // ══════════════════════════════════════════════════════════════
    echo "\n  — revisando: hay algo que decidir —\n";
    $borrar();
    $poner('borrador', null); $poner('borrador', null);
    $R = $mirar('revisando');
    ok('el navegador miró', ($R['OK'] ?? '0') === '1', substr((string)$R['_raw'], -400));
    if (($R['OK'] ?? '0') !== '1') throw new RuntimeException('sonda caída');
    $leer = fn(string $k) => json_decode((string)($R[$k] ?? '{}'), true) ?: [];

    $A = $leer('META_360');
    ok('el bloque de ejecución está',  !empty($A['hay']), json_encode($A));
    //  Y SIN AVISOS DE PHP. Esto no es cosmético: la primera versión de este
    //  bloque usaba una variable que no existe en esta pantalla y pintaba seis
    //  «Undefined variable» encima del título.
    ok('sin avisos de PHP encima',     trim((string)($R['AVISOS_360'] ?? '')) === '',
       (string)($R['AVISOS_360'] ?? ''));
    ok('y dice que toca revisar',      ($A['etapa'] ?? '') === 'revisando', (string)($A['etapa'] ?? ''));
    ok('con el turno del dueño',
       mb_stripos((string)($A['turno'] ?? ''), 'toca a ti') !== false, (string)($A['turno'] ?? ''));
    ok('dice cuántas hay',
       preg_match('/\d/', (string)($A['titulo'] ?? '')) === 1, (string)($A['titulo'] ?? ''));
    //  LA LÍNEA: una sola etapa encendida. Dos sería decirle que está en dos
    //  sitios a la vez.
    ok('la línea tiene cinco pasos',   count((array)($A['pasos'] ?? [])) === 5,
       (string)count((array)($A['pasos'] ?? [])));
    ok('y solo uno encendido',         (int)($A['activos'] ?? 0) === 1, (string)($A['activos'] ?? ''));
    ok('nada por debajo de 14px',      empty($A['finos']), json_encode($A['finos'] ?? []));
    ok('sin scroll horizontal',        (int)($A['horiz'] ?? 1) === 0, (string)($A['horiz'] ?? ''));
    $cif = (array)($A['cifras'] ?? []);
    ok('las cifras son enlaces tocables',
       $cif !== [] && !in_array(false, array_map(fn($c) => (int)$c['alto'] >= 44, $cif), true),
       json_encode(array_map(fn($c) => $c['n'] . ' ' . $c['et'] . ' h=' . $c['alto'], $cif)));
    ok('como mucho cuatro',            count($cif) <= 4, (string)count($cif));
    ok('y ninguna en cero',
       !in_array('0', array_column($cif, 'n'), true),
       json_encode(array_column($cif, 'n')) . ' — un cero repetido decora, no informa');

    $I = $leer('INICIO_360');
    ok('Inicio trae los mensajes del corillo', !empty($I['hay']), json_encode($I));
    ok('tres como máximo',             count((array)($I['mensajes'] ?? [])) <= 3,
       (string)count((array)($I['mensajes'] ?? [])));
    ok('y NO hay idea del día suelta', empty($I['idea']),
       'una recomendación que no conoce la Meta compite con el plan');
    $men = (array)($I['mensajes'] ?? []);
    ok('los que llevan a algo se pueden tocar',
       !in_array(false, array_map(fn($x) => $x['href'] === '' || (int)$x['alto'] >= 44, $men), true),
       json_encode(array_map(fn($x) => $x['accion'] . ' h=' . $x['alto'], $men)));

    // ══════════════════════════════════════════════════════════════
    //  MOMENTO 2 · YA ESTÁ PROGRAMADO
    // ══════════════════════════════════════════════════════════════
    echo "\n  — programado: ya no le toca a él —\n";
    $borrar(); $sin_deberes();
    $poner('programado', date('Y-m-d H:i:s', strtotime('tomorrow 10:00')));
    $R = $mirar('programada');
    $leer = fn(string $k) => json_decode((string)($R[$k] ?? '{}'), true) ?: [];
    $B = $leer('META_360');
    ok('la etapa es programado',       ($B['etapa'] ?? '') === 'programado', (string)($B['etapa'] ?? ''));
    ok('y el turno es del corillo',
       mb_stripos((string)($B['turno'] ?? ''), 'corillo') !== false, (string)($B['turno'] ?? ''));
    ok('se ve la próxima que sale',    !empty($B['prox']), json_encode($B['prox'] ?? null));
    ok('con su día y su hora',
       str_contains((string)($B['prox']['txt'] ?? ''), '10:00 AM')
       && mb_stripos((string)($B['prox']['txt'] ?? ''), 'mañana') !== false,
       (string)($B['prox']['txt'] ?? ''));
    ok('y lleva al Calendario',
       str_contains((string)($B['prox']['href'] ?? ''), 'calendario.php'),
       (string)($B['prox']['href'] ?? ''));

    // ══════════════════════════════════════════════════════════════
    //  MOMENTO 3 · YA SALIÓ
    // ══════════════════════════════════════════════════════════════
    echo "\n  — midiendo: ya salió —\n";
    $borrar(); $sin_deberes();
    $poner('publicado', date('Y-m-d H:i:s', strtotime('-2 days 10:00')),
           ['pub' => date('Y-m-d H:i:s', strtotime('-2 days 10:02'))]);
    $R = $mirar('midiendo');
    $leer = fn(string $k) => json_decode((string)($R[$k] ?? '{}'), true) ?: [];
    $C = $leer('META_360');
    ok('la etapa habla de resultados',
       in_array((string)($C['etapa'] ?? ''), ['midiendo', 'programado', 'revisando'], true),
       (string)($C['etapa'] ?? ''));
    //  LO QUE NO PUEDE DECIR: que ya funcionó. Publicar no es vender.
    $todo = mb_strtolower(($C['titulo'] ?? '') . ' ' . ($C['sub'] ?? ''));
    foreach (['funcionó', 'está funcionando', 'vas bien'] as $juicio) {
        ok("no dice «{$juicio}»", !str_contains($todo, $juicio), $todo);
    }

    echo "\n  — la pantalla no grita —\n";
    ok('cero errores de consola',
       in_array(trim((string)($R['ERRORES'] ?? '[]')), ['[]', ''], true), (string)($R['ERRORES'] ?? ''));
    echo "\n  capturas en tests/_capturas/ejecucion/*.png\n";

} catch (Throwable $e) {
    $fallos++; $n++;
    echo "\n  EXCEPCION · " . get_class($e) . ': ' . $e->getMessage() . "\n";
} finally {
    if ($M > 0) { try { Fixture::limpiar($pdo, $M); echo "\n  (fixture limpiada)\n"; }
                  catch (Throwable $e) {} }
}

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0 ? "  LA EJECUCIÓN, EN PANTALLA · {$n} afirmaciones\n\n"
                   : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
