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

const ARTE_WORKER_KEY = 'crarte_5x8p';

/** ¿Está activo el motor Responses para producción? */
function img_resp_activo(): bool {
    return (defined('IMAGE_ENGINE') ? IMAGE_ENGINE : 'actual') === 'responses';
}

/**
 * Dispara el worker de arte por auto-HTTP (fire-and-forget): sondea el job en
 * background hasta que la imagen esté y AVISA por notificación (campanita). Así el
 * dueño encola y sigue editando / se va; la notificación lo lleva al post listo.
 */
function arte_disparar(int $marca_id, int $post_id, ?bool $con_texto = null, ?string $extra = null, bool $fb = false): void {
    $host = $_SERVER['HTTP_HOST'] ?? 'encuentraloahora.com';
    $q = '&ct=' . ($con_texto === null ? 'x' : ($con_texto ? '1' : '0'));
    if ($extra !== null && trim($extra) !== '') $q .= '&extra=' . rawurlencode(mb_substr(trim($extra), 0, 300));
    if ($fb) $q .= '&fb=1';   // re-disparo: ir DIRECTO a Gemini (gpt no pudo)
    $url  = 'https://' . $host . '/crecer/panel/arte_worker.php?marca=' . $marca_id . '&id=' . $post_id . '&key=' . ARTE_WORKER_KEY . $q;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER    => true,
        CURLOPT_CONNECTTIMEOUT_MS => 1500,
        CURLOPT_TIMEOUT_MS        => 3000,   // el worker flushea 'ok' al instante; sigue solo
        CURLOPT_NOSIGNAL          => 1,
        CURLOPT_SSL_VERIFYPEER    => false,
    ]);
    curl_exec($ch); curl_close($ch);
}

/**
 * Brief natural (el que ganó en el lab) + reglas de marca.
 * @param $con_texto  true = anuncio con texto · false = foto SIN texto · null = el modelo decide (variedad)
 * @param $tiene_logo true = se adjunta el logo REAL del negocio (úsalo, no inventes)
 */
function img_resp_brief(array $m, string $copy, ?bool $con_texto = null, bool $tiene_logo = false, ?string $extra = null): string {
    $nombre  = trim((string)($m['nombre_negocio'] ?? ''));
    $desc    = trim((string)($m['descripcion'] ?? ''));
    $publico = trim((string)($m['publico_objetivo'] ?? ''));
    $prods_raw = $m['productos'] ?? [];
    if (is_string($prods_raw)) $prods_raw = json_decode($prods_raw, true) ?: [];
    $plist = [];
    foreach ((array)$prods_raw as $p) { $n = is_array($p) ? trim((string)($p['nombre'] ?? '')) : trim((string)$p); if ($n !== '') $plist[] = $n; }
    $prods = implode(', ', $plist);

    // Regla de TEXTO (que no SIEMPRE meta letras).
    if ($con_texto === true)       $regla_texto = "Esta pieza SÍ lleva texto de anuncio: titular corto y potente, y un CTA breve. Poco texto, bien jerarquizado y sin errores de ortografía en español.";
    elseif ($con_texto === false)  $regla_texto = "NO pongas texto ni letras dentro de la imagen: una fotografía publicitaria potente y limpia que hable por sí sola.";
    else                           $regla_texto = "Tú decides si la pieza lleva algo de texto de anuncio o si es una foto limpia sin texto — elige lo que MEJOR detenga el scroll para este negocio; no metas texto por meterlo.";

    // Regla de LOGO/MARCA (que no invente).
    if ($tiene_logo) $regla_logo = "Se adjunta el LOGO REAL del negocio: úsalo EXACTAMENTE ese (intégralo con buen gusto, en una esquina o como marca discreta). NO inventes ni dibujes otro logo.";
    else             $regla_logo = "NO inventes un logotipo ni una marca gráfica falsa. Si muestras el nombre del negocio, escríbelo como texto limpio y correcto: \"{$nombre}\" — nunca un logo ficticio.";

    return "Crea una imagen publicitaria profesional para redes sociales (Facebook e Instagram) para este negocio puertorriqueño.\n\n"
         . "Negocio (nombre EXACTO, escríbelo sin errores): {$nombre}\nQué hace: {$desc}\n"
         . ($prods !== '' ? "Productos: {$prods}\n" : '')
         . ($publico !== '' ? "Público: {$publico}\n" : '')
         . "\nTexto del post que la imagen va a acompañar:\n\"{$copy}\"\n\n"
         . "{$regla_texto}\n{$regla_logo}\n"
         . (($extra !== null && trim($extra) !== '') ? "Indicación extra del dueño (respétala con buen gusto): " . trim($extra) . "\n" : '')
         . "No inventes datos, precios ni promociones que no estén aquí.\n\n"
         . "La imagen debe detener el scroll y dar ganas de comprar. Genera la mejor imagen publicitaria posible.";
}

/**
 * Encola un trabajo Responses para una pieza de contenido. Guarda el response_id
 * en crecer_contenido.img_job (estado 'queued'). Devuelve el id, o '' si falla
 * (el llamador cae al motor viejo). Loguea en crecer_ia_log (evidencia XPRIZE #2).
 */
function img_resp_encolar(PDO $pdo, int $marca_id, int $post_id, string $copy, ?bool $con_texto = null, ?string $extra = null): string {
    try {
        $m = function_exists('leer_marca') ? leer_marca($pdo, $marca_id)
           : $pdo->query("SELECT * FROM crecer_marca WHERE id=" . (int)$marca_id)->fetch(PDO::FETCH_ASSOC);
        if (!$m) return '';
        // LOGO REAL del negocio (si subió/tiene uno) → se pasa como referencia para NO inventar.
        $logo = null;
        if (!empty($m['logo_path'])) {
            $labs = rtrim(UPLOADS_PATH, '/\\') . '/' . ltrim(str_replace(rtrim(UPLOADS_URL, '/'), '', (string)$m['logo_path']), '/');
            if (is_file($labs)) {
                $mime = (function_exists('mime_content_type') ? mime_content_type($labs) : '') ?: 'image/png';
                $logo = ['data' => base64_encode((string)file_get_contents($labs)), 'mime' => $mime];
            }
        }
        $brief = img_resp_brief($m, $copy, $con_texto, $logo !== null, $extra);
        $bg = openai_responses_crear_bg($brief, ['aspect' => '1:1'] + ($logo ? ['logo' => $logo] : []));
        $pdo->prepare("UPDATE crecer_contenido SET img_job=?, img_estado='queued' WHERE id=? AND marca_id=?")
            ->execute([$bg['id'], $post_id, $marca_id]);
        try {
            $pdo->prepare("INSERT INTO crecer_ia_log (marca_id,agente,accion,modelo,prompt,respuesta,estado)
                           VALUES (?,?,?,?,?,?, 'ok')")
                ->execute([$marca_id, 'director_imagen', 'Encolar anuncio (Responses/gpt-image-2)',
                           'responses:' . ($bg['modelo'] ?? ''), $brief, $bg['id']]);
        } catch (Throwable $e) { /* log best-effort */ }
        return $bg['id'];
    } catch (Throwable $e) {
        error_log('img_resp_encolar: ' . $e->getMessage());
        // Deja el error EXACTO en el log (para ver por qué gpt-image-2 cae a Gemini: 429/key/modelo/tool).
        try { $pdo->prepare("INSERT INTO crecer_ia_log (marca_id,agente,accion,modelo,prompt,respuesta,estado,error_msg)
                             VALUES (?,?,?,?,?,?, 'error', ?)")
            ->execute([$marca_id, 'director_imagen', 'gpt-image-2 NO pudo crear el job', 'responses', mb_substr($copy, 0, 400), '', mb_substr($e->getMessage(), 0, 400)]); } catch (Throwable $e2) {}
        return '';
    }
}

/**
 * RESPALDO: si gpt-image-2 (Responses) no pudo, genera con GEMINI (Nano Banana Pro,
 * gemini-3-pro-image) y guarda en la pieza. Usa el logo real si hay. Devuelve la URL o ''.
 * Corre donde haya tiempo (worker), NUNCA en la pantalla del dueño.
 */
function img_gemini_fallback(PDO $pdo, int $marca_id, int $post_id, string $copy): string {
    try {
        $m = function_exists('leer_marca') ? leer_marca($pdo, $marca_id)
           : $pdo->query("SELECT * FROM crecer_marca WHERE id=" . (int)$marca_id)->fetch(PDO::FETCH_ASSOC);
        if (!$m) return '';
        $imgs = [];
        if (!empty($m['logo_path'])) {
            $labs = rtrim(UPLOADS_PATH, '/\\') . '/' . ltrim(str_replace(rtrim(UPLOADS_URL, '/'), '', (string)$m['logo_path']), '/');
            if (is_file($labs)) { $mime = (function_exists('mime_content_type') ? mime_content_type($labs) : '') ?: 'image/png';
                $imgs[] = ['data' => base64_encode((string)file_get_contents($labs)), 'mime' => $mime]; }
        }
        $brief = img_resp_brief($m, $copy, null, !empty($imgs));
        $r = gemini_imagen($brief, ['modelo' => 'gemini-3-pro-image', 'aspect' => '1:1'] + ($imgs ? ['imagenes' => $imgs] : []));
        $bin = $r['data'] ?? '';
        if ($bin === '') return '';
        $rel = "marca_{$marca_id}/graficas/gem_{$post_id}_" . substr(md5((string)microtime(true)), 0, 6) . '.png';
        $abs = rtrim(UPLOADS_PATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        @mkdir(dirname($abs), 0775, true); @file_put_contents($abs, $bin);
        $url = rtrim(UPLOADS_URL, '/') . '/' . $rel;
        $pdo->prepare("UPDATE crecer_contenido SET grafica_path=?, img_estado='ok', img_job=NULL, updated_at=NOW() WHERE id=? AND marca_id=?")
            ->execute([$url, $post_id, $marca_id]);
        try { $pdo->prepare("INSERT INTO crecer_ia_log (marca_id,agente,accion,modelo,prompt,respuesta,estado) VALUES (?,?,?,?,?,?, 'ok')")
            ->execute([$marca_id, 'director_imagen', 'Respaldo Gemini (gpt no pudo)', 'gemini-3-pro-image', $brief, $url]); } catch (Throwable $e) {}
        return $url;
    } catch (Throwable $e) { error_log('img_gemini_fallback: ' . $e->getMessage()); return ''; }
}

/**
 * SWEEP: al volver a cualquier pantalla, recoge los jobs de imagen que ya terminaron en
 * OpenAI (el worker muere en Hostinger antes de que gpt-image-2 acabe) → guarda la imagen
 * Y CREA la notificación (el worker no alcanzó). Si gpt cayó → re-dispara Gemini. No bloquea:
 * cada job es un GET corto; tope de 4. Llamar en GET de las pantallas principales.
 */
function img_sweep_pendientes(PDO $pdo, int $marca_id): void {
    try {
        $pend = $pdo->prepare("SELECT id FROM crecer_contenido WHERE marca_id=? AND img_estado='queued' AND img_job IS NOT NULL ORDER BY id DESC LIMIT 4");
        $pend->execute([$marca_id]);
        $ids = $pend->fetchAll(PDO::FETCH_COLUMN);
        if (!$ids) return;
        if (!function_exists('notif_crear')) { @require_once __DIR__ . '/notif.php'; }
        $link = '/crecer/panel/propuestas.php?marca=' . $marca_id;
        foreach ($ids as $pid) {
            $r = img_resp_completar($pdo, $marca_id, (int)$pid);
            $est = $r['estado'] ?? '';
            if ($est === 'ok' && function_exists('notif_crear')) {
                notif_crear($pdo, $marca_id, 'arte', 'Tu arte ya está listo',
                    'El corillo terminó la imagen de tu post — dale un vistazo.', $link, 'image');
            } elseif ($est === 'error' && function_exists('arte_disparar')) {
                arte_disparar($marca_id, (int)$pid, null, null, true);   // gpt cayó → Gemini en background
            }
        }
    } catch (Throwable $e) {}
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

// ─── LOGOS por Responses (gpt-image-2) — más preciso, sobre todo el nombre/tipografía ───

/** Encola un LOGO (prompt ya armado) por Responses. Inserta un crecer_logos pendiente. Devuelve su id o 0. */
function logo_resp_encolar(PDO $pdo, int $marca_id, string $prompt): int {
    try {
        $bg = openai_responses_crear_bg($prompt, ['aspect' => '1:1']);
        $pdo->prepare("INSERT INTO crecer_logos (marca_id, archivo, job, estado) VALUES (?, NULL, ?, 'queued')")
            ->execute([$marca_id, $bg['id']]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) { error_log('logo_resp_encolar: ' . $e->getMessage()); return 0; }
}

/** ¿Hay algún logo generándose en background para esta marca? */
function logo_resp_pendiente(PDO $pdo, int $marca_id): bool {
    try { return (bool)$pdo->query("SELECT COUNT(*) FROM crecer_logos WHERE marca_id=" . (int)$marca_id . " AND estado='queued'")->fetchColumn(); }
    catch (Throwable $e) { return false; }
}

/** Consulta los logos pendientes; guarda los que completaron. Devuelve ['listo'=>bool,'pendiente'=>bool]. */
function logo_resp_completar(PDO $pdo, int $marca_id): array {
    $listo = false;
    try {
        $rows = $pdo->query("SELECT id, job FROM crecer_logos WHERE marca_id=" . (int)$marca_id . " AND estado='queued' AND job IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return ['listo' => false, 'pendiente' => false]; }
    foreach ($rows as $r) {
        try {
            $st = openai_responses_estado((string)$r['job']);
            if (($st['status'] ?? '') === 'completed' && ($st['b64'] ?? '') !== '') {
                $bin = base64_decode($st['b64']);
                $rel = "marca_{$marca_id}/logo_resp_{$r['id']}.png";
                $abs = rtrim(UPLOADS_PATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                @mkdir(dirname($abs), 0775, true); @file_put_contents($abs, $bin);
                $url = rtrim(UPLOADS_URL, '/') . '/' . $rel;
                $pdo->prepare("UPDATE crecer_logos SET archivo=?, estado='ok', job=NULL WHERE id=?")->execute([$url, $r['id']]);
                $listo = true;
            } elseif (in_array($st['status'] ?? '', ['failed', 'cancelled', 'incomplete'], true)) {
                $pdo->prepare("DELETE FROM crecer_logos WHERE id=? AND estado='queued'")->execute([$r['id']]);   // limpia el fallido
                $listo = true;
            }
        } catch (Throwable $e) { /* transitorio */ }
    }
    return ['listo' => $listo, 'pendiente' => logo_resp_pendiente($pdo, $marca_id)];
}
