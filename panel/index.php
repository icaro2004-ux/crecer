<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Dashboard (home del panel)
//  panel/index.php  ·  usa el shell compartido
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
requiere_login();
$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/intake.php'); exit; }
$marca_id = (int)$marca['id'];

// Métricas del calendario más reciente
$cal = $pdo->prepare("SELECT id FROM crecer_calendario WHERE marca_id=? ORDER BY anio DESC, mes DESC LIMIT 1");
$cal->execute([$marca_id]);
$cal_id = (int)$cal->fetchColumn();
$pend = 0; $aprob = 0; $proximas = [];
if ($cal_id) {
    $c = $pdo->prepare("SELECT estado, COUNT(*) n FROM crecer_contenido WHERE calendario_id=? GROUP BY estado");
    $c->execute([$cal_id]);
    foreach ($c->fetchAll() as $r) { if ($r['estado']==='borrador') $pend=(int)$r['n']; if ($r['estado']==='aprobado') $aprob=(int)$r['n']; }
    $p = $pdo->prepare("SELECT plataforma, fecha_programada, caption FROM crecer_contenido
        WHERE calendario_id=? AND estado IN ('aprobado','publicado') ORDER BY fecha_programada LIMIT 3");
    $p->execute([$cal_id]);
    $proximas = $p->fetchAll();
}
// Métricas de órdenes
$ord = ['abiertas'=>0,'mes'=>0.0];
$o = $pdo->prepare("SELECT COUNT(*) FROM crecer_ordenes WHERE marca_id=? AND estado IN ('recibida','en_proceso')");
$o->execute([$marca_id]); $ord['abiertas'] = (int)$o->fetchColumn();
$o2 = $pdo->prepare("SELECT COALESCE(SUM(monto),0) FROM crecer_ordenes WHERE marca_id=? AND estado='completada' AND MONTH(updated_at)=MONTH(NOW()) AND YEAR(updated_at)=YEAR(NOW())");
$o2->execute([$marca_id]); $ord['mes'] = (float)$o2->fetchColumn();

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$emoji_plat = ['instagram'=>'📸','facebook'=>'👍','whatsapp'=>'💬'];
$BASE = '/crecer/panel';

$active = 'inicio';
$page_title = 'Inicio';
require __DIR__ . '/_shell.php';
?>
<style>
  .hello{font-family:var(--font-display);font-weight:800;font-size:clamp(26px,4vw,36px);letter-spacing:-.025em}
  .subhi{color:var(--muted);font-size:15px;margin-top:4px}
  .lvltag{display:inline-block;margin-top:12px;font-size:12.5px;font-weight:700;color:var(--palma);background:var(--okk-bg);padding:6px 13px;border-radius:99px}
  .ct{font-size:13px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
  .big{font-family:var(--font-display);font-weight:800;font-size:42px;line-height:1;margin:8px 0 2px;letter-spacing:-.03em}
  .big.pink{color:var(--terracota)}
  .lk{display:inline-block;margin-top:14px;font-weight:800;font-size:14px;color:var(--terracota);text-decoration:none}
  .soon{font-size:13px;color:var(--muted);margin-top:6px}
  .ctab{display:inline-block;margin-top:14px;background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff;font-weight:800;font-size:14px;padding:11px 20px;border-radius:99px;text-decoration:none}
  .prox{display:flex;flex-direction:column;gap:10px;margin-top:14px}
  .prox .it{display:flex;gap:10px;align-items:center;font-size:14px;padding:10px 12px;background:var(--crema);border-radius:12px}
  .prox .it .d{font-weight:800;color:var(--terracota);font-size:13px;flex:none}
  .prox .it .tx{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .pcard.feature{grid-column:1/-1;background:linear-gradient(135deg,rgba(255,107,61,.07),rgba(255,43,133,.07))}
</style>

<h1 class="hello">¡Hola, <?= $h($marca['nombre_negocio']) ?>! 👋</h1>
<p class="subhi">Este es tu centro de mando. Aquí la IA trabaja y tú decides.</p>
<div class="lvltag">🌿 Plan Crecer · Intermedio</div>

<div class="grid g2">
  <div class="pcard">
    <div class="ct">Por aprobar</div>
    <div class="big pink"><?= $pend ?></div>
    <div class="ct" style="text-transform:none;color:var(--muted);font-weight:600">pieza<?= $pend==1?'':'s' ?> esperando tu OK</div>
    <?php if ($pend > 0): ?>
      <a class="lk" href="<?= $BASE ?>/aprobar2.php?marca=<?= $marca_id ?>">Revisar ahora →</a>
    <?php elseif (!$cal_id): ?>
      <div><a class="ctab" href="<?= $BASE ?>/aprobar2.php?marca=<?= $marca_id ?>">✨ Generar mi primer mes</a></div>
    <?php else: ?>
      <div class="soon">✅ Todo al día</div>
    <?php endif; ?>
  </div>

  <div class="pcard">
    <div class="ct">Listas para publicar</div>
    <div class="big"><?= $aprob ?></div>
    <div class="ct" style="text-transform:none;color:var(--muted);font-weight:600">aprobadas este mes</div>
    <?php if ($proximas): ?>
      <div class="prox">
        <?php foreach ($proximas as $px): ?>
          <div class="it"><span class="d"><?= date('d/m', strtotime($px['fecha_programada'])) ?></span>
            <span><?= $emoji_plat[$px['plataforma']] ?? '' ?></span>
            <span class="tx"><?= $h(mb_substr($px['caption'],0,40)) ?>…</span></div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="soon">Aprueba contenido y aquí verás tu calendario.</div>
    <?php endif; ?>
  </div>

  <div class="pcard">
    <div class="ct">Órdenes abiertas</div>
    <div class="big" style="<?= $ord['abiertas']?'':'color:var(--muted)' ?>"><?= $ord['abiertas'] ?: '—' ?></div>
    <?php if ($ord['abiertas']): ?>
      <a class="lk" href="<?= $BASE ?>/ordenes.php?marca=<?= $marca_id ?>">Ver órdenes →</a>
    <?php else: ?>
      <a class="lk" href="<?= $BASE ?>/ordenes.php?marca=<?= $marca_id ?>">Recibir una orden →</a>
    <?php endif; ?>
  </div>

  <div class="pcard">
    <div class="ct">Ingresos del mes</div>
    <div class="big" style="<?= $ord['mes']>0?'':'color:var(--muted)' ?>"><?= $ord['mes']>0 ? '$'.number_format($ord['mes'],0) : '—' ?></div>
    <div class="soon">De tus órdenes completadas. Detalle full con <b>Despegar</b>.</div>
  </div>

  <div class="pcard feature">
    <div class="ct">🖼️ Gráficas con IA</div>
    <div style="font-family:var(--font-display);font-weight:800;font-size:21px;margin:6px 0 4px;letter-spacing:-.02em">Convierte tus fotos en posts</div>
    <div class="soon">Sube las fotos de tu negocio y la IA las vuelve posts profesionales, con tu producto real.</div>
    <a class="ctab" href="<?= $BASE ?>/graficas.php?marca=<?= $marca_id ?>">🖼️ Crear gráficas con IA →</a>
  </div>
</div>

<?php require __DIR__ . '/_shell_foot.php'; ?>
