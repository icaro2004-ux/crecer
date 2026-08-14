<?php
// ============================================================
//  CRECER — Núcleo agéntico: llamada a Gemini + logging
//  includes/ia.php
//
//  CORAZÓN del criterio #2 del concurso (operado por agentes de
//  IA, con evidencia). TODA llamada a un modelo pasa por
//  ia_ejecutar(), que la registra en crecer_ia_log pase lo que
//  pase (éxito, error o modo mock).
//
//  Transportes soportados (auto-detectados por config):
//    1) Gemini API (AI Studio)  — si GEMINI_API_KEY está definida.
//    2) Vertex AI (service acct) — si GOOGLE_APPLICATION_CREDENTIALS
//       apunta a un JSON válido + GCP_PROJECT_ID.
//    3) mock                     — si no hay creds (solo para probar
//       el pipeline de logging; NUNCA es evidencia real para jurado).
//
//  Requiere que db.php ya se haya cargado ($pdo disponible) y las
//  constantes de config (GEMINI_MODEL, GCP_*, GEMINI_API_KEY).
// ============================================================

if (!defined('GEMINI_MODEL'))  define('GEMINI_MODEL', 'gemini-2.5-flash');
if (!defined('GCP_LOCATION'))  define('GCP_LOCATION', 'us-central1');
if (!defined('GEMINI_API_KEY')) define('GEMINI_API_KEY', '');
if (!defined('GCP_PROJECT_ID')) define('GCP_PROJECT_ID', '');
if (!defined('GOOGLE_APPLICATION_CREDENTIALS')) define('GOOGLE_APPLICATION_CREDENTIALS', '');

// Precio aprox por 1M tokens (USD). Ajustar según pricing vigente.
//
// ESTA TABLA ALIMENTA LA EVIDENCIA DE LA ENTREGA (el costo acumulado de IA que
// se reporta como gasto), así que un hueco aquí no es un detalle: sub-reportar
// gastos infla el margen, que es justo el numerito que un jurado puede cruzar
// contra la factura del proveedor.
//
// Faltaban 2.5-pro y 2.5-flash-lite, que el código sí usa — sus llamadas se
// registraban a CERO. Añadidos (2026-08-14).
const CRECER_IA_PRECIOS = [
    'gemini-2.0-flash'      => ['in' => 0.10,  'out' => 0.40],
    'gemini-2.5-flash'      => ['in' => 0.30,  'out' => 2.50],
    'gemini-2.5-flash-lite' => ['in' => 0.10,  'out' => 0.40],
    'gemini-1.5-flash'      => ['in' => 0.075, 'out' => 0.30],
    'gemini-2.5-pro'        => ['in' => 1.25,  'out' => 10.00],
];

// Modelo desconocido: se cobra al precio del MÁS CARO que conocemos, no a cero.
// Antes devolvía 0.0 en silencio, así que un modelo nuevo desaparecía de los
// gastos sin que nadie se enterara. Ante la duda conviene pasarse de caro: un
// gasto sobreestimado te hace ver peor, y por eso nadie lo falsea así. La cifra
// de verdad sale de la factura del proveedor; esto es el reloj interno.
const CRECER_IA_PRECIO_DESCONOCIDO = ['in' => 1.25, 'out' => 10.00];

class IaSinCredenciales extends RuntimeException {}
class IaError extends RuntimeException {}

/**
 * Detecta qué transporte usar según las credenciales disponibles.
 * @return string 'gemini_api' | 'vertex' | 'mock'
 */
function ia_transporte(): string {
    if (GEMINI_API_KEY !== '') return 'gemini_api';
    if (GCP_PROJECT_ID !== '' && GOOGLE_APPLICATION_CREDENTIALS !== ''
        && is_file(GOOGLE_APPLICATION_CREDENTIALS)) return 'vertex';
    return 'mock';
}

/**
 * Calcula el costo en USD a partir de los tokens y el modelo.
 */
function ia_costo(string $modelo, int $tokens_in, int $tokens_out): float {
    $p = CRECER_IA_PRECIOS[$modelo] ?? null;
    if (!$p) {
        // Nunca cero por desconocer el modelo: deja rastro y cobra caro.
        error_log("ia_costo: modelo sin precio en la tabla ('{$modelo}') — "
                . "se estima al precio alto. Anadelo a CRECER_IA_PRECIOS.");
        $p = CRECER_IA_PRECIO_DESCONOCIDO;
    }
    return round(($tokens_in / 1_000_000) * $p['in']
               + ($tokens_out / 1_000_000) * $p['out'], 6);
}

/**
 * Llama a Gemini y devuelve la respuesta normalizada.
 *
 * @return array{texto:string, tokens_in:int, tokens_out:int, modelo:string, transporte:string}
 * @throws IaSinCredenciales si no hay creds (modo mock lo maneja el caller)
 * @throws IaError en fallo de red/HTTP
 */
function gemini_generar(string $prompt, array $opts = []): array {
    $modelo     = $opts['modelo'] ?? GEMINI_MODEL;
    $temperatura = $opts['temperatura'] ?? 0.9;
    $transporte = ia_transporte();

    if ($transporte === 'mock') {
        throw new IaSinCredenciales('Sin credenciales de Gemini/Vertex; usa modo mock.');
    }

    if ($transporte === 'gemini_api') {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/"
             . rawurlencode($modelo) . ":generateContent?key=" . rawurlencode(GEMINI_API_KEY);
        $headers = ['Content-Type: application/json'];
    } else { // vertex
        $token = vertex_access_token();
        $url = "https://" . GCP_LOCATION . "-aiplatform.googleapis.com/v1/projects/"
             . GCP_PROJECT_ID . "/locations/" . GCP_LOCATION
             . "/publishers/google/models/" . rawurlencode($modelo) . ":generateContent";
        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $token];
    }

    // Parts: texto + (opcional) audio de entrada (multimodal — la voz del dueño).
    $parts = [['text' => $prompt]];
    if (!empty($opts['audio']['data'])) {
        // El navegador reporta cosas como 'audio/webm;codecs=opus' — Gemini quiere
        // el tipo LIMPIO. Se normaliza AQUÍ para todo el que mande audio (la
        // entrevista, el wizard viejo, la prueba viva). Era la causa probable de
        // que la voz del onboarding original "nunca funcionara".
        $mimeAudio = strtolower(trim(explode(';', (string)($opts['audio']['mime'] ?? 'audio/webm'))[0]));
        if ($mimeAudio === '') $mimeAudio = 'audio/webm';
        if ($mimeAudio === 'audio/x-m4a') $mimeAudio = 'audio/mp4';   // Safari/iOS
        $parts[] = ['inlineData' => [
            'mimeType' => $mimeAudio,
            'data'     => $opts['audio']['data'],   // base64
        ]];
    }
    // Imágenes de entrada (visión → texto): ej. aprender la línea de diseño de las
    // gráficas que el cliente aprobó.
    if (!empty($opts['imagenes']) && is_array($opts['imagenes'])) {
        foreach ($opts['imagenes'] as $im) {
            if (!empty($im['data'])) $parts[] = ['inlineData' => ['mimeType' => $im['mime'] ?? 'image/jpeg', 'data' => $im['data']]];
        }
    }
    $payload = [
        'contents' => [[
            'role'  => 'user',
            'parts' => $parts,
        ]],
        'generationConfig' => [
            'temperature'     => $temperatura,
            'maxOutputTokens' => $opts['max_tokens'] ?? 1024,
        ],
    ];
    if (!empty($opts['sistema'])) {
        $payload['systemInstruction'] = ['parts' => [['text' => $opts['sistema']]]];
    }
    // Modo JSON: fuerza a Gemini a devolver SOLO JSON válido (sin ```).
    if (!empty($opts['json'])) {
        $payload['generationConfig']['responseMimeType'] = 'application/json';
    }
    // Control del "pensamiento" (modelos 2.5): thinking_budget=0 lo apaga.
    // Útil en tareas estructuradas: más rápido, barato y sin truncar la salida.
    if (array_key_exists('thinking_budget', $opts)) {
        $payload['generationConfig']['thinkingConfig'] = [
            'thinkingBudget' => (int)$opts['thinking_budget'],
        ];
    }

    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $resp = ia_http_post_retry($url, $headers, $body, $opts['max_reintentos'] ?? 4);
    $data = json_decode($resp, true);
    if (!is_array($data)) {
        throw new IaError('Respuesta no-JSON de Gemini: ' . substr($resp, 0, 300));
    }
    if (isset($data['error'])) {
        throw new IaError('Gemini error: ' . ($data['error']['message'] ?? json_encode($data['error'])));
    }

    $texto = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $usage = $data['usageMetadata'] ?? [];

    return [
        'texto'      => $texto,
        'tokens_in'  => (int)($usage['promptTokenCount'] ?? 0),
        'tokens_out' => (int)($usage['candidatesTokenCount'] ?? 0),
        'modelo'     => $modelo,
        'transporte' => $transporte,
    ];
}

/**
 * POST HTTP con cURL. Devuelve el body; lanza IaError en fallo.
 */
function ia_http_post(string $url, array $headers, string $body, int $timeout = 60): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new IaError('cURL: ' . $err);
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) {
        throw new IaError("HTTP $code: " . substr($resp, 0, 500));
    }
    return $resp;
}

/**
 * POST con reintentos ante HTTP 429 (rate limit). Honra el retryDelay
 * que sugiere Google si viene; si no, usa backoff exponencial.
 * Así el sistema agéntico tolera el límite de velocidad por sí solo.
 */
function ia_http_post_retry(string $url, array $headers, string $body, int $max_reintentos = 4, int $timeout = 60): string {
    $intento = 0;
    while (true) {
        try {
            return ia_http_post($url, $headers, $body, $timeout);
        } catch (IaError $e) {
            $msg = $e->getMessage();
            $es_429 = strpos($msg, 'HTTP 429') !== false;
            if (!$es_429 || $intento >= $max_reintentos) {
                throw $e;
            }
            // Buscar "retry in 17.8s" o "retryDelay": "18s"; si no, backoff.
            $espera = 0;
            if (preg_match('/retry in ([\d.]+)s/i', $msg, $m)
                || preg_match('/"?retryDelay"?\s*:\s*"?([\d.]+)s/i', $msg, $m)) {
                $espera = (float)$m[1];
            }
            if ($espera <= 0) $espera = min(60, 2 ** $intento);   // backoff exponencial
            $espera = min(65, $espera + 1);                        // +1s de colchón, tope 65s
            error_log("ia: 429 rate limit; reintento " . ($intento + 1) . "/$max_reintentos en {$espera}s");
            usleep((int)($espera * 1_000_000));
            $intento++;
        }
    }
}

/**
 * Genera un OAuth access token para Vertex AI desde el service
 * account JSON (firma un JWT RS256 y lo intercambia). Sin librerías.
 */
function vertex_access_token(): string {
    $json = json_decode((string)file_get_contents(GOOGLE_APPLICATION_CREDENTIALS), true);
    if (!isset($json['client_email'], $json['private_key'])) {
        throw new IaError('service account JSON inválido.');
    }
    $now = time();
    $claim = [
        'iss'   => $json['client_email'],
        'scope' => 'https://www.googleapis.com/auth/cloud-platform',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ];
    $b64 = fn($d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
    $jwt_head = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $jwt_body = $b64(json_encode($claim));
    $sig = '';
    openssl_sign("$jwt_head.$jwt_body", $sig, $json['private_key'], 'sha256');
    $jwt = "$jwt_head.$jwt_body." . $b64($sig);

    $resp = ia_http_post('https://oauth2.googleapis.com/token',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]));
    $tok = json_decode($resp, true);
    if (empty($tok['access_token'])) {
        throw new IaError('No se obtuvo access_token de Vertex.');
    }
    return $tok['access_token'];
}

/**
 * EL WRAPPER AGÉNTICO. Ejecuta una llamada a Gemini y la registra
 * en crecer_ia_log SIEMPRE (éxito, error o mock).
 *
 * @param PDO    $pdo
 * @param string $agente   planificador|creador|responder|intake...
 * @param string $accion   qué decide/hace el agente (legible)
 * @param string $prompt
 * @param array  $opts      modelo, temperatura, sistema, marca_id, mock_texto
 * @return array{texto:string, ia_log_id:int, estado:string, transporte:string, tokens_in:int, tokens_out:int, costo:float}
 */
function ia_ejecutar(PDO $pdo, string $agente, string $accion, string $prompt, array $opts = []): array {
    $marca_id = $opts['marca_id'] ?? null;
    $t0 = microtime(true);

    $estado = 'ok';
    $error_msg = null;
    $texto = '';
    $tokens_in = 0;
    $tokens_out = 0;
    $transporte = ia_transporte();
    $modelo = $opts['modelo'] ?? GEMINI_MODEL;

    try {
        $r = gemini_generar($prompt, $opts);
        $texto      = $r['texto'];
        $tokens_in  = $r['tokens_in'];
        $tokens_out = $r['tokens_out'];
        $modelo     = $r['modelo'];
        $transporte = $r['transporte'];
    } catch (IaSinCredenciales $e) {
        // Modo mock: respuesta simulada para probar el pipeline.
        // modelo='mock' deja claro que NO es evidencia real.
        $transporte = 'mock';
        $modelo     = 'mock';
        $texto      = $opts['mock_texto'] ?? '[MOCK] (sin credenciales — respuesta simulada)';
        $tokens_in  = (int)ceil(mb_strlen($prompt) / 4);
        $tokens_out = (int)ceil(mb_strlen($texto) / 4);
    } catch (IaError $e) {
        $estado    = 'error';
        $error_msg = $e->getMessage();
    }

    $latencia_ms = (int)round((microtime(true) - $t0) * 1000);
    $costo = ia_costo($modelo, $tokens_in, $tokens_out);

    $stmt = $pdo->prepare(
        "INSERT INTO crecer_ia_log
           (marca_id, agente, accion, modelo, prompt, respuesta,
            tokens_in, tokens_out, costo_usd, latencia_ms, estado, error_msg)
         VALUES
           (:marca_id, :agente, :accion, :modelo, :prompt, :respuesta,
            :tokens_in, :tokens_out, :costo, :latencia, :estado, :error_msg)"
    );
    $stmt->execute([
        ':marca_id'   => $marca_id,
        ':agente'     => $agente,
        ':accion'     => $accion,
        ':modelo'     => $modelo,
        ':prompt'     => $prompt,
        ':respuesta'  => $texto,
        ':tokens_in'  => $tokens_in,
        ':tokens_out' => $tokens_out,
        ':costo'      => $costo,
        ':latencia'   => $latencia_ms,
        ':estado'     => $estado,
        ':error_msg'  => $error_msg,
    ]);
    $ia_log_id = (int)$pdo->lastInsertId();

    if ($estado === 'error') {
        throw new IaError("[ia_log #$ia_log_id] $error_msg");
    }

    return [
        'texto'      => $texto,
        'ia_log_id'  => $ia_log_id,
        'estado'     => $estado,
        'transporte' => $transporte,
        'modelo'     => $modelo,
        'tokens_in'  => $tokens_in,
        'tokens_out' => $tokens_out,
        'costo'      => $costo,
    ];
}

// ============================================================
//  IMÁGENES — generación con Gemini (Nano Banana) + logging
// ============================================================
const CRECER_IMG_PRECIO = 0.039; // USD aprox por imagen (gemini-2.5-flash-image)

/**
 * Genera (o edita) una imagen con Gemini. Si pasas imagen_base64, la usa de
 * base (editar la foto real del negocio). Devuelve ['data'=>bytes,'mime','modelo'].
 */
/**
 * VETO DE PROPIEDAD AJENA — se aplica en el ÚLTIMO punto antes de llamar al modelo,
 * no en cada prompt. Hay cuatro caminos que arman prompts de imagen por su cuenta
 * (img_resp_brief, el director de agentes.php, direccion_arte.php y gen_async), y el
 * quinto que se escriba nacería sin la regla. Poniéndolo aquí no se puede saltar.
 *
 * Pasó en producción (2026-08-14): el primer post de una repostería infantil salió
 * con un bizcocho de Superman, muñequitos y escudo incluidos. Quien lo publica —y
 * quien queda expuesto— es el dueño del negocio. La regla de IP del proyecto cubría
 * las FOTOS de terceros; este es el mismo problema por el lado de lo generado.
 *
 * Va en inglés porque los prompts de imagen del proyecto ya se escriben en inglés.
 */
function ia_veto_ip(string $prompt): string {
    return rtrim($prompt) . "\n\nHARD CONSTRAINT — no third-party IP: do not depict recognizable "
         . "copyrighted or trademarked characters, mascots, logos, emblems, packaging or brands "
         . "(superheroes, cartoon characters, princesses, movie or TV characters, sports teams, "
         . "franchises). If the subject calls for a themed or children's motif, use original, generic "
         . "elements instead: colors, balloons, confetti, shapes and your own figures.";
}

function gemini_imagen(string $prompt, array $opts = []): array {
    $prompt = ia_veto_ip($prompt);
    $modelo = $opts['modelo'] ?? 'gemini-2.5-flash-image';
    if (ia_transporte() !== 'gemini_api') {
        throw new IaSinCredenciales('Generación de imágenes requiere GEMINI_API_KEY.');
    }
    $url = "https://generativelanguage.googleapis.com/v1beta/models/"
         . rawurlencode($modelo) . ":generateContent?key=" . rawurlencode(GEMINI_API_KEY);
    $parts = [['text' => $prompt]];
    // Una imagen de entrada (compat) …
    if (!empty($opts['imagen_base64'])) {
        $parts[] = ['inlineData' => ['mimeType' => $opts['imagen_mime'] ?? 'image/jpeg', 'data' => $opts['imagen_base64']]];
    }
    // … o varias (ej. foto del producto + logo de la marca).
    if (!empty($opts['imagenes']) && is_array($opts['imagenes'])) {
        foreach ($opts['imagenes'] as $im) {
            if (!empty($im['data'])) $parts[] = ['inlineData' => ['mimeType' => $im['mime'] ?? 'image/jpeg', 'data' => $im['data']]];
        }
    }
    // Encuadre: si viene 'aspect' (ej. "1:1", "4:5", "9:16") se lo pedimos al modelo.
    // Con fallback: si este modelo/endpoint no acepta imageConfig, reintenta sin él
    // para no romper la generación.
    $aspect = $opts['aspect'] ?? null;
    $mk = function (bool $conConfig) use ($parts, $aspect) {
        $gc = ['responseModalities' => ['IMAGE']];
        if ($conConfig && $aspect) $gc['imageConfig'] = ['aspectRatio' => $aspect];
        return ['contents' => [['role'=>'user','parts'=>$parts]], 'generationConfig' => $gc];
    };
    $resp = ia_http_post_retry($url, ['Content-Type: application/json'], json_encode($mk(true), JSON_UNESCAPED_UNICODE), $opts['max_reintentos'] ?? 3);
    $d = json_decode($resp, true);
    if (isset($d['error']) && $aspect) {   // el aspectRatio no gustó → reintentar sin él
        $resp = ia_http_post_retry($url, ['Content-Type: application/json'], json_encode($mk(false), JSON_UNESCAPED_UNICODE), 1);
        $d = json_decode($resp, true);
    }
    if (isset($d['error'])) throw new IaError('Gemini imagen: ' . ($d['error']['message'] ?? 'error'));
    foreach (($d['candidates'][0]['content']['parts'] ?? []) as $p) {
        if (isset($p['inlineData']['data'])) {
            return ['data' => base64_decode($p['inlineData']['data']), 'mime' => $p['inlineData']['mimeType'] ?? 'image/png', 'modelo' => $modelo];
        }
    }
    throw new IaError('Gemini no devolvió imagen.');
}

// ── Motor de imagen OpenAI (opcional) ────────────────────────────────────
//  El Director de Arte (Gemini) puede enrutar aquí el arte conceptual desde
//  cero. Si no hay OPENAI_API_KEY, TODO cae a Gemini (degrada con gracia).
if (!defined('OPENAI_API_KEY'))   define('OPENAI_API_KEY', '');
if (!defined('OPENAI_IMG_MODEL')) define('OPENAI_IMG_MODEL', 'gpt-image-1');   // el MÁS cabrón (el que usa ChatGPT). dall-e-3 = respaldo sin verificación de org
// Calidad de gpt-image-1: low|medium|high|auto. 'high' = la buena ("otro nivel"),
// ~$0.17 por imagen 1:1. Reversible a 'medium' (~$0.04) si hay que bajar costo.
if (!defined('OPENAI_IMG_QUALITY')) define('OPENAI_IMG_QUALITY', 'high');
// Arquitectura del prompt de imagen: v1 = pipeline de agentes (direccion_arte.php);
// v2 = un SOLO cerebro creativo (image_messenger.php → IMAGE_CREATIVE_MODEL dirige).
if (!defined('IMAGE_PIPELINE'))       define('IMAGE_PIPELINE', 'v1');
// Quién dirige la creatividad en v2 — por PERFIL LÓGICO, no por nombre de modelo:
// 'openai:creative' | 'openai:fast' | 'gemini:creative' | 'gemini:fast'. El mapping a
// modelos concretos vive en resolver_modelo_ia() (un solo lugar que se cambia cuando
// OpenAI/Gemini saquen algo mejor, sin tocar el resto del sistema).
if (!defined('IMAGE_CREATIVE_MODEL')) define('IMAGE_CREATIVE_MODEL', 'openai:creative');

// MOTOR DE IMAGEN DE PRODUCCIÓN. 'responses' = el modelo se dirige solo (Responses API →
// gpt-image-2), background+polling — ganó 3/3 pruebas ciegas. 'actual' = motor viejo
// (director→gpt-image-1). Reversible: pon 'actual' en config.local.php para volver atrás.
if (!defined('IMAGE_ENGINE')) define('IMAGE_ENGINE', 'responses');

/**
 * Resuelve un PERFIL LÓGICO ('openai:creative') al modelo concreto de HOY
 * ('openai:gpt-4o'). El código nunca conoce nombres de modelo: solo perfiles.
 * Cuando salga un modelo mejor, se cambia AQUÍ (o vía IMAGE_MODEL_MAP en config) y
 * todo el sistema sigue igual. Si ya viene un modelo concreto, se pasa tal cual.
 */
function resolver_modelo_ia(string $cfg): string {
    $cfg = trim($cfg); $key = strtolower($cfg);
    // PIN manual opcional (si quieres fijar un modelo exacto y NO auto-actualizar):
    //   define('IMAGE_MODEL_MAP', '{"openai:creative":"gpt-6"}');
    if (defined('IMAGE_MODEL_MAP') && IMAGE_MODEL_MAP !== '') {
        $j = json_decode(IMAGE_MODEL_MAP, true);
        if (is_array($j) && isset($j[$key]) && $j[$key] !== '') {
            return explode(':', $cfg, 2)[0] . ':' . $j[$key];
        }
    }
    // Perfiles OpenAI → SIEMPRE la última versión disponible (auto, cache 24h).
    if ($key === 'openai:creative') return 'openai:' . openai_modelo_ultimo('creative');
    if ($key === 'openai:fast')     return 'openai:' . openai_modelo_ultimo('fast');
    // Perfiles Gemini → mapping estático (ajustable).
    $gem = ['gemini:creative' => 'gemini-2.5-pro', 'gemini:fast' => 'gemini-2.5-flash'];
    if (isset($gem[$key])) return 'gemini:' . $gem[$key];
    return $cfg;   // ya es 'proveedor:modelo-concreto' → tal cual (retrocompatible / pin directo)
}

/** GET HTTP simple (para consultar /v1/models). */
function ia_http_get(string $url, array $headers): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $r = curl_exec($ch);
    if ($r === false) { $e = curl_error($ch); curl_close($ch); throw new IaError('HTTP GET: ' . $e); }
    curl_close($ch);
    return (string)$r;
}

/**
 * Devuelve el ÚLTIMO modelo de chat de OpenAI disponible — auto-actualiza: siempre la
 * versión más nueva. Consulta /v1/models 1 vez al día (cache) y elige el gpt-N más alto.
 * 'creative' = el tope; 'fast' = el mini más nuevo. Si la API falla, cae a un default.
 */
function openai_modelo_ultimo(string $perfil = 'creative'): string {
    $fallback  = $perfil === 'fast' ? 'gpt-5-mini' : 'gpt-5.5';
    $cacheFile = __DIR__ . '/../storage/cache/openai_models_v2.json';   // v2: busta el cache del picker viejo (que agarró gpt-5.6-sol)
    $cache = @json_decode((string)@file_get_contents($cacheFile), true);
    if (is_array($cache) && isset($cache['ts'], $cache[$perfil]) && (time() - (int)$cache['ts'] < 86400) && $cache[$perfil] !== '') {
        return $cache[$perfil];
    }
    if (!openai_configurado()) return $fallback;
    try {
        $resp = ia_http_get('https://api.openai.com/v1/models', ['Authorization: Bearer ' . OPENAI_API_KEY]);
        $d = json_decode($resp, true);
        if (!is_array($d) || empty($d['data'])) return $fallback;
        $ids = array_map(fn($x) => (string)($x['id'] ?? ''), (array)$d['data']);
        $out = [
            'ts'       => time(),
            'creative' => _openai_pick_modelo($ids, false) ?: $fallback,
            'fast'     => _openai_pick_modelo($ids, true)  ?: 'gpt-5-mini',
        ];
        @mkdir(dirname($cacheFile), 0775, true);
        @file_put_contents($cacheFile, json_encode($out));
        return $out[$perfil] ?? $fallback;
    } catch (Throwable $e) { error_log('openai_modelo_ultimo: ' . $e->getMessage()); return $fallback; }
}

/** Elige el gpt-N de CHAT más nuevo de una lista de ids ($mini=true → el mini más nuevo). */
function _openai_pick_modelo(array $ids, bool $mini): string {
    $excluir = ['preview','audio','realtime','search','instruct','transcribe','tts','embedding','moderation','image','vision'];
    $best = ''; $bestv = -1.0;
    foreach ($ids as $id) {
        $id = strtolower(trim($id));
        // Solo ids LIMPIOS de chat: gpt-5.5 · gpt-4o · gpt-5 · gpt-4o-mini · gpt-4o-2024-08-06.
        // Rechaza variantes raras (gpt-5.6-sol, gpt-4o-audio, etc.) que rompen chat/completions.
        if (!preg_match('/^gpt-(\d+)(?:\.(\d+))?o?(?:-(?:mini|nano))?(?:-\d{4}-\d{2}-\d{2})?$/', $id, $mm)) continue;
        $esMini = (strpos($id, 'mini') !== false || strpos($id, 'nano') !== false);
        if ($mini !== $esMini) continue;
        foreach ($excluir as $b) { if (strpos($id, $b) !== false) continue 2; }
        $v = (float)$mm[1] + (isset($mm[2]) ? ((float)$mm[2]) / 10.0 : 0.0);   // major.minor
        // A igual versión, prefiere el id más corto (el alias que auto-actualiza, no el fechado).
        if ($v > $bestv || ($v === $bestv && ($best === '' || strlen($id) < strlen($best)))) { $bestv = $v; $best = $id; }
    }
    return $best;
}

/**
 * Chat de OpenAI (para el Director Creativo de v2). Devuelve el texto + tokens.
 * Reusa OPENAI_API_KEY + el transporte con reintentos.
 */
function openai_chat(string $sistema, string $mensaje, string $modelo = 'gpt-4o', array $opts = []): array {
    if (!openai_configurado()) throw new IaSinCredenciales('Falta OPENAI_API_KEY.');
    // Contenido del user: texto, o texto + imágenes (visión) si $opts['imagenes'].
    $userContent = $mensaje;
    if (!empty($opts['imagenes']) && is_array($opts['imagenes'])) {
        $userContent = [['type' => 'text', 'text' => $mensaje]];
        foreach ($opts['imagenes'] as $im) {
            $data = $im['data'] ?? ''; $mime = $im['mime'] ?? 'image/png';
            if ($data !== '') $userContent[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . $data]];
        }
    }
    $body = [
        'model'    => $modelo,
        'messages' => [
            ['role' => 'system', 'content' => $sistema],
            ['role' => 'user',   'content' => $userContent],
        ],
        // max_completion_tokens (NO 'max_tokens') → compatible con gpt-5.x / o-series.
        // 'temperature' se OMITE: los modelos nuevos rechazan valores != 1 (HTTP 400).
        'max_completion_tokens' => (int)($opts['max_tokens'] ?? 1200),
    ];
    $resp = ia_http_post_retry('https://api.openai.com/v1/chat/completions',
        ['Content-Type: application/json', 'Authorization: Bearer ' . OPENAI_API_KEY],
        json_encode($body, JSON_UNESCAPED_UNICODE), $opts['max_reintentos'] ?? 2);
    $d = json_decode($resp, true);
    if (!is_array($d)) throw new IaError('Respuesta no-JSON de OpenAI chat: ' . substr($resp, 0, 300));
    if (isset($d['error'])) throw new IaError('OpenAI chat: ' . ($d['error']['message'] ?? 'error'));
    $usage = $d['usage'] ?? [];
    return [
        'texto'      => trim((string)($d['choices'][0]['message']['content'] ?? '')),
        'tokens_in'  => (int)($usage['prompt_tokens'] ?? 0),
        'tokens_out' => (int)($usage['completion_tokens'] ?? 0),
        'modelo'     => $modelo,
    ];
}

function openai_configurado(): bool { return OPENAI_API_KEY !== ''; }

/** Extrae la imagen (b64) de una respuesta JSON de OpenAI. */
function openai_extraer_img(string $resp, string $modelo): array {
    $d = json_decode($resp, true);
    if (isset($d['error'])) throw new IaError('OpenAI imagen: ' . ($d['error']['message'] ?? 'error'));
    $b64 = $d['data'][0]['b64_json'] ?? '';
    if ($b64 === '') throw new IaError('OpenAI no devolvió imagen.');
    return ['data'=>base64_decode($b64), 'mime'=>'image/png', 'modelo'=>$modelo];
}

/**
 * Genera una imagen con OpenAI. gpt-image-1 a calidad ALTA ("otro nivel").
 * Si llega una imagen de entrada (típicamente el LOGO), usa /images/edits para
 * incorporarla al arte (la recorta/adapta); si no, /images/generations desde cero.
 * dall-e-3 = respaldo sin verificación de organización.
 * Devuelve ['data','mime','modelo'].
 */
function openai_imagen(string $prompt, array $opts = []): array {
    if (!openai_configurado()) throw new IaSinCredenciales('Falta OPENAI_API_KEY.');
    $prompt   = ia_veto_ip($prompt);
    $modelo   = $opts['modelo_openai'] ?? OPENAI_IMG_MODEL;
    $aspect   = $opts['aspect'] ?? '1:1';
    $es_dalle = (stripos($modelo, 'dall-e') !== false);

    if ($es_dalle) {   // dall-e-3: solo generación (sin edits ni verificación de org); hd + vivid = lo máximo
        $size = ['1:1'=>'1024x1024','4:5'=>'1024x1792','9:16'=>'1024x1792','3:4'=>'1024x1792','16:9'=>'1792x1024','4:3'=>'1792x1024'][$aspect] ?? '1024x1024';
        $prompt = mb_substr($prompt, 0, 3800);   // DALL·E 3 corta a ~4000 chars → recorta o falla
        // El recorte va por el final, que es justo donde quedó el veto de IP: si se
        // lo comió, se vuelve a pegar (mejor perder descripción que perder la regla).
        if (mb_strpos($prompt, 'HARD CONSTRAINT') === false) $prompt = ia_veto_ip(mb_substr($prompt, 0, 3300));
        $body = ['model'=>$modelo, 'prompt'=>$prompt, 'n'=>1, 'size'=>$size, 'response_format'=>'b64_json', 'quality'=>'hd', 'style'=>'vivid'];
        $resp = ia_http_post_retry('https://api.openai.com/v1/images/generations',
            ['Content-Type: application/json', 'Authorization: Bearer ' . OPENAI_API_KEY],
            json_encode($body, JSON_UNESCAPED_UNICODE), $opts['max_reintentos'] ?? 2);
        return openai_extraer_img($resp, $modelo);
    }

    // gpt-image-1
    $size    = ['1:1'=>'1024x1024','4:5'=>'1024x1536','9:16'=>'1024x1536','3:4'=>'1024x1536','16:9'=>'1536x1024','4:3'=>'1536x1024'][$aspect] ?? '1024x1024';
    $quality = $opts['calidad_openai'] ?? OPENAI_IMG_QUALITY;

    // /edits SOLO cuando hay una FOTO REAL que editar. Con un LOGO (fondo blanco) el
    // endpoint edits produce manchas blancas sin textura → el arte desde cero va a
    // /generations (imagen completa y de calidad). El logo se ignora aquí (mejor que romper).
    if (!empty($opts['foto_real'])) {
        $entrada = null;
        foreach (($opts['imagenes'] ?? []) as $img) {
            $bin = base64_decode($img['data'] ?? '', true);
            if ($bin !== false && $bin !== '') { $entrada = ['bin'=>$bin, 'mime'=>$img['mime'] ?? 'image/png']; break; }
        }
        if ($entrada !== null) {
            return openai_imagen_edit($prompt, $entrada, $modelo, $size, $quality, $opts);
        }
    }

    // background=opaque: PROHÍBE el fondo transparente (que se ve BLANCO al mostrarse).
    // Es LA causa del "fondo blanco" — gpt-image-1 por defecto (auto) puede devolver
    // PNG transparente; opaque lo obliga a llenar todo el lienzo con la escena.
    $body = ['model'=>$modelo, 'prompt'=>$prompt, 'n'=>1, 'size'=>$size, 'quality'=>$quality, 'background'=>'opaque'];
    // ── DEBUG TEMPORAL: traza el prompt y el payload EXACTOS enviados a gpt-image-1 ──
    $__dbg = 'GPT IMAGE LEN=' . mb_strlen($prompt) . " | model={$modelo} size={$size} quality={$quality} background=opaque\n"
           . 'GPT IMAGE PROMPT: ' . $prompt . "\n"
           . 'GPT IMAGE PAYLOAD: ' . json_encode(['model'=>$modelo,'n'=>1,'size'=>$size,'quality'=>$quality,'background'=>'opaque'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n";
    error_log('[gpt_image] LEN=' . mb_strlen($prompt) . " size={$size} q={$quality}");
    $__dbgdir = __DIR__ . '/../storage/logs'; @mkdir($__dbgdir, 0775, true);
    @file_put_contents($__dbgdir . '/gpt_image_debug.log', date('c') . ' ' . $__dbg . "-----------------------------------\n", FILE_APPEND);

    // timeout 200s: gpt-image-2 tarda >60s (mata el default). Corre en worker async → OK.
    $resp = ia_http_post_retry('https://api.openai.com/v1/images/generations',
        ['Content-Type: application/json', 'Authorization: Bearer ' . OPENAI_API_KEY],
        json_encode($body, JSON_UNESCAPED_UNICODE), $opts['max_reintentos'] ?? 2, 200);
    return openai_extraer_img($resp, $modelo);
}

/**
 * MODO ChatGPT — Responses API con la herramienta image_generation. El MODELO actúa de
 * DIRECTOR: recibe un brief, escribe su propio prompt y genera la imagen en UNA sola
 * llamada (el mismo mecanismo que usa ChatGPT). Timeout propio y largo (corre en el worker).
 * Devuelve ['data','mime','modelo','revised'] (revised = el prompt que el modelo escribió).
 */
function openai_responses_imagen(string $brief, array $opts = []): array {
    if (!openai_configurado()) throw new IaSinCredenciales('Falta OPENAI_API_KEY.');
    $modelo = trim((string)($opts['modelo'] ?? ''));
    if ($modelo === '') {
        $cfg = resolver_modelo_ia(defined('IMAGE_CREATIVE_MODEL') ? IMAGE_CREATIVE_MODEL : 'openai:creative');
        $modelo = strpos($cfg, ':') !== false ? explode(':', $cfg, 2)[1] : $cfg;
    }
    $aspect = $opts['aspect'] ?? '1:1';
    $size = ['1:1'=>'1024x1024','4:5'=>'1024x1536','9:16'=>'1024x1536','16:9'=>'1536x1024','4:3'=>'1536x1024'][$aspect] ?? '1024x1024';
    $body = [
        'model' => $modelo,
        'input' => $brief,
        'tools' => [[ 'type'=>'image_generation', 'quality'=>'high', 'size'=>$size, 'background'=>'opaque' ]],
    ];
    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . OPENAI_API_KEY],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 200,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) { $e = curl_error($ch); curl_close($ch); throw new IaError('cURL Responses: ' . $e); }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code < 200 || $code >= 300) throw new IaError("Responses HTTP $code: " . substr($resp, 0, 500));
    $d = json_decode($resp, true);
    if (!is_array($d))       throw new IaError('Responses no-JSON: ' . substr($resp, 0, 300));
    if (isset($d['error']))  throw new IaError('Responses: ' . ($d['error']['message'] ?? 'error'));
    $b64 = ''; $revised = '';
    foreach (($d['output'] ?? []) as $o) {
        if (($o['type'] ?? '') === 'image_generation_call') {
            $b64 = (string)($o['result'] ?? '');
            $revised = (string)($o['revised_prompt'] ?? '');
            break;
        }
    }
    if ($b64 === '') throw new IaError('Responses no devolvió imagen: ' . substr($resp, 0, 400));
    return ['data' => base64_decode($b64), 'mime' => 'image/png', 'modelo' => 'responses:' . $modelo, 'revised' => $revised];
}

/** Modelo concreto para las llamadas a Responses (perfil creative → gpt más nuevo). */
function _openai_responses_modelo(array $opts): string {
    $modelo = trim((string)($opts['modelo'] ?? ''));
    if ($modelo === '') {
        $cfg = resolver_modelo_ia(defined('IMAGE_CREATIVE_MODEL') ? IMAGE_CREATIVE_MODEL : 'openai:creative');
        $modelo = strpos($cfg, ':') !== false ? explode(':', $cfg, 2)[1] : $cfg;
    }
    return $modelo;
}

/**
 * MODO ChatGPT en BACKGROUND — crea la respuesta con background:true (OpenAI la corre por
 * su lado) y devuelve el id AL INSTANTE. No hay llamada larga que el servidor deba sostener.
 * Devuelve ['id','modelo','status'].
 */
function openai_responses_crear_bg(string $brief, array $opts = []): array {
    if (!openai_configurado()) throw new IaSinCredenciales('Falta OPENAI_API_KEY.');
    $modelo = _openai_responses_modelo($opts);
    $aspect = $opts['aspect'] ?? '1:1';
    $size = ['1:1'=>'1024x1024','4:5'=>'1024x1536','9:16'=>'1024x1536','16:9'=>'1536x1024','4:3'=>'1536x1024'][$aspect] ?? '1024x1024';
    // Si viene el LOGO real del negocio, se manda como imagen de referencia (input multimodal)
    // para que el modelo USE ese logo y NO invente uno. Sin logo → input de texto normal.
    $input = $brief;
    if (!empty($opts['logo']['data'])) {
        $input = [[
            'role'    => 'user',
            'content' => [
                ['type'=>'input_text',  'text'=>$brief],
                ['type'=>'input_image', 'image_url'=>'data:' . ($opts['logo']['mime'] ?? 'image/png') . ';base64,' . $opts['logo']['data']],
            ],
        ]];
    }
    $body = [
        'model'      => $modelo,
        'input'      => $input,
        'background' => true,
        'tools'      => [[ 'type'=>'image_generation', 'quality'=>'high', 'size'=>$size, 'background'=>'opaque' ]],
    ];
    $resp = ia_http_post_retry('https://api.openai.com/v1/responses',
        ['Content-Type: application/json', 'Authorization: Bearer ' . OPENAI_API_KEY],
        json_encode($body, JSON_UNESCAPED_UNICODE), 2);
    $d = json_decode($resp, true);
    if (!is_array($d))      throw new IaError('Responses(bg) no-JSON: ' . substr($resp, 0, 300));
    if (isset($d['error'])) throw new IaError('Responses(bg): ' . ($d['error']['message'] ?? 'error'));
    $id = (string)($d['id'] ?? '');
    if ($id === '')         throw new IaError('Responses(bg) sin id: ' . substr($resp, 0, 300));
    return ['id' => $id, 'modelo' => $modelo, 'status' => (string)($d['status'] ?? 'queued')];
}

/**
 * Consulta el estado de una respuesta background (GET corto). Cuando status='completed'
 * trae la imagen (b64) y el prompt que el modelo escribió (revised).
 * Devuelve ['status','b64','revised','model'].
 */
function openai_responses_estado(string $rid): array {
    if (!openai_configurado()) throw new IaSinCredenciales('Falta OPENAI_API_KEY.');
    $resp = ia_http_get('https://api.openai.com/v1/responses/' . rawurlencode($rid),
        ['Authorization: Bearer ' . OPENAI_API_KEY]);
    $d = json_decode($resp, true);
    if (!is_array($d))      throw new IaError('Responses(estado) no-JSON: ' . substr($resp, 0, 300));
    if (isset($d['error'])) throw new IaError('Responses(estado): ' . ($d['error']['message'] ?? 'error'));
    $status = (string)($d['status'] ?? '');
    $b64 = ''; $revised = ''; $igc = null;
    foreach (($d['output'] ?? []) as $o) {
        if (($o['type'] ?? '') === 'image_generation_call') {
            $b64 = (string)($o['result'] ?? '');
            $revised = (string)($o['revised_prompt'] ?? '');
            $igc = $o; unset($igc['result']); $igc['_result_bytes_b64'] = strlen($b64);   // crudo SIN el base64 gigante
            break;
        }
    }
    // 'raw' = evidencia cruda para la auditoría: modelo ORQUESTADOR (top-level) + el
    // image_generation_call completo (que confirma el revised_prompt auténtico y si expone
    // algún modelo/renderizador interno). NO asumir que es gpt-image-1: mirar aquí.
    $raw = [
        'response_id'           => (string)($d['id'] ?? ''),
        'status'                => $status,
        'model_orquestador'     => (string)($d['model'] ?? ''),
        'created_at'            => $d['created_at'] ?? null,
        'usage'                 => $d['usage'] ?? null,
        'tools_echo'            => $d['tools'] ?? null,        // parámetros de la herramienta tal como los devuelve la API
        'image_generation_call' => $igc,                      // revised_prompt real + cualquier campo de modelo interno
    ];
    return ['status' => $status, 'b64' => $b64, 'revised' => $revised, 'model' => (string)($d['model'] ?? ''), 'raw' => $raw];
}

/** gpt-image-1 /images/edits — incorpora UNA imagen (el logo) al arte, a calidad ALTA. */
function openai_imagen_edit(string $prompt, array $entrada, string $modelo, string $size, string $quality, array $opts): array {
    $mime = $entrada['mime'] ?: 'image/png';
    $ext  = (stripos($mime,'png')!==false) ? 'png' : ((stripos($mime,'jpeg')!==false||stripos($mime,'jpg')!==false) ? 'jpg' : 'png');
    $tmp  = tempnam(sys_get_temp_dir(), 'oai_');
    if ($tmp === false) throw new IaError('No se pudo crear archivo temporal para OpenAI edits.');
    file_put_contents($tmp, $entrada['bin']);
    $post = [
        'model'      => $modelo,
        'prompt'     => $prompt,
        'n'          => '1',
        'size'       => $size,
        'quality'    => $quality,
        'background' => 'opaque',   // nada de transparencia (= fondo blanco al mostrar)
        'image'      => new CURLFile($tmp, $mime, 'entrada.' . $ext),
    ];
    $ch = curl_init('https://api.openai.com/v1/images/edits');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post,   // array → multipart/form-data (curl arma el boundary)
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . OPENAI_API_KEY],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $cerr = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($tmp);
    if ($resp === false) throw new IaError('OpenAI edits cURL: ' . $cerr);
    if ($code < 200 || $code >= 300) throw new IaError("OpenAI edits HTTP $code: " . substr((string)$resp, 0, 400));
    return openai_extraer_img((string)$resp, $modelo);
}

/**
 * EL DIRECTOR DE ARTE decide qué motor usar para esta imagen (por fortaleza):
 *  - editar/realzar la FOTO REAL del negocio → Gemini (Nano Banana: excelente
 *    editando imágenes y alineado con la regla de IP);
 *  - arte conceptual/promocional DESDE CERO → OpenAI (si está configurado).
 * @return array ['motor'=>'gemini'|'openai', 'razon'=>string]
 */
function motor_imagen_elegir(array $opts): array {
    if (isset($opts['motor']) && in_array($opts['motor'], ['gemini','openai'], true)) {
        return ['motor'=>$opts['motor'], 'razon'=>'Motor forzado por la petición.'];
    }
    // Regla de IP: la FOTO REAL del producto se REALZA sin inventarla → Gemini (Nano
    // Banana), fiel al original. El ARTE desde cero (con o sin logo) → gpt-image-1
    // (calidad tope). OJO: el logo NO cuenta como "foto real" → ese arte va a OpenAI.
    $foto_real = !empty($opts['foto_real']) || !empty($opts['imagen_base64']);
    if ($foto_real) {
        return ['motor'=>'gemini', 'razon'=>'Realce fiel de la foto real → Gemini (Nano Banana).'];
    }
    if (openai_configurado()) {
        return ['motor'=>'openai', 'razon'=>'Arte desde cero → gpt-image-1 (calidad tope).'];
    }
    return ['motor'=>'gemini', 'razon'=>'Arte desde cero → Gemini (OpenAI no configurado).'];
}

/**
 * Dispatcher con RESPALDO automático: el Director de Arte elige motor, genera,
 * y si el elegido falla, cae al otro (si está disponible). Nunca se traba la
 * fábrica de contenido. Devuelve ['data','mime','modelo','motor','razon'].
 */
function motor_imagen(string $prompt, array $opts = []): array {
    $dec = motor_imagen_elegir($opts);
    $primero = $dec['motor'];
    $gen = fn($m) => $m === 'openai' ? openai_imagen($prompt, $opts) : gemini_imagen($prompt, $opts);
    try {
        $r = $gen($primero); $r['motor'] = $primero; $r['razon'] = $dec['razon']; return $r;
    } catch (Throwable $e) {
        $alt = $primero === 'openai' ? 'gemini' : 'openai';
        $alt_ok = ($alt === 'openai') ? openai_configurado() : (ia_transporte() === 'gemini_api');
        if (!$alt_ok) throw $e;
        error_log("motor_imagen: {$primero} falló ({$e->getMessage()}); respaldo a {$alt}");
        $r = $gen($alt); $r['motor'] = $alt; $r['razon'] = "Respaldo automático: {$primero} falló, se usó {$alt}."; return $r;
    }
}

/**
 * Wrapper: genera imagen, la guarda en uploads/$destino_rel y la registra en
 * crecer_ia_log. Devuelve ['archivo'=>url, 'costo', 'modelo'].
 */
function ia_imagen(PDO $pdo, string $agente, string $accion, string $prompt, string $destino_rel, array $opts = []): array {
    $t0 = microtime(true); $estado = 'ok'; $err = null; $modelo = 'gemini-2.5-flash-image'; $rel = null; $razon = null;
    try {
        $img = motor_imagen($prompt, $opts); $modelo = $img['modelo']; $razon = $img['razon'] ?? null;
        $abs = rtrim(UPLOADS_PATH, '/\\') . DIRECTORY_SEPARATOR . $destino_rel;
        @mkdir(dirname($abs), 0775, true);
        file_put_contents($abs, $img['data']);
        $rel = rtrim(UPLOADS_URL, '/') . '/' . str_replace('\\', '/', $destino_rel);
    } catch (IaSinCredenciales $e) { $estado='error'; $err='sin credenciales de imagen'; }
      catch (IaError $e)        { $estado='error'; $err=$e->getMessage(); }
      catch (Throwable $e)      { $estado='error'; $err=substr($e->getMessage(),0,200); }
    $lat = (int)round((microtime(true) - $t0) * 1000);
    // PRECIO POR IMAGEN. Aproximado y sujeto al pricing vigente — la cifra que
    // se REPORTA como gasto sale de la factura del proveedor, no de aquí.
    //
    // Faltaba gpt-image-2, que es el motor de produccion de img_responses.php:
    // sus 75 llamadas de julio-agosto cayeron al fallback CRECER_IMG_PRECIO,
    // que es el precio de GEMINI FLASH ($0.039) aplicado a un modelo de OpenAI.
    // Subestimaba por ~4x. El fallback tambien se corrige: una imagen de OpenAI
    // desconocida se estima como gpt-image-1, no como Gemini.
    $PRECIO_IMG = [
        'gemini-3-pro-image'    => 0.134,
        'gemini-2.5-flash-image'=> 0.039,
        'gpt-image-1'           => 0.17,
        'gpt-image-2'           => 0.17,   // aprox: se asume como gpt-image-1
        'dall-e-3'              => 0.08,
    ];
    $es_openai = str_starts_with($modelo, 'gpt-') || str_starts_with($modelo, 'dall-e');
    $costo = $estado === 'ok'
        ? ($PRECIO_IMG[$modelo] ?? ($es_openai ? 0.17 : CRECER_IMG_PRECIO))
        : 0;
    // La decisión del Director de Arte queda en la acción (evidencia del ruteo).
    // OJO: crecer_ia_log.accion es VARCHAR(80) → truncar para no romper el INSERT.
    $accion_log = mb_substr($razon ? ($accion . ' · ' . $razon) : $accion, 0, 80);
    $pdo->prepare("INSERT INTO crecer_ia_log (marca_id,agente,accion,modelo,prompt,respuesta,costo_usd,latencia_ms,estado,error_msg)
                   VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$opts['marca_id'] ?? null, $agente, $accion_log, $modelo, $prompt, $rel, $costo, $lat, $estado, $err]);
    if ($estado === 'error') throw new IaError($err);
    return ['archivo' => $rel, 'costo' => $costo, 'modelo' => $modelo];
}
