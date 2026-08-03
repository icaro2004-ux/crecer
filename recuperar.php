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
                . "<h2>¡Hola, $nombre!</h2>"
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
<?php require_once __DIR__ . '/includes/meta_pixel.php'; meta_pixel_head(); ?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Recuperar contraseña · Encuéntralo</title>
<link rel="icon" type="image/png" href="/crecer/assets/brand/crecer-icon.png">
<link rel="apple-touch-icon" href="/crecer/assets/brand/crecer-icon.png">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="/crecer/assets/encuentralo-ui.css?v=<?= ASSET_VER ?>" rel="stylesheet">
<style>
  body{background:var(--crema)}
  body::before{content:"";position:fixed;top:0;left:0;right:0;height:4px;z-index:60;background:linear-gradient(120deg,var(--coral),var(--magenta))}
  .auth{max-width:410px;margin:0 auto;padding:52px 22px 60px}
  .auth .top{text-align:center}
  .auth .top a{display:inline-flex;align-items:center;gap:9px;text-decoration:none;color:inherit}
  .auth .top img{height:30px}
  .auth .top b{font-family:var(--font-display);font-weight:600;font-size:17px;letter-spacing:-.02em}
  .auth .top b i{color:var(--teal);font-style:normal}
  .auth h1{font-family:var(--font-display);font-weight:600;font-size:clamp(24px,5.4vw,28px);letter-spacing:-.02em;color:var(--ink-soft);text-align:center;margin-top:22px}
  .auth .sub{color:var(--muted);text-align:center;font-size:15px;margin-top:8px;line-height:1.5}
  .card{background:var(--card);border:1px solid var(--line);border-radius:20px;padding:26px;box-shadow:var(--shadow);margin-top:22px}
  label{display:block;font-family:var(--font-display);font-weight:600;font-size:13.5px;margin:4px 0 6px;color:var(--ink-soft)}
  input{width:100%;font-family:var(--font-body);font-size:16px;color:var(--tinta);background:#fff;border:1.5px solid var(--line);border-radius:12px;padding:12px 14px;transition:border-color .15s,box-shadow .15s}
  input:focus{outline:none;border-color:color-mix(in srgb,var(--magenta) 45%,var(--line));box-shadow:0 0 0 3px color-mix(in srgb,var(--magenta) 15%,transparent)}
  .go{margin-top:18px;width:100%;background:var(--btn-grad);color:#fff;border:0;cursor:pointer;font-family:var(--font-display);font-weight:600;font-size:16px;padding:15px;border-radius:16px;box-shadow:var(--btn-glow);transition:transform .2s var(--ease),box-shadow .2s var(--ease)}
  .go:active{transform:translateY(1px);box-shadow:var(--btn-glow-active)}
  .err{background:#fdeaea;color:#b42318;font-weight:600;font-size:14px;padding:11px 14px;border-radius:12px;margin-bottom:10px;border:1px solid #f5c2c0}
  .ok{background:color-mix(in srgb,var(--teal) 10%,#fff);color:var(--teal-dark,#00827e);font-weight:600;font-size:14px;padding:12px 14px;border-radius:12px;line-height:1.5;border:1px solid color-mix(in srgb,var(--teal) 25%,#fff)}
  .alt{text-align:center;margin-top:16px;font-size:14px;color:var(--muted)}
  .alt a{color:var(--teal-dark,#00827e);font-weight:600;text-decoration:none}
  .alt a:hover{color:var(--ink-soft)}
</style>
</head>
<body>
<div class="auth">
  <div class="top"><a href="/crecer/crecer.php"><img src="/crecer/assets/brand/crecer-icon.png" alt=""><b>encuéntralo <i>crecer</i></b></a></div>
  <h1>¿Olvidaste tu contraseña?</h1>
  <p class="sub">Te enviamos un enlace para crear una nueva. Vale por 1 hora.</p>

  <div class="card">
    <?php if ($enviado): ?>
      <div class="ok">Si existe una cuenta con ese email, te mandamos el link. Revisa tu correo (y el spam por si acaso). Vale por 1 hora.</div>
      <p class="alt" style="margin-top:18px"><a href="/crecer/login.php">← Volver a entrar</a></p>
    <?php else: ?>
      <?php if ($err): ?><div class="err"><?= $h($err) ?></div><?php endif; ?>
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
