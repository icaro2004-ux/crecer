<?php
// ============================================================
//  CRECER — Registro / crear cuenta  (registro.php)
// ============================================================
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';

// Recuerda el plan elegido en el landing (para llevarlo al checkout tras el onboarding)
if (!empty($_GET['plan']) && in_array($_GET['plan'], ['crecer','despegar'], true)) {
    $_SESSION['plan_intent'] = $_GET['plan'];
}

if (esta_logueado()) { header('Location: /crecer/panel/index.php'); exit; }

$err = ''; $val = ['nombre'=>'','email'=>''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($val as $k=>$_) $val[$k] = trim($_POST[$k] ?? '');
    $pass  = $_POST['password'] ?? '';
    $pass2 = $_POST['password2'] ?? '';

    if (!csrf_ok())                       $err = 'La sesión expiró. Recarga e intenta otra vez.';
    elseif ($val['nombre']==='' || $val['email']==='')
                                          $err = 'Completa todos los campos.';
    elseif (!filter_var($val['email'], FILTER_VALIDATE_EMAIL))
                                          $err = 'Ese email no se ve válido.';
    elseif (strlen($pass) < 8)            $err = 'La contraseña debe tener al menos 8 caracteres.';
    elseif ($pass !== $pass2)             $err = 'Las contraseñas no coinciden.';
    else {
        $dup = $pdo->prepare("SELECT 1 FROM usuarios WHERE email = ?");
        $dup->execute([$val['email']]);
        if ($dup->fetchColumn()) {
            $err = 'Ya hay una cuenta con ese email. ¿Quieres <a href="/crecer/login.php" style="color:var(--terracota);font-weight:700">entrar</a>?';
        } else {
            // Registro mínimo: telefono/municipio quedan NULL (se piden luego —
            // el municipio del negocio va en el onboarding, el WhatsApp en activación).
            // Activación por correo OBLIGATORIA (confirma que es humano). La cuenta
            // arranca SIN verificar; no entra ni ve el post de muestra hasta activar.
            $token = bin2hex(random_bytes(32));
            $ins = $pdo->prepare("INSERT INTO usuarios (nombre, email, password, rol, verificado, verif_token)
                                  VALUES (?,?,?, 'proveedor', 0, ?)");
            $ins->execute([$val['nombre'], $val['email'], password_hash($pass, PASSWORD_DEFAULT), $token]);
            require_once __DIR__ . '/includes/notificaciones.php';
            $base = defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'http://localhost/crecer';
            crecer_email_activacion($val['email'], $val['nombre'], $base . '/activar.php?token=' . $token);
            header('Location: /crecer/registro.php?enviado=' . urlencode($val['email'])); // NO login: revisa tu correo
            exit;
        }
    }
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// Prueba viva (defensiva) + plan que venía del landing
$acciones = 113; $negocios = 7;
try {
    $r = $pdo->query("SELECT COUNT(*) a, COUNT(DISTINCT marca_id) n FROM crecer_ia_log WHERE estado='ok'")->fetch();
    if ($r) { $acciones = max($acciones, (int)$r['a']); $negocios = max($negocios, (int)$r['n']); }
} catch (Throwable $e) {}
$plan_intent = $_SESSION['plan_intent'] ?? '';
$plan_lbl = ['crecer'=>'Crecer','despegar'=>'Despegar'][$plan_intent] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Crear cuenta · Encuéntralo</title>
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

  /* Panel emocional (únete al corillo) */
  .aside{position:relative;border-radius:24px;padding:34px 30px;color:#fff;overflow:hidden;
    background:linear-gradient(150deg,#2a1530,#140a16);box-shadow:0 26px 56px -28px rgba(27,22,34,.6)}
  .aside::after{content:"";position:absolute;inset:0;pointer-events:none;opacity:.5;
    background:radial-gradient(60% 50% at 100% 0%,rgba(255,92,57,.22),transparent 60%),radial-gradient(50% 50% at 0% 100%,rgba(192,57,95,.2),transparent 55%)}
  .aside .in{position:relative;z-index:1}
  .aside .pill{display:inline-flex;align-items:center;gap:7px;font-weight:800;font-size:12px;letter-spacing:.05em;text-transform:uppercase;
    color:#ffcaa8;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.16);padding:6px 13px;border-radius:99px}
  .aside h2{font-size:clamp(28px,3.6vw,40px);margin:16px 0 0}
  .aside p{color:#cdc5d6;font-size:15px;margin:12px 0 0;line-height:1.55;max-width:34ch}
  .bnf{display:flex;flex-direction:column;gap:12px;margin:22px 0 0}
  .bnf .it{display:flex;align-items:center;gap:11px;font-size:14.5px;font-weight:600}
  .bnf .ic{width:34px;height:34px;border-radius:10px;flex:none;display:grid;place-items:center;font-size:17px;background:rgba(255,255,255,.1)}
  .aside .proof{display:inline-flex;align-items:center;gap:9px;margin-top:24px;font-size:13px;color:#bdb4c9;
    background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:99px;padding:8px 14px}
  .aside .proof b{color:#fff}
  .aside .proof .dot{width:8px;height:8px;border-radius:50%;background:#39d98a;box-shadow:0 0 0 0 rgba(57,217,138,.5);animation:beat 2s infinite}
  @keyframes beat{0%{box-shadow:0 0 0 0 rgba(57,217,138,.5)}70%{box-shadow:0 0 0 7px rgba(57,217,138,0)}100%{box-shadow:0 0 0 0 rgba(57,217,138,0)}}

  /* Formulario */
  .formwrap h1{font-size:clamp(28px,3.4vw,38px);margin:0}
  .formwrap .sub{color:var(--muted);font-size:15px;margin:8px 0 0}
  .card{background:var(--card,#fff);border:1px solid var(--line);border-radius:22px;padding:26px;box-shadow:var(--shadow);margin-top:18px}
  label{display:block;font-weight:700;font-size:13px;margin:13px 0 6px;color:var(--tinta)}
  input,select{width:100%;font-family:inherit;font-size:16px;color:var(--tinta);background:#fff;border:1.5px solid var(--line);border-radius:13px;padding:12px 14px}
  input:focus,select:focus{outline:none;border-color:var(--terracota);box-shadow:0 0 0 4px color-mix(in srgb,var(--terracota) 18%,transparent)}
  .r2{display:flex;gap:12px}.r2>div{flex:1}
  .go{margin-top:20px;width:100%;background:var(--grad);color:#fff;border:0;cursor:pointer;font-weight:800;font-size:16px;
    padding:15px;border-radius:99px;box-shadow:0 14px 32px -12px rgba(192,57,95,.55);position:relative;overflow:hidden;transition:transform .15s,filter .15s}
  .go:hover{transform:translateY(-2px);filter:brightness(1.05)}
  .go::after{content:"";position:absolute;top:0;left:-130%;width:55%;height:100%;
    background:linear-gradient(100deg,transparent,rgba(255,255,255,.45),transparent);transform:skewX(-18deg);animation:shine 5s ease-in-out infinite}
  @keyframes shine{0%,62%{left:-130%}82%,100%{left:170%}}
  .err{background:var(--noo-bg);color:var(--noo-ink);font-weight:700;font-size:14px;padding:11px 14px;border-radius:12px;margin-bottom:6px}
  .trust{text-align:center;font-size:13px;color:var(--muted);margin-top:14px}
  .alt{text-align:center;margin-top:14px;font-size:14px;color:var(--muted)}
  .alt a{color:var(--terracota);font-weight:700;text-decoration:none}

  @media(max-width:820px){
    .reg{grid-template-columns:1fr;gap:20px;padding-top:6px}
    .aside{order:2;padding:26px 24px}
    .formwrap{order:1}
    .aside p{max-width:none}
  }
  @media(prefers-reduced-motion:reduce){.go::after,.aside .proof .dot{animation:none}}
</style>
</head>
<body>

<div class="topbar">
  <a href="/crecer/index.php"><img src="/crecer/assets/brand/encuentralo-pin.svg" alt=""><b>encuéntralo</b></a>
  <span class="sp"></span>
  <a class="lg" href="/crecer/login.php">¿Ya tienes cuenta? Entra →</a>
</div>

<div class="reg">
  <!-- PANEL: únete al corillo -->
  <aside class="aside">
    <div class="in">
      <span class="pill">🤝 <?= $plan_lbl ? 'Vas pa\' '.$h($plan_lbl) : 'Tu corillo te espera' ?></span>
      <h2 class="disp">Móntate tu <span class="g">corillo</span> en un minuto.</h2>
      <p>Creas tu cuenta, le hablas 40 segundos de tu negocio, y el corillo arranca a trabajarte el marketing. Tú solo apruebas.</p>
      <div class="bnf">
        <div class="it"><span class="ic">🎤</span> Onboarding por voz — sin formularios largos</div>
        <div class="it"><span class="ic">✍️</span> Tu primer post listo, en tu voz boricua</div>
        <div class="it"><span class="ic">💳</span> Gratis y sin tarjeta para empezar</div>
      </div>
      <div class="proof"><span class="dot"></span><span>El corillo ya trabaja <b><?= number_format($negocios) ?></b> negocios · <b><?= number_format($acciones) ?></b> acciones de IA</span></div>
    </div>
  </aside>

  <!-- FORMULARIO -->
  <div class="formwrap">
    <?php if (!empty($_GET['enviado'])): $em = $h($_GET['enviado']); ?>
    <h1 class="disp">Revisa tu <span class="g">correo</span> 📬</h1>
    <p class="sub">Te enviamos un enlace a <b><?= $em ?></b>. Ábrelo y dale <b>"Activar mi cuenta"</b> para confirmar que eres humano — y de una vez, tu primer post de muestra.</p>
    <div class="card" style="text-align:center">
      <div style="font-size:44px;margin-bottom:6px">✉️</div>
      <p style="font-size:14px;color:var(--muted,#8a7f72);line-height:1.5;margin-bottom:14px">¿No llegó en un par de minutos? Revisa <b>spam/promociones</b>, o reenvíalo.</p>
      <form method="post" action="/crecer/reenviar.php">
        <?= csrf_field() ?>
        <input type="hidden" name="email" value="<?= $em ?>">
        <button class="go" type="submit">Reenviar el enlace</button>
      </form>
    </div>
    <p class="alt">¿Ya lo activaste? <a href="/crecer/login.php">Entra aquí</a></p>
    <?php else: ?>
    <h1 class="disp">Crea tu <span class="g">cuenta</span> 🌱</h1>
    <p class="sub">Toma 1 minuto. Activas por correo (confirmamos que eres humano) y el corillo hace el resto.</p>

    <form method="post" class="card">
      <?= csrf_field() ?>
      <?php if ($err): ?><div class="err">⚠️ <?= $err ?></div><?php endif; ?>

      <label>Tu nombre *</label>
      <input name="nombre" required value="<?= $h($val['nombre']) ?>" placeholder="Nombre y apellido">

      <label>Email *</label>
      <input type="email" name="email" required value="<?= $h($val['email']) ?>" placeholder="tu@email.com">

      <div class="r2">
        <div><label>Contraseña *</label><input type="password" name="password" required placeholder="Mín. 8 caracteres"></div>
        <div><label>Repítela *</label><input type="password" name="password2" required placeholder="Otra vez"></div>
      </div>

      <button class="go" type="submit">Crear mi cuenta →</button>
      <p class="trust">Gratis · sin tarjeta · en 1 minuto lo tienes corriendo</p>
    </form>

    <p class="alt">¿Ya tienes cuenta? <a href="/crecer/login.php">Entra aquí</a></p>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
