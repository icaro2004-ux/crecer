<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Exportar calendario a .ics (Outlook/Google)
//  panel/calendario_ics.php?marca=<id>
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
requiere_login();
$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { http_response_code(404); exit('Negocio no encontrado.'); }
$marca_id = (int)$marca['id'];

function ics_esc($s){ return str_replace(["\\", ",", ";", "\r\n", "\n"], ["\\\\", "\\,", "\\;", "\\n", "\\n"], (string)$s); }

$lineas = ["BEGIN:VCALENDAR", "VERSION:2.0", "PRODID:-//Encuentralo//Crecer//ES", "CALSCALE:GREGORIAN",
           "X-WR-CALNAME:" . ics_esc($marca['nombre_negocio'] . ' · Encuéntralo')];

// Contenido programado
$c = $pdo->prepare("SELECT id, plataforma, tipo, caption, fecha_programada FROM crecer_contenido WHERE marca_id=? AND fecha_programada IS NOT NULL");
$c->execute([$marca_id]);
foreach ($c->fetchAll() as $p) {
    $dt = date('Ymd\THis', strtotime($p['fecha_programada']));
    $end = date('Ymd\THis', strtotime($p['fecha_programada'] . ' +30 minutes'));
    $lineas[] = "BEGIN:VEVENT";
    $lineas[] = "UID:crecer-contenido-{$p['id']}@encuentralo";
    $lineas[] = "DTSTART:$dt";
    $lineas[] = "DTEND:$end";
    $lineas[] = "SUMMARY:" . ics_esc('📣 ' . ucfirst($p['plataforma']) . ' — ' . mb_substr($p['caption'] ?: $p['tipo'], 0, 50));
    $lineas[] = "DESCRIPTION:" . ics_esc($p['caption'] ?: '');
    $lineas[] = "END:VEVENT";
}
// Órdenes / citas
$o = $pdo->prepare("SELECT id, cliente_nombre, descripcion, monto, estado, fecha_entrega FROM crecer_ordenes WHERE marca_id=? AND fecha_entrega IS NOT NULL");
$o->execute([$marca_id]);
foreach ($o->fetchAll() as $r) {
    $dt = date('Ymd\THis', strtotime($r['fecha_entrega']));
    $end = date('Ymd\THis', strtotime($r['fecha_entrega'] . ' +1 hour'));
    $lineas[] = "BEGIN:VEVENT";
    $lineas[] = "UID:crecer-orden-{$r['id']}@encuentralo";
    $lineas[] = "DTSTART:$dt";
    $lineas[] = "DTEND:$end";
    $lineas[] = "SUMMARY:" . ics_esc('📦 Orden: ' . $r['cliente_nombre'] . ($r['monto'] ? ' ($' . $r['monto'] . ')' : ''));
    $lineas[] = "DESCRIPTION:" . ics_esc(($r['descripcion'] ?: '') . ' · Estado: ' . $r['estado']);
    $lineas[] = "END:VEVENT";
}
$lineas[] = "END:VCALENDAR";

$slug = $marca['slug'] ?: ('negocio-' . $marca_id);
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="encuentralo-' . $slug . '.ics"');
echo implode("\r\n", $lineas);
