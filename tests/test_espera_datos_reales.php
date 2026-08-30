<?php
// ============================================================
//  CRECER — LO QUE LA ESPERA PUEDE ENSEÑAR, Y LO QUE NO
//  tests/test_espera_datos_reales.php
//
//  La pantalla de espera dejo de ser una silueta: ahora enseña el CAPTION REAL
//  en cuanto esta escrito y la ESCENA que se esta pintando. Eso es lo que
//  sostiene dos minutos y medio — y es tambien la primera vez que texto salido
//  de un modelo llega a los ojos del cliente antes de que nadie lo apruebe.
//
//  Asi que aqui se vigilan las cuatro cosas que pueden salir mal:
//
//   1. QUE SE ENSEÑE LO DE OTRO. Los campos salen de una fila acotada por
//      marca_id; se comprueba pidiendo la pieza de otro negocio.
//   2. QUE SE COLE UNA INSTRUCCION INTERNA. `visual` es salida de modelo, y un
//      modelo puede devolver la instruccion en vez de obedecerla. La regla es
//      DESCARTAR, no limpiar a medias.
//   3. QUE UN TEXTO LARGO DOMINE LA PANTALLA. El tope va en el SERVIDOR, no
//      solo en el CSS: un recorte de estilo esconde el exceso pero igual lo
//      manda por el cable.
//   4. QUE SE REVELE ANTES DE TIEMPO. El copy se enseña cuando ESTA ESCRITO
//      (ia_log_id), ni antes; y 'copy'/'img' —los que pasan al escenario de
//      venta— siguen saliendo solo juntos, que esa regla no se toco.
//
//  Sin red y sin proveedor: todo sale de columnas.
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/_fixture.php';
require_once __DIR__ . '/../includes/muestra.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nLO QUE LA ESPERA PUEDE ENSEÑAR\n" . str_repeat('=', 60) . "\n";

// ── 1 · EL SANEADOR DE LA DIRECCION VISUAL ──────────────────────────────────
echo "\n  — una escena si; una instruccion interna JAMAS —\n";
$escenas = [
    'Un bizcocho de guayaba recién cortado sobre madera clara, con luz de mañana.',
    'Un reloj de arena hecho de harina cayendo sobre una bandeja, dramático.',
    'Manos arrugadas y jóvenes partiendo el mismo bizcocho, blanco y negro cálido.',
];
foreach ($escenas as $i => $e) {
    ok('escena legitima ' . ($i + 1) . ' pasa entera', muestra_visual_limpia($e) === $e);
}

//  Cada fuga sale de mirar los prompts que producen este campo
//  (debate_creativo) y preguntarse que pasaria si el modelo los DEVOLVIERA.
$fugas = [
    'JSON pedido'   => 'Responde SOLO JSON: {"angulos":[]}',
    'estructura'    => '{"visual":"un bizcocho"}',
    'rol del agente'=> 'Eres EL PROVOCADOR de Crecer, un creativo guerrillero',
    'nombre interno'=> 'Segun LA ESTRATEGA el angulo ganador es escasez',
    'regla interna' => 'REGLA DE ORO: nunca inventes hechos del negocio',
    'clave sistema' => 'system: eres un director de arte publicitario',
    'modelo'        => 'Imagen generada con gpt-image-2, calidad alta',
    'campos del JSON'=> 'tactica: Escasez, gancho: solo 10 esta semana',
    'bloque de codigo'=> "```\nUn bizcocho\n```",
    'formato exacto'=> 'Devuelve JSON EXACTO con 3 angulos',
];
foreach ($fugas as $q => $txt) {
    ok("se descarta la fuga: {$q}", muestra_visual_limpia($txt) === '',
       'salio «' . mb_substr(muestra_visual_limpia($txt), 0, 40) . '»');
}
ok('vacio y nulo no revientan', muestra_visual_limpia('') === '' && muestra_visual_limpia(null) === '');
ok('los saltos de linea se aplanan',
   strpos(muestra_visual_limpia("Un bizcocho\nsobre madera"), "\n") === false);

echo "\n  — y nada puede dominar la pantalla —\n";
$larga = str_repeat('un bizcocho de guayaba sobre madera clara ', 12);
$v = muestra_visual_limpia($larga);
ok('la escena se corta en el tope del servidor', mb_strlen($v) <= MUESTRA_VISUAL_MAX + 1,
   mb_strlen($v) . ' > ' . MUESTRA_VISUAL_MAX);
ok('y se corta por palabra, no a media silaba', mb_substr($v, -1) === '…' && mb_substr($v, -2, 1) !== ' ');
$capL = str_repeat('Hoy hay bizcocho recien salido del horno. ', 20);
$c = muestra_copy_fragmento($capL);
ok('el caption se corta en el tope del servidor', mb_strlen($c) <= MUESTRA_COPY_MAX + 1,
   mb_strlen($c) . ' > ' . MUESTRA_COPY_MAX);
ok('un caption corto NO se toca',
   muestra_copy_fragmento('Hoy hay bizcocho 🍰') === 'Hoy hay bizcocho 🍰');
ok('los parrafos del caption se conservan',
   strpos(muestra_copy_fragmento("Uno\n\nDos"), "\n\n") !== false,
   'el caption los usa y se leen bien');

// ── 2 · CONTRA LA BASE: PERTENENCIA Y MOMENTO ───────────────────────────────
$A = $B = null;
try {
    $A = Fixture::crear($pdo, 'espA', false);
    $B = Fixture::crear($pdo, 'espB', false);
    $MA = (int)$A['marca_id']; $MB = (int)$B['marca_id'];

    $sembrar = function (int $marca, array $c) use ($pdo): int {
        $pdo->prepare("INSERT INTO crecer_contenido
                 (marca_id,tipo,plataforma,caption,ia_log_id,corillo_json,estado,
                  img_estado,img_job,img_job_at,grafica_path)
               VALUES (?,'post','instagram',?,?,?,'borrador',?,?,NOW(),?)")
            ->execute([$marca, $c['cap'], $c['log'], $c['cor'], $c['ie'], $c['job'], $c['gp']]);
        return (int)$pdo->lastInsertId();
    };

    echo "\n  — el copy se enseña cuando ESTA ESCRITO, ni antes —\n";
    //  EL «PENSANDO» DE VERDAD: ni copy escrito, ni arte encolado, ni escena.
    //  Es como se ve la fila en los primeros ~40 segundos.
    $p0 = $sembrar($MA, ['cap' => 'borrador a medias', 'log' => null, 'cor' => null,
                         'ie' => null, 'job' => null, 'gp' => null]);
    $e0 = muestra_estado($pdo, $MA, $p0);
    ok('sin copy escrito, pieza_copy es null', $e0['pieza_copy'] === null,
       'devolvio: ' . var_export($e0['pieza_copy'], true));
    ok('y sin corillo, pieza_visual va vacia', $e0['pieza_visual'] === '');
    ok('y sin imagen guardada, pieza_img es null', $e0['pieza_img'] === null);

    //  Y LA OTRA CARA, QUE TAMBIEN HAY QUE FIJAR. Si el arte ya se encolo, el
    //  copy EXISTIO — muestra_preparar se niega a encolar sin caption («SIN COPY
    //  NO HAY ARTE. NUNCA.»). Por eso la evidencia se rellena hacia atras y
    //  pieza_copy sale aunque ia_log_id se haya quedado en blanco. No es un
    //  escape: es la misma regla que ya gobierna las etapas, y conviene que
    //  quede escrita aqui para que nadie la «arregle» sin querer.
    $p0b = $sembrar($MA, ['cap' => 'Hoy hay bizcocho de guayaba', 'log' => null, 'cor' => null,
                          'ie' => 'queued', 'job' => 'resp_a0b', 'gp' => null]);
    $e0b = muestra_estado($pdo, $MA, $p0b);
    ok('con el arte ya encolado, el copy consta aunque falte ia_log_id',
       is_string($e0b['pieza_copy']) && strpos($e0b['pieza_copy'], 'bizcocho') !== false,
       'la evidencia es acumulativa hacia atras, a proposito');

    //  Con ia_log_id y corillo_json: los dos hechos ocurrieron.
    $cor = json_encode(['visual' => 'Un bizcocho de guayaba sobre madera clara, luz de mañana.',
                        'elegido' => 'Escasez: solo 10'], JSON_UNESCAPED_UNICODE);
    $p1 = $sembrar($MA, ['cap' => "Hoy hay bizcocho 🍰\n\nPasa antes de las 4.", 'log' => 1,
                         'cor' => $cor, 'ie' => 'queued', 'job' => 'resp_a1', 'gp' => null]);
    $e1 = muestra_estado($pdo, $MA, $p1);
    ok('con copy escrito, pieza_copy lo trae', is_string($e1['pieza_copy']) && strpos($e1['pieza_copy'], 'bizcocho') !== false);
    ok('y pieza_visual trae la escena', strpos($e1['pieza_visual'], 'madera clara') !== false);
    ok('pero la imagen todavia no', $e1['pieza_img'] === null);
    //  LA REGLA VIEJA NO SE TOCO: 'copy' e 'img' son los del escenario de venta
    //  y siguen saliendo solo juntos, cuando la pieza esta lista.
    ok('«copy» del revelado sigue null mientras no este listo', $e1['copy'] === null,
       'esa regla es la que impide pasar a venta un post sin imagen');
    ok('«img» del revelado sigue null tambien', $e1['img'] === null);

    echo "\n  — la imagen aparece en cuanto esta guardada —\n";
    $p2 = $sembrar($MA, ['cap' => 'Hoy hay bizcocho', 'log' => 2, 'cor' => $cor,
                         'ie' => 'queued', 'job' => 'resp_a2', 'gp' => '/crecer/uploads/x.png']);
    $e2 = muestra_estado($pdo, $MA, $p2);
    ok('con grafica guardada, pieza_img la trae', $e2['pieza_img'] === '/crecer/uploads/x.png');
    ok('aunque el job siga abierto y la pieza no este lista', $e2['listo'] === false,
       'ese hueco es justo el momento en que la tarjeta se completa delante del dueño');

    echo "\n  — y nunca la pieza de otro negocio —\n";
    $cB = json_encode(['visual' => 'ESCENA DEL OTRO NEGOCIO'], JSON_UNESCAPED_UNICODE);
    $pB = $sembrar($MB, ['cap' => 'CAPTION DEL OTRO NEGOCIO', 'log' => 9, 'cor' => $cB,
                         'ie' => 'queued', 'job' => 'resp_b1', 'gp' => null]);
    $cruz = muestra_estado($pdo, $MA, $pB);      // marca A pidiendo la pieza de B
    ok('pedir la pieza de otra marca no la devuelve',
       (string)($cruz['pieza_copy'] ?? '') !== 'CAPTION DEL OTRO NEGOCIO',
       'devolvio: ' . var_export($cruz['pieza_copy'], true));
    ok('ni su escena', strpos((string)$cruz['pieza_visual'], 'OTRO NEGOCIO') === false);

    echo "\n  — una escena sucia en la base NO llega a la pantalla —\n";
    $sucio = json_encode(['visual' => 'Responde SOLO JSON: {"angulos":[{"visual":"x"}]}'], JSON_UNESCAPED_UNICODE);
    $p3 = $sembrar($MA, ['cap' => 'Hoy hay bizcocho', 'log' => 3, 'cor' => $sucio,
                         'ie' => 'queued', 'job' => 'resp_a3', 'gp' => null]);
    $e3 = muestra_estado($pdo, $MA, $p3);
    ok('se descarta y la pantalla no enseña escena', $e3['pieza_visual'] === '',
       'salio «' . $e3['pieza_visual'] . '»');
    ok('pero el copy sigue llegando', is_string($e3['pieza_copy']) && $e3['pieza_copy'] !== '',
       'una escena mala no puede llevarse por delante el texto');

    echo "\n  — corillo_json roto no tumba nada —\n";
    $p4 = $sembrar($MA, ['cap' => 'Hoy hay bizcocho', 'log' => 4, 'cor' => '{esto no es json',
                         'ie' => 'queued', 'job' => 'resp_a4', 'gp' => null]);
    $e4 = muestra_estado($pdo, $MA, $p4);
    ok('JSON invalido deja la escena vacia, sin excepcion', $e4['pieza_visual'] === '');
    ok('y el resto del estado sigue en pie', $e4['pieza_copy'] !== null && isset($e4['pct']));

    echo "\n  — sin pieza, las claves existen igual —\n";
    $vacio = muestra_estado($pdo, (int)$B['marca_id'], 999999999);
    foreach (['pieza_copy', 'pieza_visual', 'pieza_img'] as $k) {
        ok("«{$k}» esta definida aunque no haya fila", array_key_exists($k, $vacio),
           'si faltara, el llamador revienta con indice indefinido');
    }
} catch (Throwable $e) {
    $fallos++; echo "\n  EXCEPCION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
} finally {
    foreach ([$A, $B] as $F) {
        if (!$F) continue;
        try {
            $pdo->prepare("DELETE FROM crecer_contenido WHERE marca_id=?")->execute([(int)$F['marca_id']]);
            Fixture::limpiar($pdo, (int)$F['marca_id']);
        } catch (Throwable $e) { echo "  (limpieza: " . $e->getMessage() . ")\n"; }
    }
}

echo "\n" . str_repeat('=', 60) . "\n";
echo $fallos ? "  {$fallos} FALLAS de {$n}\n\n" : "  TODO OK · {$n} pruebas\n\n";
exit($fallos ? 1 : 0);
