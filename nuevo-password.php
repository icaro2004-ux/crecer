<?php
// ============================================================
//  CRECER — Recuperar contraseña (paso 2: crear la nueva)
//  nuevo-password.php?token=...
// ============================================================
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';

$token = $_GET['token'] ?? ($_POST['token'] ?? '');
$err = ''; $ok_token = false; $user_id = null;

// Validar token (existe, sin usar, no vencido)
if ($token !== '') {
    $q = $pdo->prepare("SELECT user_id FROM password_resets WHERE token=? AND used=0 AND expires_at > NOW()");
    $q->execute([$token]);
    $user_id = $q->fetchColumn();
    $ok_token = (bool)$user_id;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ok_token) {
    $p1 = $_POST['password'] ?? '';
    $p2 = $_POST['password2'] ?? '';
    if (!csrf_ok())              $err = 'La sesión expiró. Recarga e intenta otra vez.';
    elseif (strlen($p1) < 8)     $err = 'La contraseña debe tener al menos 8 caracteres.';
    elseif ($p1 !== $p2)         $err = 'Las contraseñas no coinciden.';
    else {
        $pdo->prepare("UPDATE usuarios SET password=?, updated_at=NOW() WHERE id=?")
            ->execute([password_hash($p1, PASSWORD_DEFAULT), (int)$user_id]);
        $pdo->prepare("UPDATE password_resets SET used=1 WHERE token=?")->execute([$token]);
        header('Location: /crecer/login.php?reset=1'); exit;
    }
}
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Nueva contraseña · Encuéntralo</title>
<link rel="icon" type="image/svg+xml" href="/crecer/assets/brand/encuentralo-pin.svg">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="/crecer/assets/encuentralo-ui.css?v=17" rel="stylesheet">
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
  label{display:block;font-weight:700;font-size:13.5px;margin:13px 0 6px}
  .pw{position:relative}
  input{width:100%;font-family:inherit;font-size:16px;color:var(--tinta);background:#fff;border:1.5px solid var(--line);border-radius:13px;padding:12px 46px 12px 14px}
  input:focus{outline:none;border-color:var(--terracota);box-shadow:0 0 0 4px rgba(239,67,117,.12)}
  .eye{position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:0;cursor:pointer;font-size:18px;padding:6px;line-height:1;color:var(--muted)}
  .go{margin-top:20px;width:100%;background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff;border:0;cursor:pointer;font-weight:800;font-size:16px;padding:14px;border-radius:99px}
  .err{background:var(--noo-bg);color:var(--noo-ink);font-weight:700;font-size:14px;padding:11px 14px;border-radius:12px;margin-bottom:10px}
  .alt{text-align:center;margin-top:16px;font-size:14px;color:var(--muted)}
  .alt a{color:var(--terracota);font-weight:700;text-decoration:none}
</style>
</head>
<body>
<div class="auth">
  <div class="top"><a href="/crecer/index.php"><img src="/crecer/assets/brand/encuentralo-pin.svg" alt=""><b>encuéntralo</b></a></div>
  <h1>Crea tu contraseña nueva 🔐</h1>

  <div class="card">
    <?php if (!$ok_token): ?>
      <div class="err">⚠️ Este link no sirve o ya venció (vale por 1 hora). Pide uno nuevo.</div>
      <p class="alt" style="margin-top:18px"><a href="/crecer/recuperar.php">Pedir un link nuevo →</a></p>
    <?php else: ?>
      <?php if ($err): ?><div class="err">⚠️ <?= $h($err) ?></div><?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= $h($token) ?>">
        <label>Nueva contraseña</label>
        <div class="pw">
          <input type="password" name="password" id="p1" required placeholder="Mín. 8 caracteres" autofocus>
          <button type="button" class="eye" data-for="p1" aria-label="Mostrar contraseña">👁</button>
        </div>
        <label>Repítela</label>
        <div class="pw">
          <input type="password" name="password2" id="p2" required placeholder="Otra vez">
          <button type="button" class="eye" data-for="p2" aria-label="Mostrar contraseña">👁</button>
        </div>
        <button class="go" type="submit">Guardar y entrar →</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<script>
  document.querySelectorAll('.eye').forEach(function(b){
    b.addEventListener('click', function(){
      var i=document.getElementById(b.dataset.for);
      if(!i) return;
      var show = i.type==='password';
      i.type = show ? 'text' : 'password';
      b.textContent = show ? '🙈' : '👁';
    });
  });
</script>
</body>
</html>
