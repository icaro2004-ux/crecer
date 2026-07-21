<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — La Sala del Corillo (War Room)
//  panel/sala.php  ·  brainstorm + órdenes + fechas/ofertas + staff meeting semanal.
//  Un solo espacio para conversar con tu equipo. Con voz (habla y te contesta).
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/agentes.php';
requiere_login();

$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }
$marca_id = (int)$marca['id'];

// ── AJAX: el dueño habla en la sala ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_ok()) { echo json_encode(['ok'=>false,'err'=>'Sesión expiró. Recarga la página.']); exit; }
    $mensaje = trim((string)($_POST['mensaje'] ?? ''));
    if ($mensaje === '') { echo json_encode(['ok'=>false,'err'=>'Escribe o di algo.']); exit; }
    $historial = json_decode((string)($_POST['historial'] ?? '[]'), true);
    if (!is_array($historial)) $historial = [];
    $limite = copiloto_limite_uso($pdo, $marca_id);
    if (empty($limite['ok'])) { echo json_encode(['ok'=>false, 'err'=>$limite['err']], JSON_UNESCAPED_UNICODE); exit; }
    require_once __DIR__ . '/../includes/suscripcion.php';
    $puede_producir = (function_exists('activacion_de_prueba') && activacion_de_prueba($usuario['email'] ?? null))
        || (function_exists('plan_de_marca') && plan_de_marca($pdo, $marca_id) !== null);
    try {
        $r = sala_responder($pdo, $marca_id, mb_substr($mensaje, 0, 1000), $historial, $puede_producir);
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false, 'err'=>substr($e->getMessage(), 0, 160)], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$saludo = sala_saludo($pdo, $marca_id);
$roster = equipo_roster();
$active = 'sala';
$page_title = 'La Sala';
require __DIR__ . '/_shell.php';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<style>
  .sc-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:2px}
  .sc-h1{font-family:'Oswald',sans-serif;font-weight:700;font-size:23px;letter-spacing:.4px;color:var(--tinta);margin:0;line-height:1.1}
  .sc-sub{font-size:13px;color:var(--muted);margin:2px 0 0;max-width:600px;line-height:1.45}
  .sc-team{display:flex;gap:5px;align-items:center;flex-wrap:wrap;margin:12px 0 6px}
  .sc-mem{display:flex;align-items:center;gap:6px;background:#fff;border:1px solid var(--line);border-radius:99px;padding:4px 11px 4px 6px;font-size:12px;font-weight:700;color:var(--tinta)}
  .sc-mem .e{font-size:15px}
  .sc-msgs{display:flex;flex-direction:column;gap:12px;margin:10px 0 12px}
  .sc-m{max-width:88%;padding:12px 15px;border-radius:16px;font-size:14.5px;line-height:1.55;white-space:pre-wrap;word-wrap:break-word}
  .sc-m.ia{background:#fff;border:1px solid var(--line);color:var(--tinta);align-self:flex-start;border-bottom-left-radius:6px;box-shadow:var(--shadow-sm)}
  .sc-m.user{background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff;align-self:flex-end;border-bottom-right-radius:6px}
  .sc-m.load{color:var(--muted);font-style:italic;background:#fff;border:1px solid var(--line);align-self:flex-start}
  .sc-learn{align-self:flex-start;max-width:88%;background:#e6f7f4;border:1px solid #9ad9d0;color:#0a6a5f;border-radius:12px;padding:8px 12px;font-size:12.5px;font-weight:600}
  .sc-cta{align-self:flex-start}
  .sc-cta a{display:inline-block;background:var(--tinta);color:#fff;text-decoration:none;font-weight:800;font-size:13px;padding:9px 15px;border-radius:12px}
  .sc-form{display:flex;gap:8px;position:sticky;bottom:0;background:linear-gradient(to top,var(--crema) 72%,transparent);padding:10px 0 4px;align-items:center}
  .sc-form input{flex:1;font-family:inherit;font-size:14.5px;border:1.5px solid var(--line);border-radius:14px;padding:13px 15px;background:#fff}
  .sc-icon{border:1.5px solid var(--line);background:#fff;cursor:pointer;width:48px;height:48px;border-radius:14px;font-size:20px;flex:none;display:grid;place-items:center;transition:all .15s}
  .sc-icon.on{background:linear-gradient(135deg,var(--coral),var(--magenta));border-color:transparent}
  .sc-icon.rec{background:#e0245e;border-color:transparent;color:#fff;animation:scpulse 1s infinite}
  @keyframes scpulse{0%,100%{transform:scale(1)}50%{transform:scale(1.08)}}
  .sc-send{border:0;cursor:pointer;background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff;font-weight:800;padding:0 18px;height:48px;border-radius:14px;font-family:inherit;font-size:15px;flex:none}
  .sc-hint{font-size:11.5px;color:var(--muted);margin:2px 2px 0;text-align:center}
</style>

<div class="sc-head">
  <div>
    <h1 class="sc-h1">La Sala del Corillo</h1>
    <div class="sc-sub">Tu war room con el equipo. Tira ideas, da órdenes ("hazme 3 posts de X"), fija fechas y ofertas — el corillo aprende y ejecuta.</div>
  </div>
  <button type="button" class="sc-icon" id="sc-speak" title="Que el corillo te conteste en voz">🔈</button>
</div>

<div class="sc-team">
  <?php foreach ($roster as $key => $ag): ?>
    <span class="sc-mem"><span class="e"><?= $ag['emoji'] ?></span><?= $h(equipo_nombre($marca, $key)) ?></span>
  <?php endforeach; ?>
</div>

<div class="sc-msgs" id="sc-msgs">
  <div class="sc-m ia"><?= $h($saludo['respuesta']) ?></div>
</div>

<form class="sc-form" id="sc-form" autocomplete="off">
  <button type="button" class="sc-icon" id="sc-mic" title="Hablar">🎤</button>
  <input type="text" id="sc-input" placeholder="Escribe o toca el 🎤 para hablar…" maxlength="1000">
  <button type="submit" class="sc-send" id="sc-send">Enviar</button>
</form>
<div class="sc-hint" id="sc-michint" style="display:none">🎙️ Escuchando… habla claro y para cuando termines.</div>

<script>
(function(){
  var CSRF=<?= json_encode(csrf_token()) ?>, SALUDO=<?= json_encode($saludo['respuesta'], JSON_UNESCAPED_UNICODE) ?>;
  var MARCA=<?= (int)$marca_id ?>;
  var msgs=document.getElementById('sc-msgs'), form=document.getElementById('sc-form'),
      input=document.getElementById('sc-input'), send=document.getElementById('sc-send');
  var hist=[{rol:'ia',texto:SALUDO}];
  function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}
  function bubble(t,cls){var d=document.createElement('div');d.className='sc-m '+cls;d.textContent=t;msgs.appendChild(d);d.scrollIntoView({behavior:'smooth',block:'end'});return d;}
  function learn(items){ if(!items||!items.length) return;
    var d=document.createElement('div');d.className='sc-learn';
    d.innerHTML='🧠 <b>El corillo aprendió:</b> '+items.map(esc).join(' · ');
    msgs.appendChild(d);d.scrollIntoView({behavior:'smooth',block:'end'});
  }
  function ctaPropuestas(){
    var d=document.createElement('div');d.className='sc-cta';
    d.innerHTML='<a href="/crecer/panel/propuestas.php?marca='+MARCA+'">Ver lo que dejó el corillo →</a>';
    msgs.appendChild(d);d.scrollIntoView({behavior:'smooth',block:'end'});
  }

  // ── VOZ: que el corillo lea su respuesta (opcional, se activa con el 🔈) ──
  var speakOn=false, spk=document.getElementById('sc-speak');
  spk.addEventListener('click',function(){ speakOn=!speakOn; spk.classList.toggle('on',speakOn); spk.textContent=speakOn?'🔊':'🔈'; if(!speakOn&&window.speechSynthesis)speechSynthesis.cancel(); });
  function decir(t){ if(!speakOn||!('speechSynthesis' in window)) return;
    try{ speechSynthesis.cancel(); var u=new SpeechSynthesisUtterance(t); u.lang='es-US'; u.rate=1.02;
      var vs=speechSynthesis.getVoices(); var v=vs.filter(function(x){return /es(-|_)/i.test(x.lang);})[0]; if(v)u.voice=v;
      speechSynthesis.speak(u);
    }catch(e){}
  }

  function enviar(t){
    t=(t||'').trim(); if(!t) return;
    bubble(t,'user'); hist.push({rol:'user',texto:t}); input.value=''; input.disabled=true; send.disabled=true;
    var load=bubble('El corillo lo está viendo…','load');
    var fd=new FormData(); fd.append('csrf',CSRF); fd.append('mensaje',t); fd.append('historial',JSON.stringify(hist));
    fetch(location.pathname+location.search,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
      load.remove(); input.disabled=false; send.disabled=false; input.focus();
      if(!d.ok){ bubble('No pude seguir ahora: '+(d.err||'intenta otra vez'),'ia'); return; }
      learn(d.aprendido);
      bubble(d.respuesta,'ia'); hist.push({rol:'ia',texto:d.respuesta}); decir(d.respuesta);
      if(d.accion==='campana') ctaPropuestas();
    }).catch(function(){ load.remove(); input.disabled=false; send.disabled=false; bubble('Se cayó la conexión. Intenta otra vez.','ia'); });
  }
  form.addEventListener('submit',function(e){ e.preventDefault(); enviar(input.value); });

  // ── VOZ: dictado del dueño (Web Speech API — Chrome/Android) ──
  var SR=window.SpeechRecognition||window.webkitSpeechRecognition, mic=document.getElementById('sc-mic'), hint=document.getElementById('sc-michint'), rec=null, grabando=false;
  if(!SR){ mic.style.display='none'; }
  else {
    rec=new SR(); rec.lang='es-US'; rec.interimResults=true; rec.continuous=false; rec.maxAlternatives=1;
    var finalTxt='';
    rec.onresult=function(e){ var interim=''; finalTxt='';
      for(var i=0;i<e.results.length;i++){ var t=e.results[i][0].transcript; if(e.results[i].isFinal) finalTxt+=t; else interim+=t; }
      input.value=(finalTxt||interim); };
    rec.onerror=function(){ parar(); };
    rec.onend=function(){ parar(); if((input.value||'').trim()) enviar(input.value); };
    function arrancar(){ try{ finalTxt=''; input.value=''; rec.start(); grabando=true; mic.classList.add('rec'); mic.textContent='⏺️'; hint.style.display='block'; }catch(e){} }
    function parar(){ grabando=false; mic.classList.remove('rec'); mic.textContent='🎤'; hint.style.display='none'; }
    mic.addEventListener('click',function(){ if(grabando){ try{rec.stop();}catch(e){} } else { arrancar(); } });
  }
})();
</script>

<?php require __DIR__ . '/_shell_foot.php'; ?>
