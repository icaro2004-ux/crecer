<?php
// ============================================================
//  CRECER — DIRECTOR DE ARTE con IA
//  includes/direccion_arte.php
//
//  No "generamos imágenes": diseñamos CAMPAÑAS. La imagen es la ejecución
//  visual de una campaña, no una foto de stock del copy. Pipeline de 3 agentes:
//    1) Estratega de Campaña   → qué EMOCIÓN provocar (no piensa en imágenes)
//    2) Director de Arte        → Visual Brief estructurado (+ biblioteca de estilos)
//    3) Prompt Engineer visual  → prompt final EN para gpt-image-1 (ciego al negocio)
//
//  Entra por generar_grafica(). Degrada con gracia: pipeline → dirigir_arte →
//  el prompt de respaldo que ya trae generar_grafica. NUNCA rompe la fábrica.
//
//  El copy JAMÁS se ilustra literalmente: la imagen COMPLEMENTA la emoción.
// ============================================================

/**
 * Biblioteca de estilos visuales — cada industria su propio lenguaje.
 * Cada estilo trae: luz, composición, paleta, tipo de foto, atmósfera,
 * contraste, profundidad de campo y tratamiento de color.
 */
function biblioteca_estilos(): array {
    return [
        'food_luxury' => [
            'nombre'=>'Food Luxury','lighting'=>'soft directional window light, warm golden-hour glow, gentle rim light',
            'composition'=>'full commercial food scene, editorial styling, an assortment/variety when the business offers it, real buying context (table, counter, packaging), balanced layout with clear hierarchy','palette'=>'warm ambers, toasted browns, cream, deep espresso',
            'photography'=>'premium commercial food photography, 50mm, full plated/table scene','atmosphere'=>'appetizing, abundant, artisanal, freshly made','contrast'=>'medium-high with rich shadows',
            'dof'=>'controlled depth, the whole scene readable (NOT a shallow single-subject blur)','treatment'=>'natural warm grade, glossy highlights on the food, mouth-watering'],
        'luxury_wellness' => [
            'nombre'=>'Luxury Wellness','lighting'=>'soft diffused daylight, airy highlights, serene','composition'=>'minimal spa editorial, lots of breathing room, calm symmetry',
            'palette'=>'soft whites, sage, warm beige, muted greens','photography'=>'clean lifestyle photography, 50mm','atmosphere'=>'calm, pure, luxurious, restorative',
            'contrast'=>'low-medium, gentle','dof'=>'shallow, soft','treatment'=>'clean bright grade, natural skin/material tones, spa-like'],
        'medical_premium' => [
            'nombre'=>'Medical Premium','lighting'=>'bright clean even light, soft shadows','composition'=>'modern clinical editorial, precise, uncluttered',
            'palette'=>'crisp whites, soft blues, warm accents','photography'=>'high-end medical/lifestyle photography, 50mm','atmosphere'=>'trustworthy, modern, pristine, caring',
            'contrast'=>'medium, clean','dof'=>'medium, sharp subject','treatment'=>'bright neutral grade, immaculate, confidence-inspiring'],
        'corporate_editorial' => [
            'nombre'=>'Corporate Editorial','lighting'=>'controlled directional light, confident shadows','composition'=>'executive editorial, strong lines, architectural framing',
            'palette'=>'deep navy, charcoal, warm neutrals, brass accents','photography'=>'premium business editorial, 35mm','atmosphere'=>'authoritative, sophisticated, credible',
            'contrast'=>'high, deliberate','dof'=>'medium','treatment'=>'refined cool-neutral grade, polished, premium'],
        'industrial_cinematic' => [
            'nombre'=>'Industrial Cinematic','lighting'=>'dramatic directional light, hard rim light, sparks/glow if relevant','composition'=>'gritty cinematic, dynamic angles, strong depth',
            'palette'=>'steel grays, deep blacks, orange safety accents','photography'=>'cinematic commercial photography, 35mm','atmosphere'=>'rugged, capable, powerful, hardworking',
            'contrast'=>'very high, moody','dof'=>'medium, textured','treatment'=>'teal-orange cinematic grade, rich texture on metal/tools'],
        'architectural_editorial' => [
            'nombre'=>'Architectural Editorial','lighting'=>'natural golden light, long soft shadows','composition'=>'wide architectural framing, leading lines, aspirational',
            'palette'=>'warm neutrals, tropical greens, sky blues, terracotta','photography'=>'real-estate editorial photography, 24mm wide','atmosphere'=>'aspirational, warm, inviting, spacious',
            'contrast'=>'medium, luminous','dof'=>'deep, crisp','treatment'=>'bright airy grade, inviting warmth, magazine-quality'],
        'botanical_luxury' => [
            'nombre'=>'Botanical Luxury','lighting'=>'soft dappled daylight, delicate highlights','composition'=>'lush botanical still life, elegant clustering, negative space',
            'palette'=>'rich greens, blush, ivory, soft gold','photography'=>'fine-art floral photography, 85mm','atmosphere'=>'romantic, fresh, elegant, alive',
            'contrast'=>'medium, delicate','dof'=>'very shallow','treatment'=>'natural lush grade, dewy freshness, refined'],
        'modern_retail' => [
            'nombre'=>'Modern Retail','lighting'=>'clean studio light with soft gradients','composition'=>'bold product hero, confident centering, minimal props',
            'palette'=>'vivid brand tones, clean neutrals','photography'=>'premium product photography, 85mm','atmosphere'=>'desirable, modern, aspirational',
            'contrast'=>'medium-high, punchy','dof'=>'medium, product sharp','treatment'=>'vibrant clean grade, crisp reflections'],
        'lifestyle_emotional' => [
            'nombre'=>'Lifestyle Emotional','lighting'=>'warm natural light, tender glow','composition'=>'candid lifestyle moment, authentic, human warmth',
            'palette'=>'warm honey tones, soft neutrals','photography'=>'documentary lifestyle photography, 35mm','atmosphere'=>'heartfelt, genuine, joyful, caring',
            'contrast'=>'medium, soft','dof'=>'shallow','treatment'=>'warm natural grade, real skin/fur tones, emotive'],
        'puerto_rican_lifestyle' => [
            'nombre'=>'Puerto Rican Lifestyle','lighting'=>'bright tropical sunlight, vivid warm glow','composition'=>'vibrant island lifestyle, lively, full of life','palette'=>'tropical turquoise, coral, sunny yellow, warm terracotta',
            'photography'=>'authentic Caribbean lifestyle photography, 35mm','atmosphere'=>'warm, festive, proud, community','contrast'=>'medium-high, sunny',
            'dof'=>'medium','treatment'=>'vivid warm tropical grade, sun-kissed, alive'],
    ];
}

/** Elige el estilo por INDUSTRIA (categoría) + lo que vende. Nunca reutiliza a ciegas. */
function estilo_por_industria(string $categoria, string $que_vende): string {
    $t = mb_strtolower($categoria . ' ' . $que_vende, 'UTF-8');
    $map = [
        'food_luxury'            => ['comida','restaurant','reposter','bizcocho','dona','panad','cafe','café','postre','dulce','food','catering','pizza','bar','helad','kiosco','fritur'],
        'luxury_wellness'        => ['spa','wellness','masaje','belleza','salon','salón','barber','uñas','estetic','peluqu','maquill','skincare','yoga'],
        'medical_premium'        => ['dental','dentista','medic','médic','salud','clinic','óptic','optic','terap','psicolog','fisio','veterinar'],
        'corporate_editorial'    => ['abogad','legal','contab','financ','consult','seguro','notar','bienes raíces','real estate','agencia'],
        'industrial_cinematic'   => ['mecán','mecan','ferret','construc','plomer','electric','taller','auto','herrami','industrial','soldad','hojalater'],
        'architectural_editorial'=> ['bienes','real estate','propiedad','apartament','casa','arquitect','remodel','inmobili'],
        'botanical_luxury'       => ['flor','floris','jardin','jardín','plant','vivero','ramo','bouquet'],
        'modern_retail'          => ['tienda','boutique','ropa','moda','accesor','zapat','joyer','retail','venta','gift','regalo'],
        'lifestyle_emotional'    => ['mascota','veterinar','perro','gato','pet','guarder','niñ','escuela','coach','fitness','gym','gimnas'],
    ];
    foreach ($map as $estilo => $claves) {
        foreach ($claves as $k) { if (strpos($t, $k) !== false) return $estilo; }
    }
    return 'puerto_rican_lifestyle';   // default: sabor local con alma
}

/**
 * AGENTE 1 · ESTRATEGA DE CAMPAÑA — decide qué EMOCIÓN provocar. No piensa en imágenes.
 */
function campana_estrategia(PDO $pdo, int $marca_id, string $ctx, string $copy): string {
    $sys = "Eres un ESTRATEGA DE CAMPAÑA publicitaria de una agencia de primer nivel. Recibes el perfil de un negocio y el "
         . "copy de un post. Tu ÚNICA tarea: decidir qué EMOCIÓN o DESEO queremos provocar en el cliente con la imagen "
         . "(hambre, antojo, nostalgia, confianza, lujo, urgencia, deseo, celebración, pertenencia, calma, orgullo...). "
         . "NO pienses en imágenes, objetos ni cámaras — piensa en MARKETING. Responde en 1-2 frases: la emoción principal "
         . "y por qué le pega a ESE público. Nada más.";
    $r = ia_ejecutar($pdo, 'estratega', 'Estrategia de campaña', "Negocio:\n{$ctx}\n\nCopy del post:\n\"{$copy}\"\n\n¿Qué emoción provocamos?", [
        'marca_id'=>$marca_id, 'sistema'=>$sys, 'temperatura'=>0.7, 'max_tokens'=>160, 'thinking_budget'=>0, 'mock_texto'=>'Provocar antojo y nostalgia cálida de hogar.',
    ]);
    return trim((string)($r['texto'] ?? ''));
}

/**
 * AGENTE 2 · DIRECTOR DE ARTE — traduce la estrategia a un VISUAL BRIEF estructurado.
 * No escribe prompts ni piensa en IA. La imagen COMPLEMENTA la emoción, no ilustra el copy.
 * @return array Visual Brief (o [] si falla).
 */
function director_creativo_visual(PDO $pdo, int $marca_id, string $que_vende, string $copy, string $estrategia, array $estilo, bool $con_texto, string $nombre, string $instr = '', array $lente = [], string $evitar = ''): array {
    $guia = "Guía de estilo visual ({$estilo['nombre']}): luz={$estilo['lighting']}; composición={$estilo['composition']}; "
          . "paleta={$estilo['palette']}; fotografía={$estilo['photography']}; atmósfera={$estilo['atmosphere']}; "
          . "contraste={$estilo['contrast']}; profundidad={$estilo['dof']}; color={$estilo['treatment']}.";
    $extra_txt = $con_texto
        ? "Este es un GRÁFICO PROMOCIONAL: añade además los campos \"headline\" (titular corto y potente sacado del copy), "
          . "\"brand_text\" (el nombre del negocio como marca), \"cta\" (llamado corto) y \"layout\" (cómo se distribuye el diseño y la tipografía)."
        : "Es una FOTOGRAFÍA comercial SIN texto ni letras.";
    $sys = "Eres un DIRECTOR DE ARTE senior de una agencia publicitaria top. Recibes la estrategia emocional, el negocio y una "
         . "guía de estilo. Diseñas la dirección artística de UNA imagen publicitaria de calidad de campaña. La imagen "
         . "COMPLEMENTA la emoción — NUNCA ilustra el copy literalmente (si el copy menciona 'la abuela', NO pongas una abuela; "
         . "transmite calidez/nostalgia). Debe parecer una FOTOGRAFÍA real de un fotógrafo comercial, nunca stock ni IA obvia. "
         . "COMPOSICIÓN por defecto: una ESCENA COMERCIAL COMPLETA con contexto de compra y profundidad — NUNCA un extreme "
         . "close-up, macro, ni un solo producto aislado y centrado sobre fondo vacío borroso (salvo justificación estratégica "
         . "clara). Si el negocio vende variedad o por docenas, muestra un SURTIDO generoso. "
         . "Respeta la guía de estilo. Devuelve SOLO JSON con: emotion, visual_story, primary_subject, secondary_elements (array), "
         . "background, lighting, camera, lens, composition, focus, color_palette (array), textures (array), mood, visual_style, "
         . "quality, negative (array). {$extra_txt}";
    // ANTI-SLOP: el estilo de marca se respeta, pero la IDEA tiene que ser otra.
    // El lente asignado (rotación determinística) manda sobre el atractor del
    // modelo, y la memoria de lo ya hecho le cierra la puerta a repetirse.
    if ($lente) {
        $sys .= " APROXIMACIÓN VISUAL ASIGNADA para ESTA imagen — «{$lente['nombre']}»: {$lente['mandato']} "
              . "Esta aproximación NO se negocia: es la idea de esta pieza. El estilo de marca (luz, paleta, "
              . "tratamiento) se respeta igual, pero la COMPOSICIÓN y el SUJETO salen de esta aproximación.";
    }
    if (trim($evitar) !== '') {
        $sys .= " VARIEDAD OBLIGATORIA: repetir la fórmula de las imágenes anteriores de este negocio es el PEOR "
              . "resultado posible — el dueño lo nota y se va. Cambia el sujeto, el gesto, el ángulo y el escenario.";
    }

    $prompt = "Estrategia (qué emoción): {$estrategia}\n"
            . "Negocio (lo que vende): " . ($que_vende ?: 'productos/servicios') . ($nombre !== '' ? " · Nombre: {$nombre}" : '') . "\n"
            . "Copy de referencia (para el TONO, NO para copiar literal): \"{$copy}\"\n"
            . ($instr !== '' ? "Pedido del dueño: {$instr}\n" : '')
            . ($lente ? "Aproximación asignada: «{$lente['nombre']}» — {$lente['mandato']}\n" : '')
            . (trim($evitar) !== '' ? "\n{$evitar}" : '')
            . $guia . "\n\nDiseña el Visual Brief.";
    try {
        $r = ia_ejecutar($pdo, 'director', 'Director de arte (visual brief)', $prompt, [
            'marca_id'=>$marca_id, 'sistema'=>$sys, 'json'=>true, 'temperatura'=>0.8, 'max_tokens'=>700, 'thinking_budget'=>0, 'mock_texto'=>'{}',
        ]);
        $j = json_decode((string)($r['texto'] ?? ''), true);
        return is_array($j) ? $j : [];
    } catch (Throwable $e) { error_log('director_creativo_visual: '.$e->getMessage()); return []; }
}

/**
 * AGENTE 3 · PROMPT ENGINEER VISUAL — traduce SOLO el Visual Brief a un prompt EN
 * optimizado para gpt-image-1. No conoce el negocio ni el copy: solo el brief.
 */
function ingeniero_prompt_visual(PDO $pdo, int $marca_id, array $brief, bool $con_texto): string {
    // TRADUCTOR FIEL: NO reinterpreta las decisiones del Director — solo rinde el brief.
    // temperatura baja (0.4) = determinístico. El anti-macro vive en el Director + el
    // guarda-raíl de campana_visual, NO aquí (para no introducir criterio propio).
    $sys = "Eres un PROMPT ENGINEER TÉCNICO para gpt-image-1. Tu ÚNICO trabajo: TRADUCIR con FIDELIDAD un Visual Brief (JSON) "
         . "a UN prompt EN INGLÉS. NO reinterpretas ni cambias NINGUNA decisión del Director de Arte: no añades ideas propias, "
         . "no 'mejoras' la composición, no cambias el sujeto, el encuadre, la luz ni el estilo. RINDE TODOS los campos del brief "
         . "(primary_subject, secondary_elements, background, lighting, camera, lens, composition, focus, color_palette, textures, "
         . "mood, visual_story, visual_style, quality) en prosa densa y fluida optimizada para el modelo, y al FINAL los 'negative' "
         . "del brief tal cual. Fotografía comercial fotorrealista, sin look de IA ni de stock. "
         . ($con_texto
             ? "Si el brief trae headline/brand_text/cta, intégralos con tipografía profesional, bien escritos en español, jerarquía clara."
             : "SIN ningún texto ni letra dentro de la imagen.")
         . " NUNCA empieces con 'Create an image of'. Devuelve SOLO el prompt, sin comillas ni notas.";
    $r = ia_ejecutar($pdo, 'creador', 'Prompt engineer visual', "Visual Brief:\n" . json_encode($brief, JSON_UNESCAPED_UNICODE), [
        'marca_id'=>$marca_id, 'sistema'=>$sys, 'temperatura'=>0.4, 'max_tokens'=>900, 'thinking_budget'=>0, 'mock_texto'=>'',
    ]);
    return trim((string)($r['texto'] ?? ''));
}

/**
 * ORQUESTADOR — corre el pipeline completo y devuelve el prompt final para el modelo.
 * Degrada con gracia en cada eslabón; si todo falla, cae a dirigir_arte() (respaldo simple).
 * @return array{prompt:string, brief:array, estrategia:string, estilo:string}
 */
function campana_visual(PDO $pdo, int $marca_id, array $m, string $copy, array $opts = []): array {
    $con_texto = !empty($opts['con_texto']);
    $instr     = trim((string)($opts['instrucciones'] ?? ''));
    $nombre    = trim((string)($m['nombre_negocio'] ?? ''));

    // Grounding real del negocio (mismo que usa el resto del corillo).
    $ctx = function_exists('cerebro_negocio') ? cerebro_negocio($pdo, $marca_id, $m) : '';
    $prods_raw = $m['productos'] ?? [];
    if (is_string($prods_raw)) $prods_raw = json_decode($prods_raw, true) ?: [];
    $prods = [];
    foreach ((array)$prods_raw as $p) { $nom = is_array($p) ? trim((string)($p['nombre'] ?? '')) : trim((string)$p); if ($nom!=='') $prods[]=$nom; }
    $que_vende = $prods ? implode(', ', array_slice($prods,0,8)) : trim((string)($m['descripcion'] ?? ''));

    // Industria → estilo.
    $cat = '';
    if (!empty($m['categoria_id'])) {
        try { $cat = (string)$pdo->query("SELECT nombre FROM categorias WHERE id=" . (int)$m['categoria_id'])->fetchColumn(); } catch (Throwable $e) {}
    }
    $estilo_key = estilo_por_industria($cat, $que_vende);
    $estilo = biblioteca_estilos()[$estilo_key];

    // ── ANTI-SLOP: memoria + rotación de la IDEA ──
    //  El estilo de marca ya quedó fijado arriba (identidad). Aquí decidimos que
    //  la IMAGEN sea distinta a las anteriores: se asigna el lente que lleva más
    //  tiempo sin usarse y se le enseña al Director lo que ya hizo.
    require_once __DIR__ . '/variedad_visual.php';
    $lente = ['clave' => '', 'nombre' => '', 'mandato' => '', 'mata' => []];
    $evitar = '';
    try {
        $lente  = variedad_lente_asignado($pdo, $marca_id, $opts['lente'] ?? null);
        $evitar = variedad_evitar_txt($pdo, $marca_id, 6);
    } catch (Throwable $e) { error_log('campana_visual variedad: '.$e->getMessage()); }

    $prompt = ''; $brief = []; $estrategia = '';
    try {
        $estrategia = campana_estrategia($pdo, $marca_id, ($ctx !== '' ? $ctx : $que_vende), $copy);
        $brief = director_creativo_visual($pdo, $marca_id, $que_vende, $copy, $estrategia, $estilo, $con_texto, $nombre, $instr, $lente, $evitar);
        if (!empty($brief)) {
            $prompt = ingeniero_prompt_visual($pdo, $marca_id, $brief, $con_texto);
            // Queda la huella: la próxima imagen sabrá que esta existió.
            try { variedad_registrar($pdo, $marca_id, (string)$lente['clave'], $brief, $opts['contenido_id'] ?? null); }
            catch (Throwable $e) { error_log('campana_visual huella: '.$e->getMessage()); }
        }
    } catch (Throwable $e) { error_log('campana_visual pipeline: '.$e->getMessage()); }

    // Respaldo: el director simple (dirigir_arte). Si TODO falla, '' → generar_grafica usa su prompt.
    if (trim($prompt) === '' && function_exists('dirigir_arte')) {
        try {
            $est_dir = ['generar' => $estilo['photography'] . ', ' . $estilo['lighting'] . ', ' . $estilo['treatment']];
            $prompt = dirigir_arte($pdo, $marca_id, $que_vende, $copy, $instr, $est_dir, $con_texto, $nombre);
        } catch (Throwable $e) { error_log('campana_visual fallback: '.$e->getMessage()); }
    }

    // GUARDA-RAÍL de composición (validación D): mata el sesgo a macro/close-up/producto
    // único aislado — el bug de la "dona gigante". Mandato duro al final del prompt.
    $prompt = trim($prompt);
    if ($prompt !== '') {
        $u = mb_strtolower($copy, 'UTF-8');
        $variedad = (bool)preg_match('/docena|variedad|surtid|todos los sabores|sabores|assortment|evento|catering|caja|selecci/u', $u);
        // El mandato anti-macro aplica SALVO cuando el lente asignado ES el del
        // detalle (ahí el close-up es la idea, no el sesgo).
        if (($lente['clave'] ?? '') !== 'detalle_textura') {
            $prompt .= ' Composition mandate: a full commercial editorial scene with real depth, professional food styling and'
                     . ' buying context — NOT an extreme close-up, macro, or a single isolated centered product on an empty blurred'
                     . ' background.';
        }
        if ($variedad) $prompt .= ' Show a generous assortment / open box with several different items, abundant and appetizing.';
        // Negativos de VARIEDAD: matan la muletilla (la mano anónima sosteniendo
        // el producto) y lo que el lente de turno prohíbe expresamente.
        try {
            $neg = variedad_negativos($lente);
            if ($neg !== '') $prompt .= ' Negative prompts: ' . $neg . ', empty blurred background, catalog cutout.';
        } catch (Throwable $e) {}
    }
    return ['prompt'=>$prompt, 'brief'=>$brief, 'estrategia'=>$estrategia, 'estilo'=>$estilo_key, 'lente'=>$lente['clave'] ?? ''];
}
