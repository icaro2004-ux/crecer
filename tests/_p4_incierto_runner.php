<?php
// ============================================================
//  CRECER — P4 ACEPTA Y NO DEVUELVE IDENTIFICADOR
//  tests/_p4_incierto_runner.php
//
//  Es el caso mas incomodo del encolado: OpenAI contesta 200, sin error, y sin
//  `id`. Puede que la imagen se este generando y nos la facturen, y no tenemos
//  con que recogerla ni con que preguntar.
//
//  SE SUSTITUYE SOLO EL BORDE DE RED (ia_http_post_retry), no la funcion
//  entera. Sustituir openai_responses_crear_bg seria probar el sustituto: el
//  camino que interesa —liberar la unidad del cliente, anotar el riesgo como
//  nuestro y NO caer a otro proveedor— vive DENTRO de esa funcion.
//
//    php tests/_p4_incierto_runner.php <marca> <post_id>
//
//  Imprime lineas CLAVE=valor.
// ============================================================

$marca = (int)($argv[1] ?? 0);
$post  = (int)($argv[2] ?? 0);

//  LLAVE FALSA, A PROPOSITO Y ANTES QUE NADA. define() gana el primero: si
//  _sin_gasto.php la pone en blanco, openai_configurado() dice que no y P4
//  lanza por credenciales ANTES de llegar al caso que se quiere probar. La red
//  no puede salir de aqui igual — ia_http_post_retry esta sustituida abajo.
define('OPENAI_API_KEY', 'sk-prueba-la-red-esta-sustituida');

require __DIR__ . '/_sin_gasto.php';

//  El borde: 200 limpio, sin error y sin id. Definido ANTES de cargar ia.php,
//  que trae la de verdad envuelta en function_exists.
function ia_http_post_retry(string $url, array $headers, string $body,
                            int $max_reintentos = 4, int $timeout = 60): string {
    $GLOBALS['P4_LLAMADAS'] = ($GLOBALS['P4_LLAMADAS'] ?? 0) + 1;
    $GLOBALS['P4_URLS'][] = $url;
    return json_encode(['object' => 'response', 'status' => 'queued']);   // sin 'id'
}

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/ia.php';
require_once __DIR__ . '/../includes/cuota_imagenes.php';
require_once __DIR__ . '/../includes/img_responses.php';

$di = fn(string $k, $v) => print($k . '=' . str_replace("\n", ' ', (string)$v) . "\n");

$antes = CuotaImg::restantes($pdo, $marca);
$di('RESTANTES_ANTES', $antes);

$res = img_resp_encolar_res($pdo, $marca, $post, 'Un bizcocho de la abuela, foto premium');

$di('VEREDICTO',  (string)($res['res'] ?? ''));
$di('CLASE',      (string)($res['clase'] ?? ''));
$di('JOB',        (string)($res['job'] ?? ''));
$di('RESTANTES_DESPUES', CuotaImg::restantes($pdo, $marca));
$di('LLAMADAS_RED', (int)($GLOBALS['P4_LLAMADAS'] ?? 0));
//  Si hubiera caido al respaldo, habria una llamada a generativelanguage.
$di('TOCO_GEMINI', (int)count(array_filter($GLOBALS['P4_URLS'] ?? [],
    fn($u) => strpos($u, 'generativelanguage') !== false)));

$q = $pdo->prepare("SELECT estado, unidades, costo_usd FROM crecer_img_cuota_asiento
                     WHERE marca_id=? AND origen_id=? ORDER BY id DESC LIMIT 1");
$q->execute([$marca, $post]);
$a = $q->fetch(PDO::FETCH_ASSOC) ?: [];
$di('ASIENTO_ESTADO', (string)($a['estado'] ?? '(ninguno)'));
$di('ASIENTO_COSTO',  (string)($a['costo_usd'] ?? '0'));
