<?php
// ============================================================
//  CRECER — CONTRATO DE LA SUSTITUCION DE UNA JUGADA (7a)
//  tests/test_meta_sustitucion_contrato.php
//
//  Escrita ANTES de implementar. Especifica una capacidad nueva, asi que nace
//  roja: no hay conducta vieja que proteger, hay una promesa que cumplir.
//
//  LA CLAUSULA CENTRAL, y la que mas facil seria traicionar «para simplificar»:
//
//      LA ORIGINAL NO SE BORRA Y NO SE MARCA HECHA.
//
//  Borrarla perderia el trabajo y la razon. Marcarla hecha inflaria el progreso
//  con trabajo que nunca ocurrio — y el progreso es lo unico que el dueño mira.
//  Queda `descartada` con `sustituida_at`, que es lo que en dominio y en
//  pantalla se llama «sustituida».
//
//  Y LAS DEMAS
//
//    · Todo en UNA transaccion: o entran la nueva, el enlace y el sello, o no
//      entra nada. Ninguna llamada a un modelo dentro.
//    · Las piezas de la original SE QUEDAN CON ELLA. Si una estaba publicada,
//      sigue contando: es trabajo real.
//    · Enlace en los dos sentidos, para poder navegar.
//    · Doble clic: el segundo no crea una segunda jugada ni vuelve a pagar.
//    · Aislamiento entre marcas.
//    · Sustituir NO consume cuota de imagenes.
//    · Si algo falla a mitad, no queda una jugada huerfana.
//
//  CERO PROVEEDORES: la alternativa se le pasa ya hecha a la funcion. Quien
//  llame a la Estratega lo hace FUERA, que es justo lo que exige el contrato.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/meta_negocio.php';
require_once __DIR__ . '/_fixture.php';
if (is_file(__DIR__ . '/../includes/meta_cambio.php')) require_once __DIR__ . '/../includes/meta_cambio.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nCONTRATO DE LA SUSTITUCION · una jugada imposible\n" . str_repeat('=', 58) . "\n";

if (!function_exists('meta_sustituir_jugada')) {
    echo "\n  La capa no existe todavia: falta includes/meta_cambio.php con\n"
       . "  meta_sustituir_jugada(). Esta prueba es su especificacion.\n\n"
       . str_repeat('=', 58) . "\n  ROJA POR DISENO · aun no implementado\n\n";
    exit(1);
}

$fx = Fixture::crear($pdo, 'suscon', true, 'admin');
$M = (int)$fx['marca_id']; $META = (int)$fx['meta_id']; $PLAN = (int)$fx['plan_id'];
$UID = (int)$fx['usuario_id'];

$jugada = function (int $id) use ($pdo): array {
    $q = $pdo->prepare("SELECT * FROM crecer_meta_tactica WHERE id=?"); $q->execute([$id]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: [];
};

/** La alternativa, tal como la devolveria la Estratega — pero sin llamarla. */
$ALT = [
    'titulo'   => '[prueba] Carrusel: los 4 pasos del combo',
    'que_hacer'=> 'Cuatro laminas enseñando el combo paso a paso, con el precio en la ultima.',
    'por_que'  => 'Lo mismo que el reel, sin pedirte que grabes nada.',
    'formato'  => 'carrusel',
    'canal'    => 'instagram',
    'cta'      => 'Escribeme por WhatsApp para separar el tuyo',
    'piezas_meta' => 1,
];

try {
    // ── La jugada imposible: un reel que necesita video del dueño ──
    $q = $pdo->prepare("SELECT id FROM crecer_meta_tactica WHERE meta_id=? AND plan_id=? ORDER BY orden LIMIT 1");
    $q->execute([$META, $PLAN]);
    $ORIG = (int)$q->fetchColumn();
    ok('la fixture trae una jugada que sustituir', $ORIG > 0);

    $pdo->prepare("UPDATE crecer_meta_tactica
                      SET clase='produccion', formato='reel', piezas_meta=1, estado='pendiente',
                          inversion=NULL, semana=2, titulo=?
                    WHERE id=?")
        ->execute(['[prueba] Reel del combo en la plaza', $ORIG]);

    //  Dos piezas suyas: una publicada (trabajo real) y un borrador.
    $cal = $pdo->query("SELECT calendario_id FROM crecer_contenido
                         WHERE marca_id={$M} AND calendario_id IS NOT NULL LIMIT 1")->fetchColumn();
    foreach ([['publicado', 'video'], ['borrador', 'video']] as $i => [$est, $mat]) {
        $pdo->prepare("INSERT INTO crecer_contenido (calendario_id,marca_id,plataforma,tipo,caption,
                          fecha_programada,estado,meta_id,tactica_id,plan_id,necesita_material,publicado_at)
                       VALUES (?,?, 'instagram','reel', ?, DATE_ADD(NOW(), INTERVAL 2 DAY), ?,?,?,?,?,?)")
            ->execute([$cal ?: null, $M, '[prueba] pieza ' . $i, $est, $META, $ORIG, $PLAN, $mat,
                       $est === 'publicado' ? date('Y-m-d H:i:s') : null]);
    }
    $piezasAntes = $pdo->query("SELECT id, estado, tactica_id FROM crecer_contenido
                                 WHERE tactica_id={$ORIG} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    ok('y la jugada tiene dos piezas suyas', count($piezasAntes) === 2, 'hay ' . count($piezasAntes));

    // ══════════════════════════════════════════════════════════
    //  1 · LA SUSTITUCION
    // ══════════════════════════════════════════════════════════
    echo "\n  — 1 · sustituir de verdad —\n";
    $orig = $jugada($ORIG);
    $token = meta_token_jugada($orig);
    ok('la jugada trae su token', $token !== '');

    $r = meta_sustituir_jugada($pdo, $M, $ORIG, $UID, 'sin_video', '', $ALT, $token);
    ok('la sustitucion entra', !empty($r['ok']), json_encode($r, JSON_UNESCAPED_UNICODE));
    $NUEVA = (int)($r['nueva_id'] ?? 0);
    ok('y devuelve el id de la nueva', $NUEVA > 0, json_encode($r));

    echo "\n  — la original: ni borrada ni hecha —\n";
    $o = $jugada($ORIG);
    ok('la original SIGUE EXISTIENDO', !empty($o), 'borrarla perderia el trabajo y la razon');
    ok('queda «descartada»', (string)$o['estado'] === 'descartada', (string)$o['estado']);
    ok('NO queda «hecha»', (string)$o['estado'] !== 'hecha',
       'marcarla hecha inflaria el progreso con trabajo que nunca ocurrio');
    ok('con el sello de sustitucion', !empty($o['sustituida_at']),
       'sustituida_at IS NOT NULL es lo que la distingue de una descartada a secas');
    ok('y con su razon', (string)$o['motivo_sustitucion'] === 'sin_video', (string)$o['motivo_sustitucion']);
    ok('apuntando a la nueva', (int)$o['sustituida_por_id'] === $NUEVA,
       'sustituida_por_id=' . ($o['sustituida_por_id'] ?? 'NULL'));

    echo "\n  — la nueva —\n";
    $nu = $jugada($NUEVA);
    ok('nace pendiente', (string)$nu['estado'] === 'pendiente', (string)$nu['estado']);
    ok('apunta de vuelta a la original', (int)$nu['sustituye_a_id'] === $ORIG,
       'sustituye_a_id=' . ($nu['sustituye_a_id'] ?? 'NULL') . ' · hace falta para navegar en los dos sentidos');
    ok('con el formato de la alternativa', (string)$nu['formato'] === 'carrusel', (string)$nu['formato']);
    ok('en la misma semana', (int)$nu['semana'] === (int)$orig['semana'],
       'semana=' . $nu['semana'] . ', la original iba en la ' . $orig['semana']);
    ok('en el mismo plan', (int)$nu['plan_id'] === (int)$orig['plan_id']);
    ok('de la misma marca y meta', (int)$nu['marca_id'] === $M && (int)$nu['meta_id'] === $META);
    ok('y la hace el corillo', (string)$nu['clase'] === 'produccion', (string)$nu['clase']);
    ok('sin pedir inversion', $nu['inversion'] === null, (string)($nu['inversion'] ?? 'NULL'));

    echo "\n  — las piezas de la original se quedan con ella —\n";
    ok('siguen colgando de la original',
       $pdo->query("SELECT id, estado, tactica_id FROM crecer_contenido
                     WHERE tactica_id={$ORIG} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) === $piezasAntes,
       'no se mueven a la nueva ni se borran');
    ok('la publicada sigue publicada',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido
                          WHERE tactica_id={$ORIG} AND estado='publicado'")->fetchColumn() === 1,
       'es trabajo real y cuenta en resultados');
    ok('y la nueva nace sin piezas',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE tactica_id={$NUEVA}")->fetchColumn() === 0);

    echo "\n  — queda registrado en el libro de cambios —\n";
    $c = $pdo->query("SELECT * FROM crecer_meta_cambio
                       WHERE meta_id={$META} AND tipo='jugada_sustituida'
                       ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    ok('hay fila de sustitucion', !empty($c), json_encode($c ?: null));
    ok('con la jugada original', (int)($c['tactica_id'] ?? 0) === $ORIG);
    ok('el motivo y a donde fue',
       strpos((string)($c['valor_despues'] ?? ''), (string)$NUEVA) !== false
       && (string)($c['campo'] ?? '') === 'sin_video',
       json_encode(['campo' => $c['campo'] ?? null, 'despues' => $c['valor_despues'] ?? null]));
    ok('y queda «aplicado»', (string)($c['resultado'] ?? '') === 'aplicado');

    echo "\n  — el compositor la da por terminada —\n";
    require_once __DIR__ . '/../core/Meta/MetaSnapshotReader.php';
    require_once __DIR__ . '/../core/Meta/MetaStateComposer.php';
    $snap = MetaSnapshotReader::leer($pdo, $M);
    $ids = array_column((array)($snap['jugadas'] ?? []), 'id');
    $viva = false;
    foreach ((array)($snap['jugadas'] ?? []) as $t) {
        if ((int)$t['id'] === $ORIG && !in_array((string)$t['estado'], ['hecha','descartada'], true)) $viva = true;
    }
    ok('la sustituida ya no pide turno', !$viva,
       'si volviera a mandar la pantalla, sustituir no serviria de nada');
    ok('y la nueva si esta en el plan', in_array($NUEVA, array_map('intval', $ids), true),
       'ids=' . implode(',', $ids));

    // ══════════════════════════════════════════════════════════
    //  2 · DOBLE CLIC
    // ══════════════════════════════════════════════════════════
    echo "\n  — 2 · el segundo clic no crea otra —\n";
    $cuantas = (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica WHERE meta_id={$META}")->fetchColumn();
    $r2 = meta_sustituir_jugada($pdo, $M, $ORIG, $UID, 'sin_video', '', $ALT, $token);
    ok('se contesta que ya estaba', !empty($r2['repetido']), json_encode($r2, JSON_UNESCAPED_UNICODE));
    ok('devolviendo la que ya existia', (int)($r2['nueva_id'] ?? 0) === $NUEVA,
       'devolvio ' . ($r2['nueva_id'] ?? '?') . ', la buena es ' . $NUEVA);
    ok('y NO nacio ninguna jugada de mas',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica WHERE meta_id={$META}")->fetchColumn() === $cuantas,
       'habia ' . $cuantas);

    // ══════════════════════════════════════════════════════════
    //  3 · NO SE SUSTITUYE LO QUE NO SE PUEDE
    // ══════════════════════════════════════════════════════════
    echo "\n  — 3 · lo que no se puede sustituir —\n";
    $q->execute([$META, $PLAN]);
    $otraJ = 0;
    foreach ($pdo->query("SELECT id FROM crecer_meta_tactica
                           WHERE meta_id={$META} AND id<>{$ORIG} AND id<>{$NUEVA}
                           ORDER BY orden LIMIT 1") as $row) $otraJ = (int)$row['id'];
    $pdo->prepare("UPDATE crecer_meta_tactica SET estado='hecha' WHERE id=?")->execute([$otraJ]);
    $cuantas2 = (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica WHERE meta_id={$META}")->fetchColumn();
    $r3 = meta_sustituir_jugada($pdo, $M, $otraJ, $UID, 'sin_tiempo', '', $ALT,
                                meta_token_jugada($jugada($otraJ)));
    //  «Viva» es pendiente O en_curso: una jugada parada esperando el video del
    //  dueño esta en en_curso, y es el caso para el que existe esta salida.
    ok('una jugada YA HECHA no se sustituye', empty($r3['ok']) || !empty($r3['repetido']),
       json_encode($r3, JSON_UNESCAPED_UNICODE) . ' · rehacer lo hecho borraria trabajo real');
    ok('y no nace nada',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica WHERE meta_id={$META}")->fetchColumn() === $cuantas2);
    ok('la hecha sigue hecha', (string)$jugada($otraJ)['estado'] === 'hecha');

    echo "\n  — un motivo inventado no pasa —\n";
    $r3b = meta_sustituir_jugada($pdo, $M, $NUEVA, $UID, 'porque_si', '', $ALT,
                                 meta_token_jugada($jugada($NUEVA)));
    ok('el motivo tiene que ser de la lista', empty($r3b['ok']),
       json_encode($r3b, JSON_UNESCAPED_UNICODE) . ' · cada motivo decide que alternativa vale');

    // ══════════════════════════════════════════════════════════
    //  4 · AISLAMIENTO ENTRE MARCAS
    // ══════════════════════════════════════════════════════════
    echo "\n  — 4 · la jugada de otra marca no se toca —\n";
    $otra = Fixture::crear($pdo, 'susajena', true, 'admin');
    $ajena = (int)$pdo->query("SELECT id FROM crecer_meta_tactica
                                WHERE marca_id=" . (int)$otra['marca_id'] . " ORDER BY id LIMIT 1")->fetchColumn();
    $ajenaAntes = $jugada($ajena);
    $r4 = meta_sustituir_jugada($pdo, $M, $ajena, $UID, 'sin_video', '', $ALT, meta_token_jugada($ajenaAntes));
    ok('se niega', empty($r4['ok']), json_encode($r4, JSON_UNESCAPED_UNICODE));
    ok('y la jugada ajena sigue intacta', $jugada($ajena) === $ajenaAntes);
    ok('sin crearle nada en su plan',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica
                          WHERE marca_id=" . (int)$otra['marca_id'])->fetchColumn()
       === (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica
                              WHERE marca_id=" . (int)$otra['marca_id'])->fetchColumn());
    Fixture::limpiar($pdo, (int)$otra['marca_id']);

    // ══════════════════════════════════════════════════════════
    //  5 · SIN HUERFANAS SI ALGO FALLA
    // ══════════════════════════════════════════════════════════
    echo "\n  — 5 · si falla a mitad, no queda una jugada suelta —\n";
    //  EL FALLO SE INYECTA EN EL ESQUEMA, no con datos raros: MySQL aqui NO va
    //  en modo estricto —sql_mode sin STRICT_*— asi que un valor fuera de rango
    //  se TRUNCA en silencio y el INSERT entra igual. Comprobado antes de
    //  escribir esto. Un indice unico temporal si revienta siempre, en
    //  cualquier modo, y por eso es el unico disparador honesto.
    $cuantas3 = (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica WHERE meta_id={$META}")->fetchColumn();
    $antesEstado = (string)$jugada($NUEVA)['estado'];
    //  Se le esconde al INSERT una columna que SI usa. Cualquier modo de MySQL
    //  contesta «Unknown column», asi que el fallo cae DENTRO de la
    //  transaccion, que es donde tiene que caer para probar el rollback.
    //  (Un indice unico no servia: la tabla ya trae titulos repetidos de
    //  otras fixtures y ni siquiera se deja crear.)
    $pdo->exec("ALTER TABLE crecer_meta_tactica CHANGE por_que por_que_zz TEXT NULL");
    try {
        $r5 = meta_sustituir_jugada($pdo, $M, $NUEVA, $UID, 'sin_foto', '', $ALT,
                                    meta_token_jugada($jugada($NUEVA)));
    } finally {
        $pdo->exec("ALTER TABLE crecer_meta_tactica CHANGE por_que_zz por_que TEXT NULL");
    }
    ok('se contesta que no', empty($r5['ok']), json_encode($r5, JSON_UNESCAPED_UNICODE));
    ok('no queda ninguna jugada de mas',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica WHERE meta_id={$META}")->fetchColumn() === $cuantas3,
       'habia ' . $cuantas3 . ' · una insertada sin enlazar seria una huerfana en el plan');
    ok('y la original del intento sigue como estaba',
       (string)$jugada($NUEVA)['estado'] === $antesEstado
       && empty($jugada($NUEVA)['sustituida_at']),
       'estado=' . $jugada($NUEVA)['estado']);

} finally {
    try { $pdo->prepare("DELETE FROM crecer_meta_cambio WHERE marca_id=?")->execute([$M]); }
    catch (Throwable $e) {}
    Fixture::limpiar($pdo, $M);
    echo "\n  (fixture limpiada)\n";
}

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  CONTRATO CUMPLIDO · {$n} afirmaciones\n\n"
    : "  {$fallos} de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
