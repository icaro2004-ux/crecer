<?php
// ============================================================
//  CRECER - LAS CAPTURAS DEL EMBUDO, A 360x800
//  tests/test_capturas_embudo.php
//
//  Diez momentos del recorrido comercial, cada uno retratado desde un ESTADO
//  DISTINTO EN LA BASE. No hay `?paso=` que finja el momento: entre captura y
//  captura se mueven las columnas que de verdad lo definen (ia_log_id,
//  img_job, img_job_at, grafica_path, telefono_verificado), y la pagina lee lo
//  que hay. Por eso las capturas se diferencian por CONTENIDO y no por nombre,
//  y al final se comprueba que ninguna se repite comparando sus hashes.
//
//  Corre contra ESTE arbol servido por Apache; el navegador lleva un shim que
//  reescribe las llamadas absolutas `/crecer/...` para que no se salga al arbol
//  de la otra rama. El centinela `_SIN_CREDENCIALES` se pone aqui y se quita en
//  el shutdown, pase lo que pase.
//
//    php tests/test_capturas_embudo.php
// ============================================================
define('OPENAI_API_KEY', 'llave-falsa-de-prueba-no-autentica');
define('GEMINI_API_KEY', 'llave-falsa-de-prueba-no-autentica');
define('CRECER_TEST_RED_FALSA', true);

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/agentes.php';
require_once __DIR__ . '/../includes/muestra.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok    $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n          -> $detalle" : '') . "\n";
}

echo "\nLAS DIEZ CAPTURAS DEL EMBUDO (360x800)\n" . str_repeat('=', 62) . "\n";

$RAIZ  = dirname(__DIR__);
$MIO   = basename($RAIZ);
$BASE  = 'http://localhost/' . rawurlencode($MIO);
$SHOTS = __DIR__ . '/_capturas/embudo';
if (!is_dir($SHOTS)) @mkdir($SHOTS, 0777, true);

$ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
$hay_web    = @file_get_contents($BASE . '/login.php', false, $ctx) !== false;
$hay_chrome = is_file('C:/Program Files/Google/Chrome/Application/chrome.exe');
if (!$hay_web || !$hay_chrome) {
    echo "\n  (sin servidor o sin Chrome: no hay capturas que sacar)\n";
    exit(0);
}

//  EL CENTINELA VA EN ESTE ARBOL, que es el que Apache sirve en {$MIO}, y se
//  quita en el shutdown aunque esto reviente a la mitad.
$CENT = $RAIZ . '/includes/_SIN_CREDENCIALES';
file_put_contents($CENT, "capturas embudo · " . date('c') . "\n");
register_shutdown_function(function () use ($CENT) { @unlink($CENT); });

//  Cuenta NO pagada: es la unica que camina el gateway.
$fx = Fixture::crear($pdo, 'capturas', false);
$M  = (int)$fx['marca_id'];
$U  = (int)$fx['usuario_id'];

try {
    onboarding_lock_reset($pdo, $U);
    $cid = muestra_fila($pdo, $M);

    $sid = 'cap' . bin2hex(random_bytes(7));
    file_put_contents((session_save_path() ?: sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'sess_' . $sid,
                      'usuario_id|i:' . $U . ';');

    $GW  = '/panel/gateway_post.php?marca=' . $M;
    $CAP = 'Bizcochos por encargo en Caguas — pide el tuyo esta semana. 🎂';
    $IMG = '/crecer/uploads/marca_' . $M . '/graficas/captura.png';

    /**
     * Saca capturas. Recibe una LISTA de pares [nombre, url], no un mapa: hacen
     * falta pasos con el mismo destino (navegar y despues actuar sobre esa misma
     * pagina), y un mapa se los come por clave repetida — asi se perdio la
     * captura de la puerta del telefono la primera vez.
     */
    $sacar = function (array $pasos) use ($SHOTS, $sid, $BASE): array {
        $args = '';
        foreach ($pasos as [$nom, $url]) $args .= ' ' . escapeshellarg($nom . '=' . $url);
        $cmd = 'node ' . escapeshellarg(__DIR__ . '/_capturas_embudo.mjs') . ' '
             . escapeshellarg($SHOTS) . ' ' . escapeshellarg($sid) . ' ' . escapeshellarg($BASE) . $args;
        $sal = (string)shell_exec($cmd . ' 2>&1');
        $R = [];
        foreach (explode("\n", $sal) as $l) {
            $l = trim($l); $i = strpos($l, '=');
            if ($i > 0) $R[substr($l, 0, $i)] = substr($l, $i + 1);
        }
        $R['_raw'] = $sal;
        return $R;
    };

    // ── 1 · el copy ya decidido, el arte todavia no ─────────────────────
    $pdo->prepare("UPDATE crecer_contenido SET caption=?, ia_log_id=1, corillo_json='{\"elegido\":1}',
                          img_job=NULL, img_estado=NULL, grafica_path=NULL,
                          created_at=DATE_SUB(NOW(), INTERVAL 6 SECOND) WHERE id=?")
        ->execute([$CAP, $cid]);
    $r = $sacar([['01_direccion_copy', $GW]]);
    ok('captura 01 · dirección y copy escogidos', ($r['OK'] ?? '0') === '1', substr($r['_raw'] ?? '', -300));

    // ── 2 · preparacion temprana (arte recien enviado) ──────────────────
    $pdo->prepare("UPDATE crecer_contenido SET img_job='resp_cap', img_estado='queued',
                          img_job_at=DATE_SUB(NOW(), INTERVAL 6 SECOND),
                          created_at=DATE_SUB(NOW(), INTERVAL 10 SECOND) WHERE id=?")->execute([$cid]);
    $r = $sacar([['02_preparacion_temprana', $GW]]);
    ok('captura 02 · preparación temprana',      ($r['OK'] ?? '0') === '1');

    // ── 3 · generacion viva, dentro del tramo estimado 70-89 ────────────
    $pdo->prepare("UPDATE crecer_contenido SET img_job_at=DATE_SUB(NOW(), INTERVAL 48 SECOND),
                          created_at=DATE_SUB(NOW(), INTERVAL 52 SECOND) WHERE id=?")->execute([$cid]);
    $st = muestra_estado($pdo, $M, $cid);
    ok('el estado 03 está en el tramo estimado', $st['estimando'] && $st['pct_estimado'] >= 70 && $st['pct_estimado'] <= 89,
       'pct=' . $st['pct_estimado']);
    $r = $sacar([['03_generacion_70_89', $GW]]);
    ok('captura 03 · generación viva 70–89%',    ($r['OK'] ?? '0') === '1');

    // ── 4 · tardanza (pasado el umbral) ─────────────────────────────────
    $pdo->prepare("UPDATE crecer_contenido SET img_job_at=DATE_SUB(NOW(), INTERVAL 150 SECOND),
                          created_at=DATE_SUB(NOW(), INTERVAL 160 SECOND) WHERE id=?")->execute([$cid]);
    $st = muestra_estado($pdo, $M, $cid);
    ok('el estado 04 ya avisa que tarda',        $st['tarde'] === true, 'seg=' . $st['segundos']);
    $r = $sacar([['04_tardanza', $GW]]);
    ok('captura 04 · está tomando más de lo normal', ($r['OK'] ?? '0') === '1');

    // ── 5 · el post completo, al 100 ────────────────────────────────────
    $abs = rtrim(UPLOADS_PATH, '/\\') . "/marca_{$M}/graficas";
    @mkdir($abs, 0775, true);
    @file_put_contents($abs . '/captura.png', base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
    $pdo->prepare("UPDATE crecer_contenido SET grafica_path=?, img_estado='ok', img_job=NULL WHERE id=?")
        ->execute([$IMG, $cid]);
    $st = muestra_estado($pdo, $M, $cid);
    ok('el estado 05 está listo al 100',         $st['listo'] === true && $st['pct'] === 100);
    $r = $sacar([['05_post_completo_100', $GW]]);
    ok('captura 05 · post completo al 100%',     ($r['OK'] ?? '0') === '1');

    // ── 6 · la puerta del telefono ──────────────────────────────────────
    //  AQUI LOCAL NO PUEDE REPRODUCIR EL CAMINO NATURAL, Y SE DICE.
    //  config.local.php trae CRECER_DEV_ACTIVAR=true, que da acceso_full a
    //  cualquier cuenta local: por Apache, `publicar_manual` publica sin pedir
    //  telefono y el modal nunca se abre. No se toca ese config -es de la
    //  maquina, no del producto- asi que la captura abre el MISMO componente
    //  real de la pagina de forma directa.
    //
    //  Que la puerta SI existe no lo prueba esta foto sino el servidor:
    //  test_integracion_embudo.php apaga el flag y comprueba que publicar
    //  contesta needs_phone sin telefono, y ok con el puesto.
    //  SIN UNA SOLA COMILLA DOBLE EN EL JS: en Windows escapeshellarg envuelve el
    //  argumento en comillas dobles, y las de dentro se pierden por el camino.
    //  La primera version murio con «fallback is not defined» por eso mismo.
    $clics = '@js:(function(){'
           . 'try { if (window.crecerSmsGate && window.crecerSmsGate.open) { window.crecerSmsGate.open(function(){}); return 1; } } catch(e){}'
           . 'var b=document.getElementById(\'btnAprobar\'); if(b) b.click(); return 0;})()';
    $r = $sacar([['06_previo', $GW], ['06_puerta_telefono', $clics]]);
    ok('captura 06 · puerta de verificación',    ($r['OK'] ?? '0') === '1', substr($r['_raw'] ?? '', -300));
    @unlink($SHOTS . '/06_previo.png');   // andamio: no es uno de los diez momentos

    // ── 7 · con el telefono ya confirmado (SMS simulado, cero envios) ───
    //  Con el telefono ya confirmado (SMS SIMULADO: no se envia nada) y la pieza
    //  aprobada, que es cuando el panel de publicar se ve. Sin aprobar, esta
    //  pantalla era byte a byte la misma que la 05 — y una captura que no
    //  enseña un estado distinto no prueba nada.
    $pdo->prepare("UPDATE crecer_marca SET telefono_verificado='7875550100' WHERE id=?")->execute([$M]);
    $pdo->prepare("UPDATE crecer_contenido SET estado='aprobado' WHERE id=?")->execute([$cid]);
    $r = $sacar([['07_tras_sms', $GW]]);
    ok('captura 07 · paso posterior al SMS',     ($r['OK'] ?? '0') === '1');

} finally {
    onboarding_lock_reset($pdo, $U);
    Fixture::limpiar($pdo, $M);
}

// ── 8-10 · las pantallas de Fase 11 (hacen falta con acceso) ────────────
$f2 = Fixture::crear($pdo, 'capturas-panel', true, 'admin');
$M2 = (int)$f2['marca_id']; $U2 = (int)$f2['usuario_id'];
try {
    $sid2 = 'cap' . bin2hex(random_bytes(7));
    file_put_contents((session_save_path() ?: sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'sess_' . $sid2,
                      'usuario_id|i:' . $U2 . ';');
    foreach (['inicio','meta','semana','calendario','resultados','sala','crear','reels'] as $p) {
        try { $pdo->prepare("INSERT IGNORE INTO crecer_tour_visto (marca_id,clave,visto_at) VALUES (?,?,NOW())")
                  ->execute([$M2, $p]); } catch (Throwable $e) {}
    }
    $args = '';
    foreach (['08_calendario' => '/panel/calendario.php?marca=' . $M2,
              '09_sala'       => '/panel/sala.php?marca=' . $M2,
              '10_mi_negocio' => '/panel/genoma.php?marca=' . $M2] as $nom => $u) {
        $args .= ' ' . escapeshellarg($nom . '=' . $u);
    }
    $sal = (string)shell_exec('node ' . escapeshellarg(__DIR__ . '/_capturas_embudo.mjs') . ' '
         . escapeshellarg($SHOTS) . ' ' . escapeshellarg($sid2) . ' ' . escapeshellarg($BASE) . $args . ' 2>&1');
    ok('capturas 08-10 · Calendario, Sala, Mi negocio', strpos($sal, 'OK=1') !== false, substr($sal, -300));
} finally {
    Fixture::limpiar($pdo, $M2);
}

// ── LAS CAPTURAS SE DIFERENCIAN POR CONTENIDO, NO POR NOMBRE ────────────
echo "\n  -- ninguna captura repite a otra --\n";
$por_hash = [];
foreach ((array)glob($SHOTS . '/*.png') as $f) {
    $por_hash[md5_file($f)][] = basename($f);
}
$repes = array_values(array_filter($por_hash, fn($v) => count($v) > 1));
ok('hay diez capturas', count(glob($SHOTS . '/*.png')) >= 10, count(glob($SHOTS . '/*.png')) . ' archivos');
ok('todas distintas por contenido', $repes === [],
   $repes ? 'iguales entre sí: ' . json_encode($repes) : '');
foreach ((array)glob($SHOTS . '/*.png') as $f) {
    printf("      %-30s %7s bytes  %s\n", basename($f), number_format(filesize($f)), substr(md5_file($f), 0, 8));
}

echo "\n" . str_repeat('-', 62) . "\n";
echo ($fallos === 0 ? "  TODO EN VERDE" : "  {$fallos} FALLAS") . " · {$n} comprobaciones\n";
exit($fallos === 0 ? 0 : 1);
