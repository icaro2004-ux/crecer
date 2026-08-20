<?php
// ============================================================
//  CRECER — UN PROCESO QUE PRESENTA UN PLAN, PARA CHOCARLO CON OTROS
//  tests/_presentar_runner.php
//
//  El doble clic de verdad no son dos llamadas seguidas: son dos PETICIONES a
//  la vez. Llamar dos veces a la funcion en el mismo proceso comprueba la
//  idempotencia, no la carrera —la segunda ya ve lo que escribio la primera—.
//  Para la carrera hacen falta procesos distintos, con conexiones distintas,
//  entrando en el mismo instante.
//
//    php tests/_presentar_runner.php <plan> <marca> <arranque_unix>
//
//  Cada proceso duerme hasta <arranque_unix> y solo entonces escribe. Imprime
//  una sola linea: `GANO` o `PERDIO` (o `ERROR: ...`). Exactamente uno de todos
//  los que arranquen puede decir GANO; si dicen dos, el UPDATE no arbitra y la
//  presentacion se estaria contando dos veces.
// ============================================================

$plan     = (int)($argv[1] ?? 0);
$marca    = (int)($argv[2] ?? 0);
$arranque = (float)($argv[3] ?? 0);

require __DIR__ . '/_sin_gasto.php';
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/meta_negocio.php';

// La conexion se abre ANTES de la cita: si cada proceso se conectara despues,
// el coste de conectar los separaria y no chocarian nunca.
try { $pdo->query('SELECT 1'); } catch (Throwable $e) {}

$espera = $arranque - microtime(true);
if ($espera > 0) usleep((int)($espera * 1000000));

try {
    echo meta_plan_presentar($pdo, $plan, $marca) ? "GANO\n" : "PERDIO\n";
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n";
}
