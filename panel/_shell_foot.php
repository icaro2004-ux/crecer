    </div><!-- /.content -->
  </div><!-- /.main -->
</div><!-- /.layout -->

<?php
/*  EL DOCK · cuatro destinos, y el que estás mirando en el centro.
 *
 *  QUIÉN ESTÁ ACTIVO LO DICE LA RUTA, no una variable que cada página pone
 *  como puede. `$active` se sigue mirando —hay vistas internas que lo ajustan
 *  y aciertan— pero manda el archivo que se está ejecutando: si el fichero es
 *  `meta.php`, el dueño está en Tu Meta, diga lo que diga cualquier otra cosa.
 *
 *  Y EL ORDEN DEL DOM NO SE TOCA. Siempre Inicio · Calendario · Tu Meta ·
 *  Resultados, en ese orden, para que el tabulador y el lector de pantalla
 *  encuentren siempre lo mismo. Al centro se llega pintando, no reordenando
 *  nodos.
 */
$dk_arch = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$dk_por_ruta = [
    'index.php'      => 'inicio',
    'calendario.php' => 'calendario',
    'meta.php'       => 'meta',
    'resultados.php' => 'resultados',
];
//  Las vistas internas que SON esa sección aunque vivan en otro archivo.
$dk_por_activo = [
    'inicio'     => 'inicio',
    'calendario' => 'calendario',
    'meta'       => 'meta',   'semana'    => 'meta',  'preparacion' => 'meta',
    'ajustar'    => 'meta',   'sustituir' => 'meta',  'cerrar'      => 'meta',
    'resultados' => 'resultados',
];
$dk_act = $dk_por_ruta[$dk_arch] ?? ($dk_por_activo[(string)($active ?? '')] ?? '');

$dk_items = [
    ['k' => 'inicio',     'ic' => 'home',     'lb' => t('Inicio'),     'hr' => "{$BASE}/index.php?marca={$marca_id}"],
    ['k' => 'calendario', 'ic' => 'calendar', 'lb' => t('Calendario'), 'hr' => "{$BASE}/calendario.php?marca={$marca_id}"],
    ['k' => 'meta',       'ic' => 'compass',  'lb' => t('Tu Meta'),    'hr' => "{$BASE}/meta.php?marca={$marca_id}"],
    ['k' => 'resultados', 'ic' => 'chart',    'lb' => t('Resultados'), 'hr' => "{$BASE}/resultados.php?marca={$marca_id}"],
];
?>
<nav class="botnav dock" id="dock" aria-label="<?= $h(t('Navegación principal')) ?>">
  <?php foreach ($dk_items as $d): $on = ($d['k'] === $dk_act); ?>
    <a href="<?= $h($d['hr']) ?>" data-k="<?= $h($d['k']) ?>"
       class="dk-i<?= $on ? ' act' : '' ?>"
       <?= $on ? 'aria-current="page"' : '' ?>>
      <span class="dk-b"><?= ico($d['ic']) ?></span>
      <span class="dk-l"><?= $h($d['lb']) ?></span>
    </a>
  <?php endforeach; ?>
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
<script>
/*  EL DOCK, MEDIDO ─────────────────────────────────────────────────────────
 *
 *  La geometría se MIDE, no se adivina: con cuatro destinos y el activo en el
 *  centro exacto, no hay reparto simétrico posible —haría falta uno y medio a
 *  cada lado— así que se calcula con los anchos reales y se coloca cada uno
 *  donde de verdad cabe, sin solaparse.
 *
 *  EL ARREGLO ES CIRCULAR: el activo al centro, el que va antes a su
 *  izquierda y los dos siguientes a su derecha, dando la vuelta. Así el orden
 *  relativo se conserva mires donde mires, y ningún lado se queda vacío
 *  cuando el activo es el primero o el último.
 *
 *  SI ESTO NO CORRE, NO PASA NADA: sin `medido` el dock es un flex de cuatro
 *  columnas con el activo igual de prominente, y los enlaces navegan solos.
 */
(function () {
  var dock = document.getElementById('dock');
  if (!dock) return;
  var items = [].slice.call(dock.querySelectorAll('.dk-i'));
  if (items.length !== 4) return;

  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  var fino   = window.matchMedia && matchMedia('(hover: hover) and (pointer: fine)').matches;

  function colocar() {
    //  El dock solo existe en móvil; por encima manda el menú lateral.
    if (getComputedStyle(dock).display === 'none') { dock.classList.remove('medido'); return; }

    var act = items.findIndex(function (a) { return a.classList.contains('act'); });
    if (act < 0) { dock.classList.remove('medido'); return; }

    //  SE MIDE EN `medido`, NO ANTES. Fuera de ahí los cuatro son `flex:1` y
    //  todos miden lo mismo —el ancho de una columna, no el suyo—, y con esa
    //  cifra inflada el reparto salía mal: los dos de la derecha se pisaban
    //  por unos pocos píxeles. En `medido` cada uno mide lo que de verdad
    //  ocupa su rótulo.
    dock.classList.add('medido');
    var W  = dock.clientWidth;
    var cs = getComputedStyle(dock);
    var padL = parseFloat(cs.paddingLeft) || 0, padR = parseFloat(cs.paddingRight) || 0;
    var anchos = items.map(function (a) { return a.getBoundingClientRect().width; });

    var centro = W / 2;
    var mitadAct = anchos[act] / 2;
    var GAP = 4;   //  aire mínimo entre el activo y su vecino

    //  Orden circular: [anterior, ACTIVO, siguiente, siguiente+1].
    var izq = [(act + 3) % 4];
    var der = [(act + 1) % 4, (act + 2) % 4];

    var x = [];
    x[act] = centro;

    //  IZQUIERDA: un solo destino, centrado en lo que queda.
    var iniI = padL, finI = centro - mitadAct - GAP;
    x[izq[0]] = Math.max(iniI + anchos[izq[0]] / 2,
                         Math.min(finI - anchos[izq[0]] / 2, (iniI + finI) / 2));

    //  DERECHA: dos, repartidos por igual en su tramo.
    var iniD = centro + mitadAct + GAP, finD = W - padR;
    var tramo = (finD - iniD) / 2;
    der.forEach(function (k, n) {
      var c = iniD + tramo * (n + 0.5);
      x[k] = Math.max(iniD + anchos[k] / 2, Math.min(finD - anchos[k] / 2, c));
    });

    items.forEach(function (a, k) { a.style.setProperty('--dk-x', x[k].toFixed(1) + 'px'); });
  }

  //  Se coloca al cargar y cuando cambie el tamaño (girar el teléfono).
  colocar();
  addEventListener('resize', function () {
    clearTimeout(colocar._t); colocar._t = setTimeout(colocar, 120);
  }, { passive: true });
  //  Las fuentes cambian los anchos al terminar de cargar.
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(colocar).catch(function () {});

  //  MAGNIFICACIÓN estilo dock: crece el de debajo del puntero y un poco sus
  //  vecinos. Solo con puntero fino, y solo con transform — la caja no se
  //  mueve, así que nada salta.
  if (fino && !reduce) {
    items.forEach(function (a, k) {
      a.addEventListener('mouseenter', function () {
        items.forEach(function (b, j) { b.classList.toggle('vec', Math.abs(j - k) === 1); });
      });
      a.addEventListener('mouseleave', function () {
        items.forEach(function (b) { b.classList.remove('vec'); });
      });
    });
  }

  //  AL TOCAR OTRO: se desliza hacia el centro y se navega. Corto de verdad
  //  —160 ms— porque una animación que retrasa la navegación se siente como
  //  una aplicación lenta, no como una aplicación cuidada. Y si algo falla en
  //  el camino, el enlace ya iba a navegar solo.
  if (!reduce) {
    dock.addEventListener('click', function (e) {
      var a = e.target.closest('.dk-i');
      if (!a || a.classList.contains('act')) return;
      if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
      if (getComputedStyle(dock).display === 'none') return;
      e.preventDefault();
      var centro = dock.clientWidth / 2;
      a.style.setProperty('--dk-x', centro.toFixed(1) + 'px');
      a.classList.add('act');
      items.forEach(function (b) { if (b !== a) b.classList.remove('act'); });
      setTimeout(function () { location.href = a.href; }, 160);
    });
  }
})();
</script>
</body>
</html>
