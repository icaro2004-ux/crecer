<?php
// ============================================================
//  CRECER — Working Moment (backend)  ·  includes/working_moment.php
//
//  La UI OBSERVA al motor; nunca lo modifica. Este módulo:
//   - start:   inicia (o RECUPERA) un run por (marca, ángulo). Guarda el
//              baseline = MAX(crecer_ia_log.id) para contar eventos reales.
//   - estado:  cuenta eventos reales (ia_log > baseline) de ESE run_uid,
//              o devuelve el resultado ya listo.
//   - generar: corre el post REAL (pipeline_post) idempotentemente por
//              run_uid; guarda el resultado. Fallback curado dentro del motor.
//
//  No emite eventos nuevos desde el motor. No toca el pipeline ni el Genome.
//  Todo vive detrás del feature flag (lo verifica quien lo invoca).
// ============================================================
require_once __DIR__ . '/genoma.php';

function wm_run_uid(): string { return bin2hex(random_bytes(16)); }

/**
 * Inicia o RECUPERA un run para (marca, ángulo). Idempotente: doble-clic /
 * refresh / re-pick reusan el mismo run_uid (no regeneran).
 * @return array{run_uid:string, estado:string, caption:?string}
 */
function wm_start(PDO $pdo, array $marca, int $usuario_id, string $angulo): array {
    $marca_id = (int)$marca['id'];
    $st = $pdo->prepare("SELECT run_uid, estado, resultado FROM crecer_wm_run
                         WHERE marca_id=? AND angulo_clave=? AND estado<>'error' AND updated_at > (NOW() - INTERVAL 15 MINUTE)
                         ORDER BY updated_at DESC LIMIT 1");
    $st->execute([$marca_id, $angulo]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        return ['run_uid' => $row['run_uid'], 'estado' => $row['estado'],
                'caption' => $row['estado'] === 'listo' ? (string)$row['resultado'] : null];
    }
    $run = wm_run_uid();
    $baseline = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM crecer_ia_log WHERE marca_id=" . $marca_id)->fetchColumn();
    $pdo->prepare("INSERT INTO crecer_wm_run (run_uid,marca_id,usuario_id,angulo_clave,baseline_ia_id,estado,created_at,updated_at)
                   VALUES (?,?,?,?,?, 'procesando', NOW(), NOW())")
        ->execute([$run, $marca_id, $usuario_id, $angulo, $baseline]);
    return ['run_uid' => $run, 'estado' => 'procesando', 'caption' => null];
}

/**
 * Estado de un run: cuenta eventos reales (ia_log > baseline) SOLO de este run_uid,
 * o entrega el resultado. Asociación inequívoca por run_uid (no por marca+timestamp).
 * @return array{estado:string, eventos:int, caption?:string}
 */
function wm_estado(PDO $pdo, int $marca_id, string $run_uid): array {
    $st = $pdo->prepare("SELECT marca_id, baseline_ia_id, estado, resultado FROM crecer_wm_run WHERE run_uid=?");
    $st->execute([$run_uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['marca_id'] !== $marca_id) return ['estado' => 'desconocido', 'eventos' => 0];
    if ($row['estado'] === 'error') return ['estado' => 'error', 'eventos' => 0];
    // Conteo REAL de eventos de ESTE run (frontera por baseline). Nunca se fuerza el total:
    // al terminar se revela la propuesta con las observaciones que alcanzaron a mostrarse.
    $n = (int)$pdo->query("SELECT COUNT(*) FROM crecer_ia_log WHERE marca_id=" . $marca_id . " AND id > " . (int)$row['baseline_ia_id'])->fetchColumn();
    if ($row['estado'] === 'listo') return ['estado' => 'listo', 'eventos' => $n, 'caption' => (string)$row['resultado']];
    return ['estado' => $row['estado'], 'eventos' => $n];
}

/**
 * Genera el post real del run (idempotente por run_uid). Reusa el DNA (0 llamadas);
 * los únicos eventos nuevos en ia_log son las llamadas del post (creador/director).
 * @return array{ok:bool, caption?:string, err?:string}
 */
function wm_generar(PDO $pdo, array $marca, string $run_uid): array {
    $marca_id = (int)$marca['id'];
    $st = $pdo->prepare("SELECT * FROM crecer_wm_run WHERE run_uid=?");
    $st->execute([$run_uid]);
    $run = $st->fetch(PDO::FETCH_ASSOC);
    if (!$run || (int)$run['marca_id'] !== $marca_id) return ['ok' => false, 'err' => 'run'];
    if ($run['estado'] === 'listo') return ['ok' => true, 'caption' => (string)$run['resultado']];   // idempotente

    // Acquire atómico: procesando → generando (un solo dueño; evita doble generación).
    $acq = $pdo->prepare("UPDATE crecer_wm_run SET estado='generando', updated_at=NOW() WHERE run_uid=? AND estado='procesando'");
    $acq->execute([$run_uid]);
    if ($acq->rowCount() !== 1) {
        // Otra petición ya está generando este run: espera breve por SU resultado (no regenera).
        for ($i = 0; $i < 48; $i++) {
            usleep(250000); // ~12s techo
            $r = $pdo->query("SELECT estado, resultado FROM crecer_wm_run WHERE run_uid=" . $pdo->quote($run_uid))->fetch(PDO::FETCH_ASSOC);
            if ($r && $r['estado'] === 'listo') return ['ok' => true, 'caption' => (string)$r['resultado']];
            if ($r && $r['estado'] === 'error') return ['ok' => false, 'err' => 'gen'];
        }
        return ['ok' => false, 'err' => 'timeout'];
    }
    // Dueño del run: genera.
    try {
        $prep = json_decode((string)($marca['pm_preparado'] ?? ''), true) ?: [];
        $direccion = null;
        foreach (($prep['direcciones'] ?? []) as $d) if (($d['id'] ?? '') === $run['angulo_clave']) { $direccion = $d; break; }
        if (!$direccion) throw new RuntimeException('sin direccion en pm_preparado');
        $genoma  = genoma_construir($pdo, $marca, $run_uid);           // reusa DNA (0 llamadas)
        $ed      = pipeline_post($pdo, $genoma, $direccion, $run_uid); // creador + director + fallback curado
        $caption = (string)($ed['contenido'] ?? '');
        $cid = (int)$pdo->query("SELECT id FROM crecer_contenido WHERE marca_id=" . $marca_id . " ORDER BY id DESC LIMIT 1")->fetchColumn();
        if ($cid && $caption !== '') $pdo->prepare("UPDATE crecer_contenido SET caption=?, updated_at=NOW() WHERE id=?")->execute([$caption, $cid]);
        $pdo->prepare("UPDATE crecer_wm_run SET estado='listo', resultado=?, updated_at=NOW() WHERE run_uid=?")->execute([$caption, $run_uid]);
        return ['ok' => true, 'caption' => $caption];
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE crecer_wm_run SET estado='error', updated_at=NOW() WHERE run_uid=?")->execute([$run_uid]);
        error_log('wm_generar: ' . $e->getMessage());
        return ['ok' => false, 'err' => 'gen'];
    }
}
