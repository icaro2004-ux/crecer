<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Dashboard "Centro del Corillo"
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

// Estado real por agente (para los pills) + contadores de "lo que hizo hoy"
$ua = $pdo->prepare("SELECT agente, MAX(created_at) ult FROM crecer_ia_log WHERE marca_id=? AND estado='ok' GROUP BY agente");
$ua->execute([$marca_id]); $por_agente = [];
foreach ($ua->fetchAll() as $r) $por_agente[$r['agente']] = $r['ult'];
$reciente = fn($db) => !empty($por_agente[$db]) && (time()-strtotime($por_agente[$db])) < 7*86400;

$cnt_ideas = (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$marca_id} AND caption<>''")->fetchColumn();
$cnt_artes = (int)$pdo->query("SELECT COUNT(*) FROM crecer_graficas WHERE marca_id={$marca_id}")->fetchColumn();
$cnt_citas = (int)$pdo->query("SELECT COUNT(*) FROM crecer_ordenes WHERE marca_id={$marca_id}")->fetchColumn();
$cnt_oport = $aprob;
$tiene_logo = (int)$pdo->query("SELECT COUNT(*) FROM crecer_logos WHERE marca_id={$marca_id}")->fetchColumn() > 0;

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$ICON = '/crecer/assets/icons';
$BASE = '/crecer/panel';

// El corillo (rico). 'req' = requiere plan Despegar. 'quotes' = frases que rotan.
$corillo = [
  ['c'=>'purple','icon'=>'estratega','db'=>'planificador','name'=>'El Estratega','role'=>'Planifica tu mes',
    'quotes'=>['Estoy cuadrando el plan pa’ que no publiques a lo loco.','Ya tengo el mapa del próximo mes.','Estoy organizando las prioridades.','Déjame ver dónde está la mejor oportunidad.']],
  ['c'=>'pink','icon'=>'creativa','db'=>'creador','name'=>'La Creativa','role'=>'Ideas + captions',
    'quotes'=>['Tengo ideas frescas pa’ que tu marca no suene genérica.','Se me ocurrió algo que puede funcionar brutal.','Estoy buscando cómo llamar la atención.','Déjame cocinar algo.']],
  ['c'=>'orange','icon'=>'disenador','db'=>'diseñador','name'=>'El Diseñador','role'=>'Artes + visuales',
    'quotes'=>['Estoy montando piezas que se vean duras.','Le estoy dando cariño a los detalles.','Quiero que esto se vea premium.','Déjame terminar este arte.']],
  ['c'=>'teal','icon'=>'agenda','db'=>'agenda','name'=>'La Agenda','role'=>'Citas + órdenes',
    'quotes'=>['Tengo el calendario tranquilo y sin revoluces.','Todo está cuadrado.','No tienes nada pendiente.','Ya organicé la semana.']],
  ['c'=>'green','icon'=>'vendedor','db'=>'responder','name'=>'El Vendedor','role'=>'Leads + ofertas','req'=>true,
    'quotes'=>['Estoy buscando dónde hay chavos en la mesa.','Vi una oportunidad interesante.','Hay clientes que podemos atacar.','Déjame mover esto.']],
  ['c'=>'blue','icon'=>'analista','db'=>'analista','name'=>'El Analista','role'=>'Métricas + oportunidades','req'=>true,
    'quotes'=>['Vi tus números y hay una oportunidad aquí.','Algo interesante está pasando.','Encontré un patrón.','Creo que podemos mejorar esto.']],
];
$active = 'inicio';
$page_title = 'Inicio';
$guia = ['key'=>'inicio','agente'=>'sparkles','titulo'=>'Tu Centro del Corillo',
  'intro'=>'Aquí ves a tu equipo de IA trabajando y qué necesita tu atención.',
  'pasos'=>[
    ['sparkles','Mira "Tu corillo": cada agente te dice qué está haciendo ahora.'],
    ['check-circle','"Necesita tu OK" es lo que el corillo te dejó listo para aprobar.'],
    ['bolt','Dale a "Dale, corillo" para pedirle más contenido cuando quieras.'],
  ]];
require __DIR__ . '/_shell.php';   // define $plan / $plan_etq

// Estado REAL → pill + frase rotada (rota por día/negocio, estable dentro del día)
$rot = (int)$marca_id + (int)date('z');
foreach ($corillo as $k=>&$c) {
  $c['locked'] = !empty($c['req']) && $plan !== 'despegar';
  $reci = $reciente($c['db']);
  if ($c['locked'])                  $c['pill'] = 'Despegar';
  elseif ($c['db']==='planificador') $c['pill'] = $cal_id ? 'Planificando' : 'En estrategia';
  elseif ($c['db']==='creador')      $c['pill'] = $pend>0 ? 'Ideando' : ($reci ? 'Inspirada' : 'Lista para crear');
  elseif ($c['db']==='diseñador')    $c['pill'] = $reci ? 'Diseñando' : 'Listo para montar';
  elseif ($c['db']==='agenda')       $c['pill'] = $ord['abiertas']>0 ? 'Coordinando' : 'Al día';
  elseif ($c['db']==='responder')    $c['pill'] = 'Prospectando';
  else                               $c['pill'] = 'Analizando';
  $c['working'] = !$c['locked'] && $reci;
  $c['quote'] = $c['quotes'][($rot + $k) % count($c['quotes'])];
}
unset($c);

// Próximo paso del corillo (coach dinámico, según tu estado real)
if     ($pend > 0)      $paso = [$pend.' post'.($pend==1?'':'s').' esperando tu OK — apruébalos', 'aprobar2.php', 'check-circle'];
elseif (!$tiene_logo)   $paso = ['Crea tu logo con El Diseñador', 'marca.php', 'palette'];
elseif ($cnt_artes==0)  $paso = ['Sube una foto pa\' tu primer arte', 'graficas.php', 'camera'];
elseif ($ord['abiertas']>0) $paso = ['Tienes '.$ord['abiertas'].' órden'.($ord['abiertas']==1?'':'es').' abierta'.($ord['abiertas']==1?'':'s'), 'ordenes.php', 'package'];
else                    $paso = ['Vas bien — pídele al corillo más contenido', 'aprobar2.php', 'bolt'];
?>
<style>
  .content{max-width:1440px}
  .dashx{--ink:var(--tinta)}
  .dashx .hero{display:flex;justify-content:space-between;gap:26px;align-items:flex-start;flex-wrap:wrap;padding:0}
  .dashx .hero h1{font-family:var(--font-impact);text-transform:uppercase;font-size:clamp(38px,6vw,62px);line-height:.92;letter-spacing:.5px;margin:0;display:flex;align-items:center;gap:14px;flex-wrap:wrap}
  .dashx .hero h1 span{color:var(--terracota)}
  .dashx .hero .sub{font-size:17px;color:var(--muted);margin:14px 0 0;max-width:560px}
  .dashx .title-bolt{width:50px;height:50px;filter:drop-shadow(0 0 10px rgba(255,45,111,.4))}
  .dashx .active-card{min-width:380px;flex:1;max-width:480px;border-radius:18px;padding:20px 22px;display:flex;gap:16px;align-items:center;
    background:var(--card);box-shadow:var(--shadow);border:1px solid var(--line);position:relative}
  .dashx .active-card .bolt-icon{width:46px;height:46px;flex:none;filter:drop-shadow(0 0 12px rgba(255,45,111,.45))}
  .dashx .active-card strong{font-family:var(--font-impact);text-transform:uppercase;letter-spacing:.03em;font-size:16px}
  .dashx .active-card p{margin:3px 0 0;font-size:14px;color:var(--muted)}
  .dashx .active-card .dot{position:absolute;right:22px;top:22px;width:12px;height:12px;border-radius:50%;background:#0bac30;box-shadow:0 0 0 6px rgba(11,172,48,.15)}
  .dashx .plan-badge{display:inline-flex;align-items:center;gap:6px;margin-top:12px;font-family:var(--font-impact);text-transform:uppercase;
    letter-spacing:.05em;font-size:12px;background:var(--gold);color:#3a2a00;padding:7px 13px;border-radius:9px;transform:rotate(-2deg)}
  .dashx .plan-badge svg{width:15px;height:15px}

  .dashx .sec{display:flex;align-items:center;gap:12px;margin:30px 0 16px}
  .dashx .sec h2{font-family:var(--font-impact);text-transform:uppercase;font-size:22px;margin:0}
  .dashx .sec .spark{width:22px;height:22px;color:var(--terracota)}
  .dashx .sec:after{content:"";height:1px;flex:1;background:var(--line)}
  .dashx .sec a{color:var(--terracota);font-weight:800;font-size:13.5px;text-decoration:none;white-space:nowrap}

  .dashx .agents{display:grid;grid-template-columns:repeat(6,1fr);gap:14px}
  .dashx .agent{--c:#8a2cff;text-align:center;border-radius:18px;padding:22px 16px;position:relative;overflow:hidden;
    background:linear-gradient(180deg,color-mix(in srgb,var(--c) 9%,#fff),var(--card));
    border:1.5px solid color-mix(in srgb,var(--c) 30%,#fff);box-shadow:0 14px 32px -18px color-mix(in srgb,var(--c) 50%,transparent);transition:transform .18s,box-shadow .18s}
  .dashx .agent:hover{transform:translateY(-4px);box-shadow:0 20px 44px -18px color-mix(in srgb,var(--c) 55%,transparent)}
  .dashx .agent.locked{opacity:.62;filter:grayscale(.35)}
  .dashx .orb{width:104px;height:104px;margin:0 auto 14px;border-radius:50%;display:grid;place-items:center;
    background:radial-gradient(circle,#fff 0 42%,color-mix(in srgb,var(--c) 14%,#fff));
    box-shadow:inset 0 0 0 2px color-mix(in srgb,var(--c) 12%,#fff),0 0 0 1px color-mix(in srgb,var(--c) 20%,#fff)}
  .dashx .orb img{width:70px;height:70px}
  .dashx .agent em{display:inline-block;font-style:normal;font-weight:900;font-size:11px;letter-spacing:.04em;text-transform:uppercase;
    padding:6px 13px;border-radius:999px;background:color-mix(in srgb,var(--c) 16%,#fff);color:color-mix(in srgb,var(--c) 78%,#000);
    border:1px solid color-mix(in srgb,var(--c) 28%,#fff)}
  .dashx .agent h3{font-family:var(--font-impact);text-transform:uppercase;font-size:21px;letter-spacing:.3px;margin:14px 0 4px}
  .dashx .agent .rol{color:var(--muted);font-size:13px;margin:0 0 12px;font-weight:600}
  .dashx .agent blockquote{margin:0;font-size:13.5px;line-height:1.5;color:#473b46}
  .dashx .purple{--c:#7928ff}.dashx .pink{--c:#ff2d6f}.dashx .orange{--c:#ff7900}
  .dashx .teal{--c:#00a5a8}.dashx .green{--c:#33b617}.dashx .blue{--c:#1e69ff}

  .dashx .metrics{display:grid;grid-template-columns:1.1fr 1fr 1fr;gap:16px;margin-top:18px}
  .dashx .metrics article{border-radius:18px;padding:24px;position:relative;overflow:hidden;background:var(--card);border:1px solid var(--line);box-shadow:var(--shadow-sm)}
  .dashx .metrics b{font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
  .dashx .metrics h4{font-family:var(--font-impact);font-size:52px;line-height:1;margin:8px 0 4px}
  .dashx .metrics p{margin:0;font-size:13.5px;color:var(--muted)}
  .dashx .metrics .lk{display:inline-block;margin-top:14px;font-weight:800;font-size:14px;text-decoration:none;padding:11px 18px;border-radius:11px}
  .dashx .metrics .hot{background:linear-gradient(135deg,var(--coral),var(--terracota-700));color:#fff;border-color:transparent}
  .dashx .hot b,.dashx .hot p{color:rgba(255,255,255,.9)} .dashx .hot h4{color:#fff;font-size:60px}
  .dashx .hot .lk{background:#fff;color:var(--terracota-700)}
  .dashx .hot .bubble{position:absolute;right:26px;top:46px;width:74px;height:74px;border-radius:50%;display:grid;place-items:center;
    font-family:var(--font-impact);font-size:26px;color:#fff;background:rgba(255,255,255,.18);border:2px solid rgba(255,255,255,.5)}
  .dashx .metrics .teal-bg{background:linear-gradient(135deg,#fff,#e9ffff)} .dashx .teal-bg h4{color:var(--teal)}
  .dashx .teal-bg .lk{border:1.5px solid var(--teal);color:var(--teal-700,#007b7e)}
  .dashx .teal-bg .pp{position:absolute;right:30px;top:40px;width:88px;opacity:.85}
  .dashx .money h4{color:var(--amber-ink)}
  .dashx .money .ic{width:34px;height:34px;position:absolute;right:30px;top:34px;color:var(--gold)}
  .dashx .money .ords{display:inline-flex;align-items:center;gap:6px;color:var(--teal);font-weight:800;text-decoration:none;margin-top:12px}
  .dashx .money .ords svg{width:18px;height:18px}

  .dashx .bottom{display:grid;grid-template-columns:1.25fr .9fr;gap:16px;margin-top:18px}
  .dashx .bottom article{border-radius:18px;padding:24px;border:1px solid var(--line);box-shadow:var(--shadow-sm)}
  .dashx .activity{background:var(--card)}
  .dashx .activity h3{font-family:var(--font-impact);text-transform:uppercase;font-size:19px;margin:0 0 18px;display:flex;align-items:center;gap:9px}
  .dashx .activity .mini{width:22px;height:22px}
  .dashx .chips{display:flex;flex-wrap:wrap;gap:14px;padding-bottom:16px;border-bottom:1px solid var(--line)}
  .dashx .chips .chip{display:flex;align-items:center;gap:10px;font-size:13.5px;color:#473b46}
  .dashx .chips .ci{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;flex:none}
  .dashx .chips .ci img{width:21px;height:21px}
  .dashx .chips b{font-family:var(--font-impact);font-size:20px;margin-right:1px}
  .dashx .activity .foot{margin:16px 0 0;color:var(--muted);font-size:13.5px}
  .dashx .cta{background:radial-gradient(circle at 90% 40%,rgba(255,0,92,.28),transparent 30%),linear-gradient(135deg,#22102c,#0a0712);color:#fff;position:relative;overflow:hidden}
  .dashx .cta h3{font-family:var(--font-impact);text-transform:uppercase;font-size:28px;margin:0 0 8px}
  .dashx .cta h3 span{color:var(--terracota)}
  .dashx .cta p{margin:0 0 18px;color:rgba(255,255,255,.78);font-size:14px;max-width:340px}
  .dashx .cta .go{display:inline-block;background:var(--terracota);color:#fff;font-family:var(--font-impact);text-transform:uppercase;
    letter-spacing:.03em;font-size:16px;padding:14px 26px;border-radius:12px;text-decoration:none}
  .dashx .cta .cta-bolt{position:absolute;right:40px;top:50%;transform:translateY(-50%);width:130px;height:130px;
    filter:drop-shadow(0 0 22px rgba(255,45,111,.9)) drop-shadow(0 0 4px rgba(255,255,255,.25))}

  .dashx .upsell{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin:18px 0 2px;
    border-radius:var(--r-lg);padding:16px 22px;background:linear-gradient(135deg,var(--coral),var(--terracota-700));color:#fff;
    box-shadow:0 14px 30px -14px rgba(239,67,117,.5)}
  .dashx .upsell b{font-family:var(--font-impact);text-transform:uppercase;letter-spacing:.03em;font-size:18px;display:block}
  .dashx .upsell span{font-size:14px;opacity:.92}
  .dashx .upsell a{flex:none;background:#fff;color:var(--terracota-700);font-family:var(--font-impact);text-transform:uppercase;
    letter-spacing:.03em;font-size:15px;padding:12px 22px;border-radius:12px;text-decoration:none}
  .dashx .coach{display:flex;align-items:center;gap:13px;margin:18px 0 2px;padding:14px 18px;border-radius:var(--r-lg);
    background:linear-gradient(135deg,color-mix(in srgb,var(--teal) 12%,#fff),var(--card));border:1.5px solid color-mix(in srgb,var(--teal) 26%,#fff);
    text-decoration:none;color:var(--tinta);transition:transform .15s,box-shadow .15s}
  .dashx .coach:hover{transform:translateY(-2px);box-shadow:0 14px 30px -16px color-mix(in srgb,var(--teal) 50%,transparent)}
  .dashx .coach .ci{width:40px;height:40px;border-radius:11px;flex:none;display:grid;place-items:center;background:color-mix(in srgb,var(--teal) 14%,#fff);color:var(--teal)}
  .dashx .coach .ci svg{width:22px;height:22px}
  .dashx .coach .ct{flex:1;font-size:14.5px}.dashx .coach .ct b{display:block;font-size:11.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--teal);font-weight:800}
  .dashx .coach .ca{font-family:var(--font-impact);color:var(--teal);font-size:20px}
  @media(max-width:1200px){.dashx .agents{grid-template-columns:repeat(3,1fr)}.dashx .metrics,.dashx .bottom{grid-template-columns:1fr}.dashx .cta .cta-bolt{opacity:.5}}
  @media(max-width:640px){.dashx .agents{grid-template-columns:repeat(2,1fr)}.dashx .active-card{min-width:0}}
</style>

<div class="dashx">

  <!-- HERO -->
  <section class="hero">
    <div>
      <h1>¡Wepa, <span><?= $h($marca['nombre_negocio']) ?>!</span> <img class="title-bolt" src="<?= $ICON ?>/bolt.svg" alt=""></h1>
      <p class="sub">Mientras tú haces lo tuyo, <b>el corillo te trabaja el negocio.</b> Esto es lo que pasó:</p>
      <?php if ($plan): ?><div class="plan-badge"><?= ico('leaf') ?> <?= $h($plan_etq) ?></div><?php endif; ?>
    </div>
    <div class="active-card">
      <img class="bolt-icon" src="<?= $ICON ?>/bolt.svg" alt="">
      <div><strong>El corillo está activo</strong><p>Trabajando pa’ que tu negocio crezca</p></div>
      <span class="dot"></span>
    </div>
  </section>

  <?php if (!$plan): ?>
    <div class="upsell">
      <div>
        <b>Estás probando gratis</b>
        <span>Activa un plan y suelta el logo, posts ilimitados y todo el corillo.</span>
      </div>
      <a href="<?= $BASE ?>/precios.php?marca=<?= $marca_id ?>">Activar plan →</a>
    </div>
  <?php else: ?>
    <a class="coach" href="<?= $BASE ?>/<?= $paso[1] ?>?marca=<?= $marca_id ?>">
      <span class="ci"><?= ico($paso[2]) ?></span>
      <span class="ct"><b>Próximo paso del corillo:</b> <?= $h($paso[0]) ?></span>
      <span class="ca">→</span>
    </a>
  <?php endif; ?>

  <?php if (!empty($marca['autopilot'])): ?>
    <a class="coach" href="<?= $BASE ?>/aprobar2.php?marca=<?= $marca_id ?>" style="background:linear-gradient(135deg,#241633,#0e0a16);border-color:transparent;color:#fff">
      <span class="ci" style="background:rgba(255,255,255,.14);color:#fff">🌙</span>
      <span class="ct" style="color:#fff"><b style="color:#c9b8ff">Piloto automático ON</b>
        <?php if (!empty($marca['autopilot_ultimo'])): ?>
          El corillo trabajó solo el <?= $h(date('d/m', strtotime($marca['autopilot_ultimo']))) ?> · <?= $pend ?> post<?= $pend===1?'':'s' ?> esperan tu OK
        <?php else: ?>
          El corillo te preparará posts solo, cada semana. Te avisamos.
        <?php endif; ?>
      </span>
      <span class="ca" style="color:#fff">→</span>
    </a>
  <?php endif; ?>

  <!-- TU CORILLO -->
  <div class="sec"><?= ico('sparkles','spark') ?><h2>Tu corillo</h2><a href="<?= $BASE ?>/evidencia.php?marca=<?= $marca_id ?>">Ver actividad del corillo →</a></div>
  <section class="agents">
    <?php foreach ($corillo as $c): ?>
      <article class="agent <?= $c['c'] ?> <?= $c['locked']?'locked':'' ?> <?= !empty($c['working'])?'working':'' ?>">
        <div class="orb"><img src="<?= $ICON ?>/<?= $c['icon'] ?>.svg" alt="<?= $h($c['name']) ?>"></div>
        <em><?= $h($c['pill']) ?><?= (!$c['locked'] && $reciente($c['db'])) ? ' ·' : '' ?></em>
        <h3><?= $h($c['name']) ?></h3>
        <div class="rol"><?= $h($c['role']) ?></div>
        <blockquote>“<?= $h($c['quote']) ?>”</blockquote>
      </article>
    <?php endforeach; ?>
  </section>

  <!-- KPIs -->
  <section class="metrics">
    <article class="hot">
      <b>Necesita tu OK</b>
      <h4><?= $pend ?></h4>
      <p>contenidos listos para tu aprobación</p>
      <a class="lk" href="<?= $BASE ?>/aprobar2.php?marca=<?= $marca_id ?>"><?= $cal_id ? 'Revisar y aprobar →' : 'Generar mi primer mes →' ?></a>
      <?php if ($pend>0): ?><div class="bubble"><?= $pend ?></div><?php endif; ?>
      <img src="<?= $ICON ?>/aprobar.svg" alt="" style="position:absolute;left:-16px;bottom:-20px;width:120px;opacity:.13;filter:brightness(0) invert(1)">
    </article>
    <article class="teal-bg">
      <b>Listas para publicar</b>
      <h4><?= $aprob ?></h4>
      <p>contenidos listos pa’ publicar en tus redes</p>
      <a class="lk" href="<?= $BASE ?>/aprobar2.php?marca=<?= $marca_id ?>&tab=aprobados">Ver contenidos →</a>
      <img class="pp" src="<?= $ICON ?>/paperplane.svg" alt="">
    </article>
    <article class="money">
      <b>Ingresos del mes</b>
      <h4><?= $ord['mes']>0 ? '$'.number_format($ord['mes'],0) : '$0' ?></h4>
      <p>de tus órdenes completadas</p>
      <?= ico('dollar') ?>
      <div><a class="ords" href="<?= $BASE ?>/ordenes.php?marca=<?= $marca_id ?>"><?= ico('package') ?> <?= $ord['abiertas'] ?> órdenes</a> <span style="color:var(--muted);font-size:13px"> abiertas ahora</span></div>
    </article>
  </section>

  <!-- LO QUE HIZO + CTA -->
  <section class="bottom">
    <article class="activity">
      <h3><img class="mini" src="<?= $ICON ?>/bolt.svg" alt=""> Lo que hizo el corillo hoy</h3>
      <div class="chips">
        <div class="chip"><span class="ci" style="background:color-mix(in srgb,#ff2d6f 14%,#fff)"><img src="<?= $ICON ?>/creativa.svg"></span><span><b><?= $cnt_ideas ?></b> ideas nuevas</span></div>
        <div class="chip"><span class="ci" style="background:color-mix(in srgb,#ff7900 14%,#fff)"><img src="<?= $ICON ?>/disenador.svg"></span><span><b><?= $cnt_artes ?></b> artes diseñadas</span></div>
        <div class="chip"><span class="ci" style="background:color-mix(in srgb,#00a5a8 14%,#fff)"><img src="<?= $ICON ?>/agenda.svg"></span><span><b><?= $cnt_citas ?></b> citas agendadas</span></div>
        <div class="chip"><span class="ci" style="background:color-mix(in srgb,#33b617 14%,#fff)"><img src="<?= $ICON ?>/vendedor.svg"></span><span><b><?= $cnt_oport ?></b> oportunidades</span></div>
      </div>
      <p class="foot">El corillo está metiendo mano. Tú sigue enfocado en lo tuyo.</p>
    </article>
    <article class="cta">
      <h3>Pon el <span>corillo</span> a trabajar</h3>
      <p>Pídele más contenido, arte o un plan nuevo. Tú decides, ellos ejecutan.</p>
      <a class="go" href="<?= $BASE ?>/aprobar2.php?marca=<?= $marca_id ?>">Dale, corillo →</a>
      <img class="cta-bolt" src="<?= $ICON ?>/bolt.svg" alt="">
    </article>
  </section>

</div>

<?php require __DIR__ . '/_shell_foot.php'; ?>
