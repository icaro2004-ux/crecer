<?php
// ============================================================
//  CRECER — LA SEMANA USA SU MATERIAL, Y NO LE PISA EL CALENDARIO
//  tests/test_semana_material.php
//
//  LA PROMESA ES: «deja tus fotos y videos; cuando encajen con tu Meta, te
//  propongo usarlos». Hasta ahora era mentira a medias — el ejecutor cogia una
//  foto de la Biblioteca y se la mandaba a Gemini COMO BASE: costaba una unidad
//  de cuota, y despues soltaba la trazabilidad, asi que la publicacion acababa
//  diciendo «arte del corillo». El dueño pagaba por usar su propia foto y
//  encima no se le reconocia.
//
//  LO QUE SE PRUEBA:
//    1 · con un activo elegido, la pieza USA ese archivo: se enlaza, no se
//        genera, no se gasta una sola unidad de cuota;
//    2 · si el activo ya no esta o no le sirve al formato, NO se pone otra cosa
//        en silencio: la pieza queda pidiendo material suyo;
//    3 · un video solo entra donde cabe un video;
//    4 · la fecha nueva no cae encima de lo que el dueño ya tenia programado;
//    5 · «Lo que tomé en cuenta» dice solo lo que de verdad paso.
//
//  CERO PROVEEDOR Y CERO CUOTA: se cuenta al final, no se supone.
// ============================================================

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/meta_ejecutar.php';
require_once __DIR__ . '/../includes/meta_ciclo.php';
require_once __DIR__ . '/../includes/material.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nLA SEMANA USA SU MATERIAL\n" . str_repeat('=', 58) . "\n";

if (!material_hay_columna($pdo)) {
    echo "\n  SALTADA · falta la migración de material\n\n"; exit(0);
}

$cnt = fn(string $t, string $w) => (int)$GLOBALS['pdo']->query("SELECT COUNT(*) FROM {$t} WHERE {$w}")->fetchColumn();
$limpiar = [];

try {
    $fx = Fixture::crear($pdo, 'mat', true, 'admin');
    $limpiar[] = $M = (int)$fx['marca_id'];
    $META = (int)$fx['meta_id']; $PLAN = (int)$fx['plan_id'];

    $hay_activo_col = false;
    try {
        $q = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crecer_meta_tactica'
                             AND COLUMN_NAME='activo_id'");
        $hay_activo_col = ((int)$q->fetchColumn() > 0);
    } catch (Throwable $e) {}
    if (!$hay_activo_col) {
        echo "\n  SALTADA · falta migrations/2026-08-28_crecer_contexto_unico.sql\n\n";
        Fixture::limpiar($pdo, $M); exit(0);
    }

    //  UNA FOTO DE VERDAD EN EL DISCO: sin archivo, «usar tu foto» no se puede
    //  comprobar de verdad — solo se comprobaría que se escribió una fila.
    require_once __DIR__ . '/_png.php';
    $base = rtrim(defined('UPLOADS_PATH') ? UPLOADS_PATH : dirname(__DIR__) . '/uploads', '/\\');
    $dir  = $base . DIRECTORY_SEPARATOR . 'marca_' . $M;
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'vitrina.jpg', png_solido(600, 600, 220, 120, 160));
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'clip.mp4', 'video de prueba');

    $ins = $pdo->prepare("INSERT INTO crecer_activos
             (marca_id, tipo, archivo, nombre, nota, origen, estado)
           VALUES (?,?,?,?,'', 'subida', 'activo')");
    $ins->execute([$M, 'imagen', "marca_{$M}/vitrina.jpg", 'La vitrina llena']);
    $FOTO = (int)$pdo->lastInsertId();
    $ins->execute([$M, 'video',  "marca_{$M}/clip.mp4", 'El mostrador']);
    $VIDEO = (int)$pdo->lastInsertId();

    /** Una jugada de producción, opcionalmente con activo elegido. */
    $jugada = function (string $formato, ?int $activo, string $titulo) use ($pdo, $M, $META, $PLAN): int {
        $pdo->prepare("INSERT INTO crecer_meta_tactica
                (meta_id, plan_id, marca_id, orden, semana, tipo, titulo, que_hacer, por_que,
                 canal, cta, quien, estado, clase, piezas_meta, formato, activo_id)
              VALUES (?,?,?,9,5,'contenido',?,?,?, 'instagram','Escríbeme','corillo','pendiente',
                      'produccion',1,?,?)")
            ->execute([$META, $PLAN, $M, $titulo, 'Una pieza para probar.',
                       'Para comprobar el material.', $formato, $activo]);
        return (int)$pdo->lastInsertId();
    };

    // ══════════════════════════════════════════════════════════════
    //  1 · CON SU FOTO: SE ENLAZA, NO SE GENERA
    // ══════════════════════════════════════════════════════════════
    echo "\n  — con su foto: se usa la suya, y no cuesta —\n";
    $asientos0 = $cnt('crecer_img_cuota_asiento', "marca_id={$M}");
    $ia0       = $cnt('crecer_ia_log', "marca_id={$M}");

    $T1 = $jugada('post', $FOTO, '[prueba] Con la foto de la vitrina');
    $r1 = jugada_ejecutar($pdo, $M, $T1);
    ok('la jugada produce',        !empty($r1['ok']), json_encode($r1));
    $C1 = (int)($r1['ids'][0] ?? 0);
    ok('y nace una pieza',         $C1 > 0, json_encode($r1['ids'] ?? []));

    $p1 = $pdo->query("SELECT * FROM crecer_contenido WHERE id={$C1}")->fetch(PDO::FETCH_ASSOC);
    ok('la pieza apunta a SU activo',
       (int)($p1['material_activo_id'] ?? 0) === $FOTO,
       json_encode(['material' => $p1['material_activo_id'] ?? null, 'esperado' => $FOTO]));
    ok('y la imagen es su archivo',
       str_contains((string)$p1['grafica_path'], 'vitrina.jpg'), (string)$p1['grafica_path']);
    ok('con su caption escrito',   trim((string)$p1['caption']) !== '');
    ok('y su fecha puesta',        !empty($p1['fecha_programada']));
    ok('sin quedarse pidiendo material',
       trim((string)($p1['necesita_material'] ?? '')) === '', (string)($p1['necesita_material'] ?? ''));

    //  LO QUE DE VERDAD IMPORTA: no se gastó una unidad.
    ok('CERO unidades de cuota',
       $cnt('crecer_img_cuota_asiento', "marca_id={$M}") === $asientos0,
       'usar material propio no puede costarle al dueño');
    ok('y ninguna llamada de imagen',
       $cnt('crecer_ia_log', "marca_id={$M} AND agente='imagen'") === 0,
       'si pasa por el modelo de imagen, no está usando su foto: la está reinterpretando');

    //  Y QUEDA EN LA MEMORIA VISUAL: su material también es un antecedente.
    ok('queda huella de que se usó',
       $cnt('crecer_visual_huella', "marca_id={$M} AND lente='foto:{$FOTO}'") >= 1,
       'repetir la misma foto tres semanas seguidas es repetirse igual');

    // ══════════════════════════════════════════════════════════════
    //  2 · SI EL ACTIVO YA NO ESTÁ: NO SE PONE OTRA COSA
    // ══════════════════════════════════════════════════════════════
    echo "\n  — si su foto ya no está, se dice; no se sustituye a escondidas —\n";
    $ins->execute([$M, 'imagen', "marca_{$M}/vitrina.jpg", 'La que se archivará']);
    $FOTO2 = (int)$pdo->lastInsertId();
    $T2 = $jugada('post', $FOTO2, '[prueba] Con una foto que se archivó');
    $pdo->prepare("UPDATE crecer_activos SET estado='archivado' WHERE id=?")->execute([$FOTO2]);

    $r2 = jugada_ejecutar($pdo, $M, $T2);
    $C2 = (int)($r2['ids'][0] ?? 0);
    ok('la jugada no se cae',      !empty($r2['ok']) && $C2 > 0, json_encode($r2));
    $p2 = $pdo->query("SELECT * FROM crecer_contenido WHERE id={$C2}")->fetch(PDO::FETCH_ASSOC);
    ok('la pieza pide material suyo',
       (string)($p2['necesita_material'] ?? '') === 'foto', json_encode($p2['necesita_material'] ?? null));
    ok('y NO se le puso otra foto',
       (int)($p2['material_activo_id'] ?? 0) === 0, (string)($p2['material_activo_id'] ?? ''));
    ok('sigue sin costar cuota',
       $cnt('crecer_img_cuota_asiento', "marca_id={$M}") === $asientos0);
    ok('y se lo dice al dueño',
       str_contains(mb_strtolower((string)$r2['resumen']), 'no estaba disponible'),
       (string)$r2['resumen']);

    // ══════════════════════════════════════════════════════════════
    //  3 · UN VIDEO SOLO DONDE CABE UN VIDEO
    // ══════════════════════════════════════════════════════════════
    echo "\n  — un video no entra en un post —\n";
    $T3 = $jugada('post', $VIDEO, '[prueba] Un video en un post');
    $r3 = jugada_ejecutar($pdo, $M, $T3);
    $C3 = (int)($r3['ids'][0] ?? 0);
    $p3 = $C3 > 0 ? $pdo->query("SELECT * FROM crecer_contenido WHERE id={$C3}")->fetch(PDO::FETCH_ASSOC) : [];
    ok('no se le mete el video al post',
       (int)($p3['material_activo_id'] ?? 0) !== $VIDEO, json_encode($p3['material_activo_id'] ?? null));
    ok('y la pieza queda pidiendo material',
       (string)($p3['necesita_material'] ?? '') === 'foto', json_encode($p3['necesita_material'] ?? null));

    echo "\n  — pero en un reel, sí —\n";
    $T4 = $jugada('reel', $VIDEO, '[prueba] Un reel con su clip');
    $r4 = jugada_ejecutar($pdo, $M, $T4);
    $C4 = (int)($r4['ids'][0] ?? 0);
    $p4 = $C4 > 0 ? $pdo->query("SELECT * FROM crecer_contenido WHERE id={$C4}")->fetch(PDO::FETCH_ASSOC) : [];
    ok('el reel usa su video',
       (int)($p4['material_activo_id'] ?? 0) === $VIDEO, json_encode($p4['material_activo_id'] ?? null));
    ok('y NO le pide que grabe otro',
       trim((string)($p4['necesita_material'] ?? '')) === '',
       'ya tenía el video: pedírselo otra vez es no mirar lo que subió');

    // ══════════════════════════════════════════════════════════════
    //  4 · EL CALENDARIO: NO SE PROGRAMA ENCIMA
    // ══════════════════════════════════════════════════════════════
    echo "\n  — no se programa encima de lo que ya tenía —\n";
    //  Una pieza SUYA, hecha a mano, justo en la hora que el motor elegiría.
    $sug = meta_fecha_sugerida($pdo, $M, 1, 1);
    ok('la fecha se pudo coordinar', !empty($sug['coordinada']),
       'sin poder leer el calendario no se puede afirmar que hay espacio');
    $pdo->prepare("INSERT INTO crecer_contenido
            (marca_id, plataforma, tipo, caption, estado, fecha_programada)
          VALUES (?, 'instagram','post','[prueba] La que él programó', 'programado', ?)")
        ->execute([$M, $sug['fecha']]);
    $SUYA = (int)$pdo->lastInsertId();

    $sug2 = meta_fecha_sugerida($pdo, $M, 1, 1);
    ok('la fecha se corre',        $sug2['fecha'] !== $sug['fecha'],
       $sug['fecha'] . ' → ' . $sug2['fecha']);
    ok('y se dice por qué',
       str_contains(mb_strtolower((string)$sug2['porque']), 'ya tenías algo programado'),
       (string)$sug2['porque']);
    ok('lo suyo no se movió',
       (string)$pdo->query("SELECT fecha_programada FROM crecer_contenido WHERE id={$SUYA}")->fetchColumn()
         === $sug['fecha'],
       'mover lo que el dueño programó sin preguntarle es decidir por él');
    $sep = abs(strtotime($sug2['fecha']) - strtotime($sug['fecha']));
    ok('con separación de verdad',  $sep >= 4 * 3600, (string)round($sep / 3600, 1) . 'h');

    // ══════════════════════════════════════════════════════════════
    //  5 · «LO QUE TOMÉ EN CUENTA» DICE LA VERDAD
    // ══════════════════════════════════════════════════════════════
    echo "\n  — y lo que se le cuenta al dueño es verdad —\n";
    $meta = meta_por_id($pdo, $META, $M);
    $plan = meta_plan_por_id($pdo, $PLAN, $M);
    $con  = ciclo_considerado($pdo, $M, $meta, $plan, 5);
    $todo = mb_strtolower(implode(' | ', $con['lineas']));
    ok('como máximo tres renglones', count($con['lineas']) <= 3, json_encode($con['lineas']));
    ok('dice que usó su material',
       str_contains($todo, 'biblioteca'), json_encode($con['lineas']));
    ok('y el detalle explica por qué', count($con['detalle']) >= 1, json_encode($con['detalle']));

    //  Y EN UNA MARCA QUE NO SUBIÓ NADA, esa línea NO puede salir.
    $fx2 = Fixture::crear($pdo, 'matB', true, 'admin');
    $limpiar[] = $M2 = (int)$fx2['marca_id'];
    $meta2 = meta_por_id($pdo, (int)$fx2['meta_id'], $M2);
    $plan2 = meta_plan_por_id($pdo, (int)$fx2['plan_id'], $M2);
    $con2  = ciclo_considerado($pdo, $M2, $meta2, $plan2, 1);
    ok('sin Biblioteca no se presume de ella',
       !str_contains(mb_strtolower(implode(' ', $con2['lineas'])), 'biblioteca'),
       json_encode($con2['lineas']));
    ok('sin cobertura no dice que miró resultados',
       !str_contains(mb_strtolower(implode(' ', $con2['lineas'])), 'publicaste'),
       json_encode($con2['lineas']));

} catch (Throwable $e) {
    $fallos++; $n++;
    echo "  FALLA excepción\n         → " . get_class($e) . ': ' . $e->getMessage()
       . "\n         → " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    foreach ($limpiar as $mid) { try { Fixture::limpiar($pdo, (int)$mid); } catch (Throwable $e) {} }
    echo "\n  (fixtures limpiadas)\n";
}

echo "\n  — el costo —\n";
//  EN DINERO, no en filas: `crecer_ia_log` recoge también decisiones por
//  reglas —sin modelo y sin costo— y contarlas como llamada hacía saltar esta
//  prueba por algo que no cuesta nada.
ok('cero gasto real',
   (float)$pdo->query("SELECT COALESCE(SUM(costo_usd),0) FROM crecer_ia_log
                        WHERE created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)")
       ->fetchColumn() < 0.000001);

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0 ? "  LA SEMANA USA SU MATERIAL · {$n} afirmaciones\n\n"
                   : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
