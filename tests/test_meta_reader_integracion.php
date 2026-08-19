<?php
// ============================================================
//  CRECER — EL LECTOR CONTRA LA BASE, CON MUNDOS SEMBRADOS
//  tests/test_meta_reader_integracion.php
//
//  Los defectos que encontró la revisión vivían EN EL LECTOR, no en el
//  compositor, y por eso los snapshots sintéticos no los veían. Aquí se
//  siembran mundos reales en la base —meta cerrada, plan sin plan activo,
//  jugadas de planes históricos— y se comprueba qué devuelve el lector.
//
//  Todo ocurre dentro de una TRANSACCIÓN que se deshace siempre. Al final se
//  verifica que no quedó ni una fila.
//
//  Si no hay base, se salta: las pruebas del compositor no la necesitan.
// ============================================================

require_once __DIR__ . '/../core/Meta/MetaStateComposer.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nLECTOR · MUNDOS SEMBRADOS EN LA BASE\n" . str_repeat('=', 52) . "\n\n";

try {
    require_once __DIR__ . '/../includes/db.php';
    if (!isset($pdo) || !($pdo instanceof PDO)) throw new RuntimeException('sin conexión');
    $pdo->query('SELECT 1');
} catch (Throwable $e) {
    echo "  SALTADO · no hay base local (" . $e->getMessage() . ")\n\n";
    exit(0);
}
require_once __DIR__ . '/../core/Meta/MetaSnapshotReader.php';

// Dos marcas del usuario de pruebas: una para sembrar, otra para el aislamiento.
$marcas = $pdo->query("SELECT id FROM crecer_marca WHERE usuario_id = 7 ORDER BY id")
              ->fetchAll(PDO::FETCH_COLUMN);
if (count($marcas) < 2) { echo "  SALTADO · hacen falta 2 marcas de pruebas\n\n"; exit(0); }
$M  = (int)$marcas[0];   // la de siembra (sin meta propia)
$M2 = (int)$marcas[1];   // la vecina, para comprobar aislamiento
echo "  marca de siembra: {$M} · marca vecina: {$M2}\n\n";

$creados = ['meta' => [], 'plan' => [], 'tactica' => [], 'contenido' => []];

try {
    $pdo->beginTransaction();

    // ── 1 · Una marca que NUNCA tuvo meta ───────────────────
    echo "  — nunca hubo meta —\n";
    $s = MetaSnapshotReader::leer($pdo, $M);
    $e = MetaStateComposer::componer($s);
    ok('sin ninguna meta, el lector devuelve meta = null', $s['meta'] === null);
    ok('y el compositor dice A', $e->estado === MetaState::A_SIN_META, "obtenido: {$e->estado}");

    // ── 2 · Meta CERRADA reciente ───────────────────────────
    echo "\n  — meta cerrada reciente —\n";
    $pdo->prepare("INSERT INTO crecer_meta
                   (marca_id, objetivo, titulo, cantidad, unidad, fecha_inicio, fecha_limite,
                    medible, estado, created_at, updated_at)
                   VALUES (?, 'pedidos', 'Meta de prueba', 20, 'pedidos', ?, ?, 1, 'lograda', NOW(), NOW())")
        ->execute([$M, date('Y-m-d', strtotime('-30 days')), date('Y-m-d', strtotime('-2 days'))]);
    $meta_id = (int)$pdo->lastInsertId(); $creados['meta'][] = $meta_id;

    $s = MetaSnapshotReader::leer($pdo, $M);
    $e = MetaStateComposer::componer($s);
    ok('el lector SÍ encuentra la meta cerrada', $s['meta'] !== null
       && (int)$s['meta']['id'] === $meta_id);
    ok('con su estado real', ($s['meta']['estado'] ?? '') === 'lograda');
    ok('el compositor dice M, no A', $e->estado === MetaState::M_CERRADA,
       "obtenido: {$e->estado}/{$e->razon}");
    ok('y la razón lo nombra', $e->razon === 'meta_lograda', $e->razon);

    // Vieja: fuera de la ventana, vuelve a ser A.
    $pdo->prepare("UPDATE crecer_meta SET updated_at = ? WHERE id = ?")
        ->execute([date('Y-m-d H:i:s', strtotime('-120 days')), $meta_id]);
    $s_vieja = MetaSnapshotReader::leer($pdo, $M);
    $e_vieja = MetaStateComposer::componer($s_vieja);
    ok('una meta cerrada hace 120 días ya no se enseña: vuelve a A',
       $e_vieja->estado === MetaState::A_SIN_META, "obtenido: {$e_vieja->estado}");
    // Se reabre para las pruebas de plan: activa Y con fecha límite por delante.
    // Si se quedara vencida, M ganaría siempre (con razón) y taparía K y L.
    $pdo->prepare("UPDATE crecer_meta SET updated_at = NOW(), estado = 'activa', fecha_limite = ? WHERE id = ?")
        ->execute([date('Y-m-d', strtotime('+20 days')), $meta_id]);

    // ── 3 · Planes históricos que NO deben mezclarse ────────
    echo "\n  — meta activa sin plan activo —\n";
    //  Dos planes cerrados, cada uno con su jugada y su pieza. Ningún plan activo.
    foreach ([['reemplazado', 1], ['completado', 2]] as $i => [$estado_plan, $ver]) {
        $pdo->prepare("INSERT INTO crecer_meta_plan (meta_id, marca_id, version, estado, inicio_at, cierre_at)
                       VALUES (?,?,?,?, ?, ?)")
            ->execute([$meta_id, $M, $ver, $estado_plan,
                       date('Y-m-d H:i:s', strtotime('-20 days')),
                       date('Y-m-d H:i:s', strtotime('-' . (10 - $i * 5) . ' days'))]);
        $plan_id = (int)$pdo->lastInsertId(); $creados['plan'][] = $plan_id;

        $pdo->prepare("INSERT INTO crecer_meta_tactica
                       (meta_id, plan_id, marca_id, orden, semana, tipo, titulo, que_hacer, canal,
                        clase, piezas_meta, formato, estado)
                       VALUES (?,?,?,1,1,'contenido',?,'Hacer algo','instagram','produccion',1,'post','pendiente')")
            ->execute([$meta_id, $plan_id, $M, 'Jugada vieja v' . $ver]);
        $creados['tactica'][] = (int)$pdo->lastInsertId();

        // Un borrador viejo y una pieza publicada sin métricas.
        $pdo->prepare("INSERT INTO crecer_contenido (marca_id, plataforma, tipo, caption, estado, meta_id, plan_id)
                       VALUES (?, 'instagram','post','Borrador viejo v{$ver}','borrador', ?, ?)")
            ->execute([$M, $meta_id, $plan_id]);
        $creados['contenido'][] = (int)$pdo->lastInsertId();

        $pdo->prepare("INSERT INTO crecer_contenido (marca_id, plataforma, tipo, caption, estado, meta_id, plan_id, publicado_at)
                       VALUES (?, 'instagram','post','Publicado v{$ver}','publicado', ?, ?, ?)")
            ->execute([$M, $meta_id, $plan_id, date('Y-m-d H:i:s', strtotime('-8 days'))]);
        $creados['contenido'][] = (int)$pdo->lastInsertId();
    }

    $s = MetaSnapshotReader::leer($pdo, $M);
    $e = MetaStateComposer::componer($s);
    ok('sin plan activo, plan = null', $s['plan'] === null);
    ok('jugadas va VACÍO (antes traía las de todos los planes)', count($s['jugadas']) === 0,
       'trajo: ' . count($s['jugadas']));
    ok('piezas va VACÍO', count($s['piezas']) === 0, 'trajo: ' . count($s['piezas']));
    ok('jobs va VACÍO', count($s['jobs']) === 0);
    ok('los borradores viejos NO dominan: el estado no es F',
       $e->estado !== MetaState::F_APROBACION, "obtenido: {$e->estado}/{$e->razon}");

    // ── 4 · El plan en observación ──────────────────────────
    echo "\n  — el plan en observación —\n";
    ok('el lector identifica un plan cerrado con piezas publicadas',
       $s['observacion'] !== null);
    if ($s['observacion']) {
        ok('es el más reciente de los cerrados',
           (int)$s['observacion']['plan']['id'] === (int)$creados['plan'][1],
           'obtuvo plan ' . $s['observacion']['plan']['id']);
        $ids_obs = array_map(fn($p) => (int)$p['id'], $s['observacion']['piezas']);
        $del_otro = array_intersect($ids_obs, [$creados['contenido'][0], $creados['contenido'][1]]);
        ok('sus piezas son SOLO las suyas, no las del otro plan cerrado',
           count($del_otro) === 0, 'se coló: ' . implode(',', $del_otro));
    }
    ok('con piezas publicadas sin métricas → K', $e->estado === MetaState::K_MIDIENDO,
       "obtenido: {$e->estado}/{$e->razon}");

    // ── 5 · Con métricas y lección → L ──────────────────────
    echo "\n  — medido y con lección → L —\n";
    $obs_plan = (int)$creados['plan'][1];
    $q = $pdo->prepare("SELECT id FROM crecer_contenido WHERE plan_id = ? AND estado='publicado'");
    $q->execute([$obs_plan]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        $pdo->prepare("INSERT INTO crecer_metricas (contenido_id, marca_id, plataforma, alcance, interacciones)
                       VALUES (?,?, 'instagram', 100, 5)")->execute([(int)$cid, $M]);
    }
    $pdo->prepare("UPDATE crecer_meta_plan SET leccion = ?, funciono = 1 WHERE id = ?")
        ->execute(['Los posts de la tarde midieron mejor.', $obs_plan]);

    $s2 = MetaSnapshotReader::leer($pdo, $M);
    $e2 = MetaStateComposer::componer($s2);
    ok('el lector trae la lección de ESE plan',
       ($s2['plan_cerrado']['id'] ?? 0) === $obs_plan);
    ok('con todo medido, el estado pasa a L', $e2->estado === MetaState::L_APRENDIZAJE,
       "obtenido: {$e2->estado}/{$e2->razon}");
    ok('y enseña la lección', strpos($e2->instruccion, 'measured') !== false
       || strpos($e2->instruccion, 'midieron') !== false, $e2->instruccion);

    // ── 6 · Aislamiento por marca ───────────────────────────
    echo "\n  — aislamiento por marca —\n";
    $sv = MetaSnapshotReader::leer($pdo, $M2);
    ok('la marca vecina no ve la meta sembrada',
       ($sv['meta']['id'] ?? 0) !== $meta_id);
    ok('ni sus jugadas', count(array_intersect(
        array_map(fn($t) => (int)$t['id'], $sv['jugadas']), $creados['tactica'])) === 0);
    ok('ni sus piezas', count(array_intersect(
        array_map(fn($p) => (int)$p['id'], $sv['piezas']), $creados['contenido'])) === 0);
    ok('ni su plan en observación',
       ($sv['observacion']['plan']['id'] ?? 0) !== $obs_plan);

} catch (Throwable $ex) {
    ok('la siembra funcionó', false, $ex->getMessage() . ' @ ' . $ex->getFile() . ':' . $ex->getLine());
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}

// ── Nada quedó sembrado ─────────────────────────────────────
echo "\n  — la prueba no deja rastro —\n";
$sobras = 0;
foreach ([['crecer_meta', $creados['meta']], ['crecer_meta_plan', $creados['plan']],
          ['crecer_meta_tactica', $creados['tactica']], ['crecer_contenido', $creados['contenido']]] as [$tabla, $ids]) {
    if (!$ids) continue;
    $in = implode(',', array_map('intval', $ids));
    try { $sobras += (int)$pdo->query("SELECT COUNT(*) FROM {$tabla} WHERE id IN ({$in})")->fetchColumn(); }
    catch (Throwable $e) {}
}
ok('no quedó ni una fila de las sembradas', $sobras === 0, "quedaron: {$sobras}");

echo "\n" . str_repeat('=', 52) . "\n";
echo $fallos === 0 ? "  TODO OK · {$n} pruebas\n\n" : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
