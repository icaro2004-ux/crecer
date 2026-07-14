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
require_once __DIR__ . '/memoria.php';   // El Cerebro del Negocio (RAG + escritura)

if (!defined('CRECER_COPILOTO_HORA'))       define('CRECER_COPILOTO_HORA', 3);         // mensajes por negocio / hora con plan
if (!defined('CRECER_COPILOTO_DIA'))        define('CRECER_COPILOTO_DIA', 8);         // mensajes por negocio / dia con plan
if (!defined('CRECER_COPILOTO_FREE_DIA'))   define('CRECER_COPILOTO_FREE_DIA', 3);    // mensajes por negocio / dia sin plan
if (!defined('CRECER_COPILOTO_GLOBAL_DIA')) define('CRECER_COPILOTO_GLOBAL_DIA', 80); // fusible de todo Crecer / dia
if (!defined('CRECER_COPILOTO_MODEL'))      define('CRECER_COPILOTO_MODEL', 'gemini-2.5-flash-lite');

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
 * Freno de costo para el Copiloto. No requiere migracion: usa crecer_ia_log,
 * que ya registra cada llamada. Se chequea ANTES de llamar al modelo.
 */
function copiloto_limite_uso(PDO $pdo, int $marca_id): array {
    $agentes = "('asistente','estratega')";
    try {
        $limite_dia = CRECER_COPILOTO_FREE_DIA;
        $limite_hora = min(CRECER_COPILOTO_HORA, CRECER_COPILOTO_FREE_DIA);
        try {
            $s = $pdo->prepare(
                "SELECT estado, periodo_fin
                 FROM crecer_suscripciones
                 WHERE marca_id=?
                 ORDER BY id DESC LIMIT 1"
            );
            $s->execute([$marca_id]);
            if ($su = $s->fetch(PDO::FETCH_ASSOC)) {
                $activa = in_array((string)$su['estado'], ['trial','activa'], true)
                    || ((string)$su['estado'] === 'cancelada' && !empty($su['periodo_fin']) && $su['periodo_fin'] >= date('Y-m-d'));
                if ($activa) {
                    $limite_dia = CRECER_COPILOTO_DIA;
                    $limite_hora = CRECER_COPILOTO_HORA;
                }
            }
        } catch (Throwable $e) {}

        $hora = $pdo->prepare(
            "SELECT COUNT(*) FROM crecer_ia_log
             WHERE marca_id=? AND agente IN {$agentes}
               AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        $hora->execute([$marca_id]);
        if ((int)$hora->fetchColumn() >= $limite_hora) {
            return ['ok' => false, 'err' => 'Usaste bastante el Copiloto en esta hora. Dale unos minutos y volvemos a meterle mano.'];
        }

        $dia = $pdo->prepare(
            "SELECT COUNT(*) FROM crecer_ia_log
             WHERE marca_id=? AND agente IN {$agentes}
               AND created_at >= CURDATE()"
        );
        $dia->execute([$marca_id]);
        if ((int)$dia->fetchColumn() >= $limite_dia) {
            return ['ok' => false, 'err' => 'Llegaste al limite diario del Copiloto. Manana se activa otra vez.'];
        }

        $global = (int)$pdo->query(
            "SELECT COUNT(*) FROM crecer_ia_log
             WHERE agente IN {$agentes}
               AND created_at >= CURDATE()"
        )->fetchColumn();
        if ($global >= CRECER_COPILOTO_GLOBAL_DIA) {
            return ['ok' => false, 'err' => 'El Copiloto alcanzo el limite general de hoy. Esto protege el costo del sistema.'];
        }
    } catch (Throwable $e) {
        error_log('copiloto_limite_uso: ' . $e->getMessage());
    }
    return ['ok' => true, 'err' => ''];
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
/**
 * Instrucción para que el agente de INTAKE elija el TONO de voz inicial
 * (4 ejes 0–100) según el TIPO de negocio. Es lo que hace que el POST DE
 * MUESTRA salga en el tono correcto desde la primera vez — sin que el dueño
 * toque un solo slider. El dueño lo puede afinar luego en Mi marca.
 */
function tono_prompt_intake(): string {
    return "- tono_boricua: 0-100, qué tan boricua suena (0=español neutro/profesional, 100=bien de la isla).\n"
         . "- tono_formal: 0-100, formalidad (0=casual y relajado, 100=formal y profesional).\n"
         . "- tono_venta: 0-100, energía de venta (0=informativo, 100=vendedor con llamado fuerte).\n"
         . "- tono_ingenio: 0-100, humor (0=sobrio y serio, 100=jocoso).\n"
         . "ESOS 4 NÚMEROS los eliges TÚ según el TIPO de negocio, aunque el dueño no lo diga — es CRÍTICO:\n"
         . "  · Servicios serios/profesionales (psicología, terapia, salud, médico, legal, finanzas, "
         . "consultoría, dental, óptica): tono_boricua ~25, tono_formal ~82, tono_venta ~45, tono_ingenio ~12.\n"
         . "  · Comida, repostería, belleza, barbería, retail, fiestas, food trucks: tono_boricua ~80, "
         . "tono_formal ~30, tono_venta ~60, tono_ingenio ~58.\n"
         . "  · Casos intermedios (fitness, fotografía, eventos): usa criterio, algo en el medio.\n"
         . "  · Un negocio serio NUNCA debe sonar 'wepa mi gente'. SIEMPRE devuelve los 4 números.\n";
}

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

    // TONO INICIAL sugerido por la IA según el tipo de negocio (un centro de
    // psicología arranca formal; una repostería, boricua). Así el POST DE
    // MUESTRA ya sale en el tono correcto — es la única bala para vender.
    // A prueba de migración: si las columnas de tono no existen aún, se ignora.
    if (isset($d['tono_boricua'], $d['tono_formal'], $d['tono_venta'], $d['tono_ingenio'])) {
        $c = fn($x) => max(0, min(100, (int)$x));
        try {
            $pdo->prepare("UPDATE crecer_marca SET tono_boricua=?, tono_formal=?, tono_venta=?, tono_ingenio=? WHERE id=?")
                ->execute([$c($d['tono_boricua']), $c($d['tono_formal']), $c($d['tono_venta']), $c($d['tono_ingenio']), $id]);
        } catch (Throwable $e) { /* columnas de tono aún no migradas: usa el default */ }
    }
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
        . tono_prompt_intake()
        . ($nombre_negocio !== '' ? "El negocio se llama: {$nombre_negocio}.\n" : "")
        . "Si algo no lo menciona, deja \"\" o lista vacía. NO inventes (salvo el tono, que SÍ debes elegir).";

    $r = ia_ejecutar($pdo, 'intake', 'Extraer perfil desde voz', $prompt, [
        'marca_id'        => $marca_id,
        'sistema'         => $sistema,
        'json'            => true,
        'thinking_budget' => 0,
        'temperatura'     => 0.4,
        'max_tokens'      => 900,
        'audio'           => ['data' => $audio_b64, 'mime' => $audio_mime],
        'mock_texto'      => '{"descripcion":"[MOCK] negocio boricua de comida","voz":"cercano y alegre, usa nene/nena","productos":["bizcocho","quesitos"],"publico_objetivo":"familias del pueblo","ofertas":"","instagram":"","whatsapp":"","tono_boricua":80,"tono_formal":30,"tono_venta":60,"tono_ingenio":55}',
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
        . tono_prompt_intake()
        . ($nombre_negocio !== '' ? "El negocio se llama: {$nombre_negocio}.\n" : "")
        . "Si algo no lo menciona, deja \"\" o lista vacía. NO inventes (salvo el tono, que SÍ debes elegir).\n\n"
        . "LO QUE ESCRIBIÓ:\n{$texto}";

    $r = ia_ejecutar($pdo, 'intake', 'Extraer perfil desde texto', $prompt, [
        'marca_id'        => $marca_id,
        'sistema'         => $sistema,
        'json'            => true,
        'thinking_budget' => 0,
        'temperatura'     => 0.4,
        'max_tokens'      => 900,
        'mock_texto'      => '{"descripcion":"[MOCK] negocio boricua de comida","voz":"cercano y alegre","productos":["bizcocho","quesitos"],"publico_objetivo":"familias del pueblo","ofertas":"","instagram":"","whatsapp":"","tono_boricua":80,"tono_formal":30,"tono_venta":60,"tono_ingenio":55}',
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
/**
 * DIRECTOR DE ARTE — lee el caption + el negocio + el tono y propone, en
 * español sencillo, QUÉ debe mostrar la imagen para que PEGUE con el post.
 * El dueño lee y aprueba la idea (no escribe prompts). Se le pasa luego a
 * generar_grafica como 'instrucciones' → el arte coincide con lo prometido.
 * Es una llamada de TEXTO (barata): sugerir ideas no quema generaciones de imagen.
 */
function sugerir_arte(PDO $pdo, int $marca_id, string $caption, string $ajuste = '', string $evitar = ''): string {
    $m = leer_marca($pdo, $marca_id);
    $ctx = marca_contexto($m);
    $sistema = "Eres el DIRECTOR DE ARTE de Crecer. Tomas lo que el dueño quiere y lo conviertes "
        . "en una idea visual CONCRETA y VÍVIDA para la imagen del post — como la dirigiría un "
        . "fotógrafo profesional. El dueño (que no sabe de diseño) la lee y la aprueba.\n"
        . "Devuelve 2-3 frases en español sencillo (sin jerga, sin la palabra \"prompt\") que "
        . "describan con DETALLE: (1) el sujeto principal, (2) el entorno y los props concretos, "
        . "(3) el ángulo/encuadre, (4) la luz y el ambiente, (5) la paleta de color. "
        . "Sé ESPECÍFICO, no genérico: nombra cosas concretas (ej. \"sobre tabla de madera rústica "
        . "con harina espolvoreada y un paño de lino al lado\", NO \"en una mesa\"). Realista, "
        . "apetitoso, premium. Sin texto dentro de la imagen salvo que ayude; deja aire arriba por "
        . "si va texto encima.\n\n"
        . "SÉ CREATIVO Y SORPRENDE — no repitas el mismo tipo de escena post tras post. "
        . "No caigas POR DEFECTO en el cliché gastado de teléfono / tablet / laptop con redes sociales o apps, "
        . "'persona en un escritorio con café', pantallas o notificaciones flotantes — se ve genérico y aburre. "
        . "Si el post lo pide de verdad, úsalo; si no, busca algo más fresco.\n"
        . "Escoge UNA dirección visual según lo que pida ESTE post, y rótala entre estas: "
        . "(a) el producto o servicio EN ACCIÓN / primer plano; (b) las MANOS del dueño creando o trabajando; "
        . "(c) el LOCAL o el ambiente real del negocio; (d) un CLIENTE disfrutando el resultado; "
        . "(e) los INGREDIENTES o materiales crudos; (f) la CALLE, la plaza o el pueblo boricua; "
        . "(g) un MOMENTO de vida cotidiana; (h) un CONCEPTO gráfico audaz y colorido. "
        . "Si el negocio es un SERVICIO (sin producto físico), muestra el RESULTADO real, la gente o el "
        . "impacto — nunca un dispositivo con pantalla como muleta.";
    if (function_exists('tono_instruccion')) $sistema .= tono_instruccion($m);
    $prompt = "Perfil del negocio:\n{$ctx}\n\nTexto del post (la imagen TIENE que pegar con esto):\n\"{$caption}\"\n";
    if (trim($ajuste) !== '') $prompt .= "\nLO QUE PIDE EL DUEÑO (es lo más importante — EXPÁNDELO con detalle visual, no lo ignores): {$ajuste}\n";
    if (trim($evitar) !== '') {
        $prompt .= "\nEL DUEÑO YA VIO esta idea y pidió OTRA — dale algo CATEGÓRICAMENTE DISTINTO: "
                 . "cambia el TIPO de escena, el ángulo, el lugar y los props por completo. NO la reconfigures "
                 . "ni la parafrasees; invéntate algo fresco y diferente de esto:\n\"" . mb_substr(trim($evitar), 0, 400) . "\"\n";
    }
    $prompt .= "\nDescribe en 2-3 frases, con detalle concreto, qué va a mostrar la imagen.";
    $r = ia_ejecutar($pdo, 'diseñador', 'Sugerir idea de arte', $prompt, [
        'marca_id' => $marca_id, 'sistema' => $sistema,
        'temperatura' => (trim($evitar) !== '' ? 1.15 : 0.9), 'max_tokens' => 300, 'thinking_budget' => 0,
        'mock_texto' => 'Un bizcocho de guayaba en primer plano sobre una mesa de madera, con luz cálida de tarde y un fondo simple; se ve fresco y apetitoso, con espacio arriba por si le quieres poner texto.',
    ]);
    return trim((string)$r['texto']);
}

/**
 * Lluvia de ideas de POST para que el dueño elija (el "sugiéreme temas").
 * Ideas ESPECÍFICAS de este negocio, con la voz de la marca y la memoria del
 * Cerebro (RAG), evitando repetir lo reciente. Devuelve [['tema','idea'], ...].
 */
function sugerir_temas(PDO $pdo, int $marca_id, int $n = 5): array {
    $m = leer_marca($pdo, $marca_id);
    $ctx = marca_contexto($m);
    // Lo reciente, para no repetir.
    $rec = $pdo->prepare("SELECT caption FROM crecer_contenido WHERE marca_id=? AND caption<>'' ORDER BY id DESC LIMIT 8");
    $rec->execute([$marca_id]);
    $recientes = array_values(array_filter(array_map(
        fn($c) => trim(mb_substr((string)$c, 0, 80)), $rec->fetchAll(PDO::FETCH_COLUMN))));

    $sistema = "Eres el ESTRATEGA de contenido de Crecer para microempresas boricuas. "
        . "Propones ideas de post ESPECÍFICAS y con gancho para que el dueño elija — un "
        . "brainstorm de agencia, enfocado 100% en ESTE negocio. Varía los pilares "
        . "(producto, proceso/detrás de cámara, prueba social, tip, promo, temporada, "
        . "pregunta). Nada genérico. Responde SOLO JSON válido.";
    if (function_exists('tono_instruccion'))  $sistema .= tono_instruccion($m);
    if (function_exists('memoria_para_prompt')) $sistema .= memoria_para_prompt($pdo, $marca_id);

    $prompt = "Perfil del negocio:\n{$ctx}\n";
    if ($recientes) $prompt .= "\nYA tiene esto (NO lo repitas, propón cosas distintas):\n- " . implode("\n- ", $recientes) . "\n";
    $prompt .= "\nPropón {$n} ideas de post. Devuelve JSON EXACTO:\n"
        . '{"ideas":[{"pilar":"producto","tema":"2-4 palabras","idea":"1 oración específica con el gancho"}]}';

    $r = ia_ejecutar($pdo, 'planificador', 'Sugerir temas de post', $prompt, [
        'marca_id' => $marca_id, 'sistema' => $sistema, 'json' => true,
        'temperatura' => 0.95, 'max_tokens' => 1200, 'thinking_budget' => 0,
        'mock_texto' => '{"ideas":[{"pilar":"producto","tema":"Bizcocho de guayaba","idea":"Primer plano del bizcocho recién cortado mostrando el relleno, con CTA por WhatsApp."},{"pilar":"prueba_social","tema":"Clienta feliz","idea":"Reseña real de una clienta que ordenó para un cumpleaños en Bayamón."},{"pilar":"proceso","tema":"Detrás de cámara","idea":"Video corto batiendo la mezcla a las 5am, para mostrar que todo es fresco."}]}',
    ]);
    $d = json_decode((string)$r['texto'], true);
    $out = [];
    foreach (($d['ideas'] ?? []) as $it) {
        $tema = trim((string)($it['tema'] ?? ''));
        $idea = trim((string)($it['idea'] ?? ''));
        $pilar = trim((string)($it['pilar'] ?? ''));
        if ($tema !== '' || $idea !== '') $out[] = ['tema' => $tema, 'idea' => $idea, 'pilar' => $pilar];
    }
    return $out;
}

/**
 * Propone la LÍNEA DE DISEÑO (estilo visual) de la marca: el look consistente que
 * tendrán TODAS sus imágenes. Se guarda en crecer_marca.estilo_visual y se inyecta
 * en cada generación. Devuelve 2-3 frases editables por el dueño.
 */
function sugerir_estilo_visual(PDO $pdo, int $marca_id, string $ajuste = ''): string {
    $m = leer_marca($pdo, $marca_id);
    $ctx = marca_contexto($m);
    $sistema = "Eres el DIRECTOR DE ARTE de Crecer. Defines la LÍNEA DE DISEÑO (estilo visual) "
        . "de una marca boricua: el look CONSISTENTE que tendrán TODAS sus imágenes de redes. "
        . "Devuelve 2-3 frases en español sencillo (sin jerga, sin la palabra \"prompt\") que "
        . "describan: paleta de colores, mood/vibra, tipo de fotografía o ilustración, fondos y "
        . "detalles de estilo. Concreto y específico de ESTE negocio, para que el feed se vea "
        . "cohesivo y profesional.";
    if (function_exists('tono_instruccion')) $sistema .= tono_instruccion($m);
    $prompt = "Perfil del negocio:\n{$ctx}\n";
    if (trim($ajuste) !== '') $prompt .= "\nEl dueño pide este ajuste — priorízalo: {$ajuste}\n";
    $prompt .= "\nDescribe la línea de diseño visual de la marca en 2-3 frases.";
    $r = ia_ejecutar($pdo, 'diseñador', 'Sugerir línea de diseño', $prompt, [
        'marca_id' => $marca_id, 'sistema' => $sistema,
        'temperatura' => 0.8, 'max_tokens' => 300, 'thinking_budget' => 0,
        'mock_texto' => 'Paleta cálida boricua (terracota, crema y verde palma) con luz natural de tarde. Fotografía real y apetitosa, fondos simples de madera o piedra, composición limpia con aire arriba para el texto. Vibra artesanal y acogedora, nada corporativo.',
    ]);
    return trim((string)$r['texto']);
}

/**
 * COPILOTO: snapshot + consejero de negocio. Conoce el negocio (perfil +
 * Cerebro + estado operativo) y aconseja sobre el proximo paso, contenido,
 * promociones, crecimiento y modelo de negocio.
 */
function asistente_snapshot_operacional(PDO $pdo, int $marca_id): string {
    $lineas = [];
    try {
        $q = $pdo->prepare(
            "SELECT
                SUM(estado='borrador') AS borrador,
                SUM(estado='aprobado') AS aprobado,
                SUM(estado='programado') AS programado,
                SUM(estado='publicando') AS publicando,
                SUM(estado='publicado') AS publicado,
                SUM(estado='fallido') AS fallido
             FROM crecer_contenido WHERE marca_id=?"
        );
        $q->execute([$marca_id]);
        $c = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        $lineas[] = "Contenido: "
            . (int)($c['borrador'] ?? 0) . " esperando OK, "
            . (int)($c['aprobado'] ?? 0) . " aprobado(s), "
            . (int)($c['programado'] ?? 0) . " programado(s), "
            . (int)($c['publicando'] ?? 0) . " publicando, "
            . (int)($c['publicado'] ?? 0) . " publicado(s), "
            . (int)($c['fallido'] ?? 0) . " fallido(s).";
    } catch (Throwable $e) {}

    try {
        $q = $pdo->prepare(
            "SELECT fecha_programada, caption, plataforma, estado
             FROM crecer_contenido
             WHERE marca_id=? AND fecha_programada IS NOT NULL
               AND estado IN ('aprobado','programado')
             ORDER BY fecha_programada ASC LIMIT 1"
        );
        $q->execute([$marca_id]);
        if ($p = $q->fetch(PDO::FETCH_ASSOC)) {
            $lineas[] = "Proximo post: {$p['fecha_programada']} ({$p['estado']}, {$p['plataforma']}) "
                . mb_strimwidth(trim((string)$p['caption']), 0, 90, '...');
        }
    } catch (Throwable $e) {}

    try {
        $q = $pdo->prepare("SELECT estado, ig_username FROM crecer_conexiones WHERE marca_id=? ORDER BY id DESC LIMIT 1");
        $q->execute([$marca_id]);
        if ($cx = $q->fetch(PDO::FETCH_ASSOC)) {
            $ig = trim((string)($cx['ig_username'] ?? ''));
            $lineas[] = "Redes: conexion {$cx['estado']}" . ($ig !== '' ? " con IG @{$ig}" : '') . ".";
        } else {
            $lineas[] = "Redes: no hay conexion activa detectada.";
        }
    } catch (Throwable $e) {}

    try {
        $q = $pdo->prepare(
            "SELECT s.estado, p.nombre, p.slug
             FROM crecer_suscripciones s
             LEFT JOIN crecer_planes p ON p.id=s.plan_id
             WHERE s.marca_id=?
             ORDER BY s.id DESC LIMIT 1"
        );
        $q->execute([$marca_id]);
        if ($s = $q->fetch(PDO::FETCH_ASSOC)) {
            $lineas[] = "Plan: " . trim(($s['nombre'] ?: $s['slug'] ?: 'sin nombre') . " ({$s['estado']}).");
        } else {
            $lineas[] = "Plan: no hay suscripcion registrada.";
        }
    } catch (Throwable $e) {}

    try {
        $q = $pdo->prepare("SELECT agente, accion, created_at FROM crecer_ia_log WHERE marca_id=? AND estado='ok' ORDER BY id DESC LIMIT 4");
        $q->execute([$marca_id]);
        $acts = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $acts[] = "{$a['created_at']}: {$a['agente']} - {$a['accion']}";
        }
        if ($acts) $lineas[] = "Actividad reciente IA:\n- " . implode("\n- ", $acts);
    } catch (Throwable $e) {}

    return $lineas ? implode("\n", $lineas) : "Snapshot operacional no disponible.";
}

function estratega_responder(PDO $pdo, int $marca_id, string $pregunta, array $historial = []): array {
    $m = leer_marca($pdo, $marca_id);
    $ctx = marca_contexto($m);
    $snapshot = asistente_snapshot_operacional($pdo, $marca_id);

    $sistema = <<<SYS
Eres EL ESTRATEGA de Crecer: un consultor de negocio y marketing para
microempresas boricuas (reposterías, comida, servicios, barras, etc.). Conoces
ESTE negocio y aconsejas para que crezca y venda más.

De qué SÍ hablas (tu fuerte):
- Contenido y promoción: qué postear, ofertas que jalan, campañas de temporada,
  fechas boricuas (Navidades, Reyes, Día de Madres, quincena), colaboraciones.
- Crecimiento y modelo de negocio: combos, servicios nuevos, precios, retención
  de clientes, upsell, boca a boca, alianzas locales, presencia en WhatsApp/IG.
- Ideas concretas y accionables para SU tipo de negocio y municipio.

Dinero (con cuidado): puedes dar tips FINANCIEROS GENERALES y educativos (ej.
separar gastos del negocio, margen, flujo de caja, apartar para reinvertir).
SIEMPRE con esta advertencia cuando toques dinero: "Ojo: esto son ideas
generales, no asesoría financiera — para números serios, un contable." NUNCA
des consejos de impuestos, Hacienda, ni contabilidad específica.

Estilo: profesional, claro y directo — asesora de negocio seria pero cercana.
Tuteo NEUTRAL y respetuoso, SIN muletillas ni jerga fuerte (nada de "nene/nena",
"wepa", "mano", "brutal", "chévere"). Práctica y CONCRETA (pasos o una lista corta,
no discursos). Aterriza todo a SU negocio con lo que sabes de él. Si te falta un
dato, dilo y pregúntalo. No inventes cifras ni datos del negocio.

(Este tono profesional es para hablar con el dueño; NO aplica al contenido de los
posts, que sí lleva la voz boricua del negocio.)
SYS;
    $sistema .= "\n\nMODO COPILOTO DE ENCUENTRALO:\n"
        . "- No eres un FAQ. Eres un asistente ejecutivo de mando para el dueno.\n"
        . "- Mira el estado real del panel antes de recomendar ideas nuevas.\n"
        . "- Si hay posts esperando OK, fallos, redes sin conectar o plan inactivo, prioriza eso.\n"
        . "- Da siempre un proximo paso claro, corto y accionable.\n"
        . "- Suena premium, sereno y profesional; no menciones ni imites a Jarvis ni a personajes existentes.\n";
    // NO se inyecta tono_instruccion($m): esos sliders son la voz del CONTENIDO
    // (posts). La Estratega, al hablar con el dueño, se mantiene profesional y neutral.
    if (function_exists('memoria_para_prompt')) $sistema .= memoria_para_prompt($pdo, $marca_id);

    // Contexto de desempeño reciente (para aterrizar el consejo).
    try {
        $pub = (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$marca_id} AND estado='publicado'")->fetchColumn();
        $ctx .= "\nPosts publicados hasta ahora: {$pub}.";
    } catch (Throwable $e) {}

    $mensajes = [];
    foreach ($historial as $h) {
        $rol = ($h['rol'] ?? '') === 'user' ? 'user' : 'model';
        $txt = trim((string)($h['texto'] ?? ''));
        if ($txt !== '') $mensajes[] = ['role' => $rol, 'texto' => $txt];
    }

    $prompt = "Perfil del negocio:\n{$ctx}\n\n"
        . "Snapshot operacional actual:\n{$snapshot}\n\n"
        . "El dueño pregunta/plantea:\n\"{$pregunta}\"\n\n"
        . "Responde como copiloto ejecutivo de Encuentralo: diagnostico breve, proximo paso y, si hace falta, una pregunta.";
    $r = ia_ejecutar($pdo, 'estratega', 'Consejo de negocio', $prompt, [
        'marca_id' => $marca_id, 'sistema' => $sistema,
        'modelo' => CRECER_COPILOTO_MODEL,
        'temperatura' => 0.75, 'max_tokens' => 520, 'thinking_budget' => 0,
        'historial' => $mensajes,
        'mock_texto' => "Ahora mismo yo miraria dos cosas: primero, si tienes posts esperando OK, aprobarlos para que no se tranque la maquina; segundo, sacar una promo sencilla para esta semana.\n\nMi recomendacion: publica un post con una oferta clara, mandalo tambien por WhatsApp y mide cuanta gente pregunta. Si me dices que vendes y en que pueblo estas, te armo la promo exacta.",
    ]);
    return ['ok' => true, 'respuesta' => trim((string)$r['texto'])];
}

/**
 * CEREBRO VISUAL: aprende la línea de diseño de un cliente MIRANDO las imágenes
 * que aprobó/publicó (visión → texto) y actualiza crecer_marca.estilo_visual.
 * Cada cliente aprende la SUYA. Devuelve true si actualizó.
 */
function aprender_estilo_visual(PDO $pdo, int $marca_id): bool {
    $q = $pdo->prepare(
        "SELECT grafica_path FROM crecer_contenido
          WHERE marca_id=? AND grafica_path IS NOT NULL AND grafica_path<>''
            AND estado IN ('aprobado','programado','publicado')
          ORDER BY id DESC LIMIT 5");
    $q->execute([$marca_id]);
    $paths = $q->fetchAll(PDO::FETCH_COLUMN);

    $imagenes = [];
    $url_pref = rtrim(UPLOADS_URL, '/');
    foreach ($paths as $p) {
        $rel = (strpos((string)$p, $url_pref) === 0) ? substr((string)$p, strlen($url_pref)) : (string)$p;
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        $abs = rtrim(UPLOADS_PATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (is_file($abs) && ($data = @file_get_contents($abs)) !== false) {
            $mime = (function_exists('mime_content_type') ? @mime_content_type($abs) : null) ?: 'image/jpeg';
            $imagenes[] = ['data' => base64_encode($data), 'mime' => $mime];
        }
    }
    if (count($imagenes) < 2) return false;   // aún no hay suficiente señal

    $m = leer_marca($pdo, $marca_id);
    $ctx = marca_contexto($m);
    $sistema = "Eres el DIRECTOR DE ARTE de Crecer. Te muestro varias imágenes que ESTE negocio "
        . "boricua APROBÓ para sus redes. Deduce su LÍNEA DE DISEÑO común: el estilo visual que se "
        . "repite y que hay que MANTENER en los próximos posts. Devuelve 2-3 frases en español "
        . "sencillo (sin jerga, sin la palabra \"prompt\", sin saludar) con: paleta de colores, "
        . "mood/vibra, tipo de fotografía o ilustración, fondos y composición. Solo la descripción.";
    $prompt = "Perfil del negocio:\n{$ctx}\n\nMira las imágenes aprobadas y describe la línea de diseño que comparten.";
    try {
        $r = ia_ejecutar($pdo, 'diseñador', 'Aprender línea de diseño (visión)', $prompt, [
            'marca_id' => $marca_id, 'sistema' => $sistema, 'imagenes' => $imagenes,
            'temperatura' => 0.5, 'max_tokens' => 300, 'thinking_budget' => 0,
        ]);
        $linea = trim((string)($r['texto'] ?? ''));
        if ($linea !== '') {
            $pdo->prepare("UPDATE crecer_marca SET estilo_visual=? WHERE id=?")->execute([$linea, $marca_id]);
            return true;
        }
    } catch (Throwable $e) { error_log('aprender_estilo_visual: ' . $e->getMessage()); }
    return false;
}

/**
 * Normaliza una foto subida por el cliente ANTES de mandarla a Gemini:
 * la re-codifica a un JPEG limpio de máx 1536px sobre blanco. Evita el error
 * "Unable to process input image" (por CMYK, JPEG progresivo, alfa, o fotos
 * gigantes del celular). Si GD no está o falla, devuelve la imagen cruda.
 * @return array ['data'=>base64, 'mime'=>string]
 */
function foto_para_ia(string $abs): array {
    $raw  = (string)@file_get_contents($abs);
    $mime = (function_exists('mime_content_type') ? @mime_content_type($abs) : null) ?: 'image/jpeg';
    if ($raw === '' || !function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
        return ['data' => base64_encode($raw), 'mime' => $mime];
    }
    $im = @imagecreatefromstring($raw);
    if (!$im) return ['data' => base64_encode($raw), 'mime' => $mime];   // formato raro: que decida Gemini
    $w = imagesx($im); $h = imagesy($im); $MAX = 1536;
    $esc = min(1.0, $MAX / max(1, max($w, $h)));
    $nw = max(1, (int)round($w * $esc)); $nh = max(1, (int)round($h * $esc));
    $canvas = imagecreatetruecolor($nw, $nh);
    $white  = imagecolorallocate($canvas, 255, 255, 255);   // aplana alfa sobre blanco
    imagefilledrectangle($canvas, 0, 0, $nw, $nh, $white);
    imagecopyresampled($canvas, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    ob_start(); $ok = imagejpeg($canvas, null, 88); $jpg = (string)ob_get_clean();
    imagedestroy($im); imagedestroy($canvas);
    return ($ok && $jpg !== '') ? ['data' => base64_encode($jpg), 'mime' => 'image/jpeg']
                                : ['data' => base64_encode($raw), 'mime' => $mime];
}

function generar_grafica(PDO $pdo, int $marca_id, ?string $foto_abs, array $opts = []): array {
    $m = leer_marca($pdo, $marca_id);
    $copy      = trim($opts['copy'] ?? '');         // el texto del post (coherencia)
    $con_texto = !empty($opts['con_texto']);
    $con_logo  = !empty($opts['con_logo']);
    $estilo    = trim($opts['estilo'] ?? '');

    // Imágenes de entrada: foto del producto (si hay) + logo (si se pide)
    $imagenes = [];
    if ($foto_abs && is_file($foto_abs)) {
        $imagenes[] = foto_para_ia($foto_abs);   // re-codifica a JPEG limpio (evita "Unable to process input image")
    }
    $logo_abs = null;
    if ($con_logo && !empty($m['logo_path'])) {
        $logo_abs = rtrim(UPLOADS_PATH, '/\\') . '/' . ltrim(str_replace(rtrim(UPLOADS_URL,'/'), '', $m['logo_path']), '/');
        if (is_file($logo_abs)) {
            $imagenes[] = ['data' => base64_encode((string)file_get_contents($logo_abs)), 'mime' => 'image/png'];
        }
    }

    $tiene_foto = (bool)($foto_abs && is_file($foto_abs));
    $prompt = "Crea el ARTE (imagen cuadrada 1:1) para un post de \"{$m['nombre_negocio']}\", negocio boricua.\n";
    if ($copy !== '') {
        // El TEMA DEL POST manda sobre el tipo de negocio: evita imágenes fuera de tema.
        $prompt .= "- ⭐ LO MÁS IMPORTANTE: la imagen tiene que ILUSTRAR EL TEMA DE ESTE POST en concreto, NO algo genérico del negocio. Lee bien el texto y muestra visualmente de qué habla:\n"
                 . "  \"{$copy}\"\n"
                 . "  Si el post NO es sobre comida, NO pongas comida. Piensa qué escena/objeto/concepto representa mejor ESE mensaje.\n";
    }
    if ($tiene_foto) {
        $prompt .= "- Usa la FOTO REAL (primera imagen) como protagonista; NO la inventes ni la cambies, solo realza composición, luz y fondo.\n";
    } else {
        $prompt .= "- Genera una imagen realista y atractiva que represente ese tema y encaje con el negocio.\n"
                 . "- VARÍA la escena: NO caigas en el cliché de teléfono/tablet/laptop mostrando redes sociales, ni 'escritorio con café'. Muestra el producto o servicio real, las manos del dueño trabajando, el local, un cliente disfrutando, los ingredientes, la calle boricua, o un concepto gráfico audaz — la que mejor cuente ESTE post.\n";
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
    // LÍNEA DE DISEÑO de la marca (definida en Mi marca): se conserva en TODAS las
    // imágenes → feed consistente, como una marca de verdad.
    $linea = trim((string)($m['estilo_visual'] ?? ''));
    if ($linea !== '') $prompt .= "- LÍNEA DE DISEÑO DE LA MARCA — MANTÉN SIEMPRE este estilo visual (colores, mood, tratamiento) para que todos los posts se vean de la misma familia: {$linea}\n";
    $instr = trim($opts['instrucciones'] ?? '');
    if ($instr !== '') $prompt .= "- LO QUE PIDE EL DUEÑO PARA ESTA IMAGEN (prioriza esto, sin romper la línea de diseño): {$instr}\n";
    $prompt .= "- DIRECCIÓN DE ARTE (calidad tope, que NO se vea \"AI genérico\" ni barato):\n"
             . "  · Fotografía profesional real: iluminación natural suave y direccional (golden hour / softbox), sombras creíbles.\n"
             . "  · Composición intencional (regla de tercios), profundidad de campo con fondo desenfocado (bokeh), foco nítido en el protagonista.\n"
             . "  · Texturas y detalles ricos y reales; colores cálidos boricuas pero NATURALES (sin sobresaturar); acabado editorial premium.\n"
             . "  · EVITA a toda costa: look plástico/CGI, objetos deformes o flotando, texto inventado, watermarks falsos, simetría artificial, ruido, y el cliché de pantallas de celular/tablet/laptop con redes sociales o notificaciones flotantes (a menos que el post sea literalmente sobre eso).\n"
             . "  · Meta: una foto que un fotógrafo profesional tomaría para redes, sobre EL TEMA del post — nítida, con alma, lista para publicar.";

    // Calidad make-or-break: SIEMPRE el Pro (Nano Banana Pro). Antes el "sin texto"
    // caía al flash barato y se notaba la baja calidad. El Pro (~$0.13) vale la pena
    // frente a un plan de $39/mes. (Reversible: volver a flash para bajar costo.)
    $modelo = 'gemini-3-pro-image';
    $fname = "marca_{$marca_id}/graficas/post_" . uniqid() . ".png";
    $r = ia_imagen($pdo, 'creador', 'Crear arte de post', $prompt, $fname, [
        'marca_id' => $marca_id,
        'modelo'   => $modelo,
        'imagenes' => $imagenes,
        'aspect'   => $opts['aspect'] ?? '1:1',   // cuadrado (feed IG/FB); encuadre limpio
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
 * Instrucción de CTA (llamado a la acción de contacto) para el prompt del
 * creador, según la preferencia del dueño y sus datos REALES.
 *
 * Regla anti-misrepresentación: SOLO se menciona WhatsApp si hay número
 * configurado. Nunca se inventa un número ni se empuja un canal que el
 * dueño no puso. Sin preferencia elegida => DM (siempre existe en la red).
 */
function contacto_instruccion(array $m): string {
    $wa   = trim((string)($m['whatsapp'] ?? ''));
    $tiene_wa = $wa !== '';
    $pref = (string)($m['contacto_preferencia'] ?? '');
    // Normaliza: si la preferencia pide WhatsApp pero no hay número, cae a DM.
    if (in_array($pref, ['whatsapp','ambas','todas'], true) && !$tiene_wa) {
        $pref = 'dm';
    }
    switch ($pref) {
        case 'whatsapp':
            return "- CIERRA con un llamado a la acción para que le escriban por WhatsApp al {$wa}. NO uses otro canal.\n";
        case 'ambas':
            return "- CIERRA invitando a escribir por mensaje directo (DM) aquí en la red O por WhatsApp al {$wa}.\n";
        case 'todas':
            return "- CIERRA invitando a contactar por la vía que le quede fácil: DM aquí en la red o WhatsApp al {$wa}.\n";
        case 'dm':
            return "- CIERRA invitando a escribir por mensaje directo (DM) aquí en la red. NO menciones WhatsApp ni inventes un número de teléfono.\n";
        default: // el dueño no ha elegido preferencia
            if ($tiene_wa) {
                return "- CIERRA invitando a escribir por mensaje directo (DM) aquí en la red o por WhatsApp al {$wa}.\n";
            }
            return "- CIERRA invitando a escribir por mensaje directo (DM) aquí en la red. NO menciones WhatsApp ni inventes un número de teléfono.\n";
    }
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
Eres el ESTRATEGA de contenido de Crecer, un departamento de marketing con IA
para microempresas boricuas. Planificas el mes de redes como lo haría una
agencia top — no llenas casillas, diseñas una estrategia.
Reglas:
- Piensa como estratega boricua: aprovecha fechas, cobros quincenales, fines de
  semana y la cultura local de Puerto Rico (y del municipio del negocio).
- VARÍA los PILARES de contenido, no repitas el mismo tipo: producto estrella,
  detrás de cámara/proceso, prueba social (reseñas/clientes), tip o educación,
  promo u oferta, fecha/temporada, y pregunta/interacción con la comunidad.
- Cada IDEA debe ser ESPECÍFICA de ESTE negocio y traer un GANCHO concreto
  (qué mostrar + por qué la gente se detiene a mirar). Nada genérico.
- Variedad de plataformas (instagram, facebook) y tipos (post, story, reel).
- Responde SOLO JSON válido, sin texto extra.
SYS;

    $prompt = "Perfil del negocio:\n{$ctx}\n\n"
        . "Diseña la estrategia de {$n_piezas} piezas para el mes {$mes}/{$anio}, con pilares variados.\n"
        . "Devuelve un JSON con esta forma EXACTA:\n"
        . '{"piezas":[{"dia":1,"plataforma":"instagram","tipo":"post","pilar":"producto","tema":"...","idea":"..."}]}'
        . "\n- dia: número 1-28.\n- plataforma: instagram|facebook.\n- tipo: post|story|reel.\n"
        . "- pilar: producto|proceso|prueba_social|tip|promo|temporada|pregunta.\n"
        . "- tema: 2-4 palabras.\n"
        . "- idea: 1-2 oraciones ESPECÍFICAS con el gancho (qué se ve y por qué engancha). Concreta, de este negocio.";

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
        // El Cerebro: la edición es la señal de oro → memoria de preferencia con
        // peso alto (corrección > aprobación). Cada viñeta = una preferencia.
        if (function_exists('memoria_escribir')) {
            foreach (preg_split('/\n+/', $leccion) as $ln) {
                $ln = trim(ltrim($ln, "-•* \t"));
                if ($ln === '') continue;
                memoria_escribir($pdo, $marca_id, [
                    'tipo'=>'preferencia', 'titulo'=>mb_strimwidth($ln,0,120,'…'), 'detalle'=>$ln,
                    'porque'=>'Lo aprendí de una edición que le hiciste a un caption.',
                    'fuente'=>'edicion', 'confianza'=>70, 'peso'=>80,
                ]);
            }
            memoria_consolidar($pdo, $marca_id);
        }
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
- Cierra con un llamado a la acción (según la vía de contacto de abajo) y 3-4 hashtags locales.
- Máximo 60 palabras. Devuelve SOLO el caption, sin comillas ni explicación.
SYS;
    $sistema .= "\n" . contacto_instruccion($m);
    if (!empty($m['glosario'])) {
        $sistema .= "\n\nVOCABULARIO DEL NEGOCIO (el dueño lo corrigió — RESPÉTALO SIEMPRE, no repitas los errores):\n" . $m['glosario'];
    }
    $sistema .= tono_instruccion($m);
    if (function_exists('memoria_para_prompt')) $sistema .= memoria_para_prompt($pdo, (int)$m['id']);

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
- Cierra con un llamado a la acción (según la vía de contacto de abajo) y 3-4 hashtags locales.
- Máximo 60 palabras. Devuelve SOLO el caption, sin comillas ni explicación.
SYS;
    $sistema .= "\n" . contacto_instruccion($m);
    if (!empty($m['glosario'])) {
        $sistema .= "\n\nVOCABULARIO DEL NEGOCIO (el dueño lo corrigió — RESPÉTALO SIEMPRE, no repitas los errores):\n" . $m['glosario'];
    }
    $sistema .= tono_instruccion($m);
    if (function_exists('memoria_para_prompt')) $sistema .= memoria_para_prompt($pdo, (int)$m['id']);

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
    $snapshot = asistente_snapshot_operacional($pdo, $marca_id);

    $sistema = <<<SYS
Eres el ASISTENTE de Crecer (también le decimos "el corillo"): un departamento
de marketing con IA para microempresas boricuas. Ayudas al dueño DENTRO de la
app: le aclaras dudas, le explicas cómo usar cada parte y lo guías paso a paso.

Tono: profesional, claro y cortés — cálido pero NEUTRAL, sin exceso de confianza.
Tuteas de forma respetuosa, SIN muletillas ni jerga fuerte (nada de "nene/nena",
"wepa", "mano", "brutal", "chévere"). Sobrio y directo. CORTO (2-5 frases o una
lista breve), sin relleno ni "AI slop". Si no sabes algo del negocio del dueño,
dilo y sugiérele dónde configurarlo. No inventes precios ni datos.

(Este tono NEUTRAL es solo para hablar con el dueño dentro de la app; NO aplica al
contenido de los posts, que sí lleva la voz boricua del negocio.)

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
    $sistema .= "\n\nMODO COPILOTO DE ENCUENTRALO:\n"
        . "- Eres una presencia tipo asistente ejecutivo de mando, no un FAQ pasivo.\n"
        . "- Si pregunta que hacer, usa el snapshot operacional y recomienda una prioridad.\n"
        . "- Si pregunta como usar algo, dale la ruta exacta y el siguiente toque.\n"
        . "- Si hay fallos, posts esperando OK, redes sin conectar o plan inactivo, dilo con calma y da el proximo paso.\n"
        . "- No menciones ni imites a Jarvis ni a personajes existentes.\n";
    if (!empty($m['glosario'])) {
        $sistema .= "\n\nVocabulario propio de este negocio (respétalo):\n" . $m['glosario'];
    }
    // NO se inyecta tono_instruccion($m): esos sliders son la voz del CONTENIDO
    // (posts). El Copiloto que habla con el dueño se mantiene neutral y profesional.
    if (function_exists('memoria_para_prompt')) $sistema .= memoria_para_prompt($pdo, (int)$m['id']);
    // El Cerebro: el asistente conoce todo lo aprendido del negocio (para
    // responder "¿qué has aprendido?", "¿qué prefiero?", "¿cómo cambió mi marca?").
    if (function_exists('memoria_listar')) {
        $mems = memoria_listar($pdo, $marca_id);
        if ($mems) {
            $lst = '';
            foreach (array_slice($mems, 0, 12) as $mm) $lst .= "- " . trim((string)$mm['detalle']) . "\n";
            $sistema .= "\n\nLO QUE EL CORILLO HA APRENDIDO DE ESTE NEGOCIO (úsalo si el dueño pregunta qué has aprendido, qué prefiere o cómo ha cambiado su marca):\n" . $lst;
        }
    }

    // Compactar el historial reciente (últimos 6 turnos) dentro del prompt.
    $hist = '';
    foreach (array_slice($historial, -6) as $t) {
        $quien = (($t['rol'] ?? '') === 'ia') ? 'Asistente' : 'Dueño';
        $txt = trim((string)($t['texto'] ?? ''));
        if ($txt !== '') $hist .= "$quien: $txt\n";
    }

    $prompt = "Contexto del negocio del dueño:\n{$ctx}\n\n"
        . "Snapshot operacional actual:\n{$snapshot}\n\n"
        . ($hist !== '' ? "Conversación hasta ahora:\n{$hist}\n" : '')
        . "Pregunta del dueño: {$pregunta}\n\n"
        . "Responde como copiloto de Encuentralo: corto, util y con proximo paso.";

    $r = ia_ejecutar($pdo, 'asistente', 'Responder duda del dueño', $prompt, [
        'marca_id'        => $marca_id,
        'sistema'         => $sistema,
        'modelo'          => CRECER_COPILOTO_MODEL,
        'temperatura'     => 0.6,
        'max_tokens'      => 360,
        'thinking_budget' => 0,
        'mock_texto'      => 'Estoy contigo. Primero mira si hay posts esperando tu OK; si los hay, entra a Contenido y aprueba el mejor. Si no hay nada pendiente, el proximo movimiento es pedir una pieza nueva o revisar Resultados para decidir que empujar esta semana.',
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
