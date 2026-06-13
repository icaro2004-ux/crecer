<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Landing de venta (los niveles)
//  crecer.php  ·  /crecer
// ============================================================
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Crecer · Tu departamento de marketing con IA — Encuéntralo</title>
<link rel="icon" type="image/svg+xml" href="/crecer/assets/brand/encuentralo-pin.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="/crecer/assets/encuentralo-ui.css" rel="stylesheet">
<style>
  .page{overflow-x:hidden}
  .wrap{max-width:1080px;margin:0 auto;padding:0 24px}
  .nav{display:flex;align-items:center;gap:10px;padding:20px 24px;max-width:1080px;margin:0 auto}
  .nav .mark{height:32px}
  .nav .bn{font-family:var(--font-display);font-weight:800;font-size:20px;letter-spacing:-.03em;text-transform:lowercase}
  .nav .back{margin-left:auto;font-weight:700;font-size:14px;color:var(--muted);text-decoration:none}
  .nav .back:hover{color:var(--tinta)}

  /* Hero */
  .hero{text-align:center;padding:34px 24px 10px;max-width:820px;margin:0 auto}
  .eyebrow{font-weight:700;font-size:13px;letter-spacing:.12em;text-transform:uppercase;color:var(--terracota)}
  .hero h1{font-family:var(--font-display);font-weight:800;font-size:clamp(36px,6.4vw,64px);
    line-height:1.03;letter-spacing:-.03em;margin:12px 0 0}
  .hero h1 .g{background:linear-gradient(100deg,var(--coral),var(--magenta));-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
  .hero .sub{font-size:clamp(16px,2.3vw,19px);color:var(--muted);margin-top:16px}

  /* Dos caminos */
  .caminos{display:grid;grid-template-columns:1fr 1fr;gap:18px;max-width:760px;margin:34px auto 0}
  .camino{background:var(--card);border:1px solid var(--line);border-radius:var(--r-lg);
    padding:24px;text-align:left;box-shadow:var(--shadow-sm)}
  .camino .e{font-size:30px}
  .camino h3{font-family:var(--font-display);font-weight:800;font-size:20px;margin:10px 0 4px;letter-spacing:-.02em}
  .camino p{font-size:14.5px;color:var(--muted)}

  /* Cómo funciona */
  .how{max-width:900px;margin:56px auto 0;text-align:center}
  .how .steps{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-top:24px}
  .step{background:var(--card);border:1px solid var(--line);border-radius:var(--r-md);padding:18px 14px}
  .step .n{font-family:var(--font-display);font-weight:800;color:var(--terracota);font-size:14px}
  .step h4{font-size:15px;font-weight:800;margin:6px 0 3px}
  .step p{font-size:13px;color:var(--muted)}

  /* Secciones */
  .sec-head{text-align:center;max-width:680px;margin:64px auto 0}
  .sec-head h2{font-family:var(--font-display);font-weight:800;font-size:clamp(28px,4.4vw,42px);letter-spacing:-.025em}
  .sec-head p{color:var(--muted);margin-top:10px;font-size:16px}

  /* Niveles */
  .plans{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-top:34px;align-items:start}
  .plan{background:var(--card);border:1px solid var(--line);border-radius:var(--r-xl);
    padding:28px 24px;box-shadow:var(--shadow-sm);position:relative}
  .plan.pop{border-color:transparent;box-shadow:0 18px 46px rgba(255,43,133,.18);
    background:linear-gradient(var(--card),var(--card)) padding-box,
      linear-gradient(135deg,var(--coral),var(--magenta)) border-box;border:2px solid transparent;
    transform:translateY(-8px)}
  .plan .pop-tag{position:absolute;top:-13px;left:50%;transform:translateX(-50%);
    background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff;
    font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;
    padding:5px 14px;border-radius:99px}
  .plan .lvl{font-size:13px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:var(--muted)}
  .plan .name{font-family:var(--font-display);font-weight:800;font-size:24px;margin:4px 0 2px;letter-spacing:-.02em}
  .plan .promise{font-size:14px;color:var(--muted);min-height:40px}
  .plan .price{font-family:var(--font-display);font-weight:800;font-size:40px;letter-spacing:-.03em;margin:14px 0 2px}
  .plan .price small{font-size:15px;color:var(--muted);font-weight:600}
  .plan ul{list-style:none;margin:18px 0 0;display:flex;flex-direction:column;gap:10px}
  .plan li{font-size:14px;display:flex;gap:9px;align-items:flex-start;line-height:1.4}
  .plan li::before{content:"✓";color:var(--palma);font-weight:900;flex:none}
  .plan li.gift::before{content:"🎁"}
  .plan li.gift{font-weight:600}
  .plan .cta{display:block;text-align:center;margin-top:22px;padding:14px;border-radius:99px;
    font-weight:800;font-size:15px;text-decoration:none;border:2px solid var(--line);color:var(--tinta)}
  .plan.pop .cta{background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff;border-color:transparent}
  .plan .cta:hover{border-color:var(--terracota)}
  .plan.pop .cta:hover{filter:brightness(1.05)}
  .note{text-align:center;color:var(--muted);font-size:13px;margin-top:18px}

  /* Flywheel */
  .fly{background:var(--card);border-top:1px solid var(--line);border-bottom:1px solid var(--line);margin-top:70px;padding:54px 0}
  .fly .ring{display:flex;flex-wrap:wrap;justify-content:center;gap:10px;align-items:center;margin-top:26px}
  .fly .node{background:var(--crema);border:1px solid var(--line);border-radius:99px;padding:10px 18px;font-weight:700;font-size:14px}
  .fly .arr{color:var(--terracota);font-weight:900}

  /* CTA final */
  .final{text-align:center;padding:66px 24px}
  .final h2{font-family:var(--font-display);font-weight:800;font-size:clamp(28px,5vw,46px);letter-spacing:-.03em;max-width:16ch;margin:0 auto}
  .final .big-cta{display:inline-block;margin-top:22px;background:linear-gradient(135deg,var(--coral),var(--magenta));
    color:#fff;font-weight:800;font-size:17px;padding:16px 34px;border-radius:99px;text-decoration:none;
    box-shadow:0 12px 30px rgba(255,43,133,.32)}
  .final .big-cta:hover{filter:brightness(1.05)}
  .foot{text-align:center;color:var(--muted);font-size:13px;padding:24px}
  .foot b{color:var(--terracota)}

  @media (max-width:860px){
    .plans{grid-template-columns:1fr;max-width:440px;margin-left:auto;margin-right:auto}
    .plan.pop{transform:none}
    .how .steps{grid-template-columns:1fr 1fr}
    .tracks{grid-template-columns:1fr;max-width:440px}
  }
</style>
</head>
<body>
<div class="page">

  <nav class="nav">
    <img class="mark" src="/crecer/assets/brand/encuentralo-pin.svg" alt="">
    <span class="bn">encuéntralo</span>
    <a class="back" href="/crecer/index.php">← Volver</a>
  </nav>

  <!-- HERO -->
  <header class="hero">
    <span class="eyebrow">Tu departamento de marketing con IA</span>
    <h1>¿Cuánto quieres <span class="g">crecer?</span></h1>
    <p class="sub">La IA te maneja el marketing del negocio — contenido, redes, clientela —
       y tú solo apruebas desde el celular. Escoge tu punto de partida:</p>

    <div class="caminos">
      <div class="camino">
        <div class="e">🌱</div>
        <h3>Empiezo de cero</h3>
        <p>Tienes una idea y quieres montar tu negocio. Te hacemos el montaje completo
           — nombre, imagen, presencia — y te damos la mano con los primeros pasos.</p>
      </div>
      <div class="camino">
        <div class="e">🚀</div>
        <h3>Ya tengo negocio</h3>
        <p>Ya operas y quieres más ventas. Te montamos la agencia de publicidad con IA:
           contenido, promoción y manejo de clientela. Tú creces.</p>
      </div>
    </div>
  </header>

  <!-- CÓMO FUNCIONA -->
  <section class="how wrap">
    <div class="sec-head" style="margin-top:0">
      <h2>La IA hace el trabajo. Tú apruebas.</h2>
    </div>
    <div class="steps">
      <div class="step"><div class="n">PASO 1</div><h4>Aprende tu negocio</h4><p>Tu voz, tus productos, tu público.</p></div>
      <div class="step"><div class="n">PASO 2</div><h4>Planifica y crea</h4><p>Calendario, posts y gráficas con tus fotos.</p></div>
      <div class="step"><div class="n">PASO 3</div><h4>Tú apruebas</h4><p>Desde el celular, en segundos.</p></div>
      <div class="step"><div class="n">PASO 4</div><h4>Publica y responde</h4><p>Postea y contesta los mensajes solo.</p></div>
    </div>
  </section>

  <!-- NIVELES -->
  <div class="wrap">
    <div class="sec-head">
      <h2>Escoge cuánto crecer</h2>
      <p>Cada nivel incluye el anterior. Y todos llevan la promo de Encuéntralo gratis 🎁</p>
    </div>

    <div class="plans">
      <!-- BÁSICO -->
      <div class="plan">
        <div class="lvl">🌱 Nivel 1 · Básico</div>
        <div class="name">Te ves PRO</div>
        <div class="promise">Tu identidad y tu presencia online, listas.</div>
        <div class="price">$25<small>/mes</small></div>
        <ul>
          <li>Logo + colores + portada (IA)</li>
          <li>Presencia online lista</li>
          <li class="gift">GRATIS: Encuéntralo <b>Pro</b> (vale $5)<br>verificado, fotos ilimitadas, prioridad</li>
        </ul>
        <a class="cta" href="#empezar">Empezar</a>
      </div>

      <!-- INTERMEDIO -->
      <div class="plan pop">
        <span class="pop-tag">★ El más popular</span>
        <div class="lvl">🌿 Nivel 2 · Intermedio</div>
        <div class="name">Te mueves solo</div>
        <div class="promise">La IA te corre las redes y las órdenes.</div>
        <div class="price">$55<small>/mes</small></div>
        <ul>
          <li>Todo lo de Básico</li>
          <li>Calendario + captions + gráficas con tus fotos</li>
          <li>Aprobación desde el celular</li>
          <li>La IA responde tus DMs</li>
          <li>Agendamiento de órdenes</li>
          <li class="gift">GRATIS: Encuéntralo <b>Max</b> (vale $15)<br>posición destacada, prioridad máxima</li>
        </ul>
        <a class="cta" href="#empezar">Empezar</a>
      </div>

      <!-- AVANZADO -->
      <div class="plan">
        <div class="lvl">🌳 Nivel 3 · Avanzado</div>
        <div class="name">Creces con data</div>
        <div class="promise">Sabes qué funciona y decides con números.</div>
        <div class="price">$75<small>/mes</small></div>
        <ul>
          <li>Todo lo de Intermedio</li>
          <li>Flywheel de reseñas reales</li>
          <li>Análisis de ventas</li>
          <li>Cuentas: ingresos, gastos, ganancia</li>
          <li class="gift">GRATIS: Encuéntralo <b>Max</b> full (vale $15)<br>máxima exposición + soporte prioritario</li>
        </ul>
        <a class="cta" href="#empezar">Empezar</a>
      </div>
    </div>
    <p class="note">💡 Precios de ejemplo — por confirmar.</p>
  </div>

  <!-- FLYWHEEL -->
  <section class="fly">
    <div class="sec-head" style="margin-top:0">
      <h2>La rueda que te hace crecer</h2>
      <p>Cada orden que completas te trae la próxima.</p>
    </div>
    <div class="ring wrap">
      <span class="node">📥 Entra una orden</span><span class="arr">→</span>
      <span class="node">✅ La completas</span><span class="arr">→</span>
      <span class="node">⭐ El cliente te reseña</span><span class="arr">→</span>
      <span class="node">📈 Subes en el directorio</span><span class="arr">→</span>
      <span class="node">🔁 Más clientes</span>
    </div>
  </section>

  <!-- CTA FINAL -->
  <section class="final" id="empezar">
    <h2>Empieza a crecer hoy 🌱</h2>
    <a class="big-cta" href="#">Crear mi negocio</a>
  </section>

  <p class="foot">© Encuéntralo · Crecer — tu departamento de marketing con IA 🇵🇷</p>
</div>
</body>
</html>
