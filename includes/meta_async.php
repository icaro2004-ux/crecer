<?php
// ============================================================
//  CRECER — Ejecutar la jugada, ASÍNCRONO  (includes/meta_async.php)
//
//  Producir las piezas de una jugada toma 1-3 min (escribir + arte + un
//  agente por pieza). Si eso corriera en el request del dueño, se comería
//  el timeout y él vería "se cayó la conexión" justo cuando el corillo SÍ
//  estaba trabajando.
//
//  Así que se ENCOLA y responde al instante con un job id; el worker
//  (panel/meta_worker.php) lo corre por detrás y el front sondea.
//  Estados: queued → working → done | failed.
//
//  Mismo patrón que includes/sala_async.php (ahí se aprendió la lección).
// ============================================================

require_once __DIR__ . '/worker_key.php';
if (!defined('META_WORKER_KEY')) define('META_WORKER_KEY', worker_key());

function _mjob_set(PDO $pdo, int $id, array $f): void {
    if (!$f) return;
    $set = []; $vals = [];
    foreach ($f as $k => $v) { $set[] = "{$k}=?"; $vals[] = $v; }
    $vals[] = $id;
    try { $pdo->prepare("UPDATE crecer_meta_jobs SET " . implode(',', $set) . " WHERE id=?")->execute($vals); } catch (Throwable $e) {}
}

// ── ENCOLADO UNICO (ATOMICO) ────────────────────────────────
/**
 * Encola la jugada UNA sola vez, aunque dos procesos lleguen a la vez.
 *
 * POR QUE HACE FALTA ESTO. El patron de antes era:
 *
 *     if (!meta_job_en_curso(...)) meta_job_encolar(...);
 *
 * y eso NO es un candado: es leer y despues insertar. Dos peticiones
 * concurrentes leen las dos que no hay job, las dos pasan, las dos insertan,
 * dos workers corren la misma jugada y el dueño paga dos veces la misma
 * tanda de piezas. `crecer_meta_jobs` no tiene indice unico que arbitre esa
 * carrera, asi que el arbitro hay que ponerlo aqui.
 *
 * COMO SE CIERRA SIN MIGRACION. Se bloquea la FILA DE LA JUGADA con
 * `FOR UPDATE`. La jugada es la que se esta reservando, asi que es el sitio
 * natural del candado: el segundo proceso se queda esperando en ese SELECT
 * hasta que el primero confirma, y cuando entra ya ve el job del otro.
 *
 * EL BLOQUEO DURA LO MINIMO: pertenencia, comprobacion e insercion. Nada de
 * proveedor, worker ni HTTP dentro. El disparo va DESPUES, y lo hace quien
 * llama —no este helper— para que la transaccion nunca abarque una llamada
 * de red.
 *
 * @return array{id:int, creado:bool, motivo:string}
 */
function meta_job_encolar_unico(PDO $pdo, int $marca_id, int $tactica_id, bool $forzar = false): array
{
    if ($marca_id <= 0 || $tactica_id <= 0) {
        return ['id' => 0, 'creado' => false, 'motivo' => 'parametros'];
    }

    $propia = false;
    try {
        if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $propia = true; }

        //  1 · PERTENENCIA + CANDADO, en la misma consulta. Una marca no puede
        //      encolar la jugada de otra: el WHERE lo impide y ademas es lo
        //      que se bloquea.
        $q = $pdo->prepare(
            "SELECT id, marca_id, estado, piezas_meta, sustituida_at
               FROM crecer_meta_tactica
              WHERE id=? AND marca_id=?
              FOR UPDATE");
        $q->execute([$tactica_id, $marca_id]);
        $t = $q->fetch(PDO::FETCH_ASSOC);

        if (!$t) {
            if ($propia) $pdo->rollBack();
            return ['id' => 0, 'creado' => false, 'motivo' => 'no_tuya'];
        }
        //  Una jugada sustituida no vuelve a producir: su sitio lo ocupa la
        //  alternativa, y generarle piezas seria gastar en trabajo muerto.
        if (!empty($t['sustituida_at']) || (string)$t['estado'] === 'descartada') {
            if ($propia) $pdo->rollBack();
            return ['id' => 0, 'creado' => false, 'motivo' => 'descartada'];
        }

        //  2 · ¿YA HAY UNO VIVO? Con el candado tomado, esta lectura si es de
        //      fiar: nadie puede insertar entre esta linea y el INSERT.
        $j = $pdo->prepare(
            "SELECT id FROM crecer_meta_jobs
              WHERE tactica_id=? AND estado IN ('queued','working')
              ORDER BY id DESC LIMIT 1");
        $j->execute([$tactica_id]);
        $ya = (int)($j->fetchColumn() ?: 0);
        if ($ya > 0) {
            if ($propia) $pdo->commit();
            return ['id' => $ya, 'creado' => false, 'motivo' => 'ya_en_curso'];
        }

        //  3 · ¿YA PRODUJO LO SUYO? Que el job anterior terminase (`done`) no
        //      autoriza otra tanda: si la jugada ya tiene sus piezas, repetir
        //      seria duplicarlas. `$forzar` existe para el caso legitimo de
        //      pedir mas, y aun asi respeta el tope de la jugada.
        if (!$forzar && function_exists('jugada_progreso')) {
            $p = jugada_progreso($pdo, $t);
            if ((int)$p['meta'] > 0 && (int)$p['creadas'] >= (int)$p['meta']) {
                if ($propia) $pdo->commit();
                return ['id' => 0, 'creado' => false, 'motivo' => 'ya_completa'];
            }
        }

        //  4 · NACE EL JOB.
        $pdo->prepare("INSERT INTO crecer_meta_jobs (marca_id, tactica_id, estado) VALUES (?,?, 'queued')")
            ->execute([$marca_id, $tactica_id]);
        $id = (int)$pdo->lastInsertId();
        if ($id <= 0) throw new RuntimeException('el job no nacio');

        if ($propia) $pdo->commit();
        return ['id' => $id, 'creado' => true, 'motivo' => 'creado'];

    } catch (Throwable $e) {
        //  Solo se deshace lo propio. Si la transaccion es del que llama, la
        //  excepcion sube y decide el: revertir aqui seria tirarle abajo
        //  escrituras que este helper no hizo.
        if ($propia && $pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('meta_job_encolar_unico: ' . $e->getMessage());
        if (!$propia) throw $e;
        return ['id' => 0, 'creado' => false, 'motivo' => 'fallo'];
    }
}

/**
 * Encola la ejecución de una jugada → devuelve el job id.
 *
 * INSEGURA POR SI SOLA: no arbitra carreras. No la uses como puerta de
 * entrada; usa `meta_job_encolar_unico()`. Se conserva porque es el INSERT
 * que aquel usa por dentro y porque el worker la referencia.
 */
function meta_job_encolar(PDO $pdo, int $marca_id, int $tactica_id): int {
    $pdo->prepare("INSERT INTO crecer_meta_jobs (marca_id, tactica_id, estado) VALUES (?,?, 'queued')")
        ->execute([$marca_id, $tactica_id]);
    return (int)$pdo->lastInsertId();
}

/** ¿Ya hay un trabajo corriendo para esta jugada? (evita doble producción) */
function meta_job_en_curso(PDO $pdo, int $tactica_id): ?int {
    try {
        $q = $pdo->prepare("SELECT id FROM crecer_meta_jobs WHERE tactica_id=? AND estado IN ('queued','working') ORDER BY id DESC LIMIT 1");
        $q->execute([$tactica_id]);
        $id = $q->fetchColumn();
        return $id ? (int)$id : null;
    } catch (Throwable $e) { return null; }
}

/** Dispara el worker por auto-HTTP, fire-and-forget. */
function meta_job_disparar(int $id): void {
    if (!worker_puede_disparar('meta')) return;

    // host VALIDADO (ver worker_host): la cabecera Host la controla quien llama.
    // Una sola implementación compartida por todos los disparadores.
    $host = worker_host();
    $url  = worker_esquema($host) . '://' . $host . '/crecer/panel/meta_worker.php?id=' . $id . '&key=' . META_WORKER_KEY;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER    => true,
        CURLOPT_CONNECTTIMEOUT_MS => 1500,
        CURLOPT_TIMEOUT_MS        => 3000,   // el worker flushea 'ok' y sigue solo
        CURLOPT_NOSIGNAL          => 1,
        CURLOPT_SSL_VERIFYPEER    => false,
    ]);
    curl_exec($ch); curl_close($ch);
}

/** EL WORKER — produce el contenido de la jugada y guarda el reporte. */
function meta_job_procesar(PDO $pdo, int $id): void {
    @set_time_limit(0);
    // CLAIM ATÓMICO. Antes esto era leer-y-luego-escribir: si dos workers
    // entraban a la vez (el disparo original y el rescate del polling, por
    // ejemplo), los dos pasaban el chequeo y producían el trabajo DOS VECES —
    // piezas duplicadas y, peor, imágenes pagadas duplicadas contra la cuota
    // de 40/mes del dueño. Con el UPDATE condicional solo gana uno.
    try {
        $q = $pdo->prepare("UPDATE crecer_meta_jobs SET estado='working', updated_at=NOW()
                             WHERE id=? AND estado='queued'");
        $q->execute([$id]);
        if ($q->rowCount() === 0) return;   // otro worker ya lo tomó (o ya terminó)
    } catch (Throwable $e) { return; }

    $j = $pdo->query("SELECT * FROM crecer_meta_jobs WHERE id=" . (int)$id)->fetch(PDO::FETCH_ASSOC);
    if (!$j) return;

    require_once __DIR__ . '/meta_ejecutar.php';
    try {
        $r = jugada_ejecutar($pdo, (int)$j['marca_id'], (int)$j['tactica_id']);
        if (!empty($r['ok'])) {
            _mjob_set($pdo, $id, [
                'estado'     => 'done',
                'resultado'  => (string)$r['resumen'],
                'creadas'    => (int)$r['creadas'],
                'recicladas' => (int)$r['recicladas'],
            ]);
            // Avisar al dueño: el trabajo puede haber tardado y él ya se fue.
            try {
                require_once __DIR__ . '/notif.php';
                if (function_exists('notif_crear')) {
                    notif_crear($pdo, (int)$j['marca_id'], 'contenido',
                        'El corillo te dejó trabajo listo', (string)$r['resumen'],
                        '/crecer/panel/propuestas.php?marca=' . (int)$j['marca_id']);
                }
            } catch (Throwable $e) {}
        } else {
            _mjob_set($pdo, $id, ['estado' => 'failed', 'error_msg' => substr((string)($r['err'] ?? 'falló'), 0, 380)]);
        }
    } catch (Throwable $e) {
        _mjob_set($pdo, $id, ['estado' => 'failed', 'error_msg' => substr($e->getMessage(), 0, 380)]);
    }
}

/**
 * Estado del job (polling del front). Verifica dueño por marca_id.
 *
 * RESCATE: si el trabajo lleva más de 25s parado en 'queued', el disparo se
 * perdió (curl cortado, worker caído, config a medias). En vez de dejar al
 * dueño mirando puntitos para siempre, se vuelve a disparar desde aquí.
 * Es idempotente: meta_job_procesar ignora lo que ya está working/done.
 */
function meta_job_estado(PDO $pdo, int $id, int $marca_id): ?array {
    try {
        $q = $pdo->prepare("SELECT estado, resultado, creadas, recicladas, error_msg,
                                   TIMESTAMPDIFF(SECOND, created_at, NOW()) AS edad,
                                   TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS quieto
                              FROM crecer_meta_jobs WHERE id=? AND marca_id=?");
        $q->execute([$id, $marca_id]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        // RESCATE, pero como mucho una vez cada 30s. Antes se re-disparaba en
        // CADA sondeo (o sea cada 3 segundos) contra el mismo job. El claim
        // atómico ya impide el trabajo duplicado, pero lanzar ocho peticiones
        // para nada es ruido que tampoco queremos.
        if ($r['estado'] === 'queued' && (int)$r['edad'] > 25 && (int)$r['quieto'] > 30) {
            try { $pdo->prepare("UPDATE crecer_meta_jobs SET updated_at=NOW() WHERE id=?")->execute([$id]); }
            catch (Throwable $e) {}
            meta_job_disparar($id);
        }
        unset($r['edad'], $r['quieto']);
        return $r;
    } catch (Throwable $e) { return null; }
}
