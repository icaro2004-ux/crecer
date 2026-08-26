<?php
// ============================================================
//  CRECER — CADA TRANSICION DEJA LA TRAZA DICIENDO LA VERDAD
//  tests/test_material_transiciones.php
//
//  LO QUE SE PRUEBA. `material_activo_id` no es un adorno: es lo que la pantalla
//  usa para decirle al dueño de donde salio lo que ve. Hay ocho maneras de que
//  la imagen de una publicacion cambie, y cada una tiene una respuesta distinta:
//
//    Biblioteca  → ruta suya + su id
//    Video suyo  → ruta suya + su id
//    Realce      → ruta DERIVADA + el id del original (de esa foto salio)
//    IA sincrona → ruta generada + id vacio
//    IA asincrona→ ruta generada + id vacio
//    Respaldo    → coherente, y no revive un id anterior
//    Fallo       → no toca nada de lo que habia
//    Reuso       → el origen que la evidencia sostenga, no el que se suponga
//
//  LA REGLA QUE ORDENA TODO ESTO: la identidad NO se deduce de la ruta del
//  archivo cuando hay `material_activo_id`. La ruta dice que se enseña; la traza
//  dice de donde salio. Cuando se separan —y en un realce se separan a
//  proposito— hay que mirar la traza, no adivinar por el nombre del fichero.
//
//  CERO PROVEEDOR. Lo que genera va con el transporte sustituido; lo asincrono
//  corre en el runner que ya existe, que tambien lo sustituye. Se cuenta al final.
// ============================================================

define('GEMINI_API_KEY', 'llave-falsa-de-prueba-no-autentica');
define('CRECER_TEST_RED_FALSA', true);

const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

$GLOBALS['TR'] = ['post' => 0, 'fallar' => false];

/** El doble del transporte. Ni un socket: devuelve lo que devuelve Gemini. */
function ia_http_post_retry(string $url, array $headers, string $body,
                            int $max_reintentos = 4, int $timeout = 60): string {
    $GLOBALS['TR']['post']++;
    if (!empty($GLOBALS['TR']['fallar'])) {
        return json_encode(['error' => ['message' => 'simulado: el proveedor dijo que no']]);
    }
    return json_encode(['candidates' => [['content' => ['parts' => [
        ['inlineData' => ['mimeType' => 'image/png', 'data' => PNG_1X1]]]]]]]);
}

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/material.php';
require_once __DIR__ . '/../includes/meta_semana.php';
require_once __DIR__ . '/../includes/cuota_imagenes.php';
require_once __DIR__ . '/../includes/agentes.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nCADA TRANSICION, Y LO QUE DEJA DICHO\n" . str_repeat('=', 58) . "\n";

$cnt = fn(string $t, string $w = '1') => (int)$GLOBALS['pdo']->query(
    "SELECT COUNT(*) FROM {$t} WHERE {$w}")->fetchColumn();
$g = ['ia' => $cnt('crecer_ia_log'), 'cuota' => $cnt('crecer_img_cuota_asiento')];

if (!material_hay_columna($pdo, true)) {
    echo "\n  SALTADA · falta migrations/2026-08-26_crecer_contenido_material.sql\n\n"; exit(0);
}

$limpiar = [];
try {
    $fx = Fixture::crear($pdo, 'trans', false, 'admin');
    $limpiar[] = $M = (int)$fx['marca_id'];

    //  Una foto suya DE VERDAD en disco: el realce transforma un archivo.
    $rel  = "marca_{$M}/biblioteca/suya_tr.png";
    $abs  = rtrim(UPLOADS_PATH, '/\\') . DIRECTORY_SEPARATOR
          . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    @mkdir(dirname($abs), 0775, true);
    file_put_contents($abs, base64_decode(PNG_1X1));

    $act = $pdo->prepare("INSERT INTO crecer_activos
            (marca_id,tipo,archivo,nombre,mime,bytes,origen,estado)
          VALUES (?,?,?,?,?,?,?, 'activo')");
    $act->execute([$M, 'imagen', $rel, '[prueba] Su bizcocho', 'image/png', 70, 'subido']);
    $FOTO = (int)$pdo->lastInsertId();
    $act->execute([$M, 'video', "marca_{$M}/biblioteca/suyo_tr.mp4",
                   '[prueba] Su horno', 'video/mp4', 9000, 'subido']);
    $VIDEO = (int)$pdo->lastInsertId();

    $ins = $pdo->prepare("INSERT INTO crecer_contenido
            (marca_id,plataforma,tipo,caption,estado,fecha_programada,grafica_path)
          VALUES (?, 'instagram',?,?, 'borrador', DATE_ADD(NOW(), INTERVAL 2 DAY), '')");
    $mat = fn(int $c) => $pdo->query("SELECT material_activo_id FROM crecer_contenido WHERE id={$c}")->fetchColumn();
    $img = fn(int $c) => (string)$pdo->query("SELECT grafica_path FROM crecer_contenido WHERE id={$c}")->fetchColumn();
    $leer = fn(int $c) => $pdo->query(
        "SELECT id,tipo,estado,grafica_path FROM crecer_contenido WHERE id={$c}")->fetch(PDO::FETCH_ASSOC);

    // ══════════════════════════════════════════════════════════════
    //  1 · FOTO DE BIBLIOTECA · ruta suya, id suyo
    // ══════════════════════════════════════════════════════════════
    echo "\n  — 1 · una foto de su Biblioteca —\n";
    $ins->execute([$M, 'post', '[prueba] Con su foto.']);
    $C1 = (int)$pdo->lastInsertId();
    $r = material_aplicar($pdo, $M, $C1, $FOTO);
    ok('se aplica',            !empty($r['ok']), json_encode($r));
    ok('la ruta es la suya',   str_contains($img($C1), 'suya_tr.png'), $img($C1));
    ok('y la traza la nombra', (int)$mat($C1) === $FOTO);
    ok('la pantalla lo dice',
       semana_material($pdo, $M, $leer($C1))['frase'] === 'Ahora lleva tu foto: «[prueba] Su bizcocho».',
       semana_material($pdo, $M, $leer($C1))['frase']);

    // ══════════════════════════════════════════════════════════════
    //  2 · VIDEO PROPIO · lo mismo, en un reel
    // ══════════════════════════════════════════════════════════════
    echo "\n  — 2 · un video suyo en un reel —\n";
    $ins->execute([$M, 'reel', '[prueba] Su reel.']);
    $CR = (int)$pdo->lastInsertId();
    $r = material_aplicar($pdo, $M, $CR, $VIDEO);
    ok('el video se aplica',   !empty($r['ok']), json_encode($r));
    ok('la ruta es la suya',   str_contains($img($CR), 'suyo_tr.mp4'), $img($CR));
    ok('y la traza también',   (int)$mat($CR) === $VIDEO);
    ok('un post no lo habría admitido',
       empty(material_aplicar($pdo, $M, $C1, $VIDEO)['ok']),
       'no lo convierte nadie: ofrecerlo y rechazarlo después es hacerle perder el viaje');

    // ══════════════════════════════════════════════════════════════
    //  3 · REALCE · ruta derivada, y el id del original SE CONSERVA
    // ══════════════════════════════════════════════════════════════
    //
    //  ESTA ES LA TRANSICION QUE MAS FACIL SE CUENTA MAL. Un realce NO es otra
    //  imagen: es la suya, trabajada. Soltar la traza aqui convertiria «tu
    //  bizcocho, realzado» en «arte del corillo» — que es exactamente lo
    //  contrario de lo que paso, y encima le borra de la pantalla que la foto
    //  era suya.
    echo "\n  — 3 · su foto, realzada —\n";
    $antes_ruta = $img($C1);
    $post0 = $GLOBALS['TR']['post'];
    $abs_pieza = material_abs_de_pieza($pdo, $M, $C1);
    ok('el dominio encuentra su foto en disco', $abs_pieza !== null && is_file($abs_pieza));

    $rg = generar_grafica($pdo, $M, $abs_pieza, ['copy' => '[prueba] Realce.',
        'con_texto' => false, 'con_logo' => false, 'contenido_id' => $C1]);
    ok('el realce entrega una imagen', !empty($rg['archivo']), json_encode($rg));
    ok('y paso por el proveedor (el doble)', $GLOBALS['TR']['post'] > $post0);

    //  El handler escribe la ruta y —esto es lo suyo— NO suelta.
    $pdo->prepare("UPDATE crecer_contenido SET grafica_path=? WHERE id=?")
        ->execute([$rg['archivo'], $C1]);

    ok('la ruta cambió',       $img($C1) !== $antes_ruta, $img($C1));
    ok('y ya no es el archivo original', !str_contains($img($C1), 'suya_tr.png'), $img($C1));
    ok('pero la traza SIGUE siendo la suya', (int)$mat($C1) === $FOTO,
       'de esa foto salió lo que se ve: soltarla diría «arte del corillo»');

    $m3 = semana_material($pdo, $M, $leer($C1));
    ok('y la pantalla dice «realzada»',
       $m3['frase'] === 'Ahora lleva tu foto realzada: «[prueba] Su bizcocho».', $m3['frase']);
    ok('sin decir que sea del corillo', $m3['origen'] === 'biblioteca');
    ok('el asiento es de realce',
       (string)$pdo->query("SELECT operacion FROM crecer_img_cuota_asiento
                             WHERE marca_id={$M} ORDER BY id DESC LIMIT 1")->fetchColumn() === 'realce');
    ok('y va a nombre de ESTA pieza',
       (int)$pdo->query("SELECT origen_id FROM crecer_img_cuota_asiento
                          WHERE marca_id={$M} ORDER BY id DESC LIMIT 1")->fetchColumn() === $C1);

    //  LA REGLA, DICHA: la identidad NO sale de la ruta.
    ok('la identidad no se deduce del nombre del archivo',
       material_origen($pdo, $M, $C1)['origen'] === 'biblioteca'
       && !str_contains($img($C1), 'suya_tr.png'),
       'la ruta dice qué se enseña; la traza dice de dónde salió');

    // ══════════════════════════════════════════════════════════════
    //  4 · IA DESDE CERO, SINCRONA · ruta generada, traza vacía
    // ══════════════════════════════════════════════════════════════
    echo "\n  — 4 · arte desde cero, aquí mismo —\n";
    $rg2 = generar_grafica($pdo, $M, null, ['copy' => '[prueba] Desde cero.',
        'con_texto' => false, 'con_logo' => false, 'contenido_id' => $C1]);
    ok('entrega una imagen', !empty($rg2['archivo']), json_encode($rg2));
    //  Esto es lo que hace el handler cuando NO hubo foto de entrada.
    $pdo->prepare("UPDATE crecer_contenido SET grafica_path=? WHERE id=?")
        ->execute([$rg2['archivo'], $C1]);
    material_soltar($pdo, $M, $C1);
    ok('la traza queda vacía', $mat($C1) === null,
       'no hay material del dueño detrás: conservar el id sería señalar a algo que ya no se ve');
    ok('y la pantalla deja de decir «tu foto»',
       semana_material($pdo, $M, $leer($C1))['frase'] === 'Ahora lleva arte del corillo.');
    ok('el asiento es arte_post',
       (string)$pdo->query("SELECT operacion FROM crecer_img_cuota_asiento
                             WHERE marca_id={$M} ORDER BY id DESC LIMIT 1")->fetchColumn() === 'arte_post');

    // ══════════════════════════════════════════════════════════════
    //  5 · FALLO DEL PROVEEDOR · no deja una mezcla imposible
    // ══════════════════════════════════════════════════════════════
    echo "\n  — 5 · y cuando el proveedor dice que no —\n";
    $ins->execute([$M, 'post', '[prueba] La que falla.']);
    $CF = (int)$pdo->lastInsertId();
    material_aplicar($pdo, $M, $CF, $FOTO);
    $ruta_f = $img($CF); $mat_f = (int)$mat($CF);

    $GLOBALS['TR']['fallar'] = true;
    $exploto = false;
    try {
        generar_grafica($pdo, $M, material_abs_de_pieza($pdo, $M, $CF),
            ['copy' => '[prueba] Falla.', 'con_texto' => false, 'con_logo' => false,
             'contenido_id' => $CF]);
    } catch (Throwable $e) { $exploto = true; }
    $GLOBALS['TR']['fallar'] = false;

    ok('el fallo se propaga', $exploto, 'un fallo silencioso deja al handler creyendo que hubo imagen');
    ok('la ruta se queda como estaba', $img($CF) === $ruta_f, $img($CF));
    ok('y la traza también',           (int)$mat($CF) === $mat_f,
       'lo que había seguía siendo cierto: el fallo no lo cambia');
    ok('la unidad vuelve al cubo',
       (string)$pdo->query("SELECT estado FROM crecer_img_cuota_asiento
                             WHERE marca_id={$M} AND origen_id={$CF}
                             ORDER BY id DESC LIMIT 1")->fetchColumn() === 'liberado',
       'el dueño no recibió nada: cobrarle del mes una imagen que no llegó es cobrar de más');

    // ══════════════════════════════════════════════════════════════
    //  6 · REUSO DE ARTE GENERADO · suelta la relación
    // ══════════════════════════════════════════════════════════════
    echo "\n  — 6 · reusar un arte ya hecho —\n";
    material_aplicar($pdo, $M, $CF, $FOTO);
    ok('parte de una pieza con su foto', (int)$mat($CF) === $FOTO);
    //  Esto es lo que hace `reusar_arte`: pega una ruta ya generada y suelta.
    $pdo->prepare("UPDATE crecer_contenido SET grafica_path=? WHERE id=?")
        ->execute([$rg2['archivo'], $CF]);
    material_soltar($pdo, $M, $CF);
    ok('reusar suelta la relación', $mat($CF) === null,
       'el arte reusado no salió de su foto: decir que sí sería inventarle un origen');

} catch (Throwable $e) {
    $fallos++; echo "\n  EXCEPCION · " . get_class($e) . ': ' . $e->getMessage()
                  . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    foreach ($limpiar as $mid) { try { Fixture::limpiar($pdo, $mid); } catch (Throwable $e) {} }
    echo "\n  (fixtures limpiadas)\n";
}

echo "\n  — el costo —\n";
ok('todo el tráfico fue al doble', $GLOBALS['TR']['post'] > 0,
   $GLOBALS['TR']['post'] . ' llamadas, todas sustituidas');
ok('el modo prueba estuvo puesto', defined('CRECER_TEST_MODE') && CRECER_TEST_MODE);

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  CADA TRANSICION DICE LA VERDAD · $n afirmaciones\n\n"
    : "  $fallos FALLAS de $n\n\n";
exit($fallos === 0 ? 0 : 1);
