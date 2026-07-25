<?php
// ============================================================
//  CRECER — Conectar Instagram DIRECTO  (panel/conectar_ig.php)
//  El cliente entra con su Instagram y ya. Sin página de Facebook,
//  sin Business Suite, sin el loop de Meta.
//  Módulo AISLADO (usa includes/ig_direct.php).
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ig_direct.php';
requiere_login();
$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }
$marca_id = (int)$marca['id'];
$BASE = '/crecer/panel';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

if (session_status() !== PHP_SESSION_ACTIVE) @session_start();

$msg = null; $err = null;

// ── Iniciar el login (redirige a Instagram) ──
if (($_GET['go'] ?? '') === '1') {
    if (!ig_direct_disponible()) { $err = 'Instagram directo aún no está configurado en el servidor.'; }
    else {
        $state = bin2hex(random_bytes(8));
        $_SESSION['ig_state']   = $state;
        $_SESSION['ig_marca']   = $marca_id;
        header('Location: ' . ig_oauth_url($marca_id . ':' . $state)); exit;
    }
}

// ── Callback de Instagram (?code=...) ──
if (isset($_GET['code']) && !isset($_GET['done'])) {
    try {
        // Validar state (CSRF).
        $st = (string)($_GET['state'] ?? '');
        [$sm, $stok] = array_pad(explode(':', $st, 2), 2, '');
        if ((int)$sm !== $marca_id || !hash_equals((string)($_SESSION['ig_state'] ?? ''), $stok)) {
            throw new RuntimeException('Sesión de seguridad no coincide. Reintenta.');
        }
        $ex   = ig_exchange_code((string)$_GET['code']);      // token corto + user_id
        $long = ig_long_lived($ex['access_token']);           // token largo (60 días)
        $me   = ig_me($long['access_token']);                 // user_id + username
        $uid  = $me['user_id'] ?: $ex['user_id'];
        if ($uid === '') throw new RuntimeException('No se obtuvo la cuenta de Instagram.');
        ig_guardar_conexion($pdo, $marca_id, $uid, $me['username'], $long['access_token'], $long['expires_in']);
        header('Location: ' . $BASE . '/conectar_ig.php?marca=' . $marca_id . '&done=1'); exit;
    } catch (Throwable $e) {
        $err = 'No se pudo conectar: ' . $e->getMessage();
    }
}
if (isset($_GET['error'])) {
    $err = 'Instagram no autorizó la conexión' . (isset($_GET['error_description']) ? ': ' . $h($_GET['error_description']) : '.');
}
if (isset($_GET['done'])) $msg = '¡Instagram conectado! 🎉 Ya el corillo puede publicar por ti.';

$conx = ig_conexion($pdo, $marca_id);
$listo = ig_direct_disponible();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Conectar Instagram · Crecer</title>
<link rel="icon" type="image/png" href="/crecer/assets/brand/crecer-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--rosa:#EF4375;--teal:#00A49F;--tinta:#231F20;--muted:#7a7580;--line:#eceaee;--crema:#faf9fb}
*{box-sizing:border-box}body{margin:0;font-family:'Plus Jakarta Sans',system-ui,sans-serif;color:var(--tinta);background:var(--crema)}
.wrap{max-width:560px;margin:0 auto;padding:20px 18px 60px}
.top{display:flex;align-items:center;gap:10px;padding:6px 0 18px}
.top a{color:var(--muted);text-decoration:none;font-weight:700;font-size:14px}
.card{background:#fff;border:1px solid var(--line);border-radius:22px;padding:26px 22px;text-align:center;box-shadow:0 1px 0 rgba(0,0,0,.02)}
h1{font-family:'Poppins';font-size:26px;font-weight:900;margin:.2em 0 .3em}
.sub{color:var(--muted);font-size:15px;line-height:1.5;margin:0 0 22px}
.iggrad{width:74px;height:74px;border-radius:22px;margin:0 auto 14px;display:flex;align-items:center;justify-content:center;font-size:38px;background:linear-gradient(45deg,#f09433,#e6683c,#dc2743,#cc2366,#bc1888)}
.btn{display:inline-block;width:100%;border:none;border-radius:16px;padding:16px 22px;font-family:'Poppins';font-weight:800;font-size:16px;text-decoration:none;transition:.16s;cursor:pointer}
.btn-ig{background:linear-gradient(45deg,#f09433,#dc2743,#bc1888);color:#fff}
.btn-ig:hover{transform:translateY(-1px)}
.btn-ghost{background:#f4f2f6;color:var(--tinta);margin-top:12px}
.ok{background:#e8f8f0;border:1px solid #b9e6cf;color:#0d7a44;border-radius:14px;padding:14px 16px;font-weight:700;margin-bottom:18px}
.bad{background:#fff0f0;border:1px solid #f3c2c2;color:#9c2b2b;border-radius:14px;padding:14px 16px;font-size:14px;margin-bottom:18px;word-break:break-word}
.pill{display:inline-flex;align-items:center;gap:8px;background:#f4f2f6;border-radius:999px;padding:8px 16px;font-weight:700;font-size:14px;margin-bottom:18px}
.steps{text-align:left;background:var(--crema);border:1px solid var(--line);border-radius:14px;padding:14px 16px;margin-top:20px;font-size:13.5px;color:var(--muted)}
.steps b{color:var(--tinta)}
.warn{background:#fff8e6;border:1px solid #f0dfa0;color:#7a5b00;border-radius:12px;padding:12px 14px;font-size:13px;text-align:left;margin-bottom:18px}
</style>
</head>
<body>
<div class="wrap">
  <div class="top"><a href="<?= $BASE ?>/index.php?marca=<?= $marca_id ?>">← Volver</a></div>

  <?php if ($msg): ?><div class="ok">✓ <?= $h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="bad">⚠️ <?= $h($err) ?></div><?php endif; ?>

  <div class="card">
    <div class="iggrad">📸</div>
    <?php if ($conx): ?>
      <h1>Instagram conectado</h1>
      <div class="pill">✓ @<?= $h($conx['ig_username'] ?: $conx['ig_user_id']) ?></div>
      <p class="sub">Tu Instagram está listo. El corillo ya puede publicar tus reels aquí.</p>
      <a class="btn btn-ig" href="<?= $BASE ?>/reels.php?marca=<?= $marca_id ?>">🎬 Ir al Reels Studio</a>
      <a class="btn btn-ghost" href="<?= $BASE ?>/conectar_ig.php?marca=<?= $marca_id ?>&go=1">Reconectar otra cuenta</a>
    <?php else: ?>
      <h1>Conecta tu Instagram</h1>
      <p class="sub">Sin páginas de Facebook, sin enredos. Entras con tu Instagram y ya — el corillo publica por ti.</p>
      <?php if (!$listo): ?>
        <div class="warn">⚠️ El Instagram directo todavía no está activado en el servidor (falta la configuración de la app). Avísale al equipo de Crecer.</div>
      <?php endif; ?>
      <a class="btn btn-ig <?= $listo ? '' : 'btn-ghost' ?>" href="<?= $listo ? $BASE.'/conectar_ig.php?marca='.$marca_id.'&go=1' : '#' ?>">📸 Conectar con Instagram</a>
      <div class="steps">
        <b>Solo necesitas:</b><br>
        1. Que tu Instagram sea <b>cuenta profesional</b> (Business o Creator) — es un cambio de 10 segundos dentro de Instagram.<br>
        2. Entrar con tu usuario y darle <b>Permitir</b>.
      </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
