<?php
// ============================================================
//  CRECER — ADR-0004: El Analista Proactivo (motor de detección)
//
//  El Analista deja de ser una pantalla de métricas: VIGILA los KPIs reales
//  (crecer_contenido + crecer_metricas de Meta) y, cuando algo merece atención,
//  guarda una SEÑAL accionable en crecer_analista_senales. La tarjeta del Analista
//  en Home muestra la señal top (o "Sigue así" si no hay).
//
//  Principios (ADR-0004): no espera preguntas · detecta patrones · prioriza ·
//  explica el porqué · SIEMPRE propone UNA acción · grounded (nunca inventa).
//  Detección DETERMINISTA sobre datos reales (cero alucinación). La voz LLM
//  del Analista es una capa posterior (F3), no bloquea F1/F2.
// ============================================================

if (!defined('ANALISTA_SILENCIO_DIAS'))  define('ANALISTA_SILENCIO_DIAS', 7);
if (!defined('ANALISTA_CADUCA_DIAS'))    define('ANALISTA_CADUCA_DIAS', 12);

/** Base URL del panel (para las acciones). */
function _an_base(): string { return '/crecer/panel'; }

/** Nombre del Analista (personalizado si existe equipo_nombre). */
function analista_nombre(array $m): string {
    if (function_exists('equipo_nombre')) { $n = trim((string)equipo_nombre($m, 'analista')); if ($n !== '') return $n; }
    return 'El Analista';
}

/** Hash de dedup: una señal por (marca, tipo, semana ISO). */
function _an_hash(int $marca_id, string $tipo): string {
    return sha1($marca_id . ':' . $tipo . ':' . date('oW'));
}

/** Log de evidencia (criterio #2 XPRIZE): cada detección queda en crecer_ia_log. */
function _an_log(PDO $pdo, int $marca_id, string $tipo, string $evidencia, string $mensaje): void {
    try {
        $pdo->prepare("INSERT INTO crecer_ia_log (marca_id,agente,accion,modelo,prompt,respuesta,estado)
                       VALUES (?,?,?,?,?,?, 'ok')")
            ->execute([$marca_id, 'analista', 'Detectó: ' . $tipo, 'reglas', mb_substr($evidencia, 0, 800), mb_substr($mensaje, 0, 800)]);
    } catch (Throwable $e) { /* best-effort */ }
}

/**
 * Trae los posts publicados con sus métricas agregadas (IG+FB sumados) por pieza.
 * Devuelve [ ['id','tipo','publicado_at','ts','alcance','interacciones','comentarios'], ... ] más recientes primero.
 */
function _an_posts(PDO $pdo, int $marca_id): array {
    $q = $pdo->prepare(
        "SELECT c.id, c.tipo, c.publicado_at,
                COALESCE(SUM(m.alcance),0)       AS alcance,
                COALESCE(SUM(m.interacciones),0) AS interacciones,
                COALESCE(SUM(m.comentarios),0)   AS comentarios
         FROM crecer_contenido c
         LEFT JOIN crecer_metricas m ON m.contenido_id = c.id
         WHERE c.marca_id=? AND c.estado='publicado' AND c.publicado_at IS NOT NULL
         GROUP BY c.id, c.tipo, c.publicado_at
         ORDER BY c.publicado_at DESC");
    $q->execute([$marca_id]);
    $out = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = [
            'id'            => (int)$r['id'],
            'tipo'          => (string)($r['tipo'] ?: 'post'),
            'publicado_at'  => (string)$r['publicado_at'],
            'ts'            => strtotime((string)$r['publicado_at']) ?: 0,
            'alcance'       => (int)$r['alcance'],
            'interacciones' => (int)$r['interacciones'],
            'comentarios'   => (int)$r['comentarios'],
        ];
    }
    return $out;
}

/** Nombre bonito de un formato. */
function _an_fmt(string $t): string {
    return ['reel'=>'Reels', 'carrusel'=>'carruseles', 'post'=>'imágenes', 'story'=>'stories'][$t] ?? $t . 's';
}
function _an_fmt_sing(string $t): string {
    return ['reel'=>'Reel', 'carrusel'=>'carrusel', 'post'=>'imagen', 'story'=>'story'][$t] ?? $t;
}

// ─── REGLAS DE DETECCIÓN (cada una devuelve una señal o null) ───────────────

/** R1 · Silencio: hace ANALISTA_SILENCIO_DIAS+ que no publica. */
function _an_r_silencio(array $posts, int $mid): ?array {
    if (!$posts) return null;
    $dias = (int)floor((time() - $posts[0]['ts']) / 86400);
    if ($dias < ANALISTA_SILENCIO_DIAS) return null;
    $sev = $dias >= 14 ? 3 : 2;
    return [
        'tipo'=>'silencio', 'severidad'=>$sev,
        'titulo'=>'Hace rato que no publicas',
        'mensaje'=>"Van {$dias} días desde tu último post. La consistencia es lo que hace crecer una cuenta — no la dejes enfriar.",
        'accion_label'=>'Crear un post ahora', 'accion_url'=>_an_base()."/aprobar2.php?marca={$mid}",
        'evidencia'=>json_encode(['dias_sin_publicar'=>$dias]),
    ];
}

/** R2 · Caída: la mitad reciente rinde ≥30% menos que la anterior (≥4 posts con datos). */
function _an_r_caida(array $posts, int $mid): ?array {
    $con = array_values(array_filter($posts, fn($p)=>$p['interacciones']>0 || $p['alcance']>0));
    if (count($con) < 4) return null;
    $n = count($con); $half = intdiv($n, 2);
    $rec = array_slice($con, 0, $half);            // más recientes
    $ant = array_slice($con, $half, $half);        // anteriores (mismo tamaño)
    $avg = fn($a)=> array_sum(array_map(fn($p)=>$p['interacciones'], $a)) / max(1,count($a));
    $r = $avg($rec); $a = $avg($ant);
    if ($a <= 0 || $r >= $a * 0.7) return null;     // no bajó lo suficiente
    $pct = (int)round((1 - $r/$a) * 100);
    return [
        'tipo'=>'caida', 'severidad'=>2,
        'titulo'=>'Tus últimos posts bajaron',
        'mensaje'=>"Tus posts recientes están recibiendo {$pct}% menos interacciones que los anteriores. Vale la pena probar otro enfoque o formato.",
        'accion_label'=>'Probar otro enfoque', 'accion_url'=>_an_base()."/aprobar2.php?marca={$mid}",
        'evidencia'=>json_encode(['avg_reciente'=>round($r,1),'avg_anterior'=>round($a,1),'baja_pct'=>$pct]),
    ];
}

/** R3 · Formato ganador: un formato rinde ≥1.5x más que otro (cada uno ≥2 posts con datos). */
function _an_r_formato(array $posts, int $mid): ?array {
    $porTipo = [];
    foreach ($posts as $p) {
        if ($p['interacciones'] <= 0 && $p['alcance'] <= 0) continue;
        $porTipo[$p['tipo']][] = $p['interacciones'];
    }
    $avgs = [];
    foreach ($porTipo as $t=>$xs) { if (count($xs) >= 2) $avgs[$t] = array_sum($xs)/count($xs); }
    if (count($avgs) < 2) return null;
    arsort($avgs);
    $tipos = array_keys($avgs);
    $gana = $tipos[0]; $pierde = end($tipos);
    if ($avgs[$pierde] <= 0 || $avgs[$gana] < $avgs[$pierde] * 1.5) return null;
    $x = round($avgs[$gana] / max(0.1,$avgs[$pierde]), 1);
    return [
        'tipo'=>'formato_ganador', 'severidad'=>2,
        'titulo'=>'Encontré tu formato ganador',
        'mensaje'=>"Tus ".strtolower(_an_fmt($gana))." están rindiendo {$x}x más que tus "._an_fmt($pierde).". Vamos a hacer más de eso.",
        'accion_label'=>'Crear un '._an_fmt_sing($gana), 'accion_url'=>_an_base()."/aprobar2.php?marca={$mid}",
        'evidencia'=>json_encode(['gana'=>$gana,'pierde'=>$pierde,'ratio'=>$x,'avgs'=>$avgs]),
    ];
}

/** R4 · Mejor día: un día de la semana rinde ≥1.4x el promedio (≥6 posts, ≥3 días distintos). */
function _an_r_mejor_dia(array $posts, int $mid): ?array {
    $con = array_filter($posts, fn($p)=>$p['interacciones']>0);
    if (count($con) < 6) return null;
    $porDia = [];
    foreach ($con as $p) { $d=(int)date('N', $p['ts']); $porDia[$d][]=$p['interacciones']; }
    if (count($porDia) < 3) return null;
    $avgDia = []; foreach ($porDia as $d=>$xs) $avgDia[$d]=array_sum($xs)/count($xs);
    $global = array_sum(array_map(fn($p)=>$p['interacciones'],$con))/count($con);
    arsort($avgDia);
    $mejor = array_key_first($avgDia);
    if ($global<=0 || $avgDia[$mejor] < $global*1.4) return null;
    $dias = [1=>'lunes',2=>'martes',3=>'miércoles',4=>'jueves',5=>'viernes',6=>'sábados',7=>'domingos'];
    $nom = $dias[$mejor] ?? 'ese día';
    return [
        'tipo'=>'mejor_dia', 'severidad'=>1,
        'titulo'=>'Detecté tu mejor día',
        'mensaje'=>"Tu audiencia responde bastante mejor los {$nom}. Programa tu próximo post para ese día y aprovecha.",
        'accion_label'=>'Programar mi próximo post', 'accion_url'=>_an_base()."/calendario.php?marca={$mid}",
        'evidencia'=>json_encode(['mejor_dia'=>$nom,'avg_dia'=>round($avgDia[$mejor],1),'avg_global'=>round($global,1)]),
    ];
}

/**
 * VIGILAR: corre todas las reglas, guarda las señales nuevas (dedup por semana),
 * caduca las viejas, y loguea la evidencia. Idempotente y barato (agregados).
 * Devuelve cuántas señales NUEVAS se crearon.
 */
function analista_vigilar(PDO $pdo, int $marca_id): int {
    try {
        // Caduca señales abiertas viejas (ya no relevantes).
        $pdo->prepare("UPDATE crecer_analista_senales SET estado='caducada', updated_at=NOW()
                       WHERE marca_id=? AND estado IN ('nueva','vista')
                         AND created_at < (NOW() - INTERVAL " . (int)ANALISTA_CADUCA_DIAS . " DAY)")->execute([$marca_id]);

        $posts = _an_posts($pdo, $marca_id);
        // Si publicó en los últimos 3 días, el 'silencio' abierto ya no aplica → caduca.
        if ($posts && (time() - $posts[0]['ts']) < 3*86400) {
            $pdo->prepare("UPDATE crecer_analista_senales SET estado='caducada', updated_at=NOW()
                           WHERE marca_id=? AND tipo='silencio' AND estado IN ('nueva','vista')")->execute([$marca_id]);
        }

        $reglas = [ _an_r_silencio($posts,$marca_id), _an_r_caida($posts,$marca_id),
                    _an_r_formato($posts,$marca_id), _an_r_mejor_dia($posts,$marca_id) ];
        $nuevas = 0;
        foreach ($reglas as $s) {
            if (!$s) continue;
            $hash = _an_hash($marca_id, $s['tipo']);
            $ins = $pdo->prepare("INSERT IGNORE INTO crecer_analista_senales
                (marca_id,tipo,severidad,titulo,mensaje,accion_label,accion_url,evidencia,hash)
                VALUES (?,?,?,?,?,?,?,?,?)");
            $ins->execute([$marca_id,$s['tipo'],$s['severidad'],$s['titulo'],$s['mensaje'],
                           $s['accion_label'],$s['accion_url'],$s['evidencia'] ?? null,$hash]);
            if ($ins->rowCount() > 0) { $nuevas++; _an_log($pdo,$marca_id,$s['tipo'],$s['evidencia'] ?? '',$s['mensaje']); }
        }
        return $nuevas;
    } catch (Throwable $e) { error_log('analista_vigilar: '.$e->getMessage()); return 0; }
}

/** La señal TOP para Home: mayor severidad, más nueva, en estado nueva/vista. O null. */
function analista_senal_top(PDO $pdo, int $marca_id): ?array {
    try {
        $q = $pdo->prepare("SELECT * FROM crecer_analista_senales
                            WHERE marca_id=? AND estado IN ('nueva','vista')
                            ORDER BY severidad DESC, id DESC LIMIT 1");
        $q->execute([$marca_id]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

/** Marca una señal (vista | aceptada | descartada). Valida marca dueña. */
function analista_marcar(PDO $pdo, int $senal_id, int $marca_id, string $estado): void {
    if (!in_array($estado, ['vista','aceptada','descartada'], true)) return;
    try {
        $pdo->prepare("UPDATE crecer_analista_senales SET estado=?, updated_at=NOW() WHERE id=? AND marca_id=?")
            ->execute([$estado, $senal_id, $marca_id]);
    } catch (Throwable $e) {}
}

/** ¿Cuántas señales abiertas hay? (para "esta semana detectamos N oportunidades"). */
function analista_abiertas(PDO $pdo, int $marca_id): int {
    try { return (int)$pdo->query("SELECT COUNT(*) FROM crecer_analista_senales WHERE marca_id=".(int)$marca_id." AND estado IN ('nueva','vista')")->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}
