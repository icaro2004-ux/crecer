<?php
// ============================================================
//  CRECER — EL LECTOR DEL SNAPSHOT
//  core/Meta/MetaSnapshotReader.php
//
//  La mitad impura del par. Lee la base UNA vez y devuelve el retrato del
//  mundo que el compositor necesita. Aquí sí hay PDO; aquí no hay reglas de
//  decisión — si aparece un "si esto entonces aquel estado", está en el sitio
//  equivocado.
//
//  Reusa la lógica de dominio que ya existe (meta_activa, meta_progreso,
//  meta_plan_activo, meta_tacticas) en vez de duplicar la medición.
//
//  HONESTIDAD DEL MODELO ACTUAL — lo que este lector NO puede observar hoy y
//  por qué (se devuelve en 'no_observables' para que sea visible, no folclore
//  de comentarios):
//   · plan_generandose  → crecer_meta_jobs.tactica_id es NOT NULL: solo hay
//     trabajos DE JUGADA. La generación del plan corre sincrónica dentro de la
//     petición y no deja rastro consultable. Siempre false.
//   · presentado_at     → la columna no existe en crecer_meta_plan. Se omite
//     del snapshot: la regla C queda inerte hasta que exista.
//   · leccion_leida     → no hay dónde guardar "ya la vio". Se deriva por
//     ventana de días desde el cierre, que es una aproximación declarada.
// ============================================================

require_once __DIR__ . '/MetaState.php';

class MetaSnapshotReader
{
    /** Días de ventana para considerar "reciente" una lección (ver arriba). */
    public const APRENDIZAJE_DIAS = 14;

    public static function leer(PDO $pdo, int $marca_id, ?string $hoy = null): array
    {
        require_once dirname(__DIR__, 2) . '/includes/meta_negocio.php';

        $hoy = $hoy ?: date('Y-m-d H:i:s');
        $s = [
            'marca_id'        => $marca_id,
            'hoy'             => $hoy,
            'meta'            => null,
            'progreso'        => ['actual' => null, 'pct' => null, 'dias_rest' => null,
                                  'ritmo_dia' => null, 'al_dia' => null, 'vencida' => false],
            'plan'            => null,
            'jugadas'         => [],
            'piezas'          => [],
            'jobs'            => [],
            'plan_cerrado'    => null,
            'semana_actual'   => 1,
            'plan_generandose' => false,          // no observable · ver cabecera
            'no_observables'  => [
                'plan_generandose' => 'crecer_meta_jobs.tactica_id es NOT NULL: no existen jobs de plan',
                'presentado_at'    => 'la columna no existe en crecer_meta_plan',
                'leccion_leida'    => 'no hay persistencia de "leída"; se deriva por ventana de ' . self::APRENDIZAJE_DIAS . ' días',
            ],
        ];

        $meta = meta_activa($pdo, $marca_id);
        if (!$meta) return $s;                     // sin meta: el compositor decide A
        $s['meta'] = [
            'id'           => (int)$meta['id'],
            'objetivo'     => (string)$meta['objetivo'],
            'cantidad'     => $meta['cantidad'] !== null ? (float)$meta['cantidad'] : null,
            'fecha_inicio' => (string)$meta['fecha_inicio'],
            'fecha_limite' => $meta['fecha_limite'] !== null ? (string)$meta['fecha_limite'] : null,
            'estado'       => (string)$meta['estado'],
        ];

        try {
            $p = meta_progreso($pdo, $meta);
            $s['progreso'] = [
                'actual'    => $p['actual'] ?? null,
                'pct'       => $p['pct'] ?? null,
                'dias_rest' => $p['dias_rest'] ?? null,
                'ritmo_dia' => $p['ritmo_dia'] ?? null,
                'al_dia'    => $p['al_dia'] ?? null,
                'vencida'   => (bool)($p['vencida'] ?? false),
            ];
        } catch (Throwable $e) { error_log('MetaSnapshotReader progreso: ' . $e->getMessage()); }

        // Semana del plan: 1 + semanas completas desde que arrancó.
        $plan = meta_plan_activo($pdo, (int)$meta['id']);
        if ($plan) {
            $s['plan'] = [
                'id'        => (int)$plan['id'],
                'version'   => (int)$plan['version'],
                'inicio_at' => (string)$plan['inicio_at'],
                // presentado_at NO se incluye: la columna no existe todavía.
            ];
            $dias = (strtotime($hoy) - strtotime((string)$plan['inicio_at'])) / 86400;
            $s['semana_actual'] = max(1, (int)floor($dias / 7) + 1);
        }

        // Jugadas del plan VIGENTE (contrato §9: limitadas al plan activo).
        $s['jugadas'] = self::jugadas($pdo, (int)$meta['id'], $plan ? (int)$plan['id'] : null);

        // Piezas de esas jugadas, con si ya tienen métricas.
        $ids = array_map(fn($t) => (int)$t['id'], $s['jugadas']);
        $s['piezas'] = self::piezas($pdo, $marca_id, $ids, $plan ? (int)$plan['id'] : null);

        // Trabajos vivos o fallidos de esas jugadas.
        $s['jobs'] = self::jobs($pdo, $marca_id, $ids);

        // El último plan cerrado con lección, para el estado de aprendizaje.
        $s['plan_cerrado'] = self::planCerrado($pdo, (int)$meta['id'], $hoy);

        return $s;
    }

    private static function jugadas(PDO $pdo, int $meta_id, ?int $plan_id): array
    {
        $out = [];
        try {
            $sql = "SELECT id, orden, semana, clase, formato, piezas_meta, estado, inversion, titulo
                      FROM crecer_meta_tactica
                     WHERE meta_id = ?" . ($plan_id ? " AND plan_id = " . (int)$plan_id : '') . "
                     ORDER BY orden ASC, id ASC";
            $q = $pdo->prepare($sql);
            $q->execute([$meta_id]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $out[] = [
                    'id'          => (int)$t['id'],
                    'orden'       => (int)$t['orden'],
                    'semana'      => $t['semana'] !== null ? (int)$t['semana'] : 1,
                    'clase'       => (string)($t['clase'] ?: 'produccion'),
                    'formato'     => (string)($t['formato'] ?: 'post'),
                    'piezas_meta' => (int)$t['piezas_meta'],
                    'estado'      => (string)$t['estado'],
                    'inversion'   => $t['inversion'] !== null ? (float)$t['inversion'] : null,
                    'titulo'      => (string)$t['titulo'],
                ];
            }
        } catch (Throwable $e) { error_log('MetaSnapshotReader jugadas: ' . $e->getMessage()); }
        return $out;
    }

    private static function piezas(PDO $pdo, int $marca_id, array $tactica_ids, ?int $plan_id): array
    {
        if (!$tactica_ids && !$plan_id) return [];
        $out = [];
        try {
            $cond = [];
            if ($tactica_ids) $cond[] = "c.tactica_id IN (" . implode(',', array_map('intval', $tactica_ids)) . ")";
            if ($plan_id)     $cond[] = "c.plan_id = " . (int)$plan_id;
            $sql = "SELECT c.id, c.tactica_id, c.tipo, c.estado, c.necesita_material, c.guion,
                           c.fecha_programada, c.publicado_at,
                           (SELECT COUNT(*) FROM crecer_metricas m WHERE m.contenido_id = c.id
                              AND (m.alcance IS NOT NULL OR m.interacciones IS NOT NULL)) AS con_metricas
                      FROM crecer_contenido c
                     WHERE c.marca_id = ? AND (" . implode(' OR ', $cond) . ")
                     ORDER BY c.fecha_programada IS NULL, c.fecha_programada ASC, c.id ASC";
            $q = $pdo->prepare($sql);
            $q->execute([$marca_id]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $out[] = [
                    'id'                => (int)$p['id'],
                    'tactica_id'        => $p['tactica_id'] !== null ? (int)$p['tactica_id'] : 0,
                    'tipo'              => (string)$p['tipo'],
                    'estado'            => (string)$p['estado'],
                    'necesita_material' => $p['necesita_material'] !== null ? (string)$p['necesita_material'] : null,
                    'guion'             => $p['guion'] !== null ? (string)$p['guion'] : null,
                    'fecha_programada'  => $p['fecha_programada'] !== null ? (string)$p['fecha_programada'] : null,
                    'publicado_at'      => $p['publicado_at'] !== null ? (string)$p['publicado_at'] : null,
                    'tiene_metricas'    => ((int)$p['con_metricas']) > 0,
                ];
            }
        } catch (Throwable $e) { error_log('MetaSnapshotReader piezas: ' . $e->getMessage()); }
        return $out;
    }

    private static function jobs(PDO $pdo, int $marca_id, array $tactica_ids): array
    {
        if (!$tactica_ids) return [];
        $out = [];
        try {
            $q = $pdo->prepare("SELECT id, tactica_id, estado FROM crecer_meta_jobs
                                 WHERE marca_id = ? AND estado IN ('queued','working','failed')
                                   AND tactica_id IN (" . implode(',', array_map('intval', $tactica_ids)) . ")
                                 ORDER BY id DESC LIMIT 20");
            $q->execute([$marca_id]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $j) {
                $out[] = ['id' => (int)$j['id'], 'tactica_id' => (int)$j['tactica_id'],
                          'estado' => (string)$j['estado']];
            }
        } catch (Throwable $e) { /* sin la tabla: sin jobs, no es un error */ }
        return $out;
    }

    private static function planCerrado(PDO $pdo, int $meta_id, string $hoy): ?array
    {
        try {
            $q = $pdo->prepare("SELECT id, leccion, funciono, cierre_at
                                  FROM crecer_meta_plan
                                 WHERE meta_id = ? AND estado <> 'activo'
                                   AND leccion IS NOT NULL AND leccion <> ''
                                 ORDER BY cierre_at DESC, id DESC LIMIT 1");
            $q->execute([$meta_id]);
            $p = $q->fetch(PDO::FETCH_ASSOC);
            if (!$p) return null;
            $dias = $p['cierre_at']
                  ? (int)floor((strtotime($hoy) - strtotime((string)$p['cierre_at'])) / 86400)
                  : 999;
            return [
                'id'                => (int)$p['id'],
                'leccion'           => (string)$p['leccion'],
                'funciono'          => $p['funciono'] !== null ? (int)$p['funciono'] : null,
                'dias_desde_cierre' => $dias,
            ];
        } catch (Throwable $e) { return null; }
    }
}
