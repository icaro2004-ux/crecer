<?php
// ============================================================
//  CRECER — UN SONDEO CON EL PROVEEDOR SIMULADO EN EL BORDE
//  tests/_sondeo_runner.php
//
//  Sustituye SOLO ia_http_get_res(), que es donde el codigo toca la red. Todo
//  lo de arriba —openai_responses_estado, img_poll_decidir, img_resp_completar,
//  el cierre de la unidad de cuota— corre igual que en produccion. Sustituir
//  una funcion mas arriba seria probar el sustituto.
//
//    php tests/_sondeo_runner.php <marca> <post> <caso>
//
//  casos:  404        el proveedor no reconoce el job   (el de #656)
//          completo   llega la imagen
//          vivo       sigue en cola
//          caido      no se pudo consultar (error de red)
//
//  Imprime lineas CLAVE=valor.
// ============================================================

$marca = (int)($argv[1] ?? 0);
$post  = (int)($argv[2] ?? 0);
$caso  = (string)($argv[3] ?? 'vivo');

//  Llave falsa: define() gana el primero. Sin ella openai_responses_estado()
//  lanzaria por credenciales antes de llegar al borde simulado. La red no sale
//  de aqui igual — ia_http_get_res esta sustituida abajo.
define('OPENAI_API_KEY', 'sk-prueba-la-red-esta-sustituida');

require __DIR__ . '/_sin_gasto.php';

function ia_http_get_res(string $url, array $headers): array {
    $GLOBALS['SONDEO_URLS'][] = $url;
    switch ($GLOBALS['SONDEO_CASO'] ?? '') {
        case '404':
            return ['code' => 404, 'body' => json_encode([
                'error' => ['message' => "No response found with id 'resp_x'.", 'type' => 'invalid_request_error'],
            ])];
        case 'completo':
            //  1x1 PNG real, para que se guarde un archivo de verdad.
            $png = base64_encode(base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
            return ['code' => 200, 'body' => json_encode([
                'id' => 'resp_x', 'status' => 'completed', 'model' => 'gpt-image-1',
                'output' => [['type' => 'image_generation_call', 'result' => $png, 'revised_prompt' => 'x']],
            ])];
        case 'caido':
            throw new IaError('HTTP GET: Could not resolve host');
        default:
            return ['code' => 200, 'body' => json_encode([
                'id' => 'resp_x', 'status' => 'in_progress', 'model' => 'gpt-image-1', 'output' => [],
            ])];
    }
}

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/ia.php';
require_once __DIR__ . '/../includes/cuota_imagenes.php';
require_once __DIR__ . '/../includes/img_responses.php';

$GLOBALS['SONDEO_CASO'] = $caso;
$GLOBALS['SONDEO_URLS'] = [];

$di = fn(string $k, $v) => print($k . '=' . str_replace("\n", ' ', (string)$v) . "\n");

$est = CuotaImg::estado($pdo, $marca);
$di('RESTANTES_ANTES',  $est['restantes']);
$di('RETENIDAS_ANTES',  $est['retenidas']);
$di('CONSUMIDAS_ANTES', $est['consumidas']);

$r = img_resp_completar($pdo, $marca, $post, true);

$di('ESTADO',      (string)($r['estado'] ?? ''));
$di('RECUPERABLE', !empty($r['recuperable']) ? '1' : '0');
$di('IMG',         (string)($r['img'] ?? ''));

$est2 = CuotaImg::estado($pdo, $marca);
$di('RESTANTES_DESPUES',  $est2['restantes']);
$di('RETENIDAS_DESPUES',  $est2['retenidas']);
$di('CONSUMIDAS_DESPUES', $est2['consumidas']);

//  Si hubiera caido al respaldo, habria una llamada a generativelanguage.
$di('TOCO_GEMINI', (int)count(array_filter($GLOBALS['SONDEO_URLS'],
    fn($u) => strpos($u, 'generativelanguage') !== false)));
$di('LLAMADAS_RED', count($GLOBALS['SONDEO_URLS']));

$q = $pdo->prepare("SELECT img_estado, img_job, img_error_clase, img_intentos, img_job_at
                      FROM crecer_contenido WHERE id=? AND marca_id=?");
$q->execute([$post, $marca]);
$p = $q->fetch(PDO::FETCH_ASSOC) ?: [];
$di('PIEZA_ESTADO',  (string)($p['img_estado'] ?? ''));
$di('PIEZA_JOB',     (string)($p['img_job'] ?? ''));
$di('PIEZA_CLASE',   (string)($p['img_error_clase'] ?? ''));
$di('PIEZA_JOB_AT',  (string)($p['img_job_at'] ?? ''));

$a = $pdo->prepare("SELECT estado, unidades, llamadas, costo_usd FROM crecer_img_cuota_asiento
                     WHERE marca_id=? ORDER BY id DESC LIMIT 1");
$a->execute([$marca]);
$as = $a->fetch(PDO::FETCH_ASSOC) ?: [];
$di('ASIENTO_ESTADO',   (string)($as['estado'] ?? '(ninguno)'));
$di('ASIENTO_LLAMADAS', (string)($as['llamadas'] ?? '0'));
