<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Actividad del corillo (vista CLIENTE)
//  panel/actividad.php?marca=<id>
//
//  Lo que el corillo hizo, en lenguaje HUMANO. Sin tokens, costos,
//  modelos ni métricas de sistema (eso vive en evidencia.php, que
//  es la evidencia técnica protegida para admin/jurado).
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/suscripcion.php';
requiere_login();
require_once __DIR__ . '/../includes/panel_guard.php';
requiere_suscripcion($pdo, isset($_GET['marca']) ? (int)$_GET['marca'] : null);
$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }
$marca_id = (int)$marca['id'];
$BASE = '/crecer/panel';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$_meses_ab = [1=>'ene',2=>'feb',3=>'mar',4=>'abr',5=>'may',6=>'jun',7=>'jul',8=>'ago',9=>'sep',10=>'oct',11=>'nov',12=>'dic'];
$ago = function($ts) use ($_meses_ab){
    $d = strtotime((string)$ts); if (!$d) return '';
    $s = time() - $d;
    if ($s < 60)    return 'hace un momento';
    if ($s < 3600)  return 'hace ' . floor($s/60) . ' min';
    if ($d >= strtotime('today'))     return 'hoy ' . date('g:i A', $d);
    if ($d >= strtotime('yesterday')) return 'ayer ' . date('g:i A', $d);
    if (date('Y', $d) === date('Y'))  return (int)date('j', $d) . ' ' . $_meses_ab[(int)date('n', $d)];
    return date('d/m/y', $d);
};

// Mapa HUMANO (sin tecnicismos): icono · nombre · qué hizo
$humano = [
  'planificador'=>['estratega','La Estratega','cuadró tu plan de contenido'],
  'creador'     =>['creativa','La Creativa','escribió un post nuevo'],
  'diseñador'   =>['disenador','El Diseñador','preparó un arte'],
  'intake'      =>['estratega','La Estratega','aprendió de tu negocio'],
  'aprendiz'    =>['creativa','La Creativa','aprendió tu vocabulario'],
  'editor'      =>['creativa','La Creativa','pulió un texto'],
  'analitica'   =>['analista','El Analista','revisó cómo va tu contenido'],
  'estratega'   =>['estratega','La Estratega','te dio una recomendación'],
  'asistente'   =>['bolt','El Copiloto','respondió tus dudas'],
  'retencion'   =>['estratega','La Estratega','preparó un mensaje para un cliente'],
];
$hf = fn($a) => $humano[$a] ?? ['bolt','El Corillo','metió mano por tu negocio'];

// Cuántas cosas ha hecho el corillo por este negocio (acciones ok).
$total = (int)$pdo->query("SELECT COUNT(*) FROM crecer_ia_log WHERE marca_id={$marca_id} AND estado='ok' AND agente<>'kernel'")->fetchColumn();

// Timeline humano (solo acciones reconocidas, sin metadatos técnicos).
// Excluye agente='kernel' (decisiones internas del orquestador; siguen en la
// tabla para admin/auditoría, pero no son trabajo visible del corillo).
$ev = $pdo->prepare("SELECT agente, created_at FROM crecer_ia_log WHERE marca_id=? AND estado='ok' AND agente<>'kernel' ORDER BY id DESC LIMIT 60");
$ev->execute([$marca_id]); $eventos = $ev->fetchAll();

// Publicaciones a redes, en términos humanos.
$publicaciones = [];
try {
    $pq = $pdo->prepare("SELECT plataforma, estado, permalink, created_at FROM crecer_publicaciones WHERE marca_id=? ORDER BY id DESC LIMIT 10");
    $pq->execute([$marca_id]); $publicaciones = $pq->fetchAll();
} catch (Throwable $e) {}

$ICON = '/crecer/assets/icons';
$active = 'inicio';
$page_title = 'Actividad del corillo';
$guia = ['key'=>'actividad','agente'=>'bolt','titulo'=>'La actividad de tu corillo',
  'intro'=>'Todo lo que la IA ha hecho por tu negocio, en cristiano.',
  'pasos'=>[
    ['sparkles','Cada línea es algo real que el corillo hizo por ti.'],
    ['check-circle','Lo que queda listo lo apruebas en "Contenido".'],
  ]];
require __DIR__ . '/_shell.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@600;700&display=swap" rel="stylesheet">
<style>
  .content{max-width:760px}
  .ac-h1{font-family:'Oswald',sans-serif;font-weight:700;text-transform:uppercase;letter-spacing:.3px;font-size:clamp(24px,4vw,34px);margin:0;color:var(--tinta)}
  .ac-lede{color:var(--muted);font-size:14.5px;margin:6px 0 18px;max-width:58ch}
  .ac-count{display:flex;align-items:center;gap:12px;background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff;border-radius:16px;padding:18px 22px;margin-bottom:20px}
  .ac-count b{font-family:'Oswald',sans-serif;font-weight:700;font-size:34px;line-height:1}
  .ac-count span{font-size:14.5px;opacity:.95}
  .ac-card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px 20px;margin-bottom:16px;box-shadow:var(--shadow-sm)}
  .ac-card h2{font-family:'Oswald',sans-serif;font-weight:700;text-transform:uppercase;font-size:15px;letter-spacing:.3px;margin:0 0 14px;display:flex;align-items:center;gap:8px}
  .ac-row{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--line)}
  .ac-row:last-child{border-bottom:0}
  .ac-row img.ic{width:26px;height:26px;flex:none}
  .ac-row .m{font-size:14px;color:#473b46;line-height:1.35;flex:1}
  .ac-row .m b{color:var(--tinta)}
  .ac-row .t{font-size:12px;color:var(--muted);font-weight:700;flex:none}
  .ac-empty{color:var(--muted);font-size:13.5px}
  .ac-pub .e{font-size:20px;flex:none}
</style>

<h1 class="ac-h1">Lo que hizo el corillo</h1>
<p class="ac-lede">Todo lo que la IA ha hecho por <b><?= $h($marca['nombre_negocio']) ?></b>, en cristiano. Sin tecnicismos.</p>

<div class="ac-count">
  <b><?= number_format($total) ?></b>
  <span>cosa<?= $total==1?'':'s' ?> que el corillo ha hecho por tu negocio</span>
</div>

<?php if ($publicaciones): ?>
<div class="ac-card">
  <h2><?= ico('send') ?> Publicaciones a tus redes</h2>
  <?php foreach ($publicaciones as $pb): ?>
    <div class="ac-row ac-pub">
      <span class="e"><?= $pb['plataforma']==='instagram'? ico('instagram') : ico('facebook') ?></span>
      <span class="m"><b><?= $h(ucfirst($pb['plataforma'])) ?></b> · <?= $pb['estado']==='ok'?'publicado':'no se pudo publicar' ?></span>
      <?php if (!empty($pb['permalink'])): ?><a class="t" style="color:var(--terracota);text-decoration:none" href="<?= $h($pb['permalink']) ?>" target="_blank" rel="noopener">ver post →</a>
      <?php else: ?><span class="t"><?= $h($ago($pb['created_at'])) ?></span><?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="ac-card">
  <h2><?= ico('list') ?> Línea de tiempo</h2>
  <?php if (!$eventos): ?>
    <p class="ac-empty">El corillo está listo pa' arrancar. Pídele contenido y empieza a meter mano.</p>
  <?php else: foreach ($eventos as $e): [$fic,$fnm,$fmsg] = $hf($e['agente']); ?>
    <div class="ac-row">
      <img class="ic" src="<?= $ICON ?>/<?= $h($fic) ?>.svg" alt="">
      <span class="m"><b><?= $h($fnm) ?></b> <?= $h($fmsg) ?></span>
      <span class="t"><?= $h($ago($e['created_at'])) ?></span>
    </div>
  <?php endforeach; endif; ?>
</div>

<?php require __DIR__ . '/_shell_foot.php'; ?>
