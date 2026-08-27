<?php
// ============================================================
//  CRECER — EL APRENDIZAJE ASINCRONO TIENE TECHO
//  tests/test_edicion_tope.php
//
//  EL RIESGO. `edicion_digerir()` tiene LIMIT, pero POR MARCA: el cron le pasa
//  10 a cada una. Con veinte marcas que hayan editado captions, una sola
//  corrida del corillo puede disparar doscientas llamadas al modelo. Nadie lo
//  pidió y nadie lo ve venir — la cola se llena sola, con el uso normal.
//
//  Lo que falta no es un sistema de colas: es un techo. Uno global por corrida
//  y uno por marca, para que ninguna se coma el presupuesto de las demás y la
//  cola se drene poco a poco en corridas sucesivas.
//
//  ══ RED CERRADA POR CONSTRUCCION ══ el borde del Aprendiz va sustituido y se
//  cuenta cuántas veces contesta: esa cifra ES el gasto.
// ============================================================

require_once __DIR__ . '/_sin_gasto.php';

/** EL DOBLE DEL APRENDIZ. Cuenta sus llamadas: eso es lo que se mide. */
function aprendiz_leccion(PDO $pdo, int $marca_id, string $original,
                          string $editado, array $opts = []): ?string {
    $GLOBALS['LLAMADAS'] = (int)($GLOBALS['LLAMADAS'] ?? 0) + 1;
    $GLOBALS['POR_MARCA'][$marca_id] = (int)($GLOBALS['POR_MARCA'][$marca_id] ?? 0) + 1;
    if (($GLOBALS['APRENDIZ_SIM'] ?? 'ok') === 'fallo') {
        throw new RuntimeException('simulado: el modelo no contestó');
    }
    require_once __DIR__ . '/../includes/memoria.php';
    $l = 'Prefiere hablarle de tú y cerrar invitando por WhatsApp.';
    memoria_escribir($pdo, $marca_id, [
        'tipo' => 'preferencia', 'titulo' => mb_strimwidth($l, 0, 120, '…'), 'detalle' => $l,
        'porque' => 'De una edición.', 'fuente' => 'edicion', 'confianza' => 70, 'peso' => 80]);
    return $l;
}
$GLOBALS['APRENDIZ_SIM'] = 'ok';
$GLOBALS['LLAMADAS'] = 0;
$GLOBALS['POR_MARCA'] = [];

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/memoria.php';
require_once __DIR__ . '/../includes/agentes.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}
function llamadas(): int { return (int)($GLOBALS['LLAMADAS'] ?? 0); }

echo "\nEL APRENDIZAJE ASINCRONO TIENE TECHO\n" . str_repeat('=', 58) . "\n";

$cnt = fn(string $t, string $w = '1') => (int)$GLOBALS['pdo']->query(
    "SELECT COUNT(*) FROM {$t} WHERE {$w}")->fetchColumn();
$g = ['ia' => $cnt('crecer_ia_log'), 'cuota' => $cnt('crecer_img_cuota_asiento')];

/** Siembra N notas crudas pendientes para una marca. */
function sembrar(PDO $pdo, int $marca_id, int $cuantas): void {
    for ($i = 1; $i <= $cuantas; $i++) {
        edicion_anotar($pdo, $marca_id, 9000 + $i,
            'Texto viejo numero ' . $i . ' del negocio.',
            'Texto nuevo numero ' . $i . ', mucho mejor y en su voz.');
    }
}
function pendientes(PDO $pdo, int $marca_id): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM crecer_memoria
                              WHERE marca_id={$marca_id} AND tipo='edicion_cruda'
                                AND estado='pendiente_revision'")->fetchColumn();
}
/** Notas con lease VIVO: reclamadas y no procesadas. Deben ser cero al terminar. */
function con_lease(PDO $pdo, int $marca_id): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM crecer_memoria
                              WHERE marca_id={$marca_id} AND tipo='edicion_cruda'
                                AND estado='pendiente_revision'
                                AND valid_until IS NOT NULL AND valid_until > NOW()")->fetchColumn();
}

$limpiar = [];
try {
    // ══════════════════════════════════════════════════════════════
    //  1 · EL TECHO EXISTE Y SE PUEDE LEER
    // ══════════════════════════════════════════════════════════════
    echo "\n  — cuánto puede costar una corrida —\n";
    ok('hay un techo por corrida', function_exists('aprendiz_tope_corrida'),
       'sin él, veinte marcas con ediciones son doscientas llamadas');
    ok('y un techo por marca',     function_exists('aprendiz_tope_marca'),
       'sin él, una sola marca se come la corrida entera');

    if (!function_exists('aprendiz_tope_corrida')) {
        throw new RuntimeException('sin techo no hay nada que medir');
    }
    $TOPE_C = aprendiz_tope_corrida();
    $TOPE_M = aprendiz_tope_marca();
    ok('el techo por corrida es conservador', $TOPE_C > 0 && $TOPE_C <= 20, (string)$TOPE_C);
    ok('el de marca es menor que el de corrida', $TOPE_M > 0 && $TOPE_M < $TOPE_C,
       $TOPE_M . ' de ' . $TOPE_C);

    // ══════════════════════════════════════════════════════════════
    //  2 · UNA MARCA NO SE COME LA CORRIDA
    // ══════════════════════════════════════════════════════════════
    echo "\n  — una marca con la cola llena —\n";
    $fa = Fixture::crear($pdo, 'topA', false, 'admin');
    $limpiar[] = $MA = (int)$fa['marca_id'];
    sembrar($pdo, $MA, $TOPE_M + 4);
    ok('sembradas de más', pendientes($pdo, $MA) === $TOPE_M + 4, (string)pendientes($pdo, $MA));

    $l0 = llamadas();
    $r1 = edicion_digerir($pdo, $MA);
    ok('no pasa de su techo por marca', llamadas() - $l0 <= $TOPE_M,
       (llamadas() - $l0) . ' llamadas, techo ' . $TOPE_M);
    ok('y digirió justo eso',      (int)$r1['digeridas'] === $TOPE_M, json_encode($r1));
    ok('las de más siguen pendientes', pendientes($pdo, $MA) === 4, (string)pendientes($pdo, $MA));
    ok('sin lease colgando',       con_lease($pdo, $MA) === 0,
       'reclamar filas que no se van a procesar las deja bloqueadas 10 minutos');

    //  LA COLA SE DRENA EN CORRIDAS SUCESIVAS, no de golpe.
    echo "\n  — y se drena poco a poco —\n";
    $vueltas = 0;
    while (pendientes($pdo, $MA) > 0 && $vueltas < 10) {
        $l = llamadas();
        edicion_digerir($pdo, $MA);
        ok('la vuelta ' . ($vueltas + 1) . ' respeta el techo', llamadas() - $l <= $TOPE_M,
           (llamadas() - $l) . ' llamadas');
        $vueltas++;
    }
    ok('la cola queda vacía',   pendientes($pdo, $MA) === 0);
    ok('y costó varias vueltas', $vueltas >= 2, $vueltas . ' vueltas');

    // ══════════════════════════════════════════════════════════════
    //  3 · EL TECHO GLOBAL · varias marcas comparten la bolsa
    // ══════════════════════════════════════════════════════════════
    echo "\n  — tres marcas, un solo presupuesto —\n";
    $marcas = [];
    foreach (['topB', 'topC', 'topD'] as $etq) {
        $f = Fixture::crear($pdo, $etq, false, 'admin');
        $limpiar[] = $mid = (int)$f['marca_id'];
        $marcas[] = $mid;
        sembrar($pdo, $mid, $TOPE_M + 3);
    }
    $GLOBALS['POR_MARCA'] = [];
    $l1 = llamadas();
    $bolsa = null;
    $tot = ['digeridas' => 0];
    foreach ($marcas as $mid) {
        $r = edicion_digerir($pdo, $mid, null, $bolsa);
        $tot['digeridas'] += (int)$r['digeridas'];
    }
    $gastado = llamadas() - $l1;
    ok('la corrida entera no pasa del techo global', $gastado <= $TOPE_C,
       $gastado . ' llamadas, techo ' . $TOPE_C);
    ok('y ninguna marca pasó del suyo',
       count(array_filter($GLOBALS['POR_MARCA'], fn($v) => $v > $TOPE_M)) === 0,
       json_encode($GLOBALS['POR_MARCA']));
    ok('más de una marca tuvo su turno',
       count(array_filter($GLOBALS['POR_MARCA'], fn($v) => $v > 0)) >= 2,
       json_encode($GLOBALS['POR_MARCA']) . ' — la primera no puede quedarse con todo');

    //  Y LO QUE NO CUPO SIGUE AHI, sin lease.
    $quedan = 0; $bloqueadas = 0;
    foreach ($marcas as $mid) { $quedan += pendientes($pdo, $mid); $bloqueadas += con_lease($pdo, $mid); }
    ok('lo que no cupo sigue pendiente', $quedan > 0, (string)$quedan);
    ok('y nada quedó reclamado en falso', $bloqueadas === 0, (string)$bloqueadas);

    //  LA SIGUIENTE CORRIDA CONTINUA.
    echo "\n  — la corrida siguiente continúa donde quedó —\n";
    $l2 = llamadas();
    $bolsa2 = null;
    foreach ($marcas as $mid) edicion_digerir($pdo, $mid, null, $bolsa2);
    ok('vuelve a gastar dentro del techo', llamadas() - $l2 <= $TOPE_C,
       (llamadas() - $l2) . ' llamadas');
    $quedan2 = 0;
    foreach ($marcas as $mid) $quedan2 += pendientes($pdo, $mid);
    ok('y la cola bajó', $quedan2 < $quedan, $quedan . ' → ' . $quedan2);

    // ══════════════════════════════════════════════════════════════
    //  4 · UN FALLO GASTA SU TURNO, NO DA VUELTAS
    // ══════════════════════════════════════════════════════════════
    echo "\n  — un fallo consume su turno de la corrida —\n";
    $fe = Fixture::crear($pdo, 'topE', false, 'admin');
    $limpiar[] = $ME = (int)$fe['marca_id'];
    sembrar($pdo, $ME, $TOPE_M + 2);
    $GLOBALS['APRENDIZ_SIM'] = 'fallo';
    $l3 = llamadas();
    $rf = edicion_digerir($pdo, $ME);
    $GLOBALS['APRENDIZ_SIM'] = 'ok';
    ok('los fallos también topan',   llamadas() - $l3 <= $TOPE_M,
       (llamadas() - $l3) . ' llamadas');
    ok('y se cuentan como fallidas', (int)$rf['fallidas'] === $TOPE_M, json_encode($rf));
    ok('sin dar vueltas dentro de la misma corrida',
       (int)$rf['digeridas'] === 0 && llamadas() - $l3 === $TOPE_M,
       'reintentar dentro de la corrida multiplicaría el gasto');
    ok('y sin lease colgando',       con_lease($pdo, $ME) === 0);

    // ══════════════════════════════════════════════════════════════
    //  5 · EL CRON USA LA BOLSA, NO UNA POR MARCA
    // ══════════════════════════════════════════════════════════════
    echo "\n  — el cron reparte una sola bolsa —\n";
    $cron = (string)file_get_contents(__DIR__ . '/../scripts/cron_corillo.php');
    $cod  = (string)preg_replace(['~/\*[\s\S]*?\*/~', '~^\s*//[^\n]*$~m'], ' ', $cron);
    //  El `[^)]*` de la primera version paraba en el `)` de `(int)` y nunca
    //  llegaba a ver la bolsa: daba rojo señalando codigo correcto.
    ok('el cron pasa una bolsa compartida',
       preg_match('~edicion_digerir\(.*bolsa~i', $cod) === 1,
       'sin bolsa compartida, el techo global se multiplica por cada marca');
    ok('y deja de repartir cuando se acaba',
       preg_match('~break~', $cod) === 1 || preg_match('~restantes~', $cod) === 1,
       'la bolsa tiene que poder agotarse');

    // ── DOS PROCESOS NO DUPLICAN ─────────────────────────────────
    echo "\n  — dos corridas a la vez no procesan la misma fila —\n";
    $ff = Fixture::crear($pdo, 'topF', false, 'admin');
    $limpiar[] = $MF = (int)$ff['marca_id'];
    sembrar($pdo, $MF, 1);
    $script = __DIR__ . '/_digerir_runner.php';
    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $procs = []; $tubos = [];
    for ($k = 0; $k < 2; $k++) {
        $tubos[$k] = [];
        $procs[$k] = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script)
                               . ' ' . $MF, $desc, $tubos[$k], dirname(__DIR__));
    }
    $sal = [];
    foreach ($procs as $k => $ph) {
        if (!is_resource($ph)) { $sal[$k] = []; continue; }
        $txt = stream_get_contents($tubos[$k][1]);
        fclose($tubos[$k][1]); fclose($tubos[$k][2]); proc_close($ph);
        $ls = array_values(array_filter(array_map('trim', explode("\n", (string)$txt))));
        $sal[$k] = json_decode((string)end($ls), true) ?: [];
    }
    ok('entre los dos, una sola llamada',
       (int)($sal[0]['llamadas'] ?? 0) + (int)($sal[1]['llamadas'] ?? 0) === 1,
       json_encode($sal));
    ok('y una sola digestión',
       (int)($sal[0]['digeridas'] ?? 0) + (int)($sal[1]['digeridas'] ?? 0) === 1,
       json_encode($sal));

} catch (Throwable $e) {
    $fallos++; echo "\n  EXCEPCION · " . get_class($e) . ': ' . $e->getMessage()
                  . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    foreach ($limpiar as $mid) { try { Fixture::limpiar($pdo, $mid); } catch (Throwable $e) {} }
    echo "\n  (fixtures limpiadas)\n";
}

echo "\n  — el costo —\n";
ok('ni una fila nueva en el log del modelo', $cnt('crecer_ia_log') === $g['ia'],
   'antes ' . $g['ia'] . ' · ahora ' . $cnt('crecer_ia_log'));
ok('cero asientos de cuota', $cnt('crecer_img_cuota_asiento') === $g['cuota']);

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  EL GASTO TIENE TECHO · $n afirmaciones\n\n"
    : "  $fallos FALLAS de $n\n\n";
exit($fallos === 0 ? 0 : 1);
