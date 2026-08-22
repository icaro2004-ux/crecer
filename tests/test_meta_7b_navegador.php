<?php
// ============================================================
//  CRECER — LA TARJETA DE OPORTUNIDAD, EN CHROME  (7b)
//  tests/test_meta_7b_navegador.php
//
//  El contrato (test_meta_oportunidad_contrato.php) prueba QUE ESCRIBE cada
//  respuesta. Esto prueba lo otro: que la tarjeta se pueda usar, y que las tres
//  respuestas hagan desde la pantalla exactamente lo que hacen por dentro.
//
//  LO QUE SOLO SE VE AQUI
//
//    · Que la tarjeta NO se coma la pantalla: el plan es lo que importa, y una
//      sugerencia no puede competir con lo que de verdad toca hoy.
//    · Que descartar conteste EN SITIO. Recargar devolveria al dueño arriba
//      del todo de una pagina larga por decir «esta no»: cobrarle un scroll
//      por una respuesta de un segundo.
//    · Que el aviso que desarma el miedo —«tu fecha no se borra»— este DONDE
//      estan los botones, no al final de la pagina donde nadie lo lee.
//    · Y lo que mas importa: que ENTRAR AL PLAN no escriba nada. Si con solo
//      mirar se creara la pieza, todo el contrato seria papel mojado.
//
//  CERO PROVEEDORES: la pieza nace en borrador y sin arte.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/meta_negocio.php';
require_once __DIR__ . '/../includes/meta_oportunidad.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nLA TARJETA DE OPORTUNIDAD · en Chrome\n" . str_repeat('=', 58) . "\n";

if (!is_file('C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe')) {
    echo "\n  SALTADA: no hay Chrome en esta maquina\n\n"; exit(2);
}
@mkdir(__DIR__ . '/_capturas', 0775, true);

$fx = Fixture::crear($pdo, 'nav7b', true, 'admin');
$M = (int)$fx['marca_id']; $META = (int)$fx['meta_id']; $PLAN = (int)$fx['plan_id'];
$sid  = 'nb' . bin2hex(random_bytes(8));
$ruta = session_save_path() ?: sys_get_temp_dir();
file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
    'usuario_id|i:' . (int)$fx['usuario_id'] . ';');
$hoy = new DateTimeImmutable('today');
$efem = [];

$sonda = function (string $script, array $args, array &$crudo = null) {
    $cmd = 'node ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . $script);
    foreach ($args as $a) $cmd .= ' ' . escapeshellarg((string)$a);
    for ($k = 0; $k < 2; $k++) {
        if ($k > 0) usleep(1500000);
        $sal = []; exec($cmd . ' 2>&1', $sal);
        $crudo = $sal;
        $j = json_decode((string)end($sal), true);
        if (is_array($j) && !isset($j['error'])) return $j;
    }
    return null;
};

/**
 * Retrato de lo que ESTA capacidad puede tocar.
 *
 * A proposito NO incluye crecer_meta_tactica: pintar Tu Meta mueve la
 * contabilidad del corillo —una jugada pasa a `en_curso` en cuanto empieza a
 * producir— y eso es conducta legitima del producto, no de las fechas.
 * Meterla aqui haria que la prueba acusara a la tarjeta de algo que no hizo.
 *
 * Lo que si tiene que quedarse quieto es todo aquello donde la tarjeta SI
 * podria escribir: las piezas, las fechas del dueño y sus decisiones.
 */
$retrato = function () use ($pdo, $M): string {
    $t = [];
    foreach (['crecer_contenido', 'crecer_eventos', 'crecer_efemeride_decision'] as $tabla) {
        $t[$tabla] = $pdo->query("SELECT * FROM {$tabla} WHERE marca_id={$M} ORDER BY id")
                         ->fetchAll(PDO::FETCH_ASSOC);
    }
    return md5(json_encode($t));
};

try {
    $pdo->prepare("UPDATE crecer_meta_plan SET presentado_at=NOW() WHERE meta_id=? AND estado='activo'")
        ->execute([$META]);
    //  La fixture programa piezas a dos dias y la regla de choque (±2) se
    //  comeria las fechas cercanas — con razon. Se apartan para medir la
    //  tarjeta, no el choque.
    $pdo->prepare("UPDATE crecer_contenido SET fecha_programada=DATE_ADD(NOW(), INTERVAL 200 DAY)
                    WHERE marca_id=?")->execute([$M]);
    $pdo->prepare("UPDATE crecer_meta SET fecha_limite=? WHERE id=?")
        ->execute([$hoy->modify('+60 days')->format('Y-m-d'), $META]);

    //  Una fecha SUYA y una del catalogo, en dias distintos.
    $fEv = $hoy->modify('+9 days');
    $pdo->prepare("INSERT INTO crecer_eventos (marca_id,titulo,nota,fecha) VALUES (?,?,?,?)")
        ->execute([$M, '[prueba] Fiestas del pueblo', 'La plaza se llena el fin de semana',
                   $fEv->format('Y-m-d') . ' 10:00:00']);
    $EV = (int)$pdo->lastInsertId();
    $fCat = $hoy->modify('+16 days');
    $pdo->prepare("INSERT INTO crecer_efemerides
                     (clave,nombre,descripcion,tipo_fecha,anio,mes,dia,ambito,fuente,
                      revisado_por,revisado_at,activa)
                   VALUES (?,?,?, 'anio', ?,?,?, 'general', '[prueba]', '[prueba]', NOW(), 1)")
        ->execute(['nav7b', '[prueba] Día de las Madres',
                   'Para una repostería es de las semanas fuertes del año.',
                   (int)$fCat->format('Y'), (int)$fCat->format('n'), (int)$fCat->format('j')]);
    $efem[] = (int)$pdo->lastInsertId();

    // ══════════════════════════════════════════════════════════
    //  1 · MIRAR NO ESCRIBE
    // ══════════════════════════════════════════════════════════
    echo "\n  — 1 · abrir el plan no escribe nada —\n";
    $huella = $retrato();
    $crudo = null;
    $j = $sonda('_navegador_estados.mjs', [$sid, $M, 360, 800, '', '', 'plan'], $crudo);
    if (!is_array($j)) {
        ok('el navegador midio', false, implode(' | ', array_slice((array)$crudo, -2)));
    } else {
        ok('el plan responde', ($j['contenedor'] ?? '') === '.plan', (string)($j['contenedor'] ?? '?'));
        ok('ningun control bajo una capa fija', count($j['tapados']) === 0,
           json_encode($j['tapados'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        ok('ningun objetivo bajo 44x44', count($j['chicos']) === 0,
           json_encode($j['chicos'], JSON_UNESCAPED_UNICODE));
        ok('ningun texto bajo 14px', count($j['bajo14']) === 0,
           json_encode($j['bajo14'], JSON_UNESCAPED_UNICODE));
        ok('sigue habiendo UNA sola voz grande', count($j['titulares']) === 1,
           json_encode($j['titulares'], JSON_UNESCAPED_UNICODE)
         . ' · la fecha es una sugerencia, no puede gritar mas que la meta');
        ok('sin scroll horizontal', empty($j['scroll_h']));
    }
    ok('y la base no se movio ni una fila', $retrato() === $huella,
       'si con solo mirar se creara la pieza, «las fechas son sugerencias» seria mentira');

    // ══════════════════════════════════════════════════════════
    //  2 · LO QUE DICE LA TARJETA
    // ══════════════════════════════════════════════════════════
    echo "\n  — 2 · lo que la tarjeta promete —\n";
    $crudo = null;
    $t = $sonda('_navegador_oportunidad.mjs', [$sid, $M, 'mirar', 360, 800,
                                                'tumeta_oportunidad_movil'], $crudo);
    if (!is_array($t)) {
        ok('la tarjeta se pudo leer', false, implode(' | ', array_slice((array)$crudo, -2)));
    } else {
        ok('la fecha del dueño va PRIMERO', ($t['origen'] ?? '') === 'evento',
           'origen=' . ($t['origen'] ?? '?') . ' · lo suyo pesa mas que el catalogo general');
        ok('y se le nota que es suya', !empty($t['tuya']));
        //  El texto viene del HTML con sus saltos de linea; se aplasta antes de
        //  mirarlo, que lo que se comprueba es lo que se LEE, no como esta
        //  escrito el fuente.
        $fTxt = preg_replace('/\s+/u', ' ', (string)($t['fecha'] ?? ''));
        ok('dice cuando es, en cristiano', strpos($fTxt, 'en 9 días') !== false, $fTxt);
        ok('ofrece las tres respuestas', (int)($t['botones'] ?? 0) === 3,
           'hay ' . ($t['botones'] ?? '?') . ' · añadir, no me sirve y ahora no');
        ok('el aviso vive JUNTO a los botones', !empty($t['pie_cerca']),
           'a ' . ($t['pie_dist'] ?? '?') . 'px del ultimo boton · al final de la pagina no lo lee nadie');
        ok('y dice que la fecha suya no se borra',
           strpos((string)($t['pie'] ?? ''), 'no se borra') !== false, (string)($t['pie'] ?? '—'));
        ok('dice que no gasta imagenes',
           strpos((string)($t['pie'] ?? ''), 'no gasta imágenes') !== false);
    }

    // ══════════════════════════════════════════════════════════
    //  3 · «ESTA NO ME SIRVE» — en sitio y sin tocar nada
    // ══════════════════════════════════════════════════════════
    echo "\n  — 3 · descartar contesta en sitio —\n";
    $metaAntes  = $pdo->query("SELECT * FROM crecer_meta WHERE id={$META}")->fetch(PDO::FETCH_ASSOC);
    $planAntes  = $pdo->query("SELECT * FROM crecer_meta_plan WHERE id={$PLAN}")->fetch(PDO::FETCH_ASSOC);
    $piezasAntes = (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$M}")->fetchColumn();
    $evAntes    = $pdo->query("SELECT * FROM crecer_eventos WHERE id={$EV}")->fetch(PDO::FETCH_ASSOC);

    $crudo = null;
    $t3 = $sonda('_navegador_oportunidad.mjs', [$sid, $M, 'descartar', 360, 800], $crudo);
    if (!is_array($t3)) {
        ok('el recorrido de descartar corrio', false, implode(' | ', array_slice((array)$crudo, -2)));
    } else {
        ok('no recarga la pagina', empty($t3['recargo']),
           'devolver al dueño arriba de una pagina larga por decir «esta no» le cobra un scroll');
        ok('y contesta ahi mismo', !empty($t3['hecho_visible']));
        ok('diciendo que no la vuelve a sacar',
           stripos((string)($t3['hecho'] ?? ''), 'no te la vuelvo a sacar') !== false,
           (string)($t3['hecho'] ?? '—'));
        ok('y que su fecha sigue en su calendario',
           stripos((string)($t3['hecho'] ?? ''), 'sigue en tu calendario') !== false,
           (string)($t3['hecho'] ?? '—'));
        ok('cero alert()', (int)($t3['alertas'] ?? -1) === 0);
    }
    ok('la meta no se toco',
       $pdo->query("SELECT * FROM crecer_meta WHERE id={$META}")->fetch(PDO::FETCH_ASSOC) === $metaAntes);
    ok('el plan tampoco',
       $pdo->query("SELECT * FROM crecer_meta_plan WHERE id={$PLAN}")->fetch(PDO::FETCH_ASSOC) === $planAntes);
    ok('no nacio ninguna pieza',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$M}")->fetchColumn()
       === $piezasAntes);
    ok('y el evento del dueño sigue EXACTAMENTE igual',
       $pdo->query("SELECT * FROM crecer_eventos WHERE id={$EV}")->fetch(PDO::FETCH_ASSOC) === $evAntes,
       'se descarta la oportunidad de contenido, no su fecha');
    ok('queda anotado que dijo que no',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_efemeride_decision
                          WHERE marca_id={$M} AND decision='descartada'")->fetchColumn() === 1);

    // ══════════════════════════════════════════════════════════
    //  4 · AHORA SALE LA DEL CATALOGO, Y SE AÑADE
    // ══════════════════════════════════════════════════════════
    echo "\n  — 4 · añadir crea UNA pieza y nada mas —\n";
    $jugadasAntes = (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica WHERE meta_id={$META}")->fetchColumn();
    $piezasAntes2 = (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$M}")->fetchColumn();
    $crudo = null;
    $t4 = $sonda('_navegador_oportunidad.mjs', [$sid, $M, 'anadir', 360, 800], $crudo);
    if (!is_array($t4)) {
        ok('el recorrido de añadir corrio', false, implode(' | ', array_slice((array)$crudo, -2)));
    } else {
        ok('ahora la que sale es la del catalogo', ($t4['origen'] ?? '') === 'efemeride',
           'origen=' . ($t4['origen'] ?? '?') . ' · la de antes ya se contesto');
        ok('el POST sale UNA vez con tres clics', (int)($t4['posts'] ?? 0) === 1,
           'salio ' . ($t4['posts'] ?? '?'));
        ok('contesta en sitio', !empty($t4['hecho_visible']));
        ok('y ofrece verla en Tus Posts',
           strpos((string)($t4['hecho_html'] ?? ''), 'propuestas.php') !== false,
           'sin la puerta, «te la puse en borrador» deja al dueño buscandola');
        ok('cero alert()', (int)($t4['alertas'] ?? -1) === 0);
    }
    ok('nace UNA pieza, no tres',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$M}")->fetchColumn()
       === $piezasAntes2 + 1);
    $p = $pdo->query("SELECT * FROM crecer_contenido WHERE marca_id={$M} ORDER BY id DESC LIMIT 1")
             ->fetch(PDO::FETCH_ASSOC) ?: [];
    ok('en borrador', (string)($p['estado'] ?? '') === 'borrador', (string)($p['estado'] ?? '—'));
    ok('sin jugada', $p['tactica_id'] === null,
       'es una pieza suelta que el dueño pidio, no una jugada del plan');
    ok('sin arte todavia', ($p['grafica_path'] ?? null) === null, 'añadir no gasta imagenes');
    ok('y no nacio ninguna jugada',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica WHERE meta_id={$META}")->fetchColumn()
       === $jugadasAntes);
    ok('la meta sigue intacta',
       $pdo->query("SELECT * FROM crecer_meta WHERE id={$META}")->fetch(PDO::FETCH_ASSOC) === $metaAntes,
       'una fecha especial no cambia lo que el dueño se propuso');

    echo "\n  — y ya no queda ninguna que ofrecer —\n";
    $crudo = null;
    $t5 = $sonda('_navegador_oportunidad.mjs', [$sid, $M, 'mirar', 360, 800], $crudo);
    ok('la tarjeta desaparece cuando no hay nada que sugerir',
       is_array($t5) && empty($t5['hay']),
       is_array($t5) ? json_encode($t5, JSON_UNESCAPED_UNICODE) : 'no midio');

    // ══════════════════════════════════════════════════════════
    //  5 · ESCRITORIO
    // ══════════════════════════════════════════════════════════
    echo "\n  — 5 · en escritorio —\n";
    //  Se repone una fecha para poder verla ancha.
    $pdo->prepare("DELETE FROM crecer_efemeride_decision WHERE marca_id=?")->execute([$M]);
    $pdo->prepare("DELETE FROM crecer_contenido WHERE id=?")->execute([(int)($p['id'] ?? 0)]);
    foreach ([[414, 896, ''], [1440, 900, 'tumeta_oportunidad_escritorio']] as [$w, $hgt, $cap]) {
        $crudo = null;
        if ($cap !== '') $sonda('_navegador_oportunidad.mjs', [$sid, $M, 'mirar', $w, $hgt, $cap]);
        $j5 = $sonda('_navegador_estados.mjs', [$sid, $M, $w, $hgt, '', '', 'plan'], $crudo);
        if (!is_array($j5)) {
            ok("{$w} · midio", false, implode(' | ', array_slice((array)$crudo, -2)));
            continue;
        }
        ok("{$w} · ningun control tapado", count($j5['tapados']) === 0,
           json_encode($j5['tapados'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        ok("{$w} · ningun texto bajo 14px", count($j5['bajo14']) === 0,
           json_encode($j5['bajo14'], JSON_UNESCAPED_UNICODE));
        ok("{$w} · ningun objetivo bajo 44x44", count($j5['chicos']) === 0,
           json_encode($j5['chicos'], JSON_UNESCAPED_UNICODE));
        ok("{$w} · una sola voz grande", count($j5['titulares']) === 1);
        ok("{$w} · sin scroll horizontal", empty($j5['scroll_h']));
    }

} finally {
    try {
        $pdo->prepare("DELETE FROM crecer_efemeride_decision WHERE marca_id=?")->execute([$M]);
        if ($efem) $pdo->exec("DELETE FROM crecer_efemerides WHERE id IN (" . implode(',', array_map('intval', $efem)) . ")");
        $pdo->prepare("DELETE FROM crecer_eventos WHERE marca_id=?")->execute([$M]);
    } catch (Throwable $e) {}
    @unlink($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid);
    Fixture::limpiar($pdo, $M);
    echo "\n  (fixture limpiada)\n";
}

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  TODO OK · {$n} afirmaciones\n\n"
    : "  {$fallos} de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
