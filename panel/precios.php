<?php
// ============================================================
//  CRECER — Página de precios / planes
//  panel/precios.php
//
//  Muestra los planes desde crecer_planes (precio = dato, no
//  hardcoded). Cada botón abre Stripe Checkout (tarjeta
//  obligatoria, con período de prueba). Cancela cuando quieras.
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

$su     = suscripcion_de_marca($pdo, $marca_id);
$activa = suscripcion_activa($su);

$planes = $pdo->query("SELECT * FROM crecer_planes WHERE activo=1 ORDER BY orden")->fetchAll();
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Planes · <?= $h($marca['nombre_negocio']) ?> — Encuéntralo</title>
<link rel="icon" type="image/svg+xml" href="/crecer/assets/brand/encuentralo-pin.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="/crecer/assets/encuentralo-ui.css?v=17" rel="stylesheet">
<style>
  body{background:#f7f7fb;font-family:'Plus Jakarta Sans',system-ui,sans-serif;color:#1a1a24;margin:0}
  .wrap{max-width:920px;margin:0 auto;padding:0 18px 64px}
  .top{display:flex;align-items:center;gap:9px;position:sticky;top:0;z-index:20;background:#f7f7fb;padding:16px 0 12px;margin:0 0 6px}
  .top img{width:34px;height:36px;object-fit:contain}.top b{font-family:'Poppins';font-weight:900;font-size:19px;letter-spacing:-.01em}
  .top b span{color:#00a49f}
  h1{font-family:'Poppins';font-weight:900;font-size:30px;line-height:1.1;margin:18px 0 6px}
  .sub{color:#6b6b7b;font-size:15.5px;margin:0 0 6px}
  .trialnote{display:inline-flex;align-items:center;gap:8px;background:#eafaf0;color:#0d7a44;font-weight:700;
    font-size:13.5px;border:1px solid #b9eccf;border-radius:999px;padding:7px 13px;margin:14px 0 26px}
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
  .grid.one{grid-template-columns:1fr;max-width:430px;margin:0 auto}
  @media(max-width:680px){.grid{grid-template-columns:1fr}}
  .card{background:#fff;border:1.5px solid #ececf2;border-radius:20px;padding:24px 22px;position:relative;display:flex;flex-direction:column}
  .card.reco{border-color:#1a1a24;box-shadow:0 14px 40px -18px rgba(0,0,0,.35)}
  .pill{position:absolute;top:-12px;left:22px;background:#1a1a24;color:#fff;font-weight:800;font-size:11.5px;
    letter-spacing:.04em;text-transform:uppercase;padding:5px 11px;border-radius:999px}
  .pname{font-family:'Poppins';font-weight:900;font-size:21px;margin:2px 0 2px}
  .pdesc{color:#6b6b7b;font-size:13.8px;margin:0 0 14px;min-height:38px}
  .price{font-family:'Poppins';font-weight:900;font-size:38px;line-height:1}
  .price span{font-size:15px;font-weight:700;color:#6b6b7b}
  ul{list-style:none;padding:0;margin:16px 0 20px;display:flex;flex-direction:column;gap:9px}
  li{display:flex;gap:9px;font-size:14px;color:#33333f}li b{color:#0d7a44}
  .btn{display:block;text-align:center;border:none;cursor:pointer;font-family:'Poppins';font-weight:800;
    font-size:15px;border-radius:13px;padding:13px;text-decoration:none;width:100%}
  .btn.dark{background:#1a1a24;color:#fff}.btn.ghost{background:#f1f1f6;color:#1a1a24}
  .btn:disabled{opacity:.5;cursor:default}
  .foot{text-align:center;color:#8a8a98;font-size:12.5px;margin-top:24px;line-height:1.6}
  .cur{display:inline-block;background:#eafaf0;color:#0d7a44;font-weight:800;font-size:12px;border-radius:999px;padding:4px 10px;margin-left:8px;vertical-align:middle}
  .err{background:#fdeaea;color:#b42318;border:1px solid #f5c2c0;border-radius:12px;padding:12px 14px;font-size:14px;margin:0 0 18px}
</style>
</head>
<body>
<div class="wrap">
  <div class="top"><img src="/crecer/assets/brand/crecer-mark.svg" alt=""><b>encuéntralo <span>crecer</span></b></div>
  <h1>Pon el corillo a trabajar tu negocio<?php if ($activa): ?> <span class="cur"><?= $h(suscripcion_etiqueta($su)) ?></span><?php endif; ?></h1>
  <p class="sub">Un equipo digital que te planifica, redacta y diseña el contenido — en boricua de verdad.</p>
  <div class="trialnote"><?= ico('gift') ?> Tu primer post va por la casa · suscríbete para desbloquear el logo y todo lo demás · cancela cuando quieras</div>

  <?php if (($_GET['motivo'] ?? '') === 'muestra'): ?>
    <div class="err" style="background:#eafaf0;color:#0d7a44;border-color:#b9eccf"><?= ico('gift') ?> Usaste tu post de muestra gratis. Activa Crecer y recibe contenido nuevo cada semana — más tu logo.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['cancelado'])): ?>
    <div class="err">No se completó el checkout. Cuando quieras, aquí estamos. 👇</div>
  <?php endif; ?>
  <?php if (!empty($_GET['error'])): ?>
    <div class="err"><?= $h($_GET['error']) ?></div>
  <?php endif; ?>

  <div class="grid <?= count($planes) <= 1 ? 'one' : '' ?>">
    <?php foreach ($planes as $i => $p):
      $reco = ($p['slug'] === 'crecer');
      $feats = json_decode($p['features'] ?? '[]', true) ?: [];
      $es_actual = $activa && ($su['plan_slug'] ?? '') === $p['slug'];
    ?>
      <div class="card <?= $reco ? 'reco' : '' ?>">
        <?php if ($reco): ?><span class="pill">Más popular</span><?php endif; ?>
        <div class="pname"><?= $h($p['nombre']) ?></div>
        <p class="pdesc"><?= $h($p['descripcion']) ?></p>
        <div class="price">$<?= number_format((float)$p['precio_mensual'], 0) ?><span>/mes</span></div>
        <ul>
          <?php foreach ($feats as $f): ?><li><?= ico('check','chk') ?><?= $h($f) ?></li><?php endforeach; ?>
        </ul>
        <?php if ($es_actual): ?>
          <button class="btn ghost" disabled>Tu plan actual</button>
        <?php else: ?>
          <form method="post" action="/crecer/panel/crear_checkout.php">
            <?= csrf_field() ?>
            <input type="hidden" name="marca" value="<?= $marca_id ?>">
            <input type="hidden" name="plan" value="<?= $h($p['slug']) ?>">
            <button type="submit" class="btn <?= $reco ? 'dark' : 'ghost' ?>">
              <?= $activa ? 'Cambiar a ' . $h($p['nombre']) : 'Activar ' . $h($p['nombre']) ?>
            </button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($activa): ?>
    <p style="text-align:center;margin:22px 0 0">
      <a href="/crecer/panel/portal.php?marca=<?= $marca_id ?>" class="btn ghost" style="display:inline-flex;align-items:center;gap:8px;width:auto;padding:11px 20px"><?= ico('settings') ?> Gestionar mi plan / cancelar</a>
    </p>
  <?php endif; ?>

  <p class="foot">
    Tu <b>post de muestra es gratis</b>. Al activar un plan se cobra el primer mes y <b>se desbloquea todo</b> (logo, más posts, descargas).<br>
    Cancela cuando quieras desde el panel — sin enredos.<br>
    Pagos seguros con Stripe · <a href="/crecer/panel/index.php?marca=<?= $marca_id ?>" style="color:#6b6b7b">Volver al panel</a>
  </p>
</div>
</body>
</html>
