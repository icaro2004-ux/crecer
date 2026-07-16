<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — LANDING V4 "El Corillo ya está trabajando"
//  crecer.php · /crecer
//
//  5 bloques: (1) pregunta → (2) reveal del Home personalizado →
//  (3) voz → (4) resultados DEMOSTRATIVOS honestos → (5) CTA de pertenencia.
//
//  Personalización REAL = solo el nombre del negocio (input del visitante).
//  Todo lo demás (créditos, propuesta, caption, números) está marcado como
//  DEMOSTRACIÓN. Cero Gemini, cero generación de imágenes, cero llamadas
//  externas. Solo plantillas estáticas + variables sanitizadas.
// ============================================================

// Sanitiza el nombre del negocio (mostrado y reenviado por query string).
function crecer_clean_negocio(string $s): string {
    $s = strip_tags($s);
    $s = preg_replace('/\s+/u', ' ', trim($s)) ?? '';
    return mb_substr($s, 0, 60);
}

$negocio  = crecer_clean_negocio($_GET['negocio'] ?? '');
$has_name = $negocio !== '';
$biz      = $has_name ? $negocio : 'tu negocio';   // token visible por defecto
$h        = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Crecer · El Corillo que trabaja por tu negocio</title>
<meta name="description" content="Escribe el nombre de tu negocio y conoce al Corillo: un equipo de marketing que trabaja por ti.">
<link rel="icon" type="image/png" href="/crecer/assets/brand/encuentralo-crecer-pin-drop.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  /* Hereda el lenguaje visual del producto (encuentralo-ui.css), aquí autónomo. */
  :root{
    --crema:#F7F5F1; --card:#fff; --tinta:#231F20; --ink:#4A434F; --muted:#6E6A67; --line:#E9E7E4;
    --magenta:#EF4375; --coral:#FF6B3D; --teal:#00A49F; --palma:#16b86a;
    --disp:'Poppins',sans-serif; --body:'Plus Jakarta Sans',system-ui,sans-serif;
    --grad:linear-gradient(135deg,var(--coral),var(--magenta));
    --glow:0 1px 0 rgba(255,255,255,.28) inset,0 10px 22px -8px rgba(239,67,117,.5),0 22px 50px -18px rgba(239,67,117,.42);
    --glow-active:0 1px 0 rgba(255,255,255,.2) inset,0 5px 13px -6px rgba(239,67,117,.5);
    --ease:cubic-bezier(.22,1,.36,1);
  }
  *{box-sizing:border-box;margin:0;padding:0}
  html{scroll-behavior:smooth}
  body{font-family:var(--body);color:var(--tinta);background:var(--crema);line-height:1.5;
    background-image:radial-gradient(110% 40% at 100% 0%,rgba(239,67,117,.045),transparent 60%),radial-gradient(80% 38% at 0% 2%,rgba(0,164,159,.035),transparent 58%);
    -webkit-font-smoothing:antialiased;overflow-x:hidden}
  .wrap{width:min(600px,calc(100% - 40px));margin-inline:auto}
  a{color:inherit}
  :where(a,button,input):focus-visible{outline:none;box-shadow:0 0 0 3px color-mix(in srgb,var(--magenta) 32%,transparent)}
  ::selection{background:color-mix(in srgb,var(--magenta) 20%,#fff)}

  /* nav — Crecer discreto */
  .nav{display:flex;align-items:center;justify-content:space-between;padding:20px 22px;position:relative;z-index:5}
  .brand{font-family:var(--disp);font-weight:600;font-size:16px;letter-spacing:-.02em;color:var(--tinta);text-decoration:none}
  .brand i{color:var(--teal);font-style:normal}
  .nav .entrar{font-family:var(--disp);font-weight:500;font-size:14px;color:var(--muted);text-decoration:none}
  .nav .entrar:hover{color:var(--tinta)}

  /* chip de honestidad */
  .demo{display:inline-block;font-family:var(--disp);font-weight:600;font-size:10.5px;text-transform:uppercase;letter-spacing:.08em;
    color:var(--muted);background:color-mix(in srgb,var(--muted) 8%,#fff);border:1px solid var(--line);padding:4px 10px;border-radius:999px}

  /* ── 1 · HERO: solo la pregunta ── */
  #ask{min-height:calc(100dvh - 64px);display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:0 22px 12vh}
  .ask-q{font-family:var(--disp);font-weight:600;font-size:clamp(30px,6.6vw,48px);line-height:1.12;letter-spacing:-.025em;color:var(--ink);text-wrap:balance;max-width:14ch}
  .namebox{display:flex;align-items:center;gap:10px;margin-top:34px;width:min(440px,100%);
    background:var(--card);border:1px solid var(--line);border-radius:18px;padding:8px 8px 8px 20px;
    box-shadow:0 2px 6px rgba(40,22,28,.05),0 24px 50px -26px rgba(40,22,28,.22);transition:box-shadow .2s var(--ease)}
  .namebox:focus-within{box-shadow:0 2px 6px rgba(40,22,28,.05),0 26px 56px -24px rgba(239,67,117,.3)}
  .namebox.shake{animation:shake .4s}
  @keyframes shake{10%,90%{transform:translateX(-2px)}30%,70%{transform:translateX(5px)}50%{transform:translateX(-7px)}}
  .namebox input{flex:1;border:0;outline:0;background:0;font-family:var(--body);font-size:17px;color:var(--tinta);min-width:0}
  .namebox input::placeholder{color:#b8b2ad}
  .namebox button{flex:none;width:52px;height:52px;border:0;border-radius:14px;background:var(--grad);color:#fff;
    box-shadow:var(--glow);font-size:22px;cursor:pointer;display:grid;place-items:center;line-height:1;transition:transform .2s var(--ease),box-shadow .2s var(--ease)}
  .namebox button:active{transform:translateY(1px);box-shadow:var(--glow-active)}
  .whisper{margin-top:22px;font-size:14px;color:var(--muted)}

  /* Estados: por defecto se ve el hero; al fichar (o con ?negocio) se ve #exp */
  #exp{display:none}
  body.revealed #ask{display:none}
  body.revealed #exp{display:block}

  /* ── 2 · EL CORILLO EMPIEZA A PENSAR (direcciones estratégicas, swipe) ── */
  .stage{padding:56px 0 58px}
  .stage .demo{margin-bottom:16px}
  .biz{font-family:var(--disp);font-weight:600;font-size:15px;letter-spacing:-.01em;color:var(--ink);margin-bottom:6px}
  .relevo{font-family:var(--disp);font-weight:600;font-size:clamp(24px,5.2vw,34px);line-height:1.15;letter-spacing:-.02em;color:var(--ink);text-wrap:balance;max-width:20ch}
  .relevo-sub{margin-top:12px;font-size:16px;color:var(--muted);line-height:1.5;max-width:36ch}
  /* carrusel de direcciones — objetivos distintos, no versiones de un copy */
  .dirs{display:flex;gap:14px;margin:26px 0 12px;overflow-x:auto;scroll-snap-type:x mandatory;
    -webkit-overflow-scrolling:touch;scrollbar-width:none;padding:4px 0 6px;cursor:grab}
  .dirs::-webkit-scrollbar{display:none}
  .dirs.drag{cursor:grabbing}
  .dir{flex:0 0 78%;max-width:300px;scroll-snap-align:center;background:var(--card);border:1px solid var(--line);
    border-radius:22px;padding:24px;box-shadow:0 2px 6px rgba(40,22,28,.05),0 20px 46px -26px rgba(40,22,28,.2);
    display:flex;flex-direction:column;min-height:184px;user-select:none}
  @media(min-width:640px){.dir{flex-basis:300px}}
  .dir-n{font-family:var(--disp);font-weight:600;font-size:12px;letter-spacing:.05em;color:var(--magenta);text-transform:uppercase;margin-bottom:16px}
  .dir h3{font-family:var(--disp);font-weight:600;font-size:21px;line-height:1.2;letter-spacing:-.015em;color:var(--ink);margin:0}
  .dir p{margin:12px 0 0;font-size:15px;line-height:1.5;color:var(--muted)}
  .dots{display:flex;gap:6px;margin-top:6px}
  .dot{width:6px;height:6px;border-radius:50%;background:var(--line);transition:width .3s var(--ease),background .3s}
  .dot.on{background:var(--magenta);width:20px;border-radius:3px}
  .team-line{margin-top:28px;font-size:14px;color:var(--muted);line-height:1.55;max-width:42ch}
  .team-line b{color:var(--ink);font-weight:600}

  /* ── 3 · APRENDE TU VOZ (promesa; la personalidad llega tras conocerte) ── */
  .voz{padding:66px 0;border-top:1px solid var(--line)}
  .voz .demo{margin-bottom:16px}
  .voz h2{font-family:var(--disp);font-weight:600;font-size:clamp(23px,5vw,30px);line-height:1.2;letter-spacing:-.02em;color:var(--ink);max-width:18ch}
  .voz p{margin-top:16px;font-size:16.5px;line-height:1.55;color:var(--muted);max-width:42ch}
  .voz p b{color:var(--ink);font-weight:600}

  /* ── 4 · RESULTADOS DEMOSTRATIVOS (honestos) ── */
  .res{padding:66px 0;border-top:1px solid var(--line)}
  .res .demo{margin-bottom:16px}
  .res .pre{font-size:16px;color:var(--tinta);margin-bottom:16px;max-width:26ch;line-height:1.45;font-weight:500}
  .num{font-family:var(--disp);font-weight:600;font-size:clamp(54px,15vw,84px);line-height:.9;letter-spacing:-.035em;color:var(--magenta)}
  .res .after{margin-top:12px;font-size:15px;color:var(--muted)}
  .spark{display:flex;align-items:flex-end;gap:6px;height:44px;margin-top:24px;max-width:260px}
  .spark i{flex:1;border-radius:4px 4px 0 0;background:linear-gradient(180deg,var(--teal),#00827e)}

  /* ── 5 · CTA — pertenencia ── */
  .cta{padding:80px 0 92px;border-top:1px solid var(--line);text-align:center}
  .cta h2{font-family:var(--disp);font-weight:600;font-size:clamp(32px,7.4vw,50px);line-height:1.05;letter-spacing:-.03em;color:var(--ink)}
  .cta p{margin:18px auto 30px;font-size:16.5px;color:var(--muted);line-height:1.5;max-width:30ch}
  .cta p b{color:var(--ink);font-weight:600}
  .enter{display:inline-block;border:0;cursor:pointer;text-decoration:none;font-family:var(--disp);font-weight:600;font-size:17px;color:#fff;
    padding:17px 42px;border-radius:16px;background:var(--grad);box-shadow:var(--glow);transition:transform .2s var(--ease),box-shadow .2s var(--ease)}
  .enter:active{transform:translateY(1px);box-shadow:var(--glow-active)}
  .free{margin-top:16px;font-size:13.5px;color:var(--muted)}
  .foot{padding:26px 0;border-top:1px solid var(--line);text-align:center;color:var(--muted);font-size:13px}
  .foot a{text-decoration:none}.foot a:hover{color:var(--tinta)}

  /* Fichaje: el Home entra desde abajo (una vez, al revelar) */
  body.revealed.animate #exp{animation:riseIn .5s var(--ease) both}
  @keyframes riseIn{from{opacity:0;transform:translateY(26px)}to{opacity:1;transform:none}}
  body.revealed.animate .dir{opacity:0;animation:riseIn .44s var(--ease) both}
  body.revealed.animate .dir:nth-child(1){animation-delay:.16s}
  body.revealed.animate .dir:nth-child(2){animation-delay:.24s}
  body.revealed.animate .dir:nth-child(3){animation-delay:.32s}

  @media (prefers-reduced-motion:reduce){
    html{scroll-behavior:auto}
    *,*::before,*::after{animation-duration:.001ms!important;transition-duration:.001ms!important}
  }
  @media(max-width:620px){
    .cap{font-size:18px}
    .res .num{font-size:clamp(50px,17vw,74px)}
  }
</style>
</head>
<body class="<?= $has_name ? 'revealed' : '' ?>">

<nav class="nav">
  <a class="brand" href="/crecer/crecer.php">encuéntralo <i>crecer</i></a>
  <a class="entrar" href="/crecer/login.php">Entrar</a>
</nav>

<!-- ── 1 · HERO — solo la pregunta ── -->
<header id="ask">
  <h1 class="ask-q">¿Cómo se llama tu negocio?</h1>
  <form class="namebox" id="fiche" method="get" action="/crecer/crecer.php" autocomplete="off">
    <input id="negInput" name="negocio" maxlength="60" required
           value="<?= $has_name ? $h($negocio) : '' ?>"
           placeholder="Escríbelo aquí…" aria-label="Nombre de tu negocio"
           enterkeyhint="go" autocapitalize="words" spellcheck="false">
    <button type="submit" aria-label="Entrar">→</button>
  </form>
  <p class="whisper">Tu corillo te está esperando.</p>
</header>

<!-- ── 2–5 · LA EXPERIENCIA (se revela al fichar) ── -->
<main id="exp">

  <!-- 2 · EL CORILLO EMPIEZA A PENSAR (direcciones estratégicas) -->
  <section class="stage wrap">
    <span class="demo">Demostración</span>
    <div class="biz jsname"><?= $h($biz) ?></div>
    <h2 class="relevo">El Corillo ya está pensando.</h2>
    <p class="relevo-sub">Preparó varias direcciones para arrancar con <b class="jsname"><?= $h($biz) ?></b>. Deslízalas.</p>
    <div class="dirs" id="dirs">
      <article class="dir"><div class="dir-n">Idea 01</div><h3>Darte a conocer</h3><p>Llegar a personas de tu zona que todavía no saben que existes.</p></article>
      <article class="dir"><div class="dir-n">Idea 02</div><h3>Mostrar lo que haces</h3><p>Enseñar tu producto o tu servicio para que la gente lo quiera.</p></article>
      <article class="dir"><div class="dir-n">Idea 03</div><h3>Que vuelvan</h3><p>Mantener cerca a tus clientes para que regresen y te recomienden.</p></article>
    </div>
    <div class="dots" id="dots"><span class="dot on"></span><span class="dot"></span><span class="dot"></span></div>
    <p class="team-line"><b>El Corillo</b> —La Creativa, El Diseñador y La Estratega— ya está pensando cómo ayudarte a empezar.</p>
  </section>

  <!-- 3 · APRENDE TU VOZ (la personalidad llega después de conocerte) -->
  <section class="voz wrap">
    <span class="demo">Más adelante</span>
    <h2>Después, aprende a hablar como tú.</h2>
    <p>Cuando le cuentes de tu negocio, <b>El Corillo</b> escribe con tu personalidad y tu forma de hablar. Por ahora, apenas te está conociendo.</p>
  </section>

  <!-- 4 · RESULTADOS DEMOSTRATIVOS (honestos) -->
  <section class="res wrap">
    <span class="demo">Demostración</span>
    <p class="pre">Así se ven los números de un negocio con el Corillo.</p>
    <div class="num">2,082</div>
    <p class="after">personas alcanzadas en un mes · ejemplo de demostración, no tus resultados.</p>
    <div class="spark"><i style="height:22%"></i><i style="height:38%"></i><i style="height:31%"></i><i style="height:55%"></i><i style="height:70%"></i><i style="height:64%"></i><i style="height:88%"></i><i style="height:100%"></i></div>
  </section>

  <!-- 5 · CTA -->
  <section class="cta wrap">
    <h2>Ya vimos por dónde podemos empezar.</h2>
    <p>Crea tu cuenta gratis y el Corillo prepara tu primer post real — con lo que aprenda de <b class="jsname"><?= $h($biz) ?></b>.</p>
    <a class="enter" id="enterCta" href="/crecer/registro.php?negocio=<?= urlencode($negocio) ?>">Crear mi primer post gratis</a>
    <div class="free">Sin tarjeta. El Corillo aprende primero sobre tu negocio.</div>
  </section>

  <footer class="foot">
    <a class="brand" href="/crecer/crecer.php" style="font-size:14px">encuéntralo <i style="color:var(--teal);font-style:normal">crecer</i></a>
    · <a href="/crecer/terminos.php">Términos</a> · <a href="/crecer/privacidad.php">Privacidad</a>
  </footer>
</main>

<script>
(function () {
  var form  = document.getElementById('fiche'),
      input = document.getElementById('negInput'),
      box   = form,
      cta   = document.getElementById('enterCta');
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function limpiar(v){ return (v || '').replace(/<[^>]*>/g,'').replace(/\s+/g,' ').trim().slice(0,60); }

  function aplicarNombre(n){
    var seguro = n || 'tu negocio';
    // textContent = XSS-safe; nunca innerHTML con input del usuario.
    document.querySelectorAll('.jsname').forEach(function(el){ el.textContent = seguro; });
    if (cta) cta.setAttribute('href', '/crecer/registro.php?negocio=' + encodeURIComponent(n));
  }

  function revelar(){
    if (reduce) {
      document.body.classList.add('revealed');
      window.scrollTo(0, 0);
      return;
    }
    var ask = document.getElementById('ask');
    ask.style.transition = 'transform .42s var(--ease), opacity .38s ease';
    ask.style.transform = 'translateY(-24px)';
    ask.style.opacity = '0';
    setTimeout(function(){
      document.body.classList.add('revealed', 'animate');   // oculta #ask, muestra #exp con rise-in
      window.scrollTo(0, 0);
    }, 360);
  }

  if (form) form.addEventListener('submit', function(e){
    var nombre = limpiar(input.value);
    if (!nombre) {                       // campo vacío → no revela; avisa
      e.preventDefault();
      box.classList.remove('shake'); void box.offsetWidth; box.classList.add('shake');
      input.focus();
      return;
    }
    e.preventDefault();                  // fichaje sin recarga (fallback: GET si no hay JS)
    input.value = nombre;
    aplicarNombre(nombre);
    revelar();
  });

  // Carrusel de direcciones: swipe táctil nativo + dots + arrastre con ratón.
  var dirs = document.getElementById('dirs'), dots = document.getElementById('dots');
  if (dirs) {
    var cards = [].slice.call(dirs.querySelectorAll('.dir'));
    var dotEls = dots ? [].slice.call(dots.querySelectorAll('.dot')) : [];
    function sync(){
      var c = dirs.scrollLeft + dirs.clientWidth / 2, idx = 0, best = 1e9;
      cards.forEach(function(el, i){ var m = el.offsetLeft + el.offsetWidth / 2, d = Math.abs(m - c); if (d < best){ best = d; idx = i; } });
      dotEls.forEach(function(d, i){ d.classList.toggle('on', i === idx); });
    }
    dirs.addEventListener('scroll', sync, { passive: true });
    var down = false, sx = 0, sl = 0;
    dirs.addEventListener('pointerdown', function(e){ if (e.pointerType && e.pointerType !== 'mouse') return; down = true; sx = e.clientX; sl = dirs.scrollLeft; dirs.classList.add('drag'); });
    window.addEventListener('pointermove', function(e){ if (!down) return; dirs.scrollLeft = sl - (e.clientX - sx); });
    window.addEventListener('pointerup', function(){ down = false; dirs.classList.remove('drag'); });
  }
})();
</script>
</body>
</html>
