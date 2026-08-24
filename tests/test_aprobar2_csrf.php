<?php
// ============================================================
//  CRECER — NINGUNA MUTACION SIN TOKEN
//  tests/test_aprobar2_csrf.php
//
//  EL AGUJERO. `aprobar2.php` concentra una veintena de acciones que ESCRIBEN
//  y solo dos comprobaban el token. Las demas aceptaban un POST venido de
//  cualquier sitio: bastaba con que el dueño tuviera su sesion abierta y
//  visitara una pagina ajena para que le aprobaran, le reescribieran o le
//  borraran un post. El recorrido semanal nuevo llama a tres de ellas, asi que
//  cerrarlo entra en su alcance.
//
//  COMO SE PRUEBA, Y POR QUE ASI. Con peticiones HTTP DE VERDAD contra el
//  servidor local, con la cookie de sesion puesta, y mirando la BASE antes y
//  despues. Buscar `csrf_ok()` en el fuente solo demuestra que alguien escribio
//  una linea; no demuestra que la linea corra antes de escribir, ni que la
//  respuesta siga sirviendo a quien pregunta, ni que las rutas viejas sigan
//  funcionando.
//
//  Retrato antes/despues en cada caso. Si una peticion sin token deja la fila
//  igual, es que no escribio — y eso es lo unico que cuenta.
//
//  LO QUE ESTA SUITE LE CUESTA AL PROVEEDOR, dicho exacto. Yo habia escrito
//  aqui que `editar` «no llama a nadie», y es FALSO: el handler, despues de
//  guardar, llama a aprender_de_edicion(), que es una peticion de verdad a
//  Gemini. Se ve en crecer_ia_log —«Aprender de edicion», gemini-2.5-flash—
//  con la marca de la fixture.
//
//  Y no se puede evitar desde aqui. El aprendizaje se activa con
//  `$pagado || rol admin || activacion_de_prueba()`, y en local
//  CRECER_DEV_ACTIVAR hace que activacion_de_prueba() sea true para CUALQUIER
//  cuenta: se probo bajando la fixture a rol proveedor y siguio llamando.
//
//  ASI QUE EL COSTO REAL DE ESTA SUITE ES: 2 llamadas de TEXTO a Gemini flash
//  (las dos ediciones que ejercita), CERO imagenes y CERO asientos de cuota.
//  Se comprueba al final contando crecer_ia_log antes y despues: si algun dia
//  sube de dos, es que una mutacion nueva empezo a llamar a alguien.
//
//  Generar arte si llama al proveedor de imagen: su token se comprueba por la
//  puerta comun -la misma linea que cubre a todas- y no ejercitandolo.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/meta_negocio.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nAPROBAR2 · NINGUNA MUTACION SIN TOKEN\n" . str_repeat('=', 58) . "\n";

$ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
if (@file_get_contents('http://localhost/crecer/login.php', false, $ctx) === false) {
    echo "\n  SALTADO · el servidor local no responde\n\n"; exit(0);
}

$fx   = Fixture::crear($pdo, 'csrf', true, 'admin');
$M    = (int)$fx['marca_id'];
$META = meta_activa($pdo, $M);
$PLAN = meta_plan_activo($pdo, (int)$META['id']);
$ARTE = '/crecer/assets/brand/crecer-icon.png';

//  Sesión de Apache escrita a mano (mismo save_path). El token de la sesión lo
//  fija esta prueba: así se puede mandar uno válido sin abrir un navegador.
$sid   = 'csrf' . bin2hex(random_bytes(8));
$TOKEN = bin2hex(random_bytes(16));
$ruta  = session_save_path() ?: sys_get_temp_dir();
file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
    'usuario_id|i:' . (int)$fx['usuario_id'] . ';csrf|s:' . strlen($TOKEN) . ':"' . $TOKEN . '";');

$URL = 'http://localhost/crecer/panel/aprobar2.php?marca=' . $M;

//  El precio, medido y no prometido. Al final se compara.
$ia_antes  = (int)$pdo->query("SELECT COUNT(*) FROM crecer_ia_log")->fetchColumn();
$img_antes = (int)$pdo->query("SELECT COUNT(*) FROM crecer_img_cuota_asiento")->fetchColumn();

/**
 * Un POST de verdad.
 *   $como = 'fetch'  → Accept comodín, como manda el navegador en un fetch()
 *   $como = 'form'   → Accept text/html, como al enviar un formulario
 * Esa cabecera es lo que distingue a quién hay que contestarle JSON y a quién
 * una pantalla.
 *
 * @return array{codigo:int, tipo:string, cuerpo:string}
 */
$post = function (array $campos, string $como = 'fetch') use ($sid, $URL) {
    $cuerpo  = http_build_query($campos);
    $accept  = $como === 'form'
        ? 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
        : '*/*';
    $c = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n"
                   . "Accept: {$accept}\r\n"
                   . "Cookie: PHPSESSID={$sid}\r\n",
        'content' => $cuerpo,
        'timeout' => 25,
        'ignore_errors'   => true,
        'follow_location' => 0,
    ]]);
    $res  = @file_get_contents($URL, false, $c);
    $cod  = 0; $tipo = '';
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $cod = (int)$m[1];
        if (stripos($h, 'Content-Type:') === 0)          $tipo = trim(substr($h, 13));
        if (stripos($h, 'Location:') === 0)              $tipo = $tipo ?: 'redirect:' . trim(substr($h, 9));
    }
    return ['codigo' => $cod, 'tipo' => $tipo, 'cuerpo' => (string)$res];
};

/** El retrato de una pieza. Lo unico que decide si algo escribio. */
$retrato = function (int $pid) use ($pdo) {
    $q = $pdo->prepare("SELECT estado, caption, fecha_programada FROM crecer_contenido WHERE id=?");
    $q->execute([$pid]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: ['ausente' => true];
};
/** Una pieza nueva, en borrador y con arte (para que 'aprobar' sea aprobar). */
$sembrar = function (string $cap) use ($pdo, $M, $META, $PLAN, $fx, $ARTE) {
    $pdo->prepare("INSERT INTO crecer_contenido
            (marca_id,plataforma,tipo,caption,estado,meta_id,plan_id,tactica_id,
             fecha_programada,grafica_path)
          VALUES (?, 'instagram','post',?, 'borrador',?,?,?, DATE_ADD(NOW(), INTERVAL 2 DAY), ?)")
      ->execute([$M, $cap, (int)$META['id'], (int)$PLAN['id'], (int)$fx['tacticas'][1], $ARTE]);
    return (int)$pdo->lastInsertId();
};

try {
    // ══════════════════════════════════════════════════════════════
    //  1 · CADA FAMILIA DE MUTACION, CON LOS TRES TOKENS
    // ══════════════════════════════════════════════════════════════
    $familias = [
        'aprobar'          => ['campos' => [],                                  'mira' => 'estado', 'espera' => 'aprobado'],
        'rechazar'         => ['campos' => [],                                  'mira' => 'estado', 'espera' => 'rechazado'],
        'reabrir'          => ['campos' => [],                                  'mira' => 'estado', 'espera' => 'borrador'],
        'marcar_publicado' => ['campos' => [],                                  'mira' => 'estado', 'espera' => 'publicado'],
        'editar'           => ['campos' => ['caption' => 'Texto de relleno reescrito.'],
                               'mira' => 'caption', 'espera' => 'Texto de relleno reescrito.'],
        'fecha'            => ['campos' => ['fecha' => date('Y-m-d H:i:s', strtotime('+9 day'))],
                               'mira' => 'fecha_programada', 'espera' => null],
    ];

    foreach ($familias as $accion => $cfg) {
        echo "\n  — {$accion} —\n";

        //  SIN TOKEN
        $pid = $sembrar("Relleno {$accion} sin token.");
        $a = $retrato($pid);
        $r = $post(['accion' => $accion, 'id' => $pid, 'ajax' => '1'] + $cfg['campos']);
        $d = $retrato($pid);
        ok("{$accion} sin token NO escribe", $a == $d,
           json_encode($a, JSON_UNESCAPED_UNICODE) . ' → ' . json_encode($d, JSON_UNESCAPED_UNICODE));
        ok("{$accion} sin token responde JSON de rechazo",
           stripos($r['tipo'], 'json') !== false && strpos($r['cuerpo'], '"ok":false') !== false,
           $r['codigo'] . ' ' . $r['tipo'] . ' ' . substr($r['cuerpo'], 0, 90));

        //  TOKEN INVALIDO
        $r = $post(['accion' => $accion, 'id' => $pid, 'ajax' => '1',
                    'csrf' => str_repeat('0', 32)] + $cfg['campos']);
        $d = $retrato($pid);
        ok("{$accion} con token inválido NO escribe", $a == $d,
           json_encode($d, JSON_UNESCAPED_UNICODE));

        //  TOKEN VALIDO
        $r = $post(['accion' => $accion, 'id' => $pid, 'ajax' => '1',
                    'csrf' => $TOKEN] + $cfg['campos']);
        $d = $retrato($pid);
        $cambio = $cfg['espera'] === null
            ? ($d[$cfg['mira']] !== $a[$cfg['mira']])
            : ((string)$d[$cfg['mira']] === (string)$cfg['espera']);
        ok("{$accion} con token válido SÍ escribe", $cambio,
           json_encode($a, JSON_UNESCAPED_UNICODE) . ' → ' . json_encode($d, JSON_UNESCAPED_UNICODE));
        ok("{$accion} con token válido sigue contestando JSON",
           stripos($r['tipo'], 'json') !== false && strpos($r['cuerpo'], '"ok":true') !== false,
           $r['codigo'] . ' ' . $r['tipo'] . ' ' . substr($r['cuerpo'], 0, 90));
    }

    // ══════════════════════════════════════════════════════════════
    //  2 · BORRAR — la mutación que no se deshace
    // ══════════════════════════════════════════════════════════════
    echo "\n  — borrar —\n";
    $pid = $sembrar('Relleno para borrar.');
    $post(['accion' => 'borrar', 'id' => $pid, 'ajax' => '1']);
    ok('borrar sin token NO borra', empty($retrato($pid)['ausente']));
    $post(['accion' => 'borrar', 'id' => $pid, 'ajax' => '1', 'csrf' => str_repeat('a', 32)]);
    ok('borrar con token inválido NO borra', empty($retrato($pid)['ausente']));
    $post(['accion' => 'borrar', 'id' => $pid, 'ajax' => '1', 'csrf' => $TOKEN]);
    ok('borrar con token válido SÍ borra', !empty($retrato($pid)['ausente']));

    // ══════════════════════════════════════════════════════════════
    //  3 · LA MARCA AJENA SIGUE SIENDO AJENA
    //      Un token válido es de la SESIÓN, no un permiso sobre todo.
    // ══════════════════════════════════════════════════════════════
    echo "\n  — el token no abre la puerta de otro negocio —\n";
    $fx2 = Fixture::crear($pdo, 'csrf-ajena', true, 'proveedor');
    $M2  = (int)$fx2['marca_id'];
    $ajena = (int)$fx2['piezas'][0];
    $a = $retrato($ajena);
    $post(['accion' => 'aprobar', 'id' => $ajena, 'ajax' => '1', 'csrf' => $TOKEN]);
    ok('con token válido, la pieza de OTRA marca no se toca', $a == $retrato($ajena),
       json_encode($retrato($ajena), JSON_UNESCAPED_UNICODE));
    $post(['accion' => 'editar', 'id' => $ajena, 'ajax' => '1', 'csrf' => $TOKEN,
           'caption' => 'No debería poder escribir esto.']);
    ok('ni se le reescribe el texto', $a == $retrato($ajena));

    // ══════════════════════════════════════════════════════════════
    //  4 · EL DOBLE ENVÍO NO DUPLICA NADA
    // ══════════════════════════════════════════════════════════════
    echo "\n  — el segundo clic no cuenta dos veces —\n";
    $pid = $sembrar('Relleno para doble envío.');
    $cuantas = fn() => (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$M}")->fetchColumn();
    $antes_filas = $cuantas();
    $post(['accion' => 'aprobar', 'id' => $pid, 'ajax' => '1', 'csrf' => $TOKEN]);
    $post(['accion' => 'aprobar', 'id' => $pid, 'ajax' => '1', 'csrf' => $TOKEN]);
    ok('aprobar dos veces deja el mismo estado',
       (string)$retrato($pid)['estado'] === 'aprobado');
    ok('y no crea filas de más', $cuantas() === $antes_filas,
       $antes_filas . ' → ' . $cuantas());

    // ══════════════════════════════════════════════════════════════
    //  5 · EL FORMULARIO CLÁSICO RECIBE UNA PANTALLA, NO UN JSON
    // ══════════════════════════════════════════════════════════════
    echo "\n  — a un formulario se le contesta con la interfaz, no con un alert —\n";
    $pid = $sembrar('Relleno del formulario clásico.');
    $a = $retrato($pid);
    $r = $post(['accion' => 'aprobar', 'id' => $pid], 'form');   // sin ajax, Accept html
    ok('el formulario sin token NO escribe', $a == $retrato($pid));
    ok('y se le devuelve a la pantalla con el aviso',
       strpos($r['tipo'], 'sesion=vencida') !== false || $r['codigo'] === 302,
       $r['codigo'] . ' ' . $r['tipo']);

    //  Y el aviso se PINTA: la pantalla lo dice con palabras, sin alert().
    $c = stream_context_create(['http' => ['header' => "Cookie: PHPSESSID={$sid}\r\n", 'timeout' => 20]]);
    $html = (string)@file_get_contents($URL . '&tab=revisar&sesion=vencida', false, $c);
    ok('la pantalla enseña el aviso de sesión vencida',
       strpos($html, 'No pude guardar ese cambio') !== false);
    ok('y dice que no se cambió nada', strpos($html, 'No se cambió nada') !== false);
    ok('sin usar alert() para eso', strpos($html, "alert('La sesión") === false);

    //  El formulario CON token sigue funcionando: la ruta vieja no se rompió.
    $r = $post(['accion' => 'aprobar', 'id' => $pid, 'csrf' => $TOKEN], 'form');
    ok('el formulario CON token sí escribe',
       (string)$retrato($pid)['estado'] === 'aprobado',
       json_encode($retrato($pid), JSON_UNESCAPED_UNICODE));

    // ══════════════════════════════════════════════════════════════
    //  6 · EL AYUDANTE ESTÁ EN LAS DOS PÁGINAS QUE ALOJAN LLAMADORES
    // ══════════════════════════════════════════════════════════════
    echo "\n  — el token se pone solo donde viven los llamadores —\n";
    $html = (string)@file_get_contents($URL . '&tab=revisar', false, $c);
    ok('aprobar2 carga el ayudante del token',
       strpos($html, "o.body.append('csrf', TOKEN)") !== false,
       'sin él, sus ~30 llamadas propias llegarían sin token');
    ok('y también engancha los formularios',
       strpos($html, "i.name = 'csrf'") !== false);

    $est = (string)@file_get_contents('http://localhost/crecer/panel/propuestas.php?marca=' . $M, false, $c);
    ok('El Estudio también lo carga',
       strpos($est, "o.body.append('csrf', TOKEN)") !== false,
       'el wizard de crear se incrusta aquí y postea a aprobar2: sin esto se rompía');

    // ══════════════════════════════════════════════════════════════
    //  7 · NINGUNA ESCRITURA SE QUEDÓ FUERA DE LA PUERTA
    //      Contrato de fuente: la comprobación va ANTES del primer handler.
    // ══════════════════════════════════════════════════════════════
    echo "\n  — la regla es una, y está en la puerta —\n";
    $src = (string)file_get_contents(__DIR__ . '/../panel/aprobar2.php');
    $pos_guarda  = strpos($src, 'if (!csrf_ok()) {');
    $pos_primera = strpos($src, "if (\$accion === 'publicar_api')");
    ok('el candado está antes del primer handler',
       $pos_guarda !== false && $pos_primera !== false && $pos_guarda < $pos_primera,
       'una excepción por handler es una excepción que se olvida');
    ok('y dentro del bloque POST', strpos($src, "REQUEST_METHOD'] === 'POST'") < $pos_guarda);

    // ══════════════════════════════════════════════════════════════
    //  8 · LO QUE ESTA SUITE LE COSTO AL PROVEEDOR
    // ══════════════════════════════════════════════════════════════
    echo "
  — el precio, contado y no prometido —
";
    $ia_despues  = (int)$pdo->query("SELECT COUNT(*) FROM crecer_ia_log")->fetchColumn();
    $img_despues = (int)$pdo->query("SELECT COUNT(*) FROM crecer_img_cuota_asiento")->fetchColumn();
    $gasto = $ia_despues - $ia_antes;
    ok('no se generó ninguna imagen', $img_despues === $img_antes,
       ($img_despues - $img_antes) . ' asientos nuevos');
    ok('las llamadas de texto son las 2 esperadas', $gasto <= 2,
       $gasto . ' llamadas — si sube, una mutación nueva empezó a llamar a alguien');

} finally {
    Fixture::limpiar($pdo, $M);
    if (isset($M2)) Fixture::limpiar($pdo, $M2);
    @unlink($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid);
    echo "\n  (fixtures limpiadas)\n";
}

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0 ? "  TODA MUTACION PROTEGIDA · {$n} afirmaciones\n\n"
                   : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
