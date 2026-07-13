<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Centro de Operaciones (admin / dueño)
//  panel/admin.php
//
//  God-view del OPERADOR (Manuel), no del cliente. Responde:
//  quiénes son mis clientes, cómo van las ventas, cuánto gasté
//  en IA (bots), cuántos posts se hicieron, y dónde caen las quejas.
//
//  Acceso: solo usuarios.rol = 'admin'.
//  Patrón tomado del admin de Encuéntralo (KPIs+delta, tendencias).
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
requiere_login();
$usuario = usuario_actual($pdo);
if (($usuario['rol'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Acceso solo para administradores. (Tu cuenta no es admin.)');
}
// ── Acción: prender/apagar acceso de cortesía (Despegar gratis) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'cortesia' && csrf_ok()) {
    $mid = (int)($_POST['marca_id'] ?? 0);
    $on  = ($_POST['on'] ?? '') === '1';
    if ($mid) {
        $cur = $pdo->prepare("SELECT stripe_subscription_id FROM crecer_suscripciones WHERE marca_id=?");
        $cur->execute([$mid]); $stripe = $cur->fetchColumn();
        if ($on) {
            // No pisar una suscripción real de Stripe
            if (empty($stripe)) {
                $uid = (int)$pdo->query("SELECT usuario_id FROM crecer_marca WHERE id=$mid")->fetchColumn();
                $pid = (int)$pdo->query("SELECT id FROM crecer_planes WHERE slug='despegar' LIMIT 1")->fetchColumn();
                $pdo->prepare("INSERT INTO crecer_suscripciones (marca_id,usuario_id,plan_id,estado,es_early_adopter)
                               VALUES (?,?,?, 'activa', 1)
                               ON DUPLICATE KEY UPDATE plan_id=VALUES(plan_id), estado='activa', es_early_adopter=1")
                    ->execute([$mid,$uid,$pid]);
            }
        } else {
            // Quitar solo si es cortesía (sin Stripe) — nunca tocar una sub pagada
            $pdo->prepare("DELETE FROM crecer_suscripciones WHERE marca_id=? AND (stripe_subscription_id IS NULL OR stripe_subscription_id='')")
                ->execute([$mid]);
        }
    }
    header('Location: /crecer/panel/admin.php'); exit;
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$money = fn($n) => '$' . number_format((float)$n, 2);
$MES = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];

// ── KPIs de clientes ─────────────────────────────────────────
$total_clientes = (int)$pdo->query("SELECT COUNT(*) FROM crecer_marca")->fetchColumn();
$cm = $pdo->query("SELECT
    SUM(created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')) este,
    SUM(created_at >= DATE_FORMAT(NOW()-INTERVAL 1 MONTH,'%Y-%m-01') AND created_at < DATE_FORMAT(NOW(),'%Y-%m-01')) pasado
    FROM crecer_marca")->fetch();
$delta_cli = (int)$cm['este'] - (int)$cm['pasado'];

// ── Revenue (de suscripciones; precio = dato de crecer_planes) ──
$subs = $pdo->query("SELECT p.slug, p.nombre, p.precio_mensual, s.estado, s.es_early_adopter
    FROM crecer_suscripciones s JOIN crecer_planes p ON p.id=s.plan_id")->fetchAll();
$mrr = 0; $mrr_rel = 0; $por_plan = []; $n_activas = 0; $n_trial = 0; $pipeline_trial = 0;
foreach ($subs as $s) {
    $pm = (float)$s['precio_mensual'];
    if ($s['estado'] === 'activa') {
        $mrr += $pm; $n_activas++;
        if ($s['es_early_adopter']) $mrr_rel += $pm;
        $por_plan[$s['nombre']] = ($por_plan[$s['nombre']] ?? 0) + $pm;
    } elseif ($s['estado'] === 'trial') {
        $n_trial++; $pipeline_trial += $pm;
    }
}
$mrr_frio = $mrr - $mrr_rel;   // revenue de clientes fríos (no allegados)
$pagos_crecer = (float)$pdo->query("SELECT COALESCE(SUM(monto),0) FROM pagos WHERE producto='crecer' AND estado='completado'")->fetchColumn();

// ── Gasto en IA (bots) ───────────────────────────────────────
$ia_tot = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(costo_usd),0) c FROM crecer_ia_log WHERE estado='ok'")->fetch();
$ia_mes = (float)$pdo->query("SELECT COALESCE(SUM(costo_usd),0) FROM crecer_ia_log
    WHERE estado='ok' AND YEAR(created_at)=YEAR(NOW()) AND MONTH(created_at)=MONTH(NOW())")->fetchColumn();
$ia_errores = (int)$pdo->query("SELECT COUNT(*) FROM crecer_ia_log WHERE estado='error'")->fetchColumn();
$ia_por_agente = $pdo->query("SELECT agente, COUNT(*) n, COALESCE(SUM(costo_usd),0) c, MAX(created_at) ult
    FROM crecer_ia_log WHERE estado='ok' GROUP BY agente ORDER BY c DESC")->fetchAll();
$ia_por_modelo = $pdo->query("SELECT modelo, COUNT(*) n, COALESCE(SUM(costo_usd),0) c
    FROM crecer_ia_log WHERE estado='ok' GROUP BY modelo ORDER BY c DESC")->fetchAll();
$margen = $mrr - $ia_mes;   // MRR menos lo que cuesta operar la IA este mes

// ── Contenido producido ──────────────────────────────────────
$cont = ['borrador'=>0,'aprobado'=>0,'programado'=>0,'publicando'=>0,'publicado'=>0,'fallido'=>0,'rechazado'=>0];
foreach ($pdo->query("SELECT estado, COUNT(*) n FROM crecer_contenido GROUP BY estado") as $r) $cont[$r['estado']] = (int)$r['n'];
$total_posts = array_sum($cont);
$total_artes = (int)$pdo->query("SELECT COUNT(*) FROM crecer_graficas")->fetchColumn();
$total_logos = (int)$pdo->query("SELECT COUNT(*) FROM crecer_logos")->fetchColumn();
$autopiloto  = (int)$pdo->query("SELECT COUNT(*) FROM crecer_marca WHERE autopilot=1")->fetchColumn();

// ── Tendencia: ingresos registrados y posts, últimos 6 meses ──
$meses6 = [];
for ($i=5;$i>=0;$i--) $meses6[] = date('Y-m', strtotime("-$i month"));
$tp = []; foreach ($pdo->query("SELECT DATE_FORMAT(created_at,'%Y-%m') m, COUNT(*) n FROM crecer_contenido
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY m") as $r) $tp[$r['m']] = (int)$r['n'];
$serie_posts = []; foreach ($meses6 as $m) $serie_posts[$m] = $tp[$m] ?? 0;
$max_posts = max(1, max($serie_posts));

// ── Clientes (tabla) ─────────────────────────────────────────
$clientes = $pdo->query("SELECT m.id, m.nombre_negocio, m.created_at, m.autopilot,
        u.nombre dueno, u.email, u.telefono, u.rol dueno_rol,
        p.slug plan, p.nombre plan_nombre, s.estado sub_estado, s.es_early_adopter, s.stripe_subscription_id stripe_sub,
        (SELECT COUNT(*) FROM crecer_contenido c WHERE c.marca_id=m.id) posts,
        (SELECT COUNT(*) FROM crecer_contenido c WHERE c.marca_id=m.id AND c.estado='publicado') publicados,
        (SELECT COALESCE(SUM(costo_usd),0) FROM crecer_ia_log l WHERE l.marca_id=m.id) ia,
        (SELECT MAX(created_at) FROM crecer_ia_log l WHERE l.marca_id=m.id) ult
    FROM crecer_marca m JOIN usuarios u ON u.id=m.usuario_id
    LEFT JOIN crecer_suscripciones s ON s.marca_id=m.id
    LEFT JOIN crecer_planes p ON p.id=s.plan_id
    ORDER BY (u.rol='admin') DESC, ult DESC, m.id DESC")->fetchAll();

// ── Quejas / mensajes (DMs entrantes) ────────────────────────
$msgs_pend = (int)$pdo->query("SELECT COUNT(*) FROM crecer_mensajes WHERE estado='pendiente'")->fetchColumn();
$mensajes = $pdo->query("SELECT mz.*, m.nombre_negocio FROM crecer_mensajes mz
    LEFT JOIN crecer_marca m ON m.id=mz.marca_id ORDER BY mz.id DESC LIMIT 12")->fetchAll();

// Chat interno con clientes (soporte) — protegido por si la tabla no está aún en prod
$soporte_sin_leer = 0; $soporte_total = 0;
try {
    $soporte_sin_leer = (int)$pdo->query("SELECT COUNT(*) FROM crecer_soporte WHERE de='cliente' AND leido=0")->fetchColumn();
    $soporte_total    = (int)$pdo->query("SELECT COUNT(DISTINCT marca_id) FROM crecer_soporte")->fetchColumn();
} catch (Throwable $e) {}

$ago = function($ts){ if(!$ts) return '—'; $s=time()-strtotime($ts);
    if($s<3600) return floor($s/60).'min'; if($s<86400) return floor($s/3600).'h'; return floor($s/86400).'d'; };
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Centro de Operaciones · Crecer</title>
<link rel="icon" type="image/svg+xml" href="/crecer/assets/brand/encuentralo-pin.svg">
<link href="https://fonts.googleapis.com/css2?family=Anton&family=Poppins:wght@600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="/crecer/assets/encuentralo-ui.css?v=11" rel="stylesheet">
<style>
  :root{--grad:linear-gradient(120deg,var(--coral,#ff5c39),var(--magenta,#c0395f))}
  *{box-sizing:border-box}
  body{background:var(--crema,#fbf6ee);color:var(--tinta,#1b1622);font-family:'Plus Jakarta Sans',sans-serif;margin:0}
  body::before{content:"";position:fixed;top:0;left:0;right:0;height:4px;z-index:60;background:var(--grad)}
  .disp{font-family:'Anton',sans-serif;text-transform:uppercase;letter-spacing:.02em}
  .top{position:sticky;top:0;z-index:50;display:flex;align-items:center;flex-wrap:wrap;gap:10px 12px;padding:14px 22px;background:#140a16;color:#fff;box-shadow:0 6px 20px -12px rgba(0,0,0,.5)}
  .top img{height:28px}
  .top b{font-family:var(--font-display);font-weight:800;text-transform:lowercase;letter-spacing:-.03em;font-size:19px}
  .top .tag{font-family:'Anton';text-transform:uppercase;font-size:12px;letter-spacing:.08em;color:#ffcaa8;border:1px solid rgba(255,255,255,.18);padding:4px 10px;border-radius:99px;white-space:nowrap}
  .top a{color:#cdc5d6;text-decoration:none;font-size:13.5px;font-weight:600;white-space:nowrap}
  .op-actions{display:flex;align-items:center;flex-wrap:wrap;gap:8px 14px;margin-left:auto}
  .op-hi{font-size:13px;color:#9f96b0;white-space:nowrap}
  .op-salir{color:#fff!important;background:rgba(255,255,255,.12);padding:6px 12px;border-radius:8px;font-weight:700}
  @media(max-width:720px){
    .top{padding:12px 15px;gap:8px 10px}
    .op-actions{width:100%;margin-left:0;gap:7px 14px;padding-top:9px;border-top:1px solid rgba(255,255,255,.1)}
    .op-hi{display:none}
    .op-salir{margin-left:auto}
  }
  .wrap{max-width:1180px;margin:0 auto;padding:24px 26px 70px}
  h1.page-h{font-family:'Anton';text-transform:uppercase;font-size:clamp(26px,4vw,38px);letter-spacing:.02em;margin:6px 0 2px}
  .lede{color:var(--muted);font-size:14.5px;margin:0 0 20px}
  .sec{display:flex;align-items:center;gap:10px;margin:30px 0 14px}
  .sec h2{font-family:'Anton';text-transform:uppercase;font-size:18px;letter-spacing:.03em;margin:0}
  .sec:after{content:"";flex:1;height:1px;background:var(--line)}
  .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px}
  .kpi{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px;box-shadow:var(--shadow-sm)}
  .kpi .l{font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
  .kpi .v{font-family:'Anton';font-size:34px;line-height:1;margin-top:6px;color:var(--tinta)}
  .kpi .d{font-size:12px;font-weight:700;margin-top:4px}
  .kpi .d.up{color:#0d7a44}.kpi .d.down{color:#9b1c1c}.kpi .d.flat{color:var(--muted)}
  .kpi.hot{background:linear-gradient(135deg,#241633,#0e0a16);color:#fff}.kpi.hot .v{color:#fff}.kpi.hot .l{color:#bdb4c9}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
  .card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px;box-shadow:var(--shadow-sm)}
  .card h3{font-size:14px;font-weight:800;margin:0 0 12px;color:var(--tinta)}
  table{width:100%;border-collapse:collapse;font-size:13px}
  th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);padding:7px 8px;border-bottom:1px solid var(--line)}
  td{padding:9px 8px;border-bottom:1px solid var(--line);vertical-align:middle}
  tr:last-child td{border-bottom:0}
  .pill{display:inline-block;font-size:11px;font-weight:800;padding:3px 9px;border-radius:99px}
  .pill.act{background:#e6f6ee;color:#0d7a44}.pill.trial{background:#fdeede;color:#9a5b00}.pill.none{background:#f1edf5;color:#7a7088}
  .pill.desp{background:#efe6ff;color:#5b2db8}.pill.cre{background:#e6f6ee;color:#0d7a44}
  .rel{font-size:10px;font-weight:800;color:#9a5b00;background:#fdeede;padding:2px 7px;border-radius:99px;margin-left:4px}
  .bars{display:flex;align-items:flex-end;gap:10px;height:120px;margin-top:6px}
  .bar{flex:1;display:flex;flex-direction:column;align-items:center;gap:5px;justify-content:flex-end;height:100%}
  .bar .col{width:100%;max-width:42px;background:var(--grad);border-radius:6px 6px 0 0;min-height:3px}
  .bar .bl{font-size:10.5px;color:var(--muted);font-weight:700}.bar .bv{font-size:10.5px;font-weight:800}
  .num{font-family:'Anton';font-size:13px;color:var(--tinta)}
  .gap{background:#fff;border:1px dashed var(--line);border-radius:14px;padding:16px;color:var(--muted);font-size:13.5px}
  .gap b{color:var(--tinta)}
  @media(max-width:820px){.grid2{grid-template-columns:1fr}.scrollx{overflow-x:auto}}
</style>
</head>
<body>
<div class="top">
  <img src="/crecer/assets/brand/encuentralo-pin.svg" alt=""><b>encuéntralo</b>
  <span class="tag">⚙ Operaciones</span>
  <div class="op-actions">
    <span class="op-hi">Hola, <?= $h($usuario['nombre']) ?></span>
    <a href="/crecer/panel/admin_soporte.php">Soporte</a>
    <a href="/crecer/panel/admin_equipo.php">Equipo</a>
    <a href="#clientes">Ver clientes ↓</a>
    <a class="op-salir" href="/crecer/logout.php">🚪 Salir</a>
  </div>
</div>

<div class="wrap">
  <h1 class="page-h">Centro de Operaciones</h1>
  <p class="lede">Tu negocio Crecer de un vistazo — clientes, ventas, lo que cuesta la IA y lo que produce.</p>

  <!-- KPIs -->
  <div class="kpis">
    <div class="kpi"><div class="l">Clientes</div><div class="v"><?= $total_clientes ?></div>
      <div class="d <?= $delta_cli>0?'up':($delta_cli<0?'down':'flat') ?>"><?= $delta_cli>0?'▲ +'.$delta_cli:($delta_cli<0?'▼ '.$delta_cli:'—') ?> este mes</div></div>
    <div class="kpi hot"><div class="l">MRR (recurrente)</div><div class="v"><?= $money($mrr) ?></div>
      <div class="d" style="color:#bdb4c9"><?= $n_activas ?> activas · <?= $n_trial ?> en prueba</div></div>
    <div class="kpi"><div class="l">Gasto IA (este mes)</div><div class="v"><?= $money($ia_mes) ?></div>
      <div class="d flat"><?= number_format((int)$ia_tot['n']) ?> llamadas · <?= $money($ia_tot['c']) ?> total</div></div>
    <div class="kpi"><div class="l">Margen del mes</div><div class="v" style="color:<?= $margen>=0?'#0d7a44':'#9b1c1c' ?>"><?= $money($margen) ?></div>
      <div class="d flat">MRR − gasto IA</div></div>
    <div class="kpi"><div class="l">Posts producidos</div><div class="v"><?= $total_posts ?></div>
      <div class="d flat"><?= $cont['publicado'] ?> publicados · <?= $total_artes ?> artes</div></div>
  </div>

  <!-- VENTAS -->
  <div class="sec"><h2>💰 Ventas</h2></div>
  <div class="grid2">
    <div class="card">
      <h3>Ingreso recurrente por plan</h3>
      <table>
        <tr><th>Plan</th><th>MRR</th></tr>
        <?php foreach ($por_plan as $pl=>$mt): ?><tr><td><?= $h($pl) ?></td><td class="num"><?= $money($mt) ?></td></tr><?php endforeach; ?>
        <tr><td><b>Total MRR</b></td><td class="num"><b><?= $money($mrr) ?></b></td></tr>
      </table>
      <div style="margin-top:12px;font-size:12.5px;color:var(--muted);line-height:1.6">
        Clientes fríos: <b style="color:var(--tinta)"><?= $money($mrr_frio) ?></b> · Allegados (related-party): <b style="color:#9a5b00"><?= $money($mrr_rel) ?></b><br>
        Pipeline en prueba: <b style="color:var(--tinta)"><?= $money($pipeline_trial) ?></b> (<?= $n_trial ?> trial)<br>
        Cobros registrados en <code>pagos</code>: <b style="color:var(--tinta)"><?= $money($pagos_crecer) ?></b>
      </div>
      <?php if ($pagos_crecer == 0 && $mrr > 0): ?>
        <div class="gap" style="margin-top:12px">⚠️ El MRR sale de <code>crecer_suscripciones</code>, pero <b>el webhook de Stripe no está escribiendo en <code>pagos</code></b> todavía. Para reportar revenue limpio al jurado, hay que registrar cada cobro en <code>pagos</code> (producto='crecer').</div>
      <?php endif; ?>
    </div>
    <div class="card">
      <h3>Posts producidos por mes</h3>
      <div class="bars">
        <?php foreach ($serie_posts as $ym=>$n): ?>
          <div class="bar"><div class="bv"><?= $n ?></div><div class="col" style="height:<?= max(3,(int)round($n/$max_posts*100)) ?>px"></div><div class="bl"><?= $MES[(int)substr($ym,5,2)] ?></div></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- GASTO EN BOTS -->
  <div class="sec"><h2>🤖 Gasto en bots (IA)</h2></div>
  <div class="grid2">
    <div class="card">
      <h3>Por agente</h3>
      <table>
        <tr><th>Agente</th><th>Llamadas</th><th>Costo</th><th>Último</th></tr>
        <?php foreach ($ia_por_agente as $a): ?>
          <tr><td><?= $h($a['agente']) ?></td><td><?= (int)$a['n'] ?></td><td class="num"><?= $money($a['c']) ?></td><td style="color:var(--muted)"><?= $ago($a['ult']) ?></td></tr>
        <?php endforeach; ?>
      </table>
    </div>
    <div class="card">
      <h3>Por modelo · salud</h3>
      <table>
        <tr><th>Modelo</th><th>Llamadas</th><th>Costo</th></tr>
        <?php foreach ($ia_por_modelo as $mo): ?>
          <tr><td><?= $h($mo['modelo']) ?></td><td><?= (int)$mo['n'] ?></td><td class="num"><?= $money($mo['c']) ?></td></tr>
        <?php endforeach; ?>
      </table>
      <div style="margin-top:12px;font-size:12.5px;color:var(--muted)">Errores de IA registrados: <b style="color:<?= $ia_errores>0?'#9b1c1c':'var(--tinta)' ?>"><?= $ia_errores ?></b> · Marcas en piloto automático: <b style="color:var(--tinta)"><?= $autopiloto ?></b></div>
    </div>
  </div>

  <!-- CLIENTES -->
  <div class="sec" id="clientes"><h2>👥 Clientes (<?= $total_clientes ?>)</h2></div>
  <div class="card scrollx">
    <table>
      <tr><th>Negocio</th><th>Dueño</th><th>Plan</th><th>Posts</th><th>Pub.</th><th>Gasto IA</th><th>Activo</th><th>Auto</th><th>Cortesía</th></tr>
      <?php foreach ($clientes as $c):
        $pc = $c['plan']==='despegar'?'desp':($c['plan']==='crecer'?'cre':'none');
        $se = $c['sub_estado']==='activa'?'act':($c['sub_estado']==='trial'?'trial':'none');
        $real_paid = !empty($c['stripe_sub']);
        $cort = (!$real_paid && (int)$c['es_early_adopter']===1 && $c['sub_estado']==='activa'); ?>
        <tr>
          <td><a href="/crecer/panel/index.php?marca=<?= (int)$c['id'] ?>" style="color:var(--tinta);font-weight:800;text-decoration:none" title="Abrir panel de este negocio">🔗 <?= $h($c['nombre_negocio']) ?></a><?php if (($c['dueno_rol'] ?? '') === 'admin'): ?> <span style="background:#fff4d6;border:1px solid #f2d488;color:#8a5a00;border-radius:99px;padding:1px 8px;font-size:10.5px;font-weight:800;white-space:nowrap">⭐ Tu negocio</span><?php endif; ?></td>
          <td><?= $h($c['dueno']) ?><br><span style="color:var(--muted);font-size:11px"><?= $h($c['email']) ?></span></td>
          <td><?php if($c['plan']): ?><span class="pill <?= $pc ?>"><?= $h($c['plan_nombre']) ?></span> <span class="pill <?= $se ?>"><?= $h($c['sub_estado']?:'—') ?></span><?php if($c['es_early_adopter']):?><span class="rel">allegado</span><?php endif;?><?php else: ?><span class="pill none">sin plan</span><?php endif; ?></td>
          <td class="num"><?= (int)$c['posts'] ?></td>
          <td class="num"><?= (int)$c['publicados'] ?></td>
          <td class="num"><?= $money($c['ia']) ?></td>
          <td style="color:var(--muted)"><?= $ago($c['ult']) ?></td>
          <td><?= $c['autopilot']?'🌙':'—' ?></td>
          <td>
            <?php if ($real_paid): ?>
              <span class="pill act">💳 paga</span>
            <?php elseif ($cort): ?>
              <form method="post" style="display:inline">
                <?= csrf_field() ?><input type="hidden" name="accion" value="cortesia"><input type="hidden" name="marca_id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="on" value="0">
                <button type="submit" title="Quitar acceso gratis" style="border:1.5px solid var(--line);cursor:pointer;background:#fff;color:#9a5b00;font-weight:800;font-size:11px;padding:5px 9px;border-radius:8px">🎁 ✕</button>
              </form>
            <?php else: ?>
              <form method="post" style="display:inline">
                <?= csrf_field() ?><input type="hidden" name="accion" value="cortesia"><input type="hidden" name="marca_id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="on" value="1">
                <button type="submit" title="Dar Despegar gratis" style="border:0;cursor:pointer;background:var(--palma,#16b86a);color:#fff;font-weight:800;font-size:11px;padding:5px 10px;border-radius:8px">Dar gratis</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <!-- SOPORTE (chat interno con clientes) -->
  <div class="sec"><h2>💬 Soporte — chat con tus clientes <?php if($soporte_sin_leer):?><span class="pill trial" style="font-size:12px"><?= $soporte_sin_leer ?> sin leer</span><?php endif;?></h2></div>
  <div class="card">
    <p style="margin:0 0 14px;font-size:13.5px;color:var(--muted)">Mensajes directos entre tú y los negocios (dudas, quejas, soporte). <?= $soporte_total ?> conversación<?= $soporte_total===1?'':'es' ?>.</p>
    <a href="/crecer/panel/admin_soporte.php" style="display:inline-block;background:var(--tinta);color:#fff;font-weight:800;font-size:14px;padding:11px 20px;border-radius:11px;text-decoration:none">Abrir bandeja de soporte →</a>
  </div>

  <!-- QUEJAS / MENSAJES (DMs de clientes finales) -->
  <div class="sec"><h2>💬 Quejas & mensajes <?php if($msgs_pend):?><span class="pill trial" style="font-size:12px"><?= $msgs_pend ?> pendientes</span><?php endif;?></h2></div>
  <div class="card">
    <?php if ($mensajes): ?>
      <table>
        <tr><th>Negocio</th><th>De</th><th>Mensaje</th><th>Estado</th></tr>
        <?php foreach ($mensajes as $mz): ?>
          <tr><td><?= $h($mz['nombre_negocio']) ?></td><td><?= $h($mz['remitente']) ?></td>
            <td><?= $h(mb_strimwidth((string)$mz['mensaje_entrante'],0,70,'…')) ?></td>
            <td><span class="pill <?= $mz['estado']==='respondido'?'act':'trial' ?>"><?= $h($mz['estado']) ?></span></td></tr>
        <?php endforeach; ?>
      </table>
    <?php else: ?>
      <div class="gap">📭 <b>Todavía no hay un canal de quejas/mensajes conectado.</b> La tabla <code>crecer_mensajes</code> está lista para recibir DMs de IG/FB/WhatsApp y que el agente <b>Responder</b> los conteste — pero ese canal aún no se ha integrado. <b>Es el próximo módulo de operación</b> (entra junto con la publicación a Meta).</div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
