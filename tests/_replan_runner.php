<?php
// ============================================================
//  CRECER — UN PROCESO QUE PIDE UN PLAN  ·  tests/_replan_runner.php
//
//  Existe para que la carrera sea de VERDAD. Simularla con dos llamadas
//  seguidas dentro del mismo proceso no prueba nada: comparten conexión,
//  comparten los `static` del esquema y comparten el orden. La carrera que hay
//  que ganar es entre dos peticiones HTTP distintas, y eso son dos procesos.
//
//    php tests/_replan_runner.php <base> <marca> <meta> <solicitud> <arrancar_en>
//
//  <base>        la base desechable a la que conectarse (nunca la compartida)
//  <arrancar_en> marca de tiempo con microsegundos: los dos procesos esperan
//                aquí y salen juntos. Sin esta barrera, el primero termina
//                antes de que el segundo empiece y no hay carrera que medir.
//
//  Imprime UNA línea de JSON con lo que devolvió meta_plan_generar().
//  CERO RED: _sin_gasto.php deja las llaves en blanco y el modelo cae al mock.
// ============================================================

require_once __DIR__ . '/_sin_gasto.php';

$base   = (string)($argv[1] ?? '');
$marca  = (int)($argv[2] ?? 0);
$meta   = (int)($argv[3] ?? 0);
$sol    = (string)($argv[4] ?? '');
$cuando = (float)($argv[5] ?? 0);

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/meta_negocio.php';

if ($base === '' || !preg_match('/^crecer_prueba_[a-z0-9_]+$/', $base)) {
    fwrite(STDERR, "base desechable no válida: {$base}\n");
    echo json_encode(['ok' => false, 'err' => 'base']), "\n";
    exit(1);
}

//  Conexión PROPIA a la copia. No se reutiliza $pdo: dos procesos que
//  compartieran conexión no serían dos peticiones.
$mio = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . $base . ';charset=' . (defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4'),
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
     PDO::ATTR_EMULATE_PREPARES => false]
);
meta_plan_olvidar_esquema();

//  LA BARRERA. Se espera en bucle corto hasta el instante acordado: es lo que
//  hace que los dos INSERT de la reclamación se jueguen a la vez.
if ($cuando > 0) {
    while (microtime(true) < $cuando) { usleep(500); }
}

$r = meta_plan_generar($mio, $marca, $meta, 'los dos a la vez', $sol);

echo json_encode([
    'ok'       => !empty($r['ok']),
    'repetido' => !empty($r['repetido']),
    'en_curso' => !empty($r['en_curso']),
    'plan_id'  => (int)($r['plan_id'] ?? 0),
    'version'  => (int)($r['version'] ?? 0),
    'err'      => $r['err'] ?? null,
    'pid'      => getmypid(),
], JSON_UNESCAPED_UNICODE), "\n";
