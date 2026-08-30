<?php
// ============================================================
//  CRECER — LA PANTALLA Y EL ENDPOINT HABLAN EL MISMO IDIOMA
//  tests/test_preparacion_contrato.php
//
//  ESTA PRUEBA EXISTE POR UN ATASCO REAL EN PRODUCCION, Y NO DEBERIA HABER
//  HECHO FALTA UN CLIENTE PARA VERLO.
//
//  Al rehacer la pantalla de espera cambie el sondeo de
//      POST accion=preparacion            (lo que el endpoint entiende)
//  a
//      GET  &preparacion=1                (lo que no entiende nadie)
//
//  `gateway_post.php` lee `$accion = $_POST['accion']`. Un GET no trae accion,
//  asi que la peticion caia hasta el final del archivo y devolvia la PAGINA
//  HTML ENTERA. En el navegador, `r.json()` reventaba, el `.catch()` reintentaba
//  a los 3 s, y asi indefinidamente: barra quieta y «El corillo esta preparando
//  tu idea» a los 3:52, aunque el worker hubiera terminado el post.
//
//  POR QUE NO LO CAZO NINGUNA PRUEBA. Las que habia miran el HTML que sirve el
//  servidor en cada estado — y ese HTML estaba perfecto. Nadie comprobaba que
//  la conversacion POSTERIOR entre la pantalla y el servidor existiera. Eso es
//  lo que se comprueba aqui, y de dos maneras:
//
//    1. ESTATICA · toda accion que la vista manda tiene que estar atendida en
//       el endpoint, y tiene que ir por POST (que es lo unico que se lee).
//    2. VIVA · se hace la peticion de verdad y se exige JSON con estado, no una
//       pagina. Si mañana alguien renombra una accion, esto se cae aqui y no
//       delante de un cliente.
//
//    php tests/test_preparacion_contrato.php
// ============================================================
define('OPENAI_API_KEY', 'llave-falsa-de-prueba-no-autentica');
define('GEMINI_API_KEY', 'llave-falsa-de-prueba-no-autentica');
define('CRECER_TEST_RED_FALSA', true);

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/agentes.php';
require_once __DIR__ . '/../includes/muestra.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok    $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n          -> " . mb_substr($detalle, 0, 300) : '') . "\n";
}

echo "\nLA PANTALLA Y EL ENDPOINT HABLAN EL MISMO IDIOMA\n" . str_repeat('=', 62) . "\n";

$vista    = (string)file_get_contents(__DIR__ . '/../includes/_preparacion_view.php');
$endpoint = (string)file_get_contents(__DIR__ . '/../panel/gateway_post.php');

// ── 1 · ESTATICA ────────────────────────────────────────────────────────
echo "\n  -- lo que la vista manda, el endpoint lo atiende --\n";

//  Las acciones que ATIENDE el endpoint.
preg_match_all("~\\\$accion\s*===\s*'([a-z0-9_]+)'~i", $endpoint, $m);
$atendidas = array_values(array_unique($m[1]));
ok('el endpoint declara sus acciones',  count($atendidas) >= 3, implode(', ', $atendidas));

//  Las acciones que MANDA la vista.
preg_match_all("~pedir\(\s*'([a-z0-9_]+)'\s*\)~i", $vista, $mv);
$mandadas = array_values(array_unique($mv[1]));
ok('la vista manda al menos dos',       count($mandadas) >= 2, implode(', ', $mandadas));

$huerfanas = array_values(array_diff($mandadas, $atendidas));
ok('ninguna accion cae en el vacio',    $huerfanas === [],
   'la vista manda y nadie atiende: ' . implode(', ', $huerfanas)
   . "\n          atendidas: " . implode(', ', $atendidas));

//  Y TIENEN QUE IR POR POST. `$accion` sale de $_POST; un GET no lo trae.
ok('el endpoint solo lee POST',         strpos($endpoint, "\$accion = \$_POST['accion']") !== false);
ok('y la vista manda por POST',         strpos($vista, "method:'POST'") !== false);
//  El fallo exacto que se vivio: un sondeo por query string. Se busca la FORMA
//  DE CODIGO —un fetch cuya URL concatena el parametro— y no la mencion suelta:
//  el comentario que explica el fallo nombra la cadena, y la primera version de
//  esta guarda se disparaba con su propia documentacion.
$codigo = preg_replace('~^\s*//.*$~m', '', $vista);
ok('ningun sondeo por query string',    preg_match('~fetch\([^)]*preparacion=1~', (string)$codigo) !== 1,
   'volvio el GET que rompio todo');

// ── 2 · VIVA · la peticion de verdad ────────────────────────────────────
echo "\n  -- y contestan de verdad, con JSON --\n";
$fx = Fixture::crear($pdo, 'contrato', false);
$M  = (int)$fx['marca_id']; $U = (int)$fx['usuario_id'];
try {
    onboarding_lock_reset($pdo, $U);
    $cid = muestra_fila($pdo, $M);

    foreach ($mandadas as $accion) {
        $raw = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' '
             . escapeshellarg(__DIR__ . '/_preparacion_post_runner.php')
             //  SIN 2>&1 A PROPOSITO: los avisos de PHP salen por stderr y, mezclados
             //  con la respuesta, hacian que json_decode fallara. Se culpaba al
             //  endpoint de un ruido que era del arranque del propio runner.
             . ' ' . $U . ' ' . $M . ' ' . escapeshellarg($accion) . ' 2>NUL');
        $j = json_decode(trim($raw), true);
        ok("«{$accion}» contesta JSON",      is_array($j), mb_substr($raw, 0, 200));
        //  LA PRUEBA DE FUEGO: que NO sea la pagina entera. Eso es exactamente
        //  lo que devolvia el GET, y lo que dejaba la pantalla congelada.
        ok("«{$accion}» no devuelve la pagina", stripos($raw, '<!doctype html') === false, mb_substr($raw, 0, 120));
        if (is_array($j)) {
            ok("«{$accion}» trae estado usable", array_key_exists('pct', $j) || array_key_exists('ok', $j),
               implode(', ', array_slice(array_keys($j), 0, 8)));
        }
    }

    //  Y EL SONDEO NO PUEDE COBRAR NI CREAR. Es la guarda de siempre, pero
    //  ahora que el sondeo VUELVE A FUNCIONAR conviene volver a exigirla.
    $antes = (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$M}")->fetchColumn();
    for ($i = 0; $i < 3; $i++) {
        shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/_preparacion_post_runner.php')
                 . ' ' . $U . ' ' . $M . ' ' . escapeshellarg('preparacion') . ' 2>&1');
    }
    $desp = (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$M}")->fetchColumn();
    ok('tres sondeos no crean piezas',   $desp === $antes, "{$antes} -> {$desp}");
    $asi = (int)$pdo->query("SELECT COUNT(*) FROM crecer_img_cuota_asiento WHERE marca_id={$M}")->fetchColumn();
    ok('ni asientos de cuota',           $asi === 0, 'asientos=' . $asi);

} finally {
    onboarding_lock_reset($pdo, $U);
    Fixture::limpiar($pdo, $M);
}

echo "\n" . str_repeat('-', 62) . "\n";
echo ($fallos === 0 ? "  TODO EN VERDE" : "  {$fallos} FALLAS") . " · {$n} comprobaciones\n";
exit($fallos === 0 ? 0 : 1);
