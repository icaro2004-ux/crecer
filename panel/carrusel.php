<?php
// ============================================================
//  CRECER — Carrusel (crear/editar)  ·  panel/carrusel.php
//
//  El GUIONISTA arma una historia slide a slide (en la voz del
//  cliente). Dos caminos para el arte: la IA lo genera (async, avisa
//  por notificación) o el cliente sube sus propias imágenes. Preview
//  con swipe horizontal. Al aprobar entra a Tus Posts para publicar
//  (IG = swipe real; FB = álbum).
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/iconos.php';
require __DIR__ . '/../includes/carrusel.php';
requiere_login();
require_once __DIR__ . '/../includes/panel_guard.php';
requiere_suscripcion($pdo, isset($_GET['marca']) ? (int)$_GET['marca'] : null);

$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }
$marca_id = (int)$marca['id'];
$BASE = '/crecer/panel';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$negocio = $marca['nombre_negocio'] ?? 'tu negocio';
$cid = (int)($_GET['id'] ?? 0);
// ¿Ya corrió la migración? (evita filas rotas si se despliega antes del SQL).
$carr_ok = false;
try { $carr_ok = (bool)$pdo->query("SHOW TABLES LIKE 'crecer_carrusel'")->fetchColumn(); } catch (Throwable $e) {}
// Verifica que el carrusel sea de esta marca.
if ($cid) {
    $chk = $pdo->prepare("SELECT id, caption, estado FROM crecer_contenido WHERE id=? AND marca_id=? AND tipo='carrusel'");
    $chk->execute([$cid, $marca_id]);
    $carr = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$carr) { header("Location: {$BASE}/carrusel.php?marca={$marca_id}"); exit; }
}

// ── AJAX: estado del arte (poll de "preparando" → "listo") ──
if (($_GET['ajax'] ?? '') === 'estado' && $cid) {
    header('Content-Type: application/json; charset=utf-8');
    // Completa los jobs que ya terminaron en OpenAI (sobrevive la muerte del worker).
    if ($carr_ok) { try { carrusel_sweep_pendientes($pdo, $marca_id); } catch (Throwable $e) {} }
    $slides = carrusel_slides($pdo, $cid);
    $out = array_map(fn($s) => [
        'id' => (int)$s['id'], 'orden' => (int)$s['orden'],
        'img' => trim((string)$s['grafica_path']), 'estado' => (string)$s['img_estado'],
    ], $slides);
    $est = carrusel_estado($pdo, $cid);
    echo json_encode(['ok' => true] + $est + ['slides' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $ajax   = !empty($_POST['ajax']);
    $jout = function (array $d) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };
    if (!function_exists('csrf_ok') || !csrf_ok()) {
        if ($ajax) $jout(['ok' => false, 'err' => 'Sesión expiró. Recarga la página.']);
        http_response_code(400); exit('Solicitud inválida.');
    }

    // Crear: el Guionista arma la historia.
    if ($accion === 'generar') {
        if (!$carr_ok) $jout(['ok' => false, 'err' => 'El carrusel aún no está activo (falta correr la migración en la base de datos).']);
        @ignore_user_abort(true);
        // Límite: 1 carrusel por semana (ventana móvil de 7 días) por marca. Admin exento (pruebas/demo).
        if (($usuario['rol'] ?? '') !== 'admin') {
            $w = $pdo->prepare("SELECT COUNT(*) n, MIN(created_at) f FROM crecer_contenido
                                WHERE marca_id=? AND tipo='carrusel' AND created_at >= (NOW() - INTERVAL 7 DAY)");
            $w->execute([$marca_id]); $wr = $w->fetch(PDO::FETCH_ASSOC);
            if ((int)($wr['n'] ?? 0) >= 1) {
                $reset = !empty($wr['f']) ? date('d/m', strtotime($wr['f'] . ' +7 day')) : '';
                $jout(['ok' => false, 'err' => 'Puedes crear 1 carrusel por semana.' . ($reset ? " Vuelve el {$reset}." : ''), 'limite' => true]);
            }
        }
        @set_time_limit(0);
        $tema = trim($_POST['tema'] ?? '');
        $n    = max(CARRUSEL_MIN, min(CARRUSEL_MAX, (int)($_POST['n'] ?? 5)));
        $con_texto = ($_POST['con_texto'] ?? '1') !== '0';   // texto+imagen (narrativa) por defecto
        $r = carrusel_generar($pdo, $marca_id, $tema, $n, $con_texto);
        if (empty($r['ok'])) $jout(['ok' => false, 'err' => 'El Guionista no pudo ahora. Intenta otra vez.']);
        $jout(['ok' => true, 'id' => (int)$r['contenido_id'], 'url' => "{$BASE}/carrusel.php?marca={$marca_id}&id=" . (int)$r['contenido_id']]);
    }

    // Editar caption.
    if ($accion === 'caption' && $cid) {
        $cap = trim($_POST['caption'] ?? '');
        $pdo->prepare("UPDATE crecer_contenido SET caption=?, updated_at=NOW() WHERE id=? AND marca_id=?")->execute([$cap, $cid, $marca_id]);
        $jout(['ok' => true]);
    }

    // El dueño le da su parecer → el Guionista reescribe el carrusel.
    if ($accion === 'ajustar' && $cid) {
        @set_time_limit(0);
        $fb = trim($_POST['feedback'] ?? '');
        $r = carrusel_ajustar($pdo, $marca_id, $cid, $fb);
        if (empty($r['ok'])) {
            $msg = ($r['err'] ?? '') === 'vacio' ? 'Escribe tu comentario primero.' : 'El Guionista no pudo ajustar ahora. Intenta otra vez.';
            $jout(['ok' => false, 'err' => $msg]);
        }
        $jout(['ok' => true, 'caption' => $r['caption'], 'slides' => $r['slides']]);
    }

    // Editar el brief (idea) de un slide.
    if ($accion === 'slide_idea' && $cid) {
        $sid  = (int)($_POST['slide'] ?? 0);
        $idea = trim($_POST['idea'] ?? '');
        $pdo->prepare("UPDATE crecer_carrusel SET idea=?, updated_at=NOW() WHERE id=? AND contenido_id=? AND marca_id=?")->execute([$idea, $sid, $cid, $marca_id]);
        $jout(['ok' => true]);
    }

    // La IA crea el arte de TODO el carrusel (async → notificación al terminar).
    // Encola un job Responses por slide (rápido + resiliente): aunque salgas o el
    // worker muera, el SWEEP completa las imágenes al volver y avisa por la campanita.
    if ($accion === 'arte_ia' && $cid) {
        @set_time_limit(0); @ignore_user_abort(true);
        // Aplica el modo "texto en las imágenes" elegido en el editor (antes de generar).
        if (isset($_POST['con_texto'])) {
            $ct = ($_POST['con_texto'] === '1') ? 1 : 0;
            $ss = $pdo->prepare("SELECT id, idea FROM crecer_carrusel WHERE contenido_id=? AND marca_id=?");
            $ss->execute([$cid, $marca_id]);
            $uu = $pdo->prepare("UPDATE crecer_carrusel SET idea=?, updated_at=NOW() WHERE id=?");
            foreach ($ss->fetchAll(PDO::FETCH_ASSOC) as $row) { $uu->execute([carrusel_idea_set_texto((string)$row['idea'], $ct), (int)$row['id']]); }
        }
        // "Rehacer": borra el arte actual (generado por IA) para volver a crearlo desde cero.
        if (!empty($_POST['rehacer'])) {
            $pdo->prepare("UPDATE crecer_carrusel SET grafica_path=NULL, img_job=NULL, img_estado=NULL, updated_at=NOW() WHERE contenido_id=? AND marca_id=?")
                ->execute([$cid, $marca_id]);
        }
        $n = carrusel_encolar_arte($pdo, $marca_id, $cid);
        if ($n < 0) {   // motor Responses apagado → worker sync (Gemini) como respaldo
            $pdo->prepare("UPDATE crecer_carrusel SET img_estado='queued', updated_at=NOW()
                           WHERE contenido_id=? AND marca_id=? AND (grafica_path IS NULL OR grafica_path='')")
                ->execute([$cid, $marca_id]);
            carrusel_disparar($marca_id, $cid);
        }
        $jout(['ok' => true, 'async' => true]);
    }

    // El cliente sube su propia imagen para un slide (listo al instante).
    if ($accion === 'subir' && $cid) {
        $sid = (int)($_POST['slide'] ?? 0);
        $own = $pdo->prepare("SELECT id FROM crecer_carrusel WHERE id=? AND contenido_id=? AND marca_id=?");
        $own->execute([$sid, $cid, $marca_id]);
        if (!$own->fetchColumn()) $jout(['ok' => false, 'err' => 'Slide no encontrado.']);
        if (empty($_FILES['foto']['tmp_name']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) $jout(['ok' => false, 'err' => 'No llegó la imagen.']);
        $info = @getimagesize($_FILES['foto']['tmp_name']);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
        if (!$ext) $jout(['ok' => false, 'err' => 'Sube una imagen JPG, PNG o WEBP.']);
        $dir = rtrim(UPLOADS_PATH, '/\\') . "/marca_{$marca_id}/fotos";
        @mkdir($dir, 0775, true);
        $fn = 'carr_' . $cid . '_' . $sid . '_' . uniqid() . '.' . $ext;
        if (!move_uploaded_file($_FILES['foto']['tmp_name'], $dir . '/' . $fn)) $jout(['ok' => false, 'err' => 'No se pudo guardar.']);
        $url = rtrim(UPLOADS_URL, '/') . "/marca_{$marca_id}/fotos/" . $fn;
        $pdo->prepare("UPDATE crecer_carrusel SET grafica_path=?, img_estado='ok', updated_at=NOW() WHERE id=? AND contenido_id=?")->execute([$url, $sid, $cid]);
        $jout(['ok' => true, 'url' => $url]);
    }

    // Publicar → cover thumbnail + aprobado + publicar en BACKGROUND (IG swipe / FB álbum).
    if ($accion === 'publicar' && $cid) {
        require_once __DIR__ . '/../includes/meta.php';
        require_once __DIR__ . '/../includes/publicador.php';
        $est = carrusel_estado($pdo, $cid);
        if (($est['total'] ?? 0) < 2)  $jout(['ok' => false, 'err' => 'Un carrusel necesita al menos 2 slides.']);
        if (($est['listos'] ?? 0) < 2) $jout(['ok' => false, 'err' => 'Faltan imágenes: genera el arte o sube tus fotos (mínimo 2 slides con imagen).']);
        // Redes conectadas.
        $conx = conexion_de_marca($pdo, $marca_id);
        if (!$conx || ($conx['estado'] ?? '') !== 'activa' || empty($conx['page_access_token'])) {
            $jout(['ok' => false, 'err' => 'no_conectado', 'url' => "{$BASE}/conectar.php?marca={$marca_id}"]);
        }
        // Cover = primer slide con imagen (para thumbnails / previews).
        $cover = '';
        foreach (carrusel_slides($pdo, $cid) as $s) { if (trim((string)$s['grafica_path']) !== '') { $cover = (string)$s['grafica_path']; break; } }
        $pdo->prepare("UPDATE crecer_contenido SET grafica_path=?, estado='aprobado', updated_at=NOW() WHERE id=? AND marca_id=?")
            ->execute([$cover, $cid, $marca_id]);
        // Publicar a TODAS las redes conectadas (el carrusel se guarda como plataforma
        // 'instagram', así que sin esto FB nunca se intentaba): IG = swipe, FB = álbum.
        $plats = [];
        if (!empty($conx['ig_user_id'])) $plats[] = 'instagram';
        if (!empty($conx['fb_page_id'])) $plats[] = 'facebook';
        publicar_disparar($marca_id, $cid, $plats);
        $jout(['ok' => true, 'publicando' => true]);
    }

    $jout(['ok' => false, 'err' => 'Acción no reconocida.']);
}

// Al cargar el carrusel, completa lo que ya terminó en background (sobrevive worker muerto).
if ($cid && $carr_ok) { try { carrusel_sweep_pendientes($pdo, $marca_id); } catch (Throwable $e) {} }

$active = '';
$page_title = 'Carrusel';
require __DIR__ . '/_shell.php';

// Datos para el render del editor.
$slides = $cid ? carrusel_slides($pdo, $cid) : [];
$prep = false;
foreach ($slides as $s) { if (($s['img_estado'] ?? '') === 'queued') { $prep = true; break; } }
$caption = $cid ? (string)($carr['caption'] ?? '') : '';
$edit_con_texto = 1;
if ($slides) { $v0 = carrusel_slide_visual((string)$slides[0]['idea']); $edit_con_texto = (int)$v0['con_texto']; }
?>
<style>
  .cr{max-width:720px;margin:0 auto}
  .cr-h{font-family:'Poppins',sans-serif;font-weight:800;font-size:clamp(20px,4.4vw,26px);color:var(--tinta);margin:2px 0 4px}
  .cr-sub{color:var(--muted);font-size:14px;margin:0 0 20px;max-width:56ch}
  /* Crear */
  .cr-new{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:20px;box-shadow:var(--shadow-sm)}
  .cr-lbl{font-weight:700;font-size:13.5px;color:var(--tinta);margin:0 0 8px;display:block}
  .cr-ta{width:100%;box-sizing:border-box;font-family:var(--font-body,'Plus Jakarta Sans');font-size:15px;line-height:1.5;color:var(--tinta);border:1.5px solid var(--line);border-radius:13px;padding:13px;resize:vertical;min-height:80px;background:#fff}
  .cr-ta:focus{outline:2px solid color-mix(in srgb,var(--magenta) 38%,transparent);border-color:transparent}
  .cr-nrow{display:flex;align-items:center;gap:10px;margin:16px 0 4px;flex-wrap:wrap}
  .cr-npill{border:1.5px solid var(--line);background:#fff;border-radius:999px;padding:9px 15px;font-weight:700;font-size:14px;color:var(--tinta);cursor:pointer}
  .cr-npill.on{border-color:var(--magenta);background:color-mix(in srgb,var(--magenta) 8%,#fff);color:var(--magenta)}
  .cr-txt-toggle{display:flex;align-items:flex-start;gap:9px;margin:16px 0 2px;font-size:13.5px;color:var(--tinta);cursor:pointer;font-weight:600}
  .cr-txt-toggle input{width:20px;height:20px;margin-top:1px;accent-color:var(--magenta);flex:none}
  .cr-txt-toggle small{display:block;font-weight:500;color:var(--muted);font-size:12px}
  .cr-rehacer{display:inline-flex;align-items:center;gap:6px;margin:12px auto 0;background:0;border:0;cursor:pointer;color:var(--muted);font-family:'Poppins',sans-serif;font-weight:600;font-size:13px;text-decoration:underline}
  .cr-rehacer svg{width:14px;height:14px}
  .cr-go{width:100%;margin-top:18px;border:0;cursor:pointer;font-family:'Poppins',sans-serif;font-weight:700;font-size:16px;color:#fff;padding:16px;border-radius:14px;background:var(--btn-grad);box-shadow:var(--btn-glow);display:flex;align-items:center;justify-content:center;gap:9px}
  .cr-go svg{width:18px;height:18px}
  .cr-go:disabled{opacity:.6;cursor:default}
  /* Preview swipe */
  .cr-cap{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:14px;margin:0 0 16px}
  .cr-cap textarea{width:100%;box-sizing:border-box;border:0;resize:vertical;min-height:70px;font-family:var(--font-body,'Plus Jakarta Sans');font-size:14.5px;line-height:1.55;color:var(--tinta);background:transparent}
  .cr-cap textarea:focus{outline:none}
  .cr-cap .cc-h{font-size:11.5px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:var(--muted);margin-bottom:6px}
  .cr-track{display:flex;gap:14px;overflow-x:auto;scroll-snap-type:x mandatory;padding:4px 2px 14px;-webkit-overflow-scrolling:touch;scrollbar-width:none}
  .cr-track::-webkit-scrollbar{display:none}
  .cr-slide{flex:0 0 84%;max-width:360px;scroll-snap-align:center;background:var(--card);border:1px solid var(--line);border-radius:18px;overflow:hidden;box-shadow:var(--shadow-sm);display:flex;flex-direction:column}
  @media(min-width:620px){.cr-slide{flex-basis:48%}}
  .cr-img{aspect-ratio:1/1;background:var(--crema-2,#f3f1f4);position:relative;display:grid;place-items:center;color:var(--muted)}
  .cr-img img{width:100%;height:100%;object-fit:cover;display:block}
  .cr-num{position:absolute;top:10px;left:10px;background:rgba(0,0,0,.6);color:#fff;font-size:12px;font-weight:800;border-radius:999px;padding:3px 10px;z-index:2}
  .cr-prep{position:absolute;inset:0;display:grid;place-items:center;background:repeating-linear-gradient(45deg,#f3f1f4,#f3f1f4 12px,#eceaee 12px,#eceaee 24px);color:var(--muted);font-size:12.5px;font-weight:700;text-align:center;padding:14px}
  .cr-prep .sp{width:26px;height:26px;border-radius:50%;border:3px solid var(--line);border-top-color:var(--magenta);animation:crspin 1s linear infinite;margin:0 auto 8px}
  @keyframes crspin{to{transform:rotate(360deg)}}
  .cr-body{padding:13px 14px 15px}
  .cr-tit{font-family:'Poppins',sans-serif;font-weight:800;font-size:15px;color:var(--tinta);margin:0 0 4px}
  .cr-txt{font-size:13px;color:var(--muted);line-height:1.45;margin:0 0 10px}
  .cr-txon{font-size:12.5px;color:var(--tinta);line-height:1.4;margin:0 0 10px;font-style:italic}
  .cr-txon .lbl{display:inline-flex;align-items:center;gap:4px;font-style:normal;font-weight:700;color:var(--magenta);font-size:11px}
  .cr-txon .lbl svg{width:12px;height:12px}
  .cr-track.no-txt .cr-txon .lbl{color:var(--muted)}
  .cr-track.no-txt .cr-txon{opacity:.6}
  .cr-up{display:inline-flex;align-items:center;gap:7px;font-size:12.5px;font-weight:700;color:var(--teal);cursor:pointer}
  .cr-up svg{width:15px;height:15px}
  .cr-up input{display:none}
  .cr-dots{display:flex;gap:6px;justify-content:center;margin:2px 0 18px}
  .cr-dots i{width:7px;height:7px;border-radius:50%;background:var(--line);transition:background .2s,width .2s}
  .cr-dots i.on{background:var(--magenta);width:20px;border-radius:99px}
  .cr-fb{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:14px;margin:0 0 16px}
  .cr-fb textarea{width:100%;box-sizing:border-box;border:1.5px solid var(--line);border-radius:12px;resize:vertical;min-height:64px;padding:11px;font-family:var(--font-body,'Plus Jakarta Sans');font-size:14px;line-height:1.5;color:var(--tinta);background:#fff}
  .cr-fb textarea:focus{outline:2px solid color-mix(in srgb,var(--magenta) 34%,transparent);border-color:transparent}
  .cr-fbgo{margin-top:10px;border:0;cursor:pointer;font-family:'Poppins',sans-serif;font-weight:700;font-size:13.5px;color:#fff;background:linear-gradient(135deg,#8b5cf6,#6d28d9);padding:11px 16px;border-radius:12px;display:inline-flex;align-items:center;gap:7px;box-shadow:0 10px 24px -12px rgba(124,58,237,.6)}
  .cr-fbgo svg{width:15px;height:15px}
  .cr-fbgo:disabled{opacity:.6;cursor:default}
  .cr-acts{display:flex;gap:10px;flex-wrap:wrap}
  .cr-b{flex:1;min-width:150px;border:0;cursor:pointer;font-family:'Poppins',sans-serif;font-weight:700;font-size:14.5px;padding:15px;border-radius:14px;display:flex;align-items:center;justify-content:center;gap:8px}
  .cr-b svg{width:17px;height:17px}
  .cr-b.ia{background:linear-gradient(135deg,var(--teal),#0a7d76);color:#fff;box-shadow:0 12px 30px -14px rgba(0,164,159,.6)}
  .cr-b.ok{background:var(--btn-grad);color:#fff;box-shadow:var(--btn-glow)}
  .cr-b:disabled{opacity:.6;cursor:default}
  .cr-note{font-size:12.5px;color:var(--muted);margin:14px 0 0;text-align:center}
  .cr-fbnote{font-size:12px;color:var(--muted);background:color-mix(in srgb,var(--teal) 7%,#fff);border:1px solid color-mix(in srgb,var(--teal) 20%,#fff);border-radius:12px;padding:11px 13px;margin:14px 0 0}
  .cr-toast{position:fixed;left:50%;bottom:calc(96px + env(safe-area-inset-bottom));transform:translateX(-50%);background:var(--tinta);color:#fff;padding:11px 18px;border-radius:12px;font-size:13.5px;font-weight:600;z-index:200;opacity:0;transition:opacity .2s;pointer-events:none;max-width:88vw;text-align:center}
  .cr-toast.on{opacity:1}
  .cr-ov{position:fixed;inset:0;z-index:150;background:rgba(20,12,22,.6);backdrop-filter:blur(4px);display:none;align-items:center;justify-content:center;padding:24px}
  .cr-ov.on{display:flex}
  .cr-ov .bx{background:#fff;border-radius:18px;padding:26px;max-width:340px;text-align:center}
  .cr-ov .sp{width:38px;height:38px;border-radius:50%;border:4px solid var(--line);border-top-color:var(--magenta);animation:crspin 1s linear infinite;margin:0 auto 14px}
  .cr-ov h3{font-family:'Poppins',sans-serif;margin:0 0 6px;font-size:17px;color:var(--tinta)}
  .cr-ov p{margin:0;color:var(--muted);font-size:13.5px}
</style>

<div class="cr">
<?php if (!$cid): ?>
  <!-- ══ CREAR ══ -->
  <h1 class="cr-h"><?= ico('list') ?> Carrusel nuevo</h1>
  <p class="cr-sub">Un carrusel cuenta una historia que la gente desliza. Dile el tema (o deja que el Guionista lo elija) y él arma los slides — uno a uno, en la voz de <b><?= $h($negocio) ?></b>.</p>
  <?php if (!$carr_ok): ?>
  <div class="cr-new"><p style="margin:0;color:var(--tinta);font-size:14.5px;line-height:1.55">El carrusel está casi listo — <b>falta un paso de base de datos</b>. Corre la migración <code>migrations/2026-07-28_crecer_carrusel.sql</code> en phpMyAdmin y recarga esta página.</p></div>
  <?php else: ?>
  <div class="cr-new">
    <label class="cr-lbl" for="crTema">¿De qué es el carrusel?</label>
    <textarea id="crTema" class="cr-ta" placeholder="Ej: 3 razones para pedir tu bizcocho con nosotros · el paso a paso de un pedido · antes y después…"></textarea>
    <div class="cr-nrow">
      <span class="cr-lbl" style="margin:0">Slides:</span>
      <?php foreach ([3,4,5] as $i): ?>
        <button type="button" class="cr-npill<?= $i===5?' on':'' ?>" data-n="<?= $i ?>"><?= $i ?></button>
      <?php endforeach; ?>
    </div>
    <label class="cr-txt-toggle"><input type="checkbox" id="crTxt" checked> <span>Texto en las imágenes <small>(cada slide muestra su titular — narrativa)</small></span></label>
    <button type="button" class="cr-go" id="crGo"><?= ico('sparkles') ?> Que el Guionista lo arme</button>
    <p class="cr-note">Deja el tema vacío y el Guionista elige el mejor ángulo para tu negocio.</p>
  </div>
  <?php endif; ?>
<?php else: ?>
  <!-- ══ EDITAR / PREVIEW ══ -->
  <h1 class="cr-h"><?= ico('list') ?> Tu carrusel <span style="font-size:11px;color:#c0392b;font-weight:800">· BUILD C5</span></h1>
  <p class="cr-sub">Desliza para ver los slides. La IA puede crear el arte de todos, o sube tus propias fotos. Al aprobar, entra a Tus Posts para publicar.</p>

  <div class="cr-cap">
    <div class="cc-h">Pie de foto</div>
    <textarea id="crCap" data-cid="<?= $cid ?>" placeholder="El texto que acompaña el carrusel…"><?= $h($caption) ?></textarea>
  </div>

  <div class="cr-track<?= $edit_con_texto ? '' : ' no-txt' ?>" id="crTrack">
    <?php foreach ($slides as $s):
      $v = carrusel_slide_visual((string)$s['idea']);
      $img = trim((string)$s['grafica_path']);
      $queued = (($s['img_estado'] ?? '') === 'queued');
    ?>
    <div class="cr-slide" data-slide="<?= (int)$s['id'] ?>">
      <div class="cr-img">
        <span class="cr-num"><?= (int)$s['orden'] ?></span>
        <?php if ($img !== ''): ?>
          <img src="<?= $h($img) ?>" alt="">
        <?php elseif ($queued): ?>
          <div class="cr-prep" data-prep><div class="sp"></div>Preparando el arte…</div>
        <?php else: ?>
          <div class="cr-prep" data-prep style="background:var(--crema-2,#f3f1f4)"><?= ico('image') ?><br>Sin arte todavía</div>
        <?php endif; ?>
      </div>
      <div class="cr-body">
        <?php if ($v['visual'] !== ''): ?><div class="cr-tit"><?= $h($v['visual']) ?></div><?php endif; ?>
        <p class="cr-txon" data-txon><span class="lbl"><?= ico('pen') ?> Texto en la imagen:</span> <?php if ($v['copy'] !== ''): ?>«<span data-txcopy><?= $h($v['copy']) ?></span>»<?php else: ?><span data-txcopy style="color:var(--muted)">(el Guionista no puso texto en este slide)</span><?php endif; ?></p>
        <label class="cr-up"><?= ico('image') ?> Subir mi foto
          <input type="file" accept="image/png,image/jpeg,image/webp" data-slide="<?= (int)$s['id'] ?>">
        </label>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="cr-dots" id="crDots"></div>

  <div class="cr-fb">
    <div class="cc-h">¿Qué le cambiarías? — dile al Guionista</div>
    <textarea id="crFb" placeholder="Ej: hazlo más corto · empieza con una pregunta · menciona que hacemos delivery · más atrevido…"></textarea>
    <button type="button" class="cr-fbgo" id="crFbGo"><?= ico('sparkles') ?> Ajustar con el Guionista</button>
  </div>

  <label class="cr-txt-toggle" style="margin:0 0 14px"><input type="checkbox" id="crTxt2" <?= $edit_con_texto ? 'checked' : '' ?>> <span>Texto en las imágenes <small>(cada slide muestra su titular — aplica al crear el arte)</small></span></label>

  <div class="cr-acts">
    <button type="button" class="cr-b ia" id="crIA"><?= ico('sparkles') ?> Que la IA cree el arte</button>
    <button type="button" class="cr-b ok" id="crOK"><?= ico('send') ?> Publicar carrusel</button>
  </div>
  <div style="text-align:center"><button type="button" class="cr-rehacer" id="crRehacer"><?= ico('refresh') ?> Rehacer el arte desde cero</button></div>
  <div class="cr-fbnote"><b>Nota:</b> en Instagram sale como carrusel deslizable; en Facebook, como álbum de fotos (FB no tiene el swipe de IG).</div>
  <p class="cr-note">Cuando la IA crea el arte, puede tardar — te avisamos por la <b>campanita</b> cuando esté listo. Si subes tus fotos, quedan al instante.</p>
<?php endif; ?>
</div>

<div class="cr-ov" id="crOv"><div class="bx"><div class="sp"></div><h3 id="crOvH">Trabajando…</h3><p id="crOvP"></p></div></div>
<div class="cr-toast" id="crToast"></div>

<script>
(function(){
  var CSRF = <?= json_encode(csrf_token()) ?>, MARCA = <?= (int)$marca_id ?>, CID = <?= (int)$cid ?>,
      BASE = <?= json_encode($BASE) ?>;
  var toast=document.getElementById('crToast');
  function say(m){ toast.textContent=m; toast.classList.add('on'); clearTimeout(say._t); say._t=setTimeout(function(){toast.classList.remove('on');},2600); }
  var ov=document.getElementById('crOv');
  function ovShow(h,p){ document.getElementById('crOvH').textContent=h||'Trabajando…'; document.getElementById('crOvP').textContent=p||''; ov.classList.add('on'); }
  function ovHide(){ ov.classList.remove('on'); }
  function post(fd){ return fetch(location.pathname+'?marca='+MARCA+(CID?'&id='+CID:''),{method:'POST',body:fd}).then(function(r){return r.json();}); }

  // ── CREAR ──
  var go=document.getElementById('crGo');
  if(go){
    var nSel=5;
    document.querySelectorAll('.cr-npill').forEach(function(b){ b.addEventListener('click',function(){
      document.querySelectorAll('.cr-npill').forEach(function(x){x.classList.remove('on');}); b.classList.add('on'); nSel=+b.dataset.n;
    }); });
    go.addEventListener('click',function(){
      go.disabled=true;
      ovShow('El Guionista está escribiendo…','Arma la historia slide a slide en tu voz.');
      var fd=new FormData(); fd.append('csrf',CSRF); fd.append('accion','generar'); fd.append('ajax','1');
      fd.append('tema',document.getElementById('crTema').value); fd.append('n',nSel);
      var txt=document.getElementById('crTxt'); fd.append('con_texto', (txt && txt.checked) ? '1' : '0');
      post(fd).then(function(d){
        if(d&&d.ok&&d.url){ location.href=d.url; }
        else { ovHide(); go.disabled=false; say((d&&d.err)||'No se pudo crear.'); }
      }).catch(function(){ ovHide(); go.disabled=false; say('Error de conexión.'); });
    });
  }

  // ── EDITAR ──
  var track=document.getElementById('crTrack');
  if(track){
    // Dots de swipe
    var slides=[].slice.call(track.querySelectorAll('.cr-slide'));
    var dots=document.getElementById('crDots');
    slides.forEach(function(_,i){ var d=document.createElement('i'); if(i===0)d.classList.add('on'); dots.appendChild(d); });
    var dotEls=[].slice.call(dots.children);
    track.addEventListener('scroll',function(){
      var i=Math.round(track.scrollLeft/(track.scrollWidth/slides.length));
      dotEls.forEach(function(d,k){ d.classList.toggle('on',k===Math.min(i,slides.length-1)); });
    },{passive:true});

    // Guardar caption (al perder foco)
    var cap=document.getElementById('crCap');
    if(cap) cap.addEventListener('blur',function(){
      var fd=new FormData(); fd.append('csrf',CSRF); fd.append('accion','caption'); fd.append('ajax','1'); fd.append('caption',cap.value);
      post(fd).then(function(d){ if(d&&d.ok) say('Pie de foto guardado'); });
    });

    // Subir foto por slide
    track.querySelectorAll('.cr-up input').forEach(function(inp){
      inp.addEventListener('change',function(){
        if(!inp.files||!inp.files[0]) return;
        var sid=inp.dataset.slide, cell=track.querySelector('.cr-slide[data-slide="'+sid+'"] .cr-img');
        var fd=new FormData(); fd.append('csrf',CSRF); fd.append('accion','subir'); fd.append('ajax','1'); fd.append('slide',sid); fd.append('foto',inp.files[0]);
        say('Subiendo…');
        post(fd).then(function(d){
          if(d&&d.ok&&d.url){ cell.innerHTML='<span class="cr-num">'+(cell.querySelector('.cr-num')?cell.querySelector('.cr-num').textContent:'')+'</span><img src="'+d.url+'" alt="">'; say('Foto lista'); }
          else say((d&&d.err)||'No se pudo subir.');
        }).catch(function(){ say('Error al subir.'); });
      });
    });

    // El parecer del dueño → el Guionista reescribe
    var fbGo=document.getElementById('crFbGo'), fbTa=document.getElementById('crFb');
    if(fbGo) fbGo.addEventListener('click',function(){
      var msg=(fbTa.value||'').trim(); if(!msg){ say('Escribe tu comentario primero.'); return; }
      fbGo.disabled=true;
      ovShow('El Guionista está ajustando…','Reescribe el carrusel con tu parecer, en tu voz.');
      var fd=new FormData(); fd.append('csrf',CSRF); fd.append('accion','ajustar'); fd.append('ajax','1'); fd.append('feedback',msg);
      post(fd).then(function(d){
        ovHide(); fbGo.disabled=false;
        if(d&&d.ok){
          if(cap && typeof d.caption==='string') cap.value=d.caption;
          (d.slides||[]).forEach(function(s){
            var sl=track.querySelector('.cr-slide[data-slide="'+s.id+'"]'); if(!sl) return;
            var t=sl.querySelector('.cr-tit'); if(t) t.textContent=s.titulo||('Slide '+s.orden);
            var x=sl.querySelector('.cr-txt');
            if(s.visual){ if(x) x.textContent=s.visual; else { var p=document.createElement('p'); p.className='cr-txt'; p.textContent=s.visual; sl.querySelector('.cr-body').insertBefore(p, sl.querySelector('.cr-up')); } }
          });
          fbTa.value=''; say('Listo — el Guionista lo ajustó. Si cambió mucho, regenera el arte.');
        } else say((d&&d.err)||'No se pudo ajustar.');
      }).catch(function(){ ovHide(); fbGo.disabled=false; say('Error de conexión.'); });
    });

    // La IA crea el arte de todo (async + poll)
    var iaBtn=document.getElementById('crIA');
    var polling=false;
    function poll(){
      fetch(location.pathname+'?marca='+MARCA+'&id='+CID+'&ajax=estado').then(function(r){return r.json();}).then(function(d){
        if(!d||!d.ok) return;
        (d.slides||[]).forEach(function(s){
          if(s.img){ var cell=track.querySelector('.cr-slide[data-slide="'+s.id+'"] .cr-img');
            if(cell && !cell.querySelector('img')){ var num=cell.querySelector('.cr-num'); cell.innerHTML=(num?num.outerHTML:'')+'<img src="'+s.img+'" alt="">'; } }
        });
        if(d.completo){ polling=false; iaBtn.disabled=false; iaBtn.innerHTML='<?= '' ?>Arte listo'; say('¡Arte del carrusel listo!'); }
        else if(polling){ setTimeout(poll,3500); }
      }).catch(function(){ if(polling) setTimeout(poll,5000); });
    }
    // Toggle "Texto en las imágenes" del editor: muestra/oculta los rótulos en vivo.
    var txt2=document.getElementById('crTxt2');
    if(txt2) txt2.addEventListener('change',function(){ track.classList.toggle('no-txt', !txt2.checked); });
    function lanzarArte(rehacer){
      if(iaBtn) iaBtn.disabled=true;
      var fd=new FormData(); fd.append('csrf',CSRF); fd.append('accion','arte_ia'); fd.append('ajax','1');
      fd.append('con_texto', (txt2 && txt2.checked) ? '1' : '0');
      if(rehacer) fd.append('rehacer','1');
      // Marca TODOS (rehacer) o los sin imagen como "preparando".
      track.querySelectorAll('.cr-slide').forEach(function(sl){ var cell=sl.querySelector('.cr-img'); if(rehacer || !cell.querySelector('img')){ var num=cell.querySelector('.cr-num'); cell.innerHTML=(num?num.outerHTML:'')+'<div class="cr-prep" data-prep><div class="sp"></div>Preparando el arte…</div>'; } });
      post(fd).then(function(d){
        if(d&&d.ok){ say('El corillo está creando tu carrusel — te aviso por la campanita.'); polling=true; setTimeout(poll,3500); }
        else { if(iaBtn) iaBtn.disabled=false; say((d&&d.err)||'No se pudo.'); }
      }).catch(function(){ if(iaBtn) iaBtn.disabled=false; say('Error de conexión.'); });
    }
    if(iaBtn){
      // Si ya hay slides "preparando" al cargar, arranca el poll.
      if(track.querySelector('[data-prep] .sp')){ polling=true; poll(); }
      iaBtn.addEventListener('click',function(){ lanzarArte(false); });
    }
    var reBtn=document.getElementById('crRehacer');
    if(reBtn) reBtn.addEventListener('click',function(){
      if(!confirm('¿Rehacer el arte de todos los slides desde cero? Se reemplaza el arte actual.')) return;
      lanzarArte(true);
    });

    // Publicar (background + notificación)
    var okBtn=document.getElementById('crOK');
    if(okBtn) okBtn.addEventListener('click',function(){
      okBtn.disabled=true;
      var fd=new FormData(); fd.append('csrf',CSRF); fd.append('accion','publicar'); fd.append('ajax','1');
      post(fd).then(function(d){
        if(d&&d.ok&&d.publicando){
          ovShow('Publicando tu carrusel…','Lo estamos soltando a tus redes. Te avisamos por la campanita cuando salga — puedes seguir en lo tuyo.');
          setTimeout(function(){ location.href=BASE+'/index.php?marca='+MARCA; },2600);
        } else if(d&&d.err==='no_conectado'){
          okBtn.disabled=false; say('Conecta Instagram/Facebook primero.'); if(d.url) setTimeout(function(){location.href=d.url;},1100);
        } else { okBtn.disabled=false; say((d&&d.err)||'No se pudo publicar.'); }
      }).catch(function(){ okBtn.disabled=false; say('Error de conexión.'); });
    });
  }
})();
</script>

<?php require __DIR__ . '/_shell_foot.php'; ?>
