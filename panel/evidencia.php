<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Evidencia del Corillo (criterio #2 XPRIZE)
//  panel/evidencia.php?marca=<id>
//
//  Prueba, con DATOS REALES, que la IA opera el negocio: cada
//  llamada queda en crecer_ia_log; aquí se muestra cruda y honesta
//  (agente, decisión, modelo, tokens, costo, hora). + corridas
//  autónomas (piloto) + publicaciones. Nada inventado.
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/suscripcion.php';
requiere_login();

$usuario = usuario_actual($pdo);
$USUARIO_ID = (int)$usuario['id'];
$marca = marca_del_usuario($pdo, $USUARIO_ID, isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }
$marca_id = (int)$marca['id'];

// EVIDENCIA TÉCNICA — protegida (tokens, costos, modelos, agregados del
// sistema). Es la prueba del criterio #2 del XPRIZE, para admin/jurado.
// El cliente ve la versión humana en actividad.php.
if (($usuario['rol'] ?? '') !== 'admin') {
    header('Location: /crecer/panel/actividad.php?marca=' . $marca_id);
    exit;
}
$BASE = '/crecer/panel';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$ago = function($ts){
    $s = time() - strtotime($ts);
    if ($s < 60) return 'hace ' . $s . 's';
    if ($s < 3600) return 'hace ' . floor($s/60) . ' min';
    if ($s < 86400) return 'hace ' . floor($s/3600) . ' h';
    return 'hace ' . floor($s/86400) . ' d';
};

$nombres_agente = [
    'planificador'=>['🗓️','El Estratega','planificó contenido'],
    'creador'=>['✍️','La Creativa','escribió un caption'],
    'diseñador'=>['🎨','El Diseñador','creó un arte'],
    'intake'=>['🧠','El Intake','aprendió el negocio'],
    'asistente'=>['💬','El Asistente','resolvió una duda'],
    'retencion'=>['🤝','El Vendedor','escribió a un cliente'],
    'analitica'=>['📊','El Analista','resumió el mes'],
    'aprendiz'=>['📚','El Aprendiz','aprendió vocabulario'],
    'editor'=>['📝','El Editor','ajustó un texto'],
];
$agf = fn($a) => $nombres_agente[$a] ?? ['⚙️', ucfirst($a), 'ejecutó una acción'];

// ── Métricas reales de ESTA marca ────────────────────────────
$tot = $pdo->prepare("SELECT COUNT(*) n, COUNT(DISTINCT agente) ag, COALESCE(SUM(costo_usd),0) costo,
                             COALESCE(SUM(tokens_in+tokens_out),0) toks
                      FROM crecer_ia_log WHERE marca_id=? AND estado='ok'");
$tot->execute([$marca_id]); $T = $tot->fetch();

$posts_creados = (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$marca_id} AND caption<>''")->fetchColumn();
$posts_pub     = (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$marca_id} AND estado='publicado'")->fetchColumn();
$msgs_cli      = (int)$pdo->query("SELECT COUNT(*) FROM crecer_ia_log WHERE marca_id={$marca_id} AND agente='retencion' AND estado='ok'")->fetchColumn();

// Desglose por agente
$desg = $pdo->prepare("SELECT agente, COUNT(*) n, MAX(created_at) ult, COALESCE(SUM(costo_usd),0) costo
                       FROM crecer_ia_log WHERE marca_id=? AND estado='ok' GROUP BY agente ORDER BY n DESC");
$desg->execute([$marca_id]); $desglose = $desg->fetchAll();

// Feed crudo: últimas acciones (la evidencia)
$feed = $pdo->prepare("SELECT agente, accion, modelo, tokens_in, tokens_out, costo_usd, created_at, LEFT(respuesta,160) resp
                       FROM crecer_ia_log WHERE marca_id=? ORDER BY id DESC LIMIT 40");
$feed->execute([$marca_id]); $eventos = $feed->fetchAll();

// Publicaciones (cuando se publique por API)
$pubs = $pdo->prepare("SELECT plataforma, estado, permalink, created_at FROM crecer_publicaciones WHERE marca_id=? ORDER BY id DESC LIMIT 10");
$pubs->execute([$marca_id]); $publicaciones = $pubs->fetchAll();

// Aggregate de TODO el sistema (escala, sin exponer datos de otras marcas)
$sis = $pdo->query("SELECT COUNT(*) n, COUNT(DISTINCT marca_id) marcas, COALESCE(SUM(costo_usd),0) costo FROM crecer_ia_log WHERE estado='ok'")->fetch();

$active = 'evidencia';
$page_title = 'Evidencia del Corillo';
require __DIR__ . '/_shell.php';
?>
<style>
  .ev-wrap{max-width:860px}
  .ev-lede{color:var(--muted);font-size:14.5px;margin:2px 0 18px;max-width:60ch}
  .ev-sys{display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:linear-gradient(135deg,#241633,#0e0a16);color:#fff;border-radius:14px;padding:14px 18px;margin-bottom:16px;font-size:14px}
  .ev-sys b{color:#c9b8ff}
  .ev-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:18px}
  .ev-kpi{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:15px;box-shadow:var(--shadow-sm)}
  .ev-kpi .v{font-family:var(--font-impact,'Poppins');font-size:30px;color:var(--tinta);line-height:1}
  .ev-kpi .l{font-size:12px;color:var(--muted);font-weight:700;margin-top:4px}
  .ev-card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px;margin-bottom:16px;box-shadow:var(--shadow-sm)}
  .ev-card h2{font-size:15px;font-weight:800;color:var(--tinta);margin:0 0 14px;display:flex;align-items:center;gap:8px}
  .ev-ag{display:flex;align-items:center;gap:11px;padding:9px 0;border-bottom:1px solid var(--line)}
  .ev-ag:last-child{border-bottom:0}
  .ev-ag .e{font-size:20px}.ev-ag .nm{font-weight:800;color:var(--tinta);font-size:14px}
  .ev-ag .ro{font-size:12px;color:var(--muted)}
  .ev-ag .n{margin-left:auto;text-align:right}.ev-ag .n b{font-size:17px;color:var(--tinta)}
  .ev-ag .n span{display:block;font-size:11px;color:var(--muted)}
  .ev-ev{border-left:2px solid color-mix(in srgb,var(--terracota) 40%,#fff);padding:0 0 14px 14px;position:relative;margin-left:6px}
  .ev-ev::before{content:'';position:absolute;left:-6px;top:3px;width:10px;height:10px;border-radius:50%;background:var(--terracota)}
  .ev-ev:last-child{padding-bottom:0}
  .ev-ev .top{display:flex;align-items:baseline;gap:8px;flex-wrap:wrap}
  .ev-ev .who{font-weight:800;color:var(--tinta);font-size:13.5px}
  .ev-ev .act{color:var(--muted);font-size:13px}
  .ev-ev .t{margin-left:auto;font-size:11.5px;color:var(--muted)}
  .ev-ev .resp{font-size:12.5px;color:#473b46;background:var(--crema);border-radius:8px;padding:7px 10px;margin-top:5px;line-height:1.4}
  .ev-ev .meta{font-size:11px;color:var(--muted);margin-top:4px;font-family:ui-monospace,monospace}
  .ev-note{font-size:12px;color:var(--muted);margin-top:6px}
  .ev-empty{color:var(--muted);font-size:13.5px}
</style>

<div class="ev-wrap">
  <h1 class="page-h" style="margin-bottom:4px">🔍 Evidencia del Corillo</h1>
  <p class="ev-lede">Cada cosa que la IA decide y hace por tu negocio queda registrada. Esto es la prueba — cruda y real — de que el corillo opera, no solo asiste.</p>

  <div class="ev-sys">🌎 En total, el corillo ha ejecutado <b><?= number_format((int)$sis['n']) ?></b> acciones de IA para <b><?= (int)$sis['marcas'] ?></b> negocios.</div>

  <div class="ev-grid">
    <div class="ev-kpi"><div class="v"><?= number_format((int)$T['n']) ?></div><div class="l">Acciones de IA (este negocio)</div></div>
    <div class="ev-kpi"><div class="v"><?= (int)$T['ag'] ?></div><div class="l">Agentes que trabajaron</div></div>
    <div class="ev-kpi"><div class="v"><?= $posts_creados ?></div><div class="l">Posts creados por IA</div></div>
    <div class="ev-kpi"><div class="v"><?= $msgs_cli ?></div><div class="l">Mensajes a clientes</div></div>
    <div class="ev-kpi"><div class="v">$<?= number_format((float)$T['costo'],2) ?></div><div class="l">Costo IA acumulado</div></div>
  </div>

  <?php if (!empty($marca['autopilot'])): ?>
  <div class="ev-card" style="border-color:color-mix(in srgb,var(--terracota) 30%,#fff)">
    <h2>🌙 Operación autónoma (sin humano)</h2>
    <p style="margin:0;font-size:13.5px;color:var(--tinta)">El piloto automático está <b>ON</b>: el corillo planifica y redacta posts <b>solo</b>, por cron, y se los deja al dueño para aprobar.
      <?php if (!empty($marca['autopilot_ultimo'])): ?> Última corrida autónoma: <b><?= $h(date('d/m/Y H:i', strtotime($marca['autopilot_ultimo']))) ?></b>.<?php endif; ?>
    </p>
  </div>
  <?php endif; ?>

  <div class="ev-card">
    <h2>👥 Qué hizo cada agente</h2>
    <?php if (!$desglose): ?><p class="ev-empty">Todavía no hay actividad registrada.</p><?php else: foreach ($desglose as $d):
      [$e,$nm,$ro] = $agf($d['agente']); ?>
      <div class="ev-ag">
        <span class="e"><?= $e ?></span>
        <div><div class="nm"><?= $h($nm) ?></div><div class="ro"><?= $h($ro) ?> · activo <?= $h($ago($d['ult'])) ?></div></div>
        <div class="n"><b><?= (int)$d['n'] ?></b><span>$<?= number_format((float)$d['costo'],4) ?></span></div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <div class="ev-card">
    <h2>⚡ Lo que el corillo ejecutó (en vivo)</h2>
    <?php if (!$eventos): ?><p class="ev-empty">Aún no hay acciones. Pon el corillo a trabajar y aparecen aquí.</p>
    <?php else: foreach ($eventos as $ev): [$e,$nm] = $agf($ev['agente']); ?>
      <div class="ev-ev">
        <div class="top">
          <span class="who"><?= $e ?> <?= $h($nm) ?></span>
          <span class="act"><?= $h($ev['accion']) ?></span>
          <span class="t"><?= $h($ago($ev['created_at'])) ?></span>
        </div>
        <?php if (trim((string)$ev['resp'])!==''): ?><div class="resp"><?= $h(mb_strimwidth(trim($ev['resp']),0,150,'…')) ?></div><?php endif; ?>
        <div class="meta"><?= $h($ev['modelo']) ?> · <?= (int)$ev['tokens_in'] ?>+<?= (int)$ev['tokens_out'] ?> tokens · $<?= number_format((float)$ev['costo_usd'],5) ?></div>
      </div>
    <?php endforeach; endif; ?>
    <p class="ev-note">Se actualiza cada vez que el corillo trabaja. Cada fila es una llamada real a Gemini, registrada en <code>crecer_ia_log</code>.</p>
  </div>

  <div class="ev-card">
    <h2>📲 Publicaciones a redes</h2>
    <?php if (!$publicaciones): ?>
      <p class="ev-empty">Cuando el corillo publique a Instagram/Facebook (redes conectadas), cada publicación queda registrada aquí con su enlace.</p>
    <?php else: foreach ($publicaciones as $pb): ?>
      <div class="ev-ag">
        <span class="e"><?= $pb['plataforma']==='instagram'?'📸':'👍' ?></span>
        <div><div class="nm"><?= $h(ucfirst($pb['plataforma'])) ?> · <?= $pb['estado']==='ok'?'publicado':'falló' ?></div>
          <div class="ro"><?= $h($ago($pb['created_at'])) ?></div></div>
        <?php if ($pb['permalink']): ?><a class="ro" style="margin-left:auto;color:var(--terracota);font-weight:700;text-decoration:none" href="<?= $h($pb['permalink']) ?>" target="_blank" rel="noopener">ver post →</a><?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<?php require __DIR__ . '/_shell_foot.php'; ?>
