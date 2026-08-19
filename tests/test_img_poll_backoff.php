<?php
// ============================================================
//  CRECER — SONDEO CON BACKOFF (hotfix de amplificación de logs)
//  tests/test_img_poll_backoff.php
//
//  El defecto medido en producción: 852 filas de error en crecer_ia_log, TODAS
//  de 'fallo_al_sondear', con 2-4 operaciones únicas por día y hasta 113
//  registros por operación. No eran 852 fallos — era el mismo puñado de jobs
//  trancados, resondeado en CADA carga de pantalla, escribiendo una fila cada
//  vez.
//
//  Aquí se afirma que eso no puede volver a pasar. Dos capas:
//    · la decisión pura (img_poll_decidir), sin base ni red;
//    · el comportamiento real contra la base, en transacción que se deshace.
//
//  NINGUNA prueba llama al proveedor. En local no hay OPENAI_API_KEY, así que
//  openai_responses_estado() lanza IaSinCredenciales ANTES de tocar la red —
//  que es exactamente el camino de "no se pudo consultar" que causó las 852.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/img_responses.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nSONDEO CON BACKOFF\n" . str_repeat('=', 52) . "\n\n";

// ── Guardia: ninguna prueba puede alcanzar al proveedor ─────────────────────
echo "  — nadie llama al proveedor —\n";
ok('sin credenciales de OpenAI en este entorno', !openai_configurado(),
   'si hubiera key, estas pruebas gastarian dinero real');

// ══════════════════════════════════════════════════════════════
//  A · LA DECISIÓN PURA
// ══════════════════════════════════════════════════════════════
echo "\n  — la decision, sin base ni red —\n";
$AHORA = '2026-08-19 12:00:00';
$vivo  = ['intentos' => 0, 'job_at' => '2026-08-19 11:58:00'];

$d = img_poll_decidir($vivo, 'completed', null, $AHORA);
ok('completed → guardar', $d['accion'] === 'guardar');
ok('completed no genera incidente', $d['incidente'] === false);

$d = img_poll_decidir($vivo, 'failed', null, $AHORA);
ok('failed → fallar', $d['accion'] === 'fallar');
ok('failed SÍ genera incidente (es una transición)', $d['incidente'] === true);

$d = img_poll_decidir($vivo, 'in_progress', null, $AHORA);
ok('vivo → esperar', $d['accion'] === 'esperar');
ok('vivo NO genera incidente', $d['incidente'] === false);
ok('vivo programa el próximo sondeo', !empty($d['next_poll_at']));

// El backoff crece: 1, 2, 4, 8, 16, 32, 60, 60...
echo "\n  — el backoff crece y tiene techo —\n";
$esperado = [1, 2, 4, 8, 16, 32, 60, 60, 60];
foreach ($esperado as $i => $min) {
    $d = img_poll_decidir(['intentos' => $i, 'job_at' => $AHORA], 'queued', null, $AHORA);
    $mins = (strtotime($d['next_poll_at']) - strtotime($AHORA)) / 60;
    ok("intento " . ($i + 1) . " espera {$min} min", (int)$mins === $min, "dio {$mins}");
}

echo "\n  — se rinde, pero una sola vez —\n";
$d = img_poll_decidir(['intentos' => IMG_POLL_MAX_INTENTOS - 1, 'job_at' => $AHORA], null, 'boom', $AHORA);
ok('al llegar al tope de intentos → fallar', $d['accion'] === 'fallar');
ok('rendirse SÍ es incidente', $d['incidente'] === true);

$d = img_poll_decidir(['intentos' => 2, 'job_at' => '2026-08-17 11:00:00'], null, 'boom', $AHORA);
ok('un job viejo se rinde aunque le sobren intentos', $d['accion'] === 'fallar');

// Un job que el proveedor sostiene NO lo mata nuestro contador.
$d = img_poll_decidir(['intentos' => IMG_POLL_MAX_INTENTOS + 5, 'job_at' => $AHORA], 'in_progress', null, $AHORA);
ok('proveedor dice vivo → los intentos no lo matan', $d['accion'] === 'esperar');
$d = img_poll_decidir(['intentos' => 1, 'job_at' => '2026-08-17 11:00:00'], 'in_progress', null, $AHORA);
ok('pero la edad sí lo mata', $d['accion'] === 'fallar' && $d['clase'] === 'vencido_por_edad');

echo "\n  — el error se guarda como CLASE, no como texto crudo —\n";
ok('429 → rate_limit_429',      img_poll_clase_error('HTTP 429 Too Many Requests') === 'rate_limit_429');
ok('401 → auth_401_403',        img_poll_clase_error('HTTP 401 unauthorized') === 'auth_401_403');
ok('timeout → timeout',         img_poll_clase_error('cURL: Operation timed out') === 'timeout');
ok('sin credenciales se aísla', img_poll_clase_error('Falta OPENAI_API_KEY.') === 'sin_credenciales');
ok('vacío → sin_detalle',       img_poll_clase_error('') === 'sin_detalle');
ok('la clase cabe en la columna (24)',
   strlen(img_poll_clase_error('HTTP 500 ' . str_repeat('x', 500))) <= 24);

// ══════════════════════════════════════════════════════════════
//  B · CONTRA LA BASE — el defecto real
// ══════════════════════════════════════════════════════════════
if (!isset($pdo) || !($pdo instanceof PDO)) {
    echo "\n  (sin base: se saltan las pruebas de integración)\n";
} else {
    $col = $pdo->query("SHOW COLUMNS FROM crecer_contenido LIKE 'img_next_poll_at'")->fetch();
    if (!$col) {
        echo "\n  FALLA: falta la migración 2026-08-19_crecer_poll_backoff.sql\n";
        $fallos++;
    } else {
        $pdo->beginTransaction();
        try {
            $mid = (int)$pdo->query("SELECT id FROM crecer_marca ORDER BY id LIMIT 1")->fetchColumn();
            if (!$mid) throw new RuntimeException('no hay marca para sembrar');

            $nueva = function (string $job) use ($pdo, $mid): int {
                $pdo->prepare("INSERT INTO crecer_contenido
                     (marca_id, plataforma, tipo, caption, estado, img_estado, img_job, img_job_at, img_intentos)
                     VALUES (?, 'instagram', 'post', 'prueba de sondeo', 'borrador', 'queued', ?, NOW(), 0)")
                    ->execute([$mid, $job]);
                return (int)$pdo->lastInsertId();
            };
            $logs = function () use ($pdo, $mid): int {
                return (int)$pdo->query("SELECT COUNT(*) FROM crecer_ia_log
                                          WHERE marca_id={$mid} AND modelo='responses' AND estado='error'")->fetchColumn();
            };
            $pieza = function (int $id) use ($pdo): array {
                $q = $pdo->prepare("SELECT img_estado,img_job,img_intentos,img_next_poll_at,img_error_clase
                                      FROM crecer_contenido WHERE id=?");
                $q->execute([$id]); return $q->fetch(PDO::FETCH_ASSOC) ?: [];
            };

            // ── 1 · CIEN CARGAS DE PANTALLA, NO CIEN LOGS ────────────────
            echo "\n  — 100 cargas sobre el mismo job —\n";
            $p1 = $nueva('resp_amplificacion');
            $antes = $logs();
            for ($i = 0; $i < 100; $i++) img_resp_completar($pdo, $mid, $p1);
            $creados = $logs() - $antes;
            ok('100 sondeos NO crean 100 filas de log', $creados <= 1, "creó {$creados}");
            $e = $pieza($p1);
            ok('el primer sondeo dejó el backoff puesto', !empty($e['img_next_poll_at']));
            ok('los 99 siguientes ni contaron intento', (int)$e['img_intentos'] === 1,
               "intentos={$e['img_intentos']}");
            ok('el motivo vive en la pieza, no en el log', $e['img_error_clase'] === 'sin_credenciales');

            // ── 2 · EL BACKOFF IMPIDE EL SONDEO TEMPRANO ────────────────
            echo "\n  — el backoff cierra la puerta —\n";
            $r = img_resp_completar($pdo, $mid, $p1);
            ok('con backoff vigente devuelve diferido', !empty($r['diferido']));
            ok('y sigue reportando en cola', ($r['estado'] ?? '') === 'queued');

            $pdo->prepare("UPDATE crecer_contenido SET img_next_poll_at = NOW() - INTERVAL 1 MINUTE WHERE id=?")
                ->execute([$p1]);
            $r = img_resp_completar($pdo, $mid, $p1);
            ok('vencido el backoff, vuelve a sondear', empty($r['diferido']));
            ok('y el intento sube a 2', (int)$pieza($p1)['img_intentos'] === 2);

            // ── 3 · UN JOB VIVO PUEDE COMPLETAR ─────────────────────────
            echo "\n  — un job vivo no queda condenado —\n";
            $e = $pieza($p1);
            ok('sigue en cola tras varios fallos de sondeo', $e['img_estado'] === 'queued');
            ok('conserva su job', $e['img_job'] === 'resp_amplificacion');
            $d = img_poll_decidir(['intentos' => (int)$e['img_intentos'], 'job_at' => date('Y-m-d H:i:s')],
                                  'completed', null, date('Y-m-d H:i:s'));
            ok('si el proveedor responde completed, se guarda', $d['accion'] === 'guardar');

            // ── 4 · EL VENCIDO PASA A FALLIDO UNA SOLA VEZ ──────────────
            echo "\n  — se rinde una vez, no en cada carga —\n";
            $p2 = $nueva('resp_vencido');
            $pdo->prepare("UPDATE crecer_contenido SET img_intentos=?, img_job_at=NOW() - INTERVAL 2 DAY WHERE id=?")
                ->execute([IMG_POLL_MAX_INTENTOS - 1, $p2]);
            $antes = $logs();
            $r = img_resp_completar($pdo, $mid, $p2);
            ok('se rinde', ($r['estado'] ?? '') === 'error');
            ok('y deja UN incidente', $logs() - $antes === 1);
            $e = $pieza($p2);
            ok('la pieza queda en error', $e['img_estado'] === 'error');
            ok('y suelta el job (estado recuperable)', $e['img_job'] === null || $e['img_job'] === '');
            ok('con su motivo persistido', !empty($e['img_error_clase']));

            $antes = $logs();
            for ($i = 0; $i < 20; $i++) img_resp_completar($pdo, $mid, $p2);
            ok('20 cargas más NO vuelven a registrar', $logs() - $antes === 0);

            // ── 5 · EL REINTENTO EXPLÍCITO ES OPERACIÓN NUEVA ───────────
            echo "\n  — el reintento del dueño empieza de cero —\n";
            img_poll_reiniciar($pdo, $mid, $p2);
            $pdo->prepare("UPDATE crecer_contenido SET img_estado='queued', img_job='resp_reintento' WHERE id=?")
                ->execute([$p2]);
            $e = $pieza($p2);
            ok('intentos vuelven a 0', (int)$e['img_intentos'] === 0);
            ok('sin backoff heredado', $e['img_next_poll_at'] === null);
            ok('sin el error viejo colgando', $e['img_error_clase'] === null);
            $r = img_resp_completar($pdo, $mid, $p2);
            ok('y puede volver a sondear de inmediato', empty($r['diferido']));

            // ── 6 · DOS PROCESOS NO DUPLICAN LA TRANSICIÓN ──────────────
            echo "\n  — dos barridos a la vez, una sola transición —\n";
            //  Se prueba la GUARDA directamente: el UPDATE exige que el job siga
            //  puesto, así que el segundo proceso afecta 0 filas y no registra.
            $p3 = $nueva('resp_carrera');
            $u = $pdo->prepare("UPDATE crecer_contenido SET img_estado='error', img_job=NULL
                                 WHERE id=? AND marca_id=? AND img_job=?");
            $u->execute([$p3, $mid, 'resp_carrera']);
            $primero = $u->rowCount();
            $u->execute([$p3, $mid, 'resp_carrera']);
            $segundo = $u->rowCount();
            ok('el primero gana la transición', $primero === 1);
            ok('el segundo no afecta ninguna fila', $segundo === 0);
            ok('→ el incidente se escribe una sola vez', $primero === 1 && $segundo === 0);

            // ── 7 · EL BARRIDO NI SIQUIERA TRAE LO DIFERIDO ─────────────
            echo "\n  — el barrido filtra en SQL, no en PHP —\n";
            $p4 = $nueva('resp_diferido');
            $pdo->prepare("UPDATE crecer_contenido SET img_next_poll_at = NOW() + INTERVAL 30 MINUTE WHERE id=?")
                ->execute([$p4]);
            $q = $pdo->prepare("SELECT COUNT(*) FROM crecer_contenido
                                 WHERE marca_id=? AND img_estado='queued'
                                   AND (img_next_poll_at IS NULL OR img_next_poll_at <= NOW())
                                   AND id=?");
            $q->execute([$mid, $p4]);
            ok('una pieza diferida no la selecciona el barrido', (int)$q->fetchColumn() === 0);

        } catch (Throwable $e) {
            $fallos++; echo "  FALLA excepción: " . $e->getMessage() . "\n";
        }
        $pdo->rollBack();

        $quedan = (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE caption='prueba de sondeo'")->fetchColumn();
        ok('la transacción se deshizo: no quedó ni una fila', $quedan === 0);
    }
}

echo "\n" . str_repeat('=', 52) . "\n";
echo $fallos === 0 ? "  TODO OK · {$n} pruebas\n\n" : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
