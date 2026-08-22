<?php
// ============================================================
//  CRECER — CONTRATO DE LAS OPORTUNIDADES DEL CALENDARIO (7b)
//  tests/test_meta_oportunidad_contrato.php
//
//  Escrita ANTES de implementar. Nace roja: especifica una capacidad nueva.
//
//  LA CLAUSULA QUE MANDA SOBRE TODAS LAS DEMAS:
//
//      LAS FECHAS SON SUGERENCIAS. Nunca se inserta contenido solo porque
//      exista una efemeride. El unico camino a crecer_contenido es un boton
//      que el dueño pulsa.
//
//  Y LAS QUE LA SOSTIENEN
//
//    · NINGUNA FECHA SALE DE UN MODELO. Se resuelven con aritmetica (nth_dow)
//      o se cargan por año. La aritmetica se comprueba contra el parser de
//      fechas de PHP, que es una implementacion independiente: si mi cuenta y
//      la suya no coinciden, una de las dos esta mal y hay que mirarlo.
//    · Una fila SIN revisar no se ofrece jamas, aunque este activa.
//    · La ventana es [hoy+3, hoy+21] y nunca pasa de la fecha de la meta.
//      Menos de 3 dias no da tiempo a aprobar y publicar; mas de 21 es ruido.
//    · Lo que ya se contesto NO vuelve a salir.
//    · Las fechas del PROPIO dueño mandan sobre el catalogo general.
//    · Añadir inserta UNA pieza y nada mas: no crea jugada, no toca el plan,
//      no mueve el progreso.
//    · Descartar no toca la meta, ni el plan, ni el progreso — y si la fecha
//      era del dueño, NO le borra ni le rechaza el evento: solo dice que no
//      quiere un post para esa vez.
//
//  CERO PROVEEDORES: aqui no se llama a ningun modelo. La pieza nace en
//  borrador y sin arte.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/meta_negocio.php';
require_once __DIR__ . '/_fixture.php';
if (is_file(__DIR__ . '/../includes/meta_oportunidad.php')) {
    require_once __DIR__ . '/../includes/meta_oportunidad.php';
}

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nCONTRATO DE LAS OPORTUNIDADES · el calendario\n" . str_repeat('=', 58) . "\n";

if (!function_exists('efem_resolver')) {
    echo "\n  La capa no existe todavia: falta includes/meta_oportunidad.php.\n"
       . "  Esta prueba es su especificacion.\n\n"
       . str_repeat('=', 58) . "\n  ROJA POR DISENO · aun no implementado\n\n";
    exit(1);
}

$hoy = new DateTimeImmutable('today');
$mas = fn(int $d) => $hoy->modify("+{$d} days")->format('Y-m-d');

$fx = Fixture::crear($pdo, 'oprcon', true, 'admin');
$M = (int)$fx['marca_id']; $META = (int)$fx['meta_id']; $PLAN = (int)$fx['plan_id'];
$UID = (int)$fx['usuario_id'];
$efem = [];   // ids que crea esta prueba, para limpiarlos

/** Siembra una efemeride del catalogo. Por defecto, ya revisada. */
$sembrar = function (array $f) use ($pdo, &$efem): int {
    $d = $f + ['clave' => 'x' . bin2hex(random_bytes(3)), 'nombre' => '[prueba] fecha',
               'tipo_fecha' => 'fija', 'mes' => null, 'dia' => null, 'anio' => null,
               'regla' => null, 'ambito' => 'general', 'municipio_id' => null,
               'categorias' => null, 'revisado' => true, 'activa' => 1];
    $pdo->prepare("INSERT INTO crecer_efemerides
                     (clave,nombre,tipo_fecha,mes,dia,anio,regla,ambito,municipio_id,
                      categorias,fuente,revisado_por,revisado_at,activa)
                   VALUES (?,?,?,?,?,?,?,?,?,?, '[prueba]', ?, ?, ?)")
        ->execute([$d['clave'], $d['nombre'], $d['tipo_fecha'], $d['mes'], $d['dia'], $d['anio'],
                   $d['regla'], $d['ambito'], $d['municipio_id'], $d['categorias'],
                   $d['revisado'] ? '[prueba]' : null,
                   $d['revisado'] ? date('Y-m-d H:i:s') : null, $d['activa']]);
    $id = (int)$pdo->lastInsertId(); $efem[] = $id; return $id;
};
/** Las oportunidades que la marca veria hoy. */
$ver = fn() => efem_oportunidades($pdo, $M);
$claves = fn(array $ops) => array_map(fn($o) => $o['clave'], $ops);

try {
    // ══════════════════════════════════════════════════════════
    //  1 · LA ARITMETICA DE LAS FECHAS
    // ══════════════════════════════════════════════════════════
    echo "\n  — 1 · nth_dow, contra el parser de PHP —\n";
    //  PHP sabe leer «second sunday of May 2026». Es OTRA implementacion: si mi
    //  cuenta y la suya no coinciden, una esta mal y hay que mirarlo. Comparar
    //  contra una constante escrita por mi solo probaria que se lo que escribi.
    $ordinal = [1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth'];
    $dias    = [0 => 'sunday', 1 => 'monday', 2 => 'tuesday', 3 => 'wednesday',
                4 => 'thursday', 5 => 'friday', 6 => 'saturday'];
    $mal = [];
    foreach ([2026, 2027, 2028] as $anio) {
        foreach ([[2, 0, 5], [3, 0, 6], [4, 4, 11], [1, 1, 9], [4, 6, 2]] as [$nn, $dw, $mm]) {
            $mia  = efem_nth_dow($nn, $dw, $mm, $anio);
            $suya = date('Y-m-d', strtotime(
                $ordinal[$nn] . ' ' . $dias[$dw] . ' of ' . date('F', mktime(0,0,0,$mm,1,$anio)) . ' ' . $anio));
            if ($mia !== $suya) $mal[] = "nth_dow:{$nn},{$dw},{$mm}/{$anio} → {$mia} vs {$suya}";
        }
    }
    ok('mi cuenta coincide con la de PHP en 15 casos', $mal === [], implode(' · ', $mal));
    ok('un mes que no tiene esa quinta semana da vacio',
       efem_nth_dow(5, 1, 2, 2026) === '' || efem_nth_dow(5, 1, 2, 2026) === null,
       'devolvio ' . var_export(efem_nth_dow(5, 1, 2, 2026), true)
     . ' · inventar un 32 de febrero seria peor que no ofrecer nada');

    echo "\n  — y las tres formas de resolver —\n";
    ok('fija repite el mismo mes y dia',
       efem_resolver(['tipo_fecha' => 'fija', 'mes' => 12, 'dia' => 25], 2027) === '2027-12-25');
    ok('regla se calcula',
       efem_resolver(['tipo_fecha' => 'regla', 'regla' => 'nth_dow:2,0,5'], 2026)
       === date('Y-m-d', strtotime('second sunday of May 2026')));
    ok('anio devuelve SU año y solo el suyo',
       efem_resolver(['tipo_fecha' => 'anio', 'anio' => 2026, 'mes' => 4, 'dia' => 3], 2026) === '2026-04-03'
       && efem_resolver(['tipo_fecha' => 'anio', 'anio' => 2026, 'mes' => 4, 'dia' => 3], 2027) === '',
       'una fila cargada para 2026 no puede resolverse en 2027: seria inventar la fecha');
    ok('una regla que no entiendo no se inventa',
       efem_resolver(['tipo_fecha' => 'regla', 'regla' => 'pascua'], 2026) === '',
       'Semana Santa se CARGA por año a proposito; calcularla mal la ve el cliente del cliente');

    // ══════════════════════════════════════════════════════════
    //  2 · SIN REVISAR NO SE OFRECE
    // ══════════════════════════════════════════════════════════
    echo "\n  — 2 · lo no revisado no existe —\n";
    $dentro = $hoy->modify('+10 days');
    $sinRev = $sembrar(['clave' => 'sinrev', 'nombre' => '[prueba] Sin revisar', 'revisado' => false,
                        'tipo_fecha' => 'anio', 'anio' => (int)$dentro->format('Y'),
                        'mes' => (int)$dentro->format('n'), 'dia' => (int)$dentro->format('j')]);
    ok('una fila sin revisado_at no sale', !in_array('sinrev', $claves($ver()), true),
       'aunque este activa: la revision humana es lo que separa un dato de una suposicion');
    $pdo->prepare("UPDATE crecer_efemerides SET revisado_at=NOW(), revisado_por='[prueba]' WHERE id=?")
        ->execute([$sinRev]);
    ok('y al revisarla si sale', in_array('sinrev', $claves($ver()), true));
    $pdo->prepare("UPDATE crecer_efemerides SET activa=0 WHERE id=?")->execute([$sinRev]);
    ok('apagarla la calla', !in_array('sinrev', $claves($ver()), true));
    $pdo->prepare("UPDATE crecer_efemerides SET activa=1 WHERE id=?")->execute([$sinRev]);

    // ══════════════════════════════════════════════════════════
    //  3 · LA VENTANA
    // ══════════════════════════════════════════════════════════
    echo "\n  — 3 · [hoy+3, hoy+21] y nunca pasada la meta —\n";
    //  LA FIXTURE TRAE PIEZAS PROGRAMADAS A DOS DÍAS, y la regla de choque (±2)
    //  se come —con razón— la fecha de +3. Aquí se mide la VENTANA, no el
    //  choque (eso es el bloque 5), así que se apartan. Sin esto la prueba
    //  acusaba al producto de una decisión suya que era correcta.
    $pdo->prepare("UPDATE crecer_contenido SET fecha_programada=DATE_ADD(NOW(), INTERVAL 200 DAY)
                    WHERE marca_id=?")->execute([$M]);
    foreach ([['pegada', 1, false], ['justa', 3, true], ['media', 12, true],
              ['borde', 21, true], ['lejos', 40, false]] as [$cl, $dd, $sale]) {
        $f = $hoy->modify("+{$dd} days");
        $sembrar(['clave' => $cl, 'nombre' => "[prueba] {$cl}", 'tipo_fecha' => 'anio',
                  'anio' => (int)$f->format('Y'), 'mes' => (int)$f->format('n'),
                  'dia' => (int)$f->format('j')]);
    }
    //  La meta llega lejos, para que sea la ventana la que decida y no ella.
    $pdo->prepare("UPDATE crecer_meta SET fecha_limite=? WHERE id=?")->execute([$mas(60), $META]);
    $vistas = $claves($ver());
    ok('a 1 dia NO sale',  !in_array('pegada', $vistas, true), 'no da tiempo a aprobar y publicar');
    ok('a 3 dias SI sale',  in_array('justa', $vistas, true), implode(',', $vistas));
    ok('a 12 dias SI sale', in_array('media', $vistas, true));
    ok('a 21 dias SI sale', in_array('borde', $vistas, true));
    ok('a 40 dias NO sale', !in_array('lejos', $vistas, true), 'mas de 21 dias es ruido');

    echo "\n  — y la meta acorta la ventana —\n";
    $pdo->prepare("UPDATE crecer_meta SET fecha_limite=? WHERE id=?")->execute([$mas(7), $META]);
    $cortas = $claves($ver());
    ok('lo que cae despues de la meta se calla', !in_array('media', $cortas, true),
       implode(',', $cortas) . ' · una fecha fuera de la meta no la empuja');
    ok('y lo de dentro sigue', in_array('justa', $cortas, true));
    $pdo->prepare("UPDATE crecer_meta SET fecha_limite=? WHERE id=?")->execute([$mas(60), $META]);

    // ══════════════════════════════════════════════════════════
    //  4 · RELEVANCIA
    // ══════════════════════════════════════════════════════════
    echo "\n  — 4 · a quien le sirve —\n";
    $cat = (int)$pdo->query("SELECT categoria_id FROM crecer_marca WHERE id={$M}")->fetchColumn();
    $f5 = $hoy->modify('+9 days');
    $base = ['tipo_fecha' => 'anio', 'anio' => (int)$f5->format('Y'),
             'mes' => (int)$f5->format('n'), 'dia' => (int)$f5->format('j')];
    $sembrar($base + ['clave' => 'mia',   'categorias' => (string)$cat]);
    $sembrar($base + ['clave' => 'ajena', 'categorias' => (string)($cat + 991)]);
    $f5b = $hoy->modify('+16 days');
    $sembrar(['clave' => 'todas', 'categorias' => null, 'tipo_fecha' => 'anio',
              'anio' => (int)$f5b->format('Y'), 'mes' => (int)$f5b->format('n'),
              'dia' => (int)$f5b->format('j')]);
    $v4 = $claves($ver());
    ok('la de mi categoria sale',   in_array('mia', $v4, true), implode(',', $v4));
    ok('la de OTRA categoria no',  !in_array('ajena', $v4, true),
       'ofrecer el dia del mecanico a una reposteria es ruido con disfraz de ayuda');
    ok('y la general sale siempre',  in_array('todas', $v4, true),
       'va en otro dia a proposito: comparte proposito con las de arriba, no fecha');

    // ══════════════════════════════════════════════════════════
    //  5 · COLISION CON LO YA PROGRAMADO
    // ══════════════════════════════════════════════════════════
    echo "\n  — 5 · si ya hay algo ese dia, no insiste —\n";
    $pieza = null;
    $pdo->prepare("INSERT INTO crecer_contenido (marca_id,plataforma,tipo,caption,fecha_programada,estado,meta_id)
                   VALUES (?, 'instagram','post','[prueba] ya programado', ?, 'aprobado', ?)")
        ->execute([$M, $hoy->modify('+10 days')->format('Y-m-d') . ' 12:00:00', $META]);
    $pieza = (int)$pdo->lastInsertId();
    ok('con una pieza a un dia de distancia, la fecha se calla',
       !in_array('mia', $claves($ver()), true),
       'ya tiene algo esos dias: proponerle otra cosa es amontonarle trabajo');
    $pdo->prepare("DELETE FROM crecer_contenido WHERE id=?")->execute([$pieza]);
    ok('y quitada la pieza, vuelve', in_array('mia', $claves($ver()), true));

    // ══════════════════════════════════════════════════════════
    //  6 · LAS FECHAS DEL DUEÑO MANDAN
    // ══════════════════════════════════════════════════════════
    echo "\n  — 6 · lo suyo primero —\n";
    //  A +18, LEJOS de la fecha que el bloque 7 va a ocupar con una pieza: si
    //  cayera al lado, la regla de choque se lo comeria —con razon— y el
    //  bloque 8 se quedaria sin evento que descartar.
    $pdo->prepare("INSERT INTO crecer_eventos (marca_id,titulo,nota,fecha) VALUES (?,?,?,?)")
        ->execute([$M, '[prueba] Aniversario del negocio', 'Cumplimos 5 años',
                   $hoy->modify('+18 days')->format('Y-m-d') . ' 10:00:00']);
    $EV = (int)$pdo->lastInsertId();
    $ops6 = $ver();
    ok('la fecha propia aparece', in_array('evento', array_column($ops6, 'origen'), true),
       json_encode(array_column($ops6, 'origen')));
    ok('y va la PRIMERA', ($ops6[0]['origen'] ?? '') === 'evento',
       'la fecha que el dueño apuntó pesa más que una del catálogo general');
    ok('con su titulo', strpos((string)($ops6[0]['titulo'] ?? ''), 'Aniversario') !== false,
       (string)($ops6[0]['titulo'] ?? '—'));

    // ══════════════════════════════════════════════════════════
    //  7 · AÑADIR UNA PUBLICACION
    // ══════════════════════════════════════════════════════════
    echo "\n  — 7 · añadir inserta UNA pieza y nada mas —\n";
    $op = null;
    foreach ($ver() as $o) if ($o['clave'] === 'mia') $op = $o;
    ok('hay una oportunidad del catalogo que aceptar', $op !== null);

    $antesPiezas  = (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$M}")->fetchColumn();
    $antesJugadas = (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica WHERE meta_id={$META}")->fetchColumn();
    $antesPlan    = $pdo->query("SELECT * FROM crecer_meta_plan WHERE id={$PLAN}")->fetch(PDO::FETCH_ASSOC);
    $antesMeta    = $pdo->query("SELECT * FROM crecer_meta WHERE id={$META}")->fetch(PDO::FETCH_ASSOC);

    $r = efem_anadir($pdo, $M, $UID, 'efemeride', (int)$op['id'], (string)$op['fecha']);
    ok('se acepta', !empty($r['ok']), json_encode($r, JSON_UNESCAPED_UNICODE));
    ok('y nace UNA pieza',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$M}")->fetchColumn()
       === $antesPiezas + 1);
    $p = $pdo->query("SELECT * FROM crecer_contenido WHERE id=" . (int)$r['contenido_id'])
             ->fetch(PDO::FETCH_ASSOC) ?: [];
    ok('en borrador', (string)($p['estado'] ?? '') === 'borrador',
       (string)($p['estado'] ?? '—') . ' · el dueño la aprueba, como todo lo demas');
    ok('con la fecha de la efemeride',
       strpos((string)($p['fecha_programada'] ?? ''), (string)$op['fecha']) === 0,
       (string)($p['fecha_programada'] ?? '—'));
    ok('atada a la meta viva', (int)($p['meta_id'] ?? 0) === $META);
    ok('pero SIN jugada', $p['tactica_id'] === null,
       'no es una jugada del plan: es una pieza suelta que el dueño pidio');
    ok('y sin arte todavia', ($p['grafica_path'] ?? null) === null,
       'añadir no gasta imagenes: eso pasa al producirla');

    echo "\n  — y NADA mas se mueve —\n";
    ok('no nace ninguna jugada',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica WHERE meta_id={$META}")->fetchColumn()
       === $antesJugadas);
    ok('el plan no se toca',
       $pdo->query("SELECT * FROM crecer_meta_plan WHERE id={$PLAN}")->fetch(PDO::FETCH_ASSOC) === $antesPlan);
    ok('la meta no se toca',
       $pdo->query("SELECT * FROM crecer_meta WHERE id={$META}")->fetch(PDO::FETCH_ASSOC) === $antesMeta,
       'una fecha especial no cambia lo que el dueño se propuso');

    echo "\n  — y no se vuelve a ofrecer —\n";
    ok('la oportunidad aceptada desaparece', !in_array('mia', $claves($ver()), true));
    $d7 = $pdo->query("SELECT * FROM crecer_efemeride_decision
                        WHERE marca_id={$M} AND origen='efemeride' ORDER BY id DESC LIMIT 1")
              ->fetch(PDO::FETCH_ASSOC) ?: [];
    ok('con su decision anotada', (string)($d7['decision'] ?? '') === 'aceptada', json_encode($d7 ?: null));
    ok('apuntando a la pieza', (int)($d7['contenido_id'] ?? 0) === (int)$r['contenido_id']);

    echo "\n  — el doble clic no crea dos —\n";
    $antes2 = (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$M}")->fetchColumn();
    $r2 = efem_anadir($pdo, $M, $UID, 'efemeride', (int)$op['id'], (string)$op['fecha']);
    ok('se contesta que ya estaba', !empty($r2['repetido']), json_encode($r2, JSON_UNESCAPED_UNICODE));
    ok('y no nace otra pieza',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$M}")->fetchColumn() === $antes2);

    // ══════════════════════════════════════════════════════════
    //  8 · DESCARTAR
    // ══════════════════════════════════════════════════════════
    echo "\n  — 8 · descartar no toca nada del negocio —\n";
    $op8 = null;
    foreach ($ver() as $o) if ($o['clave'] === 'todas') $op8 = $o;
    ok('hay otra oportunidad que descartar', $op8 !== null, json_encode($claves($ver())));
    $antesTodo = [
        'piezas'  => (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$M}")->fetchColumn(),
        'jugadas' => (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica WHERE meta_id={$META}")->fetchColumn(),
        'meta'    => $pdo->query("SELECT * FROM crecer_meta WHERE id={$META}")->fetch(PDO::FETCH_ASSOC),
        'plan'    => $pdo->query("SELECT * FROM crecer_meta_plan WHERE id={$PLAN}")->fetch(PDO::FETCH_ASSOC),
    ];
    $r8 = efem_descartar($pdo, $M, $UID, 'efemeride', (int)$op8['id'], (string)$op8['fecha'], 'no me sirve');
    ok('se descarta', !empty($r8['ok']), json_encode($r8, JSON_UNESCAPED_UNICODE));
    ok('no crea ninguna pieza',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$M}")->fetchColumn()
       === $antesTodo['piezas']);
    ok('ni jugada',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica WHERE meta_id={$META}")->fetchColumn()
       === $antesTodo['jugadas']);
    ok('la meta sigue igual',
       $pdo->query("SELECT * FROM crecer_meta WHERE id={$META}")->fetch(PDO::FETCH_ASSOC) === $antesTodo['meta']);
    ok('y el plan tambien',
       $pdo->query("SELECT * FROM crecer_meta_plan WHERE id={$PLAN}")->fetch(PDO::FETCH_ASSOC) === $antesTodo['plan'],
       'esa es la garantia de que las fechas son sugerencias');
    ok('y no vuelve a salir', !in_array('todas', $claves($ver()), true),
       'una sugerencia que reaparece despues de decir que no es una molestia, no una ayuda');

    echo "\n  — descartar una fecha PROPIA no le borra el evento —\n";
    $evAntes = $pdo->query("SELECT * FROM crecer_eventos WHERE id={$EV}")->fetch(PDO::FETCH_ASSOC);
    $opEv = null;
    foreach ($ver() as $o) if (($o['origen'] ?? '') === 'evento') $opEv = $o;
    ok('la fecha propia sigue ofreciendose', $opEv !== null);
    efem_descartar($pdo, $M, $UID, 'evento', (int)$opEv['id'], (string)$opEv['fecha'], '');
    ok('el evento del dueño sigue EXACTAMENTE igual',
       $pdo->query("SELECT * FROM crecer_eventos WHERE id={$EV}")->fetch(PDO::FETCH_ASSOC) === $evAntes,
       'se descarta la oportunidad de contenido, no la fecha: su calendario es suyo');
    ok('pero ya no se ofrece como oportunidad',
       !in_array('evento', array_column($ver(), 'origen'), true));

    // ══════════════════════════════════════════════════════════
    //  9 · POSPONER
    // ══════════════════════════════════════════════════════════
    echo "\n  — 9 · «ahora no» vuelve, «no» no —\n";
    $f9 = $hoy->modify('+15 days');
    $id9 = $sembrar(['clave' => 'luego', 'nombre' => '[prueba] Para luego', 'tipo_fecha' => 'anio',
                     'anio' => (int)$f9->format('Y'), 'mes' => (int)$f9->format('n'),
                     'dia' => (int)$f9->format('j')]);
    ok('sale', in_array('luego', $claves($ver()), true));
    efem_posponer($pdo, $M, $UID, 'efemeride', $id9, $f9->format('Y-m-d'), $mas(5));
    ok('pospuesta, se calla hoy', !in_array('luego', $claves($ver()), true));
    //  OJO: $mas(-1) daria «+-1 days», que PHP interpreta como SUMAR uno —
    //  comprobado— y la fecha de vuelta quedaba en el futuro. Se escribe el
    //  ayer sin ambigüedad.
    $pdo->prepare("UPDATE crecer_efemeride_decision SET retomar_at=? WHERE marca_id=? AND origen_id=?")
        ->execute([$hoy->modify('-1 day')->format('Y-m-d'), $M, $id9]);
    ok('y vuelve cuando toca', in_array('luego', $claves($ver()), true),
       'sin fecha a la que volver, «ahora no» seria indistinguible de no contestar');

    // ══════════════════════════════════════════════════════════
    //  10 · AISLAMIENTO
    // ══════════════════════════════════════════════════════════
    echo "\n  — 10 · lo de otra marca no se toca —\n";
    $otra = Fixture::crear($pdo, 'oprajena', true, 'admin');
    $MO = (int)$otra['marca_id'];
    $antesAjena = (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$MO}")->fetchColumn();
    //  Se acepta una oportunidad "de" la otra marca desde la mia: no puede
    //  escribirle nada a ella.
    $rX = efem_anadir($pdo, $M, $UID, 'evento', (int)$pdo->query(
        "SELECT id FROM crecer_eventos WHERE marca_id={$MO} LIMIT 1")->fetchColumn() ?: 999999,
        $mas(10));
    ok('un evento que no es mio no se acepta', empty($rX['ok']), json_encode($rX, JSON_UNESCAPED_UNICODE));
    ok('y la otra marca no recibe nada',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$MO}")->fetchColumn()
       === $antesAjena);
    ok('ni una decision en su nombre',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_efemeride_decision WHERE marca_id={$MO}")->fetchColumn() === 0);
    Fixture::limpiar($pdo, $MO);

} finally {
    try {
        $pdo->prepare("DELETE FROM crecer_efemeride_decision WHERE marca_id=?")->execute([$M]);
        if ($efem) $pdo->exec("DELETE FROM crecer_efemerides WHERE id IN (" . implode(',', array_map('intval', $efem)) . ")");
        $pdo->prepare("DELETE FROM crecer_eventos WHERE marca_id=?")->execute([$M]);
    } catch (Throwable $e) {}
    Fixture::limpiar($pdo, $M);
    echo "\n  (fixture limpiada)\n";
}

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  CONTRATO CUMPLIDO · {$n} afirmaciones\n\n"
    : "  {$fallos} de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
