<?php
// ============================================================
//  ENCUÉNTRALO — Hub / index público (hero premium con PNG assets)
//  index.php  ·  assets en /crecer/assets/encuentralo-hero/
// ============================================================
$acciones = 113; $negocios = 7;
try {
    if (is_file(__DIR__ . '/includes/config.local.php')) require_once __DIR__ . '/includes/config.local.php';
    if (defined('DB_NAME') && DB_NAME !== '') {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $r = $pdo->query("SELECT COUNT(*) a, COUNT(DISTINCT marca_id) n FROM crecer_ia_log WHERE estado='ok'")->fetch(PDO::FETCH_ASSOC);
        if ($r) { $acciones = max($acciones,(int)$r['a']); $negocios = max($negocios,(int)$r['n']); }
    }
} catch (Throwable $e) {}
$nf = fn($n) => number_format($n);
$A = '/crecer/assets/encuentralo-hero';
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
<link href="https://fonts.googleapis.com/css2?family=Anton&family=Poppins:wght@600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  :root{
    --ec-ink:#17131f; --ec-paper:rgba(255,255,255,.72); --ec-muted:#686173;
    --ec-pink:#ef3b7d; --ec-hot:#ff2f82; --ec-coral:#ff5b45; --ec-cyan:#00a7b7;
    --ec-line:#f1dece; --ec-shadow:0 28px 80px rgba(40,28,12,.13);
    --ec-disp:'Anton', Impact, Haettenschweiler, "Arial Black", sans-serif;
  }
  *{box-sizing:border-box}
  body{margin:0;color:var(--ec-ink);font-family:'Plus Jakarta Sans',system-ui,sans-serif;min-height:100vh;
    background:
      radial-gradient(closest-side 60% 50% at 8% 4%, rgba(255,142,69,.22), transparent),
      radial-gradient(closest-side 55% 45% at 96% 16%, rgba(255,47,130,.16), transparent),
      radial-gradient(closest-side 55% 50% at 18% 62%, rgba(0,167,183,.10), transparent),
      linear-gradient(180deg,#fffaf4 0%,#fff6ed 100%);
    background-attachment:fixed}
  body::before{content:"";position:fixed;top:0;left:0;right:0;height:4px;z-index:60;background:linear-gradient(120deg,var(--ec-coral),var(--ec-hot))}

  /* NAV (fijo arriba, ancho completo) */
  .ec-navbar{position:sticky;top:0;z-index:50;backdrop-filter:saturate(1.2) blur(10px);
    background:rgba(255,250,244,.82);border-bottom:1px solid rgba(241,222,206,.7)}
  .ec-nav{display:flex;align-items:center;gap:10px;max-width:1320px;margin:0 auto;padding:14px 34px}
  .ec-nav a.brand{display:flex;align-items:center;gap:9px;text-decoration:none;color:var(--ec-ink)}
  .ec-nav img{height:30px}
  .ec-nav b{font-family:'Poppins',sans-serif;font-weight:800;font-size:20px;letter-spacing:-.03em;text-transform:lowercase}
  .ec-nav .sp{flex:1}
  .ec-nav .enter{font-weight:800;font-size:14.5px;color:#fff;text-decoration:none;padding:10px 20px;border-radius:999px;
    background:linear-gradient(135deg,var(--ec-coral),var(--ec-hot));box-shadow:0 12px 26px -10px rgba(255,47,130,.6)}

  /* HERO (paquete ChatGPT) */
  .ec-hero-page{position:relative;max-width:1320px;margin:0 auto;padding:14px 34px 56px;overflow:hidden;color:var(--ec-ink);background:transparent}
  .ec-png{position:absolute;pointer-events:none;user-select:none;height:auto;z-index:0}
  .ec-star-cyan{width:84px;left:150px;top:92px;animation:ecFloat 5.5s ease-in-out infinite}
  .ec-star-pink{width:82px;right:130px;top:124px;animation:ecFloat 6s ease-in-out infinite .8s}
  .ec-star-yellow{width:42px;right:150px;top:288px;animation:ecFloat 5.2s ease-in-out infinite 1.4s}
  .ec-dash-left{width:105px;left:120px;top:240px;transform:rotate(-9deg);opacity:.9}
  .ec-swipe-right{width:150px;right:-28px;top:355px;opacity:.9}
  @keyframes ecFloat{0%,100%{transform:translateY(0) rotate(-3deg)}50%{transform:translateY(-12px) rotate(5deg)}}
  .ec-hero{position:relative;z-index:2;text-align:center;padding-bottom:42px}
  .ec-pill{display:inline-flex;align-items:center;gap:10px;padding:10px 19px;border:1px solid rgba(239,59,125,.22);border-radius:999px;
    background:rgba(255,255,255,.58);box-shadow:0 16px 34px rgba(239,59,125,.10);color:var(--ec-pink);font-size:13px;font-weight:800;
    text-transform:uppercase;letter-spacing:.12em;backdrop-filter:blur(12px)}
  .ec-pill strong{display:inline-grid;place-items:center;width:24px;height:24px;border-radius:8px;color:#fff;
    background:linear-gradient(135deg,var(--ec-coral),var(--ec-hot));font-size:12px;letter-spacing:-.04em}
  .ec-hero h1{margin:30px auto 0;max-width:980px;font-family:var(--ec-disp);font-weight:400;font-size:clamp(56px,7.7vw,118px);
    line-height:.86;letter-spacing:-.02em;text-transform:uppercase}
  .ec-line-one{display:block;color:#12101b}
  .ec-line-two{display:block;background:linear-gradient(90deg,var(--ec-coral),var(--ec-hot));-webkit-background-clip:text;background-clip:text;
    color:transparent;font-style:italic;transform:skewX(-4deg);margin-top:8px}
  .ec-brush{display:block;width:min(760px,74vw);margin:-12px auto 0;filter:drop-shadow(0 12px 18px rgba(239,59,125,.20));transform:rotate(-.4deg)}
  .ec-sub{max-width:730px;margin:20px auto 12px;color:#554d61;font-size:20px;line-height:1.45}
  .ec-sub strong{color:var(--ec-pink)}
  .ec-activity{display:inline-flex;align-items:center;gap:12px;margin-top:8px;padding:12px 20px;border-radius:999px;
    background:rgba(255,255,255,.76);border:1px solid var(--ec-line);box-shadow:0 16px 36px rgba(39,27,10,.09);color:#5c5664;font-size:15px;backdrop-filter:blur(12px)}
  .ec-dot{width:12px;height:12px;border-radius:50%;background:#16df68;box-shadow:0 0 0 8px rgba(22,223,104,.13),0 0 22px rgba(22,223,104,.75);animation:ecPulse 1.7s infinite}
  @keyframes ecPulse{0%{box-shadow:0 0 0 0 rgba(22,223,104,.4),0 0 22px rgba(22,223,104,.7)}70%{box-shadow:0 0 0 13px rgba(22,223,104,0),0 0 22px rgba(22,223,104,.7)}100%{box-shadow:0 0 0 0 rgba(22,223,104,0),0 0 22px rgba(22,223,104,.7)}}
  .ec-cards{position:relative;z-index:2;width:min(1050px,100%);margin:26px auto 0;display:grid;grid-template-columns:1fr 1fr;gap:58px;text-align:left}
  .ec-card{position:relative;min-height:270px;padding:58px 52px 38px 210px;border-radius:31px;background:var(--ec-paper);border:1px solid var(--ec-line);
    box-shadow:var(--ec-shadow);overflow:visible;backdrop-filter:blur(14px);transition:.25s ease;text-decoration:none;color:inherit;display:block}
  .ec-card:hover{transform:translateY(-6px);box-shadow:0 36px 92px rgba(40,28,12,.16)}
  .ec-featured{border:2px solid var(--ec-hot);padding-left:245px;box-shadow:0 30px 92px rgba(239,59,125,.13),0 24px 70px rgba(40,28,12,.09)}
  .ec-card-art{position:absolute;pointer-events:none;z-index:3;height:auto;filter:drop-shadow(0 28px 28px rgba(0,0,0,.18))}
  .ec-magnifier{width:300px;left:-120px;top:-30px;transform:rotate(-8deg)}
  .ec-rocket{width:300px;left:-28px;top:-48px;transform:rotate(7deg);filter:drop-shadow(0 28px 34px rgba(239,59,125,.20))}
  .ec-card h2{margin:0;font-family:var(--ec-disp);font-weight:400;text-transform:uppercase;font-size:42px;line-height:.88;letter-spacing:-.025em;color:#15121f}
  .ec-card h2 span{display:block;margin-top:4px;background:linear-gradient(90deg,var(--ec-cyan),#0bb9be);-webkit-background-clip:text;background-clip:text;color:transparent}
  .ec-featured h2 span{background:linear-gradient(90deg,var(--ec-coral),var(--ec-hot));-webkit-background-clip:text;background-clip:text;color:transparent}
  .ec-mini{margin-top:14px;font-weight:800;font-size:14px}
  .ec-card p{max-width:315px;margin:14px 0 0;color:var(--ec-muted);font-size:16.5px;line-height:1.42}
  .ec-cta{display:inline-flex;margin-top:22px;color:var(--ec-cyan);font-weight:800;text-decoration:none}
  .ec-featured .ec-cta{color:var(--ec-hot)}
  .ec-tag{position:absolute;top:20px;right:20px;padding:10px 16px;border-radius:999px;background:linear-gradient(90deg,var(--ec-coral),var(--ec-hot));
    color:#fff;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.04em}
  .ec-benefits{position:relative;z-index:2;width:min(1110px,100%);margin:48px auto 0;padding-top:36px;border-top:1px solid var(--ec-line);
    display:grid;grid-template-columns:repeat(3,1fr);gap:38px}
  .ec-benefit{position:relative;min-height:150px;padding:20px 20px 14px 150px;border-right:1px solid rgba(240,222,206,.85)}
  .ec-benefit:last-child{border-right:0}
  .ec-benefit-art{position:absolute;left:0;top:0;width:130px;height:auto;filter:drop-shadow(0 16px 18px rgba(0,0,0,.13))}
  .ec-benefit h3{margin:14px 0 10px;font-family:var(--ec-disp);font-weight:400;font-size:28px;line-height:.95;text-transform:uppercase;letter-spacing:-.025em}
  .ec-benefit p{margin:0;color:var(--ec-muted);font-size:16px;line-height:1.42;max-width:250px}
  .ec-foot{text-align:center;color:var(--ec-muted);font-size:13px;padding:26px}
  .ec-foot b{color:var(--ec-pink)}

  @media(max-width:920px){
    .ec-cards{grid-template-columns:1fr;gap:34px;margin-top:36px}
    .ec-card,.ec-featured{padding:220px 32px 34px}
    .ec-magnifier{width:280px;left:20px;top:-45px}
    .ec-rocket{width:285px;left:28px;top:-58px}
    .ec-benefits{grid-template-columns:1fr;gap:18px}
    .ec-benefit{border-right:0;border-bottom:1px solid var(--ec-line);padding-left:150px}
    .ec-benefit:last-child{border-bottom:0}
    .ec-star-cyan,.ec-star-pink,.ec-star-yellow,.ec-dash-left,.ec-swipe-right{opacity:.55}
  }
  @media(max-width:600px){
    .ec-hero-page{padding:8px 18px 46px}.ec-nav{padding:14px 18px}
    .ec-hero h1{font-size:58px}.ec-sub{font-size:17px}.ec-activity{font-size:13px}
    .ec-brush{margin-top:-4px;width:88vw}.ec-card h2{font-size:36px}
    .ec-card,.ec-featured{padding:195px 26px 30px}
    .ec-magnifier,.ec-rocket{width:245px}
    .ec-benefit{padding-left:120px}.ec-benefit-art{width:105px}
  }
  @media(prefers-reduced-motion:reduce){.ec-png,.ec-dot{animation:none}}
</style>
</head>
<body>

<header class="ec-navbar">
  <nav class="ec-nav">
    <a class="brand" href="/crecer/index.php"><img src="/crecer/assets/brand/encuentralo-pin.svg" alt=""><b>encuéntralo</b></a>
    <span class="sp"></span>
    <a class="enter" href="/crecer/login.php">Entrar</a>
  </nav>
</header>

<section class="ec-hero-page">
  <img class="ec-png ec-star-cyan"  src="<?= $A ?>/cyan_star.png" alt="">
  <img class="ec-png ec-star-pink"  src="<?= $A ?>/pink_star.png" alt="">
  <img class="ec-png ec-star-yellow" src="<?= $A ?>/yellow_star.png" alt="">
  <img class="ec-png ec-dash-left"  src="<?= $A ?>/pink_dash_left.png" alt="">
  <img class="ec-png ec-swipe-right" src="<?= $A ?>/cyan_swipe_right.png" alt="">

  <div class="ec-hero">
    <div class="ec-pill"><strong>PR</strong> Hecho en PR pa' negocios de aquí</div>
    <h1>
      <span class="ec-line-one">Todo tu negocio,</span>
      <span class="ec-line-two">en un solo sitio.</span>
    </h1>
    <img class="ec-brush" src="<?= $A ?>/brush_hero.png" alt="">
    <p class="ec-sub">Busca servicios locales o pon el corillo de IA a trabajar por tu negocio. Tú haces <strong>lo tuyo</strong>, nosotros movemos el resto.</p>
    <div class="ec-activity">
      <span class="ec-dot"></span>
      <span>El corillo ya trabaja <strong><?= $nf($negocios) ?> negocios</strong> · <strong><?= $nf($acciones) ?> acciones de IA</strong></span>
    </div>
  </div>

  <div class="ec-cards">
    <a class="ec-card" href="/crecer/buscar.php">
      <img class="ec-card-art ec-magnifier" src="<?= $A ?>/magnifier.png" alt="">
      <h2>Busco un <span>servicio</span></h2>
      <p>Encuentra reposterías, comida, belleza, servicios y negocios boricuas cerca de ti.</p>
      <span class="ec-cta">Ver el directorio →</span>
    </a>
    <a class="ec-card ec-featured" href="/crecer/crecer.php">
      <div class="ec-tag">● Corillo activo</div>
      <img class="ec-card-art ec-rocket" src="<?= $A ?>/rocket.png" alt="">
      <h2>Tengo un <span>negocio</span></h2>
      <div class="ec-mini">…o una idea</div>
      <p>La IA te ayuda con contenido, gráficas, agenda, órdenes y crecimiento desde el celular.</p>
      <span class="ec-cta">Conoce Crecer →</span>
    </a>
  </div>

  <div class="ec-benefits">
    <article class="ec-benefit">
      <img class="ec-benefit-art" src="<?= $A ?>/pr_mark.png" alt="">
      <h3>Hecho en PR</h3>
      <p>Voz boricua auténtica, negocios locales de verdad.</p>
    </article>
    <article class="ec-benefit">
      <img class="ec-benefit-art" src="<?= $A ?>/bot_mark.png" alt="">
      <h3>La IA mete mano</h3>
      <p>Planifica, crea y responde. Tú apruebas desde el celular.</p>
    </article>
    <article class="ec-benefit">
      <img class="ec-benefit-art" src="<?= $A ?>/growth_mark.png" alt="">
      <h3>Creces de verdad</h3>
      <p>Más visibilidad, más órdenes, mejores reseñas.</p>
    </article>
  </div>
</section>

<p class="ec-foot">© Encuéntralo — hecho en <b>Puerto Rico</b></p>

</body>
</html>
