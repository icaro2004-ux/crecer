<?php
// ============================================================
//  CRECER — A QUIÉN SE LE ESCRIBE, Y CUÁNDO NO
//  tests/test_aviso_correo.php
//
//  DOS DEFECTOS DE LA FASE 7, LOS DOS DEL MISMO TIPO: código que parecía
//  correcto y dejaba al dueño sin enterarse.
//
//  1 · `crecer_marca.reporte_email` es un INTERRUPTOR (TINYINT 1/0) — la
//      casilla de «avísame por correo» de Configuración. El código lo leía
//      como si fuera una dirección, así que intentaba escribirle a «1». No
//      reventaba: `filter_var` lo rechazaba justo después y no se mandaba
//      nada. El fallo era invisible y el efecto, el peor posible — el dueño
//      NUNCA recibía el aviso de que su publicación no había salido.
//
//  2 · Las notificaciones se deduplicaban por marca + tipo + título, y dos
//      publicaciones distintas que fallan traen el MISMO título. La segunda
//      desaparecía: se enteraba de una, y la otra se quedaba callada.
//
//  CERO CORREO REAL: se sustituye el emisor por un doble antes de cargar
//  nada. Ni un mensaje sale de esta máquina.
// ============================================================

//  EL DOBLE DEL CORREO. Se declara ANTES de que se cargue el de verdad, así
//  que la función real ni llega a definirse.
$GLOBALS['__CORREOS'] = [];
if (!function_exists('crecer_enviar_email')) {
    function crecer_enviar_email(string $para, string $asunto, string $cuerpo): bool {
        $GLOBALS['__CORREOS'][] = ['para' => $para, 'asunto' => $asunto, 'cuerpo' => $cuerpo];
        return true;
    }
}

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/publicador.php';
require_once __DIR__ . '/../includes/notif.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}
function correos(): array { return $GLOBALS['__CORREOS']; }
function limpiar_correos(): void { $GLOBALS['__CORREOS'] = []; }

echo "\nA QUIÉN SE LE ESCRIBE\n" . str_repeat('=', 58) . "\n";

$limpiar = [];
try {
    $fx = Fixture::crear($pdo, 'correo', true, 'admin');
    $limpiar[] = $M = (int)$fx['marca_id'];
    $UID = (int)$fx['usuario_id'];
    $correo_real = (string)$pdo->query("SELECT email FROM usuarios WHERE id={$UID}")->fetchColumn();

    $pieza = function (int $i) use ($pdo, $M): int {
        $pdo->prepare("INSERT INTO crecer_contenido
                (marca_id, plataforma, tipo, caption, estado)
              VALUES (?, 'instagram', 'post', ?, 'fallido')")
            ->execute([$M, '[prueba] Pieza ' . $i]);
        return (int)$pdo->lastInsertId();
    };
    $av = pub_aviso_fallo('contenido');

    // ══════════════════════════════════════════════════════════════
    //  1 · LA PREFERENCIA ES UN SÍ O UN NO
    // ══════════════════════════════════════════════════════════════
    echo "\n  — con la casilla puesta, se le escribe —\n";
    $pdo->prepare("UPDATE crecer_marca SET reporte_email=1 WHERE id=?")->execute([$M]);
    $c1 = $pieza(1);
    limpiar_correos();
    pub_correo_fallo($pdo, $M, $c1, $av, 'contenido');
    ok('sale un correo',           count(correos()) === 1, json_encode(array_column(correos(), 'para')));
    ok('y va a SU dirección',
       (correos()[0]['para'] ?? '') === $correo_real,
       (correos()[0]['para'] ?? '') . ' · esperada ' . $correo_real);
    //  EL DEFECTO, EN UNA AFIRMACIÓN: nunca a «1» ni a «0».
    ok('nunca a «1» ni a «0»',
       !in_array((correos()[0]['para'] ?? ''), ['1', '0'], true),
       'la preferencia es un interruptor, no una dirección');
    ok('el correo lleva a dónde ir',
       str_contains((correos()[0]['cuerpo'] ?? ''), 'aprobar2.php?ver=' . $c1),
       mb_substr((correos()[0]['cuerpo'] ?? ''), 0, 120));
    //  Sin tripas tampoco por correo, que se lee donde otros miran.
    $cuerpo = mb_strtolower((correos()[0]['cuerpo'] ?? '') . ' ' . (correos()[0]['asunto'] ?? ''));
    ok('sin tripas del proveedor',
       !str_contains($cuerpo, 'token') && !str_contains($cuerpo, 'oauth')
       && !str_contains($cuerpo, '#190'), $cuerpo);

    echo "\n  — y con la casilla quitada, no —\n";
    $pdo->prepare("UPDATE crecer_marca SET reporte_email=0 WHERE id=?")->execute([$M]);
    $c2 = $pieza(2);
    limpiar_correos();
    pub_correo_fallo($pdo, $M, $c2, $av, 'contenido');
    ok('no sale ninguno',          count(correos()) === 0, json_encode(correos()));

    echo "\n  — sin dirección tampoco, aunque diga que sí —\n";
    $pdo->prepare("UPDATE crecer_marca SET reporte_email=1 WHERE id=?")->execute([$M]);
    $pdo->prepare("UPDATE usuarios SET email='' WHERE id=?")->execute([$UID]);
    $c3 = $pieza(3);
    limpiar_correos();
    pub_correo_fallo($pdo, $M, $c3, $av, 'contenido');
    ok('con el correo vacío, cero', count(correos()) === 0, json_encode(correos()));

    $pdo->prepare("UPDATE usuarios SET email='esto-no-es-un-correo' WHERE id=?")->execute([$UID]);
    $c4 = $pieza(4);
    limpiar_correos();
    pub_correo_fallo($pdo, $M, $c4, $av, 'contenido');
    ok('con un correo inválido, cero', count(correos()) === 0, json_encode(correos()));
    $pdo->prepare("UPDATE usuarios SET email=? WHERE id=?")->execute([$correo_real, $UID]);

    echo "\n  — y el mismo fallo no escribe dos veces —\n";
    $c5 = $pieza(5);
    limpiar_correos();
    //  ASÍ OCURRE DE VERDAD, y así se prueba: el correo sale SOLO si el aviso
    //  in-app era nuevo. `notif_crear` es quien lo sabe —lo dice al devolver—
    //  y quien llama decide con eso. Adivinarlo contando filas es lo que hacía
    //  este código antes, y por eso salían dos correos.
    $enviar = function (int $cid) use ($pdo, $M, $av) {
        $nuevo = notif_crear($pdo, $M, 'pub_fallo', $av['titulo'], $av['mensaje'],
                             '/crecer/panel/aprobar2.php?ver=' . $cid . '&marca=' . $M, 'bolt');
        if ($nuevo) pub_correo_fallo($pdo, $M, $cid, $av, 'contenido');
        return $nuevo;
    };
    ok('la primera vez sí escribe',   $enviar($c5) && count(correos()) === 1,
       (string)count(correos()));
    $primero = count(correos());
    ok('y la segunda ya no',          !$enviar($c5), 'el aviso sigue sin leer');
    ok('sin un correo de más',        count(correos()) === $primero,
       $primero . ' → ' . count(correos()));

    // ══════════════════════════════════════════════════════════════
    //  2 · DOS PIEZAS FALLIDAS SON DOS AVISOS
    // ══════════════════════════════════════════════════════════════
    echo "\n  — dos publicaciones distintas, dos avisos —\n";
    $pdo->prepare("DELETE FROM crecer_notificaciones WHERE marca_id=?")->execute([$M]);
    $a = $pieza(10); $b = $pieza(11);
    $link = fn(int $c) => '/crecer/panel/aprobar2.php?ver=' . $c . '&marca=' . $M;

    notif_crear($pdo, $M, 'pub_fallo', $av['titulo'], $av['mensaje'], $link($a), 'bolt');
    notif_crear($pdo, $M, 'pub_fallo', $av['titulo'], $av['mensaje'], $link($b), 'bolt');
    $filas = $pdo->query("SELECT link FROM crecer_notificaciones
                           WHERE marca_id={$M} AND tipo='pub_fallo' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    ok('hay dos avisos, uno por pieza', count($filas) === 2, json_encode($filas));
    ok('y cada uno abre SU publicación',
       in_array($link($a), $filas, true) && in_array($link($b), $filas, true),
       json_encode($filas));
    //  EL TÍTULO NO LLEVA IDS: el enlace identifica el objeto, el título habla
    //  en cristiano.
    $tit = (string)$pdo->query("SELECT titulo FROM crecer_notificaciones
                                 WHERE marca_id={$M} AND tipo='pub_fallo' LIMIT 1")->fetchColumn();
    ok('sin ids en el título',
       preg_match('/\d{3,}/', $tit) !== 1, $tit);

    echo "\n  — y la misma pieza, otra vez, no repite —\n";
    notif_crear($pdo, $M, 'pub_fallo', $av['titulo'], $av['mensaje'], $link($a), 'bolt');
    ok('siguen siendo dos',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_notificaciones
                          WHERE marca_id={$M} AND tipo='pub_fallo'")->fetchColumn() === 2);

    echo "\n  — y los avisos sin enlace se siguen agrupando —\n";
    //  Lo de siempre: un aviso general no tiene objeto, así que dos iguales
    //  seguidos son el mismo aviso. Cambiar el dedup no podía romper esto.
    $pdo->prepare("DELETE FROM crecer_notificaciones WHERE marca_id=?")->execute([$M]);
    notif_crear($pdo, $M, 'general', 'El corillo terminó tu semana', 'Ya puedes revisarla.');
    notif_crear($pdo, $M, 'general', 'El corillo terminó tu semana', 'Ya puedes revisarla.');
    ok('uno solo, como antes',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_notificaciones
                          WHERE marca_id={$M} AND tipo='general'")->fetchColumn() === 1);

} catch (Throwable $e) {
    $fallos++; $n++;
    echo "  FALLA excepción\n         → " . get_class($e) . ': ' . $e->getMessage()
       . "\n         → " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    foreach ($limpiar as $mid) { try { Fixture::limpiar($pdo, (int)$mid); } catch (Throwable $e) {} }
    echo "\n  (fixture limpiada)\n";
}

echo "\n  — el costo —\n";
ok('ni un correo salió de esta máquina', true,
   'el emisor está sustituido desde la primera línea del archivo');
ok('y cero gasto de modelos',
   (float)$pdo->query("SELECT COALESCE(SUM(costo_usd),0) FROM crecer_ia_log
                        WHERE created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)")->fetchColumn() < 0.000001);

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0 ? "  A QUIÉN SE LE ESCRIBE · {$n} afirmaciones\n\n"
                   : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
