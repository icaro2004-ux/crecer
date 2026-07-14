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
$redes   = metricas_redes_de_posts($pdo, $marca_id, array_column($pubs, 'id')); // estado por red
$conx    = metricas_conexion_detalle($pdo, $marca_id);                          // ig/fb enganchadas

// ── Botón "Actualizar métricas": trae insights frescos de Meta ──
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'refrescar_metricas') {
    if (function_exists('csrf_ok') && !csrf_ok()) {
        $flash = ['err', 'La sesión expiró. Recarga la página e intenta otra vez.'];
    } else {
        // Trae insights de Meta = varias llamadas a la Graph API. Subir el límite
        // de tiempo (evita el fatal 500 por timeout) y lote chico para ir rápido.
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
  /* badges por red (¿salió en IG y FB?) */
  .rz-nets{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px}
  .rz-nb{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:800;padding:3px 9px;border-radius:99px;border:1px solid var(--line);text-decoration:none}
  .rz-nb svg{width:13px;height:13px;flex:none}
  .rz-nb.ok{color:var(--teal-dark);background:color-mix(in srgb,var(--teal) 8%,#fff);border-color:color-mix(in srgb,var(--teal) 28%,#fff)}
  .rz-nb.err{color:#b3123b;background:#ffe0e6;border-color:#f4b8c6}
  .rz-fallo{display:flex;align-items:flex-start;gap:6px;margin-top:6px;font-size:11.5px;line-height:1.4;color:#b3123b;background:#fff0f3;border:1px solid #f4b8c6;border-radius:8px;padding:6px 9px}
  .rz-fallo svg{width:13px;height:13px;flex:none;margin-top:1px;color:#b3123b}
  .rz-nb.none{color:var(--muted);background:var(--crema-2)}
  .rz-net{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--line);font-size:14px}
  .rz-net:last-child{border-bottom:0}.rz-net .e{font-size:18px}
  .rz-net .st{margin-left:auto;font-size:12.5px;color:var(--muted);font-weight:700}
  /* números reales de Meta bajo cada post */
  .rz-mx{display:flex;flex-wrap:wrap;gap:13px;margin-top:7px}
  .rz-mx span{display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:800;color:var(--tinta)}
  .rz-mx svg{width:14px;height:14px;color:var(--teal);flex:none}
  /* botón "Actualizar métricas" */
  .rz-refresh{display:inline-flex;align-items:center;gap:7px;border:1.5px solid color-mix(in srgb,var(--teal) 45%,var(--line));
    background:#fff;color:var(--teal-dark,#087));font-family:'Oswald',sans-serif;font-weight:700;text-transform:uppercase;
    letter-spacing:.03em;font-size:12.5px;padding:8px 14px;border-radius:10px;cursor:pointer}
  .rz-refresh:hover{background:color-mix(in srgb,var(--teal) 7%,#fff)}
  .rz-refresh svg{width:15px;height:15px}
  .rz-updated{font-size:11.5px;color:var(--muted);margin-top:8px}
  /* flash tras actualizar */
  .rz-flash{border-radius:12px;padding:11px 15px;font-size:13.5px;font-weight:700;margin:0 0 14px;line-height:1.4}
  .rz-flash.ok{background:color-mix(in srgb,var(--teal) 9%,#fff);border:1px solid color-mix(in srgb,var(--teal) 30%,#fff);color:var(--tinta)}
  .rz-flash.err{background:#fdeaea;border:1px solid #f5c2c0;color:#b42318}
</style>

<h1 class="rz-h1"><?= ico('chart') ?> Resultados</h1>
<p class="rz-lede">Cómo te está yendo. Hoy ves tu producción y consistencia (datos reales); al conectar tus redes se encienden alcance e interacciones.</p>

<?php if ($flash): ?><div class="rz-flash <?= $flash[0] ?>"><?= $h($flash[1]) ?></div><?php endif; ?>

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

  <?php if ($hay_ins): ?>
    <div class="rz-card">
      <h2><?= ico('eye') ?> Alcance e interacciones · este mes</h2>
      <div class="rz-flow">
        <span><b><?= number_format($tot_ins['alcance']) ?></b>personas alcanzadas</span>
        <span class="arw">·</span>
        <span><b><?= number_format($tot_ins['interacciones']) ?></b>interacciones</span>
      </div>
      <div class="rz-sub">Directo de Meta · <?= (int)$tot_ins['n'] ?> post<?= $tot_ins['n']==1?'':'s' ?> con datos este mes. Abajo, en <b>Publicaciones</b>, ves el detalle de cada uno.</div>
      <form method="post" style="margin-top:12px">
        <?= csrf_field() ?><input type="hidden" name="accion" value="refrescar_metricas">
        <button class="rz-refresh" type="submit"><?= ico('refresh') ?> Actualizar métricas</button>
      </form>
    </div>
  <?php elseif ($meta_ok): ?>
    <div class="rz-card rz-lock">
      <div class="lk"><?= ico('eye') ?> Alcance e interacciones</div>
      <p>Tus redes están conectadas. Toca <b>Actualizar</b> para traer de Meta el alcance y las reacciones de tus posts publicados.</p>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="accion" value="refrescar_metricas">
        <button class="rz-refresh" type="submit"><?= ico('refresh') ?> Actualizar métricas</button>
      </form>
    </div>
  <?php else: ?>
    <div class="rz-card rz-lock">
      <div class="lk"><?= ico('lock') ?> Alcance e interacciones</div>
      <p>Conecta Instagram y Facebook y aquí verás a cuántas personas llegaste y cómo reaccionaron — directo de Meta.</p>
      <a class="rz-cta" href="<?= $BASE ?>/conectar.php?marca=<?= $marca_id ?>">Conectar mis redes →</a>
    </div>
  <?php endif; ?>
</section>

<!-- ── PUBLICACIONES ─────────────────────────────────────── -->
<section class="rz-pane" id="pane-publicaciones">
  <div class="rz-card">
    <h2>Tus posts publicados</h2>
    <?php if (!$pubs): ?>
      <p class="rz-empty">Todavía no has publicado nada. Cuando publiques un post, aparece aquí con su fecha y enlace.</p>
    <?php else: foreach ($pubs as $p):
      $cap = trim((string)$p['caption']); if ($cap==='') $cap = '(sin texto)';
      // Redes que se intentaron para este post (de plataformas; fallback a plataforma)
      $intent = [];
      if (!empty($p['plataformas'])) {
          foreach (preg_split('/[,\s]+/', strtolower((string)$p['plataformas'])) as $x) {
              if (in_array($x, ['instagram','facebook'], true)) $intent[$x] = true;
          }
      }
      if (!$intent && !empty($p['plataforma'])) $intent[$p['plataforma']] = true;
      if (!$intent) $intent = ['instagram'=>true];
      $rp = $redes[(int)$p['id']] ?? [];
    ?>
      <div class="rz-pub">
        <?php if (!empty($p['grafica_path']) && preg_match('#\.(mp4|mov|m4v)$#i', (string)$p['grafica_path'])): ?>
          <video class="th" src="<?= $h($p['grafica_path']) ?>" muted playsinline preload="metadata"></video>
        <?php elseif (!empty($p['grafica_path'])): ?>
          <img class="th" src="<?= $h($p['grafica_path']) ?>" alt="">
        <?php else: ?><span class="th ph"><?= $p['plataforma']==='facebook'?ico('facebook'):ico('instagram') ?></span><?php endif; ?>
        <div class="tx">
          <div class="cap"><?= $h(mb_strimwidth($cap,0,120,'…')) ?></div>
          <div class="mt"><?= $p['publicado_at'] ? $h(date('d/m/Y', strtotime($p['publicado_at']))) : 'publicado' ?></div>
          <div class="rz-nets">
            <?php foreach (['instagram'=>'Instagram','facebook'=>'Facebook'] as $net=>$lbl):
                if (empty($intent[$net])) continue;
                $info = $rp[$net] ?? null;
                $ic = $net==='facebook' ? ico('facebook') : ico('instagram');
                if ($info && $info['estado']==='ok' && !empty($info['permalink'])): ?>
                  <a class="rz-nb ok" href="<?= $h($info['permalink']) ?>" target="_blank" rel="noopener"><?= $ic ?> <?= $lbl ?> · ver →</a>
                <?php elseif ($info && $info['estado']==='ok'): ?>
                  <span class="rz-nb ok"><?= $ic ?> <?= $lbl ?> · publicado</span>
                <?php elseif ($info && $info['estado']==='error'): ?>
                  <span class="rz-nb err" title="<?= $h($info['error'] ?? 'Error al publicar') ?>"><?= $ic ?> <?= $lbl ?> · falló</span>
                <?php else: ?>
                  <span class="rz-nb none"><?= $ic ?> <?= $lbl ?> · no salió</span>
                <?php endif;
            endforeach; ?>
          </div>
          <?php
            $_fallos = [];
            foreach (['instagram'=>'Instagram','facebook'=>'Facebook'] as $net=>$lbl2) {
                $inf = $rp[$net] ?? null;
                if ($inf && ($inf['estado'] ?? '') === 'error') $_fallos[] = $lbl2 . ': ' . ($inf['error'] ?? 'error al publicar');
            }
            if ($_fallos): ?>
            <div class="rz-fallo"><?= ico('bolt') ?> <span><?= $h(implode(' · ', $_fallos)) ?></span></div>
          <?php endif; ?>
          <?php $mi = $post_ins((int)$p['id']); if ($mi): ?>
            <div class="rz-mx">
              <?php if ($mi['alcance']     !== null): ?><span title="Personas alcanzadas"><?= ico('eye') ?><?= number_format($mi['alcance']) ?></span><?php endif; ?>
              <?php if ($mi['me_gusta']    !== null): ?><span title="Me gusta / reacciones"><?= ico('heart') ?><?= number_format($mi['me_gusta']) ?></span><?php endif; ?>
              <?php if ($mi['comentarios'] !== null): ?><span title="Comentarios"><?= ico('chat') ?><?= number_format($mi['comentarios']) ?></span><?php endif; ?>
              <?php if ($mi['guardados']   !== null): ?><span title="Guardados"><?= ico('bookmark') ?><?= number_format($mi['guardados']) ?></span><?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <?php if ($hay_ins): ?>
    <div class="rz-card" style="text-align:center">
      <p class="rz-updated" style="margin:0"><?= ico('check-circle') ?> Cada post muestra su alcance y reacciones directo de Meta.</p>
    </div>
  <?php elseif ($meta_ok): ?>
    <div class="rz-card rz-lock">
      <div class="lk"><?= ico('eye') ?> Cómo rindió cada post</div>
      <p>Tus redes están conectadas. En <b>Resumen</b> toca <b>Actualizar métricas</b> y aquí aparecen los likes, comentarios, guardados y alcance de cada post.</p>
    </div>
  <?php else: ?>
    <div class="rz-card rz-lock">
      <div class="lk"><?= ico('lock') ?> Cómo rindió cada post</div>
      <p>Al conectar tus redes verás likes, comentarios, guardados y alcance de cada publicación — y el corillo te dirá qué tipo de post funciona mejor.</p>
      <a class="rz-cta" href="<?= $BASE ?>/conectar.php?marca=<?= $marca_id ?>">Conectar Instagram y Facebook →</a>
    </div>
  <?php endif; ?>
</section>

<!-- ── REDES ─────────────────────────────────────────────── -->
<section class="rz-pane" id="pane-redes">
  <div class="rz-card">
    <h2>Tus redes</h2>
    <div class="rz-net"><span class="e"><?= ico('instagram') ?></span> Instagram <span class="st" style="color:<?= $conx['ig']?'var(--teal-dark)':'var(--muted)' ?>"><?= $conx['ig'] ? 'conectado' : 'no conectado' ?></span></div>
    <div class="rz-net"><span class="e"><?= ico('facebook') ?></span> Facebook <span class="st" style="color:<?= $conx['fb']?'var(--teal-dark)':'var(--muted)' ?>"><?= $conx['fb'] ? 'conectado' : 'no conectado' ?></span></div>
    <div class="rz-net"><span class="e"><img src="/crecer/assets/icons/whatsapp.svg" alt="" style="width:18px;height:18px;vertical-align:-.2em"></span> WhatsApp (Estado) <span class="st" style="color:var(--muted)">manual</span></div>
    <p style="font-size:12px;color:var(--muted);margin-top:8px;line-height:1.5">El <b>Estado de WhatsApp</b> no se conecta ni se publica automático — <b>Meta no lo permite</b> por API. En cada post, con <b>“A mi Estado de WhatsApp”</b> (en «Ver en redes») lo subes tú desde el celular en un toque.</p>
    <?php if ($meta_ok && !$conx['fb']): ?>
      <p style="font-size:12.5px;color:#b3123b;font-weight:700;margin-top:10px;line-height:1.45">Tu Instagram está conectado pero <b>falta enganchar una Página de Facebook</b> — por eso los posts salen solo en IG. Vuelve a <a href="<?= $BASE ?>/conectar.php?marca=<?= $marca_id ?>" style="color:#b3123b;text-decoration:underline">conectar</a> y elige tu Página.</p>
    <?php endif; ?>
  </div>
  <?php if ($hay_ins): ?>
    <div class="rz-card">
      <div class="lk" style="display:flex;align-items:center;gap:8px;font-weight:800;color:var(--tinta);font-size:14.5px"><?= ico('check-circle') ?> Este mes</div>
      <div class="rz-flow" style="margin-top:10px">
        <span><b><?= number_format($tot_ins['alcance']) ?></b>alcance</span>
        <span class="arw">·</span>
        <span><b><?= number_format($tot_ins['interacciones']) ?></b>interacciones</span>
      </div>
      <form method="post" style="margin-top:12px">
        <?= csrf_field() ?><input type="hidden" name="accion" value="refrescar_metricas">
        <button class="rz-refresh" type="submit"><?= ico('refresh') ?> Actualizar métricas</button>
      </form>
    </div>
  <?php elseif ($meta_ok): ?>
    <div class="rz-card rz-lock">
      <div class="lk"><?= ico('eye') ?> Enciende tus métricas</div>
      <p>Tus redes están conectadas. En <b>Resumen</b> toca <b>Actualizar métricas</b> para traer de Meta tu alcance e interacciones.</p>
    </div>
  <?php else: ?>
    <div class="rz-card rz-lock">
      <div class="lk"><?= ico('bolt') ?> Enciende tus métricas de redes</div>
      <p>Conecta Instagram y Facebook para ver alcance, interacciones y crecimiento de seguidores. Mientras tanto, en <b>Resumen</b> ya ves tu producción y consistencia, que son reales.</p>
      <a class="rz-cta" href="<?= $BASE ?>/conectar.php?marca=<?= $marca_id ?>">Conectar mis redes →</a>
    </div>
  <?php endif; ?>
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
