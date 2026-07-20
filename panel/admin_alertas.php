<?php
// ============================================================
//  CRECER — Operaciones · Problemas (radar de soporte, solo admin)
//  panel/admin_alertas.php
//
//  Un solo lugar que te dice QUÉ cliente tiene un problema y CUÁL
//  es, para resolverlo: posts que no pudieron publicar (con el
//  error real) y conexiones de Meta rotas. Con acciones directas.
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/iconos.php';
requiere_login();
$usuario = usuario_actual($pdo);
if (($usuario['rol'] ?? '') !== 'admin') { http_response_code(403); exit('Acceso solo para administradores.'); }
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// ── Acción: reintentar TODOS los posts fallidos de un cliente ──
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'retry_all' && csrf_ok()) {
    require_once __DIR__ . '/../includes/publicador.php';
    @set_time_limit(150); @ignore_user_abort(true);
    $rmid = (int)($_POST['marca_id'] ?? 0);
    $ids = $pdo->prepare("SELECT id FROM crecer_contenido WHERE marca_id=? AND estado='fallido' ORDER BY id DESC LIMIT 15");
    $ids->execute([$rmid]); $ids = $ids->fetchAll(PDO::FETCH_COLUMN);
    $ok=0; $no=0;
    foreach ($ids as $cid) { try { $r = publicar_pieza($pdo, (int)$cid); if (!empty($r['ok'])) $ok++; else $no++; } catch (Throwable $e) { $no++; } }
    $flash = ['ok', "Reintenté {$ok} OK · {$no} siguen fallando" . ($no>0?' (probable problema de conexión del cliente)':'') . '.'];
}
$csrf = csrf_token();

// ── Posts que NO pudieron publicar, por cliente ──
$fallos = $pdo->query("SELECT m.id, m.nombre_negocio, COUNT(*) n,
        SUBSTRING(MAX(CONCAT(LPAD(c.id,10,'0'),'||',COALESCE(c.pub_error,''))), 13) AS err
     FROM crecer_contenido c JOIN crecer_marca m ON m.id=c.marca_id
     WHERE c.estado='fallido' GROUP BY m.id ORDER BY n DESC")->fetchAll(PDO::FETCH_ASSOC);

// ── Conexiones de Meta con problema ──
$conx = $pdo->query("SELECT m.id, m.nombre_negocio, x.estado, x.ultimo_error, x.token_expira, x.fb_page_id, x.ig_user_id
     FROM crecer_conexiones x JOIN crecer_marca m ON m.id=x.marca_id
     WHERE x.estado<>'activa'
        OR (x.token_expira IS NOT NULL AND x.token_expira < NOW())
        OR (x.ig_user_id IS NOT NULL AND (x.fb_page_id IS NULL OR x.fb_page_id=''))
     ORDER BY m.nombre_negocio")->fetchAll(PDO::FETCH_ASSOC);

$total = count($fallos) + count($conx);
$op_active = 'problemas';
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Problemas — Operaciones</title>
<link href="/crecer/assets/encuentralo-ui.css?v=20" rel="stylesheet">
<style>
  *{box-sizing:border-box} body{background:var(--crema,#fbf6ee);color:var(--tinta,#1b1622);font-family:'Plus Jakarta Sans',system-ui,sans-serif;margin:0}
  .wrap{max-width:900px;margin:0 auto;padding:20px 18px 70px}
  h1{font-family:'Poppins',sans-serif;text-transform:uppercase;font-size:26px;margin:8px 0 4px}
  .sub{color:var(--muted);font-size:13.5px;margin:0 0 16px}
  .card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:16px 18px;margin-bottom:14px;box-shadow:var(--shadow-sm)}
  .card h2{font-family:'Poppins',sans-serif;text-transform:uppercase;font-size:15px;letter-spacing:.03em;margin:0 0 12px;display:flex;align-items:center;gap:8px}
  .prob{display:flex;align-items:flex-start;gap:12px;padding:12px 0;border-bottom:1px solid var(--line)}.prob:last-child{border-bottom:0}
  .prob .info{flex:1;min-width:0}
  .prob .neg{font-weight:800;font-size:14px;color:var(--tinta)}
  .prob .err{color:#b42318;font-size:12.5px;margin-top:3px;word-break:break-word;line-height:1.4}
  .prob .acts{flex:none;display:flex;flex-direction:column;gap:6px;align-items:flex-end}
  .btn{border:0;cursor:pointer;font-family:inherit;font-weight:800;font-size:12px;color:#fff;background:var(--palma,#16b86a);padding:7px 12px;border-radius:9px;text-decoration:none;white-space:nowrap;text-align:center}
  .btn.ghost{background:#fff;color:var(--tinta);border:1.5px solid var(--line)}
  .pill{display:inline-block;font-size:10.5px;font-weight:800;border-radius:99px;padding:2px 9px}
  .pill.n{background:#fdeaea;color:#b3123b}.pill.w{background:#fff4d6;color:#8a5a00}
  .flash{background:#e6f6ee;border:1px solid #b9eccf;color:#0d7a44;border-radius:12px;padding:11px 15px;font-weight:700;font-size:13.5px;margin-bottom:14px}
  .empty{text-align:center;color:#0d7a44;font-weight:700;padding:26px;font-size:15px}
</style></head><body>
<?php require __DIR__ . '/_ops_top.php'; ?>
<div class="wrap">
  <h1>Problemas <?php if ($total): ?><span class="pill n" style="font-size:14px;vertical-align:middle"><?= $total ?></span><?php endif; ?></h1>
  <p class="sub">Clientes que necesitan tu atención — qué pasa y cómo resolverlo.</p>
  <?php if ($flash): ?><div class="flash"><?= $h($flash[1]) ?></div><?php endif; ?>

  <?php if (!$total): ?>
    <div class="card"><div class="empty">✓ Todo en orden — ningún cliente con problemas ahora mismo.</div></div>
  <?php endif; ?>

  <?php if ($fallos): ?>
  <div class="card">
    <h2>🚫 No pudieron publicar (<?= count($fallos) ?>)</h2>
    <?php foreach ($fallos as $f): ?>
      <div class="prob">
        <div class="info">
          <div class="neg"><?= $h($f['nombre_negocio']) ?> <span class="pill n"><?= (int)$f['n'] ?> fallido<?= $f['n']==1?'':'s' ?></span></div>
          <?php if (!empty($f['err'])): ?><div class="err"><?= $h(mb_substr((string)$f['err'],0,200)) ?></div>
          <?php else: ?><div class="err" style="color:var(--muted)">Sin detalle del error — abre el diagnóstico.</div><?php endif; ?>
        </div>
        <div class="acts">
          <form method="post" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='…'">
            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="accion" value="retry_all"><input type="hidden" name="marca_id" value="<?= (int)$f['id'] ?>">
            <button type="submit" class="btn">Reintentar todos</button>
          </form>
          <a class="btn ghost" href="/crecer/panel/admin_cliente.php?marca=<?= (int)$f['id'] ?>">Diagnóstico</a>
        </div>
      </div>
    <?php endforeach; ?>
    <p class="sub" style="margin:10px 0 0">Si al reintentar siguen fallando por <b>permisos/token/página</b>, el cliente debe <b>reconectar sus redes</b> (tú no puedes por él).</p>
  </div>
  <?php endif; ?>

  <?php if ($conx): ?>
  <div class="card">
    <h2>🔌 Conexiones con problema (<?= count($conx) ?>)</h2>
    <?php foreach ($conx as $c):
      $razon = [];
      if (($c['estado'] ?? '')!=='activa') $razon[] = 'conexión '.$c['estado'];
      if (!empty($c['token_expira']) && strtotime($c['token_expira']) < time()) $razon[] = 'token vencido';
      if (!empty($c['ig_user_id']) && empty($c['fb_page_id'])) $razon[] = 'falta Página de Facebook (por eso solo sale en IG)';
      if (!empty($c['ultimo_error']) && !$razon) $razon[] = 'error guardado';
    ?>
      <div class="prob">
        <div class="info">
          <div class="neg"><?= $h($c['nombre_negocio']) ?> <span class="pill w"><?= $h(implode(' · ', $razon) ?: 'revisar') ?></span></div>
          <?php if (!empty($c['ultimo_error'])): ?><div class="err"><?= $h(mb_substr((string)$c['ultimo_error'],0,180)) ?></div><?php endif; ?>
          <div class="err" style="color:var(--muted)">Lo arregla el cliente: reconectar en Configuración → Conectar redes (aceptar todo + elegir su Página).</div>
        </div>
        <div class="acts"><a class="btn ghost" href="/crecer/panel/admin_cliente.php?marca=<?= (int)$c['id'] ?>">Diagnóstico</a></div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
</body></html>
