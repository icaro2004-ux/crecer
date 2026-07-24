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

if (!defined('CRECER_COPILOTO_HORA'))       define('CRECER_COPILOTO_HORA', 25);        // mensajes por negocio / hora con plan
if (!defined('CRECER_COPILOTO_DIA'))        define('CRECER_COPILOTO_DIA', 120);        // mensajes por negocio / dia con plan
if (!defined('CRECER_COPILOTO_FREE_DIA'))   define('CRECER_COPILOTO_FREE_DIA', 10);    // mensajes por negocio / dia sin plan
if (!defined('CRECER_COPILOTO_GLOBAL_DIA')) define('CRECER_COPILOTO_GLOBAL_DIA', 600); // fusible de todo Crecer / dia
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

    // FK-safe: si el municipio/categoría enviado NO existe en la BD, se guarda NULL
    // (ambas columnas son nullable, FK ON DELETE SET NULL) en vez de reventar con el
    // error 1452 "foreign key constraint fails" y tumbar todo el onboarding. (fix 2026-07-19)
    foreach (['municipio_id'=>'municipios', 'categoria_id'=>'categorias'] as $campo => $tabla) {
        $val = isset($d[$campo]) && $d[$campo] !== null && $d[$campo] !== '' ? (int)$d[$campo] : null;
        if ($val !== null) {
            try {
                $chk = $pdo->prepare("SELECT 1 FROM {$tabla} WHERE id = ?");
                $chk->execute([$val]);
                if (!$chk->fetchColumn()) $val = null;   // id inexistente → NULL (evita el 1452)
            } catch (Throwable $e) { $val = null; }       // tabla ausente/rara → NULL, no rompas
        }
        $d[$campo] = $val;
    }

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

    // PILOTO AUTOMÁTICO ON por defecto: el "done-for-you" es la promesa — el corillo
    // prepara posts SOLO (solo corre de verdad con plan vigente o cuenta de prueba;
    // ver correr_corillo). El dueño lo puede apagar en Configuración. (2026-07-20)
    try { $pdo->prepare("UPDATE crecer_marca SET autopilot=1 WHERE id=?")->execute([$id]); }
    catch (Throwable $e) { /* columna autopilot aún no migrada: se ignora */ }

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
 * ESTILO de arte elegido por el dueño → dirección visual para el Director de Arte
 * (sugerir) y para la generación de imagen (generar). Da VARIEDAD real: ya no todo
 * es hiperrealista. Desconocido → 'realista'.
 * @return array{label:string, sugerir:string, generar:string}
 */
function estilo_arte_direccion(string $estilo): array {
    $map = [
        'realista' => [
            'label'   => 'Realista',
            'sugerir' => "ESTILO: fotografía REAL y premium, como la tomaría un fotógrafo profesional.",
            'generar' => "Fotografía profesional real: luz natural suave y direccional, sombras creíbles, profundidad de campo (bokeh), texturas ricas, acabado editorial. Nada de look plástico/CGI.",
        ],
        'creativo' => [
            'label'   => 'Creativo',
            'sugerir' => "ESTILO: CREATIVO y estilizado — composición audaz, colores vivos, un giro visual inesperado que sorprenda (sin volverse genérico ni caótico).",
            'generar' => "Estilo CREATIVO y artístico: composición audaz e inesperada, colores vibrantes, contraste fuerte, un concepto visual con gancho. Puede estilizar la realidad (no tiene que ser foto literal), pero limpio y de alta calidad.",
        ],
        'fantasia' => [
            'label'   => 'Fantasía',
            'sugerir' => "ESTILO: FANTÁSTICO y de ensueño — elementos mágicos o surrealistas, atmósfera de cuento, luz de ensueño, algo memorable y espectacular.",
            'generar' => "Estilo FANTÁSTICO / de ensueño: atmósfera mágica y surrealista, luz dramática y brillos, elementos oníricos, paleta rica y saturada, sensación de cuento. Espectacular pero coherente con el mensaje del post.",
        ],
        'ilustracion' => [
            'label'   => 'Ilustración',
            'sugerir' => "ESTILO: ILUSTRACIÓN / arte digital — trazo, formas y color de ilustración (no foto), moderno y con personalidad.",
            'generar' => "Estilo ILUSTRACIÓN / arte digital: formas limpias, trazo definido, paleta plana o con degradados suaves, composición moderna. NO es fotografía — es ilustración con carácter.",
        ],
    ];
    // Permite COMBINAR estilos: "creativo+fantasia" → funde ambas direcciones en una.
    $claves = array_values(array_filter(
        array_map('trim', preg_split('/[+,]/', strtolower(trim($estilo)))),
        fn($k) => isset($map[$k])
    ));
    if (count($claves) <= 1) return $map[$claves[0] ?? 'realista'] ?? $map['realista'];

    $labels = $sug = $gen = [];
    foreach ($claves as $k) { $labels[] = $map[$k]['label']; $sug[] = $map[$k]['sugerir']; $gen[] = $map[$k]['generar']; }
    $etq = implode(' + ', $labels);
    return [
        'label'   => $etq,
        'sugerir' => "ESTILO COMBINADO ({$etq}): mezcla estas vibras en UNA sola imagen coherente, no un collage.\n- " . implode("\n- ", $sug),
        'generar' => "ESTILO COMBINADO ({$etq}) — funde estas direcciones en UNA sola imagen coherente y de alta calidad (no un collage ni dos mitades):\n- " . implode("\n- ", $gen),
    ];
}

/**
 * DIRECTOR DE ARTE — lee el CAPTION y propone una imagen que ILUSTRE lo que el
 * texto dice (mismo mensaje, no dos cosas sueltas). El dueño la lee y aprueba.
 * $estilo_arte: realista|creativo|fantasia|ilustracion. Es una llamada de TEXTO (barata).
 */
function sugerir_arte(PDO $pdo, int $marca_id, string $caption, string $ajuste = '', string $evitar = '', string $estilo_arte = 'realista', string $idea_actual = ''): string {
    $m = leer_marca($pdo, $marca_id);
    $ctx = cerebro_negocio($pdo, (int)$m['id'], $m);   // el CEREBRO del negocio permea cada decisión
    $est = estilo_arte_direccion($estilo_arte);
    $sistema = "Eres EL DIRECTOR DE ARTE de Crecer — creativo, atrevido, con ojo de agencia top. Tu misión: una imagen "
        . "que FRENE EL SCROLL y conecte con el MENSAJE del post (imagen y copy = una sola idea), pero NUNCA la foto "
        . "obvia y aburrida.\n"
        . "PIENSA PRIMERO: ¿de qué habla ESTE post y qué emoción dispara? LUEGO elige UNA táctica visual y comprométete:\n"
        . "- Producto heroico (macro dramático, luz de estudio)   - Metáfora visual (un objeto/escena simbólica inesperada)\n"
        . "- Detrás de cámara (manos, proceso, taller en acción)   - Humano/emoción (una persona real disfrutando/reaccionando)\n"
        . "- Contraste o giro visual sorpresa   - Escena de uso (el producto en la vida real del cliente)\n"
        . "- Gráfico audaz (composición tipográfica con colores de marca)   - Macro/textura (acercamiento que da deseo)\n"
        . "Sé CABRÓN y ESPECÍFICO: (1) sujeto principal, (2) entorno y props concretos, (3) ángulo/encuadre, (4) luz y "
        . "ambiente, (5) paleta. Nada genérico ni tibio. Deja aire arriba por si va texto. Evita clichés (café/amanecer/"
        . "escritorio, pantallas con apps flotantes) a menos que el post SEA literalmente de eso.\n"
        . $est['sugerir']
        . "\nDevuelve 2-3 frases, DIRECTO con la escena — sin saludos, sin \"mi gente\", sin preámbulos ni emojis. Es una dirección visual para el diseñador, no un caption.";
    $radio_img = radiografia_capitulo($pdo, $marca_id, 'reglas_imagen');
    if ($radio_img !== '') $sistema .= "\nREGLAS DE IMAGEN DEL NEGOCIO (las escribió el Business Genome — obedécelas SIEMPRE): {$radio_img}";
    $prompt = "Perfil del negocio:\n{$ctx}\n\nTexto del post (la imagen TIENE que pegar con esto):\n\"{$caption}\"\n";
    $idea_actual = trim($idea_actual);
    if ($idea_actual !== '' && trim($ajuste) !== '') {
        // MODO CHAT: el dueño conversa para AFINAR la idea actual. Se aplica su cambio, se
        // mantiene lo que ya funciona (no se empieza de cero) y se devuelve la idea revisada.
        $prompt .= "\nIDEA ACTUAL del arte (la que el dueño está afinando):\n\"" . mb_substr($idea_actual, 0, 500) . "\"\n"
                 . "EL DUEÑO PIDE ESTE CAMBIO — APLÍCALO y mantén lo demás que ya funciona, NO empieces de cero: \"{$ajuste}\"\n"
                 . "Devuelve la idea REVISADA completa (2-3 frases, con detalle concreto), ya con el cambio aplicado.";
    } else {
        if (trim($ajuste) !== '') $prompt .= "\nLO QUE PIDE EL DUEÑO (es lo más importante — EXPÁNDELO con detalle visual, no lo ignores): {$ajuste}\n";
        if (trim($evitar) !== '') {
            $prompt .= "\nEL DUEÑO YA VIO esta idea y pidió OTRA — dale algo CATEGÓRICAMENTE DISTINTO: elige una TÁCTICA "
                     . "VISUAL DIFERENTE (si la anterior era producto heroico, tira metáfora, detrás de cámara, humano, "
                     . "gráfico audaz, etc.) y cambia el tipo de escena, el ángulo, el lugar y los props por completo. NO "
                     . "la reconfigures ni la parafrasees; invéntate algo fresco y diferente de esto:\n\"" . mb_substr(trim($evitar), 0, 400) . "\"\n";
        }
        $prompt .= "\nDescribe en 2-3 frases, con detalle concreto, qué va a mostrar la imagen.";
    }
    $r = ia_ejecutar($pdo, 'diseñador', 'Sugerir idea de arte', $prompt, [
        'marca_id' => $marca_id, 'sistema' => $sistema,
        'temperatura' => (trim($evitar) !== '' ? 1.2 : 1.05), 'max_tokens' => 320, 'thinking_budget' => 0,
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
    $ctx = cerebro_negocio($pdo, (int)$m['id'], $m);   // el CEREBRO del negocio permea cada decisión
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
    // (la memoria aprendida ya viene dentro del cerebro_negocio, en el contexto)

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
    $ctx = cerebro_negocio($pdo, (int)$m['id'], $m);   // el CEREBRO del negocio permea cada decisión
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
    $ctx = cerebro_negocio($pdo, (int)$m['id'], $m);   // el CEREBRO del negocio permea cada decisión
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
    // (la memoria aprendida ya viene dentro del cerebro_negocio, en el contexto)

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
    $ctx = cerebro_negocio($pdo, (int)$m['id'], $m);   // el CEREBRO del negocio permea cada decisión
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
    // GROUNDING del producto: qué VENDE/HACE el negocio de verdad. Sin esto, el modelo
    // llena el vacío con lo que el NOMBRE sugiere (ej. "shtsnbubbles" jabones → bubble tea).
    $prods_raw = $m['productos'] ?? [];
    if (is_string($prods_raw)) $prods_raw = json_decode($prods_raw, true) ?: [];
    $prods = [];
    foreach ((array)$prods_raw as $p) { $nom = is_array($p) ? trim((string)($p['nombre'] ?? '')) : trim((string)$p); if ($nom !== '') $prods[] = $nom; }
    $que_vende = $prods ? implode(', ', array_slice($prods, 0, 8)) : trim((string)($m['descripcion'] ?? ''));

    $prompt = "Crea el ARTE (imagen cuadrada 1:1) para el post de un negocio boricua"
            . ($m['nombre_negocio'] !== '' ? " llamado \"{$m['nombre_negocio']}\"" : '') . ".\n"
            . "- EL NOMBRE ES SOLO UNA ETIQUETA DE MARCA, no el tema de la imagen. La ESCENA sale del MENSAJE del texto del post (abajo), NUNCA del nombre. No ilustres el nombre ni interpretes sus palabras de forma literal ni por parecido; trátalas como un rótulo, no como objetos.\n";
    if ($que_vende !== '') {
        $prompt .= "- ⚓ QUÉ ES ESTE NEGOCIO (ANCLA la imagen a ESTO — es lo REAL, manda sobre el nombre): "
                 . "hace/vende **{$que_vende}**. La imagen SIEMPRE vive en el mundo de este negocio. JAMÁS muestres "
                 . "un producto de otra industria aunque el NOMBRE lo sugiera por casualidad (ej.: negocio de JABONES "
                 . "con 'bubbles' en el nombre → burbujas/barras de JABÓN, NUNCA bubble tea ni bebidas).\n";
    }
    // El capítulo de IMAGEN de la RADIOGRAFÍA (lo redactó el Business Genome) → alinea el arte al negocio.
    $radio_img = radiografia_capitulo($pdo, $marca_id, 'reglas_imagen');
    if ($radio_img !== '') $prompt .= "- 📖 REGLAS DE IMAGEN DEL NEGOCIO (las escribió el Business Genome — respétalas): {$radio_img}\n";
    if ($copy !== '') {
        // El TEMA DEL POST manda sobre el tipo de negocio: evita imágenes fuera de tema.
        $prompt .= "- ⭐ LO MÁS IMPORTANTE: la imagen ILUSTRA EL MENSAJE DE ESTE POST (lo que el texto realmente dice), no el nombre ni algo genérico. Lee el texto y muestra de qué habla de verdad:\n"
                 . "  \"{$copy}\"\n"
                 . "  PRIORIDAD ABSOLUTA DEL TEXTO: si el nombre sugiere una cosa y el texto otra, manda el texto. Si una palabra del nombre es ambigua, ignórala y decide la escena solo con el texto.\n"
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
        $prompt .= "- SIN texto sobre la imagen: solo la escena/arte, pero LLENA y con vida (no minimalista vacío).\n";
    }
    // ⛔ REGLA DURA anti-fondo-blanco: el modelo tiende a dejar blanco cuando oye "limpio".
    $prompt .= "- ⛔ EL FONDO NUNCA blanco liso, plano ni vacío. SIEMPRE un entorno REAL con TEXTURA, luz, color y "
             . "profundidad (mesa de madera, taller, cocina, ingredientes, el local, la calle boricua, un ambiente) que "
             . "LLENE TODO EL CUADRO de borde a borde. Prohibidas las áreas blancas o vacías sin sentido.\n";
    if ($estilo !== '') $prompt .= "- Estilo: {$estilo}.\n";
    // LÍNEA DE DISEÑO de la marca (definida en Mi marca): se conserva en TODAS las
    // imágenes → feed consistente, como una marca de verdad.
    $linea = trim((string)($m['estilo_visual'] ?? ''));
    if ($linea !== '') $prompt .= "- LÍNEA DE DISEÑO DE LA MARCA — MANTÉN SIEMPRE este estilo visual (colores, mood, tratamiento) para que todos los posts se vean de la misma familia: {$linea}\n";
    $instr = trim($opts['instrucciones'] ?? '');
    if ($instr !== '') $prompt .= "- LO QUE PIDE EL DUEÑO PARA ESTA IMAGEN (prioriza esto, sin romper la línea de diseño): {$instr}\n";
    // ESTILO elegido por el dueño (realista/creativo/fantasia/ilustracion). Con FOTO real se fuerza
    // realista: no se distorsiona el producto del negocio (regla de IP). Sin foto, respeta la elección.
    $est_arte = $tiene_foto ? estilo_arte_direccion('realista') : estilo_arte_direccion(trim($opts['estilo_arte'] ?? 'realista'));
    $prompt .= "- DIRECCIÓN DE ARTE (calidad tope, que NO se vea \"AI genérico\" ni barato):\n"
             . "  · {$est_arte['generar']}\n"
             . "  · Composición intencional (regla de tercios), foco nítido en el protagonista, acabado premium.\n"
             . "  · EVITA a toda costa: objetos deformes o flotando, texto inventado, watermarks falsos, ruido, y el cliché de pantallas de celular/tablet/laptop con redes sociales o notificaciones flotantes (a menos que el post sea literalmente sobre eso).\n"
             . "  · Meta: una imagen sobre EL TEMA del post — nítida, con alma, lista para publicar.";

    // 🎨 DIRECTOR DE ARTE CON IA — no "generamos una imagen": diseñamos una CAMPAÑA.
    // Pipeline de 3 agentes (Estratega de Campaña → Director de Arte → Prompt Engineer)
    // + biblioteca de estilos por industria. La imagen COMPLEMENTA la emoción del copy,
    // no lo ilustra literal. Solo para arte DESDE CERO (la foto real va a Gemini fiel).
    // Degrada solo: pipeline → dirigir_arte → este prompt de respaldo. Ver includes/direccion_arte.php.
    if (!$tiene_foto) {
        // FLAG: v1 = pipeline de agentes (direccion_arte); v2 = un solo cerebro creativo
        // (image_messenger). El compare pasa 'pipeline'/'creative_model' por $opts.
        $pipeline = $opts['pipeline'] ?? (defined('IMAGE_PIPELINE') ? IMAGE_PIPELINE : 'v1');
        try {
            if ($pipeline === 'v2') {
                require_once __DIR__ . '/image_messenger.php';
                $p2 = image_messenger_prompt($pdo, $marca_id, $m, $copy, ['con_texto'=>$con_texto, 'instrucciones'=>$instr, 'modelo'=>$opts['creative_model'] ?? '']);
                if (trim($p2) !== '') $prompt = $p2;
            } else {
                require_once __DIR__ . '/direccion_arte.php';
                $camp = campana_visual($pdo, $marca_id, $m, $copy, ['con_texto'=>$con_texto, 'instrucciones'=>$instr]);
                if (!empty($camp['prompt'])) $prompt = $camp['prompt'];
            }
        } catch (Throwable $e) { error_log("pipeline {$pipeline}: " . $e->getMessage()); }
    }

    // Calidad make-or-break: SIEMPRE el Pro (Nano Banana Pro). Antes el "sin texto"
    // caía al flash barato y se notaba la baja calidad. El Pro (~$0.13) vale la pena
    // frente a un plan de $39/mes. (Reversible: volver a flash para bajar costo.)
    $modelo = 'gemini-3-pro-image';
    $fname = "marca_{$marca_id}/graficas/post_" . uniqid() . ".png";
    $r = ia_imagen($pdo, 'creador', 'Crear arte de post', $prompt, $fname, [
        'marca_id'  => $marca_id,
        'modelo'    => $modelo,
        'imagenes'  => $imagenes,
        'foto_real' => $tiene_foto,               // foto real → Gemini (fiel); arte desde cero → gpt-image-1
        'aspect'    => $opts['aspect'] ?? '1:1',   // cuadrado (feed IG/FB); encuadre limpio
    ]);
    $pdo->prepare("INSERT INTO crecer_graficas (marca_id, archivo, copy_text) VALUES (?,?,?)")
        ->execute([$marca_id, $r['archivo'], $copy]);
    return $r;
}

/**
 * EL DIRECTOR DE ARTE — convierte el brief del negocio en UN prompt de imagen vívido
 * y cinematográfico, como hace ChatGPT por dentro antes de generar. Es el secreto de
 * por qué el MISMO modelo (gpt-image-1) saca imágenes mucho mejores: una escena
 * concreta y POSITIVA rinde infinitamente más que un rulebook lleno de "NO/EVITA".
 * Además, al ser corto y limpio, gpt-image-1 NO lo rechaza → deja de caer a Gemini.
 * @return string prompt EN INGLÉS listo para el modelo (o '' si falla → usa el de respaldo).
 */
function dirigir_arte(PDO $pdo, int $marca_id, string $que_vende, string $copy, string $instr, array $est_arte, bool $con_texto = false, string $nombre = ''): string {
    $estilo_hint = trim((string)($est_arte['generar'] ?? ''));
    if ($con_texto) {
        // MODO PÓSTER PROMOCIONAL (como hace ChatGPT): un GRÁFICO terminado con titular,
        // marca y CTA metidos en la imagen — no una foto pelada. Es el "anuncio de verdad".
        $sys = "Eres un DIRECTOR CREATIVO y diseñador gráfico de clase mundial. Escribes UN prompt EN INGLÉS para gpt-image-1 "
             . "que produzca un GRÁFICO PROMOCIONAL DE REDES terminado y profesional (calidad de agencia), cuadrado, listo para "
             . "publicar en Instagram/Facebook/WhatsApp. El diseño DEBE incluir: un TITULAR corto y llamativo sacado del mensaje "
             . "(tipografía con jerarquía clara), el NOMBRE del negocio integrado como marca/logo, el producto o servicio mostrado "
             . "apetitoso y premium, y un CTA corto. Composición balanceada, paleta cálida y coherente, tipografías legibles y bien "
             . "espaciadas, todo el TEXTO perfectamente escrito EN ESPAÑOL y sin faltas. Descríbelo vívido y concreto. "
             . "Devuelve SOLO el prompt: un párrafo, sin comillas ni notas.";
        $brief = "Gráfico promocional cuadrado para redes de un negocio boricua.\n"
               . ($nombre !== '' ? "Nombre del negocio (ponlo como marca dentro del diseño): {$nombre}\n" : '')
               . "Lo que vende: " . ($que_vende !== '' ? $que_vende : 'sus productos') . "\n"
               . "Mensaje/copy del post (saca de aquí el TITULAR y el CTA): \"" . ($copy !== '' ? $copy : 'promoción del negocio') . "\"\n"
               . ($instr !== '' ? "Pedido específico del dueño: {$instr}\n" : '')
               . ($estilo_hint !== '' ? "Estilo visual: {$estilo_hint}\n" : '')
               . "Diseña el mejor gráfico promocional posible.";
    } else {
        // MODO FOTO limpia: el copy va como caption aparte. Escena premium, sin texto.
        $sys = "Eres un director creativo de clase mundial. Escribes UN prompt de imagen EN INGLÉS para un modelo "
             . "profesional (gpt-image-1). Te doy el copy de un post de redes y lo que vende el negocio; imagina la foto MÁS "
             . "impactante, premium y scroll-stopping que combine perfecto con ese mensaje para Instagram, Facebook y WhatsApp. "
             . "Descríbela vívida y concreta (escena, sujeto, luz, cámara/lente, mood, color, textura), con total libertad "
             . "creativa. Sin texto ni letras dentro de la imagen. Quédate en el mundo real de lo que vende el negocio. "
             . "Devuelve SOLO el prompt: un párrafo, sin comillas ni notas.";
        $brief = "Es para un post AUTOMÁTICO de redes de un negocio boricua.\n"
               . "El negocio vende: " . ($que_vende !== '' ? $que_vende : 'sus productos/servicios') . "\n"
               . "Copy del post: \"" . ($copy !== '' ? $copy : 'presentación cálida del negocio a su gente') . "\"\n"
               . ($instr !== '' ? "Lo que pide el dueño: {$instr}\n" : '')
               . ($estilo_hint !== '' ? "Estilo que prefiere: {$estilo_hint}\n" : '')
               . "Hazme el mejor prompt de imagen que combine con este copy.";
    }
    $r = ia_ejecutar($pdo, 'creador', 'Director de arte (prompt de imagen)', $brief, [
        'marca_id'    => $marca_id,
        'sistema'     => $sys,
        'temperatura' => 0.9,
        'max_tokens'  => 360,
        'thinking_budget' => 0,
        'mock_texto'  => '',
    ]);
    return trim((string)($r['texto'] ?? ''));
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

/**
 * EL ROSTER DEL CORILLO — los agentes que el dueño ve como su "equipo". Cada uno
 * con su rol por defecto, su cara (emoji) y qué hace. El dueño puede bautizarlos
 * (equipo_nombres) para el sentido de equipo. La clave mapea al agente que loguea.
 */
function equipo_roster(): array {
    return [
        'gerente'    => ['rol' => 'El Gerente',    'ico' => 'briefcase', 'hace' => 'Reparte el trabajo y te reporta.',              'log' => 'gerente'],
        'provocador' => ['rol' => 'El Provocador', 'ico' => 'bolt',      'hace' => 'Lanza los ángulos más atrevidos.',              'log' => 'provocador'],
        'estratega'  => ['rol' => 'La Estratega',  'ico' => 'compass',   'hace' => 'Escoge el ángulo que más vende y cuadra el plan.', 'log' => 'estratega'],
        'escritor'   => ['rol' => 'El Escritor',   'ico' => 'pen',       'hace' => 'Escribe los posts en tu voz boricua.',          'log' => 'creador'],
        'disenador'  => ['rol' => 'El Diseñador',  'ico' => 'palette',   'hace' => 'Crea el arte de cada post.',                    'log' => 'diseñador'],
        'analista'   => ['rol' => 'El Analista',   'ico' => 'chart',     'hace' => 'Revisa los números y qué está funcionando.',    'log' => 'analitica'],
    ];
}

/** Nombres personalizados del equipo (JSON en crecer_marca.equipo_nombres). */
function equipo_nombres(array $marca): array {
    $j = json_decode((string)($marca['equipo_nombres'] ?? ''), true);
    return is_array($j) ? $j : [];
}

/** Nombre a mostrar de un agente: el que el dueño le puso, o el rol por defecto. */
function equipo_nombre(array $marca, string $key): string {
    $n = trim((string)(equipo_nombres($marca)[$key] ?? ''));
    if ($n !== '') return $n;
    $r = equipo_roster();
    return $r[$key]['rol'] ?? ucfirst($key);
}

/** Resume el perfil de la marca en texto para meterlo en un prompt. */
function marca_contexto(array $m): string {
    $prod = $m['productos'] ? implode(', ', array_map(
        fn($p) => is_array($p) ? ($p['nombre'] ?? json_encode($p)) : $p, $m['productos'])) : 'n/d';
    return "Negocio: {$m['nombre_negocio']}\n"
         . (trim((string)($m['pueblo'] ?? '')) !== '' ? "Pueblo/mercado: {$m['pueblo']}\n" : '')
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
        'Bien boricua: usa expresiones de la isla (mi gente, brutal, chévere, "pa\'", nene/nena) con naturalidad — pero NO arranques todos los posts igual: EVITA empezar siempre con "wepa mi gente"; varía el saludo y el gancho.',
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
 * Preset de VOZ que el dueño elige en el onboarding → los 4 ejes de tono (0-100).
 * El dueño ya no depende de que la IA adivine el tono: escoge cómo suena su marca.
 * Devuelve null si el preset no existe (entonces se usa el tono que sugirió la IA).
 */
function preset_voz_a_tono(string $preset): ?array {
    $mapa = [
        // Formal y serio — abogados, ingenieros, médicos, contables, consultores. CERO jerga.
        'profesional' => ['tono_boricua'=>10, 'tono_formal'=>92, 'tono_venta'=>40, 'tono_ingenio'=>8],
        // Bien de la isla, con sabor y calle.
        'boricua'     => ['tono_boricua'=>85, 'tono_formal'=>25, 'tono_venta'=>55, 'tono_ingenio'=>65],
        // Con chispa, humor y giros inesperados.
        'creativo'    => ['tono_boricua'=>50, 'tono_formal'=>35, 'tono_venta'=>50, 'tono_ingenio'=>90],
        // Cercano y de confianza, como un amigo — sin jerga fuerte.
        'calido'      => ['tono_boricua'=>50, 'tono_formal'=>45, 'tono_venta'=>45, 'tono_ingenio'=>45],
        // Directo a la acción, con gancho de venta.
        'vendedor'    => ['tono_boricua'=>45, 'tono_formal'=>45, 'tono_venta'=>92, 'tono_ingenio'=>40],
    ];
    return $mapa[strtolower(trim($preset))] ?? null;
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
    // PRINCIPIO DE GROUNDING: el Creador solo puede ofrecer canales CONFIRMADOS en el
    // Business Genome. Nunca inferir DM/Instagram/WhatsApp/Facebook/teléfono/email si no
    // existen en los datos reales. (Antes se ofrecía "DM" sin Instagram → el Director lo
    // rechazaba como afirmación no respaldada. Ese era un bug de contrato Creador↔Director.)
    $wa = trim((string)($m['whatsapp']  ?? ''));
    $ig = trim((string)($m['instagram'] ?? ''));
    $fb = trim((string)($m['facebook']  ?? ''));
    $canales = [];
    if ($wa !== '') $canales['whatsapp'] = "WhatsApp al {$wa}";
    if ($ig !== '') $canales['dm']       = "mensaje directo en Instagram " . (str_starts_with($ig, '@') ? $ig : '@' . $ig);
    if ($fb !== '') $canales['facebook'] = "Facebook";
    // La preferencia solo puede elegir ENTRE los canales que existen.
    $pref = (string)($m['contacto_preferencia'] ?? '');
    if ($pref === 'whatsapp' && isset($canales['whatsapp']))    $canales = ['whatsapp' => $canales['whatsapp']];
    elseif ($pref === 'dm' && isset($canales['dm']))            $canales = ['dm' => $canales['dm']];
    if (!$canales) {
        return "- CIERRA invitando a la persona a escribirte, SIN nombrar ningún canal específico: este negocio no tiene "
             . "WhatsApp, Instagram, Facebook, teléfono ni email confirmados. PROHIBIDO inventar o inferir cualquier canal.\n";
    }
    return "- CIERRA con UN llamado a la acción usando SOLO estos canales CONFIRMADOS: " . implode(' o ', $canales)
         . ". PROHIBIDO mencionar cualquier otro canal (DM, Instagram, WhatsApp, Facebook, teléfono o email) que no esté en esa lista.\n";
}

// PRINCIPIO DE GROUNDING (producto): el Creador solo habla de lo que existe en el perfil.
function grounding_producto_instruccion(array $m): string {
    $raw = $m['productos'] ?? [];
    if (is_string($raw)) $raw = json_decode($raw, true) ?: [];
    $prods = [];
    foreach ((array)$raw as $p) {
        $nom = is_array($p) ? trim((string)($p['nombre'] ?? '')) : trim((string)$p);
        if ($nom !== '') $prods[] = rtrim($nom, ' :.-');
    }
    $lista  = $prods ? implode('; ', array_slice($prods, 0, 12)) : '';
    $oferta = trim((string)($m['ofertas'] ?? ''));
    // Frontera de HECHOS (no muro de estilo): declara qué es cierto; CÓMO se cuenta es libre.
    $out  = "- Hechos reales del negocio (no inventes otros; el estilo es tuyo). ";
    $out .= $lista  !== '' ? "Productos/servicios: {$lista}. " : "Sin productos declarados: no inventes ninguno. ";
    $out .= $oferta !== '' ? "Oferta: {$oferta}. "            : "Sin oferta declarada: no inventes promociones ni precios. ";
    $out .= "No inventes atributos, ingredientes ni años de experiencia que no estén en el perfil.\n";
    $out .= "- NO inventes afirmaciones factuales no provistas: demanda ('nos lo piden mucho'), escasez o disponibilidad "
          . "limitada ('se llenan rápido', 'quedan pocos'), popularidad, testimonios o reseñas, tradición o historia "
          . "familiar, premios, resultados, promociones, horarios, precios ni teléfonos que no estén en el perfil.\n";
    $out .= "- El NOMBRE del negocio es EXACTAMENTE «" . trim((string)($m['nombre_negocio'] ?? '')) . "»: úsalo tal cual. NO inventes "
          . "nombres alternativos, apodos ni lemas ('el verdadero…', 'los reyes de…'), ni hashtags con marcas inventadas; NO menciones "
          . "otro negocio, letrero, logo ni marca ajena — aunque aparezca en una foto. Los datos del perfil MANDAN sobre cualquier imagen.\n";
    $out .= "- Los productos/servicios son EXACTAMENTE los listados: NO inventes sabores, variantes, versiones ni presentaciones que no estén ahí.\n";
    $out .= "- SÍ eres libre de crear: imágenes verbales, ritmo, humor, emoción, contraste, curiosidad, personalidad y una "
          . "invitación atractiva — siempre sobre los HECHOS REALES.\n";
    return $out;
}

/**
 * AGENTE PLANIFICADOR. Le pide a Gemini un plan de contenido para el
 * mes y lo materializa: crea/actualiza crecer_calendario + N borradores
 * en crecer_contenido. Devuelve [calendario_id, piezas[], ia_log_id].
 *
 * @param int $n_piezas  cuántas piezas planificar (ej. 8 para un mes ligero)
 */
function planificar_mes(PDO $pdo, int $marca_id, int $anio, int $mes, int $n_piezas = 8, string $enfoque = ''): array {
    $m = leer_marca($pdo, $marca_id);
    $ctx = cerebro_negocio($pdo, (int)$m['id'], $m);   // el CEREBRO del negocio permea cada decisión

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
    // Lo que el dueño le enseñó al corillo (fechas especiales, ofertas, prioridades)
    // manda en el plan: se inyecta como memoria del negocio.
    // (la memoria aprendida ya viene dentro del cerebro_negocio, en el contexto)

    $enfoque = trim($enfoque);
    $prompt = "Perfil del negocio:\n{$ctx}\n\n"
        . ($enfoque !== '' ? "ENFOQUE DE LA SEMANA (lo fijó la Estratega — alinea las piezas a esto):\n\"{$enfoque}\"\n\n" : '')
        . "Diseña la estrategia de {$n_piezas} piezas para el mes {$mes}/{$anio}, con pilares variados.\n"
        . "Devuelve un JSON con esta forma EXACTA:\n"
        . '{"piezas":[{"dia":1,"plataforma":"instagram","tipo":"post","pilar":"producto","tema":"...","idea":"..."}]}'
        . "\n- dia: número 1-28.\n- plataforma: instagram|facebook.\n- tipo: post|story|reel.\n"
        . "- pilar: producto|proceso|prueba_social|tip|promo|temporada|pregunta.\n"
        . "- tema: 2-4 palabras.\n"
        . "- idea: 1-2 oraciones ESPECÍFICAS con el gancho (qué se ve y por qué engancha). Concreta, de este negocio.";

    // Reintento acotado: si el modelo devuelve JSON truncado/inválido (pasa a veces), reintenta.
    // Crítico para el corillo AUTÓNOMO: un fallo transitorio no debe dejar la semana sin posts.
    $plan = null; $piezas = [];
    for ($try = 1; $try <= 3; $try++) {
        $r = ia_ejecutar($pdo, 'planificador', "Planificar {$n_piezas} piezas {$mes}/{$anio}", $prompt, [
            'marca_id'   => $marca_id,
            'sistema'    => $sistema,
            'json'       => true,
            'temperatura'=> 0.8,
            'max_tokens' => 6144,        // margen para que NO se trunque (truncar = JSON inválido)
            'thinking_budget' => 0,      // tarea estructurada: sin pensamiento, JSON completo
            'mock_texto' => '{"piezas":[{"dia":5,"plataforma":"instagram","tipo":"post","tema":"Producto estrella","idea":"Foto del bizcocho de guayaba con CTA por WhatsApp."}]}',
        ]);
        $plan = json_decode((string)$r['texto'], true);
        $piezas = $plan['piezas'] ?? [];
        if ($piezas) break;              // JSON válido con piezas → listo
        error_log("planificar_mes: intento {$try}/3 sin piezas (JSON truncado/inválido) marca={$marca_id}");
    }
    if (!$piezas) throw new RuntimeException("El planificador no devolvió piezas tras 3 intentos. Respuesta: " . substr((string)$r['texto'], 0, 300));
    $piezas = array_slice($piezas, 0, max(1, $n_piezas));   // no materializar de más (controla el costo de imágenes)

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

/**
 * EL CEREBRO DEL NEGOCIO (Business Genome, permanente). El entendimiento VIVO de
 * quién es este negocio — su identidad, la voz REAL del dueño, su línea visual, y
 * TODO lo que el corillo ha aprendido (Consejo, ediciones, lo que funciona). Es la
 * terminal a la que TODO agente se conecta antes de decidir: el ADN que permea cada
 * post, estrategia e imagen. No se queda en el onboarding — vive en cada movida.
 * DB-only (barato): se puede llamar en cada agente sin costo de IA.
 */
function cerebro_negocio(PDO $pdo, int $marca_id, ?array $m = null): string {
    $m = $m ?? leer_marca($pdo, $marca_id);
    $b = "🧠 EL NEGOCIO (su ADN — respétalo en toda decisión):\n" . marca_contexto($m);
    $voz = trim((string)($m['voz'] ?? '') ?: (string)($m['descripcion'] ?? ''));
    if ($voz !== '') $b .= "\nLa voz REAL del dueño (su esencia — captúrala, no la calques literal): \"" . mb_substr($voz, 0, 450) . "\"";
    $linea = trim((string)($m['estilo_visual'] ?? ''));
    if ($linea !== '') $b .= "\nLínea visual del negocio (mantén esta familia en las imágenes): {$linea}";
    if (function_exists('memoria_para_prompt')) {
        $mem = trim((string)memoria_para_prompt($pdo, $marca_id));
        if ($mem !== '') $b .= "\n" . $mem;
    }
    return $b;
}

/**
 * LA RADIOGRAFÍA DEL NEGOCIO — el Business Genome REDACTA las reglas del negocio en
 * CAPÍTULOS, uno por agente (identidad, imagen, voz, estrategia, personalidad). Cada
 * agente lee SU capítulo → todos quedan alineados con el mismo ADN y hacen tareas
 * COHERENTES (ej.: el de imágenes sabe que un negocio de jabones NO hace bubble tea).
 * Se construye UNA vez con IA (a partir del cerebro) y se CACHEA en crecer_marca.
 * radiografia_json → después es DB-only (barato). Rebuild con $forzar=true.
 */
function genoma_radiografia(PDO $pdo, int $marca_id, bool $forzar = false): array {
    static $cache = [];
    if (!$forzar && isset($cache[$marca_id])) return $cache[$marca_id];
    $m = leer_marca($pdo, $marca_id);
    if (!$forzar) {
        $j = json_decode((string)($m['radiografia_json'] ?? ''), true);
        if (is_array($j) && !empty($j)) return $cache[$marca_id] = $j;
    }
    $ctx = cerebro_negocio($pdo, $marca_id, $m);
    $sys = "Eres EL BUSINESS GENOME de Crecer: el cerebro que conoce este negocio a fondo. Redacta la RADIOGRAFÍA del "
        . "negocio: reglas CLARAS y CONCRETAS por capítulo, una para cada agente del corillo, para que TODOS trabajen "
        . "alineados y coherentes. Usa SOLO los datos reales (no inventes). Cada capítulo, corto y accionable. Responde SOLO JSON:\n"
        . '{"identidad":"1-2 frases: qué ES el negocio, qué vende, su esencia",'
        . '"reglas_imagen":"reglas para el que crea las IMÁGENES: el mundo visual REAL del negocio, qué mostrar SIEMPRE, qué NUNCA mostrar (industrias ajenas), aclara trampas del nombre, paleta y mood",'
        . '"reglas_voz":"reglas para el ESCRITOR: cómo habla, vocabulario propio, muletillas a usar/evitar, nivel de formalidad",'
        . '"reglas_estrategia":"reglas para la ESTRATEGA: público objetivo, qué le vende, ángulos que funcionan, fechas/temporadas clave",'
        . '"personalidad":"para el PROVOCADOR: la personalidad de marca y hasta dónde ser atrevido SIN salirse del negocio"}';
    $prompt = "Perfil real del negocio:\n{$ctx}\n\nRedacta la radiografía por capítulos (reglas concretas, no descripciones vagas).";
    try {
        $r = ia_ejecutar($pdo, 'genoma', 'Radiografía del negocio', $prompt, [
            'marca_id' => $marca_id, 'sistema' => $sys, 'json' => true, 'modelo' => CRECER_COPILOTO_MODEL,
            'temperatura' => 0.5, 'max_tokens' => 950, 'thinking_budget' => 0, 'mock_texto' => '{}',
        ]);
        $j = json_decode((string)$r['texto'], true);
        if (is_array($j) && !empty($j)) {
            try { $pdo->prepare("UPDATE crecer_marca SET radiografia_json=? WHERE id=?")
                    ->execute([json_encode($j, JSON_UNESCAPED_UNICODE), $marca_id]); }
            catch (Throwable $e) { /* columna radiografia_json aún no migrada → se reconstruye por request */ }
            return $cache[$marca_id] = $j;
        }
    } catch (Throwable $e) { error_log('genoma_radiografia: ' . $e->getMessage()); }
    return $cache[$marca_id] = [];
}

/** Devuelve el capítulo de la radiografía dedicado a un agente (o '' si no hay). */
function radiografia_capitulo(PDO $pdo, int $marca_id, string $cap): string {
    $r = genoma_radiografia($pdo, $marca_id);
    return trim((string)($r[$cap] ?? ''));
}

/**
 * LA ENTREVISTA (adaptativa). Un agente entrevista al dueño con preguntas que
 * DEPENDEN de lo que va diciendo (no un guion fijo): si vende jabones pregunta de
 * jabones. Devuelve la SIGUIENTE pregunta, o done=true cuando ya tiene material.
 * @return array{done:bool, pregunta:string}
 */
function entrevista_siguiente(PDO $pdo, int $marca_id, array $historial): array {
    $m = leer_marca($pdo, $marca_id);
    $nombre = trim((string)($m['nombre_negocio'] ?? ''));

    // ── TOPE DURO en código (no confiar en que el modelo pare solo) ──────────────
    // La gente se harta si la entrevista no acaba. Contamos respuestas ÚTILES del
    // dueño y forzamos el cierre; también detectamos "no tengo más que decir".
    $MIN = 3;   // no cerrar antes de esto (necesitamos lo esencial para el Genome)
    $MAX = 5;   // NUNCA más preguntas que esto, pase lo que pase
    $n_user = 0; $ultimo = '';
    foreach ($historial as $h) {
        if (($h['rol'] ?? '') === 'user') {
            $t = trim((string)($h['texto'] ?? ''));
            if ($t !== '') { $n_user++; $ultimo = $t; }
        }
    }
    // ¿El dueño acaba de decir que no tiene nada más? (respuesta corta y negativa)
    $u = mb_strtolower($ultimo, 'UTF-8');
    $cierra_dueno = false;
    if (mb_strlen($u, 'UTF-8') <= 30) {
        foreach (['no','nada','ya','eso es todo','es todo','listo','ninguna','ninguno',
                  'asi mismo','así mismo','por ahora no','creo que no','nope','ok','na'] as $stop) {
            if (strpos($u, $stop) !== false) { $cierra_dueno = true; break; }
        }
    }
    if ($n_user >= $MAX) return ['done' => true, 'pregunta' => ''];
    if ($n_user >= $MIN && $cierra_dueno) return ['done' => true, 'pregunta' => ''];

    $restantes = max(0, $MAX - $n_user);   // preguntas que aún caben
    $sys = "Eres un consultor cálido y EFICIENTE que entrevista a un microempresario boricua. Haz UNA sola pregunta a la "
        . "vez, ADAPTADA a lo que ya te dijo (si vende jabones, pregúntale de sus jabones). Boricua suave y cercano. "
        . "REGLA CLAVE: cada pregunta debe ser de RESPUESTA CORTA o de SÍ/NO — nada de pedir que escriba párrafos ni su "
        . "'historia'. Cuando puedas, PROPÓN tú y que el dueño solo confirme (ej: 'Vendes jabones artesanales, ¿cierto?', "
        . "'¿Tu fuerte son las bodas o el día a día?'). En 3 a 5 preguntas debes captar lo esencial: qué vende, a quién y "
        . "qué lo hace distinto. NO interrogues: {$restantes} preguntas máximo. En cuanto tengas lo esencial, TERMINA "
        . "(done=true) — mejor cerrar temprano que cansar al dueño. NUNCA repitas un tema ni preguntes 'algo más' dos veces. "
        . "Responde SOLO JSON: {\"done\":true|false,\"pregunta\":\"la siguiente (vacío si done)\"}.";
    // ia_ejecutar NO soporta 'historial' → la conversación va DENTRO del prompt (si no, repite).
    $conv = '';
    foreach ($historial as $h) {
        $txt = trim((string)($h['texto'] ?? '')); if ($txt === '') continue;
        $conv .= (($h['rol'] ?? '') === 'user' ? 'DUEÑO: ' : 'TÚ (entrevistador): ') . $txt . "\n";
    }
    $prompt = ($nombre !== '' ? "El negocio se llama \"{$nombre}\".\n\n" : '')
        . "Conversación hasta ahora:\n" . ($conv !== '' ? $conv : '(aún no empieza)') . "\n"
        . "El dueño ya te dio {$n_user} respuesta(s). Te quedan {$restantes} preguntas como MÁXIMO. "
        . "Haz la SIGUIENTE pregunta SOLO si de verdad falta algo esencial; NUNCA repitas un tema. "
        . "Si ya entiendes qué vende, a quién y qué lo hace distinto, TERMINA ya (done=true, pregunta vacía).";
    try {
        $r = ia_ejecutar($pdo, 'intake', 'Entrevista: siguiente pregunta', $prompt, [
            'marca_id' => $marca_id, 'sistema' => $sys, 'json' => true, 'modelo' => CRECER_COPILOTO_MODEL,
            'temperatura' => 0.7, 'max_tokens' => 220, 'thinking_budget' => 0,
            'mock_texto' => '{"done":false,"pregunta":"Cuéntame, ¿qué es exactamente lo que haces o vendes?"}',
        ]);
        $j = json_decode((string)$r['texto'], true) ?: [];
        $preg = trim((string)($j['pregunta'] ?? ''));
        // Si el modelo dice done, o ya cubrimos el mínimo y no dio pregunta → cerrar.
        if (!empty($j['done']) || ($preg === '' && $n_user >= $MIN)) return ['done' => true, 'pregunta' => ''];
        if ($preg === '') return ['done' => false, 'pregunta' => '¿Hay algo más que deba saber de tu negocio antes de empezar?'];
        return ['done' => false, 'pregunta' => $preg];
    } catch (Throwable $e) {
        // Ante fallo, no dejar el chat colgado: si ya hay lo mínimo, cerrar.
        if ($n_user >= $MIN) return ['done' => true, 'pregunta' => ''];
        return ['done' => false, 'pregunta' => 'Cuéntame, ¿qué es exactamente lo que haces o vendes?'];
    }
}

/**
 * Cierra la ENTREVISTA: el Genome escribe el PERFIL completo del negocio a partir
 * de las respuestas, lo GUARDA (descripción, voz, productos, público, ofertas) y
 * dispara la RADIOGRAFÍA con el perfil nuevo. Basura in = basura out: aquí entra lo rico.
 * @return array{ok:bool, descripcion:string}
 */
function entrevista_finalizar(PDO $pdo, int $marca_id, array $historial): array {
    $m = leer_marca($pdo, $marca_id);
    $conv = '';
    foreach ($historial as $h) {
        $t = trim((string)($h['texto'] ?? '')); if ($t === '') continue;
        $conv .= (($h['rol'] ?? '') === 'user' ? 'DUEÑO: ' : 'CORILLO: ') . $t . "\n";
    }
    $sys = "De esta ENTREVISTA a un microempresario boricua, arma su PERFIL completo. NO inventes el contenido: usa SOLO lo "
        . "que dijo (el TONO sí lo eliges TÚ según el tipo de negocio). "
        . "Responde SOLO JSON: {\"descripcion\":\"descripción rica del negocio en 3-5 frases, tercera persona\","
        . "\"voz\":\"cómo habla el dueño, en SUS palabras (cita frases suyas)\",\"productos\":[\"...\"],"
        . "\"publico\":\"quién le compra\",\"ofertas\":\"promos/servicios si los mencionó, o vacío\","
        . "\"tono_boricua\":0-100,\"tono_formal\":0-100,\"tono_venta\":0-100,\"tono_ingenio\":0-100}.\n"
        . tono_prompt_intake();
    $prompt = ($m['nombre_negocio'] ? "Negocio: {$m['nombre_negocio']}\n" : '') . "Entrevista:\n{$conv}\n\nArma el perfil.";
    try {
        $r = ia_ejecutar($pdo, 'intake', 'Entrevista: armar perfil', $prompt, [
            'marca_id' => $marca_id, 'sistema' => $sys, 'json' => true, 'modelo' => CRECER_COPILOTO_MODEL,
            'temperatura' => 0.5, 'max_tokens' => 750, 'thinking_budget' => 0, 'mock_texto' => '{}',
        ]);
        $j = json_decode((string)$r['texto'], true) ?: [];
        $desc = trim((string)($j['descripcion'] ?? ''));
        $voz  = trim((string)($j['voz'] ?? ''));
        $pub  = trim((string)($j['publico'] ?? ''));
        $ofe  = trim((string)($j['ofertas'] ?? ''));
        $prods = array_values(array_filter(array_map(fn($x) => trim((string)$x), (array)($j['productos'] ?? [])), fn($x) => $x !== ''));
        $set = []; $vals = [];
        if ($desc !== '') { $set[] = 'descripcion=?'; $vals[] = $desc; }
        if ($voz  !== '') { $set[] = 'voz=?'; $vals[] = $voz; }
        if ($pub  !== '') { $set[] = 'publico_objetivo=?'; $vals[] = $pub; }
        if ($ofe  !== '') { $set[] = 'ofertas=?'; $vals[] = $ofe; }
        if ($prods)       { $set[] = 'productos=?'; $vals[] = json_encode($prods, JSON_UNESCAPED_UNICODE); }
        if ($set) { $vals[] = $marca_id; $pdo->prepare("UPDATE crecer_marca SET " . implode(',', $set) . " WHERE id=?")->execute($vals); }
        // TONO inicial que la IA eligió por TIPO de negocio → el primer post sale en
        // el tono correcto. Sin esto la columna queda en su default 80 (bien boricua)
        // y TODO sale "wepa mi gente". Migración-safe: si las columnas no existen, se ignora.
        if (isset($j['tono_boricua'], $j['tono_formal'], $j['tono_venta'], $j['tono_ingenio'])) {
            $c = fn($x) => max(0, min(100, (int)$x));
            try {
                $pdo->prepare("UPDATE crecer_marca SET tono_boricua=?, tono_formal=?, tono_venta=?, tono_ingenio=? WHERE id=?")
                    ->execute([$c($j['tono_boricua']), $c($j['tono_formal']), $c($j['tono_venta']), $c($j['tono_ingenio']), $marca_id]);
            } catch (Throwable $e) { /* columnas de tono no migradas: usa el default */ }
        }
        // Preset de voz SUGERIDO (para pre-seleccionar en el selector de tono al cierre).
        $tb = (int)($j['tono_boricua'] ?? 60); $tf = (int)($j['tono_formal'] ?? 40);
        $tv = (int)($j['tono_venta'] ?? 50);   $tg = (int)($j['tono_ingenio'] ?? 50);
        $preset = 'calido';
        if ($tf >= 65 && $tb <= 40)  $preset = 'profesional';
        elseif ($tg >= 72)           $preset = 'creativo';
        elseif ($tb >= 65)           $preset = 'boricua';
        elseif ($tv >= 72)           $preset = 'vendedor';
        try { genoma_radiografia($pdo, $marca_id, true); } catch (Throwable $e) {}   // reconstruye la radiografía con el perfil rico
        return ['ok' => true, 'descripcion' => $desc, 'voz' => $voz, 'publico' => $pub, 'preset' => $preset];
    } catch (Throwable $e) {
        error_log('entrevista_finalizar: ' . $e->getMessage());
        return ['ok' => false, 'descripcion' => ''];
    }
}

/** Crea el POST DE MUESTRA de bienvenida (caption + imagen), ya aterrizado en el
 *  perfil/radiografía recién armados por la entrevista. Devuelve el contenido_id. */
function crear_post_muestra(PDO $pdo, int $marca_id): int {
    // IDEMPOTENTE: 1 post por marca en el gateway. Si YA hay uno (cualquier estado),
    // devuélvelo — NUNCA crear otro. Mata el abuso de 'back' en el browser → posts infinitos.
    $ya = (int)$pdo->query("SELECT id FROM crecer_contenido WHERE marca_id={$marca_id} ORDER BY id DESC LIMIT 1")->fetchColumn();
    if ($ya) return $ya;
    $ca = (int)date('Y'); $cm = (int)date('n');
    $pdo->prepare("INSERT INTO crecer_calendario (marca_id,anio,mes,estado,generado_por_ia) VALUES (?,?,?, 'borrador',1) ON DUPLICATE KEY UPDATE updated_at=NOW()")->execute([$marca_id, $ca, $cm]);
    $calid = (int)$pdo->query("SELECT id FROM crecer_calendario WHERE marca_id={$marca_id} AND anio={$ca} AND mes={$cm}")->fetchColumn();
    $pdo->prepare("INSERT INTO crecer_contenido (calendario_id,marca_id,plataforma,tipo,caption,fecha_programada,estado) VALUES (?,?, 'instagram','post',?,?, 'borrador')")
        ->execute([$calid, $marca_id, 'Post de bienvenida: preséntale el negocio a la gente, cálido y boricua', date('Y-m-d 10:00:00')]);
    $cid = (int)$pdo->lastInsertId();
    $cap = '';
    try { $r = redactar_pieza($pdo, $cid); $cap = (string)($r['caption'] ?? ''); } catch (Throwable $e) {}
    // MOTOR RESPONSES (background): encola el anuncio y devuelve YA; la imagen llega por
    // polling en el gateway (sin colgar la página ~50s). Fallback al motor viejo si falla.
    require_once __DIR__ . '/img_responses.php';
    if (img_resp_activo() && img_resp_encolar($pdo, $marca_id, $cid, $cap) !== '') {
        return $cid;
    }
    try {
        $g = generar_grafica($pdo, $marca_id, null, ['copy' => $cap, 'con_texto' => false, 'con_logo' => false]);
        if (!empty($g['archivo'])) $pdo->prepare("UPDATE crecer_contenido SET grafica_path=? WHERE id=?")->execute([$g['archivo'], $cid]);
    } catch (Throwable $e) {}
    return $cid;
}

/**
 * LA MESA DEL CORILLO — debate creativo para que el post salga ATREVIDO, no
 * genérico. EL PROVOCADOR (creativo guerrillero, vanguardista) lanza 3 ángulos
 * audaces para ESTE negocio y su público; LA ESTRATEGA elige el que mejor le
 * pega al público objetivo y lo afila en un BRIEF concreto para el escritor.
 * Ambos loguean → el dueño ve a su equipo pensando (evidencia XPRIZE #2).
 *
 * REGLA DE ORO: proponen ÁNGULOS y GANCHOS creativos, NUNCA inventan hechos del
 * negocio (precios, productos, promesas). Atrevido en la FORMA, honesto en el
 * FONDO — el grounding lo siguen cuidando el escritor y el editor.
 *
 * @return array{brief:string, angulos:array, elegido:string, razon:string}
 *   brief='' si el debate falló → el escritor sigue con la idea original.
 */
function debate_creativo(PDO $pdo, int $marca_id, string $idea, string $plataforma = '', string $tipo = 'post'): array {
    $vacio = ['brief' => '', 'visual' => '', 'angulos' => [], 'elegido' => '', 'razon' => ''];
    $idea = trim($idea);
    if ($idea === '') return $vacio;
    try {
        $m = leer_marca($pdo, $marca_id);
        $ctx = cerebro_negocio($pdo, (int)$m['id'], $m);   // el CEREBRO del negocio permea cada decisión

        // ── 1) EL PROVOCADOR: 3 ángulos audaces ──
        $sysProv = "Eres EL PROVOCADOR de Crecer: un creativo publicitario GUERRILLERO y vanguardista que domina "
            . "todo tipo de marketing (guerrilla, disruptivo, FOMO/escasez, storytelling, emocional, meme, nostalgia, "
            . "contraste, prueba social) y sabe cómo llegarle al público REAL de un negocio boricua. Tu trabajo: lanzar "
            . "3 ÁNGULOS distintos y ATREVIDOS para este post — ganchos que FRENEN el scroll, nada tibio ni genérico. "
            . "Cada ángulo = una táctica + un gancho concreto para ESTE negocio y su público.\n"
            . "REGLA DE ORO: propones ENFOQUES y GANCHOS, NUNCA inventas hechos del negocio (precios, productos, "
            . "promesas que no existen). Atrevido en la FORMA, honesto en el FONDO. Responde SOLO JSON.";
        $__pers = radiografia_capitulo($pdo, $marca_id, 'personalidad');
        if ($__pers !== '') $sysProv .= "\nPERSONALIDAD DE MARCA (Business Genome — hasta aquí puedes ser atrevido sin salirte del negocio): {$__pers}";
        $promProv = "Negocio:\n{$ctx}\n\nPlataforma: {$plataforma} · Tipo: {$tipo}\nIdea base del post: \"{$idea}\"\n\n"
            . 'Devuelve JSON EXACTO: {"angulos":[{"tactica":"nombre corto","gancho":"el ángulo/gancho en 1 frase","porque_pega":"por qué le llega a ESTE público","visual":"una IMAGEN concreta y ATREVIDA para este ángulo — qué se ve, encuadre y mood; creativa, NO la foto obvia del producto"}]} con 3 ángulos DISTINTOS y audaces.';
        $rp = ia_ejecutar($pdo, 'provocador', 'Lanzar ángulos audaces', $promProv, [
            'marca_id' => $marca_id, 'sistema' => $sysProv, 'json' => true,
            'modelo' => CRECER_COPILOTO_MODEL, 'temperatura' => 1.0, 'max_tokens' => 750, 'thinking_budget' => 0,
            'mock_texto' => '{"angulos":[{"tactica":"Escasez","gancho":"Solo 10 esta semana","porque_pega":"lo que se acaba mueve al boricua","visual":"Un reloj de arena hecho de harina cayendo sobre una bandeja, dramático, luz lateral"},{"tactica":"Nostalgia","gancho":"Como los de abuela","porque_pega":"conecta con la memoria","visual":"Manos arrugadas y jóvenes partiendo el mismo bizcocho, blanco y negro cálido"},{"tactica":"Reto","gancho":"Te reto a que no repitas","porque_pega":"la gente comparte retos","visual":"Primerísimo plano de una mordida, migajas volando, fondo de color vibrante"}]}',
        ]);
        $angulos = json_decode((string)$rp['texto'], true)['angulos'] ?? [];
        if (!$angulos) return $vacio;

        // ── 2) LA ESTRATEGA: elige el ganador y lo afila en un brief ──
        $lista = '';
        foreach ($angulos as $i => $a) {
            $lista .= ($i + 1) . ") [" . ($a['tactica'] ?? '') . "] " . ($a['gancho'] ?? '') . " — " . ($a['porque_pega'] ?? '') . "\n";
        }
        $sysEst = "Eres LA ESTRATEGA de Crecer. De estos ángulos, ELIGE el que mejor le pega al público objetivo de "
            . "ESTE negocio y conviértelo en un BRIEF corto y concreto para el escritor: qué gancho usar, qué emoción "
            . "disparar y qué debe lograr el post. Atrevido pero aterrizado; no inventes datos. Responde SOLO JSON: "
            . '{"elegido":N,"razon":"por qué este gana","brief":"instrucción concreta para el escritor"}.';
        $__est = radiografia_capitulo($pdo, $marca_id, 'reglas_estrategia');
        if ($__est !== '') $sysEst .= "\nREGLAS DE ESTRATEGIA DEL NEGOCIO (Business Genome — público y ángulos que funcionan): {$__est}";
        $promEst = "Negocio:\n{$ctx}\n\nIdea base: \"{$idea}\"\n\nÁngulos que lanzó el Provocador:\n{$lista}\n¿Cuál gana y por qué? Dame el brief.";
        $re = ia_ejecutar($pdo, 'estratega', 'Elegir el ángulo ganador', $promEst, [
            'marca_id' => $marca_id, 'sistema' => $sysEst, 'json' => true,
            'modelo' => CRECER_COPILOTO_MODEL, 'temperatura' => 0.6, 'max_tokens' => 400, 'thinking_budget' => 0,
            'mock_texto' => '{"elegido":1,"razon":"la escasez jala compras rápidas","brief":"Empuja el gancho de escasez con urgencia real y CTA por WhatsApp. Sin inventar cantidades que el dueño no dijo."}',
        ]);
        $dec = json_decode((string)$re['texto'], true) ?: [];
        $idx = max(1, min(count($angulos), (int)($dec['elegido'] ?? 1))) - 1;
        $elegido = trim(($angulos[$idx]['tactica'] ?? '') . ': ' . ($angulos[$idx]['gancho'] ?? ''));
        $brief = trim((string)($dec['brief'] ?? ''));
        if ($brief === '') $brief = trim((string)($angulos[$idx]['gancho'] ?? ''));

        return [
            'brief'   => $brief,
            'visual'  => (is_string($__v = ($angulos[$idx]['visual'] ?? '')) ? trim($__v) : ''),   // concepto de imagen del ángulo ganador
            'angulos' => $angulos,
            'elegido' => $elegido,
            'razon'   => trim((string)($dec['razon'] ?? '')),
        ];
    } catch (Throwable $e) {
        error_log('debate_creativo: ' . $e->getMessage());
        return $vacio;
    }
}

/**
 * EL DIRECTOR CREATIVO (crítico exigente). Mira el caption ya escrito y, con vara
 * ALTA, decide si está a la altura o le falta punch. Si le falta, da UNA nota
 * concreta y el escritor lo SUBE una vez (sin perder voz ni inventar datos). Es el
 * "kick": la parte que se niega a lo genérico. Devuelve ['caption', 'nota'].
 */
function criticar_y_afinar(PDO $pdo, int $marca_id, string $caption, string $brief, string $sistema_escritor, string $ctx = ''): array {
    $caption = trim($caption);
    if ($caption === '') return ['caption' => $caption, 'nota' => ''];
    try {
        $sysC = "Eres EL DIRECTOR CREATIVO de Crecer, exigente de verdad. Juzga este caption con vara ALTA: ¿frena el "
            . "scroll?, ¿el gancho es fuerte y ESPECÍFICO (no genérico ni tibio)?, ¿suena a persona boricua real (no a "
            . "IA)?, ¿cumple el brief?, ¿da ganas de comprar o compartir? Si YA está a la altura, responde EXACTO: OK. "
            . "Si NO, responde en UNA sola frase la nota más importante para subirlo (qué cambiar para que pegue más). "
            . "No reescribas tú. No inventes datos del negocio.";
        $promC = ($brief !== '' ? "Brief del corillo: {$brief}\n\n" : '') . "Caption a juzgar:\n\"{$caption}\"\n\n¿OK, o cuál es la nota?";
        $rc = ia_ejecutar($pdo, 'editor', 'Criticar el post', $promC, [
            'marca_id' => $marca_id, 'sistema' => $sysC, 'modelo' => CRECER_COPILOTO_MODEL,
            'temperatura' => 0.5, 'max_tokens' => 160, 'thinking_budget' => 0, 'mock_texto' => 'OK',
        ]);
        $nota = trim((string)$rc['texto']);
        // Pasa la vara SOLO si respondió exactamente "OK" (le pedí eso). Cualquier otra
        // cosa es una nota (antes "Ok, pero…" pasaba por error, y notas cortas se botaban).
        if ($nota === '' || preg_match('/^\s*ok[.!]?\s*$/i', $nota)) {
            return ['caption' => $caption, 'nota' => ''];
        }
        // UNA revisión: el escritor lo sube atendiendo la nota, con su voz Y con los HECHOS
        // del negocio delante (para NO inventar datos ni romper el CTA en la reescritura).
        $rr = ia_ejecutar($pdo, 'creador', 'Afinar el post (nota del Director)',
            ($ctx !== '' ? "Negocio (estos son los HECHOS — no inventes nada fuera de esto):\n{$ctx}\n\n" : '')
            . "Tu caption:\n\"{$caption}\"\n\nEl Director Creativo te dice: \"{$nota}\"\n\nReescríbelo SUBIÉNDOLO con esa "
            . "nota: más cabrón, más específico y más humano — misma voz, mismos datos reales, sin inventar nada, y "
            . "conserva el llamado a la acción y los hashtags. Devuelve SOLO el caption.", [
            'marca_id' => $marca_id, 'sistema' => $sistema_escritor,
            'temperatura' => 0.95, 'max_tokens' => 420, 'thinking_budget' => 0, 'mock_texto' => $caption,
        ]);
        $mejor = trim((string)$rr['texto']);
        if (function_exists('_limpiar_cta_rota')) $mejor = _limpiar_cta_rota($mejor);   // mata CTA colgante ("al .")
        return ['caption' => ($mejor !== '' ? $mejor : $caption), 'nota' => $nota];
    } catch (Throwable $e) {
        error_log('criticar_y_afinar: ' . $e->getMessage());
        return ['caption' => $caption, 'nota' => ''];
    }
}

/** Arma la CONVERSACIÓN del corillo (para mostrarla al dueño y guardarla). null si no hubo debate. */
function corillo_conversacion(array $debate, array $crit = []): ?array {
    if (empty($debate['angulos'])) return null;
    $angs = [];
    foreach ($debate['angulos'] as $a) {
        $angs[] = [
            'tactica' => (string)($a['tactica'] ?? ''),
            'gancho'  => (string)($a['gancho'] ?? ''),
            'porque'  => (string)($a['porque_pega'] ?? ''),
            'visual'  => (string)($a['visual'] ?? ''),
        ];
    }
    return [
        'angulos' => $angs,
        'elegido' => (string)($debate['elegido'] ?? ''),
        'razon'   => (string)($debate['razon'] ?? ''),
        'visual'  => (string)($debate['visual'] ?? ''),
        'nota'    => (string)($crit['nota'] ?? ''),
    ];
}

/** Guarda la conversación del corillo en la pieza (best-effort; si falta la columna, se ignora). */
function corillo_guardar(PDO $pdo, int $contenido_id, ?array $corillo): void {
    if (!$corillo) return;
    try {
        $pdo->prepare("UPDATE crecer_contenido SET corillo_json = ? WHERE id = ?")
            ->execute([json_encode($corillo, JSON_UNESCAPED_UNICODE), $contenido_id]);
    } catch (Throwable $e) { /* columna corillo_json aún no migrada */ }
}

function redactar_pieza(PDO $pdo, int $contenido_id, array $extra = []): array {
    $c = $pdo->prepare("SELECT * FROM crecer_contenido WHERE id = ?");
    $c->execute([$contenido_id]);
    $pieza = $c->fetch();
    if (!$pieza) throw new RuntimeException("Contenido #$contenido_id no existe.");

    $m = leer_marca($pdo, (int)$pieza['marca_id']);
    $ctx = cerebro_negocio($pdo, (int)$m['id'], $m);   // el CEREBRO del negocio permea cada decisión
    $idea = $pieza['caption']; // en el borrador, el caption guarda la IDEA del plan
    // LA MESA DEL CORILLO decide el ángulo atrevido ANTES de escribir.
    $debate = debate_creativo($pdo, (int)$pieza['marca_id'], (string)$idea, (string)$pieza['plataforma'], (string)$pieza['tipo']);

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
    $__rv = radiografia_capitulo($pdo, (int)$m['id'], 'reglas_voz');
    if ($__rv !== '') $sistema .= "\n📖 REGLAS DE VOZ DEL NEGOCIO (las escribió el Business Genome — síguelas): {$__rv}";
    // (la memoria aprendida ya viene dentro del cerebro_negocio, en el contexto)

    $prompt = "Perfil del negocio:\n{$ctx}\n\n"
        . "Plataforma: {$pieza['plataforma']} | Tipo: {$pieza['tipo']}\n"
        . "Idea de esta pieza: {$idea}\n";
    if ($debate['brief'] !== '') {
        $prompt .= "\n🔥 BRIEF DEL CORILLO (síguelo — dale ESTE ángulo atrevido, sin inventar datos del negocio):\n"
                 . $debate['brief'] . "\n";
    }
    $prompt .= "\nEscribe el caption.";

    $r = ia_ejecutar($pdo, 'creador', "Redactar caption pieza #{$contenido_id}", $prompt, array_merge([
        'marca_id'    => (int)$pieza['marca_id'],
        'sistema'     => $sistema,
        'temperatura' => 0.95,
        'max_tokens'  => 400,
        'thinking_budget' => 0,
        'mock_texto'  => "[MOCK] Caption para: {$idea}",
    ], $extra));
    $caption = trim((string)$r['texto']);

    // EL DIRECTOR CREATIVO sube la vara: una revisión si el caption salió genérico.
    $crit = criticar_y_afinar($pdo, (int)$pieza['marca_id'], $caption, $debate['brief'], $sistema, $ctx);
    $caption = $crit['caption'];

    $pdo->prepare("UPDATE crecer_contenido SET caption = ?, ia_log_id = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$caption, $r['ia_log_id'], $contenido_id]);
    $corillo = corillo_conversacion($debate, $crit);
    corillo_guardar($pdo, $contenido_id, $corillo);   // best-effort (columna corillo_json)

    return ['caption' => $caption, 'ia_log_id' => $r['ia_log_id'], 'costo' => $r['costo'], 'debate' => $debate, 'corillo' => $corillo];
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
    $ctx = cerebro_negocio($pdo, (int)$m['id'], $m);   // el CEREBRO del negocio permea cada decisión

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
    $__rv = radiografia_capitulo($pdo, (int)$m['id'], 'reglas_voz');
    if ($__rv !== '') $sistema .= "\n📖 REGLAS DE VOZ DEL NEGOCIO (las escribió el Business Genome — síguelas): {$__rv}";
    // (la memoria aprendida ya viene dentro del cerebro_negocio, en el contexto)

    $prompt = "Perfil del negocio:\n{$ctx}\n\n"
        . "Plataforma: {$pieza['plataforma']} | Tipo: {$pieza['tipo']}\n";
    // La mesa del corillo solo entra en TEMA nuevo; si el dueño trajo su borrador, se respeta su voz.
    $debate = ['brief' => '', 'visual' => '', 'angulos' => [], 'elegido' => '', 'razon' => ''];
    if (trim($borrador) !== '') {
        $prompt .= "El DUEÑO escribió este BORRADOR del post. MEJÓRALO: corrige, pule y dale chispa "
                 . "boricua, pero RESPETA su intención y sus datos (precios, fechas, productos). "
                 . "No lo cambies por completo ni inventes datos.\n\nBORRADOR DEL DUEÑO:\n\"{$borrador}\"\n";
        if (trim($tema) !== '') $prompt .= "Tema/contexto extra: {$tema}\n";
    } else {
        $prompt .= "El DUEÑO pidió un post sobre este TEMA específico: \"{$tema}\".\n";
        $debate = debate_creativo($pdo, (int)$pieza['marca_id'], (string)$tema, (string)$pieza['plataforma'], (string)$pieza['tipo']);
        if ($debate['brief'] !== '') {
            $prompt .= "\n🔥 BRIEF DEL CORILLO (síguelo — dale ESTE ángulo atrevido, sin inventar datos del negocio):\n"
                     . $debate['brief'] . "\n";
        }
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
    $caption = trim((string)$r['texto']);

    // EL DIRECTOR CREATIVO sube la vara (una revisión si salió genérico) — solo en tema nuevo.
    $crit = ($debate['brief'] !== '')
        ? criticar_y_afinar($pdo, (int)$pieza['marca_id'], $caption, $debate['brief'], $sistema, $ctx)
        : ['caption' => $caption, 'nota' => ''];
    $caption = $crit['caption'];

    $pdo->prepare("UPDATE crecer_contenido SET caption = ?, ia_log_id = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$caption, $r['ia_log_id'], $contenido_id]);
    $corillo = corillo_conversacion($debate, $crit);
    corillo_guardar($pdo, $contenido_id, $corillo);

    return ['caption' => $caption, 'ia_log_id' => $r['ia_log_id'], 'costo' => $r['costo'], 'debate' => $debate, 'corillo' => $corillo];
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
        // Aísla el fallo por pieza: un error transitorio en una NO debe abortar el lote.
        try {
            $res = redactar_pieza($pdo, (int)$cid);
            $costo_total += $res['costo'];
            $resultados[] = ['contenido_id' => (int)$cid] + $res;
        } catch (Throwable $e) {
            error_log("redactar_calendario pieza {$cid}: " . $e->getMessage());
            $resultados[] = ['contenido_id' => (int)$cid, 'error' => true];
        }
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
    $ctx = cerebro_negocio($pdo, (int)$m['id'], $m);   // el CEREBRO del negocio permea cada decisión
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
    // (la memoria aprendida ya viene dentro del cerebro_negocio, en el contexto)
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
    $ctx = cerebro_negocio($pdo, (int)$m['id'], $m);   // el CEREBRO del negocio permea cada decisión

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
 * aprueba después. Genera también el ARTE de cada pieza (post completo, no un
 * caption pelao); el dueño aprueba antes de publicar.
 *
 * @return array{creadas:int, ids:array, razon:string}
 */
function trabajo_autonomo(PDO $pdo, int $marca_id, string $enfoque = ''): array {
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
        $plan = planificar_mes($pdo, $marca_id, $anio, $mes, $faltan, $enfoque);
    } catch (Throwable $e) {
        return ['creadas' => 0, 'ids' => [], 'razon' => 'planificador falló: ' . substr($e->getMessage(), 0, 100)];
    }

    $ids = [];
    $i = 0;
    $reprog = $pdo->prepare("UPDATE crecer_contenido SET fecha_programada=? WHERE id=? AND marca_id=?");
    foreach ($plan['piezas'] as $pz) {
        $cid = (int)$pz['id'];
        $cap = ''; $visual = '';
        try { $rr = redactar_pieza($pdo, $cid); $cap = (string)($rr['caption'] ?? ''); $visual = (string)($rr['debate']['visual'] ?? ''); } catch (Throwable $e) { /* queda la idea para editar */ }
        // El Diseñador deja el ARTE listo también → el dueño recibe el post COMPLETO
        // (arte + copy), no un caption pelao. El concepto visual del corillo maneja la imagen.
        if ($cap !== '') {
            try {
                $g = generar_grafica($pdo, $marca_id, null, ['copy' => $cap, 'con_texto' => false, 'con_logo' => true, 'instrucciones' => $visual]);
                if (!empty($g['archivo'])) {
                    $pdo->prepare("UPDATE crecer_contenido SET grafica_path=?, updated_at=NOW() WHERE id=? AND marca_id=?")
                        ->execute([$g['archivo'], $cid, $marca_id]);
                }
            } catch (Throwable $e) { error_log('relevo arte: ' . $e->getMessage()); }
        }
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
 * LA ESTRATEGA (autónoma). En cada relevo fija el ENFOQUE de la semana para
 * ESTE negocio: una dirección concreta (qué empujar y por qué), mirando su
 * perfil y lo último trabajado. Ese enfoque ALIMENTA al planificador (no es
 * adorno). Logueado como 'estratega' → aparece en el relevo del home.
 * @return string  el enfoque (o '' si falló).
 */
function estratega_enfoque_semana(PDO $pdo, int $marca_id): string {
    try {
        $m = leer_marca($pdo, $marca_id);
        $ctx = cerebro_negocio($pdo, (int)$m['id'], $m);   // el CEREBRO del negocio permea cada decisión
        // Señal real: temas de las últimas piezas (para NO repetir el mismo ángulo).
        $recientes = '';
        try {
            $q = $pdo->prepare("SELECT caption FROM crecer_contenido WHERE marca_id=? ORDER BY id DESC LIMIT 6");
            $q->execute([$marca_id]);
            $caps = array_filter(array_map(fn($c) => trim(mb_substr((string)$c, 0, 90)), $q->fetchAll(PDO::FETCH_COLUMN)));
            if ($caps) $recientes = "Últimos temas trabajados:\n- " . implode("\n- ", $caps) . "\n";
        } catch (Throwable $e) {}

        $hoy = date('Y-m-d');
        $sistema = "Eres LA ESTRATEGA de Crecer para un negocio boricua. En 1-2 frases define el ENFOQUE "
            . "de ESTA semana: qué debe empujar el negocio y POR QUÉ (aprovecha fechas boricuas, la quincena, "
            . "el fin de semana, la temporada, o un pilar que falte por trabajar). Concreto, accionable y de "
            . "ESTE negocio. NO repitas el ángulo de los últimos temas. Devuelve SOLO el enfoque, sin títulos ni saludo.";
        $prompt = "Perfil del negocio:\n{$ctx}\n\nHoy es {$hoy}.\n{$recientes}\n¿Cuál es el enfoque de la semana?";
        $r = ia_ejecutar($pdo, 'estratega', 'Enfoque de la semana', $prompt, [
            'marca_id' => $marca_id, 'sistema' => $sistema,
            'modelo' => CRECER_COPILOTO_MODEL,
            'temperatura' => 0.85, 'max_tokens' => 200, 'thinking_budget' => 0,
            'mock_texto' => 'Esta semana empuja el producto estrella con una oferta de fin de semana, que es cuando más se compra.',
        ]);
        return trim((string)($r['texto'] ?? ''));
    } catch (Throwable $e) { error_log('estratega_enfoque_semana: ' . $e->getMessage()); return ''; }
}

/**
 * EL ANALISTA (autónomo). Cierra el relevo mirando los números REALES del
 * negocio (posts publicados, piezas listas, lo que creó el corillo este mes)
 * y deja un resumen corto + 1 sugerencia. NO inventa ventas ni métricas.
 * Logueado como 'analitica'. No hace nada si aún no hay señal.
 */
function analitica_del_relevo(PDO $pdo, int $marca_id): void {
    try {
        $ini = date('Y-m-01 00:00:00');
        $publicados = (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$marca_id} AND estado='publicado'")->fetchColumn();
        $listos     = (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$marca_id} AND estado IN ('borrador','aprobado','programado')")->fetchColumn();
        $piezas_mes = 0;
        try { $piezas_mes = (int)$pdo->query("SELECT COUNT(*) FROM crecer_ia_log WHERE marca_id={$marca_id} AND estado='ok' AND created_at>='{$ini}'")->fetchColumn(); } catch (Throwable $e) {}
        if ($publicados === 0 && $listos === 0) return;   // nada que analizar todavía

        $m = leer_marca($pdo, $marca_id);
        $sistema = "Eres EL ANALISTA de Crecer. En 1-2 frases cálidas y en cristiano, dile al dueño boricua cómo "
            . "va su presencia y UNA sugerencia concreta. Habla SOLO de estos números; NO inventes ventas ni "
            . "métricas que no te di. Sin títulos ni saludo.";
        $prompt = "Negocio: {$m['nombre_negocio']}\n"
            . "Posts publicados en total: {$publicados}.\n"
            . "Posts listos (esperando OK o programados): {$listos}.\n"
            . "Piezas que creó el corillo este mes: {$piezas_mes}.\n\n"
            . "Escribe el resumen corto para el dueño.";
        ia_ejecutar($pdo, 'analitica', 'Cómo va la cosa', $prompt, [
            'marca_id' => $marca_id, 'sistema' => $sistema,
            'modelo' => CRECER_COPILOTO_MODEL,
            'temperatura' => 0.7, 'max_tokens' => 220, 'thinking_budget' => 0,
            'mock_texto' => 'Vas bien: el corillo te mantiene contenido listo. Cuando publiques, dale jueves o viernes, que es cuando más se mueve la gente.',
        ]);
    } catch (Throwable $e) { error_log('analitica_del_relevo: ' . $e->getMessage()); }
}

/**
 * EL RELEVO DEL CORILLO — para UNA marca corre el EQUIPO completo, no solo el
 * creador: el Aprendiz aprende tu estilo, la Estratega fija el enfoque, el
 * Creador redacta alineado a ese enfoque, y el Analista cierra con los números.
 * Cada agente loguea → el home enciende su parte del relevo (evidencia real,
 * criterio XPRIZE). Best-effort: si un agente falla, el relevo sigue.
 * Devuelve lo mismo que trabajo_autonomo (creadas/ids/razon).
 */
function relevo_del_corillo(PDO $pdo, int $marca_id): array {
    // 1) El Aprendiz: aprende la línea visual de lo que el dueño aprobó (si hay señal).
    try { aprender_estilo_visual($pdo, $marca_id); } catch (Throwable $e) { error_log('relevo aprendiz: ' . $e->getMessage()); }
    // 2) La Estratega: fija el enfoque de la semana (alimenta al creador).
    $enfoque = estratega_enfoque_semana($pdo, $marca_id);
    // 3) El Creador: redacta los borradores que falten, alineados al enfoque.
    $res = trabajo_autonomo($pdo, $marca_id, $enfoque);
    // 4) El Analista: cierra el relevo con los números reales.
    analitica_del_relevo($pdo, $marca_id);
    return $res;
}

/**
 * EL GERENTE GENERAL — el Account Director del corillo. Lee lo que el dueño pide
 * en el chat y decide: ¿es una ORDEN de trabajo (arma una campaña / hazme N posts
 * de X) o una consulta? Si es ORDEN, DESPACHA al equipo (planifica el tema y lo
 * redacta pieza por pieza pasando por la mesa del corillo) y reporta como un
 * gerente que repartió el trabajo. Si es consulta, devuelve null → responde la
 * Estratega. Logueado como 'gerente' (evidencia XPRIZE #2).
 *
 * @param bool $puede_producir  ¿la cuenta puede crear contenido? (plan/prueba)
 * @return array|null  ['ok'=>true,'respuesta'=>...,'accion'=>...,'creadas'=>n] o null
 */
function gerente_despachar(PDO $pdo, int $marca_id, string $peticion, bool $puede_producir, array $historial = []): ?array {
    // 1) Clasificar la intención (barato, JSON): ¿producir o conversar?
    try {
        $sys = "Eres EL GERENTE GENERAL del corillo (Account Director de una agencia de marketing). Clasifica lo que "
            . "el dueño escribe. ¿Es una ORDEN de PRODUCIR contenido (ej: 'arma una campaña de X', 'hazme 4 posts de "
            . "Y', 'necesito contenido para el Día de Madres', 'súbeme algo del combo nuevo') o es una consulta, "
            . "pregunta, saludo o pedido de consejo? Si es orden de producir, extrae el TEMA y cuántas piezas "
            . "(1-6; si no lo dice, 3). Responde SOLO JSON: {\"accion\":\"producir\"|\"conversar\",\"tema\":\"...\",\"n\":N}.";
        $r = ia_ejecutar($pdo, 'gerente', 'Repartir el trabajo', "El dueño escribe:\n\"{$peticion}\"", [
            'marca_id' => $marca_id, 'sistema' => $sys, 'json' => true,
            'modelo' => CRECER_COPILOTO_MODEL, 'temperatura' => 0.2, 'max_tokens' => 150, 'thinking_budget' => 0,
            'mock_texto' => '{"accion":"conversar","tema":"","n":3}',
        ]);
        $d = json_decode((string)$r['texto'], true) ?: [];
    } catch (Throwable $e) { return null; }

    if (($d['accion'] ?? '') !== 'producir') return null;   // consulta → la responde la Estratega
    $tema = trim((string)($d['tema'] ?? ''));
    $n = max(1, min(6, (int)($d['n'] ?? 3)));
    if ($tema === '') return null;

    // Cuenta sin plan/prueba: no despacha producción (upsell suave, en voz de gerente).
    if (!$puede_producir) {
        return ['ok' => true, 'accion' => 'upsell', 'creadas' => 0,
            'respuesta' => "Me encantaría poner al corillo a trabajar en lo de \"{$tema}\" ahora mismo. Para que el equipo "
                . "te produzca contenido a pedido, activa tu plan y me sueltas las riendas — ahí te armo campañas completas cuando quieras."];
    }

    // 2) DESPACHAR: planifica el tema (enfoque = la campaña) y redacta cada pieza (con debate del corillo).
    try {
        $anio = (int)date('Y'); $mes = (int)date('n');
        $plan = planificar_mes($pdo, $marca_id, $anio, $mes, $n, "Campaña que pidió el dueño: {$tema}");
        $ok = 0; $i = 0;
        $reprog = $pdo->prepare("UPDATE crecer_contenido SET fecha_programada=? WHERE id=? AND marca_id=?");
        foreach ($plan['piezas'] as $pz) {
            $cid = (int)$pz['id'];
            $cap = ''; $visual = '';
            try { $rr = redactar_pieza($pdo, $cid); $cap = (string)($rr['caption'] ?? ''); $visual = (string)($rr['debate']['visual'] ?? ''); $ok++; } catch (Throwable $e) {}
            if ($cap !== '') {   // deja el arte listo también (post completo), con el concepto del corillo
                try {
                    $g = generar_grafica($pdo, $marca_id, null, ['copy' => $cap, 'con_texto' => false, 'con_logo' => true, 'instrucciones' => $visual]);
                    if (!empty($g['archivo'])) {
                        $pdo->prepare("UPDATE crecer_contenido SET grafica_path=?, updated_at=NOW() WHERE id=? AND marca_id=?")
                            ->execute([$g['archivo'], $cid, $marca_id]);
                    }
                } catch (Throwable $e) { error_log('gerente arte: ' . $e->getMessage()); }
            }
            $reprog->execute([date('Y-m-d 10:00:00', strtotime('+' . (++$i) . ' day')), $cid, $marca_id]);
        }
    } catch (Throwable $e) {
        error_log('gerente_despachar: ' . $e->getMessage());
        return ['ok' => true, 'accion' => 'error', 'creadas' => 0,
            'respuesta' => "Empecé a montar lo de \"{$tema}\" pero se me trabó el equipo a mitad. Dame un momento y pídemelo otra vez."];
    }

    $mk = leer_marca($pdo, $marca_id);
    $resp = "¡Dale! Le repartí el trabajo al corillo y te armé {$ok} post" . ($ok === 1 ? '' : 's') . " sobre \"{$tema}\". "
        . equipo_nombre($mk, 'provocador') . " tiró los ángulos, " . equipo_nombre($mk, 'estratega') . " escogió el que más pega, y "
        . equipo_nombre($mk, 'escritor') . " los escribió en tu voz. "
        . "Ya están en Propuestas esperando tu OK — dale una mirada y me dices si ajustamos algo.";
    return ['ok' => true, 'respuesta' => $resp, 'accion' => 'campana', 'creadas' => $ok];
}

/**
 * EL CONSEJO DEL CORILLO — reunión de staff SEMANAL. El Gerente trae un recap y
 * PREGUNTAS para el dueño; el dueño participa; y lo que el dueño enseña se GUARDA
 * en la memoria del negocio → TODOS los agentes aprenden. 1 vez por semana,
 * conversación limitada. Evidencia XPRIZE #2: la IA aprende del negocio en vivo.
 */

/** ¿Toca Consejo esta semana? (se bloquea al participar; 1 por 7 días). */
function consejo_disponible(PDO $pdo, int $marca_id): array {
    try {
        $q = $pdo->prepare("SELECT created_at FROM crecer_ia_log
                            WHERE marca_id=? AND agente='gerente' AND accion='Consejo: charla'
                            ORDER BY id DESC LIMIT 1");
        $q->execute([$marca_id]);
        $ult = $q->fetchColumn();
        if ($ult && (time() - strtotime((string)$ult)) < 7 * 24 * 3600) {
            return ['ok' => false, 'proximo' => date('Y-m-d', strtotime((string)$ult) + 7 * 24 * 3600), 'ultimo' => $ult];
        }
    } catch (Throwable $e) {}
    return ['ok' => true, 'proximo' => null, 'ultimo' => null];
}

/** EL GERENTE abre el Consejo: recap breve + 1 preocupación + 2-3 preguntas para aprender. */
function consejo_abrir(PDO $pdo, int $marca_id): array {
    $m = leer_marca($pdo, $marca_id);
    $ctx = cerebro_negocio($pdo, (int)$m['id'], $m);   // el CEREBRO del negocio permea cada decisión
    $pend = (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$marca_id} AND estado='borrador'")->fetchColumn();
    $pub  = (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$marca_id} AND estado='publicado'")->fetchColumn();
    $temas = '';
    try {
        $q = $pdo->prepare("SELECT caption FROM crecer_contenido WHERE marca_id=? ORDER BY id DESC LIMIT 5");
        $q->execute([$marca_id]);
        $caps = array_filter(array_map(fn($c) => trim(mb_substr((string)$c, 0, 60)), $q->fetchAll(PDO::FETCH_COLUMN)));
        if ($caps) $temas = "Últimos temas: " . implode('; ', $caps);
    } catch (Throwable $e) {}

    $gte = equipo_nombre($m, 'gerente');
    $sys = "Eres {$gte}, el GERENTE del corillo (Account Director de la agencia). Estás ABRIENDO la reunión de staff "
        . "SEMANAL con el dueño del negocio. Tono cercano, boricua suave, de socio motivador (no robot). CORTO (máx 90 "
        . "palabras). Haz: 1) saludo + recap de 1 frase de lo que el equipo hizo; 2) UNA cosa que al equipo le importa "
        . "para mejorar (basada en el estado real); 3) 2-3 PREGUNTAS concretas para APRENDER del negocio (qué empujar, "
        . "fechas/eventos que vienen, qué le ha funcionado, algún cambio). Termina invitándolo a contestar. Sin listas corporativas.";
    $prompt = "Negocio:\n{$ctx}\n\nEstado: {$pend} posts esperando OK, {$pub} publicados en total. {$temas}\n\nAbre la reunión de staff.";
    $r = ia_ejecutar($pdo, 'gerente', 'Consejo: apertura', $prompt, [
        'marca_id' => $marca_id, 'sistema' => $sys, 'modelo' => CRECER_COPILOTO_MODEL,
        'temperatura' => 0.85, 'max_tokens' => 340, 'thinking_budget' => 0,
        'mock_texto' => "¡Wepa! Esta semana el corillo te dejó {$pend} posts listos. Nos falta una cosa para afinar la puntería: saber qué quieres mover ahora. Dime tres cosas — ¿qué producto está pegando?, ¿se viene alguna fecha o evento?, y ¿qué post te ha funcionado mejor? Con eso el equipo aprende y te tira mejor contenido.",
    ]);
    return ['respuesta' => trim((string)$r['texto'])];
}

/** EL GERENTE abre una reunión de AGENDA (on-demand): fechas especiales, agenda y ofertas. */
function consejo_abrir_agenda(PDO $pdo, int $marca_id): array {
    $m = leer_marca($pdo, $marca_id);
    $ctx = cerebro_negocio($pdo, (int)$m['id'], $m);   // el CEREBRO del negocio permea cada decisión
    $gte = equipo_nombre($m, 'gerente');
    $sys = "Eres {$gte}, el GERENTE del corillo. El dueño te llamó a una reunión rápida de PLANIFICACIÓN. Tono cercano, "
        . "boricua suave, directo. CORTO (máx 85 palabras). Pregúntale, concreto, por: 1) FECHAS especiales que se "
        . "vengan esta semana o este mes (aperturas, ferias, Día de las Madres, cumpleaños del negocio, quincena…); "
        . "2) si hay alguna OFERTA o promoción especial que quiera empujar y cuándo; 3) qué quiere priorizar. Invítalo a soltarlo.";
    $prompt = "Negocio:\n{$ctx}\n\nHoy es " . date('Y-m-d') . ". Abre la reunión de agenda.";
    $r = ia_ejecutar($pdo, 'gerente', 'Agenda: apertura', $prompt, [
        'marca_id' => $marca_id, 'sistema' => $sys, 'modelo' => CRECER_COPILOTO_MODEL,
        'temperatura' => 0.8, 'max_tokens' => 300, 'thinking_budget' => 0,
        'mock_texto' => "¡Dale, planifiquemos! Dime tres cosas y el corillo cuadra el mes: ¿qué FECHAS especiales se te vienen (algún evento, feria, o fecha grande)?, ¿tienes alguna OFERTA o promo que quieras empujar y para cuándo?, y ¿qué quieres que priorcemos estos días? Suéltalo y yo reparto el trabajo.",
    ]);
    return ['respuesta' => trim((string)$r['texto'])];
}

/**
 * EL GERENTE conversa en el meeting y CAPTURA lo que el dueño enseña → memoria
 * (todos los agentes aprenden). $accion distingue el Consejo semanal de la reunión
 * de agenda (para que la de agenda NO consuma el consejo semanal).
 */
function consejo_hablar(PDO $pdo, int $marca_id, string $mensaje, array $historial = [], string $accion = 'Consejo: charla'): array {
    require_once __DIR__ . '/memoria.php';
    $m = leer_marca($pdo, $marca_id);
    $gte = equipo_nombre($m, 'gerente');

    // 1) Aprender: extraer datos DURABLES del mensaje del dueño → memoria del negocio.
    $aprendido = [];
    if (function_exists('memoria_escribir')) {
        try {
            $sysX = "Del mensaje del dueño en la reunión de staff, extrae SOLO datos DURABLES y accionables para el "
                . "marketing (qué producto empujar, fechas/eventos que vienen, promociones, preferencias de voz/estilo, "
                . "qué le funciona, qué NO quiere). Ignora saludos y charla vacía. Responde SOLO JSON: "
                . '{"memorias":[{"titulo":"corto","detalle":"lo aprendido, accionable","dominio":"marketing|producto|voz|calendario"}]} (lista vacía si no hay nada durable).';
            $rx = ia_ejecutar($pdo, 'gerente', 'Consejo: aprender', "Mensaje del dueño:\n\"{$mensaje}\"", [
                'marca_id' => $marca_id, 'sistema' => $sysX, 'json' => true, 'modelo' => CRECER_COPILOTO_MODEL,
                'temperatura' => 0.3, 'max_tokens' => 400, 'thinking_budget' => 0, 'mock_texto' => '{"memorias":[]}',
            ]);
            $mem = json_decode((string)$rx['texto'], true)['memorias'] ?? [];
            foreach ($mem as $it) {
                $det = trim((string)($it['detalle'] ?? ''));
                if ($det === '') continue;
                memoria_escribir($pdo, $marca_id, [
                    'tipo' => 'aprendizaje', 'dominio' => substr((string)($it['dominio'] ?? 'marketing'), 0, 20),
                    'titulo' => (string)($it['titulo'] ?? $det), 'detalle' => $det,
                    'porque' => 'Lo dijo el dueño en el Consejo semanal', 'fuente' => 'consejo',
                    'confianza' => 80, 'peso' => 70,
                ]);
                $aprendido[] = trim((string)($it['titulo'] ?? $det));
            }
        } catch (Throwable $e) { error_log('consejo aprender: ' . $e->getMessage()); }
    }

    // 2) El Gerente responde (confirma lo aprendido, sigue la agenda o cierra).
    $mensajes = [];
    foreach ($historial as $h) {
        $rol = ($h['rol'] ?? '') === 'user' ? 'user' : 'model';
        $txt = trim((string)($h['texto'] ?? ''));
        if ($txt !== '') $mensajes[] = ['role' => $rol, 'texto' => $txt];
    }
    $sys = "Eres {$gte}, el GERENTE del corillo, en la reunión de staff semanal con el dueño. Responde CORTO (máx 70 "
        . "palabras), cercano y boricua suave. Confirma/agradece lo que te dijo y, si hace falta, haz UNA pregunta de "
        . "seguimiento. Si ya tienes buena info, cierra diciendo qué hará el equipo con eso esta semana. No inventes datos.";
    $prompt = "El dueño dijo:\n\"{$mensaje}\"\n"
        . ($aprendido ? "\n(El equipo ya anotó: " . implode('; ', $aprendido) . ")\n" : '')
        . "\nResponde como gerente en la reunión.";
    $r = ia_ejecutar($pdo, 'gerente', $accion, $prompt, [
        'marca_id' => $marca_id, 'sistema' => $sys, 'modelo' => CRECER_COPILOTO_MODEL,
        'historial' => $mensajes, 'temperatura' => 0.8, 'max_tokens' => 280, 'thinking_budget' => 0,
        'mock_texto' => 'Buenísimo, eso me sirve un montón. El equipo lo anota y lo mete en los próximos posts. ¿Algo más que quieras que el corillo empuje?',
    ]);
    return ['respuesta' => trim((string)$r['texto']), 'aprendido' => $aprendido];
}

// ── LA SALA DEL CORILLO (War Room) ───────────────────────────────────────────
//  Un solo espacio para conversar con el equipo: brainstorm, dar órdenes que
//  producen, fijar fechas/ofertas/agenda, y el staff meeting semanal. Todo lo
//  que el dueño dice se APRENDE (→ memoria, todos los agentes lo usan).

/** Extrae datos DURABLES del mensaje del dueño (fechas, ofertas, agenda, voz) → memoria. */
function corillo_aprender(PDO $pdo, int $marca_id, string $mensaje): array {
    require_once __DIR__ . '/memoria.php';
    if (!function_exists('memoria_escribir')) return [];
    $aprendido = [];
    try {
        $sysX = "Del mensaje del dueño en la sala del corillo, extrae SOLO datos DURABLES y accionables para el "
            . "marketing: qué producto empujar, FECHAS/eventos que vienen (con la fecha si la dice), OFERTAS/promos y "
            . "cuándo, agenda/prioridades, preferencias de voz/estilo, qué le funciona, qué NO quiere. Ignora saludos y "
            . "charla vacía. Responde SOLO JSON: {\"memorias\":[{\"titulo\":\"corto\",\"detalle\":\"lo aprendido, "
            . "accionable, con la fecha si aplica\",\"dominio\":\"marketing|producto|voz|calendario|oferta\"}]} (lista vacía si no hay nada durable).";
        $rx = ia_ejecutar($pdo, 'gerente', 'Sala: aprender', "Mensaje del dueño:\n\"{$mensaje}\"", [
            'marca_id' => $marca_id, 'sistema' => $sysX, 'json' => true, 'modelo' => CRECER_COPILOTO_MODEL,
            'temperatura' => 0.3, 'max_tokens' => 420, 'thinking_budget' => 0, 'mock_texto' => '{"memorias":[]}',
        ]);
        $mem = json_decode((string)$rx['texto'], true)['memorias'] ?? [];
        foreach ($mem as $it) {
            $det = trim((string)($it['detalle'] ?? ''));
            if ($det === '') continue;
            memoria_escribir($pdo, $marca_id, [
                'tipo' => 'aprendizaje', 'dominio' => substr((string)($it['dominio'] ?? 'marketing'), 0, 20),
                'titulo' => (string)($it['titulo'] ?? $det), 'detalle' => $det,
                'porque' => 'Lo dijo el dueño en la Sala del Corillo', 'fuente' => 'sala',
                'confianza' => 80, 'peso' => 70,
            ]);
            $aprendido[] = trim((string)($it['titulo'] ?? $det));
        }
    } catch (Throwable $e) { error_log('corillo_aprender: ' . $e->getMessage()); }
    return $aprendido;
}

/** El saludo al entrar a la Sala: 1x/semana el equipo abre el staff meeting; si no, un saludo. */
function sala_saludo(PDO $pdo, int $marca_id): array {
    $disp = consejo_disponible($pdo, $marca_id);
    if (!empty($disp['ok'])) {
        try {
            $ap = consejo_abrir($pdo, $marca_id);
            // Marca el staff meeting como abierto esta semana (no re-abrir por 7 días).
            try {
                $pdo->prepare("INSERT INTO crecer_ia_log (marca_id,agente,accion,modelo,prompt,respuesta,costo_usd,latencia_ms,estado)
                               VALUES (?,?,?,?,?,?,0,0,'ok')")
                    ->execute([$marca_id, 'gerente', 'Consejo: charla', '-', 'staff meeting semanal', '']);
            } catch (Throwable $e) {}
            return ['respuesta' => $ap['respuesta'], 'meeting' => true];
        } catch (Throwable $e) {}
    }
    $m = leer_marca($pdo, $marca_id);
    $gte = equipo_nombre($m, 'gerente');
    return ['respuesta' => "Aquí está el corillo, listo. 👋 Dime en qué andamos: una idea que quieras montar, fechas u ofertas "
        . "que se vengan, o una orden directa como \"hazme 3 posts del combo nuevo\" y lo repartimos al equipo. — {$gte}",
        'meeting' => false];
}

/**
 * EL CORILLO RESPONDE en la Sala (War Room). Cada mensaje: 1) aprende lo durable
 * (memoria), 2) si es ORDEN de producir, despacha al equipo; 3) si no, el equipo
 * conversa/aconseja. Devuelve respuesta + lo aprendido + qué hizo.
 */
function sala_responder(PDO $pdo, int $marca_id, string $mensaje, array $historial, bool $puede_producir): array {
    $aprendido = corillo_aprender($pdo, $marca_id, $mensaje);
    $g = gerente_despachar($pdo, $marca_id, $mensaje, $puede_producir, $historial);
    if ($g !== null) {
        return ['ok' => true, 'respuesta' => $g['respuesta'], 'aprendido' => $aprendido, 'accion' => $g['accion'] ?? 'produjo'];
    }
    $r = estratega_responder($pdo, $marca_id, $mensaje, $historial);
    return ['ok' => true, 'respuesta' => trim((string)($r['respuesta'] ?? '')), 'aprendido' => $aprendido, 'accion' => 'conversar'];
}

/**
 * EL LOOP DEL CORILLO AUTÓNOMO. Recorre las marcas con piloto automático
 * activo y plan vigente, corre el EQUIPO (relevo_del_corillo), y avisa al dueño
 * por email cuando dejó posts nuevos. Pensado para correr por cron (semanal).
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
        // Elegible: plan vigente O cuenta de prueba/full-access (CRECER_TEST_EMAILS). Sin eso, no gasta IA.
        $es_prueba = function_exists('activacion_de_prueba') && activacion_de_prueba($r['email'] ?? null);
        if (plan_de_marca($pdo, $mid) === null && !$es_prueba) {
            $detalle[] = ['marca_id' => $mid, 'creadas' => 0, 'razon' => 'sin plan activo'];
            continue;
        }
        $res = relevo_del_corillo($pdo, $mid);   // corre el EQUIPO, no solo el creador
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
