<?php
// ============================================================
//  CRECER — Operaciones · Analítica global (solo admin)
//  panel/admin_analitica.php
//
//  Números del negocio de un vistazo: gasto (día/mes), posts
//  generados y publicados, alcance e interacciones totales de
//  TODOS los clientes + desglose por cliente (tabla filtrable) y
//  gráficas por día. Todo de la BD.
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/iconos.php';
requiere_login();
$usuario = usuario_actual($pdo);
if (($usuario['rol'] ?? '') !== 'admin') { http_response_code(403); exit('Acceso solo para administradores.'); }
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$val = fn($sql) => $pdo->query($sql)->fetchColumn();
$nf  = fn($n) => number_format((float)$n);

// ── KPIs ──
$gasto_hoy = (float)$val("SELECT COALESCE(SUM(costo_usd),0) FROM crecer_ia_log WHERE DATE(created_at)=CURDATE()");
$gasto_mes = (float)$val("SELECT COALESCE(SUM(costo_usd),0) FROM crecer_ia_log WHERE created_at>=DATE_FORMAT(NOW(),'%Y-%m-01')");
$creados_mes = (int)$val("SELECT COUNT(*) FROM crecer_contenido WHERE created_at>=DATE_FORMAT(NOW(),'%Y-%m-01')");
$publ_mes    = (int)$val("SELECT COUNT(*) FROM crecer_contenido WHERE estado='publicado' AND publicado_at>=DATE_FORMAT(NOW(),'%Y-%m-01')");
$alcance_tot = 0; $inter_tot = 0;
try { $alcance_tot = (int)$val("SELECT COALESCE(SUM(alcance),0) FROM crecer_metricas"); $inter_tot = (int)$val("SELECT COALESCE(SUM(interacciones),0) FROM crecer_metricas"); } catch (Throwable $e) {}

// ── Series por día (14) ──
$serieGasto = []; foreach ($pdo->query("SELECT DATE(created_at) d, COALESCE(SUM(costo_usd),0) v FROM crecer_ia_log WHERE created_at>=(NOW()-INTERVAL 14 DAY) GROUP BY DATE(created_at)") as $r) $serieGasto[$r['d']] = (float)$r['v'];
$seriePost  = []; foreach ($pdo->query("SELECT DATE(created_at) d, COUNT(*) n FROM crecer_contenido WHERE created_at>=(NOW()-INTERVAL 14 DAY) GROUP BY DATE(created_at)") as $r) $seriePost[$r['d']] = (int)$r['n'];
$dias = [];
for ($i=13; $i>=0; $i--) { $d = date('Y-m-d', time()-$i*86400); $dias[] = ['d'=>$d, 'g'=>($serieGasto[$d] ?? 0), 'p'=>($seriePost[$d] ?? 0)]; }
$maxG = max(0.0001, max(array_column($dias,'g')));
$maxP = max(1, max(array_column($dias,'p')));

// ── Desglose por cliente ──
$sqlBase = "SELECT m.id, m.nombre_negocio,
     (SELECT COUNT(*) FROM crecer_contenido c WHERE c.marca_id=m.id) creados,
     (SELECT COUNT(*) FROM crecer_contenido c WHERE c.marca_id=m.id AND c.estado='publicado') publicados,
     (SELECT COALESCE(SUM(costo_usd),0) FROM crecer_ia_log l WHERE l.marca_id=m.id) gasto";
try {
    $clientes = $pdo->query($sqlBase . ",
     (SELECT COALESCE(SUM(alcance),0) FROM crecer_metricas g WHERE g.marca_id=m.id) alcance,
     (SELECT COALESCE(SUM(interacciones),0) FROM crecer_metricas g WHERE g.marca_id=m.id) inter
     FROM crecer_marca m ORDER BY alcance DESC, publicados DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $clientes = $pdo->query($sqlBase . ", 0 alcance, 0 inter FROM crecer_marca m ORDER BY publicados DESC")->fetchAll(PDO::FETCH_ASSOC);
}
$op_active = 'analitica';
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Analítica — Operaciones</title>
<link href="/crecer/assets/encuentralo-ui.css?v=12" rel="stylesheet">
<style>
  *{box-sizing:border-box} body{background:var(--crema,#fbf6ee);color:var(--tinta,#1b1622);font-family:'Plus Jakarta Sans',system-ui,sans-serif;margin:0}
  .wrap{max-width:1040px;margin:0 auto;padding:20px 18px 70px}
  h1{font-family:'Anton',sans-serif;text-transform:uppercase;font-size:26px;margin:8px 0 4px}
  .sub{color:var(--muted);font-size:13.5px;margin:0 0 16px}
  .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:16px}
  .kpi{background:#fff;border:1px solid var(--line);border-radius:14px;padding:13px 15px;box-shadow:var(--shadow-sm)}
  .kpi .l{font-size:11px;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.03em}
  .kpi .v{font-family:'Anton',sans-serif;font-size:27px;line-height:1;margin-top:5px;font-variant-numeric:tabular-nums}
  .kpi.hot{background:linear-gradient(135deg,#241633,#0e0a16);color:#fff}.kpi.hot .l{color:#bdb4c9}
  .card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:16px 18px;margin-bottom:14px;box-shadow:var(--shadow-sm)}
  .card h2{font-family:'Anton',sans-serif;text-transform:uppercase;font-size:15px;letter-spacing:.03em;margin:0 0 14px}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}@media(max-width:760px){.grid2{grid-template-columns:1fr}}
  .bars{display:flex;align-items:flex-end;gap:5px;height:110px;border-bottom:1px solid var(--line);padding-bottom:2px}
  .bar{flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;justify-content:flex-end;height:100%}
  .bar .col{width:100%;max-width:26px;border-radius:5px 5px 0 0;min-height:2px}
  .bar small{font-size:9px;color:var(--muted)}
  table{width:100%;border-collapse:collapse;font-size:13px}
  th{text-align:right;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:var(--muted);padding:8px 10px;border-bottom:1.5px solid var(--line);cursor:pointer;user-select:none;white-space:nowrap}
  th:first-child{text-align:left}
  td{padding:9px 10px;border-bottom:1px solid var(--line);text-align:right;font-variant-numeric:tabular-nums}
  td:first-child{text-align:left;font-weight:700}
  tr:last-child td{border-bottom:0}
  .fbox{display:flex;gap:10px;align-items:center;margin-bottom:10px;flex-wrap:wrap}
  .fbox input{flex:1;min-width:180px;font-family:inherit;font-size:14px;border:1.5px solid var(--line);border-radius:10px;padding:9px 12px}
  .scrollx{overflow-x:auto}
  .neg{color:#b3123b}.pos{color:#0d7a44}
  a.lk{color:var(--terracota);text-decoration:none;font-weight:700}
</style></head><body>
<?php require __DIR__ . '/_ops_top.php'; ?>
<div class="wrap">
  <h1>Analítica</h1>
  <p class="sub">Los números de todos los clientes, en vivo desde la BD.</p>

  <div class="kpis">
    <div class="kpi hot"><div class="l">Gasto IA hoy</div><div class="v">$<?= number_format($gasto_hoy,2) ?></div></div>
    <div class="kpi"><div class="l">Gasto IA (mes)</div><div class="v">$<?= number_format($gasto_mes,2) ?></div></div>
    <div class="kpi"><div class="l">Posts generados (mes)</div><div class="v"><?= $nf($creados_mes) ?></div></div>
    <div class="kpi"><div class="l">Publicados (mes)</div><div class="v"><?= $nf($publ_mes) ?></div></div>
    <div class="kpi"><div class="l">Alcance total</div><div class="v"><?= $nf($alcance_tot) ?></div></div>
    <div class="kpi"><div class="l">Interacciones total</div><div class="v"><?= $nf($inter_tot) ?></div></div>
  </div>

  <div class="grid2">
    <div class="card">
      <h2>💸 Gasto IA · 14 días</h2>
      <div class="bars">
        <?php foreach ($dias as $d): ?><div class="bar" title="<?= $d['d'] ?>: $<?= number_format($d['g'],2) ?>"><span class="col" style="height:<?= max(2,(int)round($d['g']/$maxG*100)) ?>%;background:linear-gradient(180deg,var(--coral),var(--magenta))"></span><small><?= (int)substr($d['d'],8,2) ?></small></div><?php endforeach; ?>
      </div>
    </div>
    <div class="card">
      <h2>📝 Posts generados · 14 días</h2>
      <div class="bars">
        <?php foreach ($dias as $d): ?><div class="bar" title="<?= $d['d'] ?>: <?= $d['p'] ?> posts"><span class="col" style="height:<?= max(2,(int)round($d['p']/$maxP*100)) ?>%;background:linear-gradient(180deg,var(--teal),var(--teal-700))"></span><small><?= (int)substr($d['d'],8,2) ?></small></div><?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="card">
    <h2>👥 Por cliente</h2>
    <div class="fbox">
      <input type="text" id="filtro" placeholder="🔎 Filtrar negocio…" oninput="filtrar()">
      <span class="sub" style="margin:0">Toca un encabezado para ordenar.</span>
    </div>
    <div class="scrollx">
      <table id="tabla">
        <thead><tr>
          <th data-c="0" data-t="s">Negocio</th>
          <th data-c="1" data-t="n">Creados</th>
          <th data-c="2" data-t="n">Publicados</th>
          <th data-c="3" data-t="n">Alcance</th>
          <th data-c="4" data-t="n">Interac.</th>
          <th data-c="5" data-t="n">Gasto IA</th>
        </tr></thead>
        <tbody>
          <?php foreach ($clientes as $c): ?>
          <tr>
            <td><a class="lk" href="/crecer/panel/admin_cliente.php?marca=<?= (int)$c['id'] ?>"><?= $h($c['nombre_negocio']) ?></a></td>
            <td data-v="<?= (int)$c['creados'] ?>"><?= $nf($c['creados']) ?></td>
            <td data-v="<?= (int)$c['publicados'] ?>"><?= $nf($c['publicados']) ?></td>
            <td data-v="<?= (int)$c['alcance'] ?>"><?= $nf($c['alcance']) ?></td>
            <td data-v="<?= (int)$c['inter'] ?>"><?= $nf($c['inter']) ?></td>
            <td data-v="<?= (float)$c['gasto'] ?>">$<?= number_format((float)$c['gasto'],2) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<script>
  function filtrar(){
    var q=(document.getElementById('filtro').value||'').toLowerCase();
    document.querySelectorAll('#tabla tbody tr').forEach(function(tr){
      tr.style.display = tr.cells[0].textContent.toLowerCase().indexOf(q)>=0 ? '' : 'none';
    });
  }
  var _sortDir={};
  document.querySelectorAll('#tabla th').forEach(function(th){
    th.addEventListener('click', function(){
      var ci=+th.dataset.c, tipo=th.dataset.t, dir=_sortDir[ci]=!_sortDir[ci];
      var tb=document.querySelector('#tabla tbody');
      var rows=[].slice.call(tb.querySelectorAll('tr'));
      rows.sort(function(a,b){
        var va,vb;
        if(tipo==='n'){ va=parseFloat(a.cells[ci].dataset.v||'0'); vb=parseFloat(b.cells[ci].dataset.v||'0'); }
        else { va=a.cells[ci].textContent.toLowerCase(); vb=b.cells[ci].textContent.toLowerCase(); }
        return (va<vb?-1:va>vb?1:0)*(dir?1:-1);
      });
      rows.forEach(function(r){ tb.appendChild(r); });
    });
  });
</script>
</body></html>
