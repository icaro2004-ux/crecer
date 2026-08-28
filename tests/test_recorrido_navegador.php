<?php
// ============================================================
//  CRECER — EL RECORRIDO, VISTO POR EL DUEÑO
//  tests/test_recorrido_navegador.php
//
//  `test_recorrido_integral.php` dice que la costura aguanta. Esto dice si el
//  dueño la RECORRE: nueve pantallas seguidas en un Android de 360, con el
//  pulgar, sin quedarse encerrado en ninguna y sin perder de vista en qué
//  negocio está.
//
//  LAS NUEVE CAPTURAS DEL ENTREGABLE:
//    1 Mi negocio   2 Plan listo    3 Revisión semanal   4 Calendario
//    5 Publicación  6 Resultados    7 Próxima semana     8 Sala   9 Inicio
//
//  Y LO QUE NO SE PUEDE MIRAR EN PHP: que la barra marque dónde está, que la
//  marca viaje en cada enlace, que Ayuda no se siente encima de la acción, que
//  atrás/adelante del navegador no borren el contexto, y que Crear y Mi negocio
//  se alcancen desde el menú.
//
//  CERO PROVEEDORES, y aquí hacen falta los dos candados: `_sin_gasto.php`
//  para este proceso —que siembra el estado llamando al ejecutor— y el
//  centinela `_SIN_CREDENCIALES` para lo que entra por Apache.
// ============================================================

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/meta_negocio.php';
require_once __DIR__ . '/../includes/meta_ciclo.php';
require_once __DIR__ . '/../includes/meta_ejecutar.php';
require_once __DIR__ . '/../includes/sala_oportunidad.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
$notas = [];
/** Detalle menor: no bloquea el recorrido, pero se anota para después. */
function nota(string $que): void {
    global $notas;
    if (!in_array($que, $notas, true)) $notas[] = $que;
}
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nEL RECORRIDO, EN PANTALLA\n" . str_repeat('=', 58) . "\n";

$ctx0 = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
if (@file_get_contents('http://localhost/crecer/login.php', false, $ctx0) === false) {
    echo "\n  SALTADO · el servidor local no responde\n\n"; exit(0);
}
if (!is_file('C:/Program Files/Google/Chrome/Application/chrome.exe')) {
    echo "\n  SALTADO · no hay Chrome\n\n"; exit(0);
}
if (!ciclo_hay_libro($pdo, true) || !sala_op_hay_libro($pdo, true)) {
    echo "\n  SALTADO · faltan migraciones del ciclo semanal o de La Sala\n\n"; exit(0);
}

$SHOTS = __DIR__ . '/_capturas/recorrido';
if (!is_dir($SHOTS)) @mkdir($SHOTS, 0777, true);

$CENT = __DIR__ . '/../includes/_SIN_CREDENCIALES';
file_put_contents($CENT, "recorrido en pantalla · " . date('c') . "\n");
register_shutdown_function(function () use ($CENT) { @unlink($CENT); });

$limpiar = [];
$gasto = null; $reales = null;
try {
    // ══════════════════════════════════════════════════════════════
    //  UN NEGOCIO A MITAD DE CAMINO
    // ══════════════════════════════════════════════════════════════
    //  El recorrido se mira en el momento con MÁS cosas a la vez: plan vivo,
    //  semana por revisar, algo programado, algo ya publicado, resultados sin
    //  cobertura y una idea esperando en La Sala. Es el estado donde una
    //  contradicción entre pantallas se ve de verdad.
    echo "\n  — un negocio a mitad de camino —\n";
    $fx = Fixture::crear($pdo, 'recnav', true, 'admin');
    $limpiar[] = $M = (int)$fx['marca_id'];
    $META = (int)$fx['meta_id']; $PLAN = (int)$fx['plan_id'];
    foreach (['inicio','meta','semana','calendario','resultados','sala','crear','reels'] as $p) {
        try { $pdo->prepare("INSERT IGNORE INTO crecer_tour_visto (marca_id,clave,visto_at)
                              VALUES (?,?,NOW())")->execute([$M, $p]); } catch (Throwable $e) {}
    }
    $pdo->prepare("INSERT INTO crecer_conexiones
            (marca_id, proveedor, estado, ig_user_id, fb_page_id, page_access_token)
          VALUES (?, 'meta', 'activa', '17000000008', '10000000008', 'token-de-prueba')")
        ->execute([$M]);

    //  Una programada y una publicada: las dos historias que el Calendario
    //  tiene que contar a la vez.
    $poner = function (string $estado, string $cuando, ?string $pub = null)
                 use ($pdo, $M, $META, $PLAN): int {
        $pdo->prepare("INSERT INTO crecer_contenido
                (marca_id, plataforma, tipo, caption, estado, fecha_programada,
                 publicado_at, meta_id, plan_id)
              VALUES (?, 'instagram','post','[prueba] El combo del sábado, con precio a la vista',
                      ?, ?, ?, ?, ?)")
            ->execute([$M, $estado, $cuando, $pub, $META, $PLAN]);
        return (int)$pdo->lastInsertId();
    };
    $PROG = $poner('aprobado',  date('Y-m-d 10:00:00', strtotime('+2 days')));
    $PUB  = $poner('publicado', date('Y-m-d 09:00:00', strtotime('-2 days')),
                                date('Y-m-d 09:03:00', strtotime('-2 days')));

    //  Y UNA IDEA ESPERANDO EN LA SALA, con su propuesta ya hecha.
    $pdo->prepare("INSERT INTO crecer_sala_jobs (marca_id,mensaje,historial,puede_producir,estado,respuesta)
                   VALUES (?,'[prueba] vi algo que puede servir','[]',1,'done',?)")
        ->execute([$M, 'Eso da para un reel corto del proceso.']);
    $JOB = (int)$pdo->lastInsertId();
    sala_op_guardar($pdo, $JOB, $M, sala_op_normalizar($pdo, $M, [
        'titulo' => 'El proceso del combo', 'que_hacer' => 'Un reel de 20 segundos.',
        'por_que' => 'Ver el proceso da confianza para ordenar.',
        'formato' => 'reel', 'red' => 'instagram', 'fuente' => 'dueno', 'alineada' => true]));

    $sid  = 'rcn' . bin2hex(random_bytes(7));
    file_put_contents((session_save_path() ?: sys_get_temp_dir())
                      . DIRECTORY_SEPARATOR . 'sess_' . $sid, 'usuario_id|i:' . (int)$fx['usuario_id'] . ';');

    $cmd = 'node ' . escapeshellarg(__DIR__ . '/_recorrido_probe.mjs') . ' '
         . escapeshellarg($SHOTS) . ' ' . escapeshellarg($sid) . ' ' . $M . ' ' . $PUB;
    $sal = (string)shell_exec($cmd . ' 2>&1');
    $R = ['_raw' => $sal];
    foreach (explode("\n", $sal) as $l) {
        $l = trim($l); $i = strpos($l, '=');
        if ($i > 0) $R[substr($l, 0, $i)] = substr($l, $i + 1);
    }
    ok('el navegador recorrió', ($R['OK'] ?? '0') === '1', substr($sal, -600));
    if (($R['OK'] ?? '0') !== '1') throw new RuntimeException('sonda caída');

    // ══════════════════════════════════════════════════════════════
    //  LAS NUEVE PARADAS
    // ══════════════════════════════════════════════════════════════
    $PARADAS = [
        '1_mi_negocio'       => 'Mi negocio',
        '2_plan_listo'       => 'el plan',
        '3_revision_semanal' => 'la semana',
        '4_calendario'       => 'el Calendario',
        '5_publicacion'      => 'la publicación',
        '6_resultados'       => 'Resultados',
        '7_proxima_semana'   => 'la próxima semana',
        '8_sala'             => 'La Sala',
        '9_inicio'           => 'Inicio',
    ];
    foreach ($PARADAS as $clave => $etq) {
        echo "\n  {$etq}\n";
        $p = json_decode((string)($R['P_' . $clave] ?? '{}'), true) ?: [];
        ok("{$etq} · abre",              !empty($p['url']), json_encode($p));
        //  UN AVISO DE PHP ENCIMA DEL TÍTULO le dice al dueño que el sitio está
        //  roto aunque funcione.
        ok("{$etq} · sin avisos de PHP", trim((string)($p['avisos'] ?? '')) === '',
           (string)($p['avisos'] ?? ''));
        //  NI UN HUECO SIN LLENAR. Un «--°» o un «undefined» en pantalla le
        //  dice al dueño que algo se rompió, aunque todo lo demás funcione.
        ok("{$etq} · sin huecos sin llenar", empty($p['placeholder']),
           'hay un marcador de posición a la vista');
        ok("{$etq} · sin scroll lateral", (int)($p['horiz'] ?? 1) === 0, (string)($p['horiz'] ?? ''));
        //  NINGÚN CALLEJÓN: de toda pantalla se sale.
        ok("{$etq} · tiene salida",      (int)($p['salidas'] ?? 0) > 0, (string)($p['salidas'] ?? ''));
        //  LA BARRA: una sola marcada, sin solaparse, y con la marca a cuestas.
        //  NUNCA DOS MARCADAS: decirle al dueño que está en dos sitios a la
        //  vez es peor que no decirle nada. Que no marque NINGUNA en las
        //  pantallas que no son destino de la barra —Mi negocio, Tus Posts,
        //  La Sala— es consecuencia de la Fase 6, no un fallo: se anota.
        ok("{$etq} · nunca dos marcadas en la barra",
           count((array)($p['activo'] ?? [])) <= 1, json_encode($p['activo'] ?? []));
        if (count((array)($p['activo'] ?? [])) === 0) {
            nota("la barra de abajo no marca nada en «{$etq}»: no es uno de sus "
               . 'cuatro destinos y el dueño se queda sin señal de dónde está');
        }
        ok("{$etq} · la barra no se pisa", (int)($p['solapa'] ?? 1) === 0, (string)($p['solapa'] ?? ''));
        //  SI UN DESTINO PIERDE LA MARCA, un toque deja al dueño en otro
        //  negocio — o en ninguno.
        ok("{$etq} · la marca viaja",    empty($p['sinMarca']), json_encode($p['sinMarca'] ?? []));
        //  AYUDA FLOTA SOBRE EL DOCK, así que se cruza con la acción principal
        //  cuando ésta cae en esa banda. Donde la acción está FIJA —la caja de
        //  escribir de La Sala— eso es permanente y se arregló; donde la acción
        //  scrollea, el dueño se libra bajando un dedo. Se anota con nombre y
        //  apellido para que la decisión de moverla sea de Manuel, no mía.
        //  LO QUE SÍ ES UN FALLO: que tape algo CLAVADO. Ahí no hay scroll que
        //  valga y la acción, sencillamente, no se puede pulsar.
        ok("{$etq} · Ayuda no tapa nada clavado", empty($p['tapaFija']),
           (string)($p['tapaQue'] ?? ''));
        if (!empty($p['tapa'])) {
            nota("en «{$etq}» el botón de Ayuda se cruza con la acción principal ("
               . (string)($p['tapaQue'] ?? '?') . ')');
        }
        //  LA LETRA FINA SE ANOTA. El producto solo se ha comprometido con los
        //  14px en la ZONA DE DECISIÓN —ahí sí hay una prueba que lo exige—,
        //  no en toda la pantalla. Contarlo aquí como fallo sería inventar un
        //  estándar que nadie adoptó; callarlo sería perder el dato.
        if ((int)($p['finos'] ?? 0) > 0) {
            nota("«{$etq}» tiene " . (int)$p['finos'] . ' textos por debajo de 14px');
        }
        ok("{$etq} · su captura",
           is_file($SHOTS . '/' . $clave . '_360.png')
           && filesize($SHOTS . '/' . $clave . '_360.png') > 9000);
    }

    // ══════════════════════════════════════════════════════════════
    //  ATRÁS, ADELANTE, Y EL CAJÓN
    // ══════════════════════════════════════════════════════════════
    echo "\n  atrás, adelante y el menú\n";
    $at = json_decode((string)($R['ATRAS'] ?? '{}'), true) ?: [];
    $ad = json_decode((string)($R['ADELANTE'] ?? '{}'), true) ?: [];
    ok('atrás vuelve al Calendario',   str_contains((string)($at['url'] ?? ''), 'calendario.php'),
       (string)($at['url'] ?? ''));
    ok('y conserva la marca',          str_contains((string)($at['url'] ?? ''), 'marca=' . $M),
       (string)($at['url'] ?? ''));
    ok('sin avisos al volver',         trim((string)($at['avisos'] ?? '')) === '', (string)($at['avisos'] ?? ''));
    ok('adelante devuelve a Resultados', str_contains((string)($ad['url'] ?? ''), 'resultados.php'),
       (string)($ad['url'] ?? ''));
    ok('y también conserva la marca',  str_contains((string)($ad['url'] ?? ''), 'marca=' . $M),
       (string)($ad['url'] ?? ''));

    $cj = json_decode((string)($R['CAJON'] ?? '{}'), true) ?: [];
    ok('el menú abre',                 !empty($cj['abre']), json_encode($cj));
    ok('con Crear dentro',             !empty($cj['crear']), json_encode($cj['etiquetas'] ?? []));
    ok('y con Mi negocio',             !empty($cj['negocio']), json_encode($cj['etiquetas'] ?? []));
    //  EL «Centro de Operaciones» NO lleva marca a propósito: es la consola de
    //  administración, y esta fixture es admin para que el candado de
    //  suscripción no la mande a la venta. Lo que sí importa —que un cliente no
    //  lo vea— se comprueba justo debajo, con una sesión de cliente de verdad.
    $sin_marca_cliente = array_values(array_filter((array)($cj['sinMarca'] ?? []),
        fn($x) => mb_stripos((string)$x, 'Operaciones') === false));
    ok('y todo el menú del cliente lleva la marca', $sin_marca_cliente === [],
       json_encode($sin_marca_cliente));
    ok('sin errores de JavaScript',    ($R['ERRORES'] ?? '[]') === '[]', (string)($R['ERRORES'] ?? ''));

    // ══════════════════════════════════════════════════════════════
    //  Y LA CONSOLA DE ADMINISTRACIÓN NO EXISTE PARA EL CLIENTE
    // ══════════════════════════════════════════════════════════════
    //  La fixture de arriba es admin —si no, el candado de suscripción la
    //  manda a la venta y no se estaría probando el recorrido—, así que ve el
    //  «Centro de Operaciones» con razón. Un cliente de verdad no puede.
    echo "
  el cliente no ve la consola
";
    $fc = Fixture::crear($pdo, 'recnavcli', true, 'proveedor');
    $limpiar[] = $MC = (int)$fc['marca_id'];
    $sc = 'rcc' . bin2hex(random_bytes(7));
    file_put_contents((session_save_path() ?: sys_get_temp_dir())
                      . DIRECTORY_SEPARATOR . 'sess_' . $sc, 'usuario_id|i:' . (int)$fc['usuario_id'] . ';');
    $htmlc = (string)@file_get_contents('http://localhost/crecer/panel/index.php?marca=' . $MC, false,
        stream_context_create(['http' => ['timeout' => 60, 'ignore_errors' => true,
                                          'header' => "Cookie: PHPSESSID={$sc}
"]]));
    ok('el cliente no ve el Centro de Operaciones',
       $htmlc !== '' && mb_stripos($htmlc, 'Centro de Operaciones') === false,
       $htmlc === '' ? 'la página no respondió' : 'aparece en el menú de un cliente');
    ok('ni el enlace a admin.php',
       $htmlc !== '' && !str_contains($htmlc, '/panel/admin.php'),
       'un cliente con la URL a mano es otra cosa: aquí se mira que no se la den hecha');

    //  EL COSTO, con la marca todavía viva y solo la suya.
    $gasto = (float)$pdo->query("SELECT COALESCE(SUM(costo_usd),0) FROM crecer_ia_log
                                  WHERE marca_id={$M}")->fetchColumn();
    $reales = $pdo->query("SELECT DISTINCT modelo FROM crecer_ia_log WHERE marca_id={$M}
                            AND (modelo LIKE 'gemini%' OR modelo LIKE 'gpt%'
                              OR modelo LIKE 'claude%' OR modelo LIKE 'vertex%')")
                  ->fetchAll(PDO::FETCH_COLUMN);

} catch (Throwable $e) {
    $fallos++; $n++;
    echo "  FALLA excepción\n         → " . get_class($e) . ': ' . $e->getMessage()
       . "\n         → " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    foreach ($limpiar as $mid) { try { Fixture::limpiar($pdo, (int)$mid); } catch (Throwable $e) {} }
    try { $pdo->exec("DELETE FROM crecer_sala_jobs WHERE mensaje LIKE '[prueba]%'"); } catch (Throwable $e) {}
    @unlink($CENT);
    echo "\n  (fixture limpiada · centinela retirado)\n";
}

echo "\n  — el costo —\n";
ok('recorrer no cuesta nada',
   isset($gasto) && $gasto < 0.000001,
   isset($gasto) ? 'gastó ' . number_format($gasto, 6) : 'no se llegó a medir');
ok('ni llama a un proveedor real',
   isset($reales) && $reales === [], isset($reales) ? implode(', ', $reales) : 'no se llegó a medir');

if ($notas) {
    echo "\n  — anotado para después —\n";
    foreach ($notas as $x) echo "  ·    $x\n";
}

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0 ? "  EL RECORRIDO, EN PANTALLA · {$n} afirmaciones\n\n"
                   : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
