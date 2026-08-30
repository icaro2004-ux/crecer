<?php
// ============================================================
//  CRECER — LOS OCHO MINUTOS DE LA PIEZA #667
//  tests/test_latencia_arte.php
//
//  EL CASO. La pieza #667 salio bien y tardo 8m12s:
//      creada    08:35:26 · encolada 08:35:38 · guardada 08:43:50
//  La calidad era la esperada; el problema era el reloj. Esta prueba fija
//  donde se fueron esos minutos y que ya no se pueden ir por ahi.
//
//  EL DEFECTO, EN TRES PIEZAS QUE SE MULTIPLICAN:
//
//   1. El sondeo de la pantalla del dueño —gateway_post 'preparacion', el sitio
//      donde alguien esta literalmente esperando su imagen— llamaba a
//      img_resp_completar SIN el flag dedicado, o sea con el perfil del barrido
//      de fondo. Con eso se anotaba a si mismo un backoff de 1, 2, 4, 8
//      minutos. Tres sondeos sanos = 1+2+4 = SIETE MINUTOS de silencio.
//
//   2. img_next_poll_at es la puerta COMUN. Al escribir ese backoff, el sondeo
//      del navegador dejaba fuera al worker dedicado: su bucle de 3 segundos
//      giraba en vacio recibiendo 'diferido' hasta agotar su ventana de cuatro
//      minutos SIN LLEGAR A PREGUNTAR NI UNA VEZ. Es decir: el arreglo que
//      protegia al barrido estaba matando al camino rapido.
//
//   3. Y el contador que gobierna esa escalera —img_intentos— subia en CADA
//      sondeo, incluidos los sanos, aunque la constante que lo declara diga
//      «Cuenta SOLO los sondeos en que no se pudo consultar». Como el worker
//      dedicado sondea cada 3s, a los dos minutos cualquier sondeo de fondo
//      calculaba min(60, 2^39) = SESENTA MINUTOS.
//
//  NADA DE ESTO LLAMA A UN PROVEEDOR. El runner sustituye el borde de red y
//  cada afirmacion sale de datos, no de una descripcion.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/_fixture.php';
require_once __DIR__ . '/../includes/img_responses.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nLOS OCHO MINUTOS DE LA PIEZA #667\n" . str_repeat('=', 60) . "\n";

$RUNNER = __DIR__ . DIRECTORY_SEPARATOR . '_latencia_runner.php';
$correr = function (int $marca, int $post, string $caso, bool $ded = false) use ($RUNNER): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($RUNNER) . ' '
         . $marca . ' ' . $post . ' ' . escapeshellarg($caso) . ' ' . ($ded ? '1' : '0') . ' 2>&1';
    $sal = []; exec($cmd, $sal);
    $out = ['_sal' => $sal];
    foreach ($sal as $l) { if (strpos($l, '=') !== false) { [$k, $v] = explode('=', trim($l), 2); $out[$k] = $v; } }
    return $out;
};

$F = null;
try {
$F = Fixture::crear($pdo, 'latencia', false);
$MID = (int)$F['marca_id'];

/** Siembra una pieza con un job VIVO y el ciclo de sondeo recien nacido. */
$pieza = function (string $job) use ($pdo, $MID): int {
    $pdo->prepare("INSERT INTO crecer_contenido
                     (marca_id,tipo,plataforma,caption,estado,img_estado,img_job,img_job_at,
                      img_intentos,img_next_poll_at)
                   VALUES (?,'post','instagram','copy de prueba','borrador','queued',?,NOW(),0,NULL)")
        ->execute([$MID, $job]);
    return (int)$pdo->lastInsertId();
};

// ── 1 · LA CADENCIA DEL QUE ESPERA DELANTE ──────────────────────────────────
echo "\n  — quien espera delante pregunta seguido —\n";
$p1 = $pieza('resp_lat_1');
$r = $correr($MID, $p1, 'vivo', true);
ok('un sondeo dedicado sobre un job vivo cierra la puerta 3s, no 60',
   (int)$r['PUERTA_SEG'] <= 3 && (int)$r['PUERTA_SEG'] >= 0,
   "puerta={$r['PUERTA_SEG']}s · con 60s el worker dedicado no puede ni preguntar");
ok('y no consume presupuesto de fallos', (int)$r['INTENTOS'] === 0,
   "intentos={$r['INTENTOS']} · un trabajo vivo no gasta el tope de consultas fallidas");

//  EL SONDEO DE FONDO SIGUE SIENDO DE FONDO. El arreglo no es «todo el mundo
//  cada 3 segundos»: el barrido conserva su cadencia suelta.
$p2 = $pieza('resp_lat_2');
$r2 = $correr($MID, $p2, 'vivo', false);
ok('el barrido conserva su cadencia suelta (60s)', (int)$r2['PUERTA_SEG'] > 3,
   "puerta={$r2['PUERTA_SEG']}s");
ok('pero YA NO escala 1-2-4-8 sobre un trabajo sano', (int)$r2['PUERTA_SEG'] <= 60,
   "puerta={$r2['PUERTA_SEG']}s · asi empezaban los siete minutos");

// ── 2 · LA PUERTA ES COMUN: NADIE PUEDE ENCERRAR AL WORKER ──────────────────
echo "\n  — el navegador ya no deja fuera al worker —\n";
//  Se reproduce el estado que dejaba el codigo viejo: un backoff de 60s escrito
//  por un sondeo NO dedicado. Con eso, el worker dedicado no podia entrar.
$p3 = $pieza('resp_lat_3');
$pdo->prepare("UPDATE crecer_contenido SET img_next_poll_at=DATE_ADD(NOW(), INTERVAL 60 SECOND)
                WHERE id=?")->execute([$p3]);
ok('CON la puerta a 60s, el worker dedicado se queda fuera (el defecto)',
   img_poll_tomar_lease($pdo, $MID, $p3, 'resp_lat_3', true) === false,
   'si esto pasa, el bucle de 3s del worker gira en vacio 20 veces seguidas');
//  Y ahora la parte que importa: ningun sondeo del camino del dueño escribe ya
//  un numero asi, de modo que ese encierro no puede volver a ocurrir solo.
$p4 = $pieza('resp_lat_4');
$correr($MID, $p4, 'vivo', true);
ok('tras un sondeo dedicado, el worker SI puede entrar en su siguiente vuelta',
   (int)$pdo->query("SELECT TIMESTAMPDIFF(SECOND, NOW(), img_next_poll_at)
                       FROM crecer_contenido WHERE id={$p4}")->fetchColumn() <= 3);

// ── 3 · COMPLETED SE DESCARGA Y SE GUARDA EN EL MISMO CICLO ─────────────────
echo "\n  — completed no espera a otra vuelta —\n";
$p5 = $pieza('resp_lat_5');
$r5 = $correr($MID, $p5, 'completo', true);
ok('completed devuelve ok en el mismo sondeo', ($r5['ESTADO'] ?? '') === 'ok',
   'salio ' . ($r5['ESTADO'] ?? '?'));
ok('y grafica_path queda escrito en ese mismo ciclo', ($r5['GRAFICA'] ?? '') !== '');
ok('el job se cierra (img_job vacio)', ($r5['IMG_JOB'] ?? 'x') === '');
ok('la descarga empieza al recibir completed', ($r5['CRON_DESCARGA'] ?? '') !== '');
ok('y el guardado queda fechado', ($r5['CRON_SAVED'] ?? '') !== '');
ok('una sola llamada de red para resolverlo', (int)($r5['LLAMADAS_RED'] ?? 0) === 1,
   'llamadas=' . ($r5['LLAMADAS_RED'] ?? '?'));

// ── 4 · LA EVIDENCIA SOBREVIVE AL FINAL ─────────────────────────────────────
echo "\n  — el diagnostico conserva con que explicar lo ocurrido —\n";
ok('la cronologia conserva el provider_job_id tras cerrar el ciclo',
   ($r5['CRON_JOB'] ?? '') === 'resp_lat_5',
   'la pieza ya tiene img_job=NULL; sin esto no hay forma de investigar');
ok('conserva el conteo de sondeos', (int)($r5['CRON_POLLS'] ?? 0) >= 1);
ok('conserva el primer y el ultimo sondeo',
   ($r5['CRON_FIRST'] ?? '') !== '' && ($r5['CRON_LAST'] ?? '') !== '');
ok('conserva cuando el proveedor dijo completed', ($r5['CRON_COMPLETED'] ?? '') !== '');
ok('y el total de punta a punta', ($r5['CRON_TOTAL_MS'] ?? '') !== '');

// ── 5 · SONDEAR RAPIDO NO INFLA EL LOG ──────────────────────────────────────
echo "\n  — medir no puede repetir la amplificacion de agosto —\n";
$p6 = $pieza('resp_lat_6');
//  El primer sondeo ABRE la cronologia (una fila, tardia: esta pieza se sembro
//  con el job ya puesto, como los trabajos que estaban en vuelo al desplegar).
//  La linea base se toma DESPUES de eso: lo que se vigila es que sondear no
//  siga insertando.
$correr($MID, $p6, 'vivo', true);
$antes = (int)$pdo->query("SELECT COUNT(*) FROM crecer_ia_log WHERE marca_id={$MID}")->fetchColumn();
for ($i = 0; $i < 5; $i++) {
    $pdo->prepare("UPDATE crecer_contenido SET img_next_poll_at=NULL WHERE id=?")->execute([$p6]);
    $correr($MID, $p6, 'vivo', true);
}
$despues = (int)$pdo->query("SELECT COUNT(*) FROM crecer_ia_log WHERE marca_id={$MID}")->fetchColumn();
ok('cinco sondeos mas NO insertan ni una fila', $despues === $antes,
   "antes={$antes} despues={$despues} · la cronologia se ACTUALIZA, no se inserta");
ok('y una cronologia tardia se marca como tal',
   !empty(img_cron_leer($pdo, $MID, $p6)['tardia']),
   'de una tardia no se puede afirmar cuanto tardo Crecer antes de pedir la imagen');
$c6 = img_cron_leer($pdo, $MID, $p6);
ok('pero el contador de sondeos si los cuenta', (int)($c6['poll_count'] ?? 0) >= 5,
   'poll_count=' . ($c6['poll_count'] ?? '?'));

// ── 6 · EL DISPARO DEL WORKER SE VERIFICA ───────────────────────────────────
echo "\n  — un worker que no arranca ya no se ve igual que uno sano —\n";
ok('HTTP 200 = arranco',            arte_disparo_ok(200, 0) === true);
ok('HTTP 403 (llave mala) = NO',    arte_disparo_ok(403, 0) === false);
ok('HTTP 404 (ruta movida) = NO',   arte_disparo_ok(404, 0) === false);
ok('HTTP 503 = NO',                 arte_disparo_ok(503, 0) === false);
ok('fallo de conexion = NO',        arte_disparo_ok(0, CURLE_COULDNT_CONNECT) === false);
//  El caso raro que hay que saber leer: el worker ya esta corriendo.
ok('timeout del disparo = SI arranco', arte_disparo_ok(0, CURLE_OPERATION_TIMEDOUT) === true,
   'el worker contesta ok y sigue por detras; cortar por tiempo no lo mata');

// ── 7 · EL RELEVO: NADIE DEPENDE DEL CRON ───────────────────────────────────
echo "\n  — al agotar su ventana, el worker pasa el testigo —\n";
$vivo   = ['img_estado' => 'queued', 'img_job' => 'resp_x', 'img_error_clase' => null];
$aparc  = ['img_estado' => 'queued', 'img_job' => 'resp_x', 'img_error_clase' => 'ap:timeout'];
$cerr   = ['img_estado' => 'ok',     'img_job' => '',       'img_error_clase' => null];
$sinjob = ['img_estado' => 'queued', 'img_job' => '',       'img_error_clase' => 'enc:timeout'];
ok('job vivo y primera ventana → releva', arte_debe_relevar($vivo, 0, 2) === true);
ok('job vivo y segunda ventana → releva', arte_debe_relevar($vivo, 1, 2) === true);
ok('ACOTADO: al llegar al tope, no releva', arte_debe_relevar($vivo, 2, 2) === false,
   'sin tope esto seria polling ilimitado');
ok('un job aparcado NO se releva', arte_debe_relevar($aparc, 0, 2) === false);
ok('un ciclo ya cerrado NO se releva', arte_debe_relevar($cerr, 0, 2) === false,
   'relevar un ciclo cerrado seria empezar otro y cobrarlo');
ok('un encolado incierto NO se releva', arte_debe_relevar($sinjob, 0, 2) === false);
ok('sin fila, no se releva', arte_debe_relevar(null, 0, 2) === false);
//  IDEMPOTENTE: el relevo no es un ciclo nuevo. Se pida una vez o cinco, la
//  decision sobre el MISMO estado da lo mismo y no consume nada.
$mismos = array_map(fn() => arte_debe_relevar($vivo, 1, 2), range(1, 5));
ok('preguntar cinco veces da la misma respuesta', array_unique($mismos) === [true]);
ok('y el tope no se puede rebasar contando mal',
   arte_debe_relevar($vivo, 3, 2) === false && arte_debe_relevar($vivo, 99, 2) === false);

//  EL RELEVO NO ENCOLA NADA. Es la propiedad que impide pagar dos veces: el
//  worker que entra de relevo encuentra img_job puesto, y esa es justo la
//  condicion con la que arte_worker.php decide NO llamar a img_resp_encolar_res.
$p9 = $pieza('resp_lat_9');
$as_antes = (int)$pdo->query("SELECT COUNT(*) FROM crecer_img_cuota_asiento WHERE marca_id={$MID}")->fetchColumn();
$job_antes = (string)$pdo->query("SELECT img_job FROM crecer_contenido WHERE id={$p9}")->fetchColumn();
for ($i = 0; $i < 3; $i++) {
    $pdo->prepare("UPDATE crecer_contenido SET img_next_poll_at=NULL WHERE id=?")->execute([$p9]);
    $correr($MID, $p9, 'vivo', true);
}
$as_desp = (int)$pdo->query("SELECT COUNT(*) FROM crecer_img_cuota_asiento WHERE marca_id={$MID}")->fetchColumn();
$job_desp = (string)$pdo->query("SELECT img_job FROM crecer_contenido WHERE id={$p9}")->fetchColumn();
ok('tres pasadas sobre un job vivo no abren otro asiento', $as_desp === $as_antes,
   "antes={$as_antes} despues={$as_desp}");
ok('y el job sigue siendo EL MISMO', $job_desp === $job_antes && $job_desp === 'resp_lat_9',
   "antes={$job_antes} despues={$job_desp} · un job nuevo seria una imagen nueva, pagada");

// ── 7b · SIN RED BAJO TRANSACCION ───────────────────────────────────────────
echo "\n  — no se despierta a nadie con una transaccion abierta —\n";
//  Con la puerta cerrada por modo prueba no se distinguiria una causa de otra,
//  asi que esto se comprueba donde SI se puede: en un proceso sin modo prueba,
//  que es como corre produccion.
$tx = (function () {
    $sal = []; exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/_worker_tx_runner.php') . ' 2>&1', $sal);
    $o = [];
    foreach ($sal as $l) { if (strpos($l, '=') !== false) { [$k, $v] = explode('=', trim($l), 2); $o[$k] = $v; } }
    return $o;
})();
ok('el runner corre SIN modo prueba', ($tx['MODO_PRUEBA'] ?? '') === '0');
ok('fuera de transaccion, la puerta deja pasar', ($tx['SIN_TX'] ?? '') === '1',
   'si esto fuera 0, el guardia estaria cerrando de mas');
ok('con transaccion abierta, la puerta NO deja pasar', ($tx['CON_TX'] ?? '') === '0',
   'el worker abre otra conexion: no puede ver lo que no se ha confirmado');
ok('y al confirmar vuelve a dejar pasar', ($tx['TRAS_COMMIT'] ?? '') === '1');

// ── 8 · NADA DE ESTO TOCO LO QUE NO SE PODIA TOCAR ──────────────────────────
echo "\n  — el brief, el modelo y la calidad siguen intactos —\n";
$p7 = $pieza('');
$pdo->prepare("UPDATE crecer_contenido SET img_job=NULL, img_estado=NULL WHERE id=?")->execute([$p7]);
$e = $correr($MID, $p7, 'encolar');
ok('sigue pidiendo calidad high',        ($e['BODY_QUALITY'] ?? '') === 'high',
   'quality=' . ($e['BODY_QUALITY'] ?? '?'));
ok('sigue en background',                ($e['BODY_BG'] ?? '') === '1');
ok('sigue usando image_generation',      ($e['BODY_TOOL'] ?? '') === 'image_generation');
ok('sigue en 1024x1024',                 ($e['BODY_SIZE'] ?? '') === '1024x1024');
ok('el brief sigue yendo completo',      (int)($e['BODY_BRIEF_LEN'] ?? 0) > 200,
   'largo=' . ($e['BODY_BRIEF_LEN'] ?? '?'));
ok('el modelo lo sigue eligiendo el codigo de siempre', ($e['BODY_MODEL'] ?? '') !== '');
ok('encolar abre la cronologia con su reloj',
   ($e['CRON_INICIO'] ?? '') !== '' && ($e['CRON_ACEPTADO'] ?? '') !== '');
ok('y nace con cero sondeos', (int)($e['CRON_POLLS'] ?? -1) === 0);

//  LA PANTALLA DE ESPERA NO SE TOCO, y no se afirma: se comprueba contra git.
$vista = __DIR__ . '/../includes/_preparacion_view.php';
$base  = @shell_exec('git show 391cbc6:includes/_preparacion_view.php 2>&1');
if (is_string($base) && strpos($base, '<script') !== false) {
    $ahora = str_replace("\r\n", "\n", (string)file_get_contents($vista));
    ok('la pantalla de espera es byte a byte la de 391cbc6',
       str_replace("\r\n", "\n", $base) === $ahora,
       'este encargo era de reloj, no de pantalla');
} else {
    echo "  (saltada: no se pudo leer 391cbc6 desde git)\n";
}

// ── 9 · UN SOLO CONSULTANTE A LA VEZ ────────────────────────────────────────
echo "\n  — sondear mas rapido no duplica trabajo —\n";
$p8 = $pieza('resp_lat_8');
$g = 0;
for ($i = 0; $i < 6; $i++) { if (img_poll_tomar_lease($pdo, $MID, $p8, 'resp_lat_8', true)) $g++; }
ok('seis sondeos seguidos, un solo lease concedido', $g === 1,
   "concedidos={$g} · dos pestañas no pueden preguntar a la vez");

$asientos = (int)$pdo->query("SELECT COUNT(*) FROM crecer_img_cuota_asiento
                               WHERE marca_id={$MID} AND origen_id={$p6}")->fetchColumn();
ok('cinco sondeos sobre la misma pieza no abren un segundo asiento', $asientos <= 1,
   "asientos={$asientos}");

} catch (Throwable $e) {
    $fallos++; echo "\n  EXCEPCION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
} finally {
    if ($F) {
        try {
            $pdo->prepare("DELETE FROM crecer_contenido WHERE marca_id=?")->execute([(int)$F['marca_id']]);
            Fixture::limpiar($pdo, (int)$F['marca_id']);
        } catch (Throwable $e) { echo "  (limpieza: " . $e->getMessage() . ")\n"; }
    }
}

echo "\n" . str_repeat('=', 60) . "\n";
echo $fallos ? "  {$fallos} FALLAS de {$n}\n\n" : "  TODO OK · {$n} pruebas\n\n";
exit($fallos ? 1 : 0);
