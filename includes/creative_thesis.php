<?php
// ============================================================
//  CRECER · Creative Thesis (ADR-0003) — la capacidad, AISLADA
//  includes/creative_thesis.php
//
//  Transforma comprensión (Business Genome) en una IDEA creativa única
//  ANTES de generar contenido. Devuelve un ACTIVO SEMÁNTICO reutilizable,
//  independiente del medio. Optimiza por RESONANCIA, no por originalidad.
//
//  ── CONTRATO ARQUITECTÓNICO (qué, no cómo) ──────────────────────────────
//  Entrada:
//    creative_thesis(array $genome, array $brief, callable $inferir): array
//    · $genome  — la comprensión disponible (del Business Genome).
//    · $brief   — el ENCARGO bajo el que se decide (contrato CERRADO, ver ct_brief).
//    · $inferir — el PROVEEDOR DE INFERENCIA, suministrado por la ORQUESTACIÓN.
//
//  Dependencia arquitectónica: Creative Thesis depende de un **proveedor de
//  inferencia**, NO de una base de datos ni de un mecanismo concreto. HOY ese
//  proveedor se implementa como un `callable` — pero eso es una DECISIÓN DE
//  IMPLEMENTACIÓN, no la dependencia arquitectónica. La capacidad NUNCA queda
//  acoplada al mecanismo por el cual obtiene inferencia (hoy un callable;
//  mañana podría ser otro). El contrato describe la responsabilidad, no el medio.
//  Por eso el módulo no conoce PDO, ni ia.php, ni transporte, ni logging: eso
//  vive en la orquestación, dentro del proveedor que ella inyecta.
//
//  AISLAMIENTO (Paso 2): NO se integra, NO se llama desde ningún flujo, NO
//  persiste, NO crea telemetría, NO toca Creator/Director/Working Moment/Genome.
//
//  Non-goals (candado permanente): NUNCA produce copy, captions, titulares,
//  hashtags ni contenido publicable. Solo decide qué historia merece contarse.
//
//  Resultado = envelope de primera clase: status = accepted | abstained.
//  SIEMPRE produce un resultado (nunca null): abstenerse es un resultado válido.
// ============================================================

if (!defined('CT_CONTRATO_VERSION')) define('CT_CONTRATO_VERSION', 'ct-v1');

// Vocabulario de ángulos (guía, no camisa de fuerza). Lo desconocido → 'otro'.
function ct_angulos(): array {
    return ['historia','orgullo','nostalgia','humor','curiosidad','tradicion','comunidad','sorpresa',
            'producto_estrella','cliente','proceso','detras_camaras','celebracion','temporada',
            'problema_que_resuelve','emocion','otro'];
}
// Fuentes válidas de evidencia trazable al Genome.
function ct_fuentes(): array { return ['observacion','producto','eje_dna','voz']; }

function _ct_norm(string $s): string { return trim(preg_replace('/\s+/u', ' ', mb_strtolower($s)) ?? ''); }

// ── El ENCARGO (brief): contrato de ENTRADA cerrado ──────────────────────
// Extrae SOLO las claves permitidas; todo lo demás se IGNORA (así no puede
// convertirse en una bolsa de parámetros).
//   Puede contener: objetivo?, medio? (solo contexto), angulos_recientes?, hechos_prohibidos?
//   Nunca debe contener: estado de ejecución/orquestación (run_uid, tesis_id, idempotencia),
//     contenido o su formato (copy, longitud, hashtags, SEO, CTA), datos crudos del Genome,
//     handles de infraestructura (PDO/persistencia/telemetría), parámetros de Director/Creator,
//     ni objetivos de engagement.
function ct_brief(array $in): array {
    return [
        'objetivo'          => (string)($in['objetivo'] ?? ''),
        'medio'             => (string)($in['medio'] ?? ''),          // solo contexto; nunca se hornea en la idea ni se guarda en el activo
        'angulos_recientes' => array_values(array_map('strval', (array)($in['angulos_recientes'] ?? []))),
        'hechos_prohibidos' => array_values(array_map('strval', (array)($in['hechos_prohibidos'] ?? []))),
    ];
}

// ── Constructores del envelope (SIEMPRE sin campos de contenido) ──
function ct_result_accepted(array $campos, array $brief): array {
    return array_merge([
        'status'               => 'accepted',
        'contrato_version'     => CT_CONTRATO_VERSION,
        'restricciones_usadas' => $brief,
    ], $campos);
}
function ct_result_abstained(string $motivo, array $brief): array {
    return [
        'status'               => 'abstained',
        'contrato_version'     => CT_CONTRATO_VERSION,
        'restricciones_usadas' => $brief,
        'motivo'               => mb_substr($motivo, 0, 280),
    ];
}

// ── Validación determinista de una referencia de evidencia contra el Genome ──
// Solo comprueba que la señal citada EXISTE. No juzga si "suena verdad".
function ct_evidencia_resuelve(array $ref, array $genome): bool {
    $fuente = (string)($ref['fuente'] ?? '');
    $clave  = trim((string)($ref['clave'] ?? ''));
    if ($clave === '' || !in_array($fuente, ct_fuentes(), true)) return false;
    switch ($fuente) {
        case 'observacion':
            $obs = (array)($genome['observaciones'] ?? []);
            if (!preg_match('/\d+/', $clave, $m)) return false;   // acepta "0", "[0]", "obs 0"…
            return array_key_exists((int)$m[0], $obs);
        case 'producto':
            $prods = array_map('_ct_norm', array_map('strval', (array)($genome['productos'] ?? [])));
            return in_array(_ct_norm($clave), $prods, true);
        case 'eje_dna':
            return array_key_exists($clave, (array)($genome['dna']['ejes'] ?? []));
        case 'voz':
            $texto = _ct_norm(((string)($genome['voz'] ?? '')) . ' ' . ((string)($genome['descripcion'] ?? '')));
            return $texto !== '' && mb_strpos($texto, _ct_norm($clave)) !== false;
    }
    return false;
}

function _ct_norm_angulo(string $a): string {
    $a = str_replace(' ', '_', _ct_norm($a));
    return in_array($a, ct_angulos(), true) ? $a : 'otro';
}
function _ct_norm_evidencia($ev): array {
    $out = [];
    foreach ((array)$ev as $it) {
        if (!is_array($it)) continue;
        $f = trim((string)($it['fuente'] ?? '')); $c = trim((string)($it['clave'] ?? ''));
        if ($f !== '' && $c !== '') $out[] = ['fuente' => $f, 'clave' => mb_substr($c, 0, 120)];
        if (count($out) >= 6) break;
    }
    return $out;
}

// ── Validación estructural + de evidencia → envelope. Pura (sin inferencia, sin DB) ──
// Reglas de abstención: no parseable · sin idea · confianza baja · sin evidencia ·
// alguna referencia de evidencia NO resuelve contra el Genome recibido.
function ct_validar($raw, array $genome, array $brief): array {
    $b = ct_brief($brief);
    if (!is_array($raw)) return ct_result_abstained('salida del modelo no parseable', $b);

    // El modelo puede declararse abstenido explícitamente.
    if (($raw['status'] ?? 'accepted') === 'abstained') {
        return ct_result_abstained(trim((string)($raw['motivo'] ?? 'sin idea suficientemente sustentada')), $b);
    }

    $idea = trim((string)($raw['idea_central'] ?? ''));
    if ($idea === '') return ct_result_abstained('sin idea central', $b);

    $conf = _ct_norm((string)($raw['confianza'] ?? ''));
    if (!in_array($conf, ['alta','media','baja'], true)) $conf = 'baja';
    if ($conf === 'baja') return ct_result_abstained('confianza insuficiente (baja no produce tesis)', $b);

    $ev = _ct_norm_evidencia($raw['evidencia'] ?? []);
    if (!$ev) return ct_result_abstained('sin evidencia', $b);
    foreach ($ev as $ref) {
        if (!ct_evidencia_resuelve($ref, $genome)) {
            return ct_result_abstained("evidencia no trazable ({$ref['fuente']}:{$ref['clave']})", $b);
        }
    }

    return ct_result_accepted([
        'idea_central' => mb_substr($idea, 0, 300),
        'angulo'       => _ct_norm_angulo((string)($raw['angulo'] ?? '')),
        'audiencia'    => (trim((string)($raw['audiencia'] ?? '')) !== '') ? mb_substr(trim((string)$raw['audiencia']), 0, 160) : null,
        'resonancia'   => (trim((string)($raw['resonancia'] ?? '')) !== '') ? mb_substr(trim((string)$raw['resonancia']), 0, 160) : null,
        'contraste'    => (trim((string)($raw['contraste'] ?? '')) !== '') ? mb_substr(trim((string)$raw['contraste']), 0, 280) : null,
        'confianza'    => $conf,
        'evidencia'    => $ev,
    ], $b);
}

// ── Construcción del request para el proveedor de inferencia. Pura (sin inferencia) ──
// Presenta las señales del Genome CON sus claves, para que la evidencia sea citable
// y trazable. Prohíbe contenido publicable. La idea es independiente del medio.
function ct_build_request(array $genome, array $brief): array {
    $nombre = (string)($genome['nombre'] ?? 'el negocio');
    $pueblo = (string)($genome['pueblo'] ?? '');
    $prods  = array_values(array_filter(array_map('strval', (array)($genome['productos'] ?? []))));
    $ejes   = (array)($genome['dna']['ejes'] ?? []);
    $obs    = (array)($genome['observaciones'] ?? []);
    $voz    = (string)($genome['voz'] ?? ($genome['descripcion'] ?? ''));
    $b      = ct_brief($brief);

    $lineasObs = [];
    foreach ($obs as $i => $o) { $t = is_array($o) ? ($o['texto'] ?? '') : (string)$o; if ($t !== '') $lineasObs[] = "  [{$i}] {$t}"; }

    $sistema =
        "Eres Creative Thesis del Corillo. Tu ÚNICA tarea: decidir la única IDEA que merece contarse de este negocio "
        . "para el contexto dado. Optimizas por RESONANCIA (que conecte con su gente), NO por originalidad.\n"
        . "REGLAS DURAS:\n"
        . "- UNA sola idea, independiente del medio. NO escribas copy, caption, titular, hashtags ni nada publicable.\n"
        . "- La idea debe estar SUSTENTADA por señales concretas del Genome. Cita evidencia TRAZABLE con {fuente,clave} usando "
        . "SOLO estas fuentes: observacion (por índice [n]), producto (nombre exacto), eje_dna (nombre del eje), voz (fragmento literal).\n"
        . "- Confianza = suficiencia de evidencia (alta|media|baja). Si NO puedes sustentar una idea fuerte, ABSTENTE: "
        . "devuelve {\"status\":\"abstained\",\"motivo\":\"…\"}. Mejor abstenerse que inventar. Confianza baja ⇒ abstente.\n"
        . "- No repitas a la ligera un ángulo reciente; prefiere otro SOLO si su calidad y sustento lo permiten (penalización blanda).\n"
        . "Devuelve SOLO JSON.";
    $prompt =
        "NEGOCIO: {$nombre}" . ($pueblo !== '' ? " · {$pueblo}" : '') . "\n"
        . "OBJETIVO/estrategia: " . ($b['objetivo'] ?: '(libre)') . "  ·  MEDIO (solo contexto, NO lo hornees en la idea): " . ($b['medio'] ?: '(cualquiera)') . "\n"
        . ($b['angulos_recientes'] ? "Ángulos recientes (penalización blanda): " . implode(', ', $b['angulos_recientes']) . "\n" : '')
        . ($b['hechos_prohibidos'] ? "Hechos prohibidos: " . implode('; ', $b['hechos_prohibidos']) . "\n" : '')
        . "\nSEÑALES DEL GENOME (cita SOLO de aquí):\n"
        . ($lineasObs ? "observaciones:\n" . implode("\n", $lineasObs) . "\n" : '')
        . ($prods ? "productos: " . implode(', ', $prods) . "\n" : '')
        . ($ejes ? "ejes_dna: " . implode(', ', array_keys($ejes)) . "\n" : '')
        . ($voz !== '' ? "voz (para citar fragmentos): " . mb_substr($voz, 0, 600) . "\n" : '')
        . "\nDevuelve: {\"status\":\"accepted|abstained\",\"idea_central\":\"…\",\"angulo\":\"" . implode('|', ct_angulos()) . "\","
        . "\"audiencia\":\"opcional\",\"resonancia\":\"opcional\",\"contraste\":\"opcional: No es ___. Es ___.\","
        . "\"confianza\":\"alta|media|baja\",\"evidencia\":[{\"fuente\":\"observacion|producto|eje_dna|voz\",\"clave\":\"…\"}],\"motivo\":\"si abstained\"}";

    return [
        'sistema' => $sistema,
        'prompt'  => $prompt,
        'opts'    => ['sistema' => $sistema, 'json' => true, 'temperatura' => 0.5, 'max_tokens' => 700, 'thinking_budget' => 0],
    ];
}

// ── La capacidad: decide una Creative Thesis ──
//  Depende de un PROVEEDOR DE INFERENCIA (la orquestación lo inyecta). El proveedor
//  recibe el request (sistema/prompt/opts) y devuelve el texto crudo del modelo.
//  El mecanismo concreto (hoy un callable) NO es la dependencia arquitectónica.
//  NO persiste, NO integra, NO conoce infraestructura.
function creative_thesis(array $genome, array $brief, callable $inferir): array {
    $req = ct_build_request($genome, $brief);
    try {
        $texto = (string)$inferir($req);               // proveedor de inferencia (mecanismo actual: un callable)
        $raw = json_decode(trim($texto), true);
    } catch (Throwable $e) {
        error_log('creative_thesis: ' . $e->getMessage());
        return ct_result_abstained('error de inferencia', ct_brief($brief));
    }
    return ct_validar($raw, $genome, $brief);
}
