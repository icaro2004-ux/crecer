<?php
// ============================================================
//  CRECER — Conector de Meta (Instagram + Facebook)
//  includes/meta.php
//
//  Calca el patrón cURL de stripe.php / ia.php. Habla con la
//  Graph API de Meta. Dos trabajos:
//   1) OAuth: conectar la Página de FB + cuenta IG Business del
//      dueño y guardar su token de página (de larga duración).
//   2) Publicar: soltar un post (imagen + caption) a IG y/o FB.
//
//  Requiere en config: META_APP_ID, META_APP_SECRET, META_REDIRECT_URI.
//  Sin esas constantes, meta_configurado()=false y todo degrada
//  con gracia (igual que Stripe sin llaves).
//
//  NOTA IG: publicar a Instagram es 2 pasos (crear contenedor con
//  una URL pública de imagen → publicar el contenedor), con un
//  posible delay de procesamiento que se resuelve con poll.
// ============================================================

if (!defined('META_GRAPH_VERSION')) define('META_GRAPH_VERSION', 'v21.0');
if (!defined('META_APP_ID'))        define('META_APP_ID', '');
if (!defined('META_APP_SECRET'))    define('META_APP_SECRET', '');
if (!defined('META_REDIRECT_URI'))  define('META_REDIRECT_URI', '');

// Permisos que pedimos en el login de Meta. Casi todos requieren
// App Review con Advanced Access para publicar a nombre de clientes.
const META_SCOPES = [
    'pages_show_list',
    'pages_read_engagement',
    'pages_manage_posts',
    'instagram_basic',
    'instagram_content_publish',
    'business_management',
];

class MetaError extends RuntimeException {}

/** ¿Hay credenciales de la app de Meta configuradas? (degradar con gracia) */
function meta_configurado(): bool {
    return META_APP_ID !== '' && META_APP_SECRET !== '';
}

/** Llamada cruda a la Graph API. $metodo: 'GET'|'POST'. Lanza MetaError. */
function meta_api(string $metodo, string $path, array $params = []): array {
    $url = 'https://graph.facebook.com/' . META_GRAPH_VERSION . '/' . ltrim($path, '/');
    $ch = curl_init();
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_CONNECTTIMEOUT => 10,
    ];
    if (strtoupper($metodo) === 'POST') {
        $opts[CURLOPT_URL]        = $url;
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
    } else {
        $opts[CURLOPT_URL] = $params ? $url . '?' . http_build_query($params) : $url;
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch); curl_close($ch);
        throw new MetaError('cURL: ' . $err);
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($resp, true);
    if ($code < 200 || $code >= 300) {
        $msg = $data['error']['message'] ?? substr((string)$resp, 0, 300);
        throw new MetaError("Meta HTTP $code: $msg");
    }
    return is_array($data) ? $data : [];
}

// ── OAUTH ────────────────────────────────────────────────────

/** URL del diálogo de login de Meta. $state liga el callback a la marca. */
function meta_oauth_url(string $state): string {
    if (!meta_configurado()) throw new MetaError('Meta no está configurado (falta META_APP_ID/SECRET).');
    $q = http_build_query([
        'client_id'     => META_APP_ID,
        'redirect_uri'  => META_REDIRECT_URI,
        'state'         => $state,
        'response_type' => 'code',
        'scope'         => implode(',', META_SCOPES),
    ]);
    return 'https://www.facebook.com/' . META_GRAPH_VERSION . '/dialog/oauth?' . $q;
}

/** Cambia el ?code= del callback por un token de usuario corto. */
function meta_token_desde_codigo(string $code): string {
    $r = meta_api('GET', 'oauth/access_token', [
        'client_id'     => META_APP_ID,
        'client_secret' => META_APP_SECRET,
        'redirect_uri'  => META_REDIRECT_URI,
        'code'          => $code,
    ]);
    if (empty($r['access_token'])) throw new MetaError('No se obtuvo access_token del código.');
    return $r['access_token'];
}

/** Cambia el token de usuario corto por uno de larga duración (~60 días). */
function meta_token_largo(string $token_corto): string {
    $r = meta_api('GET', 'oauth/access_token', [
        'grant_type'        => 'fb_exchange_token',
        'client_id'         => META_APP_ID,
        'client_secret'     => META_APP_SECRET,
        'fb_exchange_token' => $token_corto,
    ]);
    if (empty($r['access_token'])) throw new MetaError('No se obtuvo el token de larga duración.');
    return $r['access_token'];
}

/**
 * Lista las Páginas que administra el usuario, con su token de PÁGINA
 * (de larga duración si el token de usuario lo es) y la cuenta de IG
 * Business conectada a cada una. Esto es lo que mostramos para elegir.
 *
 * @return array de ['fb_page_id','fb_page_nombre','page_access_token',
 *                    'ig_user_id','ig_username']
 */
function meta_paginas(string $user_token): array {
    $r = meta_api('GET', 'me/accounts', [
        'fields'       => 'id,name,access_token,instagram_business_account{id,username}',
        'access_token' => $user_token,
        'limit'        => 50,
    ]);
    $out = [];
    foreach ($r['data'] ?? [] as $p) {
        $out[] = [
            'fb_page_id'        => $p['id'] ?? '',
            'fb_page_nombre'    => $p['name'] ?? '',
            'page_access_token' => $p['access_token'] ?? '',
            'ig_user_id'        => $p['instagram_business_account']['id'] ?? '',
            'ig_username'       => $p['instagram_business_account']['username'] ?? '',
        ];
    }
    return $out;
}

// ── PUBLICAR ─────────────────────────────────────────────────

/**
 * Publica en Instagram (Content Publishing API, 2 pasos + poll).
 * Requiere una URL PÚBLICA HTTPS de la imagen (IG no acepta bytes).
 *
 * @return array ['id'=>media_id, 'permalink'=>url]
 */
function meta_publicar_ig(string $ig_user_id, string $page_token, string $image_url, string $caption): array {
    // 1) Crear el contenedor.
    $c = meta_api('POST', "$ig_user_id/media", [
        'image_url'    => $image_url,
        'caption'     => $caption,
        'access_token' => $page_token,
    ]);
    $creation_id = $c['id'] ?? '';
    if ($creation_id === '') throw new MetaError('IG no devolvió creation_id.');

    // 2) Esperar a que Meta procese el contenedor (poll del status).
    $listo = false;
    for ($i = 0; $i < 10; $i++) {
        $st = meta_api('GET', $creation_id, [
            'fields'       => 'status_code',
            'access_token' => $page_token,
        ]);
        $sc = $st['status_code'] ?? '';
        if ($sc === 'FINISHED') { $listo = true; break; }
        if ($sc === 'ERROR' || $sc === 'EXPIRED') {
            throw new MetaError("IG contenedor en estado $sc.");
        }
        sleep(3);
    }
    if (!$listo) throw new MetaError('IG no terminó de procesar la imagen a tiempo.');

    // 3) Publicar el contenedor.
    $p = meta_api('POST', "$ig_user_id/media_publish", [
        'creation_id'  => $creation_id,
        'access_token' => $page_token,
    ]);
    $media_id = $p['id'] ?? '';
    if ($media_id === '') throw new MetaError('IG no devolvió media_id al publicar.');

    // 4) Permalink (best-effort; no crítico).
    $permalink = '';
    try {
        $pl = meta_api('GET', $media_id, ['fields' => 'permalink', 'access_token' => $page_token]);
        $permalink = $pl['permalink'] ?? '';
    } catch (MetaError $e) { /* el post ya salió; el permalink es opcional */ }

    return ['id' => $media_id, 'permalink' => $permalink];
}

/**
 * Publica en una Página de Facebook. Con imagen (URL pública) usa
 * /photos; sin imagen, /feed (solo texto).
 *
 * @return array ['id'=>post_id, 'permalink'=>url]
 */
function meta_publicar_fb(string $page_id, string $page_token, string $caption, string $image_url = ''): array {
    if ($image_url !== '') {
        $r = meta_api('POST', "$page_id/photos", [
            'url'          => $image_url,
            'caption'      => $caption,
            'access_token' => $page_token,
        ]);
        $post_id = $r['post_id'] ?? $r['id'] ?? '';
    } else {
        $r = meta_api('POST', "$page_id/feed", [
            'message'      => $caption,
            'access_token' => $page_token,
        ]);
        $post_id = $r['id'] ?? '';
    }
    if ($post_id === '') throw new MetaError('FB no devolvió el id del post.');
    return ['id' => $post_id, 'permalink' => 'https://www.facebook.com/' . $post_id];
}

// ── LECTURA DE LA CONEXIÓN GUARDADA ──────────────────────────

/** La conexión Meta de una marca (o null). */
function conexion_de_marca(PDO $pdo, int $marca_id): ?array {
    $s = $pdo->prepare("SELECT * FROM crecer_conexiones WHERE marca_id = ?");
    $s->execute([$marca_id]);
    return $s->fetch() ?: null;
}

/** ¿La marca tiene una conexión activa lista para publicar? */
function marca_conectada(PDO $pdo, int $marca_id): bool {
    $c = conexion_de_marca($pdo, $marca_id);
    return $c && $c['estado'] === 'activa' && !empty($c['page_access_token']);
}

/** Guarda (o reemplaza) la conexión de una marca tras elegir la Página. */
function guardar_conexion(PDO $pdo, int $marca_id, array $d): void {
    $pdo->prepare(
        "INSERT INTO crecer_conexiones
            (marca_id, proveedor, fb_user_id, fb_page_id, fb_page_nombre,
             ig_user_id, ig_username, page_access_token, token_expira, scopes, estado, ultimo_error)
         VALUES (?, 'meta', ?, ?, ?, ?, ?, ?, ?, ?, 'activa', NULL)
         ON DUPLICATE KEY UPDATE
            fb_user_id=VALUES(fb_user_id), fb_page_id=VALUES(fb_page_id),
            fb_page_nombre=VALUES(fb_page_nombre), ig_user_id=VALUES(ig_user_id),
            ig_username=VALUES(ig_username), page_access_token=VALUES(page_access_token),
            token_expira=VALUES(token_expira), scopes=VALUES(scopes),
            estado='activa', ultimo_error=NULL, updated_at=NOW()"
    )->execute([
        $marca_id,
        $d['fb_user_id'] ?? null,
        $d['fb_page_id'] ?? null,
        $d['fb_page_nombre'] ?? null,
        $d['ig_user_id'] ?? null,
        $d['ig_username'] ?? null,
        $d['page_access_token'] ?? null,
        $d['token_expira'] ?? null,
        $d['scopes'] ?? implode(',', META_SCOPES),
    ]);
}
