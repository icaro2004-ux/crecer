<?php
// ============================================================
//  CRECER — Vista compartida de "El Primer Minuto"
//  includes/_primer_minuto_view.php   (referencia congelada + página real)
//
//  Espera un array $V con:
//   mode('real'|'demo'), negocio, pueblo, ini, grad, props[],
//   reveal_photo(url|null), confirm_url, home_url, csrf, devswitch(bool), perfil_key
// ============================================================
$hh = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$V += ['mode'=>'demo','reveal_photo'=>null,'confirm_url'=>'','home_url'=>'','csrf'=>'','devswitch'=>false,'perfil_key'=>''];
$chk   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
$spark = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v4M12 17v4M3 12h4M17 12h4M5.6 5.6l2.8 2.8M15.6 15.6l2.8 2.8M18.4 5.6l-2.8 2.8M8.4 15.6l-2.8 2.8"/></svg>';
$plus  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>';
?>
<style>
  *{box-sizing:border-box}
  body{background:var(--crema);font-family:var(--font-body);color:var(--tinta);margin:0}
  .sr{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0}
  .wrap{max-width:1080px;margin:0 auto;padding:22px 20px 60px}
  .top{display:flex;align-items:center;gap:11px;margin-bottom:34px}
  .top .av{width:38px;height:38px;border-radius:11px;background:var(--btn-grad);color:#fff;display:grid;place-items:center;
    font-family:var(--font-display);font-weight:600;font-size:17px;box-shadow:0 6px 16px -8px rgba(239,67,117,.6)}
  .top .nm{font-family:var(--font-display);font-weight:600;font-size:15.5px;color:var(--ink-soft);line-height:1.15}
  .top .nm small{display:block;font-family:var(--font-body);font-weight:400;font-size:12px;color:var(--muted)}
  .hero{text-align:center;max-width:640px;margin:0 auto 30px}
  .kick{display:inline-flex;align-items:center;gap:7px;font-family:var(--font-display);font-weight:600;font-size:11.5px;letter-spacing:.05em;text-transform:uppercase;
    color:var(--teal-dark,#00827e);background:color-mix(in srgb,var(--teal) 12%,#fff);padding:6px 13px;border-radius:999px;margin-bottom:15px}
  h1{font-family:var(--font-display);font-weight:600;font-size:clamp(26px,5.4vw,40px);line-height:1.08;letter-spacing:-.025em;color:var(--ink-soft);margin:0 0 11px;text-wrap:balance}
  h1 span{color:var(--magenta)}
  .lede{color:var(--muted);font-size:16px;line-height:1.5;margin:0 auto;max-width:40ch}
  .mesa{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:6px;align-items:stretch}
  .idea{position:relative;display:flex;flex-direction:column;background:var(--card);border:1px solid var(--line);border-radius:20px;padding:22px 20px 20px;
    box-shadow:0 2px 5px rgba(40,22,28,.04),0 24px 50px -34px rgba(40,22,28,.28);transition:transform .22s var(--ease),box-shadow .22s var(--ease),border-color .22s var(--ease)}
  .idea::before{content:"";position:absolute;left:20px;top:0;width:34px;height:3px;border-radius:0 0 3px 3px;background:var(--btn-grad);opacity:.9}
  .idea:hover{transform:translateY(-6px);box-shadow:0 6px 14px rgba(40,22,28,.07),0 34px 66px -30px rgba(40,22,28,.4);border-color:color-mix(in srgb,var(--magenta) 22%,var(--line))}
  .idea h3{font-family:var(--font-display);font-weight:600;font-size:19px;line-height:1.18;letter-spacing:-.015em;color:var(--ink-soft);margin:6px 0 10px;text-wrap:balance}
  .idea p{font-size:14.5px;line-height:1.55;color:var(--tinta);margin:0 0 20px;flex:1}
  .pick{margin-top:auto;border:0;cursor:pointer;width:100%;font-family:var(--font-display);font-weight:600;font-size:15px;color:#fff;
    background:var(--btn-grad);box-shadow:var(--btn-glow);padding:13px 14px;border-radius:13px;transition:transform .18s var(--ease),box-shadow .18s var(--ease)}
  .pick:hover{transform:translateY(-1px)}.pick:active{transform:translateY(1px);box-shadow:var(--btn-glow-active)}
  .pick:focus-visible,.dots button:focus-visible,.acts button:focus-visible,.addphoto:focus-visible{outline:3px solid color-mix(in srgb,var(--magenta) 55%,#fff);outline-offset:2px}
  .sign{text-align:right;font-family:var(--font-display);font-weight:500;font-size:13.5px;color:var(--muted);margin:16px 4px 0}
  @keyframes rise{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}
  .an{opacity:0;animation:rise .5s var(--ease) both}
  .a1{animation-delay:.05s}.a2{animation-delay:.13s}.d0{animation-delay:.22s}.d1{animation-delay:.32s}.d2{animation-delay:.42s}
  @media(prefers-reduced-motion:reduce){.an{animation:none;opacity:1}}
  /* MÓVIL: una por una, deslizando — con dots operables y foco por teclado */
  .dots{display:none}.hint{display:none}
  @media(max-width:820px){
    .wrap{padding:18px 0 40px}
    .top,.hero{padding:0 18px}
    .hero{margin-bottom:20px}
    .mesa{display:flex;gap:14px;overflow-x:auto;scroll-snap-type:x mandatory;padding:4px 18px 6px;-webkit-overflow-scrolling:touch;scrollbar-width:none}
    .mesa::-webkit-scrollbar{display:none}
    .idea{scroll-snap-align:center;flex:0 0 82vw;max-width:340px}
    .dots{display:flex;justify-content:center;gap:8px;margin-top:12px}
    .dots button{width:9px;height:9px;padding:0;border:0;border-radius:50%;background:var(--line);cursor:pointer;transition:.2s}
    .dots button[aria-current="true"]{background:var(--magenta);width:22px;border-radius:5px}
    .hint{display:block;text-align:center;color:var(--muted);font-size:12.5px;margin-top:9px}
    .sign{text-align:center;margin-top:14px}
  }
  /* LA ESCENA + REVEAL */
  .scene{position:fixed;inset:0;z-index:60;background:color-mix(in srgb,var(--crema) 88%,#fff);backdrop-filter:blur(3px);display:none;align-items:center;justify-content:center;padding:24px}
  .scene.on{display:flex}
  .scene-in{width:100%;max-width:440px;text-align:center}
  .work .dot{width:44px;height:44px;margin:0 auto 22px;border-radius:50%;background:var(--btn-grad);box-shadow:var(--btn-glow);display:grid;place-items:center;animation:pulse 1.5s var(--ease) infinite}
  .work .dot svg{width:22px;height:22px;color:#fff}
  @keyframes pulse{0%,100%{transform:scale(1);box-shadow:0 10px 26px -8px rgba(239,67,117,.5)}50%{transform:scale(1.08);box-shadow:0 16px 40px -8px rgba(239,67,117,.7)}}
  .beats{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:12px}
  .beats li{font-family:var(--font-display);font-weight:500;font-size:16px;color:var(--muted);opacity:0;transform:translateY(8px);display:flex;align-items:center;justify-content:center;gap:9px;transition:opacity .4s var(--ease),transform .4s var(--ease),color .3s}
  .beats li.show{opacity:1;transform:none}.beats li.done{color:var(--ink-soft)}
  .beats li b{width:16px;height:16px;flex:none;color:var(--palma);opacity:0;transition:opacity .3s}.beats li.done b{opacity:1}
  .reveal{display:none;width:100%;max-width:420px;text-align:center}
  .reveal.on{display:block;animation:rise .55s var(--ease) both}
  .reveal .rk{font-family:var(--font-display);font-weight:600;font-size:clamp(22px,5vw,30px);letter-spacing:-.02em;color:var(--ink-soft);margin:0 0 16px}
  .post{background:var(--card);border:1px solid var(--line);border-radius:22px;overflow:hidden;text-align:left;margin:0 auto;box-shadow:0 2px 6px rgba(40,22,28,.05),0 30px 64px -30px rgba(40,22,28,.34)}
  .post .ph{display:flex;align-items:center;gap:10px;padding:13px 15px}
  .post .pav{width:34px;height:34px;border-radius:50%;background:var(--btn-grad);color:#fff;display:grid;place-items:center;font-family:var(--font-display);font-weight:600;font-size:14px}
  .post .phn{font-family:var(--font-display);font-weight:600;font-size:14px;color:var(--ink-soft)}
  .post .phn small{display:block;color:var(--muted);font-weight:400;font-size:11px;font-family:var(--font-body)}
  /* superficie de imagen — intencional, nunca "recuadro técnico" */
  .post .img{position:relative;height:210px;background-size:cover;background-position:center}
  .post .img.neutral{background:var(--g),radial-gradient(120% 90% at 50% 12%,rgba(255,255,255,.55),transparent 60%)}
  .post .img.neutral .mark{position:absolute;inset:0;display:grid;place-items:center;font-family:var(--font-display);font-weight:700;font-size:76px;color:rgba(255,255,255,.62);text-shadow:0 2px 10px rgba(40,22,28,.12)}
  .addphoto{position:absolute;right:11px;bottom:11px;display:inline-flex;align-items:center;gap:6px;cursor:pointer;border:0;
    font-family:var(--font-display);font-weight:600;font-size:12.5px;color:var(--ink-soft);background:rgba(255,255,255,.86);backdrop-filter:blur(3px);
    padding:8px 12px;border-radius:999px;box-shadow:0 4px 14px -4px rgba(40,22,28,.35);transition:transform .16s var(--ease),background .16s}
  .addphoto svg{width:14px;height:14px}.addphoto:hover{transform:translateY(-1px);background:#fff}
  .addphoto.said{pointer-events:none;color:var(--muted)}
  .post .cap{padding:15px;font-size:14.5px;line-height:1.55;color:var(--tinta);white-space:pre-wrap}
  .acts{display:flex;gap:10px;margin:20px auto 0;max-width:420px}
  .acts button{flex:1;cursor:pointer;font-family:var(--font-display);font-weight:600;font-size:15px;padding:14px;border-radius:13px;transition:transform .18s var(--ease)}
  .acts .love{border:0;color:#fff;background:var(--btn-grad);box-shadow:var(--btn-glow)}.acts .love:active{transform:translateY(1px)}
  .acts .love[disabled]{opacity:.7;cursor:default}
  .acts .other{border:1px solid var(--line);background:var(--card);color:var(--ink-soft)}.acts .other:hover{border-color:var(--muted)}
  .devsw{position:fixed;left:12px;bottom:12px;z-index:70;font-size:11.5px;color:var(--muted);background:color-mix(in srgb,var(--card) 92%,#fff);border:1px solid var(--line);border-radius:999px;padding:5px 11px;font-family:var(--font-display)}
  .devsw a{color:var(--magenta);text-decoration:none;font-weight:600}.devsw a.here{color:var(--ink-soft)}
  /* Bottom sheet (móvil) / modal ligero (desktop) para subir foto real */
  .fotosheet{position:fixed;inset:0;z-index:80;display:none;background:rgba(40,22,28,.42)}
  .fotosheet.on{display:block}
  .fs-panel{position:absolute;background:var(--card);box-shadow:0 -12px 44px -10px rgba(40,22,28,.4)}
  @media(min-width:701px){ .fs-panel{left:50%;top:50%;transform:translate(-50%,-50%);width:390px;max-width:92vw;border-radius:22px;padding:26px 24px} }
  @media(max-width:700px){
    .fs-panel{left:0;right:0;bottom:0;border-radius:22px 22px 0 0;padding:22px 20px calc(22px + env(safe-area-inset-bottom))}
    .fotosheet.on .fs-panel{animation:sheetup .3s var(--ease) both}
  }
  @keyframes sheetup{from{transform:translateY(100%)}to{transform:none}}
  .fs-t{font-family:var(--font-display);font-weight:600;font-size:18px;color:var(--ink-soft);margin:0 0 5px}
  .fs-s{font-size:13.5px;color:var(--muted);margin:0 0 16px}
  .fs-drop{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:9px;cursor:pointer;
    border:2px dashed color-mix(in srgb,var(--magenta) 35%,var(--line));border-radius:16px;padding:26px 16px;
    background:color-mix(in srgb,var(--magenta) 4%,#fff);transition:border-color .2s,background .2s}
  .fs-drop:hover{border-color:var(--magenta);background:color-mix(in srgb,var(--magenta) 7%,#fff)}
  .fs-drop.busy{opacity:.6;pointer-events:none}
  .fs-plus{width:40px;height:40px;border-radius:50%;background:var(--btn-grad);color:#fff;display:grid;place-items:center;font-size:24px;font-family:var(--font-display);line-height:1}
  .fs-label{font-family:var(--font-display);font-weight:600;font-size:14.5px;color:var(--ink-soft)}
  .fs-err{color:var(--magenta);font-size:13px;margin:12px 0 0;text-align:center;min-height:1px}
  .fs-cancel{display:block;margin:16px auto 0;background:0;border:0;cursor:pointer;font-family:var(--font-display);font-weight:500;font-size:14px;color:var(--muted)}
</style>

<div class="wrap">
  <div class="top an a1">
    <div class="av"><?= $hh($V['ini']) ?></div>
    <div class="nm"><?= $hh($V['negocio']) ?><small><?= $hh($V['pueblo']) ?> · tu equipo de marketing</small></div>
  </div>

  <div class="hero">
    <div class="an a1"><span class="kick"><?= $spark ?> Ya conocemos tu negocio</span></div>
    <h1 class="an a2">Conocimos a <span><?= $hh($V['negocio']) ?></span>.</h1>
    <p class="lede an a2">Estas son las tres ideas que más nos convencen para arrancar.</p>
  </div>

  <p class="sr" id="mesalabel">Tres ideas para empezar. Desliza, usa Tab o los puntos de abajo para verlas todas.</p>
  <div class="mesa" role="list" aria-labelledby="mesalabel">
    <?php foreach ($V['props'] as $i => $p): ?>
      <div class="idea an d<?= $i ?>" role="listitem" aria-label="Idea <?= $i+1 ?> de <?= count($V['props']) ?>: <?= $hh($p['titulo']) ?>">
        <h3><?= $hh($p['titulo']) ?></h3>
        <p><?= $hh($p['recomendacion']) ?></p>
        <button class="pick" data-i="<?= $i ?>" data-clave="<?= $hh($p['id']) ?>" data-cap="<?= $hh($p['caption']) ?>"><?= $hh($p['cta']) ?></button>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="dots" role="tablist" aria-label="Ir a una idea">
    <?php foreach ($V['props'] as $i => $p): ?><button type="button" data-go="<?= $i ?>" aria-current="<?= $i===0?'true':'false' ?>" aria-label="Ver idea <?= $i+1 ?>: <?= $hh($p['titulo']) ?>"></button><?php endforeach; ?>
  </div>
  <div class="hint">desliza para ver las otras →</div>
  <div class="sign an d2">— El Corillo</div>
</div>

<div class="scene" id="scene" role="dialog" aria-label="Preparando tu propuesta">
  <div class="scene-in">
    <div class="work" id="work">
      <div class="dot"><?= $spark ?></div>
      <ul class="beats" id="beats">
        <!-- Lenguaje honesto: el motor (Voice DNA) NO está conectado en C1.
             En C2, el beat 3 puede pasar a "Escribiéndolo como hablas tú…". -->
        <li><b><?= $chk ?></b>Repasando lo que nos contaste…</li>
        <li><b><?= $chk ?></b>Buscando la mejor forma de contarlo…</li>
        <li><b><?= $chk ?></b>Dándole el tono correcto…</li>
        <li><b><?= $chk ?></b>Dándole el último toque…</li>
      </ul>
    </div>
    <div class="reveal" id="reveal">
      <p class="rk">Así empezaríamos nosotros.</p>
      <div class="post">
        <div class="ph">
          <div class="pav"><?= $hh($V['ini']) ?></div>
          <div class="phn"><?= $hh($V['negocio']) ?><small><?= $hh($V['pueblo']) ?></small></div>
        </div>
        <?php if (!empty($V['reveal_photo'])): ?>
          <div class="img has-photo" style="background-image:url('<?= $hh($V['reveal_photo']) ?>')" role="img" aria-label="Foto de <?= $hh($V['negocio']) ?>"></div>
        <?php else: ?>
          <div class="img neutral" style="--g:<?= $hh($V['grad']) ?>">
            <span class="mark" aria-hidden="true"><?= $hh($V['ini']) ?></span>
            <button type="button" class="addphoto" id="addphoto"><?= $plus ?> Añadir una foto real</button>
          </div>
        <?php endif; ?>
        <div class="cap" id="revcap"></div>
      </div>
      <div class="acts">
        <button class="other" id="other">Prefiero otra idea</button>
        <button class="love" id="love">Me encanta</button>
      </div>
    </div>
  </div>
</div>

<div class="fotosheet" id="fotosheet" role="dialog" aria-modal="true" aria-label="Añadir una foto real">
  <div class="fs-panel">
    <p class="fs-t">Añade una foto real</p>
    <p class="fs-s">Se verá en tu propuesta. JPG, PNG o WebP · hasta 12 MB.</p>
    <label class="fs-drop" id="fsDrop">
      <input type="file" id="fsInput" accept="image/jpeg,image/png,image/webp" hidden>
      <span class="fs-plus">+</span>
      <span class="fs-label" id="fsLabel">Elegir una foto</span>
    </label>
    <p class="fs-err" id="fsErr"></p>
    <button type="button" class="fs-cancel" id="fsCancel">Ahora no</button>
  </div>
</div>

<?php if (!empty($V['devswitch'])): ?>
<div class="devsw">prototipo:
  <a class="<?= $V['perfil_key']==='reposteria'?'here':'' ?>" href="?perfil=reposteria">repostería</a> ·
  <a class="<?= $V['perfil_key']==='barberia'?'here':'' ?>" href="?perfil=barberia">barbería</a>
</div>
<?php endif; ?>

<script>
(function(){
  var MODE=<?= json_encode($V['mode']) ?>, CONFIRM=<?= json_encode($V['confirm_url']) ?>,
      HOME=<?= json_encode($V['home_url']) ?>, CSRF=<?= json_encode($V['csrf']) ?>;
  var scene=document.getElementById('scene'), work=document.getElementById('work'),
      reveal=document.getElementById('reveal'), beats=[].slice.call(document.querySelectorAll('#beats li')),
      revcap=document.getElementById('revcap'), love=document.getElementById('love'),
      reduce=matchMedia('(prefers-reduced-motion:reduce)').matches, timers=[], chosen=null;
  function clear(){ timers.forEach(clearTimeout); timers=[]; }
  function play(clave,cap){
    chosen=clave; revcap.textContent=cap;
    if(love){ love.disabled=false; love.textContent='Me encanta'; }
    scene.classList.add('on'); work.style.display=''; reveal.classList.remove('on');
    beats.forEach(function(b){ b.classList.remove('show','done'); });
    if(reduce){ show(); return; }
    var t=250;
    beats.forEach(function(b){
      timers.push(setTimeout(function(){ b.classList.add('show'); },t));
      timers.push(setTimeout(function(){ b.classList.add('done'); },t+560));
      t+=780;
    });
    timers.push(setTimeout(show, t+380));
  }
  function show(){ work.style.display='none'; reveal.classList.add('on'); }
  function close(){ clear(); scene.classList.remove('on'); }
  document.querySelectorAll('.pick').forEach(function(btn){
    btn.addEventListener('click', function(){ clear(); play(btn.getAttribute('data-clave'), btn.getAttribute('data-cap')); });
  });
  document.getElementById('other').addEventListener('click', close);
  scene.addEventListener('click', function(e){ if(e.target===scene) close(); });
  document.addEventListener('keydown', function(e){ if(e.key==='Escape' && scene.classList.contains('on')) close(); });
  // "Me encanta": en real guarda la decisión y va al Home; en demo solo cierra.
  if(love) love.addEventListener('click', function(){
    if(MODE!=='real'){ close(); return; }
    if(!chosen) return;
    love.disabled=true; love.textContent='Guardando…';
    fetch(CONFIRM, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:'angulo='+encodeURIComponent(chosen)+'&csrf='+encodeURIComponent(CSRF)})
      .then(function(r){ return r.json(); })
      .then(function(d){ if(d && d.ok){ location.href=HOME; } else { love.disabled=false; love.textContent='Reintentar'; } })
      .catch(function(){ love.disabled=false; love.textContent='Reintentar'; });
  });
  // "Añadir una foto real" — sube una foto de verdad (reusa el mecanismo de fotos de la marca).
  // En demo (sin sesión) no hay backend: queda como afordancia informativa.
  var add=document.getElementById('addphoto'), sheet=document.getElementById('fotosheet'),
      fsInput=document.getElementById('fsInput'), fsErr=document.getElementById('fsErr'),
      fsDrop=document.getElementById('fsDrop'), fsLabel=document.getElementById('fsLabel'),
      fsCancel=document.getElementById('fsCancel');
  function openSheet(){ if(fsErr) fsErr.textContent=''; sheet.classList.add('on'); }
  function closeSheet(){ sheet.classList.remove('on'); }
  if(add) add.addEventListener('click', function(){
    if(MODE!=='real'){ add.classList.add('said'); add.textContent='La podrás subir pronto'; return; }
    openSheet();
  });
  if(fsCancel) fsCancel.addEventListener('click', closeSheet);
  if(sheet) sheet.addEventListener('click', function(e){ if(e.target===sheet) closeSheet(); });
  function setPhoto(url){
    var img=document.querySelector('.post .img'); if(!img) return;
    img.classList.remove('neutral'); img.classList.add('has-photo');
    img.style.backgroundImage="url('"+url+"')";
    var mk=img.querySelector('.mark'); if(mk) mk.remove();
    if(add && add.parentNode) add.parentNode.removeChild(add);   // ya no hace falta el pill
  }
  if(fsInput) fsInput.addEventListener('change', function(){
    var f=fsInput.files && fsInput.files[0]; if(!f) return;
    fsErr.textContent='';
    if(['image/jpeg','image/png','image/webp'].indexOf(f.type)<0){ fsErr.textContent='Usa una imagen JPG, PNG o WebP.'; fsInput.value=''; return; }
    if(f.size>12*1024*1024){ fsErr.textContent='La imagen es muy grande (máx 12 MB).'; fsInput.value=''; return; }
    fsDrop.classList.add('busy'); fsLabel.textContent='Subiendo…';
    var fd=new FormData(); fd.append('accion','foto'); fd.append('csrf',CSRF); fd.append('foto',f);
    fetch(CONFIRM,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
      fsDrop.classList.remove('busy'); fsLabel.textContent='Elegir una foto'; fsInput.value='';
      if(d && d.ok && d.url){ setPhoto(d.url); closeSheet(); }
      else { fsErr.textContent=(d && d.err) || 'No se pudo subir. Intenta otra vez.'; }
    }).catch(function(){ fsDrop.classList.remove('busy'); fsLabel.textContent='Elegir una foto'; fsInput.value=''; fsErr.textContent='Se cayó la conexión. Intenta otra vez.'; });
  });
  // dots + swipe (móvil): operables por teclado y sincronizados con el scroll
  var mesa=document.querySelector('.mesa'), dots=[].slice.call(document.querySelectorAll('.dots button')), cards=[].slice.call(document.querySelectorAll('.idea'));
  dots.forEach(function(d){ d.addEventListener('click', function(){ var i=+d.getAttribute('data-go'); if(cards[i]) cards[i].scrollIntoView({behavior:'smooth',inline:'center',block:'nearest'}); }); });
  if(mesa) mesa.addEventListener('scroll', function(){
    var c=mesa.scrollLeft+mesa.clientWidth/2, best=0, bd=1e9;
    cards.forEach(function(el,i){ var x=el.offsetLeft+el.clientWidth/2, dd=Math.abs(x-c); if(dd<bd){bd=dd;best=i;} });
    dots.forEach(function(d,i){ d.setAttribute('aria-current', i===best?'true':'false'); });
  });
})();
</script>
