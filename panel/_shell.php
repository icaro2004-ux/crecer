<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Shell del panel (header + sidebar)
//  Antes de incluir: define $marca (array), $active (key),
//  opcional $page_title. Cierra con _shell_foot.php.
// ============================================================
$marca_id = (int)$marca['id'];
$BASE = '/crecer/panel';
$h = $h ?? fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
require_once __DIR__ . '/../includes/suscripcion.php';
require_once __DIR__ . '/../includes/iconos.php';
$susc     = suscripcion_de_marca($pdo, $marca_id);
$plan     = suscripcion_activa($susc) ? ($susc['plan_slug'] ?? null) : null;
$plan_etq = suscripcion_etiqueta($susc);
$u_actual = usuario_actual($pdo);
$es_admin = (($u_actual['rol'] ?? '') === 'admin');
$viendo_como_admin = ($es_admin && (int)$marca['usuario_id'] !== (int)($u_actual['id'] ?? 0));
// Navegación PRINCIPAL — solo el loop del producto (contenido para redes).
// Gráficas, Órdenes, Clientela, Cuentas, Analítica y Evidencia salieron del
// menú (siguen accesibles por URL / por el perfil; reversible).
$nav = [
  ['key'=>'inicio',    'ic'=>'home',    'lb'=>'Inicio',     'hr'=>"$BASE/index.php?marca=$marca_id"],
  ['key'=>'contenido', 'ic'=>'calendar','lb'=>'Contenido',  'hr'=>"$BASE/aprobar2.php?marca=$marca_id"],
  ['key'=>'resultados','ic'=>'chart',   'lb'=>'Resultados', 'hr'=>"$BASE/resultados.php?marca=$marca_id"],
  ['key'=>'marca',     'ic'=>'palette', 'lb'=>'Mi marca',   'hr'=>"$BASE/marca.php?marca=$marca_id"],
];
// Perfil (secundario, abajo): config, facturación, soporte.
$nav_perfil = [
  ['key'=>'estratega',   'ic'=>'sparkles','lb'=>'Copiloto', 'hr'=>"$BASE/estratega.php?marca=$marca_id"],
  ['key'=>'config',      'ic'=>'settings','lb'=>'Configuración','hr'=>"$BASE/configuracion.php?marca=$marca_id"],
  ['key'=>'facturacion', 'ic'=>'wallet',  'lb'=>'Facturación',  'hr'=>"$BASE/precios.php?marca=$marca_id"],
  ['key'=>'soporte',     'ic'=>'chat',    'lb'=>'Soporte',      'hr'=>"$BASE/soporte.php?marca=$marca_id"],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= $h(($page_title ?? 'Panel') . ' · ' . $marca['nombre_negocio']) ?> — Encuéntralo</title>
<link rel="icon" type="image/svg+xml" href="/crecer/assets/brand/crecer-mark.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Anton&family=Poppins:wght@600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="/crecer/assets/encuentralo-ui.css?v=9" rel="stylesheet">
</head>
<body>
<div class="layout">
  <aside class="side" id="side">
    <a class="sbrand" href="<?= $BASE ?>/index.php?marca=<?= $marca_id ?>" style="text-decoration:none;color:inherit"><img src="/crecer/assets/brand/crecer-mark.svg" alt="Inicio"><b>encuéntralo <span style="color:var(--teal)">crecer</span></b></a>
    <nav>
      <?php foreach ($nav as $n): ?>
        <a href="<?= $n['hr'] ?>" class="<?= $n['key']===$active?'on':'' ?>">
          <?= ico($n['ic']) ?><?= $n['lb'] ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <?php
      $mis_negocios = $pdo->prepare("SELECT id, nombre_negocio FROM crecer_marca WHERE usuario_id = ? ORDER BY id");
      $mis_negocios->execute([(int)$marca['usuario_id']]);
      $mis_negocios = $mis_negocios->fetchAll();
    ?>
    <div class="who">
      <div class="av"><?= $h(mb_strtoupper(mb_substr($marca['nombre_negocio'],0,1))) ?></div>
      <div style="flex:1;min-width:0">
        <?php if (count($mis_negocios) > 1): ?>
          <select onchange="location.href='?marca='+this.value"
            style="font-family:inherit;font-weight:700;font-size:13.5px;color:var(--tinta);border:1px solid var(--line);border-radius:9px;padding:5px 7px;max-width:150px;background:#fff;cursor:pointer">
            <?php foreach ($mis_negocios as $mn): ?>
              <option value="<?= $mn['id'] ?>" <?= $mn['id']==$marca_id?'selected':'' ?>><?= $h($mn['nombre_negocio']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($plan): ?><div class="tag"><?= ico('leaf') ?> <?= $h($plan_etq) ?> · cambia negocio ↑</div>
          <?php else: ?><div class="tag"><a href="<?= $BASE ?>/precios.php?marca=<?= $marca_id ?>" style="color:#0d7a44;font-weight:700;text-decoration:none"><?= ico('bolt') ?> Activar plan</a> · cambia negocio ↑</div><?php endif; ?>
        <?php else: ?>
          <div class="nm"><?= $h($marca['nombre_negocio']) ?></div>
          <?php if ($plan): ?><div class="tag"><?= ico('leaf') ?> <?= $h($plan_etq) ?></div>
          <?php else: ?><div class="tag"><a href="<?= $BASE ?>/precios.php?marca=<?= $marca_id ?>" style="color:#0d7a44;font-weight:700;text-decoration:none"><?= ico('bolt') ?> Activar plan</a></div><?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <nav class="nav-perfil" style="margin-top:10px;padding-top:10px;border-top:1px solid var(--line)">
      <?php foreach ($nav_perfil as $n): ?>
        <a href="<?= $n['hr'] ?>" class="<?= $n['key']===$active?'on':'' ?>">
          <?= ico($n['ic']) ?><?= $n['lb'] ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <?php if ($es_admin): ?>
      <a href="<?= $BASE ?>/admin.php" style="display:flex;align-items:center;gap:10px;padding:10px 12px;margin-top:6px;border-radius:12px;text-decoration:none;color:#fff;background:var(--tinta);font-weight:800;font-size:13.5px"><?= ico('settings') ?> Centro de Operaciones</a>
    <?php endif; ?>
    <a href="/crecer/logout.php" style="display:flex;align-items:center;gap:10px;padding:9px 12px;margin-top:6px;border-radius:12px;text-decoration:none;color:var(--muted);font-weight:600;font-size:13.5px">Salir</a>
  </aside>
  <div class="backdrop" id="bd"></div>

  <div class="main">
    <div class="ptop">
      <a href="<?= $BASE ?>/index.php?marca=<?= $marca_id ?>" style="display:flex;align-items:center;gap:8px;text-decoration:none;color:inherit">
        <img src="/crecer/assets/brand/crecer-mark.svg" alt="Inicio"><b>encuéntralo <span style="color:var(--teal)">crecer</span></b></a>
      <button id="burger" aria-label="Perfil y ajustes"
        style="margin-left:auto;width:38px;height:38px;border-radius:50%;border:0;cursor:pointer;
        background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff;font-weight:800;font-size:15px;
        display:grid;place-items:center;font-family:inherit;flex:none">
        <?= $h(mb_strtoupper(mb_substr($marca['nombre_negocio'],0,1))) ?>
      </button>
    </div>
    <div class="content">
    <?php if (function_exists('activacion_de_prueba') && activacion_de_prueba($u_actual['email'] ?? null)): ?>
      <div style="background:#fff4d6;border:1px solid #f2d488;color:#8a5a00;border-radius:10px;padding:8px 13px;margin-bottom:14px;font-size:12.5px;font-weight:700">MODO PRUEBA — esta cuenta activa sin cobro (Stripe en bypass).</div>
    <?php endif; ?>
    <?php if (!empty($viendo_como_admin)): ?>
      <div style="background:#140a16;color:#fff;padding:11px 16px;border-radius:12px;margin-bottom:16px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:13.5px">
        <?= ico('eye') ?> Estás viendo como <b>admin</b> el negocio de <b><?= $h($marca['nombre_negocio']) ?></b>
        <a href="<?= $BASE ?>/admin.php" style="margin-left:auto;color:#ffcaa8;font-weight:800;text-decoration:none">← Volver a Operaciones</a>
      </div>
    <?php endif; ?>
