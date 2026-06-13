<?php
// ============================================================
//  ENCUÉNTRALO — Hub / index público
//  index.php  ·  las dos puertas: Directorio | Crecer
// ============================================================
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Encuéntralo · Todo tu negocio, en un solo sitio</title>
<link rel="icon" type="image/svg+xml" href="/crecer/assets/brand/encuentralo-pin.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="/crecer/assets/encuentralo-ui.css" rel="stylesheet">
<style>
  /* ── Hub: layout propio, hereda tokens del sistema ── */
  .hub{min-height:100vh;display:flex;flex-direction:column}
  .nav{display:flex;align-items:center;gap:10px;
    padding:20px 24px;max-width:1100px;margin:0 auto;width:100%}
  .nav .mark{height:32px}
  .nav .brand-name{font-family:var(--font-display);font-weight:800;
    font-size:20px;letter-spacing:-.03em;color:var(--tinta);text-transform:lowercase}
  .nav .enter{margin-left:auto;font-weight:700;font-size:14px;color:var(--muted);
    text-decoration:none;padding:9px 16px;border-radius:99px;border:1px solid var(--line)}
  .nav .enter:hover{color:var(--tinta);background:var(--card)}

  .hero{flex:1;max-width:1100px;margin:0 auto;width:100%;
    padding:32px 24px 56px;text-align:center;
    display:flex;flex-direction:column;align-items:center;justify-content:center}
  .eyebrow{font-weight:700;font-size:13px;letter-spacing:.12em;text-transform:uppercase;
    color:var(--terracota)}
  .hero h1{font-family:var(--font-display);font-weight:800;
    font-size:clamp(34px,6vw,60px);line-height:1.04;letter-spacing:-.03em;
    color:var(--tinta);margin:12px 0 0;max-width:16ch}
  .hero h1 .grad{background:linear-gradient(100deg,var(--coral),var(--magenta));
    -webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
  .hero .sub{font-size:clamp(16px,2.4vw,19px);color:var(--muted);
    margin-top:16px;max-width:46ch}

  /* ── Las dos puertas ── */
  .doors{display:grid;grid-template-columns:1fr 1fr;gap:20px;
    margin-top:42px;width:100%;max-width:840px}
  .door{position:relative;text-align:left;background:var(--card);
    border:1px solid var(--line);border-radius:var(--r-xl);padding:30px 28px;
    box-shadow:var(--shadow);text-decoration:none;color:inherit;
    transition:transform .2s ease,box-shadow .2s ease;overflow:hidden}
  .door:hover{transform:translateY(-4px);box-shadow:0 16px 40px rgba(74,52,32,.16)}
  .door .ic{width:54px;height:54px;border-radius:16px;display:grid;place-items:center;
    font-size:26px;margin-bottom:16px}
  .door.buscar .ic{background:var(--blue-bg)}
  .door.crecer .ic{background:linear-gradient(135deg,var(--coral),var(--magenta));}
  .door h2{font-family:var(--font-display);font-weight:800;font-size:24px;
    letter-spacing:-.02em;color:var(--tinta);margin-bottom:6px}
  .door p{font-size:15px;color:var(--muted);margin-bottom:20px;min-height:44px}
  .door .go{display:inline-flex;align-items:center;gap:7px;font-weight:800;font-size:15px}
  .door.buscar .go{color:var(--teal)}
  .door.crecer .go{color:var(--terracota)}
  .door.crecer{border-color:#f6c9d6}
  .door .tag{position:absolute;top:18px;right:18px;font-size:11px;font-weight:800;
    text-transform:uppercase;letter-spacing:.04em;color:#fff;
    background:linear-gradient(135deg,var(--coral),var(--magenta));
    padding:4px 10px;border-radius:99px}

  /* ── Tira "qué es" ── */
  .strip{background:var(--card);border-top:1px solid var(--line)}
  .strip .in{max-width:1000px;margin:0 auto;padding:36px 24px;
    display:grid;grid-template-columns:repeat(3,1fr);gap:24px}
  .feat{text-align:center}
  .feat .e{font-size:30px}
  .feat h3{font-family:var(--font-display);font-weight:800;font-size:17px;
    margin:8px 0 4px;color:var(--tinta);letter-spacing:-.01em}
  .feat p{font-size:14px;color:var(--muted)}
  .foot{text-align:center;color:var(--muted);font-size:13px;padding:22px}
  .foot b{color:var(--terracota)}

  @media (max-width:720px){
    .doors{grid-template-columns:1fr;max-width:440px}
    .strip .in{grid-template-columns:1fr;gap:22px}
    .door p{min-height:0}
  }
</style>
</head>
<body>
<div class="hub">

  <nav class="nav">
    <img class="mark" src="/crecer/assets/brand/encuentralo-pin.svg" alt="">
    <span class="brand-name">encuéntralo</span>
    <a class="enter" href="/crecer/panel/aprobar2.php?marca=1">Entrar</a>
  </nav>

  <header class="hero">
    <span class="eyebrow">El sitio del negocio boricua</span>
    <h1>Todo tu negocio, <span class="grad">en un solo sitio.</span></h1>
    <p class="sub">Encuentra los servicios que buscas — o deja que la IA te maneje
       el marketing y haga crecer el tuyo. ¿Por dónde entras?</p>

    <div class="doors">
      <a class="door buscar" href="/crecer/buscar.php">
        <div class="ic">🔎</div>
        <h2>Busco un servicio</h2>
        <p>Encuentra reposterías, comida y servicios boricuas cerca de ti.</p>
        <span class="go">Ver el directorio →</span>
      </a>
      <a class="door crecer" href="/crecer/crecer.php">
        <span class="tag">Negocios &amp; ideas</span>
        <div class="ic">🚀</div>
        <h2>Tengo un negocio<br><span style="color:var(--muted);font-weight:700">…o una idea</span></h2>
        <p>Ya montado o por montar: te hacemos el montaje, el marketing y te damos la mano para arrancar.</p>
        <span class="go">Conoce Crecer →</span>
      </a>
    </div>
  </header>

  <section class="strip">
    <div class="in">
      <div class="feat"><div class="e">🇵🇷</div><h3>Hecho en y para PR</h3>
        <p>Voz boricua auténtica, negocios locales de verdad.</p></div>
      <div class="feat"><div class="e">🤖</div><h3>La IA hace el trabajo</h3>
        <p>Planifica, crea y responde. Tú apruebas desde el celular.</p></div>
      <div class="feat"><div class="e">📈</div><h3>Creces de verdad</h3>
        <p>Más visibilidad, más órdenes, mejores reseñas.</p></div>
    </div>
  </section>

  <p class="foot">© Encuéntralo · Todo tu negocio, en un solo sitio 🇵🇷</p>
</div>
</body>
</html>
