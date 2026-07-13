<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Placeholder "Próximamente" (módulos)
//  panel/pronto.php?s=<seccion>&marca=<id>
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
requiere_login();
$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }
$marca_id = (int)$marca['id'];

$secs = [
  'marca'     => ['🎨','Marca','Tu logo, colores y portada — generados por IA y listos para usar en todos lados.','crecer'],
  'clientela' => ['👥','Clientela','Tus clientes y su historial, armados solitos desde tus órdenes. Para que les des seguimiento.','crecer'],
  'cuentas'   => ['💵','Cuentas','Ingresos, gastos y ganancia clara del mes. Sin enredos, nada de planillas.','despegar'],
  'analitica' => ['📊','Analítica','Qué se vende más, qué post jala más, y cómo crece tu negocio mes a mes.','despegar'],
  'config'    => ['⚙️','Configuración','Edita tu negocio, tus datos, tu plan y tus redes.','crecer'],
];
$s = $_GET['s'] ?? 'config';
// $plan_req = plan que EXIGE esta sección (no confundir con el plan del
// usuario, que lo calcula _shell.php en $plan).
[$ic,$titulo,$desc,$plan_req] = $secs[$s] ?? $secs['config'];

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$active = $s;
$page_title = $titulo;
require __DIR__ . '/_shell.php';

// ¿Hay que ofrecerle subir de plan? Solo si la sección pide despegar Y el
// usuario NO lo tiene. Si ya lo paga, ve "próximamente" normal (no upsell).
$necesita_upgrade = ($plan_req === 'despegar') && !marca_puede($plan, 'analitica');
?>
<style>
  .soon-box{max-width:560px;background:var(--card);border:1px solid var(--line);border-radius:var(--r-xl);
    padding:40px 32px;text-align:center;box-shadow:var(--shadow-sm);margin-top:10px}
  .soon-box .e{font-size:52px}
  .soon-box h1{font-family:var(--font-display);font-weight:800;font-size:28px;letter-spacing:-.025em;margin:10px 0 6px}
  .soon-box p{color:var(--muted);font-size:16px;max-width:42ch;margin:0 auto}
  .badge-soon{display:inline-block;margin-top:18px;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;
    color:var(--amber-ink);background:var(--amber-bg);padding:6px 14px;border-radius:99px}
  .badge-up{color:#fff;background:linear-gradient(135deg,var(--coral),var(--magenta))}
  .soon-box .up{display:inline-block;margin-top:22px;background:linear-gradient(135deg,var(--coral),var(--magenta));
    color:#fff;font-weight:800;padding:13px 26px;border-radius:99px;text-decoration:none}
  .soon-box .back{display:block;margin-top:16px;color:var(--muted);font-weight:700;text-decoration:none;font-size:14px}
</style>

<div class="soon-box">
  <div class="e"><?= $ic ?></div>
  <h1><?= $h($titulo) ?></h1>
  <p><?= $h($desc) ?></p>
  <?php if ($necesita_upgrade): ?>
    <div class="badge-soon badge-up">🚀 Plan Despegar</div>
    <p style="margin-top:14px;font-size:14px">Esta función viene con el plan <b>Despegar</b>. Súbete para activarla.</p>
    <a class="up" href="/crecer/panel/precios.php?marca=<?= $marca_id ?>">Ver plan Despegar →</a>
  <?php elseif ($plan_req === 'despegar'): ?>
    <div class="badge-soon badge-up">🚀 Plan Despegar</div>
    <p style="margin-top:14px;font-size:14px">Ya tienes el plan <b>Despegar</b> 🎉 Esta función está en construcción; te avisamos apenas esté lista.</p>
  <?php else: ?>
    <div class="badge-soon">🔧 Próximamente</div>
    <p style="margin-top:14px;font-size:14px">Estamos construyéndola. Te avisamos apenas esté lista.</p>
  <?php endif; ?>
  <a class="back" href="/crecer/panel/index.php?marca=<?= $marca_id ?>">← Volver al inicio</a>
</div>

<?php require __DIR__ . '/_shell_foot.php'; ?>
