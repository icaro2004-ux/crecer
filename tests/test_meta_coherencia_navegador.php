<?php
// ============================================================
//  CRECER — SEMANA, TU META Y HOME, EN PANTALLA Y A LA VEZ
//  tests/test_meta_coherencia_navegador.php
//
//  El recorrido con el ratón, que es donde el dueño ve la contradicción:
//  marcar la última tarea, ver el cierre semanal, volver a Tu Meta, ir a
//  Inicio, volver, recargar, abrir el plan explicado y cerrarlo. Lo que se
//  afirma no es que cada pantalla esté bien por su cuenta: es que ninguna
//  contradiga a las otras sobre el mismo plan.
//
//  CERO PROVEEDOR: todo se siembra. Se cuentan planes, metas, jobs, contenido,
//  llamadas al modelo y asientos de cuota antes y después.
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

echo "\nLA MISMA HISTORIA, EN LAS TRES PANTALLAS\n" . str_repeat('=', 58) . "\n";

if (@file_get_contents('http://localhost/crecer/login.php', false,
        stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]])) === false) {
    echo "\n  SALTADO · el servidor local no responde\n\n"; exit(0);
}
if (!is_file('C:/Program Files/Google/Chrome/Application/chrome.exe')) {
    echo "\n  SALTADO · no hay Chrome\n\n"; exit(0);
}

$cnt = fn(string $t, string $w = '1') => (int)$GLOBALS['pdo']->query(
    "SELECT COUNT(*) FROM {$t} WHERE {$w}")->fetchColumn();
$g = ['ia' => $cnt('crecer_ia_log', "modelo <> 'mock'"),
      'cuota' => $cnt('crecer_img_cuota_asiento'),
      'ultimo' => (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM crecer_ia_log")->fetchColumn()];

$SHOTS = __DIR__ . '/_capturas/coherencia';
if (!is_dir($SHOTS)) @mkdir($SHOTS, 0777, true);

$limpiar = [];
try {
    echo "\n  — una meta con una sola acción viva —\n";
    $fx = Fixture::crear($pdo, 'coh', true, 'admin');
    $limpiar[] = $M = (int)$fx['marca_id'];
    $meta = meta_activa($pdo, $M);
    $plan = meta_plan_activo($pdo, (int)$meta['id']);
    $pdo->prepare("UPDATE crecer_meta SET objetivo='pedidos', cantidad=25,
                      fecha_limite='2026-10-23' WHERE id=?")->execute([(int)$meta['id']]);
    $pdo->prepare("UPDATE crecer_meta_tactica SET estado='descartada' WHERE meta_id=?")
        ->execute([(int)$meta['id']]);
    foreach ($fx['piezas'] as $p) {
        $pdo->prepare("UPDATE crecer_contenido SET tactica_id=NULL WHERE id=?")->execute([(int)$p]);
    }
    $pdo->prepare("INSERT INTO crecer_meta_tactica
            (meta_id, plan_id, marca_id, orden, semana, tipo, titulo, que_hacer, por_que,
             clase, quien, estado, piezas_meta, formato)
          VALUES (?,?,?,1,1,'contenido',?,?,?, 'accion_dueno','corillo','pendiente',0,'post')")
        ->execute([(int)$meta['id'], (int)$plan['id'], $M,
                   '[prueba] Pedir dos testimonios a clientes',
                   'Pídeselos por WhatsApp a las dos últimas que compraron.',
                   'Esto ayuda a generar confianza y conseguir más pedidos.']);
    $sid = 'chn' . bin2hex(random_bytes(7));
    file_put_contents((session_save_path() ?: sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'sess_' . $sid,
                      'usuario_id|i:' . (int)$fx['usuario_id'] . ';');

    $res = semana_resumen($pdo, $M, $meta, $plan, '/crecer/panel');
    ok('la semana es revisable', ($res['estado'] ?? '') === 'pendiente', json_encode($res));

    $antes = ['plan' => $cnt('crecer_meta_plan', "marca_id={$M}"),
              'meta' => $cnt('crecer_meta', "marca_id={$M}"),
              'jobs' => $cnt('crecer_meta_jobs', "marca_id={$M}"),
              'cont' => $cnt('crecer_contenido', "marca_id={$M}")];

    echo "\n  — el recorrido, en Chrome —\n";
    $salida = shell_exec('node ' . escapeshellarg(__DIR__ . '/_coherente_probe.mjs') . ' '
        . escapeshellarg($SHOTS) . ' ' . escapeshellarg($sid) . ' ' . $M . ' '
        . (int)$res['pos'] . ' 2>&1');
    $R = [];
    foreach (explode("\n", (string)$salida) as $l) {
        $l = trim($l); $i = strpos($l, '=');
        if ($i > 0) $R[substr($l, 0, $i)] = substr($l, $i + 1);
    }
    ok('el navegador hizo el recorrido', ($R['OK'] ?? '0') === '1', substr((string)$salida, -700));
    if (($R['OK'] ?? '0') !== '1') throw new RuntimeException('sonda caída');
    $leer = fn(string $k) => json_decode((string)($R[$k] ?? '{}'), true) ?: [];
    $txt  = fn(string $k) => (string)($R[$k] ?? '');

    // ── LA SEMANA ────────────────────────────────────────────────
    echo "\n  — marcó su única acción y la semana cerró —\n";
    ok('la tarea estaba ahí', trim($txt('SEMANA.HAY_TAREA')) === 'true');
    $SC = $leer('SEMANA.CIERRE');
    ok('la semana llegó a su cierre', !empty($SC['esFin']), json_encode($SC));
    ok('y el plan quedó completado',
       (string)$pdo->query("SELECT estado FROM crecer_meta_plan WHERE id=" . (int)$plan['id'])
                   ->fetchColumn() === 'completado');

    // ── LAS TRES PANTALLAS ───────────────────────────────────────
    echo "\n  — Tu Meta, Inicio y la llegada dicen lo mismo —\n";
    $tm   = $txt('TUMETA.TEXTO');
    $home = $txt('HOME.TEXTO');
    $lleg = $txt('LLEGADA.TEXTO');

    foreach ([['Tu Meta', $tm], ['Inicio', $home], ['la llegada', $lleg]] as [$como, $t]) {
        ok("{$como} dice que completó el plan",
           mb_stripos($t, 'Completaste este plan') !== false, mb_substr($t, 0, 220));
    }
    foreach ([['Tu Meta', $tm], ['Inicio', $home]] as [$como, $t]) {
        ok("{$como} NO dice que sigue en marcha",
           mb_stripos($t, 'plan está en marcha') === false, mb_substr($t, 0, 220));
        foreach (['No pude terminar', 'Reintentar', 'Estoy preparando',
                  'Revisar mi semana', 'Meta lograda'] as $mentira) {
            ok("{$como} no dice «{$mentira}»", mb_stripos($t, $mentira) === false);
        }
    }
    ok('Tu Meta aclara que terminar el plan no logra la meta',
       mb_stripos($tm, 'no significa que ya se logró') !== false, mb_substr($tm, 0, 300));

    ok('recargar Tu Meta no lo cambia',
       mb_stripos($txt('TUMETA.RECARGA'), 'Completaste este plan') !== false);

    // ── LA CAPA ──────────────────────────────────────────────────
    echo "\n  — la capa del plan terminado —\n";
    $H = $leer('HOJA');
    ok('abre',                  !empty($H['visible']), json_encode($H));
    ok('el foco entra',         !empty($H['focoDentro']));
    ok('nombra la jugada real',
       mb_strpos((string)($H['texto'] ?? ''), 'Pedir dos testimonios') !== false,
       (string)($H['texto'] ?? ''));
    ok('marcada como hecha',
       preg_match('~Pedir dos testimonios[\s\S]{0,200}Hecha~u', (string)($H['texto'] ?? '')) === 1);
    $HC = $leer('HOJA.CERRADA');
    ok('Escape la cierra',        empty($HC['visible']));
    ok('el foco vuelve al botón', !empty($HC['focoEnBoton']), json_encode($HC));
    ok('sin perder el sitio',
       (int)($HC['scroll'] ?? -1) === (int)($R['HOJA.SCROLL_ANTES'] ?? -2),
       'antes ' . ($R['HOJA.SCROLL_ANTES'] ?? '?') . ' · después ' . ($HC['scroll'] ?? '?'));

    // ── LA CAPA 2 · el destino de la acción ──────────────────────
    echo "\n  — «Ver el plan completado» no lleva a un vacío —\n";
    $c2 = $txt('CAPA2.TEXTO');
    ok('la capa del plan enseña su jugada',
       mb_strpos($c2, 'Pedir dos testimonios') !== false, mb_substr($c2, 0, 300));
    ok('y no mezcla planes que no son',
       mb_stripos($c2, 'Paso de relleno') === false,
       'las de la fixture son de este mismo plan pero quedaron descartadas · '
       . mb_substr($c2, 0, 300));

    // ── LA MEDIDA ────────────────────────────────────────────────
    echo "\n  — 360, 414 y 1440 —\n";
    foreach ([['TUMETA.MED_360', 'Tu Meta @360'], ['TUMETA.MED_414', 'Tu Meta @414'],
              ['TUMETA.MED_1440', 'Tu Meta @1440'], ['HOME.MED_360', 'Inicio @360'],
              ['HOME.MED_414', 'Inicio @414'], ['HOME.MED_1440', 'Inicio @1440']] as [$k, $como]) {
        $m = $leer($k);
        ok("$como · sin desbordar a lo ancho", (int)($m['horiz'] ?? 1) === 0,
           'sobran ' . ($m['horiz'] ?? '?') . 'px');
        ok("$como · nada por debajo de 44px", empty($m['chicos']), json_encode($m['chicos'] ?? []));
        ok("$como · nada por debajo de 14px", empty($m['finos']), json_encode($m['finos'] ?? []));
        ok("$como · una sola acción principal", (int)($m['primarias'] ?? 9) <= 1,
           json_encode($m['primarias'] ?? null) . ' · ' . (string)($m['priTx'] ?? ''));
        if (!empty($m['primarias'])) {
            ok("$como · y nada la tapa",
               empty($m['priTapada']) && empty($m['priBajoAyuda']), json_encode($m));
        }
    }

    echo "\n  — la pantalla no grita —\n";
    ok('cero alert()', (string)($R['ALERTAS'] ?? '1') === '0', (string)($R['ALERTAS'] ?? ''));
    ok('cero errores en consola',
       in_array(trim((string)($R['ERRORES'] ?? '[]')), ['[]', ''], true), (string)($R['ERRORES'] ?? ''));

    echo "\n  — mirar las tres pantallas no crea nada —\n";
    foreach (['plan' => 'crecer_meta_plan', 'meta' => 'crecer_meta',
              'jobs' => 'crecer_meta_jobs', 'cont' => 'crecer_contenido'] as $k => $tabla) {
        ok("ni un/a {$k} de más", $cnt($tabla, "marca_id={$M}") === $antes[$k],
           $antes[$k] . ' → ' . $cnt($tabla, "marca_id={$M}"));
    }
    echo "\n  capturas en tests/_capturas/coherencia/\n";

} catch (Throwable $e) {
    $fallos++; echo "\n  EXCEPCION · " . get_class($e) . ': ' . $e->getMessage()
                  . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    foreach ($limpiar as $mid) { try { Fixture::limpiar($pdo, $mid); } catch (Throwable $e) {} }
}

echo "\n  — el costo —\n";
//  CERO POR EL CIERRE, y se dice con precision cual es la unica llamada que SI
//  ocurre: Home genera su «Idea del dia» con Gemini al cargarse. Es una
//  capacidad suya de siempre, no depende del estado del plan y no la toca este
//  arreglo — pero esconderla detras de un «cero llamadas» seria falso, asi que
//  se nombra y se acota.
$reales = (int)$pdo->query(
    "SELECT COUNT(*) FROM crecer_ia_log WHERE modelo <> 'mock'")->fetchColumn();
$ideas  = (int)$pdo->query(
    "SELECT COUNT(*) FROM crecer_ia_log
      WHERE modelo <> 'mock' AND accion = 'Idea del día' AND id > " . (int)$g['ultimo'])->fetchColumn();
ok('ninguna llamada al modelo por el cierre del plan',
   $reales - $ideas === $g['ia'],
   'reales ' . $g['ia'] . ' → ' . $reales . ' · de ellas, ideas del día: ' . $ideas);
ok('y ninguna de la Estratega generando un plan',
   (int)$pdo->query("SELECT COUNT(*) FROM crecer_ia_log
                      WHERE modelo <> 'mock' AND accion LIKE '%plan%' AND id > "
                    . (int)$g['ultimo'])->fetchColumn() === 0,
   'terminar un plan no puede disparar la creación de otro');
ok('cero imágenes y cero cuota', $cnt('crecer_img_cuota_asiento') === $g['cuota'],
   'antes ' . $g['cuota'] . ' · ahora ' . $cnt('crecer_img_cuota_asiento'));

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  NADIE SE CONTRADICE · $n afirmaciones\n\n"
    : "  $fallos FALLAS de $n\n\n";
exit($fallos === 0 ? 0 : 1);
