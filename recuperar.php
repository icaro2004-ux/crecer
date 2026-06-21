<?php
// ============================================================
//  CRECER — Recuperar contraseña (paso 1: pedir email)
//  recuperar.php  ·  usa la tabla compartida password_resets
// ============================================================
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/notificaciones.php';

if (esta_logueado()) { header('Location: /crecer/panel/index.php'); exit; }

$enviado = false; $err = ''; $email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if (!csrf_ok()) {
        $err = 'La sesión expiró. Recarga e intenta otra vez.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Escribe un email válido.';
    } else {
        $u = $pdo->prepare("SELECT id, nombre FROM usuarios WHERE email=? AND activo=1 AND deleted_at IS NULL");
        $u->execute([$email]);
        $usuario = $u->fetch();
        if ($usuario) {
            // invalidar tokens viejos sin usar
            $pdo->prepare("UPDATE password_resets SET used=1 WHERE user_id=? AND used=0")->execute([$usuario['id']]);
            // nuevo token (1 hora)
            $token = bin2hex(random_bytes(32));
            $pdo->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?,?, DATE_ADD(NOW(), INTERVAL 1 HOUR))")
                ->execute([$usuario['id'], $token]);
            $base = defined('BASE_URL') ? rtrim(BASE_URL,'/') : 'http://localhost/crecer';
            $link = $base . '/nuevo-password.php?token=' . $token;
            $nombre = htmlspecialchars($usuario['nombre'] ?: 'jefe');
            $cuerpo = "<div style='font-family:Arial,sans-serif;max-width:520px;margin:auto;color:#1a1a24'>"
                . "<h2>¡Hola, $nombre! 👋</h2>"
                . "<p>Pediste cambiar tu contraseña en Crecer. Dale al botón (el link vale por <b>1 hora</b>):</p>"
                . "<p style='margin:26px 0'><a href='$link' style='background:#e3683f;color:#fff;text-decoration:none;font-weight:bold;padding:13px 24px;border-radius:12px'>Crear contraseña nueva</a></p>"
                . "<p style='font-size:13px;color:#8a8a98'>Si el botón no abre, copia este link:<br><span style='word-break:break-all'>$link</span></p>"
                . "<p style='font-size:12px;color:#8a8a98'>Si no fuiste tú, ignora este correo — tu contraseña no cambia.</p></div>";
            crecer_enviar_email($email, 'Recupera tu contraseña — Crecer', $cuerpo);
        }
        // Siempre éxito (no revelamos si el email existe)
        $enviado = true;
    }
}
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Recuperar contraseña · Encuéntralo</title>
<link rel="icon" type="image/svg+xml" href="/crecer/assets/brand/encuentralo-pin.svg">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="/crecer/assets/encuentralo-ui.css?v=7" rel="stylesheet">
<style>
  body::before{content:"";position:fixed;top:0;left:0;right:0;height:4px;z-index:60;background:linear-gradient(120deg,var(--coral),var(--magenta))}
  .auth{max-width:400px;margin:0 auto;padding:46px 22px 60px}
  .auth .top{text-align:center}
  .auth .top a{display:inline-flex;align-items:center;gap:9px;text-decoration:none;color:inherit}
  .auth .top img{height:34px}
  .auth .top b{font-family:var(--font-display);font-weight:800;font-size:22px;letter-spacing:-.03em;text-transform:lowercase}
  .auth h1{font-family:var(--font-display);font-weight:800;font-size:26px;letter-spacing:-.025em;text-align:center;margin-top:20px}
  .auth .sub{color:var(--muted);text-align:center;font-size:15px;margin-top:6px}
  .card{background:var(--card);border:1px solid var(--line);border-radius:var(--r-xl);padding:26px;box-shadow:var(--shadow);margin-top:20px}
  label{display:block;font-weight:700;font-size:13.5px;margin:4px 0 6px}
  input{width:100%;font-family:inherit;font-size:16px;color:var(--tinta);background:#fff;border:1.5px solid var(--line);border-radius:13px;padding:12px 14px}
  input:focus{outline:none;border-color:var(--terracota);box-shadow:0 0 0 4px rgba(239,67,117,.12)}
  .go{margin-top:18px;width:100%;background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff;border:0;cursor:pointer;font-weight:800;font-size:16px;padding:14px;border-radius:99px}
  .err{background:var(--noo-bg);color:var(--noo-ink);font-weight:700;font-size:14px;padding:11px 14px;border-radius:12px;margin-bottom:10px}
  .ok{background:var(--okk-bg);color:var(--okk-ink);font-weight:700;font-size:14px;padding:12px 14px;border-radius:12px;line-height:1.5}
  .alt{text-align:center;margin-top:16px;font-size:14px;color:var(--muted)}
  .alt a{color:var(--terracota);font-weight:700;text-decoration:none}
</style>
</head>
<body>
<div class="auth">
  <div class="top"><a href="/crecer/index.php"><img src="/crecer/assets/brand/encuentralo-pin.svg" alt=""><b>encuéntralo</b></a></div>
  <h1>¿Se te fue el password? 🔑</h1>
  <p class="sub">Tranqui — te mandamos un link y armas uno nuevo.</p>

  <div class="card">
    <?php if ($enviado): ?>
      <div class="ok">✓ Si existe una cuenta con ese email, te mandamos el link. Revisa tu correo (y el spam por si acaso). Vale por 1 hora.</div>
      <p class="alt" style="margin-top:18px"><a href="/crecer/login.php">← Volver a entrar</a></p>
    <?php else: ?>
      <?php if ($err): ?><div class="err">⚠️ <?= $h($err) ?></div><?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <label>Tu email</label>
        <input type="email" name="email" required autofocus value="<?= $h($email) ?>" placeholder="tu@email.com">
        <button class="go" type="submit">Mándame el link →</button>
      </form>
      <p class="alt"><a href="/crecer/login.php">← Volver a entrar</a></p>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
