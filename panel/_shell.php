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
require_once __DIR__ . '/../includes/notif.php';
$notif_nl = function_exists('notif_no_leidas') ? notif_no_leidas($pdo, $marca_id) : 0;
// Navegación PRINCIPAL — solo el loop del producto (contenido para redes).
// Gráficas, Clientela, Cuentas, Analítica y Evidencia salieron del menú
// (siguen accesibles por URL / por el perfil; reversible).
// ÓRDENES VOLVIÓ (2026-08-08, decisión de Manuel): el link público + QR de
// órdenes es un ASSET del negocio, no exceso — no se esconde.
// `bot` = este destino YA está en la barra de abajo del móvil. Solo esos se
// esconden del drawer (para no repetirlos); todo lo demás TIENE que verse ahí,
// o queda inalcanzable desde el teléfono.
// (Bug 2026-08-12: el CSS escondía todo el menú menos Biblioteca — se escribió
//  cuando había 4 destinos y la barra los cubría todos. Con 9 destinos, Reels,
//  Tu equipo, El Genoma y Calendario dejaron de existir en móvil.)
$nav = [
  ['key'=>'inicio',    'ic'=>'home',    'lb'=>'Inicio',     'bot'=>1, 'hr'=>"$BASE/index.php?marca=$marca_id"],
  // LA META (2026-08-12): el norte del negocio. Va arriba porque gobierna todo
  // lo demás — el corillo trabaja PARA esto, no para llenar el calendario.
  ['key'=>'meta',      'ic'=>'compass', 'lb'=>'Tu Meta',    'bot'=>1, 'hr'=>"$BASE/meta.php?marca=$marca_id"],
  ['key'=>'contenido', 'ic'=>'list', 'lb'=>'Tus Posts',  'bot'=>1, 'hr'=>"$BASE/propuestas.php?marca=$marca_id"],
  ['key'=>'sala',      'ic'=>'sparkles','lb'=>'La Sala',    'bot'=>1, 'hr'=>"$BASE/sala.php?marca=$marca_id"],
  ['key'=>'reels',     'ic'=>'camera',  'lb'=>'Reels',      'hr'=>"$BASE/reels.php?marca=$marca_id"],
  ['key'=>'equipo',    'ic'=>'users',   'lb'=>'Tu equipo',  'hr'=>"$BASE/equipo.php?marca=$marca_id"],
  ['key'=>'genoma',    'ic'=>'genoma',  'lb'=>'El Genoma',  'hr'=>"$BASE/genoma.php?marca=$marca_id"],
  ['key'=>'resultados','ic'=>'chart',   'lb'=>'Resultados', 'bot'=>1, 'hr'=>"$BASE/resultados.php?marca=$marca_id"],
  ['key'=>'biblioteca','ic'=>'image',   'lb'=>'Biblioteca', 'hr'=>"$BASE/biblioteca.php?marca=$marca_id"],
];
// Perfil / ajustes (secundario, abajo): Mi marca (config de marca/voz), config, facturación, soporte.
$nav_perfil = [
  ['key'=>'marca',       'ic'=>'palette', 'lb'=>'Mi marca',     'hr'=>"$BASE/marca.php?marca=$marca_id"],
  ['key'=>'config',      'ic'=>'settings','lb'=>'Configuración','hr'=>"$BASE/configuracion.php?marca=$marca_id"],
  ['key'=>'facturacion', 'ic'=>'wallet',  'lb'=>'Facturación',  'hr'=>"$BASE/precios.php?marca=$marca_id"],
  ['key'=>'soporte',     'ic'=>'chat',    'lb'=>'Soporte',      'hr'=>"$BASE/soporte.php?marca=$marca_id"],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php require_once __DIR__ . '/../includes/meta_pixel.php'; meta_pixel_head_panel(); ?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= $h(($page_title ?? 'Panel') . ' · ' . $marca['nombre_negocio']) ?> — Encuéntralo</title>
<link rel="icon" type="image/png" href="/crecer/assets/brand/crecer-icon.png">
<link rel="apple-touch-icon" href="/crecer/assets/brand/crecer-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="/crecer/assets/encuentralo-ui.css?v=<?= ASSET_VER ?>" rel="stylesheet">
<style>
  /* Fondo blanco unificado (el bottom-nav lo renderiza _shell_foot.php, uno solo) */
  .main{background:#fff}
  /* El menú creció (Crear, Órdenes, WhatsApp…) y en desktop el sidebar es
     height:100vh SIN overflow → se cortaba. Scroll propio, discreto. */
  .side{overflow-y:auto;scrollbar-width:thin}
  /* Logo del app 25% más grande para que el ícono resalte (override del CSS compartido) */
  .side .sbrand img{height:38px}
  .ptop img{height:35px}
  @media(max-width:860px){
    .botnav a.on{color:var(--magenta)}
    /* Botón central "Crear" (estilo del arte): ícono elevado en teal */
    .botnav a.bn-crear{gap:5px}
    .botnav a.bn-crear .ci{display:grid;place-items:center;width:46px;height:46px;border-radius:16px;margin-top:-16px;
      background:linear-gradient(135deg,var(--teal),var(--teal-700,#00827e));color:#fff;border:3px solid #fff;
      box-shadow:0 10px 22px -6px rgba(0,164,159,.55)}
    .botnav a.bn-crear .ci .ic{width:23px;height:23px;color:#fff}
    .botnav a.bn-crear .cl{font-weight:800}
    .botnav a.bn-crear.on{color:var(--teal)}
  }
</style>
</head>
<body>
<div class="layout">
  <aside class="side" id="side">
    <a class="sbrand" href="<?= $BASE ?>/index.php?marca=<?= $marca_id ?>" style="text-decoration:none;color:inherit"><img src="/crecer/assets/brand/crecer-icon.png" alt="Inicio"><b style="display:inline-flex;flex-direction:column;line-height:1;gap:0"><span style="color:var(--teal)">Crecer</span><span style="font-size:.5em;font-weight:500;color:var(--muted);letter-spacing:.02em;margin-top:1px">by Encuéntralo</span></b></a>
    <?php
      // CREAR con paridad desktop: el móvil tiene su botón central en el bottom
      // nav; el sidebar tiene ESTE. Abre el wizard directo (?crear=1) donde toque
      // según el flag (mismo criterio que propuestas.php).
      $crear_url_shell = (defined('CRECER_CREAR_UNIFICADO') && CRECER_CREAR_UNIFICADO)
          ? "$BASE/propuestas.php?marca=$marca_id&crear=1"
          : "$BASE/aprobar2.php?marca=$marca_id&crear=1";
    ?>
    <a href="<?= $crear_url_shell ?>" class="side-crear" style="display:flex;align-items:center;justify-content:center;gap:8px;margin:10px 0 6px;padding:12px 14px;border-radius:14px;text-decoration:none;color:#fff;font-weight:800;font-size:14.5px;background:linear-gradient(135deg,var(--teal),var(--teal-700,#00827e));box-shadow:0 8px 18px -8px rgba(0,164,159,.55)"><?= ico('pen') ?> Crear</a>
    <nav>
      <?php foreach ($nav as $n): ?>
        <?php /* .dup = ya está en la barra de abajo → se esconde SOLO en móvil */ ?>
        <a href="<?= $n['hr'] ?>" class="<?= $n['key']===$active?'on ':'' ?><?= !empty($n['bot'])?'dup':'' ?>">
          <?= ico($n['ic']) ?><?= $n['lb'] ?>
        </a>
      <?php endforeach; ?>
      <a href="<?= $BASE ?>/calendario.php?marca=<?= $marca_id ?>" class="<?= ($active??'')==='calendario'?'on':'' ?>">
        <?= ico('calendar') ?>Calendario
      </a>
      <a href="<?= $BASE ?>/ordenes.php?marca=<?= $marca_id ?>" class="<?= ($active??'')==='ordenes'?'on':'' ?>">
        <?= ico('qr') ?>Órdenes
      </a>
      <?php if (defined('WHATSAPP_MARCA_ID') && (int)WHATSAPP_MARCA_ID === $marca_id): ?>
      <a href="<?= $BASE ?>/whatsapp.php?marca=<?= $marca_id ?>" class="<?= ($active??'')==='whatsapp'?'on':'' ?>">
        <?= ico('phone') ?>WhatsApp
      </a>
      <?php endif; ?>
      <a href="<?= $BASE ?>/finanzas.php?marca=<?= $marca_id ?>" class="<?= ($active??'')==='finanzas'?'on':'' ?>">
        <?= ico('dollar') ?>Finanzas
      </a>
      <a href="<?= $BASE ?>/notificaciones_centro.php?marca=<?= $marca_id ?>" class="<?= ($active??'')==='notif'?'on':'' ?>" style="position:relative">
        <?= ico('bell-solid') ?>Notificaciones
        <?php if ($notif_nl > 0): ?><span style="position:absolute;top:50%;right:12px;transform:translateY(-50%);background:var(--magenta);color:#fff;font-size:11px;font-weight:800;min-width:19px;height:19px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;padding:0 5px"><?= $notif_nl > 9 ? '9+' : $notif_nl ?></span><?php endif; ?>
      </a>
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
      <a href="<?= $BASE ?>/admin.php" style="display:flex;align-items:center;gap:10px;padding:10px 12px;margin-top:6px;border-radius:12px;text-decoration:none;color:var(--tinta);background:var(--crema-2);border:1px solid var(--line);font-weight:700;font-size:13.5px"><?= ico('settings') ?> Centro de Operaciones</a>
    <?php endif; ?>
    <a href="/crecer/logout.php" style="display:flex;align-items:center;gap:10px;padding:9px 12px;margin-top:6px;border-radius:12px;text-decoration:none;color:var(--muted);font-weight:600;font-size:13.5px">Salir</a>
    <div class="side-legal" style="margin-top:12px;padding-top:12px;border-top:1px solid var(--line);display:flex;flex-wrap:wrap;gap:4px 12px;padding-left:12px;padding-right:12px">
      <a href="/crecer/privacidad.php" style="color:var(--muted);font-size:12px;text-decoration:none">Privacidad</a>
      <a href="/crecer/terminos.php" style="color:var(--muted);font-size:12px;text-decoration:none">Términos</a>
      <!-- "Eliminar datos" a secas parecía un gatillo que te vuela la cuenta. Es una
           página que EXPLICA cómo pedirlo (Meta exige que esa URL sea visible). -->
      <a href="/crecer/eliminar-datos.php" style="color:var(--muted);font-size:12px;text-decoration:none">Cómo eliminar mis datos</a>
      <span style="color:var(--muted);font-size:11.5px;width:100%;margin-top:4px">© Encuéntralo · Crecer</span>
    </div>
  </aside>
  <div class="backdrop" id="bd"></div>

  <div class="main">
    <div class="ptop">
      <a href="<?= $BASE ?>/index.php?marca=<?= $marca_id ?>" style="display:flex;align-items:center;gap:8px;text-decoration:none;color:inherit">
        <img src="/crecer/assets/brand/crecer-icon.png" alt="Inicio"><b style="display:inline-flex;flex-direction:column;line-height:1;gap:0"><span style="color:var(--teal)">Crecer</span><span style="font-size:.5em;font-weight:500;color:var(--muted);letter-spacing:.02em;margin-top:1px">by Encuéntralo</span></b></a>
      <a href="<?= $BASE ?>/finanzas.php?marca=<?= $marca_id ?>" aria-label="Finanzas" style="margin-left:auto;margin-right:16px;display:flex;align-items:center;text-decoration:none;font-size:22px;line-height:1;color:var(--teal)"><?= ico('dollar') ?></a>
      <a href="<?= $BASE ?>/notificaciones_centro.php?marca=<?= $marca_id ?>" aria-label="Notificaciones" style="position:relative;margin-right:6px;display:flex;align-items:center;text-decoration:none;font-size:22px;line-height:1;color:var(--teal)"><?= ico('bell-solid') ?><?php if ($notif_nl > 0): ?><span style="position:absolute;top:-5px;right:-7px;background:var(--magenta);color:#fff;font-size:10px;font-weight:800;min-width:16px;height:16px;border-radius:999px;display:flex;align-items:center;justify-content:center;padding:0 4px"><?= $notif_nl > 9 ? '9+' : $notif_nl ?></span><?php endif; ?></a>
      <button id="burger" class="ptop-menu" aria-label="Abrir menú">
        <span class="bars" aria-hidden="true"><span></span><span></span><span></span></span>
        <span class="av"><?= $h(mb_strtoupper(mb_substr($marca['nombre_negocio'],0,1))) ?></span>
      </button>
    </div>
    <div class="content">
    <?php if (function_exists('activacion_de_prueba') && activacion_de_prueba($u_actual['email'] ?? null)): ?>
      <div style="color:var(--muted);font-size:11.5px;margin-bottom:8px;letter-spacing:.02em">Modo prueba · cuenta activa sin cobro</div>
    <?php endif; ?>
    <?php if (!empty($viendo_como_admin)): ?>
      <div style="background:#140a16;color:#fff;padding:11px 16px;border-radius:12px;margin-bottom:16px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:13.5px">
        <?= ico('eye') ?> Estás viendo como <b>admin</b> el negocio de <b><?= $h($marca['nombre_negocio']) ?></b>
        <a href="<?= $BASE ?>/admin.php" style="margin-left:auto;color:#ffcaa8;font-weight:800;text-decoration:none">← Volver a Operaciones</a>
      </div>
    <?php endif; ?>
