<?php
// ============================================================
//  CRECER — EL PROCESO VACIO QUE CARGA UN ARCHIVO Y PREGUNTA
//  tests/_dep_probe.php
//
//  Lo usa tests/test_material_dependencias.php. Se ejecuta en un PHP NUEVO: sin
//  suite delante, sin dominio ya cargado, sin nada. Eso es lo que ve la primera
//  peticion que entra por esa pagina en produccion.
//
//  Carga el archivo que se le diga y contesta, en una linea de JSON, si las
//  funciones que ese archivo nombra existen. Una pagina del panel se detiene
//  antes de pintar —sin sesion no hay nada que enseñar— y eso esta bien: lo que
//  se mira es si, en el punto donde se detiene, el dominio ya estaba cargado.
// ============================================================

$rel = (string)($argv[1] ?? '');
$fns = array_values(array_filter(explode(',', (string)($argv[2] ?? ''))));
$raiz = dirname(__DIR__);

//  Modo prueba: los bordes de proveedor lanzan antes de cualquier curl, y las
//  paginas no pueden salir a la red por cargarse.
if (!defined('CRECER_TEST_MODE')) define('CRECER_TEST_MODE', true);
if (!defined('CRECER_DEP_PROBE'))  define('CRECER_DEP_PROBE', true);

//  Lo que la pagina imprima no es la respuesta: se traga y se tira.
ob_start();

//  Una pagina puede terminar por `exit` -sesion, permisos, JSON de error- y eso
//  NO es un fallo de dependencias. Se contesta desde el cierre, que corre igual.
$contestado = false;
$responder = function () use (&$contestado, $fns) {
    if ($contestado) return;
    $contestado = true;
    while (ob_get_level() > 0) ob_end_clean();
    $faltan = [];
    foreach ($fns as $f) if (!function_exists($f)) $faltan[] = $f;
    echo "\n" . json_encode(['ok' => $faltan === [], 'faltan' => $faltan]) . "\n";
};
register_shutdown_function($responder);

//  Un fatal de VERDAD (por ejemplo, llamar a algo que no existe) tambien acaba
//  aqui: el cierre contesta con lo que falte, que es justo el diagnostico.
try {
    /** @noinspection PhpIncludeInspection */
    require $raiz . '/' . $rel;
} catch (Throwable $e) {
    //  Que una pagina lance por falta de sesion o de datos no dice nada de sus
    //  dependencias. Lo unico que se responde es si el dominio esta cargado.
}
$responder();
