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

// La landing es la única página del producto que NO toca la base de datos (es
// estática a propósito: cero Gemini, cero consultas). Por eso engancha el
// idioma a mano — en el resto lo hace includes/db.php.
require_once __DIR__ . '/includes/i18n.php';
i18n_arrancar();

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

// ── Feed de DEMOSTRACIÓN: posts ficticios de negocios variados (corre solo). ──
// Muestran QUÉ tipo de posts monta el Corillo, para CUALQUIER negocio. Ficticios.
// El arte es un gráfico de marca (gradiente + ícono + oferta), no foto de terceros (IP).
$feed = [
  ['n'=>'Barbería El Corte','h'=>'barberia_elcorte','img'=>'barberia.png','c1'=>'#1f2937','c2'=>'#0ea5e9',
   'ic'=>'<circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M20 4 8.1 15.9M14.5 14.5 20 20M8.1 8.1 12 12"/>',
   'kick'=>'Fade impecable','offer'=>'Lun–Sáb · sin cita','cap'=>'El finde se llena rápido — asegura tu turno. 💈✂️'],
  ['n'=>'DJ Louie','h'=>'dj_louie','img'=>'dj.png','c1'=>'#6d28d9','c2'=>'#ec4899',
   'ic'=>'<path d="M4 14v-2a8 8 0 0 1 16 0v2"/><rect x="2" y="14" width="4" height="6" rx="1.5"/><rect x="18" y="14" width="4" height="6" rx="1.5"/>',
   'kick'=>'Tu fiesta, perfecta','offer'=>'Bodas · Cumpleaños','cap'=>'Fechas de diciembre volando 🔥 Aparta la tuya.'],
  ['n'=>'Patitas Felices','h'=>'patitas_felices','img'=>'grooming.png','c1'=>'#0f766e','c2'=>'#22c55e',
   'ic'=>'<circle cx="7" cy="9" r="1.6"/><circle cx="12" cy="7" r="1.6"/><circle cx="17" cy="9" r="1.6"/><ellipse cx="12" cy="15" rx="4.5" ry="3.5"/>',
   'kick'=>'Tu perro, consentido','offer'=>'Baño + corte','cap'=>'Se va lindo y oliendo rico 🐾 Reserva hoy.'],
  ['n'=>'Electric Torres','h'=>'electric_torres','img'=>'electricista.png','c1'=>'#b45309','c2'=>'#f59e0b',
   'ic'=>'<path d="M13 2 3 14h7l-1 8 10-12h-7Z"/>',
   'kick'=>'Emergencias 24/7','offer'=>'Estimados gratis','cap'=>'¿Se te fue la luz en un breaker? Te resuelvo. ⚡'],
  ['n'=>'Lcda. Rivera','h'=>'lcda_rivera','img'=>'abogada.png','c1'=>'#1e3a5f','c2'=>'#a67c00',
   'ic'=>'<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
   'kick'=>'¿Te chocaron?','offer'=>'Consulta gratis','cap'=>'No firmes nada sin hablar conmigo. Te oriento. ⚖️'],
  ['n'=>'Dulce Encanto','h'=>'dulce_encanto','img'=>'reposteria.png','c1'=>'#be185d','c2'=>'#fb7185',
   'ic'=>'<path d="M6 11h12l-1.5 8h-9zM6 11a4 4 0 0 1 .6-8 3 3 0 0 1 5-1 3 3 0 0 1 5 1 4 4 0 0 1 .6 8"/>',
   'kick'=>'Bizcochos que enamoran','offer'=>'Por encargo','cap'=>'¿Cumpleaños esta semana? Te lo hago a la medida 🎂'],
  ['n'=>'La Guagua del Sabor','h'=>'guagua_sabor','img'=>'foodtruck.png','c1'=>'#b91c1c','c2'=>'#f97316',
   'ic'=>'<path d="M3 6h11v9H3zM14 9h4l3 3v3h-7z"/><circle cx="7" cy="18" r="1.6"/><circle cx="17" cy="18" r="1.6"/>',
   'kick'=>'Sabor en la calle','offer'=>'Vie–Dom','cap'=>'Hoy toca pinchos y tostones 🔥 Búscanos en la 65.'],
  ['n'=>'CPA Núñez','h'=>'cpa_nunez','img'=>'cpa.png','c1'=>'#1d4ed8','c2'=>'#38bdf8',
   'ic'=>'<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M8 7h8M8 11h2M12 11h2M8 15h2M12 15h2"/>',
   'kick'=>'Planillas sin estrés','offer'=>'Individuos y negocios','cap'=>'Deadline cerca 📊 Deja que yo me encargue de los números.'],
];
?>
<!doctype html>
<html lang="es">
<head>
<?php require_once __DIR__ . '/includes/meta_pixel.php'; meta_pixel_head(); ?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Crecer · El Corillo que trabaja por tu negocio</title>
<meta name="description" content="Escribe el nombre de tu negocio y conoce al Corillo: un equipo de marketing que trabaja por ti.">
<link rel="icon" type="image/png" href="/crecer/assets/brand/crecer-icon.png">
<link rel="apple-touch-icon" href="/crecer/assets/brand/crecer-icon.png">
<meta property="og:type" content="website">
<meta property="og:title" content="Crecer · El Corillo que trabaja por tu negocio">
<meta property="og:description" content="Un equipo de marketing con IA que trabaja por tu negocio boricua. Tú apruebas desde el celular.">
<meta property="og:image" content="https://encuentraloahora.com/crecer/assets/brand/crecer-logo.png">
<meta name="twitter:card" content="summary_large_image">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  /* Hereda el lenguaje visual del producto (encuentralo-ui.css), aquí autónomo. */
  :root{
    --crema:#FFFFFF; --card:#fff; --tinta:#231F20; --ink:#4A434F; --muted:#6E6A67; --line:#ECEAE7;
    --magenta:#EF4375; --coral:#FF6B3D; --teal:#00A49F; --palma:#16b86a;
    --disp:'Poppins',sans-serif; --body:'Plus Jakarta Sans',system-ui,sans-serif;
    --grad:linear-gradient(135deg,var(--coral),var(--magenta));
    --glow:0 1px 0 rgba(255,255,255,.28) inset,0 10px 22px -8px rgba(239,67,117,.5),0 22px 50px -18px rgba(239,67,117,.42);
    --glow-active:0 1px 0 rgba(255,255,255,.2) inset,0 5px 13px -6px rgba(239,67,117,.5);
    --ease:cubic-bezier(.22,1,.36,1);
  }
  *{box-sizing:border-box;margin:0;padding:0}
  html{scroll-behavior:smooth}
  body{font-family:var(--body);color:var(--tinta);background:#fff;line-height:1.5;
    -webkit-font-smoothing:antialiased;overflow-x:hidden}
  .wrap{width:min(600px,calc(100% - 40px));margin-inline:auto}
  a{color:inherit}
  :where(a,button,input):focus-visible{outline:none;box-shadow:0 0 0 3px color-mix(in srgb,var(--magenta) 32%,transparent)}
  ::selection{background:color-mix(in srgb,var(--magenta) 20%,#fff)}

  /* nav — Crecer discreto */
  .nav{display:flex;align-items:center;justify-content:space-between;padding:20px 22px;position:relative;z-index:5}
  .brand{font-family:var(--disp);font-weight:600;font-size:16px;letter-spacing:-.02em;color:var(--tinta);text-decoration:none}
  .brand i{color:var(--teal);font-style:normal}
  .nav .entrar{font-family:var(--disp);font-weight:600;font-size:14px;color:var(--tinta);text-decoration:none;display:inline-flex;align-items:center;gap:6px}
  .nav .entrar .ent-arrow{color:var(--magenta);font-weight:700;display:inline-block;transition:transform .2s var(--ease)}
  .nav .entrar:hover .ent-arrow{transform:translateX(3px)}

  /* chip de honestidad */
  .demo{display:inline-block;font-family:var(--disp);font-weight:600;font-size:10.5px;text-transform:uppercase;letter-spacing:.08em;
    color:var(--muted);background:color-mix(in srgb,var(--muted) 8%,#fff);border:1px solid var(--line);padding:4px 10px;border-radius:999px}

  /* ── 1 · HERO: "Tu equipo ya adelantó trabajo por ti" (rediseño 2026-07) ── */
  #ask{position:relative;overflow:hidden;min-height:calc(100dvh - 64px);display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:24px 22px 14vh;background:#fff}
  /* logo translúcido de marca de agua (fondo) */
  .ask-wm{position:absolute;z-index:0;top:-7%;right:-16%;width:min(600px,92vw);opacity:.06;pointer-events:none;user-select:none}
  /* Desktop: el watermark se salía casi todo → traerlo visible arriba-derecha (móvil se queda igual) */
  @media(min-width:701px){ .ask-wm{width:min(700px,46vw);top:-7%;right:-5%} }
  /* ola inferior */
  .ask-wave{position:absolute;z-index:1;left:0;bottom:0;width:100%;height:clamp(100px,16vh,200px);pointer-events:none}
  /* contenido por encima del fondo */
  .ask-inner{position:relative;z-index:2;display:flex;flex-direction:column;align-items:center;width:100%}
  .ask-icon{display:block;width:78px;height:auto;margin:0 auto 18px}
  .ask-q{font-family:var(--disp);font-weight:700;font-size:clamp(40px,6.4vw,72px);line-height:1.02;letter-spacing:-.045em;color:var(--tinta);text-wrap:balance;max-width:12ch}
  .ask-q .accent{display:block;color:var(--magenta)}
  .ask-underline{width:150px;height:7px;margin:21px 0 20px;border-radius:999px;
    background:linear-gradient(90deg,var(--teal) 0 45%,#f3a3aa 45% 58%,var(--magenta) 58%);transform:rotate(-1deg)}
  .ask-intro{margin:0 0 28px;color:var(--muted);font-size:clamp(16px,1.3vw,20px);line-height:1.55;text-wrap:balance;max-width:34ch}
  .namebox{display:flex;align-items:center;gap:10px;width:min(500px,calc(100vw - 44px));
    background:var(--card);border:1.5px solid var(--magenta);border-radius:18px;padding:8px 8px 8px 20px;
    box-shadow:0 2px 6px rgba(40,22,28,.05),0 24px 50px -26px rgba(239,67,117,.22);transition:box-shadow .2s var(--ease),border-color .2s var(--ease)}
  .namebox:focus-within{border-color:var(--magenta);box-shadow:0 0 0 3px color-mix(in srgb,var(--magenta) 16%,transparent),0 26px 56px -24px rgba(239,67,117,.3)}
  .namebox.shake{animation:shake .4s}
  @keyframes shake{10%,90%{transform:translateX(-2px)}30%,70%{transform:translateX(5px)}50%{transform:translateX(-7px)}}
  .namebox input{flex:1;border:0;outline:0;background:0;font-family:var(--body);font-size:17px;color:var(--tinta);min-width:0}
  .namebox input::placeholder{color:#b8b2ad}
  .namebox button{flex:none;width:56px;height:56px;border:0;border-radius:14px;background:var(--grad);color:#fff;
    box-shadow:var(--glow);font-size:26px;cursor:pointer;display:grid;place-items:center;line-height:1;transition:transform .2s var(--ease),box-shadow .2s var(--ease)}
  .namebox button:active{transform:translateY(1px);box-shadow:var(--glow-active)}
  .whisper{margin-top:20px;font-size:14px;color:#8A9099;display:inline-flex;align-items:center;gap:7px}
  .whisper svg{width:14px;height:14px;flex:none}
  @media(max-width:700px){
    #ask{padding-top:8vh}
    .ask-icon{width:66px}
    .ask-q{font-size:clamp(30px,8.6vw,46px);line-height:1.06;max-width:92vw}
    .ask-intro{font-size:17px;max-width:24ch}
  }

  /* Estados: por defecto se ve el hero; al fichar (o con ?negocio) se ve #exp */
  #exp{display:none}
  body.revealed #ask{display:none}
  body.revealed #exp{display:block}

  /* ══ POST-NOMBRE reinventado — "El Corillo ya empezó" (1 pantalla, desktop-native) ══ */
  .show{position:relative;overflow:hidden;min-height:calc(100dvh - 66px);display:flex;flex-direction:column;justify-content:center;gap:clamp(28px,4vh,44px);padding:36px 22px}
  .show::before{content:"";position:absolute;z-index:0;top:-24%;right:-12%;width:64vw;max-width:820px;height:64vw;max-height:820px;border-radius:50%;
    background:radial-gradient(circle at 32% 30%, color-mix(in srgb,var(--magenta) 16%,#fff), transparent 62%);pointer-events:none}
  .show::after{content:"";position:absolute;z-index:0;bottom:-30%;left:-14%;width:52vw;max-width:640px;height:52vw;max-height:640px;border-radius:50%;
    background:radial-gradient(circle at 60% 40%, color-mix(in srgb,var(--teal) 13%,#fff), transparent 64%);pointer-events:none}
  .show-in{position:relative;z-index:2;width:min(1080px,100%);margin-inline:auto;display:grid;grid-template-columns:1.05fr .95fr;gap:clamp(28px,5vw,72px);align-items:center}

  /* — izquierda: pitch — */
  .pitch{max-width:530px}
  .eyebrow{display:inline-flex;align-items:center;gap:9px;font-family:var(--disp);font-weight:600;font-size:13px;letter-spacing:.01em;color:#00827e;
    background:color-mix(in srgb,var(--teal) 9%,#fff);border:1px solid color-mix(in srgb,var(--teal) 24%,#fff);padding:6px 14px;border-radius:999px}
  .eyebrow .pulse{width:8px;height:8px;border-radius:50%;background:var(--teal);animation:pulse 2s infinite}
  @keyframes pulse{0%{box-shadow:0 0 0 0 color-mix(in srgb,var(--teal) 55%,transparent)}70%{box-shadow:0 0 0 9px transparent}100%{box-shadow:0 0 0 0 transparent}}
  .pitch h2{margin:18px 0 0;font-family:var(--disp);font-weight:700;font-size:clamp(32px,3.9vw,52px);line-height:1.03;letter-spacing:-.032em;color:var(--tinta);text-wrap:balance}
  .pitch h2 .hl{color:var(--magenta)}
  .pitch .lead{margin:15px 0 0;font-size:clamp(16px,1.15vw,18px);line-height:1.55;color:var(--muted);max-width:42ch}
  .pills{display:flex;flex-wrap:wrap;gap:9px;margin:24px 0 0}
  .pill{display:inline-flex;align-items:center;gap:8px;font-family:var(--disp);font-weight:600;font-size:13.5px;color:var(--ink);
    background:#fff;border:1px solid var(--line);border-radius:999px;padding:9px 15px;box-shadow:0 8px 18px -14px rgba(40,22,28,.4);
    transition:transform .2s var(--ease),box-shadow .2s var(--ease)}
  .pill:hover{transform:translateY(-2px);box-shadow:0 14px 24px -14px rgba(239,67,117,.4)}
  .pill .d{width:8px;height:8px;border-radius:50%;flex:none}
  .pill.a .d{background:var(--teal)} .pill.b .d{background:var(--coral)} .pill.c .d{background:var(--magenta)}
  .cta-row{margin:32px 0 0;display:flex;flex-direction:column;align-items:flex-start;gap:12px}
  .enter{display:inline-flex;align-items:center;gap:10px;border:0;cursor:pointer;text-decoration:none;font-family:var(--disp);font-weight:600;font-size:17px;color:#fff;
    padding:17px 34px;border-radius:16px;background:var(--grad);box-shadow:var(--glow);transition:transform .2s var(--ease),box-shadow .2s var(--ease)}
  .enter:hover{transform:translateY(-2px)} .enter:active{transform:translateY(1px);box-shadow:var(--glow-active)}
  .enter .arw{display:inline-block;transition:transform .2s var(--ease)} .enter:hover .arw{transform:translateX(4px)}
  .micro{font-size:13.5px;color:var(--muted)} .micro b{color:var(--ink);font-weight:600}

  /* — derecha: el CELULAR (SHOW, no tell) — */
  .stageph{display:flex;justify-content:center;position:relative}
  .phone{position:relative;width:min(300px,80vw);background:#fff;border-radius:40px;padding:11px;border:1px solid var(--line);
    box-shadow:0 1px 0 rgba(255,255,255,.7) inset,0 44px 84px -32px rgba(40,22,28,.5),0 20px 44px -24px rgba(239,67,117,.32)}
  @keyframes floaty{0%,100%{transform:translateY(0)}50%{transform:translateY(-10px)}}
  .phone .scr{position:relative;border-radius:30px;overflow:hidden;background:#fff;border:1px solid var(--line)}
  /* feed que corre solo (loop sin fin; pausa al hover) */
  .feed{height:clamp(430px,58vh,560px);overflow:hidden;position:relative;
    -webkit-mask-image:linear-gradient(#0000,#000 6%,#000 94%,#0000);mask-image:linear-gradient(#0000,#000 6%,#000 94%,#0000)}
  .feed-track{display:flex;flex-direction:column;will-change:transform}   /* el avance lo maneja el JS (paso a paso con ease) */
  .fpost{border-bottom:9px solid #f2efec}
  .fbar{display:flex;align-items:center;gap:9px;padding:10px 12px}
  .fav{width:31px;height:31px;border-radius:50%;color:#fff;font-family:var(--disp);font-weight:700;font-size:14px;display:grid;place-items:center;flex:none}
  .fmeta{display:flex;flex-direction:column;line-height:1.15;flex:1;min-width:0}
  .fmeta b{font-family:var(--disp);font-weight:600;font-size:13.5px;color:var(--tinta);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .fmeta small{font-size:11px;color:var(--muted)}
  .fmore{color:var(--muted);font-weight:700;letter-spacing:1.5px;font-size:14px}
  .fimg{position:relative;aspect-ratio:1;display:flex;flex-direction:column;justify-content:flex-end;padding:16px;overflow:hidden;
    background:radial-gradient(130% 120% at 14% 12%, color-mix(in srgb,var(--c2) 76%,#fff) 0, transparent 52%),linear-gradient(150deg,var(--c1),var(--c2))}
  .fimg.hasimg{background-size:cover;background-position:center;background-repeat:no-repeat}
  .fic{position:absolute;top:-6px;right:-6px;color:rgba(255,255,255,.20)}
  .fic svg{width:120px;height:120px}
  .fk{position:relative;font-family:var(--disp);font-weight:700;font-size:clamp(19px,4.4vw,24px);line-height:1.1;letter-spacing:-.02em;color:#fff;text-shadow:0 2px 14px rgba(0,0,0,.24);max-width:90%}
  .foffer{position:relative;align-self:flex-start;margin-top:10px;font-family:var(--disp);font-weight:600;font-size:11.5px;color:#fff;background:rgba(0,0,0,.24);backdrop-filter:blur(3px);padding:5px 11px;border-radius:999px}
  .facts{display:flex;align-items:center;gap:14px;padding:10px 12px 4px}
  .facts svg{width:22px;height:22px;color:var(--tinta)}
  .facts .sp{flex:1}
  .fcap{padding:2px 12px 13px;font-size:12.5px;line-height:1.45;color:var(--ink)}
  .fcap b{font-weight:700;color:var(--tinta)}
  .demo-note{margin-top:14px;text-align:center;font-size:12px;color:var(--muted)}
  @media (prefers-reduced-motion:reduce){ .feed{overflow-y:auto} .feed-track{animation:none;transform:none} }
  .pflag{position:absolute;right:-14px;bottom:30px;background:#fff;border:1px solid var(--line);border-radius:15px;padding:9px 14px;
    box-shadow:0 18px 36px -18px rgba(40,22,28,.45);display:flex;align-items:center;gap:10px;font-family:var(--disp);font-weight:600;font-size:12.5px;color:var(--tinta);
    animation:floaty 6.5s ease-in-out infinite .5s}
  .pflag .pf-ic{width:27px;height:27px;border-radius:9px;background:color-mix(in srgb,var(--teal) 13%,#fff);display:grid;place-items:center;flex:none}
  .pflag .pf-ic svg{width:16px;height:16px;color:#00827e}

  /* entrada escalonada al revelar */
  body.revealed.animate .pitch>*{opacity:0;animation:riseIn .5s var(--ease) both}
  body.revealed.animate .pitch>*:nth-child(2){animation-delay:.06s}
  body.revealed.animate .pitch>*:nth-child(3){animation-delay:.12s}
  body.revealed.animate .pitch>*:nth-child(4){animation-delay:.18s}
  body.revealed.animate .cta-row{opacity:0;animation:riseIn .5s var(--ease) .24s both}
  body.revealed.animate .stageph{opacity:0;animation:riseIn .6s var(--ease) .12s both}

  /* ribbon de capacidades */
  .caps{position:relative;z-index:2;width:min(1000px,100%);margin-inline:auto;display:flex;flex-wrap:wrap;align-items:center;justify-content:center;
    gap:10px 20px;border-top:1px solid var(--line);padding-top:22px}
  .caps-l{font-family:var(--disp);font-weight:600;font-size:13px;color:var(--muted)}
  .cap{display:inline-flex;align-items:center;gap:7px;font-family:var(--disp);font-weight:600;font-size:13px;color:var(--ink)}
  .cap svg{width:16px;height:16px;color:var(--magenta);flex:none}
  body.revealed.animate .caps{opacity:0;animation:riseIn .5s var(--ease) .32s both}

  /* footer slim */
  .foot{position:relative;z-index:2;padding:22px;text-align:center;color:var(--muted);font-size:13px}
  .foot a{text-decoration:none}.foot a:hover{color:var(--tinta)}

  /* MÓVIL: una columna, corta; el celular arriba (el gancho antes del scroll) */
  @media(max-width:820px){
    .show{min-height:0;padding:26px 22px 42px}
    /* una sola columna; con display:contents intercalo el celular ENTRE el titular y el resto */
    .show-in{display:flex;flex-direction:column;align-items:center;gap:18px;text-align:center}
    .pitch{display:contents}
    .eyebrow{order:1}
    .pitch h2{order:2;margin:0;max-width:16ch}
    .stageph{order:3}
    .pitch .lead{order:4;margin:0;max-width:32ch}
    .pills{order:5;justify-content:center;max-width:100%}
    .cta-row{order:6;align-items:center;width:100%}
    .enter{width:100%;max-width:360px;justify-content:center}
    .phone{width:min(280px,74vw)}
    .pflag{right:-6px}
  }

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
  <a class="brand" href="/crecer/crecer.php" style="display:inline-flex;align-items:center;gap:11px"><img src="/crecer/assets/brand/crecer-icon.png" alt="Crecer" style="height:46px;width:auto"><b style="display:inline-flex;flex-direction:column;line-height:1;gap:2px;font-weight:700"><span style="font-size:23px;color:var(--teal)">Crecer</span><span style="font-size:11px;font-weight:500;color:var(--muted);letter-spacing:.02em">by Encuéntralo</span></b></a>
  <a class="entrar" href="/crecer/login.php">Entrar <span class="ent-arrow" aria-hidden="true">&rarr;</span></a>
</nav>

<!-- ── 1 · HERO — solo la pregunta ── -->
<header id="ask">
  <img class="ask-wm" src="/crecer/assets/brand/crecer-icon.png" alt="" aria-hidden="true">
  <svg class="ask-wave" viewBox="0 0 1440 200" preserveAspectRatio="none" fill="none" aria-hidden="true">
    <path d="M0,118 C300,66 600,66 720,108 C900,168 1150,180 1440,116 L1440,200 L0,200 Z" fill="rgba(0,164,159,.05)"/>
    <path d="M0,96 C288,34 576,34 720,80 C864,126 1152,166 1440,96 L1440,200 L0,200 Z" fill="rgba(0,164,159,.085)"/>
  </svg>
  <div class="ask-inner">
    <svg class="ask-icon" viewBox="0 0 160 120" fill="none" aria-hidden="true"><g stroke="#20B6AE" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"><circle cx="80" cy="43" r="17"/><path d="M49 99c0-19 14-31 31-31s31 12 31 31"/><circle cx="35" cy="58" r="12"/><path d="M15 99c0-14 9-24 22-24 7 0 13 2 18 7"/><circle cx="125" cy="58" r="12"/><path d="M105 82c5-5 11-7 18-7 13 0 22 10 22 24"/></g><g stroke="#EF4375" stroke-width="5" stroke-linecap="round"><path d="M80 12V1"/><path d="M57 18l-8-8"/><path d="M103 18l8-8"/></g></svg>
    <h1 class="ask-q">Tu equipo<span class="accent">ya adelantó</span>trabajo por ti.</h1>
    <div class="ask-underline" aria-hidden="true"></div>
    <p class="ask-intro">Solo dinos cómo se llama tu negocio y empezamos.</p>
    <form class="namebox" id="fiche" method="get" action="/crecer/crecer.php" autocomplete="off">
      <input id="negInput" name="negocio" maxlength="60" required
             value="<?= $has_name ? $h($negocio) : '' ?>"
             placeholder="Escribe el nombre de tu negocio…" aria-label="Nombre de tu negocio"
             autocomplete="organization" enterkeyhint="go" autocapitalize="words" spellcheck="false">
      <button type="submit" aria-label="Comenzar">→</button>
    </form>
    <p class="whisper">
      <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 10V8a5 5 0 0 1 10 0v2h1.5A1.5 1.5 0 0 1 20 11.5v8A1.5 1.5 0 0 1 18.5 21h-13A1.5 1.5 0 0 1 4 19.5v-8A1.5 1.5 0 0 1 5.5 10H7Zm2 0h6V8a3 3 0 0 0-6 0v2Z"/></svg>
      Es rápido, gratis y sin tarjeta.
    </p>
  </div>
</header>

<!-- ── 2–5 · LA EXPERIENCIA (se revela al fichar) ── -->
<main id="exp">

  <!-- EL CORILLO YA EMPEZÓ — split: pitch + celular con post de muestra -->
  <section class="show">
    <div class="show-in">

      <div class="pitch">
        <span class="eyebrow"><span class="pulse"></span> El Corillo en acción</span>
        <h2>Esto es lo que el Corillo <span class="hl">crea cada día</span>.</h2>
        <p class="lead">Barberías, DJs, abogados, reposterías, electricistas… posts completos, en la voz de cada negocio. Lo mismo hace por <b class="jsname"><?= $h($biz) ?></b> — tú solo apruebas desde el celular.</p>
        <div class="pills">
          <span class="pill a"><span class="d"></span> Darte a conocer</span>
          <span class="pill b"><span class="d"></span> Mostrar lo que haces</span>
          <span class="pill c"><span class="d"></span> Que vuelvan</span>
        </div>
        <div class="cta-row">
          <a class="enter" id="enterCta" href="/crecer/registro.php?negocio=<?= urlencode($negocio) ?>">Crear mi primer post gratis <span class="arw" aria-hidden="true">&rarr;</span></a>
          <span class="micro"><b>Gratis</b> · sin tarjeta · listo en 1 minuto</span>
        </div>
      </div>

      <div class="stageph">
        <div class="phone">
          <div class="scr">
            <div class="feed"><div class="feed-track">
              <?php for ($rep = 0; $rep < 2; $rep++): foreach ($feed as $p): ?>
              <article class="fpost">
                <div class="fbar">
                  <span class="fav" style="background:linear-gradient(135deg,<?= $h($p['c1']) ?>,<?= $h($p['c2']) ?>)"><?= $h(mb_strtoupper(mb_substr($p['n'], 0, 1))) ?></span>
                  <span class="fmeta"><b><?= $h($p['n']) ?></b><small>@<?= $h($p['h']) ?></small></span>
                  <span class="fmore" aria-hidden="true">•••</span>
                </div>
                <?php $imgname = preg_replace('/\.png$/i', '.jpg', $p['img']); /* las PNG se comprimieron a JPG */
                      $imgf = __DIR__ . '/assets/landing/feed/' . $imgname; $imgu = '/crecer/assets/landing/feed/' . $imgname; $ph = is_file($imgf); ?>
                <div class="fimg<?= $ph ? ' hasimg' : '' ?>" style="--c1:<?= $h($p['c1']) ?>;--c2:<?= $h($p['c2']) ?><?= $ph ? ";background-image:url('" . $h($imgu) . "')" : '' ?>">
                  <?php if (!$ph): /* respaldo si aún no está la imagen: gradiente + ícono + texto */ ?>
                    <span class="fic" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><?= $p['ic'] ?></svg></span>
                    <div class="fk"><?= $h($p['kick']) ?></div>
                    <span class="foffer"><?= $h($p['offer']) ?></span>
                  <?php endif; ?>
                </div>
                <div class="facts" aria-hidden="true">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 1 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8Z"/></svg>
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 9 9 0 0 1-3.9-.9L3 20l1.3-4a8.4 8.4 0 0 1-.9-3.9 8.5 8.5 0 0 1 17.6-.6Z"/></svg>
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>
                  <span class="sp"></span>
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m19 21-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2Z"/></svg>
                </div>
                <div class="fcap"><b>@<?= $h($p['h']) ?></b> <?= $h($p['cap']) ?></div>
              </article>
              <?php endforeach; endfor; ?>
            </div></div>
          </div>
          <div class="pflag">
            <span class="pf-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg></span>
            Hecho por el Corillo
          </div>
          <!-- CR-F06 · Este feed son PIEZAS DE MUESTRA con negocios inventados. El
               rótulo se había quitado (commit 0df29bb) y sin él la página insinúa
               clientes reales con resultados reales. Va discreto, pero va. -->
          <p class="demo-note">Ejemplos creados con Crecer</p>
        </div>
      </div>

    </div>

    <!-- ribbon: todo lo que el Corillo ya hace -->
    <div class="caps">
      <span class="caps-l">El Corillo también</span>
      <span class="cap"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="3"/><path d="M8 4v16"/></svg> Carruseles</span>
      <span class="cap"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="3"/><path d="m10 9 5 3-5 3Z"/></svg> Reels con IA</span>
      <span class="cap"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg> Publica solo</span>
      <span class="cap"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2Z"/></svg> Responde DMs</span>
      <span class="cap"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M5 10a7 7 0 0 0 14 0M12 19v3"/></svg> Aprende tu voz</span>
    </div>
  </section>

  <footer class="foot">
    <a class="brand" href="/crecer/crecer.php" style="display:inline-flex;align-items:center;gap:7px;font-size:14px"><img src="/crecer/assets/brand/crecer-icon.png" alt="" style="height:24px;width:auto"><b style="display:inline-flex;flex-direction:column;line-height:1;gap:0;font-weight:700"><span style="color:var(--teal)">Crecer</span><span style="font-size:.5em;font-weight:500;color:var(--muted);letter-spacing:.02em;margin-top:1px">by Encuéntralo</span></b></a>
    · <a href="/crecer/terminos.php">Términos</a> · <a href="/crecer/privacidad.php">Privacidad</a>
    <?php /* La landing es la primera puerta: el interruptor tiene que estar aquí
             para quien llega sin saber español. */ ?>
    <span style="display:block;margin-top:12px"><?= i18n_toggle_html() ?></span>
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
    var ini = (seguro.trim().charAt(0) || 'T').toUpperCase();
    document.querySelectorAll('.jsinitial').forEach(function(el){ el.textContent = ini; });
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

// ── Feed que corre como película: un post a la vez, sube con ease + rocecito suave ──
(function(){
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var feed = document.querySelector('.feed');
  var track = feed && feed.querySelector('.feed-track');
  if (!feed || !track || reduce) return;
  var posts = track.querySelectorAll('.fpost');
  if (posts.length < 2) return;
  var n = posts.length / 2;            // el track está duplicado → set real = mitad
  var i = 0, timer = null;
  function h(){ return posts[0].getBoundingClientRect().height; }
  function go(){
    if (!feed.offsetParent) return;    // #exp oculto → no avanzar todavía
    var step = h(); if (!step) return;
    i++;
    track.style.transition = 'transform .8s cubic-bezier(.34,1.3,.36,1)';  // sube, roza el borde y se acomoda
    track.style.transform = 'translateY(' + (-step * i) + 'px)';
    if (i >= n){                        // llegó al final del set → vuelve al inicio sin que se note
      setTimeout(function(){ track.style.transition = 'none'; i = 0; track.style.transform = 'translateY(0)'; }, 840);
    }
  }
  function start(){ stop(); timer = setInterval(go, 3400); }   // ~2.6s mirando el post + .8s de swipe
  function stop(){ if (timer){ clearInterval(timer); timer = null; } }
  feed.addEventListener('mouseenter', stop);
  feed.addEventListener('mouseleave', start);
  start();
})();
</script>
</body>
</html>
