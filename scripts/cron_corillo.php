<?php
// ============================================================
//  CRECER — Cron del CORILLO AUTÓNOMO
//  scripts/cron_corillo.php
//
//  Corre el trabajo autónomo: para cada marca con piloto automático
//  activo y plan vigente, el corillo planifica y redacta los posts
//  que falten y los deja listos para aprobar. Avisa al dueño por email.
//
//  Cadencia sugerida: 1 vez por semana (ej. lunes 7am) en Hostinger.
//   CLI:  php /ruta/crecer/scripts/cron_corillo.php
//   URL:  https://tu-dominio/crecer/scripts/cron_corillo.php?key=CRON_TOKEN
// ============================================================

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/agentes.php';

$es_cli = (PHP_SAPI === 'cli');
if (!$es_cli) {
    $token = defined('CRON_TOKEN') ? CRON_TOKEN : '';
    if ($token === '' || !hash_equals($token, (string)($_GET['key'] ?? ''))) {
        http_response_code(403); header('Content-Type: text/plain; charset=utf-8');
        echo "403 — cron no autorizado.\n"; exit;
    }
    header('Content-Type: application/json; charset=utf-8');
}

@set_time_limit(0);
$inicio = microtime(true);
try {
    $res = correr_corillo($pdo);

    //  EL CICLO SEMANAL. El dueño cierra la semana un domingo por la noche y
    //  se va; si la preparacion solo saliera de su boton, la semana siguiente
    //  se quedaria sin empezar hasta que volviera a abrir la aplicacion.
    //
    //  Entra por la MISMA funcion que el boton, que reclama antes de llamar
    //  al modelo: si el dueño pulso hace un segundo, esto se encuentra la fila
    //  reclamada y se va sin gastar nada. Va en su propio try porque una marca
    //  rara no puede tumbar la corrida del corillo.
    $ciclo = ['revisadas' => 0, 'preparadas' => 0, 'creadas' => 0];
    try {
        require_once __DIR__ . '/../includes/meta_ciclo.php';
        $ciclo = ciclo_barrer($pdo);
    } catch (Throwable $e) { error_log('cron_corillo ciclo: ' . $e->getMessage()); }
    // ADR-0004: el Analista vigila cada marca procesada y deja señales accionables (autónomo).
    try {
        require_once __DIR__ . '/../includes/analista.php';
        $an_total = 0;
        foreach (($res['detalle'] ?? []) as $d) { $an_total += analista_vigilar($pdo, (int)$d['marca_id']); }
    } catch (Throwable $e) { error_log('cron_corillo analista: ' . $e->getMessage()); }

    //  EL APRENDIZ DIGIERE LAS EDICIONES DEL DUEÑO. Cuando corrige un caption,
    //  el texto se guarda al instante y la nota queda cruda: aprender de ella
    //  es trabajo de aqui, no de su pantalla. Va en su propio try porque una
    //  edicion rara no puede tumbar la corrida del corillo.
    //  UNA SOLA BOLSA PARA TODA LA CORRIDA. Se crea aqui y se pasa por
    //  referencia a cada marca: asi el techo global es de la corrida y no de
    //  cada marca. Cuando se agota, se deja de repartir y lo que quede en la
    //  cola lo drena la proxima — aprender de una edicion no es urgente.
    $ed_total = ['digeridas' => 0, 'fallidas' => 0];
    $ed_bolsa = ['restantes' => aprendiz_tope_corrida()];
    try {
        foreach (($res['detalle'] ?? []) as $d) {
            if ((int)$ed_bolsa['restantes'] <= 0) break;
            $r = edicion_digerir($pdo, (int)$d['marca_id'], null, $ed_bolsa);
            $ed_total['digeridas'] += (int)$r['digeridas'];
            $ed_total['fallidas']  += (int)$r['fallidas'];
        }
        $res['ediciones_digeridas'] = $ed_total['digeridas'];
        $res['ediciones_fallidas']  = $ed_total['fallidas'];
    } catch (Throwable $e) { error_log('cron_corillo aprendiz: ' . $e->getMessage()); }
    $res['analista_senales'] = $an_total ?? 0;
    $res['ms'] = (int)round((microtime(true) - $inicio) * 1000);
    if ($es_cli) {
        echo "[" . date('Y-m-d H:i:s') . "] corillo autónomo: "
           . "marcas={$res['marcas']} posts_creados={$res['creadas']} ({$res['ms']}ms)\n";
        foreach ($res['detalle'] as $d) {
            echo "   marca #{$d['marca_id']} → {$d['creadas']} post(s)"
               . ($d['razon'] ? " ({$d['razon']})" : '') . "\n";
        }
    } else {
        echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
} catch (Throwable $e) {
    error_log('cron_corillo: ' . $e->getMessage());
    if ($es_cli) { fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n"); exit(1); }
    http_response_code(500); echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
