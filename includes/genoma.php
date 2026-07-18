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

// Presupuestos de seguridad (medir antes de tocar). Estabilidad > sofisticación.
if (!defined('GENOMA_MAX_REGEN'))       define('GENOMA_MAX_REGEN', 2);
if (!defined('GENOMA_TIMEOUT_POST_MS')) define('GENOMA_TIMEOUT_POST_MS', 20000); // techo blando por post

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

// Creador C2: caption del primer post según la DIRECCIÓN elegida + Voice DNA. Reusa reglas de C1.
function genoma_caption(PDO $pdo, int $marca_id, array $marca, array $dna, array $direccion, string $habla_como = 'persona', string $revision = ''): string {
    $ctx = marca_contexto($marca);
    $enfoque = "Enfoque de este primer post: «{$direccion['titulo']}» — {$direccion['recomendacion']}";
    // La identidad (persona vs organización) la decide el GENOMA, no el prompt.
    $identidad = $habla_como === 'organizacion'
        ? "- El negocio habla como ORGANIZACIÓN (nosotros / en «{$marca['nombre_negocio']}»). PROHIBIDO primera persona individual: nada de 'soy el fundador', 'soy la cara', 'mi nombre es', ni un nombre propio inventado.\n"
        : "- El negocio puede hablar cálido (yo/nosotros), pero NO inventes nombre propio, cargo ni título ('Dr.', 'fundador', 'el creador') que no esté en el perfil.\n";
    $sistema = "Eres el CREADOR del Corillo (NUNCA te presentes como 'el creador'). Caption corto (máx 45 palabras), "
             . "español puertorriqueño AUTÉNTICO, nunca 'AI slop'. PRINCIPIO DE GROUNDING: escribe SOLO lo respaldado por el perfil; "
             . "no generes nada que el Director tenga que borrar por falta de evidencia.\n"
             . grounding_producto_instruccion($marca)
             . contacto_instruccion($marca)
             . $identidad
             . $enfoque . "\n" . voice_dna_instruccion($dna);
    $prompt = "Perfil del negocio:\n{$ctx}\n\nEscribe UN caption para arrancar con ese enfoque, en SU voz."
            . ($revision !== '' ? "\n\nEL DIRECTOR EDITORIAL rechazó el intento anterior. CORRIGE exactamente: {$revision}" : '')
            . "\n\nDevuelve SOLO el caption.";
    $r = ia_ejecutar($pdo, 'creador', 'Primer post (genoma)', $prompt, [
        'marca_id'=>$marca_id, 'sistema'=>$sistema, 'temperatura'=>0.9, 'max_tokens'=>220, 'thinking_budget'=>0,
        'mock_texto'=>'En ' . ($marca['nombre_negocio'] ?? 'la casa') . ' arrancamos con algo bueno para ti.',
    ]);
    return trim((string)$r['texto']);
}

// ── ETAPA 4-5-6 · Primer post → Director → Resultado (con fallback curado) ──
function genoma_post(PDO $pdo, array $genoma, array $direccion, string $run): array {
    $marca_id = (int)$genoma['marca']['id'];
    $marca = leer_marca($pdo, $marca_id) ?: $genoma['marca'];  // productos parseado (como en C1)
    $m = $genoma['m'];
    $ctxED = [
        'nombre_negocio'=>$m['nombre_negocio'], 'productos'=>$m['producto'],
        'ofertas'=>trim((string)($marca['ofertas'] ?? '')), 'pueblo'=>$m['pueblo'],
        'whatsapp'=>$m['whatsapp'], 'instagram'=>trim((string)($marca['instagram'] ?? '')),
    ];
    $t0 = microtime(true); $snap = _genoma_snap($pdo);
    try {
        $habla = $genoma['habla_como'] ?? 'persona';
        $ed = generar_con_director($pdo, $marca_id,
            fn($instr) => genoma_caption($pdo, $marca_id, $marca, $genoma['dna'], $direccion, $habla, $instr),
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

// ── Orquestación de alto nivel ───────────────────────────────
// Preparar la reunión (etapas 1-3): lo que corre DURANTE el procesamiento del onboarding.
function pipeline_preparar(PDO $pdo, array $marca, ?string $run = null): array {
    $run = $run ?: genoma_run_uid();
    $genoma = genoma_construir($pdo, $marca, $run);
    $seleccion = genoma_seleccionar($pdo, $genoma, $run, 3);
    $direcciones = genoma_recomendaciones($pdo, $genoma, $seleccion, $run);
    return ['run'=>$run, 'genoma'=>$genoma, 'direcciones'=>$direcciones];
}
// Generar el post de la dirección elegida (etapas 4-6): corre DETRÁS de la escena, al elegir.
function pipeline_post(PDO $pdo, array $genoma, array $direccion, string $run): array {
    return genoma_post($pdo, $genoma, $direccion, $run);
}
