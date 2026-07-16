    </div><!-- /.content -->
  </div><!-- /.main -->
</div><!-- /.layout -->

<!-- Bottom nav (solo móvil) · EXACTAMENTE 4 destinos, sin FAB central · Perfil vive en el avatar del top-bar -->
<nav class="botnav botnav-4">
  <a href="<?= $BASE ?>/index.php?marca=<?= $marca_id ?>" class="<?= ($active ?? '')==='inicio'?'on':'' ?>"><?= ico('home') ?>Inicio</a>
  <a href="<?= $BASE ?>/propuestas.php?marca=<?= $marca_id ?>" class="<?= ($active ?? '')==='contenido'?'on':'' ?>"><?= ico('image') ?>Propuestas</a>
  <a href="<?= $BASE ?>/resultados.php?marca=<?= $marca_id ?>" class="<?= ($active ?? '')==='resultados'?'on':'' ?>"><?= ico('chart') ?>Resultados</a>
  <a href="<?= $BASE ?>/marca.php?marca=<?= $marca_id ?>" class="<?= ($active ?? '')==='marca'?'on':'' ?>"><?= ico('palette') ?>Mi marca</a>
</nav>

<div class="lightbox-ov" id="lightbox"><img src="" alt=""></div>

<!-- ── Asistente del corillo (helper conversacional) ─────────────
     En Inicio NO se muestra: allí la mascota (El Estratega) es el recibidor
     y dos orbes flotantes recargan la pantalla. En el resto de páginas, sí. -->
<?php if (!in_array(($active ?? ''), ['inicio','contenido','biblioteca','resultados','marca','config','soporte'], true)): ?>
<button class="asis-fab" id="asisFab" aria-label="Habla con el Copiloto" title="Habla con el Copiloto">
  <img src="/crecer/assets/brand/copiloto.png" alt="Copiloto">
</button>
<div class="asis-panel" id="asisPanel" aria-hidden="true">
  <div class="asis-head">
    <span class="asis-orb"><img src="/crecer/assets/brand/copiloto.png" alt=""></span>
    <div><div class="asis-t">Copiloto</div><div class="asis-i">Radar y ayuda del panel</div></div>
    <button class="asis-x" id="asisX" aria-label="Cerrar">✕</button>
  </div>
  <div class="asis-msgs" id="asisMsgs">
    <div class="asis-m ia">Estoy pendiente. Puedo ayudarte a usar esta pantalla, detectar qué falta y decirte el próximo paso.</div>
  </div>
  <form class="asis-form" id="asisForm">
    <input type="text" id="asisInput" placeholder="Dile qué quieres resolver…" autocomplete="off" maxlength="1000">
    <button type="submit" id="asisSend" aria-label="Enviar"><?= ico('send') ?></button>
  </form>
</div>
<style>
  .asis-fab{position:fixed;right:20px;bottom:24px;z-index:120;width:62px;height:62px;border-radius:50%;border:2px solid var(--teal,#00a49f);cursor:pointer;padding:0;overflow:hidden;
    background:#fff;box-shadow:0 12px 28px -8px rgba(0,0,0,.4);display:block}
  .asis-fab img{width:100%;height:100%;object-fit:cover;object-position:center 26%;display:block}
  .asis-orb img{width:100%;height:100%;object-fit:cover;object-position:center 26%;border-radius:50%}
  .asis-panel{position:fixed;right:20px;bottom:90px;z-index:121;width:min(380px,calc(100vw - 32px));height:min(540px,calc(100vh - 130px));
    background:#fff;border:1px solid var(--line,#eadfce);border-radius:18px;box-shadow:0 24px 60px -18px rgba(0,0,0,.45);
    display:none;flex-direction:column;overflow:hidden}
  .asis-panel.show{display:flex}
  .asis-head{display:flex;align-items:center;gap:10px;padding:13px 14px;background:var(--tinta,#1b1622);color:#fff}
  .asis-orb{width:36px;height:36px;border-radius:50%;background:#fff;overflow:hidden;display:flex;align-items:center;justify-content:center;flex:none}
  .asis-t{font-weight:800;font-size:15px;line-height:1.1}
  .asis-i{font-size:12px;color:#cfc7d6}
  .asis-x{margin-left:auto;background:0;border:0;color:#cfc7d6;font-size:16px;cursor:pointer;padding:4px}
  .asis-msgs{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px;background:var(--crema-2)}
  .asis-m{max-width:84%;padding:10px 13px;border-radius:14px;font-size:14px;line-height:1.45;white-space:pre-wrap;word-wrap:break-word}
  .asis-m.ia{background:#fff;border:1px solid var(--line,#eadfce);color:var(--tinta,#1b1622);align-self:flex-start;border-bottom-left-radius:5px}
  .asis-m.user{background:var(--terracota,#e3683f);color:#fff;align-self:flex-end;border-bottom-right-radius:5px}
  .asis-m.load{color:var(--muted,#8a7f72);font-style:italic;background:#fff;border:1px solid var(--line,#eadfce);align-self:flex-start}
  .asis-form{display:flex;gap:8px;padding:10px;border-top:1px solid var(--line,#eadfce);background:#fff}
  .asis-form input{flex:1;font-family:inherit;font-size:14px;border:1.5px solid var(--line,#eadfce);border-radius:11px;padding:10px 12px}
  .asis-form button{border:0;cursor:pointer;background:var(--palma,#16b86a);color:#fff;width:44px;border-radius:11px;display:grid;place-items:center}
  .asis-form button svg{width:18px;height:18px}
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
      var t = d.ok ? d.respuesta : (d.err||'No pude responder.');
      bubble(t,'ia'); if(d.ok) hist.push({rol:'ia',texto:d.respuesta});
    }).catch(function(){ load.remove(); bubble('Se cayó la conexión. Intenta de nuevo.','ia'); })
      .finally(function(){ busy=false; send.disabled=false; input.focus(); });
  });
})();
</script>
<?php endif; /* asis-fab oculto en Inicio */ ?>

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
  // File pickers estilizados: muestra el nombre del archivo al escoger.
  document.querySelectorAll('input.fp-in').forEach(function(f){
    f.addEventListener('change',function(){
      var lab=document.querySelector('label[for="'+f.id+'"]'); if(!lab) return;
      var tx=lab.querySelector('.fp-tx'); if(!tx) return;
      if(f.files && f.files[0]){ tx.textContent=f.files[0].name; lab.classList.add('has'); }
      else { tx.textContent=tx.getAttribute('data-default')||'Escoge un archivo'; lab.classList.remove('has'); }
    });
  });
</script>
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
