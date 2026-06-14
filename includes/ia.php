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
// gemini-2.0-flash: ~$0.10 in / $0.40 out por 1M (orientativo).
const CRECER_IA_PRECIOS = [
    'gemini-2.0-flash'      => ['in' => 0.10, 'out' => 0.40],
    'gemini-2.5-flash'      => ['in' => 0.30, 'out' => 2.50],
    'gemini-1.5-flash'      => ['in' => 0.075, 'out' => 0.30],
];

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
    if (!$p) return 0.0;
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

    $payload = [
        'contents' => [[
            'role'  => 'user',
            'parts' => [['text' => $prompt]],
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
function ia_http_post(string $url, array $headers, string $body): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
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
function ia_http_post_retry(string $url, array $headers, string $body, int $max_reintentos = 4): string {
    $intento = 0;
    while (true) {
        try {
            return ia_http_post($url, $headers, $body);
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
function gemini_imagen(string $prompt, array $opts = []): array {
    $modelo = $opts['modelo'] ?? 'gemini-2.5-flash-image';
    if (ia_transporte() !== 'gemini_api') {
        throw new IaSinCredenciales('Generación de imágenes requiere GEMINI_API_KEY.');
    }
    $url = "https://generativelanguage.googleapis.com/v1beta/models/"
         . rawurlencode($modelo) . ":generateContent?key=" . rawurlencode(GEMINI_API_KEY);
    $parts = [['text' => $prompt]];
    if (!empty($opts['imagen_base64'])) {
        $parts[] = ['inlineData' => ['mimeType' => $opts['imagen_mime'] ?? 'image/jpeg', 'data' => $opts['imagen_base64']]];
    }
    $payload = ['contents' => [['role'=>'user','parts'=>$parts]], 'generationConfig' => ['responseModalities' => ['IMAGE']]];
    $resp = ia_http_post_retry($url, ['Content-Type: application/json'], json_encode($payload, JSON_UNESCAPED_UNICODE), $opts['max_reintentos'] ?? 3);
    $d = json_decode($resp, true);
    if (isset($d['error'])) throw new IaError('Gemini imagen: ' . ($d['error']['message'] ?? 'error'));
    foreach (($d['candidates'][0]['content']['parts'] ?? []) as $p) {
        if (isset($p['inlineData']['data'])) {
            return ['data' => base64_decode($p['inlineData']['data']), 'mime' => $p['inlineData']['mimeType'] ?? 'image/png', 'modelo' => $modelo];
        }
    }
    throw new IaError('Gemini no devolvió imagen.');
}

/**
 * Wrapper: genera imagen, la guarda en uploads/$destino_rel y la registra en
 * crecer_ia_log. Devuelve ['archivo'=>url, 'costo', 'modelo'].
 */
function ia_imagen(PDO $pdo, string $agente, string $accion, string $prompt, string $destino_rel, array $opts = []): array {
    $t0 = microtime(true); $estado = 'ok'; $err = null; $modelo = 'gemini-2.5-flash-image'; $rel = null;
    try {
        $img = gemini_imagen($prompt, $opts); $modelo = $img['modelo'];
        $abs = rtrim(UPLOADS_PATH, '/\\') . DIRECTORY_SEPARATOR . $destino_rel;
        @mkdir(dirname($abs), 0775, true);
        file_put_contents($abs, $img['data']);
        $rel = rtrim(UPLOADS_URL, '/') . '/' . str_replace('\\', '/', $destino_rel);
    } catch (IaSinCredenciales $e) { $estado='error'; $err='sin credenciales de imagen'; }
      catch (IaError $e)        { $estado='error'; $err=$e->getMessage(); }
    $lat = (int)round((microtime(true) - $t0) * 1000);
    $costo = $estado === 'ok' ? CRECER_IMG_PRECIO : 0;
    $pdo->prepare("INSERT INTO crecer_ia_log (marca_id,agente,accion,modelo,prompt,respuesta,costo_usd,latencia_ms,estado,error_msg)
                   VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$opts['marca_id'] ?? null, $agente, $accion, $modelo, $prompt, $rel, $costo, $lat, $estado, $err]);
    if ($estado === 'error') throw new IaError($err);
    return ['archivo' => $rel, 'costo' => $costo, 'modelo' => $modelo];
}
