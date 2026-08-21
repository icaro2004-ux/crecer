<?php
// ============================================================
//  CRECER — PRÓLOGO COMÚN DE LAS PRUEBAS QUE TOCAN PROVEEDORES
//  tests/_sin_gasto.php
//
//  Se requiere ANTES que db.php. Hace dos cosas:
//
//  1) Anula las llaves. define() es primero-gana, así que las de
//     config.local.php quedan en nada y ningún camino puede cobrar.
//     Los defectos de esta familia son "llama al proveedor cuando no
//     debe": sin esto, una prueba los demostraría gastando.
//
//  2) Si $GLOBALS['AW_SIM'] trae algo, sustituye SOLO el borde de red
//     al crear el job — lo único que no se puede ejercitar de verdad
//     sin pagar. ia.php define esa función bajo function_exists, así
//     que la de aquí gana. Todo lo demás (clasificación del fallo,
//     veredicto, estado de la pieza, qué hace el llamador) es el
//     código de producción.
//
//     'timeout' → la petición se fue y no volvió nada: INCIERTO.
//     'rechazo' → OpenAI contestó 400: RECHAZADO CONFIRMADO.
// ============================================================

// config.local.php redefine estas constantes y PHP avisa; ese aviso saldria
// por stdout y rompe cualquier header() posterior. Al log si, a la salida no.
@ini_set('display_errors', '0');

//  LA VALLA, NO SOLO LAS LLAVES EN BLANCO.
//  Las llaves vacias impiden que una llamada AUTENTIQUE, pero no impiden que
//  salga: hay caminos que llegan al curl igual. CRECER_TEST_MODE cierra la red
//  en los cuatro puntos de proveedor y en el transporte, lanzando ANTES del
//  curl. Un runner que necesite recorrer el camino completo declara ademas
//  CRECER_TEST_RED_FALSA y sustituye el transporte por uno suyo.
if (!defined('CRECER_TEST_MODE')) define('CRECER_TEST_MODE', true);

define('OPENAI_API_KEY', '');
define('GEMINI_API_KEY', '');
define('GCP_PROJECT_ID', '');
define('GOOGLE_APPLICATION_CREDENTIALS', '');

if (!empty($GLOBALS['AW_SIM'])) {
    function openai_responses_crear_bg(string $brief, array $opts = []): array {
        switch ($GLOBALS['AW_SIM'] ?? '') {
            case 'timeout': throw new RuntimeException('cURL error 28: Operation timed out after 200000 ms');
            case 'rechazo': throw new RuntimeException('Responses(bg): 400 invalid_request_error');
        }
        return ['id' => 'resp_sim_' . bin2hex(random_bytes(4)), 'modelo' => 'simulado', 'status' => 'queued'];
    }
}
