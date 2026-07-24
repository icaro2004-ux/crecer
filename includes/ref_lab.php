<?php
// ============================================================
//  CRECER — Laboratorio de referencias visuales (includes/ref_lab.php)
//
//  Enseña al Director Creativo CRITERIO publicitario general a partir de
//  imágenes de referencia aprobadas por el admin. Extrae PRINCIPIOS
//  reutilizables (por qué funcionan), NO descripciones literales ni estilos
//  rígidos. Consolida en un Creative Playbook que orienta el nivel de calidad.
//
//  REGLA: las imágenes NUNCA se envían a la generación de clientes; solo el
//  PLAYBOOK (texto) llega al director. Cero imitación, cero biblioteca rígida.
// ============================================================

/** Analiza UNA referencia con visión → extrae principios generales (no descripción). */
function ref_analizar(PDO $pdo, int $ref_id): array {
    $r = $pdo->query("SELECT * FROM crecer_ref_imagenes WHERE id=" . (int)$ref_id)->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new RuntimeException('Referencia no existe.');
    // Cargar la imagen (desde disco vía su ruta).
    $rel = ltrim(str_replace(rtrim(UPLOADS_URL, '/'), '', (string)$r['archivo']), '/');
    $abs = rtrim(UPLOADS_PATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_file($abs)) throw new RuntimeException('Archivo no encontrado: ' . $abs);
    $bin  = (string)file_get_contents($abs);
    $mime = (function_exists('mime_content_type') ? mime_content_type($abs) : '') ?: 'image/png';

    $sys = "Eres un Director Creativo analizando una imagen publicitaria de referencia para ENSEÑAR criterio general — NO para "
        . "copiarla. Mira la imagen y extrae ÚNICAMENTE PRINCIPIOS REUTILIZABLES (por qué FUNCIONA como publicidad), como reglas "
        . "POSITIVAS y generales aplicables a CUALQUIER negocio. PROHIBIDO describir el sujeto, la pose, el texto, los colores "
        . "específicos, los logos o la identidad de esta imagen (nada de 'usar un perro con toalla' ni 'usar teal y magenta'). "
        . "Enfócate en: fuerza del concepto publicitario, jerarquía visual, integración texto-imagen, claridad del mensaje, "
        . "emoción, narrativa, uso del producto, presencia/interacción humana, recurso fantástico/inesperado, energía del color, "
        . "nivel de realismo, densidad de información, calidad del CTA, y por qué detiene el scroll. "
        . "Responde SOLO JSON: {\"principios\":[\"principio general y positivo 1\", ...]} — 5 a 10 principios cortos, generales.";
    $out = openai_chat($sys, "Analiza esta imagen y extrae los principios reutilizables.", _ref_modelo(), [
        'imagenes' => [['data' => base64_encode($bin), 'mime' => $mime]],
        'max_tokens' => 700, 'max_reintentos' => 0,
    ]);
    $j = json_decode((string)($out['texto'] ?? ''), true);
    $prin = is_array($j['principios'] ?? null) ? array_values(array_filter(array_map(fn($x)=>trim((string)$x), $j['principios']))) : [];
    $pdo->prepare("UPDATE crecer_ref_imagenes SET estado='analyzed', analisis_json=? WHERE id=?")
        ->execute([json_encode(['principios'=>$prin], JSON_UNESCAPED_UNICODE), $ref_id]);
    return $prin;
}

/** Consolida los principios de las referencias APROBADAS en el Creative Playbook. */
function ref_consolidar(PDO $pdo): array {
    $rows = $pdo->query("SELECT analisis_json FROM crecer_ref_imagenes WHERE estado='approved' AND analisis_json IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
    $todos = [];
    foreach ($rows as $aj) { $j = json_decode((string)$aj, true); foreach ((array)($j['principios'] ?? []) as $p) { $p=trim((string)$p); if($p!=='') $todos[]=$p; }}
    if (!$todos) return [];
    $sys = "Consolida estos principios (de varias imágenes publicitarias de referencia) en un CREATIVE PLAYBOOK general: reglas "
        . "POSITIVAS, deduplicadas y aplicables a CUALQUIER negocio. NADA de reglas específicas (nada de colores, sujetos, poses "
        . "ni composiciones concretas). Cada regla enseña CRITERIO, no una receta. Responde SOLO JSON: {\"principios\":[...]} — "
        . "8 a 14 principios finales, cortos y potentes.";
    $out = openai_chat($sys, "Principios crudos:\n- " . implode("\n- ", $todos), _ref_modelo(), ['max_tokens'=>900, 'max_reintentos'=>1]);
    $j = json_decode((string)($out['texto'] ?? ''), true);
    $fin = is_array($j['principios'] ?? null) ? array_values(array_filter(array_map(fn($x)=>trim((string)$x), $j['principios']))) : [];
    if (!$fin) return [];
    // Reemplaza los principios 'consolidado' (deja intactos los 'manual').
    $pdo->prepare("DELETE FROM crecer_playbook WHERE origen='consolidado'")->execute();
    $ins = $pdo->prepare("INSERT INTO crecer_playbook (principio, activo, origen) VALUES (?,1,'consolidado')");
    foreach ($fin as $p) $ins->execute([$p]);
    return $fin;
}

/** El Playbook activo como TEXTO (esto es lo ÚNICO que llega al director de V3). */
function playbook_texto(PDO $pdo): string {
    try {
        $ps = $pdo->query("SELECT principio FROM crecer_playbook WHERE activo=1 ORDER BY origen, id")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { return ''; }
    if (!$ps) return '';
    return "- " . implode("\n- ", array_map(fn($p)=>trim((string)$p), $ps));
}

/** Modelo de análisis (mismo perfil creativo; visión). */
function _ref_modelo(): string {
    return defined('IMAGE_CREATIVE_MODEL') ? IMAGE_CREATIVE_MODEL : 'openai:creative';
}
