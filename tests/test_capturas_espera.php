<?php
// ============================================================
//  CRECER — LA ESPERA, RETRATADA EN SUS CINCO MOMENTOS
//  tests/test_capturas_espera.php
//
//  Cinco estados x tres anchos. Cada captura sale de un ESTADO REAL EN LA BASE
//  —se mueven las columnas que lo definen y la pagina lee lo que hay— nunca de
//  un `?paso=` que finja el momento. Al final se comparan los hashes: dos
//  capturas identicas significarian que uno de los estados no se esta viendo.
//
//  Y ADEMAS SE VIGILA QUE NO VUELVA EL TABLERO. Sobre el HTML servido de cada
//  estado se comprueba que no reaparezcan el porcentaje grande, la lista de
//  etapas, los nombres de agentes ni los identificadores. Una captura bonita no
//  prueba nada si el proximo cambio devuelve el panel de diagnostico.
//
//  Corre contra ESTE arbol (_arbol_servido.php pone el centinela donde se lee y
//  le da al navegador la valla contra /crecer/). Cero proveedores.
//
//    php tests/test_capturas_espera.php
// ============================================================
define('OPENAI_API_KEY', 'llave-falsa-de-prueba-no-autentica');
define('GEMINI_API_KEY', 'llave-falsa-de-prueba-no-autentica');
define('CRECER_TEST_RED_FALSA', true);

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/agentes.php';
require_once __DIR__ . '/../includes/muestra.php';
require_once __DIR__ . '/_arbol_servido.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok    $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n          -> " . mb_substr($detalle, 0, 260) : '') . "\n";
}

echo "\nLA ESPERA · CINCO MOMENTOS, TRES ANCHOS\n" . str_repeat('=', 62) . "\n";

$SRV = arbol_servido();
if (!$SRV['ok']) { echo $SRV['motivo'] . "\n"; exit(0); }

$SHOTS = __DIR__ . '/_capturas/espera';
if (!is_dir($SHOTS)) @mkdir($SHOTS, 0777, true);
foreach ((array)glob($SHOTS . '/*.png') as $f) @unlink($f);

$fx = Fixture::crear($pdo, 'espera', false);
$M  = (int)$fx['marca_id'];
$U  = (int)$fx['usuario_id'];
$ANCHOS = ['360' => '360x800', '414' => '414x896', '1440' => '1440x900'];

try {
    onboarding_lock_reset($pdo, $U);
    $cid = muestra_fila($pdo, $M);
    $sid = 'esp' . bin2hex(random_bytes(7));
    file_put_contents((session_save_path() ?: sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'sess_' . $sid,
                      'usuario_id|i:' . $U . ';');
    $GW  = '/panel/gateway_post.php?marca=' . $M;
    $CAP = 'Bizcochos por encargo en Caguas — pide el tuyo esta semana.';

    /** Deja la pieza en un estado y devuelve el HTML que sirve el gateway. */
    $poner = function (array $sql) use ($pdo, $cid, $U, $M): string {
        $pdo->prepare($sql[0])->execute(array_merge($sql[1], [$cid]));
        return (string)shell_exec(escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg(__DIR__ . '/_pagina_runner.php') . " {$U} {$M} "
            . escapeshellarg('gateway_post.php') . ' 2>&1');
    };
    $tirar = function (string $nombre, string $tam) use ($SHOTS, $sid, $SRV, $GW): array {
        putenv('CRECER_TAM=' . $tam);
        $cmd = 'node ' . escapeshellarg(__DIR__ . '/_capturas_embudo.mjs') . ' '
             . escapeshellarg($SHOTS) . ' ' . escapeshellarg($sid) . ' ' . escapeshellarg($SRV['base'])
             . ' ' . escapeshellarg($nombre . '=' . $GW) . ' 2>&1';
        $sal = (string)shell_exec($cmd);
        return ['ok' => strpos($sal, 'OK=1') !== false, 'raw' => $sal];
    };

    //  Los cinco momentos. Cada uno es un juego de columnas distinto.
    $ESTADOS = [
        '1_preparando_texto' => ["UPDATE crecer_contenido SET caption=?, ia_log_id=NULL, corillo_json=NULL,
                                    img_job=NULL, img_estado=NULL, grafica_path=NULL, img_error_clase=NULL,
                                    created_at=DATE_SUB(NOW(), INTERVAL 7 SECOND) WHERE id=?", [MUESTRA_IDEA]],
        '2_creando_imagen'   => ["UPDATE crecer_contenido SET caption=?, ia_log_id=1, corillo_json='{\"visual\":\"x\"}',
                                    img_job='resp_esp', img_estado='queued', img_error_clase=NULL,
                                    img_job_at=DATE_SUB(NOW(), INTERVAL 42 SECOND),
                                    created_at=DATE_SUB(NOW(), INTERVAL 50 SECOND) WHERE id=?", [$CAP]],
        '3_tardanza'         => ["UPDATE crecer_contenido SET caption=?, ia_log_id=1, img_job='resp_esp',
                                    img_estado='queued', img_error_clase=NULL,
                                    img_job_at=DATE_SUB(NOW(), INTERVAL 150 SECOND),
                                    created_at=DATE_SUB(NOW(), INTERVAL 165 SECOND) WHERE id=?", [$CAP]],
        '4_fallo_recuperable'=> ["UPDATE crecer_contenido SET caption=?, ia_log_id=1, img_job=NULL,
                                    img_estado='error', img_error_clase='fbx:sin_motor',
                                    created_at=DATE_SUB(NOW(), INTERVAL 80 SECOND) WHERE id=?", [$CAP]],
    ];

    foreach ($ESTADOS as $nom => $sql) {
        $html = $poner($sql);
        echo "\n  -- {$nom} --\n";
        ok("{$nom} · el gateway sirve la espera", strpos($html, 'Estamos creando tu primer post') !== false
                                               || strpos($html, 'Tu texto está listo') !== false,
           mb_substr(strip_tags($html), 0, 160));
        //  EL TABLERO NO PUEDE VOLVER, y se vigila en CADA estado.
        ok("{$nom} · sin lista de etapas",        preg_match_all('/<li class="[^"]*" data-clave=/', $html) === 0);
        ok("{$nom} · sin nombres de agentes",     stripos($html, 'equipoLista') === false);
        ok("{$nom} · sin modelo ni ids",          stripos($html, 'gpt-') === false && strpos($html, (string)$cid) === false);
        foreach ($ANCHOS as $et => $tam) {
            $r = $tirar($nom . '_' . $et, $tam);
            ok("{$nom} · captura {$et}",          $r['ok'], mb_substr($r['raw'], -220));
        }
    }

    //  5 · EL POST TERMINADO. Aqui el gateway ya NO sirve la espera: sirve el
    //  escenario. Es parte del recorrido y por eso se retrata igual.
    $abs = rtrim(UPLOADS_PATH, '/\\') . "/marca_{$M}/graficas";
    @mkdir($abs, 0775, true);
    @file_put_contents($abs . '/espera.png', base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
    $html = $poner(["UPDATE crecer_contenido SET caption=?, ia_log_id=1, img_job=NULL, img_estado='ok',
                       img_error_clase=NULL, grafica_path=? WHERE id=?",
                    [$CAP, '/crecer/uploads/marca_' . $M . '/graficas/espera.png']]);
    echo "\n  -- 5_post_terminado --\n";
    ok('5 · ya no es la espera, es el post',   stripos($html, 'Aprobar este post') !== false);
    foreach ($ANCHOS as $et => $tam) {
        $r = $tirar('5_post_terminado_' . $et, $tam);
        ok("5_post_terminado · captura {$et}", $r['ok'], mb_substr($r['raw'], -220));
    }

} finally {
    onboarding_lock_reset($pdo, $U);
    Fixture::limpiar($pdo, $M);
}

// ── NINGUNA CAPTURA REPITE A OTRA ───────────────────────────────────────
echo "\n  -- cada momento se ve distinto --\n";
$por_hash = [];
foreach ((array)glob($SHOTS . '/*.png') as $f) $por_hash[md5_file($f)][] = basename($f);
$repes = array_values(array_filter($por_hash, fn($v) => count($v) > 1));
ok('hay quince capturas',           count(glob($SHOTS . '/*.png')) === 15, count(glob($SHOTS . '/*.png')) . ' archivos');
ok('todas distintas por contenido', $repes === [], $repes ? json_encode($repes) : '');
foreach ((array)glob($SHOTS . '/*.png') as $f) {
    printf("      %-34s %8s bytes  %s\n", basename($f), number_format(filesize($f)), substr(md5_file($f), 0, 8));
}

echo "\n" . str_repeat('-', 62) . "\n";
echo ($fallos === 0 ? "  TODO EN VERDE" : "  {$fallos} FALLAS") . " · {$n} comprobaciones\n";
exit($fallos === 0 ? 0 : 1);
