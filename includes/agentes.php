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
function generar_grafica(PDO $pdo, int $marca_id, string $foto_abs, array $opts = []): array {
    $m = leer_marca($pdo, $marca_id);
    if (!is_file($foto_abs)) throw new RuntimeException('Foto no encontrada.');
    $b64  = base64_encode((string)file_get_contents($foto_abs));
    $mime = function_exists('mime_content_type') ? (mime_content_type($foto_abs) ?: 'image/jpeg') : 'image/jpeg';
    $texto  = trim($opts['texto'] ?? '');
    $estilo = trim($opts['estilo'] ?? '');
    $prompt =
        "Convierte esta foto en un POST profesional para las redes sociales de "
      . "\"{$m['nombre_negocio']}\" (negocio boricua).\n"
      . "- MANTÉN el producto REAL de la foto como protagonista (no lo inventes ni lo cambies).\n"
      . "- Mejora la composición: fondo atractivo, iluminación apetitosa, estilo de agencia.\n"
      . "- Colores cálidos, vibrantes y boricuas. Formato cuadrado 1:1 para Instagram.\n"
      . ($estilo ? "- Estilo: {$estilo}.\n" : '')
      . ($texto ? "- Integra el texto \"{$texto}\" de forma bonita y perfectamente escrita.\n" : '')
      . "- Que se vea premium, con onda, listo para publicar.";
    $fname = "marca_{$marca_id}/graficas/post_" . uniqid() . ".png";
    $r = ia_imagen($pdo, 'creador', 'Crear grafica de post', $prompt, $fname, [
        'marca_id'      => $marca_id,
        'modelo'        => 'gemini-2.5-flash-image',
        'imagen_base64' => $b64,
        'imagen_mime'   => $mime,
    ]);
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
