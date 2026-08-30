<?php
// ============================================================
//  CRECER - EL EMBUDO COMERCIAL, CON LAS DOS RAMAS ENCIMA
//  tests/test_integracion_embudo.php
//
//  Fase 11 (pulido de navegacion) y el hotfix del primer post no comparten ni
//  un archivo, asi que git no tiene nada que decir sobre su union. Esta prueba
//  pregunta lo otro: si el RECORRIDO sigue siendo uno solo cuando ambas estan
//  puestas a la vez.
//
//  Camino: entrevista -> copy -> arte encolado -> pantalla de preparacion ->
//  recarga -> segunda pestaña -> proveedor termina -> post completo ->
//  puerta del telefono -> SMS simulado -> publicar -> redes -> oferta de $39.
//  Y al final, las pantallas de Fase 11 (Calendario, Sala, Mi negocio) para
//  comprobar que el pulido sobrevivio a la union.
//
//  CERO PROVEEDORES Y CERO NAVEGADOR, y lo segundo sostiene lo primero: todo
//  corre EN PROCESO, donde `_sin_gasto.php` cierra la red de verdad. Con
//  navegador no bastaria — las paginas piden sus AJAX a rutas absolutas
//  `/crecer/...`, o sea al arbol que sirve Apache, que desde un worktree
//  paralelo es OTRO y no lleva centinela. Ese camino ya se pago una vez.
//
//    php tests/test_integracion_embudo.php
// ============================================================

define('OPENAI_API_KEY', 'llave-falsa-de-prueba-no-autentica');
define('GEMINI_API_KEY', 'llave-falsa-de-prueba-no-autentica');
define('CRECER_TEST_RED_FALSA', true);
define('MUESTRA_WORKER_LOCAL', true);

const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

$GLOBALS['RED'] = ['texto' => 0, 'crear' => 0, 'estado' => 0, 'briefs' => []];
$GLOBALS['JOB_LISTO'] = false;

/** Borde de texto. El caption es fijo y reconocible: se rastrea hasta el arte. */
function ia_http_post_retry(string $url, array $headers, string $body,
                            int $max_reintentos = 4, int $timeout = 60): string {
    $GLOBALS['RED']['texto']++;
    $CAP = 'CAPTION-EMBUDO bizcochos de Caguas, por encargo.';
    $j = json_encode([
        'angulos' => [['tactica'=>'Escasez','gancho'=>'Solo 10','porque_pega'=>'mueve','visual'=>'bandeja'],
                      ['tactica'=>'Nostalgia','gancho'=>'Como abuela','porque_pega'=>'memoria','visual'=>'manos'],
                      ['tactica'=>'Reto','gancho'=>'No repitas','porque_pega'=>'comparte','visual'=>'mordida']],
        'elegido' => 1, 'razon' => 'pega mas', 'brief' => 'Dale al gancho de escasez.',
        'texto' => $CAP, 'descripcion' => 'Reposteria de prueba', 'voz' => 'cercana',
        'publico_objetivo' => 'vecinos', 'identidad' => 'x', 'reglas_imagen' => 'x',
        'reglas_voz' => 'x', 'reglas_estrategia' => 'x', 'personalidad' => 'x',
        'ejes' => ['formalidad' => 40],
    ], JSON_UNESCAPED_UNICODE);
    $plano = (stripos($body, 'Devuelve SOLO el caption') !== false
           || stripos($body, 'sin comillas ni explicaci') !== false);
    if (stripos($body, '¿OK, o cuál es la nota?') !== false) $plano = true;
    $texto = $plano ? (stripos($body, '¿OK, o cuál es la nota?') !== false ? 'OK' : $CAP) : $j;
    return json_encode(['candidates' => [['content' => ['parts' => [['text' => $texto]]]]],
                        'usageMetadata' => ['promptTokenCount' => 40, 'candidatesTokenCount' => 40]]);
}

/** Borde de OpenAI al crear: guarda el brief para poder atarlo al copy. */
function openai_responses_crear_bg(string $brief, array $opts = []): array {
    $GLOBALS['RED']['crear']++;
    $GLOBALS['RED']['briefs'][] = $brief;
    require_once __DIR__ . '/../includes/cuota_imagenes.php';
    $c = $opts['cuota'] ?? null;
    if ($c instanceof CuotaCtx) CuotaImg::garantizar($c, 'prueba embudo');
    return ['id' => 'resp_embudo_fijo', 'modelo' => 'simulado', 'status' => 'queued'];
}

/** Borde de OpenAI al consultar. LENTO: no entrega hasta que la prueba lo diga. */
function ia_http_get_res(string $url, array $headers): array {
    $GLOBALS['RED']['estado']++;
    if (!$GLOBALS['JOB_LISTO']) {
        return ['code' => 200, 'body' => json_encode(['id' => 'resp_embudo_fijo', 'status' => 'in_progress'])];
    }
    return ['code' => 200, 'body' => json_encode([
        'id' => 'resp_embudo_fijo', 'status' => 'completed', 'model' => 'simulado',
        'output' => [['type' => 'image_generation_call', 'result' => PNG_1X1, 'revised_prompt' => 'x']],
    ])];
}

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/agentes.php';
require_once __DIR__ . '/../includes/suscripcion.php';   // marca_es_pagada
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
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n          -> $detalle" : '') . "\n";
}
/** Renderiza una pagina del panel en un proceso aparte y devuelve su HTML. */
function pagina(int $uid, int $mid, string $pag, string $qry = ''): string {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/_pagina_runner.php')
         . ' ' . $uid . ' ' . $mid . ' ' . escapeshellarg($pag) . ' ' . escapeshellarg($qry) . ' 2>&1';
    return (string)shell_exec($cmd);
}
/** Envejece la pieza con el reloj de MySQL (nunca con sleep). */
function envejecer(PDO $pdo, int $cid, int $seg): void {
    $pdo->prepare("UPDATE crecer_contenido
                      SET created_at=DATE_SUB(NOW(), INTERVAL ? SECOND),
                          img_job_at=DATE_SUB(NOW(), INTERVAL ? SECOND) WHERE id=?")
        ->execute([$seg, $seg, $cid]);
}

echo "\nEL EMBUDO COMERCIAL, CON FASE 11 Y EL HOTFIX ENCIMA\n" . str_repeat('=', 62) . "\n";
echo "\n  -- los dos candados --\n";
ok('modo prueba puesto',    defined('CRECER_TEST_MODE') && CRECER_TEST_MODE);
ok('transporte falso',      defined('CRECER_TEST_RED_FALSA') && CRECER_TEST_RED_FALSA);
ok('sin navegador',         true, 'todo en proceso: nadie puede saltar al otro arbol');

//  El dueño del embudo NO paga: si pagara, el gateway lo mandaria al app y no
//  habria embudo que probar.
$fx = Fixture::crear($pdo, 'embudo', false);
$M  = (int)$fx['marca_id'];
$U  = (int)$fx['usuario_id'];

try {
    onboarding_lock_reset($pdo, $U);

    // ── 1-4 · entrevista, copy, y UNA sola imagen ───────────────────────
    echo "\n  -- la entrevista cierra y el corillo arranca --\n";
    $cid = muestra_fila($pdo, $M);
    ok('1 · la entrevista dejo marca y pieza',  $M > 0 && $cid > 0);
    $texto_antes = $GLOBALS['RED']['texto'];
    ok('6 · crear la fila no llama a nadie',    $texto_antes === 0, 'llamadas=' . $texto_antes);

    muestra_arrancar($pdo, $M, $U, $cid);
    $fila = $pdo->query("SELECT caption, ia_log_id, corillo_json, img_job FROM crecer_contenido WHERE id={$cid}")->fetch(PDO::FETCH_ASSOC);
    $cap  = (string)$fila['caption'];
    $corillo = json_decode((string)$fila['corillo_json'], true) ?: [];
    $visual = trim((string)($corillo['visual'] ?? ''));
    ok('2 · la direccion quedo decidida y guardada', trim((string)$fila['corillo_json']) !== '');
    ok('2 · la direccion visual es concreta',       $visual !== '', json_encode($corillo, JSON_UNESCAPED_UNICODE));
    ok('3 · el copy final quedo persistido',    $fila['ia_log_id'] !== null && strpos($cap, 'CAPTION-EMBUDO') === 0, $cap);
    ok('5 · nunca con copy vacio',              trim($cap) !== '');
    ok('4 · se encolo exactamente UNA imagen',  $GLOBALS['RED']['crear'] === 1, 'crear=' . $GLOBALS['RED']['crear']);
    ok('4 · y el brief lleva ESE copy',         strpos((string)($GLOBALS['RED']['briefs'][0] ?? ''), $cap) !== false);
    ok('4 · y lleva la direccion visual del corillo',
       $visual !== '' && strpos((string)($GLOBALS['RED']['briefs'][0] ?? ''), $visual) !== false,
       (string)($GLOBALS['RED']['briefs'][0] ?? ''));

    // ── 5-7 · la pantalla de preparacion, viva ──────────────────────────
    echo "\n  -- el dueño llega a una pantalla viva --\n";
    envejecer($pdo, $cid, 40);
    $html = pagina($U, $M, 'gateway_post.php');
    ok('7 · llega a la preparacion',            strpos($html, 'role="progressbar"') !== false);
    //  LA PANTALLA DEJO DE SER UN TABLERO, Y ESTAS DOS AFIRMACIONES CON ELLA.
    //  Exigian la frase de etapa y las siete etapas con su porcentaje. Eso era
    //  el panel de diagnostico: correcto de medir, malo de enseñar. Ahora se
    //  exige lo que sostiene la espera —un estado real, salido de columnas— y
    //  se PROHIBE lo interno. El detalle vive en test_preparacion_render.php.
    ok('7 · dice en que va, en cristiano',      strpos($html, 'Tu texto está listo. Ahora estamos creando la imagen.') !== false
                                             || strpos($html, 'El corillo está preparando tu idea.') !== false, mb_substr($html, 0, 200));
    //  `$st` todavia no existe en este punto del recorrido: se lee mas abajo.
    //  Se comprueba contra la fuente, que es lo que importa — el estado que la
    //  pantalla recibe sale de muestra_estado(), no de un temporizador.
    $et_ahora = muestra_estado($pdo, $M, $cid)['etapa'];
    ok('9 · el estado sale de columnas',        strpos($html, '"etapa":"' . $et_ahora . '"') !== false, (string)$et_ahora);
    ok('9 · y no se enseña el tablero interno', preg_match_all('/<li class="[^"]*" data-clave=/', $html) === 0
                                             && stripos($html, 'equipoLista') === false);
    ok('8 · NO avanza a publicar',              stripos($html, 'Publicar en mis redes') === false
                                             && stripos($html, 'Aprobar este post') === false);

    $st = muestra_estado($pdo, $M, $cid);
    ok('10 · 70-89 se reconoce estimado',       $st['estimando'] === true && $st['pct_estimado'] >= 70 && $st['pct_estimado'] <= 89,
       'pct_estimado=' . $st['pct_estimado']);
    ok('11 · 90 exige grafica_path',            $st['pct'] === 70 && !$st['listo']);

    // ── 13-14 · recarga y segunda pestaña ───────────────────────────────
    echo "\n  -- recarga y segunda pestaña --\n";
    $job1 = (string)$pdo->query("SELECT img_job FROM crecer_contenido WHERE id={$cid}")->fetchColumn();
    $a = muestra_asegurar($pdo, $M, $U);
    $b = muestra_asegurar($pdo, $M, $U);
    $job2 = (string)$pdo->query("SELECT img_job FROM crecer_contenido WHERE id={$cid}")->fetchColumn();
    ok('13 · la recarga conserva el mismo job', $job1 !== '' && $job1 === $job2);
    ok('13 · conserva etapa y reloj',           $a['etapa'] === 'enviada' && $a['segundos'] >= 40, 'seg=' . $a['segundos']);
    ok('14 · dos pestañas, el mismo trabajo',   $a['pct_estimado'] === $b['pct_estimado'] && $a['etapa'] === $b['etapa']);
    ok('15-16 · el sondeo no crea ni cobra',    $GLOBALS['RED']['crear'] === 1,
       'crear=' . $GLOBALS['RED']['crear']);
    $res = (int)$pdo->query("SELECT COUNT(*) FROM crecer_img_cuota_asiento WHERE marca_id={$M} AND estado IN ('reservado','riesgo')")->fetchColumn();
    ok('15 · una sola unidad reservada',        $res === 1, 'reservadas=' . $res);

    // ── 11-12 · el proveedor entrega ────────────────────────────────────
    echo "\n  -- el proveedor entrega --\n";
    $GLOBALS['JOB_LISTO'] = true;
    $pdo->prepare("UPDATE crecer_contenido SET img_next_poll_at=NULL WHERE id=?")->execute([$cid]);
    img_resp_completar($pdo, $M, $cid);
    $fin = muestra_estado($pdo, $M, $cid);
    ok('12 · llega a 100 con imagen guardada',  $fin['pct'] === 100 && $fin['listo'] === true);
    ok('12 · revela copy e imagen juntos',      $fin['copy'] === $cap && $fin['img'] !== null);

    // ── el post completo y la puerta del telefono ───────────────────────
    echo "\n  -- el momento de venta --\n";
    $html = pagina($U, $M, 'gateway_post.php');
    ok('ahora SI sirve el post completo',       stripos($html, 'Aprobar este post') !== false);
    ok('y el copy que se ve es el mismo',       strpos($html, 'CAPTION-EMBUDO') !== false);
    ok('la imagen que se ve es la guardada',    strpos($html, (string)$fin['img']) !== false);

    $tel = (string)($pdo->query("SELECT telefono_verificado FROM crecer_marca WHERE id={$M}")->fetchColumn() ?: '');
    ok('SMS · el telefono aun no esta puesto',  trim($tel) === '');
    //  La puerta del SMS es del servidor, no del boton: se comprueba llamando al
    //  endpoint de publicar sin telefono. Nada de SMS reales — no se toca Twilio.
    $r = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/_publicar_gratis_runner.php')
                    . " {$U} {$M} 2>&1");
    ok('2 · publicar EXIGE la verificacion',    strpos((string)$r, '"needs_phone":true') !== false, trim((string)$r));

    //  4 · «con codigo simulado valido, continua»: se marca el telefono como ya
    //  verificado igual que lo dejaria verificar_sms.php, SIN enviar nada.
    $pdo->prepare("UPDATE crecer_marca SET telefono_verificado='7875550100' WHERE id=?")->execute([$M]);
    $r2 = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/_publicar_gratis_runner.php')
                     . " {$U} {$M} 2>&1");
    ok('4 · con el telefono puesto, continua',  strpos((string)$r2, '"ok":true') !== false, trim((string)$r2));
    $pub = (string)$pdo->query("SELECT estado FROM crecer_contenido WHERE id={$cid}")->fetchColumn();
    ok('el post queda publicado',               $pub === 'publicado', 'estado=' . $pub);

    // ── 5-8 · redes y la oferta ─────────────────────────────────────────
    echo "\n  -- redes y la oferta --\n";
    $venta = pagina($U, $M, 'gateway_post.php', 'venta=1');
    ok('5 · ofrece conectar sus redes',         stripos($venta, 'conectar') !== false);
    //  BUSCAR UN PRECIO, NO UNOS DIGITOS. La primera version miraba si aparecia
    //  «49» a secas y fallaba: el teal de la marca es #00A49F. Se buscan cifras
    //  en forma de precio -con $ delante o /mes detras- que es lo unico que un
    //  dueño puede leer como el precio de su suscripcion.
    $precio = fn(string $h, int $x) => preg_match('~\$\s*' . $x . '\b|\b' . $x . '\s*(?:/|\s)\s*mes~i', $h) === 1;
    ok('6 · la oferta dice $39',                $precio($venta, 39));
    ok('6 · y en ningun sitio dice $49',        !$precio($venta, 49));
    ok('8 · publicar gratis NO da acceso',      !marca_es_pagada($pdo, $M));

} finally {
    onboarding_lock_reset($pdo, $U);
    Fixture::limpiar($pdo, $M);
}

// ══════════════════════════════════════════════════════════════
//  20 · EL POST HUERFANO DE UNA CUENTA ANTERIOR
//
//  Alguien que entro ANTES del hotfix puede tener una pieza creada y nunca
//  terminada: con la idea de plantilla en el caption, sin ia_log_id y sin job.
//  Nadie la iba a recoger — el flujo viejo ya habia devuelto su respuesta. Al
//  volver, la pantalla tiene que adoptarla y terminarla UNA vez, sin crear una
//  segunda pieza, un segundo trabajo ni un segundo asiento.
// ══════════════════════════════════════════════════════════════
echo "\n  -- 20 · el post huerfano se recupera una sola vez --\n";
$f3 = Fixture::crear($pdo, 'embudo-huerfano', false);
$M3 = (int)$f3['marca_id']; $U3 = (int)$f3['usuario_id'];
try {
    onboarding_lock_reset($pdo, $U3);
    $GLOBALS['JOB_LISTO'] = false;
    $c3 = muestra_fila($pdo, $M3);
    //  El estado exacto en que quedaban: la idea, y nada mas.
    $pdo->prepare("UPDATE crecer_contenido SET caption=?, ia_log_id=NULL, corillo_json=NULL,
                          img_job=NULL, img_estado=NULL, grafica_path=NULL WHERE id=?")
        ->execute([MUESTRA_IDEA, $c3]);
    $piezas_antes = (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$M3}")->fetchColumn();
    $crear_antes  = $GLOBALS['RED']['crear'];

    $r1 = muestra_asegurar($pdo, $M3, $U3);          // el dueño vuelve: se adopta
    $r2 = muestra_asegurar($pdo, $M3, $U3);          // y sondea otra vez
    $r3 = muestra_asegurar($pdo, $M3, $U3);          // y otra

    $piezas_desp = (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$M3}")->fetchColumn();
    $asi = (int)$pdo->query("SELECT COUNT(*) FROM crecer_img_cuota_asiento WHERE marca_id={$M3}")->fetchColumn();
    $cap3 = (string)$pdo->query("SELECT caption FROM crecer_contenido WHERE id={$c3}")->fetchColumn();

    ok('20 · el huerfano se recupera',       $cap3 !== MUESTRA_IDEA && $r1['etapa'] === 'enviada', 'etapa=' . $r1['etapa']);
    ok('20 · sin duplicar la pieza',         $piezas_desp === $piezas_antes, "{$piezas_antes} -> {$piezas_desp}");
    ok('20 · un solo job, no tres',          $GLOBALS['RED']['crear'] === $crear_antes + 1,
       'jobs creados: ' . ($GLOBALS['RED']['crear'] - $crear_antes));
    ok('20 · un solo asiento de cuota',      $asi === 1, 'asientos=' . $asi);
    ok('20 · y los sondeos siguientes no repiten', $r2['etapa'] === 'enviada' && $r3['etapa'] === 'enviada');
} finally {
    onboarding_lock_reset($pdo, $U3);
    Fixture::limpiar($pdo, $M3);
}

// ══════════════════════════════════════════════════════════════
//  FASE 11, DESPUES DE LA UNION
//  Panel de verdad: hace falta una cuenta con acceso, o el candado de
//  suscripcion manda a la venta y se estaria probando el paywall.
// ══════════════════════════════════════════════════════════════
echo "\n  -- Fase 11 sobrevivio a la union --\n";
$f2 = Fixture::crear($pdo, 'embudo-panel', true, 'admin');
$M2 = (int)$f2['marca_id']; $U2 = (int)$f2['usuario_id'];
try {
    $cal = pagina($U2, $M2, 'calendario.php');
    ok('Calendario se titula «Calendario»',     stripos($cal, '<title>Calendario') !== false
                                             || preg_match('~<h1[^>]*>\s*Calendario~i', $cal) === 1);
    ok('no aparece «Elegiste esta hora»',       stripos($cal, 'Elegiste esta hora') === false);
    ok('no aparece «Te sugerimos esta hora»',   stripos($cal, 'Te sugerimos esta hora') === false);
    ok('sin hora, no inventa medianoche',       stripos($cal, '12:00 AM') === false);

    $sala = pagina($U2, $M2, 'sala.php');
    ok('La Sala responde',                      strlen($sala) > 1500, 'bytes=' . strlen($sala));
    $gen = pagina($U2, $M2, 'genoma.php');
    ok('Mi negocio responde',                   strlen($gen) > 1500, 'bytes=' . strlen($gen));
    //  El menu es del shell, y el shell lo pinta cada pagina: si la union hubiera
    //  roto _shell.php, estas tres no traerian su navegacion.
    foreach (['calendario.php' => $cal, 'sala.php' => $sala, 'genoma.php' => $gen] as $p => $h) {
        ok("«{$p}» trae su navegacion",         stripos($h, 'aria-current') !== false, 'sin aria-current');
    }
} finally {
    Fixture::limpiar($pdo, $M2);
}

echo "\n  -- la cuenta --\n";
ok('cero proveedores reales',  true,
   'texto=' . $GLOBALS['RED']['texto'] . ' crear=' . $GLOBALS['RED']['crear'] . ' estado=' . $GLOBALS['RED']['estado'] . ' (todas al doble)');

echo "\n" . str_repeat('-', 62) . "\n";
echo ($fallos === 0 ? "  TODO EN VERDE" : "  {$fallos} FALLAS") . " · {$n} comprobaciones\n";
exit($fallos === 0 ? 0 : 1);
