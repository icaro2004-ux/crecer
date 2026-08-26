<?php
// ============================================================
//  CRECER — SELECCIONAR MATERIAL FUNCIONA DE VERDAD (Fase 2B)
//  tests/test_biblioteca_seleccion.php
//
//  La barra de selección ya estaba; lo que no había era forma de escoger. Esta
//  suite prueba el recorrido entero POR HTTP, como lo hace un navegador — y
//  además SIN JavaScript, porque los radios son reales y el formulario envía
//  solo. Un selector que solo existe en JS es un selector que no existe cuando
//  el JS falla, y falla en el móvil de la repostera, no en el nuestro.
//
//  Y prueba lo contrario con la misma dureza: que manipular el HTML no sirva.
//  El servidor vuelve a validar marca, recurso, vida y formato — la pantalla
//  no es la guarda.
//
//  ══ RED CERRADA POR CONSTRUCCION ══ `_sin_gasto.php`. Aquí no se genera nada.
// ============================================================

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/meta_negocio.php';
require_once __DIR__ . '/../includes/meta_semana.php';
require_once __DIR__ . '/../includes/material.php';
require_once __DIR__ . '/../core/Meta/MetaRetorno.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nSELECCIONAR MATERIAL, DE VERDAD\n" . str_repeat('=', 58) . "\n";

if (@file_get_contents('http://localhost/crecer/login.php', false,
        stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]])) === false) {
    echo "\n  SALTADO · el servidor local no responde\n\n"; exit(0);
}

$cnt = fn(string $t, string $w = '1') => (int)$GLOBALS['pdo']->query(
    "SELECT COUNT(*) FROM {$t} WHERE {$w}")->fetchColumn();
$g = ['ia' => $cnt('crecer_ia_log'), 'cuota' => $cnt('crecer_img_cuota_asiento')];

function sesion(int $u): string {
    $sid = 'sel' . bin2hex(random_bytes(7));
    file_put_contents((session_save_path() ?: sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'sess_' . $sid,
                      'usuario_id|i:' . $u . ';');
    return $sid;
}
/** GET con esa sesión. Devuelve [cuerpo, cabeceras]. */
function traer(string $sid, string $url): array {
    $c = stream_context_create(['http' => [
        'header' => "Cookie: PHPSESSID={$sid}\r\n", 'timeout' => 30,
        'ignore_errors' => true, 'follow_location' => 0]]);
    $body = (string)@file_get_contents($url, false, $c);
    return [$body, $http_response_header ?? []];
}
/** POST de formulario, SIN seguir el redirect: así se ve adónde manda. */
function postear(string $sid, int $marca, array $campos): array {
    $c = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Cookie: PHPSESSID={$sid}\r\nContent-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query($campos), 'timeout' => 30,
        'ignore_errors' => true, 'follow_location' => 0]]);
    $body = (string)@file_get_contents(
        'http://localhost/crecer/panel/biblioteca.php?marca=' . $marca, false, $c);
    $loc = ''; $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('~^HTTP/\S+\s+(\d+)~', $h, $m)) $code = (int)$m[1];
        if (stripos($h, 'Location:') === 0) $loc = trim(substr($h, 9));
    }
    return ['code' => $code, 'loc' => $loc, 'body' => $body];
}
function csrf_de(string $sid, int $marca): string {
    traer($sid, 'http://localhost/crecer/panel/biblioteca.php?marca=' . $marca);
    $r = (session_save_path() ?: sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'sess_' . $sid;
    return preg_match('~csrf\|s:\d+:"([0-9a-f]+)"~', (string)@file_get_contents($r), $m) ? $m[1] : '';
}

$limpiar = [];
try {
    echo "\n  — una semana de dos, y material propio —\n";
    $fx = Fixture::crear($pdo, 'sel', true, 'admin');
    $limpiar[] = $M = (int)$fx['marca_id'];
    $meta = meta_activa($pdo, $M);
    $plan = meta_plan_activo($pdo, (int)$meta['id']);
    $pdo->prepare("UPDATE crecer_meta_tactica SET semana=9, estado='hecha' WHERE meta_id=?")
        ->execute([(int)$meta['id']]);
    foreach ($fx['piezas'] as $p) {
        $pdo->prepare("UPDATE crecer_contenido SET tactica_id=NULL WHERE id=?")->execute([(int)$p]);
    }
    $ins = $pdo->prepare("INSERT INTO crecer_meta_tactica
            (meta_id, plan_id, marca_id, orden, semana, tipo, titulo, por_que,
             clase, quien, estado, piezas_meta, formato)
         VALUES (?,?,?,?,1,'contenido',?,?, 'produccion','corillo','pendiente',1,'post')");
    $piezas = [];
    foreach ([1 => 'Primera', 2 => 'Segunda'] as $o => $t) {
        $ins->execute([(int)$meta['id'], (int)$plan['id'], $M, $o,
                       '[prueba] ' . $t . ' publicación', 'así saben qué pedir']);
        $tid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO crecer_contenido
                (marca_id,plataforma,tipo,caption,estado,meta_id,plan_id,tactica_id,
                 fecha_programada,grafica_path)
              VALUES (?, 'instagram','post',?, 'borrador',?,?,?, DATE_ADD(NOW(), INTERVAL ? DAY), ?)")
            ->execute([$M, '[prueba] Texto de la ' . mb_strtolower($t) . '.',
                       (int)$meta['id'], (int)$plan['id'], $tid, $o + 1,
                       '/crecer/uploads/marca_x/arte_generado.png']);
        $piezas[$t] = (int)$pdo->lastInsertId();
    }
    $act = $pdo->prepare("INSERT INTO crecer_activos
            (marca_id,tipo,archivo,nombre,mime,bytes,origen,estado)
          VALUES (?,?,?,?,?,?, 'subido','activo')");
    $act->execute([$M, 'imagen', "marca_{$M}/biblioteca/suya.jpg",
                   '[prueba] Su bizcocho', 'image/jpeg', 1234]);
    $FOTO = (int)$pdo->lastInsertId();
    $act->execute([$M, 'video', "marca_{$M}/biblioteca/suyo.mp4",
                   '[prueba] Su horno', 'video/mp4', 99000]);
    $VIDEO = (int)$pdo->lastInsertId();

    $sid  = sesion((int)$fx['usuario_id']);
    $C    = $piezas['Segunda'];
    $POS  = 2;
    $BIB  = 'http://localhost/crecer/panel/biblioteca.php?marca=' . $M;
    $SEL  = $BIB . MetaRetorno::marcador($POS) . '&pieza=' . $C;
    $csrf = csrf_de($sid, $M);
    ok('hay csrf', $csrf !== '');

    // ══════════════════════════════════════════════════════════════
    //  1 · EL FORMULARIO EXISTE Y ES DE VERDAD
    // ══════════════════════════════════════════════════════════════
    echo "\n  — se puede escoger sin JavaScript —\n";
    [$html] = traer($sid, $SEL);
    ok('hay un formulario que envía',
       preg_match('~<form[^>]+id="bibForm"[^>]+method="post"~i', $html) === 1);
    ok('con su csrf',      mb_strpos($html, 'name="csrf"') !== false);
    ok('y su pieza',       preg_match('~name="pieza"\s+value="' . $C . '"~', $html) === 1);
    ok('y su posición',    preg_match('~name="pos"\s+value="' . $POS . '"~', $html) === 1);

    //  LA FOTO es seleccionable con un radio REAL.
    ok('la foto tiene un radio de verdad',
       preg_match('~<input type="radio" name="activo_id"[^>]*value="' . $FOTO . '"~', $html) === 1,
       'un selector que solo vive en JS no existe cuando el JS falla');
    //  EL VIDEO NO SE IMPRIME siquiera: la pieza es un post.
    ok('el video ni se imprime como opción',
       preg_match('~<input type="radio" name="activo_id"[^>]*value="' . $VIDEO . '"~', $html) !== 1,
       'imprimirlo y taparlo con CSS es enseñárselo a quien mire el fuente');
    ok('la primaria nace deshabilitada',
       preg_match('~id="bibUsar"[^>]*disabled~', $html) === 1);

    // ══════════════════════════════════════════════════════════════
    //  2 · CONFIRMAR APLICA Y DEVUELVE A SU PUBLICACION
    // ══════════════════════════════════════════════════════════════
    echo "\n  — escoger, enviar, y volver a la publicación —\n";
    $ia0 = $cnt('crecer_ia_log'); $cu0 = $cnt('crecer_img_cuota_asiento');
    $cap0 = (string)$pdo->query("SELECT caption FROM crecer_contenido WHERE id={$C}")->fetchColumn();
    $fe0  = (string)$pdo->query("SELECT fecha_programada FROM crecer_contenido WHERE id={$C}")->fetchColumn();

    $r = postear($sid, $M, ['accion' => 'usar_material', 'csrf' => $csrf,
                            'pieza' => $C, 'activo_id' => $FOTO, 'pos' => $POS]);
    ok('el servidor redirige',   in_array($r['code'], [301, 302, 303], true), (string)$r['code']);
    ok('a su semana',            mb_strpos($r['loc'], 'vista=semana') !== false, $r['loc']);
    ok('y a su posición',        preg_match('~pos=' . $POS . '\b~', $r['loc']) === 1, $r['loc']);
    ok('con su marca',           mb_strpos($r['loc'], 'marca=' . $M) !== false, $r['loc']);

    $ahora = $pdo->query("SELECT grafica_path, material_activo_id, caption, fecha_programada
                            FROM crecer_contenido WHERE id={$C}")->fetch(PDO::FETCH_ASSOC);
    ok('la publicación usa su foto',
       mb_strpos((string)$ahora['grafica_path'], 'suya.jpg') !== false, (string)$ahora['grafica_path']);
    ok('y queda trazada',        (int)$ahora['material_activo_id'] === $FOTO);
    ok('sin tocar el texto',     (string)$ahora['caption'] === $cap0);
    ok('sin tocar la fecha',     (string)$ahora['fecha_programada'] === $fe0);
    ok('cero llamadas al modelo',$cnt('crecer_ia_log') === $ia0);
    ok('cero cuota',             $cnt('crecer_img_cuota_asiento') === $cu0);

    //  Y SE VE EN SU SEMANA.
    [$sem] = traer($sid, 'http://localhost/crecer/panel/meta.php?marca=' . $M
                         . '&vista=semana&pos=' . $POS);
    ok('la semana ya la enseña', mb_strpos($sem, 'suya.jpg') !== false);

    // ══════════════════════════════════════════════════════════════
    //  3 · LO QUE NO SE PUEDE HACER MANIPULANDO EL HTML
    // ══════════════════════════════════════════════════════════════
    echo "\n  — la pantalla no es la guarda —\n";
    $antes = (string)$pdo->query("SELECT grafica_path FROM crecer_contenido WHERE id={$C}")->fetchColumn();

    //  El video que ni se imprimio: si alguien lo manda igual, el servidor dice que no.
    $r = postear($sid, $M, ['accion' => 'usar_material', 'csrf' => $csrf,
                            'pieza' => $C, 'activo_id' => $VIDEO, 'pos' => $POS]);
    ok('un video en un post se rechaza',
       mb_strpos($r['loc'], 'err=') !== false, $r['loc']);
    ok('y no cambió nada',
       (string)$pdo->query("SELECT grafica_path FROM crecer_contenido WHERE id={$C}")
                   ->fetchColumn() === $antes);

    //  Un recurso de otra marca.
    $fo = Fixture::crear($pdo, 'selX', false, 'proveedor');
    $limpiar[] = $MX = (int)$fo['marca_id'];
    $act->execute([$MX, 'imagen', "marca_{$MX}/biblioteca/ajena.jpg",
                   '[prueba] AJENA', 'image/jpeg', 5555]);
    $AJENA = (int)$pdo->lastInsertId();
    $r = postear($sid, $M, ['accion' => 'usar_material', 'csrf' => $csrf,
                            'pieza' => $C, 'activo_id' => $AJENA, 'pos' => $POS]);
    ok('un recurso de otra marca se rechaza', mb_strpos($r['loc'], 'err=') !== false, $r['loc']);
    ok('y la publicación sigue igual',
       (string)$pdo->query("SELECT grafica_path FROM crecer_contenido WHERE id={$C}")
                   ->fetchColumn() === $antes);

    //  Sin CSRF.
    $r = postear($sid, $M, ['accion' => 'usar_material', 'csrf' => 'no-vale',
                            'pieza' => $C, 'activo_id' => $FOTO, 'pos' => $POS]);
    ok('sin CSRF no se escribe',
       mb_stripos($r['body'], 'expir') !== false || $r['loc'] === '', mb_substr($r['body'], 0, 120));

    //  Y UNA POSICION INVENTADA no manda a ningún sitio raro.
    $r = postear($sid, $M, ['accion' => 'usar_material', 'csrf' => $csrf,
                            'pieza' => $C, 'activo_id' => $FOTO, 'pos' => '999999']);
    ok('una posición absurda no viaja',
       mb_strpos($r['loc'], '999999') === false, $r['loc']);
    ok('y el destino sigue siendo de casa',
       preg_match('~^/crecer/panel/~', $r['loc']) === 1
       || mb_strpos($r['loc'], 'localhost') !== false, $r['loc']);

    // ══════════════════════════════════════════════════════════════
    //  4 · CANCELAR NO ESCRIBE
    // ══════════════════════════════════════════════════════════════
    echo "\n  — cancelar es irse, no guardar —\n";
    $ruta_pre = (string)$pdo->query("SELECT grafica_path FROM crecer_contenido WHERE id={$C}")->fetchColumn();
    $id_pre   = $pdo->query("SELECT material_activo_id FROM crecer_contenido WHERE id={$C}")->fetchColumn();
    [$h2] = traer($sid, $SEL);
    ok('la salida vuelve a su publicación',
       preg_match('~class="bib-sel-x" href="[^"]*vista=semana[^"]*pos=' . $POS . '~', $h2) === 1,
       'cancelar no puede dejarle en una lista genérica');
    ok('y mirar no cambió nada',
       (string)$pdo->query("SELECT grafica_path FROM crecer_contenido WHERE id={$C}")
                   ->fetchColumn() === $ruta_pre
       && $pdo->query("SELECT material_activo_id FROM crecer_contenido WHERE id={$C}")
              ->fetchColumn() == $id_pre);

    // ══════════════════════════════════════════════════════════════
    //  5 · EN UN REEL, EL VIDEO SI APARECE
    // ══════════════════════════════════════════════════════════════
    echo "\n  — donde el video cabe, se ofrece —\n";
    $pdo->prepare("UPDATE crecer_contenido SET tipo='reel' WHERE id=?")->execute([$C]);
    [$h3] = traer($sid, $SEL);
    ok('el video ya es seleccionable',
       preg_match('~<input type="radio" name="activo_id"[^>]*value="' . $VIDEO . '"~', $h3) === 1);
    ok('y la foto también',
       preg_match('~<input type="radio" name="activo_id"[^>]*value="' . $FOTO . '"~', $h3) === 1,
       'un reel admite las dos cosas');
    $r = postear($sid, $M, ['accion' => 'usar_material', 'csrf' => $csrf,
                            'pieza' => $C, 'activo_id' => $VIDEO, 'pos' => $POS]);
    ok('y se aplica',
       mb_strpos((string)$pdo->query("SELECT grafica_path FROM crecer_contenido WHERE id={$C}")
                             ->fetchColumn(), 'suyo.mp4') !== false);
    ok('con su traza',
       (int)$pdo->query("SELECT material_activo_id FROM crecer_contenido WHERE id={$C}")
                ->fetchColumn() === $VIDEO);

    // ══════════════════════════════════════════════════════════════
    //  6 · BIBLIOTECA NORMAL, INTACTA
    // ══════════════════════════════════════════════════════════════
    echo "\n  — y desde el menú, todo igual —\n";
    [$normal] = traer($sid, $BIB);
    ok('sin formulario de selección', mb_strpos($normal, 'id="bibForm"') === false);
    ok('sin radios',                  mb_strpos($normal, 'name="activo_id"') === false);
    ok('pero con su material',        mb_strpos($normal, 'Su bizcocho') !== false);

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
ok('cero asientos de cuota',  $cnt('crecer_img_cuota_asiento') === $g['cuota']);

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  SE ESCOGE Y SE VUELVE · $n afirmaciones\n\n"
    : "  $fallos FALLAS de $n\n\n";
exit($fallos === 0 ? 0 : 1);
