<?php
// ============================================================
//  CRECER — LAS TRES PUERTAS DE «OTRA IMAGEN», POR HTTP
//  tests/test_candidata_http.php
//
//  LO QUE SE JUEGA. `cand_abrir()` esta probado en proceso; aqui se prueba lo
//  que de verdad ve el navegador: el candado CSRF, la marca, la pieza, y sobre
//  todo el ARBITRAJE con procesos DE VERDAD, no con dos llamadas seguidas en el
//  mismo PHP. Dos peticiones que se pisan es lo que pasa cuando alguien toca el
//  boton dos veces con la señal justa, y es lo unico que demuestra que el
//  `FOR UPDATE` sirve.
//
//  NO SE DISPARA NINGUN TRABAJO. El handler solo llama al worker cuando la
//  intencion NACE, y aqui el worker no puede salir a ningun sitio: el servidor
//  local no tiene llave de worker en modo prueba y, si la tuviera, el borde de
//  proveedor lanzaria antes del curl. Se cuenta al final para poder afirmarlo.
// ============================================================

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/candidata.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nLAS TRES PUERTAS DE «OTRA IMAGEN»\n" . str_repeat('=', 58) . "\n";

$cnt = fn(string $t, string $w = '1') => (int)$GLOBALS['pdo']->query(
    "SELECT COUNT(*) FROM {$t} WHERE {$w}")->fetchColumn();
$g0 = ['ia' => $cnt('crecer_ia_log'), 'as' => $cnt('crecer_img_cuota_asiento')];

$ctx0 = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
if (@file_get_contents('http://localhost/crecer/login.php', false, $ctx0) === false) {
    echo "\n  SALTADA · el servidor local no responde\n\n"; exit(0);
}
if (!cand_hay_columnas($pdo, true)) {
    echo "\n  SALTADA · falta migrations/2026-08-26_crecer_generacion_decision.sql\n\n"; exit(0);
}

function sesion(int $usuario_id): string {
    $sid  = 'ch' . bin2hex(random_bytes(7));
    $ruta = session_save_path() ?: sys_get_temp_dir();
    file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
                      'usuario_id|i:' . $usuario_id . ';');
    return $sid;
}
function token(string $sid): string {
    $ruta = (session_save_path() ?: sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'sess_' . $sid;
    return preg_match('~csrf\|s:\d+:"([0-9a-f]+)"~', (string)@file_get_contents($ruta), $m) ? $m[1] : '';
}
function ap(string $sid, int $marca, array $campos): array {
    $c = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Cookie: PHPSESSID={$sid}\r\nContent-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query($campos + ['ajax' => 1]),
        'timeout' => 30, 'ignore_errors' => true]]);
    $raw = (string)@file_get_contents(
        'http://localhost/crecer/panel/aprobar2.php?marca=' . $marca, false, $c);
    return [json_decode($raw, true) ?: [], $raw];
}

$limpiar = [];
try {
    $fx = Fixture::crear($pdo, 'cand', false, 'admin');
    $limpiar[] = $M = (int)$fx['marca_id'];
    $sid = sesion((int)$fx['usuario_id']);
    //  Acuña el token pidiendo una pagina.
    $c = stream_context_create(['http' => ['header' => "Cookie: PHPSESSID={$sid}\r\n",
                                           'timeout' => 25, 'ignore_errors' => true]]);
    @file_get_contents('http://localhost/crecer/panel/aprobar2.php?marca=' . $M, false, $c);
    ok('hay sesión con csrf', token($sid) !== '');

    $ACTUAL = '/crecer/uploads/marca_' . $M . '/actual.png';
    $pdo->prepare("INSERT INTO crecer_contenido
            (marca_id,plataforma,tipo,caption,estado,fecha_programada,grafica_path)
          VALUES (?, 'instagram','post',?, 'borrador', DATE_ADD(NOW(), INTERVAL 2 DAY), ?)")
        ->execute([$M, '[prueba] El texto que no se debe tocar.', $ACTUAL]);
    $C = (int)$pdo->lastInsertId();
    $img = fn(int $c) => (string)$pdo->query("SELECT grafica_path FROM crecer_contenido WHERE id={$c}")->fetchColumn();
    $gens = fn(int $c) => (int)$pdo->query("SELECT COUNT(*) FROM crecer_generaciones WHERE contenido_id={$c}")->fetchColumn();

    // ══════════════════════════════════════════════════════════════
    //  1 · LOS CANDADOS
    // ══════════════════════════════════════════════════════════════
    echo "\n  — sin csrf y sin marca, nada —\n";
    [$sc] = ap($sid, $M, ['accion' => 'otra_imagen', 'id' => $C, 'intencion' => 'misma_idea']);
    ok('sin csrf no abre nada', empty($sc['ok']), json_encode($sc));
    ok('y no creó ninguna fila', $gens($C) === 0);

    $fy = Fixture::crear($pdo, 'candX', false, 'cliente');
    $limpiar[] = $MX = (int)$fy['marca_id'];
    $sx = sesion((int)$fy['usuario_id']);
    @file_get_contents('http://localhost/crecer/panel/aprobar2.php?marca=' . $MX, false,
        stream_context_create(['http' => ['header' => "Cookie: PHPSESSID={$sx}\r\n",
                                          'timeout' => 25, 'ignore_errors' => true]]));
    [$aj] = ap($sx, $MX, ['accion' => 'otra_imagen', 'id' => $C,
                          'csrf' => token($sx), 'intencion' => 'misma_idea']);
    ok('otra marca no alcanza esta pieza', empty($aj['ok']), json_encode($aj));
    ok('y sigue sin haber filas', $gens($C) === 0);

    [$mal] = ap($sid, $M, ['accion' => 'otra_imagen', 'id' => $C,
                           'csrf' => token($sid), 'intencion' => 'lo_que_sea']);
    ok('una intención inventada se rechaza', empty($mal['ok']) && $mal['motivo'] === 'intencion');

    // ══════════════════════════════════════════════════════════════
    //  2 · EL ARBITRAJE, CON DOS PROCESOS DE VERDAD
    // ══════════════════════════════════════════════════════════════
    //
    //  DOS PROCESOS CON CITA, contra el DOMINIO. Arrancar dos PHP cuesta ~200 ms
    //  cada uno; con esa distancia el primero termina antes de que el segundo
    //  empiece y la prueba pasaria sin haber concurrido nunca. Se les da un
    //  instante de reloj comun y los dos esperan a el.
    //
    //  POR QUE CONTRA EL DOMINIO Y NO POR HTTP: las peticiones con la misma
    //  cookie se serializan solas —PHP bloquea el fichero de sesion— asi que por
    //  HTTP nunca se pisan de verdad. Lo que SI se prueba por HTTP, debajo, es
    //  que una segunda peticion con una intencion viva encima reusa la que hay.
    echo "\n  — dos procesos a la vez abren UNA sola intención —\n";
    $tok = token($sid);
    $PHP = PHP_BINARY;

    $pdo->prepare("INSERT INTO crecer_contenido
            (marca_id,plataforma,tipo,caption,estado,fecha_programada,grafica_path)
          VALUES (?, 'instagram','post','[prueba] carrera', 'borrador',
                  DATE_ADD(NOW(), INTERVAL 2 DAY), ?)")->execute([$M, $ACTUAL]);
    $CC = (int)$pdo->lastInsertId();

    $runner = __DIR__ . DIRECTORY_SEPARATOR . '_cand_dom_runner.php';
    $cita = microtime(true) + 2.0;
    $cmd = escapeshellarg($PHP) . ' ' . escapeshellarg($runner) . ' '
         . $M . ' ' . $CC . ' ' . escapeshellarg((string)$cita);
    $p1 = popen($cmd . ' 2>&1', 'r');
    $p2 = popen($cmd . ' 2>&1', 'r');
    $s1 = stream_get_contents($p1); pclose($p1);
    $s2 = stream_get_contents($p2); pclose($p2);
    $ult = function (string $t) {
        foreach (array_reverse(preg_split('~\R~', $t) ?: []) as $l) {
            $l = trim($l); if ($l === '') continue;
            return json_decode($l, true) ?: [];
        }
        return [];
    };
    $j1 = $ult($s1); $j2 = $ult($s2);

    ok('los dos contestan bien', !empty($j1['ok']) && !empty($j2['ok']),
       mb_substr($s1 . ' | ' . $s2, -200));
    ok('y devuelven la MISMA generación',
       (int)($j1['gen'] ?? -1) === (int)($j2['gen'] ?? -2),
       json_encode([$j1['gen'] ?? null, $j2['gen'] ?? null]));
    ok('una sola fila para esa pieza', $gens($CC) === 1, (string)$gens($CC));
    ok('y uno de los dos dice que reusó',
       !empty($j1['reusada']) || !empty($j2['reusada']),
       'el que llegó segundo tiene que saberlo, para no disparar otro trabajo');
    ok('el que esperó tardó lo que tardó el candado',
       (int)($j1['ms'] ?? 0) > 0 && (int)($j2['ms'] ?? 0) > 0,
       json_encode([$j1['ms'] ?? null, $j2['ms'] ?? null]));

    // ── Y POR HTTP: con una viva encima, la segunda petición la reusa ──
    echo "\n  — y por HTTP, con una viva encima, se reusa —\n";
    $pdo->prepare("INSERT INTO crecer_generaciones
            (marca_id, contenido_id, estado, decision_dueno, copy_text, prompt_narrativo)
          VALUES (?,?, 'generating', NULL, '[prueba]', '[prueba] instrucción')")
        ->execute([$M, $C]);
    $G = (int)$pdo->lastInsertId();
    $antes_filas = $gens($C);

    [$re] = ap($sid, $M, ['accion' => 'otra_imagen', 'id' => $C, 'csrf' => token($sid),
                          'intencion' => 'idea_diferente']);
    ok('la petición devuelve la que ya estaba',
       !empty($re['ok']) && (int)$re['gen'] === $G, json_encode($re));
    ok('y dice que la reusó',   !empty($re['reusada']),
       'sin eso, el handler dispararía otro trabajo por la misma intención');
    ok('sin crear otra fila',   $gens($C) === $antes_filas);
    ok('la imagen actual sigue intacta', $img($C) === $ACTUAL,
       'abrir la intención no toca la publicación');

    // ══════════════════════════════════════════════════════════════
    //  3 · EL SONDEO SOLO PREGUNTA
    // ══════════════════════════════════════════════════════════════
    echo "\n  — el sondeo pregunta, no produce —\n";
    $antes_gen = $gens($C);
    for ($i = 0; $i < 3; $i++) {
        [$st] = ap($sid, $M, ['accion' => 'cand_estado', 'id' => $C, 'csrf' => token($sid)]);
    }
    ok('el sondeo contesta',      !empty($st['ok']) && !empty($st['hay']), json_encode($st));
    ok('dice qué generación es',  (int)$st['gen'] === $G);
    ok('y no creó nada nuevo',    $gens($C) === $antes_gen,
       'un sondeo que produce trabajo multiplica el gasto por pestaña abierta');
    ok('ni tocó la publicación',  $img($C) === $ACTUAL);
    ok('no filtra el prompt',     !isset($st['prompt']) && !isset($st['prompt_narrativo']),
       'la instrucción es nuestra: no sale al HTML de nadie');

    // ══════════════════════════════════════════════════════════════
    //  4 · LA ENTREGA NO PISA · y la decisión sí
    // ══════════════════════════════════════════════════════════════
    echo "\n  — cuando llega, la suya sigue ahí —\n";
    $NUEVA = '/crecer/uploads/marca_' . $M . '/graficas/gen_' . $G . '_prueba.png';
    //  Esto es lo que hace el worker: escribe en la GENERACIÓN, no en la pieza.
    $pdo->prepare("UPDATE crecer_generaciones SET estado='completed', archivo=? WHERE id=?")
        ->execute([$NUEVA, $G]);
    ok('la publicación no cambió', $img($C) === $ACTUAL);

    [$st2] = ap($sid, $M, ['accion' => 'cand_estado', 'id' => $C, 'csrf' => token($sid)]);
    ok('el sondeo dice que está lista', !empty($st2['lista']));
    ok('y trae las dos para comparar',
       (string)$st2['actual'] === $ACTUAL && (string)$st2['nueva'] === $NUEVA);

    // ══════════════════════════════════════════════════════════════
    //  5 · CARRERA DE DECISIONES · con dos procesos
    // ══════════════════════════════════════════════════════════════
    echo "\n  — «usar» y «quedarme» a la vez: una gana —\n";
    $runner2 = __DIR__ . DIRECTORY_SEPARATOR . '_cand_decidir_runner.php';
    $base = escapeshellarg($PHP) . ' ' . escapeshellarg($runner2) . ' '
          . escapeshellarg($sid) . ' ' . $M . ' ' . $C . ' ' . $G . ' ' . escapeshellarg($tok) . ' ';
    $q1 = popen($base . 'elegida 2>&1', 'r');
    $q2 = popen($base . 'descartada 2>&1', 'r');
    $t1 = stream_get_contents($q1); pclose($q1);
    $t2 = stream_get_contents($q2); pclose($q2);
    //  La ULTIMA LINEA CON ALGO: el runner imprime avisos antes y el proceso
    //  termina con salto de linea, asi que «la ultima linea» a secas devuelve
    //  la vacia y la seccion sale roja con el JSON correcto justo encima.
    $d1 = $ult($t1); $d2 = $ult($t2);

    $fila = $pdo->query("SELECT decision_dueno, decidida_at FROM crecer_generaciones WHERE id={$G}")
                ->fetch(PDO::FETCH_ASSOC);
    $dec = (string)($fila['decision_dueno'] ?? '');
    ok('quedó UNA decisión, y solo una', in_array($dec, ['elegida', 'descartada'], true), $dec);
    ok('con su fecha',                   $fila['decidida_at'] !== null);
    ok('los dos contestan sin romperse', !empty($d1['ok']) && !empty($d2['ok']),
       mb_substr($t1 . ' | ' . $t2, 0, 200));
    ok('y los dos cuentan la MISMA decisión',
       (string)($d1['decision'] ?? '') === $dec && (string)($d2['decision'] ?? '') === $dec,
       json_encode([$d1['decision'] ?? null, $d2['decision'] ?? null]) . ' vs ' . $dec);

    //  Y LA PUBLICACIÓN ES COHERENTE CON LO QUE GANÓ. Nunca a medias.
    ok('la imagen coincide con la decisión',
       $dec === 'elegida' ? $img($C) === $NUEVA : $img($C) === $ACTUAL,
       'decisión=' . $dec . ' · imagen=' . $img($C));
    ok('el texto sigue intacto',
       (string)$pdo->query("SELECT caption FROM crecer_contenido WHERE id={$C}")->fetchColumn()
         === '[prueba] El texto que no se debe tocar.');
    ok('y la pieza no se aprobó sola',
       (string)$pdo->query("SELECT estado FROM crecer_contenido WHERE id={$C}")->fetchColumn()
         === 'borrador');

    //  YA NO HAY PENDIENTE: recargar no reabre la comparación.
    [$st3] = ap($sid, $M, ['accion' => 'cand_estado', 'id' => $C, 'csrf' => token($sid)]);
    ok('ya no queda candidata pendiente', empty($st3['hay']), json_encode($st3));

    // ══════════════════════════════════════════════════════════════
    //  6 · UN CICLO NUEVO SÍ SE PERMITE
    // ══════════════════════════════════════════════════════════════
    echo "\n  — y otro ciclo deliberado sí abre otra —\n";
    [$n2] = ap($sid, $M, ['accion' => 'otra_imagen', 'id' => $C, 'csrf' => token($sid),
                          'intencion' => 'idea_diferente', 'evitar' => 'sin personas']);
    ok('se abre otra intención', !empty($n2['ok']) && (int)$n2['gen'] !== $G, json_encode($n2));
    ok('y ahora hay dos filas',  $gens($C) === 2);
    ok('la nueva guarda lo que pidió evitar',
       str_contains((string)$pdo->query("SELECT prompt_narrativo FROM crecer_generaciones
                                          WHERE id=" . (int)$n2['gen'])->fetchColumn(), 'sin personas'));

    //  UNA PIEZA PUBLICADA NO EMPIEZA NADA.
    $pdo->prepare("UPDATE crecer_contenido SET estado='publicado' WHERE id=?")->execute([$C]);
    [$pb] = ap($sid, $M, ['accion' => 'otra_imagen', 'id' => $C, 'csrf' => token($sid),
                          'intencion' => 'misma_idea']);
    ok('una publicada no abre intención', empty($pb['ok']) && $pb['motivo'] === 'publicada');
    $pdo->prepare("UPDATE crecer_contenido SET estado='borrador' WHERE id=?")->execute([$C]);

} catch (Throwable $e) {
    $fallos++; echo "\n  EXCEPCION · " . get_class($e) . ': ' . $e->getMessage()
                  . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    foreach ($limpiar as $mid) {
        try { $pdo->prepare("DELETE FROM crecer_generaciones WHERE marca_id=?")->execute([$mid]); }
        catch (Throwable $e) {}
        try { Fixture::limpiar($pdo, $mid); } catch (Throwable $e) {}
    }
    echo "\n  (fixtures limpiadas)\n";
}

echo "\n  — el costo —\n";
ok('cero llamadas al modelo', $cnt('crecer_ia_log') === $g0['ia'],
   'antes ' . $g0['ia'] . ' · ahora ' . $cnt('crecer_ia_log'));
ok('cero asientos de cuota',  $cnt('crecer_img_cuota_asiento') === $g0['as'],
   'abrir, sondear y decidir no pasan por proveedor: la unidad la abre el worker');

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  UNA INTENCION, UNA DECISION · $n afirmaciones\n\n"
    : "  $fallos FALLAS de $n\n\n";
exit($fallos === 0 ? 0 : 1);
