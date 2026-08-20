<?php
// ============================================================
//  CRECER — LA PRESENTACION DEL PLAN A 360x800, CON NAVEGADOR DE VERDAD
//  tests/test_meta_presentacion_navegador.php
//
//  test_meta_presentacion.php prueba el SELLO —pertenencia, vigencia,
//  idempotencia, carrera— y el HTML que sale del arnes de CLI. Esto prueba lo
//  otro: que el trato se puede LEER y el boton se puede PULSAR en el telefono
//  de la duena, con Ayuda flotando y la barra fija abajo.
//
//  Y una cosa que solo un navegador puede demostrar: que pulsar Empezar cambia
//  la pantalla de verdad. Si la escritura fallara en silencio, la recarga
//  volveria a ensenar el mismo trato y el dueno entraria en bucle en la primera
//  pantalla de su producto.
//
//  SE SALTA, diciendolo, si no hay servidor local o Chrome. Fingir que corrio
//  seria peor que no correrla.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/_fixture.php';
require_once __DIR__ . '/../includes/meta_negocio.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nLA PRESENTACION DEL PLAN · 360x800 · NAVEGADOR REAL\n" . str_repeat('=', 56) . "\n";

$CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if (!is_file($CHROME)) { echo "\n  SALTADO · no hay Chrome en esta máquina\n\n"; exit(0); }

$ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true]]);
if (@file_get_contents('http://localhost/crecer/login.php', false, $ctx) === false) {
    echo "\n  SALTADO · el servidor local no responde\n\n"; exit(0);
}
if (!meta_plan_col_presentado($pdo, true)) {
    echo "\n  SALTADO · falta migrations/2026-08-20_crecer_plan_presentado.sql\n\n"; exit(2);
}

$SHOTS = __DIR__ . '/_capturas';
@mkdir($SHOTS, 0775, true);

$fx   = Fixture::crear($pdo, 'nav-presenta', true, 'admin');
$M    = (int)$fx['marca_id'];
$PLAN = (int)$fx['plan_id'];

try {
    // El plan vuelve a como nace de verdad: sin ensenar. La fixture lo sella al
    // crearlo para que las demas suites puedan llegar a los estados de trabajo.
    Fixture::sinPresentar($pdo, $M, $PLAN);

    // Sesión de Apache, escrita a mano. Sin teclear contraseñas.
    $sid  = 'nvp' . bin2hex(random_bytes(8));
    $ruta = session_save_path() ?: sys_get_temp_dir();
    file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
        'usuario_id|i:' . (int)$fx['usuario_id'] . ';');

    $cmd = 'node ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . '_navegador_presentacion.mjs')
         . ' ' . escapeshellarg($sid) . ' ' . $M . ' ' . escapeshellarg($SHOTS) . ' 2>&1';
    $sal = []; exec($cmd, $sal);
    $r = [];
    foreach ($sal as $l) { if (strpos($l, '=') !== false) { [$k, $v] = explode('=', trim($l), 2); $r[$k] = $v; } }

    ok('el navegador completó el recorrido', ($r['OK'] ?? '0') === '1',
       ($r['ERROR'] ?? '') ?: implode(' | ', array_slice($sal, -3)));

    if (($r['OK'] ?? '0') === '1') {
        echo "\n  — el trato, en un Android de 360 —\n";
        ok('la pantalla es la del plan por presentar',
           strpos($r['C_TITULO'] ?? '', 'Tu camino está listo') !== false,
           'salió: ' . ($r['C_TITULO'] ?? '—'));
        ok('el botón dice Empezar', trim($r['C_BOTON'] ?? '') === 'Empezar');
        ok('el resumen del trato está', ($r['C_TRATO'] ?? '') === 'true');
        ok('y dice quién hace qué', strpos($r['C_REPARTO'] ?? '', 'hago yo') !== false
                                 && strpos($r['C_REPARTO'] ?? '', 'te toca') !== false,
           $r['C_REPARTO'] ?? '—');

        echo "\n  — los criterios del contrato —\n";
        ok('criterio 1 · la acción se ve sin hacer scroll',
           ($r['C_ACCION_SIN_SCROLL'] ?? '') === 'si',
           'bottom/alto = ' . ($r['C_ACCION_SIN_SCROLL'] ?? '?'));
        ok('criterio 3 · un solo botón primario',
           (int)($r['C_PRIMARIAS'] ?? 9) === 1,
           'hay ' . ($r['C_PRIMARIAS'] ?? '?') . ' con clase .ah-btn');
        ok('criterio 7 · ningún texto por debajo de 14px',
           (float)($r['C_MIN_PX'] ?? 0) >= 14,
           'el más pequeño mide ' . ($r['C_MIN_PX'] ?? '?') . 'px');
        ok('criterio 11 · el botón recibe el foco del teclado',
           ($r['C_TECLADO'] ?? '') === 'true');

        //  Los numeros medidos, dichos SIEMPRE y no solo al fallar: un verde sin
        //  cifras no deja saber si el margen es holgado o de un pelo.
        echo "\n  — nada tapado, nada desbordado —\n";
        printf("  ·    ancho %s · controles %s · hueco final %spx\n",
               $r['C_ANCHO'] ?? '?', $r['C_CONTROLES'] ?? '?', $r['C_HUECO_FINAL'] ?? '?');
        echo "  ·    geometría " . ($r['C_GEOMETRIA'] ?? '?') . "\n";
        echo "  ·    el último de abajo: " . ($r['C_ULTIMO'] ?? '?') . "\n";
        echo "  ·    el techo de lo fijo: " . ($r['C_TECHO'] ?? '?') . "\n";
        ok('la página no se va de ancho', (int)($r['C_DESBORDE'] ?? 1) === 0,
           'documentElement/ventana = ' . ($r['C_ANCHO'] ?? '?'));
        ok('ningún control queda bajo Ayuda ni bajo la barra',
           (int)($r['C_TAPADOS'] ?? 1) === 0, $r['C_TAPADOS_DET'] ?? '');
        ok('ninguno se sale por los lados',
           (int)($r['C_FUERA'] ?? 1) === 0, $r['C_FUERA_DET'] ?? '');
        ok('lo último de la página queda por encima de la barra',
           (int)($r['C_HUECO_FINAL'] ?? -1) >= 0,
           'hueco = ' . ($r['C_HUECO_FINAL'] ?? '?') . 'px');
        //  Y EL OTRO LADO DE LA MISMA MONEDA. Un hueco enorme tambien es un
        //  defecto: la primera correccion metio 300px de vacio y paso este
        //  criterio en verde. Que quede por encima de la barra Y que no haya
        //  una pantalla de nada debajo.
        ok('y sin una pantalla de espacio muerto debajo',
           (int)($r['C_HUECO_FINAL'] ?? 999) <= 160,
           'sobran ' . ($r['C_HUECO_FINAL'] ?? '?') . 'px de vacío · el suelo lo pone '
           . ($r['C_TECHO'] ?? '?'));

        echo "\n  — Ayuda: sin estorbar, pero sin desaparecer —\n";
        //  cada lectura viene como  apartada / y_del_boton / y_de_la_cola / zona
        $leer = function (?string $v): array {
            $p = array_pad(explode('/', (string)$v), 4, '');
            return ['apartada' => $p[0], 'fab' => $p[1], 'cola' => $p[2], 'zona' => $p[3]];
        };
        $normal  = $leer($r['C_AYUDA_NORMAL']  ?? null);
        $forzada = $leer($r['C_AYUDA_FORZADA'] ?? null);
        $vuelta  = $leer($r['C_AYUDA_VUELTA']  ?? null);
        printf("  ·    normal: zona %s · cola @%s · Ayuda @%s\n",
               $normal['zona'], $normal['cola'], $normal['fab']);

        ok('con la zona bien medida no hay colisión', $normal['apartada'] === 'false',
           'esconder Ayuda «por si acaso» seria perder una capacidad del producto');
        ok('la cola queda por encima del botón',
           $normal['cola'] !== '' && $normal['fab'] !== ''
           && (int)$normal['cola'] <= (int)$normal['fab'],
           "cola @{$normal['cola']} · Ayuda @{$normal['fab']}");
        ok('y la zona segura es un margen, no una pantalla',
           (int)$normal['zona'] > 0 && (int)$normal['zona'] <= 120,
           'salió ' . $normal['zona'] . ' · antes eran 300px de nada');

        echo "\n  — y si algo la empuja, Ayuda se aparta —\n";
        ok('sin zona, la cola se le echa encima y Ayuda se quita',
           $forzada['apartada'] === 'true',
           'la regla tiene que existir aunque hoy no haga falta');
        ok('y se va de la pantalla, no encima de la barra',
           $forzada['fab'] !== '' && (int)$forzada['fab'] >= 780,
           "el botón quedó en y={$forzada['fab']}; la ventana mide 800");
        ok('devuelta la zona, Ayuda vuelve sola', $vuelta['apartada'] === 'false');
        ok('y vuelve a donde se alcanza',
           $vuelta['fab'] !== '' && (int)$vuelta['fab'] < 780,
           "quedó en y={$vuelta['fab']}: apartarse y no volver deja la ayuda muerta");
        ok('y se midió sobre controles de verdad',
           (int)($r['C_CONTROLES'] ?? 0) >= 3,
           'medir 1 control y decir «nada tapado» sería un verde falso');

        echo "\n  — pulsar Empezar cambia la pantalla —\n";
        ok('el trato desaparece', ($r['POST_SIGUE_TRATO'] ?? 'true') === 'false');
        ok('y el botón de empezar también', ($r['POST_SIGUE_C'] ?? 'true') === 'false',
           'si sigue ahí, la escritura falló en silencio y el dueño entra en bucle');
        ok('la pantalla dice otra cosa',
           ($r['POST_TITULO'] ?? '') !== '' && ($r['POST_TITULO'] ?? '') !== ($r['C_TITULO'] ?? ''),
           'antes: ' . ($r['C_TITULO'] ?? '—') . ' · después: ' . ($r['POST_TITULO'] ?? '—'));
        ok('y sigue habiendo algo que hacer',
           (int)($r['POST_HAY_ACCION'] ?? 0) >= 1,
           'quedarse sin acción sería peor que el estado C');
        ok('la pantalla de después tampoco desborda',
           (int)($r['POST_DESBORDE'] ?? 1) === 0);
        ok('ni tapa controles', (int)($r['POST_TAPADOS'] ?? 1) === 0,
           $r['POST_TAPADOS_DET'] ?? '');

        echo "\n  — el sello vive en la base, no en la URL —\n";
        ok('recargar no resucita el trato', ($r['RECARGA_SIGUE_C'] ?? 'true') === 'false',
           'si volviera, el plan se estaría presentando en cada visita');
        $q = $pdo->prepare("SELECT presentado_at FROM crecer_meta_plan WHERE id=? AND marca_id=?");
        $q->execute([$PLAN, $M]);
        ok('y quedó escrito de verdad', $q->fetchColumn() !== null,
           'la pantalla cambió sin haber escrito nada');

        echo "\n  — capturas —\n";
        foreach (['meta_plan_por_presentar', 'meta_plan_presentado'] as $c) {
            $vp = $SHOTS . DIRECTORY_SEPARATOR . $c . '.png';
            ok("captura {$c} (viewport 360x800)", is_file($vp) && filesize($vp) > 5000,
               'la de viewport es la que juzga; la _completa solo se lee');
        }
    }
} finally {
    Fixture::limpiar($pdo, $M);
    $q = $pdo->prepare("SELECT COUNT(*) FROM crecer_marca WHERE id=?"); $q->execute([$M]);
    echo "\n  (fixture limpiada: " . ((int)$q->fetchColumn() === 0 ? 'sí' : 'NO') . ")\n";
    if (isset($ruta, $sid)) @unlink($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid);
}

echo "\n" . str_repeat('=', 56) . "\n";
echo $fallos === 0 ? "  TODO OK · {$n} pruebas\n\n" : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
