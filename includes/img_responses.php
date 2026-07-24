<?php
// ============================================================
//  CRECER — Imagen de PRODUCCIÓN por Responses (gpt-image-2) en BACKGROUND.
//
//  El ganador (3/3 pruebas ciegas): el modelo se dirige solo vía Responses API
//  (herramienta image_generation → gpt-image-2) y escribe su propio prompt de
//  anuncio. Corre en background (crear→id en <2s) y el frontend hace polling —
//  inmune al 504 de Hostinger. Reversible con el flag IMAGE_ENGINE.
//
//  Flag: define('IMAGE_ENGINE','responses') = activo · 'actual' = motor viejo.
// ============================================================
require_once __DIR__ . '/ia.php';

/** ¿Está activo el motor Responses para producción? */
function img_resp_activo(): bool {
    return (defined('IMAGE_ENGINE') ? IMAGE_ENGINE : 'actual') === 'responses';
}

/** Brief natural (mismo que ganó en el laboratorio) a partir del negocio + copy. */
function img_resp_brief(array $m, string $copy): string {
    $nombre  = trim((string)($m['nombre_negocio'] ?? ''));
    $desc    = trim((string)($m['descripcion'] ?? ''));
    $publico = trim((string)($m['publico_objetivo'] ?? ''));
    $prods_raw = $m['productos'] ?? [];
    if (is_string($prods_raw)) $prods_raw = json_decode($prods_raw, true) ?: [];
    $plist = [];
    foreach ((array)$prods_raw as $p) { $n = is_array($p) ? trim((string)($p['nombre'] ?? '')) : trim((string)$p); if ($n !== '') $plist[] = $n; }
    $prods = implode(', ', $plist);
    return "Crea una imagen publicitaria profesional para redes sociales (Facebook e Instagram) para este negocio puertorriqueño.\n\n"
         . "Negocio: {$nombre}\nQué hace: {$desc}\n"
         . ($prods !== '' ? "Productos: {$prods}\n" : '')
         . ($publico !== '' ? "Público: {$publico}\n" : '')
         . "\nTexto del post que la imagen va a acompañar:\n\"{$copy}\"\n\n"
         . "La imagen debe detener el scroll y dar ganas de comprar. Genera la mejor imagen publicitaria posible.";
}

/**
 * Encola un trabajo Responses para una pieza de contenido. Guarda el response_id
 * en crecer_contenido.img_job (estado 'queued'). Devuelve el id, o '' si falla
 * (el llamador cae al motor viejo). Loguea en crecer_ia_log (evidencia XPRIZE #2).
 */
function img_resp_encolar(PDO $pdo, int $marca_id, int $post_id, string $copy): string {
    try {
        $m = function_exists('leer_marca') ? leer_marca($pdo, $marca_id)
           : $pdo->query("SELECT * FROM crecer_marca WHERE id=" . (int)$marca_id)->fetch(PDO::FETCH_ASSOC);
        if (!$m) return '';
        $brief = img_resp_brief($m, $copy);
        $bg = openai_responses_crear_bg($brief, ['aspect' => '1:1']);
        $pdo->prepare("UPDATE crecer_contenido SET img_job=?, img_estado='queued' WHERE id=? AND marca_id=?")
            ->execute([$bg['id'], $post_id, $marca_id]);
        try {
            $pdo->prepare("INSERT INTO crecer_ia_log (marca_id,agente,accion,modelo,prompt,respuesta,estado)
                           VALUES (?,?,?,?,?,?, 'ok')")
                ->execute([$marca_id, 'director_imagen', 'Encolar anuncio (Responses/gpt-image-2)',
                           'responses:' . ($bg['modelo'] ?? ''), $brief, $bg['id']]);
        } catch (Throwable $e) { /* log best-effort */ }
        return $bg['id'];
    } catch (Throwable $e) { error_log('img_resp_encolar: ' . $e->getMessage()); return ''; }
}

/**
 * Consulta el trabajo pendiente de una pieza; si completó, GUARDA la imagen y
 * actualiza crecer_contenido. Devuelve ['estado'=>ok|queued|error|none, 'img'=>url|null].
 * Idempotente: si ya no hay job pendiente, reporta el estado actual.
 */
function img_resp_completar(PDO $pdo, int $marca_id, int $post_id): array {
    $row = $pdo->query("SELECT img_job,img_estado,grafica_path FROM crecer_contenido WHERE id=" . (int)$post_id)->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['estado' => 'error', 'img' => null];
    $rid = trim((string)($row['img_job'] ?? ''));
    if ($rid === '') return ['estado' => ($row['grafica_path'] ? 'ok' : 'none'), 'img' => $row['grafica_path'] ?: null];
    try {
        $st = openai_responses_estado($rid);
        if (($st['status'] ?? '') === 'completed' && ($st['b64'] ?? '') !== '') {
            $bin = base64_decode($st['b64']);
            $rel = "marca_{$marca_id}/graficas/resp_{$post_id}_" . substr(md5((string)microtime(true)), 0, 6) . '.png';
            $abs = rtrim(UPLOADS_PATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            @mkdir(dirname($abs), 0775, true); @file_put_contents($abs, $bin);
            $url = rtrim(UPLOADS_URL, '/') . '/' . $rel;
            $pdo->prepare("UPDATE crecer_contenido SET grafica_path=?, img_estado='ok', img_job=NULL, updated_at=NOW() WHERE id=? AND marca_id=?")
                ->execute([$url, $post_id, $marca_id]);
            return ['estado' => 'ok', 'img' => $url];
        }
        if (in_array($st['status'] ?? '', ['failed', 'cancelled', 'incomplete'], true)) {
            $pdo->prepare("UPDATE crecer_contenido SET img_estado='error', img_job=NULL WHERE id=? AND marca_id=?")->execute([$post_id, $marca_id]);
            return ['estado' => 'error', 'img' => null];
        }
        return ['estado' => 'queued', 'img' => null];   // in_progress / queued
    } catch (Throwable $e) { return ['estado' => 'queued', 'img' => null]; }   // transitorio → reintenta el próximo poll
}
