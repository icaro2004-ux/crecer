<?php
// ============================================================
//  CRECER — Reels Studio · CEREBRO  (includes/reels.php)
//  Módulo AISLADO. Reusa (solo lectura / include) db.php, ia.php,
//  render.php. NO edita nada del app existente.
//
//  Flujo:
//   1) reels_analizar()  — Gemini VE los clips (1 fotograma + duración
//      por clip) y decide orden + cortes (in/out) + captions boricuas
//      + mood. Se registra en crecer_ia_log (evidencia criterio #2).
//   2) reels_construir_timeline() — EDL + preset → timeline de Shotstack.
//   3) reels_procesar() — worker: analiza → arma → renderiza → poll.
//
//  REGLA (heredada del app): sin fallback creativo silencioso. Si
//  Gemini falla, el reel queda 'failed' con el motivo exacto.
// ============================================================

require_once __DIR__ . '/ia.php';
require_once __DIR__ . '/render.php';

// ── PRESETS ─────────────────────────────────────────────────
// Cada preset es un PAQUETE de estilo (no un filtro): ritmo de
// corte, transición, tipografía del caption, movimiento y mood.
function reels_presets(): array {
    return [
        'vivido' => [
            'nombre'    => 'Vívido',
            'tagline'   => 'Cortes rápidos, colores que saltan, energía.',
            'emoji'     => '🔥',
            'title_style'=> 'chunk',
            'title_color'=> '#ffffff',
            'trans_in'  => 'zoom',
            'trans_out' => 'zoom',
            'efecto'    => 'zoomIn',     // ken burns
            'overlap'   => 0.0,
            'seg_max'   => 3.0,
            'background'=> '#0e0e12',
            'ritmo'     => 'rápido y alegre',
            'filtro'    => 'boost',      // color saturado/contrastado
            'mood'      => 'upbeat',
            'cap'       => ['family'=>"'Arial Black', Arial, sans-serif", 'size'=>52, 'weight'=>900, 'transform'=>'none', 'ls'=>'0px'],
        ],
        'accion' => [
            'nombre'    => 'Acción',
            'tagline'   => 'Impacto, movimiento, sincronizado al beat.',
            'emoji'     => '⚡',
            'title_style'=> 'blockbuster',
            'title_color'=> '#ffffff',
            'trans_in'  => 'slideUp',
            'trans_out' => 'slideDown',
            'efecto'    => 'zoomIn',
            'overlap'   => 0.0,
            'seg_max'   => 2.2,
            'background'=> '#000000',
            'ritmo'     => 'intenso y punchy',
            'filtro'    => 'contrast',
            'mood'      => 'energetico',
            'cap'       => ['family'=>"'Arial Black', Arial, sans-serif", 'size'=>56, 'weight'=>900, 'transform'=>'uppercase', 'ls'=>'1px'],
        ],
        'elegante' => [
            'nombre'    => 'Elegante',
            'tagline'   => 'Transiciones suaves, tipografía fina, clase.',
            'emoji'     => '🕊️',
            'title_style'=> 'vogue',
            'title_color'=> '#ffffff',
            'trans_in'  => 'fade',
            'trans_out' => 'fade',
            'efecto'    => null,
            'overlap'   => 0.6,
            'seg_max'   => 4.0,
            'background'=> '#101014',
            'ritmo'     => 'pausado y con respiración',
            'filtro'    => 'muted',
            'mood'      => 'elegante',
            'cap'       => ['family'=>"Georgia, 'Times New Roman', serif", 'size'=>44, 'weight'=>600, 'transform'=>'none', 'ls'=>'0.5px'],
        ],
    ];
}
function reels_preset(string $slug): array {
    $p = reels_presets();
    return $p[$slug] ?? $p['vivido'];
}

// ── Helpers de estado / rutas ───────────────────────────────
function reels_set(PDO $pdo, int $id, array $f): void {
    if (!$f) return;
    $set = []; $vals = [];
    foreach ($f as $k => $v) { $set[] = "{$k}=?"; $vals[] = $v; }
    $vals[] = $id;
    try { $pdo->prepare("UPDATE crecer_reels SET " . implode(',', $set) . " WHERE id=?")->execute($vals); }
    catch (Throwable $e) { error_log('reels_set #' . $id . ': ' . $e->getMessage()); }
}

function reels_uploads_path(): string {
    return defined('UPLOADS_PATH') ? rtrim(UPLOADS_PATH, '/\\') : dirname(__DIR__) . '/uploads';
}

/** URL PÚBLICA absoluta de un archivo relativo bajo uploads/ (Shotstack la descarga). */
function reels_public_url(string $rel): string {
    $rel = ltrim($rel, '/');
    $upl = defined('UPLOADS_URL') ? UPLOADS_URL : '/crecer/uploads';
    if (strpos($upl, 'http') === 0) return rtrim($upl, '/') . '/' . $rel;
    // Construir esquema+host absoluto.
    $host = null;
    if (defined('BASE_URL') && strpos(BASE_URL, 'http') === 0) {
        $p = parse_url(BASE_URL);
        $host = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    } elseif (!empty($_SERVER['HTTP_HOST'])) {
        $sch = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $sch . '://' . $_SERVER['HTTP_HOST'];
    } else {
        $host = 'https://encuentraloahora.com';
    }
    return $host . rtrim($upl, '/') . '/' . $rel;
}

/** Ruta absoluta del fotograma que el cliente extrajo de un clip. */
function reels_frame_abs(int $marca_id, int $clip_id): string {
    return reels_uploads_path() . "/marca_{$marca_id}/reels/frames/{$clip_id}.jpg";
}

// ── 1) ANÁLISIS — Gemini VE los clips y decide el reel ──────
/**
 * @param array $reel   fila crecer_reels
 * @param array $clips  filas crecer_reel_clips (orden_subido asc)
 * @return array EDL validado: ['hook','musica_mood','segmentos'=>[['clip','in','out','caption'],...]]
 * @throws Throwable si Gemini falla o devuelve algo inservible (sin fallback silencioso)
 */
function reels_analizar(PDO $pdo, array $reel, array $clips): array {
    $marca_id = (int)$reel['marca_id'];
    $preset   = reels_preset((string)$reel['preset']);
    $contexto = trim((string)($reel['contexto'] ?? ''));

    // Fotograma (1 por clip) → visión de Gemini. Duración → largo.
    $imagenes = []; $lista = [];
    foreach ($clips as $i => $c) {
        $dur = $c['dur_orig'] !== null ? (float)$c['dur_orig'] : null;
        $lista[] = "Clip {$i}: dura " . ($dur !== null ? number_format($dur, 1) . 's' : 'desconocida')
                 . " — la Imagen #{$i} es un fotograma de este clip.";
        $fabs = reels_frame_abs($marca_id, (int)$c['id']);
        if (is_file($fabs)) {
            $imagenes[] = ['mime' => 'image/jpeg', 'data' => base64_encode((string)file_get_contents($fabs))];
        } else {
            $imagenes[] = ['mime' => 'image/jpeg', 'data' => '']; // se ignora abajo; el índice se mantiene por claridad
        }
    }

    $sistema = "Eres el editor de video del Corillo de Crecer, para un micronegocio "
        . "boricua (repostería, comida, servicios). Montas un REEL vertical corto "
        . "(8–22s) para Instagram/Facebook. Estilo pedido: {$preset['nombre']} "
        . "(ritmo {$preset['ritmo']}). Los captions van en español BORICUA auténtico, "
        . "cortos (máx 6 palabras), con chispa, NUNCA traducidos ni 'AI slop'. "
        . "NO inventes productos ni promesas que no se vean en el clip. "
        . "Escoge el mejor pedazo de cada clip (in/out) y el mejor ORDEN para "
        . "enganchar en el primer segundo. Devuelve SOLO JSON válido.";

    $prompt = "Tengo " . count($clips) . " clips subidos por el dueño.\n"
        . implode("\n", $lista) . "\n\n"
        . ($contexto !== '' ? "Contexto que dio el dueño: \"{$contexto}\"\n\n" : "")
        . "Arma el reel. Reglas de tiempo: cada segmento entre 1.2s y "
        . number_format($preset['seg_max'], 1) . "s, dentro de la duración real del clip. "
        . "Puedes repetir un clip si vale la pena, y puedes dejar clips fuera si no aportan.\n\n"
        . "Devuelve EXACTAMENTE este JSON:\n"
        . "{\n"
        . "  \"hook\": \"texto gancho MUY corto para el primer segundo (máx 5 palabras)\",\n"
        . "  \"musica_mood\": \"upbeat|energetico|elegante\",\n"
        . "  \"segmentos\": [\n"
        . "    {\"clip\": 0, \"in\": 0.0, \"out\": 2.5, \"caption\": \"texto boricua corto\"}\n"
        . "  ],\n"
        . "  \"resumen\": \"1 línea: por qué este orden\"\n"
        . "}";

    $imgs_validas = array_values(array_filter($imagenes, fn($im) => $im['data'] !== ''));

    $res = ia_ejecutar($pdo, 'reels', 'analizar_clips', $prompt, [
        'marca_id'       => $marca_id,
        'sistema'        => $sistema,
        'imagenes'       => $imgs_validas,
        'json'           => true,
        'max_tokens'     => 3000,
        'temperatura'    => 0.7,
        'thinking_budget'=> 0,   // apaga el "pensamiento": sin truncar el JSON, más rápido y barato
    ]);

    if (!empty($res['ia_log_id'])) reels_set($pdo, (int)$reel['id'], ['ia_log_id' => (int)$res['ia_log_id']]);

    $edl = json_decode(trim((string)$res['texto']), true);
    if (!is_array($edl) || empty($edl['segmentos']) || !is_array($edl['segmentos'])) {
        throw new RuntimeException('El editor no devolvió un plan válido: ' . substr((string)$res['texto'], 0, 200));
    }
    return reels_validar_edl($edl, $clips, $preset);
}

/** Sanea el EDL contra los clips reales (índices y tiempos válidos). */
function reels_validar_edl(array $edl, array $clips, array $preset): array {
    $n = count($clips);
    $segs = [];
    foreach ($edl['segmentos'] as $s) {
        $ci = (int)($s['clip'] ?? -1);
        if ($ci < 0 || $ci >= $n) continue;
        $dur = $clips[$ci]['dur_orig'] !== null ? (float)$clips[$ci]['dur_orig'] : 60.0;
        $in  = max(0.0, (float)($s['in'] ?? 0));
        $out = (float)($s['out'] ?? ($in + 2.0));
        if ($out <= $in) $out = $in + 1.5;
        if ($dur > 0) { $in = min($in, max(0.0, $dur - 0.6)); $out = min($out, $dur); }
        $len = $out - $in;
        if ($len < 0.8) { $out = $in + 0.8; $len = 0.8; }
        if ($len > $preset['seg_max']) $out = $in + $preset['seg_max'];
        $cap = trim((string)($s['caption'] ?? ''));
        $segs[] = ['clip' => $ci, 'in' => round($in, 2), 'out' => round($out, 2), 'caption' => mb_substr($cap, 0, 120)];
    }
    if (!$segs) throw new RuntimeException('El plan quedó sin segmentos usables.');
    return [
        'hook'        => mb_substr(trim((string)($edl['hook'] ?? '')), 0, 60),
        'musica_mood' => (string)($edl['musica_mood'] ?? 'upbeat'),
        'resumen'     => mb_substr(trim((string)($edl['resumen'] ?? '')), 0, 240),
        'segmentos'   => $segs,
    ];
}

// Caja de caption en HTML (wrap real + márgenes seguros + tamaño por preset).
// Reemplaza el asset 'title' de Shotstack, que no hace wrap y se sale por los lados.
function reels_caption_asset(string $text, array $preset, bool $hook = false): array {
    $c = $preset['cap'];
    $color = $preset['title_color'];
    $size  = $hook ? $c['size'] + 18 : $c['size'];
    $bw = 960; $bh = $hook ? 1000 : 600;               // caja ancha; deja ~60px de margen a cada lado
    $align = $hook ? 'center' : 'flex-end';            // hook al centro; captions al tercio inferior
    $padb  = $hook ? '0' : '48px';
    $t = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $css = "html,body{margin:0;padding:0}"
         . ".c{width:{$bw}px;height:{$bh}px;display:flex;align-items:{$align};justify-content:center;box-sizing:border-box;padding:0 50px {$padb}}"
         . ".c span{font-family:{$c['family']};font-size:{$size}px;font-weight:{$c['weight']};color:{$color};"
         . "text-align:center;line-height:1.14;text-transform:{$c['transform']};letter-spacing:{$c['ls']};"
         . "text-shadow:0 3px 16px rgba(0,0,0,.6);background:rgba(0,0,0,.32);padding:14px 24px;border-radius:22px;"
         . "-webkit-box-decoration-break:clone;box-decoration-break:clone}";
    $html = '<div class="c"><span>' . $t . '</span></div>';
    return ['type'=>'html', 'html'=>$html, 'css'=>$css, 'width'=>$bw, 'height'=>$bh, 'background'=>'transparent'];
}

/** Datos de la marca para el cierre (nombre, WhatsApp, preferencia de contacto, logo). */
function reels_marca(PDO $pdo, int $marca_id): ?array {
    try {
        $st = $pdo->prepare("SELECT nombre_negocio, whatsapp, contacto_preferencia, logo_path FROM crecer_marca WHERE id=?");
        $st->execute([$marca_id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { return null; }
}

/**
 * Pista musical por mood. Pistas libres (Unminus) por defecto — reemplazables
 * por TUS pistas con licencia comercial (súbelas a uploads y cambia estas URLs,
 * o define REELS_MUSIC_MANIFEST en config). REELS_MUSIC_OFF=true las apaga.
 */
function reels_music(string $mood): ?string {
    if (defined('REELS_MUSIC_OFF') && REELS_MUSIC_OFF) return null;
    $map = defined('REELS_MUSIC_MANIFEST') && is_array(REELS_MUSIC_MANIFEST) ? REELS_MUSIC_MANIFEST : [
        'upbeat'     => 'https://shotstack-assets.s3.amazonaws.com/music/unminus/palmtrees.mp3',
        'energetico' => 'https://shotstack-assets.s3.amazonaws.com/music/unminus/lit.mp3',
        'energetic'  => 'https://shotstack-assets.s3.amazonaws.com/music/unminus/lit.mp3',
        'elegante'   => 'https://shotstack-assets.s3.amazonaws.com/music/unminus/ambisax.mp3',
        'elegant'    => 'https://shotstack-assets.s3.amazonaws.com/music/unminus/ambisax.mp3',
    ];
    return $map[strtolower($mood)] ?? reset($map) ?: null;
}

/** Subtítulo automático (rich-caption) para un segmento, transcribiendo su voz. */
function reels_richcaption_clip(string $alias, array $preset, float $start, float $len): array {
    $c = $preset['cap'];
    return [
        'asset' => [
            'type'   => 'rich-caption',
            'src'    => 'alias://' . $alias,
            'font'   => ['family' => 'Montserrat ExtraBold', 'size' => min(44, (int)$c['size'] - 8), 'color' => $preset['title_color']],
            'stroke' => ['width' => 3, 'color' => '#000000'],
            'animation' => ['style' => 'highlight'],
            'align'  => ['vertical' => 'bottom'],
        ],
        'start'  => $start,
        'length' => $len,
    ];
}

/** Cierre de marca (end card): logo + nombre + CTA. Respeta anti-misrepresentación. */
function reels_endcard_asset(?array $marca, array $preset): ?array {
    if (!$marca) return null;
    $nombre = trim((string)($marca['nombre_negocio'] ?? ''));
    if ($nombre === '') return null;
    $wa   = trim((string)($marca['whatsapp'] ?? ''));
    $pref = (string)($marca['contacto_preferencia'] ?? '');
    // WhatsApp SOLO si hay número y la preferencia no es DM. Nunca inventa número.
    $cta = ($wa !== '' && $pref !== 'dm') ? 'Escríbeme por WhatsApp' : 'Escríbeme por DM';
    $logo_html = '';
    $logo = trim((string)($marca['logo_path'] ?? ''));
    if ($logo !== '') {
        $logo_html = '<img class="lg" src="' . htmlspecialchars(reels_public_url($logo), ENT_QUOTES) . '">';
    }
    $c  = $preset['cap'];
    $bg = $preset['background'];
    $nm = htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8');
    $ct = htmlspecialchars($cta, ENT_QUOTES, 'UTF-8');
    $css = "html,body{margin:0;padding:0}"
         . ".card{width:1080px;height:1920px;background:{$bg};display:flex;flex-direction:column;align-items:center;justify-content:center;box-sizing:border-box;padding:90px;text-align:center}"
         . ".lg{max-width:360px;max-height:360px;object-fit:contain;margin-bottom:46px;border-radius:28px}"
         . ".nm{font-family:{$c['family']};font-size:78px;font-weight:{$c['weight']};color:{$preset['title_color']};line-height:1.08}"
         . ".cta{font-family:Arial,sans-serif;font-size:46px;color:{$preset['title_color']};opacity:.95;margin-top:40px;background:rgba(255,255,255,.14);padding:22px 46px;border-radius:999px}";
    $html = '<div class="card">' . $logo_html . '<div class="nm">' . $nm . '</div><div class="cta">📲 ' . $ct . '</div></div>';
    return ['type'=>'html', 'html'=>$html, 'css'=>$css, 'width'=>1080, 'height'=>1920, 'background'=>$bg];
}

// ── 2) TIMELINE — EDL + preset → documento de Shotstack ─────
function reels_construir_timeline(array $reel, array $clips, array $edl, ?array $marca = null): array {
    $preset  = reels_preset((string)$reel['preset']);
    $overlap = (float)$preset['overlap'];
    $subs    = !empty($reel['subtitulos']);

    $video_clips = [];
    $caption_clips = [];
    $sub_clips = [];
    $cursor = 0.0;

    foreach ($edl['segmentos'] as $idx => $s) {
        $c   = $clips[$s['clip']];
        $len = round($s['out'] - $s['in'], 2);
        $src = reels_public_url((string)$c['archivo']);
        $start = round($cursor, 2);

        $vc = [
            'asset' => ['type' => 'video', 'src' => $src, 'trim' => $s['in'], 'volume' => 1.0],
            'start' => $start,
            'length'=> $len,
            'fit'   => 'crop',                 // llena el 9:16 sin bordes
            'filter'=> $preset['filtro'],      // color por estilo (boost/contrast/muted)
            'transition' => ['in' => $preset['trans_in'], 'out' => $preset['trans_out']],
        ];
        if (!empty($preset['efecto'])) $vc['effect'] = $preset['efecto'];
        if ($subs) $vc['alias'] = 'seg' . $idx;   // para transcribir su voz
        $video_clips[] = $vc;

        if ($subs) {
            // Subtítulos reales de la voz de este clip (no inventados).
            $sub_clips[] = reels_richcaption_clip('seg' . $idx, $preset, $start, $len);
        } elseif ($s['caption'] !== '') {
            // Caption escrito por la IA (cuando no hay voz).
            $caption_clips[] = [
                'asset'    => reels_caption_asset($s['caption'], $preset, false),
                'start'    => $start,
                'length'   => $len,
                'position' => 'bottom',
                'transition' => ['in' => 'fade', 'out' => 'fade'],
            ];
        }

        $cursor = $start + $len - $overlap;
    }

    $total = round($cursor + $overlap, 2);

    // Cierre de marca (end card ~2.6s).
    $endcard = reels_endcard_asset($marca, $preset);
    if ($endcard) {
        $ec_len = 2.6;
        $video_clips[] = [
            'asset'  => $endcard,
            'start'  => round($total, 2),
            'length' => $ec_len,
            'transition' => ['in' => 'fade'],
        ];
        $total = round($total + $ec_len, 2);
    }

    // Música + ducking: baja el audio de los clips (más si NO hay subtítulos).
    $music   = reels_music((string)($edl['musica_mood'] ?? $preset['mood']));
    $clip_vol = $music ? ($subs ? 0.85 : 0.35) : 1.0;
    foreach ($video_clips as &$vc0) {
        if (($vc0['asset']['type'] ?? '') === 'video') $vc0['asset']['volume'] = $clip_vol;
    }
    unset($vc0);

    // Hook + capas.
    $tracks = [];
    if (!empty($edl['hook'])) {
        $hook_len = min(2.2, max(1.2, $total * 0.22));
        $tracks[] = ['clips' => [[
            'asset'    => reels_caption_asset($edl['hook'], $preset, true),
            'start'    => 0,
            'length'   => $hook_len,
            'position' => 'center',
            'transition' => ['in' => 'fade', 'out' => 'fade'],
        ]]];
    }
    if ($sub_clips)     $tracks[] = ['clips' => $sub_clips];     // subtítulos de voz (capa arriba)
    if ($caption_clips) $tracks[] = ['clips' => $caption_clips]; // o captions de la IA
    $tracks[] = ['clips' => $video_clips];

    $timeline = ['background' => $preset['background'], 'tracks' => $tracks];
    if ($music) {
        $timeline['soundtrack'] = ['src' => $music, 'effect' => 'fadeInFadeOut', 'volume' => $subs ? 0.22 : 0.6];
    }

    $doc = [
        'timeline' => $timeline,
        'output'   => ['format' => 'mp4', 'size' => ['width' => 1080, 'height' => 1920], 'fps' => 30],
    ];
    return ['doc' => $doc, 'duracion' => $total];
}

// ── 3) WORKER — corre todo el pipeline y hace polling ───────
function reels_procesar(PDO $pdo, int $id): void {
    @set_time_limit(0);
    $reel = $pdo->query("SELECT * FROM crecer_reels WHERE id=" . (int)$id)->fetch(PDO::FETCH_ASSOC);
    if (!$reel || in_array($reel['estado'], ['listo', 'publicado'], true)) return;

    $clips = $pdo->prepare("SELECT * FROM crecer_reel_clips WHERE reel_id=? ORDER BY orden_subido ASC, id ASC");
    $clips->execute([$id]);
    $clips = $clips->fetchAll(PDO::FETCH_ASSOC);
    if (!$clips) { reels_set($pdo, $id, ['estado' => 'failed', 'error_msg' => 'sin clips']); return; }

    // 1) Analizar.
    reels_set($pdo, $id, ['estado' => 'analizando', 'error_msg' => null]);
    try { $edl = reels_analizar($pdo, $reel, $clips); }
    catch (Throwable $e) {
        reels_set($pdo, $id, ['estado' => 'failed', 'error_msg' => 'análisis: ' . substr($e->getMessage(), 0, 400)]);
        return;
    }

    // Persistir el EDL + los cortes por clip (para el editor de timing de la Fase 2).
    reels_set($pdo, $id, ['estado' => 'armando', 'edl_json' => json_encode($edl, JSON_UNESCAPED_UNICODE)]);
    $orden = 0;
    foreach ($edl['segmentos'] as $s) {
        $c = $clips[$s['clip']];
        try {
            $pdo->prepare("UPDATE crecer_reel_clips SET orden=?, in_pt=?, out_pt=?, caption=? WHERE id=?")
                ->execute([$orden++, $s['in'], $s['out'], $s['caption'], (int)$c['id']]);
        } catch (Throwable $e) {}
    }

    // 2) Timeline.
    $marca = reels_marca($pdo, (int)$reel['marca_id']);
    $built = reels_construir_timeline($reel, $clips, $edl, $marca);
    reels_set($pdo, $id, [
        'timeline_json' => json_encode($built['doc'], JSON_UNESCAPED_UNICODE),
        'duracion_seg'  => $built['duracion'],
    ]);

    // 3) Render + poll (reusable en el re-render del editor).
    reels_finalizar_render($pdo, $id, $built['doc']);
}

/**
 * RE-RENDER desde el plan YA editado por el dueño (editor de timing/captions).
 * NO vuelve a llamar a Gemini: usa los cortes/captions guardados en
 * crecer_reel_clips y conserva hook/mood/resumen del edl_json previo.
 */
function reels_reprocesar(PDO $pdo, int $id): void {
    @set_time_limit(0);
    $reel = $pdo->query("SELECT * FROM crecer_reels WHERE id=" . (int)$id)->fetch(PDO::FETCH_ASSOC);
    if (!$reel) return;

    $clips = $pdo->prepare("SELECT * FROM crecer_reel_clips WHERE reel_id=? ORDER BY orden_subido ASC, id ASC");
    $clips->execute([$id]);
    $clips = $clips->fetchAll(PDO::FETCH_ASSOC);
    if (!$clips) { reels_set($pdo, $id, ['estado' => 'failed', 'error_msg' => 'sin clips']); return; }

    $edl = reels_edl_desde_clips($pdo, $reel, $clips);
    reels_set($pdo, $id, ['estado' => 'armando', 'edl_json' => json_encode($edl, JSON_UNESCAPED_UNICODE), 'error_msg' => null]);

    $marca = reels_marca($pdo, (int)$reel['marca_id']);
    $built = reels_construir_timeline($reel, $clips, $edl, $marca);
    reels_set($pdo, $id, [
        'timeline_json' => json_encode($built['doc'], JSON_UNESCAPED_UNICODE),
        'duracion_seg'  => $built['duracion'],
    ]);
    reels_finalizar_render($pdo, $id, $built['doc']);
}

/**
 * Reconstruye el EDL a partir de las filas editadas de crecer_reel_clips
 * (orden / in_pt / out_pt / caption), conservando hook/mood/resumen previos.
 */
function reels_edl_desde_clips(PDO $pdo, array $reel, array $clips): array {
    // Índice por id → posición en el orden de subida (lo que espera el timeline).
    $pos = []; foreach ($clips as $i => $c) $pos[(int)$c['id']] = $i;

    // Orden final editado.
    $rows = $pdo->prepare("SELECT * FROM crecer_reel_clips WHERE reel_id=? ORDER BY orden ASC, id ASC");
    $rows->execute([(int)$reel['id']]);
    $rows = $rows->fetchAll(PDO::FETCH_ASSOC);

    $preset = reels_preset((string)$reel['preset']);
    $segs = [];
    foreach ($rows as $r) {
        if (!isset($pos[(int)$r['id']])) continue;
        $in  = $r['in_pt']  !== null ? (float)$r['in_pt']  : 0.0;
        $out = $r['out_pt'] !== null ? (float)$r['out_pt'] : $in + 2.0;
        if ($out <= $in) $out = $in + 1.0;
        $segs[] = ['clip' => $pos[(int)$r['id']], 'in' => round($in, 2), 'out' => round($out, 2), 'caption' => (string)($r['caption'] ?? '')];
    }
    $prev = $reel['edl_json'] ? json_decode((string)$reel['edl_json'], true) : [];
    // Re-saneo contra los clips reales (respeta seg_max del preset, límites de duración).
    $edl = reels_validar_edl(['segmentos' => $segs], $clips, $preset);
    $edl['hook']        = (string)($prev['hook'] ?? '');
    $edl['musica_mood'] = (string)($prev['musica_mood'] ?? 'upbeat');
    $edl['resumen']     = (string)($prev['resumen'] ?? '');
    return $edl;
}

/** Envía el documento al render y hace polling hasta done|failed|timeout. */
function reels_finalizar_render(PDO $pdo, int $id, array $doc): void {
    if (!render_disponible()) {
        reels_set($pdo, $id, ['estado' => 'failed', 'error_msg' => 'render no configurado (falta la key de Shotstack en el servidor)']);
        return;
    }
    reels_set($pdo, $id, ['estado' => 'renderizando']);
    $env = render_enviar($doc);
    if (!$env['ok']) {
        reels_set($pdo, $id, ['estado' => 'failed', 'error_msg' => 'render: ' . substr((string)$env['error'], 0, 400)]);
        return;
    }
    reels_set($pdo, $id, ['render_id' => $env['id']]);

    // Polling hasta done|failed (o timeout ~6 min).
    $max = 90;
    for ($i = 0; $i < $max; $i++) {
        sleep(4);
        $st = render_estado($env['id']);
        reels_set($pdo, $id, ['intentos_poll' => $i + 1]);
        if (!$st['ok']) continue;
        if ($st['status'] === 'done') {
            reels_set($pdo, $id, [
                'estado'     => 'listo',
                'video_url'  => $st['url'],
                'poster_url' => $st['poster'],
                'error_msg'  => null,
            ]);
            return;
        }
        if ($st['status'] === 'failed') {
            reels_set($pdo, $id, ['estado' => 'failed', 'error_msg' => 'render falló: ' . substr((string)($st['error'] ?? 'desconocido'), 0, 300)]);
            return;
        }
    }
    reels_set($pdo, $id, ['estado' => 'failed', 'error_msg' => 'el render tardó demasiado (timeout)']);
}

/** Dispara el worker por auto-HTTP (fire-and-forget). $modo: 'crear' | 'reedit'. */
function reels_disparar(int $id, string $modo = 'crear'): void {
    $host = $_SERVER['HTTP_HOST'] ?? 'encuentraloahora.com';
    $sch  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $url  = $sch . '://' . $host . '/crecer/panel/reel_worker.php?id=' . $id . '&modo=' . rawurlencode($modo) . '&key=' . REELS_WORKER_KEY;
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

/** Estado para el polling del frontend. */
function reels_estado(PDO $pdo, int $id): ?array {
    $r = $pdo->query("SELECT id, estado, preset, video_url, poster_url, duracion_seg, error_msg, edl_json FROM crecer_reels WHERE id=" . (int)$id)->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

// Llave fija del worker (no es público). Aislada del resto.
if (!defined('REELS_WORKER_KEY')) define('REELS_WORKER_KEY', 'crreel_9m4v');
