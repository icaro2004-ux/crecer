<?php
// ============================================================
//  CRECER — PIPELINE DE IMAGEN v2  (includes/image_messenger.php)
//
//  NUEVO PARADIGMA: un SOLO cerebro creativo. Crecer aporta el CONTEXTO del
//  negocio; el modelo aporta TODA la creatividad. Nada de cadena de agentes que
//  reinterpretan: el Image Messenger solo EMPACA y TRANSPORTA la info; el Director
//  Creativo (IMAGE_CREATIVE_MODEL, p.ej. openai:gpt-4o) decide todo y devuelve la
//  descripción visual DIRECTO para gpt-image-1.
//
//  Convive con v1 (direccion_arte.php) por el flag IMAGE_PIPELINE.
// ============================================================

/**
 * Enruta al DIRECTOR CREATIVO según el modelo configurado ('openai:gpt-4o' | 'gemini' | 'openai').
 * Loguea en crecer_ia_log (evidencia del cerebro creativo). Devuelve el texto.
 */
function director_creativo_llm(PDO $pdo, int $marca_id, string $sistema, string $mensaje, string $modelo_cfg): array {
    // Perfil lógico ('openai:creative') → modelo concreto de hoy ('openai:gpt-4o').
    if (function_exists('resolver_modelo_ia')) $modelo_cfg = resolver_modelo_ia($modelo_cfg);
    $prov = 'openai'; $mdl = 'gpt-4o';
    if (strpos($modelo_cfg, ':') !== false) { $p = explode(':', $modelo_cfg, 2); $prov = $p[0]; $mdl = $p[1]; }
    else { $prov = $modelo_cfg; }
    $prov = strtolower(trim($prov)); $mdl = trim($mdl);

    $t0 = microtime(true); $texto = ''; $ti = 0; $to = 0; $usado = $modelo_cfg; $estado = 'ok'; $err = null;
    try {
        if ($prov === 'openai') {
            $r = openai_chat($sistema, $mensaje, $mdl !== '' ? $mdl : 'gpt-4o', ['temperatura' => 0.9, 'max_tokens' => 900]);
            $texto = $r['texto']; $ti = $r['tokens_in']; $to = $r['tokens_out']; $usado = 'openai:' . $r['modelo'];
        } else {
            // Gemini reusa el transporte actual (system prepend, ya que gemini_generar no separa system).
            $r = gemini_generar($sistema . "\n\n" . $mensaje, ['temperatura' => 0.9, 'max_tokens' => 900]);
            $texto = $r['texto']; $ti = (int)($r['tokens_in'] ?? 0); $to = (int)($r['tokens_out'] ?? 0); $usado = 'gemini';
        }
    } catch (Throwable $e) { $estado = 'error'; $err = substr($e->getMessage(), 0, 200); }

    try {
        $lat = (int)round((microtime(true) - $t0) * 1000);
        $pdo->prepare("INSERT INTO crecer_ia_log (marca_id,agente,accion,modelo,prompt,respuesta,tokens_in,tokens_out,costo_usd,latencia_ms,estado,error_msg)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$marca_id, 'director_creativo', 'Director Creativo v2', $usado, $mensaje, $texto, $ti, $to, null, $lat, $estado, $err]);
    } catch (Throwable $e) { /* log best-effort */ }

    if ($estado === 'error') throw new IaError($err ?: 'Director creativo falló');
    return ['texto' => $texto, 'modelo' => $usado];
}

/**
 * IMAGE MESSENGER — empaca el contexto del negocio y deja que UN solo modelo dirija.
 * Cero creatividad propia: NO decide composición, luz, cámara, estilo ni mood.
 * @return string la descripción visual lista para gpt-image-1 (o '' si falla).
 */
function image_messenger_prompt(PDO $pdo, int $marca_id, array $m, string $copy, array $opts = []): string {
    $con_texto  = !empty($opts['con_texto']);
    $instr      = trim((string)($opts['instrucciones'] ?? ''));
    $modelo_cfg = trim((string)($opts['modelo'] ?? '')) !== ''
                ? trim((string)$opts['modelo'])
                : (defined('IMAGE_CREATIVE_MODEL') ? IMAGE_CREATIVE_MODEL : 'openai:gpt-4o');

    // CONTEXTO (Crecer lo aporta; el modelo NO lo adivina).
    $ctx        = function_exists('cerebro_negocio') ? cerebro_negocio($pdo, $marca_id, $m) : '';
    $nombre     = trim((string)($m['nombre_negocio'] ?? ''));
    $colores    = trim((string)($m['estilo_visual'] ?? ''));   // línea/colores de marca si existen
    $plataforma = trim((string)($opts['plataforma'] ?? 'Instagram, Facebook y WhatsApp'));

    // EL SYSTEM PROMPT — el único cerebro creativo. Guardrails DENTRO (no en agentes aparte).
    $sistema = "Eres el Director Creativo Senior de Crecer, una agencia de publicidad especializada en pequeñas empresas. "
        . "Tu misión es crear la MEJOR imagen promocional posible para acompañar un post de redes sociales. "
        . "No describas literalmente el copy. Piensa como un Director Creativo humano: identifica la emoción, identifica el deseo, "
        . "construye una campaña. La imagen debe hacer detener el scroll, parecer una FOTOGRAFÍA COMERCIAL PROFESIONAL, y VENDER, "
        . "no explicar. Nunca generes fotos tipo stock. Nunca composiciones aburridas. Nunca macro extremos salvo que realmente sean "
        . "necesarios. Si el negocio vende variedad, transmite abundancia. Si el copy habla de familia, transmite familia; si de lujo, "
        . "lujo; si de nostalgia, nostalgia. La imagen COMPLEMENTA el copy, nunca lo repite. "
        . ($con_texto
            ? "El dueño quiere un GRÁFICO PROMOCIONAL: integra un titular corto, la marca y un CTA con tipografía profesional, bien escritos en español."
            : "No incluyas texto ni letras dentro de la imagen.")
        . " Devuelve ÚNICAMENTE la descripción visual optimizada para GPT Image (un solo párrafo denso, en inglés), sin comillas ni explicaciones.";

    // EL MENSAJE — pura información del negocio, sin dirección creativa.
    $mensaje = "CONTEXTO DEL NEGOCIO (esto lo aporta Crecer):\n"
        . ($nombre  !== '' ? "Negocio: {$nombre}\n" : '')
        . ($ctx     !== '' ? $ctx . "\n" : '')
        . ($colores !== '' ? "Colores / línea de marca: {$colores}\n" : '')
        . "Plataforma: {$plataforma}\n"
        . "\nCOPY DEL POST (que la imagen va a acompañar):\n\"{$copy}\"\n"
        . ($instr !== '' ? "\nPEDIDO ESPECÍFICO DEL DUEÑO: {$instr}\n" : '')
        . "\nCrea la imagen promocional.";

    try {
        $r = director_creativo_llm($pdo, $marca_id, $sistema, $mensaje, $modelo_cfg);
        return trim((string)($r['texto'] ?? ''));
    } catch (Throwable $e) { error_log('image_messenger: ' . $e->getMessage()); return ''; }
}
