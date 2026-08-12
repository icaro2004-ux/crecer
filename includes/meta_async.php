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

/** Encola la ejecución de una jugada → devuelve el job id. */
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
    $host = $_SERVER['HTTP_HOST'] ?? 'encuentraloahora.com';
    // Producción es https siempre; en local (XAMPP) no hay TLS y el disparo se
    // perdía en silencio — el trabajo quedaba encolado para siempre y no se
    // podía probar el ciclo antes de subirlo. Solo localhost baja a http.
    $esquema = preg_match('/^(localhost|127\.0\.0\.1)(:|$)/i', $host) ? 'http' : 'https';
    $url  = $esquema . '://' . $host . '/crecer/panel/meta_worker.php?id=' . $id . '&key=' . META_WORKER_KEY;
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
    $j = $pdo->query("SELECT * FROM crecer_meta_jobs WHERE id=" . (int)$id)->fetch(PDO::FETCH_ASSOC);
    if (!$j || in_array($j['estado'], ['done','working'], true)) return;   // idempotente
    _mjob_set($pdo, $id, ['estado' => 'working']);

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
                                   TIMESTAMPDIFF(SECOND, created_at, NOW()) AS edad
                              FROM crecer_meta_jobs WHERE id=? AND marca_id=?");
        $q->execute([$id, $marca_id]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        if ($r['estado'] === 'queued' && (int)$r['edad'] > 25) meta_job_disparar($id);
        unset($r['edad']);
        return $r;
    } catch (Throwable $e) { return null; }
}
