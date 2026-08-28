<?php
// ============================================================
//  CRECER — La Sala ASÍNCRONA  (includes/sala_async.php)
//
//  El mensaje del dueño se ENCOLA y el request responde al instante con un
//  job id. Un worker (panel/sala_worker.php) corre la cadena de agentes por
//  detrás (aprender → gerente → producir/estratega) y guarda la respuesta.
//  El front hace polling. Estados: queued → working → done | failed.
//
//  Esto elimina el 504 / "se cayó la conexión": producir una campaña puede
//  tomar 1-3 min y ya no depende de mantener la conexión HTTP abierta.
// ============================================================

require_once __DIR__ . '/worker_key.php';
// CR-F01b: sin CRECER_WORKER_KEY no hay llave. NADA de literal de respaldo:
// adoptar en silencio una llave del repo publico era la trampa.
if (!defined('SALA_WORKER_KEY')) define('SALA_WORKER_KEY', worker_key());

function _sala_set(PDO $pdo, int $id, array $f): void {
    if (!$f) return;
    $set = []; $vals = [];
    foreach ($f as $k => $v) { $set[] = "{$k}=?"; $vals[] = $v; }
    $vals[] = $id;
    try { $pdo->prepare("UPDATE crecer_sala_jobs SET " . implode(',', $set) . " WHERE id=?")->execute($vals); } catch (Throwable $e) {}
}

/** Encola un mensaje de La Sala → devuelve el job id (estado 'queued'). */
function sala_encolar(PDO $pdo, int $marca_id, string $mensaje, array $historial, bool $puede_producir): int {
    $pdo->prepare("INSERT INTO crecer_sala_jobs (marca_id, mensaje, historial, puede_producir, estado)
                   VALUES (?,?,?,?, 'queued')")
        ->execute([$marca_id, $mensaje, json_encode($historial, JSON_UNESCAPED_UNICODE), $puede_producir ? 1 : 0]);
    return (int)$pdo->lastInsertId();
}

/** Dispara el worker por auto-HTTP, fire-and-forget (responde YA y sigue por detrás). */
function sala_disparar(int $id): void {
    // CR-F01b: sin llave no se dispara. El job se queda en cola y lo rescata el
    // sweep cuando el config vuelva — mejor eso que quemar el intento contra un 503.
    if (!worker_puede_disparar('sala')) return;
    // host VALIDADO: con HTTP_HOST a pelo, una cabecera Host forjada se llevaba
    // la llave de los workers a un servidor ajeno (ver worker_host()).
    $host = worker_host();
    $url  = worker_esquema($host) . '://' . $host . '/crecer/panel/sala_worker.php?id=' . $id . '&key=' . SALA_WORKER_KEY;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER    => true,
        CURLOPT_CONNECTTIMEOUT_MS => 1500,
        CURLOPT_TIMEOUT_MS        => 3000,   // el worker flushea 'ok' al instante; luego sigue solo
        CURLOPT_NOSIGNAL          => 1,
        CURLOPT_SSL_VERIFYPEER    => false,
    ]);
    curl_exec($ch); curl_close($ch);          // ignoramos el resultado a propósito
}

/** EL WORKER — corre la cadena de agentes y guarda el resultado. */
function sala_procesar(PDO $pdo, int $id): void {
    @set_time_limit(0);
    $j = $pdo->query("SELECT * FROM crecer_sala_jobs WHERE id=" . (int)$id)->fetch(PDO::FETCH_ASSOC);
    if (!$j || in_array($j['estado'], ['done', 'working'], true)) return;   // idempotente
    _sala_set($pdo, $id, ['estado' => 'working']);

    require_once __DIR__ . '/agentes.php';
    $mid       = (int)$j['marca_id'];
    $mensaje   = (string)$j['mensaje'];
    $historial = json_decode((string)($j['historial'] ?? '[]'), true);
    if (!is_array($historial)) $historial = [];
    $puede     = (int)$j['puede_producir'] === 1;

    try {
        $r = sala_responder($pdo, $mid, mb_substr($mensaje, 0, 1000), $historial, $puede);

        //  LA PROPUESTA SE SEPARA DE LA CONVERSACIÓN. El agente la manda al
        //  final de su respuesta, en la misma llamada; aquí se corta —el dueño
        //  nunca ve un JSON— y se guarda en ESTE turno, que es lo que después
        //  permite ejecutarla sin volver a preguntarle al modelo.
        require_once __DIR__ . '/sala_oportunidad.php';
        $sep = sala_op_extraer((string)($r['respuesta'] ?? ''));
        $op  = sala_op_normalizar($pdo, $mid, $sep['bruto']);
        sala_op_guardar($pdo, $id, $mid, $op);

        _sala_set($pdo, $id, [
            'estado'    => 'done',
            'respuesta' => $sep['texto'],
            'accion'    => (string)($r['accion'] ?? 'conversar'),
            'aprendido' => json_encode($r['aprendido'] ?? null, JSON_UNESCAPED_UNICODE),
        ]);
    } catch (Throwable $e) {
        _sala_set($pdo, $id, ['estado' => 'failed', 'error_msg' => substr($e->getMessage(), 0, 380)]);
    }
}

/** Estado de un job (para el polling del front). Verifica dueño por marca_id. */
//  LA OPORTUNIDAD VIAJA CON EL ESTADO: el front la necesita para pintar la
//  elección, y pedirla aparte sería otra petición para algo que ya está aquí.
function sala_job_estado(PDO $pdo, int $id, int $marca_id): ?array {
    //  La columna es de Fase 9: sin la migración se pide sin ella y La Sala
    //  conversa igual, solo que sin poder llevar la idea al trabajo.
    require_once __DIR__ . '/sala_oportunidad.php';
    $cols = 'estado, respuesta, accion, aprendido, error_msg'
          . (sala_op_hay_libro($pdo) ? ', oportunidad' : '');
    $q = $pdo->prepare("SELECT {$cols}
                          FROM crecer_sala_jobs WHERE id=? AND marca_id=?");
    $q->execute([$id, $marca_id]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}
