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
        // El GATEWAY retoma donde quedó (entrevista → escenario → venta) o abre el app si ya paga.
        require_once __DIR__ . '/includes/gateway.php';
        $u['verificado'] = 1;   // recién marcado arriba (el row se leyó antes del UPDATE)
        gateway_redirigir($pdo, $u);
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
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{--crema:#F7F5F1;--card:#fff;--ink:#231F20;--ink-soft:#4A434F;--muted:#6E6A67;--line:#E9E7E4;
    --magenta:#EF4375;--coral:#FF6B3D;--teal-dark:#00827e;
    --grad:linear-gradient(135deg,var(--coral),var(--magenta));
    --glow:0 1px 0 rgba(255,255,255,.28) inset,0 10px 22px -8px rgba(239,67,117,.5),0 22px 50px -18px rgba(239,67,117,.42);
    --ease:cubic-bezier(.22,1,.36,1)}
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Plus Jakarta Sans',system-ui,sans-serif;background:var(--crema);color:var(--ink);display:grid;place-items:center;min-height:100vh;padding:24px;-webkit-font-smoothing:antialiased;
    background-image:radial-gradient(110% 40% at 100% 0%,rgba(239,67,117,.045),transparent 60%),radial-gradient(80% 38% at 0% 2%,rgba(0,164,159,.035),transparent 58%)}
  .card{background:var(--card);border:1px solid var(--line);border-radius:22px;max-width:420px;width:100%;padding:34px 28px;text-align:center;box-shadow:0 2px 6px rgba(40,22,28,.05),0 30px 64px -30px rgba(40,22,28,.28)}
  .ic{width:52px;height:52px;margin:0 auto 16px;border-radius:50%;display:grid;place-items:center;background:color-mix(in srgb,#e0a53a 15%,#fff);color:#bd7f1c}
  .ic svg{width:26px;height:26px}
  h1{font-family:'Poppins',sans-serif;font-weight:600;font-size:24px;letter-spacing:-.02em;color:var(--ink-soft);margin-bottom:10px}
  p{font-size:14.5px;color:var(--muted);line-height:1.55;margin-bottom:22px}
  .btn{display:inline-block;background:var(--grad);color:#fff;text-decoration:none;font-family:'Poppins',sans-serif;font-weight:600;font-size:15px;padding:15px 30px;border-radius:16px;box-shadow:var(--glow);transition:transform .2s var(--ease)}
  .btn:active{transform:translateY(1px)}
  .alt{margin-top:16px;font-size:13.5px;color:var(--muted)}.alt a{color:var(--teal-dark);font-weight:600;text-decoration:none}
</style></head><body>
  <div class="card">
    <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg></div>
    <h1>Enlace vencido o ya usado</h1>
    <p>Ese enlace de activación no es válido — quizás ya lo usaste, o pediste uno nuevo. Entra y te reenviamos uno fresco.</p>
    <a class="btn" href="/crecer/login.php">Ir a entrar →</a>
    <p class="alt">¿No tienes cuenta? <a href="/crecer/registro.php">Regístrate</a></p>
  </div>
</body></html>
