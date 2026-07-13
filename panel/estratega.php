<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Copiloto de Encuentralo
//  panel/estratega.php  ·  asistente ejecutivo para estado + crecimiento
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

// ── AJAX: pregunta al copiloto ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_ok()) { echo json_encode(['ok'=>false,'err'=>'Sesión expiró. Recarga la página.']); exit; }
    $pregunta = trim((string)($_POST['pregunta'] ?? ''));
    if ($pregunta === '') { echo json_encode(['ok'=>false,'err'=>'Escribe tu pregunta.']); exit; }
    $historial = json_decode((string)($_POST['historial'] ?? '[]'), true);
    if (!is_array($historial)) $historial = [];
    $limite = copiloto_limite_uso($pdo, $marca_id);
    if (empty($limite['ok'])) { echo json_encode(['ok'=>false, 'err'=>$limite['err']], JSON_UNESCAPED_UNICODE); exit; }
    try {
        $r = estratega_responder($pdo, $marca_id, mb_substr($pregunta, 0, 1000), $historial);
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false, 'err'=>substr($e->getMessage(), 0, 160)], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$active = 'estratega';
$page_title = 'La Estratega';
require __DIR__ . '/_shell.php';
?>
<style>
  .es-head{display:flex;align-items:center;gap:12px;margin-bottom:6px}
  .es-orb{width:48px;height:48px;border-radius:50%;flex:none;overflow:hidden;background:#fff;border:2px solid var(--terracota);display:grid;place-items:center}
  .es-orb img{width:100%;height:100%;object-fit:cover;object-position:center 26%}
  .es-h1{font-family:'Oswald',sans-serif;font-weight:700;font-size:24px;letter-spacing:.4px;color:var(--tinta);margin:0;line-height:1}
  .es-sub{font-size:13.5px;color:var(--muted);margin:2px 0 16px;max-width:680px;line-height:1.45}
  .es-radar{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px;margin:14px 0 16px}
  .es-radar div{background:#fff;border:1px solid var(--line);border-radius:12px;padding:11px 12px;box-shadow:var(--shadow-sm);font-size:12.5px;color:var(--muted);line-height:1.35}
  .es-radar b{display:block;color:var(--tinta);font-size:13px;margin-bottom:2px}
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
  @media(max-width:720px){.es-radar{grid-template-columns:1fr}}
</style>

<div class="es-head">
  <div class="es-orb"><img src="/crecer/assets/brand/estratega.png" alt=""></div>
  <div><h1 class="es-h1">La Estratega</h1><div class="es-sub" style="margin:2px 0 0">Tu asesora de negocio. Mira tu negocio, entiende el panel y te ayuda a decidir el próximo movimiento.</div></div>
</div>

<div class="es-radar">
  <div><b>Radar</b>Detecta pendientes, fallos, redes y contenido esperando tu OK.</div>
  <div><b>Estrategia</b>Convierte ideas sueltas en promociones, posts y pasos concretos.</div>
  <div><b>Memoria</b>Usa lo aprendido de tu marca para hablar y recomendar con contexto.</div>
</div>

<div class="es-chips" id="es-chips">
  <button type="button" class="es-chip">Qué necesita atención ahora</button>
  <button type="button" class="es-chip">Qué promoción hago esta semana</button>
  <button type="button" class="es-chip">Cómo consigo más clientes</button>
  <button type="button" class="es-chip">Qué aprendiste de mi marca</button>
</div>

<div class="es-msgs" id="es-msgs">
  <div class="es-m ia">Estoy en línea. Puedo revisar qué necesita atención, ayudarte a planificar contenido o convertir una meta de negocio en próximos pasos. Toca una opción o escríbeme lo que quieres lograr.</div>
</div>

<form class="es-form" id="es-form" autocomplete="off">
  <input type="text" id="es-input" placeholder="Dile a La Estratega qué quieres resolver…" maxlength="1000">
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
    var load=bubble('Analizando tu negocio…','load');
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
