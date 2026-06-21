<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Clientela (CRM ligero auto-armado)
//  panel/clientela.php?marca=<id>
//
//  Se arma SOLO de crecer_ordenes (cero entrada manual).
//   - Crecer:   lista de clientes + ficha/historial + "Escríbele"
//               por WhatsApp (link directo, sin IA).
//   - Despegar: segmentos automáticos + el corillo REDACTA el
//               mensaje de retención (IA) → dueño aprueba → WhatsApp.
//
//  Endpoints AJAX (mismo archivo): ?ajax=historial | ?ajax=retencion
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/agentes.php';
require __DIR__ . '/../includes/suscripcion.php';
requiere_login();

$usuario = usuario_actual($pdo);
$USUARIO_ID = (int)$usuario['id'];
$marca = marca_del_usuario($pdo, $USUARIO_ID, isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }
$marca_id = (int)$marca['id'];
$BASE = '/crecer/panel';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$susc   = suscripcion_de_marca($pdo, $marca_id);
$plan   = suscripcion_activa($susc) ? ($susc['plan_slug'] ?? null) : null;
$es_despegar = ($plan === 'despegar') && suscripcion_activa($susc);  // capa IA de retención

// Expresión de agrupación de cliente (contacto si hay; si no, el nombre).
const CKEY_SQL = "COALESCE(NULLIF(TRIM(cliente_contacto),''), cliente_nombre)";

/** Normaliza un contacto a número para wa.me (PR/US = 10 dígitos → +1). */
function wa_num(?string $c): string {
    $d = preg_replace('/\D+/', '', (string)$c);
    if ($d === '') return '';
    if (strlen($d) === 10) $d = '1' . $d;
    return $d;
}

/** Trae el agregado de UN cliente por su ckey (para acciones server-side). */
function cliente_por_ckey(PDO $pdo, int $marca_id, string $ckey): ?array {
    $q = $pdo->prepare(
        "SELECT " . CKEY_SQL . " AS ckey, MAX(cliente_nombre) AS nombre, MAX(cliente_contacto) AS contacto,
                COUNT(*) AS n, SUM(COALESCE(monto,0)) AS total,
                MAX(created_at) AS ultima, MIN(created_at) AS primera
           FROM crecer_ordenes
          WHERE marca_id=? AND estado<>'cancelada'
          GROUP BY ckey HAVING ckey=?");
    $q->execute([$marca_id, $ckey]);
    return $q->fetch() ?: null;
}

/** Días desde la última compra. */
function dias_desde(?string $fecha): int {
    if (!$fecha) return 9999;
    return (int)floor((time() - strtotime($fecha)) / 86400);
}

// ── AJAX: historial de un cliente ────────────────────────────
if (($_GET['ajax'] ?? '') === 'historial') {
    header('Content-Type: application/json; charset=utf-8');
    $ckey = (string)($_GET['ckey'] ?? '');
    $q = $pdo->prepare(
        "SELECT descripcion, monto, estado, created_at, fecha_entrega
           FROM crecer_ordenes
          WHERE marca_id=? AND " . CKEY_SQL . "=? AND estado<>'cancelada'
          ORDER BY created_at DESC LIMIT 30");
    $q->execute([$marca_id, $ckey]);
    echo json_encode(['ok'=>true, 'ordenes'=>$q->fetchAll()], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── AJAX: el corillo redacta el mensaje de retención (Despegar) ──
if (($_GET['ajax'] ?? '') === 'retencion' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    if (empty($in['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', (string)$in['csrf'])) {
        http_response_code(403); echo json_encode(['ok'=>false,'err'=>'Token vencido. Recarga.']); exit;
    }
    if (!$es_despegar) {
        echo json_encode(['ok'=>false,'err'=>'Esta función es del plan Despegar.', 'upsell'=>true]); exit;
    }
    $ckey = (string)($in['ckey'] ?? '');
    $cli = cliente_por_ckey($pdo, $marca_id, $ckey);
    if (!$cli) { echo json_encode(['ok'=>false,'err'=>'Cliente no encontrado.']); exit; }
    $dias = dias_desde($cli['ultima']);
    $seg = $dias >= 30 ? 'dormido' : ((int)$cli['n'] >= 3 ? 'frecuente' : (dias_desde($cli['primera']) <= 14 ? 'nuevo' : 'frecuente'));
    if (!empty($in['segmento'])) $seg = (string)$in['segmento'];
    try {
        $r = mensaje_retencion($pdo, $marca_id, [
            'nombre'=>$cli['nombre'], 'n'=>(int)$cli['n'], 'total'=>(float)$cli['total'], 'dias_sin_comprar'=>$dias,
        ], $seg);
        $num = wa_num($cli['contacto']);
        echo json_encode([
            'ok'=>true, 'mensaje'=>$r['mensaje'],
            'wa'=> $num ? ('https://wa.me/'.$num.'?text='.rawurlencode($r['mensaje'])) : '',
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('retencion: '.$e->getMessage());
        echo json_encode(['ok'=>false,'err'=>'No pude redactar el mensaje. Intenta de nuevo.']);
    }
    exit;
}

// ── Render: lista de clientes ────────────────────────────────
$rows = $pdo->prepare(
    "SELECT " . CKEY_SQL . " AS ckey, MAX(cliente_nombre) AS nombre, MAX(cliente_contacto) AS contacto,
            COUNT(*) AS n, SUM(COALESCE(monto,0)) AS total,
            MAX(created_at) AS ultima, MIN(created_at) AS primera
       FROM crecer_ordenes
      WHERE marca_id=? AND estado<>'cancelada'
      GROUP BY ckey
      ORDER BY ultima DESC");
$rows->execute([$marca_id]);
$clientes = $rows->fetchAll();

// Segmentar (en PHP) para chips y filtros.
foreach ($clientes as &$c) {
    $c['dias'] = dias_desde($c['ultima']);
    $c['dias_primera'] = dias_desde($c['primera']);
    $c['seg'] = $c['dias'] >= 30 ? 'dormido'
              : ((int)$c['n'] >= 3 ? 'frecuente'
              : ($c['dias_primera'] <= 14 ? 'nuevo' : 'frecuente'));
    $c['wa'] = wa_num($c['contacto']);
}
unset($c);
$cnt = ['dormido'=>0,'frecuente'=>0,'nuevo'=>0];
foreach ($clientes as $c) { $cnt[$c['seg']] = ($cnt[$c['seg']] ?? 0) + 1; }

$active = 'clientela';
$page_title = 'Clientela';
require __DIR__ . '/_shell.php';
?>
<style>
  .cl-wrap{max-width:760px}
  .cl-head{display:flex;align-items:baseline;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:6px}
  .cl-sub{color:var(--muted);font-size:14px;margin:0 0 16px}
  .cl-chips{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
  .cl-chip{cursor:pointer;border:1px solid var(--line);background:var(--card);border-radius:99px;padding:7px 14px;font-weight:700;font-size:13px;color:var(--tinta)}
  .cl-chip.on{background:var(--tinta);color:#fff;border-color:var(--tinta)}
  .cl-chip .n{opacity:.7;font-weight:800;margin-left:4px}
  .cl-card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px 16px;margin-bottom:10px;box-shadow:var(--shadow-sm)}
  .cl-top{display:flex;align-items:center;gap:12px}
  .cl-av{width:42px;height:42px;border-radius:50%;background:var(--crema);display:flex;align-items:center;justify-content:center;font-weight:800;color:var(--terracota);flex:none;font-size:17px}
  .cl-nm{font-weight:800;font-size:15.5px;color:var(--tinta)}
  .cl-meta{font-size:12.5px;color:var(--muted)}
  .cl-tag{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.03em;padding:3px 9px;border-radius:99px;margin-left:auto}
  .tag-dormido{background:#fdeede;color:#9a5b00}.tag-frecuente{background:#e6f6ee;color:#0d7a44}.tag-nuevo{background:#e8f0fe;color:#1a56b3}
  .cl-stats{display:flex;gap:18px;margin:11px 0 0;font-size:13px;color:var(--tinta)}
  .cl-stats b{font-size:15px}
  .cl-acts{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
  .cl-btn{display:inline-flex;align-items:center;gap:7px;text-decoration:none;cursor:pointer;border:0;font-family:inherit;font-weight:700;font-size:13.5px;padding:9px 14px;border-radius:10px}
  .cl-btn.wa{background:#25D366;color:#fff}.cl-btn.ai{background:var(--terracota);color:#fff}
  .cl-btn.ghost{background:#fff;color:var(--tinta);border:1.5px solid var(--line)}
  .cl-btn:disabled{opacity:.5;cursor:default}
  .cl-empty{background:var(--card);border:1px dashed var(--line);border-radius:14px;padding:34px;text-align:center;color:var(--muted)}
  .cl-hist{margin-top:10px;font-size:13px;color:var(--muted);border-top:1px dashed var(--line);padding-top:10px;display:none}
  .cl-hist .o{padding:4px 0;border-bottom:1px solid var(--line)}
  /* modal retención */
  .ret-ov{position:fixed;inset:0;background:rgba(27,22,34,.6);z-index:130;display:none;align-items:center;justify-content:center;padding:18px}
  .ret-ov.show{display:flex}
  .ret-box{background:#fff;border-radius:18px;max-width:440px;width:100%;padding:20px;box-shadow:0 24px 60px -18px rgba(0,0,0,.5)}
  .ret-box h3{margin:0 0 4px;font-size:17px;color:var(--tinta)}
  .ret-box .who{font-size:13px;color:var(--muted);margin-bottom:12px}
  .ret-box textarea{width:100%;box-sizing:border-box;min-height:120px;font-family:inherit;font-size:14.5px;border:1.5px solid var(--line);border-radius:12px;padding:12px;resize:vertical}
  .ret-acts{display:flex;gap:8px;margin-top:14px;flex-wrap:wrap}
  .ret-load{color:var(--muted);font-style:italic;padding:14px 0}
  .lock-note{background:#fff;border:1px dashed var(--line);border-radius:12px;padding:12px 14px;font-size:13px;color:var(--muted);margin-bottom:16px}
  .lock-note a{color:#0d7a44;font-weight:800;text-decoration:none}
</style>

<div class="cl-wrap">
  <div class="cl-head"><h1 class="page-h" style="margin:0">Clientela</h1></div>
  <p class="cl-sub">Tus clientes se arman solos de tus órdenes — no tienes que apuntar a nadie. 🪄</p>

  <?php if (!$plan): ?>
    <div class="lock-note">Clientela viene con tu plan. <a href="<?= $BASE ?>/precios.php?marca=<?= $marca_id ?>">Activa un plan →</a></div>
  <?php endif; ?>

  <?php if (!$clientes): ?>
    <div class="cl-empty">
      <div style="font-size:40px">👥</div>
      <p style="margin:10px 0 4px;font-weight:700;color:var(--tinta)">Todavía no tienes clientes registrados</p>
      <p style="margin:0;font-size:13.5px">Cuando te lleguen órdenes por tu página de pedidos (el QR), tus clientes aparecen aquí solitos.</p>
      <a class="cl-btn ghost" style="margin-top:14px" href="<?= $BASE ?>/ordenes.php?marca=<?= $marca_id ?>">Ver Órdenes →</a>
    </div>
  <?php else: ?>

    <?php if ($es_despegar): ?>
      <div class="cl-chips" id="chips">
        <span class="cl-chip on" data-seg="todos">Todos<span class="n"><?= count($clientes) ?></span></span>
        <span class="cl-chip" data-seg="dormido">😴 Dormidos<span class="n"><?= (int)$cnt['dormido'] ?></span></span>
        <span class="cl-chip" data-seg="frecuente">🔥 Frecuentes<span class="n"><?= (int)$cnt['frecuente'] ?></span></span>
        <span class="cl-chip" data-seg="nuevo">🌱 Nuevos<span class="n"><?= (int)$cnt['nuevo'] ?></span></span>
      </div>
    <?php else: ?>
      <div class="lock-note">🚀 Con <b>Despegar</b>, el corillo detecta a tus clientes dormidos y les <b>escribe solo</b> el mensaje para que vuelvan. <a href="<?= $BASE ?>/precios.php?marca=<?= $marca_id ?>">Súbete →</a></div>
    <?php endif; ?>

    <div id="lista">
    <?php foreach ($clientes as $c):
      $ini = mb_strtoupper(mb_substr(trim($c['nombre']) ?: '?', 0, 1));
      $wa_simple = $c['wa'] ? ('https://wa.me/'.$c['wa']) : '';
    ?>
      <div class="cl-card" data-seg="<?= $h($c['seg']) ?>" data-ckey="<?= $h($c['ckey']) ?>" data-nombre="<?= $h($c['nombre']) ?>">
        <div class="cl-top">
          <div class="cl-av"><?= $h($ini) ?></div>
          <div style="min-width:0">
            <div class="cl-nm"><?= $h($c['nombre'] ?: 'Cliente') ?></div>
            <div class="cl-meta"><?= $c['contacto'] ? $h($c['contacto']) : 'sin contacto' ?> · última compra hace <?= $c['dias']>=9999?'—':$c['dias'].'d' ?></div>
          </div>
          <span class="cl-tag tag-<?= $h($c['seg']) ?>"><?= $c['seg']==='dormido'?'😴 dormido':($c['seg']==='nuevo'?'🌱 nuevo':'🔥 frecuente') ?></span>
        </div>
        <div class="cl-stats">
          <span><b><?= (int)$c['n'] ?></b> pedido<?= (int)$c['n']===1?'':'s' ?></span>
          <span><b>$<?= number_format((float)$c['total'],2) ?></b> total</span>
        </div>
        <div class="cl-acts">
          <?php if ($wa_simple): ?><a class="cl-btn wa" href="<?= $h($wa_simple) ?>" target="_blank" rel="noopener">💬 Escríbele</a><?php endif; ?>
          <button class="cl-btn ghost" onclick="verHist(this)">📋 Ver pedidos</button>
          <?php if ($es_despegar): ?>
            <button class="cl-btn ai" onclick="corilloEscribe(this)">✨ Que el corillo le escriba</button>
          <?php endif; ?>
        </div>
        <div class="cl-hist"></div>
      </div>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- Modal de retención (Despegar) -->
<div class="ret-ov" id="retOv">
  <div class="ret-box">
    <h3>✨ Mensaje para tu cliente</h3>
    <div class="who" id="retWho"></div>
    <div id="retBody"><div class="ret-load">El corillo está escribiendo…</div></div>
  </div>
</div>

<script>
  var CSRF = <?= json_encode(csrf_token()) ?>, MARCA = <?= (int)$marca_id ?>;

  // Filtro por segmento (Despegar)
  var chips = document.getElementById('chips');
  if (chips) chips.addEventListener('click', function(e){
    var ch = e.target.closest('.cl-chip'); if(!ch) return;
    chips.querySelectorAll('.cl-chip').forEach(function(x){x.classList.remove('on');});
    ch.classList.add('on');
    var seg = ch.dataset.seg;
    document.querySelectorAll('#lista .cl-card').forEach(function(card){
      card.style.display = (seg==='todos' || card.dataset.seg===seg) ? '' : 'none';
    });
  });

  // Ver historial (todos los planes)
  window.verHist = function(btn){
    var card = btn.closest('.cl-card'), box = card.querySelector('.cl-hist');
    if (box.style.display==='block'){ box.style.display='none'; return; }
    box.style.display='block'; box.innerHTML='Cargando…';
    fetch('/crecer/panel/clientela.php?ajax=historial&marca='+MARCA+'&ckey='+encodeURIComponent(card.dataset.ckey))
      .then(function(r){return r.json();}).then(function(d){
        if(!d.ok||!d.ordenes.length){ box.innerHTML='Sin pedidos.'; return; }
        box.innerHTML = d.ordenes.map(function(o){
          var f = (o.created_at||'').substr(0,10);
          var mt = o.monto ? ' · $'+parseFloat(o.monto).toFixed(2) : '';
          return '<div class="o">'+f+' — '+(o.descripcion? o.descripcion.replace(/</g,'&lt;') : '(sin detalle)')+mt+' · '+o.estado+'</div>';
        }).join('');
      }).catch(function(){ box.innerHTML='No pude cargar el historial.'; });
  };

  // El corillo escribe el mensaje de retención (Despegar)
  var retOv=document.getElementById('retOv'), retWho=document.getElementById('retWho'), retBody=document.getElementById('retBody');
  retOv && retOv.addEventListener('click', function(e){ if(e.target===retOv) retOv.classList.remove('show'); });
  window.corilloEscribe = function(btn){
    var card = btn.closest('.cl-card');
    retWho.textContent = 'Para ' + (card.dataset.nombre||'tu cliente') + ' · segmento ' + card.dataset.seg;
    retBody.innerHTML = '<div class="ret-load">El corillo está escribiendo…</div>';
    retOv.classList.add('show');
    fetch('/crecer/panel/clientela.php?ajax=retencion&marca='+MARCA, {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({csrf:CSRF, ckey:card.dataset.ckey, segmento:card.dataset.seg})
    }).then(function(r){return r.json();}).then(function(d){
      if(!d.ok){ retBody.innerHTML='<p style="color:#9b1c1c">⚠️ '+(d.err||'Error')+'</p>'; return; }
      var wa = d.wa ? '<a class="cl-btn wa" href="'+d.wa+'" target="_blank" rel="noopener">💬 Abrir en WhatsApp</a>'
                    : '<span style="font-size:12.5px;color:var(--muted)">Este cliente no tiene WhatsApp guardado.</span>';
      retBody.innerHTML =
        '<textarea id="retTxt">'+d.mensaje.replace(/</g,'&lt;')+'</textarea>'+
        '<div class="ret-acts">'+wa+
        '<button class="cl-btn ghost" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById(\'retTxt\').value)">Copiar</button>'+
        '<button class="cl-btn ghost" onclick="document.getElementById(\'retOv\').classList.remove(\'show\')">Cerrar</button></div>'+
        '<p style="font-size:12px;color:var(--muted);margin:10px 0 0">Puedes editarlo antes de enviarlo. Tú tienes la última palabra.</p>';
      // El botón de WhatsApp toma el texto editado al hacer click
      var txt=document.getElementById('retTxt'), waBtn=retBody.querySelector('a.wa');
      if(txt&&waBtn) txt.addEventListener('input', function(){
        var base=waBtn.href.split('?text=')[0];
        waBtn.href = base+'?text='+encodeURIComponent(txt.value);
      });
    }).catch(function(){ retBody.innerHTML='<p style="color:#9b1c1c">⚠️ Error de conexión.</p>'; });
  };
</script>

<?php require __DIR__ . '/_shell_foot.php'; ?>
