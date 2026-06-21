<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Soporte (chat interno cliente ↔ Crecer)
//  panel/soporte.php
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/suscripcion.php';
requiere_login();
$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }
$marca_id = (int)$marca['id'];

// ── AJAX: enviar mensaje ─────────────────────────────────────
if (($_GET['ajax'] ?? '') === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    if (empty($in['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', (string)$in['csrf'])) { echo json_encode(['ok'=>false]); exit; }
    $msg = trim((string)($in['mensaje'] ?? ''));
    if ($msg === '') { echo json_encode(['ok'=>false]); exit; }
    if (mb_strlen($msg) > 2000) $msg = mb_substr($msg, 0, 2000);
    $pdo->prepare("INSERT INTO crecer_soporte (marca_id, de, mensaje) VALUES (?, 'cliente', ?)")->execute([$marca_id, $msg]);
    echo json_encode(['ok'=>true, 'id'=>(int)$pdo->lastInsertId()]); exit;
}

// ── AJAX: traer mensajes nuevos ──────────────────────────────
if (($_GET['ajax'] ?? '') === 'poll') {
    header('Content-Type: application/json; charset=utf-8');
    // marcar como leídos los del operador
    $pdo->prepare("UPDATE crecer_soporte SET leido=1 WHERE marca_id=? AND de='operador' AND leido=0")->execute([$marca_id]);
    $after = (int)($_GET['after'] ?? 0);
    $q = $pdo->prepare("SELECT id, de, mensaje, created_at FROM crecer_soporte WHERE marca_id=? AND id>? ORDER BY id");
    $q->execute([$marca_id, $after]);
    echo json_encode(['ok'=>true, 'msgs'=>$q->fetchAll()], JSON_UNESCAPED_UNICODE); exit;
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$hist = $pdo->prepare("SELECT id, de, mensaje, created_at FROM crecer_soporte WHERE marca_id=? ORDER BY id");
$hist->execute([$marca_id]); $mensajes = $hist->fetchAll();

$active = 'soporte';
$page_title = 'Soporte';
require __DIR__ . '/_shell.php';
?>
<style>
  .sp-wrap{max-width:620px}
  .sp-card{background:var(--card);border:1px solid var(--line);border-radius:18px;box-shadow:var(--shadow-sm);overflow:hidden;display:flex;flex-direction:column;height:min(64vh,560px)}
  .sp-head{display:flex;align-items:center;gap:11px;padding:14px 16px;background:var(--tinta,#1b1622);color:#fff}
  .sp-head .o{width:36px;height:36px;border-radius:50%;background:var(--grad,linear-gradient(120deg,#ff5c39,#c0395f));display:grid;place-items:center;font-size:17px;flex:none}
  .sp-head b{font-size:15px}.sp-head .s{font-size:12px;color:#cfc7d6}
  .sp-msgs{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:10px;background:var(--crema,#fbf6ee)}
  .sp-m{max-width:82%;padding:10px 13px;border-radius:14px;font-size:14px;line-height:1.45;white-space:pre-wrap;word-wrap:break-word}
  .sp-m .t{display:block;font-size:10.5px;color:var(--muted);margin-top:4px}
  .sp-m.cliente{align-self:flex-end;background:var(--terracota,#e3683f);color:#fff;border-bottom-right-radius:5px}
  .sp-m.cliente .t{color:rgba(255,255,255,.8)}
  .sp-m.operador{align-self:flex-start;background:#fff;border:1px solid var(--line);color:var(--tinta);border-bottom-left-radius:5px}
  .sp-empty{text-align:center;color:var(--muted);font-size:14px;margin:auto;max-width:34ch}
  .sp-form{display:flex;gap:8px;padding:11px;border-top:1px solid var(--line);background:#fff}
  .sp-form input{flex:1;font-family:inherit;font-size:14.5px;border:1.5px solid var(--line);border-radius:11px;padding:11px 13px}
  .sp-form button{border:0;cursor:pointer;background:var(--palma,#16b86a);color:#fff;font-weight:800;font-size:15px;padding:0 18px;border-radius:11px}
  .sp-form button:disabled{opacity:.5}
</style>

<div class="sp-wrap">
  <h1 class="page-h" style="margin-bottom:6px">Soporte</h1>
  <p style="color:var(--muted);font-size:14px;margin:0 0 16px">¿Una duda, una queja, una idea? Escríbele directo al equipo de Crecer. Te contestamos por aquí.</p>

  <div class="sp-card">
    <div class="sp-head">
      <span class="o">🤝</span>
      <div><b>Equipo Crecer</b><div class="s">Aquí pa' lo que necesites</div></div>
    </div>
    <div class="sp-msgs" id="spMsgs">
      <?php if (!$mensajes): ?>
        <div class="sp-empty" id="spEmpty">👋 ¡Salúdanos! Escríbenos lo que sea — dudas, problemas, sugerencias. El equipo te responde por aquí.</div>
      <?php else: foreach ($mensajes as $m): ?>
        <div class="sp-m <?= $h($m['de']) ?>"><?= $h($m['mensaje']) ?><span class="t"><?= $h(date('d/m H:i', strtotime($m['created_at']))) ?></span></div>
      <?php endforeach; endif; ?>
    </div>
    <form class="sp-form" id="spForm">
      <input type="text" id="spInput" placeholder="Escribe tu mensaje…" autocomplete="off" maxlength="2000">
      <button type="submit" id="spSend">➤</button>
    </form>
  </div>
</div>

<script>
  var CSRF=<?= json_encode(csrf_token()) ?>, MARCA=<?= (int)$marca_id ?>;
  var msgs=document.getElementById('spMsgs'), form=document.getElementById('spForm'),
      input=document.getElementById('spInput'), send=document.getElementById('spSend');
  var lastId=<?= $mensajes ? (int)end($mensajes)['id'] : 0 ?>;
  function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}
  function add(m){
    var e=document.getElementById('spEmpty'); if(e) e.remove();
    var d=document.createElement('div'); d.className='sp-m '+m.de;
    var t=(m.created_at||'').substr(8,2)+'/'+(m.created_at||'').substr(5,2)+' '+(m.created_at||'').substr(11,5);
    d.innerHTML=esc(m.mensaje)+'<span class="t">'+t+'</span>';
    msgs.appendChild(d); msgs.scrollTop=msgs.scrollHeight;
  }
  msgs.scrollTop=msgs.scrollHeight;
  form.addEventListener('submit', function(e){
    e.preventDefault(); var t=input.value.trim(); if(!t) return;
    input.value=''; send.disabled=true;
    fetch('/crecer/panel/soporte.php?ajax=send&marca='+MARCA,{method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({csrf:CSRF,mensaje:t})}).then(function(r){return r.json();}).then(function(d){
      send.disabled=false;
      if(d.ok){ add({de:'cliente',mensaje:t,created_at:new Date().toISOString().replace('T',' ')}); if(d.id>lastId)lastId=d.id; input.focus(); }
    }).catch(function(){ send.disabled=false; });
  });
  function poll(){
    fetch('/crecer/panel/soporte.php?ajax=poll&marca='+MARCA+'&after='+lastId).then(function(r){return r.json();})
      .then(function(d){ if(d.ok&&d.msgs.length){ d.msgs.forEach(function(m){ if(m.id>lastId){ if(m.de==='operador') add(m); lastId=m.id; } }); } }).catch(function(){});
  }
  setInterval(poll, 5000);
</script>

<?php require __DIR__ . '/_shell_foot.php'; ?>
