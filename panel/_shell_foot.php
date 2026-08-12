    </div><!-- /.content -->
  </div><!-- /.main -->
</div><!-- /.layout -->

<!-- Bottom nav (solo móvil) · EXACTAMENTE 4 destinos, sin FAB central · Perfil vive en el avatar del top-bar -->
<nav class="botnav botnav-crear">
  <a href="<?= $BASE ?>/index.php?marca=<?= $marca_id ?>" class="<?= ($active ?? '')==='inicio'?'on':'' ?>"><?= ico('home') ?>Inicio</a>
  <?php /* El Calendario se queda en la barra y Tu Meta NO (decisión 2026-08-12):
           la meta se pone una vez por semana — gastar 1 de 5 slots del pulgar en
           un ritual semanal es caro. El seguimiento diario ya vive en el card del
           Home (grande y tocable entero), que es por donde se entra a la meta.
           El calendario sí se consulta seguido: qué sale y cuándo. */ ?>
  <a href="<?= $BASE ?>/calendario.php?marca=<?= $marca_id ?>" class="<?= ($active ?? '')==='calendario'?'on':'' ?>"><?= ico('calendar') ?>Calendario</a>
  <a href="<?= $BASE ?>/propuestas.php?marca=<?= $marca_id ?>" class="bn-crear <?= ($active ?? '')==='contenido'?'on':'' ?>"><span class="ci"><?= ico('pen') ?></span><span class="cl">Crear</span></a>
  <a href="<?= $BASE ?>/resultados.php?marca=<?= $marca_id ?>" class="<?= ($active ?? '')==='resultados'?'on':'' ?>"><?= ico('chart') ?>Resultados</a>
  <a href="<?= $BASE ?>/sala.php?marca=<?= $marca_id ?>" class="<?= ($active ?? '')==='sala'?'on':'' ?>"><?= ico('chat') ?>Sala</a>
</nav>

<div class="lightbox-ov" id="lightbox"><img src="" alt=""></div>

<?php require_once __DIR__ . "/../includes/ayudante_widget.php"; ?>

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
