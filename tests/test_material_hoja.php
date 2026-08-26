<?php
// ============================================================
//  CRECER — LA HOJA DE «IMAGEN O VIDEO»
//  tests/test_material_hoja.php
//
//  LO QUE PRUEBA. La hoja abre con una frase que redacta el servidor y ofrece
//  tres caminos. Si esa frase miente —dice «arte del corillo» sobre la foto que
//  el dueño acaba de poner— la decisión se toma con datos falsos, y eso es peor
//  que no decir nada.
//
//  Y prueba la regla que separa las dos mitades de una subida: el archivo entra
//  en la Biblioteca SIEMPRE, y ponerlo en la publicación es la decisión de
//  después. Si van juntas, un formato que no cabe se lleva por delante el
//  archivo que acaba de subir por datos móviles.
//
//  CERO PROVEEDOR. Aquí no se genera nada: ni una llamada al modelo, ni una
//  unidad de cuota. Se cuenta al principio y al final.
// ============================================================

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/material.php';
require_once __DIR__ . '/../includes/meta_semana.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nLA HOJA DE IMAGEN O VIDEO\n" . str_repeat('=', 58) . "\n";

echo "\n  — la red, cerrada por construcción —\n";
ok('el modo prueba está puesto', defined('CRECER_TEST_MODE') && CRECER_TEST_MODE);
ok('y sin transporte falso declarado',
   !defined('CRECER_TEST_RED_FALSA') || !CRECER_TEST_RED_FALSA,
   'esta suite no ejercita ningún proveedor: no debe necesitar un doble');

$cnt = fn(string $t, string $w = '1') => (int)$GLOBALS['pdo']->query(
    "SELECT COUNT(*) FROM {$t} WHERE {$w}")->fetchColumn();
$g = ['ia' => $cnt('crecer_ia_log'), 'cuota' => $cnt('crecer_img_cuota_asiento')];

/** El PNG más pequeño que finfo reconoce como imagen de verdad. */
function png_minimo(): string {
    return base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
}

/** Una sesión de fichero con ese usuario dentro. */
function sesion(int $usuario_id): string {
    $sid  = 'mh' . bin2hex(random_bytes(7));
    $ruta = session_save_path() ?: sys_get_temp_dir();
    file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
                      'usuario_id|i:' . $usuario_id . ';');
    return $sid;
}
function csrf_de(string $sid, int $marca = 0): string {
    $c = stream_context_create(['http' => [
        'header' => "Cookie: PHPSESSID={$sid}\r\n", 'timeout' => 25, 'ignore_errors' => true]]);
    @file_get_contents('http://localhost/crecer/panel/aprobar2.php'
        . ($marca > 0 ? '?marca=' . $marca : ''), false, $c);
    $ruta = (session_save_path() ?: sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'sess_' . $sid;
    return preg_match('~csrf\|s:\d+:"([0-9a-f]+)"~', (string)@file_get_contents($ruta), $m) ? $m[1] : '';
}
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
/** Un POST multipart, que es como sube de verdad un teléfono. */
function aprobar2_archivo(string $sid, int $marca, array $campos,
                          string $campo, string $nombre, string $bytes, string $mime): array {
    $b = '----crecer' . bin2hex(random_bytes(8));
    $body = '';
    foreach ($campos + ['ajax' => 1] as $k => $v) {
        $body .= "--{$b}\r\nContent-Disposition: form-data; name=\"{$k}\"\r\n\r\n{$v}\r\n";
    }
    $body .= "--{$b}\r\nContent-Disposition: form-data; name=\"{$campo}\"; filename=\"{$nombre}\"\r\n"
           . "Content-Type: {$mime}\r\n\r\n" . $bytes . "\r\n--{$b}--\r\n";
    $c = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Cookie: PHPSESSID={$sid}\r\nContent-Type: multipart/form-data; boundary={$b}\r\n",
        'content' => $body, 'timeout' => 40, 'ignore_errors' => true]]);
    $raw = (string)@file_get_contents(
        'http://localhost/crecer/panel/aprobar2.php?marca=' . $marca, false, $c);
    return [json_decode($raw, true) ?: [], $raw];
}

$limpiar = [];
try {
    // ══════════════════════════════════════════════════════════════
    //  1 · LA FRASE DICE LA VERDAD DE LO QUE HAY
    // ══════════════════════════════════════════════════════════════
    echo "\n  — la hoja abre diciendo qué lleva ahora —\n";

    $fx = Fixture::crear($pdo, 'hoja', false, 'admin');
    $limpiar[] = $M = (int)$fx['marca_id'];

    $ins = $pdo->prepare("INSERT INTO crecer_contenido
            (marca_id,plataforma,tipo,caption,estado,fecha_programada,grafica_path)
          VALUES (?, 'instagram',?,?, ?, DATE_ADD(NOW(), INTERVAL 2 DAY), ?)");
    $leer = fn(int $c) => $pdo->query(
        "SELECT id,tipo,estado,grafica_path FROM crecer_contenido WHERE id={$c}")
        ->fetch(PDO::FETCH_ASSOC);

    //  a · sin nada
    $ins->execute([$M, 'post', '[prueba] Sin imagen.', 'borrador', '']);
    $C0 = (int)$pdo->lastInsertId();
    $m0 = semana_material($pdo, $M, $leer($C0));
    ok('sin imagen lo dice',            $m0['frase'] === 'Todavía no tiene imagen.', $m0['frase']);
    ok('y no afirma que haya nada',     $m0['hay'] === false);
    ok('un post no admite video',       $m0['admite_video'] === false);
    ok('y se puede tocar',              $m0['editable'] === true);

    //  b · con arte generado (grafica_path lleno, sin traza)
    $ins->execute([$M, 'post', '[prueba] Arte del corillo.', 'borrador',
                   '/crecer/uploads/marca_x/generado.png']);
    $C1 = (int)$pdo->lastInsertId();
    $m1 = semana_material($pdo, $M, $leer($C1));
    ok('el arte generado se dice como es', $m1['frase'] === 'Ahora lleva arte del corillo.', $m1['frase']);
    ok('y sí hay algo puesto',             $m1['hay'] === true);

    //  c · con una foto SUYA aplicada: la frase la nombra
    $act = $pdo->prepare("INSERT INTO crecer_activos
            (marca_id,tipo,archivo,nombre,mime,bytes,origen,estado)
          VALUES (?,?,?,?,?,?,?, 'activo')");
    $act->execute([$M, 'imagen', "marca_{$M}/biblioteca/suya.jpg",
                   '[prueba] Su bizcocho', 'image/jpeg', 1234, 'subido']);
    $FOTO = (int)$pdo->lastInsertId();
    $act->execute([$M, 'video', "marca_{$M}/biblioteca/suyo.mp4",
                   '[prueba] Su horno', 'video/mp4', 99000, 'subido']);
    $VIDEO = (int)$pdo->lastInsertId();

    material_aplicar($pdo, $M, $C1, $FOTO);
    $m2 = semana_material($pdo, $M, $leer($C1));
    ok('la foto suya se nombra',
       $m2['frase'] === 'Ahora lleva tu foto: «[prueba] Su bizcocho».', $m2['frase']);
    ok('y se sabe cuál es',    (int)$m2['activo_id'] === $FOTO);
    ok('el origen es su biblioteca', $m2['origen'] === 'biblioteca');

    //  d · Y SI ENCIMA CAE ARTE GENERADO, la frase cambia con ella. Esta es la
    //     mentira que la traza puede contar: soltarla es lo que la evita.
    material_soltar($pdo, $M, $C1);
    $m3 = semana_material($pdo, $M, $leer($C1));
    ok('al soltar deja de decir «tu foto»',
       $m3['frase'] === 'Ahora lleva arte del corillo.', $m3['frase']);

    //  e · un reel sí admite video
    $ins->execute([$M, 'reel', '[prueba] Un reel.', 'borrador', '']);
    $CR = (int)$pdo->lastInsertId();
    $mr = semana_material($pdo, $M, $leer($CR));
    ok('un reel admite video',       $mr['admite_video'] === true);
    ok('y lo dice sin imagen ni video',
       $mr['frase'] === 'Todavía no tiene imagen ni video.', $mr['frase']);

    //  f · lo que ya salió no se toca
    $pdo->prepare("UPDATE crecer_contenido SET estado='publicado' WHERE id=?")->execute([$C0]);
    ok('una publicada no es editable', semana_material($pdo, $M, $leer($C0))['editable'] === false,
       'la hoja no puede ofrecer una puerta que material_aplicar() va a cerrar');
    $pdo->prepare("UPDATE crecer_contenido SET estado='borrador' WHERE id=?")->execute([$C0]);

    //  g · sin pieza no se inventa nada
    $mv = semana_material($pdo, $M, null);
    ok('sin pieza no afirma nada', $mv['frase'] === '' && $mv['editable'] === false);

    // ══════════════════════════════════════════════════════════════
    //  2 · LAS DOS MITADES DE UNA SUBIDA (por HTTP, como el teléfono)
    // ══════════════════════════════════════════════════════════════
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
    if (@file_get_contents('http://localhost/crecer/login.php', false, $ctx) === false) {
        echo "\n  (el servidor local no responde · se salta la parte HTTP)\n";
    } else {
        echo "\n  — subir y usar son dos decisiones —\n";
        $sid  = sesion((int)$fx['usuario_id']);
        $csrf = csrf_de($sid, $M);
        ok('hay sesión con csrf', $csrf !== '');

        //  a · SUBIR SIN APLICAR: el archivo es suyo y la pieza no se toca.
        $antes = (string)$pdo->query("SELECT grafica_path FROM crecer_contenido WHERE id={$C0}")
                             ->fetchColumn();
        [$r] = aprobar2_archivo($sid, $M,
            ['accion' => 'foto_directa', 'id' => $C0, 'csrf' => $csrf, 'solo_subir' => '1'],
            'imagen', 'suya.png', png_minimo(), 'image/png');
        ok('la subida sale bien',      !empty($r['ok']), json_encode($r));
        ok('y NO se aplicó',           isset($r['aplicado']) && $r['aplicado'] === false, json_encode($r));
        ok('la publicación no cambió',
           (string)$pdo->query("SELECT grafica_path FROM crecer_contenido WHERE id={$C0}")
                       ->fetchColumn() === $antes,
           'subir no es poner: el dueño todavía no ha dicho que sí');
        $SUB = (int)($r['activo_id'] ?? 0);
        ok('pero ya está en su Biblioteca',
           $SUB > 0 && (int)$pdo->query("SELECT COUNT(*) FROM crecer_activos
                          WHERE id={$SUB} AND marca_id={$M} AND estado='activo'")->fetchColumn() === 1);

        //  b · Y LA SEGUNDA MITAD: usarlo.
        [$u] = aprobar2($sid, $M, ['accion' => 'usar_activo', 'id' => $C0,
                                   'csrf' => $csrf, 'activo_id' => $SUB]);
        ok('usarlo sale bien',   !empty($u['ok']), json_encode($u));
        ok('ahora sí cambió la publicación',
           (string)$pdo->query("SELECT grafica_path FROM crecer_contenido WHERE id={$C0}")
                       ->fetchColumn() !== $antes);
        ok('y queda trazado',
           (int)$pdo->query("SELECT material_activo_id FROM crecer_contenido WHERE id={$C0}")
                    ->fetchColumn() === $SUB);

        //  c · SIN CSRF NO SE ESCRIBE. Toda escritura pasa por el candado.
        [$sc] = aprobar2($sid, $M, ['accion' => 'usar_activo', 'id' => $C0, 'activo_id' => $FOTO]);
        ok('sin csrf no se aplica', empty($sc['ok']), json_encode($sc));
        ok('y la publicación sigue igual',
           (int)$pdo->query("SELECT material_activo_id FROM crecer_contenido WHERE id={$C0}")
                    ->fetchColumn() === $SUB);

        //  d · UN VIDEO EN UN POST NO CABE, y se dice — el dominio, no la hoja.
        [$vd] = aprobar2($sid, $M, ['accion' => 'usar_activo', 'id' => $C0,
                                    'csrf' => $csrf, 'activo_id' => $VIDEO]);
        ok('un video en un post se rechaza', empty($vd['ok']), json_encode($vd));
        ok('y el post conserva su foto',
           (int)$pdo->query("SELECT material_activo_id FROM crecer_contenido WHERE id={$C0}")
                    ->fetchColumn() === $SUB,
           'un rechazo no puede dejar la pieza peor de como estaba');

        //  e · LO QUE YA SALIO, NI POR HTTP.
        $pdo->prepare("UPDATE crecer_contenido SET estado='publicado' WHERE id=?")->execute([$C0]);
        [$pb] = aprobar2($sid, $M, ['accion' => 'usar_activo', 'id' => $C0,
                                    'csrf' => $csrf, 'activo_id' => $FOTO]);
        ok('una publicada no se cambia', empty($pb['ok']), json_encode($pb));
        $pdo->prepare("UPDATE crecer_contenido SET estado='borrador' WHERE id=?")->execute([$C0]);

        //  f · Y DE OTRA MARCA, NADA. Aquí el dueño es de otra cuenta, no admin:
        //     un admin ve cualquier marca por diseño (auth.php) y probarlo con
        //     dos admins no probaría nada.
        $fy = Fixture::crear($pdo, 'hojaX', false, 'cliente');
        $limpiar[] = $MX = (int)$fy['marca_id'];
        $sx = sesion((int)$fy['usuario_id']);
        $cx = csrf_de($sx, $MX);
        [$aj] = aprobar2($sx, $MX, ['accion' => 'usar_activo', 'id' => $C0,
                                    'csrf' => $cx, 'activo_id' => $FOTO]);
        ok('otra marca no puede tocar esta pieza', empty($aj['ok']), json_encode($aj));
    }

} catch (Throwable $e) {
    $fallos++; echo "\n  EXCEPCION · " . get_class($e) . ': ' . $e->getMessage()
                  . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    foreach ($limpiar as $mid) { try { Fixture::limpiar($pdo, $mid); } catch (Throwable $e) {} }
    echo "\n  (fixtures limpiadas)\n";
}

echo "\n  — el costo —\n";
ok('cero llamadas al modelo', $cnt('crecer_ia_log') === $g['ia'],
   'antes ' . $g['ia'] . ' · ahora ' . $cnt('crecer_ia_log'));
ok('cero asientos de cuota',  $cnt('crecer_img_cuota_asiento') === $g['cuota'],
   'poner una foto que ya es suya no cuesta una imagen');

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  LA HOJA DICE LA VERDAD · $n afirmaciones\n\n"
    : "  $fallos FALLAS de $n\n\n";
exit($fallos === 0 ? 0 : 1);
