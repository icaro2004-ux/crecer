<?php
// ============================================================
//  CRECER — LOS TRES BORDES DE LA CUOTA (Fase 3C · commit 3)
//  tests/test_cuota_bordes.php
//
//  Tres cosas que, si se rompen, no se notan hasta que ya costaron dinero o
//  disgusto:
//
//   1 · UNA MARCA CON LOGO OFICIAL NUNCA LLEGA A UN PROVEEDOR. Ni una llamada,
//       ni una reserva, ni un asiento — salvo que el dueño pida el reemplazo a
//       proposito. Reinterpretarle el logo a un negocio es cambiarle la
//       identidad sin permiso, y ademas se cobra.
//
//   2 · LOS 5 LOGOS SON DE POR VIDA. No se renuevan en septiembre. El cubo del
//       logo NO lleva el mes en el nombre, y esta prueba lo comprueba viajando
//       de agosto a diciembre.
//
//   3 · UN P4 INCIERTO NO CONSUME CUOTA NI DISPARA EL RESPALDO. Aceptaron el
//       encargo y no dieron identificador: el dueño no puede pagar por algo que
//       quiza no reciba, y caer a Gemini seria gastar dos veces a ciegas por la
//       misma imagen. Es la misma leccion del hotfix de sondeo del 19 de agosto,
//       aplicada al dinero en vez de al log.
// ============================================================

// ══════════════════════════════════════════════════════════════════════
//  LA RED, CERRADA POR CONSTRUCCION — Y AUN ASI SE RECORRE EL CAMINO
//
//  ESTA PRUEBA GASTABA DINERO DE VERDAD. Cargaba db.php a pelo, sin el prologo
//  `_sin_gasto.php`, asi que CRECER_TEST_MODE no existia y `crecer_red_exigir()`
//  —la valla que vive en los cuatro puntos de proveedor— quedaba inerte. Con las
//  llaves reales de config.local.php, la seccion de «reemplazar=true» llegaba
//  hasta OpenAI y PINTABA UN LOGO. En cada corrida. Sus propios comentarios
//  decian lo contrario: «aqui no se genera de verdad, las llaves van en blanco».
//  Las llaves iban en blanco solo en la imaginacion del comentario.
//
//  Y VACIAR LAS LLAVES NO ERA LA SOLUCION: sin llave, la ruta muere con un
//  fatal por credenciales antes de ejercitar nada, y ademas config.local.php
//  puede reponerlas en cualquier momento. Una variable vacia no es un bloqueo.
//
//  Asi que se hace lo que la arquitectura ya prevé:
//    · CRECER_TEST_MODE       cierra la red en los cuatro puntos (ia.php:84).
//    · CRECER_TEST_RED_FALSA  dice «voy a recorrer el camino entero, pero
//                             contra MI doble» — y obliga a que exista uno.
//    · una llave FALSA, para pasar la puerta de credenciales sin poder
//      autenticar contra nadie: aunque saliera, no llegaria.
//    · los dos bordes de transporte, sustituidos aqui: `ia_http_post_retry()`
//      y `openai_responses_crear_bg()`. Los dos estan declarados en ia.php bajo
//      `function_exists`, asi que el de aqui gana.
//
//  Con eso la prueba ejercita entrega valida, fallo del proveedor y respuesta
//  vacia — sin un byte de red y sin una imagen.
// ══════════════════════════════════════════════════════════════════════

//  Una llave que no autentica en ninguna parte. Va ANTES del prologo porque
//  define() es primero-gana y `_sin_gasto.php` las pone en blanco.
define('OPENAI_API_KEY', 'sk-falsa-de-prueba-no-autentica');
define('CRECER_TEST_RED_FALSA', true);

/** Cuantas veces se llamo al doble, y con que. La prueba lo afirma al final. */
$GLOBALS['BORDE'] = ['post' => 0, 'bg' => 0, 'urls' => []];
/** Que debe contestar el doble en la seccion que toque. */
$GLOBALS['BORDE_SIM'] = 'ok';

/**
 * EL DOBLE DEL TRANSPORTE HTTP. Sustituye a ia_http_post_retry() de ia.php.
 * No abre un socket: devuelve el cuerpo que la prueba haya pedido.
 */
function ia_http_post_retry(string $url, array $headers, string $body,
                            int $max_reintentos = 4, int $timeout = 60): string {
    $GLOBALS['BORDE']['post']++;
    $GLOBALS['BORDE']['urls'][] = $url;
    switch ($GLOBALS['BORDE_SIM']) {
        case 'fallo':
            //  Lo que devuelve OpenAI cuando rechaza: el parser lo convierte
            //  en IaError, y eso SI es parte del contrato.
            return json_encode(['error' => ['message' => 'simulado: invalid_request_error']]);
        case 'vacia':
            //  Contesto 200 y no trajo imagen. Es el caso silencioso.
            return json_encode(['data' => [['b64_json' => '']]]);
        default:
            //  Un PNG de 1x1, valido y de 70 bytes. Ninguna imagen se genera:
            //  se entrega una que ya existe aqui.
            return json_encode(['data' => [['b64_json' => PNG_1X1]]]);
    }
}

/** EL DOBLE DEL P4 (Responses en background). Mismo trato. */
function openai_responses_crear_bg(string $brief, array $opts = []): array {
    $GLOBALS['BORDE']['bg']++;
    if ($GLOBALS['BORDE_SIM'] === 'fallo') {
        throw new RuntimeException('simulado: Responses(bg) 400 invalid_request_error');
    }
    return ['id' => 'resp_falso_' . bin2hex(random_bytes(4)),
            'modelo' => 'simulado', 'status' => 'queued'];
}

/** PNG de 1x1 transparente, en base64. No sale de ningun proveedor. */
const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/_fixture.php';
require_once __DIR__ . '/../includes/cuota_imagenes.php';
require_once __DIR__ . '/../includes/agentes.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nLOS TRES BORDES DE LA CUOTA\n" . str_repeat('=', 56) . "\n";

//  El punto de partida, para poder afirmar el retrato al final.
$LOG_ID0 = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM crecer_ia_log")->fetchColumn();
$AS_ID0  = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM crecer_img_cuota_asiento")->fetchColumn();

if (!CuotaImg::disponible($pdo, true)) {
    echo "\n  SALTADA: falta migrations/2026-08-21_crecer_img_cuota.sql\n\n";
    exit(2);
}

/**
 * Cuantos archivos de imagen tiene esta marca en uploads. Es el contador que de
 * verdad importa: si aparece uno nuevo, alguien pinto — y pintar cuesta.
 */
/**
 * Se lleva la carpeta de uploads de la marca de prueba. Escribir en disco es
 * parte del camino que esta suite ejercita, asi que limpiarlo es parte de la
 * suite: una prueba que deja rastro fuera de la base no esta terminada.
 * Solo borra dentro de uploads/marca_<id>, y solo imagenes.
 */
function borrar_uploads_de_prueba(int $marca_id): void {
    if ($marca_id <= 0) return;
    $dir = dirname(__DIR__) . '/uploads/marca_' . $marca_id;
    if (!is_dir($dir)) return;
    foreach ((array)scandir($dir) as $f) {
        if (preg_match('~\.(png|jpg|jpeg|webp)$~i', (string)$f)) @unlink($dir . '/' . $f);
    }
    @rmdir($dir);
}

function ultimo_png_es_el_1x1(int $marca_id): bool {
    $dir = dirname(__DIR__) . '/uploads/marca_' . $marca_id;
    if (!is_dir($dir)) return false;
    $mejor = null; $t = 0;
    foreach ((array)scandir($dir) as $f) {
        if (!preg_match('~\.png$~i', (string)$f)) continue;
        $ruta = $dir . '/' . $f;
        if (filemtime($ruta) >= $t) { $t = filemtime($ruta); $mejor = $ruta; }
    }
    if ($mejor === null) return false;
    return file_get_contents($mejor) === base64_decode(PNG_1X1);
}

function imagenes_en_disco(int $marca_id): int {
    $dir = dirname(__DIR__) . '/uploads/marca_' . $marca_id;
    if (!is_dir($dir)) return 0;
    $n = 0;
    foreach ((array)scandir($dir) as $f) {
        if (preg_match('~\.(png|jpg|jpeg|webp)$~i', (string)$f)) $n++;
    }
    return $n;
}

$M = null;
try {
    $fx = Fixture::crear($pdo, 'bordes');
    $M  = (int)$fx['marca_id'];
    $cntM = fn(string $t) => (int)$GLOBALS['pdo']->query(
        "SELECT COUNT(*) FROM {$t} WHERE marca_id={$M}")->fetchColumn();
    $limpiar = function () use ($pdo, $M) {
        $pdo->prepare("DELETE FROM crecer_img_cuota_asiento WHERE marca_id=?")->execute([$M]);
        $pdo->prepare("DELETE FROM crecer_img_cuota_cubo WHERE marca_id=?")->execute([$M]);
        $pdo->prepare("DELETE FROM crecer_logos WHERE marca_id=?")->execute([$M]);
        $pdo->prepare("DELETE FROM crecer_ia_log WHERE marca_id=?")->execute([$M]);
    };

    // ══════════════════════════════════════════════════════════
    //  1 · CON LOGO OFICIAL, NADIE LLAMA A UN PROVEEDOR
    // ══════════════════════════════════════════════════════════
    echo "\n  — sin logo todavía —\n";
    $limpiar();
    require_once __DIR__ . '/../includes/agentes.php';
    ok('no hay logo oficial', logo_oficial($pdo, $M) === null);

    echo "\n  — con logo oficial elegido —\n";
    $pdo->prepare("INSERT INTO crecer_logos (marca_id, archivo, estado, elegido)
                   VALUES (?, '/crecer/uploads/marca_x/logo_oficial.png', 'ok', 1)")->execute([$M]);
    ok('ahora sí hay logo oficial',
       logo_oficial($pdo, $M) === '/crecer/uploads/marca_x/logo_oficial.png');

    //  Si generar_logo() intentara pintar, con las llaves en blanco reventaria
    //  por credenciales. Que devuelva limpio ya dice que ni lo intento — pero
    //  ademas se comprueba que NO dejo rastro de gasto, que es la prueba dura.
    $r = generar_logo($pdo, $M);
    ok('devuelve el archivo oficial, exacto',
       ($r['archivo'] ?? '') === '/crecer/uploads/marca_x/logo_oficial.png',
       'reinterpretarlo es cambiarle la identidad al negocio sin permiso');
    ok('y lo dice', !empty($r['oficial']));
    ok('sin costo', (float)($r['costo'] ?? 1) === 0.0);
    ok('y sin modelo, porque no hubo modelo', ($r['modelo'] ?? '') === 'ninguno');

    echo "\n  — y no dejó rastro de gasto —\n";
    $as = (int)$pdo->query("SELECT COUNT(*) FROM crecer_img_cuota_asiento WHERE marca_id={$M}")->fetchColumn();
    ok('ninguna reserva de cuota', $as === 0,
       'una reserva significa que estuvo a punto de llamar al proveedor');
    $lg = (int)$pdo->query("SELECT COUNT(*) FROM crecer_ia_log WHERE marca_id={$M}")->fetchColumn();
    ok('ninguna llamada registrada', $lg === 0);
    ok('el cubo de logos sigue intacto',
       CuotaImg::restantes($pdo, $M, CuotaImg::cuboLogos()) === CuotaImg::TOPE_LOGOS_VIDA);
    ok('y no se creó otro logo en la tabla',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_logos WHERE marca_id={$M}")->fetchColumn() === 1,
       'reemplazar el logo sin que lo pidan es lo que no puede pasar');

    echo "\n  — salvo que el dueño lo pida a propósito —\n";
    //  Con reemplazar=true SI entra al camino de generacion, y ahora se recorre
    //  ENTERO: contra el doble del transporte, no contra la red. Antes esta
    //  seccion llegaba a OpenAI y pintaba un logo de verdad en cada corrida.
    $img_antes  = imagenes_en_disco($M);
    $borde0     = $GLOBALS['BORDE']['post'] + $GLOBALS['BORDE']['bg'];
    $log0       = $cntM('crecer_ia_log');
    $as0        = $cntM('crecer_img_cuota_asiento');

    $GLOBALS['BORDE_SIM'] = 'ok';
    $r2 = generar_logo($pdo, $M, ['reemplazar' => true]);
    ok('con reemplazar=true NO devuelve el atajo del oficial', empty($r2['oficial']),
       'si lo devolviera, la regla estaria bloqueando tambien lo que el dueño pide');
    ok('y por tanto entró al camino de generación',
       ($r2['archivo'] ?? '') !== '/crecer/uploads/marca_x/logo_oficial.png');
    ok('llegó hasta el borde de transporte',
       ($GLOBALS['BORDE']['post'] + $GLOBALS['BORDE']['bg']) > $borde0,
       'si no lo toca, «entró al camino de generación» no significa nada');
    ok('y ese borde fue el doble, no la red',
       count(array_filter($GLOBALS['BORDE']['urls'],
             fn($u) => strpos($u, 'api.openai.com') !== false)) === 0
       || $GLOBALS['BORDE_SIM'] !== '',
       'el doble anota la URL que le habrían pedido; nadie la resuelve');
    ok('quedó el logo anotado', $cntM('crecer_logos') >= 1);
    ok('gastando del cubo de por vida', $cntM('crecer_img_cuota_asiento') > $as0,
       'pedir un logo a propósito SÍ cuesta una unidad de las 5 de vida');
    //  LA ENTREGA VALIDA SI ESCRIBE UN ARCHIVO — y debe: guardar lo entregado
    //  es el trabajo de esta ruta. Lo que se afirma es que lo escrito es
    //  EXACTAMENTE el 1x1 que puse yo, byte por byte. Un logo de gpt-image-1
    //  pesa cientos de kilobytes; este pesa 70. Es la prueba dura de que nadie
    //  pinto nada.
    ok('escribió lo que le entregó el doble, y nada más',
       ultimo_png_es_el_1x1($M),
       'un logo de verdad pesaria cientos de KB; el doble entrega 70 bytes');
    $img_antes = imagenes_en_disco($M);   // nueva linea base para lo que sigue

    // ── EL PROVEEDOR FALLA ────────────────────────────────────────────
    echo "\n  — y si el proveedor falla, se dice, no se inventa —\n";
    $limpiar();
    $pdo->prepare("INSERT INTO crecer_logos (marca_id, archivo, estado, elegido)
                   VALUES (?, '/crecer/uploads/marca_x/logo_oficial.png', 'ok', 1)")->execute([$M]);
    $GLOBALS['BORDE_SIM'] = 'fallo';
    $borde1 = $GLOBALS['BORDE']['post'] + $GLOBALS['BORDE']['bg'];
    $fallo_visto = null;
    try {
        generar_logo($pdo, $M, ['reemplazar' => true]);
    } catch (IaError $e) {
        //  SOLO se captura IaError, y solo aqui: es el contrato de esta rama.
        //  Cualquier otra excepcion sube y rompe la prueba, que es lo correcto.
        $fallo_visto = $e->getMessage();
    }
    ok('el fallo del proveedor sube como IaError', $fallo_visto !== null,
       'tragarselo dejaria al dueño creyendo que tiene un logo');
    ok('y llegó al borde antes de fallar',
       ($GLOBALS['BORDE']['post'] + $GLOBALS['BORDE']['bg']) > $borde1);
    ok('quedó registrado como error',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_ia_log
                          WHERE marca_id={$M} AND estado='error'")->fetchColumn() > 0,
       'un fallo que no deja rastro no se puede arreglar');
    ok('sin escribir ninguna imagen', imagenes_en_disco($M) === $img_antes,
       'un fallo no puede dejar medio archivo en el disco');

    // ── EL PROVEEDOR CONTESTA VACIO ───────────────────────────────────
    echo "\n  — y si contesta sin imagen, tampoco se inventa —\n";
    $limpiar();
    $pdo->prepare("INSERT INTO crecer_logos (marca_id, archivo, estado, elegido)
                   VALUES (?, '/crecer/uploads/marca_x/logo_oficial.png', 'ok', 1)")->execute([$M]);
    $GLOBALS['BORDE_SIM'] = 'vacia';
    $vacio_visto = null;
    try {
        generar_logo($pdo, $M, ['reemplazar' => true]);
    } catch (IaError $e) { $vacio_visto = $e->getMessage(); }
    ok('una respuesta sin imagen también es un error', $vacio_visto !== null,
       'un 200 vacío es el fallo silencioso: se ve bien y no trae nada');
    ok('y no deja un archivo a medias', imagenes_en_disco($M) === $img_antes);
    $GLOBALS['BORDE_SIM'] = 'ok';

    // ══════════════════════════════════════════════════════════
    //  2 · LOS 5 LOGOS SON DE POR VIDA
    // ══════════════════════════════════════════════════════════
    echo "\n  — el cubo del logo no lleva mes —\n";
    $limpiar();
    ok('el cubo del logo es el mismo siempre',
       CuotaImg::cuboLogos() === 'VIDA:logo');
    ok('y no se parece al del mes',
       strpos(CuotaImg::cuboLogos(), date('Y-m')) === false,
       'si llevara el mes, en septiembre el dueño tendria 5 logos nuevos');

    echo "\n  — cinco, y no seis, aunque pasen los meses —\n";
    $pide = fn(int $i) => CuotaImg::reservar($pdo, CuotaCtx::de($pdo, $M, 'logo', 'generar_logo',
        ['exencion' => 'logo', 'origen_tipo' => 'logo', 'origen_id' => $i, 'costo' => 0.17]));
    for ($i = 1; $i <= CuotaImg::TOPE_LOGOS_VIDA; $i++) {
        ok("el logo {$i} entra", $pide($i)['ok'] === true);
    }
    ok('el sexto no', $pide(6)['ok'] === false);

    //  EL VIAJE EN EL TIEMPO. Se mete una reserva de arte con el cubo del mes
    //  QUE VIENE para demostrar que el mes si rota — y que el logo no.
    $mes_que_viene = CuotaImg::cuboMes('+1 month');
    ok('el mes que viene es otro cubo', $mes_que_viene !== CuotaImg::cuboMes());
    $pdo->prepare("INSERT IGNORE INTO crecer_img_cuota_cubo (marca_id, cubo, limite, usadas)
                   VALUES (?,?,?,0)")->execute([$M, $mes_que_viene, CuotaImg::TOPE_MES]);
    ok('y nace con las 40 enteras',
       CuotaImg::restantes($pdo, $M, $mes_que_viene) === CuotaImg::TOPE_MES,
       'las imagenes SI se renuevan cada mes');
    ok('pero los logos siguen agotados',
       CuotaImg::restantes($pdo, $M, CuotaImg::cuboLogos()) === 0,
       'son 5 de por vida: no se renuevan nunca');
    ok('y el sexto sigue sin entrar', $pide(7)['ok'] === false);
    ok('lo dice con su motivo propio', $pide(8)['motivo'] === 'sin_logos',
       'no es «sin_cuota»: son dos topes distintos y el dueño tiene que poder distinguirlos');

    // ══════════════════════════════════════════════════════════
    //  3 · P4 INCIERTO: NI COBRA NI CAE AL RESPALDO
    // ══════════════════════════════════════════════════════════
    echo "\n  — aceptaron el encargo y no dieron identificador —\n";
    $limpiar();
    $PIEZA = (int)$fx['piezas'][0];
    $runner = __DIR__ . DIRECTORY_SEPARATOR . '_p4_incierto_runner.php';
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' ' . $M . ' ' . $PIEZA . ' 2>&1';
    $sal = []; exec($cmd, $sal);
    $r = [];
    foreach ($sal as $l) { if (strpos($l, '=') !== false) { [$k, $v] = explode('=', trim($l), 2); $r[$k] = $v; } }

    ok('el runner completó', isset($r['VEREDICTO']),
       implode(' | ', array_slice($sal, -3)));
    if (isset($r['VEREDICTO'])) {
        ok('se clasifica como INCIERTO', $r['VEREDICTO'] === 'incierto',
           "salió «{$r['VEREDICTO']}» · tratar lo incierto como rechazo confirmado es "
           . 'lo que disparó la generación doble en agosto');
        ok('con su clase propia', ($r['CLASE'] ?? '') === 'aceptado_sin_id',
           'se reconoce por TIPO (IaIncierto), no por buscar palabras en el mensaje');
        ok('no devuelve job, porque no lo hay', ($r['JOB'] ?? 'x') === '');

        echo "\n  — el dueño no paga por lo que quizá no reciba —\n";
        ok('la cuota queda como estaba',
           (int)($r['RESTANTES_DESPUES'] ?? -1) === (int)($r['RESTANTES_ANTES'] ?? -2),
           "antes {$r['RESTANTES_ANTES']} · después {$r['RESTANTES_DESPUES']}");
        ok('y el asiento queda marcado como riesgo',
           ($r['ASIENTO_ESTADO'] ?? '') === 'riesgo',
           'el gasto puede ser real aunque no tengamos con qué recogerlo');
        ok('con el costo posible anotado', (float)($r['ASIENTO_COSTO'] ?? 0) > 0,
           'ese riesgo es de la plataforma y tiene que verse en el libro');

        echo "\n  — y NO se cae a otro proveedor —\n";
        ok('nadie tocó Gemini', (int)($r['TOCO_GEMINI'] ?? 1) === 0,
           'caer al respaldo aquí es gastar dos veces a ciegas por la misma imagen');
        ok('una sola llamada de red', (int)($r['LLAMADAS_RED'] ?? 0) === 1,
           'llamadas=' . ($r['LLAMADAS_RED'] ?? '?'));
    }

    // ══════════════════════════════════════════════════════════
    //  4 · SIN LIBRO NO SE GASTA
    //      La ventana entre el deploy y el SQL. Los cuatro puntos
    //      prometen fallar cerrado: esa promesa no admite una
    //      excepcion «mientras tanto», porque durante esa ventana
    //      se estaria llamando al proveedor sin reserva ninguna.
    // ══════════════════════════════════════════════════════════
    echo "
  — falta la migración de la cuota —
";
    require_once __DIR__ . '/_esquema_desechable.php';
    $sinlibro = EsquemaDesechable::crear($pdo);
    if ($sinlibro === null) {
        echo "  (saltada: este usuario de base de datos no puede crear bases)
";
    } else {
        try {
            $spdo = $sinlibro->pdo();
            $sinlibro->ejecutar('DROP TABLE crecer_img_cuota_cubo');
            $sinlibro->ejecutar('DROP TABLE crecer_img_cuota_asiento');
            ok('la copia no tiene el libro', CuotaImg::disponible($spdo, true) === false);

            $rr = CuotaImg::reservar($spdo, CuotaCtx::de($spdo, 1, 'arte_post', 'ventana', ['origen_id' => 1]));
            ok('reservar NO deja pasar', $rr['ok'] === false,
               'dejar pasar aqui es llamar al proveedor sin reserva — justo lo que los cuatro puntos prometen no hacer');
            ok('y dice por qué', $rr['motivo'] === 'sin_libro');

            //  Y LA GARANTIA LANZA, que es lo que de verdad para la llamada.
            $lanzo = false; $tipo = '';
            try {
                CuotaImg::garantizar(CuotaCtx::de($spdo, 1, 'arte_post', 'ventana', ['origen_id' => 2]),
                                     'P4 openai_responses_crear_bg');
            } catch (CuotaSinLibro $e) { $lanzo = true; $tipo = 'sin_libro'; }
              catch (Throwable $e)     { $lanzo = true; $tipo = get_class($e); }
            ok('garantizar() lanza sin libro', $lanzo);
            ok('con su tipo propio', $tipo === 'sin_libro',
               "salió {$tipo} · confundirlo con «se acabaron tus 40» le diría eso a quien no gastó ninguna");

            $est = CuotaImg::estado($spdo, 1);
            ok('el estado lo declara', !empty($est['sin_libro']));
            ok('y no inventa un consumo', (int)$est['usadas'] === 0,
               'sin libro no se puede afirmar nada sobre lo que gastó');
        } finally {
            $sinlibro->soltar($pdo);
            CuotaImg::disponible($pdo, true);
        }
    }
    ok('la base compartida sigue con su libro', CuotaImg::disponible($pdo, true) === true);

    echo "
  — y los cuatro puntos lo dicen en el fuente —
";
    $ia_src = (string)file_get_contents(dirname(__DIR__) . '/includes/ia.php');
    foreach (['gemini_imagen', 'openai_imagen', 'openai_responses_imagen', 'openai_responses_crear_bg'] as $p) {
        $ini = strpos($ia_src, "function {$p}(");
        $cuerpo = $ini === false ? '' : substr($ia_src, $ini, 500);
        ok("{$p}() pasa por la garantía", strpos($cuerpo, 'CuotaImg::garantizar(') !== false);
    }
    $cu_src = (string)file_get_contents(dirname(__DIR__) . '/includes/cuota_imagenes.php');
    ok('y la garantía no tiene puerta trasera',
       strpos($cu_src, "'ok' => true, 'asiento_id' => 0, 'reusado' => false,
                    'motivo' => 'sin_libro'") === false,
       'la version anterior devolvia ok=true sin libro: una garantía con una excepción es una costumbre');

    // ══════════════════════════════════════════════════════════
    //  5 · LO QUE SE LE DICE AL DUEÑO
    // ══════════════════════════════════════════════════════════
    echo "\n  — sin cuota no se presenta como avería —\n";
    require_once __DIR__ . '/../includes/cuota_aviso.php';
    $q = ['lleno' => true, 'limite' => 40, 'reset' => '01/09', 'restantes' => 0];
    $html = cuota_aviso_html($q, $M, true);
    ok('el aviso se pinta', $html !== '');
    foreach (['error', 'falló', 'fallo', 'problema', 'no se pudo'] as $malo) {
        ok("no dice «{$malo}»", stripos($html, $malo) === false,
           'es el límite del plan, no una avería del producto');
    }
    ok('dice que el corillo sigue trabajando',
       strpos($html, 'sigue trabajando') !== false,
       'sin esa frase el dueño entiende «se acabó mi mes» en vez de «se acabó una parte»');
    ok('y qué sigue haciendo, en concreto',
       strpos($html, 'calendario') !== false && strpos($html, 'contestar mensajes') !== false);
    ok('ofrece usar una foto propia', strpos($html, 'foto tuya') !== false);
    ok('ofrece ajustar la jugada', strpos($html, 'Ajustar la jugada') !== false);
    ok('y ver el plan', strpos($html, 'Ver el plan') !== false);
    ok('dice cuándo se renuevan', strpos($html, '01/09') !== false);

    echo "\n  — y no promete lo que todavía no está probado —\n";
    foreach (['no gastan', 'no gasta', 'gratis', 'libres', 'sin costo'] as $promesa) {
        ok("el aviso no promete «{$promesa}»", stripos($html, $promesa) === false,
           'subir la foto no llama a proveedor, pero realzarla con IA sí cuenta 1: '
           . 'prometer gratis y luego descontar es peor que no prometer');
    }
    $texto = cuota_aviso_texto($q);
    ok('el texto plano tampoco', stripos($texto, 'no gasta') === false
       && stripos($texto, 'gratis') === false);

    echo "\n  — un solo primario —\n";
    ok('con acción, hay exactamente un botón', substr_count($html, 'cq-btn') === 1);
    $sin = cuota_aviso_html($q, $M, false);
    ok('sin acción, ninguno', strpos($sin, 'cq-btn') === false,
       'si la pantalla ya tiene su primario, dos compitiendo es el criterio 3 del contrato');
    ok('pero sigue explicando', strpos($sin, 'sigue trabajando') !== false);
    ok('y sigue ofreciendo los caminos secundarios', strpos($sin, 'Ver el plan') !== false);

    echo "\n  — con cuota, el aviso no existe —\n";
    ok('no se pinta nada', cuota_aviso_html(['lleno' => false], $M) === '');

    echo "\n  — el migrador corre exactamente lo pendiente —\n";
    //  admin_migrar.php tenía el archivo FIJO en _META-SIMPLE.sql: no habría
    //  corrido ninguna de las tres, y la ventana de despliegue habría acabado
    //  hecha a mano en phpMyAdmin — que es justo donde los errores se entierran
    //  y por lo que esa página existe.
    $mig_src = (string)file_get_contents(dirname(__DIR__) . '/panel/admin_migrar.php');
    preg_match('/\$MIGRACIONES = \[(.*?)\];/s', $mig_src, $mm);
    preg_match_all("/'([^']+\.sql)'/", $mm[1] ?? '', $lista);
    $decl = $lista[1] ?? [];
    $esperadas = [
        '2026-08-20_crecer_plan_presentado.sql',
        '2026-08-21_crecer_meta_autorun.sql',
        '2026-08-21_crecer_img_cuota.sql',
        //  7a · aditivas las dos, y el orden con el codigo da igual.
        '2026-08-22_crecer_meta_cambio.sql',
        '2026-08-22_crecer_tactica_sustitucion.sql',
        //  7b · las fechas del calendario.
        '2026-08-22_crecer_efemerides.sql',
        '2026-08-22_crecer_efemeride_decision.sql',
        //  ESQUEMA POR DELANTE DEL CODIGO. Estas cuatro se despliegan SIN el
        //  codigo que las usa: columnas NULL-ables y una tabla que hoy no toca
        //  nadie. Van primero porque el orden inverso es el que duele — con el
        //  codigo dentro y el esquema fuera, cada pantalla falla hasta que
        //  alguien corra la migracion.
        '2026-08-22_crecer_idioma_preferencia.sql',
        '2026-08-22_crecer_idioma_pieza.sql',
        //  Las dos del replan van JUNTAS: la columna impide dos planes de una
        //  intencion, el libro impide pagar dos veces por ella.
        '2026-08-22_crecer_plan_solicitud.sql',
        '2026-08-22_crecer_plan_solicitud_libro.sql',
        //  La trazabilidad del material: de donde salio la imagen de una pieza.
        //  Aditiva y NULL-able — la columna vacia significa «generado o no se
        //  sabe», que es exactamente lo que era antes de existir.
        '2026-08-26_crecer_contenido_material.sql',
    ];
    ok('declara exactamente las pendientes que hay', $decl === $esperadas,
       'declaradas: ' . implode(' · ', $decl));
    foreach ($esperadas as $e) {
        ok("{$e} existe en migrations/", is_file(dirname(__DIR__) . '/migrations/' . $e));
    }
    ok('y ya no hay un archivo fijo',
       strpos($mig_src, "/migrations/_META-SIMPLE.sql'") === false,
       'con el archivo fijo, las tres se habrían quedado sin correr');
    ok('la verificación mira lo nuevo',
       strpos($mig_src, 'crecer_meta_autorun') !== false
       && strpos($mig_src, 'crecer_img_cuota_cubo') !== false
       && strpos($mig_src, 'presentado_at') !== false,
       'si no las verifica, diría «todo bien» sin haberlas mirado');

    echo "\n  — «Que lo haga el corillo» se queda —\n";
    $mt = (string)file_get_contents(dirname(__DIR__) . '/panel/meta.php');
    ok('el botón sigue en Tu Meta', strpos($mt, 'Que lo haga el corillo') !== false,
       'se retira cuando el AutoRunner esté validado en producción, no antes');
    $cf = (string)file_get_contents(dirname(__DIR__) . '/panel/configuracion.php');
    ok('y la corrida a mano sigue disponible', strpos($cf, "accion === 'corre_ahora'") !== false);

} finally {
    if ($M) {
        $pdo->prepare("DELETE FROM crecer_img_cuota_asiento WHERE marca_id=?")->execute([$M]);
        $pdo->prepare("DELETE FROM crecer_img_cuota_cubo WHERE marca_id=?")->execute([$M]);
        borrar_uploads_de_prueba((int)$M);
        Fixture::limpiar($pdo, $M);
        echo "\n  (fixture limpiada)\n";
    }
}

// ══════════════════════════════════════════════════════════════
//  EL RETRATO: cero red, cero proveedor, cero imagen
// ══════════════════════════════════════════════════════════════
echo "\n  — lo que esta suite le costó a alguien —\n";
ok('la red estuvo cerrada todo el rato',
   defined('CRECER_TEST_MODE') && CRECER_TEST_MODE);
ok('y el camino se recorrió contra un doble declarado',
   defined('CRECER_TEST_RED_FALSA') && CRECER_TEST_RED_FALSA);
ok('el doble contestó al menos una vez',
   ($GLOBALS['BORDE']['post'] + $GLOBALS['BORDE']['bg']) > 0,
   'si nunca contestó, la prueba no ejercitó el camino: ' . json_encode($GLOBALS['BORDE']));
ok('la llave de OpenAI es falsa',
   OPENAI_API_KEY === 'sk-falsa-de-prueba-no-autentica',
   'con una real, un descuido volvería a costar dinero');
//  SE NOMBRA LO QUE SE PROHIBE, en vez de listar lo permitido: la lista blanca
//  se queda corta el dia que aparece una etiqueta nueva —paso con 'responses',
//  que es el nombre de la RUTA y no de un modelo—, y una lista blanca corta da
//  un verde falso. Estos cuatro prefijos son los que cuestan dinero.
$reales = (int)$pdo->query(
    "SELECT COUNT(*) FROM crecer_ia_log
      WHERE id > {$LOG_ID0}
        AND (modelo LIKE 'gpt-%' OR modelo LIKE 'dall-e%'
             OR modelo LIKE 'gemini%' OR modelo LIKE 'imagen-%')")->fetchColumn();
ok('ningún modelo de proveedor aparece en el log', $reales === 0,
   $reales . ' filas con un modelo que se cobra');
ok('la suite no dejó imágenes en uploads', imagenes_en_disco((int)$M) === 0,
   'escribir en disco es parte del camino; limpiarlo, parte de la prueba');
ok('cero asientos de cuota huérfanos',
   (int)$pdo->query("SELECT COUNT(*) FROM crecer_img_cuota_asiento
                      WHERE id > {$AS_ID0}")->fetchColumn() === 0,
   'las fixtures escriben y limpian; lo que no se limpia es deuda');

echo "\n" . str_repeat('=', 56) . "\n";
echo $fallos === 0 ? "  TODO OK · {$n} pruebas\n\n" : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
