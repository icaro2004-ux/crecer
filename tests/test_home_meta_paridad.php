<?php
// ============================================================
//  CRECER — HOME Y TU META DICEN LO MISMO  (Fase 5)
//  tests/test_home_meta_paridad.php
//
//  Escrita ANTES de tocar Home, y NACE ROJA a proposito: hoy las dos pantallas
//  deciden por separado y en varios casos se contradicen.
//
//  LO QUE LA AUDITORIA ENCONTRO, Y QUE ES LO QUE ESTA PRUEBA FIJA
//
//  · Home no consulta al compositor. Lee por su cuenta (meta_activa,
//    meta_progreso, meta_tactica_de_turno) y colapsa los TRECE estados de Tu
//    Meta en tres suyos: ninguna / cerrada / activa.
//  · Home pinta barra, porcentaje y ritmo SIN el contrato de cobertura. Es
//    exactamente lo que se prohibio en Tu Meta, vivo en la primera pantalla
//    que ve el dueño.
//  · Home no sabe nada de la cuota. Con el cubo lleno, Tu Meta dice «usaste
//    las 40 imagenes» y Home sigue mandando a producir.
//  · MetaState::resumen() existe desde hace tiempo y su docblock dice «lo unico
//    que ve Home». Nadie lo llama.
//  · CognitiveEngine (core/Cognition) no lo usa NADIE fuera de core/.
//
//  LA REGLA QUE SE PRUEBA
//
//      Para la misma marca y el mismo instante, Home y Tu Meta muestran la
//      MISMA decision: mismo estado, mismo mensaje y misma accion.
//
//  Se comparan las dos pantallas de VERDAD —el HTML que sirve Apache— porque
//  la contradiccion que sufre el dueño esta ahi, no en las funciones.
//
//  CERO PROVEEDORES: monta filas y abre paginas.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/meta_negocio.php';
require_once __DIR__ . '/../core/Meta/MetaSnapshotReader.php';
require_once __DIR__ . '/../core/Meta/MetaStateComposer.php';
require_once __DIR__ . '/../core/Meta/MetaLimiteImagen.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nHOME Y TU META · la misma decisión\n" . str_repeat('=', 58) . "\n";

$fx = Fixture::crear($pdo, 'homepar', true, 'admin');
$M = (int)$fx['marca_id']; $META = (int)$fx['meta_id']; $PLAN = (int)$fx['plan_id'];
$sid  = 'hp' . bin2hex(random_bytes(8));
$ruta = session_save_path() ?: sys_get_temp_dir();
file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
    'usuario_id|i:' . (int)$fx['usuario_id'] . ';');

$pedir = function (string $pag, string $q = '') use ($sid): string {
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 40,
        'header' => "Cookie: PHPSESSID={$sid}\r\n", 'ignore_errors' => true]]);
    $h = @file_get_contents('http://localhost/crecer/panel/' . $pag . '?' . $q, false, $ctx);
    return is_string($h) ? $h : '';
};
/** El estado que decide el compositor, que es la unica fuente. */
$estado = function () use ($pdo, $M): MetaState {
    return MetaStateComposer::componer(MetaSnapshotReader::leer($pdo, $M));
};
/** Lo que Home dice de la meta: su tarjeta, en texto plano. */
$tarjeta = function (string $html): string {
    if (!preg_match('~<(section|a)[^>]*class="[^"]*\bnorte\b[^"]*"[^>]*>(.*?)</\1>~s', $html, $m)) return '';
    return trim(preg_replace('/\s+/u', ' ', strip_tags($m[2])));
};
/** La frase que Tu Meta pinta de verdad — no el titulo crudo del compositor.
 *
 *  La primera version comparaba Home contra $E->titulo, y eso era comparar
 *  contra algo que NINGUNA de las dos pantallas enseña: las dos pasan por el
 *  presentador. El contrato dice que Home y Tu Meta digan lo mismo, asi que
 *  se enfrentan las dos pantallas. */
$frase = function (string $html): string {
    if (!preg_match('~<h1[^>]*class="[^"]*\btm-frase\b[^"]*"[^>]*>(.*?)</h1>~s', $html, $m)) return '';
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8')));
};

/** El destino al que manda Home desde su tarjeta. */
$destino = function (string $html): string {
    if (preg_match('~<a[^>]*class="[^"]*\bnorte\b[^"]*"[^>]*href="([^"]+)"~', $html, $m)) return $m[1];
    if (preg_match('~class="[^"]*\bnorte\b.*?<a[^>]*href="([^"]+)"~s', $html, $m2)) return $m2[1];
    return '';
};

try {
    $pdo->prepare("UPDATE crecer_meta_plan SET presentado_at=NOW() WHERE meta_id=? AND estado='activo'")
        ->execute([$META]);

    // ══════════════════════════════════════════════════════════
    //  0 · LA COSTURA QUE YA EXISTE Y NADIE USA
    // ══════════════════════════════════════════════════════════
    echo "\n  — 0 · el resumen compartido —\n";
    $E = $estado();
    ok('el compositor da un resumen para Home', method_exists($E, 'resumen'));
    $r = $E->resumen();
    foreach (['estado', 'titulo', 'accion'] as $k) {
        ok("el resumen trae «{$k}»", array_key_exists($k, $r), json_encode(array_keys($r)));
    }
    ok('y NO expone la evidencia entera',
       !array_key_exists('evidencia', $r) && !array_key_exists('cobertura', $r),
       'Home no necesita el objeto completo: consumirlo la volveria a acoplar al detalle');

    $home = (string)file_get_contents(dirname(__DIR__) . '/panel/index.php');
    ok('Home consume el compositor',
       strpos($home, 'MetaStateComposer') !== false,
       'hoy lee por su cuenta con meta_activa/meta_progreso y decide aparte');
    //  Los comentarios no ejecutan. Contarlos daba un rojo por la nota que
    //  explica QUE se quito y por que — justo lo que hay que conservar.
    $homeVivo = preg_replace('~^\s*//.*$~m', '',
                 preg_replace('~/\*.*?\*/~s', '', $home));
    ok('y no reimplementa la decisión',
       strpos($homeVivo, 'meta_tactica_de_turno') === false,
       'esa función es la regla de «qué toca ahora» duplicada fuera del compositor');

    // ══════════════════════════════════════════════════════════
    //  1 · MISMO ESTADO, MISMO MENSAJE, MISMA ACCIÓN
    // ══════════════════════════════════════════════════════════
    echo "\n  — 1 · los estados observables, uno a uno —\n";

    //  Cada escenario deja la base en la forma que dispara UN estado. Se compara
    //  lo que dicen las DOS pantallas de verdad.
    $escenarios = [
        'F · una pieza espera el OK' => function () use ($pdo, $M, $META, $PLAN) {
            $pdo->prepare("UPDATE crecer_contenido SET estado='borrador', necesita_material=NULL
                            WHERE marca_id=? LIMIT 1")->execute([$M]);
        },
        'G · falta el video del dueño' => function () use ($pdo, $M, $META, $PLAN) {
            $pdo->prepare("UPDATE crecer_contenido SET estado='borrador', necesita_material='video',
                                  tipo='reel' WHERE marca_id=? LIMIT 1")->execute([$M]);
        },
        'J · todo programado' => function () use ($pdo, $M) {
            $pdo->prepare("UPDATE crecer_contenido SET estado='programado', necesita_material=NULL,
                                  fecha_programada=DATE_ADD(NOW(), INTERVAL 2 DAY) WHERE marca_id=?")
                ->execute([$M]);
            $pdo->prepare("UPDATE crecer_meta_tactica SET estado='hecha' WHERE meta_id=?
                            AND clase<>'accion_dueno'")->execute([(int)0]);
        },
        'M · la meta cerró' => function () use ($pdo, $META) {
            $pdo->prepare("UPDATE crecer_meta SET estado='lograda' WHERE id=?")->execute([$META]);
        },
        'A · sin meta ninguna' => function () use ($pdo, $META) {
            $pdo->prepare("UPDATE crecer_meta SET estado='cancelada' WHERE id=?")->execute([$META]);
        },
    ];

    foreach ($escenarios as $etq => $montar) {
        $montar();
        $E = $estado();
        $h = $pedir('index.php', 'marca=' . $M);
        $t = $tarjeta($h);

        ok("{$etq} · Home dice algo de la meta", $t !== '', 'no encontré la tarjeta en el Home');

        //  EL MENSAJE, PANTALLA CONTRA PANTALLA. Se abre Tu Meta de verdad y
        //  se coge la frase que pinta; la de Home tiene que contenerla.
        $tuMeta = $frase($pedir('meta.php', 'marca=' . $M));
        ok("{$etq} · y es el mismo mensaje que Tu Meta",
           $tuMeta !== '' && mb_stripos($t, $tuMeta) !== false,
           'Tu Meta pinta «' . mb_substr($tuMeta, 0, 70) . '»' . "\n"
         . '         Home pinta  «' . mb_substr($t, 0, 100) . '»');

        //  LA ACCIÓN. El destino de Home tiene que ser el mismo objeto que abre
        //  Tu Meta, no siempre «meta.php».
        $dest = $destino($h);
        $esperado = (string)($E->accion['destino'] ?? '');
        if ($esperado !== '') {
            ok("{$etq} · y manda al mismo sitio",
               $dest !== '' && parse_url($dest, PHP_URL_PATH) === parse_url($esperado, PHP_URL_PATH),
               'Tu Meta abre ' . $esperado . "\n         Home abre   " . ($dest ?: '(ninguno)'));
        }
        ok("{$etq} · conservando la marca", strpos($dest, 'marca=' . $M) !== false, $dest ?: '(sin destino)');
    }

    //  Se repone la meta para lo que viene.
    $pdo->prepare("UPDATE crecer_meta SET estado='activa' WHERE id=?")->execute([$META]);

    // ══════════════════════════════════════════════════════════
    //  2 · EL CONTRATO DE COBERTURA, TAMBIÉN EN HOME
    // ══════════════════════════════════════════════════════════
    echo "\n  — 2 · Home no afirma lo que no puede —\n";
    $E = $estado();
    $h = $pedir('index.php', 'marca=' . $M);
    //  SE BUSCA DENTRO DE LA TARJETA. Buscar «n-barra» en la pagina entera
    //  encontraba la REGLA CSS que la define y daba un rojo por una hoja de
    //  estilos, no por una barra pintada.
    $soloTarjeta = '';
    if (preg_match('~<(section|a)[^>]*class="[^"]*\bnorte\b[^"]*"[^>]*>(.*?)</\1>~s', $h, $mt)) {
        $soloTarjeta = $mt[0];
    }
    if (!$E->puedeAfirmarProgreso()) {
        ok('sin cobertura completa, Home no pinta barra',
           strpos($soloTarjeta, 'n-barra') === false,
           'una barra afirma «vas por aquí de un total que conozco», y con cobertura parcial no se conoce');
        ok('ni afirma el ritmo',
           mb_stripos($soloTarjeta, 'Vamos en ritmo') === false
           && mb_stripos($soloTarjeta, 'que apretar') === false,
           'decir «vamos en ritmo» contando solo lo que pasa por Crecer es inventarse el ritmo');
    } else {
        ok('con cobertura completa, la barra es legítima', true);
    }
    //  Y QUE LA REGLA SEA LA MISMA, NO UNA COPIA PARECIDA.
    //  Home ya NO nombra puedeAfirmarProgreso, y eso es lo correcto: la pregunta
    //  se hace UNA vez, al cruzar la frontera, y el DTO trae la respuesta. Lo
    //  que se exige es que el presentador la haga de verdad y que la barra
    //  cuelgue de ella — si el DTO no la trae, Home no puede pintarla.
    $pres = (string)file_get_contents(dirname(__DIR__) . '/core/Meta/MetaPresentador.php');
    ok('el presentador pregunta por la cobertura',
       strpos($pres, 'puedeAfirmarProgreso') !== false,
       'si no la pregunta nadie, el DTO se inventa el permiso');
    ok('y la barra del DTO cuelga de esa respuesta',
       preg_match('~[\'"]barra[\'"]\s*=>\s*\(\$puede~', $pres) === 1,
       'barra null cuando no se puede afirmar: así Home no la pinta ni por descuido');
    ok('y Home pinta la barra solo si el DTO la trae',
       preg_match('~if\s*\(\$__hm\[[\'"]barra[\'"]\]\)~', $home) === 1,
       'la regla vive en un sitio; aquí solo se obedece');

    // ══════════════════════════════════════════════════════════
    //  3 · LA CUOTA, CON LA MISMA REGLA
    // ══════════════════════════════════════════════════════════
    echo "\n  — 3 · con la cuota llena, las dos dicen lo mismo —\n";
    ok('Home conoce la regla de la cuota',
       strpos($home, 'MetaLimiteImagen') !== false,
       'hoy Home no la menciona: con el cubo lleno seguía mandando a producir');

    // ══════════════════════════════════════════════════════════
    //  4 · LAS DEMÁS DECISIONES DE HOME NO DESAPARECEN
    // ══════════════════════════════════════════════════════════
    echo "\n  — 4 · lo demás de Home sigue en pie —\n";
    $h = $pedir('index.php', 'marca=' . $M);
    ok('la pantalla responde entera', strpos($h, '<html') !== false
        && strpos($h, 'Fatal error') === false && strpos($h, 'Warning:') === false);
    foreach (['el saludo' => 'hz-hello', 'la tarjeta del turno' => 'hz-card'] as $qs => $marca) {
        ok("{$qs} sigue ahí", strpos($h, $marca) !== false,
           'coherencia no es quitarle a Home lo que ya servía');
    }

    // ══════════════════════════════════════════════════════════
    //  5 · EL MOTOR APAGADO
    // ══════════════════════════════════════════════════════════
    echo "\n  — 5 · CognitiveEngine —\n";
    $usan = [];
    foreach (glob(dirname(__DIR__) . '/{panel,includes,scripts}/*.php', GLOB_BRACE) as $p) {
        if (strpos((string)file_get_contents($p), 'CognitiveEngine') !== false) $usan[] = basename($p);
    }
    //  Es un hecho, no un juicio: si un dia se enciende, esta prueba dira donde.
    ok('se sabe quién lo usa', true,
       $usan ? implode(', ', $usan) : 'nadie fuera de core/ · hoy no decide nada de lo que ve el dueño');

} finally {
    @unlink($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid);
    Fixture::limpiar($pdo, $M);
    echo "\n  (fixture limpiada)\n";
}

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  PARIDAD COMPLETA · {$n} afirmaciones\n\n"
    : "  {$fallos} de {$n} · Home y Tu Meta todavía se contradicen\n\n";
exit($fallos === 0 ? 0 : 1);
