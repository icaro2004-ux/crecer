<?php
// ============================================================
//  CRECER — LA JUGADA QUE LE TOCA A ÉL (accion_dueno)
//  tests/test_meta_tarea_dueno.php
//
//  EL BLOQUEO QUE REPRODUCE. Una jugada de clase `accion_dueno` entra en la
//  semana igual que una publicación, pero NO tiene pieza de contenido y nunca
//  la va a tener: no la produce el ejecutor, no hay job que la complete, y el
//  encolado de la primera semana la salta a propósito. Aun así
//  `semana_accion()` caía en su rama «sin pieza» y le ponía:
//
//      «Estoy preparando esta publicación. Vuelve en un rato.»
//
//  Nadie la prepara. Y como `semana_resumen()` la contaba en `preparando`,
//  una semana cuya única jugada abierta fuera del dueño se quedaba en «Estoy
//  preparando tu primera semana» PARA SIEMPRE. La llegada de 2C le prometía
//  «Necesitaré tu ayuda en 2» y al entrar no había forma de ayudar.
//
//  Es un bloqueo del recorrido Meta → Plan → Semana, no una mejora de copy.
//
//  ══ RED BLOQUEADA POR CONSTRUCCION, NO POR CONFIANZA ══
//  Las claves se definen VACIAS antes de la config: gana el primer define() y
//  `ia_transporte()` cae a 'mock'. Se comprueba contando crecer_ia_log y los
//  asientos de cuota antes y después.
// ============================================================

$__err = error_reporting();
error_reporting($__err & ~E_WARNING);
define('GEMINI_API_KEY', '');
define('GCP_PROJECT_ID', '');
define('GOOGLE_APPLICATION_CREDENTIALS', '');
define('OPENAI_API_KEY', '');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/meta_negocio.php';
require_once __DIR__ . '/../includes/meta_semana.php';
require_once __DIR__ . '/../includes/meta_cambio.php';
require_once __DIR__ . '/../includes/ia.php';
require_once __DIR__ . '/_fixture.php';
error_reporting($__err);

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nLA JUGADA QUE LE TOCA A ÉL\n" . str_repeat('=', 58) . "\n";

echo "\n  — la red, bloqueada por construcción —\n";
ok('el transporte del modelo es «mock»', ia_transporte() === 'mock', ia_transporte());
ok('sin clave de Gemini', GEMINI_API_KEY === '');
ok('sin clave de OpenAI', OPENAI_API_KEY === '');

$cnt = fn(string $t, string $w = '1') => (int)$GLOBALS['pdo']->query(
    "SELECT COUNT(*) FROM {$t} WHERE {$w}")->fetchColumn();
$real_antes  = $cnt('crecer_ia_log', "modelo <> 'mock'");
$cuota_antes = $cnt('crecer_img_cuota_asiento');
$img_antes   = $cnt('crecer_contenido');

$hay_http = @file_get_contents('http://localhost/crecer/login.php', false,
    stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]])) !== false;

function sesion(int $usuario_id): string {
    $sid  = 'tar' . bin2hex(random_bytes(7));
    $ruta = session_save_path() ?: sys_get_temp_dir();
    file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
                      'usuario_id|i:' . $usuario_id . ';');
    return $sid;
}
/** Un POST a meta.php como lo haría el navegador. */
function postear(string $sid, int $marca, array $campos): array {
    $c = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Cookie: PHPSESSID={$sid}\r\nContent-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query($campos),
        'timeout' => 25, 'ignore_errors' => true]]);
    $r = (string)@file_get_contents(
        'http://localhost/crecer/panel/meta.php?marca=' . $marca, false, $c);
    return [json_decode($r, true) ?: [], $r];
}
/** El csrf de esa sesión, leído del fichero de sesión de PHP. */
function csrf_de(string $sid): string {
    $ruta = (session_save_path() ?: sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'sess_' . $sid;
    $raw  = (string)@file_get_contents($ruta);
    return preg_match('~csrf\|s:\d+:"([0-9a-f]+)"~', $raw, $m) ? $m[1] : '';
}
function pagina(string $sid, int $marca, string $extra = ''): string {
    $c = stream_context_create(['http' => [
        'header' => "Cookie: PHPSESSID={$sid}\r\n", 'timeout' => 30, 'ignore_errors' => true]]);
    return (string)@file_get_contents(
        'http://localhost/crecer/panel/meta.php?marca=' . $marca . $extra, false, $c);
}
function visible(string $html): string {
    $s = preg_replace('#<script\b[^>]*>.*?</script>#is', ' ', $html);
    $s = preg_replace('#<style\b[^>]*>.*?</style>#is', ' ', (string)$s);
    return (string)preg_replace('#<!--.*?-->#s', ' ', (string)$s);
}

/**
 * Una marca con plan y con las jugadas que se le pidan en la semana 1.
 * Cada entrada: [clase, titulo, con_pieza].
 */
function montar(PDO $pdo, string $etq, array $jugadas): array {
    $fx = Fixture::crear($pdo, $etq, true, 'admin');
    $M  = (int)$fx['marca_id'];
    $meta = meta_activa($pdo, $M);
    $plan = meta_plan_activo($pdo, (int)$meta['id']);
    //  Se parte de cero: lo de la fixture, fuera de la semana 1.
    $pdo->prepare("UPDATE crecer_meta_tactica SET semana=9, estado='hecha' WHERE meta_id=?")
        ->execute([(int)$meta['id']]);
    foreach ($fx['piezas'] as $p) {
        $pdo->prepare("UPDATE crecer_contenido SET tactica_id=NULL WHERE id=?")->execute([(int)$p]);
    }
    $ins = $pdo->prepare(
        "INSERT INTO crecer_meta_tactica
            (meta_id, plan_id, marca_id, orden, semana, tipo, titulo, que_hacer, por_que,
             clase, quien, estado, piezas_meta, formato)
         VALUES (?,?,?,?,1,'contenido',?,?,?,?,'corillo','pendiente',?,'post')");
    $ids = [];
    foreach (array_values($jugadas) as $i => [$clase, $titulo, $con_pieza]) {
        $ins->execute([(int)$meta['id'], (int)$plan['id'], $M, $i + 1, $titulo,
                       'Pídeselos por WhatsApp a las dos últimas que compraron.',
                       'Esto ayuda a generar confianza y conseguir más pedidos.',
                       $clase, $clase === 'produccion' ? 1 : 0]);
        $tid = (int)$pdo->lastInsertId();
        $ids[$titulo] = $tid;
        if ($con_pieza) {
            $pdo->prepare("INSERT INTO crecer_contenido
                    (marca_id,plataforma,tipo,caption,estado,meta_id,plan_id,tactica_id,
                     fecha_programada,grafica_path)
                  VALUES (?, 'instagram','post',?, 'borrador',?,?,?, DATE_ADD(NOW(), INTERVAL 2 DAY), ?)")
                ->execute([$M, '[prueba] Caption lista.', (int)$meta['id'], (int)$plan['id'], $tid,
                           '/crecer/assets/brand/crecer-icon.png']);
        }
    }
    return [$fx, $meta, $plan, $ids];
}

$limpiar = [];
try {
    // ══════════════════════════════════════════════════════════════
    //  1 · EL BLOQUEO · una semana que es SOLO una tarea suya
    // ══════════════════════════════════════════════════════════════
    echo "\n  — una semana con una sola acción, la suya —\n";
    [$fa, $meta_a, $plan_a, $ids_a] = montar($pdo, 'tarA',
        [['accion_dueno', '[prueba] Pedir dos testimonios a clientes', false]]);
    $limpiar[] = $MA = (int)$fa['marca_id'];
    $TA = $ids_a['[prueba] Pedir dos testimonios a clientes'];

    $sem = semana_construir($pdo, $MA, $meta_a, $plan_a);
    ok('la acción ocupa una posición', (int)$sem['total'] === 1, json_encode($sem['total']));
    $it = $sem['items'][0];
    ok('y no tiene pieza que revisar', $it['pieza'] === null);

    //  NI UN JOB LA ESPERA: el encolado de la primera semana salta lo que no
    //  es producción, así que nadie va a completarla nunca.
    $jobs = $cnt('crecer_meta_jobs', "tactica_id={$TA}");
    ok('ningún job la espera', $jobs === 0,
       'si hubiera uno, «preparando» sería cierto — y no lo hay');

    $ac = semana_accion($it, $MA, '/crecer/panel');
    ok('NO se presenta como publicación preparándose',
       mb_stripos((string)($ac['nota'] ?? ''), 'preparando esta publicación') === false,
       'nota: ' . (string)($ac['nota'] ?? ''));
    ok('y ofrece algo que el dueño pueda hacer', ($ac['modo'] ?? 'ninguna') !== 'ninguna',
       'modo: ' . (string)($ac['modo'] ?? '') . ' — sin acción, la semana no se cierra jamás');

    $res = semana_resumen($pdo, $MA, $meta_a, $plan_a, '/crecer/panel');
    ok('la semana es REVISABLE, no eternamente «preparando»',
       ($res['estado'] ?? '') === 'pendiente', json_encode($res));
    ok('y su acción es la primera decisión', (int)($res['pos'] ?? 0) === 1, json_encode($res));
    ok('cuenta como decisión pendiente', (int)($res['pendientes'] ?? 0) === 1, json_encode($res));
    ok('y NO como algo preparándose',    (int)($res['preparando'] ?? 9) === 0, json_encode($res));

    //  Y LAS CIFRAS SE SEPARAN: llamarla «publicación» sería mentir.
    ok('el resumen distingue publicaciones de acciones',
       array_key_exists('pend_pub', $res) && array_key_exists('pend_tarea', $res),
       json_encode(array_keys($res)));
    ok('aquí hay 0 publicaciones y 1 acción',
       (int)($res['pend_pub'] ?? -1) === 0 && (int)($res['pend_tarea'] ?? -1) === 1,
       json_encode($res));
    $fr = semana_frase_estado($res);
    ok('y la frase no la llama publicación',
       mb_stripos($fr, 'publicaci') === false && mb_stripos($fr, 'acción') !== false, $fr);

    // ══════════════════════════════════════════════════════════════
    //  2 · «YA LO HICE» · el handler que ya existe
    // ══════════════════════════════════════════════════════════════
    echo "\n  — marcarla hecha, con el handler de siempre —\n";
    ok('existe la transición del dominio', function_exists('meta_tarea_hecha'),
       'reutiliza meta_tactica_estado() para escribir, y añade las guardas');

    if (function_exists('meta_tarea_hecha')) {
        $r1 = meta_tarea_hecha($pdo, $MA, $TA);
        ok('la marca hecha', !empty($r1['ok']), json_encode($r1));
        ok('y quedó hecha en la base',
           (string)$pdo->query("SELECT estado FROM crecer_meta_tactica WHERE id={$TA}")
                       ->fetchColumn() === 'hecha');

        //  DOBLE CLIC: contesta que sí, sin volver a escribir ni romperse.
        $r2 = meta_tarea_hecha($pdo, $MA, $TA);
        ok('el segundo clic no rompe nada', !empty($r2['ok']), json_encode($r2));
        ok('y se reconoce como repetido',   !empty($r2['repetido']), json_encode($r2));

        //  NO CREA CONTENIDO NI GASTA.
        ok('no creó contenido para esa acción',
           $cnt('crecer_contenido', "tactica_id={$TA}") === 0,
           'marcar una tarea no produce una pieza');

        //  LA MARCA AJENA no puede tocarla.
        $fo = Fixture::crear($pdo, 'tarX', false, 'proveedor');
        $limpiar[] = $MX = (int)$fo['marca_id'];
        $rx = meta_tarea_hecha($pdo, $MX, $TA);
        ok('otra marca no puede cambiarla', empty($rx['ok']), json_encode($rx));

        //  UNA DE PRODUCCIÓN NO SE DECLARA A MANO: el corillo cierra lo suyo
        //  con la evidencia de publicación.
        [$fp, $meta_p, $plan_p, $ids_p] = montar($pdo, 'tarP',
            [['produccion', '[prueba] Un post normal', true]]);
        $limpiar[] = $MP = (int)$fp['marca_id'];
        $rp = meta_tarea_hecha($pdo, $MP, $ids_p['[prueba] Un post normal']);
        ok('una jugada de producción no se marca a mano', empty($rp['ok']), json_encode($rp));
    }

    //  Y AL CERRARLA, LA SEMANA SE CIERRA.
    $res_c = semana_resumen($pdo, $MA, $meta_a, $plan_a, '/crecer/panel');
    ok('con la única acción hecha, la semana está lista',
       ($res_c['estado'] ?? '') === 'lista', json_encode($res_c));
    ok('y ya no cuenta como pendiente', (int)($res_c['pendientes'] ?? 9) === 0);

    // ══════════════════════════════════════════════════════════════
    //  3 · SEMANA MIXTA · publicaciones cocinándose no bloquean su tarea
    // ══════════════════════════════════════════════════════════════
    echo "\n  — dos publicaciones cocinándose y una acción suya —\n";
    [$fb, $meta_b, $plan_b, $ids_b] = montar($pdo, 'tarB', [
        ['produccion',   '[prueba] Post cocinándose 1', false],
        ['produccion',   '[prueba] Post cocinándose 2', false],
        ['accion_dueno', '[prueba] Pon tus precios',    false],
    ]);
    $limpiar[] = $MB = (int)$fb['marca_id'];
    $res_b = semana_resumen($pdo, $MB, $meta_b, $plan_b, '/crecer/panel');
    ok('la semana es revisable', ($res_b['estado'] ?? '') === 'pendiente', json_encode($res_b));
    ok('abre en la acción disponible, no en las que se cocinan',
       (int)($res_b['pos'] ?? 0) === 3, json_encode($res_b));
    ok('las dos publicaciones siguen preparándose',
       (int)($res_b['preparando'] ?? 0) === 2, json_encode($res_b));
    ok('y hay 1 decisión disponible', (int)($res_b['pendientes'] ?? 0) === 1, json_encode($res_b));

    // ── LA FRASE MIXTA ───────────────────────────────────────────
    echo "\n  — y las cifras no se mezclan —\n";
    [$fm, $meta_m, $plan_m, $ids_m] = montar($pdo, 'tarM', [
        ['produccion',   '[prueba] Post listo 1',    true],
        ['produccion',   '[prueba] Post listo 2',    true],
        ['accion_dueno', '[prueba] Contesta los DM', false],
    ]);
    $limpiar[] = $MM = (int)$fm['marca_id'];
    $res_m = semana_resumen($pdo, $MM, $meta_m, $plan_m, '/crecer/panel');
    ok('2 publicaciones y 1 acción', (int)($res_m['pend_pub'] ?? -1) === 2
       && (int)($res_m['pend_tarea'] ?? -1) === 1, json_encode($res_m));
    $fr_m = semana_frase_estado($res_m);
    ok('la frase las nombra por separado',
       mb_stripos($fr_m, '2 publicaciones') !== false
       && mb_stripos($fr_m, '1 acción') !== false, $fr_m);
    ok('y NUNCA dice «3 publicaciones»', mb_stripos($fr_m, '3 publicaciones') === false, $fr_m);

    // ══════════════════════════════════════════════════════════════
    //  4 · «NO PUEDO CON ESTA» · la sustitución que ya existe
    // ══════════════════════════════════════════════════════════════
    echo "\n  — cambiarla por otra, sin wizard nuevo —\n";
    [$fs, $meta_s, $plan_s, $ids_s] = montar($pdo, 'tarS',
        [['accion_dueno', '[prueba] Grabar un video contando la receta', false]]);
    $limpiar[] = $MS = (int)$fs['marca_id'];
    $TS = $ids_s['[prueba] Grabar un video contando la receta'];
    $tac_s = $pdo->query("SELECT * FROM crecer_meta_tactica WHERE id={$TS}")->fetch(PDO::FETCH_ASSOC);

    $sust = meta_sustituir_jugada($pdo, $MS, $TS, (int)$fs['usuario_id'], 'sin_tiempo', '',
        ['titulo' => '[prueba] Post con los precios a la vista', 'formato' => 'post',
         'que_hacer' => 'Un post con la lista', 'por_que' => 'quita la fricción de preguntar',
         'piezas_meta' => 1], meta_token_jugada($tac_s));
    ok('la sustitución acepta una jugada del dueño', !empty($sust['ok']), json_encode($sust));

    if (!empty($sust['ok'])) {
        $nueva = $pdo->query("SELECT * FROM crecer_meta_tactica WHERE id="
                             . (int)$sust['nueva_id'])->fetch(PDO::FETCH_ASSOC);
        $vieja = $pdo->query("SELECT * FROM crecer_meta_tactica WHERE id={$TS}")->fetch(PDO::FETCH_ASSOC);
        ok('la alternativa hereda semana y orden',
           (int)$nueva['semana'] === (int)$tac_s['semana']
           && (int)$nueva['orden'] === (int)$tac_s['orden'], json_encode($nueva));
        ok('la original queda descartada, con su historia',
           (string)$vieja['estado'] === 'descartada' && !empty($vieja['sustituida_at'])
           && (int)$vieja['sustituida_por_id'] === (int)$sust['nueva_id'], json_encode($vieja));
        ok('la alternativa es de producción y la produce el corillo',
           (string)$nueva['clase'] === 'produccion', (string)$nueva['clase']);

        $res_s = semana_resumen($pdo, $MS, $meta_s, $plan_s, '/crecer/panel');
        ok('la posición no se pierde',   (int)($res_s['total'] ?? 0) === 1, json_encode($res_s));
        ok('y ahora sí se está preparando de verdad',
           ($res_s['estado'] ?? '') === 'preparando', json_encode($res_s));
    }

    //  UNA ALTERNATIVA QUE TAMBIÉN ES SUYA se presenta como tarea nueva.
    echo "\n  — si la alternativa también le toca a él —\n";
    [$fd2, $meta_d2, $plan_d2, $ids_d2] = montar($pdo, 'tarD',
        [['accion_dueno', '[prueba] La primera suya', false]]);
    $limpiar[] = $MD2 = (int)$fd2['marca_id'];
    $T1 = $ids_d2['[prueba] La primera suya'];
    //  Se siembra la cadena a mano: hoy el dominio nunca propone otra del
    //  dueño como alternativa (META_ALTERNATIVAS solo ofrece formatos que el
    //  corillo produce entero), pero la PRESENTACIÓN tiene que aguantarlo.
    $pdo->prepare("INSERT INTO crecer_meta_tactica
            (meta_id, plan_id, marca_id, orden, semana, tipo, titulo, por_que,
             clase, quien, estado, piezas_meta, formato, sustituye_a_id)
          VALUES (?,?,?,?,1,'contenido',?,?, 'accion_dueno','corillo','pendiente',0,'post',?)")
        ->execute([(int)$meta_d2['id'], (int)$plan_d2['id'], $MD2, 1,
                   '[prueba] La alternativa, también suya',
                   'la anterior no se podía', $T1]);
    $pdo->prepare("UPDATE crecer_meta_tactica SET estado='descartada', sustituida_at=NOW(),
                      sustituida_por_id=? WHERE id=?")
        ->execute([(int)$pdo->lastInsertId(), $T1]);
    $res_d2 = semana_resumen($pdo, $MD2, $meta_d2, $plan_d2, '/crecer/panel');
    ok('la alternativa suya es una decisión disponible',
       ($res_d2['estado'] ?? '') === 'pendiente' && (int)($res_d2['pend_tarea'] ?? 0) === 1,
       json_encode($res_d2));
    ok('y la original no ocupa sitio', (int)($res_d2['total'] ?? 0) === 1, json_encode($res_d2));

    // ══════════════════════════════════════════════════════════════
    //  5 · LA PANTALLA · lo que de verdad recibe el navegador
    // ══════════════════════════════════════════════════════════════
    if ($hay_http) {
        echo "\n  — la semana, pedida por HTTP —\n";
        $sid = sesion((int)$fb['usuario_id']);
        $html = pagina($sid, $MB, '&vista=semana&pos=3');
        $vis  = visible($html);
        ok('la página respondió', mb_strlen($html) > 500);
        ok('la etiqueta dice que le toca a él',
           mb_stripos($vis, 'Te toca a ti') !== false, 'sin eso parece trabajo del corillo');
        ok('con el título real de la jugada',
           mb_strpos($vis, 'Pon tus precios') !== false);
        ok('y el porqué',
           mb_stripos($vis, 'confianza y conseguir más pedidos') !== false);
        ok('ofrece «Ya lo hice»', mb_stripos($vis, 'Ya lo hice') !== false);
        ok('y la salida a cambiarla', mb_stripos($vis, 'No puedo con esta') !== false);
        ok('NO dice que está preparando esa publicación',
           preg_match('~Te toca a ti[\s\S]{0,900}Estoy preparando esta publicaci~u', $vis) !== 1);
        ok('la cabecera no llama publicación a una tarea',
           mb_stripos($vis, 'Publicación 3 de 3') === false,
           'la semana mezcla publicaciones y acciones: la cuenta tiene que ser honesta');

        //  Y EL HANDLER, POR HTTP, con su CSRF.
        echo "\n  — «Ya lo hice» por HTTP —\n";
        $csrf = csrf_de($sid);
        ok('la sesión tiene csrf', $csrf !== '');
        $TB = $ids_b['[prueba] Pon tus precios'];

        [$mal] = postear($sid, $MB, ['accion' => 'tactica', 'id' => $TB,
                                     'estado' => 'hecha', 'csrf' => 'no-vale']);
        ok('sin CSRF válido no escribe', empty($mal['ok']), json_encode($mal));
        ok('y la jugada sigue viva',
           (string)$pdo->query("SELECT estado FROM crecer_meta_tactica WHERE id={$TB}")
                       ->fetchColumn() === 'pendiente');

        [$bien] = postear($sid, $MB, ['accion' => 'tactica', 'id' => $TB,
                                      'estado' => 'hecha', 'csrf' => $csrf]);
        ok('con CSRF sí', !empty($bien['ok']), json_encode($bien));
        ok('y quedó hecha',
           (string)$pdo->query("SELECT estado FROM crecer_meta_tactica WHERE id={$TB}")
                       ->fetchColumn() === 'hecha');
        [$otra] = postear($sid, $MB, ['accion' => 'tactica', 'id' => $TB,
                                      'estado' => 'hecha', 'csrf' => $csrf]);
        ok('el reenvío contesta que sí', !empty($otra['ok']), json_encode($otra));

        //  Y AL VOLVER, LA LLEGADA CUENTA OTRA COSA.
        $res_b2 = semana_resumen($pdo, $MB, $meta_b, $plan_b, '/crecer/panel');
        ok('ya no queda decisión disponible', (int)($res_b2['pendientes'] ?? 9) === 0,
           json_encode($res_b2));
        ok('y la semana vuelve a estar preparándose de verdad',
           ($res_b2['estado'] ?? '') === 'preparando',
           'quedan dos publicaciones que SÍ tienen quien las haga');
    } else {
        echo "\n  (sin servidor local: la parte HTTP se salta)\n";
    }

    // ══════════════════════════════════════════════════════════════
    //  6 · NADA ESPERA A UN WORKER QUE NO VA A VENIR
    // ══════════════════════════════════════════════════════════════
    echo "\n  — ninguna tarea espera a nadie —\n";
    $huerfanas = (int)$pdo->query(
        "SELECT COUNT(*) FROM crecer_meta_tactica t
          WHERE t.clase='accion_dueno' AND t.estado IN ('pendiente','en_curso')
            AND t.sustituida_at IS NULL
            AND EXISTS (SELECT 1 FROM crecer_meta_jobs j WHERE j.tactica_id = t.id
                         AND j.estado IN ('queued','working'))")->fetchColumn();
    ok('ninguna acción del dueño tiene un job vivo detrás', $huerfanas === 0,
       $huerfanas . ' esperan a un worker que no la va a completar');

    //  Se miran las TRES funciones de lectura, no el archivo entero: el
    //  archivo tiene una transaccion legitima —semana_retirar_compromiso(),
    //  que si escribe— y afirmar sobre el completo daba una roja señalando
    //  codigo que no era el que se estaba mirando.
    $ms = (string)file_get_contents(__DIR__ . '/../includes/meta_semana.php');
    foreach (['semana_construir', 'semana_accion', 'semana_resumen'] as $fn) {
        $ini = mb_strpos($ms, 'function ' . $fn . '(');
        $cuerpo = $ini === false ? '' : mb_substr($ms, (int)$ini);
        $sig = mb_strpos($cuerpo, "\nfunction ", 10);
        if ($sig !== false) $cuerpo = mb_substr($cuerpo, 0, $sig);
        ok("{$fn}() no abre transaccion", $cuerpo !== ''
           && mb_strpos($cuerpo, 'beginTransaction') === false,
           'leer la semana no escribe');
    }

} catch (Throwable $e) {
    $fallos++; echo "\n  EXCEPCION · " . get_class($e) . ': ' . $e->getMessage()
                  . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    foreach ($limpiar as $mid) { try { Fixture::limpiar($pdo, $mid); } catch (Throwable $e) {} }
    echo "\n  (fixtures limpiadas)\n";
}

echo "\n  — el costo —\n";
ok('cero llamadas reales al modelo',
   $cnt('crecer_ia_log', "modelo <> 'mock'") === $real_antes,
   'antes ' . $real_antes . ' · ahora ' . $cnt('crecer_ia_log', "modelo <> 'mock'"));
ok('cero imágenes y cero cuota',
   $cnt('crecer_img_cuota_asiento') === $cuota_antes,
   'antes ' . $cuota_antes . ' · ahora ' . $cnt('crecer_img_cuota_asiento'));

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  LA TAREA DEL DUEÑO CUMPLE · $n afirmaciones\n\n"
    : "  $fallos FALLAS de $n\n\n";
exit($fallos === 0 ? 0 : 1);
