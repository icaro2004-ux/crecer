<?php
// ============================================================
//  CRECER — EL ESCENARIO DEL POST (Pantalla C del Gateway)
//  panel/gateway_post.php
//
//  El primer post NUNCA sale de la pantalla. La zona de acción evoluciona con
//  el estado: verlo → ajustar/aprobar → publicar (SMS + manual/redes) → venta.
//  Standalone (NO usa el shell del app: esto es el gateway, no el app).
//
//  Fase 1 (backbone): mostrar el post + aprobar + publicar (manual/redes) +
//  celebración. El carrusel de venta pleno llega en la Fase 4.
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/agentes.php';
require __DIR__ . '/../includes/suscripcion.php';
require __DIR__ . '/../includes/gateway.php';
requiere_login();

$usuario = usuario_actual($pdo);
if (!$usuario) { logout_usuario(); header('Location: /crecer/login.php?expirado=1'); exit; }
$USUARIO_ID = (int)$usuario['id'];
$marca = marca_del_usuario($pdo, $USUARIO_ID, isset($_GET['marca']) ? (int)$_GET['marca'] : null);

// ?gw=1 = modo PRUEBA: camina el gateway aunque la cuenta ya tenga acceso. PEGAJOSO
// en sesión para sobrevivir el rebote de conectar/OAuth (?gw=0 lo apaga).
if (($_GET['gw'] ?? '') === '1') $_SESSION['gw_test'] = 1;
elseif (($_GET['gw'] ?? '') === '0') unset($_SESSION['gw_test']);
$forzar = !empty($_SESSION['gw_test']);
$gwq = $forzar ? '&gw=1' : '';
// El router manda: si su estado NO es del escenario (sin marca, ya pagó, etc.),
// lo mando a donde de verdad le toca. Así nadie aterriza aquí fuera de lugar.
$estado = $marca ? gateway_estado($pdo, $usuario, $marca, $forzar) : GW_ENTREVISTA;
if ($estado !== GW_POST && $estado !== GW_VENTA) {
    if ($forzar) { header('Location: /crecer/panel/entrevista.php?gw=1'); exit; }
    gateway_redirigir($pdo, $usuario); exit;
}
$marca_id = (int)$marca['id'];

// El post del escenario: si es venta, el publicado; si no, el borrador/aprobado más reciente.
if ($estado === GW_VENTA) {
    $q = $pdo->prepare("SELECT * FROM crecer_contenido WHERE marca_id=? AND estado='publicado' ORDER BY id DESC LIMIT 1");
} else {
    // Cualquier post NO publicado (incluye 'fallido'/'publicando' → evita el loop de
    // redirección si una publicación a redes se cayó a mitad).
    $q = $pdo->prepare("SELECT * FROM crecer_contenido WHERE marca_id=? AND estado NOT IN ('publicado','rechazado') ORDER BY id DESC LIMIT 1");
}
$q->execute([$marca_id]);
$post = $q->fetch(PDO::FETCH_ASSOC);
if (!$post) { gateway_redirigir($pdo, $usuario); exit; }   // por si acaso: sin post, retoma el router
$post_id = (int)$post['id'];

// Gate del teléfono (igual que aprobar2): el free confirma su celular 1 vez para publicar.
$acceso_full = ($marca && marca_es_pagada($pdo, $marca_id))
    || (($usuario['rol'] ?? '') === 'admin')
    || (function_exists('activacion_de_prueba') && activacion_de_prueba($usuario['email'] ?? null));
$necesita_telefono = !$acceso_full && trim((string)($marca['telefono_verificado'] ?? '')) === '';

// ── Acciones AJAX del propio escenario (aprobar / ajustar el texto) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_ok()) { echo json_encode(['ok'=>false,'err'=>'Sesión expiró, recarga.']); exit; }
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'aprobar') {
        $pdo->prepare("UPDATE crecer_contenido SET estado='aprobado', updated_at=NOW() WHERE id=? AND marca_id=? AND estado='borrador'")
            ->execute([$post_id, $marca_id]);
        echo json_encode(['ok'=>true]); exit;
    }
    // Publicar MANUAL (el dueño lo subió a mano) → marcar publicado. Con gate SMS
    // (colectar teléfono), pero SIN exigir redes conectadas — por eso NO va por aprobar2.
    if ($accion === 'publicar_manual') {
        if ($necesita_telefono) { echo json_encode(['ok'=>false,'needs_phone'=>true]); exit; }
        $pdo->prepare("UPDATE crecer_contenido SET estado='publicado', publicado_at=NOW(), updated_at=NOW() WHERE id=? AND marca_id=? AND estado IN ('aprobado','borrador')")
            ->execute([$post_id, $marca_id]);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($accion === 'guardar_caption') {
        $cap = trim((string)($_POST['caption'] ?? ''));
        if ($cap !== '') $pdo->prepare("UPDATE crecer_contenido SET caption=?, updated_at=NOW() WHERE id=? AND marca_id=?")->execute([$cap, $post_id, $marca_id]);
        echo json_encode(['ok'=>true, 'caption'=>$cap]); exit;
    }
    // ✨ Otra sugerencia: la IA reescribe el copy desde cero (respeta el tono elegido).
    if ($accion === 'sugerir') {
        @set_time_limit(0);
        try { $r = redactar_pieza($pdo, $post_id); echo json_encode(['ok'=>true, 'caption'=>(string)($r['caption'] ?? '')], JSON_UNESCAPED_UNICODE); }
        catch (Throwable $e) { echo json_encode(['ok'=>false, 'err'=>'No pude sugerir otra ahora.']); }
        exit;
    }
    // 💬 Pedir un cambio: el dueño dice qué cambiar → la IA lo aplica sin perder su voz.
    if ($accion === 'pedir_cambio') {
        @set_time_limit(0);
        $nota = trim((string)($_POST['nota'] ?? ''));
        if ($nota === '') { echo json_encode(['ok'=>false,'err'=>'Escribe qué quieres cambiar.']); exit; }
        $cap_actual = (string)$pdo->query("SELECT caption FROM crecer_contenido WHERE id={$post_id}")->fetchColumn();
        try { $r = redactar_sugerido($pdo, $post_id, $nota, $cap_actual); echo json_encode(['ok'=>true, 'caption'=>(string)($r['caption'] ?? '')], JSON_UNESCAPED_UNICODE); }
        catch (Throwable $e) { echo json_encode(['ok'=>false, 'err'=>'No pude aplicar el cambio ahora.']); }
        exit;
    }
    // 🎨 Regenerar la IMAGEN. Motor Responses (background) → encola y el frontend hace
    // polling; si no, motor viejo síncrono. El dueño decide con/sin texto (motor viejo).
    if ($accion === 'regenerar_imagen') {
        @set_time_limit(0);
        require_once __DIR__ . '/../includes/img_responses.php';
        $con_txt = ($_POST['con_texto'] ?? '') === '1';
        $cap = (string)$pdo->query("SELECT caption FROM crecer_contenido WHERE id={$post_id}")->fetchColumn();
        if (img_resp_activo() && img_resp_encolar($pdo, $marca_id, $post_id, $cap, $con_txt) !== '') {
            echo json_encode(['ok'=>true, 'job'=>1]); exit;   // → el frontend consulta con poll_imagen
        }
        try {
            $g = generar_grafica($pdo, $marca_id, null, ['copy'=>$cap, 'con_texto'=>$con_txt, 'con_logo'=>false]);
            if (!empty($g['archivo'])) {
                $pdo->prepare("UPDATE crecer_contenido SET grafica_path=?, updated_at=NOW() WHERE id=? AND marca_id=?")->execute([$g['archivo'], $post_id, $marca_id]);
                echo json_encode(['ok'=>true, 'img'=>(string)$g['archivo']]); exit;
            }
            echo json_encode(['ok'=>false, 'err'=>'No se pudo regenerar.']); exit;
        } catch (Throwable $e) { echo json_encode(['ok'=>false, 'err'=>'No se pudo regenerar ahora.']); exit; }
    }
    // 🔁 Polling de la imagen en background (Responses): guarda al completar y devuelve el estado.
    if ($accion === 'poll_imagen') {
        require_once __DIR__ . '/../includes/img_responses.php';
        $r = img_resp_completar($pdo, $marca_id, $post_id);
        echo json_encode(['ok'=>true, 'estado'=>$r['estado'], 'img'=>$r['img']]); exit;
    }
    echo json_encode(['ok'=>false,'err'=>'Acción inválida.']); exit;
}

// ¿Redes conectadas? (para ofrecer "publicar en mis redes")
$redes_ok = false; $redes_conectadas = [];   // publicar SOLO a lo que de verdad esté conectado
try {
    $cx = $pdo->query("SELECT ig_user_id, fb_page_id FROM crecer_conexiones WHERE marca_id={$marca_id} AND estado='activa' LIMIT 1")->fetch();
    if ($cx) {
        if (!empty($cx['ig_user_id'])) $redes_conectadas[] = 'instagram';
        if (!empty($cx['fb_page_id'])) $redes_conectadas[] = 'facebook';
        $redes_ok = !empty($redes_conectadas);
    }
} catch (Throwable $e) {}

// Plan para la VENTA (precio real desde crecer_planes; el CTA suscribe de verdad).
$plan_venta = null;
try { $plan_venta = $pdo->query("SELECT nombre, slug, precio_mensual FROM crecer_planes WHERE slug='crecer' AND activo=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
if (!$plan_venta) $plan_venta = ['nombre'=>'Crecer', 'slug'=>'crecer', 'precio_mensual'=>39];

// "Ver mi publicación" → al ENLACE REAL del post; si no, al perfil de la red que SÍ
// tenga (nada de mandar siempre a IG cuando solo tiene FB).
$ver_url = '';
try {
    $pk = $pdo->prepare("SELECT permalink FROM crecer_publicaciones WHERE contenido_id=? AND permalink IS NOT NULL AND permalink<>'' ORDER BY id DESC LIMIT 1");
    $pk->execute([$post_id]);
    $ver_url = (string)($pk->fetchColumn() ?: '');
} catch (Throwable $e) {}
if ($ver_url === '') {
    try {
        $cx2 = $pdo->query("SELECT ig_username, fb_page_id FROM crecer_conexiones WHERE marca_id={$marca_id} AND estado='activa' LIMIT 1")->fetch();
        if ($cx2) {
            if (!empty($cx2['ig_username']))     $ver_url = 'https://instagram.com/' . rawurlencode((string)$cx2['ig_username']);
            elseif (!empty($cx2['fb_page_id']))  $ver_url = 'https://facebook.com/' . rawurlencode((string)$cx2['fb_page_id']);
        }
    } catch (Throwable $e) {}
}

$nombre  = trim((string)($marca['nombre_negocio'] ?? 'tu negocio'));
$caption = (string)($post['caption'] ?? '');
// Texto para COPIAR/publicar manual: el post gratis lleva la firma de Crecer (los pagados, limpios).
$caption_copiar = function_exists('firma_publicar') ? firma_publicar($pdo, $marca_id, $caption) : $caption;
// La firma visible en el preview (solo si aplica = marca no pagada).
$firma_txt = (defined('CRECER_FIRMA') && CRECER_FIRMA !== '' && function_exists('marca_es_pagada') && !marca_es_pagada($pdo, $marca_id)) ? CRECER_FIRMA : '';
$grafica = (string)($post['grafica_path'] ?? '');
$img_pending = (empty($grafica) && (($post['img_estado'] ?? '') === 'queued'));   // Responses generando en background
$aprobado = in_array(($post['estado'] ?? ''), ['aprobado','fallido','publicando'], true);   // ya pasó del borrador → listo para (re)publicar
$publicado = ($post['estado'] ?? '') === 'publicado';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Tu primer post · Encuéntralo Crecer</title>
<link rel="icon" type="image/png" href="/crecer/assets/brand/crecer-icon.png">
<link rel="apple-touch-icon" href="/crecer/assets/brand/crecer-icon.png">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="/crecer/assets/encuentralo-ui.css?v=22" rel="stylesheet">
<style>
  body{background:var(--crema,#F7F5F1);min-height:100vh}
  .gw{max-width:560px;margin:0 auto;padding:22px 18px 60px}
  .gw-top{display:flex;align-items:center;gap:9px;margin-bottom:16px}
  .gw-top img{height:28px}.gw-top b{font-weight:800;font-size:18px;color:var(--tinta)}
  .gw-top .step{margin-left:auto;font-size:12px;font-weight:700;color:var(--muted)}
  .gw-kick{font-family:var(--font-display,'Poppins');font-weight:700;font-size:clamp(22px,5.4vw,28px);letter-spacing:-.01em;color:var(--tinta);line-height:1.12;margin:0 0 6px}
  .gw-sub{color:var(--muted);font-size:14.5px;line-height:1.5;margin:0 0 18px}
  /* La tarjeta del post — CLAVADA, es la protagonista */
  .card{background:var(--card,#fff);border:1px solid var(--line);border-radius:20px;overflow:hidden;box-shadow:var(--shadow-sm)}
  .card .img{width:100%;aspect-ratio:1/1;object-fit:cover;display:block;background:var(--crema-2,#efece7)}
  .card .noimg{width:100%;aspect-ratio:1/1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;text-align:center;padding:24px;color:var(--muted);font-size:14px;font-weight:600;background:var(--crema-2,#efece7)}
  .card .noimg .spin{width:38px;height:38px;border-radius:50%;border:4px solid rgba(0,0,0,.12);border-top-color:var(--magenta,#EF4375);animation:gspin .8s linear infinite}
  /* Pantalla de espera viva — el corillo trabajando */
  .card .wait{width:100%;aspect-ratio:1/1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;padding:22px 20px;text-align:center;
    background:linear-gradient(160deg,#fff 0%,#ffeef4 55%,#e9faf8 100%)}
  .card .wait .spin{width:20px;height:20px;border-radius:50%;border:3px solid rgba(0,0,0,.12);border-top-color:var(--magenta,#EF4375);animation:gspin .8s linear infinite;flex:0 0 auto}
  .card .wait-top{display:flex;align-items:center;gap:9px;font-weight:800;font-size:13.5px;color:var(--tinta,#231F20)}
  .card .wait-top #waitStatus{transition:opacity .3s}
  .card .wait-card{background:#fff;border:1px solid rgba(0,0,0,.06);border-radius:16px;padding:18px 16px;max-width:320px;width:100%;box-shadow:0 8px 26px rgba(35,31,32,.10);animation:wcIn .35s ease}
  @keyframes wcIn{from{opacity:0;transform:translateY(8px) scale(.98)}to{opacity:1;transform:none}}
  .card .wc-emoji{font-size:34px;line-height:1;margin-bottom:8px}
  .card .wc-kind{font-size:10.5px;font-weight:900;letter-spacing:.09em;color:var(--magenta,#EF4375);margin-bottom:6px}
  .card .wc-text{font-size:14px;line-height:1.5;color:#3a3436;font-weight:600}
  .card .wait-next{background:#fff;border:1.5px solid rgba(0,0,0,.12);color:var(--tinta,#231F20);font-weight:800;font-size:13px;padding:8px 18px;border-radius:99px;cursor:pointer}
  .card .wait-next:active{transform:scale(.96)}
  .card .wait-foot{font-size:12px;color:var(--muted,#6E6A67);max-width:300px;line-height:1.45}
  .card .cap{padding:16px 17px;font-size:14.5px;line-height:1.6;color:var(--tinta);white-space:pre-wrap;word-wrap:break-word}
  .card .cap-edit{width:100%;font-family:inherit;font-size:14.5px;line-height:1.6;border:1.5px solid var(--magenta);border-radius:12px;padding:12px 13px;min-height:130px;resize:vertical}
  .cambio-in{width:100%;font-family:inherit;font-size:15px;border:1.5px solid var(--magenta);border-radius:13px;padding:13px 14px;box-sizing:border-box}
  .cambio-in:focus{outline:0}
  /* Zona de acción */
  .acts{margin-top:18px;display:flex;flex-direction:column;gap:11px}
  .btn{width:100%;border:0;cursor:pointer;font-family:var(--font-display,'Poppins');font-weight:700;font-size:16px;padding:15px;border-radius:15px;transition:transform .15s,box-shadow .15s;display:flex;align-items:center;justify-content:center;gap:8px;text-decoration:none}
  .btn:active{transform:translateY(1px)}
  .btn.pri{color:#fff;background:var(--btn-grad,linear-gradient(135deg,#FF6B3D,#EF4375));box-shadow:var(--btn-glow)}
  .btn.ok{color:#fff;background:var(--palma,#00A49F)}
  .btn.gho{background:#fff;border:1.5px solid var(--line);color:var(--ink-soft,#333)}
  .btn:disabled{opacity:.55;cursor:default}
  .hint{text-align:center;font-size:12.5px;color:var(--muted);margin-top:4px;line-height:1.45}
  .row2{display:flex;gap:11px}.row2 .btn{flex:1;font-size:15px}
  .toast{position:fixed;left:50%;bottom:26px;transform:translateX(-50%) translateY(20px);background:var(--tinta);color:#fff;font-weight:600;font-size:14px;padding:12px 18px;border-radius:12px;opacity:0;transition:.25s;z-index:50}
  .toast.on{opacity:1;transform:translateX(-50%) translateY(0)}
  /* Loading a pantalla completa (publicar / conectar) */
  .gload{position:fixed;inset:0;z-index:400;display:none;flex-direction:column;align-items:center;justify-content:center;gap:18px;padding:30px;text-align:center;
    background:radial-gradient(120% 90% at 14% 0%,color-mix(in srgb,var(--magenta,#EF4375) 58%,transparent),transparent 58%),
      radial-gradient(120% 90% at 100% 100%,color-mix(in srgb,var(--palma,#00A49F) 52%,transparent),transparent 58%),rgba(24,14,24,.5);
    backdrop-filter:blur(9px);-webkit-backdrop-filter:blur(9px)}
  .gload.on{display:flex}
  .gload .sp{width:52px;height:52px;border-radius:50%;border:4px solid rgba(255,255,255,.35);border-top-color:#fff;animation:gspin .8s linear infinite}
  @keyframes gspin{to{transform:rotate(360deg)}}
  .gload .msg{color:#fff;font-family:var(--font-display,'Poppins');font-weight:700;font-size:17px;max-width:300px;line-height:1.4;text-shadow:0 1px 12px rgba(0,0,0,.35)}
  /* Venta (placeholder Fase 1 — el carrusel pleno llega en Fase 4) */
  .cel{text-align:center;padding:8px 0 4px}
  .cel .big{font-family:var(--font-display,'Poppins');font-weight:800;font-size:26px;color:var(--tinta);margin:14px 0 6px}
  .pitch{margin-top:22px;background:linear-gradient(135deg,color-mix(in srgb,var(--magenta) 8%,#fff),color-mix(in srgb,var(--palma) 8%,#fff));border:1px solid var(--line);border-radius:18px;padding:20px 18px;text-align:center}
  .pitch h3{font-family:var(--font-display,'Poppins');font-weight:700;font-size:18px;color:var(--tinta);margin:0 0 6px}
  .pitch p{font-size:14px;color:var(--muted);line-height:1.55;margin:0 0 14px}
  /* Carrusel de venta — móvil: swipe con el dedo · desktop: flechas */
  .sell{margin-top:22px}
  .sell-track{display:flex;gap:12px;overflow-x:auto;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch;scrollbar-width:none;padding:2px}
  .sell-track::-webkit-scrollbar{display:none}
  .slide{flex:0 0 100%;scroll-snap-align:center;box-sizing:border-box;background:var(--card,#fff);border:1px solid var(--line);border-radius:20px;padding:34px 24px;text-align:center;box-shadow:var(--shadow-sm)}
  .slide .ico{font-size:46px;margin-bottom:14px;line-height:1}
  .slide h3{font-family:var(--font-display,'Poppins');font-weight:700;font-size:21px;color:var(--tinta);margin:0 0 8px;letter-spacing:-.01em}
  .slide p{font-size:14.5px;color:var(--muted);line-height:1.55;margin:0 auto;max-width:32ch}
  .sell-nav{display:flex;align-items:center;justify-content:center;gap:16px;margin-top:16px}
  .sell-dots{display:flex;gap:7px}
  .sell-dots i{width:8px;height:8px;border-radius:50%;background:var(--line);transition:.25s;cursor:pointer}
  .sell-dots i.on{background:var(--magenta,#EF4375);width:22px;border-radius:4px}
  .sell-arrow{width:40px;height:40px;border-radius:50%;border:1.5px solid var(--line);background:#fff;color:var(--tinta);font-size:22px;line-height:1;cursor:pointer;display:grid;place-items:center;transition:.15s;flex:none}
  .sell-arrow:hover{border-color:var(--magenta,#EF4375);color:var(--magenta,#EF4375)}
  .sell-cta{margin-top:20px;text-align:center;position:sticky;bottom:0;background:linear-gradient(to top,var(--crema,#F7F5F1) 72%,transparent);padding:14px 0 8px}
  .price{font-family:var(--font-display,'Poppins');font-weight:800;font-size:40px;color:var(--tinta);line-height:1}
  .price span{font-size:16px;font-weight:600;color:var(--muted)}
  .price-sub{font-size:13px;color:var(--muted);margin:5px 0 13px}
  /* Selector segmentado: la imagen con o sin texto (lo decide el dueño) */
  .imgmode{margin-top:14px}
  .imgmode .lbl{font-size:12.5px;font-weight:700;color:var(--muted);margin:0 2px 7px}
  .imgmode .seg{display:flex;gap:4px;background:var(--crema-2,#efece7);border-radius:14px;padding:4px}
  .imgmode .opt{flex:1;border:0;background:transparent;cursor:pointer;font-family:var(--font-display,'Poppins');font-weight:700;font-size:13.5px;color:var(--muted);padding:11px 8px;border-radius:11px;transition:background .2s,color .2s,box-shadow .2s;display:flex;align-items:center;justify-content:center;gap:7px}
  .imgmode .opt:active{transform:scale(.98)}
  .imgmode .opt.on{background:#fff;color:var(--tinta);box-shadow:0 3px 10px -3px rgba(20,12,20,.16)}
  .imgmode .opt svg{width:16px;height:16px}
</style>
</head>
<body>
<div class="gw">
  <div class="gw-top">
    <img src="/crecer/assets/brand/crecer-icon.png" alt=""><b>encuéntralo <span style="color:var(--teal)">crecer</span></b>
    <span class="step"><?= $publicado ? '¡Listo!' : ($aprobado ? 'Paso 3 de 3' : 'Paso 2 de 3') ?></span>
  </div>

<?php if ($publicado): /* ── VENTA: celebración → carrusel de bondades → precio → suscribir ── */ ?>
  <div class="cel">
    <div style="font-size:46px">🎉</div>
    <div class="big">¡Tu post está publicado!</div>
    <p class="gw-sub" style="margin-bottom:0">Lo hizo tu equipo por ti, en tu voz. Ahora imagínate esto <b>todos los días</b>.</p>
  </div>

  <!-- SU POST (preview read-only — ya publicado, nada que ajustar) -->
  <div class="card" style="margin-top:16px">
    <?php if ($grafica): ?><img class="img" src="<?= $h($grafica) ?>" alt=""><?php endif; ?>
    <?php if ($caption !== ''): ?><div class="cap"><?= $h($caption) ?></div><?php endif; ?>
    <?php if ($firma_txt !== ''): ?><div class="cap" style="opacity:.6;font-size:13px;padding-top:0"><?= $h($firma_txt) ?></div><?php endif; ?>
  </div>
  <?php if ($ver_url !== ''): ?><a class="btn gho" style="margin-top:14px" href="<?= $h($ver_url) ?>" target="_blank" rel="noopener">Ver mi post en vivo →</a><?php endif; ?>

  <div class="sell">
    <div class="sell-track" id="sellTrack">
      <div class="slide"><div class="ico">🎨</div><h3>Tu marketing, hecho</h3><p>El corillo crea los posts, el arte y los captions en tu voz. Tú solo apruebas.</p></div>
      <div class="slide"><div class="ico">📅</div><h3>Contenido todo el mes</h3><p>Nunca más quedarte en blanco. Un calendario listo, mes tras mes.</p></div>
      <div class="slide"><div class="ico">🇵🇷</div><h3>Suena a ti, no a robot</h3><p>Boricua de verdad, con tu sabor. Cero "AI slop".</p></div>
      <div class="slide"><div class="ico">📲</div><h3>Apruebas desde el celular</h3><p>En segundos, donde estés. El corillo hace el resto.</p></div>
      <div class="slide"><div class="ico">🚀</div><h3>Publica y responde solo</h3><p>Auto-publica a tus redes y contesta los DMs por ti.</p></div>
    </div>
    <div class="sell-nav">
      <button class="sell-arrow" id="sellPrev" aria-label="Anterior">‹</button>
      <div class="sell-dots" id="sellDots"></div>
      <button class="sell-arrow" id="sellNext" aria-label="Siguiente">›</button>
    </div>
  </div>

  <div class="sell-cta">
    <div class="price">$<?= number_format((float)$plan_venta['precio_mensual'], 0) ?><span>/mes</span></div>
    <div class="price-sub">Cancela cuando quieras · tu primer post ya es tuyo</div>
    <form method="post" action="/crecer/panel/crear_checkout.php">
      <?= csrf_field() ?>
      <input type="hidden" name="marca" value="<?= (int)$marca_id ?>">
      <input type="hidden" name="plan" value="<?= $h($plan_venta['slug']) ?>">
      <button class="btn pri" type="submit">Activar mi corillo →</button>
    </form>
  </div>

<?php else: /* ── ESTADO POST (borrador/aprobado) ── */ ?>
  <?php if ($aprobado): ?>
    <h1 class="gw-kick">¡Aprobado! Ahora publícalo 🚀</h1>
    <p class="gw-sub">Publícalo en tus redes o WhatsApp. Como tú lo quieras: te lo conectamos y publica solo, o lo bajas y lo subes tú.</p>
  <?php else: ?>
    <h1 class="gw-kick">Tu primer post está listo</h1>
    <p class="gw-sub">El corillo lo hizo para <b><?= $h($nombre) ?></b>, en tu voz. Míralo, ajústalo si quieres, y cuando te guste, apruébalo.</p>
  <?php endif; ?>

  <div class="card">
    <?php if ($grafica): ?>
      <img class="img" src="<?= $h($grafica) ?>" alt="">
    <?php elseif ($img_pending): ?>
      <div class="wait" id="wait">
        <div class="wait-top"><span class="spin"></span><span id="waitStatus">Preparando el concepto…</span></div>
        <div class="wait-card" id="waitCard">
          <div class="wc-emoji" id="wcEmoji">🎨</div>
          <div class="wc-kind" id="wcKind">EL CORILLO</div>
          <div class="wc-text" id="wcText">Tu anuncio se está cocinando…</div>
        </div>
        <button class="wait-next" id="waitNext" type="button">Otra ▸</button>
        <div class="wait-foot">Toma un par de minutos — tu arte aparece solo. Mientras, ajusta el texto abajo. 👇</div>
      </div>
    <?php else: ?>
      <div class="noimg">Preparando tu arte…</div>
    <?php endif; ?>
    <div class="cap" id="capBox"><?= $caption !== '' ? $h($caption) : '<span style="color:var(--muted)">Sin texto todavía.</span>' ?></div>
    <?php if ($firma_txt !== ''): ?><div class="cap" style="opacity:.55;font-size:13px;padding-top:0"><?= $h($firma_txt) ?></div><?php endif; ?>
    <textarea class="cap-edit" id="capEdit" style="display:none;margin:0 15px 15px"><?= $h($caption) ?></textarea>
  </div>

  <?php if ($grafica): ?>
  <div class="imgmode">
    <div class="lbl">La imagen — tú decides</div>
    <div class="seg" id="imgMode">
      <button type="button" class="opt on" data-txt="0">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>Foto sola
      </button>
      <button type="button" class="opt" data-txt="1">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7V5a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v2"/><path d="M9 20h6"/><path d="M12 4v16"/></svg>Con texto
      </button>
    </div>
  </div>
  <?php endif; ?>

  <div class="acts" id="actsBorrador" style="<?= $aprobado ? 'display:none' : '' ?>">
    <button class="btn ok" id="btnAprobar">✓ Aprobar este post</button>
    <button class="btn gho" id="btnSugerir">✨ Sugiéreme otra versión</button>
    <button class="btn gho" id="btnCambio">💬 Pedir un cambio</button>
    <button class="btn gho" id="btnAjustar">✎ Editar el texto yo mismo</button>
  </div>
  <div class="acts" id="actsCambio" style="display:none">
    <input class="cambio-in" id="cambioNota" placeholder="Ej. más corto · menciona el descuento · más divertido" maxlength="160">
    <button class="btn pri" id="btnCambioGo">Aplicar cambio</button>
    <button class="btn gho" id="btnCambioX">Cancelar</button>
  </div>
  <div class="acts" id="actsEdit" style="display:none">
    <button class="btn pri" id="btnGuardar">Guardar cambios</button>
    <button class="btn gho" id="btnCancelar">Cancelar</button>
  </div>

  <div class="acts" id="actsPublicar" style="<?= $aprobado ? '' : 'display:none' ?>">
    <button class="btn pri" id="btnRedes">📲 Publicar en mis redes</button>
    <button class="btn gho" id="btnManual">Descargar y publicar yo mismo</button>
    <div class="hint">Gratis. Solo te pedimos confirmar tu celular una vez (que eres humano).</div>
  </div>
  <div class="acts" id="actsManual" style="display:none">
    <a class="btn ok" id="btnBajar" href="<?= $h($grafica) ?>" download>⬇ Bajar la imagen</a>
    <button class="btn gho" id="btnCopiar">Copiar el texto</button>
    <div class="hint">Baja la imagen, copia el texto, y súbelo a tu Instagram, Facebook o WhatsApp. Cuando lo publiques, dale al botón de abajo.</div>
    <div class="hint" style="opacity:.85">🌱 Tu post gratis lleva una pequeña firma de Crecer. Al suscribirte, tus posts salen <b>100% tuyos</b>, sin firma.</div>
    <button class="btn pri" id="btnYaPubli">Ya lo publiqué →</button>
  </div>
<?php endif; ?>
</div>

<div class="toast" id="toast"></div>
<div class="gload" id="gload"><div class="sp"></div><div class="msg" id="gloadMsg">Trabajando…</div></div>

<?php $marca_id = $marca_id; require __DIR__ . '/../includes/_sms_gate.php'; ?>

<script>
(function(){
  var CSRF=<?= json_encode(csrf_token()) ?>, MARCA=<?= (int)$marca_id ?>, PID=<?= (int)$post_id ?>, GW=<?= json_encode($gwq) ?>, PLATS=<?= json_encode(implode(',', $redes_conectadas)) ?>;
  var IMG_PENDING=<?= $img_pending ? 'true' : 'false' ?>;
  var toast=document.getElementById('toast');
  function T(m){ toast.textContent=m; toast.classList.add('on'); setTimeout(function(){toast.classList.remove('on');},2200); }
  function self(accion, extra){ var fd=new FormData(); fd.append('csrf',CSRF); fd.append('accion',accion); for(var k in (extra||{})) fd.append(k,extra[k]); return fetch(location.pathname+location.search,{method:'POST',body:fd}).then(function(r){return r.json();}); }
  var gload=document.getElementById('gload'), gloadMsg=document.getElementById('gloadMsg');
  function showLoad(m){ gloadMsg.textContent=m||'Trabajando…'; gload.classList.add('on'); }
  function hideLoad(){ gload.classList.remove('on'); }
  // Polling de la imagen en background (Responses/gpt-image-2). cb(url) o cb(null) si falla.
  function pollImg(cb){
    var t=setInterval(function(){
      self('poll_imagen',{}).then(function(d){
        if(d&&d.estado==='ok'&&d.img){ clearInterval(t); cb(d.img); }
        else if(d&&d.estado==='error'){ clearInterval(t); cb(null); }
      }).catch(function(){});
    },4000);
  }
  function swapImg(url){ var im=document.querySelector('.card .img');
    if(im){ im.src=url+(url.indexOf('?')>-1?'&':'?')+'v='+Date.now(); }
    else { location.reload(); }   // venía del estado "generando" (sin <img>) → recarga para pintar toda la UI
  }
  // Al cargar: si la imagen se está generando en background, espera y refresca solo.
  if(IMG_PENDING){ pollImg(function(url){ location.reload(); }); }
  // Pantalla de espera VIVA — el corillo trabajando + deck de trivias/novedades.
  (function(){
    var wait=document.getElementById('wait'); if(!wait) return;
    var STATUS=['Preparando el concepto…','Escribiendo tu titular…','Eligiendo los colores…','Buscando la idea que detiene el scroll…','Dándole el toque boricua…','Componiendo la escena…','Puliendo los detalles…'];
    var DECK=[
      // 🇵🇷 Orgullo / datos de Puerto Rico
      {e:'🌊',k:'ORGULLO PR',t:'La Bahía Bioluminiscente de Vieques es la más brillante del mundo — récord Guinness. Aquí hasta el mar brilla.'},
      {e:'🌴',k:'¿SABÍAS QUE…',t:'El Yunque es el único bosque tropical lluvioso del sistema forestal de Estados Unidos. Y está aquí, en la isla.'},
      {e:'☕',k:'ORGULLO PR',t:'Puerto Rico produce café de altura premiado a nivel mundial. Si vendes café, presúmelo sin pena.'},
      {e:'🎶',k:'PA’L MUNDO',t:'La música boricua está sonando en todo el planeta. Lo de aquí llega lejos — tu negocio también puede.'},
      {e:'🏅',k:'CAMPEONES',t:'Mónica Puig (2016) y Jasmine Camacho-Quinn (2020) trajeron oro olímpico a la isla. Aquí se echa pa’lante.'},
      {e:'🗺️',k:'DATO BORICUA',t:'Puerto Rico tiene 78 municipios, cada uno con su sabor. Tu negocio es parte de esa historia.'},
      {e:'🍢',k:'TRIVIA',t:'El pastelillo y la empanadilla son primos, no gemelos: la empanadilla es más grande y jugosa. Guerra eterna.'},
      {e:'🐸',k:'¿SABÍAS QUE…',t:'El coquí solo canta de noche — el macho pone el "co" para marcar territorio y el "quí" para enamorar.'},
      {e:'🥁',k:'CULTURA',t:'La bomba y la plena nacieron aquí. Ese ritmo de resistencia y celebración es el mismo con el que se levanta un negocio.'},

      // 💪 Negocios que echan pa’lante (historias que inspiran)
      {e:'💪',k:'ECHA PA’LANTE',t:'Muchas marcas boricuas grandes empezaron en una cocina, un garaje o un kiosco. El tuyo también puede.'},
      {e:'🏪',k:'DATO REAL',t:'Los pequeños negocios son la columna de la economía boricua: la mayoría de los empleos salen de ellos. Tú mueves la isla.'},
      {e:'🚀',k:'HISTORIA',t:'La repostera que empezó vendiendo por WhatsApp los domingos… hoy tiene fila. Todo empieza con un buen post.'},
      {e:'🔁',k:'LA CLAVE',t:'Consistencia le gana a perfección: quien postea todas las semanas crece más que quien postea "cuando puede".'},
      {e:'🌱',k:'ECHA PA’LANTE',t:'No necesitas ser experto en redes. Necesitas aparecer. De eso nos encargamos nosotros.'},

      // 🌱 Crecer + XPRIZE
      {e:'🏆',k:'CRECER VA AL XPRIZE',t:'Crecer compite en el Build with Gemini XPRIZE: IA que levanta al micronegocio boricua. Tú eres parte de esa historia.'},
      {e:'🌱',k:'QUÉ ES CRECER',t:'Un departamento de marketing con IA para el negocio boricua — sin pagar una agencia cara. Tú apruebas, la IA hace el resto.'},
      {e:'🤖',k:'TU VENTAJA',t:'Tu contenido lo crea IA de última generación — la misma tecnología de las grandes marcas, ahora de tu lado.'},
      {e:'🇵🇷',k:'DE AQUÍ',t:'Crecer es hecho en Puerto Rico, para Puerto Rico. Entendemos tu mercado porque es el nuestro.'},
      {e:'📲',k:'LA META',t:'Que tú solo apruebes desde el celular y tu equipo de IA corra el marketing del mes. Así de fácil.'},

      // 🔥 Tips de marketing / redes
      {e:'🔥',k:'TIP',t:'Un post con imagen detiene el scroll muchísimo más que uno de solo texto. Por eso cuidamos tanto tu arte.'},
      {e:'📸',k:'TIP',t:'¿Tienes fotos reales de tu producto? Súbelas y tu equipo las realza. Lo real siempre gana.'},
      {e:'⏰',k:'DATO',t:'Las mejores horas para postear comida en PR: 11am (antojo de almuerzo) y 6pm (¿qué como hoy?).'},
      {e:'🗣️',k:'POR QUÉ FUNCIONA',t:'Mostrar a las personas detrás del negocio vende: la gente compra de gente, no de logos.'},
      {e:'🎯',k:'TIP',t:'Un solo mensaje claro por post gana. No metas cinco ideas en uno.'},
      {e:'📍',k:'NO FALLES',t:'Pon SIEMPRE cómo comprar: WhatsApp, link o dirección. Que nadie tenga que adivinar.'},
      {e:'💬',k:'TIP',t:'Contesta los comentarios y DMs rápido: el algoritmo premia la conversación.'},
      {e:'🎬',k:'TIP',t:'Los videos cortos (Reels) llegan a más gente nueva que las fotos. Prueba uno esta semana.'},
      {e:'🔗',k:'TIP',t:'Conecta tu Instagram y Facebook una sola vez y tu equipo publica por ti.'},
      {e:'📅',k:'NOVEDAD',t:'Con tu plan, tu equipo te arma el calendario del mes completo. Nunca más quedarte en blanco.'},
      {e:'🧠',k:'TIP',t:'Mientras más le cuentes a tu equipo sobre tu negocio, mejores te salen los posts.'},
      {e:'💡',k:'IDEA',t:'Cuenta el "por qué" de tu negocio de vez en cuando. La historia conecta más que el precio.'},
      {e:'⭐',k:'TIP',t:'Comparte reseñas y fotos de clientes felices. La prueba social vende sola.'}
    ];
    for(var i=DECK.length-1;i>0;i--){ var j=Math.floor(Math.random()*(i+1)); var tmp=DECK[i]; DECK[i]=DECK[j]; DECK[j]=tmp; }
    var si=0, ci=0, st=document.getElementById('waitStatus');
    setInterval(function(){ si=(si+1)%STATUS.length; st.style.opacity=0; setTimeout(function(){ st.textContent=STATUS[si]; st.style.opacity=1; },300); },2800);
    var card=document.getElementById('waitCard'), we=document.getElementById('wcEmoji'), wk=document.getElementById('wcKind'), wt=document.getElementById('wcText');
    function showCard(){ var c=DECK[ci%DECK.length]; we.textContent=c.e; wk.textContent=c.k; wt.textContent=c.t; card.style.animation='none'; void card.offsetWidth; card.style.animation='wcIn .35s ease'; }
    function next(){ ci++; showCard(); }
    showCard();
    var auto=setInterval(next,7000);
    document.getElementById('waitNext').addEventListener('click',function(){ clearInterval(auto); next(); auto=setInterval(next,7000); });
  })();

<?php if (!$publicado): ?>
  var actsB=document.getElementById('actsBorrador'), actsE=document.getElementById('actsEdit'),
      actsP=document.getElementById('actsPublicar'), actsM=document.getElementById('actsManual'),
      capBox=document.getElementById('capBox'), capEdit=document.getElementById('capEdit');

  // Aprobar → NO lo saco de pantalla; solo cambio la zona de acción a "publicar".
  var btnAprobar=document.getElementById('btnAprobar');
  if(btnAprobar) btnAprobar.addEventListener('click',function(){
    btnAprobar.disabled=true;
    self('aprobar').then(function(d){
      if(d&&d.ok){ actsB.style.display='none'; actsP.style.display=''; T('✓ Aprobado'); window.scrollTo({top:document.body.scrollHeight,behavior:'smooth'}); }
      else { btnAprobar.disabled=false; T((d&&d.err)||'No se pudo.'); }
    }).catch(function(){ btnAprobar.disabled=false; T('Error de conexión.'); });
  });

  // Ajustar el texto (inline, el post nunca se va)
  var btnAjustar=document.getElementById('btnAjustar');
  if(btnAjustar) btnAjustar.addEventListener('click',function(){ capBox.style.display='none'; capEdit.style.display='block'; actsB.style.display='none'; actsE.style.display=''; capEdit.focus(); });
  document.getElementById('btnCancelar').addEventListener('click',function(){ capEdit.style.display='none'; capBox.style.display=''; actsE.style.display='none'; actsB.style.display=''; });

  // ✨ Otra versión / 💬 Pedir un cambio — la IA reescribe respetando el TONO elegido.
  var actsC=document.getElementById('actsCambio');
  function aiRewrite(accion, extra){
    var prev=capBox.textContent;
    actsB.style.display='none'; actsC.style.display='none';
    capBox.innerHTML='<span style="color:var(--muted)">✍️ Tu equipo está escribiendo…</span>';
    self(accion, extra).then(function(d){
      if(d&&d.ok&&d.caption){ capBox.textContent=d.caption; if(capEdit) capEdit.value=d.caption; T('Nueva versión ✓'); }
      else { capBox.textContent=prev; T((d&&d.err)||'No se pudo ahora.'); }
      actsB.style.display='';
    }).catch(function(){ capBox.textContent=prev; T('Error de conexión.'); actsB.style.display=''; });
  }
  var btnSugerir=document.getElementById('btnSugerir');
  if(btnSugerir) btnSugerir.addEventListener('click',function(){ aiRewrite('sugerir'); });
  var btnCambio=document.getElementById('btnCambio');
  if(btnCambio) btnCambio.addEventListener('click',function(){ actsB.style.display='none'; actsC.style.display=''; var n=document.getElementById('cambioNota'); if(n) n.focus(); });
  var btnCambioX=document.getElementById('btnCambioX');
  if(btnCambioX) btnCambioX.addEventListener('click',function(){ actsC.style.display='none'; actsB.style.display=''; });
  var btnCambioGo=document.getElementById('btnCambioGo');
  if(btnCambioGo) btnCambioGo.addEventListener('click',function(){ var n=(document.getElementById('cambioNota').value||'').trim(); if(!n){ T('Escribe qué cambiar.'); return; } aiRewrite('pedir_cambio',{nota:n}); });

  // 🎨 Imagen con/sin texto — el dueño decide; corre el director de arte y regenera.
  var imgMode=document.getElementById('imgMode');
  if(imgMode) imgMode.querySelectorAll('.opt').forEach(function(b){
    b.addEventListener('click',function(){
      if(b.classList.contains('on')) return;
      var ct=b.getAttribute('data-txt');
      showLoad(ct==='1'?'El director de arte está diseñando tu gráfico…':'El director de arte está rediseñando tu foto…');
      self('regenerar_imagen',{con_texto:ct}).then(function(d){
        if(d&&d.job){   // motor Responses: se generó en background → esperar por polling
          pollImg(function(url){ hideLoad();
            if(url){ swapImg(url); imgMode.querySelectorAll('.opt').forEach(function(x){x.classList.remove('on');}); b.classList.add('on'); T('Imagen lista ✓'); }
            else T('No se pudo esta vez.'); });
          return;
        }
        hideLoad();
        if(d&&d.ok&&d.img){ swapImg(d.img); imgMode.querySelectorAll('.opt').forEach(function(x){x.classList.remove('on');}); b.classList.add('on'); T('Imagen lista ✓'); }
        else T((d&&d.err)||'No se pudo ahora.');
      }).catch(function(){ hideLoad(); T('Error de conexión.'); });
    });
  });
  document.getElementById('btnGuardar').addEventListener('click',function(){
    var b=this; b.disabled=true;
    self('guardar_caption',{caption:capEdit.value}).then(function(d){
      b.disabled=false;
      if(d&&d.ok){ capBox.textContent=d.caption||capEdit.value; capBox.style.display=''; capEdit.style.display='none'; actsE.style.display='none'; actsB.style.display=''; T('Guardado'); }
      else T((d&&d.err)||'No se pudo guardar.');
    }).catch(function(){ b.disabled=false; T('Error de conexión.'); });
  });

  // Publicar en redes (canónico: aprobar2.php publicar_api, con gate SMS)
  function publicarRedes(){
    var fd=new FormData(); fd.append('accion','publicar_api'); fd.append('id',PID); fd.append('plataformas', PLATS||'instagram,facebook'); fd.append('ajax','1'); fd.append('csrf',CSRF);
    return fetch('/crecer/panel/aprobar2.php?marca='+MARCA,{method:'POST',body:fd}).then(function(r){return r.json();});
  }
  var btnRedes=document.getElementById('btnRedes');
  if(btnRedes) btnRedes.addEventListener('click',function(){
    showLoad('Publicando en tus redes…');
    publicarRedes().then(function(d){
      if(d&&d.ok){ showLoad('¡Publicado! Un momento…'); location.href='/crecer/panel/gateway_post.php?marca='+MARCA+'&venta=1'+GW; return; }
      if(d&&d.needs_phone){ hideLoad(); window.crecerSmsGate.open(function(){ btnRedes.click(); }); return; }
      if(d&&d.err==='no_conectado'){ showLoad('Conectando tus redes…'); setTimeout(function(){ location.href='/crecer/panel/conectar.php?marca='+MARCA+'&desde=gateway'; }, 500); return; }
      hideLoad(); T((d&&d.err)||'No se pudo publicar.');
    }).catch(function(){ hideLoad(); T('Error de conexión.'); });
  });

  // Publicar manual (bajar + copiar)
  var btnManual=document.getElementById('btnManual');
  if(btnManual) btnManual.addEventListener('click',function(){ actsP.style.display='none'; actsM.style.display=''; window.scrollTo({top:document.body.scrollHeight,behavior:'smooth'}); });
  var btnCopiar=document.getElementById('btnCopiar');
  if(btnCopiar) btnCopiar.addEventListener('click',function(){ var t=<?= json_encode($caption_copiar) ?>; if(navigator.clipboard){ navigator.clipboard.writeText(t).then(function(){T('Texto copiado ✓');},function(){T('Copia el texto a mano');}); } else T('Copia el texto a mano'); });
  // "Ya lo publiqué" (manual) → marca publicado con el gate SMS y pasa a venta.
  var btnYa=document.getElementById('btnYaPubli');
  if(btnYa) btnYa.addEventListener('click',function(){
    showLoad('Confirmando…');
    self('publicar_manual').then(function(d){
      if(d&&d.needs_phone){ hideLoad(); window.crecerSmsGate.open(function(){ btnYa.click(); }); return; }
      showLoad('¡Listo! Un momento…');
      location.href='/crecer/panel/gateway_post.php?marca='+MARCA+'&venta=1'+GW;
    }).catch(function(){ location.href='/crecer/panel/gateway_post.php?marca='+MARCA+'&venta=1'+GW; });
  });
<?php endif; ?>
<?php if ($publicado): /* carrusel de venta: swipe (móvil) + flechas + dots */ ?>
  var track=document.getElementById('sellTrack');
  if(track){
    var slides=track.querySelectorAll('.slide'), dotsBox=document.getElementById('sellDots');
    slides.forEach(function(_,i){ var d=document.createElement('i'); if(i===0)d.className='on'; d.addEventListener('click',function(){ track.scrollTo({left:i*track.clientWidth,behavior:'smooth'}); }); dotsBox.appendChild(d); });
    var dots=dotsBox.querySelectorAll('i');
    function upd(){ var idx=Math.round(track.scrollLeft/track.clientWidth); dots.forEach(function(d,i){ d.classList.toggle('on',i===idx); }); }
    track.addEventListener('scroll',function(){ window.requestAnimationFrame(upd); });
    function go(dir){ var idx=Math.max(0,Math.min(slides.length-1, Math.round(track.scrollLeft/track.clientWidth)+dir)); track.scrollTo({left:idx*track.clientWidth,behavior:'smooth'}); }
    var p=document.getElementById('sellPrev'), n=document.getElementById('sellNext');
    if(p)p.addEventListener('click',function(){go(-1);}); if(n)n.addEventListener('click',function(){go(1);});
  }
<?php endif; ?>
})();
</script>
</body>
</html>
