<?php
// ============================================================
//  LA BARAJA — el gesto único de decidir (includes/baraja.php)
//
//  Motor compartido del swipe de aprobar en móvil:
//    derecha  = "Vamos con este" (aprueba)
//    izquierda = "Ahora no" (aparta — persiste, no recicla)
//    siempre  = Deshacer (5 segundos)
//
//  SOLO teléfono (pointer: coarse). En desktop no se monta nada y
//  las pantallas quedan exactamente como estaban. Los botones se
//  quedan en todos lados: el gesto es el atajo del pulgar, no el
//  único camino.
//
//  Flag: define('CRECER_BARAJA', true) — sin el define, baraja_assets()
//  devuelve '' y NADA de esto llega al navegador (reversa instantánea).
//
//  Uso (una vez por página, antes del script que llama a Baraja.montar):
//    require_once __DIR__ . '/../includes/baraja.php';
//    ...y en el HTML: echo baraja_assets();  (OJO: nada de tags PHP en
//    este comentario — cierran el archivo. Lección aprendida.)
//    <script>
//      if (window.Baraja) Baraja.montar({
//        activa:   function(){ return laCardVisible|null; },
//        aprobar:  function(card, hecho){ ...; hecho(ok); },
//        apartar:  function(card, hecho){ ...; hecho(ok); },
//        deshacer: function(card, dir, hecho){ ...; hecho(ok); },  // dir: 1=aprobó, -1=apartó
//        pista:    document.getElementById('miPista') || null,
//        ignorar:  '.zona-de-botones,.editor'
//      });
//    </script>
// ============================================================

/** CSS + JS del motor. Vacío si el flag está apagado. Se emite UNA vez. */
function baraja_assets(): string {
    if (!(defined('CRECER_BARAJA') && CRECER_BARAJA)) return '';
    static $ya = false;
    if ($ya) return '';
    $ya = true;
    ob_start(); ?>
<style>
  /* La pista del gesto: nace invisible; el motor la enciende solo en teléfono
     y solo hasta el primer swipe logrado. */
  .bj-pista{display:none;align-items:center;justify-content:center;gap:10px;font-size:12.5px;font-weight:700;color:var(--muted,#6E6A67);margin:0 0 14px;text-align:center}
  .bj-pista.on{display:flex}
  .bj-pista i{flex:0 0 26px;height:1.5px;background:var(--line,#E9E7E4);display:block}
  /* Veredictos que se revelan con el arrastre (estilo sello, sin emoji) */
  .bj-v{position:absolute;top:14px;padding:7px 13px;border-radius:12px;font-weight:800;font-size:14px;letter-spacing:.02em;opacity:0;pointer-events:none;z-index:6;border:2.5px solid currentColor;background:rgba(255,255,255,.92)}
  .bj-v.me{left:12px;color:var(--palma,#2e8b57);transform:rotate(-8deg)}
  .bj-v.no{right:12px;color:var(--muted,#6E6A67);transform:rotate(8deg)}
  /* Deshacer: píldora fija abajo con barra de tiempo */
  .bj-undo{position:fixed;left:50%;bottom:18px;transform:translate(-50%,90px);display:flex;align-items:center;gap:14px;background:var(--tinta,#231F20);color:#fff;border-radius:99px;padding:8px 8px 8px 18px;z-index:220;box-shadow:0 12px 30px rgba(0,0,0,.35);overflow:hidden;transition:transform .25s cubic-bezier(.22,1,.36,1);max-width:92vw}
  .bj-undo.on{transform:translate(-50%,0)}
  .bj-undo span{font-size:14px;font-weight:700;white-space:nowrap}
  .bj-undo button{border:0;cursor:pointer;font-family:inherit;background:rgba(255,255,255,.16);color:#fff;font-weight:800;font-size:13.5px;border-radius:99px;padding:0 18px;min-height:44px}
  .bj-undo i{position:absolute;left:0;bottom:0;height:2.5px;background:var(--magenta,#EF4375);width:100%;transform-origin:left;animation:bj-undo-t 5.2s linear forwards}
  @keyframes bj-undo-t{to{transform:scaleX(0)}}
  @media (prefers-reduced-motion: reduce){ .bj-undo{transition:none} .bj-undo i{animation:none} }
</style>
<script>
(function(){
  if (window.Baraja) return;
  var COARSE = !!(window.matchMedia && matchMedia('(pointer: coarse)').matches);
  var REDUCE = !!(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches);
  var EASE = 'cubic-bezier(.22,1,.36,1)';

  window.Baraja = { activo: COARSE, montar: function(cfg){
    if (!COARSE) return;   // desktop: ni un listener
    var IGNORAR = 'input,textarea,select,button,a,video,details,summary,label' + (cfg.ignorar ? ',' + cfg.ignorar : '');

    // ── La pista: solo hasta que el gesto se aprenda (una vez por dispositivo) ──
    var pista = cfg.pista || null;
    try { if (pista && !localStorage.getItem('bj_pista')) pista.classList.add('on'); } catch (e) {}
    function pistaLista(){
      try { localStorage.setItem('bj_pista', '1'); } catch (e) {}
      if (pista) { pista.classList.remove('on'); pista = null; }
    }

    // ── El arrastre ──
    var card=null, x0=0, y0=0, t0=0, dx=0, lock=null, w=320, vibro=false, vMe=null, vNo=null;

    function sello(cls, txt){
      var s=document.createElement('span'); s.className='bj-v '+cls; s.textContent=txt; return s;
    }
    function soltar(anima){
      if(!card) return;
      var c=card; card=null;
      if(vMe){ vMe.remove(); vMe=null; } if(vNo){ vNo.remove(); vNo=null; }
      c.style.willChange='';
      if(anima && !REDUCE){
        c.style.transition='transform .32s '+EASE;
        c.style.transform='';
        setTimeout(function(){ c.style.transition=''; }, 340);
      } else { c.style.transition=''; c.style.transform=''; }
    }
    function umbral(){ return Math.min(110, w*0.32); }

    function onStart(e){
      if(card) return;
      var c = cfg.activa && cfg.activa(); if(!c) return;
      var t = e.touches[0]; if(!t) return;
      if(!c.contains(e.target)) return;            // el gesto nace EN la card activa
      if(e.target.closest(IGNORAR)) return;        // nunca sobre botones/inputs/etc.
      card=c; x0=t.clientX; y0=t.clientY; t0=e.timeStamp; dx=0; lock=null; vibro=false;
      w=c.offsetWidth||320;
    }
    function onMove(e){
      if(!card) return;
      var t=e.touches[0], mx=t.clientX-x0, my=t.clientY-y0;
      if(lock===null && (Math.abs(mx)>10 || Math.abs(my)>10)) lock = Math.abs(mx)>Math.abs(my) ? 'x' : 'y';
      if(lock!=='x'){ if(lock==='y') soltar(false); return; }   // vertical = scroll normal
      e.preventDefault();                                        // horizontal = el dedo es nuestro
      dx=mx;
      if(!vMe){
        if(getComputedStyle(card).position==='static') card.style.position='relative';
        vMe=sello('me','Vamos con este'); vNo=sello('no','Ahora no');
        card.appendChild(vMe); card.appendChild(vNo);
        card.style.willChange='transform';
      }
      card.style.transition='none';
      card.style.transform='translateX('+dx+'px) rotate('+(dx*0.045)+'deg)';
      var p=Math.min(1, Math.abs(dx)/(w*0.28));
      vMe.style.opacity = dx>0 ? p : 0;
      vNo.style.opacity = dx<0 ? p : 0;
      var cruzado = Math.abs(dx) >= umbral();
      if(cruzado && !vibro){ vibro=true; if(navigator.vibrate){ try{ navigator.vibrate(10); }catch(x){} } }
      if(!cruzado) vibro=false;
    }
    function onEnd(e){
      if(!card) return;
      if(lock!=='x'){ soltar(false); return; }
      var dt=Math.max(1, e.timeStamp-t0), v=dx/dt;   // px/ms
      var decide = Math.abs(dx)>=umbral() || (Math.abs(v)>0.6 && Math.abs(dx)>40);
      if(!decide){ soltar(true); return; }           // rebote elástico: no era en serio
      var dir = dx>0 ? 1 : -1;
      var c=card, accion = dir>0 ? cfg.aprobar : cfg.apartar;
      var badges=[vMe,vNo]; card=null; vMe=null; vNo=null;
      pistaLista();
      // La card vuela en la dirección del dedo
      if(REDUCE){ c.style.transition='opacity .15s ease'; c.style.opacity='0'; }
      else{
        c.style.transition='transform .28s '+EASE+', opacity .28s '+EASE;
        c.style.transform='translateX('+(dir*(window.innerWidth+w))+'px) rotate('+(dir*16)+'deg)';
        c.style.opacity='.3';
      }
      setTimeout(function(){ badges.forEach(function(b){ if(b) b.remove(); }); }, 300);
      accion(c, function(ok){
        if(ok){ undoToast(dir, c); return; }
        // No se pudo (server dijo no): la card vuelve a entrar
        c.style.transition = REDUCE ? '' : 'transform .34s '+EASE+', opacity .2s ease';
        c.style.opacity='1'; c.style.transform='';
        c.style.willChange='';
        setTimeout(function(){ c.style.transition=''; }, 360);
      });
    }

    // ── Deshacer (5s, con barra de tiempo) ──
    var undoEl=null, undoTimer=null;
    function undoToast(dir, c){
      if(undoEl){ undoEl.remove(); undoEl=null; }
      if(undoTimer){ clearTimeout(undoTimer); undoTimer=null; }
      var el=document.createElement('div'); el.className='bj-undo';
      var s=document.createElement('span'); s.textContent = dir>0 ? 'Aprobado' : 'Apartado';
      var b=document.createElement('button'); b.type='button'; b.textContent='Deshacer';
      var i=document.createElement('i');
      el.appendChild(s); el.appendChild(b); el.appendChild(i);
      document.body.appendChild(el); undoEl=el;
      requestAnimationFrame(function(){ el.classList.add('on'); });
      function quitar(){
        if(undoTimer){ clearTimeout(undoTimer); undoTimer=null; }
        el.classList.remove('on');
        setTimeout(function(){ el.remove(); if(undoEl===el) undoEl=null; }, 260);
      }
      b.addEventListener('click', function(){
        b.disabled=true;
        cfg.deshacer(c, dir, function(ok){
          if(ok){
            c.style.transition='none'; c.style.transform=''; c.style.opacity='1'; c.style.willChange='';
            quitar();
          } else { b.disabled=false; }
        });
      });
      undoTimer=setTimeout(quitar, 5200);
    }

    document.addEventListener('touchstart', onStart, {passive:true});
    document.addEventListener('touchmove',  onMove,  {passive:false});
    document.addEventListener('touchend',   onEnd,   {passive:true});
    document.addEventListener('touchcancel', function(){ soltar(false); }, {passive:true});
  }};
})();
</script>
<?php
    return ob_get_clean();
}
