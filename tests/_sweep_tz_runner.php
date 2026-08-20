<?php
// ============================================================
//  CRECER — EL BARRIDO CON LOS DOS RELOJES DESFASADOS
//  tests/_sweep_tz_runner.php
//
//  Reproduce la condicion de produccion que rompio el backoff:
//  PHP corre en una zona (APP_TZ) y el servidor de base en otra.
//  En Hostinger APP_TZ es America/Puerto_Rico y MySQL va en UTC —
//  cuatro horas de diferencia.
//
//  Con el defecto, el vencimiento lo calculaba PHP y lo comparaba
//  MySQL: nacia cuatro horas en el pasado, la puerta lo daba por
//  vencido siempre y CADA recarga volvia a sondear la misma pieza.
//
//  El desfase se fuerza por los dos lados para que la prueba no
//  dependa de como este configurada la maquina donde corre:
//  APP_TZ se define ANTES de db.php (define() es primero-gana) y a
//  la sesion de MySQL se le fija otra zona.
//
//  Imprime lineas maquina-legibles; quien asierta es la prueba.
//
//    php tests/_sweep_tz_runner.php <marca> <pieza> <vueltas>
// ============================================================

$mid    = (int)($argv[1] ?? 0);
$pid    = (int)($argv[2] ?? 0);
$vueltas = max(1, (int)($argv[3] ?? 10));
if (!$mid || !$pid) { fwrite(STDERR, "uso: sweep_tz_runner <marca> <pieza> <vueltas>\n"); exit(2); }

require __DIR__ . '/_sin_gasto.php';

define('APP_TZ', 'UTC');                  // PHP en UTC
require dirname(__DIR__) . '/includes/db.php';
$pdo->exec("SET time_zone = '+09:00'");   // MySQL nueve horas por delante
require_once dirname(__DIR__) . '/includes/img_responses.php';

$leer = function () use ($pdo, $pid): array {
    $q = $pdo->prepare("SELECT img_intentos, img_next_poll_at,
                               (img_next_poll_at > NOW()) AS futuro,
                               TIMESTAMPDIFF(MINUTE, NOW(), img_next_poll_at) AS faltan
                          FROM crecer_contenido WHERE id=?");
    $q->execute([$pid]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: [];
};

$desfase = (int)$pdo->query("SELECT TIMESTAMPDIFF(SECOND, '"
         . date('Y-m-d H:i:s') . "', NOW())")->fetchColumn();
echo "DESFASE_SEG=" . $desfase . "\n";

$a = $leer();
echo "INTENTOS_ANTES=" . (int)($a['img_intentos'] ?? -1) . "\n";

for ($i = 0; $i < $vueltas; $i++) img_sweep_pendientes($pdo, $mid);

$b = $leer();
echo "INTENTOS_DESPUES=" . (int)($b['img_intentos'] ?? -1) . "\n";
echo "FUTURO=" . (int)($b['futuro'] ?? 0) . "\n";
echo "FALTAN_MIN=" . (int)($b['faltan'] ?? 0) . "\n";
