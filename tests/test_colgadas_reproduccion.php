<?php
// ============================================================
//  CRECER — REPRODUCCION EXACTA DEL SINTOMA DE #644 Y #656
//  tests/test_colgadas_reproduccion.php
//
//  NO ES UNA PRUEBA DE QUE ALGO FUNCIONE. Es lo contrario: fija por escrito
//  DOS DEFECTOS que siguen vivos despues del commit 25cc3c5, para poder hablar
//  de ellos con hechos en vez de con hipotesis. Cuando se arreglen, estas
//  afirmaciones se invierten y esta cabecera se reescribe.
//
//  SINTOMA A · img_job_at SE QUEDA NULL
//    El sello de la R5 esta DESPUES de img_poll_tomar_lease(). Con la puerta
//    del backoff cerrada, img_resp_completar() se va en el lease y no llega
//    nunca al sello. Y #644 lleva 35 intentos: su backoff es de 60 minutos, o
//    sea que casi siempre esta cerrada. El sello prometido no se puede alcanzar.
//
//  SINTOMA B · no_clasificado CON EL CODIGO NUEVO
//    La R1 metio el codigo HTTP en el mensaje, pero img_poll_clase_error()
//    sigue clasificando por TEXTO y solo mira 400, 401, 403, 404, 429 y 5xx.
//    Un 200-con-error o un 409 no casan con ninguno y caen igual en
//    'no_clasificado'. Y como no son 404, tampoco disparan 'soltar': la unidad
//    se queda retenida exactamente igual que antes.
//
//  Ninguna llamada real: el runner sustituye ia_http_get_res(), el borde de red.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/_fixture.php';
require_once __DIR__ . '/../includes/cuota_imagenes.php';
require_once __DIR__ . '/../includes/img_responses.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nREPRODUCCION DEL SINTOMA (defectos vivos)\n" . str_repeat('=', 56) . "\n";

if (!CuotaImg::disponible($pdo, true)) {
    echo "\n  SALTADA: falta la migracion de la cuota\n\n"; exit(2);
}

$RUNNER = __DIR__ . DIRECTORY_SEPARATOR . '_sondeo_runner.php';
$sondear = function (int $marca, int $post, string $caso) use ($RUNNER): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($RUNNER) . ' '
         . $marca . ' ' . $post . ' ' . escapeshellarg($caso) . ' 2>&1';
    $sal = []; exec($cmd, $sal);
    $out = ['_sal' => $sal];
    foreach ($sal as $l) { if (strpos($l, '=') !== false) { [$k, $v] = explode('=', trim($l), 2); $out[$k] = $v; } }
    return $out;
};

$M = null;
try {
    $fx = Fixture::crear($pdo, 'colgadas');
    $M  = (int)$fx['marca_id'];
    $P  = (int)$fx['piezas'][0];

    $sembrar = function (int $post, string $job, ?string $job_at, ?string $next_poll, int $intentos)
               use ($pdo, $M) {
        $pdo->prepare("DELETE FROM crecer_img_cuota_asiento WHERE marca_id=?")->execute([$M]);
        $pdo->prepare("DELETE FROM crecer_img_cuota_cubo WHERE marca_id=?")->execute([$M]);
        $pdo->prepare("UPDATE crecer_contenido
                          SET img_estado='queued', img_job=?, grafica_path=NULL,
                              img_intentos=?, img_error_clase=NULL,
                              img_job_at={$job_at}, img_next_poll_at={$next_poll}
                        WHERE id=? AND marca_id=?")->execute([$job, $intentos, $post, $M]);
        $c = CuotaImg::garantizar(CuotaCtx::de($pdo, $M, 'arte_post', 'img_resp_encolar_res',
            ['origen_tipo' => 'contenido', 'origen_id' => $post, 'costo' => 0.17]),
            'P4 openai_responses_crear_bg');
        CuotaImg::atarJob($pdo, $c->asiento_id, $job);
    };
    $pieza = function (int $post) use ($pdo, $M): array {
        $q = $pdo->prepare("SELECT img_job_at, img_intentos, img_error_clase, img_next_poll_at,
                                   NOW() AS ahora FROM crecer_contenido WHERE id=? AND marca_id=?");
        $q->execute([$post, $M]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: [];
    };

    // ══════════════════════════════════════════════════════════
    //  SINTOMA A · el sello no se alcanza con la puerta cerrada
    //             (el caso literal de #644)
    // ══════════════════════════════════════════════════════════
    echo "\n  — #644: sin fecha de control y con la puerta cerrada —\n";
    //  35 intentos y el proximo sondeo a media hora vista: exactamente la fila
    //  que se ve en produccion.
    $sembrar($P, 'resp_644', 'NULL', 'DATE_ADD(NOW(), INTERVAL 30 MINUTE)', 35);
    $a0 = $pieza($P);
    ok('arranca sin fecha de control', $a0['img_job_at'] === null);
    ok('y con la puerta del backoff cerrada', $a0['img_next_poll_at'] > $a0['ahora'],
       "next_poll={$a0['img_next_poll_at']} · ahora={$a0['ahora']}");

    $r = $sondear($M, $P, 'vivo');
    $a1 = $pieza($P);
    ok('el sondeo se va en el lease', ($r['ESTADO'] ?? '') === 'queued');
    //  ESTE ES EL DEFECTO. Se afirma que ocurre, no que este bien.
    ok('DEFECTO VIVO: la fecha de control sigue sin sellarse', $a1['img_job_at'] === null,
       'si esto se pusiera verde al reves, el defecto estaria arreglado');
    ok('y los intentos tampoco suben', (int)$a1['img_intentos'] === 35,
       'el lease devuelve antes de decidir, asi que no hay sondeo que contar');
    echo "    >> el sello de la R5 vive DESPUES del lease. Con 35 intentos el\n";
    echo "       backoff es de 60 min, asi que la puerta casi nunca esta abierta\n";
    echo "       y la fecha no se alcanza. En produccion lleva asi desde el 19.\n";

    echo "\n  — con la puerta abierta sí se sella —\n";
    $pdo->prepare("UPDATE crecer_contenido SET img_next_poll_at=NULL WHERE id=?")->execute([$P]);
    $sondear($M, $P, 'vivo');
    $a2 = $pieza($P);
    ok('el sello llega cuando el sondeo entra de verdad', $a2['img_job_at'] !== null,
       'lo cual confirma que el codigo del sello funciona: lo que falla es DONDE esta');

    // ══════════════════════════════════════════════════════════
    //  SINTOMA B · no_clasificado con el codigo nuevo
    //             (el caso literal de #656)
    // ══════════════════════════════════════════════════════════
    echo "\n  — #656: el proveedor contesta algo que no es 404 —\n";
    foreach ([
        'error200' => 'HTTP 200 con el fallo dentro del cuerpo',
        'error409' => 'HTTP 409, un codigo que el clasificador no mira',
    ] as $caso => $desc) {
        echo "\n    · {$desc}\n";
        $sembrar($P, 'resp_656_' . $caso, 'NOW()', 'NULL', 0);
        $r2 = $sondear($M, $P, $caso);
        $b  = $pieza($P);
        ok("  DEFECTO VIVO: cae en no_clasificado", ($b['img_error_clase'] ?? '') === 'no_clasificado',
           "salio «{$b['img_error_clase']}» · la R1 metio el codigo en el MENSAJE, pero "
           . 'img_poll_clase_error sigue leyendo texto y solo mira 400/401/403/404/429/5xx');
        ok('  la pieza se queda en cola', ($r2['ESTADO'] ?? '') === 'queued');
        ok('  y la unidad sigue retenida', (int)($r2['RETENIDAS_DESPUES'] ?? 0) === 1,
           'no es 404, asi que no dispara soltar: la unidad no vuelve');
        ok('  al menos no se dispara otro proveedor', (int)($r2['TOCO_GEMINI'] ?? 1) === 0);
        ok('  ni se crea otro asiento',
           (int)$pdo->query("SELECT COUNT(*) FROM crecer_img_cuota_asiento WHERE marca_id={$M}")->fetchColumn() === 1,
           'esto SI quedo bien: no hubo cobro nuevo');
    }

    echo "\n  — y el 404 de verdad sí se resuelve —\n";
    //  Para que quede claro que lo que falla es el ALCANCE de la R2, no la R2.
    $sembrar($P, 'resp_404', 'NOW()', 'NULL', 0);
    $r3 = $sondear($M, $P, '404');
    ok('con 404 sí suelta', ($r3['PIEZA_CLASE'] ?? '') === 'job_no_existe');
    ok('y devuelve la unidad', (int)($r3['RETENIDAS_DESPUES'] ?? 9) === 0);
    echo "    >> la R2 funciona. El problema es que solo reconoce UN codigo, y\n";
    echo "       el proveedor esta contestando otra cosa.\n";

    // ══════════════════════════════════════════════════════════
    //  LO QUE SI QUEDO BIEN
    // ══════════════════════════════════════════════════════════
    echo "\n  — sin cobros nuevos —\n";
    $tot = (int)$pdo->query("SELECT COUNT(*) FROM crecer_img_cuota_asiento WHERE marca_id={$M}")->fetchColumn();
    ok('un solo asiento en toda la corrida', $tot === 1,
       'coincide con produccion: no se creo otro asiento ni hubo otro cobro');

} finally {
    if ($M) {
        $pdo->prepare("DELETE FROM crecer_img_cuota_asiento WHERE marca_id=?")->execute([$M]);
        $pdo->prepare("DELETE FROM crecer_img_cuota_cubo WHERE marca_id=?")->execute([$M]);
        Fixture::limpiar($pdo, $M);
        echo "\n  (fixture limpiada)\n";
    }
}

echo "\n" . str_repeat('=', 56) . "\n";
echo $fallos === 0
    ? "  REPRODUCIDO · {$n} afirmaciones\n  (verde aqui = los defectos SIGUEN VIVOS y estan fijados por escrito)\n\n"
    : "  {$fallos} de {$n} no reprodujeron — el sintoma cambio, hay que volver a mirar\n\n";
exit($fallos === 0 ? 0 : 1);
