<?php
// ============================================================
//  CRECER — Worker de PUBLICACIÓN async (panel/publicar_worker.php)
//  Disparado por publicar_disparar(). Publica una pieza (que puede
//  tardar — ej. carrusel de N slides) en background y avisa por
//  notificación con el permalink. Gated por llave fija.
// ============================================================
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/worker_key.php';   // CR-F01b: falla cerrado sin llave
require __DIR__ . '/../includes/meta.php';
require __DIR__ . '/../includes/publicador.php';
require __DIR__ . '/../includes/notif.php';

$mid = (int)($_GET['marca'] ?? 0);
$cid = (int)($_GET['id'] ?? 0);
$key = (string)($_GET['key'] ?? '');
if (!$mid || !$cid) { http_response_code(403); exit('no'); }
worker_autorizar($key, 'publicar');

header('Content-Type: text/plain; charset=utf-8');
echo "ok\n";
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
@ignore_user_abort(true);
@set_time_limit(0);

$link = '/crecer/panel/resultados.php?marca=' . $mid;
$plats = array_values(array_intersect(['instagram', 'facebook'], array_filter(explode(',', (string)($_GET['pl'] ?? '')))));
try {
    $r = publicar_pieza($pdo, $cid, $plats);
    if (!empty($r['ok'])) {
        notif_crear($pdo, $mid, 'publicado', '¡Tu carrusel está publicado!',
            'El corillo lo soltó a tus redes. Míralo en Resultados.', $link, 'check-circle');
    } else {
        notif_crear($pdo, $mid, 'publicado', 'No se pudo publicar',
            ($r['motivo'] ?? '') ?: 'Revisa que tus redes estén conectadas e intenta otra vez.',
            '/crecer/panel/carrusel.php?marca=' . $mid . '&id=' . $cid, 'bolt');
    }
} catch (Throwable $e) {
    error_log('publicar_worker #' . $cid . ': ' . $e->getMessage());
    notif_crear($pdo, $mid, 'publicado', 'No se pudo publicar',
        'Hubo un problema al publicar. Intenta otra vez en un momento.',
        '/crecer/panel/carrusel.php?marca=' . $mid . '&id=' . $cid, 'bolt');
}
