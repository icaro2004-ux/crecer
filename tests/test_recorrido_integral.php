<?php
// ============================================================
//  CRECER — EL RECORRIDO ENTERO, CON UNA SOLA MARCA
//  tests/test_recorrido_integral.php
//
//  Hay una prueba por pieza y todas pasan. Esto pregunta otra cosa: si las
//  piezas, puestas una detrás de otra, cuentan LA MISMA HISTORIA. Un negocio
//  nuevo nace, se pone una meta, revisa su semana, publica, mide, cierra y
//  abre la siguiente — y en cada parada se comprueba que Inicio, Tu Meta,
//  Semana y Calendario dicen lo mismo.
//
//  LAS SEIS PREGUNTAS. En cada momento, y cuando haya datos para contestarlas:
//    1 ¿qué está haciendo el corillo?      4 ¿qué se publicará y cuándo?
//    2 ¿qué ya terminó?                    5 ¿cómo va la Meta?
//    3 ¿qué espera por mí?                 6 ¿qué ocurrirá después?
//
//  Y LA CADENA DE CONSECUENCIA. Cada decisión —aprobar, cambiar la fecha,
//  usar material propio, sustituir, publicar, fallar, cerrar la semana, añadir
//  una oportunidad— tiene que dejar: consecuencia visible, estado del plan
//  coherente y próximo paso. Recargar no puede cambiar la historia.
//
//  CERO PROVEEDORES, Y AQUÍ HAY TRES BORDES. El modelo (centinela
//  `_SIN_CREDENCIALES`, que solo en localhost fuerza transporte `mock`), las
//  redes sociales (el runner declara su propia `meta_api()`) y el correo
//  (`crecer_enviar_email` doblada). Al final se cuenta lo gastado por ESTAS
//  marcas: tiene que ser cero, y la cuota de imágenes tiene que quedar igual
//  que estaba.
//
//  NO SE PRUEBA AQUÍ lo que ya tiene su prueba: el detalle de cada pantalla,
//  la aritmética del compositor, el candado de CSRF de cada endpoint. Aquí se
//  prueba la COSTURA.
// ============================================================

//  ESTE PRÓLOGO VA PRIMERO, Y NO ES DECORACIÓN. El centinela
//  `_SIN_CREDENCIALES` solo protege lo que entra por Apache en localhost: mira
//  el `HTTP_HOST`, y en CLI no hay ninguno. Este recorrido llama al ejecutor
//  del plan desde su propio proceso, y sin esta línea esas llamadas salían de
//  verdad: la primera versión gastó $0.14 en gemini-2.5-flash, -flash-image y
//  gemini-3-pro-image antes de que la afirmación del final lo cazara. Los dos
//  candados hacen falta: éste para el proceso, el centinela para Apache.
//
//  Y HACE FALTA UN TERCERO, que se descubrió el 2026-08-29 corriendo esto desde
//  un worktree paralelo: el recorrido dispara workers por auto-HTTP, y
//  `worker_url()` arma la URL con `/crecer/` FIJO. O sea que el worker no cae en
//  el árbol de esta prueba sino en el que Apache sirva — el de otra rama, sin
//  centinela — y allí las llamadas salen y se pagan. Medido: 0.002535 USD por
//  corrida. Sin llave de workers no se dispara ninguno (`worker_puede_disparar`
//  falla cerrado), que es exactamente lo que esta prueba quiere: aquí no se
//  ejercita el worker, se ejercita el recorrido.
define('CRECER_WORKER_KEY', '');
require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/meta_negocio.php';
require_once __DIR__ . '/../includes/meta_semana.php';
require_once __DIR__ . '/../includes/meta_ciclo.php';
require_once __DIR__ . '/../includes/meta_ejecutar.php';
require_once __DIR__ . '/../includes/contexto.php';
require_once __DIR__ . '/../includes/inicio.php';
require_once __DIR__ . '/../includes/ejecucion.php';
require_once __DIR__ . '/../includes/sala_oportunidad.php';
require_once __DIR__ . '/../core/Meta/MetaSnapshotReader.php';
require_once __DIR__ . '/../core/Meta/MetaStateComposer.php';
require_once __DIR__ . '/../core/Meta/MetaPresentador.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0; $notas = [];
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}
/** Detalle menor: no bloquea el recorrido, pero se anota para después. */
function nota(string $que): void { global $notas; $notas[] = $que; }

echo "\nEL RECORRIDO ENTERO\n" . str_repeat('=', 58) . "\n";

//  ── TODAS LAS PETICIONES, A ESTE ÁRBOL ──────────────────────────────────
//  Este recorrido pide páginas por HTTP, y las pedía a `/crecer/...` fijo. El
//  centinela, en cambio, se escribe en el árbol de la prueba. Desde un worktree
//  paralelo eso son DOS árboles distintos: las páginas cargaban sin centinela y
//  las llamadas al modelo salían de verdad. Medido: 0.0027 USD por corrida.
//  RAIZ_HTTP fija el prefijo correcto para todo el archivo.
define('RAIZ_HTTP', 'http://localhost/' . rawurlencode(basename(dirname(__DIR__))));
$ctx0 = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
if (@file_get_contents(RAIZ_HTTP . '/login.php', false, $ctx0) === false) {
    echo "\n  SALTADO · el servidor local no responde\n\n"; exit(0);
}
if (!ciclo_hay_libro($pdo, true) || !sala_op_hay_libro($pdo, true)) {
    echo "\n  SALTADO · faltan migraciones del ciclo semanal o de La Sala\n\n"; exit(0);
}

//  EL CENTINELA. Todo esto entra por Apache, donde `CRECER_TEST_MODE` no
//  existe: sin él, montar una meta llamaría a Gemini con la clave de verdad.
$CENT = __DIR__ . '/../includes/_SIN_CREDENCIALES';
file_put_contents($CENT, "recorrido integral · " . date('c') . "\n");
register_shutdown_function(function () use ($CENT) { @unlink($CENT); });

// ── HERRAMIENTAS DEL CLIENTE ────────────────────────────────────────────
function sesion(int $uid): string {
    $sid = 'rec' . bin2hex(random_bytes(7));
    file_put_contents((session_save_path() ?: sys_get_temp_dir())
                      . DIRECTORY_SEPARATOR . 'sess_' . $sid, 'usuario_id|i:' . $uid . ';');
    return $sid;
}
function csrf_de(string $sid): string {
    $r = (session_save_path() ?: sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'sess_' . $sid;
    return preg_match('~csrf\|s:\d+:"([0-9a-f]+)"~', (string)@file_get_contents($r), $m) ? $m[1] : '';
}
/** GET de una pantalla, como la pide un navegador. Devuelve el HTML. */
function ver(string $sid, string $pag, string $q = ''): string {
    $c = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 90,
        'header' => "Cookie: PHPSESSID={$sid}\r\n", 'ignore_errors' => true]]);
    return (string)@file_get_contents(RAIZ_HTTP . '/panel/' . $pag
                                      . ($q !== '' ? '?' . $q : ''), false, $c);
}
/** POST a una pantalla. Devuelve [json, crudo]. */
function post(string $sid, string $pag, string $q, array $campos): array {
    $c = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 120,
        'header' => "Cookie: PHPSESSID={$sid}\r\nContent-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query($campos), 'ignore_errors' => true]]);
    $raw = (string)@file_get_contents(RAIZ_HTTP . '/panel/' . $pag
                                      . ($q !== '' ? '?' . $q : ''), false, $c);
    return [json_decode($raw, true) ?: [], $raw];
}
/** Texto plano de una pantalla: es lo que el dueño lee. */
function texto(string $html): string {
    $s = preg_replace('~<script[^>]*>.*?</script>~si', ' ', $html);
    $s = preg_replace('~<style[^>]*>.*?</style>~si', ' ', (string)$s);
    return trim(preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags((string)$s), ENT_QUOTES, 'UTF-8')));
}
function avisos_php(string $html): string {
    return preg_match('~(Undefined variable|Warning:|Notice:|Fatal error|Deprecated:)[^<\n]{0,90}~', $html, $m)
         ? $m[0] : '';
}

$limpiar = [];
$gasto = null; $cuota_antes = null; $cuota_despues = null; $retrato0 = []; $retrato1 = [];
//  LAS TABLAS COMPARTIDAS CON ENCUÉNTRALO. Un recorrido que deja basura aquí
//  ensucia datos que no son suyos: se retratan antes y después.
$COMPARTIDAS = ['usuarios', 'pagos', 'fotos', 'reviews', 'municipios', 'categorias'];
$retrato = function () use ($pdo, $COMPARTIDAS): array {
    $r = [];
    foreach ($COMPARTIDAS as $t) {
        try { $r[$t] = (int)$pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn(); }
        catch (Throwable $e) { $r[$t] = -1; }
    }
    return $r;
};

try {
    $retrato0 = $retrato();

    // ══════════════════════════════════════════════════════════════
    //  EL NEGOCIO NACE
    // ══════════════════════════════════════════════════════════════
    echo "\n  — un negocio nuevo, sin meta y sin historia —\n";
    $fx = Fixture::crear($pdo, 'recorrido', false, 'admin');
    $limpiar[] = $M = (int)$fx['marca_id'];
    $UID = (int)$fx['usuario_id'];
    $SID = sesion($UID);
    //  LA CUOTA QUE LE IMPORTA AL DUEÑO es la CONSUMIDA, no las filas del
    //  libro: un asiento liberado —una generación que no llegó a existir— no
    //  le quita ninguna de sus 40 imágenes del mes.
    $consumida = fn() => (int)$pdo->query("SELECT COUNT(*) FROM crecer_img_cuota_asiento
                                            WHERE marca_id={$M} AND estado IN ('reservado','confirmado')")
                                  ->fetchColumn();
    $cuota_antes = $consumida();

    //  El Recibimiento tapa la pantalla en cuentas nuevas: se da por visto.
    foreach (['inicio','meta','semana','calendario','resultados','sala','crear','reels'] as $p) {
        try { $pdo->prepare("INSERT IGNORE INTO crecer_tour_visto (marca_id,clave,visto_at)
                              VALUES (?,?,NOW())")->execute([$M, $p]); } catch (Throwable $e) {}
    }
    //  Redes falsas: sin conexión el publicador ni mira la pieza.
    $pdo->prepare("INSERT INTO crecer_conexiones
            (marca_id, proveedor, estado, ig_user_id, fb_page_id, page_access_token)
          VALUES (?, 'meta', 'activa', '17000000009', '10000000009', 'token-de-prueba')")
        ->execute([$M]);

    //  BIBLIOTECA: una foto y un video suyos, en disco. El publicador comprueba
    //  que el archivo esté antes de llamar a la red.
    $dir = rtrim(defined('UPLOADS_PATH') ? UPLOADS_PATH : dirname(__DIR__) . '/uploads', '/\\')
         . DIRECTORY_SEPARATOR . 'marca_' . $M;
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $JPG = base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsL'
        . 'DBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/'
        . '2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIy'
        . 'MjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QA'
        . 'HwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUF'
        . 'BAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkK'
        . 'FhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1'
        . 'dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXG'
        . 'x8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/9oACAEBAAA/APn+'
        . 'iiigD//Z');
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'mia.jpg', $JPG);
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'mio.mp4', "\x00\x00\x00\x18ftypmp42");
    $pdo->prepare("INSERT INTO crecer_activos (marca_id,tipo,archivo,nombre,origen,estado)
                   VALUES (?,'imagen',?,'Mi foto del mostrador','subida','activo')")
        ->execute([$M, "marca_{$M}/mia.jpg"]);
    $FOTO = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO crecer_activos (marca_id,tipo,archivo,nombre,origen,estado)
                   VALUES (?,'video',?,'Mi video del proceso','subida','activo')")
        ->execute([$M, "marca_{$M}/mio.mp4"]);
    $VIDEO = (int)$pdo->lastInsertId();

    // ══════════════════════════════════════════════════════════════
    //  A · MI NEGOCIO (EL GENOMA)
    // ══════════════════════════════════════════════════════════════
    echo "\n  A · mi negocio\n";
    $g = ver($SID, 'genoma.php', 'marca=' . $M);
    ok('Mi negocio abre',              $g !== '' && stripos($g, '<html') !== false, substr($g, 0, 120));
    ok('sin avisos de PHP',            avisos_php($g) === '', avisos_php($g));
    ok('y es el negocio de esta marca', str_contains($g, Fixture::SELLO));

    //  Y DICE LA VERDAD SOBRE SUS REDES. Esta marca tiene Instagram y Facebook
    //  conectados —la conexión está sembrada arriba—, así que «Sin conectar
    //  todavía» aquí sería mentirle en la línea donde mira si le falta ese
    //  paso, que es la fricción número uno del producto.
    $g_t = texto($g);
    ok('y sabe que sus redes están conectadas',
       mb_stripos($g_t, 'Instagram') !== false || mb_stripos($g_t, 'Facebook') !== false,
       mb_substr($g_t, 0, 220));
    ok('no dice «sin conectar» teniéndolas',
       mb_stripos($g_t, 'Sin conectar') === false, mb_substr($g_t, 0, 220));

    //  AJUSTA SU VOZ. Es el dato del que cuelga todo lo demás: si se pierde al
    //  volver, el negocio deja de ser suyo.
    $VOZ = 'Cercano y con guasa, tuteo, cero corporativo. [recorrido]';
    $pdo->prepare("UPDATE crecer_marca SET voz=? WHERE id=?")->execute([$VOZ, $M]);
    $g2 = ver($SID, 'genoma.php', 'marca=' . $M);
    ok('el ajuste se conserva al volver', str_contains(texto($g2), 'cero corporativo'),
       mb_substr(texto($g2), 0, 160));
    //  Y LLEGA AL CEREBRO: si el contexto no lo ve, la Estratega escribe con
    //  una voz que el dueño ya cambió.
    $ctx = ctx_estrategico($pdo, $M);
    $ctx_txt = json_encode($ctx, JSON_UNESCAPED_UNICODE);
    ok('el contexto estratégico lo refleja', str_contains((string)$ctx_txt, 'cero corporativo'),
       'el ajuste de voz no llegó al contexto que leen los agentes');

    // ══════════════════════════════════════════════════════════════
    //  B · LA META Y EL PLAN
    // ══════════════════════════════════════════════════════════════
    echo "\n  B · la meta y el plan\n";
    //  SIN META, la portada no puede mentir: no hay progreso que enseñar.
    $home0 = ver($SID, 'index.php', 'marca=' . $M);
    ok('Inicio abre sin meta',         $home0 !== '' && avisos_php($home0) === '', avisos_php($home0));
    ok('y no inventa progreso',        !preg_match('~\d+% logrado~', texto($home0)),
       'sin meta no hay porcentaje que enseñar');

    //  Acuña el token pidiendo la pantalla.
    ver($SID, 'meta.php', 'marca=' . $M . '&vista=wizard');
    $TOK = csrf_de($SID);
    ok('hay sesión con csrf',          $TOK !== '');

    $limite = date('Y-m-d', strtotime('+28 days'));
    $campos = ['ajax' => 1, 'csrf' => $TOK, 'accion' => 'crear', 'objetivo' => 'pedidos',
               'titulo' => 'Subir los pedidos del combo', 'cantidad' => '25',
               'fecha_limite' => $limite, 'contexto' => 'Tengo fotos mías y un video corto.'];
    [$r1] = post($SID, 'meta.php', 'marca=' . $M, $campos);
    ok('la meta se crea',              !empty($r1['ok']) && !empty($r1['meta_id']), json_encode($r1));
    ok('y con ella el plan',           !empty($r1['plan_ok']), json_encode($r1));
    $META = (int)($r1['meta_id'] ?? 0);

    //  DOBLE CLIC. El dedo nervioso manda el mismo formulario dos veces.
    [$r2] = post($SID, 'meta.php', 'marca=' . $M, $campos);
    ok('el doble clic no crea otra',   (int)($r2['meta_id'] ?? 0) === $META, json_encode($r2));
    ok('una sola meta activa',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta WHERE marca_id={$M} AND estado='activa'")->fetchColumn() === 1);
    ok('y un solo plan',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_plan WHERE marca_id={$M}")->fetchColumn() === 1);
    $PLAN = (int)$pdo->query("SELECT id FROM crecer_meta_plan WHERE marca_id={$M} ORDER BY id DESC LIMIT 1")->fetchColumn();

    //  LA LLEGADA: qué busca, quién hace qué, qué pasa esta semana.
    $meta = meta_activa($pdo, $M);
    $plan = meta_plan_por_id($pdo, $PLAN, $M);
    $tm = ver($SID, 'meta.php', 'marca=' . $M);
    $tm_t = texto($tm);
    ok('Tu Meta abre',                 $tm !== '' && avisos_php($tm) === '', avisos_php($tm));
    ok('dice qué busca',               mb_stripos($tm_t, 'pedido') !== false);
    ok('y qué le toca a cada quien',
       mb_stripos($tm_t, 'corillo') !== false || mb_stripos($tm_t, 'lo hace') !== false);

    //  Y LA PREPARACIÓN AGUANTA UNA RECARGA: la historia no cambia por mirar
    //  otra vez.
    $tm_b = texto(ver($SID, 'meta.php', 'marca=' . $M));
    ok('recargar cuenta lo mismo',
       similar_text($tm_t, $tm_b) > (mb_strlen($tm_t) * 0.8),
       'la pantalla cambió de historia entre dos miradas seguidas');

    // ══════════════════════════════════════════════════════════════
    //  LAS SEIS PREGUNTAS · un lector, no seis
    // ══════════════════════════════════════════════════════════════
    //  Se preguntan aquí y se vuelven a preguntar al final: lo que importa no
    //  es que existan una vez, sino que sigan contestadas cuando el estado
    //  cambia.
    $seis = function (string $etapa) use ($pdo, $M, $SID, &$fallos, &$n) {
        $marca = $pdo->query("SELECT * FROM crecer_marca WHERE id={$M}")->fetch(PDO::FETCH_ASSOC) ?: [];
        //  EL MISMO LECTOR QUE LA PANTALLA, ENTERO. Armar el snapshot a mano
        //  —meta por un lado, plan por otro— es abrir una segunda
        //  interpretación: la primera versión de esto lo hizo y sacó la letra
        //  de fallback, que es justo lo que el compositor existe para evitar.
        $snap  = MetaSnapshotReader::leer($pdo, $M);
        $E     = MetaStateComposer::componer($snap);
        $plan  = $snap['plan'] ?? null;
        $ops   = ejec_operacion($pdo, $M, $plan ? (int)$plan['id'] : null);
        $etp   = ejec_etapa((string)$E->estado, $ops);
        $cal   = inicio_calendario($pdo, $M, 3);
        $act   = inicio_actividad($pdo, $M, $marca, 3);
        $pen   = inicio_pendientes($pdo, $M, '/crecer/panel');
        $prox  = ejec_proxima($pdo, $M);
        return [
            1 => trim((string)($etp['titulo'] ?? '')) !== '',                 // qué hace el corillo
            2 => is_array($act),                                              // qué ya terminó
            3 => is_array($pen),                                              // qué espera por mí
            4 => $cal['hay'] || $cal['estado'] !== CTX_DISPONIBLE,            // qué se publica y cuándo
            5 => (string)$E->estado !== '',                                            // cómo va la Meta
            //  «Qué ocurrirá después» está contestado si hay algo con fecha, o
            //  si la línea de etapas dice en qué punto va: sin nada programado
            //  todavía, saber que tras decidir viene programar ES la respuesta.
            //  Y tambien la contesta el TURNO. La etapa del fallo no es un
            //  paso de la linea —`idx` vale -1 a proposito— y sin embargo dice
            //  perfectamente lo que viene: «tu contenido sigue guardado, te
            //  toca a ti». Exigir un paso de la linea daba «sin respuesta» en
            //  el unico momento en que el dueño mas necesita saber que sigue.
            6 => $prox !== null || (int)($etp['idx'] ?? -1) >= 0
                 || trim((string)($etp['turno'] ?? '')) !== ''
                 || (string)$E->estado === 'A',
            'pendientes' => $pen, 'cal' => $cal, 'E' => $E, 'etapa' => $etp, 'prox' => $prox,
        ];
    };
    $S = $seis('tras confirmar');
    foreach ([1 => 'qué está haciendo el corillo', 2 => 'qué ya terminó', 3 => 'qué espera por mí',
              4 => 'qué se publicará y cuándo', 5 => 'cómo va la Meta',
              6 => 'qué ocurrirá después'] as $i => $q) {
        ok("las seis · {$q}",          (bool)$S[$i], 'sin respuesta tras confirmar la meta');
    }
    //  Y COMO MUCHO TRES MENSAJES OPERATIVOS: una lista larga no se lee.
    ok('como mucho tres pendientes',   count($S['pendientes']) <= 3, (string)count($S['pendientes']));

    // ══════════════════════════════════════════════════════════════
    //  C · LA SEMANA
    // ══════════════════════════════════════════════════════════════
    echo "\n  C · la semana\n";
    //  Las piezas de la primera semana las produce el ejecutor; aquí se
    //  ejecutan sus jugadas sin red (el centinela ya fuerza `mock`).
    $eje = plan_ejecutar_pendientes($pdo, $M, 3);
    $piezas = $pdo->query("SELECT * FROM crecer_contenido WHERE marca_id={$M} ORDER BY id")
                  ->fetchAll(PDO::FETCH_ASSOC);
    ok('el corillo dejó trabajo hecho', count($piezas) >= 1,
       'jugadas ejecutadas: ' . json_encode($eje));
    if (!$piezas) throw new RuntimeException('sin piezas no hay recorrido que seguir');

    $sem = semana_construir($pdo, $M, $meta, $plan, 1);
    ok('la semana se arma',            !empty($sem['items']), json_encode(array_keys($sem)));

    $sv = ver($SID, 'meta.php', 'marca=' . $M . '&vista=semana');
    $sv_t = texto($sv);
    ok('Revisar mi semana abre',       $sv !== '' && avisos_php($sv) === '', avisos_php($sv));
    //  UNA PUBLICACIÓN POR PANTALLA, con lo que hace falta para decidir.
    ok('una publicación a la vez',     substr_count($sv, 'sm-pieza') <= 1 || str_contains($sv, 'de '),
       'la semana enseña más de una pieza a la vez');

    $P1 = (int)$piezas[0]['id'];
    //  APROBAR · decisión → consecuencia → estado → próximo paso.
    ver($SID, 'aprobar2.php', 'marca=' . $M);
    $TOK2 = csrf_de($SID);
    [$ap] = post($SID, 'aprobar2.php', 'marca=' . $M,
                 ['ajax' => 1, 'csrf' => $TOK2, 'accion' => 'aprobar', 'id' => $P1]);
    ok('aprobar contesta',             !empty($ap['ok']), json_encode($ap));
    $est1 = (string)$pdo->query("SELECT estado FROM crecer_contenido WHERE id={$P1}")->fetchColumn();
    ok('y la pieza queda aprobada',    in_array($est1, ['aprobado', 'programado'], true), $est1);
    $f1 = (string)$pdo->query("SELECT fecha_programada FROM crecer_contenido WHERE id={$P1}")->fetchColumn();
    ok('con fecha y hora',             $f1 !== '' && $f1 !== null, (string)$f1);

    //  CAMBIAR LA FECHA · y volver a la misma posición.
    $NUEVA = date('Y-m-d 10:00:00', strtotime('+2 days'));
    [$ed] = post($SID, 'aprobar2.php', 'marca=' . $M,
                 ['ajax' => 1, 'csrf' => $TOK2, 'accion' => 'fecha', 'id' => $P1, 'fecha' => $NUEVA]);
    $f2 = (string)$pdo->query("SELECT fecha_programada FROM crecer_contenido WHERE id={$P1}")->fetchColumn();
    ok('la fecha nueva se guarda',     substr((string)$f2, 0, 16) === substr($NUEVA, 0, 16),
       'pedida ' . $NUEVA . ' · quedó ' . $f2 . ' · ' . json_encode($ed));

    //  MATERIAL PROPIO · se usa, se sabe de dónde salió, y NO gasta cuota.
    $cuota_m = $consumida();
    $pdo->prepare("UPDATE crecer_contenido SET grafica_path=?, material_activo_id=? WHERE id=?")
        ->execute(["marca_{$M}/mia.jpg", $FOTO, $P1]);
    ok('el material propio queda trazado',
       (int)$pdo->query("SELECT material_activo_id FROM crecer_contenido WHERE id={$P1}")->fetchColumn() === $FOTO);
    ok('y usar lo suyo no gasta cuota', $consumida() === $cuota_m,
       'antes ' . $cuota_m . ' · después ' . $consumida());

    // ══════════════════════════════════════════════════════════════
    //  D · EL CALENDARIO
    // ══════════════════════════════════════════════════════════════
    echo "\n  D · el calendario\n";
    $cal = ver($SID, 'calendario.php', 'marca=' . $M);
    ok('el Calendario abre',           $cal !== '' && avisos_php($cal) === '', avisos_php($cal));

    //  LA MISMA HORA EN LAS CUATRO PANTALLAS. Es la contradicción que más
    //  duele: el dueño ve una hora en Semana y otra en Inicio y no sabe cuál
    //  creerse.
    $hora_bd  = date('g:i A', strtotime((string)$f2));
    $ini_cal  = inicio_calendario($pdo, $M, 5);
    $en_ini   = '';
    foreach ($ini_cal['filas'] as $fl) if ((int)$fl['id'] === $P1) $en_ini = $fl['cuando'];
    ok('Inicio la anuncia',            $en_ini !== '', json_encode($ini_cal['filas']));
    ok('a la misma hora que la base',  $en_ini === '' || str_contains($en_ini, $hora_bd),
       'base ' . $hora_bd . ' · Inicio «' . $en_ini . '»');
    ok('y el Calendario también',      str_contains($cal, date('H:i', strtotime((string)$f2)))
                                    || str_contains(texto($cal), $hora_bd),
       'la hora de la pieza no aparece en el Calendario');

    //  EL ORIGEN. Tres casos y una sola verdad: si Inicio dice una cosa y el
    //  Calendario otra sobre la MISMA pieza, una de las dos miente.
    $fila_ini = null;
    foreach ($ini_cal['filas'] as $fl) if ((int)$fl['id'] === $P1) $fila_ini = $fl;
    $tac_p1 = (int)$pdo->query("SELECT COALESCE(tactica_id,0) FROM crecer_contenido WHERE id={$P1}")->fetchColumn();
    ok('la pieza del plan dice que es del plan',
       $tac_p1 === 0 || ($fila_ini && $fila_ini['origen'] === 'De tu Meta'),
       'táctica ' . $tac_p1 . ' · Inicio dice «' . ($fila_ini['origen'] ?? '?') . '»');
    ok('y el Calendario dice lo mismo',
       $tac_p1 === 0 || str_contains($cal, 'De tu Meta'),
       'el Calendario no la reconoce como del plan');

    //  UNA HORA QUE NADIE ESCOGIÓ NO SE ANUNCIA COMO ESCOGIDA. Una pieza con
    //  fecha pero sin hora vive a las 00:00, y el Estudio lo enseñaba como «La
    //  Estratega escogió la hora — el domingo, 12:00 AM»: una decisión que
    //  nadie tomó y una publicación a medianoche.
    $pdo->prepare("INSERT INTO crecer_contenido (marca_id,plataforma,tipo,caption,estado,fecha_programada,meta_id,plan_id)
                   VALUES (?,'instagram','post','[prueba] sin hora','aprobado',?,?,?)")
        ->execute([$M, date('Y-m-d 00:00:00', strtotime('+4 days')), $META, $PLAN]);
    $SIN_HORA = (int)$pdo->lastInsertId();
    $est_sh = texto(ver($SID, 'propuestas.php', 'marca=' . $M . '&id=' . $SIN_HORA));
    ok('sin hora no se inventa una hora',
       !str_contains($est_sh, '12:00 AM'), mb_substr($est_sh, 0, 200));
    //  Y NINGUNA ELECCIÓN ACREDITADA A MEDIANOCHE. La pantalla enseña varias
    //  piezas y las que SÍ tienen hora sí llevan su crédito —eso es correcto—,
    //  así que lo que se busca es la combinación imposible: alguien que
    //  «escogió» las 12:00 AM.
    ok('ni se acredita una elección que nadie hizo',
       !preg_match('~escogi(ó|o) la hora[^.]{0,40}12:00 AM~ui', $est_sh),
       mb_substr($est_sh, 0, 200));
    $pdo->prepare('DELETE FROM crecer_contenido WHERE id=?')->execute([$SIN_HORA]);

    //  RECHAZADO NO ES FUTURO. Una pieza descartada que sigue anunciándose es
    //  una promesa que no se va a cumplir.
    $pdo->prepare("INSERT INTO crecer_contenido (marca_id,plataforma,tipo,caption,estado,fecha_programada)
                   VALUES (?,'instagram','post','[prueba] descartada','rechazado',?)")
        ->execute([$M, date('Y-m-d 09:00:00', strtotime('+3 days'))]);
    $RECH = (int)$pdo->lastInsertId();
    $ini2 = inicio_calendario($pdo, $M, 10);
    $ids2 = array_column($ini2['filas'], 'id');
    ok('lo rechazado no se anuncia',   !in_array($RECH, $ids2, true), json_encode($ids2));

    // ══════════════════════════════════════════════════════════════
    //  E · LA PUBLICACIÓN
    // ══════════════════════════════════════════════════════════════
    echo "\n  E · la publicación\n";
    //  Le llega la hora y el cron la encuentra.
    $pdo->prepare("UPDATE crecer_contenido SET estado='aprobado', fecha_programada=DATE_SUB(NOW(), INTERVAL 2 MINUTE),
                       lock_token=NULL, lock_at=NULL, pub_intentos=0 WHERE id=?")->execute([$P1]);
    $notif0 = (int)$pdo->query("SELECT COUNT(*) FROM crecer_notificaciones WHERE marca_id={$M}")->fetchColumn();

    $correr = function (int $cid, string $guion) : array {
        //  EL PHP QUE CORRE ESTA PRUEBA, no el que haya en el PATH — que en esta
        //  maquina no hay ninguno. Con `php` a secas el subproceso moria con
        //  «'php' is not recognized», el publicador no publicaba, y eso tumbaba
        //  once afirmaciones en cadena: sin id remoto, sin notificacion, sin
        //  metricas y con Resultados vacio. Se veia como once defectos y era uno.
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/_publicar_runner.php') . ' ' . $cid . ' ' . $guion;
        $out = (string)shell_exec($cmd . ' 2>&1');
        $j = json_decode(trim(substr($out, strrpos($out, '{') !== false ? strrpos($out, '{') : 0)), true);
        return is_array($j) ? $j : ['_raw' => $out];
    };
    $pub = $correr($P1, 'ok');
    $est2 = (string)$pdo->query("SELECT estado FROM crecer_contenido WHERE id={$P1}")->fetchColumn();
    ok('la publicación sale',          $est2 === 'publicado', $est2 . ' · ' . json_encode($pub));
    $rem = (string)$pdo->query("SELECT COALESCE(external_id,'') FROM crecer_publicaciones
                                 WHERE contenido_id={$P1} AND estado='ok'
                                 ORDER BY id DESC LIMIT 1")->fetchColumn();
    ok('con su id remoto',             $rem !== '', 'sin id remoto no se puede volver a la publicación');
    $notif1 = (int)$pdo->query("SELECT COUNT(*) FROM crecer_notificaciones WHERE marca_id={$M}")->fetchColumn();
    ok('y una notificación',           $notif1 - $notif0 === 1, ($notif1 - $notif0) . ' notificaciones');

    //  SEGUNDA CORRIDA: el cron vuelve a pasar y no publica dos veces.
    $correr($P1, 'ok');
    ok('la segunda corrida no duplica',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_notificaciones WHERE marca_id={$M}")->fetchColumn() === $notif1,
       'una publicación anunciada dos veces es una publicación que salió dos veces, a ojos del dueño');

    //  Y LA NOTIFICACIÓN LLEVA A SU PIEZA, no a la de otro.
    $nl = (string)$pdo->query("SELECT link FROM crecer_notificaciones WHERE marca_id={$M}
                                ORDER BY id DESC LIMIT 1")->fetchColumn();
    ok('la notificación lleva a su marca', $nl === '' || str_contains($nl, 'marca=' . $M), $nl);

    // ── EL FALLO ────────────────────────────────────────────────────────
    $pdo->prepare("INSERT INTO crecer_contenido
            (marca_id,plataforma,tipo,caption,estado,fecha_programada,grafica_path,meta_id,plan_id)
          VALUES (?,'instagram','post','[prueba] la que falla','aprobado',
                  DATE_SUB(NOW(), INTERVAL 2 MINUTE), ?, ?, ?)")
        ->execute([$M, "marca_{$M}/mia.jpg", $META, $PLAN]);
    $P2 = (int)$pdo->lastInsertId();
    $correr($P2, 'credenciales');
    $f = $pdo->query("SELECT estado, caption, pub_error FROM crecer_contenido WHERE id={$P2}")->fetch(PDO::FETCH_ASSOC);
    ok('lo que falla no se pierde',    trim((string)$f['caption']) !== '', json_encode($f));
    ok('queda como fallido',           (string)$f['estado'] === 'fallido', (string)$f['estado']);
    ok('con su clase',                 preg_match('~^\[(credenciales|temporal|contenido|incierto|desconocido)\]~',
                                                  (string)$f['pub_error']) === 1, (string)$f['pub_error']);
    //  Y EL AVISO NO ENSEÑA LAS TRIPAS: un token vencido no se le cuenta al
    //  dueño con el texto de Meta.
    $pend = inicio_pendientes($pdo, $M, '/crecer/panel');
    $pend_txt = json_encode($pend, JSON_UNESCAPED_UNICODE);
    ok('el aviso no enseña las tripas',
       !str_contains((string)$pend_txt, 'access token') && !str_contains((string)$pend_txt, '#190')
       && !str_contains((string)$pend_txt, 'token-de-prueba'), (string)$pend_txt);
    ok('y lleva a la pieza',           str_contains((string)$pend_txt, 'marca=' . $M), (string)$pend_txt);

    // ══════════════════════════════════════════════════════════════
    //  F · LOS RESULTADOS
    // ══════════════════════════════════════════════════════════════
    echo "\n  F · los resultados\n";
    $res = ver($SID, 'resultados.php', 'marca=' . $M);
    $res_t = texto($res);
    ok('Resultados abre',              $res !== '' && avisos_php($res) === '', avisos_php($res));
    //  ANTES DE MÉTRICAS: se ve la publicación, y NI UNA afirmación de
    //  rendimiento. Decir «va bien» sin datos es la mentira más fácil.
    //  LA LISTA ES LARGA A PROPÓSITO. La primera versión buscaba «va bien» y
    //  «funcionó», y dejó pasar «tu alcance viene subiendo» — que es
    //  exactamente la misma mentira dicha de otra forma, y que estaba impresa
    //  DEBAJO de «todavía no traje los números». Lo cazó una captura, no una
    //  afirmación: cualquier verbo de tendencia sin datos es una afirmación de
    //  rendimiento.
    ok('sin métricas, sin veredicto',
       !preg_match('~funcion(ó|o) (bien|mal)|va(s)? (bien|en ritmo)|est(á|a) funcionando'
                 . '|viene subiendo|va(n)? subiendo|est(á|a) creciendo|mejor(ó|o) (mucho|bastante)'
                 . '|con constancia|buen (mes|alcance)~ui', $res_t),
       mb_substr($res_t, 0, 240));
    ok('y lo dice: todavía no hay con qué',
       preg_match('~todav[ií]a|a[úu]n no|sin (datos|se[ñn]al|n[uú]meros)|no puedo~ui', $res_t) === 1,
       mb_substr($res_t, 0, 200));

    //  ── Y AHORA CON MÉTRICAS DE VERDAD (falsas, pero completas) ──────────
    //  Se siembran para la pieza que SÍ salió: es la única de la que se puede
    //  hablar sin inventar.
    $pdo->prepare("INSERT INTO crecer_metricas
            (contenido_id, marca_id, plataforma, external_id, alcance, impresiones,
             me_gusta, comentarios, guardados, compartidos, interacciones, actualizado_at)
          VALUES (?,?, 'instagram', ?, 480, 620, 41, 6, 9, 3, 59, NOW())")
        ->execute([$P1, $M, $rem !== '' ? $rem : 'ext-prueba']);
    $rc = ctx_resultados($pdo, $M, $plan);
    ok('con números ya hay señal',     $rc['estado'] === CTX_DISPONIBLE, json_encode($rc));
    ok('y la cobertura se dice',       isset($rc['cobertura']), json_encode(array_keys($rc)));
    //  Y LAS TRES PANTALLAS CUENTAN LO MISMO. La señal de Inicio sale del mismo
    //  sitio que la de Resultados: si no, el dueño ve dos verdades.
    $sen = inicio_senal($pdo, $M, $plan);
    $res2 = texto(ver($SID, 'resultados.php', 'marca=' . $M));
    ok('Inicio también la tiene',      is_array($sen) && $sen !== [], json_encode($sen));
    ok('Resultados enseña el alcance', str_contains($res2, '480') || str_contains($res2, '620'),
       mb_substr($res2, 0, 220));
    //  Y LA PRÓXIMA ESTRATEGIA PUEDE LEERLA: sin esto, medir no sirve de nada.
    $ctx2 = ctx_estrategico($pdo, $M);
    ok('la Estratega puede leerla',
       (string)(($ctx2['resultados']['estado'] ?? '')) === CTX_DISPONIBLE,
       json_encode($ctx2['resultados'] ?? null, JSON_UNESCAPED_UNICODE));

    // ══════════════════════════════════════════════════════════════
    //  C bis · LO QUE EL DUEÑO NO PUEDE HACER
    // ══════════════════════════════════════════════════════════════
    echo "\n  C bis · sustituir y marcar\n";
    //  UNA JUGADA QUE NO PUEDE HACER. Sustituirla no puede borrar la historia
    //  —el dueño tiene que poder ver qué pasó— ni dejar trabajo futuro huérfano.
    $tac = $pdo->query("SELECT * FROM crecer_meta_tactica WHERE marca_id={$M}
                          AND estado IN ('pendiente','en_curso') ORDER BY id LIMIT 1")
               ->fetch(PDO::FETCH_ASSOC);
    if ($tac) {
        $T = (int)$tac['id'];
        ver($SID, 'meta.php', 'marca=' . $M);
        $TOK4 = csrf_de($SID);
        //  SON DOS PASOS Y EL ORDEN IMPORTA: primero se PIDE la alternativa
        //  —ahí es donde habla la Estratega, fuera de transacción— y solo
        //  cuando el dueño la ve se sustituye. Mandar «sustituir» a secas
        //  contesta «se perdió la alternativa», que es lo correcto.
        [$alt] = post($SID, 'meta.php', 'marca=' . $M,
                      ['ajax' => 1, 'csrf' => $TOK4, 'accion' => 'alternativa', 'jugada' => $T,
                       'motivo' => 'sin_video', 'nota' => 'No tengo con qué grabar.']);
        ok('la Estratega propone otra', !empty($alt['ok']), json_encode($alt));
        [$su] = post($SID, 'meta.php', 'marca=' . $M,
                     ['ajax' => 1, 'csrf' => $TOK4, 'accion' => 'sustituir', 'jugada' => $T,
                      'alt' => json_encode($alt['alt'] ?? $alt['jugada'] ?? [], JSON_UNESCAPED_UNICODE),
                      'motivo' => 'sin_video', 'nota' => 'No tengo con qué grabar.',
                      'token' => (string)($alt['token'] ?? '')]);
        $vieja = $pdo->query("SELECT estado, sustituida_at, sustituida_por_id
                                FROM crecer_meta_tactica WHERE id={$T}")->fetch(PDO::FETCH_ASSOC);
        ok('sustituir contesta',       !empty($su['ok']), json_encode($su));
        ok('la vieja no desaparece',   $vieja !== false, json_encode($vieja));
        ok('queda sellada como sustituida', !empty($vieja['sustituida_at']), json_encode($vieja));
        $nueva = (int)($vieja['sustituida_por_id'] ?? 0);
        ok('y hay una alternativa en su sitio', $nueva > 0, json_encode($vieja));
        if ($nueva > 0) {
            $nv = $pdo->query("SELECT semana, orden FROM crecer_meta_tactica WHERE id={$nueva}")
                      ->fetch(PDO::FETCH_ASSOC);
            ok('en la misma semana',   (int)$nv['semana'] === (int)$tac['semana'],
               json_encode([$nv, $tac['semana']]));
        }
        //  Y LA SEMANA NO LA ENSEÑA DOS VECES: contar la vieja y la nueva sería
        //  contar el trabajo dos veces.
        $sem2 = semana_construir($pdo, $M, $meta, $plan, (int)$tac['semana']);
        $ids_sem = array_map(fn($x) => (int)($x['id'] ?? 0), $sem2['items'] ?? []);
        ok('la semana no la enseña dos veces', !in_array($T, $ids_sem, true), json_encode($ids_sem));
    }

    //  LA TAREA QUE HACE ÉL. «Ya lo hice» tiene que cerrarla: si no, la semana
    //  se queda esperando para siempre. El plan simulado no siempre trae una,
    //  así que se siembra: el camino tiene que recorrerse igual.
    $tar = $pdo->query("SELECT id FROM crecer_meta_tactica WHERE marca_id={$M}
                          AND clase='accion_dueno' AND estado IN ('pendiente','en_curso')
                        ORDER BY id LIMIT 1")->fetchColumn();
    if (!$tar) {
        $pdo->prepare("INSERT INTO crecer_meta_tactica
                (meta_id, plan_id, marca_id, orden, semana, tipo, titulo, que_hacer,
                 por_que, quien, estado, clase, piezas_meta)
              VALUES (?,?,?, 99, 1, 'operacion', '[prueba] Manda el combo por WhatsApp',
                      'Escríbele a diez clientes de siempre.', 'Los de siempre compran primero.',
                      'dueno', 'pendiente', 'accion_dueno', 0)")
            ->execute([$META, $PLAN, $M]);
        $tar = (int)$pdo->lastInsertId();
    }
    if ($tar) {
        ver($SID, 'meta.php', 'marca=' . $M);
        [$ya] = post($SID, 'meta.php', 'marca=' . $M,
                     ['ajax' => 1, 'csrf' => csrf_de($SID), 'accion' => 'tactica',
                      'id' => (int)$tar, 'estado' => 'hecha']);
        $e_tar = (string)$pdo->query("SELECT estado FROM crecer_meta_tactica WHERE id=" . (int)$tar)->fetchColumn();
        ok('«Ya lo hice» la cierra',   $e_tar === 'hecha', $e_tar . ' · ' . json_encode($ya));
        ok('y no queda preparando',    $e_tar !== 'en_curso' && $e_tar !== 'pendiente', $e_tar);
    } else {
        nota('el plan de prueba no trajo ninguna tarea del dueño: «Ya lo hice» quedó sin recorrer');
    }

    // ══════════════════════════════════════════════════════════════
    //  H · LA SALA
    // ══════════════════════════════════════════════════════════════
    echo "\n  H · la sala\n";
    $pdo->prepare("INSERT INTO crecer_sala_jobs (marca_id,mensaje,historial,puede_producir,estado,respuesta)
                   VALUES (?,'[prueba] una idea de fuera','[]',1,'done',?)")
        ->execute([$M, 'Eso da para un reel del proceso.']);
    $JOB = (int)$pdo->lastInsertId();
    $op = sala_op_normalizar($pdo, $M, [
        'titulo' => 'El proceso del combo', 'que_hacer' => 'Un reel de 20s.',
        'por_que' => 'Ver el proceso da confianza.', 'formato' => 'reel', 'red' => 'instagram',
        'material' => 'video', 'activo_id' => $VIDEO, 'fuente' => 'dueno', 'alineada' => true]);
    sala_op_guardar($pdo, $JOB, $M, $op);
    $tac0 = (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica WHERE marca_id={$M}")->fetchColumn();
    ver($SID, 'sala.php', 'marca=' . $M);
    $TOK3 = csrf_de($SID);
    [$o1] = post($SID, 'sala.php', 'marca=' . $M,
                 ['csrf' => $TOK3, 'oportunidad' => 'meta', 'job' => $JOB]);
    ok('la oportunidad entra a la Meta', !empty($o1['ok']), json_encode($o1));
    $tac1 = (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica WHERE marca_id={$M}")->fetchColumn();
    ok('y crea UNA jugada',            $tac1 - $tac0 === 1, ($tac1 - $tac0) . ' jugadas');
    [$o2] = post($SID, 'sala.php', 'marca=' . $M,
                 ['csrf' => $TOK3, 'oportunidad' => 'meta', 'job' => $JOB]);
    ok('dos veces no crean dos',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica WHERE marca_id={$M}")->fetchColumn() === $tac1);
    $sem_op = (int)$pdo->query("SELECT semana FROM crecer_meta_tactica WHERE marca_id={$M}
                                 AND sala_job_id={$JOB}")->fetchColumn();
    ok('en una semana válida del plan',
       $sem_op >= 1 && $sem_op <= ciclo_semanas_del_plan($meta), (string)$sem_op);

    // ══════════════════════════════════════════════════════════════
    //  G · CERRAR LA SEMANA Y ABRIR LA SIGUIENTE
    // ══════════════════════════════════════════════════════════════
    echo "\n  G · cerrar y abrir\n";
    //  Se decide todo lo que quedaba: una semana no se cierra a medias.
    $pdo->prepare("UPDATE crecer_contenido SET estado='publicado'
                    WHERE marca_id=? AND estado IN ('borrador','aprobado','programado')")->execute([$M]);
    $pdo->prepare("UPDATE crecer_meta_tactica SET estado='hecha'
                    WHERE marca_id=? AND semana=1 AND estado IN ('pendiente','en_curso')")->execute([$M]);
    $plan = meta_plan_por_id($pdo, $PLAN, $M);
    $sem_actual = ciclo_semana_actual($pdo, $M, $META, $PLAN);
    $cerr = ciclo_cerrar($pdo, $M, $META, $PLAN, $sem_actual, 4, 'La gente preguntó por el combo.');
    ok('la semana se cierra',          !empty($cerr['ok']), json_encode($cerr));
    ok('sin cerrar el plan',
       (string)$pdo->query("SELECT estado FROM crecer_meta_plan WHERE id={$PLAN}")->fetchColumn() === 'activo');
    ok('ni la meta',
       (string)$pdo->query("SELECT estado FROM crecer_meta WHERE id={$META}")->fetchColumn() === 'activa');

    //  `ciclo_preparar()` recibe la semana que se CERRÓ —él calcula la
    //  siguiente—: pasarle la próxima le pide cerrar una que nadie ha abierto.
    $prep = ciclo_preparar($pdo, $M, $META, $PLAN, $sem_actual);
    ok('la siguiente se prepara',      !empty($prep['ok']) || !empty($prep['ya']), json_encode($prep));
    //  UNA SOLA GENERACIÓN SEMANAL: el botón y el cron van al mismo sitio.
    $prep2 = ciclo_preparar($pdo, $M, $META, $PLAN, $sem_actual);
    ok('el botón y el cron convergen', !empty($prep2['ya']) || empty($prep2['generado']),
       json_encode($prep2));
    ok('una sola fila por semana',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_semana WHERE plan_id={$PLAN}
                          AND semana=" . ($sem_actual + 1))->fetchColumn() <= 1);

    // ══════════════════════════════════════════════════════════════
    //  I · VOLVER A INICIO
    // ══════════════════════════════════════════════════════════════
    echo "\n  I · volver a Inicio\n";
    $home = ver($SID, 'index.php', 'marca=' . $M);
    ok('Inicio abre al final',         $home !== '' && avisos_php($home) === '', avisos_php($home));
    $S2 = $seis('al final');
    foreach ([1 => 'qué está haciendo el corillo', 3 => 'qué espera por mí',
              5 => 'cómo va la Meta', 6 => 'qué ocurrirá después'] as $i => $q) {
        ok("al final · {$q}",          (bool)$S2[$i],
           'sin respuesta · estado ' . (string)$S2['E']->estado
           . ' · etapa ' . json_encode($S2['etapa'], JSON_UNESCAPED_UNICODE));
    }
    ok('al final · como mucho tres pendientes', count($S2['pendientes']) <= 3,
       (string)count($S2['pendientes']));
    //  Y CERO RECOMENDACIONES SUELTAS: la portada no propone por proponer.
    ok('cero recomendaciones al azar',
       !preg_match('~te recomiendo|deber[ií]as probar|prueba a publicar~ui', texto($home)),
       'una recomendación que no sale del plan es ruido');

    // ══════════════════════════════════════════════════════════════
    //  SEGURIDAD · lo de uno no se ve desde el otro
    // ══════════════════════════════════════════════════════════════
    echo "\n  seguridad\n";
    $fy = Fixture::crear($pdo, 'recorridoX', false, 'admin');
    $limpiar[] = $MY = (int)$fy['marca_id'];
    $SY = sesion((int)$fy['usuario_id']);
    $ajeno = ver($SY, 'meta.php', 'marca=' . $M);
    ok('otra marca no ve esta meta',
       !str_contains(texto($ajeno), 'Subir los pedidos del combo'),
       mb_substr(texto($ajeno), 0, 160));
    ver($SY, 'sala.php', 'marca=' . $MY);
    [$rob] = post($SY, 'sala.php', 'marca=' . $MY,
                  ['csrf' => csrf_de($SY), 'oportunidad' => 'meta', 'job' => $JOB]);
    ok('ni puede añadirle una jugada',  empty($rob['ok']), json_encode($rob));
    //  CSRF: una escritura sin token no pasa.
    [$sin] = post($SID, 'aprobar2.php', 'marca=' . $M,
                  ['ajax' => 1, 'accion' => 'aprobar', 'id' => $P1]);
    ok('sin token no se escribe',       empty($sin['ok']), json_encode($sin));
    //  Y EL CRON POR HTTP ESTÁ CERRADO.
    $cron = (string)@file_get_contents(RAIZ_HTTP . '/scripts/cron_publicar.php',
        false, stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true]]));
    ok('el cron por HTTP pide llave',   str_contains($cron, '403') || trim($cron) === '',
       mb_substr($cron, 0, 120));

    // ══════════════════════════════════════════════════════════════
    //  EL COSTO
    // ══════════════════════════════════════════════════════════════
    $en = implode(',', array_map('intval', $limpiar));
    $gasto = (float)$pdo->query("SELECT COALESCE(SUM(costo_usd),0) FROM crecer_ia_log
                                  WHERE marca_id IN ({$en})")->fetchColumn();
    $reales = $pdo->query("SELECT DISTINCT modelo FROM crecer_ia_log WHERE marca_id IN ({$en})
                            AND (modelo LIKE 'gemini%' OR modelo LIKE 'gpt%'
                              OR modelo LIKE 'claude%' OR modelo LIKE 'vertex%')")
                  ->fetchAll(PDO::FETCH_COLUMN);
    $cuota_despues = $consumida();
    $asientos = $pdo->query("SELECT estado, operacion, motivo FROM crecer_img_cuota_asiento
                              WHERE marca_id={$M}")->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    $fallos++; $n++;
    echo "  FALLA excepción\n         → " . get_class($e) . ': ' . $e->getMessage()
       . "\n         → " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    foreach ($limpiar as $mid) { try { Fixture::limpiar($pdo, (int)$mid); } catch (Throwable $e) {} }
    try { $pdo->exec("DELETE FROM crecer_sala_jobs WHERE mensaje LIKE '[prueba]%'"); } catch (Throwable $e) {}
    @unlink($CENT);
    $retrato1 = $retrato();
    echo "\n  (fixtures limpiadas · centinela retirado)\n";
}

echo "\n  — el costo y el rastro —\n";
ok('el recorrido entero no gastó un centavo',
   isset($gasto) && $gasto < 0.000001,
   isset($gasto) ? 'gastó ' . number_format($gasto, 6) : 'no se llegó a medir');
ok('ni una llamada a un proveedor real',
   isset($reales) && $reales === [], isset($reales) ? implode(', ', $reales) : 'no se llegó a medir');
ok('cuota de imágenes intacta',
   $cuota_antes !== null && $cuota_despues !== null && $cuota_antes === $cuota_despues,
   'antes ' . var_export($cuota_antes, true) . ' · después ' . var_export($cuota_despues, true)
   . ' · asientos ' . json_encode($asientos ?? null, JSON_UNESCAPED_UNICODE));
//  LA BASE ES COMPARTIDA CON ENCUÉNTRALO: lo que este recorrido toque de más
//  no es basura de pruebas, son datos de otro producto.
ok('la base compartida quedó como estaba', $retrato0 === $retrato1,
   json_encode(['antes' => $retrato0, 'después' => $retrato1], JSON_UNESCAPED_UNICODE));

if ($notas) {
    echo "\n  — anotado para después —\n";
    foreach ($notas as $x) echo "  ·    $x\n";
}

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0 ? "  EL RECORRIDO ENTERO · {$n} afirmaciones\n\n"
                   : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
