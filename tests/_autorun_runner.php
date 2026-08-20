<?php
// ============================================================
//  CRECER — UN PROCESO QUE RECLAMA UNA RONDA, PARA CHOCARLO CON OTROS
//  tests/_autorun_runner.php
//
//  El candado del corillo protege contra DOS PETICIONES A LA VEZ, no contra dos
//  llamadas seguidas. Llamar dos veces en el mismo proceso comprueba la
//  idempotencia; la carrera necesita procesos distintos, con conexiones
//  distintas, entrando en el mismo instante.
//
//    php tests/_autorun_runner.php <marca> <plan> <ronda> <arranque_unix>
//
//  Imprime una linea: `GANO <id>` o `PERDIO` (o `ERROR: ...`). Exactamente uno
//  de todos los que arranquen puede decir GANO. Si dicen dos, el corillo corre
//  dos veces y se factura dos veces.
// ============================================================

$marca    = (int)($argv[1] ?? 0);
$plan     = (int)($argv[2] ?? 0);
$ronda    = (string)($argv[3] ?? '');
$arranque = (float)($argv[4] ?? 0);

require __DIR__ . '/_sin_gasto.php';
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../core/Meta/MetaAutoRunner.php';

// Conectar ANTES de la cita: si cada proceso se conectara despues, el coste de
// conectar los separaria y no chocarian nunca.
try { $pdo->query('SELECT 1'); } catch (Throwable $e) {}

$espera = $arranque - microtime(true);
if ($espera > 0) usleep((int)($espera * 1000000));

try {
    $run = MetaAutoRunner::reclamar($pdo, $marca, $plan, 'cron', null, $ronda);
    echo $run ? "GANO {$run->id}\n" : "PERDIO\n";
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n";
}
