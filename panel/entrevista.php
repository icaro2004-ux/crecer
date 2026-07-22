<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — La Entrevista (intake adaptativo por chat)
//  panel/entrevista.php  ·  un agente entrevista al dueño (como ChatGPT), con
//  preguntas que dependen de lo que va diciendo, hasta ENTENDER el negocio.
//  Al final: arma el perfil rico + dispara la Radiografía → todos los agentes alineados.
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/agentes.php';
require_once __DIR__ . '/../includes/iconos.php';   // ico() se usa antes de _shell
requiere_login();

$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }
$marca_id = (int)$marca['id'];

// ── AJAX: el dueño contesta → siguiente pregunta, o cierre ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_ok()) { echo json_encode(['ok'=>false,'err'=>'Sesión expiró. Recarga la página.']); exit; }
    @set_time_limit(0);
    $historial = json_decode((string)($_POST['historial'] ?? '[]'), true);
    if (!is_array($historial)) $historial = [];
    $historial = array_slice($historial, -40);   // acota el contexto
    try {
        $sig = entrevista_siguiente($pdo, $marca_id, $historial);
        if (!empty($sig['done'])) {
            $fin = entrevista_finalizar($pdo, $marca_id, $historial);
            echo json_encode(['ok'=>true, 'done'=>true, 'resumen'=>(string)($fin['descripcion'] ?? '')], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['ok'=>true, 'done'=>false, 'pregunta'=>(string)$sig['pregunta']], JSON_UNESCAPED_UNICODE);
        }
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false, 'err'=>substr($e->getMessage(), 0, 160)], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Primera pregunta (historial vacío)
try { $primera = trim((string)(entrevista_siguiente($pdo, $marca_id, [])['pregunta'] ?? '')); }
catch (Throwable $e) { $primera = ''; }
if ($primera === '') $primera = '¡Hola! Cuéntame en tus palabras: ¿qué es exactamente lo que haces o vendes?';

$active = 'marca';
$page_title = 'La Entrevista';
require __DIR__ . '/_shell.php';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<style>
  .content{max-width:720px}
  .en-top h1{font-family:'Oswald',sans-serif;font-weight:700;font-size:23px;letter-spacing:.4px;color:var(--tinta);margin:0}
  .en-top p{font-size:13px;color:var(--muted);margin:4px 0 0;line-height:1.45;max-width:560px}
  .en-thread{display:flex;flex-direction:column;gap:14px;padding:14px 0}
  .en-row{display:flex;gap:9px;max-width:92%;align-items:flex-end}
  .en-row.ia{align-self:flex-start}
  .en-row.me{align-self:flex-end;flex-direction:row-reverse}
  .en-face{width:32px;height:32px;border-radius:50%;flex:none;display:grid;place-items:center;background:var(--card,#fff);border:1.5px solid var(--line);box-shadow:var(--shadow-sm)}
  .en-face svg{width:17px;height:17px;color:var(--magenta,#EF4375)}
  .en-b{padding:11px 15px;border-radius:18px;font-size:14.5px;line-height:1.55;white-space:pre-wrap;word-wrap:break-word}
  .en-row.ia .en-b{background:var(--card,#fff);border:1px solid var(--line);color:var(--tinta);border-bottom-left-radius:6px;box-shadow:var(--shadow-sm)}
  .en-row.me .en-b{background:linear-gradient(135deg,var(--coral,#FF6B3D),var(--magenta,#EF4375));color:#fff;border-bottom-right-radius:6px}
  .en-b.load{color:var(--muted);font-style:italic}
  .en-dots span{display:inline-block;width:6px;height:6px;border-radius:50%;background:var(--muted);margin:0 1px;animation:enb 1s infinite}
  .en-dots span:nth-child(2){animation-delay:.15s}.en-dots span:nth-child(3){animation-delay:.3s}
  @keyframes enb{0%,60%,100%{opacity:.3;transform:translateY(0)}30%{opacity:1;transform:translateY(-3px)}}
  .en-done{align-self:stretch;background:color-mix(in srgb,var(--teal,#00A49F) 10%,#fff);border:1px solid color-mix(in srgb,var(--teal,#00A49F) 32%,#fff);border-radius:16px;padding:16px}
  .en-done h3{font-family:'Oswald',sans-serif;margin:0 0 8px;font-size:18px;color:var(--tinta)}
  .en-done p{font-size:13.5px;color:var(--tinta);line-height:1.5;margin:0 0 12px}
  .en-done a{display:inline-block;background:var(--tinta);color:#fff;text-decoration:none;font-weight:800;font-size:14px;padding:12px 20px;border-radius:13px}
  .en-form{display:flex;gap:9px;align-items:center;position:sticky;bottom:0;background:linear-gradient(to top,var(--crema,#F7F5F1) 74%,transparent);padding:12px 0 6px}
  .en-input{flex:1;font-family:inherit;font-size:15px;border:1.5px solid var(--line);border-radius:16px;padding:14px 16px;background:var(--card,#fff);color:var(--tinta)}
  .en-input:focus{outline:0;border-color:var(--magenta,#EF4375)}
  .en-mic{border:1.5px solid var(--line);background:var(--card,#fff);cursor:pointer;width:52px;height:52px;border-radius:16px;flex:none;display:grid;place-items:center}
  .en-mic svg{width:23px;height:23px;color:var(--tinta)}
  .en-mic.rec{background:#e0245e;border-color:transparent;animation:enpulse 1.1s infinite}
  .en-mic.rec svg{color:#fff}
  @keyframes enpulse{0%,100%{box-shadow:0 0 0 0 rgba(224,36,94,.45)}70%{box-shadow:0 0 0 12px rgba(224,36,94,0)}}
  .en-send{border:0;cursor:pointer;background:var(--btn-grad,linear-gradient(135deg,#FF6B3D,#EF4375));color:#fff;font-weight:800;height:52px;padding:0 20px;border-radius:16px;font-family:inherit;font-size:15px;flex:none}
  .en-send:disabled{opacity:.5}
  .en-listen{font-size:12px;color:var(--muted);text-align:center;min-height:16px;font-weight:600}
</style>

<div class="en-top">
  <h1>Cuéntame de tu negocio</h1>
  <p>Es una conversación, no un formulario. Te voy a hacer preguntas hasta entender bien tu negocio — y con eso el corillo hace todo mejor (los posts, el arte, todo).</p>
</div>

<div class="en-thread" id="enMsgs">
  <div class="en-row ia"><div class="en-face"><?= ico('chat') ?></div><div class="en-b"><?= $h($primera) ?></div></div>
</div>

<form class="en-form" id="enForm" autocomplete="off">
  <button type="button" class="en-mic" id="enMic" title="Hablar"><?= ico('mic') ?></button>
  <input type="text" class="en-input" id="enInput" placeholder="Escribe o toca el micrófono…" maxlength="1200">
  <button type="submit" class="en-send" id="enSend">Enviar</button>
</form>
<div class="en-listen" id="enListen"></div>

<script>
(function(){
  var CSRF=<?= json_encode(csrf_token()) ?>, MARCA=<?= (int)$marca_id ?>, FACE=<?= json_encode(ico('chat')) ?>, PRIMERA=<?= json_encode($primera, JSON_UNESCAPED_UNICODE) ?>;
  var msgs=document.getElementById('enMsgs'), form=document.getElementById('enForm'), input=document.getElementById('enInput'),
      send=document.getElementById('enSend'), listen=document.getElementById('enListen');
  var hist=[{rol:'ia',texto:PRIMERA}], cerrado=false;
  function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}
  function scroll(el){el.scrollIntoView({behavior:'smooth',block:'end'});}
  function me(t){var r=document.createElement('div');r.className='en-row me';r.innerHTML='<div class="en-b">'+esc(t)+'</div>';msgs.appendChild(r);scroll(r);}
  function ia(t){var r=document.createElement('div');r.className='en-row ia';r.innerHTML='<div class="en-face">'+FACE+'</div><div class="en-b">'+esc(t)+'</div>';msgs.appendChild(r);scroll(r);}
  function loading(){var r=document.createElement('div');r.className='en-row ia';r.innerHTML='<div class="en-face">'+FACE+'</div><div class="en-b load"><span class="en-dots"><span></span><span></span><span></span></span></div>';msgs.appendChild(r);scroll(r);return r;}
  function done(resumen){
    cerrado=true; form.style.display='none'; listen.textContent='';
    var d=document.createElement('div'); d.className='en-done';
    d.innerHTML='<h3>✓ Ya entiendo tu negocio</h3>'+(resumen?'<p>'+esc(resumen)+'</p>':'<p>Armé tu perfil y el corillo ya lo tiene.</p>')+'<a href="/crecer/panel/index.php?marca='+MARCA+'">Ver mi corillo →</a>';
    msgs.appendChild(d); scroll(d);
  }
  function enviar(t){
    t=(t||'').trim(); if(!t||cerrado) return;
    me(t); hist.push({rol:'user',texto:t}); input.value=''; input.disabled=true; send.disabled=true;
    var load=loading();
    var fd=new FormData(); fd.append('csrf',CSRF); fd.append('historial',JSON.stringify(hist));
    fetch(location.pathname+location.search,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
      load.remove();
      if(!d.ok){ ia('Perdona, se me trabó. Repíteme eso último.'); input.disabled=false; send.disabled=false; input.focus(); return; }
      if(d.done){ done(d.resumen); return; }
      ia(d.pregunta||'¿Algo más que deba saber?'); hist.push({rol:'ia',texto:d.pregunta||''});
      input.disabled=false; send.disabled=false; input.focus();
    }).catch(function(){ load.remove(); ia('Se cayó la conexión. Intenta otra vez.'); input.disabled=false; send.disabled=false; });
  }
  form.addEventListener('submit',function(e){ e.preventDefault(); enviar(input.value); });

  // Voz: dictado (Web Speech). Habla y se manda solo.
  var SR=window.SpeechRecognition||window.webkitSpeechRecognition, mic=document.getElementById('enMic'), rec=null, grabando=false;
  if(!SR){ mic.style.display='none'; }
  else {
    rec=new SR(); rec.lang='es-US'; rec.interimResults=true; rec.continuous=false;
    rec.onresult=function(e){ var f='',i2=''; for(var i=0;i<e.results.length;i++){ var t=e.results[i][0].transcript; if(e.results[i].isFinal)f+=t; else i2+=t; } input.value=(f||i2); };
    rec.onerror=function(){ parar(); }; rec.onend=function(){ parar(); if((input.value||'').trim()) enviar(input.value); };
    function arrancar(){ try{ input.value=''; rec.start(); grabando=true; mic.classList.add('rec'); listen.textContent='Escuchando… habla y para cuando termines.'; }catch(e){} }
    function parar(){ grabando=false; mic.classList.remove('rec'); listen.textContent=''; }
    mic.addEventListener('click',function(){ if(grabando){ try{rec.stop();}catch(e){} } else { arrancar(); } });
  }
})();
</script>

<?php require __DIR__ . '/_shell_foot.php'; ?>
