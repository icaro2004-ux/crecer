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
function director_creativo_llm(PDO $pdo, int $marca_id, string $sistema, string $mensaje, string $modelo_cfg, array $opts = []): array {
    $strict = !empty($opts['strict']);   // strict = SIN fallback (v3_async: falla en vez de cambiar de modelo)
    // Perfil lógico ('openai:creative') → modelo concreto de hoy.
    if (function_exists('resolver_modelo_ia')) $modelo_cfg = resolver_modelo_ia($modelo_cfg);
    $prov = 'openai'; $mdl = 'gpt-4o';
    if (strpos($modelo_cfg, ':') !== false) { $p = explode(':', $modelo_cfg, 2); $prov = $p[0]; $mdl = $p[1]; }
    else { $prov = $modelo_cfg; }
    $prov = strtolower(trim($prov)); $mdl = trim($mdl);

    $t0 = microtime(true); $texto = ''; $ti = 0; $to = 0; $usado = $modelo_cfg; $estado = 'ok'; $err = null; $fallback = false; $http = null;
    try {
        if ($prov === 'openai') {
            $mdl = $mdl !== '' ? $mdl : 'gpt-4o';
            try {
                $r = openai_chat($sistema, $mensaje, $mdl, ['max_tokens' => 700, 'max_reintentos' => 0]);
            } catch (Throwable $e1) {
                if ($strict) throw $e1;   // STRICT: NO fallback → propaga el error (se marca failed)
                error_log('openai_chat falló con ' . $mdl . ': ' . $e1->getMessage() . ' → respaldo gpt-4o');
                if ($mdl === 'gpt-4o') throw $e1;
                $r = openai_chat($sistema, $mensaje, 'gpt-4o', ['max_tokens' => 700, 'max_reintentos' => 1]);
                $mdl = 'gpt-4o(respaldo)'; $fallback = true;
            }
            $texto = $r['texto']; $ti = $r['tokens_in']; $to = $r['tokens_out']; $usado = 'openai:' . $mdl;
        } else {
            $r = gemini_generar($sistema . "\n\n" . $mensaje, ['temperatura' => 0.9, 'max_tokens' => 900]);
            $texto = $r['texto']; $ti = (int)($r['tokens_in'] ?? 0); $to = (int)($r['tokens_out'] ?? 0); $usado = 'gemini';
        }
    } catch (Throwable $e) {
        $estado = 'error'; $err = substr($e->getMessage(), 0, 300);
        if (preg_match('/HTTP (\d{3})/', $err, $mm)) $http = (int)$mm[1];
    }

    $lat = (int)round((microtime(true) - $t0) * 1000);
    try {
        $pdo->prepare("INSERT INTO crecer_ia_log (marca_id,agente,accion,modelo,prompt,respuesta,tokens_in,tokens_out,costo_usd,latencia_ms,estado,error_msg)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$marca_id, 'director_creativo', 'Director Creativo v2', $usado, $mensaje, $texto, $ti, $to, null, $lat, $estado, $err]);
    } catch (Throwable $e) { /* log best-effort */ }

    if ($estado === 'error') throw new IaError($err ?: 'Director creativo falló');
    return ['texto' => $texto, 'modelo' => $usado, 'fallback' => $fallback, 'dur_ms' => $lat, 'http' => $http];
}

/**
 * Arma el SYSTEM + USER de V3 (los mismos que usa image_messenger_prompt). Separado
 * para que el worker async pueda llamar al director directamente y capturar todo.
 * @return array{sistema:string, mensaje:string}
 */
function image_messenger_build(PDO $pdo, int $marca_id, array $m, string $copy): array {
    $nombre  = trim((string)($m['nombre_negocio'] ?? ''));
    $desc    = trim((string)($m['descripcion'] ?? ''));
    $publico = trim((string)($m['publico_objetivo'] ?? ''));
    $genome  = function_exists('cerebro_negocio') ? cerebro_negocio($pdo, $marca_id, $m) : '';
    $prods_raw = $m['productos'] ?? [];
    if (is_string($prods_raw)) $prods_raw = json_decode($prods_raw, true) ?: [];
    $prods = [];
    foreach ((array)$prods_raw as $p) { $n = is_array($p) ? trim((string)($p['nombre'] ?? '')) : trim((string)$p); if ($n !== '') $prods[] = $n; }
    $productos = $prods ? implode(', ', $prods) : '';
    $personalidad = function_exists('radiografia_capitulo') ? trim((string)radiografia_capitulo($pdo, $marca_id, 'personalidad')) : '';
    if ($personalidad === '') $personalidad = trim((string)($m['voz'] ?? ''));
    $objetivo = 'Crear la mejor imagen publicitaria posible para acompañar este post en Facebook e Instagram: detener el scroll y generar deseo de compra.';

    $sistema = <<<SYS
Eres el Director Creativo de una de las mejores agencias de publicidad del mundo.

NO eres fotógrafo.

NO eres prompt engineer.

NO eres diseñador técnico.

Piensas como un creativo publicitario cuyo único objetivo es hacer que una persona deje de hacer scroll y quiera comprar.

Tu trabajo NO es mostrar el producto.

Tu trabajo es vender una experiencia.

Antes de escribir la descripción pregúntate:

"¿Qué concepto visual haría que esta campaña pudiera ganar un premio de publicidad y al mismo tiempo aumentara las ventas?"

Nunca describas una fotografía de catálogo.

Nunca centres la escena en un producto aislado.

Nunca hagas una imagen donde simplemente aparezca el producto sobre una mesa.

Siempre crea una escena con una idea.

Debe existir una historia.

Debe existir interacción.

Debe existir emoción.

Debe existir vida.

Debe sentirse espontánea.

Debe parecer una campaña publicitaria real, no una foto de stock.

El producto debe formar parte de una experiencia memorable, no ser el protagonista absoluto.

Si mostrar únicamente el producto produce una imagen aburrida, cambia completamente el enfoque visual.

Sorpréndeme.

No expliques tus decisiones.

No uses listas.

No uses formato JSON.

Devuelve únicamente una descripción narrativa de la escena publicitaria ideal.
SYS;

    $mensaje = <<<USR
NEGOCIO

{$nombre}

DESCRIPCIÓN

{$desc}

PRODUCTOS

{$productos}

PERSONALIDAD

{$personalidad}

AUDIENCIA

{$publico}

OBJETIVO

{$objetivo}

COPY DEL POST

{$copy}

GENOMA DEL NEGOCIO

{$genome}
USR;

    // ANTI-SLOP (2026-08-12) — este cerebro creativo también necesita MEMORIA.
    // Es libre de inventar la escena, pero no de repetir la que ya hizo: se le
    // asigna una aproximación que no haya usado y se le enseña su propio historial.
    require_once __DIR__ . '/variedad_visual.php';
    try {
        $lente  = variedad_lente_asignado($pdo, $marca_id);
        $evitar = variedad_evitar_txt($pdo, $marca_id, 6);
        $mensaje .= "\n\nAPROXIMACIÓN VISUAL ASIGNADA PARA ESTA PIEZA\n\n«{$lente['nombre']}»\n{$lente['mandato']}\n\n"
                  . "Eres libre en TODO lo demás — el concepto, la historia, la emoción — pero el sujeto y el "
                  . "encuadre salen de esta aproximación. Es lo que garantiza que este negocio no termine con "
                  . "diez piezas que parecen la misma.";
        if (trim($evitar) !== '') $mensaje .= "\n\n" . $evitar;
        // Huella al ASIGNAR (no al terminar): dos piezas seguidas nunca comparten lente.
        variedad_registrar($pdo, $marca_id, (string)$lente['clave'], [
            'primary_subject' => $lente['nombre'],
            'composition'     => mb_substr(trim($copy), 0, 90),
        ], null);
    } catch (Throwable $e) { error_log('messenger variedad: ' . $e->getMessage()); }

    // ESTÁNDAR DE CALIDAD (Creative Playbook del laboratorio) — orienta el NIVEL, NO la
    // escena. Lo ÚNICO del laboratorio que llega aquí es este texto (nunca las imágenes).
    require_once __DIR__ . '/ref_lab.php';
    $pb = function_exists('playbook_texto') ? playbook_texto($pdo) : '';
    if (trim($pb) !== '') {
        $sistema .= "\n\nESTÁNDAR DE CALIDAD DE CRECER (principios generales — orientan el NIVEL de calidad de la pieza, "
                 . "NO deciden la escena; tú sigues creando el concepto único para ESTE negocio):\n" . $pb;
    }

    return ['sistema' => $sistema, 'mensaje' => $mensaje];
}

/**
 * IMAGE MESSENGER — empaca el contexto del negocio y deja que UN solo modelo dirija.
 * Cero creatividad propia: NO decide composición, luz, cámara, estilo ni mood.
 * @return string la descripción visual lista para gpt-image-1 (o '' si falla).
 */
function image_messenger_prompt(PDO $pdo, int $marca_id, array $m, string $copy, array $opts = []): string {
    $modelo_cfg = trim((string)($opts['modelo'] ?? '')) !== ''
                ? trim((string)$opts['modelo'])
                : (defined('IMAGE_CREATIVE_MODEL') ? IMAGE_CREATIVE_MODEL : 'openai:creative');
    $b = image_messenger_build($pdo, $marca_id, $m, $copy);
    // El mensajero SOLO transporta: la respuesta va DIRECTA a gpt-image-1, sin modificar.
    try {
        $r = director_creativo_llm($pdo, $marca_id, $b['sistema'], $b['mensaje'], $modelo_cfg, $opts);
        return trim((string)($r['texto'] ?? ''));
    } catch (Throwable $e) { error_log('image_messenger: ' . $e->getMessage()); return ''; }
}
