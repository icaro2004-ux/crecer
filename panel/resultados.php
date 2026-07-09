<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Resultados (centro de mando)
//  panel/resultados.php?marca=<id>
//
//  Pregunta: ¿cómo nos está yendo? Útil desde HOY con datos
//  internos reales (producción + consistencia + publicaciones).
//  Las métricas de redes (alcance, interacciones, crecimiento)
//  se ENCIENDEN al conectar Meta. NUNCA ceros falsos: lo
//  desconectado muestra contexto + CTA. Ver _ARQUITECTURA-CENTRO-MANDO.md.
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/suscripcion.php';
require __DIR__ . '/../includes/metricas.php';
requiere_login();
$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }
$marca_id = (int)$marca['id'];
$BASE = '/crecer/panel';

// ── Datos reales (hoy) ───────────────────────────────────────
$prod    = metricas_produccion($pdo, $marca_id);
$racha   = metricas_racha($pdo, $marca_id);
$pubs    = metricas_publicaciones($pdo, $marca_id, 30);
$meta_ok = metricas_meta_conectado($pdo, $marca_id);

// Consistencia: publicaciones por semana (últimas 8 semanas ISO), datos reales.
$semsql = $pdo->prepare(
    "SELECT YEARWEEK(publicado_at,3) wk, COUNT(*) n
     FROM crecer_contenido
     WHERE marca_id=? AND estado='publicado' AND publicado_at IS NOT NULL
       AND publicado_at >= (NOW() - INTERVAL 8 WEEK)
     GROUP BY wk");
$semsql->execute([$marca_id]);
$wkmap = [];
foreach ($semsql->fetchAll(PDO::FETCH_ASSOC) as $r) $wkmap[(int)$r['wk']] = (int)$r['n'];
$semanas = [];
for ($i = 7; $i >= 0; $i--) {
    $ts = time() - $i*7*86400;
    $wk = (int)date('oW', $ts);
    $semanas[] = ['wk'=>$wk, 'n'=>($wkmap[$wk] ?? 0)];
}
$max_sem = max(1, max(array_column($semanas, 'n')));
$total_pub_8sem = array_sum(array_column($semanas, 'n'));

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$ICON = '/crecer/assets/icons';
$active = 'resultados';
$page_title = 'Resultados';
$guia = ['key'=>'resultados','agente'=>'chart','titulo'=>'Cómo te está yendo',
  'intro'=>'Tu centro de mando: qué se publicó, qué viene y, al conectar redes, cómo rindió.',
  'pasos'=>[
    ['check-circle','En "Resumen" ves tu producción y consistencia (datos reales).'],
    ['image','En "Publicaciones" ves lo que ya salió.'],
    ['bolt','Conecta tus redes para encender alcance e interacciones.'],
  ]];
require __DIR__ . '/_shell.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@600;700&display=swap" rel="stylesheet">
<style>
  .content{max-width:820px}
  .rz-h1{font-family:'Oswald',sans-serif;font-weight:700;text-transform:uppercase;letter-spacing:.3px;font-size:clamp(24px,4vw,34px);margin:0;color:var(--tinta)}
  .rz-lede{color:var(--muted);font-size:14.5px;margin:6px 0 16px;max-width:60ch}
  /* tabs (segmented control, sticky en móvil) */
  .rz-tabs{position:sticky;top:0;z-index:5;display:flex;gap:6px;background:var(--crema);padding:8px 0 12px}
  .rz-tab{flex:1;border:1.5px solid var(--line);background:#fff;border-radius:12px;padding:10px 8px;cursor:pointer;
    font-family:inherit;font-weight:800;font-size:13.5px;color:var(--muted);transition:.15s}
  .rz-tab.on{border-color:transparent;background:linear-gradient(135deg,var(--teal),var(--teal-700));color:#fff;box-shadow:0 8px 20px -10px rgba(0,164,159,.45)}
  .rz-pane{display:none}.rz-pane.on{display:block;animation:rzin .25s ease both}
  @keyframes rzin{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
  .rz-card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px 20px;margin-bottom:16px;box-shadow:var(--shadow-sm)}
  .rz-card h2{font-family:'Oswald',sans-serif;font-weight:700;text-transform:uppercase;font-size:15px;letter-spacing:.3px;margin:0 0 12px;display:flex;align-items:center;gap:8px}
  /* flujo compacto de producción (NO 4 cards) */
  .rz-flow{display:flex;align-items:center;flex-wrap:wrap;gap:8px 12px;font-size:15px;color:var(--tinta)}
  .rz-flow b{font-family:'Oswald',sans-serif;font-weight:700;font-size:22px;color:var(--tinta);margin-right:4px}
  .rz-flow .arw{color:var(--line);font-weight:900;font-size:18px}
  .rz-sub{margin-top:8px;font-size:14px;color:var(--muted)}
  .rz-sub b{color:var(--tinta)}
  /* barras de consistencia */
  .rz-bars{display:flex;align-items:flex-end;gap:7px;height:88px;margin-top:6px}
  .rz-bar{flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;height:100%}
  .rz-bar .col{width:100%;border-radius:6px 6px 0 0;background:linear-gradient(180deg,var(--teal),var(--teal-700));min-height:3px;align-self:flex-end}
  .rz-bar .col.z{background:var(--line)}
  .rz-bar small{font-size:10px;color:var(--muted)}
  .rz-streak{margin-top:12px;font-size:14px;color:var(--tinta)}
  .rz-streak b{color:var(--tinta)}
  /* bloque bloqueado (sin Meta) — informativo = teal */
  .rz-lock{border:1.5px dashed color-mix(in srgb,var(--teal) 40%,var(--line));background:color-mix(in srgb,var(--teal) 5%,#fff)}
  .rz-lock .lk{display:flex;align-items:center;gap:8px;font-weight:800;color:var(--tinta);font-size:14.5px}
  .rz-lock p{margin:8px 0 14px;font-size:13.5px;color:var(--muted);line-height:1.5}
  .rz-cta{display:inline-flex;align-items:center;gap:8px;text-decoration:none;font-family:'Oswald',sans-serif;font-weight:700;
    text-transform:uppercase;letter-spacing:.03em;font-size:14px;color:#fff;padding:12px 20px;border-radius:12px;
    background:linear-gradient(135deg,var(--coral),var(--magenta));box-shadow:0 12px 26px -12px rgba(255,43,133,.5)}
  /* lista de publicaciones */
  .rz-pub{display:flex;align-items:center;gap:12px;padding:11px 0;border-bottom:1px solid var(--line)}
  .rz-pub:last-child{border-bottom:0}
  .rz-pub .th{width:46px;height:46px;border-radius:10px;object-fit:cover;flex:none;background:var(--crema-2)}
  .rz-pub .th.ph{display:grid;place-items:center;font-size:20px;color:var(--muted)}
  .rz-pub .tx{flex:1;min-width:0}
  .rz-pub .cap{font-size:13.5px;color:#473b46;line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
  .rz-pub .mt{font-size:11.5px;color:var(--muted);margin-top:2px}
  .rz-pub a.vp{flex:none;color:var(--teal);font-weight:700;font-size:12.5px;text-decoration:none}
  .rz-empty{color:var(--muted);font-size:13.5px}
  .rz-net{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--line);font-size:14px}
  .rz-net:last-child{border-bottom:0}.rz-net .e{font-size:18px}
  .rz-net .st{margin-left:auto;font-size:12.5px;color:var(--muted);font-weight:700}
</style>

<h1 class="rz-h1"><?= ico('chart') ?> Resultados</h1>
<p class="rz-lede">Cómo te está yendo. Hoy ves tu producción y consistencia (datos reales); al conectar tus redes se encienden alcance e interacciones.</p>

<div class="rz-tabs" role="tablist">
  <button class="rz-tab on" data-pane="resumen" role="tab">Resumen</button>
  <button class="rz-tab" data-pane="publicaciones" role="tab">Publicaciones</button>
  <button class="rz-tab" data-pane="redes" role="tab">Redes</button>
</div>

<!-- ── RESUMEN ───────────────────────────────────────────── -->
<section class="rz-pane on" id="pane-resumen">
  <div class="rz-card">
    <h2>Tu producción este mes</h2>
    <div class="rz-flow">
      <span><b><?= $prod['creados_mes'] ?></b>creados</span>
      <span class="arw">→</span>
      <span><b><?= $prod['esperando_ok'] ?></b>esperan tu OK</span>
      <span class="arw">→</span>
      <span><b><?= $prod['publicados_mes'] ?></b>publicados</span>
    </div>
    <?php if ($prod['listos'] > 0): ?>
      <div class="rz-sub"><b><?= $prod['listos'] ?></b> listo<?= $prod['listos']==1?'':'s' ?> para salir</div>
    <?php endif; ?>
  </div>

  <div class="rz-card">
    <h2>Consistencia</h2>
    <?php if ($total_pub_8sem === 0): ?>
      <p class="rz-empty">Aún no has publicado nada en las últimas semanas. Cuando empieces a publicar, aquí verás tu ritmo semana a semana.</p>
    <?php else: ?>
      <div class="rz-bars">
        <?php foreach ($semanas as $s): $hpc = (int)round($s['n']/$max_sem*100); ?>
          <div class="rz-bar" title="<?= $s['n'] ?> publicado(s)">
            <span class="col <?= $s['n']===0?'z':'' ?>" style="height:<?= max(4,$hpc) ?>%"></span>
            <small><?= (int)substr((string)$s['wk'], -2) ?></small>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="rz-streak">
        <?php if ($racha >= 2): ?>Racha actual: <b><?= $racha ?> semanas</b> seguidas publicando
        <?php elseif ($racha === 1): ?>Racha actual: <b>1 semana</b>. ¡Sigue así!
        <?php else: ?>Publica esta semana para arrancar tu racha.<?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="rz-card rz-lock">
    <div class="lk"><?= ico('lock') ?> Alcance e interacciones</div>
    <?php if ($meta_ok): ?>
      <p>Tus redes están conectadas. El alcance y las interacciones de cada post están en camino — el corillo los traerá de Meta.</p>
    <?php else: ?>
      <p>Conecta Instagram y Facebook y aquí verás a cuántas personas llegaste y cómo reaccionaron — directo de Meta.</p>
      <a class="rz-cta" href="<?= $BASE ?>/conectar.php?marca=<?= $marca_id ?>">Conectar mis redes →</a>
    <?php endif; ?>
  </div>
</section>

<!-- ── PUBLICACIONES ─────────────────────────────────────── -->
<section class="rz-pane" id="pane-publicaciones">
  <div class="rz-card">
    <h2>Tus posts publicados</h2>
    <?php if (!$pubs): ?>
      <p class="rz-empty">Todavía no has publicado nada. Cuando publiques un post, aparece aquí con su fecha y enlace.</p>
    <?php else: foreach ($pubs as $p):
      $cap = trim((string)$p['caption']); if ($cap==='') $cap = '(sin texto)'; ?>
      <div class="rz-pub">
        <?php if (!empty($p['grafica_path'])): ?>
          <img class="th" src="<?= $h($p['grafica_path']) ?>" alt="">
        <?php else: ?><span class="th ph"><?= $p['plataforma']==='facebook'?ico('facebook'):ico('instagram') ?></span><?php endif; ?>
        <div class="tx">
          <div class="cap"><?= $h(mb_strimwidth($cap,0,120,'…')) ?></div>
          <div class="mt"><?= $p['plataforma']==='facebook'?'Facebook':'Instagram' ?> · <?= $p['publicado_at'] ? $h(date('d/m/Y', strtotime($p['publicado_at']))) : 'publicado' ?></div>
        </div>
        <?php if (!empty($p['permalink'])): ?><a class="vp" href="<?= $h($p['permalink']) ?>" target="_blank" rel="noopener">ver post →</a><?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <div class="rz-card rz-lock">
    <div class="lk"><?= ico('lock') ?> Cómo rindió cada post</div>
    <?php if ($meta_ok): ?>
      <p>Tus redes están conectadas. Los likes, comentarios, guardados y alcance de cada post están en camino.</p>
    <?php else: ?>
      <p>Al conectar tus redes verás likes, comentarios, guardados y alcance de cada publicación — y el corillo te dirá qué tipo de post funciona mejor.</p>
      <a class="rz-cta" href="<?= $BASE ?>/conectar.php?marca=<?= $marca_id ?>">Conectar Instagram y Facebook →</a>
    <?php endif; ?>
  </div>
</section>

<!-- ── REDES ─────────────────────────────────────────────── -->
<section class="rz-pane" id="pane-redes">
  <div class="rz-card">
    <h2>Tus redes</h2>
    <div class="rz-net"><span class="e"><?= ico('instagram') ?></span> Instagram <span class="st"><?= $meta_ok ? 'conectado' : 'no conectado' ?></span></div>
    <div class="rz-net"><span class="e"><?= ico('facebook') ?></span> Facebook <span class="st"><?= $meta_ok ? 'conectado' : 'no conectado' ?></span></div>
  </div>
  <div class="rz-card rz-lock">
    <?php if ($meta_ok): ?>
      <div class="lk"><?= ico('check-circle') ?> Redes conectadas</div>
      <p>Tus métricas de alcance, interacciones y crecimiento de seguidores están en camino — el corillo las traerá de Meta.</p>
    <?php else: ?>
      <div class="lk"><?= ico('bolt') ?> Enciende tus métricas de redes</div>
      <p>Conecta Instagram y Facebook para ver alcance, interacciones y crecimiento de seguidores. Mientras tanto, en <b>Resumen</b> ya ves tu producción y consistencia, que son reales.</p>
      <a class="rz-cta" href="<?= $BASE ?>/conectar.php?marca=<?= $marca_id ?>">Conectar mis redes →</a>
    <?php endif; ?>
  </div>
</section>

<script>
(function(){
  var tabs=document.querySelectorAll('.rz-tab');
  tabs.forEach(function(t){
    t.addEventListener('click',function(){
      tabs.forEach(function(x){x.classList.remove('on');});
      document.querySelectorAll('.rz-pane').forEach(function(p){p.classList.remove('on');});
      t.classList.add('on');
      var pane=document.getElementById('pane-'+t.dataset.pane);
      if(pane) pane.classList.add('on');
    });
  });
})();
</script>

<?php require __DIR__ . '/_shell_foot.php'; ?>
