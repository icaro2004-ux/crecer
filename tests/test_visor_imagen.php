<?php
// ============================================================
//  CRECER — VER LA IMAGEN COMPLETA, ENCIMA DE TODO
//  tests/test_visor_imagen.php
//
//  EL DEFECTO QUE CIERRA, y es de los que bloquean. El dueño tenia que decidir
//  —aprobar, publicar, quedarse con la suya o usar la nueva— viendo MEDIA
//  imagen: el menu, Ayuda y los propios botones quedaban delante. Se le pedia un
//  juicio sobre algo que no podia ver entero.
//
//  Lo que se prueba no es que exista un visor, sino que sirve:
//    · esta POR ENCIMA de todos los fijos (se le pregunta al navegador quien hay
//      en el centro y en las cuatro esquinas, no se mira el CSS);
//    · la imagen CABE entera, sin recorte;
//    · las acciones NO se ven mientras se mira: se decide despues, no encima;
//    · se sale por el fondo, por la X y con Escape;
//    · y al volver, la comparacion y la posicion siguen donde estaban.
//
//  Y ES DE LECTURA: se toma un retrato de las tablas antes y despues y tienen
//  que ser identicas. Cero proveedor, cero cuota, cero escritura.
// ============================================================

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/candidata.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nVER LA IMAGEN COMPLETA\n" . str_repeat('=', 58) . "\n";

$cnt = fn(string $t, string $w = '1') => (int)$GLOBALS['pdo']->query(
    "SELECT COUNT(*) FROM {$t} WHERE {$w}")->fetchColumn();
$g0 = ['ia' => $cnt('crecer_ia_log'), 'as' => $cnt('crecer_img_cuota_asiento')];

$ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
if (@file_get_contents('http://localhost/crecer/login.php', false, $ctx) === false) {
    echo "\n  SALTADA · el servidor local no responde\n\n"; exit(0);
}
$SONDA = __DIR__ . DIRECTORY_SEPARATOR . '_visor.mjs';
if (!is_file($SONDA)) { echo "\n  SALTADA · falta la sonda\n\n"; exit(0); }
$SHOTS = __DIR__ . DIRECTORY_SEPARATOR . '_capturas' . DIRECTORY_SEPARATOR . 'visor';
@mkdir($SHOTS, 0775, true);

$limpiar = [];
try {
    $fx = Fixture::crear($pdo, 'visor', true, 'admin');
    $limpiar[] = $M = (int)$fx['marca_id'];
    $P = (int)($fx['piezas'][0] ?? 0);
    ok('la fixture trae una pieza', $P > 0);
    if ($P <= 0) throw new RuntimeException('sin pieza no hay imagen que mirar');

    $sid = 'vi' . bin2hex(random_bytes(7));
    file_put_contents((session_save_path() ?: sys_get_temp_dir())
                      . DIRECTORY_SEPARATOR . 'sess_' . $sid,
                      'usuario_id|i:' . (int)$fx['usuario_id'] . ';');

    //  DOS IMAGENES DE VERDAD, y con forma distinta: una apaisada y otra
    //  vertical. Si el visor recortara, con una cuadrada no se notaria.
    $mk = function (string $nombre, int $w, int $hh) use ($M): string {
        $rel = "marca_{$M}/graficas/{$nombre}.png";
        $abs = rtrim(UPLOADS_PATH, '/\\') . DIRECTORY_SEPARATOR
             . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        @mkdir(dirname($abs), 0775, true);
        //  UN PNG DE VERDAD, con su tamaño. Con el de 1x1 que usa el resto de
        //  las suites no se podria demostrar que NO se recorta — y esta maquina
        //  no tiene GD, asi que se escribe a mano.
        require_once __DIR__ . '/_png.php';
        file_put_contents($abs, png_solido($w, $hh));
        return rtrim(UPLOADS_URL, '/') . '/' . $rel;
    };
    $ACTUAL = $mk('visor_actual', 1600, 900);    // apaisada
    $NUEVA  = $mk('visor_nueva', 900, 1600);     // vertical

    $pdo->prepare("UPDATE crecer_contenido SET grafica_path=?, tipo='post', estado='borrador',
                          fecha_programada = DATE_ADD(NOW(), INTERVAL 2 DAY)
                    WHERE id=? AND marca_id=?")->execute([$ACTUAL, $P, $M]);

    $CAND = 0;
    if (cand_hay_columnas($pdo, true)) {
        $pdo->prepare("INSERT INTO crecer_generaciones
                (marca_id, contenido_id, estado, decision_dueno, copy_text, prompt_narrativo, archivo)
              VALUES (?,?, 'completed', NULL, '[prueba]', '[prueba] instrucción', ?)")
            ->execute([$M, $P, $NUEVA]);
        $CAND = (int)$pdo->lastInsertId();
    }
    ok('hay una candidata que comparar', $CAND > 0);

    //  EL RETRATO DE ANTES. El visor es de lectura: si algo se mueve, escribio.
    $retrato = function () use ($pdo, $M, $P): string {
        $h = '';
        foreach ($pdo->query("SELECT * FROM crecer_contenido WHERE id={$P}") as $r) $h .= json_encode($r);
        foreach ($pdo->query("SELECT * FROM crecer_generaciones WHERE marca_id={$M} ORDER BY id") as $r) $h .= json_encode($r);
        foreach ($pdo->query("SELECT * FROM crecer_img_cuota_asiento WHERE marca_id={$M} ORDER BY id") as $r) $h .= json_encode($r);
        return sha1($h);
    };
    $antes = $retrato();

    $cmd = 'node ' . escapeshellarg($SONDA) . ' ' . escapeshellarg($sid) . ' ' . $M
         . ' ' . $P . ' ' . escapeshellarg($SHOTS) . ' 2>&1';
    $sal = []; exec($cmd, $sal);
    $r = [];
    foreach ($sal as $l) if (strpos($l, '=') !== false) { [$k, $v] = explode('=', trim($l), 2); $r[$k] = $v; }
    ok('el navegador completó el recorrido', ($r['OK'] ?? '0') === '1',
       ($r['ERROR'] ?? '') ?: implode(' | ', array_slice($sal, -3)));
    if (($r['OK'] ?? '0') !== '1') throw new RuntimeException('sin recorrido no hay nada que afirmar');

    $J = fn(string $k) => json_decode((string)($r[$k] ?? '{}'), true) ?: [];

    // ══════════════════════════════════════════════════════════════
    //  1 · LA IMAGEN COMUNICA QUE SE PUEDE TOCAR
    // ══════════════════════════════════════════════════════════════
    echo "\n  — la imagen se ve tocable, y lo es entera —\n";
    ok('toda la superficie es el control', ($r['TARJETA_SE_TOCA'] ?? '') === 'true',
       'no un botón pequeño al lado: el dedo tiene que poder fallar');
    ok('y lo dice para quien no ve',
       str_contains((string)($r['TARJETA_LO_DICE'] ?? ''), 'Ver imagen completa'),
       $r['TARJETA_LO_DICE'] ?? '');
    ok('con una pista discreta encima', ($r['TARJETA_TIENE_PISTA'] ?? '') === 'true',
       'un icono en la esquina, no otro botón rectangular debajo');

    // ══════════════════════════════════════════════════════════════
    //  2 · EL VISOR, EN LAS TRES ANCHURAS
    // ══════════════════════════════════════════════════════════════
    echo "\n  — y se abre encima de todo, entera —\n";
    foreach (['360', '414', '1440'] as $w) {
        $v = $J('ACTUAL_' . $w);
        ok("a {$w} · el visor abre",        !empty($v['abierto']));
        ok("a {$w} · la imagen cabe entera", !empty($v['cabe']),
           'rect=' . json_encode($v['rect'] ?? null) . ' viewport=' . json_encode($v['vp'] ?? null));
        ok("a {$w} · sin recortarla",       ($v['contain'] ?? '') === 'contain'
           && ($v['natural'] ?? true) !== false,
           'object-fit=' . (string)($v['contain'] ?? '?') . ' proporción=' . json_encode($v['natural'] ?? null));
        ok("a {$w} · NADA quedó delante",   empty($v['intrusos']),
           json_encode(array_slice((array)($v['intrusos'] ?? []), 0, 4))
           . ' · el menú, Ayuda o la barra no pueden taparla');
        ok("a {$w} · ni una acción encima", empty($v['accionesEncima']),
           json_encode(array_slice((array)($v['accionesEncima'] ?? []), 0, 4))
           . ' · se decide después, no encima de la imagen');
        ok("a {$w} · la X se puede tocar",
           (int)(($v['x'] ?? [0,0])[0]) >= 44 && (int)(($v['x'] ?? [0,0])[1]) >= 44,
           json_encode($v['x'] ?? null));
        ok("a {$w} · cero scroll horizontal", (int)($v['horiz'] ?? 0) === 0);
        ok("a {$w} · y el fondo lo cierra",  ($r['ACTUAL_CIERRA_' . $w] ?? '') === 'true');
    }
    ok('sigo en la misma publicación', ($r['POS_TRAS_ACTUAL'] ?? '') === ($r['POS_ANTES'] ?? 'x'),
       ($r['POS_ANTES'] ?? '') . ' → ' . ($r['POS_TRAS_ACTUAL'] ?? ''));
    ok('y sin salir de la semana',     ($r['SIGO_EN_SEMANA'] ?? '') === 'true');

    // ══════════════════════════════════════════════════════════════
    //  3 · LA COMPARACIÓN · las dos se abren, y la decisión aguanta
    // ══════════════════════════════════════════════════════════════
    echo "\n  — las dos de la comparación, y la decisión sigue ahí —\n";
    ok('la comparación abre',      ($r['COMPARACION_ABRE'] ?? '') === '¿Cuál te gusta más?',
       $r['COMPARACION_ABRE'] ?? '');
    ok('las dos se pueden tocar',  (int)($r['COMP_DOS_SE_TOCAN'] ?? 0) === 2,
       $r['COMP_DOS_SE_TOCAN'] ?? '');

    $nv = $J('NUEVA_360');
    ok('la candidata abre ENCIMA de la hoja', !empty($nv['abierto']));
    ok('y cabe entera',                       !empty($nv['cabe']),
       'rect=' . json_encode($nv['rect'] ?? null) . ' viewport=' . json_encode($nv['vp'] ?? null));
    ok('sin nada delante',                    empty($nv['intrusos']),
       json_encode(array_slice((array)($nv['intrusos'] ?? []), 0, 4)));
    ok('ni las acciones de decidir',          empty($nv['accionesEncima']),
       json_encode(array_slice((array)($nv['accionesEncima'] ?? []), 0, 4)));
    ok('Escape la cierra',                    ($r['NUEVA_CIERRA_ESC'] ?? '') === 'true');

    ok('y AL VOLVER sigue la comparación',
       ($r['SIGUE_LA_COMPARACION'] ?? '') === '¿Cuál te gusta más?',
       $r['SIGUE_LA_COMPARACION'] ?? '');
    ok('con «Usar la nueva»',      ($r['SIGUE_USAR_NUEVA'] ?? '') === 'true');
    ok('y «Quedarme con la actual»', ($r['SIGUE_QUEDARME'] ?? '') === 'true',
       'cerrar el visor no puede costarle la decisión que estaba tomando');
    ok('en la misma publicación',  ($r['POS_FINAL'] ?? '') === ($r['POS_ANTES'] ?? 'x'),
       ($r['POS_ANTES'] ?? '') . ' → ' . ($r['POS_FINAL'] ?? ''));

    $ac = $J('ACTUAL_DESDE_HOJA');
    ok('la actual también abre desde la hoja', !empty($ac['abierto']));
    ok('y también cabe entera',                !empty($ac['cabe']));
    ok('la X la cierra',                       ($r['CIERRA_CON_X'] ?? '') === 'true');
    ok('y la decisión sigue en pie',           ($r['Y_SIGUE_LA_DECISION'] ?? '') === 'true');

    // ══════════════════════════════════════════════════════════════
    //  4 · LA BARRA DE ABAJO · Tu Meta donde llega el pulgar
    // ══════════════════════════════════════════════════════════════
    echo "\n  — y el sitio del pulgar es Tu Meta —\n";
    foreach (['360', '414'] as $w) {
        $nav = $J('NAV_' . $w);
        ok("a {$w} · hay barra",           !empty($nav['hay']));
        ok("a {$w} · con Tu Meta dentro",
           in_array('Tu Meta', (array)($nav['etiquetas'] ?? []), true),
           json_encode($nav['etiquetas'] ?? []));
        ok("a {$w} · y sin «Crear» en ella",
           !in_array('Crear', (array)($nav['etiquetas'] ?? []), true),
           'Crear baja al menú: se usa a ratos, no todos los días');
        ok("a {$w} · marcada como activa en la semana",
           in_array('Tu Meta', (array)($nav['activa'] ?? []), true),
           json_encode($nav['activa'] ?? []) . ' · apagarla al entrar en la semana '
           . 'le diría al dueño que se ha ido de donde está');
        ok("a {$w} · nada por debajo de 44",   empty($nav['chicos']),
           json_encode(array_slice((array)($nav['chicos'] ?? []), 0, 3)));
        ok("a {$w} · sin solaparse entre sí",  empty($nav['solapa']),
           json_encode($nav['solapa'] ?? []));
        ok("a {$w} · cero scroll horizontal",  (int)($nav['horiz'] ?? 0) === 0);
        ok("a {$w} · Tu Meta NO se repite en el cajón",
           (int)($nav['meta_en_cajon'] ?? 0) === 0,
           'dos entradas visibles a lo mismo en la misma navegación es ruido');
        ok("a {$w} · y Crear sigue estando en el cajón",
           !empty($nav['crear_en_cajon']),
           'no se elimina: se mueve');
    }

    ok('consola limpia', in_array((string)($r['CONSOLA'] ?? ''), ['[]', ''], true),
       $r['CONSOLA'] ?? '');

    // ══════════════════════════════════════════════════════════════
    //  5 · Y NO ESCRIBIÓ NADA
    // ══════════════════════════════════════════════════════════════
    echo "\n  — el visor solo mira —\n";
    ok('las tablas quedan idénticas', $retrato() === $antes,
       'mirar una imagen no puede aprobar, decidir ni cambiar una ruta');
    if ($CAND > 0) {
        ok('la candidata sigue sin decidir',
           $pdo->query("SELECT decision_dueno FROM crecer_generaciones WHERE id={$CAND}")
               ->fetchColumn() === null);
    }
    ok('y la publicación conserva su imagen',
       (string)$pdo->query("SELECT grafica_path FROM crecer_contenido WHERE id={$P}")
                   ->fetchColumn() === $ACTUAL);

} catch (Throwable $e) {
    $fallos++; echo "\n  EXCEPCION · " . get_class($e) . ': ' . $e->getMessage()
                  . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    foreach ($limpiar as $mid) {
        try { $pdo->prepare("DELETE FROM crecer_generaciones WHERE marca_id=?")->execute([$mid]); }
        catch (Throwable $e) {}
        try { Fixture::limpiar($pdo, $mid); } catch (Throwable $e) {}
    }
    echo "\n  (fixtures limpiadas)\n";
}

echo "\n  — el costo —\n";
ok('cero llamadas al modelo', $cnt('crecer_ia_log') === $g0['ia']);
ok('cero asientos de cuota',  $cnt('crecer_img_cuota_asiento') === $g0['as'],
   'mirar una imagen no cuesta una imagen');

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  SE VE ENTERA Y ENCIMA DE TODO · $n afirmaciones\n\n"
    : "  $fallos FALLAS de $n\n\n";
exit($fallos === 0 ? 0 : 1);
