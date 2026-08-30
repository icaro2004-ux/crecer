<?php
// ============================================================
//  CRECER — UN SONDEO MEDIDO, CON EL PROVEEDOR SIMULADO EN EL BORDE
//  tests/_latencia_runner.php
//
//  Hermano de _sondeo_runner.php, con otra pregunta. Aquel comprueba QUE se
//  decide; este comprueba CUANTO se espera despues de decidirlo — que es lo
//  que costo los ocho minutos de la pieza #667.
//
//  Sustituye SOLO ia_http_get_res (el borde de red del sondeo) y, cuando hace
//  falta, ia_http_post_retry (el borde de red del encolado). Todo lo demas
//  —img_poll_decidir, el lease, la cronologia, el cierre de la unidad— es el
//  codigo de produccion.
//
//    php tests/_latencia_runner.php <marca> <post> <caso> <dedicado>
//
//  casos:  vivo      el proveedor lo sostiene (in_progress)
//          completo  llega la imagen
//          caido     no se pudo consultar
//          encolar   ejercita el encolado y devuelve el CUERPO que se envio
//
//  Imprime lineas CLAVE=valor.
// ============================================================

$marca    = (int)($argv[1] ?? 0);
$post     = (int)($argv[2] ?? 0);
$caso     = (string)($argv[3] ?? 'vivo');
$dedicado = (string)($argv[4] ?? '0') === '1';

define('OPENAI_API_KEY', 'sk-prueba-la-red-esta-sustituida');
if (!defined('CRECER_TEST_RED_FALSA')) define('CRECER_TEST_RED_FALSA', true);

require __DIR__ . '/_sin_gasto.php';

function ia_http_get_res(string $url, array $headers): array {
    $GLOBALS['LAT_URLS'][] = $url;
    switch ($GLOBALS['LAT_CASO'] ?? '') {
        case 'completo':
            //  PNG 1x1 de verdad, para que se escriba un archivo real y el
            //  tramo de guardado se pueda medir.
            $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
            return ['code' => 200, 'body' => json_encode([
                'id' => 'resp_lat', 'status' => 'completed', 'model' => 'gpt-image-1',
                'output' => [['type' => 'image_generation_call', 'result' => $png, 'revised_prompt' => 'x']],
            ])];
        case 'caido':
            throw new IaError('HTTP GET: Could not resolve host');
        default:
            return ['code' => 200, 'body' => json_encode([
                'id' => 'resp_lat', 'status' => 'in_progress', 'model' => 'gpt-image-1', 'output' => [],
            ])];
    }
}

//  EL CUERPO DEL ENCOLADO, TAL CUAL SE ENVIA. Es lo que permite afirmar que
//  este arreglo NO toco la calidad, el modelo ni el brief: no se compara una
//  descripcion del cuerpo, se compara el cuerpo.
function ia_http_post_retry(string $url, array $headers, string $body, int $max = 4, int $timeout = 60): string {
    $GLOBALS['LAT_BODY'] = $body;
    return json_encode(['id' => 'resp_lat_' . bin2hex(random_bytes(4)), 'status' => 'queued']);
}

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/ia.php';
require_once __DIR__ . '/../includes/cuota_imagenes.php';
require_once __DIR__ . '/../includes/img_responses.php';

$GLOBALS['LAT_CASO'] = $caso;
$GLOBALS['LAT_URLS'] = [];

$di = fn(string $k, $v) => print($k . '=' . str_replace("\n", ' ', (string)$v) . "\n");

if ($caso === 'encolar') {
    $r = img_resp_encolar_res($pdo, $marca, $post, 'copy de prueba para el arte');
    $di('RES', $r['res']);
    $di('JOB', $r['job']);
    $di('CLASE', $r['clase']);
    $b = json_decode((string)($GLOBALS['LAT_BODY'] ?? '{}'), true) ?: [];
    $di('BODY_MODEL',   (string)($b['model'] ?? ''));
    $di('BODY_BG',      !empty($b['background']) ? '1' : '0');
    $di('BODY_QUALITY', (string)($b['tools'][0]['quality'] ?? ''));
    $di('BODY_SIZE',    (string)($b['tools'][0]['size'] ?? ''));
    $di('BODY_TOOL',    (string)($b['tools'][0]['type'] ?? ''));
    $di('BODY_BRIEF_LEN', is_string($b['input'] ?? null) ? strlen($b['input']) : -1);
    $c = img_cron_leer($pdo, $marca, $post);
    $di('CRON_JOB',      (string)($c['provider_job_id'] ?? ''));
    $di('CRON_INICIO',   (string)($c['request_started_at'] ?? ''));
    $di('CRON_ACEPTADO', (string)($c['provider_accepted_at'] ?? ''));
    $di('CRON_POLLS',    (int)($c['poll_count'] ?? -1));
    exit(0);
}

$t0 = microtime(true);
$r  = img_resp_completar($pdo, $marca, $post, $dedicado);
$di('ESTADO',   (string)($r['estado'] ?? ''));
$di('DIFERIDO', !empty($r['diferido']) ? '1' : '0');
$di('IMG',      (string)($r['img'] ?? ''));
$di('MS',       (int)round((microtime(true) - $t0) * 1000));

//  LA PUERTA QUE ACABA DE ESCRIBIR ESTE SONDEO. Es el numero del defecto:
//  cuantos segundos se queda el trabajo sin que NADIE pueda preguntarle al
//  proveedor — ni el worker dedicado, porque la puerta es la misma para todos.
$q = $pdo->prepare("SELECT img_estado, img_job, img_intentos, grafica_path,
                           TIMESTAMPDIFF(SECOND, NOW(), img_next_poll_at) AS puerta_seg
                      FROM crecer_contenido WHERE id=? AND marca_id=?");
$q->execute([$post, $marca]);
$f = $q->fetch(PDO::FETCH_ASSOC) ?: [];
$di('PUERTA_SEG',  $f['puerta_seg'] ?? 'NULL');
$di('INTENTOS',    $f['img_intentos'] ?? '');
$di('IMG_ESTADO',  $f['img_estado'] ?? '');
$di('IMG_JOB',     (string)($f['img_job'] ?? ''));
$di('GRAFICA',     (string)($f['grafica_path'] ?? ''));

$c = img_cron_leer($pdo, $marca, $post);
$di('CRON_JOB',       (string)($c['provider_job_id'] ?? ''));
$di('CRON_POLLS',     (int)($c['poll_count'] ?? -1));
$di('CRON_FIRST',     (string)($c['first_poll_at'] ?? ''));
$di('CRON_LAST',      (string)($c['last_poll_at'] ?? ''));
$di('CRON_COMPLETED', (string)($c['provider_completed_at'] ?? ''));
$di('CRON_DESCARGA',  (string)($c['download_started_at'] ?? ''));
$di('CRON_SAVED',     (string)($c['saved_at'] ?? ''));
$di('CRON_TOTAL_MS',  (string)($c['total_ms'] ?? ''));
$di('CRON_ESTADO',    (string)($c['estado'] ?? ''));
$di('LLAMADAS_RED',   count($GLOBALS['LAT_URLS']));
