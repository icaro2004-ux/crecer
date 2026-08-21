<?php
// ============================================================
//  CRECER — «UN SOLO GEMINI»: EL CONTRATO DEL RESPALDO
//  tests/test_respaldo_un_solo_gemini.php
//
//  Nace del incidente de #656 en produccion (20 ago 2026). El contrato que se
//  acordo era: cuando OpenAI confirma que el trabajo A no sale por credito
//  agotado, se reusa la MISMA reserva para UN UNICO respaldo de Gemini.
//
//  Lo que hizo de verdad, medido en el libro:
//      asiento #1 sigue reservado (aunque el arte SI se entrego)
//      llamadas       3 → 6
//      costo_usd   0.17 → 0.68
//      provider_job_id → otro resp_...
//      cubo           1/40            (lo unico que salio bien)
//
//  OJO CON ESA CIFRA: costo_usd es COSTO POTENCIAL ANOTADO, no factura. Un
//  intento rechazado por falta de credito puede no haberse cobrado nunca. El
//  gasto real sale de OpenAI Costs; este libro sirve para cuadrar unidades de
//  cliente, no para declarar dinero.
//
//  Ningun proveedor real: el runner sustituye LOS DOS bordes de red.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/_fixture.php';
require_once __DIR__ . '/../includes/cuota_imagenes.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nEL RESPALDO: UNA IMAGEN, UNA UNIDAD, UN SOLO PROVEEDOR DE RESPALDO\n"
   . str_repeat('=', 66) . "\n";

if (!CuotaImg::disponible($pdo, true)) {
    echo "\n  SALTADA: falta la migracion de la cuota\n\n"; exit(2);
}

/** Corre el runner en su propio proceso (el que tiene la red sustituida). */
function correr(int $M, int $P, string $modo): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . '_respaldo_runner.php') . ' '
         . $M . ' ' . $P . ' ' . escapeshellarg($modo) . ' 2>&1';
    $sal = []; exec($cmd, $sal);
    $d = ['_sal' => $sal];
    foreach ($sal as $l) { if (strpos($l, '=') !== false) { [$k, $v] = explode('=', trim($l), 2); $d[$k] = $v; } }
    return $d;
}

function volcar(array $d, array $claves): void {
    echo "\n  — la traza medida —\n";
    foreach ($claves as $k) printf("    %-22s %s\n", $k, $d[$k] ?? '(sin dato)');
}

$M = null;
try {
    $fx = Fixture::crear($pdo, 'respaldo');
    $M  = (int)$fx['marca_id'];
    $P  = (int)$fx['piezas'][0];

    $limpiar = function () use ($pdo, $M) {
        $pdo->prepare("DELETE FROM crecer_img_cuota_asiento WHERE marca_id=?")->execute([$M]);
        $pdo->prepare("DELETE FROM crecer_img_cuota_cubo WHERE marca_id=?")->execute([$M]);
    };

    // ══════════════════════════════════════════════════════════
    //  1 · GEMINI ENTREGA — exactamente P1 una vez, P2 y P4 cero
    // ══════════════════════════════════════════════════════════
    echo "\n  ══ el respaldo entrega ══\n";
    $limpiar();
    $d = correr($M, $P, 'entrega');
    if (!isset($d['ASIENTO'])) {
        echo "  el runner no arranco:\n";
        foreach (array_slice($d['_sal'], -20) as $l) echo "    $l\n";
        $fallos++; $n++;
    } else {
        echo "\n  — el sondeo cierra el trabajo A —\n";
        ok('el proveedor confirma y la pieza suelta el job', ($d['TRAS_SONDEO_JOB'] ?? 'x') === '');
        ok('y queda el permiso de UN respaldo',
           strpos((string)($d['TRAS_SONDEO_CLASE'] ?? ''), 'fb:') === 0,
           "clase=«{$d['TRAS_SONDEO_CLASE']}»");

        echo "\n  — JAMAS otro trabajo de OpenAI —\n";
        //  El sensor. El transporte falso reparte un id nuevo cada vez que
        //  alguien hace POST a /v1/responses; si aparece alguno, es que se
        //  encolo otra generacion sobre la misma unidad.
        ok('no se encolo ningun trabajo B', (int)($d['N_JOBS_NUEVOS'] ?? 0) === 0,
           "se repartieron: «{$d['JOBS_NUEVOS']}»\n         quien lo pidio: {$d['PILA_B']}");
        ok('cero entradas a P4', (int)($d['POST_RESPONSES'] ?? 0) === 0);
        ok('cero entradas a P2', (int)($d['POST_OPENAI_IMG'] ?? 0) === 0);
        ok('resp_A sigue siendo resp_A', ($d['PROVIDER_JOB_DESPUES'] ?? '') === 'resp_A',
           "provider_job_id=«{$d['PROVIDER_JOB_DESPUES']}»");

        echo "\n  — un solo respaldo, y es Gemini —\n";
        ok('P1 exactamente una vez', (int)($d['POST_GEMINI_IMG'] ?? 0) === 1,
           "peticiones de imagen a Gemini: {$d['POST_GEMINI_IMG']}");
        ok('el respaldo entrego imagen', ($d['PIEZA_GRAFICA'] ?? '') !== '');
        ok('la pieza queda cerrada', ($d['PIEZA_ESTADO'] ?? '') === 'ok');

        echo "\n  — el libro cuadra —\n";
        ok('un solo asiento', (int)($d['ASIENTOS_TOTAL'] ?? 0) === 1);
        ok('la unidad se confirma al entregar', ($d['ASIENTO_ESTADO'] ?? '') === 'confirmado',
           "estado=«{$d['ASIENTO_ESTADO']}» · si el rescate entrega y nadie confirma, la "
           . 'reserva se queda abierta para siempre — barrerCaducadas() la salta porque '
           . 'tiene job atado');
        //  Una entrada mas que la del encolado: la del respaldo. Ni una mas.
        ok('llamadas sube exactamente 1',
           (int)($d['LLAMADAS_DESPUES'] ?? 0) === (int)($d['LLAMADAS_ANTES'] ?? 0) + 1,
           "antes={$d['LLAMADAS_ANTES']} · despues={$d['LLAMADAS_DESPUES']}");
        volcar($d, ['LLAMADAS_ANTES','LLAMADAS_DESPUES','COSTO_ANTES','COSTO_DESPUES',
                    'PROVIDER_JOB_DESPUES','ASIENTO_ESTADO','POST_RESPONSES',
                    'POST_OPENAI_IMG','POST_GEMINI_IMG','POST_GEMINI_TXT','SALIDAS_TOTAL']);
    }

    // ══════════════════════════════════════════════════════════
    //  2 · LA SEGUNDA ENTRADA REBOTA
    // ══════════════════════════════════════════════════════════
    echo "\n  ══ el respaldo, dos veces ══\n";
    $limpiar();
    $d2 = correr($M, $P, 'segunda');
    ok('la primera entrega', ($d2['FB_URL'] ?? '') !== '');
    ok('la segunda devuelve vacio', ($d2['FB_URL2'] ?? 'x') === '',
       "devolvio «{$d2['FB_URL2']}» · el permiso 'fb:' se consume al entrar");
    ok('y no toca la red en la segunda', (int)($d2['RED_EN_LA_SEGUNDA'] ?? 9) === 0,
       "salidas en la segunda pasada: {$d2['RED_EN_LA_SEGUNDA']}");
    ok('sigue habiendo un solo asiento', (int)($d2['ASIENTOS_TOTAL'] ?? 0) === 1);

    // ══════════════════════════════════════════════════════════
    //  3 · SI FALLA, LIBERA — y el reintento cuesta unidad nueva
    // ══════════════════════════════════════════════════════════
    echo "\n  ══ el respaldo tampoco entrega ══\n";
    $limpiar();
    $d3 = correr($M, $P, 'falla');
    ok('no hay imagen', ($d3['FB_URL'] ?? 'x') === '');
    ok('la unidad vuelve', ($d3['ASIENTO_ESTADO'] ?? '') === 'liberado',
       "estado=«{$d3['ASIENTO_ESTADO']}»");
    ok('la pieza queda en fallo recuperable', ($d3['PIEZA_ESTADO'] ?? '') === 'error');
    ok('y marcada para que nada automatico vuelva a entrar',
       strpos((string)($d3['PIEZA_CLASE'] ?? ''), 'fbx:') === 0,
       "clase=«{$d3['PIEZA_CLASE']}»");
    //  liberar() retira la llave idempotente. Sin eso, el reintento explicito
    //  del dueño reusaria una reserva ya cerrada — o sea, imagen gratis.
    ok('un intento nuevo abre OTRO asiento', ($d3['ASIENTO_ES_OTRO'] ?? '0') === '1',
       "asiento del reintento: {$d3['ASIENTO_DEL_REINTENTO']} · original: {$d3['ASIENTO']}");
    ok('sin encolar nada en OpenAI', (int)($d3['POST_RESPONSES'] ?? 0) === 0);

    // ══════════════════════════════════════════════════════════
    //  4 · RECONCILIAR LO YA ENTREGADO, SIN RED
    // ══════════════════════════════════════════════════════════
    echo "\n  ══ la pieza ya tiene su arte: cuadrar el libro ══\n";
    $limpiar();
    $d4 = correr($M, $P, 'reconcilia');
    ok('en seco dice que puede, y no escribe', ($d4['SECO_PUEDE'] ?? '') === '1'
                                            && ($d4['SECO_HECHO'] ?? '') === '0');
    ok('reconcilia', ($d4['REC_HECHO'] ?? '') === '1');
    ok('la pieza queda cerrada', ($d4['PIEZA_ESTADO'] ?? '') === 'ok');
    ok('sin sello de error', ($d4['PIEZA_CLASE'] ?? 'x') === '');
    ok('el asiento se confirma', ($d4['ASIENTO_ESTADO'] ?? '') === 'confirmado');
    ok('CERO llamadas de red', (int)($d4['SALIDAS_TOTAL'] ?? 9) === 0,
       "salidas: {$d4['SALIDAS_TOTAL']} · reconciliar no pregunta ni genera");
    //  Reconciliar cuadra lo que ya existe. No fabrica lo que falta.
    ok('sin grafica no reconcilia nada', ($d4['SIN_GRAFICA_PUEDE'] ?? '1') === '0'
                                      && ($d4['SIN_GRAFICA_HECHO'] ?? '1') === '0');

    // ══════════════════════════════════════════════════════════
    //  5 · UNA RESERVA ATADA NO SE REATA
    // ══════════════════════════════════════════════════════════
    echo "
  ══ atar no es reatar ══
";
    $limpiar();
    $d6 = correr($M, $P, 'atar');
    ok('un trabajo distinto NO pisa al que ya estaba', ($d6['TRAS_INTRUSO'] ?? '') === 'resp_A',
       "quedo «{$d6['TRAS_INTRUSO']}» · si se pisa, el trabajo original queda "
       . 'inalcanzable: ni sondearlo ni cancelarlo');
    ok('reatar con el mismo id sigue valiendo', ($d6['TRAS_MISMO'] ?? '') === 'resp_A');
    //  El control negativo: si la guarda fuera «no atar nunca», esto saldria vacio.
    ok('una reserva sin atar si acepta el primero', ($d6['VIRGEN_ACEPTA'] ?? '') === 'resp_C');
    ok('y nada de esto toca la red', (int)($d6['SALIDAS_TOTAL'] ?? 9) === 0);

    // ══════════════════════════════════════════════════════════
    //  6 · EL WORKER NO REENCOLA UN CICLO CERRADO
    // ══════════════════════════════════════════════════════════
    echo "\n  ══ el worker, despues de un ciclo terminal ══\n";
    $d5 = correr($M, $P, 'worker');
    foreach (['terminal_fb' => 'el proveedor confirmo el fallo',
              'terminal_fbx' => 'el respaldo ya corrio',
              'aparcado' => 'nos rendimos de preguntar',
              'encolo_incierto' => 'puede haber un trabajo invisible'] as $c => $porque) {
        ok("no reencola: {$porque}", ($d5['CICLO_' . $c] ?? '0') === '1');
    }
    //  Y la otra mitad: sin sello, el worker SI tiene que poder encolar. Si esto
    //  saliera cerrado, la guarda habria matado el camino normal.
    ok('pero SI encola cuando nunca hubo trabajo', ($d5['CICLO_nunca_hubo'] ?? '1') === '0');
    ok('y con una clase de espera tampoco estorba', ($d5['CICLO_esperando'] ?? '1') === '0');
    ok('sin fila, no decide nada', ($d5['CICLO_sin_fila'] ?? '1') === '0');
    ok('el worker usa ESTE predicado', ($d5['WORKER_USA_PREDICADO'] ?? '0') === '1',
       'si el worker se lleva su propia copia de la regla, esta prueba deja de '
       . 'hablar de lo que corre en produccion');

} finally {
    if ($M) {
        $pdo->prepare("DELETE FROM crecer_img_cuota_asiento WHERE marca_id=?")->execute([$M]);
        $pdo->prepare("DELETE FROM crecer_img_cuota_cubo WHERE marca_id=?")->execute([$M]);
        Fixture::limpiar($pdo, $M);
        echo "\n  (fixture limpiada)\n";
    }
}

echo "\n" . str_repeat('=', 66) . "\n";
echo $fallos === 0
    ? "  CONTRATO CUMPLIDO · {$n} afirmaciones\n\n"
    : "  {$fallos} de {$n} incumplidas — el respaldo todavia no respeta el contrato\n\n";
exit($fallos === 0 ? 0 : 1);
