<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Activación de cuenta (verifica el correo)
//  activar.php?token=XXXX  ·  confirma que es humano → entra
// ============================================================
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';

$token = trim($_GET['token'] ?? '');
if ($token !== '' && ctype_xdigit($token)) {
    $q = $pdo->prepare("SELECT * FROM usuarios WHERE verif_token = ? AND deleted_at IS NULL LIMIT 1");
    $q->execute([$token]);
    $u = $q->fetch();
    if ($u) {
        $pdo->prepare("UPDATE usuarios SET verificado=1, email_verificado_at=NOW(), verif_token=NULL WHERE id=?")
            ->execute([(int)$u['id']]);
        login_usuario($u);
        $tiene = (int)$pdo->query("SELECT COUNT(*) FROM crecer_marca WHERE usuario_id=" . (int)$u['id'])->fetchColumn();
        header('Location: ' . ($tiene ? '/crecer/panel/index.php' : '/crecer/onboarding.php?activado=1'));
        exit;
    }
}
// Token inválido o ya usado → página simple.
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Activación · Crecer</title>
<link href="https://fonts.googleapis.com/css2?family=Anton&family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Plus Jakarta Sans',sans-serif;background:#fbf6ee;color:#1b1622;display:grid;place-items:center;min-height:100vh;padding:24px}
  .card{background:#fff;border:1px solid #eadfce;border-radius:20px;max-width:420px;width:100%;padding:32px 26px;text-align:center;box-shadow:0 20px 50px -20px rgba(0,0,0,.3)}
  .ic{font-size:52px;margin-bottom:8px}
  h1{font-family:'Anton',sans-serif;font-size:26px;letter-spacing:.5px;margin-bottom:8px}
  p{font-size:14.5px;color:#6a6068;line-height:1.55;margin-bottom:20px}
  .btn{display:inline-block;background:linear-gradient(135deg,#ff6b3d,#ff2d85);color:#fff;text-decoration:none;font-weight:800;font-size:15px;padding:13px 26px;border-radius:99px}
  .alt{margin-top:14px;font-size:13.5px}.alt a{color:#e3683f;font-weight:700;text-decoration:none}
</style></head><body>
  <div class="card">
    <div class="ic">⚠️</div>
    <h1>Enlace vencido o ya usado</h1>
    <p>Ese enlace de activación no es válido (quizás ya lo usaste, o pediste uno nuevo). Entra e te reenviamos uno fresco.</p>
    <a class="btn" href="/crecer/login.php">Ir a entrar →</a>
    <p class="alt">¿No tienes cuenta? <a href="/crecer/registro.php">Regístrate</a></p>
  </div>
</body></html>
