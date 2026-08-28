<?php
// ============================================================
//  CRECER — UN SOLO CEREBRO: EL CONTEXTO QUE COMPARTEN TODOS
//  tests/test_contexto_unico.php
//
//  LO QUE SE PRUEBA, Y POR QUE CADA COSA:
//
//  1 · NO SE MEZCLAN DOS NEGOCIOS. Es lo primero porque es lo unico que no
//      tiene arreglo: enseñarle a un cliente la foto, el calendario o los
//      numeros de otro no es un fallo, es el fin del producto. Se montan dos
//      marcas completas y se comprueba que ni un id, ni una foto, ni una
//      publicacion cruza.
//
//  2 · CADA FUENTE DICE EN QUE ESTADO ESTA. «Vacia» es «miré y no habia»;
//      «no disponible» es «no pude mirar». Confundirlas es lo que hace que un
//      producto diga «no tienes fotos» cuando en realidad se le cayo la
//      consulta — y el dueño, que SI tiene fotos, deja de creerle.
//
//  3 · UNA FUENTE CAIDA NO TUMBA LAS DEMAS, y no miente. Se le quita la tabla
//      de huellas de verdad —no se simula— y se comprueba que el contexto
//      sigue en pie y que el texto NO promete que evito repeticiones.
//
//  4 · EL RECORTE. No se le manda la base entera al modelo: ventanas, topes y
//      cero rutas de fichero.
//
//  CERO PROVEEDOR: el ensamblador no llama a ningun modelo. Se cuenta al final.
// ============================================================

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/contexto.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nUN SOLO CEREBRO · EL CONTEXTO\n" . str_repeat('=', 58) . "\n";

$ia0 = (int)$pdo->query("SELECT COUNT(*) FROM crecer_ia_log")->fetchColumn();
$limpiar = [];

try {
    // ══════════════════════════════════════════════════════════════
    //  DOS NEGOCIOS DE VERDAD, CADA UNO CON LO SUYO
    // ══════════════════════════════════════════════════════════════
    echo "\n  — dos negocios, cada uno con su material —\n";
    $A = Fixture::crear($pdo, 'ctxA', true, 'admin');
    $B = Fixture::crear($pdo, 'ctxB', true, 'admin');
    $limpiar[] = $MA = (int)$A['marca_id'];
    $limpiar[] = $MB = (int)$B['marca_id'];

    //  Biblioteca: una foto y un video para A, una foto para B.
    $act = $pdo->prepare("INSERT INTO crecer_activos
             (marca_id, tipo, archivo, nombre, nota, origen, estado)
           VALUES (?,?,?,?,?, 'subida', 'activo')");
    $act->execute([$MA, 'imagen', "marca_{$MA}/vitrina.jpg", 'La vitrina llena', 'sábado por la mañana']);
    $FOTO_A = (int)$pdo->lastInsertId();
    $act->execute([$MA, 'video',  "marca_{$MA}/mostrador.mp4", 'El mostrador', '']);
    $VIDEO_A = (int)$pdo->lastInsertId();
    $act->execute([$MB, 'imagen', "marca_{$MB}/corte.jpg", 'Un corte terminado', '']);
    $FOTO_B = (int)$pdo->lastInsertId();

    //  Una publicación MANUAL de A: sin meta, sin plan, sin jugada. Es suya y
    //  cuenta igual — el corillo tiene que aprender de ella.
    $pdo->prepare("INSERT INTO crecer_contenido
            (marca_id, plataforma, tipo, caption, estado, fecha_programada)
          VALUES (?, 'instagram', 'post', ?, 'programado', DATE_ADD(NOW(), INTERVAL 2 DAY))")
        ->execute([$MA, '[prueba] Bizcocho de guayaba que hice yo mismo']);
    $MANUAL_A = (int)$pdo->lastInsertId();

    $ctxA = ctx_estrategico($pdo, $MA);
    $ctxB = ctx_estrategico($pdo, $MB);

    // ── 1 · NI UN DATO CRUZA ──────────────────────────────────────
    echo "\n  — y ni un dato cruza de uno al otro —\n";
    $ids_A = array_column($ctxA['biblioteca']['activos'] ?? [], 'id');
    $ids_B = array_column($ctxB['biblioteca']['activos'] ?? [], 'id');
    ok('A ve su foto',            in_array($FOTO_A, $ids_A, true), json_encode($ids_A));
    ok('y su video',              in_array($VIDEO_A, $ids_A, true), json_encode($ids_A));
    ok('B ve la suya',            in_array($FOTO_B, $ids_B, true), json_encode($ids_B));
    ok('A NO ve la de B',         !in_array($FOTO_B, $ids_A, true),
       'enseñarle a un cliente el material de otro no es un fallo: es el fin del producto');
    ok('B NO ve las de A',        !in_array($FOTO_A, $ids_B, true) && !in_array($VIDEO_A, $ids_B, true));

    $txtB = ctx_para_prompt($ctxB);
    ok('el prompt de B no nombra a A',
       mb_strpos($txtB, (string)$FOTO_A) === false
       && mb_stripos($txtB, 'Bizcocho de guayaba') === false, mb_substr($txtB, 0, 200));

    $piezas_B = array_column($ctxB['historial_contenido']['piezas'] ?? [], 'id');
    ok('el historial de B no trae piezas de A', !in_array($MANUAL_A, $piezas_B, true),
       json_encode($piezas_B));

    // ── 2 · LO MANUAL ENTRA, Y SE SABE QUE ES SUYO ────────────────
    echo "\n  — lo que el dueño hizo a mano también cuenta —\n";
    $piezas_A = $ctxA['historial_contenido']['piezas'] ?? [];
    $manual = null;
    foreach ($piezas_A as $p) if ((int)$p['id'] === $MANUAL_A) $manual = $p;
    ok('la pieza manual está en el historial', $manual !== null, json_encode(array_column($piezas_A,'id')));
    ok('y se sabe que la hizo él',  ($manual['origen'] ?? '') === 'manual', json_encode($manual));
    ok('el contexto cuenta cuántas son suyas',
       (int)($ctxA['historial_contenido']['manuales'] ?? 0) >= 1);

    $calA = array_column($ctxA['calendario_proximo']['ocupados'] ?? [], 'id');
    ok('y ocupa sitio en el calendario', in_array($MANUAL_A, $calA, true), json_encode($calA));

    // ── 3 · LA BIBLIOTECA VIAJA CON SU ID ─────────────────────────
    echo "\n  — la Biblioteca viaja con id, o no se puede ejecutar —\n";
    $txtA = ctx_para_prompt($ctxA);
    ok('el prompt trae el número de la foto',
       mb_strpos($txtA, '#' . $FOTO_A) !== false,
       '«usa una foto del mostrador» no lo puede ejecutar nadie');
    ok('y lo que el dueño escribió de ella',
       mb_stripos($txtA, 'La vitrina llena') !== false);
    ok('NO viaja la ruta del fichero',
       mb_strpos($txtA, '.jpg') === false && mb_strpos($txtA, 'marca_') === false,
       'una ruta interna no le dice nada al modelo y sí dice de más');
    ok('se distingue el video de la foto',
       mb_strpos($txtA, '[video]') !== false && mb_strpos($txtA, '[imagen]') !== false, $txtA);

    // ── 4 · CADA SECCIÓN DICE EN QUÉ ESTADO ESTÁ ──────────────────
    echo "\n  — cada fuente dice si pudo mirarse —\n";
    $est = ctx_estados($ctxA);
    ok('están las once secciones',  count($est) === 11, json_encode(array_keys($est)));
    foreach ($est as $k => $v) {
        if (!in_array($v, [CTX_DISPONIBLE, CTX_VACIA, CTX_NO_DISP], true)) {
            ok("estado válido en {$k}", false, $v);
        }
    }
    ok('todos los estados son válidos', true);
    ok('el negocio se pudo leer',   $est['negocio'] === CTX_DISPONIBLE, $est['negocio']);
    ok('la Biblioteca también',     $est['biblioteca'] === CTX_DISPONIBLE, $est['biblioteca']);

    //  UNA MARCA SIN NADA: vacía no es lo mismo que rota.
    $C = Fixture::crear($pdo, 'ctxC', true, 'admin');
    $limpiar[] = $MC = (int)$C['marca_id'];
    $ctxC = ctx_estrategico($pdo, $MC);
    ok('sin fotos, la Biblioteca está VACÍA (no rota)',
       $ctxC['biblioteca']['estado'] === CTX_VACIA, $ctxC['biblioteca']['estado']);
    $txtC = ctx_para_prompt($ctxC);
    ok('y el prompt lo dice sin prometer nada',
       mb_stripos($txtC, 'No prometas usar material suyo') !== false, mb_substr($txtC, 0, 300));

    // ── 5 · SIN COBERTURA NO SE DECLARA UN GANADOR ────────────────
    echo "\n  — sin cobertura, no hay ganadores —\n";
    $pub = $pdo->prepare("INSERT INTO crecer_contenido
            (marca_id, plataforma, tipo, caption, estado, publicado_at)
          VALUES (?, 'instagram', ?, ?, 'publicado', DATE_SUB(NOW(), INTERVAL 3 DAY))");
    $pub->execute([$MA, 'post', '[prueba] Uno publicado sin números']);
    $P1 = (int)$pdo->lastInsertId();
    $ctxA2 = ctx_estrategico($pdo, $MA);
    ok('publicó pero nadie reportó: VACÍA',
       $ctxA2['resultados']['estado'] === CTX_VACIA, json_encode($ctxA2['resultados']));
    ok('y el prompt prohíbe inventar aprendizaje',
       mb_stripos(ctx_para_prompt($ctxA2), 'No inventes aprendizaje') !== false);

    //  Ahora CON números, pero solo una pieza: sigue sin dar para afirmar.
    $pdo->prepare("INSERT INTO crecer_metricas (contenido_id, marca_id, plataforma, alcance, interacciones)
                   VALUES (?,?, 'instagram', 900, 60)")->execute([$P1, $MA]);
    $ctxA3 = ctx_estrategico($pdo, $MA);
    ok('con una sola pieza medida NO es confiable',
       empty($ctxA3['resultados']['confiable']), json_encode($ctxA3['resultados']));
    ok('y se le dice al modelo que no declare ganadores',
       mb_stripos(ctx_para_prompt($ctxA3), 'NO declares ganadores') !== false);

    //  Con tres medidas y cobertura, ya se puede.
    foreach ([['carrusel', 2400, 310], ['post', 700, 40]] as $i => [$tp, $al, $it]) {
        $pub->execute([$MA, $tp, "[prueba] Publicado {$i} con números"]);
        $pid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO crecer_metricas (contenido_id, marca_id, plataforma, alcance, interacciones)
                       VALUES (?,?, 'instagram', ?, ?)")->execute([$pid, $MA, $al, $it]);
    }
    $ctxA4 = ctx_estrategico($pdo, $MA);
    ok('con tres medidas y cobertura, ya es confiable',
       !empty($ctxA4['resultados']['confiable']), json_encode($ctxA4['resultados']));
    ok('y sabe cuál movió más',
       (int)($ctxA4['resultados']['mejor']['interacciones'] ?? 0) === 310,
       json_encode($ctxA4['resultados']['mejor']));
    ok('sin confundir publicar mucho con cumplir la meta',
       mb_stripos(ctx_para_prompt($ctxA4), 'La que más movió') !== false);

    // ── 6 · EL COMENTARIO DEL DUEÑO NO SE BORRA CON NÚMEROS ───────
    echo "\n  — su percepción y los números conviven —\n";
    if (ciclo_hay_libro_ctx($pdo)) {
        $pdo->prepare("INSERT INTO crecer_meta_semana
                (marca_id, meta_id, plan_id, semana, estado, valoracion, comentario, cerrada_at)
              VALUES (?,?,?,1,'cerrada','peor','Los mensajes no fueron buenos.', NOW())")
            ->execute([$MA, (int)$A['meta_id'], (int)$A['plan_id']]);
        $ctxA5 = ctx_estrategico($pdo, $MA);
        ok('la voz del dueño llega',
           ($ctxA5['comentario_dueno']['estado'] ?? '') === CTX_DISPONIBLE,
           json_encode($ctxA5['comentario_dueno']));
        $t5 = ctx_para_prompt($ctxA5);
        ok('con lo que dijo, literal',
           mb_stripos($t5, 'Los mensajes no fueron buenos') !== false);
        ok('y se le dice que puede chocar con los números',
           mb_stripos($t5, 'no borres una con la otra') !== false, mb_substr($t5, -300));
    } else {
        ok('(saltado: falta el libro de semanas)', true);
        ok('(saltado)', true); ok('(saltado)', true);
    }

    // ── 7 · UNA FUENTE CAÍDA NO TUMBA NI MIENTE ───────────────────
    echo "
  — con una fuente caída: sigue en pie y no promete —
";
    //  SE LE QUITA LA TABLA DE VERDAD, pero en una COPIA desechable de la base.
    //  Simularlo probaría el simulacro; hacerlo contra la base compartida sería
    //  cambiarle la forma a la base de todos —un DROP no se deshace y hace
    //  COMMIT implícito—. La copia se tira al terminar.
    require_once __DIR__ . '/_esquema_desechable.php';
    $copia = EsquemaDesechable::crear($pdo);
    if ($copia === null) {
        echo "  (saltada: este usuario de base de datos no puede crear bases)
";
    } else {
        try {
            $cpdo = $copia->pdo();
            //  LA COPIA CLONA LA FORMA, NO LOS DATOS. Se siembra lo mínimo para
            //  que haya algo que leer: un negocio y una foto suya.
            $cpdo->prepare("INSERT INTO crecer_marca (id, usuario_id, nombre_negocio, descripcion)
                             VALUES (?,?,?,?)")
                 ->execute([$MA, 1, '[prueba] Copia desechable', 'Repostería de prueba']);
            $cpdo->prepare("INSERT INTO crecer_activos
                              (id, marca_id, tipo, archivo, nombre, nota, origen, estado)
                            VALUES (?,?, 'imagen', ?, 'La vitrina llena', '', 'subida', 'activo')")
                 ->execute([$FOTO_A, $MA, "marca_{$MA}/vitrina.jpg"]);

            $copia->ejecutar('DROP TABLE crecer_visual_huella');
            ctx_hay_columna($cpdo, 'crecer_visual_huella', 'concepto', true);

            $roto = ctx_estrategico($cpdo, $MA);
            ok('el historial visual dice que no se pudo mirar',
               $roto['historial_visual']['estado'] === CTX_NO_DISP,
               json_encode($roto['historial_visual']));
            ok('pero el negocio sigue',      $roto['negocio']['estado'] === CTX_DISPONIBLE,
               json_encode($roto['negocio']['estado']));
            ok('y la Biblioteca también',    $roto['biblioteca']['estado'] === CTX_DISPONIBLE,
               json_encode($roto['biblioteca']['estado']));
            $tr = ctx_para_prompt($roto);
            ok('el prompt NO promete que evitó repetirse',
               mb_stripos($tr, 'IMÁGENES QUE YA HIZO') === false,
               'sin memoria visual no se puede decir que se evitó repetir');
            ok('y aun así trae la Biblioteca',
               mb_strpos($tr, '#' . $FOTO_A) !== false, mb_substr($tr, 0, 300));
        } finally {
            $copia->soltar($pdo);
            ctx_hay_columna($pdo, 'crecer_visual_huella', 'concepto', true);
        }
        ok('la base de verdad ni se enteró',
           (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
                              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crecer_visual_huella'")
               ->fetchColumn() === 1);
    }

    // ── 8 · EL RECORTE ────────────────────────────────────────────
    echo "\n  — se manda lo que decide, no la base entera —\n";
    for ($i = 0; $i < 20; $i++) {
        $act->execute([$MA, 'imagen', "marca_{$MA}/relleno{$i}.jpg", "Relleno {$i}", '']);
    }
    $ctxA6 = ctx_estrategico($pdo, $MA);
    ok('la Biblioteca va topada',
       count($ctxA6['biblioteca']['activos']) <= CTX_TOPE_BIBLIOTECA,
       (string)count($ctxA6['biblioteca']['activos']));
    ok('el calendario mira dos semanas',
       (int)($ctxA6['calendario_proximo']['dias'] ?? 0) === CTX_DIAS_CALENDARIO);
    ok('los resultados miran 30 días',
       (int)($ctxA6['resultados']['dias'] ?? 0) === CTX_DIAS_RESULTADOS);
    $largo = mb_strlen(ctx_para_prompt($ctxA6));
    ok('y el texto cabe en un prompt sano', $largo > 200 && $largo < 12000, (string)$largo);

} catch (Throwable $e) {
    $fallos++; $n++;
    echo "  FALLA excepción\n         → " . get_class($e) . ': ' . $e->getMessage() . "\n";
} finally {
    foreach ($limpiar as $mid) {
        try { Fixture::limpiar($pdo, (int)$mid); } catch (Throwable $e) {}
    }
    echo "\n  (fixtures limpiadas)\n";
}

// ── EL COSTO ──────────────────────────────────────────────────
echo "\n  — el costo —\n";
$ia1 = (int)$pdo->query("SELECT COUNT(*) FROM crecer_ia_log")->fetchColumn();
ok('ensamblar contexto NO llama a ningún modelo', $ia1 === $ia0,
   "antes {$ia0} · ahora {$ia1} — si costara, nadie lo llamaría tres veces");

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0 ? "  UN SOLO CEREBRO · {$n} afirmaciones\n\n"
                   : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);

/** ¿Está el libro de semanas? La prueba no puede depender de una migración. */
function ciclo_hay_libro_ctx(PDO $pdo): bool {
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
                                  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crecer_meta_semana'")
            ->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}
