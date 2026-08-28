<?php
// ============================================================
//  CRECER — BARRIDO DEL AYUDANTE  ·  scripts/cron_ayudante.php
//
//  El helper no espera a que el dueño se queje. Cada corrida recorre las
//  cuentas con movimiento, ESCANEA, ARREGLA lo que puede solo (arte trabado,
//  publicación caída, carpeta de fotos) y lo que no puede lo levanta como
//  INCIDENCIA: queda escrito y al fundador le llega email + texto.
//
//  Es la diferencia entre "hay un botón de soporte" y "el soporte lo corre un
//  agente" (criterio #2 XPRIZE): cada corrida queda en crecer_ia_log.
//
//  Cadencia sugerida: cada 15 min en Hostinger.
//   CLI:  php scripts/cron_ayudante.php
//   URL:  https://tu-dominio/crecer/scripts/cron_ayudante.php?key=CRON_TOKEN
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/cron_latido.php';
require __DIR__ . '/../includes/ayudante.php';

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
@ignore_user_abort(true);

// Cuentas con movimiento reciente (no tiene sentido barrer negocios dormidos).
$sql =
  "SELECT DISTINCT m.id, m.nombre_negocio
     FROM crecer_marca m
LEFT JOIN crecer_suscripciones s ON s.marca_id = m.id
    WHERE s.estado IN ('activa','trial','prueba','incompleta')
       OR EXISTS (SELECT 1 FROM crecer_contenido c
                   WHERE c.marca_id = m.id AND c.updated_at >= (NOW() - INTERVAL 3 DAY))
    ORDER BY m.id";
try { $marcas = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
catch (Throwable $e) { echo 'error leyendo marcas: ' . $e->getMessage() . "\n"; exit; }

$t0 = microtime(true);
$n_marcas = 0; $n_hall = 0; $n_fix = 0; $n_esc = 0;

foreach ($marcas as $mk) {
    $mid = (int)$mk['id'];
    try {
        // PRIMERO RECOGER, DESPUÉS QUEJARSE. El arte se produce por cron, pero
        //  hasta ahora recogerlo dependía de que un humano abriera el panel:
        //  img_sweep_pendientes solo se llamaba desde index/propuestas/aprobar2.
        //  En una cuenta que nadie visita, los trabajos terminados se quedaban
        //  en 'queued' para siempre — pagados y sin cobrar — y este mismo cron
        //  los reportaba como colgados. Modo solo_recoger: consulta y guarda lo
        //  que ya está hecho, sin disparar ningún motor (recoger es gratis;
        //  regenerar cuesta, y eso no lo decide un barrido a las 3 AM).
        try {
            require_once __DIR__ . '/../includes/img_responses.php';
            img_sweep_pendientes($pdo, $mid, true, 25);
        } catch (Throwable $e) { error_log('cron_ayudante/sweep: ' . $e->getMessage()); }
        // Los carruseles tenian la MISMA enfermedad: carrusel_sweep_pendientes
        //  solo se llamaba desde carrusel.php, index.php y propuestas.php. El
        //  mismo bug esperando su turno, en otra tabla.
        try {
            require_once __DIR__ . '/../includes/carrusel.php';
            if (function_exists('carrusel_sweep_pendientes')) carrusel_sweep_pendientes($pdo, $mid);
        } catch (Throwable $e) { error_log('cron_ayudante/carrusel: ' . $e->getMessage()); }

        $r = ayudante_atender($pdo, $mid, ['origen' => 'barrido']);
    } catch (Throwable $e) {
        echo "marca #{$mid}: ERROR " . $e->getMessage() . "\n";
        continue;
    }
    $n_marcas++;
    $h = count($r['hallazgos']); $f = count($r['arreglados']); $x = count($r['escalados']);
    $n_hall += $h; $n_fix += $f; $n_esc += $x;
    if ($h > 0) {
        echo "marca #{$mid} ({$mk['nombre_negocio']}): {$h} hallazgo(s), {$f} atendido(s), {$x} escalado(s)\n";
    }
}

$seg = round(microtime(true) - $t0, 1);
$resumen = "barrido: {$n_marcas} cuenta(s), {$n_hall} hallazgo(s), {$n_fix} atendido(s), {$n_esc} escalado(s) en {$seg}s";
echo $resumen . "\n";
_ay_log($pdo, null, 'Barrido automático', $resumen);

//  EL LATIDO. Una linea por corrida en `crecer_pipeline_run`: sin esto,
//  producción no tiene forma de saber si este cron sigue sonando.
cron_latido($pdo, 'ayudante', true,
            (int)round((microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))) * 1000));
