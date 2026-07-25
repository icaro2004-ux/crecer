<?php
// ============================================================
//  CRECER — Instagram Login DIRECTO  (includes/ig_direct.php)
//  "Instagram API with Instagram Login": el cliente conecta su
//  Instagram con SOLO su login de IG — SIN página de Facebook.
//  Mata la fricción #1 del onboarding.
//
//  Módulo AISLADO. No toca el conector de Meta por página
//  (includes/meta.php sigue igual para Facebook).
//
//  Credenciales (en config.local): IG_APP_ID, IG_APP_SECRET.
//  (Son las del producto "Instagram" de la app de Meta — DISTINTAS
//   del META_APP_ID/SECRET del login de Facebook.)
//  Redirect: IG_REDIRECT_URI (default: BASE_URL/panel/conectar_ig.php).
// ============================================================

if (!defined('IG_APP_ID'))     define('IG_APP_ID', '');
if (!defined('IG_APP_SECRET')) define('IG_APP_SECRET', '');
if (!defined('IG_REDIRECT_URI')) {
    define('IG_REDIRECT_URI', (defined('BASE_URL') ? rtrim(BASE_URL, '/') : '') . '/panel/conectar_ig.php');
}
const IG_SCOPES = ['instagram_business_basic', 'instagram_business_content_publish'];

class IgDirectError extends RuntimeException {}

/** ¿Está configurado el Instagram Login directo? */
function ig_direct_disponible(): bool {
    return IG_APP_ID !== '' && IG_APP_SECRET !== '';
}

/** URL de autorización (a donde mandamos al cliente para que entre con su IG). */
function ig_oauth_url(string $state): string {
    $q = http_build_query([
        'client_id'     => IG_APP_ID,
        'redirect_uri'  => IG_REDIRECT_URI,
        'response_type' => 'code',
        'scope'         => implode(',', IG_SCOPES),
        'state'         => $state,
    ]);
    return 'https://www.instagram.com/oauth/authorize?' . $q;
}

/** Intercambia el ?code por token corto + user_id. */
function ig_exchange_code(string $code): array {
    $resp = ig_http('POST', 'https://api.instagram.com/oauth/access_token', [
        'client_id'     => IG_APP_ID,
        'client_secret' => IG_APP_SECRET,
        'grant_type'    => 'authorization_code',
        'redirect_uri'  => IG_REDIRECT_URI,
        'code'          => $code,
    ], false);
    if (empty($resp['access_token'])) throw new IgDirectError('Sin access_token: ' . json_encode($resp));
    return ['access_token' => $resp['access_token'], 'user_id' => (string)($resp['user_id'] ?? '')];
}

/** Cambia el token corto por uno de LARGA duración (60 días). */
function ig_long_lived(string $short_token): array {
    $resp = ig_http('GET', 'https://graph.instagram.com/access_token', [
        'grant_type'    => 'ig_exchange_token',
        'client_secret' => IG_APP_SECRET,
        'access_token'  => $short_token,
    ]);
    if (empty($resp['access_token'])) throw new IgDirectError('Sin token largo: ' . json_encode($resp));
    return ['access_token' => $resp['access_token'], 'expires_in' => (int)($resp['expires_in'] ?? 5184000)];
}

/** Datos de la cuenta IG conectada. */
function ig_me(string $token): array {
    $resp = ig_http('GET', 'https://graph.instagram.com/v21.0/me', [
        'fields'       => 'user_id,username',
        'access_token' => $token,
    ]);
    return ['user_id' => (string)($resp['user_id'] ?? $resp['id'] ?? ''), 'username' => (string)($resp['username'] ?? '')];
}

/** Renueva un token largo antes de que expire (opcional, para el cron). */
function ig_refresh(string $token): array {
    $resp = ig_http('GET', 'https://graph.instagram.com/refresh_access_token', [
        'grant_type'   => 'ig_refresh_token',
        'access_token' => $token,
    ]);
    if (empty($resp['access_token'])) throw new IgDirectError('No se pudo renovar: ' . json_encode($resp));
    return ['access_token' => $resp['access_token'], 'expires_in' => (int)($resp['expires_in'] ?? 5184000)];
}

// ── Persistencia ────────────────────────────────────────────
function ig_guardar_conexion(PDO $pdo, int $marca_id, string $ig_user_id, string $username, string $token, int $expires_in): void {
    $exp = date('Y-m-d H:i:s', time() + max(3600, $expires_in));
    $pdo->prepare(
        "INSERT INTO crecer_ig_conexiones (marca_id, ig_user_id, ig_username, access_token, token_expira, estado, ultimo_error)
         VALUES (?,?,?,?,?, 'activa', NULL)
         ON DUPLICATE KEY UPDATE ig_user_id=VALUES(ig_user_id), ig_username=VALUES(ig_username),
           access_token=VALUES(access_token), token_expira=VALUES(token_expira), estado='activa', ultimo_error=NULL"
    )->execute([$marca_id, $ig_user_id, $username, $token, $exp]);
}

function ig_conexion(PDO $pdo, int $marca_id): ?array {
    try {
        $st = $pdo->prepare("SELECT * FROM crecer_ig_conexiones WHERE marca_id=? AND estado='activa'");
        $st->execute([$marca_id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { return null; }
}

// ── Publicar un REEL por Instagram Login (Content Publishing) ─
/**
 * Publica un reel en la cuenta IG conectada. 2 pasos + poll del contenedor.
 * @return array ['id'=>media_id, 'permalink'=>?]
 */
function ig_publicar_reel(string $ig_user_id, string $token, string $video_url, string $caption): array {
    // 1) Crear contenedor REEL.
    $c = ig_http('POST', "https://graph.instagram.com/v21.0/{$ig_user_id}/media", [
        'media_type'   => 'REELS',
        'video_url'    => $video_url,
        'caption'      => $caption,
        'access_token' => $token,
    ]);
    $creation_id = $c['id'] ?? '';
    if (!$creation_id) throw new IgDirectError('IG no creó el contenedor: ' . json_encode($c));

    // 2) Esperar a que el video termine de procesar (FINISHED).
    $ready = false;
    for ($i = 0; $i < 30; $i++) {   // ~90s máx
        sleep(3);
        $st = ig_http('GET', "https://graph.instagram.com/v21.0/{$creation_id}", [
            'fields'       => 'status_code,status',
            'access_token' => $token,
        ]);
        $sc = $st['status_code'] ?? '';
        if ($sc === 'FINISHED') { $ready = true; break; }
        if ($sc === 'ERROR')    throw new IgDirectError('IG falló procesando el video: ' . json_encode($st));
    }
    if (!$ready) throw new IgDirectError('El video de IG tardó demasiado en procesar.');

    // 3) Publicar.
    $p = ig_http('POST', "https://graph.instagram.com/v21.0/{$ig_user_id}/media_publish", [
        'creation_id'  => $creation_id,
        'access_token' => $token,
    ]);
    $media_id = $p['id'] ?? '';
    if (!$media_id) throw new IgDirectError('IG no publicó: ' . json_encode($p));

    // Permalink (best-effort).
    $permalink = null;
    try {
        $m = ig_http('GET', "https://graph.instagram.com/v21.0/{$media_id}", ['fields' => 'permalink', 'access_token' => $token]);
        $permalink = $m['permalink'] ?? null;
    } catch (Throwable $e) {}
    return ['id' => $media_id, 'permalink' => $permalink];
}

/** cURL interno. GET = querystring; POST = form-urlencoded. Devuelve array JSON. */
function ig_http(string $metodo, string $url, array $params = [], bool $throw_http = true): array {
    if ($metodo === 'GET' && $params) $url .= '?' . http_build_query($params);
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 12,
    ];
    if ($metodo === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
    }
    curl_setopt_array($ch, $opts);
    $raw  = curl_exec($ch);
    if ($raw === false) { $e = curl_error($ch); curl_close($ch); throw new IgDirectError('cURL: ' . $e); }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) $data = ['_raw' => $raw];
    if ($throw_http && ($code < 200 || $code >= 300)) {
        $msg = $data['error']['message'] ?? $data['error_message'] ?? substr((string)$raw, 0, 200);
        throw new IgDirectError("HTTP $code: $msg");
    }
    return $data;
}
