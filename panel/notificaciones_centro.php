<?php
// ============================================================
//  CRECER — Centro de Notificaciones (vista)  panel/notificaciones_centro.php
//  El Corillo avisa aquí cuando termina algo (reel listo/publicado,
//  posts, etc.). Módulo AISLADO. Usa includes/notif.php.
//
//  Endpoints:
//   ?ajax=contar  → {ok, no_leidas}  (para la campanita)
//   POST leer     → marca todas leídas
//   (GET normal)  → la página con la lista
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notif.php';
requiere_login();
$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }
$marca_id = (int)$marca['id'];
$BASE = '/crecer/panel';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// Contador para la campanita (poll ligero desde cualquier pantalla).
if (($_GET['ajax'] ?? '') === 'contar') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true, 'no_leidas'=>notif_no_leidas($pdo, $marca_id)]); exit;
}
// Acciones AJAX: marcar leídas / borrar una / limpiar todas.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_ok()) { echo json_encode(['ok'=>false]); exit; }
    $acc = $_POST['accion'] ?? '';
    if ($acc === 'leer')    { notif_marcar_leidas($pdo, $marca_id); echo json_encode(['ok'=>true]); exit; }
    if ($acc === 'borrar')  { notif_borrar($pdo, $marca_id, (int)($_POST['id'] ?? 0)); echo json_encode(['ok'=>true]); exit; }
    if ($acc === 'limpiar') { notif_borrar_todas($pdo, $marca_id); echo json_encode(['ok'=>true]); exit; }
    echo json_encode(['ok'=>false]); exit;
}

$items = notif_listar($pdo, $marca_id, 50);
$no_leidas = notif_no_leidas($pdo, $marca_id);
$CSRF = csrf_token();
// Al abrir la página, marcar como leídas (ya las está viendo).
notif_marcar_leidas($pdo, $marca_id);

function notif_hace(string $ts): string {
    $t = strtotime($ts); if (!$t) return '';
    $d = time() - $t;
    if ($d < 60) return 'ahora';
    if ($d < 3600) return floor($d/60) . ' min';
    if ($d < 86400) return floor($d/3600) . ' h';
    return floor($d/86400) . ' d';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Notificaciones · Crecer</title>
<link rel="icon" type="image/png" href="/crecer/assets/brand/crecer-icon.png">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--rosa:#EF4375;--teal:#00A49F;--tinta:#231F20;--muted:#7a7580;--line:#eceaee;--crema:#faf9fb}
*{box-sizing:border-box}body{margin:0;font-family:'Plus Jakarta Sans',system-ui,sans-serif;color:var(--tinta);background:var(--crema)}
.wrap{max-width:640px;margin:0 auto;padding:18px 16px 60px}
.top{display:flex;align-items:center;gap:10px;padding:6px 0 14px}
.top a{color:var(--muted);text-decoration:none;font-weight:700;font-size:14px}
h1{font-family:'Poppins';font-size:26px;font-weight:900;margin:6px 0 16px}
.n{display:flex;gap:13px;align-items:flex-start;background:#fff;border:1px solid var(--line);border-radius:16px;padding:14px 15px;margin-bottom:10px;text-decoration:none;color:inherit;transition:.15s}
.n:hover{border-color:#d9d5de;transform:translateY(-1px)}
.n.un{background:#fff5f8;border-color:#f6ccda}
.n .em{font-size:24px;width:44px;height:44px;flex-shrink:0;border-radius:12px;background:var(--crema);display:flex;align-items:center;justify-content:center}
.n.un .em{background:#ffe3ec}
.n .tt{font-family:'Poppins';font-weight:700;font-size:15px;line-height:1.25}
.n .ms{color:var(--muted);font-size:13.5px;margin-top:3px;line-height:1.4}
.n .tm{color:var(--muted);font-size:12px;margin-left:auto;flex-shrink:0;white-space:nowrap}
.nwrap{position:relative}
.nwrap::after{content:"🗑";position:absolute;top:0;bottom:10px;left:16px;display:flex;align-items:center;font-size:20px;opacity:.6;z-index:0}
.n{position:relative;z-index:1;cursor:pointer;touch-action:pan-y;user-select:none}
.ndel{margin-left:6px;flex-shrink:0;background:none;border:0;cursor:pointer;font-size:16px;opacity:.35;padding:4px;transition:opacity .15s}
.ndel:hover{opacity:1}
.hbar{display:flex;align-items:center;gap:10px;margin:6px 0 16px}
.clr{margin-left:auto;background:#fff;border:1px solid var(--line);border-radius:10px;padding:8px 14px;font-weight:700;font-size:13px;color:var(--muted);cursor:pointer;font-family:inherit}
.clr:hover{color:var(--tinta);border-color:#d9d5de}
.empty{text-align:center;color:var(--muted);padding:50px 20px}
.empty .big{font-size:44px}
</style>
</head>
<body>
<div class="wrap">
  <div class="top"><a href="<?= $BASE ?>/index.php?marca=<?= $marca_id ?>">← Volver al panel</a></div>
  <div class="hbar">
    <h1 style="margin:0">🔔 Notificaciones</h1>
    <?php if ($items): ?><button class="clr" id="clearAll">Limpiar todo</button><?php endif; ?>
  </div>

  <?php if (!$items): ?>
    <div class="empty">
      <div class="big">🌱</div>
      <p><b>Todavía nada por aquí.</b><br>Cuando el corillo termine un reel o publique algo, te aviso aquí.</p>
    </div>
  <?php else: ?>
    <div id="list">
    <?php foreach ($items as $n): $un = !$n['leida']; $lk = $n['link'] ?: ($BASE.'/index.php?marca='.$marca_id); ?>
      <div class="nwrap">
        <div class="n <?= $un ? 'un' : '' ?>" data-id="<?= (int)$n['id'] ?>" data-link="<?= $h($lk) ?>">
          <div class="em"><?= $h($n['icono'] ?: '🔔') ?></div>
          <div style="min-width:0">
            <div class="tt"><?= $h($n['titulo']) ?></div>
            <?php if ($n['mensaje']): ?><div class="ms"><?= $h($n['mensaje']) ?></div><?php endif; ?>
          </div>
          <div class="tm"><?= $h(notif_hace($n['created_at'])) ?></div>
          <button class="ndel" aria-label="Eliminar" title="Eliminar">🗑</button>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
    <p style="text-align:center;color:var(--muted);font-size:12.5px;margin-top:14px">Desliza a la derecha para borrar, o usa 🗑</p>
  <?php endif; ?>
</div>

<script>
const CSRF = <?= json_encode($CSRF) ?>;
const HERE = location.pathname + '?marca=<?= $marca_id ?>';
function npost(data){ const fd=new FormData(); fd.append('csrf',CSRF); for(const k in data) fd.append(k,data[k]); return fetch(HERE,{method:'POST',body:fd}).then(r=>r.json()).catch(()=>({ok:false})); }
function slideOut(row){
  const w=row.closest('.nwrap');
  row.style.transition='transform .22s ease, opacity .22s'; row.style.transform='translateX(115%)'; row.style.opacity='0';
  setTimeout(()=>{ if(!w) return; w.style.height=w.offsetHeight+'px'; w.style.overflow='hidden';
    requestAnimationFrame(()=>{ w.style.transition='height .18s, margin .18s'; w.style.height='0'; w.style.margin='0'; });
    setTimeout(()=>{ w.remove(); if(!document.querySelectorAll('.nwrap').length){ location.reload(); } },200);
  },220);
}
document.querySelectorAll('.n').forEach(row=>{
  const id=row.dataset.id, link=row.dataset.link;
  row.querySelector('.ndel').addEventListener('click',e=>{ e.stopPropagation(); npost({accion:'borrar',id}); slideOut(row); });
  let sx=0,dx=0,drag=false;
  row.addEventListener('pointerdown',e=>{ if(e.target.closest('.ndel'))return; drag=true; sx=e.clientX; dx=0; row.style.transition='none'; try{row.setPointerCapture(e.pointerId)}catch(_){}});
  row.addEventListener('pointermove',e=>{ if(!drag)return; dx=e.clientX-sx; if(dx<0)dx=0; row.style.transform='translateX('+dx+'px)'; row.style.opacity=String(Math.max(.3,1-dx/300)); });
  const end=()=>{ if(!drag)return; drag=false; row.style.transition='transform .2s, opacity .2s';
    if(dx>110){ npost({accion:'borrar',id}); slideOut(row); }
    else { row.style.transform=''; row.style.opacity=''; if(dx<6){ location.href=link; } } };
  row.addEventListener('pointerup',end); row.addEventListener('pointercancel',end);
});
const ca=document.getElementById('clearAll');
if(ca) ca.addEventListener('click',()=>{ if(!confirm('¿Limpiar todas las notificaciones?'))return;
  npost({accion:'limpiar'}).then(()=>location.reload()); });
</script>
</body>
</html>
