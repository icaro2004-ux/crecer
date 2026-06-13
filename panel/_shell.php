<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Shell del panel (header + sidebar)
//  Antes de incluir: define $marca (array), $active (key),
//  opcional $page_title. Cierra con _shell_foot.php.
// ============================================================
$marca_id = (int)$marca['id'];
$BASE = '/crecer/panel';
$h = $h ?? fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$nav = [
  ['key'=>'inicio',   'ic'=>'🏠','lb'=>'Inicio',          'hr'=>"$BASE/index.php?marca=$marca_id",   'st'=>''],
  ['key'=>'contenido','ic'=>'📅','lb'=>'Contenido',       'hr'=>"$BASE/aprobar2.php?marca=$marca_id",'st'=>''],
  ['key'=>'marca',    'ic'=>'🎨','lb'=>'Marca',           'hr'=>'#',                                 'st'=>'pronto'],
  ['key'=>'ordenes',  'ic'=>'📦','lb'=>'Órdenes & Agenda','hr'=>"$BASE/ordenes.php?marca=$marca_id",'st'=>''],
  ['key'=>'clientela','ic'=>'👥','lb'=>'Clientela',       'hr'=>'#',                                 'st'=>'pronto'],
  ['key'=>'cuentas',  'ic'=>'💵','lb'=>'Cuentas',         'hr'=>'#',                                 'st'=>'despegar'],
  ['key'=>'analitica','ic'=>'📊','lb'=>'Analítica',       'hr'=>'#',                                 'st'=>'despegar'],
  ['key'=>'config',   'ic'=>'⚙️','lb'=>'Configuración',    'hr'=>'#',                                 'st'=>'pronto'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= $h(($page_title ?? 'Panel') . ' · ' . $marca['nombre_negocio']) ?> — Encuéntralo</title>
<link rel="icon" type="image/svg+xml" href="/crecer/assets/brand/encuentralo-pin.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="/crecer/assets/encuentralo-ui.css" rel="stylesheet">
</head>
<body>
<div class="layout">
  <aside class="side" id="side">
    <a class="sbrand" href="<?= $BASE ?>/index.php?marca=<?= $marca_id ?>" style="text-decoration:none;color:inherit"><img src="/crecer/assets/brand/encuentralo-pin.svg" alt="Inicio"><b>encuéntralo</b></a>
    <nav>
      <?php foreach ($nav as $n): ?>
        <a href="<?= $n['hr'] ?>" class="<?= $n['key']===$active?'on':'' ?> <?= in_array($n['st'],['pronto','despegar'])?'locked':'' ?>">
          <span class="ic"><?= $n['ic'] ?></span><?= $n['lb'] ?>
          <?php if ($n['st']==='pronto'): ?><span class="badge">pronto</span>
          <?php elseif ($n['st']==='despegar'): ?><span class="badge">despegar</span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="who">
      <div class="av"><?= $h(mb_strtoupper(mb_substr($marca['nombre_negocio'],0,1))) ?></div>
      <div><div class="nm"><?= $h($marca['nombre_negocio']) ?></div><div class="tag">🌿 Crecer · Intermedio</div></div>
    </div>
  </aside>
  <div class="backdrop" id="bd"></div>

  <div class="main">
    <div class="ptop">
      <a href="<?= $BASE ?>/index.php?marca=<?= $marca_id ?>" style="display:flex;align-items:center;gap:8px;text-decoration:none;color:inherit">
        <img src="/crecer/assets/brand/encuentralo-pin.svg" alt="Inicio"><b>encuéntralo</b></a>
      <button class="burger" id="burger" aria-label="Menú">☰</button>
    </div>
    <div class="content">
