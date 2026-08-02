<?php
// ============================================================
//  CRECER — EL RECIBIMIENTO  ·  includes/tour_home.php
//
//  Lo que ve el dueño la PRIMERA VEZ que entra a su Home. Una vez en la
//  vida de la cuenta, nunca más (se marca en crecer_marca.tour_home_at).
//
//  NO es un manual de la app. El Home está hecho para SENTIRSE, no para
//  explicarse — por eso no tiene el overlay-guía de las otras pantallas.
//  Esto es otra cosa: le presenta al equipo que YA trabajó por él, le dice
//  dónde vive cada cosa, y lo suelta EN la acción (aprobar lo que le dejaron).
//  Por eso ilumina lo que de verdad está en su pantalla, con su contenido —
//  no cuenta un cuento aparte.
//
//  Native Design: en desktop el paso de "dónde vive todo" apunta al sidebar;
//  en móvil apunta a la barra de abajo. Son navegaciones distintas.
//
//  Requiere: $marca_id, csrf_token(). Se incluye desde panel/index.php ANTES
//  de _shell_foot.php; el JS espera al load para que el botón Ayuda exista.
// ============================================================

$tour_marca_id = (int)($marca_id ?? 0);
if ($tour_marca_id <= 0 || !function_exists('csrf_token')) return;

// ¿Ya lo vio? Si la columna no existe todavía (migración sin correr), se deja
// que el navegador decida — así no se rompe nada y no se lo come dos veces.
$tour_pendiente = true;
$tour_hay_columna = false;
try {
    $st = $pdo->prepare("SELECT tour_home_at FROM crecer_marca WHERE id=?");
    $st->execute([$tour_marca_id]);
    $tour_hay_columna = true;
    $tour_pendiente = ($st->fetchColumn() === null);
} catch (Throwable $e) { /* sin columna: manda el localStorage */ }

// ?tour=1 — el dueño lo pidió otra vez desde Configuración. Manda él.
$tour_forzado = isset($_GET['tour']);
if ($tour_forzado) $tour_pendiente = true;

if (!$tour_pendiente) return;
?>
<div class="tr-ov" id="trOv" hidden>
  <div class="tr-hole" id="trHole"></div>
  <div class="tr-card" id="trCard" role="dialog" aria-label="Recibimiento">
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
  /* El "hoyo": no se pinta un overlay con recorte — se pinta la sombra ALREDEDOR
     del elemento real. Así el contenido del dueño se ve nítido, sin filtro encima. */
  .tr-hole{position:absolute;border-radius:18px;box-shadow:0 0 0 9999px rgba(23,18,28,.66);
    pointer-events:none;transition:all .42s cubic-bezier(.4,0,.2,1)}
  .tr-hole.centro{box-shadow:0 0 0 9999px rgba(23,18,28,.72)}
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
    /* Móvil: la tarjeta no persigue al elemento — se ancla abajo, al alcance del pulgar. */
    .tr-card{left:12px;right:12px;width:auto;bottom:calc(18px + env(safe-area-inset-bottom));top:auto!important}
    .tr-card.arriba{top:18px!important;bottom:auto}
  }
</style>

<script>
(function(){
  var CSRF=<?= json_encode(csrf_token()) ?>, MARCA=<?= (int)$tour_marca_id ?>,
      HAY_COL=<?= $tour_hay_columna ? 'true' : 'false' ?>,
      FORZADO=<?= $tour_forzado ? 'true' : 'false' ?>, LSK='tour_home_'+MARCA;
  // Sin la columna en la BD, el navegador es la única memoria que hay.
  // Si el dueño lo pidió a propósito, ninguna memoria lo detiene.
  if(!FORZADO && !HAY_COL && localStorage.getItem(LSK)) return;
  if(FORZADO){ try{ localStorage.removeItem(LSK); }catch(e){} }

  var movil = window.matchMedia('(max-width:860px)').matches;
  // Los pasos apuntan a lo que YA está en su pantalla. El que no encuentre su
  // elemento se cae solo (ej. cuenta sin post pendiente: no hay nada que iluminar).
  var PASOS=[
    // El texto cambia según lo que HAY de verdad en su pantalla. Prometerle un post
    // hecho cuando la tarjeta está vacía sería empezar la relación mintiendo.
    <?php if (!empty($hz_post)): ?>
    { sel:'.hz-card',
      t:'Tu corillo ya trabajó.',
      s:'Esto no es una pantalla vacía esperándote: tu próximo post ya está hecho. Tú solo lo apruebas.' },
    <?php else: ?>
    { sel:'.hz-card',
      t:'Aquí aparece tu próximo post.',
      s:'El corillo lo está preparando. Cuando esté, sale aquí y lo único que tienes que hacer es darle el OK.' },
    <?php endif; ?>
    { sel:'.an-card',
      t:'Y hay quien vigila.',
      s:'Tu analista mira tus números todos los días y te habla solo cuando algo vale la pena. No tienes que buscarlo.' },
    { sel: movil ? '.botnav' : '.side',
      t: movil ? 'Aquí vive todo.' : 'Todo vive aquí al lado.',
      s:'Tus Posts es donde está el trabajo del corillo. La Sala es donde les hablas y les pides. Resultados, cómo te fue.' },
    { sel:'#ayFab',
      t:'Y si algo se traba…',
      s:'Este botón no es un formulario de quejas. Revisa tu cuenta ahí mismo y lo arregla. Si no puede, avisa al equipo por ti.' }
  ];

  var ov=document.getElementById('trOv'), hole=document.getElementById('trHole'), card=document.getElementById('trCard'),
      elT=document.getElementById('trT'), elS=document.getElementById('trS'), dots=document.getElementById('trDots'),
      bNext=document.getElementById('trNext'), bSkip=document.getElementById('trSkip');

  var pasos=PASOS.filter(function(p){ return document.querySelector(p.sel); });
  if(!pasos.length) return;
  var i=0;

  pasos.forEach(function(_,n){ var d=document.createElement('i'); if(!n)d.className='on'; dots.appendChild(d); });

  function colocar(){
    var p=pasos[i], el=document.querySelector(p.sel);
    if(!el){ siguiente(); return; }
    var r=el.getBoundingClientRect(), pad=8;
    hole.style.top=(r.top-pad)+'px'; hole.style.left=(r.left-pad)+'px';
    hole.style.width=(r.width+pad*2)+'px'; hole.style.height=(r.height+pad*2)+'px';

    if(movil){
      // Si lo iluminado está en la mitad de abajo, la tarjeta se va arriba (no lo tapa).
      card.classList.toggle('arriba', r.top > window.innerHeight*0.45);
    } else {
      var ancho=card.offsetWidth||340, alto=card.offsetHeight||160, m=16, top, left;
      // Debajo si cabe; si no, encima; si tampoco, al lado.
      if(r.bottom+m+alto < window.innerHeight)      top=r.bottom+m;
      else if(r.top-m-alto > 0)                      top=r.top-m-alto;
      else                                           top=Math.max(m, (window.innerHeight-alto)/2);
      left=r.left+r.width/2-ancho/2;
      if(left<m) left=r.right+m;                                  // se pegó al borde izq (sidebar)
      if(left+ancho>window.innerWidth-m) left=window.innerWidth-ancho-m;
      card.style.top=top+'px'; card.style.left=Math.max(m,left)+'px';
    }
  }

  function pintar(){
    var p=pasos[i];
    elT.textContent=p.t; elS.textContent=p.s;
    bNext.textContent = (i===pasos.length-1) ? 'Empezar' : 'Siguiente';
    [].forEach.call(dots.children,function(d,n){ d.classList.toggle('on', n===i); });
    var el=document.querySelector(p.sel);
    if(el) el.scrollIntoView({block:'center', behavior:'smooth'});
    setTimeout(colocar, 380);   // medir DESPUÉS de que el scroll asentó
  }

  function cerrar(){
    ov.classList.remove('on');
    setTimeout(function(){ ov.hidden=true; }, 300);
    try{ localStorage.setItem(LSK,'1'); }catch(e){}
    fetch('/crecer/panel/tour_visto.php',{method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({csrf:CSRF, marca_id:MARCA})}).catch(function(){});
  }
  function siguiente(){ if(i>=pasos.length-1){ cerrar(); return; } i++; pintar(); }

  bNext.addEventListener('click', siguiente);
  bSkip.addEventListener('click', cerrar);
  document.addEventListener('keydown', function(e){
    if(ov.hidden) return;
    if(e.key==='Escape') cerrar();
    if(e.key==='Enter' || e.key===' ') { e.preventDefault(); siguiente(); }
  });
  window.addEventListener('resize', function(){ if(!ov.hidden) colocar(); });

  // Arranca cuando la pantalla ya está quieta (el Ayudante y las tarjetas ya pintaron).
  window.addEventListener('load', function(){
    setTimeout(function(){ ov.hidden=false; requestAnimationFrame(function(){ ov.classList.add('on'); pintar(); }); }, 700);
  });
})();
</script>
