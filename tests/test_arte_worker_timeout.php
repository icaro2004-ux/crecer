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

// Igual que el runner: las llaves se anulan ANTES de que db.php cargue el
// config (define() es primero-gana). Este archivo también llama al barrido, y
// el barrido rescata piezas con Gemini — si el candado fallara, la prueba lo
// demostraría gastando. Puede fallar; no puede facturar.
define('OPENAI_API_KEY', '');
define('GEMINI_API_KEY', '');
define('GCP_PROJECT_ID', '');
define('GOOGLE_APPLICATION_CREDENTIALS', '');

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
$sucias  = [];   // [usuario_id, marca_id] sembrados para los otros dos llamadores

/** Siembra una pieza en cola. job=null → todavía no se ha encolado nada.
 *  Se COMETE: el worker corre en otro proceso y no vería una transacción. */
$sembrar = function (?string $job, string $edad = '0 MINUTE', int $intentos = 0) use ($pdo, $mid, $MARCA, &$creadas): int {
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
// Limpieza previa por si una corrida anterior murió a medias.
$pdo->prepare("DELETE FROM crecer_contenido WHERE marca_id=? AND caption=?")->execute([$mid, $MARCA]);
$pdo->prepare("DELETE FROM crecer_notificaciones WHERE marca_id=? AND tipo='arte'
                 AND titulo IN ('Tu arte va en camino','No se pudo crear el arte','Tu arte ya está listo')
                 AND created_at > (NOW() - INTERVAL 30 MINUTE)")->execute([$mid]);

$lanzar = function (int $pid, int $sondeos = 3, string $fb = '', string $sim = '') use ($PHP, $RUNNER, $mid): string {
    $cmd = escapeshellarg($PHP) . ' ' . escapeshellarg($RUNNER)
         . ' ' . $mid . ' ' . $pid . ' ' . $sondeos
         . ' ' . escapeshellarg($fb !== '' ? $fb : '-')
         . ($sim !== '' ? ' ' . escapeshellarg($sim) : '')
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

    // ══════════════════════════════════════════════════════════
    //  D · AL ENCOLAR, EL PROVEEDOR CONTESTA QUE NO (400)
    //      OpenAI respondió: no quedó trabajo creado. Nadie va a
    //      cobrar dos veces, así que el respaldo SÍ corresponde.
    // ══════════════════════════════════════════════════════════
    echo "\n  — encolar rechazado por el proveedor —\n";
    $pdo->prepare("DELETE FROM crecer_notificaciones WHERE marca_id=? AND tipo='arte'")->execute([$mid]);
    $id4 = $sembrar(null);                 // sin job: el worker tiene que encolar
    $lanzar($id4, 3, '', 'rechazo');
    $p4 = $pza($id4);

    ok('rechazo confirmado SÍ habilita el respaldo', $aviso('No se pudo crear el arte'),
       'sin trabajo creado no hay riesgo de pagar dos imágenes');
    ok('no se marcó como incierta', !str_starts_with((string)($p4['img_error_clase'] ?? ''), 'enc:'),
       'clase=' . (string)($p4['img_error_clase'] ?? 'null'));

    // ══════════════════════════════════════════════════════════
    //  E · AL ENCOLAR, LA PETICIÓN SE VA EN TIMEOUT
    //      No sabemos si OpenAI creó el trabajo. Es el caso que
    //      podía generar —y pagar— dos imágenes.
    // ══════════════════════════════════════════════════════════
    echo "\n  — encolar incierto por timeout —\n";
    $pdo->prepare("DELETE FROM crecer_notificaciones WHERE marca_id=? AND tipo='arte'")->execute([$mid]);
    $id5 = $sembrar(null);
    $lanzar($id5, 3, '', 'timeout');
    $p5 = $pza($id5);

    ok('NO se llamó al respaldo', !$aviso('No se pudo crear el arte'),
       'el trabajo pudo quedar creado: pedir otra imagen sería pagarla dos veces');
    ok('NO se dio la pieza por lista', !$aviso('Tu arte ya está listo'));
    ok('el respaldo no dejó huella en el log', $logRespaldo() === 0);
    ok('el aviso NO afirma que el arte viene en camino', !$aviso('Tu arte va en camino'),
       'puede que el trabajo ni se creara: decir que viene sería afirmar de más');
    ok('el aviso dice la verdad y ofrece reintento', $aviso('No pude confirmar la creación del arte'));
    ok('la pieza sigue en cola', (string)($p5['img_estado'] ?? '') === 'queued');
    ok('quedó marcada como encolado incierto',
       (string)($p5['img_error_clase'] ?? '') === 'enc:timeout',
       'clase=' . (string)($p5['img_error_clase'] ?? 'null'));
    ok('no se gastó un intento de arte', (int)($p5['arte_intentos'] ?? -1) === 0);
    ok('no se guardó ninguna imagen', (string)($p5['grafica_path'] ?? '') === '');

    // ══════════════════════════════════════════════════════════
    //  F · EL BARRIDO TAMPOCO PUEDE RESCATARLA
    //      Sin este candado el arreglo duraría dos minutos: el
    //      barrido rescata con Gemini las piezas sin job.
    // ══════════════════════════════════════════════════════════
    //      Sin llaves el rescate falla y no deja rastro en la base:
    //      mirar la base NO distingue "no la rescató" de "la rescató
    //      y no pudo". Por eso se lee el log del barrido, donde la
    //      decisión queda escrita ANTES de llamar al proveedor.
    echo "\n  — el barrido respeta la marca de incierto —\n";
    $id7 = $sembrar(null);   // contraste: colgada, pero SIN marca de incierto
    $pdo->prepare("UPDATE crecer_contenido SET updated_at = NOW() - INTERVAL 10 MINUTE
                    WHERE id IN (?,?)")->execute([$id5, $id7]);
    $pdo->prepare("DELETE FROM crecer_notificaciones WHERE marca_id=? AND tipo='arte'")->execute([$mid]);

    $logBarrido = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crecer_barrido_' . getmypid() . '.log';
    @unlink($logBarrido);
    exec(escapeshellarg($PHP) . ' ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . '_sweep_runner.php')
         . ' ' . $mid . ' ' . escapeshellarg($logBarrido) . ' 2>&1');
    $traza = is_file($logBarrido) ? (string)file_get_contents($logBarrido) : '';
    @unlink($logBarrido);
    $p6 = $pza($id5);

    ok('el barrido NI SIQUIERA intentó rescatarla',
       strpos($traza, "rescate #{$id5}") === false,
       'la decisión de rescatar se escribe antes de llamar al proveedor');
    ok('el barrido no la dio por lista', !$aviso('Tu arte ya está listo'));
    ok('el respaldo no dejó huella en el log', $logRespaldo() === 0);
    ok('sigue en cola y sin imagen', (string)($p6['img_estado'] ?? '') === 'queued'
        && (string)($p6['grafica_path'] ?? '') === '');
    ok('conserva la marca de incierto', (string)($p6['img_error_clase'] ?? '') === 'enc:timeout');

    // El contraste prueba que el barrido SÍ corrió y que la traza sirve para
    // distinguir: sin esto, "no aparece en el log" podría ser que no barrió.
    ok('una pieza colgada SIN la marca sí entra al rescate',
       strpos($traza, "rescate #{$id7}") !== false,
       'si esta falla, el candado no se está probando: el barrido no rescató a nadie');

    // ══════════════════════════════════════════════════════════
    //  G · EL WORKER SE VUELVE A EJECUTAR: UN SOLO AVISO
    // ══════════════════════════════════════════════════════════
    echo "\n  — el worker corre dos veces y el aviso no se duplica —\n";
    $pdo->prepare("DELETE FROM crecer_notificaciones WHERE marca_id=? AND tipo='arte'")->execute([$mid]);
    $id8 = $sembrar('resp_dup_' . bin2hex(random_bytes(4)), '0 MINUTE');
    $lanzar($id8, 2);
    $lanzar($id8, 2);
    $cuenta = $pdo->prepare("SELECT COUNT(*) FROM crecer_notificaciones
                              WHERE marca_id=? AND tipo='arte' AND titulo='Tu arte va en camino'");
    $cuenta->execute([$mid]);
    ok('dos corridas dejan UN solo aviso', (int)$cuenta->fetchColumn() === 1);

    // ══════════════════════════════════════════════════════════
    //  LOS OTROS DOS LLAMADORES
    //      El worker no era el único que encolaba. agentes.php
    //      (primer post del negocio) y gateway_post.php (regenerar)
    //      leían la cadena vacía como permiso para el motor viejo,
    //      y ese fallback es INMEDIATO: la marca 'enc:' frena al
    //      barrido, pero no frenaba a estos.
    //
    //      El motor viejo deja una huella inconfundible en
    //      crecer_ia_log: agente 'creador', acción 'Crear arte de
    //      post'. Se cuenta eso.
    // ══════════════════════════════════════════════════════════
    $motorViejo = function () use ($pdo): int {
        return (int)$pdo->query("SELECT COUNT(*) FROM crecer_ia_log
                                  WHERE accion='Crear arte de post'
                                    AND created_at > (NOW() - INTERVAL 3 MINUTE)")->fetchColumn();
    };
    $limpiarLog = function () use ($pdo) {
        $pdo->query("DELETE FROM crecer_ia_log WHERE accion='Crear arte de post'
                       AND created_at > (NOW() - INTERVAL 3 MINUTE)");
    };
    /** Marca virgen: crear_post_muestra es idempotente y devolvería el post que ya hubiera. */
    $marcaNueva = function () use ($pdo, &$sucias): array {
        $em = 'prueba.enc.' . bin2hex(random_bytes(4)) . '@prueba.local';
        $pdo->prepare("INSERT INTO usuarios (nombre,email,password,rol,verificado,activo)
                       VALUES ('Prueba encolar',?,?, 'admin',1,1)")
            ->execute([$em, password_hash('x', PASSWORD_DEFAULT)]);
        $u = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO crecer_marca (usuario_id,nombre_negocio) VALUES (?, 'Negocio de prueba')")
            ->execute([$u]);
        $m = (int)$pdo->lastInsertId();
        $sucias[] = [$u, $m];
        return [$u, $m];
    };

    echo "\n  — agentes.php: el primer post del negocio —\n";
    [$u1, $m1] = $marcaNueva();
    $limpiarLog();
    exec(escapeshellarg($PHP) . ' ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . '_agentes_runner.php')
         . ' ' . $m1 . ' timeout 2>&1', $sal1);
    $pz1 = $pdo->query("SELECT id,img_estado,img_error_clase,grafica_path FROM crecer_contenido
                         WHERE marca_id={$m1} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];

    ok('encolado incierto: NO corre el motor viejo', $motorViejo() === 0,
       'el fallback aquí es inmediato — la marca enc: no lo frena');
    ok('la pieza queda en cola', (string)($pz1['img_estado'] ?? '') === 'queued');
    ok('la pieza queda marcada', (string)($pz1['img_error_clase'] ?? '') === 'enc:timeout',
       'clase=' . (string)($pz1['img_error_clase'] ?? 'null'));
    ok('no se guardó ninguna imagen', (string)($pz1['grafica_path'] ?? '') === '');

    [$u2, $m2] = $marcaNueva();
    $limpiarLog();
    exec(escapeshellarg($PHP) . ' ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . '_agentes_runner.php')
         . ' ' . $m2 . ' rechazo 2>&1', $sal2);
    ok('rechazo confirmado: el motor viejo SÍ corre', $motorViejo() > 0,
       'sin trabajo creado no hay riesgo: el fallback es legítimo');

    echo "\n  — gateway_post.php: regenerar la imagen —\n";
    [$u3, $m3] = $marcaNueva();
    $pdo->prepare("INSERT INTO crecer_contenido (marca_id,plataforma,tipo,caption,estado)
                   VALUES (?, 'instagram','post','post de prueba gw','borrador')")->execute([$m3]);
    $pg = (int)$pdo->lastInsertId();
    $limpiarLog();
    $sal3 = [];
    exec(escapeshellarg($PHP) . ' ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . '_gateway_runner.php')
         . " {$u3} {$m3} {$pg} timeout 2>&1", $sal3);
    $json = json_decode((string)end($sal3), true);
    $pz3 = $pza($pg);

    ok('el endpoint contestó JSON', is_array($json), 'salida: ' . mb_substr((string)end($sal3), 0, 120));
    ok('encolado incierto: NO corre el motor viejo', $motorViejo() === 0);
    ok('lo reporta como incierto, no como fallo', !empty($json['incierto']));
    ok('el mensaje no afirma que el arte se creó',
       is_array($json) && strpos((string)($json['err'] ?? ''), 'No pude confirmar') === 0,
       'err=' . mb_substr((string)($json['err'] ?? ''), 0, 80));
    ok('la pieza queda marcada', (string)($pz3['img_error_clase'] ?? '') === 'enc:timeout');

    $limpiarLog();
    $sal4 = [];
    exec(escapeshellarg($PHP) . ' ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . '_gateway_runner.php')
         . " {$u3} {$m3} {$pg} rechazo 2>&1", $sal4);
    ok('rechazo confirmado: el motor viejo SÍ corre', $motorViejo() > 0);
    ok('el rechazo confirmado limpia la marca de incierto',
       (string)($pza($pg)['img_error_clase'] ?? '') === '',
       'la duda quedó resuelta: la pieza vuelve a ser recogible');

    $limpiarLog();

    // ══════════════════════════════════════════════════════════
    //  EL BACKOFF CONTRA DOS RELOJES DESFASADOS
    //      Medido en produccion: la pieza #644 subio de 2 a 14
    //      intentos con diez recargas y su vencimiento quedaba 182
    //      minutos EN EL PASADO. La causa: el vencimiento lo
    //      calculaba PHP (zona APP_TZ) y lo comparaba MySQL (UTC en
    //      Hostinger), asi que nacia cuatro horas vencido.
    //
    //      La pieza #640 no se movia porque estaba aparcada, y
    //      aparcar SI ponia la fecha con DATE_ADD(NOW()) — del lado
    //      de MySQL. Ese contraste fue lo que delato el defecto.
    // ══════════════════════════════════════════════════════════
    echo "\n  — el backoff aguanta que PHP y MySQL vayan en zonas distintas —\n";
    $jobTz = 'resp_tz_' . bin2hex(random_bytes(4));
    $idTz  = $sembrar($jobTz, '10 MINUTE', 2);
    $pdo->prepare("UPDATE crecer_contenido SET img_next_poll_at = NOW() - INTERVAL 3 HOUR WHERE id=?")
        ->execute([$idTz]);

    $salTz = [];
    exec(escapeshellarg($PHP) . ' ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . '_sweep_tz_runner.php')
         . " {$mid} {$idTz} 10 2>&1", $salTz);
    $tz = [];
    foreach ($salTz as $l) if (strpos($l, '=') !== false) { [$k, $v] = explode('=', trim($l), 2); $tz[$k] = $v; }

    ok('el escenario reprodujo el desfase de relojes',
       isset($tz['DESFASE_SEG']) && abs((int)$tz['DESFASE_SEG']) > 3600,
       'desfase=' . ($tz['DESFASE_SEG'] ?? 'n/d') . 's — sin desfase esta prueba no prueba nada');
    ok('diez barridos suben img_intentos UNA vez como mucho',
       isset($tz['INTENTOS_DESPUES']) && ((int)$tz['INTENTOS_DESPUES'] - (int)$tz['INTENTOS_ANTES']) <= 1,
       'de ' . ($tz['INTENTOS_ANTES'] ?? '?') . ' a ' . ($tz['INTENTOS_DESPUES'] ?? '?')
       . ' — si sube diez, cada recarga volvio a sondear');
    ok('el vencimiento queda en el FUTURO para MySQL', (int)($tz['FUTURO'] ?? 0) === 1,
       'faltan ' . ($tz['FALTAN_MIN'] ?? '?') . ' min — negativo significa que nacio vencido');

} finally {
    if ($creadas) {
        $pdo->prepare("DELETE FROM crecer_contenido WHERE id IN ("
            . implode(',', array_map('intval', $creadas)) . ")")->execute();
    }
    $pdo->prepare("DELETE FROM crecer_notificaciones WHERE marca_id=? AND tipo='arte'
                     AND titulo IN ('Tu arte va en camino','No se pudo crear el arte',
                                    'No pude confirmar la creación del arte','Tu arte ya está listo')
                     AND created_at > (NOW() - INTERVAL 30 MINUTE)")->execute([$mid]);
    foreach ($sucias as [$u, $m]) {
        $pdo->prepare("DELETE FROM crecer_notificaciones WHERE marca_id=?")->execute([$m]);
        $pdo->prepare("DELETE FROM crecer_contenido WHERE marca_id=?")->execute([$m]);
        $pdo->prepare("DELETE FROM crecer_calendario WHERE marca_id=?")->execute([$m]);
        $pdo->prepare("DELETE FROM crecer_ia_log WHERE marca_id=?")->execute([$m]);
        $pdo->prepare("DELETE FROM crecer_marca WHERE id=?")->execute([$m]);
        $pdo->prepare("DELETE FROM usuarios WHERE id=?")->execute([$u]);
    }
    echo "\n  (siembra y avisos de prueba limpiados)\n";
}

echo "\n" . str_repeat('=', 68) . "\n";
echo $fallos === 0 ? "  TODO OK · {$n} pruebas\n\n" : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
