<?php
// ============================================================
//  CRECER — Registro / crear cuenta  (registro.php)
// ============================================================
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/iconos.php';

// Recuerda el plan elegido en el landing (para llevarlo al checkout tras el onboarding)
if (!empty($_GET['plan']) && in_array($_GET['plan'], ['crecer','despegar'], true)) {
    $_SESSION['plan_intent'] = $_GET['plan'];
}
// Nombre del negocio que viene del landing (LANDING V4). Se sanitiza, se guarda
// en sesión y se prellena en el onboarding (donde vive el campo real del negocio).
if (isset($_GET['negocio'])) {
    $neg = preg_replace('/\s+/u', ' ', trim(strip_tags((string)$_GET['negocio']))) ?? '';
    $neg = mb_substr($neg, 0, 60);
    if ($neg !== '') $_SESSION['negocio_intent'] = $neg;
}
$negocio_intent = $_SESSION['negocio_intent'] ?? '';

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
            // EXCEPCIÓN — CUENTAS DE PRUEBA: si el email está en CRECER_TEST_EMAILS
            // (o CRECER_DEV_ACTIVAR en local), entra DIRECTO sin correo, para poder
            // probar el flujo completo desde la página. Los reales sí pasan por correo.
            require_once __DIR__ . '/includes/suscripcion.php';
            $es_prueba = activacion_de_prueba($val['email']);
            $token = bin2hex(random_bytes(32));
            $ins = $pdo->prepare("INSERT INTO usuarios (nombre, email, password, rol, verificado, verif_token)
                                  VALUES (?,?,?, 'proveedor', ?, ?)");
            $ins->execute([$val['nombre'], $val['email'], password_hash($pass, PASSWORD_DEFAULT), $es_prueba ? 1 : 0, $token]);
            $nuevo_id = (int)$pdo->lastInsertId();

            if ($es_prueba) {
                login_usuario(['id'=>$nuevo_id, 'nombre'=>$val['nombre'], 'rol'=>'proveedor']);
                header('Location: /crecer/onboarding.php'); exit;   // prueba: sin correo, directo
            }

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
<title>Crear cuenta · Encuéntralo Crecer</title>
<link rel="icon" type="image/png" href="/crecer/assets/brand/encuentralo-crecer-pin-drop.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="/crecer/assets/crecer-brand.css?v=2" rel="stylesheet">
<style>
  body{padding-top:66px}
  .auth{width:min(1000px,calc(100% - 44px));margin:0 auto;padding:40px 0 60px;display:grid;grid-template-columns:1.04fr .96fr;gap:44px;align-items:center;min-height:calc(100vh - 66px)}
  .welcome .eyebrow{font-family:var(--display);font-weight:600;font-size:15px;color:var(--muted);margin:0 0 14px}
  .welcome h1{font-family:var(--display);font-weight:600;letter-spacing:-.025em;font-size:clamp(32px,5vw,48px);line-height:1.06;margin:0;color:var(--ink-soft)}
  .welcome p{margin:18px 0 0;font-size:16.5px;line-height:1.55;color:var(--muted);max-width:42ch}
  .bnf{display:flex;flex-direction:column;gap:12px;margin:22px 0 0}
  .bnf .it{display:flex;align-items:center;gap:11px;font-size:15px;font-weight:500;color:var(--ink)}
  .bnf .it svg{width:20px;height:20px;color:var(--teal);flex:none}
  .welcome .chip-live{margin-top:24px}
  .formwrap h1{font-family:var(--display);font-weight:600;letter-spacing:-.02em;font-size:clamp(24px,3.2vw,32px);line-height:1.1;margin:0;color:var(--ink-soft)}
  .formwrap .sub{color:var(--muted);font-size:15px;font-weight:400;margin:8px 0 0;line-height:1.5}
  .formwrap form{margin-top:18px}
  .r2{display:flex;gap:12px}.r2>div{flex:1}
  .go-full{margin-top:20px;width:100%}
  .trust{text-align:center;font-size:13px;color:var(--muted);font-weight:400;margin-top:14px}
  .alt{text-align:center;margin-top:14px;font-size:14px;color:var(--muted);font-weight:400}
  .alt a{color:var(--teal-dark);font-weight:600;text-decoration:none}
  .legal{text-align:center;font-size:12px;color:var(--muted);margin-top:16px}
  .legal a{color:var(--muted);text-decoration:none}
  .legal a:hover{color:var(--magenta)}
  .big-ic svg{width:46px;height:46px;color:var(--teal)}
  /* Dos narrativas: por defecto (desktop) el móvil-only queda oculto */
  .sub-m{display:none}
  .after{display:none}
  .after h2{font-family:var(--display);font-weight:600;font-size:17px;color:var(--ink-soft);letter-spacing:-.01em;margin:0 0 16px}
  .steps{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:14px}
  .steps li{display:flex;align-items:center;gap:13px;font-size:15.5px;color:var(--ink);line-height:1.35}
  .steps .sn{flex:none;width:26px;height:26px;border-radius:50%;color:var(--magenta);font-family:var(--display);font-weight:600;font-size:13px;
    display:grid;place-items:center;background:color-mix(in srgb,var(--magenta) 10%,#fff);border:1px solid color-mix(in srgb,var(--magenta) 22%,#fff)}
  /* ── MÓVIL: nace para móvil. Menos texto, el formulario pegado al título,
        la acción antes del primer scroll, y "¿Qué pasa después?" en 3 pasos. ── */
  @media(max-width:820px){
    .auth{grid-template-columns:1fr;gap:16px;padding-top:16px;min-height:0}
    .welcome{text-align:center}
    .welcome .eyebrow{margin-bottom:10px}
    .welcome h1{font-size:clamp(30px,7.6vw,38px)}
    .sub-d{display:none}
    .sub-m{display:block;margin:14px auto 0;max-width:26ch;font-size:16px;color:var(--muted)}
    .bnf{display:none}
    .welcome .chip-live{display:none}
    .form-h,.form-sub{display:none}
    .formwrap .brand-card{margin-top:2px}
    .after{display:block;margin-top:22px;width:100%;max-width:440px;margin-inline:auto;text-align:left}
    .trust{font-weight:400}
  }
</style>
</head>
<body>

<nav class="cnav">
  <div class="in wrap">
    <a class="cbrand" href="/crecer/crecer.php"><img src="/crecer/assets/brand/crecer-mark.svg" alt=""><b>encuéntralo <span class="teal">crecer</span></b></a>
    <span class="sp"></span>
    <a class="lg" href="/crecer/login.php">¿Ya tienes cuenta? Entra →</a>
  </div>
</nav>

<div class="auth">
  <!-- Únete al corillo -->
  <section class="welcome">
    <p class="eyebrow"><?= $negocio_intent ? 'El corillo de '.$h($negocio_intent).' te espera.' : ($plan_lbl ? 'Vas pa\' '.$h($plan_lbl).'.' : 'Tu corillo te espera.') ?></p>
    <h1>Monta tu <span class="teal u-teal">corillo</span> en un minuto.</h1>
    <p class="sub-d">Creas tu cuenta, le hablas 40 segundos de tu negocio, y el corillo arranca a trabajarte el marketing. Tú solo apruebas.</p>
    <p class="sub-m">Cuéntanos sobre tu negocio. El Corillo hace el resto.</p>
    <div class="bnf">
      <div class="it"><?= ico('mic') ?> Onboarding por voz — sin formularios largos</div>
      <div class="it"><?= ico('pen') ?> Tu primer post listo, en tu voz boricua</div>
      <div class="it"><?= ico('wallet') ?> Gratis y sin tarjeta para empezar</div>
    </div>
    <div class="chip-live"><span class="dot"></span><span>El corillo ya trabaja <b><?= number_format($negocios) ?></b> negocios · <b><?= number_format($acciones) ?></b> acciones de IA</span></div>
  </section>

  <!-- Formulario -->
  <div class="formwrap">
    <?php if (!empty($_GET['enviado'])): $em = $h($_GET['enviado']); ?>
    <h1>Revisa tu <span class="teal u-teal">correo</span></h1>
    <p class="sub">Te enviamos un enlace a <b><?= $em ?></b>. Ábrelo y dale <b>"Activar mi cuenta"</b> para confirmar que eres humano — y de una vez, tu primer post de muestra.</p>
    <div class="brand-card" style="text-align:center;margin-top:18px">
      <div class="big-ic" style="margin-bottom:8px"><?= ico('inbox') ?></div>
      <p style="font-size:14px;color:var(--muted);line-height:1.5;margin-bottom:14px">¿No llegó en un par de minutos? Revisa <b>spam/promociones</b>, o reenvíalo.</p>
      <form method="post" action="/crecer/reenviar.php">
        <?= csrf_field() ?>
        <input type="hidden" name="email" value="<?= $em ?>">
        <button class="btn-pink go-full" type="submit">Reenviar el enlace</button>
      </form>
    </div>
    <p class="alt">¿Ya lo activaste? <a href="/crecer/login.php">Entra aquí</a></p>
    <?php else: ?>
    <h1 class="form-h">Crea tu <span class="pink u-teal">cuenta</span></h1>
    <p class="sub form-sub">Toma 1 minuto. Activas por correo (confirmamos que eres humano) y el corillo hace el resto.</p>

    <form method="post" class="brand-card">
      <?= csrf_field() ?>
      <?php if ($err): ?><div class="alert-err"><?= $err ?></div><?php endif; ?>

      <label class="f-label">Tu nombre *</label>
      <input class="f-input" name="nombre" required value="<?= $h($val['nombre']) ?>" placeholder="Nombre y apellido">

      <label class="f-label">Email *</label>
      <input class="f-input" type="email" name="email" required value="<?= $h($val['email']) ?>" placeholder="tu@email.com">

      <div class="r2">
        <div><label class="f-label">Contraseña *</label><input class="f-input" type="password" name="password" required placeholder="Mín. 8 caracteres"></div>
        <div><label class="f-label">Repítela *</label><input class="f-input" type="password" name="password2" required placeholder="Otra vez"></div>
      </div>

      <button class="btn-pink go-full" type="submit">Crear mi cuenta →</button>
      <p class="trust">Gratis · sin tarjeta · en 1 minuto lo tienes corriendo</p>
    </form>

    <section class="after">
      <h2>¿Qué pasa después?</h2>
      <ol class="steps">
        <li><span class="sn">1</span> Conocemos tu negocio.</li>
        <li><span class="sn">2</span> El Corillo prepara las primeras propuestas.</li>
        <li><span class="sn">3</span> Tú apruebas.</li>
      </ol>
    </section>

    <p class="alt">¿Ya tienes cuenta? <a href="/crecer/login.php">Entra aquí</a></p>
    <?php endif; ?>
    <p class="legal">
      Al crear tu cuenta aceptas los <a href="/crecer/terminos.php">Términos</a>
      y la <a href="/crecer/privacidad.php">Política de Privacidad</a>.</p>
  </div>
</div>
</body>
</html>
