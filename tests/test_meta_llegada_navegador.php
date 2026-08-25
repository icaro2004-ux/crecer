<?php
// ============================================================
//  CRECER — LA LLEGADA, VISTA POR EL DUEÑO (TRAMO 2C)
//  tests/test_meta_llegada_navegador.php
//
//  EL CONTRATO EN PHP dice que las cifras son correctas. Este dice lo que
//  ninguna prueba de servidor puede decir: que el dueño LO VE y LO PUEDE
//  TOCAR. Que en un Android de 360px el título, su meta, su semana y la acción
//  caben sin bajar; que el botón —pulsado de verdad, con el ratón— termina en
//  la publicación que le toca; que la explicación abre encima, atrapa el foco,
//  cierra con Escape y lo devuelve al mismo sitio; y que al volver de decidir
//  una, el resumen ya cuenta otra cosa.
//
//  CINCO ESTADOS REALES, sembrados en la base:
//    listo      — plan listo y hay decisiones     → «Revisar mi semana»
//    continuar  — ya resolvió una                 → «Continuar revisando…»
//    preparando — todavía no hay nada que decidir → sin primaria
//    lista      — la semana ya está decidida      → «Ver mi semana», secundaria
//    sinplan    — la Estratega no llegó           → la recuperación de 2B
//
//  CERO PROVEEDOR: nada se genera. El plan y las piezas se siembran a mano y
//  el encolado no se dispara. Aquí no se gasta un centavo.
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
require_once __DIR__ . '/../includes/meta_async.php';
require_once __DIR__ . '/_fixture.php';
error_reporting($__err);

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nLA LLEGADA, EN PANTALLA\n" . str_repeat('=', 58) . "\n";

$ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
if (@file_get_contents('http://localhost/crecer/login.php', false, $ctx) === false) {
    echo "\n  SALTADO · el servidor local no responde\n\n"; exit(0);
}
if (!is_file('C:/Program Files/Google/Chrome/Application/chrome.exe')) {
    echo "\n  SALTADO · no hay Chrome\n\n"; exit(0);
}

$cnt = fn(string $t, string $w = '1') => (int)$GLOBALS['pdo']->query(
    "SELECT COUNT(*) FROM {$t} WHERE {$w}")->fetchColumn();
$real_antes  = $cnt('crecer_ia_log', "modelo <> 'mock'");
$cuota_antes = $cnt('crecer_img_cuota_asiento');

$SHOTS = __DIR__ . '/_capturas/llegada';
if (!is_dir($SHOTS)) @mkdir($SHOTS, 0777, true);
$ARTE = '/crecer/assets/brand/crecer-icon.png';

function sesion(int $usuario_id): string {
    $sid  = 'lgn' . bin2hex(random_bytes(7));
    $ruta = session_save_path() ?: sys_get_temp_dir();
    file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
                      'usuario_id|i:' . $usuario_id . ';');
    return $sid;
}

/**
 * Una marca con plan y con N publicaciones decidibles en la semana 1.
 * Devuelve [fixture, meta, plan, ids de las jugadas].
 */
function montar(PDO $pdo, string $etq, int $decidibles, string $arte): array {
    $fx = Fixture::crear($pdo, $etq, true, 'admin');
    $M  = (int)$fx['marca_id'];
    $meta = meta_activa($pdo, $M);
    $plan = meta_plan_activo($pdo, (int)$meta['id']);
    $pdo->prepare("UPDATE crecer_meta SET objetivo='pedidos', cantidad=25,
                      fecha_limite='2026-10-23' WHERE id=?")->execute([(int)$meta['id']]);
    //  Se parte de cero: las jugadas de la fixture, fuera del camino.
    $pdo->prepare("UPDATE crecer_meta_tactica SET semana=9, estado='hecha'
                    WHERE meta_id=?")->execute([(int)$meta['id']]);
    foreach ($fx['piezas'] as $p) {
        $pdo->prepare("UPDATE crecer_contenido SET tactica_id=NULL WHERE id=?")->execute([(int)$p]);
    }

    $ins = $pdo->prepare(
        "INSERT INTO crecer_meta_tactica
            (meta_id, plan_id, marca_id, orden, semana, tipo, titulo, por_que,
             clase, quien, estado, piezas_meta, formato)
         VALUES (?,?,?,?,?,'contenido',?,?,?,'corillo','pendiente',?,'post')");
    $ids = [];
    for ($i = 1; $i <= $decidibles; $i++) {
        $ins->execute([(int)$meta['id'], (int)$plan['id'], $M, $i, 1,
                       '[prueba] Publicación ' . $i,
                       'así la gente sabe qué pedir y cómo', 'produccion', 1]);
        $tid = (int)$pdo->lastInsertId();
        $ids[] = $tid;
        $pdo->prepare("INSERT INTO crecer_contenido
                (marca_id,plataforma,tipo,caption,estado,meta_id,plan_id,tactica_id,
                 fecha_programada,grafica_path)
              VALUES (?, 'instagram','post',?, 'borrador',?,?,?, DATE_ADD(NOW(), INTERVAL ? DAY), ?)")
            ->execute([$M, '[prueba] Caption ' . $i . ' lista para decidir.', (int)$meta['id'],
                       (int)$plan['id'], $tid, $i + 1, $arte]);
    }
    //  Una que le toca a él: el reparto tiene que tener las dos mitades.
    $ins->execute([(int)$meta['id'], (int)$plan['id'], $M, 90, 3,
                   '[prueba] Pon tus precios a la vista',
                   'sin precios la gente pregunta y se va', 'accion_dueno', 0]);
    $ids['dueno'] = (int)$pdo->lastInsertId();

    return [$fx, $meta, $plan, $ids];
}

$limpiar = [];
$estados = [];
try {
    echo "\n  — se siembran cinco estados reales —\n";

    //  A · LISTO · tres decisiones esperando.
    [$fa, $meta_a, $plan_a, $ids_a] = montar($pdo, 'lgA', 3, $ARTE);
    $limpiar[] = $MA = (int)$fa['marca_id'];
    $res_a = semana_resumen($pdo, $MA, $meta_a, $plan_a, '/crecer/panel');
    ok('A · tres decisiones esperando', (int)$res_a['pendientes'] === 3, json_encode($res_a));
    $estados['listo'] = [sesion((int)$fa['usuario_id']), $MA];

    //  B · CONTINUAR · la primera ya está decidida.
    [$fb, $meta_b, $plan_b, $ids_b] = montar($pdo, 'lgB', 3, $ARTE);
    $limpiar[] = $MB = (int)$fb['marca_id'];
    $pdo->prepare("UPDATE crecer_contenido SET estado='aprobado' WHERE tactica_id=?")
        ->execute([(int)$ids_b[0]]);
    $res_b = semana_resumen($pdo, $MB, $meta_b, $plan_b, '/crecer/panel');
    ok('B · ya resolvió una', !empty($res_b['continua']) && (int)$res_b['pos'] === 2,
       json_encode($res_b));
    $estados['continuar'] = [sesion((int)$fb['usuario_id']), $MB];

    //  C · PREPARANDO · jugada viva sin pieza y su job encolado.
    [$fc, $meta_c, $plan_c, $ids_c] = montar($pdo, 'lgC', 1, $ARTE);
    $limpiar[] = $MC = (int)$fc['marca_id'];
    $pdo->prepare("DELETE FROM crecer_contenido WHERE tactica_id=?")->execute([(int)$ids_c[0]]);
    meta_encolar_primera_semana($pdo, $MC, (int)$meta_c['id'], false);
    $res_c = semana_resumen($pdo, $MC, $meta_c, $plan_c, '/crecer/panel');
    ok('C · nada que decidir todavía', ($res_c['estado'] ?? '') === 'preparando',
       json_encode($res_c));
    $estados['preparando'] = [sesion((int)$fc['usuario_id']), $MC];

    //  D · LISTA · todo decidido.
    [$fd, $meta_d, $plan_d, $ids_d] = montar($pdo, 'lgD', 2, $ARTE);
    $limpiar[] = $MD = (int)$fd['marca_id'];
    $pdo->prepare("UPDATE crecer_contenido SET estado='aprobado' WHERE marca_id=? AND plan_id=?")
        ->execute([$MD, (int)$plan_d['id']]);
    $res_d = semana_resumen($pdo, $MD, $meta_d, $plan_d, '/crecer/panel');
    ok('D · la semana ya está decidida', ($res_d['estado'] ?? '') === 'lista',
       json_encode($res_d));
    $estados['lista'] = [sesion((int)$fd['usuario_id']), $MD];

    //  E · SIN PLAN · la recuperación de 2B.
    $fe = Fixture::crear($pdo, 'lgE', true, 'admin');
    $limpiar[] = $ME = (int)$fe['marca_id'];
    $meta_e = meta_activa($pdo, $ME);
    $pdo->prepare("UPDATE crecer_meta_plan SET estado='descartado' WHERE meta_id=?")
        ->execute([(int)$meta_e['id']]);
    ok('E · se quedó sin plan', meta_plan_activo($pdo, (int)$meta_e['id']) === null);
    $estados['sinplan'] = [sesion((int)$fe['usuario_id']), $ME];

    // ══════════════════════════════════════════════════════════════
    //  EN CHROME
    // ══════════════════════════════════════════════════════════════
    echo "\n  — se abren en Chrome: 360, 414 y 1440 —\n";
    $args = [escapeshellarg($SHOTS)];
    foreach ($estados as $etq => [$sid, $mid]) $args[] = escapeshellarg("$etq:$sid:$mid");
    $salida = shell_exec('node ' . escapeshellarg(__DIR__ . '/_llegada_probe.mjs')
                         . ' ' . implode(' ', $args) . ' 2>&1');
    $R = [];
    foreach (explode("\n", (string)$salida) as $l) {
        $l = trim($l); $i = strpos($l, '=');
        if ($i > 0) $R[substr($l, 0, $i)] = substr($l, $i + 1);
    }
    ok('el navegador recorrió los cinco estados', ($R['OK'] ?? '0') === '1',
       substr((string)$salida, -700));
    if (($R['OK'] ?? '0') !== '1') throw new RuntimeException('sonda caída');
    $leer = fn(string $k) => json_decode((string)($R[$k] ?? '{}'), true) ?: [];

    // ── A · EL RESUMEN ───────────────────────────────────────────
    echo "\n  — A · lo que entiende sin que nadie le explique —\n";
    $A = $leer('listo.LEIDO');
    ok('titula con el resultado',       ($A['titulo'] ?? '') === 'Tu plan está listo', $A['titulo'] ?? '');
    ok('sale el resumen, no los pasos', !empty($A['resumen']) && empty($A['pasos']));
    ok('dice su meta con número y fecha',
       mb_strpos((string)($A['meta'] ?? ''), '25') !== false
       && mb_stripos((string)($A['meta'] ?? ''), 'octubre') !== false, (string)($A['meta'] ?? ''));
    ok('dice cuántas hay que revisar',
       mb_strpos((string)($A['semana'] ?? ''), '3') !== false
       && mb_stripos((string)($A['semana'] ?? ''), 'revisar') !== false, (string)($A['semana'] ?? ''));
    ok('dice de qué se encarga el corillo',
       mb_stripos((string)($A['texto'] ?? ''), 'se encarga de') !== false);
    ok('y en qué necesita su ayuda',
       mb_stripos((string)($A['texto'] ?? ''), 'tu ayuda en') !== false);
    ok('ofrece revisar la semana',     !empty($A['ir']) && $A['irTx'] === 'Revisar mi semana',
       json_encode($A));
    ok('y la explicación, como secundaria', !empty($A['explica']));
    ok('la salida sigue estando',      !empty($A['volver']));

    $rec = $leer('listo.RECARGA');
    ok('recargar no cambia las cifras',
       ($rec['meta'] ?? '') === ($A['meta'] ?? 'x')
       && ($rec['semana'] ?? '') === ($A['semana'] ?? 'x'), json_encode($rec));

    // ── B · CONTINUAR ────────────────────────────────────────────
    echo "\n  — B · si ya empezó, se continúa —\n";
    $B = $leer('continuar.LEIDO');
    ok('el botón dice «continuar»', ($B['irTx'] ?? '') === 'Continuar revisando mi semana',
       (string)($B['irTx'] ?? ''));
    ok('y apunta a la que le toca, no a la primera',
       mb_strpos((string)($B['irHref'] ?? ''), 'pos=' . (int)$res_b['pos']) !== false,
       (string)($B['irHref'] ?? ''));
    ok('la cifra baja a 2',
       mb_strpos((string)($B['semana'] ?? ''), '2') !== false, (string)($B['semana'] ?? ''));

    // ── C · PREPARANDO ───────────────────────────────────────────
    echo "\n  — C · sin primaria falsa —\n";
    $C = $leer('preparando.LEIDO');
    ok('dice que está preparando',
       mb_stripos((string)($C['titulo'] ?? ''), 'preparando') !== false, (string)($C['titulo'] ?? ''));
    ok('enseña los pasos, no el resumen', !empty($C['pasos']) && empty($C['resumen']));
    ok('NO ofrece revisar una semana vacía', empty($C['ir']));
    ok('ni la explicación todavía',         empty($C['explica']));
    ok('pero sí la salida',                 !empty($C['volver']));

    // ── D · LISTA ────────────────────────────────────────────────
    echo "\n  — D · ya está todo decidido —\n";
    $D = $leer('lista.LEIDO');
    ok('dice que la semana está lista',
       mb_stripos((string)($D['semana'] ?? ''), 'lista') !== false, (string)($D['semana'] ?? ''));
    ok('NO ofrece trabajo que no hay', empty($D['ir']));
    ok('pero deja verla',              !empty($D['ver']));

    // ── E · SIN PLAN ─────────────────────────────────────────────
    echo "\n  — E · el plan no llegó a existir —\n";
    $E = $leer('sinplan.LEIDO');
    ok('NO pinta el resumen de éxito', empty($E['resumen'])
       && mb_stripos((string)($E['titulo'] ?? ''), 'plan está listo') === false,
       (string)($E['titulo'] ?? ''));
    ok('ofrece reintentar',            !empty($E['reintentar']));
    ok('y la salida',                  !empty($E['volver']));

    // ── LA MEDIDA, EN LOS TRES ANCHOS ────────────────────────────
    echo "\n  — 360, 414 y 1440: se toca, se lee y se ve —\n";
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
            if (!empty($m['primarias'])) {
                ok("$etq @$w · la acción se ve sin bajar", !empty($m['priVisible']),
                   (string)($m['priRect'] ?? '') . ' techo ' . (string)($m['techo'] ?? ''));
                ok("$etq @$w · y nada la tapa",
                   empty($m['priTapada']) && empty($m['priBajoAyuda']), json_encode($m));
            }
        }
    }

    //  Y LO QUE EL CONTRATO EXIGE VER SIN DESPLAZAR, en el teléfono.
    echo "\n  — en un Android de 360, todo lo importante cabe —\n";
    $mA = $leer('listo.MED_360');
    foreach (['titulo' => 'el título', 'meta' => 'su meta', 'semana' => 'el estado de la semana',
              'explica' => 'la explicación', 'salida' => 'la salida'] as $k => $como) {
        ok("se ve {$como} sin bajar", ($mA['sinBajar'][$k] ?? null) === true,
           json_encode($mA['sinBajar'] ?? []));
    }

    // ── LA HOJA ──────────────────────────────────────────────────
    echo "\n  — «Mi plan explicado»: encima, con foco y con salida —\n";
    $H = $leer('HOJA.ABIERTA');
    ok('la hoja abre',                 !empty($H['visible']), json_encode($H));
    ok('se anuncia como diálogo',      ($H['dialogo'] ?? '') === 'dialog'
                                    && ($H['modal'] ?? '') === 'true', json_encode($H));
    ok('el foco entra en la hoja',     !empty($H['focoDentro']));
    ok('y no desborda a lo ancho',     (int)($H['horiz'] ?? 1) === 0);
    ok('nada por debajo de 14px',      (int)($H['finos'] ?? 1) === 0);
    ok('su cierre se puede tocar',     ($H['cerrarCaja'] ?? '') === '44x44', (string)($H['cerrarCaja'] ?? ''));

    $txt = (string)($H['texto'] ?? '');
    ok('explica qué buscamos',         mb_stripos($txt, '25 pedidos') !== false, $txt);
    ok('qué hace el corillo',          mb_stripos($txt, 'me encargo yo') !== false, $txt);
    ok('qué necesita de él',           mb_stripos($txt, 'necesito tu ayuda') !== false, $txt);
    ok('nombra jugadas de verdad',     mb_strpos($txt, 'Publicación 1') !== false, $txt);
    ok('con su porqué',                mb_stripos($txt, 'sabe qué pedir') !== false, $txt);
    ok('y cómo entra su material',     mb_stripos($txt, 'busco tus fotos') !== false, $txt);
    ok('sin prometer que el plan ya la revisó',
       mb_stripos($txt, 'revisé tu biblioteca') === false
       && mb_stripos($txt, 'revisando tu biblioteca') === false, $txt);
    ok('sin tripas',                   mb_stripos($txt, 'produccion') === false
                                    && mb_stripos($txt, 'accion_dueno') === false, $txt);

    ok('el foco no se va detrás del modal',
       trim((string)($R['HOJA.FOCO_TRAS_TABS'] ?? '')) === 'true',
       (string)($R['HOJA.FOCO_TRAS_TABS'] ?? ''));
    $T = $leer('HOJA.TRAS_ESCAPE');
    ok('Escape la cierra',             empty($T['visible']), json_encode($T));
    ok('y el foco vuelve al botón',    !empty($T['focoEnBoton']), json_encode($T));
    ok('sin perder el sitio',
       (int)($T['scroll'] ?? -1) === (int)($R['HOJA.SCROLL_ANTES'] ?? -2),
       'antes ' . ($R['HOJA.SCROLL_ANTES'] ?? '?') . ' · después ' . ($T['scroll'] ?? '?'));

    // ── EL BOTÓN, PULSADO DE VERDAD ──────────────────────────────
    echo "\n  — el botón no promete: lleva —\n";
    $CL = $leer('CLIC.DESTINO');
    ok('termina en la revisión semanal',
       mb_strpos((string)($CL['url'] ?? ''), 'vista=semana') !== false, (string)($CL['url'] ?? ''));
    ok('y en la posición que le tocaba',
       (int)($CL['pos'] ?? 0) === (int)$res_a['pos'],
       'llegó a ' . ($CL['pos'] ?? '?') . ' · le tocaba ' . (int)$res_a['pos']);
    ok('con las tres de su semana',    (int)($CL['total'] ?? 0) === (int)$res_a['total'],
       json_encode($CL));
    ok('y con algo que decidir ahí',   !empty($CL['hayAprobar']), json_encode($CL));

    // ── VOLVER: EL RESUMEN YA CUENTA OTRA COSA ───────────────────
    echo "\n  — decide una, vuelve, y la cuenta cambió —\n";
    ok('la aprobó de verdad',
       trim((string)($R['VUELTA.APROBADA'] ?? '')) === 'true',
       (string)($R['VUELTA.APROBADA'] ?? ''));
    $V = $leer('VUELTA.RESUMEN');
    ok('al volver quedan 2',
       mb_strpos((string)($V['semana'] ?? ''), '2') !== false, (string)($V['semana'] ?? ''));
    ok('y ahora dice «continuar»',
       ($V['irTx'] ?? '') === 'Continuar revisando mi semana', (string)($V['irTx'] ?? ''));
    ok('la cuenta cambió respecto a la de antes',
       (string)($V['semana'] ?? '') !== trim((string)($R['VUELTA.ANTES'] ?? '')),
       'antes «' . ($R['VUELTA.ANTES'] ?? '') . '» · ahora «' . ($V['semana'] ?? '') . '»');

    echo "\n  — la pantalla no grita —\n";
    ok('cero alert()',           (string)($R['ALERTAS'] ?? '1') === '0', (string)($R['ALERTAS'] ?? ''));
    ok('cero errores en consola',
       in_array(trim((string)($R['ERRORES'] ?? '[]')), ['[]', ''], true), (string)($R['ERRORES'] ?? ''));
    echo "\n  capturas en tests/_capturas/llegada/\n";

} catch (Throwable $e) {
    $fallos++; echo "\n  EXCEPCION · " . get_class($e) . ': ' . $e->getMessage()
                  . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    foreach ($limpiar as $mid) { try { Fixture::limpiar($pdo, $mid); } catch (Throwable $e) {} }
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
    ? "  SE VE, SE TOCA Y LLEVA · $n afirmaciones\n\n"
    : "  $fallos FALLAS de $n\n\n";
exit($fallos === 0 ? 0 : 1);
