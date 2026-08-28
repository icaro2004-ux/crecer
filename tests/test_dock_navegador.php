<?php
// ============================================================
//  CRECER — EL DOCK: EL ACTIVO, EN EL CENTRO
//  tests/test_dock_navegador.php
//
//  LO QUE SE ARREGLÓ. La barra tenía cuatro columnas rígidas con Tu Meta
//  flotando encima como un botón aparte. El problema de fondo no era que se
//  viera pegado: era que el trato especial pertenecía a UNA pestaña, así que
//  en Calendario o en Resultados el dueño no tenía de un vistazo dónde estaba
//  — lo único que gritaba era una pestaña donde NO estaba.
//
//  LO QUE SE PRUEBA, y por qué cada cosa:
//    · el activo está en el CENTRO de verdad (se mide la desviación en px);
//    · cualquiera de los cuatro recibe el mismo trato al estar activo;
//    · solo uno dice `aria-current`, y es el de la ruta que se está mirando;
//    · nadie se solapa con nadie, ni el rótulo se corta a 360;
//    · Ayuda ya no cae encima de la barra;
//    · la altura de la página no cambia al navegar (nada salta);
//    · sin JavaScript los enlaces siguen navegando.
//
//  CERO GASTO: solo se abren páginas.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nEL DOCK · EL ACTIVO EN EL CENTRO\n" . str_repeat('=', 58) . "\n";

$ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true]]);
if (@file_get_contents('http://localhost/crecer/login.php', false, $ctx) === false) {
    echo "\n  SALTADO · el servidor local no responde\n\n"; exit(0);
}
$CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
if (!is_file($CHROME)) { echo "\n  SALTADO · no hay Chrome\n\n"; exit(0); }

$SHOTS = __DIR__ . '/_capturas/dock';
if (!is_dir($SHOTS)) @mkdir($SHOTS, 0777, true);

$RUTAS = ['inicio' => 'index.php', 'calendario' => 'calendario.php',
          'meta' => 'meta.php', 'resultados' => 'resultados.php'];
$M = 0;

try {
    $fx = Fixture::crear($pdo, 'dock', true, 'admin');
    $M = (int)$fx['marca_id'];
    foreach (array_keys($RUTAS) as $pantalla) {
        try {
            $pdo->prepare("INSERT IGNORE INTO crecer_tour_visto (marca_id, clave, visto_at)
                            VALUES (?,?, NOW())")->execute([$M, $pantalla]);
        } catch (Throwable $e) {}
    }
    $sid  = 'dk' . bin2hex(random_bytes(7));
    $ruta = session_save_path() ?: sys_get_temp_dir();
    file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
                      'usuario_id|i:' . (int)$fx['usuario_id'] . ';');

    $correr = function (string $modo) use ($SHOTS, $sid, $M): array {
        $cmd = 'node ' . escapeshellarg(__DIR__ . '/_dock_probe.mjs') . ' '
             . escapeshellarg($SHOTS) . ' ' . escapeshellarg($sid) . ' ' . $M . ' ' . $modo;
        $sal = (string)shell_exec($cmd . ' 2>&1');
        $R = ['_raw' => $sal];
        foreach (explode("\n", $sal) as $l) {
            $l = trim($l); $i = strpos($l, '=');
            if ($i > 0) $R[substr($l, 0, $i)] = substr($l, $i + 1);
        }
        return $R;
    };

    // ══════════════════════════════════════════════════════════════
    //  EL RECORRIDO
    // ══════════════════════════════════════════════════════════════
    $R = $correr('recorrido');
    ok('el navegador hizo el recorrido', ($R['OK'] ?? '0') === '1',
       substr((string)$R['_raw'], -500));
    if (($R['OK'] ?? '0') !== '1') throw new RuntimeException('sonda caída');
    $leer = fn(string $k) => json_decode((string)($R[$k] ?? '{}'), true) ?: [];

    foreach (['360', '414'] as $w) {
        echo "\n  — a {$w}px —\n";
        foreach (array_keys($RUTAS) as $k) {
            $m = $leer("M_{$w}_{$k}");
            if (empty($m['visible'])) { ok("@{$w} {$k} · el dock se ve", false, json_encode($m)); continue; }

            //  EL CENTRO. Dos píxeles de tolerancia: el redondeo del navegador,
            //  no la geometría.
            ok("@{$w} {$k} · el activo está en el centro",
               (float)($m['desvio'] ?? 999) <= 2.0,
               'se desvía ' . round((float)($m['desvio'] ?? -1), 1) . 'px del centro');
            ok("@{$w} {$k} · y es el de esta ruta",
               (string)($m['activoK'] ?? '') === $k, (string)($m['activoK'] ?? ''));
            ok("@{$w} {$k} · uno solo activo",
               (int)($m['activos'] ?? 0) === 1, (string)($m['activos'] ?? ''));
            ok("@{$w} {$k} · y un solo aria-current",
               (int)($m['current'] ?? 0) === 1, (string)($m['current'] ?? ''));
            ok("@{$w} {$k} · nadie se solapa",
               empty($m['solapes']), json_encode($m['solapes'] ?? []));

            $items = (array)($m['items'] ?? []);
            ok("@{$w} {$k} · los cuatro destinos", count($items) === 4, (string)count($items));
            $cort = array_values(array_filter($items, fn($i) => !empty($i['cortado'])));
            ok("@{$w} {$k} · ni un rótulo cortado", $cort === [],
               json_encode(array_column($cort, 'etiqueta')));
            $bajos = array_values(array_filter($items, fn($i) => (float)$i['h'] < 44));
            ok("@{$w} {$k} · todo se toca (44px)", $bajos === [],
               json_encode(array_map(fn($i) => $i['k'] . ' h=' . round($i['h']), $bajos)));
            ok("@{$w} {$k} · todos conservan la marca",
               !in_array(false, array_map(fn($i) => str_contains((string)$i['href'], 'marca=' . $M),
                                          $items), true),
               json_encode(array_column($items, 'href')));

            //  LA PROMINENCIA ES DEL ACTIVO, SEA CUAL SEA. Su burbuja tiene que
            //  ser mayor que la de cualquier inactivo — si no, el trato especial
            //  seguiría siendo de una pestaña concreta.
            $act = null; $otros = [];
            foreach ($items as $i) { if (!empty($i['act'])) $act = $i; else $otros[] = $i; }
            ok("@{$w} {$k} · el activo destaca sobre los demás",
               $act && (int)$act['burbuja'] >= 46
               && (int)$act['burbuja'] > max(array_column($otros, 'burbuja')),
               json_encode(['act' => $act['burbuja'] ?? null,
                            'otros' => array_column($otros, 'burbuja')]));

            ok("@{$w} {$k} · sin scroll horizontal", (int)($m['horiz'] ?? 1) === 0,
               'sobran ' . ($m['horiz'] ?? '?') . 'px');
            //  Y QUE QUEPAN. Un fijo que se sale por el canto no crea scroll:
            //  el rótulo se corta contra el borde y nadie se entera.
            ok("@{$w} {$k} · ninguno se sale por el borde",
               empty($m['fuera']), json_encode($m['fuera'] ?? []));
            ok("@{$w} {$k} · ni por arriba ni por abajo",
               empty($m['desborda']), json_encode($m['desborda'] ?? []));
            ok("@{$w} {$k} · Ayuda no tapa la barra", empty($m['fabSobreDock']),
               json_encode($m['fabRect'] ?? null));
            ok("@{$w} {$k} · ni la Meta ni la acción principal",
               empty($m['fabSobreMeta']) && empty($m['fabSobrePri']),
               json_encode(['meta' => $m['fabSobreMeta'] ?? null,
                            'principal' => $m['fabSobrePri'] ?? null,
                            'ayuda' => $m['fabRect'] ?? null]));
        }

        //  LA ALTURA NO CAMBIA AL NAVEGAR: si cambiara, la página daría un
        //  salto en cada toque.
        $alt = json_decode((string)($R["ALTURAS_{$w}"] ?? '[]'), true) ?: [];
        $vuelta = $leer("M_{$w}_vuelta");
        ok("@{$w} · el dock mide lo mismo en todas",
           count(array_unique(array_map(
               fn($k) => (int)($leer("M_{$w}_{$k}")['dockAlto'] ?? 0), array_keys($RUTAS)))) === 1,
           json_encode(array_map(fn($k) => $leer("M_{$w}_{$k}")['dockAlto'] ?? null, array_keys($RUTAS))));
        ok("@{$w} · y al volver a Inicio sigue centrado",
           (float)($vuelta['desvio'] ?? 999) <= 2.0 && ($vuelta['activoK'] ?? '') === 'inicio',
           json_encode([$vuelta['activoK'] ?? '', $vuelta['desvio'] ?? null]));
    }

    echo "\n  — al tocar otro destino —\n";
    $T = json_decode((string)($R['TOQUE'] ?? '{}'), true) ?: [];
    ok('navega al tocar',
       str_contains((string)($T['url'] ?? ''), 'calendario.php'), (string)($T['url'] ?? ''));
    ok('y conserva la marca',
       str_contains((string)($T['url'] ?? ''), 'marca=' . $M), (string)($T['url'] ?? ''));
    //  LA ESPERA SE LEE EN LA FUENTE, no con un cronómetro. Desde fuera no se
    //  puede separar «lo que tarda la animación» de «lo que tarda el servidor
    //  en devolver la página siguiente»: el reloj mide las dos cosas juntas y
    //  la afirmación se vuelve un sorteo. La promesa es del código —animar
    //  poco y navegar— y ahí es donde se comprueba.
    $foot = (string)file_get_contents(dirname(__DIR__) . '/panel/_shell_foot.php');
    preg_match('/location\.href = a\.href; \}, (\d+)\)/', $foot, $ms);
    ok('la animación no pasa de 200ms',
       isset($ms[1]) && (int)$ms[1] > 0 && (int)$ms[1] <= 200,
       ($ms[1] ?? 'no encontrado') . 'ms — una animación que retrasa la navegación'
       . ' se siente como una aplicación lenta, no como una cuidada');
    ok('cero errores en consola',
       in_array(trim((string)($R['ERRORES'] ?? '[]')), ['[]', ''], true), (string)($R['ERRORES'] ?? ''));

    // ══════════════════════════════════════════════════════════════
    //  HOVER · y el escritorio de verdad
    // ══════════════════════════════════════════════════════════════
    echo "\n  — con puntero encima —\n";
    $H = $correr('hover');
    ok('la pasada de hover corrió', ($H['OK'] ?? '0') === '1', substr((string)$H['_raw'], -400));
    $hv = json_decode((string)($H['HOVER'] ?? '{}'), true) ?: [];
    ok('el icono bajo el puntero crece',
       (float)($hv['escala'] ?? 1) > 1.05, json_encode($hv));
    ok('y sus vecinos acompañan',
       (int)($hv['vecinos'] ?? 0) >= 1, json_encode($hv));
    $d14 = json_decode((string)($H['D1440'] ?? '{}'), true) ?: [];
    ok('a 1440 el dock no se pinta',   !empty($d14['oculto']), json_encode($d14));
    ok('y manda el menú lateral',      !empty($d14['lateral']), json_encode($d14));
    ok('sin scroll horizontal',        (int)($d14['horiz'] ?? 1) === 0, json_encode($d14));

    // ══════════════════════════════════════════════════════════════
    //  SIN JAVASCRIPT
    // ══════════════════════════════════════════════════════════════
    echo "\n  — sin JavaScript —\n";
    $J = $correr('sinjs');
    ok('la pasada sin JS corrió',      ($J['OK'] ?? '0') === '1', substr((string)$J['_raw'], -300));
    ok('los enlaces siguen navegando',
       str_contains((string)($J['SINJS_URL'] ?? ''), 'calendario.php'),
       (string)($J['SINJS_URL'] ?? '') . ' — el dock es navegación, no una animación con enlaces dentro');

    echo "\n  capturas en tests/_capturas/dock/*.png\n";

} catch (Throwable $e) {
    $fallos++; $n++;
    echo "\n  EXCEPCION · " . get_class($e) . ': ' . $e->getMessage() . "\n";
} finally {
    if ($M > 0) { try { Fixture::limpiar($pdo, $M); echo "\n  (fixture limpiada)\n"; }
                  catch (Throwable $e) {} }
}

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0 ? "  EL DOCK · EL ACTIVO EN EL CENTRO · {$n} afirmaciones\n\n"
                   : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
