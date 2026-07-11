<?php
// ============================================================
//  CRECER — Login  (login.php)
// ============================================================
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/iconos.php';

if (esta_logueado()) {
    $u = usuario_actual($pdo);
    if (($u['rol'] ?? '') === 'admin') { header('Location: /crecer/panel/admin.php'); exit; }
    $tiene = marca_del_usuario($pdo, (int)$u['id']);
    header('Location: ' . ($tiene ? '/crecer/panel/index.php' : '/crecer/onboarding.php')); exit;
}

$err = ''; $email = '';
$exito = isset($_GET['registrado']) ? 'Cuenta creada. Entra con tu email y contraseña.'
       : (isset($_GET['reset']) ? 'Contraseña actualizada. Entra con la nueva.' : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    if (!csrf_ok())              $err = 'La sesión expiró. Recarga e intenta otra vez.';
    elseif (!$email || !$pass)   $err = 'Completa email y contraseña.';
    else {
        $s = $pdo->prepare("SELECT id, nombre, email, password, rol, activo, verificado, verif_token FROM usuarios WHERE email = ? AND deleted_at IS NULL");
        $s->execute([$email]);
        $u = $s->fetch();
        if (!$u || !password_verify($pass, $u['password'])) {
            $err = 'Email o contraseña incorrectos.';
        } elseif (!$u['activo']) {
            $err = 'Tu cuenta está desactivada.';
        } elseif ((int)$u['verificado'] !== 1) {
            // Cuenta sin verificar → reenviar el enlace y mandar a "revisa tu correo".
            require_once __DIR__ . '/includes/notificaciones.php';
            $tok = $u['verif_token'] ?: bin2hex(random_bytes(32));
            if (!$u['verif_token']) $pdo->prepare("UPDATE usuarios SET verif_token=? WHERE id=?")->execute([$tok, $u['id']]);
            $base = defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'http://localhost/crecer';
            crecer_email_activacion($u['email'], $u['nombre'], $base . '/activar.php?token=' . $tok);
            header('Location: /crecer/registro.php?enviado=' . urlencode($u['email'])); exit;
        } else {
            login_usuario($u);
            $dest = $_SESSION['after_login'] ?? null; unset($_SESSION['after_login']);
            if (!$dest) {
                if (($u['rol'] ?? '') === 'admin') {
                    $dest = '/crecer/panel/admin.php';            // admin → Centro de Operaciones
                } else {
                    $tiene = marca_del_usuario($pdo, (int)$u['id']);
                    $dest = $tiene ? '/crecer/panel/index.php' : '/crecer/onboarding.php';
                }
            }
            header('Location: ' . $dest); exit;
        }
    }
}
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
// Prueba viva (defensiva)
$acciones = 113; $negocios = 7;
try {
    $r = $pdo->query("SELECT COUNT(*) a, COUNT(DISTINCT marca_id) n FROM crecer_ia_log WHERE estado='ok'")->fetch();
    if ($r) { $acciones = max($acciones,(int)$r['a']); $negocios = max($negocios,(int)$r['n']); }
} catch (Throwable $e) {}
$nf = fn($n) => number_format($n);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Entrar · Encuéntralo Crecer</title>
<link rel="icon" type="image/png" href="/crecer/assets/brand/encuentralo-crecer-pin-drop.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Anton&family=Caveat:wght@600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link href="/crecer/assets/crecer-brand.css?v=1" rel="stylesheet">
<style>
  body{padding-top:70px}
  .auth{width:min(1000px,calc(100% - 44px));margin:0 auto;padding:40px 0 60px;display:grid;grid-template-columns:1.04fr .96fr;gap:44px;align-items:center;min-height:calc(100vh - 70px)}
  .welcome .eyebrow{font-family:var(--hand);font-weight:700;font-size:clamp(28px,4vw,36px);color:var(--teal);transform:rotate(-1deg);margin:0 0 4px}
  .welcome h1{font-family:var(--display);font-weight:400;text-transform:uppercase;letter-spacing:.012em;font-size:clamp(42px,6vw,68px);line-height:.9;margin:0;color:var(--ink)}
  .welcome p{margin:22px 0 0;font-size:17px;line-height:1.55;color:var(--ink-soft);max-width:40ch}
  .welcome .chip-live{margin-top:26px}
  .formwrap h2{font-family:var(--display);font-weight:400;text-transform:uppercase;letter-spacing:.012em;font-size:clamp(26px,3.4vw,34px);line-height:.95;margin:0;color:var(--ink)}
  .formwrap .sub{color:var(--muted);font-size:15px;font-weight:600;margin:7px 0 0}
  .formwrap form{margin-top:18px}
  .pw{position:relative}
  .pw .f-input{padding-right:46px}
  .eye{position:absolute;right:8px;top:calc(50% + 10px);transform:translateY(-50%);background:none;border:0;cursor:pointer;padding:6px;line-height:0;color:var(--muted)}
  .eye svg{width:19px;height:19px}
  .eye.on{color:var(--teal)}
  .go-full{margin-top:22px;width:100%}
  .forgot{text-align:center;margin-top:16px;font-size:14px}
  .forgot a{color:var(--muted);font-weight:700;text-decoration:none}
  .forgot a:hover{color:var(--ink)}
  .alt{text-align:center;margin-top:12px;font-size:14px;color:var(--muted);font-weight:600}
  .alt a{color:var(--teal-dark);font-weight:800;text-decoration:none}
  .legal{text-align:center;font-size:12px;color:var(--muted);margin-top:18px}
  .legal a{color:var(--muted);text-decoration:none}
  .legal a:hover{color:var(--pink)}
  @media(max-width:820px){
    .auth{grid-template-columns:1fr;gap:26px;padding-top:24px;min-height:0}
    .welcome{text-align:center}.welcome p{margin-inline:auto}
    .welcome .chip-live{display:inline-flex}
  }
</style>
</head>
<body>

<nav class="cnav">
  <div class="in wrap">
    <a class="cbrand" href="/crecer/crecer.php"><img src="/crecer/assets/brand/crecer-mark.svg" alt=""><b>encuéntralo <span class="teal">crecer</span></b></a>
    <span class="sp"></span>
    <a class="lg" href="/crecer/registro.php">¿No tienes cuenta? Créala →</a>
  </div>
</nav>

<div class="auth">
  <!-- Bienvenida -->
  <section class="welcome">
    <p class="eyebrow">Tu corillo te esperaba.</p>
    <h1>Entra a tu <span class="teal u-teal">corillo.</span></h1>
    <p>Mientras no estabas, el corillo siguió trabajándote el negocio. Entra y mira lo que te dejó listo.</p>
    <div class="chip-live"><span class="dot"></span><span>El corillo ya trabaja <b><?= $nf($negocios) ?></b> negocios · <b><?= $nf($acciones) ?></b> acciones de IA</span></div>
  </section>

  <!-- Formulario -->
  <div class="formwrap">
    <h2>Bienvenido de vuelta</h2>
    <p class="sub">Tu panel te espera.</p>

    <form method="post" class="brand-card">
      <?= csrf_field() ?>
      <?php if ($exito): ?><div class="alert-ok"><?= $h($exito) ?></div><?php endif; ?>
      <?php if ($err): ?><div class="alert-err"><?= $err ?></div><?php endif; ?>

      <label class="f-label">Email</label>
      <input class="f-input" type="email" name="email" required autofocus value="<?= $h($email) ?>" placeholder="tu@email.com">

      <label class="f-label">Contraseña</label>
      <div class="pw">
        <input class="f-input" type="password" name="password" id="pw" required placeholder="Tu contraseña">
        <button type="button" class="eye" id="eye" aria-label="Mostrar contraseña"><?= ico('eye') ?></button>
      </div>

      <button class="btn-pink go-full" type="submit">Entrar →</button>
    </form>

    <p class="forgot"><a href="/crecer/recuperar.php">¿Olvidaste tu contraseña?</a></p>
    <p class="alt">¿No tienes cuenta? <a href="/crecer/registro.php">Créala gratis</a></p>
    <p class="legal">
      <a href="/crecer/terminos.php">Términos</a> ·
      <a href="/crecer/privacidad.php">Privacidad</a></p>
  </div>
</div>

<script>
  document.getElementById('eye').addEventListener('click', function(){
    var i=document.getElementById('pw'); var show=i.type==='password';
    i.type=show?'text':'password'; this.classList.toggle('on', show);
  });
</script>
</body>
</html>
