<?php
// ============================================================
//  CRECER — EL LATIDO DE LOS CRON
//  includes/cron_latido.php
//
//  EL HUECO QUE CIERRA. Los cron corren en Hostinger y nadie los mira. Si uno
//  deja de sonar —una ruta que cambia tras un despliegue, un PHP que se
//  actualiza, una cuota que se agota— el producto no se cae: se queda quieto.
//  Las publicaciones no salen, las semanas no se preparan, y el dueño se
//  entera cuando le pregunta a un cliente por qué no vio su post.
//
//  QUÉ SE GUARDA Y DÓNDE. Nada nuevo: una fila por corrida en
//  `crecer_pipeline_run`, que ya existe y ya tiene las columnas que hacen
//  falta —etapa, ok, ms, resultado, motivo—. No hay tabla nueva ni migración:
//  esta fase no la necesita.
//
//  LO QUE NO ES. No es observabilidad general ni un panel de métricas. Es la
//  respuesta a tres preguntas: ¿corrió?, ¿cuándo fue la última vez que salió
//  bien?, ¿cuánto tardó? Con eso se sabe si producción está viva.
// ============================================================

/**
 * Deja constancia de una corrida. Best-effort: si no se puede escribir, el
 * cron sigue — un latido que tumbara la corrida sería peor que no tenerlo.
 *
 * @param string $etapa   nombre corto y estable del cron ('publicar', 'corillo'…)
 * @param bool   $ok      ¿terminó su trabajo?
 * @param int    $ms      cuánto tardó
 * @param int    $piezas  cuántas cosas procesó (0 es una respuesta válida)
 * @param string $motivo  una línea si algo fue mal. NUNCA tokens ni datos del cliente.
 */
function cron_latido(PDO $pdo, string $etapa, bool $ok, int $ms, int $piezas = 0,
                     string $motivo = ''): void
{
    try {
        $pdo->prepare(
            "INSERT INTO crecer_pipeline_run
                 (run_uid, marca_id, etapa, ok, ms, llamadas, resultado, motivo, created_at)
               VALUES (?, 0, ?, ?, ?, ?, ?, ?, NOW())")
            //  `created_at` explicito: la columna no tiene valor por defecto y
            //  se quedaba en cero — un latido sin hora no dice si el cron corrio
            //  hace un minuto o hace tres dias, que es justo lo unico que se le
            //  pregunta.
            //  `marca_id` = 0 a proposito: la corrida es del sistema, no de un
            //  negocio. La columna no admite NULL y no se toca su esquema por
            //  esto — un cero no se confunde con ninguna marca real.
            ->execute([
                bin2hex(random_bytes(16)),
                mb_substr('cron_' . $etapa, 0, 24),
                $ok ? 1 : 0,
                max(0, $ms),
                max(0, min(65535, $piezas)),
                mb_substr($ok ? 'ok' : 'fallo', 0, 24),
                mb_substr($motivo, 0, 255),
            ]);
    } catch (Throwable $e) {
        error_log('cron_latido(' . $etapa . '): ' . $e->getMessage());
    }
}

/**
 * ¿CÓMO VA ESE CRON? Lo que hace falta para saber si producción está viva.
 *
 * `atrasado` es la pregunta que de verdad importa, y por eso se responde aquí
 * y no en cada pantalla: si el último latido bueno es más viejo que el doble
 * de su frecuencia, ese cron dejó de sonar. El doble, no la frecuencia justa:
 * una corrida que se salta por carga no es una avería.
 *
 * @return array{hubo:bool, ultima:?string, ultima_ok:?string, ms:int,
 *               piezas:int, motivo:string, atrasado:bool}
 */
function cron_estado(PDO $pdo, string $etapa, int $cada_min = 10): array
{
    $out = ['hubo' => false, 'ultima' => null, 'ultima_ok' => null,
            'ms' => 0, 'piezas' => 0, 'motivo' => '', 'atrasado' => false];
    try {
        $q = $pdo->prepare("SELECT ok, ms, llamadas, motivo, created_at
                              FROM crecer_pipeline_run
                             WHERE etapa=? ORDER BY id DESC LIMIT 1");
        $q->execute(['cron_' . $etapa]);
        $f = $q->fetch(PDO::FETCH_ASSOC);
        if ($f) {
            $out['hubo']   = true;
            $out['ultima'] = (string)$f['created_at'];
            $out['ms']     = (int)$f['ms'];
            $out['piezas'] = (int)$f['llamadas'];
            $out['motivo'] = (string)($f['motivo'] ?? '');
        }
        $q = $pdo->prepare("SELECT created_at FROM crecer_pipeline_run
                             WHERE etapa=? AND ok=1 ORDER BY id DESC LIMIT 1");
        $q->execute(['cron_' . $etapa]);
        $ok = $q->fetchColumn();
        if ($ok) $out['ultima_ok'] = (string)$ok;
    } catch (Throwable $e) { return $out; }

    $ref = $out['ultima_ok'] ? strtotime($out['ultima_ok']) : 0;
    $out['atrasado'] = ($ref === 0) || ((time() - $ref) > (max(1, $cada_min) * 60 * 2));
    return $out;
}
