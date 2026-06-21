<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Landing de venta (rediseño "brutal")
//  crecer.php  ·  /crecer
//
//  Identidad "El Corillo": Anton de impacto + paleta boricua.
//  Prueba social VIVA desde crecer_ia_log (con fallback si la BD
//  no responde — la landing nunca debe caerse).
// ============================================================
require __DIR__ . '/includes/iconos.php';

// ── Prueba social viva (defensiva: nunca tumba la página) ──
$acciones = 113; $negocios = 7;
try {
    if (is_file(__DIR__ . '/includes/config.local.php')) require_once __DIR__ . '/includes/config.local.php';
    if (defined('DB_NAME') && DB_NAME !== '') {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $r = $pdo->query("SELECT COUNT(*) a, COUNT(DISTINCT marca_id) n FROM crecer_ia_log WHERE estado='ok'")->fetch(PDO::FETCH_ASSOC);
        if ($r) { $acciones = max($acciones, (int)$r['a']); $negocios = max($negocios, (int)$r['n']); }
    }
} catch (Throwable $e) { /* usa el fallback */ }
$nf = fn($n) => number_format($n);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Crecer · Tu departamento de marketing con IA — Encuéntralo</title>
<meta name="description" content="La IA te corre el marketing del negocio — contenido, redes y clientela — y tú solo apruebas desde el celular. Hecho en y para Puerto Rico.">
<link rel="icon" type="image/svg+xml" href="/crecer/assets/brand/encuentralo-pin.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Anton&family=Poppins:wght@600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="/crecer/assets/encuentralo-ui.css?v=7" rel="stylesheet">
<style>
  :root{ --grad:linear-gradient(120deg,var(--coral,#ff5c39),var(--magenta,#c0395f)); }
  *{box-sizing:border-box}
  body{background:var(--crema,#fbf6ee);color:var(--tinta,#1b1622);overflow-x:hidden;
    font-family:'Plus Jakarta Sans',system-ui,sans-serif}
  .wrap{max-width:1120px;margin:0 auto;padding:0 24px}
  .disp{font-family:'Anton',sans-serif;text-transform:uppercase;letter-spacing:.01em;line-height:.94;font-weight:400}
  .g{background:var(--grad);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}

  /* NAV */
  .nav{position:sticky;top:0;z-index:50;backdrop-filter:saturate(1.2) blur(10px);
    background:color-mix(in srgb,var(--crema) 82%,transparent);border-bottom:1px solid var(--line)}
  .nav .in{display:flex;align-items:center;gap:12px;max-width:1120px;margin:0 auto;padding:13px 24px}
  .nav .mark{height:30px}
  .nav .bn{font-family:var(--font-display);font-weight:800;text-transform:lowercase;font-size:20px;letter-spacing:-.03em}
  .nav .sp{flex:1}
  .nav .enter{font-weight:700;font-size:14.5px;color:var(--muted);text-decoration:none;padding:9px 14px;border-radius:99px}
  .nav .enter:hover{color:var(--tinta)}
  .nav .cta{font-weight:800;font-size:14.5px;color:#fff;background:var(--grad);text-decoration:none;
    padding:10px 18px;border-radius:99px;box-shadow:0 10px 24px -10px rgba(192,57,95,.7)}
  .nav .cta:hover{filter:brightness(1.06)}

  /* HERO */
  .hero{position:relative;padding:60px 0 40px}
  .hero::before{content:"";position:absolute;inset:0;z-index:-1;
    background:radial-gradient(60% 70% at 78% 8%,rgba(255,92,57,.16),transparent 60%),
               radial-gradient(50% 60% at 8% 30%,rgba(192,57,95,.12),transparent 55%)}
  .hero .in{display:grid;grid-template-columns:1.05fr .95fr;gap:40px;align-items:center}
  .eyebrow{display:inline-flex;align-items:center;gap:8px;font-weight:800;font-size:12.5px;letter-spacing:.1em;
    text-transform:uppercase;color:var(--terracota,#e3683f);background:color-mix(in srgb,var(--terracota) 12%,#fff);
    border:1px solid color-mix(in srgb,var(--terracota) 24%,#fff);padding:7px 14px;border-radius:99px}
  .hero h1{font-size:clamp(44px,7vw,80px);margin:18px 0 0}
  .hero .sub{font-size:clamp(16px,1.5vw,19px);color:var(--muted);margin:20px 0 0;max-width:38ch;line-height:1.5}
  .hero .actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:28px}
  .btn-primary{font-weight:800;font-size:16px;color:#fff;background:var(--grad);text-decoration:none;
    padding:15px 28px;border-radius:99px;box-shadow:0 16px 36px -12px rgba(192,57,95,.6);transition:transform .15s,filter .15s}
  .btn-primary:hover{transform:translateY(-2px);filter:brightness(1.06)}
  .btn-ghost{font-weight:800;font-size:16px;color:var(--tinta);background:#fff;border:1.5px solid var(--line);
    text-decoration:none;padding:15px 24px;border-radius:99px}
  .btn-ghost:hover{border-color:var(--terracota)}
  .proof{display:flex;align-items:center;gap:10px;margin-top:26px;font-size:14px;color:var(--muted)}
  .proof b{font-family:'Anton',sans-serif;font-size:22px;color:var(--tinta);letter-spacing:.02em}
  .proof .dot{width:8px;height:8px;border-radius:50%;background:var(--palma,#16b86a);box-shadow:0 0 0 4px rgba(22,184,106,.18)}

  /* HERO phone mockup (puro CSS, sin imágenes) */
  .phone{justify-self:center;width:300px;max-width:84vw;background:#0e0a16;border-radius:38px;padding:12px;
    box-shadow:0 40px 90px -30px rgba(27,22,34,.55),0 0 0 2px rgba(255,255,255,.04) inset;position:relative}
  .phone .scr{background:var(--crema);border-radius:28px;overflow:hidden}
  .phone .bar{display:flex;align-items:center;gap:8px;padding:14px 16px 10px;background:#fff;border-bottom:1px solid var(--line)}
  .phone .bar .av{width:30px;height:30px;border-radius:50%;background:var(--grad)}
  .phone .bar b{font-size:13.5px}
  .phone .bar .live{margin-left:auto;font-size:10px;font-weight:800;color:var(--palma);text-transform:uppercase;letter-spacing:.06em}
  .pcard{margin:14px;background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden;box-shadow:var(--shadow-sm)}
  .pcard .pimg{height:128px;background:
    radial-gradient(circle at 30% 30%,rgba(255,255,255,.5),transparent 40%),
    linear-gradient(135deg,#ffb27a,#e3683f 55%,#c0395f);position:relative}
  .pcard .pimg .emoji{position:absolute;inset:0;display:grid;place-items:center;font-size:46px;filter:drop-shadow(0 6px 10px rgba(0,0,0,.2))}
  .pcard .pcap{padding:11px 13px;font-size:12.5px;line-height:1.45;color:#2a2230}
  .pcard .pact{display:flex;gap:8px;padding:0 13px 13px}
  .pcard .pact .ok{flex:1;text-align:center;font-weight:800;font-size:12.5px;color:#fff;background:var(--palma);border-radius:10px;padding:9px}
  .pcard .pact .sh{font-weight:800;font-size:12.5px;color:var(--tinta);background:var(--crema);border:1px solid var(--line);border-radius:10px;padding:9px 12px}
  .float{position:absolute;left:-26px;background:#fff;border:1px solid var(--line);border-radius:14px;
    padding:9px 12px;box-shadow:0 16px 30px -14px rgba(27,22,34,.4);font-size:12px;font-weight:700;display:flex;align-items:center;gap:8px}
  .float .o{width:26px;height:26px;border-radius:50%;display:grid;place-items:center;font-size:14px;background:color-mix(in srgb,var(--magenta) 14%,#fff)}
  .float.f1{top:74px;animation:bob 4s ease-in-out infinite}
  .float.f2{bottom:60px;left:auto;right:-22px;animation:bob 4.6s ease-in-out infinite .5s}
  @keyframes bob{0%,100%{transform:translateY(0)}50%{transform:translateY(-9px)}}

  /* Tira "el corillo" */
  .band{margin-top:46px}
  .band .lab{text-align:center;font-weight:800;font-size:12.5px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)}
  .agents{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin-top:18px;position:relative;perspective:900px}
  .ag{background:var(--card,#fff);border:1px solid var(--line);border-radius:16px;padding:16px 10px;text-align:center;
    transition:transform .18s,box-shadow .18s}
  .ag{position:relative;transform-style:preserve-3d;transition:transform .1s ease-out,box-shadow .2s}
  .ag:hover{transform:translateY(-8px);box-shadow:0 26px 50px -18px color-mix(in srgb,var(--c) 50%,rgba(27,22,34,.5)),0 0 0 2px color-mix(in srgb,var(--c) 40%,transparent),0 0 30px -2px color-mix(in srgb,var(--c) 55%,transparent)}
  .ag .o{width:56px;height:56px;border-radius:50%;margin:0 auto 11px;display:grid;place-items:center;color:#fff;
    background:linear-gradient(140deg,color-mix(in srgb,var(--c) 72%,#fff),var(--c))}
  .ag .o svg{width:27px;height:27px;stroke:#fff;fill:none}
  .ag h4{font-family:'Anton',sans-serif;font-size:15px;letter-spacing:.02em;margin:0;text-transform:uppercase}
  .ag p{font-size:11.5px;color:var(--muted);margin:2px 0 0}

  /* Secciones */
  section{padding:64px 0}
  .sec-head{text-align:center;max-width:680px;margin:0 auto 36px}
  .sec-head h2{font-size:clamp(30px,4.6vw,52px);margin:0}
  .sec-head p{color:var(--muted);margin:14px 0 0;font-size:17px}

  /* Cómo funciona */
  .steps{display:grid;grid-template-columns:repeat(4,1fr);gap:16px}
  .step{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:24px 20px;position:relative}
  .step .n{font-family:'Anton',sans-serif;font-size:40px;color:color-mix(in srgb,var(--terracota) 30%,#fff);line-height:1}
  .step h4{font-size:17px;font-weight:800;margin:8px 0 5px}
  .step p{font-size:14px;color:var(--muted);margin:0;line-height:1.5}

  /* Bento de features */
  .bento{display:grid;grid-template-columns:repeat(6,1fr);gap:16px}
  .cell{background:var(--card);border:1px solid var(--line);border-radius:20px;padding:26px;position:relative;overflow:hidden}
  .cell.big{grid-column:span 4}.cell.sm{grid-column:span 2}
  .cell .ic{font-size:30px}
  .cell h3{font-family:'Anton',sans-serif;font-size:24px;text-transform:uppercase;letter-spacing:.02em;margin:12px 0 6px}
  .cell p{font-size:14.5px;color:var(--muted);margin:0;line-height:1.55;max-width:44ch}
  .cell.hero-cell{background:linear-gradient(135deg,#241633,#0e0a16);color:#fff}
  .cell.hero-cell h3{color:#fff}.cell.hero-cell p{color:#ccc4d6}
  .cell.hero-cell .tagm{display:inline-block;margin-top:14px;font-size:12px;font-weight:800;color:#c9b8ff;
    background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.14);padding:6px 12px;border-radius:99px}

  /* Niveles */
  .plans{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;align-items:start}
  .plan{background:var(--card);border:1px solid var(--line);border-radius:22px;padding:28px 24px;position:relative}
  .plan.pop{border:2px solid transparent;transform:translateY(-10px);
    background:linear-gradient(var(--card),var(--card)) padding-box,var(--grad) border-box;
    box-shadow:0 26px 56px -24px rgba(192,57,95,.5)}
  .plan .pop-tag{position:absolute;top:-13px;left:50%;transform:translateX(-50%);background:var(--grad);color:#fff;
    font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;padding:6px 15px;border-radius:99px}
  .plan .lvl{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:var(--muted)}
  .plan .lvl svg{width:15px;height:15px}
  .plan .name{font-family:'Anton',sans-serif;font-size:30px;text-transform:uppercase;letter-spacing:.02em;margin:6px 0 2px}
  .plan .promise{font-size:14px;color:var(--muted);min-height:42px}
  .plan .price{font-family:'Anton',sans-serif;font-size:50px;letter-spacing:.01em;margin:12px 0 2px}
  .plan .price small{font-size:15px;color:var(--muted);font-weight:600;font-family:'Plus Jakarta Sans'}
  .plan ul{list-style:none;margin:18px 0 0;padding:0;display:flex;flex-direction:column;gap:11px}
  .plan li{font-size:14px;display:flex;gap:9px;align-items:flex-start;line-height:1.4}
  .plan li::before{content:"✓";color:var(--palma);font-weight:900;flex:none}
  .plan .cta{display:block;text-align:center;margin-top:22px;padding:14px;border-radius:99px;font-weight:800;font-size:15px;
    text-decoration:none;border:1.5px solid var(--line);color:var(--tinta);transition:.15s}
  .plan.pop .cta{background:var(--grad);color:#fff;border-color:transparent}
  .plan .cta:hover{border-color:var(--terracota)}.plan.pop .cta:hover{filter:brightness(1.06)}
  .note{text-align:center;color:var(--muted);font-size:13.5px;margin-top:20px}

  /* Evidencia / prueba */
  .ev{background:linear-gradient(135deg,#241633,#0e0a16);color:#fff;border-radius:26px;padding:44px;text-align:center}
  .ev h2{font-family:'Anton',sans-serif;font-size:clamp(28px,4vw,44px);text-transform:uppercase;margin:0}
  .ev p{color:#c9c2d4;font-size:16px;margin:14px auto 0;max-width:52ch}
  .ev .stats{display:flex;justify-content:center;gap:42px;flex-wrap:wrap;margin-top:30px}
  .ev .stat b{font-family:'Anton',sans-serif;font-size:46px;display:block;line-height:1}
  .ev .stat .g{display:inline}
  .ev .stat span{color:#9f96b0;font-size:13px;font-weight:700}

  /* Flywheel */
  .ring{display:flex;flex-wrap:wrap;justify-content:center;gap:10px;align-items:center}
  .node{background:#fff;border:1px solid var(--line);border-radius:99px;padding:11px 18px;font-weight:700;font-size:14px}
  .arr{color:var(--terracota);font-weight:900}

  /* CTA final */
  .final{text-align:center}
  .final h2{font-size:clamp(34px,6vw,64px);max-width:16ch;margin:0 auto}
  .final .big-cta{display:inline-block;margin-top:26px;background:var(--grad);color:#fff;font-weight:800;font-size:18px;
    padding:18px 40px;border-radius:99px;text-decoration:none;box-shadow:0 18px 40px -14px rgba(192,57,95,.6)}
  .final .big-cta:hover{filter:brightness(1.06)}
  .foot{text-align:center;color:var(--muted);font-size:13px;padding:30px}
  .foot b{color:var(--terracota)}

  /* Empatía (el "te entendemos") */
  .empat{background:linear-gradient(135deg,#2a1020,#160a12);color:#fff;border-radius:26px;padding:48px 40px;text-align:center}
  .empat .k{font-weight:800;letter-spacing:.12em;text-transform:uppercase;font-size:12.5px;color:#ffb27a}
  .empat h2{font-family:'Anton',sans-serif;font-size:clamp(28px,4.4vw,46px);text-transform:uppercase;margin:12px 0 0;line-height:1}
  .empat .pains{display:flex;justify-content:center;gap:12px;flex-wrap:wrap;margin:26px 0 0}
  .empat .pain{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.13);border-radius:14px;padding:12px 16px;font-size:14px;color:#efe7ee}
  .empat .turn{font-family:'Anton',sans-serif;font-size:clamp(22px,3.4vw,36px);text-transform:uppercase;margin-top:30px;color:#fff;letter-spacing:.01em}
  .empat .turn span{color:#ff7a4d}
  /* Voz del agente (el alma del corillo) */
  .ag .q{font-size:11.5px;font-style:italic;color:#6a5c66;margin-top:8px;line-height:1.4;min-height:46px}
  /* Manifiesto (crescendo emocional) */
  .manif{text-align:center;padding:84px 24px 70px}
  .manif h2{font-size:clamp(34px,6.2vw,70px);line-height:.98;max-width:16ch;margin:0 auto}
  .manif p{color:var(--muted);font-size:18px;margin:22px auto 0;max-width:44ch;line-height:1.55}

  /* ── Personalidad / vida ── */
  /* Orbes del corillo: laten suave (sensación de "trabajando") */
  .ag .o{animation:pulseGlow 3.8s ease-in-out infinite}
  .ag:nth-child(2) .o{animation-delay:.5s}.ag:nth-child(3) .o{animation-delay:1s}
  .ag:nth-child(4) .o{animation-delay:1.5s}.ag:nth-child(5) .o{animation-delay:2s}.ag:nth-child(6) .o{animation-delay:2.5s}
  @keyframes pulseGlow{0%,100%{box-shadow:0 8px 18px -8px color-mix(in srgb,var(--c) 55%,transparent),0 0 0 0 transparent}
    50%{box-shadow:0 8px 18px -8px color-mix(in srgb,var(--c) 55%,transparent),0 0 0 8px color-mix(in srgb,var(--c) 12%,transparent)}}
  /* Dinamismo boricua: el corillo se mece en cadencia (ola) */
  .ag-in{will-change:transform;animation:sway 3.4s ease-in-out infinite}
  .ag:nth-child(2) .ag-in{animation-delay:.28s}
  .ag:nth-child(3) .ag-in{animation-delay:.56s}
  .ag:nth-child(4) .ag-in{animation-delay:.84s}
  .ag:nth-child(5) .ag-in{animation-delay:1.12s}
  .ag:nth-child(6) .ag-in{animation-delay:1.4s}
  @keyframes sway{0%,100%{transform:translateY(0) rotate(0deg)}30%{transform:translateY(-6px) rotate(-.9deg)}65%{transform:translateY(-2px) rotate(.9deg)}}
  .ag:hover .ag-in{animation-play-state:paused}
  /* El orbe del agente baila al pasar el cursor */
  .ag .o{position:relative;transition:transform .22s cubic-bezier(.34,1.56,.64,1)}
  .ag:hover .o{transform:scale(1.18) rotate(-8deg)}
  /* Ondas eléctricas que emite cada orbe (radar) */
  .ag .o::after{content:"";position:absolute;inset:-3px;border-radius:50%;border:2px solid var(--c);opacity:0;pointer-events:none;animation:onda 2.8s ease-out infinite}
  .ag:nth-child(2) .o::after{animation-delay:.45s}.ag:nth-child(3) .o::after{animation-delay:.9s}
  .ag:nth-child(4) .o::after{animation-delay:1.35s}.ag:nth-child(5) .o::after{animation-delay:1.8s}.ag:nth-child(6) .o::after{animation-delay:2.25s}
  @keyframes onda{0%{transform:scale(1);opacity:.55}80%,100%{transform:scale(2.4);opacity:0}}
  .ag:hover .o::after{animation-duration:.9s}
  /* Chispa de los fireworks */
  .chispa{position:absolute;width:7px;height:7px;border-radius:50%;pointer-events:none;z-index:6;will-change:transform,opacity}
  /* Reflejo que sigue el cursor (luz sobre la tarjeta) */
  .ag{z-index:1}
  .ag::before{content:"";position:absolute;inset:0;border-radius:inherit;z-index:3;pointer-events:none;opacity:0;transition:opacity .25s;
    background:radial-gradient(170px circle at var(--gx,50%) var(--gy,50%),rgba(255,255,255,.55),transparent 46%)}
  .ag:hover::before{opacity:1}
  /* Rayos eléctricos entre los agentes */
  .rayos{position:absolute;inset:0;z-index:0;pointer-events:none;overflow:visible}
  .bolt{fill:none;stroke:#fff;stroke-width:2;opacity:0;filter:drop-shadow(0 0 4px #ff7a4d) drop-shadow(0 0 8px #c0395f)}
  @keyframes zap{0%{opacity:0}10%{opacity:1}28%{opacity:.35}44%{opacity:.95}100%{opacity:0}}
  /* Trazo de marcador hecho a mano bajo palabras clave (firma boricua) */
  .brush{position:relative}
  .brush::after{content:"";position:absolute;left:-3%;right:-3%;bottom:-.16em;height:.32em;pointer-events:none;
    background:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 300 18'%3E%3Cpath d='M4 11 C70 3 130 16 186 8 S281 7 296 12' stroke='%23e3683f' stroke-width='6' fill='none' stroke-linecap='round'/%3E%3C/svg%3E") center/100% 100% no-repeat}
  /* Brillo que cruza los botones (vivo, premium) */
  .btn-primary,.big-cta{position:relative;overflow:hidden}
  .btn-primary::after,.big-cta::after{content:"";position:absolute;top:0;left:-130%;width:55%;height:100%;
    background:linear-gradient(100deg,transparent,rgba(255,255,255,.45),transparent);transform:skewX(-18deg);animation:shine 5s ease-in-out infinite}
  @keyframes shine{0%,62%{left:-130%}82%,100%{left:170%}}
  /* Demo del teléfono auto-reproduciéndose */
  #demoWrap{transition:opacity .4s ease,transform .4s ease}
  .demo-pop{animation:popb .5s ease}
  @keyframes popb{0%{transform:scale(.92)}55%{transform:scale(1.05)}100%{transform:scale(1)}}
  @media(prefers-reduced-motion:reduce){.ag .o,.ag .o::after,.ag-in,.btn-primary::after,.big-cta::after{animation:none}.rayos{display:none}}
  /* Alma boricua: barra de marca + grano de film */
  body::before{content:"";position:fixed;top:0;left:0;right:0;height:4px;z-index:60;background:var(--grad)}
  body::after{content:"";position:fixed;inset:0;z-index:9998;pointer-events:none;opacity:.03;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='140' height='140'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");background-size:180px}
  /* Confeti del demo */
  .conf{position:absolute;width:8px;height:11px;border-radius:2px;pointer-events:none;will-change:transform,opacity}

  /* Reveal */
  .rv{opacity:0;transform:translateY(22px);transition:opacity .6s cubic-bezier(.2,.7,.2,1),transform .6s cubic-bezier(.2,.7,.2,1)}
  .rv.in{opacity:1;transform:none}

  @media(max-width:960px){
    .hero .in{grid-template-columns:1fr;gap:30px}.phone{order:-1}
    .agents{grid-template-columns:repeat(3,1fr)}
    .steps{grid-template-columns:1fr 1fr}
    .bento{grid-template-columns:1fr}.cell.big,.cell.sm{grid-column:auto}
    .plans{grid-template-columns:1fr;max-width:440px;margin:0 auto}.plan.pop{transform:none}
  }
  @media(max-width:520px){.agents{grid-template-columns:repeat(2,1fr)}.steps{grid-template-columns:1fr}.ev{padding:30px 20px}}
  @media(prefers-reduced-motion:reduce){.rv{opacity:1;transform:none}.float{animation:none}}
</style>
</head>
<body>

<nav class="nav">
  <div class="in">
    <a href="/crecer/index.php" style="display:flex;align-items:center;gap:9px;text-decoration:none;color:inherit">
      <img class="mark" src="/crecer/assets/brand/encuentralo-pin.svg" alt=""><span class="bn">encuéntralo</span></a>
    <span class="sp"></span>
    <a class="enter" href="/crecer/login.php">Entrar</a>
    <a class="cta" href="/crecer/registro.php">Empezar gratis</a>
  </div>
</nav>

<!-- HERO -->
<header class="hero">
  <div class="in wrap">
    <div>
      <span class="eyebrow">🇵🇷 Pa'l boricua que lo da todo en su negocio</span>
      <h1 class="disp">Tú haces lo tuyo. <span class="g">El corillo hace el resto.</span></h1>
      <p class="sub">Trabajas como un mulo y al final del día no te quedan fuerzas pa' pensar qué postear. Tranqui — desde hoy tienes un corillo que te trabaja las redes completas. Tú solo le das el OK desde el celular.</p>
      <div class="actions">
        <a class="btn-primary" href="/crecer/registro.php">Empezar gratis →</a>
        <a class="btn-ghost" href="#planes">Ver los planes</a>
      </div>
      <div class="proof">
        <span class="dot"></span>
        <span>El corillo ya ejecutó <b class="count" data-to="<?= $acciones ?>">0</b> acciones de IA para <b class="count" data-to="<?= $negocios ?>">0</b> negocios.</span>
      </div>
    </div>

    <!-- Phone mockup -->
    <div class="phone">
      <div class="scr">
        <div class="bar"><span class="av"></span><b>Dulce Coquí</b><span class="live">● en vivo</span></div>
        <div class="pcard" id="demoWrap">
          <div class="pimg"><span class="emoji" id="demoEmoji">🍰</span></div>
          <div class="pcap" id="demoCap"><b>¡Llegó el weekend, mi gente!</b> 🎉 Endúlzate con un bizcocho de guayaba hecho con amor. Ordena por WhatsApp 👉</div>
          <div class="pact"><span class="ok" id="demoBtn">✓ Aprobar</span><span class="sh">📲</span></div>
        </div>
      </div>
      <div class="float f1"><span class="o">✍️</span> La Creativa escribió esto</div>
      <div class="float f2"><span class="o">🎨</span> El Diseñador montó el arte</div>
    </div>
  </div>

  <!-- Tira el corillo -->
  <div class="band wrap rv">
    <div class="lab">No es una app — es tu corillo 🤝</div>
    <div class="agents">
      <div class="ag" style="--c:#7928ff"><div class="ag-in"><div class="o"><?= ico('list') ?></div><h4>El Estratega</h4><p>Planifica el mes</p><div class="q">"Estoy cuadrando el plan pa' que no publiques a lo loco."</div></div></div>
      <div class="ag" style="--c:#ff2d6f"><div class="ag-in"><div class="o"><?= ico('pen') ?></div><h4>La Creativa</h4><p>Escribe los posts</p><div class="q">"Déjame cocinar algo brutal pa' tu marca."</div></div></div>
      <div class="ag" style="--c:#ff7900"><div class="ag-in"><div class="o"><?= ico('palette') ?></div><h4>El Diseñador</h4><p>Crea las gráficas</p><div class="q">"Quiero que esto se vea premium, con cariño en cada detalle."</div></div></div>
      <div class="ag" style="--c:#00a5a8"><div class="ag-in"><div class="o"><?= ico('calendar') ?></div><h4>La Agenda</h4><p>Órdenes y citas</p><div class="q">"Tengo el calendario tranquilo y sin revoluces."</div></div></div>
      <div class="ag" style="--c:#33b617"><div class="ag-in"><div class="o"><?= ico('users') ?></div><h4>El Vendedor</h4><p>Cuida tu clientela</p><div class="q">"Estoy buscando dónde hay chavos en la mesa."</div></div></div>
      <div class="ag" style="--c:#1e69ff"><div class="ag-in"><div class="o"><?= ico('chart') ?></div><h4>El Analista</h4><p>Mide y aconseja</p><div class="q">"Vi tus números y hay una oportunidad aquí."</div></div></div>
    </div>
  </div>
</header>

<!-- EMPATÍA -->
<section class="wrap rv">
  <div class="empat">
    <div class="k">Te entendemos, de verdad</div>
    <h2>Sabemos lo que es darlo todo<br>y que el día no alcance.</h2>
    <div class="pains">
      <span class="pain">😮‍💨 "Sé que tengo que estar en redes… pero ¿cuándo?"</span>
      <span class="pain">😕 "Lo que subo se ve aficionado."</span>
      <span class="pain">😩 "Una agencia me sale por un ojo de la cara."</span>
    </div>
    <div class="turn">Se acabó. Ahora <span>metemos mano contigo.</span></div>
  </div>
</section>

<!-- CÓMO FUNCIONA -->
<section class="wrap rv">
  <div class="sec-head"><h2 class="disp">La IA hace el trabajo. <span class="g brush">Tú apruebas.</span></h2></div>
  <div class="steps">
    <div class="step"><div class="n">1</div><h4>Aprende tu negocio</h4><p>Le hablas 40 segundos: tu voz, tus productos, tu público.</p></div>
    <div class="step"><div class="n">2</div><h4>Planifica y crea</h4><p>Arma el calendario y escribe los posts con tus fotos reales.</p></div>
    <div class="step"><div class="n">3</div><h4>Tú apruebas</h4><p>Desde el celular, en segundos. La IA propone, tú decides.</p></div>
    <div class="step"><div class="n">4</div><h4>Publica y crece</h4><p>Lo sueltas a tus redes en un toque y cuida tu clientela.</p></div>
  </div>
</section>

<!-- BENTO FEATURES -->
<section class="wrap rv">
  <div class="sec-head"><h2 class="disp">Todo un equipo de marketing, <span class="g">por menos que un almuerzo al día.</span></h2></div>
  <div class="bento">
    <div class="cell hero-cell big">
      <div class="ic">🌙</div>
      <h3>El corillo trabaja solo</h3>
      <p>Con el piloto automático, la IA te prepara los posts de la semana sin que se lo pidas. Te despiertas con el trabajo hecho, listo para aprobar.</p>
      <span class="tagm">Operado por agentes de IA, con evidencia real</span>
    </div>
    <div class="cell sm"><div class="ic">✍️</div><h3>Voz boricua</h3><p>Captions auténticos, nunca traducidos ni genéricos. La IA aprende cómo hablas tú.</p></div>
    <div class="cell sm"><div class="ic">🎨</div><h3>Arte premium</h3><p>Tus fotos reales convertidas en gráficas de agencia. El producto siempre real.</p></div>
    <div class="cell sm"><div class="ic">🤝</div><h3>Clientela</h3><p>Tus clientes se arman solos de tus órdenes y el corillo los reactiva.</p></div>
    <div class="cell sm"><div class="ic">📲</div><h3>Publica fácil</h3><p>Pasa el post completo a Facebook e Instagram en un solo toque.</p></div>
  </div>
</section>

<!-- NIVELES -->
<section class="wrap rv" id="planes">
  <div class="sec-head"><h2 class="disp">Empieza gratis, <span class="g">crece a tu ritmo.</span></h2>
    <p>Prueba la IA con un post de muestra — sin tarjeta. Si te gusta, activa un plan y suéltalo todo.</p></div>
  <div class="plans">
    <div class="plan">
      <div class="lvl"><?= ico('gift') ?> Gratis</div>
      <div class="name">Prueba</div>
      <div class="promise">Mira la IA en acción con tu propio negocio, sin pagar nada.</div>
      <div class="price">$0 <small>sin tarjeta</small></div>
      <ul>
        <li>1 post de muestra (imagen + caption en tu voz)</li>
        <li>Onboarding por voz o texto</li>
        <li>El logo y más posts se desbloquean con un plan</li>
      </ul>
      <a class="cta" href="/crecer/registro.php">Empezar gratis</a>
    </div>
    <div class="plan pop">
      <span class="pop-tag">★ El más popular</span>
      <div class="lvl"><?= ico('leaf') ?> Mensual</div>
      <div class="name">Crecer</div>
      <div class="promise">El corillo te corre el marketing. Tú apruebas.</div>
      <div class="price">$49<small>/mes</small></div>
      <ul>
        <li>Marca y logo con IA</li>
        <li>Fábrica de posts (captions boricuas + arte)</li>
        <li>Calendario + aprobación desde el celular</li>
        <li>Órdenes y agenda + página pública con QR</li>
        <li>~10 imágenes IA por semana</li>
      </ul>
      <a class="cta" href="/crecer/registro.php?plan=crecer">Activar Crecer</a>
    </div>
    <div class="plan">
      <div class="lvl"><?= ico('rocket') ?> Mensual</div>
      <div class="name">Despegar</div>
      <div class="promise">El corillo además te ayuda a vender y crecer.</div>
      <div class="price">$89<small>/mes</small></div>
      <ul>
        <li>Todo lo de Crecer</li>
        <li>Piloto automático (posts solos cada semana)</li>
        <li>Clientela con retención por IA</li>
        <li>Analítica de impacto</li>
        <li>Publicación a IG/FB</li>
      </ul>
      <a class="cta" href="/crecer/registro.php?plan=despegar">Activar Despegar</a>
    </div>
  </div>
  <p class="note">Precios accesibles a propósito — hechos para el microempresario boricua 🇵🇷</p>
</section>

<!-- EVIDENCIA / PRUEBA -->
<section class="wrap rv">
  <div class="ev">
    <h2>Esto no es promesa. La IA opera de verdad.</h2>
    <p>Cada decisión del corillo queda registrada — planificó, escribió, diseñó, contestó. Transparencia total, datos reales.</p>
    <div class="stats">
      <div class="stat"><b class="g count" data-to="<?= $acciones ?>">0</b><span>ACCIONES DE IA EJECUTADAS</span></div>
      <div class="stat"><b class="g count" data-to="<?= $negocios ?>">0</b><span>NEGOCIOS OPERADOS</span></div>
      <div class="stat"><b class="g">24/7</b><span>EL CORILLO NO PARA</span></div>
    </div>
  </div>
</section>

<!-- FLYWHEEL -->
<section class="wrap rv">
  <div class="sec-head"><h2 class="disp">La rueda que te hace crecer</h2><p>Cada orden que completas te trae la próxima.</p></div>
  <div class="ring">
    <span class="node">📥 Entra una orden</span><span class="arr">→</span>
    <span class="node">✅ La completas</span><span class="arr">→</span>
    <span class="node">⭐ El cliente te reseña</span><span class="arr">→</span>
    <span class="node">📈 Subes en el directorio</span><span class="arr">→</span>
    <span class="node">🔁 Más clientes</span>
  </div>
</section>

<!-- MANIFIESTO -->
<section class="wrap manif rv">
  <h2 class="disp">Tú no naciste pa' pelear<br>con un <span class="g brush">teléfono.</span></h2>
  <p>Naciste pa' lo que amas — tu sazón, tu arte, tu gente. Lo demás, déjaselo al corillo. Poco a poco, pero siempre pa'lante. 🇵🇷</p>
</section>

<!-- CTA FINAL -->
<section class="wrap final rv">
  <h2 class="disp">Tu negocio merece <span class="g">un corillo que lo trabaje.</span></h2>
  <a class="big-cta" href="/crecer/registro.php">Crear mi negocio gratis →</a>
  <p style="color:var(--muted);font-size:14px;margin-top:14px">Gratis · sin tarjeta · en 2 minutos lo tienes corriendo</p>
</section>

<p class="foot">© Encuéntralo · Crecer — hecho con 🤎 y un poco de 🐸 en <b>Puerto Rico 🇵🇷</b></p>

<script>
  // Reveal on scroll (suave, premium)
  var io = new IntersectionObserver(function(es){
    es.forEach(function(e){ if(e.isIntersecting){ e.target.classList.add('in'); io.unobserve(e.target); } });
  }, {threshold:.12});
  document.querySelectorAll('.rv').forEach(function(el){ io.observe(el); });

  // Contadores que suben al entrar en pantalla
  function countUp(el){
    var to=+el.dataset.to||0, t0=null, dur=1200;
    function step(ts){ if(!t0)t0=ts; var p=Math.min(1,(ts-t0)/dur);
      var v=Math.floor((1-Math.pow(1-p,3))*to);
      el.textContent=v.toLocaleString('en-US'); if(p<1) requestAnimationFrame(step); else el.textContent=to.toLocaleString('en-US'); }
    requestAnimationFrame(step);
  }
  var io2=new IntersectionObserver(function(es){es.forEach(function(e){if(e.isIntersecting){countUp(e.target);io2.unobserve(e.target);}});},{threshold:.6});
  document.querySelectorAll('.count').forEach(function(el){io2.observe(el);});

  // Confeti ligero (DOM)
  function confeti(host){
    var cols=['#ff5c39','#c0395f','#16b86a','#ffb27a','#1e69ff'];
    for(var i=0;i<20;i++){
      var d=document.createElement('span'); d.className='conf'; d.style.background=cols[i%cols.length];
      d.style.left=(42+Math.random()*16)+'%'; d.style.top='42%'; host.appendChild(d);
      var ang=Math.random()*Math.PI*2, dist=55+Math.random()*85;
      var dx=Math.cos(ang)*dist, dy=Math.sin(ang)*dist;
      d.animate([{transform:'translate(0,0) rotate(0)',opacity:1},
                 {transform:'translate('+dx+'px,'+(dy+130)+'px) rotate('+(Math.random()*540)+'deg)',opacity:0}],
                {duration:950+Math.random()*450,easing:'cubic-bezier(.2,.7,.2,1)'}).onfinish=function(){this.effect.target.remove();};
    }
  }

  // Demo del teléfono: el corillo creando → tú aprobando → publicado, en loop
  (function(){
    var wrap=document.getElementById('demoWrap'); if(!wrap) return;
    var em=document.getElementById('demoEmoji'), cap=document.getElementById('demoCap'),
        btn=document.getElementById('demoBtn'), phone=wrap.closest('.phone');
    var posts=[
      {e:'🍰',c:'<b>¡Llegó el weekend, mi gente!</b> 🎉 Endúlzate con un bizcocho de guayaba hecho con amor. Ordena por WhatsApp 👉'},
      {e:'☕',c:'<b>Tu cafecito recién colao</b> ☕ Como le gusta a la familia boricua. Pásate por El Posito 📍'},
      {e:'🎂',c:'<b>¿Cumpleaños este finde?</b> 🥳 Te montamos el bizcocho que se roba la fiesta. Escríbenos 💬'}
    ];
    var i=0;
    function cycle(){
      setTimeout(function(){ btn.textContent='✓ Publicado 🎉'; btn.classList.add('demo-pop'); if(phone) confeti(phone); }, 2300);
      setTimeout(function(){
        wrap.style.opacity='0'; wrap.style.transform='translateY(10px)';
        setTimeout(function(){
          i=(i+1)%posts.length; em.textContent=posts[i].e; cap.innerHTML=posts[i].c;
          btn.textContent='✓ Aprobar'; btn.classList.remove('demo-pop');
          wrap.style.opacity='1'; wrap.style.transform='none';
        },430);
      }, 3500);
    }
    cycle(); setInterval(cycle, 4600);
  })();

  // Agentes ELÉCTRICOS: tilt 3D que sigue el cursor + fireworks al entrar
  (function(){
    var cards=document.querySelectorAll('.agents .ag'); if(!cards.length) return;
    cards.forEach(function(card){
      card.addEventListener('pointermove',function(e){
        var r=card.getBoundingClientRect();
        var px=(e.clientX-r.left)/r.width-.5, py=(e.clientY-r.top)/r.height-.5;
        card.style.transform='translateY(-8px) rotateX('+(-py*16).toFixed(1)+'deg) rotateY('+(px*18).toFixed(1)+'deg) scale(1.06)';
        card.style.setProperty('--gx',((px+.5)*100).toFixed(1)+'%');
        card.style.setProperty('--gy',((py+.5)*100).toFixed(1)+'%');
      });
      card.addEventListener('pointerleave',function(){ card.style.transform=''; });
      card.addEventListener('pointerenter',function(){ fuegos(card); });
    });
    function fuegos(card){
      var grid=card.parentNode, r=card.getBoundingClientRect(), g=grid.getBoundingClientRect();
      var cx=r.left-g.left+r.width/2, cy=r.top-g.top+r.height*0.30;
      var col=(getComputedStyle(card).getPropertyValue('--c')||'#ff5c39').trim();
      for(var i=0;i<14;i++){
        var s=document.createElement('span'); s.className='chispa';
        s.style.left=(cx-3.5)+'px'; s.style.top=(cy-3.5)+'px';
        s.style.background=col; s.style.boxShadow='0 0 9px '+col;
        grid.appendChild(s);
        var a=Math.PI*2*i/14+Math.random()*.35, d=36+Math.random()*54;
        s.animate([{transform:'translate(0,0) scale(1.35)',opacity:1},
                   {transform:'translate('+(Math.cos(a)*d).toFixed(0)+'px,'+(Math.sin(a)*d).toFixed(0)+'px) scale(0)',opacity:0}],
                  {duration:520+Math.random()*240,easing:'cubic-bezier(.12,.7,.25,1)'}).onfinish=function(){this.effect.target.remove();};
      }
    }
  })();

  // RAYOS entre los agentes: relámpagos que saltan de orbe a orbe
  (function(){
    var grid=document.querySelector('.agents'); if(!grid) return;
    if(window.matchMedia&&window.matchMedia('(prefers-reduced-motion:reduce)').matches) return;
    var NS='http://www.w3.org/2000/svg';
    var svg=document.createElementNS(NS,'svg'); svg.setAttribute('class','rayos'); grid.appendChild(svg);
    var bolts=[];
    function jag(a,b){
      var seg=7, pts=[[a[0],a[1]]], nx=-(b[1]-a[1]), ny=(b[0]-a[0]), len=Math.hypot(nx,ny)||1;
      for(var k=1;k<seg;k++){ var t=k/seg, off=(Math.random()-.5)*18;
        pts.push([a[0]+(b[0]-a[0])*t+nx/len*off, a[1]+(b[1]-a[1])*t+ny/len*off]); }
      pts.push([b[0],b[1]]);
      return 'M'+pts.map(function(p){return p[0].toFixed(0)+' '+p[1].toFixed(0);}).join(' L');
    }
    function build(){
      var gr=grid.getBoundingClientRect();
      svg.setAttribute('viewBox','0 0 '+gr.width+' '+gr.height);
      svg.setAttribute('width',gr.width); svg.setAttribute('height',gr.height); svg.innerHTML='';
      var orbs=[].map.call(grid.querySelectorAll('.ag .o'),function(o){
        var r=o.getBoundingClientRect(); return [r.left-gr.left+r.width/2, r.top-gr.top+r.height/2]; });
      bolts=[];
      for(var i=0;i<orbs.length-1;i++){ var p=document.createElementNS(NS,'path'); p.setAttribute('class','bolt');
        svg.appendChild(p); bolts.push({el:p,a:orbs[i],b:orbs[i+1]}); }
    }
    function zap(){ if(!bolts.length) return; var b=bolts[Math.floor(Math.random()*bolts.length)];
      b.el.setAttribute('d',jag(b.a,b.b)); b.el.style.animation='none'; void b.el.offsetWidth; b.el.style.animation='zap .5s ease-out'; }
    build(); var rt; window.addEventListener('resize',function(){clearTimeout(rt);rt=setTimeout(build,200);});
    setInterval(zap, 850);
  })();
</script>
</body>
</html>
