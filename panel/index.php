<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Inicio "Lanzador" (¿Qué quieres hacer hoy?)
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
$pend = 0; $aprob = 0;
if ($cal_id) {
    $c = $pdo->prepare("SELECT estado, COUNT(*) n FROM crecer_contenido WHERE calendario_id=? GROUP BY estado");
    $c->execute([$cal_id]);
    foreach ($c->fetchAll() as $r) { if ($r['estado']==='borrador') $pend=(int)$r['n']; if ($r['estado']==='aprobado') $aprob=(int)$r['n']; }
}
// Órdenes
$ord = ['abiertas'=>0,'mes'=>0.0];
$o = $pdo->prepare("SELECT COUNT(*) FROM crecer_ordenes WHERE marca_id=? AND estado IN ('recibida','en_proceso')");
$o->execute([$marca_id]); $ord['abiertas'] = (int)$o->fetchColumn();
$o2 = $pdo->prepare("SELECT COALESCE(SUM(monto),0) FROM crecer_ordenes WHERE marca_id=? AND estado='completada' AND MONTH(updated_at)=MONTH(NOW()) AND YEAR(updated_at)=YEAR(NOW())");
$o2->execute([$marca_id]); $ord['mes'] = (float)$o2->fetchColumn();

$cnt_artes = (int)$pdo->query("SELECT COUNT(*) FROM crecer_graficas WHERE marca_id={$marca_id}")->fetchColumn();
$tiene_logo = (int)$pdo->query("SELECT COUNT(*) FROM crecer_logos WHERE marca_id={$marca_id}")->fetchColumn() > 0;

// Activity feed — actividad REAL reciente del corillo (de crecer_ia_log)
$feed_map = [
  'planificador'=>['estratega','El Estratega','cuadró el plan del mes'],
  'creador'     =>['creativa','La Creativa','escribió contenido nuevo'],
  'diseñador'   =>['disenador','El Diseñador','preparó un arte'],
  'analitica'   =>['analista','El Analista','revisó tus números'],
  'retencion'   =>['vendedor','El Vendedor','le escribió a un cliente'],
  'intake'      =>['estratega','El Estratega','aprendió de tu negocio'],
  'asistente'   =>['creativa','El Asistente','resolvió una duda'],
  'aprendiz'    =>['creativa','La Creativa','aprendió tu vocabulario'],
  'editor'      =>['creativa','La Creativa','pulió un texto'],
];
$fq = $pdo->prepare("SELECT agente, created_at FROM crecer_ia_log WHERE marca_id=? AND estado='ok' ORDER BY id DESC LIMIT 5");
$fq->execute([$marca_id]);
$feed = $fq->fetchAll();

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$ICON = '/crecer/assets/icons';
$BASE = '/crecer/panel';

$active = 'inicio';
$page_title = 'Inicio';
$guia = ['key'=>'inicio','agente'=>'sparkles','titulo'=>'Tu punto de partida',
  'intro'=>'Desde aquí haces todo: pídele al corillo, revisa lo que te dejó listo y mira tu negocio.',
  'pasos'=>[
    ['sparkles','Dale a "Crear contenido" para pedirle un post al corillo.'],
    ['check-circle','"Revisar y aprobar" es lo que el corillo te dejó listo.'],
    ['calendar','En "Calendario" ves tu plan del mes.'],
  ]];
require __DIR__ . '/_shell.php';   // define $plan / $plan_etq

// Próximo paso del corillo (coach dinámico, según tu estado real)
if     ($pend > 0)      $paso = [$pend.' post'.($pend==1?'':'s').' esperando tu OK — apruébalos', 'aprobar2.php?tab=pendientes', 'check-circle'];
elseif (!$tiene_logo)   $paso = ['Crea tu logo con El Diseñador', 'marca.php', 'palette'];
elseif ($cnt_artes==0)  $paso = ['Sube una foto para tu primer arte', 'graficas.php', 'camera'];
elseif ($ord['abiertas']>0) $paso = ['Tienes '.$ord['abiertas'].' órden'.($ord['abiertas']==1?'':'es').' abierta'.($ord['abiertas']==1?'':'s'), 'ordenes.php', 'package'];
else                    $paso = ['Vas bien — pídele al corillo más contenido', 'aprobar2.php', 'bolt'];

// Cards-acción (el menú, pero como cards útiles). 'n' = contador opcional.
$acciones = [
  ['feature'=>true,'c'=>'#ff2d6f','icon'=>'sparkles','t'=>'Crear contenido','s'=>"Pídele un post al corillo",'hr'=>"aprobar2.php"],
  ['c'=>'#c98a00','icon'=>'check-circle','t'=>'Revisar y aprobar','s'=>'Lo que el corillo te dejó listo','hr'=>"aprobar2.php?tab=pendientes",'n'=>$pend],
  ['c'=>'#7928ff','icon'=>'calendar','t'=>'Calendario','s'=>'Tu plan del mes','hr'=>"calendario.php"],
  ['c'=>'#ff7900','icon'=>'image','t'=>'Gráficas','s'=>'Tus artes y fotos','hr'=>"graficas.php"],
  ['c'=>'#00a5a8','icon'=>'palette','t'=>'Mi marca','s'=>'Voz, logo y colores','hr'=>"marca.php"],
  ['c'=>'#1e69ff','icon'=>'package','t'=>'Órdenes & Agenda','s'=>'Citas y pedidos','hr'=>"ordenes.php",'n'=>$ord['abiertas']],
  ['c'=>'#33b617','icon'=>'users','t'=>'Clientela','s'=>'Tus clientes','hr'=>"clientela.php"],
  ['c'=>'#0d7a44','icon'=>'chat','t'=>'Soporte','s'=>'Te montamos por WhatsApp','hr'=>"soporte.php"],
];
?>
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&display=swap" rel="stylesheet">
<style>
  .content{max-width:1080px}
  .lx-hi h1{font-family:'Oswald',sans-serif;font-weight:700;text-transform:uppercase;font-size:clamp(30px,5vw,46px);line-height:1.02;letter-spacing:.4px;margin:0;color:var(--tinta)}
  .lx-hi h1 span{color:var(--terracota)}
  .lx-hi .sub{font-size:16px;color:var(--muted);margin:8px 0 0}
  .lx-badge{display:inline-flex;align-items:center;gap:6px;margin-top:12px;font-family:'Oswald',sans-serif;font-weight:700;text-transform:uppercase;letter-spacing:.05em;font-size:12px;background:var(--gold,#ffd34e);color:#3a2a00;padding:6px 12px;border-radius:9px}
  .lx-badge svg{width:15px;height:15px}

  /* Estado glanceable */
  .lx-status{display:flex;flex-wrap:wrap;gap:10px;margin:18px 0 0}
  .lx-chip{display:inline-flex;align-items:baseline;gap:7px;background:var(--card);border:1px solid var(--line);border-radius:999px;padding:8px 15px;text-decoration:none;color:var(--tinta);box-shadow:var(--shadow-sm);font-size:13.5px}
  .lx-chip b{font-family:'Oswald',sans-serif;font-weight:700;font-size:18px}
  .lx-chip.hot{border-color:transparent;background:linear-gradient(120deg,var(--coral),var(--magenta));color:#fff}
  .lx-chip span{color:var(--muted)}.lx-chip.hot span{color:rgba(255,255,255,.9)}

  /* Coach / upsell */
  .lx-coach{display:flex;align-items:center;gap:13px;margin:18px 0 0;padding:14px 18px;border-radius:var(--r-lg);
    background:linear-gradient(135deg,color-mix(in srgb,var(--teal) 12%,#fff),var(--card));border:1.5px solid color-mix(in srgb,var(--teal) 26%,#fff);
    text-decoration:none;color:var(--tinta);transition:transform .15s,box-shadow .15s}
  .lx-coach:hover{transform:translateY(-2px);box-shadow:0 14px 30px -16px color-mix(in srgb,var(--teal) 50%,transparent)}
  .lx-coach .ci{width:40px;height:40px;border-radius:11px;flex:none;display:grid;place-items:center;background:color-mix(in srgb,var(--teal) 14%,#fff);color:var(--teal)}
  .lx-coach .ci svg{width:22px;height:22px}
  .lx-coach .ct{flex:1;font-size:14.5px}.lx-coach .ct b{display:block;font-size:11.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--teal);font-weight:800}
  .lx-coach .ca{font-family:'Oswald',sans-serif;color:var(--teal);font-size:20px}
  .lx-upsell{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin:18px 0 0;
    border-radius:var(--r-lg);padding:16px 22px;background:linear-gradient(135deg,var(--coral),var(--terracota-700));color:#fff;box-shadow:0 14px 30px -14px rgba(239,67,117,.5)}
  .lx-upsell b{font-family:'Oswald',sans-serif;font-weight:700;text-transform:uppercase;letter-spacing:.03em;font-size:18px;display:block}
  .lx-upsell span{font-size:14px;opacity:.92}
  .lx-upsell a{flex:none;background:#fff;color:var(--terracota-700);font-family:'Oswald',sans-serif;font-weight:700;text-transform:uppercase;letter-spacing:.03em;font-size:15px;padding:11px 20px;border-radius:12px;text-decoration:none}

  /* Sección título */
  .lx-h2{font-family:'Oswald',sans-serif;font-weight:700;text-transform:uppercase;font-size:21px;letter-spacing:.4px;margin:28px 0 14px;color:var(--tinta)}

  /* Grid de acciones */
  .lx-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}
  .lx-card{--c:#ff2d6f;position:relative;display:flex;flex-direction:column;gap:10px;text-decoration:none;color:var(--tinta);
    background:var(--card);border:1px solid var(--line);border-radius:18px;padding:20px 18px;box-shadow:var(--shadow-sm);
    transition:transform .16s,box-shadow .16s,border-color .16s}
  .lx-card:hover{transform:translateY(-4px);box-shadow:0 18px 38px -20px color-mix(in srgb,var(--c) 60%,transparent);border-color:color-mix(in srgb,var(--c) 40%,#fff)}
  .lx-card .ico{width:52px;height:52px;border-radius:14px;display:grid;place-items:center;
    background:color-mix(in srgb,var(--c) 14%,#fff);color:var(--c)}
  .lx-card .ico svg{width:26px;height:26px}
  .lx-card h3{font-family:'Oswald',sans-serif;font-weight:700;text-transform:uppercase;font-size:17px;letter-spacing:.3px;margin:2px 0 0}
  .lx-card p{margin:0;font-size:13px;color:var(--muted);line-height:1.35}
  .lx-card .n{position:absolute;top:16px;right:16px;min-width:24px;height:24px;padding:0 7px;border-radius:999px;
    display:grid;place-items:center;font-family:'Oswald',sans-serif;font-weight:700;font-size:13px;color:#fff;background:var(--c)}
  .lx-card.feature{background:linear-gradient(135deg,var(--coral),var(--magenta));border-color:transparent;color:#fff;
    box-shadow:0 16px 34px -16px rgba(192,57,95,.55)}
  .lx-card.feature .ico{background:rgba(255,255,255,.2);color:#fff}
  .lx-card.feature p{color:rgba(255,255,255,.92)}

  /* Feed liviano */
  .lx-feed-card{margin-top:26px;background:var(--card);border:1px solid var(--line);border-radius:18px;padding:20px 22px;box-shadow:var(--shadow-sm)}
  .lx-feed-card h3{font-family:'Oswald',sans-serif;font-weight:700;text-transform:uppercase;font-size:17px;letter-spacing:.3px;margin:0 0 14px;display:flex;align-items:center;gap:9px}
  .lx-feed-card h3 img{width:20px;height:20px}
  .lx-feed{list-style:none;margin:0;padding:0}
  .lx-feed li{display:flex;align-items:center;gap:11px;padding:9px 0;border-bottom:1px solid var(--line)}
  .lx-feed li:last-child{border-bottom:0}
  .lx-feed .t{font-size:12px;font-weight:700;color:var(--muted);width:64px;flex:none;font-variant-numeric:tabular-nums}
  .lx-feed .dot{width:8px;height:8px;border-radius:50%;flex:none;background:var(--terracota);box-shadow:0 0 0 4px color-mix(in srgb,var(--terracota) 14%,transparent)}
  .lx-feed img.ic{width:26px;height:26px;flex:none}
  .lx-feed .m{font-size:14px;color:#473b46;line-height:1.35}
  .lx-feed-card .more{display:inline-block;margin-top:14px;color:var(--terracota);font-weight:800;font-size:13.5px;text-decoration:none}

  @media(max-width:980px){.lx-grid{grid-template-columns:repeat(3,1fr)}}
  @media(max-width:680px){.lx-grid{grid-template-columns:repeat(2,1fr)}.lx-card{padding:16px 15px}}
</style>

<section class="lx-hi">
  <h1>¡Hola, <span><?= $h($marca['nombre_negocio']) ?></span>!</h1>
  <p class="sub">¿Qué quieres hacer hoy? El corillo está listo pa' meterle mano.</p>
  <?php if ($plan): ?><div class="lx-badge"><?= ico('leaf') ?> <?= $h($plan_etq) ?></div><?php endif; ?>
</section>

<div class="lx-status">
  <a class="lx-chip <?= $pend>0?'hot':'' ?>" href="<?= $BASE ?>/aprobar2.php?marca=<?= $marca_id ?>&tab=pendientes"><b><?= $pend ?></b> <span>esperan tu OK</span></a>
  <a class="lx-chip" href="<?= $BASE ?>/aprobar2.php?marca=<?= $marca_id ?>&tab=aprobados"><b><?= $aprob ?></b> <span>listos pa' publicar</span></a>
  <a class="lx-chip" href="<?= $BASE ?>/ordenes.php?marca=<?= $marca_id ?>"><b><?= $ord['mes']>0 ? '$'.number_format($ord['mes'],0) : '$0' ?></b> <span>este mes</span></a>
</div>

<?php if (!$plan): ?>
  <div class="lx-upsell">
    <div><b>Estás probando gratis</b><span>Activa un plan y suelta el logo, posts ilimitados y todo el corillo.</span></div>
    <a href="<?= $BASE ?>/precios.php?marca=<?= $marca_id ?>">Activar plan →</a>
  </div>
<?php else: ?>
  <a class="lx-coach" href="<?= $BASE ?>/<?= strpos($paso[1],'?')!==false ? $paso[1].'&' : $paso[1].'?' ?>marca=<?= $marca_id ?>">
    <span class="ci"><?= ico($paso[2]) ?></span>
    <span class="ct"><b>Próximo paso del corillo:</b> <?= $h($paso[0]) ?></span>
    <span class="ca">→</span>
  </a>
<?php endif; ?>

<h2 class="lx-h2">¿Qué quieres hacer hoy?</h2>
<div class="lx-grid">
  <?php foreach ($acciones as $a):
    $href = $BASE.'/'.$a['hr'].(strpos($a['hr'],'?')!==false ? '&' : '?').'marca='.$marca_id;
    $n = $a['n'] ?? 0; ?>
    <a class="lx-card <?= !empty($a['feature'])?'feature':'' ?>" style="--c:<?= $a['c'] ?>" href="<?= $href ?>">
      <?php if ($n > 0): ?><span class="n"><?= $n ?></span><?php endif; ?>
      <span class="ico"><?= ico($a['icon']) ?></span>
      <h3><?= $h($a['t']) ?></h3>
      <p><?= $h($a['s']) ?></p>
    </a>
  <?php endforeach; ?>
</div>

<div class="lx-feed-card">
  <h3><img src="<?= $ICON ?>/bolt.svg" alt=""> Lo que hizo el corillo hoy</h3>
  <ol class="lx-feed">
    <?php if (!$feed): ?>
      <li><span class="m" style="color:var(--muted)">El corillo está listo pa' arrancar. Pídele algo y metemos mano.</span></li>
    <?php else: foreach ($feed as $fa): [$fic,$fnm,$fmsg] = $feed_map[$fa['agente']] ?? ['bolt','El Corillo','metió mano']; ?>
      <li>
        <span class="t"><?= date('g:i A', strtotime($fa['created_at'])) ?></span>
        <span class="dot"></span>
        <img class="ic" src="<?= $ICON ?>/<?= $h($fic) ?>.svg" alt="">
        <span class="m"><b><?= $h($fnm) ?></b> <?= $h($fmsg) ?></span>
      </li>
    <?php endforeach; endif; ?>
  </ol>
  <a class="more" href="<?= $BASE ?>/evidencia.php?marca=<?= $marca_id ?>">Ver toda la actividad →</a>
</div>

<?php require __DIR__ . '/_shell_foot.php'; ?>
