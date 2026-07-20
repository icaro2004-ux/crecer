<?php
// ============================================================
//  CRECER — Operaciones · Centro de EVIDENCIA (XPRIZE, global)
//  panel/admin_evidencia.php   (solo admin)
//
//  Agrega la evidencia del criterio #2 de TODOS los clientes:
//  decisiones de IA en producción, posts publicados por la IA,
//  revenue real (fríos vs allegados) y costo de API. + export CSV
//  del log de IA para el jurado. Nada inventado — todo de la BD.
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/iconos.php';
requiere_login();
$usuario = usuario_actual($pdo);
if (($usuario['rol'] ?? '') !== 'admin') { http_response_code(403); exit('Acceso solo para administradores.'); }

// ── Export CSV del log de IA (evidencia cruda para el jurado) ──
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="crecer_evidencia_ia.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['fecha_hora','marca','agente','accion','modelo','costo_usd','estado']);
    $q = $pdo->query("SELECT l.created_at, m.nombre_negocio, l.agente, l.accion, l.modelo, l.costo_usd, l.estado
                      FROM crecer_ia_log l LEFT JOIN crecer_marca m ON m.id=l.marca_id
                      ORDER BY l.id DESC LIMIT 5000");
    foreach ($q as $r) fputcsv($out, [$r['created_at'], $r['nombre_negocio'] ?? '', $r['agente'], $r['accion'], $r['modelo'], $r['costo_usd'], $r['estado']]);
    fclose($out); exit;
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$val = fn($sql) => $pdo->query($sql)->fetchColumn();

$dec_ia     = (int)$val("SELECT COUNT(*) FROM crecer_ia_log");
$dec_30     = (int)$val("SELECT COUNT(*) FROM crecer_ia_log WHERE created_at>=(NOW()-INTERVAL 30 DAY)");
$dec_ok     = (int)$val("SELECT COUNT(*) FROM crecer_ia_log WHERE estado='ok'");
$posts_pub  = (int)$val("SELECT COUNT(*) FROM crecer_contenido WHERE estado='publicado'");
$posts_mes  = (int)$val("SELECT COUNT(*) FROM crecer_contenido WHERE estado='publicado' AND publicado_at>=DATE_FORMAT(NOW(),'%Y-%m-01')");
$clientes   = (int)$val("SELECT COUNT(*) FROM crecer_marca");
$pagando    = (int)$val("SELECT COUNT(*) FROM crecer_suscripciones WHERE estado='activa'");
$costo_ia   = (float)$val("SELECT COALESCE(SUM(costo_usd),0) FROM crecer_ia_log");
// MRR real, separando clientes fríos de allegados (related-party).
$mrr = $pdo->query("SELECT COALESCE(SUM(CASE WHEN s.es_early_adopter=0 THEN p.precio_mensual ELSE 0 END),0) frio,
                           COALESCE(SUM(CASE WHEN s.es_early_adopter=1 THEN p.precio_mensual ELSE 0 END),0) allegado
                    FROM crecer_suscripciones s JOIN crecer_planes p ON p.id=s.plan_id WHERE s.estado='activa'")->fetch(PDO::FETCH_ASSOC);

// Actividad de IA por día (últimos 14).
$serie = [];
foreach ($pdo->query("SELECT DATE(created_at) d, COUNT(*) n FROM crecer_ia_log WHERE created_at>=(NOW()-INTERVAL 14 DAY) GROUP BY DATE(created_at)") as $r) $serie[$r['d']] = (int)$r['n'];
$dias = [];
for ($i=13; $i>=0; $i--) { $d = date('Y-m-d', time()-$i*86400); $dias[] = ['d'=>$d, 'n'=>($serie[$d] ?? 0)]; }
$max = max(1, max(array_column($dias,'n')));
// Por agente.
$por_agente = $pdo->query("SELECT agente, COUNT(*) n, COALESCE(SUM(costo_usd),0) c FROM crecer_ia_log GROUP BY agente ORDER BY n DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Evidencia XPRIZE — Operaciones</title>
<link href="/crecer/assets/encuentralo-ui.css?v=20" rel="stylesheet">
<style>
  *{box-sizing:border-box} body{background:var(--crema,#fbf6ee);color:var(--tinta,#1b1622);font-family:'Plus Jakarta Sans',system-ui,sans-serif;margin:0}
  .top{display:flex;align-items:center;gap:14px;padding:14px 20px;background:#140a16;color:#fff}
  .top a{color:#cdc5d6;text-decoration:none;font-weight:700;font-size:13.5px}.top b{font-family:'Poppins',sans-serif;text-transform:uppercase;font-size:16px}
  .wrap{max-width:940px;margin:0 auto;padding:20px 18px 70px}
  h1{font-family:'Poppins',sans-serif;text-transform:uppercase;font-size:26px;margin:8px 0 4px}
  .sub{color:var(--muted);font-size:13.5px;margin:0 0 16px}
  .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px}
  .kpi{background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px 16px;box-shadow:var(--shadow-sm)}
  .kpi .l{font-size:11.5px;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.03em}
  .kpi .v{font-family:'Poppins',sans-serif;font-size:30px;line-height:1;margin-top:5px}
  .kpi.hot{background:linear-gradient(135deg,#241633,#0e0a16);color:#fff}.kpi.hot .l{color:#bdb4c9}
  .card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:16px 18px;margin-bottom:14px;box-shadow:var(--shadow-sm)}
  .card h2{font-family:'Poppins',sans-serif;text-transform:uppercase;font-size:15px;letter-spacing:.03em;margin:0 0 12px}
  .bars{display:flex;align-items:flex-end;gap:6px;height:96px}
  .bar{flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;justify-content:flex-end;height:100%}
  .bar .col{width:100%;border-radius:5px 5px 0 0;background:linear-gradient(180deg,var(--teal),var(--teal-700));min-height:2px}
  .bar small{font-size:9px;color:var(--muted)}
  .row{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border-bottom:1px solid var(--line);font-size:13.5px}.row:last-child{border-bottom:0}.row .k{color:var(--muted)}
  .btn{display:inline-block;border:0;cursor:pointer;font-family:inherit;font-weight:800;font-size:14px;color:#fff;background:var(--tinta);padding:11px 18px;border-radius:11px;text-decoration:none}
</style></head><body>
<?php $op_active='evidencia'; require __DIR__.'/_ops_top.php'; ?>
<div class="wrap">
  <h1>Evidencia del criterio #2</h1>
  <p class="sub">La IA operando el negocio, con datos reales de la BD. Nada inventado.</p>

  <div class="kpis">
    <div class="kpi hot"><div class="l">Decisiones de IA</div><div class="v"><?= number_format($dec_ia) ?></div></div>
    <div class="kpi"><div class="l">Últimos 30 días</div><div class="v"><?= number_format($dec_30) ?></div></div>
    <div class="kpi"><div class="l">Posts publicados</div><div class="v"><?= number_format($posts_pub) ?></div></div>
    <div class="kpi"><div class="l">Publicados este mes</div><div class="v"><?= number_format($posts_mes) ?></div></div>
    <div class="kpi"><div class="l">Costo API real</div><div class="v">$<?= number_format($costo_ia,2) ?></div></div>
  </div>

  <div class="card">
    <h2>💵 Revenue real (MRR)</h2>
    <div class="row"><span class="k">Clientes fríos (no allegados)</span><span><b>$<?= number_format((float)$mrr['frio'],2) ?></b>/mes</span></div>
    <div class="row"><span class="k">Allegados (related-party)</span><span>$<?= number_format((float)$mrr['allegado'],2) ?>/mes</span></div>
    <div class="row"><span class="k">Suscripciones activas</span><span><?= $pagando ?> de <?= $clientes ?> negocios</span></div>
    <p class="sub" style="margin:10px 0 0">Para el jurado el que cuenta es el revenue <b>frío</b>; el de allegados va aparte (regla del proyecto).</p>
  </div>

  <div class="card">
    <h2>📈 Actividad de IA · últimos 14 días</h2>
    <div class="bars">
      <?php foreach ($dias as $d): ?>
        <div class="bar" title="<?= $d['d'] ?>: <?= $d['n'] ?> decisiones"><span class="col" style="height:<?= max(2,(int)round($d['n']/$max*100)) ?>%"></span><small><?= (int)substr($d['d'],8,2) ?></small></div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <h2>🤖 Por agente</h2>
    <?php foreach ($por_agente as $a): ?>
      <div class="row"><span class="k"><?= $h($a['agente']) ?></span><span><b><?= number_format((int)$a['n']) ?></b> decisiones · $<?= number_format((float)$a['c'],2) ?></span></div>
    <?php endforeach; ?>
  </div>

  <div class="card" style="text-align:center">
    <p class="sub" style="margin:0 0 12px">Descarga el log completo de decisiones de IA (agente, modelo, costo, hora) para la evidencia.</p>
    <a class="btn" href="?export=csv"><?= ico('download') ?> Exportar evidencia (CSV)</a>
  </div>
</div>
</body></html>
