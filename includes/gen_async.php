<?php
// ============================================================
//  CRECER — Generación de imágenes V3 ASÍNCRONA  (includes/gen_async.php)
//
//  El request ENCOLA y responde al instante; un worker corre por detrás
//  (fastcgi_finish_request + auto-HTTP) las 2 llamadas — gpt-5.5 → gpt-image-1 —
//  y actualiza la BD. El frontend hace polling. Estados: queued → directing →
//  generating → completed | failed.
//
//  REGLA CRÍTICA: SIN fallback silencioso. Si falla el modelo creativo, la
//  generación se marca 'failed' con el error exacto — NUNCA se genera con otro
//  prompt (nada de rulebook) sin informar. (strict=true en el director.)
// ============================================================

require_once __DIR__ . '/worker_key.php';
// CR-F01b: sin CRECER_WORKER_KEY no hay llave. NADA de literal de respaldo:
// adoptar en silencio una llave del repo publico era la trampa.
if (!defined('GEN_WORKER_KEY')) define('GEN_WORKER_KEY', worker_key());

function _gen_set(PDO $pdo, int $id, array $f): void {
    if (!$f) return;
    $set = []; $vals = [];
    foreach ($f as $k => $v) { $set[] = "{$k}=?"; $vals[] = $v; }
    $vals[] = $id;
    try { $pdo->prepare("UPDATE crecer_generaciones SET " . implode(',', $set) . " WHERE id=?")->execute($vals); } catch (Throwable $e) {}
}

/** Encola una generación → devuelve el id (estado 'queued'). */
function gen_encolar(PDO $pdo, int $marca_id, string $copy, array $opts = []): int {
    $pdo->prepare("INSERT INTO crecer_generaciones (marca_id, contenido_id, estado, copy_text) VALUES (?,?, 'queued', ?)")
        ->execute([$marca_id, $opts['contenido_id'] ?? null, $copy]);
    return (int)$pdo->lastInsertId();
}

/** Dispara el worker por auto-HTTP, fire-and-forget (el worker responde YA y sigue por detrás). */
function gen_disparar(int $id): void {
    // CR-F01b: sin llave no se dispara. El job se queda en cola y lo rescata el
    // sweep cuando el config vuelva — mejor eso que quemar el intento contra un 503.
    if (!worker_puede_disparar('gen')) return;
    $host = $_SERVER['HTTP_HOST'] ?? 'encuentraloahora.com';
    $url  = 'https://' . $host . '/crecer/panel/gen_worker.php?id=' . $id . '&key=' . GEN_WORKER_KEY;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT_MS => 1500,
        CURLOPT_TIMEOUT_MS        => 3000,   // el worker flushea 'ok' al instante; luego sigue solo
        CURLOPT_NOSIGNAL          => 1,
        CURLOPT_SSL_VERIFYPEER    => false,
    ]);
    curl_exec($ch); curl_close($ch);         // ignoramos el resultado a propósito
}

/**
 * EL WORKER — corre las 2 llamadas y guarda. STRICT: sin fallback silencioso.
 * Actualiza estado + duraciones + modelos + error exacto en crecer_generaciones.
 */
function gen_procesar(PDO $pdo, int $id): void {
    @set_time_limit(0);
    $g = $pdo->query("SELECT * FROM crecer_generaciones WHERE id=" . (int)$id)->fetch(PDO::FETCH_ASSOC);
    if (!$g || $g['estado'] === 'completed') return;
    $mid = (int)$g['marca_id']; $copy = (string)$g['copy_text'];
    $t_all = microtime(true);
    require_once __DIR__ . '/agentes.php';
    require_once __DIR__ . '/image_messenger.php';

    try { $m = leer_marca($pdo, $mid); }
    catch (Throwable $e) { _gen_set($pdo, $id, ['estado'=>'failed', 'error_msg'=>'marca: ' . substr($e->getMessage(),0,300)]); return; }

    // 1) DIRECTING — gpt-5.5 escribe la escena (strict: sin fallback).
    _gen_set($pdo, $id, ['estado'=>'directing']);
    $b = image_messenger_build($pdo, $mid, $m, $copy);
    $modelo_cfg = defined('IMAGE_CREATIVE_MODEL') ? IMAGE_CREATIVE_MODEL : 'openai:creative';
    try {
        $d = director_creativo_llm($pdo, $mid, $b['sistema'], $b['mensaje'], $modelo_cfg, ['strict'=>true]);
    } catch (Throwable $e) {
        $http = null; if (preg_match('/HTTP (\d{3})/', $e->getMessage(), $mm)) $http = (int)$mm[1];
        _gen_set($pdo, $id, ['estado'=>'failed', 'modelo_texto'=>$modelo_cfg, 'http_status'=>$http,
            'error_msg'=>'texto: ' . substr($e->getMessage(),0,400), 'dur_total_ms'=>(int)round((microtime(true)-$t_all)*1000)]);
        return;
    }
    $escena = trim((string)($d['texto'] ?? ''));
    if ($escena === '') { _gen_set($pdo, $id, ['estado'=>'failed', 'modelo_texto'=>$d['modelo']??$modelo_cfg, 'error_msg'=>'texto vacío']); return; }
    _gen_set($pdo, $id, ['modelo_texto'=>$d['modelo']??'', 'prompt_narrativo'=>$escena,
        'dur_texto_ms'=>$d['dur_ms']??null, 'fallback'=>!empty($d['fallback'])?1:0, 'estado'=>'generating']);

    // 2) GENERATING — gpt-image-1 DIRECTO (sin motor_imagen ni fallback a Gemini).
    $ti = microtime(true);
    try {
        $img = openai_imagen($escena, ['aspect'=>'1:1']);
    } catch (Throwable $e) {
        $http = null; if (preg_match('/HTTP (\d{3})/', $e->getMessage(), $mm)) $http = (int)$mm[1];
        _gen_set($pdo, $id, ['estado'=>'failed', 'modelo_imagen'=>'gpt-image-1', 'http_status'=>$http,
            'error_msg'=>'imagen: ' . substr($e->getMessage(),0,400),
            'dur_imagen_ms'=>(int)round((microtime(true)-$ti)*1000), 'dur_total_ms'=>(int)round((microtime(true)-$t_all)*1000)]);
        return;
    }
    $dur_img = (int)round((microtime(true)-$ti)*1000);

    // Guardar el archivo.
    $rel = "marca_{$mid}/graficas/gen_" . $id . '_' . substr(md5((string)microtime(true)), 0, 6) . '.png';
    $abs = rtrim(UPLOADS_PATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    @mkdir(dirname($abs), 0775, true);
    @file_put_contents($abs, $img['data']);
    $url = rtrim(UPLOADS_URL, '/') . '/' . $rel;

    _gen_set($pdo, $id, ['estado'=>'completed', 'modelo_imagen'=>$img['modelo']??'gpt-image-1',
        'dur_imagen_ms'=>$dur_img, 'archivo'=>$url, 'dur_total_ms'=>(int)round((microtime(true)-$t_all)*1000)]);
}

/** Estado de una generación (para el polling del frontend). */
function gen_estado(PDO $pdo, int $id): ?array {
    $r = $pdo->query("SELECT * FROM crecer_generaciones WHERE id=" . (int)$id)->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}
