<?php
// ============================================================
//  CRECER — Business Voice DNA + Director Editorial  (v2)
//  includes/voice_dna.php
//
//  Cambios v2 (críticos):
//   1. Cultura ≠ informalidad. Ejes SEPARADOS: identidad_local,
//      formalidad, cercania, uso_jerga, energia, humor (+ comercial,
//      optimismo). Un negocio puede ser formal, profesional, MUY
//      puertorriqueño y cercano, SIN jerga.
//   2. Proveniencia obligatoria: cada expresión lleva
//      {valor, origen, evidencia}. Orígenes: observado_audio,
//      observado_texto, inferido_contexto, guardrail_global.
//      Lo inferido NUNCA se presenta como palabra real del cliente.
//      Los guardrails anti-IA (globales) NO se mezclan con las
//      preferencias del negocio.
//   4. Director FACTUAL: verifica afirmaciones contra los datos
//      reales; una afirmación no respaldada ⇒ rechazo automático.
//   5. Feature flag: VOICE_DNA_ONBOARDING_ENABLED (default false).
//
//  Complementa (NO reemplaza) los tono_* actuales.
// ============================================================
require_once __DIR__ . '/ia.php';
require_once __DIR__ . '/agentes.php';

// El recorrido nuevo del onboarding queda APAGADO hasta validar.
if (!defined('VOICE_DNA_ONBOARDING_ENABLED')) define('VOICE_DNA_ONBOARDING_ENABLED', false);

function voice_dna_origenes(): array {
    return ['observado_audio', 'observado_texto', 'inferido_contexto', 'guardrail_global'];
}

// ── Esquema + defaults (siembra desde tono_* para compat) ──
function voice_dna_defaults(array $marca = []): array {
    $seed = fn($k, $def) => isset($marca[$k]) && $marca[$k] !== null && $marca[$k] !== '' ? max(0, min(100, (int)$marca[$k])) : $def;
    return [
        'version' => 2,
        'ejes' => [
            'identidad_local'      => 50,                       // qué tan puertorriqueño/local (INDEPENDIENTE de jerga)
            'formalidad'           => $seed('tono_formal',  45),
            'cercania'             => $seed('tono_boricua', 65),
            'uso_jerga'            => 40,                       // jerga/informalidad — SEPARADO de identidad_local
            'energia'              => 55,
            'humor'                => $seed('tono_ingenio', 40),
            'intensidad_comercial' => $seed('tono_venta',   50),
            'optimismo'            => 65,
        ],
        'lexico' => [
            'vocabulario_caracteristico'  => [],   // [{valor,origen,evidencia}]
            'expresiones_repetidas'       => [],   // [{valor,origen,evidencia}]
            'palabras_prohibidas_negocio' => [],   // SOLO del negocio (con proveniencia). Los guardrails globales viven aparte.
        ],
        'estilo' => [
            'como_habla'      => '',
            'longitud_tipica' => 'medio',   // corto|medio|largo
            'uso_emoji'       => 'pocos',   // ninguno|pocos|libre
            'estilo_cta'      => '',
        ],
        'editorial' => [
            'direccion_preferida' => null,
            'ejemplos_de_su_voz'  => [],    // [{valor,origen,evidencia}] — SOLO observado si es una cita real
        ],
        'fuente' => 'default',              // audio|texto|default
    ];
}

// Coacciona un elemento a {valor, origen, evidencia} con origen válido.
function voice_dna_prov_item($x, string $origen_def = 'inferido_contexto'): ?array {
    if (is_string($x)) {
        $v = trim(mb_substr($x, 0, 60));
        return $v === '' ? null : ['valor' => $v, 'origen' => $origen_def, 'evidencia' => ''];
    }
    if (is_array($x) && isset($x['valor'])) {
        $v = trim(mb_substr((string)$x['valor'], 0, 60));
        if ($v === '') return null;
        $o = in_array($x['origen'] ?? '', voice_dna_origenes(), true) ? $x['origen'] : $origen_def;
        $e = trim(mb_substr((string)($x['evidencia'] ?? ''), 0, 160));
        // Regla dura: si dice "observado" pero no trae evidencia, se degrada a inferido
        // (nada inferido puede presentarse como cita real del cliente).
        if (($o === 'observado_audio' || $o === 'observado_texto') && $e === '') $o = 'inferido_contexto';
        return ['valor' => $v, 'origen' => $o, 'evidencia' => $e];
    }
    return null;
}

function voice_dna_prov_list($v, int $maxItems = 12, string $origen_def = 'inferido_contexto'): array {
    if (!is_array($v)) return [];
    $out = []; $seen = [];
    foreach ($v as $x) {
        $it = voice_dna_prov_item($x, $origen_def);
        if ($it && !isset($seen[mb_strtolower($it['valor'])])) { $seen[mb_strtolower($it['valor'])] = 1; $out[] = $it; }
        if (count($out) >= $maxItems) break;
    }
    return $out;
}

// ── Validación estricta: coacciona cualquier JSON al esquema v2 ──
function voice_dna_validar($j, array $marca = []): array {
    $d = voice_dna_defaults($marca);
    if (!is_array($j)) return $d;
    $clampInt = fn($v, $def) => is_numeric($v) ? max(0, min(100, (int)round((float)$v))) : $def;
    $enum = fn($v, $allow, $def) => in_array($v, $allow, true) ? $v : $def;

    if (isset($j['ejes']) && is_array($j['ejes'])) {
        foreach ($d['ejes'] as $k => $def) $d['ejes'][$k] = $clampInt($j['ejes'][$k] ?? $def, $def);
    }
    if (isset($j['lexico']) && is_array($j['lexico'])) {
        $d['lexico']['vocabulario_caracteristico']  = voice_dna_prov_list($j['lexico']['vocabulario_caracteristico'] ?? []);
        $d['lexico']['expresiones_repetidas']       = voice_dna_prov_list($j['lexico']['expresiones_repetidas'] ?? []);
        // Solo preferencias DEL NEGOCIO. Cualquier cliché de IA se filtra a los guardrails globales (no aquí).
        $slopGlobal = array_map('mb_strtolower', director_slop_frases());
        $lim = [];
        foreach (voice_dna_prov_list($j['lexico']['palabras_prohibidas_negocio'] ?? ($j['lexico']['palabras_prohibidas'] ?? [])) as $it) {
            if (!in_array(mb_strtolower($it['valor']), $slopGlobal, true)) $lim[] = $it;
        }
        $d['lexico']['palabras_prohibidas_negocio'] = $lim;
    }
    if (isset($j['estilo']) && is_array($j['estilo'])) {
        $d['estilo']['como_habla']      = trim(mb_substr((string)($j['estilo']['como_habla'] ?? ''), 0, 300));
        $d['estilo']['longitud_tipica'] = $enum($j['estilo']['longitud_tipica'] ?? '', ['corto', 'medio', 'largo'], 'medio');
        $d['estilo']['uso_emoji']       = $enum($j['estilo']['uso_emoji'] ?? '', ['ninguno', 'pocos', 'libre'], 'pocos');
        $d['estilo']['estilo_cta']      = trim(mb_substr((string)($j['estilo']['estilo_cta'] ?? ''), 0, 200));
    }
    if (isset($j['editorial']['ejemplos_de_su_voz'])) {
        // Ejemplos que se presentan como "su voz" SOLO valen si son observados con evidencia.
        $ej = [];
        foreach (voice_dna_prov_list($j['editorial']['ejemplos_de_su_voz'], 6) as $it) {
            $it['es_cita_real'] = in_array($it['origen'], ['observado_audio', 'observado_texto'], true);
            $ej[] = $it;
        }
        $d['editorial']['ejemplos_de_su_voz'] = $ej;
    }
    return $d;
}

function voice_dna_hash(string $fuente): string {
    return hash('sha256', 'v2|' . trim(mb_strtolower(preg_replace('/\s+/u', ' ', $fuente) ?? '')));
}

/**
 * Extrae el Voice DNA desde texto (transcripción/perfil). Idempotente por hash.
 * @return array{dna:array, llamadas:int, reusado:bool}
 */
function voice_dna_extraer(PDO $pdo, int $marca_id, string $fuente_texto, array $opts = []): array {
    $marca = $pdo->query("SELECT * FROM crecer_marca WHERE id=" . (int)$marca_id)->fetch(PDO::FETCH_ASSOC) ?: [];
    $fuente_texto = trim($fuente_texto);
    $fuente_origen = ($opts['fuente'] ?? 'texto') === 'audio' ? 'observado_audio' : 'observado_texto';
    $hash = voice_dna_hash($fuente_texto . '|' . ($marca['nombre_negocio'] ?? ''));

    if (empty($opts['forzar']) && !empty($marca['voice_dna']) && (($marca['voice_dna_hash'] ?? '') === $hash)) {
        $ex = json_decode((string)$marca['voice_dna'], true);
        if (is_array($ex) && (($ex['version'] ?? 0) >= 2)) return ['dna' => voice_dna_validar($ex, $marca), 'llamadas' => 0, 'reusado' => true];
    }

    $dna = voice_dna_defaults($marca);
    $llamadas = 0;

    if ($fuente_texto !== '') {
        $sistema = "Eres el perfilador de VOZ de Crecer. Construyes el Business Voice DNA observando PATRONES. "
                 . "REGLA CLAVE: cultura ≠ informalidad. 'identidad_local' (qué tan puertorriqueño/local suena) es INDEPENDIENTE "
                 . "de 'uso_jerga' (jerga callejera). Un negocio puede ser MUY puertorriqueño, formal y sin jerga. "
                 . "PROVENIENCIA obligatoria: cada expresión con {valor, origen, evidencia}. origen = 'observado_texto' SOLO si la "
                 . "palabra aparece literal en el texto (copia la cita en evidencia); si la deduces por el tipo de negocio, "
                 . "origen='inferido_contexto' y evidencia=''. NO inventes citas. NO metas clichés de marketing/IA. Devuelve SOLO JSON.";
        $prompt = "Del texto, extrae el Voice DNA en JSON EXACTO:\n"
            . '{"ejes":{"identidad_local":0-100,"formalidad":0-100,"cercania":0-100,"uso_jerga":0-100,"energia":0-100,"humor":0-100,"intensidad_comercial":0-100,"optimismo":0-100},'
            . '"lexico":{"vocabulario_caracteristico":[{"valor":"","origen":"observado_texto|inferido_contexto","evidencia":""}],'
            . '"expresiones_repetidas":[{"valor":"","origen":"","evidencia":""}],'
            . '"palabras_prohibidas_negocio":[{"valor":"","origen":"","evidencia":""}]},'
            . '"estilo":{"como_habla":"","longitud_tipica":"corto|medio|largo","uso_emoji":"ninguno|pocos|libre","estilo_cta":""},'
            . '"editorial":{"ejemplos_de_su_voz":[{"valor":"cita textual suya","origen":"observado_texto","evidencia":"la misma frase"}]}}' . "\n\n"
            . "Guía de ejes: identidad_local alto = suena de PR (referencias, calidez local) aunque sea formal. "
            . "uso_jerga alto = 'wepa/nene/brutal/pa''. formalidad alta = profesional. cercania alta = trato tú-a-tú. "
            . "energia = intensidad emocional. palabras_prohibidas_negocio = SOLO lo que NO pega con ESTE negocio "
            . "(ej. a un contable: 'wepa','brutal'; NO metas clichés de IA aquí, eso lo maneja el sistema aparte). "
            . "vocabulario/expresiones: si no está literal en el texto, márcalo inferido_contexto (no como cita real). "
            . "MÁXIMO 6 ítems por lista (los más representativos).\n\n"
            . "TEXTO:\n" . mb_substr($fuente_texto, 0, 2500);
        // El JSON con proveniencia es verboso; damos margen para que NO se trunque (truncar = JSON inválido).
        $mock = '{"ejes":{"identidad_local":85,"formalidad":20,"cercania":90,"uso_jerga":75,"energia":80,"humor":40,"intensidad_comercial":60,"optimismo":80},"lexico":{"vocabulario_caracteristico":[{"valor":"fresquecito","origen":"observado_texto","evidencia":"todo fresquecito"}],"expresiones_repetidas":[{"valor":"mi gente","origen":"observado_texto","evidencia":"mi gente"}],"palabras_prohibidas_negocio":[]},"estilo":{"como_habla":"Coloquial y calido.","longitud_tipica":"corto","uso_emoji":"pocos","estilo_cta":"Invita por WhatsApp"},"editorial":{"ejemplos_de_su_voz":[{"valor":"Todo fresquecito","origen":"observado_texto","evidencia":"todo fresquecito"}]}}';
        $j = null;
        // Hasta 2 intentos SOLO si el JSON viene inválido (truncado / con ruido). Nunca un loop abierto.
        for ($try = 1; $try <= 2 && $j === null; $try++) {
            try {
                $r = ia_ejecutar($pdo, 'voice_dna', 'Extraer Voice DNA v2', $prompt, [
                    'marca_id' => $marca_id, 'sistema' => $sistema, 'json' => true,
                    'temperatura' => 0.25, 'max_tokens' => 2200, 'thinking_budget' => 0, 'mock_texto' => $mock,
                ]);
                $llamadas++;
                $j = json_decode(trim((string)$r['texto']), true);
                if (!is_array($j)) { $j = null; error_log("voice_dna_extraer: JSON inválido (intento {$try}) marca={$marca_id}"); }
            } catch (Throwable $e) {
                error_log('voice_dna_extraer: ' . $e->getMessage());
                break;
            }
        }
        if (is_array($j)) {
            $j = _voice_dna_marcar_origen($j, $fuente_origen, $fuente_texto);
            $dna = voice_dna_validar($j, $marca);
            $dna['fuente'] = ($opts['fuente'] ?? 'texto');
        } else {
            // Fallback honesto: defaults prudentes, marcado como 'default' (NO se hace pasar por extraído).
            $dna = voice_dna_defaults($marca);
            $dna['fuente'] = 'default';
        }
    }

    try {
        $pdo->prepare("UPDATE crecer_marca SET voice_dna=?, voice_dna_version=?, voice_dna_hash=?, voice_dna_at=NOW() WHERE id=?")
            ->execute([json_encode($dna, JSON_UNESCAPED_UNICODE), (int)$dna['version'], $hash, $marca_id]);
    } catch (Throwable $e) {
        error_log('voice_dna persist: ' . $e->getMessage());
    }
    return ['dna' => $dna, 'llamadas' => $llamadas, 'reusado' => false];
}

// Verifica origen: si el modelo dijo "observado" pero la evidencia NO está en el texto real,
// lo degrada a inferido (imposible presentar algo inferido como cita real del cliente).
function _voice_dna_marcar_origen(array $j, string $origen_real, string $texto): array {
    $low = mb_strtolower($texto);
    $fix = function ($lista) use ($low, $origen_real) {
        if (!is_array($lista)) return $lista;
        foreach ($lista as &$it) {
            if (!is_array($it)) continue;
            $ev = mb_strtolower(trim((string)($it['evidencia'] ?? '')));
            $val = mb_strtolower(trim((string)($it['valor'] ?? '')));
            $enTexto = ($ev !== '' && mb_strpos($low, $ev) !== false) || ($val !== '' && mb_strpos($low, $val) !== false);
            if (($it['origen'] ?? '') === 'observado_audio' || ($it['origen'] ?? '') === 'observado_texto') {
                $it['origen'] = $enTexto ? $origen_real : 'inferido_contexto';
                if (!$enTexto) $it['evidencia'] = '';
            } elseif ($enTexto && ($it['origen'] ?? '') === '') {
                $it['origen'] = $origen_real;
            }
        }
        return $lista;
    };
    foreach (['vocabulario_caracteristico', 'expresiones_repetidas', 'palabras_prohibidas_negocio'] as $k) {
        if (isset($j['lexico'][$k])) $j['lexico'][$k] = $fix($j['lexico'][$k]);
    }
    if (isset($j['editorial']['ejemplos_de_su_voz'])) $j['editorial']['ejemplos_de_su_voz'] = $fix($j['editorial']['ejemplos_de_su_voz']);
    return $j;
}

// Instrucción del DNA para el creador. Solo lo OBSERVADO se presenta como "sus palabras".
function voice_dna_instruccion(array $dna): string {
    if (empty($dna['ejes'])) return '';
    $e = $dna['ejes']; $l = $dna['lexico'] ?? []; $s = $dna['estilo'] ?? [];
    $obs = fn($lista) => array_values(array_filter(array_map(fn($x) => is_array($x) ? $x['valor'] : $x,
        array_filter((array)$lista, fn($x) => is_array($x) && in_array($x['origen'] ?? '', ['observado_audio', 'observado_texto'], true)))));
    $vocab = array_slice($obs($l['vocabulario_caracteristico'] ?? []), 0, 8);
    $mulet = array_slice($obs($l['expresiones_repetidas'] ?? []), 0, 5);
    $prohib = array_slice(array_map(fn($x) => is_array($x) ? $x['valor'] : $x, (array)($l['palabras_prohibidas_negocio'] ?? [])), 0, 8);

    $out = "\n\nVOICE DNA (respétalo por encima de reglas genéricas):\n"
         . "- Ejes 0-100: identidad_local {$e['identidad_local']}, formalidad {$e['formalidad']}, cercanía {$e['cercania']}, uso_jerga {$e['uso_jerga']}, energía {$e['energia']}, humor {$e['humor']}, venta {$e['intensidad_comercial']}, optimismo {$e['optimismo']}.\n";
    // La regla que pidió el cliente: cultura ≠ jerga.
    if ($e['identidad_local'] >= 60 && $e['uso_jerga'] < 45) {
        $out .= "- IMPORTANTE: suena puertorriqueño en calidez y cercanía, pero SIN jerga callejera (nada de 'wepa/nene/brutal') y con gramática cuidada. Profesional y local a la vez.\n";
    }
    if (!empty($s['como_habla']))               $out .= "- Cómo habla: {$s['como_habla']}\n";
    $out .= "- Longitud: " . ($s['longitud_tipica'] ?? 'medio') . ". Emojis: " . ($s['uso_emoji'] ?? 'pocos') . ".\n";
    if ($vocab)  $out .= "- Palabras REALES suyas (úsalas si cuadran): " . implode(', ', $vocab) . ".\n";
    if ($mulet)  $out .= "- Muletillas REALES suyas: " . implode(', ', $mulet) . ".\n";
    if ($prohib) $out .= "- NO uses (no pega con la marca): " . implode(', ', $prohib) . ".\n";
    if (!empty($s['estilo_cta'])) $out .= "- Su CTA suele ser: {$s['estilo_cta']}\n";
    return $out;
}

// ── B · DIRECTOR EDITORIAL (con verificación FACTUAL) ────────
// Guardrails GLOBALES anti-IA (origen: guardrail_global). NO son del negocio.
function director_slop_frases(): array {
    return ['eleva tu negocio', 'lleva tu negocio', 'al siguiente nivel', 'en el mundo de hoy',
            'en la era digital', 'no busques más', 'somos tu mejor opción', 'calidad y servicio',
            'soluciones a tu medida', 'soluciones innovadoras', 'lo que necesitas y más', 'déjate consentir',
            'vive la experiencia', 'tu satisfacción es nuestra prioridad', 'la mejor calidad al mejor precio',
            'potencia tu marca', 'optimiza tus resultados', 'transforma tu'];
}

// Extrae números de teléfono de un texto (para verificar contra el whatsapp real).
function _solo_digitos(string $s): string { return preg_replace('/\D+/', '', $s) ?? ''; }

/**
 * Revisa un caption. Devuelve estructura clara + verificación factual.
 * @return array{aprobado:bool,puntuacion:int,razones:array,problemas:array,instrucciones_de_revision:string,
 *               afirmaciones_verificadas:array,afirmaciones_no_respaldadas:array,llamadas:int}
 */
function director_editorial(PDO $pdo, int $marca_id, string $contenido, array $dna, array $ctx = []): array {
    $contenido = trim($contenido);
    $negocio   = trim((string)($ctx['nombre_negocio'] ?? ''));
    $productos = trim((string)($ctx['productos'] ?? ''));
    $ofertas   = trim((string)($ctx['ofertas'] ?? ''));
    $pueblo    = trim((string)($ctx['pueblo'] ?? ''));
    $whatsapp  = trim((string)($ctx['whatsapp'] ?? ''));
    $instagram = trim((string)($ctx['instagram'] ?? ''));

    $hechos = "HECHOS REALES (lo ÚNICO cierto — todo lo demás es inventado):\n"
        . "- Negocio: " . ($negocio ?: '(sin nombre)') . "\n"
        . "- Productos/servicios: " . ($productos ?: '(ninguno declarado)') . "\n"
        . "- Ofertas: " . ($ofertas ?: '(ninguna)') . "\n"
        . "- Pueblo/ubicación: " . ($pueblo ?: '(no declarado)') . "\n"
        . "- WhatsApp: " . ($whatsapp ?: '(no tiene)') . "\n"
        . "- Instagram: " . ($instagram ?: '(no declarado)') . "\n";

    $sistema = "Eres el DIRECTOR EDITORIAL de Crecer. Eres EXIGENTE y FACTUAL. Rechazas cualquier caption con datos "
             . "que NO estén respaldados por los hechos reales (productos, servicios, ofertas, ubicación, teléfono, atributos "
             . "inventados). Prefieres rechazar a dejar pasar. Devuelve SOLO JSON.";
    $prompt = "Evalúa el caption. Devuelve JSON EXACTO:\n"
        . '{"aprobado":true|false,"puntuacion":0-100,"razones":[],"problemas":[],'
        . '"afirmaciones_verificadas":["afirmación respaldada por los hechos"],'
        . '"afirmaciones_no_respaldadas":["afirmación que NO está en los hechos (inventada)"],'
        . '"instrucciones_de_revision":"qué cambiar, concreto"}' . "\n\n"
        . "Criterios: (1) NO genérico (serviría para cualquier negocio ⇒ rechazo). (2) Específico de ESTE negocio. "
        . "(3) Auténtico, no IA. (4) Coincide con el Voice DNA. (5) FACTUAL: cada producto/servicio/oferta/ubicación/teléfono "
        . "DEBE estar en los hechos; si no, va a afirmaciones_no_respaldadas. (6) CTA coherente con los datos. (7) Publicable mañana.\n"
        . "Regla dura: si afirmaciones_no_respaldadas tiene algo ⇒ aprobado=false. aprobado=true solo si puntuacion>=75 y sin no_respaldadas.\n\n"
        . $hechos . voice_dna_instruccion($dna) . "\n\nCAPTION:\n{$contenido}";

    $res = ['aprobado' => false, 'puntuacion' => 0, 'razones' => [], 'problemas' => [],
            'afirmaciones_verificadas' => [], 'afirmaciones_no_respaldadas' => [], 'instrucciones_de_revision' => '', 'llamadas' => 0];
    try {
        $r = ia_ejecutar($pdo, 'director_editorial', 'Revisar contenido (factual)', $prompt, [
            'marca_id' => $marca_id, 'sistema' => $sistema, 'json' => true,
            'temperatura' => 0.2, 'max_tokens' => 650, 'thinking_budget' => 0,
            'mock_texto' => '{"aprobado":true,"puntuacion":82,"razones":["Menciona producto y pueblo"],"problemas":[],"afirmaciones_verificadas":["producto declarado"],"afirmaciones_no_respaldadas":[],"instrucciones_de_revision":""}',
        ]);
        $res['llamadas'] = 1;
        $j = json_decode(trim((string)$r['texto']), true);
        if (is_array($j)) {
            $strl = fn($v, $n = 6) => array_slice(array_values(array_filter(array_map('strval', (array)$v), 'strlen')), 0, $n);
            $res['aprobado']   = !empty($j['aprobado']);
            $res['puntuacion'] = is_numeric($j['puntuacion'] ?? null) ? max(0, min(100, (int)$j['puntuacion'])) : 0;
            $res['razones']    = $strl($j['razones'] ?? []);
            $res['problemas']  = $strl($j['problemas'] ?? []);
            $res['afirmaciones_verificadas']   = $strl($j['afirmaciones_verificadas'] ?? [], 10);
            $res['afirmaciones_no_respaldadas'] = $strl($j['afirmaciones_no_respaldadas'] ?? [], 10);
            $res['instrucciones_de_revision'] = trim((string)($j['instrucciones_de_revision'] ?? ''));
        }
    } catch (Throwable $e) {
        error_log('director_editorial: ' . $e->getMessage());
        $res['problemas'][] = 'No se pudo revisar (error del crítico).';
    }

    // Guarda 1 — anti-slop determinista (guardrail GLOBAL, manda sobre el crítico).
    $low = mb_strtolower($contenido);
    $slop = [];
    foreach (director_slop_frases() as $f) { if (mb_strpos($low, $f) !== false) $slop[] = $f; }
    if ($slop) {
        $res['aprobado'] = false;
        $res['puntuacion'] = min($res['puntuacion'] ?: 40, 40);
        $res['problemas'][] = 'Frases genéricas de IA (guardrail global): ' . implode(', ', $slop);
        $res['instrucciones_de_revision'] = trim($res['instrucciones_de_revision'] . ' Elimina las frases genéricas (' . implode(', ', $slop) . ').');
    }

    // Guarda 2 — FACTUAL determinista: teléfono en el caption que NO es el WhatsApp real ⇒ inventado.
    if (preg_match_all('/\b(?:\+?1[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}\b/', $contenido, $mm)) {
        $waDig = _solo_digitos($whatsapp);
        foreach ($mm[0] as $tel) {
            $telDig = _solo_digitos($tel);
            if ($telDig !== '' && ($waDig === '' || substr($telDig, -10) !== substr($waDig, -10))) {
                $res['aprobado'] = false;
                $res['afirmaciones_no_respaldadas'][] = "teléfono {$tel} (no es el WhatsApp del negocio)";
            }
        }
    }
    // Guarda 3 — especificidad mínima.
    if ($negocio !== '' && mb_stripos($contenido, $negocio) === false) {
        $prod_ok = false;
        foreach (preg_split('/[,;]+/', $productos) as $p) { $p = trim($p); if ($p !== '' && mb_stripos($contenido, $p) !== false) { $prod_ok = true; break; } }
        if (!$prod_ok) { $res['aprobado'] = false; $res['problemas'][] = 'No menciona el negocio ni un producto real.'; }
    }
    // Regla dura FACTUAL: cualquier afirmación no respaldada ⇒ rechazo.
    $res['afirmaciones_no_respaldadas'] = array_values(array_unique($res['afirmaciones_no_respaldadas']));
    if (!empty($res['afirmaciones_no_respaldadas'])) {
        $res['aprobado'] = false;
        $res['problemas'][] = 'Afirmaciones inventadas: ' . implode('; ', $res['afirmaciones_no_respaldadas']);
        if ($res['instrucciones_de_revision'] === '') $res['instrucciones_de_revision'] = 'Elimina lo que no está en los hechos reales del negocio.';
    }
    return $res;
}

// Fallback SEGURO y ESPECÍFICO — armado con los datos reales, nunca un draft malo.
function contenido_fallback_seguro(array $ctx, array $dna): string {
    $neg    = trim((string)($ctx['nombre_negocio'] ?? '')) ?: 'nuestro negocio';
    $prods  = array_values(array_filter(array_map('trim', preg_split('/[,;\n]+/', (string)($ctx['productos'] ?? '')))));
    $prod   = $prods[0] ?? '';
    $pueblo = trim((string)($ctx['pueblo'] ?? ''));
    $wa     = trim((string)($ctx['whatsapp'] ?? ''));
    $cta    = $wa !== '' ? "Escríbenos por WhatsApp al {$wa}" : "Escríbenos por mensaje directo";
    $linea  = $prod !== ''
        ? "En {$neg} tenemos {$prod}" . ($pueblo ? " aquí en {$pueblo}" : "") . ". "
        : "En {$neg}" . ($pueblo ? " en {$pueblo}" : "") . " estamos listos para atenderte. ";
    return $linea . $cta . ".";
}

/**
 * LA COMPUERTA: genera → revisa → (corrige)×máx2 → fallback. Loop SIEMPRE acotado.
 * @param callable $generar  fn(string $instrucciones): string
 */
function generar_con_director(PDO $pdo, int $marca_id, callable $generar, array $dna, array $ctx, array $opts = []): array {
    $max_regen = max(0, (int)($opts['max_regeneraciones'] ?? 2));
    $costo_gen = (int)($opts['costo_generar'] ?? 1);
    $llamadas  = 0; $intentos = 0; $historial = [];
    $instr = '';
    $contenido = (string)$generar($instr); $llamadas += $costo_gen;
    while (true) {
        $rev = director_editorial($pdo, $marca_id, $contenido, $dna, $ctx);
        $llamadas += (int)$rev['llamadas'];
        $historial[] = ['intento' => $intentos + 1, 'contenido' => $contenido, 'aprobado' => $rev['aprobado'], 'puntuacion' => $rev['puntuacion'], 'problemas' => $rev['problemas'], 'no_respaldadas' => $rev['afirmaciones_no_respaldadas']];
        if ($rev['aprobado']) {
            return ['contenido' => $contenido, 'aprobado' => true, 'revision' => $rev, 'intentos' => $intentos + 1, 'llamadas' => $llamadas, 'fallback' => false, 'historial' => $historial];
        }
        if ($intentos >= $max_regen) break;
        $intentos++;
        $instr = $rev['instrucciones_de_revision'] !== '' ? $rev['instrucciones_de_revision'] : 'Hazlo específico del negocio y sin inventar nada.';
        $contenido = (string)$generar($instr); $llamadas += $costo_gen;
    }
    $fb = contenido_fallback_seguro($ctx, $dna);
    return ['contenido' => $fb, 'aprobado' => true, 'fallback' => true, 'intentos' => $intentos + 1, 'llamadas' => $llamadas,
            'revision' => ['aprobado' => true, 'puntuacion' => 70, 'razones' => ['Fallback seguro y específico (solo datos reales)'], 'problemas' => [], 'afirmaciones_verificadas' => [], 'afirmaciones_no_respaldadas' => [], 'instrucciones_de_revision' => ''],
            'historial' => $historial];
}

// Generador de caption que respeta el Voice DNA (reusa reglas del creador).
function generar_caption_dna(PDO $pdo, int $marca_id, array $m, array $dna, string $revision = ''): string {
    $ctx = marca_contexto($m);
    $sistema = "Eres el CREADOR de contenido de Crecer. Captions cortos para redes de microempresas boricuas. "
             . "Español puertorriqueño AUTÉNTICO, nunca traducido ni \"AI slop\". NO inventes productos, ofertas, teléfonos "
             . "ni ubicaciones: usa SOLO lo que está en el perfil. Máximo 45 palabras.\n"
             . contacto_instruccion($m) . voice_dna_instruccion($dna);
    $prompt = "Perfil del negocio:\n{$ctx}\n\nEscribe UN caption para promocionar el negocio o su producto principal, en SU voz."
            . ($revision !== '' ? "\n\nUN DIRECTOR EDITORIAL RECHAZÓ el intento anterior. CORRIGE exactamente esto: {$revision}" : '')
            . "\n\nDevuelve SOLO el caption, sin comillas ni explicación.";
    $r = ia_ejecutar($pdo, 'creador', 'Caption con Voice DNA', $prompt, [
        'marca_id' => $marca_id, 'sistema' => $sistema,
        'temperatura' => 0.9, 'max_tokens' => 220, 'thinking_budget' => 0,
        'mock_texto' => 'En ' . ($m['nombre_negocio'] ?? 'la casa') . ' tenemos algo bueno para ti hoy.',
    ]);
    return trim((string)$r['texto']);
}

// ── Idempotencia PERSISTENTE (BD): un pipeline por usuario ────
// Estados: 'procesando' (en curso), 'completed' (marca creada), 'failed' (murió/timeout).
// Nunca queda amarrado para siempre: un 'procesando' viejo (stale) o un 'failed' se pueden
// re-adquirir de forma atómica. El acquire usa PRIMARY KEY(usuario_id): dos requests
// simultáneos ⇒ uno solo entra; el otro recibe un estado RECUPERABLE (no un error confuso).
// $stale_seg: a partir de cuántos segundos un 'procesando' se considera muerto (default 3 min).
function onboarding_lock_acquire(PDO $pdo, int $usuario_id, int $stale_seg = 180): array {
    $token = bin2hex(random_bytes(16));
    try {
        $pdo->prepare("INSERT INTO crecer_onboarding_lock (usuario_id, estado, token, created_at, updated_at) VALUES (?, 'procesando', ?, NOW(), NOW())")
            ->execute([$usuario_id, $token]);
        return ['acquired' => true, 'token' => $token, 'recovered' => false, 'estado' => 'procesando', 'marca_id' => null];
    } catch (PDOException $e) {
        $row = $pdo->query("SELECT estado, marca_id FROM crecer_onboarding_lock WHERE usuario_id=" . (int)$usuario_id)->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['acquired' => false, 'estado' => 'procesando', 'marca_id' => null];
        // Ya terminó: reutiliza el resultado (idempotente).
        if ($row['estado'] === 'completed') return ['acquired' => false, 'estado' => 'completed', 'marca_id' => $row['marca_id'] ? (int)$row['marca_id'] : null];
        // Re-adquisición ATÓMICA si está 'failed' o 'procesando' pero stale (proceso muerto).
        // Un solo UPDATE gana la carrera (rowCount===1); los demás ven 'procesando' recuperable.
        $upd = $pdo->prepare(
            "UPDATE crecer_onboarding_lock SET token=?, estado='procesando', updated_at=NOW()
             WHERE usuario_id=? AND (estado='failed' OR (estado='procesando' AND updated_at < (NOW() - INTERVAL ? SECOND)))");
        $upd->execute([$token, $usuario_id, $stale_seg]);
        if ($upd->rowCount() === 1) return ['acquired' => true, 'token' => $token, 'recovered' => true, 'estado' => 'procesando', 'marca_id' => null];
        // En curso y fresco ⇒ estado recuperable (el front puede reintentar/poll).
        return ['acquired' => false, 'estado' => 'procesando', 'marca_id' => null];
    }
}
function onboarding_lock_done(PDO $pdo, int $usuario_id, int $marca_id, string $token): void {
    try { $pdo->prepare("UPDATE crecer_onboarding_lock SET estado='completed', marca_id=?, updated_at=NOW() WHERE usuario_id=? AND token=?")->execute([$marca_id, $usuario_id, $token]); }
    catch (Throwable $e) { error_log('onboarding_lock_done: ' . $e->getMessage()); }
}
// Falla controlada: marca 'failed' (NO borra). Deja el lock re-adquirible por el próximo intento.
function onboarding_lock_fail(PDO $pdo, int $usuario_id, string $token): void {
    try { $pdo->prepare("UPDATE crecer_onboarding_lock SET estado='failed', updated_at=NOW() WHERE usuario_id=? AND token=?")->execute([$usuario_id, $token]); }
    catch (Throwable $e) { error_log('onboarding_lock_fail: ' . $e->getMessage()); }
}
function onboarding_lock_reset(PDO $pdo, int $usuario_id): void {
    try { $pdo->prepare("DELETE FROM crecer_onboarding_lock WHERE usuario_id=?")->execute([$usuario_id]); }
    catch (Throwable $e) {}
}
