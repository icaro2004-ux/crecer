<?php
// ============================================================
//  CRECER - Worker del PRIMER POST  ·  panel/muestra_worker.php
//
//  Disparado por muestra_arrancar(). Contesta 'ok' al instante
//  (fastcgi_finish_request) y sigue por detras: escribe el copy y encola el
//  arte. El sondeo de la imagen NO se hace aqui - de eso ya se encarga
//  arte_worker.php, al que muestra_preparar() despierta con arte_disparar().
//
//  POR QUE EXISTE. Antes esto corria dentro del request del navegador: el
//  dueño miraba un spinner ~14 s (cinco llamadas de texto en fila) y solo
//  DESPUES llegaba a la pantalla donde empezaba a esperar la imagen. Ahora
//  entra a la pantalla de preparacion de inmediato y ve el trabajo ocurrir.
//
//  EL LOCK SE SUELTA SIEMPRE. Un worker que muere sin soltarlo deja a la
//  pantalla esperando 180 s a un muerto; por eso el done/fail va en un finally
//  y no al final del camino feliz.
//  Gated por llave fija (no publico).
// ============================================================
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/worker_key.php';   // CR-F01b: falla cerrado sin llave
require_once __DIR__ . '/../includes/agentes.php';
require_once __DIR__ . '/../includes/muestra.php';

$mid = (int)($_GET['marca'] ?? 0);
$pid = (int)($_GET['id'] ?? 0);
$tk  = (string)($_GET['tk'] ?? '');
$key = (string)($_GET['key'] ?? '');
if (!$mid || !$pid) { http_response_code(403); exit('no'); }

worker_autorizar($key, 'muestra');

// Responde YA al que disparó; el trabajo sigue por detrás.
header('Content-Type: text/plain; charset=utf-8');
echo "ok\n";
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
@ignore_user_abort(true);
@set_time_limit(0);

//  EL DUEÑO SALE DE LA MARCA, NO DE LA URL. Quien llama controla la query, y el
//  lock se suelta por (usuario_id, token): soltarlo con un usuario de la URL
//  seria dejar que un tercero cierre el lock de otro.
$uid = (int)$pdo->query("SELECT usuario_id FROM crecer_marca WHERE id={$mid}")->fetchColumn();
if (!$uid) exit;

try {
    muestra_preparar($pdo, $mid, $pid);
    onboarding_lock_done($pdo, $uid, $mid, $tk);
} catch (Throwable $e) {
    error_log('muestra_worker: ' . $e->getMessage());
    //  'failed' deja el lock RE-ADQUIRIBLE al instante: la pantalla puede
    //  levantar otro preparador en el proximo sondeo en vez de esperar el rancio.
    onboarding_lock_fail($pdo, $uid, $tk);
}
