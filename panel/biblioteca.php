<?php
// ============================================================
//  LA BIBLIOTECA DEL NEGOCIO — Memoria Visual.
//  panel/biblioteca.php
//
//  El lugar donde el dueño guarda el patrimonio visual de su
//  negocio (fotos y videos), aunque todavía no los use. Hoy es
//  una biblioteca en calma; mañana el Corillo la consume para
//  posts, campañas, historias, anuncios y el Business Genome.
//  Tabla: crecer_activos (con ganchos futuros: origen/tags/analizado_at).
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/iconos.php';
requiere_login();
$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }
$marca_id = (int)$marca['id'];
$BASE = '/crecer/panel';
$mid  = "marca={$marca_id}";
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$negocio = $marca['nombre_negocio'] ?? 'tu negocio';

$UP_PATH = (defined('UPLOADS_PATH') ? UPLOADS_PATH : dirname(__DIR__) . '/uploads') . "/marca_{$marca_id}/biblioteca";
$UP_URL  = (defined('UPLOADS_URL') ? UPLOADS_URL : '/crecer/uploads') . "/marca_{$marca_id}/biblioteca";

$IMG_EXT = ['jpg','jpeg','png','webp','gif'];
$VID_EXT = ['mp4','mov','webm','m4v'];
$MAX_IMG = 15 * 1024 * 1024;   // 15 MB
$MAX_VID = 100 * 1024 * 1024;  // 100 MB

// ── AJAX ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_ok()) { echo json_encode(['ok'=>false,'err'=>'Sesión expiró. Recarga la página.']); exit; }
    $accion = $_POST['accion'] ?? '';

    // Subir uno o varios archivos.
    if ($accion === 'subir') {
        @set_time_limit(0);
        if (!is_dir($UP_PATH)) @mkdir($UP_PATH, 0775, true);
        $guardados = []; $errores = [];
        $files = $_FILES['archivos'] ?? null;
        if (!$files || !isset($files['name'])) { echo json_encode(['ok'=>false,'err'=>'No llegó ningún archivo.']); exit; }
        $n = is_array($files['name']) ? count($files['name']) : 0;
        for ($i = 0; $i < $n; $i++) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { $errores[] = 'Un archivo no se pudo leer.'; continue; }
            $orig = (string)$files['name'][$i];
            $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            $size = (int)$files['size'][$i];
            $tmp  = $files['tmp_name'][$i];
            $es_img = in_array($ext, $IMG_EXT, true);
            $es_vid = in_array($ext, $VID_EXT, true);
            if (!$es_img && !$es_vid) { $errores[] = "\"{$orig}\": formato no permitido."; continue; }
            if ($es_img && $size > $MAX_IMG) { $errores[] = "\"{$orig}\": imagen muy grande (máx 15 MB)."; continue; }
            if ($es_vid && $size > $MAX_VID) { $errores[] = "\"{$orig}\": video muy grande (máx 100 MB)."; continue; }
            $tipo = $es_img ? 'imagen' : 'video';
            $mime = function_exists('mime_content_type') ? (mime_content_type($tmp) ?: '') : '';
            if ($es_img && strpos($mime, 'image/') !== 0 && $mime !== '') { $errores[] = "\"{$orig}\": no parece una imagen."; continue; }
            $ancho = $alto = null;
            if ($es_img) { $gi = @getimagesize($tmp); if ($gi) { $ancho = (int)$gi[0]; $alto = (int)$gi[1]; } }
            $fn = bin2hex(random_bytes(8)) . '.' . $ext;
            if (!@move_uploaded_file($tmp, $UP_PATH . '/' . $fn)) { $errores[] = "\"{$orig}\": no se pudo guardar."; continue; }
            $nombre = trim(pathinfo($orig, PATHINFO_FILENAME)); if ($nombre === '') $nombre = ($tipo === 'imagen' ? 'Foto' : 'Video');
            $nombre = mb_substr($nombre, 0, 180);
            $rel = "marca_{$marca_id}/biblioteca/{$fn}";
            $st = $pdo->prepare("INSERT INTO crecer_activos (marca_id,tipo,archivo,nombre,mime,bytes,ancho,alto,origen,estado) VALUES (?,?,?,?,?,?,?,?, 'subido','activo')");
            $st->execute([$marca_id, $tipo, $rel, $nombre, $mime, $size, $ancho, $alto]);
            $guardados[] = (int)$pdo->lastInsertId();
        }
        echo json_encode(['ok'=>!empty($guardados), 'guardados'=>count($guardados), 'errores'=>$errores], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Eliminar (archivo + fila).
    if ($accion === 'eliminar') {
        $id = (int)($_POST['id'] ?? 0);
        $r = $pdo->prepare("SELECT archivo FROM crecer_activos WHERE id=? AND marca_id=?");
        $r->execute([$id, $marca_id]); $arch = $r->fetchColumn();
        if ($arch) {
            $abs = (defined('UPLOADS_PATH') ? UPLOADS_PATH : dirname(__DIR__) . '/uploads') . '/' . $arch;
            if (is_file($abs)) @unlink($abs);
            $pdo->prepare("DELETE FROM crecer_activos WHERE id=? AND marca_id=?")->execute([$id, $marca_id]);
            echo json_encode(['ok'=>true]); exit;
        }
        echo json_encode(['ok'=>false,'err'=>'No encontrado.']); exit;
    }

    // Eliminar VARIOS (selección múltiple).
    if ($accion === 'eliminar_varios') {
        $ids = json_decode((string)($_POST['ids'] ?? '[]'), true);
        $n = 0;
        if (is_array($ids)) foreach ($ids as $id) {
            $id = (int)$id; if (!$id) continue;
            $r = $pdo->prepare("SELECT archivo FROM crecer_activos WHERE id=? AND marca_id=?");
            $r->execute([$id, $marca_id]); $arch = $r->fetchColumn();
            if ($arch) {
                $abs = (defined('UPLOADS_PATH') ? UPLOADS_PATH : dirname(__DIR__) . '/uploads') . '/' . $arch;
                if (is_file($abs)) @unlink($abs);
                $pdo->prepare("DELETE FROM crecer_activos WHERE id=? AND marca_id=?")->execute([$id, $marca_id]);
                $n++;
            }
        }
        echo json_encode(['ok'=>true, 'borrados'=>$n]); exit;
    }

    // Renombrar.
    if ($accion === 'renombrar') {
        $id = (int)($_POST['id'] ?? 0); $nombre = mb_substr(trim((string)($_POST['nombre'] ?? '')), 0, 180);
        if ($nombre === '') { echo json_encode(['ok'=>false,'err'=>'El nombre no puede quedar vacío.']); exit; }
        $pdo->prepare("UPDATE crecer_activos SET nombre=?, updated_at=NOW() WHERE id=? AND marca_id=?")->execute([$nombre, $id, $marca_id]);
        echo json_encode(['ok'=>true,'nombre'=>$nombre], JSON_UNESCAPED_UNICODE); exit;
    }

    // Nota opcional.
    if ($accion === 'nota') {
        $id = (int)($_POST['id'] ?? 0); $nota = mb_substr(trim((string)($_POST['nota'] ?? '')), 0, 2000);
        $pdo->prepare("UPDATE crecer_activos SET nota=?, updated_at=NOW() WHERE id=? AND marca_id=?")->execute([$nota !== '' ? $nota : null, $id, $marca_id]);
        echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE); exit;
    }

    echo json_encode(['ok'=>false,'err'=>'Acción desconocida.']); exit;
}

// ── Los activos del negocio (los más nuevos primero) ──
$activos = [];
try {
    $q = $pdo->prepare("SELECT id,tipo,archivo,nombre,nota,ancho,alto,created_at FROM crecer_activos WHERE marca_id=? AND estado='activo' ORDER BY id DESC");
    $q->execute([$marca_id]); $activos = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $activos = []; }

$_mes = [1=>'ene',2=>'feb',3=>'mar',4=>'abr',5=>'may',6=>'jun',7=>'jul',8=>'ago',9=>'sep',10=>'oct',11=>'nov',12=>'dic'];
$fecha_larga = function ($ts) use ($_mes) { $d = strtotime((string)$ts); return $d ? ((int)date('j',$d).' '.$_mes[(int)date('n',$d)].' '.date('Y',$d)) : ''; };

$active = 'biblioteca';
$page_title = 'Biblioteca';
$guia = null; // La galería se usa sola: tap → fullscreen → swipe. Sin explicación.
require __DIR__ . '/_shell.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
  /* ══ LA BIBLIOTECA ══ galería viva. Mismo director que Home/Propuestas:
     Poppins, top ink-soft, tarjeta oscura inmersiva para el fullscreen. */
  .content{max-width:1000px}
  .asis-fab{display:none}
  .bib{max-width:940px;margin:0 auto;padding:16px 14px 100px;font-family:'Poppins',var(--font-body)}
  @media(min-width:761px){.bib{padding:26px 6px 70px}}

  .bib-top{display:flex;align-items:baseline;justify-content:space-between;gap:12px;padding:0 2px;margin-bottom:20px}
  .bib-neg{font-family:var(--font-display);font-size:15px;font-weight:600;color:var(--ink-soft);letter-spacing:-.01em}
  .bib-cred{font-size:12.5px;color:var(--muted);font-weight:400;white-space:nowrap}

  /* masonry por columnas: alturas naturales, las fotos respiran */
  .bib-grid{column-count:2;column-gap:12px}
  @media(min-width:560px){.bib-grid{column-count:3}}
  @media(min-width:860px){.bib-grid{column-count:4;column-gap:14px}}
  .bib-grid.drag{outline:2px dashed var(--teal);outline-offset:8px;border-radius:20px}
  .bib-tile,.bib-add{break-inside:avoid;width:100%;margin:0 0 12px}
  @media(min-width:860px){.bib-tile,.bib-add{margin-bottom:14px}}

  .bib-add{aspect-ratio:1;border:1.5px dashed var(--line);border-radius:18px;background:var(--crema-2);
    display:flex;flex-direction:column;align-items:center;justify-content:center;gap:7px;cursor:pointer;
    color:var(--muted);font-family:'Poppins',sans-serif;font-weight:500;font-size:13.5px;transition:.15s}
  .bib-add:hover{border-color:var(--teal);color:var(--teal-700);background:#f2fbfa}
  .bib-add .p{font-size:30px;font-weight:300;line-height:1}

  .bib-tile{position:relative;border-radius:18px;overflow:hidden;cursor:pointer;background:var(--crema-2);display:block;
    box-shadow:0 1px 3px rgba(40,22,28,.06);transition:transform var(--dur) var(--ease),box-shadow var(--dur) var(--ease)}
  .bib-tile:hover{transform:translateY(-3px);box-shadow:0 18px 40px -16px rgba(40,22,28,.3)}
  .bib-tile img,.bib-tile video{width:100%;height:auto;display:block}
  .bib-tile .play{position:absolute;inset:0;display:grid;place-items:center;background:rgba(20,10,22,.16);color:#fff}
  .bib-tile .play span{width:44px;height:44px;border-radius:50%;background:rgba(0,0,0,.45);display:grid;place-items:center;backdrop-filter:blur(2px)}
  .bib-tile .play span::after{content:"";margin-left:3px;border-style:solid;border-width:9px 0 9px 14px;border-color:transparent transparent transparent #fff}
  .bib-del{position:absolute;top:8px;right:8px;z-index:4;width:32px;height:32px;border-radius:50%;border:0;cursor:pointer;
    background:rgba(20,10,22,.55);color:#fff;display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .15s,background .15s;backdrop-filter:blur(2px)}
  .bib-tile:hover .bib-del{opacity:1}
  .bib-del:hover{background:#e0384f}
  @media(hover:none){.bib-del{opacity:.92}}
  /* Selección múltiple */
  .bib-selbtn{margin-left:auto;background:#fff;border:1px solid var(--line);border-radius:10px;padding:7px 14px;font-weight:700;font-size:13px;color:var(--muted);cursor:pointer;font-family:inherit}
  .bib-selbtn:hover{color:var(--tinta)}
  .bib-selbtn.on{background:var(--tinta);color:#fff;border-color:var(--tinta)}
  .bib-ck{position:absolute;top:8px;left:8px;z-index:4;width:26px;height:26px;border-radius:50%;border:2px solid #fff;background:rgba(20,10,22,.35);display:none;align-items:center;justify-content:center;color:#fff;font-size:14px;box-shadow:0 1px 4px rgba(0,0,0,.3)}
  .bib-grid.selecting .bib-ck{display:flex}
  .bib-grid.selecting .bib-del{display:none}
  .bib-grid.selecting .bib-add{opacity:.4;pointer-events:none}
  .bib-tile.sel{outline:3px solid var(--teal);outline-offset:-3px}
  .bib-tile.sel .bib-ck{background:var(--teal);border-color:var(--teal)}
  .bib-tile.sel img,.bib-tile.sel video{opacity:.82}
  .bib-selbar{position:fixed;left:50%;transform:translateX(-50%);bottom:16px;z-index:60;background:var(--tinta,#231F20);color:#fff;border-radius:16px;padding:9px 10px 9px 18px;display:none;align-items:center;gap:10px;box-shadow:0 14px 40px -12px rgba(0,0,0,.5)}
  .bib-selbar.show{display:flex}
  .bib-selbar .cnt{font-weight:700;font-size:14px;white-space:nowrap}
  .bib-selbar button{border:0;border-radius:10px;padding:9px 14px;font-weight:700;font-size:13.5px;cursor:pointer;font-family:inherit}
  .bib-selbar .all{background:rgba(255,255,255,.14);color:#fff}
  .bib-selbar .del{background:#e0384f;color:#fff}
  .bib-selbar .cancel{background:transparent;color:rgba(255,255,255,.7)}

  .bib-empty{text-align:center;padding:8vh 10px 2vh;color:var(--muted)}
  .bib-empty p{font-size:15px;line-height:1.6;margin:0 auto;max-width:34ch}

  .bib-up{position:fixed;left:50%;bottom:82px;transform:translateX(-50%);z-index:130;background:var(--tinta);color:#fff;
    padding:12px 20px;border-radius:99px;font-size:13.5px;font-weight:600;box-shadow:0 14px 34px -10px rgba(0,0,0,.4);display:none}
  .bib-up.show{display:block}

  /* ── Fullscreen: la foto crece a toda la pantalla; swipe; cerrar y vuelves ── */
  .lb{position:fixed;inset:0;z-index:200;background:#0b0810;opacity:0;pointer-events:none;
    transition:opacity .3s var(--ease);display:flex;flex-direction:column}
  .lb.show{opacity:1;pointer-events:auto}
  .lb-bar{display:flex;align-items:center;gap:12px;padding:14px 16px;position:relative;z-index:3}
  .lb-count{font-size:13px;color:rgba(255,255,255,.7);font-weight:500}
  .lb-ico{width:40px;height:40px;border-radius:50%;border:0;background:rgba(255,255,255,.12);color:#fff;cursor:pointer;
    display:grid;place-items:center;font-size:19px;line-height:1;transition:background var(--dur) var(--ease)}
  .lb-ico:hover{background:rgba(255,255,255,.22)}
  .lb-stage{flex:1;position:relative;overflow:hidden;min-height:0}
  .lb-track{position:absolute;inset:0;display:flex;transition:transform .34s var(--ease);will-change:transform}
  .lb-slide{flex:0 0 100%;height:100%;display:grid;place-items:center;padding:0 8px}
  .lb-slide img,.lb-slide video{max-width:100%;max-height:100%;object-fit:contain;border-radius:12px;display:block}
  .lb-foot{padding:14px 20px calc(22px + env(safe-area-inset-bottom));position:relative;z-index:3;max-width:640px;margin:0 auto;width:100%;box-sizing:border-box}
  .lb-name{width:100%;box-sizing:border-box;font-family:var(--font-display);font-weight:600;font-size:18px;color:#fff;
    border:0;border-bottom:1.5px solid transparent;padding:3px 0;background:0}
  .lb-name:focus{outline:0;border-bottom-color:rgba(255,255,255,.5)}
  .lb-meta{display:flex;align-items:center;gap:18px;margin-top:9px}
  .lb-date{font-size:12.5px;color:rgba(255,255,255,.55)}
  .lb-sp{flex:1}
  .lb-note-btn,.lb-del{background:0;border:0;cursor:pointer;font-family:'Poppins',sans-serif;font-weight:500;font-size:13px;color:rgba(255,255,255,.82);padding:2px}
  .lb-note-btn:hover,.lb-del:hover{color:#fff}
  .lb-note{width:100%;box-sizing:border-box;margin-top:11px;font-family:var(--font-body);font-size:14px;line-height:1.5;color:#fff;
    border:1px solid rgba(255,255,255,.2);border-radius:12px;padding:10px;resize:vertical;min-height:52px;background:rgba(255,255,255,.06);display:none}
  .lb-note.show{display:block}
  .lb-note:focus{outline:0;border-color:rgba(255,255,255,.5)}
  .lb-note::placeholder{color:rgba(255,255,255,.4)}
</style>

<main class="bib">
  <div class="bib-top">
    <span class="bib-neg">Biblioteca</span>
    <?php if ($activos): ?><span class="bib-cred"><?= count($activos) ?> <?= count($activos) === 1 ? 'recuerdo' : 'recuerdos' ?></span>
      <button type="button" class="bib-selbtn" id="bibSelBtn">Seleccionar</button><?php endif; ?>
  </div>

  <input type="file" id="bibFile" accept="image/*,video/*" multiple hidden>

  <div class="bib-grid" id="bibGrid">
    <button type="button" class="bib-add" id="bibAdd"><span class="p">＋</span>Agregar</button>
    <?php foreach ($activos as $a):
      $url = $UP_URL . '/' . basename($a['archivo']);
    ?>
      <figure class="bib-tile" data-id="<?= (int)$a['id'] ?>" data-tipo="<?= $h($a['tipo']) ?>"
        data-url="<?= $h($url) ?>" data-nombre="<?= $h($a['nombre']) ?>" data-nota="<?= $h((string)$a['nota']) ?>"
        data-fecha="Subido el <?= $h($fecha_larga($a['created_at'])) ?>">
        <?php if ($a['tipo'] === 'imagen'): ?>
          <img src="<?= $h($url) ?>" alt="" loading="lazy">
        <?php else: ?>
          <video src="<?= $h($url) ?>" preload="metadata" muted playsinline></video>
          <span class="play"><span></span></span>
        <?php endif; ?>
        <button type="button" class="bib-del" data-del="<?= (int)$a['id'] ?>" aria-label="Eliminar" title="Eliminar">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
        </button>
        <span class="bib-ck">✓</span>
      </figure>
    <?php endforeach; ?>
  </div>

  <?php if (!$activos): ?>
    <div class="bib-empty"><p>Todavía no hay nada. Arrastra fotos y videos, o dale a <b>Agregar</b>. Es el álbum del que el corillo va a tirar mañana.</p></div>
  <?php endif; ?>

  <div class="bib-up" id="bibUp">Guardando…</div>

  <div class="bib-selbar" id="bibSelBar">
    <span class="cnt" id="bibSelCnt">0 seleccionados</span>
    <button type="button" class="all" id="bibSelAll">Todos</button>
    <button type="button" class="del" id="bibSelDel"><?= ico('trash') ?> Eliminar</button>
    <button type="button" class="cancel" id="bibSelCancel">Cancelar</button>
  </div>
</main>

<!-- Fullscreen: la foto crece a toda la pantalla, swipe, cerrar y vuelves -->
<div class="lb" id="lb" aria-hidden="true">
  <div class="lb-bar">
    <button type="button" class="lb-ico" id="lbClose" aria-label="Cerrar">&times;</button>
    <span class="lb-count" id="lbCount"></span>
  </div>
  <div class="lb-stage" id="lbStage">
    <div class="lb-track" id="lbTrack"></div>
  </div>
  <div class="lb-foot">
    <input class="lb-name" id="lbName" maxlength="180" aria-label="Nombre">
    <div class="lb-meta">
      <span class="lb-date" id="lbDate"></span>
      <span class="lb-sp"></span>
      <button type="button" class="lb-note-btn" id="lbNoteBtn">Nota</button>
      <button type="button" class="lb-del" id="lbDel">Eliminar</button>
    </div>
    <textarea class="lb-note" id="lbNote" maxlength="2000" placeholder="Agrega una nota…"></textarea>
  </div>
</div>

<script>
(function () {
  var CSRF = <?= json_encode(csrf_token()) ?>, HERE = location.pathname + '?<?= $h($mid) ?>';
  var grid = document.getElementById('bibGrid'), input = document.getElementById('bibFile'),
      add = document.getElementById('bibAdd'), up = document.getElementById('bibUp');

  function post(accion, data) {
    var fd = new FormData(); fd.append('csrf', CSRF); fd.append('accion', accion);
    if (data) for (var k in data) fd.append(k, data[k]);
    return fetch(HERE, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
  }

  // ── Subir (Agregar + arrastrar) ──
  function subir(files) {
    if (!files || !files.length) return;
    var fd = new FormData(); fd.append('csrf', CSRF); fd.append('accion', 'subir');
    for (var i = 0; i < files.length; i++) fd.append('archivos[]', files[i]);
    up.textContent = 'Guardando ' + files.length + (files.length === 1 ? ' archivo…' : ' archivos…'); up.classList.add('show');
    fetch(HERE, { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (d) {
      if (d && d.ok) { location.reload(); }
      else { up.classList.remove('show'); alert((d && d.errores && d.errores[0]) || (d && d.err) || 'No se pudo guardar.'); }
    }).catch(function () { up.classList.remove('show'); alert('Se cayó la conexión.'); });
  }
  add.addEventListener('click', function () { input.click(); });
  input.addEventListener('change', function () { subir(input.files); input.value = ''; });

  ['dragenter', 'dragover'].forEach(function (ev) { grid.addEventListener(ev, function (e) { e.preventDefault(); grid.classList.add('drag'); }); });
  ['dragleave', 'drop'].forEach(function (ev) { grid.addEventListener(ev, function (e) { e.preventDefault(); if (ev !== 'drop' && e.target !== grid && grid.contains(e.relatedTarget)) return; grid.classList.remove('drag'); }); });
  grid.addEventListener('drop', function (e) { e.preventDefault(); if (e.dataTransfer && e.dataTransfer.files) subir(e.dataTransfer.files); });

  // ── Galería fullscreen con swipe ──
  var tiles = [].slice.call(grid.querySelectorAll('.bib-tile'));
  var assets = tiles.map(function (t) {
    return { id: t.getAttribute('data-id'), tipo: t.getAttribute('data-tipo'), url: t.getAttribute('data-url'),
             nombre: t.getAttribute('data-nombre') || '', nota: t.getAttribute('data-nota') || '', fecha: t.getAttribute('data-fecha') || '' };
  });
  // Botón de basura en cada tile: borra sin abrir el visor.
  [].slice.call(grid.querySelectorAll('.bib-del')).forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.stopPropagation(); e.preventDefault();
      if (!confirm('¿Eliminar esto de tu biblioteca? No se puede deshacer.')) return;
      btn.disabled = true;
      post('eliminar', { id: btn.getAttribute('data-del') }).then(function (d) {
        if (d && d.ok) location.reload();
        else { btn.disabled = false; alert((d && d.err) || 'No se pudo eliminar.'); }
      }).catch(function () { btn.disabled = false; alert('Se cayó la conexión.'); });
    });
  });

  var lb = document.getElementById('lb'), track = document.getElementById('lbTrack'), stage = document.getElementById('lbStage'),
      countEl = document.getElementById('lbCount'), nameEl = document.getElementById('lbName'), dateEl = document.getElementById('lbDate'),
      noteEl = document.getElementById('lbNote'), noteBtn = document.getElementById('lbNoteBtn'), delEl = document.getElementById('lbDel');
  var idx = 0;

  function build() {
    track.innerHTML = assets.map(function (a) {
      var m = a.tipo === 'imagen' ? '<img src="' + a.url + '" alt="">'
                                  : '<video src="' + a.url + '" controls playsinline preload="metadata"></video>';
      return '<div class="lb-slide">' + m + '</div>';
    }).join('');
  }
  function meta() {
    var a = assets[idx];
    nameEl.value = a.nombre; dateEl.textContent = a.fecha; noteEl.value = a.nota;
    noteEl.classList.toggle('show', !!a.nota);
    countEl.textContent = (idx + 1) + ' / ' + assets.length;
  }
  function show(i, anim) {
    idx = Math.max(0, Math.min(assets.length - 1, i));
    track.style.transition = anim === false ? 'none' : '';
    track.style.transform = 'translateX(-' + (idx * 100) + '%)';
    meta();
  }
  function open(i) {
    if (!assets.length) return;
    build(); lb.classList.add('show'); lb.setAttribute('aria-hidden', 'false'); document.body.style.overflow = 'hidden';
    show(i, false);
  }
  function close() { lb.classList.remove('show'); lb.setAttribute('aria-hidden', 'true'); document.body.style.overflow = ''; track.innerHTML = ''; }

  tiles.forEach(function (t, i) { t.addEventListener('click', function (e) {
    if (selecting) { e.preventDefault(); e.stopPropagation(); toggleSel(t); return; }
    open(i);
  }); });

  // ── Selección múltiple + borrar en bloque ──
  var selecting = false, selected = {};
  var selBtn = document.getElementById('bibSelBtn'), selBar = document.getElementById('bibSelBar'),
      selCnt = document.getElementById('bibSelCnt'), selDel = document.getElementById('bibSelDel'),
      selAll = document.getElementById('bibSelAll'), selCancel = document.getElementById('bibSelCancel');
  function updSel(){ var n = Object.keys(selected).length; selCnt.textContent = n + (n === 1 ? ' seleccionado' : ' seleccionados'); if (selDel) selDel.style.visibility = n ? 'visible' : 'hidden'; }
  function toggleSel(t){ var id = t.getAttribute('data-id'); if (selected[id]) { delete selected[id]; t.classList.remove('sel'); } else { selected[id] = 1; t.classList.add('sel'); } updSel(); }
  function enterSel(){ selecting = true; selected = {}; grid.classList.add('selecting'); if (selBar) selBar.classList.add('show'); if (selBtn){ selBtn.classList.add('on'); selBtn.textContent = 'Listo'; } updSel(); }
  function exitSel(){ selecting = false; selected = {}; grid.classList.remove('selecting'); if (selBar) selBar.classList.remove('show'); if (selBtn){ selBtn.classList.remove('on'); selBtn.textContent = 'Seleccionar'; } tiles.forEach(function (t){ t.classList.remove('sel'); }); }
  if (selBtn) selBtn.addEventListener('click', function () { selecting ? exitSel() : enterSel(); });
  if (selCancel) selCancel.addEventListener('click', exitSel);
  if (selAll) selAll.addEventListener('click', function () { tiles.forEach(function (t){ selected[t.getAttribute('data-id')] = 1; t.classList.add('sel'); }); updSel(); });
  if (selDel) selDel.addEventListener('click', function () {
    var ids = Object.keys(selected); if (!ids.length) return;
    if (!confirm('¿Eliminar ' + ids.length + (ids.length === 1 ? ' cosa?' : ' cosas?') + ' No se puede deshacer.')) return;
    selDel.disabled = true;
    post('eliminar_varios', { ids: JSON.stringify(ids) }).then(function (d) {
      if (d && d.ok) location.reload(); else { selDel.disabled = false; alert('No se pudo eliminar.'); }
    }).catch(function () { selDel.disabled = false; alert('Se cayó la conexión.'); });
  });
  document.getElementById('lbClose').addEventListener('click', close);
  document.addEventListener('keydown', function (e) {
    if (!lb.classList.contains('show')) return;
    if (e.key === 'Escape') close(); else if (e.key === 'ArrowRight') show(idx + 1); else if (e.key === 'ArrowLeft') show(idx - 1);
  });

  // swipe entre activos
  var sx = 0, drag = false;
  stage.addEventListener('pointerdown', function (e) { if (e.target.closest('video,button,a,input,textarea')) return; drag = true; sx = e.clientX; track.style.transition = 'none'; });
  stage.addEventListener('pointermove', function (e) { if (!drag) return; var dx = e.clientX - sx; track.style.transform = 'translateX(calc(-' + (idx * 100) + '% + ' + dx + 'px))'; });
  function endDrag(e) { if (!drag) return; drag = false; var dx = (e.clientX || sx) - sx; if (dx < -60) show(idx + 1); else if (dx > 60) show(idx - 1); else show(idx); }
  stage.addEventListener('pointerup', endDrag);
  stage.addEventListener('pointercancel', endDrag);

  // renombrar / nota / eliminar sobre el activo actual
  nameEl.addEventListener('blur', function () {
    var a = assets[idx]; if (!a) return; var v = nameEl.value.trim();
    if (!v || v === a.nombre) { nameEl.value = a.nombre; return; }
    post('renombrar', { id: a.id, nombre: v }).then(function (d) { if (d && d.ok) a.nombre = d.nombre; });
  });
  noteBtn.addEventListener('click', function () { noteEl.classList.toggle('show'); if (noteEl.classList.contains('show')) noteEl.focus(); });
  noteEl.addEventListener('blur', function () {
    var a = assets[idx]; if (!a) return; var v = noteEl.value.trim(); if (v === a.nota) return;
    post('nota', { id: a.id, nota: v }).then(function (d) { if (d && d.ok) a.nota = v; });
  });
  delEl.addEventListener('click', function () {
    var a = assets[idx]; if (!a) return; if (!confirm('¿Eliminar esto de tu biblioteca? No se puede deshacer.')) return;
    delEl.disabled = true;
    post('eliminar', { id: a.id }).then(function (d) { delEl.disabled = false; if (d && d.ok) location.reload(); else alert((d && d.err) || 'No se pudo eliminar.'); })
      .catch(function () { delEl.disabled = false; alert('Se cayó la conexión.'); });
  });
})();
</script>

<?php require __DIR__ . '/_shell_foot.php'; ?>
