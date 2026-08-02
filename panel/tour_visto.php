<?php
// ============================================================
//  CRECER — "Ya vi el recibimiento"  ·  panel/tour_visto.php
//
//  Lo llama includes/tour_home.php al terminar o al saltar. Marca la fecha
//  en crecer_marca.tour_home_at para que el tour NO se repita ni cambiando
//  de aparato. Si la columna no existe (migración sin correr), contesta ok
//  igual: el navegador ya se acordó por su cuenta.
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
if (!esta_logueado() || $_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(403); echo '{"ok":false}'; exit; }

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;
if (empty($in['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', (string)$in['csrf'])) { http_response_code(403); echo '{"ok":false}'; exit; }

$usuario  = usuario_actual($pdo);
$marca_id = (int)($in['marca_id'] ?? 0);

try {
    $st = $pdo->prepare("UPDATE crecer_marca SET tour_home_at=NOW()
                         WHERE id=? AND usuario_id=? AND tour_home_at IS NULL");
    $st->execute([$marca_id, (int)$usuario['id']]);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    // Sin la columna todavía: no es un error del dueño, el tour ya no le sale igual.
    echo json_encode(['ok' => true, 'nota' => 'sin columna']);
}
