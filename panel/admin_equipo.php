<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Equipo / Colaboradores (admin)
//  panel/admin_equipo.php   ·   solo rol=admin
//  Ver, invitar y quitar administradores sin tocar SQL.
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/notificaciones.php';
requiere_login();
$usuario = usuario_actual($pdo);
if (($usuario['rol'] ?? '') !== 'admin') { http_response_code(403); exit('Acceso solo para administradores.'); }
$YO = (int)$usuario['id'];
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$base = defined('BASE_URL') ? rtrim(BASE_URL,'/') : 'http://localhost/crecer';

$err = ''; $ok = ''; $invite_link = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'add') {
        $nombre = trim($_POST['nombre'] ?? '');
        $email  = trim($_POST['email'] ?? '');
        if ($nombre === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'Pon un nombre y un email válido.';
        } else {
            $q = $pdo->prepare("SELECT id, rol FROM usuarios WHERE email=?");
            $q->execute([$email]); $ex = $q->fetch();
            if ($ex) {
                // Ya tiene cuenta → solo lo subimos a admin
                $pdo->prepare("UPDATE usuarios SET rol='admin', activo=1 WHERE id=?")->execute([(int)$ex['id']]);
                $uid = (int)$ex['id'];
                $ok = "$nombre ya tenía cuenta — lo subimos a administrador. ✅";
            } else {
                // Crear cuenta admin con contraseña aleatoria
                $minmun = (int)$pdo->query("SELECT MIN(id) FROM municipios")->fetchColumn() ?: 1;
                $pdo->prepare("INSERT INTO usuarios (nombre, email, password, telefono, municipio_id, rol)
                               VALUES (?,?,?,?,?, 'admin')")
                    ->execute([$nombre, $email, password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT), '', $minmun]);
                $uid = (int)$pdo->lastInsertId();
                $ok = "Colaborador <b>$nombre</b> creado como administrador. ✅";
            }
            // Link de invitación para que ponga su contraseña (reusa password_resets)
            $token = bin2hex(random_bytes(32));
            $pdo->prepare("UPDATE password_resets SET used=1 WHERE user_id=? AND used=0")->execute([$uid]);
            $pdo->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?,?, DATE_ADD(NOW(), INTERVAL 48 HOUR))")
                ->execute([$uid, $token]);
            $invite_link = $base . '/nuevo-password.php?token=' . $token;
            // Intento de email (best-effort)
            $cuerpo = "<div style='font-family:Arial,sans-serif;max-width:520px;margin:auto;color:#1a1a24'>"
                . "<h2>¡Te sumaron al corillo! 🤝</h2>"
                . "<p>Te dieron acceso de administrador en Crecer. Pon tu contraseña aquí (vale 48h):</p>"
                . "<p style='margin:24px 0'><a href='$invite_link' style='background:#e3683f;color:#fff;text-decoration:none;font-weight:bold;padding:13px 24px;border-radius:12px'>Activar mi cuenta</a></p>"
                . "<p style='font-size:13px;color:#8a8a98'>O copia: $invite_link</p></div>";
            crecer_enviar_email($email, 'Tu acceso a Crecer (admin)', $cuerpo);
        }
    }

    if ($accion === 'remove') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === $YO) { $err = 'No puedes quitarte a ti mismo.'; }
        elseif ($id) { $pdo->prepare("UPDATE usuarios SET rol='proveedor' WHERE id=? AND rol='admin'")->execute([$id]); $ok = 'Acceso de admin retirado.'; }
    }
}

$admins = $pdo->query("SELECT id, nombre, email, activo, created_at FROM usuarios WHERE rol='admin' ORDER BY id")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Equipo · Operaciones · Crecer</title>
<link rel="icon" type="image/svg+xml" href="/crecer/assets/brand/encuentralo-pin.svg">
<link href="https://fonts.googleapis.com/css2?family=Anton&family=Poppins:wght@600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="/crecer/assets/encuentralo-ui.css?v=13" rel="stylesheet">
<style>
  :root{--grad:linear-gradient(120deg,var(--coral,#ff5c39),var(--magenta,#c0395f))}
  *{box-sizing:border-box} body{background:var(--crema,#fbf6ee);color:var(--tinta,#1b1622);font-family:'Plus Jakarta Sans',sans-serif;margin:0}
  body::before{content:"";position:fixed;top:0;left:0;right:0;height:4px;z-index:60;background:var(--grad)}
  .top{display:flex;align-items:center;gap:12px;padding:15px 24px;background:#140a16;color:#fff}
  .top img{height:26px} .top b{font-family:var(--font-display);font-weight:800;text-transform:lowercase;letter-spacing:-.03em;font-size:18px}
  .top .tag{font-family:'Anton';text-transform:uppercase;font-size:11px;letter-spacing:.08em;color:#ffcaa8;border:1px solid rgba(255,255,255,.18);padding:4px 10px;border-radius:99px}
  .top .sp{flex:1} .top a{color:#cdc5d6;text-decoration:none;font-size:13px;font-weight:600}
  .wrap{max-width:720px;margin:0 auto;padding:22px 24px 60px}
  h1{font-family:'Anton';text-transform:uppercase;font-size:28px;letter-spacing:.02em;margin:0 0 4px}
  .lede{color:var(--muted);font-size:14px;margin:0 0 20px}
  .card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px;margin-bottom:16px;box-shadow:var(--shadow-sm)}
  .card h2{font-size:15px;font-weight:800;margin:0 0 12px}
  label{display:block;font-weight:700;font-size:13px;margin:10px 0 6px}
  input{width:100%;font-family:inherit;font-size:15px;border:1.5px solid var(--line);border-radius:11px;padding:11px 13px;background:#fff}
  .row{display:flex;gap:12px;flex-wrap:wrap}.row>div{flex:1;min-width:180px}
  .btn{border:0;cursor:pointer;font-family:inherit;font-weight:800;font-size:14px;color:#fff;background:var(--palma,#16b86a);padding:11px 20px;border-radius:11px;margin-top:14px}
  .msg{padding:11px 14px;border-radius:12px;font-weight:700;font-size:14px;margin-bottom:14px}
  .msg.ok{background:#e6f6ee;color:#0d7a44}.msg.err{background:#fde8e8;color:#9b1c1c}
  .invite{background:#fff7ed;border:1px dashed var(--terracota);border-radius:12px;padding:14px;margin-top:14px;font-size:13.5px}
  .invite b{color:var(--tinta)} .invite .lk{word-break:break-all;color:var(--terracota);font-weight:700}
  table{width:100%;border-collapse:collapse;font-size:14px}
  th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);padding:8px;border-bottom:1px solid var(--line)}
  td{padding:10px 8px;border-bottom:1px solid var(--line)} tr:last-child td{border-bottom:0}
  .you{font-size:11px;font-weight:800;color:#0d7a44;background:#e6f6ee;padding:2px 8px;border-radius:99px;margin-left:6px}
  .rm{border:1.5px solid var(--line);background:#fff;color:#9b1c1c;font-weight:700;font-size:12.5px;cursor:pointer;border-radius:9px;padding:6px 12px}
</style>
</head>
<body>
<?php $op_active='equipo'; require __DIR__.'/_ops_top.php'; ?>
<div class="wrap">
  <h1>Equipo</h1>
  <p class="lede">Administradores con acceso a tu Centro de Operaciones.</p>

  <?php if ($ok): ?><div class="msg ok">✅ <?= $ok ?></div><?php endif; ?>
  <?php if ($err): ?><div class="msg err">⚠️ <?= $h($err) ?></div><?php endif; ?>

  <?php if ($invite_link): ?>
    <div class="invite">
      🔗 <b>Link de invitación</b> (cópialo y mándaselo por WhatsApp si no le llega el email — vale 48h):<br>
      <span class="lk"><?= $h($invite_link) ?></span>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2>➕ Añadir colaborador</h2>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="accion" value="add">
      <div class="row">
        <div><label>Nombre</label><input name="nombre" required placeholder="Nombre y apellido"></div>
        <div><label>Email</label><input type="email" name="email" required placeholder="colaborador@email.com"></div>
      </div>
      <button class="btn" type="submit">Crear acceso de admin</button>
      <p style="font-size:12.5px;color:var(--muted);margin:10px 0 0">Le creamos cuenta de administrador y te damos un link para que ponga su contraseña. Si ya tiene cuenta, solo lo subimos a admin.</p>
    </form>
  </div>

  <div class="card">
    <h2>👥 Administradores (<?= count($admins) ?>)</h2>
    <table>
      <tr><th>Nombre</th><th>Email</th><th></th></tr>
      <?php foreach ($admins as $a): ?>
        <tr>
          <td><b><?= $h($a['nombre']) ?></b><?php if((int)$a['id']===$YO):?><span class="you">tú</span><?php endif;?><?php if(!$a['activo']):?> <span style="color:var(--muted);font-size:11px">(inactivo)</span><?php endif;?></td>
          <td style="color:var(--muted)"><?= $h($a['email']) ?></td>
          <td style="text-align:right">
            <?php if((int)$a['id']!==$YO): ?>
              <form method="post" onsubmit="return confirm('¿Quitarle acceso de admin a <?= $h($a['nombre']) ?>?')" style="display:inline">
                <?= csrf_field() ?><input type="hidden" name="accion" value="remove"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                <button class="rm" type="submit">Quitar</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
</body>
</html>
