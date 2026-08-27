<?php
// ============================================================
//  CRECER — AJUSTAR EL TEXTO Y LA FECHA (Fase 2A)
//  tests/test_edicion_texto_fecha.php
//
//  EL CONTRATO. Cambiar lo que dice una publicación y cuándo sale son las dos
//  cosas que el dueño va a hacer todos los días. Tienen que ser gratis, exactas
//  y reversibles:
//
//   · GUARDAR EL TEXTO CAMBIA EL TEXTO. Y nada más. No llama a nadie, no gasta
//     cuota, no toca la fecha ni la imagen. Hoy no es así: el handler `editar`
//     llama a `aprender_de_edicion()` en cada guardado — una llamada al modelo
//     por cada coma que el dueño corrija. Aprender está bien; hacerlo dentro
//     del guardado, no: encarece lo más frecuente y ata una escritura barata a
//     una llamada de red que puede fallar.
//
//   · LA FECHA NO ACEPTA CUALQUIER COSA. Hoy `fecha` solo pide que
//     `strtotime()` entienda la cadena: una fecha del año pasado entra igual, y
//     una pieza que ya está saliendo se deja «reprogramar» sin que eso
//     signifique nada. Prometer que algo saldrá el martes cuando ya salió el
//     lunes es la peor clase de mentira: la que el dueño descubre solo.
//
//  ══ RED CERRADA POR CONSTRUCCION ══ `_sin_gasto.php` define CRECER_TEST_MODE
//  y los cuatro puntos de proveedor lanzan antes del curl. Además se cuentan
//  las filas de crecer_ia_log: con el modelo en mock igual dejarían rastro, y
//  el contrato dice CERO llamadas — no «llamadas baratas».
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

echo "\nAJUSTAR EL TEXTO Y LA FECHA\n" . str_repeat('=', 58) . "\n";

echo "\n  — la red, cerrada por construcción —\n";
ok('el modo prueba está puesto', defined('CRECER_TEST_MODE') && CRECER_TEST_MODE);
ok('y sin transporte falso declarado',
   !defined('CRECER_TEST_RED_FALSA') || !CRECER_TEST_RED_FALSA,
   'esta suite no ejercita ningún proveedor: no debe necesitar un doble');

$ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
if (@file_get_contents('http://localhost/crecer/login.php', false, $ctx) === false) {
    echo "\n  SALTADO · el servidor local no responde\n\n"; exit(0);
}

$cnt = fn(string $t, string $w = '1') => (int)$GLOBALS['pdo']->query(
    "SELECT COUNT(*) FROM {$t} WHERE {$w}")->fetchColumn();
$g = ['ia' => $cnt('crecer_ia_log'), 'cuota' => $cnt('crecer_img_cuota_asiento')];

function sesion(int $usuario_id): string {
    $sid  = 'ed' . bin2hex(random_bytes(7));
    $ruta = session_save_path() ?: sys_get_temp_dir();
    file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
                      'usuario_id|i:' . $usuario_id . ';');
    return $sid;
}
/**
 * El csrf de esa sesion. OJO AL ORDEN: el token no existe hasta que una pagina
 * lo acuña —csrf_token() lo crea la primera vez que se pinta algo—, asi que
 * primero se pide una pagina con esa cookie y despues se lee del fichero de
 * sesion. Leerlo antes devolvia vacio y toda la suite salia en rojo por
 * «sesion expirada», que era un defecto de la prueba y no del producto.
 */
function csrf_de(string $sid, int $marca = 0): string {
    $c = stream_context_create(['http' => [
        'header' => "Cookie: PHPSESSID={$sid}
",
        'timeout' => 25, 'ignore_errors' => true]]);
    @file_get_contents('http://localhost/crecer/panel/aprobar2.php'
        . ($marca > 0 ? '?marca=' . $marca : ''), false, $c);
    $ruta = (session_save_path() ?: sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'sess_' . $sid;
    return preg_match('~csrf\|s:\d+:"([0-9a-f]+)"~', (string)@file_get_contents($ruta), $m) ? $m[1] : '';
}
/** Un POST a aprobar2.php como lo hace la hoja de Ajustar. */
function aprobar2(string $sid, int $marca, array $campos): array {
    $c = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Cookie: PHPSESSID={$sid}\r\nContent-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query($campos + ['ajax' => 1]),
        'timeout' => 30, 'ignore_errors' => true]]);
    $raw = (string)@file_get_contents(
        'http://localhost/crecer/panel/aprobar2.php?marca=' . $marca, false, $c);
    return [json_decode($raw, true) ?: [], $raw];
}

/** Una marca con una publicación en borrador, lista para ajustarse. */
function montar(PDO $pdo, string $etq): array {
    $fx = Fixture::crear($pdo, $etq, true, 'admin');
    $M  = (int)$fx['marca_id'];
    $meta = meta_activa($pdo, $M);
    $plan = meta_plan_activo($pdo, (int)$meta['id']);
    $pdo->prepare("UPDATE crecer_meta_tactica SET semana=9, estado='hecha' WHERE meta_id=?")
        ->execute([(int)$meta['id']]);
    foreach ($fx['piezas'] as $p) {
        $pdo->prepare("UPDATE crecer_contenido SET tactica_id=NULL WHERE id=?")->execute([(int)$p]);
    }
    $pdo->prepare("INSERT INTO crecer_meta_tactica
            (meta_id, plan_id, marca_id, orden, semana, tipo, titulo, por_que,
             clase, quien, estado, piezas_meta, formato)
          VALUES (?,?,?,1,1,'contenido',?,?, 'produccion','corillo','pendiente',1,'post')")
        ->execute([(int)$meta['id'], (int)$plan['id'], $M,
                   '[prueba] El post que se va a ajustar', 'así la gente sabe qué pedir']);
    $T = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO crecer_contenido
            (marca_id,plataforma,tipo,caption,estado,meta_id,plan_id,tactica_id,
             fecha_programada,grafica_path)
          VALUES (?, 'instagram','post',?, 'borrador',?,?,?, DATE_ADD(NOW(), INTERVAL 2 DAY), ?)")
        ->execute([$M, '[prueba] El texto original, tal como lo escribió el corillo.',
                   (int)$meta['id'], (int)$plan['id'], $T,
                   '/crecer/assets/brand/crecer-icon.png']);
    return [$fx, $meta, $plan, $T, (int)$pdo->lastInsertId(), sesion((int)$fx['usuario_id'])];
}

$limpiar = [];
try {
    // ══════════════════════════════════════════════════════════════
    //  1 · GUARDAR EL TEXTO · gratis, exacto y sin efectos laterales
    // ══════════════════════════════════════════════════════════════
    echo "\n  — cambiar lo que dice la publicación —\n";
    [$fx, $meta, $plan, $T, $C, $sid] = montar($pdo, 'edA');
    $limpiar[] = $M = (int)$fx['marca_id'];
    $csrf = csrf_de($sid, $M);
    ok('la sesión tiene csrf', $csrf !== '');

    $antes = $pdo->query("SELECT caption, fecha_programada, grafica_path, estado
                            FROM crecer_contenido WHERE id={$C}")->fetch(PDO::FETCH_ASSOC);
    $ia0    = $cnt('crecer_ia_log');
    $cuota0 = $cnt('crecer_img_cuota_asiento');

    $NUEVO = '[prueba] Lo que el dueño quiso decir, con sus palabras.';
    [$r] = aprobar2($sid, $M, ['accion' => 'editar', 'id' => $C,
                               'caption' => $NUEVO, 'csrf' => $csrf]);
    ok('el servidor dice que sí', !empty($r['ok']), json_encode($r));

    $despues = $pdo->query("SELECT caption, fecha_programada, grafica_path, estado
                              FROM crecer_contenido WHERE id={$C}")->fetch(PDO::FETCH_ASSOC);
    ok('el texto cambió',        (string)$despues['caption'] === $NUEVO, (string)$despues['caption']);
    ok('la fecha NO se movió',   (string)$despues['fecha_programada'] === (string)$antes['fecha_programada']);
    ok('la imagen NO se tocó',   (string)$despues['grafica_path'] === (string)$antes['grafica_path']);
    ok('y el estado sigue igual',(string)$despues['estado'] === (string)$antes['estado']);

    //  EL CORAZON DE ESTA FASE: guardar texto es una escritura, no una llamada.
    ok('guardar el texto NO llamó a nadie', $cnt('crecer_ia_log') === $ia0,
       'antes ' . $ia0 . ' · ahora ' . $cnt('crecer_ia_log')
       . ' — aprender está bien; hacerlo dentro del guardado, no');
    ok('y no gastó cuota', $cnt('crecer_img_cuota_asiento') === $cuota0);

    // ── CANCELAR NO ESCRIBE ──────────────────────────────────────
    echo "\n  — cancelar no deja rastro —\n";
    //  Cancelar en la hoja es no enviar nada. Se comprueba que el estado de la
    //  pieza es idéntico tras «abrir y cerrar» — es decir, tras no hacer nada.
    $antes2 = $pdo->query("SELECT * FROM crecer_contenido WHERE id={$C}")->fetch(PDO::FETCH_ASSOC);
    $ia1 = $cnt('crecer_ia_log');
    ok('mirar la publicación no la cambia',
       (string)$antes2['caption'] === $NUEVO && $cnt('crecer_ia_log') === $ia1);

    // ── LO QUE NO SE PUEDE HACER ─────────────────────────────────
    echo "\n  — sin CSRF y sin ser tuya, no se escribe —\n";
    [$mal] = aprobar2($sid, $M, ['accion' => 'editar', 'id' => $C,
                                 'caption' => '[prueba] SECUESTRADO', 'csrf' => 'no-vale']);
    ok('un CSRF inválido no escribe', empty($mal['ok']), json_encode($mal));
    ok('y el texto sigue siendo el suyo',
       (string)$pdo->query("SELECT caption FROM crecer_contenido WHERE id={$C}")
                   ->fetchColumn() === $NUEVO);

    $fo = Fixture::crear($pdo, 'edX', false, 'proveedor');
    $limpiar[] = $MX = (int)$fo['marca_id'];
    $sid_x = sesion((int)$fo['usuario_id']);
    [$aj] = aprobar2($sid_x, $MX, ['accion' => 'editar', 'id' => $C,
                                   'caption' => '[prueba] DE OTRO', 'csrf' => csrf_de($sid_x, $MX)]);
    ok('otra marca no puede editar esta pieza',
       (string)$pdo->query("SELECT caption FROM crecer_contenido WHERE id={$C}")
                   ->fetchColumn() === $NUEVO, json_encode($aj));

    // ══════════════════════════════════════════════════════════════
    //  2 · LA FECHA · con consecuencia, y sin promesas falsas
    // ══════════════════════════════════════════════════════════════
    echo "\n  — cambiar cuándo sale —\n";
    $ia2 = $cnt('crecer_ia_log');
    $cap_antes = (string)$pdo->query("SELECT caption FROM crecer_contenido WHERE id={$C}")->fetchColumn();
    $futuro = date('Y-m-d H:i:s', strtotime('+5 days 10:00'));
    [$rf] = aprobar2($sid, $M, ['accion' => 'fecha', 'id' => $C,
                                'fecha' => $futuro, 'csrf' => $csrf]);
    ok('el servidor la acepta', !empty($rf['ok']), json_encode($rf));
    ok('y quedó guardada',
       (string)$pdo->query("SELECT fecha_programada FROM crecer_contenido WHERE id={$C}")
                   ->fetchColumn() === $futuro);
    ok('sin tocar el texto',
       (string)$pdo->query("SELECT caption FROM crecer_contenido WHERE id={$C}")
                   ->fetchColumn() === $cap_antes);
    ok('y sin llamar a nadie', $cnt('crecer_ia_log') === $ia2);

    // ── UNA FECHA QUE YA PASÓ NO SE GUARDA ───────────────────────
    echo "\n  — una fecha que ya pasó no es una fecha —\n";
    $pasado = date('Y-m-d H:i:s', strtotime('-3 days 10:00'));
    [$rp] = aprobar2($sid, $M, ['accion' => 'fecha', 'id' => $C,
                                'fecha' => $pasado, 'csrf' => $csrf]);
    ok('el servidor la rechaza', empty($rp['ok']), json_encode($rp));
    ok('y lo dice en cristiano',
       mb_stripos((string)($rp['err'] ?? ''), 'pas') !== false
       || mb_stripos((string)($rp['err'] ?? ''), 'futuro') !== false,
       (string)($rp['err'] ?? ''));
    ok('la fecha buena sigue puesta',
       (string)$pdo->query("SELECT fecha_programada FROM crecer_contenido WHERE id={$C}")
                   ->fetchColumn() === $futuro,
       'rechazar mal escrito no puede borrar lo que ya estaba bien');

    //  Y una cadena que no es una fecha, tampoco.
    [$rb] = aprobar2($sid, $M, ['accion' => 'fecha', 'id' => $C,
                                'fecha' => 'el martes por la tarde', 'csrf' => $csrf]);
    ok('una fecha ininteligible tampoco entra', empty($rb['ok']), json_encode($rb));

    // ── UNA PIEZA QUE YA SALIÓ NO SE REPROGRAMA ──────────────────
    echo "\n  — lo que ya salió no se puede mover —\n";
    foreach (['publicando', 'publicado'] as $est) {
        $pdo->prepare("UPDATE crecer_contenido SET estado=? WHERE id=?")->execute([$est, $C]);
        $f_antes = (string)$pdo->query("SELECT fecha_programada FROM crecer_contenido WHERE id={$C}")
                               ->fetchColumn();
        [$re] = aprobar2($sid, $M, ['accion' => 'fecha', 'id' => $C,
                                    'fecha' => date('Y-m-d H:i:s', strtotime('+9 days 10:00')),
                                    'csrf' => $csrf]);
        ok("una pieza «{$est}» no se reprograma", empty($re['ok']), json_encode($re));
        ok("y su fecha no se movió",
           (string)$pdo->query("SELECT fecha_programada FROM crecer_contenido WHERE id={$C}")
                       ->fetchColumn() === $f_antes);
        ok("se le dice por qué, sin tripas",
           mb_strlen((string)($re['err'] ?? '')) > 5
           && mb_stripos((string)($re['err'] ?? ''), 'sql') === false,
           (string)($re['err'] ?? ''));
    }
    $pdo->prepare("UPDATE crecer_contenido SET estado='borrador' WHERE id=?")->execute([$C]);

    // ══════════════════════════════════════════════════════════════
    //  3 · LA CONSECUENCIA, DICHA ANTES DE PULSAR
    // ══════════════════════════════════════════════════════════════
    echo "\n  — el dueño sabe qué va a pasar —\n";
    ok('existe la frase de la consecuencia', function_exists('semana_frase_cuando'),
       'la hoja no puede redactarla por su cuenta: se dice una vez, en el dominio');
    if (function_exists('semana_frase_cuando')) {
        $fr = semana_frase_cuando(date('Y-m-d H:i:s', strtotime('next tuesday 10:00')));
        ok('nombra el día',   mb_stripos($fr, 'martes') !== false, $fr);
        ok('y la hora',       mb_strpos($fr, '10') !== false, $fr);
        ok('en futuro, que es lo que va a pasar',
           mb_stripos($fr, 'publicar') !== false || mb_stripos($fr, 'sale') !== false, $fr);
        ok('sin fecha, no promete nada', semana_frase_cuando(null) === '');
    }

    // ══════════════════════════════════════════════════════════════
    //  4 · LA HOJA NO LLAMA A NADIE POR ABRIRSE
    // ══════════════════════════════════════════════════════════════
    echo "\n  — abrir una capa no cuesta —\n";
    $sm = (string)file_get_contents(__DIR__ . '/../panel/_meta_semana.php');
    $sm_cod = (string)preg_replace(['~/\*[\s\S]*?\*/~', '~^\s*//[^\n]*$~m'], ' ', $sm);
    ok('la hoja de ajustar no pide nada al abrirse',
       preg_match('~function menuAjustar[\s\S]{0,900}?fetch\(~', $sm_cod) !== 1,
       'el menú se pinta con lo que ya está en la tarjeta');
    ok('ni la de texto',
       preg_match('~function editarTexto[\s\S]{0,700}?fetch\(~', $sm_cod) !== 1);
    ok('ni la de fecha',
       preg_match('~function editarFecha[\s\S]{0,700}?fetch\(~', $sm_cod) !== 1);
    ok('y ninguna usa alert()', mb_strpos($sm_cod, 'alert(') === false);

} catch (Throwable $e) {
    $fallos++; echo "\n  EXCEPCION · " . get_class($e) . ': ' . $e->getMessage()
                  . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    foreach ($limpiar as $mid) { try { Fixture::limpiar($pdo, $mid); } catch (Throwable $e) {} }
    echo "\n  (fixtures limpiadas)\n";
}

echo "\n  — el costo —\n";
ok('cero llamadas al modelo en toda la suite', $cnt('crecer_ia_log') === $g['ia'],
   'antes ' . $g['ia'] . ' · ahora ' . $cnt('crecer_ia_log'));
ok('cero asientos de cuota', $cnt('crecer_img_cuota_asiento') === $g['cuota']);

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  AJUSTAR ES BARATO Y EXACTO · $n afirmaciones\n\n"
    : "  $fallos FALLAS de $n\n\n";
exit($fallos === 0 ? 0 : 1);
