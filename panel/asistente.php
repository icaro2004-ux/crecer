<?php
// ============================================================
//  CRECER — Endpoint del ASISTENTE (helper del web app)
//  panel/asistente.php
//
//  Recibe la pregunta del dueño (JSON, AJAX), llama al agente
//  asistente_responder() y devuelve la respuesta. Protegido por
//  login + CSRF + verificación de que la marca es del usuario.
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/agentes.php';

header('Content-Type: application/json; charset=utf-8');

if (!esta_logueado()) { http_response_code(401); echo json_encode(['ok'=>false,'err'=>'Sesión expirada.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'err'=>'Método no permitido.']); exit; }

$usuario = usuario_actual($pdo);
$USUARIO_ID = (int)$usuario['id'];

// Body JSON o form.
$raw = file_get_contents('php://input');
$in  = json_decode($raw, true);
if (!is_array($in)) $in = $_POST;

// CSRF.
$csrf = $in['csrf'] ?? '';
if (empty($csrf) || !hash_equals($_SESSION['csrf'] ?? '', (string)$csrf)) {
    http_response_code(403); echo json_encode(['ok'=>false,'err'=>'Token inválido. Recarga la página.']); exit;
}

$marca_id = (int)($in['marca_id'] ?? 0);
$pregunta = trim((string)($in['pregunta'] ?? ''));
$historial = is_array($in['historial'] ?? null) ? $in['historial'] : [];

if ($pregunta === '') { echo json_encode(['ok'=>false,'err'=>'Escribe tu pregunta.']); exit; }
if (mb_strlen($pregunta) > 1000) $pregunta = mb_substr($pregunta, 0, 1000);

// La marca tiene que ser del usuario logueado.
$chk = $pdo->prepare("SELECT id FROM crecer_marca WHERE id=? AND usuario_id=?");
$chk->execute([$marca_id, $USUARIO_ID]);
if (!$chk->fetchColumn()) { http_response_code(403); echo json_encode(['ok'=>false,'err'=>'Negocio no válido.']); exit; }

try {
    $r = asistente_responder($pdo, $marca_id, $pregunta, $historial);
    echo json_encode(['ok'=>true, 'respuesta'=>$r['respuesta']], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('asistente: ' . $e->getMessage());
    echo json_encode(['ok'=>false, 'err'=>'No pude responder ahora mismo. Intenta de nuevo.']);
}
