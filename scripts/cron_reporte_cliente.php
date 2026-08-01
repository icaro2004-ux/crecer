<?php
// ============================================================
//  CRECER — Reporte SEMANAL al cliente (in-app)  ·  scripts/cron_reporte_cliente.php
//
//  Hace VISIBLE el trabajo del corillo = defensa contra el churn (la paradoja
//  del done-for-you: "¿qué pago si no hago nada?"). Cada semana, por cada cliente
//  con suscripción viva, resume SUS resultados reales y lo deja en la campanita
//  (in-app). Grounded + templado (cero costo de IA, cero invención).
//
//  v1 = in-app (crecer_notificaciones). Email opt-in / WhatsApp = capas siguientes.
//  Cadencia sugerida: 1x/semana (ej. lunes 8am AST) en Hostinger.
//   CLI:  php scripts/cron_reporte_cliente.php
//   URL:  https://tu-dominio/crecer/scripts/cron_reporte_cliente.php?key=CRON_TOKEN[&force=1]
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/notif.php';
require __DIR__ . '/../includes/notificaciones.php';   // crecer_enviar_email (email opt-in)

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
$force = isset($_GET['force']) || in_array('--force', $argv ?? [], true);

// Clientes con suscripción viva (a quienes les sirve el reporte).
try {
    $marcas = $pdo->query(
        "SELECT DISTINCT m.id, m.nombre_negocio
         FROM crecer_marca m JOIN crecer_suscripciones s ON s.marca_id=m.id
         WHERE s.estado IN ('activa','trial','prueba','incompleta')")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { echo "error leyendo marcas: ".$e->getMessage()."\n"; exit; }

$enviados = 0; $saltados = 0; $emails = 0;
foreach ($marcas as $mk) {
    $mid = (int)$mk['id'];
    $c = function(string $sql) use ($pdo, $mid) {
        try { $q=$pdo->prepare($sql); $q->execute([$mid]); return $q->fetchColumn(); } catch (Throwable $e) { return 0; }
    };

    // Dedup: ¿ya salió un resumen esta semana?
    try {
        $ya = $pdo->prepare("SELECT COUNT(*) FROM crecer_notificaciones WHERE marca_id=? AND tipo='resumen' AND created_at >= (NOW() - INTERVAL 5 DAY)");
        $ya->execute([$mid]);
        if ((int)$ya->fetchColumn() > 0 && !$force) { $saltados++; continue; }
    } catch (Throwable $e) { /* si no hay created_at, seguimos (el cron semanal ya limita) */ }

    // Números REALES de la semana (últimos 7 días)
    $creados    = (int)$c("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id=? AND created_at >= (NOW()-INTERVAL 7 DAY)");
    $publicados = (int)$c("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id=? AND estado='publicado' AND publicado_at >= (NOW()-INTERVAL 7 DAY)");
    $pendientes = (int)$c("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id=? AND estado IN ('borrador','aprobado')");
    $ins = (function() use ($pdo,$mid) {
        try { $q=$pdo->prepare("SELECT COALESCE(SUM(g.alcance),0) a, COALESCE(SUM(g.interacciones),0) i
               FROM crecer_contenido c JOIN crecer_metricas g ON g.contenido_id=c.id
               WHERE c.marca_id=? AND c.estado='publicado' AND c.publicado_at >= (NOW()-INTERVAL 7 DAY)");
            $q->execute([$mid]); return $q->fetch(PDO::FETCH_ASSOC) ?: ['a'=>0,'i'=>0];
        } catch (Throwable $e) { return ['a'=>0,'i'=>0]; }
    })();
    $alcance = (int)$ins['a']; $inter = (int)$ins['i'];

    // Señal sobre ruido: si no pasó nada esta semana, no molestamos.
    if ($creados === 0 && $publicados === 0 && $pendientes === 0 && $alcance === 0) { $saltados++; continue; }

    // Mensaje templado, cálido, SIN emoji (el ícono lo pone la campanita).
    $partes = [];
    if ($creados > 0 || $publicados > 0) {
        $partes[] = "El corillo te preparó {$creados} " . ($creados===1?'post':'posts')
                  . ($publicados > 0 ? " y publicó {$publicados}." : ".");
    }
    if ($alcance > 0) {
        $partes[] = "Llegaste a " . number_format($alcance) . " personas"
                  . ($inter > 0 ? " con " . number_format($inter) . " interacciones." : ".");
    }
    if ($pendientes > 0) {
        $partes[] = "Tienes {$pendientes} " . ($pendientes===1?'post esperando':'posts esperando') . " tu OK.";
    }
    $partes[] = "Sigue así.";
    $mensaje = implode(' ', $partes);
    $link = '/crecer/panel/resultados.php?marca=' . $mid;

    notif_crear($pdo, $mid, 'resumen', 'Tu resumen de la semana', $mensaje, $link, 'chart');
    $enviados++;

    // EMAIL opt-in: solo si reporte_email=1 y el dueño tiene correo (el in-app ya salió).
    $optin = 1; $correo = '';
    try {
        $qi = $pdo->prepare("SELECT COALESCE(m.reporte_email,1) opt, u.email FROM crecer_marca m LEFT JOIN usuarios u ON u.id=m.usuario_id WHERE m.id=?");
        $qi->execute([$mid]); $ri = $qi->fetch(PDO::FETCH_ASSOC);
        if ($ri) { $optin = (int)$ri['opt']; $correo = (string)($ri['email'] ?? ''); }
    } catch (Throwable $e) { $optin = 0; }  // columna aún no existe → solo in-app
    if ($optin === 1 && $correo && filter_var($correo, FILTER_VALIDATE_EMAIL) && function_exists('crecer_enviar_email')) {
        $conf   = 'https://encuentraloahora.com/crecer/panel/configuracion.php?marca=' . $mid;
        $resurl = 'https://encuentraloahora.com/crecer/panel/resultados.php?marca=' . $mid;
        $body   = htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8')
                . '<div style="margin-top:14px;font-size:12.5px;color:#8a8a8a">¿No quieres este resumen? <a href="' . $conf . '" style="color:#8a8a8a">Desactívalo en Configuración</a>.</div>';
        $html = function_exists('crecer_email_shell')
            ? crecer_email_shell('Tu resumen de la semana', $body, [
                'eyebrow' => (string)$mk['nombre_negocio'],
                'cta_txt' => 'Ver mis resultados',
                'cta_url' => $resurl,
                'footer'  => 'Te lo manda tu corillo cada semana · Crecer by Encuéntralo.',
              ])
            : '<div>' . htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8') . '</div>';
        if (crecer_enviar_email($correo, 'Tu resumen de la semana · Crecer', $html)) $emails++;
    }
}

echo "[" . date('Y-m-d H:i:s') . "] reporte_cliente_semanal: marcas=" . count($marcas)
   . " in_app={$enviados} emails={$emails} saltados={$saltados}\n";
