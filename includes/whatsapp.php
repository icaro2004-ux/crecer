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
 * EL VIGÍA DEL TOKEN — un token que muere mudo deja al agente sordo sin que
 * nadie se entere (pasó el 2026-08-09: expiró a las 4:00 PM y los mensajes
 * caían al vacío). Esto pregunta a Meta por la salud del token y AVISA
 * (campanita + email al fundador) si está inválido o le quedan <7 días.
 * Lo corre el cron de métricas a diario. Aplica a CUALQUIER token, hasta al
 * "permanente" — aquí no confiamos ni en las promesas de Meta.
 */
function wa_token_vigia(PDO $pdo): array {
    if (!wa_configurado()) return ['ok' => false, 'motivo' => 'sin_config'];
    if (!defined('META_APP_ID') || !defined('META_APP_SECRET') || META_APP_ID === '' || META_APP_SECRET === '') {
        return ['ok' => false, 'motivo' => 'sin_app_secret'];
    }
    $version = defined('META_GRAPH_VERSION') ? META_GRAPH_VERSION : 'v21.0';
    $url = "https://graph.facebook.com/{$version}/debug_token?"
         . http_build_query(['input_token' => WHATSAPP_TOKEN, 'access_token' => META_APP_ID . '|' . META_APP_SECRET]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $out = curl_exec($ch);
    curl_close($ch);
    $d = json_decode((string)$out, true)['data'] ?? null;
    if (!is_array($d)) return ['ok' => false, 'motivo' => 'no_se_pudo_leer'];

    $valido  = !empty($d['is_valid']);
    $expira  = (int)($d['expires_at'] ?? 0);          // 0 = no expira
    $dias    = $expira > 0 ? (int)floor(($expira - time()) / 86400) : null;
    $alerta  = null;
    if (!$valido)                       $alerta = 'El token de WhatsApp está INVÁLIDO — el agente está sordo AHORA MISMO.';
    elseif ($dias !== null && $dias < 7) $alerta = "Al token de WhatsApp le quedan {$dias} día(s) — renuévalo antes de que el agente enmudezca.";

    if ($alerta !== null) {
        $marca_id = (int)WHATSAPP_MARCA_ID;
        notif_crear($pdo, $marca_id, 'whatsapp', 'ATENCIÓN: el token de WhatsApp', $alerta,
            '/crecer/panel/whatsapp.php?marca=' . $marca_id, 'bolt');
        if (function_exists('crecer_enviar_email') && defined('CRECER_FUNDADOR_EMAIL') && CRECER_FUNDADOR_EMAIL !== '') {
            try {
                crecer_enviar_email(CRECER_FUNDADOR_EMAIL, 'Crecer · el token de WhatsApp necesita acción',
                    "<p>{$alerta}</p><p>Se renueva en developers.facebook.com y se pega en config.local.php (WHATSAPP_TOKEN).</p>");
            } catch (Throwable $e) { error_log('wa_token_vigia email: ' . $e->getMessage()); }
        }
    }
    return ['ok' => $valido, 'expira_en_dias' => $dias, 'alerta' => $alerta];
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
        if ((string)$f['mensaje_entrante'] !== '') $out[] = 'CLIENTE: ' . mb_substr((string)$f['mensaje_entrante'], 0, 200);
        if (!empty($f['respuesta_ia'])) $out[] = 'TÚ: ' . mb_substr((string)$f['respuesta_ia'], 0, 200);
    }
    return implode("\n", $out);
}

/**
 * Idioma del mensaje del cliente: 'en' o 'es'. DETERMINISTA a propósito.
 *
 * Pedirle al modelo que "conteste en el idioma del cliente" no basta: el prompt
 * lleva el historial del hilo, y si los ocho turnos anteriores están en español
 * el modelo sigue en español aunque el cliente acabe de escribir en inglés. La
 * voz del negocio, que también va en español, empuja para el mismo lado.
 * Aquí se decide fuera del modelo y se le dice explícito.
 *
 * Devuelve 'en', 'es', o NULL cuando el mensaje no da señal ("ok", "yes", un
 * emoji). En ese caso manda el hilo, no un valor por defecto.
 */
function wa_idioma(string $texto): ?string {
    // Puntuación fuera: "thanks!" tiene que contar igual que "thanks".
    $t = mb_strtolower(trim($texto), 'UTF-8');
    $t = ' ' . trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t)) . ' ';
    if (trim($t) === '') return null;   // solo emoji o signos: sin señal, manda el hilo

    // Las palabras función mandan, NO los acentos: "do you deliver to Bayamón?"
    // es inglés con un nombre de pueblo dentro.
    //
    // SOLO palabras función. Nada de vocabulario de negocio: aquí había
    // "delivery", "order", "price" y "open", y con eso "Uds hacen delivery a
    // Bayamon?" salía inglés — en Puerto Rico esos préstamos se hablan en
    // español todos los días. Lo que delata el idioma es el andamiaje de la
    // frase, no los sustantivos.
    $pal_es = ['el','la','los','las','un','una','unos','unas','de','del','al','que','por','para','con',
               'como','donde','cuando','cuanto','cuanta','tienen','tienes','tiene','hacen','hace',
               'quiero','quisiera','necesito','hola','gracias','buenas','buenos','dias','tardes','noches',
               'me','mi','su','sus','les','le','lo','es','son','esta','estan','ustedes','uds','usted',
               'y','o','pero','si','no','hay','puedo','puedes','pueden','favor','muy','mas','tambien'];
    $pal_en = ['the','an','of','that','this','for','with','how','where','when','what','which','do','does',
               'did','you','your','i','my','is','are','was','were','and','or','but','if','have','has','had',
               'can','could','would','should','hi','hello','hey','thanks','thank','please','much','many',
               'there','they','we','it','to','from','about','any','some','need','want'];
    $n_es = 0; $n_en = 0;
    foreach ($pal_es as $w) if (strpos($t, ' ' . $w . ' ') !== false) $n_es++;
    foreach ($pal_en as $w) if (strpos($t, ' ' . $w . ' ') !== false) $n_en++;

    if ($n_en !== $n_es) return $n_en > $n_es ? 'en' : 'es';

    // Un acento o un signo de apertura, sin nada en contra, alcanza.
    if (preg_match('/[áéíóúñ¿¡]/u', mb_strtolower($texto, 'UTF-8'))) return 'es';

    // SIN SEÑAL. Aquí caen "yes", "ok", "sure", "gracias?", "👍" — que es la
    // mitad de lo que se escribe en un chat. Antes esto devolvía 'es' y por eso
    // la conversación arrancaba en inglés y se caía al español en el segundo
    // mensaje. No se adivina: decide el hilo (ver wa_idioma_hilo).
    return null;
}

/**
 * El idioma del HILO: se miran los mensajes que ESTE cliente ha escrito, del
 * más nuevo al más viejo, y manda el primero que dé señal.
 *
 * El idioma es una propiedad de la conversación, no de cada mensaje suelto. Si
 * alguien abrió en inglés y ahora escribe "ok", sigue siendo una conversación
 * en inglés. Solo se miran los mensajes DEL CLIENTE: si se miraran también las
 * respuestas del agente, un error se realimentaría solo.
 */
function wa_idioma_hilo(PDO $pdo, int $marca_id, string $telefono, int $excluir_id = 0, int $limit = 10): ?string {
    $tel = preg_replace('/\D+/', '', $telefono);
    if ($tel === '') return null;
    try {
        $q = $pdo->prepare(
            "SELECT mensaje_entrante FROM crecer_mensajes
              WHERE marca_id=? AND plataforma='whatsapp' AND remitente LIKE ? AND id<>?
                AND mensaje_entrante IS NOT NULL AND mensaje_entrante <> ''
              ORDER BY id DESC LIMIT " . max(1, (int)$limit));
        $q->execute([$marca_id, '%' . $tel, $excluir_id]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $texto) {
            $l = wa_idioma((string)$texto);
            if ($l !== null) return $l;          // el más reciente con señal manda
        }
    } catch (Throwable $e) { /* sin hilo, se cae al idioma del negocio */ }
    return null;
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
    // Fuera del modelo, y en cascada: lo que dice ESTE mensaje → lo que venía
    // hablando el hilo → el idioma del negocio. Sin el paso del medio, un "ok"
    // tumbaba al español una conversación que iba en inglés.
    $idioma = wa_idioma($mensaje)
           ?? wa_idioma_hilo($pdo, (int)$marca['id'], $telefono, $excluir_id)
           ?? 'es';

    $sistema = "Eres quien atiende el WhatsApp de \"{$negocio}\" — contestas EN SU VOZ"
        . ($voz !== '' ? " (así habla: {$voz})" : '') . ".\n"
        . "REGLAS DURAS:\n"
        // El idioma lo pone el CLIENTE, no nosotros, y no se deja a criterio del
        // modelo: viene decidido por wa_idioma() y se le ordena. El historial del
        // hilo (todo en español) lo arrastraba a contestar en español aunque el
        // cliente escribiera en inglés. Cambia la lengua, no quién habla.
        . ($idioma === 'en'
            ? "- IDIOMA: el cliente te escribió EN INGLÉS. Contesta ENTERAMENTE EN INGLÉS, "
              . "aunque la conversación anterior esté en español y aunque la voz del negocio "
              . "esté descrita en español. Mismo tono y misma calidez, en inglés.\n"
            : "- IDIOMA: contesta en español boricua natural, en la voz del negocio.\n")
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
        // La orden de idioma se repite pegada al mensaje nuevo: lo último que lee
        // el modelo pesa más que lo que quedó arriba, sobre todo con historial.
        . "\nMENSAJE NUEVO del cliente:\n\"{$mensaje}\"\n\n"
        . ($idioma === 'en'
            ? "Este mensaje está EN INGLÉS. Tu \"respuesta\" tiene que ir EN INGLÉS.\n"
            : "Este mensaje está en español. Tu \"respuesta\" va en español boricua.\n")
        . "Decide y responde a LO QUE PREGUNTÓ.";

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
            $remitente . ': "' . mb_substr($texto, 0, 120) . '"',
            '/crecer/panel/whatsapp.php?marca=' . $marca_id . '&tel=' . preg_replace('/\D+/', '', $telefono), 'phone');
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
        . ($d['porque'] !== '' ? ' — ' . $d['porque'] : ''),
        '/crecer/panel/whatsapp.php?marca=' . $marca_id . '&tel=' . preg_replace('/\D+/', '', $telefono), 'phone');
    return ['ok' => true, 'motivo' => 'escalado'];
}
