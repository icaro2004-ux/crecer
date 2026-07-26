<?php
// ============================================================
//  CRECER — REELS STUDIO (editor de video inteligente)
//  panel/reels.php  ·  Módulo AISLADO, pestaña aparte.
//  NO edita nada del app existente: solo incluye (lectura) db/auth
//  y el cerebro nuevo (includes/reels.php).
//
//  El cliente sube clips + escoge un preset → el Corillo (Gemini)
//  ve los clips, decide orden/cortes/captions → Shotstack renderiza
//  el reel vertical. Interacción mínima.
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reels.php';
require_once __DIR__ . '/../includes/iconos.php';
requiere_login();
$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }
$marca_id = (int)$marca['id'];
$BASE = '/crecer/panel';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$negocio = $marca['nombre_negocio'] ?? 'tu negocio';

$VID_EXT = ['mp4','mov','webm','m4v'];
$MAX_VID = 100 * 1024 * 1024; // 100 MB por clip

// ── AJAX ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_ok()) { echo json_encode(['ok'=>false,'err'=>'Sesión expiró. Recarga la página.']); exit; }
    $accion = $_POST['accion'] ?? '';

    // ── Crear un reel: guarda clips subidos + de la Biblioteca, encola ──
    if ($accion === 'crear') {
        @set_time_limit(0);
        $preset = in_array($_POST['preset'] ?? '', array_keys(reels_presets()), true) ? $_POST['preset'] : 'vivido';
        $contexto = mb_substr(trim((string)($_POST['contexto'] ?? '')), 0, 300);
        $subtitulos = !empty($_POST['subtitulos']) ? 1 : 0;
        $mus_ok = array_merge(['auto','none'], array_keys(reels_music_catalogo()));
        $musica = in_array($_POST['musica'] ?? '', $mus_ok, true) ? $_POST['musica'] : 'auto';

        $UP_PATH = reels_uploads_path() . "/marca_{$marca_id}/reels";
        $FR_PATH = $UP_PATH . '/frames';
        if (!is_dir($FR_PATH)) @mkdir($FR_PATH, 0775, true);

        $guardar_frame = function(int $clip_id, ?string $fr) use ($FR_PATH) {
            if (is_string($fr) && strpos($fr, 'base64,') !== false) {
                $bin = base64_decode(substr($fr, strpos($fr, 'base64,') + 7), true);
                if ($bin !== false && strlen($bin) > 200) @file_put_contents($FR_PATH . "/{$clip_id}.jpg", $bin);
            }
        };

        // Crear el reel primero (para el id).
        $pdo->prepare("INSERT INTO crecer_reels (marca_id, estado, preset, contexto, subtitulos, musica) VALUES (?, 'borrador', ?, ?, ?, ?)")
            ->execute([$marca_id, $preset, $contexto, $subtitulos, $musica]);
        $reel_id = (int)$pdo->lastInsertId();

        $ord = 0; $guardados = 0; $errores = [];

        // 1) Clips SUBIDOS.
        $files = $_FILES['clips'] ?? null;
        $n = ($files && isset($files['name']) && is_array($files['name'])) ? count($files['name']) : 0;
        for ($i = 0; $i < $n; $i++) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { $errores[] = 'Un clip no se pudo leer.'; continue; }
            $orig = (string)$files['name'][$i];
            $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            $size = (int)$files['size'][$i];
            $tmp  = $files['tmp_name'][$i];
            if (!in_array($ext, $VID_EXT, true)) { $errores[] = "\"{$orig}\": no es video."; continue; }
            if ($size > $MAX_VID) { $errores[] = "\"{$orig}\": muy pesado (máx 100 MB)."; continue; }
            $mime = function_exists('mime_content_type') ? (mime_content_type($tmp) ?: 'video/mp4') : 'video/mp4';
            $fn  = bin2hex(random_bytes(6)) . '.' . $ext;
            $rel = "marca_{$marca_id}/reels/{$fn}";
            if (!@move_uploaded_file($tmp, $UP_PATH . '/' . $fn)) { $errores[] = "\"{$orig}\": no se pudo guardar."; continue; }
            $dur = isset($_POST['dur'][$i]) && $_POST['dur'][$i] !== '' ? (float)$_POST['dur'][$i] : null;
            $pdo->prepare("INSERT INTO crecer_reel_clips (reel_id, orden_subido, orden, archivo, mime, bytes, dur_orig) VALUES (?,?,?,?,?,?,?)")
                ->execute([$reel_id, $ord, $ord, $rel, $mime, $size, $dur]);
            $guardar_frame((int)$pdo->lastInsertId(), $_POST['frame'][$i] ?? '');
            $ord++; $guardados++;
        }

        // 2) Clips de la BIBLIOTECA (referencian el archivo existente, no se copia).
        $lib = json_decode((string)($_POST['lib'] ?? '[]'), true);
        if (is_array($lib)) {
            foreach ($lib as $it) {
                $aid = (int)($it['activo_id'] ?? 0);
                if (!$aid) continue;
                $a = $pdo->prepare("SELECT archivo, mime, bytes FROM crecer_activos WHERE id=? AND marca_id=? AND tipo='video' AND estado='activo'");
                $a->execute([$aid, $marca_id]); $a = $a->fetch(PDO::FETCH_ASSOC);
                if (!$a) { $errores[] = 'Un video de la biblioteca no se encontró.'; continue; }
                $dur = isset($it['dur']) && $it['dur'] !== '' ? (float)$it['dur'] : null;
                $pdo->prepare("INSERT INTO crecer_reel_clips (reel_id, activo_id, orden_subido, orden, archivo, mime, bytes, dur_orig) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$reel_id, $aid, $ord, $ord, $a['archivo'], $a['mime'], $a['bytes'], $dur]);
                $guardar_frame((int)$pdo->lastInsertId(), $it['frame'] ?? '');
                $ord++; $guardados++;
            }
        }

        if ($guardados < 2) {
            $pdo->prepare("DELETE FROM crecer_reel_clips WHERE reel_id=?")->execute([$reel_id]);
            $pdo->prepare("DELETE FROM crecer_reels WHERE id=?")->execute([$reel_id]);
            echo json_encode(['ok'=>false,'err'=>($errores[0] ?? 'Necesito al menos 2 clips.')]); exit;
        }

        reels_disparar($reel_id); // fire-and-forget: analiza → arma → renderiza
        echo json_encode(['ok'=>true, 'reel_id'=>$reel_id, 'errores'=>$errores], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── Segmentos de un reel (para el editor de timing) ──
    if ($accion === 'segmentos') {
        $rid = (int)($_POST['reel_id'] ?? 0);
        $chk = $pdo->prepare("SELECT id, preset FROM crecer_reels WHERE id=? AND marca_id=?");
        $chk->execute([$rid, $marca_id]); $reel = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$reel) { echo json_encode(['ok'=>false,'err'=>'No encontrado.']); exit; }
        $cs = $pdo->prepare("SELECT id, orden, in_pt, out_pt, caption, dur_orig FROM crecer_reel_clips WHERE reel_id=? ORDER BY orden ASC, id ASC");
        $cs->execute([$rid]);
        $frbase = (defined('UPLOADS_URL') ? UPLOADS_URL : '/crecer/uploads') . "/marca_{$marca_id}/reels/frames";
        $segs = [];
        foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $segs[] = [
                'clip_id' => (int)$c['id'],
                'orden'   => (int)$c['orden'],
                'in'      => $c['in_pt']  !== null ? (float)$c['in_pt']  : 0,
                'out'     => $c['out_pt'] !== null ? (float)$c['out_pt'] : 2,
                'caption' => (string)($c['caption'] ?? ''),
                'dur'     => $c['dur_orig'] !== null ? (float)$c['dur_orig'] : null,
                'thumb'   => $frbase . '/' . (int)$c['id'] . '.jpg',
            ];
        }
        echo json_encode(['ok'=>true, 'preset'=>$reel['preset'], 'segmentos'=>$segs], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── Guardar edición del timing/captions → re-render ──
    if ($accion === 'reeditar') {
        $rid = (int)($_POST['reel_id'] ?? 0);
        $chk = $pdo->prepare("SELECT id FROM crecer_reels WHERE id=? AND marca_id=?");
        $chk->execute([$rid, $marca_id]);
        if (!$chk->fetch()) { echo json_encode(['ok'=>false,'err'=>'No encontrado.']); exit; }
        $segs = json_decode((string)($_POST['segmentos'] ?? '[]'), true);
        if (!is_array($segs) || count($segs) < 1) { echo json_encode(['ok'=>false,'err'=>'Sin segmentos.']); exit; }
        // Los clip_id deben pertenecer a este reel.
        $validos = $pdo->prepare("SELECT id FROM crecer_reel_clips WHERE reel_id=?");
        $validos->execute([$rid]); $validos = array_map('intval', $validos->fetchAll(PDO::FETCH_COLUMN));
        $orden = 0;
        foreach ($segs as $s) {
            $cid = (int)($s['clip_id'] ?? 0);
            if (!in_array($cid, $validos, true)) continue;
            $in  = max(0.0, (float)($s['in'] ?? 0));
            $out = (float)($s['out'] ?? ($in + 1.5));
            if ($out <= $in) $out = $in + 1.0;
            $cap = mb_substr(trim((string)($s['caption'] ?? '')), 0, 120);
            $pdo->prepare("UPDATE crecer_reel_clips SET orden=?, in_pt=?, out_pt=?, caption=? WHERE id=? AND reel_id=?")
                ->execute([$orden++, round($in,2), round($out,2), $cap, $cid, $rid]);
        }
        $pdo->prepare("UPDATE crecer_reels SET estado='armando', error_msg=NULL WHERE id=?")->execute([$rid]);
        reels_disparar($rid, 'reedit');
        echo json_encode(['ok'=>true, 'reel_id'=>$rid], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── Listar videos de la Biblioteca (para el picker) ──
    if ($accion === 'biblioteca') {
        $base = (defined('UPLOADS_URL') ? UPLOADS_URL : '/crecer/uploads');
        $q = $pdo->prepare("SELECT id, archivo, nombre FROM crecer_activos WHERE marca_id=? AND tipo='video' AND estado='activo' ORDER BY id DESC LIMIT 60");
        $q->execute([$marca_id]);
        $vids = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $v) {
            $vids[] = ['id'=>(int)$v['id'], 'nombre'=>$v['nombre'], 'url'=>rtrim($base,'/') . '/' . ltrim($v['archivo'],'/')];
        }
        echo json_encode(['ok'=>true, 'videos'=>$vids], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── Polling del estado ──
    if ($accion === 'estado') {
        $rid = (int)($_POST['reel_id'] ?? 0);
        $r = $pdo->prepare("SELECT id, estado, video_url, poster_url, duracion_seg, error_msg, edl_json, activo_id, copy_post FROM crecer_reels WHERE id=? AND marca_id=?");
        $r->execute([$rid, $marca_id]); $row = $r->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['ok'=>false,'err'=>'No encontrado.']); exit; }
        $edl = $row['edl_json'] ? json_decode($row['edl_json'], true) : null;
        echo json_encode([
            'ok'        => true,
            'estado'    => $row['estado'],
            'video_url' => $row['video_url'],
            'poster'    => $row['poster_url'],
            'duracion'  => $row['duracion_seg'],
            'error'     => $row['error_msg'],
            'resumen'   => $edl['resumen'] ?? null,
            'hook'      => $edl['hook'] ?? null,
            'guardado'  => !empty($row['activo_id']),
            'copy'      => $row['copy_post'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── Regenerar el copy del post ──
    if ($accion === 'recopy') {
        $rid = (int)($_POST['reel_id'] ?? 0);
        $r = $pdo->prepare("SELECT * FROM crecer_reels WHERE id=? AND marca_id=?");
        $r->execute([$rid, $marca_id]); $reel = $r->fetch(PDO::FETCH_ASSOC);
        if (!$reel) { echo json_encode(['ok'=>false,'err'=>'No encontrado.']); exit; }
        $txt = reels_generar_copy($pdo, $reel, null);
        if ($txt === null) { echo json_encode(['ok'=>false,'err'=>'No pude escribir el copy. Reintenta.']); exit; }
        echo json_encode(['ok'=>true, 'copy'=>$txt], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── Publicar el reel a IG/FB (reusa el publicador existente) ──
    if ($accion === 'publicar') {
        @set_time_limit(0);
        $rid = (int)($_POST['reel_id'] ?? 0);
        $r = $pdo->prepare("SELECT * FROM crecer_reels WHERE id=? AND marca_id=?");
        $r->execute([$rid, $marca_id]); $reel = $r->fetch(PDO::FETCH_ASSOC);
        if (!$reel) { echo json_encode(['ok'=>false,'err'=>'No encontrado.']); exit; }
        if (!in_array($reel['estado'], ['listo','publicado'], true)) { echo json_encode(['ok'=>false,'err'=>'El reel todavía no está listo.']); exit; }

        // Ruta LOCAL del mp4 (de la Biblioteca, o reconstruida).
        $rel = null;
        if (!empty($reel['activo_id'])) {
            $a = $pdo->prepare("SELECT archivo FROM crecer_activos WHERE id=? AND marca_id=?");
            $a->execute([(int)$reel['activo_id'], $marca_id]); $rel = $a->fetchColumn() ?: null;
        }
        if (!$rel) $rel = "marca_{$marca_id}/reels/final/{$rid}.mp4";
        if (!is_file(reels_uploads_path() . '/' . $rel)) {
            echo json_encode(['ok'=>false,'err'=>'El video aún no está guardado en el servidor. Espera unos segundos y reintenta.']); exit;
        }

        $caption = trim((string)($reel['copy_post'] ?? ''));
        $destinos = array_values(array_intersect((array)($_POST['redes'] ?? ['instagram','facebook']), ['instagram','facebook']));
        if (!$destinos) $destinos = ['instagram','facebook'];

        // Crear la pieza (tipo reel) que apunta al mp4. Guardamos la URL PÚBLICA
        // COMPLETA (no la ruta pelada): con la ruta relativa sin '/uploads/',
        // imagen_url_publica() armaba .../crecer/marca_1/... (sin 'uploads') → Meta
        // daba 404 "unable to fetch video file from URL".
        $video_pub = reels_public_url($rel);
        $pdo->prepare("INSERT INTO crecer_contenido (marca_id, plataforma, tipo, caption, grafica_path, estado) VALUES (?, 'instagram','reel',?,?, 'aprobado')")
            ->execute([$marca_id, $caption, $video_pub]);
        $cid = (int)$pdo->lastInsertId();

        // Publicar es LENTO (Meta procesa el video) → async, o el request se cae.
        // Limpiamos el error, disparamos el worker y el frontend hace polling.
        $pdo->prepare("UPDATE crecer_reels SET error_msg=NULL WHERE id=?")->execute([$rid]);
        $host = $_SERVER['HTTP_HOST'] ?? 'encuentraloahora.com';
        $sch  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $wurl = $sch . '://' . $host . '/crecer/panel/reel_publicar_worker.php?id=' . $rid . '&cid=' . $cid
              . '&redes=' . rawurlencode(implode(',', $destinos)) . '&key=' . REELS_WORKER_KEY;
        $ch = curl_init($wurl);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT_MS=>1500, CURLOPT_TIMEOUT_MS=>3000, CURLOPT_NOSIGNAL=>1, CURLOPT_SSL_VERIFYPEER=>false]);
        curl_exec($ch); curl_close($ch);

        echo json_encode(['ok'=>true, 'publishing'=>true], JSON_UNESCAPED_UNICODE); exit;
    }

    echo json_encode(['ok'=>false,'err'=>'Acción desconocida.']); exit;
}

$presets = reels_presets();
$musica_cat = reels_music_catalogo();
$render_ok = render_disponible();
$CSRF = csrf_token();
// Reels usa el MISMO shell que todo el panel (un solo nav, coherencia total).
$active = 'reels';
$page_title = 'Reels Studio';
require __DIR__ . '/_shell.php';
?>
<style>
:root{
  --rosa:#EF4375; --teal:#00A49F; --navy:#231F20;
  --tinta:#231F20; --muted:#7a7580; --line:#eceaee;
  --crema:#faf9fb; --crema2:#f4f2f6;
  --stage:#0e0e12;
}
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{font-family:'Plus Jakarta Sans',system-ui,sans-serif;color:var(--tinta);background:#fff;-webkit-font-smoothing:antialiased}
h1,h2,h3,.pop{font-family:'Poppins',sans-serif}
button{font-family:inherit;cursor:pointer}
svg.ic{width:1.05em;height:1.05em;flex-shrink:0;vertical-align:-2px}
.wrap{max-width:1080px;margin:0 auto;padding:0 18px}

/* Topbar propio (chrome aislada) */
.rtop{display:flex;align-items:center;gap:10px;padding:13px 16px;position:sticky;top:0;z-index:50;background:rgba(255,255,255,.92);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);border-bottom:1px solid var(--line)}
.rbrand{display:flex;align-items:center;gap:9px;text-decoration:none;color:inherit}
.rbrand img{width:33px;height:33px}
.rbrand b{display:inline-flex;flex-direction:column;line-height:1;font-family:'Poppins',sans-serif;font-size:17px}
.rbrand .c1{color:var(--teal);font-weight:700}
.rbrand .c2{font-size:.5em;font-weight:500;color:var(--muted);margin-top:1px;letter-spacing:.02em}
.rbadge{font-size:10px;font-weight:800;letter-spacing:.03em;text-transform:uppercase;background:#fff0f5;color:var(--rosa);padding:3px 8px;border-radius:999px;margin-left:2px}
.rbell{position:relative;margin-left:auto;color:var(--teal);font-size:22px;display:flex;align-items:center;text-decoration:none;line-height:1}
.rdot{position:absolute;top:-5px;right:-7px;background:var(--rosa);color:#fff;font-size:10px;font-weight:800;min-width:16px;height:16px;border-radius:999px;display:flex;align-items:center;justify-content:center;padding:0 4px}
.botnav{display:none}
@media(max-width:860px){
  .botnav{display:grid;grid-template-columns:repeat(4,1fr);position:fixed;left:10px;right:10px;bottom:10px;z-index:60;
    background:rgba(255,255,255,.96);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);
    border:1px solid var(--line);border-radius:22px;box-shadow:0 14px 34px rgba(34,29,38,.14);
    padding:8px 6px calc(8px + env(safe-area-inset-bottom))}
  .botnav .bn{display:flex;flex-direction:column;align-items:center;gap:3px;text-decoration:none;color:#8b8790;
    font-size:10.5px;font-weight:700;padding:7px 2px;border-radius:15px}
  .botnav .bn svg.ic{width:22px;height:22px}
  .botnav .bn.on{color:var(--rosa);background:#fff0f5}
  .wrap{padding-bottom:96px}
}

/* Hero + progreso de pasos */
.hero{padding:6px 0 14px}
.hero h1{font-size:clamp(26px,6vw,40px);font-weight:900;letter-spacing:-.02em;margin:.2em 0 .15em}
.hero p{color:var(--muted);font-size:15px;margin:0;max-width:52ch}
.steps{display:flex;gap:8px;margin:20px 0 8px}
.steps .st{flex:1;height:6px;border-radius:999px;background:var(--crema2);overflow:hidden}
.steps .st i{display:block;height:100%;width:0;background:linear-gradient(90deg,var(--rosa),var(--teal));border-radius:999px;transition:width .4s cubic-bezier(.2,.8,.2,1)}
.steps .st.done i,.steps .st.on i{width:100%}

/* Paneles del wizard */
.screen{display:none;animation:rise .45s cubic-bezier(.2,.8,.2,1)}
.screen.on{display:block}
@keyframes rise{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}
.card{background:#fff;border:1px solid var(--line);border-radius:20px;padding:22px;box-shadow:0 1px 0 rgba(0,0,0,.02)}
.kick{font-weight:800;color:var(--rosa);font-size:12.5px;letter-spacing:.04em;text-transform:uppercase;margin-bottom:6px}
.qh{font-size:clamp(20px,4.5vw,26px);font-weight:800;letter-spacing:-.01em;margin:0 0 4px}
.sub{color:var(--muted);font-size:14.5px;margin:0 0 18px}

/* Dropzone */
.drop{border:2px dashed #d9d5de;border-radius:18px;background:var(--crema);padding:34px 20px;text-align:center;transition:.2s;cursor:pointer}
.drop.hot{border-color:var(--teal);background:#effcfb}
.drop .big{font-size:40px;line-height:1;color:var(--teal)}
.drop b{display:block;margin-top:8px;font-family:'Poppins';font-size:16px}
.drop small{color:var(--muted);display:block;margin-top:4px}
.clips{display:grid;grid-template-columns:repeat(auto-fill,minmax(96px,1fr));gap:10px;margin-top:16px}
.clip{position:relative;aspect-ratio:9/16;border-radius:12px;overflow:hidden;background:#000;border:1px solid var(--line)}
.clip video{width:100%;height:100%;object-fit:cover;display:block}
.clip .num{position:absolute;top:6px;left:6px;background:rgba(0,0,0,.6);color:#fff;font-weight:800;font-size:11px;width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center}
.clip .x{position:absolute;top:6px;right:6px;background:rgba(0,0,0,.6);color:#fff;border:none;width:22px;height:22px;border-radius:50%;font-size:13px;line-height:1;display:flex;align-items:center;justify-content:center}
.clip .load{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.35);color:#fff;font-size:11px;font-weight:700}

/* Presets */
.presets{display:grid;grid-template-columns:1fr;gap:12px}
.preset{display:flex;align-items:center;gap:14px;text-align:left;width:100%;background:#fff;border:2px solid var(--line);border-radius:16px;padding:16px;transition:.18s}
.preset:hover{border-color:#cfcad4;transform:translateY(-1px)}
.preset.sel{border-color:var(--rosa);background:#fff5f8;box-shadow:0 6px 22px -12px rgba(239,67,117,.5)}
.preset .em{width:34px;height:34px;border-radius:10px;flex-shrink:0;box-shadow:inset 0 0 0 1px rgba(0,0,0,.08)}
.preset .nm{font-family:'Poppins';font-weight:800;font-size:17px}
.preset .tg{color:var(--muted);font-size:13.5px;margin-top:2px}
.preset .tick{margin-left:auto;width:24px;height:24px;border-radius:50%;border:2px solid var(--line);flex-shrink:0;display:flex;align-items:center;justify-content:center;color:#fff}
.preset.sel .tick{background:var(--rosa);border-color:var(--rosa)}

/* Inputs */
.field{width:100%;border:1.5px solid var(--line);border-radius:14px;padding:14px 16px;font-size:15px;font-family:inherit;background:#fff}
.field:focus{outline:none;border-color:var(--teal)}
.subsw{display:flex;align-items:center;gap:12px;margin-top:14px;padding:14px 16px;border:1.5px solid var(--line);border-radius:14px;cursor:pointer;user-select:none}
.subsw input{position:absolute;opacity:0;pointer-events:none}
.subsw .knob{flex-shrink:0;width:46px;height:27px;border-radius:999px;background:#d8d4dd;position:relative;transition:.2s}
.subsw .knob::after{content:'';position:absolute;top:3px;left:3px;width:21px;height:21px;border-radius:50%;background:#fff;transition:.2s;box-shadow:0 1px 3px rgba(0,0,0,.25)}
.subsw input:checked + .knob{background:var(--teal)}
.subsw input:checked + .knob::after{transform:translateX(19px)}
.subsw .lbl b{display:block;font-size:14.5px;font-family:'Poppins'}
.subsw .lbl small{color:var(--muted);font-size:12.5px}
.msec{margin-top:16px}
.mlbl{font-family:'Poppins';font-weight:700;font-size:14px;margin-bottom:9px}
.mrow{display:flex;gap:8px;flex-wrap:wrap}
.mchip{display:inline-flex;align-items:center;gap:7px;border:1.5px solid var(--line);background:#fff;border-radius:999px;padding:9px 14px;font-size:13.5px;font-weight:600;color:var(--tinta);transition:.15s}
.mchip:hover{border-color:#cfcad4}
.mchip.sel{border-color:var(--rosa);background:#fff5f8;color:var(--rosa)}
.mchip .mp{font-size:12px;width:20px;height:20px;border-radius:50%;background:var(--crema2);display:flex;align-items:center;justify-content:center}
.mchip.sel .mp{background:#ffe3ec}
.mchip .mp[data-play].playing{background:var(--rosa);color:#fff}

/* Botones */
.row{display:flex;gap:10px;align-items:center;margin-top:20px}
.btn{border:none;border-radius:14px;padding:15px 22px;font-family:'Poppins';font-weight:700;font-size:15.5px;transition:.16s}
.btn-primary{background:var(--tinta);color:#fff;flex:1}
.btn-primary:hover{transform:translateY(-1px)}
.btn-primary:disabled{opacity:.4;transform:none;cursor:not-allowed}
.btn-go{background:linear-gradient(100deg,var(--rosa),#ff6b93);color:#fff;flex:1;box-shadow:0 10px 30px -12px rgba(239,67,117,.7)}
.btn-ghost{background:var(--crema2);color:var(--tinta)}
.hint{color:var(--muted);font-size:12.5px;margin-top:10px;text-align:center}

/* Progreso (render) */
.stage{background:var(--stage);border-radius:24px;padding:30px 22px;color:#fff;text-align:center;position:relative;overflow:hidden}
.stage .halo{position:absolute;inset:-40% -10% auto;height:60%;background:radial-gradient(circle,rgba(239,67,117,.35),transparent 60%);filter:blur(30px);animation:float 5s ease-in-out infinite}
@keyframes float{50%{transform:translateY(20px)}}
.stage .ring{width:74px;height:74px;margin:6px auto 14px;border-radius:50%;border:4px solid rgba(255,255,255,.15);border-top-color:var(--rosa);animation:spin 1s linear infinite;position:relative;z-index:1}
@keyframes spin{to{transform:rotate(360deg)}}
.stage .msg{font-family:'Poppins';font-weight:800;font-size:20px;position:relative;z-index:1}
.stage .sub2{color:#b9b4c2;font-size:13.5px;margin-top:6px;position:relative;z-index:1;min-height:1.2em}
.dots{display:flex;gap:6px;justify-content:center;margin-top:16px;position:relative;z-index:1}
.dots i{width:7px;height:7px;border-radius:50%;background:rgba(255,255,255,.25);transition:.3s}
.dots i.on{background:var(--teal);transform:scale(1.3)}

/* Resultado */
.result{max-width:560px;margin:0 auto}
.meta .kh{font-family:'Poppins';font-weight:800;font-size:22px;margin:0 0 5px}
.meta .rs{color:var(--muted);font-size:14px;margin:0 0 4px;line-height:1.45}
/* Aviso-botón: abre el reel en lightbox (menos scroll) */
.reel-ready{display:flex;align-items:center;gap:13px;width:100%;text-align:left;cursor:pointer;
  background:linear-gradient(100deg,#fff0f5,#eafaf8);border:1px solid var(--line);border-radius:18px;padding:14px 16px;margin:12px 0 2px;transition:transform .16s,box-shadow .16s}
.reel-ready:hover{transform:translateY(-2px);box-shadow:0 14px 30px -14px rgba(239,67,117,.4)}
.reel-ready .rr-play{width:46px;height:46px;flex-shrink:0;border-radius:50%;background:var(--rosa);color:#fff;display:flex;align-items:center;justify-content:center;box-shadow:0 6px 16px -4px rgba(239,67,117,.6)}
.reel-ready .rr-play svg{width:22px;height:22px;margin-left:2px}
.reel-ready .rr-txt b{font-family:'Poppins';font-size:16px;font-weight:700;display:block}
.reel-ready .rr-txt small{color:var(--muted);font-size:12.5px}
.reel-lb{display:none;position:fixed;inset:0;z-index:80;background:rgba(14,14,18,.93);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);align-items:center;justify-content:center;padding:20px}
.reel-lb.on{display:flex}
.reel-lb video{width:100%;max-width:330px;aspect-ratio:9/16;border-radius:16px;background:#000;display:block}
.reel-lb-x{position:absolute;top:16px;right:16px;width:42px;height:42px;border-radius:50%;border:0;background:rgba(255,255,255,.16);color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center}
.reel-lb-x svg{width:22px;height:22px}
.pub-modal{display:none;position:fixed;inset:0;z-index:90;background:rgba(20,12,22,.55);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:22px}
.pub-modal.on{display:flex;animation:pubfade .2s}
@keyframes pubfade{from{opacity:0}to{opacity:1}}
.pub-card{position:relative;background:#fff;border-radius:24px;padding:30px 24px 22px;max-width:400px;width:100%;text-align:center;box-shadow:0 30px 70px -20px rgba(0,0,0,.5);animation:pubrise .32s cubic-bezier(.2,.85,.25,1)}
@keyframes pubrise{from{transform:translateY(16px);opacity:0}to{transform:none;opacity:1}}
.pub-ic{width:60px;height:60px;margin:0 auto 14px;border-radius:50%;background:#eafaf8;color:var(--teal);display:flex;align-items:center;justify-content:center}
.pub-ic svg{width:28px;height:28px}
.pub-card h3{font-family:'Poppins';font-size:21px;font-weight:800;margin:0 0 8px}
.pub-card p{color:var(--muted);font-size:14.5px;line-height:1.5;margin:0 0 18px}
.pub-q{font-family:'Poppins';font-weight:700;font-size:14px;margin-bottom:12px}
.pub-next{display:flex;flex-direction:column;gap:9px}
.pub-x{position:absolute;top:14px;right:14px;width:34px;height:34px;border-radius:50%;border:0;background:#f4f2f6;color:var(--muted);cursor:pointer;display:flex;align-items:center;justify-content:center}
.pub-x svg{width:18px;height:18px}
.chip{display:inline-flex;align-items:center;gap:6px;background:var(--crema2);border-radius:999px;padding:6px 12px;font-size:12.5px;font-weight:700;margin:0 6px 6px 0}
.err{background:#fff0f0;border:1px solid #f3c2c2;color:#9c2b2b;border-radius:14px;padding:14px 16px;font-size:14px}
.warn{background:#fff8e6;border:1px solid #f0dfa0;color:#7a5b00;border-radius:12px;padding:10px 14px;font-size:13px;margin-top:14px}

/* Editor de segmentos */
.seg{display:flex;gap:12px;align-items:flex-start;background:var(--crema);border:1px solid var(--line);border-radius:16px;padding:12px;margin-bottom:12px}
.seg .th{width:56px;aspect-ratio:9/16;border-radius:10px;object-fit:cover;background:#000;flex-shrink:0}
.seg .body{flex:1;min-width:0}
.seg .cap{width:100%;border:1.5px solid var(--line);border-radius:10px;padding:9px 11px;font-size:14px;font-family:inherit;background:#fff}
.seg .cap:focus{outline:none;border-color:var(--teal)}
.seg .dur{display:flex;align-items:center;gap:8px;margin-top:9px;font-size:12.5px;color:var(--muted)}
.seg .dur input[type=range]{flex:1;accent-color:var(--rosa)}
.seg .ord{display:flex;flex-direction:column;gap:5px}
.seg .ord button{width:30px;height:28px;border-radius:8px;border:1px solid var(--line);background:#fff;font-size:14px;line-height:1}
.seg .ord .del{color:#c0392b}
.bibsel{outline:3px solid var(--rosa);outline-offset:-3px}
.bibnum{position:absolute;bottom:6px;left:6px;background:var(--rosa);color:#fff;font-size:11px;font-weight:800;padding:2px 7px;border-radius:999px}
.savedchip{display:inline-block;margin:12px 0 4px;background:#e8f8f0;color:#0d7a44;border:1px solid #b9e6cf;border-radius:999px;padding:7px 14px;font-size:13px;font-weight:700}
.copybox{margin-top:16px;background:var(--crema);border:1px solid var(--line);border-radius:16px;padding:14px}
.copybox .cbh{display:flex;align-items:center;margin-bottom:8px}
.copybox .cbh b{font-family:'Poppins';font-size:15px}
.copybox .mini{margin-left:auto;background:#fff;border:1px solid var(--line);border-radius:9px;padding:5px 11px;font-size:12.5px;font-weight:700;color:var(--muted)}
.copyta{width:100%;min-height:130px;border:1.5px solid var(--line);border-radius:12px;padding:12px 14px;font-size:14px;font-family:inherit;line-height:1.5;background:#fff;resize:vertical;margin-bottom:10px}

@media(min-width:760px){
  .presets{grid-template-columns:repeat(3,1fr)}
  .preset{flex-direction:column;align-items:flex-start;text-align:left;min-height:170px}
  .preset .tick{margin-left:0;position:absolute;top:14px;right:14px}
  .preset{position:relative;padding:20px}
  .result{grid-template-columns:320px 1fr;align-items:start}
  .player video{max-width:100%}
}
</style>

<div class="wrap" style="max-width:640px;padding-top:8px">
  <div class="hero">
    <h1>Reels Studio</h1>
    <p>Sube tus pedacitos de video. El corillo los une, elige el mejor momento de cada uno, les pone captions con sabor y te lo entrega listo. Tú casi ni trabajas.</p>
    <div class="steps">
      <div class="st on" data-s="1"><i></i></div>
      <div class="st" data-s="2"><i></i></div>
      <div class="st" data-s="3"><i></i></div>
    </div>
  </div>

  <!-- PASO 1 · Subir -->
  <section class="screen on" id="s1">
    <div class="card">
      <div class="kick">Paso 1 de 3</div>
      <h2 class="qh">Tira tus clips aquí</h2>
      <p class="sub">Mínimo 2. Mientras más ángulos, mejor la mezcla. (mp4/mov, máx 100 MB c/u)</p>
      <div class="drop" id="drop">
        <div class="big"><?= ico('camera') ?></div>
        <b>Toca para escoger tus videos</b>
        <small>o arrástralos aquí</small>
      </div>
      <input type="file" id="file" accept="video/*" multiple hidden>
      <button class="btn btn-ghost" id="openbib" style="width:100%;margin-top:12px">O tira de tu Biblioteca</button>
      <div class="clips" id="clips"></div>
      <div class="row">
        <button class="btn btn-primary" id="n1" disabled>Elegir el estilo →</button>
      </div>
    </div>
  </section>

  <!-- PASO 2 · Preset -->
  <section class="screen" id="s2">
    <div class="card">
      <div class="kick">Paso 2 de 3</div>
      <h2 class="qh">¿Qué vibra le damos?</h2>
      <p class="sub">Escoge el ánimo. El corillo lo aplica solo: cortes, transiciones, tipografía y ritmo.</p>
      <div class="presets" id="presets">
        <?php foreach ($presets as $slug => $p): ?>
        <button class="preset<?= $slug==='vivido'?' sel':'' ?>" data-p="<?= $h($slug) ?>">
          <span class="em" style="background:<?= $h($p['accent']) ?>"></span>
          <div>
            <div class="nm"><?= $h($p['nombre']) ?></div>
            <div class="tg"><?= $h($p['tagline']) ?></div>
          </div>
          <div class="tick"><?= ico('check') ?></div>
        </button>
        <?php endforeach; ?>
      </div>
      <div class="row">
        <button class="btn btn-ghost" data-back="1">← Atrás</button>
        <button class="btn btn-primary" id="n2">Último paso →</button>
      </div>
    </div>
  </section>

  <!-- PASO 3 · Contexto + crear -->
  <section class="screen" id="s3">
    <div class="card">
      <div class="kick">Paso 3 de 3</div>
      <h2 class="qh">¿De qué es? <span style="color:var(--muted);font-weight:600;font-size:15px">(opcional)</span></h2>
      <p class="sub">Una línea ayuda al corillo a escribir mejores captions. Si no, él se inventa algo con sabor.</p>
      <input type="text" class="field" id="contexto" maxlength="140" placeholder="Ej: bizcocho de guayaba para el Día de las Madres">
      <div class="msec">
        <div class="mlbl">Música</div>
        <div class="mrow" id="mrow">
          <button type="button" class="mchip sel" data-m="auto"><span>El corillo elige</span></button>
          <?php foreach ($musica_cat as $k => $t): ?>
          <button type="button" class="mchip" data-m="<?= $h($k) ?>" data-url="<?= $h($t['url']) ?>">
            <span class="mp" data-play>▶</span><span><?= $h($t['nombre']) ?></span>
          </button>
          <?php endforeach; ?>
          <button type="button" class="mchip" data-m="none"><span>Sin música</span></button>
        </div>
      </div>
      <label class="subsw" id="subsw">
        <input type="checkbox" id="subtitulos">
        <span class="knob"></span>
        <span class="lbl"><b>Alguien habla en los clips</b><small>Pongo subtítulos automáticos de la voz (no inventados)</small></span>
      </label>
      <?php if (!$render_ok): ?>
        <div class="warn"><?= ico('bolt') ?> El motor de render todavía no está configurado en este servidor. Puedes crear el reel (el corillo lo planifica), pero el mp4 final no se generará hasta poner la key de Shotstack en producción.</div>
      <?php endif; ?>
      <div class="row">
        <button class="btn btn-ghost" data-back="2">← Atrás</button>
        <button class="btn btn-go" id="crear">Crear mi reel</button>
      </div>
      <div class="hint">Tarda entre 30 seg y 2 min. Te lo muestro apenas esté.</div>
    </div>
  </section>

  <!-- PROGRESO -->
  <section class="screen" id="sload">
    <div class="stage">
      <div class="halo"></div>
      <div class="ring"></div>
      <div class="msg" id="loadmsg">Preparando el corillo…</div>
      <div class="sub2" id="loadsub"></div>
      <div class="dots"><i class="on"></i><i></i><i></i><i></i></div>
    </div>
  </section>

  <!-- RESULTADO -->
  <section class="screen" id="sdone">
    <div class="result">
      <div class="meta">
        <div class="kick">Tu reel está listo</div>
        <h2 class="kh" id="rhook">Quedó brutal</h2>
        <p class="rs" id="rsum"></p>
        <button type="button" class="reel-ready" id="reelOpen">
          <span class="rr-play"><svg viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M8 5v14l11-7z"/></svg></span>
          <span class="rr-txt"><b>Ver tu reel</b><small>Toca para reproducirlo</small></span>
        </button>
        <div id="rchips"></div>
        <div class="savedchip" id="rsaved" style="display:none"><?= ico('check-circle') ?> Guardado en tu Biblioteca</div>
        <div class="row" style="flex-wrap:wrap;margin-top:14px">
          <button class="btn btn-go" id="rpub" style="flex:1">Publicar ahora</button>
        </div>
        <div id="pubmsg" style="display:none;margin-top:10px"></div>
        <div class="copybox" id="copybox" style="display:none">
          <div class="cbh"><b>Texto para tu post</b><button class="mini" id="cregen">Otro</button></div>
          <textarea class="copyta" id="copytext" readonly></textarea>
          <button class="btn btn-ghost" id="ccopy" style="width:100%">Copiar texto</button>
        </div>
        <div class="row" style="margin-top:10px;flex-wrap:wrap;gap:10px">
          <a class="btn btn-ghost" id="rdl" download style="text-decoration:none;text-align:center;flex:1">Descargar</a>
          <button class="btn btn-ghost" id="redit" style="flex:1">Ajustar</button>
          <button class="btn btn-ghost" id="ragain" style="flex:1">Hacer otro</button>
        </div>
      </div>
    </div>
  </section>

  <!-- Lightbox del reel -->
  <div class="reel-lb" id="reelLb">
    <button class="reel-lb-x" id="reelLbX" aria-label="Cerrar"><?= ico('x') ?></button>
    <video id="rvid" controls playsinline></video>
  </div>

  <!-- EDITOR de timing/captions -->
  <section class="screen" id="sedit">
    <div class="card">
      <div class="kick">Afínalo a tu gusto</div>
      <h2 class="qh">Ajusta el timing y los textos</h2>
      <p class="sub">Reordena, alarga o acorta cada pedazo y arregla el caption. Cuando termines, el corillo lo vuelve a montar.</p>
      <div id="segs"></div>
      <div class="row">
        <button class="btn btn-ghost" id="ecancel">← Cancelar</button>
        <button class="btn btn-go" id="esave">Volver a montar</button>
      </div>
    </div>
  </section>

  <!-- PICKER de Biblioteca -->
  <div id="bibmodal" style="display:none;position:fixed;inset:0;z-index:50;background:rgba(20,10,22,.55);backdrop-filter:blur(3px);align-items:flex-end;justify-content:center">
    <div style="background:#fff;width:100%;max-width:640px;border-radius:22px 22px 0 0;max-height:82vh;display:flex;flex-direction:column;padding:18px 18px 24px">
      <div style="display:flex;align-items:center;margin-bottom:10px">
        <b class="pop" style="font-size:18px">Tu Biblioteca</b>
        <button class="btn btn-ghost" id="bibclose" style="margin-left:auto;padding:8px 14px">Cerrar</button>
      </div>
      <div id="bibgrid" class="clips" style="overflow:auto"></div>
      <div class="row"><button class="btn btn-primary" id="bibadd" style="flex:1">Agregar seleccionados</button></div>
    </div>
  </div>

  <!-- FALLO -->
  <section class="screen" id="sfail">
    <div class="card">
      <div class="kick" style="color:#9c2b2b">Algo se atravesó</div>
      <h2 class="qh">No pude terminar el reel</h2>
      <div class="err" id="failmsg" style="margin-top:8px"></div>
      <div class="row">
        <button class="btn btn-primary" id="retry">Intentar de nuevo</button>
      </div>
    </div>
  </section>

  <div style="height:40px"></div>
</div>

<script>
const CSRF = <?= json_encode($CSRF) ?>;
const MARCA = <?= (int)$marca_id ?>;
const HERE = location.pathname + '?marca=<?= $marca_id ?>';
const $ = s => document.querySelector(s);
let files = [];        // {file|url, activo_id, dur, frame}
let preset = 'vivido';
let step = 1;
let curReel = null;    // reel activo (para el editor)
let editSegs = [];     // segmentos en edición

// ── Navegación de pasos ──
function go(n){
  step = n;
  document.querySelectorAll('.screen').forEach(s=>s.classList.remove('on'));
  const map = {1:'s1',2:'s2',3:'s3',load:'sload',done:'sdone',fail:'sfail',edit:'sedit'};
  $('#'+map[n]).classList.add('on');
  document.querySelectorAll('.steps .st').forEach(st=>{
    const s = +st.dataset.s;
    st.classList.toggle('done', typeof n==='number' && s < n);
    st.classList.toggle('on', typeof n==='number' && s === n);
  });
  window.scrollTo({top:0,behavior:'smooth'});
}
document.querySelectorAll('[data-back]').forEach(b=>b.onclick=()=>go(+b.dataset.back));
$('#n1').onclick=()=>go(2);
$('#n2').onclick=()=>go(3);

// ── Paso 1: subir + extraer fotograma + duración ──
const drop=$('#drop'), file=$('#file'), clipsEl=$('#clips');
drop.onclick=()=>file.click();
['dragover','dragenter'].forEach(e=>drop.addEventListener(e,ev=>{ev.preventDefault();drop.classList.add('hot')}));
['dragleave','drop'].forEach(e=>drop.addEventListener(e,ev=>{ev.preventDefault();drop.classList.remove('hot')}));
drop.addEventListener('drop',ev=>addFiles(ev.dataTransfer.files));
file.onchange=()=>addFiles(file.files);

function addFiles(list){
  [...list].forEach(f=>{
    if(!f.type.startsWith('video/')) return;
    const item={file:f,url:null,activo_id:null,dur:null,frame:null};
    files.push(item);
    renderClips();
    extractFrame(item);
  });
}
function addLib(list){ // {id,url,nombre}
  list.forEach(v=>{
    if(files.some(f=>f.activo_id===v.id)) return;
    const item={file:null,url:v.url,activo_id:v.id,dur:null,frame:null};
    files.push(item);
    renderClips();
    extractFrame(item);
  });
}
function clipSrc(it){ return it.url || URL.createObjectURL(it.file); }
function renderClips(){
  clipsEl.innerHTML='';
  files.forEach((it,i)=>{
    const d=document.createElement('div'); d.className='clip';
    const v=document.createElement('video'); v.src=clipSrc(it); v.muted=true; v.playsInline=true; v.preload='metadata';
    d.appendChild(v);
    const num=document.createElement('div'); num.className='num'; num.textContent=i+1; d.appendChild(num);
    const x=document.createElement('button'); x.className='x'; x.textContent='×';
    x.onclick=()=>{files.splice(i,1);renderClips();gate()}; d.appendChild(x);
    if(!it.frame){ const l=document.createElement('div'); l.className='load'; l.textContent='leyendo…'; d.appendChild(l); }
    clipsEl.appendChild(d);
  });
  gate();
}
function gate(){ $('#n1').disabled = files.length < 2; }

// Extrae 1 fotograma (mitad) + la duración, en el navegador.
function extractFrame(item){
  const v=document.createElement('video');
  v.muted=true; v.playsInline=true; v.preload='auto'; v.crossOrigin='anonymous'; v.src=clipSrc(item);
  let done=false;
  const finish=()=>{ if(!done){done=true; renderClips();} };
  const to=setTimeout(finish, 14000); // resiliente: si falla, seguimos con la duración
  v.onloadedmetadata=()=>{
    item.dur = isFinite(v.duration)? v.duration : null;
    const t = item.dur? Math.min(item.dur/2, item.dur-0.1) : 0.1;
    try{ v.currentTime = Math.max(0.05, t); }catch(e){ clearTimeout(to); finish(); }
  };
  v.onseeked=()=>{
    try{
      const c=document.createElement('canvas');
      const w=v.videoWidth||720, hh=v.videoHeight||1280;
      const scale=Math.min(1, 640/Math.max(w,hh));
      c.width=Math.round(w*scale); c.height=Math.round(hh*scale);
      c.getContext('2d').drawImage(v,0,0,c.width,c.height);
      item.frame = c.toDataURL('image/jpeg',0.7);
    }catch(e){}
    clearTimeout(to); finish();
  };
  v.onerror=()=>{ clearTimeout(to); finish(); };
}

// ── Paso 2: preset ──
document.querySelectorAll('.preset').forEach(b=>b.onclick=()=>{
  document.querySelectorAll('.preset').forEach(x=>x.classList.remove('sel'));
  b.classList.add('sel'); preset=b.dataset.p;
});

// ── Paso 3: música (selección + preview) ──
let musica='auto', mAudio=null, mPlayingBtn=null;
function stopPreview(){ if(mAudio){mAudio.pause();mAudio=null;} if(mPlayingBtn){mPlayingBtn.classList.remove('playing');mPlayingBtn.textContent='▶';mPlayingBtn=null;} }
document.querySelectorAll('.mchip').forEach(chip=>{
  chip.onclick=(e)=>{
    if(e.target.closest('[data-play]')){ // botón de preview, no selecciona
      const play=chip.querySelector('[data-play]'); const url=chip.dataset.url;
      if(mPlayingBtn===play){ stopPreview(); return; }
      stopPreview();
      if(url){ mAudio=new Audio(url); mAudio.volume=.85; mAudio.play().catch(()=>{}); mPlayingBtn=play; play.classList.add('playing'); play.textContent='⏸';
        mAudio.onended=()=>stopPreview(); }
      return;
    }
    document.querySelectorAll('.mchip').forEach(c=>c.classList.remove('sel'));
    chip.classList.add('sel'); musica=chip.dataset.m;
  };
});

// ── Paso 3: crear ──
$('#crear').onclick=async()=>{
  stopPreview();
  if(files.length<2){ go(1); return; }
  $('#crear').disabled=true;
  go('load'); animateLoad();
  const fd=new FormData();
  fd.append('csrf',CSRF); fd.append('accion','crear'); fd.append('preset',preset);
  fd.append('contexto', $('#contexto').value||'');
  fd.append('subtitulos', $('#subtitulos').checked ? '1' : '');
  fd.append('musica', musica);
  const lib=[];
  files.forEach(it=>{
    if(it.file){
      fd.append('clips[]', it.file, it.file.name);
      fd.append('dur[]', it.dur!=null? it.dur.toFixed(2):'');
      fd.append('frame[]', it.frame||'');
    }else if(it.activo_id){
      lib.push({activo_id:it.activo_id, dur:it.dur!=null? +it.dur.toFixed(2):'', frame:it.frame||''});
    }
  });
  fd.append('lib', JSON.stringify(lib));
  try{
    const r=await fetch(HERE,{method:'POST',body:fd});
    const j=await r.json();
    if(!j.ok){ showFail(j.err||'No se pudo crear.'); $('#crear').disabled=false; return; }
    curReel=j.reel_id; poll(j.reel_id);
  }catch(e){ showFail('Se cayó la conexión al subir. Reintenta.'); $('#crear').disabled=false; }
};

// ── Progreso animado + polling ──
const LOAD_MSGS={
  borrador:['Recibiendo tus clips…','Acomodando el material…'],
  analizando:['El corillo está viendo tus clips…','Escogiendo el mejor momento de cada uno…','Buscando el ángulo que engancha…'],
  armando:['Ordenando la historia…','Escribiendo los captions con sabor…'],
  renderizando:['Uniendo todo…','Puliendo transiciones…','Casi listo, dándole el toque final…']
};
let loadState='borrador', loadIdx=0, loadTimer=null, dotI=0;
function animateLoad(){
  clearInterval(loadTimer);
  loadTimer=setInterval(()=>{
    const arr=LOAD_MSGS[loadState]||LOAD_MSGS.analizando;
    $('#loadsub').textContent=arr[loadIdx % arr.length]; loadIdx++;
    const dots=document.querySelectorAll('.dots i'); dots.forEach((d,k)=>d.classList.toggle('on',k===dotI%dots.length)); dotI++;
  },1600);
}
const STATE_TITLE={borrador:'Subiendo…',analizando:'Viendo tus clips',armando:'Montando el reel',renderizando:'Renderizando'};

async function poll(rid){
  try{
    const fd=new FormData(); fd.append('csrf',CSRF); fd.append('accion','estado'); fd.append('reel_id',rid);
    const r=await fetch(HERE,{method:'POST',body:fd}); const j=await r.json();
    if(!j.ok){ showFail(j.err||'Error consultando el estado.'); return; }
    if(['borrador','analizando','armando','renderizando'].includes(j.estado)){
      loadState=j.estado; $('#loadmsg').textContent=STATE_TITLE[j.estado]||'Trabajando…';
      setTimeout(()=>poll(rid), 2500); return;
    }
    if(j.estado==='listo'){ clearInterval(loadTimer); showDone(j); return; }
    if(j.estado==='failed'){ clearInterval(loadTimer); showFail(j.error||'El corillo no pudo terminarlo.'); return; }
    setTimeout(()=>poll(rid), 2500);
  }catch(e){ setTimeout(()=>poll(rid), 3500); }
}

function showDone(j){
  go('done');
  const v=$('#rvid'); v.src=j.video_url||''; if(j.poster) v.poster=j.poster;
  $('#rdl').href=j.video_url||'#';
  if(j.hook) $('#rhook').textContent = '“'+j.hook+'”';
  $('#rsum').textContent = j.resumen||'El corillo escogió el orden y los cortes que más enganchan.';
  const p=<?= json_encode(array_map(fn($x)=>['nombre'=>$x['nombre']], $presets)) ?>;
  const chips=[]; if(p[preset]) chips.push(p[preset].nombre);
  if(j.duracion) chips.push(Math.round(j.duracion)+'s');
  chips.push('9:16');
  $('#rchips').innerHTML=chips.map(c=>'<span class="chip">'+c+'</span>').join('');
  // Guardado en Biblioteca + copy del post
  $('#rsaved').style.display = j.guardado ? 'inline-block' : 'none';
  if(j.copy){ $('#copybox').style.display='block'; $('#copytext').value=j.copy; }
  else{ $('#copybox').style.display='block'; $('#copytext').value=''; $('#copytext').placeholder='El corillo está escribiendo el texto…'; setTimeout(()=>refreshCopy(), 3000); }
}
async function refreshCopy(){
  if(!curReel) return;
  const fd=new FormData(); fd.append('csrf',CSRF); fd.append('accion','estado'); fd.append('reel_id',curReel);
  try{ const j=await (await fetch(HERE,{method:'POST',body:fd})).json();
    if(j.ok && j.copy){ $('#copytext').value=j.copy; } else if(j.ok){ setTimeout(refreshCopy, 3000); }
  }catch(e){}
}
$('#ccopy').onclick=()=>{ const t=$('#copytext'); t.select(); document.execCommand('copy');
  navigator.clipboard && navigator.clipboard.writeText(t.value).catch(()=>{});
  $('#ccopy').textContent='¡Copiado!'; setTimeout(()=>$('#ccopy').textContent='Copiar texto',1600); };
$('#cregen').onclick=async()=>{
  $('#cregen').textContent='…'; $('#cregen').disabled=true;
  const fd=new FormData(); fd.append('csrf',CSRF); fd.append('accion','recopy'); fd.append('reel_id',curReel);
  try{ const j=await (await fetch(HERE,{method:'POST',body:fd})).json();
    if(j.ok) $('#copytext').value=j.copy; else alert(j.err||'No pude regenerar.');
  }catch(e){ alert('Se cayó la conexión.'); }
  $('#cregen').textContent='Otro'; $('#cregen').disabled=false;
};
function showFail(msg){ go('fail'); $('#failmsg').textContent=msg; }
$('#retry').onclick=()=>{ $('#crear').disabled=false; go(3); };
$('#ragain').onclick=()=>{ files=[]; renderClips(); $('#contexto').value=''; go(1); };

// Lightbox del reel (abrir/cerrar) — menos scroll
const reelLb=$('#reelLb');
function reelClose(){ reelLb.classList.remove('on'); const v=$('#rvid'); v.pause(); }
$('#reelOpen').onclick=()=>{ reelLb.classList.add('on'); const v=$('#rvid'); v.currentTime=0; v.play().catch(()=>{}); };
$('#reelLbX').onclick=reelClose;
reelLb.onclick=e=>{ if(e.target===reelLb) reelClose(); };

// Popup tras publicar: despacha al usuario (recomendación o ir al inicio; ellos escogen).
// Handlers perezosos: el modal está más abajo en el DOM, se enlaza al abrir.
function openPubModal(){
  const m=$('#pubModal'); if(!m) return;
  m.classList.add('on');
  m.onclick=e=>{ if(e.target.id==='pubModal') m.classList.remove('on'); };
  const x=$('#pubClose'); if(x) x.onclick=()=>m.classList.remove('on');
  const o=$('#pubOtro'); if(o) o.onclick=()=>{ m.classList.remove('on'); files=[]; renderClips(); $('#contexto').value=''; go(1); };
}

// ── Publicar a IG/FB (reusa el publicador del app) ──
let pubTries=0;
$('#rpub').onclick=async()=>{
  if(!curReel) return;
  const b=$('#rpub'), pm=$('#pubmsg'), old=b.textContent;
  b.disabled=true; b.textContent='Publicando…'; pm.style.display='none';
  const fd=new FormData(); fd.append('csrf',CSRF); fd.append('accion','publicar'); fd.append('reel_id',curReel);
  try{
    const j=await (await fetch(HERE,{method:'POST',body:fd})).json();
    if(!j.ok){ b.disabled=false; b.textContent=old; pm.style.display='block';
      const link=j.conectar?(' <a href="/crecer/panel/conectar.php?marca='+MARCA+'" style="color:#9c2b2b;font-weight:800">Conectar mis redes →</a>'):'';
      pm.innerHTML='<div class="err">'+(j.err||'No se pudo publicar.')+link+'</div>'; return; }
    // Despachado: publica por detrás. Popup + redirect al inicio (no esperar).
    b.disabled=false; b.textContent=old;
    openPubModal();
  }catch(e){ b.disabled=false; b.textContent=old; pm.style.display='block'; pm.innerHTML='<div class="err">Se cayó la conexión al arrancar. Reintenta.</div>'; }
};
async function pollPublish(){
  const b=$('#rpub'), pm=$('#pubmsg');
  const fd=new FormData(); fd.append('csrf',CSRF); fd.append('accion','estado'); fd.append('reel_id',curReel);
  try{
    const j=await (await fetch(HERE,{method:'POST',body:fd})).json();
    if(j.ok && j.estado==='publicado'){ b.textContent='¡Publicado!'; pm.innerHTML='<div class="savedchip">Tu reel se publicó en tus redes.</div>'; return; }
    if(j.ok && j.error){ b.disabled=false; b.textContent='Publicar ahora';
      const link=/conect|redes/i.test(j.error)?(' <a href="/crecer/panel/conectar.php?marca='+MARCA+'" style="color:#9c2b2b;font-weight:800">Conectar mis redes →</a>'):'';
      pm.innerHTML='<div class="err">'+j.error.replace(/^publicar:\s*/,'')+link+'</div>'; return; }
    if(++pubTries>90){ b.disabled=false; b.textContent='Publicar ahora';
      pm.innerHTML='<div class="savedchip" style="background:#fff5f8;color:var(--rosa)">Sigue subiendo — te aviso en Notificaciones cuando esté. Puedes seguir en lo tuyo.</div>'; return; }
    setTimeout(pollPublish, 3000);
  }catch(e){ if(++pubTries>90){ pm.innerHTML='<div class="savedchip" style="background:#fff5f8;color:var(--rosa)">Sigue subiendo — te aviso en Notificaciones.</div>'; return; } setTimeout(pollPublish, 4000); }
}

// ── EDITOR de timing/captions ──
$('#redit').onclick=async()=>{
  if(!curReel) return;
  const fd=new FormData(); fd.append('csrf',CSRF); fd.append('accion','segmentos'); fd.append('reel_id',curReel);
  const j=await (await fetch(HERE,{method:'POST',body:fd})).json();
  if(!j.ok){ alert(j.err||'No pude cargar el reel.'); return; }
  editSegs=j.segmentos.map(s=>({...s})); renderSegs(); go('edit');
};
function renderSegs(){
  const box=$('#segs'); box.innerHTML='';
  editSegs.forEach((s,i)=>{
    const dur = s.dur||Math.max(6, s.out+1);
    const len = Math.max(0.8, (s.out - s.in));
    const el=document.createElement('div'); el.className='seg';
    el.innerHTML=`
      <img class="th" src="${s.thumb}" onerror="this.style.opacity=.2">
      <div class="body">
        <input class="cap" value="${(s.caption||'').replace(/"/g,'&quot;')}" placeholder="Caption con sabor…" maxlength="120">
        <div class="dur">
          <span><b class="ln">${len.toFixed(1)}s</b></span>
          <input type="range" min="0.8" max="${Math.min(dur, 6).toFixed(1)}" step="0.1" value="${Math.min(len,Math.min(dur,6)).toFixed(1)}">
        </div>
      </div>
      <div class="ord">
        <button class="up" ${i===0?'disabled':''}>↑</button>
        <button class="dn" ${i===editSegs.length-1?'disabled':''}>↓</button>
        <button class="del">✕</button>
      </div>`;
    el.querySelector('.cap').oninput=e=>{ editSegs[i].caption=e.target.value; };
    const range=el.querySelector('input[type=range]'), lnl=el.querySelector('.ln');
    range.oninput=e=>{ const L=+e.target.value; editSegs[i].out=+(editSegs[i].in+L).toFixed(2); lnl.textContent=L.toFixed(1)+'s'; };
    el.querySelector('.up').onclick=()=>{ if(i>0){ [editSegs[i-1],editSegs[i]]=[editSegs[i],editSegs[i-1]]; renderSegs(); } };
    el.querySelector('.dn').onclick=()=>{ if(i<editSegs.length-1){ [editSegs[i+1],editSegs[i]]=[editSegs[i],editSegs[i+1]]; renderSegs(); } };
    el.querySelector('.del').onclick=()=>{ if(editSegs.length>1){ editSegs.splice(i,1); renderSegs(); } else alert('Deja al menos un pedazo.'); };
    box.appendChild(el);
  });
}
$('#ecancel').onclick=()=>go('done');
$('#esave').onclick=async()=>{
  go('load'); animateLoad(); loadState='armando'; $('#loadmsg').textContent='Montando de nuevo';
  const fd=new FormData(); fd.append('csrf',CSRF); fd.append('accion','reeditar'); fd.append('reel_id',curReel);
  fd.append('segmentos', JSON.stringify(editSegs.map(s=>({clip_id:s.clip_id, in:s.in, out:s.out, caption:s.caption}))));
  try{
    const j=await (await fetch(HERE,{method:'POST',body:fd})).json();
    if(!j.ok){ showFail(j.err||'No pude re-montar.'); return; }
    poll(curReel);
  }catch(e){ showFail('Se cayó la conexión. Reintenta.'); }
};

// ── PICKER de Biblioteca ──
const bibModal=$('#bibmodal'); let bibSel=[];
$('#openbib').onclick=async()=>{
  bibSel=[]; $('#bibgrid').innerHTML='<div style="padding:20px;color:var(--muted)">Cargando…</div>';
  bibModal.style.display='flex';
  const fd=new FormData(); fd.append('csrf',CSRF); fd.append('accion','biblioteca');
  const j=await (await fetch(HERE,{method:'POST',body:fd})).json();
  const g=$('#bibgrid');
  if(!j.ok || !j.videos.length){ g.innerHTML='<div style="padding:20px;color:var(--muted)">No tienes videos en la Biblioteca todavía.</div>'; return; }
  g.innerHTML='';
  j.videos.forEach(v=>{
    const d=document.createElement('div'); d.className='clip'; d.style.cursor='pointer';
    const vid=document.createElement('video'); vid.src=v.url; vid.muted=true; vid.playsInline=true; vid.preload='metadata';
    d.appendChild(vid);
    d.onclick=()=>{
      const k=bibSel.indexOf(v.id);
      if(k>=0){ bibSel.splice(k,1); d.classList.remove('bibsel'); d.querySelector('.bibnum')?.remove(); }
      else{ bibSel.push(v.id); d.classList.add('bibsel'); const n=document.createElement('div'); n.className='bibnum'; n.textContent='✓'; d.appendChild(n); }
    };
    d._v=v; g.appendChild(d);
  });
};
$('#bibclose').onclick=()=>bibModal.style.display='none';
bibModal.onclick=e=>{ if(e.target===bibModal) bibModal.style.display='none'; };
$('#bibadd').onclick=()=>{
  const picked=[...$('#bibgrid').children].filter(d=>d._v && bibSel.includes(d._v.id)).map(d=>d._v);
  addLib(picked); bibModal.style.display='none';
};
</script>
<!-- Popup: despachar al usuario tras publicar -->
<div class="pub-modal" id="pubModal">
  <div class="pub-card">
    <button class="pub-x" id="pubClose" aria-label="Cerrar"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
    <div class="pub-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/></svg></div>
    <h3>¡En camino!</h3>
    <p>Estoy publicando tu reel. Te aviso en Notificaciones cuando esté — no tienes que esperar aquí.</p>
    <div class="pub-q">¿Qué hacemos ahora?</div>
    <div class="pub-next">
      <button class="btn btn-go" id="pubOtro" style="width:100%">Hacer otro reel</button>
      <a class="btn btn-ghost" style="width:100%;text-decoration:none;text-align:center" href="<?= $BASE ?>/index.php?marca=<?= $marca_id ?>">Ir al inicio</a>
    </div>
  </div>
</div>
<?php require __DIR__ . '/_shell_foot.php'; ?>
