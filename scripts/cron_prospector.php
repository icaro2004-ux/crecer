<?php
// ============================================================
//  CRECER — Cron del PROSPECTOR (radar de oportunidades)
//  scripts/cron_prospector.php
//
//  Sale a buscar negocios que podrían necesitar Crecer, los puntúa
//  con datos reales y deja los mejores con su consejo escrito. Nadie
//  se lo pide: se dispara solo. La fila en prospector_runs con su hora
//  es justamente la evidencia de eso.
//
//  Nunca contacta a nadie. Solo prepara.
//
//  Cadencia sugerida: 1 vez por semana (lunes 7am) en Hostinger.
//   CLI:  php /ruta/crecer/scripts/cron_prospector.php
//   URL:  https://tu-dominio/crecer/scripts/cron_prospector.php?key=CRON_TOKEN
//  Opcional: &categoria=repostería&municipio=Bayamón para forzar un barrido.
// ============================================================

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/prospector.php';

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

try {
    if (!prospector_configurado()) {
        throw new RuntimeException('Falta PLACES_API_KEY — define la clave en config.local.php');
    }

    $opts = ['disparo' => $es_cli ? 'cron' : 'cron'];
    foreach (['categoria', 'municipio'] as $k) {
        $v = $es_cli ? ($argv[array_search("--$k", $argv ?: []) + 1] ?? null) : ($_GET[$k] ?? null);
        if (is_string($v) && $v !== '' && strpos($v, '--') !== 0) $opts[$k] = $v;
    }

    $r = prospector_correr($pdo, $opts);

    if ($es_cli) {
        echo "[" . date('Y-m-d H:i:s') . "] prospector: {$r['categoria']} → "
           . "encontrados={$r['encontrados']} nuevos={$r['nuevos']} "
           . "actualizados={$r['actualizados']} consejos={$r['aconsejados']} ({$r['ms']}ms)\n";
        foreach ($r['errores'] as $e) echo "   aviso: $e\n";
    } else {
        echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
} catch (Throwable $e) {
    error_log('cron_prospector: ' . $e->getMessage());
    if ($es_cli) { fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n"); exit(1); }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
