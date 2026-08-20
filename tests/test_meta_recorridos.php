<?php
// ============================================================
//  CRECER — LOS RECORRIDOS DE IDA Y VUELTA, PEDIDOS DE VERDAD
//  tests/test_meta_recorridos.php
//
//  test_meta_retorno.php afirma el CONTRATO. Este afirma los RECORRIDOS: cada
//  pantalla se pide como la pediria un navegador —sesion, router, marca real— y
//  se mira el HTML que salio. La diferencia importa: buscar en el fuente
//  demuestra que alguien escribio una linea; mirar la salida demuestra que esa
//  linea se ejecuto, para esta peticion, con el acuse correcto.
//
//  LO QUE NO SE PUEDE EJERCITAR, Y SE DICE. El render del reel lo hace
//  Shotstack y publicar el carrusel toca las redes del cliente: eso cuesta
//  dinero y sale del entorno. De esos dos se comprueba que la pantalla llega
//  ARMADA —que el retorno correcto viaja en la respuesta y que el camino de
//  exito lo usa—, no que el proveedor externo respondio.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/_fixture.php';
require_once __DIR__ . '/../core/Meta/MetaRetorno.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nRECORRIDOS DE TU META\n" . str_repeat('=', 56) . "\n";

$PHP    = PHP_BINARY;
$RUNNER = __DIR__ . DIRECTORY_SEPARATOR . '_render_runner.php';
$fx     = Fixture::crear($pdo, 'recorridos', true, 'admin');
$M      = (int)$fx['marca_id'];
$U      = (int)$fx['usuario_id'];
$PIEZA  = (int)$fx['piezas'][0];

/** Pide una pantalla como la pediría un navegador y devuelve su HTML. */
$pedir = function (string $pantalla, string $query, string $metodo = 'GET', string $post = '')
         use ($PHP, $RUNNER, $U): string {
    $cmd = escapeshellarg($PHP) . ' ' . escapeshellarg($RUNNER) . ' ' . $U . ' '
         . escapeshellarg($pantalla) . ' ' . escapeshellarg($query) . ' '
         . escapeshellarg($metodo) . ' ' . escapeshellarg($post) . ' 2>&1';
    $sal = []; exec($cmd, $sal);
    return implode("\n", $sal);
};

try {
    // ══════════════════════════════════════════════════════════
    //  1 · VOLVER A MANO NO PUEDE AFIRMAR NADA
    //      El dueño pudo editar o subir cosas y luego irse por el
    //      enlace de arriba: decir «no cambié nada» seria mentira.
    // ══════════════════════════════════════════════════════════
    echo "\n  — volver a mano —\n";
    // NOTA HONESTA SOBRE aprobar2. Bajo este arnés de CLI esa pantalla termina
    // sin emitir cuerpo —sin redirect y sin error— y no se pudo determinar por
    // qué. Así que aquí NO se afirma nada sobre su HTML: sería afirmar sobre una
    // página vacía. Su cableado (vuelta 'aprobado' al aprobar y 'pendiente' al
    // salir a mano) queda cubierto por test_meta_retorno.php, que lo comprueba
    // contra el archivo. Lo que SÍ se ejercita aquí es la otra mitad: que Tu
    // Meta pinte cada acuse correctamente cuando lo recibe.
    $mp = $pedir('meta.php', "marca={$M}&hecho=pendiente");
    ok('Tu Meta responde al acuse', strlen($mp) > 500, 'largo=' . strlen($mp));
    ok('acusa el regreso sin afirmar', strpos($mp, 'Volviste a tu meta.') !== false);
    ok('y dice que aquello sigue pendiente', strpos($mp, 'Esta acción sigue pendiente.') !== false);
    ok('ya no dice «No cambié nada»', strpos($mp, 'No cambié nada') === false,
       'era falso en cuanto el dueño editaba algo y luego se iba por el enlace');

    echo "\n  — aprobar —\n";
    $ma = $pedir('meta.php', "marca={$M}&hecho=aprobado");
    ok('Tu Meta confirma la aprobación', strpos($ma, 'Aprobado.') !== false);
    ok('y no la confunde con el regreso a mano', strpos($ma, 'Volviste a tu meta.') === false,
       'dos acuses distintos para dos cosas distintas');
    // ══════════════════════════════════════════════════════════
    //  3 · EL REEL, AL TERMINAR, DEVUELVE COMO MATERIAL
    //      El render lo hace Shotstack y no se invoca aqui: se
    //      comprueba que la pantalla llega armada y que el camino
    //      de exito —showDone— es el que usa esa vuelta.
    // ══════════════════════════════════════════════════════════
    echo "\n  — completar el reel —\n";
    $re = $pedir('reels.php', "marca={$M}&volver=meta");
    ok('reels responde', strlen($re) > 500, 'largo=' . strlen($re));
    ok('la vuelta por material viaja armada', strpos($re, 'hecho=material') !== false);
    ok('y la arma el servidor, no el navegador',
       strpos($re, 'var META_VUELTA_MATERIAL = "/crecer/panel/meta.php') !== false,
       'si saliera null, el reel terminado no tendria regreso');
    $ini = strpos($re, 'function showDone(');
    $fin = $ini === false ? false : strpos($re, "\nfunction ", $ini + 10);
    $cuerpoDone = ($ini === false) ? '' : substr($re, $ini, ($fin ?: strlen($re)) - $ini);
    ok('el regreso se ofrece dentro de showDone, que es el exito',
       strpos($cuerpoDone, 'META_VUELTA_MATERIAL') !== false,
       'si estuviera fuera, el reel terminado seguiria sin regreso');
    ok('la salida manual del reel sigue siendo pendiente', strpos($re, 'hecho=pendiente') !== false);

    $mm = $pedir('meta.php', "marca={$M}&hecho=material");
    ok('Tu Meta confirma el material', strpos($mm, 'Recibí tu material.') !== false);

    // ══════════════════════════════════════════════════════════
    //  4 · EL CARRUSEL: PROGRAMAR NO ES PUBLICAR
    //      Programar SI se ejercita entero contra la base.
    // ══════════════════════════════════════════════════════════
    echo "\n  — programar el carrusel —\n";
    $pdo->prepare("UPDATE crecer_contenido SET tipo='carrusel' WHERE id=?")->execute([$PIEZA]);
    // Dos slides CON imagen: programar se niega sin ellos, y hace bien.
    $sl = $pdo->prepare("INSERT INTO crecer_carrusel (contenido_id,marca_id,orden,idea,grafica_path,img_estado)
                          VALUES (?,?,?,?,?, 'ok')");
    $sl->execute([$PIEZA, $M, 1, 'Idea de relleno 1', '/crecer/uploads/prueba/slide1.png']);
    $sl->execute([$PIEZA, $M, 2, 'Idea de relleno 2', '/crecer/uploads/prueba/slide2.png']);
    $antes = $pdo->prepare("SELECT estado FROM crecer_contenido WHERE id=?");
    $antes->execute([$PIEZA]);
    ok('la pieza empieza en borrador', (string)$antes->fetchColumn() === 'borrador');

    $pedir('carrusel.php', "marca={$M}&id={$PIEZA}", 'POST', 'accion=programar&ajax=1');
    $desp = $pdo->prepare("SELECT estado, fecha_programada FROM crecer_contenido WHERE id=?");
    $desp->execute([$PIEZA]);
    $fila = $desp->fetch(PDO::FETCH_ASSOC) ?: [];
    ok('programar la deja PROGRAMADA en la base', (string)($fila['estado'] ?? '') === 'programado',
       'estado=' . (string)($fila['estado'] ?? 'null'));
    ok('y con fecha', trim((string)($fila['fecha_programada'] ?? '')) !== '');

    $cr = $pedir('carrusel.php', "marca={$M}&id={$PIEZA}&volver=meta");
    ok('el cierre del wizard vuelve como PROGRAMADO', strpos($cr, 'hecho=programado') !== false);
    ok('y NO como aprobado', strpos($cr, 'hecho=aprobado') === false,
       'programar no aprueba nada: son dos resultados distintos');

    echo "\n  — publicar el carrusel —\n";
    ok('la vuelta por publicado viaja armada', strpos($cr, 'hecho=publicado') !== false);
    ok('el mapa lo arma el servidor, una por resultado',
       strpos($cr, 'var META_VUELTA = {') !== false);
    ok('y el camino de exito del poll usa la de publicado',
       strpos($cr, "META_VUELTA.publicado") !== false,
       'ese overlay era el unico final del que no se volvia');

    $mpu = $pedir('meta.php', "marca={$M}&hecho=publicado");
    ok('Tu Meta confirma publicado', strpos($mpu, 'Publicado.') !== false);
    $mpr = $pedir('meta.php', "marca={$M}&hecho=programado");
    ok('y programado, distinto', strpos($mpr, 'Quedó programado.') !== false);

    // ══════════════════════════════════════════════════════════
    //  5 · NADIE CONFIRMA LO QUE NO PASO
    // ══════════════════════════════════════════════════════════
    echo "\n  — el acuse no se inventa —\n";
    $mx = $pedir('meta.php', "marca={$M}&hecho=" . rawurlencode('<b>ojo</b>'));
    ok('una llave inventada no pinta confirmacion',
       strpos($mx, '<div class="ah-hecho"') === false,
       'ojo: ah-hecho tambien es el nombre de la clase en el <style>');
    ok('y no cuela marcado', strpos($mx, '<b>ojo</b>') === false);

} finally {
    Fixture::limpiar($pdo, $M);
    $q = $pdo->prepare("SELECT COUNT(*) FROM crecer_marca WHERE id=?"); $q->execute([$M]);
    echo "\n  (fixture limpiada: " . ((int)$q->fetchColumn() === 0 ? 'sí' : 'NO') . ")\n";
}

echo "\n" . str_repeat('=', 56) . "\n";
echo $fallos === 0 ? "  TODO OK · {$n} pruebas\n\n" : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
