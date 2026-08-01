<?php
// ============================================================
//  CRECER — Reporte DIARIO al fundador (email)  ·  scripts/cron_reporte_diario.php
//
//  El "jefe de operaciones IA" de Crecer le escribe a Manuel cada mañana:
//   (1) Lo que necesita tu decisión · (2) Lo que la IA hizo · (3) Cómo va el negocio.
//  GROUNDED (principio de verdad): junta HECHOS REALES de la BD y la IA solo los
//  REDACTA — no inventa actividad. Se loguea en crecer_ia_log (evidencia XPRIZE #2:
//  agente operando el negocio del creador).
//
//  Destinatario: REPORTE_EMAIL (config) · fallback jmp.arch.eng@gmail.com.
//  Cadencia sugerida: 1x/día (ej. 7:00am AST) en Hostinger.
//   CLI:  php scripts/cron_reporte_diario.php
//   URL:  https://tu-dominio/crecer/scripts/cron_reporte_diario.php?key=CRON_TOKEN[&force=1]
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/ia.php';
require __DIR__ . '/../includes/notificaciones.php';
require_once __DIR__ . '/../includes/ops_agentes.php';   // agentes de ops (nombres de en-riesgo / cierres calientes)

$es_cli = (PHP_SAPI === 'cli');
if (!$es_cli) {
    $token = defined('CRON_TOKEN') ? CRON_TOKEN : '';
    if ($token === '' || !hash_equals($token, (string)($_GET['key'] ?? ''))) {
        http_response_code(403); header('Content-Type: text/plain; charset=utf-8');
        echo "403 — cron no autorizado.\n"; exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}
@set_time_limit(0);

$destino = defined('REPORTE_EMAIL') && REPORTE_EMAIL ? REPORTE_EMAIL : 'jmp.arch.eng@gmail.com';
$force   = isset($_GET['force']) || in_array('--force', $argv ?? [], true);

// ── Dedup: no re-enviar si ya salió hoy (salvo ?force) ──
try {
    $ya = (int)$pdo->query("SELECT COUNT(*) FROM crecer_ia_log WHERE agente='reporte_diario' AND estado='ok' AND DATE(created_at)=CURDATE()")->fetchColumn();
    if ($ya > 0 && !$force) { echo "· Reporte de hoy ya enviado (usa ?force=1 para reenviar).\n"; exit; }
} catch (Throwable $e) {}

// ── HECHOS REALES (cada uno defensivo; si falla, 0) ──
$q1 = fn(string $sql) => (function() use ($pdo, $sql) { try { return $pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; } })();

// Estado del negocio
$clientes      = (int)$q1("SELECT COUNT(*) FROM crecer_marca");
$clientes_hoy  = (int)$q1("SELECT COUNT(*) FROM crecer_marca WHERE DATE(created_at)=CURDATE()");
$mrr           = (float)$q1("SELECT COALESCE(SUM(p.precio_mensual),0) FROM crecer_suscripciones s JOIN crecer_planes p ON p.id=s.plan_id WHERE s.estado='activa'");
$activas       = (int)$q1("SELECT COUNT(*) FROM crecer_suscripciones WHERE estado='activa'");
$en_prueba     = (int)$q1("SELECT COUNT(*) FROM crecer_suscripciones WHERE estado IN ('incompleta','trial','prueba')");
$ia_mes        = (float)$q1("SELECT COALESCE(SUM(costo_usd),0) FROM crecer_ia_log WHERE estado='ok' AND YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())");
$margen        = $mrr - $ia_mes;
$autopilot     = (int)$q1("SELECT COUNT(*) FROM crecer_marca WHERE autopilot=1");

// Lo que la IA hizo HOY
$posts_hoy     = (int)$q1("SELECT COUNT(*) FROM crecer_contenido WHERE DATE(created_at)=CURDATE()");
$publicados_hoy= (int)$q1("SELECT COUNT(*) FROM crecer_contenido WHERE estado='publicado' AND DATE(publicado_at)=CURDATE()");
$ia_hoy_n      = (int)$q1("SELECT COUNT(*) FROM crecer_ia_log WHERE estado='ok' AND DATE(created_at)=CURDATE()");
$ia_hoy_costo  = (float)$q1("SELECT COALESCE(SUM(costo_usd),0) FROM crecer_ia_log WHERE estado='ok' AND DATE(created_at)=CURDATE()");
$ia_errores_hoy= (int)$q1("SELECT COUNT(*) FROM crecer_ia_log WHERE estado='error' AND DATE(created_at)=CURDATE()");

// Lo que NECESITA tu decisión
$fallidos      = (int)$q1("SELECT COUNT(*) FROM crecer_contenido WHERE estado='fallido'");
$soporte_sin   = (int)$q1("SELECT COUNT(*) FROM crecer_soporte WHERE de='cliente' AND leido=0");
$msgs_pend     = (int)$q1("SELECT COUNT(*) FROM crecer_mensajes WHERE estado='pendiente'");
$trial_termina = (int)$q1("SELECT COUNT(*) FROM crecer_suscripciones WHERE estado IN ('incompleta','trial','prueba') AND periodo_fin IS NOT NULL AND periodo_fin BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)");
$decisiones    = $fallidos + $soporte_sin + $msgs_pend + $trial_termina;

$m = fn($n) => '$' . number_format((float)$n, 2);
$hechos =
  "FECHA: " . date('Y-m-d') . "\n" .
  "-- Necesita decisión del fundador --\n" .
  "posts fallidos (destrabar): {$fallidos}\n" .
  "soporte de clientes sin leer: {$soporte_sin}\n" .
  "DMs de clientes pendientes: {$msgs_pend}\n" .
  "pruebas que terminan en <=3 días: {$trial_termina}\n" .
  "-- Lo que la IA hizo hoy --\n" .
  "posts creados hoy: {$posts_hoy}\n" .
  "posts publicados hoy: {$publicados_hoy}\n" .
  "llamadas de IA hoy: {$ia_hoy_n} (costo " . $m($ia_hoy_costo) . ")\n" .
  "errores de IA hoy: {$ia_errores_hoy}\n" .
  "-- Estado del negocio --\n" .
  "clientes totales: {$clientes} (nuevos hoy: {$clientes_hoy})\n" .
  "MRR: " . $m($mrr) . " ({$activas} activas, {$en_prueba} en prueba)\n" .
  "gasto IA del mes: " . $m($ia_mes) . " · MARGEN del mes: " . $m($margen) . "\n" .
  "marcas en autopilot: {$autopilot}\n";

// Enriquecer con los AGENTES DE OPS (nombres concretos, no solo conteos).
$ops = ops_vigilar($pdo);
$op_riesgo = $ops['retencion'] ?? []; $op_cal = $ops['conversion'] ?? [];
if ($op_riesgo) {
    $hechos .= "clientes EN RIESGO de churn (reenganchar): "
        . implode(', ', array_map(fn($r)=>$r['nombre']." ({$r['dias']}d)", array_slice($op_riesgo,0,8))) . "\n";
    $decisiones += count($op_riesgo);
}
if ($op_cal) {
    $hechos .= "cierres CALIENTES (trials enganchados sin pagar): "
        . implode(', ', array_map(fn($r)=>$r['nombre']." ({$r['publicados']} pub)", array_slice($op_cal,0,8))) . "\n";
    $decisiones += count($op_cal);
}

// ── La IA REDACTA el resumen a partir de los hechos (no inventa) ──
$prompt =
  "Eres el jefe de operaciones de IA de Crecer (plataforma de marketing con IA para microempresas de PR). " .
  "Le escribes al FUNDADOR (Manuel) un resumen diario CORTO por email, a partir EXCLUSIVAMENTE de estos hechos reales. " .
  "NO inventes datos ni actividad que no esté aquí. Español directo, sin floritura, tono de colega que ejecuta.\n\n" .
  "Estructura EXACTA (3 bloques):\n" .
  "1) LO QUE NECESITA TU DECISIÓN — si el total de decisiones es 0, di claramente 'Nada pide tu OK hoy — todo tranqui.'\n" .
  "2) LO QUE HICE HOY — resume la actividad autónoma de la IA (posts, publicaciones, costo).\n" .
  "3) CÓMO VA EL NEGOCIO — MRR, margen, clientes, en 2-3 líneas.\n" .
  "La PRIMERA línea del correo debe ser el titular (ej. 'X cosas piden tu OK' o 'Todo tranqui hoy'). Máximo ~180 palabras.\n\n" .
  "HECHOS:\n" . $hechos;

$r = ia_ejecutar($pdo, 'reporte_diario', 'Resumen diario al fundador', $prompt, ['max_tokens' => 700]);
$cuerpo = trim((string)($r['texto'] ?? ''));
if ($cuerpo === '' || ($r['estado'] ?? '') === 'error' || ($r['modelo'] ?? '') === 'mock') {
    // Fallback grounded (sin IA): arma el correo con los hechos directos.
    $titular = $decisiones > 0 ? "{$decisiones} cosa(s) piden tu OK" : "Todo tranqui hoy";
    $cuerpo  = $titular . "\n\n" . $hechos;
}

// ── Email con MARCA (plantilla crecer_email_shell) ──
$titular = strtok($cuerpo, "\n") ?: 'Reporte diario';
$resto   = trim(mb_substr($cuerpo, mb_strlen($titular)));
$body    = nl2br(htmlspecialchars($resto !== '' ? $resto : $cuerpo, ENT_QUOTES, 'UTF-8'));
$html = function_exists('crecer_email_shell')
    ? crecer_email_shell($titular, $body, [
        'eyebrow' => 'Reporte diario · ' . date('Y-m-d'),
        'cta_txt' => 'Ver el panel',
        'cta_url' => 'https://encuentraloahora.com/crecer/panel/admin.php',
        'footer'  => 'Generado por tu equipo de IA · datos reales de tu panel.',
      ])
    : '<div style="white-space:pre-wrap">' . htmlspecialchars($cuerpo, ENT_QUOTES, 'UTF-8') . '</div>';

$asunto = 'Crecer · ' . mb_substr($titular, 0, 80);
$ok = crecer_enviar_email($destino, $asunto, $html);

$linea = '[' . date('Y-m-d H:i:s') . '] reporte_diario → ' . $destino
       . ' · ' . ($ok ? 'ENVIADO' : 'FALLÓ envío')
       . " · decisiones={$decisiones} posts_hoy={$posts_hoy} MRR=" . $m($mrr) . ' margen=' . $m($margen)
       . ' · modelo=' . ($r['modelo'] ?? '?');
echo $linea . "\n";
if (!$ok) { error_log('cron_reporte_diario: ' . $linea); }
