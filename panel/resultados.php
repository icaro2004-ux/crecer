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
$guia = null; // Los logros se sienten, no se explican.
require __DIR__ . '/_shell.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
  /* ══ RESULTADOS ══ historias, no dashboard. Mismo director: Poppins, ink-soft,
     el número como héroe, mucho aire, la galería como prueba visual. */
  .content{max-width:640px}
  .asis-fab{display:none}
  .rz{max-width:600px;margin:0 auto;padding:16px 16px 100px;font-family:'Poppins',var(--font-body)}
  @media(min-width:761px){.rz{padding:26px 10px 70px}}

  .rz-top{display:flex;align-items:baseline;justify-content:space-between;gap:12px;padding:0 2px}
  .rz-neg{font-family:var(--font-display);font-size:15px;font-weight:600;color:var(--ink-soft);letter-spacing:-.01em}
  .rz-cred{font-size:12.5px;color:var(--muted)}
  .rz-flash{border-radius:14px;padding:12px 16px;font-size:13.5px;font-weight:600;margin:14px 0 0;line-height:1.4}
  .rz-flash.ok{background:color-mix(in srgb,var(--teal) 10%,#fff);border:1px solid color-mix(in srgb,var(--teal) 26%,#fff);color:var(--ink-soft)}
  .rz-flash.err{background:#fdeaea;border:1px solid #f5c2c0;color:#b42318}

  /* cada logro: un momento con el número de héroe */
  .rz-mo{padding:34px 2px;border-bottom:1px solid var(--line)}
  .rz-num{font-family:var(--font-display);font-weight:600;font-size:clamp(46px,13vw,70px);line-height:.95;letter-spacing:-.03em;color:var(--ink-soft)}
  .rz-num .u{font-size:.4em;color:var(--muted);font-weight:500;margin-left:9px;letter-spacing:0}
  .rz-line{margin-top:12px;font-size:16px;color:var(--tinta);line-height:1.45;font-weight:400;max-width:34ch}
  .rz-line b{font-weight:600}
  .rz-accent .rz-num{color:var(--magenta)}

  .rz-spark{display:flex;align-items:flex-end;gap:6px;height:50px;margin-top:20px;max-width:290px}
  .rz-spark i{flex:1;border-radius:4px 4px 0 0;background:linear-gradient(180deg,var(--teal),var(--teal-700));min-height:3px}
  .rz-spark i.z{background:var(--line)}

  .rz-inv{padding:32px 2px;border-bottom:1px solid var(--line)}
  .rz-inv p{font-size:16px;color:var(--muted);line-height:1.5;margin:0 0 18px;max-width:32ch}
  .rz-link{display:inline-block;font-family:'Poppins',sans-serif;font-weight:600;font-size:15px;color:#fff;text-decoration:none;
    padding:14px 28px;border-radius:15px;background:var(--btn-grad);box-shadow:var(--btn-glow);border:0;cursor:pointer;
    transition:transform var(--dur) var(--ease),box-shadow var(--dur) var(--ease)}
  .rz-link:active{transform:translateY(1px);box-shadow:var(--btn-glow-active)}
  .rz-quiet{background:0;border:0;cursor:pointer;font-family:'Poppins',sans-serif;font-weight:500;font-size:14px;color:var(--teal-700);padding:4px 0}
  .rz-quiet:hover{color:var(--teal-dark)}
  .rz-quiet svg{width:15px;height:15px;vertical-align:-.2em;margin-right:5px}

  /* lo que salió: galería visual (misma lengua que Biblioteca) */
  .rz-galt{font-family:var(--font-display);font-size:16px;font-weight:600;color:var(--ink-soft);margin:34px 0 16px}
  .rz-gal{display:flex;gap:12px;overflow-x:auto;padding:2px 2px 12px;scroll-snap-type:x proximity;-webkit-overflow-scrolling:touch}
  .rz-gal::-webkit-scrollbar{height:0}
  .rz-shot{position:relative;flex:0 0 140px;width:140px;aspect-ratio:4/5;scroll-snap-align:start;display:block;border-radius:16px;overflow:hidden;background:var(--crema-2);
    box-shadow:0 1px 3px rgba(40,22,28,.06);transition:transform var(--dur) var(--ease),box-shadow var(--dur) var(--ease);text-decoration:none}
  .rz-shot:hover{transform:translateY(-3px);box-shadow:0 18px 40px -16px rgba(40,22,28,.3)}
  .rz-shot img,.rz-shot video{width:100%;height:100%;object-fit:cover;display:block}
  .rz-shot .ph{width:100%;height:100%;display:grid;place-items:center;color:var(--muted)}
  .rz-shot .chip{position:absolute;left:10px;bottom:10px;display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:700;color:#fff;
    background:rgba(0,0,0,.5);backdrop-filter:blur(4px);padding:5px 10px;border-radius:99px}
  .rz-shot .chip svg{width:13px;height:13px}
  .rz-shot .fail{position:absolute;top:9px;right:9px;width:9px;height:9px;border-radius:50%;background:#ff5a72;box-shadow:0 0 0 3px rgba(255,90,114,.25)}
  .rz-empty{color:var(--muted);font-size:15px;padding:30px 2px;line-height:1.5;max-width:34ch}
</style>

<main class="rz">
  <div class="rz-top"><span class="rz-neg">Resultados</span><span class="rz-cred">Este mes</span></div>
  <?php if ($flash): ?><div class="rz-flash <?= $flash[0] ?>"><?= $h($flash[1]) ?></div><?php endif; ?>

  <section class="rz-mo">
    <div class="rz-num"><?= (int)$prod['publicados_mes'] ?></div>
    <div class="rz-line">post<?= $prod['publicados_mes']==1?'':'s' ?> publicados este mes<?php if ($prod['listos'] > 0): ?> · <b><?= (int)$prod['listos'] ?></b> listo<?= $prod['listos']==1?'':'s' ?> para salir<?php endif; ?></div>
  </section>

  <?php if ($total_pub_8sem > 0): ?>
  <section class="rz-mo">
    <div class="rz-num"><?= (int)$racha ?><span class="u"><?= $racha==1?'semana':'semanas' ?></span></div>
    <div class="rz-line"><?= $racha >= 2 ? 'seguidas publicando. La constancia es lo que crece.' : ($racha === 1 ? 'publicando. Sigue así.' : 'Publica esta semana para arrancar tu racha.') ?></div>
    <div class="rz-spark">
      <?php foreach ($semanas as $s): $hpc = (int)round($s['n']/$max_sem*100); ?>
        <i class="<?= $s['n']===0?'z':'' ?>" style="height:<?= max(6,$hpc) ?>%" title="<?= $s['n'] ?>"></i>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($hay_ins): ?>
  <section class="rz-mo rz-accent">
    <div class="rz-num"><?= number_format($tot_ins['alcance']) ?></div>
    <div class="rz-line">personas te vieron · <b><?= number_format($tot_ins['interacciones']) ?></b> interacciones</div>
    <form method="post" style="margin-top:16px"><?= csrf_field() ?><input type="hidden" name="accion" value="refrescar_metricas">
      <button class="rz-quiet" type="submit"><?= ico('refresh') ?>Actualizar</button></form>
  </section>
  <?php elseif ($meta_ok): ?>
  <section class="rz-inv">
    <p>Tus redes están conectadas. Trae de Meta a cuántos llegaste.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="refrescar_metricas">
      <button class="rz-link" type="submit">Actualizar métricas</button></form>
  </section>
  <?php else: ?>
  <section class="rz-inv">
    <p>Conecta tus redes y aquí verás a cuántas personas llegaste.</p>
    <a class="rz-link" href="<?= $BASE ?>/conectar.php?marca=<?= $marca_id ?>">Conectar mis redes</a>
  </section>
  <?php endif; ?>

  <?php if ($pubs): ?>
    <div class="rz-galt">Lo que salió</div>
    <div class="rz-gal">
      <?php foreach ($pubs as $p):
        $rp = $redes[(int)$p['id']] ?? [];
        $link = ''; foreach ($rp as $inf) { if (($inf['estado'] ?? '') === 'ok' && !empty($inf['permalink'])) { $link = (string)$inf['permalink']; break; } }
        $fail = false; foreach ($rp as $inf) { if (($inf['estado'] ?? '') === 'error') { $fail = true; break; } }
        $mi = $post_ins((int)$p['id']);
        $tag = $link !== '' ? 'a' : 'div';
      ?>
      <<?= $tag ?> class="rz-shot"<?= $link !== '' ? ' href="' . $h($link) . '" target="_blank" rel="noopener"' : '' ?>>
        <?php if (!empty($p['grafica_path']) && preg_match('#\.(mp4|mov|m4v)$#i', (string)$p['grafica_path'])): ?>
          <video src="<?= $h($p['grafica_path']) ?>" muted playsinline preload="metadata"></video>
        <?php elseif (!empty($p['grafica_path'])): ?>
          <img src="<?= $h($p['grafica_path']) ?>" alt="" loading="lazy">
        <?php else: ?><div class="ph"><?= ico('image') ?></div><?php endif; ?>
        <?php if ($mi && $mi['alcance'] !== null): ?><span class="chip"><?= ico('eye') ?><?= number_format($mi['alcance']) ?></span><?php endif; ?>
        <?php if ($fail): ?><span class="fail" title="Una red falló al publicar"></span><?php endif; ?>
      </<?= $tag ?>>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p class="rz-empty">Todavía no ha salido nada. Cuando publiques, tus posts viven aquí.</p>
  <?php endif; ?>
</main>

<?php require __DIR__ . '/_shell_foot.php'; ?>
