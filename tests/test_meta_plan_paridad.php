<?php
// ============================================================
//  CRECER — PARIDAD DE LA VISTA DEL PLAN
//  tests/test_meta_plan_paridad.php
//
//  ESTA PRUEBA SE ESCRIBIÓ ANTES DE TOCAR NADA, Y A PROPÓSITO.
//
//  `vista=plan` no es una pantalla decorativa: es donde vive casi todo lo que
//  Tu Meta sabe hacer — producir una jugada, abrir la pieza exacta, marcar lo
//  que el dueño hizo fuera, la inversión, las reglas del negocio, replanificar,
//  evaluar un plan viejo, comparar y el historial.
//
//  Rediseñarla «simplificando» es la forma más fácil de perder capacidades sin
//  enterarse: se pliega un bloque, se cae un botón, y nadie lo nota hasta que
//  un dueño no puede hacer algo que antes hacía.
//
//  Así que primero se enumera lo que hay. Esta prueba pasó VERDE contra el
//  diseño viejo antes de empezar; si sigue verde después, el rediseño no se
//  llevó nada por delante. Si se pone roja, se llevó algo.
//
//  Cada afirmación mira el CONTRATO —a dónde va la acción, qué objeto abre—
//  y no el nombre de la clase, que es justo lo que el rediseño va a cambiar.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nPARIDAD DE LA VISTA DEL PLAN · nada se pierde al reordenar\n"
   . str_repeat('=', 60) . "\n";

$fx = Fixture::crear($pdo, 'paridad', true, 'admin');
$M  = (int)$fx['marca_id'];
$PLAN = (int)$fx['plan_id'];
$META = (int)$fx['meta_id'];

$sid  = 'par' . bin2hex(random_bytes(8));
$ruta = session_save_path() ?: sys_get_temp_dir();
file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
    'usuario_id|i:' . (int)$fx['usuario_id'] . ';');

/** Pide la página como la pediría el navegador, con la sesión puesta. */
/**  El href de un control, tal cual lo escribe el servidor. Sirve para SEGUIR
 *   una capacidad que se mudo de pantalla en vez de darla por perdida. */
$mt_href = function (string $html, string $id): string {
    if (!preg_match('~<a[^>]*id="' . preg_quote($id, '~') . '"[^>]*href="([^"]+)"~i', $html, $m)
     && !preg_match('~<a[^>]*href="([^"]+)"[^>]*id="' . preg_quote($id, '~') . '"~i', $html, $m)) return '';
    return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
};

$pedir = function (string $query) use ($sid): string {
    $ctx = stream_context_create(['http' => [
        'method' => 'GET', 'timeout' => 30,
        'header' => "Cookie: PHPSESSID={$sid}\r\n",
    ]]);
    $html = @file_get_contents('http://localhost/crecer/panel/meta.php?' . $query, false, $ctx);
    return is_string($html) ? $html : '';
};

/** Abre una URL absoluta del panel, para seguir un enlace hasta su destino. */
$seguir = function (string $url) use ($sid): string {
    if ($url === '') return '';
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 30,
        'header' => "Cookie: PHPSESSID={$sid}
"]]);
    $h = @file_get_contents('http://localhost' . $url, false, $ctx);
    return is_string($h) ? $h : '';
};

try {
    // ══════════════════════════════════════════════════════════
    //  UN PLAN DE SEIS JUGADAS EN ESTADOS MEZCLADOS
    //  Uno hecho, uno de regla, uno del dueño con inversión, uno con
    //  piezas a medias, uno sin empezar y uno esperando video. Es el
    //  plan que de verdad se encuentra alguien a mitad de mes.
    // ══════════════════════════════════════════════════════════
    $q = $pdo->prepare("SELECT id, orden FROM crecer_meta_tactica
                         WHERE marca_id=? AND plan_id=? ORDER BY orden, id");
    $q->execute([$M, $PLAN]);
    $tac = $q->fetchAll(PDO::FETCH_ASSOC);
    ok('la fixture trae al menos 6 jugadas', count($tac) >= 6,
       'trae ' . count($tac) . ' · sin plan de verdad esto no prueba nada');

    if (count($tac) >= 6) {
        $ids = array_column($tac, 'id');
        $up = function (int $id, array $campos) use ($pdo, $M) {
            $set = []; $val = [];
            foreach ($campos as $k => $v) { $set[] = "{$k}=?"; $val[] = $v; }
            $val[] = $id; $val[] = $M;
            $pdo->prepare("UPDATE crecer_meta_tactica SET " . implode(',', $set)
                        . " WHERE id=? AND marca_id=?")->execute($val);
        };
        $up((int)$ids[0], ['estado' => 'hecha',      'clase' => 'produccion', 'formato' => 'post', 'piezas_meta' => 1]);
        $up((int)$ids[1], ['estado' => 'pendiente',  'clase' => 'regla']);
        $up((int)$ids[2], ['estado' => 'pendiente',  'clase' => 'accion_dueno', 'inversion' => 15.00]);
        $up((int)$ids[3], ['estado' => 'pendiente',  'clase' => 'produccion', 'formato' => 'post', 'piezas_meta' => 2]);
        $up((int)$ids[4], ['estado' => 'pendiente',  'clase' => 'produccion', 'formato' => 'reel', 'piezas_meta' => 1]);
        $up((int)$ids[5], ['estado' => 'pendiente',  'clase' => 'produccion', 'formato' => 'carrusel', 'piezas_meta' => 1]);

        //  Piezas: una publicada en la hecha, una en borrador en la 4ª, y una
        //  de reel esperando video en la 5ª. Así hay puertas de los tres tipos.
        $pz = $pdo->prepare("SELECT id FROM crecer_contenido WHERE marca_id=? AND plan_id=? ORDER BY id");
        $pz->execute([$M, $PLAN]);
        $piezas = $pz->fetchAll(PDO::FETCH_COLUMN);
        //  LA FIXTURE SOLO TRAE DOS PIEZAS. El escenario que esta prueba dice
 //  montar necesita cuatro -un post publicado, un post en borrador, un reel
 //  esperando video y un carrusel-, asi que las pone ella. Depender de que
 //  la fixture traiga justo lo que hace falta es como se acaba comprobando
 //  menos de lo que se cree.
        $cal = (int)$pdo->query("SELECT calendario_id FROM crecer_contenido
                                    WHERE marca_id={$M} AND calendario_id IS NOT NULL LIMIT 1")->fetchColumn();
        $crear = function (int $tac, string $tipo, string $estado, ?string $mat, ?string $pub)
                 use ($pdo, $M, $PLAN, $META, $cal): int {
            $pdo->prepare("INSERT INTO crecer_contenido
                    (calendario_id, marca_id, plataforma, tipo, caption, fecha_programada,
                     estado, meta_id, tactica_id, plan_id, necesita_material, guion, publicado_at)
                 VALUES (?,?, 'instagram', ?, ?, DATE_ADD(NOW(), INTERVAL 2 DAY), ?,?,?,?,?,?,?)")
                ->execute([$cal ?: null, $M, $tipo, '[prueba] pieza de ' . $tipo, $estado,
                           $META, $tac, $PLAN, $mat, $mat ? 'Graba 3 clips.' : null, $pub]);
            return (int)$pdo->lastInsertId();
        };
        $crear((int)$ids[0], 'post',     'publicado', null,    date('Y-m-d H:i:s'));
        $crear((int)$ids[3], 'post',     'borrador',  null,    null);
        $crear((int)$ids[4], 'reel',     'borrador',  'video', null);
        $crear((int)$ids[5], 'carrusel', 'borrador',  null,    null);
    }

    //  La fixture no le pone diagnostico ni contexto a la meta, asi que esos
    //  dos bloques no se pintaban y la prueba no comprobaba nada de ellos.
    $pdo->prepare("UPDATE crecer_meta SET diagnostico=?, veredicto=?, contexto=?
                    WHERE id=? AND marca_id=?")
        ->execute(['[prueba] Tu fuerte son los encargos por WhatsApp.', 'alcanzable',
                   '[prueba] Trabajo sola y horneo de noche.', $META, $M]);

    $html = $pedir('marca=' . $M . '&vista=plan');
    ok('la vista del plan responde', trim($html) !== '' && strpos($html, '<html') !== false,
       'sin HTML no hay nada que comprobar · ¿está Apache arriba?');

    if (trim($html) === '') { throw new RuntimeException('sin html'); }

    // ══════════════════════════════════════════════════════════
    //  LAS DIEZ CAPACIDADES
    // ══════════════════════════════════════════════════════════

    echo "\n  — 1 · producir una jugada —\n";
    //  El control que le dice al corillo que se ponga. Se mira el gancho de
    //  datos (data-id) y que el guion sepa mandar 'ejecutar': el nombre de la
    //  clase puede cambiar, el contrato no.
    ok('hay un control para producir', preg_match('~data-id="\d+"~', $html) === 1);
    ok('y llega a la acción ejecutar', strpos($html, "accion:'ejecutar'") !== false,
       'sin eso el botón no arranca ningún trabajo');

    echo "\n  — 2 · abrir las piezas exactas —\n";
    ok('a la lista de piezas de la jugada',
       preg_match('~propuestas\.php\?marca=' . $M . '&(amp;)?jugada=\d+~', $html) === 1,
       'ese enlace es el que lleva a las piezas DE ESA jugada, no a todas');
    ok('al post concreto', strpos($html, 'aprobar2.php?marca=' . $M) !== false
                        || strpos($html, 'aprobar2.php?marca=' . $M . '&amp;') !== false);
    ok('al estudio del reel con su pieza',
       preg_match('~reels\.php\?marca=' . $M . '(&amp;|&)pieza=\d+~', $html) === 1
       || strpos($html, 'reels.php?marca=' . $M) !== false);
    ok('y al constructor del carrusel',
       strpos($html, 'carrusel.php?marca=' . $M) !== false
       || strpos($html, 'carrusel.php?') !== false,
       'si no hay carrusel en el plan esto puede no salir — la fixture pone uno');

    echo "\n  — 3 · marcar una acción del dueño —\n";
    ok('hay un control de «ya lo hice»',
       stripos($html, 'Ya lo hice') !== false);
    ok('y llega a la acción tactica/hecha',
       strpos($html, "accion:'tactica'") !== false && strpos($html, "estado:'hecha'") !== false,
       'solo esa confirmación cierra una jugada del dueño');

    echo "\n  — 4 · la inversión —\n";
    ok('se ve el monto de la jugada que lo lleva',
       strpos($html, '$15') !== false,
       'la jugada de inversión de la fixture son $15');

    echo "\n  — 5 · las reglas del negocio —\n";
    ok('la jugada de regla se distingue',
       stripos($html, 'Regla del negocio') !== false || stripos($html, 'Siempre') !== false);

    echo "\n  — 6 · replanificar —\n";
    //  EL CONTROL, no el texto. La primera version buscaba «plan nuevo» en el
    //  HTML y eso tambien casa con el aviso que el guion enseña al pulsar: al
    //  quitar el boton, la prueba siguio verde. Una afirmacion que se cumple
    //  con el eco de si misma no comprueba nada.
    ok('hay un control para empezar un plan nuevo',
       strpos($html, 'id="replan"') !== false,
       'el guion engancha por ese id: sin el boton, addEventListener revienta');
    //  LA CAPACIDAD, NO EL CAMINO. Esto pedia "accion:'replan'" en el guion de
    //  ESTA pagina, y era correcto mientras el boton disparaba el POST desde
    //  aqui. Ya no: abre su wizard, que es donde se ensena lo que se mueve
    //  antes de moverlo. Lo que no se puede perder es que EXISTA un camino y
    //  que ese camino de verdad rehaga el plan — asi que se sigue el enlace.
    $destinoReplan = $mt_href($html, 'replan');
    ok('y lleva a un sitio que rehace el plan',
       strpos($destinoReplan, 'vista=plan-nuevo') !== false
       && strpos($seguir($destinoReplan), "accion:'replan'") !== false,
       'destino=' . ($destinoReplan ?: '(ninguno)'));
    ok('hay un control para cambiar de meta',
       strpos($html, 'id="cerrar"') !== false,
       'mismo motivo: el id es lo que el guion busca');
    $destinoCerrar = $mt_href($html, 'cerrar');
    ok('y lleva a un sitio que cierra o cambia la meta',
       strpos($destinoCerrar, 'vista=cambiar') !== false
       && strpos($seguir($destinoCerrar), "accion:'cambiar'") !== false,
       'destino=' . ($destinoCerrar ?: '(ninguno)'));

    echo "\n  — 7 · evaluar un plan —\n";
    ok('llega a la acción evaluar', strpos($html, "accion:'evaluar'") !== false,
       'es lo que le pide al Analista mirar los números de un plan viejo');

    echo "\n  — 8 · comparar planes —\n";
    //  Con un solo plan la comparación no se pinta, y es correcto. Lo que se
    //  protege es que el CÓDIGO siga ahí para cuando haya dos.
    $fuente = file_get_contents(__DIR__ . '/../panel/meta.php');
    ok('el bloque de comparación sigue en el código',
       strpos($fuente, 'movió la meta') !== false,
       'la comparación solo se pinta con 2+ planes; si desaparece del fuente, se perdió');
    ok('y la nota de «sin dato todavía»',
       strpos($fuente, 'sin dato todavía') !== false,
       'esa raya distingue «no hay dato» de «cero», que no es lo mismo');

    echo "\n  — 9 · historial y aprendizaje —\n";
    ok('el bloque de historial sigue en el código',
       strpos($fuente, 'Evaluarlo ahora') !== false);
    ok('y el ancla de aprendizaje',
       strpos($fuente, 'id="aprendizaje"') !== false,
       'el estado L enlaza a meta.php?vista=plan#aprendizaje: si el ancla se va, ese enlace cae en el vacío');

    echo "\n  — 10 · volver a «Ahora» con la misma marca —\n";
    ok('hay vuelta a la capa 1',
       preg_match('~meta\.php\?marca=' . $M . '["\']~', $html) === 1
       || strpos($html, 'meta.php?marca=' . $M . '"') !== false,
       'volver tiene que conservar la marca, o el dueño acaba en otro negocio');

    // ══════════════════════════════════════════════════════════
    //  Y LO QUE EL PLAN NUNCA DEBE HACER
    // ══════════════════════════════════════════════════════════
    echo "
  — 11 · el diagnostico de la Estratega —
";
    //  Estaba en el codigo Y en la pantalla. Al reordenar puede quedarse en el
    //  codigo y desaparecer de la vista sin que nadie lo note.
    ok('el diagnostico se pinta', strpos($html, 'diag') !== false
       && strpos($html, 'Estratega') !== false,
       'plegado esta bien; desaparecido no');
    ok('y el contexto que dio el dueño', stripos($html, 'Lo que me contaste') !== false);

    echo "
  --- VUELTA: cada capa tiene su salida ---
";
    //  Una capa sin puerta de vuelta es una capa donde el dueño se queda
    //  encerrado con el boton del navegador. Tiene que haber una salida
    //  VISIBLE en la pantalla, y tiene que conservar la marca.
    ok('la vista del plan tiene un volver visible',
       stripos($html, 'Volver') !== false,
       'no basta con que el navegador pueda: tiene que verse');

    //  LO QUE SIGUE NO ES PARIDAD, ES EL CONTRATO NUEVO.
    //  La capa 1 no afirma ritmo con cobertura parcial; la capa 2 tampoco
    //  puede — es la misma meta, en la misma pantalla, a un toque. Estas
    //  dos salen ROJAS contra el diseño viejo, y ese es el trabajo.
    echo "\n  — el contrato de cobertura, el MISMO que en Ahora —\n";
    //  La capa 1 no afirma ritmo con cobertura parcial. La capa 2 tampoco
    //  puede: es la misma meta, en la misma pantalla, a un toque.
    ok('no dice «Vas en ritmo»', stripos($html, 'Vas en ritmo') === false,
       'la fixture tiene cobertura parcial: afirmar el ritmo es inventarlo');
    ok('no dice «Vas atrasado»', stripos($html, 'Vas atrasado') === false);
    ok('no pinta un porcentaje de la meta', strpos($html, '% logrado') === false,
       'un porcentaje afirma que se conoce el total, y no se conoce');

    // ══════════════════════════════════════════════════════════
    //  CONTROL POSITIVO · el contrato se OBEDECE, no se esquiva
    //
    //  Las dos afirmaciones de arriba se pueden aprobar haciendo trampa:
    //  borrando los textos. Esto lo impide. Con cobertura COMPLETA el
    //  compositor deja afirmar el progreso, y entonces la barra, el
    //  porcentaje y el ritmo TIENEN que aparecer. Si no aparecen, es que se
    //  borraron en vez de condicionarse.
    // ══════════════════════════════════════════════════════════
    echo "
  --- CONTROL POSITIVO: con cobertura completa SI se afirma ---
";
    require_once __DIR__ . '/../core/Meta/MetaSnapshotReader.php';
    require_once __DIR__ . '/../core/Meta/MetaStateComposer.php';
    $snap = MetaSnapshotReader::leer($pdo, $M);
    $est  = MetaStateComposer::componer($snap);
    ok('hoy la cobertura es parcial', !$est->puedeAfirmarProgreso(),
       'cobertura=' . $est->cobertura . ' · si ya fuera completa, las dos de
        arriba no estarian probando nada');

    //  Se compone a mano un estado con cobertura COMPLETA y se comprueba que
    //  la vista lo respeta. No se toca la base: se pregunta a la funcion que
    //  la vista usa, que es la misma.
    $completo = new MetaState($est->estado, $est->titulo, $est->instruccion, $est->accion,
                              $est->evidencia, $est->camino, 'completa', $est->razon);
    ok('con cobertura completa, el contrato SI deja afirmar',
       $completo->puedeAfirmarProgreso(),
       'si esto fuera falso, el contrato no distinguiria nada');

    //  Y la vista pregunta por ahi: el fuente tiene que consultarlo, no tener
    //  los textos borrados a mano.
    //  SOLO la vista del plan. La primera version se comio el archivo entero
    //  porque la cadena de busqueda se rompio al escribirla: strpos devolvia
    //  false, substr empezaba en 0, y las afirmaciones pasaban leyendo la
    //  capa 1. Un falso verde de manual.
    $corte = strpos($fuente, chr(36) . "vista === 'plan'");
    ok('se localiza la vista del plan en el fuente', $corte !== false,
       'sin esto, lo de abajo estaria leyendo todo el archivo');
    $vplan = $corte !== false ? substr($fuente, $corte) : '';
    ok('la vista del plan consulta puedeAfirmarProgreso()',
       strpos($vplan, 'puedeAfirmarProgreso()') !== false,
       'es el MISMO contrato que la capa 1, no una segunda interpretacion');
    ok('y conserva el texto del porcentaje para cuando toque',
       strpos($vplan, '% logrado') !== false,
       'si el texto no esta en el fuente, no se condiciono: se borro');
    ok('y el del ritmo',
       stripos($vplan, 'vas en ritmo') !== false && stripos($vplan, 'vas atrasado') !== false,
       'lo mismo: condicionados, no desaparecidos');

} finally {
    @unlink($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid);
    Fixture::limpiar($pdo, $M);
    echo "\n  (fixture limpiada)\n";
}

echo "\n" . str_repeat('=', 60) . "\n";
echo $fallos === 0
    ? "  PARIDAD COMPLETA · {$n} afirmaciones\n\n"
    : "  {$fallos} de {$n} · el plan perdió algo por el camino\n\n";
exit($fallos === 0 ? 0 : 1);
