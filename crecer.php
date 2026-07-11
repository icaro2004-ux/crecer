<?php
// ============================================================
//  ENCUENTRALO · CRECER — Landing minimalista inspirada en flyer
//  crecer.php · /crecer
// ============================================================
require __DIR__ . '/includes/iconos.php';

$acciones = 113;
$negocios = 7;
$precio_crecer = 39;   // respaldo si la BD no responde; la fuente real es crecer_planes.precio_mensual
try {
    if (is_file(__DIR__ . '/includes/config.local.php')) require_once __DIR__ . '/includes/config.local.php';
    if (defined('DB_NAME') && DB_NAME !== '') {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $r = $pdo->query("SELECT COUNT(*) a, COUNT(DISTINCT marca_id) n FROM crecer_ia_log WHERE estado='ok'")->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $acciones = max($acciones, (int)$r['a']);
            $negocios = max($negocios, (int)$r['n']);
        }
        $pp = $pdo->query("SELECT precio_mensual FROM crecer_planes WHERE slug='crecer' AND activo=1")->fetchColumn();
        if ($pp !== false && $pp !== null) $precio_crecer = (int)$pp;
    }
} catch (Throwable $e) {}

$nf = fn($n) => number_format((int)$n);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Crecer · Hacerse conocer no tiene por qué serlo</title>
<meta name="description" content="Encuéntralo Crecer es tu asistente de inteligencia artificial para atraer clientes, ahorrar tiempo y hacer crecer tu negocio.">
<link rel="icon" type="image/png" href="/crecer/assets/brand/encuentralo-crecer-pin-drop.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Anton&family=Caveat:wght@600;700&family=Poppins:wght@600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="/crecer/assets/encuentralo-ui.css?v=10" rel="stylesheet">
<style>
  :root{
    --paper:#fff;
    --ink:#06274a;
    --pink:#ef4375;
    --teal:#00a49f;
    --teal-dark:#00827e;
    --soft:#eefafa;
    --line:#ebe7df;
    --muted:#566276;
    --cream:#fff6dc;
    --display:'Anton', Impact, sans-serif;
    --hand:'Caveat', cursive;
    --body:'Plus Jakarta Sans', system-ui, sans-serif;
  }
  *{box-sizing:border-box}
  html{scroll-behavior:smooth}
  body{margin:0;background:#fff;color:var(--ink);font-family:var(--body);overflow-x:hidden;padding-top:75px}
  .wrap{width:min(1160px,calc(100% - 48px));margin:0 auto}
  .nav{position:fixed;top:0;left:0;right:0;z-index:50;background:rgba(255,255,255,.92);backdrop-filter:blur(14px);border-bottom:1px solid rgba(235,231,223,.75)}
  .nav .in{height:74px;display:flex;align-items:center;gap:18px}
  .brand{display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit}
  .brand img{width:54px;height:58px;object-fit:contain;display:block}
  .navlinks{margin-left:auto;display:flex;align-items:center;gap:20px}
  .navlinks a{color:var(--ink);text-decoration:none;font-size:14px;font-weight:800}
  .navlinks a.cta{color:#fff}
  .navlinks .login{color:var(--muted)}
  .cta{display:inline-flex;align-items:center;justify-content:center;gap:9px;border:0;border-radius:999px;background:var(--pink);color:#fff;text-decoration:none;font-weight:900;padding:13px 22px;box-shadow:0 16px 32px -18px var(--pink);transition:transform .16s ease,filter .16s ease}
  .cta:hover{transform:translateY(-2px);filter:brightness(1.04)}
  .hero{padding:58px 0 42px}
  .hero-grid{display:grid;grid-template-columns:minmax(0,1.03fr) minmax(340px,.97fr);gap:44px;align-items:center}
  .hand{font-family:var(--hand);font-weight:700;letter-spacing:.01em}
  .pre{font-size:clamp(28px,3.6vw,42px);line-height:1;color:var(--ink);margin:0 0 10px;transform:rotate(-1deg)}
  h1{font-family:var(--display);font-weight:400;text-transform:uppercase;letter-spacing:.012em;font-size:clamp(62px,8.7vw,116px);line-height:.88;margin:0;max-width:760px}
  .pink{color:var(--pink)} .teal{color:var(--teal)}
  .underline{position:relative;display:inline-block}
  .underline::after{content:"";position:absolute;left:-3%;right:-3%;bottom:-.16em;height:.18em;background:var(--teal);border-radius:999px;transform:rotate(-2deg)}
  .lede{max-width:570px;margin:30px 0 0;font-size:clamp(18px,2vw,24px);line-height:1.47;color:var(--ink)}
  .lede b{color:var(--pink)}
  .lede strong{color:var(--teal)}
  .hero-actions{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-top:30px}
  .secondary{font-weight:900;color:var(--ink);text-decoration:none;border-bottom:3px solid var(--teal);padding-bottom:2px}
  .pain-note{margin-top:34px;width:min(520px,100%);background:linear-gradient(100deg,rgba(0,164,159,.12),rgba(0,164,159,.045));border:1px solid rgba(0,164,159,.14);padding:26px 30px;position:relative;clip-path:polygon(1% 3%,98% 0,100% 96%,2% 100%)}
  .pain-note h2{font-family:var(--hand);font-size:36px;line-height:.95;color:var(--teal);margin:0 0 16px;text-transform:uppercase}
  .pain-note h2 span{border-bottom:4px solid var(--pink)}
  .pain-note ul{list-style:none;margin:0;padding:0;display:grid;gap:10px}
  .pain-note li{display:flex;align-items:center;gap:10px;color:var(--ink);font-size:16px;font-weight:700}
  .xmark{width:23px;height:23px;display:grid;place-items:center;border-radius:50%;background:var(--pink);color:#fff;flex:none}
  .xmark svg{width:14px;height:14px}
  .alone{font-family:var(--hand);font-size:34px;line-height:1;color:var(--teal);margin:20px 0 0;text-transform:uppercase}
  .alone small{display:block;color:var(--ink);font-size:27px}
  .visual{position:relative;min-height:620px;display:grid;place-items:center;overflow:visible}
  .hero-photo{position:absolute;inset:-18px -96px -42px -48px;z-index:1}
  .hero-photo img{width:100%;height:100%;object-fit:cover;object-position:center right;display:block;
    -webkit-mask-image:linear-gradient(90deg,transparent 0,#000 16%,#000 100%),
      linear-gradient(180deg,#000 0,#000 90%,transparent 100%);
    -webkit-mask-composite:source-in;
    mask-image:linear-gradient(90deg,transparent 0,#000 16%,#000 100%),
      linear-gradient(180deg,#000 0,#000 90%,transparent 100%);
    mask-composite:intersect}
  .hero-photo::after{content:"";position:absolute;inset:auto 0 0;height:34%;background:linear-gradient(180deg,transparent,var(--paper));pointer-events:none}
  .photo-bubble{position:absolute;right:18px;top:155px;border:3px solid var(--teal);border-radius:50%;padding:18px 26px;background:rgba(255,253,249,.78);font-family:var(--hand);font-size:27px;line-height:1.05;text-align:center;transform:rotate(5deg);z-index:3}
  .photo-bubble::after{content:"";position:absolute;left:26px;bottom:-20px;width:34px;height:24px;border-left:3px solid var(--teal);border-bottom:3px solid var(--teal);border-radius:0 0 0 30px;transform:rotate(-20deg)}
  .features{padding:46px 0 30px;border-top:1px solid var(--line)}
  .features h2{text-align:center;font-family:var(--hand);font-size:34px;line-height:1;color:var(--ink);margin:0 0 28px;text-transform:uppercase}
  .features h2 span{color:var(--teal)}
  .feature-row{display:grid;grid-template-columns:repeat(5,1fr);gap:0}
  .feature{padding:8px 22px 0;text-align:center;border-right:1px solid var(--line)}
  .feature:last-child{border-right:0}
  .feature .icon{height:58px;display:grid;place-items:center;color:var(--teal);margin-bottom:10px}
  .feature .icon svg{width:44px;height:44px;stroke-width:1.8}
  .feature p{margin:0 auto;font-size:15px;line-height:1.35;color:var(--ink);font-weight:800;max-width:180px}
  .offer{padding:52px 0}
  .offer-grid{display:grid;grid-template-columns:minmax(0,1fr) 290px;gap:28px;align-items:center}
  .banner{position:relative;overflow:hidden;background:linear-gradient(135deg,#008f8b 0%,#00a49f 52%,#0dbbb5 100%);color:#fff;padding:42px 46px;display:grid;grid-template-columns:minmax(0,1fr) minmax(250px,.78fr);align-items:center;gap:28px;clip-path:polygon(1% 6%,99% 0,98% 96%,0 100%)}
  .banner::before{content:"";position:absolute;inset:0;background:radial-gradient(circle at 78% 30%,rgba(255,255,255,.24),transparent 30%),linear-gradient(90deg,rgba(0,0,0,.16),transparent 45%);pointer-events:none}
  .banner-copy{position:relative;z-index:2}
  .banner h2{font-family:var(--display);font-size:clamp(32px,4vw,50px);font-weight:400;letter-spacing:.01em;line-height:1.02;margin:0;text-transform:uppercase;color:#fff}
  .banner h2 span{display:block;color:#ffe45c}
  .banner h2 b{color:var(--pink);font-weight:400}
  .banner .accent-line{width:76px;height:4px;background:var(--pink);border-radius:999px;margin:20px 0 18px;display:block}
  .banner p{margin:0;font-size:16px;font-weight:800;color:#e9ffff}
  .growth-art{position:relative;z-index:1;min-height:250px;align-self:stretch;display:grid;place-items:center;overflow:visible}
  .growth-art img{width:145%;height:100%;max-height:330px;object-fit:contain;object-position:center;border-radius:8px;opacity:.98;mix-blend-mode:screen;
    -webkit-mask-image:linear-gradient(90deg,transparent 0,#000 10%,#000 100%),linear-gradient(180deg,transparent 0,#000 10%,#000 90%,transparent 100%);
    -webkit-mask-composite:source-in;
    mask-image:linear-gradient(90deg,transparent 0,#000 10%,#000 100%),linear-gradient(180deg,transparent 0,#000 10%,#000 90%,transparent 100%);
    mask-composite:intersect}
  .price-card{background:linear-gradient(140deg,#fffaf0,#fff2d3);border:1px solid #f2dfba;border-radius:24px;padding:28px 24px;text-align:center;box-shadow:0 22px 50px -38px rgba(0,0,0,.38)}
  .price-card .hand{font-size:31px;line-height:1;color:var(--teal);text-transform:uppercase}
  .price-card .line{width:120px;height:4px;background:var(--pink);border-radius:999px;margin:6px auto 18px;transform:rotate(-2deg)}
  .price-card .desde{font-size:16px;font-weight:900;color:var(--ink)}
  .price-card .price{font-family:var(--display);font-size:70px;line-height:.85;margin:8px 0;color:var(--ink)}
  .price-card .price small{font-family:var(--body);font-size:24px;color:var(--teal);font-weight:900}
  .price-card p{margin:12px 0 0;color:var(--ink);font-weight:800}
  .price-cta{display:inline-flex;align-items:center;justify-content:center;margin-top:18px;border-radius:999px;background:var(--pink);color:#fff;text-decoration:none;font-size:14px;font-weight:900;padding:11px 18px;box-shadow:0 14px 30px -20px var(--pink)}
  .price-note{display:block;margin-top:10px;color:var(--muted);font-size:12px;font-weight:800}
  .final{padding:30px 0 58px;text-align:center}
  .final h2{font-family:var(--display);font-size:clamp(40px,5vw,70px);line-height:.94;margin:0 auto 22px;max-width:760px;text-transform:uppercase}
  .final h2 span{color:var(--pink)}
  .micro{color:var(--muted);font-size:14px;font-weight:800;margin-top:12px}
  .foot{border-top:1px solid var(--line);padding:22px 0;color:var(--muted)}
  .foot .in{display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap}
  .foot img{width:160px;height:auto}
  .foot a{color:var(--muted);text-decoration:none;font-size:13px;font-weight:800}
  .foot a:hover{color:var(--pink)}
  @media(max-width:960px){
    .hero-grid,.offer-grid{grid-template-columns:1fr}
    .visual{min-height:520px}
    .hero-photo{inset:0 -46px -28px -34px}
    .photo-bubble{right:4px;top:112px}
    .feature-row{grid-template-columns:repeat(2,1fr);gap:24px}.feature{border-right:0}
  }
  @media(max-width:620px){
    .wrap{width:min(100% - 30px,1160px)}
    .nav .in{height:66px}.brand img{width:48px;height:52px}.navlinks a:not(.cta){display:none}
    .hero{padding-top:30px}h1{font-size:58px}.pre{font-size:31px}.lede{font-size:18px}
    .visual{min-height:390px;margin-top:22px}
    .hero-photo{inset:0 -105px -18px -34px}
    .hero-photo img{object-position:62% center}
    .photo-bubble{display:none}
    .pain-note{padding:22px 20px}.feature-row{grid-template-columns:1fr}
    .banner{padding:42px 24px 30px;grid-template-columns:1fr;gap:10px}.banner h2{font-size:31px}.banner p{font-size:16px}.growth-art{min-height:236px;margin-top:-8px}.growth-art img{width:93%;max-height:255px;object-position:center}
    .price-card{padding-bottom:34px}.price-card .price{font-size:58px}
  }
  /* ═══════════════ HERO SOLO MÓVIL (≤768px) ═══════════════
     Capa aparte. NO altera el desktop: solo se muestra bajo 768px. */
  .hero-mobile{display:none}
  @media (max-width:768px){
    .hero-desktop{display:none !important}
    .hero-mobile{display:block}
    .hero{padding:0}
  }
  .hero-mobile{width:100%;overflow:hidden;background:#fff;padding:32px 0 56px}
  .hero-mobile__content{width:min(100% - 40px,480px);margin-inline:auto}
  .hero-mobile__eyebrow{margin:0 0 12px;color:#062A4E;font-family:var(--hand);font-size:clamp(28px,8vw,38px);line-height:1;font-weight:700;transform:rotate(-1deg)}
  .hero-mobile__title{margin:0;color:#061F3B;font-family:var(--display);font-weight:400;text-transform:uppercase;font-size:clamp(34px,10vw,48px);line-height:.96;letter-spacing:.01em}
  .text-magenta{color:var(--pink)} .text-teal{color:var(--teal)}
  .text-underlined{position:relative;display:inline-block}
  .text-underlined::after{content:"";position:absolute;left:0;right:0;bottom:-5px;height:5px;border-radius:999px;background:var(--teal)}
  .hero-mobile__description{margin:20px 0 0;max-width:36ch;color:#36506B;font-size:17px;line-height:1.55}
  .hero-mobile__description strong{font-weight:800}
  .hero-mobile__actions{display:flex;flex-direction:column;align-items:flex-start;gap:16px;margin-top:26px}
  .hero-mobile .button--primary{display:inline-flex;align-items:center;justify-content:center;min-height:56px;padding:14px 24px;border-radius:999px;background:var(--pink);color:#fff;font-size:17px;font-weight:800;text-decoration:none;box-shadow:0 14px 30px rgba(239,67,117,.22)}
  .hero-mobile__plans-link{color:#061F3B;font-size:16px;font-weight:800;text-decoration:none;border-bottom:3px solid var(--teal);padding-bottom:3px}
  /* teléfono */
  .hero-mobile__visual{position:relative;width:min(102%,532px);margin:42px auto 0;min-height:auto}
  .hero-mobile__phone{position:relative;z-index:1;display:block;width:100%;height:auto;margin-inline:auto;object-fit:contain;object-position:top center}
  /* card flotante */
  .growth-card{position:absolute;z-index:3;top:17%;right:2%;width:min(46vw,190px);padding:16px;border:1px solid rgba(6,31,59,.06);border-radius:20px;background:#fff;box-shadow:0 20px 50px rgba(6,31,59,.18),0 4px 12px rgba(6,31,59,.08);transform:rotate(2deg)}
  .growth-card__icon{display:grid;place-items:center;width:36px;height:36px;margin-bottom:10px;border-radius:50%;background:var(--teal);color:#fff}
  .growth-card__icon svg{width:21px;height:21px;fill:none;stroke:currentColor;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round}
  .growth-card__title{margin:0;color:#061F3B;font-size:15px;line-height:1.2;font-weight:800}
  .growth-card__metric{margin:8px 0 0;color:var(--teal);font-size:14px;line-height:1.25;font-weight:800}
  /* partículas */
  .hero-particle{position:absolute;z-index:2;width:10px;height:10px;border-radius:3px;pointer-events:none}
  .hero-particle--one{left:5%;top:36%;background:var(--pink)}
  .hero-particle--two{right:6%;top:43%;background:var(--teal)}
  .hero-particle--three{left:10%;top:65%;background:var(--teal)}
  /* card ¿Te suena? */
  .pain-card{position:relative;z-index:4;width:min(100% - 32px,520px);margin:-150px auto 0;padding:28px 24px;border:1px solid rgba(0,164,159,.20);border-radius:28px;background:#F1FBFA;box-shadow:0 20px 50px rgba(6,31,59,.08)}
  .pain-card__title{display:inline-block;margin:0 0 20px;color:var(--teal);font-family:var(--hand);font-size:34px;line-height:1;border-bottom:4px solid var(--pink);text-transform:uppercase}
  .pain-card__list{display:grid;gap:14px;margin:0;padding:0;list-style:none}
  .pain-card__list li{position:relative;padding-left:36px;color:#061F3B;font-size:16px;line-height:1.35;font-weight:700}
  .pain-card__list li::before{content:"×";position:absolute;left:0;top:-1px;display:grid;place-items:center;width:23px;height:23px;border-radius:50%;background:var(--pink);color:#fff;font-size:17px;line-height:1;font-weight:800}
  .pain-card__footer{display:flex;flex-direction:column;margin-top:26px;padding-top:22px;border-top:1px solid rgba(0,164,159,.18)}
  .pain-card__footer strong{color:var(--teal);font-family:var(--hand);font-size:27px;line-height:1;font-weight:700;text-transform:uppercase}
  .pain-card__footer span{margin-top:6px;color:#062A4E;font-family:var(--hand);font-size:22px;line-height:1.05;text-transform:uppercase}
  /* accesibilidad */
  .hero-mobile a:focus-visible{outline:3px solid var(--teal);outline-offset:3px;border-radius:6px}
  @media (max-width:390px){
    .hero-mobile__content{width:min(100% - 32px,480px)}
    .hero-mobile__title{font-size:clamp(31px,9.5vw,39px)}
    .hero-mobile__description{font-size:16px}
    .hero-mobile__visual{width:104%;margin-inline:auto;min-height:auto}
    .growth-card{right:8%;width:165px;padding:14px}
    .pain-card{width:calc(100% - 24px);padding:25px 20px}
  }
  @media (prefers-reduced-motion:no-preference){
    .growth-card{animation:growth-card-in 700ms ease-out 250ms both}
    @keyframes growth-card-in{from{opacity:0;transform:translateY(18px) rotate(2deg)}to{opacity:1;transform:translateY(0) rotate(2deg)}}
  }
</style>
</head>
<body>
<nav class="nav">
  <div class="in wrap">
    <a class="brand" href="/crecer/crecer.php"><img src="/crecer/assets/brand/encuentralo-crecer-pin-drop.png" alt="Encuéntralo Crecer"></a>
    <div class="navlinks">
      <a class="login" href="/crecer/login.php">Entrar</a>
      <a class="cta" href="/crecer/registro.php">Probar gratis</a>
    </div>
  </div>
</nav>

<main>
  <header class="hero">
    <div class="hero-grid wrap hero-desktop">
      <section>
        <p class="pre hand">Emprender es difícil.</p>
        <h1>Hacerse <span class="pink">conocer</span><br>no tiene por qué<br><span class="teal underline">serlo.</span></h1>
        <p class="lede"><b>Encuéntralo Crecer</b> es tu asistente de inteligencia artificial que te ayuda a atraer clientes, ahorrar tiempo y <strong>hacer crecer tu negocio.</strong></p>
        <div class="hero-actions">
          <a class="cta" href="/crecer/registro.php">Crear mi primer post</a>
          <a class="secondary" href="#planes">Ver planes desde $<?= (int)$precio_crecer ?>/mes</a>
        </div>

        <div class="pain-note">
          <h2><span>¿Te suena?</span></h2>
          <ul>
            <li><span class="xmark"><?= ico('x') ?></span>No sabes qué publicar.</li>
            <li><span class="xmark"><?= ico('x') ?></span>No tienes tiempo.</li>
            <li><span class="xmark"><?= ico('x') ?></span>Tus redes están abandonadas.</li>
            <li><span class="xmark"><?= ico('x') ?></span>No sabes de marketing.</li>
            <li><span class="xmark"><?= ico('x') ?></span>Sientes que tu negocio merece más.</li>
          </ul>
          <p class="alone">No estás solo.<small>Estamos aquí para ayudarte.</small></p>
        </div>
      </section>

      <section class="visual" aria-label="Vista previa de Crecer">
        <div class="hero-photo">
          <img src="/crecer/assets/crecer-contenido/hero-foto-crecer.png" alt="Teléfono con la app Encuéntralo Crecer junto a una taza y una planta">
        </div>
        <div class="photo-bubble">Tu negocio<br>en buenas manos.</div>
      </section>
    </div>

    <!-- ── HERO SOLO MÓVIL (≤768px). Desktop intacto arriba. ── -->
    <div class="hero-mobile">
      <div class="hero-mobile__content">
        <p class="hero-mobile__eyebrow">Emprender es difícil.</p>
        <h1 class="hero-mobile__title">Hacerse <span class="text-magenta">conocer</span><br>no tiene por qué<br><span class="text-teal text-underlined">serlo.</span></h1>
        <p class="hero-mobile__description"><strong class="text-magenta">Encuéntralo Crecer</strong> es tu asistente de inteligencia artificial que te ayuda a atraer clientes, ahorrar tiempo y <strong class="text-teal">hacer crecer tu negocio.</strong></p>
        <div class="hero-mobile__actions">
          <a class="button button--primary" href="/crecer/registro.php">Crear mi primer post</a>
          <a class="hero-mobile__plans-link" href="#planes">Ver planes desde $<?= (int)$precio_crecer ?>/mes</a>
        </div>
      </div>

      <div class="hero-mobile__visual">
        <img class="hero-mobile__phone" src="/crecer/assets/images/crecer-phone-mobile.png"
             alt="Aplicación Encuéntralo Crecer mostrada en un teléfono" loading="eager" decoding="async">

        <div class="growth-card" aria-label="Ejemplo de resultado generado por Crecer">
          <div class="growth-card__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M4 17l5-5 4 4 7-8"></path><path d="M15 8h5v5"></path></svg>
          </div>
          <p class="growth-card__title">Tu negocio está creciendo</p>
          <p class="growth-card__metric">+14 clientes esta semana</p>
        </div>

        <span class="hero-particle hero-particle--one" aria-hidden="true"></span>
        <span class="hero-particle hero-particle--two" aria-hidden="true"></span>
        <span class="hero-particle hero-particle--three" aria-hidden="true"></span>
      </div>

      <article class="pain-card">
        <h2 class="pain-card__title">¿Te suena?</h2>
        <ul class="pain-card__list">
          <li>No sabes qué publicar.</li>
          <li>No tienes tiempo.</li>
          <li>Tus redes están abandonadas.</li>
          <li>No sabes de marketing.</li>
          <li>Sientes que tu negocio merece más.</li>
        </ul>
        <div class="pain-card__footer">
          <strong>No estás solo.</strong>
          <span>Estamos aquí para ayudarte.</span>
        </div>
      </article>
    </div>
  </header>

  <section class="features wrap" aria-label="Beneficios">
    <h2>Con <span>Encuéntralo Crecer</span> puedes:</h2>
    <div class="feature-row">
      <article class="feature"><div class="icon"><?= ico('lightbulb') ?></div><p>Tener ideas de contenido sin romperte la cabeza.</p></article>
      <article class="feature"><div class="icon"><?= ico('pen') ?></div><p>Publicar contenido que atrae y conecta con tu gente.</p></article>
      <article class="feature"><div class="icon"><?= ico('calendar') ?></div><p>Organizar tus publicaciones y olvidarte del estrés.</p></article>
      <article class="feature"><div class="icon"><?= ico('chat') ?></div><p>Responder a tus clientes rápido y de forma profesional.</p></article>
      <article class="feature"><div class="icon"><?= ico('chart') ?></div><p>Hacer crecer tu negocio con recomendaciones inteligentes.</p></article>
    </div>
  </section>

  <section class="offer wrap" id="planes">
    <div class="offer-grid">
      <div class="banner">
        <div class="banner-copy">
          <h2>Tu negocio tiene potencial.<span>Nosotros te ayudamos a hacerlo <b>crecer.</b></span></h2>
          <span class="accent-line" aria-hidden="true"></span>
          <p><?= $nf($acciones) ?> acciones de IA registradas para <?= $nf($negocios) ?> negocios y contando.</p>
        </div>
        <picture class="growth-art" aria-hidden="true">
          <source media="(max-width: 620px)" srcset="/crecer/assets/crecer-contenido/grafica-crecimiento-mobile-square.png">
          <img src="/crecer/assets/crecer-contenido/grafica-crecimiento-tight.png" alt="">
        </picture>
      </div>
      <aside class="price-card">
        <div class="hand">Bueno, bonito<br>y asequible</div>
        <div class="line"></div>
        <div class="desde">Planes desde</div>
        <div class="price">$<?= (int)$precio_crecer ?><small>/mes</small></div>
        <p>Hecho para emprendedores como tú.</p>
        <a class="price-cta" href="/crecer/registro.php">Empezar gratis</a>
        <span class="price-note">Tu primer post, gratis · sin tarjeta</span>
      </aside>
    </div>
  </section>

  <section class="final wrap">
    <h2>Haz que tu negocio se vea <span>presente, activo y listo para crecer.</span></h2>
    <a class="cta" href="/crecer/registro.php">Crea tu primer post gratis</a>
    <div class="micro">Sin tarjeta · empiezas con tu post de muestra.</div>
  </section>
</main>

<footer class="foot">
  <div class="in wrap">
    <img src="/crecer/assets/brand/encuentralo-crecer-completo.png" alt="Encuéntralo Crecer">
    <span>Conecta. Impulsa. <b style="color:var(--pink)">Crece.</b></span>
    <span><a href="/crecer/terminos.php">Términos</a> · <a href="/crecer/privacidad.php">Privacidad</a> · <a href="/crecer/eliminar-datos.php">Eliminar datos</a></span>
  </div>
</footer>
</body>
</html>
