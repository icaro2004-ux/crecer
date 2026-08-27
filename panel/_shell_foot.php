    </div><!-- /.content -->
  </div><!-- /.main -->
</div><!-- /.layout -->

<!-- Bottom nav (solo móvil) · EXACTAMENTE 4 destinos, sin FAB central · Perfil vive en el avatar del top-bar -->
<nav class="botnav botnav-crear">
  <a href="<?= $BASE ?>/index.php?marca=<?= $marca_id ?>" class="<?= ($active ?? '')==='inicio'?'on':'' ?>"><?= ico('home') ?><?= $h(t('Inicio')) ?></a>
  <a href="<?= $BASE ?>/calendario.php?marca=<?= $marca_id ?>" class="<?= ($active ?? '')==='calendario'?'on':'' ?>"><?= ico('calendar') ?><?= $h(t('Calendario')) ?></a>
  <?php
    /*  EL SITIO DEL PULGAR ES PARA TU META, NO PARA CREAR.
     *
     *  La decisión de 2026-08-12 fue la contraria —«la meta se pone una vez
     *  por semana, gastar un slot en un ritual semanal es caro»— y era
     *  razonable ENTONCES: la meta se ponía y ya. Ya no. Con la revisión
     *  semanal, el material y la comparación de imágenes, Tu Meta es donde
     *  el dueño decide TODOS los días. Crear, en cambio, es una herramienta
     *  que se usa a ratos.
     *
     *  Crear NO se elimina: baja al menú lateral, con su URL, su flag y su
     *  marca intactos. Y Tu Meta no se duplica — la entrada del lateral
     *  lleva `.dup`, que la esconde solo en móvil.
     */
    $meta_url_bn = $BASE . '/meta.php?marca=' . $marca_id;
    //  ACTIVA EN TODO EL RECORRIDO, no solo en la portada: semana,
    //  preparación, ajuste y sustitución son Tu Meta. Que se apague al
    //  entrar en la semana le diría al dueño que se ha ido de donde está.
    $meta_on_bn = in_array(($active ?? ''), ['meta', 'semana', 'preparacion',
                                             'ajustar', 'sustituir'], true)
                  || strpos($_SERVER['SCRIPT_NAME'] ?? '', 'meta.php') !== false;
  ?>
  <a href="<?= $h($meta_url_bn) ?>" class="bn-crear <?= $meta_on_bn ? 'on' : '' ?>"><span class="ci"><?= ico('compass') ?></span><span class="cl"><?= $h(t('Tu Meta')) ?></span></a>
  <a href="<?= $BASE ?>/resultados.php?marca=<?= $marca_id ?>" class="<?= ($active ?? '')==='resultados'?'on':'' ?>"><?= ico('chart') ?><?= $h(t('Resultados')) ?></a>
  <a href="<?= $BASE ?>/sala.php?marca=<?= $marca_id ?>" class="<?= ($active ?? '')==='sala'?'on':'' ?>"><?= ico('chat') ?><?= $h(t('Sala')) ?></a>
</nav>

<?php /*  EL VISOR DE IMAGEN · uno solo para todo el producto.
          Es estrictamente DE LECTURA: no guarda, no aprueba, no publica, no
          decide y no llama a nadie. Solo enseña. */ ?>
<div class="lightbox-ov" id="lightbox" role="dialog" aria-modal="true"
     aria-label="<?= $h(t('Ver imagen completa')) ?>">
  <button type="button" class="lightbox-x" id="lightboxX"
          aria-label="<?= $h(t('Cerrar')) ?>"><?= ico('x') ?></button>
  <img src="" alt="">
</div>

<?php require_once __DIR__ . "/../includes/ayudante_widget.php"; ?>

<?php if (!empty($guia)): ?>
<!-- Guía del corillo (overlay flotante, una vez por página + botón "?") -->
<button class="guia-btn" id="guiaBtn" aria-label="<?= $h(t('Guía de esta página')) ?>"><?= ico('lightbulb') ?></button>
<div class="guia-ov" id="guiaOv" data-key="<?= $h($guia['key']) ?>">
  <div class="guia-card">
    <button class="guia-x" onclick="cerrarGuia()" aria-label="<?= $h(t('Cerrar')) ?>">✕</button>
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
      <button class="guia-ok" onclick="cerrarGuia()"><?= $h(t('¡Entendido!')) ?></button>
      <label class="guia-no"><input type="checkbox" id="guiaNo"> <?= $h(t('No mostrar de nuevo')) ?></label>
    </div>
  </div>
</div>
<script>
  (function(){
    var ov=document.getElementById('guiaOv'); if(!ov) return;
    var key='guia_'+ov.dataset.key;
    window.cerrarGuia=function(){ ov.classList.remove('show');
      var no=document.getElementById('guiaNo'); if(no&&no.checked) localStorage.setItem(key,'1'); };
    // UNA SOLA BIENVENIDA A LA VEZ. El Recibimiento (#trOv, el spotlight que
    // señala elementos reales) y esta guía se disparaban los dos en la primera
    // visita y salían uno encima del otro. Si el Recibimiento va a correr, la
    // guía cede: se queda esperando en su botón "?" para cuando la pidan.
    var hayTour = !!document.getElementById('trOv');
    if(!hayTour && !localStorage.getItem(key)) setTimeout(function(){ov.classList.add('show');},650);
    document.getElementById('guiaBtn').addEventListener('click',function(){ov.classList.add('show');});
    ov.addEventListener('click',function(e){ if(e.target===ov) cerrarGuia(); });
  })();
</script>
<?php endif; ?>

<script>
  //  EL JAVASCRIPT NO TRADUCE: RECIBE.
  //  Hoy hay 609 cadenas dentro de <script> en 31 archivos, y ninguna se
  //  traduce nunca — el filtro de salida salta <script> a propósito, porque
  //  traducir código lo rompe. La respuesta no es un segundo diccionario en el
  //  navegador ni reemplazo de texto en el DOM: es que el JS deje de tener
  //  texto. El PHP traduce y lo entrega ya hecho.
  //  Object.assign para que cada pantalla pueda añadir lo suyo sin pisarse.
  window.T = Object.assign(window.T || {}, <?= tj(['escoge_archivo' => 'Escoge un archivo']) ?>);

  // File pickers estilizados: muestra el nombre del archivo al escoger.
  document.querySelectorAll('input.fp-in').forEach(function(f){
    f.addEventListener('change',function(){
      var lab=document.querySelector('label[for="'+f.id+'"]'); if(!lab) return;
      var tx=lab.querySelector('.fp-tx'); if(!tx) return;
      if(f.files && f.files[0]){ tx.textContent=f.files[0].name; lab.classList.add('has'); }
      else { tx.textContent=tx.getAttribute('data-default')||T.escoge_archivo; lab.classList.remove('has'); }
    });
  });
</script>
<script>
  var side=document.getElementById('side'),bd=document.getElementById('bd'),bg=document.getElementById('burger');
  function _open(o){side.classList.toggle('open',o);bd.classList.toggle('show',o);}
  if(bg)bg.addEventListener('click',function(){_open(true);});
  if(bd)bd.addEventListener('click',function(){_open(false);});
  //  ── VER LA IMAGEN COMPLETA ──────────────────────────────────────────
  //  El dueño tiene que decidir sobre lo que ve, y hasta ahora veía media
  //  imagen: el menú, Ayuda y los botones quedaban delante. Aquí se abre
  //  encima de todo, se ve entera, y se sale por donde uno espera salir.
  //
  //  DE LECTURA Y NADA MÁS: no guarda, no aprueba, no publica, no decide.
  var _lb=document.getElementById('lightbox');
  var _lbX=document.getElementById('lightboxX');
  var _lbVolver=null;   // a dónde devolver el foco al cerrar

  function _lbAbrir(src, origen){
    if(!_lb||!src) return;
    _lbVolver = origen || null;
    _lb.querySelector('img').src = src;
    _lb.classList.add('show');
    //  Lo de debajo no se desplaza mientras esto está abierto: si no, el
    //  dedo mueve la página detrás y al cerrar se ha perdido el sitio.
    document.body.classList.add('lb-abierto');
    if(_lbX) _lbX.focus();
  }
  function _lbCerrar(){
    if(!_lb||!_lb.classList.contains('show')) return;
    _lb.classList.remove('show');
    document.body.classList.remove('lb-abierto');
    //  EL FOCO VUELVE A LA IMAGEN QUE LO ABRIÓ. Sin esto, quien navega con
    //  teclado acaba al principio de la página y pierde dónde estaba.
    if(_lbVolver && _lbVolver.isConnected){ try{ _lbVolver.focus(); }catch(e){} }
    _lbVolver = null;
  }
  window.verImagenCompleta = _lbAbrir;   // lo usan las pantallas

  document.addEventListener('click',function(e){
    //  TODA LA SUPERFICIE DE LA IMAGEN ES EL CONTROL: vale la propia imagen
    //  o cualquier envoltorio marcado, para que el dedo no tenga que
    //  acertarle a un botón pequeño.
    var z = e.target.closest ? e.target.closest('[data-zoom]') : null;
    if(z){
      var im = z.matches('img') ? z : z.querySelector('img');
      var src = z.getAttribute('data-zoom') || (im ? im.src : '');
      if(src){ e.preventDefault(); e.stopPropagation(); _lbAbrir(src, z); return; }
    }
    if(e.target.tagName==='IMG' && e.target.classList.contains('zoomable')){
      e.preventDefault(); _lbAbrir(e.target.src, e.target); return;
    }
    //  Tocar el fondo cierra. La imagen no: ahí se está mirando.
    if(e.target===_lb || e.target===_lbX || (_lbX && _lbX.contains(e.target))) _lbCerrar();
  });
  //  Escape cierra SOLO el visor, y se para ahí: si no, cerraría también la
  //  hoja de debajo y el dueño perdería la comparación que estaba haciendo.
  document.addEventListener('keydown',function(e){
    if(e.key==='Escape' && _lb && _lb.classList.contains('show')){
      e.stopPropagation(); _lbCerrar();
    }
  }, true);
</script>
</body>
</html>
