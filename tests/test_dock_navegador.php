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
    //  Y JAVASCRIPT YA NO SE METE EN MEDIO. Retrasar la navegación para animar
    //  era el error de raíz: la animación pasaba en el documento que se iba,
    //  así que al llegar el nuevo el dock aparecía de golpe. Ahora el viaje lo
    //  hace el navegador y el enlace navega cuando el dueño lo toca.
    $foot = (string)file_get_contents(dirname(__DIR__) . '/panel/_shell_foot.php');
    ok('el script del dock no retrasa la navegación',
       !str_contains($foot, 'location.href = a.href'),
       'el viaje lo hace el navegador, no un temporizador');
    ok('cero errores en consola',
       in_array(trim((string)($R['ERRORES'] ?? '[]')), ['[]', ''], true), (string)($R['ERRORES'] ?? ''));

    // ══════════════════════════════════════════════════════════════
    //  LOS PRIMEROS CUADROS · aquí vivía el parpadeo
    // ══════════════════════════════════════════════════════════════
    //  El estado final siempre estuvo bien. Lo que el dueño veía era el
    //  CAMINO: la barra llegaba en cuatro columnas iguales y JavaScript la
    //  recolocaba después, así que el activo se deslizaba desde la izquierda
    //  hasta el centro en cada página. Mirar el final no lo cazaba; hay que
    //  muestrear mientras se pinta.
    echo "\n  — los primeros 500ms de cada carga —\n";
    $F = $correr('flash');
    ok('el navegador muestreó las cargas', ($F['OK'] ?? '0') === '1',
       substr((string)$F['_raw'], -400));

    $revisar = function (string $clave, string $nombre) use ($F, &$fallos, &$n) {
        $ms = json_decode((string)($F[$clave] ?? '[]'), true) ?: [];
        if (!$ms) { ok("{$nombre} · hay muestras del dock", false, 'ninguna'); return; }
        ok("{$nombre} · el dock se ve desde la primera muestra", count($ms) >= 3,
           count($ms) . ' muestras');

        //  DESDE LA PRIMERA MUESTRA VISIBLE, ya centrado. Sin margen para
        //  «se coloca en la segunda».
        $desvios = array_map(fn($m) => abs((float)($m['desvio'] ?? 999)), $ms);
        ok("{$nombre} · centrado desde el primer cuadro", max($desvios) <= 2.0,
           'peor desvío ' . round(max($desvios), 1) . 'px · primero '
           . round($desvios[0], 1) . 'px');

        //  Y QUIETO: ni horizontal ni verticalmente.
        $xs = array_map(fn($m) => (float)($m['desvio'] ?? 0), $ms);
        $ys = array_map(fn($m) => (float)($m['actY'] ?? 0), $ms);
        ok("{$nombre} · sin moverse a lo ancho", (max($xs) - min($xs)) <= 2.0,
           'varía ' . round(max($xs) - min($xs), 1) . 'px');
        ok("{$nombre} · sin moverse a lo alto", (max($ys) - min($ys)) <= 2.0,
           'varía ' . round(max($ys) - min($ys), 1) . 'px');

        $altos = array_unique(array_map(fn($m) => (int)$m['alto'], $ms));
        ok("{$nombre} · la barra no cambia de altura", count($altos) === 1,
           json_encode(array_values($altos)));
        ok("{$nombre} · los cuatro destinos, siempre",
           array_unique(array_map(fn($m) => (int)$m['n'], $ms)) === [4],
           json_encode(array_values(array_unique(array_map(fn($m) => (int)$m['n'], $ms)))));
        ok("{$nombre} · un solo aria-current en todo momento",
           array_unique(array_map(fn($m) => (int)$m['current'], $ms)) === [1],
           json_encode(array_values(array_unique(array_map(fn($m) => (int)$m['current'], $ms)))));
        ok("{$nombre} · nadie se sale de la pantalla",
           array_sum(array_map(fn($m) => (int)$m['fuera'], $ms)) === 0);
        ok("{$nombre} · cero scroll horizontal",
           array_sum(array_map(fn($m) => (int)$m['horiz'], $ms)) === 0);
    };

    foreach (['360', '414'] as $w) {
        foreach (array_keys($RUTAS) as $k) $revisar("F_{$w}_{$k}", "@{$w} {$k}");
        $revisar("F_{$w}_atras",    "@{$w} atrás");
        $revisar("F_{$w}_adelante", "@{$w} adelante");
    }
    ok('cero errores de consola al cargar',
       in_array(trim((string)($F['ERRORES'] ?? '[]')), ['[]', ''], true), (string)($F['ERRORES'] ?? ''));

    // ══════════════════════════════════════════════════════════════
    //  LA TRANSICIÓN ENTRE DOCUMENTOS
    // ══════════════════════════════════════════════════════════════
    //  Que el CSS lo pida no basta: hay que comprobar que OCURRE. La señal
    //  honesta es `pagereveal`, que llega en el documento NUEVO y trae la
    //  transición viva cuando el navegador la hizo de verdad.
    echo "
  — el dock viaja de una página a la otra —
";
    $V = $correr('vt');
    ok('la pasada de transición corrió', ($V['OK'] ?? '0') === '1',
       substr((string)$V['_raw'], -400));
    $sop = json_decode((string)($V['SOPORTE'] ?? '{}'), true) ?: [];
    ok('el navegador sabe hacerlo',  !empty($sop['css']) && !empty($sop['evento']),
       json_encode($sop));
    //  CADA DESTINO SE RECONOCE A SÍ MISMO entre documentos: sin nombre propio
    //  no hay a quién llevar de una página a la otra, y sin nombres ÚNICOS el
    //  navegador descarta la transición entera.
    $nom = (array)($sop['nombres'] ?? []);
    ok('los cuatro tienen nombre propio', count($nom) === 4, json_encode($nom));
    ok('y ninguno repetido',
       count(array_unique($nom)) === count($nom) && !in_array('none', array_map(
           fn($x) => explode(':', $x)[1] ?? 'none', $nom), true),
       json_encode($nom));

    foreach (['calendario', 'meta', 'resultados', 'inicio'] as $k) {
        $r = json_decode((string)($V['VT_' . $k] ?? '{}'), true) ?: [];
        ok("al tocar {$k} · el navegador hace la transición", ($r['vt'] ?? null) === true,
           json_encode($r) . ' — si es false, navegó de golpe');
        ok("al tocar {$k} · y llega con {$k} activo", ($r['act'] ?? '') === $k,
           json_encode($r));
    }
    ok('cero errores durante las transiciones',
       in_array(trim((string)($V['ERRORES'] ?? '[]')), ['[]', ''], true), (string)($V['ERRORES'] ?? ''));

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
    //  Y ASENTADO. Sin JavaScript no hay quien coloque nada, así que si sale
    //  centrado es porque la geometría venía del servidor — que es justo lo
    //  que se arregló.
    $sc = json_decode((string)($J['SINJS_CENTRO'] ?? '{}'), true) ?: [];
    ok('y el activo sale centrado sin JavaScript',
       abs((float)($sc['desvio'] ?? 999)) <= 2.0,
       'desvío ' . ($sc['desvio'] ?? '?') . 'px · ' . ($sc['k'] ?? ''));

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
