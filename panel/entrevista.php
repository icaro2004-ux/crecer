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

// ANTI-DUPLICADO: si esta marca YA tiene su post, NO se repite la entrevista (evita
// que 'back' en el browser cree posts infinitos). En GET → directo al escenario.
if (empty($_GET['otra']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $ya_post = (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$marca_id}")->fetchColumn();
    if ($ya_post > 0) { header('Location: /crecer/panel/gateway_post.php?marca=' . $marca_id . $gw); exit; }
}

// ── AJAX: VOZ → texto. El dueño CONTESTA HABLANDO; Gemini transcribe fiel y el
//    texto entra al chat como si lo hubiera escrito. (Antes esto era dictado del
//    navegador: no existía en Firefox, fallaba mudo y escuchaba en es-US. Ahora
//    graba MediaRecorder — universal — y transcribe el mismo motor multimodal
//    del corillo, que sí entiende boricua. Cada transcripción queda en
//    crecer_ia_log.) Prueba viva: _cache.php?test=voz ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'voz') {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_ok()) { echo json_encode(['ok'=>false,'err'=>'Sesión expiró. Recarga.']); exit; }
    if (empty($_FILES['audio']['tmp_name']) || ($_FILES['audio']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        echo json_encode(['ok'=>false,'err'=>'No llegó el audio — intenta de nuevo o escribe.']); exit;
    }
    if ((int)$_FILES['audio']['size'] > 10 * 1024 * 1024) {
        echo json_encode(['ok'=>false,'err'=>'El audio quedó muy largo — contesta en pedazos más cortos.']); exit;
    }
    try {
        $texto = voz_a_texto($pdo, $marca_id,
            base64_encode((string)file_get_contents($_FILES['audio']['tmp_name'])),
            (string)($_FILES['audio']['type'] ?: 'audio/webm'));
        if ($texto === '') { echo json_encode(['ok'=>false,'err'=>'No te escuché bien — prueba otra vez o escribe.']); exit; }
        echo json_encode(['ok'=>true, 'texto'=>$texto], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'err'=>'La transcripción falló: ' . substr($e->getMessage(), 0, 120)], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

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
<link href="/crecer/assets/encuentralo-ui.css?v=<?= ASSET_VER ?>" rel="stylesheet">
<style>
  *{box-sizing:border-box}
  body{background:#fbfaf9;color:var(--tinta,#231F20);position:relative;min-height:100dvh}
  body::before{content:"";position:fixed;inset:0;z-index:-1;pointer-events:none;
    background:radial-gradient(58% 40% at 90% -4%, color-mix(in srgb,var(--magenta,#EF4375) 15%,transparent), transparent 70%),
      radial-gradient(52% 40% at -6% 104%, color-mix(in srgb,var(--teal,#00A49F) 13%,transparent), transparent 72%)}
  .content{max-width:640px;margin:0 auto;padding:0 16px 150px}
  /* Topbar glass */
  .en-bar{position:sticky;top:0;z-index:20;display:flex;align-items:center;gap:10px;padding:12px 16px;margin:0 -16px 2px;
    background:color-mix(in srgb,#fff 72%,transparent);backdrop-filter:blur(14px) saturate(1.3);-webkit-backdrop-filter:blur(14px) saturate(1.3);
    border-bottom:1px solid color-mix(in srgb,var(--line,#E9E7E4) 70%,transparent)}
  .en-bar img{height:24px}
  .en-bar b{font-weight:800;font-size:16px;color:var(--tinta,#231F20);letter-spacing:-.01em}
  .en-bar .t{color:var(--teal,#00A49F)}
  .en-bar .live{margin-left:auto;display:inline-flex;align-items:center;gap:6px;font-size:11.5px;font-weight:700;color:var(--teal,#00A49F);
    background:color-mix(in srgb,var(--teal,#00A49F) 12%,#fff);padding:5px 11px;border-radius:99px}
  .en-bar .live i{width:7px;height:7px;border-radius:50%;background:var(--teal,#00A49F);animation:enpulse2 1.7s infinite}
  @keyframes enpulse2{0%,100%{box-shadow:0 0 0 0 color-mix(in srgb,var(--teal,#00A49F) 50%,transparent)}70%{box-shadow:0 0 0 6px transparent}}
  /* Hero intro con personalidad */
  .en-hero{display:flex;gap:13px;align-items:flex-start;padding:22px 2px 8px}
  .en-hero .face{width:48px;height:48px;border-radius:16px;flex:none;display:grid;place-items:center;color:#fff;
    background:linear-gradient(135deg,var(--coral,#FF6B3D),var(--magenta,#EF4375));box-shadow:0 12px 26px -8px color-mix(in srgb,var(--magenta,#EF4375) 60%,transparent)}
  .en-hero .face svg{width:25px;height:25px}
  .en-hero h1{font-family:'Oswald',sans-serif;font-weight:700;font-size:clamp(23px,6.2vw,28px);letter-spacing:.3px;color:var(--tinta,#231F20);margin:1px 0 0;line-height:1.05}
  .en-hero p{font-size:13.5px;color:var(--muted,#6E6A67);margin:6px 0 0;line-height:1.5}
  /* Thread + burbujas messenger */
  .en-thread{display:flex;flex-direction:column;gap:12px;padding:16px 0}
  .en-row{display:flex;gap:9px;max-width:90%;align-items:flex-end;animation:enrise .4s cubic-bezier(.16,1,.3,1) both}
  .en-row.ia{align-self:flex-start}
  .en-row.me{align-self:flex-end;flex-direction:row-reverse}
  @keyframes enrise{from{opacity:0;transform:translateY(10px) scale(.96)}to{opacity:1;transform:none}}
  .en-face{width:34px;height:34px;border-radius:50%;flex:none;display:grid;place-items:center;color:#fff;
    background:linear-gradient(135deg,var(--coral,#FF6B3D),var(--magenta,#EF4375));box-shadow:0 4px 11px -3px color-mix(in srgb,var(--magenta,#EF4375) 55%,transparent)}
  .en-face svg{width:17px;height:17px;color:#fff}
  .en-b{padding:12px 16px;border-radius:20px;font-size:15px;line-height:1.5;white-space:pre-wrap;word-wrap:break-word}
  .en-row.ia .en-b{background:#fff;border:1px solid color-mix(in srgb,var(--line,#E9E7E4) 80%,transparent);color:var(--tinta,#231F20);border-bottom-left-radius:6px;box-shadow:0 6px 18px -8px rgba(20,12,20,.12)}
  .en-row.me .en-b{background:linear-gradient(135deg,var(--coral,#FF6B3D),var(--magenta,#EF4375));color:#fff;border-bottom-right-radius:6px;box-shadow:0 10px 22px -8px color-mix(in srgb,var(--magenta,#EF4375) 60%,transparent)}
  .en-b.load{color:var(--muted,#6E6A67)}
  .en-dots span{display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--magenta,#EF4375);margin:0 2px;animation:enb 1s infinite}
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
  .cp-feed{padding:16px;overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:13px;min-height:190px;background:color-mix(in srgb,var(--crema,#f7f5f1) 60%,#fff)}
  .cp-line{display:flex;gap:8px;align-items:flex-end;opacity:0;transform:translateY(9px) scale(.97);animation:cpin .34s cubic-bezier(.22,1,.36,1) forwards}
  .cp-line .av{width:31px;height:31px;border-radius:50%;flex:none;display:grid;place-items:center;font-size:15px;background:#fff;border:1.5px solid var(--line);box-shadow:0 3px 8px rgba(20,12,20,.1)}
  .cp-line .bub{max-width:82%}
  .cp-line .nm{font-size:10.5px;font-weight:700;color:var(--magenta,#EF4375);margin:0 0 3px 4px;letter-spacing:.02em;text-transform:uppercase}
  .cp-line .tx{font-size:14px;line-height:1.5;color:var(--tinta);background:#fff;border:1px solid var(--line);border-radius:16px;border-bottom-left-radius:5px;padding:9px 13px;box-shadow:0 2px 9px rgba(20,12,20,.06)}
  .cp-line .tx.pop{animation:cppop .32s cubic-bezier(.22,1,.36,1)}
  .cp-line.det .nm{color:var(--teal,#00A49F)}
  .cp-line.det .tx{background:color-mix(in srgb,var(--teal,#00A49F) 11%,#fff);border-color:color-mix(in srgb,var(--teal,#00A49F) 30%,#fff)}
  .cp-line.cp-hype .nm{color:var(--coral,#FF6B3D)}
  .cp-line.cp-hype .tx{font-weight:700;border-color:color-mix(in srgb,var(--magenta,#EF4375) 38%,#fff);
    background:linear-gradient(135deg,color-mix(in srgb,var(--coral,#FF6B3D) 22%,#fff),color-mix(in srgb,var(--magenta,#EF4375) 22%,#fff))}
  .cp-line.cp-hype .av{border-color:var(--magenta,#EF4375);animation:cppop .5s}
  @keyframes cppop{0%{transform:scale(.82)}58%{transform:scale(1.05)}100%{transform:scale(1)}}
  @keyframes cpin{to{opacity:1;transform:none}}
  .cp-typing{display:inline-flex;gap:4px;padding:3px 1px}
  .cp-typing i{width:6px;height:6px;border-radius:50%;background:var(--muted);animation:cptype 1s infinite}
  .cp-typing i:nth-child(2){animation-delay:.15s}.cp-typing i:nth-child(3){animation-delay:.3s}
  @keyframes cptype{0%,60%,100%{opacity:.3;transform:translateY(0)}30%{opacity:1;transform:translateY(-3px)}}
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
  .en-form{display:flex;gap:9px;align-items:center;position:fixed;left:0;right:0;bottom:0;z-index:20;max-width:640px;margin:0 auto;
    padding:12px 16px calc(12px + env(safe-area-inset-bottom));
    background:color-mix(in srgb,#fff 80%,transparent);backdrop-filter:blur(16px) saturate(1.3);-webkit-backdrop-filter:blur(16px) saturate(1.3);
    border-top:1px solid color-mix(in srgb,var(--line,#E9E7E4) 70%,transparent)}
  .en-input{flex:1;font-family:inherit;font-size:16px;border:1.5px solid var(--line,#E9E7E4);border-radius:99px;padding:14px 18px;background:#fff;color:var(--tinta,#231F20);transition:border-color .18s,box-shadow .18s}
  .en-input:focus{outline:0;border-color:var(--magenta,#EF4375);box-shadow:0 0 0 4px color-mix(in srgb,var(--magenta,#EF4375) 14%,transparent)}
  .en-mic{border:1.5px solid var(--line,#E9E7E4);background:#fff;cursor:pointer;width:52px;height:52px;border-radius:50%;flex:none;display:grid;place-items:center;color:var(--tinta,#231F20);transition:transform .15s,border-color .15s,color .15s}
  .en-mic:hover{border-color:var(--teal,#00A49F);color:var(--teal,#00A49F)}
  .en-mic:active{transform:scale(.94)}
  .en-mic svg{width:22px;height:22px}
  .en-mic.rec{background:var(--magenta,#EF4375);border-color:transparent;color:#fff;animation:enpulse 1.1s infinite}
  .en-mic.rec svg{color:#fff}
  .en-mic.on{border-color:var(--teal,#00A49F);color:var(--teal,#00A49F);box-shadow:0 0 0 3px color-mix(in srgb,var(--teal,#00A49F) 14%,transparent)}
  @keyframes enpulse{0%,100%{box-shadow:0 0 0 0 color-mix(in srgb,var(--magenta,#EF4375) 45%,transparent)}70%{box-shadow:0 0 0 12px transparent}}
  .en-send{border:0;cursor:pointer;background:linear-gradient(135deg,var(--coral,#FF6B3D),var(--magenta,#EF4375));color:#fff;font-weight:800;height:52px;padding:0 22px;border-radius:99px;font-family:inherit;font-size:15px;flex:none;
    box-shadow:0 10px 22px -8px color-mix(in srgb,var(--magenta,#EF4375) 60%,transparent);transition:transform .15s,box-shadow .2s}
  .en-send:hover{box-shadow:0 14px 28px -8px color-mix(in srgb,var(--magenta,#EF4375) 72%,transparent)}
  .en-send:active{transform:scale(.97)}
  .en-send:disabled{opacity:.5;box-shadow:none}
  .en-listen{position:fixed;left:0;right:0;bottom:78px;z-index:19;text-align:center;font-size:12px;color:var(--teal,#00A49F);font-weight:700;min-height:16px;pointer-events:none}
  @media(prefers-reduced-motion:reduce){*{animation-duration:.01ms!important;transition-duration:.01ms!important}}
</style>
</head>
<body>
<div class="en-bar">
  <img src="/crecer/assets/brand/crecer-icon.png" alt="">
  <b style="display:inline-flex;flex-direction:column;line-height:1;gap:1px"><span style="color:var(--teal)">Crecer</span><span style="font-size:.5em;font-weight:500;color:var(--muted);letter-spacing:.02em;margin-top:1px">by Encuéntralo</span></b>
  <span class="live"><i></i>El corillo · en línea</span>
</div>
<div class="content">

<div class="en-hero">
  <div class="face"><?= ico('chat') ?></div>
  <div>
    <h1>Cuéntame de tu negocio</h1>
    <p>Es una conversación, no un formulario. Unas preguntas cortas y el corillo se encarga de todo lo demás.</p>
  </div>
</div>

<div class="en-thread" id="enMsgs">
  <div class="en-row ia"><div class="en-face"><?= ico('chat') ?></div><div class="en-b"><?= $h($primera) ?></div></div>
</div>
</div><!-- /content -->

<form class="en-form" id="enForm" autocomplete="off">
  <button type="button" class="en-mic" id="enMic" title="Hablar" aria-label="Hablar"><?= ico('mic') ?></button>
  <button type="button" class="en-mic" id="enTts" title="Escuchar al corillo" aria-label="Escuchar al corillo"><?= ico('volume') ?></button>
  <input type="text" class="en-input" id="enInput" placeholder="Escribe o toca el micrófono…" maxlength="1200" aria-label="Tu respuesta">
  <button type="submit" class="en-send" id="enSend">Enviar</button>
</form>
<div class="en-listen" id="enListen"></div>

<script>
(function(){
  var CSRF=<?= json_encode(csrf_token()) ?>, MARCA=<?= (int)$marca_id ?>, GW=<?= json_encode($gw) ?>, FACE=<?= json_encode(ico('chat')) ?>, PRIMERA=<?= json_encode($primera, JSON_UNESCAPED_UNICODE) ?>;
  var msgs=document.getElementById('enMsgs'), form=document.getElementById('enForm'), input=document.getElementById('enInput'),
      send=document.getElementById('enSend'), listen=document.getElementById('enListen');
  var hist=[{rol:'ia',texto:PRIMERA}], cerrado=false;
  function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}
  function scroll(el){el.scrollIntoView({behavior:'smooth',block:'end'});}
  function me(t){var r=document.createElement('div');r.className='en-row me';r.innerHTML='<div class="en-b">'+esc(t)+'</div>';msgs.appendChild(r);scroll(r);}
  function ia(t){var r=document.createElement('div');r.className='en-row ia';r.innerHTML='<div class="en-face">'+FACE+'</div><div class="en-b">'+esc(t)+'</div>';msgs.appendChild(r);scroll(r);di(t);}
  function loading(){var r=document.createElement('div');r.className='en-row ia';r.innerHTML='<div class="en-face">'+FACE+'</div><div class="en-b load"><span class="en-dots"><span></span><span></span><span></span></span></div>';msgs.appendChild(r);scroll(r);return r;}
  function done(resumen, redirect){
    cerrado=true; form.style.display='none'; listen.textContent='';
    var url = redirect || ('/crecer/panel/gateway_post.php?marca='+MARCA+GW);
    var d=document.createElement('div'); d.className='en-done';
    d.innerHTML='<h3>✓ Ya entiendo tu negocio</h3>'+(resumen?'<p>'+esc(resumen)+'</p>':'<p>Armé tu perfil y el corillo ya lo tiene.</p>')
      +'<p style="margin-top:10px;font-weight:700;color:var(--teal,#00A49F)">Acaba de nacer el Genoma de tu negocio — el cerebro que solo existe para ti, y no va a parar de crecer.</p>'
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
  var CORILLO=[   // [icono, nombre, texto]
    [FACE,'El Estratega','Déjenme leer bien lo que nos contó…'],
    [FACE,'La Creativa','Ya le voy cogiendo la forma de hablar.'],
    [FACE,'El Estratega','Estoy fijando quién es su cliente ideal.'],
    [FACE,'El Director','Y yo el estilo visual que le va a pegar.'],
    [FACE,'El corillo','Nos estamos poniendo de acuerdo…']
  ];
  var ovFeed=null, ovFoot=null;
  // Burbuja estilo messenger: sale "escribiendo…" y luego el texto hace POP.
  function cpSay(av, nm, tx, cls){
    var line=document.createElement('div'); line.className='cp-line'+(cls?(' '+cls):'');
    line.innerHTML='<div class="av">'+av+'</div><div class="bub"><div class="nm">'+esc(nm)+'</div><div class="tx"><span class="cp-typing"><i></i><i></i><i></i></span></div></div>';
    ovFeed.appendChild(line); ovFeed.scrollTop=ovFeed.scrollHeight;
    var txEl=line.querySelector('.tx');
    setTimeout(function(){ txEl.classList.add('pop'); txEl.textContent=tx; ovFeed.scrollTop=ovFeed.scrollHeight; }, 620);
    return line;
  }
  function cerrar(){
    cerrado=true; form.style.display='none'; listen.textContent='';
    var ov=document.createElement('div'); ov.className='corillo-ov';
    ov.innerHTML='<div class="corillo-panel"><div class="cp-head"><span class="cp-dot"></span>El corillo está armando tu perfil</div><div class="cp-feed" id="cpFeed"></div><div class="cp-foot" id="cpFoot" style="display:none"></div></div>';
    document.body.appendChild(ov);
    requestAnimationFrame(function(){ ov.classList.add('on'); });
    ovFeed=ov.querySelector('#cpFeed'); ovFoot=ov.querySelector('#cpFoot');
    var i=0, listo=false;
    (function tick(){ if(listo) return; if(i<CORILLO.length){ cpSay(CORILLO[i][0], CORILLO[i][1], CORILLO[i][2]); i++; } setTimeout(tick, 2500); })();
    post({accion:'finalizar', historial:JSON.stringify(hist)}, 95000).then(function(d){
      listo=true;
      if(!d||!d.ok){ salirSuave(); return; }
      RESUMEN=d.resumen||'';
      revelar(d);
    }).catch(function(){ listo=true; salirSuave(); });
  }
  function salirSuave(){ cpSay(FACE,'El corillo','Tu negocio quedó guardado — te llevo a tu post…','det'); setTimeout(function(){ location.href='/crecer/panel/gateway_post.php?marca='+MARCA+GW; }, 1900); }
  function revelar(d){
    var seq=[];
    if(d.publico) seq.push([FACE,'El Estratega','Tu cliente ideal: '+d.publico,'det']);
    if(d.voz)     seq.push([FACE,'La Creativa','Tu voz: '+d.voz,'det']);
    seq.push([FACE,'¡El corillo lo tiene!','¡Ya lo tenemos, esto va a quedar brutal!','cp-hype']);
    if(d.resumen) seq.push([FACE,'Así te entendimos', d.resumen,'det']);
    var head=document.querySelector('.cp-head');
    var k=0; (function step(){
      if(k>=seq.length){ setTimeout(function(){ pedirTono(d.preset||'boricua'); }, 1000); return; }
      var s=seq[k];
      if(s[3]==='cp-hype' && head) head.innerHTML='<span class="cp-dot" style="background:#fff"></span>¡Ya lo tenemos!';
      cpSay(s[0], s[1], s[2], s[3]);
      k++; setTimeout(step, s[3]==='cp-hype' ? 1600 : 1750);
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
    var head=document.querySelector('.cp-head'); if(head) head.innerHTML='<span class="cp-dot"></span>Montando tu primer post…';
    cpSay(FACE,'El Director','Dame unos segundos… lo bueno no se apura. Estoy montando tu primer post con calma.');
    // Burbuja "cocinando" que rota mensajes cool mientras trabaja (el arte tarda 30-40s):
    // así se siente VIVO y con paciencia, no trancado.
    var frases=[
      'Esto toma unos segundos, pero vale cada uno.',
      'Buscando la toma perfecta para tu negocio…',
      'Ajustando la luz como un fotógrafo de verdad…',
      'Aquí no salimos con cualquier cosa — puliendo detalles…',
      'Cocinando algo que va a parar el scroll…',
      'Casi, casi… esto va a quedar brutal.'
    ];
    var row=document.createElement('div'); row.className='cp-line'; var txEl=null, fi=0, rot=null;
    row.innerHTML='<div class="av">'+FACE+'</div><div class="bub"><div class="nm">El corillo</div><div class="tx pop"></div></div>';
    setTimeout(function(){
      ovFeed.appendChild(row); txEl=row.querySelector('.tx'); txEl.textContent=frases[0]; ovFeed.scrollTop=ovFeed.scrollHeight;
      rot=setInterval(function(){ if(!txEl) return; fi=(fi+1)%frases.length; txEl.classList.remove('pop'); void txEl.offsetWidth; txEl.classList.add('pop'); txEl.textContent=frases[fi]; }, 4500);
    }, 720);
    function ir(url){ if(rot) clearInterval(rot); if(row.parentNode) row.remove(); cpSay(FACE,'Listo','¡Tu primer post está montado!','det'); setTimeout(function(){ location.href=url; }, 1200); }
    post({accion:'post_muestra'}, 95000).then(function(d2){ ir((d2&&d2.redirect)||('/crecer/panel/gateway_post.php?marca='+MARCA+GW)); }).catch(function(){ ir('/crecer/panel/gateway_post.php?marca='+MARCA+GW); });
  }
  form.addEventListener('submit',function(e){ e.preventDefault(); enviar(input.value); });

  // ── VOZ (entrada): GRABAS tu respuesta y Gemini la transcribe — el mismo motor
  //    multimodal del corillo, que sí entiende boricua. (El dictado del navegador
  //    se quitó: no existía en Firefox, fallaba MUDO y escuchaba en es-US.)
  //    Si el mic no da permiso, se escribe y ya — nunca tranca.
  var mic=document.getElementById('enMic'), mrec=null, mstream=null, mchunks=[], grabando=false, mTimer=null, msecs=0;
  if(!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder){ mic.style.display='none'; }
  else {
    mic.addEventListener('click', function(){
      if(cerrado) return;
      if(grabando){ try{mrec.stop();}catch(e){} return; }
      navigator.mediaDevices.getUserMedia({audio:true}).then(function(stream){
        mstream=stream; mchunks=[];
        try{ mrec=new MediaRecorder(stream); }
        catch(e){ listen.textContent='Este navegador no puede grabar — escribe tu respuesta, tranqui.'; return; }
        mrec.ondataavailable=function(e){ if(e.data&&e.data.size) mchunks.push(e.data); };
        mrec.onstop=function(){
          grabando=false; mic.classList.remove('rec'); if(mTimer){clearInterval(mTimer);mTimer=null;}
          mstream.getTracks().forEach(function(t){t.stop();});
          var blob=new Blob(mchunks,{type:(mrec.mimeType||'audio/webm')});
          if(blob.size<800){ listen.textContent='No se grabó nada — intenta otra vez.'; return; }
          listen.textContent='El corillo te está escuchando…';
          input.disabled=true; send.disabled=true;
          post({accion:'voz', audio:blob}, 45000).then(function(d){
            reenable();
            if(!d||!d.ok){ listen.textContent=(d&&d.err)||'No pude transcribirte — escríbelo, tranqui.'; return; }
            listen.textContent=''; if(!ttsUser) ttsSet(true);   // me hablaste → te hablo
            input.value=d.texto; enviar(d.texto);
          }).catch(function(){ reenable(); listen.textContent='Se cayó la conexión — toca el micrófono otra vez o escribe.'; });
        };
        mrec.start(); grabando=true; msecs=0; mic.classList.add('rec');
        listen.textContent='Grabando… toca el micrófono otra vez cuando termines.';
        mTimer=setInterval(function(){ if(++msecs>=90){ try{mrec.stop();}catch(e){} } },1000);
      }).catch(function(){ listen.textContent='El navegador no dio permiso de micrófono — escribe tu respuesta, tranqui.'; });
    });
  }

  // ── VOZ (salida): "si me hablas, te hablo" — la primera vez que usas el
  //    micrófono, el corillo empieza a LEER sus preguntas en voz alta (voz del
  //    sistema, $0, sin red). La bocina lo prende/apaga cuando quieras.
  var ttsBtn=document.getElementById('enTts'), ttsOn=false, ttsUser=false, ttsVoz=null;
  function ttsPick(){ if(ttsVoz) return ttsVoz; var vs=window.speechSynthesis?window.speechSynthesis.getVoices():[]; ttsVoz=vs.find(function(v){return /es[-_](PR|US|419)/i.test(v.lang);})||vs.find(function(v){return /^es/i.test(v.lang);})||null; return ttsVoz; }
  if(window.speechSynthesis){ try{ window.speechSynthesis.onvoiceschanged=function(){ ttsVoz=null; ttsPick(); }; }catch(e){} }
  function di(t){ if(!ttsOn||!window.speechSynthesis||!t) return; try{ window.speechSynthesis.cancel(); var u=new SpeechSynthesisUtterance(t); var v=ttsPick(); if(v) u.voice=v; u.lang=(v&&v.lang)||'es-US'; u.rate=1.02; window.speechSynthesis.speak(u); }catch(e){} }
  function ttsSet(on){ ttsOn=!!on&&!!window.speechSynthesis; if(ttsBtn){ ttsBtn.classList.toggle('on',ttsOn); ttsBtn.title=ttsOn?'El corillo lee en voz alta — toca para callarlo':'Escuchar al corillo'; } if(!ttsOn&&window.speechSynthesis){ try{window.speechSynthesis.cancel();}catch(e){} } }
  if(ttsBtn){ if(!window.speechSynthesis){ ttsBtn.style.display='none'; } else { ttsBtn.addEventListener('click', function(){ ttsUser=true; ttsSet(!ttsOn); }); } }
})();
</script>

</body></html>
