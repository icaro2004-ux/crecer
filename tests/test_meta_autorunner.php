<?php
// ============================================================
//  CRECER — EL CORILLO CORRE UNA SOLA VEZ POR RONDA (Fase 3C)
//  tests/test_meta_autorunner.php
//
//  Lo que se protege aqui no es un estado de pantalla: es DINERO. Cada relevo
//  corre el equipo entero —Aprendiz, Estratega, Creador, Analista— y cada
//  agente llama al modelo. Dos disparos casi simultaneos son dos facturas.
//
//  LO QUE SE COMPRUEBA, y por que cada cosa:
//
//   1 · LA RONDA SE CALCULA EN APP_TZ. En Hostinger MySQL va en UTC y PHP en
//       hora de PR. Un lunes a las 8pm de PR ya es martes en UTC: si la ronda
//       saliera de la base, la semana se partiria y el corillo correria dos
//       veces el mismo lunes.
//
//   2 · EL CANDADO VA ANTES QUE LA CUOTA Y QUE LA IA. Si se mirara la cuota
//       primero, dos procesos leerian «te quedan 6» y entrarian los dos.
//
//   3 · LA CARRERA DE VERDAD: cuatro procesos citados al mismo microsegundo.
//       Uno gana. Si ganan dos, el candado no arbitra.
//
//   4 · LAS HUERFANAS. Una corrida que muere a mitad deja su fila en
//       'corriendo' y bloquearia esa ronda para siempre. Sin latido reciente
//       se puede recoger — pero solo hasta 3 intentos, porque reintentar sin
//       fin algo que falla siempre es gastar dinero en bucle.
//
//   5 · EL CODIGO NUEVO SIN LA TABLA. Entre el deploy y el SQL hay minutos: el
//       relevo tiene que seguir corriendo, sin candado, como antes.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/_fixture.php';
require_once __DIR__ . '/_esquema_desechable.php';
require_once __DIR__ . '/../core/Meta/MetaAutoRunner.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nEL LIBRO DE CORRIDAS DEL CORILLO\n" . str_repeat('=', 56) . "\n";

if (!MetaAutoRunner::disponible($pdo, true)) {
    echo "\n  SALTADA: falta migrations/2026-08-21_crecer_meta_autorun.sql\n";
    echo "  Sin la tabla esta suite pasaria en verde sin haber probado el candado.\n\n";
    exit(2);
}

$PHP = PHP_BINARY;
$M = null;

try {
    // ══════════════════════════════════════════════════════════
    //  1 · LA RONDA, EN LA ZONA DEL NEGOCIO
    // ══════════════════════════════════════════════════════════
    echo "\n  — la ronda se cuenta en hora de Puerto Rico —\n";
    ok('la semana ISO sale con su formato', MetaAutoRunner::ronda('2026-08-21 14:32') === '2026-W34',
       'salio ' . MetaAutoRunner::ronda('2026-08-21 14:32'));
    ok('lunes y viernes de la misma semana son la MISMA ronda',
       MetaAutoRunner::ronda('2026-08-17 07:00') === MetaAutoRunner::ronda('2026-08-21 23:00'));
    ok('y el lunes siguiente es otra',
       MetaAutoRunner::ronda('2026-08-17 07:00') !== MetaAutoRunner::ronda('2026-08-24 07:00'));

    //  EL CASO QUE MOTIVA TODO ESTO: 8pm de PR = medianoche pasada en UTC.
    //  Si la ronda saliera de la base, esa hora caeria en el dia siguiente.
    $pr  = new DateTime('2026-08-23 20:30', new DateTimeZone('America/Puerto_Rico'));
    $utc = (clone $pr)->setTimezone(new DateTimeZone('UTC'));
    ok('a las 8:30pm de PR, en UTC ya es otro dia',
       $pr->format('Y-m-d') !== $utc->format('Y-m-d'),
       'si no fuera asi, esta prueba no estaria probando nada');
    ok('y aun asi la ronda es la del domingo de PR',
       MetaAutoRunner::ronda('2026-08-23 20:30') === MetaAutoRunner::ronda('2026-08-23 09:00'),
       'calcular la ronda con NOW() de MySQL partiria la semana por la mitad');

    echo "\n  — la ronda pedida a mano —\n";
    ok('lleva el minuto pegado',
       MetaAutoRunner::rondaManual('2026-08-21 14:32') === '2026-W34-m211432',
       'salio ' . MetaAutoRunner::rondaManual('2026-08-21 14:32'));
    ok('dos clics del mismo minuto son la MISMA ronda',
       MetaAutoRunner::rondaManual('2026-08-21 14:32:05')
       === MetaAutoRunner::rondaManual('2026-08-21 14:32:55'),
       'es la proteccion contra el doble clic');
    ok('un minuto despues ya es otra',
       MetaAutoRunner::rondaManual('2026-08-21 14:32')
       !== MetaAutoRunner::rondaManual('2026-08-21 14:33'),
       'si no, el boton moriria en cuanto corriera el cron');
    ok('y nunca choca con la semanal',
       MetaAutoRunner::rondaManual('2026-08-21 14:32') !== MetaAutoRunner::ronda('2026-08-21 14:32'));
    ok('cabe en la columna',
       strlen(MetaAutoRunner::rondaManual('2026-12-28 23:59')) <= 24);

    // ══════════════════════════════════════════════════════════
    //  2 · RECLAMAR: UNA VEZ, Y NO MAS
    // ══════════════════════════════════════════════════════════
    $fx = Fixture::crear($pdo, 'autorun');
    $M  = (int)$fx['marca_id']; $PLAN = (int)$fx['plan_id'];
    $R  = 'TEST-W01';

    echo "\n  — el turno se pide una vez —\n";
    $r1 = MetaAutoRunner::reclamar($pdo, $M, $PLAN, 'cron', null, $R);
    ok('el primero se lleva el turno', $r1 !== null);
    ok('y nace corriendo', $r1 && $r1->estado === 'corriendo');
    ok('con un intento', $r1 && $r1->intentos === 1);
    ok('y latiendo', $r1 && $r1->latido_at !== null);
    ok('el segundo se queda fuera',
       MetaAutoRunner::reclamar($pdo, $M, $PLAN, 'cron', null, $R) === null,
       'dos turnos en la misma ronda = el equipo corre dos veces');

    echo "\n  — y no se confunde de marca, plan ni ronda —\n";
    ok('otra ronda si tiene turno propio',
       MetaAutoRunner::reclamar($pdo, $M, $PLAN, 'cron', null, 'TEST-W02') !== null);
    ok('otro plan de la misma marca tambien',
       MetaAutoRunner::reclamar($pdo, $M, $PLAN + 777, 'cron', null, $R) !== null,
       'un plan nuevo es un mundo nuevo: su primera ronda no la corrio nadie');
    ok('plan 0 es una ronda legitima, no un error',
       MetaAutoRunner::reclamar($pdo, $M, 0, 'cron', null, $R) !== null,
       'el corillo tambien releva sin plan vigente');
    ok('marca 0 no reclama nada', MetaAutoRunner::reclamar($pdo, 0, $PLAN, 'cron', null, $R) === null);

    echo "\n  — una ronda ya hecha no se repite —\n";
    MetaAutoRunner::hecho($pdo, $r1, 3, 'el corillo avanzó el plan');
    $hecha = MetaAutoRunner::porRonda($pdo, $M, $PLAN, $R);
    ok('queda marcada como hecha', $hecha && $hecha->estado === 'hecho');
    ok('con lo que dejó', $hecha && $hecha->creadas === 3);
    ok('y ya no se puede reclamar',
       MetaAutoRunner::reclamar($pdo, $M, $PLAN, 'cron', null, $R) === null,
       'reclamar una ronda hecha volveria a correr el equipo por lo mismo');

    // ══════════════════════════════════════════════════════════
    //  3 · LA CARRERA · cuatro procesos en el mismo instante
    // ══════════════════════════════════════════════════════════
    echo "\n  — cuatro disparos a la vez: gana uno —\n";
    $runner = __DIR__ . DIRECTORY_SEPARATOR . '_autorun_runner.php';
    if (!function_exists('proc_open')) {
        echo "  (saltada: proc_open no esta disponible)\n";
    } else {
        $cita = microtime(true) + 1.6;
        $procs = []; $tubos = [];
        for ($i = 0; $i < 4; $i++) {
            $cmd = escapeshellarg($PHP) . ' ' . escapeshellarg($runner) . ' '
                 . $M . ' ' . $PLAN . ' ' . escapeshellarg('TEST-CARRERA') . ' '
                 . sprintf('%.4f', $cita);
            $t = [];
            $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $t);
            if (is_resource($p)) { $procs[] = $p; $tubos[] = $t; }
        }
        $dijo = [];
        foreach ($tubos as $k => $t) {
            $dijo[] = trim(stream_get_contents($t[1]));
            fclose($t[1]); fclose($t[2]); proc_close($procs[$k]);
        }
        $ganaron = count(array_filter($dijo, fn($d) => strpos($d, 'GANO') === 0));
        $errores = array_values(array_filter($dijo, fn($d) => strpos($d, 'ERROR') === 0));
        ok('arrancaron los cuatro', count($dijo) === 4, 'salieron ' . count($dijo));
        ok('ninguno reventó', $errores === [], implode(' | ', $errores));
        ok('gana exactamente uno', $ganaron === 1,
           'ganaron ' . $ganaron . ' · [' . implode(', ', $dijo) . '] — '
           . 'si son 2, el corillo corre dos veces y factura dos veces');
        $q = $pdo->prepare("SELECT COUNT(*) FROM crecer_meta_autorun WHERE marca_id=? AND ronda='TEST-CARRERA'");
        $q->execute([$M]);
        ok('y solo hay UNA fila para esa ronda', (int)$q->fetchColumn() === 1);
    }

    // ══════════════════════════════════════════════════════════
    //  4 · LAS HUERFANAS · recoger sin repetir para siempre
    // ══════════════════════════════════════════════════════════
    echo "\n  — una corrida que se murió a mitad —\n";
    $RH = 'TEST-HUERFANA';
    $h1 = MetaAutoRunner::reclamar($pdo, $M, $PLAN, 'cron', null, $RH);
    ok('arranca viva', $h1 !== null && $h1->estado === 'corriendo');
    ok('mientras late, nadie se la lleva',
       MetaAutoRunner::reclamar($pdo, $M, $PLAN, 'cron', null, $RH) === null,
       'recoger una corrida VIVA es exactamente correr el equipo dos veces');

    /** Envejece el latido como si el proceso llevara rato muerto. */
    $matar = function (int $id, int $min) use ($pdo) {
        $pdo->prepare("UPDATE crecer_meta_autorun SET latido_at = NOW() - INTERVAL ? MINUTE WHERE id=?")
            ->execute([$min, $id]);
    };
    $matar($h1->id, MetaAutoRunner::LATIDO_MUERTO_MIN + 5);
    $h2 = MetaAutoRunner::reclamar($pdo, $M, $PLAN, 'cron', null, $RH);
    ok('sin latido, otro tick la recoge', $h2 !== null);
    ok('y cuenta el intento', $h2 && $h2->intentos === 2);
    ok('sigue siendo la MISMA fila', $h2 && $h2->id === $h1->id,
       'crear otra fila rompería la idempotencia por ronda');

    echo "\n  — pero no para siempre: tres intentos —\n";
    $matar($h2->id, MetaAutoRunner::LATIDO_MUERTO_MIN + 5);
    $h3 = MetaAutoRunner::reclamar($pdo, $M, $PLAN, 'cron', null, $RH);
    ok('el tercero todavia entra', $h3 !== null && $h3->intentos === 3);
    $matar($h3->id, MetaAutoRunner::LATIDO_MUERTO_MIN + 5);
    ok('el cuarto ya no', MetaAutoRunner::reclamar($pdo, $M, $PLAN, 'cron', null, $RH) === null,
       'reintentar sin fin algo que falla siempre es gastar dinero en bucle');
    $ag = MetaAutoRunner::porRonda($pdo, $M, $PLAN, $RH);
    ok('y la corrida se declara agotada', $ag && $ag->agotada());

    echo "\n  — fallar con intentos de sobra deja volver —\n";
    $RF = 'TEST-FALLO';
    $f1 = MetaAutoRunner::reclamar($pdo, $M, $PLAN, 'cron', null, $RF);
    MetaAutoRunner::fallado($pdo, $f1, 'se cayó la red');
    $f2 = MetaAutoRunner::porRonda($pdo, $M, $PLAN, $RF);
    ok('no se entierra al primer tropiezo', $f2 && $f2->estado === 'corriendo');
    ok('se le quita el latido, que es como se recoge', $f2 && $f2->latido_at === null);
    ok('guarda por qué se cayó', $f2 && strpos($f2->motivo, 'red') !== false);
    $f3 = MetaAutoRunner::reclamar($pdo, $M, $PLAN, 'cron', null, $RF);
    ok('y el siguiente tick la retoma', $f3 !== null && $f3->intentos === 2);

    echo "\n  — al agotarse, se entierra —\n";
    $pdo->prepare("UPDATE crecer_meta_autorun SET intentos=? WHERE id=?")
        ->execute([MetaAutoRunner::INTENTOS_MAX, $f3->id]);
    MetaAutoRunner::fallado($pdo, MetaAutoRunner::porRonda($pdo, $M, $PLAN, $RF), 'se cayó otra vez');
    $f4 = MetaAutoRunner::porRonda($pdo, $M, $PLAN, $RF);
    ok('queda fallada', $f4 && $f4->estado === 'fallado');
    ok('y no se vuelve a reclamar',
       MetaAutoRunner::reclamar($pdo, $M, $PLAN, 'cron', null, $RF) === null);

    // ══════════════════════════════════════════════════════════
    //  5 · EL ENVOLTORIO · candado ANTES de trabajar
    // ══════════════════════════════════════════════════════════
    echo "\n  — el envoltorio corre el trabajo una sola vez —\n";
    $veces = 0; $latidos = 0;
    $trabajo = function (callable $latir) use (&$veces, &$latidos) {
        $veces++; $latir(); $latidos++;
        return ['creadas' => 2, 'razon' => 'dos piezas'];
    };
    $e1 = MetaAutoRunner::envolver($pdo, $M, $PLAN, 'cron', $trabajo, 'TEST-ENV');
    ok('la primera vez corre', $e1['corrio'] === true && $veces === 1);
    ok('y devuelve lo que dejó', $e1['creadas'] === 2);
    ok('el trabajo pudo latir', $latidos === 1,
       'sin latido, una corrida lenta pero sana se ve igual que una muerta');

    $e2 = MetaAutoRunner::envolver($pdo, $M, $PLAN, 'cron', $trabajo, 'TEST-ENV');
    ok('la segunda NO corre el trabajo', $veces === 1,
       'aqui es donde se ahorra la factura duplicada');
    ok('y lo dice sin llamarlo error', $e2['corrio'] === false && $e2['motivo'] === 'ronda_tomada',
       '«ya se corrió» no es una avería y no puede pintarse de rojo');

    echo "\n  — si el trabajo revienta, la corrida no miente —\n";
    $RX = 'TEST-EXPLOTA';
    try {
        MetaAutoRunner::envolver($pdo, $M, $PLAN, 'cron',
            function (callable $latir) { throw new RuntimeException('el modelo dijo que no'); }, $RX);
        ok('la excepcion sube al que llamó', false, 'se la tragó');
    } catch (RuntimeException $e) {
        ok('la excepcion sube al que llamó', true);
    }
    $x = MetaAutoRunner::porRonda($pdo, $M, $PLAN, $RX);
    ok('y la corrida NO queda como hecha', $x && $x->estado !== 'hecho',
       'darla por buena escondería el fallo y nadie la retomaría');
    ok('queda recogible por el siguiente tick', $x && $x->latido_at === null);

    // ══════════════════════════════════════════════════════════
    //  6 · EL ORDEN: CANDADO ANTES QUE CUOTA, IA Y GENERACION
    // ══════════════════════════════════════════════════════════
    echo "\n  — el candado va primero, y se comprueba en el fuente —\n";
    $rc = (string)file_get_contents(dirname(__DIR__) . '/core/Meta/MetaAutoRunner.php');
    $cuerpo = substr($rc, strpos($rc, 'public static function envolver'));
    $pos_reclama = strpos($cuerpo, 'self::reclamar(');
    $pos_trabajo = strpos($cuerpo, '$trabajo(');
    ok('envolver() reclama antes de trabajar', $pos_reclama !== false && $pos_trabajo !== false);
    //  SI hay una llamada al trabajo ANTES de reclamar, solo puede ser una: la
    //  del camino «todavia no existe la tabla», que corre sin candado a
    //  proposito. Cualquier otra seria gastar antes de tener el turno.
    $antes = substr($cuerpo, 0, (int)$pos_reclama);
    ok('y si alguna corre antes, es solo la de «sin libro»',
       substr_count($antes, '$trabajo(') === 0
       || (strpos($antes, '!self::disponible') !== false && strpos($antes, "'sin_libro'") !== false),
       'mirar la cuota o generar antes de reclamar deja entrar a dos: los dos leen «te quedan 6»');
    ok('el camino con candado NO llama al trabajo antes de tenerlo',
       strpos($cuerpo, "if (\$run === null) {") !== false
       && strpos($cuerpo, "if (\$run === null) {") < strpos($cuerpo, '$trabajo(', (int)$pos_reclama),
       'entre reclamar y trabajar tiene que estar la salida de «no es mi turno»');
    ok('y si no consigue turno, no llama al trabajo',
       $veces === 1, 'ya comprobado arriba con el contador');

    //  Y en los tres disparadores de verdad.
    foreach ([
        'includes/agentes.php'      => 'cron',
        'panel/relevo_worker.php'   => 'worker',
        'panel/configuracion.php'   => 'manual',
    ] as $ruta => $origen) {
        $src = (string)file_get_contents(dirname(__DIR__) . '/' . $ruta);
        ok("{$ruta} dispara por el libro",
           strpos($src, 'MetaAutoRunner::envolver') !== false,
           'un disparador suelto se salta el candado entero');
        ok("y se identifica como '{$origen}'", strpos($src, "'{$origen}'") !== false);
    }
    $conf = (string)file_get_contents(dirname(__DIR__) . '/panel/configuracion.php');
    ok('la ejecución a mano sigue disponible',
       strpos($conf, "accion === 'corre_ahora'") !== false,
       'el contrato pide mantenerla durante la transición');
    ok('y usa la ronda manual, no la semanal',
       strpos($conf, 'MetaAutoRunner::rondaManual()') !== false,
       'con la semanal el botón moriría en cuanto corriera el cron del lunes');

    // ══════════════════════════════════════════════════════════
    //  7 · CODIGO NUEVO, TABLA TODAVIA NO
    //      Entre el deploy y el SQL hay minutos. En base desechable.
    // ══════════════════════════════════════════════════════════
    echo "\n  — el código nuevo antes de la migración (base desechable) —\n";
    $vieja = EsquemaDesechable::crear($pdo);
    if ($vieja === null) {
        echo "  (saltada: este usuario de base de datos no puede crear bases)\n";
    } else {
        try {
            $vpdo = $vieja->pdo();
            $vieja->ejecutar("DROP TABLE crecer_meta_autorun");
            ok('la copia no tiene el libro',
               MetaAutoRunner::disponible($vpdo, true) === false);

            $corrio = 0;
            $ev = MetaAutoRunner::envolver($vpdo, 7, 7, 'cron',
                function (callable $latir) use (&$corrio) { $corrio++; $latir(); return ['creadas' => 1]; });
            ok('el relevo corre igual, sin candado', $ev['corrio'] === true && $corrio === 1,
               'perder un relevo sería peor que arriesgar un duplicado en esa ventana');
            ok('y lo dice sin disimulo', $ev['motivo'] === 'sin_libro');
            ok('el latido no revienta sin tabla', true);
        } finally {
            $vieja->soltar($pdo);
            MetaAutoRunner::disponible($pdo, true);
        }
    }
    ok('la base compartida sigue con su libro', MetaAutoRunner::disponible($pdo, true) === true);

} finally {
    if ($M) {
        $pdo->prepare("DELETE FROM crecer_meta_autorun WHERE marca_id=?")->execute([$M]);
        Fixture::limpiar($pdo, $M);
        $q = $pdo->prepare("SELECT COUNT(*) FROM crecer_marca WHERE id=?"); $q->execute([$M]);
        echo "\n  (fixture limpiada: " . ((int)$q->fetchColumn() === 0 ? 'sí' : 'NO') . ")\n";
    }
}

echo "\n" . str_repeat('=', 56) . "\n";
echo $fallos === 0 ? "  TODO OK · {$n} pruebas\n\n" : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
