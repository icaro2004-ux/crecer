<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — La Sala del Corillo (War Room)
//  panel/sala.php  ·  brainstorm + órdenes + fechas/ofertas + staff meeting semanal.
//  Un solo espacio para conversar con tu equipo. Con voz (habla y te contesta).
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/agentes.php';
require_once __DIR__ . '/../includes/iconos.php';   // ico() se usa antes de _shell.php
requiere_login();

$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }
$marca_id = (int)$marca['id'];

// ── AJAX: el dueño habla en la sala ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    @set_time_limit(0);   // producir una campaña (planificar + escribir + arte) toma su tiempo
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_ok()) { echo json_encode(['ok'=>false,'err'=>'Sesión expiró. Recarga la página.']); exit; }
    $mensaje = trim((string)($_POST['mensaje'] ?? ''));
    if ($mensaje === '') { echo json_encode(['ok'=>false,'err'=>'Escribe o di algo.']); exit; }
    $historial = json_decode((string)($_POST['historial'] ?? '[]'), true);
    if (!is_array($historial)) $historial = [];
    require_once __DIR__ . '/../includes/suscripcion.php';
    $puede_producir = (function_exists('activacion_de_prueba') && activacion_de_prueba($usuario['email'] ?? null))
        || (function_exists('plan_de_marca') && plan_de_marca($pdo, $marca_id) !== null);
    // El límite del copiloto solo aplica a cuentas FREE (protege costo). Plan/prueba: libre —
    // La Sala es el centro de trabajo y cada mensaje usa varios agentes por dentro.
    if (!$puede_producir) {
        $limite = copiloto_limite_uso($pdo, $marca_id);
        if (empty($limite['ok'])) { echo json_encode(['ok'=>false, 'err'=>$limite['err']], JSON_UNESCAPED_UNICODE); exit; }
    }
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
$gerente = ['svg' => ico($roster['gerente']['ico']), 'nombre' => equipo_nombre($marca, 'gerente')];
$active = 'sala';
$page_title = 'La Sala';
require __DIR__ . '/_shell.php';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<style>
  /* ══ LA SALA DEL CORILLO ══ war room: el equipo presente + hilo + voz.
     Móvil = chat a pantalla, voz al frente. Desktop = columna que respira.
     Hereda tokens de Crecer (crema, rosa/teal, Poppins/Oswald). */
  .content{max-width:760px}
  .sc-top{margin-bottom:2px}
  .sc-top h1{font-family:'Oswald',sans-serif;font-weight:700;font-size:23px;letter-spacing:.4px;color:var(--tinta);margin:0;line-height:1.1}
  .sc-top p{font-size:13px;color:var(--muted);margin:4px 0 0;max-width:560px;line-height:1.45}

  /* Equipo presente: caras con nombre, "en la sala" */
  .sc-team{display:flex;gap:14px;overflow-x:auto;padding:14px 2px 4px;margin:0 -2px;scrollbar-width:none}
  .sc-team::-webkit-scrollbar{display:none}
  .sc-mem{flex:none;display:flex;flex-direction:column;align-items:center;gap:5px;width:58px;text-align:center}
  .sc-mem .face{width:46px;height:46px;border-radius:50%;display:grid;place-items:center;background:var(--card,#fff);border:1.5px solid var(--line);box-shadow:var(--shadow-sm);position:relative}
  .sc-mem .face svg{width:22px;height:22px;color:var(--magenta,#EF4375)}
  .sc-mem .face::after{content:'';position:absolute;right:1px;bottom:1px;width:11px;height:11px;border-radius:50%;background:var(--teal,#00A49F);border:2px solid var(--crema,#F7F5F1)}
  .sc-mem .nm{font-size:11px;font-weight:700;color:var(--tinta);line-height:1.15;max-width:58px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

  /* Hilo de conversación */
  .sc-thread{display:flex;flex-direction:column;gap:14px;padding:8px 0 8px}
  .sc-row{display:flex;gap:9px;max-width:92%;align-items:flex-end}
  .sc-row.ia{align-self:flex-start}
  .sc-row.me{align-self:flex-end;flex-direction:row-reverse}
  .sc-face{width:32px;height:32px;border-radius:50%;flex:none;display:grid;place-items:center;background:var(--card,#fff);border:1.5px solid var(--line);box-shadow:var(--shadow-sm)}
  .sc-face svg{width:17px;height:17px;color:var(--magenta,#EF4375)}
  .sc-bubble{padding:11px 15px;border-radius:18px;font-size:14.5px;line-height:1.55;white-space:pre-wrap;word-wrap:break-word}
  .sc-row.ia .sc-bubble{background:var(--card,#fff);border:1px solid var(--line);color:var(--tinta);border-bottom-left-radius:6px;box-shadow:var(--shadow-sm)}
  .sc-row.me .sc-bubble{background:linear-gradient(135deg,var(--coral,#FF6B3D),var(--magenta,#EF4375));color:#fff;border-bottom-right-radius:6px}
  .sc-who{font-size:11px;font-weight:800;color:var(--magenta,#EF4375);margin-bottom:2px;letter-spacing:.2px}
  .sc-row.ia .sc-bubble.load{color:var(--muted);font-style:italic}
  .sc-dots span{display:inline-block;width:6px;height:6px;border-radius:50%;background:var(--muted);margin:0 1px;animation:scb 1s infinite}
  .sc-dots span:nth-child(2){animation-delay:.15s}.sc-dots span:nth-child(3){animation-delay:.3s}
  @keyframes scb{0%,60%,100%{opacity:.3;transform:translateY(0)}30%{opacity:1;transform:translateY(-3px)}}

  .sc-learn{align-self:flex-start;max-width:92%;background:color-mix(in srgb,var(--teal,#00A49F) 12%,#fff);border:1px solid color-mix(in srgb,var(--teal,#00A49F) 35%,#fff);color:#0a6a5f;border-radius:13px;padding:9px 13px;font-size:12.5px;font-weight:600;line-height:1.4}
  .sc-cta{align-self:flex-start}
  .sc-cta a{display:inline-flex;align-items:center;gap:6px;background:var(--tinta);color:#fff;text-decoration:none;font-weight:800;font-size:13px;padding:10px 16px;border-radius:13px}

  /* Composer: voz al frente */
  .sc-composer{display:flex;gap:9px;align-items:center;position:sticky;bottom:0;background:linear-gradient(to top,var(--crema,#F7F5F1) 74%,transparent);padding:12px 0 6px}
  .sc-input{flex:1;font-family:inherit;font-size:15px;border:1.5px solid var(--line);border-radius:16px;padding:14px 16px;background:var(--card,#fff);color:var(--tinta)}
  .sc-input:focus{outline:0;border-color:var(--magenta,#EF4375)}
  .sc-mic{border:1.5px solid var(--line);background:var(--card,#fff);cursor:pointer;width:52px;height:52px;border-radius:16px;flex:none;display:grid;place-items:center;transition:transform .12s,background .15s,border-color .15s}
  .sc-mic svg{width:23px;height:23px;color:var(--tinta)}
  .sc-mic:active{transform:scale(.94)}
  .sc-mic.rec{background:#e0245e;border-color:transparent;animation:scpulse 1.1s infinite}
  .sc-mic.rec svg{color:#fff}
  @keyframes scpulse{0%,100%{box-shadow:0 0 0 0 rgba(224,36,94,.45)}70%{box-shadow:0 0 0 12px rgba(224,36,94,0)}}
  .sc-send{border:0;cursor:pointer;background:linear-gradient(135deg,var(--coral,#FF6B3D),var(--magenta,#EF4375));color:#fff;font-weight:800;height:52px;padding:0 20px;border-radius:16px;font-family:inherit;font-size:15px;flex:none}
  .sc-send:disabled{opacity:.5;cursor:default}
  .sc-speak{border:1.5px solid var(--line);background:var(--card,#fff);cursor:pointer;width:38px;height:38px;border-radius:11px;display:grid;place-items:center;flex:none}
  .sc-speak svg{width:18px;height:18px;color:var(--tinta)}
  .sc-speak.on{background:linear-gradient(135deg,var(--coral,#FF6B3D),var(--magenta,#EF4375));border-color:transparent}
  .sc-speak.on svg{color:#fff}
  .sc-tools{display:flex;align-items:center;gap:8px}
  .sc-listen{font-size:12px;color:var(--muted);text-align:center;margin:2px 0 0;min-height:16px;font-weight:600}

  @media(max-width:760px){
    .content{padding:16px 14px 8px}
    .sc-thread{padding-bottom:4px}
  }
</style>

<div class="sc-top" style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px">
  <div>
    <h1>La Sala del Corillo</h1>
    <p>Tu war room con el equipo. Tira ideas, da órdenes (“hazme 3 posts de X”), fija fechas y ofertas — el corillo aprende y ejecuta.</p>
  </div>
  <button type="button" class="sc-speak" id="sc-speak" title="Que el corillo te conteste en voz"><?= ico('volume') ?></button>
</div>

<div class="sc-team">
  <?php foreach ($roster as $key => $ag): ?>
    <div class="sc-mem" title="<?= $h($ag['hace']) ?>">
      <div class="face"><?= ico($ag['ico']) ?></div>
      <div class="nm"><?= $h(equipo_nombre($marca, $key)) ?></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="sc-thread" id="sc-msgs">
  <div class="sc-row ia">
    <div class="sc-face"><?= $gerente['svg'] ?></div>
    <div class="sc-bubble"><div class="sc-who"><?= $h($gerente['nombre']) ?></div><?= $h($saludo['respuesta']) ?></div>
  </div>
</div>

<form class="sc-composer" id="sc-form" autocomplete="off">
  <button type="button" class="sc-mic" id="sc-mic" title="Hablar"><?= ico('mic') ?></button>
  <input type="text" class="sc-input" id="sc-input" placeholder="Escribe o toca el micrófono para hablar…" maxlength="1000">
  <button type="submit" class="sc-send" id="sc-send">Enviar</button>
</form>
<div class="sc-listen" id="sc-listen"></div>

<script>
(function(){
  var CSRF=<?= json_encode(csrf_token()) ?>, SALUDO=<?= json_encode($saludo['respuesta'], JSON_UNESCAPED_UNICODE) ?>,
      MARCA=<?= (int)$marca_id ?>, GTE=<?= json_encode($gerente, JSON_UNESCAPED_UNICODE) ?>;
  var msgs=document.getElementById('sc-msgs'), form=document.getElementById('sc-form'),
      input=document.getElementById('sc-input'), send=document.getElementById('sc-send'), listen=document.getElementById('sc-listen');
  var hist=[{rol:'ia',texto:SALUDO}];
  function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}
  function scroll(el){el.scrollIntoView({behavior:'smooth',block:'end'});}

  function meBubble(t){
    var row=document.createElement('div'); row.className='sc-row me';
    row.innerHTML='<div class="sc-bubble">'+esc(t)+'</div>';
    msgs.appendChild(row); scroll(row); return row;
  }
  function iaBubble(t){
    var row=document.createElement('div'); row.className='sc-row ia';
    row.innerHTML='<div class="sc-face">'+GTE.svg+'</div><div class="sc-bubble"><div class="sc-who">'+esc(GTE.nombre)+'</div>'+esc(t)+'</div>';
    msgs.appendChild(row); scroll(row); return row;
  }
  function loadBubble(){
    var row=document.createElement('div'); row.className='sc-row ia';
    row.innerHTML='<div class="sc-face">'+GTE.svg+'</div><div class="sc-bubble load"><span class="sc-dots"><span></span><span></span><span></span></span> el corillo lo está viendo…</div>';
    msgs.appendChild(row); scroll(row); return row;
  }
  function learn(items){ if(!items||!items.length) return;
    var d=document.createElement('div');d.className='sc-learn';
    d.innerHTML='🧠 <b>El corillo aprendió:</b> '+items.map(esc).join(' · ');
    msgs.appendChild(d); scroll(d);
  }
  function cta(){
    var d=document.createElement('div');d.className='sc-cta';
    d.innerHTML='<a href="/crecer/panel/propuestas.php?marca='+MARCA+'">👀 Ver lo que dejó el corillo</a>';
    msgs.appendChild(d); scroll(d);
  }

  // ── VOZ: que el corillo lea su respuesta (se activa con el 🔈) ──
  var speakOn=false, spk=document.getElementById('sc-speak');
  spk.addEventListener('click',function(){ speakOn=!speakOn; spk.classList.toggle('on',speakOn); if(!speakOn&&window.speechSynthesis)speechSynthesis.cancel(); });
  function decir(t){ if(!speakOn||!('speechSynthesis' in window)) return;
    try{ speechSynthesis.cancel(); var u=new SpeechSynthesisUtterance(t); u.lang='es-US'; u.rate=1.03;
      var vs=speechSynthesis.getVoices(); var v=vs.filter(function(x){return /es(-|_)/i.test(x.lang);})[0]; if(v)u.voice=v;
      speechSynthesis.speak(u);
    }catch(e){}
  }

  function enviar(t){
    t=(t||'').trim(); if(!t) return;
    meBubble(t); hist.push({rol:'user',texto:t}); input.value=''; input.disabled=true; send.disabled=true;
    var load=loadBubble();
    var fd=new FormData(); fd.append('csrf',CSRF); fd.append('mensaje',t); fd.append('historial',JSON.stringify(hist));
    fetch(location.pathname+location.search,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
      load.remove(); input.disabled=false; send.disabled=false; input.focus();
      if(!d.ok){ iaBubble('No pude seguir ahora: '+(d.err||'intenta otra vez')); return; }
      learn(d.aprendido);
      iaBubble(d.respuesta); hist.push({rol:'ia',texto:d.respuesta}); decir(d.respuesta);
      if(d.accion==='campana') cta();
    }).catch(function(){ load.remove(); input.disabled=false; send.disabled=false; iaBubble('Se cayó la conexión. Intenta otra vez.'); });
  }
  form.addEventListener('submit',function(e){ e.preventDefault(); enviar(input.value); });

  // ── VOZ: dictado del dueño (Web Speech API — Chrome/Android). Habla y se manda solo. ──
  var SR=window.SpeechRecognition||window.webkitSpeechRecognition, mic=document.getElementById('sc-mic'), rec=null, grabando=false;
  if(!SR){ mic.style.display='none'; }
  else {
    rec=new SR(); rec.lang='es-US'; rec.interimResults=true; rec.continuous=false; rec.maxAlternatives=1;
    rec.onresult=function(e){ var fin='',intr='';
      for(var i=0;i<e.results.length;i++){ var t=e.results[i][0].transcript; if(e.results[i].isFinal) fin+=t; else intr+=t; }
      input.value=(fin||intr); };
    rec.onerror=function(){ parar(); };
    rec.onend=function(){ parar(); if((input.value||'').trim()) enviar(input.value); };
    function arrancar(){ try{ input.value=''; rec.start(); grabando=true; mic.classList.add('rec'); listen.textContent='Escuchando… habla y para cuando termines.'; }catch(e){} }
    function parar(){ grabando=false; mic.classList.remove('rec'); listen.textContent=''; }
    mic.addEventListener('click',function(){ if(grabando){ try{rec.stop();}catch(e){} } else { arrancar(); } });
  }
})();
</script>

<?php require __DIR__ . '/_shell_foot.php'; ?>
