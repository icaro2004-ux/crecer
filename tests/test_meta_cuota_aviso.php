<?php
// ============================================================
//  CRECER — EL AVISO DE CUOTA DICE LA VERDAD O SE CALLA
//  tests/test_meta_cuota_aviso.php
//
//  LO QUE ESTA PRUEBA DESTAPO. `semana_aviso_cuota()` preguntaba por el asiento
//  con `CuotaImg::asientoDePieza()`, que es la funcion del SONDEO: busca el
//  asiento VIVO para poder cerrarlo, filtrando `estado IN ('reservado','riesgo')`.
//  Despues comprobaba `estado === 'confirmado'` — es decir, exigia justo lo que
//  la consulta anterior habia excluido. Devolvia false SIEMPRE. El aviso era
//  codigo muerto y nadie lo habia notado porque nadie habia intentado el caso
//  positivo.
//
//  Y SE PUEDE PROBAR SIN PROVEEDOR. Un asiento confirmado es una FILA; no hace
//  falta generar ninguna imagen ni llamar a nadie para escribirla. Lo que hacia
//  falta era una base donde escribirla sin ensuciar la compartida.
//
//  BASE DESECHABLE. Todo corre sobre `crecer_prueba_<pid>_<azar>`, clonada de
//  la estructura real y soltada al terminar. La base compartida no se toca: ni
//  una fila, ni un DDL. Si el usuario de base de datos no puede crear bases, la
//  prueba se SALTA diciendolo — saltarsela es honesto; ensuciar la compartida
//  para no saltarsela, no.
//
//  CERO RED. Aqui no se llama a OpenAI, a Gemini ni a nadie: se comprueba
//  ademas contando las funciones de red que el proceso podria haber usado.
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

echo "\nEL AVISO DE CUOTA · CONFIRMADO DE VERDAD\n" . str_repeat('=', 58) . "\n";

$base = EsquemaDesechable::crear($pdo, ['crecer_img_cuota_asiento', 'crecer_img_cuota_cubo',
                                        'crecer_contenido', 'crecer_marca', 'usuarios']);
if (!$base) {
    echo "\n  SALTADO · este usuario de base de datos no puede crear bases\n\n";
    exit(0);
}

//  El contador de la base compartida ANTES: al final tiene que ser el mismo.
//  Es la prueba de que esta suite no contamina a las demas.
$antes_compartida = (int)$pdo->query("SELECT COUNT(*) FROM crecer_img_cuota_asiento")->fetchColumn();

$db = $base->pdo();

/** Escribe un asiento a mano. Es una FILA: ninguna imagen se genera aquí. */
$asiento = function (array $c) use ($db) {
    $db->prepare(
        "INSERT INTO crecer_img_cuota_asiento
           (marca_id, cubo, idem, operacion, ruta, punto, exencion, unidades,
            estado, origen_tipo, origen_id, llamadas, costo_usd, overage, created_at)
         VALUES (?,?,?,?, 'prueba','prueba', ?, ?, ?, ?, ?, 0, 0, 0, NOW())")
      ->execute([
        $c['marca'], CuotaImg::cuboMes(),
        CuotaImg::idem((int)$c['marca'], $c['op'] ?? 'arte_post', 'contenido', (int)$c['pieza']),
        $c['op'] ?? 'arte_post',
        $c['exencion'] ?? '', $c['unidades'] ?? 1, $c['estado'], 'contenido', $c['pieza'],
      ]);
    return (int)$db->lastInsertId();
};

try {
    $MARCA = 4001;      // en la copia no hay FK: la marca es solo un número
    $OTRA  = 4002;

    // ══════════════════════════════════════════════════════════════
    //  1 · EL CASO POSITIVO — el que nunca se habia probado
    // ══════════════════════════════════════════════════════════════
    echo "\n  — la imagen se entregó: se dice, y se dice bien —\n";
    $P_OK = 9101;
    $asiento(['marca' => $MARCA, 'pieza' => $P_OK, 'estado' => 'confirmado', 'unidades' => 1]);

    ok('un asiento confirmado SÍ produce el aviso',
       semana_aviso_cuota($db, $MARCA, $P_OK) === true,
       'con asientoDePieza() esto devolvía false siempre: la consulta del sondeo '
       . "excluye 'confirmado', que es justo lo que aquí se exige");

    ok('y la frase dice que NO se devuelve la unidad',
       semana_frase_cuota(true) === 'Esta imagen ya cuenta en tu cuota del mes aunque quites la publicación.',
       semana_frase_cuota(true));
    ok('la frase del caso positivo no promete recuperarla',
       stripos(semana_frase_cuota(true), 'no gasta') === false
       && stripos(semana_frase_cuota(true), 'devuel') === false,
       semana_frase_cuota(true));

    ok('sin evidencia NO se habla de gastar ni de devolver',
       stripos(semana_frase_cuota(false), 'gasta') === false
       && stripos(semana_frase_cuota(false), 'devuel') === false
       && stripos(semana_frase_cuota(false), 'cuota') === false,
       semana_frase_cuota(false));
    ok('y lo que dice es estrictamente cierto',
       semana_frase_cuota(false) === 'Sustituirla no genera otra imagen hasta preparar la alternativa.',
       semana_frase_cuota(false));

    //  UNA PIEZA, UN ASIENTO. La tabla lleva UNIQUE(marca_id, idem), así que un
    //  reintento no crea otra fila: reusa la que hay. Esto no es un detalle de
    //  esquema — es lo que hace que «¿está confirmado?» sea una pregunta con
    //  UNA respuesta y no un desempate entre varias. Se afirma aquí porque el
    //  día que ese índice desaparezca, la lectura deja de ser inequívoca.
    $idx = $db->query("SHOW INDEX FROM crecer_img_cuota_asiento")->fetchAll(PDO::FETCH_ASSOC);
    $uq = array_filter($idx, fn($r) => $r['Key_name'] === 'uq_asiento_idem' && (int)$r['Non_unique'] === 0);
    ok('una pieza no puede tener dos asientos', count($uq) === 2,
       'UNIQUE(marca_id, idem) es lo que hace inequívoca la lectura del consumo');

    $choque = false;
    try { $asiento(['marca' => $MARCA, 'pieza' => $P_OK, 'estado' => 'liberado', 'unidades' => 1]); }
    catch (Throwable $e) { $choque = strpos($e->getMessage(), '1062') !== false; }
    ok('y la base lo impide de verdad, no de palabra', $choque === true);

    //  EL CAMINO REAL de un reintento: la MISMA fila cambia de estado. Mientras
    //  esté liberada se calla; en cuanto se confirma, se dice.
    $P_RE = 9102;
    $asiento(['marca' => $MARCA, 'pieza' => $P_RE, 'estado' => 'liberado', 'unidades' => 1]);
    ok('reintento todavía liberado: calla', semana_aviso_cuota($db, $MARCA, $P_RE) === false);
    $db->prepare("UPDATE crecer_img_cuota_asiento SET estado='confirmado'
                   WHERE marca_id=? AND idem=?")
       ->execute([$MARCA, CuotaImg::idem($MARCA, 'arte_post', 'contenido', $P_RE)]);
    ok('y en cuanto se confirma: lo dice', semana_aviso_cuota($db, $MARCA, $P_RE) === true,
       'la respuesta sigue al estado de la fila, sin memoria propia');

    // ══════════════════════════════════════════════════════════════
    //  2 · LOS CONTROLES NEGATIVOS — cada uno tiene que CALLAR
    // ══════════════════════════════════════════════════════════════
    echo "\n  — sin evidencia, silencio: el aviso no se deduce, se demuestra —\n";

    $P_RES = 9201; $asiento(['marca'=>$MARCA,'pieza'=>$P_RES,'estado'=>'reservado','unidades'=>1]);
    ok('reservado calla',  semana_aviso_cuota($db, $MARCA, $P_RES) === false,
       'reservar aparta la unidad; no la gasta');

    $P_LIB = 9202; $asiento(['marca'=>$MARCA,'pieza'=>$P_LIB,'estado'=>'liberado','unidades'=>1]);
    ok('liberado calla',   semana_aviso_cuota($db, $MARCA, $P_LIB) === false);

    $P_RIE = 9203; $asiento(['marca'=>$MARCA,'pieza'=>$P_RIE,'estado'=>'riesgo','unidades'=>1]);
    ok('en riesgo calla',  semana_aviso_cuota($db, $MARCA, $P_RIE) === false,
       'en riesgo todavía no se sabe si se entregó');

    $P_CERO = 9204; $asiento(['marca'=>$MARCA,'pieza'=>$P_CERO,'estado'=>'confirmado','unidades'=>0]);
    ok('confirmado con cero unidades calla',
       semana_aviso_cuota($db, $MARCA, $P_CERO) === false,
       'cero unidades es exactamente cero imágenes');

    $P_EX = 9205;
    $asiento(['marca'=>$MARCA,'pieza'=>$P_EX,'estado'=>'confirmado','unidades'=>1,'exencion'=>'admin']);
    ok('confirmado con exención calla',
       semana_aviso_cuota($db, $MARCA, $P_EX) === false,
       'un exento no consumió del mes del dueño');

    $P_NADA = 9206;
    ok('una pieza sin asiento calla', semana_aviso_cuota($db, $MARCA, $P_NADA) === false);

    //  MATERIAL PROPIO SIN GENERACIÓN: el dueño subió su foto, así que la pieza
    //  tiene arte pero nunca hubo reserva. No debe hablarse de cuota.
    $P_MIO = 9207;
    $db->prepare("INSERT INTO crecer_contenido (id, marca_id, plataforma, tipo, caption, estado, grafica_path)
                  VALUES (?,?, 'instagram','post','Relleno con foto del dueño.','borrador','/subidas/mia.jpg')")
       ->execute([$P_MIO, $MARCA]);
    ok('material propio sin generación calla',
       semana_aviso_cuota($db, $MARCA, $P_MIO) === false,
       'tener imagen no es haberla generado');

    echo "\n  — el consumo de un negocio no se lee desde otro —\n";
    $P_AJENA = 9301;
    $asiento(['marca'=>$OTRA,'pieza'=>$P_AJENA,'estado'=>'confirmado','unidades'=>1]);
    ok('el asiento de OTRA marca no se ve desde esta',
       semana_aviso_cuota($db, $MARCA, $P_AJENA) === false);
    ok('pero sí desde la suya',
       semana_aviso_cuota($db, $OTRA, $P_AJENA) === true,
       'si esto fallara, el control anterior pasaría por el motivo equivocado');

    ok('el asiento de OTRA pieza no se ve desde esta',
       semana_aviso_cuota($db, $MARCA, $P_OK + 500) === false,
       'la llave lleva el origen dentro: una pieza no hereda el gasto de otra');

    echo "\n  — parámetros imposibles no revientan ni afirman —\n";
    ok('marca 0 calla',   semana_aviso_cuota($db, 0, $P_OK) === false);
    ok('pieza 0 calla',   semana_aviso_cuota($db, $MARCA, 0) === false);
    ok('negativos callan', semana_aviso_cuota($db, -1, -1) === false);

    // ══════════════════════════════════════════════════════════════
    //  3 · LA CAPA 2 LO PINTA SOLO EN ESE CASO
    // ══════════════════════════════════════════════════════════════
    echo "\n  — la vista no deduce la cuota: la recibe ya decidida —\n";
    $vista = (string)file_get_contents(__DIR__ . '/../panel/_meta_semana.php');
    ok('la Capa 2 pinta la frase desde el atributo del servidor',
       strpos($vista, 'data-cuota-tx') !== false
       && strpos($vista, "if (el.dataset.cuotaTx)") !== false,
       'si la vista decidiera por su cuenta habría dos verdades');
    ok('y ese atributo sale de semana_frase_cuota()',
       strpos($vista, 'semana_frase_cuota(true)') !== false);
    ok('la vista NO escribe la frase a mano',
       strpos($vista, 'ya cuenta en tu cuota') === false,
       'el texto vive en el dominio, junto a la regla que lo autoriza');

    $puerta = (string)file_get_contents(__DIR__ . '/../panel/_meta_sustituir.php');
    ok('la puerta de sustituir usa la misma frase',
       strpos($puerta, 'semana_frase_cuota($su_cuota)') !== false);
    //  NINGUNA frase del wizard puede negar el consumo en seco. Se buscan las
    //  dos que había —la de la puerta y la del repaso— y cualquier variante:
    //  «no gasta imágenes» junto a una sustitución se lee como «me la devuelven».
    ok('ninguna pantalla de sustituir niega el consumo en seco',
       stripos($puerta, 'no gasta imágenes') === false
       && stripos($puerta, 'no gasta imagenes') === false,
       'quedó una frase que se lee como «me devuelven la unidad»');
    ok('el repaso también saca su frase del dominio',
       strpos($puerta, 'semana_frase_cuota($su_quitar && $su_cuota)') !== false,
       'el paso 3 decía «Cambiarla no gasta imágenes», falso al quitar una ya entregada');
    ok('lo que sí se dice de la alternativa es hacia delante',
       strpos($puerta, 'No usa imágenes de tu cuota') !== false,
       'esa afirmación es sobre lo que costará producir la nueva, y es cierta');

    // ══════════════════════════════════════════════════════════════
    //  4 · NI UNA LLAMADA A NADIE
    // ══════════════════════════════════════════════════════════════
    echo "\n  — un asiento es una fila, no una imagen —\n";
    $dom = (string)file_get_contents(__DIR__ . '/../includes/meta_semana.php');
    //  Se mira la funcion, no el fichero entero: lo que no puede llamar a un
    //  proveedor es ella.
    $ini = strpos($dom, 'function semana_aviso_cuota');
    $fin = strpos($dom, 'function semana_frase_cuota');
    $cuerpo = substr($dom, $ini, max(0, $fin - $ini));
    foreach (['curl_', 'file_get_contents', 'fsockopen', 'stream_socket', 'openai', 'gemini', 'vertex'] as $mal) {
        ok("semana_aviso_cuota() no usa «{$mal}»", stripos($cuerpo, $mal) === false);
    }
    ok('y tampoco reserva ni confirma nada',
       stripos($cuerpo, 'CuotaImg::reservar') === false
       && stripos($cuerpo, 'CuotaImg::confirmar') === false,
       'leer el consumo no puede cambiarlo');

} finally {
    $base->soltar($pdo);
    echo "\n  (base desechable soltada)\n";
}

// ══════════════════════════════════════════════════════════════
//  5 · LA BASE COMPARTIDA SIGUE COMO ESTABA
// ══════════════════════════════════════════════════════════════
echo "\n  — ninguna otra suite puede notar que esta corrió —\n";
$despues = (int)$pdo->query("SELECT COUNT(*) FROM crecer_img_cuota_asiento")->fetchColumn();
ok('no se escribió ni un asiento en la base compartida', $despues === $antes_compartida,
   "antes {$antes_compartida}, después {$despues}");
$vivas = $pdo->query("SHOW DATABASES LIKE '" . EsquemaDesechable::PREFIJO . "%'")->fetchAll(PDO::FETCH_COLUMN);
ok('y la base desechable ya no existe', count($vivas) === 0, implode(', ', $vivas));

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0 ? "  AVISO HONESTO · {$n} afirmaciones\n\n"
                   : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
