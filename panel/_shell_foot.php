    </div><!-- /.content -->
  </div><!-- /.main -->
</div><!-- /.layout -->

<!-- Bottom nav (solo móvil) · EXACTAMENTE 4 destinos, sin FAB central · Perfil vive en el avatar del top-bar -->
<nav class="botnav botnav-4">
  <a href="<?= $BASE ?>/index.php?marca=<?= $marca_id ?>" class="<?= ($active ?? '')==='inicio'?'on':'' ?>"><?= ico('home') ?>Inicio</a>
  <a href="<?= $BASE ?>/aprobar2.php?marca=<?= $marca_id ?>" class="<?= ($active ?? '')==='contenido'?'on':'' ?>"><?= ico('calendar') ?>Contenido</a>
  <a href="<?= $BASE ?>/resultados.php?marca=<?= $marca_id ?>" class="<?= ($active ?? '')==='resultados'?'on':'' ?>"><?= ico('chart') ?>Resultados</a>
  <a href="<?= $BASE ?>/marca.php?marca=<?= $marca_id ?>" class="<?= ($active ?? '')==='marca'?'on':'' ?>"><?= ico('palette') ?>Mi marca</a>
</nav>

<div class="lightbox-ov" id="lightbox"><img src="" alt=""></div>

<!-- ── Asistente del corillo (helper conversacional) ───────────── -->
<button class="asis-fab" id="asisFab" aria-label="Pregúntale al corillo" title="Pregúntale al corillo">
  <svg viewBox="0 0 24 24" width="26" height="26" fill="currentColor"><path d="M12 3C6.5 3 2 6.58 2 11c0 2.05.98 3.92 2.6 5.34-.12 1.27-.6 2.5-1.4 3.5 1.6-.2 3.1-.7 4.32-1.5 1.32.43 2.77.66 4.48.66 5.5 0 10-3.58 10-8s-4.5-8-10-8z"/></svg>
</button>
<div class="asis-panel" id="asisPanel" aria-hidden="true">
  <div class="asis-head">
    <span class="asis-orb"><?= ico('chat') ?></span>
    <div><div class="asis-t">El corillo</div><div class="asis-i">Pregúntame lo que sea de la app</div></div>
    <button class="asis-x" id="asisX" aria-label="Cerrar">✕</button>
  </div>
  <div class="asis-msgs" id="asisMsgs">
    <div class="asis-m ia">¡Wepa! 👋 Soy tu asistente. Pregúntame cómo crear un post, montar tu logo, conectar Instagram y Facebook, o lo que no entiendas.</div>
  </div>
  <form class="asis-form" id="asisForm">
    <input type="text" id="asisInput" placeholder="Escribe tu pregunta…" autocomplete="off" maxlength="1000">
    <button type="submit" id="asisSend" aria-label="Enviar">➤</button>
  </form>
</div>
<style>
  .asis-fab{position:fixed;right:20px;bottom:24px;z-index:120;width:56px;height:56px;border-radius:50%;border:0;cursor:pointer;
    background:var(--terracota,#e3683f);color:#fff;box-shadow:0 10px 26px -8px rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center}
  .asis-panel{position:fixed;right:20px;bottom:90px;z-index:121;width:min(380px,calc(100vw - 32px));height:min(540px,calc(100vh - 130px));
    background:#fff;border:1px solid var(--line,#eadfce);border-radius:18px;box-shadow:0 24px 60px -18px rgba(0,0,0,.45);
    display:none;flex-direction:column;overflow:hidden}
  .asis-panel.show{display:flex}
  .asis-head{display:flex;align-items:center;gap:10px;padding:13px 14px;background:var(--tinta,#1b1622);color:#fff}
  .asis-orb{width:34px;height:34px;border-radius:50%;background:rgba(255,255,255,.14);display:flex;align-items:center;justify-content:center;font-size:17px;flex:none}
  .asis-t{font-weight:800;font-size:15px;line-height:1.1}
  .asis-i{font-size:12px;color:#cfc7d6}
  .asis-x{margin-left:auto;background:0;border:0;color:#cfc7d6;font-size:16px;cursor:pointer;padding:4px}
  .asis-msgs{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px;background:var(--crema,#fbf6ee)}
  .asis-m{max-width:84%;padding:10px 13px;border-radius:14px;font-size:14px;line-height:1.45;white-space:pre-wrap;word-wrap:break-word}
  .asis-m.ia{background:#fff;border:1px solid var(--line,#eadfce);color:var(--tinta,#1b1622);align-self:flex-start;border-bottom-left-radius:5px}
  .asis-m.user{background:var(--terracota,#e3683f);color:#fff;align-self:flex-end;border-bottom-right-radius:5px}
  .asis-m.load{color:var(--muted,#8a7f72);font-style:italic;background:#fff;border:1px solid var(--line,#eadfce);align-self:flex-start}
  .asis-form{display:flex;gap:8px;padding:10px;border-top:1px solid var(--line,#eadfce);background:#fff}
  .asis-form input{flex:1;font-family:inherit;font-size:14px;border:1.5px solid var(--line,#eadfce);border-radius:11px;padding:10px 12px}
  .asis-form button{border:0;cursor:pointer;background:var(--palma,#16b86a);color:#fff;font-size:16px;width:44px;border-radius:11px}
  .asis-form button:disabled{opacity:.5;cursor:default}
  @media(max-width:760px){.asis-fab{bottom:78px}.asis-panel{bottom:140px}}
</style>
<script>
(function(){
  var fab=document.getElementById('asisFab'), panel=document.getElementById('asisPanel'),
      msgs=document.getElementById('asisMsgs'), form=document.getElementById('asisForm'),
      input=document.getElementById('asisInput'), send=document.getElementById('asisSend');
  if(!fab) return;
  var MARCA=<?= (int)$marca_id ?>, CSRF=<?= json_encode(csrf_token()) ?>;
  var hist=[], busy=false;

  function open(o){ panel.classList.toggle('show',o); panel.setAttribute('aria-hidden',!o); if(o) setTimeout(function(){input.focus();},50); }
  fab.addEventListener('click', function(){ open(!panel.classList.contains('show')); });
  document.getElementById('asisX').addEventListener('click', function(){ open(false); });

  function bubble(texto, clase){
    var d=document.createElement('div'); d.className='asis-m '+clase; d.textContent=texto;
    msgs.appendChild(d); msgs.scrollTop=msgs.scrollHeight; return d;
  }

  form.addEventListener('submit', function(e){
    e.preventDefault();
    if(busy) return;
    var q=input.value.trim(); if(!q) return;
    input.value=''; bubble(q,'user'); hist.push({rol:'user',texto:q});
    busy=true; send.disabled=true;
    var load=bubble('escribiendo…','load');
    fetch('/crecer/panel/asistente.php',{
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({csrf:CSRF, marca_id:MARCA, pregunta:q, historial:hist})
    }).then(function(r){return r.json();}).then(function(d){
      load.remove();
      var t = d.ok ? d.respuesta : ('⚠️ '+(d.err||'No pude responder.'));
      bubble(t,'ia'); if(d.ok) hist.push({rol:'ia',texto:d.respuesta});
    }).catch(function(){ load.remove(); bubble('⚠️ Error de conexión. Intenta de nuevo.','ia'); })
      .finally(function(){ busy=false; send.disabled=false; input.focus(); });
  });
})();
</script>

<?php if (!empty($guia)): ?>
<!-- Guía del corillo (overlay flotante, una vez por página + botón "?") -->
<button class="guia-btn" id="guiaBtn" aria-label="Guía de esta página"><?= ico('lightbulb') ?></button>
<div class="guia-ov" id="guiaOv" data-key="<?= $h($guia['key']) ?>">
  <div class="guia-card">
    <button class="guia-x" onclick="cerrarGuia()" aria-label="Cerrar">✕</button>
    <div class="guia-h">
      <span class="guia-orb"><?= ico($guia['agente'] ?? 'sparkles') ?></span>
      <div><div class="guia-t"><?= $h($guia['titulo']) ?></div><div class="guia-i"><?= $h($guia['intro']) ?></div></div>
    </div>
    <ol class="guia-pasos">
      <?php foreach (($guia['pasos'] ?? []) as $i=>$ps): ?>
        <li><span class="gn"><?= $i+1 ?></span><?= ico($ps[0]) ?><span><?= $h($ps[1]) ?></span></li>
      <?php endforeach; ?>
    </ol>
    <div class="guia-foot">
      <button class="guia-ok" onclick="cerrarGuia()">¡Entendido!</button>
      <label class="guia-no"><input type="checkbox" id="guiaNo"> No mostrar de nuevo</label>
    </div>
  </div>
</div>
<script>
  (function(){
    var ov=document.getElementById('guiaOv'); if(!ov) return;
    var key='guia_'+ov.dataset.key;
    window.cerrarGuia=function(){ ov.classList.remove('show');
      var no=document.getElementById('guiaNo'); if(no&&no.checked) localStorage.setItem(key,'1'); };
    if(!localStorage.getItem(key)) setTimeout(function(){ov.classList.add('show');},650);
    document.getElementById('guiaBtn').addEventListener('click',function(){ov.classList.add('show');});
    ov.addEventListener('click',function(e){ if(e.target===ov) cerrarGuia(); });
  })();
</script>
<?php endif; ?>

<script>
  var side=document.getElementById('side'),bd=document.getElementById('bd'),bg=document.getElementById('burger');
  function _open(o){side.classList.toggle('open',o);bd.classList.toggle('show',o);}
  if(bg)bg.addEventListener('click',function(){_open(true);});
  if(bd)bd.addEventListener('click',function(){_open(false);});
  // Lightbox: tocar cualquier imagen .zoomable la agranda
  var _lb=document.getElementById('lightbox');
  document.addEventListener('click',function(e){
    if(e.target.tagName==='IMG' && e.target.classList.contains('zoomable')){
      _lb.querySelector('img').src=e.target.src; _lb.classList.add('show');
    } else if(e.target===_lb || e.target.parentNode===_lb){ _lb.classList.remove('show'); }
  });
</script>
</body>
</html>
