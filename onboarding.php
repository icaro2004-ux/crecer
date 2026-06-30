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
requiere_login();
$usuario = usuario_actual($pdo);
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

    $municipio   = ($_POST['municipio_id'] ?? '') !== '' ? (int)$_POST['municipio_id'] : null;
    $texto_in    = trim($_POST['texto'] ?? '');
    $tiene_audio = !empty($_FILES['audio']['tmp_name']) && $_FILES['audio']['error'] === UPLOAD_ERR_OK;
    if (!$tiene_audio && $texto_in === '') {
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
        echo json_encode(['ok'=>false,'err'=>'No pude procesar: '.substr($e->getMessage(),0,120)]); exit;
    }

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
        // Tono inicial elegido por la IA según el tipo de negocio (si lo devolvió).
        'tono_boricua'     => $perfil['tono_boricua'] ?? null,
        'tono_formal'      => $perfil['tono_formal']  ?? null,
        'tono_venta'       => $perfil['tono_venta']   ?? null,
        'tono_ingenio'     => $perfil['tono_ingenio'] ?? null,
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
    try { $rp = redactar_pieza($pdo, $cid); $caption = $rp['caption'] ?? ''; } catch (Throwable $e) {}
    if ($foto_path) {
        try {
            $g = generar_grafica($pdo, $marca_id, $foto_path, ['copy'=>$caption, 'con_texto'=>false, 'estilo'=>'']);
            $pdo->prepare("UPDATE crecer_contenido SET grafica_path=?, arte_intentos=arte_intentos+1, updated_at=NOW() WHERE id=?")
                ->execute([$g['archivo'], $cid]);
        } catch (Throwable $e) { /* el caption igual quedó */ }
    }

    echo json_encode(['ok'=>true, 'marca_id'=>$marca_id]);
    exit;
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
<link rel="icon" type="image/svg+xml" href="/crecer/assets/brand/encuentralo-pin.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Anton&family=Poppins:wght@600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="/crecer/assets/encuentralo-ui.css?v=2" rel="stylesheet">
<style>
  body{background:var(--crema)}
  .wrap{max-width:560px;margin:0 auto;padding:26px 20px 70px}
  .top{display:flex;align-items:center;gap:9px;margin-bottom:18px}
  .top img{height:30px}.top b{font-weight:800;font-size:19px;color:var(--tinta)}
  h1{font-family:var(--font-impact);text-transform:uppercase;letter-spacing:.5px;line-height:.96;font-size:clamp(30px,7vw,44px);color:var(--tinta)}
  h1 span{color:var(--terracota)}
  .lede{color:var(--muted);font-size:15.5px;margin:8px 0 22px}
  .step{background:var(--card);border:1px solid var(--line);border-radius:var(--r-lg);padding:18px;margin-bottom:14px;box-shadow:var(--shadow-sm)}
  .step .n{font-family:var(--font-impact);color:var(--terracota);font-size:13px}
  .step label{display:block;font-weight:800;font-size:15px;margin:2px 0 10px}
  input[type=text], select{width:100%;font-family:inherit;font-size:15px;border:1.5px solid var(--line);border-radius:12px;padding:11px 13px;background:#fff}
  .rec{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
  .recbtn{border:0;cursor:pointer;font-family:inherit;font-weight:800;font-size:15px;color:#fff;background:var(--terracota);padding:13px 20px;border-radius:99px}
  .recbtn.stop{background:var(--tinta)}
  .recbtn:disabled{opacity:.5;cursor:default}
  .timer{font-family:var(--font-impact);font-size:20px;color:var(--tinta)}
  .hint{font-size:12.5px;color:var(--muted);margin-top:10px}
  audio{width:100%;margin-top:12px}
  .photo-prev{width:100%;max-height:230px;object-fit:cover;border-radius:14px;margin-top:12px;display:none}
  .go{width:100%;border:0;cursor:pointer;font-family:var(--font-impact);text-transform:uppercase;letter-spacing:.04em;font-size:18px;color:#fff;background:var(--palma);padding:16px;border-radius:14px;box-shadow:0 12px 28px -12px rgba(22,184,106,.7);margin-top:6px}
  .go:disabled{opacity:.5;cursor:default}
  .err{background:var(--noo-bg);color:var(--noo-ink);font-weight:700;font-size:14px;padding:11px 14px;border-radius:12px;margin-bottom:14px;display:none}
  /* overlay de carga */
  .load{position:fixed;inset:0;background:rgba(27,22,34,.92);z-index:90;display:none;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:30px;color:#fff}
  .load.show{display:flex}
  .load .big{font-family:var(--font-impact);text-transform:uppercase;font-size:26px;letter-spacing:.04em;margin-bottom:8px}
  .load .sub{color:#cfc7d6;font-size:14.5px;max-width:340px}
  .spin{width:46px;height:46px;border:4px solid rgba(255,255,255,.2);border-top-color:var(--terracota);border-radius:50%;animation:sp 1s linear infinite;margin-bottom:20px}
  @keyframes sp{to{transform:rotate(360deg)}}
  .dotpulse{margin-top:18px;color:#a79bb8;font-size:13px;min-height:18px}
</style>
</head>
<body>
<div class="wrap">
  <div class="top"><img src="/crecer/assets/brand/encuentralo-pin.svg" alt=""><b>encuéntralo</b></div>
  <h1>Háblame de <span>tu negocio</span></h1>
  <p class="lede">No llenes formularios largos. Grábate 40 segundos contándome de tu negocio y el corillo arma tu primer post — en tu propia voz boricua.</p>

  <div class="err" id="err"></div>

  <div class="step">
    <div class="n">PASO 1</div>
    <label>¿Cómo se llama tu negocio?</label>
    <input type="text" id="nombre" placeholder="Ej. El Palo Dulce" maxlength="120">
    <label style="margin-top:14px">¿De qué pueblo es tu negocio? <span style="color:var(--muted);font-weight:500">(opcional)</span></label>
    <select id="municipio_id">
      <option value="">— Escoge tu pueblo —</option>
      <?php foreach ($municipios as $m): ?><option value="<?= $m['id'] ?>"><?= $h($m['nombre']) ?></option><?php endforeach; ?>
    </select>
  </div>

  <div class="step">
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
      <div style="margin-top:12px"><a href="#" id="prefiero" style="color:var(--terracota);font-weight:700;font-size:13.5px;text-decoration:none">✍️ Mejor lo escribo (sin micrófono)</a></div>
    </div>
    <div id="texto-hint" style="display:none;background:var(--okk-bg,#e6f6ee);color:var(--okk-ink,#0d7a44);font-weight:700;font-size:13px;padding:10px 13px;border-radius:11px;margin-top:10px"></div>
    <textarea id="texto" rows="4" placeholder="Escribe: qué vendes, a quién, qué te hace especial, alguna promo…" style="display:none;width:100%;font-family:inherit;font-size:15px;border:1.5px solid var(--line);border-radius:12px;padding:11px 13px;margin-top:10px"></textarea>
  </div>

  <div class="step">
    <div class="n">PASO 3 · OPCIONAL</div>
    <label style="display:inline-flex;align-items:center;gap:8px"><img src="/crecer/assets/icons/foto.svg" alt="" style="width:22px;height:22px"> ¿Tienes una foto de tu producto? <span style="color:var(--muted);font-weight:500">(opcional)</span></label>
    <input type="file" id="foto" accept="image/png,image/jpeg,image/webp">
    <img class="photo-prev" id="prev" alt="">
    <div class="hint">Si tienes una foto real de lo que vendes, la IA la convierte en tu post de muestra. <b>Si no tienes ahora, no hay lío</b> — el corillo te arma el caption igual y la foto la subes después desde tu panel.</div>
  </div>

  <button class="go" id="go">⚡ Que el corillo me arme mi post →</button>
  <div class="hint" style="text-align:center;margin-top:14px">Gratis · sin tarjeta · tu logo y más posts se desbloquean luego con un plan</div>
</div>

<div class="load" id="load">
  <div class="spin"></div>
  <div class="big">El corillo está trabajando</div>
  <div class="sub">Escuchando tu voz, aprendiendo tu negocio y montándote el primer post…</div>
  <div class="dotpulse" id="dot">🎧 Escuchando…</div>
</div>

<script>
  var mediaRec=null, chunks=[], blob=null, secs=0, timerId=null;
  var recBtn=document.getElementById('rec'), timer=document.getElementById('timer'), player=document.getElementById('player');
  var foto=document.getElementById('foto'), prev=document.getElementById('prev');

  function fmt(s){return Math.floor(s/60)+':'+('0'+(s%60)).slice(-2);}

  recBtn.addEventListener('click', async function(){
    if (mediaRec && mediaRec.state==='recording'){ mediaRec.stop(); return; }
    if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia){
      usarTexto('Tu navegador no deja grabar aquí — no hay lío, cuéntamelo por escrito 👇');
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
      usarTexto('No pudimos usar el micrófono — tranqui, cuéntamelo por escrito aquí 👇');
    }
  });

  foto.addEventListener('change', function(){
    if(foto.files[0]){ prev.src=URL.createObjectURL(foto.files[0]); prev.style.display='block'; }
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
    usarTexto('Aquí no se puede grabar — escríbelo y el corillo arranca igual 👇');
  } else if (navigator.permissions && navigator.permissions.query){
    navigator.permissions.query({name:'microphone'}).then(function(st){
      // 'denied' → ocultamos el micrófono y dejamos solo el texto.
      if(st.state==='denied') usarTexto('Tu micrófono está bloqueado — no hay lío, escríbelo aquí 👇');
      // 'granted' o 'prompt' (primera vez) → dejamos el micrófono visible.
      if(st.onchange===null){ st.onchange=function(){ if(st.state==='denied') usarTexto('Micrófono bloqueado — escríbelo aquí 👇'); }; }
    }).catch(function(){});
  }

  function showErr(m){ var e=document.getElementById('err'); e.textContent='⚠️ '+m; e.style.display='block'; window.scrollTo(0,0); }

  var dots=['🎧 Escuchando tu voz…','🧠 Aprendiendo tu negocio…','✍️ Escribiendo tu caption…','🎨 Montando tu arte…'];
  document.getElementById('go').addEventListener('click', function(){
    var nombre=document.getElementById('nombre').value.trim();
    if(!nombre){ showErr('Ponle nombre a tu negocio (paso 1).'); return; }
    var texto=document.getElementById('texto').value.trim();
    if(!blob && !texto){ showErr('Grábate o escribe de tu negocio (paso 2).'); return; }
    document.getElementById('err').style.display='none';
    var fd=new FormData();
    fd.append('nombre', nombre);
    fd.append('municipio_id', document.getElementById('municipio_id').value);
    if(blob) fd.append('audio', blob, 'voz.webm');
    else fd.append('texto', texto);
    if(foto.files[0]) fd.append('foto', foto.files[0]);   // foto opcional
    var load=document.getElementById('load'); load.classList.add('show');
    var di=0, dEl=document.getElementById('dot');
    var dotTimer=setInterval(function(){ di=(di+1)%dots.length; dEl.textContent=dots[di]; }, 4000);
    fetch('/crecer/onboarding.php', {method:'POST', body:fd})
      .then(function(r){return r.json();})
      .then(function(d){
        clearInterval(dotTimer);
        if(d.ok){ location.href='/crecer/panel/bienvenida.php?marca='+d.marca_id; }
        else { load.classList.remove('show'); showErr(d.err||'Algo falló. Intenta de nuevo.'); }
      })
      .catch(function(){ clearInterval(dotTimer); load.classList.remove('show'); showErr('Error de conexión. Intenta de nuevo.'); });
  });
</script>
</body>
</html>
