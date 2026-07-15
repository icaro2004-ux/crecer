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
$guia = ['key'=>'biblioteca','agente'=>'sparkles','titulo'=>'La biblioteca de tu negocio',
  'intro'=>'Guarda aquí las fotos y videos de tu negocio — el corillo los usa para crear.',
  'pasos'=>[
    ['image','Arrastra fotos y videos, o dale a Agregar.'],
    ['sparkles','Ponle una nota si quieres recordar qué es.'],
    ['check-circle','Toca cualquiera para verlo, renombrarlo o borrarlo.'],
  ]];
require __DIR__ . '/_shell.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
  /* ══ LA BIBLIOTECA ══ un lugar donde el dueño deja recuerdos útiles. Aire, calma. */
  .content{max-width:920px}
  .asis-fab{display:none}
  .bib{max-width:880px;margin:0 auto;padding:5vh 6px 100px;font-family:'Poppins',var(--font-body)}
  @media(min-width:761px){.bib{padding:7vh 6px 70px}}
  .bib-top{margin-bottom:30px}
  .bib-top h1{font-family:'Poppins',sans-serif;font-weight:500;font-size:clamp(25px,4.6vw,36px);line-height:1.15;letter-spacing:-.02em;color:var(--tinta);margin:0;text-wrap:balance}
  .bib-top h1 b{font-weight:600}
  .bib-top p{margin:12px 0 0;font-size:14.5px;color:var(--muted);line-height:1.55;max-width:56ch}

  .bib-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}
  @media(min-width:560px){.bib-grid{grid-template-columns:repeat(3,1fr)}}
  @media(min-width:860px){.bib-grid{grid-template-columns:repeat(4,1fr);gap:16px}}
  .bib-grid.drag{outline:2px dashed var(--teal);outline-offset:8px;border-radius:20px}

  .bib-add{aspect-ratio:1;border:1.5px dashed var(--line);border-radius:16px;background:var(--crema-2);
    display:flex;flex-direction:column;align-items:center;justify-content:center;gap:7px;cursor:pointer;
    color:var(--muted);font-family:'Poppins',sans-serif;font-weight:500;font-size:13.5px;transition:.15s}
  .bib-add:hover{border-color:var(--teal);color:var(--teal-700);background:#f2fbfa}
  .bib-add .p{font-size:30px;font-weight:300;line-height:1}

  .bib-tile{position:relative;aspect-ratio:1;border-radius:16px;overflow:hidden;cursor:pointer;background:var(--crema-2);
    border:1px solid var(--line);margin:0;transition:transform .18s,box-shadow .18s}
  .bib-tile:hover{transform:translateY(-2px);box-shadow:var(--shadow)}
  .bib-tile img,.bib-tile video{width:100%;height:100%;object-fit:cover;display:block}
  .bib-tile .play{position:absolute;inset:0;display:grid;place-items:center;background:rgba(20,10,22,.18);color:#fff}
  .bib-tile .play span{width:40px;height:40px;border-radius:50%;background:rgba(0,0,0,.45);display:grid;place-items:center;backdrop-filter:blur(2px)}
  .bib-tile .play span::after{content:"";margin-left:3px;border-style:solid;border-width:8px 0 8px 13px;border-color:transparent transparent transparent #fff}
  .bib-tile .cap{position:absolute;left:0;right:0;bottom:0;padding:18px 10px 8px;font-size:12px;color:#fff;font-weight:500;
    background:linear-gradient(transparent,rgba(20,10,22,.55));opacity:0;transition:opacity .18s;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .bib-tile:hover .cap{opacity:1}

  /* Estado vacío — una invitación en calma, no un panel */
  .bib-empty{text-align:center;padding:6vh 10px 2vh;color:var(--muted)}
  .bib-empty p{font-size:15px;line-height:1.6;margin:0 auto;max-width:38ch}

  .bib-up{position:fixed;left:50%;bottom:26px;transform:translateX(-50%);z-index:130;background:var(--tinta);color:#fff;
    padding:12px 20px;border-radius:99px;font-size:13.5px;font-weight:600;box-shadow:0 14px 34px -10px rgba(0,0,0,.4);display:none}
  .bib-up.show{display:block}

  /* Vista individual — un lightbox en calma */
  .bib-modal{position:fixed;inset:0;z-index:140;display:none}
  .bib-modal.show{display:block}
  .bib-back{position:absolute;inset:0;background:rgba(20,12,22,.66);backdrop-filter:blur(3px)}
  .bib-sheet{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:min(560px,92vw);max-height:88vh;overflow:auto;
    background:var(--card);border-radius:22px;box-shadow:0 40px 90px -20px rgba(0,0,0,.5);padding:16px}
  .bib-x{position:absolute;top:10px;right:12px;z-index:2;width:34px;height:34px;border-radius:50%;border:0;background:rgba(255,255,255,.9);
    font-size:20px;line-height:1;color:var(--tinta);cursor:pointer;box-shadow:var(--shadow-sm)}
  .bib-media{border-radius:14px;overflow:hidden;background:var(--crema-2);margin-bottom:14px;display:grid;place-items:center;min-height:180px}
  .bib-media img,.bib-media video{width:100%;max-height:56vh;object-fit:contain;display:block;background:#000}
  .bib-name{width:100%;box-sizing:border-box;font-family:'Poppins',sans-serif;font-weight:600;font-size:18px;color:var(--tinta);
    border:0;border-bottom:1.5px solid transparent;padding:4px 2px;background:0}
  .bib-name:hover{border-bottom-color:var(--line)}
  .bib-name:focus{outline:0;border-bottom-color:var(--magenta)}
  .bib-note{width:100%;box-sizing:border-box;font-family:var(--font-body);font-size:14px;line-height:1.5;color:#3a3340;
    border:1px solid var(--line);border-radius:12px;padding:11px;resize:vertical;min-height:56px;margin-top:12px;background:#fff}
  .bib-note:focus{outline:2px solid color-mix(in srgb,var(--magenta) 35%,transparent);outline-offset:1px;border-color:transparent}
  .bib-metarow{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:16px}
  .bib-date{font-size:12.5px;color:var(--muted)}
  .bib-del{background:0;border:0;cursor:pointer;color:var(--noo-ink);font-family:'Poppins',sans-serif;font-weight:600;font-size:13.5px}
  .bib-del:hover{text-decoration:underline}
</style>

<main class="bib">
  <header class="bib-top">
    <h1>La biblioteca de <b><?= $h($negocio) ?></b></h1>
    <p>Las fotos y videos de tu negocio, en un solo lugar. Guárdalos aunque no los uses todavía — el corillo los tiene a mano para crear.</p>
  </header>

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
        <span class="cap"><?= $h($a['nombre']) ?></span>
      </figure>
    <?php endforeach; ?>
  </div>

  <?php if (!$activos): ?>
    <div class="bib-empty"><p>Todavía no hay nada aquí. Arrastra las fotos y videos de tu negocio — o dale a <b>Agregar</b>. Este es el álbum del que el corillo va a tirar mañana.</p></div>
  <?php endif; ?>

  <div class="bib-up" id="bibUp">Guardando…</div>
</main>

<!-- Vista individual -->
<div class="bib-modal" id="bibModal" aria-hidden="true">
  <div class="bib-back" data-close></div>
  <div class="bib-sheet">
    <button type="button" class="bib-x" data-close aria-label="Cerrar">&times;</button>
    <div class="bib-media" id="bibMedia"></div>
    <input class="bib-name" id="bibName" maxlength="180" aria-label="Nombre">
    <textarea class="bib-note" id="bibNote" maxlength="2000" placeholder="Agrega una nota… (opcional)"></textarea>
    <div class="bib-metarow">
      <span class="bib-date" id="bibDate"></span>
      <button type="button" class="bib-del" id="bibDel">Eliminar</button>
    </div>
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

  // ── Vista individual ──
  var modal = document.getElementById('bibModal'), media = document.getElementById('bibMedia'),
      nameEl = document.getElementById('bibName'), noteEl = document.getElementById('bibNote'),
      dateEl = document.getElementById('bibDate'), delEl = document.getElementById('bibDel');
  var cur = null;

  function open(tile) {
    cur = tile;
    var tipo = tile.getAttribute('data-tipo'), url = tile.getAttribute('data-url');
    media.innerHTML = tipo === 'imagen'
      ? '<img src="' + url + '" alt="">'
      : '<video src="' + url + '" controls playsinline preload="metadata"></video>';
    nameEl.value = tile.getAttribute('data-nombre') || '';
    noteEl.value = tile.getAttribute('data-nota') || '';
    dateEl.textContent = tile.getAttribute('data-fecha') || '';
    modal.classList.add('show'); modal.setAttribute('aria-hidden', 'false');
  }
  function close() { modal.classList.remove('show'); modal.setAttribute('aria-hidden', 'true'); media.innerHTML = ''; cur = null; }

  grid.querySelectorAll('.bib-tile').forEach(function (t) { t.addEventListener('click', function () { open(t); }); });
  modal.querySelectorAll('[data-close]').forEach(function (b) { b.addEventListener('click', close); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.classList.contains('show')) close(); });

  // Renombrar (al salir del campo)
  nameEl.addEventListener('blur', function () {
    if (!cur) return; var v = nameEl.value.trim(); if (!v || v === cur.getAttribute('data-nombre')) { nameEl.value = cur.getAttribute('data-nombre'); return; }
    post('renombrar', { id: cur.getAttribute('data-id'), nombre: v }).then(function (d) {
      if (d && d.ok) { cur.setAttribute('data-nombre', d.nombre); var c = cur.querySelector('.cap'); if (c) c.textContent = d.nombre; }
    });
  });
  // Nota (al salir del campo)
  noteEl.addEventListener('blur', function () {
    if (!cur) return; var v = noteEl.value.trim(); if (v === (cur.getAttribute('data-nota') || '')) return;
    post('nota', { id: cur.getAttribute('data-id'), nota: v }).then(function (d) { if (d && d.ok) cur.setAttribute('data-nota', v); });
  });
  // Eliminar
  delEl.addEventListener('click', function () {
    if (!cur) return; if (!confirm('¿Eliminar esto de tu biblioteca? No se puede deshacer.')) return;
    var id = cur.getAttribute('data-id'); delEl.disabled = true;
    post('eliminar', { id: id }).then(function (d) {
      delEl.disabled = false;
      if (d && d.ok) { var t = grid.querySelector('.bib-tile[data-id="' + id + '"]'); if (t) t.remove(); close(); }
      else alert((d && d.err) || 'No se pudo eliminar.');
    }).catch(function () { delEl.disabled = false; alert('Se cayó la conexión.'); });
  });
})();
</script>

<?php require __DIR__ . '/_shell_foot.php'; ?>
