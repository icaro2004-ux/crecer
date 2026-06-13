<?php
// ============================================================
//  CRECER — Login  (login.php)
// ============================================================
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';

if (esta_logueado()) { header('Location: /crecer/panel/index.php'); exit; }

$err = ''; $email = '';
$exito = isset($_GET['registrado']) ? '✓ Cuenta creada. Entra con tu email y contraseña.' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    if (!csrf_ok())              $err = 'La sesión expiró. Recarga e intenta otra vez.';
    elseif (!$email || !$pass)   $err = 'Completa email y contraseña.';
    else {
        $s = $pdo->prepare("SELECT id, nombre, password, rol, activo FROM usuarios WHERE email = ? AND deleted_at IS NULL");
        $s->execute([$email]);
        $u = $s->fetch();
        if (!$u || !password_verify($pass, $u['password'])) {
            $err = 'Email o contraseña incorrectos.';
        } elseif (!$u['activo']) {
            $err = 'Tu cuenta está desactivada.';
        } else {
            login_usuario($u);
            $dest = $_SESSION['after_login'] ?? null; unset($_SESSION['after_login']);
            if (!$dest) {
                $tiene = marca_del_usuario($pdo, (int)$u['id']);
                $dest = $tiene ? '/crecer/panel/index.php' : '/crecer/intake.php';
            }
            header('Location: ' . $dest); exit;
        }
    }
}
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Entrar · Encuéntralo</title>
<link rel="icon" type="image/svg+xml" href="/crecer/assets/brand/encuentralo-pin.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="/crecer/assets/encuentralo-ui.css" rel="stylesheet">
<style>
  .auth{max-width:400px;margin:0 auto;padding:42px 22px 60px}
  .auth .top{text-align:center}
  .auth .top a{display:inline-flex;align-items:center;gap:9px;text-decoration:none;color:inherit}
  .auth .top img{height:36px}
  .auth .top b{font-family:var(--font-display);font-weight:800;font-size:22px;letter-spacing:-.03em;text-transform:lowercase}
  .auth h1{font-family:var(--font-display);font-weight:800;font-size:28px;letter-spacing:-.025em;text-align:center;margin-top:20px}
  .auth .sub{color:var(--muted);text-align:center;font-size:15px;margin-top:6px}
  .card{background:var(--card);border:1px solid var(--line);border-radius:var(--r-xl);padding:26px;box-shadow:var(--shadow);margin-top:20px}
  label{display:block;font-weight:700;font-size:13.5px;margin:13px 0 6px}
  input{width:100%;font-family:inherit;font-size:16px;color:var(--tinta);background:#fff;border:1.5px solid var(--line);border-radius:13px;padding:12px 14px}
  input:focus{outline:none;border-color:var(--terracota);box-shadow:0 0 0 4px rgba(239,67,117,.12)}
  .go{margin-top:20px;width:100%;background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff;border:0;cursor:pointer;font-weight:800;font-size:16px;padding:14px;border-radius:99px;box-shadow:0 12px 28px rgba(255,43,133,.3)}
  .err{background:var(--noo-bg);color:var(--noo-ink);font-weight:700;font-size:14px;padding:11px 14px;border-radius:12px;margin-bottom:6px}
  .ok{background:var(--okk-bg);color:var(--okk-ink);font-weight:700;font-size:14px;padding:11px 14px;border-radius:12px;margin-bottom:6px}
  .alt{text-align:center;margin-top:16px;font-size:14px;color:var(--muted)}
  .alt a{color:var(--terracota);font-weight:700;text-decoration:none}
</style>
</head>
<body>
<div class="auth">
  <div class="top"><a href="/crecer/index.php"><img src="/crecer/assets/brand/encuentralo-pin.svg" alt=""><b>encuéntralo</b></a></div>
  <h1>Bienvenido de vuelta 👋</h1>
  <p class="sub">Entra a tu panel — tu negocio te espera.</p>

  <form method="post" class="card">
    <?= csrf_field() ?>
    <?php if ($exito): ?><div class="ok"><?= $h($exito) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="err">⚠️ <?= $err ?></div><?php endif; ?>

    <label>Email</label>
    <input type="email" name="email" required autofocus value="<?= $h($email) ?>" placeholder="tu@email.com">

    <label>Contraseña</label>
    <input type="password" name="password" required placeholder="Tu contraseña">

    <button class="go" type="submit">Entrar →</button>
  </form>

  <p class="alt">¿No tienes cuenta? <a href="/crecer/registro.php">Créala gratis</a></p>
</div>
</body>
</html>
