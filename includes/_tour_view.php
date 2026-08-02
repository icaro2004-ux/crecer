<?php
// ============================================================
//  CRECER — El recibimiento · VISTA  ·  includes/_tour_view.php
//  Lo pinta tour_montar(). Espera: $pasos, $clave, $marca_id, $hay_tabla.
//  No se incluye directo desde una pantalla — se llama tour_montar().
// ============================================================
if (!isset($pasos, $clave, $marca_id)) return;
?>
<div class="tr-ov" id="trOv" hidden>
  <div class="tr-hole" id="trHole"></div>
  <div class="tr-card" id="trCard" role="dialog" aria-label="Recorrido">
    <p class="tr-t" id="trT"></p>
    <p class="tr-s" id="trS"></p>
    <div class="tr-foot">
      <div class="tr-dots" id="trDots"></div>
      <button type="button" class="tr-skip" id="trSkip">Saltar</button>
      <button type="button" class="tr-next" id="trNext">Siguiente</button>
    </div>
  </div>
</div>

<style>
  .tr-ov{position:fixed;inset:0;z-index:140;opacity:0;transition:opacity .28s var(--ease)}
  .tr-ov.on{opacity:1}
  /* El "hoyo": no se pone un filtro encima de la pantalla — se pinta la sombra
     ALREDEDOR del elemento real. Así el contenido del dueño se ve nítido. */
  .tr-hole{position:absolute;border-radius:18px;box-shadow:0 0 0 9999px rgba(23,18,28,.66);
    pointer-events:none;transition:all .42s cubic-bezier(.4,0,.2,1)}
  .tr-hole.sinfoco{top:50%;left:50%;width:0;height:0;box-shadow:0 0 0 9999px rgba(23,18,28,.74)}
  .tr-card{position:absolute;width:min(340px,calc(100vw - 32px));background:var(--card,#fff);
    border-radius:18px;padding:17px 18px 13px;box-shadow:0 24px 60px rgba(23,18,28,.34);
    transition:all .42s cubic-bezier(.4,0,.2,1)}
  .tr-t{font-family:var(--font-display);font-weight:600;font-size:17px;letter-spacing:-.015em;
    color:var(--tinta);margin:0 0 5px;line-height:1.25}
  .tr-s{font-size:14px;line-height:1.5;color:var(--muted);margin:0 0 14px}
  .tr-foot{display:flex;align-items:center;gap:9px}
  .tr-dots{display:flex;gap:5px;margin-right:auto}
  .tr-dots i{width:6px;height:6px;border-radius:50%;background:var(--line);transition:all .25s}
  .tr-dots i.on{background:var(--magenta);width:17px;border-radius:99px}
  .tr-skip{border:0;background:none;cursor:pointer;font-family:var(--font-body);font-size:12.5px;
    font-weight:600;color:var(--muted);padding:8px 4px}
  .tr-skip:hover{color:var(--tinta)}
  .tr-next{border:0;cursor:pointer;font-family:var(--font-body);font-weight:700;font-size:13.5px;
    color:#fff;background:var(--btn-grad);box-shadow:var(--btn-glow);padding:10px 18px;border-radius:11px;
    transition:transform .15s var(--ease)}
  .tr-next:active{transform:translateY(1px)}
  @media(max-width:860px){
    /* Móvil: la tarjeta no persigue al elemento — se ancla abajo, al alcance
       del pulgar, y salta arriba solo si taparía lo que está iluminando. */
    .tr-card{left:12px;right:12px;width:auto;bottom:calc(18px + env(safe-area-inset-bottom));top:auto!important}
    .tr-card.arriba{top:18px!important;bottom:auto}
  }
</style>

<script>
(function(){
  var CSRF=<?= json_encode(csrf_token()) ?>, MARCA=<?= (int)$marca_id ?>,
      CLAVE=<?= json_encode($clave) ?>, HAY_TABLA=<?= !empty($hay_tabla) ? 'true' : 'false' ?>,
      FORZADO=<?= isset($_GET['tour']) ? 'true' : 'false' ?>, LSK='tour_'+CLAVE+'_'+MARCA;

  // Sin la tabla en la BD, el navegador es la única memoria que hay.
  // Si el dueño lo pidió a propósito, ninguna memoria lo detiene.
  if(!FORZADO && !HAY_TABLA && localStorage.getItem(LSK)) return;
  if(FORZADO){ try{ localStorage.removeItem(LSK); }catch(e){} }

  var movil = window.matchMedia('(max-width:860px)').matches;
  var PASOS = <?= json_encode($pasos, JSON_UNESCAPED_UNICODE) ?>;

  // __NAV__ = "dónde vive cada cosa". En desktop es el sidebar; en móvil es la
  // barra de abajo. La misma idea, dos navegaciones distintas.
  PASOS.forEach(function(p){ if(p.sel==='__NAV__') p.sel = movil ? '.botnav' : '.side'; });

  function buscar(p){
    if(!p.sel) return null;                       // paso sin foco: tarjeta al centro
    var el=document.querySelector(p.sel);
    if(!el) return false;                         // no existe → se cae el paso
    if(p.up) el = el.closest(p.up) || el;
    // Escondido (pantalla de un wizard que aún no toca, tarjeta vacía): no se señala.
    if(!el.getClientRects().length) return false;
    return el;
  }

  var ov=document.getElementById('trOv'), hole=document.getElementById('trHole'), card=document.getElementById('trCard'),
      elT=document.getElementById('trT'), elS=document.getElementById('trS'), dots=document.getElementById('trDots'),
      bNext=document.getElementById('trNext'), bSkip=document.getElementById('trSkip');

  var pasos=PASOS.filter(function(p){ return buscar(p)!==false; });
  if(!pasos.length) return;
  var i=0;

  pasos.forEach(function(_,n){ var d=document.createElement('i'); if(!n)d.className='on'; dots.appendChild(d); });

  function colocar(){
    var el=buscar(pasos[i]);
    if(el===false){ siguiente(); return; }
    if(!el){ hole.className='tr-hole sinfoco';
      if(!movil){ card.style.top=Math.max(16,(window.innerHeight-(card.offsetHeight||160))/2)+'px';
                  card.style.left=((window.innerWidth-(card.offsetWidth||340))/2)+'px'; }
      else { card.classList.remove('arriba'); }
      return; }

    hole.className='tr-hole';
    var r=el.getBoundingClientRect(), pad=8;
    hole.style.top=(r.top-pad)+'px'; hole.style.left=(r.left-pad)+'px';
    hole.style.width=(r.width+pad*2)+'px'; hole.style.height=(r.height+pad*2)+'px';

    if(movil){
      card.classList.toggle('arriba', r.top > window.innerHeight*0.45);
    } else {
      var ancho=card.offsetWidth||340, alto=card.offsetHeight||160, m=16, top, left;
      if(r.bottom+m+alto < window.innerHeight)   top=r.bottom+m;      // debajo si cabe
      else if(r.top-m-alto > 0)                  top=r.top-m-alto;    // si no, encima
      else                                       top=Math.max(m,(window.innerHeight-alto)/2);
      left=r.left+r.width/2-ancho/2;
      if(left<m) left=r.right+m;                                      // pegado al borde izq (sidebar)
      if(left+ancho>window.innerWidth-m) left=window.innerWidth-ancho-m;
      card.style.top=top+'px'; card.style.left=Math.max(m,left)+'px';
    }
  }

  function pintar(){
    var p=pasos[i];
    elT.textContent=p.t; elS.textContent=p.s;
    bNext.textContent = (i===pasos.length-1) ? 'Entendido' : 'Siguiente';
    [].forEach.call(dots.children,function(d,n){ d.classList.toggle('on', n===i); });
    var el=buscar(p);
    if(el) el.scrollIntoView({block:'center', behavior:'smooth'});
    setTimeout(colocar, 380);   // medir DESPUÉS de que el scroll asentó
  }

  function cerrar(){
    ov.classList.remove('on');
    setTimeout(function(){ ov.hidden=true; }, 300);
    try{ localStorage.setItem(LSK,'1'); }catch(e){}
    fetch('/crecer/panel/tour_visto.php',{method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({csrf:CSRF, marca_id:MARCA, clave:CLAVE})}).catch(function(){});
  }
  function siguiente(){ if(i>=pasos.length-1){ cerrar(); return; } i++; pintar(); }

  bNext.addEventListener('click', siguiente);
  bSkip.addEventListener('click', cerrar);
  document.addEventListener('keydown', function(e){
    if(ov.hidden) return;
    if(e.key==='Escape') cerrar();
    if(e.key==='Enter'){ e.preventDefault(); siguiente(); }
  });
  window.addEventListener('resize', function(){ if(!ov.hidden) colocar(); });

  // Arranca cuando la pantalla ya está quieta (el Ayudante y las tarjetas ya pintaron).
  window.addEventListener('load', function(){
    setTimeout(function(){ ov.hidden=false; requestAnimationFrame(function(){ ov.classList.add('on'); pintar(); }); }, 700);
  });
})();
</script>
