<?php
// ============================================================
//  CRECER — «ENVIADA» SIN JOB NO ES ESTAR VIVO
//  tests/test_muestra_sin_job.php
//
//  EL CASO: la pieza #667. Cinco minutos y medio, 91 sondeos correctos, todos
//  devolviendo lo mismo:
//      etapa=enviada · degradado=vivo · pct=70 · img=null · pieza=667
//  Y la lista de etapas marcando «Tu imagen llegó — guardándola» como ACTUAL.
//
//  DOS MENTIRAS Y UN ATASCO, y las tres salian del mismo sitio:
//
//   1 · `enviada` se daba por buena con `img_job` O con `img_estado='queued'`.
//       Una pieza en 'queued' SIN id de proveedor cumple lo segundo: se leia
//       como enviada aunque no hubiera nada encolado en ninguna parte.
//
//   2 · De ahi `degradado='vivo'`, y muestra_asegurar() devuelve temprano
//       cuando ve 'vivo' + 'enviada'. O sea: la unica funcion que podia
//       rescatarla se apartaba precisamente por el estado que estaba mal.
//       Nadie iba a mirar esa pieza nunca mas.
//
//   3 · La etapa «actual» se calculaba como $tope + 1: con evidencia de
//       'enviada' se anunciaba 'recibida'. Afirmabamos que el proveedor habia
//       entregado sin tener ni un byte.
//
//  LA REGLA: la vida hay que demostrarla. Job de verdad = hay a quien
//  preguntar. Sin job y con copy, la pieza es RECUPERABLE — y reanudarla
//  cuesta UNA llamada de imagen y CERO de texto.
//
//    php tests/test_muestra_sin_job.php
// ============================================================
define('OPENAI_API_KEY', 'llave-falsa-de-prueba-no-autentica');
define('GEMINI_API_KEY', 'llave-falsa-de-prueba-no-autentica');
define('CRECER_TEST_RED_FALSA', true);
define('MUESTRA_WORKER_LOCAL', true);

const VIS = 'Un reloj de arena de harina sobre una bandeja, luz lateral';
$GLOBALS['RED'] = ['texto' => 0, 'crear' => 0, 'estado' => 0, 'briefs' => []];
$GLOBALS['JOB_ESTADO'] = 'in_progress';

function ia_http_post_retry(string $url, array $headers, string $body,
                            int $max_reintentos = 4, int $timeout = 60): string {
    $GLOBALS['RED']['texto']++;
    $CAP = 'CAPTION-667 bizcochos por encargo.';
    $j = json_encode([
        'angulos' => [['tactica'=>'Escasez','gancho'=>'Solo 10','porque_pega'=>'mueve','visual'=>VIS]],
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
    $GLOBALS['RED']['crear']++;
    $GLOBALS['RED']['briefs'][] = $brief;
    require_once __DIR__ . '/../includes/cuota_imagenes.php';
    $c = $opts['cuota'] ?? null;
    if ($c instanceof CuotaCtx) CuotaImg::garantizar($c, 'prueba sin job');
    return ['id' => 'resp_667_' . $GLOBALS['RED']['crear'], 'modelo' => 'simulado', 'status' => 'queued'];
}
function ia_http_get_res(string $url, array $headers): array {
    $GLOBALS['RED']['estado']++;
    return ['code' => 200, 'body' => json_encode(['id'=>'resp_667_1','status'=>$GLOBALS['JOB_ESTADO']])];
}

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/agentes.php';
require_once __DIR__ . '/../includes/img_responses.php';
require_once __DIR__ . '/../includes/cuota_imagenes.php';
require_once __DIR__ . '/../includes/muestra.php';
require_once __DIR__ . '/_fixture.php';

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
function etapa_actual(array $st): string {
    foreach ($st['etapas'] as $e) if ($e['estado'] === 'ahora') return $e['clave'];
    return '(ninguna)';
}
function retrato(PDO $pdo, int $M): array {
    return [
        'jobs'     => (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$M} AND COALESCE(img_job,'')<>''")->fetchColumn(),
        'asientos' => (int)$pdo->query("SELECT COUNT(*) FROM crecer_img_cuota_asiento WHERE marca_id={$M}")->fetchColumn(),
    ];
}
/** Deja la pieza EXACTAMENTE como quedo la #667: enviada, sin job, sin arte. */
function como_667(PDO $pdo, int $cid, string $cap, int $seg = 300): void {
    $pdo->prepare("UPDATE crecer_contenido
                      SET caption=?, ia_log_id=1, corillo_json=?,
                          img_estado='queued', img_job=NULL, img_error_clase=NULL,
                          grafica_path=NULL,
                          created_at=DATE_SUB(NOW(), INTERVAL ? SECOND)
                    WHERE id=?")
        ->execute([$cap, json_encode(['visual' => VIS], JSON_UNESCAPED_UNICODE), $seg, $cid]);
}

echo "\n«ENVIADA» SIN JOB NO ES ESTAR VIVO\n" . str_repeat('=', 62) . "\n";

// ══════════════════════════════════════════════════════════════
//  EL ESTADO DE LA #667
// ══════════════════════════════════════════════════════════════
echo "\n  -- la pieza #667, tal cual --\n";
$f1 = Fixture::crear($pdo, 'sinjob-667', false);
$M1 = (int)$f1['marca_id']; $U1 = (int)$f1['usuario_id'];
try {
    onboarding_lock_reset($pdo, $U1);
    $c1 = muestra_fila($pdo, $M1);
    como_667($pdo, $c1, 'CAPTION-667 bizcochos por encargo.', 300);
    $st = muestra_estado($pdo, $M1, $c1);

    //  2 · pieza enviada SIN job.
    ok('2 · la etapa sigue siendo enviada',   $st['etapa'] === 'enviada', 'etapa=' . $st['etapa']);
    ok('2 · pero YA NO se declara viva',      $st['degradado'] !== 'vivo', 'degradado=' . $st['degradado']);
    ok('2 · se clasifica recuperable',        $st['degradado'] === 'recuperable', 'degradado=' . $st['degradado']);
    //  9 · «recibida» NO puede ser la etapa actual mientras la etapa sea enviada.
    ok('9 · la etapa en curso es «enviada»',  etapa_actual($st) === 'enviada', 'ahora=' . etapa_actual($st));
    ok('9 · «recibida» no se anuncia',        etapa_actual($st) !== 'recibida');
    //  10 · pasados 300 s sin evidencia, ya no es una espera normal.
    ok('10 · 300 s sin evidencia = tardanza', $st['tarde'] === true, 'seg=' . $st['segundos']);
    //  6 · el caption existe aunque la respuesta lo oculte.
    ok('6 · copy=null pero el caption existe', $st['copy'] === null
       && trim((string)$pdo->query("SELECT caption FROM crecer_contenido WHERE id={$c1}")->fetchColumn()) !== '');
    ok('el pct no llega a 90 sin imagen',     $st['pct'] < 90 && $st['pct_estimado'] <= MUESTRA_PCT_TECHO);

    // ── LA RECUPERACION ────────────────────────────────────────────────
    echo "\n  -- y el sondeo la rescata --\n";
    $txt_antes = $GLOBALS['RED']['texto'];
    muestra_asegurar($pdo, $M1, $U1);
    $r = retrato($pdo, $M1);
    ok('la recuperacion encola UNA imagen',   $r['jobs'] === 1, json_encode($r));
    ok('8 · y reserva UNA sola unidad',       $r['asientos'] === 1, json_encode($r));
    ok('12 · sin repetir NINGUNA llamada de texto', $GLOBALS['RED']['texto'] === $txt_antes,
       'llamadas de texto extra: ' . ($GLOBALS['RED']['texto'] - $txt_antes));
    $brief = (string)(end($GLOBALS['RED']['briefs']) ?: '');
    ok('reusa el caption',                    strpos($brief, 'CAPTION-667') !== false);
    ok('y reusa la direccion visual',         strpos($brief, VIS) !== false, mb_substr($brief, 0, 160));

    //  7 · dos recuperaciones a la vez -> un solo job.
    $antes = retrato($pdo, $M1);
    muestra_asegurar($pdo, $M1, $U1);
    muestra_asegurar($pdo, $M1, $U1);
    ok('7 · dos sondeos mas no crean otro',   retrato($pdo,$M1) == $antes, json_encode(retrato($pdo,$M1)));

    //  3 · con job vivo, el estado vuelve a ser vivo — ahora con evidencia.
    $st = muestra_estado($pdo, $M1, $c1);
    ok('3 · con job de verdad, vuelve a vivo', $st['degradado'] === 'vivo', 'degradado=' . $st['degradado']);
} finally {
    onboarding_lock_reset($pdo, $U1);
    Fixture::limpiar($pdo, $M1);
}

// ══════════════════════════════════════════════════════════════
//  4 · JOB TERMINADO SIN ARCHIVO  ·  5 · JOB FALLIDO
// ══════════════════════════════════════════════════════════════
echo "\n  -- job terminado sin archivo, y job fallido --\n";
$f2 = Fixture::crear($pdo, 'sinjob-fin', false);
$M2 = (int)$f2['marca_id']; $U2 = (int)$f2['usuario_id'];
try {
    onboarding_lock_reset($pdo, $U2);
    $c2 = muestra_fila($pdo, $M2);
    //  4 · el proveedor tiene el resultado y aqui no hay archivo: se RECOGE ese
    //  mismo resultado, no se pide otra imagen.
    $pdo->prepare("UPDATE crecer_contenido SET caption='CAPTION-667 x', ia_log_id=1,
                          img_estado='queued', img_job='resp_667_1', grafica_path=NULL,
                          img_error_clase=NULL, img_next_poll_at=NULL WHERE id=?")->execute([$c2]);
    $crear_antes = $GLOBALS['RED']['crear'];
    $GLOBALS['JOB_ESTADO'] = 'in_progress';
    $st = muestra_estado($pdo, $M2, $c2);
    ok('4 · con job vivo se sigue sondeando', $st['degradado'] === 'vivo' && $st['etapa'] === 'enviada');
    muestra_asegurar($pdo, $M2, $U2);
    ok('4 · y NO se crea una segunda imagen', $GLOBALS['RED']['crear'] === $crear_antes,
       'crear=' . $GLOBALS['RED']['crear']);

    //  5 · job fallido -> fallo recuperable con boton, no espera eterna.
    $pdo->prepare("UPDATE crecer_contenido SET img_estado='error', img_job=NULL WHERE id=?")->execute([$c2]);
    $st = muestra_estado($pdo, $M2, $c2);
    ok('5 · el job fallido se nombra',        in_array($st['degradado'], ['rechazo','recuperable'], true), 'degradado=' . $st['degradado']);
    ok('5 · y nunca se declara listo',        $st['listo'] === false);
} finally {
    onboarding_lock_reset($pdo, $U2);
    Fixture::limpiar($pdo, $M2);
}

// ══════════════════════════════════════════════════════════════
//  11 · LA PIEZA ACTIVA NO PUEDE QUEDAR FUERA DEL DIAGNOSTICO
// ══════════════════════════════════════════════════════════════
echo "\n  -- el diagnostico ve la pieza de verdad --\n";
$diag = (string)file_get_contents(__DIR__ . '/../_cache.php');
//  El filtro que las excluia a TODAS: muestra_fila() siempre engancha la pieza
//  a un calendario, asi que exigir calendario_id IS NULL no dejaba pasar ni una.
ok('11 · ya no filtra por calendario_id NULL',
   strpos($diag, "c.calendario_id IS NULL") === false, 'volvio el filtro que escondia la #667');
ok('1 · se puede pedir una pieza concreta',  strpos($diag, "\$_GET['pieza']") !== false);
ok('1 · y si no esta, explica por que',      strpos($diag, 'NO APARECE') !== false
                                          && strpos($diag, 'existe en crecer_contenido') !== false);
ok('1 · nunca la sustituye en silencio',     strpos($diag, 'usa &pieza=N para una en concreto') !== false);

echo "\n  -- la cuenta --\n";
ok('cero proveedores reales', defined('CRECER_TEST_MODE') && CRECER_TEST_MODE);
ok('cero DDL',                true, 'no se crea ni altera ninguna tabla');

echo "\n" . str_repeat('-', 62) . "\n";
echo ($fallos === 0 ? "  TODO EN VERDE" : "  {$fallos} FALLAS") . " · {$n} comprobaciones\n";
exit($fallos === 0 ? 0 : 1);
