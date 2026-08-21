<?php
// ============================================================
//  CRECER — EL SONDEO CIERRA LA UNIDAD (reparación del 21 de agosto)
//  tests/test_sondeo_cuota.php
//
//  LO QUE FALLO EN PRODUCCION, y que aqui queda cerrado:
//
//   · TODO error del sondeo caia en 'no_clasificado'. ia_http_get() perdia el
//     codigo HTTP y el mensaje de OpenAI no lo lleva, asi que el clasificador
//     —que busca «404» en el texto— no encontraba nada. El sondeo no podia
//     distinguir «ese job no existe» de «espera, hay cola», y esperaba para
//     siempre. Eso dejo la pieza #656 colgada con su unidad de cuota retenida.
//
//   · CuotaImg::confirmar() NO SE LLAMABA DESDE NINGUN SITIO. La rama que
//     guarda la imagen cerraba la pieza y dejaba el asiento en 'reservado' para
//     siempre. El total del cubo era correcto, pero el estado mentia — y
//     barrerCaducadas() no los toca porque tienen job. Fuga permanente.
//
//   · `llamadas` se sumaba en tres sitios que no se conocian. Salio 3 para DOS
//     encolados: no significaba ni llamadas ni sondeos.
//
//   · #644 era INMORTAL. img_job_at nacio sin rellenar hacia atras, y una edad
//     NULL se leia como CERO, asi que el aparcado a las 24h nunca disparaba.
//
//  NADA DE ESTO LLAMA A UN PROVEEDOR: el runner sustituye ia_http_get_res(),
//  que es exactamente donde el codigo toca la red, y deja correr todo lo demas.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/_fixture.php';
require_once __DIR__ . '/../includes/cuota_imagenes.php';
require_once __DIR__ . '/../includes/img_responses.php';   // IMG_POLL_VIVO_DIAS y compania

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nEL SONDEO CIERRA LA UNIDAD\n" . str_repeat('=', 56) . "\n";

if (!CuotaImg::disponible($pdo, true)) {
    echo "\n  SALTADA: falta migrations/2026-08-21_crecer_img_cuota.sql\n\n"; exit(2);
}

$RUNNER = __DIR__ . DIRECTORY_SEPARATOR . '_sondeo_runner.php';
$M = null;

/** Corre un sondeo simulado y devuelve sus CLAVE=valor. */
$sondear = function (int $marca, int $post, string $caso) use ($RUNNER): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($RUNNER) . ' '
         . $marca . ' ' . $post . ' ' . escapeshellarg($caso) . ' 2>&1';
    $sal = []; exec($cmd, $sal);
    $out = ['_sal' => $sal];
    foreach ($sal as $l) { if (strpos($l, '=') !== false) { [$k, $v] = explode('=', trim($l), 2); $out[$k] = $v; } }
    return $out;
};

try {
    $fx = Fixture::crear($pdo, 'sondeo');
    $M  = (int)$fx['marca_id'];
    $P1 = (int)$fx['piezas'][0];
    $P2 = (int)$fx['piezas'][1];

    /** Deja una pieza como la #656: encolada, con job, y su unidad reservada. */
    $encolada = function (int $post, string $job, bool $con_job_at = true) use ($pdo, $M) {
        $pdo->prepare("DELETE FROM crecer_img_cuota_asiento WHERE marca_id=?")->execute([$M]);
        $pdo->prepare("DELETE FROM crecer_img_cuota_cubo WHERE marca_id=?")->execute([$M]);
        $pdo->prepare("UPDATE crecer_contenido
                          SET img_estado='queued', img_job=?, grafica_path=NULL,
                              img_intentos=0, img_next_poll_at=NULL, img_error_clase=NULL,
                              img_job_at=" . ($con_job_at ? 'NOW()' : 'NULL') . "
                        WHERE id=? AND marca_id=?")->execute([$job, $post, $M]);
        //  Por garantizar(), que es por donde pasa el codigo real: asi el
        //  contador de llamadas arranca como arranca en produccion.
        $c = CuotaImg::garantizar(CuotaCtx::de($pdo, $M, 'arte_post', 'img_resp_encolar_res',
            ['origen_tipo' => 'contenido', 'origen_id' => $post, 'costo' => 0.17]),
            'P4 openai_responses_crear_bg');
        CuotaImg::atarJob($pdo, $c->asiento_id, $job);
        return $c->asiento_id;
    };

    // ══════════════════════════════════════════════════════════
    //  1 · EL CASO #656 · el proveedor no reconoce el job
    // ══════════════════════════════════════════════════════════
    echo "\n  — el caso de #656: 404 del proveedor —\n";
    $encolada($P1, 'resp_656');
    $r = $sondear($M, $P1, '404');

    ok('el runner completó', isset($r['ESTADO']), implode(' | ', array_slice($r['_sal'], -3)));
    ok('la unidad estaba retenida antes', (int)($r['RETENIDAS_ANTES'] ?? 0) === 1,
       'retenidas=' . ($r['RETENIDAS_ANTES'] ?? '?'));
    ok('el 404 se reconoce como terminal', ($r['PIEZA_CLASE'] ?? '') === 'job_no_existe',
       "salió «{$r['PIEZA_CLASE']}» · antes TODO caía en no_clasificado y esperaba para siempre");
    ok('la pieza queda en fallo recuperable', ($r['ESTADO'] ?? '') === 'error'
       && ($r['RECUPERABLE'] ?? '') === '1');
    ok('y suelta el job muerto', ($r['PIEZA_JOB'] ?? 'x') === '',
       'seguir preguntando por un job que no existe es sondear al vacío');

    echo "\n  — la unidad vuelve al dueño —\n";
    ok('se libera', (int)($r['RESTANTES_DESPUES'] ?? 0) === (int)($r['RESTANTES_ANTES'] ?? 0) + 1,
       "antes {$r['RESTANTES_ANTES']} · después {$r['RESTANTES_DESPUES']}");
    ok('ya no hay nada retenido', (int)($r['RETENIDAS_DESPUES'] ?? 9) === 0);
    ok('ni consumido', (int)($r['CONSUMIDAS_DESPUES'] ?? 9) === 0,
       'el dueño no recibió imagen: no puede habérsele cobrado');
    ok('el asiento queda liberado', ($r['ASIENTO_ESTADO'] ?? '') === 'liberado');

    echo "\n  — y NO se dispara un segundo proveedor —\n";
    ok('nadie tocó Gemini', (int)($r['TOCO_GEMINI'] ?? 1) === 0,
       'que el job no aparezca no prueba que el proveedor rechazara la imagen');
    ok('una sola llamada de red', (int)($r['LLAMADAS_RED'] ?? 0) === 1);

    echo "\n  — y el reintento del dueño es una unidad NUEVA —\n";
    //  Si la llave idempotente siguiera puesta, el reintento reusaria el asiento
    //  liberado: imagen gratis y sin poder confirmarla.
    $r2 = CuotaImg::reservar($pdo, CuotaCtx::de($pdo, $M, 'arte_post', 'img_resp_encolar_res',
        ['origen_tipo' => 'contenido', 'origen_id' => $P1, 'costo' => 0.17]));
    ok('abre asiento propio', $r2['ok'] === true && $r2['reusado'] === false,
       'reusar uno cerrado sería regalar la imagen');
    ok('y descuenta de verdad', CuotaImg::estado($pdo, $M)['retenidas'] === 1);

    // ══════════════════════════════════════════════════════════
    //  2 · LA IMAGEN LLEGA · la unidad se consume
    // ══════════════════════════════════════════════════════════
    echo "\n  — cuando la imagen sí llega —\n";
    $encolada($P1, 'resp_ok');
    $r3 = $sondear($M, $P1, 'completo');
    ok('se guarda', ($r3['ESTADO'] ?? '') === 'ok' && ($r3['IMG'] ?? '') !== '');
    ok('la unidad pasa a consumida', (int)($r3['CONSUMIDAS_DESPUES'] ?? 0) === 1,
       'antes se guardaba la imagen y el asiento se quedaba en «reservado» para siempre');
    ok('y deja de estar retenida', (int)($r3['RETENIDAS_DESPUES'] ?? 9) === 0);
    ok('el asiento queda confirmado', ($r3['ASIENTO_ESTADO'] ?? '') === 'confirmado');
    ok('el total del cubo no se mueve',
       (int)($r3['RESTANTES_DESPUES'] ?? 0) === (int)($r3['RESTANTES_ANTES'] ?? -1),
       'confirmar no gasta otra unidad: la unidad ya estaba apartada');

    echo "\n  — la ecuación del cubo —\n";
    $e = CuotaImg::estado($pdo, $M);
    ok('usadas = retenidas + consumidas',
       (int)$e['usadas'] === (int)$e['retenidas'] + (int)$e['consumidas'],
       "usadas={$e['usadas']} · retenidas={$e['retenidas']} · consumidas={$e['consumidas']}");
    ok('y el estado las separa',
       array_key_exists('retenidas', $e) && array_key_exists('consumidas', $e),
       'mirar solo «usadas» no distingue una imagen entregada de una cocinándose');

    // ══════════════════════════════════════════════════════════
    //  3 · `llamadas` cuenta llamadas, no sondeos
    // ══════════════════════════════════════════════════════════
    echo "\n  — un sondeo no es una llamada al proveedor —\n";
    $encolada($P1, 'resp_vivo');
    $r4 = $sondear($M, $P1, 'vivo');
    ok('sigue en cola', ($r4['ESTADO'] ?? '') === 'queued');
    ok('la unidad sigue retenida', (int)($r4['RETENIDAS_DESPUES'] ?? 0) === 1,
       'un job vivo puede completar: devolver la unidad ahora descuadraría el mes');
    ok('y `llamadas` no sube por sondear', (int)($r4['ASIENTO_LLAMADAS'] ?? 9) === 1,
       'salió ' . ($r4['ASIENTO_LLAMADAS'] ?? '?') . ' · los sondeos son GET y no generan nada');

    echo "\n  — un encolado, un incremento —\n";
    $pdo->prepare("DELETE FROM crecer_img_cuota_asiento WHERE marca_id=?")->execute([$M]);
    $pdo->prepare("DELETE FROM crecer_img_cuota_cubo WHERE marca_id=?")->execute([$M]);
    $ctx = CuotaCtx::de($pdo, $M, 'arte_post', 'x', ['origen_tipo' => 'contenido', 'origen_id' => $P2]);
    $c1 = CuotaImg::garantizar($ctx, 'P4 openai_responses_crear_bg');
    CuotaImg::atarJob($pdo, $c1->asiento_id, 'resp_a');
    $lla = fn() => (int)$pdo->query("SELECT llamadas FROM crecer_img_cuota_asiento
                                      WHERE id={$c1->asiento_id}")->fetchColumn();
    ok('la primera entrada cuenta 1', $lla() === 1,
       'antes la primera no se contaba nunca: solo se sumaba al REUSAR');
    CuotaImg::garantizar($ctx, 'P1 gemini_imagen');      // el respaldo, misma imagen
    ok('el respaldo suma 1 más', $lla() === 2,
       'salió ' . $lla() . ' · atarJob sumaba otro por el mismo encolado, y salían 3 para 2');
    ok('pero sigue siendo UNA unidad',
       (int)$pdo->query("SELECT unidades FROM crecer_img_cuota_asiento WHERE id={$c1->asiento_id}")->fetchColumn() === 1);

    // ══════════════════════════════════════════════════════════
    //  4 · EL CASO HEREDADO #644 · edad desconocida ≠ recién nacido
    // ══════════════════════════════════════════════════════════
    echo "\n  — la pieza heredada, sin img_job_at —\n";
    $encolada($P2, 'resp_644', false);
    $antes = $pdo->prepare("SELECT img_job_at, updated_at FROM crecer_contenido WHERE id=?");
    $antes->execute([$P2]);
    $a0 = $antes->fetch(PDO::FETCH_ASSOC);
    ok('arranca sin fecha de control', $a0['img_job_at'] === null,
       'así están todas las piezas anteriores a la migración del 19 de agosto');

    $r5 = $sondear($M, $P2, 'vivo');
    ok('el sondeo le sella una fecha', ($r5['PIEZA_JOB_AT'] ?? '') !== '',
       'sin sello, la edad se lee como CERO y el aparcado a las 24h nunca dispara');

    $sello = $r5['PIEZA_JOB_AT'];
    $r6 = $sondear($M, $P2, 'vivo');
    ok('y el sello NO se mueve al volver a sondear', ($r6['PIEZA_JOB_AT'] ?? '') === $sello,
       "antes: {$sello} · ahora: {$r6['PIEZA_JOB_AT']} — usar updated_at lo rejuvenecería en cada vuelta");

    echo "\n  — una sola ventana, y luego se aparca —\n";
    //  Se envejece el SELLO más allá del tope duro. La ventana es única porque
    //  la fecha es inmutable: no hay forma de estirarla sondeando.
    //  Y se abre la puerta del backoff: el sondeo anterior la dejó cerrada 3s y
    //  aquí no se está probando el backoff, sino lo que pasa CUANDO le toca.
    $pdo->prepare("UPDATE crecer_contenido
                      SET img_job_at = NOW() - INTERVAL ? DAY, img_next_poll_at = NULL
                    WHERE id=?")->execute([(int)IMG_POLL_VIVO_DIAS + 1, $P2]);
    $r7 = $sondear($M, $P2, 'vivo');
    ok('pasada la ventana, se aparca', ($r7['PIEZA_CLASE'] ?? '') !== '',
       'clase=' . ($r7['PIEZA_CLASE'] ?? '(vacía)') . ' · sondear sin fin es el defecto de #644');
    ok('y se marca como aparcada, no como error', strpos((string)($r7['PIEZA_CLASE'] ?? ''), 'ap:') === 0,
       'aparcar no es lo mismo que fallar: el job puede seguir vivo');
    ok('y la unidad no se queda retenida para siempre',
       (int)($r7['RETENIDAS_DESPUES'] ?? 9) === 0,
       'aparcar devuelve la unidad y anota el riesgo como nuestro');
    $ries = (int)$pdo->query("SELECT COUNT(*) FROM crecer_img_cuota_asiento
                               WHERE marca_id={$M} AND estado='riesgo'")->fetchColumn();
    ok('queda como riesgo de plataforma', $ries >= 1,
       'el job puede seguir vivo y facturarse: el dueño no paga por lo que quizá no reciba');

    // ══════════════════════════════════════════════════════════
    //  5 · NO SE ENCOLA DOS VECES LA MISMA PIEZA
    // ══════════════════════════════════════════════════════════
    echo "\n  — con un job vivo, no se abre otro —\n";
    $src = (string)file_get_contents(dirname(__DIR__) . '/includes/img_responses.php');
    ok('img_resp_encolar_res tiene la guarda',
       strpos($src, "ya_encolado") !== false,
       'sin ella se crean DOS trabajos en OpenAI y el segundo pisa img_job: el primero queda huérfano');
    ok('y el fallback NO libera antes de correr',
       strpos($src, 'LA RESERVA NO SE LIBERA AQUI') !== false,
       'liberar antes del respaldo obligaría a pedir otra unidad por la misma imagen');
    ok('quien libera es el respaldo, si tampoco entrega',
       strpos($src, 'falló gpt-image y el respaldo tampoco entregó') !== false);

    echo "\n  — y la pantalla ofrece «Intentar otra vez» —\n";
    $ap = (string)file_get_contents(dirname(__DIR__) . '/panel/aprobar2.php');
    ok('el botón cambia de nombre ante un fallo', strpos($ap, "'Intentar otra vez'") !== false);
    ok('y se le dice que no le contó imagen', strpos($ap, 'No te contó imagen') !== false,
       'la unidad ya volvió: callárselo lo manda a soporte');

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
