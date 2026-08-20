<?php
// ============================================================
//  CRECER — LABORATORIO de imágenes (interno)  _imgtry.php?k=crecer
//  Banco de investigación para afinar al agente creador.
//   FLUJO (intacto):  copy → agente V3 → escena → prompt → gpt-image-1 → imagen
//   Capa de investigación (añadida): historial persistente, calificación 1-10,
//   observaciones, "Analizar imagen" (crítica publicitaria por visión),
//   hipótesis por experimento y búsqueda. NADA se borra.
// ============================================================
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/ia.php';
require __DIR__ . '/includes/agentes.php';
require __DIR__ . '/includes/image_messenger.php';

// Herramienta INTERNA (gasta API): SOLO administradores (auth real, no ?k= público).
require __DIR__ . '/includes/auth.php';
requiere_login();
$usuario = usuario_actual($pdo);
// SOLO ADMIN (2026-08-14). El `||` de antes dejaba entrar a las cuentas de
// CRECER_TEST_EMAILS — y ahi vive la cuenta de evaluacion del jurado, cuyas
// credenciales se publican. Este laboratorio GASTA API de verdad (~$0.17 por
// imagen): no puede quedar al alcance de nadie a quien se le da una contraseña.
if (($usuario['rol'] ?? '') !== 'admin') {
    http_response_code(403); exit('Solo administradores.');
}

/** Carga un experimento por id (o null). */
//  EL LABORATORIO TAMBIEN SE ASIENTA. Es exento —el gasto de afinar el motor
//  es nuestro, no del cliente— pero exento no es invisible: cada prueba deja su
//  fila con su ruta y su motivo. Un gasto que no se ve no se puede auditar.
function _lab_cuota(PDO $pdo, string $ruta, int $n): CuotaCtx {
    require_once __DIR__ . '/includes/cuota_imagenes.php';
    return CuotaCtx::de($pdo, 0, 'laboratorio', $ruta,
        ['exencion' => 'laboratorio', 'origen_tipo' => 'banco',
         'origen_id' => $n * 1000 + (int)(microtime(true) * 10) % 1000, 'costo' => 0.17]);
}

function lab_exp(PDO $pdo, int $id): ?array {
    try { $s = $pdo->prepare("SELECT * FROM crecer_lab_experimentos WHERE id=?"); $s->execute([$id]);
          $r = $s->fetch(PDO::FETCH_ASSOC); return $r ?: null; } catch (Throwable $e) { return null; }
}
/** URL de uploads → ruta absoluta en disco. */
function lab_abs(string $url): string {
    $rel = ltrim(str_replace(rtrim(UPLOADS_URL, '/'), '', $url), '/');
    return rtrim(UPLOADS_PATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
}
/** Brief natural compartido por el Modo ChatGPT y por la variante directa del comparador. */
function lab_brief_natural(array $m, string $copy): string {
    $nombre  = trim((string)($m['nombre_negocio'] ?? ''));
    $desc    = trim((string)($m['descripcion'] ?? ''));
    $publico = trim((string)($m['publico_objetivo'] ?? ''));
    $prods_raw = $m['productos'] ?? []; if (is_string($prods_raw)) $prods_raw = json_decode($prods_raw, true) ?: [];
    $plist = []; foreach ((array)$prods_raw as $p) { $n = is_array($p) ? trim((string)($p['nombre'] ?? '')) : trim((string)$p); if ($n !== '') $plist[] = $n; }
    $prods = implode(', ', $plist);
    return "Crea una imagen publicitaria profesional para redes sociales (Facebook e Instagram) para este negocio puertorriqueño.\n\n"
         . "Negocio: {$nombre}\nQué hace: {$desc}\n"
         . ($prods !== '' ? "Productos: {$prods}\n" : '')
         . ($publico !== '' ? "Público: {$publico}\n" : '')
         . "\nTexto del post que la imagen va a acompañar:\n\"{$copy}\"\n\n"
         . "La imagen debe detener el scroll y dar ganas de comprar. Genera la mejor imagen publicitaria posible.";
}

/** Log del worker (para diagnosticar arranque/duración/errores). */
function lab_log(string $m): void {
    $d = __DIR__ . '/storage/logs'; @mkdir($d, 0775, true);
    @file_put_contents($d . '/lab_worker.log', date('c') . ' ' . $m . "\n", FILE_APPEND);
}

/** Fire-and-forget: arranca el worker por auto-HTTP (responde al instante). */
function lab_fire(int $id, string $aspect, string $motor = 'img', string $rend = ''): void {
    $host = $_SERVER['HTTP_HOST'] ?? 'encuentraloahora.com';
    $url  = 'https://' . $host . '/crecer/_imgtry.php?k=crecer&work=' . $id . '&a=' . rawurlencode($aspect) . '&motor=' . $motor
          . ($rend !== '' ? '&r=' . rawurlencode($rend) : '');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT_MS=>1500,
        CURLOPT_TIMEOUT_MS=>2500, CURLOPT_NOSIGNAL=>1, CURLOPT_SSL_VERIFYPEER=>false]);
    curl_exec($ch); curl_close($ch);
}

// ===== WORKER: genera por detrás (inmune al 504 de nginx) =====
if (isset($_GET['work'])) {
    $wid = (int)$_GET['work']; $wa = (string)($_GET['a'] ?? '1:1'); $wmotor = (string)($_GET['motor'] ?? 'img');
    lab_log("work={$wid} motor={$wmotor} ARRANCÓ (fcfr=" . (function_exists('fastcgi_finish_request')?'sí':'no') . ")");
    if (function_exists('fastcgi_finish_request')) { echo 'ok'; @fastcgi_finish_request(); }
    @set_time_limit(0); @ignore_user_abort(true);
    $e = lab_exp($pdo, $wid);
    if ($e && $e['estado'] === 'queued') {
        try {
            $t0 = microtime(true);
            if ($wmotor === 'responses') {
                lab_log("work={$wid} llamando openai_responses_imagen…");
                // MODO ChatGPT: el modelo se dirige solo (Responses API). El 'prompt' = el brief.
                //  RUTA 12 — laboratorio. Exento y asentado.
                $r = openai_responses_imagen((string)$e['prompt'], ['aspect' => $wa,
                    'cuota' => _lab_cuota($pdo, 'imgtry_resp_lote', 12)]);
                $seg = round(microtime(true) - $t0, 1);
                $rel = 'pruebas/lab_' . substr(md5((string)microtime(true)), 0, 8) . '.png';
                $abs = rtrim(UPLOADS_PATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                @mkdir(dirname($abs), 0775, true);
                @file_put_contents($abs, $r['data']);
                $url = rtrim(UPLOADS_URL, '/') . '/' . $rel;
                $rev = trim((string)($r['revised'] ?? ''));
                // 'prompt' pasa a ser el prompt REAL que el modelo escribió (para comparar).
                $pdo->prepare("UPDATE crecer_lab_experimentos SET estado='ok', imagen=?, bytes=?, modelo=?, segundos=?, prompt=? WHERE id=?")
                    ->execute([$url, strlen($r['data']), $r['modelo'], $seg, ($rev !== '' ? $rev : (string)$e['prompt']), $wid]);
                lab_log("work={$wid} OK {$seg}s modelo={$r['modelo']}");
            } else {
                // Modo directo: gpt-image-1 (o el renderizador que pida &r=, ej. gpt-image-2).
                $wrend = trim((string)($_GET['r'] ?? ''));
                $oimg = ['aspect' => $wa];
                if ($wrend !== '') $oimg['modelo_openai'] = $wrend;
                //  RUTA 13
                $r  = openai_imagen((string)$e['prompt'], $oimg + ['cuota' => _lab_cuota($pdo, 'imgtry_openai_lote', 13)]);
                $seg = round(microtime(true) - $t0, 1);
                $rel = 'pruebas/lab_' . substr(md5((string)microtime(true)), 0, 8) . '.png';
                $abs = rtrim(UPLOADS_PATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                @mkdir(dirname($abs), 0775, true);
                @file_put_contents($abs, $r['data']);
                $url = rtrim(UPLOADS_URL, '/') . '/' . $rel;
                $pdo->prepare("UPDATE crecer_lab_experimentos SET estado='ok', imagen=?, bytes=?, modelo=?, segundos=? WHERE id=?")
                    ->execute([$url, strlen($r['data']), $r['modelo'] ?? null, $seg, $wid]);
            }
        } catch (Throwable $ex) {
            $pdo->prepare("UPDATE crecer_lab_experimentos SET estado='error', observaciones=CONCAT('[error] ', ?) WHERE id=?")
                ->execute([substr($ex->getMessage(), 0, 400), $wid]);
            lab_log("work={$wid} ERROR " . substr($ex->getMessage(), 0, 300));
        }
    } else { lab_log("work={$wid} no estaba 'queued' (estado=" . ($e['estado'] ?? 'no existe') . ")"); }
    exit;
}

// ===== VISOR del log del worker =====
if (isset($_GET['wlog'])) {
    header('Content-Type: text/plain; charset=utf-8');
    $f = __DIR__ . '/storage/logs/lab_worker.log';
    echo is_file($f) ? (string)file_get_contents($f) : '(log vacío — el worker nunca escribió)';
    exit;
}

// ===== EVIDENCIA CRUDA del image_generation_call de un experimento Responses =====
if (isset($_GET['raw'])) {
    header('Content-Type: application/json; charset=utf-8');
    $f = __DIR__ . '/storage/logs/responses/exp_' . (int)$_GET['raw'] . '.json';
    echo is_file($f) ? (string)file_get_contents($f)
        : json_encode(['error' => 'No hay crudo para ese exp. Genera uno NUEVO en Modo ChatGPT (los viejos no lo tienen).'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ===== DIAGNÓSTICO de configuración efectiva (qué corre PRODUCCIÓN) =====
if (isset($_GET['cfg'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "IMAGE_PIPELINE:       " . (defined('IMAGE_PIPELINE') ? IMAGE_PIPELINE : '(no def)') . "   <- v1 = rulebook VIEJO (direccion_arte) · v2 = cerebro único V3 (image_messenger)\n";
    echo "IMAGE_CREATIVE_MODEL: " . (defined('IMAGE_CREATIVE_MODEL') ? IMAGE_CREATIVE_MODEL : '(no def)') . "\n";
    echo "  -> resuelve a:      " . resolver_modelo_ia(defined('IMAGE_CREATIVE_MODEL') ? IMAGE_CREATIVE_MODEL : 'openai:creative') . "\n";
    echo "OPENAI_IMG_MODEL:     " . (defined('OPENAI_IMG_MODEL') ? OPENAI_IMG_MODEL : '(no def)') . "   (renderizador del camino DIRECTO)\n";
    echo "OPENAI_IMG_QUALITY:   " . (defined('OPENAI_IMG_QUALITY') ? OPENAI_IMG_QUALITY : '(no def)') . "\n";
    echo "Director (image_messenger) tope de salida: 700 tokens (max_completion_tokens; en gpt-5.x INCLUYE tokens de razonamiento)\n";
    echo "OpenAI configurado:   " . (openai_configurado() ? 'sí' : 'NO') . "\n";
    exit;
}

// ===== EXPORTAR el paquete técnico completo de una comparación (JSON indivisible) =====
if (isset($_GET['cmpjson'])) {
    header('Content-Type: application/json; charset=utf-8');
    $cid = (string)$_GET['cmpjson'];
    $rs = $pdo->prepare("SELECT * FROM crecer_lab_experimentos WHERE comparison_id=? ORDER BY variante");
    $rs->execute([$cid]);
    $out = ['comparison_id' => $cid, 'variantes' => []];
    foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rf = __DIR__ . '/storage/logs/responses/exp_' . (int)$r['id'] . '.json';
        $out['variantes'][] = [
            'exp_id'        => (int)$r['id'],
            'variante'      => $r['variante'],
            'modo'          => json_decode((string)$r['meta_json'], true)['modo'] ?? null,
            'estado'        => $r['estado'],
            'negocio'       => $r['negocio'],
            'copy'          => $r['copy_txt'],
            'brief_o_escena'=> $r['escena'],
            'prompt_final'  => $r['prompt'],
            'modelo'        => $r['modelo'],
            'bytes'         => (int)$r['bytes'],
            'segundos'      => $r['segundos'],
            'imagen'        => $r['imagen'],
            'meta'          => json_decode((string)$r['meta_json'], true),
            'image_generation_call_raw' => is_file($rf) ? json_decode((string)file_get_contents($rf), true) : null,
            'evaluacion'    => json_decode((string)$r['eval_json'], true),
        ];
    }
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ===== VISTA COMPARATIVA — 3 modos lado a lado, ciego (A/B/C barajadas) =====
if (isset($_GET['cmp'])) {
    $cid = (string)$_GET['cmp'];
    $rs = $pdo->prepare("SELECT * FROM crecer_lab_experimentos WHERE comparison_id=? ORDER BY variante");
    $rs->execute([$cid]);
    $rows = $rs->fetchAll(PDO::FETCH_ASSOC);
    $H = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $pend = [];
    foreach ($rows as $r) if (!in_array($r['estado'], ['ok','error'], true)) $pend[] = (int)$r['id'];
    $sel = fn($n,$v) => (string)$v === (string)$n ? 'selected' : '';
    ?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comparación · Laboratorio Crecer</title><style>
      *{box-sizing:border-box}body{font-family:system-ui,-apple-system,sans-serif;max-width:1150px;margin:0 auto;padding:20px 16px 70px;background:#faf9f8;color:#231F20}
      h1{font-size:21px;margin:0 0 4px}a{color:#EF4375}.top{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px}
      .grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}@media(max-width:820px){.grid{grid-template-columns:1fr}}
      .card{background:#fff;border:1px solid #E9E7E4;border-radius:14px;overflow:hidden}
      .card h2{margin:0;padding:12px 14px;font-size:20px;background:#231F20;color:#fff;text-align:center;letter-spacing:.05em}
      .card img{width:100%;display:block;background:#eee;aspect-ratio:1;object-fit:cover}
      .wait{aspect-ratio:1;display:flex;align-items:center;justify-content:center;color:#8a8a8a;font-weight:600;text-align:center;padding:16px;background:#f3f2f1}
      .modo{display:none;font-size:12px;font-weight:800;color:#00827e;text-align:center;padding:6px;background:#e9f8f6}
      .modo.err{color:#b42318;background:#fdeaea}
      .ev{padding:12px 13px}.ev label{display:block;font-size:11.5px;font-weight:700;color:#6E6A67;margin:8px 0 3px}
      .ev select,.ev textarea{width:100%;padding:7px 8px;border:1.5px solid #E9E7E4;border-radius:8px;font:13px system-ui}
      .ev .two{display:grid;grid-template-columns:1fr 1fr;gap:8px}
      button{background:#EF4375;color:#fff;border:0;padding:9px 15px;border-radius:9px;font-weight:700;font-size:13px;cursor:pointer;margin-top:10px}
      button.gho{background:#fff;border:1.5px solid #E9E7E4;color:#333}
      .err{background:#fdeaea;color:#b42318;padding:8px 11px;border-radius:8px;font-size:12.5px;margin:8px 13px}
    </style></head><body>
    <div class="top">
      <h1>🆚 Comparación ciega — <?= $H($rows[0]['negocio'] ?? '') ?></h1>
      <div><button class="gho" onclick="document.querySelectorAll('.modo').forEach(function(e){e.style.display='block'})">Revelar modos</button>
        <a href="?k=crecer&cmpjson=<?= $H($cid) ?>" target="_blank" style="margin-left:10px">Ver JSON técnico ↗</a>
        <a href="?k=crecer" style="margin-left:10px">← Lab</a></div>
    </div>
    <?php if (!$rows): ?><p>No existe esa comparación.</p><?php else: ?>
    <p style="color:#6E6A67;font-size:13.5px;margin:0 0 14px">Copy: <em><?= $H(mb_strimwidth((string)$rows[0]['copy_txt'],0,160,'…')) ?></em> · Califica cada una SIN saber qué modo es; después revela.</p>
    <div class="grid">
      <?php foreach ($rows as $r): $est=$r['estado']; $ev=json_decode((string)$r['eval_json'],true)?:[]; $modo=json_decode((string)$r['meta_json'],true)['modo']??'?'; ?>
      <div class="card">
        <h2><?= $H($r['variante']) ?></h2>
        <?php if ($est==='ok' && $r['imagen']): ?><img src="<?= $H($r['imagen']) ?>" alt="">
        <?php elseif ($est==='error'): ?><div class="wait" style="color:#b42318">❌ Falló<br><small><?= $H(mb_strimwidth((string)$r['observaciones'],0,120,'…')) ?></small></div>
        <?php else: ?><div class="wait">⏳ generando…</div><?php endif; ?>
        <div class="modo <?= $est==='error'?'err':'' ?>"><?= $H($modo) ?> · <?= $H($r['modelo'] ?: '—') ?> · <?= $H($r['segundos']?:'?') ?>s</div>
        <?php if ($est==='ok'): ?>
        <form method="post" class="ev">
          <input type="hidden" name="accion" value="evaluar"><input type="hidden" name="exp_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="cid" value="<?= $H($cid) ?>">
          <div class="two">
            <div><label>¿Publicable?</label><select name="publicable"><option value="">—</option><option value="si" <?= $sel($ev['publicable']??'','si') ?>>Sí</option><option value="no" <?= $sel($ev['publicable']??'','no') ?>>No</option></select></div>
            <div><label>¿Stock/catálogo?</label><select name="stock"><option value="">—</option><option value="si" <?= $sel($ev['stock']??'','si') ?>>Sí</option><option value="no" <?= $sel($ev['stock']??'','no') ?>>No</option></select></div>
          </div>
          <div class="two">
            <div><label>Detiene scroll (1-10)</label><select name="scroll"><option value="0">—</option><?php for($i=10;$i>=1;$i--)echo '<option '.$sel($ev['scroll']??0,$i).'>'.$i.'</option>';?></select></div>
            <div><label>Fuerza de idea</label><select name="idea"><option value="0">—</option><?php for($i=10;$i>=1;$i--)echo '<option '.$sel($ev['idea']??0,$i).'>'.$i.'</option>';?></select></div>
          </div>
          <div class="two">
            <div><label>Especificidad</label><select name="especificidad"><option value="0">—</option><?php for($i=10;$i>=1;$i--)echo '<option '.$sel($ev['especificidad']??0,$i).'>'.$i.'</option>';?></select></div>
            <div><label>Fidelidad al copy</label><select name="fidelidad"><option value="0">—</option><?php for($i=10;$i>=1;$i--)echo '<option '.$sel($ev['fidelidad']??0,$i).'>'.$i.'</option>';?></select></div>
          </div>
          <label>Observaciones</label><textarea name="obs" rows="2"><?= $H($ev['obs']??'') ?></textarea>
          <button type="submit">Guardar evaluación</button>
        </form>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if ($pend): ?><script>
      var pend=<?= json_encode($pend) ?>;
      var t=setInterval(function(){Promise.all(pend.map(function(id){return fetch('?k=crecer&poll='+id,{cache:'no-store'}).then(function(r){return r.json()}).then(function(d){return d.estado}).catch(function(){return'?'})}))
        .then(function(s){if(s.every(function(x){return x==='ok'||x==='error'})){clearInterval(t);location.reload();}});},4000);
    </script><p style="color:#8a8a8a;margin-top:16px">⏳ Esperando <?= count($pend) ?> variante(s)… la página se recarga sola.</p><?php endif; ?>
    <?php endif; ?>
    </body></html><?php
    exit;
}

// ===== DIAGNÓSTICO del Modo ChatGPT en background (pregunta directo a OpenAI) =====
if (isset($_GET['bgstat'])) {
    header('Content-Type: text/plain; charset=utf-8');
    $id = (int)$_GET['bgstat'];
    $e = $id ? lab_exp($pdo, $id)
             : ($pdo->query("SELECT * FROM crecer_lab_experimentos WHERE modelo LIKE 'pending:%' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null);
    if (!$e) { echo "No hay experimento en background pendiente."; exit; }
    echo "exp #{$e['id']} · estado(BD)={$e['estado']} · modelo={$e['modelo']}\n";
    if (strncmp((string)$e['modelo'], 'pending:', 8) === 0) {
        $rid = substr((string)$e['modelo'], 8);
        echo "response id: {$rid}\nConsultando OpenAI…\n\n";
        try {
            $st = openai_responses_estado($rid);
            echo "status OpenAI: {$st['status']}\n";
            echo "imagen lista: " . (strlen($st['b64']) ? 'SÍ (' . strlen($st['b64']) . ' chars b64)' : 'todavía no') . "\n";
            echo "model: {$st['model']}\n";
            echo "revised_prompt: " . (substr((string)$st['revised'], 0, 300) ?: '(vacío)') . "\n";
        } catch (Throwable $ex) { echo "ERROR consultando OpenAI:\n" . $ex->getMessage() . "\n"; }
    } else {
        echo "Ya no está pendiente (estado final=" . $e['estado'] . ").\n";
    }
    exit;
}

// ===== POLL: estado para el frontend (y avanza los Modo ChatGPT en background) =====
if (isset($_GET['poll'])) {
    header('Content-Type: application/json');
    $e = lab_exp($pdo, (int)$_GET['poll']);
    // Modo ChatGPT: si sigue 'queued' con un id de OpenAI pendiente, consúltalo.
    if ($e && $e['estado'] === 'queued' && strncmp((string)$e['modelo'], 'pending:', 8) === 0) {
        $rid = substr((string)$e['modelo'], 8);
        try {
            $st = openai_responses_estado($rid);
            if ($st['status'] === 'completed' && $st['b64'] !== '') {
                $bin = base64_decode($st['b64']);
                $rel = 'pruebas/lab_' . substr(md5((string)microtime(true)), 0, 8) . '.png';
                $abs = rtrim(UPLOADS_PATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                @mkdir(dirname($abs), 0775, true); @file_put_contents($abs, $bin);
                $url = rtrim(UPLOADS_URL, '/') . '/' . $rel;
                $rev = trim((string)$st['revised']);
                // Evidencia cruda del image_generation_call (revised_prompt auténtico + params) por experimento.
                $rawdir = __DIR__ . '/storage/logs/responses'; @mkdir($rawdir, 0775, true);
                @file_put_contents($rawdir . '/exp_' . (int)$e['id'] . '.json',
                    json_encode($st['raw'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $pdo->prepare("UPDATE crecer_lab_experimentos SET estado='ok', imagen=?, bytes=?, modelo=?, segundos=TIMESTAMPDIFF(SECOND, creado, NOW()), prompt=? WHERE id=?")
                    ->execute([$url, strlen($bin), 'responses:' . ($st['model'] ?: ''), ($rev !== '' ? $rev : (string)$e['prompt']), (int)$e['id']]);
            } elseif (in_array($st['status'], ['failed', 'cancelled', 'incomplete'], true)) {
                $pdo->prepare("UPDATE crecer_lab_experimentos SET estado='error', observaciones=CONCAT('[error] Responses ', ?) WHERE id=?")
                    ->execute([$st['status'], (int)$e['id']]);
            }   // in_progress / queued → sigue esperando
        } catch (Throwable $ex) { /* transitorio: se reintenta en el próximo poll */ }
        $e = lab_exp($pdo, (int)$_GET['poll']);
    }
    echo json_encode(['estado' => $e['estado'] ?? '?', 'imagen' => $e['imagen'] ?? '']);
    exit;
}

// ===== DIAGNÓSTICO Responses API (síncrono, muestra el error CRUDO) =====
if (isset($_GET['rtest'])) {
    header('Content-Type: text/plain; charset=utf-8');
    @set_time_limit(0);
    $cfg = resolver_modelo_ia(defined('IMAGE_CREATIVE_MODEL') ? IMAGE_CREATIVE_MODEL : 'openai:creative');
    $mdl = strpos($cfg, ':') !== false ? explode(':', $cfg, 2)[1] : $cfg;
    echo "Modelo resuelto para Modo ChatGPT: {$mdl}\n";
    echo "Llamando a /v1/responses con la herramienta image_generation…\n\n";
    $brief = "Crea una imagen publicitaria profesional de un frasco de miel puertorriqueña sobre una mesa rústica de madera, con luz cálida de mañana. Fotografía de producto de alta calidad.";
    try {
        $t0 = microtime(true);
        //  RUTA 14
        $r  = openai_responses_imagen($brief, ['aspect' => '1:1',
            'cuota' => _lab_cuota($pdo, 'imgtry_resp', 14)]);
        $seg = round(microtime(true) - $t0, 1);
        $rel = 'pruebas/rtest_' . substr(md5((string)microtime(true)), 0, 8) . '.png';
        $abs = rtrim(UPLOADS_PATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        @mkdir(dirname($abs), 0775, true); @file_put_contents($abs, $r['data']);
        echo "✅ OK en {$seg}s\nmodelo: {$r['modelo']}\nbytes: " . strlen($r['data']) . "\n\n";
        echo "revised_prompt (lo que el modelo escribió):\n" . ($r['revised'] ?: '(la API no lo devolvió)') . "\n\n";
        echo "imagen: " . rtrim(UPLOADS_URL, '/') . '/' . $rel . "\n";
    } catch (Throwable $e) {
        echo "❌ ERROR:\n" . $e->getMessage() . "\n";
    }
    exit;
}

$modo    = $_POST['modo']   ?? '';
$accion  = $_POST['accion'] ?? '';
$prompt  = trim((string)($_POST['prompt'] ?? ''));
$copy_in = trim((string)($_POST['copy'] ?? ''));
$marca_id = (int)($_POST['marca'] ?? 0);
$aspect  = (string)($_POST['aspect'] ?? '1:1');
$hipotesis = trim((string)($_POST['hipotesis'] ?? ''));

$escena = ''; $ag_info = ''; $ag_err = '';
$img_url = ''; $img_info = ''; $img_err = '';
$exp = null; $flash = ''; $save_warn = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    @set_time_limit(0);

    // ---- MODO 1 — el AGENTE V3: copy → escena (INTACTO) ----
    if ($modo === 'escena' && $copy_in !== '' && $marca_id) {
        try {
            $m = leer_marca($pdo, $marca_id);
            $b = image_messenger_build($pdo, $marca_id, $m, $copy_in);
            $cfg = defined('IMAGE_CREATIVE_MODEL') ? IMAGE_CREATIVE_MODEL : 'openai:creative';
            $d = director_creativo_llm($pdo, $marca_id, $b['sistema'], $b['mensaje'], $cfg, ['strict'=>true]);
            $escena = trim((string)($d['texto'] ?? ''));
            $ag_info = 'director: ' . ($d['modelo'] ?? '?') . ' · ' . round(((int)($d['dur_ms'] ?? 0))/1000, 1) . 's';
            $prompt = $escena;   // pre-carga la escena en el generador de abajo
        } catch (Throwable $e) { $ag_err = $e->getMessage(); }
    }

    // ---- MODO 2 — PROMPT → gpt-image-1 (mismo prompt, misma llamada; ahora ASÍNCRONO) ----
    if ($modo === 'imagen' && $prompt !== '') {
        $s_copy   = trim((string)($_POST['copy_txt'] ?? ''));
        $s_escena = trim((string)($_POST['escena_h'] ?? ''));
        $s_marca  = (int)($_POST['marca'] ?? 0);
        $neg = '';
        if ($s_marca) { $q = $pdo->prepare("SELECT nombre_negocio FROM crecer_marca WHERE id=?"); $q->execute([$s_marca]); $neg = (string)$q->fetchColumn(); }
        try {
            // Encola (estado 'queued') y dispara el worker → inmune al 504 de nginx.
            $ins = $pdo->prepare("INSERT INTO crecer_lab_experimentos
                (marca_id,negocio,hipotesis,copy_txt,escena,prompt,estado) VALUES (?,?,?,?,?,?, 'queued')");
            $ins->execute([$s_marca ?: null, $neg ?: null, $hipotesis ?: null, $s_copy ?: null, $s_escena ?: null, $prompt]);
            $qid = (int)$pdo->lastInsertId();
            lab_fire($qid, $aspect, 'img', trim((string)($_POST['renderer'] ?? '')));
            $exp = lab_exp($pdo, $qid);   // 'queued' → el panel muestra el spinner y hace polling
        } catch (Throwable $e) {
            // Sin tabla (migración no corrida) → generación síncrona (viejo camino).
            try {
                $t0 = microtime(true);
                $_rend = trim((string)($_POST['renderer'] ?? ''));
                //  RUTA 15
        $r  = openai_imagen($prompt, ['aspect' => $aspect, 'cuota' => _lab_cuota($pdo, 'imgtry_openai', 15)]
            + ($_rend !== '' ? ['modelo_openai' => $_rend] : []));
                $seg = round(microtime(true) - $t0, 1);
                $rel = 'pruebas/lab_' . substr(md5((string)microtime(true)), 0, 8) . '.png';
                $abs = rtrim(UPLOADS_PATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                @mkdir(dirname($abs), 0775, true);
                @file_put_contents($abs, $r['data']);
                $img_url  = rtrim(UPLOADS_URL, '/') . '/' . $rel;
                $img_info = 'modelo ' . ($r['modelo'] ?? '?') . ' · ' . $seg . 's · ' . strlen($r['data']) . ' bytes';
                $save_warn = 'Generado en modo directo (sin historial). Corre la migración para el historial + async.';
            } catch (Throwable $e2) { $img_err = $e2->getMessage(); }
        }
    }

    // ---- MODO 3 — MODO ChatGPT: el modelo se dirige solo (Responses API) ----
    if ($modo === 'chatgpt' && $marca_id && $copy_in !== '') {
        try {
            $m = leer_marca($pdo, $marca_id);
            $nombre = trim((string)($m['nombre_negocio'] ?? ''));
            $brief = lab_brief_natural($m, $copy_in);   // brief natural (como se lo pedirías a ChatGPT)
            // BACKGROUND: OpenAI corre el trabajo; nos devuelve un id al instante (sin 504).
            //  RUTA 16
            $bg = openai_responses_crear_bg($brief, ['aspect' => $aspect,
                'cuota' => _lab_cuota($pdo, 'imgtry_bg', 16)]);
            $ins = $pdo->prepare("INSERT INTO crecer_lab_experimentos
                (marca_id,negocio,hipotesis,copy_txt,escena,prompt,modelo,estado) VALUES (?,?,?,?,?,?,?, 'queued')");
            $ins->execute([$marca_id, $nombre ?: null, $hipotesis ?: null, $copy_in, $brief, $brief, 'pending:' . $bg['id']]);
            $exp = lab_exp($pdo, (int)$pdo->lastInsertId());   // el poll del frontend lo completa
        } catch (Throwable $e) { $img_err = $e->getMessage(); }
    }

    // ---- COMPARADOR — crea 3 variantes con el MISMO snapshot del brief ----
    if ($modo === 'comparar' && $marca_id && $copy_in !== '') {
        try {
            $m = leer_marca($pdo, $marca_id);
            $nombre = trim((string)($m['nombre_negocio'] ?? ''));
            $brief  = lab_brief_natural($m, $copy_in);
            $cid    = 'cmp_' . substr(md5(uniqid('', true)), 0, 12);
            $cfg    = defined('IMAGE_CREATIVE_MODEL') ? IMAGE_CREATIVE_MODEL : 'openai:creative';
            $renderer = trim((string)($_POST['renderer'] ?? ''));   // renderizador de las variantes DIRECTAS (director/directo)

            // VARIANTE · Director de dos pasos (v2 image_messenger). Director INLINE (~10s), imagen async.
            $bA = image_messenger_build($pdo, $marca_id, $m, $copy_in);
            $escenaA = ''; $metaA = ['modo'=>'director', 'endpoint_texto'=>'/v1/chat/completions',
                'endpoint_imagen'=>'/v1/images/generations', 'modelo_solicitado'=>resolver_modelo_ia($cfg),
                'renderizador'=>($renderer ?: 'gpt-image-1'), 'quality'=>'high', 'size'=>$aspect, 'background'=>'opaque',
                'action'=>'generate', 'system'=>$bA['sistema'], 'user'=>$bA['mensaje']];
            try { $dA = director_creativo_llm($pdo, $marca_id, $bA['sistema'], $bA['mensaje'], $cfg, ['strict'=>true]);
                  $escenaA = trim((string)($dA['texto'] ?? '')); $metaA['director_modelo'] = $dA['modelo'] ?? ''; }
            catch (Throwable $ex) { $metaA['director_error'] = $ex->getMessage(); }

            // VARIANTE · Prompt directo (el MISMO brief natural va directo a gpt-image-1, sin director).
            $metaB = ['modo'=>'directo', 'endpoint_imagen'=>'/v1/images/generations', 'renderizador'=>($renderer ?: 'gpt-image-1'),
                'quality'=>'high', 'size'=>$aspect, 'background'=>'opaque', 'action'=>'generate', 'prompt'=>$brief];

            // VARIANTE · Responses auto-dirigido (background:true).
            $metaC = ['modo'=>'responses', 'endpoint'=>'/v1/responses', 'tool'=>'image_generation',
                'background_api'=>true, 'quality'=>'high', 'size'=>$aspect, 'background'=>'opaque', 'brief'=>$brief];
            $okC = true; $modeloC = null;
            //  RUTA 17
            try { $bg = openai_responses_crear_bg($brief, ['aspect'=>$aspect,
                      'cuota' => _lab_cuota($pdo, 'imgtry_bg_ciego', 17)]);
                  $modeloC = 'pending:' . $bg['id']; $metaC['response_id'] = $bg['id']; $metaC['model_orquestador'] = $bg['modelo']; }
            catch (Throwable $ex) { $okC = false; $metaC['error'] = $ex->getMessage(); }

            $variantes = [
                ['escena'=>$escenaA, 'prompt'=>$escenaA, 'modelo'=>null,     'meta'=>$metaA, 'fire'=>'img',  'ok'=>($escenaA !== '')],
                ['escena'=>'',       'prompt'=>$brief,   'modelo'=>null,     'meta'=>$metaB, 'fire'=>'img',  'ok'=>true],
                ['escena'=>$brief,   'prompt'=>$brief,   'modelo'=>$modeloC, 'meta'=>$metaC, 'fire'=>'none', 'ok'=>$okC],
            ];
            // Letras A/B/C BARAJADAS → ciego (la etiqueta no revela el modo).
            $letras = ['A','B','C']; shuffle($letras);
            $ins = $pdo->prepare("INSERT INTO crecer_lab_experimentos
                (comparison_id,variante,marca_id,negocio,hipotesis,copy_txt,escena,prompt,modelo,estado,meta_json)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $disparar = [];
            foreach ($variantes as $i => $v) {
                $estado = $v['ok'] ? 'queued' : 'error';
                $ins->execute([$cid, $letras[$i], $marca_id, $nombre ?: null, $hipotesis ?: null, $copy_in,
                    $v['escena'] ?: null, $v['prompt'], $v['modelo'], $estado, json_encode($v['meta'], JSON_UNESCAPED_UNICODE)]);
                $eid = (int)$pdo->lastInsertId();
                if (!$v['ok']) {
                    $err = $v['meta']['director_error'] ?? $v['meta']['error'] ?? 'no arrancó';
                    $pdo->prepare("UPDATE crecer_lab_experimentos SET observaciones=? WHERE id=?")->execute(['[error] ' . $err, $eid]);
                } elseif ($v['fire'] === 'img') { $disparar[] = $eid; }
            }
            foreach ($disparar as $eid) lab_fire($eid, $aspect, 'img', $renderer);   // A y B por el worker de imagen (renderizador elegido)
            header('Location: ?k=crecer&cmp=' . $cid); exit;
        } catch (Throwable $e) { $img_err = 'Comparador: ' . $e->getMessage(); }
    }

    // ---- Evaluación de una variante del comparador ----
    if ($accion === 'evaluar') {
        $id = (int)($_POST['exp_id'] ?? 0);
        $ev = [
            'publicable'    => $_POST['publicable'] ?? '',
            'scroll'        => (int)($_POST['scroll'] ?? 0),
            'idea'          => (int)($_POST['idea'] ?? 0),
            'especificidad' => (int)($_POST['especificidad'] ?? 0),
            'stock'         => $_POST['stock'] ?? '',
            'fidelidad'     => (int)($_POST['fidelidad'] ?? 0),
            'obs'           => trim((string)($_POST['obs'] ?? '')),
        ];
        try { $pdo->prepare("UPDATE crecer_lab_experimentos SET eval_json=? WHERE id=?")->execute([json_encode($ev, JSON_UNESCAPED_UNICODE), $id]); } catch (Throwable $e) {}
        header('Location: ?k=crecer&cmp=' . urlencode((string)($_POST['cid'] ?? ''))); exit;
    }

    // ---- (AÑADIDO) calificar / observaciones ----
    if ($accion === 'calificar') {
        $id = (int)($_POST['exp_id'] ?? 0);
        $pu = ($_POST['puntuacion'] ?? '') === '' ? null : max(1, min(10, (int)$_POST['puntuacion']));
        $ob = trim((string)($_POST['observaciones'] ?? ''));
        try { $pdo->prepare("UPDATE crecer_lab_experimentos SET puntuacion=?, observaciones=? WHERE id=?")->execute([$pu, $ob ?: null, $id]);
              $flash = 'Calificación guardada.'; } catch (Throwable $e) { $flash = 'Error: ' . $e->getMessage(); }
        $exp = lab_exp($pdo, $id);
    }

    // ---- (AÑADIDO) analizar imagen: crítica publicitaria por VISIÓN (solo la imagen) ----
    if ($accion === 'analizar') {
        $id = (int)($_POST['exp_id'] ?? 0);
        $e0 = lab_exp($pdo, $id);
        if ($e0 && !empty($e0['imagen'])) {
            try {
                $abs = lab_abs((string)$e0['imagen']);
                if (!is_file($abs)) throw new RuntimeException('Imagen no encontrada en disco.');
                $bin = (string)file_get_contents($abs);
                $mime = (function_exists('mime_content_type') ? mime_content_type($abs) : '') ?: 'image/png';
                $sys = "Eres un Director Creativo de publicidad de clase mundial evaluando una pieza para redes (Facebook/Instagram) "
                     . "de un micronegocio. NO describas la imagen literalmente. Haz una CRÍTICA PUBLICITARIA honesta y crítica, sin "
                     . "floritura. Responde EXACTAMENTE en este formato y nada más:\n"
                     . "Calificación general: X/10\n\nFortalezas\n- ...\n\nDebilidades\n- ...\n\nEmoción\n...\n\n"
                     . "Mensaje percibido\n...\n\n¿Detiene el scroll?\nSí / No — por qué en una línea\n\n"
                     . "¿Se siente como una campaña profesional?\nSí / Parcialmente / No\n\nMejoras sugeridas\n- ...";
                $cfg = resolver_modelo_ia(defined('IMAGE_CREATIVE_MODEL') ? IMAGE_CREATIVE_MODEL : 'openai:creative');
                $mdl = strpos($cfg, ':') !== false ? explode(':', $cfg, 2)[1] : $cfg;
                $out = openai_chat($sys, 'Evalúa esta pieza publicitaria como Director Creativo.', $mdl, [
                    'imagenes' => [['data' => base64_encode($bin), 'mime' => $mime]],
                    'max_tokens' => 900, 'max_reintentos' => 0,
                ]);
                $txt = trim((string)($out['texto'] ?? ''));
                $pdo->prepare("UPDATE crecer_lab_experimentos SET analisis=? WHERE id=?")->execute([$txt, $id]);
                $flash = 'Análisis listo.';
            } catch (Throwable $e) { $flash = 'Error al analizar: ' . $e->getMessage(); }
        }
        $exp = lab_exp($pdo, $id);
    }
}

// Ver un experimento del historial (?exp=ID)
if (!$exp && isset($_GET['exp'])) $exp = lab_exp($pdo, (int)$_GET['exp']);

// ---- Búsqueda del historial (GET) ----
$fq     = trim((string)($_GET['q'] ?? ''));
$fmarca = (int)($_GET['fmarca'] ?? 0);
$fpunt  = (int)($_GET['fpunt'] ?? 0);
$fdesde = trim((string)($_GET['fdesde'] ?? ''));
$hist = []; $hist_err = '';
try {
    $w = []; $a = [];
    if ($fq !== '')   { $w[] = '(prompt LIKE ? OR copy_txt LIKE ? OR negocio LIKE ? OR observaciones LIKE ?)'; $like = '%' . $fq . '%'; array_push($a, $like, $like, $like, $like); }
    if ($fmarca)      { $w[] = 'marca_id = ?'; $a[] = $fmarca; }
    if ($fpunt)       { $w[] = 'puntuacion >= ?'; $a[] = $fpunt; }
    if ($fdesde !== ''){ $w[] = 'creado >= ?'; $a[] = $fdesde . ' 00:00:00'; }
    $sql = "SELECT id,creado,negocio,imagen,prompt,puntuacion,estado,observaciones FROM crecer_lab_experimentos"
         . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY id DESC LIMIT 120";
    $st = $pdo->prepare($sql); $st->execute($a); $hist = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $hist_err = $e->getMessage(); }

$marcas = $pdo->query("SELECT id, nombre_negocio FROM crecer_marca ORDER BY id DESC LIMIT 60")->fetchAll(PDO::FETCH_ASSOC);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Laboratorio de imágenes · Crecer</title>
<style>
  *{box-sizing:border-box}
  body{font-family:system-ui,-apple-system,sans-serif;max-width:820px;margin:0 auto;padding:22px 18px 80px;background:#faf9f8;color:#231F20}
  h1{font-size:23px;margin:0 0 2px}
  h2{font-size:16px;margin:26px 0 10px;color:#EF4375}
  p.sub{color:#6E6A67;font-size:14px;margin:0 0 16px}
  .box{background:#fff;border:1px solid #E9E7E4;border-radius:16px;padding:18px;margin-bottom:16px}
  textarea{width:100%;min-height:120px;font:15px/1.55 system-ui;padding:12px;border:1.5px solid #E9E7E4;border-radius:12px;background:#fff}
  textarea.sm{min-height:64px}
  textarea:focus,select:focus,input:focus{outline:0;border-color:#EF4375}
  select,input[type=text],input[type=date]{padding:11px;border-radius:10px;border:1.5px solid #E9E7E4;background:#fff;font:15px system-ui;width:100%;margin-bottom:10px}
  label.f{display:block;font-weight:700;font-size:12.5px;color:#6E6A67;margin:2px 0 4px}
  .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px}
  button{background:linear-gradient(135deg,#FF6B3D,#EF4375);color:#fff;border:0;padding:13px 24px;border-radius:13px;font-weight:800;font-size:15px;cursor:pointer}
  button.gho{background:#fff;border:1.5px solid #E9E7E4;color:#333}
  button.teal{background:#00A49F}
  img.res{width:100%;border-radius:14px;margin-top:6px;border:1px solid #eee;display:block}
  .err{background:#fdeaea;color:#b42318;padding:12px 14px;border-radius:11px;margin-top:12px;font-size:13.5px;white-space:pre-wrap;line-height:1.5}
  .warn{background:#fff4d6;color:#8a5a00;padding:10px 13px;border-radius:10px;margin-top:10px;font-size:13px}
  .ok{background:#e6f6ee;color:#0d7a44;padding:10px 13px;border-radius:10px;margin-bottom:12px;font-size:13.5px;font-weight:600}
  .info{color:#00827e;font-weight:700;font-size:12.5px;margin:8px 0}
  .escena{background:#f4f7ff;border:1px solid #dbe4ff;border-radius:12px;padding:13px 15px;margin-top:12px;font-size:14px;line-height:1.55;white-space:pre-wrap}
  .load{display:none;color:#6E6A67;margin-top:12px;font-weight:600}
  small{color:#888}
  details{margin-top:10px}details summary{cursor:pointer;font-weight:700;font-size:13px;color:#EF4375}
  details .escena{margin-top:8px}
  .stars{display:inline-flex;flex-direction:row-reverse;justify-content:flex-end}
  .stars input{display:none}
  .stars label{font-size:27px;color:#dcdcdc;cursor:pointer;padding:0 1px;line-height:1;transition:color .08s}
  .stars label:hover,.stars label:hover ~ label,.stars input:checked ~ label{color:#FFB400}
  .crit{background:#231F20;color:#f4f2f0;border-radius:12px;padding:15px 17px;margin-top:12px;font-size:13.5px;line-height:1.6;white-space:pre-wrap}
  .hist{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px}
  .hcard{background:#fff;border:1px solid #E9E7E4;border-radius:12px;overflow:hidden;text-decoration:none;color:inherit;display:block}
  .hcard img{width:100%;height:110px;object-fit:cover;display:block;background:#eee}
  .hcard .m{padding:8px 10px}
  .hcard .p{font-size:11.5px;color:#4A434F;line-height:1.35;max-height:48px;overflow:hidden}
  .hcard .t{font-size:11px;color:#999;margin-top:4px}
  .badge{display:inline-block;background:#FFF3D0;color:#8a5a00;font-weight:800;font-size:11px;padding:2px 7px;border-radius:99px}
  .filtros{display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:8px;align-items:end}
  @media(max-width:640px){.filtros{grid-template-columns:1fr 1fr}}
</style></head>
<body>
<h1>🧪 Laboratorio de imágenes</h1>
<p class="sub">Banco de investigación. Prueba qué escena escribe el agente, genera, califica y analiza — para construir evidencia de qué produce mejores campañas.</p>
<?php if ($flash): ?><div class="ok"><?= $h($flash) ?></div><?php endif; ?>

<h2>1 · El agente V3 — copy → escena</h2>
<div class="box">
  <form method="post" onsubmit="document.getElementById('l1').style.display='block'">
    <input type="hidden" name="modo" value="escena">
    <label class="f">Negocio</label>
    <select name="marca" required>
      <option value="">— Elige el negocio —</option>
      <?php foreach ($marcas as $mm): ?>
        <option value="<?= (int)$mm['id'] ?>" <?= $marca_id===(int)$mm['id']?'selected':'' ?>>#<?= (int)$mm['id'] ?> · <?= $h($mm['nombre_negocio']) ?></option>
      <?php endforeach; ?>
    </select>
    <label class="f">Copy del post</label>
    <textarea name="copy" placeholder="Pega el copy del post…"><?= $h($copy_in) ?></textarea>
    <div class="row"><button type="submit">Ver la escena que escribe el agente</button> <small>~10s</small></div>
    <div class="load" id="l1">🧠 El agente está pensando…</div>
  </form>
  <?php if ($ag_err): ?><div class="err">❌ <?= $h($ag_err) ?></div><?php endif; ?>
  <?php if ($escena): ?><div class="info"><?= $h($ag_info) ?></div><div class="escena"><?= $h($escena) ?></div>
    <p class="sub" style="margin:10px 0 0">↓ Ya cargué esa escena abajo — dale "Generar imagen" para verla.</p><?php endif; ?>
</div>

<h2>2 · Prompt → gpt-image-1 (directo)</h2>
<div class="box">
  <form method="post" onsubmit="document.getElementById('l2').style.display='block'">
    <input type="hidden" name="modo" value="imagen">
    <input type="hidden" name="marca" value="<?= (int)$marca_id ?>">
    <input type="hidden" name="copy_txt" value="<?= $h($copy_in) ?>">
    <input type="hidden" name="escena_h" value="<?= $h($escena) ?>">
    <label class="f">Hipótesis <small>(opcional — qué quieres comprobar)</small></label>
    <input type="text" name="hipotesis" value="<?= $h($hipotesis) ?>" placeholder="Ej.: comprobar si la frase 'world-class advertising agency' mejora el resultado">
    <label class="f">Prompt</label>
    <textarea name="prompt" placeholder="La escena de arriba, o cualquier prompt (mejor en inglés)…"><?= $h($prompt) ?></textarea>
    <div class="row">
      <select name="aspect" style="width:auto;margin:0">
        <option value="1:1"  <?= $aspect==='1:1'?'selected':'' ?>>Cuadrado 1:1</option>
        <option value="4:5"  <?= $aspect==='4:5'?'selected':'' ?>>Vertical 4:5</option>
        <option value="16:9" <?= $aspect==='16:9'?'selected':'' ?>>Horizontal 16:9</option>
      </select>
      <select name="renderer" style="width:auto;margin:0">
        <option value="">gpt-image-1 (actual)</option>
        <option value="gpt-image-2">gpt-image-2 (nuevo · el de Responses)</option>
      </select>
      <button type="submit">Generar imagen</button> <small>~48s</small>
    </div>
    <div class="load" id="l2">🎨 Generando… (no cierres, ~48s)</div>
  </form>
  <?php if ($img_err): ?><div class="err">❌ <?= $h($img_err) ?></div><?php endif; ?>
  <?php if ($save_warn): ?><div class="warn">⚠️ <?= $h($save_warn) ?></div><?php endif; ?>
  <?php // Fallback: si generó pero no se pudo guardar (sin migración), muestra la imagen igual.
  if (!$exp && $img_url): ?><div class="info"><?= $h($img_info) ?></div><img class="res" src="<?= $h($img_url) ?>" alt=""><?php endif; ?>
</div>

<h2>3 · Modo ChatGPT — el modelo se dirige solo</h2>
<div class="box">
  <p class="sub" style="margin:0 0 12px">Recibe el negocio + copy, <b>escribe su propio prompt y genera</b> en una sola llamada (Responses API — el mecanismo de ChatGPT). Úsalo con el <b>mismo negocio y copy</b> de arriba para comparar contra nuestro director.</p>
  <form method="post" onsubmit="document.getElementById('l4').style.display='block'">
    <input type="hidden" name="modo" value="chatgpt">
    <label class="f">Negocio</label>
    <select name="marca" required>
      <option value="">— Elige el negocio —</option>
      <?php foreach ($marcas as $mm): ?><option value="<?= (int)$mm['id'] ?>" <?= $marca_id===(int)$mm['id']?'selected':'' ?>>#<?= (int)$mm['id'] ?> · <?= $h($mm['nombre_negocio']) ?></option><?php endforeach; ?>
    </select>
    <label class="f">Copy del post</label>
    <textarea name="copy" placeholder="Pega el copy del post…"><?= $h($copy_in) ?></textarea>
    <label class="f">Hipótesis <small>(opcional)</small></label>
    <input type="text" name="hipotesis" placeholder="Qué quieres comprobar…">
    <div class="row">
      <select name="aspect" style="width:auto;margin:0">
        <option value="1:1">Cuadrado 1:1</option><option value="4:5">Vertical 4:5</option><option value="16:9">Horizontal 16:9</option>
      </select>
      <button type="submit" class="teal">🤖 Generar en Modo ChatGPT</button> <small>~40-70s</small>
    </div>
    <div class="load" id="l4">🤖 ChatGPT se está dirigiendo solo…</div>
  </form>
</div>

<h2>4 · Comparar los 3 modos (automático, ciego)</h2>
<div class="box">
  <p class="sub" style="margin:0 0 12px">Un solo botón: genera las 3 variantes con el <b>mismo brief</b> (Director · Prompt directo · Responses), las muestra lado a lado como <b>A/B/C barajadas</b> para calificar sin sesgo, y arma el paquete técnico completo. Cero copiar/pegar.</p>
  <form method="post" onsubmit="document.getElementById('l5').style.display='block'">
    <input type="hidden" name="modo" value="comparar">
    <label class="f">Negocio</label>
    <select name="marca" required>
      <option value="">— Elige el negocio —</option>
      <?php foreach ($marcas as $mm): ?><option value="<?= (int)$mm['id'] ?>" <?= $marca_id===(int)$mm['id']?'selected':'' ?>>#<?= (int)$mm['id'] ?> · <?= $h($mm['nombre_negocio']) ?></option><?php endforeach; ?>
    </select>
    <label class="f">Copy del post</label>
    <textarea name="copy" placeholder="Pega el copy del post…"><?= $h($copy_in) ?></textarea>
    <label class="f">Hipótesis <small>(opcional)</small></label>
    <input type="text" name="hipotesis" placeholder="Qué quieres comprobar…">
    <div class="row">
      <select name="aspect" style="width:auto;margin:0">
        <option value="1:1">Cuadrado 1:1</option><option value="4:5">Vertical 4:5</option><option value="16:9">Horizontal 16:9</option>
      </select>
      <select name="renderer" style="width:auto;margin:0" title="Renderizador de las variantes directas (Director/Directo). Responses siempre usa su interno.">
        <option value="">Directas en gpt-image-1</option>
        <option value="gpt-image-2">Directas en gpt-image-2 (comparación justa)</option>
      </select>
      <button type="submit">🆚 Comparar los 3 modos</button> <small>~15s en crear, luego se generan solas</small>
    </div>
    <div class="load" id="l5">🧠 Creando las 3 variantes (director + directo + Responses)…</div>
  </form>
</div>

<?php if ($exp): $est = (string)$exp['estado']; ?>
<h2>Experimento #<?= (int)$exp['id'] ?><?= $est==='queued' ? ' — generando…' : '' ?></h2>
<div class="box">
  <div class="info"><?= $h(($exp['negocio'] ? $exp['negocio'] . ' · ' : '') . $exp['creado'] . ' · ' . ($est==='ok' ? $exp['modelo'] . ' · ' . $exp['segundos'] . 's · ' . number_format((int)$exp['bytes']) . ' bytes' : $est)) ?></div>

  <?php if ($est === 'queued'): ?>
    <div class="load" style="display:block">🎨 Generando la imagen… <small>(sigue aunque cierres esta pestaña; 40–70s)</small></div>
    <script>
      (function(){var id=<?= (int)$exp['id'] ?>;var t=setInterval(function(){
        fetch('?k=crecer&poll='+id,{cache:'no-store'}).then(function(r){return r.json()}).then(function(d){
          if(d.estado==='ok'||d.estado==='error'){clearInterval(t);location.href='?k=crecer&exp='+id;}
        }).catch(function(){});
      },4000);})();
    </script>
  <?php elseif ($est === 'error'): ?>
    <div class="err">❌ <?= $h($exp['observaciones'] ?: 'La generación falló.') ?></div>
  <?php elseif (!empty($exp['imagen'])): ?>
    <img class="res" src="<?= $h($exp['imagen']) ?>" alt="">
  <?php endif; ?>

  <?php if (!empty($exp['hipotesis'])): ?><p style="margin:12px 0 0;font-size:13.5px"><b>Hipótesis:</b> <?= $h($exp['hipotesis']) ?></p><?php endif; ?>
  <?php if (!empty($exp['escena'])): ?><details><summary>Ver escena del agente</summary><div class="escena"><?= $h($exp['escena']) ?></div></details><?php endif; ?>
  <?php if (!empty($exp['prompt'])): ?><details><summary>Ver prompt enviado a gpt-image-1</summary><div class="escena"><?= $h($exp['prompt']) ?></div></details><?php endif; ?>

  <?php if ($est === 'ok'): ?>
  <!-- Calificación 1–10 + observaciones -->
  <form method="post" style="margin-top:16px">
    <input type="hidden" name="accion" value="calificar">
    <input type="hidden" name="exp_id" value="<?= (int)$exp['id'] ?>">
    <label class="f">Calificación (1–10)</label>
    <div class="stars">
      <?php for ($i=10;$i>=1;$i--): ?>
        <input type="radio" name="puntuacion" id="st<?= $i ?>" value="<?= $i ?>" <?= ((int)$exp['puntuacion']===$i)?'checked':'' ?>>
        <label for="st<?= $i ?>" title="<?= $i ?>/10">★</label>
      <?php endfor; ?>
      <span style="margin-left:10px;font-weight:800;color:#8a5a00"><?= $exp['puntuacion'] ? (int)$exp['puntuacion'].'/10' : '' ?></span>
    </div>
    <label class="f" style="margin-top:12px">Observaciones</label>
    <textarea class="sm" name="observaciones" placeholder="demasiado stock · excelente composición · mucho texto · no comunica el beneficio · parece plantilla…"><?= $h($exp['observaciones']) ?></textarea>
    <div class="row"><button type="submit" class="gho">Guardar calificación</button></div>
  </form>

  <!-- Analizar imagen (crítica publicitaria por visión) -->
  <form method="post" style="margin-top:8px" onsubmit="document.getElementById('l3').style.display='block'">
    <input type="hidden" name="accion" value="analizar">
    <input type="hidden" name="exp_id" value="<?= (int)$exp['id'] ?>">
    <button type="submit" class="teal">🔍 Analizar imagen (crítica del Director)</button> <small>~10s</small>
    <div class="load" id="l3">🧠 Evaluando la pieza…</div>
  </form>
  <?php if (!empty($exp['analisis'])): ?><div class="crit"><?= $h($exp['analisis']) ?></div><?php endif; ?>
  <?php endif; /* est ok */ ?>
</div>
<?php endif; ?>

<h2>Historial de experimentos</h2>
<div class="box">
  <form method="get" class="filtros">
    <input type="hidden" name="k" value="crecer">
    <div><label class="f">Buscar (prompt / copy / negocio / obs.)</label><input type="text" name="q" value="<?= $h($fq) ?>" style="margin:0"></div>
    <div><label class="f">Negocio</label><select name="fmarca" style="margin:0"><option value="0">Todos</option><?php foreach ($marcas as $mm): ?><option value="<?= (int)$mm['id'] ?>" <?= $fmarca===(int)$mm['id']?'selected':'' ?>><?= $h($mm['nombre_negocio']) ?></option><?php endforeach; ?></select></div>
    <div><label class="f">Puntuación ≥</label><select name="fpunt" style="margin:0"><option value="0">Cualquiera</option><?php for($i=10;$i>=1;$i--): ?><option value="<?= $i ?>" <?= $fpunt===$i?'selected':'' ?>><?= $i ?></option><?php endfor; ?></select></div>
    <div><label class="f">Desde</label><input type="date" name="fdesde" value="<?= $h($fdesde) ?>" style="margin:0"></div>
    <div style="grid-column:1/-1"><button type="submit" class="gho">Filtrar</button> <a href="?k=crecer" style="margin-left:8px;color:#888">limpiar</a></div>
  </form>
  <?php if ($hist_err): ?><div class="warn">No pude leer el historial (¿corriste <code>migrations/2026-07-25_lab_experimentos.sql</code>?): <?= $h($hist_err) ?></div><?php endif; ?>
  <div class="hist" style="margin-top:14px">
    <?php foreach ($hist as $x): ?>
      <a class="hcard" href="?k=crecer&exp=<?= (int)$x['id'] ?>">
        <?php if (!empty($x['imagen'])): ?><img src="<?= $h($x['imagen']) ?>" alt=""><?php endif; ?>
        <div class="m">
          <?php if ($x['puntuacion']): ?><span class="badge">★ <?= (int)$x['puntuacion'] ?>/10</span> <?php endif; ?>
          <?php if ($x['estado']!=='ok'): ?><span class="badge" style="background:#fdeaea;color:#b42318"><?= $h($x['estado']) ?></span><?php endif; ?>
          <div class="p"><?= $h(mb_strimwidth((string)($x['negocio'] ?: $x['prompt']), 0, 90, '…')) ?></div>
          <div class="t">#<?= (int)$x['id'] ?> · <?= $h(substr((string)$x['creado'],0,16)) ?></div>
        </div>
      </a>
    <?php endforeach; ?>
    <?php if (!$hist && !$hist_err): ?><p style="color:#888">Aún no hay experimentos. Genera el primero arriba.</p><?php endif; ?>
  </div>
</div>
</body></html>
