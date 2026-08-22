<?php
// ============================================================
//  CRECER — LA MATRIZ DE COMPATIBILIDAD DE 7a
//  tests/test_meta_compatibilidad.php
//
//  Las migraciones se corren A MANO en phpMyAdmin. Entre que el codigo llega al
//  servidor y que alguien abre el panel de migraciones pueden pasar horas — y
//  en esas horas Tu Meta tiene que funcionar. Esta prueba mide precisamente esa
//  ventana, y la de vuelta: un rollback del codigo con el esquema ya nuevo.
//
//  NO SE PUEDE COMPROBAR LEYENDO. Aqui se le esconden de verdad las columnas y
//  la tabla al codigo, y se mira que hace.
//
//  LO QUE EXIGE EL CONTRATO APROBADO
//
//    Falta M1  → sustituir SE APAGA · ajustar sigue · el plan se pinta igual
//    Falta M2  → ajustar SE APAGA ENTERO (no se degrada: un ajuste sin registro
//                es la edicion silenciosa que este contrato prohibe)
//    Falta todo→ Tu Meta se comporta como en el commit 6
//    Esquema nuevo + codigo viejo → una jugada sustituida queda 'descartada',
//                que el compositor YA ignora. Ese es el motivo de haber dejado
//                el ENUM en paz.
//
//  El esquema se esconde con una BASE DESECHABLE, no tocando la de verdad.
//  CERO PROVEEDORES.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/meta_negocio.php';
require_once __DIR__ . '/_fixture.php';
require_once __DIR__ . '/_esquema_desechable.php';
require_once __DIR__ . '/../core/Meta/MetaSnapshotReader.php';
require_once __DIR__ . '/../core/Meta/MetaStateComposer.php';
require_once __DIR__ . '/../includes/meta_oportunidad.php';
if (is_file(__DIR__ . '/../includes/meta_cambio.php')) require_once __DIR__ . '/../includes/meta_cambio.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nMATRIZ DE COMPATIBILIDAD · 7a\n" . str_repeat('=', 58) . "\n";

if (!function_exists('meta_ajuste_disponible')) {
    echo "\n  La capa no existe todavia: falta includes/meta_cambio.php con\n"
       . "  meta_ajuste_disponible() y meta_sustitucion_disponible().\n\n"
       . str_repeat('=', 58) . "\n  ROJA POR DISENO · aun no implementado\n\n";
    exit(1);
}

$fx = Fixture::crear($pdo, 'compat', true, 'admin');
$M = (int)$fx['marca_id']; $META = (int)$fx['meta_id']; $PLAN = (int)$fx['plan_id'];
$UID = (int)$fx['usuario_id'];

/**
 * Esconde algo del esquema EN UNA COPIA, nunca en la base compartida.
 *
 * La primera version hacia RENAME TABLE y ALTER contra $pdo, que es la
 * conexion de todos. El DDL en MySQL hace COMMIT implicito y no se deshace:
 * si la prueba muere a mitad, la tabla se queda renombrada para el resto del
 * mundo. Ya hubo un incidente por eso y hay una prueba que lo vigila
 * (test_fixtures_disciplina) — me pillo, y tenia razon.
 *
 * Aqui se clona el esquema, se muerde la COPIA y se le pasa su $pdo a quien
 * mide. Muere con la prueba.
 */
$sinEsquema = function (array $quitar, callable $fn) use ($pdo) {
    $copia = EsquemaDesechable::crear($pdo);
    if ($copia === null) {
        echo "  (saltada: este usuario de base de datos no puede crear bases)
";
        return;
    }
    try {
        $cpdo = $copia->pdo();
        foreach ($quitar as $q) {
            if ($q === 'M2') {
                $copia->ejecutar("DROP TABLE IF EXISTS crecer_meta_cambio");
            } elseif ($q === 'M1') {
                //  Basta con quitar el sello: es la columna por la que se
                //  reconoce la capacidad.
                try { $copia->ejecutar("ALTER TABLE crecer_meta_tactica DROP COLUMN sustituida_at"); }
                catch (Throwable $e) {}
            } elseif ($q === 'M3') {
                $copia->ejecutar("DROP TABLE IF EXISTS crecer_efemerides");
            } elseif ($q === 'M4') {
                $copia->ejecutar("DROP TABLE IF EXISTS crecer_efemeride_decision");
            }
        }
        //  El codigo recuerda si una tabla estaba, y con razon: preguntarlo en
        //  cada pintada seria caro. Aqui se le hace olvidar a proposito —y al
        //  cambiar de conexion hay que hacerlo—, que es lo que distingue medir
        //  de creerse el recuerdo.
        meta_olvidar_esquema();
        $fn($cpdo, $copia);
    } finally {
        $copia->soltar($pdo);
        meta_olvidar_esquema();
    }
};

/** ¿Responde la pantalla, y con que? */
$pedir = function (string $q) use ($fx): string {
    static $sid = null, $ruta = null;
    if ($sid === null) {
        $sid = 'cp' . bin2hex(random_bytes(8));
        $ruta = session_save_path() ?: sys_get_temp_dir();
        file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
            'usuario_id|i:' . (int)$fx['usuario_id'] . ';');
    }
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 30,
        'header' => "Cookie: PHPSESSID={$sid}\r\n", 'ignore_errors' => true]]);
    $h = @file_get_contents('http://localhost/crecer/panel/meta.php?' . $q, false, $ctx);
    return is_string($h) ? $h : '';
};

try {
    $pdo->prepare("UPDATE crecer_meta_plan SET presentado_at=NOW() WHERE meta_id=? AND estado='activo'")
        ->execute([$META]);

    // ══════════════════════════════════════════════════════════
    //  0 · CON TODO PUESTO
    // ══════════════════════════════════════════════════════════
    echo "\n  — 0 · con las dos migraciones —\n";
    ok('ajustar esta disponible', meta_ajuste_disponible($pdo));
    ok('sustituir esta disponible', meta_sustitucion_disponible($pdo));
    $plan = $pedir('marca=' . $M . '&vista=plan');
    ok('la vista del plan responde', strpos($plan, '<html') !== false);
    ok('y ofrece ajustar la meta', strpos($plan, 'vista=ajustar') !== false,
       'con M2 puesta, el control tiene que estar');

    // ══════════════════════════════════════════════════════════
    //  1 · FALTA M2 · AJUSTAR SE APAGA ENTERO
    // ══════════════════════════════════════════════════════════
    echo "\n  — 1 · sin crecer_meta_cambio —\n";
    $sinEsquema(['M2'], function (PDO $cpdo) {
        ok('ajustar deja de estar disponible', !meta_ajuste_disponible($cpdo),
           'sin trazabilidad, un ajuste seria una edicion silenciosa');
        ok('y sustituir sigue en pie', meta_sustitucion_disponible($cpdo),
           'son capacidades independientes: una no puede arrastrar a la otra');

        //  La fixture se siembra DENTRO de la copia: solo inserta, asi que
        //  funciona igual aqui que en la base de verdad.
        $fv = Fixture::crear($cpdo, 'sinM2');
        $mv = (int)$fv['marca_id']; $mtv = (int)$fv['meta_id'];
        $q = $cpdo->prepare("SELECT * FROM crecer_meta WHERE id=?"); $q->execute([$mtv]);
        $antes = $q->fetch(PDO::FETCH_ASSOC);

        $r = meta_ajustar_trazado($cpdo, $mv, $mtv, (int)$fv['usuario_id'],
                                  ['cantidad' => '888'], meta_token($antes), '');
        ok('y la funcion se niega en vez de escribir a ciegas', empty($r['ok']),
           json_encode($r, JSON_UNESCAPED_UNICODE));
        ok('diciendo por que', ($r['motivo'] ?? '') === 'sin_traza', (string)($r['motivo'] ?? '—'));
        $q->execute([$mtv]);
        ok('la meta no se toco', $q->fetch(PDO::FETCH_ASSOC) === $antes);
        Fixture::limpiar($cpdo, $mv);
    });
    ok('repuesta la tabla, ajustar vuelve', meta_ajuste_disponible($pdo));

    // ══════════════════════════════════════════════════════════
    //  2 · FALTA M1 · SUSTITUIR SE APAGA
    // ══════════════════════════════════════════════════════════
    echo "\n  — 2 · sin las columnas de sustitucion —\n";
    $sinEsquema(['M1'], function (PDO $cpdo) {
        ok('sustituir deja de estar disponible', !meta_sustitucion_disponible($cpdo));
        ok('y ajustar sigue en pie', meta_ajuste_disponible($cpdo));

        $fv = Fixture::crear($cpdo, 'sinM1');
        $mv = (int)$fv['marca_id']; $mtv = (int)$fv['meta_id'];
        $jug = (int)$cpdo->query("SELECT id FROM crecer_meta_tactica
                                   WHERE meta_id={$mtv} AND estado='pendiente'
                                   ORDER BY orden LIMIT 1")->fetchColumn();
        $cuantas = (int)$cpdo->query("SELECT COUNT(*) FROM crecer_meta_tactica
                                       WHERE meta_id={$mtv}")->fetchColumn();
        $r = meta_sustituir_jugada($cpdo, $mv, $jug, (int)$fv['usuario_id'], 'sin_video', '',
            ['titulo' => '[prueba] alternativa', 'formato' => 'carrusel'], '');
        ok('y la funcion se niega', empty($r['ok']), json_encode($r, JSON_UNESCAPED_UNICODE));
        ok('diciendo por que', ($r['motivo'] ?? '') === 'sin_esquema', (string)($r['motivo'] ?? '—'));
        ok('sin crear ninguna jugada suelta',
           (int)$cpdo->query("SELECT COUNT(*) FROM crecer_meta_tactica
                               WHERE meta_id={$mtv}")->fetchColumn() === $cuantas,
           'habia ' . $cuantas);
        Fixture::limpiar($cpdo, $mv);
    });
    ok('repuestas las columnas, sustituir vuelve', meta_sustitucion_disponible($pdo));

    // ══════════════════════════════════════════════════════════
    //  3 · SIN NINGUNA DE LAS DOS
    // ══════════════════════════════════════════════════════════
    echo "\n  — 3 · sin M1 ni M2 · Tu Meta como en el commit 6 —\n";
    $sinEsquema(['M1', 'M2'], function (PDO $cpdo) {
        ok('las dos capacidades apagadas',
           !meta_ajuste_disponible($cpdo) && !meta_sustitucion_disponible($cpdo));

        //  Y lo que de verdad importa: que el MOTOR de Tu Meta siga componiendo
        //  el estado sin ellas. Si el compositor se cayera aqui, la pantalla se
        //  caeria con el.
        $fv = Fixture::crear($cpdo, 'sinNada');
        $mv = (int)$fv['marca_id'];
        $snap = MetaSnapshotReader::leer($cpdo, $mv);
        $ev   = MetaStateComposer::componer($snap);
        ok('el compositor sigue dando un estado', $ev->estado !== '' && $ev->estado !== MetaState::FALLBACK,
           'salio ' . $ev->estado . ' · una migracion que falta no puede tumbar lo que ya funcionaba');
        ok('y con su accion', !empty($ev->accion['etiqueta'] ?? ''),
           json_encode($ev->accion, JSON_UNESCAPED_UNICODE));
        Fixture::limpiar($cpdo, $mv);
    });

    //  ── LA MITAD QUE NO SE PUEDE MEDIR EN LA COPIA ──────────────────────
    //  Apache habla con la base COMPARTIDA, asi que no hay forma de pedirle una
    //  pagina «sin la migracion» sin romperle el esquema a todo el mundo — que
    //  es justo lo que prohibe la regla de la casa (test_fixtures_disciplina).
    //  Lo que si se puede afirmar, y se afirma leyendo: que la puerta y el
    //  control estan GOBERNADOS por la misma comprobacion que arriba se acaba
    //  de medir apagada.
    echo "\n  — y la pantalla, leida: las dos puertas estan gobernadas —\n";
    $src = (string)file_get_contents(dirname(__DIR__) . '/panel/meta.php');
    ok('la ruta de ajustar cae al plan si no esta la tabla',
       preg_match('~vista === \'ajustar\'\s*&&\s*!meta_ajuste_disponible~', $src) === 1,
       'sin esa linea, ?vista=ajustar pintaria un wizard que no puede guardar');
    ok('la de sustituir tambien',
       preg_match('~vista === \'sustituir\'\s*&&\s*!meta_sustitucion_disponible~', $src) === 1);
    ok('y el control del plan solo se pinta si se puede ajustar',
       preg_match('~meta_ajuste_disponible\(\$pdo\)\s*\):\s*\?>\s*\R\s*<a id="ajustar"~u', $src) === 1
       || strpos($src, "if (meta_ajuste_disponible(\$pdo)): ?>") !== false,
       'un control que lleva a una pantalla apagada es una promesa rota');

    // ══════════════════════════════════════════════════════════
    //  3b · SIN EL CATALOGO (M3) · DEGRADA, NO SE APAGA
    //
    //  Quedan las fechas del propio dueño. Es poco, pero es verdad: cero
    //  invencion y cero pantalla rota.
    // ══════════════════════════════════════════════════════════
    echo "
  — 3b · sin el catalogo de fechas —
";
    $sinEsquema(['M3'], function (PDO $cpdo) {
        ok('las oportunidades siguen en pie', efem_disponible($cpdo),
           'el catalogo es una FUENTE, no la capacidad');
        ok('pero el catalogo no esta', !efem_hay_catalogo($cpdo));

        $fv = Fixture::crear($cpdo, 'sinM3');
        $mv = (int)$fv['marca_id'];
        $cpdo->prepare("UPDATE crecer_contenido SET fecha_programada=DATE_ADD(NOW(), INTERVAL 200 DAY)
                         WHERE marca_id=?")->execute([$mv]);
        $cpdo->prepare("UPDATE crecer_meta SET fecha_limite=DATE_ADD(CURDATE(), INTERVAL 60 DAY)
                         WHERE marca_id=?")->execute([$mv]);
        $f = (new DateTimeImmutable('today'))->modify('+9 days');
        $cpdo->prepare("INSERT INTO crecer_eventos (marca_id,titulo,nota,fecha) VALUES (?,?,?,?)")
            ->execute([$mv, '[prueba] Fecha suya', '', $f->format('Y-m-d') . ' 10:00:00']);

        $ops = efem_oportunidades($cpdo, $mv);
        ok('y las fechas del dueño SI se ofrecen', count($ops) === 1
            && ($ops[0]['origen'] ?? '') === 'evento',
           json_encode($ops, JSON_UNESCAPED_UNICODE) . ' · sin catalogo queda lo suyo, que es poco pero es verdad');
        Fixture::limpiar($cpdo, $mv);
    });

    // ══════════════════════════════════════════════════════════
    //  3c · SIN LA MEMORIA (M4) · SE APAGA ENTERA
    //
    //  Una sugerencia que reaparece cada vez que se abre el plan, despues de
    //  que el dueño ya dijo que no, es PEOR que no tener la capacidad.
    // ══════════════════════════════════════════════════════════
    echo "
  — 3c · sin la memoria de lo contestado —
";
    $sinEsquema(['M4'], function (PDO $cpdo) {
        ok('las oportunidades se apagan', !efem_disponible($cpdo),
           'sin memoria, la misma fecha volveria a salir tras decir que no');

        $fv = Fixture::crear($cpdo, 'sinM4');
        $mv = (int)$fv['marca_id'];
        $f = (new DateTimeImmutable('today'))->modify('+9 days');
        $cpdo->prepare("INSERT INTO crecer_eventos (marca_id,titulo,nota,fecha) VALUES (?,?,?,?)")
            ->execute([$mv, '[prueba] Fecha suya', '', $f->format('Y-m-d') . ' 10:00:00']);
        ok('no se ofrece ninguna', efem_oportunidades($cpdo, $mv) === []);
        $antes = (int)$cpdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$mv}")->fetchColumn();
        $r = efem_anadir($cpdo, $mv, (int)$fv['usuario_id'], 'evento',
            (int)$cpdo->query("SELECT id FROM crecer_eventos WHERE marca_id={$mv} LIMIT 1")->fetchColumn(),
            $f->format('Y-m-d'));
        ok('y añadir se niega en vez de escribir a ciegas', empty($r['ok']),
           json_encode($r, JSON_UNESCAPED_UNICODE));
        ok('sin crear ninguna pieza',
           (int)$cpdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$mv}")->fetchColumn()
           === $antes);
        Fixture::limpiar($cpdo, $mv);
    });
    ok('repuestas las dos, las oportunidades vuelven',
       efem_disponible($pdo) && efem_hay_catalogo($pdo));

    // ══════════════════════════════════════════════════════════
    //  4 · ESQUEMA NUEVO, CODIGO VIEJO
    // ══════════════════════════════════════════════════════════
    echo "\n  — 4 · esquema nuevo con un compositor que no sabe de sustitucion —\n";
    //  Se simula el codigo viejo de la unica forma honesta: se escribe una
    //  jugada sustituida a mano y se le pregunta al compositor —que solo mira
    //  `estado`— si vuelve a pedir turno. Si el ENUM se hubiera ampliado,
    //  'sustituida' seria un valor desconocido y el filtro la dejaria pasar.
    $jug = (int)$pdo->query("SELECT id FROM crecer_meta_tactica
                              WHERE meta_id={$META} AND estado='pendiente' ORDER BY orden LIMIT 1")->fetchColumn();
    $pdo->prepare("UPDATE crecer_meta_tactica
                      SET estado='descartada', sustituida_at=NOW(), motivo_sustitucion='sin_video'
                    WHERE id=?")->execute([$jug]);

    $snap = MetaSnapshotReader::leer($pdo, $M);
    $vuelve = false;
    foreach ((array)($snap['jugadas'] ?? []) as $t) {
        if ((int)$t['id'] === $jug && !in_array((string)$t['estado'], ['hecha','descartada'], true)) $vuelve = true;
    }
    ok('un compositor que solo mira `estado` la da por terminada', !$vuelve,
       'ESTE es el motivo de no haber tocado el ENUM: descartada ya la sabia ignorar');
    ok('y el estado sigue siendo un valor del enum de siempre',
       (string)$pdo->query("SELECT estado FROM crecer_meta_tactica WHERE id={$jug}")->fetchColumn() === 'descartada');
    ok('la pantalla la pinta sin quejarse',
       strpos($pedir('marca=' . $M . '&vista=plan'), 'Fatal error') === false);

    echo "\n  — y una descartada A SECAS sigue siendo otra cosa —\n";
    $otro = (int)$pdo->query("SELECT id FROM crecer_meta_tactica
                               WHERE meta_id={$META} AND estado='pendiente' ORDER BY orden LIMIT 1")->fetchColumn();
    $pdo->prepare("UPDATE crecer_meta_tactica SET estado='descartada' WHERE id=?")->execute([$otro]);
    ok('la sustituida se distingue por el sello',
       meta_fue_sustituida($pdo->query("SELECT * FROM crecer_meta_tactica WHERE id={$jug}")->fetch(PDO::FETCH_ASSOC)));
    ok('y la descartada a secas no',
       !meta_fue_sustituida($pdo->query("SELECT * FROM crecer_meta_tactica WHERE id={$otro}")->fetch(PDO::FETCH_ASSOC)),
       'si no se distinguieran, «Sustituida» seria una etiqueta puesta a ojo');

} finally {
    //  Ya no hay nada que reponer en la base compartida: no se le toca la
    //  forma. Cada copia muere con su propio soltar().
    try { $pdo->prepare("DELETE FROM crecer_meta_cambio WHERE marca_id=?")->execute([$M]); }
    catch (Throwable $e) {}
    Fixture::limpiar($pdo, $M);
    echo "\n  (fixture limpiada)\n";
}

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  MATRIZ CUMPLIDA · {$n} afirmaciones\n\n"
    : "  {$fallos} de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
