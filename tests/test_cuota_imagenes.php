<?php
// ============================================================
//  CRECER — LA GARANTIA DE CUOTA DE IMAGENES (Fase 3C · commit 2)
//  tests/test_cuota_imagenes.php
//
//  DOS PRUEBAS EN UNA, y las dos hacen falta.
//
//  A · LA LISTA BLANCA (estatica). Saca del fuente TODAS las llamadas a los
//      cuatro puntos de proveedor y las compara con las 17 rutas declaradas
//      aqui abajo. Si aparece una ruta nueva sin contexto de cuota, esta suite
//      se pone roja. Es la unica forma de que la garantia no se erosione: el
//      dia que alguien añada un quinto sitio que pinte imagenes, se entera.
//
//  B · EL LIBRO (de verdad, contra la base). Que reservar sea atomico, que el
//      respaldo no cobre dos veces, que el logo tenga su tope aparte, que un
//      job identificado no caduque y que el sin-identificar libere la unidad
//      del cliente y anote el riesgo como nuestro.
//
//  POR QUE UN CUBO Y NO UN SUM(): un INSERT..SELECT con agregado lee una
//  instantanea y dos transacciones concurrentes ven la misma suma. Aqui hay una
//  fila por (marca, cubo) y la reserva es un UPDATE condicional sobre ella. Eso
//  se prueba abajo con 6 procesos peleandose por 3 unidades.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/_fixture.php';
require_once __DIR__ . '/../includes/cuota_imagenes.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nLA GARANTIA DE CUOTA DE IMAGENES\n" . str_repeat('=', 56) . "\n";

// ══════════════════════════════════════════════════════════════
//  LOS CUATRO PUNTOS DE PROVEEDOR
//  Son estos y no otros. Si aparece un quinto, se declara aqui.
// ══════════════════════════════════════════════════════════════
$PUNTOS = ['gemini_imagen', 'openai_imagen', 'openai_responses_imagen', 'openai_responses_crear_bg'];

//  Y LA ENTRADA INDIRECTA. ia_imagen() no llama al proveedor: llama a
//  motor_imagen(), que elige entre P1 y P2 y ademas hace el respaldo. Sus
//  llamadores gastan igual, asi que son rutas de pleno derecho y se enumeran
//  con la misma exigencia. Sin esto, dos de las diecisiete se colarian por
//  debajo de la lista blanca sin que nadie se enterara.
$ENTRADAS = ['ia_imagen'];

// ══════════════════════════════════════════════════════════════
//  LA LISTA BLANCA · 17 RUTAS
//  archivo → [punto, ruta declarada, operacion, exencion, unidades]
//  El orden dentro de cada archivo es el de aparicion.
// ══════════════════════════════════════════════════════════════
$RUTAS = [
    // ── lo que paga el cliente ─────────────────────────────────
    ['includes/agentes.php',       'ia_imagen',                 'generar_logo',              'logo',        'logo',        0],
    ['includes/agentes.php',       'ia_imagen',                 'crear_arte_post',           'arte_post',   '',            1],
    ['includes/agentes.php',       'openai_responses_crear_bg', 'crear_arte_post_responses', 'arte_post',   '',            1],
    ['includes/carrusel.php',      'openai_responses_crear_bg', 'carrusel_encolar_arte',     'slide',       '',            1],
    ['includes/img_responses.php', 'openai_responses_crear_bg', 'img_resp_encolar_res',      'arte_post',   '',            1],
    ['includes/img_responses.php', 'openai_responses_crear_bg', 'logo_resp_encolar',         'logo',        'logo',        0],
    ['includes/img_responses.php', 'gemini_imagen',             'img_gemini_fallback',       'arte_post',   '',            1],
    ['includes/gen_async.php',     'openai_imagen',             'gen_async',                 'muestra',     '',            1],
    // ── diagnostico del admin: exento, pero asentado ───────────
    ['_cache.php',                 'openai_imagen',             'cache_test_img',            'diagnostico', 'admin',       0],
    ['_cache.php',                 'openai_imagen',             'cache_test_prompt',         'diagnostico', 'admin',       0],
    ['_cache.php',                 'openai_imagen',             'cache_humo_img',            'diagnostico', 'admin',       0],
    // ── laboratorio: exento, pero asentado ─────────────────────
    ['_imgtry.php',                'openai_responses_imagen',   'imgtry_resp_lote',          'laboratorio', 'laboratorio', 0],
    ['_imgtry.php',                'openai_imagen',             'imgtry_openai_lote',        'laboratorio', 'laboratorio', 0],
    ['_imgtry.php',                'openai_responses_imagen',   'imgtry_resp',               'laboratorio', 'laboratorio', 0],
    ['_imgtry.php',                'openai_imagen',             'imgtry_openai',             'laboratorio', 'laboratorio', 0],
    ['_imgtry.php',                'openai_responses_crear_bg', 'imgtry_bg',                 'laboratorio', 'laboratorio', 0],
    ['_imgtry.php',                'openai_responses_crear_bg', 'imgtry_bg_ciego',           'laboratorio', 'laboratorio', 0],
];

// ══════════════════════════════════════════════════════════════
//  A · LA LISTA BLANCA
// ══════════════════════════════════════════════════════════════
echo "\n  — los cuatro puntos fallan cerrado —\n";
$ia = (string)file_get_contents(dirname(__DIR__) . '/includes/ia.php');
foreach ($PUNTOS as $p) {
    $ini = strpos($ia, "function {$p}(");
    ok("{$p}() existe", $ini !== false);
    if ($ini === false) continue;
    $cuerpo = substr($ia, $ini, 900);
    ok("{$p}() exige contexto antes de gastar",
       strpos($cuerpo, 'CuotaImg::garantizar(') !== false,
       'sin la garantia, esta ruta se salta el tope entero');
    //  Y ANTES del curl: si la garantia fuera despues, ya se habria gastado.
    $pos_g = strpos($cuerpo, 'CuotaImg::garantizar(');
    $pos_c = strpos($cuerpo, 'curl_init');
    ok("{$p}() la exige ANTES de la red",
       $pos_c === false || $pos_g < $pos_c,
       'la garantia despues del curl no garantiza nada');
}
$cuota = (string)file_get_contents(dirname(__DIR__) . '/includes/cuota_imagenes.php');
ok('garantizar() lanza si no hay contexto',
   strpos($cuota, 'throw new CuotaFaltante') !== false,
   'fallar abierto convierte un olvido en un gasto sin tope');

echo "\n  — toda llamada a un punto esta declarada —\n";
//  Se recorre el fuente ENTERO, no una lista de archivos escrita a mano: si
//  mañana aparece un archivo nuevo que pinte imagenes, tambien cae aqui.
$raiz = dirname(__DIR__);
$fuentes = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS));
foreach ($it as $ruta) {
    $r = str_replace('\\', '/', (string)$ruta);
    if (substr($r, -4) !== '.php') continue;
    if (strpos($r, '/tests/') !== false) continue;         // ahi el borde de red va sustituido
    if (strpos($r, '/vendor/') !== false) continue;
    $fuentes[substr($r, strlen(str_replace('\\', '/', $raiz)) + 1)] = (string)file_get_contents($r);
}

$encontradas = [];
foreach ($fuentes as $rel => $src) {
    foreach (explode("\n", $src) as $i => $linea) {
        if (preg_match('/^\s*(\*|\/\/|#)/', $linea)) continue;      // comentario
        foreach ($PUNTOS as $p) {
            if (!preg_match('/(?<![a-z_])' . $p . '\s*\(/i', $linea)) continue;
            if (strpos($linea, 'function ' . $p) !== false) continue;  // la definicion
            $encontradas[] = ['archivo' => $rel, 'linea' => $i + 1, 'punto' => $p,
                              'texto' => trim($linea)];
        }
    }
}

//  El despachador es codigo de infraestructura, no una ruta: pasa el $opts que
//  le dieron y por eso NO declara contexto propio.
$infra = array_values(array_filter($encontradas,
    fn($e) => $e['archivo'] === 'includes/ia.php'));
$rutas_reales = array_values(array_filter($encontradas,
    fn($e) => $e['archivo'] !== 'includes/ia.php'));

ok('el despachador vive en ia.php y no declara ruta', count($infra) >= 1,
   'motor_imagen() reenvia el contexto que le dieron, no inventa uno');

printf("  ·    %d llamadas a los cuatro puntos, en %d archivos\n",
       count($rutas_reales), count(array_unique(array_column($rutas_reales, 'archivo'))));

//  Las que entran por el despachador. Mismo barrido, misma exigencia.
$indirectas = [];
foreach ($fuentes as $rel => $src) {
    foreach (explode("\n", $src) as $i => $linea) {
        if (preg_match('/^\s*(\*|\/\/|#)/', $linea)) continue;
        foreach ($ENTRADAS as $ent) {
            if (!preg_match('/(?<![a-z_])' . $ent . '\s*\(/i', $linea)) continue;
            if (strpos($linea, 'function ' . $ent) !== false) continue;
            $indirectas[] = ['archivo' => $rel, 'linea' => $i + 1, 'punto' => $ent, 'texto' => trim($linea)];
        }
    }
}
printf("  ·    %d por el despachador (ia_imagen)\n", count($indirectas));

$todas = array_merge($rutas_reales, $indirectas);
ok('salen exactamente las 17 declaradas', count($todas) === count($RUTAS),
   'encontradas ' . count($todas) . ' (' . count($rutas_reales) . ' directas + '
   . count($indirectas) . ' indirectas) · declaradas ' . count($RUTAS) . "\n         "
   . implode("\n         ", array_map(fn($e) => "{$e['archivo']}:{$e['linea']} · {$e['punto']}", $todas)));

echo "\n  — y cada una lleva su contexto —\n";
foreach ($todas as $e) {
    //  El contexto puede ir en la misma linea o en las 6 siguientes: estas
    //  llamadas son multilinea a proposito, para que se lea que se pasa.
    $lineas = explode("\n", $fuentes[$e['archivo']]);
    $trozo = implode("\n", array_slice($lineas, max(0, $e['linea'] - 2), 9));
    ok("{$e['archivo']}:{$e['linea']} ({$e['punto']}) pasa contexto",
       strpos($trozo, "'cuota'") !== false || strpos($trozo, '_lab_cuota(') !== false,
       'una ruta sin CuotaCtx llega al proveedor sin reserva: ' . mb_substr($e['texto'], 0, 70));
}

echo "\n  — las rutas declaradas existen de verdad —\n";
foreach ($RUTAS as [$arch, $punto, $ruta, $op, $exen, $uni]) {
    $src = $fuentes[$arch] ?? '';
    ok("{$ruta} está en {$arch}", strpos($src, "'{$ruta}'") !== false,
       'la lista blanca nombra una ruta que ya no existe; si se renombró, actualízala');
    if ($exen !== '') {
        ok("{$ruta} declara su exención «{$exen}»", strpos($src, "'{$exen}'") !== false);
    }
}
ok('toda exención usada está en la lista de exenciones legítimas',
   (function () use ($RUTAS) {
       foreach ($RUTAS as $r) {
           if ($r[4] !== '' && !in_array($r[4], CuotaImg::EXENCIONES, true)) return false;
       }
       return true;
   })(),
   'una exención inventada al vuelo es un agujero con nombre bonito');

echo "\n  — el logo oficial no se toca solo —\n";
$ag = $fuentes['includes/agentes.php'] ?? '';
ok('generar_logo() consulta el logo oficial antes de nada',
   strpos($ag, 'logo_oficial($pdo, $marca_id)') !== false);
ok('y devuelve ESE archivo en vez de generar otro',
   strpos($ag, "'oficial' => true") !== false,
   'reinterpretar el logo de un negocio es cambiarle la identidad sin permiso');
ok('reemplazarlo exige acción explícita del dueño',
   strpos($ag, "empty(\$opts['reemplazar'])") !== false);

// ══════════════════════════════════════════════════════════════
//  B · EL LIBRO, CONTRA LA BASE
// ══════════════════════════════════════════════════════════════
if (!CuotaImg::disponible($pdo, true)) {
    echo "\n  SALTADO EL LIBRO: falta migrations/2026-08-21_crecer_img_cuota.sql\n";
    echo "\n" . str_repeat('=', 56) . "\n";
    echo $fallos === 0 ? "  TODO OK (solo lista blanca) · {$n} pruebas\n\n" : "  {$fallos} FALLAS de {$n}\n\n";
    exit($fallos === 0 ? 0 : 1);
}

$M = null; $M2 = null;
try {
    $fx = Fixture::crear($pdo, 'cuota');   $M  = (int)$fx['marca_id'];
    $fy = Fixture::crear($pdo, 'cuota-b'); $M2 = (int)$fy['marca_id'];
    $limpiar = function (int $m) use ($pdo) {
        $pdo->prepare("DELETE FROM crecer_img_cuota_asiento WHERE marca_id=?")->execute([$m]);
        $pdo->prepare("DELETE FROM crecer_img_cuota_cubo WHERE marca_id=?")->execute([$m]);
    };
    $ctx = fn(int $m, string $op, string $ruta, ?int $oid, array $mas = []) =>
        CuotaCtx::de($pdo, $m, $op, $ruta, ['origen_tipo' => 'contenido', 'origen_id' => $oid] + $mas);

    echo "\n  — el mes se cuenta en hora de Puerto Rico —\n";
    ok('el cubo del mes lleva su formato', CuotaImg::cuboMes('2026-08-21') === 'M:2026-08');
    //  El caso que motiva calcularlo en APP_TZ: el 31 a las 9pm de PR, en UTC
    //  ya es dia 1 del mes siguiente. Con el mes saliendo de la base, esa
    //  imagen se cobraria del mes que viene.
    $pr  = new DateTime('2026-08-31 21:00', new DateTimeZone('America/Puerto_Rico'));
    $utc = (clone $pr)->setTimezone(new DateTimeZone('UTC'));
    ok('el 31 a las 9pm de PR ya es otro mes en UTC',
       $pr->format('Y-m') !== $utc->format('Y-m'),
       'si no fuera asi, esta prueba no probaria nada');
    ok('y aun asi el cubo es el de agosto',
       CuotaImg::cuboMes('2026-08-31 21:00') === 'M:2026-08');

    echo "\n  — reservar aparta de verdad —\n";
    $limpiar($M);
    $r1 = CuotaImg::reservar($pdo, $ctx($M, 'arte_post', 'img_resp_encolar_res', 101));
    ok('la primera reserva entra', $r1['ok'] === true && $r1['asiento_id'] > 0);
    ok('y descuenta una del mes', CuotaImg::restantes($pdo, $M) === CuotaImg::TOPE_MES - 1);
    ok('el estado se lo puede contar al dueño',
       CuotaImg::estado($pdo, $M)['restantes'] === CuotaImg::TOPE_MES - 1);
    ok('y no dice que esté lleno', CuotaImg::estado($pdo, $M)['lleno'] === false);

    echo "\n  — la misma imagen NO cobra dos veces —\n";
    //  Por garantizar(), que es por donde entran los puntos de proveedor de
    //  verdad. `llamadas` se cuenta AHI y solo ahí: reservar() a secas no
    //  representa una entrada al proveedor, así que contarla desde aquí medía
    //  otra cosa. Es el arreglo del 21 de agosto — antes se sumaba en tres
    //  sitios que no se conocían y salían 3 llamadas para 2 encolados.
    $g1 = CuotaImg::garantizar($ctx($M, 'arte_post', 'img_resp_encolar_res', 101),
                               'P4 openai_responses_crear_bg');
    $g2 = CuotaImg::garantizar($ctx($M, 'arte_post', 'img_gemini_fallback', 101),
                               'P1 gemini_imagen');
    ok('el respaldo reusa el asiento', $g2->asiento_id === $g1->asiento_id
       && $g1->asiento_id === $r1['asiento_id'],
       'aqui es donde un rechazo de gpt-image-1 le costaria 2 de 40 al dueño');
    ok('y el mes sigue en una sola unidad', CuotaImg::restantes($pdo, $M) === CuotaImg::TOPE_MES - 1);
    CuotaImg::confirmar($pdo, $r1['asiento_id'], 0.10);
    $q = $pdo->prepare("SELECT llamadas, unidades, estado FROM crecer_img_cuota_asiento WHERE id=?");
    $q->execute([$r1['asiento_id']]); $a = $q->fetch(PDO::FETCH_ASSOC);
    ok('pero se anotan las dos llamadas de proveedor', (int)$a['llamadas'] === 2,
       'salió ' . $a['llamadas'] . ' · una unidad de cliente, las llamadas que hagan falta colgadas de ella');
    ok('y la unidad sigue siendo una', (int)$a['unidades'] === 1);
    ok('la imagen queda confirmada', $a['estado'] === 'confirmado');

    echo "\n  — otra imagen sí es otra unidad —\n";
    $r3 = CuotaImg::reservar($pdo, $ctx($M, 'arte_post', 'img_resp_encolar_res', 102));
    ok('reserva propia', $r3['reusado'] === false && $r3['asiento_id'] !== $r1['asiento_id']);
    ok('y descuenta', CuotaImg::restantes($pdo, $M) === CuotaImg::TOPE_MES - 2);

    echo "\n  — liberar devuelve la unidad —\n";
    CuotaImg::liberar($pdo, $r3['asiento_id'], 'el proveedor la rechazó');
    ok('vuelve al cubo', CuotaImg::restantes($pdo, $M) === CuotaImg::TOPE_MES - 1);
    CuotaImg::liberar($pdo, $r3['asiento_id'], 'otra vez');
    ok('liberar dos veces no regala una unidad', CuotaImg::restantes($pdo, $M) === CuotaImg::TOPE_MES - 1,
       'la simetria del reservar: el descuento va en el mismo UPDATE que comprueba el estado');

    echo "\n  — el tope se respeta y se dice sin dramatismo —\n";
    $limpiar($M);
    for ($i = 0; $i < CuotaImg::TOPE_MES; $i++) {
        CuotaImg::reservar($pdo, $ctx($M, 'arte_post', 'img_resp_encolar_res', 1000 + $i));
    }
    ok('llega justo al tope', CuotaImg::restantes($pdo, $M) === 0);
    $lleno = CuotaImg::reservar($pdo, $ctx($M, 'arte_post', 'img_resp_encolar_res', 9999));
    ok('la 41 no entra', $lleno['ok'] === false);
    ok('y lo dice como límite, no como avería', $lleno['motivo'] === 'sin_cuota');
    ok('el estado lo refleja', CuotaImg::estado($pdo, $M)['lleno'] === true);
    ok('con la fecha de renovación', preg_match('#^\d{2}/\d{2}$#', CuotaImg::estado($pdo, $M)['reset']) === 1);
    ok('y no se cuela por el asiento', (int)$pdo->query(
       "SELECT COUNT(*) FROM crecer_img_cuota_asiento WHERE marca_id={$M} AND origen_id=9999")->fetchColumn() === 0,
       'un asiento sin unidad detras seria un gasto fuera del libro');

    echo "\n  — el tope es por marca, no del mundo —\n";
    ok('la otra marca no se contagia', CuotaImg::restantes($pdo, $M2) === CuotaImg::TOPE_MES);
    $rb = CuotaImg::reservar($pdo, $ctx($M2, 'arte_post', 'img_resp_encolar_res', 101));
    ok('y reserva la suya', $rb['ok'] === true);

    echo "\n  — el logo va por su cuenta —\n";
    $limpiar($M);
    ok('el logo no toca las 40', (function () use ($pdo, $M, $ctx) {
        CuotaImg::reservar($pdo, $ctx($M, 'logo', 'generar_logo', 1, ['exencion' => 'logo', 'origen_tipo' => 'logo']));
        return CuotaImg::restantes($pdo, $M) === CuotaImg::TOPE_MES;
    })(), 'exento de la cuota normal, por decisión del 21 de agosto');
    ok('pero sí gasta de su cubo de por vida',
       CuotaImg::restantes($pdo, $M, CuotaImg::cuboLogos()) === CuotaImg::TOPE_LOGOS_VIDA - 1);
    for ($i = 2; $i <= CuotaImg::TOPE_LOGOS_VIDA; $i++) {
        CuotaImg::reservar($pdo, $ctx($M, 'logo', 'generar_logo', $i, ['exencion' => 'logo', 'origen_tipo' => 'logo']));
    }
    $sexto = CuotaImg::reservar($pdo, $ctx($M, 'logo', 'generar_logo', 99, ['exencion' => 'logo', 'origen_tipo' => 'logo']));
    ok('el sexto logo no entra', $sexto['ok'] === false && $sexto['motivo'] === 'sin_logos',
       'son 5 de por vida por marca');
    ok('y las 40 del mes siguen intactas', CuotaImg::restantes($pdo, $M) === CuotaImg::TOPE_MES);
    ok('el estado enseña los logos gastados',
       CuotaImg::estado($pdo, $M)['logos'] === CuotaImg::TOPE_LOGOS_VIDA);

    echo "\n  — exento no es invisible —\n";
    $limpiar($M);
    CuotaImg::reservar($pdo, $ctx($M, 'laboratorio', 'imgtry_bg', 7, ['exencion' => 'laboratorio', 'origen_tipo' => 'banco']));
    $ex = $pdo->query("SELECT exencion, unidades FROM crecer_img_cuota_asiento
                        WHERE marca_id={$M} AND operacion='laboratorio'")->fetch(PDO::FETCH_ASSOC);
    ok('el laboratorio deja su asiento', $ex !== false);
    ok('con unidades 0', $ex && (int)$ex['unidades'] === 0);
    ok('y con su exención escrita', $ex && $ex['exencion'] === 'laboratorio',
       'un gasto que no se ve no se puede auditar');
    ok('no descuenta del mes', CuotaImg::restantes($pdo, $M) === CuotaImg::TOPE_MES);

    echo "\n  — una exención inventada se rechaza —\n";
    try {
        CuotaImg::reservar($pdo, $ctx($M, 'arte_post', 'ruta_falsa', 5, ['exencion' => 'porque_si']));
        ok('lanza ante una exención desconocida', false, 'la aceptó');
    } catch (InvalidArgumentException $e) {
        ok('lanza ante una exención desconocida', true);
    }

    echo "\n  — P4 sin job id: el riesgo es nuestro, no del cliente —\n";
    $limpiar($M);
    $rp = CuotaImg::reservar($pdo, $ctx($M, 'arte_post', 'img_resp_encolar_res', 500));
    ok('la reserva estaba puesta', CuotaImg::restantes($pdo, $M) === CuotaImg::TOPE_MES - 1);
    CuotaImg::riesgoPlataforma($pdo, $rp['asiento_id'], 0.17);
    ok('se le devuelve la unidad al dueño', CuotaImg::restantes($pdo, $M) === CuotaImg::TOPE_MES,
       'no puede pagar por algo que quizá no reciba');
    $ra = $pdo->prepare("SELECT estado, costo_usd FROM crecer_img_cuota_asiento WHERE id=?");
    $ra->execute([$rp['asiento_id']]); $rr = $ra->fetch(PDO::FETCH_ASSOC);
    ok('y queda marcado como riesgo de plataforma', $rr['estado'] === 'riesgo');
    ok('con el costo posible anotado', (float)$rr['costo_usd'] > 0,
       'el gasto puede existir aunque no tengamos con qué recogerlo');

    echo "\n  — si el job aparece después, se consume —\n";
    $co = CuotaImg::correlacionar($pdo, $rp['asiento_id'], 'resp_tardio_1', 0.17);
    ok('correlaciona', $co['ok'] === true);
    ok('y ahora sí descuenta', CuotaImg::restantes($pdo, $M) === CuotaImg::TOPE_MES - 1);
    ok('sin overage porque cabía', $co['overage'] === false);

    echo "\n  — y si ya no cabía, se consume marcando overage —\n";
    $limpiar($M);
    $rq = CuotaImg::reservar($pdo, $ctx($M, 'arte_post', 'img_resp_encolar_res', 600));
    CuotaImg::riesgoPlataforma($pdo, $rq['asiento_id'], 0.17);
    for ($i = 0; $i < CuotaImg::TOPE_MES; $i++) {
        CuotaImg::reservar($pdo, $ctx($M, 'arte_post', 'img_resp_encolar_res', 2000 + $i));
    }
    ok('el mes quedó lleno', CuotaImg::restantes($pdo, $M) === 0);
    $co2 = CuotaImg::correlacionar($pdo, $rq['asiento_id'], 'resp_tardio_2', 0.17);
    ok('se consume igual', $co2['ok'] === true);
    ok('y se declara overage', $co2['overage'] === true,
       'dejarlo fuera del libro sería peor que un número incómodo: el gasto ocurrió');

    echo "\n  — un job identificado NO caduca por reloj —\n";
    $limpiar($M);
    $rj = CuotaImg::reservar($pdo, $ctx($M, 'arte_post', 'img_resp_encolar_res', 700));
    CuotaImg::atarJob($pdo, $rj['asiento_id'], 'resp_vivo_1');
    $rs = CuotaImg::reservar($pdo, $ctx($M, 'arte_post', 'img_resp_encolar_res', 701));
    //  Las dos se envejecen; solo la que no tiene job debe caducar.
    $pdo->prepare("UPDATE crecer_img_cuota_asiento SET created_at = NOW() - INTERVAL ? MINUTE
                    WHERE id IN (?,?)")
        ->execute([CuotaImg::CADUCA_MIN + 10, $rj['asiento_id'], $rs['asiento_id']]);
    $barridas = CuotaImg::barrerCaducadas($pdo, $M);
    ok('caduca la que no tiene identificador', $barridas === 1,
       'barridas ' . $barridas);
    $est = $pdo->prepare("SELECT estado FROM crecer_img_cuota_asiento WHERE id=?");
    $est->execute([$rj['asiento_id']]);
    ok('y la del job identificado sigue viva', $est->fetchColumn() === 'reservado',
       'un job remoto puede tardar lo que tarde; devolverle la unidad antes descuadraría el mes');
    ok('el mes solo recupera la caducada', CuotaImg::restantes($pdo, $M) === CuotaImg::TOPE_MES - 1);

    // ══════════════════════════════════════════════════════════
    //  LA CARRERA · 6 procesos, 3 unidades
    // ══════════════════════════════════════════════════════════
    echo "\n  — seis peticiones para tres unidades —\n";
    $limpiar($M);
    $pdo->prepare("INSERT INTO crecer_img_cuota_cubo (marca_id, cubo, limite, usadas) VALUES (?,?,3,0)")
        ->execute([$M, CuotaImg::cuboMes()]);
    $runner = __DIR__ . DIRECTORY_SEPARATOR . '_cuota_runner.php';
    if (!function_exists('proc_open')) {
        echo "  (saltada: proc_open no esta disponible)\n";
    } else {
        $cita = microtime(true) + 1.8;
        $procs = []; $tubos = [];
        for ($i = 0; $i < 6; $i++) {
            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' '
                 . $M . ' ' . (5000 + $i) . ' ' . sprintf('%.4f', $cita);
            $t = [];
            $p = proc_open($cmd, [1 => ['pipe','w'], 2 => ['pipe','w']], $t);
            if (is_resource($p)) { $procs[] = $p; $tubos[] = $t; }
        }
        $dijo = [];
        foreach ($tubos as $k => $t) {
            $dijo[] = trim(stream_get_contents($t[1]));
            fclose($t[1]); fclose($t[2]); proc_close($procs[$k]);
        }
        $entraron = count(array_filter($dijo, fn($d) => strpos($d, 'RESERVO') === 0));
        $errores  = array_values(array_filter($dijo, fn($d) => strpos($d, 'ERROR') === 0));
        ok('arrancaron los seis', count($dijo) === 6, 'salieron ' . count($dijo));
        ok('ninguno reventó', $errores === [], implode(' | ', $errores));
        ok('entran exactamente tres', $entraron === 3,
           'entraron ' . $entraron . ' · [' . implode(', ', $dijo) . '] — '
           . 'un SUM() en el WHERE habria dejado pasar a mas de tres');
        $usadas = (int)$pdo->query("SELECT usadas FROM crecer_img_cuota_cubo
                                     WHERE marca_id={$M} AND cubo='" . CuotaImg::cuboMes() . "'")->fetchColumn();
        ok('y el cubo cuadra exactamente', $usadas === 3, "usadas={$usadas}");
        $asientos = (int)$pdo->query("SELECT COUNT(*) FROM crecer_img_cuota_asiento
                                       WHERE marca_id={$M} AND unidades>0")->fetchColumn();
        ok('sin asientos de más', $asientos === 3, "asientos={$asientos}");
    }

} finally {
    foreach ([$M, $M2] as $m) {
        if (!$m) continue;
        $pdo->prepare("DELETE FROM crecer_img_cuota_asiento WHERE marca_id=?")->execute([$m]);
        $pdo->prepare("DELETE FROM crecer_img_cuota_cubo WHERE marca_id=?")->execute([$m]);
        Fixture::limpiar($pdo, $m);
    }
    echo "\n  (fixtures limpiadas)\n";
}

echo "\n" . str_repeat('=', 56) . "\n";
echo $fallos === 0 ? "  TODO OK · {$n} pruebas\n\n" : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
