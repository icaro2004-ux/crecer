<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Resultados (centro de mando)
//  panel/resultados.php?marca=<id>
//
//  WIZARD educativo: card 1 = RESUMEN general del Analista (panorama +
//  tendencias + recomendaciones); cada KPI siguiente = su gráfica + una
//  EXPLICACIÓN de la IA para que el dueño entienda lo que ve. Cross-fade,
//  cero scroll. Los números son REALES (crecer_metricas). Sin Meta = CTA,
//  nunca ceros falsos.
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/suscripcion.php';
require __DIR__ . '/../includes/metricas.php';
requiere_login();
require_once __DIR__ . '/../includes/panel_guard.php';
requiere_suscripcion($pdo, isset($_GET['marca']) ? (int)$_GET['marca'] : null);
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
$redes   = metricas_redes_de_posts($pdo, $marca_id, array_column($pubs, 'id')); // estado por red
$conx    = metricas_conexion_detalle($pdo, $marca_id);                          // ig/fb enganchadas

// ── Botón "Actualizar métricas": trae insights frescos de Meta ──
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'refrescar_metricas') {
    if (function_exists('csrf_ok') && !csrf_ok()) {
        $flash = ['err', 'La sesión expiró. Recarga la página e intenta otra vez.'];
    } else {
        @set_time_limit(90);
        @ignore_user_abort(true);
        try {
            $r = metricas_refrescar_insights($pdo, $marca_id, 6, 0); // lote chico; el cron completa el resto
            if (!empty($r['ok'])) {
                $flash = ['ok', $r['n'] > 0
                    ? "Métricas al día · traje datos de {$r['n']} post" . ($r['n']==1?'':'s') . " de Meta."
                    : 'Ya estabas al día — Meta no tenía nada nuevo por ahora. Si acabas de publicar, dale unos minutos.'];
            } elseif (($r['motivo'] ?? '') === 'sin_conexion') {
                $flash = ['err', 'Conecta Instagram/Facebook primero para traer métricas.'];
            } elseif (($r['motivo'] ?? '') === 'sin_app') {
                $flash = ['err', 'La app de Meta aún no está configurada en el servidor.'];
            } else {
                $flash = ['ok', 'Listo.'];
            }
        } catch (Throwable $e) {
            error_log('resultados refrescar_metricas: ' . $e->getMessage());
            $flash = ['err', 'No pude actualizar las métricas ahora mismo. Intenta de nuevo en un momento.'];
        }
    }
}

// Insights ya guardados (cache) para pintar números reales — sin llamar a Meta.
$insights = metricas_insights_de_posts($pdo, $marca_id, array_column($pubs, 'id'));
$tot_ins  = metricas_totales_insights($pdo, $marca_id);
$hay_ins  = $tot_ins['n'] > 0;

// Combina las redes de un post en un set de números (para la lista).
$post_ins = function (int $pid) use ($insights): ?array {
    if (empty($insights[$pid])) return null;
    $a = ['alcance'=>null,'me_gusta'=>null,'comentarios'=>null,'guardados'=>null];
    $hay = false;
    foreach ($insights[$pid] as $row) {
        foreach ($a as $k => $_) {
            if (isset($row[$k]) && $row[$k] !== null) { $a[$k] = (int)$a[$k] + (int)$row[$k]; $hay = true; }
        }
    }
    return $hay ? $a : null;
};

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

// ── Agregados para el análisis (de lo YA capturado en crecer_metricas) ──
$mix = ['me_gusta'=>0,'comentarios'=>0,'guardados'=>0,'compartidos'=>0];
$net = [
  'instagram'=>['alcance'=>0,'me_gusta'=>0,'comentarios'=>0,'guardados'=>0,'compartidos'=>0,'n'=>0],
  'facebook' =>['alcance'=>0,'me_gusta'=>0,'comentarios'=>0,'guardados'=>0,'compartidos'=>0,'n'=>0],
];
$cap_by = []; $graf_by = [];
foreach ($pubs as $pp) { $cap_by[(int)$pp['id']] = (string)($pp['caption'] ?? ''); $graf_by[(int)$pp['id']] = (string)($pp['grafica_path'] ?? ''); }
$top = null;
foreach ($insights as $pid => $rows) {
    $palc = 0;
    foreach ($rows as $plat => $row) {
        $pl = in_array($plat, ['facebook','fb'], true) ? 'facebook' : (in_array($plat, ['instagram','ig'], true) ? 'instagram' : null);
        foreach (['me_gusta','comentarios','guardados','compartidos'] as $k) $mix[$k] += (int)($row[$k] ?? 0);
        if ($pl) {
            foreach (['alcance','me_gusta','comentarios','guardados','compartidos'] as $k) $net[$pl][$k] += (int)($row[$k] ?? 0);
            if (($row['alcance'] ?? null) !== null) $net[$pl]['n']++;
        }
        $palc += (int)($row['alcance'] ?? 0);
    }
    if ($palc > 0 && ($top === null || $palc > $top['alcance'])) {
        $mi = $post_ins((int)$pid);
        $top = ['pid'=>(int)$pid,'alcance'=>$palc,'me_gusta'=>(int)($mi['me_gusta'] ?? 0),
                'guardados'=>(int)($mi['guardados'] ?? 0),'caption'=>$cap_by[(int)$pid] ?? '','grafica'=>$graf_by[(int)$pid] ?? ''];
    }
}
$mix_total = array_sum($mix);
$has_ig = ($net['instagram']['alcance'] + $net['instagram']['me_gusta'] + $net['instagram']['comentarios']) > 0;
$has_fb = ($net['facebook']['alcance'] + $net['facebook']['me_gusta'] + $net['facebook']['comentarios']) > 0;
$eng_rate = $tot_ins['alcance'] > 0 ? round($tot_ins['interacciones'] / $tot_ins['alcance'] * 100, 1) : 0.0;

$datos_kpi = [
  'mes'                 => date('n/Y'),
  'posts_publicados'    => (int)$prod['publicados_mes'],
  'racha_semanas'       => (int)$racha,
  'alcance_total'       => (int)$tot_ins['alcance'],
  'interacciones_total' => (int)$tot_ins['interacciones'],
  'engagement_pct'      => $eng_rate,
  'mix'                 => $mix,
  'instagram'           => $net['instagram'],
  'facebook'            => $net['facebook'],
  'post_estrella'       => $top ? ['alcance'=>$top['alcance'],'me_gusta'=>$top['me_gusta'],'guardados'=>$top['guardados'],'caption'=>mb_substr($top['caption'],0,120)] : null,
  'tendencia_publicacion_semanal' => array_column($semanas, 'n'),
];

require_once __DIR__ . '/../includes/agentes.php';
$analista_nombre = function_exists('equipo_nombre') ? equipo_nombre($marca, 'analista') : 'El Analista';

// ── AJAX: la lectura del Analista (IA) por KPI (async; el front la pide al cargar) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'analizar') {
    header('Content-Type: application/json; charset=utf-8');
    if (function_exists('csrf_ok') && !csrf_ok()) { echo json_encode(['ok'=>false,'err'=>'Sesión expiró.']); exit; }
    try { echo json_encode(['ok'=>true, 'a'=>analista_resultados($pdo, $marca_id, $datos_kpi)], JSON_UNESCAPED_UNICODE); }
    catch (Throwable $e) { echo json_encode(['ok'=>false, 'err'=>substr($e->getMessage(), 0, 140)]); }
    exit;
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$active = 'resultados';
$page_title = 'Resultados';
$guia = null;
require __DIR__ . '/_shell.php';

// Barras de tendencia (reutilizable)
$barras = function() use ($semanas, $max_sem) {
    $o = '<div class="rzc-bars">';
    foreach ($semanas as $s) { $hpc = (int)round($s['n']/$max_sem*100); $o .= '<i class="'.($s['n']===0?'z':'').'" style="height:'.max(6,$hpc).'%"></i>'; }
    return $o . '</div>';
};
// Bloque de lectura del Analista (lo llena la IA async)
$ai = function(string $k) use ($h, $analista_nombre) { ?>
  <div class="rzc-ai" data-ai="<?= $h($k) ?>">
    <div class="who"><?= ico('chart') ?> <?= $h($analista_nombre) ?></div>
    <div class="rzc-read"><span class="rzc-load"><span class="sp"></span> leyendo tus números…</span></div>
    <div class="rzc-reco" style="display:none"><?= ico('bolt') ?><span></span></div>
  </div>
<?php };
$fnum = fn($n) => number_format((int)$n);
?>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
  .content{max-width:620px}
  .asis-fab{display:none}
  .rzw{max-width:560px;margin:0 auto;padding:10px 14px 26px;font-family:'Poppins',var(--font-body)}
  .rzw-top{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px;font-size:13px;color:var(--muted)}
  .rzw-top b{color:var(--ink-soft);font-weight:700}
  .rzw-refresh{background:none;border:1px solid var(--line);border-radius:99px;padding:6px 13px;font:inherit;font-size:12px;font-weight:700;color:var(--muted);cursor:pointer;display:inline-flex;align-items:center;gap:5px}
  .rzw-refresh svg{width:13px;height:13px}
  .rzw-flash{border-radius:12px;padding:10px 14px;font-size:13px;font-weight:600;margin-bottom:10px}
  .rzw-flash.ok{background:color-mix(in srgb,var(--teal) 10%,#fff);border:1px solid color-mix(in srgb,var(--teal) 26%,#fff);color:var(--ink-soft)}
  .rzw-flash.err{background:#fdeaea;border:1px solid #f5c2c0;color:#b42318}
  .rzw-dots{display:flex;gap:6px;justify-content:center;margin:2px 0 12px}
  .rzw-dots i{width:7px;height:7px;border-radius:50%;background:var(--line);transition:width .3s,background .3s}
  .rzw-dots i.on{width:20px;background:linear-gradient(90deg,var(--coral),var(--magenta))}
  .rzw-view{overflow:hidden;transition:height .4s cubic-bezier(.22,1,.36,1)}
  .rzw-track{position:relative}
  .rzc{box-sizing:border-box;background:var(--card);border:1px solid var(--line);border-radius:22px;padding:22px 20px;box-shadow:var(--shadow-sm)}
  .rzc-eyebrow{font-size:11.5px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:var(--magenta);display:flex;align-items:center;gap:7px}
  .rzc-eyebrow svg{width:15px;height:15px}
  .rzc-num{font-family:var(--font-display);font-weight:700;font-size:clamp(40px,12vw,58px);line-height:.95;letter-spacing:-.03em;color:var(--ink-soft);margin-top:10px}
  .rzc-num .u{font-size:.32em;color:var(--muted);font-weight:600;margin-left:8px}
  .rzc-sub{font-size:14.5px;color:var(--tinta);margin-top:8px;line-height:1.45}
  .rzc-sub b{font-weight:700}
  .rzc-bars{display:flex;align-items:flex-end;gap:6px;height:52px;margin-top:16px}
  .rzc-bars i{flex:1;border-radius:4px 4px 0 0;background:linear-gradient(180deg,var(--teal),var(--teal-700));min-height:3px}
  .rzc-bars i.z{background:var(--line)}
  /* chips resumen */
  .rzc-chips{display:flex;gap:9px;flex-wrap:wrap;margin-top:14px}
  .rzc-chip{flex:1;min-width:96px;background:var(--crema-2,#f6f4f1);border:1px solid var(--line);border-radius:14px;padding:11px 13px}
  .rzc-chip .n{font-family:var(--font-display);font-weight:700;font-size:21px;color:var(--ink-soft);letter-spacing:-.02em}
  .rzc-chip .l{font-size:11.5px;color:var(--muted);font-weight:600;margin-top:2px}
  /* dona interacciones */
  .rzc-mix{display:flex;align-items:center;gap:18px;margin-top:14px}
  .rzc-donut{width:118px;height:118px;border-radius:50%;position:relative;display:grid;place-items:center;flex:none}
  .rzc-donut::after{content:"";position:absolute;inset:15px;border-radius:50%;background:var(--card)}
  .rzc-donut span{position:relative;z-index:1;font-family:var(--font-display);font-weight:700;font-size:19px;color:var(--ink-soft)}
  .rzc-mixlist{flex:1;display:flex;flex-direction:column;gap:9px;min-width:0}
  .rzc-mrow{display:flex;align-items:center;gap:8px;font-size:13.5px}
  .rzc-mrow .sw{width:11px;height:11px;border-radius:3px;flex:none}
  .rzc-mrow .nm{color:var(--ink-soft);font-weight:600}
  .rzc-mrow .vv{margin-left:auto;font-weight:800}
  /* filas por red */
  .rzc-mets{margin-top:14px;display:flex;flex-direction:column}
  .rzc-met{display:flex;justify-content:space-between;padding:10px 0;border-top:1px solid var(--line);font-size:14px}
  .rzc-met:first-child{border-top:0}
  .rzc-met .k{color:var(--ink-soft);font-weight:600}
  .rzc-met .v{font-weight:800}
  .rzc-met .v.na{color:var(--muted);font-weight:600;font-size:12.5px}
  /* estrella */
  .rzc-star{display:flex;gap:14px;margin-top:12px}
  .rzc-star img,.rzc-star .noimg{width:78px;height:98px;border-radius:12px;flex:none;object-fit:cover;background:linear-gradient(135deg,var(--crema-2,#f6f4f1),#fff);border:1px solid var(--line);display:grid;place-items:center;color:var(--muted)}
  .rzc-star .q{font-size:13.5px;color:var(--ink-soft);line-height:1.45;margin:0 0 8px}
  .rzc-star .st{display:flex;gap:16px;font-size:12px;color:var(--muted);font-weight:600}
  .rzc-star .st b{display:block;font-size:16px;color:var(--ink-soft);font-weight:800}
  /* bloque IA */
  .rzc-ai{margin-top:18px;border-top:1px dashed var(--line);padding-top:14px}
  .rzc-ai .who{font-size:11.5px;font-weight:800;letter-spacing:.02em;color:var(--muted);display:flex;align-items:center;gap:7px;margin-bottom:9px;text-transform:uppercase}
  .rzc-ai .who svg{width:15px;height:15px;color:var(--teal)}
  .rzc-read{font-size:14px;color:var(--tinta);line-height:1.55}
  .rzc-reco{margin-top:11px;background:color-mix(in srgb,var(--teal) 8%,#fff);border:1px solid color-mix(in srgb,var(--teal) 22%,#fff);border-radius:13px;padding:11px 13px;font-size:13.5px;color:var(--ink-soft);line-height:1.5;display:flex;gap:9px;align-items:flex-start}
  .rzc-reco svg{width:16px;height:16px;color:var(--teal);flex:none;margin-top:2px}
  .rzc-load{color:var(--muted);font-style:italic;font-size:13.5px;display:inline-flex;align-items:center;gap:8px}
  .rzc-load .sp{width:15px;height:15px;border-radius:50%;border:2px solid rgba(0,0,0,.12);border-top-color:var(--magenta);animation:rzspin .8s linear infinite}
  @keyframes rzspin{to{transform:rotate(360deg)}}
  /* nav */
  .rzw-nav{display:flex;align-items:center;justify-content:space-between;margin-top:16px;gap:10px}
  .rzw-nav button{background:none;border:0;cursor:pointer;font:inherit;font-weight:700;font-size:14px;color:var(--ink-soft);padding:8px 4px;display:inline-flex;align-items:center;gap:5px}
  .rzw-nav button:disabled{opacity:.28;pointer-events:none}
  .rzw-nav svg{width:16px;height:16px}
  .rzw-count{font-size:12.5px;font-weight:700;color:var(--muted)}
  /* CTA card */
  .rzc-cta{text-align:center;padding:14px 0}
  .rzc-cta p{color:var(--muted);font-size:15px;line-height:1.5;margin:0 auto 16px;max-width:30ch}
  .rzc-cta .b{display:inline-block;font:inherit;font-family:'Poppins',sans-serif;font-weight:700;font-size:15px;color:#fff;text-decoration:none;padding:13px 26px;border-radius:14px;background:var(--btn-grad);box-shadow:var(--btn-glow);border:0;cursor:pointer}
  .rzc-gal{display:flex;gap:10px;overflow-x:auto;margin-top:16px;padding-bottom:6px;scroll-snap-type:x proximity}
  .rzc-gal::-webkit-scrollbar{height:0}
  .rzc-gal a,.rzc-gal .g{flex:0 0 92px;aspect-ratio:4/5;border-radius:12px;overflow:hidden;background:var(--crema-2,#f6f4f1);scroll-snap-align:start;position:relative;display:block}
  .rzc-gal img,.rzc-gal video{width:100%;height:100%;object-fit:cover;display:block}
</style>

<div class="rzw">
  <div class="rzw-top">
    <div><b>Resultados</b> · <?= $h(date('n/Y')) ?></div>
    <?php if ($meta_ok): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="refrescar_metricas">
      <button class="rzw-refresh" type="submit"><?= ico('refresh') ?> Actualizar</button></form>
    <?php endif; ?>
  </div>
  <?php if ($flash): ?><div class="rzw-flash <?= $flash[0] ?>"><?= $h($flash[1]) ?></div><?php endif; ?>

  <div class="rzw-dots" id="rzDots"></div>
  <div class="rzw-view" id="rzView"><div class="rzw-track" id="rzTrack">
  <?php $__f = true; $disp = function() use (&$__f) { if ($__f) { $__f = false; return ''; } return ' style="display:none"'; }; ?>

  <?php $analizar = true; /* el Analista siempre da su lectura */ ?>
  <!-- ── Card 1: RESUMEN general — SIEMPRE (panorama + recomendaciones) ── -->
  <section class="rzc" data-k="resumen"<?= $disp() ?>>
    <div class="rzc-eyebrow"><?= ico('sparkles') ?> Resumen del mes</div>
    <div class="rzc-chips">
      <div class="rzc-chip"><div class="n"><?= $fnum($tot_ins['alcance']) ?></div><div class="l">personas te vieron</div></div>
      <div class="rzc-chip"><div class="n"><?= $fnum($tot_ins['interacciones']) ?></div><div class="l">interacciones</div></div>
      <div class="rzc-chip"><div class="n"><?= (int)$prod['publicados_mes'] ?></div><div class="l">posts publicados</div></div>
    </div>
    <?php if (!$hay_ins): ?>
      <div class="rzc-sub" style="margin-top:12px"><?= $meta_ok
        ? 'Todavía no traje los números — dale a <b>Actualizar</b> arriba. Los KPIs de abajo se llenan solos.'
        : 'Los KPIs de abajo están en cero hasta que conectes tus redes. Conéctalas y verás crecer los números.' ?></div>
    <?php endif; ?>
    <?php $ai('resumen'); ?>
  </section>

  <!-- KPIs SIEMPRE (aunque en 0, para ver el progreso) -->
  <!-- Alcance -->
    <section class="rzc" data-k="alcance"<?= $disp() ?>>
      <div class="rzc-eyebrow"><?= ico('eye') ?> Alcance</div>
      <div class="rzc-num"><?= $fnum($tot_ins['alcance']) ?></div>
      <div class="rzc-sub">personas únicas te vieron<?php if ($eng_rate > 0): ?> · <b><?= $eng_rate ?>%</b> de engagement<?php endif; ?></div>
      <?php if ($total_pub_8sem > 0) echo $barras(); ?>
      <?php $ai('alcance'); ?>
    </section>

    <?php
      if ($mix_total > 0) {
        $p1 = round($mix['me_gusta']/$mix_total*100); $p2 = $p1 + round($mix['comentarios']/$mix_total*100);
        $p3 = $p2 + round($mix['guardados']/$mix_total*100);
        $donut_bg = "conic-gradient(var(--magenta) 0 {$p1}%, var(--teal) {$p1}% {$p2}%, var(--amber,#c78a16) {$p2}% {$p3}%, var(--ink-soft,#4a444c) {$p3}% 100%)";
      } else { $donut_bg = 'var(--line)'; }
    ?>
    <!-- Interacciones -->
    <section class="rzc" data-k="interacciones"<?= $disp() ?>>
      <div class="rzc-eyebrow"><?= ico('heart') ?> Interacciones</div>
      <div class="rzc-mix">
        <div class="rzc-donut" style="background:<?= $donut_bg ?>">
          <span><?= $fnum($mix_total) ?></span>
        </div>
        <div class="rzc-mixlist">
          <div class="rzc-mrow"><span class="sw" style="background:var(--magenta)"></span><span class="nm">Me gusta</span><span class="vv"><?= $fnum($mix['me_gusta']) ?></span></div>
          <div class="rzc-mrow"><span class="sw" style="background:var(--teal)"></span><span class="nm">Comentarios</span><span class="vv"><?= $fnum($mix['comentarios']) ?></span></div>
          <div class="rzc-mrow"><span class="sw" style="background:var(--amber,#c78a16)"></span><span class="nm">Guardados</span><span class="vv"><?= $fnum($mix['guardados']) ?></span></div>
          <div class="rzc-mrow"><span class="sw" style="background:var(--ink-soft,#4a444c)"></span><span class="nm">Compartidos</span><span class="vv"><?= $fnum($mix['compartidos']) ?></span></div>
        </div>
      </div>
      <?php $ai('interacciones'); ?>
    </section>

    <!-- Instagram -->
    <section class="rzc" data-k="instagram"<?= $disp() ?>>
      <div class="rzc-eyebrow" style="color:#c837ab"><?= ico('instagram') ?> Instagram</div>
      <div class="rzc-num" style="font-size:clamp(34px,10vw,46px)"><?= $fnum($net['instagram']['alcance']) ?></div>
      <div class="rzc-sub">de alcance en Instagram</div>
      <div class="rzc-mets">
        <div class="rzc-met"><span class="k">Me gusta</span><span class="v"><?= $fnum($net['instagram']['me_gusta']) ?></span></div>
        <div class="rzc-met"><span class="k">Comentarios</span><span class="v"><?= $fnum($net['instagram']['comentarios']) ?></span></div>
        <div class="rzc-met"><span class="k">Guardados</span><span class="v"><?= $fnum($net['instagram']['guardados']) ?></span></div>
        <div class="rzc-met"><span class="k">Compartidos</span><span class="v"><?= $fnum($net['instagram']['compartidos']) ?></span></div>
      </div>
      <?php $ai('instagram'); ?>
    </section>

    <!-- Facebook -->
    <section class="rzc" data-k="facebook"<?= $disp() ?>>
      <div class="rzc-eyebrow" style="color:#0a7cff"><?= ico('facebook') ?> Facebook</div>
      <div class="rzc-num" style="font-size:clamp(34px,10vw,46px)"><?= $fnum($net['facebook']['alcance']) ?></div>
      <div class="rzc-sub">de alcance en Facebook</div>
      <div class="rzc-mets">
        <div class="rzc-met"><span class="k">Reacciones</span><span class="v"><?= $fnum($net['facebook']['me_gusta']) ?></span></div>
        <div class="rzc-met"><span class="k">Comentarios</span><span class="v"><?= $fnum($net['facebook']['comentarios']) ?></span></div>
        <div class="rzc-met"><span class="k">Compartidos</span><span class="v"><?= $fnum($net['facebook']['compartidos']) ?></span></div>
        <div class="rzc-met"><span class="k">Guardados</span><span class="v na">no aplica en FB</span></div>
      </div>
      <?php $ai('facebook'); ?>
    </section>

    <?php if ($top): ?>
    <!-- Post estrella -->
    <section class="rzc" data-k="estrella"<?= $disp() ?>>
      <div class="rzc-eyebrow"><?= ico('star') ?> Tu post estrella</div>
      <div class="rzc-star">
        <?php if (!empty($top['grafica']) && preg_match('#\.(mp4|mov|m4v)$#i', $top['grafica'])): ?>
          <video src="<?= $h($top['grafica']) ?>" muted playsinline></video>
        <?php elseif (!empty($top['grafica'])): ?>
          <img src="<?= $h($top['grafica']) ?>" alt="">
        <?php else: ?><div class="noimg"><?= ico('image') ?></div><?php endif; ?>
        <div>
          <?php $cap = trim($top['caption']); if ($cap !== ''): ?><p class="q">“<?= $h(mb_strimwidth($cap, 0, 90, '…')) ?>”</p><?php endif; ?>
          <div class="st">
            <div><b><?= $fnum($top['alcance']) ?></b>alcance</div>
            <div><b><?= $fnum($top['me_gusta']) ?></b>me gusta</div>
            <div><b><?= $fnum($top['guardados']) ?></b>guardados</div>
          </div>
        </div>
      </div>
      <?php $ai('estrella'); ?>
    </section>
    <?php endif; ?>

  <?php if (!$meta_ok): ?>
    <!-- Sin conexión: los KPIs de arriba están en 0 hasta conectar -->
    <section class="rzc" data-k="cta"<?= $disp() ?>>
      <div class="rzc-cta">
        <div class="rzc-eyebrow" style="justify-content:center"><?= ico('chart') ?> Conecta tus redes</div>
        <p style="margin-top:12px">Conecta Instagram/Facebook y estos KPIs se empiezan a llenar con datos reales, con la lectura del Analista.</p>
        <a class="b" href="<?= $BASE ?>/conectar.php?marca=<?= $marca_id ?>">Conectar mis redes</a>
      </div>
    </section>
  <?php endif; ?>

  </div></div><!-- /rzw-track /rzw-view -->

  <div class="rzw-nav">
    <button type="button" id="rzPrev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6"/></svg>Atrás</button>
    <span class="rzw-count" id="rzCount"></span>
    <button type="button" id="rzNext">Siguiente<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg></button>
  </div>
</div>

<script>
(function(){
  var view=document.getElementById('rzView'), track=document.getElementById('rzTrack');
  if(!view||!track) return;
  var cards=[].slice.call(track.querySelectorAll('.rzc'));
  var dotsWrap=document.getElementById('rzDots'), prevB=document.getElementById('rzPrev'), nextB=document.getElementById('rzNext'), count=document.getElementById('rzCount');
  var REDUCE=window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches, dots=[];
  cards.forEach(function(_,i){ var d=document.createElement('i'); if(i===0)d.className='on'; dotsWrap.appendChild(d); dots.push(d); });
  var cur=0;
  function fit(){ var c=cards[cur]; if(c) view.style.height=c.offsetHeight+'px'; }
  function paint(){ if(count)count.textContent=(cur+1)+' / '+cards.length; if(prevB)prevB.disabled=cur<=0; if(nextB)nextB.disabled=cur>=cards.length-1; dots.forEach(function(d,x){ d.classList.toggle('on',x===cur); }); }
  function go(t){
    t=Math.max(0,Math.min(cards.length-1,t)); if(t===cur){ fit(); return; }
    var dir=t>cur?1:-1, a=cards[cur], b=cards[t]; cur=t; paint();
    if(REDUCE){ a.style.display='none'; b.style.display=''; fit(); return; }
    a.style.transition='opacity .2s ease, transform .2s ease'; a.style.opacity='0'; a.style.transform='translateX('+(-14*dir)+'px)';
    setTimeout(function(){
      a.style.display='none'; a.style.transition=''; a.style.transform=''; a.style.opacity='';
      b.style.display=''; b.style.opacity='0'; b.style.transform='translateX('+(16*dir)+'px)'; view.style.height=b.offsetHeight+'px';
      requestAnimationFrame(function(){ b.style.transition='opacity .34s cubic-bezier(.22,1,.36,1), transform .34s cubic-bezier(.22,1,.36,1)'; b.style.opacity='1'; b.style.transform='none'; });
    }, 190);
  }
  if(prevB) prevB.addEventListener('click',function(){ go(cur-1); });
  if(nextB) nextB.addEventListener('click',function(){ go(cur+1); });
  var x0=null,y0=null,lock=null;
  view.addEventListener('touchstart',function(e){ if(e.target.closest('a,button,form,input,.rzc-gal')){x0=null;return;} var t=e.touches[0];x0=t.clientX;y0=t.clientY;lock=null; },{passive:true});
  view.addEventListener('touchmove',function(e){ if(x0===null)return; var t=e.touches[0],dx=t.clientX-x0,dy=t.clientY-y0; if(lock===null&&(Math.abs(dx)>8||Math.abs(dy)>8)) lock=Math.abs(dx)>Math.abs(dy)?'x':'y'; },{passive:true});
  view.addEventListener('touchend',function(e){ if(x0===null||lock!=='x'){x0=null;return;} var dx=e.changedTouches[0].clientX-x0; if(dx<-45)go(cur+1); else if(dx>45)go(cur-1); x0=null; },{passive:true});
  window.addEventListener('resize',fit); window.addEventListener('load',fit);
  cur=0; cards.forEach(function(c,x){ c.style.display=x===0?'':'none'; }); paint(); fit();

  // ── La lectura del Analista (IA) ──
  var ANALIZAR=<?= $analizar ? 'true' : 'false' ?>, CSRF=<?= json_encode(csrf_token()) ?>;
  if(ANALIZAR){
    function fail(msg){ cards.forEach(function(c){ var r=c.querySelector('.rzc-read'); if(r) r.textContent=msg; }); fit(); }
    var fd=new FormData(); fd.append('accion','analizar'); fd.append('csrf',CSRF);
    fetch(location.pathname+location.search,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
      if(!d.ok||!d.a){ fail('No pude leer los números ahora. Dale a Actualizar y regresa en un momento.'); return; }
      cards.forEach(function(c){
        var slot=c.querySelector('.rzc-ai'); if(!slot) return;
        var a=d.a[slot.dataset.ai]||{}, read=slot.querySelector('.rzc-read'), reco=slot.querySelector('.rzc-reco');
        read.textContent = a.lectura || '—';
        if(a.reco){ reco.querySelector('span').textContent=a.reco; reco.style.display='flex'; }
      });
      fit();
    }).catch(function(){ fail('Se cayó la conexión al leer tus números. Intenta de nuevo.'); });
  }
})();
</script>

<?php require __DIR__ . '/_shell_foot.php'; ?>
