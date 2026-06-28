<?php
// ============================================================
//  CRECER — Agentes de IA del producto
//  includes/agentes.php
//
//  Cada agente es una función que usa ia_ejecutar() (de ia.php),
//  así que TODA decisión queda registrada en crecer_ia_log.
//  Requiere db.php + ia.php cargados antes.
// ============================================================

require_once __DIR__ . '/ia.php';

// Límites de generación de imágenes (control de costos)
if (!defined('CRECER_IMG_SEMANA')) define('CRECER_IMG_SEMANA', 10); // máximo por semana (ventana 7 días)
if (!defined('CRECER_IMG_POST'))   define('CRECER_IMG_POST', 2);    // máximo de generaciones IA por post

/** Convierte "El Palo Dulce" → "el-palo-dulce" (para links públicos). */
function slugify(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-') ?: 'negocio';
}

/**
 * INTAKE: guarda (o actualiza) el perfil de un negocio en crecer_marca.
 * Idempotente por (usuario_id + nombre_negocio): si ya existe, lo devuelve.
 *
 * @param array $d  nombre_negocio, usuario_id, municipio_id, descripcion,
 *                  voz, productos(array), publico_objetivo, ofertas,
 *                  instagram, whatsapp
 * @return int  id de la marca
 */
function crear_marca(PDO $pdo, array $d): int {
    $existe = $pdo->prepare(
        "SELECT id FROM crecer_marca WHERE usuario_id = ? AND nombre_negocio = ?");
    $existe->execute([$d['usuario_id'], $d['nombre_negocio']]);
    if ($id = $existe->fetchColumn()) return (int)$id;

    $stmt = $pdo->prepare(
        "INSERT INTO crecer_marca
           (usuario_id, municipio_id, categoria_id, nombre_negocio, descripcion,
            voz, productos, publico_objetivo, ofertas, instagram, whatsapp, estado)
         VALUES
           (:usuario_id, :municipio_id, :categoria_id, :nombre, :descripcion,
            :voz, :productos, :publico, :ofertas, :instagram, :whatsapp, 'activo')");
    $stmt->execute([
        ':usuario_id'   => $d['usuario_id'],
        ':municipio_id' => $d['municipio_id'] ?? null,
        ':categoria_id' => $d['categoria_id'] ?? null,
        ':nombre'       => $d['nombre_negocio'],
        ':descripcion'  => $d['descripcion'] ?? null,
        ':voz'          => $d['voz'] ?? null,
        ':productos'    => isset($d['productos']) ? json_encode($d['productos'], JSON_UNESCAPED_UNICODE) : null,
        ':publico'      => $d['publico_objetivo'] ?? null,
        ':ofertas'      => $d['ofertas'] ?? null,
        ':instagram'    => $d['instagram'] ?? null,
        ':whatsapp'     => $d['whatsapp'] ?? null,
    ]);
    $id = (int)$pdo->lastInsertId();
    // slug único para el link público (nombre + id garantiza unicidad)
    $slug = slugify($d['nombre_negocio']) . '-' . $id;
    $pdo->prepare("UPDATE crecer_marca SET slug=? WHERE id=?")->execute([$slug, $id]);
    return $id;
}

/**
 * AGENTE INTAKE POR VOZ — escucha al dueño (audio) y extrae su perfil
 * de negocio como JSON. Es el corazón del onboarding "wow".
 *
 * @param string $audio_b64  audio en base64 (webm/ogg/mp3/wav)
 * @return array  ['descripcion','voz','productos'(array),'publico_objetivo',
 *                 'ofertas','instagram','whatsapp']
 */
function perfil_desde_voz(PDO $pdo, ?int $marca_id, string $audio_b64, string $audio_mime, string $nombre_negocio = ''): array {
    $sistema = <<<SYS
Eres el agente de INTAKE de Crecer. Escuchas a un microempresario boricua
hablar de su negocio y extraes su perfil. Fiel a lo que dijo, en español
puertorriqueño. Devuelve SOLO JSON válido, sin explicación.
SYS;
    $prompt = "Escucha el audio del dueño y devuelve un JSON con estas llaves:\n"
        . "- descripcion: 1–2 frases de qué es el negocio.\n"
        . "- voz: cómo habla (tono, palabras típicas, personalidad) para imitar su estilo en los posts.\n"
        . "- productos: array de strings con sus productos/servicios.\n"
        . "- publico_objetivo: a quién le vende.\n"
        . "- ofertas: promo o algo especial que mencione (o \"\").\n"
        . "- instagram: handle si lo dice (o \"\").\n"
        . "- whatsapp: número si lo dice (o \"\").\n"
        . ($nombre_negocio !== '' ? "El negocio se llama: {$nombre_negocio}.\n" : "")
        . "Si algo no lo menciona, deja \"\" o lista vacía. NO inventes.";

    $r = ia_ejecutar($pdo, 'intake', 'Extraer perfil desde voz', $prompt, [
        'marca_id'        => $marca_id,
        'sistema'         => $sistema,
        'json'            => true,
        'thinking_budget' => 0,
        'temperatura'     => 0.4,
        'max_tokens'      => 900,
        'audio'           => ['data' => $audio_b64, 'mime' => $audio_mime],
        'mock_texto'      => '{"descripcion":"[MOCK] negocio boricua de comida","voz":"cercano y alegre, usa nene/nena","productos":["bizcocho","quesitos"],"publico_objetivo":"familias del pueblo","ofertas":"","instagram":"","whatsapp":""}',
    ]);

    $j = json_decode(trim($r['texto']), true);
    if (!is_array($j)) $j = [];
    return $j + [
        'descripcion' => '', 'voz' => '', 'productos' => [],
        'publico_objetivo' => '', 'ofertas' => '', 'instagram' => '', 'whatsapp' => '',
    ];
}

/**
 * Variante por TEXTO (respaldo del onboarding por voz): el dueño escribe
 * de su negocio en vez de grabarse. Misma salida que perfil_desde_voz.
 */
function perfil_desde_texto(PDO $pdo, ?int $marca_id, string $texto, string $nombre_negocio = ''): array {
    $sistema = <<<SYS
Eres el agente de INTAKE de Crecer. Lees lo que un microempresario boricua
escribió sobre su negocio y extraes su perfil. Fiel a lo que escribió, en
español puertorriqueño. Devuelve SOLO JSON válido, sin explicación.
SYS;
    $prompt = "Lee lo que el dueño escribió y devuelve un JSON con estas llaves:\n"
        . "- descripcion: 1–2 frases de qué es el negocio.\n"
        . "- voz: cómo habla (tono, palabras típicas, personalidad) para imitar su estilo.\n"
        . "- productos: array de strings con sus productos/servicios.\n"
        . "- publico_objetivo: a quién le vende.\n"
        . "- ofertas: promo o algo especial que mencione (o \"\").\n"
        . "- instagram: handle si lo dice (o \"\").\n"
        . "- whatsapp: número si lo dice (o \"\").\n"
        . ($nombre_negocio !== '' ? "El negocio se llama: {$nombre_negocio}.\n" : "")
        . "Si algo no lo menciona, deja \"\" o lista vacía. NO inventes.\n\n"
        . "LO QUE ESCRIBIÓ:\n{$texto}";

    $r = ia_ejecutar($pdo, 'intake', 'Extraer perfil desde texto', $prompt, [
        'marca_id'        => $marca_id,
        'sistema'         => $sistema,
        'json'            => true,
        'thinking_budget' => 0,
        'temperatura'     => 0.4,
        'max_tokens'      => 900,
        'mock_texto'      => '{"descripcion":"[MOCK] negocio boricua de comida","voz":"cercano y alegre","productos":["bizcocho","quesitos"],"publico_objetivo":"familias del pueblo","ofertas":"","instagram":"","whatsapp":""}',
    ]);

    $j = json_decode(trim($r['texto']), true);
    if (!is_array($j)) $j = [];
    return $j + [
        'descripcion' => '', 'voz' => '', 'productos' => [],
        'publico_objetivo' => '', 'ofertas' => '', 'instagram' => '', 'whatsapp' => '',
    ];
}

/**
 * AGENTE DISEÑADOR — genera el logo del negocio con IA y lo guarda.
 * Devuelve ['archivo'=>url, 'costo'].
 */
function generar_logo(PDO $pdo, int $marca_id, array $opts = []): array {
    $m = leer_marca($pdo, $marca_id);
    $nombre = $m['nombre_negocio'];
    $desc = trim($opts['descripcion'] ?? '') ?: (string)($m['descripcion'] ?? '');
    $prods = $m['productos'] ? implode(', ', array_map(
        fn($p) => is_array($p) ? ($p['nombre'] ?? '') : $p, $m['productos'])) : '';

    // Sliders (0–100) → frases de dirección de arte
    $tono = (int)($opts['tono'] ?? 50);
    $epoca = (int)($opts['epoca'] ?? 50);
    $detalle = (int)($opts['detalle'] ?? 50);
    $f_tono   = $tono < 35 ? 'serio, formal, sobrio' : ($tono > 65 ? 'alegre, divertido, con energía' : 'equilibrado entre serio y alegre');
    $f_epoca  = $epoca < 35 ? 'clásico, tradicional, atemporal' : ($epoca > 65 ? 'moderno, contemporáneo, fresco' : 'entre clásico y moderno');
    $f_detalle= $detalle < 35 ? 'minimalista, simple, mucho aire' : ($detalle > 65 ? 'detallado, elaborado, rico en elementos' : 'balanceado');

    $estilo = trim($opts['estilo'] ?? '');
    $tipo   = trim($opts['tipografia'] ?? '');
    $color  = trim($opts['color'] ?? '');
    $instr  = trim($opts['instrucciones'] ?? '');

    $prompt = "Diseña un LOGO de marca profesional, nivel estudio de diseño "
      . "(calidad Behance/Dribbble), para este negocio boricua.\n"
      . "Negocio: {$nombre}\n"
      . ($desc  ? "Descripción: {$desc}\n" : '')
      . ($prods ? "Ofrece: {$prods}\n" : '')
      . "\nDIRECCIÓN DE ARTE:\n"
      . "- Tono: {$f_tono}.\n"
      . "- Estilo: {$f_epoca}.\n"
      . "- Complejidad: {$f_detalle}.\n"
      . ($estilo ? "- Vibra/estilo: {$estilo}.\n" : '')
      . ($tipo   ? "- Tipografía: {$tipo}.\n" : '')
      . ($color  ? "- Colores: {$color}.\n" : '')
      . "- Un símbolo/ícono distintivo y memorable (concepto creativo, no genérico).\n"
      . "- El nombre \"{$nombre}\" perfectamente escrito y bien integrado.\n"
      . "- Fondo blanco liso, composición centrada, apto para foto de perfil.\n"
      . "- Con alma y personalidad.";
    if ($instr !== '') $prompt .= "\n\nLO QUE PIDE EL DUEÑO (prioriza esto): {$instr}";

    $fname = "marca_{$marca_id}/logo_" . uniqid() . ".png";
    $r = ia_imagen($pdo, 'diseñador', 'Generar logo del negocio', $prompt,
        $fname, ['marca_id' => $marca_id, 'modelo' => 'gemini-3-pro-image']);
    $pdo->prepare("INSERT INTO crecer_logos (marca_id, archivo) VALUES (?, ?)")
        ->execute([$marca_id, $r['archivo']]);
    return $r;
}

/**
 * AGENTE CREADOR (visual) — convierte la FOTO REAL del negocio en un post
 * profesional para redes (mantiene el producto real; regla de IP).
 */
function generar_grafica(PDO $pdo, int $marca_id, ?string $foto_abs, array $opts = []): array {
    $m = leer_marca($pdo, $marca_id);
    $copy      = trim($opts['copy'] ?? '');         // el texto del post (coherencia)
    $con_texto = !empty($opts['con_texto']);
    $con_logo  = !empty($opts['con_logo']);
    $estilo    = trim($opts['estilo'] ?? '');

    // Imágenes de entrada: foto del producto (si hay) + logo (si se pide)
    $imagenes = [];
    if ($foto_abs && is_file($foto_abs)) {
        $imagenes[] = ['data' => base64_encode((string)file_get_contents($foto_abs)),
                       'mime' => (function_exists('mime_content_type') ? mime_content_type($foto_abs) : null) ?: 'image/jpeg'];
    }
    $logo_abs = null;
    if ($con_logo && !empty($m['logo_path'])) {
        $logo_abs = rtrim(UPLOADS_PATH, '/\\') . '/' . ltrim(str_replace(rtrim(UPLOADS_URL,'/'), '', $m['logo_path']), '/');
        if (is_file($logo_abs)) {
            $imagenes[] = ['data' => base64_encode((string)file_get_contents($logo_abs)), 'mime' => 'image/png'];
        }
    }

    $tiene_foto = (bool)($foto_abs && is_file($foto_abs));
    $prompt = "Crea el ARTE de un post de Instagram (cuadrado 1:1) para \"{$m['nombre_negocio']}\", negocio boricua.\n";
    if ($tiene_foto) {
        $prompt .= "- Usa la FOTO REAL del producto (primera imagen) como protagonista; NO la inventes ni la cambies, solo realza composición, luz y fondo.\n";
    } else {
        $prompt .= "- Genera una imagen apetitosa y realista acorde al negocio.\n";
    }
    if ($copy !== '') {
        $prompt .= "- La imagen debe ser COHERENTE con este mensaje del post (mismo tema, ambiente y vibra): \"{$copy}\".\n";
    }
    if ($con_logo && $logo_abs) {
        $le = $opts['logo_estilo'] ?? 'esquina';
        $como = [
            'esquina'   => 'el SÍMBOLO del logo, pequeño y elegante, en una esquina, en un color que combine con la imagen',
            'watermark' => 'el logo como MARCA DE AGUA sutil: monocromático o lineal, semitransparente, integrado a la composición SIN tapar el producto',
            'integrado' => 'el SÍMBOLO del logo integrado de forma creativa al diseño, recoloreado para que armonice con la paleta de la imagen',
        ][$le] ?? 'el símbolo del logo discreto en una esquina';
        $prompt .= "- Aplica {$como}.\n"
                 . "  IMPORTANTE: usa SOLO el símbolo/marca del logo (última imagen); NO copies su fondo blanco ni el recuadro — recórtalo y adáptalo a la imagen.\n";
    }
    if ($con_texto) {
        $prompt .= "- Añade un TEXTO corto y llamativo (un gancho sacado del mensaje), perfectamente escrito y bien diseñado sobre la imagen.\n";
    } else {
        $prompt .= "- SIN texto sobre la imagen: solo la foto/arte limpio y bonito.\n";
    }
    if ($estilo !== '') $prompt .= "- Estilo: {$estilo}.\n";
    $instr = trim($opts['instrucciones'] ?? '');
    if ($instr !== '') $prompt .= "- LO QUE PIDE EL DUEÑO (prioriza esto): {$instr}\n";
    $prompt .= "- Calidad de agencia top, colores cálidos boricuas, premium, listo para publicar.";

    // Con texto -> modelo Pro (texto perfecto). Sin texto -> estándar (más barato).
    $modelo = $con_texto ? 'gemini-3-pro-image' : 'gemini-2.5-flash-image';
    $fname = "marca_{$marca_id}/graficas/post_" . uniqid() . ".png";
    $r = ia_imagen($pdo, 'creador', 'Crear arte de post', $prompt, $fname, [
        'marca_id' => $marca_id,
        'modelo'   => $modelo,
        'imagenes' => $imagenes,
    ]);
    $pdo->prepare("INSERT INTO crecer_graficas (marca_id, archivo, copy_text) VALUES (?,?,?)")
        ->execute([$marca_id, $r['archivo'], $copy]);
    return $r;
}

/** Lee una marca como array asociativo (productos decodificado). */
function leer_marca(PDO $pdo, int $marca_id): array {
    $m = $pdo->prepare("SELECT * FROM crecer_marca WHERE id = ?");
    $m->execute([$marca_id]);
    $row = $m->fetch();
    if (!$row) throw new RuntimeException("Marca #$marca_id no existe.");
    $row['productos'] = $row['productos'] ? json_decode($row['productos'], true) : [];
    return $row;
}

/** Resume el perfil de la marca en texto para meterlo en un prompt. */
function marca_contexto(array $m): string {
    $prod = $m['productos'] ? implode(', ', array_map(
        fn($p) => is_array($p) ? ($p['nombre'] ?? json_encode($p)) : $p, $m['productos'])) : 'n/d';
    return "Negocio: {$m['nombre_negocio']}\n"
         . "Descripción: " . ($m['descripcion'] ?: 'n/d') . "\n"
         . "Productos: {$prod}\n"
         . "Público: " . ($m['publico_objetivo'] ?: 'n/d') . "\n"
         . "Voz/tono: " . ($m['voz'] ?: 'boricua cercano') . "\n"
         . "Ofertas: " . ($m['ofertas'] ?: 'n/d');
}

/**
 * Bloque de instrucción de TONO para el prompt del creador, a partir de los
 * 4 ejes (0-100) que el dueño definió en la pantalla de Marca. Degrada con
 * gracia: si la marca no tiene columnas de tono, devuelve "".
 */
function tono_instruccion(array $m): string {
    if (!isset($m['tono_boricua'])) return '';
    $bk = fn($x) => $x < 34 ? 0 : ($x < 67 ? 1 : 2);
    $b = $bk((int)$m['tono_boricua']); $f = $bk((int)$m['tono_formal']);
    $v = $bk((int)$m['tono_venta']);   $g = $bk((int)$m['tono_ingenio']);
    $B = [
        'Español neutral, sin regionalismos marcados.',
        'Español con sabor boricua moderado.',
        'Bien boricua: usa expresiones de la isla (wepa, mi gente, brutal, chévere, "pa\'", nene/nena) con naturalidad.',
    ][$b];
    $F = [
        'Bien casual y relajado, como un mensaje de WhatsApp a un pana.',
        'Equilibrado: cercano pero pulido.',
        'Formal y profesional; cuida la gramática y evita la jerga.',
    ][$f];
    $V = [
        'Informativo, sin presión de venta.',
        'Invita suavemente a la acción.',
        'Vendedor: llamado a la acción claro y urgencia honesta (sin exagerar ni mentir).',
    ][$v];
    $G = [
        'Sobrio y directo, sin chistes.',
        'Con una chispa ligera de gracia.',
        'Jocoso: mete humor boricua y algún juego de palabras, sin perder el mensaje.',
    ][$g];
    $emoji = ((int)$m['tono_boricua'] + (int)$m['tono_ingenio']) / 2;
    $E = $emoji < 28 ? 'Casi sin emojis.' : ($emoji > 66 ? 'Emojis con libertad (2-4).' : '1-2 emojis.');
    return "\n\nTONO DE VOZ (el dueño lo definió con los controles — RESPÉTALO por encima de la regla genérica de tono):\n"
         . "- Sabor: {$B}\n- Formalidad: {$F}\n- Venta: {$V}\n- Humor: {$G}\n- Emojis: {$E}";
}

/**
 * AGENTE PLANIFICADOR. Le pide a Gemini un plan de contenido para el
 * mes y lo materializa: crea/actualiza crecer_calendario + N borradores
 * en crecer_contenido. Devuelve [calendario_id, piezas[], ia_log_id].
 *
 * @param int $n_piezas  cuántas piezas planificar (ej. 8 para un mes ligero)
 */
function planificar_mes(PDO $pdo, int $marca_id, int $anio, int $mes, int $n_piezas = 8): array {
    $m = leer_marca($pdo, $marca_id);
    $ctx = marca_contexto($m);

    $sistema = <<<SYS
Eres el PLANIFICADOR de contenido de Crecer, un departamento de marketing
con IA para microempresas boricuas. Planificas el mes de redes sociales.
Reglas:
- Piensa como mercadólogo boricua: aprovecha fechas, cobros quincenales,
  fines de semana, y la cultura local de Puerto Rico.
- Variedad de plataformas (instagram, facebook) y tipos (post, story, reel).
- Cada pieza debe tener una IDEA concreta y accionable, no genérica.
- Responde SOLO JSON válido, sin texto extra.
SYS;

    $prompt = "Perfil del negocio:\n{$ctx}\n\n"
        . "Planifica {$n_piezas} piezas de contenido para el mes {$mes}/{$anio}.\n"
        . "Devuelve un JSON con esta forma EXACTA:\n"
        . '{"piezas":[{"dia":1,"plataforma":"instagram","tipo":"post","tema":"...","idea":"..."}]}'
        . "\n- dia: número 1-28.\n- plataforma: instagram|facebook.\n"
        . "- tipo: post|story|reel.\n- tema: 2-4 palabras.\n- idea: 1 oración concreta.";

    $r = ia_ejecutar($pdo, 'planificador', "Planificar {$n_piezas} piezas {$mes}/{$anio}", $prompt, [
        'marca_id'   => $marca_id,
        'sistema'    => $sistema,
        'json'       => true,
        'temperatura'=> 0.8,
        'max_tokens' => 4096,
        'thinking_budget' => 0,   // tarea estructurada: sin pensamiento, JSON completo
        'mock_texto' => '{"piezas":[{"dia":5,"plataforma":"instagram","tipo":"post","tema":"Producto estrella","idea":"Foto del bizcocho de guayaba con CTA por WhatsApp."}]}',
    ]);

    $plan = json_decode($r['texto'], true);
    $piezas = $plan['piezas'] ?? [];
    if (!$piezas) throw new RuntimeException("El planificador no devolvió piezas. Respuesta: " . substr($r['texto'], 0, 300));

    // Crear/obtener el calendario del periodo (UNIQUE por marca+anio+mes).
    $pdo->prepare(
        "INSERT INTO crecer_calendario (marca_id, anio, mes, estado, generado_por_ia, ia_log_id)
         VALUES (?,?,?, 'borrador', 1, ?)
         ON DUPLICATE KEY UPDATE ia_log_id = VALUES(ia_log_id), updated_at = NOW()"
    )->execute([$marca_id, $anio, $mes, $r['ia_log_id']]);
    $cal_id = (int)$pdo->query(
        "SELECT id FROM crecer_calendario WHERE marca_id={$marca_id} AND anio={$anio} AND mes={$mes}"
    )->fetchColumn();

    // Materializar cada pieza como borrador en crecer_contenido.
    $ins = $pdo->prepare(
        "INSERT INTO crecer_contenido
           (calendario_id, marca_id, plataforma, tipo, caption, fecha_programada, estado)
         VALUES (?,?,?,?,?,?, 'borrador')");
    $creadas = [];
    foreach ($piezas as $p) {
        $dia = max(1, min(28, (int)($p['dia'] ?? 1)));
        $fecha = sprintf('%04d-%02d-%02d 10:00:00', $anio, $mes, $dia);
        $plat = in_array($p['plataforma'] ?? '', ['instagram','facebook'], true) ? $p['plataforma'] : 'instagram';
        $tipo = in_array($p['tipo'] ?? '', ['post','story','reel'], true) ? $p['tipo'] : 'post';
        // Guardamos la IDEA en el caption provisional (el creador lo reemplaza luego).
        $idea = trim(($p['tema'] ?? '') . ' — ' . ($p['idea'] ?? ''));
        $ins->execute([$cal_id, $marca_id, $plat, $tipo, $idea, $fecha]);
        $creadas[] = [
            'id'         => (int)$pdo->lastInsertId(),
            'dia'        => $dia,
            'plataforma' => $plat,
            'tipo'       => $tipo,
            'idea'       => $idea,
        ];
    }

    return [
        'calendario_id' => $cal_id,
        'ia_log_id'     => $r['ia_log_id'],
        'piezas'        => $creadas,
        'costo'         => $r['costo'],
        'transporte'    => $r['transporte'],
    ];
}

/**
 * AGENTE CREADOR. Toma UNA pieza (borrador con su idea) y escribe el
 * caption boricua real. Actualiza crecer_contenido (caption + ia_log_id),
 * dejándola en 'borrador' para que el dueño la apruebe.
 *
 * @return array{caption:string, ia_log_id:int, costo:float}
 */
/**
 * APRENDIZAJE — cuando el dueño edita un caption, la IA extrae la lección de
 * vocabulario/voz boricua y la añade al glosario del negocio (para no repetirla).
 * No rompe la edición si la IA falla.
 */
function aprender_de_edicion(PDO $pdo, int $marca_id, string $original, string $editado): ?string {
    if (trim($original) === trim($editado) || trim($editado) === '') return null;
    $prompt = "El dueño de un negocio boricua editó el caption de un post. Compara el ORIGINAL con el EDITADO y extrae SOLO lecciones de VOCABULARIO o VOZ boricua para no repetir el error (ej: 'usa china, no naranja'; 'evita platicar, di hablar'). Máximo 2 viñetas muy cortas. Si el cambio NO es de vocabulario/voz (solo cambió datos o contenido), responde EXACTAMENTE: NINGUNA.\n\nORIGINAL:\n{$original}\n\nEDITADO:\n{$editado}";
    try {
        $r = ia_ejecutar($pdo, 'aprendiz', 'Aprender de edicion', $prompt, [
            'marca_id' => $marca_id, 'thinking_budget' => 0, 'max_tokens' => 200, 'temperatura' => 0.3,
            'mock_texto' => 'NINGUNA',
        ]);
        $leccion = trim($r['texto']);
        if ($leccion === '' || stripos($leccion, 'NINGUNA') !== false) return null;
        $g = $pdo->prepare("SELECT glosario FROM crecer_marca WHERE id=?"); $g->execute([$marca_id]);
        $actual = (string)$g->fetchColumn();
        $nuevo = trim($actual . "\n" . $leccion);
        $pdo->prepare("UPDATE crecer_marca SET glosario=? WHERE id=?")->execute([$nuevo, $marca_id]);
        return $leccion;
    } catch (Throwable $e) { return null; }
}

function redactar_pieza(PDO $pdo, int $contenido_id, array $extra = []): array {
    $c = $pdo->prepare("SELECT * FROM crecer_contenido WHERE id = ?");
    $c->execute([$contenido_id]);
    $pieza = $c->fetch();
    if (!$pieza) throw new RuntimeException("Contenido #$contenido_id no existe.");

    $m = leer_marca($pdo, (int)$pieza['marca_id']);
    $ctx = marca_contexto($m);
    $idea = $pieza['caption']; // en el borrador, el caption guarda la IDEA del plan

    $sistema = <<<SYS
Eres el CREADOR de contenido de Crecer. Escribes captions para redes
sociales de microempresas boricuas. Reglas:
- Español puertorriqueño AUTÉNTICO, nunca traducido ni "AI slop".
- Vocabulario local (bizcocho, no "tarta"; chavos; nene/nena; etc.).
- Tono según la voz del negocio. 1-2 emojis máximo.
- Llamado a la acción por WhatsApp y 3-4 hashtags locales.
- Máximo 60 palabras. Devuelve SOLO el caption, sin comillas ni explicación.
SYS;
    if (!empty($m['glosario'])) {
        $sistema .= "\n\nVOCABULARIO DEL NEGOCIO (el dueño lo corrigió — RESPÉTALO SIEMPRE, no repitas los errores):\n" . $m['glosario'];
    }
    $sistema .= tono_instruccion($m);

    $prompt = "Perfil del negocio:\n{$ctx}\n\n"
        . "Plataforma: {$pieza['plataforma']} | Tipo: {$pieza['tipo']}\n"
        . "Idea de esta pieza: {$idea}\n\n"
        . "Escribe el caption.";

    $r = ia_ejecutar($pdo, 'creador', "Redactar caption pieza #{$contenido_id}", $prompt, array_merge([
        'marca_id'    => (int)$pieza['marca_id'],
        'sistema'     => $sistema,
        'temperatura' => 0.95,
        'max_tokens'  => 400,
        'thinking_budget' => 0,
        'mock_texto'  => "[MOCK] Caption para: {$idea}",
    ], $extra));

    $pdo->prepare(
        "UPDATE crecer_contenido SET caption = ?, ia_log_id = ?, updated_at = NOW() WHERE id = ?"
    )->execute([trim($r['texto']), $r['ia_log_id'], $contenido_id]);

    return ['caption' => trim($r['texto']), 'ia_log_id' => $r['ia_log_id'], 'costo' => $r['costo']];
}

/**
 * CREADOR guiado por el dueño. Toma una pieza y la redacta a partir de un
 * TEMA sugerido y/o un BORRADOR del dueño (que la IA pule respetando su
 * intención). Respeta el glosario aprendido. Actualiza el caption.
 */
function redactar_sugerido(PDO $pdo, int $contenido_id, string $tema = '', string $borrador = ''): array {
    $c = $pdo->prepare("SELECT * FROM crecer_contenido WHERE id = ?");
    $c->execute([$contenido_id]);
    $pieza = $c->fetch();
    if (!$pieza) throw new RuntimeException("Contenido #$contenido_id no existe.");

    $m = leer_marca($pdo, (int)$pieza['marca_id']);
    $ctx = marca_contexto($m);

    $sistema = <<<SYS
Eres el CREADOR de contenido de Crecer. Escribes captions para redes
sociales de microempresas boricuas. Reglas:
- Español puertorriqueño AUTÉNTICO, nunca traducido ni "AI slop".
- Vocabulario local (bizcocho, no "tarta"; chavos; nene/nena; etc.).
- Tono según la voz del negocio. 1-2 emojis máximo.
- Llamado a la acción por WhatsApp y 3-4 hashtags locales.
- Máximo 60 palabras. Devuelve SOLO el caption, sin comillas ni explicación.
SYS;
    if (!empty($m['glosario'])) {
        $sistema .= "\n\nVOCABULARIO DEL NEGOCIO (el dueño lo corrigió — RESPÉTALO SIEMPRE, no repitas los errores):\n" . $m['glosario'];
    }
    $sistema .= tono_instruccion($m);

    $prompt = "Perfil del negocio:\n{$ctx}\n\n"
        . "Plataforma: {$pieza['plataforma']} | Tipo: {$pieza['tipo']}\n";
    if (trim($borrador) !== '') {
        $prompt .= "El DUEÑO escribió este BORRADOR del post. MEJÓRALO: corrige, pule y dale chispa "
                 . "boricua, pero RESPETA su intención y sus datos (precios, fechas, productos). "
                 . "No lo cambies por completo ni inventes datos.\n\nBORRADOR DEL DUEÑO:\n\"{$borrador}\"\n";
        if (trim($tema) !== '') $prompt .= "Tema/contexto extra: {$tema}\n";
    } else {
        $prompt .= "El DUEÑO pidió un post sobre este TEMA específico: \"{$tema}\".\n";
    }
    $prompt .= "\nEscribe el caption final.";

    $r = ia_ejecutar($pdo, 'creador', "Redactar post sugerido #{$contenido_id}", $prompt, [
        'marca_id'    => (int)$pieza['marca_id'],
        'sistema'     => $sistema,
        'temperatura' => 0.9,
        'max_tokens'  => 400,
        'thinking_budget' => 0,
        'mock_texto'  => "[MOCK] " . (trim($borrador) ?: trim($tema)),
    ]);

    $pdo->prepare(
        "UPDATE crecer_contenido SET caption = ?, ia_log_id = ?, updated_at = NOW() WHERE id = ?"
    )->execute([trim($r['texto']), $r['ia_log_id'], $contenido_id]);

    return ['caption' => trim($r['texto']), 'ia_log_id' => $r['ia_log_id'], 'costo' => $r['costo']];
}

/**
 * Corre el CREADOR sobre todas las piezas en borrador de un calendario
 * cuyo caption aún es la idea (sin redactar). Devuelve resumen.
 */
function redactar_calendario(PDO $pdo, int $calendario_id): array {
    $ids = $pdo->prepare(
        "SELECT id FROM crecer_contenido WHERE calendario_id = ? AND estado = 'borrador' ORDER BY fecha_programada");
    $ids->execute([$calendario_id]);
    $resultados = [];
    $costo_total = 0.0;
    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        $res = redactar_pieza($pdo, (int)$cid);
        $costo_total += $res['costo'];
        $resultados[] = ['contenido_id' => (int)$cid] + $res;
    }
    return ['piezas' => $resultados, 'costo_total' => round($costo_total, 6)];
}

/**
 * AGENTE ASISTENTE — el "helper" dentro del web app. Aclara dudas del
 * dueño sobre cómo funciona Crecer y lo guía. Conoce el contexto de su
 * marca y las secciones del panel. Tono boricua, corto y útil. Toda
 * consulta queda logueada en crecer_ia_log (agente='asistente').
 *
 * @param array $historial  turnos previos [['rol'=>'user'|'ia','texto'=>...], ...]
 * @return array{respuesta:string, ia_log_id:int}
 */
function asistente_responder(PDO $pdo, int $marca_id, string $pregunta, array $historial = []): array {
    $m = leer_marca($pdo, $marca_id);
    $ctx = marca_contexto($m);

    $sistema = <<<SYS
Eres el ASISTENTE de Crecer (también le decimos "el corillo"): un departamento
de marketing con IA para microempresas boricuas. Ayudas al dueño DENTRO de la
app: le aclaras dudas, le explicas cómo usar cada parte y lo guías paso a paso.

Tono: boricua cálido y cercano, claro y CORTO (2-5 frases o una lista breve).
Nada de relleno ni "AI slop". Tú lo tuteas. Si no sabes algo del negocio del
dueño, dilo y sugiérele dónde configurarlo. No inventes precios ni datos.

QUÉ HAY EN LA APP (oriéntalo a estas secciones):
- Inicio: resumen de lo que el corillo ha hecho.
- Contenido: la "fábrica de posts" — pedirle posts a la IA (tema, borrador o
  random), escoger plataformas y fecha, montar el arte y APROBAR cada post.
- Gráficas: estudio de arte; subir foto real del producto → arte de post.
- Marca: estudio de logo con IA.
- Órdenes & Agenda: pedidos, calendario, página pública con QR.
- Conectar redes (conectar.php): enlazar Instagram Business + Página de Facebook
  para que el corillo publique SOLO los posts que el dueño aprobó.

REGLAS CLAVE QUE DEBES SABER EXPLICAR:
- El dueño SIEMPRE aprueba cada post antes de que se publique. La IA propone,
  el dueño dispone.
- Para publicar automático a Instagram hace falta: cuenta de IG Business/Creator
  conectada a una Página de Facebook (eso lo pone el dueño una vez en "Conectar
  redes"). IG personal no se puede.
- Las imágenes salen de fotos reales del negocio o las genera la IA y el dueño
  las aprueba. Nunca se inventa el producto.
SYS;
    if (!empty($m['glosario'])) {
        $sistema .= "\n\nVocabulario propio de este negocio (respétalo):\n" . $m['glosario'];
    }
    $sistema .= tono_instruccion($m);

    // Compactar el historial reciente (últimos 6 turnos) dentro del prompt.
    $hist = '';
    foreach (array_slice($historial, -6) as $t) {
        $quien = (($t['rol'] ?? '') === 'ia') ? 'Asistente' : 'Dueño';
        $txt = trim((string)($t['texto'] ?? ''));
        if ($txt !== '') $hist .= "$quien: $txt\n";
    }

    $prompt = "Contexto del negocio del dueño:\n{$ctx}\n\n"
        . ($hist !== '' ? "Conversación hasta ahora:\n{$hist}\n" : '')
        . "Pregunta del dueño: {$pregunta}\n\n"
        . "Responde corto y útil.";

    $r = ia_ejecutar($pdo, 'asistente', 'Responder duda del dueño', $prompt, [
        'marca_id'        => $marca_id,
        'sistema'         => $sistema,
        'temperatura'     => 0.6,
        'max_tokens'      => 500,
        'thinking_budget' => 0,
        'mock_texto'      => '¡Claro! (respuesta de prueba — falta la credencial de IA). '
                           . 'Pregúntame cómo crear un post, montar tu logo o conectar tus redes.',
    ]);

    return ['respuesta' => trim($r['texto']), 'ia_log_id' => $r['ia_log_id']];
}

/**
 * AGENTE DE RETENCIÓN (capa Despegar de Clientela). Mira un cliente real del
 * negocio (sacado de las órdenes) y redacta un mensaje de WhatsApp boricua,
 * corto y personalizado, para reactivarlo / agradecerle / atraerlo de vuelta
 * según su segmento. El dueño lo aprueba antes de enviarlo. Logueado.
 *
 * @param array  $cli  ['nombre','n','total','dias_sin_comprar']
 * @param string $segmento  dormido|frecuente|nuevo|top
 * @return array{mensaje:string, ia_log_id:int}
 */
function mensaje_retencion(PDO $pdo, int $marca_id, array $cli, string $segmento): array {
    $m = leer_marca($pdo, $marca_id);
    $ctx = marca_contexto($m);

    $guias = [
        'dormido'   => 'Hace rato que no compra. Escríbele con cariño, sin sonar a venta desesperada: que lo extrañas y lo invitas a volver. Puedes mencionar una de tus ofertas.',
        'frecuente' => 'Es cliente fiel. Agradécele de corazón y hazlo sentir especial; quizás un detalle o adelanto para él/ella.',
        'nuevo'     => 'Compró por primera vez hace poco. Dale la bienvenida, agradece y déjale la puerta abierta para volver.',
        'top'       => 'Es de los que más gasta. Reconócele, trátalo VIP, hazlo sentir tu mejor cliente.',
    ];
    $guia = $guias[$segmento] ?? $guias['dormido'];

    $sistema = <<<SYS
Eres el agente de RETENCIÓN de Crecer. Escribes mensajes de WhatsApp para que
un negocio boricua le hable a UN cliente suyo. Reglas:
- Español puertorriqueño AUTÉNTICO, cálido y personal. Nunca "AI slop".
- CORTO: 2-4 frases, como un mensaje real de WhatsApp. 1 emoji máximo.
- Tutea al cliente por su nombre. Suena a persona, no a campaña.
- No inventes precios ni promesas que el negocio no dijo.
- Devuelve SOLO el mensaje, sin comillas ni explicación.
SYS;
    if (!empty($m['glosario'])) {
        $sistema .= "\n\nVocabulario del negocio (respétalo):\n" . $m['glosario'];
    }

    $nombre = trim((string)($cli['nombre'] ?? 'cliente'));
    $detalle = "Cliente: {$nombre}\n"
        . "Compras: " . (int)($cli['n'] ?? 0) . "\n"
        . (isset($cli['dias_sin_comprar']) ? "Días sin comprar: " . (int)$cli['dias_sin_comprar'] . "\n" : '')
        . (isset($cli['total']) ? "Total gastado: \$" . number_format((float)$cli['total'], 2) . "\n" : '');

    $prompt = "Perfil del negocio:\n{$ctx}\n\n"
        . "Este cliente es del segmento: {$segmento}. {$guia}\n\n"
        . "Datos del cliente:\n{$detalle}\n"
        . "Escribe el mensaje de WhatsApp para {$nombre}.";

    $r = ia_ejecutar($pdo, 'retencion', "Mensaje de retención ({$segmento})", $prompt, [
        'marca_id'        => $marca_id,
        'sistema'         => $sistema,
        'temperatura'     => 0.9,
        'max_tokens'      => 300,
        'thinking_budget' => 0,
        'mock_texto'      => "¡Hola {$nombre}! 👋 Hace rato no te veo por aquí. Pásate cuando quieras, que te tengo algo rico.",
    ]);

    return ['mensaje' => trim($r['texto']), 'ia_log_id' => $r['ia_log_id']];
}

/**
 * AGENTE DE ANALÍTICA (capa Despegar). Lee los números reales del mes del
 * negocio y escribe un resumen boricua corto + 1-2 sugerencias accionables.
 * No inventa cifras: solo interpreta las que se le pasan. Logueado.
 *
 * @param array $d  ['ventas_mes','ordenes_mes','ventas_mes_ant','posts_mes',
 *                   'piezas_ia_mes','mejor_mes' (texto opcional)]
 * @return array{texto:string, ia_log_id:int}
 */
function resumen_analitica(PDO $pdo, int $marca_id, array $d): array {
    $m = leer_marca($pdo, $marca_id);

    $sistema = <<<SYS
Eres el agente de ANALÍTICA de Crecer. Le explicas a un microempresario boricua
cómo le fue el mes, en cristiano. Reglas:
- Español puertorriqueño cálido y claro. Nada de jerga ni "AI slop".
- CORTO: 2-3 frases de resumen + 1 o 2 sugerencias concretas y accionables.
- Habla SOLO de los números que te doy; NO inventes cifras ni métricas.
- Tono de socio que te quiere ver crecer, no de reporte frío.
- Devuelve SOLO el texto, sin títulos ni explicación.
SYS;

    $ventas = number_format((float)($d['ventas_mes'] ?? 0), 2);
    $ant    = number_format((float)($d['ventas_mes_ant'] ?? 0), 2);
    $prompt = "Negocio: {$m['nombre_negocio']}\n"
        . "Ventas este mes: \${$ventas} en " . (int)($d['ordenes_mes'] ?? 0) . " pedidos.\n"
        . "Ventas el mes pasado: \${$ant}.\n"
        . "Posts publicados este mes: " . (int)($d['posts_mes'] ?? 0) . ".\n"
        . "Piezas que creó el corillo este mes (captions, artes, mensajes): " . (int)($d['piezas_ia_mes'] ?? 0) . ".\n"
        . (!empty($d['mejor_mes']) ? "Dato extra: {$d['mejor_mes']}\n" : '')
        . "\nEscribe el resumen del mes para el dueño.";

    $r = ia_ejecutar($pdo, 'analitica', 'Resumen del mes', $prompt, [
        'marca_id'        => $marca_id,
        'sistema'         => $sistema,
        'temperatura'     => 0.7,
        'max_tokens'      => 350,
        'thinking_budget' => 0,
        'mock_texto'      => 'Este mes se movió la cosa 💪 Seguiste publicando y entraron pedidos. '
                           . 'Mi consejo: dale seguimiento a los clientes que no han vuelto y publica algo el fin de semana, que es cuando más se compra.',
    ]);

    return ['texto' => trim($r['texto']), 'ia_log_id' => $r['ia_log_id']];
}

/**
 * EL CORILLO AUTÓNOMO. Para UNA marca: si tiene menos borradores pendientes
 * que su meta (autopilot_n), planifica y redacta los que falten SOLO, los
 * deja como borradores en los próximos días y los registra. El dueño los
 * aprueba después. No genera arte (lo caro lo controla el dueño al aprobar).
 *
 * @return array{creadas:int, ids:array, razon:string}
 */
function trabajo_autonomo(PDO $pdo, int $marca_id): array {
    $m = leer_marca($pdo, $marca_id);
    $objetivo = max(1, (int)($m['autopilot_n'] ?? 3));

    // ¿Cuántos borradores pendientes tiene ya? (no le amontonamos trabajo)
    $q = $pdo->prepare("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id=? AND estado='borrador'");
    $q->execute([$marca_id]);
    $pendientes = (int)$q->fetchColumn();

    $faltan = $objetivo - $pendientes;
    if ($faltan <= 0) {
        $pdo->prepare("UPDATE crecer_marca SET autopilot_ultimo=NOW() WHERE id=?")->execute([$marca_id]);
        return ['creadas' => 0, 'ids' => [], 'razon' => 'ya tiene suficientes borradores'];
    }

    $anio = (int)date('Y'); $mes = (int)date('n');
    try {
        $plan = planificar_mes($pdo, $marca_id, $anio, $mes, $faltan);
    } catch (Throwable $e) {
        return ['creadas' => 0, 'ids' => [], 'razon' => 'planificador falló: ' . substr($e->getMessage(), 0, 100)];
    }

    $ids = [];
    $i = 0;
    $reprog = $pdo->prepare("UPDATE crecer_contenido SET fecha_programada=? WHERE id=? AND marca_id=?");
    foreach ($plan['piezas'] as $pz) {
        $cid = (int)$pz['id'];
        try { redactar_pieza($pdo, $cid); } catch (Throwable $e) { /* queda la idea para editar */ }
        // Repartir en los próximos días (look "te dejé la semana lista")
        $fecha = date('Y-m-d 10:00:00', strtotime('+' . ($i + 1) . ' day'));
        $reprog->execute([$fecha, $cid, $marca_id]);
        $ids[] = $cid;
        $i++;
    }

    $pdo->prepare("UPDATE crecer_marca SET autopilot_ultimo=NOW() WHERE id=?")->execute([$marca_id]);
    return ['creadas' => count($ids), 'ids' => $ids, 'razon' => ''];
}

/**
 * EL LOOP DEL CORILLO AUTÓNOMO. Recorre las marcas con piloto automático
 * activo y plan vigente, les genera el trabajo, y avisa al dueño por email
 * cuando dejó posts nuevos. Pensado para correr por cron (semanal).
 *
 * @return array{marcas:int, creadas:int, detalle:array}
 */
function correr_corillo(PDO $pdo): array {
    require_once __DIR__ . '/suscripcion.php';
    require_once __DIR__ . '/notificaciones.php';

    $rows = $pdo->query(
        "SELECT m.id, m.nombre_negocio, m.usuario_id, u.email, u.nombre AS usuario_nombre
           FROM crecer_marca m JOIN usuarios u ON u.id = m.usuario_id
          WHERE m.autopilot = 1")->fetchAll();

    $tot = 0; $detalle = [];
    foreach ($rows as $r) {
        $mid = (int)$r['id'];
        if (plan_de_marca($pdo, $mid) === null) {        // sin plan vigente → no gasta IA
            $detalle[] = ['marca_id' => $mid, 'creadas' => 0, 'razon' => 'sin plan activo'];
            continue;
        }
        $res = trabajo_autonomo($pdo, $mid);
        $tot += $res['creadas'];
        $detalle[] = ['marca_id' => $mid, 'creadas' => $res['creadas'], 'razon' => $res['razon']];

        if ($res['creadas'] > 0 && !empty($r['email'])) {
            $n = $res['creadas'];
            $base = defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'http://localhost/crecer';
            $panel = $base . '/panel/aprobar2.php?marca=' . $mid;
            $nombre = $r['usuario_nombre'] ?: 'jefe';
            $negocio = htmlspecialchars($r['nombre_negocio'] ?: 'tu negocio');
            $asunto = "El corillo te dejó $n post" . ($n === 1 ? '' : 's') . " listo" . ($n === 1 ? '' : 's') . " ✨";
            $cuerpo = "<div style='font-family:Arial,sans-serif;max-width:520px;margin:auto;color:#1a1a24'>"
                . "<h2>¡Wepa, $nombre! 👋</h2>"
                . "<p>Mientras hacías lo tuyo, el corillo trabajó para <b>$negocio</b> y te dejó "
                . "<b>$n post" . ($n === 1 ? '' : 's') . "</b> listo" . ($n === 1 ? '' : 's') . " para que apruebes.</p>"
                . "<p style='margin:24px 0'><a href='$panel' style='background:#1a1a24;color:#fff;text-decoration:none;font-weight:bold;padding:13px 22px;border-radius:12px'>Ver lo que hizo el corillo</a></p>"
                . "<p style='color:#8a8a98;font-size:13px'>Tú apruebas, el corillo ejecuta. 🇵🇷</p></div>";
            crecer_enviar_email($r['email'], $asunto, $cuerpo);
        }
    }
    return ['marcas' => count($rows), 'creadas' => $tot, 'detalle' => $detalle];
}
