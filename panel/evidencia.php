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
require_once __DIR__ . '/../includes/relevo_demo.php';
requiere_login();

$usuario = usuario_actual($pdo);
$USUARIO_ID = (int)$usuario['id'];
$es_admin  = (($usuario['rol'] ?? '') === 'admin');
$req_marca = isset($_GET['marca']) ? (int)$_GET['marca'] : null;
// EVIDENCIA TÉCNICA — protegida (tokens, costos, modelos). Es la prueba del
// criterio #2 del XPRIZE, para admin/jurado. El cliente ve la versión humana
// en actividad.php. El ADMIN puede inspeccionar CUALQUIER marca (para grabar
// la demo en vivo); el cliente solo la suya.
if ($es_admin && $req_marca) {
    $marca = $pdo->query("SELECT * FROM crecer_marca WHERE id=" . $req_marca)->fetch(PDO::FETCH_ASSOC) ?: null;
} else {
    $marca = marca_del_usuario($pdo, $USUARIO_ID, $req_marca);
}
if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }
$marca_id = (int)$marca['id'];
if (!$es_admin) {
    header('Location: /crecer/panel/actividad.php?marca=' . $marca_id);
    exit;
}

// ── DEMO EN VIVO del relevo (criterio #2) ─────────────────────
// Nombres humanos de cada agente para el feed en vivo (el jurado ve quién hace qué).
$RELEVO_HUMANO = [
    'aprendiz'      => ['bookmark', 'El Aprendiz',   'aprende tu línea visual'],
    'estratega'     => ['compass',  'La Estratega',  'fija el enfoque de la semana'],
    'planificador'  => ['calendar', 'La Estratega',  'planifica el contenido'],
    'provocador'    => ['sparkles', 'El Provocador', 'lanza ángulos atrevidos'],
    'escritor'      => ['pen',      'La Creativa',   'escribe el post en tu voz'],
    'creador'       => ['pen',      'La Creativa',   'escribe el post'],
    'editor'        => ['pen',      'La Creativa',   'pule el texto'],
    'diseñador'     => ['image',    'El Diseñador',  'concibe el arte'],
    'director_imagen'=> ['image',   'El Diseñador',  'dirige la imagen'],
    'analitica'     => ['chart',    'El Analista',   'cierra con los números'],
    'gerente'       => ['users',    'El Gerente',    'reparte el trabajo'],
];
// Arranca el relevo en background y devuelve la marca de agua (último id de log).
if (($_GET['ajax'] ?? '') === 'relevo_start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (function_exists('csrf_ok') && !csrf_ok()) { echo json_encode(['ok' => false, 'err' => 'csrf']); exit; }
    $baseline = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM crecer_ia_log WHERE marca_id={$marca_id}")->fetchColumn();
    relevo_marcar($pdo, $marca_id, 'relevo_inicio');
    relevo_disparar($marca_id);
    echo json_encode(['ok' => true, 'baseline' => $baseline]); exit;
}
// Sondeo: entrega los logs nuevos (desde 'since') + si el relevo ya terminó.
if (($_GET['ajax'] ?? '') === 'relevo_feed') {
    header('Content-Type: application/json; charset=utf-8');
    $since = (int)($_GET['since'] ?? 0);
    $q = $pdo->prepare("SELECT id, agente, accion, modelo, tokens_in, tokens_out, costo_usd, estado, created_at
                        FROM crecer_ia_log WHERE marca_id=? AND id>? ORDER BY id ASC LIMIT 120");
    $q->execute([$marca_id, $since]);
    $rows = []; $done = false; $maxid = $since; $creadas = null;
    foreach ($q as $r) {
        $maxid = max($maxid, (int)$r['id']);
        if ($r['agente'] === 'kernel') {
            if (strpos((string)$r['accion'], 'relevo_fin') === 0) { $done = true; }
            continue;   // los marcadores no se muestran
        }
        $info = $RELEVO_HUMANO[$r['agente']] ?? ['settings', ucfirst((string)$r['agente']), (string)$r['accion']];
        $rows[] = [
            'id'     => (int)$r['id'],
            'icono'  => $info[0],
            'nombre' => $info[1],
            'hace'   => $info[2],
            'accion' => (string)$r['accion'],
            'modelo' => (string)$r['modelo'],
            'tokens' => (int)$r['tokens_in'] + (int)$r['tokens_out'],
            'costo'  => (float)$r['costo_usd'],
            'estado' => (string)$r['estado'],
            'hora'   => date('g:i:s A', strtotime((string)$r['created_at'])),
        ];
    }
    // Si terminó, saca cuántos posts creó (leyendo el marcador con su respuesta).
    if ($done && $creadas === null) {
        $mk = $pdo->prepare("SELECT respuesta FROM crecer_ia_log WHERE marca_id=? AND agente='kernel' AND accion LIKE 'relevo_fin%' ORDER BY id DESC LIMIT 1");
        $mk->execute([$marca_id]);
        $j = json_decode((string)$mk->fetchColumn(), true);
        $creadas = is_array($j) ? ($j['creadas'] ?? 0) : 0;
    }
    echo json_encode(['ok' => true, 'rows' => $rows, 'maxid' => $maxid, 'done' => $done, 'creadas' => $creadas], JSON_UNESCAPED_UNICODE);
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
    'planificador'=>['calendar','El Estratega','planificó contenido'],
    'creador'=>['pen','La Creativa','escribió un caption'],
    'diseñador'=>['palette','El Diseñador','creó un arte'],
    'intake'=>['lightbulb','El Intake','aprendió el negocio'],
    'asistente'=>['chat','El Asistente','resolvió una duda'],
    'retencion'=>['users','El Vendedor','escribió a un cliente'],
    'analitica'=>['chart','El Analista','resumió el mes'],
    'aprendiz'=>['bookmark','El Aprendiz','aprendió vocabulario'],
    'editor'=>['pen','El Editor','ajustó un texto'],
];
$agf = fn($a) => $nombres_agente[$a] ?? ['settings', ucfirst($a), 'ejecutó una acción'];

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

  /* ── DEMO EN VIVO: el corillo trabajando en tiempo real (el money-shot del video) ── */
  .lv{background:linear-gradient(135deg,#1a1030,#0b0814);color:#fff;border-radius:18px;padding:20px;margin-bottom:20px;box-shadow:0 20px 50px -20px rgba(80,40,160,.6)}
  .lv-top{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
  .lv-top h2{font-family:'Poppins',sans-serif;font-size:16px;font-weight:800;margin:0;color:#fff;display:flex;align-items:center;gap:8px}
  .lv-top h2 svg{width:19px;height:19px;color:#c9b8ff}
  .lv-sub{font-size:12.5px;color:#b8add6;margin:6px 0 0;max-width:60ch}
  .lv-go{margin-left:auto;border:0;cursor:pointer;font-family:'Poppins',sans-serif;font-weight:700;font-size:14px;color:#1a1030;
    background:linear-gradient(135deg,#f0c85a,#ec7a4d);padding:13px 22px;border-radius:13px;display:inline-flex;align-items:center;gap:8px;
    box-shadow:0 10px 26px -10px rgba(240,160,80,.6);transition:transform .15s ease,box-shadow .15s ease,opacity .2s}
  .lv-go svg{width:16px;height:16px}
  .lv-go:hover{transform:translateY(-2px)}
  .lv-go:disabled{opacity:.55;cursor:default;transform:none}
  .lv-live{display:none;align-items:center;gap:7px;margin-left:auto;font-size:12.5px;font-weight:700;color:#ffd0d0}
  .lv-live.on{display:inline-flex}
  .lv-live .dot{width:9px;height:9px;border-radius:50%;background:#ff5a5a;box-shadow:0 0 0 0 rgba(255,90,90,.7);animation:lvpulse 1.4s infinite}
  @keyframes lvpulse{0%{box-shadow:0 0 0 0 rgba(255,90,90,.7)}70%{box-shadow:0 0 0 10px rgba(255,90,90,0)}100%{box-shadow:0 0 0 0 rgba(255,90,90,0)}}
  .lv-stats{display:none;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:16px}
  .lv-stats.on{display:grid}
  .lv-stat{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:12px 14px;text-align:center}
  .lv-stat .v{font-family:'Poppins',sans-serif;font-size:24px;font-weight:800;color:#fff;line-height:1;font-variant-numeric:tabular-nums}
  .lv-stat .l{font-size:10.5px;color:#b8add6;font-weight:700;margin-top:5px;text-transform:uppercase;letter-spacing:.04em}
  .lv-feed{margin-top:16px;display:flex;flex-direction:column;gap:9px}
  .lv-row{display:flex;align-items:center;gap:12px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:11px 13px;
    opacity:0;transform:translateY(10px);animation:lvin .4s cubic-bezier(.22,1,.36,1) forwards}
  @keyframes lvin{to{opacity:1;transform:translateY(0)}}
  .lv-row .ic{width:34px;height:34px;flex:none;border-radius:9px;display:grid;place-items:center;background:rgba(201,184,255,.16);color:#c9b8ff}
  .lv-row .ic svg{width:18px;height:18px}
  .lv-row .tx{min-width:0;flex:1}
  .lv-row .nm{font-weight:800;font-size:13.5px;color:#fff}
  .lv-row .ac{font-size:12px;color:#b8add6;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .lv-row .mt{margin-left:auto;text-align:right;flex:none}
  .lv-row .mt .mo{font-size:10.5px;color:#9a8fc0;font-family:ui-monospace,monospace}
  .lv-row .mt .tk{font-size:11.5px;color:#e9e2ff;font-variant-numeric:tabular-nums}
  .lv-done{display:none;margin-top:16px;background:rgba(120,220,160,.12);border:1px solid rgba(120,220,160,.3);border-radius:12px;padding:14px 16px;font-size:13.5px;color:#d7ffe6}
  .lv-done.on{display:block}
  .lv-done a{color:#8ff0b8;font-weight:800;text-decoration:none}
  @media (prefers-reduced-motion: reduce){.lv-row{animation:none;opacity:1;transform:none}.lv-live .dot{animation:none}}
</style>

<div class="ev-wrap">
  <h1 class="page-h" style="margin-bottom:4px"><?= ico('compass') ?> Evidencia del Corillo</h1>
  <p class="ev-lede">Cada cosa que la IA decide y hace por tu negocio queda registrada. Esto es la prueba — cruda y real — de que el corillo opera, no solo asiste.</p>

  <!-- DEMO EN VIVO: oprime y mira al corillo ejecutar decisiones en tiempo real -->
  <section class="lv" id="lv">
    <div class="lv-top">
      <div>
        <h2><?= ico('bolt') ?> El corillo, en vivo</h2>
        <p class="lv-sub">Oprime y mira al equipo de IA trabajar en tiempo real: cada agente, su decisión, el modelo, los tokens y el costo — según ocurre, en producción.</p>
      </div>
      <button type="button" class="lv-go" id="lvGo"><?= ico('play') ?> Correr el corillo ahora</button>
      <span class="lv-live" id="lvLive"><span class="dot"></span> EN VIVO</span>
    </div>
    <div class="lv-stats" id="lvStats">
      <div class="lv-stat"><div class="v" id="lvAcc">0</div><div class="l">Acciones IA</div></div>
      <div class="lv-stat"><div class="v" id="lvTok">0</div><div class="l">Tokens</div></div>
      <div class="lv-stat"><div class="v" id="lvCost">$0.00</div><div class="l">Costo real</div></div>
    </div>
    <div class="lv-feed" id="lvFeed"></div>
    <div class="lv-done" id="lvDone"></div>
  </section>

  <div class="ev-sys">En total, el corillo ha ejecutado <b><?= number_format((int)$sis['n']) ?></b> acciones de IA para <b><?= (int)$sis['marcas'] ?></b> negocios.</div>

  <div class="ev-grid">
    <div class="ev-kpi"><div class="v"><?= number_format((int)$T['n']) ?></div><div class="l">Acciones de IA (este negocio)</div></div>
    <div class="ev-kpi"><div class="v"><?= (int)$T['ag'] ?></div><div class="l">Agentes que trabajaron</div></div>
    <div class="ev-kpi"><div class="v"><?= $posts_creados ?></div><div class="l">Posts creados por IA</div></div>
    <div class="ev-kpi"><div class="v"><?= $msgs_cli ?></div><div class="l">Mensajes a clientes</div></div>
    <div class="ev-kpi"><div class="v">$<?= number_format((float)$T['costo'],2) ?></div><div class="l">Costo IA acumulado</div></div>
  </div>

  <?php if (!empty($marca['autopilot'])): ?>
  <div class="ev-card" style="border-color:color-mix(in srgb,var(--terracota) 30%,#fff)">
    <h2>Operación autónoma (sin humano)</h2>
    <p style="margin:0;font-size:13.5px;color:var(--tinta)">El piloto automático está <b>ON</b>: el corillo planifica y redacta posts <b>solo</b>, por cron, y se los deja al dueño para aprobar.
      <?php if (!empty($marca['autopilot_ultimo'])): ?> Última corrida autónoma: <b><?= $h(date('d/m/Y H:i', strtotime($marca['autopilot_ultimo']))) ?></b>.<?php endif; ?>
    </p>
  </div>
  <?php endif; ?>

  <div class="ev-card">
    <h2><?= ico('users') ?> Qué hizo cada agente</h2>
    <?php if (!$desglose): ?><p class="ev-empty">Todavía no hay actividad registrada.</p><?php else: foreach ($desglose as $d):
      [$e,$nm,$ro] = $agf($d['agente']); ?>
      <div class="ev-ag">
        <span class="e"><?= ico($e) ?></span>
        <div><div class="nm"><?= $h($nm) ?></div><div class="ro"><?= $h($ro) ?> · activo <?= $h($ago($d['ult'])) ?></div></div>
        <div class="n"><b><?= (int)$d['n'] ?></b><span>$<?= number_format((float)$d['costo'],4) ?></span></div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <div class="ev-card">
    <h2><?= ico('bolt') ?> Lo que el corillo ejecutó (en vivo)</h2>
    <?php if (!$eventos): ?><p class="ev-empty">Aún no hay acciones. Pon el corillo a trabajar y aparecen aquí.</p>
    <?php else: foreach ($eventos as $ev): [$e,$nm] = $agf($ev['agente']); ?>
      <div class="ev-ev">
        <div class="top">
          <span class="who"><?= ico($e) ?> <?= $h($nm) ?></span>
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
    <h2><?= ico('send') ?> Publicaciones a redes</h2>
    <?php if (!$publicaciones): ?>
      <p class="ev-empty">Cuando el corillo publique a Instagram/Facebook (redes conectadas), cada publicación queda registrada aquí con su enlace.</p>
    <?php else: foreach ($publicaciones as $pb): ?>
      <div class="ev-ag">
        <span class="e"><?= $pb['plataforma']==='instagram'?ico('instagram'):ico('facebook') ?></span>
        <div><div class="nm"><?= $h(ucfirst($pb['plataforma'])) ?> · <?= $pb['estado']==='ok'?'publicado':'falló' ?></div>
          <div class="ro"><?= $h($ago($pb['created_at'])) ?></div></div>
        <?php if ($pb['permalink']): ?><a class="ro" style="margin-left:auto;color:var(--terracota);font-weight:700;text-decoration:none" href="<?= $h($pb['permalink']) ?>" target="_blank" rel="noopener">ver post →</a><?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<script>
(function(){
  var CSRF = <?= json_encode(csrf_token()) ?>;
  var go=document.getElementById('lvGo'), live=document.getElementById('lvLive'),
      stats=document.getElementById('lvStats'), feed=document.getElementById('lvFeed'), doneBox=document.getElementById('lvDone'),
      elAcc=document.getElementById('lvAcc'), elTok=document.getElementById('lvTok'), elCost=document.getElementById('lvCost');
  if(!go) return;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // SVG de cada agente (para el feed en vivo, sin depender de ico() en PHP).
  var A='viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';
  var ICON={
    bookmark:'<path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/>',
    compass:'<circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/>',
    calendar:'<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
    sparkles:'<path d="m12 3 1.9 4.1L18 9l-4.1 1.9L12 15l-1.9-4.1L6 9l4.1-1.9z"/><path d="M19 14l.6 1.4 1.4.6-1.4.6-.6 1.4-.6-1.4-1.4-.6 1.4-.6z"/>',
    pen:'<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
    image:'<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>',
    chart:'<path d="M3 3v18h18"/><path d="M7 15l3-3 3 2 5-6"/>',
    users:'<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    settings:'<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>'
  };
  function svg(n){ return '<svg '+A+'>'+(ICON[n]||ICON.settings)+'</svg>'; }

  var since=0, accN=0, tokN=0, costN=0, polls=0, timer=null, MAXPOLL=170;

  function bump(el,val){ el.textContent=val; if(!reduce){ el.style.transform='scale(1.18)'; setTimeout(function(){ el.style.transition='transform .2s ease'; el.style.transform='scale(1)'; },30); } }

  function addRow(r){
    var row=document.createElement('div'); row.className='lv-row';
    row.innerHTML='<span class="ic">'+svg(r.icono)+'</span>'+
      '<div class="tx"><div class="nm">'+esc(r.nombre)+'</div><div class="ac">'+esc(r.hace||r.accion)+'</div></div>'+
      '<div class="mt"><div class="tk">'+ (r.tokens>0 ? r.tokens.toLocaleString()+' tok' : '—') +'</div><div class="mo">'+esc(r.modelo||'')+'</div></div>';
    feed.appendChild(row);
    accN++; tokN+=(r.tokens||0); costN+=(r.costo||0);
    bump(elAcc, accN.toLocaleString());
    bump(elTok, tokN.toLocaleString());
    bump(elCost, '$'+costN.toFixed(4));
  }
  function esc(s){ var d=document.createElement('div'); d.textContent=(s==null?'':String(s)); return d.innerHTML; }

  function poll(){
    polls++;
    fetch(location.pathname+'?marca=<?= (int)$marca_id ?>&ajax=relevo_feed&since='+since, {headers:{'X-Requested-With':'fetch'}})
      .then(function(r){return r.json();}).then(function(d){
        if(d && d.ok){
          (d.rows||[]).forEach(addRow);
          if(d.maxid>since) since=d.maxid;
          if(d.done){ finish(d.creadas); return; }
        }
        if(polls>=MAXPOLL){ finish(null, true); return; }
        timer=setTimeout(poll, 1500);
      }).catch(function(){
        if(polls>=MAXPOLL){ finish(null, true); return; }
        timer=setTimeout(poll, 2200);
      });
  }

  function finish(creadas, timeout){
    live.classList.remove('on');
    go.disabled=false; go.innerHTML='<svg '+A+'><path d="M3 12a9 9 0 1 0 9-9"/><path d="M3 3v6h6"/></svg> Correr otra vez';
    var link='<?= $BASE ?>/propuestas.php?marca=<?= (int)$marca_id ?>';
    if(timeout){
      doneBox.innerHTML='El relevo sigue corriendo por detrás. Refresca en un momento para ver el cierre — todo queda en <code>crecer_ia_log</code>.';
    } else if(creadas>0){
      doneBox.innerHTML='Listo. El corillo dejó <b>'+creadas+'</b> post'+(creadas==1?'':'s')+' nuevo'+(creadas==1?'':'s')+' para aprobar. <a href="'+link+'">Ver en Propuestas →</a>';
    } else {
      doneBox.innerHTML='El corillo revisó y ya tenías suficientes borradores — no amontonó trabajo. Cada decisión quedó registrada arriba.';
    }
    doneBox.classList.add('on');
  }

  go.addEventListener('click', function(){
    go.disabled=true; go.innerHTML='<svg '+A+'><circle cx="12" cy="12" r="10"/></svg> Arrancando…';
    feed.innerHTML=''; doneBox.classList.remove('on'); doneBox.innerHTML='';
    accN=tokN=costN=polls=0; elAcc.textContent='0'; elTok.textContent='0'; elCost.textContent='$0.00';
    stats.classList.add('on');
    var fd=new FormData(); fd.append('csrf',CSRF);
    fetch(location.pathname+'?marca=<?= (int)$marca_id ?>&ajax=relevo_start', {method:'POST', body:fd})
      .then(function(r){return r.json();}).then(function(d){
        if(!d || !d.ok){ go.disabled=false; go.textContent='Correr el corillo ahora'; doneBox.innerHTML='No se pudo arrancar. Intenta otra vez.'; doneBox.classList.add('on'); return; }
        since=d.baseline||0; live.classList.add('on');
        timer=setTimeout(poll, 1200);
      }).catch(function(){ go.disabled=false; doneBox.innerHTML='Error de conexión.'; doneBox.classList.add('on'); });
  });
})();
</script>

<?php require __DIR__ . '/_shell_foot.php'; ?>
