<?php
// ============================================================
//  CRECER — UN PROCESO QUE DIGIERE, PARA PROBAR LA CARRERA
//  tests/_digerir_runner.php
//
//  Se lanza DOS VECES a la vez, cada uno con su propio proceso y su propia
//  conexión. Dos llamadas seguidas dentro del mismo proceso no prueban una
//  carrera: prueban un orden. Lo que aquí se demuestra es que con los dos
//  entrando al mismo tiempo solo uno reclama la fila, solo uno llama al
//  Aprendiz, y ninguno la pierde.
//
//  El borde del Aprendiz va sustituido —`aprendiz_leccion()` está declarada
//  bajo function_exists en agentes.php, así que la de aquí gana— y cuenta sus
//  llamadas en el resultado. No sale un byte a la red.
//
//    php tests/_digerir_runner.php <marca_id> [fallo]
// ============================================================

$MARCA = (int)($argv[1] ?? 0);
$SIM   = (string)($argv[2] ?? 'ok');

require_once __DIR__ . '/_sin_gasto.php';

/** EL DOBLE DEL APRENDIZ. Ni prompt ni red: la lección que se le pida. */
function aprendiz_leccion(PDO $pdo, int $marca_id, string $original,
                          string $editado, array $opts = []): ?string {
    $GLOBALS['LLAMADAS'] = (int)($GLOBALS['LLAMADAS'] ?? 0) + 1;
    if (($GLOBALS['SIM'] ?? 'ok') === 'fallo') {
        throw new RuntimeException('simulado: el modelo no contestó');
    }
    if (($GLOBALS['SIM'] ?? 'ok') === 'nada') return null;
    require_once __DIR__ . '/../includes/memoria.php';
    //  Se escribe con memoria_escribir(), que es lo que hace el Aprendiz de
    //  verdad: la lección tiene que quedar donde memoria_relevante() la lee.
    $leccion = 'Prefiere hablarle de tú y cerrar con una invitación directa por WhatsApp.';
    memoria_escribir($pdo, $marca_id, [
        'tipo' => 'preferencia', 'titulo' => mb_strimwidth($leccion, 0, 120, '…'),
        'detalle' => $leccion,
        'porque' => 'Lo aprendí de una edición que le hiciste a un caption.',
        'fuente' => 'edicion', 'confianza' => 70, 'peso' => 80,
    ]);
    return $leccion;
}

$GLOBALS['SIM'] = $SIM;
$GLOBALS['LLAMADAS'] = 0;

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/agentes.php';

$salida = ['digeridas' => 0, 'fallidas' => 0, 'llamadas' => 0, 'error' => null];
try {
    $r = edicion_digerir($pdo, $MARCA, 10);
    $salida['digeridas'] = (int)$r['digeridas'];
    $salida['fallidas']  = (int)$r['fallidas'];
} catch (Throwable $e) {
    $salida['error'] = get_class($e) . ': ' . $e->getMessage();
}
//  Las llamadas se cuentan en el DOBLE, no en el digestor: es la unica cifra
//  que demuestra que nadie pago dos veces por la misma leccion.
$salida['llamadas'] = (int)($GLOBALS['LLAMADAS'] ?? 0);

echo json_encode($salida, JSON_UNESCAPED_UNICODE), "\n";
