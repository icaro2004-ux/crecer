<?php
// ============================================================
//  CRECER — EL AYUDANTE · widget flotante  ·  includes/ayudante_widget.php
//
//  El helper que vive en TODO el app. Se incluye desde panel/_shell_foot.php
//  (todas las pantallas del panel) y se puede poner en CUALQUIER otra página
//  del site con UNA línea, siempre que exista una marca en contexto:
//
//      require_once __DIR__ . '/../includes/ayudante_widget.php';
//
//  Requisitos: sesión iniciada + $marca_id (o $marca['id']). Si no hay marca,
//  no pinta nada (en vez de romper la página).
//  Backend: panel/ayudante.php  ·  Motor: includes/ayudante.php
// ============================================================
require_once __DIR__ . '/iconos.php';
$ay_marca_id = (int)($marca_id ?? ($marca['id'] ?? 0));
if ($ay_marca_id <= 0 || !function_exists('csrf_token') || empty($_SESSION['usuario_id'])) return;
?>
<!-- ══ EL AYUDANTE ══ Helper vivo en TODO el app. No contesta y ya: revisa la
     cuenta, arregla lo que puede (arte trabado, publicación caída, carpeta de
     fotos), y lo que no puede lo levanta como caso y le avisa al equipo por
     email y texto. Backend: panel/ayudante.php · Motor: includes/ayudante.php
     Desktop = panel lateral (contexto al lado del trabajo).
     Móvil    = hoja que sube (una mano, pulgar, acción primaria arriba). -->
<button class="ay-fab" id="ayFab" aria-label="<?= $h(t('Ayuda')) ?>">
  <?= ico('sparkles') ?><span><?= $h(t('Ayuda')) ?></span>
</button>
<div class="ay-bd" id="ayBd"></div>
<aside class="ay-panel" id="ayPanel" aria-hidden="true">
  <header class="ay-head">
    <span class="ay-orb"><?= ico('sparkles') ?></span>
    <div class="ay-hx">
      <b><?= $h(t('Ayuda')) ?></b>
      <span><?= $h(t('Reviso, arreglo, y si no puedo lo reporto')) ?></span>
    </div>
    <button class="ay-x" id="ayX" aria-label="<?= $h(t('Cerrar')) ?>"><?= ico('x') ?></button>
  </header>

  <div class="ay-body" id="ayBody">
    <div class="ay-msg ia" id="ayHola">
      <?= $h(t('Dime qué está pasando y lo reviso. Si algo se trabó, lo arreglo yo mismo.')) ?>
    </div>
  </div>

  <div class="ay-quick">
    <button class="ay-q primaria" id="ayRevisar"><?= ico('refresh') ?> <?= $h(t('Revisar y arreglar')) ?></button>
    <button class="ay-q" id="ayReportar"><?= ico('bell') ?> <?= $h(t('Reportar')) ?></button>
  </div>

  <form class="ay-form" id="ayForm">
    <input type="text" id="ayIn" placeholder="<?= $h(t('No me sube la foto…')) ?>" autocomplete="off" maxlength="1200">
    <button type="submit" id="aySend" aria-label="<?= $h(t('Enviar')) ?>"><?= ico('send') ?></button>
  </form>
</aside>

<style>
  /* ── El botón: presencia constante, nunca en el medio ── */
  .ay-fab{position:fixed;right:22px;bottom:24px;z-index:118;display:inline-flex;align-items:center;gap:8px;
    border:0;cursor:pointer;font-family:var(--font-body);font-weight:700;font-size:14px;color:#fff;
    padding:13px 20px 13px 17px;border-radius:99px;background:var(--btn-grad);box-shadow:var(--btn-glow),0 10px 26px rgba(27,22,34,.18);
    transition:transform .18s var(--ease),box-shadow .18s var(--ease)}
  .ay-fab svg{width:19px;height:19px}
  /* CON UN MODAL ABIERTO, Ayuda se quita de en medio. Vive en z-index 118 y
     tapaba controles y contenido de la vista previa. Quien abra un modal
     pone body.modal-abierto y lo quita al cerrar. */
  body.modal-abierto .ay-fab{display:none !important}
  /*  AYUDA POR ENCIMA DEL DOCK. Estaba a 24px del borde: justo dentro de la
      barra de abajo, tapando un destino y compitiendo con la navegación. Se
      sube por encima de su zona segura —alto del dock más el margen del
      teléfono— y ahí se queda. Su función no cambia: solo deja de estorbar. */
  @media (max-width:860px){
    .ay-fab{bottom:calc(78px + env(safe-area-inset-bottom));right:16px}
  }
  .ay-fab:hover{transform:translateY(-2px) scale(1.02)}
  .ay-fab:active{transform:translateY(1px) scale(.99)}
  .ay-fab.abierto{transform:scale(.9);opacity:0}
  .ay-bd{position:fixed;inset:0;z-index:119;background:rgba(27,22,34,.42);backdrop-filter:blur(6px);
    opacity:0;pointer-events:none;transition:opacity .22s var(--ease)}
  .ay-bd.show{opacity:1;pointer-events:auto}

  /* ── DESKTOP: panel lateral. El dueño sigue viendo su pantalla al lado ── */
  .ay-panel{position:fixed;z-index:120;right:22px;bottom:24px;width:396px;max-height:min(76vh,660px);
    display:flex;flex-direction:column;background:var(--card);border:1px solid var(--line);
    border-radius:22px;box-shadow:0 30px 70px rgba(27,22,34,.28);overflow:hidden;
    opacity:0;transform:translateY(14px) scale(.98);pointer-events:none;
    transition:opacity .22s var(--ease),transform .26s var(--ease)}
  .ay-panel.show{opacity:1;transform:none;pointer-events:auto}
  .ay-head{display:flex;align-items:center;gap:11px;padding:15px 16px;background:var(--tinta);color:#fff;flex:none}
  .ay-orb{width:38px;height:38px;border-radius:50%;flex:none;display:grid;place-items:center;color:#fff;background:var(--btn-grad)}
  .ay-orb svg{width:20px;height:20px}
  .ay-hx{min-width:0}
  .ay-hx b{display:block;font-family:var(--font-display);font-weight:600;font-size:15.5px;letter-spacing:-.01em}
  .ay-hx span{display:block;font-size:11.5px;color:#cfc7d6;margin-top:1px}
  .ay-x{margin-left:auto;border:0;background:rgba(255,255,255,.1);color:#fff;width:32px;height:32px;border-radius:50%;
    display:grid;place-items:center;cursor:pointer;flex:none;transition:background .15s}
  .ay-x:hover{background:rgba(255,255,255,.2)}
  .ay-x svg{width:16px;height:16px}

  .ay-body{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:10px;background:var(--crema);
    -webkit-overflow-scrolling:touch}
  .ay-msg{max-width:88%;padding:11px 14px;border-radius:16px;font-size:14px;line-height:1.5;
    white-space:pre-wrap;word-wrap:break-word;font-family:var(--font-body);animation:ayIn .22s var(--ease)}
  @keyframes ayIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
  .ay-msg.ia{align-self:flex-start;background:#fff;border:1px solid var(--line);color:var(--tinta);border-bottom-left-radius:5px}
  .ay-msg.yo{align-self:flex-end;background:var(--btn-grad);color:#fff;border-bottom-right-radius:5px}
  .ay-msg.arreglado{align-self:flex-start;background:#e9f8f0;border:1px solid #bfe8d4;color:#0d5c3c;border-bottom-left-radius:5px}
  .ay-msg.caso{align-self:flex-start;background:#fff6e6;border:1px solid #f2ddb4;color:#7a4d05;border-bottom-left-radius:5px}
  .ay-msg a{color:inherit;font-weight:700}
  .ay-typing{align-self:flex-start;display:flex;gap:4px;padding:13px 15px;background:#fff;border:1px solid var(--line);border-radius:16px}
  .ay-typing i{width:6px;height:6px;border-radius:50%;background:var(--muted);animation:ayDot 1.05s infinite}
  .ay-typing i:nth-child(2){animation-delay:.15s}.ay-typing i:nth-child(3){animation-delay:.3s}
  @keyframes ayDot{0%,60%,100%{opacity:.25;transform:translateY(0)}30%{opacity:1;transform:translateY(-3px)}}

  .ay-quick{display:flex;gap:8px;padding:10px 12px 0;background:var(--card);flex:none}
  .ay-q{flex:1;display:inline-flex;align-items:center;justify-content:center;gap:6px;cursor:pointer;
    font-family:var(--font-body);font-weight:700;font-size:12.5px;padding:10px 12px;border-radius:11px;
    border:1.5px solid var(--line);background:#fff;color:var(--tinta);transition:transform .15s var(--ease),border-color .15s}
  .ay-q svg{width:15px;height:15px}
  .ay-q:hover{border-color:color-mix(in srgb,var(--magenta) 40%,var(--line))}
  .ay-q:active{transform:translateY(1px)}
  .ay-q.primaria{background:color-mix(in srgb,var(--teal-700,#00A49F) 10%,#fff);border-color:color-mix(in srgb,var(--teal-700,#00A49F) 30%,var(--line));color:var(--teal-700,#00A49F)}
  .ay-q:disabled{opacity:.55;cursor:default}

  .ay-form{display:flex;gap:8px;padding:12px;background:var(--card);flex:none}
  .ay-form input{flex:1;min-width:0;font-family:var(--font-body);font-size:14.5px;border:1.5px solid var(--line);
    border-radius:12px;padding:12px 14px;transition:border-color .15s}
  .ay-form input:focus{outline:none;border-color:color-mix(in srgb,var(--magenta) 45%,var(--line))}
  .ay-form button{border:0;cursor:pointer;background:var(--btn-grad);box-shadow:var(--btn-glow);color:#fff;width:46px;
    border-radius:12px;display:grid;place-items:center;flex:none;transition:transform .15s var(--ease)}
  .ay-form button:active{transform:translateY(1px)}
  .ay-form button svg{width:18px;height:18px}
  .ay-form button:disabled{opacity:.5}

  /* ── MÓVIL: hoja que sube. Pulgar arriba del bottom-nav, chat a pantalla casi completa ── */
  @media(max-width:860px){
    .ay-fab{right:16px;bottom:calc(78px + env(safe-area-inset-bottom));padding:12px 17px 12px 14px;font-size:13.5px}
    .ay-panel{right:0;left:0;bottom:0;width:auto;max-height:none;height:88dvh;border-radius:22px 22px 0 0;
      border-left:0;border-right:0;border-bottom:0;transform:translateY(100%) scale(1)}
    .ay-panel.show{transform:none}
    .ay-head{padding:16px 16px 14px}
    .ay-msg{max-width:90%;font-size:14.5px}
    .ay-form{padding:12px 12px calc(12px + env(safe-area-inset-bottom))}
  }
</style>

<script>
//  EL JAVASCRIPT NO TRADUCE: RECIBE.
//  Todo lo que el Ayudante dice cuando algo va mal —o cuando no va mal— lo
//  escribe este guion. Nada de esto se ve en un barrido de la pantalla: sale
//  solo al pulsar, al fallar o al recibir respuesta. El PHP lo traduce y lo
//  entrega hecho.
window.T = Object.assign(window.T || {}, <?= tj([
  'ay_no_reviso'   => 'No pude revisar ahora mismo.',
  'ay_todo_bien'   => 'Le di un vistazo a tu cuenta: todo corriendo, nada trabado.',
  'ay_caso_abierto'=> 'abierto. El equipo ya recibió el aviso con la explicación.',
  'ay_sin_red'     => 'Se cayó la conexión al revisar. Intenta otra vez.',
  'ay_dime'        => 'Escríbeme en una línea qué pasó y lo reporto al equipo.',
  'ay_no_reporto'  => 'No pude reportarlo.',
  'ay_no_reporto2' => 'No pude reportarlo ahora. Intenta otra vez.',
  'ay_no_contesto' => 'No pude contestarte ahora.',
  'ay_cayo'        => 'Se cayó la conexión. Intenta otra vez.',
]) ?>);
(function(){
  var fab=document.getElementById('ayFab'), pan=document.getElementById('ayPanel'), bd=document.getElementById('ayBd'),
      body=document.getElementById('ayBody'), form=document.getElementById('ayForm'), input=document.getElementById('ayIn'),
      send=document.getElementById('aySend'), bRev=document.getElementById('ayRevisar'), bRep=document.getElementById('ayReportar');
  if(!fab||!pan) return;
  var CSRF=<?= json_encode(csrf_token()) ?>, MARCA=<?= (int)$ay_marca_id ?>, hist=[], revisado=false, ocupado=false;

  function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}
  function msg(t,clase){
    var d=document.createElement('div'); d.className='ay-msg '+(clase||'ia'); d.innerHTML=esc(t);
    body.appendChild(d); body.scrollTop=body.scrollHeight; return d;
  }
  function pensando(){
    var d=document.createElement('div'); d.className='ay-typing'; d.innerHTML='<i></i><i></i><i></i>';
    body.appendChild(d); body.scrollTop=body.scrollHeight; return d;
  }
  function pedir(payload){
    payload.csrf=CSRF; payload.marca_id=MARCA;
    return fetch('/crecer/panel/ayudante.php',{method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify(payload)}).then(function(r){return r.json();});
  }
  function bloquear(b){ ocupado=b; send.disabled=b; bRev.disabled=b; bRep.disabled=b; }

  function abrir(){
    pan.classList.add('show'); bd.classList.add('show'); fab.classList.add('abierto');
    pan.setAttribute('aria-hidden','false');
    if(window.innerWidth>860) setTimeout(function(){input.focus();},260);
    if(!revisado){ revisado=true; revisar(true); }
  }
  function cerrar(){
    pan.classList.remove('show'); bd.classList.remove('show'); fab.classList.remove('abierto');
    pan.setAttribute('aria-hidden','true');
  }
  fab.addEventListener('click',abrir);
  bd.addEventListener('click',cerrar);
  document.getElementById('ayX').addEventListener('click',cerrar);
  document.addEventListener('keydown',function(e){ if(e.key==='Escape'&&pan.classList.contains('show')) cerrar(); });

  // Revisar y arreglar: el ciclo entero (escanear → reparar → escalar).
  function revisar(silencioso){
    if(ocupado) return; bloquear(true);
    var t=pensando();
    pedir({accion:'revisar'}).then(function(d){
      t.remove(); bloquear(false);
      if(!d.ok){ msg(d.err||T.ay_no_reviso); return; }
      var nada=(!d.hallazgos||!d.hallazgos.length);
      if(nada && silencioso){ msg(T.ay_todo_bien); return; }
      msg(d.texto, nada?'ia':'arreglado');
      (d.escalados||[]).forEach(function(e){
        if(e.id) msg('Caso #'+e.id+' '+T.ay_caso_abierto,'caso');
      });
      (d.arreglados||[]).forEach(function(a){
        if(a.requiere_dueno && a.link){
          var m=msg('','ia');
          m.innerHTML=esc(a.msg)+'<br><a href="'+a.link+'">Ir a arreglarlo &rsaquo;</a>';
        }
      });
    }).catch(function(){ t.remove(); bloquear(false); msg(T.ay_sin_red); });
  }
  bRev.addEventListener('click',function(){ revisar(false); });

  // Reportar: la queja queda escrita y el equipo recibe email + texto.
  bRep.addEventListener('click',function(){
    var t=(input.value||'').trim();
    if(!t){ msg(T.ay_dime); input.focus(); return; }
    input.value=''; msg(t,'yo'); bloquear(true);
    var p=pensando();
    pedir({accion:'reportar',texto:t}).then(function(d){
      p.remove(); bloquear(false);
      msg(d.msg||d.err||T.ay_no_reporto, d.ok?'caso':'ia');
    }).catch(function(){ p.remove(); bloquear(false); msg(T.ay_no_reporto2); });
  });

  // Conversar: el Ayudante puede responder, arreglar o levantar el caso.
  form.addEventListener('submit',function(e){
    e.preventDefault();
    var t=(input.value||'').trim(); if(!t||ocupado) return;
    input.value=''; msg(t,'yo'); hist.push({rol:'dueno',texto:t}); bloquear(true);
    var p=pensando();
    pedir({accion:'chat',pregunta:t,historial:hist.slice(-6)}).then(function(d){
      p.remove(); bloquear(false);
      if(!d.ok){ msg(d.err||T.ay_no_contesto); return; }
      var clase = d.caso ? 'caso' : (d.accion==='arreglar' ? 'arreglado' : 'ia');
      msg(d.respuesta, clase);
      hist.push({rol:'ia',texto:d.respuesta});
    }).catch(function(){ p.remove(); bloquear(false); msg(T.ay_cayo); });
  });
})();
</script>
