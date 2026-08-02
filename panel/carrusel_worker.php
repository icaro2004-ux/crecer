<?php
// ============================================================
//  CRECER — Worker del CARRUSEL async (panel/carrusel_worker.php)
//  Disparado por carrusel_disparar(). Responde 'ok' al instante
//  (fastcgi_finish_request) y genera el ARTE de cada slide por
//  detrás; al terminar AVISA por notificación con link al carrusel.
//  Así el dueño pide el carrusel y sigue trabajando / se va.
//  Gated por llave fija (no público).
// ============================================================
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/worker_key.php';   // CR-F01b: falla cerrado sin llave
require __DIR__ . '/../includes/carrusel.php';

$mid = (int)($_GET['marca'] ?? 0);
$cid = (int)($_GET['id'] ?? 0);
$key = (string)($_GET['key'] ?? '');
if (!$mid || !$cid) { http_response_code(403); exit('no'); }
worker_autorizar($key, 'carrusel');

header('Content-Type: text/plain; charset=utf-8');
echo "ok\n";
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
@ignore_user_abort(true);
@set_time_limit(0);

$link = '/crecer/panel/carrusel.php?marca=' . $mid . '&id=' . $cid;

// Genera el arte de cada slide que NO tenga imagen (los que el cliente subió se saltan).
$ok = 0;
foreach (carrusel_slides($pdo, $cid) as $s) {
    if (trim((string)$s['grafica_path']) !== '') { $ok++; continue; }
    if (carrusel_arte_slide($pdo, $mid, (int)$s['id'])) $ok++;
}

$est = carrusel_estado($pdo, $cid);
if ($est['completo']) {
    notif_crear($pdo, $mid, 'carrusel', 'Tu carrusel ya está listo',
        'El corillo terminó el arte de tu carrusel — dale un vistazo y publícalo.', $link, 'image');
} elseif ($ok > 0) {
    notif_crear($pdo, $mid, 'carrusel', 'Tu carrusel casi listo',
        "Quedaron {$ok} de {$est['total']} slides con arte. Revisa los que faltan.", $link, 'image');
} else {
    notif_crear($pdo, $mid, 'carrusel', 'No se pudo crear el arte del carrusel',
        'Vuelve a tu carrusel e intenta otra vez, o sube tus propias imágenes.', $link, 'bolt');
}
