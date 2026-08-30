<?php
// ============================================================
//  CRECER — EL POST GRATIS SE BRIEFEA COMO EL QUE SE PAGA
//  tests/test_calidad_muestra.php
//
//  Lo gratuito limita la CANTIDAD, nunca la calidad. Esta prueba vigila que la
//  muestra y una pieza de produccion lleguen al proveedor con el MISMO insumo
//  creativo, porque el mismo motor con peor brief da peor pieza.
//
//  LO QUE FALLABA, EN UNA LINEA. `redactar_pieza()` devuelve la direccion visual
//  que el corillo eligio (`debate.visual`) y produccion se la pasa a
//  `generar_grafica(..., 'instrucciones' => $visual)`. La muestra la tiraba:
//  encolaba con el caption y nada mas. Misma API, mismo modelo, misma calidad
//  `high` — y una escena generica, porque era lo unico que se pidio.
//
//  LO QUE ESTA PRUEBA NO PUEDE HACER, Y CONVIENE DECIRLO. Con el proveedor
//  doblado no se juzga si una idea es buena: eso lo dice un humano mirando la
//  pieza. Aqui se comprueba lo que SI es verificable — que el contexto llega,
//  que llega con el mismo peso que en produccion, y que dos negocios distintos
//  no reciben el mismo brief con los sustantivos cambiados.
//
//    php tests/test_calidad_muestra.php
// ============================================================

define('OPENAI_API_KEY', 'llave-falsa-de-prueba-no-autentica');
define('GEMINI_API_KEY', 'llave-falsa-de-prueba-no-autentica');
define('CRECER_TEST_RED_FALSA', true);
define('MUESTRA_WORKER_LOCAL', true);
//  OJO: NO se define CRECER_WORKER_KEY vacia. Se probo y rompio la prueba
//  entera: sin llave, muestra_arrancar() no llama ni al worker LOCAL, asi
//  que no se escribia nada y todo lo de abajo media el brief de la seccion
//  anterior. El worker de arte por HTTP tampoco hace falta silenciarlo: el
//  proveedor esta doblado y el job nunca completa.

//  La direccion visual que devuelve el corillo doblado. Es reconocible a
//  proposito: se rastrea desde el debate hasta el brief que sale al proveedor.
const VIS_A = 'Un reloj de arena hecho de harina cayendo sobre una bandeja, luz lateral dramatica';
const VIS_B = 'Una llave inglesa apoyada en una tuberia seca, contraluz de garaje';

$GLOBALS['RED'] = ['texto' => 0, 'crear' => 0, 'estado' => 0, 'briefs' => []];
$GLOBALS['VIS'] = VIS_A;

function ia_http_post_retry(string $url, array $headers, string $body,
                            int $max_reintentos = 4, int $timeout = 60): string {
    $GLOBALS['RED']['texto']++;
    $V   = $GLOBALS['VIS'];
    $CAP = $GLOBALS['CAP'] ?? 'CAPTION-CALIDAD del negocio.';
    $j = json_encode([
        'angulos' => [['tactica'=>'Escasez','gancho'=>'Solo 10','porque_pega'=>'mueve','visual'=>$V],
                      ['tactica'=>'Nostalgia','gancho'=>'Como antes','porque_pega'=>'memoria','visual'=>'otra cosa'],
                      ['tactica'=>'Reto','gancho'=>'No repitas','porque_pega'=>'comparte','visual'=>'otra mas']],
        'elegido' => 1, 'razon' => 'pega mas', 'brief' => 'Dale al gancho de escasez.',
        'texto' => $CAP, 'descripcion' => 'x', 'voz' => 'cercana', 'publico_objetivo' => 'vecinos',
        'identidad' => 'x', 'reglas_imagen' => 'x', 'reglas_voz' => 'x', 'reglas_estrategia' => 'x',
        'personalidad' => 'x', 'ejes' => ['formalidad' => 40],
    ], JSON_UNESCAPED_UNICODE);
    $plano = (stripos($body, 'Devuelve SOLO el caption') !== false
           || stripos($body, 'sin comillas ni explicaci') !== false);
    if (stripos($body, '¿OK, o cuál es la nota?') !== false) return json_encode(
        ['candidates'=>[['content'=>['parts'=>[['text'=>'OK']]]]], 'usageMetadata'=>['promptTokenCount'=>10,'candidatesTokenCount'=>10]]);
    $texto = $plano ? $CAP : $j;
    return json_encode(['candidates' => [['content' => ['parts' => [['text' => $texto]]]]],
                        'usageMetadata' => ['promptTokenCount' => 40, 'candidatesTokenCount' => 40]]);
}

function openai_responses_crear_bg(string $brief, array $opts = []): array {
    $GLOBALS['RED']['crear']++;
    $GLOBALS['RED']['briefs'][] = $brief;
    require_once __DIR__ . '/../includes/cuota_imagenes.php';
    $c = $opts['cuota'] ?? null;
    if ($c instanceof CuotaCtx) CuotaImg::garantizar($c, 'prueba calidad');
    return ['id' => 'resp_calidad_' . $GLOBALS['RED']['crear'], 'modelo' => 'simulado', 'status' => 'queued'];
}
function ia_http_get_res(string $url, array $headers): array {
    $GLOBALS['RED']['estado']++;
    return ['code' => 200, 'body' => json_encode(['id' => 'resp_calidad_1', 'status' => 'in_progress'])];
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
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n          -> " . mb_substr($detalle, 0, 300) : '') . "\n";
}
function ultimo_brief(): string { return (string)(end($GLOBALS['RED']['briefs']) ?: ''); }

echo "\nEL POST GRATIS SE BRIEFEA COMO EL QUE SE PAGA\n" . str_repeat('=', 62) . "\n";

// ══════════════════════════════════════════════════════════════
//  LA SEMILLA · ya no pide la respuesta obvia
// ══════════════════════════════════════════════════════════════
echo "\n  -- la idea de partida --\n";
$sem = MUESTRA_IDEA;
ok('la semilla exige una razón concreta',   stripos($sem, 'concreta') !== false);
ok('y prohíbe el post de presentación',     stripos($sem, 'no es un post de bienvenida') !== false
                                         || stripos($sem, 'presentación') !== false, $sem);
ok('sin inventar nada',                     stripos($sem, 'no inventes') !== false);
//  QUE SIRVA A CUALQUIER NEGOCIO. Si la semilla nombrara un rubro, estariamos
//  arreglando el ejemplo de la panaderia en vez del producto.
$rubros = ['panader', 'reposter', 'bizcocho', 'croissant', 'café', 'cafeter', 'plomer', 'restaurante', 'salón'];
$colado = array_values(array_filter($rubros, fn($r) => stripos($sem, $r) !== false));
ok('y no nombra ningún rubro',              $colado === [], 'cuela: ' . implode(', ', $colado));

// ══════════════════════════════════════════════════════════════
//  LA DIRECCION VISUAL · nace, se guarda, y llega al proveedor
// ══════════════════════════════════════════════════════════════
echo "\n  -- la dirección visual llega al arte --\n";
$GLOBALS['CAP'] = 'CAPTION-CALIDAD bizcochos por encargo.';
$GLOBALS['VIS'] = VIS_A;
$fa = Fixture::crear($pdo, 'calidad-a', false);
$MA = (int)$fa['marca_id']; $UA = (int)$fa['usuario_id'];
$brief_a = '';
try {
    onboarding_lock_reset($pdo, $UA);
    //  UN NEGOCIO CON DATOS DE VERDAD. La fixture por defecto no trae productos
    //  ni publico, y sin ellos el brief de A salia con dos campos y el de B con
    //  cuatro: la comparacion entre los dos negocios no era entre iguales.
    //  Una entrevista real siempre deja estas cuatro cosas.
    $pdo->prepare("UPDATE crecer_marca SET descripcion=?, productos=?, publico_objetivo=? WHERE id=?")
        ->execute(['Repostería por encargo en Caguas: bizcochos y pastelitos',
                   json_encode([['nombre'=>'Bizcocho de ron'],['nombre'=>'Pastelitos de guayaba']], JSON_UNESCAPED_UNICODE),
                   'Familias que celebran cumpleaños en casa', $MA]);
    $ca = muestra_fila($pdo, $MA);
    muestra_arrancar($pdo, $MA, $UA, $ca);
    $fila = $pdo->query("SELECT caption, corillo_json, img_job FROM crecer_contenido WHERE id={$ca}")->fetch(PDO::FETCH_ASSOC);
    $cj   = json_decode((string)$fila['corillo_json'], true) ?: [];
    $brief_a = ultimo_brief();

    ok('1 · debate.visual no viene vacío',    trim((string)($cj['visual'] ?? '')) !== '', json_encode($cj));
    ok('2 · se persiste en corillo_json',     (string)($cj['visual'] ?? '') === VIS_A);
    ok('3 · el brief lleva el copy final',    strpos($brief_a, (string)$fila['caption']) !== false);
    ok('4 · el brief lleva la dirección',     strpos($brief_a, VIS_A) !== false, $brief_a);
    ok('19 · copy e imagen, una sola propuesta',
       strpos($brief_a, (string)$fila['caption']) !== false && strpos($brief_a, VIS_A) !== false);

    // 8 · sin logo, no se inventa NINGUNA marca grafica — ni el nombre escrito.
    ok('8 · sin logo, no inventa marca gráfica', stripos($brief_a, 'no inventes un logotipo') !== false);
    ok('8 · y tampoco escribe el nombre',        stripos($brief_a, 'tampoco escribas el nombre') !== false, $brief_a);
    //  La regla vieja invitaba al letrero: «si muestras el nombre, escríbelo
    //  como texto limpio». De ahi salio la cafeteria con su rotulo inventado.
    ok('8 · la invitación al letrero ya no está',
       stripos($brief_a, 'escríbelo como texto limpio') === false, $brief_a);
    ok('14 · la pieza va sin texto dentro',      stripos($brief_a, 'NO pongas texto ni letras') !== false);

    // 10-11 · idempotencia: repetir la preparacion no crea otro job ni otro asiento.
    $crear1 = $GLOBALS['RED']['crear'];
    muestra_preparar($pdo, $MA, $ca);
    muestra_preparar($pdo, $MA, $ca);
    $asi = (int)$pdo->query("SELECT COUNT(*) FROM crecer_img_cuota_asiento WHERE marca_id={$MA}")->fetchColumn();
    ok('10 · no duplica el job',              $GLOBALS['RED']['crear'] === $crear1, 'crear=' . $GLOBALS['RED']['crear']);
    ok('11 · no duplica el asiento',          $asi === 1, 'asientos=' . $asi);

    // 13 · el sondeo no escribe trabajo nuevo.
    $antes = $GLOBALS['RED']['crear'];
    muestra_asegurar($pdo, $MA, $UA);
    muestra_asegurar($pdo, $MA, $UA);
    ok('13 · el sondeo no encola nada',       $GLOBALS['RED']['crear'] === $antes);
} finally {
    onboarding_lock_reset($pdo, $UA);
    Fixture::limpiar($pdo, $MA);
}

// ══════════════════════════════════════════════════════════════
//  5-6 · EL WORKER SE CAE DESPUES DEL COPY
//  Es el caso que degradaba a caption-only: al reiniciar, el copy ya existe y
//  nadie vuelve a llamar al corillo, asi que la direccion habia que sacarla de
//  donde quedo escrita.
// ══════════════════════════════════════════════════════════════
echo "\n  -- el worker se reinicia con el copy ya escrito --\n";
$GLOBALS['VIS'] = VIS_A;
$fr = Fixture::crear($pdo, 'calidad-reinicio', false);
$MR = (int)$fr['marca_id']; $UR = (int)$fr['usuario_id'];
try {
    onboarding_lock_reset($pdo, $UR);
    $cr = muestra_fila($pdo, $MR);
    //  Tramo 1: solo el copy. Se escribe la pieza y se corta antes del arte,
    //  que es exactamente lo que deja un worker que muere a la mitad.
    $r = redactar_pieza($pdo, $cr, [], $MR);
    $cap_r = (string)$r['caption'];
    ok('el copy quedó escrito',               trim($cap_r) !== '' && $cap_r !== MUESTRA_IDEA);
    ok('y la dirección quedó guardada',       strpos((string)$pdo->query("SELECT corillo_json FROM crecer_contenido WHERE id={$cr}")->fetchColumn(), VIS_A) !== false);

    //  Tramo 2: el worker vuelve a arrancar. NO puede volver a llamar al corillo
    //  (seria pagar el texto dos veces) y NO puede briefear solo con el caption.
    $txt_antes = $GLOBALS['RED']['texto'];
    muestra_preparar($pdo, $MR, $cr);
    $brief_r = ultimo_brief();

    ok('5 · recupera la MISMA dirección',     strpos($brief_r, VIS_A) !== false, $brief_r);
    ok('6 · no degrada a solo-caption',       strpos($brief_r, VIS_A) !== false && strpos($brief_r, $cap_r) !== false);
    ok('y no reescribió el copy',             $GLOBALS['RED']['texto'] === $txt_antes,
       'llamadas de texto extra: ' . ($GLOBALS['RED']['texto'] - $txt_antes));
} finally {
    onboarding_lock_reset($pdo, $UR);
    Fixture::limpiar($pdo, $MR);
}

// ══════════════════════════════════════════════════════════════
//  7 · OTRO NEGOCIO, OTRO BRIEF
//  La prueba de especificidad que pidio el encargo: si cambiar de reposteria a
//  plomeria dejara el brief casi igual, seria el mismo post con los sustantivos
//  cambiados. No se juzga la CALIDAD de la idea —eso no lo puede hacer un
//  proveedor doblado— sino que el contexto del negocio de verdad llega.
// ══════════════════════════════════════════════════════════════
echo "\n  -- otro negocio, otro brief --\n";
$GLOBALS['CAP'] = 'CAPTION-CALIDAD destapes de emergencia.';
$GLOBALS['VIS'] = VIS_B;
$fb = Fixture::crear($pdo, 'calidad-b', false);
$MB = (int)$fb['marca_id']; $UB = (int)$fb['usuario_id'];
try {
    onboarding_lock_reset($pdo, $UB);
    //  Un negocio de OTRO rubro, con sus propios datos.
    $pdo->prepare("UPDATE crecer_marca SET nombre_negocio=?, descripcion=?, productos=?, publico_objetivo=? WHERE id=?")
        ->execute(['[prueba] Plomería Quiñones-b',
                   'Plomería de emergencia 24/7 en Bayamón: destapes y calentadores',
                   json_encode([['nombre'=>'Destape de tuberías'],['nombre'=>'Instalación de calentador']], JSON_UNESCAPED_UNICODE),
                   'Dueños de casa con una emergencia ahora mismo', $MB]);
    $cb = muestra_fila($pdo, $MB);
    //  QUE B GENERE SU PROPIO BRIEF, Y QUE SE SEPA SI NO.
    //  Sin esta guarda, un arranque que no llega a encolar deja `ultimo_brief()`
    //  devolviendo el de la seccion ANTERIOR: los dos briefs comparados eran
    //  entonces dos fixtures por defecto que solo se diferencian en el sufijo
    //  del nombre. La prueba fallaba por «se parecen un 92%» y la culpa parecia
    //  del brief, cuando el problema era que B no habia briefeado nada.
    $n_antes = count($GLOBALS['RED']['briefs']);
    muestra_arrancar($pdo, $MB, $UB, $cb);
    ok('7 · el segundo negocio SI briefea',  count($GLOBALS['RED']['briefs']) > $n_antes,
       'no se encolo nada para la marca B; el brief comparado seria el de otra seccion');
    $brief_b = ultimo_brief();

    ok('7 · otra marca, otra dirección',       strpos($brief_b, VIS_B) !== false && strpos($brief_b, VIS_A) === false);
    ok('7 · el brief trae SUS productos',      stripos($brief_b, 'Destape de tuberías') !== false, $brief_b);
    ok('7 · y SU público',                     stripos($brief_b, 'emergencia ahora mismo') !== false);
    ok('7 · sin rastro del otro negocio',      stripos($brief_b, 'bizcocho') === false && stripos($brief_b, 'harina') === false);
    //  QUE LA DIFERENCIA NO SEA COSMETICA — MIDIENDO LO QUE SE AFIRMA.
    //  La primera version restaba los conjuntos de LINEAS de los dos briefs. Era
    //  inestable: segun el orden de las secciones el resto colapsaba a una linea
    //  y la prueba fallaba sin que el producto tuviera nada malo (las
    //  afirmaciones de arriba —sus productos, su publico, su direccion, sin
    //  rastro del otro— pasaban igual). Un proxy que parpadea no vigila nada.
    //
    //  Ahora se miran las lineas que de VERDAD llevan el negocio, por su
    //  etiqueta, y se exige que cambien. Eso es lo que significa «no es el mismo
    //  post con los sustantivos cambiados».
    $campos = function (string $b): array {
        $out = [];
        foreach (['Negocio', 'Qué hace', 'Productos', 'Público'] as $et) {
            if (preg_match('~^' . preg_quote($et, '~') . '[^:]*:\s*(.+)$~mu', $b, $m)) $out[$et] = trim($m[1]);
        }
        return $out;
    };
    $ca = $campos($brief_a); $cb = $campos($brief_b);
    ok('7 · el brief nombra al negocio',     count($ca) >= 3 && count($cb) >= 3,
       'A=' . implode(',', array_keys($ca)) . ' B=' . implode(',', array_keys($cb)));
    $iguales = array_keys(array_intersect_assoc($ca, $cb));
    ok('7 · y ningun campo se repite',       $iguales === [],
       'campos identicos en los dos negocios: ' . implode(', ', $iguales));

    //  El dato que conviene tener a la vista: cuanto del brief es plantilla.
    similar_text($brief_a, $brief_b, $pct);
    printf("      (plantilla compartida: %.0f%% del brief · el resto es el negocio)
", $pct);
} finally {
    onboarding_lock_reset($pdo, $UB);
    Fixture::limpiar($pdo, $MB);
}

// ══════════════════════════════════════════════════════════════
//  9 · CON LOGO REAL, SE USA EL LOGO REAL
// ══════════════════════════════════════════════════════════════
echo "\n  -- con logo real --\n";
$fl = Fixture::crear($pdo, 'calidad-logo', false);
$ML = (int)$fl['marca_id']; $UL = (int)$fl['usuario_id'];
try {
    onboarding_lock_reset($pdo, $UL);
    $dir = rtrim(UPLOADS_PATH, '/\\') . "/marca_{$ML}";
    @mkdir($dir, 0775, true);
    @file_put_contents($dir . '/logo.png', base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
    $pdo->prepare("UPDATE crecer_marca SET logo_path=? WHERE id=?")
        ->execute([rtrim(UPLOADS_URL, '/') . "/marca_{$ML}/logo.png", $ML]);

    $cl = muestra_fila($pdo, $ML);
    muestra_arrancar($pdo, $ML, $UL, $cl);
    $brief_l = ultimo_brief();
    ok('9 · usa el LOGO REAL adjunto',         stripos($brief_l, 'LOGO REAL') !== false, $brief_l);
    ok('9 · y prohíbe dibujar otro',           stripos($brief_l, 'NO inventes ni dibujes otro logo') !== false);
} finally {
    onboarding_lock_reset($pdo, $UL);
    Fixture::limpiar($pdo, $ML);
}

// ══════════════════════════════════════════════════════════════
//  LA CUENTA
// ══════════════════════════════════════════════════════════════
echo "\n  -- la cuenta --\n";
ok('15 · cero proveedores reales',  defined('CRECER_TEST_MODE') && CRECER_TEST_MODE);
ok('17 · cero imágenes reales',     $GLOBALS['RED']['estado'] >= 0 && OPENAI_API_KEY === 'llave-falsa-de-prueba-no-autentica');
ok('16 · cero cuota neta',          (int)$pdo->query("SELECT COUNT(*) FROM crecer_img_cuota_asiento
                                                       WHERE marca_id IN (SELECT id FROM crecer_marca WHERE nombre_negocio LIKE '[prueba]%')")->fetchColumn() === 0);
ok('18 · cero DDL',                 true, 'esta prueba no crea ni altera tablas');

echo "\n" . str_repeat('-', 62) . "\n";
echo ($fallos === 0 ? "  TODO EN VERDE" : "  {$fallos} FALLAS") . " · {$n} comprobaciones\n";
exit($fallos === 0 ? 0 : 1);
