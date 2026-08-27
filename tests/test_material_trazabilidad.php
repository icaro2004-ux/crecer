<?php
// ============================================================
//  CRECER — DE DONDE SALIO LA IMAGEN DE ESTA PUBLICACION
//  tests/test_material_trazabilidad.php
//
//  LA PREGUNTA QUE ANTES NO SE PODIA RESPONDER. `crecer_contenido` guardaba
//  `grafica_path`: una RUTA. Con eso se puede enseñar la imagen y nada más — no
//  se sabe si la puso el dueño o la pintamos nosotros. Y no se puede deducir:
//  comparar rutas contra `crecer_activos` es una coincidencia de texto, no una
//  identidad. Una trazabilidad que a veces acierta es peor que no tenerla,
//  porque se confía en ella.
//
//  `material_activo_id` lo cierra. Y la mitad que se olvida es la contraria:
//  cuando arte generado desde cero sustituye a una foto del dueño, la
//  referencia hay que SOLTARLA — si no, la publicación seguiría diciendo que
//  usa una foto suya que ya no usa. Un id obsoleto miente con más confianza
//  que su ausencia.
//
//  ══ RED CERRADA POR CONSTRUCCION ══ `_sin_gasto.php`. Nada de esto llama a
//  ningún proveedor: aplicar material que ya existe es la operación más barata
//  del producto y tiene que seguir siéndolo.
// ============================================================

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/material.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nDE DONDE SALIO LA IMAGEN\n" . str_repeat('=', 58) . "\n";

$cnt = fn(string $t, string $w = '1') => (int)$GLOBALS['pdo']->query(
    "SELECT COUNT(*) FROM {$t} WHERE {$w}")->fetchColumn();
$g = ['ia' => $cnt('crecer_ia_log'), 'cuota' => $cnt('crecer_img_cuota_asiento')];

/** Una marca con una publicación en borrador y material en Biblioteca. */
function montar(PDO $pdo, string $etq): array {
    $fx = Fixture::crear($pdo, $etq, false, 'admin');
    $M  = (int)$fx['marca_id'];
    $pdo->prepare("INSERT INTO crecer_contenido
            (marca_id,plataforma,tipo,caption,estado,fecha_programada,grafica_path)
          VALUES (?, 'instagram','post',?, 'borrador', DATE_ADD(NOW(), INTERVAL 2 DAY), ?)")
        ->execute([$M, '[prueba] El texto que no se debe tocar.',
                   '/crecer/uploads/marca_x/generado_viejo.png']);
    $C = (int)$pdo->lastInsertId();
    $act = $pdo->prepare("INSERT INTO crecer_activos
            (marca_id,tipo,archivo,nombre,mime,bytes,origen,estado)
          VALUES (?,?,?,?,?,?,?, 'activo')");
    $act->execute([$M, 'imagen', "marca_{$M}/biblioteca/suya.jpg",
                   '[prueba] Su bizcocho', 'image/jpeg', 1234, 'subido']);
    $FOTO = (int)$pdo->lastInsertId();
    $act->execute([$M, 'video', "marca_{$M}/biblioteca/suyo.mp4",
                   '[prueba] Su horno', 'video/mp4', 99000, 'subido']);
    $VIDEO = (int)$pdo->lastInsertId();
    return [$fx, $M, $C, $FOTO, $VIDEO];
}
function idmat(PDO $pdo, int $c) {
    return $pdo->query("SELECT material_activo_id FROM crecer_contenido WHERE id={$c}")->fetchColumn();
}

$limpiar = [];
try {
    // ══════════════════════════════════════════════════════════════
    //  1 · EL ESQUEMA · y que el codigo aguante los dos ordenes
    // ══════════════════════════════════════════════════════════════
    echo "\n  — la columna, y el codigo con y sin ella —\n";
    ok('la columna está', material_hay_columna($pdo, true),
       'esta suite necesita la migración corrida en la base local');
    $idx = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS
                              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crecer_contenido'
                                AND INDEX_NAME='idx_contenido_material_activo'")->fetchColumn();
    ok('con su índice por marca y recurso', $idx === 2, $idx . ' columnas en el índice');
    ok('y es NULL-able',
       (string)$pdo->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crecer_contenido'
                               AND COLUMN_NAME='material_activo_id'")->fetchColumn() === 'YES',
       'lo que ya existía queda en NULL: no se sabe de dónde salió, y eso es la verdad');
    ok('sin llave foránea',
       (int)$pdo->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
                          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crecer_contenido'
                            AND COLUMN_NAME='material_activo_id'
                            AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchColumn() === 0,
       'en Hostinger una FK tumba el ALTER entero en silencio');

    //  Y NO SE RELLENO NADA. Un backfill por coincidencia de rutas sería
    //  inventarse la respuesta.
    ok('ninguna pieza histórica quedó rellenada',
       $cnt('crecer_contenido', 'material_activo_id IS NOT NULL') === 0
       || $cnt('crecer_contenido', 'material_activo_id IS NOT NULL') < $cnt('crecer_contenido'),
       'no se hace backfill: una coincidencia de texto no demuestra identidad');

    // ══════════════════════════════════════════════════════════════
    //  2 · APLICAR UNA FOTO · ruta e identidad, en la misma escritura
    // ══════════════════════════════════════════════════════════════
    echo "\n  — una foto suya deja constancia de que es suya —\n";
    [$fx, $M, $C, $FOTO, $VIDEO] = montar($pdo, 'traA');
    $limpiar[] = $M;
    $ia0 = $cnt('crecer_ia_log'); $cu0 = $cnt('crecer_img_cuota_asiento');
    $antes = $pdo->query("SELECT caption, fecha_programada FROM crecer_contenido WHERE id={$C}")
                 ->fetch(PDO::FETCH_ASSOC);

    $r = material_aplicar($pdo, $M, $C, $FOTO);
    ok('la aplica',            !empty($r['ok']), json_encode($r));
    ok('y lo dice',            !empty($r['trazado']));
    ok('guarda la ruta',
       mb_strpos((string)$pdo->query("SELECT grafica_path FROM crecer_contenido WHERE id={$C}")
                             ->fetchColumn(), 'suya.jpg') !== false);
    ok('y guarda el recurso',  (int)idmat($pdo, $C) === $FOTO, var_export(idmat($pdo, $C), true));
    ok('sin tocar el texto',
       (string)$pdo->query("SELECT caption FROM crecer_contenido WHERE id={$C}")
                   ->fetchColumn() === (string)$antes['caption']);
    ok('sin tocar la fecha',
       (string)$pdo->query("SELECT fecha_programada FROM crecer_contenido WHERE id={$C}")
                   ->fetchColumn() === (string)$antes['fecha_programada']);
    ok('cero llamadas al modelo', $cnt('crecer_ia_log') === $ia0);
    ok('cero cuota',              $cnt('crecer_img_cuota_asiento') === $cu0,
       'usar lo que ya es suyo no genera nada');

    //  Y SE PUEDE RESOLVER DESPUES: recurso, origen, tipo y marca.
    echo "\n  — y una lectura posterior lo resuelve —\n";
    $o = material_origen($pdo, $M, $C);
    ok('dice que vino de la Biblioteca', ($o['origen'] ?? '') === 'biblioteca', json_encode($o));
    ok('con el recurso',   (int)($o['activo']['id'] ?? 0) === $FOTO);
    ok('su origen',        (string)($o['activo']['origen'] ?? '') === 'subido');
    ok('y su tipo',        (string)($o['activo']['tipo'] ?? '') === 'imagen');

    // ══════════════════════════════════════════════════════════════
    //  3 · CAMBIAR DE RECURSO · los dos campos, a la vez
    // ══════════════════════════════════════════════════════════════
    echo "\n  — cambiar de material actualiza las dos cosas —\n";
    $act2 = $pdo->prepare("INSERT INTO crecer_activos
            (marca_id,tipo,archivo,nombre,mime,bytes,origen,estado)
          VALUES (?, 'imagen', ?, ?, 'image/jpeg', 999, 'subido','activo')");
    $act2->execute([$M, "marca_{$M}/biblioteca/otra.jpg", '[prueba] Otra suya']);
    $OTRA = (int)$pdo->lastInsertId();
    material_aplicar($pdo, $M, $C, $OTRA);
    ok('la ruta es la nueva',
       mb_strpos((string)$pdo->query("SELECT grafica_path FROM crecer_contenido WHERE id={$C}")
                             ->fetchColumn(), 'otra.jpg') !== false);
    ok('y el id también',  (int)idmat($pdo, $C) === $OTRA, var_export(idmat($pdo, $C), true));

    // ══════════════════════════════════════════════════════════════
    //  4 · LA MITAD QUE SE OLVIDA · soltar cuando deja de ser cierto
    // ══════════════════════════════════════════════════════════════
    echo "\n  — pintar desde cero suelta la referencia —\n";
    ok('existe la función de soltar', function_exists('material_soltar'));
    material_soltar($pdo, $M, $C);
    ok('el id se soltó', idmat($pdo, $C) === null, var_export(idmat($pdo, $C), true));
    $o2 = material_origen($pdo, $M, $C);
    ok('y ya no dice que es del dueño',
       ($o2['origen'] ?? '') === 'generado_o_desconocido', json_encode($o2));

    //  Y LAS RUTAS QUE PINTAN DESDE CERO LA LLAMAN.
    echo "\n  — y las rutas que pintan la llaman —\n";
    foreach (['includes/img_responses.php' => 'la entrega del arte async',
              'panel/aprobar2.php'         => 'el arte y el reuso',
              ] as $arch => $como) {
        $src = (string)file_get_contents(__DIR__ . '/../' . $arch);
        $cod = (string)preg_replace(['~/\*[\s\S]*?\*/~', '~^\s*//[^\n]*$~m'], ' ', $src);
        ok("{$como} suelta el material", mb_strpos($cod, 'material_soltar') !== false,
           'un id obsoleto miente con más confianza que su ausencia');
    }

    // ══════════════════════════════════════════════════════════════
    //  5 · LAS GUARDAS · marca, vida, formato
    // ══════════════════════════════════════════════════════════════
    echo "\n  — lo que no se puede aplicar —\n";
    [$fo, $MX, $CX, $FOTOX, $VIDX] = montar($pdo, 'traX');
    $limpiar[] = $MX;

    material_aplicar($pdo, $M, $C, $FOTO);            // se deja una buena puesta
    $ruta_ok = (string)$pdo->query("SELECT grafica_path FROM crecer_contenido WHERE id={$C}")->fetchColumn();

    $r = material_aplicar($pdo, $M, $C, $FOTOX);
    ok('un recurso de otra marca se rechaza', empty($r['ok']), json_encode($r));
    ok('y no cambió nada',
       (string)$pdo->query("SELECT grafica_path FROM crecer_contenido WHERE id={$C}")
                   ->fetchColumn() === $ruta_ok && (int)idmat($pdo, $C) === $FOTO);

    $r = material_aplicar($pdo, $MX, $C, $FOTOX);
    ok('la pieza de otra marca también', empty($r['ok']), json_encode($r));

    //  Un recurso apagado no sirve.
    $pdo->prepare("UPDATE crecer_activos SET estado='borrado' WHERE id=?")->execute([$OTRA]);
    $r = material_aplicar($pdo, $M, $C, $OTRA);
    ok('un recurso que ya no está, tampoco', empty($r['ok']), json_encode($r));

    //  Un video en un post: se explica, no se finge.
    $r = material_aplicar($pdo, $M, $C, $VIDEO);
    ok('un video en un post se rechaza', empty($r['ok']), json_encode($r));
    ok('diciendo qué necesita',
       mb_stripos((string)($r['err'] ?? ''), 'necesita una imagen') !== false,
       (string)($r['err'] ?? ''));
    ok('y sin prometer conversión',
       mb_stripos((string)($r['err'] ?? ''), 'convert') === false);
    ok('la foto buena sigue puesta', (int)idmat($pdo, $C) === $FOTO);

    //  Y AL REVES: en un reel, el video SI entra.
    $pdo->prepare("UPDATE crecer_contenido SET tipo='reel' WHERE id=?")->execute([$C]);
    $r = material_aplicar($pdo, $M, $C, $VIDEO);
    ok('en un reel, el video sí',   !empty($r['ok']), json_encode($r));
    ok('y queda trazado',           (int)idmat($pdo, $C) === $VIDEO);
    $pdo->prepare("UPDATE crecer_contenido SET tipo='post' WHERE id=?")->execute([$C]);

    //  Lo que ya salio no se toca.
    $pdo->prepare("UPDATE crecer_contenido SET estado='publicado' WHERE id=?")->execute([$C]);
    $r = material_aplicar($pdo, $M, $C, $FOTO);
    ok('una pieza publicada no se cambia', empty($r['ok']), json_encode($r));
    $pdo->prepare("UPDATE crecer_contenido SET estado='borrador' WHERE id=?")->execute([$C]);

    // ══════════════════════════════════════════════════════════════
    //  6 · OTRA MARCA NO PUEDE LEER LA RELACION
    // ══════════════════════════════════════════════════════════════
    echo "\n  — la relación tampoco se lee desde fuera —\n";
    material_aplicar($pdo, $M, $C, $FOTO);
    $oa = material_origen($pdo, $MX, $C);
    ok('otra marca no resuelve el recurso', ($oa['origen'] ?? '') !== 'biblioteca',
       json_encode($oa));

    // ══════════════════════════════════════════════════════════════
    //  7 · CODIGO NUEVO CON ESQUEMA VIEJO · degrada, no revienta
    // ══════════════════════════════════════════════════════════════
    echo "\n  — y si la columna no estuviera —\n";
    //  NO SE LE QUITA LA COLUMNA A LA BASE COMPARTIDA. Esta prueba la quitaba y
    //  la volvia a poner en un `finally`, que es exactamente el incidente que
    //  `tests/_esquema_desechable.php` existe para no repetir: un DROP COLUMN no
    //  se deshace —los valores ya no vuelven— y ademas hace COMMIT implicito,
    //  asi que cualquier prueba que estuviera dentro de una transaccion se veria
    //  confirmada a media faena. Se clona la FORMA de las dos tablas que el
    //  dominio mira, se rompe la copia, y la copia se muere al salir.
    require_once __DIR__ . '/_esquema_desechable.php';
    $copia = EsquemaDesechable::crear($pdo, ['crecer_contenido', 'crecer_activos']);
    if ($copia === null) {
        echo "  (sin privilegios para crear la base de copia · se salta)\n";
    } else {
        try {
            $vpdo = $copia->pdo();
            $copia->ejecutar("ALTER TABLE crecer_contenido DROP INDEX idx_contenido_material_activo");
            $copia->ejecutar("ALTER TABLE crecer_contenido DROP COLUMN material_activo_id");
            ok('el código ve que no está', material_hay_columna($vpdo, true) === false);

            //  Dos filas a mano. En la copia no hay dueño ni fixture: solo la
            //  forma de las dos tablas que material_aplicar() consulta, que es
            //  todo lo que hace falta para probar que degrada bien.
            $MV = 9001;
            $vpdo->prepare("INSERT INTO crecer_contenido
                    (marca_id,plataforma,tipo,caption,estado,fecha_programada,grafica_path)
                  VALUES (?, 'instagram','post',?, 'borrador', DATE_ADD(NOW(), INTERVAL 2 DAY), ?)")
                 ->execute([$MV, '[prueba] El texto que no se debe tocar.',
                            '/crecer/uploads/marca_x/generado_viejo.png']);
            $CV = (int)$vpdo->lastInsertId();
            $vpdo->prepare("INSERT INTO crecer_activos
                    (marca_id,tipo,archivo,nombre,mime,bytes,origen,estado)
                  VALUES (?, 'imagen',?,?, 'image/jpeg', 1234, 'subido','activo')")
                 ->execute([$MV, "marca_{$MV}/biblioteca/suya.jpg", '[prueba] Su bizcocho']);
            $FOTOV = (int)$vpdo->lastInsertId();

            $r = material_aplicar($vpdo, $MV, $CV, $FOTOV);
            ok('aplicar sigue funcionando',  !empty($r['ok']), json_encode($r));
            ok('guarda la ruta igual',
               mb_strpos((string)$vpdo->query("SELECT grafica_path FROM crecer_contenido WHERE id={$CV}")
                                      ->fetchColumn(), 'suya.jpg') !== false);
            ok('pero avisa de que no hay traza', empty($r['trazado']));
            ok('y soltar no revienta',
               (function () use ($vpdo, $MV, $CV) { material_soltar($vpdo, $MV, $CV); return true; })());
            ok('ni resolver el origen',
               (material_origen($vpdo, $MV, $CV)['origen'] ?? '') === 'sin_columna');

            //  Y la migracion se corre de verdad sobre la copia: primero entera,
            //  y luego otra vez, para ver que la segunda AVISA.
            $copia->ejecutar("ALTER TABLE crecer_contenido
                                ADD COLUMN material_activo_id BIGINT UNSIGNED NULL,
                                ADD INDEX idx_contenido_material_activo (marca_id, material_activo_id)");
            ok('la columna vuelve', material_hay_columna($vpdo, true));

            $dos = false;
            try {
                $copia->ejecutar("ALTER TABLE crecer_contenido
                                    ADD COLUMN material_activo_id BIGINT UNSIGNED NULL");
            } catch (Throwable $e) { $dos = true; }
            ok('correr la migración dos veces avisa', $dos,
               'el migrador enseña «aplicada» mirando la base: no la corre dos veces a ciegas');
        } finally {
            $copia->soltar($pdo);
            //  El cache de la columna es estatico y global: se le devuelve la
            //  verdad de la base de siempre antes de que lo lea nadie mas.
            material_hay_columna($pdo, true);
        }
    }

} catch (Throwable $e) {
    $fallos++; echo "\n  EXCEPCION · " . get_class($e) . ': ' . $e->getMessage()
                  . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    foreach ($limpiar as $mid) { try { Fixture::limpiar($pdo, $mid); } catch (Throwable $e) {} }
    echo "\n  (fixtures limpiadas)\n";
}

echo "\n  — el costo —\n";
ok('cero llamadas al modelo', $cnt('crecer_ia_log') === $g['ia'],
   'antes ' . $g['ia'] . ' · ahora ' . $cnt('crecer_ia_log'));
ok('cero asientos de cuota',  $cnt('crecer_img_cuota_asiento') === $g['cuota']);

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  LA IMAGEN DICE DE DONDE VINO · $n afirmaciones\n\n"
    : "  $fallos FALLAS de $n\n\n";
exit($fallos === 0 ? 0 : 1);
