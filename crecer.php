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

  /* ── 2 · EL CORILLO TRABAJANDO (Home real, personalizado) ── */
  .stage{padding:56px 0 60px}
  .stage .demo{margin-bottom:16px}
  .biz{font-family:var(--disp);font-weight:600;font-size:15px;letter-spacing:-.01em;color:var(--ink);margin-bottom:6px}
  .relevo{font-family:var(--disp);font-weight:600;font-size:clamp(24px,5.2vw,34px);line-height:1.15;letter-spacing:-.02em;color:var(--ink);text-wrap:balance;max-width:20ch}
  .credits{list-style:none;margin:26px 0 30px;display:flex;flex-direction:column;gap:16px}
  .credits li{display:flex;align-items:flex-start;gap:12px;font-size:16.5px;line-height:1.35;color:var(--tinta)}
  .ck{flex:none;width:21px;height:21px;margin-top:1px;color:var(--palma)}
  .prop{border-radius:26px;overflow:hidden;background:#14121c;box-shadow:0 34px 80px -26px rgba(24,12,20,.55)}
  .prop-top{padding:20px 20px 0}
  .chip{display:inline-block;font-size:11px;font-weight:700;color:#fff;text-transform:capitalize;letter-spacing:.03em;
    background:rgba(255,255,255,.14);backdrop-filter:blur(7px);padding:6px 12px;border-radius:999px}
  .cap{padding:22px 22px 4px;color:#fff;font-size:19.5px;line-height:1.5;font-weight:400}
  .cap b{font-weight:600}
  .prop-foot{padding:20px 20px 22px}
  .go{width:100%;border:0;cursor:pointer;font-family:var(--disp);font-weight:600;font-size:16px;color:#fff;
    padding:16px;border-radius:16px;background:var(--grad);box-shadow:var(--glow);transition:transform .2s var(--ease),box-shadow .2s var(--ease)}
  .go:active{transform:translateY(1px);box-shadow:var(--glow-active)}
  .subacc{display:flex;justify-content:center;gap:22px;margin-top:12px}
  .subacc span{color:rgba(255,255,255,.8);font-family:var(--disp);font-weight:500;font-size:13.5px}
  .firma{margin-top:26px;font-family:var(--disp);font-style:italic;font-weight:400;font-size:15px;color:var(--muted)}

  /* ── 3 · LA VOZ DEL NEGOCIO ── */
  .voz{padding:66px 0;border-top:1px solid var(--line)}
  .voz .demo{margin-bottom:20px}
  .voz .lbl{font-family:var(--disp);font-weight:600;font-size:15px;color:var(--muted);margin:16px 0 18px}
  .voz q{quotes:none;font-family:var(--disp);font-weight:500;font-size:clamp(22px,5vw,30px);line-height:1.4;letter-spacing:-.015em;color:var(--ink);display:block}

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
  body.revealed.animate .credits li{opacity:0;animation:riseIn .42s var(--ease) both}
  body.revealed.animate .credits li:nth-child(1){animation-delay:.12s}
  body.revealed.animate .credits li:nth-child(2){animation-delay:.20s}
  body.revealed.animate .credits li:nth-child(3){animation-delay:.28s}
  body.revealed.animate .prop{opacity:0;animation:riseIn .46s var(--ease) .34s both}

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

  <!-- 2 · EL CORILLO TRABAJANDO -->
  <section class="stage wrap">
    <span class="demo">Demostración</span>
    <div class="biz jsname"><?= $h($biz) ?></div>
    <h2 class="relevo">Tu Corillo ya tendría esto listo.</h2>
    <ul class="credits">
      <li><svg class="ck" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.4 2.4 4.6-4.8"/></svg>La Creativa prepara tu propuesta del día.</li>
      <li><svg class="ck" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.4 2.4 4.6-4.8"/></svg>El Diseñador monta tu arte.</li>
      <li><svg class="ck" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.4 2.4 4.6-4.8"/></svg>La Estratega cuadra tu semana.</li>
    </ul>
    <article class="prop">
      <div class="prop-top"><span class="chip">Instagram</span></div>
      <p class="cap">¡Wepa! En <b class="jsname"><?= $h($biz) ?></b> tenemos algo bueno para ti hoy 🔥 Escríbenos por WhatsApp y te lo apartamos, mi gente 💛</p>
      <div class="prop-foot">
        <button class="go" type="button">Vamos con este</button>
        <div class="subacc"><span>Ajústalo</span><span>No es esto</span></div>
      </div>
    </article>
    <p class="firma">El corillo sigue trabajando.</p>
  </section>

  <!-- 3 · LA VOZ DEL NEGOCIO -->
  <section class="voz wrap">
    <span class="demo">Demostración</span>
    <div class="lbl">Habla como tú. Nunca traducido.</div>
    <q>"Date el gusto: lo bueno de <b class="jsname"><?= $h($biz) ?></b>, como te lo mereces. Escríbenos hoy y te atendemos con cariño. 😋"</q>
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
    <h2>Bienvenido al Corillo.</h2>
    <p>Tu equipo está listo para trabajar por <b class="jsname"><?= $h($biz) ?></b>.</p>
    <a class="enter" id="enterCta" href="/crecer/registro.php?negocio=<?= urlencode($negocio) ?>">Entrar al Corillo</a>
    <div class="free">Gratis para empezar · sin tarjeta</div>
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
})();
</script>
</body>
</html>
