<?php
// ============================================================
//  CRECER — Pantalla "wow" post-onboarding
//  panel/bienvenida.php  ·  muestra el post de muestra + CTA a activar plan
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/suscripcion.php';
require __DIR__ . '/../includes/iconos.php';
requiere_login();
$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }
$marca_id = (int)$marca['id'];

// El post de muestra recién creado
$ps = $pdo->prepare("SELECT caption, grafica_path, plataforma FROM crecer_contenido WHERE marca_id=? ORDER BY id DESC LIMIT 1");
$ps->execute([$marca_id]); $post = $ps->fetch() ?: null;

// Plan que eligió en el landing (si vino por un plan pagado)
$pi = $_SESSION['plan_intent'] ?? null;
$planRow = null;
if (in_array($pi, ['crecer','despegar'], true)) {
    $q = $pdo->prepare("SELECT * FROM crecer_planes WHERE slug=? AND activo=1");
    $q->execute([$pi]); $planRow = $q->fetch() ?: null;
}
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>¡Tu primer post está listo! · Encuéntralo</title>
<link rel="icon" type="image/svg+xml" href="/crecer/assets/brand/crecer-mark.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="/crecer/assets/encuentralo-ui.css?v=18" rel="stylesheet">
<style>
  body{background:var(--crema);font-family:var(--font-body)}
  .bv{max-width:480px;margin:0 auto;padding:30px 20px 80px;text-align:center}
  .brand{display:inline-flex;align-items:center;gap:8px;margin-bottom:26px;font-family:var(--font-display);font-weight:600;font-size:15px;color:var(--ink-soft);text-decoration:none}
  .brand img{height:26px}.brand i{color:var(--teal);font-style:normal}
  .chip{display:inline-flex;align-items:center;gap:7px;font-family:var(--font-display);font-weight:600;font-size:12px;letter-spacing:.03em;text-transform:uppercase;
    color:var(--teal-dark,#00827e);background:color-mix(in srgb,var(--teal) 12%,#fff);padding:7px 14px;border-radius:999px;margin-bottom:16px}
  .chip svg{width:15px;height:15px}
  h1{font-family:var(--font-display);font-weight:600;font-size:clamp(28px,6.4vw,40px);line-height:1.1;letter-spacing:-.025em;color:var(--ink-soft);margin:0 0 10px;text-wrap:balance}
  h1 span{color:var(--magenta)}
  .lede{color:var(--muted);font-size:15.5px;line-height:1.5;margin:0 auto 26px;max-width:34ch}
  /* el post — tarjeta estilo IG, coordinada, protagonista */
  .post{background:var(--card);border:1px solid var(--line);border-radius:22px;overflow:hidden;text-align:left;max-width:400px;margin:0 auto;
    box-shadow:0 2px 6px rgba(40,22,28,.05),0 30px 64px -30px rgba(40,22,28,.3)}
  .post .ph{display:flex;align-items:center;gap:10px;padding:14px 15px}
  .post .av{width:36px;height:36px;border-radius:50%;background:var(--btn-grad);color:#fff;display:grid;place-items:center;font-family:var(--font-display);font-weight:600;font-size:15px}
  .post .hn{font-family:var(--font-display);font-weight:600;font-size:14.5px;color:var(--ink-soft)}
  .post .hn small{display:block;color:var(--muted);font-weight:400;font-size:11.5px;font-family:var(--font-body)}
  .post .img{width:100%;display:block;background:var(--crema-2)}
  .post .cap{padding:15px;font-size:14.5px;line-height:1.55;color:var(--tinta);white-space:pre-wrap}
  /* CTA signature */
  .cta{display:block;width:100%;max-width:400px;margin:24px auto 0;border:0;cursor:pointer;text-decoration:none;font-family:var(--font-display);font-weight:600;font-size:17px;color:#fff;
    background:var(--btn-grad);box-shadow:var(--btn-glow);padding:17px;border-radius:16px;transition:transform .2s var(--ease),box-shadow .2s var(--ease)}
  .cta:active{transform:translateY(1px);box-shadow:var(--btn-glow-active)}
  .cta .small{display:block;font-family:var(--font-body);font-weight:500;font-size:12.5px;opacity:.92;margin-top:3px}
  .feat{display:flex;flex-direction:column;gap:9px;max-width:340px;margin:22px auto 0;text-align:left}
  .feat div{display:flex;align-items:center;gap:10px;font-size:14px;color:var(--tinta)}
  .feat svg{width:17px;height:17px;color:var(--palma);flex:none}
  .later{display:inline-block;margin-top:20px;color:var(--muted);font-family:var(--font-display);font-weight:500;font-size:14px;text-decoration:none}
  .later:hover{color:var(--ink-soft)}
  /* entrada delicada — el contenido ya estaba ahí */
  @keyframes rise{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}
  .an{opacity:0;animation:rise .5s var(--ease) both}
  .a1{animation-delay:.06s}.a2{animation-delay:.15s}.a3{animation-delay:.28s}.a4{animation-delay:.46s}.a5{animation-delay:.56s}
  @media(prefers-reduced-motion:reduce){.an{animation:none;opacity:1}}
</style>
</head>
<body>
<?php $chk='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>'; ?>
<div class="bv">
  <a class="brand an" href="/crecer/panel/index.php?marca=<?= $marca_id ?>"><img src="/crecer/assets/brand/crecer-mark.svg" alt=""><span>encuéntralo <i>crecer</i></span></a>

  <div><span class="chip an a1"><?= $chk ?> El corillo ya lo hizo</span></div>
  <h1 class="an a2">Tu primer post está <span>listo</span>.</h1>
  <p class="lede an a2">Le hablaste 40 segundos y el corillo lo armó — en tu voz, con tu foto. Esto es solo un adelanto.</p>

  <!-- El post de muestra (protagonista) -->
  <div class="post an a3">
    <div class="ph">
      <div class="av"><?= $h(mb_strtoupper(mb_substr($marca['nombre_negocio'],0,1))) ?></div>
      <div class="hn"><?= $h($marca['nombre_negocio']) ?><small>tu post de muestra · gratis</small></div>
    </div>
    <?php if (!empty($post['grafica_path'])): ?>
      <img class="img" src="<?= $h($post['grafica_path']) ?>" alt="">
    <?php endif; ?>
    <div class="cap"><?= $h($post['caption'] ?? 'El corillo está redactando tu caption…') ?></div>
  </div>

  <?php if ($planRow): ?>
    <form method="post" action="/crecer/panel/crear_checkout.php" class="an a4">
      <?= csrf_field() ?>
      <input type="hidden" name="marca" value="<?= $marca_id ?>">
      <input type="hidden" name="plan" value="<?= $h($planRow['slug']) ?>">
      <button type="submit" class="cta" style="margin-top:0">
        Activar Crecer · $<?= number_format((float)$planRow['precio_mensual'],0) ?>/mes
        <span class="small">Contenido nuevo cada semana, en tu voz</span>
      </button>
    </form>
  <?php else: ?>
    <a class="cta an a4" href="/crecer/panel/precios.php?marca=<?= $marca_id ?>">
      Activar Crecer
      <span class="small">Contenido nuevo cada semana, en tu voz</span>
    </a>
  <?php endif; ?>

  <div class="feat an a5">
    <div><?= $chk ?> Tu logo profesional con IA</div>
    <div><?= $chk ?> Contenido nuevo cada semana, en tu voz</div>
    <div><?= $chk ?> Gráficas con tus fotos + calendario</div>
  </div>

  <div><a class="later an a5" href="/crecer/panel/index.php?marca=<?= $marca_id ?>">Explorar mi panel primero →</a></div>
</div>
</body>
</html>
