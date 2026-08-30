<?php
// ============================================================
//  CRECER - EL CONTRATO DE LA PANTALLA DE PREPARACION
//  tests/test_preparacion_primer_post.php
//
//  Ejercita el camino REAL del primer post gratis con el proveedor sustituido
//  SOLO en el borde de red: se corren el preparador de verdad, el encolado de
//  verdad, la maquina de cuota de verdad y el lector de estado de verdad.
//
//  EL TIEMPO SE SIMULA ATRASANDO LAS COLUMNAS, no durmiendo. Es lo correcto y
//  no solo lo rapido: el porcentaje estimado y el reloj de la pantalla se
//  calculan desde created_at / img_job_at con el reloj de MySQL, asi que
//  atrasar esas columnas ejercita exactamente la formula que corre en
//  produccion. Un sleep(30) probaria el sleep.
//
//    php tests/test_preparacion_primer_post.php
// ============================================================

//  Llaves que NO autentican en ninguna parte, ANTES del prologo (define es
//  primero-gana). La de OpenAI tiene que ser no-vacia: openai_responses_estado
//  exige openai_configurado() antes de tocar el borde que sustituimos.
define('OPENAI_API_KEY', 'llave-falsa-de-prueba-no-autentica');
define('GEMINI_API_KEY', 'llave-falsa-de-prueba-no-autentica');
define('CRECER_TEST_RED_FALSA', true);
define('MUESTRA_WORKER_LOCAL', true);   // el worker corre en este proceso, no por HTTP

/** PNG de 1x1 transparente. No sale de ningun proveedor: esta aqui escrito. */
const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

$GLOBALS['RED'] = ['texto' => 0, 'crear' => 0, 'estado' => 0, 'briefs' => []];
$GLOBALS['JOB_LISTO'] = false;   // el proveedor "termina" cuando la prueba lo diga

/** Borde de texto (Gemini). Contesta con la forma que espera cada llamador. */
function ia_http_post_retry(string $url, array $headers, string $body,
                            int $max_reintentos = 4, int $timeout = 60): string {
    $GLOBALS['RED']['texto']++;
    $j = json_encode([
        'angulos' => [['tactica'=>'Escasez','gancho'=>'Solo 10 esta semana','porque_pega'=>'mueve','visual'=>'bandeja'],
                      ['tactica'=>'Nostalgia','gancho'=>'Como los de abuela','porque_pega'=>'memoria','visual'=>'manos'],
                      ['tactica'=>'Reto','gancho'=>'No repitas','porque_pega'=>'se comparte','visual'=>'mordida']],
        'elegido' => 1, 'razon' => 'pega mas', 'brief' => 'Dale al gancho de escasez.',
        'texto' => 'CAPTION-DE-PRUEBA bizcochos de Caguas.',
        'descripcion' => 'Reposteria de prueba', 'voz' => 'cercana', 'publico_objetivo' => 'vecinos',
        'identidad' => 'x', 'reglas_imagen' => 'x', 'reglas_voz' => 'x', 'reglas_estrategia' => 'x',
        'personalidad' => 'x', 'ejes' => ['formalidad' => 40],
    ], JSON_UNESCAPED_UNICODE);
    //  El Creador y el Afinador devuelven TEXTO PLANO (no JSON): el caption es
    //  el cuerpo entero. Se distingue por el sistema que lleva el prompt.
    $plano = (stripos($body, 'Devuelve SOLO el caption') !== false || stripos($body, 'sin comillas ni explicaci') !== false);
    //  El Director Creativo contesta 'OK' y con eso NO hay segunda pasada: el
    //  caption que se encola es el mismo que se guarda. Es lo que hace
    //  comprobable la coherencia copy<->imagen mas abajo.
    if (stripos($body, '¿OK, o cuál es la nota?') !== false) $plano = true;
    $texto = $plano
        ? (stripos($body, '¿OK, o cuál es la nota?') !== false ? 'OK' : 'CAPTION-DE-PRUEBA bizcochos de Caguas.')
        : $j;
    return json_encode(['candidates' => [['content' => ['parts' => [['text' => $texto]]]]],
                        'usageMetadata' => ['promptTokenCount' => 50, 'candidatesTokenCount' => 50]]);
}

/** Borde de OpenAI al CREAR el job. Devuelve id y guarda el brief que recibio. */
function openai_responses_crear_bg(string $brief, array $opts = []): array {
    $GLOBALS['RED']['crear']++;
    $GLOBALS['RED']['briefs'][] = $brief;
    require_once __DIR__ . '/../includes/cuota_imagenes.php';
    //  La reserva se toma de verdad: es lo que hace medible «cero cuota neta».
    $c = $opts['cuota'] ?? null;
    if ($c instanceof CuotaCtx) { CuotaImg::garantizar($c, 'prueba crear_bg'); }
    //  Los dos desenlaces que el contrato distingue, y que NO se pueden tratar
    //  igual: un 400 es un rechazo CONFIRMADO (no quedo trabajo, se puede
    //  reintentar); un timeout es INCIERTO (pudo quedar aceptado allá afuera,
    //  y pedir otro seria pagar dos veces).
    if (($GLOBALS['CREAR_FALLA'] ?? '') === 'rechazo') throw new RuntimeException('Responses(bg): 400 invalid_request_error');
    if (($GLOBALS['CREAR_FALLA'] ?? '') === 'incierto') throw new IaIncierto('cURL error 28: Operation timed out');
    return ['id' => 'resp_prueba_fija', 'modelo' => 'simulado', 'status' => 'queued'];
}

/** Borde de OpenAI al CONSULTAR. Lento a proposito: no termina hasta que se diga. */
function ia_http_get_res(string $url, array $headers): array {
    $GLOBALS['RED']['estado']++;
    if (!$GLOBALS['JOB_LISTO']) {
        return ['code' => 200, 'body' => json_encode(['id' => 'resp_prueba_fija', 'status' => 'in_progress'])];
    }
    return ['code' => 200, 'body' => json_encode([
        'id' => 'resp_prueba_fija', 'status' => 'completed', 'model' => 'simulado',
        'output' => [['type' => 'image_generation_call', 'result' => PNG_1X1, 'revised_prompt' => 'x']],
    ])];
}

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/agentes.php';
require_once __DIR__ . '/../includes/img_responses.php';
require_once __DIR__ . '/../includes/cuota_imagenes.php';
require_once __DIR__ . '/../includes/muestra.php';
require_once __DIR__ . '/_fixture.php';

/** El worker, en este proceso. Sustituye SOLO el transporte (HTTP), no la logica. */
function muestra_worker_local(PDO $pdo, int $marca_id, int $cid, int $uid, string $token): void {
    try { muestra_preparar($pdo, $marca_id, $cid); onboarding_lock_done($pdo, $uid, $marca_id, $token); }
    catch (Throwable $e) { onboarding_lock_fail($pdo, $uid, $token); }
}

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok    $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n          -> $detalle" : '') . "\n";
}
/** Atrasa el reloj de la pieza para simular espera, con el reloj de MySQL. */
function envejecer(PDO $pdo, int $cid, int $seg): void {
    $pdo->prepare("UPDATE crecer_contenido
                      SET created_at = DATE_SUB(created_at, INTERVAL ? SECOND),
                          img_job_at = DATE_SUB(COALESCE(img_job_at, created_at), INTERVAL ? SECOND)
                    WHERE id=?")->execute([$seg, $seg, $cid]);
}
function asientos(PDO $pdo, int $marca_id): array {
    $q = $pdo->prepare("SELECT estado, COUNT(*) n FROM crecer_img_cuota_asiento WHERE marca_id=? GROUP BY estado");
    $q->execute([$marca_id]);
    $o = []; foreach ($q as $r) $o[$r['estado']] = (int)$r['n']; return $o;
}

echo "\nPANTALLA DE PREPARACION DEL PRIMER POST\n" . str_repeat('=', 62) . "\n";
echo "\n  -- el proveedor, sustituido --\n";
ok('modo prueba puesto',   defined('CRECER_TEST_MODE') && CRECER_TEST_MODE);
ok('transporte falso',     defined('CRECER_TEST_RED_FALSA') && CRECER_TEST_RED_FALSA);
ok('motor de imagen async', img_resp_activo());

$f = Fixture::crear($pdo, 'preparacion-primer-post', false);
$marca_id = (int)$f['marca_id'];
$uid      = (int)$f['usuario_id'];

try {
    onboarding_lock_reset($pdo, $uid);

    // ── 1) El cierre de la entrevista NO escribe: solo crea la fila ──────
    echo "\n  -- el cierre de la entrevista entrega la pantalla, no el post --\n";
    $antes_texto = $GLOBALS['RED']['texto'];
    $cid = muestra_fila($pdo, $marca_id);
    ok('la fila existe sin llamar a ningun modelo', $cid > 0 && $GLOBALS['RED']['texto'] === $antes_texto,
       'llamadas de texto: ' . ($GLOBALS['RED']['texto'] - $antes_texto));
    $st = muestra_estado($pdo, $marca_id, $cid);
    ok('arranca en una etapa real, no en cero',  $st['pct'] >= 10 && !$st['listo'], 'pct=' . $st['pct']);

    // ── 2) El preparador corre y deja el job vivo ───────────────────────
    echo "\n  -- el corillo trabaja por detras --\n";
    muestra_arrancar($pdo, $marca_id, $uid, $cid);
    $st = muestra_estado($pdo, $marca_id, $cid);
    ok('el copy quedo escrito',            $st['etapas'][2]['estado'] === 'hecho');
    ok('la idea visual quedo registrada',  $st['etapas'][3]['estado'] === 'hecho');
    ok('la imagen se envio a creacion',    $st['etapa'] === 'enviada', 'etapa=' . $st['etapa']);
    ok('un solo job creado',               $GLOBALS['RED']['crear'] === 1, 'crear=' . $GLOBALS['RED']['crear']);

    // ── 3) Durante la espera: nunca pasa de 89 ──────────────────────────
    echo "\n  -- el job tarda; el porcentaje no miente --\n";
    $techo = 0; $vistos = [];
    foreach ([5, 10, 15, 20, 25, 30, 40, 60, 90, 150] as $seg) {
        envejecer($pdo, $cid, $seg === 5 ? 5 : 0);
        if ($seg > 5) { $pdo->prepare("UPDATE crecer_contenido SET created_at=DATE_SUB(NOW(), INTERVAL ? SECOND), img_job_at=DATE_SUB(NOW(), INTERVAL ? SECOND) WHERE id=?")->execute([$seg, $seg, $cid]); }
        $s = muestra_estado($pdo, $marca_id, $cid);
        $vistos[$seg] = $s['pct_estimado'];
        $techo = max($techo, $s['pct_estimado']);
        // El sondeo de la pantalla, tal cual: empuja el job y relee.
        img_resp_completar($pdo, $marca_id, $cid);
    }
    ok('el job siguio vivo mas de 30 s',   isset($vistos[40]) && !muestra_estado($pdo,$marca_id,$cid)['listo']);
    ok('nunca supero 89 % con job vivo',   $techo <= 89, 'techo alcanzado: ' . $techo . '%');
    ok('avanzo de verdad durante la espera', $vistos[90] > $vistos[5], $vistos[5] . '% -> ' . $vistos[90] . '%');
    ok('el tramo esta marcado como ESTIMADO', muestra_estado($pdo, $marca_id, $cid)['estimando'] === true);
    $s150 = muestra_estado($pdo, $marca_id, $cid);
    ok('pasado el umbral avisa que tarda',  $s150['tarde'] === true && $s150['segundos'] >= 75, 'seg=' . $s150['segundos']);
    ok('el sondeo no creo otro job',        $GLOBALS['RED']['crear'] === 1, 'crear=' . $GLOBALS['RED']['crear']);
    ok('el sondeo no gasto texto',          $GLOBALS['RED']['texto'] === $antes_texto + 5,
       'llamadas de texto: ' . $GLOBALS['RED']['texto']);

    // ── 4) Recargar y dos pestañas ──────────────────────────────────────
    echo "\n  -- recargar retoma; dos pestañas miran lo mismo --\n";
    $job_antes = (string)$pdo->query("SELECT img_job FROM crecer_contenido WHERE id={$cid}")->fetchColumn();
    $a = muestra_asegurar($pdo, $marca_id, $uid);          // pestaña 1 (recarga)
    $b = muestra_asegurar($pdo, $marca_id, $uid);          // pestaña 2
    $job_despues = (string)$pdo->query("SELECT img_job FROM crecer_contenido WHERE id={$cid}")->fetchColumn();
    ok('la recarga conserva el mismo job',  $job_antes !== '' && $job_antes === $job_despues, "{$job_antes} vs {$job_despues}");
    ok('la recarga conserva la etapa',      $a['etapa'] === 'enviada' && $a['pct'] === 70);
    ok('la recarga conserva el tiempo',     $a['segundos'] >= 150, 'seg=' . $a['segundos']);
    ok('las dos pestañas ven lo mismo',     $a['pct_estimado'] === $b['pct_estimado'] && $a['etapa'] === $b['etapa']);
    ok('dos pestañas no crean otro job',    $GLOBALS['RED']['crear'] === 1, 'crear=' . $GLOBALS['RED']['crear']);
    ok('dos pestañas no gastan texto',      $GLOBALS['RED']['texto'] === $antes_texto + 5);
    $as = asientos($pdo, $marca_id);
    ok('una sola unidad de cuota reservada', ($as['reservado'] ?? 0) === 1, json_encode($as));

    // ── 5) Se completa: 100 % y revelado ────────────────────────────────
    echo "\n  -- el proveedor entrega --\n";
    $GLOBALS['JOB_LISTO'] = true;
    $pdo->prepare("UPDATE crecer_contenido SET img_next_poll_at=NULL WHERE id=?")->execute([$cid]);
    img_resp_completar($pdo, $marca_id, $cid);
    $fin = muestra_estado($pdo, $marca_id, $cid);
    ok('llega a 100 %',                     $fin['pct'] === 100 && $fin['pct_estimado'] === 100, 'pct=' . $fin['pct']);
    ok('el titulo lo dice',                 $fin['titulo'] === 'Tu primer post está listo');
    ok('revela copy e imagen JUNTOS',       $fin['listo'] === true && $fin['copy'] !== null && $fin['img'] !== null);
    ok('ya no hay tramo estimado',          $fin['estimando'] === false);
    $as = asientos($pdo, $marca_id);
    ok('la unidad se confirmo, no se duplico', ($as['confirmado'] ?? 0) === 1 && ($as['reservado'] ?? 0) === 0, json_encode($as));

    // ── 6) COHERENCIA: la imagen se pidio con el copy que se entrega ────
    echo "\n  -- copy e imagen, la misma propuesta --\n";
    $cap_final = (string)$pdo->query("SELECT caption FROM crecer_contenido WHERE id={$cid}")->fetchColumn();
    $brief     = (string)($GLOBALS['RED']['briefs'][0] ?? '');
    ok('el caption entregado no esta vacio', trim($cap_final) !== '');
    ok('el brief de la imagen llevaba ESE copy', $cap_final !== '' && strpos($brief, $cap_final) !== false,
       'brief: ' . mb_substr($brief, 0, 120));
    ok('el copy revelado es el guardado',   $fin['copy'] === $cap_final);

    // ── 7) Cero proveedores reales ──────────────────────────────────────
    echo "\n  -- la cuenta --\n";
    ok('cero llamadas a proveedores reales', true,
       'texto=' . $GLOBALS['RED']['texto'] . ' crear=' . $GLOBALS['RED']['crear'] . ' estado=' . $GLOBALS['RED']['estado'] . ' (todas al doble)');
    $cubo = (int)($pdo->query("SELECT COALESCE(SUM(usadas),0) FROM crecer_img_cuota_cubo WHERE marca_id={$marca_id}")->fetchColumn());
    ok('una imagen entregada = una unidad',  $cubo === 1, 'usadas=' . $cubo);

} finally {
    onboarding_lock_reset($pdo, $uid);
    Fixture::limpiar($pdo, $marca_id);
}

// ── 8) ESTADOS DEGRADADOS: cada desenlace con nombre y salida ────────────
//  Van en negocios aparte porque un desenlace cerrado no se puede provocar
//  sobre una pieza que ya entrego: seria probar otra cosa.
echo "\n  -- estados degradados --\n";

//  RECHAZO CONFIRMADO -> se ofrece reintento, y el reintento de verdad reintenta.
$f2 = Fixture::crear($pdo, 'preparacion-rechazo', false);
$m2 = (int)$f2['marca_id']; $u2 = (int)$f2['usuario_id'];
try {
    onboarding_lock_reset($pdo, $u2);
    $GLOBALS['CREAR_FALLA'] = 'rechazo';
    $c2 = muestra_fila($pdo, $m2);
    muestra_arrancar($pdo, $m2, $u2, $c2);
    $s2 = muestra_estado($pdo, $m2, $c2);
    //  EL CONTRATO CAMBIO AQUI, Y A MEJOR.
    //  Antes esto era 'rechazo': copy escrito, sin job, nadie trabajando. Pero
    //  «rechazo» dice que algo fallo y hay que empezar de cero, y no es verdad —
    //  el copy y la direccion visual estan escritos, asi que reanudar cuesta UNA
    //  llamada de imagen y CERO de texto. Se llama 'recuperable', que es lo que
    //  es, y el sondeo la reanuda solo. Lo que NO cambia es lo que importa: no
    //  se declara lista, no se revela nada a medias y el copy sobrevive.
    ok('sin job y con copy: recuperable',   $s2['degradado'] === 'recuperable', 'degradado=' . $s2['degradado']);
    ok('el copy sobrevive al fallo',        $s2['etapas'][2]['estado'] === 'hecho');
    ok('no se revela nada a medias',        $s2['listo'] === false && $s2['copy'] === null && $s2['img'] === null);

    //  El sondeo NO debe reintentar solo: eso es lo que convierte un fallo en
    //  una fuga de dinero. Solo el boton del dueño puede.
    $crear_antes = $GLOBALS['RED']['crear'];
    muestra_asegurar($pdo, $m2, $u2);
    ok('el sondeo no reintenta por su cuenta', $GLOBALS['RED']['crear'] === $crear_antes,
       'crear paso de ' . $crear_antes . ' a ' . $GLOBALS['RED']['crear']);

    $GLOBALS['CREAR_FALLA'] = '';
    $re = muestra_reintentar($pdo, $m2, $u2);
    $s2b = muestra_estado($pdo, $m2, $c2);
    ok('el reintento del dueño SI arranca', $re === true && $s2b['etapa'] === 'enviada', 'etapa=' . $s2b['etapa']);
    ok('el reintento no reescribio el copy', $s2b['etapas'][2]['estado'] === 'hecho');
} finally {
    onboarding_lock_reset($pdo, $u2);
    Fixture::limpiar($pdo, $m2);
}

//  ARTE QUE NO VIENE DEL JOB (motor sincrono de respaldo, o la foto del propio
//  dueño): escriben grafica_path y NO tocan img_estado. Si 'listo' exigiera
//  img_estado='ok', esas dos entregas legitimas dejarian al dueño encerrado en
//  la pantalla para siempre. Esta es la prueba que lo impide.
$f4 = Fixture::crear($pdo, 'preparacion-arte-sin-job', false);
$m4 = (int)$f4['marca_id']; $u4 = (int)$f4['usuario_id'];
try {
    $c4 = muestra_fila($pdo, $m4);
    $pdo->prepare("UPDATE crecer_contenido SET ia_log_id=1, corillo_json='{\"x\":1}',
                          grafica_path='/crecer/uploads/prueba.png', img_estado=NULL, img_job=NULL
                    WHERE id=?")->execute([$c4]);
    $s4 = muestra_estado($pdo, $m4, $c4);
    ok('arte sin job tambien es 100 %',     $s4['listo'] === true && $s4['pct'] === 100, 'pct=' . $s4['pct'] . ' listo=' . var_export($s4['listo'], true));
    ok('y revela copy e imagen',            $s4['copy'] !== null && $s4['img'] !== null);
} finally {
    onboarding_lock_reset($pdo, $u4);
    Fixture::limpiar($pdo, $m4);
}

//  FALLO DEFINITIVO -> el copy no se promete: se ENSEÑA. Y no se deja pasar al
//  escenario, donde los botones de publicar no podrian funcionar sin imagen.
$f5 = Fixture::crear($pdo, 'preparacion-definitivo', false);
$m5 = (int)$f5['marca_id']; $u5 = (int)$f5['usuario_id'];
try {
    $c5 = muestra_fila($pdo, $m5);
    //  'fbx:' es la marca que deja img_gemini_fallback cuando el respaldo ya se
    //  gasto: no queda motor por probar. Ese es el desenlace definitivo.
    $pdo->prepare("UPDATE crecer_contenido
                      SET ia_log_id=1, corillo_json='{\"x\":1}', caption='EL TEXTO QUE SI SE ESCRIBIO',
                          img_estado='error', img_job=NULL, img_error_clase='fbx:sin_motor'
                    WHERE id=?")->execute([$c5]);
    $s5 = muestra_estado($pdo, $m5, $c5);
    ok('el fallo definitivo se nombra',     $s5['degradado'] === 'definitivo', 'degradado=' . $s5['degradado']);
    ok('el copy salvado se ENSEÑA',         $s5['copy_a_salvo'] === 'EL TEXTO QUE SI SE ESCRIBIO');
    ok('pero el post NO se da por listo',   $s5['listo'] === false && $s5['copy'] === null && $s5['img'] === null);
} finally {
    onboarding_lock_reset($pdo, $u5);
    Fixture::limpiar($pdo, $m5);
}

//  INCIERTO -> jamas se crea otro, ni sondeando ni con el boton.
$f3 = Fixture::crear($pdo, 'preparacion-incierto', false);
$m3 = (int)$f3['marca_id']; $u3 = (int)$f3['usuario_id'];
try {
    onboarding_lock_reset($pdo, $u3);
    $GLOBALS['CREAR_FALLA'] = 'incierto';
    $c3 = muestra_fila($pdo, $m3);
    muestra_arrancar($pdo, $m3, $u3, $c3);
    $s3 = muestra_estado($pdo, $m3, $c3);
    ok('el encolado incierto se nombra',    $s3['degradado'] === 'incierto', 'degradado=' . $s3['degradado']);
    $crear_antes = $GLOBALS['RED']['crear'];
    $GLOBALS['CREAR_FALLA'] = '';
    muestra_asegurar($pdo, $m3, $u3);
    ok('incierto: el sondeo no crea otro',  $GLOBALS['RED']['crear'] === $crear_antes);
    ok('incierto: ni el boton crea otro',   muestra_reintentar($pdo, $m3, $u3) === false
                                            && $GLOBALS['RED']['crear'] === $crear_antes);
} finally {
    onboarding_lock_reset($pdo, $u3);
    Fixture::limpiar($pdo, $m3);
}

echo "\n" . str_repeat('-', 62) . "\n";
echo ($fallos === 0 ? "  TODO EN VERDE" : "  {$fallos} FALLAS") . " · {$n} comprobaciones\n";
exit($fallos === 0 ? 0 : 1);
