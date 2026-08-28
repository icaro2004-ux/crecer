<?php
// ============================================================
//  CRECER — CERRAR UNA SEMANA Y ABRIR LA SIGUIENTE, EN PANTALLA
//  tests/test_ciclo_navegador.php
//
//  EL CONTRATO EN PHP dice que el ciclo es correcto. Esto dice lo otro: que el
//  dueño LO PUEDE HACER con el pulgar, en un Android de 360px. El recorrido
//  entero y de verdad —tocando— porque cada uno de estos pasos ya se rompio
//  alguna vez sin que ninguna prueba de servidor se enterara:
//
//    1 · la semana terminada ofrece cerrarla, y la puerta lleva al cierre
//    2 · el cierre enseña numeros REALES y una valoracion OPCIONAL (y que se
//        puede quitar: tocarla dos veces la deselecciona)
//    3 · se pulsa «Preparar la proxima semana» y el boton se apaga solo
//    4 · se RECARGA en mitad del trabajo: la historia no cambia
//    5 · la semana nueva aparece lista
//    6 · y desde ahi se entra a su primera decision
//
//  CERO PROVEEDOR, Y ESTO ES LO DELICADO. La peticion entra por Apache, donde
//  CRECER_TEST_MODE no existe: pulsar el boton llamaria a Gemini con la clave
//  de verdad. Por eso se pone el centinela `includes/_SIN_CREDENCIALES`, que
//  SOLO en localhost hace que el transporte sea `mock`. Se borra en el
//  `finally` y tambien si el proceso muere, y al final se cuenta que no quedo
//  ni una llamada real.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/meta_ciclo.php';
require_once __DIR__ . '/../includes/meta_ejecutar.php';
require_once __DIR__ . '/../includes/material.php';
require_once __DIR__ . '/_png.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nEL CICLO SEMANAL, EN PANTALLA\n" . str_repeat('=', 58) . "\n";

$ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
if (@file_get_contents('http://localhost/crecer/login.php', false, $ctx) === false) {
    echo "\n  SALTADO · el servidor local no responde\n\n"; exit(0);
}
$CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
if (!is_file($CHROME)) { echo "\n  SALTADO · no hay Chrome\n\n"; exit(0); }
if (!ciclo_hay_libro($pdo, true)) {
    echo "\n  SALTADO · falta migrations/2026-08-27_crecer_meta_semana.sql\n\n"; exit(0);
}

$SHOTS = __DIR__ . '/_capturas/ciclo';
if (!is_dir($SHOTS)) @mkdir($SHOTS, 0777, true);

//  EL CENTINELA. Sin esto, pulsar el botón cuesta dinero de verdad. Se borra
//  pase lo que pase: en el finally y también si el proceso se cae.
$CENT = __DIR__ . '/../includes/_SIN_CREDENCIALES';
file_put_contents($CENT, "prueba de navegador · " . date('c') . "\n");
register_shutdown_function(function () use ($CENT) { @unlink($CENT); });

/** Una sesión de verdad para ese usuario, escrita donde PHP la busca. */
function sesion(int $usuario_id): string {
    $sid  = 'cic' . bin2hex(random_bytes(7));
    $ruta = session_save_path() ?: sys_get_temp_dir();
    file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
                      'usuario_id|i:' . $usuario_id . ';');
    return $sid;
}

$M = 0;
try {
    //  UNA SEMANA 1 TERMINADA DE VERDAD: jugadas hechas y piezas decididas.
    //  Ese es el único momento en que la pantalla ofrece cerrar.
    echo "\n  — se siembra una semana 1 terminada —\n";
    $fx = Fixture::crear($pdo, 'ciclonav', true, 'admin');
    $M = (int)$fx['marca_id']; $META = (int)$fx['meta_id']; $PLAN = (int)$fx['plan_id'];
    $SID = sesion((int)$fx['usuario_id']);

    $pdo->prepare("UPDATE crecer_meta SET fecha_inicio=CURDATE(),
                          fecha_limite=DATE_ADD(CURDATE(), INTERVAL 28 DAY)
                    WHERE id=?")->execute([$META]);
    $pdo->prepare("UPDATE crecer_meta_tactica SET estado='hecha', semana=1
                    WHERE plan_id=? AND marca_id=?")->execute([$PLAN, $M]);
    $pdo->prepare("UPDATE crecer_contenido SET estado='publicado', necesita_material=''
                    WHERE marca_id=? AND plan_id=?")->execute([$M, $PLAN]);

    $meta = meta_por_id($pdo, $META, $M);
    $plan = meta_plan_por_id($pdo, $PLAN, $M);

    //  UNA JUGADA DE PRODUCCION SIN PIEZA sigue «preparando» por muy `hecha`
    //  que diga su fila: el dueño no ha visto nada. Se entrega la pieza de
    //  cada una —que es lo que hace el corillo— y se dan por publicadas.
    $pz = $pdo->prepare("INSERT INTO crecer_contenido
             (marca_id,plataforma,tipo,caption,estado,meta_id,plan_id,tactica_id,fecha_programada)
           VALUES (?, 'instagram', 'post', '[prueba] Pieza entregada.', 'publicado', ?, ?, ?, CURDATE())");
    foreach (semana_construir($pdo, $M, $meta, $plan, 1)['items'] as $it) {
        if (empty($it['preparando'])) continue;
        $pz->execute([$M, $META, $PLAN, (int)$it['tactica']['id']]);
    }
    $e0 = ciclo_estado($pdo, $M, $meta, $plan);
    ok('la semana 1 pide cerrarse', $e0['clase'] === 'cerrar', $e0['clase']);

    $ia_antes = (int)$pdo->query("SELECT COUNT(*) FROM crecer_ia_log")->fetchColumn();

    // ══════════════════════════════════════════════════════════════
    //  EL RECORRIDO, EN CHROME A 360
    // ══════════════════════════════════════════════════════════════
    echo "\n  — el recorrido, con el dedo, a 360px —\n";
    $cmd = 'node ' . escapeshellarg(__DIR__ . '/_semana_cierre_probe.mjs') . ' '
         . escapeshellarg($SHOTS) . ' ' . escapeshellarg($SID) . ' ' . $M;
    $salida = (string)shell_exec($cmd . ' 2>&1');
    $R = [];
    foreach (explode("\n", $salida) as $l) {
        $l = trim($l); $i = strpos($l, '=');
        if ($i > 0) $R[substr($l, 0, $i)] = substr($l, $i + 1);
    }
    ok('el navegador completó el recorrido', ($R['OK'] ?? '0') === '1',
       substr($salida, -700));
    if (($R['OK'] ?? '0') !== '1') throw new RuntimeException('sonda caída');
    $leer = fn(string $k) => json_decode((string)($R[$k] ?? '{}'), true) ?: [];

    // ── 1 · LA PUERTA ─────────────────────────────────────────────
    echo "\n  — la semana terminada ofrece cerrarla —\n";
    $P = $leer('PUERTA');
    ok('hay puerta al cierre',          !empty($P['hay']), json_encode($P));
    ok('y al final de la semana se ve', !empty($P['visible']), json_encode($P));
    ok('y dice lo que hace',
       mb_stripos((string)($P['texto'] ?? ''), 'cerrar') !== false, (string)($P['texto'] ?? ''));
    ok('lleva a la vista de cierre',
       str_contains((string)($P['href'] ?? ''), 'vista=cerrar'), (string)($P['href'] ?? ''));

    // ── 2 · EL CIERRE ─────────────────────────────────────────────
    echo "\n  — «Cerramos esta semana»: números reales, opinión opcional —\n";
    $C = $leer('CIERRE');
    ok('la pantalla existe',            !empty($C['hay']), json_encode($C));
    ok('y sabe que toca cerrar',        ($C['estado'] ?? '') === 'cerrar', (string)($C['estado'] ?? ''));
    ok('dice en qué semana va',
       preg_match('/1\D+4/u', (string)($C['paso'] ?? '')) === 1, (string)($C['paso'] ?? ''));
    ok('el título es el del cierre',
       mb_stripos((string)($C['titulo'] ?? ''), 'cerramos') !== false, (string)($C['titulo'] ?? ''));

    //  LOS NÚMEROS SON DE LA BASE, no del navegador: se comparan.
    $res = ciclo_resumen($pdo, $M, $PLAN, 1);
    ok('los números son los de la base',
       ($C['numeros'][0] ?? '') === (string)(int)$res['publicadas']
       && ($C['numeros'][1] ?? '') === (string)(int)$res['acciones'],
       json_encode([$C['numeros'] ?? [], $res['publicadas'], $res['acciones']]));
    ok('publicó algo de verdad', (int)$res['publicadas'] > 0,
       'la fixture publica: si sale 0, el número no prueba nada');

    ok('las tres opciones están',       count((array)($C['opciones'] ?? [])) === 3,
       json_encode($C['opciones'] ?? []));
    ok('ninguna viene marcada',
       !in_array(true, array_map(fn($o) => str_contains((string)$o, ':on'),
                                 (array)($C['opciones'] ?? [])), true),
       json_encode($C['opciones'] ?? []));
    ok('hay dónde contar lo que pasó',  !empty($C['comentario']));
    ok('y se dice que es opcional',
       mb_stripos((string)($C['aux'] ?? ''), 'sin escribir') !== false, (string)($C['aux'] ?? ''));
    ok('el botón de preparar está',     !empty($C['prep']), json_encode($C));
    ok('la salida atrás también',       !empty($C['atras']));

    //  TOCAR DOS VECES LA QUITA: nadie se queda atrapado en una respuesta.
    $T1 = $leer('TRAS_TOCAR'); $T2 = $leer('TRAS_DESTOCAR');
    ok('tocar una opción la marca',
       in_array('peor:on', (array)($T1['opciones'] ?? []), true), json_encode($T1['opciones'] ?? []));
    ok('y tocarla otra vez la quita',
       !in_array('peor:on', (array)($T2['opciones'] ?? []), true), json_encode($T2['opciones'] ?? []));

    // ── 3 · SE PULSA ──────────────────────────────────────────────
    echo "\n  — se pulsa preparar y el botón se apaga solo —\n";
    ok('el botón se pudo pulsar',       ($R['PULSA'] ?? '') === 'true', (string)($R['PULSA'] ?? ''));
    $B = $leer('BOTON_TRAS_PULSAR');
    ok('queda deshabilitado al instante', !empty($B['disabled']) || empty($B['hay']),
       json_encode($B) . ' — sin esto, un doble toque son dos peticiones');

    // ── 4 · RECARGAR EN MITAD ─────────────────────────────────────
    echo "\n  — recargar en mitad del trabajo no cambia la historia —\n";
    $RC = $leer('RECARGA');
    ok('la pantalla sigue en pie',      !empty($RC['hay']), json_encode($RC));
    //  TRES ESTADOS SON LEGITIMOS AQUI y ninguno es un error: si la peticion
    //  todavia no cuajo se ve el cierre otra vez -y volver a pulsar no hace
    //  dano, porque cerrar es idempotente-; si esta en marcha, «preparando»;
    //  si ya acabo, «preparada». Lo que no puede salir es una pantalla rota.
    ok('y el estado sale de la base',
       in_array((string)($RC['estado'] ?? ''), ['cerrar', 'preparando', 'preparada'], true),
       (string)($RC['estado'] ?? ''));
    ok('no enseña un error',            empty($RC['errVisible']), json_encode($RC));

    // ── 5 · LA SEMANA NUEVA ───────────────────────────────────────
    echo "\n  — la semana nueva, lista —\n";
    $L = $leer('LISTA');
    ok('termina en «preparada»',        ($L['estado'] ?? '') === 'preparada',
       (string)($L['estado'] ?? ''));
    ok('y lo dice con claridad',
       mb_stripos((string)($L['titulo'] ?? ''), 'lista') !== false, (string)($L['titulo'] ?? ''));
    ok('con la puerta a revisar la semana',
       str_contains((string)($L['irHref'] ?? ''), 'vista=semana'), (string)($L['irHref'] ?? ''));

    //  Y EN LA BASE: una semana nueva, en el mismo plan, sin adelantar nada.
    $s2 = (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica
                             WHERE plan_id={$PLAN} AND semana=2")->fetchColumn();
    ok('la semana 2 existe de verdad',  $s2 > 0, (string)$s2);
    ok('sin adelantar la 3 ni la 4',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica
                          WHERE plan_id={$PLAN} AND semana>2")->fetchColumn() === 0);
    ok('en el MISMO plan',
       (int)$pdo->query("SELECT COUNT(DISTINCT plan_id) FROM crecer_meta_tactica
                          WHERE marca_id={$M}")->fetchColumn() === 1);
    ok('y lo que dijo el dueño quedó guardado',
       (string)$pdo->query("SELECT valoracion FROM crecer_meta_semana
                             WHERE plan_id={$PLAN} AND semana=1")->fetchColumn() === 'mejor'
       && str_contains((string)$pdo->query("SELECT comentario FROM crecer_meta_semana
                             WHERE plan_id={$PLAN} AND semana=1")->fetchColumn(), 'horno'));
    ok('ni el plan ni la meta se cerraron',
       (string)$pdo->query("SELECT estado FROM crecer_meta_plan WHERE id={$PLAN}")->fetchColumn() === 'activo'
       && (string)$pdo->query("SELECT estado FROM crecer_meta WHERE id={$META}")->fetchColumn() === 'activa');

    $ia_tras_preparar = (int)$pdo->query("SELECT COUNT(*) FROM crecer_ia_log WHERE marca_id={$M}")->fetchColumn();

    // ── 6 · LA PRIMERA DECISIÓN ───────────────────────────────────
    echo "\n  — y se entra a su primera decisión —\n";
    $D = $leer('DECISION');
    ok('la revisión semanal abre',      !empty($D['hay']), json_encode($D));
    ok('y es la semana 2',
       str_contains((string)($D['texto'] ?? ''), 'Semana 2')
       || str_contains((string)($D['url'] ?? ''), 'vista=semana'),
       (string)($D['url'] ?? '') . ' · ' . mb_substr((string)($D['texto'] ?? ''), 0, 120));

    // ── EL RATO EN QUE TRABAJA, SEMBRADO ───────────────
    //  Con el modelo simulado ese rato dura milisegundos: pillarlo pulsando el
    //  boton es una carrera perdida. Se siembra el estado -que es justo lo que
    //  el dueño se encuentra si vuelve mientras el corillo trabaja- y se mira.
    echo "\n  — mientras el corillo trabaja —\n";
    $pdo->prepare("UPDATE crecer_meta_semana SET estado='preparando'
                    WHERE plan_id=? AND semana=1")->execute([$PLAN]);
    $salida2 = (string)shell_exec($cmd . ' espera 2>&1');
    $R2 = [];
    foreach (explode("\n", $salida2) as $l) {
        $l = trim($l); $i = strpos($l, '=');
        if ($i > 0) $R2[substr($l, 0, $i)] = substr($l, $i + 1);
    }
    ok('el navegador vio el estado de espera', ($R2['OK'] ?? '0') === '1',
       substr($salida2, -400));
    $E  = json_decode((string)($R2['ESPERA'] ?? '{}'), true) ?: [];
    $ER = json_decode((string)($R2['ESPERA_RECARGA'] ?? '{}'), true) ?: [];
    $EM = json_decode((string)($R2['ESPERA_MED'] ?? '{}'), true) ?: [];
    ok('dice que esta trabajando', ($E['estado'] ?? '') === 'preparando', (string)($E['estado'] ?? ''));
    ok('y que puede irse',
       mb_stripos((string)($E['texto'] ?? ''), 'puedes cerrar') !== false,
       (string)($E['texto'] ?? ''));
    ok('la salida atras sigue estando', !empty($E['atras']), json_encode($E));
    ok('no le ofrece cerrar otra vez',  empty($E['prep']), json_encode($E));
    ok('recargar no cambia la historia',
       ($ER['estado'] ?? '') === ($E['estado'] ?? 'x'), json_encode($ER));
    ok('sin desbordar a lo ancho', (int)($EM['horiz'] ?? 1) === 0, json_encode($EM));
    ok('nada por debajo de 44px',  empty($EM['chicos']), json_encode($EM['chicos'] ?? []));
    ok('nada por debajo de 14px',  empty($EM['finos']),  json_encode($EM['finos'] ?? []));

    //  Y MIRAR NO GENERA: el sondeo solo lee.
    $ia_esp = (int)$pdo->query("SELECT COUNT(*) FROM crecer_ia_log WHERE marca_id={$M}")->fetchColumn();
    ok('mirar la pantalla no genero nada', $ia_esp === $ia_tras_preparar,
       "antes {$ia_tras_preparar} · ahora {$ia_esp}");
    $pdo->prepare("UPDATE crecer_meta_semana SET estado='preparada'
                    WHERE plan_id=? AND semana=1")->execute([$PLAN]);

    // ── LA LLEGADA DICE LO QUE TOMÓ EN CUENTA ────────────────
    //  El corillo ya preparó la semana; aquí se produce UNA de sus jugadas con
    //  una foto que el dueño había dejado en su Biblioteca —que es lo que hace el
    //  worker en producción—. Sin esto no se puede comprobar la promesa entera:
    //  que la pantalla diga «usé tu foto» SOLO cuando de verdad la usó.
    echo "\n  — la llegada dice lo que tomó en cuenta —\n";
    $dir = rtrim(defined('UPLOADS_PATH') ? UPLOADS_PATH : dirname(__DIR__) . '/uploads', '/\\')
         . DIRECTORY_SEPARATOR . 'marca_' . $M;
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'vitrina.jpg', png_solido(600, 600, 220, 120, 160));
    $pdo->prepare("INSERT INTO crecer_activos (marca_id, tipo, archivo, nombre, nota, origen, estado)
                   VALUES (?, 'imagen', ?, 'La vitrina llena', '', 'subida', 'activo')")
        ->execute([$M, "marca_{$M}/vitrina.jpg"]);
    $FOTO = (int)$pdo->lastInsertId();

    $qt = $pdo->prepare("SELECT id FROM crecer_meta_tactica
                          WHERE plan_id=? AND semana=2 AND clase='produccion'
                       ORDER BY orden ASC LIMIT 1");
    $qt->execute([$PLAN]);
    $TAC2 = (int)($qt->fetchColumn() ?: 0);
    ok('la semana nueva trae una jugada de producción', $TAC2 > 0, (string)$TAC2);
    //  LA VALLA, OTRA VEZ. `jugada_ejecutar()` corre AQUÍ dentro, y aquí no
    //  existe CRECER_TEST_MODE: sin esto llamaría al proveedor de verdad con la
    //  clave de verdad. El centinela solo cuenta en localhost, así que se dice
    //  que este proceso es el servidor local —que es la verdad: la prueba y el
    //  sitio comparten máquina y base—. Y se COMPRUEBA antes de producir nada.
    $_SERVER['HTTP_HOST'] = 'localhost';
    require_once __DIR__ . '/../includes/ia.php';
    ok('el transporte está en simulado', ia_transporte() === 'mock',
       ia_transporte() . ' — si no, producir aquí costaría dinero de verdad');
    if (ia_transporte() !== 'mock') { throw new RuntimeException('sin centinela: no se produce'); }

    if ($TAC2 > 0) {
        $pdo->prepare("UPDATE crecer_meta_tactica SET activo_id=?, piezas_meta=1, formato='post' WHERE id=?")
            ->execute([$FOTO, $TAC2]);
        $rp = jugada_ejecutar($pdo, $M, $TAC2);
        ok('se produce con su foto', !empty($rp['ok']), json_encode($rp));
        $CP = (int)($rp['ids'][0] ?? 0);
        $pz = $CP > 0 ? $pdo->query("SELECT * FROM crecer_contenido WHERE id={$CP}")->fetch(PDO::FETCH_ASSOC) : [];
        ok('y la pieza queda enlazada a su activo',
           (int)($pz['material_activo_id'] ?? 0) === $FOTO, json_encode($pz['material_activo_id'] ?? null));
        //  APROBADA: es lo que hace el dueño, y es lo que la manda al Calendario.
        if ($CP > 0) {
            $pdo->prepare("UPDATE crecer_contenido SET estado='aprobado' WHERE id=? AND marca_id=?")
                ->execute([$CP, $M]);
        }
    }

    $salida3 = (string)shell_exec($cmd . ' cta 2>&1');
    $R3 = [];
    foreach (explode("\n", $salida3) as $l3) {
        $l3 = trim($l3); $i3 = strpos($l3, '=');
        if ($i3 > 0) $R3[substr($l3, 0, $i3)] = substr($l3, $i3 + 1);
    }
    ok('el navegador ve la llegada', ($R3['OK'] ?? '0') === '1', substr($salida3, -400));
    $CTA  = json_decode((string)($R3['CTA'] ?? '{}'), true) ?: [];
    $CTA2 = json_decode((string)($R3['CTA_ABIERTA'] ?? '{}'), true) ?: [];
    $CAL  = json_decode((string)($R3['CALENDARIO'] ?? '{}'), true) ?: [];

    ok('hay resumen de lo que tomó en cuenta', !empty($CTA['hay']), json_encode($CTA));
    ok('con su título',
       mb_stripos((string)($CTA['titulo'] ?? ''), 'tomé en cuenta') !== false, (string)($CTA['titulo'] ?? ''));
    ok('tres renglones como máximo',
       count((array)($CTA['lineas'] ?? [])) <= 3, json_encode($CTA['lineas'] ?? []));
    ok('y uno dice que usó su Biblioteca',
       str_contains(mb_strtolower(implode(' ', (array)($CTA['lineas'] ?? []))), 'biblioteca'),
       json_encode($CTA['lineas'] ?? []));

    //  NADA DE NOMBRES INTERNOS: el dueño no conoce a «la Estratega» ni le
    //  importa qué modelo lo hizo. Le importa su negocio.
    $txt_cta = mb_strtolower(implode(' ', (array)($CTA['lineas'] ?? [])) . ' ' . (string)($CTA2['hojaTexto'] ?? ''));
    foreach (['estratega', 'director de arte', 'gemini', 'openai', 'prompt'] as $palabra) {
        ok("no nombra «{$palabra}»", mb_strpos($txt_cta, $palabra) === false, $txt_cta);
    }
    ok('el detalle está escondido al llegar', empty($CTA['hojaAbierta']), json_encode($CTA));
    ok('hay un «Ver por qué»', !empty($CTA['porque']), json_encode($CTA));
    ok('y al tocarlo se abre',   !empty($CTA2['hojaAbierta']), json_encode($CTA2));
    ok('con una explicación de verdad',
       mb_strlen((string)($CTA2['hojaTexto'] ?? '')) > 40, (string)($CTA2['hojaTexto'] ?? ''));
    ok('el Calendario abre',     !empty($CAL['tieneAlgo']), json_encode($CAL));

    // ── LA MEDIDA A 360 ───────────────────────────────────────────
    echo "\n  — se toca y se lee, a 360px —\n";
    foreach (['CIERRE_MED' => 'el cierre', 'LISTA_MED' => 'la semana lista'] as $k => $etq) {
        $m = $leer($k);
        ok("$etq · sin desbordar a lo ancho", (int)($m['horiz'] ?? 1) === 0,
           'sobran ' . ($m['horiz'] ?? '?') . 'px');
        ok("$etq · nada por debajo de 44px",  empty($m['chicos']), json_encode($m['chicos'] ?? []));
        ok("$etq · nada por debajo de 14px",  empty($m['finos']),  json_encode($m['finos'] ?? []));
        ok("$etq · una sola acción principal", (int)($m['primarias'] ?? 9) === 1,
           json_encode($m['primarias'] ?? null));
        ok("$etq · al llegar al botón, nada lo tapa",
           empty($m['priTapada']) && empty($m['priBajoAyuda']), json_encode($m));
        ok("$etq · la salida está a la vista", !empty($m['salida']), json_encode($m));
    }

    //  Y LA PRIMERA PREGUNTA, SIN BAJAR: si abre y solo ve texto, no sabe qué
    //  se espera de él.
    $mc = $leer('CIERRE_MED');
    ok('la primera pregunta se ve sin bajar', !empty($mc['preguntaSinBajar']),
       json_encode($mc));

    echo "\n  — la pantalla no grita —\n";
    ok('cero alert()',           (string)($R['ALERTAS'] ?? '1') === '0', (string)($R['ALERTAS'] ?? ''));
    ok('cero errores en consola',
       in_array(trim((string)($R['ERRORES'] ?? '[]')), ['[]', ''], true), (string)($R['ERRORES'] ?? ''));

    // ── EL COSTO ──────────────────────────────────────────────────
    echo "\n  — el costo —\n";
    $nuevas = (int)$pdo->query("SELECT COUNT(*) FROM crecer_ia_log WHERE id > 0
                                 AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                                 AND marca_id={$M}")->fetchColumn();
    $reales = (int)$pdo->query("SELECT COUNT(*) FROM crecer_ia_log
                                 WHERE marca_id={$M} AND modelo <> 'mock'")->fetchColumn();
    ok('hubo llamada de Estratega',     $nuevas > 0, (string)$nuevas);
    ok('y NINGUNA fue real',            $reales === 0,
       (string)$reales . ' — el centinela es lo único que separa esta prueba de una factura');
    $gasto = (float)$pdo->query("SELECT COALESCE(SUM(costo_usd),0) FROM crecer_ia_log
                                  WHERE marca_id={$M}")->fetchColumn();
    ok('cero dólares',                  abs($gasto) < 0.000001, (string)$gasto);

    echo "\n  capturas en tests/_capturas/ciclo/*.png\n";

} catch (Throwable $e) {
    $fallos++; $n++;
    echo "\n  EXCEPCION · " . get_class($e) . ': ' . $e->getMessage() . "\n";
} finally {
    @unlink($CENT);
    if ($M > 0) {
        try { Fixture::limpiar($pdo, $M); echo "\n  (fixture limpiada)\n"; }
        catch (Throwable $e) { echo "\n  (no se pudo limpiar: " . $e->getMessage() . ")\n"; }
    }
}

ok('el centinela no quedó puesto', !is_file($CENT),
   'si se queda, el motor local se queda en mock y nadie se entera');

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0 ? "  EL CICLO SEMANAL, EN PANTALLA · {$n} afirmaciones\n\n"
                   : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
