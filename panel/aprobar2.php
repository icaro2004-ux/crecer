<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Contenido / Aprobar (dentro del shell)
//  panel/aprobar2.php
// ============================================================
require __DIR__ . '/../includes/db.php';

$marca_id = (int)($_GET['marca'] ?? 1);

// ── Acción POST (PRG) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int)($_POST['id'] ?? 0);
    $accion = $_POST['accion'] ?? '';
    $nuevo  = ['aprobar'=>'aprobado','rechazar'=>'rechazado','reabrir'=>'borrador'][$accion] ?? null;
    if ($id && $nuevo) {
        $pdo->prepare("UPDATE crecer_contenido SET estado=?, updated_at=NOW() WHERE id=? AND marca_id=?")
            ->execute([$nuevo, $id, $marca_id]);
    }
    header('Location: ' . $_SERVER['REQUEST_URI']); exit;
}

$m = $pdo->prepare("SELECT * FROM crecer_marca WHERE id = ?");
$m->execute([$marca_id]);
$marca = $m->fetch();
if (!$marca) { http_response_code(404); exit('Negocio no encontrado.'); }

$piezas = $pdo->prepare(
    "SELECT c.* FROM crecer_contenido c
       JOIN crecer_calendario cal ON cal.id = c.calendario_id
      WHERE c.marca_id = ?
        AND cal.id = (SELECT id FROM crecer_calendario WHERE marca_id = ? ORDER BY anio DESC, mes DESC LIMIT 1)
      ORDER BY c.fecha_programada");
$piezas->execute([$marca_id, $marca_id]);
$piezas = $piezas->fetchAll();

$cuenta = ['borrador'=>0,'aprobado'=>0,'rechazado'=>0,'publicado'=>0];
foreach ($piezas as $p) { $cuenta[$p['estado']] = ($cuenta[$p['estado']] ?? 0) + 1; }
$total  = count($piezas);
$listos = $cuenta['aprobado'] + $cuenta['publicado'];
$pct    = $total ? round($listos / $total * 100) : 0;

$plat = ['instagram'=>['Instagram',''], 'facebook'=>['Facebook','fb'], 'whatsapp'=>['WhatsApp','']];
$pill = ['borrador'=>['Pendiente','wait'],'aprobado'=>['Aprobado','ok'],'rechazado'=>['Rechazado','no'],'publicado'=>['Publicado','pub']];
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$active = 'contenido';
$page_title = 'Contenido';
require __DIR__ . '/_shell.php';
?>
<style>
  .feedwrap{max-width:600px}
  .cprogress{max-width:600px;margin-top:16px}
  .cprogress .row{display:flex;align-items:baseline;justify-content:space-between;margin-bottom:8px}
  .cprogress .count{font-family:var(--font-display);font-weight:700;font-size:15px}
  .cprogress .count b{color:var(--terracota)}
  .cprogress .pending{font-size:13px;color:var(--muted)}
  .feedwrap .post{margin-top:14px}
</style>

<h1 class="page-h">Tu contenido del mes</h1>
<p class="page-sub">La IA lo preparó. Aprueba lo que te guste — tú tienes la última palabra. ✋</p>

<?php if ($total): ?>
  <div class="cprogress">
    <div class="row">
      <span class="count"><b><?= $listos ?></b> de <?= $total ?> listos para publicar</span>
      <span class="pending"><?= $cuenta['borrador'] ?> por revisar</span>
    </div>
    <div class="track"><i style="width:<?= $pct ?>%"></i></div>
  </div>
<?php endif; ?>

<div class="feedwrap">
  <?php if (!$total): ?>
    <div class="empty">
      <div class="big">🌱</div>
      <p style="margin-bottom:18px">Todavía no hay contenido para este negocio.</p>
      <?php if (!empty($_GET['err'])): ?>
        <p style="color:var(--noo-ink);font-size:13px;margin-bottom:14px">No se pudo generar ahora (<?= $h($_GET['err']) ?>). Intenta de nuevo en un minuto.</p>
      <?php endif; ?>
      <form method="post" action="/crecer/panel/generar.php"
            onsubmit="var b=this.querySelector('button');b.textContent='✨ Creando tu mes…';b.disabled=true;">
        <input type="hidden" name="marca" value="<?= $marca_id ?>">
        <button type="submit" style="border:0;cursor:pointer;font-family:inherit;font-weight:800;font-size:15px;color:#fff;background:linear-gradient(135deg,var(--coral),var(--magenta));padding:15px 26px;border-radius:99px;box-shadow:0 12px 28px rgba(255,43,133,.3)">✨ Que la IA prepare mi primer mes</button>
      </form>
      <p style="color:var(--muted);font-size:12.5px;margin-top:12px">Tarda un minutito — la IA está creando tu contenido.</p>
    </div>
  <?php endif; ?>

  <?php foreach ($piezas as $p):
    [$pl_label,$pl_cls] = $plat[$p['plataforma']] ?? [ucfirst($p['plataforma']),''];
    [$pi_label,$pi_cls] = $pill[$p['estado']] ?? ['—','wait'];
    $done = in_array($p['estado'],['aprobado','rechazado','publicado'],true);
    $fecha = date('d/m', strtotime($p['fecha_programada'] ?: 'now'));
  ?>
    <article class="post <?= $done?'done':'' ?>">
      <div class="post-head">
        <span class="chip <?= $pl_cls ?>"><span class="ico"></span><?= $h($pl_label) ?></span>
        <span class="chip"><?= $h($p['tipo']) ?></span>
        <span class="pill <?= $pi_cls ?>"><?= $pi_label ?></span>
        <span class="date"><?= $fecha ?></span>
      </div>
      <div class="caption"><?= $h($p['caption']) ?></div>
      <div class="post-actions">
        <?php if ($p['estado']==='borrador'): ?>
          <form method="post"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="btn btn-ok" name="accion" value="aprobar">✓ Aprobar</button></form>
          <form method="post"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="btn btn-no" name="accion" value="rechazar">Rechazar</button></form>
        <?php else: ?>
          <form method="post"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="btn btn-ghost" name="accion" value="reabrir">↺ Volver a revisar</button></form>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
</div>

<?php require __DIR__ . '/_shell_foot.php'; ?>
