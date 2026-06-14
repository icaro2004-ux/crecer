<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Gráficas con tus fotos
//  panel/graficas.php  ·  sube foto → la IA la vuelve un post
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/agentes.php';
requiere_login();
$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/intake.php'); exit; }
$marca_id = (int)$marca['id'];

$dir_fotos = rtrim(UPLOADS_PATH, '/\\') . "/marca_{$marca_id}/fotos";
$dir_graf  = rtrim(UPLOADS_PATH, '/\\') . "/marca_{$marca_id}/graficas";
$url_fotos = rtrim(UPLOADS_URL, '/') . "/marca_{$marca_id}/fotos";
$url_graf  = rtrim(UPLOADS_URL, '/') . "/marca_{$marca_id}/graficas";

$err = ''; $ok = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'subir' && !empty($_FILES['foto']['tmp_name'])) {
        $f = $_FILES['foto'];
        if ($f['error'] !== UPLOAD_ERR_OK)      $err = 'No se pudo subir la foto.';
        elseif ($f['size'] > 12 * 1024 * 1024)  $err = 'La foto es muy grande (máx 12MB).';
        else {
            $info = @getimagesize($f['tmp_name']);
            $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$info['mime'] ?? ''] ?? null;
            if (!$ext) $err = 'Sube una imagen JPG, PNG o WebP.';
            else {
                @mkdir($dir_fotos, 0775, true);
                move_uploaded_file($f['tmp_name'], $dir_fotos . '/foto_' . uniqid() . '.' . $ext);
                $ok = '✓ Foto subida.';
            }
        }
    } elseif ($accion === 'grafica') {
        $nombre = basename($_POST['foto'] ?? '');
        $src = $dir_fotos . '/' . $nombre;
        if (strpos($nombre, '..') !== false || !is_file($src)) $err = 'Foto inválida.';
        else {
            @set_time_limit(0);
            try { generar_grafica($pdo, $marca_id, $src, ['texto' => trim($_POST['texto'] ?? '')]); $ok = '✓ ¡Post creado!'; }
            catch (Throwable $e) { $err = 'No se pudo crear el post: ' . substr($e->getMessage(), 0, 120); }
        }
    }
    // PRG solo si no hay error (para conservar mensajes)
    if (!$err) { header("Location: /crecer/panel/graficas.php?marca={$marca_id}&ok=1"); exit; }
}

$fotos = is_dir($dir_fotos) ? array_values(array_filter(scandir($dir_fotos), fn($x)=>$x[0]!=='.')) : [];
$graficas = is_dir($dir_graf) ? array_reverse(array_values(array_filter(scandir($dir_graf), fn($x)=>$x[0]!=='.'))) : [];
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$active = 'graficas';
$page_title = 'Gráficas';
require __DIR__ . '/_shell.php';
?>
<style>
  .subline{color:var(--muted);font-size:15px;margin-top:4px}
  .ok-banner{background:var(--okk-bg);color:var(--okk-ink);font-weight:700;font-size:14px;padding:11px 14px;border-radius:12px;margin-top:14px}
  .err-banner{background:var(--noo-bg);color:var(--noo-ink);font-weight:700;font-size:14px;padding:11px 14px;border-radius:12px;margin-top:14px}
  .sec{margin-top:26px}
  .sec h2{font-family:var(--font-display);font-weight:800;font-size:18px;letter-spacing:-.02em;margin-bottom:4px}
  .sec .d{color:var(--muted);font-size:14px;margin-bottom:12px}

  .upload{border:2px dashed var(--line);border-radius:18px;padding:22px;text-align:center;background:var(--card);max-width:560px}
  .upload input[type=file]{display:block;margin:10px auto;font-family:inherit;font-size:14px}
  .upload .btn{border:0;cursor:pointer;font-family:inherit;font-weight:800;font-size:14px;color:#fff;background:var(--palma);padding:11px 22px;border-radius:99px;margin-top:8px}

  .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:14px;margin-top:6px}
  .ph{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:10px;text-align:center}
  .ph img{width:100%;border-radius:10px;display:block;aspect-ratio:1;object-fit:cover}
  .ph form{margin-top:8px}
  .ph .txt{width:100%;font-family:inherit;font-size:12px;border:1.5px solid var(--line);border-radius:9px;padding:7px;margin-bottom:6px}
  .ph .mk{width:100%;border:0;cursor:pointer;font-family:inherit;font-weight:700;font-size:12.5px;color:#fff;background:linear-gradient(135deg,var(--coral),var(--magenta));border-radius:99px;padding:8px}
  .gphoto img{width:100%;border-radius:12px;display:block}
  .gphoto{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:10px}
  .gphoto a{display:block;text-align:center;margin-top:8px;font-weight:700;font-size:12.5px;color:var(--terracota);text-decoration:none}
  .empty{color:var(--muted);font-size:14px}
</style>

<h1 class="page-h">Gráficas con tus fotos 🖼️</h1>
<p class="subline">Sube las fotos de tu negocio y la IA las convierte en posts profesionales — con tu producto real.</p>
<?php if (!empty($_GET['ok'])): ?><div class="ok-banner">✓ Listo.</div><?php endif; ?>
<?php if ($err): ?><div class="err-banner">⚠️ <?= $h($err) ?></div><?php endif; ?>

<div class="sec">
  <h2>1. Sube tus fotos</h2>
  <div class="d">Fotos reales de tus productos/servicios (la IA NUNCA inventa tu producto). JPG, PNG o WebP.</div>
  <form class="upload" method="post" enctype="multipart/form-data">
    <input type="hidden" name="accion" value="subir">
    <div style="font-size:34px">📷</div>
    <input type="file" name="foto" accept="image/png,image/jpeg,image/webp" required>
    <button class="btn" type="submit">Subir foto</button>
  </form>
</div>

<div class="sec">
  <h2>2. Conviértelas en posts</h2>
  <div class="d">Dale a "✨ Crear post" sobre una foto. Puedes añadir un texto (ej. una promo).</div>
  <?php if (!$fotos): ?>
    <p class="empty">Sube una foto arriba para empezar. 👆</p>
  <?php else: ?>
    <div class="grid">
      <?php foreach ($fotos as $fn): ?>
        <div class="ph">
          <img class="zoomable" src="<?= $h($url_fotos.'/'.$fn) ?>" alt="">
          <form method="post" onsubmit="var b=this.querySelector('.mk');b.textContent='✨ Creando…';b.disabled=true;">
            <input type="hidden" name="accion" value="grafica">
            <input type="hidden" name="foto" value="<?= $h($fn) ?>">
            <input class="txt" name="texto" placeholder="Texto en la gráfica (opcional)">
            <button class="mk" type="submit">✨ Crear post</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="sec">
  <h2>3. Tus posts listos</h2>
  <?php if (!$graficas): ?>
    <p class="empty">Aquí aparecerán los posts que cree la IA.</p>
  <?php else: ?>
    <div class="grid">
      <?php foreach ($graficas as $gn): ?>
        <div class="gphoto">
          <img class="zoomable" src="<?= $h($url_graf.'/'.$gn) ?>" alt="post">
          <a href="<?= $h($url_graf.'/'.$gn) ?>" download>⬇ Descargar</a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/_shell_foot.php'; ?>
