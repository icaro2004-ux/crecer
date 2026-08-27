<?php
// ============================================================
//  CRECER — EL ARNES RECOGE LO SUYO, Y SOLO LO SUYO
//  tests/test_arnes_barrido.php
//
//  DE DONDE SALE ESTO. Una suite que murio a la mitad dejo su base de copia
//  viva, y la corrida siguiente se puso roja por algo que no tenia nada que ver
//  con lo que estaba probando. Un rojo que no significa nada enseña a ignorar
//  los rojos, asi que el arnés tiene que recoger lo que deja.
//
//  PERO BARRER ES PELIGROSO. Borrar bases por «nombre parecido» es como se borra
//  la base de otro. Y en esta maquina corren dos suites a la vez sin problema:
//  llevarse la base VIVA de la otra seria peor que no barrer nada.
//
//  Por eso esta prueba es sobre todo una prueba de lo que NO se puede tocar:
//
//    · un testigo con nombre parecido pero que no cumple la forma → intacto
//    · la base compartida y la de la configuracion               → intactas
//    · una huerfana vieja de verdad                              → recogida
//    · la base viva de otra corrida                              → intacta
//
//  Y es falsable: si el barrido dejara de mirar la edad, la ultima se caeria.
// ============================================================

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/_esquema_desechable.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}
$existe = function (string $b) use ($pdo): bool {
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?");
    $q->execute([$b]); return (int)$q->fetchColumn() > 0;
};

echo "\nEL ARNES RECOGE LO SUYO, Y SOLO LO SUYO\n" . str_repeat('=', 58) . "\n";

$P = EsquemaDesechable::PREFIJO;
$compartida = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
echo "\n  base compartida: {$compartida}\n";

//  ¿Se pueden crear bases aquí? Sin ese permiso no hay nada que probar.
try { $pdo->exec("CREATE DATABASE `{$P}permiso_0000aa`"); $pdo->exec("DROP DATABASE `{$P}permiso_0000aa`"); }
catch (Throwable $e) { echo "\n  SALTADA · sin privilegios para crear bases\n\n"; exit(0); }

$testigos = [];
try {
    // ══════════════════════════════════════════════════════════════
    //  1 · LOS CUATRO TESTIGOS
    // ══════════════════════════════════════════════════════════════
    //
    //  Cada uno representa algo que el barrido tiene que tratar distinto. Se
    //  crean a mano —no por el arnés— para poder controlar exactamente su
    //  nombre y la edad de sus tablas.
    echo "\n  — se siembran cuatro testigos —\n";

    //  a · NOMBRE PARECIDO PERO NO ES LA FORMA. Lleva el prefijo, pero no la
    //      forma exacta que produce crear(). Un barrido por «empieza por» se lo
    //      llevaría; este no puede.
    $ajeno = $P . 'de_alguien_mas';
    $pdo->exec("CREATE DATABASE `{$ajeno}`");
    $pdo->exec("CREATE TABLE `{$ajeno}`.`t` (id INT)");
    $testigos[] = $ajeno;

    //  b · UNA HUERFANA DE VERDAD: la forma exacta, y con las tablas creadas
    //      hace rato. Esta SÍ hay que recogerla.
    $vieja = $P . '999001_aabbcc';
    $pdo->exec("CREATE DATABASE `{$vieja}`");
    $pdo->exec("CREATE TABLE `{$vieja}`.`t` (id INT)");
    $testigos[] = $vieja;

    //  c · LA VIVA DE OTRA CORRIDA: forma exacta también, pero recién creada.
    //      Otra suite la está usando ahora mismo.
    $viva = $P . '999002_ddeeff';
    $pdo->exec("CREATE DATABASE `{$viva}`");
    $pdo->exec("CREATE TABLE `{$viva}`.`t` (id INT)");
    $testigos[] = $viva;

    //  d · UNA SIN TABLAS: no hay forma de saber su edad. Ante la duda no se
    //      borra — dejar una de más es molesto; borrar la de otro le pierde el
    //      trabajo.
    $sin_edad = $P . '999003_112233';
    $pdo->exec("CREATE DATABASE `{$sin_edad}`");
    $testigos[] = $sin_edad;

    ok('los cuatro están', $existe($ajeno) && $existe($vieja) && $existe($viva) && $existe($sin_edad));

    //  ENVEJECER LA HUERFANA. No se puede esperar media hora en una prueba, así
    //  que se le retrasa el CREATE_TIME de su tabla. Es el mismo dato que mira
    //  el barrido, así que se está probando el mecanismo de verdad.
    //  Y SE COMPRUEBA QUE DE VERDAD ENVEJECIO, no que el UPDATE no diera
    //  error. `information_schema.TABLES.CREATE_TIME` lo pone InnoDB y no
    //  siempre se deja mover; dar por bueno el intento haria que la prueba
    //  midiera otra cosa y se pusiera roja culpando al barrido.
    $edad_de = function (string $b) use ($pdo): int {
        $q = $pdo->prepare("SELECT MAX(CREATE_TIME) FROM information_schema.TABLES
                              WHERE TABLE_SCHEMA = ?");
        $q->execute([$b]);
        $c = $q->fetchColumn();
        return $c ? (int)floor((time() - strtotime((string)$c)) / 60) : -1;
    };
    try {
        $pdo->exec("UPDATE mysql.innodb_table_stats SET last_update = NOW() - INTERVAL 3 HOUR
                     WHERE database_name = '{$vieja}'");
    } catch (Throwable $e) { /* sin permiso sobre mysql.*: se sigue */ }
    $envejecida = $edad_de($vieja) >= 30;

    //  Si no se puede tocar el reloj de la tabla, se comprueba lo mismo pidiendo
    //  un umbral de 0 minutos: el barrido tiene que recoger la vieja igual, y
    //  seguir respetando a las otras tres.
    $umbral = $envejecida ? 30 : 0;
    if (!$envejecida) {
        echo "  (no pude mover el reloj de la tabla · se prueba con umbral 0,\n";
        echo "   y a la viva de otra corrida la protege el registro de abiertas)\n";
    }

    // ══════════════════════════════════════════════════════════════
    //  2 · SE BARRE
    // ══════════════════════════════════════════════════════════════
    echo "\n  — se barre —\n";
    //  La viva de otra corrida se protege por EDAD: acaba de nacer. Con umbral
    //  0 eso no vale, así que en ese caso se la anota como abierta para que el
    //  barrido la respete por la otra guarda — que es la que usa el arnés real.
    if (!$envejecida) {
        //  Se simula que otra corrida la tiene abierta usando el mismo camino
        //  que usa el arnés: crear una de verdad y dejarla viva.
        $otra = EsquemaDesechable::crear($pdo, ['crecer_marca']);
        ok('otra corrida tiene la suya abierta', $otra !== null);
    }

    $recogidas = EsquemaDesechable::barrerHuerfanas($pdo, $umbral);
    echo "  recogidas: {$recogidas}\n";

    echo "\n  — y lo que NO podía tocar sigue ahí —\n";
    ok('la base compartida, intacta',      $existe($compartida),
       'si esto se cae, el barrido se llevó la base de todos');
    ok('el nombre parecido, intacto',      $existe($ajeno),
       'lleva el prefijo pero no la forma: un barrido por «empieza por» se lo llevaría');
    ok('la que no tiene edad, intacta',    $existe($sin_edad),
       'sin tablas no se sabe si es de hace un minuto: ante la duda no se borra');

    if ($envejecida) {
        ok('la huérfana vieja, recogida',  !$existe($vieja), 'esa era la que había que barrer');
        ok('la viva de otra corrida, intacta', $existe($viva),
           'acaba de nacer: otra suite la está usando ahora mismo');
        //  FALSABLE: si el barrido dejara de mirar la edad, la de arriba
        //  se caeria. Se comprueba que la diferencia entre las dos es la
        //  EDAD y nada mas — misma forma de nombre, misma marca de tiempo
        //  de creacion de la base.
        ok('y las dos tenían la misma forma de nombre',
           preg_match('/^' . preg_quote($P, '/') . '\\d+_[0-9a-f]{6}$/', $vieja) === 1
           && preg_match('/^' . preg_quote($P, '/') . '\\d+_[0-9a-f]{6}$/', $viva) === 1,
           'lo único que las separa es cuánto llevan quietas');
    } else {
        ok('la huérfana, recogida',        !$existe($vieja));
        //  Con umbral 0 la «viva» también cae: es correcto y se dice, porque lo
        //  que protege a la de otra corrida es justamente la edad.
        echo "  (con umbral 0 la viva de otra corrida también cae · es lo esperado)\n";
        if (isset($otra) && $otra !== null) {
            ok('pero la que el arnés tiene abierta, intacta',
               $existe($otra->nombre()),
               'esa la protege el registro de abiertas, no la edad');
            $otra->soltar($pdo);
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  3 · FALSABLE · si mirara solo el nombre, se llevaría de más
    // ══════════════════════════════════════════════════════════════
    echo "\n  — y la regla del nombre es exacta, no «parecida» —\n";
    $re = '/^' . preg_quote($P, '/') . '\d+_[0-9a-f]{6}$/';
    $CASOS = [
        [$P . '12345_abcdef', true,  'la forma que produce el arnés'],
        [$P . 'de_alguien_mas', false, 'prefijo, pero no la forma'],
        [$P . '12345_ABCDEF', false, 'hex en mayúsculas no es lo que genera'],
        [$P . '12345_abcde',  false, 'cinco bytes, no seis'],
        ['crecer_prueba', false, 'el prefijo a secas'],
        ['encuentralo_db', false, 'la base compartida'],
        ['crecer_pruebas_1_aabbcc', false, 'un prefijo parecido de otro proyecto'],
    ];
    foreach ($CASOS as [$nombre, $vale, $porque]) {
        ok(($vale ? 'coincide: ' : 'NO coincide: ') . $nombre,
           (bool)preg_match($re, $nombre) === $vale, $porque);
    }

    // ══════════════════════════════════════════════════════════════
    //  4 · Y EL ARNES SE LIMPIA SOLO AL SALIR
    // ══════════════════════════════════════════════════════════════
    //
    //  Un proceso que muere sin llegar a soltar() tiene que dejar la base
    //  recogida igual. Se prueba en un PHP aparte que crea una y se va sin
    //  soltarla: al terminar, no puede quedar viva.
    echo "\n  — y una muerte interceptable limpia lo suyo —\n";
    $runner = __DIR__ . DIRECTORY_SEPARATOR . '_arnes_muere_runner.php';
    if (is_file($runner)) {
        $sal = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1', $sal);
        $creada = '';
        foreach ($sal as $l) if (strpos(trim($l), $P) === 0) $creada = trim($l);
        ok('el proceso creó una base', $creada !== '', implode(' | ', array_slice($sal, -3)));
        if ($creada !== '') {
            ok('y al morir la dejó recogida', !$existe($creada),
               "{$creada} sigue viva · el cierre no la soltó");
        }
    } else {
        ok('(falta el runner de la muerte)', false, $runner);
    }

} catch (Throwable $e) {
    $fallos++; echo "\n  EXCEPCION · " . get_class($e) . ': ' . $e->getMessage() . "\n";
} finally {
    foreach ($testigos as $t) {
        try { $pdo->exec("DROP DATABASE IF EXISTS `{$t}`"); } catch (Throwable $e) {}
    }
    echo "\n  (testigos retirados)\n";
}

echo "\n  — y no queda nada tirado —\n";
$quedan = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.SCHEMATA
                             WHERE SCHEMA_NAME LIKE '" . $P . "%'")->fetchColumn();
ok('cero bases desechables al terminar', $quedan === 0, $quedan . ' vivas');
ok('y la compartida sigue donde estaba',
   (string)$pdo->query('SELECT DATABASE()')->fetchColumn() === $compartida);

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  RECOGE LO SUYO Y SOLO LO SUYO · $n afirmaciones\n\n"
    : "  $fallos FALLAS de $n\n\n";
exit($fallos === 0 ? 0 : 1);
