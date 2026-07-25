<?php
// ============================================================
//  CRECER — Adaptador de RENDER de video  (includes/render.php)
//  Módulo AISLADO del Reels Studio. NO toca ni depende de nada
//  del app existente (solo curl nativo).
//
//  Hoy: Shotstack (timeline JSON → mp4). Mañana se puede cambiar
//  de proveedor tocando SOLO este archivo (Creatomate/JSON2Video).
//
//  La API key sale de (en orden):
//    1) const SHOTSTACK_API_KEY  (si algún config la define)
//    2) includes/shotstack.key   (una línea; auto-ignorado por *.key)
//  Sin key → render_disponible()=false (el módulo cae en modo demo).
//
//  Entorno: SHOTSTACK_ENV = 'stage' (sandbox, gratis, con marca de
//  agua) o 'v1' (producción). Default 'stage'.
// ============================================================

if (!defined('RENDER_PROVIDER')) define('RENDER_PROVIDER', 'shotstack');
if (!defined('SHOTSTACK_ENV'))   define('SHOTSTACK_ENV', 'stage'); // 'stage' | 'v1'

/** Lee la API key del proveedor (const o archivo .key). Cacheada. */
function render_key(): string {
    static $k = null;
    if ($k !== null) return $k;
    if (defined('SHOTSTACK_API_KEY') && SHOTSTACK_API_KEY !== '') { return $k = SHOTSTACK_API_KEY; }
    $f = __DIR__ . '/shotstack.key';
    $k = is_file($f) ? trim((string)file_get_contents($f)) : '';
    return $k;
}

/** ¿Hay con qué renderizar de verdad? */
function render_disponible(): bool {
    return RENDER_PROVIDER === 'shotstack' && render_key() !== '';
}

/** Base del endpoint según entorno (sandbox vs producción). */
function render_base(): string {
    $env = SHOTSTACK_ENV === 'v1' ? 'v1' : 'stage';
    return "https://api.shotstack.io/edit/{$env}";
}

/**
 * Envía un documento de render {timeline, output} y devuelve el id.
 * @return array ['ok'=>bool, 'id'=>?string, 'error'=>?string, 'http'=>int]
 */
function render_enviar(array $doc): array {
    if (!render_disponible()) {
        return ['ok'=>false, 'id'=>null, 'error'=>'render no disponible (falta SHOTSTACK key)', 'http'=>0];
    }
    $r = render_http('POST', render_base() . '/render', $doc);
    if (!$r['ok']) return ['ok'=>false, 'id'=>null, 'error'=>$r['error'], 'http'=>$r['http']];
    $id = $r['data']['response']['id'] ?? null;
    if (!$id) return ['ok'=>false, 'id'=>null, 'error'=>'sin id de render: ' . substr($r['raw'],0,300), 'http'=>$r['http']];
    return ['ok'=>true, 'id'=>$id, 'error'=>null, 'http'=>$r['http']];
}

/**
 * Consulta el estado de un render.
 * status: queued | fetching | rendering | saving | done | failed
 * @return array ['ok'=>bool,'status'=>?string,'url'=>?string,'poster'=>?string,'error'=>?string]
 */
function render_estado(string $id): array {
    if (!render_disponible()) return ['ok'=>false, 'status'=>null, 'error'=>'render no disponible'];
    $r = render_http('GET', render_base() . '/render/' . rawurlencode($id));
    if (!$r['ok']) return ['ok'=>false, 'status'=>null, 'error'=>$r['error']];
    $resp = $r['data']['response'] ?? [];
    return [
        'ok'     => true,
        'status' => $resp['status'] ?? null,
        'url'    => $resp['url'] ?? null,
        'poster' => $resp['poster'] ?? null,
        'error'  => $resp['error'] ?? null,
    ];
}

/** cURL interno del adaptador. @return ['ok','data','raw','http','error'] */
function render_http(string $metodo, string $url, ?array $body = null): array {
    $ch = curl_init($url);
    $headers = ['x-api-key: ' . render_key(), 'Content-Type: application/json'];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_CUSTOMREQUEST  => $metodo,
    ];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    curl_setopt_array($ch, $opts);
    $raw  = curl_exec($ch);
    if ($raw === false) { $e = curl_error($ch); curl_close($ch); return ['ok'=>false,'data'=>null,'raw'=>'','http'=>0,'error'=>'cURL: '.$e]; }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string)$raw, true);
    if ($code < 200 || $code >= 300) {
        $msg = $data['message'] ?? substr((string)$raw, 0, 300);
        return ['ok'=>false, 'data'=>$data, 'raw'=>(string)$raw, 'http'=>$code, 'error'=>"HTTP $code: $msg"];
    }
    return ['ok'=>true, 'data'=>$data, 'raw'=>(string)$raw, 'http'=>$code, 'error'=>null];
}
