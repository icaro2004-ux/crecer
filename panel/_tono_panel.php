<?php
// ============================================================
//  CRECER — Wizard "Tono de Voz" (incluido por marca.php)
//  Swipe-cards: 1) punto de partida  2) afinar sliders  3) así suena + guardar.
//  Una ventana a la vez, mínimo scroll. Auto-swipe al escoger; swipe manual en móvil.
//  Espera en scope: $T (boricua/formal/venta/ingenio), $Tpreset, $marca_id, $h, $marca
//  Guarda con accion=tono · genera ejemplos con accion=tono_preview (en lightbox).
// ============================================================
$creativa = (function_exists('equipo_nombre') && isset($marca)) ? equipo_nombre($marca, 'escritor') : 'La Creativa';
?>
<style>
  .vz{max-width:640px;margin-top:14px}
  .vz-dots{display:flex;gap:7px;justify-content:center;margin:2px 0 14px}
  .vz-dots i{width:8px;height:8px;border-radius:50%;background:var(--line);transition:width .3s var(--ease),background .3s}
  .vz-dots i.on{width:22px;background:linear-gradient(90deg,var(--coral),var(--magenta))}
  .vz-view{overflow:hidden;transition:height .4s cubic-bezier(.22,1,.36,1)}
  .vz-track{position:relative}
  .vz-card{box-sizing:border-box;background:var(--card);border:1px solid var(--line);
    border-radius:20px;padding:20px 20px 22px;box-shadow:var(--shadow-sm)}
  .vz-step{font-family:'Poppins',sans-serif;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--magenta)}
  .vz-t{font-family:var(--font-display);font-weight:600;font-size:19px;color:var(--ink-soft);letter-spacing:-.01em;margin:3px 0 3px;line-height:1.15}
  .vz-hint{font-size:13px;color:var(--muted);margin:0 0 14px;line-height:1.45}

  /* Paso 1 — presets */
  .vz-presets{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}
  .vz-preset{border:1.5px solid var(--line);background:#fff;border-radius:15px;padding:15px 13px;cursor:pointer;text-align:center;font-family:inherit;
    display:flex;flex-direction:column;align-items:center;gap:7px;transition:border-color .15s,transform .12s,box-shadow .15s,background .2s}
  .vz-preset:hover{transform:translateY(-2px);box-shadow:var(--shadow-sm);border-color:color-mix(in srgb,var(--magenta) 40%,var(--line))}
  .vz-preset .chip{width:42px;height:42px;border-radius:13px;display:grid;place-items:center;background:color-mix(in srgb,var(--magenta) 10%,#fff);color:var(--magenta);transition:.2s}
  .vz-preset .chip svg{width:22px;height:22px}
  .vz-preset b{font-size:14px;font-weight:800;color:var(--tinta)}
  .vz-preset small{color:var(--muted);font-size:11.5px;line-height:1.3}
  .vz-preset.on{border-color:transparent;background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff;box-shadow:0 14px 30px -12px rgba(239,67,117,.5)}
  .vz-preset.on .chip{background:rgba(255,255,255,.22);color:#fff}
  .vz-preset.on b,.vz-preset.on small{color:#fff}

  /* Paso 2 — sliders */
  .vz-slab{display:flex;justify-content:space-between;align-items:baseline;margin:16px 0 6px}
  .vz-slab:first-of-type{margin-top:4px}
  .vz-slab .nm{font-weight:700;font-size:13.5px;color:var(--ink-soft)}
  .vz-slab .vl{font-family:'Poppins',sans-serif;font-weight:600;font-size:11.5px;color:var(--magenta);text-transform:uppercase;letter-spacing:.03em}
  .vz input[type=range]{-webkit-appearance:none;appearance:none;width:100%;height:8px;border-radius:99px;outline:none;cursor:pointer;
    background:linear-gradient(90deg,var(--coral),var(--magenta)) no-repeat,#f0e9e2;background-size:var(--p,50%) 100%,100% 100%}
  .vz input[type=range]::-webkit-slider-thumb{-webkit-appearance:none;appearance:none;width:24px;height:24px;border-radius:50%;background:#fff;border:3px solid var(--magenta);box-shadow:0 4px 10px -2px rgba(255,43,133,.5);transition:transform .1s}
  .vz input[type=range]::-webkit-slider-thumb:active{transform:scale(1.15)}
  .vz input[type=range]::-moz-range-thumb{width:24px;height:24px;border-radius:50%;background:#fff;border:3px solid var(--magenta)}
  .vz-ends{display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin-top:4px}

  /* Paso 3 — resumen + acciones */
  .vz-voice{padding:16px 16px;border-radius:15px;background:color-mix(in srgb,var(--teal) 9%,#fff);border:1px solid color-mix(in srgb,var(--teal) 22%,#fff);
    font-size:15px;line-height:1.55;color:var(--ink-soft)}
  .vz-voice b{color:#007b7e}
  .vz-by{display:flex;align-items:center;gap:8px;margin:15px 0 9px;color:var(--muted);font-size:12.5px}
  .vz-by svg{width:15px;height:15px}
  .vz-by b{color:var(--ink-soft)}
  .vz-ex{width:100%;border:1.5px solid var(--line);background:#fff;cursor:pointer;font-family:inherit;font-weight:700;font-size:13.5px;color:var(--ink-soft);
    border-radius:13px;padding:12px 14px;display:flex;align-items:center;justify-content:center;gap:8px;transition:.15s}
  .vz-ex:hover{border-color:var(--teal);color:var(--teal-700)}
  .vz-ex svg{width:17px;height:17px}
  .vz-ex[disabled]{opacity:.6;pointer-events:none}
  .vz-save{width:100%;margin-top:10px;border:0;cursor:pointer;font-family:'Poppins',sans-serif;font-weight:600;font-size:15px;color:#fff;
    background:linear-gradient(135deg,var(--coral),var(--magenta));padding:15px;border-radius:15px;box-shadow:0 12px 26px -10px rgba(239,67,117,.5);transition:transform .12s}
  .vz-save:active{transform:translateY(1px)}

  /* Navegación (atrás/siguiente) */
  .vz-nav{display:flex;justify-content:space-between;align-items:center;margin-top:16px;gap:10px}
  .vz-back{background:none;border:0;cursor:pointer;font-family:inherit;font-weight:700;font-size:13.5px;color:var(--muted);padding:8px 4px;display:inline-flex;align-items:center;gap:5px}
  .vz-back svg{width:15px;height:15px}
  .vz-back[hidden]{visibility:hidden}
  .vz-next{border:0;cursor:pointer;font-family:'Poppins',sans-serif;font-weight:600;font-size:14px;color:#fff;background:var(--tinta,#231f20);
    padding:12px 22px;border-radius:13px;display:inline-flex;align-items:center;gap:7px;transition:transform .12s}
  .vz-next:active{transform:translateY(1px)}
  .vz-next svg{width:16px;height:16px}

  /* Lightbox de ejemplos */
  .vz-lb{position:fixed;inset:0;z-index:200;background:rgba(20,12,22,.55);backdrop-filter:blur(3px);display:none;align-items:flex-end;justify-content:center}
  .vz-lb.show{display:flex;animation:vzfade .2s ease}
  @keyframes vzfade{from{opacity:0}to{opacity:1}}
  .vz-sheet{background:var(--card);width:100%;max-width:520px;max-height:86vh;overflow-y:auto;border-radius:22px 22px 0 0;padding:20px 20px calc(24px + env(safe-area-inset-bottom));
    box-shadow:0 -20px 50px -18px rgba(0,0,0,.45);animation:vzrise .28s var(--ease) both}
  @media(min-width:620px){.vz-lb{align-items:center}.vz-sheet{border-radius:22px}}
  @keyframes vzrise{from{transform:translateY(24px);opacity:.6}to{transform:none;opacity:1}}
  .vz-lb-h{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px}
  .vz-lb-h b{font-family:var(--font-display);font-weight:600;font-size:17px;color:var(--ink-soft)}
  .vz-lb-x{background:none;border:0;cursor:pointer;color:var(--muted);padding:5px;display:grid;place-items:center}
  .vz-lb-x svg{width:20px;height:20px}
  .vz-lb-sub{color:var(--muted);font-size:12.5px;margin:0 0 14px}
  .vz-vcard{border:1px solid var(--line);border-radius:14px;padding:14px 16px;background:#fff;margin-bottom:11px;font-size:14px;line-height:1.55;white-space:pre-wrap;animation:vzrise .3s ease both}
  .vz-vcard .vn{font-family:'Poppins',sans-serif;font-weight:600;font-size:10.5px;text-transform:uppercase;color:var(--magenta);letter-spacing:.04em;margin-bottom:6px;display:block}
  .vz-lb-load{display:flex;align-items:center;gap:11px;color:var(--muted);font-size:14px;padding:14px 2px}
  .vz-lb-load .sp{width:20px;height:20px;border-radius:50%;border:3px solid rgba(0,0,0,.12);border-top-color:var(--magenta);animation:vzspin .8s linear infinite}
  @keyframes vzspin{to{transform:rotate(360deg)}}
</style>

<form method="post" class="vz" id="vzForm">
  <input type="hidden" name="accion" value="tono">
  <input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>">
  <input type="hidden" name="preset" id="t_preset" value="<?= $h($Tpreset) ?>">

  <div class="vz-dots" id="vzDots"><i class="on"></i><i></i><i></i></div>

  <div class="vz-view" id="vzView"><div class="vz-track" id="vzTrack">

    <!-- ── Paso 1: punto de partida ── -->
    <section class="vz-card" data-i="0">
      <span class="vz-step">Paso 1 de 3</span>
      <h3 class="vz-t">¿Cómo quieres sonar?</h3>
      <p class="vz-hint">Toca un punto de partida — lo afinas en el próximo paso.</p>
      <div class="vz-presets" id="vzPresets">
        <button type="button" class="vz-preset" data-p="boricua"   data-v="85,25,55,60"><span class="chip"><?= ico('chat') ?></span><b>Cercano</b><small>Como hablas con tu gente</small></button>
        <button type="button" class="vz-preset" data-p="pro"       data-v="28,85,48,18"><span class="chip"><?= ico('briefcase') ?></span><b>Profesional</b><small>Serio y confiable</small></button>
        <button type="button" class="vz-preset" data-p="premium"   data-v="35,74,34,32"><span class="chip"><?= ico('sparkles') ?></span><b>Premium</b><small>Elegante, sin gritar</small></button>
        <button type="button" class="vz-preset" data-p="divertido" data-v="72,20,62,92"><span class="chip"><?= ico('bolt') ?></span><b>Divertido</b><small>Con chiste y energía</small></button>
      </div>
    </section>

    <!-- ── Paso 2: afinar ── -->
    <section class="vz-card" data-i="1" style="display:none">
      <span class="vz-step">Paso 2 de 3</span>
      <h3 class="vz-t">Afínalo a tu gusto</h3>
      <p class="vz-hint">Mueve los controles hasta que suene a ti.</p>
      <?php
      $sliders = [
        ['boricua','Sabor boricua','Neutral','Bien de la isla'],
        ['formal','Formalidad','Relajado','Profesional'],
        ['venta','Energía de venta','Suave','Vendedor'],
        ['ingenio','Ingenio / humor','Sobrio','Con chiste'],
      ];
      foreach ($sliders as [$k,$nm,$lo,$hi]): ?>
        <div class="vz-slab"><span class="nm"><?= $h($nm) ?></span><span class="vl" id="vl_<?= $k ?>"></span></div>
        <input type="range" id="t_<?= $k ?>" name="t_<?= $k ?>" min="0" max="100" value="<?= (int)$T[$k] ?>" aria-label="<?= $h($nm) ?>">
        <div class="vz-ends"><span><?= $h($lo) ?></span><span><?= $h($hi) ?></span></div>
      <?php endforeach; ?>
      <div class="vz-nav">
        <button type="button" class="vz-back" data-go="0"><?= ico('x') ?>Atrás</button>
        <button type="button" class="vz-next" data-go="2">Ver cómo suena <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg></button>
      </div>
    </section>

    <!-- ── Paso 3: así suena + guardar ── -->
    <section class="vz-card" data-i="2" style="display:none">
      <span class="vz-step">Paso 3 de 3</span>
      <h3 class="vz-t">Así va a sonar</h3>
      <div class="vz-voice" id="vzVoice"></div>
      <div class="vz-by"><?= ico('pen') ?> Ejemplos escritos por <b><?= $h($creativa) ?></b></div>
      <button type="button" class="vz-ex" id="vzEx"><?= ico('sparkles') ?> Genera 3 ejemplos</button>
      <button type="submit" class="vz-save">Guardar mi tono</button>
      <div class="vz-nav">
        <button type="button" class="vz-back" data-go="1"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6"/></svg>Atrás</button>
        <span></span>
      </div>
    </section>

  </div></div>
</form>

<!-- Lightbox: 3 ejemplos de la Creativa -->
<div class="vz-lb" id="vzLb">
  <div class="vz-sheet">
    <div class="vz-lb-h"><b>Así escribiría <?= $h($creativa) ?></b><button type="button" class="vz-lb-x" id="vzLbX" aria-label="Cerrar"><?= ico('x') ?></button></div>
    <p class="vz-lb-sub">3 ejemplos con el tono que escogiste. Si te gusta, dale a <b>Guardar mi tono</b>.</p>
    <div id="vzExRes"></div>
  </div>
</div>

<script>
(function(){
  var ids=['boricua','formal','venta','ingenio'];
  var S={}; ids.forEach(function(k){S[k]=document.getElementById('t_'+k);});
  var view=document.getElementById('vzView'), track=document.getElementById('vzTrack');
  var cards=[].slice.call(track.querySelectorAll('.vz-card')), dots=[].slice.call(document.querySelectorAll('#vzDots i'));
  var cur=0;

  var REDUCE = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  function fit(){ var c=cards[cur]; if(c) view.style.height=c.offsetHeight+'px'; window.dispatchEvent(new Event('resize')); }
  // Transición: FADE-OUT / FADE-IN direccional (izq↔der), como el pase de propuestas.
  function go(target){
    target=Math.max(0,Math.min(cards.length-1,target));
    if(target===cur){ fit(); return; }
    var dir=target>cur?1:-1, a=cards[cur], b=cards[target];
    cur=target; dots.forEach(function(d,x){ d.classList.toggle('on',x===cur); });
    if(target===2) paint();
    if(REDUCE){ a.style.display='none'; b.style.display=''; fit(); return; }
    a.style.transition='opacity .2s ease, transform .2s ease';
    a.style.opacity='0'; a.style.transform='translateX('+(-14*dir)+'px)';
    setTimeout(function(){
      a.style.display='none'; a.style.transition=''; a.style.transform=''; a.style.opacity='';
      b.style.display=''; b.style.opacity='0'; b.style.transform='translateX('+(16*dir)+'px)';
      view.style.height=b.offsetHeight+'px'; window.dispatchEvent(new Event('resize'));
      requestAnimationFrame(function(){
        b.style.transition='opacity .34s cubic-bezier(.22,1,.36,1), transform .34s cubic-bezier(.22,1,.36,1)';
        b.style.opacity='1'; b.style.transform='none';
      });
    }, 190);
  }
  // Botones atrás/siguiente
  track.querySelectorAll('[data-go]').forEach(function(b){ b.addEventListener('click',function(){ go(+b.dataset.go); }); });

  // Sliders → pintar etiquetas + barra
  var bucket=function(v){return v<34?0:(v<67?1:2);};
  var LB={boricua:['Neutral','Con sabor','Bien boricua'],formal:['Casual','Equilibrado','Formal'],
          venta:['Suave','Invita','Vendedor'],ingenio:['Sobrio','Con chispa','Bien jocoso']};
  function paint(){
    ids.forEach(function(k){
      S[k].style.setProperty('--p',S[k].value+'%');
      var el=document.getElementById('vl_'+k); if(el) el.textContent=LB[k][bucket(+S[k].value)];
    });
    var b=bucket(+S.boricua.value),f=bucket(+S.formal.value),v=bucket(+S.venta.value),g=bucket(+S.ingenio.value);
    var B=['en español neutral','con sabor boricua','bien de la isla'][b];
    var F=['bien casual','relajado pero pulido','profesional'][f];
    var V=['sin presión','con invitación suave','con punch de venta'][v];
    var G=['','',' y con chiste'][g];
    var vc=document.getElementById('vzVoice');
    if(vc) vc.innerHTML='Tu voz suena <b>'+B+'</b>, <b>'+F+'</b>, <b>'+V+'</b>'+(G?(', <b>'+G.trim()+'</b>'):'')+'.';
  }
  ids.forEach(function(k){S[k].addEventListener('input',function(){
    document.querySelectorAll('#vzPresets .vz-preset').forEach(function(p){p.classList.remove('on');});
    document.getElementById('t_preset').value=''; paint();
  });});

  // Presets → aplican valores y AUTO-SWIPE al paso 2
  document.querySelectorAll('#vzPresets .vz-preset').forEach(function(btn){
    btn.addEventListener('click',function(){
      var v=btn.dataset.v.split(',');
      ids.forEach(function(k,i){S[k].value=v[i];});
      document.querySelectorAll('#vzPresets .vz-preset').forEach(function(p){p.classList.remove('on');});
      btn.classList.add('on'); document.getElementById('t_preset').value=btn.dataset.p; paint();
      setTimeout(function(){ go(1); }, 240);
    });
  });
  var saved=<?= json_encode($Tpreset) ?>;
  if(saved){var sb=document.querySelector('#vzPresets .vz-preset[data-p="'+saved+'"]'); if(sb)sb.classList.add('on');}
  paint();

  // Swipe manual (no interfiere con los sliders)
  var x0=null,y0=null,lock=null;
  view.addEventListener('touchstart',function(e){ if(e.target.closest('input[type=range]')){x0=null;return;} var t=e.touches[0];x0=t.clientX;y0=t.clientY;lock=null; },{passive:true});
  view.addEventListener('touchmove',function(e){ if(x0===null)return; var t=e.touches[0],dx=t.clientX-x0,dy=t.clientY-y0; if(lock===null&&(Math.abs(dx)>8||Math.abs(dy)>8)) lock=Math.abs(dx)>Math.abs(dy)?'x':'y'; },{passive:true});
  view.addEventListener('touchend',function(e){ if(x0===null||lock!=='x'){x0=null;return;} var dx=e.changedTouches[0].clientX-x0; if(dx<-45)go(cur+1); else if(dx>45)go(cur-1); x0=null; },{passive:true});

  window.addEventListener('resize',function(){ var c=cards[cur]; if(c) view.style.height=c.offsetHeight+'px'; });
  // arranque: card 0 visible (las demás ya en display:none por el markup)
  cur=0; dots.forEach(function(d,x){ d.classList.toggle('on',x===0); }); fit();

  // ── Ejemplos en LIGHTBOX ──
  var ex=document.getElementById('vzEx'), lb=document.getElementById('vzLb'), lbX=document.getElementById('vzLbX'), exRes=document.getElementById('vzExRes');
  function openLb(){ lb.classList.add('show'); document.body.style.overflow='hidden'; }
  function closeLb(){ lb.classList.remove('show'); document.body.style.overflow=''; }
  lbX.addEventListener('click',closeLb);
  lb.addEventListener('click',function(e){ if(e.target===lb) closeLb(); });

  ex.addEventListener('click',function(){
    openLb();
    exRes.innerHTML='<div class="vz-lb-load"><span class="sp"></span> <?= $h($creativa) ?> está escribiendo…</div>';
    var fd=new FormData(); fd.append('accion','tono_preview'); fd.append('csrf', <?= json_encode(csrf_token()) ?>);
    ids.forEach(function(k){fd.append('t_'+k,S[k].value);});
    fetch(location.pathname+location.search,{method:'POST',body:fd})
      .then(function(r){return r.json();})
      .then(function(d){
        if(!d.ok||!d.variaciones||!d.variaciones.length){
          exRes.innerHTML='<div class="vz-vcard">No se pudo generar ahora. '+((d&&d.error)?String(d.error).replace(/</g,'&lt;'):'Intenta de nuevo.')+'</div>'; return;
        }
        var names=['Ejemplo 1','Ejemplo 2','Ejemplo 3'];
        exRes.innerHTML=d.variaciones.map(function(t,i){
          return '<div class="vz-vcard" style="animation-delay:'+(i*0.07)+'s"><span class="vn">'+(names[i]||'Ejemplo')+'</span>'+String(t).replace(/</g,'&lt;')+'</div>';
        }).join('');
      })
      .catch(function(){ exRes.innerHTML='<div class="vz-vcard">Hubo un problema de conexión. Intenta de nuevo.</div>'; });
  });
})();
</script>
