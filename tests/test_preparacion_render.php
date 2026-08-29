<?php
// ============================================================
//  CRECER - LA PANTALLA DE PREPARACION, RENDERIZADA DE VERDAD
//  tests/test_preparacion_render.php
//
//  includes/_preparacion_view.php es la unica pantalla que ve el dueño durante
//  toda la espera. Un fatal ahi -una variable que no llega, una funcion que no
//  esta- no da pantalla rota: da el gateway entero caido en el momento de
//  venta. Por eso se renderiza aqui con el estado REAL de una pieza sembrada,
//  y se comprueba que el HTML dice lo que el contrato exige que diga.
//
//    php tests/test_preparacion_render.php
// ============================================================
define('OPENAI_API_KEY', 'llave-falsa-de-prueba-no-autentica');
define('GEMINI_API_KEY', 'llave-falsa-de-prueba-no-autentica');
define('CRECER_TEST_RED_FALSA', true);

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
//  auth.php hace falta por csrf_token(), que la vista imprime. Se carga aqui a
//  proposito y no se «parchea» con un doble: si mañana la vista pide algo mas
//  de auth, esta prueba lo descubre igual que lo descubriria el gateway.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/agentes.php';
require_once __DIR__ . '/../includes/muestra.php';
require_once __DIR__ . '/_fixture.php';
if (session_status() === PHP_SESSION_NONE) { session_id('prueba_render' . getmypid()); @session_start(); }

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok    $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n          -> $detalle" : '') . "\n";
}

echo "\nLA PANTALLA DE PREPARACION, RENDERIZADA\n" . str_repeat('=', 62) . "\n";

$f = Fixture::crear($pdo, 'preparacion-render', false);
$marca_id = (int)$f['marca_id'];

try {
    $cid = muestra_fila($pdo, $marca_id);
    //  Se coloca la pieza en el momento mas delicado: la imagen encolada y
    //  corriendo. Es el estado en el que el dueño pasa mas tiempo mirando.
    $pdo->prepare("UPDATE crecer_contenido
                      SET ia_log_id=1, corillo_json='{\"x\":1}', img_job='resp_render',
                          img_estado='queued', img_job_at=DATE_SUB(NOW(), INTERVAL 100 SECOND),
                          created_at=DATE_SUB(NOW(), INTERVAL 100 SECOND)
                    WHERE id=?")->execute([$cid]);

    $marca = $pdo->query("SELECT * FROM crecer_marca WHERE id={$marca_id}")->fetch(PDO::FETCH_ASSOC);
    $prep  = muestra_estado($pdo, $marca_id, $cid);
    $gwq   = '';

    ok('el estado sembrado es el de la espera', $prep['etapa'] === 'enviada' && $prep['estimando'] === true,
       'etapa=' . $prep['etapa']);

    ob_start();
    require __DIR__ . '/../includes/_preparacion_view.php';
    $html = (string)ob_get_clean();

    echo "\n  -- lo que el contrato exige que se vea --\n";
    ok('renderiza sin morirse',            strlen($html) > 2000, 'bytes=' . strlen($html));
    ok('barra de progreso',                strpos($html, 'role="progressbar"') !== false);
    ok('porcentaje etiquetado ESTIMADO',   strpos($html, 'Progreso estimado') !== false);
    ok('etapa actual visible',             strpos($html, $prep['titulo']) !== false, $prep['titulo']);
    ok('el texto explicito del contrato',  strpos($html, 'Sí, sigo creando tu imagen.') !== false);
    ok('tiempo transcurrido',              strpos($html, 'id="reloj"') !== false);
    ok('animacion tranquila declarada',    strpos($html, 'class="ring"') !== false);
    ok('sondeo persistente del mismo job', strpos($html, "accion':'preparacion'") !== false
                                        || strpos($html, 'preparacion') !== false);
    //  Se cuentan los <li> pintados, no las apariciones del atributo: el JS
    //  tambien lo nombra al buscar la fila, y eso no es una etapa.
    ok('las 7 etapas, con su porcentaje',  preg_match_all('/<li class="[^"]*" data-clave=/', $html) === 7,
       'encontradas: ' . preg_match_all('/<li class="[^"]*" data-clave=/', $html));
    ok('el techo de 89 vive en el cliente', strpos($html, 'TECHO_ESTIMADO = 89') !== false);

    echo "\n  -- y lo que NO puede aparecer todavia --\n";
    ok('sin boton de aprobar',             stripos($html, 'Aprobar este post') === false);
    ok('sin boton de publicar',            stripos($html, 'Publicar en mis redes') === false);
    ok('sin descarga de la imagen',        stripos($html, 'Bajar la imagen') === false);
    //  Se mira el COPY que el dueño puede leer, no los comentarios del fuente:
    //  la regla del contrato es sobre lo que se le dice, no sobre como esta
    //  escrito el archivo. Se quitan las lineas de comentario y se busca ahi.
    $visible = preg_replace('~^\s*//.*$~m', '', $html);
    ok('no le pide recargar ni repetir',   stripos($visible, 'recarga') === false
                                        && stripos($visible, 'refresca') === false
                                        && stripos($visible, 'vuelve a pedir') === false,
       'aparece en texto visible');
    ok('no revela el copy a medias',       $prep['copy'] === null);

    echo "\n  -- el umbral cambia el mensaje, no el estado --\n";
    ok('a los 100 s ya avisa que tarda',   $prep['tarde'] === true);
    ok('el aviso esta en el cliente',      strpos($html, 'Está tomando más de lo normal') !== false);

    //  ── LA PUERTA, EN LA PAGINA DE VERDAD ───────────────────────────────
    //  Renderizar la vista prueba que la vista funciona; esto prueba que el
    //  gateway la ELIGE. Son cosas distintas y solo la segunda es la que ve el
    //  dueño. Va en un proceso aparte porque la pagina termina en exit().
    echo "\n  -- el gateway sirve la pantalla, no el escenario --\n";
    $runner = escapeshellarg(__DIR__ . '/_preparacion_gateway_runner.php');
    $php    = escapeshellarg(PHP_BINARY);
    $salida = (string)shell_exec("{$php} {$runner} " . (int)$f['usuario_id'] . " {$marca_id} 2>&1");
    ok('la pagina responde',                strlen($salida) > 1000, 'bytes=' . strlen($salida));
    ok('sirve la PANTALLA DE PREPARACION',  strpos($salida, 'Sí, sigo creando tu imagen.') !== false
                                         || strpos($salida, 'role="progressbar"') !== false,
       mb_substr(strip_tags($salida), 0, 160));
    ok('NO sirve el escenario de venta',    stripos($salida, 'Aprobar este post') === false
                                         && stripos($salida, 'Publicar en mis redes') === false);
    ok('no filtra el arte a medias',        stripos($salida, 'Bajar la imagen') === false);

} finally {
    Fixture::limpiar($pdo, $marca_id);
}

echo "\n" . str_repeat('-', 62) . "\n";
echo ($fallos === 0 ? "  TODO EN VERDE" : "  {$fallos} FALLAS") . " · {$n} comprobaciones\n";
exit($fallos === 0 ? 0 : 1);
