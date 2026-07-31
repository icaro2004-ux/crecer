<?php
// ============================================================
//  CRECER — Agentes de OPERACIONES del fundador  ·  includes/ops_agentes.php
//
//  La IA no solo corre el negocio del CLIENTE — también corre el de su CREADOR.
//  Estos agentes vigilan el negocio Crecer (retención, adquisición, soporte),
//  DECIDEN una acción, y se reportan al fundador (dashboard + email diario).
//  Cada vigilancia se loguea en crecer_ia_log = evidencia XPRIZE #2 (agentes
//  operando la EMPRESA, no solo el producto).
//
//  Construidos YA; DESPIERTAN con la data (sin clientes → "sin señales aún").
//  DETERMINISTA (cero alucinación); la voz LLM es capa posterior.
// ============================================================

if (!defined('OPS_RETENCION_DIAS')) define('OPS_RETENCION_DIAS', 10);  // días sin publicar = riesgo

/** Log de evidencia: cada corrida de un agente de ops queda en crecer_ia_log. */
function _ops_log(PDO $pdo, string $agente, string $accion, string $respuesta): void {
    try {
        $pdo->prepare("INSERT INTO crecer_ia_log (agente,accion,modelo,prompt,respuesta,estado)
                       VALUES (?,?,?,?,?, 'ok')")
            ->execute([$agente, $accion, 'reglas', '', mb_substr($respuesta, 0, 800)]);
    } catch (Throwable $e) { /* best-effort */ }
}

/**
 * AGENTE DE RETENCIÓN — clientes activos/en prueba que se están enfriando
 * (con suscripción viva pero sin publicar hace OPS_RETENCION_DIAS+ días, o
 * registrados hace rato y que nunca publicaron). Son los que van camino al churn.
 * Devuelve [ ['marca_id','nombre','dias','estado_sub'], ... ] ordenado por riesgo.
 */
function ops_retencion_riesgo(PDO $pdo): array {
    try {
        $sql =
          "SELECT m.id, m.nombre_negocio, s.estado AS sub,
                  DATEDIFF(NOW(), COALESCE(
                     (SELECT MAX(c.publicado_at) FROM crecer_contenido c WHERE c.marca_id=m.id AND c.estado='publicado'),
                     m.created_at)) AS dias
           FROM crecer_marca m
           JOIN crecer_suscripciones s ON s.marca_id=m.id
           WHERE s.estado IN ('activa','trial','prueba','incompleta')
           HAVING dias >= ?
           ORDER BY dias DESC
           LIMIT 20";
        $q = $pdo->prepare($sql);
        $q->execute([OPS_RETENCION_DIAS]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'marca_id' => (int)$r['id'],
                'nombre'   => (string)$r['nombre_negocio'],
                'dias'     => (int)$r['dias'],
                'sub'      => (string)$r['sub'],
            ];
        }
        return $out;
    } catch (Throwable $e) { error_log('ops_retencion_riesgo: '.$e->getMessage()); return []; }
}

/**
 * VIGILAR: corre los agentes de ops, loguea la evidencia, y devuelve un paquete
 * para el dashboard/email del fundador. Barato e idempotente.
 * Devuelve ['retencion'=>[...], 'resumen'=>['riesgo'=>N, ...]].
 */
function ops_vigilar(PDO $pdo): array {
    $riesgo = ops_retencion_riesgo($pdo);
    if ($riesgo) {
        $nombres = implode(', ', array_map(fn($r) => $r['nombre'] . " ({$r['dias']}d)", array_slice($riesgo, 0, 8)));
        _ops_log($pdo, 'ops_retencion', 'Detectó clientes en riesgo de churn',
                 count($riesgo) . " en riesgo (sin publicar " . OPS_RETENCION_DIAS . "+ días): " . $nombres);
    }
    return [
        'retencion' => $riesgo,
        'resumen'   => ['riesgo' => count($riesgo)],
    ];
}
