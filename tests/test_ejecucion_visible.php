<?php
// ============================================================
//  CRECER — LA EJECUCIÓN SE VE, Y DICE LA VERDAD
//  tests/test_ejecucion_visible.php
//
//  EL HUECO QUE CIERRA ESTA FASE. Escoger la Meta funcionaba y el plan se
//  armaba bien; lo que el dueño no veía era lo de después. El producto hacía
//  el trabajo y no lo contaba.
//
//  LAS SEIS PREGUNTAS que Tu Meta e Inicio tienen que poder contestar cuando
//  haya datos: qué hace el corillo, qué terminó, qué espera por mí, qué se
//  publica y cuándo, cómo va la Meta, y qué pasa después.
//
//  LO QUE SE PRUEBA, y por qué:
//
//   1 · CADA ETAPA SALE DE LA MÁQUINA DE ESTADOS QUE YA HABÍA. No hay un
//       segundo motor decidiendo por su cuenta: se traduce la letra del
//       compositor. Dos motores acaban dando dos respuestas distintas a la
//       misma pregunta, y el dueño no sabe cuál creer.
//
//   2 · LA CONSECUENCIA SOBREVIVE A LA RECARGA. Un aviso que desaparece deja
//       al dueño sin saber qué pasó con lo que acaba de decidir. Aquí se
//       reconstruye del estado guardado, así que mañana dice lo mismo.
//
//   3 · NUNCA «Listo» cuando hay algo concreto que decir: una fecha, una red,
//       una cuota que no se gastó.
//
//   4 · LA MISMA HORA EN TODAS PARTES. La que se enseña es la que usa el
//       publicador para decidir; una hora distinta en cada pantalla es peor
//       que no ponerla.
//
//  CERO MODELOS, CERO RED, CERO CUOTA.
// ============================================================

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/agentes.php';
require_once __DIR__ . '/../includes/ejecucion.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nLA EJECUCIÓN SE VE\n" . str_repeat('=', 58) . "\n";

$limpiar = [];
try {
    $fx = Fixture::crear($pdo, 'ejec', true, 'admin');
    $limpiar[] = $M = (int)$fx['marca_id'];
    $marca = $pdo->query("SELECT * FROM crecer_marca WHERE id={$M}")->fetch(PDO::FETCH_ASSOC);
    $BASE  = '/crecer/panel';
    //  Se parte de limpio: la fixture trae piezas y aquí se controla cada caso.
    $pdo->prepare("DELETE FROM crecer_contenido WHERE marca_id=?")->execute([$M]);

    $pieza = function (string $estado, ?string $cuando = null, array $extra = []) use ($pdo, $M): int {
        $pdo->prepare("INSERT INTO crecer_contenido
                (marca_id, plataforma, tipo, caption, estado, fecha_programada,
                 publicado_at, necesita_material, pub_error)
              VALUES (?, 'instagram', 'post', '[prueba] El combo del sábado', ?, ?, ?, ?, ?)")
            ->execute([$M, $estado, $cuando,
                       $extra['publicado_at'] ?? null,
                       $extra['material'] ?? null,
                       $extra['pub_error'] ?? null]);
        return (int)$pdo->lastInsertId();
    };

    // ══════════════════════════════════════════════════════════════
    //  1 · LAS ETAPAS SALEN DEL COMPOSITOR
    // ══════════════════════════════════════════════════════════════
    echo "\n  — cada estado tiene su etapa, y su turno —\n";
    $vacio = ['revisar' => 0, 'programadas' => 0, 'publicadas' => 0, 'fallidas' => 0,
              'acciones' => 0, 'material' => 0, 'publicando' => 0, 'proxima' => null];
    $casos = [
        'E' => ['preparando', 0], 'F' => ['revisando', 1], 'G' => ['revisando', 1],
        'J' => ['programado', 2], 'K' => ['midiendo', 4],  'L' => ['midiendo', 4],
        'N' => ['completado', 4], 'M' => ['cerrada', 4],   'A' => ['sin_meta', -1],
    ];
    foreach ($casos as $letra => [$esperada, $idx]) {
        $e = ejec_etapa($letra, $vacio);
        ok("el estado {$letra} es «{$esperada}»", $e['etapa'] === $esperada, $e['etapa']);
        ok("y su sitio en la línea es {$idx}",    $e['idx'] === $idx, (string)$e['idx']);
    }
    //  UN ESTADO DESCONOCIDO NO SE DISFRAZA. Antes que enseñar una etapa falsa,
    //  no enseñar ninguna.
    ok('un estado que no se reconoce no inventa etapa',
       ejec_etapa('ZZZ', $vacio)['etapa'] === '', ejec_etapa('ZZZ', $vacio)['etapa']);

    echo "\n  — el plan termina, la Meta no —\n";
    $completado = ejec_etapa('N', $vacio);
    ok('completar el plan no dice que logró la meta',
       str_contains(mb_strtolower($completado['sub']), 'sigue activa'),
       $completado['sub'] . ' — son dos hechos distintos');

    echo "\n  — un fallo manda, pero no es una etapa del camino —\n";
    $conFallo = ejec_etapa('J', ['fallidas' => 1] + $vacio);
    ok('con un fallo, eso es lo que se enseña', $conFallo['etapa'] === 'fallo', $conFallo['etapa']);
    ok('y NO ocupa un sitio en la línea',       $conFallo['idx'] === -1, (string)$conFallo['idx']);
    ok('con la meta cerrada, el fallo no grita',
       ejec_etapa('M', ['fallidas' => 1] + $vacio)['etapa'] === 'cerrada',
       'no hay plan al que devolverla');

    // ══════════════════════════════════════════════════════════════
    //  2 · LAS CIFRAS SALEN DE LAS PIEZAS
    // ══════════════════════════════════════════════════════════════
    echo "\n  — lo que se cuenta es lo que hay —\n";
    $manana = date('Y-m-d H:i:s', strtotime('tomorrow 10:00'));
    $b1 = $pieza('borrador');
    $b2 = $pieza('borrador', null, ['material' => 'foto']);
    $pr = $pieza('programado', $manana);
    $pu = $pieza('publicado', date('Y-m-d H:i:s', strtotime('-2 days 10:00')),
                 ['publicado_at' => date('Y-m-d H:i:s', strtotime('-2 days 10:02'))]);
    $fa = $pieza('fallido', date('Y-m-d H:i:s', strtotime('-1 day 10:00')),
                 ['pub_error' => '[credenciales] (#190) token']);

    $ops = ejec_operacion($pdo, $M, null);
    ok('cuenta las de revisar',    (int)$ops['revisar'] === 2, (string)$ops['revisar']);
    ok('las programadas',          (int)$ops['programadas'] === 1, (string)$ops['programadas']);
    ok('las publicadas',           (int)$ops['publicadas'] === 1, (string)$ops['publicadas']);
    ok('las fallidas',             (int)$ops['fallidas'] === 1, (string)$ops['fallidas']);
    ok('y las que esperan material', (int)$ops['material'] === 1, (string)$ops['material']);
    ok('las acciones del dueño salen del plan', (int)$ops['acciones'] >= 1, (string)$ops['acciones']);

    echo "\n  — y la próxima que sale, con su hora y de dónde vino —\n";
    ok('hay próxima',              !empty($ops['proxima']), json_encode($ops['proxima']));
    ok('es la programada',         (int)($ops['proxima']['id'] ?? 0) === $pr, json_encode($ops['proxima']));
    ok('dice cuándo',              str_contains((string)($ops['proxima']['cuando'] ?? ''), 'mañana'),
       (string)($ops['proxima']['cuando'] ?? ''));
    ok('con la hora de verdad',    str_contains((string)($ops['proxima']['cuando'] ?? ''), '10:00 AM'),
       (string)($ops['proxima']['cuando'] ?? ''));
    ok('y dice que la hizo él',
       (string)($ops['proxima']['origen'] ?? '') === 'Creada por ti',
       (string)($ops['proxima']['origen'] ?? '') . ' — sin meta_id ni tactica_id, es suya');
    //  EL CAPTION ENTERO NO SUBE A LA CAPA PRINCIPAL: esto es una fila.
    ok('el título va recortado',
       mb_strlen((string)($ops['proxima']['titulo'] ?? '')) <= 61,
       (string)($ops['proxima']['titulo'] ?? ''));

    // ══════════════════════════════════════════════════════════════
    //  3 · LA CONSECUENCIA, RECONSTRUIDA DEL ESTADO
    // ══════════════════════════════════════════════════════════════
    echo "\n  — cada decisión dice qué pasó y qué viene —\n";
    $leer = fn(int $id) => $pdo->query("SELECT * FROM crecer_contenido WHERE id={$id}")->fetch(PDO::FETCH_ASSOC);

    $c_pr = ejec_consecuencia($leer($pr));
    ok('aprobar dice cuándo se publica',
       str_contains($c_pr, 'Se publicará') && str_contains($c_pr, '10:00 AM'), $c_pr);
    $c_pu = ejec_consecuencia($leer($pu));
    ok('publicada dice dónde salió y a qué hora',
       str_contains($c_pu, 'Instagram') && str_contains($c_pu, '10:02'), $c_pu);
    ok('y que ahora se esperan resultados',
       str_contains(mb_strtolower($c_pu), 'resultados'), $c_pu);
    $c_fa = ejec_consecuencia($leer($fa));
    ok('fallida dice que el contenido sigue guardado',
       str_contains(mb_strtolower($c_fa), 'sigue guardado'), $c_fa);
    ok('y que hay que revisar la conexión',
       str_contains(mb_strtolower($c_fa), 'conexión'),
       $c_fa . ' — la clase estaba en pub_error');
    //  SIN TRIPAS: la clase vive entre corchetes y ahí se queda.
    ok('sin enseñar el código del proveedor',
       !str_contains($c_fa, '190') && !str_contains(mb_strtolower($c_fa), 'token')
       && !str_contains($c_fa, '['), $c_fa);
    $c_b2 = ejec_consecuencia($leer($b2));
    ok('la que espera material lo dice',
       str_contains(mb_strtolower($c_b2), 'foto'), $c_b2);
    $c_b1 = ejec_consecuencia($leer($b1));
    ok('y la que espera decisión, también',
       str_contains(mb_strtolower($c_b1), 'hasta que decidas'), $c_b1);

    //  NUNCA «Listo». Si hay algo concreto que decir, se dice.
    foreach ([$c_pr, $c_pu, $c_fa, $c_b1, $c_b2] as $frase) {
        if (trim($frase) === 'Listo' || trim($frase) === 'Listo.') {
            ok('ninguna consecuencia dice solo «Listo»', false, $frase);
        }
    }
    ok('ninguna consecuencia dice solo «Listo»', true);

    //  Y SOBREVIVE A LA RECARGA: sale del estado, no de un aviso que pasa.
    ok('la consecuencia se reconstruye igual al releer',
       ejec_consecuencia($leer($pr)) === $c_pr, 'no depende de un toast');

    // ══════════════════════════════════════════════════════════════
    //  4 · LO QUE DICE EL CORILLO EN INICIO
    // ══════════════════════════════════════════════════════════════
    echo "\n  — el corillo habla por consecuencia, no por orden de llegada —\n";
    $etapa = ejec_etapa('F', $ops);
    $msg = ejec_mensajes($pdo, $M, $marca, $BASE, $ops, $etapa);
    ok('tres mensajes como máximo',  count($msg['mensajes']) <= 3, (string)count($msg['mensajes']));
    ok('y hay al menos uno',         count($msg['mensajes']) >= 1);
    //  LO QUE NO SALE SI ÉL NO HACE NADA, PRIMERO.
    ok('lo urgente va delante',      !empty($msg['mensajes'][0]['urgente']),
       json_encode(array_column($msg['mensajes'], 'txt')));
    ok('el primero es el fallo',
       str_contains(mb_strtolower((string)$msg['mensajes'][0]['txt']), 'no pudo salir'),
       (string)$msg['mensajes'][0]['txt']);
    foreach ($msg['mensajes'] as $x) {
        if (trim((string)$x['txt']) === '') { ok('cada mensaje dice algo', false, json_encode($x)); }
    }
    ok('cada mensaje dice algo', true);
    ok('y los que llevan a algún sitio conservan la marca',
       !in_array(false, array_map(
           fn($x) => $x['href'] === '' || str_contains((string)$x['href'], 'marca=' . $M),
           $msg['mensajes']), true),
       json_encode(array_column($msg['mensajes'], 'href')));

    echo "\n  — y se llama como el dueño lo llamó —\n";
    ok('sin bautizo, «Tu corillo»',  $msg['nombre'] === 'Tu corillo', $msg['nombre']);
    $pdo->prepare("UPDATE crecer_marca SET equipo_nombres=? WHERE id=?")
        ->execute([json_encode(['gerente' => 'Luna']), $M]);
    $marca2 = $pdo->query("SELECT * FROM crecer_marca WHERE id={$M}")->fetch(PDO::FETCH_ASSOC);
    ok('con bautizo, su nombre',     ejec_nombre($marca2) === 'Luna', ejec_nombre($marca2));

    // ══════════════════════════════════════════════════════════════
    //  5 · LA MISMA HORA EN TODAS PARTES
    // ══════════════════════════════════════════════════════════════
    echo "\n  — la hora que se enseña es la que va a ocurrir —\n";
    require_once __DIR__ . '/../includes/inicio.php';
    $cal = inicio_calendario($pdo, $M, 5);
    $fila = null;
    foreach ($cal['filas'] as $f) if ((int)$f['id'] === $pr) $fila = $f;
    ok('el adelanto de Inicio trae la pieza', $fila !== null, json_encode($cal['filas']));
    //  Las dos superficies usan formatos distintos («mañana · 10:00 AM») pero
    //  la HORA tiene que ser la misma: si no, el dueño ve dos horas para la
    //  misma publicación.
    ok('y con la misma hora que Tu Meta',
       str_contains((string)($fila['cuando'] ?? ''), '10:00 AM')
       && str_contains((string)$ops['proxima']['cuando'], '10:00 AM'),
       (string)($fila['cuando'] ?? '') . '  vs  ' . (string)$ops['proxima']['cuando']);
    ok('que es la hora guardada',
       date('g:i A', strtotime($manana)) === '10:00 AM', $manana);

    //  EL COSTO SE MIDE AQUÍ, con las marcas todavía vivas y SOLO las de esta
    //  prueba: un cronómetro global sobre `crecer_ia_log` recoge también lo que
    //  haya hecho cualquier otra cosa —abrir La Sala en un navegador, por
    //  ejemplo, que sí llama al modelo— y entonces la afirmación no dice nada.
    $__en = implode(',', array_map('intval', $limpiar));
    $gasto = $__en === '' ? 0.0 : (float)$pdo->query(
        "SELECT COALESCE(SUM(costo_usd),0) FROM crecer_ia_log WHERE marca_id IN ({$__en})")->fetchColumn();

} catch (Throwable $e) {
    $fallos++; $n++;
    echo "  FALLA excepción\n         → " . get_class($e) . ': ' . $e->getMessage()
       . "\n         → " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    foreach ($limpiar as $mid) { try { Fixture::limpiar($pdo, (int)$mid); } catch (Throwable $e) {} }
    echo "\n  (fixture limpiada)\n";
}

echo "\n  — el costo —\n";
ok('leer la ejecución no cuesta un centavo',
   isset($gasto) && $gasto < 0.000001,
   'la ejecución se lee de la base: mirar el trabajo no es rehacerlo'
   . (isset($gasto) ? ' · gastó ' . number_format($gasto, 6) : ' · no se llegó a medir'));

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0 ? "  LA EJECUCIÓN SE VE · {$n} afirmaciones\n\n"
                   : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
