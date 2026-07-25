<?php
// ============================================================
//  CRECER — Centro de Notificaciones (vista)  panel/notificaciones_centro.php
//  El Corillo avisa aquí cuando termina algo (reel listo/publicado,
//  posts, etc.). Módulo AISLADO. Usa includes/notif.php.
//
//  Endpoints:
//   ?ajax=contar  → {ok, no_leidas}  (para la campanita)
//   POST leer     → marca todas leídas
//   (GET normal)  → la página con la lista
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notif.php';
requiere_login();
$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }
$marca_id = (int)$marca['id'];
$BASE = '/crecer/panel';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// Contador para la campanita (poll ligero desde cualquier pantalla).
if (($_GET['ajax'] ?? '') === 'contar') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true, 'no_leidas'=>notif_no_leidas($pdo, $marca_id)]); exit;
}
// Marcar leídas.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'leer') {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_ok()) { echo json_encode(['ok'=>false]); exit; }
    notif_marcar_leidas($pdo, $marca_id);
    echo json_encode(['ok'=>true]); exit;
}

$items = notif_listar($pdo, $marca_id, 50);
$no_leidas = notif_no_leidas($pdo, $marca_id);
$CSRF = csrf_token();
// Al abrir la página, marcar como leídas (ya las está viendo).
notif_marcar_leidas($pdo, $marca_id);

function notif_hace(string $ts): string {
    $t = strtotime($ts); if (!$t) return '';
    $d = time() - $t;
    if ($d < 60) return 'ahora';
    if ($d < 3600) return floor($d/60) . ' min';
    if ($d < 86400) return floor($d/3600) . ' h';
    return floor($d/86400) . ' d';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Notificaciones · Crecer</title>
<link rel="icon" type="image/png" href="/crecer/assets/brand/crecer-icon.png">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--rosa:#EF4375;--teal:#00A49F;--tinta:#231F20;--muted:#7a7580;--line:#eceaee;--crema:#faf9fb}
*{box-sizing:border-box}body{margin:0;font-family:'Plus Jakarta Sans',system-ui,sans-serif;color:var(--tinta);background:var(--crema)}
.wrap{max-width:640px;margin:0 auto;padding:18px 16px 60px}
.top{display:flex;align-items:center;gap:10px;padding:6px 0 14px}
.top a{color:var(--muted);text-decoration:none;font-weight:700;font-size:14px}
h1{font-family:'Poppins';font-size:26px;font-weight:900;margin:6px 0 16px}
.n{display:flex;gap:13px;align-items:flex-start;background:#fff;border:1px solid var(--line);border-radius:16px;padding:14px 15px;margin-bottom:10px;text-decoration:none;color:inherit;transition:.15s}
.n:hover{border-color:#d9d5de;transform:translateY(-1px)}
.n.un{background:#fff5f8;border-color:#f6ccda}
.n .em{font-size:24px;width:44px;height:44px;flex-shrink:0;border-radius:12px;background:var(--crema);display:flex;align-items:center;justify-content:center}
.n.un .em{background:#ffe3ec}
.n .tt{font-family:'Poppins';font-weight:700;font-size:15px;line-height:1.25}
.n .ms{color:var(--muted);font-size:13.5px;margin-top:3px;line-height:1.4}
.n .tm{color:var(--muted);font-size:12px;margin-left:auto;flex-shrink:0;white-space:nowrap}
.empty{text-align:center;color:var(--muted);padding:50px 20px}
.empty .big{font-size:44px}
</style>
</head>
<body>
<div class="wrap">
  <div class="top"><a href="<?= $BASE ?>/index.php?marca=<?= $marca_id ?>">← Volver al panel</a></div>
  <h1>🔔 Notificaciones</h1>

  <?php if (!$items): ?>
    <div class="empty">
      <div class="big">🌱</div>
      <p><b>Todavía nada por aquí.</b><br>Cuando el corillo termine un reel o publique algo, te aviso aquí.</p>
    </div>
  <?php else: ?>
    <?php foreach ($items as $n): $un = !$n['leida']; ?>
      <a class="n <?= $un ? 'un' : '' ?>" href="<?= $h($n['link'] ?: ($BASE.'/index.php?marca='.$marca_id)) ?>">
        <div class="em"><?= $h($n['icono'] ?: '🔔') ?></div>
        <div>
          <div class="tt"><?= $h($n['titulo']) ?></div>
          <?php if ($n['mensaje']): ?><div class="ms"><?= $h($n['mensaje']) ?></div><?php endif; ?>
        </div>
        <div class="tm"><?= $h(notif_hace($n['created_at'])) ?></div>
      </a>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
</body>
</html>
