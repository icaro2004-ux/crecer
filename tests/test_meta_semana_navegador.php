<?php
// ============================================================
//  CRECER — EL RECORRIDO DE LA SEMANA, EN UN NAVEGADOR DE VERDAD
//  tests/test_meta_semana_navegador.php
//
//  POR QUE NO BASTA MIRAR EL href. Un enlace bien escrito hacia una pantalla
//  que redirige deja al dueno en otro sitio igual, y el fuente no lo dice. Lo
//  unico que demuestra que el recorrido funciona es RECORRERLO: pulsar
//  aprobar, salir a ajustar, volver, y mirar donde se acaba.
//
//  Y SE MIDE LA PANTALLA. Una decision que hay que ir a buscar desplazando no
//  se toma. A 360x800 la accion principal tiene que verse entera, por encima
//  de la barra de abajo, sin que nada la tape.
//
//  Monta el caso de la maqueta: tres publicaciones en la semana 1 —una para
//  aprobar, una para ajustar y una YA PROGRAMADA que va a salir sola—. La
//  tercera es la que obliga a preguntar antes de sustituir.
//
//  SE SALTA, diciendolo, si no hay servidor local o Chrome. Fingir que corrio
//  seria peor que no correrla.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/meta_negocio.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nREVISAR MI SEMANA · NAVEGADOR REAL\n" . str_repeat('=', 58) . "\n";

$CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if (!is_file($CHROME)) { echo "\n  SALTADO · no hay Chrome en esta máquina\n\n"; exit(0); }
$ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true]]);
if (@file_get_contents('http://localhost/crecer/login.php', false, $ctx) === false) {
    echo "\n  SALTADO · el servidor local no responde\n\n"; exit(0);
}

$SHOTS = __DIR__ . '/_capturas/semana';
@mkdir($SHOTS, 0775, true);
foreach (glob($SHOTS . '/*.png') ?: [] as $f) @unlink($f);

$fx   = Fixture::crear($pdo, 'semana-nav', true, 'admin');
$M    = (int)$fx['marca_id'];
$META = meta_activa($pdo, $M);
$PLAN = meta_plan_activo($pdo, (int)$META['id']);
$ARTE = '/crecer/assets/brand/crecer-icon.png';

try {
    // ── EL CASO DE LA MAQUETA: TRES PUBLICACIONES EN LA SEMANA 1 ──
    //  La fixture trae seis pasos repartidos; aqui se juntan tres en la semana
    //  1 para reproducir el «Publicación N de 3» que se dibujó.
    $T1 = (int)$fx['tacticas'][0];   // paso 1 → se revive: es la de aprobar
    $T2 = (int)$fx['tacticas'][1];   // paso 2 → la de ajustar
    $T3 = (int)$fx['tacticas'][4];   // paso 5 → se trae a la semana 1: la comprometida

    $pdo->prepare("UPDATE crecer_meta_tactica SET estado='pendiente', por_que=? WHERE id=?")
        ->execute(['el precio a la vista quita la fricción de preguntar', $T1]);
    $pdo->prepare("UPDATE crecer_meta_tactica SET por_que=? WHERE id=?")
        ->execute(['que lo diga una clienta pesa más que decirlo tú', $T2]);
    $pdo->prepare("UPDATE crecer_meta_tactica SET semana=1, orden=3, estado='pendiente', por_que=? WHERE id=?")
        ->execute(['recordar la entrega mantiene el pedido caliente', $T3]);

    //  Pieza 1 · lista para el OK (tiene arte, así que la primaria es Aprobar).
    $pdo->prepare("INSERT INTO crecer_contenido
            (marca_id,plataforma,tipo,caption,estado,meta_id,plan_id,tactica_id,fecha_programada,grafica_path)
          VALUES (?, 'instagram','post',?, 'borrador',?,?,?, DATE_ADD(NOW(), INTERVAL 2 DAY), ?)")
        ->execute([$M, 'Texto de relleno de la primera publicación.', (int)$META['id'],
                   (int)$PLAN['id'], $T1, $ARTE]);
    $P1 = (int)$pdo->lastInsertId();

    //  Pieza 2 · la que se va a ajustar (texto, fecha, imagen).
    $P2 = (int)$fx['piezas'][0];
    $pdo->prepare("UPDATE crecer_contenido SET tactica_id=?, grafica_path=?, estado='borrador',
                          fecha_programada=DATE_ADD(NOW(), INTERVAL 3 DAY)
                    WHERE id=? AND marca_id=?")->execute([$T2, $ARTE, $P2, $M]);

    //  Pieza 3 · YA PROGRAMADA con fecha futura: va a salir sola.
    $pdo->prepare("INSERT INTO crecer_contenido
            (marca_id,plataforma,tipo,caption,estado,meta_id,plan_id,tactica_id,fecha_programada,grafica_path)
          VALUES (?, 'facebook','post',?, 'programado',?,?,?, DATE_ADD(NOW(), INTERVAL 4 DAY), ?)")
        ->execute([$M, 'Texto de relleno ya programado.', (int)$META['id'],
                   (int)$PLAN['id'], $T3, $ARTE]);
    $P3 = (int)$pdo->lastInsertId();

    //  Y UNA PIEZA CREADA A MANO: la que el calendario debe llamar «Creado por
    //  ti», no «De tu Meta». `calendario_id` es la huella del camino manual, y
    //  tiene FK: hace falta el calendario de verdad, no un número inventado.
    $pdo->prepare("INSERT INTO crecer_calendario (marca_id,anio,mes,estado,generado_por_ia)
                   VALUES (?,?,?, 'activo', 0)")
        ->execute([$M, (int)date('Y'), (int)date('n')]);
    $CAL = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO crecer_contenido
            (calendario_id,marca_id,plataforma,tipo,caption,estado,fecha_programada,grafica_path)
          VALUES (?,?, 'instagram','post',?, 'borrador', DATE_ADD(NOW(), INTERVAL 1 DAY), ?)")
        ->execute([$CAL, $M, 'Texto de relleno hecho por el dueño.', $ARTE]);
    $PM = (int)$pdo->lastInsertId();

    //  Sesión de Apache escrita a mano (mismo save_path). Sin contraseñas.
    $sid  = 'sem' . bin2hex(random_bytes(8));
    $ruta = session_save_path() ?: sys_get_temp_dir();
    file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
        'usuario_id|i:' . (int)$fx['usuario_id'] . ';');

    $cap_antes = (string)$pdo->query("SELECT caption FROM crecer_contenido WHERE id={$P2}")->fetchColumn();
    $fec_antes = (string)$pdo->query("SELECT fecha_programada FROM crecer_contenido WHERE id={$P2}")->fetchColumn();

    $cmd = 'node ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . '_semana.mjs')
         . ' ' . escapeshellarg($sid) . ' ' . $M . ' ' . $T3 . ' ' . escapeshellarg($SHOTS) . ' 2>&1';
    $sal = []; exec($cmd, $sal);
    $r = [];
    foreach ($sal as $l) { if (strpos($l, '=') !== false) { [$k, $v] = explode('=', trim($l), 2); $r[$k] = $v; } }

    ok('el navegador completó el recorrido', ($r['OK'] ?? '0') === '1',
       ($r['ERROR'] ?? '') ?: implode(' | ', array_slice($sal, -4)));
    if (($r['OK'] ?? '0') !== '1') { throw new RuntimeException('sin recorrido no hay nada que afirmar'); }

    $J = fn($k) => json_decode($r[$k] ?? 'null', true);

    // ══ 1 · SÉ DÓNDE ESTOY Y CUÁNTO ME QUEDA ═══════════════════
    echo "\n  — una pantalla a la que no se llega es una pantalla que no existe —\n";
    ok('Tu Meta enseña la entrada a la semana', ($r['ENTRADA_HAY'] ?? '') === 'true',
       'sin el enlace, el cliente no encuentra la revisión aunque esté construida');
    ok('y dice CUÁNTAS hay esperando', strpos($r['ENTRADA_TX'] ?? '', '3') !== false,
       ($r['ENTRADA_TX'] ?? '') . ' — «revisar mi semana» a secas no dice si hay trabajo');
    ok('el enlace lleva a la revisión', strpos($r['ENTRADA_LLEVA_A'] ?? '', 'vista=semana') !== false,
       $r['ENTRADA_LLEVA_A'] ?? '');

    echo "\n  — «2 de 3» es lo que convierte una cola en una tarea que se acaba —\n";
    ok('la vista se monta en Tu Meta', strpos($r['URL_1'] ?? '', 'vista=semana') !== false, $r['URL_1'] ?? '');
    ok('dice la publicación y el total', ($r['PASO_1'] ?? '') === 'Publicación 1 de 3', $r['PASO_1'] ?? '');
    ok('la barra tiene un tramo por publicación', ($r['BARRA_N'] ?? '') === '3', $r['BARRA_N'] ?? '');

    // ══ 2 · LA PANTALLA A 360x800 ══════════════════════════════
    echo "\n  — una decisión que hay que ir a buscar no se toma —\n";
    foreach (['MED_360' => '360×800', 'MED_HOJA_360' => 'la hoja a 360×800',
              'MED_GATE_360' => 'la puerta a 360×800', 'MED_414' => '414×896',
              'MED_1440' => '1440×900'] as $k => $como) {
        $m = $J($k);
        if (!$m) { ok("hay medida de {$como}", false, 'no llegó'); continue; }
        ok("{$como}: cero scroll horizontal", (int)$m['horiz'] === 0, 'sobran ' . $m['horiz'] . 'px');
        ok("{$como}: nada se sale del ancho", count($m['fuera']) === 0, implode(' · ', array_slice($m['fuera'], 0, 3)));
        ok("{$como}: todo lo que se toca mide 44+", count($m['chicos']) === 0, implode(' · ', array_slice($m['chicos'], 0, 3)));
        ok("{$como}: el texto de decidir es 14px+", count($m['finos']) === 0, implode(' · ', array_slice($m['finos'], 0, 3)));
        ok("{$como}: cero emoji en la interfaz", count($m['emo']) === 0, implode(' ', $m['emo']));
        ok("{$como}: Ayuda no se sienta encima de nada",
           count($m['tapadosPorAyuda'] ?? []) === 0,
           implode(' · ', array_slice($m['tapadosPorAyuda'] ?? [], 0, 3))
           . ' — un control debajo del FAB no es un control');
        ok("{$como}: una sola acción principal", (int)$m['primarias'] <= 1, 'hay ' . $m['primarias']);
        if ($m['primarias'] > 0) {
            ok("{$como}: la acción se ve sin desplazar", $m['primVisible'] === true,
               'rect ' . ($m['primRect'] ?? '?') . ' — si cae bajo la barra, no existe');
            ok("{$como}: y nada la tapa", $m['primTapada'] === false);
        }
    }

    // ══ 3 · APROBAR ════════════════════════════════════════════
    echo "\n  — aprobar escribe de verdad, y lo que se dice después sale del dato —\n";
    ok('la 1 ofrece Aprobar', ($r['POS1_TIENE_APROBAR'] ?? '') === 'true');
    ok('tras aprobar, avanza sola a la 2', ($r['TRAS_APROBAR_PASO'] ?? '') === 'Publicación 2 de 3',
       $r['TRAS_APROBAR_PASO'] ?? '');
    ok('y la URL guarda el sitio', strpos($r['TRAS_APROBAR_URL'] ?? '', 'pos=2') !== false,
       $r['TRAS_APROBAR_URL'] ?? '');
    $est1 = (string)$pdo->query("SELECT estado FROM crecer_contenido WHERE id={$P1}")->fetchColumn();
    ok('la pieza quedó aprobada EN LA BASE', $est1 === 'aprobado', 'estado=' . $est1);
    ok('y se le dice cuándo sale, no «en su fecha»',
       strpos($r['TRAS_APROBAR_HECHO'] ?? '', 'Sale el') !== false, $r['TRAS_APROBAR_HECHO'] ?? '');
    ok('sin dos puntos al final', strpos($r['TRAS_APROBAR_HECHO'] ?? '', '..') === false,
       $r['TRAS_APROBAR_HECHO'] ?? '');

    // ══ 4 · AJUSTAR · la hoja y la vuelta al mismo sitio ═══════
    echo "\n  — al cerrar la capa se vuelve a la publicación, no al principio —\n";
    ok('la hoja se abre sobre la pieza', ($r['HOJA_ABIERTA'] ?? '') === 'true');
    ok('y pregunta una sola cosa', ($r['HOJA_TIT'] ?? '') === '¿Qué quieres ajustar?', $r['HOJA_TIT'] ?? '');
    $filas = $J('HOJA_FILAS') ?: [];
    ok('ofrece texto, imagen y fecha', in_array('texto', $filas, true) && in_array('arte', $filas, true)
       && in_array('fecha', $filas, true), implode(',', $filas));

    ok('el editor de texto abre', ($r['TEXTO_ABRE'] ?? '') === 'true');
    $cap_ahora = (string)$pdo->query("SELECT caption FROM crecer_contenido WHERE id={$P2}")->fetchColumn();
    ok('el texto se guardó EN LA BASE', $cap_ahora !== $cap_antes
       && strpos($cap_ahora, 'editado desde la revision semanal') !== false, $cap_ahora);
    ok('la hoja se cerró sola', ($r['TEXTO_HOJA_CERRADA'] ?? '') === 'true');
    ok('el texto nuevo se ve en la pieza',
       strpos($r['TEXTO_EN_PANTALLA'] ?? '', 'editado desde la revision semanal') !== false);
    ok('y sigo en la MISMA publicación', ($r['TEXTO_SIGO_EN_POS'] ?? '') === 'Publicación 2 de 3',
       $r['TEXTO_SIGO_EN_POS'] ?? '');

    ok('el selector de fecha abre', ($r['FECHA_ABRE'] ?? '') === 'true');
    $fec_ahora = (string)$pdo->query("SELECT fecha_programada FROM crecer_contenido WHERE id={$P2}")->fetchColumn();
    ok('la fecha se movió EN LA BASE', $fec_ahora !== $fec_antes, "{$fec_antes} → {$fec_ahora}");
    ok('y sigo en la MISMA publicación', ($r['FECHA_SIGO_EN_POS'] ?? '') === 'Publicación 2 de 3',
       $r['FECHA_SIGO_EN_POS'] ?? '');

    // ══ 5 · SALIR A LA IMAGEN Y VOLVER ════════════════════════
    echo "\n  — salir a otra pantalla y volver a la publicación exacta —\n";
    ok('la imagen abre donde se hace', strpos($r['ARTE_URL'] ?? '', 'aprobar2.php') !== false, $r['ARTE_URL'] ?? '');
    ok('y se lleva el regreso con la posición', ($r['ARTE_LLEVA_POS'] ?? '') === 'true', $r['ARTE_URL'] ?? '');
    ok('esa pantalla enseña la vuelta', ($r['ARTE_TIENE_VUELTA'] ?? '') === 'true');
    ok('y vuelve a la revisión semanal', strpos($r['ARTE_VUELVE_A'] ?? '', 'vista=semana') !== false,
       $r['ARTE_VUELVE_A'] ?? '');
    ok('en la MISMA publicación', ($r['ARTE_VUELVE_PASO'] ?? '') === 'Publicación 2 de 3',
       $r['ARTE_VUELVE_PASO'] ?? '');

    // ══ 6 · «NO PUEDO CON ESTA» ═══════════════════════════════
    echo "\n  — la salida de la jugada imposible, ida y vuelta —\n";
    ok('la publicación ofrece cambiarla', ($r['POS2_TIENE_NOPUEDO'] ?? '') === 'true');
    ok('abre el wizard de sustituir', ($r['SUST_ES_WIZARD'] ?? '') === 'true');
    ok('llevándose desde=semana y la posición', ($r['SUST_LLEVA_DESDE'] ?? '') === 'true', $r['SUST_URL'] ?? '');
    ok('«volver sin cambiar nada» vuelve a la semana',
       strpos($r['SUST_VUELVE_A'] ?? '', 'vista=semana') !== false, $r['SUST_VUELVE_A'] ?? '');
    ok('y a la MISMA publicación', ($r['SUST_VUELVE_PASO'] ?? '') === 'Publicación 2 de 3',
       $r['SUST_VUELVE_PASO'] ?? '');

    // ══ 7 · LA PIEZA COMPROMETIDA ═════════════════════════════
    echo "\n  — lo que ya va a salir solo no se quita sin preguntar —\n";
    ok('con una pieza programada NO se abre el wizard de una',
       ($r['GATE_ES_PUERTA'] ?? '') === 'true',
       'sustituir sin preguntar dejaría viva la publicación que el dueño acaba de rechazar');
    ok('se dice que ya está en el calendario',
       mb_strpos($r['GATE_TIT'] ?? '', 'calendario') !== false, $r['GATE_TIT'] ?? '');
    ok('la puerta no deja frases con dos puntos',
       strpos($r['GATE_CONSERVAR_TX'] ?? '', '..') === false, $r['GATE_CONSERVAR_TX'] ?? '');
    $ops = $J('GATE_OPCIONES') ?: [];
    ok('y se ofrecen exactamente dos salidas', count($ops) === 2, implode(' | ', $ops));
    ok('una es conservarla', (bool)array_filter($ops, fn($o) => mb_stripos($o, 'Conservar') !== false), implode(' | ', $ops));
    ok('y la otra quitarla del calendario',
       (bool)array_filter($ops, fn($o) => mb_stripos($o, 'Quitarla') !== false), implode(' | ', $ops));

    ok('«conservar» vuelve a la semana', strpos($r['GATE_CONSERVAR_VUELVE'] ?? '', 'vista=semana') !== false,
       $r['GATE_CONSERVAR_VUELVE'] ?? '');
    ok('y a la publicación 3', ($r['GATE_CONSERVAR_PASO'] ?? '') === 'Publicación 3 de 3',
       $r['GATE_CONSERVAR_PASO'] ?? '');
    //  LO IMPORTANTE: conservar no toca NADA.
    ok('conservar no tocó la pieza programada',
       (string)$pdo->query("SELECT estado FROM crecer_contenido WHERE id={$P3}")->fetchColumn() === 'programado');
    ok('ni sustituyó la jugada',
       empty($pdo->query("SELECT sustituida_at FROM crecer_meta_tactica WHERE id={$T3}")->fetchColumn()));

    ok('«quitarla» sí abre el wizard', ($r['GATE_QUITAR_ES_WIZARD'] ?? '') === 'true');
    ok('con quitar=1 en la URL', strpos($r['GATE_QUITAR_URL'] ?? '', 'quitar=1') !== false,
       $r['GATE_QUITAR_URL'] ?? '');
    ok('y el repaso avisa de que la saca del calendario',
       mb_stripos($r['GATE_QUITAR_AVISA'] ?? '', 'calendario') !== false, $r['GATE_QUITAR_AVISA'] ?? '');

    // ══ 8 · EL CALENDARIO DICE DE DÓNDE VIENE CADA COSA ═══════
    echo "\n  — «De tu Meta» y «Creado por ti» no son lo mismo —\n";
    $orig = $J('CAL_ORIGENES') ?: [];
    $todo = implode(' || ', $orig);
    ok('el calendario trae piezas', count($orig) > 0, $todo);
    ok('lo del plan se llama «De tu Meta»', strpos($todo, 'De tu Meta') !== false, $todo);
    ok('lo que hizo el dueño se llama «Creado por ti»',
       (bool)array_filter($orig, fn($o) => strpos((string)$o, 'Creado por ti') !== false
                                        && strpos((string)$o, 'De tu Meta') === false), $todo);

    // ══ 9 · ESCRITORIO Y CONSOLA ══════════════════════════════
    echo "\n  — el escritorio no es el móvil estirado —\n";
    ok('a 1440 se ve la semana entera al lado', (int)($r['ESCRITORIO_LISTA'] ?? 0) === 3,
       'lista=' . ($r['ESCRITORIO_LISTA'] ?? '?'));
    $errores = $J('ERRORES') ?: [];
    ok('la consola queda limpia', count($errores) === 0, implode(' · ', array_slice($errores, 0, 3)));

    // ══ 10 · LAS CAPTURAS EXISTEN Y NO SON LA MISMA ═══════════
    echo "\n  — dos pantallas distintas no pueden dar el mismo PNG —\n";
    $pngs = glob($SHOTS . '/*.png') ?: [];
    ok('se guardaron capturas', count($pngs) >= 6, count($pngs) . ' archivos');
    $huellas = [];
    foreach ($pngs as $f) { $huellas[] = md5_file($f); ok('«' . basename($f) . '» no está vacía', filesize($f) > 3000, filesize($f) . ' bytes'); }
    ok('y ninguna es idéntica a otra', count($huellas) === count(array_unique($huellas)),
       'capturas iguales = el recorte cayó fuera de la página');

} finally {
    Fixture::limpiar($pdo, $M);
    echo "\n  (fixture limpiada · capturas en tests/_capturas/semana)\n";
}

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0 ? "  RECORRIDO CUMPLIDO · {$n} afirmaciones\n\n"
                   : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
