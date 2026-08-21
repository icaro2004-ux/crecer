<?php
// ============================================================
//  CRECER — SE ACABO EL CREDITO: TERMINAL, UN RESPALDO, Y SE ACABO
//  tests/test_credito_agotado.php
//
//  EL NUDO DEL INCIDENTE, y llevaba ahi desde antes de la R1.
//
//  Una respuesta de fondo que fracasa vuelve con HTTP 200, `status: failed` Y un
//  campo `error` DENTRO DEL MISMO CUERPO. openai_responses_estado() lanzaba en
//  cuanto veia `error`, asi que el `failed` NUNCA llegaba a img_poll_decidir:
//  se convertia en excepcion, el status quedaba null, y la pieza caia en «no se
//  pudo consultar» — que no es terminal y por tanto se reintenta para siempre.
//  Un trabajo muerto sondeado sin fin, y una unidad de cuota retenida con el.
//
//  Ahora: si el cuerpo trae STATUS, la consulta funciono y decide el de arriba.
//
//  Y con el credito agotado ademas hay que ser exactos, porque el 10 de agosto
//  se gastaron $17.31 en un dia: terminal SI, respaldo SI pero UNO SOLO, y la
//  misma reserva — no una segunda unidad por la misma imagen.
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

echo "\nCREDITO AGOTADO Y EL RESPALDO UNICO\n" . str_repeat('=', 56) . "\n";

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
    $fx = Fixture::crear($pdo, 'credito');
    $M  = (int)$fx['marca_id'];
    $P  = (int)$fx['piezas'][0];

    $sembrar = function (int $post, string $job, ?string $grafica = null, int $intentos = 0)
               use ($pdo, $M) {
        $pdo->prepare("DELETE FROM crecer_img_cuota_asiento WHERE marca_id=?")->execute([$M]);
        $pdo->prepare("DELETE FROM crecer_img_cuota_cubo WHERE marca_id=?")->execute([$M]);
        $pdo->prepare("UPDATE crecer_contenido
                          SET img_estado='queued', img_job=?, grafica_path=?,
                              img_intentos=?, img_error_clase=NULL,
                              img_job_at=NOW(), img_next_poll_at=NULL
                        WHERE id=? AND marca_id=?")->execute([$job, $grafica, $intentos, $post, $M]);
        $c = CuotaImg::garantizar(CuotaCtx::de($pdo, $M, 'arte_post', 'img_resp_encolar_res',
            ['origen_tipo' => 'contenido', 'origen_id' => $post, 'costo' => 0.17]),
            'P4 openai_responses_crear_bg');
        CuotaImg::atarJob($pdo, $c->asiento_id, $job);
        return $c->asiento_id;
    };
    $pieza = function (int $post) use ($pdo, $M): array {
        $q = $pdo->prepare("SELECT img_estado, img_job, img_error_clase, img_intentos, grafica_path
                              FROM crecer_contenido WHERE id=? AND marca_id=?");
        $q->execute([$post, $M]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: [];
    };

    // ══════════════════════════════════════════════════════════
    //  1 · failed LLEGA A DECIDIR (era el nudo)
    // ══════════════════════════════════════════════════════════
    echo "\n  — un `failed` con su error dentro ya no es una excepción —\n";
    $sembrar($P, 'resp_credito');
    $r = $sondear($M, $P, 'sin_credito');
    ok('el runner completó', isset($r['ESTADO']), implode(' | ', array_slice($r['_sal'], -3)));
    ok('NO cae en no_clasificado', ($r['PIEZA_CLASE'] ?? '') !== 'no_clasificado',
       "salió «{$r['PIEZA_CLASE']}» · antes el failed se volvía excepción y la pieza "
       . 'quedaba en «no se pudo consultar», que se reintenta para siempre');
    ok('se reconoce como crédito agotado',
       strpos((string)($r['PIEZA_CLASE'] ?? ''), 'sin_credito') !== false,
       "clase=«{$r['PIEZA_CLASE']}»");

    echo "\n  — y deja de sondear ese job —\n";
    ok('la pieza sale de la cola', ($r['ESTADO'] ?? '') === 'error');
    ok('y suelta el job', ($r['PIEZA_JOB'] ?? 'x') === '',
       'sin soltarlo se seguiría preguntando por un trabajo que no va a salir');

    echo "\n  — el respaldo queda autorizado, y solo una vez —\n";
    $b = $pieza($P);
    ok('la pieza queda marcada con el permiso',
       strpos((string)$b['img_error_clase'], 'fb:') === 0,
       "clase=«{$b['img_error_clase']}»");

    // ══════════════════════════════════════════════════════════
    //  2 · UN RESPALDO, LA MISMA RESERVA
    // ══════════════════════════════════════════════════════════
    echo "\n  — el respaldo consume su permiso al entrar —\n";
    //  POR EL RUNNER, SIEMPRE. Llamar a img_gemini_fallback() desde aquí lo
    //  haría con las credenciales DE VERDAD: este proceso carga db.php y con él
    //  config.local.php. Ya pasó una vez —dos imágenes reales de Gemini, $0.268—
    //  y por eso el respaldo solo se ejercita en un proceso con _sin_gasto.php,
    //  donde las llaves van en blanco y nada puede salir a la red.
    $antes = (int)$pdo->query("SELECT COUNT(*) FROM crecer_img_cuota_asiento WHERE marca_id={$M}")->fetchColumn();
    $f1 = $sondear($M, $P, 'fallback');
    ok('el permiso pasa a usado', strpos((string)($f1['FB_CLASE'] ?? ''), 'fbx:') === 0,
       "clase=«{$f1['FB_CLASE']}» · el sello va en el MISMO UPDATE que lo comprueba");
    ok('y no se abrió otro asiento', (int)($f1['FB_ASIENTOS'] ?? -1) === $antes,
       'la misma imagen reusa su reserva: una unidad, no dos');

    echo "\n  — y un segundo respaldo NO entra —\n";
    $f2 = $sondear($M, $P, 'fallback');
    ok('se niega sin permiso', (string)($f2['FB_URL'] ?? 'x') === '',
       'sin esta puerta, cada pasada del barrido volvía a pedirle una imagen a Gemini');
    ok('y sigue sin abrir asientos', (int)($f2['FB_ASIENTOS'] ?? -1) === $antes,
       'aquí es donde 148 generaciones se convierten en 148 facturas');

    // ══════════════════════════════════════════════════════════
    //  3 · OTROS `failed` SIGUEN FUNCIONANDO
    // ══════════════════════════════════════════════════════════
    echo "\n  — un failed por otra razón también es terminal —\n";
    $sembrar($P, 'resp_filtro');
    $r2 = $sondear($M, $P, 'fallo_otro');
    ok('sale de la cola', ($r2['ESTADO'] ?? '') === 'error');
    ok('con su clase propia, distinta del crédito',
       strpos((string)($r2['PIEZA_CLASE'] ?? ''), 'proveedor_failed') !== false,
       "clase=«{$r2['PIEZA_CLASE']}» · distinguirlas deja contarle al dueño lo que pasó");

    // ══════════════════════════════════════════════════════════
    //  4 · #640 · CON ARTE, SE CIERRA SIN PROVEEDOR
    // ══════════════════════════════════════════════════════════
    echo "\n  — la pieza que ya tiene arte no le pregunta a nadie —\n";
    $sembrar($P, 'resp_640', '/crecer/uploads/marca_x/graficas/ya_estaba.png');
    $r3 = $sondear($M, $P, 'vivo');
    ok('se cierra en ok', ($r3['ESTADO'] ?? '') === 'ok');
    ok('conserva su arte', ($r3['IMG'] ?? '') === '/crecer/uploads/marca_x/graficas/ya_estaba.png');
    ok('sin llamar a nadie', (int)($r3['LLAMADAS_RED'] ?? 9) === 0,
       'llamadas=' . ($r3['LLAMADAS_RED'] ?? '?') . ' · el job era huérfano, la imagen ya estaba');
    ok('suelta el job', (string)($r3['PIEZA_JOB'] ?? 'x') === '');
    ok('y cierra su unidad', (int)($r3['CONSUMIDAS_DESPUES'] ?? 0) === 1,
       'si no, la unidad se queda retenida para siempre por un job que sobra');
    ok('sin quedar nada retenido', (int)($r3['RETENIDAS_DESPUES'] ?? 9) === 0);

    // ══════════════════════════════════════════════════════════
    //  5 · EL SELLO, AHORA ANTES DEL LEASE
    // ══════════════════════════════════════════════════════════
    echo "\n  — el sello se alcanza aunque la puerta esté cerrada —\n";
    $sembrar($P, 'resp_sello');
    $pdo->prepare("UPDATE crecer_contenido
                      SET img_job_at=NULL, img_intentos=35,
                          img_next_poll_at=DATE_ADD(NOW(), INTERVAL 30 MINUTE)
                    WHERE id=? AND marca_id=?")->execute([$P, $M]);
    $r4 = $sondear($M, $P, 'vivo');
    ok('el sondeo se va en el lease', ($r4['ESTADO'] ?? '') === 'queued');
    ok('PERO la fecha de control ya quedó sellada', ($r4['PIEZA_JOB_AT'] ?? '') !== '',
       'era el defecto de #644: el sello estaba detrás del lease y con 35 intentos '
       . 'el backoff de 60 min lo hacía inalcanzable');
    $sello = $r4['PIEZA_JOB_AT'];
    $r5 = $sondear($M, $P, 'vivo');
    ok('y no se mueve al volver a sondear', ($r5['PIEZA_JOB_AT'] ?? '') === $sello,
       'la guarda IS NULL la hace inmutable: sondear no la rejuvenece');

    // ══════════════════════════════════════════════════════════
    //  6 · EL TOPE ABSOLUTO
    // ══════════════════════════════════════════════════════════
    echo "\n  — ninguna pieza vuelve a llegar a 35 sondeos —\n";
    ok('hay un tope declarado', defined('IMG_POLL_INTENTOS_MAX'));
    ok('y es menor que los 35 que se vieron', (int)IMG_POLL_INTENTOS_MAX < 35,
       'tope=' . (int)IMG_POLL_INTENTOS_MAX);
    //  Se comprueba en la decisión pura, sin base ni red: es donde vive la regla.
    $d = img_poll_decidir(['intentos' => (int)IMG_POLL_INTENTOS_MAX, 'job_at' => null],
                          'in_progress', null, date('Y-m-d H:i:s'));
    ok('pasado el tope, se aparca', $d['accion'] === 'aparcar',
       "salió «{$d['accion']}»");
    ok('con su clase propia', $d['clase'] === 'tope_intentos');
    $d2 = img_poll_decidir(['intentos' => 2, 'job_at' => null],
                           'in_progress', null, date('Y-m-d H:i:s'));
    ok('y por debajo del tope sigue esperando', $d2['accion'] === 'esperar',
       'el tope es la red de seguridad, no la regla normal');

    echo "\n  — el crédito agotado se reconoce por código y por mensaje —\n";
    ok('credit_balance_exhausted', img_credito_agotado('credit_balance_exhausted'));
    ok('insufficient_quota', img_credito_agotado('insufficient_quota'));
    ok('billing_hard_limit_reached', img_credito_agotado('billing_hard_limit_reached'));
    ok('también si solo viene en el mensaje',
       img_credito_agotado(null, 'Responses(bg): 400 credit_balance_exhausted'));
    ok('y no confunde otros fallos', !img_credito_agotado('content_filter'));
    ok('ni un código vacío', !img_credito_agotado('', ''));

} finally {
    if ($M) {
        $pdo->prepare("DELETE FROM crecer_img_cuota_asiento WHERE marca_id=?")->execute([$M]);
        $pdo->prepare("DELETE FROM crecer_img_cuota_cubo WHERE marca_id=?")->execute([$M]);
        Fixture::limpiar($pdo, $M);
        echo "\n  (fixture limpiada)\n";
    }
}

echo "\n" . str_repeat('=', 56) . "\n";
echo $fallos === 0 ? "  TODO OK · {$n} pruebas\n\n" : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
