<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Analítica (capa Despegar)
//  panel/analitica.php?marca=<id>
//
//  Números reales de lo que YA existe: ventas (crecer_ordenes),
//  posts publicados (crecer_contenido), y "lo que hizo el corillo"
//  (crecer_ia_log). NADA inventado. Las métricas de alcance de
//  IG/FB llegan cuando se integren los Insights de Meta (futuro).
//
//  Endpoint AJAX (mismo archivo): ?ajax=insight (resumen IA).
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/agentes.php';
require __DIR__ . '/../includes/suscripcion.php';
requiere_login();
require_once __DIR__ . '/../includes/panel_guard.php';
requiere_suscripcion($pdo, isset($_GET['marca']) ? (int)$_GET['marca'] : null);

$usuario = usuario_actual($pdo);
$USUARIO_ID = (int)$usuario['id'];
$marca = marca_del_usuario($pdo, $USUARIO_ID, isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }
$marca_id = (int)$marca['id'];
$BASE = '/crecer/panel';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$susc = suscripcion_de_marca($pdo, $marca_id);
$plan = suscripcion_activa($susc) ? ($susc['plan_slug'] ?? null) : null;
// Un solo plan: el de $39. Antes esto exigia un plan "Despegar" que ya no se
// vende — la pantalla quedaba tras un muro con un boton que no llevaba a nada.
// Ahora entra cualquier suscripcion activa.

// ── Helpers de métricas (datos reales) ───────────────────────
$es_despegar = suscripcion_activa($susc);
function ventas_periodo(PDO $pdo, int $marca_id, int $anio, int $mes): array {
    $q = $pdo->prepare(
        "SELECT COUNT(*) n, COALESCE(SUM(monto),0) total
           FROM crecer_ordenes
          WHERE marca_id=? AND estado<>'cancelada' AND YEAR(created_at)=? AND MONTH(created_at)=?");
    $q->execute([$marca_id, $anio, $mes]);
    return $q->fetch() ?: ['n'=>0,'total'=>0];
}

$anio = (int)date('Y'); $mes = (int)date('n');
$ant = strtotime('first day of last month');
$este = ventas_periodo($pdo, $marca_id, $anio, $mes);
$pasado = ventas_periodo($pdo, $marca_id, (int)date('Y',$ant), (int)date('n',$ant));

// Posts publicados este mes.
$posts_mes = (int)$pdo->query(
    "SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$marca_id} AND estado='publicado'
       AND YEAR(COALESCE(publicado_at,updated_at))={$anio} AND MONTH(COALESCE(publicado_at,updated_at))={$mes}")->fetchColumn();

// Piezas que creó el corillo este mes (todas las llamadas IA OK).
$piezas_mes = (int)$pdo->query(
    "SELECT COUNT(*) FROM crecer_ia_log WHERE marca_id={$marca_id} AND estado='ok'
       AND YEAR(created_at)={$anio} AND MONTH(created_at)={$mes}")->fetchColumn();

// Desglose "el corillo en números" (por agente, este mes).
$desglose = $pdo->prepare(
    "SELECT agente, COUNT(*) n FROM crecer_ia_log
      WHERE marca_id=? AND estado='ok' AND YEAR(created_at)=? AND MONTH(created_at)=?
      GROUP BY agente ORDER BY n DESC");
$desglose->execute([$marca_id, $anio, $mes]);
$desglose = $desglose->fetchAll();
$nombres_agente = [
    'planificador'=>['calendar','Planes de contenido'], 'creador'=>['pen','Captions escritos'],
    'diseñador'=>['palette','Logos diseñados'], 'intake'=>['lightbulb','Perfiles aprendidos'],
    'asistente'=>['chat','Dudas resueltas'], 'retencion'=>['users','Mensajes a clientes'],
    'analitica'=>['chart','Resúmenes'], 'aprendiz'=>['bookmark','Lecciones de voz'],
];

// Ventas últimos 6 meses (para la barra de crecimiento).
$MESES_ABR = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
$serie = [];
for ($i=5; $i>=0; $i--) {
    $t = strtotime("first day of -$i month");
    $v = ventas_periodo($pdo, $marca_id, (int)date('Y',$t), (int)date('n',$t));
    $serie[] = ['lbl'=>$MESES_ABR[(int)date('n',$t)], 'total'=>(float)$v['total']];
}
$max_serie = max(1, max(array_map(fn($s)=>$s['total'], $serie)));

// Delta de ventas vs mes pasado.
$delta = null;
if ((float)$pasado['total'] > 0) $delta = round((((float)$este['total'] - (float)$pasado['total']) / (float)$pasado['total']) * 100);

// ── AJAX: resumen del corillo (Despegar) ─────────────────────
if (($_GET['ajax'] ?? '') === 'insight' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    if (empty($in['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', (string)$in['csrf'])) {
        http_response_code(403); echo json_encode(['ok'=>false,'err'=>'Token vencido. Recarga.']); exit;
    }
    if (!$es_despegar) { echo json_encode(['ok'=>false,'err'=>'Activa tu plan para ver la analítica.']); exit; }
    try {
        $r = resumen_analitica($pdo, $marca_id, [
            'ventas_mes'=>(float)$este['total'], 'ordenes_mes'=>(int)$este['n'],
            'ventas_mes_ant'=>(float)$pasado['total'], 'posts_mes'=>$posts_mes, 'piezas_ia_mes'=>$piezas_mes,
        ]);
        echo json_encode(['ok'=>true,'texto'=>$r['texto']], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('analitica insight: '.$e->getMessage());
        echo json_encode(['ok'=>false,'err'=>'No pude generar el resumen. Intenta de nuevo.']);
    }
    exit;
}

$active = 'analitica';
$page_title = 'Analítica';
require __DIR__ . '/_shell.php';

if (!$es_despegar):
?>
<div style="max-width:560px">
  <h1 class="page-h">Analítica</h1>
  <div style="background:var(--card);border:1px dashed var(--line);border-radius:16px;padding:30px;text-align:center;margin-top:14px">
    <div><?= ico('chart','ic ic-xl') ?></div>
    <p style="font-weight:800;color:var(--tinta);margin:10px 0 4px">Analítica viene con tu plan</p>
    <p style="color:var(--muted);font-size:14px;max-width:42ch;margin:0 auto 16px">Mira tus ventas mes a mes, cuánto trabajó el corillo por ti, y recibe un resumen claro de cómo va tu negocio.</p>
    <a href="<?= $BASE ?>/precios.php?marca=<?= $marca_id ?>" style="display:inline-block;background:linear-gradient(135deg,var(--coral,#e3683f),var(--magenta,#c0395f));color:#fff;font-weight:800;padding:12px 24px;border-radius:99px;text-decoration:none"><?= ico('rocket') ?> Activar mi plan</a>
  </div>
</div>
<?php require __DIR__ . '/_shell_foot.php'; exit; endif; ?>

<style>
  .an-wrap{max-width:820px}
  .an-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:16px}
  .an-kpi{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px;box-shadow:var(--shadow-sm)}
  .an-kpi .lb{font-size:12.5px;color:var(--muted);font-weight:700}
  .an-kpi .v{font-family:var(--font-impact,'Poppins');font-size:30px;color:var(--tinta);line-height:1.1;margin-top:4px}
  .an-kpi .d{font-size:12.5px;font-weight:800;margin-top:2px}
  .an-kpi .d.up{color:#0d7a44}.an-kpi .d.down{color:#9b1c1c}
  .an-card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px;margin-bottom:16px;box-shadow:var(--shadow-sm)}
  .an-card h2{font-size:15px;font-weight:800;color:var(--tinta);margin:0 0 14px}
  .bars{display:flex;align-items:flex-end;gap:10px;height:140px}
  .bar{flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;height:100%;justify-content:flex-end}
  .bar .col{width:100%;max-width:46px;background:linear-gradient(180deg,var(--terracota,#e3683f),var(--magenta,#c0395f));border-radius:7px 7px 0 0;min-height:3px}
  .bar .bl{font-size:11px;color:var(--muted);font-weight:700}
  .bar .bv{font-size:11px;color:var(--tinta);font-weight:800}
  .corillo-row{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--line)}
  .corillo-row:last-child{border-bottom:0}
  .corillo-row .e{font-size:19px}.corillo-row .n{margin-left:auto;font-weight:800;color:var(--tinta)}
  .insight{background:linear-gradient(135deg,#fff,var(--crema,#fbf6ee));border:1px solid var(--line)}
  .insight .btn{border:0;cursor:pointer;font-family:inherit;font-weight:800;font-size:14px;color:#fff;background:var(--terracota,#e3683f);padding:11px 18px;border-radius:11px}
  .insight .out{margin-top:14px;font-size:14.5px;line-height:1.55;color:var(--tinta);white-space:pre-wrap;display:none}
  .insight .out.show{display:block}
  .note{font-size:12.5px;color:var(--muted);margin-top:8px}
</style>

<div class="an-wrap">
  <h1 class="page-h" style="margin-bottom:14px">Analítica</h1>

  <div class="an-grid">
    <div class="an-kpi">
      <div class="lb">Ventas este mes</div>
      <div class="v">$<?= number_format((float)$este['total'],0) ?></div>
      <?php if ($delta !== null): ?><div class="d <?= $delta>=0?'up':'down' ?>"><?= $delta>=0?'▲':'▼' ?> <?= abs($delta) ?>% vs mes pasado</div><?php endif; ?>
    </div>
    <div class="an-kpi"><div class="lb">Pedidos este mes</div><div class="v"><?= (int)$este['n'] ?></div></div>
    <div class="an-kpi"><div class="lb">Posts publicados</div><div class="v"><?= $posts_mes ?></div></div>
    <div class="an-kpi"><div class="lb">Piezas del corillo</div><div class="v"><?= $piezas_mes ?></div></div>
  </div>

  <div class="an-card">
    <h2><?= ico('chart') ?> Tus ventas, mes a mes</h2>
    <div class="bars">
      <?php foreach ($serie as $s): $hpx = (int)round(($s['total']/$max_serie)*120); ?>
        <div class="bar">
          <div class="bv">$<?= number_format($s['total'],0) ?></div>
          <div class="col" style="height:<?= max(3,$hpx) ?>px"></div>
          <div class="bl"><?= $h($s['lbl']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="note">Ventas = pedidos no cancelados, por mes.</p>
  </div>

  <div class="an-card insight">
    <h2><?= ico('chat') ?> ¿Cómo voy? — pregúntale al corillo</h2>
    <button class="btn" id="insBtn">Dame el resumen del mes</button>
    <div class="out" id="insOut"></div>
  </div>

  <div class="an-card">
    <h2><?= ico('bolt') ?> Lo que hizo el corillo este mes</h2>
    <?php if (!$desglose): ?>
      <p style="color:var(--muted);font-size:14px;margin:0">Todavía no hay actividad este mes. Pon al corillo a crear contenido y aquí verás todo lo que trabajó por ti.</p>
    <?php else: foreach ($desglose as $row):
      [$e,$lb] = $nombres_agente[$row['agente']] ?? ['settings', ucfirst($row['agente'])]; ?>
      <div class="corillo-row"><span class="e"><?= ico($e) ?></span><span><?= $h($lb) ?></span><span class="n"><?= (int)$row['n'] ?></span></div>
    <?php endforeach; endif; ?>
  </div>

  <div class="an-card" style="background:var(--crema,#fbf6ee)">
    <h2><?= ico('send') ?> Alcance de Instagram y Facebook</h2>
    <p style="color:var(--muted);font-size:14px;margin:0">Cuando publiques por la app (redes conectadas), aquí traeremos los números reales de alcance y likes directo de Meta. Por ahora medimos lo que controlamos: ventas y trabajo del corillo.</p>
  </div>
</div>

<script>
  var CSRF=<?= json_encode(csrf_token()) ?>, MARCA=<?= (int)$marca_id ?>;
  var btn=document.getElementById('insBtn'), out=document.getElementById('insOut');
  btn && btn.addEventListener('click', function(){
    btn.disabled=true; out.classList.add('show'); out.textContent='El corillo está mirando tus números…';
    fetch('/crecer/panel/analitica.php?ajax=insight&marca='+MARCA, {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({csrf:CSRF})
    }).then(function(r){return r.json();}).then(function(d){
      out.textContent = d.ok ? d.texto : (d.err||'Error');
    }).catch(function(){ out.textContent='Error de conexión.'; })
      .finally(function(){ btn.disabled=false; });
  });
</script>

<?php require __DIR__ . '/_shell_foot.php'; ?>
