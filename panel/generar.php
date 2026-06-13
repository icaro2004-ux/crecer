<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Generar el primer mes (el "wow")
//  panel/generar.php
//
//  Corre el PLANIFICADOR + CREADOR para una marca y redirige al
//  panel ya lleno. Puede tardar (rate limit free tier) → sin límite.
// ============================================================

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/agentes.php';

requiere_login();
$usuario = usuario_actual($pdo);

@set_time_limit(0); // la generación con backoff puede pasar de 30s

$marca_id = (int)($_POST['marca'] ?? $_GET['marca'] ?? 0);
$n_piezas = (int)($_POST['n'] ?? 6);
// Verificar que el negocio sea del usuario logueado
$own = $pdo->prepare("SELECT 1 FROM crecer_marca WHERE id = ? AND usuario_id = ?");
$own->execute([$marca_id, (int)$usuario['id']]);
if (!$own->fetchColumn()) { http_response_code(403); exit('No autorizado.'); }

// Periodo: el próximo mes.
$anio = (int)date('Y');
$mes  = (int)date('n') + 1;
if ($mes > 12) { $mes = 1; $anio++; }

try {
    $plan = planificar_mes($pdo, $marca_id, $anio, $mes, $n_piezas);
    redactar_calendario($pdo, $plan['calendario_id']);
    header('Location: /crecer/panel/aprobar2.php?marca=' . $marca_id . '&nuevo=1');
    exit;
} catch (Throwable $e) {
    error_log('generar.php: ' . $e->getMessage());
    header('Location: /crecer/panel/aprobar2.php?marca=' . $marca_id . '&err=' . urlencode(substr($e->getMessage(), 0, 120)));
    exit;
}
