<?php
// ============================================================
//  CRECER — EL RECORRIDO DE CREAR LA META (TRAMO 2A)
//  tests/test_meta_wizard_recorrido.php
//
//  EL CONTRATO QUE FIJA. El dueño escoge qué quiere lograr, cuánto y para
//  cuándo, entiende que puede aportar sus fotos y videos —ahora o después— y
//  recién entonces confirma. Hasta ese último botón NO SE ESCRIBE NADA: ni
//  meta, ni plan, ni intención. Y si sale a su Biblioteca y vuelve, sus
//  respuestas siguen ahí; perderlas es como se abandona un formulario.
//
//  POR QUE EN NAVEGADOR. Las respuestas del wizard viven en el cliente. Que
//  sobrevivan a un «Atrás», a abrir una capa y a un viaje a Biblioteca no se
//  puede afirmar leyendo PHP: hay que ir y volver.
//
//  CERO PROVEEDOR, Y MEDIDO. Este recorrido no llega a crear la meta —eso es
//  el Tramo 2B—, asi que no debe salir ni una llamada. Se cuentan
//  crecer_ia_log y crecer_img_cuota_asiento antes y despues, y se exige CERO
//  de diferencia. Si algun dia un paso empieza a llamar a alguien, esto lo
//  dice el mismo dia.
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

echo "\nCREAR LA META · EL RECORRIDO\n" . str_repeat('=', 58) . "\n";

$CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if (!is_file($CHROME)) { echo "\n  SALTADO · no hay Chrome en esta máquina\n\n"; exit(0); }
$ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true]]);
if (@file_get_contents('http://localhost/crecer/login.php', false, $ctx) === false) {
    echo "\n  SALTADO · el servidor local no responde\n\n"; exit(0);
}

$SHOTS = __DIR__ . '/_capturas/wizard';
@mkdir($SHOTS, 0775, true);
foreach (glob($SHOTS . '/*.png') ?: [] as $f) @unlink($f);

//  UNA MARCA SIN META: es el estado en el que vive el wizard.
$fx = Fixture::crear($pdo, 'wizard', false, 'admin');
$M  = (int)$fx['marca_id'];

$sid  = 'wiz' . bin2hex(random_bytes(8));
$ruta = session_save_path() ?: sys_get_temp_dir();
file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
    'usuario_id|i:' . (int)$fx['usuario_id'] . ';');

//  EL PRECIO, contado y no prometido.
$cnt = fn(string $t) => (int)$pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
$ia_antes  = $cnt('crecer_ia_log');
$img_antes = $cnt('crecer_img_cuota_asiento');

try {
    $cmd = 'node ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . '_wizard_probe.mjs')
         . ' ' . escapeshellarg($sid) . ' ' . $M . ' ' . escapeshellarg($SHOTS) . ' 2>&1';
    $sal = []; exec($cmd, $sal);
    $r = [];
    foreach ($sal as $l) { if (strpos($l, '=') !== false) { [$k, $v] = explode('=', trim($l), 2); $r[$k] = $v; } }
    $J = fn($k) => json_decode($r[$k] ?? 'null', true);

    ok('el navegador completó el recorrido', ($r['OK'] ?? '0') === '1',
       ($r['ERROR'] ?? '') ?: implode(' | ', array_slice($sal, -4)));
    if (($r['OK'] ?? '0') !== '1') { throw new RuntimeException('sin recorrido no hay nada que afirmar'); }

    // ══════════════════════════════════════════════════════════════
    //  1 · UNA DECISIÓN POR PANTALLA, EN ESTE ORDEN
    // ══════════════════════════════════════════════════════════════
    echo "\n  — objetivo · cuánto · para cuándo · tu material —\n";
    ok('empieza preguntando qué quiere lograr',
       mb_stripos($r['P1_PASO'] ?? '', 'Paso 1 de 4') !== false, $r['P1_PASO'] ?? '');
    ok('ofrece los objetivos del dominio, no inventados',
       (int)($r['P1_OBJETIVOS'] ?? 0) === count(meta_objetivos()),
       ($r['P1_OBJETIVOS'] ?? '?') . ' en pantalla · ' . count(meta_objetivos()) . ' en meta_objetivos()');

    ok('la cantidad va en su propia pantalla',
       mb_stripos($r['P2_PASO'] ?? '', 'Paso 2 de 4') !== false, $r['P2_PASO'] ?? '');
    //  El sustantivo sale del objetivo escogido: es del dominio.
    $def = meta_objetivos()['pedidos'];
    ok('y pregunta con las palabras de ESE objetivo',
       trim($r['P2_PREGUNTA'] ?? '') === trim($def['pregunta']),
       ($r['P2_PREGUNTA'] ?? '') . ' · se esperaba: ' . $def['pregunta']);
    ok('con su unidad al lado', trim($r['P2_UNIDAD'] ?? '') === 'pedidos', $r['P2_UNIDAD'] ?? '');

    ok('la fecha va en su propia pantalla',
       mb_stripos($r['P3_PASO'] ?? '', 'Paso 3 de 4') !== false, $r['P3_PASO'] ?? '');
    ok('con opciones y una fecha clara', ($r['P3_HAY_FECHA'] ?? '') === 'true'
       && trim($r['P3_FECHA_CLARA'] ?? '') !== '',
       'fecha clara: «' . ($r['P3_FECHA_CLARA'] ?? '') . '»');

    ok('el material cierra el recorrido',
       mb_stripos($r['P4_PASO'] ?? '', 'Paso 4 de 4') !== false, $r['P4_PASO'] ?? '');

    // ══════════════════════════════════════════════════════════════
    //  2 · EL PASO DEL MATERIAL DICE LA VERDAD
    // ══════════════════════════════════════════════════════════════
    echo "\n  — tu contenido también puede ser parte del plan —\n";
    $t4 = $r['P4_TEXTO'] ?? '';
    ok('la primaria es continuar con lo que tiene',
       mb_stripos($r['P4_PRIMARIA'] ?? '', 'Continuar con lo que tengo') !== false,
       $r['P4_PRIMARIA'] ?? '');
    ok('la secundaria es añadir fotos o videos', ($r['P4_SECUNDARIA'] ?? '') === 'true');
    ok('y hay puerta a la Biblioteca', ($r['P4_BIBLIO'] ?? '') === 'true');
    ok('dice que puede añadir material DESPUÉS',
       mb_stripos($t4, 'después') !== false || mb_stripos($t4, 'más adelante') !== false,
       $t4);
    ok('dice que no hay que empezar de nuevo',
       mb_stripos($t4, 'empezar de nuevo') !== false || mb_stripos($t4, 'sin empezar') !== false, $t4);
    //  LA HONESTIDAD: no se promete que todo se use.
    ok('NO promete que usará todo lo que suba',
       mb_stripos($t4, 'usaré todo') === false && mb_stripos($t4, 'usará todo') === false, $t4);
    ok('sino que lo propondrá cuando encaje',
       mb_stripos($t4, 'encaj') !== false, $t4);
    ok('y que subir es opcional',
       mb_stripos($t4, 'opcional') !== false || mb_stripos($t4, 'no hace falta') !== false, $t4);

    // ══════════════════════════════════════════════════════════════
    //  3 · ATRÁS NO PIERDE NADA
    // ══════════════════════════════════════════════════════════════
    echo "\n  — volver atrás no puede costarle lo que ya contestó —\n";
    $ida  = $J('ESTADO_IDA');
    $vta  = $J('ESTADO_VUELTA');
    $reida = $J('ESTADO_REIDA');
    ok('la ida quedó completa', ($ida['objetivo'] ?? '') === 'pedidos'
       && ($ida['cantidad'] ?? '') === '25' && ($ida['dias'] ?? '') === '60',
       json_encode($ida, JSON_UNESCAPED_UNICODE));
    ok('al volver atrás, el objetivo sigue escogido', ($r['ATRAS_OBJ_SEL'] ?? '') === 'true');
    ok('y la cantidad sigue puesta', ($vta['cantidad'] ?? '') === '25',
       json_encode($vta, JSON_UNESCAPED_UNICODE));
    ok('y la fecha también', ($vta['dias'] ?? '') === '60', json_encode($vta, JSON_UNESCAPED_UNICODE));
    ok('y al avanzar de nuevo está todo', ($reida['objetivo'] ?? '') === 'pedidos'
       && ($reida['cantidad'] ?? '') === '25' && ($reida['dias'] ?? '') === '60',
       json_encode($reida, JSON_UNESCAPED_UNICODE));

    // ══════════════════════════════════════════════════════════════
    //  4 · LO AVANZADO VIVE EN UNA CAPA, NO EN EL CAMINO
    // ══════════════════════════════════════════════════════════════
    echo "\n  — presupuesto y contexto: opcionales, en su capa —\n";
    ok('hay una capa de ajustes', ($r['AJUSTES_HAY'] ?? '') === 'true',
       'el presupuesto de anuncios y el contexto no pueden ocupar un paso del camino');
    ok('se abre',  ($r['AJUSTES_ABIERTA'] ?? '') === 'true');
    ok('y cierra', ($r['AJUSTES_CERRADA'] ?? '') === 'true');
    $tra = $J('ESTADO_TRAS_AJUSTES');
    ok('cerrarla no perdió las respuestas', ($tra['cantidad'] ?? '') === '25'
       && ($tra['dias'] ?? '') === '60', json_encode($tra, JSON_UNESCAPED_UNICODE));

    // ══════════════════════════════════════════════════════════════
    //  5 · AÑADIR MATERIAL SIN SALIRSE DEL PASO
    // ══════════════════════════════════════════════════════════════
    echo "\n  — subir una foto no puede costarle el recorrido —\n";
    ok('el paso tiene su cargador', ($r['SUBIDA_LANZADA'] ?? '') === 'lanzado', $r['SUBIDA_LANZADA'] ?? '');
    ok('la foto se guardó de verdad',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_activos WHERE marca_id={$M}")->fetchColumn() >= 1,
       'no llegó a crecer_activos');
    ok('y la pantalla lo dice', trim($r['TRAS_SUBIR_ESTADO'] ?? '') !== '',
       'sin acuse, el dueño no sabe si se subió');
    ok('sin moverse del paso 4',
       mb_stripos($r['TRAS_SUBIR_PASO'] ?? '', 'Paso 4 de 4') !== false, $r['TRAS_SUBIR_PASO'] ?? '');
    $tsub = $J('TRAS_SUBIR_RESPUESTAS');
    ok('y con las respuestas intactas', ($tsub['objetivo'] ?? '') === 'pedidos'
       && ($tsub['cantidad'] ?? '') === '25' && ($tsub['dias'] ?? '') === '60',
       json_encode($tsub, JSON_UNESCAPED_UNICODE));

    // ══════════════════════════════════════════════════════════════
    //  6 · IR A BIBLIOTECA Y VOLVER AL MISMO SITIO
    // ══════════════════════════════════════════════════════════════
    echo "\n  — salir a la Biblioteca y volver donde estaba —\n";
    ok('la puerta lleva a Biblioteca con su marca',
       strpos($r['BIBLIO_HREF'] ?? '', 'biblioteca.php') !== false
       && strpos($r['BIBLIO_HREF'] ?? '', 'marca=' . $M) !== false, $r['BIBLIO_HREF'] ?? '');
    ok('Biblioteca enseña la vuelta al wizard', ($r['BIBLIO_TIENE_VUELTA'] ?? '') === 'true',
       'sin enlace de vuelta, el recorrido es un viaje de ida');
    ok('y vuelve al wizard', strpos($r['BIBLIO_VUELVE_A'] ?? '', 'vista=wizard') !== false,
       $r['BIBLIO_VUELVE_A'] ?? '');
    ok('al MISMO paso', mb_stripos($r['BIBLIO_VUELVE_PASO'] ?? '', 'Paso 4 de 4') !== false,
       $r['BIBLIO_VUELVE_PASO'] ?? '');
    $tbib = $J('BIBLIO_VUELVE_ESTADO');
    ok('con TODAS las respuestas intactas', ($tbib['objetivo'] ?? '') === 'pedidos'
       && ($tbib['cantidad'] ?? '') === '25' && ($tbib['dias'] ?? '') === '60',
       json_encode($tbib, JSON_UNESCAPED_UNICODE) . ' — perderlas es como se abandona un formulario');

    // ══════════════════════════════════════════════════════════════
    //  7 · HASTA EL ÚLTIMO BOTÓN NO SE ESCRIBE NADA
    // ══════════════════════════════════════════════════════════════
    echo "\n  — salir antes de confirmar no crea nada —\n";
    ok('salir borra el borrador de la pestaña', ($r['SALIR_BORRO_BORRADOR'] ?? '') === 'true',
       'si no, volver a entrar reanudaría un recorrido que el dueño abandonó');
    ok('la salida dice la verdad de lo que se guardó',
       mb_stripos($r['SALIR_TX'] ?? '', 'sin crear') !== false
       || mb_stripos($r['SALIR_TX'] ?? '', 'no se crea') !== false
       || mb_stripos($r['SALIR_TX'] ?? '', 'nada') !== false,
       '«' . ($r['SALIR_TX'] ?? '') . '»');
    ok('no se creó ninguna meta',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta WHERE marca_id={$M}")->fetchColumn() === 0);
    ok('ni ningún plan',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_plan WHERE marca_id={$M}")->fetchColumn() === 0);
    ok('ni ninguna táctica',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica WHERE marca_id={$M}")->fetchColumn() === 0);
    ok('ni ninguna pieza',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$M}")->fetchColumn() === 0);

    // ══════════════════════════════════════════════════════════════
    //  8 · LA PANTALLA, EN LA MANO
    // ══════════════════════════════════════════════════════════════
    echo "\n  — 360×800: una decisión, visible, sin ir a buscarla —\n";
    $pasos = ['MED_P1' => 'objetivo', 'MED_P2' => 'cantidad', 'MED_P3' => 'fecha',
              'MED_P4' => 'material', 'MED_AJUSTES' => 'la capa de ajustes',
              'MED_P1_414' => 'objetivo a 414', 'MED_P4_414' => 'material a 414',
              'MED_P1_1440' => 'objetivo a 1440', 'MED_P4_1440' => 'material a 1440'];
    foreach ($pasos as $k => $como) {
        $m = $J($k);
        if (!$m) { ok("hay medida de {$como}", false, 'no llegó'); continue; }
        ok("{$como}: cero scroll horizontal", (int)$m['horiz'] === 0, 'sobran ' . $m['horiz'] . 'px');
        ok("{$como}: todo lo que se toca mide 44+", count($m['chicos']) === 0,
           implode(' · ', array_slice($m['chicos'], 0, 3)));
        ok("{$como}: texto de contenido 14px+", count($m['finos']) === 0,
           implode(' · ', array_slice($m['finos'], 0, 3)));
        ok("{$como}: una sola acción principal", (int)$m['primarias'] === 1, 'hay ' . $m['primarias']);
        ok("{$como}: la acción se ve sin desplazar", ($m['priVisible'] ?? false) === true,
           'rect ' . ($m['priRect'] ?? '?') . ' · techo ' . ($m['techo'] ?? '?'));
        ok("{$como}: nada la tapa", ($m['priTapada'] ?? true) === false);
        ok("{$como}: Ayuda no se le sienta encima", ($m['priBajoAyuda'] ?? true) === false);
        //  No es «nada bajo el pliegue» —una página se desplaza— sino que
        //  ningún control quede ATRAPADO detrás de la barra fija cuando ya no
        //  se puede desplazar más. Eso sí sería inalcanzable.
        ok("{$como}: ningún control atrapado tras la barra", (int)($m['atrapados'] ?? 9) === 0,
           ($m['atrapados'] ?? '?') . ' controles inalcanzables');
    }

    echo "\n  — y sin ruido —\n";
    ok('cero alert()', (int)($r['ALERTAS'] ?? 9) === 0, ($r['ALERTAS'] ?? '?') . ' alertas');
    $errs = $J('ERRORES') ?: [];
    ok('consola limpia', count($errs) === 0, implode(' · ', array_slice($errs, 0, 3)));

    // ══════════════════════════════════════════════════════════════
    //  9 · LO QUE ESTE RECORRIDO LE COSTÓ AL PROVEEDOR
    // ══════════════════════════════════════════════════════════════
    echo "\n  — este tramo no llama a nadie, y se cuenta —\n";
    ok('cero llamadas a un modelo', $cnt('crecer_ia_log') === $ia_antes,
       ($cnt('crecer_ia_log') - $ia_antes) . ' registros nuevos');
    ok('cero imágenes generadas', $cnt('crecer_img_cuota_asiento') === $img_antes,
       ($cnt('crecer_img_cuota_asiento') - $img_antes) . ' asientos nuevos');

    // ══════════════════════════════════════════════════════════════
    //  10 · LAS CAPTURAS
    // ══════════════════════════════════════════════════════════════
    echo "\n  — dos pantallas distintas no dan el mismo PNG —\n";
    $pngs = glob($SHOTS . '/*.png') ?: [];
    ok('se guardaron capturas', count($pngs) >= 6, count($pngs) . ' archivos');
    $h = [];
    foreach ($pngs as $f) { $h[] = md5_file($f); ok('«' . basename($f) . '» no está vacía', filesize($f) > 3000); }
    ok('y ninguna repite a otra', count($h) === count(array_unique($h)));

} finally {
    //  Los activos que subió la prueba cuelgan de la marca: se van con ella.
    try { $pdo->prepare("DELETE FROM crecer_activos WHERE marca_id=?")->execute([$M]); } catch (Throwable $e) {}
    Fixture::limpiar($pdo, $M);
    @unlink($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid);
    echo "\n  (fixture limpiada · capturas en tests/_capturas/wizard)\n";
}

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0 ? "  EL RECORRIDO SE SOSTIENE · {$n} afirmaciones\n\n"
                   : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
