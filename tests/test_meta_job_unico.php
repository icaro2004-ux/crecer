<?php
// ============================================================
//  CRECER — EL ENCOLADO TIENE QUE SER UNO SOLO
//  tests/test_meta_job_unico.php
//
//  POR QUE EXISTE. El codigo anterior hacia esto:
//
//      if (!meta_job_en_curso(...)) meta_job_encolar(...);
//
//  y yo lo llame «candado». No lo es: es leer y despues insertar, y entre las
//  dos cosas cabe otro proceso haciendo exactamente lo mismo. El resultado no
//  es un bug cosmetico — son dos workers produciendo la misma tanda de piezas
//  y el dueño pagando dos veces por ellas.
//
//  Y NO SE PUEDE PROBAR EN UN SOLO PROCESO. Dos llamadas seguidas en el mismo
//  script pasan siempre, porque la segunda ve lo que escribio la primera. La
//  carrera solo aparece con DOS PROCESOS DE VERDAD, con conexiones distintas,
//  saliendo a la vez. Por eso esta prueba se lanza a si misma como hijo:
//
//      php tests/test_meta_job_unico.php                → el juez
//      php tests/test_meta_job_unico.php --hijo=<ts>    → el corredor
//
//  Los dos hijos esperan al MISMO instante de reloj (barrera) y despues
//  llaman a meta_job_encolar_unico(). El juez cuenta las filas.
//
//  CERO PROVEEDORES: aqui no se genera nada. Se encola y se cuenta; el worker
//  nunca se dispara. Encolar es escribir una fila, no llamar a nadie.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/meta_negocio.php';
require_once __DIR__ . '/../includes/meta_ejecutar.php';
require_once __DIR__ . '/../includes/meta_async.php';
require_once __DIR__ . '/_fixture.php';

// ── MODO HIJO ────────────────────────────────────────────────
//  Recibe la tactica y el instante de salida. Imprime UNA linea JSON y muere.
$arg = null;
foreach ($argv as $a) if (strpos($a, '--hijo=') === 0) $arg = substr($a, 7);
if ($arg !== null) {
    [$marca_id, $tactica_id, $salida_us] = array_map('intval', explode(':', $arg));
    //  Barrera de reloj: los dos procesos se sueltan en el mismo microsegundo.
    //  usleep en bucle corto es mas fiel que un sleep largo — el arranque de
    //  PHP ya se comio parte del margen.
    while (true) {
        $ahora = (int) round(microtime(true) * 1_000_000);
        if ($ahora >= $salida_us) break;
        $falta = $salida_us - $ahora;
        if ($falta > 2000) usleep($falta - 1000); else usleep(50);
    }
    try {
        $r = meta_job_encolar_unico($pdo, $marca_id, $tactica_id);
        echo json_encode(['pid' => getmypid()] + $r) . "\n";
    } catch (Throwable $e) {
        echo json_encode(['pid' => getmypid(), 'id' => 0, 'creado' => false,
                          'motivo' => 'excepcion', 'err' => $e->getMessage()]) . "\n";
    }
    exit;
}

// ── MODO JUEZ ────────────────────────────────────────────────
$fallos = 0; $n = 0;
function ok(string $q, bool $c, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($c) { echo "  ok   $q\n"; return; }
    $fallos++; echo "  FALLA $q" . ($detalle !== '' ? "  → $detalle" : '') . "\n";
}
function jobs_vivos(PDO $pdo, int $tid): array {
    $q = $pdo->prepare("SELECT id FROM crecer_meta_jobs WHERE tactica_id=? AND estado IN ('queued','working') ORDER BY id");
    $q->execute([$tid]);
    return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
}
function limpia_jobs(PDO $pdo, int $tid): void {
    $pdo->prepare("DELETE FROM crecer_meta_jobs WHERE tactica_id=?")->execute([$tid]);
}

echo "\n=== ENCOLADO UNICO ===\n\n";

$fx  = Fixture::crear($pdo, 'jobunico');
$fx2 = Fixture::crear($pdo, 'jobajena');
$tid  = (int)$fx['tacticas'][0];
$tid2 = (int)($fx['tacticas'][1] ?? $fx['tacticas'][0]);
$marca = (int)$fx['marca_id'];

//  La fixture siembra piezas; para que la jugada NO cuente como completa hay
//  que dejarle sitio. Se sube el tope en vez de borrar piezas: tocar piezas
//  cambiaria lo que otras pruebas dan por sentado.
$pdo->prepare("UPDATE crecer_meta_tactica SET piezas_meta=99, estado='pendiente' WHERE id IN (?,?)")
    ->execute([$tid, $tid2]);

try {

// ── 1 · LA CARRERA, con dos procesos de verdad ───────────────
echo "1 · dos procesos a la vez\n";
limpia_jobs($pdo, $tid);

$php    = PHP_BINARY;
$script = __FILE__;
$salida = (int) round((microtime(true) + 1.5) * 1_000_000);   // 1.5 s de margen
$arg    = "{$marca}:{$tid}:{$salida}";

$procs = []; $tubos = [];
for ($i = 0; $i < 2; $i++) {
    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $p = proc_open([$php, $script, "--hijo={$arg}"], $desc, $tubo);
    if (!is_resource($p)) { echo "  (no pude lanzar el hijo $i)\n"; break; }
    $procs[] = $p; $tubos[] = $tubo;
}

$salidas = [];
foreach ($procs as $i => $p) {
    $txt = stream_get_contents($tubos[$i][1]);
    $err = stream_get_contents($tubos[$i][2]);
    fclose($tubos[$i][1]); fclose($tubos[$i][2]);
    proc_close($p);
    $j = json_decode(trim(explode("\n", trim($txt))[0] ?? ''), true);
    if (!is_array($j)) { echo "  (hijo $i sin respuesta: " . trim($txt . ' ' . $err) . ")\n"; continue; }
    $salidas[] = $j;
}

ok('los dos hijos contestaron', count($salidas) === 2, count($salidas) . ' de 2');

$vivos = jobs_vivos($pdo, $tid);
ok('UNA sola fila activa', count($vivos) === 1, count($vivos) . ' filas: ' . implode(',', $vivos));

if (count($salidas) === 2) {
    $ids = array_map(fn($s) => (int)$s['id'], $salidas);
    ok('los dos reciben el MISMO job id', $ids[0] === $ids[1] && $ids[0] > 0, implode(' vs ', $ids));
    $creados = array_sum(array_map(fn($s) => !empty($s['creado']) ? 1 : 0, $salidas));
    ok('exactamente uno dice haberlo creado', $creados === 1, "creado=$creados");
    ok('el que pierde reconoce el existente', $creados === 1
        && in_array('ya_en_curso', array_column($salidas, 'motivo'), true));
    ok('ninguno murio por interbloqueo', !in_array('excepcion', array_column($salidas, 'motivo'), true),
       json_encode(array_column($salidas, 'err')));
    //  El disparo del worker es responsabilidad del llamador y SOLO si creado:
    //  con un unico `creado` no puede haber dos disparos.
    ok('un solo disparo posible tras el commit', $creados === 1);
}

// ── 2 · CONTROLES NEGATIVOS ──────────────────────────────────
echo "\n2 · controles\n";

//  job vivo → devuelve el mismo, no inserta
$antes = jobs_vivos($pdo, $tid);
$r = meta_job_encolar_unico($pdo, $marca, $tid);
ok('job vivo: devuelve el mismo id y no inserta',
   $r['id'] === ($antes[0] ?? 0) && $r['creado'] === false && count(jobs_vivos($pdo, $tid)) === 1);

//  otra tactica SI puede encolar
limpia_jobs($pdo, $tid2);
$r2 = meta_job_encolar_unico($pdo, $marca, $tid2);
ok('otra jugada distinta si encola', $r2['id'] > 0 && $r2['creado'] === true, $r2['motivo']);

//  marca ajena NO
$r3 = meta_job_encolar_unico($pdo, (int)$fx2['marca_id'], $tid);
ok('marca ajena rechazada', $r3['id'] === 0 && $r3['motivo'] === 'no_tuya', $r3['motivo']);

//  done no bloquea un ciclo legitimo
limpia_jobs($pdo, $tid2);
$pdo->prepare("INSERT INTO crecer_meta_jobs (marca_id,tactica_id,estado) VALUES (?,?, 'done')")->execute([$marca, $tid2]);
$r4 = meta_job_encolar_unico($pdo, $marca, $tid2);
ok('un job `done` no bloquea', $r4['id'] > 0 && $r4['creado'] === true, $r4['motivo']);

//  failed no cuenta como activo
limpia_jobs($pdo, $tid2);
$pdo->prepare("INSERT INTO crecer_meta_jobs (marca_id,tactica_id,estado) VALUES (?,?, 'failed')")->execute([$marca, $tid2]);
$r5 = meta_job_encolar_unico($pdo, $marca, $tid2);
ok('`failed` no se considera activo', $r5['id'] > 0 && $r5['creado'] === true, $r5['motivo']);

//  jugada ya completa: no vuelve a producir aunque el job anterior terminase
limpia_jobs($pdo, $tid2);
$creadas = (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE tactica_id={$tid2}")->fetchColumn();
$pdo->prepare("UPDATE crecer_meta_tactica SET piezas_meta=? WHERE id=?")
    ->execute([max(1, $creadas), $tid2]);
$r6 = meta_job_encolar_unico($pdo, $marca, $tid2);
ok('jugada ya completa no vuelve a producir',
   $creadas > 0 ? ($r6['id'] === 0 && $r6['motivo'] === 'ya_completa') : true,
   "creadas=$creadas motivo={$r6['motivo']}");

//  ...pero forzar si deja pedir mas
if ($creadas > 0) {
    limpia_jobs($pdo, $tid2);
    $r7 = meta_job_encolar_unico($pdo, $marca, $tid2, true);
    ok('forzar sortea `ya_completa`', $r7['id'] > 0 && $r7['creado'] === true, $r7['motivo']);
}

//  jugada descartada: no se le encola nada
limpia_jobs($pdo, $tid2);
$pdo->prepare("UPDATE crecer_meta_tactica SET estado='descartada', sustituida_at=NOW() WHERE id=?")->execute([$tid2]);
$r8 = meta_job_encolar_unico($pdo, $marca, $tid2);
ok('jugada descartada no encola', $r8['id'] === 0 && $r8['motivo'] === 'descartada', $r8['motivo']);
$pdo->prepare("UPDATE crecer_meta_tactica SET estado='pendiente', sustituida_at=NULL WHERE id=?")->execute([$tid2]);

// ── 3 · TRANSACCION EXTERNA: el commit es del que llama ──────
echo "\n3 · transaccion externa\n";
limpia_jobs($pdo, $tid2);
$pdo->prepare("UPDATE crecer_meta_tactica SET piezas_meta=99 WHERE id=?")->execute([$tid2]);

$pdo->beginTransaction();
$r9 = meta_job_encolar_unico($pdo, $marca, $tid2);
$dentro = $r9['id'] > 0;
$sigue  = $pdo->inTransaction();
$pdo->rollBack();                       // el dueño de la transaccion decide
$tras   = jobs_vivos($pdo, $tid2);

ok('participa en la transaccion ajena', $dentro, $r9['motivo']);
ok('no hace commit de lo ajeno', $sigue === true);
ok('el rollback del llamador se lleva el job', count($tras) === 0, count($tras) . ' filas');

} finally {
    Fixture::limpiar($pdo, (int)$fx['marca_id']);
    Fixture::limpiar($pdo, (int)$fx2['marca_id']);
}

echo "\n" . ($fallos === 0 ? "TODO VERDE ($n)" : "$fallos de $n FALLAN") . "\n\n";
exit($fallos === 0 ? 0 : 1);
