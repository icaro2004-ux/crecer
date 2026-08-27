<?php
// ============================================================
//  CRECER — MEJORAR SU FOTO CUESTA UNA, Y UNA SOLA
//  tests/test_material_mejorar.php
//
//  EL RIESGO QUE CIERRA. «Mejórala» es la única fila de la hoja que gasta. Con
//  40 imágenes al mes, que una intención acabe cobrando dos es dinero del dueño;
//  y que dos publicaciones distintas acaben cobrando una es dinero nuestro. Las
//  dos direcciones fallan igual de mal, y las dos salen de la MISMA cosa: la
//  llave con la que se abre la reserva.
//
//  Y ESO YA HABIA MORDIDO. La llamada síncrona de `arte` no pasaba
//  `contenido_id`, así que la reserva se abría para «(marca, arte_post, -, 0)»
//  — la misma llave para todas las publicaciones de esa marca. La segunda
//  reusaba la reserva de la primera y salía sin pagar. La nota de
//  generar_grafica_responses() cuenta el mismo defecto en la ruta de al lado.
//
//  CERO PROVEEDOR DE VERDAD. `CRECER_TEST_RED_FALSA` recorre el camino entero
//  —reserva, llamada, entrega, confirmación— con el transporte sustituido. No
//  se abre un socket ni se paga un centavo, y se cuenta al final.
// ============================================================

//  Una llave que no autentica en ninguna parte. ANTES del prólogo: define() es
//  primero-gana y `_sin_gasto.php` las deja en blanco.
define('GEMINI_API_KEY', 'llave-falsa-de-prueba-no-autentica');
define('CRECER_TEST_RED_FALSA', true);

/** PNG de 1x1 transparente. No sale de ningún proveedor: está aquí escrito. */
const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

$GLOBALS['MJ'] = ['post' => 0, 'urls' => []];

/**
 * EL DOBLE DEL TRANSPORTE. Sustituye a ia_http_post_retry() de ia.php, que está
 * declarada bajo `function_exists` justo para esto. Devuelve lo que devuelve
 * Gemini cuando entrega una imagen, sin abrir un socket.
 */
function ia_http_post_retry(string $url, array $headers, string $body,
                            int $max_reintentos = 4, int $timeout = 60): string {
    $GLOBALS['MJ']['post']++;
    $GLOBALS['MJ']['urls'][] = $url;
    return json_encode(['candidates' => [['content' => ['parts' => [
        ['inlineData' => ['mimeType' => 'image/png', 'data' => PNG_1X1]]]]]]]);
}

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/material.php';
require_once __DIR__ . '/../includes/cuota_imagenes.php';
require_once __DIR__ . '/../includes/suscripcion.php';
require_once __DIR__ . '/../includes/agentes.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nMEJORAR SU FOTO · UNA UNIDAD POR UNA INTENCION\n" . str_repeat('=', 58) . "\n";

echo "\n  — el proveedor, sustituido —\n";
ok('el modo prueba está puesto',  defined('CRECER_TEST_MODE') && CRECER_TEST_MODE);
ok('con transporte falso',        defined('CRECER_TEST_RED_FALSA') && CRECER_TEST_RED_FALSA,
   'esta suite SÍ recorre el camino del proveedor: necesita el doble');

$cnt = fn(string $t, string $w = '1') => (int)$GLOBALS['pdo']->query(
    "SELECT COUNT(*) FROM {$t} WHERE {$w}")->fetchColumn();

if (!CuotaImg::disponible($pdo, true)) {
    echo "\n  SALTADA · falta migrations/2026-08-21_crecer_img_cuota.sql\n\n"; exit(0);
}

$limpiar = [];
try {
    $fx = Fixture::crear($pdo, 'mej', false, 'admin');
    $limpiar[] = $M = (int)$fx['marca_id'];

    //  Una foto suya DE VERDAD en disco: mejorar realza un archivo, no una fila.
    $dir = rtrim(UPLOADS_PATH, '/\\') . DIRECTORY_SEPARATOR . "marca_{$M}"
         . DIRECTORY_SEPARATOR . 'biblioteca';
    @mkdir($dir, 0775, true);
    $rel = "marca_{$M}/biblioteca/suya_mej.png";
    file_put_contents(rtrim(UPLOADS_PATH, '/\\') . DIRECTORY_SEPARATOR
                      . str_replace('/', DIRECTORY_SEPARATOR, $rel), base64_decode(PNG_1X1));

    $act = $pdo->prepare("INSERT INTO crecer_activos
            (marca_id,tipo,archivo,nombre,mime,bytes,origen,estado)
          VALUES (?,?,?,?,?,?, 'subido','activo')");
    $act->execute([$M, 'imagen', $rel, '[prueba] Su bizcocho', 'image/png', 70]);
    $FOTO = (int)$pdo->lastInsertId();
    $act->execute([$M, 'video', "marca_{$M}/biblioteca/suyo_mej.mp4",
                   '[prueba] Su horno', 'video/mp4', 9000]);
    $VIDEO = (int)$pdo->lastInsertId();

    $ins = $pdo->prepare("INSERT INTO crecer_contenido
            (marca_id,plataforma,tipo,caption,estado,fecha_programada,grafica_path)
          VALUES (?, 'instagram','post',?, 'borrador', DATE_ADD(NOW(), INTERVAL 2 DAY), '')");
    $ins->execute([$M, '[prueba] Con su foto.']);   $C1 = (int)$pdo->lastInsertId();
    $ins->execute([$M, '[prueba] Otra pieza.']);    $C2 = (int)$pdo->lastInsertId();
    $ins->execute([$M, '[prueba] Sin material.']);  $C3 = (int)$pdo->lastInsertId();

    material_aplicar($pdo, $M, $C1, $FOTO);
    material_aplicar($pdo, $M, $C2, $FOTO);

    // ══════════════════════════════════════════════════════════════
    //  1 · QUE FOTO SE MEJORA · solo la suya, y solo si está
    // ══════════════════════════════════════════════════════════════
    echo "\n  — se mejora la foto suya, y nada más —\n";
    $abs = material_abs_de_pieza($pdo, $M, $C1);
    ok('resuelve la foto de la pieza', $abs !== null && is_file($abs), (string)$abs);
    ok('y es la que él puso',
       $abs !== null && str_contains(str_replace('\\', '/', $abs), 'suya_mej.png'));

    ok('sin material no hay nada que mejorar',
       material_abs_de_pieza($pdo, $M, $C3) === null,
       'sobre arte generado no se «mejora»: se vuelve a pintar, y eso es otra fila');

    //  Un VIDEO aplicado no es mejorable: el realce es de imagen.
    $ins->execute([$M, '[prueba] Un reel.']);
    $CR = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE crecer_contenido SET tipo='reel' WHERE id=?")->execute([$CR]);
    material_aplicar($pdo, $M, $CR, $VIDEO);
    ok('un video no se mejora', material_abs_de_pieza($pdo, $M, $CR) === null);

    //  Y DE OTRA MARCA, NADA — aunque el id exista.
    $fy = Fixture::crear($pdo, 'mejX', false, 'cliente');
    $limpiar[] = $MX = (int)$fy['marca_id'];
    ok('otra marca no resuelve esta foto', material_abs_de_pieza($pdo, $MX, $C1) === null);

    // ══════════════════════════════════════════════════════════════
    //  2 · LO QUE CUESTA · realce, no arte desde cero
    // ══════════════════════════════════════════════════════════════
    echo "\n  — con foto delante, el motor cuenta «realce» —\n";
    $as0 = $cnt('crecer_img_cuota_asiento', "marca_id={$M}");
    $post0 = $GLOBALS['MJ']['post'];

    $r1 = generar_grafica($pdo, $M, $abs, ['copy' => '[prueba] Su bizcocho.',
        'con_texto' => false, 'con_logo' => false, 'contenido_id' => $C1]);
    ok('la mejora entrega una imagen', !empty($r1['archivo']), json_encode($r1));
    ok('y pasó por el proveedor (el doble)', $GLOBALS['MJ']['post'] > $post0);

    $op = (string)$pdo->query("SELECT operacion FROM crecer_img_cuota_asiento
                                WHERE marca_id={$M} ORDER BY id DESC LIMIT 1")->fetchColumn();
    ok('la operación es «realce»', $op === 'realce',
       "salió «{$op}» · subir la foto cuesta 0, transformarla cuesta 1");
    ok('y gastó exactamente una',
       $cnt('crecer_img_cuota_asiento', "marca_id={$M}") === $as0 + 1);

    // ══════════════════════════════════════════════════════════════
    //  3 · UNA INTENCION VIVA, UN ASIENTO
    // ══════════════════════════════════════════════════════════════
    //
    //  LA REGLA, DICHA CON CUIDADO. La reserva es idempotente MIENTRAS ESTA
    //  VIVA: dos toques del mismo boton, la conexion que reintenta, las seis
    //  peticiones que se pisan — todas reusan el mismo asiento. Pero una vez
    //  CERRADA, la llave se retira a proposito, y un intento posterior y
    //  deliberado del dueño SI abre otro. Son dos cosas distintas y la prueba
    //  las separa: confundirlas es como se acaba cobrando dos veces un doble
    //  clic, o regalando para siempre la segunda imagen de una pieza.
    echo "\n  — la misma intención viva no se cobra dos veces —\n";
    $ctx = fn(string $op, int $cid) => CuotaCtx::de($pdo, $M, $op, 'crear_arte_post',
                ['origen_tipo' => 'contenido', 'origen_id' => $cid, 'costo' => 0.17]);

    $as_a = $cnt('crecer_img_cuota_asiento', "marca_id={$M}");
    $r1 = CuotaImg::reservar($pdo, $ctx('realce', $C3));
    $r2 = CuotaImg::reservar($pdo, $ctx('realce', $C3));
    ok('dos intentos a la vez abren UN asiento',
       !empty($r1['ok']) && !empty($r2['ok'])
       && (int)$r1['asiento_id'] === (int)$r2['asiento_id'],
       json_encode([$r1, $r2]));
    ok('y el segundo dice que reusó', !empty($r2['reusado']), json_encode($r2));
    ok('una sola fila nueva',
       $cnt('crecer_img_cuota_asiento', "marca_id={$M}") === $as_a + 1);

    //  Y CERRADA LA PRIMERA, un intento posterior del dueño SI es otra cosa.
    echo "\n  — y un ciclo posterior deliberado sí es otra —\n";
    CuotaImg::confirmar($pdo, (int)$r1['asiento_id'], 0.17);
    ok('la unidad queda consumida',
       (string)$pdo->query("SELECT estado FROM crecer_img_cuota_asiento
                             WHERE id=" . (int)$r1['asiento_id'])->fetchColumn() === 'confirmado',
       'una entrega que no se cierra cuenta como retenida para siempre');
    $r3 = CuotaImg::reservar($pdo, $ctx('realce', $C3));
    ok('el ciclo siguiente abre asiento nuevo',
       !empty($r3['ok']) && (int)$r3['asiento_id'] !== (int)$r1['asiento_id'],
       json_encode($r3));
    ok('y suma una unidad',
       $cnt('crecer_img_cuota_asiento', "marca_id={$M}") === $as_a + 2);
    CuotaImg::liberar($pdo, (int)$r3['asiento_id'], '[prueba] fin del ciclo');

    // ══════════════════════════════════════════════════════════════
    //  3b · IDENTIDADES · pieza, operación y slide
    // ══════════════════════════════════════════════════════════════
    echo "\n  — cada cosa tiene su identidad —\n";
    ok('dos publicaciones son dos identidades',
       CuotaImg::idem($M, 'realce', 'contenido', $C1) !== CuotaImg::idem($M, 'realce', 'contenido', $C2),
       'sin la pieza dentro, la segunda reusaba la reserva de la primera y salía gratis');
    ok('realce y arte de la MISMA pieza son distintos',
       CuotaImg::idem($M, 'realce', 'contenido', $C1) !== CuotaImg::idem($M, 'arte_post', 'contenido', $C1),
       'mejorar su foto y pintar una nueva no son el mismo gasto');
    ok('y un slide no es su publicación',
       CuotaImg::idem($M, 'slide', 'slide', $C1) !== CuotaImg::idem($M, 'slide', 'contenido', $C1),
       'un carrusel de cinco slides son cinco imágenes, no una');
    ok('ni un slide es otro slide',
       CuotaImg::idem($M, 'slide', 'slide', 1) !== CuotaImg::idem($M, 'slide', 'slide', 2));

    // ══════════════════════════════════════════════════════════════
    //  3c · NINGUNA UNIDAD POR PIEZA SIN PIEZA
    // ══════════════════════════════════════════════════════════════
    //
    //  El barrido de verdad: se mira EL LIBRO ENTERO, no solo lo que esta
    //  prueba acaba de escribir. Una fila por pieza con origen 0 es la llave
    //  compartida por toda la marca, que es el defecto que esto cierra.
    echo "\n  — y ninguna unidad por pieza se quedó sin pieza —\n";
    $huerfanas = (int)$pdo->query(
        "SELECT COUNT(*) FROM crecer_img_cuota_asiento
          WHERE operacion IN ('arte_post','realce','slide')
            AND (origen_id IS NULL OR origen_id = 0)
            AND created_at >= (NOW() - INTERVAL 1 DAY)")->fetchColumn();
    ok('cero asientos por pieza con origen 0 (últimas 24h)', $huerfanas === 0,
       $huerfanas . ' filas · una llave con origen 0 es la MISMA para toda la marca');

    // ══════════════════════════════════════════════════════════════
    //  3d · USAR LO QUE YA ES SUYO NO CUESTA
    // ══════════════════════════════════════════════════════════════
    echo "\n  — y poner una foto suya no gasta nada —\n";
    $as_d = $cnt('crecer_img_cuota_asiento', "marca_id={$M}");
    $ia_d = $cnt('crecer_ia_log');
    material_aplicar($pdo, $M, $C3, $FOTO);
    ok('aplicar no abre asiento', $cnt('crecer_img_cuota_asiento', "marca_id={$M}") === $as_d,
       'subir y usar no pasan por proveedor: cuestan 0');
    ok('ni llama al modelo', $cnt('crecer_ia_log') === $ia_d);

    // ══════════════════════════════════════════════════════════════
    //  4 · SIN CUOTA NO SE LLAMA AL PROVEEDOR
    // ══════════════════════════════════════════════════════════════
    echo "\n  — y cuando se acaba el mes, se acaba antes de llamar —\n";
    $post_antes = $GLOBALS['MJ']['post'];
    //  SE AGOTA EL CUBO POR DONDE SE AGOTA DE VERDAD: `usadas` del mes. No es
    //  simular el limite, es ponerlo en el estado que tendra el dia 28.
    $cubo = CuotaImg::cuboMes();
    $pdo->prepare("UPDATE crecer_img_cuota_cubo SET usadas = limite, updated_at=NOW()
                    WHERE marca_id=? AND cubo=?")->execute([$M, $cubo]);
    $lleno = !empty(CuotaImg::estado($pdo, $M, false)['lleno']);
    ok('el cubo se puede llenar para probarlo', $lleno,
       'sin poder agotar la cuota, esta sección no prueba nada');
    if ($lleno) {
        $err = '';
        //  Una pieza LIMPIA: la de arriba ya lleva su foto puesta, y entonces
        //  «no se quedo con arte» seria cierto por otra razon.
        $ins->execute([$M, '[prueba] Sin nada, para el mes agotado.']);
        $CX = (int)$pdo->lastInsertId();
        try {
            generar_grafica($pdo, $M, $abs, ['copy' => '[prueba] Ya no queda.',
                'con_texto' => false, 'con_logo' => false, 'contenido_id' => $CX]);
        } catch (Throwable $e) { $err = get_class($e) . ': ' . $e->getMessage(); }
        ok('con el mes agotado no se llama al proveedor',
           $GLOBALS['MJ']['post'] === $post_antes,
           'se llamó ' . ($GLOBALS['MJ']['post'] - $post_antes) . ' vez/veces · ' . $err);
        ok('y la pieza no se queda con arte que no se pagó',
           trim((string)$pdo->query("SELECT grafica_path FROM crecer_contenido WHERE id={$CX}")
                            ->fetchColumn()) === '',
           'entregar sin reserva es la puerta que cuota_imagenes cierra a propósito');
    }

} catch (Throwable $e) {
    $fallos++; echo "\n  EXCEPCION · " . get_class($e) . ': ' . $e->getMessage()
                  . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    foreach ($limpiar as $mid) { try { Fixture::limpiar($pdo, $mid); } catch (Throwable $e) {} }
    echo "\n  (fixtures limpiadas)\n";
}

echo "\n  — el costo de verdad —\n";
ok('ni un socket abierto', defined('CRECER_TEST_MODE') && CRECER_TEST_MODE,
   'crecer_red_exigir() lanza en los cuatro bordes antes del curl');
ok('todo el tráfico fue al doble', $GLOBALS['MJ']['post'] > 0,
   $GLOBALS['MJ']['post'] . ' llamadas, todas sustituidas');

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  UNA INTENCION, UNA UNIDAD · $n afirmaciones\n\n"
    : "  $fallos FALLAS de $n\n\n";
exit($fallos === 0 ? 0 : 1);
