<?php
// ============================================================
//  CRECER — LOS CINCO ESTADOS DE «OTRA IMAGEN», EN PANTALLA
//  tests/test_candidata_estados.php
//
//  POR QUE ESTA SUITE EXISTE APARTE. Cada estado —elegir, preparando, comparar,
//  fallo, cuota agotada— nace de datos DISTINTOS. No se ven los cinco en un solo
//  recorrido sin tocar la base a mitad, y tocarla desde el navegador seria
//  FABRICAR la pantalla en vez de encontrarla. Aqui se siembra el estado en la
//  base, se abre el navegador de cero, y la pantalla tiene que salir sola.
//
//  Eso es tambien la prueba de que RECARGAR reconstruye: la sonda entra por la
//  URL cada vez y no lleva nada guardado del paso anterior.
//
//  CERO PROVEEDOR, ESTRUCTURALMENTE. Ninguno de los cinco estados necesita
//  generar: el que ya se genero se siembra como lo deja el worker, y el que
//  falla se siembra fallido. El unico camino que llamaria —escoger «otra
//  version»— se fotografia ANTES de escoger, que es justo el estado que
//  interesa. Se cuentan las llamadas al final para poder afirmarlo.
// ============================================================

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/candidata.php';
require_once __DIR__ . '/../includes/cuota_imagenes.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nLOS CINCO ESTADOS DE «OTRA IMAGEN»\n" . str_repeat('=', 58) . "\n";

$cnt = fn(string $t, string $w = '1') => (int)$GLOBALS['pdo']->query(
    "SELECT COUNT(*) FROM {$t} WHERE {$w}")->fetchColumn();
$g0 = ['ia' => $cnt('crecer_ia_log'), 'as' => $cnt('crecer_img_cuota_asiento')];

$ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
if (@file_get_contents('http://localhost/crecer/login.php', false, $ctx) === false) {
    echo "\n  SALTADA · el servidor local no responde\n\n"; exit(0);
}
if (!cand_hay_columnas($pdo, true)) {
    echo "\n  SALTADA · falta migrations/2026-08-26_crecer_generacion_decision.sql\n\n"; exit(0);
}
$SONDA = __DIR__ . DIRECTORY_SEPARATOR . '_cand_estados.mjs';
if (!is_file($SONDA)) { echo "\n  SALTADA · falta la sonda\n\n"; exit(0); }

$SHOTS = __DIR__ . DIRECTORY_SEPARATOR . '_capturas' . DIRECTORY_SEPARATOR . 'candidata';
@mkdir($SHOTS, 0775, true);

$limpiar = [];
try {
    $fx = Fixture::crear($pdo, 'cest', true, 'admin');
    $limpiar[] = $M = (int)$fx['marca_id'];
    $sid = 'ce' . bin2hex(random_bytes(7));
    file_put_contents((session_save_path() ?: sys_get_temp_dir())
                      . DIRECTORY_SEPARATOR . 'sess_' . $sid,
                      'usuario_id|i:' . (int)$fx['usuario_id'] . ';');

    //  LA PIEZA. Se usa la primera del plan y se le pone una imagen de verdad en
    //  disco: la comparacion no dice nada si no hay una imagen que comparar.
    $P = (int)($fx['piezas'][0] ?? 0);
    ok('la fixture trae una pieza del plan', $P > 0, json_encode($fx['piezas'] ?? []));
    if ($P <= 0) throw new RuntimeException('sin pieza no hay pantalla que mirar');

    $mk = function (string $nombre) use ($M): string {
        $rel = "marca_{$M}/graficas/{$nombre}.png";
        $abs = rtrim(UPLOADS_PATH, '/\\') . DIRECTORY_SEPARATOR
             . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        @mkdir(dirname($abs), 0775, true);
        file_put_contents($abs, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        return rtrim(UPLOADS_URL, '/') . '/' . $rel;
    };
    $ACTUAL = $mk('actual_estado');
    $NUEVA  = $mk('candidata_estado');
    $pdo->prepare("UPDATE crecer_contenido SET grafica_path=?, tipo='post', estado='borrador',
                          fecha_programada = DATE_ADD(NOW(), INTERVAL 2 DAY)
                    WHERE id=? AND marca_id=?")->execute([$ACTUAL, $P, $M]);

    //  LA SONDA BUSCA LA TARJETA POR ID, no por posición: el orden de las
    //  jugadas del plan cambia con la fixture, y atarse a `pos` hacía que
    //  abriera la publicación equivocada.

    /** Deja la base en UN estado y lo abre en el navegador. */
    $correr = function (string $estado) use ($SONDA, $sid, $M, $SHOTS, $P): array {
        $cmd = 'node ' . escapeshellarg($SONDA) . ' ' . escapeshellarg($sid) . ' ' . $M
             . ' ' . escapeshellarg($SHOTS) . ' ' . escapeshellarg($estado) . ' ' . $P . ' 2>&1';
        $sal = []; exec($cmd, $sal);
        $r = [];
        foreach ($sal as $l) if (strpos($l, '=') !== false) { [$k, $v] = explode('=', trim($l), 2); $r[$k] = $v; }
        $r['__crudo'] = implode(' | ', array_slice($sal, -3));
        return $r;
    };
    $sembrar = function (?string $estado, ?string $archivo) use ($pdo, $M, $P) {
        $pdo->prepare("DELETE FROM crecer_generaciones WHERE marca_id=? AND contenido_id=?")
            ->execute([$M, $P]);
        if ($estado === null) return 0;
        $pdo->prepare("INSERT INTO crecer_generaciones
                (marca_id, contenido_id, estado, decision_dueno, copy_text, prompt_narrativo, archivo)
              VALUES (?,?,?, NULL, '[prueba]', '[prueba] instrucción guardada', ?)")
            ->execute([$M, $P, $estado, $archivo]);
        return (int)$pdo->lastInsertId();
    };
    /** Las tres medidas, con su vara. */
    $medir = function (array $r, string $etq) use (&$fallos, &$n) {
        foreach (['360', '414', '1440'] as $w) {
            $m = json_decode((string)($r['MED_' . $w] ?? '{}'), true) ?: [];
            ok("{$etq} a {$w} · cero scroll horizontal",
               (int)($m['horiz'] ?? 0) === 0 && empty($m['fuera']),
               json_encode(array_slice((array)($m['fuera'] ?? []), 0, 3)));
            ok("{$etq} a {$w} · nada que se toque bajo 44x44",
               empty($m['chicos']), json_encode(array_slice((array)($m['chicos'] ?? []), 0, 3)));
            ok("{$etq} a {$w} · nada que se lea bajo 14px",
               empty($m['finos']), json_encode(array_slice((array)($m['finos'] ?? []), 0, 3)));
            ok("{$etq} a {$w} · una sola decisión principal",
               (int)($m['primarias'] ?? 0) <= 1, (string)($m['primarias'] ?? '?'));
            if ((int)($m['primarias'] ?? 0) === 1) {
                ok("{$etq} a {$w} · y se ve sin buscarla", ($m['primVisible'] ?? null) === true,
                   'acaba en ' . ($m['primBottom'] ?? '?') . 'px y el techo está en '
                   . ($m['primTecho'] ?? '?') . 'px');
                ok("{$etq} a {$w} · sin nada encima",      ($m['primTapada'] ?? null) === false);
            }
            ok("{$etq} a {$w} · Ayuda no se sienta encima", ($m['ayudaTapa'] ?? false) === false);
            ok("{$etq} a {$w} · cero emoji",                empty($m['emo']),
               json_encode($m['emo'] ?? []));
        }
    };

    // ══════════════════════════════════════════════════════════════
    //  1 · ELECCIÓN · «misma idea» / «una idea visual diferente»
    // ══════════════════════════════════════════════════════════════
    echo "\n  ══ elección ══\n";
    $sembrar(null, null);
    $r = $correr('eleccion');
    ok('el navegador llegó',     ($r['OK'] ?? '0') === '1', $r['__crudo'] ?? '');
    ok('con la fila de generar', ($r['FILA_EXISTE'] ?? '') === 'true');
    ok('abre «¿Qué quieres cambiar?»', ($r['TITULO'] ?? '') === '¿Qué quieres cambiar?', $r['TITULO'] ?? '');
    ok('con las dos opciones',
       str_contains((string)($r['CUERPO'] ?? ''), 'Mantendré el concepto')
       && str_contains((string)($r['CUERPO'] ?? ''), 'otro concepto para comunicar'),
       $r['CUERPO'] ?? '');
    ok('y dice lo que cuesta ANTES',
       str_contains((string)($r['CUERPO'] ?? ''), 'usa 1 imagen de tu cuota'));
    ok('sin sacarme de la semana', ($r['SIGO_EN_SEMANA'] ?? '') === 'true');
    ok('Escape cierra sin escribir', ($r['ESCAPE_CIERRA'] ?? '') === 'true');
    ok('y sigo en la misma publicación', ($r['ESCAPE_SIGO_EN_POS'] ?? '') !== '');
    ok('consola limpia',          in_array((string)($r['CONSOLA'] ?? ''), ['[]', ''], true),
       $r['CONSOLA'] ?? '');
    ok('y alert() sigue siendo el nativo (nadie lo usa)',
       ($r['ALERTS'] ?? '') === 'nativo');
    $medir($r, 'elección');
    ok('Escape no escribió nada',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_generaciones
                          WHERE marca_id={$M} AND contenido_id={$P}")->fetchColumn() === 0,
       'cancelar una hoja no puede abrir una intención');

    // ══════════════════════════════════════════════════════════════
    //  2 · PREPARANDO
    // ══════════════════════════════════════════════════════════════
    echo "\n  ══ preparando ══\n";
    $sembrar('generating', null);
    $r = $correr('preparando');
    ok('el navegador llegó',      ($r['OK'] ?? '0') === '1', $r['__crudo'] ?? '');
    ok('la fila lleva a la que está en marcha', ($r['FILA_EXISTE'] ?? '') === 'true');
    ok('dice que está preparando',
       str_contains((string)($r['TITULO'] ?? ''), 'preparando'), $r['TITULO'] ?? '');
    ok('y que puede salir',
       str_contains((string)($r['CUERPO'] ?? ''), 'Puedes salir'), $r['CUERPO'] ?? '');
    ok('LA SUYA SIGUE VISIBLE',   ($r['IMAGEN_ACTUAL_VISIBLE'] ?? '') === 'true',
       'mientras se cocina lo otro no se le quita nada');
    ok('consola limpia',          in_array((string)($r['CONSOLA'] ?? ''), ['[]', ''], true),
       $r['CONSOLA'] ?? '');
    $medir($r, 'preparando');

    // ══════════════════════════════════════════════════════════════
    //  3 · COMPARACIÓN
    // ══════════════════════════════════════════════════════════════
    echo "\n  ══ comparación ══\n";
    $G = $sembrar('completed', $NUEVA);
    $r = $correr('comparacion');
    ok('el navegador llegó',      ($r['OK'] ?? '0') === '1', $r['__crudo'] ?? '');
    ok('abre la comparación',     ($r['TITULO'] ?? '') === '¿Cuál te gusta más?', $r['TITULO'] ?? '');
    ok('con las dos imágenes',    ($r['IMAGEN_ACTUAL_VISIBLE'] ?? '') === 'true');
    ok('y dice cuál es cuál',
       str_contains((string)($r['CUERPO'] ?? ''), 'La que tienes')
       && str_contains((string)($r['CUERPO'] ?? ''), 'La nueva opción'), $r['CUERPO'] ?? '');
    ok('una sola primaria',       (int)($r['PRIMARIAS'] ?? 0) === 1);
    ok('consola limpia',          in_array((string)($r['CONSOLA'] ?? ''), ['[]', ''], true),
       $r['CONSOLA'] ?? '');
    $medir($r, 'comparación');
    //  Y ESCAPE NO DECIDE POR ÉL.
    ok('Escape no decidió nada',
       $pdo->query("SELECT decision_dueno FROM crecer_generaciones WHERE id={$G}")
           ->fetchColumn() === null,
       'cerrar la hoja no es escoger');
    ok('ni tocó la publicación',
       (string)$pdo->query("SELECT grafica_path FROM crecer_contenido WHERE id={$P}")
                   ->fetchColumn() === $ACTUAL);

    // ══════════════════════════════════════════════════════════════
    //  4 · FALLO · con salida, y sin perder la suya
    // ══════════════════════════════════════════════════════════════
    echo "\n  ══ fallo ══\n";
    $pdo->prepare("DELETE FROM crecer_generaciones WHERE marca_id=? AND contenido_id=?")
        ->execute([$M, $P]);
    $pdo->prepare("INSERT INTO crecer_generaciones
            (marca_id, contenido_id, estado, decision_dueno, copy_text, prompt_narrativo,
             error_msg, http_status)
          VALUES (?,?, 'failed', NULL, '[prueba]', '[prueba] instrucción',
                  'imagen: Your credit balance is too low.', 400)")
        ->execute([$M, $P]);
    $r = $correr('fallo');
    ok('el navegador llegó',      ($r['OK'] ?? '0') === '1', $r['__crudo'] ?? '');
    ok('lo dice sin rodeos',
       str_contains((string)($r['TITULO'] ?? ''), 'No pude preparar'), $r['TITULO'] ?? '');
    ok('y que su imagen sigue',
       str_contains((string)($r['CUERPO'] ?? ''), 'sigue como estaba'), $r['CUERPO'] ?? '');
    ok('NO ES UN CALLEJÓN: hay por dónde salir',
       str_contains((string)($r['CUERPO'] ?? ''), 'Intentar otra vez')
       && str_contains((string)($r['CUERPO'] ?? ''), 'Biblioteca')
       && str_contains((string)($r['CUERPO'] ?? ''), 'Quedarme con la actual'),
       $r['CUERPO'] ?? '');
    ok('NO se enseña el mensaje del proveedor',
       !str_contains((string)($r['CUERPO'] ?? ''), 'credit balance')
       && !str_contains((string)($r['CUERPO'] ?? ''), 'Your credit'),
       'el error crudo no le dice nada al dueño y sí dice de más');
    ok('la suya sigue visible',   ($r['IMAGEN_ACTUAL_VISIBLE'] ?? '') === 'true');
    ok('consola limpia',          in_array((string)($r['CONSOLA'] ?? ''), ['[]', ''], true),
       $r['CONSOLA'] ?? '');
    $medir($r, 'fallo');
    ok('y la publicación no cambió',
       (string)$pdo->query("SELECT grafica_path FROM crecer_contenido WHERE id={$P}")
                   ->fetchColumn() === $ACTUAL);

    // ══════════════════════════════════════════════════════════════
    //  5 · CUOTA AGOTADA · tampoco es un callejón
    // ══════════════════════════════════════════════════════════════
    echo "\n  ══ cuota agotada ══\n";
    $sembrar(null, null);
    //  Se agota el mes por donde se agota de verdad.
    $cubo = CuotaImg::cuboMes();
    $pdo->prepare("INSERT INTO crecer_img_cuota_cubo (marca_id, cubo, limite, usadas, created_at, updated_at)
                   VALUES (?,?,40,40,NOW(),NOW())
                   ON DUPLICATE KEY UPDATE usadas = limite, updated_at = NOW()")
        ->execute([$M, $cubo]);
    ok('el mes está agotado', !empty(CuotaImg::estado($pdo, $M, false)['lleno']));

    $r = $correr('cuota');
    ok('el navegador llegó',      ($r['OK'] ?? '0') === '1', $r['__crudo'] ?? '');
    ok('la fila sigue estando',   ($r['FILA_EXISTE'] ?? '') === 'true',
       'esconderla dejaría al dueño mirando una pared');
    ok('y explica el mes',
       str_contains((string)($r['TITULO'] ?? ''), 'ya usaste tus imágenes'), $r['TITULO'] ?? '');
    ok('con las dos salidas',
       str_contains((string)($r['CUERPO'] ?? ''), 'Biblioteca')
       && str_contains((string)($r['CUERPO'] ?? ''), 'Quedarme con la actual'), $r['CUERPO'] ?? '');
    ok('sin prometer devolución',
       !str_contains(mb_strtolower((string)($r['CUERPO'] ?? '')), 'devolv')
       && !str_contains(mb_strtolower((string)($r['CUERPO'] ?? '')), 'reembols'),
       'una unidad confirmada no se devuelve, y no se insinúa que sí');
    ok('y lo dice al revés, que es lo cierto',
       str_contains((string)($r['CUERPO'] ?? ''), 'siguen contando'));
    ok('consola limpia',          in_array((string)($r['CONSOLA'] ?? ''), ['[]', ''], true),
       $r['CONSOLA'] ?? '');
    $medir($r, 'cuota agotada');
    ok('y con el mes agotado no se abrió ninguna intención',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_generaciones
                          WHERE marca_id={$M} AND contenido_id={$P}")->fetchColumn() === 0);

} catch (Throwable $e) {
    $fallos++; echo "\n  EXCEPCION · " . get_class($e) . ': ' . $e->getMessage()
                  . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    foreach ($limpiar as $mid) {
        try { $pdo->prepare("DELETE FROM crecer_generaciones WHERE marca_id=?")->execute([$mid]); }
        catch (Throwable $e) {}
        try { Fixture::limpiar($pdo, $mid); } catch (Throwable $e) {}
    }
    echo "\n  (fixtures limpiadas)\n";
}

echo "\n  — el costo —\n";
ok('cero llamadas al modelo', $cnt('crecer_ia_log') === $g0['ia'],
   'antes ' . $g0['ia'] . ' · ahora ' . $cnt('crecer_ia_log'));
ok('cero asientos de cuota',  $cnt('crecer_img_cuota_asiento') === $g0['as'],
   'ninguno de los cinco estados necesita generar: se siembran');

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  LOS CINCO ESTADOS SE VEN Y SE TOCAN · $n afirmaciones\n\n"
    : "  $fallos FALLAS de $n\n\n";
exit($fallos === 0 ? 0 : 1);
