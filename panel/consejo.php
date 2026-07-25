<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — El Consejo del Corillo (reunión de staff semanal)
//  panel/consejo.php  ·  el equipo trae preguntas, el dueño participa, la IA aprende
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/agentes.php';
requiere_login();

$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }
$marca_id = (int)$marca['id'];
$MAX_TURNOS = 6;   // conversación limitada (una vez por semana)

// ── AJAX: el dueño habla en el Consejo ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_ok()) { echo json_encode(['ok'=>false,'err'=>'Sesión expiró. Recarga la página.']); exit; }
    $mensaje = trim((string)($_POST['mensaje'] ?? ''));
    if ($mensaje === '') { echo json_encode(['ok'=>false,'err'=>'Escribe algo.']); exit; }
    $historial = json_decode((string)($_POST['historial'] ?? '[]'), true);
    if (!is_array($historial)) $historial = [];
    $turnos_usuario = count(array_filter($historial, fn($h) => ($h['rol'] ?? '') === 'user'));
    if ($turnos_usuario >= $MAX_TURNOS) {
        echo json_encode(['ok'=>true, 'respuesta'=>'Con esto tenemos suficiente por hoy, jefe. El equipo ya anotó todo y lo mete a trabajar esta semana. ¡Nos vemos en el próximo consejo! 🙌', 'aprendido'=>[], 'cerrado'=>true], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $r = consejo_hablar($pdo, $marca_id, mb_substr($mensaje, 0, 1000), $historial);
        $r['ok'] = true;
        $r['cerrado'] = ($turnos_usuario + 1 >= $MAX_TURNOS);
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false, 'err'=>substr($e->getMessage(), 0, 160)], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$disp = consejo_disponible($pdo, $marca_id);
$apertura = '';
if (!empty($disp['ok'])) {
    try { $ap = consejo_abrir($pdo, $marca_id); $apertura = $ap['respuesta']; }
    catch (Throwable $e) { $apertura = ''; }
}
// Para el estado "ya nos reunimos": lo último que el corillo aprendió del dueño.
$aprendizajes = [];
try {
    $q = $pdo->prepare("SELECT titulo FROM crecer_memoria WHERE marca_id=? AND fuente='consejo' AND estado='activa' ORDER BY id DESC LIMIT 6");
    $q->execute([$marca_id]);
    $aprendizajes = array_filter($q->fetchAll(PDO::FETCH_COLUMN));
} catch (Throwable $e) {}

$gerente_nombre = equipo_nombre($marca, 'gerente');
$active = 'consejo';
$page_title = 'El Consejo';
require __DIR__ . '/_shell.php';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<style>
  .cj-head{display:flex;align-items:center;gap:12px;margin-bottom:4px}
  .cj-orb{width:48px;height:48px;border-radius:14px;flex:none;display:grid;place-items:center;background:linear-gradient(135deg,var(--coral),var(--magenta));font-size:26px}
  .cj-h1{font-family:'Oswald',sans-serif;font-weight:700;font-size:23px;letter-spacing:.4px;color:var(--tinta);margin:0;line-height:1.1}
  .cj-sub{font-size:13px;color:var(--muted);margin:2px 0 0;max-width:640px;line-height:1.45}
  .cj-when{display:inline-block;background:var(--crema,#F7F5F1);border:1px solid var(--line);color:var(--muted);font-size:11.5px;font-weight:700;padding:4px 10px;border-radius:99px;margin:12px 0}
  .cj-msgs{display:flex;flex-direction:column;gap:12px;margin:14px 0 12px}
  .cj-m{max-width:88%;padding:12px 15px;border-radius:16px;font-size:14.5px;line-height:1.55;white-space:pre-wrap;word-wrap:break-word}
  .cj-m.ia{background:#fff;border:1px solid var(--line);color:var(--tinta);align-self:flex-start;border-bottom-left-radius:6px;box-shadow:var(--shadow-sm)}
  .cj-m.user{background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff;align-self:flex-end;border-bottom-right-radius:6px}
  .cj-m.load{color:var(--muted);font-style:italic;background:#fff;border:1px solid var(--line);align-self:flex-start}
  .cj-learn{align-self:flex-start;max-width:88%;background:#e6f7f4;border:1px solid #9ad9d0;color:#0a6a5f;border-radius:12px;padding:8px 12px;font-size:12.5px;font-weight:600}
  .cj-learn b{font-weight:800}
  .cj-form{display:flex;gap:9px;position:sticky;bottom:0;background:linear-gradient(to top,var(--crema) 70%,transparent);padding:10px 0 4px}
  .cj-form input{flex:1;font-family:inherit;font-size:14.5px;border:1.5px solid var(--line);border-radius:14px;padding:13px 15px;background:#fff}
  .cj-form button{border:0;cursor:pointer;background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff;font-weight:800;padding:0 20px;border-radius:14px;font-family:inherit;font-size:15px}
  .cj-form button:disabled{opacity:.55;cursor:default}
  .cj-closed{background:var(--crema,#F7F5F1);border:1px solid var(--line);border-radius:14px;padding:16px;color:var(--tinta);font-size:14px;line-height:1.55}
  .cj-closed h3{font-family:'Oswald',sans-serif;margin:0 0 8px;font-size:18px}
  .cj-chips{display:flex;flex-direction:column;gap:6px;margin-top:8px}
  .cj-chip{background:#fff;border:1px solid var(--line);border-radius:10px;padding:8px 11px;font-size:13px;color:var(--tinta)}
</style>

<div class="cj-head">
  <div class="cj-orb">🗣️</div>
  <div>
    <h1 class="cj-h1">El Consejo del Corillo</h1>
    <div class="cj-sub">Tu reunión de staff semanal. El equipo te trae lo que le importa y te hace preguntas — y con lo que les cuentas, <b>aprenden tu negocio</b>.</div>
  </div>
</div>

<?php if (empty($disp['ok'])): ?>
  <!-- YA se reunieron esta semana -->
  <span class="cj-when">Próximo consejo: <?= $h(date('d/m', strtotime($disp['proximo']))) ?></span>
  <div class="cj-closed">
    <h3>Ya nos reunimos esta semana 🙌</h3>
    <p style="margin:0 0 6px">El Consejo es una vez por semana para no quitarte tiempo. <?= $h($gerente_nombre) ?> y el equipo están trabajando con lo que hablamos.</p>
    <?php if ($aprendizajes): ?>
      <p style="margin:10px 0 4px;font-weight:800">Lo último que el corillo aprendió de ti:</p>
      <div class="cj-chips">
        <?php foreach ($aprendizajes as $a): ?><div class="cj-chip">🧠 <?= $h($a) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
<?php else: ?>
  <!-- Reunión ABIERTA -->
  <span class="cj-when">Reunión de esta semana · máx <?= $MAX_TURNOS ?> intervenciones</span>
  <div class="cj-msgs" id="cj-msgs">
    <?php if ($apertura !== ''): ?>
      <div class="cj-m ia"><?= $h($apertura) ?></div>
    <?php else: ?>
      <div class="cj-m ia">¡Dale! Arranquemos el consejo. Cuéntame: ¿qué quieres que el corillo empuje esta semana, y hay alguna fecha o evento que se venga?</div>
    <?php endif; ?>
  </div>
  <form class="cj-form" id="cj-form" autocomplete="off">
    <input type="text" id="cj-input" placeholder="Contéstale al equipo…" maxlength="1000">
    <button type="submit" id="cj-send">Enviar</button>
  </form>

  <script>
  (function(){
    var CSRF=<?= json_encode(csrf_token()) ?>, APERTURA=<?= json_encode($apertura, JSON_UNESCAPED_UNICODE) ?>;
    var msgs=document.getElementById('cj-msgs'), form=document.getElementById('cj-form'),
        input=document.getElementById('cj-input'), send=document.getElementById('cj-send');
    var hist=[]; if(APERTURA) hist.push({rol:'ia',texto:APERTURA});
    function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}
    function bubble(t,cls){var d=document.createElement('div');d.className='cj-m '+cls;d.textContent=t;msgs.appendChild(d);d.scrollIntoView({behavior:'smooth',block:'end'});return d;}
    function learn(items){ if(!items||!items.length) return;
      var d=document.createElement('div');d.className='cj-learn';
      d.innerHTML='🧠 <b>El corillo aprendió:</b> '+items.map(esc).join(' · ');
      msgs.appendChild(d);d.scrollIntoView({behavior:'smooth',block:'end'});
    }
    function cerrar(){ input.disabled=true; send.disabled=true; input.placeholder='Consejo cerrado por esta semana.'; }
    form.addEventListener('submit',function(e){
      e.preventDefault();
      var t=input.value.trim(); if(!t) return;
      bubble(t,'user'); hist.push({rol:'user',texto:t}); input.value=''; input.disabled=true; send.disabled=true;
      var load=bubble('El equipo está anotando…','load');
      var fd=new FormData(); fd.append('csrf',CSRF); fd.append('mensaje',t); fd.append('historial',JSON.stringify(hist));
      fetch(location.pathname+location.search,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
        load.remove();
        if(!d.ok){ bubble('No pude seguir ahora: '+(d.err||'intenta otra vez'),'ia'); input.disabled=false; send.disabled=false; return; }
        learn(d.aprendido);
        bubble(d.respuesta,'ia'); hist.push({rol:'ia',texto:d.respuesta});
        if(d.cerrado){ cerrar(); } else { input.disabled=false; send.disabled=false; input.focus(); }
      }).catch(function(){ load.remove(); bubble('Se cayó la conexión. Intenta otra vez.','ia'); input.disabled=false; send.disabled=false; });
    });
  })();
  </script>
<?php endif; ?>

<?php require __DIR__ . '/_shell_foot.php'; ?>
