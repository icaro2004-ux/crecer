<?php
// ============================================================
//  CRECER — EL OPTIMIZADOR (el corillo aprende de SUS resultados)
//  includes/optimizador.php
//
//  Cierra el loop: métricas reales → lecciones → el próximo plan
//  las usa. 100% DETERMINISTA (cero llamadas a modelos, cero
//  costo): solo emite una lección si hay evidencia — mínimo 3
//  posts en el patrón y ≥30% de diferencia vs. el promedio de la
//  marca. Lo que no está medido, no se afirma.
//
//  Las lecciones viven en crecer_memoria (tipo='patron',
//  fuente='optimizador'): el dueño las VE en su Home y el
//  planificador las INYECTA en el prompt del mes. Cada corrida
//  supersede las anteriores (los números cambian con cada post).
//
//  Prueba viva: _cache.php?test=optimizador[&marca=ID]
// ============================================================

require_once __DIR__ . '/memoria.php';

const OPT_MIN_POSTS  = 5;    // menos que esto = muy poca data para opinar
const OPT_MIN_BUCKET = 3;    // posts mínimos en un patrón
const OPT_MIN_DELTA  = 30;   // % mínimo de diferencia para que sea lección

/** Junta la data real por post: cuándo salió, qué era, cuánto rindió. */
function optimizador_datos(PDO $pdo, int $marca_id): array {
    $q = $pdo->prepare(
        "SELECT c.id, c.tipo, c.publicado_at, m.plataforma,
                COALESCE(m.alcance,0)     AS alcance,
                COALESCE(m.impresiones,0) AS vistas,
                COALESCE(m.interacciones,
                         COALESCE(m.me_gusta,0)+COALESCE(m.comentarios,0)
                        +COALESCE(m.guardados,0)+COALESCE(m.compartidos,0)) AS inter
           FROM crecer_contenido c
           JOIN crecer_metricas m ON m.contenido_id = c.id
          WHERE c.marca_id=? AND c.estado='publicado' AND c.publicado_at IS NOT NULL
            AND m.alcance IS NOT NULL
          ORDER BY c.publicado_at DESC
          LIMIT 200");
    $q->execute([$marca_id]);
    $filas = $q->fetchAll(PDO::FETCH_ASSOC);

    // Consolidar por POST (un post puede tener métrica en IG y FB).
    $posts = [];
    foreach ($filas as $f) {
        $pid = (int)$f['id'];
        if (!isset($posts[$pid])) {
            $ts = strtotime((string)$f['publicado_at']);
            $posts[$pid] = [
                'tipo'    => (string)$f['tipo'],
                'dow'     => (int)date('w', $ts),          // 0=domingo
                'hora'    => (int)date('G', $ts),
                'alcance' => 0, 'inter' => 0,
                'por_red' => [],
            ];
        }
        $posts[$pid]['alcance'] += (int)$f['alcance'];
        $posts[$pid]['inter']   += (int)$f['inter'];
        $posts[$pid]['por_red'][(string)$f['plataforma']] = (int)$f['alcance'];
    }
    return $posts;
}

function _opt_franja(int $h): string {
    if ($h >= 6 && $h < 11)  return 'mañana';
    if ($h >= 11 && $h < 15) return 'mediodía';
    if ($h >= 15 && $h < 19) return 'tarde';
    if ($h >= 19)            return 'noche';
    return 'madrugada';
}
function _opt_franja_hora(string $f): int {
    return ['mañana'=>10, 'mediodía'=>12, 'tarde'=>17, 'noche'=>19, 'madrugada'=>10][$f] ?? 10;
}

/**
 * Analiza y devuelve las lecciones CON su evidencia. Cada una:
 * ['clave','titulo','detalle','n','delta_pct','datos'=>[...], 'dow'?, 'hora'?]
 */
function optimizador_analizar(PDO $pdo, int $marca_id): array {
    $posts = optimizador_datos($pdo, $marca_id);
    $n_tot = count($posts);
    if ($n_tot < OPT_MIN_POSTS) return [];   // honesto: sin data no hay lecciones

    $avg_g = array_sum(array_column($posts, 'alcance')) / $n_tot;
    if ($avg_g <= 0) return [];
    $DOW = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
    $lec = [];

    $mejor_bucket = function(array $grupos) use ($avg_g) {
        $best = null;
        foreach ($grupos as $k => $vals) {
            if (count($vals) < OPT_MIN_BUCKET) continue;
            $avg = array_sum($vals) / count($vals);
            $delta = ($avg / $avg_g - 1) * 100;
            if ($delta < OPT_MIN_DELTA) continue;
            if ($best === null || $avg > $best['avg']) {
                $best = ['k'=>$k, 'n'=>count($vals), 'avg'=>$avg, 'delta'=>$delta];
            }
        }
        return $best;
    };

    // 1) Mejor DÍA de la semana (por alcance real).
    $g = [];
    foreach ($posts as $p) $g[$p['dow']][] = $p['alcance'];
    if ($b = $mejor_bucket($g)) {
        $lec[] = [
            'clave' => 'mejor_dia', 'dow' => (int)$b['k'],
            'titulo' => 'Tu mejor día: ' . $DOW[$b['k']],
            'detalle' => sprintf('Tus posts de %s alcanzan %s personas de promedio — %+d%% sobre tu promedio general (%d posts medidos).',
                $DOW[$b['k']], number_format($b['avg'], 0, '.', ','), round($b['delta']), $b['n']),
            'n' => $b['n'], 'delta_pct' => round($b['delta']),
            'datos' => ['avg'=>round($b['avg']), 'avg_general'=>round($avg_g)],
        ];
    }

    // 2) Mejor FRANJA horaria.
    $g = [];
    foreach ($posts as $p) $g[_opt_franja($p['hora'])][] = $p['alcance'];
    if ($b = $mejor_bucket($g)) {
        $lec[] = [
            'clave' => 'mejor_franja', 'hora' => _opt_franja_hora((string)$b['k']),
            'titulo' => 'Tu mejor horario: la ' . $b['k'],
            'detalle' => sprintf('Cuando publicas en la %s tu alcance promedio es %s — %+d%% sobre tu promedio (%d posts medidos).',
                $b['k'], number_format($b['avg'], 0, '.', ','), round($b['delta']), $b['n']),
            'n' => $b['n'], 'delta_pct' => round($b['delta']),
            'datos' => ['franja'=>$b['k'], 'avg'=>round($b['avg']), 'avg_general'=>round($avg_g)],
        ];
    }

    // 3) TIPO de pieza (reel vs post — solo si ambos tienen base).
    $g = [];
    foreach ($posts as $p) $g[$p['tipo']][] = $p['alcance'];
    if (isset($g['reel'], $g['post']) && count($g['reel']) >= OPT_MIN_BUCKET && count($g['post']) >= OPT_MIN_BUCKET) {
        $ar = array_sum($g['reel']) / count($g['reel']);
        $ap = array_sum($g['post']) / count($g['post']);
        $hi = $ar >= $ap ? ['reel', $ar, count($g['reel'])] : ['post', $ap, count($g['post'])];
        $lo = $ar >= $ap ? ['post', $ap, count($g['post'])] : ['reel', $ar, count($g['reel'])];
        if ($lo[1] > 0 && ($hi[1] / $lo[1] - 1) * 100 >= OPT_MIN_DELTA) {
            $delta = round(($hi[1] / $lo[1] - 1) * 100);
            $lec[] = [
                'clave' => 'mejor_tipo',
                'titulo' => 'Tus ' . $hi[0] . 's rinden más',
                'detalle' => sprintf('Tus %ss alcanzan %s personas de promedio vs %s de tus %ss — %+d%% (%d vs %d medidos).',
                    $hi[0], number_format($hi[1], 0, '.', ','), number_format($lo[1], 0, '.', ','), $lo[0], $delta, $hi[2], $lo[2]),
                'n' => $hi[2] + $lo[2], 'delta_pct' => $delta,
                'datos' => ['ganador'=>$hi[0], 'avg_ganador'=>round($hi[1]), 'avg_perdedor'=>round($lo[1])],
            ];
        }
    }

    // 4) RED (IG vs FB — promedio por post en cada red, con base en ambas).
    $ig = []; $fb = [];
    foreach ($posts as $p) {
        if (isset($p['por_red']['instagram'])) $ig[] = $p['por_red']['instagram'];
        if (isset($p['por_red']['facebook']))  $fb[] = $p['por_red']['facebook'];
    }
    if (count($ig) >= OPT_MIN_BUCKET && count($fb) >= OPT_MIN_BUCKET) {
        $ai = array_sum($ig) / count($ig);
        $af = array_sum($fb) / count($fb);
        $hi = $ai >= $af ? ['Instagram', $ai, count($ig)] : ['Facebook', $af, count($fb)];
        $lo = $ai >= $af ? ['Facebook', $af, count($fb)] : ['Instagram', $ai, count($ig)];
        if ($lo[1] > 0 && ($hi[1] / $lo[1] - 1) * 100 >= OPT_MIN_DELTA) {
            $delta = round(($hi[1] / $lo[1] - 1) * 100);
            $lec[] = [
                'clave' => 'mejor_red',
                'titulo' => $hi[0] . ' es tu cancha',
                'detalle' => sprintf('Por post, %s te alcanza %s personas de promedio vs %s en %s — %+d%% (%d y %d posts medidos).',
                    $hi[0], number_format($hi[1], 0, '.', ','), number_format($lo[1], 0, '.', ','), $lo[0], $delta, $hi[2], $lo[2]),
                'n' => $hi[2] + $lo[2], 'delta_pct' => $delta,
                'datos' => ['ganador'=>$hi[0], 'avg_ganador'=>round($hi[1]), 'avg_perdedor'=>round($lo[1])],
            ];
        }
    }

    return $lec;
}

/** Guarda las lecciones en la memoria del negocio (supersede las anteriores). */
function optimizador_guardar(PDO $pdo, int $marca_id, array $lecciones): int {
    // Las corridas viejas caducan: los números cambian con cada post.
    try {
        $pdo->prepare("UPDATE crecer_memoria SET estado='superseded', updated_at=NOW()
                       WHERE marca_id=? AND fuente='optimizador' AND estado='activa'")
            ->execute([$marca_id]);
    } catch (Throwable $e) { error_log('optimizador_guardar supersede: ' . $e->getMessage()); }

    $n = 0;
    foreach ($lecciones as $l) {
        $id = memoria_escribir($pdo, $marca_id, [
            'tipo'        => 'patron',
            'dominio'     => 'marketing',
            'titulo'      => (string)$l['titulo'],
            'detalle'     => (string)$l['detalle'],
            'porque'      => 'Medido de tus posts publicados (datos de Meta — no es opinión).',
            'fuente'      => 'optimizador',
            'confianza'   => min(95, 55 + (int)$l['n'] * 5),   // más posts medidos = más confianza
            'peso'        => 70,
            'datos_json'  => ['clave'=>$l['clave'], 'delta_pct'=>$l['delta_pct'], 'n'=>$l['n']] + (array)($l['datos'] ?? []),
            'valid_until' => date('Y-m-d H:i:s', strtotime('+30 days')),
        ]);
        if ($id) $n++;
    }
    return $n;
}

/** Corre el ciclo completo para una marca. Devuelve resumen (para crons/tests). */
function optimizador_correr(PDO $pdo, int $marca_id): array {
    $lec = optimizador_analizar($pdo, $marca_id);
    $n = $lec ? optimizador_guardar($pdo, $marca_id, $lec) : 0;
    return ['lecciones' => count($lec), 'guardadas' => $n];
}

/** Bloque de texto para el PROMPT del planificador (lecciones vigentes). */
function optimizador_para_prompt(PDO $pdo, int $marca_id): string {
    try {
        $q = $pdo->prepare(
            "SELECT detalle FROM crecer_memoria
              WHERE marca_id=? AND fuente='optimizador' AND estado='activa'
                AND (valid_until IS NULL OR valid_until > NOW())
              ORDER BY peso DESC, confianza DESC LIMIT 5");
        $q->execute([$marca_id]);
        $filas = $q->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { return ''; }
    if (!$filas) return '';
    return "LO QUE DICEN TUS NÚMEROS (medido de los posts reales de ESTE negocio — úsalo para elegir días, horarios, tipos y red; no lo contradigas sin razón):\n- "
        . implode("\n- ", $filas) . "\n";
}

/** El mejor momento MEDIDO para publicar: ['dow'=>int|null, 'hora'=>int|null]. */
function optimizador_mejor_momento(PDO $pdo, int $marca_id): array {
    $out = ['dow' => null, 'hora' => null];
    try {
        $q = $pdo->prepare(
            "SELECT datos_json FROM crecer_memoria
              WHERE marca_id=? AND fuente='optimizador' AND estado='activa'
                AND (valid_until IS NULL OR valid_until > NOW())");
        $q->execute([$marca_id]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $dj) {
            $d = json_decode((string)$dj, true) ?: [];
            if (($d['clave'] ?? '') === 'mejor_franja' && isset($d['franja'])) $out['hora'] = _opt_franja_hora((string)$d['franja']);
        }
        // El mejor día se guarda re-analizando barato (el dow no viaja en datos_json del detalle).
        $lec = optimizador_analizar($pdo, $marca_id);
        foreach ($lec as $l) {
            if ($l['clave'] === 'mejor_dia')    $out['dow']  = (int)$l['dow'];
            if ($l['clave'] === 'mejor_franja') $out['hora'] = (int)$l['hora'];
        }
    } catch (Throwable $e) {}
    return $out;
}
