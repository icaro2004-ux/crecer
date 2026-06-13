<?php
// ============================================================
//  ENCUÉNTRALO — Panel de aprobación (rediseño "Calor Boricua")
//  panel/aprobar2.php   ·  usa assets/encuentralo-ui.css
// ============================================================

require __DIR__ . '/../includes/db.php';

$marca_id = (int)($_GET['marca'] ?? 1);

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
$total  = count($piezas);
$listos = $cuenta['aprobado'] + $cuenta['publicado'];
$pct    = $total ? round($listos / $total * 100) : 0;

$plat = [
    'instagram' => ['Instagram', ''],
    'facebook'  => ['Facebook', 'fb'],
    'whatsapp'  => ['WhatsApp', ''],
];
$pill = [
    'borrador'  => ['Pendiente', 'wait'],
    'aprobado'  => ['Aprobado',  'ok'],
    'rechazado' => ['Rechazado', 'no'],
    'publicado' => ['Publicado', 'pub'],
];
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Aprobar contenido · <?= $h($marca['nombre_negocio']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="icon" type="image/svg+xml" href="/crecer/assets/brand/encuentralo-icon.svg">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="/crecer/assets/encuentralo-ui.css" rel="stylesheet">
</head>
<body>
<div class="app">

  <div class="topbar">
    <img class="logo" src="/crecer/assets/brand/encuentralo-wordmark.svg" alt="Encuéntralo">

    <span class="tier">🌿 Crecer · Intermedio</span>
  </div>

  <header class="hero">
    <h1><?= $h($marca['nombre_negocio']) ?></h1>
    <p class="lede">Tu IA ya preparó el contenido del mes. Revisa y aprueba lo que te guste — tú tienes la última palabra.</p>

    <div class="progress">
      <div class="row">
        <span class="count"><b><?= $listos ?></b> de <?= $total ?> listos para publicar</span>
        <span class="pending"><?= $cuenta['borrador'] ?> por revisar</span>
      </div>
      <div class="track"><i style="width:<?= $pct ?>%"></i></div>
    </div>
  </header>

  <div class="feed">
    <?php if (!$total): ?>
      <div class="empty"><div class="big">🌱</div>Todavía no hay contenido para este negocio.</div>
    <?php endif; ?>

    <?php foreach ($piezas as $p):
      [$pl_label, $pl_cls] = $plat[$p['plataforma']] ?? [ucfirst($p['plataforma']), ''];
      [$pi_label, $pi_cls] = $pill[$p['estado']] ?? ['—', 'wait'];
      $done = in_array($p['estado'], ['aprobado','rechazado','publicado'], true);
      $fecha = date('d/m', strtotime($p['fecha_programada'] ?: 'now'));
    ?>
      <article class="post <?= $done ? 'done' : '' ?>">
        <div class="post-head">
          <span class="chip <?= $pl_cls ?>"><span class="ico"></span><?= $h($pl_label) ?></span>
          <span class="chip"><?= $h($p['tipo']) ?></span>
          <span class="pill <?= $pi_cls ?>"><?= $pi_label ?></span>
          <span class="date"><?= $fecha ?></span>
        </div>
        <div class="caption"><?= $h($p['caption']) ?></div>
        <div class="post-actions">
          <?php if ($p['estado'] === 'borrador'): ?>
            <form method="post"><input type="hidden" name="id" value="<?= $p['id'] ?>">
              <button class="btn btn-ok" name="accion" value="aprobar">✓ Aprobar</button></form>
            <form method="post"><input type="hidden" name="id" value="<?= $p['id'] ?>">
              <button class="btn btn-no" name="accion" value="rechazar">Rechazar</button></form>
          <?php else: ?>
            <form method="post"><input type="hidden" name="id" value="<?= $p['id'] ?>">
              <button class="btn btn-ghost" name="accion" value="reabrir">↺ Volver a revisar</button></form>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>

  <p class="foot">Tu departamento de marketing con IA · <b>Encuéntralo</b> 🇵🇷</p>
</div>
</body>
</html>
