<?php
// ============================================================
//  CRECER — EL CENTRO DE MANDO, VISTO POR EL DUEÑO
//  tests/test_inicio_navegador.php
//
//  EL CONTRATO EN PHP dice que los bloques traen los datos correctos. Esto
//  dice lo otro, que es lo que decide si el producto sirve: que al abrir
//  Crecer en su teléfono entienda su negocio en pocos segundos.
//
//  LO QUE SE MIRA:
//    · el primer viewport de un Android de 360 trae saludo, la Meta ENTERA y
//      una pista de lo que sigue — sin tres pantallas de scroll;
//    · la acción de la Meta abre la vista EXACTA, no una lista genérica;
//    · cada bloque abre su sección de verdad y conserva la marca;
//    · la barra de abajo tiene los cuatro destinos del circuito diario;
//    · el menú agrupa sin esconder nada en el móvil;
//    · nada se solapa, nada baja de 44px ni de 14px, y no hay dos elementos
//      activos a la vez diciéndole que está en dos sitios.
//
//  CERO GASTO: abrir Inicio no llama a nadie. Se cuenta al final.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nEL CENTRO DE MANDO, EN PANTALLA\n" . str_repeat('=', 58) . "\n";

$ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
if (@file_get_contents('http://localhost/crecer/login.php', false, $ctx) === false) {
    echo "\n  SALTADO · el servidor local no responde\n\n"; exit(0);
}
$CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
if (!is_file($CHROME)) { echo "\n  SALTADO · no hay Chrome\n\n"; exit(0); }

$SHOTS = __DIR__ . '/_capturas/inicio';
if (!is_dir($SHOTS)) @mkdir($SHOTS, 0777, true);

//  EL CENTINELA. Este recorrido pasa por Resultados y por Tu Meta, y esas
//  pantallas despiertan agentes: la peticion entra por Apache, donde
//  CRECER_TEST_MODE no existe, asi que la llamada iria a Gemini con la clave
//  de verdad. Se vio como una afirmacion de costo que fallaba a veces —solo
//  cuando al Analista le tocaba hablar—, que es la peor forma de verlo. Con
//  este fichero, y solo en localhost, el transporte es `mock`.
$CENT = __DIR__ . '/../includes/_SIN_CREDENCIALES';
file_put_contents($CENT, "prueba de navegador · " . date('c') . "
");
register_shutdown_function(function () use ($CENT) { @unlink($CENT); });

$M = 0;
try {
    $fx = Fixture::crear($pdo, 'inav', true, 'admin');
    $M = (int)$fx['marca_id'];
    $META = (int)$fx['meta_id']; $PLAN = (int)$fx['plan_id'];

    //  UN NEGOCIO CON VIDA: algo programado del plan, algo suyo, y una pieza
    //  esperando su foto. Una portada vacía no prueba nada.
    $pdo->prepare("INSERT INTO crecer_contenido
            (marca_id, plataforma, tipo, caption, estado, fecha_programada, meta_id, plan_id, tactica_id)
          VALUES (?, 'instagram','post','[prueba] El combo del sábado','programado',
                  DATE_ADD(NOW(), INTERVAL 3 HOUR), ?,?,?)")
        ->execute([$M, $META, $PLAN, (int)$fx['tacticas'][0]]);
    $pdo->prepare("INSERT INTO crecer_contenido
            (marca_id, plataforma, tipo, caption, estado, fecha_programada)
          VALUES (?, 'facebook','post','[prueba] La foto que subí yo','programado',
                  DATE_ADD(NOW(), INTERVAL 1 DAY))")->execute([$M]);

    //  EL RECIBIMIENTO, YA VISTO. El tour de la primera vez tiene su propia
    //  prueba; aquí taparía justo lo que se viene a mirar, y la captura
    //  enseñaría el tutorial en vez del producto.
    foreach (['inicio', 'meta', 'calendario', 'resultados'] as $pantalla) {
        try {
            $pdo->prepare("INSERT IGNORE INTO crecer_tour_visto (marca_id, clave, visto_at)
                            VALUES (?,?, NOW())")->execute([$M, $pantalla]);
        } catch (Throwable $e) {}
    }

    $sid  = 'inv' . bin2hex(random_bytes(7));
    $ruta = session_save_path() ?: sys_get_temp_dir();
    file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
                      'usuario_id|i:' . (int)$fx['usuario_id'] . ';');

    $cmd = 'node ' . escapeshellarg(__DIR__ . '/_inicio_probe.mjs') . ' '
         . escapeshellarg($SHOTS) . ' ' . escapeshellarg($sid) . ' ' . $M;
    $salida = (string)shell_exec($cmd . ' 2>&1');
    $R = [];
    foreach (explode("\n", $salida) as $l) {
        $l = trim($l); $i = strpos($l, '=');
        if ($i > 0) $R[substr($l, 0, $i)] = substr($l, $i + 1);
    }
    ok('el navegador recorrió Inicio', ($R['OK'] ?? '0') === '1', substr($salida, -600));
    if (($R['OK'] ?? '0') !== '1') throw new RuntimeException('sonda caída');
    $leer = fn(string $k) => json_decode((string)($R[$k] ?? '{}'), true) ?: [];

    // ── QUÉ CUENTA LA PANTALLA ────────────────────────────────────
    echo "\n  — lo que ve al abrir —\n";
    $L = $leer('LEIDO_360');
    ok('lo saluda por su nombre',   trim((string)($L['saludo'] ?? '')) !== '', (string)($L['saludo'] ?? ''));
    ok('la Meta manda en la pantalla', !empty($L['hayNorte']), json_encode($L['norteTxt'] ?? ''));
    ok('con una acción clara',      trim((string)($L['accion'] ?? '')) !== '', (string)($L['accion'] ?? ''));
    //  LA VISTA EXACTA, NO UNA LISTA. Según en qué punto esté, la acción
    //  lleva a Tu Meta o directamente a la publicación que hay que decidir —
    //  con su id. Lo que no puede hacer nunca es soltarlo en una lista
    //  genérica a que la busque.
    $nh = (string)($L['norteHref'] ?? '');
    ok('lleva a un destino exacto',
       str_contains($nh, 'meta.php') || preg_match('/[?&]ver=\d+/', $nh) === 1, $nh);
    ok('y conserva su marca',
       str_contains((string)($L['norteHref'] ?? ''), 'marca=' . $M), (string)($L['norteHref'] ?? ''));

    $tit = array_map(fn($b) => mb_strtolower((string)$b['titulo']), (array)($L['bloques'] ?? []));
    ok('está el adelanto del calendario',
       in_array('hoy y lo próximo', $tit, true), json_encode($tit));
    ok('y la señal de resultado',   in_array('cómo va', $tit, true), json_encode($tit));
    ok('cada bloque abre su sección',
       !in_array(false, array_map(function ($b) use ($M) {
           $e = (string)$b['enlace'];
           return $e === '' || str_contains($e, 'marca=' . $M);
       }, (array)($L['bloques'] ?? [])), true),
       json_encode(array_column((array)($L['bloques'] ?? []), 'enlace')));

    //  LO QUE NO PUEDE DECIR: un juicio sin cobertura.
    $cuerpo = mb_strtolower((string)($L['texto'] ?? ''));
    foreach (['vamos en ritmo', 'vas cortos', 'en crecimiento'] as $juicio) {
        ok("no dice «{$juicio}»", !str_contains($cuerpo, $juicio), $juicio);
    }

    // ── LA BARRA Y EL MENÚ ────────────────────────────────────────
    echo "\n  — la navegación —\n";
    $bar = array_map(fn($a) => mb_strtolower((string)$a['t']), (array)($L['botnav'] ?? []));
    ok('la barra tiene cuatro destinos', count($bar) === 4, json_encode($bar));
    foreach (['inicio', 'calendario', 'tu meta', 'resultados'] as $d) {
        ok("y está «{$d}»", in_array($d, $bar, true), json_encode($bar));
    }
    ok('todos conservan la marca',
       !in_array(false, array_map(fn($a) => str_contains((string)$a['href'], 'marca=' . $M),
                                  (array)($L['botnav'] ?? [])), true),
       json_encode(array_column((array)($L['botnav'] ?? []), 'href')));

    $menu = (array)($L['menu'] ?? []);
    $vis_movil = array_values(array_filter($menu, fn($a) => empty($a['dup'])));
    $etq = array_map(fn($a) => mb_strtolower((string)$a['t']), $vis_movil);
    ok('«Crear» sigue a mano en el menú',
       in_array('crear', $etq, true), json_encode($etq));
    ok('y «Tus Posts» también',
       in_array('tus posts', $etq, true),
       'llevaba la marca de duplicado sin estar en la barra: era inalcanzable desde el teléfono');
    ok('Tu Meta NO se repite en el móvil',
       !in_array('tu meta', $etq, true),
       'está en la barra de abajo: dos entradas visibles a lo mismo es ruido');
    ok('el menú viene agrupado',
       count((array)($L['grupos'] ?? [])) >= 3, json_encode($L['grupos'] ?? []));

    // ── LA MEDIDA, EN LOS TRES ANCHOS ─────────────────────────────
    echo "\n  — 360, 414 y 1440 —\n";
    foreach (['360', '414', '1440'] as $w) {
        $m = $leer('MED_' . $w);
        ok("@{$w} · sin desbordar a lo ancho", (int)($m['horiz'] ?? 1) === 0,
           'sobran ' . ($m['horiz'] ?? '?') . 'px');
        ok("@{$w} · nada por debajo de 44px",  empty($m['chicos']), json_encode($m['chicos'] ?? []));
        ok("@{$w} · nada por debajo de 14px",  empty($m['finos']),  json_encode($m['finos'] ?? []));
        ok("@{$w} · un solo activo en la barra",
           (int)($m['activosBar'] ?? 0) <= 1, json_encode($m['activosBar'] ?? null));
        ok("@{$w} · un solo activo en el menú",
           (int)($m['activosMenu'] ?? 0) <= 1, json_encode($m['activosMenu'] ?? null));
    }

    //  EL PRIMER VIEWPORT DEL MÓVIL: es donde se gana o se pierde.
    $m360 = $leer('MED_360');
    ok('el saludo cabe sin bajar',      !empty($m360['saludoDentro']), json_encode($m360));
    ok('la Meta cabe ENTERA sin bajar', !empty($m360['norteEntero']),
       (string)($m360['norteRect'] ?? '') . ' · techo ' . (string)($m360['techo'] ?? ''));
    ok('y nada la tapa',                empty($m360['norteBajoAyuda']), json_encode($m360));
    ok('lo siguiente asoma',            !empty($m360['pistaAsoma']),
       'sin una pista de que hay más, nadie baja');

    // ── LOS DESTINOS ──────────────────────────────────────────────
    echo "\n  — y cada cosa abre donde dice —\n";
    $D = $leer('DESTINO');
    ok('la acción de la Meta abre',     !empty($D['hay']), json_encode($D));
    ok('y abre la vista exacta',
       str_contains((string)($D['url'] ?? ''), 'meta.php')
       || preg_match('/[?&]ver=\d+/', (string)($D['url'] ?? '')) === 1,
       (string)($D['url'] ?? ''));
    foreach (['CAL' => 'el Calendario', 'RES' => 'Resultados'] as $k => $nom) {
        $X = $leer($k);
        ok("{$nom} abre",               !empty($X['ok']), json_encode($X));
        ok("{$nom} se marca en la barra", (int)($X['activos'] ?? 0) === 1,
           json_encode($X['activos'] ?? null));
    }

    echo "\n  — la pantalla no grita —\n";
    ok('cero alert()',          (string)($R['ALERTAS'] ?? '1') === '0', (string)($R['ALERTAS'] ?? ''));
    ok('cero errores en consola',
       in_array(trim((string)($R['ERRORES'] ?? '[]')), ['[]', ''], true), (string)($R['ERRORES'] ?? ''));

    echo "\n  — el costo —\n";
    //  SE MIDE EN DINERO. El recorrido pasa por Resultados, que sí deja su
    //  apunte del Analista —y en pruebas es simulado, de coste cero—. Contar
    //  filas haría saltar esto por algo que no cuesta nada.
    ok('abrir Inicio no costó un centavo',
       (float)$pdo->query("SELECT COALESCE(SUM(costo_usd),0) FROM crecer_ia_log
                            WHERE marca_id={$M}")->fetchColumn() < 0.000001,
       'la portada es de lectura: si aquí hay gasto, alguien está pagando por mirar');

    echo "\n  capturas en tests/_capturas/inicio/*.png\n";

} catch (Throwable $e) {
    $fallos++; $n++;
    echo "\n  EXCEPCION · " . get_class($e) . ': ' . $e->getMessage() . "\n";
} finally {
    if ($M > 0) { try { Fixture::limpiar($pdo, $M); echo "\n  (fixture limpiada)\n"; }
                  catch (Throwable $e) {} }
    @unlink($CENT);
}

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0 ? "  EL CENTRO DE MANDO, EN PANTALLA · {$n} afirmaciones\n\n"
                   : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
