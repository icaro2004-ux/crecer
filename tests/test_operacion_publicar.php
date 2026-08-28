<?php
// ============================================================
//  CRECER — LO APROBADO SALE, Y SI NO SALE SE DICE
//  tests/test_operacion_publicar.php
//
//  ESTO ES LA PROMESA DEL PRODUCTO. «El corillo trabaja aunque cierres la
//  aplicación» solo es cierto si, con nadie mirando, el cron encuentra lo
//  programado, lo publica UNA vez, deja el estado bien y avisa cuando algo
//  necesita al dueño.
//
//  LO QUE SE PRUEBA, y por qué cada cosa:
//
//   1 · LA HORA. Es lo primero porque es lo que estaba roto: PHP trabajaba en
//       hora de Puerto Rico y MySQL en Hostinger va en UTC, así que una
//       publicación puesta para las 9:00 AM salía a las 5:00. Cuatro horas de
//       diferencia entre la hora que ve el cliente y la que decide el
//       publicador.
//
//   2 · EL ÉXITO: se publica, se guarda el id remoto, se avisa una vez, y la
//       pieza queda lista para que Resultados recoja sus métricas.
//
//   3 · QUE NO SALGA DOS VECES. Dos crones solapados, dos procesos de verdad
//       con cita de reloj: una sola llamada a la red y una sola publicación.
//       Un post duplicado en el muro del cliente no se puede deshacer.
//
//   4 · LOS FALLOS, CLASIFICADOS. Lo pasajero se reintenta solo y NO molesta;
//       un token vencido no se reintenta y SÍ avisa; lo incierto no se
//       reintenta nunca, porque reintentar lo que quizá salió es publicar dos
//       veces.
//
//  CERO RED: el runner declara su propio `meta_api()`. Ni un byte a Meta.
// ============================================================

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/publicador.php';
require_once __DIR__ . '/../includes/cron_latido.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nLO APROBADO SALE\n" . str_repeat('=', 58) . "\n";

/** Corre el publicador en su propio proceso, con la red sustituida. */
function correr(int $cid, string $guion = 'ok', float $cita = 0): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(__DIR__ . '/_publicar_runner.php') . ' '
         . $cid . ' ' . escapeshellarg($guion)
         . ($cita > 0 ? ' ' . escapeshellarg((string)$cita) : '');
    $sal = (string)shell_exec($cmd . ' 2>&1');
    foreach (array_reverse(preg_split('~\R~', $sal) ?: []) as $l) {
        $l = trim($l); if ($l === '') continue;
        $j = json_decode($l, true);
        if (is_array($j)) return $j;
    }
    return ['estado' => 'sin_respuesta', '_raw' => mb_substr($sal, -300)];
}

/**
 * CUÁNTAS VECES SE PUBLICÓ DE VERDAD.
 *
 * No cuenta llamadas a la red: cuenta las que PONEN el post en el muro.
 * Publicar en Instagram son tres pasos —crear el contenedor, preguntar si ya
 * está listo, publicarlo— y solo el último es irreversible. Contarlos todos
 * juntos mezcla el sondeo con la publicación, y entonces la cifra no responde
 * a la única pregunta que importa: ¿salió dos veces?
 */
function publicaciones_reales(int $cid): int {
    $f = sys_get_temp_dir() . '/crecer_pub_llamadas_' . $cid . '.log';
    if (!is_file($f)) return 0;
    $n = 0;
    foreach (explode("
", (string)file_get_contents($f)) as $l) {
        if (str_contains($l, 'media_publish') || str_contains($l, '/photos')
            || str_contains($l, '/feed') || str_contains($l, '/videos')) $n++;
    }
    return $n;
}

/** Cuántas veces se llamó a la red por esta pieza (el doble lo apunta). */
function llamadas(int $cid): int {
    $f = sys_get_temp_dir() . '/crecer_pub_llamadas_' . $cid . '.log';
    return is_file($f) ? count(array_filter(explode("\n", (string)file_get_contents($f)))) : 0;
}
function limpiar_llamadas(int $cid): void {
    @unlink(sys_get_temp_dir() . '/crecer_pub_llamadas_' . $cid . '.log');
}

$limpiar = [];
try {
    // ══════════════════════════════════════════════════════════════
    //  1 · LA HORA
    // ══════════════════════════════════════════════════════════════
    echo "\n  — la hora que ve el cliente es la que decide —\n";
    $r = $pdo->query("SELECT @@session.time_zone tz, NOW() n")->fetch(PDO::FETCH_ASSOC);
    ok('PHP trabaja en hora de Puerto Rico',
       date_default_timezone_get() === 'America/Puerto_Rico', date_default_timezone_get());
    ok('y la base, en el mismo reloj',
       abs(strtotime((string)$r['n']) - time()) <= 2,
       'MySQL ' . $r['n'] . ' · PHP ' . date('Y-m-d H:i:s') . ' · tz ' . $r['tz']);
    //  PUERTO RICO NO CAMBIA LA HORA EN VERANO: el desplazamiento fijo es
    //  exacto todo el año. Si algún día el producto sale de la isla, esto deja
    //  de valer — y por eso está escrito aquí.
    ok('sin horario de verano que lo mueva',
       (new DateTime('now', new DateTimeZone('America/Puerto_Rico')))->format('I') === '0');

    $fx = Fixture::crear($pdo, 'oper', true, 'admin');
    $limpiar[] = $M = (int)$fx['marca_id'];
    //  Una conexión activa: sin ella el publicador no mira la pieza siquiera.
    $pdo->prepare("INSERT INTO crecer_conexiones
            (marca_id, proveedor, estado, ig_user_id, fb_page_id, page_access_token)
          VALUES (?, 'meta', 'activa', '17000000001', '10000000001', 'tok-de-prueba')")
        ->execute([$M]);

    //  UNA IMAGEN DE VERDAD EN DISCO. El publicador comprueba que el archivo
    //  esté ahí antes de llamar a la red —para no quemar intentos con el error
    //  críptico de Meta— así que sin fichero no se llega a publicar nunca.
    //  Se escribe un JPEG mínimo a mano: GD no está en esta máquina.
    $dir_up = rtrim(defined('UPLOADS_PATH') ? UPLOADS_PATH : dirname(__DIR__) . '/uploads', '/\\')
            . DIRECTORY_SEPARATOR . 'marca_' . $M;
    if (!is_dir($dir_up)) @mkdir($dir_up, 0777, true);
    $JPG_REL = 'marca_' . $M . '/prueba-pub.jpg';
    file_put_contents($dir_up . DIRECTORY_SEPARATOR . 'prueba-pub.jpg',
        base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsL'
                    . 'DBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/'
                    . '2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIy'
                    . 'MjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QA'
                    . 'HwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUF'
                    . 'BAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkK'
                    . 'FhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1'
                    . 'dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXG'
                    . 'x8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/9oACAEBAAA/APn+'
                    . 'iiigD//Z'));

    $crear_pieza = function (string $cuando) use ($pdo, $M, $JPG_REL): int {
        $pdo->prepare("INSERT INTO crecer_contenido
                (marca_id, plataforma, tipo, caption, estado, fecha_programada, grafica_path)
              VALUES (?, 'instagram', 'post', '[prueba] El combo del sábado', 'programado', ?, ?)")
            ->execute([$M, $cuando, rtrim(UPLOADS_URL, '/') . '/' . $JPG_REL]);
        return (int)$pdo->lastInsertId();
    };

    //  LA PIEZA DEL FUTURO NO SALE. Es la otra mitad del contrato de la hora:
    //  si el reloj estuviera corrido, esta se publicaría antes de tiempo.
    $futura = $crear_pieza(date('Y-m-d H:i:s', strtotime('+3 hours')));
    $res = correr_publicador($pdo, 25);
    $est_futura = (string)$pdo->query("SELECT estado FROM crecer_contenido WHERE id={$futura}")->fetchColumn();
    ok('lo programado para luego NO sale ahora', $est_futura === 'programado', $est_futura);

    // ══════════════════════════════════════════════════════════════
    //  2 · EL ÉXITO
    // ══════════════════════════════════════════════════════════════
    echo "\n  — llega la hora y sale —\n";
    $cid = $crear_pieza(date('Y-m-d H:i:s', strtotime('-2 minutes')));
    limpiar_llamadas($cid);
    $r1 = correr($cid, 'ok');
    ok('se publica',              ($r1['estado'] ?? '') === 'publicado', json_encode($r1));

    $p = $pdo->query("SELECT * FROM crecer_contenido WHERE id={$cid}")->fetch(PDO::FETCH_ASSOC);
    ok('el estado queda publicado', $p['estado'] === 'publicado', (string)$p['estado']);
    ok('con su hora',               !empty($p['publicado_at']), (string)$p['publicado_at']);
    ok('y suelta el candado',       $p['lock_token'] === null, (string)$p['lock_token']);

    //  EL ID REMOTO ES LO QUE PERMITE VOLVER A ELLA: para enseñarle el post al
    //  dueño y para que Resultados le pida sus números a la red.
    $pub = $pdo->query("SELECT * FROM crecer_publicaciones WHERE contenido_id={$cid}")->fetch(PDO::FETCH_ASSOC);
    ok('queda registrada la publicación', (bool)$pub, json_encode($pub));
    ok('con el id que devolvió la red',
       trim((string)($pub['external_id'] ?? '')) !== '', json_encode($pub));
    ok('y marcada como buena',      ($pub['estado'] ?? '') === 'ok', (string)($pub['estado'] ?? ''));

    $av = $pdo->query("SELECT COUNT(*) FROM crecer_notificaciones
                        WHERE marca_id={$M} AND tipo='publicado'")->fetchColumn();
    ok('el dueño recibe UN aviso',  (int)$av === 1, (string)$av);

    //  Y NO SALE OTRA VEZ. Volver a pasarle el cron por encima no puede
    //  publicar de nuevo lo que ya salió.
    $antes = publicaciones_reales($cid);
    $r2 = correr($cid, 'ok');
    ok('pasarle el cron otra vez no republica',
       publicaciones_reales($cid) === $antes && $antes === 1,
       'publicaciones antes ' . $antes . ' · despues ' . publicaciones_reales($cid));
    ok('y sigue habiendo un solo aviso',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_notificaciones
                          WHERE marca_id={$M} AND tipo='publicado'")->fetchColumn() === 1);

    // ══════════════════════════════════════════════════════════════
    //  3 · DOS CRONES A LA VEZ · una sola publicación
    // ══════════════════════════════════════════════════════════════
    echo "\n  — dos crones solapados no publican dos veces —\n";
    $cid2 = $crear_pieza(date('Y-m-d H:i:s', strtotime('-2 minutes')));
    limpiar_llamadas($cid2);
    $cita = microtime(true) + 2.0;
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/_publicar_runner.php')
         . ' ' . $cid2 . ' ok ' . escapeshellarg((string)$cita);
    $p1 = popen($cmd . ' 2>&1', 'r'); $p2 = popen($cmd . ' 2>&1', 'r');
    $s1 = stream_get_contents($p1); pclose($p1);
    $s2 = stream_get_contents($p2); pclose($p2);
    $ult = function (string $t): array {
        foreach (array_reverse(preg_split('~\R~', $t) ?: []) as $l) {
            $l = trim($l); if ($l === '') continue;
            $j = json_decode($l, true); if (is_array($j)) return $j;
        }
        return [];
    };
    $j1 = $ult($s1); $j2 = $ult($s2);
    //  LOS DOS PUEDEN DECIR «publicado», y está bien: el segundo entra, ve que
    //  esa red ya salió y no la repite. Lo que no puede pasar es que ninguno
    //  publique.
    ok('la publicación sale',
       ($j1['estado'] ?? '') === 'publicado' || ($j2['estado'] ?? '') === 'publicado',
       json_encode([$j1['estado'] ?? '', $j2['estado'] ?? '']));
    //  LA CIFRA QUE IMPORTA: cuántas veces se tocó la red. Dos publicaciones
    //  en el muro de un cliente no se deshacen.
    //  Y ESTA ES LA QUE IMPORTA: cuántas veces se puso el post en el muro. Las
    //  demás llamadas —crear el contenedor, preguntar si está listo— no publican
    //  nada. Un post duplicado en el muro de un cliente no se deshace, así que
    //  aquí no hay margen: uno.
    ok('el post se puso en el muro UNA sola vez',
       publicaciones_reales($cid2) === 1,
       publicaciones_reales($cid2) . ' publicaciones · ' . llamadas($cid2) . ' llamadas en total');
    ok('una sola fila de publicación',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_publicaciones
                          WHERE contenido_id={$cid2} AND estado='ok'")->fetchColumn() === 1);

    // ══════════════════════════════════════════════════════════════
    //  4 · LOS FALLOS, CADA UNO EN SU SITIO
    // ══════════════════════════════════════════════════════════════
    echo "\n  — lo pasajero se reintenta solo y no molesta —\n";
    $cid3 = $crear_pieza(date('Y-m-d H:i:s', strtotime('-2 minutes')));
    $r3 = correr($cid3, 'temporal');
    ok('queda fallida',            ($r3['estado'] ?? '') === 'fallido', json_encode($r3));
    ok('clasificada como pasajera', ($r3['clase'] ?? '') === 'temporal', json_encode($r3));
    ok('y se va a reintentar sola', !empty($r3['reintenta']), json_encode($r3));
    ok('sin molestar al dueño',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_notificaciones
                          WHERE marca_id={$M} AND tipo='pub_fallo'")->fetchColumn() === 0,
       'avisar de algo que se arregla solo enseña a ignorar la campanita');
    ok('el contenido sigue guardado',
       (string)$pdo->query("SELECT caption FROM crecer_contenido WHERE id={$cid3}")->fetchColumn() !== '');

    //  EL FRENO: recién fallada, el loop NO la coge otra vez. Esperar dos
    //  minutos antes del siguiente intento es lo que evita el martilleo.
    $ids = array_map('intval', $pdo->query(
        "SELECT c.id FROM crecer_contenido c
           JOIN crecer_conexiones x ON x.marca_id=c.marca_id AND x.estado='activa'
          WHERE c.estado='fallido' AND c.pub_error LIKE '[temporal]%'
            AND c.updated_at < (NOW() - INTERVAL 2 MINUTE)")->fetchAll(PDO::FETCH_COLUMN));
    ok('y no la reintenta en el mismo minuto', !in_array($cid3, $ids, true),
       json_encode($ids));

    echo "\n  — un token vencido no se martillea: se avisa —\n";
    $cid4 = $crear_pieza(date('Y-m-d H:i:s', strtotime('-2 minutes')));
    $r4 = correr($cid4, 'credenciales');
    ok('queda fallida',              ($r4['estado'] ?? '') === 'fallido', json_encode($r4));
    ok('clasificada como credenciales', ($r4['clase'] ?? '') === 'credenciales', json_encode($r4));
    ok('y NO se reintenta sola',     empty($r4['reintenta']), json_encode($r4));
    $nf = $pdo->query("SELECT titulo, mensaje, link FROM crecer_notificaciones
                        WHERE marca_id={$M} AND tipo='pub_fallo' ORDER BY id DESC LIMIT 1")
              ->fetch(PDO::FETCH_ASSOC);
    ok('se avisa al dueño',          (bool)$nf, json_encode($nf));
    ok('y el aviso lleva a reconectar',
       str_contains((string)($nf['link'] ?? ''), 'conectar.php'), (string)($nf['link'] ?? ''));
    //  NADA DE TRIPAS. El dueño no tiene que ver un código de Meta ni la
    //  palabra «token» para entender que hay que reconectar.
    $texto = mb_strtolower(($nf['titulo'] ?? '') . ' ' . ($nf['mensaje'] ?? ''));
    foreach (['token', '(#190)', 'oauth', 'access', 'error validating'] as $tripa) {
        ok("el aviso no enseña «{$tripa}»", !str_contains($texto, $tripa), $texto);
    }

    echo "\n  — y lo incierto no se reintenta NUNCA —\n";
    $cid5 = $crear_pieza(date('Y-m-d H:i:s', strtotime('-2 minutes')));
    $r5 = correr($cid5, 'incierto');
    ok('clasificada como incierta',  ($r5['clase'] ?? '') === 'incierto', json_encode($r5));
    ok('y NO se reintenta sola',     empty($r5['reintenta']),
       'reintentar algo que quizá salió es publicar dos veces en el muro del cliente');
    $ids2 = array_map('intval', $pdo->query(
        "SELECT id FROM crecer_contenido WHERE estado='fallido'
           AND pub_error LIKE '[temporal]%'")->fetchAll(PDO::FETCH_COLUMN));
    ok('el loop tampoco la va a coger', !in_array($cid5, $ids2, true), json_encode($ids2));

    // ══════════════════════════════════════════════════════════════
    //  5 · EL LATIDO DEL CRON
    // ══════════════════════════════════════════════════════════════
    echo "\n  — y producción sabe si el cron sigue vivo —\n";
    cron_latido($pdo, 'prueba_latido', true, 123, 4);
    $e = cron_estado($pdo, 'prueba_latido', 10);
    ok('queda constancia de la corrida', !empty($e['hubo']), json_encode($e));
    ok('con su hora',                 (string)($e['ultima'] ?? '') !== '0000-00-00 00:00:00'
                                      && !empty($e['ultima']), (string)($e['ultima'] ?? ''));
    ok('y no se ve atrasado',         empty($e['atrasado']), json_encode($e));
    $e2 = cron_estado($pdo, 'un_cron_que_no_existe', 10);
    ok('un cron que nunca corrió SÍ se ve atrasado', !empty($e2['atrasado']),
       'es la señal de que dejó de sonar, y es la que importa');
    try { $pdo->exec("DELETE FROM crecer_pipeline_run WHERE etapa='cron_prueba_latido'"); } catch (Throwable $ex) {}

} catch (Throwable $e) {
    $fallos++; $n++;
    echo "  FALLA excepción\n         → " . get_class($e) . ': ' . $e->getMessage()
       . "\n         → " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    foreach ($limpiar as $mid) { try { Fixture::limpiar($pdo, (int)$mid); } catch (Throwable $e) {} }
    foreach ([$cid ?? 0, $cid2 ?? 0, $cid3 ?? 0, $cid4 ?? 0, $cid5 ?? 0] as $c) {
        if ($c) limpiar_llamadas((int)$c);
    }
    echo "\n  (fixture limpiada)\n";
}

echo "\n  — el costo —\n";
ok('ni un byte a las redes ni a ningún modelo',
   (float)$pdo->query("SELECT COALESCE(SUM(costo_usd),0) FROM crecer_ia_log
                        WHERE created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)")->fetchColumn() < 0.000001);

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0 ? "  LO APROBADO SALE · {$n} afirmaciones\n\n"
                   : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
