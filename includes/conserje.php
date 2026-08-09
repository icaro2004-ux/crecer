<?php
// ============================================================
//  CRECER — EL CONSERJE (responde los comentarios del negocio)
//  includes/conserje.php
//
//  V1: COMENTARIOS de los posts que Crecer publicó (IG + FB).
//  El corillo los lee y contesta EN LA VOZ del negocio, con una
//  compuerta de honestidad:
//    - Solo responde con hechos del perfil del negocio.
//    - Piden un dato que no tiene (precio, cita, encargo) → ESCALA
//      al dueño por notificación, no inventa.
//    - Queja o algo delicado → ESCALA SIEMPRE.
//    - Spam/trolleo → se ignora (y queda registrado).
//  Todo cae en crecer_mensajes (la tabla del día 1, que esperaba
//  esto) y cada decisión en crecer_ia_log.
//
//  Requiere scopes instagram_manage_comments + pages_manage_engagement
//  (añadidos a META_SCOPES — reconectar la cuenta una vez).
//  DMs = fase 2 (webhook de mensajería). WhatsApp = módulo aparte.
//  Prueba viva: _cache.php?test=conserje
// ============================================================

require_once __DIR__ . '/meta.php';
require_once __DIR__ . '/ia.php';
require_once __DIR__ . '/notif.php';

const CONSERJE_VENTANA_HORAS = 72;   // solo comentarios frescos (no arqueología)
const CONSERJE_MAX_POR_RONDA = 6;    // tope de acciones por marca por corrida
const CONSERJE_DIAS_POSTS    = 14;   // qué tan atrás se monitorean posts

/** Posts publicados recientes CON id externo (por red), de esta marca. */
function conserje_posts(PDO $pdo, int $marca_id, int $limite = 10): array {
    $q = $pdo->prepare(
        "SELECT p.contenido_id, p.plataforma, p.external_id, p.permalink
           FROM crecer_publicaciones p
           JOIN crecer_contenido c ON c.id = p.contenido_id AND c.estado='publicado'
          WHERE p.marca_id=? AND p.estado='ok' AND p.external_id IS NOT NULL
            AND p.id = (SELECT MAX(p2.id) FROM crecer_publicaciones p2
                        WHERE p2.contenido_id=p.contenido_id AND p2.plataforma=p.plataforma AND p2.estado='ok')
            AND c.publicado_at >= (NOW() - INTERVAL " . CONSERJE_DIAS_POSTS . " DAY)
          ORDER BY c.publicado_at DESC
          LIMIT " . max(1, (int)$limite));
    $q->execute([$marca_id]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/** Comentarios de un media (IG) o post (FB), filtrando los del propio negocio. */
function conserje_comentarios(array $conx, string $plataforma, string $external_id): array {
    $token = (string)$conx['page_access_token'];
    $out = [];
    if ($plataforma === 'instagram') {
        $r = meta_api('GET', $external_id . '/comments', [
            'fields' => 'id,text,username,timestamp,from', 'access_token' => $token,
        ]);
        foreach ($r['data'] ?? [] as $c) {
            if ((string)($c['from']['id'] ?? '') === (string)($conx['ig_user_id'] ?? '')) continue; // nuestras respuestas
            $out[] = ['id' => (string)$c['id'], 'texto' => trim((string)($c['text'] ?? '')),
                      'autor' => (string)($c['username'] ?? ''), 'ts' => strtotime((string)($c['timestamp'] ?? '')) ?: 0];
        }
    } else {
        $r = meta_api('GET', $external_id . '/comments', [
            'fields' => 'id,message,from{id,name},created_time', 'access_token' => $token,
        ]);
        foreach ($r['data'] ?? [] as $c) {
            if ((string)($c['from']['id'] ?? '') === (string)($conx['fb_page_id'] ?? '')) continue;
            $out[] = ['id' => (string)$c['id'], 'texto' => trim((string)($c['message'] ?? '')),
                      'autor' => (string)($c['from']['name'] ?? ''), 'ts' => strtotime((string)($c['created_time'] ?? '')) ?: 0];
        }
    }
    return $out;
}

/** La decisión con compuerta: responder / escalar / ignorar. Queda en crecer_ia_log. */
function conserje_decidir(PDO $pdo, array $marca, string $comentario, string $autor, string $plataforma): array {
    $negocio = trim((string)($marca['nombre_negocio'] ?? 'el negocio'));
    $voz     = trim((string)($marca['voz'] ?? ''));
    $desc    = trim((string)($marca['descripcion'] ?? ''));
    $ofertas = trim((string)($marca['ofertas'] ?? ''));
    $wa      = trim((string)($marca['whatsapp'] ?? ''));
    $via     = $wa !== '' ? 'WhatsApp' : 'mensaje directo';

    $sistema = "Eres el CONSERJE de \"{$negocio}\": respondes los comentarios de sus posts EN SU VOZ"
        . ($voz !== '' ? " (así habla: {$voz})" : '') . ".\n"
        . "REGLAS DURAS:\n"
        . "- USA SOLO los hechos del perfil. NO inventes precios, fechas, sabores, disponibilidad ni promesas.\n"
        . "- Piden un dato que NO está en el perfil (precio, cita, encargo específico) → accion \"escalar\".\n"
        . "- Queja, reclamo o tema delicado → accion \"escalar\" SIEMPRE (eso lo maneja el dueño).\n"
        . "- Spam, trolleo o solo etiquetas de otras cuentas → accion \"ignorar\".\n"
        . "- Elogio o emojis → agradece corto y cálido.\n"
        . "- Si quieren comprar u ordenar → invítalos por {$via}, sin prometer nada específico.\n"
        . "- Máximo 2 frases. Humano y cálido, en el tono del negocio. Sin hashtags.\n"
        . "Devuelve SOLO JSON válido: {\"accion\":\"responder|escalar|ignorar\",\"respuesta\":\"...\",\"porque\":\"...\"}";
    $prompt = "PERFIL DEL NEGOCIO (los únicos hechos que puedes usar):\n"
        . "- Qué es: " . ($desc !== '' ? $desc : '(sin descripción)') . "\n"
        . "- Ofertas documentadas: " . ($ofertas !== '' ? $ofertas : '(ninguna)') . "\n"
        . "- Canal para ordenar: {$via}\n\n"
        . "COMENTARIO de @{$autor} en {$plataforma}:\n\"{$comentario}\"\n\nDecide.";

    $r = ia_ejecutar($pdo, 'conserje', 'Responder comentario', $prompt, [
        'marca_id'        => (int)$marca['id'],
        'sistema'         => $sistema,
        'json'            => true,
        'temperatura'     => 0.6,
        'max_tokens'      => 300,
        'thinking_budget' => 0,
        'mock_texto'      => '{"accion":"responder","respuesta":"[MOCK] ¡Mil gracias! Aquí estamos para ti.","porque":"elogio"}',
    ]);
    $d = json_decode(trim((string)$r['texto']), true) ?: [];
    $accion = in_array($d['accion'] ?? '', ['responder','escalar','ignorar'], true) ? $d['accion'] : 'escalar';
    return ['accion' => $accion, 'respuesta' => trim((string)($d['respuesta'] ?? '')),
            'porque' => trim((string)($d['porque'] ?? '')), 'ia_log_id' => $r['ia_log_id'] ?? null];
}

/** Publica la respuesta como reply del comentario. */
function conserje_reply(array $conx, string $plataforma, string $comment_id, string $texto): array {
    $token = (string)$conx['page_access_token'];
    return $plataforma === 'instagram'
        ? meta_api('POST', $comment_id . '/replies',  ['message' => $texto, 'access_token' => $token])
        : meta_api('POST', $comment_id . '/comments', ['message' => $texto, 'access_token' => $token]);
}

/**
 * La RONDA completa de una marca. $enviar=false → decide y guarda la
 * propuesta pero NO publica (modo prueba). Devuelve resumen honesto.
 */
function conserje_correr(PDO $pdo, int $marca_id, bool $enviar = true, int $max = CONSERJE_MAX_POR_RONDA): array {
    $conx = conexion_de_marca($pdo, $marca_id);
    if (!$conx || ($conx['estado'] ?? '') !== 'activa' || empty($conx['page_access_token'])) {
        return ['ok' => false, 'motivo' => 'sin_conexion'];
    }
    $mq = $pdo->prepare("SELECT * FROM crecer_marca WHERE id=?");
    $mq->execute([$marca_id]);
    $marca = $mq->fetch(PDO::FETCH_ASSOC);
    if (!$marca) return ['ok' => false, 'motivo' => 'sin_marca'];

    $res = ['ok'=>true, 'nuevos'=>0, 'respondidos'=>0, 'escalados'=>0, 'ignorados'=>0, 'errores'=>[]];
    $limite_ts = time() - CONSERJE_VENTANA_HORAS * 3600;
    $acciones  = 0;

    foreach (conserje_posts($pdo, $marca_id) as $p) {
        try {
            $coms = conserje_comentarios($conx, (string)$p['plataforma'], (string)$p['external_id']);
        } catch (Throwable $e) {
            $res['errores'][] = "leer {$p['plataforma']} #{$p['contenido_id']}: " . substr($e->getMessage(), 0, 160);
            continue;
        }
        foreach ($coms as $c) {
            if ($c['texto'] === '' || ($c['ts'] && $c['ts'] < $limite_ts)) continue;
            $chk = $pdo->prepare("SELECT 1 FROM crecer_mensajes WHERE plataforma=? AND external_id=?");
            $chk->execute([$p['plataforma'], $c['id']]);
            if ($chk->fetchColumn()) continue;                      // ya visto
            if ($acciones >= $max) break 2;                          // tope por ronda

            $pdo->prepare("INSERT INTO crecer_mensajes (marca_id, contenido_id, plataforma, external_id, remitente, mensaje_entrante, estado)
                           VALUES (?,?,?,?,?,?, 'pendiente')")
                ->execute([$marca_id, (int)$p['contenido_id'], $p['plataforma'], $c['id'], mb_substr($c['autor'], 0, 120), $c['texto']]);
            $msg_id = (int)$pdo->lastInsertId();
            $res['nuevos']++;
            $acciones++;

            try {
                $d = conserje_decidir($pdo, $marca, $c['texto'], $c['autor'], (string)$p['plataforma']);
            } catch (Throwable $e) {
                // Si la decisión falla, se escala por seguridad — nunca respuesta a ciegas.
                $pdo->prepare("UPDATE crecer_mensajes SET estado='escalado' WHERE id=?")->execute([$msg_id]);
                notif_crear($pdo, $marca_id, 'conserje', 'Un comentario espera TU respuesta',
                    '@' . $c['autor'] . ': "' . mb_substr($c['texto'], 0, 120) . '"', $p['permalink'] ?: null, 'chat');
                $res['escalados']++;
                $res['errores'][] = "decidir: " . substr($e->getMessage(), 0, 120);
                continue;
            }

            if ($d['accion'] === 'responder' && $d['respuesta'] !== '') {
                if ($enviar) {
                    try {
                        conserje_reply($conx, (string)$p['plataforma'], $c['id'], $d['respuesta']);
                        $pdo->prepare("UPDATE crecer_mensajes SET respuesta_ia=?, ia_log_id=?, estado='respondido', respondido_at=NOW() WHERE id=?")
                            ->execute([$d['respuesta'], $d['ia_log_id'], $msg_id]);
                        $res['respondidos']++;
                    } catch (Throwable $e) {
                        // La respuesta quedó escrita pero no se pudo publicar (permiso/red):
                        // queda 'pendiente' con la propuesta y el error a la vista.
                        $pdo->prepare("UPDATE crecer_mensajes SET respuesta_ia=?, ia_log_id=? WHERE id=?")
                            ->execute([$d['respuesta'], $d['ia_log_id'], $msg_id]);
                        $res['errores'][] = "reply {$p['plataforma']}: " . substr($e->getMessage(), 0, 160);
                    }
                } else {
                    $pdo->prepare("UPDATE crecer_mensajes SET respuesta_ia=?, ia_log_id=? WHERE id=?")
                        ->execute([$d['respuesta'], $d['ia_log_id'], $msg_id]);
                    $res['respondidos']++;   // en modo prueba: "habría respondido"
                }
            } elseif ($d['accion'] === 'ignorar') {
                $pdo->prepare("UPDATE crecer_mensajes SET estado='ignorado', ia_log_id=? WHERE id=?")->execute([$d['ia_log_id'], $msg_id]);
                $res['ignorados']++;
            } else {   // escalar
                $pdo->prepare("UPDATE crecer_mensajes SET estado='escalado', ia_log_id=? WHERE id=?")->execute([$d['ia_log_id'], $msg_id]);
                notif_crear($pdo, $marca_id, 'conserje', 'Un comentario espera TU respuesta',
                    '@' . $c['autor'] . ': "' . mb_substr($c['texto'], 0, 120) . '"'
                    . ($d['porque'] !== '' ? ' — ' . $d['porque'] : ''), $p['permalink'] ?: null, 'chat');
                $res['escalados']++;
            }
        }
    }
    return $res;
}
