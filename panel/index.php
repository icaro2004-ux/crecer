<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Inicio (máquina de estados)
//  panel/index.php  ·  una sola próxima acción según hechos reales.
//  Estados A–F (ver _WIREFRAME-FLUJO.md §4). Sin estados fingidos.
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

// ── Hechos verificables ──────────────────────────────────────
// Conteo de contenido por estado (un query).
$cuenta = ['borrador'=>0,'aprobado'=>0,'programado'=>0,'publicando'=>0,'publicado'=>0,'fallido'=>0,'rechazado'=>0];
$cq = $pdo->prepare("SELECT estado, COUNT(*) n FROM crecer_contenido WHERE marca_id=? GROUP BY estado");
$cq->execute([$marca_id]);
foreach ($cq->fetchAll() as $r) { if (isset($cuenta[$r['estado']])) $cuenta[$r['estado']] = (int)$r['n']; }

// Métricas del centro de mando (definiciones exactas en includes/metricas.php).
$prod     = metricas_produccion($pdo, $marca_id);   // creados/esperando/listos/publicados del mes
$racha    = metricas_racha($pdo, $marca_id);         // semanas consecutivas publicando
$proximos = metricas_proximos($pdo, $marca_id, 3);   // próximos posts programados
$obs      = metrica_observacion($prod, $racha);      // observación (solo hechos)

// Plan activo de la marca.
$susc = suscripcion_de_marca($pdo, $marca_id);
$plan = suscripcion_activa($susc) ? ($susc['plan_slug'] ?? null) : null;

// ¿Meta conectado? (hay una conexión activa en crecer_conexiones).
$meta_ok = false;
try { $meta_ok = (bool)$pdo->query("SELECT 1 FROM crecer_conexiones WHERE marca_id={$marca_id} AND estado='activa' LIMIT 1")->fetchColumn(); }
catch (Throwable $e) { $meta_ok = false; }

// Próximo post programado (estado D).
$prox_fecha = null;
try {
    $pq = $pdo->prepare("SELECT fecha_programada FROM crecer_contenido WHERE marca_id=? AND estado='programado' AND fecha_programada IS NOT NULL ORDER BY fecha_programada ASC LIMIT 1");
    $pq->execute([$marca_id]); $prox_fecha = $pq->fetchColumn() ?: null;
} catch (Throwable $e) {}

if (!function_exists('_fecha_humana')) {
    function _fecha_humana($f) {
        $ts = strtotime((string)$f); if (!$ts) return '';
        $dias = ['Sunday'=>'domingo','Monday'=>'lunes','Tuesday'=>'martes','Wednesday'=>'miércoles','Thursday'=>'jueves','Friday'=>'viernes','Saturday'=>'sábado'];
        $d = $dias[date('l', $ts)] ?? '';
        $hora = date('g:i A', $ts);
        $fd = date('Y-m-d', $ts);
        if ($fd === date('Y-m-d')) return "hoy a las {$hora}";
        if ($fd === date('Y-m-d', strtotime('+1 day'))) return "mañana a las {$hora}";
        return "el {$d} a las {$hora}";
    }
}

// ── MÁQUINA DE ESTADOS (el primero que aplica gana) ──────────
$BASE = '/crecer/panel';
$mid  = "marca={$marca_id}";
// pipe: índice del nodo activo (0=Negocio · 1=Contenido · 2=Tu OK · 3=Publicado)
if (!$plan) {
    // A — muestra lista, sin plan activo
    $st = ['k'=>'A','tono'=>'sell','ico'=>'sparkles','pipe'=>2,
        'titulo'=>'Tu primer post está listo.',
        'sub'=>'Actívalo y el corillo te prepara contenido nuevo cada semana, en tu propia voz.',
        'cta'=>'Activar Crecer','href'=>"{$BASE}/precios.php?{$mid}"];
} elseif ($cuenta['fallido'] > 0) {
    // E — falló una publicación
    $n = $cuenta['fallido'];
    $st = ['k'=>'E','tono'=>'warn','ico'=>'bolt','pipe'=>3,
        'titulo'=> $n==1 ? 'Un post no se pudo publicar.' : "{$n} posts no se pudieron publicar.",
        'sub'=>'Revisa qué pasó y vuelve a intentarlo.',
        'cta'=>'Resolver','href'=>"{$BASE}/aprobar2.php?tab=aprobados&{$mid}"];
} elseif ($cuenta['borrador'] > 0) {
    // B — borradores esperando tu OK
    $n = $cuenta['borrador'];
    $st = ['k'=>'B','tono'=>'hot','ico'=>'check-circle','pipe'=>2,
        'titulo'=>"Tienes {$n} post".($n==1?'':'s')." listo".($n==1?'':'s')." para tu OK.",
        'sub'=>'Revísalos y aprueba lo que te guste — toma segundos desde el celular.',
        'cta'=>'Revisar y aprobar','href'=>"{$BASE}/aprobar2.php?tab=pendientes&{$mid}"];
} elseif ($cuenta['aprobado'] > 0) {
    // C — aprobados listos para publicar
    $st = ['k'=>'C','tono'=>'ok','ico'=>'image','pipe'=>3,
        'titulo'=>'Tus posts están listos para publicar.',
        'sub'=> $meta_ok ? 'Publícalos a tus redes cuando quieras.' : 'Conecta tus redes para publicarlos en un toque.',
        'cta'=> $meta_ok ? 'Ver aprobados' : 'Conectar redes',
        'href'=> $meta_ok ? "{$BASE}/aprobar2.php?tab=aprobados&{$mid}" : "{$BASE}/conectar.php?{$mid}"];
} elseif ($cuenta['programado'] > 0) {
    // D — programados / al día
    $cuando = $prox_fecha ? _fecha_humana($prox_fecha) : '';
    $st = ['k'=>'D','tono'=>'ok','ico'=>'calendar','pipe'=>3,
        'titulo'=>'Todo al día.',
        'sub'=> $cuando ? "Tu próximo post sale {$cuando}." : 'Tienes posts programados.',
        'cta'=>'Ver lo programado','href'=>"{$BASE}/calendario.php?{$mid}"];
} else {
    // F — nada pendiente
    $st = ['k'=>'F','tono'=>'ok','ico'=>'sparkles','pipe'=>1,
        'titulo'=>'Todo al día.',
        'sub'=>'El corillo no tiene nada pendiente ahora mismo. ¿Quieres pedir contenido nuevo?',
        'cta'=>'Pedir contenido','href'=>"{$BASE}/aprobar2.php?{$mid}"];
}

// ── Actividad del corillo (feed HUMANO — sin tokens/costos/modelos) ──
$feed_map = [
  'planificador'=>['estratega','La Estratega','cuadró el plan del mes'],
  'creador'     =>['creativa','La Creativa','escribió contenido nuevo'],
  'diseñador'   =>['disenador','El Diseñador','preparó un arte'],
  'analitica'   =>['analista','El Analista','revisó tus números'],
  'intake'      =>['estratega','La Estratega','aprendió de tu negocio'],
  'aprendiz'    =>['creativa','La Creativa','aprendió tu vocabulario'],
  'editor'      =>['creativa','La Creativa','pulió un texto'],
];
$fq = $pdo->prepare("SELECT agente, created_at FROM crecer_ia_log WHERE marca_id=? AND estado='ok' ORDER BY id DESC LIMIT 5");
$fq->execute([$marca_id]);
$feed = $fq->fetchAll();

// Etiqueta de día en boricua: Hoy / Ayer / "5 jul" / "5/1/25".
$_meses_ab = [1=>'ene',2=>'feb',3=>'mar',4=>'abr',5=>'may',6=>'jun',7=>'jul',8=>'ago',9=>'sep',10=>'oct',11=>'nov',12=>'dic'];
$dia_rel = function ($ts) use ($_meses_ab) {
    $d = strtotime((string)$ts); if (!$d) return '';
    if ($d >= strtotime('today'))     return 'Hoy';
    if ($d >= strtotime('yesterday')) return 'Ayer';
    if (date('Y', $d) === date('Y'))  return (int)date('j', $d) . ' ' . $_meses_ab[(int)date('n', $d)];
    return date('d/m/y', $d);
};

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$ICON = '/crecer/assets/icons';

$active = 'inicio';
$page_title = 'Inicio';
$guia = ['key'=>'inicio','agente'=>'sparkles','titulo'=>'Tu punto de partida',
  'intro'=>'Aquí ves qué hizo el corillo, qué necesita de ti y qué sigue. Una sola cosa a la vez.',
  'pasos'=>[
    ['check-circle','El bloque grande te dice tu próximo paso. Dale y listo.'],
    ['calendar','En "Contenido" revisas, apruebas y ves tu calendario.'],
    ['palette','En "Mi marca" ajustas tu voz, logo y fotos.'],
  ]];
require __DIR__ . '/_shell.php';

// Nodos del pipeline
$nodos = ['Negocio','Contenido','Tu OK','Publicado'];
?>
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&display=swap" rel="stylesheet">
<style>
  .content{max-width:880px}
  .ix-hi h1{font-family:'Oswald',sans-serif;font-weight:700;text-transform:uppercase;font-size:clamp(26px,4.6vw,40px);line-height:1.04;letter-spacing:.4px;margin:0;color:var(--tinta)}
  .ix-hi h1 span{color:var(--terracota)}
  .ix-hi .sub{font-size:15px;color:var(--muted);margin:7px 0 0}

  /* Bloque de estado (una sola próxima acción) */
  .ix-state{margin-top:20px;border-radius:var(--r-lg);padding:26px 26px 24px;position:relative;overflow:hidden;
    border:1px solid var(--line);background:var(--card);box-shadow:var(--shadow-sm)}
  .ix-state .ic{width:48px;height:48px;border-radius:13px;display:grid;place-items:center;margin-bottom:14px}
  .ix-state .ic svg{width:25px;height:25px}
  .ix-state h2{font-family:'Oswald',sans-serif;font-weight:700;text-transform:uppercase;letter-spacing:.3px;
    font-size:clamp(21px,3.4vw,28px);line-height:1.06;margin:0;color:var(--tinta)}
  .ix-state p{margin:8px 0 0;font-size:15px;color:var(--muted);line-height:1.5;max-width:52ch}
  .ix-state .go{display:inline-flex;align-items:center;gap:9px;margin-top:18px;text-decoration:none;
    font-family:'Oswald',sans-serif;font-weight:700;text-transform:uppercase;letter-spacing:.04em;font-size:16px;
    color:#fff;padding:13px 24px;border-radius:13px;background:linear-gradient(135deg,var(--coral),var(--magenta));
    box-shadow:0 14px 30px -12px rgba(255,43,133,.5);transition:transform .15s,filter .15s}
  .ix-state .go:hover{transform:translateY(-2px);filter:brightness(1.05)}
  /* tonos */
  .ix-state.sell .ic{background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff}
  .ix-state.hot{border-color:transparent;background:linear-gradient(135deg,#fff,color-mix(in srgb,var(--magenta) 7%,#fff))}
  .ix-state.hot .ic{background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff}
  .ix-state.ok .ic{background:color-mix(in srgb,var(--teal) 14%,#fff);color:#007b7e}
  .ix-state.warn{border-color:color-mix(in srgb,#e3683f 40%,#fff)}
  .ix-state.warn .ic{background:color-mix(in srgb,#e3683f 16%,#fff);color:#c2410c}
  .ix-state.warn .go{background:linear-gradient(135deg,#f0822f,#d8472f)}

  /* Pipeline (explica, no actúa) */
  .ix-pipe{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin:18px 0 0;padding:14px 16px;
    border:1px solid var(--line);border-radius:14px;background:var(--card);box-shadow:var(--shadow-sm)}
  .ix-node{display:inline-flex;align-items:center;gap:7px;font-size:13px;font-weight:700;color:var(--muted)}
  .ix-node .dot{width:18px;height:18px;border-radius:50%;display:grid;place-items:center;font-size:11px;flex:none;
    border:2px solid var(--line);color:transparent}
  .ix-node.done{color:var(--tinta)}
  .ix-node.done .dot{background:var(--palma,#16b86a);border-color:var(--palma,#16b86a);color:#fff}
  .ix-node.now{color:var(--terracota)}
  .ix-node.now .dot{border-color:var(--terracota);background:color-mix(in srgb,var(--terracota) 18%,#fff);color:var(--terracota);box-shadow:0 0 0 4px color-mix(in srgb,var(--terracota) 14%,transparent)}
  .ix-sep{color:var(--line);font-weight:900}

  /* Actividad del corillo (humana) */
  .ix-feed{margin-top:24px;background:var(--card);border:1px solid var(--line);border-radius:18px;padding:20px 22px;box-shadow:var(--shadow-sm)}
  .ix-feed h3{font-family:'Oswald',sans-serif;font-weight:700;text-transform:uppercase;font-size:16px;letter-spacing:.3px;margin:0 0 14px;display:flex;align-items:center;gap:9px}
  .ix-feed h3 img{width:19px;height:19px}
  .ix-feed ol{list-style:none;margin:0;padding:0}
  .ix-feed li{display:flex;align-items:center;gap:11px;padding:9px 0;border-bottom:1px solid var(--line)}
  .ix-feed li:last-child{border-bottom:0}
  .ix-feed .t{display:flex;flex-direction:column;line-height:1.2;width:64px;flex:none;font-variant-numeric:tabular-nums}
  .ix-feed .t .d{font-size:11px;font-weight:800;color:var(--tinta)}
  .ix-feed .t .hr{font-size:11.5px;font-weight:700;color:var(--muted)}
  .ix-feed img.ic{width:24px;height:24px;flex:none}
  .ix-feed .m{font-size:14px;color:#473b46;line-height:1.35}
  .ix-feed .more{display:inline-block;margin-top:14px;color:var(--teal);font-weight:800;font-size:13.5px;text-decoration:none}
  .ix-feed .more:hover{color:var(--teal-700)}

  /* 3 KPIs de pulso — el color mapea significado, no decora:
     teal = ya logrado (crecimiento) · rosa = necesita tu acción · navy = métrica neutra */
  .ix-kpis{display:flex;gap:10px;margin-top:18px;overflow-x:auto;padding-bottom:2px}
  .ix-kpi{flex:1;min-width:118px;background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px 16px;box-shadow:var(--shadow-sm);border-top:3px solid var(--line)}
  .ix-kpi b{font-family:'Oswald',sans-serif;font-weight:700;font-size:26px;color:var(--tinta);display:block;line-height:1}
  .ix-kpi span{font-size:12.5px;color:var(--muted);font-weight:600}
  .ix-kpi:nth-child(1){border-top-color:var(--teal)}
  .ix-kpi:nth-child(1) b{color:var(--teal)}
  .ix-kpi:nth-child(2){border-top-color:var(--terracota)}
  .ix-kpi:nth-child(2) b{color:var(--terracota)}
  .ix-kpi:nth-child(3){border-top-color:var(--tinta)}
  /* observación (solo hechos) */
  .ix-obs{margin-top:16px;padding:12px 16px;border-radius:12px;font-size:14px;line-height:1.45;color:#0a5a5c;
    background:color-mix(in srgb,var(--teal) 8%,#fff);border:1px solid color-mix(in srgb,var(--teal) 20%,#fff)}
  /* próximos posts */
  .ix-next{margin-top:18px;background:var(--card);border:1px solid var(--line);border-radius:16px;padding:16px 20px;box-shadow:var(--shadow-sm)}
  .ix-next h3{font-family:'Oswald',sans-serif;font-weight:700;text-transform:uppercase;font-size:15px;letter-spacing:.3px;margin:0 0 10px}
  .ix-next ul{list-style:none;margin:0;padding:0}
  .ix-next li{display:flex;gap:12px;padding:7px 0;border-bottom:1px solid var(--line);font-size:13.5px;align-items:baseline}
  .ix-next li:last-child{border-bottom:0}
  .ix-next .d{color:var(--teal-700);font-weight:700;flex:none;min-width:140px}
  .ix-next .c{color:#473b46}
  .ix-next .more{display:inline-block;margin-top:10px;color:var(--teal);font-weight:800;font-size:13px;text-decoration:none}
  .ix-next .more:hover{color:var(--teal-700)}
  @media(max-width:560px){.ix-next li{flex-direction:column;gap:1px}.ix-next .d{min-width:0;font-size:12.5px}}
</style>

<section class="ix-hi">
  <h1>¡Hola, <span><?= $h($marca['nombre_negocio']) ?></span>!</h1>
  <p class="sub">El corillo está al día con tu contenido. Esto es lo que sigue:</p>
</section>

<!-- BLOQUE DE ESTADO: una sola próxima acción -->
<div class="ix-state <?= $h($st['tono']) ?>">
  <div class="ic"><?= ico($st['ico']) ?></div>
  <h2><?= $h($st['titulo']) ?></h2>
  <p><?= $h($st['sub']) ?></p>
  <a class="go" href="<?= $h($st['href']) ?>"><?= $h($st['cta']) ?> →</a>
</div>

<!-- PIPELINE: explica dónde está el trabajo -->
<div class="ix-pipe">
  <?php foreach ($nodos as $i => $nm):
    $cls = $i < $st['pipe'] ? 'done' : ($i === $st['pipe'] ? 'now' : '');
  ?>
    <span class="ix-node <?= $cls ?>"><span class="dot"><?= $i < $st['pipe'] ? '✓' : '' ?></span><?= $h($nm) ?></span>
    <?php if ($i < count($nodos)-1): ?><span class="ix-sep">→</span><?php endif; ?>
  <?php endforeach; ?>
</div>

<!-- 3 KPIs de pulso (reales, sin Meta) -->
<div class="ix-kpis">
  <div class="ix-kpi"><b><?= $prod['publicados_mes'] ?></b><span>publicados este mes</span></div>
  <div class="ix-kpi"><b><?= $prod['esperando_ok'] ?></b><span>esperan tu OK</span></div>
  <div class="ix-kpi"><b><?= $racha ?></b><span>semana<?= $racha==1?'':'s' ?> de racha</span></div>
</div>

<?php if ($obs): ?>
<div class="ix-obs"><?= ico('lightbulb') ?> <?= $h($obs) ?></div>
<?php endif; ?>

<?php if ($proximos): ?>
<div class="ix-next">
  <h3>Próximos posts</h3>
  <ul>
    <?php foreach ($proximos as $p): ?>
      <li>
        <span class="d"><?= $h(_fecha_humana($p['fecha_programada'])) ?></span>
        <span class="c"><?= $h(mb_strimwidth(trim((string)$p['caption']) ?: '(sin texto)', 0, 52, '…')) ?></span>
      </li>
    <?php endforeach; ?>
  </ul>
  <a class="more" href="<?= $BASE ?>/calendario.php?marca=<?= $marca_id ?>">Ver calendario →</a>
</div>
<?php endif; ?>

<!-- ACTIVIDAD DEL CORILLO (humana, sin tecnicismos) -->
<div class="ix-feed">
  <h3><img src="<?= $ICON ?>/bolt.svg" alt=""> Lo que hizo el corillo</h3>
  <ol>
    <?php if (!$feed): ?>
      <li><span class="m" style="color:var(--muted)">El corillo está listo pa' arrancar. Pídele algo y metemos mano.</span></li>
    <?php else: foreach ($feed as $fa): [$fic,$fnm,$fmsg] = $feed_map[$fa['agente']] ?? ['bolt','El Corillo','metió mano']; ?>
      <li>
        <span class="t">
          <span class="d"><?= $h($dia_rel($fa['created_at'])) ?></span>
          <span class="hr"><?= date('g:i A', strtotime($fa['created_at'])) ?></span>
        </span>
        <img class="ic" src="<?= $ICON ?>/<?= $h($fic) ?>.svg" alt="">
        <span class="m"><b><?= $h($fnm) ?></b> <?= $h($fmsg) ?></span>
      </li>
    <?php endforeach; endif; ?>
  </ol>
  <a class="more" href="<?= $BASE ?>/actividad.php?marca=<?= $marca_id ?>">Ver más actividad →</a>
</div>

<?php
// ── Copiloto flotante (solo en Inicio) — te recibe y conversa ──
$estr_img = '/crecer/assets/brand/estratega.png'; // avatar de La Estratega
$negocio  = $marca['nombre_negocio'] ?? 'mi gente';
?>
<div class="estr" id="estr">
  <div class="estr-bubble" id="estrBubble">
    <button class="estr-x" id="estrBx" aria-label="Cerrar">&times;</button>
    <div class="estr-kicker"><?= ico('sparkles') ?> La Estratega</div>
    <div class="estr-msgs" id="estrMsgs">
      <div class="estr-m ia">Soy <b>La Estratega</b>, pendiente a <b><?= $h($negocio) ?></b>. Puedo mirar tu contenido, detectar qué falta y ayudarte a decidir el próximo movimiento.</div>
    </div>
    <div class="estr-chips" id="estrChips">
      <button type="button">Qué hago ahora</button>
      <button type="button">Detecta qué falta</button>
      <button type="button">Dame una promo</button>
    </div>
    <form class="estr-form" id="estrForm" autocomplete="off">
      <input type="text" id="estrInput" placeholder="Dile qué quieres resolver…" maxlength="1000">
      <button type="submit" aria-label="Enviar"><?= ico('send') ?></button>
    </form>
    <a class="estr-full" href="<?= $BASE ?>/estratega.php?marca=<?= $marca_id ?>">Abrir centro de mando →</a>
  </div>
  <button class="estr-face" id="estrFace" aria-label="Habla con La Estratega">
    <img src="<?= $estr_img ?>" alt="La Estratega">
    <span class="estr-ping"></span>
  </button>
</div>
<style>
  .estr{position:fixed;left:16px;bottom:82px;z-index:118;display:flex;flex-direction:column;align-items:flex-start;gap:10px}
  .estr-face{position:relative;width:66px;height:66px;border-radius:50%;border:0;cursor:pointer;padding:0;background:linear-gradient(135deg,var(--coral),var(--magenta));box-shadow:0 12px 28px -8px rgba(192,57,95,.55);overflow:visible}
  .estr-face img{width:100%;height:100%;object-fit:cover;border-radius:50%}
  .estr-face .estr-ping{position:absolute;top:-2px;right:-2px;width:14px;height:14px;border-radius:50%;background:var(--palma);border:2px solid #fff;animation:estrping 1.8s ease-out infinite}
  @keyframes estrping{0%{box-shadow:0 0 0 0 rgba(22,184,106,.5)}70%{box-shadow:0 0 0 8px rgba(22,184,106,0)}100%{box-shadow:0 0 0 0 rgba(22,184,106,0)}}
  .estr-bubble{display:none;width:min(320px,86vw);background:#fff;border:1px solid var(--line);border-radius:18px;box-shadow:0 24px 60px -18px rgba(27,22,34,.4);padding:14px 14px 12px;position:relative}
  .estr-bubble.show{display:block;animation:estrpop .22s ease-out}
  @keyframes estrpop{from{opacity:0;transform:translateY(8px) scale(.98)}to{opacity:1;transform:none}}
  .estr-x{position:absolute;top:8px;right:9px;background:0;border:0;font-size:18px;color:var(--muted);cursor:pointer;line-height:1}
  .estr-kicker{display:inline-flex;align-items:center;gap:6px;margin:0 22px 9px 0;color:var(--terracota);font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.04em}
  .estr-kicker svg{width:14px;height:14px}
  .estr-msgs{display:flex;flex-direction:column;gap:8px;max-height:230px;overflow-y:auto;margin-bottom:10px;padding-right:2px}
  .estr-m{font-size:13.5px;line-height:1.5;padding:9px 11px;border-radius:12px;white-space:pre-wrap;word-wrap:break-word}
  .estr-m.ia{background:var(--crema-2);border:1px solid var(--line);color:var(--tinta);align-self:flex-start;border-bottom-left-radius:4px}
  .estr-m.user{background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff;align-self:flex-end;border-bottom-right-radius:4px}
  .estr-m.load{color:var(--muted);font-style:italic;background:var(--crema-2);border:1px solid var(--line);align-self:flex-start}
  .estr-chips{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:9px}
  .estr-chips button{border:1.5px solid var(--line);background:#fff;color:var(--tinta);font-family:inherit;font-weight:700;font-size:11.5px;padding:6px 10px;border-radius:99px;cursor:pointer}
  .estr-chips button:hover{border-color:var(--terracota)}
  .estr-form{display:flex;gap:7px}
  .estr-form input{flex:1;font-family:inherit;font-size:13.5px;border:1.5px solid var(--line);border-radius:11px;padding:9px 11px}
  .estr-form button{border:0;cursor:pointer;background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff;width:40px;border-radius:11px;display:grid;place-items:center}
  .estr-form button svg{width:17px;height:17px}
  .estr-full{display:block;text-align:center;font-size:12px;font-weight:700;color:var(--terracota);text-decoration:none;margin-top:9px}
  @media(max-width:760px){.estr{bottom:80px}}
  /* Desktop: el sidebar ya navega; la mascota es feature del celular y chocaría con el menú. */
  @media(min-width:761px){.estr{display:none}}
</style>
<script>
(function(){
  var CSRF=<?= json_encode(csrf_token()) ?>, MARCA=<?= (int)$marca_id ?>;
  var wrap=document.getElementById('estr'), face=document.getElementById('estrFace'),
      bubble=document.getElementById('estrBubble'), bx=document.getElementById('estrBx'),
      msgs=document.getElementById('estrMsgs'), form=document.getElementById('estrForm'),
      input=document.getElementById('estrInput'), chips=document.getElementById('estrChips');
  var hist=[], busy=false, abierto=false;
  function open(o){ abierto=o; bubble.classList.toggle('show',o); if(o) setTimeout(function(){input.focus();},60); }
  face.addEventListener('click', function(){ open(!abierto); });
  bx.addEventListener('click', function(e){ e.stopPropagation(); open(false); });
  // Saludo automático la primera vez de la sesión (te "recibe").
  try{ if(!sessionStorage.getItem('estr_hi')){ setTimeout(function(){ open(true); sessionStorage.setItem('estr_hi','1'); }, 900); } }catch(e){}
  function bubbleMsg(t,cls){ var d=document.createElement('div'); d.className='estr-m '+cls; d.textContent=t; msgs.appendChild(d); msgs.scrollTop=msgs.scrollHeight; return d; }
  function preguntar(q){
    if(busy||!q.trim()) return;
    if(chips) chips.style.display='none';
    bubbleMsg(q,'user'); hist.push({rol:'user',texto:q}); input.value='';
    busy=true; var load=bubbleMsg('Pensando…','load');
    var fd=new FormData(); fd.append('csrf',CSRF); fd.append('pregunta',q); fd.append('historial',JSON.stringify(hist.slice(-8)));
    fetch('<?= $BASE ?>/estratega.php?marca='+MARCA,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
      load.remove();
      var t=d.ok?d.respuesta:('No pude responder ahora. '+(d.err||''));
      bubbleMsg(t,'ia'); if(d.ok) hist.push({rol:'ia',texto:d.respuesta});
    }).catch(function(){ load.remove(); bubbleMsg('Se cayó la conexión. Intenta otra vez.','ia'); })
      .finally(function(){ busy=false; input.focus(); });
  }
  form.addEventListener('submit', function(e){ e.preventDefault(); preguntar(input.value); });
  if(chips) chips.addEventListener('click', function(e){ var b=e.target.closest('button'); if(b) preguntar(b.textContent); });
})();
</script>

<?php require __DIR__ . '/_shell_foot.php'; ?>
