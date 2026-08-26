<?php
// ============================================================
//  CRECER — LA SELECCION SIN JAVASCRIPT, Y LOS BORDES DE LA SUBIDA
//  tests/test_material_sin_js.php
//
//  POR QUE SIN JAVASCRIPT. La primera version del selector era un boton que
//  esperaba a un `fetch`: sin JS no habia forma de escoger nada. Eso no es un
//  detalle de purista — el telefono de la repostera es un Android de gama baja
//  con la señal justa, y ahi el JS falla de verdad. Un selector que solo existe
//  en JavaScript es un selector que no existe cuando falla el JavaScript.
//
//  Aqui se prueba lo que hace un navegador SIN JS: manda el formulario tal cual,
//  con su radio y su csrf, y sigue el redirect. Si eso funciona, la capa de JS
//  es lo que dice ser — una mejora, no el producto.
//
//  Y DE PASO, LOS BORDES DE LA SUBIDA. Un MIME mentido, un nombre con truco, un
//  archivo enorme, el doble envio, y la regla de que cancelar NO le quita al
//  dueño lo que acaba de subir.
//
//  CERO PROVEEDOR: nada de esto genera imagen. Subir y aplicar no llaman a
//  nadie. Se cuenta al final.
// ============================================================

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/material.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nESCOGER SIN JAVASCRIPT, Y LOS BORDES DE SUBIR\n" . str_repeat('=', 58) . "\n";

$cnt = fn(string $t, string $w = '1') => (int)$GLOBALS['pdo']->query(
    "SELECT COUNT(*) FROM {$t} WHERE {$w}")->fetchColumn();
$g = ['ia' => $cnt('crecer_ia_log'), 'cuota' => $cnt('crecer_img_cuota_asiento')];

$ctx0 = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
if (@file_get_contents('http://localhost/crecer/login.php', false, $ctx0) === false) {
    echo "\n  SALTADA · el servidor local no responde\n\n"; exit(0);
}

const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

function sesion(int $usuario_id): string {
    $sid  = 'nj' . bin2hex(random_bytes(7));
    $ruta = session_save_path() ?: sys_get_temp_dir();
    file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
                      'usuario_id|i:' . $usuario_id . ';');
    return $sid;
}
/** GET con esa sesión. Devuelve [cuerpo, cabeceras]. */
function pedir(string $sid, string $url): array {
    $c = stream_context_create(['http' => [
        'header' => "Cookie: PHPSESSID={$sid}\r\n", 'timeout' => 25,
        'follow_location' => 0, 'ignore_errors' => true]]);
    $cuerpo = (string)@file_get_contents($url, false, $c);
    return [$cuerpo, $http_response_header ?? []];
}
/** POST de formulario, como lo manda un navegador SIN JavaScript. */
function enviar(string $sid, string $url, array $campos): array {
    $c = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Cookie: PHPSESSID={$sid}\r\nContent-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query($campos),
        'timeout' => 30, 'follow_location' => 0, 'ignore_errors' => true]]);
    $cuerpo = (string)@file_get_contents($url, false, $c);
    return [$cuerpo, $http_response_header ?? []];
}
/** POST multipart con un archivo, como lo manda un teléfono. */
function enviar_archivo(string $sid, string $url, array $campos,
                        string $campo, string $nombre, string $bytes, string $mime): array {
    $b = '----crecer' . bin2hex(random_bytes(8));
    $body = '';
    foreach ($campos as $k => $v) {
        $body .= "--{$b}\r\nContent-Disposition: form-data; name=\"{$k}\"\r\n\r\n{$v}\r\n";
    }
    $body .= "--{$b}\r\nContent-Disposition: form-data; name=\"{$campo}\"; filename=\"{$nombre}\"\r\n"
           . "Content-Type: {$mime}\r\n\r\n" . $bytes . "\r\n--{$b}--\r\n";
    $c = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Cookie: PHPSESSID={$sid}\r\nContent-Type: multipart/form-data; boundary={$b}\r\n",
        'content' => $body, 'timeout' => 60, 'follow_location' => 0, 'ignore_errors' => true]]);
    $raw = (string)@file_get_contents($url, false, $c);
    return [json_decode($raw, true) ?: [], $raw];
}
/**
 * EL TOKEN QUE VALE AHORA MISMO.
 *
 * OJO AL ORDEN, que ya costo una tanda de rojos: el token no existe hasta que
 * una pagina lo acuña, y puede cambiar entre peticiones. Leerlo UNA VEZ al
 * principio y reusarlo despues daba «la sesion expiro» en todos los POST — un
 * defecto de la prueba que se leia como un defecto del candado.
 */
function token(string $sid): string {
    $ruta = (session_save_path() ?: sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'sess_' . $sid;
    return preg_match('~csrf\|s:\d+:"([0-9a-f]+)"~', (string)@file_get_contents($ruta), $m) ? $m[1] : '';
}
function csrf_de(string $sid, int $marca): string {
    pedir($sid, 'http://localhost/crecer/panel/aprobar2.php?marca=' . $marca);
    return token($sid);
}
function cabecera(array $h, string $clave): string {
    foreach ($h as $l) if (stripos($l, $clave . ':') === 0) return trim(substr($l, strlen($clave) + 1));
    return '';
}
function estado_http(array $h): int {
    return isset($h[0]) && preg_match('~ (\d{3}) ~', $h[0], $m) ? (int)$m[1] : 0;
}

$limpiar = [];
try {
    $fx = Fixture::crear($pdo, 'nojs', false, 'admin');
    $limpiar[] = $M = (int)$fx['marca_id'];
    $sid  = sesion((int)$fx['usuario_id']);
    csrf_de($sid, $M);   // acuña el token en esa sesión

    $act = $pdo->prepare("INSERT INTO crecer_activos
            (marca_id,tipo,archivo,nombre,mime,bytes,origen,estado)
          VALUES (?,?,?,?,?,?, 'subido','activo')");
    $act->execute([$M, 'imagen', "marca_{$M}/biblioteca/nojs.jpg", '[prueba] Su bizcocho', 'image/jpeg', 1234]);
    $FOTO = (int)$pdo->lastInsertId();
    $act->execute([$M, 'video', "marca_{$M}/biblioteca/nojs.mp4", '[prueba] Su horno', 'video/mp4', 9000]);
    $VIDEO = (int)$pdo->lastInsertId();

    $ins = $pdo->prepare("INSERT INTO crecer_contenido
            (marca_id,plataforma,tipo,caption,estado,fecha_programada,grafica_path)
          VALUES (?, 'instagram',?,?, 'borrador', DATE_ADD(NOW(), INTERVAL 2 DAY), '')");
    $ins->execute([$M, 'post', '[prueba] El texto que no se debe tocar.']);
    $C = (int)$pdo->lastInsertId();
    $mat = fn(int $c) => $pdo->query("SELECT material_activo_id FROM crecer_contenido WHERE id={$c}")->fetchColumn();
    $img = fn(int $c) => (string)$pdo->query("SELECT grafica_path FROM crecer_contenido WHERE id={$c}")->fetchColumn();
    $cap = fn(int $c) => (string)$pdo->query("SELECT caption FROM crecer_contenido WHERE id={$c}")->fetchColumn();

    $BIB = "http://localhost/crecer/panel/biblioteca.php?marca={$M}";

    // ══════════════════════════════════════════════════════════════
    //  1 · EL FORMULARIO EXISTE EN EL HTML, sin ejecutar una línea de JS
    // ══════════════════════════════════════════════════════════════
    echo "\n  — el selector está en el HTML, no en el JavaScript —\n";
    [$html] = pedir($sid, $BIB . "&pieza={$C}&volver=meta&pos=2");
    ok('la página responde',        $html !== '');
    //  El token se lee del momento, no del principio: una pagina puede
    //  acuñarlo o rotarlo, y guardarse el primero daba «la sesion expiro».
    ok('la sesión tiene csrf',      token($sid) !== '');
    ok('hay un formulario de verdad',
       str_contains($html, 'id="bibForm"') && str_contains($html, 'method="post"'),
       'un botón que espera a fetch no es un formulario');
    ok('con un radio real por recurso',
       preg_match('~<input type="radio" name="activo_id"[^>]*value="' . $FOTO . '"~', $html) === 1,
       'sin radio no hay nada que enviar sin JS');
    ok('y su csrf dentro',          str_contains($html, 'name="csrf"'));
    ok('la pieza viaja en el formulario', str_contains($html, 'name="pieza" value="' . $C . '"'));
    ok('y la posición también',     str_contains($html, 'value="2"'));

    //  LO QUE NO CABE, NO SE PINTA SELECCIONABLE. Un post no admite video.
    ok('el video no se ofrece para un post',
       preg_match('~<input type="radio" name="activo_id"[^>]*value="' . $VIDEO . '"~', $html) !== 1,
       'ofrecer lo que el servidor va a rechazar es hacerle perder el viaje');

    // ══════════════════════════════════════════════════════════════
    //  2 · Y ENVIARLO FUNCIONA · escoger, aplicar, volver
    // ══════════════════════════════════════════════════════════════
    echo "\n  — y enviarlo funciona: escoger, aplicar, volver —\n";
    [, $h] = enviar($sid, $BIB, ['csrf' => token($sid), 'accion' => 'usar_material',
                                 'pieza' => $C, 'activo_id' => $FOTO, 'pos' => 2]);
    $loc = cabecera($h, 'Location');
    ok('contesta con un redirect',  estado_http($h) >= 300 && estado_http($h) < 400, (string)estado_http($h));
    ok('y la foto quedó aplicada',  (int)$mat($C) === $FOTO, (string)$mat($C));
    ok('con su ruta',               str_contains($img($C), 'nojs.jpg'), $img($C));
    ok('sin tocar el texto',        $cap($C) === '[prueba] El texto que no se debe tocar.');

    //  EL RETORNO ES DEL SERVIDOR, no de la petición.
    echo "\n  — y la vuelta la construye el servidor —\n";
    ok('vuelve a Tu Meta',          str_contains($loc, 'meta.php'), $loc);
    ok('a la misma publicación',    str_contains($loc, 'pos=2'), $loc);
    ok('y es una ruta de aquí',     str_starts_with($loc, '/crecer/') || str_contains($loc, 'localhost/crecer/'),
       'un destino que no empiece por nuestra ruta es un redirect abierto');

    // ══════════════════════════════════════════════════════════════
    //  3 · SEGURIDAD DEL MISMO FORMULARIO
    // ══════════════════════════════════════════════════════════════
    echo "\n  — y el mismo formulario, con malas intenciones —\n";
    $mat_antes = (int)$mat($C);

    //  a · sin csrf
    enviar($sid, $BIB, ['accion' => 'usar_material', 'pieza' => $C, 'activo_id' => $VIDEO, 'pos' => 2]);
    ok('sin csrf no escribe',       (int)$mat($C) === $mat_antes);

    //  b · un recurso de otra marca
    $fy = Fixture::crear($pdo, 'nojsX', false, 'cliente');
    $limpiar[] = $MX = (int)$fy['marca_id'];
    $act->execute([$MX, 'imagen', "marca_{$MX}/biblioteca/ajena.jpg", '[prueba] Ajena', 'image/jpeg', 10]);
    $AJENO = (int)$pdo->lastInsertId();
    enviar($sid, $BIB, ['csrf' => token($sid), 'accion' => 'usar_material',
                        'pieza' => $C, 'activo_id' => $AJENO, 'pos' => 2]);
    //  Y NI SIQUIERA SE ENSEÑA. Que no se pueda aplicar es la mitad; la otra
    //  es que no aparezca en el HTML de nadie mas.
    [$html_x] = pedir($sid, $BIB . "&pieza={$C}&volver=meta&pos=2");
    ok('un recurso de otra marca no sale en el HTML',
       !str_contains($html_x, 'ajena.jpg') && !str_contains($html_x, 'value="' . $AJENO . '"'),
       'la Biblioteca lista por marca: un id de otra cuenta no se pinta ni tapado');
    ok('un recurso de otra marca no entra', (int)$mat($C) === $mat_antes,
       'el dueño de esta marca no puede alcanzar el archivo de otra');

    //  c · un formato que no cabe
    enviar($sid, $BIB, ['csrf' => token($sid), 'accion' => 'usar_material',
                        'pieza' => $C, 'activo_id' => $VIDEO, 'pos' => 2]);
    ok('un video en un post tampoco', (int)$mat($C) === $mat_antes);

    //  d · una posición inventada no manda a ningún sitio
    [, $h4] = enviar($sid, $BIB, ['csrf' => token($sid), 'accion' => 'usar_material',
                                  'pieza' => $C, 'activo_id' => $FOTO,
                                  'pos' => 'https://evil.example.com/']);
    $loc4 = cabecera($h4, 'Location');
    ok('una posición inventada no manda fuera',
       !str_contains($loc4, 'evil.example.com')
       && (str_starts_with($loc4, '/crecer/') || str_contains($loc4, 'localhost/crecer/')),
       $loc4);

    //  e · una pieza ya publicada no se toca
    $pdo->prepare("UPDATE crecer_contenido SET estado='publicado' WHERE id=?")->execute([$C]);
    $mat_pub = (int)$mat($C);
    $ins->execute([$M, 'post', '[prueba] Otra.']);
    $C2 = (int)$pdo->lastInsertId();
    enviar($sid, $BIB, ['csrf' => token($sid), 'accion' => 'usar_material',
                        'pieza' => $C, 'activo_id' => $FOTO, 'pos' => 2]);
    ok('una publicada no cambia',   (int)$mat($C) === $mat_pub);
    $pdo->prepare("UPDATE crecer_contenido SET estado='borrador' WHERE id=?")->execute([$C]);

    // ══════════════════════════════════════════════════════════════
    //  4 · LOS BORDES DE SUBIR
    // ══════════════════════════════════════════════════════════════
    echo "\n  — subir: lo que entra y lo que no —\n";
    $AP = "http://localhost/crecer/panel/aprobar2.php?marca={$M}";
    $act0 = $cnt('crecer_activos', "marca_id={$M}");

    //  a · una foto de verdad
    [$r] = enviar_archivo($sid, $AP,
        ['ajax' => 1, 'csrf' => token($sid), 'accion' => 'foto_directa', 'id' => $C2, 'solo_subir' => '1'],
        'imagen', 'bizcocho.png', base64_decode(PNG_1X1), 'image/png');
    ok('una foto real entra',       !empty($r['ok']), json_encode($r));
    $SUB = (int)($r['activo_id'] ?? 0);

    //  b · UN MIME MENTIDO. El navegador dice image/png y el contenido es texto.
    [$rm] = enviar_archivo($sid, $AP,
        ['ajax' => 1, 'csrf' => token($sid), 'accion' => 'foto_directa', 'id' => $C2, 'solo_subir' => '1'],
        'imagen', 'truco.png', "<?php echo 'hola'; ?>\n", 'image/png');
    ok('un MIME mentido se rechaza', empty($rm['ok']), json_encode($rm));
    ok('y no dejó nada en la Biblioteca',
       $cnt('crecer_activos', "marca_id={$M} AND nombre LIKE '%truco%'") === 0,
       'el tipo sale del CONTENIDO del archivo, no de lo que diga el navegador');

    //  c · UN NOMBRE CON TRUCO. El nombre del archivo no decide donde se guarda.
    [$rn] = enviar_archivo($sid, $AP,
        ['ajax' => 1, 'csrf' => token($sid), 'accion' => 'foto_directa', 'id' => $C2, 'solo_subir' => '1'],
        'imagen', '../../../evil.php', base64_decode(PNG_1X1), 'image/png');
    if (!empty($rn['ok'])) {
        $ruta_n = (string)$pdo->query("SELECT archivo FROM crecer_activos
                                        WHERE id=" . (int)$rn['activo_id'])->fetchColumn();
        ok('un nombre con «..» no sale de su carpeta',
           !str_contains($ruta_n, '..') && str_starts_with($ruta_n, "marca_{$M}/"), $ruta_n);
        ok('y el archivo no acaba en .php',
           !str_ends_with(strtolower($ruta_n), '.php'),
           'el nombre lo pone el servidor: lo que mande el cliente no decide dónde ni cómo');
    } else {
        ok('un nombre con «..» se rechaza', true, json_encode($rn));
        ok('(sin archivo que comprobar)',   true);
    }

    //  d · UN ARCHIVO ENORME. 16 MB para una foto: el tope es 15.
    [$rg] = enviar_archivo($sid, $AP,
        ['ajax' => 1, 'csrf' => token($sid), 'accion' => 'foto_directa', 'id' => $C2, 'solo_subir' => '1'],
        'imagen', 'enorme.png', base64_decode(PNG_1X1) . str_repeat("\0", 16 * 1024 * 1024),
        'image/png');
    ok('un archivo enorme se rechaza', empty($rg['ok']), json_encode($rg));

    //  e · SIN CSRF NO SE SUBE
    [$rc] = enviar_archivo($sid, $AP,
        ['ajax' => 1, 'accion' => 'foto_directa', 'id' => $C2, 'solo_subir' => '1'],
        'imagen', 'sincsrf.png', base64_decode(PNG_1X1), 'image/png');
    ok('sin csrf no se sube',       empty($rc['ok']), json_encode($rc));

    // ══════════════════════════════════════════════════════════════
    //  5 · CANCELAR NO LE QUITA LO QUE SUBIÓ
    // ══════════════════════════════════════════════════════════════
    echo "\n  — y si al final no la usa, sigue siendo suya —\n";
    ok('la subida está en su Biblioteca',
       $SUB > 0 && $cnt('crecer_activos', "id={$SUB} AND marca_id={$M} AND estado='activo'") === 1);
    ok('y la publicación no cambió',
       trim($img($C2)) === '' && $mat($C2) === null,
       'subir no es poner: el dueño todavía no había dicho que sí');

    //  Y el doble envío de «usarlo» no deja la pieza a medias.
    echo "\n  — y el doble envío no la deja a medias —\n";
    $ap = "http://localhost/crecer/panel/aprobar2.php?marca={$M}";
    for ($i = 0; $i < 2; $i++) {
        $c = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => "Cookie: PHPSESSID={$sid}\r\nContent-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query(['ajax' => 1, 'csrf' => token($sid), 'accion' => 'usar_activo',
                                           'id' => $C2, 'activo_id' => $SUB]),
            'timeout' => 30, 'ignore_errors' => true]]);
        @file_get_contents($ap, false, $c);
    }
    ok('dos envíos dejan un solo estado', (int)$mat($C2) === $SUB, (string)$mat($C2));
    ok('y una sola pieza tocada',
       $cnt('crecer_contenido', "marca_id={$M} AND material_activo_id={$SUB}") === 1);

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
   'escoger y subir no pasan por proveedor: cuestan 0');

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  FUNCIONA SIN JAVASCRIPT · $n afirmaciones\n\n"
    : "  $fallos FALLAS de $n\n\n";
exit($fallos === 0 ? 0 : 1);
