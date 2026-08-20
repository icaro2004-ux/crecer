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
//   · presentado_at     → solo viaja si la columna existe (Fase 3B). Se omite
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
            'observacion'     => null,          // plan cerrado que aún se mide (estado K)
            'plan_cerrado'    => null,
            'semana_actual'   => 1,
            'plan_generandose' => false,          // no observable · ver cabecera
            'no_observables'  => [
                'plan_generandose' => 'crecer_meta_jobs.tactica_id es NOT NULL: no existen jobs de plan',
                'presentado_at'    => 'solo si crecer_meta_plan la tiene (migracion 3B)',
                'leccion_leida'    => 'no hay persistencia de "leída"; se deriva por ventana de ' . self::APRENDIZAJE_DIAS . ' días',
                'meta_cerrada'     => 'se muestra el cierre solo si ocurrió en los últimos ' . self::CIERRE_RECIENTE_DIAS . ' días',
            ],
        ];

        // ── LA META: activa, o la última cerrada si es reciente ────────────
        //  meta_activa() solo devuelve las 'activa'. Con eso, una meta lograda
        //  o vencida daba null y el compositor decía "no tienes meta" (A) en vez
        //  de "esta meta se cerró" (M): se perdía el cierre y el aprendizaje.
        //  Aquí se distinguen los tres mundos de verdad.
        $meta = meta_activa($pdo, $marca_id);
        if (!$meta) $meta = self::metaCerradaReciente($pdo, $marca_id, $hoy);
        if (!$meta) return $s;                     // nunca hubo meta (o ya es vieja): A
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
                'id'          => (int)$plan['id'],
                'version'     => (int)$plan['version'],
                'inicio_at'   => (string)$plan['inicio_at'],
                //  El texto de la Estratega. El compositor le saca UNA frase
                //  para la presentación del plan; no se pinta entero aquí.
                'diagnostico' => (string)($plan['diagnostico'] ?? ''),
            ];
            //  presentado_at SOLO si la columna existe. La regla C del compositor
            //  pregunta con array_key_exists: si la clave no viene, se queda
            //  inerte. Así el código nuevo convive con el esquema viejo sin
            //  enterarse, y el día que corre la migración se enciende sola.
            if (array_key_exists('presentado_at', $plan)) {
                $s['plan']['presentado_at'] = $plan['presentado_at'] !== null
                    ? (string)$plan['presentado_at'] : null;
            }
            $dias = (strtotime($hoy) - strtotime((string)$plan['inicio_at'])) / 86400;
            $s['semana_actual'] = max(1, (int)floor($dias / 7) + 1);
        }

        // ── LA VENTANA DE OBSERVACIÓN ──────────────────────────────────────
        //  Todo lo que puede EXIGIR algo hoy (jugadas, piezas, jobs) sale del
        //  PLAN ACTIVO y de ningún otro. Sin plan activo, esas colecciones van
        //  vacías a propósito: antes se caía a "todas las del meta_id" y un
        //  borrador o un fallo de un plan reemplazado hace semanas podía
        //  secuestrar la pantalla de hoy.
        if ($plan) {
            $s['jugadas'] = self::jugadas($pdo, $marca_id, (int)$plan['id']);
            $ids = array_map(fn($t) => (int)$t['id'], $s['jugadas']);
            $s['piezas'] = self::piezas($pdo, $marca_id, (int)$plan['id']);
            $s['jobs']   = self::jobs($pdo, $marca_id, $ids);
        }

        // El plan CERRADO que todavía está midiéndose. Solo sus piezas alimentan
        // el estado K: las del plan activo ya se miraron arriba.
        $s['observacion'] = self::observacion($pdo, $marca_id, (int)$meta['id']);

        // El último plan cerrado con lección, para el estado de aprendizaje.
        $s['plan_cerrado'] = self::planCerrado($pdo, $marca_id, (int)$meta['id'], $hoy);

        return $s;
    }

    /**
     * Jugadas DE UN PLAN. El plan_id es obligatorio a propósito: no existe el
     * "tráemelas todas". Esa omisión era la que mezclaba los cuatro planes
     * históricos de una meta en la pantalla de hoy.
     */
    private static function jugadas(PDO $pdo, int $marca_id, int $plan_id): array
    {
        $out = [];
        if ($plan_id <= 0) return $out;
        try {
            $sql = "SELECT id, orden, semana, clase, formato, piezas_meta, estado, inversion, titulo, que_hacer
                      FROM crecer_meta_tactica
                     WHERE marca_id = ? AND plan_id = ?
                     ORDER BY orden ASC, id ASC";
            $q = $pdo->prepare($sql);
            $q->execute([$marca_id, $plan_id]);
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
                    'que_hacer'   => (string)($t['que_hacer'] ?? ''),
                ];
            }
        } catch (Throwable $e) { error_log('MetaSnapshotReader jugadas: ' . $e->getMessage()); }
        return $out;
    }

    /**
     * Piezas DE UN PLAN, siempre de esa marca. Igual que arriba: sin plan no
     * hay piezas. Se filtra por plan_id (no por tactica_id) para que también
     * entren las piezas del plan que aún no cuelgan de una jugada concreta.
     */
    private static function piezas(PDO $pdo, int $marca_id, int $plan_id): array
    {
        $out = [];
        if ($plan_id <= 0) return $out;
        try {
            $sql = "SELECT c.id, c.tactica_id, c.tipo, c.estado, c.necesita_material, c.guion,
                           c.fecha_programada, c.publicado_at,
                           (SELECT COUNT(*) FROM crecer_metricas m WHERE m.contenido_id = c.id
                              AND (m.alcance IS NOT NULL OR m.interacciones IS NOT NULL)) AS con_metricas
                      FROM crecer_contenido c
                     WHERE c.marca_id = ? AND c.plan_id = ?
                     ORDER BY c.fecha_programada IS NULL, c.fecha_programada ASC, c.id ASC";
            $q = $pdo->prepare($sql);
            $q->execute([$marca_id, $plan_id]);
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

    /**
     * SOLO EL JOB VIGENTE DE CADA JUGADA.
     *
     * Antes esto traía todos los jobs no terminados y un `failed` viejo ganaba
     * aunque después hubiera corrido un `done`: la pantalla enseñaba un error
     * ya resuelto. Ahora se toma el ÚLTIMO job de cada jugada, sea cual sea su
     * estado, y solo se devuelve si ese último sigue vivo o falló. Un `done`
     * posterior tapa cualquier fallo anterior, que es lo que ocurrió de verdad.
     */
    private static function jobs(PDO $pdo, int $marca_id, array $tactica_ids): array
    {
        if (!$tactica_ids) return [];
        $out = [];
        try {
            $in = implode(',', array_map('intval', $tactica_ids));
            $q = $pdo->prepare(
                "SELECT j.id, j.tactica_id, j.estado
                   FROM crecer_meta_jobs j
                  WHERE j.marca_id = ? AND j.tactica_id IN ({$in})
                    AND j.id = (SELECT MAX(j2.id) FROM crecer_meta_jobs j2
                                 WHERE j2.tactica_id = j.tactica_id AND j2.marca_id = j.marca_id)
                  ORDER BY j.id DESC");
            $q->execute([$marca_id]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $j) {
                $estado = (string)$j['estado'];
                if (!in_array($estado, ['queued', 'working', 'failed'], true)) continue;  // 'done': nada que decir
                $out[] = ['id' => (int)$j['id'], 'tactica_id' => (int)$j['tactica_id'],
                          'estado' => $estado];
            }
        } catch (Throwable $e) { /* sin la tabla: sin jobs, no es un error */ }
        return $out;
    }

    /**
     * LA ÚLTIMA META CERRADA, si el cierre es reciente.
     *
     * Una meta lograda o vencida hace tres días es noticia: hay que enseñar el
     * cierre y lo que dejó. La misma meta seis meses después ya no: ahí lo
     * honesto es invitar a poner una nueva (estado A). La ventana marca esa
     * frontera, y se declara en 'no_observables' para que se vea que es una
     * decisión y no un accidente.
     */
    public const CIERRE_RECIENTE_DIAS = 30;

    private static function metaCerradaReciente(PDO $pdo, int $marca_id, string $hoy): ?array
    {
        try {
            $q = $pdo->prepare("SELECT * FROM crecer_meta
                                 WHERE marca_id = ? AND estado IN ('lograda','vencida','cancelada')
                                 ORDER BY updated_at DESC, id DESC LIMIT 1");
            $q->execute([$marca_id]);
            $m = $q->fetch(PDO::FETCH_ASSOC);
            if (!$m) return null;
            $dias = (int)floor((strtotime($hoy) - strtotime((string)$m['updated_at'])) / 86400);
            return $dias <= self::CIERRE_RECIENTE_DIAS ? $m : null;
        } catch (Throwable $e) { return null; }
    }

    /**
     * EL PLAN EN OBSERVACIÓN: ya cerró, pero sus piezas todavía se están
     * midiendo. Es lo único que alimenta el estado K. Se devuelve con SUS
     * piezas para que el compositor no tenga que ir a buscarlas —y para que no
     * pueda confundirlas con las del plan activo.
     */
    private static function observacion(PDO $pdo, int $marca_id, int $meta_id): ?array
    {
        try {
            // La consulta ELIGE el plan correcto; no elige uno y luego se
            // arrepiente. Antes se tomaba el último cerrado y, si resultaba que
            // no había publicado nada, se devolvía null — y el plan anterior,
            // que sí tenía piezas midiéndose, quedaba invisible para siempre.
            //
            // Tres condiciones, todas dentro del SQL:
            //  · estado cerrado de verdad: completado o reemplazado (abandonado
            //    no se mide: se abandonó);
            //  · SIN lección: un plan ya evaluado no está en observación. Sin
            //    esto, un plan juzgado sin métricas producía K eternamente y L
            //    no llegaba nunca;
            //  · que TENGA al menos una pieza publicada, o no hay nada que medir;
            //  · y que NO exista un plan cerrado MÁS RECIENTE ya evaluado. Sin
            //    esta última, un plan viejo que nunca se midió resucitaba K por
            //    encima de la lección de uno posterior: el ciclo ya siguió, y
            //    volver atrás bloquearía L igual que el caso que arregla la
            //    condición de la lección. Lo viejo sin medir es historia, no
            //    algo que esté pasando.
            $q = $pdo->prepare(
                "SELECT p.id, p.version, p.inicio_at, p.cierre_at, p.estado
                   FROM crecer_meta_plan p
                  WHERE p.marca_id = ? AND p.meta_id = ?
                    AND p.estado IN ('completado','reemplazado')
                    AND (p.leccion IS NULL OR p.leccion = '')
                    AND EXISTS (SELECT 1 FROM crecer_contenido c
                                 WHERE c.plan_id = p.id
                                   AND c.marca_id = p.marca_id
                                   AND c.estado = 'publicado')
                    AND NOT EXISTS (SELECT 1 FROM crecer_meta_plan q
                                     WHERE q.meta_id = p.meta_id
                                       AND q.marca_id = p.marca_id
                                       AND q.leccion IS NOT NULL AND q.leccion <> ''
                                       AND (q.cierre_at > p.cierre_at
                                            OR (q.cierre_at = p.cierre_at AND q.id > p.id)))
                  ORDER BY p.cierre_at DESC, p.id DESC
                  LIMIT 1");
            $q->execute([$marca_id, $meta_id]);
            $p = $q->fetch(PDO::FETCH_ASSOC);
            if (!$p) return null;

            return [
                'plan' => ['id' => (int)$p['id'], 'version' => (int)$p['version'],
                           'estado' => (string)$p['estado'],
                           'cierre_at' => (string)($p['cierre_at'] ?? '')],
                'piezas' => self::piezas($pdo, $marca_id, (int)$p['id']),
            ];
        } catch (Throwable $e) { return null; }
    }

    private static function planCerrado(PDO $pdo, int $marca_id, int $meta_id, string $hoy): ?array
    {
        try {
            $q = $pdo->prepare("SELECT id, leccion, funciono, cierre_at
                                  FROM crecer_meta_plan
                                 WHERE marca_id = ? AND meta_id = ? AND estado <> 'activo'
                                   AND leccion IS NOT NULL AND leccion <> ''
                                 ORDER BY cierre_at DESC, id DESC LIMIT 1");
            $q->execute([$marca_id, $meta_id]);
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
