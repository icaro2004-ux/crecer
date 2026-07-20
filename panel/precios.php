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

// Pagos/recibos de este negocio (facturación). El webhook de Stripe los escribe
// en `pagos`; los recibos oficiales descargables viven en el portal de Stripe.
$pagos = [];
if ($activa) {
    try {
        $pq = $pdo->prepare("SELECT created_at, monto, estado FROM pagos WHERE marca_id=? AND producto='crecer' ORDER BY created_at DESC LIMIT 12");
        $pq->execute([$marca_id]); $pagos = $pq->fetchAll();
    } catch (Throwable $e) { $pagos = []; }
}

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
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="/crecer/assets/encuentralo-ui.css?v=19" rel="stylesheet">
<style>
  body{background:var(--crema);font-family:var(--font-body);color:var(--tinta)}
  .wrap{max-width:900px;margin:0 auto;padding:0 18px 80px}
  .top{display:flex;align-items:center;gap:9px;padding:18px 0 4px}
  .top img{width:30px;height:34px;object-fit:contain}.top b{font-family:var(--font-display);font-weight:600;font-size:16px;letter-spacing:-.02em}
  .top b span{color:var(--teal)}
  .back{display:inline-flex;align-items:center;gap:6px;color:var(--muted);text-decoration:none;font-family:var(--font-display);font-weight:500;font-size:14px;margin:14px 0 0}
  .back:hover{color:var(--ink-soft)}
  h1{font-family:var(--font-display);font-weight:600;font-size:clamp(24px,4.6vw,30px);line-height:1.15;letter-spacing:-.02em;color:var(--ink-soft);margin:14px 0 6px}
  .sub{color:var(--muted);font-size:15px;margin:0 0 18px;line-height:1.5;max-width:46ch}
  .note{display:inline-flex;align-items:center;gap:8px;background:color-mix(in srgb,var(--teal) 10%,#fff);color:var(--teal-dark,#00827e);font-family:var(--font-display);font-weight:600;
    font-size:13px;border:1px solid color-mix(in srgb,var(--teal) 25%,#fff);border-radius:999px;padding:7px 14px;margin:2px 0 24px}
  .note svg{width:15px;height:15px}
  .msg{border-radius:12px;padding:12px 15px;font-size:14px;font-weight:600;margin:0 0 18px;line-height:1.45;border:1px solid transparent}
  .msg.ok{background:color-mix(in srgb,var(--teal) 10%,#fff);color:var(--teal-dark,#00827e);border-color:color-mix(in srgb,var(--teal) 25%,#fff)}
  .msg.err{background:#fdeaea;color:#b42318;border-color:#f5c2c0}
  /* plan actual (facturación sobria) */
  .now{background:var(--card);border:1px solid var(--line);border-radius:20px;padding:24px;box-shadow:var(--shadow);max-width:460px}
  .now .lbl{font-family:var(--font-display);font-weight:600;font-size:11.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)}
  .now .pn{font-family:var(--font-display);font-weight:600;font-size:24px;color:var(--ink-soft);margin-top:5px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
  .now .pn .pill{font-family:var(--font-display);font-size:12px;font-weight:600;color:var(--teal-dark,#00827e);background:color-mix(in srgb,var(--teal) 12%,#fff);padding:4px 11px;border-radius:99px}
  .now .pr{font-size:14.5px;color:var(--muted);margin-top:4px}
  .now .btn{margin-top:20px}
  .change{display:block;width:100%;text-align:center;background:0;border:0;cursor:pointer;font-family:var(--font-display);font-weight:500;font-size:14px;color:var(--muted);padding:16px 0 2px}
  .change:hover{color:var(--ink-soft)}
  /* pagos / recibos */
  .pays{margin-top:24px;max-width:460px}
  .pays-h{font-family:var(--font-display);font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin:0 0 10px}
  .pay{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;background:var(--card);border:1px solid var(--line);border-radius:14px;margin-bottom:8px}
  .pay-d{font-family:var(--font-display);font-weight:600;font-size:14px;color:var(--ink-soft)}
  .pay-s{font-size:12px;color:var(--muted);margin-top:1px;text-transform:capitalize}
  .pay-a{font-family:var(--font-display);font-weight:600;font-size:15px;color:var(--ink-soft);font-variant-numeric:tabular-nums}
  .pay-a.neg{color:var(--teal-dark,#00827e)}
  .pay-empty{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px;font-size:13.5px;color:var(--muted);line-height:1.5}
  .pay-link{display:inline-block;margin-top:12px;font-family:var(--font-display);font-weight:500;font-size:14px;color:var(--teal-dark,#00827e);text-decoration:none}
  .pay-link:hover{color:var(--ink-soft)}
  /* grid de planes */
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:6px}
  .grid.one{grid-template-columns:1fr;max-width:440px}
  .grid.chg{margin-top:16px}
  @media(max-width:680px){.grid{grid-template-columns:1fr}}
  .pcard{background:var(--card);border:1.5px solid var(--line);border-radius:20px;padding:24px 22px;position:relative;display:flex;flex-direction:column}
  .pcard.reco{border-color:color-mix(in srgb,var(--magenta) 40%,var(--line));box-shadow:0 24px 54px -30px rgba(239,67,117,.28)}
  .pill-top{position:absolute;top:-12px;left:22px;background:var(--btn-grad);color:#fff;font-family:var(--font-display);font-weight:600;font-size:11.5px;letter-spacing:.02em;padding:5px 12px;border-radius:99px;box-shadow:var(--btn-glow)}
  .pname{font-family:var(--font-display);font-weight:600;font-size:20px;color:var(--ink-soft)}
  .pdesc{color:var(--muted);font-size:13.5px;margin:4px 0 14px;min-height:36px;line-height:1.4}
  .price{font-family:var(--font-display);font-weight:600;font-size:36px;line-height:1;color:var(--ink-soft)}
  .price span{font-size:15px;font-weight:500;color:var(--muted)}
  ul{list-style:none;padding:0;margin:16px 0 20px;display:flex;flex-direction:column;gap:9px}
  li{display:flex;gap:9px;font-size:14px;color:var(--tinta);align-items:flex-start}
  li svg{width:16px;height:16px;color:var(--palma);flex:none;margin-top:1px}
  .btn{display:block;text-align:center;border:0;cursor:pointer;font-family:var(--font-display);font-weight:600;font-size:15px;border-radius:15px;padding:14px;text-decoration:none;width:100%;transition:transform .2s var(--ease),box-shadow .2s var(--ease)}
  .btn.pri{background:var(--btn-grad);color:#fff;box-shadow:var(--btn-glow)}.btn.pri:active{transform:translateY(1px);box-shadow:var(--btn-glow-active)}
  .btn.ghost{background:#fff;color:var(--ink-soft);border:1.5px solid var(--line)}.btn.ghost:hover{border-color:var(--magenta);color:var(--magenta)}
  .btn:disabled{opacity:.6;cursor:default}
  .foot{color:var(--muted);font-size:12.5px;margin-top:26px;line-height:1.7;max-width:520px}
  .foot b{color:var(--ink-soft)}.foot a{color:var(--muted)}
  [hidden]{display:none}
</style>
</head>
<body>
<?php
  $chk = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
  $gift = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13M5 12v7a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-7"/><path d="M12 8S9.5 3.5 7 5.5 12 8 12 8Zm0 0s2.5-4.5 5-2.5S12 8 12 8Z"/></svg>';
  $renderCard = function($p) use ($activa,$su,$marca_id,$h,$chk) {
    $reco = $p['slug']==='crecer';
    $feats = json_decode($p['features'] ?? '[]', true) ?: [];
    $es_actual = $activa && ($su['plan_slug'] ?? '')===$p['slug'];
    ob_start(); ?>
    <div class="pcard <?= $reco?'reco':'' ?>">
      <?php if($reco): ?><span class="pill-top">Más popular</span><?php endif; ?>
      <div class="pname"><?= $h($p['nombre']) ?></div>
      <p class="pdesc"><?= $h($p['descripcion']) ?></p>
      <div class="price">$<?= number_format((float)$p['precio_mensual'],0) ?><span>/mes</span></div>
      <ul><?php foreach($feats as $f): ?><li><?= $chk ?><?= $h($f) ?></li><?php endforeach; ?></ul>
      <?php if($es_actual): ?>
        <button class="btn ghost" disabled>Tu plan actual</button>
      <?php else: ?>
        <form method="post" action="/crecer/panel/crear_checkout.php">
          <?= csrf_field() ?><input type="hidden" name="marca" value="<?= $marca_id ?>"><input type="hidden" name="plan" value="<?= $h($p['slug']) ?>">
          <button type="submit" class="btn <?= $reco?'pri':'ghost' ?>"><?= $activa?'Cambiar a '.$h($p['nombre']):'Activar '.$h($p['nombre']) ?></button>
        </form>
      <?php endif; ?>
    </div>
    <?php return ob_get_clean();
  };
  $plan_actual = null; foreach($planes as $pp){ if($activa && ($su['plan_slug'] ?? '')===$pp['slug']) $plan_actual=$pp; }
?>
<div class="wrap">
  <div class="top"><img src="/crecer/assets/brand/crecer-mark.svg" alt=""><b>encuéntralo <span>crecer</span></b></div>
  <a class="back" href="/crecer/panel/index.php?marca=<?= $marca_id ?>">← Volver</a>

  <?php
    $msgs='';
    if(($_GET['motivo'] ?? '')==='muestra') $msgs.='<div class="msg ok">Usaste tu post de muestra gratis. Activa Crecer y recibe contenido nuevo cada semana — más tu logo.</div>';
    if(!empty($_GET['cancelado'])) $msgs.='<div class="msg err">No se completó el checkout. Cuando quieras, aquí estamos.</div>';
    if(!empty($_GET['error'])) $msgs.='<div class="msg err">'.$h($_GET['error']).'</div>';
  ?>

  <?php if ($activa): /* ── FACTURACIÓN: plan actual + pagos/recibos (un solo plan) ── */ ?>
    <h1>Facturación</h1>
    <?= $msgs ?>
    <div class="now">
      <div class="lbl">Plan actual</div>
      <div class="pn"><?= $h($plan_actual['nombre'] ?? suscripcion_etiqueta($su)) ?> <span class="pill">Activa</span></div>
      <div class="pr"><?php if($plan_actual): ?>$<?= number_format((float)$plan_actual['precio_mensual'],0) ?>/mes · se renueva solo<?php else: ?>Se renueva solo<?php endif; ?></div>
      <a class="btn pri" style="margin-top:20px" href="/crecer/panel/portal.php?marca=<?= $marca_id ?>">Gestionar mi plan</a>
    </div>

    <div class="pays">
      <div class="pays-h">Tus pagos</div>
      <?php if ($pagos): foreach ($pagos as $pg): $neg = (float)$pg['monto'] < 0 || ($pg['estado'] ?? '') === 'reembolso'; ?>
        <div class="pay">
          <div>
            <div class="pay-d"><?= $h(date('d/m/Y', strtotime($pg['created_at']))) ?></div>
            <div class="pay-s"><?= $h($pg['estado'] ?: 'pagado') ?></div>
          </div>
          <div class="pay-a <?= $neg ? 'neg' : '' ?>"><?= $neg ? '−' : '' ?>$<?= number_format(abs((float)$pg['monto']), 2) ?></div>
        </div>
      <?php endforeach; else: ?>
        <div class="pay-empty">Todavía no hay pagos registrados aquí. Tus recibos oficiales —con factura descargable— viven en el portal de Stripe.</div>
      <?php endif; ?>
      <a class="pay-link" href="/crecer/panel/portal.php?marca=<?= $marca_id ?>">Ver recibos en Stripe →</a>
    </div>

    <p class="foot">Cambia de tarjeta, revisa el próximo cobro o cancela desde el portal seguro de Stripe · <a href="/crecer/panel/index.php?marca=<?= $marca_id ?>">Volver al panel</a></p>

  <?php else: /* ── ACTIVACIÓN: coordinada, no landing ruidosa ── */ ?>
    <h1>Pon el corillo a trabajar tu negocio.</h1>
    <p class="sub">Un equipo digital que planifica, redacta y diseña tu contenido — en tu voz.</p>
    <div class="note"><?= $gift ?> Tu primer post va por la casa · cancela cuando quieras</div>
    <?= $msgs ?>
    <div class="grid <?= count($planes)<=1?'one':'' ?>">
      <?php foreach($planes as $p) echo $renderCard($p); ?>
    </div>
    <p class="foot">Al activar un plan se cobra el primer mes y <b>se desbloquea todo</b> (logo, más posts, descargas). Cancela cuando quieras — sin enredos. Pagos seguros con Stripe · <a href="/crecer/panel/index.php?marca=<?= $marca_id ?>">Volver al panel</a></p>
  <?php endif; ?>
</div>
</body>
</html>
