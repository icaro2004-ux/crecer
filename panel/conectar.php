<?php
// ============================================================
//  CRECER — Conectar redes (OAuth de Meta: IG Business + FB Page)
//  panel/conectar.php
//
//  Flujo:
//   1) El dueño da "Conectar" → lo mandamos al login de Meta.
//   2) Meta regresa con ?code → cambiamos por token de larga
//      duración y listamos sus Páginas + cuentas de IG Business.
//   3) Elige la Página → guardamos la conexión (crecer_conexiones).
//
//  Requisito del dueño (su parte): cuenta de IG Business/Creator
//  conectada a una Página de Facebook. Se lo explicamos aquí.
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/meta.php';
requiere_login();

$usuario = usuario_actual($pdo);
$USUARIO_ID = (int)$usuario['id'];
$marca = marca_del_usuario($pdo, $USUARIO_ID, isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }
$marca_id = (int)$marca['id'];
$BASE = '/crecer/panel';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// ¿Vengo del GATEWAY? (para volver al ESCENARIO del post, no al app). Se guarda en
// sesión porque el rebote de OAuth pierde los query params. El app usa el default.
if (($_GET['desde'] ?? '') === 'gateway') $_SESSION['conectar_return'] = 'gateway';
$es_gateway = ($_SESSION['conectar_return'] ?? '') === 'gateway';
$volver_url = $es_gateway ? ('/crecer/panel/gateway_post.php?marca=' . $marca_id) : ($BASE . '/index.php?marca=' . $marca_id);

$err = ''; $info = '';

// ── Desconectar ──────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'desconectar' && $_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $pdo->prepare("DELETE FROM crecer_conexiones WHERE marca_id=?")->execute([$marca_id]);
    header("Location: $BASE/conectar.php?marca=$marca_id&ok=desc"); exit;
}

// ── Guardar la Página elegida (paso 3) ───────────────────────
if (($_POST['paso'] ?? '') === 'elegir' && csrf_ok()) {
    $sel = $_POST['pagina'] ?? '';
    $cand = $_SESSION['meta_paginas'][$marca_id] ?? [];
    $elegida = null;
    foreach ($cand as $p) { if (($p['fb_page_id'] ?? '') === $sel) { $elegida = $p; break; } }
    if ($elegida) {
        $exp = !empty($_SESSION['meta_token_exp'][$marca_id])
             ? date('Y-m-d H:i:s', (int)$_SESSION['meta_token_exp'][$marca_id]) : null;
        guardar_conexion($pdo, $marca_id, $elegida + ['token_expira' => $exp]);
        unset($_SESSION['meta_paginas'][$marca_id]);
        header("Location: $BASE/conectar.php?marca=$marca_id&ok=conx"); exit;
    }
    $err = 'No pude identificar la página elegida. Intenta conectar de nuevo.';
}

// ── Iniciar OAuth (paso 1) ───────────────────────────────────
if (($_GET['action'] ?? '') === 'iniciar') {
    if (!meta_configurado()) { $err = 'Falta configurar la app de Meta (META_APP_ID/SECRET).'; }
    else {
        $rand = bin2hex(random_bytes(12));
        $_SESSION['meta_state'] = ['marca' => $marca_id, 'rand' => $rand];
        header('Location: ' . meta_oauth_url($marca_id . ':' . $rand)); exit;
    }
}

// ── Callback de Meta (paso 2) ────────────────────────────────
if (isset($_GET['code']) || isset($_GET['error'])) {
    if (isset($_GET['error'])) {
        $err = 'Meta canceló la conexión: ' . $h($_GET['error_description'] ?? $_GET['error']);
    } else {
        $state = explode(':', (string)($_GET['state'] ?? ''), 2);
        $ss = $_SESSION['meta_state'] ?? null;
        if (!$ss || (int)$state[0] !== $marca_id || ($state[1] ?? '') !== ($ss['rand'] ?? '')) {
            $err = 'La sesión de conexión no cuadró (state inválido). Intenta de nuevo.';
        } else {
            unset($_SESSION['meta_state']);
            try {
                $corto = meta_token_desde_codigo((string)$_GET['code']);
                $largo = meta_token_largo($corto);
                $paginas = meta_paginas($largo);
                if (!$paginas) {
                    $err = 'No encontré ninguna Página de Facebook que administres. '
                         . 'Asegúrate de tener una Página y de darle permiso en el paso anterior.';
                } else {
                    $_SESSION['meta_paginas'][$marca_id] = $paginas;
                    $_SESSION['meta_token_exp'][$marca_id] = time() + 60 * 24 * 3600; // ~60 días
                }
            } catch (Throwable $e) {
                $err = 'Falló la conexión con Meta: ' . $h(substr($e->getMessage(), 0, 160));
            }
        }
    }
}

if (($_GET['ok'] ?? '') === 'conx') $info = '¡Conectado! El corillo ya puede publicar por ti.';
if (($_GET['ok'] ?? '') === 'desc') $info = 'Desconectaste tus redes. El corillo ya no publicará automático.';

$conx = conexion_de_marca($pdo, $marca_id);
$paginas = $_SESSION['meta_paginas'][$marca_id] ?? [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Conectar redes · <?= $h($marca['nombre_negocio']) ?></title>
<link rel="icon" type="image/png" href="/crecer/assets/brand/crecer-icon.png">
<link rel="apple-touch-icon" href="/crecer/assets/brand/crecer-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="/crecer/assets/encuentralo-ui.css?v=20" rel="stylesheet">
<style>
  body{background:var(--crema);font-family:var(--font-body)}
  .cx{max-width:520px;margin:0 auto;padding:26px 20px 90px}
  .back{display:inline-flex;align-items:center;gap:6px;color:var(--muted);text-decoration:none;font-family:var(--font-display);font-weight:500;font-size:14px;margin-bottom:22px}
  .back:hover{color:var(--ink-soft)}
  h1{font-family:var(--font-display);font-weight:600;font-size:clamp(26px,5.6vw,32px);letter-spacing:-.02em;line-height:1.1;color:var(--ink-soft);margin:0}
  .sub{color:var(--muted);font-size:15.5px;margin:8px 0 22px;line-height:1.5}
  .card{background:var(--card);border:1px solid var(--line);border-radius:20px;padding:22px;box-shadow:var(--shadow);margin-bottom:14px}
  .msg{padding:12px 15px;border-radius:12px;font-weight:600;font-size:14px;margin-bottom:14px;line-height:1.45;display:flex;gap:9px;align-items:flex-start}
  .msg svg{width:17px;height:17px;flex:none;margin-top:1px}
  .msg.err{background:#fdeaea;color:#b42318;border:1px solid #f5c2c0}
  .msg.ok{background:color-mix(in srgb,var(--teal) 10%,#fff);color:var(--teal-dark,#00827e);border:1px solid color-mix(in srgb,var(--teal) 25%,#fff)}
  /* filas de estado: qué está conectado / qué falta */
  .row{display:flex;align-items:center;gap:13px;padding:15px 0;border-bottom:1px solid var(--line)}
  .row:last-of-type{border-bottom:0}
  .row .ic{width:40px;height:40px;border-radius:12px;flex:none;display:grid;place-items:center;color:#fff}
  .row .ic.ig{background:linear-gradient(135deg,#f9ce34,#ee2a7b 45%,#6228d7)}
  .row .ic.fb{background:#1877F2}
  .row .ic svg{width:22px;height:22px}
  .row .tx{min-width:0;flex:1}
  .row .nm{font-family:var(--font-display);font-weight:600;font-size:15px;color:var(--ink-soft)}
  .row .ds{font-size:13px;color:var(--muted);margin-top:1px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .pill{flex:none;font-family:var(--font-display);font-weight:600;font-size:12px;padding:5px 11px;border-radius:99px}
  .pill.on{color:var(--teal-dark,#00827e);background:color-mix(in srgb,var(--teal) 12%,#fff)}
  .pill.off{color:#a06a00;background:#fff4d6}
  /* botones */
  .btn{display:inline-flex;align-items:center;justify-content:center;gap:10px;width:100%;border:0;cursor:pointer;font-family:var(--font-display);font-weight:600;font-size:16px;color:#fff;background:var(--btn-grad);box-shadow:var(--btn-glow);padding:15px 20px;border-radius:16px;text-decoration:none;transition:transform .2s var(--ease),box-shadow .2s var(--ease)}
  .btn:active{transform:translateY(1px);box-shadow:var(--btn-glow-active)}
  .btn.fb{background:#1877F2;box-shadow:0 12px 26px -12px rgba(24,119,242,.6)}
  .btn.fb svg{width:20px;height:20px}
  .quiet{display:block;width:100%;text-align:center;background:0;border:0;cursor:pointer;font-family:var(--font-display);font-weight:500;font-size:14px;color:var(--muted);padding:14px 0 2px;margin-top:6px}
  .quiet:hover{color:var(--ink-soft)}
  .need{display:block;width:100%;text-align:center;background:0;border:0;cursor:pointer;font-family:var(--font-display);font-weight:500;font-size:14px;color:var(--teal-dark,#00827e);padding:16px 0 0}
  /* elegir página */
  .pg{display:flex;align-items:center;gap:12px;padding:14px;border:1.5px solid var(--line);border-radius:14px;margin-bottom:10px;cursor:pointer;transition:border-color .15s}
  .pg:has(input:checked){border-color:var(--magenta);background:color-mix(in srgb,var(--magenta) 4%,#fff)}
  .pg input{width:18px;height:18px;accent-color:var(--magenta)}
  .pg .nm{font-family:var(--font-display);font-weight:600;font-size:15px;color:var(--ink-soft)}
  .pg .sub{font-size:13px;color:var(--muted);margin-top:1px}
  .amber{background:#fff7e6;color:#8a5a00;border:1px solid #f2d488;border-radius:12px;padding:13px 15px;margin-top:14px;font-size:13.5px;line-height:1.5}
  .amber b{color:#6b4600}
  /* bottom sheet: "¿Qué necesito?" (progressive disclosure) */
  .sheet-ov{position:fixed;inset:0;z-index:100;background:rgba(20,12,20,.5);opacity:0;pointer-events:none;transition:opacity .3s var(--ease)}
  .sheet-ov.show{opacity:1;pointer-events:auto}
  .sheet{position:fixed;left:0;right:0;bottom:0;z-index:101;background:var(--card);border-radius:24px 24px 0 0;padding:8px 22px calc(24px + env(safe-area-inset-bottom));
    box-shadow:0 -20px 60px -20px rgba(20,12,20,.4);transform:translateY(100%);transition:transform .34s var(--ease);max-width:520px;margin:0 auto}
  .sheet.show{transform:none}
  .sheet .grip{width:38px;height:4px;border-radius:99px;background:var(--line);margin:8px auto 14px}
  .sheet h2{font-family:var(--font-display);font-weight:600;font-size:18px;color:var(--ink-soft);letter-spacing:-.01em;margin:0 0 12px}
  .sheet ol{margin:0;padding-left:20px;display:flex;flex-direction:column;gap:10px}
  .sheet li{font-size:14.5px;color:var(--tinta);line-height:1.45}
  .sheet li b{color:var(--ink-soft);font-weight:600}
  .sheet .fine{font-size:13px;color:var(--muted);margin-top:14px;line-height:1.5}
  @media(min-width:721px){
    /* Desktop: hay espacio → los requisitos viven inline (no hace falta sheet) */
    .sheet-ov{display:none}
    .sheet{position:static;transform:none;box-shadow:none;border-radius:16px;border:1px solid var(--line);margin-top:14px;padding:20px 22px;max-width:none}
    .sheet .grip{display:none}
    .need{display:none}
  }
  @media(prefers-reduced-motion:reduce){.sheet,.sheet-ov{transition:none}}
</style>
</head>
<body>
<?php
  $ig_on = $conx && $conx['estado']==='activa' && !empty($conx['ig_username']);
  $fb_on = $conx && $conx['estado']==='activa' && !empty($conx['fb_page_nombre']);
  $ig_svg = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.2c3.2 0 3.6 0 4.85.07 1.17.05 1.8.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.42.36 1.06.41 2.23.06 1.27.07 1.65.07 4.85s0 3.58-.07 4.85c-.05 1.17-.25 1.8-.41 2.23-.22.56-.48.96-.9 1.38-.42.42-.82.68-1.38.9-.42.16-1.06.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58 0-4.85-.07c-1.17-.05-1.8-.25-2.23-.41a3.7 3.7 0 0 1-1.38-.9 3.7 3.7 0 0 1-.9-1.38c-.16-.42-.36-1.06-.41-2.23C2.2 15.58 2.2 15.2 2.2 12s0-3.58.07-4.85c.05-1.17.25-1.8.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.16 1.06-.36 2.23-.41C8.42 2.2 8.8 2.2 12 2.2Zm0 1.8c-3.15 0-3.5 0-4.74.07-.9.04-1.38.19-1.7.32-.43.16-.73.36-1.05.68-.32.32-.52.62-.68 1.05-.13.32-.28.8-.32 1.7C3.24 8.6 3.24 8.95 3.24 12s0 3.4.07 4.63c.04.9.19 1.38.32 1.7.16.43.36.73.68 1.05.32.32.62.52 1.05.68.32.13.8.28 1.7.32 1.24.07 1.59.07 4.74.07s3.5 0 4.74-.07c.9-.04 1.38-.19 1.7-.32.43-.16.73-.36 1.05-.68.32-.32.52-.62.68-1.05.13-.32.28-.8.32-1.7.07-1.23.07-1.58.07-4.63s0-3.4-.07-4.63c-.04-.9-.19-1.38-.32-1.7a2.8 2.8 0 0 0-.68-1.05 2.8 2.8 0 0 0-1.05-.68c-.32-.13-.8-.28-1.7-.32C15.5 4 15.15 4 12 4Zm0 3.06A4.94 4.94 0 1 1 12 17a4.94 4.94 0 0 1 0-9.88Zm0 1.8a3.14 3.14 0 1 0 0 6.28 3.14 3.14 0 0 0 0-6.28Zm5.15-.9a1.15 1.15 0 1 1-2.3 0 1.15 1.15 0 0 1 2.3 0Z"/></svg>';
  $fb_svg = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M24 12a12 12 0 1 0-13.875 11.854v-8.385H7.078V12h3.047V9.356c0-3.007 1.792-4.668 4.533-4.668 1.312 0 2.686.234 2.686.234v2.953H15.83c-1.49 0-1.955.925-1.955 1.874V12h3.328l-.532 3.469h-2.796v8.385A12.002 12.002 0 0 0 24 12z"/></svg>';
  $check = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
  $warn = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>';
?>
<div class="cx">
  <a class="back" href="<?= $h($volver_url) ?>">← Volver</a>
  <h1>Conecta tus redes</h1>
  <p class="sub">Una vez. Después el corillo publica solo, a la hora que toca.</p>

  <?php if ($err): ?><div class="msg err"><?= $warn ?><span><?= $err ?></span></div><?php endif; ?>
  <?php if ($info): ?><div class="msg ok"><?= $check ?><span><?= $h($info) ?></span></div><?php endif; ?>

  <?php if ($paginas): ?>
    <!-- PASO 3: elegir página -->
    <div class="card">
      <div style="font-family:var(--font-display);font-weight:600;font-size:16px;color:var(--ink-soft);margin-bottom:12px">¿En cuál página publicamos?</div>
      <form method="post" action="?marca=<?= $marca_id ?>">
        <?= csrf_field() ?><input type="hidden" name="paso" value="elegir">
        <?php foreach ($paginas as $i => $p): ?>
          <label class="pg">
            <input type="radio" name="pagina" value="<?= $h($p['fb_page_id']) ?>" <?= $i===0?'checked':'' ?>>
            <span class="tx">
              <span class="nm"><?= $h($p['fb_page_nombre'] ?: 'Página sin nombre') ?></span>
              <span class="sub"><?= $p['ig_username'] ? '@' . $h($p['ig_username']) . ' · Instagram listo' : 'Sin Instagram Business — solo Facebook' ?></span>
            </span>
          </label>
        <?php endforeach; ?>
        <button class="btn" type="submit" style="margin-top:6px">Usar esta página</button>
      </form>
    </div>

  <?php elseif ($conx && $conx['estado'] === 'activa'): ?>
    <!-- YA CONECTADO: estado claro (qué está / qué falta) -->
    <div class="card">
      <div class="row">
        <span class="ic ig"><?= $ig_svg ?></span>
        <span class="tx"><span class="nm">Instagram</span><span class="ds"><?= $ig_on ? '@'.$h($conx['ig_username']) : 'Falta activar tu cuenta' ?></span></span>
        <span class="pill <?= $ig_on?'on':'off' ?>"><?= $ig_on?'Conectado':'Falta' ?></span>
      </div>
      <div class="row">
        <span class="ic fb"><?= $fb_svg ?></span>
        <span class="tx"><span class="nm">Facebook</span><span class="ds"><?= $h($conx['fb_page_nombre'] ?: '—') ?></span></span>
        <span class="pill <?= $fb_on?'on':'off' ?>"><?= $fb_on?'Conectado':'Falta' ?></span>
      </div>
    </div>
    <?php $algo_on = $ig_on || $fb_on; ?>
    <?php if ($algo_on): ?>
      <!-- Con UNA sola red ya se puede publicar — nada de bloquear. -->
      <a class="btn" href="<?= $h($volver_url) ?>" style="margin-top:4px"><?= $check ?> Listo — <?= $es_gateway ? 'volver a publicar' : 'ir a mi panel' ?></a>
      <?php if (!$ig_on && $fb_on): ?>
        <div class="amber" style="margin-top:12px">Publicaremos en <b>Facebook</b>. ¿Quieres <b>Instagram</b> también? Ponlo en Business/Creator, enlázalo a tu Página y dale <a href="?action=iniciar&marca=<?= $marca_id ?>" style="color:#6b4600;font-weight:700">volver a conectar</a>. (Opcional — no hace falta para publicar.)</div>
      <?php elseif ($ig_on && !$fb_on): ?>
        <div class="amber" style="margin-top:12px">Publicaremos en <b>Instagram</b>. Tu <b>Página de Facebook</b> también se puede añadir si quieres, pero no hace falta.</div>
      <?php else: ?>
        <a class="quiet" href="?action=iniciar&marca=<?= $marca_id ?>">Volver a conectar / actualizar</a>
      <?php endif; ?>
    <?php else: ?>
      <!-- Conexión activa pero sin IG ni FB utilizables → sí hace falta reintentar. -->
      <div class="card" style="margin-top:0">
        <div class="amber" style="margin-top:0"><b>No pude leer ninguna red publicable.</b> Vuelve a conectar y elige tu Página (con Instagram Business enlazado si lo tienes).</div>
        <a class="btn" href="?action=iniciar&marca=<?= $marca_id ?>" style="margin-top:14px">Volver a conectar</a>
      </div>
    <?php endif; ?>
    <form method="post" action="?action=desconectar&marca=<?= $marca_id ?>" onsubmit="return confirm('¿Seguro que quieres desconectar? El corillo dejará de publicar automático.')">
      <?= csrf_field() ?><button class="quiet" type="submit" style="color:var(--noo-ink,#b42318)">Desconectar</button>
    </form>

  <?php else: ?>
    <!-- PASO 1: iniciar -->
    <div class="card">
      <?php if (!meta_configurado()): ?>
        <div class="msg err" style="margin:0"><?= $warn ?><span>La app de Meta todavía no está configurada en el servidor (META_APP_ID / META_APP_SECRET + App Review de Meta).</span></div>
      <?php else: ?>
        <a class="btn fb" href="?action=iniciar&marca=<?= $marca_id ?>"><?= $fb_svg ?> Conectar con Facebook</a>
        <button class="need" type="button" id="needBtn">¿Qué necesito para conectar?</button>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<!-- Sheet móvil / bloque inline en desktop: requisitos (progressive disclosure) -->
<?php if (!$paginas && !($conx && $conx['estado']==='activa')): ?>
<div class="sheet-ov" id="needOv"></div>
<div class="sheet" id="needSheet">
  <div class="grip"></div>
  <h2>Lo que necesitas (tu parte)</h2>
  <ol>
    <li>Una <b>Página de Facebook</b> de tu negocio.</li>
    <li>Tu <b>Instagram en modo Business o Creator</b>, enlazado a esa Página.</li>
  </ol>
  <p class="fine">¿No lo tienes? Es gratis y toma minutos: app de Instagram → Configuración → Cuenta → <b>Cambiar a cuenta profesional</b>, y enlázalo a tu Página. Si te trabas, pregúntale al Copiloto.</p>
</div>
<script>
  (function(){
    var btn=document.getElementById('needBtn'), ov=document.getElementById('needOv'), sh=document.getElementById('needSheet');
    if(!btn||!sh) return;
    function open(){ ov.classList.add('show'); sh.classList.add('show'); }
    function close(){ ov.classList.remove('show'); sh.classList.remove('show'); }
    btn.addEventListener('click', open);
    ov.addEventListener('click', close);
    document.addEventListener('keydown', function(e){ if(e.key==='Escape') close(); });
  })();
</script>
<?php endif; ?>
</body>
</html>
