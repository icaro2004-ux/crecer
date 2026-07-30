<?php
// ============================================================
//  CRECER — Carrusel (post multi-imagen que cuenta una historia)
//  includes/carrusel.php
//
//  EL GUIONISTA: agente especialista en carruseles que escribe la
//  historia slide a slide EN LA VOZ que el cliente le asignó. Dos
//  caminos: la IA genera el arte de cada slide (async, avisa por
//  notificación) o el cliente sube sus propias imágenes (al instante).
//  IG = carrusel swipe real; FB = álbum. Módulo aislado.
// ============================================================
require_once __DIR__ . '/agentes.php';
require_once __DIR__ . '/notif.php';

const CARRUSEL_WORKER_KEY = 'crcarr_8w3z';
const CARRUSEL_MIN = 3;   // mínimo útil
const CARRUSEL_MAX = 10;  // tope de Instagram

/**
 * EL GUIONISTA escribe el carrusel: caption + N slides (1=gancho … último=CTA),
 * en la VOZ y PERSONALIDAD que el dueño le asignó. Crea el post (tipo='carrusel')
 * y los slides (texto + brief visual). NO genera el arte todavía (eso es aparte:
 * la IA async o el cliente subiendo). Loguea como 'carruselista' (evidencia #2).
 *
 * @return array ['ok'=>bool, 'contenido_id'=>int, 'caption'=>string, 'slides'=>array]
 */
function carrusel_generar(PDO $pdo, int $marca_id, string $tema, int $n = 5): array {
    $n = max(CARRUSEL_MIN, min(CARRUSEL_MAX, $n));
    $tema = trim($tema);
    $m = leer_marca($pdo, $marca_id);
    if (!$m) return ['ok' => false, 'err' => 'marca'];

    $ctx    = cerebro_negocio($pdo, $marca_id, $m);
    $nombre = equipo_nombre($m, 'guionista');

    $sys = "Eres {$nombre}, EL GUIONISTA DE CARRUSELES del corillo de Crecer: experto en storytelling para "
        . "redes que arma CARRUSELES de Instagram que la gente DESLIZA hasta el final. Dominas la estructura:\n"
        . "• SLIDE 1 = gancho que frena el scroll y promete valor.\n"
        . "• SLIDES DEL MEDIO = un beat por slide (un paso, un tip, una parte de la historia); cada uno deja ganas de deslizar.\n"
        . "• ÚLTIMO SLIDE = cierre + llamada a la acción CLARA.\n"
        . "Texto CORTÍSIMO por slide (una idea, se lee de un vistazo).\n\n"
        . "VOZ Y PERSONALIDAD DE LA MARCA (respétala SIEMPRE — esta es tu voz):\n"
        . tono_instruccion($m) . "\n" . reglas_idioma($m) . "\n\n"
        . "REGLA DE ORO: atrevido en la FORMA, HONESTO en el FONDO — nunca inventes precios, productos ni promesas "
        . "que no estén en el negocio. Responde SOLO JSON.";

    $prompt = "Negocio:\n{$ctx}\n\n"
        . "Tema del carrusel: \"" . ($tema !== '' ? $tema : 'elige TÚ el mejor ángulo para este negocio y su público') . "\"\n"
        . "Cantidad de slides: {$n} (en orden, 1=gancho … último=CTA).\n\n"
        . 'Devuelve JSON EXACTO: '
        . '{"caption":"el pie de foto del post — hook al inicio, valor, y CTA; hashtags al final si aplican",'
        . '"slides":[{"titulo":"3-6 palabras que van GRANDES en el slide","texto":"1 frase corta de apoyo (o vacío)",'
        . '"visual":"qué se VE en la imagen del slide: escena, encuadre y mood, concreto y atractivo — NO la foto obvia"}]}'
        . " con EXACTAMENTE {$n} slides.";

    try {
        $r = ia_ejecutar($pdo, 'carruselista', 'Guion de carrusel', $prompt, [
            'marca_id' => $marca_id, 'sistema' => $sys, 'json' => true,
            'modelo' => defined('CRECER_COPILOTO_MODEL') ? CRECER_COPILOTO_MODEL : null,
            'temperatura' => 0.9, 'max_tokens' => 1300, 'thinking_budget' => 0,
            'mock_texto' => '{"caption":"Desliza 👉 te lo cuento completo","slides":[{"titulo":"Mira esto","texto":"","visual":"primer plano llamativo del producto"},{"titulo":"El secreto","texto":"","visual":"detalle del proceso, luz cálida"},{"titulo":"Te toca a ti","texto":"","visual":"llamada a la accion, fondo de color de marca"}]}',
        ]);
        $d = json_decode((string)$r['texto'], true) ?: [];
    } catch (Throwable $e) {
        error_log('carrusel_generar: ' . $e->getMessage());
        return ['ok' => false, 'err' => 'ia'];
    }

    $slides = $d['slides'] ?? [];
    if (!is_array($slides) || count($slides) < 2) return ['ok' => false, 'err' => 'sin_slides'];
    $slides  = array_slice($slides, 0, CARRUSEL_MAX);
    $caption = trim((string)($d['caption'] ?? ''));

    // Crear el post carrusel dentro del calendario del mes.
    $ca = (int)date('Y'); $cm = (int)date('n');
    $pdo->prepare("INSERT INTO crecer_calendario (marca_id,anio,mes,estado,generado_por_ia) VALUES (?,?,?, 'borrador',1) ON DUPLICATE KEY UPDATE updated_at=NOW()")->execute([$marca_id, $ca, $cm]);
    $calid = (int)$pdo->query("SELECT id FROM crecer_calendario WHERE marca_id={$marca_id} AND anio={$ca} AND mes={$cm}")->fetchColumn();
    $pdo->prepare("INSERT INTO crecer_contenido (calendario_id,marca_id,plataforma,tipo,caption,fecha_programada,estado) VALUES (?,?, 'instagram','carrusel',?,?, 'borrador')")
        ->execute([$calid, $marca_id, $caption, date('Y-m-d 10:00:00')]);
    $cid = (int)$pdo->lastInsertId();

    $ins = $pdo->prepare("INSERT INTO crecer_carrusel (contenido_id,marca_id,orden,idea) VALUES (?,?,?,?)");
    $orden = 1; $out = [];
    foreach ($slides as $s) {
        $titulo = trim((string)($s['titulo'] ?? ''));
        $texto  = trim((string)($s['texto'] ?? ''));
        $visual = trim((string)($s['visual'] ?? ''));
        $idea   = trim($titulo . ($texto !== '' ? " — {$texto}" : '') . ($visual !== '' ? "\n[visual] {$visual}" : ''));
        $ins->execute([$cid, $marca_id, $orden, $idea]);
        $out[] = ['id' => (int)$pdo->lastInsertId(), 'orden' => $orden, 'titulo' => $titulo, 'texto' => $texto, 'visual' => $visual];
        $orden++;
    }
    return ['ok' => true, 'contenido_id' => $cid, 'caption' => $caption, 'slides' => $out];
}

/**
 * El GUIONISTA AJUSTA el carrusel según el parecer del dueño (chat de revisión).
 * Reescribe caption + los slides MANTENIENDO la cantidad, en la voz del cliente.
 * Actualiza el texto de los slides (no borra las imágenes que ya tengan).
 *
 * @return array ['ok'=>bool, 'caption'=>string, 'slides'=>array]
 */
function carrusel_ajustar(PDO $pdo, int $marca_id, int $contenido_id, string $feedback): array {
    $feedback = trim($feedback);
    if ($feedback === '') return ['ok' => false, 'err' => 'vacio'];
    $m = leer_marca($pdo, $marca_id);
    if (!$m) return ['ok' => false, 'err' => 'marca'];
    $cap = (string)$pdo->query("SELECT caption FROM crecer_contenido WHERE id=" . (int)$contenido_id . " AND marca_id=" . (int)$marca_id)->fetchColumn();
    $slides = carrusel_slides($pdo, $contenido_id);
    if (!$slides) return ['ok' => false, 'err' => 'sin_slides'];
    $n = count($slides);

    $actual = "Pie de foto actual: \"{$cap}\"\nSlides actuales (en orden):\n";
    foreach ($slides as $i => $s) {
        $v = carrusel_slide_visual((string)$s['idea']);
        $actual .= ($i + 1) . '. ' . $v['copy'] . ($v['visual'] !== '' ? " [visual: {$v['visual']}]" : '') . "\n";
    }
    $ctx    = cerebro_negocio($pdo, $marca_id, $m);
    $nombre = equipo_nombre($m, 'guionista');
    $sys = "Eres {$nombre}, EL GUIONISTA DE CARRUSELES del corillo de Crecer. Ajustas un carrusel EXISTENTE según lo "
        . "que pide el dueño, manteniendo la estructura (1=gancho … último=CTA) y el texto CORTÍSIMO por slide.\n"
        . "VOZ Y PERSONALIDAD DE LA MARCA (respétala SIEMPRE):\n" . tono_instruccion($m) . "\n" . reglas_idioma($m) . "\n"
        . "REGLA DE ORO: atrevido en la forma, HONESTO en el fondo — no inventes precios, productos ni promesas. Responde SOLO JSON.";
    $prompt = "Negocio:\n{$ctx}\n\nCarrusel ACTUAL:\n{$actual}\n\nEl dueño pide este AJUSTE:\n\"{$feedback}\"\n\n"
        . "Reescribe el carrusel aplicando el ajuste, MANTENIENDO EXACTAMENTE {$n} slides en orden. "
        . 'Devuelve JSON EXACTO: {"caption":"…","slides":[{"titulo":"…","texto":"…","visual":"…"}]} con ' . $n . ' slides.';
    try {
        $r = ia_ejecutar($pdo, 'carruselista', 'Ajustar carrusel', $prompt, [
            'marca_id' => $marca_id, 'sistema' => $sys, 'json' => true,
            'modelo' => defined('CRECER_COPILOTO_MODEL') ? CRECER_COPILOTO_MODEL : null,
            'temperatura' => 0.85, 'max_tokens' => 1300, 'thinking_budget' => 0,
            'mock_texto' => '{"caption":"' . addslashes($cap) . '","slides":[]}',
        ]);
        $d = json_decode((string)$r['texto'], true) ?: [];
    } catch (Throwable $e) { error_log('carrusel_ajustar: ' . $e->getMessage()); return ['ok' => false, 'err' => 'ia']; }

    $ns = $d['slides'] ?? [];
    if (!is_array($ns) || count($ns) < 2) return ['ok' => false, 'err' => 'sin_slides'];
    $newCap = trim((string)($d['caption'] ?? $cap));
    if ($newCap !== '') $pdo->prepare("UPDATE crecer_contenido SET caption=?, updated_at=NOW() WHERE id=? AND marca_id=?")->execute([$newCap, $contenido_id, $marca_id]);

    // Actualiza el texto de cada slide por posición (no toca las imágenes).
    $out = [];
    $upd = $pdo->prepare("UPDATE crecer_carrusel SET idea=?, updated_at=NOW() WHERE id=? AND contenido_id=?");
    foreach ($slides as $i => $s) {
        $sd = $ns[$i] ?? null;
        if ($sd) {
            $titulo = trim((string)($sd['titulo'] ?? ''));
            $texto  = trim((string)($sd['texto'] ?? ''));
            $visual = trim((string)($sd['visual'] ?? ''));
            $idea   = trim($titulo . ($texto !== '' ? " — {$texto}" : '') . ($visual !== '' ? "\n[visual] {$visual}" : ''));
            if ($idea !== '') $upd->execute([$idea, (int)$s['id'], $contenido_id]);
            $out[] = ['id' => (int)$s['id'], 'orden' => (int)$s['orden'], 'titulo' => $titulo, 'texto' => $texto, 'visual' => $visual];
        }
    }
    return ['ok' => true, 'caption' => $newCap !== '' ? $newCap : $cap, 'slides' => $out];
}

/** Separa el brief VISUAL del texto de la idea de un slide. */
function carrusel_slide_visual(string $idea): array {
    $visual = '';
    if (preg_match('/\[visual\]\s*(.+)$/s', $idea, $mm)) $visual = trim($mm[1]);
    $copy = trim((string)preg_replace('/\n?\[visual\].*/s', '', $idea));
    return ['copy' => $copy, 'visual' => $visual];
}

/**
 * Genera el ARTE (IA) de UN slide y lo guarda. Reusa el mismo motor del post
 * sencillo (Responses/Gemini según reglas). Corre en el worker, NUNCA en la
 * pantalla del dueño. Devuelve true si quedó imagen.
 */
function carrusel_arte_slide(PDO $pdo, int $marca_id, int $slide_id): bool {
    $s = $pdo->prepare("SELECT * FROM crecer_carrusel WHERE id=? AND marca_id=?");
    $s->execute([$slide_id, $marca_id]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    if (trim((string)$row['grafica_path']) !== '') return true;   // ya tiene (cliente subió o ya se generó)

    $v = carrusel_slide_visual((string)$row['idea']);
    try {
        $g = generar_grafica($pdo, $marca_id, null, [
            'copy' => $v['copy'], 'con_texto' => false, 'con_logo' => true, 'instrucciones' => $v['visual'],
        ]);
        if (!empty($g['archivo'])) {
            $pdo->prepare("UPDATE crecer_carrusel SET grafica_path=?, img_estado='ok', updated_at=NOW() WHERE id=?")
                ->execute([$g['archivo'], $slide_id]);
            return true;
        }
    } catch (Throwable $e) { error_log('carrusel_arte_slide #' . $slide_id . ': ' . $e->getMessage()); }
    $pdo->prepare("UPDATE crecer_carrusel SET img_estado='error', updated_at=NOW() WHERE id=?")->execute([$slide_id]);
    return false;
}

/**
 * Encola un job Responses (background) por cada slide SIN imagen. RÁPIDO (crea→id
 * en <2s cada uno) y RESILIENTE: el job vive en OpenAI, así que aunque el worker
 * se muera en Hostinger, el sweep lo completa después. Guarda el job en
 * crecer_carrusel.img_job (estado 'queued'). Devuelve cuántos encoló, o -1 si el
 * motor Responses no está activo (→ el llamador cae al worker/Gemini sync).
 */
function carrusel_encolar_arte(PDO $pdo, int $marca_id, int $contenido_id): int {
    require_once __DIR__ . '/img_responses.php';
    if (!function_exists('img_resp_activo') || !img_resp_activo()) return -1;
    $m = leer_marca($pdo, $marca_id);
    if (!$m) return 0;
    // Logo real del negocio (para no inventar marca), igual que el post sencillo.
    $logo = null;
    if (!empty($m['logo_path'])) {
        $labs = rtrim(UPLOADS_PATH, '/\\') . '/' . ltrim(str_replace(rtrim(UPLOADS_URL, '/'), '', (string)$m['logo_path']), '/');
        if (is_file($labs)) {
            $mime = (function_exists('mime_content_type') ? mime_content_type($labs) : '') ?: 'image/png';
            $logo = ['data' => base64_encode((string)file_get_contents($labs)), 'mime' => $mime];
        }
    }
    $n = 0;
    foreach (carrusel_slides($pdo, $contenido_id) as $s) {
        if (trim((string)$s['grafica_path']) !== '') continue;         // ya tiene imagen (cliente subió o listo)
        if (trim((string)($s['img_job'] ?? '')) !== '') { $n++; continue; }  // ya encolado
        $v = carrusel_slide_visual((string)$s['idea']);
        try {
            $brief = img_resp_brief($m, $v['copy'], false, $logo !== null, $v['visual']);
            $bg = openai_responses_crear_bg($brief, ['aspect' => '1:1'] + ($logo ? ['logo' => $logo] : []));
            $pdo->prepare("UPDATE crecer_carrusel SET img_job=?, img_estado='queued', updated_at=NOW() WHERE id=?")->execute([$bg['id'], (int)$s['id']]);
            try { $pdo->prepare("INSERT INTO crecer_ia_log (marca_id,agente,accion,modelo,prompt,respuesta,estado) VALUES (?,?,?,?,?,?, 'ok')")
                ->execute([$marca_id, 'director_imagen', 'Encolar slide de carrusel (Responses)', 'responses', $brief, $bg['id']]); } catch (Throwable $e) {}
            $n++;
        } catch (Throwable $e) { error_log('carrusel_encolar_arte slide ' . $s['id'] . ': ' . $e->getMessage()); }
    }
    return $n;
}

/**
 * Completa el job de UN slide: si el job de OpenAI terminó, guarda la imagen.
 * Idempotente. Devuelve 'ok' | 'queued' | 'error' | 'none'.
 */
function carrusel_completar_slide(PDO $pdo, int $marca_id, int $slide_id): string {
    require_once __DIR__ . '/img_responses.php';
    $row = $pdo->query("SELECT img_job, grafica_path FROM crecer_carrusel WHERE id=" . (int)$slide_id)->fetch(PDO::FETCH_ASSOC);
    if (!$row) return 'none';
    if (trim((string)$row['grafica_path']) !== '') return 'ok';
    $rid = trim((string)($row['img_job'] ?? ''));
    if ($rid === '') return 'none';
    try {
        $st = openai_responses_estado($rid);
        if (($st['status'] ?? '') === 'completed' && ($st['b64'] ?? '') !== '') {
            $bin = base64_decode($st['b64']);
            $rel = "marca_{$marca_id}/graficas/carr_{$slide_id}_" . substr(md5((string)microtime(true)), 0, 6) . '.png';
            $abs = rtrim(UPLOADS_PATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            @mkdir(dirname($abs), 0775, true); @file_put_contents($abs, $bin);
            $url = rtrim(UPLOADS_URL, '/') . '/' . $rel;
            $pdo->prepare("UPDATE crecer_carrusel SET grafica_path=?, img_estado='ok', img_job=NULL, updated_at=NOW() WHERE id=?")->execute([$url, $slide_id]);
            return 'ok';
        }
        if (in_array($st['status'] ?? '', ['failed', 'cancelled', 'incomplete'], true)) {
            // gpt no pudo con este slide → respaldo Gemini sync (hay tiempo: es 1 slide).
            if (carrusel_arte_slide_gemini($pdo, $marca_id, $slide_id)) return 'ok';
            $pdo->prepare("UPDATE crecer_carrusel SET img_estado='error', img_job=NULL WHERE id=?")->execute([$slide_id]);
            return 'error';
        }
        return 'queued';
    } catch (Throwable $e) { return 'queued'; }
}

/**
 * SWEEP: al volver a cualquier pantalla, completa los slides cuyos jobs ya
 * terminaron en OpenAI (el worker muere en Hostinger) y, cuando un carrusel
 * queda COMPLETO en esta pasada, AVISA por notificación (una sola vez — los
 * slides completados sueltan su job, así no reaparecen). No bloquea: cada job
 * es un GET corto; tope de 8 slides por pasada.
 */
function carrusel_sweep_pendientes(PDO $pdo, int $marca_id): void {
    try {
        $q = $pdo->prepare("SELECT id, contenido_id FROM crecer_carrusel WHERE marca_id=? AND img_estado='queued' AND img_job IS NOT NULL ORDER BY id ASC LIMIT 8");
        $q->execute([$marca_id]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return;
        $tocados = [];
        foreach ($rows as $r) { carrusel_completar_slide($pdo, $marca_id, (int)$r['id']); $tocados[(int)$r['contenido_id']] = true; }
        if (!function_exists('notif_crear')) @require_once __DIR__ . '/notif.php';
        foreach (array_keys($tocados) as $cidc) {
            $est = carrusel_estado($pdo, $cidc);
            if (!empty($est['completo']) && function_exists('notif_crear')) {
                notif_crear($pdo, $marca_id, 'carrusel', 'Tu carrusel ya está listo',
                    'El corillo terminó el arte de tu carrusel — dale un vistazo y publícalo.',
                    '/crecer/panel/carrusel.php?marca=' . $marca_id . '&id=' . $cidc, 'image');
            }
        }
    } catch (Throwable $e) { error_log('carrusel_sweep: ' . $e->getMessage()); }
}

/** Respaldo Gemini SYNC para un slide (cuando gpt no pudo). Devuelve true si guardó. */
function carrusel_arte_slide_gemini(PDO $pdo, int $marca_id, int $slide_id): bool {
    $s = $pdo->query("SELECT idea FROM crecer_carrusel WHERE id=" . (int)$slide_id)->fetch(PDO::FETCH_ASSOC);
    if (!$s) return false;
    $v = carrusel_slide_visual((string)$s['idea']);
    try {
        $g = generar_grafica($pdo, $marca_id, null, ['copy' => $v['copy'], 'con_texto' => false, 'con_logo' => true, 'instrucciones' => $v['visual']]);
        if (!empty($g['archivo'])) {
            $pdo->prepare("UPDATE crecer_carrusel SET grafica_path=?, img_estado='ok', img_job=NULL, updated_at=NOW() WHERE id=?")->execute([$g['archivo'], $slide_id]);
            return true;
        }
    } catch (Throwable $e) { error_log('carrusel_arte_slide_gemini #' . $slide_id . ': ' . $e->getMessage()); }
    return false;
}

/** Dispara el worker que genera el arte de TODOS los slides en background + avisa. */
function carrusel_disparar(int $marca_id, int $contenido_id): void {
    $host = $_SERVER['HTTP_HOST'] ?? 'encuentraloahora.com';
    $url  = 'https://' . $host . '/crecer/panel/carrusel_worker.php?marca=' . $marca_id . '&id=' . $contenido_id . '&key=' . CARRUSEL_WORKER_KEY;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT_MS => 1500, CURLOPT_TIMEOUT_MS => 3000,
        CURLOPT_NOSIGNAL => 1, CURLOPT_SSL_VERIFYPEER => false,
    ]);
    curl_exec($ch); curl_close($ch);
}

/** Los slides de un carrusel, en orden. */
function carrusel_slides(PDO $pdo, int $contenido_id): array {
    $q = $pdo->prepare("SELECT * FROM crecer_carrusel WHERE contenido_id=? ORDER BY orden ASC, id ASC");
    $q->execute([$contenido_id]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/** Estado del arte del carrusel: cuántos slides ya tienen imagen. */
function carrusel_estado(PDO $pdo, int $contenido_id): array {
    $total = 0; $listos = 0;
    foreach (carrusel_slides($pdo, $contenido_id) as $s) {
        $total++;
        if (trim((string)$s['grafica_path']) !== '') $listos++;
    }
    return ['total' => $total, 'listos' => $listos, 'completo' => ($total > 0 && $listos === $total)];
}
