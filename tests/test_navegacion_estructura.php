<?php
// ============================================================
//  CRECER — LA NAVEGACIÓN DICE LA VERDAD
//  tests/test_navegacion_estructura.php
//
//  QUÉ SE PRUEBA, Y POR QUÉ CADA COSA:
//
//  1 · TODA ENTRADA VISIBLE LLEVA A UNA RUTA QUE EXISTE. Un menú con un
//      destino roto es peor que un menú corto: el dueño toca, no pasa nada, y
//      deja de fiarse del resto.
//
//  2 · NINGUNA RUTA DE ADMINISTRACIÓN SE LE OFRECE AL CLIENTE. Las páginas
//      `admin_*`, la evidencia del jurado y los diagnósticos existen y siguen
//      protegidas — lo que no puede pasar es invitarle a entrar.
//
//  3 · NADA APARECE DOS VECES. Dos entradas a la misma página le hacen creer
//      que son dos sitios distintos.
//
//  4 · LOS REDIRECTS LLEVAN A ALGÚN LADO, CONSERVAN LA MARCA Y NO DAN VUELTAS.
//      Un ciclo de redirect es una pantalla que nunca carga.
//
//  5 · «MI NEGOCIO» ES UNA PUERTA, NO UN FORMULARIO INTERMINABLE: filas con su
//      ajuste, y cada ajuste vuelve.
//
//  CERO PROVEEDOR Y CERO CUOTA: aquí solo se abren páginas y se leen enlaces.
// ============================================================

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nLA NAVEGACIÓN DICE LA VERDAD\n" . str_repeat('=', 58) . "\n";

$ctx0 = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true]]);
if (@file_get_contents('http://localhost/crecer/login.php', false, $ctx0) === false) {
    echo "\n  SALTADO · el servidor local no responde\n\n"; exit(0);
}

$PANEL = dirname(__DIR__) . '/panel';
$M = 0;

/** Abre una URL con la sesión y devuelve [codigo, cuerpo, destino]. */
function pedir(string $url, string $sid, bool $seguir = false): array {
    $ctx = stream_context_create(['http' => [
        'timeout' => 20, 'ignore_errors' => true, 'follow_location' => $seguir ? 1 : 0,
        'header' => "Cookie: PHPSESSID={$sid}\r\n",
    ]]);
    $cuerpo = @file_get_contents($url, false, $ctx);
    $codigo = 0; $destino = '';
    foreach (($http_response_header ?? []) as $l) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $l, $m)) $codigo = (int)$m[1];
        if (stripos($l, 'Location:') === 0) $destino = trim(substr($l, 9));
    }
    return [$codigo, (string)$cuerpo, $destino];
}

try {
    //  UNA CUENTA DE CLIENTE, no de admin: lo que se prueba es lo que ve un
    //  dueño. Con un admin, el «Centro de Operaciones» aparece —y debe
    //  aparecer— y la prueba estaría mirando otra pantalla.
    $fx = Fixture::crear($pdo, 'nav6', true, 'proveedor');
    $M = (int)$fx['marca_id'];
    //  Y PAGANDO. Un cliente sin suscripción no entra al panel —lo manda al
    //  gateway, que es correcto y es otra prueba—; aquí lo que se mira es el
    //  menú de quien ya está dentro.
    $pdo->prepare("INSERT INTO crecer_suscripciones (marca_id, usuario_id, estado, periodo_fin)
                    VALUES (?,?, 'activa', DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
        ->execute([$M, (int)$fx['usuario_id']]);
    $sid  = 'nv6' . bin2hex(random_bytes(7));
    $ruta = session_save_path() ?: sys_get_temp_dir();
    file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
                      'usuario_id|i:' . (int)$fx['usuario_id'] . ';');

    [$cod, $html] = pedir("http://localhost/crecer/panel/index.php?marca={$M}", $sid);
    ok('la portada abre', $cod === 200, (string)$cod);
    ok('y sin avisos de PHP',
       stripos($html, 'Undefined variable') === false && stripos($html, 'Warning:') === false,
       mb_substr(strip_tags($html), 0, 160));

    //  LOS ENLACES DE LA NAVEGACIÓN: menú lateral + barra de abajo.
    preg_match_all('#<a[^>]+href="([^"]*/panel/[a-z_0-9]+\.php[^"]*)"#i', $html, $m);
    $enlaces = array_values(array_unique($m[1] ?? []));
    $rutas   = [];
    foreach ($enlaces as $e) {
        if (preg_match('#/panel/([a-z_0-9]+\.php)#i', $e, $r)) $rutas[] = $r[1];
    }
    $rutas = array_values(array_unique($rutas));
    ok('hay navegación que revisar', count($rutas) >= 8, json_encode($rutas));

    // ══════════════════════════════════════════════════════════════
    //  1 · TODO DESTINO EXISTE
    // ══════════════════════════════════════════════════════════════
    echo "\n  — cada entrada lleva a una ruta que existe —\n";
    $rotas = array_values(array_filter($rutas, fn($r) => !is_file($PANEL . '/' . $r)));
    ok('ninguna entrada apunta a un archivo que no está', $rotas === [], json_encode($rotas));

    // ══════════════════════════════════════════════════════════════
    //  2 · NADA DE ADMINISTRACIÓN NI DE JURADO
    // ══════════════════════════════════════════════════════════════
    echo "\n  — al cliente no se le ofrece la trastienda —\n";
    $prohibidas = ['admin.php', 'admin_alertas.php', 'admin_analitica.php', 'admin_cliente.php',
                   'admin_equipo.php', 'admin_evidencia.php', 'admin_incidencias.php',
                   'admin_migrar.php', 'admin_paquete.php', 'admin_prospector.php',
                   'admin_salud.php', 'admin_soporte.php', 'evidencia.php',
                   'lab_referencias.php', 'generar.php', 'pronto.php',
                   'arte_worker.php', 'gen_worker.php', 'meta_worker.php', 'relevo_worker.php',
                   'publicar_worker.php', 'sala_worker.php', 'carrusel_worker.php',
                   'reel_worker.php', 'reel_publicar_worker.php'];
    $coladas = array_values(array_intersect($rutas, $prohibidas));
    ok('ni una ruta de administración o de worker', $coladas === [], json_encode($coladas));
    ok('ni la evidencia del jurado',  !in_array('evidencia.php', $rutas, true));

    //  Y SIGUEN PROTEGIDAS: sacarlas del menú no puede ser toda la seguridad.
    [$ca] = pedir("http://localhost/crecer/panel/admin.php", $sid);
    ok('admin sigue cerrado con llave', $ca === 403 || $ca === 302,
       (string)$ca . ' — esta cuenta no es admin');

    // ══════════════════════════════════════════════════════════════
    //  3 · NADA DOS VECES
    // ══════════════════════════════════════════════════════════════
    echo "\n  — nada aparece dos veces en el menú —\n";
    //  Se cuentan las entradas VISIBLES del menú lateral (las `.dup` están
    //  escondidas en móvil pero se ven en escritorio: no son duplicados).
    preg_match('#<nav>(.*?)</nav>#s', $html, $nv);
    preg_match_all('#href="[^"]*/panel/([a-z_0-9]+\.php)#i', (string)($nv[1] ?? ''), $mm);
    $cuenta = array_count_values($mm[1] ?? []);
    //  `propuestas.php` sale dos veces a propósito: «Crear» (con ?crear=1) y
    //  «Tus Posts» son dos trabajos distintos en la misma página.
    unset($cuenta['propuestas.php']);
    $repes = array_keys(array_filter($cuenta, fn($c) => $c > 1));
    ok('ninguna página repetida en el menú', $repes === [], json_encode($repes));
    ok('y «Crear» y «Tus Posts» se distinguen',
       substr_count((string)($nv[1] ?? ''), 'crear=1') === 1,
       'son el mismo archivo con dos trabajos: uno abre el asistente, el otro la bandeja');

    // ══════════════════════════════════════════════════════════════
    //  4 · LOS CINCO CONCEPTOS
    // ══════════════════════════════════════════════════════════════
    echo "\n  — los cinco conceptos están —\n";
    foreach (['index.php' => 'Inicio', 'meta.php' => 'Tu Meta', 'calendario.php' => 'Calendario',
              'resultados.php' => 'Resultados', 'genoma.php' => 'Mi negocio'] as $r => $nom) {
        ok("está {$nom}", in_array($r, $rutas, true), json_encode($rutas));
    }
    ok('y «Crear» sigue disponible',   in_array('propuestas.php', $rutas, true));
    ok('y la Biblioteca también',      in_array('biblioteca.php', $rutas, true));

    // ══════════════════════════════════════════════════════════════
    //  5 · LOS REDIRECTS: llegan, conservan la marca y no dan vueltas
    // ══════════════════════════════════════════════════════════════
    echo "\n  — las rutas antiguas llegan a su sitio —\n";
    $saltos = [
        'analitica.php' => 'resultados.php',
    ];
    foreach ($saltos as $vieja => $nueva) {
        $url = "http://localhost/crecer/panel/{$vieja}?marca={$M}";
        $visto = []; $actual = $url; $vueltas = 0; $destino = '';
        while ($vueltas++ < 5) {
            [$c, , $d] = pedir($actual, $sid);
            if ($c !== 301 && $c !== 302) { $destino = $actual; break; }
            if (isset($visto[$d])) { $destino = 'CICLO'; break; }
            $visto[$d] = true; $actual = $d;
        }
        ok("{$vieja} llega a {$nueva}", str_contains($destino, $nueva), $destino);
        ok("y conserva la marca",       str_contains($destino, 'marca=' . $M), $destino);
        ok("sin dar vueltas",           $destino !== 'CICLO' && $vueltas <= 3, "saltos: {$vueltas}");
    }

    // ══════════════════════════════════════════════════════════════
    //  6 · MI NEGOCIO
    // ══════════════════════════════════════════════════════════════
    echo "\n  — Mi negocio: una puerta, no un formulario —\n";
    [$cg, $hg] = pedir("http://localhost/crecer/panel/genoma.php?marca={$M}", $sid);
    ok('abre',                     $cg === 200, (string)$cg);
    //  NI UN AVISO DE PHP. Esto no es cosmético: un «Undefined variable»
    //  encima del título es lo primero que ve el dueño al entrar en su propio
    //  negocio, y le dice que el sitio está roto aunque funcione. Pasó de
    //  verdad en esta misma pantalla, y por eso ahora se comprueba.
    ok('sin avisos de PHP encima',
       stripos($hg, 'Undefined variable') === false
       && stripos($hg, 'Warning:') === false && stripos($hg, 'Notice:') === false
       && stripos($hg, 'Deprecated:') === false,
       mb_substr(strip_tags($hg), 0, 160));
    ok('con la radiografía',       substr_count($hg, 'ng-fila') >= 4,
       (string)substr_count($hg, 'ng-fila'));
    foreach (['Identidad y voz', 'Logo y colores', 'Público y oferta',
              'Tu equipo', 'Canales y conexiones'] as $fila) {
        ok("está «{$fila}»", str_contains($hg, $fila));
    }
    ok('y dice para qué sirve',
       str_contains($hg, 'Así entiende el corillo tu negocio'), 'el copy de la fase');
    ok('sin prometer que reescribe lo ya hecho',
       !str_contains(mb_strtolower($hg), 'reescrib'), 'sería mentira: lo publicado no se toca');
    ok('cada ajuste sabe volver',
       substr_count($hg, 'volver=negocio') >= 4, (string)substr_count($hg, 'volver=negocio'));

    //  LA VUELTA ES UNA ETIQUETA, NO UNA URL. Si aceptara una URL, se podría
    //  sacar a alguien de Crecer con un enlace que parece de Crecer.
    [, $hm] = pedir("http://localhost/crecer/panel/marca.php?marca={$M}&volver=negocio", $sid);
    ok('el editor trae su vuelta',
       str_contains($hm, 'class="volver-a" href="/crecer/panel/genoma.php'), 'volver a Mi negocio');
    [, $hx] = pedir("http://localhost/crecer/panel/marca.php?marca={$M}&volver=" . urlencode('https://evil.example/x'), $sid);
    //  Que la cadena aparezca dentro de otra URL local (el selector de idioma
    //  rehace la dirección actual) no es el problema. El problema sería que
    //  fuera un DESTINO: un enlace que saca de Crecer pareciendo de Crecer.
    preg_match_all('#href="([^"]+)"#i', $hx, $hh);
    $fuera = array_values(array_filter($hh[1] ?? [],
        fn($u) => preg_match('#^https?://#i', $u) === 1
               && stripos($u, 'evil.example') !== false));
    ok('y una URL de fuera NO se convierte en destino', $fuera === [],
       json_encode($fuera) . ' — así se construye un redirect abierto');
    ok('la vuelta sigue siendo la de siempre',
       !str_contains($hx, 'class="volver-a"')
       || str_contains($hx, 'class="volver-a" href="/crecer/panel/'),
       'el destino lo decide una lista corta, no el que manda el enlace');

    // ══════════════════════════════════════════════════════════════
    //  7 · NO SE VE EL NEGOCIO DE OTRO
    // ══════════════════════════════════════════════════════════════
    echo "\n  — cambiar la marca no abre la puerta de otro —\n";
    $fx2 = Fixture::crear($pdo, 'nav6B', true, 'proveedor');
    $M2 = (int)$fx2['marca_id'];
    $pdo->prepare("INSERT INTO crecer_suscripciones (marca_id, usuario_id, estado, periodo_fin)
                    VALUES (?,?, 'activa', DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
        ->execute([$M2, (int)$fx2['usuario_id']]);
    [, $ha] = pedir("http://localhost/crecer/panel/genoma.php?marca={$M2}", $sid);
    $nom2 = (string)$pdo->query("SELECT nombre_negocio FROM crecer_marca WHERE id={$M2}")->fetchColumn();
    ok('no se cuela el nombre del otro negocio',
       !str_contains($ha, $nom2),
       'la marca de la URL no manda: manda de quién es');
    try { Fixture::limpiar($pdo, $M2); } catch (Throwable $e) {}

    // ══════════════════════════════════════════════════════════════
    //  8 · Y VISTO EN UN NAVEGADOR
    // ══════════════════════════════════════════════════════════════
    //  Un menú se juzga viéndolo. Aquí se abre como lo abre el dueño —tocando
    //  el botón— y se mira lo que de verdad pasa en 360: si los rótulos caben,
    //  si se puede tocar cada fila, y si «Mi negocio» se lee de un vistazo.
    $CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
    if (!is_file($CHROME)) {
        echo "\n  (saltado: no hay Chrome para mirar el menú)\n";
    } else {
        echo "\n  — el menú, visto en un teléfono —\n";
        $SHOTS = __DIR__ . '/_capturas/navegacion';
        if (!is_dir($SHOTS)) @mkdir($SHOTS, 0777, true);
        foreach (['inicio', 'negocio', 'meta', 'calendario', 'resultados'] as $pantalla) {
            try {
                $pdo->prepare("INSERT IGNORE INTO crecer_tour_visto (marca_id, clave, visto_at)
                                VALUES (?,?, NOW())")->execute([$M, $pantalla]);
            } catch (Throwable $e) {}
        }
        $cmd = 'node ' . escapeshellarg(__DIR__ . '/_inicio_probe.mjs') . ' '
             . escapeshellarg($SHOTS) . ' ' . escapeshellarg($sid) . ' ' . $M . ' nav';
        $sal = (string)shell_exec($cmd . ' 2>&1');
        $R = [];
        foreach (explode("\n", $sal) as $l) {
            $l = trim($l); $i = strpos($l, '=');
            if ($i > 0) $R[substr($l, 0, $i)] = substr($l, $i + 1);
        }
        ok('el navegador abrió el menú', ($R['OK'] ?? '0') === '1', substr($sal, -400));
        $MN = json_decode((string)($R['MENU'] ?? '{}'), true) ?: [];
        $NG = json_decode((string)($R['NEGOCIO'] ?? '{}'), true) ?: [];

        ok('el menú se abre al tocar',   !empty($MN['abierto']), json_encode($MN['abierto'] ?? null));
        ok('con sus grupos a la vista',
           count((array)($MN['grupos'] ?? [])) >= 4, json_encode($MN['grupos'] ?? []));
        ok('sin desbordar a lo ancho',   (int)($MN['horiz'] ?? 1) === 0,
           'sobran ' . ($MN['horiz'] ?? '?') . 'px');

        $links = (array)($MN['links'] ?? []);
        ok('hay destinos que tocar',     count($links) >= 8, (string)count($links));
        $cortados = array_values(array_filter($links, fn($l) => !empty($l['cortado'])));
        ok('ni un rótulo cortado a 360', $cortados === [],
           json_encode(array_column($cortados, 't')));
        $chicos = array_values(array_filter($links, fn($l) => (int)$l['alto'] < 44));
        ok('todo se toca con el pulgar', $chicos === [],
           json_encode(array_map(fn($l) => $l['t'] . ' h=' . $l['alto'], $chicos)));
        ok('y todos conservan la marca',
           !in_array(false, array_map(fn($l) => str_contains((string)$l['h'], 'marca=' . $M)
                                              || str_contains((string)$l['h'], 'logout'), $links), true),
           json_encode(array_column($links, 'h')));

        //  LO QUE NO PUEDE ESTAR: la trastienda, ni en el teléfono.
        $textos = mb_strtolower(implode(' | ', array_column($links, 't')));
        foreach (['operaciones', 'evidencia', 'migrar', 'diagn'] as $palabra) {
            ok("el menú no ofrece «{$palabra}»", !str_contains($textos, $palabra), $textos);
        }

        echo "\n  — Mi negocio, en el teléfono —\n";
        $filas = (array)($NG['filas'] ?? []);
        ok('se ven las filas',           count($filas) >= 4, (string)count($filas));
        ok('cada una se puede tocar',
           !in_array(false, array_map(fn($f) => (int)$f['alto'] >= 44, $filas), true),
           json_encode(array_column($filas, 'alto')));
        ok('y cada una lleva a su ajuste',
           !in_array(false, array_map(fn($f) => str_contains((string)$f['h'], 'volver=negocio'), $filas), true),
           json_encode(array_column($filas, 'h')));
        ok('sin desbordar a lo ancho',   (int)($NG['horiz'] ?? 1) === 0,
           'sobran ' . ($NG['horiz'] ?? '?') . 'px');
        ok('cero alert()',               (string)($R['ALERTAS'] ?? '1') === '0');
        ok('cero errores en consola',
           in_array(trim((string)($R['ERRORES'] ?? '[]')), ['[]', ''], true), (string)($R['ERRORES'] ?? ''));
        echo "\n  capturas en tests/_capturas/navegacion/*.png\n";
    }

    @unlink($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid);

} catch (Throwable $e) {
    $fallos++; $n++;
    echo "  FALLA excepción\n         → " . get_class($e) . ': ' . $e->getMessage()
       . "\n         → " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    if ($M > 0) { try { Fixture::limpiar($pdo, $M); echo "\n  (fixture limpiada)\n"; }
                  catch (Throwable $e) {} }
}

echo "\n  — el costo —\n";
//  Se mide en DINERO, no en filas: hay apuntes que no son llamadas a un
//  modelo —decisiones por reglas, por ejemplo— y contarlos como gasto haría
//  saltar esta prueba por algo que no cuesta nada.
ok('abrir el panel no costó un centavo',
   (float)$pdo->query("SELECT COALESCE(SUM(costo_usd),0) FROM crecer_ia_log
                        WHERE created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)")
       ->fetchColumn() < 0.000001);

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0 ? "  LA NAVEGACIÓN DICE LA VERDAD · {$n} afirmaciones\n\n"
                   : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
