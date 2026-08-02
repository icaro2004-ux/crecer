<?php
// ============================================================
//  CRECER — "Ya vi este recorrido"  ·  panel/tour_visto.php
//
//  Lo llama includes/_tour_view.php al terminar o al saltar. Marca la
//  pantalla como vista en crecer_tour_visto para que no se repita ni
//  cambiando de aparato. Si la tabla no existe (migración sin correr),
//  contesta ok igual: el navegador ya se acordó por su cuenta.
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tour.php';

header('Content-Type: application/json; charset=utf-8');
if (!esta_logueado() || $_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(403); echo '{"ok":false}'; exit; }

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;
if (empty($in['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', (string)$in['csrf'])) { http_response_code(403); echo '{"ok":false}'; exit; }

$usuario  = usuario_actual($pdo);
$marca_id = (int)($in['marca_id'] ?? 0);
$clave    = (string)($in['clave'] ?? '');
if (!in_array($clave, tour_claves(), true)) { echo '{"ok":false}'; exit; }

// La marca tiene que ser suya (o de un admin mirando).
$es_admin = (($usuario['rol'] ?? '') === 'admin');
$chk = $pdo->prepare("SELECT 1 FROM crecer_marca WHERE id=?" . ($es_admin ? '' : ' AND usuario_id=?'));
$chk->execute($es_admin ? [$marca_id] : [$marca_id, (int)$usuario['id']]);
if (!$chk->fetchColumn()) { http_response_code(403); echo '{"ok":false}'; exit; }

try {
    $pdo->prepare("INSERT IGNORE INTO crecer_tour_visto (marca_id, clave) VALUES (?,?)")
        ->execute([$marca_id, $clave]);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    // Sin la tabla todavía: no es culpa del dueño y el tour ya no le sale igual.
    echo json_encode(['ok' => true, 'nota' => 'sin tabla']);
}
