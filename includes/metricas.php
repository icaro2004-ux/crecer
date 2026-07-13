<?php
// ============================================================
//  CRECER — Métricas del centro de mando (Inicio + Resultados)
//  Definiciones EXACTAS aprobadas por Codex. Regla de oro:
//  NUNCA usar updated_at para analítica; sin datos falsos.
//  Fuente principal de publicaciones = crecer_contenido (no
//  crecer_publicaciones, que solo registra intentos vía Meta).
// ============================================================

/**
 * Producción del mes en curso. Definiciones:
 *  - creados_mes:    created_at dentro del mes, sin importar el estado.
 *  - esperando_ok:   estado actual 'borrador'.
 *  - listos:         estado 'aprobado' o 'programado'.
 *  - publicados_mes: estado 'publicado' Y publicado_at dentro del mes.
 */
function metricas_produccion(PDO $pdo, int $marca_id): array {
    $q = $pdo->prepare(
        "SELECT
            SUM(YEAR(created_at)=YEAR(NOW()) AND MONTH(created_at)=MONTH(NOW()))                      AS creados_mes,
            SUM(estado='borrador')                                                                    AS esperando_ok,
            SUM(estado IN ('aprobado','programado'))                                                  AS listos,
            SUM(estado='publicado' AND publicado_at IS NOT NULL
                AND YEAR(publicado_at)=YEAR(NOW()) AND MONTH(publicado_at)=MONTH(NOW()))              AS publicados_mes
         FROM crecer_contenido WHERE marca_id=?");
    $q->execute([$marca_id]);
    $r = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'creados_mes'    => (int)($r['creados_mes']    ?? 0),
        'esperando_ok'   => (int)($r['esperando_ok']   ?? 0),
        'listos'         => (int)($r['listos']         ?? 0),
        'publicados_mes' => (int)($r['publicados_mes'] ?? 0),
    ];
}

/**
 * Racha: semanas consecutivas (ISO) con AL MENOS una publicación confirmada
 * (estado='publicado' con publicado_at). Tolerante: si esta semana aún no hay
 * post pero la pasada sí, la racha sigue contando desde la pasada. Si la última
 * publicación es más vieja que eso, la racha "actual" es 0 (no inflamos).
 */
function metricas_racha(PDO $pdo, int $marca_id): int {
    $q = $pdo->prepare(
        "SELECT DISTINCT YEARWEEK(publicado_at, 3) wk
         FROM crecer_contenido
         WHERE marca_id=? AND estado='publicado' AND publicado_at IS NOT NULL");
    $q->execute([$marca_id]);
    $weeks = array_flip(array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)));
    if (!$weeks) return 0;

    $now = time();
    $curwk  = (int)date('oW', $now);
    $prevwk = (int)date('oW', $now - 7*86400);
    if (!isset($weeks[$curwk]) && !isset($weeks[$prevwk])) return 0;

    // Empezar en la semana más reciente que tenga post (esta o la pasada).
    $cursor = isset($weeks[$curwk]) ? $now : ($now - 7*86400);
    $racha = 0;
    while (isset($weeks[(int)date('oW', $cursor)])) {
        $racha++;
        $cursor -= 7*86400;
    }
    return $racha;
}

/**
 * Próximos posts: fecha_programada futura Y estado 'aprobado'/'programado'.
 */
function metricas_proximos(PDO $pdo, int $marca_id, int $limit = 3): array {
    $q = $pdo->prepare(
        "SELECT id, caption, plataforma, grafica_path, fecha_programada, estado
         FROM crecer_contenido
         WHERE marca_id=? AND estado IN ('aprobado','programado')
           AND fecha_programada IS NOT NULL AND fecha_programada > NOW()
         ORDER BY fecha_programada ASC
         LIMIT {$limit}");
    $q->execute([$marca_id]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Lista de PUBLICACIONES. Fuente = crecer_contenido (estado='publicado');
 * LEFT JOIN a la última publicación exitosa SOLO para external_id/permalink.
 */
function metricas_publicaciones(PDO $pdo, int $marca_id, int $limit = 20): array {
    $q = $pdo->prepare(
        "SELECT c.id, c.caption, c.plataforma, c.plataformas, c.grafica_path, c.publicado_at,
                p.external_id, p.permalink
         FROM crecer_contenido c
         LEFT JOIN crecer_publicaciones p
           ON p.id = (SELECT p2.id FROM crecer_publicaciones p2
                      WHERE p2.contenido_id = c.id AND p2.estado='ok'
                      ORDER BY p2.id DESC LIMIT 1)
         WHERE c.marca_id=? AND c.estado='publicado'
         ORDER BY c.publicado_at DESC, c.id DESC
         LIMIT {$limit}");
    $q->execute([$marca_id]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Estado por RED de un conjunto de posts (para "¿salió en IG y FB?").
 * Devuelve [contenido_id => ['instagram'=>['estado','permalink','error'], 'facebook'=>...]].
 * Se queda con el ÚLTIMO intento por (post, red).
 */
function metricas_redes_de_posts(PDO $pdo, int $marca_id, array $ids): array {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $out = [];
    try {
        $q = $pdo->prepare(
            "SELECT contenido_id, plataforma, estado, permalink, error_msg
             FROM crecer_publicaciones
             WHERE marca_id=? AND contenido_id IN ($in)
             ORDER BY id ASC");
        $q->execute(array_merge([$marca_id], $ids));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['contenido_id']][$r['plataforma']] = [
                'estado'    => $r['estado'],
                'permalink' => $r['permalink'],
                'error'     => $r['error_msg'],
            ];
        }
    } catch (Throwable $e) {}
    return $out;
}

/** Detalle de la conexión activa: ¿qué redes están realmente enganchadas? */
function metricas_conexion_detalle(PDO $pdo, int $marca_id): array {
    try {
        $r = $pdo->query("SELECT ig_user_id, fb_page_id FROM crecer_conexiones
                          WHERE marca_id={$marca_id} AND estado='activa'
                          ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        return ['ig' => !empty($r['ig_user_id']), 'fb' => !empty($r['fb_page_id'])];
    } catch (Throwable $e) { return ['ig' => false, 'fb' => false]; }
}

/**
 * ¿Tiene redes conectadas? (conexión activa en crecer_conexiones). Gating de
 * todo lo que dependa de Meta. A prueba de tabla ausente.
 */
function metricas_meta_conectado(PDO $pdo, int $marca_id): bool {
    try {
        return (bool)$pdo->query("SELECT 1 FROM crecer_conexiones WHERE marca_id={$marca_id} AND estado='activa' LIMIT 1")->fetchColumn();
    } catch (Throwable $e) { return false; }
}

// ============================================================
//  INSIGHTS DE META — alcance / interacciones por post publicado.
//  Fuente: crecer_metricas (cache). Se refresca desde Meta por
//  cron o botón. Los lectores NO llaman a Meta (solo leen cache);
//  quien llama a la API es metricas_refrescar_insights().
// ============================================================

/**
 * Insights guardados de un conjunto de posts.
 * @return array [contenido_id => ['instagram'=>row, 'facebook'=>row]]
 *   donde row tiene alcance,me_gusta,comentarios,guardados,compartidos,
 *   interacciones,actualizado_at.
 */
function metricas_insights_de_posts(PDO $pdo, int $marca_id, array $ids): array {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) return [];
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $out = [];
    try {
        $q = $pdo->prepare(
            "SELECT contenido_id, plataforma, alcance, me_gusta, comentarios,
                    guardados, compartidos, interacciones, actualizado_at
             FROM crecer_metricas
             WHERE marca_id=? AND contenido_id IN ($in)");
        $q->execute(array_merge([$marca_id], $ids));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['contenido_id']][$r['plataforma']] = $r;
        }
    } catch (Throwable $e) {}
    return $out;
}

/** Totales de alcance/interacciones del MES en curso (posts publicados). */
function metricas_totales_insights(PDO $pdo, int $marca_id): array {
    try {
        $q = $pdo->prepare(
            "SELECT COALESCE(SUM(m.alcance),0)       AS alcance,
                    COALESCE(SUM(m.interacciones),0) AS interacciones,
                    COUNT(*)                         AS n
             FROM crecer_metricas m
             JOIN crecer_contenido c ON c.id = m.contenido_id
             WHERE m.marca_id=? AND c.estado='publicado' AND c.publicado_at IS NOT NULL
               AND YEAR(c.publicado_at)=YEAR(NOW()) AND MONTH(c.publicado_at)=MONTH(NOW())");
        $q->execute([$marca_id]);
        $r = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'alcance'       => (int)($r['alcance']       ?? 0),
            'interacciones' => (int)($r['interacciones'] ?? 0),
            'n'             => (int)($r['n']             ?? 0),
        ];
    } catch (Throwable $e) {
        return ['alcance'=>0, 'interacciones'=>0, 'n'=>0];
    }
}

/** Guarda (upsert) el insight de un post en una red. */
function metricas_guardar_insight(PDO $pdo, int $contenido_id, int $marca_id, string $plataforma, ?string $external_id, array $ins): void {
    $pdo->prepare(
        "INSERT INTO crecer_metricas
            (contenido_id, marca_id, plataforma, external_id,
             alcance, me_gusta, comentarios, guardados, compartidos, interacciones, crudo)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            external_id=VALUES(external_id), alcance=VALUES(alcance), me_gusta=VALUES(me_gusta),
            comentarios=VALUES(comentarios), guardados=VALUES(guardados), compartidos=VALUES(compartidos),
            interacciones=VALUES(interacciones), crudo=VALUES(crudo), actualizado_at=NOW()"
    )->execute([
        $contenido_id, $marca_id, $plataforma, $external_id,
        $ins['alcance'] ?? null, $ins['me_gusta'] ?? null, $ins['comentarios'] ?? null,
        $ins['guardados'] ?? null, $ins['compartidos'] ?? null, $ins['interacciones'] ?? null,
        $ins['crudo'] ?? null,
    ]);
}

/**
 * Refresca desde Meta los insights de los posts publicados de una marca
 * cuya métrica falta o está vieja (> $stale_horas). Llama a la Graph API;
 * úsalo desde cron o desde un botón (nunca en el render normal de la página).
 *
 * @return array ['ok'=>bool, 'motivo'=>?string, 'n'=>int (guardados),
 *                'revisadas'=>int, 'errores'=>string[]]
 */
function metricas_refrescar_insights(PDO $pdo, int $marca_id, int $max = 10, int $stale_horas = 6): array {
    require_once __DIR__ . '/meta.php';
    if (!meta_configurado()) return ['ok'=>false, 'motivo'=>'sin_app', 'n'=>0];

    $conx = conexion_de_marca($pdo, $marca_id);
    if (!$conx || ($conx['estado'] ?? '') !== 'activa' || empty($conx['page_access_token'])) {
        return ['ok'=>false, 'motivo'=>'sin_conexion', 'n'=>0];
    }
    $token = (string)$conx['page_access_token'];
    $max   = max(1, min(50, $max));
    $stale = max(0, (int)$stale_horas);

    // Último intento OK por (post, red) de posts publicados, cuya métrica
    // no exista todavía o esté más vieja que la ventana de frescura.
    $q = $pdo->prepare(
        "SELECT p.contenido_id, p.plataforma, p.external_id
         FROM crecer_publicaciones p
         JOIN crecer_contenido c ON c.id = p.contenido_id AND c.estado='publicado'
         LEFT JOIN crecer_metricas m
                ON m.contenido_id = p.contenido_id AND m.plataforma = p.plataforma
         WHERE p.marca_id = ? AND p.estado='ok' AND p.external_id IS NOT NULL
           AND p.id = (SELECT MAX(p2.id) FROM crecer_publicaciones p2
                       WHERE p2.contenido_id = p.contenido_id
                         AND p2.plataforma = p.plataforma AND p2.estado='ok')
           AND (m.id IS NULL OR m.actualizado_at < (NOW() - INTERVAL {$stale} HOUR))
         ORDER BY c.publicado_at DESC
         LIMIT {$max}");
    $q->execute([$marca_id]);
    $filas = $q->fetchAll(PDO::FETCH_ASSOC);

    $n = 0; $errores = [];
    foreach ($filas as $f) {
        try {
            $ins = ($f['plataforma'] === 'facebook')
                 ? meta_insights_fb((string)$f['external_id'], $token)
                 : meta_insights_ig((string)$f['external_id'], $token);
            metricas_guardar_insight($pdo, (int)$f['contenido_id'], $marca_id, $f['plataforma'], $f['external_id'], $ins);
            $n++;
        } catch (Throwable $e) {
            $errores[] = "#{$f['contenido_id']}/{$f['plataforma']}: " . $e->getMessage();
        }
    }
    return ['ok'=>true, 'motivo'=>null, 'n'=>$n, 'revisadas'=>count($filas), 'errores'=>$errores];
}

// ============================================================
//  PARTE DEL DÍA — saludo proactivo de La Estratega en Inicio.
//  100% determinista: hechos reales de la BD + una sugerencia por
//  reglas (calendario boricua / quincena / fin de semana / estado).
//  NUNCA inventa cifras: si no hay dato, esa línea no aparece.
// ============================================================

/** El post publicado más reciente del que YA tenemos métricas de Meta. */
function metricas_ultimo_post_con_datos(PDO $pdo, int $marca_id): ?array {
    try {
        $q = $pdo->prepare(
            "SELECT c.caption, c.publicado_at,
                    SUM(m.alcance) AS alcance, SUM(m.me_gusta) AS me_gusta
             FROM crecer_metricas m
             JOIN crecer_contenido c ON c.id = m.contenido_id
             WHERE m.marca_id = ? AND c.estado='publicado' AND m.alcance IS NOT NULL
             GROUP BY c.id
             ORDER BY c.publicado_at DESC, c.id DESC
             LIMIT 1");
        $q->execute([$marca_id]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r || $r['alcance'] === null) return null;
        return [
            'caption'      => (string)($r['caption'] ?? ''),
            'publicado_at' => $r['publicado_at'] ?? null,
            'alcance'      => (int)$r['alcance'],
            'me_gusta'     => $r['me_gusta'] !== null ? (int)$r['me_gusta'] : null,
        ];
    } catch (Throwable $e) { return null; }
}

/** Sugerencia concreta para HOY (una sola frase), por reglas. */
function _crecer_sugerencia_hoy(DateTime $ahora, array $cuenta, array $prod): string {
    $anio = (int)$ahora->format('Y');
    $dia  = (int)$ahora->format('j');
    $fin  = (int)$ahora->format('t');       // último día del mes
    $dow  = (int)$ahora->format('N');       // 1=lun … 7=dom
    $hoy0 = (clone $ahora)->setTime(0,0,0)->getTimestamp();

    // 1) Fecha boricua cercana (dentro de 5 días) — lo que más jala.
    $eventos = [
        ["$anio-01-06",                                    "Reyes — la gente compra pa' los nenes. Tira una promo de Reyes."],
        ["$anio-02-14",                                    "San Valentín — arma un combo o regalo pa' parejas."],
        [@strtotime("second sunday of may $anio"),         "Día de las Madres — el día más fuerte del año pa' vender. Prepara una oferta especial."],
        [@strtotime("third sunday of june $anio"),         "Día de los Padres — saca un combo o regalo pa' papá."],
        ["$anio-07-04",                                    "fin de semana del 4 de julio — mucha gente janguea. Postea algo hoy."],
        ["$anio-10-31",                                    "Halloween — tira algo temático, que la gente lo comparte."],
        [@strtotime("fourth thursday of november $anio"),  "Acción de Gracias — arma tu oferta de la temporada."],
        ["$anio-12-24",                                    "Navidad — es tu pico de ventas. Empuja tus combos navideños."],
        ["$anio-12-31",                                    "fin de año — última llamada pa' cerrar el mes fuerte."],
    ];
    foreach ($eventos as $ev) {
        $ets = is_int($ev[0]) ? $ev[0] : @strtotime($ev[0]);
        if (!$ets) continue;
        $dif = ($ets - $hoy0) / 86400;
        if ($dif >= 0 && $dif <= 5) return "Se acerca " . $ev[1];
    }

    // 2) Quincena (1, 14–15, o los 2 últimos del mes): hay chavos frescos.
    if ($dia === 1 || $dia === 14 || $dia === 15 || $dia >= $fin - 1) {
        return "Es quincena — la gente tiene chavos frescos. Buen día pa' una promo.";
    }

    // 3) Fin de semana (vie/sáb): la gente sale y compra.
    if ($dow === 5 || $dow === 6) {
        return "Es fin de semana, la gente janguea — postea algo hoy pa' agarrar ese tráfico.";
    }

    // 4) Estado del negocio.
    if (!empty($cuenta['borrador'])) {
        return "Aprueba lo que tienes en cola pa' que salga y no se enfríe.";
    }
    if (empty($prod['publicados_mes']) && empty($cuenta['aprobado']) && empty($cuenta['programado'])) {
        return "Pídele al corillo contenido nuevo pa' esta semana y montamos el ritmo.";
    }
    return "Buen día pa' sacar algo fresco y mantener el ritmo de publicación.";
}

/**
 * Arma el "parte del día" de La Estratega. Devuelve:
 *   ['saludo'=>str, 'hechos'=>string[], 'sugerencia'=>str]
 * $ctx: ['negocio'=>str,'prod'=>array,'racha'=>int,'cuenta'=>array,'meta_ok'=>bool]
 */
function crecer_parte_del_dia(PDO $pdo, int $marca_id, array $ctx): array {
    $negocio = trim((string)($ctx['negocio'] ?? '')) ?: 'tu negocio';
    $prod    = $ctx['prod']   ?? [];
    $racha   = (int)($ctx['racha'] ?? 0);
    $cuenta  = $ctx['cuenta'] ?? [];
    $meta_ok = !empty($ctx['meta_ok']);

    try { $ahora = new DateTime('now', new DateTimeZone('America/Puerto_Rico')); }
    catch (Throwable $e) { $ahora = new DateTime(); }
    $hh = (int)$ahora->format('G');
    $saludo = $hh < 12 ? 'Buenos días' : ($hh < 19 ? 'Buenas tardes' : 'Buenas noches');

    $hechos = [];

    // Cómo rindió tu último post (solo si Meta conectado + hay métricas).
    $ultimo = metricas_ultimo_post_con_datos($pdo, $marca_id);
    if ($ultimo) {
        $f = "Tu último post llegó a " . number_format($ultimo['alcance']) . " personas";
        if ($ultimo['me_gusta'] !== null) $f .= " y " . number_format($ultimo['me_gusta']) . " me gusta";
        $hechos[] = $f . ".";
    }

    // Lo pendiente (lo más urgente primero).
    if (!empty($cuenta['fallido'])) {
        $n = (int)$cuenta['fallido'];
        $hechos[] = "Ojo: {$n} post" . ($n==1?'':'s') . " no se pudo publicar — hay que resolverlo.";
    } elseif (!empty($cuenta['borrador'])) {
        $n = (int)$cuenta['borrador'];
        $hechos[] = "Tienes {$n} post" . ($n==1?'':'s') . " esperando tu OK.";
    }

    // Racha (refuerzo positivo).
    if ($racha >= 2) $hechos[] = "Llevas {$racha} semanas seguidas publicando — no rompas la racha.";

    // Sin datos de rendimiento y sin Meta: invita a conectar (una vez).
    if (!$ultimo && !$meta_ok) $hechos[] = "Conecta tus redes y te digo cuánta gente ve cada post.";

    return [
        'saludo'     => "{$saludo}. Aquí, pendiente a {$negocio}.",
        'hechos'     => $hechos,
        'sugerencia' => _crecer_sugerencia_hoy($ahora, $cuenta, $prod),
    ];
}

/**
 * Observación útil — SOLO HECHOS antes de Meta (nada de "mueve la aguja" ni
 * impacto externo). Devuelve null si no hay nada honesto que decir.
 */
function metrica_observacion(array $prod, int $racha): ?string {
    if ($racha >= 2) {
        return "Llevas {$racha} semanas publicando consistentemente.";
    }
    if ($prod['esperando_ok'] > 0) {
        $n = $prod['esperando_ok'];
        return "Tienes {$n} post" . ($n==1?'':'s') . " esperando tu OK.";
    }
    if ($prod['publicados_mes'] === 0 && $prod['creados_mes'] > 0) {
        return "Tienes contenido creado, pero aún nada publicado este mes.";
    }
    return null;
}
