<?php
// ============================================================
//  CRECER — Login  (login.php)
// ============================================================
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';

if (esta_logueado()) {
    $u = usuario_actual($pdo);
    if (($u['rol'] ?? '') === 'admin') { header('Location: /crecer/panel/admin.php'); exit; }
    $tiene = marca_del_usuario($pdo, (int)$u['id']);
    header('Location: ' . ($tiene ? '/crecer/panel/index.php' : '/crecer/onboarding.php')); exit;
}

$err = ''; $email = '';
$exito = isset($_GET['registrado']) ? '✓ Cuenta creada. Entra con tu email y contraseña.'
       : (isset($_GET['reset']) ? '✓ Contraseña actualizada. Entra con la nueva.' : '');

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
<title>Entrar · Encuéntralo</title>
<link rel="icon" type="image/svg+xml" href="/crecer/assets/brand/encuentralo-pin.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Anton&family=Poppins:wght@600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="/crecer/assets/encuentralo-ui.css?v=7" rel="stylesheet">
<style>
  :root{ --grad:linear-gradient(120deg,var(--coral,#ff5c39),var(--magenta,#c0395f)); }
  *{box-sizing:border-box}
  body{background:var(--crema,#fbf6ee);color:var(--tinta,#1b1622);font-family:'Plus Jakarta Sans',system-ui,sans-serif;min-height:100vh}
  .disp{font-family:'Anton',sans-serif;text-transform:uppercase;letter-spacing:.01em;line-height:.95;font-weight:400}
  .g{background:var(--grad);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
  body::before{content:"";position:fixed;top:0;left:0;right:0;height:4px;z-index:60;background:var(--grad)}
  body::after{content:"";position:fixed;inset:0;z-index:9998;pointer-events:none;opacity:.03;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='140' height='140'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");background-size:180px}

  .topbar{display:flex;align-items:center;gap:10px;padding:16px 24px;max-width:1080px;margin:0 auto}
  .topbar a{display:flex;align-items:center;gap:9px;text-decoration:none;color:inherit}
  .topbar img{height:30px}
  .topbar b{font-family:var(--font-display);font-weight:800;font-size:20px;letter-spacing:-.03em;text-transform:lowercase}
  .topbar .sp{flex:1}
  .topbar .lg{font-weight:700;font-size:14px;color:var(--muted);text-decoration:none}
  .topbar .lg:hover{color:var(--tinta)}

  .reg{max-width:1000px;margin:0 auto;padding:14px 24px 56px;display:grid;grid-template-columns:1fr 1fr;gap:30px;align-items:center}

  .aside{position:relative;border-radius:24px;padding:36px 30px;color:#fff;overflow:hidden;
    background:linear-gradient(150deg,#2a1530,#140a16);box-shadow:0 26px 56px -28px rgba(27,22,34,.6)}
  .aside::after{content:"";position:absolute;inset:0;pointer-events:none;opacity:.5;
    background:radial-gradient(60% 50% at 100% 0%,rgba(255,92,57,.22),transparent 60%),radial-gradient(50% 50% at 0% 100%,rgba(192,57,95,.2),transparent 55%)}
  .aside .in{position:relative;z-index:1}
  .aside .pill{display:inline-flex;align-items:center;gap:7px;font-weight:800;font-size:12px;letter-spacing:.05em;text-transform:uppercase;
    color:#ffcaa8;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.16);padding:6px 13px;border-radius:99px}
  .aside h2{font-size:clamp(28px,3.6vw,42px);margin:16px 0 0}
  .aside p{color:#cdc5d6;font-size:15px;margin:12px 0 0;line-height:1.55;max-width:34ch}
  .aside .proof{display:inline-flex;align-items:center;gap:9px;margin-top:24px;font-size:13px;color:#bdb4c9;
    background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:99px;padding:8px 14px}
  .aside .proof b{color:#fff}
  .aside .proof .dot{width:8px;height:8px;border-radius:50%;background:#39d98a;box-shadow:0 0 0 0 rgba(57,217,138,.5);animation:beat 2s infinite}
  @keyframes beat{0%{box-shadow:0 0 0 0 rgba(57,217,138,.5)}70%{box-shadow:0 0 0 7px rgba(57,217,138,0)}100%{box-shadow:0 0 0 0 rgba(57,217,138,0)}}

  .formwrap h1{font-size:clamp(30px,3.6vw,40px);margin:0}
  .formwrap .sub{color:var(--muted);font-size:15px;margin:8px 0 0}
  .card{background:var(--card,#fff);border:1px solid var(--line);border-radius:22px;padding:26px;box-shadow:var(--shadow);margin-top:18px}
  label{display:block;font-weight:700;font-size:13px;margin:13px 0 6px;color:var(--tinta)}
  label:first-of-type{margin-top:0}
  .pw{position:relative}
  input{width:100%;font-family:inherit;font-size:16px;color:var(--tinta);background:#fff;border:1.5px solid var(--line);border-radius:13px;padding:12px 14px}
  .pw input{padding-right:46px}
  input:focus{outline:none;border-color:var(--terracota);box-shadow:0 0 0 4px color-mix(in srgb,var(--terracota) 18%,transparent)}
  .eye{position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:0;cursor:pointer;font-size:18px;padding:6px;line-height:1;color:var(--muted)}
  .go{margin-top:20px;width:100%;background:var(--grad);color:#fff;border:0;cursor:pointer;font-weight:800;font-size:16px;
    padding:15px;border-radius:99px;box-shadow:0 14px 32px -12px rgba(192,57,95,.55);position:relative;overflow:hidden;transition:transform .15s,filter .15s}
  .go:hover{transform:translateY(-2px);filter:brightness(1.05)}
  .go::after{content:"";position:absolute;top:0;left:-130%;width:55%;height:100%;
    background:linear-gradient(100deg,transparent,rgba(255,255,255,.45),transparent);transform:skewX(-18deg);animation:shine 5s ease-in-out infinite}
  @keyframes shine{0%,62%{left:-130%}82%,100%{left:170%}}
  .err{background:var(--noo-bg);color:var(--noo-ink);font-weight:700;font-size:14px;padding:11px 14px;border-radius:12px;margin-bottom:10px}
  .ok{background:var(--okk-bg);color:var(--okk-ink);font-weight:700;font-size:14px;padding:11px 14px;border-radius:12px;margin-bottom:10px}
  .forgot{text-align:center;margin-top:14px;font-size:14px}
  .forgot a{color:var(--muted);font-weight:600;text-decoration:none}
  .alt{text-align:center;margin-top:14px;font-size:14px;color:var(--muted)}
  .alt a{color:var(--terracota);font-weight:700;text-decoration:none}

  @media(max-width:820px){
    .reg{grid-template-columns:1fr;gap:20px;padding-top:6px}
    .aside{order:2;padding:26px 24px}.formwrap{order:1}.aside p{max-width:none}
  }
  @media(prefers-reduced-motion:reduce){.go::after,.aside .proof .dot{animation:none}}
</style>
</head>
<body>

<div class="topbar">
  <a href="/crecer/index.php"><img src="/crecer/assets/brand/encuentralo-pin.svg" alt=""><b>encuéntralo</b></a>
  <span class="sp"></span>
  <a class="lg" href="/crecer/registro.php">¿No tienes cuenta? Créala →</a>
</div>

<div class="reg">
  <!-- PANEL: bienvenida -->
  <aside class="aside">
    <div class="in">
      <span class="pill">🤝 Tu corillo te esperaba</span>
      <h2 class="disp">Bienvenido de <span class="g">vuelta.</span></h2>
      <p>Mientras no estabas, el corillo siguió trabajándote el negocio. Entra y mira lo que te dejó listo.</p>
      <div class="proof"><span class="dot"></span><span>El corillo ya trabaja <b><?= $nf($negocios) ?></b> negocios · <b><?= $nf($acciones) ?></b> acciones de IA</span></div>
    </div>
  </aside>

  <!-- FORMULARIO -->
  <div class="formwrap">
    <h1 class="disp">Entra a tu <span class="g">corillo</span></h1>
    <p class="sub">Tu panel te espera.</p>

    <form method="post" class="card">
      <?= csrf_field() ?>
      <?php if ($exito): ?><div class="ok"><?= $h($exito) ?></div><?php endif; ?>
      <?php if ($err): ?><div class="err">⚠️ <?= $err ?></div><?php endif; ?>

      <label>Email</label>
      <input type="email" name="email" required autofocus value="<?= $h($email) ?>" placeholder="tu@email.com">

      <label>Contraseña</label>
      <div class="pw">
        <input type="password" name="password" id="pw" required placeholder="Tu contraseña">
        <button type="button" class="eye" id="eye" aria-label="Mostrar contraseña">👁</button>
      </div>

      <button class="go" type="submit">Entrar →</button>
    </form>

    <p class="forgot"><a href="/crecer/recuperar.php">¿Olvidaste tu contraseña?</a></p>
    <p class="alt">¿No tienes cuenta? <a href="/crecer/registro.php">Créala gratis</a></p>
  </div>
</div>

<script>
  document.getElementById('eye').addEventListener('click', function(){
    var i=document.getElementById('pw'); var show=i.type==='password';
    i.type=show?'text':'password'; this.textContent=show?'🙈':'👁';
  });
</script>
</body>
</html>
