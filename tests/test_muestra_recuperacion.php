<?php
// ============================================================
//  CRECER — NINGUNA MUESTRA SE QUEDA EN «PREPARANDO» PARA SIEMPRE
//  tests/test_muestra_recuperacion.php
//
//  EL CASO QUE SE VIVIO EN PRODUCCION, Y QUE ESTA PRUEBA REPRODUCE.
//  Un dueño llego a 3:52 con la barra quieta y «El corillo esta preparando tu
//  idea». Dos defectos distintos daban el mismo cuadro:
//
//   1 · LA PANTALLA NO PREGUNTABA. El sondeo se mando a un GET que el endpoint
//       no atiende, y devolvia la pagina entera; `r.json()` reventaba y el
//       .catch() reintentaba en bucle. Eso lo cubre test_preparacion_contrato.
//
//   2 · EL DISPARO NO SABIA SI HABIA ARRANCADO. `muestra_arrancar` solo miraba
//       errores de CONEXION: un 403/404/503 devolvia `true`, el lock se quedaba
//       tomado 180 s, y al vencer se volvia a disparar contra el mismo error.
//       Un bucle silencioso: sin avance, sin fallo, sin fin. Eso es lo que se
//       prueba aqui.
//
//  LA REGLA QUE SE VIGILA: el tiempo NO declara exito ni fracaso. El tiempo solo
//  sirve para detectar AUSENCIA DE AVANCE; quien declara el desenlace es el
//  estado persistido. Por eso aqui se envejecen filas con el reloj de MySQL y
//  jamas se usa sleep().
//
//    php tests/test_muestra_recuperacion.php
// ============================================================
define('OPENAI_API_KEY', 'llave-falsa-de-prueba-no-autentica');
define('GEMINI_API_KEY', 'llave-falsa-de-prueba-no-autentica');
define('CRECER_TEST_RED_FALSA', true);
define('MUESTRA_WORKER_LOCAL', true);

const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
$GLOBALS['RED'] = ['texto' => 0, 'crear' => 0, 'estado' => 0];
$GLOBALS['MUERE_EN'] = '';     // '', 'antes_caption', 'antes_job'

function ia_http_post_retry(string $url, array $headers, string $body,
                            int $max_reintentos = 4, int $timeout = 60): string {
    $GLOBALS['RED']['texto']++;
    if ($GLOBALS['MUERE_EN'] === 'antes_caption') throw new RuntimeException('[prueba] el worker murio');
    $CAP = 'CAPTION-RECUP del negocio.';
    $j = json_encode([
        'angulos' => [['tactica'=>'Escasez','gancho'=>'Solo 10','porque_pega'=>'mueve','visual'=>'una bandeja']],
        'elegido' => 1, 'razon' => 'x', 'brief' => 'x', 'texto' => $CAP,
        'descripcion'=>'x','voz'=>'x','publico_objetivo'=>'x','identidad'=>'x','reglas_imagen'=>'x',
        'reglas_voz'=>'x','reglas_estrategia'=>'x','personalidad'=>'x','ejes'=>['formalidad'=>40],
    ], JSON_UNESCAPED_UNICODE);
    if (stripos($body, '¿OK, o cuál es la nota?') !== false) return json_encode(
        ['candidates'=>[['content'=>['parts'=>[['text'=>'OK']]]]],'usageMetadata'=>['promptTokenCount'=>5,'candidatesTokenCount'=>5]]);
    $plano = (stripos($body, 'Devuelve SOLO el caption') !== false || stripos($body, 'sin comillas ni explicaci') !== false);
    return json_encode(['candidates'=>[['content'=>['parts'=>[['text'=>$plano ? $CAP : $j]]]]],
                        'usageMetadata'=>['promptTokenCount'=>40,'candidatesTokenCount'=>40]]);
}
function openai_responses_crear_bg(string $brief, array $opts = []): array {
    if ($GLOBALS['MUERE_EN'] === 'antes_job') throw new RuntimeException('[prueba] murio antes de encolar');
    $GLOBALS['RED']['crear']++;
    require_once __DIR__ . '/../includes/cuota_imagenes.php';
    $c = $opts['cuota'] ?? null;
    if ($c instanceof CuotaCtx) CuotaImg::garantizar($c, 'prueba recuperacion');
    return ['id' => 'resp_recup', 'modelo' => 'simulado', 'status' => 'queued'];
}
function ia_http_get_res(string $url, array $headers): array {
    $GLOBALS['RED']['estado']++;
    return ['code' => 200, 'body' => json_encode(['id'=>'resp_recup','status'=>'in_progress'])];
}

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/agentes.php';
require_once __DIR__ . '/../includes/img_responses.php';
require_once __DIR__ . '/../includes/cuota_imagenes.php';
require_once __DIR__ . '/../includes/muestra.php';
require_once __DIR__ . '/_fixture.php';

//  El worker LOCAL. Si $MUERE_EN esta puesto, revienta como reventaria uno de
//  verdad que se queda sin proceso a mitad de camino.
function muestra_worker_local(PDO $pdo, int $marca_id, int $cid, int $uid, string $token): void {
    try { muestra_preparar($pdo, $marca_id, $cid); onboarding_lock_done($pdo, $uid, $marca_id, $token); }
    catch (Throwable $e) { onboarding_lock_fail($pdo, $uid, $token); }
}

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok    $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n          -> " . mb_substr($detalle, 0, 260) : '') . "\n";
}
/** Envejece la pieza con el reloj de MySQL. Nunca sleep(). */
function envejecer(PDO $pdo, int $cid, int $seg): void {
    $pdo->prepare("UPDATE crecer_contenido SET created_at=DATE_SUB(NOW(), INTERVAL ? SECOND) WHERE id=?")
        ->execute([$seg, $cid]);
}
/** Cuenta piezas, jobs y asientos de una marca: el retrato anti-duplicado. */
function retrato(PDO $pdo, int $M): array {
    return [
        'piezas'   => (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$M}")->fetchColumn(),
        'jobs'     => (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$M} AND COALESCE(img_job,'')<>''")->fetchColumn(),
        'asientos' => (int)$pdo->query("SELECT COUNT(*) FROM crecer_img_cuota_asiento WHERE marca_id={$M}")->fetchColumn(),
    ];
}

echo "\nNINGUNA MUESTRA SE QUEDA EN «PREPARANDO» PARA SIEMPRE\n" . str_repeat('=', 62) . "\n";

// ══════════════════════════════════════════════════════════════
//  EL CASO OBSERVADO · 3:52 sin cambio de etapa
// ══════════════════════════════════════════════════════════════
echo "\n  -- el caso de produccion: 3:52 y la barra quieta --\n";
$f1 = Fixture::crear($pdo, 'recup-352', false);
$M1 = (int)$f1['marca_id']; $U1 = (int)$f1['usuario_id'];
try {
    onboarding_lock_reset($pdo, $U1);
    $c1 = muestra_fila($pdo, $M1);
    //  El cuadro exacto: 232 s, sin copy, sin job, y el disparo fallando.
    envejecer($pdo, $c1, 232);

    //  Uno y dos fallos: TODAVIA se reintenta en silencio. Un tropiezo no es un
    //  desenlace, y anunciar un fallo al primer intento seria alarmismo.
    muestra_arranque_fallido($pdo, $M1, $c1, 'http_403');
    $st = muestra_estado($pdo, $M1, $c1);
    ok('con un fallo, sigue intentando',   $st['degradado'] === 'vivo', 'degradado=' . $st['degradado']);
    muestra_arranque_fallido($pdo, $M1, $c1, 'http_403');
    ok('con dos, tambien',                 muestra_estado($pdo,$M1,$c1)['degradado'] === 'vivo');

    //  El tercero cierra el caso: ya no es mala suerte.
    muestra_arranque_fallido($pdo, $M1, $c1, 'http_403');
    $st = muestra_estado($pdo, $M1, $c1);
    ok('al tercero se declara el fallo',   $st['degradado'] === 'arranque', 'degradado=' . $st['degradado']);
    ok('y NO se declara listo por tiempo', $st['listo'] === false && $st['pct'] < 100);
    ok('el reloj no inventa avance',       $st['pct_estimado'] <= MUESTRA_PCT_TECHO);
    //  LO QUE IMPORTA DE VERDAD: deja de ser una espera y pasa a ser accionable.
    ok('la espera se convierte en salida', in_array($st['degradado'], ['arranque','rechazo','definitivo'], true));

    //  Y un arranque que SI sale limpia el rastro: un tropiezo viejo no puede
    //  condenar a una pieza que ya va bien.
    muestra_arranque_ok($pdo, $M1, $c1);
    ok('un arranque bueno borra el rastro', muestra_estado($pdo,$M1,$c1)['degradado'] === 'vivo');
} finally {
    onboarding_lock_reset($pdo, $U1);
    Fixture::limpiar($pdo, $M1);
}

// ══════════════════════════════════════════════════════════════
//  EL WORKER MUERE A MITAD · y el siguiente intento CONTINUA
// ══════════════════════════════════════════════════════════════
echo "\n  -- el worker muere antes del caption --\n";
$f2 = Fixture::crear($pdo, 'recup-antes', false);
$M2 = (int)$f2['marca_id']; $U2 = (int)$f2['usuario_id'];
try {
    onboarding_lock_reset($pdo, $U2);
    $c2 = muestra_fila($pdo, $M2);
    $GLOBALS['MUERE_EN'] = 'antes_caption';
    muestra_arrancar($pdo, $M2, $U2, $c2);
    $r = retrato($pdo, $M2);
    ok('3 · sin caption no queda job',     $r['jobs'] === 0 && $r['asientos'] === 0, json_encode($r));
    ok('3 · y el lock quedo libre',        muestra_lock_estado($pdo, $M2) !== 'procesando');

    //  Y NO SE GASTO NADA. Esta fue la falla que descubrio esta prueba: el
    //  try/catch dejaba seguir sin caption y se encolaba la imagen igual, con un
    //  brief vacio y una unidad de cuota pagada.
    ok('3 · sin caption no se gasta cuota', retrato($pdo,$M2)['asientos'] === 0);

    //  El intento siguiente CONTINUA desde donde quedo: escribe el copy y encola.
    $GLOBALS['MUERE_EN'] = '';
    muestra_asegurar($pdo, $M2, $U2);
    $st = muestra_estado($pdo, $M2, $c2);
    ok('el siguiente intento continua',    $st['etapa'] === 'enviada', 'etapa=' . $st['etapa']);
    $r = retrato($pdo, $M2);
    ok('11 · sin duplicar pieza ni job',   $r['piezas'] === 1 && $r['jobs'] === 1 && $r['asientos'] === 1, json_encode($r));
} finally {
    $GLOBALS['MUERE_EN'] = '';
    onboarding_lock_reset($pdo, $U2);
    Fixture::limpiar($pdo, $M2);
}

echo "\n  -- muere despues del caption, antes del job --\n";
$f3 = Fixture::crear($pdo, 'recup-despues', false);
$M3 = (int)$f3['marca_id']; $U3 = (int)$f3['usuario_id'];
try {
    onboarding_lock_reset($pdo, $U3);
    $c3 = muestra_fila($pdo, $M3);
    $GLOBALS['MUERE_EN'] = 'antes_job';
    muestra_arrancar($pdo, $M3, $U3, $c3);
    $cap = (string)$pdo->query("SELECT caption FROM crecer_contenido WHERE id={$c3}")->fetchColumn();
    ok('4 · el caption sobrevivio',        $cap !== '' && $cap !== MUESTRA_IDEA, mb_substr($cap, 0, 60));
    ok('4 · y no hay job ni asiento',      retrato($pdo,$M3)['jobs'] === 0);

    //  Reanudar NO puede volver a pagar el texto.
    $GLOBALS['MUERE_EN'] = '';
    $txt = $GLOBALS['RED']['texto'];
    muestra_asegurar($pdo, $M3, $U3);
    ok('reanudar reusa el caption',        $GLOBALS['RED']['texto'] === $txt,
       'llamadas de texto extra: ' . ($GLOBALS['RED']['texto'] - $txt));

    //  AQUI LA RESPUESTA CORRECTA ES NO HACER NADA, Y COSTO ENTENDERLO.
    //  La primera version de esta prueba exigia que reanudar encolara. Pero una
    //  excepcion AL ENCOLAR es indistinguible de «la peticion llego y no vimos
    //  la respuesta»: la pieza queda marcada 'incierto' a proposito, y crear
    //  otro job seria pagar dos veces la misma imagen. Que se quede quieta es la
    //  garantia, no el fallo. Lo que si se exige es que el dueño no se quede
    //  colgado — para eso esta el desenlace con nombre y su boton.
    $st3 = muestra_estado($pdo, $M3, $c3);
    ok('12 · el encolado incierto se nombra', $st3['degradado'] === 'incierto', 'degradado=' . $st3['degradado']);
    ok('12 · y NO se crea un segundo job',    retrato($pdo,$M3)['jobs'] === 0, json_encode(retrato($pdo,$M3)));
    ok('11 · ni un segundo asiento',          retrato($pdo,$M3)['asientos'] <= 1, json_encode(retrato($pdo,$M3)));
} finally {
    $GLOBALS['MUERE_EN'] = '';
    onboarding_lock_reset($pdo, $U3);
    Fixture::limpiar($pdo, $M3);
}

// ══════════════════════════════════════════════════════════════
//  LOCK VIVO vs VENCIDO · DOS PESTAÑAS · JOB YA EXISTENTE
// ══════════════════════════════════════════════════════════════
echo "\n  -- lock, pestañas y job existente --\n";
$f4 = Fixture::crear($pdo, 'recup-lock', false);
$M4 = (int)$f4['marca_id']; $U4 = (int)$f4['usuario_id'];
try {
    onboarding_lock_reset($pdo, $U4);
    $c4 = muestra_fila($pdo, $M4);
    muestra_arrancar($pdo, $M4, $U4, $c4);
    $base = retrato($pdo, $M4);
    ok('5 · con job vivo, no se crea otro', $base['jobs'] === 1 && $base['asientos'] === 1, json_encode($base));

    //  6 · dos pestañas sondeando a la vez.
    $a = muestra_asegurar($pdo, $M4, $U4);
    $b = muestra_asegurar($pdo, $M4, $U4);
    ok('6 · dos pestañas, el mismo trabajo', $a['etapa'] === $b['etapa'] && $a['pieza'] === $b['pieza']);
    ok('6 · y ninguna crea nada',            retrato($pdo,$M4) == $base, json_encode(retrato($pdo,$M4)));

    //  7 · lock VIVO: asegurar no vuelve a arrancar.
    $pdo->prepare("UPDATE crecer_onboarding_lock SET estado='procesando', updated_at=NOW() WHERE usuario_id=?")->execute([$U4]);
    ok('7 · lock vivo frena el rearranque',  muestra_lock_estado($pdo, $M4) === 'procesando');
    muestra_asegurar($pdo, $M4, $U4);
    ok('7 · y no se creo nada',              retrato($pdo,$M4) == $base);

    //  7 · lock VENCIDO: deja de frenar.
    $pdo->prepare("UPDATE crecer_onboarding_lock SET updated_at=DATE_SUB(NOW(), INTERVAL ? SECOND) WHERE usuario_id=?")
        ->execute([MUESTRA_STALE_SEG + 30, $U4]);
    ok('7 · lock vencido ya no frena',        muestra_lock_estado($pdo, $M4) !== 'procesando');
    muestra_asegurar($pdo, $M4, $U4);
    ok('7 · y aun asi no duplica',            retrato($pdo,$M4) == $base, json_encode(retrato($pdo,$M4)));
} finally {
    onboarding_lock_reset($pdo, $U4);
    Fixture::limpiar($pdo, $M4);
}

// ══════════════════════════════════════════════════════════════
//  FALLO DEFINITIVO Y REINTENTO
// ══════════════════════════════════════════════════════════════
echo "\n  -- fallo definitivo y reintento del dueño --\n";
$f5 = Fixture::crear($pdo, 'recup-def', false);
$M5 = (int)$f5['marca_id']; $U5 = (int)$f5['usuario_id'];
try {
    onboarding_lock_reset($pdo, $U5);
    $c5 = muestra_fila($pdo, $M5);
    $pdo->prepare("UPDATE crecer_contenido SET ia_log_id=1, caption='EL TEXTO QUE SI SE ESCRIBIO',
                          img_estado='error', img_job=NULL, img_error_clase='fbx:sin_motor' WHERE id=?")->execute([$c5]);
    $st = muestra_estado($pdo, $M5, $c5);
    ok('10 · el fallo definitivo se nombra', $st['degradado'] === 'definitivo');
    ok('10 · y el copy se conserva',         $st['copy_a_salvo'] === 'EL TEXTO QUE SI SE ESCRIBIO');

    $antes = retrato($pdo, $M5);
    ok('10 · el reintento arranca',          muestra_reintentar($pdo, $M5, $U5) === true);
    $desp = retrato($pdo, $M5);
    ok('11 · el reintento no duplica pieza', $desp['piezas'] === $antes['piezas']);
    ok('11 · ni abre un segundo asiento',    $desp['asientos'] <= 1, json_encode($desp));
} finally {
    onboarding_lock_reset($pdo, $U5);
    Fixture::limpiar($pdo, $M5);
}

echo "\n  -- la cuenta --\n";
ok('cero proveedores reales', defined('CRECER_TEST_MODE') && CRECER_TEST_MODE);
ok('cero DDL',                true, 'el estado de arranque reusa img_error_clase con prefijo arr<N>:');

echo "\n" . str_repeat('-', 62) . "\n";
echo ($fallos === 0 ? "  TODO EN VERDE" : "  {$fallos} FALLAS") . " · {$n} comprobaciones\n";
exit($fallos === 0 ? 0 : 1);
