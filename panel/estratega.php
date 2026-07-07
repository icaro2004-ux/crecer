<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — El Estratega (consejero de negocio)
//  panel/estratega.php  ·  chat que aconseja crecimiento/marketing
//  (tips de dinero generales con disclaimer; nada de Hacienda).
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/agentes.php';
requiere_login();

$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }
$marca_id = (int)$marca['id'];

// ── AJAX: pregunta al Estratega ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_ok()) { echo json_encode(['ok'=>false,'err'=>'Sesión expiró. Recarga la página.']); exit; }
    $pregunta = trim((string)($_POST['pregunta'] ?? ''));
    if ($pregunta === '') { echo json_encode(['ok'=>false,'err'=>'Escribe tu pregunta.']); exit; }
    $historial = json_decode((string)($_POST['historial'] ?? '[]'), true);
    if (!is_array($historial)) $historial = [];
    try {
        $r = estratega_responder($pdo, $marca_id, mb_substr($pregunta, 0, 1000), $historial);
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false, 'err'=>substr($e->getMessage(), 0, 160)], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$active = 'estratega';
$page_title = 'El Estratega';
require __DIR__ . '/_shell.php';
?>
<style>
  .es-head{display:flex;align-items:center;gap:12px;margin-bottom:6px}
  .es-orb{width:46px;height:46px;border-radius:14px;flex:none;display:grid;place-items:center;color:#fff;background:linear-gradient(135deg,var(--coral),var(--magenta))}
  .es-orb svg{width:24px;height:24px}
  .es-h1{font-family:'Oswald',sans-serif;font-weight:700;font-size:24px;letter-spacing:.4px;color:var(--tinta);margin:0;line-height:1}
  .es-sub{font-size:13.5px;color:var(--muted);margin:2px 0 16px;max-width:640px;line-height:1.45}
  .es-chips{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
  .es-chip{border:1.5px solid var(--line);background:#fff;color:var(--tinta);font-family:inherit;font-weight:700;font-size:13px;padding:9px 13px;border-radius:99px;cursor:pointer;text-align:left}
  .es-chip:hover{border-color:var(--terracota)}
  .es-msgs{display:flex;flex-direction:column;gap:12px;margin-bottom:16px}
  .es-m{max-width:88%;padding:12px 15px;border-radius:16px;font-size:14.5px;line-height:1.55;white-space:pre-wrap;word-wrap:break-word}
  .es-m.ia{background:#fff;border:1px solid var(--line);color:var(--tinta);align-self:flex-start;border-bottom-left-radius:6px;box-shadow:var(--shadow-sm)}
  .es-m.user{background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff;align-self:flex-end;border-bottom-right-radius:6px}
  .es-m.load{color:var(--muted);font-style:italic;background:#fff;border:1px solid var(--line);align-self:flex-start}
  .es-form{display:flex;gap:9px;position:sticky;bottom:0;background:linear-gradient(to top,var(--crema) 70%,transparent);padding:10px 0 4px}
  .es-form input{flex:1;font-family:inherit;font-size:14.5px;border:1.5px solid var(--line);border-radius:14px;padding:13px 15px;background:#fff}
  .es-form button{border:0;cursor:pointer;background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff;font-weight:800;padding:0 20px;border-radius:14px;font-family:inherit;font-size:15px}
  .es-form button:disabled{opacity:.55;cursor:default}
  .es-disc{font-size:11.5px;color:var(--muted);margin-top:10px;line-height:1.4}
</style>

<div class="es-head">
  <div class="es-orb"><?= ico('lightbulb') ?></div>
  <div><h1 class="es-h1">El Estratega</h1><div class="es-sub" style="margin:2px 0 0">Tu consultor de negocio. Conoce tu marca y te aconseja cómo crecer, promocionar y vender más.</div></div>
</div>

<div class="es-chips" id="es-chips">
  <button type="button" class="es-chip">¿Qué promoción hago este mes?</button>
  <button type="button" class="es-chip">Ideas para conseguir más clientes</button>
  <button type="button" class="es-chip">¿Cómo hago que la gente vuelva?</button>
  <button type="button" class="es-chip">Un combo o servicio nuevo para vender más</button>
</div>

<div class="es-msgs" id="es-msgs">
  <div class="es-m ia">¡Wepa! Soy El Estratega. Cuéntame qué quieres lograr en tu negocio — más clientes, una promo que jale, una idea nueva — y te doy un plan concreto, hecho pa' lo tuyo. Toca una idea de arriba o escríbeme.</div>
</div>

<form class="es-form" id="es-form" autocomplete="off">
  <input type="text" id="es-input" placeholder="Escríbele al Estratega…" maxlength="1000">
  <button type="submit" id="es-send"><?= ico('send') ?></button>
</form>
<div class="es-disc">Los consejos de dinero son ideas generales, no asesoría financiera profesional. Para números serios (impuestos, contabilidad), consulta un contable.</div>

<script>
(function(){
  var CSRF = <?= json_encode(csrf_token()) ?>, MARCA = <?= $marca_id ?>;
  var msgs=document.getElementById('es-msgs'), form=document.getElementById('es-form'),
      input=document.getElementById('es-input'), send=document.getElementById('es-send');
  var hist=[], busy=false;
  function esc(s){ var d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
  function bubble(t,cls){ var d=document.createElement('div'); d.className='es-m '+cls; d.textContent=t; msgs.appendChild(d); d.scrollIntoView({behavior:'smooth',block:'end'}); return d; }
  function preguntar(q){
    if(busy || !q.trim()) return;
    bubble(q,'user'); hist.push({rol:'user',texto:q}); input.value='';
    busy=true; send.disabled=true;
    var load=bubble('El Estratega está pensando…','load');
    var fd=new FormData(); fd.append('csrf',CSRF); fd.append('pregunta',q); fd.append('historial',JSON.stringify(hist.slice(-8)));
    fetch(location.pathname+'?marca='+MARCA,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
      load.remove();
      var t = d.ok ? d.respuesta : ('No pude responder ahora: '+(d.err||'intenta otra vez'));
      bubble(t,'ia'); if(d.ok) hist.push({rol:'ia',texto:d.respuesta});
    }).catch(function(){ load.remove(); bubble('Se cayó la conexión. Intenta otra vez.','ia'); })
      .finally(function(){ busy=false; send.disabled=false; input.focus(); });
  }
  form.addEventListener('submit', function(e){ e.preventDefault(); preguntar(input.value); });
  document.getElementById('es-chips').addEventListener('click', function(e){
    var b=e.target.closest('.es-chip'); if(b) preguntar(b.textContent);
  });
})();
</script>

<?php require __DIR__ . '/_shell_foot.php'; ?>
