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
    $modelo_cfg = trim((string)($opts['modelo'] ?? '')) !== ''
                ? trim((string)$opts['modelo'])
                : (defined('IMAGE_CREATIVE_MODEL') ? IMAGE_CREATIVE_MODEL : 'openai:creative');

    // Datos del negocio (Crecer los aporta; el mensajero NO interpreta ni añade nada).
    $nombre  = trim((string)($m['nombre_negocio'] ?? ''));
    $desc    = trim((string)($m['descripcion'] ?? ''));
    $publico = trim((string)($m['publico_objetivo'] ?? ''));
    $genome  = function_exists('cerebro_negocio') ? cerebro_negocio($pdo, $marca_id, $m) : '';
    $prods_raw = $m['productos'] ?? [];
    if (is_string($prods_raw)) $prods_raw = json_decode($prods_raw, true) ?: [];
    $prods = [];
    foreach ((array)$prods_raw as $p) { $n = is_array($p) ? trim((string)($p['nombre'] ?? '')) : trim((string)$p); if ($n !== '') $prods[] = $n; }
    $productos = $prods ? implode(', ', $prods) : '';

    // SYSTEM — EXACTO (el único cerebro creativo). NO se toca una sola palabra.
    $sistema = <<<SYS
Eres el Director Creativo de Crecer.

Tu trabajo consiste en crear la mejor imagen promocional posible para acompañar un post de redes sociales.

Piensa exactamente como lo hace ChatGPT cuando un usuario le pide una imagen publicitaria.

NO describas literalmente el copy.

Analiza el negocio.

Analiza el objetivo comercial.

Analiza el público.

Construye una campaña publicitaria.

No una fotografía bonita.

Una imagen que haga detener el scroll.

Debe parecer una campaña creada por una agencia de publicidad de primer nivel.

La imagen debe vender.

No explicar.

No incluir texto.

No incluir logos a menos que el usuario los haya suministrado.

Si el negocio vende variedad, transmite abundancia.

Si el negocio vende experiencias, vende la experiencia.

Si vende lujo, vende lujo.

Si vende emociones, vende emociones.

Prioriza SIEMPRE impacto comercial sobre belleza artística.

Devuelve ÚNICAMENTE una descripción visual optimizada para GPT Image.
SYS;

    // USER — plantilla EXACTA con los datos del negocio interpolados.
    $mensaje = <<<USR
NEGOCIO:
{$nombre}

DESCRIPCIÓN:
{$desc}

BUSINESS GENOME:
{$genome}

PRODUCTOS:
{$productos}

PÚBLICO:
{$publico}

COPY:
{$copy}

OBJETIVO:
Crear la mejor imagen promocional posible para Facebook e Instagram.

RESTRICCIONES:
No texto.
No collages.
No mockups.
No stock.
No diseño gráfico.
Fotografía publicitaria hiperrealista.
USR;

    // El mensajero SOLO transporta: la respuesta va DIRECTA a gpt-image-1, sin modificar.
    try {
        $r = director_creativo_llm($pdo, $marca_id, $sistema, $mensaje, $modelo_cfg);
        return trim((string)($r['texto'] ?? ''));
    } catch (Throwable $e) { error_log('image_messenger: ' . $e->getMessage()); return ''; }
}
