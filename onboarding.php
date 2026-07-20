<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Onboarding "wow" por VOZ + FOTO
//  onboarding.php
//
//  El dueño graba 40-60s hablando de su negocio + sube 1 foto.
//  La IA: extrae su perfil (voz/productos/público) → crea la marca →
//  genera su 1 POST DE MUESTRA (caption en su voz + imagen con su foto).
//  El logo y el resto quedan tras el paywall (freemium 1-post).
// ============================================================
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/agentes.php';
require __DIR__ . '/includes/suscripcion.php';
require __DIR__ . '/includes/voice_dna.php';   // Business Voice DNA + Director Editorial
require __DIR__ . '/includes/genoma.php';       // C2 · Business Genome Engine (solo actúa con el flag ON)
requiere_login($pdo);                 // bounce si la sesión apunta a un usuario fantasma
$usuario = usuario_actual($pdo);
if (!$usuario) { logout_usuario(); header('Location: /crecer/login.php?expirado=1'); exit; }  // cinturón + tirantes
$USUARIO_ID = (int)$usuario['id'];

// Si ya tiene una marca, el onboarding ya pasó → al panel (salvo ?otra=1).
$ya = marca_del_usuario($pdo, $USUARIO_ID);
if ($ya && empty($_GET['otra'])) { header('Location: /crecer/panel/index.php?marca=' . (int)$ya['id']); exit; }

// ── POST (AJAX, multipart): voz + foto + nombre → marca + post muestra ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    @set_time_limit(0);
    $nombre = trim($_POST['nombre'] ?? '');
    if ($nombre === '') { echo json_encode(['ok'=>false,'err'=>'Ponle nombre a tu negocio.']); exit; }

    // Idempotencia PERSISTENTE (BD): un pipeline por usuario aunque haya doble clic /
    // refresh / dos pestañas / otra sesión / otro dispositivo / requests concurrentes.
    // El acquire es atómico (PRIMARY KEY usuario_id): dos requests simultáneos ⇒ uno solo entra.
    if (!empty($_GET['otra'])) onboarding_lock_reset($pdo, $USUARIO_ID);   // crear OTRO negocio: lock nuevo
    $lock = onboarding_lock_acquire($pdo, $USUARIO_ID);
    if (!$lock['acquired']) {
        if (($lock['estado'] ?? '') === 'completed' && !empty($lock['marca_id'])) {
            echo json_encode(['ok'=>true, 'marca_id'=>(int)$lock['marca_id']]); exit;   // ya se creó: reutiliza el resultado
        }
        // En curso ⇒ estado RECUPERABLE (no un error confuso): el front reintenta / hace poll.
        http_response_code(202);
        echo json_encode(['ok'=>false, 'estado'=>'procesando', 'reintentar'=>true,
            'err'=>'Ya estamos montando tu corillo — dale un segundito y se abre solo.']); exit;
    }
    $LOCK_TOKEN = $lock['token'];

    $municipio   = ($_POST['municipio_id'] ?? '') !== '' ? (int)$_POST['municipio_id'] : null;
    // VOZ elegida por el dueño (paso 4 del wizard) → los 4 ejes de tono. Si no eligió, $tono=null
    // y se usa el tono que la IA sugiere por tipo de negocio. La elección del dueño MANDA.
    $tono        = preset_voz_a_tono(trim($_POST['voz_preset'] ?? ''));
    $texto_in    = trim($_POST['texto'] ?? '');
    $tiene_audio = !empty($_FILES['audio']['tmp_name']) && $_FILES['audio']['error'] === UPLOAD_ERR_OK;
    if (!$tiene_audio && $texto_in === '') {
        onboarding_lock_fail($pdo, $USUARIO_ID, $LOCK_TOKEN);
        echo json_encode(['ok'=>false,'err'=>'Grábate o escribe de tu negocio.']); exit;
    }

    // 1) Voz o texto → perfil
    try {
        if ($tiene_audio) {
            $audio_b64  = base64_encode((string)file_get_contents($_FILES['audio']['tmp_name']));
            $audio_mime = $_FILES['audio']['type'] ?: 'audio/webm';
            $perfil = perfil_desde_voz($pdo, null, $audio_b64, $audio_mime, $nombre);
        } else {
            $perfil = perfil_desde_texto($pdo, null, $texto_in, $nombre);
        }
    } catch (Throwable $e) {
        onboarding_lock_fail($pdo, $USUARIO_ID, $LOCK_TOKEN);
        echo json_encode(['ok'=>false,'err'=>'No pude procesar: '.substr($e->getMessage(),0,120)]); exit;
    }

    // A partir de aquí, CUALQUIER Throwable debe soltar el lock (no dejarlo 'procesando'):
    // cubre crear_marca(), los INSERT de calendario/contenido y todo lo demás hasta el done.
    try {

    // 2) Crear la marca con lo que extrajo
    $marca_id = crear_marca($pdo, [
        'usuario_id'       => $USUARIO_ID,
        'municipio_id'     => $municipio,
        'nombre_negocio'   => $nombre,
        'descripcion'      => $perfil['descripcion'] ?? '',
        'voz'              => $perfil['voz'] ?? '',
        'productos'        => array_map(fn($p)=>['nombre'=>$p], array_filter((array)($perfil['productos'] ?? []))),
        'publico_objetivo' => $perfil['publico_objetivo'] ?? '',
        'ofertas'          => $perfil['ofertas'] ?? '',
        'instagram'        => $perfil['instagram'] ?? '',
        'whatsapp'         => $perfil['whatsapp'] ?? '',
        // Tono: MANDA lo que eligió el dueño en el paso de voz; si no eligió, lo que sugiere la IA.
        'tono_boricua'     => $tono['tono_boricua'] ?? ($perfil['tono_boricua'] ?? null),
        'tono_formal'      => $tono['tono_formal']  ?? ($perfil['tono_formal']  ?? null),
        'tono_venta'       => $tono['tono_venta']   ?? ($perfil['tono_venta']   ?? null),
        'tono_ingenio'     => $tono['tono_ingenio'] ?? ($perfil['tono_ingenio'] ?? null),
    ]);

    // 3) Guardar la foto (será la imagen del post de muestra)
    $foto_path = null;
    if (!empty($_FILES['foto']['tmp_name']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
        $info = @getimagesize($_FILES['foto']['tmp_name']);
        $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$info['mime'] ?? ''] ?? null;
        if ($ext && $_FILES['foto']['size'] <= 12*1024*1024) {
            $dir = rtrim(UPLOADS_PATH, '/\\') . "/marca_{$marca_id}/fotos";
            @mkdir($dir, 0775, true);
            $dest = $dir . '/foto_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['foto']['tmp_name'], $dest)) $foto_path = $dest;
        }
    }

    // 4) Generar el POST DE MUESTRA (1 caption + 1 imagen = la cuota gratis)
    $ca = (int)date('Y'); $cm = (int)date('n');
    $pdo->prepare("INSERT INTO crecer_calendario (marca_id,anio,mes,estado,generado_por_ia) VALUES (?,?,?, 'borrador',1) ON DUPLICATE KEY UPDATE updated_at=NOW()")->execute([$marca_id,$ca,$cm]);
    $calid = (int)$pdo->query("SELECT id FROM crecer_calendario WHERE marca_id={$marca_id} AND anio={$ca} AND mes={$cm}")->fetchColumn();
    $pdo->prepare("INSERT INTO crecer_contenido (calendario_id,marca_id,plataforma,tipo,caption,fecha_programada,estado) VALUES (?,?, 'instagram','post',?,?, 'borrador')")
        ->execute([$calid, $marca_id, 'Post de bienvenida: preséntale el negocio a la gente, cálido y boricua', date('Y-m-d 10:00:00')]);
    $cid = (int)$pdo->lastInsertId();
    $caption = '';
    // EL PREVIEW ES EL PRODUCTO — se prepara SIEMPRE con IA (ya no detrás de un flag).
    // El motor arma genome + 3 direcciones + 3 captions REALES (genoma_captions_preview) +
    // observaciones, y lo guarda en pm_preparado para que el Primer Minuto lo LEA directo.
    // Solo si el motor falla del todo, cae al creador clásico como red de emergencia.
    try {
        $marcaRow = $pdo->query("SELECT * FROM crecer_marca WHERE id={$marca_id}")->fetch(PDO::FETCH_ASSOC);
        $prep = pipeline_preparar($pdo, $marcaRow);
        $pdo->prepare("UPDATE crecer_marca SET pm_preparado=? WHERE id=?")
            ->execute([json_encode(['run'=>$prep['run'], 'direcciones'=>$prep['direcciones'], 'observaciones'=>$prep['observaciones']], JSON_UNESCAPED_UNICODE), $marca_id]);
        $caption = '';
    } catch (Throwable $e) {
        error_log('onboarding pipeline_preparar falló: ' . $e->getMessage());
        try { $rp = redactar_pieza($pdo, $cid); $caption = $rp['caption'] ?? ''; } catch (Throwable $e2) {}  // respaldo clásico
    }
    if ($caption !== '') { $pdo->prepare("UPDATE crecer_contenido SET caption=? WHERE id=?")->execute([$caption, $cid]); }
    // SIEMPRE genera la imagen de muestra (cumple la promesa "imagen + caption").
    // Con foto real la realza; sin foto, la IA genera una acorde al tema/negocio.
    try {
        $g = generar_grafica($pdo, $marca_id, $foto_path ?: null, ['copy'=>$caption, 'con_texto'=>false, 'estilo'=>'']);
        $pdo->prepare("UPDATE crecer_contenido SET grafica_path=?, arte_intentos=arte_intentos+1, updated_at=NOW() WHERE id=?")
            ->execute([$g['archivo'], $cid]);
    } catch (Throwable $e) { /* si falla la imagen, el caption igual quedó */ }

    onboarding_lock_done($pdo, $USUARIO_ID, $marca_id, $LOCK_TOKEN);   // marca lock 'listo' + marca_id (idempotente)
    echo json_encode(['ok'=>true, 'marca_id'=>$marca_id]);
    exit;

    } catch (Throwable $e) {
        // Fallo manejable tras adquirir el lock: márcalo 'failed' (re-adquirible al instante),
        // NUNCA lo dejes atascado en 'procesando'. El pipeline_preparar y generar_grafica ya
        // tienen su propio try/catch (degradan sin abortar); esto cubre lo que quedaba sin proteger.
        onboarding_lock_fail($pdo, $USUARIO_ID, $LOCK_TOKEN);
        error_log('onboarding: fallo tras acquire (lock→failed): ' . $e->getMessage());
        echo json_encode(['ok'=>false, 'err'=>'No pude completar el arranque: ' . substr($e->getMessage(), 0, 300)]);
        exit;
    }
}

$municipios = $pdo->query("SELECT id, nombre FROM municipios ORDER BY nombre")->fetchAll();
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Empieza tu negocio · Encuéntralo</title>
<link rel="icon" type="image/svg+xml" href="/crecer/assets/brand/crecer-mark.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="/crecer/assets/encuentralo-ui.css?v=19" rel="stylesheet">
<style>
  body{background:var(--crema)}
  .wrap{max-width:560px;margin:0 auto;padding:26px 20px 70px}
  .top{display:flex;align-items:center;gap:9px;margin-bottom:18px}
  .top img{height:30px}.top b{font-weight:800;font-size:19px;color:var(--tinta)}
  h1{font-family:var(--font-display);font-weight:600;letter-spacing:-.02em;line-height:1.1;font-size:clamp(28px,6.2vw,40px);color:var(--ink-soft)}
  h1 span{color:var(--magenta)}
  .lede{color:var(--muted);font-size:15.5px;margin:8px 0 22px;line-height:1.5}
  /* Wizard: una pantalla a la vez */
  .prog{display:flex;gap:6px;margin:2px 0 20px}
  .prog i{height:6px;flex:1;background:var(--crema-2);border-radius:99px;transition:background .3s}
  .prog i.on{background:linear-gradient(90deg,var(--coral),var(--magenta))}
  /* Paso 4 · selector de voz */
  .voces{display:flex;flex-direction:column;gap:10px}
  .voz{text-align:left;width:100%;font-family:inherit;cursor:pointer;display:block;
    border:1.5px solid var(--line);background:#fff;border-radius:14px;padding:13px 15px;transition:border-color .16s,box-shadow .16s,transform .12s}
  .voz b{display:block;font-family:var(--font-display);font-weight:600;font-size:15.5px;color:var(--tinta);letter-spacing:-.01em}
  .voz span{display:block;color:var(--muted);font-size:13px;margin-top:3px;line-height:1.4}
  .voz:hover{border-color:var(--magenta)}
  .voz:active{transform:scale(.99)}
  .voz.sel{border-color:var(--magenta);box-shadow:0 0 0 3px color-mix(in srgb,var(--magenta) 16%,transparent)}
  .voz.sel b{color:var(--magenta)}
  .step{background:var(--card);border:1px solid var(--line);border-radius:var(--r-lg);padding:20px;box-shadow:var(--shadow-sm);display:none;animation:fadein .3s ease}
  .step.on{display:block}
  @keyframes fadein{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
  .wiznav{display:flex;gap:12px;margin-top:16px}
  .wback{flex:none;border:1.5px solid var(--line);background:#fff;cursor:pointer;font-family:var(--font-display);font-weight:600;font-size:15px;color:var(--ink-soft);padding:0 20px;border-radius:14px}
  .wnext{flex:1;border:0;cursor:pointer;font-family:var(--font-display);font-weight:600;font-size:16px;color:#fff;background:var(--btn-grad);box-shadow:var(--btn-glow);padding:15px;border-radius:15px;transition:transform .2s var(--ease),box-shadow .2s var(--ease)}
  .wnext:active{transform:translateY(1px);box-shadow:var(--btn-glow-active)}
  .wiznav .go{flex:1;width:auto;margin-top:0}
  .step .n{font-family:var(--font-display);font-weight:600;color:var(--magenta);font-size:12px;letter-spacing:.05em;text-transform:uppercase}
  .step label{display:block;font-weight:600;font-size:15px;margin:2px 0 10px;color:var(--ink-soft);font-family:var(--font-display)}
  input[type=text], select{width:100%;font-family:var(--font-body);font-size:15px;border:1.5px solid var(--line);border-radius:12px;padding:11px 13px;background:#fff;transition:border-color .15s}
  input[type=text]:focus, select:focus{outline:none;border-color:color-mix(in srgb,var(--magenta) 45%,var(--line))}
  .rec{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
  .recbtn{border:0;cursor:pointer;font-family:var(--font-display);font-weight:600;font-size:15px;color:#fff;background:var(--btn-grad);box-shadow:var(--btn-glow);padding:13px 22px;border-radius:99px}
  .recbtn.stop{background:var(--tinta);box-shadow:none}
  .recbtn:disabled{opacity:.5;cursor:default}
  .timer{font-family:var(--font-display);font-weight:600;font-size:20px;color:var(--ink-soft)}
  .hint{font-size:12.5px;color:var(--muted);margin-top:10px}
  audio{width:100%;margin-top:12px}
  .photo-prev{width:100%;max-height:230px;object-fit:cover;border-radius:14px;margin-top:12px;display:none}
  /* Uploader (subir foto = estructural → teal, como la Biblioteca) */
  .uploader{display:flex;align-items:center;gap:13px;width:100%;cursor:pointer;padding:15px 16px;border:1.5px dashed color-mix(in srgb,var(--teal) 36%,var(--line));border-radius:14px;background:color-mix(in srgb,var(--teal) 4%,#fff);transition:border-color .15s,background .15s}
  .uploader:hover,.uploader.drag{border-color:var(--teal);background:color-mix(in srgb,var(--teal) 8%,#fff)}
  .uploader .up-ic{width:44px;height:44px;border-radius:12px;flex:none;display:grid;place-items:center;background:var(--btn-grad);color:#fff}
  .uploader .up-ic svg{width:22px;height:22px}
  .uploader .up-tx{font-weight:600;font-size:14.5px;color:var(--ink-soft);line-height:1.3;overflow:hidden;text-overflow:ellipsis;font-family:var(--font-display)}
  .uploader .up-tx b{display:block;color:var(--muted);font-weight:500;font-size:12.5px;margin-top:1px}
  .uploader.has-file{border-style:solid;border-color:var(--palma);background:color-mix(in srgb,var(--palma) 7%,#fff)}
  .uploader.has-file .up-ic{background:var(--palma)}
  input[type=file]{position:absolute;width:1px;height:1px;opacity:0;overflow:hidden;clip:rect(0 0 0 0)}
  .go{width:100%;border:0;cursor:pointer;font-family:var(--font-display);font-weight:600;font-size:16px;color:#fff;background:var(--btn-grad);box-shadow:var(--btn-glow);padding:16px;border-radius:15px;margin-top:6px;transition:transform .2s var(--ease),box-shadow .2s var(--ease)}
  .go:active{transform:translateY(1px);box-shadow:var(--btn-glow-active)}
  .go:disabled{opacity:.5;cursor:default}
  .err{background:#fdeaea;color:#b42318;font-weight:600;font-size:14px;padding:11px 14px;border-radius:12px;margin-bottom:14px;display:none;border:1px solid #f5c2c0}
  /* overlay de carga */
  .load{position:fixed;inset:0;background:rgba(20,12,20,.94);z-index:90;display:none;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:30px;color:#fff}
  .load.show{display:flex}
  .load .big{font-family:var(--font-display);font-weight:600;font-size:24px;letter-spacing:-.01em;margin-bottom:8px}
  .load .sub{color:#cfc7d6;font-size:14.5px;max-width:340px;line-height:1.5}
  .spin{width:46px;height:46px;border:4px solid rgba(255,255,255,.2);border-top-color:var(--magenta);border-radius:50%;animation:sp 1s linear infinite;margin-bottom:20px}
  @keyframes sp{to{transform:rotate(360deg)}}
  .dotpulse{margin-top:18px;color:#a79bb8;font-size:13px;min-height:18px}
</style>
</head>
<body>
<div class="wrap">
  <div class="top"><img src="/crecer/assets/brand/crecer-mark.svg" alt=""><b>encuéntralo <span style="color:var(--teal)">crecer</span></b></div>
  <h1>Háblame de <span>tu negocio</span></h1>
  <p class="lede">No llenes formularios largos. Grábate 40 segundos contándome de tu negocio y el corillo arma tu primer post — en tu propia voz boricua.</p>

  <div class="prog"><i class="on"></i><i></i><i></i><i></i></div>
  <div class="err" id="err"></div>

  <div class="step on" data-step="1">
    <div class="n">PASO 1</div>
    <label>¿Cómo se llama tu negocio?</label>
    <input type="text" id="nombre" placeholder="Ej. El Palo Dulce" maxlength="120" value="<?= htmlspecialchars((string)($_SESSION['negocio_intent'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    <label style="margin-top:14px">¿De qué pueblo es tu negocio? <span style="color:var(--muted);font-weight:500">(opcional)</span></label>
    <select id="municipio_id">
      <option value="">— Escoge tu pueblo —</option>
      <?php foreach ($municipios as $m): ?><option value="<?= $m['id'] ?>"><?= $h($m['nombre']) ?></option><?php endforeach; ?>
    </select>
  </div>

  <div class="step" data-step="2">
    <div class="n">PASO 2</div>
    <label>Cuéntame de tu negocio</label>
    <div id="bloque-voz">
      <div class="rec">
        <img src="/crecer/assets/icons/mic.svg" alt="" style="width:34px;height:34px;flex:none">
        <button type="button" class="recbtn" id="rec">● Grabar</button>
        <span class="timer" id="timer">0:00</span>
      </div>
      <audio id="player" controls style="display:none"></audio>
      <div class="hint">Di: qué vendes, a quién, qué te hace especial, alguna promo. Entre 20 y 60 segundos. Habla normal, como le hablas a un cliente.</div>
      <div style="margin-top:12px"><a href="#" id="prefiero" style="color:var(--terracota);font-weight:700;font-size:13.5px;text-decoration:none">Mejor lo escribo (sin micrófono)</a></div>
    </div>
    <div id="texto-hint" style="display:none;background:var(--okk-bg,#e6f6ee);color:var(--okk-ink,#0d7a44);font-weight:700;font-size:13px;padding:10px 13px;border-radius:11px;margin-top:10px"></div>
    <textarea id="texto" rows="4" placeholder="Escribe: qué vendes, a quién, qué te hace especial, alguna promo…" style="display:none;width:100%;font-family:inherit;font-size:15px;border:1.5px solid var(--line);border-radius:12px;padding:11px 13px;margin-top:10px"></textarea>
  </div>

  <div class="step" data-step="3">
    <div class="n">PASO 3 · OPCIONAL</div>
    <label style="display:inline-flex;align-items:center;gap:8px"><img src="/crecer/assets/icons/foto.svg" alt="" style="width:22px;height:22px"> ¿Tienes una foto de tu producto? <span style="color:var(--muted);font-weight:500">(opcional)</span></label>
    <label class="uploader" id="foto-drop" for="foto">
      <span class="up-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg></span>
      <span class="up-tx" id="foto-tx">Subir una foto<b>Toca aquí o arrástrala</b></span>
    </label>
    <input type="file" id="foto" accept="image/png,image/jpeg,image/webp">
    <img class="photo-prev" id="prev" alt="">
    <div class="hint">Si tienes una foto real de lo que vendes, la IA la convierte en tu post de muestra. <b>Si no tienes ahora, no hay lío</b> — el corillo te arma el caption igual y la foto la subes después desde tu panel.</div>
  </div>

  <div class="step" data-step="4">
    <div class="n">PASO 4</div>
    <label>¿Cómo quieres que suene tu marca?</label>
    <div class="hint" style="margin:2px 0 13px">Elige la voz de tus posts. La puedes cambiar cuando quieras en Mi marca.</div>
    <div class="voces">
      <button type="button" class="voz" data-voz="profesional"><b>Profesional</b><span>Formal y serio. Para abogados, ingenieros, médicos, contables.</span></button>
      <button type="button" class="voz sel" data-voz="boricua"><b>Boricua</b><span>Bien de la isla, con sabor y de la calle.</span></button>
      <button type="button" class="voz" data-voz="creativo"><b>Creativo</b><span>Con chispa, humor y giros inesperados.</span></button>
      <button type="button" class="voz" data-voz="calido"><b>Cálido</b><span>Cercano y de confianza, como un buen amigo.</span></button>
      <button type="button" class="voz" data-voz="vendedor"><b>Vendedor</b><span>Directo a la acción, con gancho de venta.</span></button>
    </div>
  </div>

  <div class="wiznav">
    <button type="button" class="wback" id="wback" style="display:none">← Atrás</button>
    <button type="button" class="wnext" id="wnext">Siguiente →</button>
    <button class="go" id="go" style="display:none">Crea mi primer post →</button>
  </div>
  <div class="hint" style="text-align:center;margin-top:14px">Gratis · sin tarjeta · tu logo y más posts se desbloquean luego con un plan</div>
</div>

<div class="load" id="load">
  <div class="spin"></div>
  <div class="big">El corillo está trabajando</div>
  <div class="sub">Escuchando tu voz, aprendiendo tu negocio y montándote el primer post…</div>
  <div class="dotpulse" id="dot">Escuchando…</div>
</div>

<script>
  var mediaRec=null, chunks=[], blob=null, secs=0, timerId=null;
  var recBtn=document.getElementById('rec'), timer=document.getElementById('timer'), player=document.getElementById('player');
  var foto=document.getElementById('foto'), prev=document.getElementById('prev');

  function fmt(s){return Math.floor(s/60)+':'+('0'+(s%60)).slice(-2);}

  recBtn.addEventListener('click', async function(){
    if (mediaRec && mediaRec.state==='recording'){ mediaRec.stop(); return; }
    if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia){
      usarTexto('Tu navegador no deja grabar aquí — no hay lío, cuéntamelo por escrito');
      return;
    }
    try{
      var stream = await navigator.mediaDevices.getUserMedia({audio:true});
      chunks=[]; mediaRec = new MediaRecorder(stream);
      mediaRec.ondataavailable = function(e){ if(e.data.size) chunks.push(e.data); };
      mediaRec.onstop = function(){
        blob = new Blob(chunks, {type: mediaRec.mimeType || 'audio/webm'});
        player.src = URL.createObjectURL(blob); player.style.display='block';
        stream.getTracks().forEach(function(t){t.stop();});
        recBtn.textContent='● Volver a grabar'; recBtn.classList.remove('stop');
        clearInterval(timerId);
      };
      mediaRec.start(); secs=0; timer.textContent='0:00';
      recBtn.textContent='■ Detener'; recBtn.classList.add('stop');
      timerId = setInterval(function(){ secs++; timer.textContent=fmt(secs); if(secs>=60) mediaRec.stop(); }, 1000);
    }catch(e){
      // Mic bloqueado/sin permiso → NO trancamos al usuario: lo pasamos a escribir, suave.
      usarTexto('No pudimos usar el micrófono — tranqui, cuéntamelo por escrito aquí');
    }
  });

  var fotoDrop=document.getElementById('foto-drop'), fotoTx=document.getElementById('foto-tx');
  function mostrarFoto(file){
    if(!file) return;
    prev.src=URL.createObjectURL(file); prev.style.display='block';
    if(fotoTx) fotoTx.innerHTML = file.name + '<b>Toca para cambiar</b>';
    if(fotoDrop) fotoDrop.classList.add('has-file');
  }
  foto.addEventListener('change', function(){ if(foto.files[0]) mostrarFoto(foto.files[0]); });
  // Arrastrar y soltar
  if(fotoDrop){
    ['dragenter','dragover'].forEach(function(ev){ fotoDrop.addEventListener(ev, function(e){ e.preventDefault(); fotoDrop.classList.add('drag'); }); });
    ['dragleave','drop'].forEach(function(ev){ fotoDrop.addEventListener(ev, function(e){ e.preventDefault(); fotoDrop.classList.remove('drag'); }); });
    fotoDrop.addEventListener('drop', function(e){
      var f=e.dataTransfer && e.dataTransfer.files[0];
      if(f && /^image\//.test(f.type)){ foto.files=e.dataTransfer.files; mostrarFoto(f); }
    });
  }

  // Paso 4: selección de voz (una sola)
  [].forEach.call(document.querySelectorAll('.voz'), function(b){
    b.addEventListener('click', function(){
      [].forEach.call(document.querySelectorAll('.voz'), function(x){ x.classList.remove('sel'); });
      b.classList.add('sel');
    });
  });

  function usarTexto(msg){
    var t=document.getElementById('texto'); if(t) t.style.display='block';
    var bv=document.getElementById('bloque-voz'); if(bv) bv.style.display='none'; // oculta el micrófono
    var h=document.getElementById('texto-hint');
    if(h && msg){ h.textContent=msg; h.style.display='block'; }
    try{ t.focus(); }catch(_){ }
  }
  document.getElementById('prefiero').addEventListener('click', function(e){
    e.preventDefault(); usarTexto('');
  });

  // Al cargar: decidir si mostrar el micrófono o solo el texto.
  if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia){
    // El navegador no puede grabar → ni mostramos el micrófono.
    usarTexto('Aquí no se puede grabar — escríbelo y el corillo arranca igual');
  } else if (navigator.permissions && navigator.permissions.query){
    navigator.permissions.query({name:'microphone'}).then(function(st){
      // 'denied' → ocultamos el micrófono y dejamos solo el texto.
      if(st.state==='denied') usarTexto('Tu micrófono está bloqueado — no hay lío, escríbelo aquí');
      // 'granted' o 'prompt' (primera vez) → dejamos el micrófono visible.
      if(st.onchange===null){ st.onchange=function(){ if(st.state==='denied') usarTexto('Micrófono bloqueado — escríbelo aquí'); }; }
    }).catch(function(){});
  }

  function showErr(m){ var e=document.getElementById('err'); e.textContent='⚠️ '+m; e.style.display='block'; window.scrollTo(0,0); }

  var dots=['Escuchando tu voz…','Aprendiendo tu negocio…','Escribiendo tu caption…','Montando tu arte…'];
  var enviando=false;                       // guard: un solo envío en curso (anti doble-clic / concurrencia)
  var POLL_MS=3000, POLL_MAX_MS=150000;     // reintento controlado; tope < 180s del lock (no re-adquiere)

  // POST que devuelve {status, data}; data=null si la respuesta no es JSON (p. ej. un 504 del proxy).
  function postOnboarding(fd){
    return fetch('/crecer/onboarding.php', {method:'POST', body:fd}).then(function(r){
      return r.text().then(function(t){ var d=null; try{ d=JSON.parse(t); }catch(_){ } return {status:r.status, data:d}; });
    });
  }

  document.getElementById('go').addEventListener('click', function(){
    if(enviando) return;                    // ya hay un envío en curso → ignora clics extra
    var nombre=document.getElementById('nombre').value.trim();
    if(!nombre){ showErr('Ponle nombre a tu negocio (paso 1).'); return; }
    var texto=document.getElementById('texto').value.trim();
    if(!blob && !texto){ showErr('Grábate o escribe de tu negocio (paso 2).'); return; }
    document.getElementById('err').style.display='none';
    var goBtn=document.getElementById('go');
    var muni=document.getElementById('municipio_id').value;

    // Envío COMPLETO (una sola vez): voz/texto + foto.
    var fd=new FormData();
    fd.append('nombre', nombre);
    fd.append('municipio_id', muni);
    if(blob) fd.append('audio', blob, 'voz.webm');
    else fd.append('texto', texto);
    if(foto.files[0]) fd.append('foto', foto.files[0]);   // foto opcional
    var vsel=document.querySelector('.voz.sel'); fd.append('voz_preset', vsel ? vsel.getAttribute('data-voz') : '');  // voz elegida (paso 4)
    // Sondas de estado LIGERAS (no re-suben audio/foto): mientras el lock está 'procesando' el
    // backend responde 202 antes de tocar el audio, y {ok:true} cuando termina. El tope < 180s
    // evita que una sonda ligera re-adquiera el lock (que exigiría voz/texto).
    function ligera(){ var p=new FormData(); p.append('nombre', nombre); p.append('municipio_id', muni); p.append('reintento','1'); return p; }

    enviando=true; goBtn.disabled=true;
    var load=document.getElementById('load'); load.classList.add('show');
    var di=0, dEl=document.getElementById('dot');
    var dotTimer=setInterval(function(){ di=(di+1)%dots.length; dEl.textContent=dots[di]; }, 4000);
    var t0=Date.now();

    function terminar(){ clearInterval(dotTimer); }
    function fallar(msg){ terminar(); load.classList.remove('show'); showErr(msg); enviando=false; goBtn.disabled=false; }
    var TARDE='Esto está tardando más de lo normal. Recarga la página en un momento — tu negocio debería estar listo.';

    function manejar(res){
      var d=res.data;
      if(d && d.ok && d.marca_id){ terminar(); location.href='/crecer/primer_minuto.php?marca='+d.marca_id; return; }
      var procesando = (res.status===202) || (d && d.estado==='procesando' && d.reintentar);
      if(procesando){ sondear(); return; }              // sigue montándose → reintenta controladamente
      fallar((d && d.err) ? d.err : 'Algo falló. Intenta de nuevo.');
    }
    function sondear(){
      if(Date.now()-t0 > POLL_MAX_MS){ fallar(TARDE); return; }
      setTimeout(function(){
        postOnboarding(ligera()).then(manejar).catch(function(){
          if(Date.now()-t0 > POLL_MAX_MS) fallar(TARDE);   // transitorio → reintenta hasta el tope
          else sondear();
        });
      }, POLL_MS);
    }

    postOnboarding(fd).then(manejar).catch(function(){ fallar('Error de conexión. Intenta de nuevo.'); });
  });

  // ── Wizard: una pantalla a la vez ──
  (function(){
    var steps=[].slice.call(document.querySelectorAll('.step'));
    var bars=[].slice.call(document.querySelectorAll('.prog i'));
    var wback=document.getElementById('wback'), wnext=document.getElementById('wnext'), goBtn=document.getElementById('go');
    var errEl=document.getElementById('err');
    var cur=1, total=steps.length;
    function paint(){
      steps.forEach(function(s){ s.classList.toggle('on', +s.dataset.step===cur); });
      bars.forEach(function(b,i){ b.classList.toggle('on', i<cur); });
      wback.style.display = cur>1 ? '' : 'none';
      wnext.style.display = cur<total ? '' : 'none';
      goBtn.style.display = cur===total ? '' : 'none';
      if(errEl) errEl.style.display='none';
      window.scrollTo({top:0, behavior:'smooth'});
    }
    wnext.addEventListener('click', function(){
      if(cur===1){
        var nom=document.getElementById('nombre');
        if(!nom.value.trim()){ if(typeof showErr==='function') showErr('Ponle el nombre a tu negocio para seguir.'); nom.focus(); return; }
      }
      if(cur<total){ cur++; paint(); }
    });
    wback.addEventListener('click', function(){ if(cur>1){ cur--; paint(); } });
    paint();
  })();
</script>
</body>
</html>
