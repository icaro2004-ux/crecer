<?php
// ============================================================
//  CRECER — Demo EN VIVO del relevo del corillo (criterio #2 XPRIZE)
//  includes/relevo_demo.php
//
//  Pieza AISLADA: llave del worker + disparador fire-and-forget +
//  marcadores de inicio/fin en crecer_ia_log (agente='kernel', que ya
//  se excluye del timeline humano). Permite que evidencia.php dispare
//  el corillo en background y sondee los logs para MOSTRAR a los
//  agentes trabajando en tiempo real. No toca ninguna lógica existente.
// ============================================================

if (!defined('RELEVO_WORKER_KEY')) define('RELEVO_WORKER_KEY', (defined('CRECER_WORKER_KEY') && CRECER_WORKER_KEY !== '') ? CRECER_WORKER_KEY : 'crrelevo_7k2n');

/**
 * Marca en crecer_ia_log un hito del relevo (inicio/fin) como 'kernel'
 * → el frontend lo usa para saber cuándo terminó; el timeline humano lo ignora.
 */
function relevo_marcar(PDO $pdo, int $marca_id, string $accion, string $resp = ''): void {
    try {
        $pdo->prepare(
            "INSERT INTO crecer_ia_log
               (marca_id, agente, accion, modelo, prompt, respuesta,
                tokens_in, tokens_out, costo_usd, latencia_ms, estado, error_msg)
             VALUES (?, 'kernel', ?, '', '', ?, 0, 0, 0, 0, 'ok', NULL)"
        )->execute([$marca_id, $accion, $resp]);
    } catch (Throwable $e) { error_log('relevo_marcar: ' . $e->getMessage()); }
}

/**
 * Dispara el worker del relevo por auto-HTTP (fire-and-forget): responde YA y
 * el corillo corre por detrás (Aprendiz → Estratega → Creador → Analista),
 * logueando cada agente. El tablero lo ve aparecer sondeando crecer_ia_log.
 */
function relevo_disparar(int $marca_id): void {
    $host = $_SERVER['HTTP_HOST'] ?? 'encuentraloahora.com';
    $url  = 'https://' . $host . '/crecer/panel/relevo_worker.php?marca=' . $marca_id . '&key=' . RELEVO_WORKER_KEY;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER    => true,
        CURLOPT_CONNECTTIMEOUT_MS => 1500,
        CURLOPT_TIMEOUT_MS        => 3000,   // el worker flushea 'ok' al instante y sigue solo
        CURLOPT_NOSIGNAL          => 1,
        CURLOPT_SSL_VERIFYPEER    => false,
    ]);
    curl_exec($ch); curl_close($ch);
}
