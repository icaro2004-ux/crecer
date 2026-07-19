<?php
// ============================================================
//  CRECER · C2 — Business Genome (motor detrás de la experiencia)
//  includes/genoma.php
//
//  Enchufa el motor DETRÁS de "El Primer Minuto" sin cambiar la
//  experiencia. Pipeline observable con fallback curado en CADA etapa:
//
//    Genome → Selección → 3 recomendaciones → Primer post → Director → Resultado
//                                     └────────── fallback curado (C1) ──────────┘
//
//  INERTE por ahora: este módulo NO está enganchado al flujo vivo.
//  El feature flag (VOICE_DNA_ONBOARDING_ENABLED) sigue OFF. Se activa
//  recién cuando la medición lo respalde. No modifica C1.
//
//  Toda etapa registra telemetría en crecer_pipeline_run.
// ============================================================
require_once __DIR__ . '/ia.php';
require_once __DIR__ . '/agentes.php';
require_once __DIR__ . '/voice_dna.php';
require_once __DIR__ . '/primer_minuto.php';
require_once __DIR__ . '/creative_thesis.php';

// Presupuestos de seguridad (medir antes de tocar). Estabilidad > sofisticación.
if (!defined('GENOMA_MAX_REGEN'))       define('GENOMA_MAX_REGEN', 2);
if (!defined('GENOMA_TIMEOUT_POST_MS')) define('GENOMA_TIMEOUT_POST_MS', 20000); // techo blando por post
if (!defined('UMBRAL_POTENCIAL'))       define('UMBRAL_POTENCIAL', 85);          // Potencial: solo con evidencia FUERTE

// ── Telemetría: captura tokens/costo por rango de ia_log (pipeline por-usuario es secuencial) ──
function _genoma_snap(PDO $pdo): int { return (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM crecer_ia_log")->fetchColumn(); }
function _genoma_delta(PDO $pdo, int $desde): array {
    $r = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(tokens_in),0) ti, COALESCE(SUM(tokens_out),0) too,
                             COALESCE(SUM(costo_usd),0) c, COALESCE(SUM(estado='error'),0) err
                        FROM crecer_ia_log WHERE id > " . (int)$desde)->fetch(PDO::FETCH_ASSOC) ?: [];
    return ['llamadas'=>(int)($r['n']??0), 'tokens_in'=>(int)($r['ti']??0), 'tokens_out'=>(int)($r['too']??0),
            'costo'=>(float)($r['c']??0), 'err'=>(int)($r['err']??0)];
}
function genoma_run_uid(): string { return bin2hex(random_bytes(16)); }
function _genoma_registrar(PDO $pdo, string $run, int $marca_id, string $etapa, bool $ok, float $ms, array $tel, string $resultado, string $motivo = ''): void {
    try {
        $pdo->prepare("INSERT INTO crecer_pipeline_run (run_uid,marca_id,etapa,ok,ms,llamadas,tokens_in,tokens_out,costo_usd,resultado,motivo,created_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())")
            ->execute([$run, $marca_id, $etapa, $ok?1:0, (int)round($ms), $tel['llamadas'], $tel['tokens_in'], $tel['tokens_out'], round($tel['costo'],6), $resultado, mb_substr($motivo,0,255)]);
    } catch (Throwable $e) { error_log('pipeline_run: ' . $e->getMessage()); }
}

// ── ETAPA 1 · Business Genome ────────────────────────────────
// El genoma = Voice DNA (idempotente) + datos reales del negocio + señales.
function genoma_construir(PDO $pdo, array $marca, string $run): array {
    $marca_id = (int)$marca['id'];
    $t0 = microtime(true); $snap = _genoma_snap($pdo);
    $fuente = trim(($marca['voz'] ?? '') . "\n" . ($marca['descripcion'] ?? '') . "\nProductos: "
        . implode(', ', array_map(fn($x) => is_array($x) ? ($x['nombre'] ?? '') : $x, (array)json_decode((string)($marca['productos'] ?? ''), true) ?: [])));
    $reusado = false;
    try {
        $r = voice_dna_extraer($pdo, $marca_id, $fuente);
        $dna = $r['dna']; $reusado = $r['reusado'];
    } catch (Throwable $e) {
        $dna = voice_dna_defaults($marca); // fallback: perfil prudente
        _genoma_registrar($pdo, $run, $marca_id, 'genoma', false, (microtime(true)-$t0)*1000, _genoma_delta($pdo,$snap), 'fallback', 'DNA: '.$e->getMessage());
        return ['dna'=>$dna, 'm'=>pm_marca_a_m($pdo,$marca), 'marca'=>$marca, 'ok'=>false, 'habla_como'=>genoma_habla_como($dna)];
    }
    $tel = _genoma_delta($pdo, $snap);
    _genoma_registrar($pdo, $run, $marca_id, 'genoma', true, (microtime(true)-$t0)*1000, $tel, $reusado ? 'reuso' : 'ok');
    return ['dna'=>$dna, 'm'=>pm_marca_a_m($pdo,$marca), 'marca'=>$marca, 'ok'=>true, 'reusado'=>$reusado, 'habla_como'=>genoma_habla_como($dna)];
}

// El GENOMA decide cómo habla la marca (no el prompt): alta formalidad ⇒ organización.
function genoma_habla_como(array $dna): string {
    return ((int)($dna['ejes']['formalidad'] ?? 50) >= 60) ? 'organizacion' : 'persona';
}

// ── ETAPA 2 · Selección de estrategia (informada por el genoma) ──
// Archetipos del catálogo puntuados por señales + ejes del Voice DNA. Determinista, 0 llamadas.
function genoma_seleccionar(PDO $pdo, array $genoma, string $run, int $n = 3): array {
    $marca_id = (int)$genoma['marca']['id'];
    $t0 = microtime(true);
    $s = pm_senales($genoma['m']); $e = $genoma['dna']['ejes'] ?? [];
    $ej = fn($k) => (int)($e[$k] ?? 50);
    $boost = [
        'presentarte'       => ($ej('cercania') + $ej('identidad_local')) / 20,
        'producto_estrella' => $ej('energia') / 20,
        'movimiento'        => $ej('intensidad_comercial') / 15,
        'que_te_encuentren' => $ej('formalidad') / 15,
        'historia'          => $ej('optimismo') / 20,
        'prueba_social'     => $ej('cercania') / 25,
        'detras_camaras'    => ($ej('energia') + $ej('humor')) / 25,
    ];
    $cat = pm_catalogo();
    foreach ($cat as $i => &$a) { $a['_score'] = (int)($a['score'])($s) + ($boost[$a['id']] ?? 0); $a['_ord'] = $i; }
    unset($a);
    usort($cat, fn($x,$y) => ($y['_score'] <=> $x['_score']) ?: ($x['_ord'] <=> $y['_ord']));
    $sel = array_slice($cat, 0, max(1,$n));
    // baseline curado (fallback y semilla para la personalización)
    $out = array_map(fn($a) => [
        'id'=>$a['id'], 'titulo'=>pm_fill($a['titulo'],$genoma['m']), 'recomendacion'=>pm_fill($a['recomendacion'],$genoma['m']),
        'caption'=>pm_fill($a['caption'],$genoma['m']), 'cta'=>'Empecemos por aquí', 'tesis'=>$a['recomendacion'],
    ], $sel);
    _genoma_registrar($pdo, $run, $marca_id, 'seleccion', true, (microtime(true)-$t0)*1000, ['llamadas'=>0,'tokens_in'=>0,'tokens_out'=>0,'costo'=>0], 'ok', implode(',', array_column($out,'id')));
    return $out;
}

// ── ETAPA 3 · Generar las 3 recomendaciones (personalizadas por el genoma) ──
// UNA llamada para las tres. Fallback: el baseline curado de la etapa 2.
function genoma_recomendaciones(PDO $pdo, array $genoma, array $seleccion, string $run): array {
    $marca_id = (int)$genoma['marca']['id'];
    $t0 = microtime(true); $snap = _genoma_snap($pdo);
    $m = $genoma['m'];
    $ctx = "Negocio: {$m['nombre_negocio']} · {$m['pueblo']}" . ($m['producto'] !== '' ? " · producto: {$m['producto']}" : '');
    $angulos = array_map(fn($d) => ['clave'=>$d['id'], 'enfoque'=>$d['tesis']], $seleccion);
    $sistema = "Eres el estratega del Corillo. Con el perfil (Business Genome) del negocio personalizas 3 direcciones de arranque. "
             . "Cada una: un 'titulo' corto (la jugada) y una 'recomendacion' en primera persona plural, EN LA VOZ del negocio, "
             . "como CONSEJO (no explicación), máx 22 palabras. NO inventes datos (productos/ofertas/lugares que no estén). Devuelve SOLO JSON."
             . voice_dna_instruccion($genoma['dna']);
    $prompt = "Perfil: {$ctx}\n\nDirecciones a personalizar (respeta cada 'clave'):\n" . json_encode($angulos, JSON_UNESCAPED_UNICODE)
        . "\n\nDevuelve: {\"direcciones\":[{\"clave\":\"\",\"titulo\":\"\",\"recomendacion\":\"\"}]}";
    try {
        $r = ia_ejecutar($pdo, 'genoma_estrategias', 'Personalizar 3 direcciones', $prompt, [
            'marca_id'=>$marca_id, 'sistema'=>$sistema, 'json'=>true, 'temperatura'=>0.6, 'max_tokens'=>600, 'thinking_budget'=>0,
            'mock_texto'=>'{"direcciones":[]}',
        ]);
        $j = json_decode(trim((string)$r['texto']), true);
        $map = [];
        foreach (($j['direcciones'] ?? []) as $d) {
            $c = $d['clave'] ?? ''; if ($c==='') continue;
            $map[$c] = ['titulo'=>trim(mb_substr((string)($d['titulo']??''),0,80)), 'recomendacion'=>trim(mb_substr((string)($d['recomendacion']??''),0,220))];
        }
        $out = []; $personalizadas = 0;
        foreach ($seleccion as $d) {
            if (!empty($map[$d['id']]['titulo']) && !empty($map[$d['id']]['recomendacion'])) {
                $d['titulo'] = $map[$d['id']]['titulo']; $d['recomendacion'] = $map[$d['id']]['recomendacion']; $personalizadas++;
            }
            $out[] = $d;
        }
        $tel = _genoma_delta($pdo, $snap);
        $res = $personalizadas === count($seleccion) ? 'ok' : ($personalizadas > 0 ? 'regenerado' : 'fallback');
        _genoma_registrar($pdo, $run, $marca_id, 'estrategias', $personalizadas>0, (microtime(true)-$t0)*1000, $tel, $res, "personalizadas={$personalizadas}/".count($seleccion));
        return $out;
    } catch (Throwable $e) {
        _genoma_registrar($pdo, $run, $marca_id, 'estrategias', false, (microtime(true)-$t0)*1000, _genoma_delta($pdo,$snap), 'fallback', $e->getMessage());
        return $seleccion; // curado
    }
}

// ── The Working Moment · El Corillo devuelve INTERPRETACIONES (no datos) ──
// 1–3 observaciones que hagan pensar "sí, así mismo es". Respaldadas por el Genome,
// en tono tentativo (conclusiones de una conversación, no un reporte). Gated por
// confianza: habla cuando tiene algo que decir; si solo hay una fuerte, muestra una.
// Interpretación, NO dato: "Parece que hay un producto del que te sientes orgulloso",
// no "vendes bizcocho". Si nada supera el umbral, devuelve [] (la UI cae a algo neutro).
function genoma_observaciones(PDO $pdo, array $genoma, string $run, int $max = 3): array {
    $marca_id = (int)$genoma['marca']['id'];
    $t0 = microtime(true); $snap = _genoma_snap($pdo);
    $m = $genoma['m'];
    $dicho = trim((string)($genoma['marca']['voz'] ?? '') ?: (string)($genoma['marca']['descripcion'] ?? ''));
    $ctx = "Negocio: {$m['nombre_negocio']} · {$m['pueblo']}\n"
         . "Lo que dijo el dueño (en sus palabras): " . mb_substr($dicho, 0, 700) . "\n"
         . "Productos/servicios: " . ($m['producto'] !== '' ? $m['producto'] : '(no especificó)');
    // Gate de material: el Corillo habla solo cuando tiene de dónde. Poco texto ⇒ pocas o ninguna.
    $riqueza = count(array_filter(preg_split('/\s+/u', trim($dicho))));
    if ($riqueza < 5) {
        _genoma_registrar($pdo, $run, $marca_id, 'observaciones', true, (microtime(true)-$t0)*1000, ['llamadas'=>0,'tokens_in'=>0,'tokens_out'=>0,'costo'=>0], '0_obs', 'sin_material');
        return [];
    }
    $umbral = $riqueza < 10 ? 88 : 70;                 // poco material ⇒ solo lo muy seguro
    $cap    = $riqueza < 10 ? 1 : $max;                // poco material ⇒ a lo sumo una
    $sistema = "Eres el Corillo: un equipo de marketing que acaba de conocer este negocio. NO listas datos ni repites lo que el "
        . "dueño dijo. Devuelves INTERPRETACIONES, como las conclusiones de una conversación. Cada frase debe hacer pensar al dueño "
        . "\"sí, así mismo es\" o \"no lo había visto de esa manera\" — NUNCA \"¿de dónde sacó eso?\".\n"
        . "El Corillo NUNCA critica, NUNCA corrige, NUNCA supone. Solo comparte aquello de lo que tiene evidencia suficiente.\n"
        . "Cada observación es de UNO de tres TIPOS:\n"
        . "  • comprension  — demuestra que entendiste el negocio. Ej: 'Creo que tu mayor fortaleza está en la confianza que generas.'\n"
        . "  • confirmacion — refuerza algo que el negocio YA hace muy bien. Ej: 'Lo artesanal parece parte de tu identidad, no solo de tu proceso.'\n"
        . "  • potencial    — señala algo VALIOSO que el negocio TIENE pero que NO parece estar mostrando o aprovechando lo suficiente "
        .                    "(un producto que se vende solo pero casi no promociona; una historia que no cuenta; un diferenciador que no destaca). "
        .                    "Solo con evidencia CLARA de que existe Y está subaprovechado. NO propongas soluciones, solo señala la oportunidad. "
        .                    "Ej: 'Creo que hay un producto que merece mucho más protagonismo del que le das.' Si no hay evidencia clara, NO la incluyas.\n"
        . "REGLAS DURAS:\n"
        . "- Interpretación, NO dato. PROHIBIDO 'vendes X', 'estás en Y', 'hablas Z', 'ofreces...'.\n"
        . "- Tono humano y tentativo: 'Parece que…', 'Da la impresión de que…', 'Creo que…', 'Se nota que…'.\n"
        . "- Específica de ESTE negocio; jamás una frase que sirva para cualquiera.\n"
        . "- Cada observación: UNA idea, máximo 20 palabras. Corta y con alma.\n"
        . "- Incluye una observación SOLO si estás genuinamente seguro. Mejor decir menos y acertar. Si el dueño dio muy poca información, devuelve pocas o NINGUNA. Nunca inventes.\n"
        . "Devuelve SOLO JSON." . voice_dna_instruccion($genoma['dna']);
    $prompt = $ctx . "\n\nDevuelve hasta 5 CANDIDATAS (yo elijo la mejor combinación): "
        . "{\"observaciones\":[{\"texto\":\"\",\"tipo\":\"comprension|confirmacion|potencial\",\"confianza\":0-100,\"evidencia\":\"qué del perfil la respalda\"}]}. Ordena de mayor a menor confianza.";
    try {
        $r = ia_ejecutar($pdo, 'genoma_observaciones', 'Interpretaciones del negocio', $prompt, [
            'marca_id'=>$marca_id, 'sistema'=>$sistema, 'json'=>true, 'temperatura'=>0.7, 'max_tokens'=>650, 'thinking_budget'=>0,
            'mock_texto'=>'{"observaciones":[]}',
        ]);
        $j = json_decode(trim((string)$r['texto']), true);
        $cands = [];
        foreach (($j['observaciones'] ?? []) as $o) {
            $texto = trim((string)($o['texto'] ?? ''));
            $conf  = is_numeric($o['confianza'] ?? null) ? (int)$o['confianza'] : 0;
            if ($texto === '' || $conf < $umbral) continue;                              // habla solo si está seguro
            if (preg_match('/^\s*(vendes|ofreces|tienes|haces|eres|est[áa]s|hablas)\b/iu', $texto)) continue; // es un DATO, no interpretación
            $tipo = mb_strtolower(trim((string)($o['tipo'] ?? 'comprension')));
            if (!in_array($tipo, ['comprension','confirmacion','potencial'], true)) $tipo = 'comprension';
            $cands[] = ['texto'=>mb_substr($texto,0,200), 'tipo'=>$tipo, 'confianza'=>$conf, 'evidencia'=>trim(mb_substr((string)($o['evidencia']??''),0,160))];
        }
        // Selección de la combinación más útil: backbone (comprensión/confirmación) + a lo sumo UNA
        // Potencial, y solo con evidencia FUERTE (≥UMBRAL_POTENCIAL) y material rico. La Potencial va al final.
        $pot  = array_values(array_filter($cands, fn($o)=>$o['tipo']==='potencial'));
        $rest = array_values(array_filter($cands, fn($o)=>$o['tipo']!=='potencial'));
        usort($pot,  fn($a,$b)=>$b['confianza']<=>$a['confianza']);
        usort($rest, fn($a,$b)=>$b['confianza']<=>$a['confianza']);
        $sel = [];
        foreach ($rest as $o) { if (count($sel) >= $cap) break; $sel[] = $o; }
        if ($cap >= 2 && $riqueza >= 10 && !empty($pot) && $pot[0]['confianza'] >= UMBRAL_POTENCIAL) {
            if (count($sel) >= $cap) array_pop($sel);    // libera un espacio (baja la más débil del backbone)
            $sel[] = $pot[0];                            // la Potencial es la elevación → va al final
        }
        $sel = array_slice($sel, 0, $cap);
        _genoma_registrar($pdo, $run, $marca_id, 'observaciones', count($sel)>0, (microtime(true)-$t0)*1000, _genoma_delta($pdo,$snap), count($sel).'_obs', implode('+', array_column($sel,'tipo')));
        return $sel;
    } catch (Throwable $e) {
        _genoma_registrar($pdo, $run, $marca_id, 'observaciones', false, (microtime(true)-$t0)*1000, _genoma_delta($pdo,$snap), 'fallback', $e->getMessage());
        return []; // sin observaciones → el Working Moment usa una línea neutra y honesta
    }
}
// Nota de visión (futuro, Learning Engine): hoy las observaciones demuestran COMPRENSIÓN.
// Cuando el Corillo aprenda del negocio con el tiempo, la Potencial podrá demostrar CRITERIO
// ("tus clientes mencionan la rapidez; vale la pena destacarla"). Ese día no es hoy.

// ── ORQUESTACIÓN de Creative Thesis (ADR-0003, Paso 3) ───────────────────────
//  Creative Thesis (includes/creative_thesis.php) es una capacidad AISLADA: no
//  conoce BD, run_uid, tesis_id, persistencia, reintentos, telemetría, Creator ni
//  Director. TODO eso vive AQUÍ, en la orquestación del pipeline. La orquestación:
//   (a) arma la vista del Genome y el brief CERRADO desde datos que YA existen;
//   (b) inyecta el PROVEEDOR DE INFERENCIA (mecanismo: un callable sobre ia_ejecutar);
//   (c) invoca la capacidad UNA vez por ejecución lógica;
//   (d) persiste el activo (crecer_tesis) EXACTAMENTE como salió (sin copy/prompts);
//   (e) el binding ejecución↔tesis_id vive en crecer_wm_run (working_moment.php),
//       no aquí dentro y NUNCA dentro de la capacidad ni del Creator.

// ¿Debe correr Creative Thesis? Solo con el Feature Flag ON. Con OFF: no corre,
// no se crea tesis, no se toca el prompt del Creator, no se añade latencia.
function tesis_activa(): bool { return defined('VOICE_DNA_ONBOARDING_ENABLED') && VOICE_DNA_ONBOARDING_ENABLED; }

// Vista del Genome que consume la capacidad (SOLO comprensión; nada de ejecución).
function _tesis_genome_view(array $genoma, array $prep): array {
    $m = $genoma['m']; $marca = $genoma['marca'];
    $prods = array_map(fn($x) => is_array($x) ? trim((string)($x['nombre'] ?? '')) : trim((string)$x),
             (array)(json_decode((string)($marca['productos'] ?? ''), true) ?: []));
    $prods = array_values(array_filter($prods));
    $obs = [];
    foreach (($prep['observaciones'] ?? []) as $o) {
        $t = is_array($o) ? (string)($o['texto'] ?? '') : (string)$o;
        if ($t !== '') $obs[] = ['texto' => $t, 'tipo' => (string)($o['tipo'] ?? 'comprension')];
    }
    return [
        'nombre'        => (string)($m['nombre_negocio'] ?? ''),
        'pueblo'        => (string)($m['pueblo'] ?? ''),
        'productos'     => $prods,
        'voz'           => trim((string)($marca['voz'] ?? '') ?: (string)($marca['descripcion'] ?? '')),
        'descripcion'   => (string)($marca['descripcion'] ?? ''),
        'dna'           => ['ejes' => (array)($genoma['dna']['ejes'] ?? [])],
        'observaciones' => $obs,
    ];
}

// El ENCARGO (brief CERRADO) desde datos ya presentes en el pipeline. No inventa nada.
function _tesis_brief(PDO $pdo, int $marca_id, array $direccion): array {
    $recientes = [];
    try {
        $st = $pdo->prepare("SELECT angulo FROM crecer_tesis WHERE marca_id=? AND status='accepted' AND angulo IS NOT NULL ORDER BY created_at DESC, tesis_id DESC LIMIT 5");
        $st->execute([$marca_id]);
        $recientes = array_values(array_filter($st->fetchAll(PDO::FETCH_COLUMN)));
    } catch (Throwable $e) { /* sin historial → sin restricción de variedad */ }
    return ct_brief([
        'objetivo'          => (string)($direccion['id'] ?? ''),   // objetivo estratégico de la pieza (arquetipo elegido, upstream)
        'medio'             => 'post',                              // Working Moment = post (solo contexto de encuadre)
        'angulos_recientes' => $recientes,                         // variedad: no repetir ángulos recientes de la marca
        'hechos_prohibidos' => [],                                 // ninguno disponible hoy en el pipeline (no inventar)
    ]);
}

// Persiste el activo EXACTAMENTE como salió (envelope validado). NO guarda copy,
// prompts, respuestas crudas, revisiones ni estado de Director/Working Moment.
function tesis_persistir(PDO $pdo, int $marca_id, array $env): int {
    $acc = ($env['status'] ?? '') === 'accepted';
    $pdo->prepare("INSERT INTO crecer_tesis
        (marca_id,status,contrato_version,angulo,idea_central,audiencia,resonancia,contraste,confianza,evidencia,restricciones_usadas,motivo,created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
      ->execute([
        $marca_id,
        (string)($env['status'] ?? 'abstained'),
        (string)($env['contrato_version'] ?? CT_CONTRATO_VERSION),
        $acc ? ($env['angulo']       ?? null) : null,
        $acc ? ($env['idea_central'] ?? null) : null,
        $acc ? ($env['audiencia']    ?? null) : null,
        $acc ? ($env['resonancia']   ?? null) : null,
        $acc ? ($env['contraste']    ?? null) : null,
        $acc ? ($env['confianza']    ?? null) : null,
        $acc ? json_encode($env['evidencia'] ?? [], JSON_UNESCAPED_UNICODE) : null,
        json_encode($env['restricciones_usadas'] ?? [], JSON_UNESCAPED_UNICODE),
        $acc ? null : (string)($env['motivo'] ?? ''),
      ]);
    return (int)$pdo->lastInsertId();
}

// Recupera un activo persistido a la forma del envelope (para reuso idempotente).
function tesis_cargar(PDO $pdo, int $tesis_id): ?array {
    $st = $pdo->prepare("SELECT * FROM crecer_tesis WHERE tesis_id=?");
    $st->execute([$tesis_id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    if ($r['status'] === 'accepted') {
        return [
            'status' => 'accepted', 'contrato_version' => (string)$r['contrato_version'],
            'idea_central' => $r['idea_central'], 'angulo' => $r['angulo'],
            'audiencia' => $r['audiencia'], 'resonancia' => $r['resonancia'], 'contraste' => $r['contraste'],
            'confianza' => $r['confianza'],
            'evidencia' => json_decode((string)$r['evidencia'], true) ?: [],
            'restricciones_usadas' => json_decode((string)$r['restricciones_usadas'], true) ?: [],
        ];
    }
    return [
        'status' => 'abstained', 'contrato_version' => (string)$r['contrato_version'],
        'motivo' => $r['motivo'], 'restricciones_usadas' => json_decode((string)$r['restricciones_usadas'], true) ?: [],
    ];
}

// Proveedor de inferencia REAL (mecanismo actual: ia_ejecutar). La capacidad no lo conoce;
// la orquestación lo inyecta. crecer_ia_log registra costo/contabilidad, NO gobierna estado.
function _tesis_inferidor_real(PDO $pdo, int $marca_id): callable {
    return function (array $req) use ($pdo, $marca_id): string {
        $r = ia_ejecutar($pdo, 'creative_thesis', 'Decidir la tesis', $req['prompt'],
            array_merge($req['opts'], ['marca_id' => $marca_id,
                'mock_texto' => '{"status":"abstained","motivo":"sin proveedor de inferencia real (mock)"}']));
        return (string)$r['texto'];
    };
}

// ── DECIDIR (inferencia, FUERA de toda transacción) ──────────────────────────
// Arma vista+brief, inyecta el proveedor, invoca la capacidad y CLASIFICA desde el
// contrato observable por la orquestación (NO desde crecer_ia_log):
//   · el proveedor lanza / falla el transporte → 'error' (fallo de infraestructura);
//   · envelope accepted  → 'accepted';
//   · envelope abstained → 'abstained' (incluye no-parseable / confianza baja / evidencia
//     inválida: son ABSTENCIONES correctas de la capacidad, no errores).
// Devuelve ['envelope'=>envelope, 'clasificacion'=>'accepted|abstained|error']. NO persiste.
function tesis_decidir(PDO $pdo, array $genoma, array $direccion, array $prep, ?callable $inferir = null): array {
    $marca_id = (int)$genoma['marca']['id'];
    $G       = _tesis_genome_view($genoma, $prep);
    $brief   = _tesis_brief($pdo, $marca_id, $direccion);
    $inferir = $inferir ?? _tesis_inferidor_real($pdo, $marca_id);
    // La orquestación observa el fallo del proveedor en SU propia frontera (no vía ia_log).
    $proveedor_fallo = false;
    $observado = function (array $req) use ($inferir, &$proveedor_fallo): string {
        try { return $inferir($req); }
        catch (Throwable $e) { $proveedor_fallo = true; throw $e; }   // la capacidad igual lo convierte en abstención
    };
    $env    = creative_thesis($G, $brief, $observado);
    $clasif = $proveedor_fallo ? 'error' : (string)$env['status'];
    return ['envelope' => $env, 'clasificacion' => $clasif];
}

// ── PUBLICAR (transacción CORTA, atómica; sin inferencia dentro) ─────────────
// Publica el activo + el binding como una sola operación, o reutiliza el ya vinculado.
//   1) bloquea/relee la fila de crecer_wm_run (FOR UPDATE);
//   2) re-comprueba tesis_id;
//   3) si existe → carga y reutiliza, SIN insertar el candidato;
//   4) si no → INSERT crecer_tesis + UPDATE crecer_wm_run.tesis_id en la MISMA tx;
//   5) commit de ambas juntas;  6) rollback de ambas ante cualquier fallo.
// Garantía: nunca hay commit con tesis insertada pero run sin binding; una carrera tardía
// puede desperdiciar la inferencia en memoria, pero no publica un segundo activo.
function tesis_publicar(PDO $pdo, int $marca_id, string $run_uid, array $envelope): array {
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT tesis_id FROM crecer_wm_run WHERE run_uid=? FOR UPDATE");
        $st->execute([$run_uid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException("run inexistente para binding: {$run_uid}");
        if (!empty($row['tesis_id'])) {                        // ya vinculado ⇒ reutiliza, no inserta
            $existente = tesis_cargar($pdo, (int)$row['tesis_id']);
            $pdo->commit();
            return ['tesis_id' => (int)$row['tesis_id'], 'envelope' => $existente, 'reused' => true];
        }
        $tid = tesis_persistir($pdo, $marca_id, $envelope);     // INSERT + UPDATE en la misma tx
        $pdo->prepare("UPDATE crecer_wm_run SET tesis_id=?, updated_at=NOW() WHERE run_uid=?")->execute([$tid, $run_uid]);
        $pdo->commit();
        return ['tesis_id' => $tid, 'envelope' => $envelope, 'reused' => false];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();            // rollback de ambas (tesis + binding)
        throw $e;
    }
}

// ── ORQUESTAR: decide (fuera de tx) → publica (tx corta) → telemetría honesta ──
// Devuelve ['envelope'=>?array, 'tesis_id'=>?int, 'clasificacion'=>str, 'reused'=>bool].
// 'error' del proveedor NO se publica (no es una decisión); el run queda re-intentable.
// $inferir inyectable para pruebas (sin depender de crecer_ia_log).
function tesis_orquestar(PDO $pdo, array $genoma, array $direccion, string $run, array $prep, ?callable $inferir = null): array {
    $marca_id = (int)$genoma['marca']['id'];
    $t0 = microtime(true); $snap = _genoma_snap($pdo);
    $d   = tesis_decidir($pdo, $genoma, $direccion, $prep, $inferir);   // inferencia FUERA de transacción
    $env = $d['envelope']; $clasif = $d['clasificacion'];
    $tel = _genoma_delta($pdo, $snap);                                  // costo/contabilidad (NO gobierna estado)

    // EXCEPCIÓN: fallo real del proveedor ANTES de producir envelope → 'error'. No se publica,
    // no se crea ni vincula activo, el run queda re-intentable. (No hay candidato que descartar.)
    if ($clasif === 'error') {
        _genoma_registrar($pdo, $run, $marca_id, 'tesis', false, (microtime(true)-$t0)*1000, $tel, 'error', 'fallo del proveedor de inferencia');
        return ['envelope' => null, 'tesis_id' => null, 'clasificacion' => 'error', 'reused' => false];
    }

    // $clasif / $env = resultado CANDIDATO (inferencia local; aún NO es la verdad publicada).
    $pub = tesis_publicar($pdo, $marca_id, $run, $env);                 // publica candidato, o reutiliza el ya vinculado

    // ── RESULTADO EFECTIVO: única fuente de verdad para el resto del pipeline ──
    // Es la tesis realmente vinculada al run: la insertada por este proceso (reused=false) o la
    // existente recuperada en el re-check transaccional (reused=true). En una carrera tardía el
    // candidato puede diferir del efectivo; SIEMPRE manda el efectivo. El candidato perdedor NO se
    // persiste ni se expone: solo queda como nota en telemetría.
    $efectivo = $pub['envelope'];
    $clasif_ef = (string)$efectivo['status'];                          // accepted | abstained (del activo vinculado)
    $nota = ($clasif_ef === 'accepted') ? ('angulo=' . (string)($efectivo['angulo'] ?? '')) : ('motivo=' . mb_substr((string)($efectivo['motivo'] ?? ''), 0, 120));
    $trazaCand = $pub['reused'] ? " candidato_descartado={$clasif}" : '';   // honesto: qué decidió este proceso vs. qué quedó
    _genoma_registrar($pdo, $run, $marca_id, 'tesis', $clasif_ef === 'accepted', (microtime(true)-$t0)*1000, $tel, $clasif_ef,
        "tesis_id={$pub['tesis_id']} reused=" . ($pub['reused'] ? 1 : 0) . "{$trazaCand} {$nota}");
    // Devuelve SOLO el efectivo (nunca el candidato descartado).
    return ['envelope' => $efectivo, 'tesis_id' => $pub['tesis_id'], 'clasificacion' => $clasif_ef, 'reused' => $pub['reused']];
}

// Directiva de enfoque del Creador. Con tesis accepted → DEFIENDE esa idea (no elige).
// Sin tesis (null) → enfoque legado, PROMPT byte-idéntico al de antes (compat/flag OFF).
function _creador_directiva(array $direccion, ?array $tesis): string {
    $enfoque = "Enfoque de este primer post: «{$direccion['titulo']}» — {$direccion['recomendacion']}";
    if ($tesis !== null && ($tesis['status'] ?? '') === 'accepted' && trim((string)($tesis['idea_central'] ?? '')) !== '') {
        $enfoque .= "\nÁNGULO ESTRATÉGICO (tu BRÚJULA, la decidió Creative Thesis): «"
                  . trim((string)$tesis['idea_central']) . "»"
                  . (!empty($tesis['contraste']) ? " · " . trim((string)$tesis['contraste']) : '')
                  . ". Es una DIRECCIÓN, no un libreto: cuéntala con libertad total (metáfora, historia, humor, ritmo) y sorprende. "
                  . "No la contradigas; dentro de ella, todo lo demás es tuyo (ángulo: " . (string)($tesis['angulo'] ?? 'otro') . ").";
    }
    return $enfoque;
}

// Creador C2: caption del primer post según la DIRECCIÓN elegida.
// Con $tesis accepted, el Creador pasa de "elige y escribe" a "defiende una tesis recibida".
//
// RECUPERACIÓN DE CALIDAD (2026-07-19): el escritor estaba enterrado bajo un muro de
// prohibiciones (grounding_producto_instruccion) + un volcado de ejes numéricos
// (voice_dna_instruccion) que forzaba jerga y producía ortografía butcheada y copy plano.
// Ahora el escritor va MAGRO: la VOZ REAL del dueño al frente (la esencia), ortografía
// impecable exigida, y solo una LÍNEA de hechos. El grounding NO se pierde: lo hace cumplir
// el Director (director_editorial), que sigue intacto. El escritor escribe libre; el Director valida.
function _creador_sistema(PDO $pdo, int $marca_id, array $marca, array $dna, array $direccion, string $habla_como, ?array $tesis): string {
    $enfoque = _creador_directiva($direccion, $tesis);
    // La identidad (persona vs organización) la decide el GENOMA, no el prompt.
    $identidad = $habla_como === 'organizacion'
        ? "- El negocio habla como ORGANIZACIÓN (nosotros / en «{$marca['nombre_negocio']}»). PROHIBIDO primera persona individual: nada de 'soy el fundador', 'soy la cara', 'mi nombre es', ni un nombre propio inventado.\n"
        : "- El negocio puede hablar cálido (yo/nosotros), pero NO inventes nombre propio, cargo ni título ('Dr.', 'fundador', 'el creador') que no esté en el perfil.\n";
    $glosario = trim((string)($marca['glosario'] ?? '')) !== ''
        ? "\nVOCABULARIO DEL NEGOCIO (el dueño lo corrigió — RESPÉTALO SIEMPRE):\n" . $marca['glosario'] . "\n" : '';
    if (is_file(__DIR__ . '/memoria.php')) require_once __DIR__ . '/memoria.php';
    $memoria = function_exists('memoria_para_prompt') ? memoria_para_prompt($pdo, $marca_id) : '';

    // LA ESENCIA: las palabras REALES del dueño, al frente. Es lo que el escritor debe capturar.
    $vozReal = trim((string)($marca['voz'] ?? '') ?: (string)($marca['descripcion'] ?? ''));
    $vozBloque = $vozReal !== ''
        ? "\nLA VOZ DEL DUEÑO (esta es la ESENCIA del negocio — captúrala en el caption, no la calques literal):\n\"" . mb_substr($vozReal, 0, 600) . "\"\n"
        : '';

    // HECHOS en UNA línea positiva (antes era un muro de 'NO inventes...'). El grounding duro lo hace el Director.
    $rawp = $marca['productos'] ?? [];
    if (is_string($rawp)) $rawp = json_decode($rawp, true) ?: [];
    $prods = [];
    foreach ((array)$rawp as $p) { $nom = is_array($p) ? trim((string)($p['nombre'] ?? '')) : trim((string)$p); if ($nom !== '') $prods[] = rtrim($nom, ' :.-'); }
    $lista  = $prods ? implode(', ', array_slice($prods, 0, 12)) : '';
    $oferta = trim((string)($marca['ofertas'] ?? ''));
    $nombre = trim((string)($marca['nombre_negocio'] ?? ''));
    $hechos = "- HECHOS reales (no inventes otros — el ESTILO es 100% tuyo): "
        . ($lista !== '' ? "ofrece {$lista}. " : "sin productos declarados: no inventes ninguno. ")
        . ($oferta !== '' ? "Oferta: {$oferta}. " : "")
        . "Nombre exacto: «{$nombre}». No inventes sabores, precios ni reseñas que no estén.\n";

    return "Eres el CREADOR de contenido del Corillo (NUNCA te presentes como 'el creador'). Escribes UN caption para redes "
         . "de una microempresa boricua, con PERSONALIDAD, ritmo y picardía — nunca traducido, nunca 'AI slop', nunca plano. Reglas:\n"
         . "- Español puertorriqueño AUTÉNTICO y con alma, pero con ORTOGRAFÍA Y GRAMÁTICA IMPECABLES (cero errores, acentos correctos). "
         . "El sabor boricua vive en las PALABRAS y el RITMO, JAMÁS en escribir mal: escribe 'para', 'nada', 'todo' completos; no butchees palabras ni pongas apóstrofes por todos lados.\n"
         . "- Vocabulario local natural (bizcocho, no 'tarta'; chavos; nene/nena) — solo si le queda al negocio; NO fuerces jerga callejera.\n"
         . "- 1-2 emojis máximo. Cierra con un llamado a la acción (según el contacto de abajo) y 3-4 hashtags locales.\n"
         . "- Máximo 60 palabras. Devuelve SOLO el caption, sin comillas ni explicación.\n"
         . $hechos
         . $vozBloque
         . contacto_instruccion($marca)
         . $identidad
         . $enfoque . "\n"
         . tono_instruccion($marca)
         . $glosario
         . $memoria;
}

// KILL determinista del CTA roto: "escríbenos al ." / "por WhatsApp al ." (número en blanco).
// Pasa aunque el modelo se equivoque: elimina el "al" colgante sin número detrás, y limpia
// dobles espacios/puntuación. "al momento", "al horno" (seguidos de palabra) NO se tocan.
function _limpiar_cta_rota(string $t): string {
    // "... al ." / "... al," / "... al!" / "... al" al final → quita el "al" colgante (sin dígito detrás)
    $t = preg_replace('/\s+al\b\s*(?=[.,;:!?)]|$)/iu', '', $t);
    // "WhatsApp al ." o "número al " que quedó sin dígitos → deja la frase limpia
    $t = preg_replace('/\s+al\s+(?=[.,;:!?)]|$)/iu', '', $t);
    // Limpia " ." colgante, comas dobles y espacios múltiples
    $t = preg_replace('/\s+([.,;:!?])/u', '$1', $t);
    $t = preg_replace('/([.,])\1+/u', '$1', $t);
    $t = preg_replace('/[ \t]{2,}/u', ' ', $t);
    return trim($t);
}

function genoma_caption(PDO $pdo, int $marca_id, array $marca, array $dna, array $direccion, string $habla_como = 'persona', string $revision = '', ?array $tesis = null): string {
    $ctx = marca_contexto($marca);
    $sistema = _creador_sistema($pdo, $marca_id, $marca, $dna, $direccion, $habla_como, $tesis);
    // Regeneración del Director: corrige el HECHO señalado SIN aplanar la personalidad.
    $prompt = "Perfil del negocio:\n{$ctx}\n\nEscribe UN caption para arrancar con ese enfoque, en SU voz."
            . ($revision !== '' ? "\n\nUn editor marcó UN ajuste (normalmente un hecho no respaldado o un CTA). Corrígelo SIN perder la chispa: reescribe con la MISMA personalidad, ritmo y voz boricua; solo respeta lo señalado. Ajuste: {$revision}" : '')
            . "\n\nDevuelve SOLO el caption.";
    $r = ia_ejecutar($pdo, 'creador', 'Primer post (genoma)', $prompt, [
        'marca_id'=>$marca_id, 'sistema'=>$sistema, 'temperatura'=>0.95, 'max_tokens'=>400, 'thinking_budget'=>0,
        'mock_texto'=>'En ' . ($marca['nombre_negocio'] ?? 'la casa') . ' arrancamos con algo bueno para ti.',
    ]);
    return _limpiar_cta_rota(trim((string)$r['texto']));
}

// ── ETAPA 4-5-6 · Primer post → Director → Resultado (con fallback curado) ──
// $tesis (opcional): activo Creative Thesis accepted que el Creador DEFIENDE. Se decide
// UNA vez arriba (orquestación) y se captura en el closure → la MISMA idea llega a todos
// los reintentos del Director (solo cambia la revisión editorial). null → ruta de compat.
function genoma_post(PDO $pdo, array $genoma, array $direccion, string $run, ?array $tesis = null): array {
    $marca_id = (int)$genoma['marca']['id'];
    $marca = leer_marca($pdo, $marca_id) ?: $genoma['marca'];  // productos parseado (como en C1)
    $m = $genoma['m'];
    $marca['pueblo'] = $m['pueblo'] ?? ($marca['pueblo'] ?? '');  // la CIUDAD/mercado llega al Creator vía marca_contexto
    $ctxED = [
        'nombre_negocio'=>$m['nombre_negocio'], 'productos'=>$m['producto'],
        'ofertas'=>trim((string)($marca['ofertas'] ?? '')), 'pueblo'=>$m['pueblo'],
        'whatsapp'=>$m['whatsapp'], 'instagram'=>trim((string)($marca['instagram'] ?? '')),
        // La HISTORIA/VOZ del dueño va al Director como HECHO (no invento): sin esto rechazaba
        // el storytelling grounded y caía al fallback genérico. (fix 2026-07-19)
        'voz'=>trim((string)($marca['voz'] ?? '') ?: (string)($marca['descripcion'] ?? '')),
    ];
    $t0 = microtime(true); $snap = _genoma_snap($pdo);
    try {
        $habla = $genoma['habla_como'] ?? 'persona';
        // El closure captura $tesis: el Director puede regenerar N veces, pero la idea
        // NO cambia — Creative Thesis NO se vuelve a invocar; solo cambia $instr (revisión).
        $ed = generar_con_director($pdo, $marca_id,
            fn($instr) => genoma_caption($pdo, $marca_id, $marca, $genoma['dna'], $direccion, $habla, $instr, $tesis),
            $genoma['dna'], $ctxED, ['max_regeneraciones'=>GENOMA_MAX_REGEN]);
        $tel = _genoma_delta($pdo, $snap);
        // Desenlace observable del Director.
        $resultado = $ed['fallback'] ? 'fallback' : ($ed['intentos'] > 1 ? 'regenerado' : 'aprobado_directo');
        $rechazos = 0;
        foreach (($ed['historial'] ?? []) as $h) if (empty($h['aprobado'])) $rechazos++;
        $motivo = "intentos={$ed['intentos']} rechazos_director={$rechazos}" . ($ed['fallback'] ? ' → fallback_curado' : '');
        _genoma_registrar($pdo, $run, $marca_id, 'director', !$ed['fallback'], (microtime(true)-$t0)*1000, $tel, $resultado, $motivo);
        $ed['run_uid'] = $run;
        return $ed;
    } catch (Throwable $e) {
        // Fallback duro: caption curado y específico. Nunca rompe la experiencia.
        $fb = contenido_fallback_seguro($ctxED, $genoma['dna']);
        _genoma_registrar($pdo, $run, $marca_id, 'director', false, (microtime(true)-$t0)*1000, _genoma_delta($pdo,$snap), 'fallback', 'excepcion: '.$e->getMessage());
        return ['contenido'=>$fb, 'aprobado'=>true, 'fallback'=>true, 'intentos'=>1, 'llamadas'=>0, 'run_uid'=>$run];
    }
}

// CAPTIONS DEL PREVIEW: los 3 posts que se muestran en "Así empezaríamos nosotros" los escribe
// la IA (escritor ya arreglado), UNO por dirección — NO la plantilla curada de pm_catalogo, que
// salía idéntica entre negocios, sin chispa y con CTA roto. La plantilla queda SOLO como fallback
// (con el CTA limpio) si la IA falla. Sin tesis/director aquí (rapidez); el post elegido recibe el
// tratamiento completo al dar "Me encanta" (wm_generar). (fix 2026-07-19)
function genoma_captions_preview(PDO $pdo, array $genoma, array $direcciones, string $run): array {
    $marca_id = (int)$genoma['marca']['id'];
    try { $marca = leer_marca($pdo, $marca_id); } catch (Throwable $e) { $marca = $genoma['marca']; }
    $marca['pueblo'] = $genoma['m']['pueblo'] ?? ($marca['pueblo'] ?? '');
    $habla = $genoma['habla_como'] ?? 'persona';
    foreach ($direcciones as &$d) {
        try {
            $cap = genoma_caption($pdo, $marca_id, $marca, $genoma['dna'], $d, $habla, '', null);
            $d['caption'] = (trim($cap) !== '') ? $cap : _limpiar_cta_rota((string)($d['caption'] ?? ''));
        } catch (Throwable $e) {
            $d['caption'] = _limpiar_cta_rota((string)($d['caption'] ?? ''));   // fallback curado, CTA sin romper
        }
    }
    unset($d);
    return $direcciones;
}

// ── Orquestación de alto nivel ───────────────────────────────
// Preparar la reunión (etapas 1-3): lo que corre DURANTE el procesamiento del onboarding.
function pipeline_preparar(PDO $pdo, array $marca, ?string $run = null): array {
    $run = $run ?: genoma_run_uid();
    $genoma = genoma_construir($pdo, $marca, $run);
    $seleccion = genoma_seleccionar($pdo, $genoma, $run, 3);
    $direcciones = genoma_recomendaciones($pdo, $genoma, $seleccion, $run);
    $observaciones = genoma_observaciones($pdo, $genoma, $run, 3);  // The Working Moment (interpretaciones)
    $direcciones = genoma_captions_preview($pdo, $genoma, $direcciones, $run);  // captions reales (IA), no plantilla
    return ['run'=>$run, 'genoma'=>$genoma, 'direcciones'=>$direcciones, 'observaciones'=>$observaciones];
}
// Generar el post de la dirección elegida (etapas 4-6): corre DETRÁS de la escena, al elegir.
// $tesis (opcional): la orquestación (working_moment.php) entrega aquí la tesis accepted que
// el Creador debe defender; null cuando el flag está OFF o la tesis se abstuvo (ruta de compat).
function pipeline_post(PDO $pdo, array $genoma, array $direccion, string $run, ?array $tesis = null): array {
    return genoma_post($pdo, $genoma, $direccion, $run, $tesis);
}
