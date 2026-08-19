<?php
// ============================================================
//  CRECER — EL WORKER DE ARTE NO PUEDE PEDIR UNA SEGUNDA IMAGEN
//  tests/test_arte_worker_timeout.php
//
//  Defecto: panel/arte_worker.php llamaba a img_gemini_fallback()
//  incondicionalmente al agotar sus 80 sondeos. Quedarse sin
//  sondeos NO prueba que OpenAI fallara — el job puede seguir vivo
//  y completar solo. El respaldo generaba entonces la MISMA pieza
//  por segunda vez, y la cobraba dos veces.
//
//  Es el mismo error que ya se corrigió en el barrido
//  (test_img_poll_backoff.php), pero en el otro camino: allá lo
//  hacía img_sweep_pendientes, aquí el worker dedicado.
//
//  ESTAS PRUEBAS CORREN EL WORKER DE VERDAD, no una función que se
//  le parezca: _arte_worker_runner.php requiere panel/arte_worker.php
//  en un proceso aparte —hace falta porque el worker termina con
//  exit() en cada camino— y aquí se mira lo que dejó en la base.
//
//  El runner anula las llaves antes de cargar el config: si el
//  defecto volviera, esta prueba lo caza sin gastar un centavo.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/img_responses.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nWORKER DE ARTE · AGOTAR LOS SONDEOS NO AUTORIZA UN SEGUNDO PROVEEDOR\n"
   . str_repeat('=', 68) . "\n";

$hayBase = false;
try { $pdo->query('SELECT 1'); $hayBase = true; } catch (Throwable $e) {}
if (!$hayBase) { echo "\n  (sin base: no se puede probar el worker)\n\n"; exit(1); }

$mid = (int)$pdo->query("SELECT id FROM crecer_marca ORDER BY id LIMIT 1")->fetchColumn();
if (!$mid) { echo "\n  (no hay marca sembrada: no se puede probar el worker)\n\n"; exit(1); }

$PHP    = PHP_BINARY;
$RUNNER = __DIR__ . DIRECTORY_SEPARATOR . '_arte_worker_runner.php';
$MARCA  = 'prueba worker timeout';
$creadas = [];

/** Siembra una pieza en cola con un job vivo. Se COMETE: el worker corre en otro proceso. */
$sembrar = function (string $job, string $edad = '0 MINUTE', int $intentos = 0) use ($pdo, $mid, $MARCA, &$creadas): int {
    $pdo->prepare("INSERT INTO crecer_contenido
         (marca_id, plataforma, tipo, caption, estado, img_estado, img_job, img_job_at, img_intentos, arte_intentos)
         VALUES (?, 'instagram', 'post', ?, 'borrador', 'queued', ?,
                 DATE_SUB(NOW(), INTERVAL {$edad}), ?, 0)")
        ->execute([$mid, $MARCA, $job, $intentos]);
    $id = (int)$pdo->lastInsertId(); $creadas[] = $id; return $id;
};
$pza = function (int $id) use ($pdo): array {
    $q = $pdo->prepare("SELECT img_estado, img_job, img_error_clase, arte_intentos, grafica_path
                          FROM crecer_contenido WHERE id=?");
    $q->execute([$id]); return $q->fetch(PDO::FETCH_ASSOC) ?: [];
};
/** ¿Existe un aviso con este título creado por la corrida? */
$aviso = function (string $titulo) use ($pdo, $mid): bool {
    $q = $pdo->prepare("SELECT COUNT(*) FROM crecer_notificaciones
                         WHERE marca_id=? AND tipo='arte' AND titulo=?
                           AND created_at > (NOW() - INTERVAL 5 MINUTE)");
    $q->execute([$mid, $titulo]); return (int)$q->fetchColumn() > 0;
};
/** Huella que deja el respaldo cuando SÍ produce imagen. */
$logRespaldo = function () use ($pdo, $mid): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM crecer_ia_log
                              WHERE marca_id={$mid} AND accion LIKE 'Respaldo Gemini%'
                                AND created_at > (NOW() - INTERVAL 5 MINUTE)")->fetchColumn();
};
$correr = function (int $pid, int $sondeos = 3, string $fb = '') use ($PHP, $RUNNER): array {
    $cmd = escapeshellarg($PHP) . ' ' . escapeshellarg($RUNNER)
         . ' ' . (int)$pid . ' ' . (int)$pid . ' ' . (int)$sondeos . ($fb !== '' ? ' ' . $fb : '');
    return [$cmd];
};

// Limpieza previa por si una corrida anterior murió a medias.
$pdo->prepare("DELETE FROM crecer_contenido WHERE marca_id=? AND caption=?")->execute([$mid, $MARCA]);
$pdo->prepare("DELETE FROM crecer_notificaciones WHERE marca_id=? AND tipo='arte'
                 AND titulo IN ('Tu arte va en camino','No se pudo crear el arte','Tu arte ya está listo')
                 AND created_at > (NOW() - INTERVAL 30 MINUTE)")->execute([$mid]);

$lanzar = function (int $pid, int $sondeos = 3, string $fb = '') use ($PHP, $RUNNER, $mid): string {
    $cmd = escapeshellarg($PHP) . ' ' . escapeshellarg($RUNNER)
         . ' ' . $mid . ' ' . $pid . ' ' . $sondeos . ($fb !== '' ? ' ' . escapeshellarg($fb) : '')
         . ' 2>&1';
    $salida = []; $rc = 0; exec($cmd, $salida, $rc);
    return implode("\n", $salida);
};

try {
    // ══════════════════════════════════════════════════════════
    //  A · SE AGOTA LA VENTANA CON EL JOB RECIÉN NACIDO
    //      El caso del defecto: el dueño encoló hace un momento,
    //      gpt-image tarda más que la ventana del worker, y el
    //      worker se queda sin sondeos. Nadie confirmó nada.
    // ══════════════════════════════════════════════════════════
    echo "\n  — se agotan los sondeos y el job está vivo —\n";
    $job = 'resp_timeout_' . bin2hex(random_bytes(4));
    $id  = $sembrar($job, '0 MINUTE');
    $lanzar($id, 3);
    $p = $pza($id);

    ok('NO se llamó al respaldo', !$aviso('No se pudo crear el arte'),
       'el aviso de fallo solo lo escribe $respaldo_gemini()');
    ok('NO se dio la pieza por lista', !$aviso('Tu arte ya está listo'));
    ok('el respaldo no dejó huella en el log', $logRespaldo() === 0);
    ok('img_job se conserva', (string)($p['img_job'] ?? '') === $job,
       'sin img_job no hay forma de reconciliar el trabajo después');
    ok('la pieza sigue en cola', (string)($p['img_estado'] ?? '') === 'queued',
       'estado=' . (string)($p['img_estado'] ?? ''));
    ok('no se gastó un intento de arte', (int)($p['arte_intentos'] ?? -1) === 0);
    ok('no se guardó ninguna imagen', (string)($p['grafica_path'] ?? '') === '');
    ok('el dueño ve que sigue procesando, no que falló', $aviso('Tu arte va en camino'));

    // El barrido tiene que poder retomarla: es lo que la deja recuperable.
    $rec = $pdo->prepare("SELECT COUNT(*) FROM crecer_contenido
                           WHERE id=? AND img_estado='queued' AND img_job IS NOT NULL");
    $rec->execute([$id]);
    ok('queda reconciliable por el barrido', (int)$rec->fetchColumn() === 1);

    // ══════════════════════════════════════════════════════════
    //  B · NO SE PUDO CONSULTAR, Y EL JOB YA ES VIEJO
    //      Aquí img_poll_decidir aparca la pieza. Aparcar tampoco
    //      autoriza un segundo proveedor: "no pude preguntar"
    //      nunca es "el proveedor falló".
    // ══════════════════════════════════════════════════════════
    echo "\n  — fallan las consultas hasta aparcar el job —\n";
    $job2 = 'resp_aparcar_' . bin2hex(random_bytes(4));
    $id2  = $sembrar($job2, '49 HOUR', 3);
    $lanzar($id2, 3);
    $p2 = $pza($id2);

    ok('NO se llamó al respaldo', !$aviso('No se pude crear el arte') && !$aviso('No se pudo crear el arte'));
    ok('el respaldo no dejó huella en el log', $logRespaldo() === 0);
    ok('img_job se conserva aunque se aparque', (string)($p2['img_job'] ?? '') === $job2,
       'aparcar conserva el job justo para poder reconciliar');
    ok('la pieza sigue en cola', (string)($p2['img_estado'] ?? '') === 'queued');
    ok('no se gastó un intento de arte', (int)($p2['arte_intentos'] ?? -1) === 0);
    ok('quedó marcada como aparcada', str_starts_with((string)($p2['img_error_clase'] ?? ''), 'ap:'),
       'clase=' . (string)($p2['img_error_clase'] ?? 'null'));

    // ══════════════════════════════════════════════════════════
    //  C · LA PUERTA NO QUEDÓ SELLADA
    //      El arreglo tiene que impedir el respaldo por timeout,
    //      no apagarlo. El re-disparo explícito (fb=1) lo manda el
    //      barrido SOLO cuando el proveedor ya confirmó el fallo.
    // ══════════════════════════════════════════════════════════
    echo "\n  — el respaldo sigue disponible cuando sí corresponde —\n";
    $job3 = 'resp_fb_' . bin2hex(random_bytes(4));
    $id3  = $sembrar($job3, '0 MINUTE');
    $lanzar($id3, 3, '1');

    ok('fb=1 entra al respaldo y reporta el fallo', $aviso('No se pudo crear el arte'),
       'sin llaves el respaldo no puede producir, pero se ve que SÍ se intentó');
    ok('el respaldo no gastó nada (llaves anuladas en el runner)', $logRespaldo() === 0);

} finally {
    if ($creadas) {
        $pdo->prepare("DELETE FROM crecer_contenido WHERE id IN ("
            . implode(',', array_map('intval', $creadas)) . ")")->execute();
    }
    $pdo->prepare("DELETE FROM crecer_notificaciones WHERE marca_id=? AND tipo='arte'
                     AND titulo IN ('Tu arte va en camino','No se pudo crear el arte','Tu arte ya está listo')
                     AND created_at > (NOW() - INTERVAL 30 MINUTE)")->execute([$mid]);
    echo "\n  (siembra y avisos de prueba limpiados)\n";
}

echo "\n" . str_repeat('=', 68) . "\n";
echo $fallos === 0 ? "  TODO OK · {$n} pruebas\n\n" : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
