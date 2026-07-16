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
<link rel="icon" type="image/svg+xml" href="/crecer/assets/brand/encuentralo-pin.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Anton&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="/crecer/assets/encuentralo-ui.css?v=17" rel="stylesheet">
<style>
  body{background:var(--crema)}
  .wrap{max-width:560px;margin:0 auto;padding:30px 20px 70px;text-align:center}
  .top{display:flex;align-items:center;justify-content:center;gap:9px;margin-bottom:20px}
  .top img{height:30px}.top b{font-weight:800;font-size:19px}
  .tag{display:inline-flex;align-items:center;gap:8px;font-family:'Anton';text-transform:uppercase;letter-spacing:.05em;
    font-size:12.5px;background:var(--okk-bg);color:var(--okk-ink);padding:8px 15px;border-radius:999px;margin-bottom:14px}
  .tag img{width:18px;height:18px}
  h1{font-family:'Anton';text-transform:uppercase;letter-spacing:.5px;line-height:.98;font-size:clamp(30px,7vw,44px);color:var(--tinta);margin:0 0 8px}
  h1 span{color:var(--terracota)}
  .lede{color:var(--muted);font-size:15.5px;margin:0 0 24px}
  /* tarjeta del post (mock IG) */
  .post{background:var(--card);border:1px solid var(--line);border-radius:var(--r-lg);box-shadow:var(--shadow);overflow:hidden;text-align:left;max-width:420px;margin:0 auto}
  .post .ph{display:flex;align-items:center;gap:9px;padding:12px 14px;border-bottom:1px solid var(--line)}
  .post .av{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff;display:grid;place-items:center;font-weight:800}
  .post .hn{font-weight:800;font-size:14px}.post .hn small{display:block;color:var(--muted);font-weight:500;font-size:11.5px}
  .post .img{width:100%;display:block;background:var(--crema-2)}
  .post .cap{padding:14px;font-size:14.5px;line-height:1.5;color:#3a2f26;white-space:pre-wrap}
  .cta{display:block;width:100%;border:0;cursor:pointer;font-family:'Anton';text-transform:uppercase;letter-spacing:.03em;
    font-size:18px;color:#fff;background:linear-gradient(135deg,var(--coral),var(--terracota-700));padding:16px;border-radius:14px;
    box-shadow:0 14px 30px -12px rgba(239,67,117,.6);margin-top:24px;text-decoration:none}
  .cta .small{display:block;font-family:'Plus Jakarta Sans';text-transform:none;letter-spacing:0;font-size:12.5px;font-weight:600;opacity:.9;margin-top:2px}
  .later{display:inline-block;margin-top:16px;color:var(--muted);font-weight:700;font-size:14px;text-decoration:none}
  .feat{display:flex;flex-direction:column;gap:7px;max-width:380px;margin:18px auto 0;text-align:left}
  .feat div{display:flex;align-items:center;gap:9px;font-size:13.5px;color:#473b46}
  .feat .ic{width:16px;height:16px;color:var(--palma);flex:none}
  .lock{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;color:var(--muted);margin-top:16px}
  .lock img{width:15px;height:15px}
</style>
</head>
<body>
<div class="wrap">
  <div class="top"><img src="/crecer/assets/brand/encuentralo-pin.svg" alt=""><b>encuéntralo</b></div>

  <div class="tag"><img src="/crecer/assets/icons/aprobar.svg" alt=""> El corillo ya metió mano</div>
  <h1>¡Tu primer post<br><span>está listo!</span></h1>
  <p class="lede">Le hablaste 40 segundos y el corillo te armó esto — en tu voz, con tu foto. Esto es solo una probadita.</p>

  <!-- El post de muestra -->
  <div class="post">
    <div class="ph">
      <div class="av"><?= $h(mb_strtoupper(mb_substr($marca['nombre_negocio'],0,1))) ?></div>
      <div class="hn"><?= $h($marca['nombre_negocio']) ?><small>tu post de muestra · gratis</small></div>
    </div>
    <?php if (!empty($post['grafica_path'])): ?>
      <img class="img" src="<?= $h($post['grafica_path']) ?>" alt="post">
    <?php endif; ?>
    <div class="cap"><?= $h($post['caption'] ?? 'El corillo está redactando tu caption…') ?></div>
  </div>

  <?php if ($planRow): ?>
    <!-- Eligió un plan en el landing → directo al checkout -->
    <form method="post" action="/crecer/panel/crear_checkout.php">
      <?= csrf_field() ?>
      <input type="hidden" name="marca" value="<?= $marca_id ?>">
      <input type="hidden" name="plan" value="<?= $h($planRow['slug']) ?>">
      <button type="submit" class="cta">
        Activar Crecer · $<?= number_format((float)$planRow['precio_mensual'],0) ?>/mes
        <span class="small">Contenido nuevo cada semana, en tu voz</span>
      </button>
    </form>
  <?php else: ?>
    <!-- Vino gratis → invitar a activar -->
    <a class="cta" href="/crecer/panel/precios.php?marca=<?= $marca_id ?>">
      Activar Crecer
      <span class="small">Contenido nuevo cada semana, en tu voz</span>
    </a>
  <?php endif; ?>

  <div class="feat">
    <div><?= ico('check','ic') ?> Tu logo profesional con IA</div>
    <div><?= ico('check','ic') ?> Contenido nuevo cada semana, en tu voz</div>
    <div><?= ico('check','ic') ?> Gráficas con tus fotos + calendario</div>
  </div>

  <div><a class="later" href="/crecer/panel/index.php?marca=<?= $marca_id ?>">Explorar mi panel primero →</a></div>
</div>
</body>
</html>
