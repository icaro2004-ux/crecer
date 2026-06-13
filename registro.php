<?php
// ============================================================
//  CRECER — Registro / crear cuenta  (registro.php)
// ============================================================
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';

if (esta_logueado()) { header('Location: /crecer/panel/index.php'); exit; }

$err = ''; $val = ['nombre'=>'','email'=>'','telefono'=>'','municipio_id'=>''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($val as $k=>$_) $val[$k] = trim($_POST[$k] ?? '');
    $pass  = $_POST['password'] ?? '';
    $pass2 = $_POST['password2'] ?? '';

    if (!csrf_ok())                       $err = 'La sesión expiró. Recarga e intenta otra vez.';
    elseif ($val['nombre']==='' || $val['email']==='' || $val['telefono']==='' || $val['municipio_id']==='')
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
            $ins = $pdo->prepare("INSERT INTO usuarios (nombre, email, password, telefono, municipio_id, rol)
                                  VALUES (?,?,?,?,?, 'proveedor')");
            $ins->execute([
                $val['nombre'], $val['email'], password_hash($pass, PASSWORD_DEFAULT),
                $val['telefono'], (int)$val['municipio_id'],
            ]);
            $uid = (int)$pdo->lastInsertId();
            login_usuario(['id'=>$uid, 'nombre'=>$val['nombre'], 'rol'=>'proveedor']);
            header('Location: /crecer/intake.php');  // ahora a montar el negocio
            exit;
        }
    }
}

$municipios = $pdo->query("SELECT id, nombre FROM municipios ORDER BY nombre")->fetchAll();
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
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
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="/crecer/assets/encuentralo-ui.css" rel="stylesheet">
<style>
  .auth{max-width:440px;margin:0 auto;padding:34px 22px 60px}
  .auth .top{text-align:center;margin-bottom:8px}
  .auth .top a{display:inline-flex;align-items:center;gap:9px;text-decoration:none;color:inherit}
  .auth .top img{height:34px}
  .auth .top b{font-family:var(--font-display);font-weight:800;font-size:21px;letter-spacing:-.03em;text-transform:lowercase}
  .auth h1{font-family:var(--font-display);font-weight:800;font-size:28px;letter-spacing:-.025em;text-align:center;margin-top:18px}
  .auth .sub{color:var(--muted);text-align:center;font-size:15px;margin-top:6px}
  .card{background:var(--card);border:1px solid var(--line);border-radius:var(--r-xl);padding:26px;box-shadow:var(--shadow);margin-top:20px}
  label{display:block;font-weight:700;font-size:13.5px;margin:13px 0 6px}
  input,select{width:100%;font-family:inherit;font-size:16px;color:var(--tinta);background:#fff;border:1.5px solid var(--line);border-radius:13px;padding:12px 14px}
  input:focus,select:focus{outline:none;border-color:var(--terracota);box-shadow:0 0 0 4px rgba(239,67,117,.12)}
  .r2{display:flex;gap:12px}.r2>div{flex:1}
  .go{margin-top:20px;width:100%;background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff;border:0;cursor:pointer;font-weight:800;font-size:16px;padding:14px;border-radius:99px;box-shadow:0 12px 28px rgba(255,43,133,.3)}
  .err{background:var(--noo-bg);color:var(--noo-ink);font-weight:700;font-size:14px;padding:11px 14px;border-radius:12px;margin-bottom:6px}
  .alt{text-align:center;margin-top:16px;font-size:14px;color:var(--muted)}
  .alt a{color:var(--terracota);font-weight:700;text-decoration:none}
</style>
</head>
<body>
<div class="auth">
  <div class="top"><a href="/crecer/index.php"><img src="/crecer/assets/brand/encuentralo-pin.svg" alt=""><b>encuéntralo</b></a></div>
  <h1>Crea tu cuenta 🌱</h1>
  <p class="sub">Empieza a crecer tu negocio con IA. Toma 1 minuto.</p>

  <form method="post" class="card">
    <?= csrf_field() ?>
    <?php if ($err): ?><div class="err">⚠️ <?= $err ?></div><?php endif; ?>

    <label>Tu nombre *</label>
    <input name="nombre" required value="<?= $h($val['nombre']) ?>" placeholder="Nombre y apellido">

    <label>Email *</label>
    <input type="email" name="email" required value="<?= $h($val['email']) ?>" placeholder="tu@email.com">

    <div class="r2">
      <div><label>WhatsApp / teléfono *</label><input name="telefono" required value="<?= $h($val['telefono']) ?>" placeholder="787-555-0000"></div>
      <div><label>Municipio *</label>
        <select name="municipio_id" required>
          <option value="">Escoge…</option>
          <?php foreach ($municipios as $m): ?>
            <option value="<?= $m['id'] ?>" <?= (string)$val['municipio_id']===(string)$m['id']?'selected':'' ?>><?= $h($m['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="r2">
      <div><label>Contraseña *</label><input type="password" name="password" required placeholder="Mín. 8 caracteres"></div>
      <div><label>Repítela *</label><input type="password" name="password2" required placeholder="Otra vez"></div>
    </div>

    <button class="go" type="submit">Crear mi cuenta →</button>
  </form>

  <p class="alt">¿Ya tienes cuenta? <a href="/crecer/login.php">Entra aquí</a></p>
</div>
</body>
</html>
