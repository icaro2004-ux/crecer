<?php
// ============================================================
//  CRECER — WhatsApp Cloud API (el canal donde el negocio VIVE)
//  includes/whatsapp.php
//
//  V1: UN número → UNA marca (WHATSAPP_MARCA_ID). Un cliente le
//  escribe al número del negocio → webhook_whatsapp.php recibe →
//  el Conserje decide con la MISMA compuerta de honestidad
//  (hechos del perfil o escala) → responde por la Cloud API.
//  Responder mensajes entrantes (ventana de 24h) no cuesta.
//
//  Config (config.local.php del server):
//    WHATSAPP_TOKEN        token de la Cloud API (temporal 24h para
//                          probar; permanente vía System User después)
//    WHATSAPP_PHONE_ID     Phone Number ID del número del negocio
//    WHATSAPP_VERIFY_TOKEN palabra secreta que TÚ inventas (la misma
//                          se pone al configurar el webhook en Meta)
//    WHATSAPP_MARCA_ID     marca que atiende este número (ej. 1)
//
//  Prueba viva: _cache.php?test=whatsapp
// ============================================================

require_once __DIR__ . '/ia.php';
require_once __DIR__ . '/notif.php';

function wa_configurado(): bool {
    return defined('WHATSAPP_TOKEN') && WHATSAPP_TOKEN !== ''
        && defined('WHATSAPP_PHONE_ID') && WHATSAPP_PHONE_ID !== ''
        && defined('WHATSAPP_MARCA_ID') && (int)WHATSAPP_MARCA_ID > 0;
}

/** Manda un texto por la Cloud API (dentro de la ventana de 24h del cliente). */
function wa_enviar_texto(string $telefono, string $texto): array {
    $version = defined('META_GRAPH_VERSION') ? META_GRAPH_VERSION : 'v21.0';
    $url = "https://graph.facebook.com/{$version}/" . rawurlencode(WHATSAPP_PHONE_ID) . "/messages";
    $body = json_encode([
        'messaging_product' => 'whatsapp',
        'to'                => preg_replace('/\D+/', '', $telefono),
        'type'              => 'text',
        'text'              => ['preview_url' => true, 'body' => $texto],
    ], JSON_UNESCAPED_UNICODE);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . WHATSAPP_TOKEN],
        CURLOPT_TIMEOUT        => 25,
    ]);
    $out  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($out === false) throw new RuntimeException("WhatsApp: fallo de red: {$err}");
    $j = json_decode((string)$out, true) ?: [];
    if ($code >= 400 || isset($j['error'])) {
        throw new RuntimeException('WhatsApp API: ' . substr((string)($j['error']['message'] ?? $out), 0, 300));
    }
    return $j;
}

/**
 * La CONVERSACIÓN previa con este teléfono (para que el agente tenga memoria
 * y no se repita como perico — el defecto de la primera prueba en vivo).
 */
function wa_historial(PDO $pdo, int $marca_id, string $telefono, int $limit = 8, int $excluir_id = 0): string {
    $tel = preg_replace('/\D+/', '', $telefono);
    if ($tel === '') return '';
    try {
        $q = $pdo->prepare(
            "SELECT mensaje_entrante, respuesta_ia FROM crecer_mensajes
              WHERE marca_id=? AND plataforma='whatsapp' AND remitente LIKE ? AND id<>?
              ORDER BY id DESC LIMIT " . max(1, (int)$limit));
        $q->execute([$marca_id, '%' . $tel, $excluir_id]);
        $filas = array_reverse($q->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) { return ''; }
    if (!$filas) return '';
    $out = [];
    foreach ($filas as $f) {
        $out[] = 'CLIENTE: ' . mb_substr((string)$f['mensaje_entrante'], 0, 200);
        if (!empty($f['respuesta_ia'])) $out[] = 'TÚ: ' . mb_substr((string)$f['respuesta_ia'], 0, 200);
    }
    return implode("\n", $out);
}

/**
 * La decisión para un MENSAJE DIRECTO de WhatsApp — misma compuerta que el
 * Conserje de comentarios, con el arma extra del canal: puede mandar el LINK
 * de órdenes del negocio cuando el cliente quiere ordenar.
 */
function wa_decidir(PDO $pdo, array $marca, string $mensaje, string $telefono, int $excluir_id = 0): array {
    $negocio = trim((string)($marca['nombre_negocio'] ?? 'el negocio'));
    $voz     = trim((string)($marca['voz'] ?? ''));
    $desc    = trim((string)($marca['descripcion'] ?? ''));
    $ofertas = trim((string)($marca['ofertas'] ?? ''));
    $slug    = trim((string)($marca['slug'] ?? ''));
    $link_ordenes = $slug !== ''
        ? (defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'https://encuentraloahora.com/crecer') . '/ordenar.php?n=' . rawurlencode($slug)
        : '';

    $link_cta = defined('WHATSAPP_LINK_CTA') ? trim((string)WHATSAPP_LINK_CTA) : '';
    $historial = wa_historial($pdo, (int)$marca['id'], $telefono, 8, $excluir_id);

    $sistema = "Eres quien atiende el WhatsApp de \"{$negocio}\" — contestas EN SU VOZ"
        . ($voz !== '' ? " (así habla: {$voz})" : '') . ".\n"
        . "REGLAS DURAS:\n"
        . "- CONTESTA LA PREGUNTA ESPECÍFICA del cliente. Nada de discursos genéricos de venta.\n"
        . "- NUNCA repitas lo que ya dijiste en la conversación (te la doy abajo). Si ya explicaste qué es el negocio, NO lo vuelvas a explicar — avanza.\n"
        . "- USA SOLO los hechos del perfil. NO inventes precios, fechas, sabores, disponibilidad ni promesas.\n"
        . "- Piden un dato que NO está (precio, cita, encargo a la medida) → accion \"escalar\" con una respuesta puente corta tipo \"déjame confirmarte eso y te escribo ahorita\".\n"
        . "- Queja o tema delicado → accion \"escalar\" SIEMPRE (con puente amable).\n"
        . ($link_cta !== '' ? "- Si quiere EMPEZAR, registrarse o que lo ayuden → dale el paso concreto con este link: {$link_cta}\n" : '')
        . ($link_ordenes !== '' ? "- Si quiere ORDENAR algo del perfil → incluye este link para ordenar: {$link_ordenes}\n" : '')
        . "- NO llames \"corillo\" ni apodos al cliente. Trátalo cálido y normal (o por su nombre si lo dio).\n"
        . "- Máximo 3 frases. Humano, en el tono del negocio. Sin hashtags.\n"
        . "Devuelve SOLO JSON válido: {\"accion\":\"responder|escalar\",\"respuesta\":\"...\",\"porque\":\"...\"}";
    $prompt = "PERFIL DEL NEGOCIO (los únicos hechos que puedes usar):\n"
        . "- Qué es: " . ($desc !== '' ? $desc : '(sin descripción)') . "\n"
        . "- Ofertas documentadas: " . ($ofertas !== '' ? $ofertas : '(ninguna)') . "\n"
        . ($link_cta !== '' ? "- Link para empezar/registrarse: {$link_cta}\n" : '')
        . ($link_ordenes !== '' ? "- Link para ordenar: {$link_ordenes}\n" : '')
        . ($historial !== '' ? "\nCONVERSACIÓN RECIENTE (lo ya dicho — NO te repitas):\n{$historial}\n" : '')
        . "\nMENSAJE NUEVO del cliente:\n\"{$mensaje}\"\n\nDecide y responde a LO QUE PREGUNTÓ.";

    $r = ia_ejecutar($pdo, 'conserje', 'Responder WhatsApp', $prompt, [
        'marca_id'        => (int)$marca['id'],
        'sistema'         => $sistema,
        'json'            => true,
        'temperatura'     => 0.6,
        'max_tokens'      => 350,
        'thinking_budget' => 0,
        'mock_texto'      => '{"accion":"responder","respuesta":"[MOCK] ¡Saludos! Aquí estamos para ti.","porque":"saludo"}',
    ]);
    $d = json_decode(trim((string)$r['texto']), true) ?: [];
    $accion = in_array($d['accion'] ?? '', ['responder','escalar'], true) ? $d['accion'] : 'escalar';
    return ['accion' => $accion, 'respuesta' => trim((string)($d['respuesta'] ?? '')),
            'porque' => trim((string)($d['porque'] ?? '')), 'ia_log_id' => $r['ia_log_id'] ?? null];
}

/**
 * Procesa UN mensaje entrante (lo llama el webhook). Dedupe por wamid.
 * Responde (o escala con puente) y deja todo en crecer_mensajes.
 */
function wa_procesar_entrante(PDO $pdo, string $wamid, string $telefono, string $nombre, string $texto): array {
    $marca_id = (int)WHATSAPP_MARCA_ID;
    $texto = trim($texto);
    if ($texto === '') return ['ok' => false, 'motivo' => 'vacio'];

    // Dedupe: Meta reintenta webhooks — el mismo wamid no se procesa dos veces.
    $chk = $pdo->prepare("SELECT 1 FROM crecer_mensajes WHERE plataforma='whatsapp' AND external_id=?");
    $chk->execute([$wamid]);
    if ($chk->fetchColumn()) return ['ok' => true, 'motivo' => 'duplicado'];

    $mq = $pdo->prepare("SELECT * FROM crecer_marca WHERE id=?");
    $mq->execute([$marca_id]);
    $marca = $mq->fetch(PDO::FETCH_ASSOC);
    if (!$marca) return ['ok' => false, 'motivo' => 'sin_marca'];

    $remitente = mb_substr(($nombre !== '' ? $nombre . ' · ' : '') . $telefono, 0, 120);
    $pdo->prepare("INSERT INTO crecer_mensajes (marca_id, plataforma, external_id, remitente, mensaje_entrante, estado)
                   VALUES (?, 'whatsapp', ?, ?, ?, 'pendiente')")
        ->execute([$marca_id, $wamid, $remitente, $texto]);
    $msg_id = (int)$pdo->lastInsertId();

    try {
        $d = wa_decidir($pdo, $marca, $texto, $telefono, $msg_id);
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE crecer_mensajes SET estado='escalado' WHERE id=?")->execute([$msg_id]);
        notif_crear($pdo, $marca_id, 'whatsapp', 'Un WhatsApp espera TU respuesta',
            $remitente . ': "' . mb_substr($texto, 0, 120) . '"', null, 'phone');
        return ['ok' => false, 'motivo' => 'decidir: ' . substr($e->getMessage(), 0, 120)];
    }

    $enviada = false;
    if ($d['respuesta'] !== '') {
        try { wa_enviar_texto($telefono, $d['respuesta']); $enviada = true; }
        catch (Throwable $e) { error_log('wa_enviar #' . $msg_id . ': ' . $e->getMessage()); }
    }

    if ($d['accion'] === 'responder' && $enviada) {
        $pdo->prepare("UPDATE crecer_mensajes SET respuesta_ia=?, ia_log_id=?, estado='respondido', respondido_at=NOW() WHERE id=?")
            ->execute([$d['respuesta'], $d['ia_log_id'], $msg_id]);
        return ['ok' => true, 'motivo' => 'respondido'];
    }

    // Escalado (o la respuesta puente no salió): el dueño se entera SIEMPRE.
    $pdo->prepare("UPDATE crecer_mensajes SET respuesta_ia=?, ia_log_id=?, estado='escalado' WHERE id=?")
        ->execute([$enviada ? $d['respuesta'] : null, $d['ia_log_id'], $msg_id]);
    notif_crear($pdo, $marca_id, 'whatsapp', 'Un WhatsApp espera TU respuesta',
        $remitente . ': "' . mb_substr($texto, 0, 120) . '"'
        . ($d['porque'] !== '' ? ' — ' . $d['porque'] : ''), null, 'phone');
    return ['ok' => true, 'motivo' => 'escalado'];
}
