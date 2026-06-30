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
        "SELECT c.id, c.caption, c.plataforma, c.grafica_path, c.publicado_at,
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
 * ¿Tiene redes conectadas? (conexión activa en crecer_conexiones). Gating de
 * todo lo que dependa de Meta. A prueba de tabla ausente.
 */
function metricas_meta_conectado(PDO $pdo, int $marca_id): bool {
    try {
        return (bool)$pdo->query("SELECT 1 FROM crecer_conexiones WHERE marca_id={$marca_id} AND estado='activa' LIMIT 1")->fetchColumn();
    } catch (Throwable $e) { return false; }
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
