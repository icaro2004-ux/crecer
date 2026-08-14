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

require_once __DIR__ . '/worker_key.php';
// CR-F01b: sin CRECER_WORKER_KEY no hay llave. NADA de literal de respaldo:
// adoptar en silencio una llave del repo publico era la trampa.
if (!defined('ARTE_WORKER_KEY')) define('ARTE_WORKER_KEY', worker_key());

/** ¿Está activo el motor Responses para producción? */
function img_resp_activo(): bool {
    return (defined('IMAGE_ENGINE') ? IMAGE_ENGINE : 'actual') === 'responses';
}

/**
 * Dispara el worker de arte por auto-HTTP (fire-and-forget): sondea el job en
 * background hasta que la imagen esté y AVISA por notificación (campanita). Así el
 * dueño encola y sigue editando / se va; la notificación lo lleva al post listo.
 */
function arte_disparar(int $marca_id, int $post_id, ?bool $con_texto = null, ?string $extra = null, bool $fb = false, string $estilo = 'realista'): void {
    // CR-F01b: sin llave no se dispara. El job se queda en cola y lo rescata el
    // sweep cuando el config vuelva — mejor eso que quemar el intento contra un 503.
    if (!worker_puede_disparar('arte')) return;
    // host VALIDADO (ver worker_host): la cabecera Host la controla quien llama.
    $host = worker_host();
    $q = '&ct=' . ($con_texto === null ? 'x' : ($con_texto ? '1' : '0'));
    if ($extra !== null && trim($extra) !== '') $q .= '&extra=' . rawurlencode(mb_substr(trim($extra), 0, 300));
    if (trim($estilo) !== '' && $estilo !== 'realista') $q .= '&est=' . rawurlencode(mb_substr(trim($estilo), 0, 60));
    if ($fb) $q .= '&fb=1';   // re-disparo: ir DIRECTO a Gemini (gpt no pudo)
    $url  = worker_esquema($host) . '://' . $host . '/crecer/panel/arte_worker.php?marca=' . $marca_id . '&id=' . $post_id . '&key=' . ARTE_WORKER_KEY . $q;
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
 * Dirección de arte según el estilo elegido por el dueño (realista/creativo/
 * fantasia/ilustracion, combinables con '+'). Devuelve ['medio'=>..., 'dir'=>...].
 * Local aquí para no acoplar el worker a agentes.php.
 */
function img_estilo_dir(string $estilo): array {
    $map = [
        'realista'    => ['medio' => 'fotografía',  'dir' => 'ESTILO REALISTA (obligatorio): una FOTOGRAFÍA real y profesional — luz natural, nitidez editorial, texturas y sombras creíbles, apetecible. PROHIBIDO: ilustración, dibujo, caricatura, render 3D, look plástico/CGI.'],
        'creativo'    => ['medio' => 'imagen',       'dir' => 'ESTILO CREATIVO (obligatorio): imagen estilizada y audaz — composición inesperada, color vibrante, un concepto con gancho. Puede alejarse de la foto literal, limpia y de alta calidad. Nunca la toma obvia y aburrida.'],
        'fantasia'    => ['medio' => 'imagen',       'dir' => 'ESTILO FANTÁSTICO (obligatorio): atmósfera mágica y surrealista, luz de ensueño, brillos, paleta rica y saturada, sensación de cuento. Espectacular pero coherente con el mensaje.'],
        'ilustracion' => ['medio' => 'ilustración',  'dir' => 'ESTILO ILUSTRACIÓN (obligatorio): una ILUSTRACIÓN / arte digital DIBUJADO — trazo definido, formas limpias, color plano o degradados suaves, estética moderna. PROHIBIDO ABSOLUTAMENTE que parezca una fotografía o un render 3D: es un DIBUJO.'],
    ];
    $claves = array_values(array_filter(array_map('trim', explode('+', strtolower(trim($estilo)))), fn($k) => isset($map[$k])));
    if (count($claves) <= 1) return $map[$claves[0] ?? 'realista'] ?? $map['realista'];
    // Combinados: refleja SOLO los estilos seleccionados, fundidos en una sola imagen.
    $dirs = []; $medio = 'imagen';
    foreach ($claves as $k) { $dirs[] = $map[$k]['dir']; if ($k !== 'realista' && $medio === 'imagen') $medio = $map[$k]['medio']; }
    return ['medio' => $medio, 'dir' => "ESTILO COMBINADO — usa SOLO estas vibras seleccionadas (" . implode(' + ', $claves) . ") y fúndelas en UNA sola imagen coherente (no un collage ni dos mitades):\n- " . implode("\n- ", $dirs)];
}

/**
 * Brief natural (el que ganó en el lab) + reglas de marca + ESTILO elegido.
 * @param $con_texto  true = anuncio con texto · false = imagen SIN texto · null = el modelo decide (variedad)
 * @param $tiene_logo true = se adjunta el logo REAL del negocio (úsalo, no inventes)
 * @param $estilo     realista|creativo|fantasia|ilustracion (combinable con '+')
 */
function img_resp_brief(array $m, string $copy, ?bool $con_texto = null, bool $tiene_logo = false, ?string $extra = null, string $estilo = 'realista', array $lente = [], string $evitar = ''): string {
    $nombre  = trim((string)($m['nombre_negocio'] ?? ''));
    $desc    = trim((string)($m['descripcion'] ?? ''));
    $publico = trim((string)($m['publico_objetivo'] ?? ''));
    $prods_raw = $m['productos'] ?? [];
    if (is_string($prods_raw)) $prods_raw = json_decode($prods_raw, true) ?: [];
    $plist = [];
    foreach ((array)$prods_raw as $p) { $n = is_array($p) ? trim((string)($p['nombre'] ?? '')) : trim((string)$p); if ($n !== '') $plist[] = $n; }
    $prods = implode(', ', $plist);

    $ed = img_estilo_dir($estilo);
    $medio = $ed['medio'];

    // Regla de TEXTO (que no SIEMPRE meta letras) — respeta el MEDIO del estilo (no fuerza "fotografía").
    if ($con_texto === true)       $regla_texto = "Esta pieza SÍ lleva texto de anuncio: titular corto y potente, y un CTA breve. Poco texto, bien jerarquizado y sin errores de ortografía en español.";
    elseif ($con_texto === false)  $regla_texto = "NO pongas texto ni letras dentro de la imagen: una {$medio} publicitaria potente y limpia que hable por sí sola.";
    else                           $regla_texto = "Tú decides si la pieza lleva algo de texto de anuncio o si va limpia sin texto — elige lo que MEJOR detenga el scroll; no metas texto por meterlo.";

    // Regla de LOGO/MARCA (que no invente).
    if ($tiene_logo) $regla_logo = "Se adjunta el LOGO REAL del negocio: úsalo EXACTAMENTE ese (intégralo con buen gusto, en una esquina o como marca discreta). NO inventes ni dibujes otro logo.";
    else             $regla_logo = "NO inventes un logotipo ni una marca gráfica falsa. Si muestras el nombre del negocio, escríbelo como texto limpio y correcto: \"{$nombre}\" — nunca un logo ficticio.";

    // PROPIEDAD AJENA (2026-08-14): en repostería infantil el modelo mete Superman,
    // princesas o dibujos animados sin pestañear — pasó en prod con el primer post
    // de una cuenta nueva. El que publica es el DUEÑO, y es él quien queda expuesto.
    // La regla de IP del proyecto cubría las FOTOS de terceros; esto cierra el hueco
    // por el lado de lo que la IA genera.
    $regla_ip = "NADA DE PROPIEDAD AJENA: no incluyas personajes, mascotas, logotipos, escudos, "
              . "envases ni marcas reconocibles de terceros (superhéroes, dibujos animados, princesas, "
              . "equipos, franquicias, personajes de película). Si el tema pide un motivo infantil o "
              . "temático, resuélvelo con elementos genéricos y originales: colores, globos, confeti, "
              . "formas y figuras propias. El negocio responde por lo que publica.";

    // ANTI-SLOP (2026-08-12): el estilo de marca se respeta al pie de la letra,
    // pero la IDEA tiene que ser otra cada vez. Sin esto el modelo repite su
    // composición favorita y solo cambia el objeto ("una mano sosteniendo X",
    // después "...sosteniendo Y") — el dueño lo nota y se va.
    $bloque_variedad = '';
    if ($lente) {
        $bloque_variedad .= "\nIDEA VISUAL DE ESTA PIEZA (obligatoria — «{$lente['nombre']}»):\n{$lente['mandato']}\n"
                          . "Esta idea NO se negocia: define el sujeto, el encuadre y la escena. El ESTILO de arriba "
                          . "se mantiene igual (esa es la identidad del negocio); lo que cambia es QUÉ se ve y CÓMO se encuadra.\n";
    }
    if (trim($evitar) !== '') {
        $bloque_variedad .= "\n{$evitar}";
    }

    return "Crea una pieza publicitaria profesional para redes sociales (Facebook e Instagram) para este negocio puertorriqueño.\n\n"
         . "ESTILO OBLIGATORIO (respétalo al pie de la letra): {$ed['dir']}\n\n"
         . "Negocio (nombre EXACTO, escríbelo sin errores): {$nombre}\nQué hace: {$desc}\n"
         . ($prods !== '' ? "Productos: {$prods}\n" : '')
         . ($publico !== '' ? "Público: {$publico}\n" : '')
         . "\nTexto del post que la imagen va a acompañar:\n\"{$copy}\"\n\n"
         . "{$regla_texto}\n{$regla_logo}\n{$regla_ip}\n"
         . (($extra !== null && trim($extra) !== '') ? "Indicación extra del dueño (respétala con buen gusto): " . trim($extra) . "\n" : '')
         . "No inventes datos, precios ni promociones que no estén aquí.\n"
         . $bloque_variedad
         . "\nLa pieza debe detener el scroll y dar ganas de comprar, SIEMPRE en el estilo indicado arriba. Genera la mejor pieza posible.";
}

/**
 * Encola un trabajo Responses para una pieza de contenido. Guarda el response_id
 * en crecer_contenido.img_job (estado 'queued'). Devuelve el id, o '' si falla
 * (el llamador cae al motor viejo). Loguea en crecer_ia_log (evidencia XPRIZE #2).
 */
function img_resp_encolar(PDO $pdo, int $marca_id, int $post_id, string $copy, ?bool $con_texto = null, ?string $extra = null, string $estilo = 'realista'): string {
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
        // ANTI-SLOP: el camino ASÍNCRONO (el que usa el corillo autónomo) también
        // obedece la memoria visual — si no, la tanda semanal sale toda igual.
        require_once __DIR__ . '/variedad_visual.php';
        $lente = []; $evitar = '';
        try {
            $lente  = variedad_lente_asignado($pdo, $marca_id);
            $evitar = variedad_evitar_txt($pdo, $marca_id, 6);
        } catch (Throwable $e) { error_log('encolar variedad: ' . $e->getMessage()); }

        $brief = img_resp_brief($m, $copy, $con_texto, $logo !== null, $extra, $estilo, $lente, $evitar);
        $bg = openai_responses_crear_bg($brief, ['aspect' => '1:1'] + ($logo ? ['logo' => $logo] : []));
        $pdo->prepare("UPDATE crecer_contenido SET img_job=?, img_estado='queued' WHERE id=? AND marca_id=?")
            ->execute([$bg['id'], $post_id, $marca_id]);
        // La huella se registra AL ENCOLAR (no al terminar): así dos piezas
        // encoladas seguidas no reciben el mismo lente.
        if ($lente) {
            try {
                variedad_registrar($pdo, $marca_id, (string)$lente['clave'], [
                    'primary_subject' => $lente['nombre'],
                    'composition'     => mb_substr(trim($copy), 0, 90),
                ], $post_id);
            } catch (Throwable $e) { /* best-effort */ }
        }
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
        // El RESPALDO también obedece la memoria visual: si no, la imagen que
        // salva el día es justo la que repite la fórmula.
        require_once __DIR__ . '/variedad_visual.php';
        $lente = []; $evitar = '';
        try {
            $lente  = variedad_lente_asignado($pdo, $marca_id);
            $evitar = variedad_evitar_txt($pdo, $marca_id, 6);
        } catch (Throwable $e) {}

        $brief = img_resp_brief($m, $copy, null, !empty($imgs), null, 'realista', $lente, $evitar);
        $r = gemini_imagen($brief, ['modelo' => 'gemini-3-pro-image', 'aspect' => '1:1'] + ($imgs ? ['imagenes' => $imgs] : []));
        $bin = $r['data'] ?? '';
        if ($bin === '') return '';
        $rel = "marca_{$marca_id}/graficas/gem_{$post_id}_" . substr(md5((string)microtime(true)), 0, 6) . '.png';
        $abs = rtrim(UPLOADS_PATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        @mkdir(dirname($abs), 0775, true); @file_put_contents($abs, $bin);
        $url = rtrim(UPLOADS_URL, '/') . '/' . $rel;
        // Cuenta el intento SOLO al producir la imagen (no al encolar). Ver aprobar2 'arte'.
        $pdo->prepare("UPDATE crecer_contenido SET grafica_path=?, img_estado='ok', img_job=NULL, arte_intentos=arte_intentos+1, updated_at=NOW() WHERE id=? AND marca_id=?")
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
        // Recoge jobs con response_id, Y TAMBIÉN los colgados sin job >2 min (el worker
        // se murió/bloqueó antes de crear el job → sin esto quedaban en 'queued' para siempre).
        $pend = $pdo->prepare("SELECT id, img_job FROM crecer_contenido
             WHERE marca_id=? AND img_estado='queued'
               AND (img_job IS NOT NULL OR updated_at < (NOW() - INTERVAL 2 MINUTE))
             ORDER BY id DESC LIMIT 4");
        $pend->execute([$marca_id]);
        $rows = $pend->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return;
        if (!function_exists('notif_crear')) { @require_once __DIR__ . '/notif.php'; }
        $link = '/crecer/panel/propuestas.php?marca=' . $marca_id;
        foreach ($rows as $row) {
            $pid = (int)$row['id'];
            // Colgado sin job → el worker nunca arrancó: rescátalo directo por Gemini (síncrono, fiable).
            if (empty($row['img_job'])) {
                if (function_exists('img_gemini_fallback')) {
                    $cap = (string)($pdo->query("SELECT caption FROM crecer_contenido WHERE id=" . $pid)->fetchColumn() ?: '');
                    $url = img_gemini_fallback($pdo, $marca_id, $pid, $cap);
                    if ($url !== '' && function_exists('notif_crear')) {
                        notif_crear($pdo, $marca_id, 'arte', 'Tu arte ya está listo',
                            'El corillo terminó la imagen de tu post — dale un vistazo.', $link, 'image');
                    }
                }
                continue;
            }
            $r = img_resp_completar($pdo, $marca_id, $pid);
            $est = $r['estado'] ?? '';
            if ($est === 'ok' && function_exists('notif_crear')) {
                notif_crear($pdo, $marca_id, 'arte', 'Tu arte ya está listo',
                    'El corillo terminó la imagen de tu post — dale un vistazo.', $link, 'image');
            } elseif ($est === 'error' && function_exists('arte_disparar')) {
                arte_disparar($marca_id, $pid, null, null, true);   // gpt cayó → Gemini en background
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
            // Cuenta el intento SOLO al producir la imagen (no al encolar). Ver aprobar2 'arte'.
            $pdo->prepare("UPDATE crecer_contenido SET grafica_path=?, img_estado='ok', img_job=NULL, arte_intentos=arte_intentos+1, updated_at=NOW() WHERE id=? AND marca_id=?")
                ->execute([$url, $post_id, $marca_id]);
            return ['estado' => 'ok', 'img' => $url];
        }
        if (in_array($st['status'] ?? '', ['failed', 'cancelled', 'incomplete'], true)) {
            $pdo->prepare("UPDATE crecer_contenido SET img_estado='error', img_job=NULL WHERE id=? AND marca_id=?")->execute([$post_id, $marca_id]);
            return ['estado' => 'error', 'img' => null];
        }
        return ['estado' => 'queued', 'img' => null];   // in_progress / queued
    } catch (Throwable $e) {
        // Se sigue devolviendo 'queued' (puede ser transitorio y el próximo poll
        // lo resuelve), PERO se deja rastro: si el fallo es permanente, sin esto
        // el dueño espera para siempre y no queda constancia de por qué.
        error_log('img_resp_completar: ' . $e->getMessage());
        try { $pdo->prepare("INSERT INTO crecer_ia_log (marca_id,agente,accion,modelo,prompt,respuesta,estado,error_msg)
                             VALUES (?,?,?,?,?,?, 'error', ?)")
            ->execute([$marca_id, 'director_imagen', 'No pude consultar el job de imagen', 'responses',
                       'post_id=' . $post_id . ' job=' . $rid, '', mb_substr($e->getMessage(), 0, 400)]); } catch (Throwable $e2) {}
        return ['estado' => 'queued', 'img' => null];
    }
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
