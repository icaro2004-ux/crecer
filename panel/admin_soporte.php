<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Soporte (bandeja del operador)
//  panel/admin_soporte.php   ·   solo rol=admin
//  Lista de conversaciones + hilo por marca + responder.
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
requiere_login();
$usuario = usuario_actual($pdo);
if (($usuario['rol'] ?? '') !== 'admin') { http_response_code(403); exit('Acceso solo para administradores.'); }
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// ── AJAX: responder (operador) ───────────────────────────────
if (($_GET['ajax'] ?? '') === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    if (empty($in['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', (string)$in['csrf'])) { echo json_encode(['ok'=>false]); exit; }
    $mid = (int)($in['marca_id'] ?? 0); $msg = trim((string)($in['mensaje'] ?? ''));
    if (!$mid || $msg === '') { echo json_encode(['ok'=>false]); exit; }
    if (mb_strlen($msg) > 2000) $msg = mb_substr($msg, 0, 2000);
    $pdo->prepare("INSERT INTO crecer_soporte (marca_id, de, mensaje) VALUES (?, 'operador', ?)")->execute([$mid, $msg]);
    echo json_encode(['ok'=>true, 'id'=>(int)$pdo->lastInsertId()]); exit;
}
// ── AJAX: poll de un hilo ────────────────────────────────────
if (($_GET['ajax'] ?? '') === 'poll') {
    header('Content-Type: application/json; charset=utf-8');
    $mid = (int)($_GET['marca'] ?? 0); $after = (int)($_GET['after'] ?? 0);
    $pdo->prepare("UPDATE crecer_soporte SET leido=1 WHERE marca_id=? AND de='cliente' AND leido=0")->execute([$mid]);
    $q = $pdo->prepare("SELECT id, de, mensaje, created_at FROM crecer_soporte WHERE marca_id=? AND id>? ORDER BY id");
    $q->execute([$mid, $after]);
    echo json_encode(['ok'=>true, 'msgs'=>$q->fetchAll()], JSON_UNESCAPED_UNICODE); exit;
}

$ver = (int)($_GET['ver'] ?? 0);
$ago = function($ts){ if(!$ts) return ''; $s=time()-strtotime($ts); if($s<3600) return floor($s/60).'min'; if($s<86400) return floor($s/3600).'h'; return floor($s/86400).'d'; };

// Hilo abierto?
$thread = null; $marca_sel = null;
if ($ver) {
    $m = $pdo->prepare("SELECT id, nombre_negocio FROM crecer_marca WHERE id=?"); $m->execute([$ver]); $marca_sel = $m->fetch();
    if ($marca_sel) {
        $pdo->prepare("UPDATE crecer_soporte SET leido=1 WHERE marca_id=? AND de='cliente' AND leido=0")->execute([$ver]);
        $t = $pdo->prepare("SELECT id, de, mensaje, created_at FROM crecer_soporte WHERE marca_id=? ORDER BY id"); $t->execute([$ver]); $thread = $t->fetchAll();
    }
}

// Lista de conversaciones
$convs = $pdo->query("
    SELECT s.marca_id, m.nombre_negocio,
      (SELECT mensaje FROM crecer_soporte x WHERE x.marca_id=s.marca_id ORDER BY id DESC LIMIT 1) ultimo,
      (SELECT de FROM crecer_soporte x WHERE x.marca_id=s.marca_id ORDER BY id DESC LIMIT 1) ultimo_de,
      MAX(s.created_at) cuando,
      SUM(s.de='cliente' AND s.leido=0) sin_leer
    FROM crecer_soporte s JOIN crecer_marca m ON m.id=s.marca_id
    GROUP BY s.marca_id, m.nombre_negocio ORDER BY cuando DESC")->fetchAll();
$total_sin_leer = 0; foreach ($convs as $c) $total_sin_leer += (int)$c['sin_leer'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Soporte · Operaciones · Crecer</title>
<link rel="icon" type="image/svg+xml" href="/crecer/assets/brand/encuentralo-pin.svg">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="/crecer/assets/encuentralo-ui.css?v=20" rel="stylesheet">
<style>
  :root{--grad:linear-gradient(120deg,var(--coral,#ff5c39),var(--magenta,#c0395f))}
  *{box-sizing:border-box} body{background:var(--crema,#fbf6ee);color:var(--tinta,#1b1622);font-family:'Plus Jakarta Sans',sans-serif;margin:0}
  body::before{content:"";position:fixed;top:0;left:0;right:0;height:4px;z-index:60;background:var(--grad)}
  .top{display:flex;align-items:center;gap:12px;padding:15px 24px;background:#140a16;color:#fff}
  .top img{height:26px} .top b{font-family:var(--font-display);font-weight:800;text-transform:lowercase;letter-spacing:-.03em;font-size:18px}
  .top .tag{font-family:'Poppins';text-transform:uppercase;font-size:11px;letter-spacing:.08em;color:#ffcaa8;border:1px solid rgba(255,255,255,.18);padding:4px 10px;border-radius:99px}
  .top .sp{flex:1} .top a{color:#cdc5d6;text-decoration:none;font-size:13px;font-weight:600}
  .wrap{max-width:760px;margin:0 auto;padding:22px 24px 60px}
  h1{font-family:'Poppins';text-transform:uppercase;font-size:28px;letter-spacing:.02em;margin:0 0 14px}
  .conv{display:flex;align-items:center;gap:12px;background:var(--card);border:1px solid var(--line);border-radius:14px;padding:13px 15px;margin-bottom:10px;text-decoration:none;color:inherit;box-shadow:var(--shadow-sm)}
  .conv:hover{border-color:var(--terracota)}
  .conv .av{width:42px;height:42px;border-radius:50%;background:var(--crema);display:grid;place-items:center;font-weight:800;color:var(--terracota);flex:none}
  .conv .nm{font-weight:800;color:var(--tinta);font-size:14.5px}
  .conv .last{font-size:13px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:42ch}
  .conv .meta{margin-left:auto;text-align:right;flex:none}
  .conv .when{font-size:11.5px;color:var(--muted)}
  .badge{display:inline-block;background:var(--terracota);color:#fff;font-weight:800;font-size:11px;min-width:20px;text-align:center;padding:2px 7px;border-radius:99px;margin-top:4px}
  .empty{background:var(--card);border:1px dashed var(--line);border-radius:14px;padding:30px;text-align:center;color:var(--muted)}
  /* hilo */
  .back{display:inline-block;color:var(--muted);text-decoration:none;font-weight:700;font-size:14px;margin-bottom:12px}
  .chat{background:var(--card);border:1px solid var(--line);border-radius:18px;overflow:hidden;display:flex;flex-direction:column;height:min(66vh,560px);box-shadow:var(--shadow-sm)}
  .chat .ch{display:flex;align-items:center;gap:11px;padding:14px 16px;background:var(--tinta);color:#fff}
  .chat .ch .av{width:36px;height:36px;border-radius:50%;background:var(--grad);display:grid;place-items:center;font-weight:800;flex:none}
  .msgs{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:10px;background:var(--crema)}
  .m{max-width:82%;padding:10px 13px;border-radius:14px;font-size:14px;line-height:1.45;white-space:pre-wrap;word-wrap:break-word}
  .m .t{display:block;font-size:10.5px;color:var(--muted);margin-top:4px}
  .m.operador{align-self:flex-end;background:var(--palma,#16b86a);color:#fff;border-bottom-right-radius:5px}
  .m.operador .t{color:rgba(255,255,255,.85)}
  .m.cliente{align-self:flex-start;background:#fff;border:1px solid var(--line);border-bottom-left-radius:5px}
  .cf{display:flex;gap:8px;padding:11px;border-top:1px solid var(--line);background:#fff}
  .cf input{flex:1;font-family:inherit;font-size:14.5px;border:1.5px solid var(--line);border-radius:11px;padding:11px 13px}
  .cf button{border:0;cursor:pointer;background:var(--terracota);color:#fff;font-weight:800;font-size:15px;padding:0 18px;border-radius:11px}
</style>
</head>
<body>
<?php $op_active='soporte'; require __DIR__.'/_ops_top.php'; ?>
<div class="wrap">
<?php if ($thread !== null && $marca_sel): ?>
  <a class="back" href="/crecer/panel/admin_soporte.php">← Todas las conversaciones</a>
  <div class="chat">
    <div class="ch"><span class="av"><?= $h(mb_strtoupper(mb_substr($marca_sel['nombre_negocio'],0,1))) ?></span><b><?= $h($marca_sel['nombre_negocio']) ?></b></div>
    <div class="msgs" id="msgs">
      <?php if(!$thread): ?><div style="margin:auto;color:var(--muted)">Sin mensajes todavía.</div>
      <?php else: foreach ($thread as $m): ?>
        <div class="m <?= $h($m['de']) ?>"><?= $h($m['mensaje']) ?><span class="t"><?= $h(date('d/m H:i', strtotime($m['created_at']))) ?></span></div>
      <?php endforeach; endif; ?>
    </div>
    <form class="cf" id="cf"><input type="text" id="inp" placeholder="Responde a <?= $h($marca_sel['nombre_negocio']) ?>…" autocomplete="off" maxlength="2000"><button id="snd">➤</button></form>
  </div>
  <script>
    var CSRF=<?= json_encode(csrf_token()) ?>, MID=<?= (int)$marca_sel['id'] ?>;
    var msgs=document.getElementById('msgs'), inp=document.getElementById('inp'), snd=document.getElementById('snd');
    var lastId=<?= $thread ? (int)end($thread)['id'] : 0 ?>;
    function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}
    function add(m){var d=document.createElement('div');d.className='m '+m.de;
      var t=(m.created_at||'').substr(8,2)+'/'+(m.created_at||'').substr(5,2)+' '+(m.created_at||'').substr(11,5);
      d.innerHTML=esc(m.mensaje)+'<span class="t">'+t+'</span>';msgs.appendChild(d);msgs.scrollTop=msgs.scrollHeight;}
    msgs.scrollTop=msgs.scrollHeight;
    document.getElementById('cf').addEventListener('submit',function(e){e.preventDefault();var t=inp.value.trim();if(!t)return;
      inp.value='';snd.disabled=true;
      fetch('/crecer/panel/admin_soporte.php?ajax=send',{method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({csrf:CSRF,marca_id:MID,mensaje:t})}).then(function(r){return r.json();}).then(function(d){
        snd.disabled=false; if(d.ok){add({de:'operador',mensaje:t,created_at:new Date().toISOString().replace('T',' ')});if(d.id>lastId)lastId=d.id;inp.focus();}
      }).catch(function(){snd.disabled=false;});});
    setInterval(function(){fetch('/crecer/panel/admin_soporte.php?ajax=poll&marca='+MID+'&after='+lastId).then(function(r){return r.json();})
      .then(function(d){if(d.ok&&d.msgs.length)d.msgs.forEach(function(m){if(m.id>lastId){if(m.de==='cliente')add(m);lastId=m.id;}});}).catch(function(){});},5000);
  </script>
<?php else: ?>
  <h1>Soporte <?php if($total_sin_leer):?><span class="badge" style="font-size:13px;vertical-align:middle"><?= $total_sin_leer ?> sin leer</span><?php endif;?></h1>
  <?php if (!$convs): ?>
    <div class="empty">Todavía no hay conversaciones. Cuando un cliente te escriba desde su panel (sección <b>Soporte</b>), aparece aquí.</div>
  <?php else: foreach ($convs as $c): ?>
    <a class="conv" href="/crecer/panel/admin_soporte.php?ver=<?= (int)$c['marca_id'] ?>">
      <span class="av"><?= $h(mb_strtoupper(mb_substr($c['nombre_negocio'],0,1))) ?></span>
      <div style="min-width:0">
        <div class="nm"><?= $h($c['nombre_negocio']) ?></div>
        <div class="last"><?= $c['ultimo_de']==='operador'?'Tú: ':'' ?><?= $h(mb_strimwidth((string)$c['ultimo'],0,60,'…')) ?></div>
      </div>
      <div class="meta"><div class="when"><?= $h($ago($c['cuando'])) ?></div><?php if((int)$c['sin_leer']):?><span class="badge"><?= (int)$c['sin_leer'] ?></span><?php endif;?></div>
    </a>
  <?php endforeach; endif; ?>
<?php endif; ?>
</div>
</body>
</html>
