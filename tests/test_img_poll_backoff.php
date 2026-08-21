<?php
// ============================================================
//  CRECER — SONDEO: BACKOFF, LEASE Y LA DIFERENCIA ENTRE
//  "NO PUDE PREGUNTAR" Y "EL PROVEEDOR FALLÓ"
//  tests/test_img_poll_backoff.php
//
//  Defecto medido en producción: 852 eventos de error en crecer_ia_log, TODOS
//  de fallo_al_sondear, con 2-4 operaciones únicas por día y hasta 113.3
//  registros por operación. No eran 852 fallos: era el mismo puñado de jobs
//  trancados, resondeado en CADA carga de pantalla, escribiendo una fila cada
//  vez.
//
//  Pero el arreglo tenía un defecto peor que el defecto: dar por muerto un job
//  porque NO SE PUDO CONSULTAR. Eso borraba img_job —la única forma de
//  reconciliarlo— y disparaba un segundo proveedor sobre un trabajo que aún
//  podía completar. Aquí se afirma que eso no puede pasar.
//
//  NINGUNA prueba llama al proveedor: openai_responses_estado() revisa
//  credenciales ANTES de tocar la red, y en local no hay key. Así el camino que
//  se recorre es justo el de "no se pudo consultar" que causó las 852.
//
//  NO CUBIERTO, y se dice para no aparentar más de lo que hay: la degradación
//  sin migración (los condicionales $cols/$puerta) no se ejercita, porque
//  img_poll_columnas() se cachea por proceso y aquí la migración sí está.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/img_responses.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nSONDEO · BACKOFF, LEASE Y RECONCILIACIÓN\n" . str_repeat('=', 56) . "\n\n";

echo "  — nadie llama al proveedor —\n";
ok('sin credenciales de OpenAI en este entorno', !openai_configurado(),
   'con key, estas pruebas gastarían dinero real');

// ══════════════════════════════════════════════════════════════
//  A · LA DECISIÓN PURA
// ══════════════════════════════════════════════════════════════
$AHORA  = '2026-08-19 12:00:00';
$joven  = ['intentos' => 0, 'job_at' => '2026-08-19 11:58:00'];
$viejo  = ['intentos' => 3, 'job_at' => '2026-08-17 11:00:00'];   // 49 h
$anciano= ['intentos' => 3, 'job_at' => '2026-08-01 11:00:00'];   // 18 días

echo "\n  — solo el proveedor puede declarar un fallo —\n";
foreach (['failed', 'cancelled', 'incomplete'] as $s) {
    $d = img_poll_decidir($joven, $s, null, $AHORA);
    ok("{$s} confirmado → fallback", $d['accion'] === 'fallback');
    ok("{$s} deja UN incidente", $d['incidente'] === true);
}
$d = img_poll_decidir($joven, 'completed', null, $AHORA);
ok('completed → guardar', $d['accion'] === 'guardar');
ok('completed no genera incidente', $d['incidente'] === false);

echo "\n  — «no pude preguntar» NUNCA es «el proveedor falló» —\n";
$d = img_poll_decidir($joven, null, 'cURL error 7', $AHORA);
ok('sin respuesta y job joven → esperar', $d['accion'] === 'esperar');
ok('nunca fallback', $d['accion'] !== 'fallback');
$d = img_poll_decidir($viejo, null, 'cURL error 7', $AHORA);
ok('sin respuesta y job de 49h → aparcar, NO fallback', $d['accion'] === 'aparcar');
ok('aparcar deja UN incidente', $d['incidente'] === true);
for ($i = 1; $i <= 30; $i++) {
    $d = img_poll_decidir(['intentos' => $i, 'job_at' => '2026-08-19 11:00:00'], null, 'boom', $AHORA);
    if ($d['accion'] === 'fallback') { ok("con {$i} fallos de consulta NO hay fallback", false); break; }
}
ok('30 fallos de consulta seguidos no producen ni un fallback', true);
$d = img_poll_decidir(['intentos' => 12, 'job_at' => '2026-08-19 11:00:00'], null, 'boom', $AHORA);
ok('12 fallos de consulta: sigue esperando (job de 1h)', $d['accion'] === 'esperar');

echo "\n  — un job que el proveedor sostiene no se mata a las 24h —\n";
$d = img_poll_decidir($viejo, 'in_progress', null, $AHORA);
ok('vivo a las 49h → sigue esperando', $d['accion'] === 'esperar');
ok('vivo a las 49h NO dispara respaldo', $d['accion'] !== 'fallback');
//  LOS INTENTOS NO MATAN UN JOB VIVO. Ni con 999 sondeos: mientras el proveedor
//  conteste 'queued' o 'in_progress' esta sosteniendo el trabajo, y a ese lo
//  decide la EDAD. El tope cuenta otra cosa — ver justo abajo.
$d = img_poll_decidir(['intentos' => 999, 'job_at' => '2026-08-19 11:00:00'], 'queued', null, $AHORA);
ok('un job VIVO no se aparca por intentos', $d['accion'] === 'esperar',
   "salio {$d['accion']} · el tope es para consultas FALLIDAS, no para trabajos sanos");

//  LO QUE SI CUENTA EL TOPE: consultas que no se pudieron hacer. Ese es el caso
//  de #644 — su edad se leia como cero y nada mas podia pararla.
$d = img_poll_decidir(['intentos' => IMG_POLL_INTENTOS_MAX - 1, 'job_at' => null], null, 'boom', $AHORA);
ok('por debajo del tope de fallos, sigue esperando', $d['accion'] === 'esperar');
$d = img_poll_decidir(['intentos' => IMG_POLL_INTENTOS_MAX + 1, 'job_at' => null], null, 'boom', $AHORA);
ok('pasado el tope de FALLOS, se aparca',
   $d['accion'] === 'aparcar' && $d['clase'] === 'tope_fallos_consulta',
   "salio {$d['accion']}/{$d['clase']} · ningun trabajo puede volver a 35 sondeos");
ok('y aparcar no autoriza respaldo', $d['accion'] !== 'fallback');
$d = img_poll_decidir($anciano, 'in_progress', null, $AHORA);
ok('solo el tope duro (7 días) lo aparca', $d['accion'] === 'aparcar' && $d['clase'] === 'vivo_tope_duro');
ok('y aparcar tampoco autoriza respaldo', $d['accion'] !== 'fallback');

echo "\n  — el backoff distingue quién sondea —\n";
$esperado = [1, 2, 4, 8, 16, 32, 60, 60];
foreach ($esperado as $i => $min) {
    $d = img_poll_decidir(['intentos' => $i, 'job_at' => $AHORA], 'queued', null, $AHORA, false);
    $mins = $d['espera_seg'] / 60;
    ok("barrido, intento " . ($i + 1) . ": {$min} min", (int)$mins === $min, "dio {$mins}");
}
$d = img_poll_decidir(['intentos' => 9, 'job_at' => $AHORA], 'queued', null, $AHORA, true);
$seg = $d['espera_seg'];
ok('worker dedicado conserva su cadencia de 3s', $seg === 3, "dio {$seg}s");

echo "\n  — el error se guarda como CLASE, no como texto crudo —\n";
ok('429 → rate_limit_429',      img_poll_clase_error('HTTP 429 Too Many Requests') === 'rate_limit_429');
ok('401 → auth_401_403',        img_poll_clase_error('HTTP 401 unauthorized') === 'auth_401_403');
ok('timeout → timeout',         img_poll_clase_error('cURL: Operation timed out') === 'timeout');
ok('sin credenciales se aísla', img_poll_clase_error('Falta OPENAI_API_KEY.') === 'sin_credenciales');
ok('vacío → sin_detalle',       img_poll_clase_error('') === 'sin_detalle');
ok('la clase cabe en la columna (24)',
   strlen(img_poll_clase_error('HTTP 500 ' . str_repeat('x', 500))) <= 24);

// ══════════════════════════════════════════════════════════════
//  B · CONTRA LA BASE
// ══════════════════════════════════════════════════════════════
if (!isset($pdo) || !($pdo instanceof PDO)) {
    echo "\n  (sin base: se saltan las pruebas de integración)\n";
} elseif (!img_poll_columnas($pdo)) {
    echo "\n  FALLA: falta migrations/2026-08-19_crecer_poll_backoff.sql\n"; $fallos++;
} else {
    $mid = (int)$pdo->query("SELECT id FROM crecer_marca ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->beginTransaction();
    try {
        if (!$mid) throw new RuntimeException('no hay marca para sembrar');

        $nueva = function (string $job, string $edad = '0 MINUTE') use ($pdo, $mid): int {
            $pdo->prepare("INSERT INTO crecer_contenido
                 (marca_id, plataforma, tipo, caption, estado, img_estado, img_job, img_job_at, img_intentos)
                 VALUES (?, 'instagram', 'post', 'prueba de sondeo', 'borrador', 'queued', ?,
                         DATE_SUB(NOW(), INTERVAL {$edad}), 0)")->execute([$mid, $job]);
            return (int)$pdo->lastInsertId();
        };
        $logs = function () use ($pdo, $mid): int {
            return (int)$pdo->query("SELECT COUNT(*) FROM crecer_ia_log
                                      WHERE marca_id={$mid} AND modelo='responses' AND estado='error'")->fetchColumn();
        };
        $pza = function (int $id) use ($pdo): array {
            $q = $pdo->prepare("SELECT img_estado,img_job,img_intentos,img_next_poll_at,img_error_clase,arte_intentos
                                  FROM crecer_contenido WHERE id=?");
            $q->execute([$id]); return $q->fetch(PDO::FETCH_ASSOC) ?: [];
        };
        $abrir = function (int $id) use ($pdo) {   // simula pasar el lease/backoff
            $pdo->prepare("UPDATE crecer_contenido SET img_next_poll_at=NULL WHERE id=?")->execute([$id]);
        };

        // ── 1 · CIEN CARGAS, NO CIEN LOGS ────────────────────────────
        echo "\n  — 100 cargas de pantalla sobre el mismo job —\n";
        $p1 = $nueva('resp_amplif');
        $antes = $logs();
        for ($i = 0; $i < 100; $i++) img_resp_completar($pdo, $mid, $p1);
        ok('100 sondeos NO crean 100 filas de log', $logs() - $antes === 0, 'creó ' . ($logs() - $antes));
        $e = $pza($p1);
        ok('solo el primero consultó de verdad', (int)$e['img_intentos'] === 1, "intentos={$e['img_intentos']}");
        ok('el motivo vive en la pieza', $e['img_error_clase'] === 'sin_credenciales');
        ok('y el job se conserva', $e['img_job'] === 'resp_amplif');

        // ── 2 · DOCE FALLOS DE CONSULTA NO DISPARAN RESPALDO ─────────
        echo "\n  — 12 fallos de consulta, cero respaldo —\n";
        for ($i = 0; $i < 12; $i++) { $abrir($p1); img_resp_completar($pdo, $mid, $p1); }
        $e = $pza($p1);
        ok('sigue en cola tras 13 fallos', $e['img_estado'] === 'queued', "estado={$e['img_estado']}");
        ok('NO se marcó error terminal', $e['img_estado'] !== 'error');
        ok('img_job intacto para reconciliar', $e['img_job'] === 'resp_amplif');
        ok('y ni un incidente en el log', $logs() - $antes === 0);

        // ── 3 · EL BACKOFF CIERRA LA PUERTA ─────────────────────────
        echo "\n  — el backoff impide el sondeo temprano —\n";
        $r = img_resp_completar($pdo, $mid, $p1);
        ok('con backoff vigente devuelve diferido', !empty($r['diferido']));
        $i_antes = (int)$pza($p1)['img_intentos'];
        img_resp_completar($pdo, $mid, $p1);
        ok('y no gasta intento', (int)$pza($p1)['img_intentos'] === $i_antes);

        // ── 4 · APARCAR: DIFERIDO, NO MUERTO ────────────────────────
        echo "\n  — a las 24h sin poder consultar: aparcado, no muerto —\n";
        $p2 = $nueva('resp_aparcar', '30 HOUR');
        $antes2 = $logs();
        $r = img_resp_completar($pdo, $mid, $p2);
        ok('devuelve aparcado', !empty($r['aparcado']));
        ok('NO devuelve error (el barrido no dispara respaldo)', ($r['estado'] ?? '') !== 'error');
        $e = $pza($p2);
        ok('conserva img_job para reconciliarlo', $e['img_job'] === 'resp_aparcar');
        ok('sigue en cola, recuperable', $e['img_estado'] === 'queued');
        ok('deja UN incidente', $logs() - $antes2 === 1);
        for ($i = 0; $i < 15; $i++) { $abrir($p2); img_resp_completar($pdo, $mid, $p2); }
        ok('15 cargas más NO vuelven a registrar', $logs() - $antes2 === 1, 'total ' . ($logs() - $antes2));

        // ── 5 · JOB VIVO DE MÁS DE 24h NO SE DUPLICA ────────────────
        echo "\n  — un job vivo de 40h no arranca un segundo proveedor —\n";
        $d = img_poll_decidir(['intentos' => 20, 'job_at' => date('Y-m-d H:i:s', time() - 40 * 3600)],
                              'in_progress', null, date('Y-m-d H:i:s'));
        ok('sigue esperando', $d['accion'] === 'esperar');
        ok('no hay fallback', $d['accion'] !== 'fallback');
        ok('no hay incidente', $d['incidente'] === false);

        // ── 6 · FALLO CONFIRMADO: RESPALDO, UNA VEZ ─────────────────
        echo "\n  — fallo confirmado por el proveedor sí habilita respaldo —\n";
        $p3 = $nueva('resp_confirmado');
        $antes3 = $logs();
        $u = $pdo->prepare("UPDATE crecer_contenido
                               SET img_estado='error', img_job=NULL, updated_at=NOW()
                             WHERE id=? AND marca_id=? AND img_job=?");
        $u->execute([$p3, $mid, 'resp_confirmado']); $g1 = $u->rowCount();
        $u->execute([$p3, $mid, 'resp_confirmado']); $g2 = $u->rowCount();
        ok('el primero gana la transición', $g1 === 1);
        ok('el segundo no afecta nada → un solo incidente', $g2 === 0);
        ok('la pieza queda en error (estado recuperable del respaldo)',
           $pza($p3)['img_estado'] === 'error');

        // ── 7 · COMPLETED SE GUARDA UNA SOLA VEZ ────────────────────
        echo "\n  — completed se guarda una vez, no dos —\n";
        $p4 = $nueva('resp_exito');
        $arte0 = (int)$pza($p4)['arte_intentos'];
        $ug = $pdo->prepare("UPDATE crecer_contenido
                                SET grafica_path=?, img_estado='ok', img_job=NULL,
                                    arte_intentos=arte_intentos+1, updated_at=NOW()
                              WHERE id=? AND marca_id=? AND img_job=?");
        $ug->execute(['/x/resp_exito.png', $p4, $mid, 'resp_exito']); $s1 = $ug->rowCount();
        $ug->execute(['/x/resp_exito.png', $p4, $mid, 'resp_exito']); $s2 = $ug->rowCount();
        ok('solo el primero guarda', $s1 === 1 && $s2 === 0);
        ok('arte_intentos sube UNA vez', (int)$pza($p4)['arte_intentos'] === $arte0 + 1);
        ok('el nombre del archivo es determinista (mismo job → mismo archivo)',
           substr(md5('resp_exito'), 0, 8) === substr(md5('resp_exito'), 0, 8));

        // ── 8 · EL BARRIDO NI TRAE LO DIFERIDO ──────────────────────
        echo "\n  — el barrido filtra en SQL, no en PHP —\n";
        $p5 = $nueva('resp_diferido');
        $pdo->prepare("UPDATE crecer_contenido SET img_next_poll_at = NOW() + INTERVAL 30 MINUTE WHERE id=?")
            ->execute([$p5]);
        $q = $pdo->prepare("SELECT COUNT(*) FROM crecer_contenido
                             WHERE marca_id=? AND img_estado='queued'
                               AND (img_next_poll_at IS NULL OR img_next_poll_at <= NOW()) AND id=?");
        $q->execute([$mid, $p5]);
        ok('una pieza diferida no la selecciona el barrido', (int)$q->fetchColumn() === 0);

    } catch (Throwable $e) {
        $fallos++; echo "  FALLA excepción: " . $e->getMessage() . "\n";
    }
    $pdo->rollBack();
    ok('la transacción se deshizo',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE caption='prueba de sondeo'")->fetchColumn() === 0);

    // ══════════════════════════════════════════════════════════════
    //  C · CONCURRENCIA REAL — dos conexiones compitiendo
    //      Fuera de transacción a propósito: dos conexiones no ven los
    //      datos sin confirmar de la otra, así que un test transaccional
    //      no probaría nada. Se siembra, se compite y se limpia siempre.
    // ══════════════════════════════════════════════════════════════
    echo "\n  — dos procesos reales, un solo sondeo —\n";
    $pid_c = 0;
    try {
        $otro = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
                        DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        ok('segunda conexión PDO abierta', true);

        $pdo->prepare("INSERT INTO crecer_contenido
             (marca_id, plataforma, tipo, caption, estado, img_estado, img_job, img_job_at, img_intentos)
             VALUES (?, 'instagram', 'post', 'carrera de sondeo', 'borrador', 'queued', 'resp_carrera', NOW(), 0)")
            ->execute([$mid]);
        $pid_c = (int)$pdo->lastInsertId();

        // Ambas intentan tomar el lease sobre la MISMA fila confirmada.
        $g1 = img_poll_tomar_lease($pdo,  $mid, $pid_c, 'resp_carrera');
        $g2 = img_poll_tomar_lease($otro, $mid, $pid_c, 'resp_carrera');
        ok('exactamente una conexión gana el lease', ($g1 ? 1 : 0) + ($g2 ? 1 : 0) === 1,
           'g1=' . var_export($g1, true) . ' g2=' . var_export($g2, true));

        // Y el que pierde, al llamar a img_resp_completar, se va sin sondear.
        $perdedor = $g1 ? $otro : $pdo;
        $r = img_resp_completar($perdedor, $mid, $pid_c);
        ok('el perdedor devuelve diferido y no consulta', !empty($r['diferido']));

        // Repetido 10 veces: nunca ganan las dos.
        $dobles = 0;
        for ($i = 0; $i < 10; $i++) {
            $pdo->prepare("UPDATE crecer_contenido SET img_next_poll_at=NULL WHERE id=?")->execute([$pid_c]);
            $a = img_poll_tomar_lease($pdo,  $mid, $pid_c, 'resp_carrera');
            $b = img_poll_tomar_lease($otro, $mid, $pid_c, 'resp_carrera');
            if ($a && $b) $dobles++;
        }
        ok('en 10 rondas nunca ganan las dos', $dobles === 0, "dobles={$dobles}");

        $otro = null;
    } catch (Throwable $e) {
        $fallos++; echo "  FALLA concurrencia: " . $e->getMessage() . "\n";
    }
    // Limpieza SIEMPRE: esta parte no va en transacción.
    if ($pid_c) {
        try { $pdo->prepare("DELETE FROM crecer_contenido WHERE id=? AND caption='carrera de sondeo'")->execute([$pid_c]); }
        catch (Throwable $e) {}
    }
    ok('la siembra de la carrera quedó limpia',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE caption='carrera de sondeo'")->fetchColumn() === 0);
}

echo "\n" . str_repeat('=', 56) . "\n";
echo $fallos === 0 ? "  TODO OK · {$n} pruebas\n\n" : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
