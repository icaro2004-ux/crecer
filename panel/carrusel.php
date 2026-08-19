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
    // Estado del POST (publicando → publicado/fallido) + permalink si ya salió:
    // el poll de después de Publicar cuenta la verdad en vez de botar a inicio.
    $pe = $pdo->prepare("SELECT estado FROM crecer_contenido WHERE id=? AND marca_id=?");
    $pe->execute([$cid, $marca_id]);
    $post_estado = (string)$pe->fetchColumn();
    $permalink = null;
    try {
        $pl = $pdo->prepare("SELECT permalink FROM crecer_publicaciones
                             WHERE contenido_id=? AND estado='ok' AND permalink IS NOT NULL AND permalink <> ''
                             ORDER BY id DESC LIMIT 1");
        $pl->execute([$cid]);
        $permalink = $pl->fetchColumn() ?: null;
    } catch (Throwable $e) {}
    echo json_encode(['ok' => true] + $est + ['slides' => $out, 'post_estado' => $post_estado, 'permalink' => $permalink], JSON_UNESCAPED_UNICODE);
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

    // Crear DESDE LAS FOTOS del dueño (fotos-primero): van TAL CUAL como
    // slides; el Guionista las MIRA y arma orden + caption + beats alrededor.
    if ($accion === 'generar_desde_fotos') {
        if (!$carr_ok) $jout(['ok' => false, 'err' => 'El carrusel aún no está activo (falta correr la migración en la base de datos).']);
        @ignore_user_abort(true);
        // Mismo límite que el carrusel normal: 1 por semana (admin exento).
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
        $files = $_FILES['fotos'] ?? null;
        $nf = ($files && isset($files['name']) && is_array($files['name'])) ? count($files['name']) : 0;
        if ($nf < 2) $jout(['ok' => false, 'err' => 'Sube al menos 2 fotos (hasta 8).']);
        if ($nf > 8) $jout(['ok' => false, 'err' => 'Máximo 8 fotos por carrusel.']);
        $dir = rtrim(UPLOADS_PATH, '/\\') . "/marca_{$marca_id}/fotos";
        @mkdir($dir, 0775, true);
        $fotos = [];
        for ($i = 0; $i < $nf; $i++) {
            if (($files['error'][$i] ?? 1) !== UPLOAD_ERR_OK) $jout(['ok' => false, 'err' => 'Una foto no se pudo leer. Intenta de nuevo.']);
            $info = @getimagesize($files['tmp_name'][$i]);
            $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$info['mime'] ?? ''] ?? null;
            if (!$ext || (int)$files['size'][$i] > 12*1024*1024) $jout(['ok' => false, 'err' => 'Fotos JPG/PNG/WebP de hasta 12 MB cada una.']);
            $fn = 'carr_' . uniqid() . '.' . $ext;
            if (!move_uploaded_file($files['tmp_name'][$i], $dir . '/' . $fn)) $jout(['ok' => false, 'err' => 'No se pudo guardar una foto.']);
            // Los ojos del Guionista: la foto misma (el navegador ya la achicó a
            // ~1440px JPEG, así que cabe de sobra en la llamada al modelo).
            $b64 = null;
            if ((int)$files['size'][$i] <= 4*1024*1024) {
                $b64 = base64_encode((string)file_get_contents($dir . '/' . $fn));
            }
            $fotos[] = ['url' => rtrim(UPLOADS_URL, '/') . "/marca_{$marca_id}/fotos/" . $fn, 'b64' => $b64];
        }
        $contexto = mb_substr(trim((string)($_POST['contexto'] ?? '')), 0, 300);
        $r = carrusel_desde_fotos($pdo, $marca_id, $fotos, $contexto);
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
        // CUOTA MENSUAL: cada slide que la IA pinte consume 1 de las 40 del mes.
        $imgq = img_cuota_estado($pdo, $marca_id, ($usuario['rol'] ?? '') === 'admin');
        $pend = 0;
        foreach (carrusel_slides($pdo, $cid) as $s) { if (trim((string)$s['grafica_path']) === '') $pend++; }
        if (!$imgq['exento'] && $pend > $imgq['restantes']) {
            $jout(['ok' => false, 'err' => "Pintar este carrusel usa {$pend} imágenes y este mes te quedan {$imgq['restantes']} de {$imgq['limite']} (se renuevan el {$imgq['reset']}). Sube tus fotos a los slides — esas no gastan."]);
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

    // ── LA BIBLIOTECA, POR SLIDE ────────────────────────────────────────────
    //  Lo real gana siempre: antes de pintar nada, se le enseña al dueño lo que
    //  YA tiene. No gasta cuota de imágenes y es su negocio de verdad.
    if ($accion === 'biblioteca') {
        $out = [];
        try {
            $q = $pdo->prepare("SELECT id, archivo, nombre, nota FROM crecer_activos
                                 WHERE marca_id=? AND tipo IN ('imagen','foto') AND estado='activo'
                                 ORDER BY id DESC LIMIT 60");
            $q->execute([$marca_id]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $out[] = ['id' => (int)$f['id'], 'url' => (string)$f['archivo'],
                          'nombre' => (string)($f['nombre'] ?? ''), 'nota' => (string)($f['nota'] ?? '')];
            }
        } catch (Throwable $e) {}
        $jout(['ok' => true, 'fotos' => $out]);
    }

    // Usar una foto de la Biblioteca en un slide (sin copiarla: es la misma).
    if ($accion === 'usar_foto' && $cid) {
        $sid = (int)($_POST['slide'] ?? 0);
        $fid = (int)($_POST['foto'] ?? 0);
        $own = $pdo->prepare("SELECT id FROM crecer_carrusel WHERE id=? AND contenido_id=? AND marca_id=?");
        $own->execute([$sid, $cid, $marca_id]);
        if (!$own->fetchColumn()) $jout(['ok' => false, 'err' => 'Slide no encontrado.']);
        $qf = $pdo->prepare("SELECT archivo FROM crecer_activos WHERE id=? AND marca_id=? AND tipo IN ('imagen','foto') AND estado='activo'");
        $qf->execute([$fid, $marca_id]);
        $url = (string)$qf->fetchColumn();
        if ($url === '') $jout(['ok' => false, 'err' => 'Esa foto ya no está en tu biblioteca.']);
        $pdo->prepare("UPDATE crecer_carrusel SET grafica_path=?, img_estado='ok', updated_at=NOW() WHERE id=? AND contenido_id=?")
            ->execute([$url, $sid, $cid]);
        // Queda anotado que esta foto se usó: el corillo no la repite enseguida.
        try {
            $pdo->prepare("INSERT INTO crecer_visual_huella (marca_id, contenido_id, lente, sujeto, composicion, escenario, resumen)
                           VALUES (?,?,?,'','','','foto de la biblioteca en un carrusel')")
                ->execute([$marca_id, $cid, 'foto:' . $fid]);
        } catch (Throwable $e) {}
        $jout(['ok' => true, 'url' => $url]);
    }

    // ── APROBAR Y CALENDARIZAR ──────────────────────────────────────────────
    //  El final del wizard. La fecha la propone la IA según la meta (misma
    //  fuente que usa el motor), y el dueño la puede cambiar.
    if ($accion === 'programar' && $cid) {
        require_once __DIR__ . '/../includes/meta_ejecutar.php';
        $est = carrusel_estado($pdo, $cid);
        if (($est['total'] ?? 0) < 2)  $jout(['ok' => false, 'err' => 'Un carrusel necesita al menos 2 slides.']);
        if (($est['listos'] ?? 0) < 2) $jout(['ok' => false, 'err' => 'Faltan imágenes: escoge de tu biblioteca, sube las tuyas o deja que la IA las pinte.']);
        $cuando = trim((string)($_POST['cuando'] ?? ''));
        if ($cuando === '' || !strtotime($cuando)) {
            $cuando = meta_fecha_sugerida($pdo, $marca_id, 1)['fecha'];
        }
        $cuando = date('Y-m-d H:i:00', strtotime($cuando));
        // Portada: el primer slide con imagen.
        $cover = '';
        foreach (carrusel_slides($pdo, $cid) as $s) { if (trim((string)$s['grafica_path']) !== '') { $cover = (string)$s['grafica_path']; break; } }
        $pdo->prepare("UPDATE crecer_contenido SET estado='programado', fecha_programada=?, grafica_path=COALESCE(NULLIF(grafica_path,''),?), updated_at=NOW()
                        WHERE id=? AND marca_id=?")
            ->execute([$cuando, $cover, $cid, $marca_id]);
        // Si la pieza pertenece a una jugada, la jugada se entera sola.
        try {
            $qt = $pdo->prepare("SELECT tactica_id FROM crecer_contenido WHERE id=? AND marca_id=?");
            $qt->execute([$cid, $marca_id]);
            $tid = (int)$qt->fetchColumn();
            if ($tid) { $t = jugada_por_id($pdo, $tid, $marca_id); if ($t) jugada_sincronizar($pdo, $t); }
        } catch (Throwable $e) {}
        $jout(['ok' => true, 'cuando' => $cuando,
               'texto' => 'Calendarizado para ' . fecha_humana_es($cuando)]);
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
  /* ── EL WIZARD ─────────────────────────────────────────────────────────
     Móvil: una pantalla a la vez, la acción SIEMPRE a la mano (barra pegada
     abajo, pulgar). Desktop: la misma secuencia con los 4 pasos a la vista y
     la acción al final del bloque, que ahí sí hay espacio para leer entero. */
  .wz{max-width:760px;margin:0 auto}
  .wz-bar{height:4px;background:var(--line);border-radius:99px;overflow:hidden;margin-bottom:10px}
  .wz-bar i{display:block;height:100%;width:25%;border-radius:99px;
    background:linear-gradient(90deg,#FF6B3D,#EF4375);transition:width .35s cubic-bezier(.22,1,.36,1)}
  .wz-pasos{display:flex;gap:6px;list-style:none;margin:0 0 18px;padding:0;counter-reset:p}
  .wz-pasos li{flex:1;font-size:11px;font-weight:800;letter-spacing:.02em;color:#b3aca4;
    text-transform:uppercase;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .wz-pasos li.on{color:var(--magenta,#EF4375)}
  .wz-pasos li.ya{color:#0a6a4a}
  .wz-p{display:none;animation:wzIn .3s cubic-bezier(.22,1,.36,1)}
  .wz-p.on{display:block}
  @keyframes wzIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}

  /* La historia en texto, numerada: se lee de corrido antes de ver imágenes. */
  .wz-hist{list-style:none;margin:14px 0 0;padding:0;display:flex;flex-direction:column;gap:8px}
  .wz-hist li{display:flex;gap:11px;align-items:flex-start;background:var(--card);
    border:1px solid var(--line);border-radius:13px;padding:12px 13px}
  .wz-hist .n{flex:none;width:24px;height:24px;border-radius:7px;background:#f4f1ec;color:#6b6560;
    font-size:12px;font-weight:800;display:inline-flex;align-items:center;justify-content:center}
  .wz-hist b{display:block;font-size:14.5px;line-height:1.3}
  .wz-hist small{display:block;color:var(--muted);font-size:12.5px;line-height:1.45;margin-top:3px}

  /* Las tres fuentes de imagen, por slide. */
  .cr-fuentes{display:flex;gap:8px;margin-top:10px}
  .cr-fu{flex:1;display:inline-flex;align-items:center;justify-content:center;gap:7px;cursor:pointer;
    background:#fff;border:1.5px solid var(--line);border-radius:11px;padding:10px 8px;
    font-family:inherit;font-weight:800;font-size:12.5px;color:var(--tinta);white-space:nowrap}
  .cr-fu svg{width:14px;height:14px;flex:none}
  .cr-fu input{display:none}
  .cr-fu:active{transform:scale(.97)}
  .cr-fu.bib{border-color:#cfe9e6;color:#00827e}

  /* Preview: el carrusel como se desliza de verdad. */
  .wz-prev{display:flex;gap:12px;overflow-x:auto;scroll-snap-type:x mandatory;
    -webkit-overflow-scrolling:touch;padding:4px 0 10px}
  .wz-prev img{scroll-snap-align:center;flex:none;width:min(78vw,300px);aspect-ratio:1/1;
    object-fit:cover;border-radius:16px;border:1px solid var(--line);background:#f3f1f4}
  .wz-prevcap{white-space:pre-wrap;font-size:14px;line-height:1.55;color:var(--tinta);
    background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px;margin-bottom:16px}

  /* Cuándo sale. */
  .wz-cuando{display:flex;flex-direction:column;gap:12px;margin:14px 0 18px}
  .wz-prop{background:#fff6f8;border:1.5px solid #f7cdd9;border-radius:14px;padding:14px 15px}
  .wz-prop .lbl{display:block;font-size:11px;font-weight:800;letter-spacing:.12em;
    text-transform:uppercase;color:var(--magenta,#EF4375);margin-bottom:4px}
  .wz-prop b{display:block;font-size:19px;line-height:1.25}
  .wz-prop small{display:block;color:#6b6560;font-size:12.5px;line-height:1.5;margin-top:5px}
  .wz-fecha{display:block;background:var(--card);border:1px solid var(--line);border-radius:14px;padding:12px 14px}
  .wz-fecha span{display:block;font-size:12px;font-weight:800;color:var(--muted);margin-bottom:7px}
  .wz-fecha input{width:100%;font-family:inherit;font-size:15px;padding:10px;border:1.5px solid var(--line);
    border-radius:10px;background:#fff;color:var(--tinta)}

  /* Navegación. */
  .wz-nav{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:18px}
  .wz-nav .wz-go{display:inline-flex;align-items:center;gap:8px;border:0;cursor:pointer;text-decoration:none;
    font-family:inherit;font-weight:800;font-size:15px;color:#fff;padding:14px 22px;border-radius:99px;
    background:linear-gradient(135deg,#FF6B3D,#EF4375);box-shadow:0 12px 26px -14px rgba(239,67,117,.7)}
  .wz-nav .wz-go[disabled]{opacity:.45;box-shadow:none;cursor:not-allowed}
  .wz-nav .wz-back{background:none;border:0;cursor:pointer;font-family:inherit;font-weight:800;
    font-size:14px;color:var(--muted);padding:12px 6px}
  .wz-nav.fin{justify-content:center}
  .wz-fin{text-align:center;padding:22px 0}
  .wz-fin .fin-ok{width:64px;height:64px;margin:0 auto 14px;border-radius:50%;background:#e6f7f0;
    color:#0a6a4a;display:flex;align-items:center;justify-content:center}
  .wz-fin .fin-ok svg{width:32px;height:32px}

  @media (max-width:719px){
    /* El botón de Ayuda flota justo donde cae la acción del wizard: se sube,
       porque en el móvil la acción del paso manda sobre el soporte. */
    .ay-fab{bottom:calc(env(safe-area-inset-bottom,0px) + 148px)!important}
    /* La acción vive pegada al pulgar, no al final del scroll. */
    .wz-nav{position:sticky;bottom:calc(env(safe-area-inset-bottom,0px) + 74px);z-index:5;
      background:linear-gradient(180deg,rgba(253,252,250,0),var(--crema,#FDFCFA) 34%);
      padding:12px 0 8px;margin-top:14px}
    .wz-nav .wz-go{flex:1;justify-content:center}
    .wz-pasos li{font-size:10px}
    .cr-fuentes{flex-direction:column}
  }
  @media (min-width:720px){
    .wz-cuando{flex-direction:row;align-items:stretch}
    .wz-cuando>*{flex:1}
    .wz-prev img{width:280px}
  }

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
  .cr-fu input{display:none}
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
    <?php
      $imgq_c = null;
      try {
          if (function_exists('img_cuota_estado')) {
              $imgq_c = img_cuota_estado($pdo, $marca_id, (($usuario['rol'] ?? '') === 'admin'));
          }
      } catch (Throwable $e) { $imgq_c = null; }
    ?>
    <?php if ($imgq_c && !$imgq_c['exento']): ?>
    <p class="cr-note" style="margin-top:6px">Si la IA pinta los slides, <b>cada slide consume 1 imagen</b> de tu plan — vas <b><?= (int)$imgq_c['usadas'] ?> de <?= (int)$imgq_c['limite'] ?></b> este mes (renuevan el <?= $imgq_c['reset'] ?>). Con tus fotos, no gastas.</p>
    <?php endif; ?>
  </div>

  <div class="cr-new" style="margin-top:14px">
    <label class="cr-lbl">O con TUS fotos — van tal cual</label>
    <p style="margin:2px 0 12px;color:var(--muted);font-size:13px;line-height:1.5">Sube de 2 a 8 fotos tuyas. El Guionista <b>las mira</b>, elige el mejor orden para contar la historia, y escribe el caption en tu voz — <b>sin tocar tus imágenes</b>. Si escribiste el tema arriba, lo usa de contexto.</p>
    <label class="cr-go" style="margin-top:0;background:linear-gradient(135deg,var(--teal),#0a7d76);cursor:pointer"><?= ico('camera') ?> Elegir mis fotos
      <input type="file" id="crFotos" accept="image/png,image/jpeg,image/webp" multiple style="display:none">
    </label>
  </div>
  <?php endif; ?>
<?php else: ?>
  <?php /* ══ EL WIZARD ═══════════════════════════════════════════════════════
       Una pantalla a la vez: la historia → las imágenes → cómo se ve → cuándo
       sale. Antes era una sola pantalla larga con todo encima (caption, slides,
       feedback, botones) y el dueño no sabía por dónde empezar. */ ?>
  <?php
    require_once __DIR__ . '/../includes/meta_ejecutar.php';
    $sug = meta_fecha_sugerida($pdo, $marca_id, 1);
    $jugada_id = 0;
    try {
        $qj = $pdo->prepare("SELECT tactica_id FROM crecer_contenido WHERE id=? AND marca_id=?");
        $qj->execute([$cid, $marca_id]); $jugada_id = (int)$qj->fetchColumn();
    } catch (Throwable $e) {}
  ?>
  <div class="wz" id="wz" data-cid="<?= $cid ?>" data-jugada="<?= $jugada_id ?>">
    <div class="wz-bar"><i id="wzBar"></i></div>
    <ol class="wz-pasos" id="wzPasos">
      <li class="on">La historia</li><li>Las imágenes</li><li>Cómo se ve</li><li>Cuándo sale</li>
    </ol>

  <!-- ── PASO 1 · LA HISTORIA ── -->
  <section class="wz-p on" data-paso="1">
  <h1 class="cr-h"><?= ico('list') ?> La historia que va a contar</h1>
  <p class="cr-sub">Esto es lo que el Guionista escribió para tu carrusel. Léelo, cámbiale lo que quieras, y seguimos.</p>

  <div class="cr-cap">
    <div class="cc-h">Pie de foto</div>
    <textarea id="crCap" data-cid="<?= $cid ?>" placeholder="El texto que acompaña el carrusel…"><?= $h($caption) ?></textarea>
  </div>

  <ol class="wz-hist">
    <?php foreach ($slides as $s): $v = carrusel_slide_visual((string)$s['idea']); ?>
      <li>
        <span class="n"><?= (int)$s['orden'] ?></span>
        <div>
          <?php /* Lo que el slide DICE va primero; cómo se verá, debajo. Al revés
                   el dueño leía descripciones de arte y no su propia historia. */ ?>
          <?php if ($v['copy'] !== ''): ?><b><?= $h($v['copy']) ?></b><?php endif; ?>
          <?php if ($v['visual'] !== ''): ?><small>Se verá: <?= $h($v['visual']) ?></small><?php endif; ?>
        </div>
      </li>
    <?php endforeach; ?>
  </ol>

  <div class="cr-fb">
    <div class="cc-h">¿Qué le cambiarías? — dile al Guionista</div>
    <textarea id="crFb" placeholder="Ej: hazlo más corto · empieza con una pregunta · menciona que hacemos delivery · más atrevido…"></textarea>
    <button type="button" class="cr-fbgo" id="crFbGo"><?= ico('sparkles') ?> Ajustar con el Guionista</button>
  </div>

  <div class="wz-nav"><span></span>
    <button type="button" class="wz-go" data-ir="2">Vamos con esta historia <?= ico('send') ?></button>
  </div>
  </section>

  <!-- ── PASO 2 · LAS IMÁGENES ── -->
  <section class="wz-p" data-paso="2">
  <h1 class="cr-h"><?= ico('image') ?> Las imágenes</h1>
  <p class="cr-sub">Una por slide, contando esa parte de la historia. <b>Lo tuyo siempre gana:</b> escoge de tu biblioteca o sube una foto. Lo que dejes vacío, lo pinta el corillo.</p>

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
        <div class="cr-fuentes">
          <button type="button" class="cr-fu bib" data-slide="<?= (int)$s['id'] ?>"><?= ico('image') ?> De mi biblioteca</button>
          <label class="cr-fu"><?= ico('upload') ?> Subir foto
            <input type="file" accept="image/png,image/jpeg,image/webp" data-slide="<?= (int)$s['id'] ?>">
          </label>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="cr-dots" id="crDots"></div>

  <label class="cr-txt-toggle" style="margin:0 0 14px"><input type="checkbox" id="crTxt2" <?= $edit_con_texto ? 'checked' : '' ?>> <span>Texto en las imágenes <small>(cada slide muestra su titular — aplica al crear el arte)</small></span></label>

  <div class="cr-acts">
    <button type="button" class="cr-b ia" id="crIA"><?= ico('sparkles') ?> Que el corillo pinte las que faltan</button>
  </div>
  <div style="text-align:center"><button type="button" class="cr-rehacer" id="crRehacer"><?= ico('refresh') ?> Rehacer el arte desde cero</button></div>
  <p class="cr-note">Cuando la IA pinta, puede tardar — te avisamos por la <b>campanita</b>. Tus fotos quedan al instante y <b>no gastan</b> de tu cuota.</p>

  <div class="wz-nav">
    <button type="button" class="wz-back" data-ir="1">Atrás</button>
    <button type="button" class="wz-go" data-ir="3" id="wzA3">Siguiente <?= ico('send') ?></button>
  </div>
  </section>

  <!-- ── PASO 3 · CÓMO SE VE ── -->
  <section class="wz-p" data-paso="3">
  <h1 class="cr-h"><?= ico('eye') ?> Así se ve</h1>
  <p class="cr-sub">Como lo va a ver la gente en Instagram: desliza. En Facebook sale como álbum.</p>
  <div class="wz-prev" id="wzPrev"></div>
  <div class="wz-prevcap" id="wzPrevCap"></div>
  <div class="wz-nav">
    <button type="button" class="wz-back" data-ir="2">Atrás</button>
    <button type="button" class="wz-go" data-ir="4">Se ve bien <?= ico('send') ?></button>
  </div>
  </section>

  <!-- ── PASO 4 · CUÁNDO SALE ── -->
  <section class="wz-p" data-paso="4">
  <h1 class="cr-h"><?= ico('calendar') ?> ¿Cuándo sale?</h1>
  <p class="cr-sub">La fecha la propone tu corillo mirando tu meta. Si te sirve otra, cámbiala.</p>

  <div class="wz-cuando">
    <div class="wz-prop">
      <span class="lbl">Te propongo</span>
      <b id="wzCuandoTxt"><?= $h(fecha_humana_es($sug['fecha'])) ?></b>
      <small><?= $h($sug['porque']) ?></small>
    </div>
    <label class="wz-fecha">
      <span>O escoge tú</span>
      <input type="datetime-local" id="crCuando" value="<?= $h(date('Y-m-d\TH:i', strtotime($sug['fecha']))) ?>">
    </label>
  </div>

  <div class="cr-acts">
    <button type="button" class="cr-b ok" id="crProg"><?= ico('calendar') ?> Aprobar y calendarizar</button>
  </div>
  <div style="text-align:center"><button type="button" class="cr-rehacer" id="crOK"><?= ico('send') ?> O publicarlo ahora mismo</button></div>
  <div class="cr-fbnote"><b>Nota:</b> en Instagram sale como carrusel deslizable; en Facebook, como álbum de fotos (FB no tiene el swipe de IG).</div>

  <div class="wz-nav"><button type="button" class="wz-back" data-ir="3">Atrás</button><span></span></div>
  </section>

  <!-- ── EL CIERRE ── -->
  <section class="wz-p wz-fin" data-paso="5">
    <div class="fin-ok"><?= ico('check-circle') ?></div>
    <h1 class="cr-h" id="wzFinH">Listo</h1>
    <p class="cr-sub" id="wzFinP"></p>
    <div class="wz-nav fin">
      <?php if ($jugada_id): ?>
        <a class="wz-go" href="<?= $BASE ?>/meta.php?marca=<?= $marca_id ?>">Volver a tu meta <?= ico('send') ?></a>
      <?php else: ?>
        <a class="wz-go" href="<?= $BASE ?>/aprobar2.php?marca=<?= $marca_id ?>&tab=programados">Ver tus posts <?= ico('send') ?></a>
      <?php endif; ?>
    </div>
  </section>
  </div>
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

  // ── CREAR CON MIS FOTOS (fotos-primero): tus fotos tal cual; el Guionista
  //    las mira (vistas previas JPEG hechas aquí mismo) y arma la historia. ──
  var crF=document.getElementById('crFotos');
  if(crF) crF.addEventListener('change', function(){
    var fs=[].slice.call(crF.files||[]); crF.value='';
    if(fs.length<2){ say('Elige al menos 2 fotos.'); return; }
    if(fs.length>8){ say('Máximo 8 fotos por carrusel.'); return; }
    ovShow('El Guionista está mirando tus fotos…','Eligiendo el orden y escribiendo la historia en tu voz.');
    // Las fotos del celular pesan 8-12MB — el navegador las ACHICA aquí a
    // 1440px JPEG (lo que IG muestra de todos modos) y sube ESAS: el paquete
    // pasa de ~50MB a ~2MB y ningún límite del servidor lo mata.
    var blobs=new Array(fs.length), left=fs.length, fallo=false;
    fs.forEach(function(f,ix){
      var img=new Image(), url=URL.createObjectURL(f);
      function fin(b){
        blobs[ix]=b||null; if(!b) fallo=true;
        URL.revokeObjectURL(url); if(--left===0) manda();
      }
      img.onload=function(){
        try{
          var vw=img.naturalWidth||1440, vh=img.naturalHeight||1440;
          var w=Math.min(1440,vw), h=Math.round(w*vh/vw);
          var c=document.createElement('canvas'); c.width=w; c.height=h;
          c.getContext('2d').drawImage(img,0,0,w,h);
          c.toBlob(function(b){ fin(b); }, 'image/jpeg', .85);
        }catch(e){ fin(null); }
      };
      img.onerror=function(){ fin(null); };
      img.src=url;
    });
    function manda(){
      if(fallo){ ovHide(); say('Una foto no se pudo leer — ¿es HEIC? Conviértela a JPG e intenta.'); return; }
      var fd=new FormData(); fd.append('csrf',CSRF); fd.append('ajax','1'); fd.append('accion','generar_desde_fotos');
      var t=document.getElementById('crTema'); fd.append('contexto', t ? t.value.trim() : '');
      blobs.forEach(function(b,ix){ fd.append('fotos[]', b, 'foto'+(ix+1)+'.jpg'); });
      post(fd).then(function(d){
        if(d&&d.ok&&d.url){ location.href=d.url; }
        else { ovHide(); say((d&&d.err)||'No se pudo crear.'); }
      }).catch(function(){ ovHide(); say('Se cayó la conexión — intenta otra vez.'); });
    }
  });

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
    track.querySelectorAll('.cr-fu input').forEach(function(inp){
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
            if(s.visual){ if(x) x.textContent=s.visual; else { var p=document.createElement('p'); p.className='cr-txt'; p.textContent=s.visual; sl.querySelector('.cr-body').insertBefore(p, sl.querySelector('.cr-fuentes')); } }
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
          pubCamino();   // la verdad en pantalla: en camino → publicado/fallido, con link
        } else if(d&&d.err==='no_conectado'){
          okBtn.disabled=false; say('Conecta Instagram/Facebook primero.'); if(d.url) setTimeout(function(){location.href=d.url;},1100);
        } else { okBtn.disabled=false; say((d&&d.err)||'No se pudo publicar.'); }
      }).catch(function(){ okBtn.disabled=false; say('Error de conexión.'); });
    });
  }

  // ── DESPUÉS DE PUBLICAR, LA VERDAD: primero "en camino", y el poll (~90s)
  //    lo convierte en "¡Publicado!" con el link real, o en el aviso honesto
  //    de que quedó guardado para reintentar. Nada de botarte a inicio mudo. ──
  function cardOv(html){
    var bx=document.querySelector('#crOv .bx');
    if(bx) bx.innerHTML=html;
    document.getElementById('crOv').classList.add('on');
  }
  function pubCamino(){
    cardOv('<div class="sp"></div><h3>En camino a tus redes</h3><p>El corillo lo está publicando — IG como carrusel, FB como álbum. Esto toma un momento…</p>');
    var tries=0;
    var t=setInterval(function(){
      tries++;
      fetch(location.pathname+'?marca='+MARCA+'&id='+CID+'&ajax=estado').then(function(r){return r.json();}).then(function(d){
        var e=(d&&d.post_estado)||'';
        if(e==='publicado'){
          clearInterval(t);
          cardOv('<h3>¡Publicado!</h3><p>Tu carrusel ya está en la calle.</p>'
            +'<div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-top:14px">'
            +((d.permalink)?'<a class="cr-fbgo" style="text-decoration:none" href="'+d.permalink+'" target="_blank" rel="noopener">Verlo en la red ↗</a>':'')
            +'<button type="button" class="cr-fbgo" onclick="location.href=\''+BASE+'/index.php?marca='+MARCA+'\'">Ir al inicio</button></div>');
        } else if(e==='fallido'){
          clearInterval(t);
          cardOv('<h3>No salió esta vez</h3><p>Tranquilo: tu carrusel quedó <b>guardado</b> — no se perdió. Reintenta cuando quieras.</p>'
            +'<button type="button" class="cr-fbgo" style="margin-top:14px" onclick="location.href=\''+BASE+'/aprobar2.php?tab=listos&marca='+MARCA+'\'">Ver y reintentar</button>');
        } else if(tries>=22){
          clearInterval(t);
          cardOv('<h3>Sigue en camino</h3><p>Está tomando más de lo normal — la campanita te avisa cuando salga a la calle.</p>'
            +'<button type="button" class="cr-fbgo" style="margin-top:14px" onclick="location.href=\''+BASE+'/index.php?marca='+MARCA+'\'">Ir al inicio</button>');
        }
      }).catch(function(){ /* siguiente intento del poll */ });
    }, 4000);
  }
  // ══ EL WIZARD ═══════════════════════════════════════════════════════════
  //  Una pantalla a la vez. No deja pasar del paso de imágenes hasta que haya
  //  con qué publicar: un carrusel sin imágenes no es un carrusel.
  var wz = document.getElementById('wz');
  if (wz) {
    var pasos = wz.querySelectorAll('.wz-p'),
        rotulos = document.querySelectorAll('#wzPasos li'),
        barra = document.getElementById('wzBar');

    function irA(n) {
      pasos.forEach(function (s) { s.classList.toggle('on', +s.dataset.paso === n); });
      rotulos.forEach(function (li, i) {
        li.classList.toggle('on', i === n - 1);
        li.classList.toggle('ya', i < n - 1);
      });
      barra.style.width = Math.min(100, n * 25) + '%';
      if (n === 3) pintarPreview();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    wz.addEventListener('click', function (e) {
      var b = e.target.closest('[data-ir]'); if (!b || b.disabled) return;
      irA(+b.dataset.ir);
    });

    // Cuántos slides tienen imagen: es lo que habilita seguir.
    function conImagen() { return wz.querySelectorAll('#crTrack .cr-img img').length; }
    var a3 = document.getElementById('wzA3');
    function revisar() {
      if (!a3) return;
      var n = conImagen();
      a3.disabled = n < 2;
      a3.title = n < 2 ? 'Necesitas al menos 2 slides con imagen' : '';
    }
    revisar();
    new MutationObserver(revisar).observe(document.getElementById('crTrack'), { childList: true, subtree: true });

    // Paso 3: el carrusel como se desliza, con su pie de foto.
    function pintarPreview() {
      var cont = document.getElementById('wzPrev'); cont.innerHTML = '';
      wz.querySelectorAll('#crTrack .cr-slide').forEach(function (sl) {
        var im = sl.querySelector('.cr-img img'); if (!im) return;
        var c = document.createElement('img'); c.src = im.src; c.alt = ''; cont.appendChild(c);
      });
      if (!cont.children.length) cont.innerHTML = '<p style="color:var(--muted);font-size:14px">Todavía no hay imágenes.</p>';
      document.getElementById('wzPrevCap').textContent = document.getElementById('crCap').value || '';
    }

    // ── La biblioteca, por slide ──
    var bibOv = document.createElement('div');
    bibOv.className = 'cr-ov'; bibOv.id = 'bibOv';
    bibOv.innerHTML = '<div class="bx" style="max-width:560px;width:92vw;text-align:left">' +
      '<h3 style="margin:0 0 4px">Tus fotos</h3>' +
      '<p style="margin:0 0 12px;color:#6b6560;font-size:13px">Lo real gana siempre — y no gasta de tu cuota.</p>' +
      '<div id="bibGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(96px,1fr));gap:8px;max-height:52vh;overflow:auto"></div>' +
      '<button type="button" id="bibX" style="margin-top:14px;width:100%;border:0;background:#f4f1ec;color:#231F20;' +
      'font-family:inherit;font-weight:800;font-size:14px;padding:12px;border-radius:11px;cursor:pointer">Cerrar</button></div>';
    document.body.appendChild(bibOv);
    var bibSlide = 0;
    bibOv.addEventListener('click', function (e) { if (e.target === bibOv || e.target.id === 'bibX') bibOv.classList.remove('on'); });

    wz.addEventListener('click', function (e) {
      var b = e.target.closest('.cr-fu.bib'); if (!b) return;
      bibSlide = +b.dataset.slide;
      var grid = document.getElementById('bibGrid');
      grid.innerHTML = '<p style="color:#6b6560;font-size:13px">Buscando en tu biblioteca…</p>';
      bibOv.classList.add('on');
      var fd = new FormData(); fd.append('csrf', CSRF); fd.append('accion', 'biblioteca'); fd.append('ajax', '1');
      post(fd).then(function (d) {
        if (!d || !d.ok || !(d.fotos || []).length) {
          grid.innerHTML = '<p style="color:#6b6560;font-size:13px;grid-column:1/-1">Todavía no tienes fotos en tu biblioteca. ' +
            'Sube una desde este mismo slide, o deja que el corillo pinte esta imagen.</p>';
          return;
        }
        grid.innerHTML = '';
        d.fotos.forEach(function (f) {
          var im = document.createElement('img');
          im.src = f.url; im.alt = f.nombre || ''; im.title = f.nota || f.nombre || '';
          im.style.cssText = 'width:100%;aspect-ratio:1/1;object-fit:cover;border-radius:10px;cursor:pointer;border:2px solid transparent';
          im.addEventListener('click', function () {
            var fd2 = new FormData(); fd2.append('csrf', CSRF); fd2.append('accion', 'usar_foto');
            fd2.append('ajax', '1'); fd2.append('slide', bibSlide); fd2.append('foto', f.id);
            im.style.borderColor = '#EF4375';
            post(fd2).then(function (r) {
              if (!r || !r.ok) { say((r && r.err) || 'No se pudo usar esa foto.'); return; }
              var cell = document.querySelector('#crTrack .cr-slide[data-slide="' + bibSlide + '"] .cr-img');
              if (cell) { var num = cell.querySelector('.cr-num');
                cell.innerHTML = (num ? num.outerHTML : '') + '<img src="' + r.url + '" alt="">'; }
              bibOv.classList.remove('on'); say('Foto puesta en el slide.'); revisar();
            });
          });
          grid.appendChild(im);
        });
      });
    });

    // ── Aprobar y calendarizar ──
    var prog = document.getElementById('crProg');
    if (prog) prog.addEventListener('click', function () {
      prog.disabled = true;
      ovShow('Calendarizando…', 'Lo dejo listo para que salga solo.');
      var fd = new FormData(); fd.append('csrf', CSRF); fd.append('accion', 'programar'); fd.append('ajax', '1');
      fd.append('cuando', (document.getElementById('crCuando') || {}).value || '');
      post(fd).then(function (d) {
        ovHide(); prog.disabled = false;
        if (!d || !d.ok) { say((d && d.err) || 'No se pudo calendarizar.'); return; }
        document.getElementById('wzFinH').textContent = '¡Carrusel calendarizado!';
        document.getElementById('wzFinP').textContent = d.texto +
          (wz.dataset.jugada !== '0' ? '. Tu jugada quedó al día — vuelve a la meta para lo que sigue.' : '.');
        irA(5);
      }).catch(function () { ovHide(); prog.disabled = false; say('Error de conexión.'); });
    });
  }
})();
</script>

<?php require __DIR__ . '/_shell_foot.php'; ?>
