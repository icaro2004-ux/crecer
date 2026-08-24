<?php
// ============================================================
//  CRECER — LA CUOTA DE UNA PUBLICACION SE SUMA ENTERA
//  tests/test_meta_cuota_aviso.php
//
//  DOS AFIRMACIONES MIAS QUE ERAN FALSAS, y esta prueba existe para que no
//  vuelvan:
//
//  1. «El aviso sale cuando hay un asiento confirmado». No salia nunca: la
//     lectura usaba CuotaImg::asientoDePieza(), que es del SONDEO y filtra
//     estado IN ('reservado','riesgo'), y despues exigia 'confirmado'. Pedia
//     justo lo que la consulta excluia.
//
//  2. «Una publicacion tiene como mucho un asiento, lo garantiza
//     UNIQUE(marca_id, idem)». NO lo garantiza. `idem` lleva DENTRO la
//     operacion y el origen, asi que ese indice garantiza un asiento por
//     (marca, operacion, origen) — que es otra cosa. La misma publicacion
//     puede tener a la vez:
//       · `arte_post`  arte hecho desde cero        → origen contenido
//       · `realce`     su foto mejorada con IA      → origen contenido
//       · `slide` xN   una por slide de su carrusel → origen slide
//     Contando solo `arte_post` se callaba el realce entero y los carruseles
//     enteros.
//
//  CERO PROVEEDOR: un asiento es una FILA. Aqui no se genera ninguna imagen.
//  BASE DESECHABLE: la compartida no recibe ni una fila, y se comprueba.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/cuota_imagenes.php';
require_once __DIR__ . '/../includes/meta_semana.php';
require_once __DIR__ . '/_esquema_desechable.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nLA CUOTA DE UNA PUBLICACION · ENTERA\n" . str_repeat('=', 58) . "\n";

$base = EsquemaDesechable::crear($pdo, ['crecer_img_cuota_asiento', 'crecer_img_cuota_cubo',
                                        'crecer_contenido', 'crecer_carrusel',
                                        'crecer_marca', 'usuarios']);
if (!$base) {
    echo "\n  SALTADO · este usuario de base de datos no puede crear bases\n\n";
    exit(0);
}

$antes_compartida = (int)$pdo->query("SELECT COUNT(*) FROM crecer_img_cuota_asiento")->fetchColumn();
$db = $base->pdo();

/**
 * Un asiento, escrito a mano. Es una FILA: ninguna imagen se genera aquí.
 * La `idem` se calcula con el MISMO helper que usa la reserva de verdad, así
 * que estas filas son indistinguibles de las que escribe producción.
 */
$asiento = function (array $c) use ($db) {
    $op  = $c['op']   ?? 'arte_post';
    $tip = $c['tipo'] ?? 'contenido';
    $db->prepare(
        "INSERT INTO crecer_img_cuota_asiento
           (marca_id, cubo, idem, operacion, ruta, punto, exencion, unidades,
            estado, origen_tipo, origen_id, llamadas, costo_usd, overage, created_at)
         VALUES (?,?,?,?, 'prueba','prueba', ?, ?, ?, ?, ?, 0, 0, 0, NOW())")
      ->execute([
        $c['marca'], CuotaImg::cuboMes(),
        CuotaImg::idem((int)$c['marca'], $op, $tip, (int)$c['origen']),
        $op, $c['exencion'] ?? '', $c['unidades'] ?? 1, $c['estado'], $tip, $c['origen'],
      ]);
    return (int)$db->lastInsertId();
};
/** Un slide de carrusel colgando de una publicación. */
$slide = function (int $contenido, int $marca, int $orden) use ($db) {
    $db->prepare("INSERT INTO crecer_carrusel (contenido_id, marca_id, orden, idea, img_estado)
                  VALUES (?,?,?, 'Idea de relleno.', 'ok')")
       ->execute([$contenido, $marca, $orden]);
    return (int)$db->lastInsertId();
};
/** Cuántas unidades ve el dominio. */
$u = fn(int $marca, int $pieza) => (int)semana_cuota_gastada($db, $marca, $pieza)['unidades'];
$g = fn(int $marca, int $pieza) => (bool)semana_cuota_gastada($db, $marca, $pieza)['gastada'];

try {
    $MARCA = 4001;      // en la copia no hay FK: la marca es solo un número
    $OTRA  = 4002;

    // ══════════════════════════════════════════════════════════════
    //  1 · LAS RUTAS REALES DE CONSUMO, UNA POR UNA
    // ══════════════════════════════════════════════════════════════
    echo "\n  — arte desde cero —\n";
    $P_ARTE = 9101;
    $asiento(['marca'=>$MARCA, 'origen'=>$P_ARTE, 'op'=>'arte_post', 'estado'=>'confirmado']);
    ok('un arte_post confirmado cuenta 1', $u($MARCA, $P_ARTE) === 1, (string)$u($MARCA, $P_ARTE));
    ok('y se declara gastada',             $g($MARCA, $P_ARTE) === true);

    echo "\n  — la foto del dueño, mejorada con IA —\n";
    //  agentes.php:1009 → $tiene_foto ? 'realce' : 'arte_post'. Otra operación,
    //  otra idem, OTRO asiento. Con la lectura vieja esto era invisible.
    $P_REALCE = 9102;
    $asiento(['marca'=>$MARCA, 'origen'=>$P_REALCE, 'op'=>'realce', 'estado'=>'confirmado']);
    ok('un realce confirmado cuenta 1', $u($MARCA, $P_REALCE) === 1,
       'consultando solo arte_post esto daba 0 y la pantalla callaba');

    echo "\n  — la misma publicación por las dos rutas —\n";
    $P_DOS = 9103;
    $asiento(['marca'=>$MARCA, 'origen'=>$P_DOS, 'op'=>'arte_post', 'estado'=>'confirmado']);
    $asiento(['marca'=>$MARCA, 'origen'=>$P_DOS, 'op'=>'realce',    'estado'=>'confirmado']);
    ok('arte_post + realce suman 2', $u($MARCA, $P_DOS) === 2, (string)$u($MARCA, $P_DOS));
    ok('y las dos filas conviven: la llave única no lo impide',
       (int)$db->query("SELECT COUNT(*) FROM crecer_img_cuota_asiento
                         WHERE origen_tipo='contenido' AND origen_id={$P_DOS}")->fetchColumn() === 2,
       'UNIQUE(marca_id, idem) separa por operación, no por publicación');

    echo "\n  — se suman UNIDADES, no filas —\n";
    $P_MULTI = 9104;
    $asiento(['marca'=>$MARCA, 'origen'=>$P_MULTI, 'op'=>'arte_post', 'estado'=>'confirmado', 'unidades'=>2]);
    $asiento(['marca'=>$MARCA, 'origen'=>$P_MULTI, 'op'=>'realce',    'estado'=>'confirmado', 'unidades'=>3]);
    ok('dos asientos de 2 y 3 unidades suman 5', $u($MARCA, $P_MULTI) === 5,
       (string)$u($MARCA, $P_MULTI) . ' — con COUNT(*) habría dicho 2');

    echo "\n  — los slides de un carrusel —\n";
    //  carrusel.php:287 → origen_tipo='slide', origen_id=crecer_carrusel.id.
    //  La publicación no aparece por ningún lado en el asiento: la relación es
    //  de esquema (crecer_carrusel.contenido_id), y hay que ir a buscarla.
    $P_CAR1 = 9201;
    $s1 = $slide($P_CAR1, $MARCA, 1);
    $asiento(['marca'=>$MARCA, 'origen'=>$s1, 'op'=>'slide', 'tipo'=>'slide', 'estado'=>'confirmado']);
    ok('un carrusel con un slide confirmado cuenta 1', $u($MARCA, $P_CAR1) === 1,
       'el asiento cuelga del slide, no de la publicación');

    $P_CAR5 = 9202;
    for ($i = 1; $i <= 5; $i++) {
        $sid = $slide($P_CAR5, $MARCA, $i);
        $asiento(['marca'=>$MARCA, 'origen'=>$sid, 'op'=>'slide', 'tipo'=>'slide', 'estado'=>'confirmado']);
    }
    ok('un carrusel de cinco slides cuenta 5', $u($MARCA, $P_CAR5) === 5, (string)$u($MARCA, $P_CAR5));

    echo "\n  — un carrusel al que además se le realzó una foto —\n";
    $P_MIX = 9203;
    $sid = $slide($P_MIX, $MARCA, 1);
    $asiento(['marca'=>$MARCA, 'origen'=>$sid,   'op'=>'slide',  'tipo'=>'slide', 'estado'=>'confirmado']);
    $asiento(['marca'=>$MARCA, 'origen'=>$P_MIX, 'op'=>'realce', 'estado'=>'confirmado']);
    ok('las dos ramas se suman', $u($MARCA, $P_MIX) === 2, (string)$u($MARCA, $P_MIX));

    // ══════════════════════════════════════════════════════════════
    //  2 · EL CANDADO CONTRA LA REGRESIÓN
    //      Si alguien vuelve a mirar solo `arte_post`, estas caen a la vez.
    // ══════════════════════════════════════════════════════════════
    echo "\n  — la prueba que se pone roja si se vuelve a mirar solo arte_post —\n";
    ok('el realce solo NO puede dar 0',   $u($MARCA, $P_REALCE) > 0);
    ok('el carrusel solo NO puede dar 0', $u($MARCA, $P_CAR5) > 0);
    ok('y arte+realce NO puede dar 1',    $u($MARCA, $P_DOS) !== 1,
       'daría exactamente 1 si solo se mirase arte_post');

    $dom = (string)file_get_contents(__DIR__ . '/../includes/meta_semana.php');
    ok('el dominio ya no reconstruye una llave suelta',
       strpos($dom, "CuotaImg::idem(\$marca_id, 'arte_post'") === false,
       'la atribución se hace por columnas reales, no por una idem adivinada');
    ok('y la lista de operaciones sale del libro, no de aquí',
       strpos($dom, 'CuotaImg::POR_PIEZA') !== false,
       'si mañana nace otra operación por pieza, tiene que contarse sola');

    // ══════════════════════════════════════════════════════════════
    //  3 · LOS CONTROLES NEGATIVOS — cada uno tiene que CALLAR
    // ══════════════════════════════════════════════════════════════
    echo "\n  — sin evidencia, silencio —\n";
    foreach ([['reservado', 'reservar aparta la unidad; no la gasta'],
              ['liberado',  'se devolvió antes de gastarse'],
              ['riesgo',    'todavía no se sabe si se entregó']] as $i => $par) {
        [$estado, $por] = $par;
        $pid = 9300 + $i;
        $asiento(['marca'=>$MARCA, 'origen'=>$pid, 'op'=>'arte_post', 'estado'=>$estado]);
        ok("{$estado} calla", $u($MARCA, $pid) === 0, $por);
        //  Y también por la rama de slides, que es código aparte.
        $pid2 = 9310 + $i;
        $sid  = $slide($pid2, $MARCA, 1);
        $asiento(['marca'=>$MARCA, 'origen'=>$sid, 'op'=>'slide', 'tipo'=>'slide', 'estado'=>$estado]);
        ok("un slide {$estado} también calla", $u($MARCA, $pid2) === 0);
    }

    $P_CERO = 9320;
    $asiento(['marca'=>$MARCA, 'origen'=>$P_CERO, 'op'=>'arte_post', 'estado'=>'confirmado', 'unidades'=>0]);
    ok('confirmado con cero unidades calla', $u($MARCA, $P_CERO) === 0);

    $P_EX = 9321;
    $asiento(['marca'=>$MARCA, 'origen'=>$P_EX, 'op'=>'realce', 'estado'=>'confirmado',
              'exencion'=>'material_propio']);
    ok('un realce EXENTO calla', $u($MARCA, $P_EX) === 0,
       'material_propio pesa 0 en el cubo: no consumió del mes del dueño');
    $P_EX2 = 9322;
    $sid = $slide($P_EX2, $MARCA, 1);
    $asiento(['marca'=>$MARCA, 'origen'=>$sid, 'op'=>'slide', 'tipo'=>'slide',
              'estado'=>'confirmado', 'exencion'=>'misma_imagen']);
    ok('un slide exento también calla', $u($MARCA, $P_EX2) === 0);

    ok('una publicación sin asiento calla', $u($MARCA, 9399) === 0);

    //  MATERIAL PROPIO SIN GENERACIÓN: tiene imagen, pero nunca hubo reserva.
    $P_MIO = 9330;
    $db->prepare("INSERT INTO crecer_contenido (id, marca_id, plataforma, tipo, caption, estado, grafica_path)
                  VALUES (?,?, 'instagram','post','Relleno con foto del dueño.','borrador','/subidas/mia.jpg')")
       ->execute([$P_MIO, $MARCA]);
    ok('material propio sin asiento calla', $u($MARCA, $P_MIO) === 0,
       'tener imagen no es haberla generado');

    echo "\n  — lo que no es de esta publicación no cuenta —\n";
    $P_AJENA = 9401;
    $asiento(['marca'=>$OTRA, 'origen'=>$P_AJENA, 'op'=>'arte_post', 'estado'=>'confirmado']);
    ok('el asiento de OTRA marca no se ve desde esta', $u($MARCA, $P_AJENA) === 0);
    ok('pero sí desde la suya',                        $u($OTRA, $P_AJENA) === 1,
       'si esto fallara, el control anterior pasaría por el motivo equivocado');
    ok('el asiento de OTRA publicación no se ve',      $u($MARCA, $P_ARTE + 700) === 0);

    //  UN SLIDE DE OTRO CARRUSEL: el error clásico de un JOIN mal atado.
    $P_C_A = 9410; $P_C_B = 9411;
    $sA = $slide($P_C_A, $MARCA, 1);
    $asiento(['marca'=>$MARCA, 'origen'=>$sA, 'op'=>'slide', 'tipo'=>'slide', 'estado'=>'confirmado']);
    ok('el slide del carrusel A no cuenta en el B', $u($MARCA, $P_C_B) === 0);
    ok('y sí cuenta en el A',                        $u($MARCA, $P_C_A) === 1);

    //  UN SLIDE DE OTRA MARCA con el mismo contenido_id: el JOIN exige marca.
    $sX = $slide($P_C_B, $OTRA, 1);
    $asiento(['marca'=>$OTRA, 'origen'=>$sX, 'op'=>'slide', 'tipo'=>'slide', 'estado'=>'confirmado']);
    ok('un slide de otra marca no se cuela por el JOIN', $u($MARCA, $P_C_B) === 0,
       'el JOIN ata marca_id además de contenido_id');

    echo "\n  — las operaciones que no son de la publicación —\n";
    //  logo, muestra, diagnostico y laboratorio NO están en POR_PIEZA. Aunque
    //  alguien les pusiera origen 'contenido', no son consumo de esta pieza.
    foreach (['logo', 'muestra', 'diagnostico', 'laboratorio'] as $i => $op) {
        $pid = 9500 + $i;
        $asiento(['marca'=>$MARCA, 'origen'=>$pid, 'op'=>$op, 'estado'=>'confirmado']);
        ok("«{$op}» no cuenta como cuota de la publicación", $u($MARCA, $pid) === 0);
    }

    echo "\n  — parámetros imposibles no revientan ni afirman —\n";
    ok('marca 0 calla',    $u(0, $P_ARTE) === 0);
    ok('pieza 0 calla',    $u($MARCA, 0) === 0);
    ok('negativos callan', $u(-1, -1) === 0);

    // ══════════════════════════════════════════════════════════════
    //  4 · LO QUE SE LE DICE AL DUEÑO
    // ══════════════════════════════════════════════════════════════
    echo "\n  — singular, plural y silencio —\n";
    ok('cero no afirma nada sobre consumo', semana_frase_cuota(0) === '',
       'junto a un botón de quitar, «no gasta» se lee como devolución');
    ok('negativo tampoco',                  semana_frase_cuota(-3) === '');
    ok('una unidad va en singular',
       semana_frase_cuota(1) === 'Esta imagen ya cuenta en tu cuota del mes aunque quites la publicación.',
       semana_frase_cuota(1));
    ok('tres unidades van en plural y con el número',
       semana_frase_cuota(3) === 'Estas 3 imágenes ya cuentan en tu cuota del mes aunque quites la publicación.',
       semana_frase_cuota(3));
    ok('cinco también', strpos(semana_frase_cuota(5), 'Estas 5 imágenes ya cuentan') === 0);
    foreach ([1, 2, 5] as $k) {
        ok("con {$k} no se promete devolución",
           stripos(semana_frase_cuota($k), 'no gasta') === false
           && stripos(semana_frase_cuota($k), 'devuel') === false
           && stripos(semana_frase_cuota($k), 'no genera') === false,
           semana_frase_cuota($k));
    }

    // ══════════════════════════════════════════════════════════════
    //  5 · LAS VISTAS RECIBEN EL NÚMERO, NO LO DEDUCEN
    // ══════════════════════════════════════════════════════════════
    echo "\n  — la vista no cuenta: le cuentan —\n";
    $vista = (string)file_get_contents(__DIR__ . '/../panel/_meta_semana.php');
    ok('la Capa 2 pinta la frase que le da el servidor',
       strpos($vista, 'data-cuota-tx') !== false && strpos($vista, 'el.dataset.cuotaTx') !== false);
    ok('y el atributo sale de semana_frase_cuota() con las unidades',
       strpos($vista, "semana_frase_cuota((int)\$x['cuota']['unidades'])") !== false);
    ok('la vista NO escribe la frase a mano',
       strpos($vista, 'ya cuenta en tu cuota') === false
       && strpos($vista, 'ya cuentan en tu cuota') === false);

    $puerta = (string)file_get_contents(__DIR__ . '/../panel/_meta_sustituir.php');
    ok('la puerta usa las unidades reales',
       strpos($puerta, "semana_frase_cuota((int)\$su_cuota['unidades'])") !== false);
    ok('y no pinta la línea cuando no hay nada que decir',
       strpos($puerta, "if (\$su_cuota_tx !== '')") !== false,
       'una línea vacía es peor que ninguna línea');
    ok('ninguna pantalla niega el consumo en seco',
       stripos($puerta, 'no gasta imágenes') === false
       && stripos($puerta, 'no gasta imagenes') === false);
    ok('y ya no queda la frase de relleno del caso cero',
       strpos($puerta, 'no genera otra imagen hasta preparar') === false
       && strpos($dom, 'Sustituirla no genera otra imagen') === false,
       'se retiró: al lado de quitar se leía como devolución');

    // ══════════════════════════════════════════════════════════════
    //  6 · NI UNA LLAMADA A NADIE
    // ══════════════════════════════════════════════════════════════
    echo "\n  — un asiento es una fila, no una imagen —\n";
    $ini = strpos($dom, 'function semana_cuota_gastada');
    $fin = strpos($dom, 'function semana_frase_cuota');
    $cuerpo = substr($dom, (int)$ini, max(0, (int)$fin - (int)$ini));
    ok('la función existe y se pudo aislar', $ini !== false && $fin !== false && $cuerpo !== '');
    foreach (['curl_', 'file_get_contents', 'fsockopen', 'stream_socket',
              'openai', 'gemini', 'vertex', 'API_KEY', 'getenv'] as $mal) {
        ok("semana_cuota_gastada() no usa «{$mal}»", stripos($cuerpo, $mal) === false);
    }
    ok('y tampoco reserva, confirma ni libera nada',
       stripos($cuerpo, 'CuotaImg::reservar') === false
       && stripos($cuerpo, 'CuotaImg::confirmar') === false
       && stripos($cuerpo, 'CuotaImg::liberar') === false,
       'leer el consumo no puede cambiarlo');
    ok('solo hace SELECT',
       stripos($cuerpo, 'INSERT') === false && stripos($cuerpo, 'UPDATE') === false
       && stripos($cuerpo, 'DELETE') === false);

} finally {
    //  Se suelta SIEMPRE, falle o no la prueba: por eso vive en el finally.
    $base->soltar($pdo);
    echo "\n  (base desechable soltada)\n";
}

// ══════════════════════════════════════════════════════════════
//  7 · LA BASE COMPARTIDA SIGUE COMO ESTABA
// ══════════════════════════════════════════════════════════════
echo "\n  — ninguna otra suite puede notar que esta corrió —\n";
$despues = (int)$pdo->query("SELECT COUNT(*) FROM crecer_img_cuota_asiento")->fetchColumn();
ok('no se escribió ni un asiento en la base compartida', $despues === $antes_compartida,
   "antes {$antes_compartida}, después {$despues}");
$vivas = $pdo->query("SHOW DATABASES LIKE '" . EsquemaDesechable::PREFIJO . "%'")->fetchAll(PDO::FETCH_COLUMN);
ok('y la base desechable ya no existe', count($vivas) === 0, implode(', ', $vivas));

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0 ? "  CUOTA ATRIBUIDA ENTERA · {$n} afirmaciones\n\n"
                   : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
