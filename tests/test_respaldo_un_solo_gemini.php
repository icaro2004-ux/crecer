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
//      asiento #1 sigue reservado
//      llamadas       3 → 6
//      costo_usd   0.17 → 0.68        (+0.51 = tres entradas de 0.17)
//      provider_job_id → otro resp_...
//      cubo           1/40            (lo unico que salio bien)
//
//  ESTA PRUEBA AFIRMA EL CONTRATO, NO LA CONDUCTA ACTUAL. Hoy sale ROJA, y eso
//  es lo que demuestra el defecto. Se pone verde cuando el respaldo deje de
//  encolar otro trabajo de OpenAI. Ningun proveedor real: el runner sustituye
//  LOS DOS bordes de red.
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

$M = null;
try {
    $fx = Fixture::crear($pdo, 'respaldo');
    $M  = (int)$fx['marca_id'];
    $P  = (int)$fx['piezas'][0];

    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . '_respaldo_runner.php') . ' '
         . $M . ' ' . $P . ' 2>&1';
    $sal = []; exec($cmd, $sal);
    $d = [];
    foreach ($sal as $l) { if (strpos($l, '=') !== false) { [$k, $v] = explode('=', trim($l), 2); $d[$k] = $v; } }

    if (!isset($d['ASIENTO'])) {
        echo "  el runner no llego a arrancar:\n";
        foreach (array_slice($sal, -20) as $l) echo "    $l\n";
        $fallos++; $n++;
    } else {
        echo "\n  — el sondeo cierra el trabajo A —\n";
        ok('el proveedor confirma y la pieza suelta el job',
           ($d['TRAS_SONDEO_JOB'] ?? 'x') === '');
        ok('y queda el permiso de UN respaldo',
           strpos((string)($d['TRAS_SONDEO_CLASE'] ?? ''), 'fb:') === 0,
           "clase=«{$d['TRAS_SONDEO_CLASE']}»");

        echo "\n  — el respaldo: JAMAS otro trabajo de OpenAI —\n";
        //  El sensor. El transporte falso reparte un id nuevo cada vez que
        //  alguien hace POST a /v1/responses; si aparece alguno, es que se
        //  encolo otra generacion sobre la misma unidad.
        ok('no se encolo ningun trabajo B', (int)($d['N_JOBS_NUEVOS'] ?? 0) === 0,
           "se repartieron: «{$d['JOBS_NUEVOS']}»
         quien lo pidio: {$d['PILA_B']}");
        ok('el libro sigue apuntando al trabajo A',
           ($d['PROVIDER_JOB_DESPUES'] ?? '') === 'resp_A',
           "provider_job_id=«{$d['PROVIDER_JOB_DESPUES']}» · atarJob() lo sobrescribe "
           . 'sin condicion, asi que el trabajo A se vuelve inalcanzable: ya no se '
           . 'puede sondear ni cancelar');
        ok('no se pidio nada al motor viejo de OpenAI',
           (int)($d['POST_OPENAI_IMG'] ?? 0) === 0);

        echo "\n  — un solo respaldo, y es Gemini —\n";
        ok('Gemini se llamo exactamente una vez', (int)($d['POST_GEMINI_IMG'] ?? 0) === 1,
           "peticiones de imagen a Gemini: {$d['POST_GEMINI_IMG']}");
        ok('el respaldo entrego imagen', ($d['PIEZA_GRAFICA'] ?? '') !== '');

        echo "\n  — el libro cuadra —\n";
        ok('un solo asiento', (int)($d['ASIENTOS_TOTAL'] ?? 0) === 1);
        ok('la unidad se cierra al entregar',
           ($d['ASIENTO_ESTADO'] ?? '') === 'confirmado',
           "estado=«{$d['ASIENTO_ESTADO']}» · si el rescate entrega y nadie confirma, "
           . 'la reserva se queda abierta para siempre — y barrerCaducadas() la salta '
           . 'porque tiene job');
        //  Una entrada mas que la del encolado: la del respaldo. Ni una mas.
        ok('llamadas sube exactamente 1', (int)($d['LLAMADAS_DESPUES'] ?? 0)
                                        === (int)($d['LLAMADAS_ANTES'] ?? 0) + 1,
           "antes={$d['LLAMADAS_ANTES']} · despues={$d['LLAMADAS_DESPUES']} · "
           . 'cada entrada a un punto de proveedor suma una');

        echo "\n  — la traza medida —\n";
        foreach (['LLAMADAS_ANTES','LLAMADAS_DESPUES','COSTO_ANTES','COSTO_DESPUES',
                  'PROVIDER_JOB_DESPUES','ASIENTO_ESTADO','POST_RESPONSES',
                  'POST_OPENAI_IMG','POST_GEMINI_IMG','POST_GEMINI_TXT','SALIDAS_TOTAL'] as $k) {
            printf("    %-22s %s\n", $k, $d[$k] ?? '(sin dato)');
        }
    }

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
