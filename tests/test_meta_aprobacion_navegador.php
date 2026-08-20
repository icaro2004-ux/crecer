<?php
// ============================================================
//  CRECER — APROBAR DESDE TU META, CON NAVEGADOR DE VERDAD
//  tests/test_meta_aprobacion_navegador.php
//
//  aprobar2 es EL recorrido de aprobacion y era el unico que seguia sin
//  ejercitarse: bajo el arnes de CLI la pantalla no emite cuerpo, y afirmar
//  sobre el fuente solo demuestra que alguien escribio una linea.
//
//  Aqui se conduce Chrome contra el servidor local: entrar a Tu Meta, pulsar
//  la accion dominante, aprobar la pieza y comprobar a donde se vuelve y que
//  cambio. Nada de proveedores externos — es el recorrido propio de Crecer.
//
//  La sesion se inyecta por cookie (mismo save_path que Apache). La fixture
//  lleva su sello, asi que la limpieza no puede tocar nada ajeno.
//
//  SE SALTA, diciendolo, si no hay servidor local o Chrome: es una prueba de
//  entorno, y fingir que corrio seria peor que no correrla.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nAPROBAR DESDE TU META · NAVEGADOR REAL\n" . str_repeat('=', 56) . "\n";

$CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if (!is_file($CHROME)) { echo "\n  SALTADO · no hay Chrome en esta máquina\n\n"; exit(0); }

$ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true]]);
$vive = @file_get_contents('http://localhost/crecer/login.php', false, $ctx);
if ($vive === false) { echo "\n  SALTADO · el servidor local no responde\n\n"; exit(0); }

$SHOTS = __DIR__ . '/_capturas';
@mkdir($SHOTS, 0775, true);

$fx    = Fixture::crear($pdo, 'navegador', true, 'admin');
$M     = (int)$fx['marca_id'];
$PIEZA = (int)$fx['piezas'][0];
$CARR  = (int)$fx['piezas'][1];   // esta se vuelve carrusel: carrusel.php no monta el wizard para un post

try {
    // La pieza tiene que llevar arte: sin él, aprobar abre el estudio en vez de
    // aprobar (es «aprobar inteligente», no un fallo). Y va en la jugada que
    // toca para que sea ella la que domine Tu Meta.
    $pdo->prepare("UPDATE crecer_contenido
                      SET grafica_path='/crecer/assets/brand/crecer-icon.png', estado='borrador'
                    WHERE id=?")->execute([$PIEZA]);

    // La segunda pieza pasa a ser un carrusel con sus dos slides: sin eso,
    // carrusel.php no monta el wizard y no hay cierre que mirar.
    $pdo->prepare("UPDATE crecer_contenido SET tipo='carrusel' WHERE id=?")->execute([$CARR]);
    $sl = $pdo->prepare("INSERT INTO crecer_carrusel (contenido_id,marca_id,orden,idea,grafica_path,img_estado)
                          VALUES (?,?,?,?,?, 'ok')");
    $sl->execute([$CARR, $M, 1, 'Idea de relleno 1', '/crecer/assets/brand/crecer-icon.png']);
    $sl->execute([$CARR, $M, 2, 'Idea de relleno 2', '/crecer/assets/brand/crecer-icon.png']);

    // Sesión de Apache, escrita a mano. Sin teclear contraseñas.
    $sid = 'nav' . bin2hex(random_bytes(8));
    $ruta = session_save_path() ?: sys_get_temp_dir();
    file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
        'usuario_id|i:' . (int)$fx['usuario_id'] . ';');

    $cmd = 'node ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . '_navegador.mjs')
         . ' ' . escapeshellarg($sid) . ' ' . $M . ' ' . $PIEZA . ' ' . $CARR . ' ' . escapeshellarg($SHOTS) . ' 2>&1';
    $sal = []; exec($cmd, $sal);
    $r = [];
    foreach ($sal as $l) { if (strpos($l, '=') !== false) { [$k, $v] = explode('=', trim($l), 2); $r[$k] = $v; } }

    ok('el navegador completó el recorrido', ($r['OK'] ?? '0') === '1',
       ($r['ERROR'] ?? '') ?: implode(' | ', array_slice($sal, -3)));

    if (($r['OK'] ?? '0') === '1') {
        echo "\n  — abre la pieza exacta —\n";
        ok('Tu Meta ofrece UNA sola acción primaria', (int)($r['PRIMARIAS_META'] ?? 9) === 1,
           'primarias=' . ($r['PRIMARIAS_META'] ?? '?'));
        ok('la acción sale hacia aprobar2', strpos((string)($r['ACCION_HREF'] ?? ''), 'aprobar2.php') !== false,
           (string)($r['ACCION_HREF'] ?? ''));
        ok('lleva el marcador de vuelta', strpos((string)($r['ACCION_HREF'] ?? ''), 'volver=meta') !== false);
        ok('abre LA pieza, no la bandeja', strpos((string)($r['ACCION_HREF'] ?? ''), 'ver=' . $PIEZA) !== false,
           'el contrato pide el objeto exacto, no una lista');
        ok('y la pieza que sale en pantalla es esa',
           (string)($r['PIEZA_ID_EN_FORM'] ?? '') === (string)$PIEZA,
           'en el formulario: ' . (string)($r['PIEZA_ID_EN_FORM'] ?? '—'));

        echo "\n  — aprobar cambia la pieza —\n";
        $q = $pdo->prepare("SELECT estado FROM crecer_contenido WHERE id=?");
        $q->execute([$PIEZA]);
        $estado = (string)$q->fetchColumn();
        ok('la pieza quedó APROBADA en la base', $estado === 'aprobado', "estado={$estado}");

        echo "\n  — vuelve a la misma marca, con acuse —\n";
        $v = (string)($r['VUELTA_URL'] ?? '');
        ok('vuelve a Tu Meta', strpos($v, 'meta.php') !== false, $v);
        ok('a la MISMA marca', strpos($v, 'marca=' . $M) !== false, $v);
        ok('con el acuse de aprobado', strpos($v, 'hecho=aprobado') !== false, $v);
        ok('y el acuse se ve en pantalla',
           strpos((string)($r['ACUSE'] ?? ''), 'Aprobado.') !== false,
           'acuse: ' . (string)($r['ACUSE'] ?? '—'));

        echo "\n  — «Ahora» se recalcula —\n";
        // Con dos piezas esperando, el estado sigue siendo F y el titulo es el
        // mismo: eso NO es que no se recalculara. Lo que lo prueba es que la
        // accion ya no apunta a la pieza recien aprobada.
        ok('la acción ya no lleva a la pieza aprobada',
           strpos((string)($r['ACCION_HREF_DESPUES'] ?? ''), 'ver=' . $PIEZA) === false,
           'sigue: ' . (string)($r['ACCION_HREF_DESPUES'] ?? '—'));
        ok('y sí apunta a algo que hacer',
           strpos((string)($r['ACCION_HREF_DESPUES'] ?? ''), 'ver=') !== false
           || strpos((string)($r['ACCION_HREF_DESPUES'] ?? ''), 'reels.php') !== false);

        echo "\n  — la salida manual no afirma nada —\n";
        ok('la salida manual vuelve como pendiente',
           strpos((string)($r['SALIDA_MANUAL_URL'] ?? ''), 'hecho=pendiente') !== false,
           (string)($r['SALIDA_MANUAL_URL'] ?? '—'));
        ok('y lo dice sin dar nada por hecho',
           strpos((string)($r['SALIDA_MANUAL_ACUSE'] ?? ''), 'sigue pendiente') !== false,
           (string)($r['SALIDA_MANUAL_ACUSE'] ?? '—'));

        // ── JERARQUÍA: nunca dos botones principales (criterio 3) ────────
        echo "\n  — una sola acción primaria por pantalla —\n";
        ok('en Tu Meta hay UNA primaria', (int)($r['PRIMARIAS_META'] ?? 9) === 1,
           'primarias=' . ($r['PRIMARIAS_META'] ?? '?'));

        ok('en el reel terminado, volver es la primaria',
           ($r['REEL_VUELTA_PRIMARIA'] ?? '') === 'true',
           'si el dueño vino por el video, al terminarlo lo que toca es volver');
        ok('y «Publicar ahora» baja a secundaria',
           ($r['REEL_PUB_SECUNDARIA'] ?? '') === 'true',
           'sigue ahí y a un toque, pero deja de competir');
        ok('el reel no muestra dos primarias', (int)($r['REEL_PRIMARIAS'] ?? 9) === 1,
           'primarias visibles=' . ($r['REEL_PRIMARIAS'] ?? '?'));
        // MEDIDAS, no contadores. El detector viejo solo comparaba .btn entre
        // si: daba verde mientras Ayuda y la barra fija tapaban botones.
        ok('el reel no desborda a lo ancho', (int)($r['REEL_DESBORDE'] ?? 1) === 0,
           'documento/viewport = ' . ($r['REEL_ANCHO'] ?? '?') . ' · desborde=' . ($r['REEL_DESBORDE'] ?? '?') . 'px');
        ok('ningún control del reel queda bajo Ayuda ni la barra',
           (int)($r['REEL_TAPADOS'] ?? 9) === 0,
           (string)($r['REEL_TAPADOS_DET'] ?? ''));
        ok('ni se sale ninguno del viewport', (int)($r['REEL_FUERA'] ?? 9) === 0,
           (string)($r['REEL_FUERA_DET'] ?? ''));
        ok('al final del reel queda hueco sobre la barra fija',
           (int)($r['REEL_HUECO_FINAL'] ?? -1) >= 0,
           'hueco=' . ($r['REEL_HUECO_FINAL'] ?? '?') . 'px · negativo = el último botón queda debajo');
        echo "         (reel: " . ($r['REEL_CONTROLES'] ?? '?') . " controles medidos)
";

        ok('el carrusel programado muestra UNA salida', (int)($r['CARR_PRIMARIAS'] ?? 9) === 1,
           'primarias=' . ($r['CARR_PRIMARIAS'] ?? '?'));
        ok('y vuelve como programado, no como aprobado',
           strpos((string)($r['CARR_VUELTA'] ?? ''), 'hecho=programado') !== false,
           (string)($r['CARR_VUELTA'] ?? '—'));
        ok('el carrusel no desborda', (int)($r['CARR_DESBORDE'] ?? 1) === 0,
           'desborde=' . ($r['CARR_DESBORDE'] ?? '?') . 'px');
        ok('ningún control del carrusel queda tapado', (int)($r['CARR_TAPADOS'] ?? 9) === 0,
           (string)($r['CARR_TAPADOS_DET'] ?? ''));
        ok('ni fuera del viewport', (int)($r['CARR_FUERA'] ?? 9) === 0);
        ok('la aprobación no desborda', (int)($r['APROB_DESBORDE'] ?? 1) === 0,
           'desborde=' . ($r['APROB_DESBORDE'] ?? '?') . 'px');
        ok('ningún control de la aprobación queda tapado', (int)($r['APROB_TAPADOS'] ?? 9) === 0,
           (string)($r['APROB_TAPADOS_DET'] ?? ''));

        echo "\n  — las capturas a 360x800 —\n";
        foreach (['aprobacion', 'reel_terminado', 'carrusel_programado'] as $c) {
            ok("captura {$c} · viewport 360x800", is_file($SHOTS . '/' . $c . '.png'));
            ok("captura {$c} · página completa", is_file($SHOTS . '/' . $c . '_completa.png'));
        }
    }
} finally {
    Fixture::limpiar($pdo, $M);
    if (!empty($sid)) @unlink((session_save_path() ?: sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'sess_' . $sid);
    $q = $pdo->prepare("SELECT COUNT(*) FROM crecer_marca WHERE id=?"); $q->execute([$M]);
    echo "\n  (fixture limpiada: " . ((int)$q->fetchColumn() === 0 ? 'sí' : 'NO') . ")\n";
}

echo "\n" . str_repeat('=', 56) . "\n";
echo $fallos === 0 ? "  TODO OK · {$n} pruebas\n\n" : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
