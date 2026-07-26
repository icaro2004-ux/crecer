<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Consejos & Finanzas  panel/finanzas.php
//  Consejos de negocio/finanzas (IA, ligados a las métricas) + una
//  calculadora simple de ganancia/contribuciones. GUÍA GENERAL, no
//  asesoría contributiva. La calculadora es client-side (localStorage).
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/suscripcion.php';
require __DIR__ . '/../includes/metricas.php';
require_once __DIR__ . '/../includes/agentes.php';
requiere_login();
$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }
$marca_id = (int)$marca['id'];
$BASE = '/crecer/panel';

// ── Números de redes (para que los consejos se apoyen en datos reales) ──
$prod    = metricas_produccion($pdo, $marca_id);
$tot_ins = metricas_totales_insights($pdo, $marca_id);
$pubs    = metricas_publicaciones($pdo, $marca_id, 30);
$insights = metricas_insights_de_posts($pdo, $marca_id, array_column($pubs, 'id'));
$cap_by = []; foreach ($pubs as $pp) $cap_by[(int)$pp['id']] = (string)($pp['caption'] ?? '');
$guardados = 0; $top = ['alcance'=>0,'caption'=>''];
foreach ($insights as $pid => $rows) {
    $palc = 0;
    foreach ($rows as $row) { $guardados += (int)($row['guardados'] ?? 0); $palc += (int)($row['alcance'] ?? 0); }
    if ($palc > $top['alcance']) $top = ['alcance'=>$palc, 'caption'=>mb_substr($cap_by[(int)$pid] ?? '', 0, 100)];
}
$eng = $tot_ins['alcance'] > 0 ? round($tot_ins['interacciones'] / $tot_ins['alcance'] * 100, 1) : 0.0;
$datos_fz = [
  'posts_publicados'      => (int)$prod['publicados_mes'],
  'alcance'               => (int)$tot_ins['alcance'],
  'interacciones'         => (int)$tot_ins['interacciones'],
  'engagement_pct'        => $eng,
  'guardados'             => $guardados,
  'post_estrella_alcance' => (int)$top['alcance'],
  'post_estrella'         => $top['caption'],
];

// ── AJAX: los consejos del Estratega (async; el front los pide al cargar) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'consejos') {
    header('Content-Type: application/json; charset=utf-8');
    if (function_exists('csrf_ok') && !csrf_ok()) { echo json_encode(['ok'=>false]); exit; }
    try { echo json_encode(['ok'=>true, 'c'=>consejos_finanzas($pdo, $marca_id, $datos_fz)], JSON_UNESCAPED_UNICODE); }
    catch (Throwable $e) { echo json_encode(['ok'=>false, 'err'=>substr($e->getMessage(), 0, 140)]); }
    exit;
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$estratega_nombre = function_exists('equipo_nombre') ? equipo_nombre($marca, 'estratega') : 'El Estratega';
$active = 'finanzas';
$page_title = 'Finanzas';
$guia = null;
require __DIR__ . '/_shell.php';
?>
<style>
  .content{max-width:900px}
  .asis-fab{display:none}
  .fz{max-width:860px;margin:0 auto;font-family:var(--font-body)}
  .fz-h1{font-family:var(--font-display);font-weight:800;letter-spacing:-.02em;font-size:clamp(23px,3.6vw,30px);margin:2px 0 4px}
  .fz-lead{color:var(--muted);font-size:14.5px;margin:0 0 22px;max-width:60ch;line-height:1.5}
  .fz-sec{font-size:12px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);margin:30px 0 13px;display:flex;align-items:center;gap:10px}
  .fz-sec::after{content:"";flex:1;height:1px;background:var(--line)}

  /* Consejo del mes */
  .fz-adv{background:var(--card);border:1px solid var(--line);border-radius:20px;padding:20px 20px 18px;box-shadow:var(--shadow-sm);position:relative;overflow:hidden}
  .fz-adv::before{content:"";position:absolute;left:0;top:0;bottom:0;width:5px;background:linear-gradient(180deg,var(--magenta),var(--teal))}
  .fz-adv .who{display:flex;align-items:center;gap:9px;font-size:11.5px;font-weight:800;text-transform:uppercase;letter-spacing:.03em;color:var(--muted);margin-bottom:10px}
  .fz-adv .who .dot{width:27px;height:27px;border-radius:50%;background:color-mix(in srgb,var(--magenta) 12%,#fff);color:var(--magenta);display:grid;place-items:center}
  .fz-adv .who svg{width:15px;height:15px}
  .fz-adv p{margin:0 0 12px;font-size:15.5px;line-height:1.6;color:var(--tinta)}
  .fz-tie{display:inline-flex;align-items:center;gap:7px;font-size:12px;font-weight:700;color:var(--teal-700,#00827e);background:color-mix(in srgb,var(--teal) 12%,#fff);border-radius:999px;padding:5px 12px}
  .fz-tie svg{width:13px;height:13px}
  .fz-load{color:var(--muted);font-style:italic;font-size:14px;display:inline-flex;align-items:center;gap:9px}
  .fz-load .sp{width:16px;height:16px;border-radius:50%;border:2px solid rgba(0,0,0,.12);border-top-color:var(--magenta);animation:fzspin .8s linear infinite}
  @keyframes fzspin{to{transform:rotate(360deg)}}

  /* Grid de consejos */
  .fz-cards{display:grid;grid-template-columns:repeat(2,1fr);gap:14px}
  @media(max-width:640px){.fz-cards{grid-template-columns:1fr}}
  .fz-card{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:16px 16px 15px;box-shadow:var(--shadow-sm);display:flex;flex-direction:column;gap:8px;min-height:120px}
  .fz-card .chip{width:38px;height:38px;border-radius:11px;display:grid;place-items:center;background:var(--crema-2);color:var(--muted)}
  .fz-card .chip svg{width:20px;height:20px}
  .fz-mag .chip{background:color-mix(in srgb,var(--magenta) 12%,#fff);color:var(--magenta)}
  .fz-teal .chip{background:color-mix(in srgb,var(--teal) 12%,#fff);color:var(--teal)}
  .fz-amb .chip{background:color-mix(in srgb,var(--amber,#c78a16) 15%,#fff);color:var(--amber-ink,#9a6b00)}
  .fz-pur .chip{background:#efeaff;color:#7c58e8}
  .fz-card h3{margin:2px 0 0;font-size:15px;font-weight:800;letter-spacing:-.01em;color:var(--tinta);font-family:var(--font-display)}
  .fz-card p{margin:0;font-size:13.5px;color:var(--ink-soft,#4a444c);line-height:1.5}
  .fz-card .from{font-size:11px;font-weight:700;color:var(--muted);margin-top:auto;display:inline-flex;align-items:center;gap:6px;text-transform:lowercase}
  /* Nav del wizard (solo móvil) */
  .fz-wnav{display:none;align-items:center;justify-content:center;gap:14px;margin-top:14px}
  .fz-wnav button{width:38px;height:38px;border-radius:50%;border:1.5px solid var(--line);background:var(--card);color:var(--tinta);font-size:20px;line-height:1;cursor:pointer;display:grid;place-items:center}
  .fz-wnav button:disabled{opacity:.3;pointer-events:none}
  .fz-dots{display:flex;gap:6px}
  .fz-dots i{width:7px;height:7px;border-radius:50%;background:var(--line);transition:width .3s,background .3s}
  .fz-dots i.on{width:20px;background:linear-gradient(90deg,var(--coral),var(--magenta))}
  @media(max-width:640px){
    .fz-cards.wiz{display:block;position:relative;overflow:hidden;transition:height .35s cubic-bezier(.22,1,.36,1)}
    .fz-cards.wiz .fz-card{width:100%;min-height:0}
    .fz-wnav.on{display:flex}
  }

  /* Calculadora */
  .fz-calc{background:var(--card);border:1px solid var(--line);border-radius:22px;box-shadow:var(--shadow-sm);overflow:hidden}
  .fz-cg{display:grid;grid-template-columns:1fr 1fr}
  @media(max-width:680px){.fz-cg{grid-template-columns:1fr}}
  .fz-in{padding:20px 20px 22px;border-right:1px solid var(--line)}
  @media(max-width:680px){.fz-in{border-right:0;border-bottom:1px solid var(--line)}}
  .fz-in h3{margin:0 0 3px;font-size:16px;font-weight:800;font-family:var(--font-display)}
  .fz-in .sub{font-size:12.5px;color:var(--muted);margin:0 0 16px}
  .fld{margin-bottom:14px}
  .fld label{display:block;font-size:12.5px;font-weight:700;color:var(--ink-soft,#4a444c);margin-bottom:6px}
  .fld .box{display:flex;align-items:center;background:var(--crema-2);border:1.5px solid var(--line);border-radius:12px;padding:0 12px;transition:border-color .15s}
  .fld .box:focus-within{border-color:var(--magenta)}
  .fld .box .pre{color:var(--muted);font-weight:700;font-size:15px}
  .fld input{flex:1;border:0;background:transparent;font-family:inherit;font-size:16px;font-weight:700;color:var(--tinta);padding:12px 8px;outline:none;width:100%;font-variant-numeric:tabular-nums}
  .fld input.pct{text-align:right}
  .fld .post{color:var(--muted);font-weight:700;font-size:14px}
  .hint{font-size:11.5px;color:var(--muted);margin-top:-6px}
  .fz-out{padding:20px;display:flex;flex-direction:column;gap:14px;background:linear-gradient(180deg,color-mix(in srgb,var(--teal) 6%,var(--card)),var(--card))}
  .fz-big{text-align:center;padding:6px 0 2px}
  .fz-big .l{font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)}
  .fz-big .v{font-family:var(--font-display);font-weight:800;font-size:clamp(38px,9vw,52px);letter-spacing:-.03em;line-height:1;margin-top:6px;color:var(--teal-700,#00827e);font-variant-numeric:tabular-nums}
  .fz-big .v.neg{color:var(--magenta)}
  .fz-rows{display:flex;flex-direction:column;gap:1px;border-radius:14px;overflow:hidden;border:1px solid var(--line)}
  .fz-r{display:flex;justify-content:space-between;align-items:center;padding:11px 14px;background:var(--card);font-size:13.5px}
  .fz-r .k{color:var(--ink-soft,#4a444c);font-weight:600;display:flex;align-items:center;gap:8px}
  .fz-r .k i{width:9px;height:9px;border-radius:3px;display:inline-block}
  .fz-r .v{font-weight:800;font-variant-numeric:tabular-nums}
  .fz-r.tax .v{color:var(--amber-ink,#9a6b00)} .fz-r.cost .v{color:var(--magenta)}
  .fz-stack{height:12px;border-radius:99px;overflow:hidden;display:flex;background:var(--line)}
  .fz-stack i{height:100%}
  .fz-mrow{display:flex;justify-content:space-between;font-size:12.5px;color:var(--muted);font-weight:700}
  .fz-mrow b{color:var(--tinta);font-variant-numeric:tabular-nums}

  .fz-foot{margin-top:24px;background:var(--card);border:1px dashed var(--line);border-radius:16px;padding:15px 18px;font-size:12.5px;color:var(--ink-soft,#4a444c);line-height:1.55}
  .fz-foot b{color:var(--tinta)}
</style>

<div class="fz">
  <h1 class="fz-h1">Consejos & Finanzas</h1>
  <p class="fz-lead">Guía sencilla, ligada a tus números de redes, para que el negocio no solo se vea bien — también dé dinero.</p>

  <!-- CONSEJO DEL MES -->
  <div class="fz-sec"><?= ico('lightbulb') ?> El consejo del mes</div>
  <div class="fz-adv">
    <div class="who"><span class="dot"><?= ico('sparkles') ?></span> <?= $h($estratega_nombre) ?> · basado en tu mes</div>
    <p id="fzAdv"><span class="fz-load"><span class="sp"></span> El Estratega está mirando tus números…</span></p>
    <span class="fz-tie" id="fzTie" style="display:none"><?= ico('chart') ?><span></span></span>
  </div>

  <!-- CONSEJOS -->
  <div class="fz-sec"><?= ico('compass') ?> Consejos accionables</div>
  <div class="fz-cards" id="fzCards">
    <?php for ($i=0;$i<4;$i++): ?>
      <div class="fz-card"><span class="fz-load"><span class="sp"></span> pensando…</span></div>
    <?php endfor; ?>
  </div>
  <div class="fz-wnav" id="fzWnav">
    <button type="button" id="fzPrev" aria-label="Anterior">‹</button>
    <span class="fz-dots" id="fzDots"></span>
    <button type="button" id="fzNext" aria-label="Siguiente">›</button>
  </div>

  <!-- CALCULADORA -->
  <div class="fz-sec"><?= ico('dollar') ?> Calculadora · ¿Cuánto te queda?</div>
  <div class="fz-calc">
    <div class="fz-cg">
      <div class="fz-in">
        <h3>Tus números del mes</h3>
        <p class="sub">Escríbelos y abajo ves lo que de verdad te queda.</p>
        <div class="fld"><label>Ventas del mes</label>
          <div class="box"><span class="pre">$</span><input id="fzVentas" type="number" inputmode="decimal" placeholder="0"></div></div>
        <div class="fld"><label>Costos y gastos (ingredientes, empaque, delivery…)</label>
          <div class="box"><span class="pre">$</span><input id="fzCostos" type="number" inputmode="decimal" placeholder="0"></div></div>
        <div class="fld"><label>Aparta para contribuciones</label>
          <div class="box"><input id="fzTax" class="pct" type="number" inputmode="decimal" value="15"><span class="post">%</span></div>
          <p class="hint">Estimado para Hacienda. Ajústalo con tu contador.</p></div>
      </div>
      <div class="fz-out">
        <div class="fz-big"><div class="l">Te queda limpio</div><div class="v" id="fzNeto">$0</div></div>
        <div class="fz-stack" id="fzStack"><i style="background:var(--magenta)"></i><i style="background:var(--amber,#c78a16)"></i><i style="background:var(--teal)"></i></div>
        <div class="fz-rows">
          <div class="fz-r"><span class="k">Ventas</span><span class="v" id="fzRVentas">$0</span></div>
          <div class="fz-r cost"><span class="k"><i style="background:var(--magenta)"></i>Costos y gastos</span><span class="v" id="fzRCostos">$0</span></div>
          <div class="fz-r tax"><span class="k"><i style="background:var(--amber,#c78a16)"></i>Reserva contribuciones</span><span class="v" id="fzRTax">$0</span></div>
          <div class="fz-r"><span class="k"><i style="background:var(--teal)"></i>Ganancia limpia</span><span class="v" id="fzRNeto">$0</span></div>
        </div>
        <div class="fz-mrow"><span>Margen del negocio</span><b id="fzMargen">0%</b></div>
        <div class="fz-mrow"><span>Para cubrir gastos necesitas vender</span><b id="fzBE">$0</b></div>
      </div>
    </div>
  </div>

  <div class="fz-foot">
    <b>Cómo funciona:</b> los consejos los escribe <?= $h($estratega_nombre) ?> leyendo tus métricas reales (alcance, engagement, guardados), en tu tono. La calculadora es orientativa para que veas tu ganancia real de un vistazo.
    <br><br><b>Nota:</b> esto es <b>guía general, no asesoría contributiva</b>. Las contribuciones, IVU (11.5% en PR) y patente dependen de tu caso — para lo oficial, consulta un contador o Hacienda.
  </div>
</div>

<script>
(function(){
  // ── Consejos del Estratega (IA) ──
  var CSRF=<?= json_encode(csrf_token()) ?>;
  var ICON={0:'<?= addslashes(ico('dollar')) ?>',1:'<?= addslashes(ico('wallet')) ?>',2:'<?= addslashes(ico('chart')) ?>',3:'<?= addslashes(ico('briefcase')) ?>'};
  var TONE=['mag','amb','teal','pur'];
  function esc(s){var d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;}
  // En MÓVIL, los cards de consejos se vuelven un wizard con cross-fade (desktop = grid)
  function initFzWizard(){
    if(!window.matchMedia||!window.matchMedia('(max-width:640px)').matches) return;
    var cont=document.getElementById('fzCards'), cards=[].slice.call(cont.querySelectorAll('.fz-card'));
    if(cards.length<2) return;
    cont.classList.add('wiz');
    document.getElementById('fzWnav').classList.add('on');
    var dotsW=document.getElementById('fzDots'), prevB=document.getElementById('fzPrev'), nextB=document.getElementById('fzNext'), dots=[];
    dotsW.innerHTML=''; cards.forEach(function(_,i){var d=document.createElement('i');if(i===0)d.className='on';dotsW.appendChild(d);dots.push(d);});
    var cur=0, REDUCE=window.matchMedia('(prefers-reduced-motion: reduce)').matches, x0=null, y0=0, lock=null;
    cards.forEach(function(c,i){ c.style.display=i===0?'':'none'; });
    function fit(){ var c=cards[cur]; if(c) cont.style.height=c.offsetHeight+'px'; }
    function paint(){ dots.forEach(function(d,x){d.classList.toggle('on',x===cur);}); prevB.disabled=cur<=0; nextB.disabled=cur>=cards.length-1; }
    function go(t){ t=Math.max(0,Math.min(cards.length-1,t)); if(t===cur){fit();return;} var dir=t>cur?1:-1,a=cards[cur],b=cards[t]; cur=t; paint();
      if(REDUCE){a.style.display='none';b.style.display='';fit();return;}
      a.style.transition='opacity .2s ease, transform .2s ease';a.style.opacity='0';a.style.transform='translateX('+(-14*dir)+'px)';
      setTimeout(function(){a.style.display='none';a.style.transition='';a.style.transform='';a.style.opacity='';
        b.style.display='';b.style.opacity='0';b.style.transform='translateX('+(16*dir)+'px)';cont.style.height=b.offsetHeight+'px';
        requestAnimationFrame(function(){b.style.transition='opacity .34s cubic-bezier(.22,1,.36,1), transform .34s cubic-bezier(.22,1,.36,1)';b.style.opacity='1';b.style.transform='none';});
      },190);
    }
    prevB.onclick=function(){go(cur-1);}; nextB.onclick=function(){go(cur+1);};
    cont.addEventListener('touchstart',function(e){var t=e.touches[0];x0=t.clientX;y0=t.clientY;lock=null;},{passive:true});
    cont.addEventListener('touchmove',function(e){if(x0===null)return;var t=e.touches[0],dx=t.clientX-x0,dy=t.clientY-y0;if(lock===null&&(Math.abs(dx)>8||Math.abs(dy)>8))lock=Math.abs(dx)>Math.abs(dy)?'x':'y';},{passive:true});
    cont.addEventListener('touchend',function(e){if(x0===null||lock!=='x'){x0=null;return;}var dx=e.changedTouches[0].clientX-x0;if(dx<-45)go(cur+1);else if(dx>45)go(cur-1);x0=null;},{passive:true});
    window.addEventListener('resize',fit);
    paint(); fit();
  }
  var fd=new FormData(); fd.append('accion','consejos'); fd.append('csrf',CSRF);
  fetch(location.pathname+location.search,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
    var adv=document.getElementById('fzAdv'), tie=document.getElementById('fzTie'), cards=document.getElementById('fzCards');
    if(!d.ok||!d.c){ adv.textContent='No pude traer los consejos ahora. Recarga en un momento.'; cards.innerHTML=''; return; }
    var c=d.c;
    adv.textContent = c.consejo_mes || '—';
    if(c.metrica_mes){ tie.querySelector('span').textContent='Se conecta con: '+c.metrica_mes; tie.style.display='inline-flex'; }
    var list=(c.consejos||[]).slice(0,4);
    cards.innerHTML = list.map(function(x,i){
      return '<div class="fz-card fz-'+(TONE[i]||'mag')+'">'+
        '<span class="chip">'+(ICON[i]||ICON[0])+'</span>'+
        '<h3>'+esc(x.titulo)+'</h3>'+
        '<p>'+esc(x.texto)+'</p>'+
        (x.metrica?('<span class="from">'+esc(x.metrica)+'</span>'):'')+
      '</div>';
    }).join('') || '<div class="fz-card"><p>Publica y conecta tus redes para consejos personalizados.</p></div>';
    initFzWizard();
  }).catch(function(){ document.getElementById('fzAdv').textContent='Se cayó la conexión al traer los consejos.'; document.getElementById('fzCards').innerHTML=''; });

  // ── Calculadora (client-side + recuerda tus valores) ──
  var g=function(id){return document.getElementById(id);};
  var f=function(n){return '$'+(Math.round(n)).toLocaleString('en-US');};
  var V=g('fzVentas'), C=g('fzCostos'), T=g('fzTax');
  try{ var saved=JSON.parse(localStorage.getItem('fz_calc')||'{}');
    if(saved.v!=null)V.value=saved.v; if(saved.c!=null)C.value=saved.c; if(saved.t!=null)T.value=saved.t; }catch(e){}
  function calc(){
    var ventas=+V.value||0, costos=+C.value||0, taxpct=+T.value||0;
    var bruto=Math.max(0,ventas-costos), tax=bruto*taxpct/100, neto=bruto-tax;
    g('fzRVentas').textContent=f(ventas);
    g('fzRCostos').textContent='– '+f(costos);
    g('fzRTax').textContent='– '+f(tax);
    g('fzRNeto').textContent=f(neto);
    var ne=g('fzNeto'); ne.textContent=f(neto); ne.classList.toggle('neg',neto<=0 && ventas>0);
    g('fzMargen').textContent=(ventas>0?Math.round(neto/ventas*100):0)+'%';
    g('fzBE').textContent=f(costos);
    var tot=Math.max(1,ventas), s=g('fzStack').children;
    s[0].style.width=(costos/tot*100)+'%'; s[1].style.width=(tax/tot*100)+'%'; s[2].style.width=(Math.max(0,neto)/tot*100)+'%';
    try{ localStorage.setItem('fz_calc',JSON.stringify({v:V.value,c:C.value,t:T.value})); }catch(e){}
  }
  [V,C,T].forEach(function(el){ el.addEventListener('input',calc); });
  calc();
})();
</script>

<?php require __DIR__ . '/_shell_foot.php'; ?>
