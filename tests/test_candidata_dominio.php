<?php
// ============================================================
//  CRECER — OTRA IMAGEN SIN PERDER LA QUE YA TIENE
//  tests/test_candidata_dominio.php
//
//  LA REGLA QUE NO SE PUEDE ROMPER: la imagen que el dueño ya tiene no se toca
//  hasta que EL diga que si. Antes la nueva pisaba la suya en cuanto llegaba —
//  o sea que pedir otra opcion era una apuesta: si no te gusta, la tuya ya no
//  esta.
//
//  Y LA SEGUNDA: una intencion viva es UN trabajo. Un doble clic, un reenvio del
//  formulario o una conexion que reintenta no pueden acabar en dos llamadas al
//  proveedor. El arbitro es la fila de la pieza, bloqueada.
//
//  Se prueba sobre BASE DESECHABLE para poder mirar el esquema viejo y el nuevo
//  sin tocar la compartida, y con el proveedor sustituido.
// ============================================================

define('OPENAI_API_KEY', 'sk-falsa-de-prueba-no-autentica');
define('CRECER_TEST_RED_FALSA', true);

const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
$GLOBALS['CAND'] = ['post' => 0, 'fallar' => false];

/** El doble del transporte. Ni un socket. */
function ia_http_post_retry(string $url, array $headers, string $body,
                            int $max_reintentos = 4, int $timeout = 60): string {
    $GLOBALS['CAND']['post']++;
    if (!empty($GLOBALS['CAND']['fallar'])) {
        return json_encode(['error' => ['message' => 'simulado: el proveedor dijo que no']]);
    }
    return json_encode(['data' => [['b64_json' => PNG_1X1]]]);
}

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/candidata.php';
require_once __DIR__ . '/_esquema_desechable.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nOTRA IMAGEN, SIN PERDER LA QUE YA TIENE\n" . str_repeat('=', 58) . "\n";

$cnt = fn(string $t, string $w = '1') => (int)$GLOBALS['pdo']->query(
    "SELECT COUNT(*) FROM {$t} WHERE {$w}")->fetchColumn();
$g0 = ['ia' => $cnt('crecer_ia_log'), 'as' => $cnt('crecer_img_cuota_asiento'),
       'gen' => $cnt('crecer_generaciones')];

echo "\n  — el proveedor, sustituido —\n";
ok('modo prueba puesto',    defined('CRECER_TEST_MODE') && CRECER_TEST_MODE);
ok('con transporte falso',  defined('CRECER_TEST_RED_FALSA') && CRECER_TEST_RED_FALSA);

// ══════════════════════════════════════════════════════════════
//  1 · LAS COMBINACIONES IMPOSIBLES, EN EL DOMINIO
// ══════════════════════════════════════════════════════════════
//
//  Se valida aqui y no solo en la pantalla porque la pantalla no es el unico
//  que escribe: hay un worker, hay reintentos y habra mas caminos.
echo "\n  — la entrega y la decisión son dos ejes —\n";
$VALIDAS = [['queued', null], ['directing', null], ['generating', null],
            ['completed', null], ['completed', 'elegida'], ['completed', 'descartada'],
            ['failed', null]];
$INVALIDAS = [['failed', 'elegida'], ['failed', 'descartada'], ['queued', 'elegida'],
              ['generating', 'descartada'], ['completed', 'quizas'], ['queued', 'descartada']];
foreach ($VALIDAS as [$e, $d]) {
    ok("«{$e} + " . ($d ?? 'NULL') . "» es válida", cand_combinacion_valida($e, $d));
}
foreach ($INVALIDAS as [$e, $d]) {
    ok("«{$e} + " . ($d ?? 'NULL') . "» NO es válida", !cand_combinacion_valida($e, $d),
       'decidir sobre algo que no se entregó sería afirmar que el dueño aplicó una imagen que no existe');
}

$limpiar = [];
$copia = EsquemaDesechable::crear($pdo, ['crecer_generaciones', 'crecer_contenido',
                                         'crecer_marca', 'crecer_meta', 'crecer_meta_tactica',
                                         'crecer_img_cuota_asiento', 'crecer_img_cuota_cubo',
                                         'crecer_ia_log', 'crecer_activos']);
if ($copia === null) {
    echo "\n  (sin privilegios para la base de copia · se salta)\n";
} else {
  try {
    $v = $copia->pdo();

    // ══════════════════════════════════════════════════════════════
    //  2 · CON EL ESQUEMA VIEJO: NO SE OFRECE, Y NO REVIENTA
    // ══════════════════════════════════════════════════════════════
    echo "\n  — con el esquema viejo, la opción no se ofrece —\n";
    //  EL INDICE TAMBIEN. `CREATE TABLE ... LIKE` copia los indices, y quitar
    //  dos columnas de un indice COMPUESTO no lo borra: sobrevive sobre las que
    //  quedan. Sin esto, la migracion de abajo muere con «Duplicate key name» y
    //  la prueba culpa a la migracion de un defecto de la prueba.
    $copia->ejecutar("ALTER TABLE crecer_generaciones DROP INDEX idx_generacion_decision");
    $copia->ejecutar("ALTER TABLE crecer_generaciones DROP COLUMN decision_dueno");
    $copia->ejecutar("ALTER TABLE crecer_generaciones DROP COLUMN decidida_at");
    ok('el código ve que faltan', cand_hay_columnas($v, true) === false);

    $v->prepare("INSERT INTO crecer_marca (id, usuario_id, nombre_negocio, slug)
                 VALUES (7, 1, '[prueba] Repostería', 'p7')")->execute();
    $v->prepare("INSERT INTO crecer_contenido
            (id, marca_id, plataforma, tipo, caption, estado, grafica_path, fecha_programada)
          VALUES (?,?, 'instagram','post',?, 'borrador', ?, DATE_ADD(NOW(), INTERVAL 2 DAY))")
      ->execute([200, 7, '[prueba] Bizcocho de la casa.', '/crecer/uploads/marca_7/actual.png']);

    $pz = $v->query("SELECT * FROM crecer_contenido WHERE id=200")->fetch(PDO::FETCH_ASSOC);
    $p  = cand_puede($v, 7, $pz);
    ok('no se puede ofrecer',    $p['puede'] === false && $p['motivo'] === 'sin_columnas');
    ok('y lo dice en neutro',    $p['frase'] === 'Esta opción no está disponible ahora.',
       'sin acusar al dueño ni enseñarle nuestro esquema');

    $r = cand_abrir($v, 7, 200, CAND_MISMA_IDEA);
    ok('abrir se niega sin fatal', empty($r['ok']) && $r['motivo'] === 'sin_columnas');
    ok('y la imagen actual sigue intacta',
       (string)$v->query("SELECT grafica_path FROM crecer_contenido WHERE id=200")->fetchColumn()
         === '/crecer/uploads/marca_7/actual.png',
       'JAMÁS caer a sobrescribir cuando falta el esquema');
    ok('cero llamadas al proveedor', $GLOBALS['CAND']['post'] === 0);

    // ══════════════════════════════════════════════════════════════
    //  3 · SE MIGRA, Y AHORA SÍ
    // ══════════════════════════════════════════════════════════════
    echo "\n  — se aplica la migración —\n";
    $mig = (string)file_get_contents(dirname(__DIR__)
         . '/migrations/2026-08-26_crecer_generacion_decision.sql');
    $copia->ejecutar(trim((string)preg_replace('~^\s*--[^\n]*$~m', '', $mig)));
    ok('las columnas están', cand_hay_columnas($v, true));
    ok('y el índice también',
       (int)$v->query("SELECT COUNT(*) FROM information_schema.STATISTICS
                        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crecer_generaciones'
                          AND INDEX_NAME='idx_generacion_decision'")->fetchColumn() === 4);

    //  CORRERLA DOS VECES SE NOTA, no se traga en silencio.
    $dos = false;
    try { $copia->ejecutar("ALTER TABLE crecer_generaciones ADD COLUMN decision_dueno VARCHAR(12) NULL"); }
    catch (Throwable $e) { $dos = true; }
    ok('correrla dos veces avisa', $dos,
       'el migrador enseña «aplicada» mirando la base: no la corre dos veces a ciegas');

    //  NO HAY BACKFILL: nada se marca solo.
    ok('ninguna fila nació decidida',
       (int)$v->query("SELECT COUNT(*) FROM crecer_generaciones WHERE decision_dueno IS NOT NULL")
              ->fetchColumn() === 0,
       'una fila sin decisión es una fila SIN DECISIÓN');

    // ══════════════════════════════════════════════════════════════
    //  4 · ABRIR UNA INTENCIÓN NO TOCA LA PUBLICACIÓN
    // ══════════════════════════════════════════════════════════════
    echo "\n  — pedir otra opción no toca lo que ya tiene —\n";
    $antes = $v->query("SELECT grafica_path, caption, estado, fecha_programada
                          FROM crecer_contenido WHERE id=200")->fetch(PDO::FETCH_ASSOC);

    $r = cand_abrir($v, 7, 200, CAND_MISMA_IDEA);
    ok('se abre la intención',   !empty($r['ok']), json_encode($r));
    ok('y NO se reusó ninguna',  empty($r['reusada']));
    $G1 = (int)$r['gen']['id'];
    ok('nace en cola',           (string)$r['gen']['estado'] === 'queued');
    ok('sin decisión',           $r['gen']['decision_dueno'] === null);
    ok('atada a la pieza',       (int)$r['gen']['contenido_id'] === 200);
    ok('con su instrucción guardada', trim((string)$r['gen']['prompt_narrativo']) !== '',
       'que viva en la fila es lo que permite recargar y volver');

    $ahora = $v->query("SELECT grafica_path, caption, estado, fecha_programada
                          FROM crecer_contenido WHERE id=200")->fetch(PDO::FETCH_ASSOC);
    ok('la imagen actual, intacta',  $ahora['grafica_path'] === $antes['grafica_path']);
    ok('el texto, intacto',          $ahora['caption'] === $antes['caption']);
    ok('la fecha, intacta',          $ahora['fecha_programada'] === $antes['fecha_programada']);
    ok('y no se aprobó nada',        $ahora['estado'] === $antes['estado']);
    ok('cero llamadas al proveedor', $GLOBALS['CAND']['post'] === 0,
       'abrir la intención no llama a nadie: eso lo hace el worker, después');

    // ══════════════════════════════════════════════════════════════
    //  5 · EL ARBITRAJE · dos intentos, una intención
    // ══════════════════════════════════════════════════════════════
    echo "\n  — un doble clic no abre dos trabajos —\n";
    $r2 = cand_abrir($v, 7, 200, CAND_MISMA_IDEA);
    ok('el segundo devuelve la MISMA', !empty($r2['ok']) && (int)$r2['gen']['id'] === $G1,
       json_encode($r2['gen']['id'] ?? null));
    ok('y dice que la reusó',          !empty($r2['reusada']));
    ok('una sola fila para esa pieza',
       (int)$v->query("SELECT COUNT(*) FROM crecer_generaciones WHERE contenido_id=200")
              ->fetchColumn() === 1);

    //  Y CAMBIAR DE IDEA CON UNA VIVA TAMPOCO ABRE OTRA.
    $r3 = cand_abrir($v, 7, 200, CAND_IDEA_DIFERENTE);
    ok('ni cambiando de intención', (int)$r3['gen']['id'] === $G1,
       'con una viva encima, «generar otra» vuelve a ella: no se gastan dos por una');

    //  DOS PIEZAS SON DOS IDENTIDADES.
    $v->prepare("INSERT INTO crecer_contenido
            (id, marca_id, plataforma, tipo, caption, estado, grafica_path, fecha_programada)
          VALUES (201, 7, 'instagram','post','[prueba] Otra.', 'borrador', '', DATE_ADD(NOW(), INTERVAL 3 DAY))")
      ->execute();
    $r4 = cand_abrir($v, 7, 201, CAND_MISMA_IDEA);
    ok('otra pieza abre su propia intención',
       !empty($r4['ok']) && (int)$r4['gen']['id'] !== $G1);
    $G2 = (int)$r4['gen']['id'];

    // ══════════════════════════════════════════════════════════════
    //  6 · MISMA IDEA vs IDEA DIFERENTE · contratos distintos
    // ══════════════════════════════════════════════════════════════
    //
    //  No basta que los textos sean distintos: tienen que decir cosas OPUESTAS
    //  sobre el concepto. «Otra idea» que conserve el concepto es «otra versión»
    //  con otro nombre, y el dueño pagó por otra cosa.
    echo "\n  — «otra versión» y «otra idea» piden cosas distintas —\n";
    $pz2 = $v->query("SELECT * FROM crecer_contenido WHERE id=200")->fetch(PDO::FETCH_ASSOC);
    $mi = cand_instruccion($v, 7, $pz2, CAND_MISMA_IDEA, '');
    $di = cand_instruccion($v, 7, $pz2, CAND_IDEA_DIFERENTE, '');

    ok('los textos son distintos', $mi['texto'] !== $di['texto']);
    ok('«misma idea» CONSERVA el concepto',
       in_array('el concepto y de qué habla la imagen', $mi['contrato']['conservar'], true));
    ok('«idea diferente» lo EVITA',
       in_array('el concepto que se está usando ahora', $di['contrato']['evitar'], true));
    ok('y el concepto no está en los dos lados',
       !in_array('el concepto que se está usando ahora', $mi['contrato']['evitar'], true)
       && !in_array('el concepto y de qué habla la imagen', $di['contrato']['conservar'], true),
       'si conservar y evitar dicen lo mismo, el contrato no dice nada');
    ok('«idea diferente» excluye el sujeto y la metáfora',
       in_array('el sujeto principal actual', $di['contrato']['evitar'], true)
       && in_array('la metáfora actual', $di['contrato']['evitar'], true));
    ok('las dos conservan el producto y la marca',
       in_array('el producto o servicio', $mi['contrato']['conservar'], true)
       && in_array('el producto o servicio', $di['contrato']['conservar'], true)
       && in_array('la identidad del negocio', $di['contrato']['conservar'], true));
    ok('las dos llevan lo que comunica',
       str_contains($mi['texto'], 'Bizcocho de la casa') && str_contains($di['texto'], 'Bizcocho de la casa'));
    ok('y el negocio',
       str_contains($mi['texto'], 'Repostería') && str_contains($di['texto'], 'Repostería'));
    ok('no es «hazla diferente» pegado al final',
       !str_contains(mb_strtolower($di['texto']), 'hazla diferente'),
       'una coletilla no es un encargo distinto');

    //  EL TEXTO DEL DUEÑO ENTRA, RECORTADO Y TAL CUAL.
    $ev = cand_instruccion($v, 7, $pz2, CAND_IDEA_DIFERENTE, '  sin personas, sin café  ');
    ok('lo que pide evitar entra',  in_array('sin personas, sin café', $ev['contrato']['evitar'], true));
    ok('y aparece en la instrucción', str_contains($ev['texto'], 'sin personas, sin café'));
    $largo = cand_instruccion($v, 7, $pz2, CAND_MISMA_IDEA, str_repeat('x', 500));
    ok('un texto larguísimo se recorta',
       mb_strlen((string)end($largo['contrato']['evitar'])) <= 200);

    // ══════════════════════════════════════════════════════════════
    //  7 · DECIDIR · aplicar, descartar, y la carrera
    // ══════════════════════════════════════════════════════════════
    echo "\n  — decidir: usar la nueva o quedarse con la suya —\n";
    //  El worker entrega: escribe archivo y completed, y NADA MÁS.
    $NUEVA = '/crecer/uploads/marca_7/graficas/gen_' . $G1 . '_abc.png';
    $v->prepare("UPDATE crecer_generaciones SET estado='completed', archivo=? WHERE id=?")
      ->execute([$NUEVA, $G1]);

    ok('tras entregar, la actual sigue siendo la suya',
       (string)$v->query("SELECT grafica_path FROM crecer_contenido WHERE id=200")->fetchColumn()
         === '/crecer/uploads/marca_7/actual.png',
       'ESTE es el defecto que 2C cierra: la entrega ya no pisa nada');

    $pend = cand_pendiente($v, 7, 200);
    ok('hay candidata esperando', !empty($pend['hay']) && (string)$pend['estado'] === 'completed');
    ok('y se puede comparar con la actual',
       (string)$pend['actual'] === '/crecer/uploads/marca_7/actual.png'
       && (string)$pend['gen']['archivo'] === $NUEVA);

    //  DESCARTAR: la suya se queda, y la unidad sigue contada.
    $post_antes = $GLOBALS['CAND']['post'];
    $d = cand_decidir($v, 7, 200, $G1, 'descartada');
    ok('descartar sale bien',      !empty($d['ok']) && $d['decision'] === 'descartada', json_encode($d));
    ok('la imagen NO cambió',
       (string)$v->query("SELECT grafica_path FROM crecer_contenido WHERE id=200")->fetchColumn()
         === '/crecer/uploads/marca_7/actual.png');
    ok('queda anotado cuándo decidió',
       $v->query("SELECT decidida_at FROM crecer_generaciones WHERE id={$G1}")->fetchColumn() !== null);
    ok('y no se llamó a nadie',    $GLOBALS['CAND']['post'] === $post_antes);
    ok('la candidata sigue guardada, con su instrucción',
       trim((string)$v->query("SELECT prompt_narrativo FROM crecer_generaciones WHERE id={$G1}")
                      ->fetchColumn()) !== '',
       'para que la memoria creativa futura sepa qué dirección se rechazó');
    ok('y su archivo no se borra',
       (string)$v->query("SELECT archivo FROM crecer_generaciones WHERE id={$G1}")->fetchColumn() === $NUEVA);

    //  YA NO HAY PENDIENTE: la comparación no vuelve a abrirse sola.
    ok('ya no queda candidata pendiente', empty(cand_pendiente($v, 7, 200)['hay']),
       'sin esto, la comparación reaparecería para siempre en cada recarga');

    //  NO SE PUEDE APLICAR LO YA DESCARTADO.
    $d2 = cand_decidir($v, 7, 200, $G1, 'elegida');
    ok('no se aplica lo ya descartado',
       !empty($d2['ok']) && !empty($d2['ya_estaba']) && $d2['decision'] === 'descartada',
       json_encode($d2));
    ok('y la imagen sigue sin cambiar',
       (string)$v->query("SELECT grafica_path FROM crecer_contenido WHERE id=200")->fetchColumn()
         === '/crecer/uploads/marca_7/actual.png');

    //  UN CICLO NUEVO DELIBERADO SÍ SE PERMITE.
    echo "\n  — y un ciclo nuevo deliberado sí se permite —\n";
    $r5 = cand_abrir($v, 7, 200, CAND_IDEA_DIFERENTE, 'sin texto dentro de la imagen');
    ok('se abre otra intención',   !empty($r5['ok']) && (int)$r5['gen']['id'] !== $G1);
    $G3 = (int)$r5['gen']['id'];
    ok('y no reusó la descartada', empty($r5['reusada']));

    //  APLICAR: la publicación cambia, y solo la imagen.
    echo "\n  — usar la nueva cambia la imagen, y nada más —\n";
    $NUEVA3 = '/crecer/uploads/marca_7/graficas/gen_' . $G3 . '_xyz.png';
    $v->prepare("UPDATE crecer_generaciones SET estado='completed', archivo=? WHERE id=?")
      ->execute([$NUEVA3, $G3]);
    //  La pieza llevaba material del dueño: al pintar desde cero, se suelta.
    $v->prepare("INSERT INTO crecer_activos (id, marca_id, tipo, archivo, nombre, origen, estado)
                 VALUES (900, 7, 'imagen', 'marca_7/biblioteca/suya.jpg', '[prueba] Su foto', 'subido','activo')")
      ->execute();
    $v->prepare("UPDATE crecer_contenido SET material_activo_id=900 WHERE id=200")->execute();

    $ap = cand_decidir($v, 7, 200, $G3, 'elegida');
    ok('aplicar sale bien',      !empty($ap['ok']) && $ap['decision'] === 'elegida', json_encode($ap));
    $fin = $v->query("SELECT grafica_path, caption, estado, fecha_programada, material_activo_id
                        FROM crecer_contenido WHERE id=200")->fetch(PDO::FETCH_ASSOC);
    ok('la imagen es la nueva',  (string)$fin['grafica_path'] === $NUEVA3);
    ok('la traza de material se soltó', $fin['material_activo_id'] === null,
       'arte pintado desde cero no salió de su Biblioteca: decir que sí sería mentir');
    ok('el texto, intacto',      (string)$fin['caption'] === (string)$antes['caption']);
    ok('la fecha, intacta',      (string)$fin['fecha_programada'] === (string)$antes['fecha_programada']);
    ok('y no se aprobó',         (string)$fin['estado'] === (string)$antes['estado']);
    ok('cero llamadas al proveedor al decidir', $GLOBALS['CAND']['post'] === $post_antes);

    //  DOBLE CONFIRMACIÓN: idempotente.
    $ap2 = cand_decidir($v, 7, 200, $G3, 'elegida');
    ok('confirmar dos veces es idempotente',
       !empty($ap2['ok']) && !empty($ap2['ya_estaba']) && $ap2['decision'] === 'elegida');
    ok('y la imagen sigue siendo la misma',
       (string)$v->query("SELECT grafica_path FROM crecer_contenido WHERE id=200")->fetchColumn() === $NUEVA3);

    //  Y NO SE DESCARTA LO YA APLICADO.
    $ap3 = cand_decidir($v, 7, 200, $G3, 'descartada');
    ok('no se descarta lo ya aplicado', !empty($ap3['ya_estaba']) && $ap3['decision'] === 'elegida');

    // ══════════════════════════════════════════════════════════════
    //  8 · LO QUE NO SE PUEDE ALCANZAR
    // ══════════════════════════════════════════════════════════════
    echo "\n  — y lo que no es suyo, no se alcanza —\n";
    $r_aj = cand_abrir($v, 99, 200, CAND_MISMA_IDEA);
    ok('otra marca no abre nada', empty($r_aj['ok']) && $r_aj['motivo'] === 'no_tuya');
    $d_aj = cand_decidir($v, 99, 200, $G3, 'elegida');
    ok('otra marca no decide nada', empty($d_aj['ok']) && $d_aj['motivo'] === 'no_tuya');
    $d_ot = cand_decidir($v, 7, 201, $G3, 'elegida');
    ok('una candidata de otra pieza tampoco', empty($d_ot['ok']) && $d_ot['motivo'] === 'no_tuya',
       'la generación se exige de ESTA marca y de ESTA pieza');

    //  UNA PIEZA PUBLICADA NO SE TOCA.
    $v->prepare("UPDATE crecer_contenido SET estado='publicado' WHERE id=201")->execute();
    $pz3 = $v->query("SELECT * FROM crecer_contenido WHERE id=201")->fetch(PDO::FETCH_ASSOC);
    ok('una publicada no ofrece la opción', cand_puede($v, 7, $pz3)['motivo'] === 'publicada');
    ok('ni deja abrir intención',
       cand_abrir($v, 7, 201, CAND_MISMA_IDEA)['motivo'] === 'publicada');

    //  UN REEL NO LLEVA IMAGEN QUE CAMBIAR.
    $v->prepare("UPDATE crecer_contenido SET estado='borrador', tipo='reel' WHERE id=201")->execute();
    $pz4 = $v->query("SELECT * FROM crecer_contenido WHERE id=201")->fetch(PDO::FETCH_ASSOC);
    ok('un reel no ofrece «otra imagen»', cand_puede($v, 7, $pz4)['motivo'] === 'no_imagen');

    //  DECIDIR SOBRE ALGO QUE FALLÓ.
    $v->prepare("UPDATE crecer_generaciones SET estado='failed', decision_dueno=NULL,
                        error_msg='[prueba] simulado' WHERE id=?")->execute([$G2]);
    $d_f = cand_decidir($v, 7, 201, $G2, 'elegida');
    ok('no se aplica lo que falló', empty($d_f['ok']), json_encode($d_f));

  } finally {
    $copia->soltar($pdo);
    cand_hay_columnas($pdo, true);   // el cache vuelve a la base de siempre
  }
}

foreach ($limpiar as $mid) { try { Fixture::limpiar($pdo, $mid); } catch (Throwable $e) {} }

echo "\n  — el costo —\n";
ok('cero llamadas al proveedor', $GLOBALS['CAND']['post'] === 0,
   $GLOBALS['CAND']['post'] . ' · abrir y decidir no llaman a nadie');
ok('cero líneas de modelo en la base compartida', $cnt('crecer_ia_log') === $g0['ia']);
ok('cero asientos nuevos',       $cnt('crecer_img_cuota_asiento') === $g0['as']);
ok('cero generaciones nuevas',   $cnt('crecer_generaciones') === $g0['gen'],
   'todo esto vivió en la base desechable');

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  LA SUYA NO SE TOCA HASTA QUE ÉL DIGA · $n afirmaciones\n\n"
    : "  $fallos FALLAS de $n\n\n";
exit($fallos === 0 ? 0 : 1);
