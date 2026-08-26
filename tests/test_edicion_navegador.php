<?php
// ============================================================
//  CRECER — AJUSTAR, CON EL DEDO (Fase 2A)
//  tests/test_edicion_navegador.php
//
//  El contrato en PHP dice que los handlers hacen lo correcto. Este dice lo
//  otro: que el dueño abre «Ajustar», cambia lo que quiere, lo ve cambiado
//  delante de él y sigue en SU publicación — no al principio de la semana.
//  Y lo contrario: que cancelar y Escape no escriben nada.
//
//  CERO PROVEEDOR: `_sin_gasto.php` cierra la red por construcción y se cuentan
//  las filas de crecer_ia_log — el contrato de esta fase es CERO llamadas al
//  guardar texto o fecha, no «llamadas baratas».
// ============================================================

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/meta_negocio.php';
require_once __DIR__ . '/../includes/meta_semana.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nAJUSTAR, CON EL DEDO\n" . str_repeat('=', 58) . "\n";

if (@file_get_contents('http://localhost/crecer/login.php', false,
        stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]])) === false) {
    echo "\n  SALTADO · el servidor local no responde\n\n"; exit(0);
}
if (!is_file('C:/Program Files/Google/Chrome/Application/chrome.exe')) {
    echo "\n  SALTADO · no hay Chrome\n\n"; exit(0);
}

$cnt = fn(string $t, string $w = '1') => (int)$GLOBALS['pdo']->query(
    "SELECT COUNT(*) FROM {$t} WHERE {$w}")->fetchColumn();
$g = ['ia' => $cnt('crecer_ia_log'), 'cuota' => $cnt('crecer_img_cuota_asiento')];

$SHOTS = __DIR__ . '/_capturas/edicion';
if (!is_dir($SHOTS)) @mkdir($SHOTS, 0777, true);

$limpiar = [];
try {
    echo "\n  — una semana con dos publicaciones —\n";
    $fx = Fixture::crear($pdo, 'edn', true, 'admin');
    $limpiar[] = $M = (int)$fx['marca_id'];
    $meta = meta_activa($pdo, $M);
    $plan = meta_plan_activo($pdo, (int)$meta['id']);
    $pdo->prepare("UPDATE crecer_meta_tactica SET semana=9, estado='hecha' WHERE meta_id=?")
        ->execute([(int)$meta['id']]);
    foreach ($fx['piezas'] as $p) {
        $pdo->prepare("UPDATE crecer_contenido SET tactica_id=NULL WHERE id=?")->execute([(int)$p]);
    }
    $ins = $pdo->prepare(
        "INSERT INTO crecer_meta_tactica
            (meta_id, plan_id, marca_id, orden, semana, tipo, titulo, por_que,
             clase, quien, estado, piezas_meta, formato)
         VALUES (?,?,?,?,1,'contenido',?,?, 'produccion','corillo','pendiente',1,'post')");
    $ids = [];
    foreach ([1 => 'Primera', 2 => 'Segunda'] as $o => $t) {
        $ins->execute([(int)$meta['id'], (int)$plan['id'], $M, $o,
                       '[prueba] ' . $t . ' publicación', 'así la gente sabe qué pedir']);
        $tid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO crecer_contenido
                (marca_id,plataforma,tipo,caption,estado,meta_id,plan_id,tactica_id,
                 fecha_programada,grafica_path)
              VALUES (?, 'instagram','post',?, 'borrador',?,?,?, DATE_ADD(NOW(), INTERVAL ? DAY), ?)")
            ->execute([$M, '[prueba] Texto original de la ' . mb_strtolower($t) . '.',
                       (int)$meta['id'], (int)$plan['id'], $tid, $o + 1,
                       '/crecer/assets/brand/crecer-icon.png']);
        $ids[$t] = (int)$pdo->lastInsertId();
    }
    $sid = 'edn' . bin2hex(random_bytes(7));
    file_put_contents((session_save_path() ?: sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'sess_' . $sid,
                      'usuario_id|i:' . (int)$fx['usuario_id'] . ';');

    $res = semana_resumen($pdo, $M, $meta, $plan, '/crecer/panel');
    ok('la semana tiene dos posiciones', (int)$res['total'] === 2, json_encode($res));

    //  SE AJUSTA LA SEGUNDA a propósito: si el retorno estuviera roto, volver
    //  a la 1 pasaría desapercibido con una sola publicación.
    $POS = 2;
    $C   = $ids['Segunda'];
    $antes = $pdo->query("SELECT caption, fecha_programada FROM crecer_contenido WHERE id={$C}")
                 ->fetch(PDO::FETCH_ASSOC);
    $ia0 = $cnt('crecer_ia_log');

    echo "\n  — el recorrido, en Chrome —\n";
    $salida = shell_exec('node ' . escapeshellarg(__DIR__ . '/_edicion_probe.mjs') . ' '
        . escapeshellarg($SHOTS) . ' ' . escapeshellarg($sid) . ' ' . $M . ' ' . $POS . ' 2>&1');
    $R = [];
    foreach (explode("\n", (string)$salida) as $l) {
        $l = trim($l); $i = strpos($l, '=');
        if ($i > 0) $R[substr($l, 0, $i)] = substr($l, $i + 1);
    }
    ok('el navegador hizo el recorrido', ($R['OK'] ?? '0') === '1', substr((string)$salida, -700));
    if (($R['OK'] ?? '0') !== '1') throw new RuntimeException('sonda caída');
    $leer = fn(string $k) => json_decode((string)($R[$k] ?? '{}'), true) ?: [];

    // ── EL MENÚ ──────────────────────────────────────────────────
    echo "\n  — «¿Qué quieres ajustar?» —\n";
    $I = $leer('INICIO');
    ok('abrió en la publicación pedida', (int)($I['n'] ?? 0) === $POS, json_encode($I));
    $Mn = $leer('MENU');
    ok('la hoja abre',            !empty($Mn['abierta']), json_encode($Mn));
    ok('se anuncia como diálogo', ($Mn['dialogo'] ?? '') === 'dialog'
                               && ($Mn['modal'] ?? '') === 'true');
    ok('pregunta qué ajustar',
       mb_stripos((string)($Mn['titulo'] ?? ''), 'ajustar') !== false, (string)($Mn['titulo'] ?? ''));
    $filas = (array)($Mn['filas'] ?? []);
    ok('ofrece el texto',   count(array_filter($filas, fn($f) => mb_stripos($f, 'Texto') !== false)) > 0,
       json_encode($filas));
    ok('la imagen o video', count(array_filter($filas, fn($f) => mb_stripos($f, 'Imagen') !== false)) > 0);
    ok('y la fecha',        count(array_filter($filas, fn($f) => mb_stripos($f, 'Fecha') !== false)) > 0);
    ok('no son siete botones iguales', count($filas) <= 4,
       count($filas) . ' filas · una lista interminable es una lista que nadie lee');

    // ── ESCAPE NO ESCRIBE ────────────────────────────────────────
    echo "\n  — cerrar no es guardar —\n";
    $E = $leer('TRAS_ESCAPE');
    ok('Escape cierra la hoja',  empty($E['abierta']), json_encode($E));
    ok('y no envió nada',        (int)($E['posts'] ?? -1) === (int)($E['antes'] ?? -2), json_encode($E));

    // ── EL TEXTO ─────────────────────────────────────────────────
    echo "\n  — cambiar lo que dice —\n";
    $TH = $leer('TEXTO.HOJA');
    ok('la hoja del texto abre',  !empty($TH['abierta']), json_encode($TH));
    ok('el foco entra en ella',   !empty($TH['focoDentro']));
    ok('con una sola primaria',   (int)($TH['primarias'] ?? 9) === 1);
    ok('y trae el texto de ahora',
       mb_stripos((string)($TH['texto'] ?? ''), 'Texto original de la segunda') !== false,
       (string)($TH['texto'] ?? ''));

    $TC = $leer('TEXTO.CANCELADO');
    ok('cancelar cierra',            empty($TC['abierta']), json_encode($TC));
    ok('sin enviar nada',            (int)($TC['posts'] ?? -1) === (int)($TC['antes'] ?? -2));
    ok('y sin tocar la publicación',
       mb_stripos((string)($TC['caption'] ?? ''), 'Texto original de la segunda') !== false,
       (string)($TC['caption'] ?? ''));
    //  Lo que cancelar NO cambio se afirma con lo que vio el navegador en ese
    //  instante, no releyendo la base ahora: para cuando esto corre, el
    //  guardado de verdad ya ocurrio, y comparar contra el texto original
    //  medía el paso siguiente, no el cancelado.
    ok('cancelar no envió ni un POST', (int)($TC['posts'] ?? -1) === (int)($TC['antes'] ?? -2),
       json_encode($TC));

    $TG = $leer('TEXTO.GUARDADO');
    ok('al guardar, la hoja se cierra', empty($TG['hoja']), json_encode($TG));
    ok('el texto nuevo se ve al instante',
       mb_stripos((string)($TG['caption'] ?? ''), 'con su dedo') !== false,
       (string)($TG['caption'] ?? ''));
    ok('sin moverse de su publicación', (int)($TG['n'] ?? 0) === $POS);
    ok('y quedó guardado de verdad',
       mb_stripos((string)$pdo->query("SELECT caption FROM crecer_contenido WHERE id={$C}")
                              ->fetchColumn(), 'con su dedo') !== false);
    $TR = $leer('TEXTO.TRAS_RECARGA');
    ok('al recargar sigue ahí',
       mb_stripos((string)($TR['caption'] ?? ''), 'con su dedo') !== false
       && (int)($TR['n'] ?? 0) === $POS, json_encode($TR));

    ok('y guardar el texto no llamó a nadie', $cnt('crecer_ia_log') === $ia0,
       'antes ' . $ia0 . ' · ahora ' . $cnt('crecer_ia_log'));

    // ── LA FECHA ─────────────────────────────────────────────────
    echo "\n  — cambiar cuándo sale —\n";
    $FH = $leer('FECHA.HOJA');
    ok('la hoja de la fecha abre', !empty($FH['abierta']), json_encode($FH));
    ok('y dice qué va a pasar',
       mb_stripos((string)($FH['texto'] ?? ''), 'Se publicará el') !== false,
       (string)($FH['texto'] ?? ''));

    $FP = $leer('FECHA.PASADA');
    ok('una fecha que ya pasó se rechaza dentro de la capa',
       !empty($FP['abierta']) && mb_strlen((string)($FP['err'] ?? '')) > 5, json_encode($FP));
    ok('y lo dice en cristiano',
       mb_stripos((string)($FP['err'] ?? ''), 'pasó') !== false, (string)($FP['err'] ?? ''));
    ok('sin mover la fecha que ya tenía',
       (string)($FP['fecha'] ?? '') !== '' && mb_strpos((string)($FP['fecha'] ?? ''), '2020') === false,
       'la tarjeta seguía con su fecha: ' . (string)($FP['fecha'] ?? ''));

    $FG = $leer('FECHA.GUARDADA');
    $esperada = str_replace('T', ' ', (string)($R['FECHA.ESPERADA'] ?? '')) . ':00';
    ok('la fecha buena se guarda',
       (string)$pdo->query("SELECT fecha_programada FROM crecer_contenido WHERE id={$C}")
                   ->fetchColumn() === $esperada,
       'esperada ' . $esperada);
    ok('la hoja se cierra',          empty($FG['hoja']), json_encode($FG));
    ok('sin moverse de su publicación', (int)($FG['n'] ?? 0) === $POS);
    ok('y la tarjeta enseña la nueva',
       mb_strpos((string)($FG['linea'] ?? ''), '10:00') !== false
       || mb_stripos((string)($FG['cuandoTx'] ?? ''), 'Se publicará') !== false,
       json_encode($FG));
    $FR = $leer('FECHA.TRAS_RECARGA');
    ok('al recargar sigue puesta y en su sitio', (int)($FR['n'] ?? 0) === $POS, json_encode($FR));

    ok('y cambiar la fecha tampoco llamó a nadie', $cnt('crecer_ia_log') === $ia0);

    // ── LA MEDIDA ────────────────────────────────────────────────
    echo "\n  — 360, 414 y 1440 —\n";
    foreach ([['MENU', 'el menú @360'], ['TEXTO.HOJA', 'el texto @360'],
              ['FECHA.HOJA', 'la fecha @360'], ['MENU.MED_414', 'el menú @414'],
              ['MENU.MED_1440', 'el menú @1440']] as [$k, $como]) {
        $m = $leer($k);
        ok("$como · sin desbordar a lo ancho", (int)($m['horiz'] ?? 1) === 0,
           'sobran ' . ($m['horiz'] ?? '?') . 'px');
        ok("$como · nada por debajo de 44px", empty($m['chicos']), json_encode($m['chicos'] ?? []));
        ok("$como · nada por debajo de 14px", empty($m['finos']), json_encode($m['finos'] ?? []));
        ok("$como · una sola primaria",       (int)($m['primarias'] ?? 9) <= 1,
           json_encode($m['primarias'] ?? null));
    }

    echo "\n  — la pantalla no grita —\n";
    ok('cero alert()', (string)($R['ALERTAS'] ?? '1') === '0', (string)($R['ALERTAS'] ?? ''));
    ok('cero errores en consola',
       in_array(trim((string)($R['ERRORES'] ?? '[]')), ['[]', ''], true), (string)($R['ERRORES'] ?? ''));
    echo "\n  capturas en tests/_capturas/edicion/\n";

} catch (Throwable $e) {
    $fallos++; echo "\n  EXCEPCION · " . get_class($e) . ': ' . $e->getMessage()
                  . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    foreach ($limpiar as $mid) { try { Fixture::limpiar($pdo, $mid); } catch (Throwable $e) {} }
}

echo "\n  — el costo —\n";
ok('cero llamadas al modelo en toda la suite', $cnt('crecer_ia_log') === $g['ia'],
   'antes ' . $g['ia'] . ' · ahora ' . $cnt('crecer_ia_log'));
ok('cero asientos de cuota', $cnt('crecer_img_cuota_asiento') === $g['cuota']);

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  SE AJUSTA Y SE VUELVE · $n afirmaciones\n\n"
    : "  $fallos FALLAS de $n\n\n";
exit($fallos === 0 ? 0 : 1);
