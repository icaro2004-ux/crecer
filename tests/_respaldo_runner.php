<?php
// ============================================================
//  CRECER — EL RESPALDO, CON LOS DOS BORDES DE RED SUSTITUIDOS
//  tests/_respaldo_runner.php
//
//  Reproduce el incidente de #656: el proveedor confirma que el trabajo A no
//  sale (failed + credit_balance_exhausted) y el respaldo se dispara.
//
//  El runner del sondeo sustituye SOLO ia_http_get_res(), que basta para
//  sondear. Aqui no basta: el camino del respaldo hace POST —crea trabajos,
//  pide imagenes— asi que se sustituyen LOS DOS bordes. Sin el POST sustituido
//  este runner saldria de verdad a api.openai.com con una llave falsa, y eso ya
//  no es una prueba: es una llamada al proveedor.
//
//    php tests/_respaldo_runner.php <marca> <post>
//
//  El transporte falso responde:
//    · GET  /v1/responses/<id>       → failed + credit_balance_exhausted
//    · POST /v1/responses            → un trabajo NUEVO (resp_B_...)   ← la trampa
//    · POST /v1/images/generations   → error (asi cae al otro motor)
//    · POST generativelanguage       → un PNG de 1x1 (Gemini entrega)
//
//  El id que devuelve el POST a /v1/responses es el sensor: si aparece en el
//  libro, es que alguien encolo OTRO trabajo de OpenAI sobre la misma unidad.
//
//  Imprime lineas CLAVE=valor.
// ============================================================

$marca = (int)($argv[1] ?? 0);
$post  = (int)($argv[2] ?? 0);

//  Llaves falsas ANTES de _sin_gasto.php: define() gana el primero. Hacen falta
//  para que openai_configurado() y ia_transporte() digan que si — si no, los
//  motores se rendirian por credenciales y no recorreriamos el camino. La red
//  no sale de aqui igual: los dos transportes estan sustituidos abajo.
define('OPENAI_API_KEY', 'sk-prueba-el-transporte-esta-sustituido');
define('GEMINI_API_KEY', 'prueba-el-transporte-esta-sustituido');

//  Este runner recorre el camino entero contra su propio doble. Se declara para
//  que la valla lo deje pasar — y solo a el.
if (!defined('CRECER_TEST_RED_FALSA')) define('CRECER_TEST_RED_FALSA', true);

require __DIR__ . '/_sin_gasto.php';

$GLOBALS['RED'] = [];          // toda salida, en orden
$GLOBALS['JOBS_NUEVOS'] = [];  // los trabajos que el doble llego a repartir

/** PNG de 1x1 real, para que el respaldo guarde un archivo de verdad. */
function _rr_png(): string {
    return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
}

function ia_http_post_retry(string $url, array $headers, string $body,
                            int $max_reintentos = 4, int $timeout = 60): string {
    $GLOBALS['RED'][] = 'POST ' . $url;

    //  P4 · crear trabajo en segundo plano. Reparte un id NUEVO a proposito:
    //  es el sensor del que habla la cabecera.
    if (strpos($url, '/v1/responses') !== false) {
        //  QUIEN lo pidio, no solo que se pidio. Deducir el camino leyendo el
        //  fuente es como se pierden las tardes: aqui queda la pila real.
        $pila = array_values(array_filter(array_map(fn($x) => $x['function'] ?? '',
                    debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12)),
                    fn($x) => $x !== '' && strpos($x, '{closure') === false));
        $GLOBALS['PILA_B'] = implode(' <- ', $pila);
        $id = 'resp_B_' . str_pad((string)(count($GLOBALS['JOBS_NUEVOS']) + 1), 3, '0', STR_PAD_LEFT);
        $GLOBALS['JOBS_NUEVOS'][] = $id;
        return json_encode(['id' => $id, 'status' => 'queued', 'model' => 'gpt-image-1']);
    }

    //  P2 · el motor viejo de OpenAI. Falla, para que el despachador pruebe el otro.
    if (strpos($url, '/v1/images/generations') !== false) {
        return json_encode(['error' => ['message' => 'Your credit balance is too low.',
                                        'type' => 'invalid_request_error',
                                        'code' => 'credit_balance_exhausted']]);
    }

    //  P1 · Gemini. Entrega.
    if (strpos($url, 'generativelanguage') !== false) {
        return json_encode(['candidates' => [['content' => ['parts' => [
            ['inlineData' => ['mimeType' => 'image/png', 'data' => _rr_png()]],
        ]]]]]);
    }

    //  Cualquier otra cosa (texto: director de arte, campana visual...) devuelve
    //  algo inocuo. Que el camino creativo degrade solo no es lo que se mide aqui.
    return json_encode(['candidates' => [['content' => ['parts' => [['text' => 'brief de prueba']]]]]]);
}

function ia_http_get_res(string $url, array $headers): array {
    $GLOBALS['RED'][] = 'GET ' . $url;
    //  El caso del incidente: HTTP 200, status failed, y el motivo dentro del
    //  mismo cuerpo. Terminal confirmado.
    return ['code' => 200, 'body' => json_encode([
        'id' => 'resp_A', 'status' => 'failed', 'output' => [],
        'error' => ['message' => 'Your credit balance is too low.',
                    'type'    => 'invalid_request_error',
                    'code'    => 'credit_balance_exhausted'],
    ])];
}

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/ia.php';
require_once __DIR__ . '/../includes/cuota_imagenes.php';
require_once __DIR__ . '/../includes/img_responses.php';

$di = fn(string $k, $v) => print($k . '=' . str_replace("\n", ' ', (string)$v) . "\n");

// ── 1 · La pieza tal como quedo el trabajo A: encolado y con su unidad ──
$pdo->prepare("UPDATE crecer_contenido
                  SET img_estado='queued', img_job='resp_A', grafica_path=NULL,
                      img_intentos=0, img_error_clase=NULL,
                      img_job_at=NOW(), img_next_poll_at=NULL
                WHERE id=? AND marca_id=?")->execute([$post, $marca]);

$c = CuotaImg::garantizar(CuotaCtx::de($pdo, $marca, 'arte_post', 'img_resp_encolar_res',
        ['origen_tipo' => 'contenido', 'origen_id' => $post, 'costo' => 0.17]),
    'P4 openai_responses_crear_bg');
CuotaImg::atarJob($pdo, $c->asiento_id, 'resp_A');
$asiento = $c->asiento_id;

$leer = function () use ($pdo, $asiento): array {
    $q = $pdo->prepare("SELECT estado, llamadas, costo_usd, provider_job_id
                          FROM crecer_img_cuota_asiento WHERE id=?");
    $q->execute([$asiento]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: [];
};

$a0 = $leer();
$di('ASIENTO', $asiento);
$di('JOB_A', (string)($a0['provider_job_id'] ?? ''));
$di('LLAMADAS_ANTES', (string)($a0['llamadas'] ?? '0'));
$di('COSTO_ANTES', (string)($a0['costo_usd'] ?? '0'));

// ── 2 · El sondeo. El proveedor confirma que A no sale ──
$r = img_resp_completar($pdo, $marca, $post, true);
$q = $pdo->prepare("SELECT img_job, img_error_clase FROM crecer_contenido WHERE id=? AND marca_id=?");
$q->execute([$post, $marca]);
$p1 = $q->fetch(PDO::FETCH_ASSOC) ?: [];
$di('SONDEO_ESTADO', (string)($r['estado'] ?? ''));
$di('TRAS_SONDEO_JOB', (string)($p1['img_job'] ?? ''));
$di('TRAS_SONDEO_CLASE', (string)($p1['img_error_clase'] ?? ''));

// ── 3 · El respaldo, por donde lo llama el barrido ──
$url = img_gemini_fallback($pdo, $marca, $post, 'copy de prueba');

$a1 = $leer();
$q->execute([$post, $marca]);
$q2 = $pdo->prepare("SELECT img_job, img_error_clase, img_estado, grafica_path
                       FROM crecer_contenido WHERE id=? AND marca_id=?");
$q2->execute([$post, $marca]);
$p2 = $q2->fetch(PDO::FETCH_ASSOC) ?: [];

$di('FB_URL', $url);
$di('PIEZA_ESTADO', (string)($p2['img_estado'] ?? ''));
$di('PIEZA_GRAFICA', (string)($p2['grafica_path'] ?? ''));
$di('PIEZA_CLASE', (string)($p2['img_error_clase'] ?? ''));

$di('ASIENTO_ESTADO', (string)($a1['estado'] ?? ''));
$di('LLAMADAS_DESPUES', (string)($a1['llamadas'] ?? '0'));
$di('COSTO_DESPUES', (string)($a1['costo_usd'] ?? '0'));
$di('PROVIDER_JOB_DESPUES', (string)($a1['provider_job_id'] ?? ''));

$di('ASIENTOS_TOTAL', (int)$pdo->query("SELECT COUNT(*) FROM crecer_img_cuota_asiento
                                          WHERE marca_id={$marca}")->fetchColumn());

//  Los sensores: cuantos trabajos nuevos de OpenAI se repartieron, y por que
//  puntos de proveedor se paso.
$di('JOBS_NUEVOS', implode(',', $GLOBALS['JOBS_NUEVOS']));
$di('N_JOBS_NUEVOS', count($GLOBALS['JOBS_NUEVOS']));
$di('PILA_B', $GLOBALS['PILA_B'] ?? '(ninguna)');
$di('POST_RESPONSES', count(array_filter($GLOBALS['RED'], fn($u) => strpos($u, 'POST') === 0 && strpos($u, '/v1/responses') !== false)));
$di('POST_OPENAI_IMG', count(array_filter($GLOBALS['RED'], fn($u) => strpos($u, '/v1/images/generations') !== false)));
//  Texto e imagen salen por el MISMO host. Solo cuenta como respaldo la que
//  pide una IMAGEN: el modelo va en la URL y los de imagen lo llevan en el
//  nombre. Sin separarlos, el director de arte inflaba el contador.
$di('POST_GEMINI_IMG', count(array_filter($GLOBALS['RED'], fn($u) => strpos($u, 'image:generateContent') !== false)));
$di('POST_GEMINI_TXT', count(array_filter($GLOBALS['RED'], fn($u) => strpos($u, 'generativelanguage') !== false
                                              && strpos($u, 'image:generateContent') === false)));
$di('SALIDAS_TOTAL', count($GLOBALS['RED']));
