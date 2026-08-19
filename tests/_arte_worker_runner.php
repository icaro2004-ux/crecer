<?php
// ============================================================
//  CRECER — LANZADOR DEL WORKER DE ARTE PARA PRUEBAS
//  tests/_arte_worker_runner.php
//
//  No es una prueba: es el envoltorio que deja correr
//  panel/arte_worker.php DE VERDAD desde la línea de comandos.
//
//  Existe porque el worker termina con exit() en cada uno de sus
//  caminos: dentro del proceso de la prueba, el primer escenario
//  mataría a los demás. Cada escenario se corre aquí, en su propio
//  proceso, contra el archivo real — con su bucle, sus salidas y
//  sus notificaciones — y la prueba mira lo que quedó en la base.
//
//    php tests/_arte_worker_runner.php <marca> <pieza> <sondeos> [fb] [sim]
//
//  <sim> sustituye SOLO el borde de red al crear el job, que es lo único que
//  no se puede ejercitar sin gastar dinero: 'timeout' simula que la petición
//  se fue sin respuesta (incierto) y 'rechazo' que OpenAI contestó 400
//  (confirmado). La clasificación, el veredicto y lo que se hace con la pieza
//  son los de producción.
// ============================================================

$mid = (int)($argv[1] ?? 0);
$pid = (int)($argv[2] ?? 0);
$max = (int)($argv[3] ?? 3);
$fb  = (string)($argv[4] ?? '');
$sim = (string)($argv[5] ?? '');

if (!$mid || !$pid) { fwrite(STDERR, "uso: runner <marca> <pieza> <sondeos> [fb] [sim]\n"); exit(2); }

// Se declara ANTES de cargar ia.php, que la define bajo function_exists. Lo que
// se sustituye es la llamada a OpenAI, no la decisión que se está probando.
if ($sim !== '') {
    $GLOBALS['AW_SIM'] = $sim;
    function openai_responses_crear_bg(string $brief, array $opts = []): array {
        switch ($GLOBALS['AW_SIM'] ?? '') {
            // Se fue la petición y no volvió nada: pudo quedar trabajo creado.
            case 'timeout': throw new RuntimeException('cURL error 28: Operation timed out after 200000 ms');
            // OpenAI contestó que no. No quedó trabajo creado.
            case 'rechazo': throw new RuntimeException('Responses(bg): 400 invalid_request_error');
        }
        return ['id' => 'resp_sim_' . bin2hex(random_bytes(4)), 'modelo' => 'simulado', 'status' => 'queued'];
    }
}

// ── SIN CRÉDITOS, PASE LO QUE PASE ───────────────────────────
//  El defecto que se está probando es "llama al proveedor cuando no debe", y
//  config.local.php tiene llaves de verdad. Si la prueba corriera con el
//  defecto presente, lo demostraría gastando dinero.
//  Estas constantes se definen ANTES de que db.php cargue el config: define()
//  es primero-gana, así que las del config quedan en nada y todo camino que
//  intente cobrar falla en seco, sin red. La prueba puede fallar; no puede
//  facturar.
define('OPENAI_API_KEY', '');
define('GEMINI_API_KEY', '');
define('GCP_PROJECT_ID', '');
define('GOOGLE_APPLICATION_CREDENTIALS', '');

// ARTE_WORKER_TEST salta el apretón de manos HTTP y la llave del worker; el
// resto del archivo —que es lo que se quiere probar— corre igual que en prod.
define('ARTE_WORKER_TEST', true);
define('ARTE_POLL_MAX', $max);
define('ARTE_POLL_ESPERA', 0);   // sin esperas: el bucle es lo que importa, no el reloj

$_GET = ['marca' => (string)$mid, 'id' => (string)$pid, 'key' => 'prueba'];
if ($fb === '1') $_GET['fb'] = '1';

require dirname(__DIR__) . '/panel/arte_worker.php';
