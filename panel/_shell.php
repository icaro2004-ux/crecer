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
// ══════════════════════════════════════════════════════════════════════
//  EL MENÚ — cinco conceptos, y el resto son herramientas.
//
//  El cliente no tiene que entender la arquitectura de Crecer. Tiene que
//  entender cinco cosas: dónde está el resumen (Inicio), qué persigue (Tu
//  Meta), qué va a salir (Calendario), qué pasó (Resultados) y quién es su
//  negocio (Mi negocio). Todo lo demás son herramientas que se usan a ratos,
//  y por eso van debajo y agrupadas.
//
//  NO SE BORRA NADA. Las rutas que salen del menú siguen existiendo, con su
//  URL y sus permisos: lo que se retira es la invitación a entrar por ahí.
//  Una lista plana de trece destinos no dice cuál importa, y el dueño acaba
//  entrando por donde recuerda en vez de por donde debe.
//
//  `bot` = este destino YA está en la barra de abajo del móvil. Le pone
//  `.dup`, que lo esconde SOLO en el teléfono. Marcar `bot` en algo que NO
//  está en la barra lo deja inalcanzable desde el móvil — le pasó a «Tus
//  Posts» y por eso esto se comprueba en una prueba, no de memoria.
// ══════════════════════════════════════════════════════════════════════
$crear_url_shell = (defined('CRECER_CREAR_UNIFICADO') && CRECER_CREAR_UNIFICADO)
    ? "$BASE/propuestas.php?marca=$marca_id&crear=1"
    : "$BASE/aprobar2.php?marca=$marca_id&crear=1";

$nav_grupos = [
  //  EL CIRCUITO DIARIO. Tu Meta gobierna: el corillo trabaja PARA ese
  //  número, no para llenar un calendario.
  ['t' => '', 'items' => [
    ['key'=>'inicio',    'ic'=>'home',    'lb'=>t('Inicio'),     'bot'=>1, 'hr'=>"$BASE/index.php?marca=$marca_id"],
    ['key'=>'meta',      'ic'=>'compass', 'lb'=>t('Tu Meta'),    'bot'=>1, 'hr'=>"$BASE/meta.php?marca=$marca_id"],
    ['key'=>'calendario','ic'=>'calendar','lb'=>t('Calendario'), 'bot'=>1, 'hr'=>"$BASE/calendario.php?marca=$marca_id"],
    ['key'=>'resultados','ic'=>'chart',   'lb'=>t('Resultados'), 'bot'=>1, 'hr'=>"$BASE/resultados.php?marca=$marca_id"],
  ]],
  //  LA HERRAMIENTA MANUAL Y LO QUE PRODUCE. Crear no compite con la Meta:
  //  es lo que el dueño usa cuando quiere meter mano él.
  ['t' => t('Crear y contenido'), 'items' => [
    ['key'=>'crear',     'ic'=>'pen',     'lb'=>t('Crear'),      'hr'=>$crear_url_shell],
    ['key'=>'contenido', 'ic'=>'list',    'lb'=>t('Tus Posts'),  'hr'=>"$BASE/propuestas.php?marca=$marca_id"],
    ['key'=>'biblioteca','ic'=>'image',   'lb'=>t('Biblioteca'), 'hr'=>"$BASE/biblioteca.php?marca=$marca_id"],
    ['key'=>'reels',     'ic'=>'camera',  'lb'=>t('Reels'),      'hr'=>"$BASE/reels.php?marca=$marca_id"],
  ]],
  //  QUIÉN ES EL NEGOCIO. «Mi negocio» es la puerta: dentro están la voz, la
  //  identidad, el logo y lo que el corillo ha aprendido. Los formularios que
  //  ya existían (marca, configuración) se abren DESDE ahí — no compiten aquí
  //  con nombres que el dueño tendría que aprenderse.
  ['t' => t('Mi negocio'), 'items' => [
    ['key'=>'negocio',   'ic'=>'genoma',  'lb'=>t('Mi negocio'),         'hr'=>"$BASE/genoma.php?marca=$marca_id"],
    ['key'=>'equipo',    'ic'=>'users',   'lb'=>t('Mi equipo'),          'hr'=>"$BASE/equipo.php?marca=$marca_id"],
    ['key'=>'conectar',  'ic'=>'bolt',    'lb'=>t('Canales y conexiones'),'hr'=>"$BASE/conectar.php?marca=$marca_id"],
  ]],
];

// Perfil / ajustes (secundario, abajo): Mi marca (config de marca/voz), config, facturación, soporte.
$nav_perfil = [
  //  «Mi marca» subió al grupo «Mi negocio»: es identidad, no ajustes. Aquí
  //  abajo se quedaba con Facturación y Soporte, y aparecer en los dos sitios
  //  sería una entrada duplicada a la misma página.
  ['key'=>'config',      'ic'=>'settings','lb'=>t('Configuración'),'hr'=>"$BASE/configuracion.php?marca=$marca_id"],
  ['key'=>'facturacion', 'ic'=>'wallet',  'lb'=>t('Facturación'),  'hr'=>"$BASE/precios.php?marca=$marca_id"],
  ['key'=>'soporte',     'ic'=>'chat',    'lb'=>t('Soporte'),      'hr'=>"$BASE/soporte.php?marca=$marca_id"],
];

/*  «AQUÍ ESTÁS», TAMBIÉN PARA QUIEN NO VE LA PANTALLA.
 *
 *  El menú marcaba la entrada de turno con la clase `on` —color y nada más—,
 *  así que un lector de pantalla no tenía forma de saber en qué sección está.
 *  Y en escritorio era peor: la barra de abajo, que sí lleva `aria-current`,
 *  está en `display:none` a partir de 861px, con lo que la única marca
 *  semántica de la página vivía en algo que nadie ve.
 *
 *  No hay riesgo de marcar dos veces: los cuatro destinos que el menú repite
 *  llevan `.dup` y se esconden en móvil, y la barra de abajo se esconde en
 *  escritorio. A cualquier ancho hay exactamente una entrada visible marcada.
 */
$aq = fn(string $k): string => (($active ?? '') === $k) ? ' aria-current="page"' : '';
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
  /*  TÍTULOS DE GRUPO: pequeños, callados y sin acordeón. Un menú que hay que
      desplegar para ver qué tiene es un menú que se usa peor — sobre todo en
      escritorio, donde no sobra el sitio y sí sobra la paciencia. */
  /*  La vuelta: discreta, arriba del todo y de 44px — se toca con el pulgar
      sin apuntar. */
  .volver-a{display:inline-flex;align-items:center;gap:6px;min-height:44px;padding:0 4px;
    margin:8px 0 -4px;color:var(--muted);font-size:14px;font-weight:700;text-decoration:none}
  .volver-a:hover{color:var(--tinta)}
  .volver-a svg{width:17px;height:17px;transform:rotate(180deg)}
  .side-gt{font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;
    color:var(--muted);opacity:.72;margin:16px 14px 6px;user-select:none}
  .side nav > .side-gt:first-child{margin-top:6px}
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
    <a class="sbrand" href="<?= $BASE ?>/index.php?marca=<?= $marca_id ?>" style="text-decoration:none;color:inherit"><img src="/crecer/assets/brand/crecer-icon.png" alt="<?= $h(t('Inicio')) ?>"><b style="display:inline-flex;flex-direction:column;line-height:1;gap:0"><span style="color:var(--teal)">Crecer</span><span style="font-size:.5em;font-weight:500;color:var(--muted);letter-spacing:.02em;margin-top:1px">by Encuéntralo</span></b></a>
    <nav>
      <?php foreach ($nav_grupos as $g): ?>
        <?php if ($g['t'] !== ''): ?><div class="side-gt"><?= $h($g['t']) ?></div><?php endif; ?>
        <?php foreach ($g['items'] as $n): ?>
          <?php /*  .dup = ya está en la barra de abajo → se esconde SOLO en móvil.
                    En escritorio no hay barra, así que ahí SÍ se ven.  */ ?>
          <a href="<?= $n['hr'] ?>" class="<?= $n['key']===$active?'on ':'' ?><?= !empty($n['bot'])?'dup':'' ?>"<?= $aq($n['key']) ?>>
            <?= ico($n['ic']) ?><?= $n['lb'] ?>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>

      <?php /*  OPERACIÓN. Lo que no es el circuito diario ni contenido: se usa
                cuando hace falta, y por eso va al final. WhatsApp solo aparece
                donde de verdad está conectado — ofrecer una puerta que no lleva
                a ningún sitio es peor que no ofrecerla.  */ ?>
      <div class="side-gt"><?= $h(t('Operación')) ?></div>
      <a href="<?= $BASE ?>/ordenes.php?marca=<?= $marca_id ?>" class="<?= ($active??'')==='ordenes'?'on':'' ?>"<?= $aq('ordenes') ?>>
        <?= ico('qr') ?><?= $h(t('Órdenes')) ?>
      </a>
      <?php if (defined('WHATSAPP_MARCA_ID') && (int)WHATSAPP_MARCA_ID === $marca_id): ?>
      <a href="<?= $BASE ?>/whatsapp.php?marca=<?= $marca_id ?>" class="<?= ($active??'')==='whatsapp'?'on':'' ?>"<?= $aq('whatsapp') ?>>
        <?= ico('phone') ?>WhatsApp
      </a>
      <?php endif; ?>
      <a href="<?= $BASE ?>/finanzas.php?marca=<?= $marca_id ?>" class="<?= ($active??'')==='finanzas'?'on':'' ?>"<?= $aq('finanzas') ?>>
        <?= ico('dollar') ?><?= $h(t('Finanzas')) ?>
      </a>
      <a href="<?= $BASE ?>/notificaciones_centro.php?marca=<?= $marca_id ?>" class="<?= ($active??'')==='notif'?'on':'' ?>"<?= $aq('notif') ?> style="position:relative">
        <?= ico('bell-solid') ?><?= $h(t('Notificaciones')) ?>
        <?php if ($notif_nl > 0): ?><span style="position:absolute;top:50%;right:12px;transform:translateY(-50%);background:var(--magenta);color:#fff;font-size:11px;font-weight:800;min-width:19px;height:19px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;padding:0 5px"><?= $notif_nl > 9 ? '9+' : $notif_nl ?></span><?php endif; ?>
      </a>

      <?php /*  MÁS. Solo lo que tiene lector propio y algo que el resto no
                hace. La Sala es una conversación con el corillo — no es un
                duplicado de nada.  */ ?>
      <div class="side-gt"><?= $h(t('Más')) ?></div>
      <a href="<?= $BASE ?>/sala.php?marca=<?= $marca_id ?>" class="<?= ($active??'')==='sala'?'on':'' ?>"<?= $aq('sala') ?>>
        <?= ico('sparkles') ?><?= $h(t('La Sala')) ?>
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
          <?php if ($plan): ?><div class="tag"><?= ico('leaf') ?> <?= $h($plan_etq) ?> <?= $h(t('· cambia negocio ↑')) ?></div>
          <?php else: ?><div class="tag"><a href="<?= $BASE ?>/precios.php?marca=<?= $marca_id ?>" style="color:#0d7a44;font-weight:700;text-decoration:none"><?= ico('bolt') ?> <?= $h(t('Activar plan')) ?></a> <?= $h(t('· cambia negocio ↑')) ?></div><?php endif; ?>
        <?php else: ?>
          <div class="nm"><?= $h($marca['nombre_negocio']) ?></div>
          <?php if ($plan): ?><div class="tag"><?= ico('leaf') ?> <?= $h($plan_etq) ?></div>
          <?php else: ?><div class="tag"><a href="<?= $BASE ?>/precios.php?marca=<?= $marca_id ?>" style="color:#0d7a44;font-weight:700;text-decoration:none"><?= ico('bolt') ?> <?= $h(t('Activar plan')) ?></a></div><?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <nav class="nav-perfil" style="margin-top:10px;padding-top:10px;border-top:1px solid var(--line)">
      <?php foreach ($nav_perfil as $n): ?>
        <a href="<?= $n['hr'] ?>" class="<?= $n['key']===$active?'on':'' ?>"<?= $aq($n['key']) ?>>
          <?= ico($n['ic']) ?><?= $n['lb'] ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <?php if ($es_admin): ?>
      <a href="<?= $BASE ?>/admin.php" style="display:flex;align-items:center;gap:10px;padding:10px 12px;margin-top:6px;border-radius:12px;text-decoration:none;color:var(--tinta);background:var(--crema-2);border:1px solid var(--line);font-weight:700;font-size:13.5px"><?= ico('settings') ?> <?= $h(t('Centro de Operaciones')) ?></a>
    <?php endif; ?>
    <a href="/crecer/logout.php" style="display:flex;align-items:center;gap:10px;padding:9px 12px;margin-top:6px;border-radius:12px;text-decoration:none;color:var(--muted);font-weight:600;font-size:13.5px"><?= $h(t('Salir')) ?></a>
    <?php /* Idioma de la interfaz. Va junto a lo legal, no en la barra de arriba:
             el dueño boricua lo cambia una vez o ninguna, y el espacio de arriba
             es del negocio. Lo que escribe la IA no cambia de idioma nunca. */ ?>
    <div class="side-lang" style="margin-top:10px;padding-left:12px;display:flex;align-items:center;gap:8px">
      <span style="color:var(--muted);font-size:11.5px;font-weight:600"><?= $h(t('Idioma de Crecer')) ?></span>
      <?= i18n_toggle_html() ?>
    </div>
    <div class="side-legal" style="margin-top:12px;padding-top:12px;border-top:1px solid var(--line);display:flex;flex-wrap:wrap;gap:4px 12px;padding-left:12px;padding-right:12px">
      <a href="/crecer/privacidad.php" style="color:var(--muted);font-size:12px;text-decoration:none"><?= $h(t('Privacidad')) ?></a>
      <a href="/crecer/terminos.php" style="color:var(--muted);font-size:12px;text-decoration:none"><?= $h(t('Términos')) ?></a>
      <!-- "Eliminar datos" a secas parecía un gatillo que te vuela la cuenta. Es una
           página que EXPLICA cómo pedirlo (Meta exige que esa URL sea visible). -->
      <a href="/crecer/eliminar-datos.php" style="color:var(--muted);font-size:12px;text-decoration:none"><?= $h(t('Cómo eliminar mis datos')) ?></a>
      <span style="color:var(--muted);font-size:11.5px;width:100%;margin-top:4px">© Encuéntralo · Crecer</span>
    </div>
  </aside>
  <div class="backdrop" id="bd"></div>

  <div class="main">
    <?php
      /*  LA VUELTA. Un editor abierto desde «Mi negocio» tiene que poder
          volver ahí, o el dueño acaba en una página suelta sin saber cómo
          salió. El destino NO viaja en la URL: `volver` es una etiqueta
          corta y el destino se decide aquí contra una lista. Aceptar una URL
          de vuelta es como se construye un redirect abierto — y con eso se
          saca a alguien de Crecer creyendo que sigue dentro.  */
      $vol = (string)($_GET['volver'] ?? '');
      $VOLVER = [
          'negocio' => ['/crecer/panel/genoma.php', t('Mi negocio')],
      ];
      $v_dest = $VOLVER[$vol] ?? null;
    ?>
    <?php if ($v_dest && ($active ?? '') !== 'negocio'): ?>
      <a class="volver-a" href="<?= $h($v_dest[0]) ?>?marca=<?= $marca_id ?>">
        <?= ico('chev-der') ?><?= $h(t('Volver a %s', $v_dest[1])) ?></a>
    <?php endif; ?>
    <div class="ptop">
      <a href="<?= $BASE ?>/index.php?marca=<?= $marca_id ?>" style="display:flex;align-items:center;gap:8px;text-decoration:none;color:inherit">
        <img src="/crecer/assets/brand/crecer-icon.png" alt="<?= $h(t('Inicio')) ?>"><b style="display:inline-flex;flex-direction:column;line-height:1;gap:0"><span style="color:var(--teal)">Crecer</span><span style="font-size:.5em;font-weight:500;color:var(--muted);letter-spacing:.02em;margin-top:1px">by Encuéntralo</span></b></a>
      <a href="<?= $BASE ?>/finanzas.php?marca=<?= $marca_id ?>" aria-label="<?= $h(t('Finanzas')) ?>" style="margin-left:auto;margin-right:16px;display:flex;align-items:center;text-decoration:none;font-size:22px;line-height:1;color:var(--teal)"><?= ico('dollar') ?></a>
      <a href="<?= $BASE ?>/notificaciones_centro.php?marca=<?= $marca_id ?>" aria-label="<?= $h(t('Notificaciones')) ?>" style="position:relative;margin-right:6px;display:flex;align-items:center;text-decoration:none;font-size:22px;line-height:1;color:var(--teal)"><?= ico('bell-solid') ?><?php if ($notif_nl > 0): ?><span style="position:absolute;top:-5px;right:-7px;background:var(--magenta);color:#fff;font-size:10px;font-weight:800;min-width:16px;height:16px;border-radius:999px;display:flex;align-items:center;justify-content:center;padding:0 4px"><?= $notif_nl > 9 ? '9+' : $notif_nl ?></span><?php endif; ?></a>
      <button id="burger" class="ptop-menu" aria-label="<?= $h(t('Abrir menú')) ?>">
        <span class="bars" aria-hidden="true"><span></span><span></span><span></span></span>
        <span class="av"><?= $h(mb_strtoupper(mb_substr($marca['nombre_negocio'],0,1))) ?></span>
      </button>
    </div>
    <div class="content">
    <?php if (function_exists('activacion_de_prueba') && activacion_de_prueba($u_actual['email'] ?? null)): ?>
      <div style="color:var(--muted);font-size:11.5px;margin-bottom:8px;letter-spacing:.02em"><?= $h(t('Modo prueba · cuenta activa sin cobro')) ?></div>
    <?php endif; ?>
    <?php if (!empty($viendo_como_admin)): ?>
      <div style="background:#140a16;color:#fff;padding:11px 16px;border-radius:12px;margin-bottom:16px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:13.5px">
        <?= ico('eye') ?> <?= $h(t('Estás viendo como')) ?> <b>admin</b> <?= $h(t('el negocio de')) ?> <b><?= $h($marca['nombre_negocio']) ?></b>
        <a href="<?= $BASE ?>/admin.php" style="margin-left:auto;color:#ffcaa8;font-weight:800;text-decoration:none"><?= $h(t('← Volver a Operaciones')) ?></a>
      </div>
    <?php endif; ?>
