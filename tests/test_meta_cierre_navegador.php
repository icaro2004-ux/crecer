<?php
// ============================================================
//  CRECER — EL CIERRE DEL PLAN, VISTO Y PULSADO
//  tests/test_meta_cierre_navegador.php
//
//  El recorrido entero con el ratón, que es donde vivía la mentira: abrir la
//  semana con una única tarea, pulsar «Ya lo hice», ver el cierre semanal,
//  volver a Tu Meta —y encontrar «Completaste este plan» en vez de «no pude
//  terminar el plan» con un botón de reintentar—, recargar, abrir la capa del
//  plan terminado y cerrarla volviendo al mismo punto.
//
//  CERO PROVEEDOR: todo se siembra, y se cuentan planes, metas, jobs, llamadas
//  al modelo y asientos de cuota antes y después.
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

echo "\nEL CIERRE DEL PLAN, EN PANTALLA\n" . str_repeat('=', 58) . "\n";

if (@file_get_contents('http://localhost/crecer/login.php', false,
        stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]])) === false) {
    echo "\n  SALTADO · el servidor local no responde\n\n"; exit(0);
}
if (!is_file('C:/Program Files/Google/Chrome/Application/chrome.exe')) {
    echo "\n  SALTADO · no hay Chrome\n\n"; exit(0);
}

$cnt = fn(string $t, string $w = '1') => (int)$GLOBALS['pdo']->query(
    "SELECT COUNT(*) FROM {$t} WHERE {$w}")->fetchColumn();
$g_ia    = $cnt('crecer_ia_log', "modelo <> 'mock'");
$g_cuota = $cnt('crecer_img_cuota_asiento');

$SHOTS = __DIR__ . '/_capturas/cierre';
if (!is_dir($SHOTS)) @mkdir($SHOTS, 0777, true);

function sesion(int $usuario_id): string {
    $sid  = 'cnv' . bin2hex(random_bytes(7));
    $ruta = session_save_path() ?: sys_get_temp_dir();
    file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
                      'usuario_id|i:' . $usuario_id . ';');
    return $sid;
}

$limpiar = [];
try {
    echo "\n  — una meta con una sola acción viva —\n";
    $fx = Fixture::crear($pdo, 'cie', true, 'admin');
    $limpiar[] = $M = (int)$fx['marca_id'];
    $meta = meta_activa($pdo, $M);
    $plan = meta_plan_activo($pdo, (int)$meta['id']);
    $pdo->prepare("UPDATE crecer_meta SET objetivo='pedidos', cantidad=25,
                      fecha_limite='2026-10-23' WHERE id=?")->execute([(int)$meta['id']]);
    //  Las de la fixture, descartadas: el plan podrá completarse con la única
    //  que se siembra, que es el caso que destapó el defecto.
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
    $T = (int)$pdo->lastInsertId();
    $sid = sesion((int)$fx['usuario_id']);

    $res = semana_resumen($pdo, $M, $meta, $plan, '/crecer/panel');
    ok('la semana es revisable', ($res['estado'] ?? '') === 'pendiente', json_encode($res));

    //  Y UNA MARCA SIN PLAN, para comprobar que la recuperación sigue viva.
    $fx2 = Fixture::crear($pdo, 'cieSP', true, 'admin');
    $limpiar[] = $M2 = (int)$fx2['marca_id'];
    $meta2 = meta_activa($pdo, $M2);
    $pdo->prepare("DELETE FROM crecer_meta_plan WHERE meta_id=?")->execute([(int)$meta2['id']]);
    $sid2 = sesion((int)$fx2['usuario_id']);
    ok('la otra marca se quedó sin plan',
       meta_plan_activo($pdo, (int)$meta2['id']) === null);

    $antes = ['planes' => $cnt('crecer_meta_plan', "marca_id={$M}"),
              'metas'  => $cnt('crecer_meta',      "marca_id={$M}"),
              'jobs'   => $cnt('crecer_meta_jobs', "marca_id={$M}")];

    // ══════════════════════════════════════════════════════════════
    echo "\n  — el recorrido, en Chrome —\n";
    $salida = shell_exec('node ' . escapeshellarg(__DIR__ . '/_cierre_probe.mjs') . ' '
        . escapeshellarg($SHOTS) . ' ' . escapeshellarg($sid) . ' ' . $M . ' '
        . (int)$res['pos'] . ' ' . escapeshellarg($sid2 . ':' . $M2) . ' 2>&1');
    $R = [];
    foreach (explode("\n", (string)$salida) as $l) {
        $l = trim($l); $i = strpos($l, '=');
        if ($i > 0) $R[substr($l, 0, $i)] = substr($l, $i + 1);
    }
    ok('el navegador hizo el recorrido', ($R['OK'] ?? '0') === '1', substr((string)$salida, -700));
    if (($R['OK'] ?? '0') !== '1') throw new RuntimeException('sonda caída');
    $leer = fn(string $k) => json_decode((string)($R[$k] ?? '{}'), true) ?: [];

    // ── LA SEMANA ────────────────────────────────────────────────
    echo "\n  — marcó su única acción —\n";
    $SA = $leer('SEMANA.ANTES');
    ok('la tarea estaba ahí', !empty($SA['hayTarea']) && !empty($SA['yaLoHice']), json_encode($SA));
    $SC = $leer('SEMANA.CIERRE');
    ok('no hubo error',              ($SC['err'] ?? '') === '', (string)($SC['err'] ?? ''));
    ok('la semana llegó a su cierre', !empty($SC['esFin']), json_encode($SC));
    ok('sin nada por decidir',       (string)($SC['sinDecidir'] ?? '') === '0');
    ok('y el plan quedó completado en la base',
       (string)$pdo->query("SELECT estado FROM crecer_meta_plan WHERE id=" . (int)$plan['id'])
                   ->fetchColumn() === 'completado');

    // ── LA LLEGADA · aquí estaba la mentira ──────────────────────
    echo "\n  — y al volver a Tu Meta: un final, no un fallo —\n";
    $L = $leer('LLEGADA');
    ok('el estado es «plan_completado»', ($L['estado'] ?? '') === 'plan_completado',
       (string)($L['estado'] ?? ''));
    ok('titula «Completaste este plan»',
       ($L['titulo'] ?? '') === 'Completaste este plan', (string)($L['titulo'] ?? ''));
    ok('y dice qué terminó',
       mb_stripos((string)($L['ayuda'] ?? ''), 'Terminaste las acciones') !== false,
       (string)($L['ayuda'] ?? ''));
    ok('NO dice que no pudo terminarlo',
       mb_stripos((string)($L['texto'] ?? ''), 'no pude terminar') === false,
       (string)($L['texto'] ?? ''));
    ok('NO ofrece reintentar',   empty($L['reintentar']), json_encode($L));
    ok('ni puerta a la semana',  empty($L['ir']) && empty($L['ver']));
    ok('ni pasos de preparación', empty($L['pasos']));
    ok('sigue enseñando su meta',
       mb_strpos((string)($L['meta'] ?? ''), '25') !== false, (string)($L['meta'] ?? ''));
    ok('dice que la meta sigue activa',
       mb_stripos((string)($L['texto'] ?? ''), 'Tu Meta sigue activa') !== false);
    ok('sin afirmar que la logró',
       mb_stripos((string)($L['texto'] ?? ''), 'lograda') === false
       && mb_stripos((string)($L['texto'] ?? ''), 'alcanzada') === false,
       (string)($L['texto'] ?? ''));
    ok('ni prometer la próxima semana',
       mb_stripos((string)($L['texto'] ?? ''), 'próxima semana') === false);
    ok('la acción que queda es ver lo que hizo',
       !empty($L['explica']) && !empty($L['explicaPri'])
       && ($L['explicaTx'] ?? '') === 'Ver el plan completado', json_encode($L));
    ok('y la salida a Tu Meta',  !empty($L['volver']));

    $LR = $leer('LLEGADA.RECARGA');
    ok('recargar no lo cambia', ($LR['estado'] ?? '') === 'plan_completado'
       && ($LR['titulo'] ?? '') === ($L['titulo'] ?? 'x'), json_encode($LR));

    // ── LA CAPA ──────────────────────────────────────────────────
    echo "\n  — la capa del plan terminado —\n";
    $H = $leer('HOJA');
    ok('abre',                     !empty($H['visible']), json_encode($H));
    ok('con su nombre de cierre',
       mb_stripos((string)($H['titulo'] ?? ''), 'completaste') !== false, (string)($H['titulo'] ?? ''));
    ok('el foco entra',            !empty($H['focoDentro']));
    ok('no desborda a lo ancho',   (int)($H['horiz'] ?? 1) === 0);
    $txt = (string)($H['texto'] ?? '');
    ok('nombra la jugada que hizo',
       mb_strpos($txt, 'Pedir dos testimonios') !== false, $txt);
    ok('marcada como hecha',
       preg_match('~Pedir dos testimonios[\s\S]{0,200}Hecha~u', $txt) === 1, $txt);
    ok('y no promete un ciclo que nadie encola',
       mb_stripos($txt, 'Cada semana preparo') === false, $txt);
    ok('dice qué pasa de verdad después',
       mb_stripos($txt, 'sigue midiéndose') !== false
       || mb_stripos($txt, 'Tu Meta sigue activa') !== false, $txt);

    $HC = $leer('HOJA.CERRADA');
    ok('Escape la cierra',        empty($HC['visible']), json_encode($HC));
    ok('el foco vuelve al botón', !empty($HC['focoEnBoton']), json_encode($HC));
    ok('y no se pierde el sitio',
       (int)($HC['scroll'] ?? -1) === (int)($R['HOJA.SCROLL_ANTES'] ?? -2),
       'antes ' . ($R['HOJA.SCROLL_ANTES'] ?? '?') . ' · después ' . ($HC['scroll'] ?? '?'));

    // ── LA RECUPERACIÓN SIGUE VIVA ───────────────────────────────
    echo "\n  — y el que de verdad falló sigue pudiendo reintentar —\n";
    $SP = $leer('SINPLAN');
    ok('sin plan, el estado es «sin_plan»', ($SP['estado'] ?? '') === 'sin_plan',
       (string)($SP['estado'] ?? ''));
    ok('sí dice que no pudo terminarlo',
       mb_stripos((string)($SP['texto'] ?? ''), 'no pude terminar') !== false);
    ok('y ofrece reintentar',   !empty($SP['reintentar']));
    ok('sin hablar de cierre',
       mb_stripos((string)($SP['texto'] ?? ''), 'Completaste') === false);

    // ── LA MEDIDA ────────────────────────────────────────────────
    echo "\n  — 360, 414 y 1440 —\n";
    foreach ([['LLEGADA.MED_360', 'cierre @360'], ['LLEGADA.MED_414', 'cierre @414'],
              ['LLEGADA.MED_1440', 'cierre @1440'], ['SINPLAN.MED_360', 'sin plan @360']] as [$k, $como]) {
        $m = $leer($k);
        ok("$como · sin desbordar a lo ancho", (int)($m['horiz'] ?? 1) === 0,
           'sobran ' . ($m['horiz'] ?? '?') . 'px');
        ok("$como · nada por debajo de 44px", empty($m['chicos']), json_encode($m['chicos'] ?? []));
        ok("$como · nada por debajo de 14px", empty($m['finos']), json_encode($m['finos'] ?? []));
        ok("$como · una sola acción principal", (int)($m['primarias'] ?? 9) <= 1,
           json_encode($m['primarias'] ?? null));
        ok("$como · la acción se ve sin bajar", !empty($m['priVisible']),
           (string)($m['priRect'] ?? '') . ' techo ' . (string)($m['techo'] ?? ''));
        ok("$como · y nada la tapa",
           empty($m['priTapada']) && empty($m['priBajoAyuda']), json_encode($m));
        ok("$como · la salida se ve sin bajar", !empty($m['salidaSinScroll']), json_encode($m));
    }

    echo "\n  — la pantalla no grita —\n";
    ok('cero alert()', (string)($R['ALERTAS'] ?? '1') === '0', (string)($R['ALERTAS'] ?? ''));
    ok('cero errores en consola',
       in_array(trim((string)($R['ERRORES'] ?? '[]')), ['[]', ''], true), (string)($R['ERRORES'] ?? ''));

    // ── NADA SE CREÓ POR MIRAR ───────────────────────────────────
    echo "\n  — mirar el cierre no crea trabajo —\n";
    ok('ni un plan más',  $cnt('crecer_meta_plan', "marca_id={$M}") === $antes['planes'],
       $antes['planes'] . ' → ' . $cnt('crecer_meta_plan', "marca_id={$M}"));
    ok('ni una meta más', $cnt('crecer_meta', "marca_id={$M}") === $antes['metas']);
    ok('ni un job más',   $cnt('crecer_meta_jobs', "marca_id={$M}") === $antes['jobs']);
    echo "\n  capturas en tests/_capturas/cierre/\n";

} catch (Throwable $e) {
    $fallos++; echo "\n  EXCEPCION · " . get_class($e) . ': ' . $e->getMessage()
                  . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    foreach ($limpiar as $mid) { try { Fixture::limpiar($pdo, $mid); } catch (Throwable $e) {} }
}

echo "\n  — el costo —\n";
ok('cero llamadas reales al modelo', $cnt('crecer_ia_log', "modelo <> 'mock'") === $g_ia,
   'antes ' . $g_ia . ' · ahora ' . $cnt('crecer_ia_log', "modelo <> 'mock'"));
ok('cero imágenes y cero cuota', $cnt('crecer_img_cuota_asiento') === $g_cuota,
   'antes ' . $g_cuota . ' · ahora ' . $cnt('crecer_img_cuota_asiento'));

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  EL FINAL SE VE COMO UN FINAL · $n afirmaciones\n\n"
    : "  $fallos FALLAS de $n\n\n";
exit($fallos === 0 ? 0 : 1);
