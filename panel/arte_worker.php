<?php
// ============================================================
//  CRECER — Worker del ARTE async (panel/arte_worker.php)
//  Disparado por arte_disparar(). Responde 'ok' al instante
//  (fastcgi_finish_request) y sigue por detrás: sondea el job de
//  Responses hasta que la imagen esté y AVISA por notificación con
//  link al post. Así el dueño encola y sigue trabajando / se va.
//  Gated por llave fija (no público).
// ============================================================
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/worker_key.php';   // CR-F01b: falla cerrado sin llave
require_once __DIR__ . '/../includes/img_responses.php';
require_once __DIR__ . '/../includes/notif.php';

// ── ARNÉS DE PRUEBA ──────────────────────────────────────────
//  Estas constantes NO existen en producción: allí el worker entra por HTTP y
//  toma los valores de siempre (80 sondeos, 3 s). Existen para que la prueba
//  pueda ejercitar ESTE archivo —el worker de verdad, con su bucle y sus
//  salidas— en vez de una función auxiliar que se le parezca.
if (!defined('ARTE_WORKER_TEST')) define('ARTE_WORKER_TEST', false);
if (!defined('ARTE_POLL_MAX'))    define('ARTE_POLL_MAX', 80);   // ~4 min de ventana
if (!defined('ARTE_POLL_ESPERA')) define('ARTE_POLL_ESPERA', 3); // segundos entre sondeos

$mid = (int)($_GET['marca'] ?? 0);
$pid = (int)($_GET['id'] ?? 0);
$key = (string)($_GET['key'] ?? '');
if (!$mid || !$pid) { http_response_code(403); exit('no'); }

if (!ARTE_WORKER_TEST) {
    worker_autorizar($key, 'arte');
    // Responde YA al que disparó; el trabajo sigue por detrás.
    header('Content-Type: text/plain; charset=utf-8');
    echo "ok\n";
    if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
    @ignore_user_abort(true);
    @set_time_limit(0);
}

// Si aún no hay job de imagen → CREARLO AQUÍ (la llamada lenta a OpenAI corre en el
// worker, NUNCA en la pantalla del dueño → cero timeout / "error de conexión").
$ct_raw = (string)($_GET['ct'] ?? 'x');
$con_texto = ($ct_raw === 'x' ? null : ($ct_raw === '1'));
$extra = trim((string)($_GET['extra'] ?? ''));
$estilo = trim((string)($_GET['est'] ?? 'realista')) ?: 'realista';
$link = '/crecer/panel/propuestas.php?marca=' . $mid;
$row  = $pdo->query("SELECT img_job, caption FROM crecer_contenido WHERE id=" . $pid)->fetch(PDO::FETCH_ASSOC);
$copy = (string)($row['caption'] ?? '');

// avisa ok/respaldo/error por notificación
$notif_ok = function () use ($pdo, $mid, $link) {
    notif_crear($pdo, $mid, 'arte', 'Tu arte ya está listo', 'El corillo terminó la imagen de tu post — dale un vistazo.', $link, 'image');
};
// RESPALDO Gemini (Nano Banana Pro) cuando gpt no puede → avisa ok o error.
$respaldo_gemini = function () use ($pdo, $mid, $pid, $copy, $link, $notif_ok) {
    $u = img_gemini_fallback($pdo, $mid, $pid, $copy);
    if ($u !== '') { $notif_ok(); }
    else { notif_crear($pdo, $mid, 'arte', 'No se pudo crear el arte', 'Vuelve a tu post e intenta otra vez.', $link, 'bolt'); }
    exit;
};

// Re-disparo desde el sweep: ir DIRECTO a Gemini (gpt ya falló antes).
if (($_GET['fb'] ?? '') === '1') { error_log("arte_worker #{$pid}: re-disparo → Gemini directo"); $respaldo_gemini(); }

// 1) CREAR el job de gpt-image-2.
//    Aquí "no me devolvieron id" tampoco alcanza para llamar a otro proveedor:
//    si la petición se fue en timeout, OpenAI pudo haberla aceptado y el trabajo
//    existe con un id que nunca recibimos. Por eso encolar devuelve un veredicto
//    y no una cadena vacía que confunde el rechazo con la duda.
if ($row && trim((string)($row['img_job'] ?? '')) === '') {
    $enc = img_resp_encolar_res($pdo, $mid, $pid, $copy, $con_texto, $extra !== '' ? $extra : null, $estilo);

    // El proveedor contestó que no (401, 400, 429, sin credenciales…): no quedó
    // nada creado, nadie va a cobrar dos veces. El respaldo puede correr.
    if ($enc['res'] === 'rechazado_confirmado') {
        error_log("arte_worker #{$pid}: gpt rechazó el job ({$enc['clase']}) → Gemini");
        $respaldo_gemini();
    }

    // No sabemos si quedó trabajo creado. No se llama a nadie. img_resp_encolar_res
    // ya dejó la pieza en cola y marcada 'enc:' para que el barrido tampoco la
    // rescate: la única regeneración posible es la que pida el dueño.
    if ($enc['res'] === 'incierto') {
        error_log("arte_worker #{$pid}: encolado incierto ({$enc['clase']}); sin respaldo");
        notif_crear($pdo, $mid, 'arte', 'Tu arte va en camino',
            'Se está tardando un poco más de lo normal. Seguimos en eso y te avisamos apenas esté.',
            $link, 'image');
        exit;
    }
}

// 2) Sondea gpt-image-2. ok → avisa. error CONFIRMADO por el proveedor → Gemini.
//    Cualquier otra cosa (sigue en progreso, o no se pudo consultar) → espera.
//    img_resp_completar solo devuelve 'error' desde su rama de fallback, que es
//    la que exige un veredicto del proveedor: failed, cancelled o incomplete.
$estado = 'queued';
for ($i = 0; $i < ARTE_POLL_MAX; $i++) {
    // dedicado=true: este worker existe para ESTA pieza y el dueno esta mirando.
    // Sin el flag heredaria el backoff del barrido (1-2-4 min) y su bucle de 3s
    // se volveria inutil - el camino rapido moriria por culpa del arreglo.
    try { $r = img_resp_completar($pdo, $mid, $pid, true); $estado = (string)($r['estado'] ?? 'queued'); }
    catch (Throwable $e) { $estado = 'queued'; }
    if ($estado === 'ok') { $notif_ok(); exit; }
    if ($estado === 'error') { error_log("arte_worker #{$pid}: el proveedor descartó el job → Gemini"); $respaldo_gemini(); }
    if (ARTE_POLL_ESPERA > 0) sleep(ARTE_POLL_ESPERA);
}

// 3) SE AGOTÓ LA VENTANA DE SONDEO. Aquí antes se llamaba a Gemini, y era el
//    mismo error que ya se corrigió en el barrido: quedarse sin sondeos NO es
//    prueba de que OpenAI fallara. El job puede seguir vivo y completar solo.
//    Disparar el respaldo aquí generaba la misma pieza dos veces y la cobraba
//    dos veces.
//
//    Así que no se llama a nadie y no se toca la fila: img_job se conserva —sin
//    él no hay forma de reconciliar—, img_estado sigue en 'queued', y el
//    barrido la retoma en cuanto venza el lease (img_next_poll_at), que el
//    último sondeo dejó a segundos vista.
error_log("arte_worker #{$pid}: se agotó la ventana de sondeo; job conservado, sin respaldo");
notif_crear($pdo, $mid, 'arte', 'Tu arte va en camino',
    'Se está tardando un poco más de lo normal. Seguimos en eso y te avisamos apenas esté.',
    $link, 'image');
exit;
