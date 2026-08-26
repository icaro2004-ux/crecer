<?php
// ============================================================
//  CRECER — LA TAREA DEL DUEÑO, VISTA Y PULSADA (bloqueo accion_dueno)
//  tests/test_meta_tarea_navegador.php
//
//  El contrato en PHP dice que el dominio ya no la deja en el limbo. Este dice
//  lo otro: que el dueño la RECONOCE como suya y la puede resolver con el
//  dedo. Se pulsa «Ya lo hice» de verdad, se comprueba que guardó en la base,
//  que la revisión avanzó sola al cierre, que al recargar sigue guardado y que
//  la llegada de 2C —que lee del mismo dominio— ya cuenta otra cosa.
//
//  SEIS ESTADOS SEMBRADOS:
//    solo       — una única acción suya
//    mixta      — dos publicaciones cocinándose y una acción suya
//    hecha      — una acción ya marcada
//    sustProd   — acción sustituida por una publicación que se prepara
//    sustTarea  — acción sustituida por otra acción suya
//    publi      — una publicación normal, para que se note la diferencia
//
//  CERO PROVEEDOR: todo se siembra. Ni una imagen, ni un asiento de cuota.
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
require_once __DIR__ . '/_fixture.php';
error_reporting($__err);

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nLA TAREA DEL DUEÑO, EN PANTALLA\n" . str_repeat('=', 58) . "\n";

if (@file_get_contents('http://localhost/crecer/login.php', false,
        stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]])) === false) {
    echo "\n  SALTADO · el servidor local no responde\n\n"; exit(0);
}
if (!is_file('C:/Program Files/Google/Chrome/Application/chrome.exe')) {
    echo "\n  SALTADO · no hay Chrome\n\n"; exit(0);
}

$cnt = fn(string $t, string $w = '1') => (int)$GLOBALS['pdo']->query(
    "SELECT COUNT(*) FROM {$t} WHERE {$w}")->fetchColumn();
$real_antes  = $cnt('crecer_ia_log', "modelo <> 'mock'");
$cuota_antes = $cnt('crecer_img_cuota_asiento');

$SHOTS = __DIR__ . '/_capturas/tarea';
if (!is_dir($SHOTS)) @mkdir($SHOTS, 0777, true);
$ARTE = '/crecer/assets/brand/crecer-icon.png';

function sesion(int $usuario_id): string {
    $sid  = 'tnv' . bin2hex(random_bytes(7));
    $ruta = session_save_path() ?: sys_get_temp_dir();
    file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
                      'usuario_id|i:' . $usuario_id . ';');
    return $sid;
}

/** Marca + plan + las jugadas pedidas en la semana 1: [clase, titulo, con_pieza]. */
function montar(PDO $pdo, string $etq, array $jugadas, string $arte): array {
    $fx = Fixture::crear($pdo, $etq, true, 'admin');
    $M  = (int)$fx['marca_id'];
    $meta = meta_activa($pdo, $M);
    $plan = meta_plan_activo($pdo, (int)$meta['id']);
    $pdo->prepare("UPDATE crecer_meta SET objetivo='pedidos', cantidad=25,
                      fecha_limite='2026-10-23' WHERE id=?")->execute([(int)$meta['id']]);
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
                ->execute([$M, '[prueba] Caption lista para decidir.', (int)$meta['id'],
                           (int)$plan['id'], $tid, $arte]);
        }
    }
    return [$fx, $meta, $plan, $ids, sesion((int)$fx['usuario_id'])];
}

$limpiar = [];
$estados = [];
try {
    echo "\n  — se siembran seis estados —\n";

    //  SOLO · una acción suya, y al lado una publicación YA decidida.
    //
    //  La compañía no es decorado. Con la acción sola en todo el plan, marcarla
    //  hecha lo dejaba entero terminado: meta_plan_progreso() lo daba por
    //  completo y el handler lo cerraba —comportamiento correcto y de siempre—,
    //  así que al volver no había plan activo y la llegada decía «no pude
    //  terminar el plan». La prueba medía un final que el producto no tiene.
    [$f1, $m1, $p1, $i1, $s1] = montar($pdo, 'tnA', [
        ['accion_dueno', '[prueba] Pedir dos testimonios a clientes', false],
        ['produccion',   '[prueba] Post ya aprobado',                 true],
    ], $ARTE);
    $limpiar[] = $M1 = (int)$f1['marca_id'];
    $T1 = $i1['[prueba] Pedir dos testimonios a clientes'];
    $pdo->prepare("UPDATE crecer_contenido SET estado='aprobado' WHERE tactica_id=?")
        ->execute([$i1['[prueba] Post ya aprobado']]);
    $r1 = semana_resumen($pdo, $M1, $m1, $p1, '/crecer/panel');
    ok('solo · la semana es revisable', ($r1['estado'] ?? '') === 'pendiente', json_encode($r1));
    $estados['solo'] = [$s1, $M1, (int)$r1['pos']];

    //  MIXTA · dos cocinándose y una suya disponible.
    [$f2, $m2, $p2, $i2, $s2] = montar($pdo, 'tnB', [
        ['produccion',   '[prueba] Post cocinándose 1', false],
        ['produccion',   '[prueba] Post cocinándose 2', false],
        ['accion_dueno', '[prueba] Pon tus precios a la vista', false],
    ], $ARTE);
    $limpiar[] = $M2 = (int)$f2['marca_id'];
    $r2 = semana_resumen($pdo, $M2, $m2, $p2, '/crecer/panel');
    ok('mixta · abre en la acción disponible',
       ($r2['estado'] ?? '') === 'pendiente' && (int)$r2['pos'] === 3, json_encode($r2));
    $estados['mixta'] = [$s2, $M2, (int)$r2['pos']];

    //  HECHA · una acción ya marcada.
    [$f3, $m3, $p3, $i3, $s3] = montar($pdo, 'tnC',
        [['accion_dueno', '[prueba] Contesta los DM de ayer', false]], $ARTE);
    $limpiar[] = $M3 = (int)$f3['marca_id'];
    meta_tarea_hecha($pdo, $M3, $i3['[prueba] Contesta los DM de ayer']);
    $estados['hecha'] = [$s3, $M3, 1];

    //  SUST-PROD · su acción sustituida por una publicación que se prepara.
    [$f4, $m4, $p4, $i4, $s4] = montar($pdo, 'tnD',
        [['accion_dueno', '[prueba] Grabar un video de la receta', false]], $ARTE);
    $limpiar[] = $M4 = (int)$f4['marca_id'];
    $T4 = $i4['[prueba] Grabar un video de la receta'];
    $pdo->prepare("INSERT INTO crecer_meta_tactica
            (meta_id, plan_id, marca_id, orden, semana, tipo, titulo, por_que,
             clase, quien, estado, piezas_meta, formato, sustituye_a_id)
          VALUES (?,?,?,1,1,'contenido',?,?, 'produccion','corillo','pendiente',1,'post',?)")
        ->execute([(int)$m4['id'], (int)$p4['id'], $M4,
                   '[prueba] Post con los precios a la vista',
                   'quita la fricción de preguntar', $T4]);
    $pdo->prepare("UPDATE crecer_meta_tactica SET estado='descartada', sustituida_at=NOW(),
                      sustituida_por_id=? WHERE id=?")->execute([(int)$pdo->lastInsertId(), $T4]);
    $r4 = semana_resumen($pdo, $M4, $m4, $p4, '/crecer/panel');
    ok('sustProd · ahora sí se prepara de verdad', ($r4['estado'] ?? '') === 'preparando',
       json_encode($r4));
    $estados['sustProd'] = [$s4, $M4, 1];

    //  SUST-TAREA · su acción sustituida por otra acción suya.
    [$f5, $m5, $p5, $i5, $s5] = montar($pdo, 'tnE',
        [['accion_dueno', '[prueba] La primera suya', false]], $ARTE);
    $limpiar[] = $M5 = (int)$f5['marca_id'];
    $T5 = $i5['[prueba] La primera suya'];
    $pdo->prepare("INSERT INTO crecer_meta_tactica
            (meta_id, plan_id, marca_id, orden, semana, tipo, titulo, por_que,
             clase, quien, estado, piezas_meta, formato, sustituye_a_id)
          VALUES (?,?,?,1,1,'contenido',?,?, 'accion_dueno','corillo','pendiente',0,'post',?)")
        ->execute([(int)$m5['id'], (int)$p5['id'], $M5,
                   '[prueba] Manda un mensaje a tus cinco mejores clientas',
                   'las que ya te compraron son las que más rápido repiten', $T5]);
    $pdo->prepare("UPDATE crecer_meta_tactica SET estado='descartada', sustituida_at=NOW(),
                      sustituida_por_id=? WHERE id=?")->execute([(int)$pdo->lastInsertId(), $T5]);
    $r5 = semana_resumen($pdo, $M5, $m5, $p5, '/crecer/panel');
    ok('sustTarea · la alternativa suya es decisión disponible',
       ($r5['estado'] ?? '') === 'pendiente' && (int)($r5['pend_tarea'] ?? 0) === 1, json_encode($r5));
    $estados['sustTarea'] = [$s5, $M5, (int)$r5['pos']];

    //  PUBLI · una publicación normal. Es el control: tiene que seguir igual.
    [$f6, $m6, $p6, $i6, $s6] = montar($pdo, 'tnF',
        [['produccion', '[prueba] Un post normal', true]], $ARTE);
    $limpiar[] = $M6 = (int)$f6['marca_id'];
    $estados['publi'] = [$s6, $M6, 1];

    // ══════════════════════════════════════════════════════════════
    //  EN CHROME
    // ══════════════════════════════════════════════════════════════
    echo "\n  — se abren en Chrome: 360, 414 y 1440 —\n";
    $args = [escapeshellarg($SHOTS)];
    foreach ($estados as $etq => [$sid, $mid, $pos]) $args[] = escapeshellarg("$etq:$sid:$mid:$pos");
    $salida = shell_exec('node ' . escapeshellarg(__DIR__ . '/_tarea_probe.mjs')
                         . ' ' . implode(' ', $args) . ' 2>&1');
    $R = [];
    foreach (explode("\n", (string)$salida) as $l) {
        $l = trim($l); $i = strpos($l, '=');
        if ($i > 0) $R[substr($l, 0, $i)] = substr($l, $i + 1);
    }
    ok('el navegador recorrió los seis estados', ($R['OK'] ?? '0') === '1',
       substr((string)$salida, -700));
    if (($R['OK'] ?? '0') !== '1') throw new RuntimeException('sonda caída');
    $leer = fn(string $k) => json_decode((string)($R[$k] ?? '{}'), true) ?: [];

    // ── LA TAREA, COMO TAREA ─────────────────────────────────────
    echo "\n  — se ve como lo que es: algo que le toca a él —\n";
    $A = $leer('solo.LEIDO');
    ok('la posición existe',            !empty($A['on']), json_encode($A));
    ok('se pinta como tarea',           !empty($A['esTarea']));
    ok('y NO como publicación con foto vacía', empty($A['hayFoto']),
       'un marco de imagen sin imagen finge que hay un post donde no lo hay');
    ok('la etiqueta dice «Te toca a ti»',
       mb_stripos((string)($A['etiqueta'] ?? ''), 'Te toca a ti') !== false,
       (string)($A['etiqueta'] ?? ''));
    ok('con el título real',
       mb_strpos((string)($A['titulo'] ?? ''), 'Pedir dos testimonios') !== false,
       (string)($A['titulo'] ?? ''));
    ok('dice que está pendiente esta semana',
       mb_stripos((string)($A['estado'] ?? ''), 'Pendiente esta semana') !== false,
       (string)($A['estado'] ?? ''));
    ok('explica por qué ayuda',
       mb_stripos((string)($A['texto'] ?? ''), 'Por qué ayuda') !== false);
    ok('NO dice que la está preparando',
       mb_stripos((string)($A['texto'] ?? ''), 'preparando') === false,
       (string)($A['texto'] ?? ''));
    ok('ni ofrece aprobar algo que no existe', empty($A['aprobar']));

    ok('ofrece «Ya lo hice»',      !empty($A['yaLoHice']));
    ok('y «No puedo con esta»',    !empty($A['noPuedo']));
    ok('que lleva a la sustitución de siempre',
       mb_strpos((string)($A['noPuedoHref'] ?? ''), 'vista=sustituir') !== false,
       (string)($A['noPuedoHref'] ?? ''));
    ok('la salida no promete nada que no vaya a pasar',
       in_array(mb_strtolower(trim((string)($A['salida'] ?? ''))), ['volver después', 'terminar'], true),
       (string)($A['salida'] ?? ''));
    ok('y avisa de que seguirá pendiente',
       mb_stripos((string)($A['texto'] ?? ''), 'Seguirá pendiente') !== false);

    // ── LA CUENTA NO MIENTE ──────────────────────────────────────
    echo "\n  — la cabecera no la llama publicación —\n";
    $B = $leer('mixta.LEIDO');
    ok('en semana mixta la cuenta es común',
       mb_stripos((string)($B['cuenta'] ?? ''), 'Tu semana') !== false
       && mb_stripos((string)($B['cuenta'] ?? ''), 'Publicación') === false,
       (string)($B['cuenta'] ?? ''));
    ok('y abre en la acción, no en las que se cocinan',
       (int)($B['n'] ?? 0) === 3 && !empty($B['esTarea']), json_encode($B));

    $F = $leer('publi.LEIDO');
    ok('una semana de solo publicaciones sigue diciendo «Publicación 1 de 1»',
       mb_stripos((string)($F['cuenta'] ?? ''), 'Publicación 1 de 1') !== false,
       (string)($F['cuenta'] ?? ''));
    ok('y la publicación se sigue viendo como publicación',
       empty($F['esTarea']) && !empty($F['aprobar']), json_encode($F));

    // ── HECHA ────────────────────────────────────────────────────
    echo "\n  — una ya marcada no vuelve a pedir nada —\n";
    $C = $leer('hecha.LEIDO');
    ok('se ve como hecha',   mb_stripos((string)($C['estado'] ?? ''), 'hecha') !== false,
       (string)($C['estado'] ?? ''));
    ok('no ofrece «Ya lo hice» otra vez', empty($C['yaLoHice']));
    ok('ni cambiarla',                    empty($C['noPuedo']));
    ok('y lo dice sin afirmar el resultado de allá afuera',
       mb_stripos((string)($C['texto'] ?? ''), 'Lo marqué como hecho') !== false
       && mb_stripos((string)($C['texto'] ?? ''), 'ya conseguiste') === false,
       (string)($C['texto'] ?? ''));

    // ── SUSTITUIDAS ──────────────────────────────────────────────
    echo "\n  — sustituida: la posición no se pierde —\n";
    $D = $leer('sustProd.LEIDO');
    ok('la alternativa de producción SÍ se está preparando',
       empty($D['esTarea']) && mb_stripos((string)($D['texto'] ?? ''), 'preparando') !== false,
       (string)($D['texto'] ?? ''));
    $E = $leer('sustTarea.LEIDO');
    ok('la alternativa suya vuelve a ser una tarea', !empty($E['esTarea']), json_encode($E));
    ok('con su nuevo título',
       mb_strpos((string)($E['titulo'] ?? ''), 'cinco mejores clientas') !== false,
       (string)($E['titulo'] ?? ''));
    ok('y su «Ya lo hice»', !empty($E['yaLoHice']));

    // ── EL CLIC DE VERDAD ────────────────────────────────────────
    echo "\n  — «Ya lo hice», pulsado con el ratón —\n";
    $CD = $leer('CLIC.DESPUES');
    ok('no salió ningún error',  ($CD['err'] ?? '') === '', (string)($CD['err'] ?? ''));
    ok('guardó en la base',
       (string)$pdo->query("SELECT estado FROM crecer_meta_tactica WHERE id={$T1}")
                   ->fetchColumn() === 'hecha');
    ok('y la revisión avanzó sola a lo siguiente',
       (int)($CD['on'] ?? 0) === 2 && empty($CD['esFin']), json_encode($CD));
    ok('el plan sigue vivo: cerrar una semana no cierra el plan',
       meta_plan_activo($pdo, (int)$m1['id']) !== null,
       'la acción era una de las jugadas, no el plan entero');

    $CI = $leer('CIERRE');
    ok('siguiendo hasta el final se llega al cierre', !empty($CI['esFin']), json_encode($CI));
    ok('que dice que la semana está lista',
       mb_stripos((string)($CI['cierreT'] ?? ''), 'lista') !== false, (string)($CI['cierreT'] ?? ''));
    ok('con 0 sin decidir', (string)($CI['sinDecidir'] ?? '') === '0', (string)($CI['sinDecidir'] ?? ''));
    ok('1 lista para salir',  (string)($CI['listas'] ?? '') === '1', (string)($CI['listas'] ?? ''));
    ok('y 1 acción tuya hecha', (string)($CI['hechas'] ?? '') === '1', (string)($CI['hechas'] ?? ''));

    $TR = $leer('CLIC.TRAS_RECARGA');
    ok('al recargar la posición sigue ahí',  !empty($TR['on']), json_encode($TR));
    ok('y sigue marcada como hecha',
       mb_stripos((string)($TR['estado'] ?? ''), 'hecha') !== false
       || ($TR['clave'] ?? '') === 'tarea_hecha', json_encode($TR));
    ok('sin volver a ofrecer «Ya lo hice»', empty($TR['yaLoHice']), json_encode($TR));

    //  Y LA LLEGADA DE 2C, que lee del MISMO dominio.
    echo "\n  — y Tu Meta ya cuenta otra cosa —\n";
    $LL = $leer('CLIC.LLEGADA');
    ok('la llegada dice que la semana está lista',
       ($LL['estado'] ?? '') === 'lista', json_encode($LL));
    ok('sin llamarla publicación',
       mb_stripos((string)($LL['semana'] ?? ''), 'publicaci') === false,
       (string)($LL['semana'] ?? ''));

    // ── «NO PUEDO CON ESTA» LLEVA A LA SUSTITUCIÓN ───────────────
    echo "\n  — «No puedo con esta» abre lo que ya existe —\n";
    $SU = $leer('SUST.DESTINO');
    ok('termina en la sustitución',
       mb_strpos((string)($SU['url'] ?? ''), 'vista=sustituir') !== false, (string)($SU['url'] ?? ''));
    ok('y vuelve a esta posición al terminar',
       mb_strpos((string)($SU['url'] ?? ''), 'desde=semana') !== false
       && mb_strpos((string)($SU['url'] ?? ''), 'pos=3') !== false, (string)($SU['url'] ?? ''));
    ok('no es un wizard nuevo', !empty($SU['hayWizard']), json_encode($SU));

    // ── LA MEDIDA ────────────────────────────────────────────────
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
            if (!empty($m['primarias'])) {
                ok("$etq @$w · la acción se ve sin bajar", !empty($m['priVisible']),
                   (string)($m['priRect'] ?? '') . ' techo ' . (string)($m['techo'] ?? ''));
                ok("$etq @$w · y nada la tapa",
                   empty($m['priTapada']) && empty($m['priBajoAyuda']), json_encode($m));
            }
        }
    }

    echo "\n  — la pantalla no grita —\n";
    ok('cero alert()', (string)($R['ALERTAS'] ?? '1') === '0', (string)($R['ALERTAS'] ?? ''));
    ok('cero errores en consola',
       in_array(trim((string)($R['ERRORES'] ?? '[]')), ['[]', ''], true), (string)($R['ERRORES'] ?? ''));
    echo "\n  capturas en tests/_capturas/tarea/\n";

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
    ? "  SE VE, SE TOCA Y SE CIERRA · $n afirmaciones\n\n"
    : "  $fallos FALLAS de $n\n\n";
exit($fallos === 0 ? 0 : 1);
