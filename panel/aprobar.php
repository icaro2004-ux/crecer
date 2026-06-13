<?php
// ============================================================
//  CRECER — Panel de aprobación móvil
//  panel/aprobar.php
//
//  El dueño del negocio ve los borradores que la IA generó y
//  aprueba o rechaza cada uno desde el celular. Cambia
//  crecer_contenido.estado: borrador -> aprobado | rechazado.
//
//  MVP: la marca se pasa por ?marca=<id> (auth real = TODO,
//  reusar sesión de usuarios de Encuéntralo más adelante).
// ============================================================

require __DIR__ . '/../includes/db.php';

$marca_id = (int)($_GET['marca'] ?? 1);

// ── Acción POST (Patrón PRG: procesar y redirigir) ──────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int)($_POST['id'] ?? 0);
    $accion = $_POST['accion'] ?? '';
    $nuevo  = ['aprobar' => 'aprobado', 'rechazar' => 'rechazado', 'reabrir' => 'borrador'][$accion] ?? null;
    if ($id && $nuevo) {
        $up = $pdo->prepare("UPDATE crecer_contenido SET estado = ?, updated_at = NOW() WHERE id = ? AND marca_id = ?");
        $up->execute([$nuevo, $id, $marca_id]);
    }
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

// ── Datos: marca + piezas del calendario más reciente ───────
$m = $pdo->prepare("SELECT * FROM crecer_marca WHERE id = ?");
$m->execute([$marca_id]);
$marca = $m->fetch();
if (!$marca) { http_response_code(404); exit('Negocio no encontrado.'); }

$piezas = $pdo->prepare(
    "SELECT c.* FROM crecer_contenido c
       JOIN crecer_calendario cal ON cal.id = c.calendario_id
      WHERE c.marca_id = ?
        AND cal.id = (SELECT id FROM crecer_calendario WHERE marca_id = ? ORDER BY anio DESC, mes DESC LIMIT 1)
      ORDER BY c.fecha_programada");
$piezas->execute([$marca_id, $marca_id]);
$piezas = $piezas->fetchAll();

$cuenta = ['borrador' => 0, 'aprobado' => 0, 'rechazado' => 0, 'publicado' => 0];
foreach ($piezas as $p) { $cuenta[$p['estado']] = ($cuenta[$p['estado']] ?? 0) + 1; }
$total = count($piezas);
$listos = $cuenta['aprobado'] + $cuenta['publicado'];

$emoji_plat = ['instagram' => '📸', 'facebook' => '👍', 'whatsapp' => '💬'];
$badge = [
    'borrador'  => ['Pendiente', '#92610a', '#fef3c7'],
    'aprobado'  => ['Aprobado',  '#166534', '#dcfce7'],
    'rechazado' => ['Rechazado', '#991b1b', '#fee2e2'],
    'publicado' => ['Publicado', '#1e40af', '#dbeafe'],
];
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Aprobar contenido · <?= $h($marca['nombre_negocio']) ?></title>
<style>
  :root{
    --bg:#f4f1ec; --card:#fff; --ink:#1c1917; --muted:#78716c;
    --brand:#e11d48; --brand2:#f43f5e; --line:#e7e2d9;
    --ok:#16a34a; --no:#dc2626;
  }
  *{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
  body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
    background:var(--bg);color:var(--ink);line-height:1.5;
    padding-bottom:env(safe-area-inset-bottom)}
  .wrap{max-width:520px;margin:0 auto}

  header{position:sticky;top:0;z-index:10;
    background:linear-gradient(135deg,var(--brand),var(--brand2));
    color:#fff;padding:18px 20px calc(18px + 14px);
    border-radius:0 0 22px 22px;box-shadow:0 6px 20px rgba(225,29,72,.25)}
  .eyebrow{font-size:12px;letter-spacing:.08em;text-transform:uppercase;opacity:.85;font-weight:600}
  header h1{font-size:21px;font-weight:800;margin-top:2px}
  .sub{font-size:13px;opacity:.9;margin-top:2px}
  .progress{margin-top:14px;background:rgba(255,255,255,.25);border-radius:99px;height:8px;overflow:hidden}
  .progress > i{display:block;height:100%;background:#fff;border-radius:99px;
    width:<?= $total ? round($listos/$total*100) : 0 ?>%}
  .progress-txt{font-size:12px;margin-top:6px;opacity:.92;font-weight:600}

  .list{padding:16px 14px 90px;display:flex;flex-direction:column;gap:14px}

  .card{background:var(--card);border:1px solid var(--line);border-radius:18px;
    overflow:hidden;box-shadow:0 2px 8px rgba(28,25,23,.05)}
  .card.is-done{opacity:.62}
  .card-top{display:flex;align-items:center;gap:8px;padding:13px 16px 0}
  .chip{font-size:12px;font-weight:700;padding:4px 10px;border-radius:99px;
    background:#f5f5f4;color:#44403c}
  .chip.cap{text-transform:capitalize}
  .date{margin-left:auto;font-size:12px;color:var(--muted);font-weight:600}
  .status{display:inline-block;font-size:11px;font-weight:800;padding:3px 9px;
    border-radius:99px;letter-spacing:.02em}
  .caption{padding:10px 16px 14px;font-size:15px;white-space:pre-wrap}

  .actions{display:flex;gap:0;border-top:1px solid var(--line)}
  .actions form{flex:1;display:flex}
  .btn{flex:1;border:0;background:none;padding:14px;font-size:15px;font-weight:800;
    cursor:pointer;font-family:inherit;display:flex;align-items:center;
    justify-content:center;gap:6px}
  .btn.ok{color:var(--ok)} .btn.no{color:var(--no);border-left:1px solid var(--line)}
  .btn.ghost{color:var(--muted)}
  .btn:active{background:#faf9f7}

  .empty{text-align:center;padding:60px 24px;color:var(--muted)}
  .empty .big{font-size:42px;margin-bottom:8px}
  footer{text-align:center;color:var(--muted);font-size:12px;padding:8px 0 24px}
</style>
</head>
<body>
<div class="wrap">
  <header>
    <div class="eyebrow">Crecer · tu marketing con IA</div>
    <h1><?= $h($marca['nombre_negocio']) ?></h1>
    <div class="sub">Revisa lo que preparó la IA y aprueba lo que te guste 👇</div>
    <div class="progress"><i></i></div>
    <div class="progress-txt"><?= $listos ?> de <?= $total ?> listos para publicar
      · <?= $cuenta['borrador'] ?> pendiente<?= $cuenta['borrador']==1?'':'s' ?></div>
  </header>

  <div class="list">
    <?php if (!$total): ?>
      <div class="empty">
        <div class="big">🗓️</div>
        Todavía no hay contenido planificado para este negocio.<br>
        Corre el planificador y vuelve aquí.
      </div>
    <?php endif; ?>

    <?php foreach ($piezas as $p):
      [$bl, $bc, $bg] = $badge[$p['estado']] ?? ['—','#000','#eee'];
      $done = in_array($p['estado'], ['aprobado','rechazado','publicado'], true);
      $fecha = date('d/m', strtotime($p['fecha_programada'] ?: 'now'));
    ?>
      <div class="card <?= $done ? 'is-done' : '' ?>">
        <div class="card-top">
          <span class="chip"><?= $emoji_plat[$p['plataforma']] ?? '' ?> <?= $h(ucfirst($p['plataforma'])) ?></span>
          <span class="chip cap"><?= $h($p['tipo']) ?></span>
          <span class="status" style="color:<?= $bc ?>;background:<?= $bg ?>"><?= $bl ?></span>
          <span class="date">📅 <?= $fecha ?></span>
        </div>
        <div class="caption"><?= $h($p['caption']) ?></div>
        <div class="actions">
          <?php if ($p['estado'] === 'borrador'): ?>
            <form method="post"><input type="hidden" name="id" value="<?= $p['id'] ?>">
              <button class="btn ok" name="accion" value="aprobar">✓ Aprobar</button></form>
            <form method="post"><input type="hidden" name="id" value="<?= $p['id'] ?>">
              <button class="btn no" name="accion" value="rechazar">✕ Rechazar</button></form>
          <?php else: ?>
            <form method="post"><input type="hidden" name="id" value="<?= $p['id'] ?>">
              <button class="btn ghost" name="accion" value="reabrir">↺ Volver a revisar</button></form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <footer>Hecho por tu departamento de marketing con IA 🤖</footer>
</div>
</body>
</html>
