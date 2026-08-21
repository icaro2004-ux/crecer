<?php
// ============================================================
//  CRECER — EL PLAN SE PRESENTA UNA SOLA VEZ (Fase 3B)
//  tests/test_meta_presentacion.php
//
//  El estado C existia en el compositor desde la Fase 1, inerte: no habia
//  columna que dijera si al dueno ya se le habia ensenado su camino. Esta suite
//  comprueba que al encenderlo no se rompe nada y que el sello aguanta lo que
//  de verdad pasa en produccion.
//
//  LOS CINCO MUNDOS QUE HAY QUE CUBRIR, y por que cada uno:
//
//   1 · CODIGO NUEVO · ESQUEMA VIEJO   El deploy y el SQL no ocurren a la vez.
//       Entre uno y otro hay minutos en que este codigo corre sin la columna.
//       Se prueba contra un esquema viejo DE VERDAD —no un mock— pero en una
//       BASE DESECHABLE que nace y muere con la prueba. Nunca sobre la base
//       compartida: un DROP COLUMN no se deshace, y el DDL ademas hace COMMIT
//       implicito de lo que hubiera en vuelo.
//
//   2 · ESQUEMA NUEVO · CODIGO ANTERIOR  Si la migracion entra primero, el
//       codigo que ya corre no puede enterarse. Un INSERT posicional —sin lista
//       de columnas— se rompe el dia que aparece una columna nueva.
//
//   3 · PERTENENCIA   El id del plan viaja en un POST. Cualquiera puede
//       cambiarlo: presentar el plan de otra marca no puede tener efecto.
//
//   4 · VIGENCIA      Un plan reemplazado ya no se presenta. Su momento paso.
//
//   5 · IDEMPOTENCIA Y CARRERA  El movil lento hace doble clic. Dos procesos a
//       la vez leen y escriben; si las condiciones no van dentro del UPDATE,
//       ganan los dos.
//
//  Y, al final, la PANTALLA: que el estado C se pinta con su resumen, que el
//  boton escribe, y que al volver la pantalla ya muestra otra cosa.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/_fixture.php';
require_once __DIR__ . '/_esquema_desechable.php';
require_once __DIR__ . '/../includes/meta_negocio.php';
require_once __DIR__ . '/../core/Meta/MetaState.php';
require_once __DIR__ . '/../core/Meta/MetaSnapshotReader.php';
require_once __DIR__ . '/../core/Meta/MetaStateComposer.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nLA PRESENTACION DEL PLAN\n" . str_repeat('=', 56) . "\n";

$PHP = PHP_BINARY;

/** El estado dominante de una marca, recompuesto desde la base. */
$estado_de = function (int $marca_id) use ($pdo) {
    return MetaStateComposer::componer(MetaSnapshotReader::leer($pdo, $marca_id));
};
/** El sello tal y como esta guardado ahora mismo. */
$sello_de = function (int $plan_id) use ($pdo) {
    $q = $pdo->prepare("SELECT presentado_at FROM crecer_meta_plan WHERE id=?");
    $q->execute([$plan_id]);
    $v = $q->fetchColumn();
    return $v === false ? '(sin fila)' : $v;
};

// La migracion tiene que estar puesta para que esta suite signifique algo.
$hay_columna = meta_plan_col_presentado($pdo, true);
if (!$hay_columna) {
    echo "\n  SALTADA: falta migrations/2026-08-20_crecer_plan_presentado.sql\n";
    echo "  Corre la migracion y vuelve. Sin la columna esta suite no puede\n";
    echo "  afirmar nada: pasaria en verde sin haber probado el sello.\n\n";
    exit(2);
}

$A = null; $B = null;
try {
    //  rol admin en la que se va a RENDERIZAR: el candado del paywall
    //  (includes/panel_guard.php) desvia a un proveedor sin suscripcion y la
    //  peticion se va en un redirect sin cuerpo. La prueba veria una pagina
    //  vacia y no sabria por que.
    $fa = Fixture::crear($pdo, 'presenta-a', true, 'admin');
    $A  = (int)$fa['marca_id']; $PLAN_A = (int)$fa['plan_id']; $UA = (int)$fa['usuario_id'];
    $fb = Fixture::crear($pdo, 'presenta-b');
    $B  = (int)$fb['marca_id']; $PLAN_B = (int)$fb['plan_id'];

    // ══════════════════════════════════════════════════════════
    //  0 · EL PLAN NACE SIN PRESENTAR, Y ESO ES EL ESTADO C
    // ══════════════════════════════════════════════════════════
    echo "\n  — un plan recien nacido —\n";
    Fixture::sinPresentar($pdo, $A, $PLAN_A);
    $e = $estado_de($A);
    ok('sin sello, el estado dominante es C', $e->estado === MetaState::C_PLAN_POR_VER,
       "salio {$e->estado} · {$e->razon}");
    ok('y lo dice por la razon correcta', $e->razon === 'plan_sin_presentar');
    ok('la accion se llama Empezar', ($e->accion['etiqueta'] ?? '') === 'Empezar',
       'el contrato §C pide esa palabra, no «Ver lo primero»');
    ok('y va marcada como presentacion', ($e->accion['tipo'] ?? '') === 'presentacion');

    echo "\n  — el resumen que decide (contrato §C) —\n";
    $ev = $e->evidencia;
    ok('viaja el plan que se va a sellar', (int)($ev['plan_id'] ?? 0) === $PLAN_A);
    ok('se dice de cuanto me encargo yo', (int)($ev['hago_yo'] ?? -1) === 3,
       'la fixture deja 3 vivas del corillo · salio ' . ($ev['hago_yo'] ?? '?'));
    ok('y cuanto se le va a pedir a el', (int)($ev['te_pido'] ?? -1) === 2,
       'inversion + fisica · salio ' . ($ev['te_pido'] ?? '?'));
    ok('con los nombres de lo que se le pide', count((array)($ev['pide'] ?? [])) === 2);
    ok('lo hecho no se promete otra vez',
       !in_array('Paso de relleno 1', (array)($ev['pide'] ?? []), true) &&
       (int)($ev['hago_yo'] ?? 0) + (int)($ev['te_pido'] ?? 0) === 5,
       'la fixture trae 6 jugadas, una ya hecha');
    ok('el camino NO se pinta entero aqui',
       is_array($e->camino) && count((array)($e->camino['proximos'] ?? [])) <= 3,
       'el contrato §C dice: no mostrar aqui todas las jugadas. El camino trae los
        tres numeros y, como mucho, TRES proximos: mas alla de eso ya no es «lo que
        sigue», es el plan — y el plan tiene su vista.');

    // ══════════════════════════════════════════════════════════
    //  5a · IDEMPOTENCIA · el segundo clic no hace nada
    // ══════════════════════════════════════════════════════════
    echo "\n  — se presenta una sola vez —\n";
    ok('el primer Empezar sella', meta_plan_presentar($pdo, $PLAN_A, $A) === true);
    $sello1 = $sello_de($PLAN_A);
    ok('y queda escrito', $sello1 !== null && $sello1 !== '(sin fila)');
    ok('el segundo no sella', meta_plan_presentar($pdo, $PLAN_A, $A) === false,
       'devolver true otra vez contaria dos presentaciones del mismo plan');
    ok('y no le mueve la fecha', $sello_de($PLAN_A) === $sello1,
       'un segundo UPDATE reescribiria presentado_at y perderia cuando fue de verdad');

    echo "\n  — y la pantalla ya muestra otra cosa —\n";
    $e2 = $estado_de($A);
    ok('el estado deja de ser C', $e2->estado !== MetaState::C_PLAN_POR_VER,
       "sigue en {$e2->estado}");
    ok('y pasa a la tarea real', $e2->estado === MetaState::F_APROBACION,
       "la fixture deja una pieza en borrador · salio {$e2->estado} · {$e2->razon}");

    // ══════════════════════════════════════════════════════════
    //  3 · PERTENENCIA · el id del plan viaja en un POST
    // ══════════════════════════════════════════════════════════
    echo "\n  — el plan de otra marca no se toca —\n";
    Fixture::sinPresentar($pdo, $B, $PLAN_B);
    ok('B empieza sin sello', $sello_de($PLAN_B) === null);
    ok('A no puede presentar el plan de B', meta_plan_presentar($pdo, $PLAN_B, $A) === false);
    ok('y el plan de B sigue sin sello', $sello_de($PLAN_B) === null,
       'sin AND marca_id en el WHERE, un POST manipulado sellaba el plan ajeno');
    ok('B si puede presentar el suyo', meta_plan_presentar($pdo, $PLAN_B, $B) === true);

    echo "\n  — ids imposibles —\n";
    ok('plan 0 no hace nada', meta_plan_presentar($pdo, 0, $A) === false);
    ok('marca 0 tampoco', meta_plan_presentar($pdo, $PLAN_A, 0) === false);
    ok('un plan que no existe tampoco', meta_plan_presentar($pdo, 999999999, $A) === false);
    ok('y un negativo no revienta', meta_plan_presentar($pdo, -1, -1) === false);

    // ══════════════════════════════════════════════════════════
    //  4 · VIGENCIA · un plan historico ya no se presenta
    // ══════════════════════════════════════════════════════════
    echo "\n  — un plan que ya fue reemplazado —\n";
    $pdo->prepare("UPDATE crecer_meta_plan SET presentado_at=NULL, estado='reemplazado' WHERE id=? AND marca_id=?")
        ->execute([$PLAN_A, $A]);
    ok('no se presenta aunque sea tuyo y este sin sellar',
       meta_plan_presentar($pdo, $PLAN_A, $A) === false);
    ok('y sigue sin sello', $sello_de($PLAN_A) === null,
       'presentar un plan muerto ensenaria un camino que ya no se va a andar');
    $e3 = $estado_de($A);
    ok('sin plan vigente, C no aparece', $e3->estado !== MetaState::C_PLAN_POR_VER,
       "salio {$e3->estado} · {$e3->razon}");
    $pdo->prepare("UPDATE crecer_meta_plan SET estado='activo', presentado_at=NULL WHERE id=?")
        ->execute([$PLAN_A]);

    // ══════════════════════════════════════════════════════════
    //  5b · LA CARRERA · dos peticiones en el mismo instante
    // ══════════════════════════════════════════════════════════
    echo "\n  — doble clic de verdad: 4 procesos a la vez —\n";
    $runner = __DIR__ . DIRECTORY_SEPARATOR . '_presentar_runner.php';
    if (!function_exists('proc_open')) {
        echo "  (saltada: proc_open no esta disponible)\n";
    } else {
        $cita = microtime(true) + 1.6;      // margen para que arranquen los 4
        $procs = []; $tubos = [];
        for ($i = 0; $i < 4; $i++) {
            $cmd = escapeshellarg($PHP) . ' ' . escapeshellarg($runner) . ' '
                 . $PLAN_A . ' ' . $A . ' ' . sprintf('%.4f', $cita);
            $t = [];
            $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $t);
            if (is_resource($p)) { $procs[] = $p; $tubos[] = $t; }
        }
        $dijo = [];
        foreach ($tubos as $k => $t) {
            $dijo[] = trim(stream_get_contents($t[1]));
            fclose($t[1]); fclose($t[2]); proc_close($procs[$k]);
        }
        $ganaron = count(array_filter($dijo, fn($d) => $d === 'GANO'));
        $errores = array_values(array_filter($dijo, fn($d) => strpos($d, 'ERROR') === 0));
        ok('arrancaron los cuatro', count($dijo) === 4, 'salieron ' . count($dijo));
        ok('ninguno reventó', $errores === [], implode(' | ', $errores));
        ok('gana exactamente uno', $ganaron === 1,
           'ganaron ' . $ganaron . ' · [' . implode(', ', $dijo) . '] — '
           . 'si son 2, el UPDATE no arbitra y la presentacion se cuenta doble');
        ok('y el plan quedo sellado una vez', $sello_de($PLAN_A) !== null);
    }

    // ══════════════════════════════════════════════════════════
    //  2 · ESQUEMA NUEVO · CODIGO ANTERIOR
    //      La migracion puede entrar antes que el deploy. El codigo
    //      que ya corre no nombra la columna en ningun sitio: tiene
    //      que seguir funcionando sin enterarse.
    // ══════════════════════════════════════════════════════════
    echo "\n  — la migracion puesta, el codigo viejo corriendo —\n";
    $fuentes = [];
    foreach (['includes', 'panel', 'core', 'scripts'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            dirname(__DIR__) . DIRECTORY_SEPARATOR . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $ruta) {
            if (substr((string)$ruta, -4) === '.php') $fuentes[] = (string)$ruta;
        }
    }
    $posicional = [];
    foreach ($fuentes as $ruta) {
        $txt = (string)file_get_contents($ruta);
        // INSERT INTO crecer_meta_plan VALUES (...)  — sin lista de columnas.
        if (preg_match('/INSERT\s+INTO\s+crecer_meta_plan\s+(VALUES|SELECT)/i', $txt)) {
            $posicional[] = basename($ruta);
        }
    }
    ok('ningun INSERT del plan es posicional', $posicional === [],
       'una columna nueva rompe INSERT ... VALUES sin lista: ' . implode(', ', $posicional));

    //  Y las cuatro funciones que el codigo anterior usa sobre esta tabla,
    //  ejecutadas de verdad contra el esquema nuevo.
    $meta_b = meta_activa($pdo, $B);
    ok('meta_plan_activo sigue devolviendo el plan',
       (int)(meta_plan_activo($pdo, (int)$meta_b['id'])['id'] ?? 0) === $PLAN_B);
    ok('meta_planes sigue devolviendo el historial',
       count(meta_planes($pdo, (int)$meta_b['id'])) >= 1);
    ok('un plan nacido sin nombrar la columna nace en NULL',
       (function () use ($pdo, $B, $meta_b) {
           $pdo->prepare("INSERT INTO crecer_meta_plan (meta_id, marca_id, version, diagnostico, veredicto, estado, ia_log_id)
                          VALUES (?,?,?,?,?, 'activo', ?)")
               ->execute([(int)$meta_b['id'], $B, 99, 'Texto de relleno.', 'ok', null]);
           $id = (int)$pdo->lastInsertId();
           $q = $pdo->prepare("SELECT presentado_at FROM crecer_meta_plan WHERE id=?");
           $q->execute([$id]);
           $v = $q->fetchColumn();
           $pdo->prepare("DELETE FROM crecer_meta_plan WHERE id=? AND marca_id=?")->execute([$id, $B]);
           return $v === null;
       })(),
       'NULL = «todavia no se le ha ensenado», que es lo correcto para los planes que ya existian');

    // ══════════════════════════════════════════════════════════
    //  1 · CODIGO NUEVO · ESQUEMA VIEJO
    //      En una BASE DESECHABLE, no en la compartida.
    //
    //      La primera version de esta prueba quitaba la columna de la base
    //      local y la reponia en un finally. Eso esta prohibido y con razon:
    //      un DROP COLUMN no se deshace —el finally repone la COLUMNA, jamas
    //      los VALORES— y ademas el DDL hace COMMIT implicito, de modo que
    //      cualquier prueba en transaccion se veria confirmada a medias.
    //
    //      Aqui se clona la estructura en una base propia que nace y muere con
    //      la prueba, y es a ESA copia a la que se le quita la columna. El
    //      esquema viejo sigue siendo de verdad; lo que ya no se arriesga es
    //      nada de nadie.
    // ══════════════════════════════════════════════════════════
    echo "\n  — el codigo nuevo, sin la columna (base desechable) —\n";
    $vieja = EsquemaDesechable::crear($pdo);
    if ($vieja === null) {
        echo "  (saltada: este usuario de base de datos no puede crear bases)\n";
        echo "  NO se toca el esquema compartido para no saltarsela.\n";
    } else {
        try {
            $vpdo = $vieja->pdo();
            $vieja->ejecutar("ALTER TABLE crecer_meta_plan DROP COLUMN presentado_at");
            ok('la copia quedo con la forma de ANTES de la migracion',
               $vpdo->query("SHOW COLUMNS FROM crecer_meta_plan LIKE 'presentado_at'")->fetch() === false,
               'sin esto, lo de abajo probaria el esquema nuevo creyendo probar el viejo');

            //  Una marca completa sembrada DENTRO de la copia. La fixture solo
            //  inserta —no lee tablas semilla— asi que funciona igual aqui.
            $fv = Fixture::crear($vpdo, 'esquema-viejo');
            $MV = (int)$fv['marca_id']; $PV = (int)$fv['plan_id'];

            //  La guarda cachea por proceso: hay que refrescarla al cambiar de
            //  conexion o respondera por la base equivocada.
            ok('la guarda se entera de que no esta',
               meta_plan_col_presentado($vpdo, true) === false);
            ok('presentar no revienta, solo dice que no',
               meta_plan_presentar($vpdo, $PV, $MV) === false);

            $snap = MetaSnapshotReader::leer($vpdo, $MV);
            ok('el lector encuentra el plan igual',
               (int)($snap['plan']['id'] ?? 0) === $PV,
               'si no lo encontrara, lo de abajo pasaria por vacio y no por inerte');
            ok('el snapshot no inventa la clave',
               !array_key_exists('presentado_at', (array)($snap['plan'] ?? [])),
               'si la pusiera en null, el estado C se dispararia sin columna que sellar');
            $ev = MetaStateComposer::componer($snap);
            ok('y el estado C se queda inerte', $ev->estado !== MetaState::C_PLAN_POR_VER,
               "salio {$ev->estado} · {$ev->razon}");
            ok('la pantalla sigue teniendo algo que decir',
               trim($ev->titulo) !== '' && $ev->estado !== '',
               'sin columna la pantalla no puede quedarse en blanco');
        } finally {
            $vieja->soltar($pdo);
            meta_plan_col_presentado($pdo, true);   // la guarda vuelve a la base de verdad
        }
        ok('la base desechable ya no existe',
           $pdo->query("SHOW DATABASES LIKE '" . EsquemaDesechable::PREFIJO . "%'")->fetch() === false);
    }
    ok('el esquema COMPARTIDO sigue intacto',
       meta_plan_col_presentado($pdo, true) === true,
       'ninguna prueba puede dejar la base local peor de como la encontro');

    // ══════════════════════════════════════════════════════════
    //  LA PANTALLA · pedida como la pediria un navegador
    // ══════════════════════════════════════════════════════════
    echo "\n  — la pantalla de verdad —\n";
    $RUNNER = __DIR__ . DIRECTORY_SEPARATOR . '_render_runner.php';
    $pedir = function (string $query, string $metodo = 'GET', string $post = '')
             use ($PHP, $RUNNER, $UA): string {
        $cmd = escapeshellarg($PHP) . ' ' . escapeshellarg($RUNNER) . ' ' . $UA . ' '
             . escapeshellarg('meta.php') . ' ' . escapeshellarg($query) . ' '
             . escapeshellarg($metodo) . ' ' . escapeshellarg($post) . ' 2>&1';
        $sal = []; exec($cmd, $sal);
        return implode("\n", $sal);
    };

    $pdo->prepare("UPDATE crecer_meta_plan SET presentado_at=NULL WHERE id=? AND marca_id=?")
        ->execute([$PLAN_A, $A]);
    $html = $pedir('marca=' . $A);
    ok('sin sello, la pantalla dice que el camino esta listo',
       strpos($html, 'Tu camino está listo') !== false);
    ok('y el boton dice Empezar', strpos($html, '>Empezar</button>') !== false,
       'tiene que ser <button>: un <a> lo dispara el prefetch del navegador');
    ok('con el plan a sellar dentro', strpos($html, 'data-plan="' . $PLAN_A . '"') !== false);
    ok('se ensena el reparto del trabajo', strpos($html, '<div class="tm-reparto">') !== false);
    ok('con lo que me toca a mi', strpos($html, 'cosas las hago yo') !== false);
    ok('y lo que le toca a el', strpos($html, 'cosas te tocan a ti') !== false);
    ok('y se le dice por su nombre', strpos($html, 'Lo que te voy a pedir') !== false);
    //  La fixture trae SEIS jugadas. La capa 1 puede nombrar como mucho cuatro:
    //  el objeto del que habla ahora y los tres «lo que sigue». Nombrar las seis
    //  seria pintar el plan entero, que es lo que el contrato §C prohibe aqui.
    ok('el plan entero NO se pinta aqui',
       strpos($html, '<span class="jg-tag') === false && substr_count($html, 'Paso de relleno') <= 4,
       'nombra ' . substr_count($html, 'Paso de relleno') . ' jugadas de 6 · ojo: jg-tag tambien es el nombre de la clase en el <style>');

    echo "\n  — pulsar Empezar —\n";
    //  La salida del arnes trae avisos de PHP delante (constantes ya definidas
    //  al blindar el gasto). El JSON empieza en la primera llave: leerlo desde
    //  el principio devolvia null y la prueba culpaba al handler.
    $leer_json = function (string $salida) {
        $i = strpos($salida, '{');
        return $i === false ? null : json_decode(substr($salida, $i), true);
    };
    $r = $leer_json($pedir('marca=' . $A, 'POST', 'accion=presentar&plan=' . $PLAN_A));
    $json = '';
    ok('el POST contesta bien', is_array($r) && ($r['ok'] ?? false) === true,
       'el handler no devolvio JSON');
    ok('y dice que esta vez si cambio algo', is_array($r) && ($r['cambio'] ?? null) === true);
    ok('el sello quedo en la base', $sello_de($PLAN_A) !== null,
       'la pantalla decia haber empezado sin haber escrito nada');

    $r2 = $leer_json($pedir('marca=' . $A, 'POST', 'accion=presentar&plan=' . $PLAN_A));
    ok('el segundo POST tampoco es un error', is_array($r2) && ($r2['ok'] ?? false) === true,
       'false no es fallo: es «ya estaba presentado»');
    ok('pero avisa que no cambio nada', is_array($r2) && ($r2['cambio'] ?? null) === false);

    $ajeno = $leer_json($pedir('marca=' . $A, 'POST', 'accion=presentar&plan=' . $PLAN_B));
    ok('un plan ajeno en el POST no cambia nada',
       is_array($ajeno) && ($ajeno['cambio'] ?? null) === false);

    echo "\n  — y al recargar —\n";
    $html2 = $pedir('marca=' . $A);
    ok('la pantalla ya no ofrece Empezar', strpos($html2, '>Empezar</button>') === false);
    ok('ni el resumen del trato', strpos($html2, '<div class="tm-reparto">') === false);
    ok('ahora pide lo que toca de verdad',
       strpos($html2, 'tm-btn') !== false && trim($html2) !== '',
       'quedarse sin accion seria peor que el estado C');

} finally {
    if ($A) Fixture::limpiar($pdo, $A);
    if ($B) Fixture::limpiar($pdo, $B);
    $vivas = 0;
    foreach ([$A, $B] as $m) {
        if (!$m) continue;
        $q = $pdo->prepare("SELECT COUNT(*) FROM crecer_marca WHERE id=?"); $q->execute([$m]);
        $vivas += (int)$q->fetchColumn();
    }
    echo "\n  (fixtures limpiadas: " . ($vivas === 0 ? 'sí' : 'NO') . ")\n";
}

echo "\n" . str_repeat('=', 56) . "\n";
echo $fallos === 0 ? "  TODO OK · {$n} pruebas\n\n" : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
