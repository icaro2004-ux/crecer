<?php
// ============================================================
//  CRECER — DOS NO PREPARAN LA MISMA SEMANA
//  tests/test_ciclo_concurrencia.php
//
//  EL RIESGO REAL. Preparar la semana siguiente lo pueden pedir dos a la vez: el
//  dueño toca el boton el domingo por la noche y el cron del corillo pasa en ese
//  mismo minuto. Tambien basta un doble clic. Cada preparacion cuesta UNA llamada
//  al modelo, UNA tanda de jugadas y UN job por jugada; si entran dos, el dueño
//  ve la semana duplicada y la paga dos veces.
//
//  COMO SE PRUEBA. Dos procesos de verdad con cita de reloj, contra el dominio
//  (por HTTP se serializarian solos en el candado de la sesion). Se afirma lo
//  que se puede contar: una sola llamada al modelo, una sola tanda de jugadas,
//  un solo job por jugada, y una sola fila en el libro.
//
//  CERO PROVEEDOR. Los dos procesos entran en modo prueba: la Estratega va por
//  `ia_ejecutar`, que devuelve su `mock_texto`.
// ============================================================

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/meta_ciclo.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nDOS NO PREPARAN LA MISMA SEMANA\n" . str_repeat('=', 58) . "\n";

if (!ciclo_hay_libro($pdo, true)) {
    echo "\n  SALTADA · falta migrations/2026-08-27_crecer_meta_semana.sql\n\n"; exit(0);
}

$cnt = function (string $t, string $w = '1') use ($pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM {$t} WHERE {$w}")->fetchColumn();
};

$M = 0;
try {
    $fx = Fixture::crear($pdo, 'ciclorace', true, 'admin');
    $M = (int)$fx['marca_id']; $META = (int)$fx['meta_id']; $PLAN = (int)$fx['plan_id'];

    $pdo->prepare("UPDATE crecer_meta SET fecha_inicio=CURDATE(),
                          fecha_limite=DATE_ADD(CURDATE(), INTERVAL 28 DAY)
                    WHERE id=?")->execute([$META]);
    //  La semana 1, terminada y cerrada: ese es el momento en que los dos
    //  caminos —el botón y el cron— quieren preparar la 2.
    $pdo->prepare("UPDATE crecer_meta_tactica SET estado='hecha', semana=1
                    WHERE plan_id=? AND marca_id=?")->execute([$PLAN, $M]);
    $c = ciclo_cerrar($pdo, $M, $META, $PLAN, 1, 'igual', '');
    ok('la semana 1 queda cerrada', !empty($c['ok']), json_encode($c));

    $ia_antes  = $cnt('crecer_ia_log');
    $job_antes = $cnt('crecer_meta_jobs', "marca_id={$M}");

    echo "\n  — el botón y el cron, en el mismo instante —\n";
    $runner = __DIR__ . DIRECTORY_SEPARATOR . '_ciclo_runner.php';
    $cita   = microtime(true) + 2.0;
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' '
         . $M . ' ' . $META . ' ' . $PLAN . ' 1 ' . escapeshellarg((string)$cita);
    $p1 = popen($cmd . ' 2>&1', 'r');
    $p2 = popen($cmd . ' 2>&1', 'r');
    $s1 = stream_get_contents($p1); pclose($p1);
    $s2 = stream_get_contents($p2); pclose($p2);

    $ult = function (string $t): array {
        foreach (array_reverse(preg_split('~\R~', $t) ?: []) as $l) {
            $l = trim($l); if ($l === '') continue;
            $j = json_decode($l, true);
            if (is_array($j)) return $j;
        }
        return [];
    };
    $j1 = $ult($s1); $j2 = $ult($s2);

    ok('los dos contestan bien', !empty($j1['ok']) && !empty($j2['ok']),
       mb_substr(trim($s1) . ' | ' . trim($s2), -300));
    ok('y uno de los dos sabe que llegó tarde',
       (!empty($j1['ya']) xor !empty($j2['ya'])),
       'exactamente uno tiene que decir «ya»: ' . json_encode([$j1['ya'] ?? null, $j2['ya'] ?? null]));

    //  ── UNA SOLA LLAMADA AL MODELO ──
    ok('una sola llamada al modelo', $cnt('crecer_ia_log') === $ia_antes + 1,
       'antes ' . $ia_antes . ' · ahora ' . $cnt('crecer_ia_log')
       . ' — reclamar antes de llamar es justo lo que evita pagar dos veces');

    //  ── UNA SOLA TANDA DE JUGADAS ──
    $creadas = max((int)($j1['creadas'] ?? 0), (int)($j2['creadas'] ?? 0));
    $s2t = $cnt('crecer_meta_tactica', "plan_id={$PLAN} AND semana=2");
    ok('una sola tanda de jugadas', $creadas > 0 && $s2t === $creadas,
       "creadas {$creadas} · en la base {$s2t}");
    ok('el perdedor no creó ninguna',
       (int)($j1['creadas'] ?? 0) === 0 || (int)($j2['creadas'] ?? 0) === 0,
       json_encode([$j1['creadas'] ?? null, $j2['creadas'] ?? null]));
    ok('sin adelantar la 3 ni la 4',
       $cnt('crecer_meta_tactica', "plan_id={$PLAN} AND semana>2") === 0);

    //  ── UN SOLO JOB POR JUGADA ──
    $prod = $cnt('crecer_meta_tactica',
                 "plan_id={$PLAN} AND semana=2 AND clase NOT IN ('regla','accion_dueno')");
    $jobs = $cnt('crecer_meta_jobs', "marca_id={$M}") - $job_antes;
    ok('un job por jugada de producción, ni uno más', $jobs === $prod,
       "jugadas de producción {$prod} · jobs nuevos {$jobs}");
    $dup = (int)$pdo->query(
        "SELECT COUNT(*) FROM (SELECT tactica_id FROM crecer_meta_jobs
                                WHERE marca_id={$M} GROUP BY tactica_id HAVING COUNT(*) > 1) x")
        ->fetchColumn();
    ok('y ninguna jugada con dos jobs', $dup === 0, (string)$dup);

    //  ── UNA SOLA FILA EN EL LIBRO ──
    ok('una sola fila en el libro',
       $cnt('crecer_meta_semana', "plan_id={$PLAN}") === 1);
    ok('y queda preparada',
       (string)$pdo->query("SELECT estado FROM crecer_meta_semana
                             WHERE plan_id={$PLAN} AND semana=1")->fetchColumn() === 'preparada');

    //  ── NI EL PLAN NI LA META SE TOCAN ──
    ok('el plan sigue activo',
       (string)$pdo->query("SELECT estado FROM crecer_meta_plan WHERE id={$PLAN}")->fetchColumn() === 'activo');
    ok('y la meta sigue activa',
       (string)$pdo->query("SELECT estado FROM crecer_meta WHERE id={$META}")->fetchColumn() === 'activa');

    //  ── EL QUE ESPERÓ, ESPERÓ ──
    ok('los dos tardaron algo medible',
       (int)($j1['ms'] ?? 0) > 0 && (int)($j2['ms'] ?? 0) > 0,
       json_encode([$j1['ms'] ?? null, $j2['ms'] ?? null]));

} catch (Throwable $e) {
    $fallos++; $n++;
    echo "  FALLA excepción\n         → " . $e->getMessage() . "\n";
} finally {
    if ($M > 0) {
        try { Fixture::limpiar($pdo, $M); echo "\n  (fixture limpiada)\n"; }
        catch (Throwable $e) { echo "\n  (no se pudo limpiar: " . $e->getMessage() . ")\n"; }
    }
}

//  EL COSTO. Se afirma al final, no se supone.
echo "\n  — el costo —\n";
ok('cero llamadas reales al modelo',
   (int)$pdo->query("SELECT COUNT(*) FROM crecer_ia_log WHERE modelo <> 'mock'
                      AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)")->fetchColumn() === 0);

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0 ? "  DOS NO PREPARAN LA MISMA SEMANA · {$n} afirmaciones\n\n"
                   : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
