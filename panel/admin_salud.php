<?php
// ============================================================
//  CRECER — Operaciones · Salud del sistema (solo admin)
//  panel/admin_salud.php
//
//  Para que nada se caiga en silencio: integraciones configuradas,
//  actividad reciente de los crons (publicador/métricas/IA), y un
//  feed de ERRORES global (todos los clientes).
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/iconos.php';
require_once __DIR__ . '/../includes/ia.php';       // ia_transporte(), openai_configurado()
require_once __DIR__ . '/../includes/meta.php';     // meta_configurado()
require_once __DIR__ . '/../includes/stripe.php';   // stripe_configurado()
require_once __DIR__ . '/../includes/metricas.php'; // refrescar insights de todos
requiere_login();
$usuario = usuario_actual($pdo);
if (($usuario['rol'] ?? '') !== 'admin') { http_response_code(403); exit('Acceso solo para administradores.'); }
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// ── Refrescar métricas de TODOS los clientes conectados (un toque) ──
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'refrescar_todo' && csrf_ok()) {
    @set_time_limit(150); @ignore_user_abort(true);
    $marcas = $pdo->query("SELECT marca_id FROM crecer_conexiones WHERE estado='activa' AND page_access_token IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
    $nm = 0; $ng = 0;
    foreach ($marcas as $cmid) { try { $r = metricas_refrescar_insights($pdo, (int)$cmid, 8, 0); $nm++; $ng += (int)($r['n'] ?? 0); } catch (Throwable $e) {} }
    header("Location: /crecer/panel/admin_salud.php?ok=1&nm={$nm}&ng={$ng}"); exit;
}
if (isset($_GET['ok'])) $flash = ['ok', '✓ Métricas refrescadas de Meta: '.(int)($_GET['ng'] ?? 0).' posts en '.(int)($_GET['nm'] ?? 0).' negocios conectados.'];
$csrf = csrf_token();

$ago = function($ts) {
    if (!$ts) return 'nunca';
    $s = time() - strtotime((string)$ts);
    if ($s < 60)    return 'hace ' . $s . 's';
    if ($s < 3600)  return 'hace ' . floor($s/60) . ' min';
    if ($s < 86400) return 'hace ' . floor($s/3600) . ' h';
    return 'hace ' . floor($s/86400) . ' d';
};
$val = fn($sql) => $pdo->query($sql)->fetchColumn();

// ── Integraciones ──
$integraciones = [
    ['Gemini (texto+imagen)', ia_transporte() !== 'mock', ia_transporte()==='mock'?'modo mock (sin llave)':ia_transporte()],
    ['OpenAI (imágenes)',     openai_configurado(),        openai_configurado()?'configurado':'opcional — sin llave (todo cae a Gemini)'],
    ['Meta (IG/FB)',          meta_configurado(),          meta_configurado()?'app configurada':'falta META_APP_ID/SECRET'],
    ['Stripe (cobros)',       stripe_configurado(),        stripe_configurado()?'configurado':'falta STRIPE_SECRET_KEY'],
];

// ── Actividad de crons (proxies honestos) ──
$ult_pub  = $val("SELECT MAX(created_at) FROM crecer_publicaciones");
$pub_24h  = (int)$val("SELECT COUNT(*) FROM crecer_publicaciones WHERE estado='ok' AND created_at>=(NOW()-INTERVAL 24 HOUR)");
$fail_24h = (int)$val("SELECT COUNT(*) FROM crecer_publicaciones WHERE estado='error' AND created_at>=(NOW()-INTERVAL 24 HOUR)");
$ult_met  = null; try { $ult_met = $val("SELECT MAX(actualizado_at) FROM crecer_metricas"); } catch (Throwable $e) {}
$ult_ia   = $val("SELECT MAX(created_at) FROM crecer_ia_log");
$ia_err_24 = (int)$val("SELECT COUNT(*) FROM crecer_ia_log WHERE estado='error' AND created_at>=(NOW()-INTERVAL 24 HOUR)");
$costo_mes = (float)$val("SELECT COALESCE(SUM(costo_usd),0) FROM crecer_ia_log WHERE created_at>=DATE_FORMAT(NOW(),'%Y-%m-01')");

// ── Feed de errores global (últimos) ──
$err_ia = $pdo->query("SELECT l.created_at, l.agente, l.error_msg, m.nombre_negocio
    FROM crecer_ia_log l LEFT JOIN crecer_marca m ON m.id=l.marca_id
    WHERE l.estado='error' ORDER BY l.id DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
$err_pub = $pdo->query("SELECT p.created_at, p.plataforma, p.error_msg, m.nombre_negocio
    FROM crecer_publicaciones p LEFT JOIN crecer_marca m ON m.id=p.marca_id
    WHERE p.estado='error' ORDER BY p.id DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Salud del sistema — Operaciones</title>
<link href="/crecer/assets/encuentralo-ui.css?v=13" rel="stylesheet">
<style>
  *{box-sizing:border-box} body{background:var(--crema,#fbf6ee);color:var(--tinta,#1b1622);font-family:'Plus Jakarta Sans',system-ui,sans-serif;margin:0}
  .top{display:flex;align-items:center;gap:14px;padding:14px 20px;background:#140a16;color:#fff}
  .top a{color:#cdc5d6;text-decoration:none;font-weight:700;font-size:13.5px}
  .top b{font-family:'Anton',sans-serif;text-transform:uppercase;letter-spacing:.03em;font-size:16px}
  .wrap{max-width:900px;margin:0 auto;padding:20px 18px 70px}
  h1{font-family:'Anton',sans-serif;text-transform:uppercase;font-size:26px;margin:8px 0 16px}
  .card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:16px 18px;margin-bottom:14px;box-shadow:var(--shadow-sm)}
  .card h2{font-family:'Anton',sans-serif;text-transform:uppercase;font-size:15px;letter-spacing:.03em;margin:0 0 12px}
  .row{display:flex;justify-content:space-between;gap:10px;padding:9px 0;border-bottom:1px solid var(--line);font-size:13.5px}.row:last-child{border-bottom:0}
  .row .k{color:var(--muted)}
  .st{display:inline-flex;align-items:center;gap:6px;font-weight:800;font-size:12px;border-radius:99px;padding:3px 10px}
  .st.ok{background:#e6f6ee;color:#0d7a44}.st.bad{background:#fdeaea;color:#b42318}.st.warn{background:#fff4d6;color:#8a5a00}.st.none{background:#f1edf5;color:#7a7088}
  .item{padding:9px 0;border-bottom:1px solid var(--line);font-size:12.5px}.item:last-child{border-bottom:0}
  .item .neg{color:var(--muted);font-weight:800;margin-right:6px}
  .err{color:#b42318;word-break:break-word}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}@media(max-width:680px){.grid2{grid-template-columns:1fr}}
  .big{font-family:'Anton',sans-serif;font-size:22px}
</style></head><body>
<?php $op_active='salud'; require __DIR__.'/_ops_top.php'; ?>
<div class="wrap">
  <h1>Salud del sistema</h1>
  <?php if ($flash): ?><div style="background:#e6f6ee;border:1px solid #b9eccf;color:#0d7a44;border-radius:12px;padding:11px 15px;margin-bottom:14px;font-weight:700;font-size:13.5px"><?= $h($flash[1]) ?></div><?php endif; ?>

  <div class="card">
    <h2>🔌 Integraciones</h2>
    <?php foreach ($integraciones as $i): ?>
      <div class="row"><span class="k"><?= $h($i[0]) ?></span><span><span class="st <?= $i[1]?'ok':'bad' ?>"><?= $i[1]?'✓':'✕' ?> <?= $h($i[2]) ?></span></span></div>
    <?php endforeach; ?>
  </div>

  <div class="grid2">
    <div class="card">
      <h2>⚙️ Publicador (cron)</h2>
      <div class="row"><span class="k">Última publicación</span><span class="st <?= $ult_pub?'ok':'none' ?>"><?= $h($ago($ult_pub)) ?></span></div>
      <div class="row"><span class="k">Publicados (24h)</span><span><b><?= $pub_24h ?></b></span></div>
      <div class="row"><span class="k">Fallidos (24h)</span><span><span class="st <?= $fail_24h>0?'bad':'ok' ?>"><?= $fail_24h ?></span></span></div>
    </div>
    <div class="card">
      <h2>📊 Métricas + IA</h2>
      <div class="row"><span class="k">Última métrica refrescada</span><span class="st <?= $ult_met?'ok':'none' ?>"><?= $h($ago($ult_met)) ?></span></div>
      <div class="row"><span class="k">Última actividad de IA</span><span class="st <?= $ult_ia?'ok':'none' ?>"><?= $h($ago($ult_ia)) ?></span></div>
      <div class="row"><span class="k">Errores de IA (24h)</span><span><span class="st <?= $ia_err_24>0?'warn':'ok' ?>"><?= $ia_err_24 ?></span></span></div>
      <form method="post" style="margin-top:12px" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Trayendo de Meta… (aguanta)'">
        <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="accion" value="refrescar_todo">
        <button type="submit" style="width:100%;border:0;cursor:pointer;font-family:inherit;font-weight:800;font-size:13.5px;color:#fff;background:var(--teal-700,#087);padding:11px;border-radius:11px"><?= ico('refresh') ?> Actualizar métricas de TODOS ahora</button>
      </form>
      <p style="font-size:11px;color:var(--muted);margin:7px 0 0;line-height:1.4">Trae de Meta el alcance/interacciones de todos los negocios conectados. Sin tener que entrar a cada Resultados.</p>
    </div>
  </div>

  <div class="card" style="text-align:center">
    <div class="k" style="color:var(--muted);font-size:12.5px">Costo total de IA este mes (todos los clientes)</div>
    <div class="big">$<?= number_format($costo_mes,2) ?></div>
  </div>

  <div class="card">
    <h2>🧨 Errores recientes — publicación</h2>
    <?php if (!$err_pub): ?><p style="color:var(--muted);font-size:13px;margin:0">Ninguno. 👍</p>
    <?php else: foreach ($err_pub as $e): ?>
      <div class="item"><span class="neg"><?= $h($e['nombre_negocio'] ?: '—') ?></span><span style="color:var(--muted)"><?= $h($e['plataforma']) ?> · <?= $h(date('d/m H:i', strtotime($e['created_at']))) ?></span><div class="err"><?= $h(mb_substr((string)$e['error_msg'],0,170)) ?></div></div>
    <?php endforeach; endif; ?>
  </div>

  <div class="card">
    <h2>🧨 Errores recientes — IA</h2>
    <?php if (!$err_ia): ?><p style="color:var(--muted);font-size:13px;margin:0">Ninguno. 👍</p>
    <?php else: foreach ($err_ia as $e): ?>
      <div class="item"><span class="neg"><?= $h($e['nombre_negocio'] ?: '—') ?></span><span style="color:var(--muted)"><?= $h($e['agente']) ?> · <?= $h(date('d/m H:i', strtotime($e['created_at']))) ?></span><div class="err"><?= $h(mb_substr((string)$e['error_msg'],0,170)) ?></div></div>
    <?php endforeach; endif; ?>
  </div>
</div>
</body></html>
