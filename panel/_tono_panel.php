<?php
// ============================================================
//  CRECER — Panel "Tono de Voz" (incluido por marca.php)
//  Espera en scope: $T (array boricua/formal/venta/ingenio),
//                   $Tpreset (string), $marca_id (int), $h (callable)
//  Guarda con accion=tono · genera ejemplos reales con accion=tono_preview
// ============================================================
?>
<style>
  .sec-h{font-family:var(--font-display);font-weight:600;color:var(--ink-soft);letter-spacing:-.01em;font-size:19px;margin:6px 0 4px;display:flex;align-items:center;gap:9px}
  .tono{max-width:680px;background:var(--card);border:1px solid var(--line);border-radius:var(--r-lg);padding:20px 22px;box-shadow:var(--shadow-sm);margin-top:14px}
  .tono .presets{display:grid;grid-template-columns:repeat(2,1fr);gap:9px;margin-bottom:18px}
  .tono .preset{border:1.5px solid var(--line);background:#fff;border-radius:13px;padding:11px 13px;cursor:pointer;text-align:left;font-family:inherit;display:flex;gap:9px;align-items:center;transition:border-color .15s,transform .12s,box-shadow .15s}
  .tono .preset:hover{transform:translateY(-2px);box-shadow:var(--shadow-sm)}
  .tono .preset .em{font-size:19px;line-height:1}
  .tono .preset b{display:block;font-size:13px;font-weight:800;color:var(--tinta)}
  .tono .preset small{color:var(--muted);font-size:11px}
  .tono .preset.on{border-color:transparent;background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff}
  .tono .preset.on b,.tono .preset.on small{color:#fff}
  .tono .preset.on small{opacity:.92}
  .tono .slab{display:flex;justify-content:space-between;align-items:baseline;margin:16px 0 6px}
  .tono .slab .nm{font-weight:700;font-size:13.5px}
  .tono .slab .vl{font-family:'Poppins',sans-serif;font-weight:600;font-size:12px;color:var(--magenta);text-transform:uppercase;letter-spacing:.03em}
  .tono input[type=range]{-webkit-appearance:none;appearance:none;width:100%;height:8px;border-radius:99px;outline:none;cursor:pointer;
    background:linear-gradient(90deg,var(--coral),var(--magenta)) no-repeat,#f0e9e2;background-size:var(--p,50%) 100%,100% 100%}
  .tono input[type=range]::-webkit-slider-thumb{-webkit-appearance:none;appearance:none;width:22px;height:22px;border-radius:50%;background:#fff;border:3px solid var(--magenta);box-shadow:0 4px 10px -2px rgba(255,43,133,.5);transition:transform .1s}
  .tono input[type=range]::-webkit-slider-thumb:active{transform:scale(1.15)}
  .tono input[type=range]::-moz-range-thumb{width:22px;height:22px;border-radius:50%;background:#fff;border:3px solid var(--magenta)}
  .tono .ends{display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin-top:4px}
  .tono .voicebar{margin-top:18px;padding:12px 14px;border-radius:12px;background:color-mix(in srgb,var(--teal) 9%,#fff);border:1px solid color-mix(in srgb,var(--teal) 22%,#fff);font-size:13px;line-height:1.5}
  .tono .voicebar b{color:#007b7e}
  .tono .acts{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}
  .tono .gen{border:0;cursor:pointer;font-family:inherit;font-weight:800;font-size:14px;color:#fff;background:linear-gradient(135deg,var(--coral),var(--magenta));padding:12px 18px;border-radius:12px;box-shadow:0 10px 22px -10px rgba(255,43,133,.5)}
  .tono .gen[disabled]{opacity:.7;pointer-events:none}
  .tono .save{border:1.5px solid var(--line);background:#fff;cursor:pointer;font-family:inherit;font-weight:800;font-size:14px;color:var(--tinta);padding:12px 18px;border-radius:12px}
  .tono .save:hover{border-color:var(--terracota);color:var(--terracota-700)}
  .tono .res{margin-top:16px}
  .tono .vcard{border:1px solid var(--line);border-radius:13px;padding:13px 15px;background:#fff;margin-bottom:10px;font-size:13.5px;line-height:1.55;white-space:pre-wrap;animation:trise .35s ease both}
  .tono .vcard .vn{font-family:'Poppins',sans-serif;font-weight:600;font-size:11px;text-transform:uppercase;color:var(--magenta);letter-spacing:.04em;margin-bottom:5px;display:block}
  @keyframes trise{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
  @media(max-width:560px){.tono .presets{grid-template-columns:1fr}}
</style>

<form method="post" class="tono" id="tonoForm">
  <input type="hidden" name="accion" value="tono">
  <input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>">
  <input type="hidden" name="preset" id="t_preset" value="<?= $h($Tpreset) ?>">

  <div class="presets" id="tpresets">
    <button type="button" class="preset" data-p="boricua"   data-v="85,25,55,60"><span class="em"><?= ico('chat') ?></span><span><b>Cercano · Boricua</b><small>Como hablas con tu gente</small></span></button>
    <button type="button" class="preset" data-p="pro"       data-v="28,85,48,18"><span class="em"><?= ico('briefcase') ?></span><span><b>Profesional</b><small>Serio y confiable</small></span></button>
    <button type="button" class="preset" data-p="premium"   data-v="35,74,34,32"><span class="em"><?= ico('sparkles') ?></span><span><b>Premium</b><small>Elegante, sin gritar</small></span></button>
    <button type="button" class="preset" data-p="divertido" data-v="72,20,62,92"><span class="em"><?= ico('bolt') ?></span><span><b>Divertido</b><small>Con chiste y energía</small></span></button>
  </div>

  <?php
  $sliders = [
    ['boricua','Sabor boricua','Neutral','Bien de la isla'],
    ['formal','Formalidad','Relajado','Profesional'],
    ['venta','Energía de venta','Suave','Vendedor'],
    ['ingenio','Ingenio / humor','Sobrio','Con chiste'],
  ];
  foreach ($sliders as [$k,$nm,$lo,$hi]): ?>
    <div class="slab"><span class="nm"><?= $h($nm) ?></span><span class="vl" id="vl_<?= $k ?>"></span></div>
    <input type="range" id="t_<?= $k ?>" name="t_<?= $k ?>" min="0" max="100" value="<?= (int)$T[$k] ?>" aria-label="<?= $h($nm) ?>">
    <div class="ends"><span><?= $h($lo) ?></span><span><?= $h($hi) ?></span></div>
  <?php endforeach; ?>

  <div class="voicebar" id="tvoice"></div>

  <div class="acts">
    <button type="button" class="gen" id="tgen">Genera 3 ejemplos con La Creativa</button>
    <button type="submit" class="save">Guardar mi tono</button>
  </div>
  <div class="res" id="tres"></div>
</form>

<script>
(function(){
  var ids=['boricua','formal','venta','ingenio'];
  var S={}; ids.forEach(function(k){S[k]=document.getElementById('t_'+k);});
  var bucket=function(v){return v<34?0:(v<67?1:2);};
  var LB={boricua:['Neutral','Con sabor','Bien boricua'],formal:['Casual','Equilibrado','Formal'],
          venta:['Suave','Invita','Vendedor'],ingenio:['Sobrio','Con chispa','Bien jocoso']};
  function paint(){
    ids.forEach(function(k){
      S[k].style.setProperty('--p',S[k].value+'%');
      document.getElementById('vl_'+k).textContent=LB[k][bucket(+S[k].value)];
    });
    var b=bucket(+S.boricua.value),f=bucket(+S.formal.value),v=bucket(+S.venta.value),g=bucket(+S.ingenio.value);
    var B=['en español neutral','con sabor boricua','bien de la isla'][b];
    var F=['bien casual','relajado pero pulido','profesional'][f];
    var V=['sin presión','con invitación suave','con punch de venta'][v];
    var G=['','',' y con chiste'][g];
    document.getElementById('tvoice').innerHTML='Tu voz suena: <b>'+B+'</b>, <b>'+F+'</b>, <b>'+V+'</b>'+(G?(', <b>'+G.trim()+'</b>'):'')+'.';
  }
  ids.forEach(function(k){S[k].addEventListener('input',function(){
    document.querySelectorAll('#tpresets .preset').forEach(function(p){p.classList.remove('on');});
    document.getElementById('t_preset').value=''; paint();
  });});
  document.querySelectorAll('#tpresets .preset').forEach(function(btn){
    btn.addEventListener('click',function(){
      var v=btn.dataset.v.split(',');
      ids.forEach(function(k,i){S[k].value=v[i];});
      document.querySelectorAll('#tpresets .preset').forEach(function(p){p.classList.remove('on');});
      btn.classList.add('on'); document.getElementById('t_preset').value=btn.dataset.p; paint();
    });
  });
  var saved=<?= json_encode($Tpreset) ?>;
  if(saved){var sb=document.querySelector('#tpresets .preset[data-p="'+saved+'"]'); if(sb)sb.classList.add('on');}
  paint();

  var gen=document.getElementById('tgen'), res=document.getElementById('tres');
  gen.addEventListener('click',function(){
    gen.disabled=true; var old=gen.textContent; gen.textContent='La Creativa está escribiendo…'; res.innerHTML='';
    var fd=new FormData(); fd.append('accion','tono_preview'); fd.append('csrf', <?= json_encode(csrf_token()) ?>);
    ids.forEach(function(k){fd.append('t_'+k,S[k].value);});
    fetch(location.pathname+location.search,{method:'POST',body:fd})
      .then(function(r){return r.json();})
      .then(function(d){
        gen.disabled=false; gen.textContent=old;
        if(!d.ok||!d.variaciones||!d.variaciones.length){
          res.innerHTML='<div class="vcard">No se pudo generar ahora. '+((d&&d.error)?String(d.error).replace(/</g,'&lt;'):'Intenta de nuevo.')+'</div>'; return;
        }
        var names=['Versión A','Versión B','Versión C'];
        res.innerHTML=d.variaciones.map(function(t,i){
          return '<div class="vcard" style="animation-delay:'+(i*0.08)+'s"><span class="vn">'+(names[i]||'Versión')+'</span>'+String(t).replace(/</g,'&lt;')+'</div>';
        }).join('');
      })
      .catch(function(){ gen.disabled=false; gen.textContent=old;
        res.innerHTML='<div class="vcard">Hubo un problema de conexión. Intenta de nuevo.</div>'; });
  });
})();
</script>
