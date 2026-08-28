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
    // EL CONSERJE necesita ademas: pages_manage_engagement +
    // instagram_manage_comments. Se REVIRTIERON (2026-08-09): pedirlos sin
    // habilitarlos antes en la app rompia el login completo ("something went
    // wrong") y desconecto a Manuel. NO re-anadirlos hasta habilitarlos en
    // developers.facebook.com → App Review → Permissions and features
    // (standard access basta para cuentas con rol en la app).
];

class MetaError extends RuntimeException {}

/** ¿Hay credenciales de la app de Meta configuradas? (degradar con gracia) */
function meta_configurado(): bool {
    return META_APP_ID !== '' && META_APP_SECRET !== '';
}

/** Llamada cruda a la Graph API. $metodo: 'GET'|'POST'. Lanza MetaError. */
//  ENVUELTO EN `function_exists` A PROPOSITO. Es el UNICO sitio por donde
//  salen las llamadas a Instagram y Facebook, asi que es el unico sitio donde
//  hay que ponerse delante para probar el publicador sin tocar la red. Un
//  runner de prueba declara su propia `meta_api()` antes de cargar esto y
//  ejercita el camino ENTERO —reclamar, publicar, guardar, avisar— contra su
//  propio doble. Es el mismo patron que ya usa el borde de la IA.
//
//  DEFENSA EN PROFUNDIDAD: si estamos en modo prueba y ESTA es la de verdad,
//  no sale. Un olvido no puede acabar publicando en el muro de un cliente.
if (!function_exists('meta_api')) {
function meta_api(string $metodo, string $path, array $params = []): array {
    if (defined('CRECER_TEST_MODE') && CRECER_TEST_MODE
        && !(defined('CRECER_TEST_RED_FALSA') && CRECER_TEST_RED_FALSA)) {
        throw new MetaError('meta_api: transporte real en modo prueba.');
    }
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
}   // fin del envoltorio function_exists

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
 * Publica un REEL en Instagram (video que el dueño subió; nosotros NO
 * generamos video). 2 pasos + poll, igual que la imagen pero el
 * procesamiento del video tarda más. Requiere URL pública del .mp4.
 *
 * @return array ['id'=>media_id, 'permalink'=>url]
 */
function meta_publicar_ig_reel(string $ig_user_id, string $page_token, string $video_url, string $caption): array {
    // 1) Crear el contenedor de REEL.
    $c = meta_api('POST', "$ig_user_id/media", [
        'media_type'   => 'REELS',
        'video_url'    => $video_url,
        'caption'      => $caption,
        'access_token' => $page_token,
    ]);
    $creation_id = $c['id'] ?? '';
    if ($creation_id === '') throw new MetaError('IG no devolvió creation_id para el Reel.');

    // 2) Esperar a que Meta procese el video. Más lento que una foto:
    //    hasta ~80s (16×5s) — dentro del límite del proxy. Si no termina,
    //    se avisa para reintentar (el video queda subido, no se pierde).
    $listo = false;
    for ($i = 0; $i < 16; $i++) {
        sleep(5);
        $st = meta_api('GET', $creation_id, ['fields' => 'status_code', 'access_token' => $page_token]);
        $sc = $st['status_code'] ?? '';
        if ($sc === 'FINISHED') { $listo = true; break; }
        if ($sc === 'ERROR' || $sc === 'EXPIRED') throw new MetaError("IG rechazó el Reel (estado $sc). Revisa que el video cumpla el formato de Reels.");
    }
    if (!$listo) throw new MetaError('Meta todavía está procesando el video. Espera un momento y vuelve a darle Publicar.');

    // 3) Publicar el contenedor.
    $p = meta_api('POST', "$ig_user_id/media_publish", ['creation_id' => $creation_id, 'access_token' => $page_token]);
    $media_id = $p['id'] ?? '';
    if ($media_id === '') throw new MetaError('IG no devolvió media_id al publicar el Reel.');

    $permalink = '';
    try {
        $pl = meta_api('GET', $media_id, ['fields' => 'permalink', 'access_token' => $page_token]);
        $permalink = $pl['permalink'] ?? '';
    } catch (MetaError $e) { /* el Reel ya salió; permalink opcional */ }

    return ['id' => $media_id, 'permalink' => $permalink];
}

/**
 * Publica un VIDEO en una Página de Facebook (video subido por el dueño).
 * FB procesa el video de forma asíncrona; devuelve el id al instante.
 *
 * @return array ['id'=>video_id, 'permalink'=>url]
 */
function meta_publicar_fb_video(string $page_id, string $page_token, string $caption, string $video_url): array {
    $r = meta_api('POST', "$page_id/videos", [
        'file_url'     => $video_url,
        'description'  => $caption,
        'access_token' => $page_token,
    ]);
    $vid = $r['id'] ?? '';
    if ($vid === '') throw new MetaError('FB no devolvió el id del video.');
    return ['id' => $vid, 'permalink' => 'https://www.facebook.com/' . $page_id . '/videos/' . $vid];
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

/**
 * Publica un CARRUSEL en Instagram (2..10 imágenes que cuentan una historia,
 * swipe horizontal). Flujo oficial: 1) un contenedor HIJO por imagen
 * (is_carousel_item=true), 2) un contenedor CAROUSEL con los hijos + caption,
 * 3) publicar. Poll de status en cada paso (Meta procesa async).
 *
 * @param string[] $image_urls  URLs públicas JPEG, EN ORDEN (2..10).
 * @return array ['id'=>media_id, 'permalink'=>url]
 */
function meta_publicar_ig_carrusel(string $ig_user_id, string $page_token, array $image_urls, string $caption): array {
    $urls = array_values(array_filter(array_map('strval', $image_urls), fn($u) => trim($u) !== ''));
    if (count($urls) < 2)  throw new MetaError('Un carrusel necesita al menos 2 imágenes.');
    if (count($urls) > 10) $urls = array_slice($urls, 0, 10);   // IG tope 10

    $poll = function (string $id) use ($page_token) {
        for ($i = 0; $i < 12; $i++) {
            $st = meta_api('GET', $id, ['fields' => 'status_code', 'access_token' => $page_token]);
            $sc = $st['status_code'] ?? '';
            if ($sc === 'FINISHED') return;
            if ($sc === 'ERROR' || $sc === 'EXPIRED') throw new MetaError("IG contenedor en estado $sc.");
            sleep(3);
        }
        throw new MetaError('IG no terminó de procesar un contenedor a tiempo.');
    };

    // 1) Un contenedor hijo por imagen.
    $children = [];
    foreach ($urls as $u) {
        $c = meta_api('POST', "$ig_user_id/media", [
            'image_url'        => $u,
            'is_carousel_item' => 'true',
            'access_token'     => $page_token,
        ]);
        $cid = $c['id'] ?? '';
        if ($cid === '') throw new MetaError('IG no devolvió id de un slide del carrusel.');
        $poll($cid);
        $children[] = $cid;
    }

    // 2) Contenedor CAROUSEL (padre) con los hijos + el caption.
    $p = meta_api('POST', "$ig_user_id/media", [
        'media_type'   => 'CAROUSEL',
        'children'     => implode(',', $children),
        'caption'      => $caption,
        'access_token' => $page_token,
    ]);
    $creation_id = $p['id'] ?? '';
    if ($creation_id === '') throw new MetaError('IG no devolvió creation_id del carrusel.');
    $poll($creation_id);

    // 3) Publicar.
    $r = meta_api('POST', "$ig_user_id/media_publish", [
        'creation_id'  => $creation_id,
        'access_token' => $page_token,
    ]);
    $media_id = $r['id'] ?? '';
    if ($media_id === '') throw new MetaError('IG no devolvió media_id al publicar el carrusel.');

    $permalink = '';
    try {
        $pl = meta_api('GET', $media_id, ['fields' => 'permalink', 'access_token' => $page_token]);
        $permalink = $pl['permalink'] ?? '';
    } catch (MetaError $e) { /* el post ya salió; el permalink es opcional */ }
    return ['id' => $media_id, 'permalink' => $permalink];
}

/**
 * Publica un ÁLBUM en una Página de Facebook (varias fotos en un post). OJO: FB
 * NO tiene el swipe-carrusel orgánico de IG — esto se ve como galería/álbum.
 * Flujo: subir cada foto con published=false → recoger media_fbid → post al feed
 * con attached_media[].
 *
 * @param string[] $image_urls  URLs públicas, EN ORDEN.
 * @return array ['id'=>post_id, 'permalink'=>url]
 */
function meta_publicar_fb_album(string $page_id, string $page_token, string $caption, array $image_urls): array {
    $urls = array_values(array_filter(array_map('strval', $image_urls), fn($u) => trim($u) !== ''));
    if (!$urls) throw new MetaError('El álbum necesita al menos una imagen.');

    $params = ['message' => $caption, 'access_token' => $page_token];
    $n = 0;
    foreach ($urls as $u) {
        try {
            $r = meta_api('POST', "$page_id/photos", [
                'url'          => $u,
                'published'    => 'false',
                'access_token' => $page_token,
            ]);
            $fbid = $r['id'] ?? '';
            if ($fbid !== '') { $params["attached_media[$n]"] = json_encode(['media_fbid' => $fbid]); $n++; }
        } catch (MetaError $e) { error_log('fb_album foto: ' . $e->getMessage()); }
    }
    if ($n === 0) throw new MetaError('FB no aceptó ninguna foto del álbum.');

    $r = meta_api('POST', "$page_id/feed", $params);
    $post_id = $r['id'] ?? '';
    if ($post_id === '') throw new MetaError('FB no devolvió el id del álbum.');
    return ['id' => $post_id, 'permalink' => 'https://www.facebook.com/' . $post_id];
}

// ── INSIGHTS (alcance / interacciones de un post ya publicado) ─
//  Solo lectura. Para la PROPIA cuenta del dueño esto funciona
//  aun con la app de Meta en modo desarrollo (App Review solo hace
//  falta para operar cuentas de TERCEROS). Todo es tolerante: si
//  Meta no da una métrica, queda null (nunca cero inventado).

/** Suma las interacciones disponibles (ignora las que vengan null). */
function _meta_interacciones(array $o): ?int {
    $p = array_filter(
        [$o['me_gusta'] ?? null, $o['comentarios'] ?? null, $o['guardados'] ?? null, $o['compartidos'] ?? null],
        fn($x) => $x !== null
    );
    return $p ? array_sum($p) : null;
}

/**
 * Insights de UN media de Instagram. Combina conteos del nodo
 * (like_count/comments_count, fiables) con el edge /insights
 * (reach, saved, shares — puede variar por tipo de media).
 * @return array claves alcance,me_gusta,comentarios,guardados,compartidos,interacciones,crudo
 */
function meta_insights_ig(string $media_id, string $token): array {
    $o = ['alcance'=>null,'impresiones'=>null,'me_gusta'=>null,'comentarios'=>null,'guardados'=>null,'compartidos'=>null,'crudo'=>null];
    // 1) Conteos directos del nodo (siempre disponibles).
    try {
        $n = meta_api('GET', $media_id, ['fields'=>'like_count,comments_count','access_token'=>$token]);
        if (isset($n['like_count']))     $o['me_gusta']    = (int)$n['like_count'];
        if (isset($n['comments_count'])) $o['comentarios'] = (int)$n['comments_count'];
    } catch (MetaError $e) { /* nodo sin permiso: seguimos con insights */ }
    // 2) Insights: VIEWS (la métrica titular de IG desde 2025 — la que el dueño ve
    //    en la app) + reach + saved + shares. Tolerante a métricas ausentes; si
    //    'views' no existe en esta cuenta/versión, cae a la petición clásica.
    //    Las views se guardan en la columna 'impresiones' (su sucesora).
    $crudo = null;
    $leer = function(array $r) use (&$o) {
        foreach ($r['data'] ?? [] as $m) {
            $val = $m['values'][0]['value'] ?? null;
            if ($val === null) continue;
            if ($m['name']==='views')  $o['impresiones'] = (int)$val;
            if ($m['name']==='reach')  $o['alcance']     = (int)$val;
            if ($m['name']==='saved')  $o['guardados']   = (int)$val;
            if ($m['name']==='shares') $o['compartidos'] = (int)$val;
        }
    };
    try {
        $r = meta_api('GET', "$media_id/insights", ['metric'=>'views,reach,saved,shares','access_token'=>$token]);
        $leer($r); $crudo = $r;
    } catch (MetaError $e) {
        try {
            $r = meta_api('GET', "$media_id/insights", ['metric'=>'reach,saved,shares','access_token'=>$token]);
            $leer($r); $crudo = $r;
        } catch (MetaError $e1) {
            // Fallback final: solo reach (la métrica más universal).
            try {
                $r = meta_api('GET', "$media_id/insights", ['metric'=>'reach','access_token'=>$token]);
                $val = $r['data'][0]['values'][0]['value'] ?? null;
                if ($val !== null) $o['alcance'] = (int)$val;
                $crudo = $r;
            } catch (MetaError $e2) { /* sin insights: quedan null */ }
        }
    }
    $o['crudo']         = $crudo ? json_encode($crudo, JSON_UNESCAPED_UNICODE) : null;
    $o['interacciones'] = _meta_interacciones($o);
    return $o;
}

/**
 * Insights de UN post de una Página de Facebook. Reacciones /
 * comentarios / shares vienen por summary; el alcance por el edge
 * de insights (post_impressions_unique).
 */
function meta_insights_fb(string $post_id, string $token): array {
    $o = ['alcance'=>null,'me_gusta'=>null,'comentarios'=>null,'guardados'=>null,'compartidos'=>null,'crudo'=>null];
    $crudo = [];
    // 1) Reacciones / comentarios / shares (summary, sin traer las listas).
    try {
        $n = meta_api('GET', $post_id, [
            'fields'       => 'reactions.summary(total_count).limit(0),comments.summary(total_count).limit(0),shares',
            'access_token' => $token,
        ]);
        if (isset($n['reactions']['summary']['total_count'])) $o['me_gusta']    = (int)$n['reactions']['summary']['total_count'];
        if (isset($n['comments']['summary']['total_count']))  $o['comentarios'] = (int)$n['comments']['summary']['total_count'];
        if (isset($n['shares']['count']))                     $o['compartidos'] = (int)$n['shares']['count'];
        $crudo['nodo'] = $n;
    } catch (MetaError $e) { /* seguimos con el alcance */ }
    // 2) Alcance (personas únicas alcanzadas).
    try {
        $r = meta_api('GET', "$post_id/insights/post_impressions_unique", ['access_token'=>$token]);
        $val = $r['data'][0]['values'][0]['value'] ?? null;
        if ($val !== null) $o['alcance'] = (int)$val;
        $crudo['insights'] = $r;
    } catch (MetaError $e) { /* alcance no disponible: null */ }
    $o['crudo']         = $crudo ? json_encode($crudo, JSON_UNESCAPED_UNICODE) : null;
    $o['interacciones'] = _meta_interacciones($o);
    return $o;
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
