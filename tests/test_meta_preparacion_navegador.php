<?php
// ============================================================
//  CRECER — LA PREPARACION, VISTA POR EL DUEÑO (TRAMO 2B)
//  tests/test_meta_preparacion_navegador.php
//
//  EL CONTRATO EN PHP dice que el estado es correcto. Este dice algo distinto
//  y que ninguna prueba de servidor puede decir: que el dueño LO VE. Que en un
//  Android de 360px la salida esta a la vista sin bajar, que no se le ofrece
//  una puerta hacia una semana sin decisiones, que recargar no le cambia la
//  historia, y que cuando algo falla no se queda encerrado.
//
//  TRES ESTADOS REALES, sembrados en la base:
//    A · preparando — hay plan y hay jobs vivos, todavia no hay que decidir
//    B · pendiente  — ya hay una pieza que decidir: la puerta se abre
//    C · sin_plan   — la meta existe y el plan no: error con salida y reintento
//
//  CERO PROVEEDOR: nada se genera. El plan y las piezas se siembran a mano y
//  el encolado no se dispara. Aqui no se gasta un centavo.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/meta_negocio.php';
require_once __DIR__ . '/../includes/meta_semana.php';
require_once __DIR__ . '/../includes/meta_async.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nLA PREPARACION, EN PANTALLA\n" . str_repeat('=', 58) . "\n";

$ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
if (@file_get_contents('http://localhost/crecer/login.php', false, $ctx) === false) {
    echo "\n  SALTADO · el servidor local no responde\n\n"; exit(0);
}
$CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
if (!is_file($CHROME)) { echo "\n  SALTADO · no hay Chrome\n\n"; exit(0); }

$SHOTS = __DIR__ . '/_capturas/preparacion';
if (!is_dir($SHOTS)) @mkdir($SHOTS, 0777, true);
$ARTE = '/crecer/assets/brand/crecer-icon.png';

/** Una sesion de verdad para esa marca, escrita donde PHP la busca. */
function sesion(int $usuario_id): string {
    $sid  = 'prep' . bin2hex(random_bytes(7));
    $ruta = session_save_path() ?: sys_get_temp_dir();
    file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
                      'usuario_id|i:' . $usuario_id . ';');
    return $sid;
}

$limpiar = [];
$estados = [];   //  etq => [sid, marca_id]

try {
    // ══════════════════════════════════════════════════════════════
    //  SEMBRAR LOS TRES ESTADOS
    // ══════════════════════════════════════════════════════════════
    echo "\n  — se siembran tres estados reales —\n";

    //  A · PREPARANDO. Plan vivo, jugadas de la semana 1 con job encolado y
    //     ninguna pieza todavia: exactamente el rato posterior a confirmar.
    $fa = Fixture::crear($pdo, 'prepA', true, 'admin');
    $limpiar[] = $MA = (int)$fa['marca_id'];
    $meta_a = meta_activa($pdo, $MA);
    $plan_a = meta_plan_activo($pdo, (int)$meta_a['id']);
    $pdo->prepare("UPDATE crecer_meta_tactica SET semana=9 WHERE meta_id=?")
        ->execute([(int)$meta_a['id']]);
    $TA = (int)$fa['tacticas'][0];
    $pdo->prepare("UPDATE crecer_meta_tactica
                      SET semana=1, orden=1, estado='pendiente', clase='produccion'
                    WHERE id=?")->execute([$TA]);
    foreach ($fa['piezas'] as $p) {
        $pdo->prepare("UPDATE crecer_contenido SET tactica_id=NULL WHERE id=?")->execute([(int)$p]);
    }
    //  El job, encolado sin disparar: encolar escribe una fila; disparar
    //  cruzaria a otro proceso con las credenciales de verdad.
    $enc_a = meta_encolar_primera_semana($pdo, $MA, (int)$meta_a['id'], false);
    ok('el estado A tiene un job vivo', (int)$enc_a['jobs'] > 0, json_encode($enc_a));
    $estados['preparando'] = [sesion((int)$fa['usuario_id']), $MA];

    //  B · PENDIENTE. La misma escena un minuto despues: ya hay una pieza en
    //     borrador. Es el unico estado en el que la puerta puede abrirse.
    $fb = Fixture::crear($pdo, 'prepB', true, 'admin');
    $limpiar[] = $MB = (int)$fb['marca_id'];
    $meta_b = meta_activa($pdo, $MB);
    $plan_b = meta_plan_activo($pdo, (int)$meta_b['id']);
    $pdo->prepare("UPDATE crecer_meta_tactica SET semana=9 WHERE meta_id=?")
        ->execute([(int)$meta_b['id']]);
    $TB = (int)$fb['tacticas'][0];
    $pdo->prepare("UPDATE crecer_meta_tactica
                      SET semana=1, orden=1, estado='pendiente', clase='produccion'
                    WHERE id=?")->execute([$TB]);
    foreach ($fb['piezas'] as $p) {
        $pdo->prepare("UPDATE crecer_contenido SET tactica_id=NULL WHERE id=?")->execute([(int)$p]);
    }
    $pdo->prepare("INSERT INTO crecer_contenido
            (marca_id,plataforma,tipo,caption,estado,meta_id,plan_id,tactica_id,
             fecha_programada,grafica_path)
          VALUES (?, 'instagram','post',?, 'borrador',?,?,?, DATE_ADD(NOW(), INTERVAL 2 DAY), ?)")
        ->execute([$MB, '[prueba] Texto listo para decidir.', (int)$meta_b['id'],
                   (int)$plan_b['id'], $TB, $ARTE]);
    $estados['pendiente'] = [sesion((int)$fb['usuario_id']), $MB];

    //  C · SIN PLAN. La meta se creo y el plan no llego a existir: es el fallo
    //     que de verdad ocurre cuando la Estratega no contesta.
    $fc = Fixture::crear($pdo, 'prepC', true, 'admin');
    $limpiar[] = $MC = (int)$fc['marca_id'];
    $meta_c = meta_activa($pdo, $MC);
    $pdo->prepare("UPDATE crecer_meta_plan SET estado='descartado' WHERE meta_id=?")
        ->execute([(int)$meta_c['id']]);
    ok('el estado C se quedó sin plan',
       meta_plan_activo($pdo, (int)$meta_c['id']) === null);
    $estados['sinplan'] = [sesion((int)$fc['usuario_id']), $MC];

    // ══════════════════════════════════════════════════════════════
    //  MIRARLOS EN UN NAVEGADOR
    // ══════════════════════════════════════════════════════════════
    echo "\n  — se abren en Chrome: 360, 414 y 1440 —\n";
    $args = [escapeshellarg($SHOTS)];
    foreach ($estados as $etq => [$sid, $mid]) $args[] = escapeshellarg("$etq:$sid:$mid");
    $cmd = 'node ' . escapeshellarg(__DIR__ . '/_preparando_probe.mjs') . ' ' . implode(' ', $args);
    $salida = shell_exec($cmd . ' 2>&1');
    $R = [];
    foreach (explode("\n", (string)$salida) as $l) {
        $l = trim($l); $i = strpos($l, '=');
        if ($i > 0) $R[substr($l, 0, $i)] = substr($l, $i + 1);
    }
    ok('el navegador recorrió los tres estados', ($R['OK'] ?? '0') === '1',
       substr((string)$salida, -600));
    if (($R['OK'] ?? '0') !== '1') { throw new RuntimeException('sonda caída'); }

    $leer = fn(string $k) => json_decode((string)($R[$k] ?? '{}'), true) ?: [];

    // ── A · PREPARANDO ────────────────────────────────────────────
    echo "\n  — «estoy preparando»: sin puerta falsa, con salida —\n";
    $A = $leer('preparando.LEIDO');
    ok('la pantalla existe',            !empty($A['hay']), json_encode($A));
    ok('y sabe que está preparando',    ($A['estado'] ?? '') === 'preparando', $A['estado'] ?? '');
    ok('el título habla de preparar',
       mb_stripos((string)($A['titulo'] ?? ''), 'prepar') !== false, (string)($A['titulo'] ?? ''));
    ok('el paso «recibí tu meta» está hecho',
       in_array('meta:ok', (array)($A['pasos'] ?? []), true), json_encode($A['pasos'] ?? []));
    ok('el paso «el plan» está hecho',
       in_array('plan:ok', (array)($A['pasos'] ?? []), true), json_encode($A['pasos'] ?? []));
    ok('y el de la semana está ocurriendo ahora',
       in_array('semana:ahora', (array)($A['pasos'] ?? []), true), json_encode($A['pasos'] ?? []));
    ok('NO se ofrece revisar una semana sin decisiones', empty($A['ir']),
       'mandarlo a una pantalla vacía es peor que hacerlo esperar');
    ok('la salida a Tu Meta sí está',   !empty($A['volver']));
    ok('no se le pide que espere ahí',
       mb_stripos((string)($A['nota'] ?? ''), 'puedes') !== false
       || mb_stripos((string)($A['texto'] ?? ''), 'puedes irte') !== false
       || mb_stripos((string)($A['texto'] ?? ''), 'sigo trabajando') !== false,
       (string)($A['nota'] ?? '') . ' · ' . (string)($A['texto'] ?? ''));

    //  Ninguna promesa que el código no cumpla: la Estratega no lee ni la
    //  Biblioteca ni el Calendario, así que la pantalla no puede nombrarlos.
    $txtA = mb_strtolower((string)($A['texto'] ?? ''));
    ok('no promete leer la Biblioteca',  mb_strpos($txtA, 'bibliotec') === false, $txtA);
    ok('no promete leer el Calendario',  mb_strpos($txtA, 'calendario') === false, $txtA);

    $rec = $leer('preparando.RECARGA');
    ok('recargar no cambia la historia',
       ($rec['estado'] ?? '') === ($A['estado'] ?? 'x')
       && ($rec['titulo'] ?? '') === ($A['titulo'] ?? 'x'),
       json_encode($rec));

    // ── B · PENDIENTE ─────────────────────────────────────────────
    echo "\n  — ya hay algo que decidir: la puerta se abre —\n";
    $B = $leer('pendiente.LEIDO');
    ok('el estado es «pendiente»',      ($B['estado'] ?? '') === 'pendiente', $B['estado'] ?? '');
    ok('y aparece la puerta a la semana', !empty($B['ir']), json_encode($B));
    ok('que lleva a la revisión semanal',
       mb_strpos((string)($B['irHref'] ?? ''), 'vista=semana') !== false, (string)($B['irHref'] ?? ''));
    ok('con la posición donde toca decidir',
       preg_match('/pos=\d+/', (string)($B['irHref'] ?? '')) === 1, (string)($B['irHref'] ?? ''));
    ok('la salida sigue estando',       !empty($B['volver']));

    // ── C · SIN PLAN ──────────────────────────────────────────────
    echo "\n  — cuando falla: ni encierro ni callejón —\n";
    $C = $leer('sinplan.LEIDO');
    ok('el estado dice que no hay plan', ($C['estado'] ?? '') === 'sin_plan', $C['estado'] ?? '');
    ok('se ofrece volver a intentar',    !empty($C['reintentar']), json_encode($C));
    ok('y la salida a Tu Meta',          !empty($C['volver']));
    ok('NO se ofrece una semana que no existe', empty($C['ir']));
    $txtC = mb_strtolower((string)($C['texto'] ?? ''));
    ok('el mensaje no enseña tripas',
       mb_strpos($txtC, 'sql') === false && mb_strpos($txtC, 'exception') === false
       && mb_strpos($txtC, 'pdo') === false && mb_strpos($txtC, 'http 5') === false
       && mb_strpos($txtC, 'gemini') === false && mb_strpos($txtC, 'openai') === false,
       $txtC);

    // ── LA MEDIDA, EN LOS TRES ANCHOS ─────────────────────────────
    echo "\n  — 360, 414 y 1440: se toca y se lee —\n";
    foreach (array_keys($estados) as $etq) {
        foreach (['360', '414', '1440'] as $w) {
            $m = $leer("$etq.MED_$w");
            ok("$etq @$w · sin desbordar a lo ancho", (int)($m['horiz'] ?? 1) === 0,
               'sobran ' . ($m['horiz'] ?? '?') . 'px');
            ok("$etq @$w · nada por debajo de 44px", empty($m['chicos']),
               json_encode($m['chicos'] ?? []));
            ok("$etq @$w · nada por debajo de 14px", empty($m['finos']),
               json_encode($m['finos'] ?? []));
            ok("$etq @$w · una sola acción principal", (int)($m['primarias'] ?? 9) <= 1,
               json_encode($m['primarias'] ?? null));
            ok("$etq @$w · la salida se ve sin bajar", !empty($m['salidaSinScroll']),
               json_encode($m));
            if (!empty($m['primarias'])) {
                ok("$etq @$w · la acción se ve sin bajar", !empty($m['priVisible']),
                   (string)($m['priRect'] ?? '') . ' techo ' . (string)($m['techo'] ?? ''));
                ok("$etq @$w · y nada la tapa",
                   empty($m['priTapada']) && empty($m['priBajoAyuda']), json_encode($m));
            }
        }
    }

    echo "\n  — la pantalla no grita —\n";
    ok('cero alert()',        (string)($R['ALERTAS'] ?? '1') === '0', (string)($R['ALERTAS'] ?? ''));
    ok('cero errores en consola',
       in_array(trim((string)($R['ERRORES'] ?? '[]')), ['[]', ''], true), (string)($R['ERRORES'] ?? ''));
    echo "\n  capturas en tests/_capturas/preparacion/*.png\n";

} catch (Throwable $e) {
    $fallos++; echo "\n  EXCEPCION · " . get_class($e) . ': ' . $e->getMessage() . "\n";
} finally {
    foreach ($limpiar as $mid) { try { Fixture::limpiar($pdo, $mid); } catch (Throwable $e) {} }
}

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  SE VE Y SE TOCA · $n afirmaciones\n\n"
    : "  $fallos FALLAS de $n\n\n";
exit($fallos === 0 ? 0 : 1);
