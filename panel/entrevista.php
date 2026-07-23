<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — La Entrevista (intake adaptativo por chat)
//  panel/entrevista.php  ·  un agente entrevista al dueño (como ChatGPT), con
//  preguntas que dependen de lo que va diciendo, hasta ENTENDER el negocio.
//  Al final: arma el perfil rico + dispara la Radiografía → todos los agentes alineados.
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/agentes.php';
require_once __DIR__ . '/../includes/iconos.php';   // ico() para el avatar del chat
requiere_login();

$usuario = usuario_actual($pdo);
$nuevo = !empty($_GET['nuevo']);
$gw = (($_GET['gw'] ?? '') === '1') ? '&gw=1' : '';   // modo prueba: caminar el gateway

// ── Arranque del onboarding: crear el negocio (solo el nombre) para empezar ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'crear_negocio') {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_ok()) { echo json_encode(['ok'=>false,'err'=>'Sesión expiró. Recarga.']); exit; }
    $nombre = trim((string)($_POST['nombre'] ?? ''));
    if ($nombre === '') { echo json_encode(['ok'=>false,'err'=>'Ponle nombre a tu negocio.']); exit; }
    try {
        $mid = crear_marca($pdo, ['usuario_id' => (int)$usuario['id'], 'nombre_negocio' => mb_substr($nombre, 0, 80)]);
        echo json_encode(['ok'=>true, 'marca_id'=>$mid]);
    } catch (Throwable $e) { echo json_encode(['ok'=>false, 'err'=>substr($e->getMessage(), 0, 120)]); }
    exit;
}

$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!empty($_GET['otra'])) $marca = null;   // "crear otro negocio" → pide nombre nuevo
// El nombre YA se capturó en el landing (negocio_intent) → crea la marca y salta al chat.
if (!$marca && empty($_GET['otra'])) {
    if (session_status() === PHP_SESSION_NONE) { @session_start(); }
    $neg = trim((string)($_SESSION['negocio_intent'] ?? ''));
    if ($neg !== '') {
        try {
            $mid = crear_marca($pdo, ['usuario_id' => (int)$usuario['id'], 'nombre_negocio' => mb_substr($neg, 0, 80)]);
            unset($_SESSION['negocio_intent']);
            header('Location: /crecer/panel/entrevista.php?marca=' . $mid . '&nuevo=1' . $gw); exit;
        } catch (Throwable $e) { /* si falla, cae a la pantalla de nombre */ }
    }
}
// Sin negocio → pantalla de arranque: pide el nombre y arranca la entrevista.
if (!$marca) { include __DIR__ . '/_entrevista_arranque.php'; exit; }
$marca_id = (int)$marca['id'];

// ── AJAX: el CIERRE va en 2 pasos separados para que NINGÚN request pase del
//    timeout del proxy (~60s). Antes se hacía perfil + radiografía + imagen en un
//    solo request (40-70s) → "se cayó la conexión". Ahora: (1) perfil, (2) post. ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'finalizar') {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_ok()) { echo json_encode(['ok'=>false,'err'=>'Sesión expiró. Recarga.']); exit; }
    @set_time_limit(0);
    $historial = json_decode((string)($_POST['historial'] ?? '[]'), true);
    if (!is_array($historial)) $historial = [];
    $historial = array_slice($historial, -40);
    try {
        $fin = entrevista_finalizar($pdo, $marca_id, $historial);   // perfil + radiografía (~20s)
        echo json_encode(['ok'=>true, 'resumen'=>(string)($fin['descripcion'] ?? ''),
            'voz'=>(string)($fin['voz'] ?? ''), 'publico'=>(string)($fin['publico'] ?? ''),
            'preset'=>(string)($fin['preset'] ?? 'boricua')], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false, 'err'=>substr($e->getMessage(), 0, 160)], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
// El dueño escoge el TONO/VOZ (paso final del chat) → aplica el preset a la marca.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'set_tono') {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_ok()) { echo json_encode(['ok'=>false]); exit; }
    $pk = trim((string)($_POST['preset'] ?? ''));
    $t  = preset_voz_a_tono($pk);
    if ($t) {
        try {
            $pdo->prepare("UPDATE crecer_marca SET tono_boricua=?, tono_formal=?, tono_venta=?, tono_ingenio=?, tono_preset=? WHERE id=?")
                ->execute([$t['tono_boricua'], $t['tono_formal'], $t['tono_venta'], $t['tono_ingenio'], $pk, $marca_id]);
        } catch (Throwable $e) { /* columnas de tono no migradas: se ignora */ }
    }
    echo json_encode(['ok'=>true]); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'post_muestra') {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_ok()) { echo json_encode(['ok'=>false,'err'=>'Sesión expiró.']); exit; }
    @set_time_limit(0);
    try { crear_post_muestra($pdo, $marca_id); } catch (Throwable $e) { error_log('post_muestra: ' . $e->getMessage()); }
    echo json_encode(['ok'=>true, 'redirect'=>'/crecer/panel/gateway_post.php?marca=' . $marca_id . $gw], JSON_UNESCAPED_UNICODE);
    exit;
}
// ── AJAX: el dueño contesta → siguiente pregunta (o done, SIN trabajo pesado aquí) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_ok()) { echo json_encode(['ok'=>false,'err'=>'Sesión expiró. Recarga la página.']); exit; }
    @set_time_limit(0);
    $historial = json_decode((string)($_POST['historial'] ?? '[]'), true);
    if (!is_array($historial)) $historial = [];
    $historial = array_slice($historial, -40);   // acota el contexto
    try {
        $sig = entrevista_siguiente($pdo, $marca_id, $historial);
        echo json_encode(['ok'=>true, 'done'=>!empty($sig['done']), 'pregunta'=>(string)($sig['pregunta'] ?? '')], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false, 'err'=>substr($e->getMessage(), 0, 160)], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Primera pregunta (historial vacío)
try { $primera = trim((string)(entrevista_siguiente($pdo, $marca_id, [])['pregunta'] ?? '')); }
catch (Throwable $e) { $primera = ''; }
if ($primera === '') $primera = '¡Hola! Cuéntame en tus palabras: ¿qué es exactamente lo que haces o vendes?';

// STANDALONE: el gateway NO usa el shell del app (cero nav ni enlaces al app).
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="es"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Cuéntame de tu negocio — Crecer</title>
<link rel="icon" type="image/png" href="/crecer/assets/brand/crecer-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@600;700&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="/crecer/assets/encuentralo-ui.css?v=22" rel="stylesheet">
<style>
  body{background:var(--crema,#F7F5F1)}
  .content{max-width:720px;margin:0 auto;padding:6px 18px 26px}
  .en-brand{display:flex;align-items:center;gap:8px;max-width:720px;margin:0 auto;padding:16px 18px 2px}
  .en-brand img{height:26px}
  .en-brand b{font-weight:800;font-size:17px;color:var(--tinta)}
  .en-brand .t{color:var(--teal,#00A49F)}
  .en-top h1{font-family:'Oswald',sans-serif;font-weight:700;font-size:23px;letter-spacing:.4px;color:var(--tinta);margin:0}
  .en-top p{font-size:13px;color:var(--muted);margin:4px 0 0;line-height:1.45;max-width:560px}
  .en-thread{display:flex;flex-direction:column;gap:14px;padding:14px 0}
  .en-row{display:flex;gap:9px;max-width:92%;align-items:flex-end}
  .en-row.ia{align-self:flex-start}
  .en-row.me{align-self:flex-end;flex-direction:row-reverse}
  .en-face{width:32px;height:32px;border-radius:50%;flex:none;display:grid;place-items:center;background:var(--card,#fff);border:1.5px solid var(--line);box-shadow:var(--shadow-sm)}
  .en-face svg{width:17px;height:17px;color:var(--magenta,#EF4375)}
  .en-b{padding:11px 15px;border-radius:18px;font-size:14.5px;line-height:1.55;white-space:pre-wrap;word-wrap:break-word}
  .en-row.ia .en-b{background:var(--card,#fff);border:1px solid var(--line);color:var(--tinta);border-bottom-left-radius:6px;box-shadow:var(--shadow-sm)}
  .en-row.me .en-b{background:linear-gradient(135deg,var(--coral,#FF6B3D),var(--magenta,#EF4375));color:#fff;border-bottom-right-radius:6px}
  .en-b.load{color:var(--muted);font-style:italic}
  .en-dots span{display:inline-block;width:6px;height:6px;border-radius:50%;background:var(--muted);margin:0 1px;animation:enb 1s infinite}
  .en-dots span:nth-child(2){animation-delay:.15s}.en-dots span:nth-child(3){animation-delay:.3s}
  @keyframes enb{0%,60%,100%{opacity:.3;transform:translateY(0)}30%{opacity:1;transform:translateY(-3px)}}
  .en-done{align-self:stretch;background:color-mix(in srgb,var(--teal,#00A49F) 10%,#fff);border:1px solid color-mix(in srgb,var(--teal,#00A49F) 32%,#fff);border-radius:16px;padding:16px}
  .en-done h3{font-family:'Oswald',sans-serif;margin:0 0 8px;font-size:18px;color:var(--tinta)}
  .en-done p{font-size:13.5px;color:var(--tinta);line-height:1.5;margin:0 0 12px}
  .en-done a{display:inline-block;background:var(--tinta);color:#fff;text-decoration:none;font-weight:800;font-size:14px;padding:12px 20px;border-radius:13px}
  .en-done a.sec{background:transparent;color:var(--muted);font-weight:700;padding:12px 8px;text-decoration:underline}
  .en-armando{align-self:stretch;display:flex;flex-direction:column;align-items:center;gap:16px;padding:30px 20px;background:var(--card,#fff);border:1px solid var(--line);border-radius:18px;box-shadow:var(--shadow-sm)}
  .en-armando .ring{width:46px;height:46px;border-radius:50%;border:3px solid color-mix(in srgb,var(--magenta,#EF4375) 20%,#eee);border-top-color:var(--magenta,#EF4375);animation:enspin .8s linear infinite}
  @keyframes enspin{to{transform:rotate(360deg)}}
  .en-armando .pasos{display:flex;flex-direction:column;gap:10px;width:100%;max-width:290px}
  .en-armando .paso{display:flex;align-items:center;gap:10px;font-size:14px;color:var(--muted);transition:color .3s}
  .en-armando .paso .dot{width:19px;height:19px;border-radius:50%;border:2px solid var(--line);flex:none;display:grid;place-items:center;font-size:11px;line-height:1}
  .en-armando .paso.activo{color:var(--tinta);font-weight:700}
  .en-armando .paso.activo .dot{border-color:var(--magenta,#EF4375)}
  .en-armando .paso.hecho{color:var(--teal,#00A49F)}
  .en-armando .paso.hecho .dot{border-color:var(--teal,#00A49F);background:var(--teal,#00A49F);color:#fff}
  /* ── Ventana translúcida: el CORILLO trabajando (conversación de agentes) ── */
  .corillo-ov{position:fixed;inset:0;z-index:200;display:flex;align-items:center;justify-content:center;padding:16px;opacity:0;transition:opacity .3s;
    background:radial-gradient(130% 95% at 12% 0%, color-mix(in srgb,var(--magenta,#EF4375) 66%,transparent), transparent 58%),
      radial-gradient(130% 95% at 100% 100%, color-mix(in srgb,var(--teal,#00A49F) 60%,transparent), transparent 58%),
      rgba(24,14,24,.5);
    backdrop-filter:blur(10px) saturate(1.2);-webkit-backdrop-filter:blur(10px) saturate(1.2)}
  .corillo-ov.on{opacity:1}
  .corillo-panel{background:var(--card,#fff);border:1px solid rgba(255,255,255,.5);border-radius:22px;
    width:100%;max-width:440px;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 40px 100px -24px rgba(20,12,20,.7);overflow:hidden}
  .cp-head{font-family:'Oswald',sans-serif;font-weight:700;font-size:15px;color:#fff;padding:15px 18px;display:flex;align-items:center;gap:9px;
    background:var(--btn-grad,linear-gradient(135deg,#FF6B3D,#EF4375))}
  .cp-dot{width:9px;height:9px;border-radius:50%;background:#fff;animation:cppulse 1.2s infinite;flex:none}
  @keyframes cppulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.35;transform:scale(.8)}}
  .cp-feed{padding:14px 16px;overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:12px;min-height:170px}
  .cp-line{opacity:0;transform:translateY(6px);animation:cpin .35s forwards}
  .cp-line b{display:block;font-size:11.5px;font-weight:700;color:var(--magenta,#EF4375);margin-bottom:3px;letter-spacing:.01em}
  .cp-line span{display:block;font-size:14px;line-height:1.5;color:var(--tinta);background:var(--crema,#f7f5f1);border:1px solid var(--line);border-radius:13px;border-top-left-radius:4px;padding:9px 12px}
  .cp-line.det b{color:var(--teal,#00A49F)}
  .cp-line.det span{background:color-mix(in srgb,var(--teal,#00A49F) 10%,#fff);border-color:color-mix(in srgb,var(--teal,#00A49F) 30%,#fff)}
  .cp-line.cp-hype b{color:var(--coral,#FF6B3D)}
  .cp-line.cp-hype span{font-weight:700;color:var(--tinta);border-color:color-mix(in srgb,var(--magenta,#EF4375) 38%,#fff);
    background:linear-gradient(135deg,color-mix(in srgb,var(--coral,#FF6B3D) 20%,#fff),color-mix(in srgb,var(--magenta,#EF4375) 20%,#fff));animation:cppop .5s}
  @keyframes cppop{0%{transform:scale(.9)}60%{transform:scale(1.03)}100%{transform:scale(1)}}
  @keyframes cpin{to{opacity:1;transform:none}}
  .cp-typing span{display:inline-flex;gap:3px;padding:11px 13px}
  .cp-typing i{width:6px;height:6px;border-radius:50%;background:var(--muted);animation:enb 1s infinite}
  .cp-typing i:nth-child(2){animation-delay:.15s}.cp-typing i:nth-child(3){animation-delay:.3s}
  .cp-foot{padding:14px 16px;border-top:1px solid var(--line)}
  .cp-foot h3{font-family:'Oswald',sans-serif;font-weight:700;font-size:16px;color:var(--tinta);margin:0 0 2px}
  .cp-foot .sb{font-size:12.5px;color:var(--muted);margin:0 0 12px;line-height:1.4}
  .tono{display:block;width:100%;text-align:left;font-family:inherit;cursor:pointer;border:1.5px solid var(--line);background:#fff;border-radius:13px;padding:10px 13px;margin-bottom:8px;transition:border-color .15s,box-shadow .15s}
  .tono b{display:block;font-family:'Oswald',sans-serif;font-weight:700;font-size:14px;color:var(--tinta)}
  .tono span{display:block;color:var(--muted);font-size:11.5px;margin-top:1px;line-height:1.3}
  .tono.sel{border-color:var(--magenta,#EF4375);box-shadow:0 0 0 3px color-mix(in srgb,var(--magenta,#EF4375) 15%,transparent)}
  .tono.sel b{color:var(--magenta,#EF4375)}
  .tono-go{width:100%;border:0;cursor:pointer;background:var(--btn-grad,linear-gradient(135deg,#FF6B3D,#EF4375));color:#fff;font-family:'Oswald',sans-serif;font-weight:700;font-size:16px;padding:13px;border-radius:14px;margin-top:6px}
  .tono-go:disabled{opacity:.6}
  .en-form{display:flex;gap:9px;align-items:center;position:sticky;bottom:0;background:linear-gradient(to top,var(--crema,#F7F5F1) 74%,transparent);padding:12px 0 6px}
  .en-input{flex:1;font-family:inherit;font-size:15px;border:1.5px solid var(--line);border-radius:16px;padding:14px 16px;background:var(--card,#fff);color:var(--tinta)}
  .en-input:focus{outline:0;border-color:var(--magenta,#EF4375)}
  .en-mic{border:1.5px solid var(--line);background:var(--card,#fff);cursor:pointer;width:52px;height:52px;border-radius:16px;flex:none;display:grid;place-items:center}
  .en-mic svg{width:23px;height:23px;color:var(--tinta)}
  .en-mic.rec{background:#e0245e;border-color:transparent;animation:enpulse 1.1s infinite}
  .en-mic.rec svg{color:#fff}
  @keyframes enpulse{0%,100%{box-shadow:0 0 0 0 rgba(224,36,94,.45)}70%{box-shadow:0 0 0 12px rgba(224,36,94,0)}}
  .en-send{border:0;cursor:pointer;background:var(--btn-grad,linear-gradient(135deg,#FF6B3D,#EF4375));color:#fff;font-weight:800;height:52px;padding:0 20px;border-radius:16px;font-family:inherit;font-size:15px;flex:none}
  .en-send:disabled{opacity:.5}
  .en-listen{font-size:12px;color:var(--muted);text-align:center;min-height:16px;font-weight:600}
</style>
</head>
<body>
<div class="en-brand"><img src="/crecer/assets/brand/crecer-icon.png" alt=""><b>encuéntralo <span class="t">crecer</span></b></div>
<div class="content">

<div class="en-top">
  <h1>Cuéntame de tu negocio</h1>
  <p>Es una conversación, no un formulario. Te voy a hacer preguntas hasta entender bien tu negocio — y con eso el corillo hace todo mejor (los posts, el arte, todo).</p>
</div>

<div class="en-thread" id="enMsgs">
  <div class="en-row ia"><div class="en-face"><?= ico('chat') ?></div><div class="en-b"><?= $h($primera) ?></div></div>
</div>

<form class="en-form" id="enForm" autocomplete="off">
  <button type="button" class="en-mic" id="enMic" title="Hablar"><?= ico('mic') ?></button>
  <input type="text" class="en-input" id="enInput" placeholder="Escribe o toca el micrófono…" maxlength="1200">
  <button type="submit" class="en-send" id="enSend">Enviar</button>
</form>
<div class="en-listen" id="enListen"></div>
</div><!-- /content -->

<script>
(function(){
  var CSRF=<?= json_encode(csrf_token()) ?>, MARCA=<?= (int)$marca_id ?>, GW=<?= json_encode($gw) ?>, FACE=<?= json_encode(ico('chat')) ?>, PRIMERA=<?= json_encode($primera, JSON_UNESCAPED_UNICODE) ?>;
  var msgs=document.getElementById('enMsgs'), form=document.getElementById('enForm'), input=document.getElementById('enInput'),
      send=document.getElementById('enSend'), listen=document.getElementById('enListen');
  var hist=[{rol:'ia',texto:PRIMERA}], cerrado=false;
  function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}
  function scroll(el){el.scrollIntoView({behavior:'smooth',block:'end'});}
  function me(t){var r=document.createElement('div');r.className='en-row me';r.innerHTML='<div class="en-b">'+esc(t)+'</div>';msgs.appendChild(r);scroll(r);}
  function ia(t){var r=document.createElement('div');r.className='en-row ia';r.innerHTML='<div class="en-face">'+FACE+'</div><div class="en-b">'+esc(t)+'</div>';msgs.appendChild(r);scroll(r);}
  function loading(){var r=document.createElement('div');r.className='en-row ia';r.innerHTML='<div class="en-face">'+FACE+'</div><div class="en-b load"><span class="en-dots"><span></span><span></span><span></span></span></div>';msgs.appendChild(r);scroll(r);return r;}
  function done(resumen, redirect){
    cerrado=true; form.style.display='none'; listen.textContent='';
    var url = redirect || ('/crecer/panel/gateway_post.php?marca='+MARCA+GW);
    var d=document.createElement('div'); d.className='en-done';
    d.innerHTML='<h3>✓ Ya entiendo tu negocio</h3>'+(resumen?'<p>'+esc(resumen)+'</p>':'<p>Armé tu perfil y el corillo ya lo tiene.</p>')
      +'<a href="'+url+'">Ver mi primer post →</a>';
    msgs.appendChild(d); scroll(d);
  }
  // POST con timeout (AbortController) para no colgarse esperando indefinido.
  function post(fields, timeoutMs){
    var ctrl=new AbortController(), to=setTimeout(function(){ctrl.abort();}, timeoutMs||60000);
    var fd=new FormData(); fd.append('csrf',CSRF);
    for(var k in fields){ if(fields.hasOwnProperty(k)) fd.append(k, fields[k]); }
    return fetch(location.pathname+location.search,{method:'POST',body:fd,signal:ctrl.signal})
      .then(function(r){ clearTimeout(to); return r.json(); },
            function(e){ clearTimeout(to); throw e; });
  }
  function reenable(){ input.disabled=false; send.disabled=false; input.focus(); }
  function enviar(t){
    t=(t||'').trim(); if(!t||cerrado) return;
    me(t); hist.push({rol:'user',texto:t}); input.value=''; input.disabled=true; send.disabled=true;
    var load=loading();
    post({historial:JSON.stringify(hist)}, 60000).then(function(d){
      load.remove();
      if(!d||!d.ok){ ia('Perdona, se me trabó. Repíteme eso último.'); reenable(); return; }
      if(d.done){ cerrar(); return; }
      ia(d.pregunta||'¿Algo más que deba saber?'); hist.push({rol:'ia',texto:d.pregunta||''}); reenable();
    }).catch(function(){ load.remove(); ia('Se me fue el internet un segundo — toca Enviar otra vez.'); reenable(); });
  }
  // Al cerrar el chat se levanta una VENTANA TRANSLÚCIDA donde el corillo "conversa"
  // mientras arma el perfil. Las líneas de proceso son REALES (de verdad está
  // analizando); la DETERMINACIÓN final muestra el perfil REAL producido (voz/público/
  // resumen) — nada inventado. Luego el dueño escoge el TONO y se monta el post.
  var RESUMEN='';
  var TONOS=[
    {k:'profesional',t:'Profesional',d:'Formal y serio. Abogados, médicos, contables.'},
    {k:'boricua',t:'Boricua',d:'Bien de la isla, con sabor y de la calle.'},
    {k:'creativo',t:'Creativo',d:'Con chispa, humor y giros inesperados.'},
    {k:'calido',t:'Cálido',d:'Cercano y de confianza, como un buen amigo.'},
    {k:'vendedor',t:'Vendedor',d:'Directo a la acción, con gancho de venta.'}
  ];
  var CORILLO=[
    ['🧠 El Estratega','Déjenme leer bien lo que nos contó…'],
    ['✍️ La Creativa','Ya le voy cogiendo la forma de hablar.'],
    ['📊 El Estratega','Estoy fijando quién es su cliente ideal.'],
    ['🎨 El Director','Y yo el estilo visual que le va a pegar.'],
    ['🧠 El corillo','Nos estamos poniendo de acuerdo…']
  ];
  var ovFeed=null, ovFoot=null;
  function cpLine(ag, txt, det){ var d=document.createElement('div'); d.className='cp-line'+(det?' det':''); d.innerHTML=(ag?'<b>'+esc(ag)+'</b>':'')+'<span>'+esc(txt)+'</span>'; ovFeed.appendChild(d); ovFeed.scrollTop=ovFeed.scrollHeight; return d; }
  function cerrar(){
    cerrado=true; form.style.display='none'; listen.textContent='';
    var ov=document.createElement('div'); ov.className='corillo-ov';
    ov.innerHTML='<div class="corillo-panel"><div class="cp-head"><span class="cp-dot"></span>El corillo está armando tu perfil</div><div class="cp-feed" id="cpFeed"></div><div class="cp-foot" id="cpFoot" style="display:none"></div></div>';
    document.body.appendChild(ov);
    requestAnimationFrame(function(){ ov.classList.add('on'); });
    ovFeed=ov.querySelector('#cpFeed'); ovFoot=ov.querySelector('#cpFoot');
    var i=0, listo=false;
    (function tick(){ if(listo) return; if(i<CORILLO.length){ cpLine(CORILLO[i][0], CORILLO[i][1]); i++; } setTimeout(tick, 2500); })();
    post({accion:'finalizar', historial:JSON.stringify(hist)}, 95000).then(function(d){
      listo=true;
      if(!d||!d.ok){ salirSuave(); return; }
      RESUMEN=d.resumen||'';
      revelar(d);
    }).catch(function(){ listo=true; salirSuave(); });
  }
  function salirSuave(){ cpLine('🧠 El corillo','Tu negocio quedó guardado — te llevo a tu post…', true); setTimeout(function(){ location.href='/crecer/panel/gateway_post.php?marca='+MARCA+GW; }, 1700); }
  function revelar(d){
    var seq=[];
    if(d.publico) seq.push(['📊 El Estratega','Tu cliente ideal: '+d.publico, 'det']);
    if(d.voz)     seq.push(['✍️ La Creativa','Tu voz: '+d.voz, 'det']);
    seq.push(['🔥 ¡El corillo lo tiene!','¡Ya lo tenemos, esto va a quedar brutal!', 'hype']);
    if(d.resumen) seq.push(['✅ Así te entendimos', d.resumen, 'det']);
    var head=document.querySelector('.cp-head');
    var k=0; (function step(){
      if(k>=seq.length){ setTimeout(function(){ pedirTono(d.preset||'boricua'); }, 900); return; }
      var s=seq[k];
      if(s[2]==='hype'){
        if(head) head.innerHTML='<span class="cp-dot" style="background:var(--teal,#00A49F);animation:none"></span>¡Ya lo tenemos!';
        var el=cpLine(s[0], s[1], true); el.className='cp-line det cp-hype';
      } else { cpLine(s[0], s[1], true); }
      k++; setTimeout(step, s[2]==='hype' ? 1300 : 1500);
    })();
  }
  function pedirTono(pre){
    var sel=pre;
    var html='<h3>Una última cosa</h3><div class="sb">Elegimos este tono por tu tipo de negocio — cámbialo si quieres.</div>';
    TONOS.forEach(function(o){ html+='<button type="button" class="tono'+(o.k===pre?' sel':'')+'" data-k="'+o.k+'"><b>'+o.t+'</b><span>'+o.d+'</span></button>'; });
    html+='<button type="button" class="tono-go" id="tonoGo">Con este vamos →</button>';
    ovFoot.innerHTML=html; ovFoot.style.display='block'; ovFoot.scrollIntoView&&ovFoot.scrollIntoView({block:'end'});
    ovFoot.querySelectorAll('.tono').forEach(function(b){ b.addEventListener('click',function(){ ovFoot.querySelectorAll('.tono').forEach(function(x){x.classList.remove('sel');}); b.classList.add('sel'); sel=b.getAttribute('data-k'); }); });
    document.getElementById('tonoGo').addEventListener('click',function(){ this.disabled=true; this.textContent='Perfecto…'; post({accion:'set_tono',preset:sel},20000).then(crearPost).catch(crearPost); });
  }
  function crearPost(){
    ovFoot.style.display='none';
    cpLine('🎨 El Director','Perfecto. Montando tu primer post en tu voz…');
    var tp=cpLine('', ' '); tp.className='cp-line cp-typing'; tp.innerHTML='<span><i></i><i></i><i></i></span>';
    function ir(url){ cpLine('✅ Listo','¡Tu primer post está montado!', true); setTimeout(function(){ location.href=url; }, 1100); }
    post({accion:'post_muestra'}, 95000).then(function(d2){ ir((d2&&d2.redirect)||('/crecer/panel/gateway_post.php?marca='+MARCA+GW)); }).catch(function(){ ir('/crecer/panel/gateway_post.php?marca='+MARCA+GW); });
  }
  form.addEventListener('submit',function(e){ e.preventDefault(); enviar(input.value); });

  // Voz: dictado (Web Speech). Habla y se manda solo.
  var SR=window.SpeechRecognition||window.webkitSpeechRecognition, mic=document.getElementById('enMic'), rec=null, grabando=false;
  if(!SR){ mic.style.display='none'; }
  else {
    rec=new SR(); rec.lang='es-US'; rec.interimResults=true; rec.continuous=false;
    rec.onresult=function(e){ var f='',i2=''; for(var i=0;i<e.results.length;i++){ var t=e.results[i][0].transcript; if(e.results[i].isFinal)f+=t; else i2+=t; } input.value=(f||i2); };
    rec.onerror=function(){ parar(); }; rec.onend=function(){ parar(); if((input.value||'').trim()) enviar(input.value); };
    function arrancar(){ try{ input.value=''; rec.start(); grabando=true; mic.classList.add('rec'); listen.textContent='Escuchando… habla y para cuando termines.'; }catch(e){} }
    function parar(){ grabando=false; mic.classList.remove('rec'); listen.textContent=''; }
    mic.addEventListener('click',function(){ if(grabando){ try{rec.stop();}catch(e){} } else { arrancar(); } });
  }
})();
</script>

</body></html>
