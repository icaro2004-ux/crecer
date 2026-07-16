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

if (($_GET['ok'] ?? '') === 'conx') $info = '¡Conectado! El corillo ya puede publicar por ti. 🎉';
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
<link rel="icon" type="image/svg+xml" href="/crecer/assets/brand/encuentralo-pin.svg">
<link href="https://fonts.googleapis.com/css2?family=Anton&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="/crecer/assets/encuentralo-ui.css?v=16" rel="stylesheet">
<style>
  body{background:var(--crema)}
  .wrap{max-width:560px;margin:0 auto;padding:26px 20px 70px}
  .top{display:flex;align-items:center;gap:9px;margin-bottom:18px}
  .top img{height:30px}.top b{font-weight:800;font-size:19px;color:var(--tinta)}
  h1{font-family:var(--font-impact,'Anton');text-transform:uppercase;letter-spacing:.5px;line-height:.98;font-size:clamp(26px,6vw,38px);color:var(--tinta)}
  h1 span{color:var(--terracota)}
  .lede{color:var(--muted);font-size:15px;margin:8px 0 20px}
  .card{background:var(--card,#fff);border:1px solid var(--line);border-radius:var(--r-lg,16px);padding:18px;margin-bottom:14px;box-shadow:var(--shadow-sm)}
  .btn{display:inline-flex;align-items:center;gap:9px;border:0;cursor:pointer;font-family:inherit;font-weight:800;font-size:15px;color:#fff;background:#1877F2;padding:13px 20px;border-radius:12px;text-decoration:none}
  .btn.green{background:var(--palma,#16b86a)}
  .btn.ghost{background:#fff;color:var(--tinta);border:1.5px solid var(--line)}
  .msg{padding:11px 14px;border-radius:12px;font-weight:700;font-size:14px;margin-bottom:14px}
  .msg.err{background:#fde8e8;color:#9b1c1c}
  .msg.ok{background:#e6f6ee;color:#0d7a44}
  .pg{display:flex;align-items:center;gap:12px;padding:12px;border:1.5px solid var(--line);border-radius:12px;margin-bottom:10px;cursor:pointer}
  .pg input{width:18px;height:18px}
  .pg .nm{font-weight:800;font-size:15px;color:var(--tinta)}
  .pg .sub{font-size:13px;color:var(--muted)}
  .reqs{font-size:13.5px;color:var(--muted);line-height:1.6;margin-top:6px}
  .reqs b{color:var(--tinta)}
  .back{display:inline-block;color:var(--muted);text-decoration:none;font-weight:600;font-size:14px;margin-bottom:14px}
</style>
</head>
<body>
<div class="wrap">
  <div class="top"><img src="/crecer/assets/brand/encuentralo-pin.svg" alt=""><b>encuéntralo</b></div>
  <a class="back" href="<?= $BASE ?>/index.php?marca=<?= $marca_id ?>">← Volver al panel</a>
  <h1>Conecta tus <span>redes</span></h1>
  <p class="lede">Conecta Instagram y Facebook una sola vez. Después, cuando apruebes un post, el corillo lo publica solo a la hora que toca — sin que tú tengas que entrar.</p>

  <?php if ($err): ?><div class="msg err">⚠️ <?= $err ?></div><?php endif; ?>
  <?php if ($info): ?><div class="msg ok">✅ <?= $h($info) ?></div><?php endif; ?>

  <?php if ($paginas): ?>
    <!-- PASO 3: ELEGIR PÁGINA (también sirve para reconectar / activar Instagram) -->
    <div class="card">
      <div style="font-weight:800;font-size:16px;color:var(--tinta);margin-bottom:10px">¿En cuál página publicamos?</div>
      <form method="post" action="?marca=<?= $marca_id ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="paso" value="elegir">
        <?php foreach ($paginas as $i => $p): ?>
          <label class="pg">
            <input type="radio" name="pagina" value="<?= $h($p['fb_page_id']) ?>" <?= $i===0?'checked':'' ?>>
            <span>
              <span class="nm"><?= $h($p['fb_page_nombre'] ?: 'Página sin nombre') ?></span>
              <span class="sub"><?= $p['ig_username'] ? '📸 @' . $h($p['ig_username']) : '⚠️ sin IG Business — solo publicará a Facebook' ?></span>
            </span>
          </label>
        <?php endforeach; ?>
        <button class="btn green" type="submit" style="margin-top:6px">Usar esta página</button>
      </form>
    </div>

  <?php elseif ($conx && $conx['estado'] === 'activa'): ?>
    <!-- YA CONECTADO -->
    <div class="card">
      <div style="font-weight:800;font-size:16px;color:var(--tinta);margin-bottom:8px">🟢 Redes conectadas</div>
      <div class="reqs">
        <?php if ($conx['ig_username']): ?><div>📸 Instagram: <b>@<?= $h($conx['ig_username']) ?></b></div><?php else: ?><div>📸 Instagram: <i>sin cuenta IG Business conectada a esta página</i></div><?php endif; ?>
        <div>👍 Página de Facebook: <b><?= $h($conx['fb_page_nombre']) ?></b></div>
      </div>

      <?php if (empty($conx['ig_username'])): ?>
        <div class="msg" style="margin:14px 0 0;background:#fff7e6;color:#8a5a00">
          <b>Para publicar a Instagram</b> tu IG tiene que estar en modo <b>Business o Creator</b> y <b>enlazado a esta Página</b> (<?= $h($conx['fb_page_nombre']) ?>).
          <div style="margin-top:6px;font-weight:500">Cómo: app de Instagram → ⚙️ Configuración → Cuenta → <b>Cambiar a cuenta profesional</b>; luego enlázalo a tu Página de Facebook. Cuando termines, dale <b>"Volver a conectar"</b> aquí abajo y el corillo agarra tu Instagram.</div>
        </div>
        <a class="btn" href="?action=iniciar&marca=<?= $marca_id ?>" style="margin-top:14px">🔄 Volver a conectar (activar Instagram)</a>
      <?php else: ?>
        <a class="btn ghost" href="?action=iniciar&marca=<?= $marca_id ?>" style="margin-top:14px">🔄 Volver a conectar / actualizar</a>
      <?php endif; ?>

      <form method="post" action="?action=desconectar&marca=<?= $marca_id ?>" style="margin-top:10px" onsubmit="return confirm('¿Seguro que quieres desconectar? El corillo dejará de publicar automático.')">
        <?= csrf_field() ?>
        <button class="btn ghost" type="submit">Desconectar</button>
      </form>
    </div>

  <?php else: ?>
    <!-- PASO 1: INICIAR -->
    <div class="card">
      <?php if (!meta_configurado()): ?>
        <div class="msg err" style="margin:0">⚙️ La app de Meta todavía no está configurada. Falta <b>META_APP_ID</b> y <b>META_APP_SECRET</b> en el servidor (más App Review de Meta para publicar a nombre de clientes).</div>
      <?php else: ?>
        <a class="btn" href="?action=iniciar&marca=<?= $marca_id ?>">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12a12 12 0 1 0-13.875 11.854v-8.385H7.078V12h3.047V9.356c0-3.007 1.792-4.668 4.533-4.668 1.312 0 2.686.234 2.686.234v2.953H15.83c-1.49 0-1.955.925-1.955 1.874V12h3.328l-.532 3.469h-2.796v8.385A12.002 12.002 0 0 0 24 12z"/></svg>
          Conectar con Facebook
        </a>
      <?php endif; ?>
      <div class="reqs" style="margin-top:16px">
        <b>Lo que necesitas tener listo (tu parte):</b>
        <div>1. Una <b>Página de Facebook</b> de tu negocio.</div>
        <div>2. Tu <b>Instagram en modo Business o Creator</b>, conectado a esa Página.</div>
        <div style="margin-top:8px">¿No lo tienes? Es gratis y se hace en minutos desde la app de Instagram → Configuración → Cuenta → Cambiar a cuenta profesional. Si necesitas ayuda, pregúntale al asistente 💬.</div>
      </div>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
