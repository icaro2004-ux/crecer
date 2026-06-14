<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Estudio de arte para posts
//  panel/graficas.php  ·  foto + copy → arte coherente (texto/logo/estilo)
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

$err = '';
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
            else { @mkdir($dir_fotos, 0775, true); move_uploaded_file($f['tmp_name'], $dir_fotos.'/foto_'.uniqid().'.'.$ext); }
        }
    } elseif ($accion === 'arte') {
        $nombre = basename($_POST['foto'] ?? '');
        $src = ($nombre && strpos($nombre,'..')===false && is_file($dir_fotos.'/'.$nombre)) ? $dir_fotos.'/'.$nombre : null;
        @set_time_limit(0);
        try {
            generar_grafica($pdo, $marca_id, $src, [
                'copy'      => trim($_POST['copy'] ?? ''),
                'con_texto' => ($_POST['con_texto'] ?? '') === '1',
                'con_logo'  => !empty($_POST['con_logo']),
                'estilo'    => $_POST['estilo'] ?? '',
            ]);
        } catch (Throwable $e) { $err = 'No se pudo crear el arte: ' . substr($e->getMessage(), 0, 120); }
    }
    if (!$err) { header("Location: /crecer/panel/graficas.php?marca={$marca_id}&ok=1"); exit; }
}

$fotos = is_dir($dir_fotos) ? array_values(array_filter(scandir($dir_fotos), fn($x)=>$x[0]!=='.')) : [];
$graficas = is_dir($dir_graf) ? array_reverse(array_values(array_filter(scandir($dir_graf), fn($x)=>$x[0]!=='.'))) : [];
$tiene_logo = !empty($marca['logo_path']);
// posts del calendario (para atar el copy)
$posts = $pdo->prepare("SELECT caption FROM crecer_contenido WHERE marca_id=? AND caption<>'' ORDER BY fecha_programada DESC LIMIT 12");
$posts->execute([$marca_id]); $posts = $posts->fetchAll();
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$active = 'graficas';
$page_title = 'Gráficas';
require __DIR__ . '/_shell.php';
?>
<style>
  .subline{color:var(--muted);font-size:15px;margin-top:4px}
  .ok-banner{background:var(--okk-bg);color:var(--okk-ink);font-weight:700;font-size:14px;padding:11px 14px;border-radius:12px;margin-top:14px}
  .err-banner{background:var(--noo-bg);color:var(--noo-ink);font-weight:700;font-size:14px;padding:11px 14px;border-radius:12px;margin-top:14px}
  .sec{margin-top:24px;max-width:720px}
  .sec h2{font-family:var(--font-display);font-weight:800;font-size:17px;letter-spacing:-.02em;margin-bottom:4px}
  .sec .d{color:var(--muted);font-size:13.5px;margin-bottom:12px}
  .card2{background:var(--card);border:1px solid var(--line);border-radius:var(--r-lg);padding:18px;box-shadow:var(--shadow-sm)}

  .uprow{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
  .uprow input[type=file]{font-family:inherit;font-size:13px;flex:1;min-width:180px}
  .btnp{border:0;cursor:pointer;font-family:inherit;font-weight:800;font-size:13px;color:#fff;background:var(--palma);padding:10px 18px;border-radius:99px}

  .fl{display:block;font-weight:700;font-size:13px;margin:14px 0 7px}
  .picker{display:flex;gap:8px;flex-wrap:wrap}
  .pk{cursor:pointer}.pk input{position:absolute;opacity:0}
  .pk img,.pk .none{width:72px;height:72px;border-radius:12px;object-fit:cover;border:2.5px solid var(--line);display:block}
  .pk .none{display:grid;place-items:center;font-size:11px;color:var(--muted);text-align:center;background:var(--crema);line-height:1.1}
  .pk input:checked + img,.pk input:checked + .none{border-color:var(--terracota)}
  textarea,select{width:100%;font-family:inherit;font-size:14px;border:1.5px solid var(--line);border-radius:12px;padding:10px 12px}
  .chips{display:flex;flex-wrap:wrap;gap:7px}
  .chip-opt{cursor:pointer}.chip-opt input{position:absolute;opacity:0}
  .chip-opt span{display:inline-block;padding:7px 13px;border-radius:99px;border:1.5px solid var(--line);background:#fff;font-weight:700;font-size:13px}
  .chip-opt input:checked + span{border-color:transparent;color:#fff;background:linear-gradient(135deg,var(--coral),var(--magenta))}
  .row2{display:flex;gap:18px;flex-wrap:wrap;align-items:center}
  .ck{display:flex;align-items:center;gap:7px;font-weight:700;font-size:14px}
  .genbtn{border:0;cursor:pointer;font-family:inherit;font-weight:800;font-size:15px;color:#fff;background:linear-gradient(135deg,var(--coral),var(--magenta));padding:13px 24px;border-radius:99px;margin-top:16px;box-shadow:0 10px 24px rgba(255,43,133,.28)}
  .costnote{font-size:11.5px;color:var(--muted);margin-top:8px}

  .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;margin-top:6px}
  .gphoto{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:10px}
  .gphoto img{width:100%;border-radius:12px;display:block}
  .gphoto a{display:block;text-align:center;margin-top:8px;font-weight:700;font-size:12.5px;color:var(--terracota);text-decoration:none}
  .empty{color:var(--muted);font-size:14px}
</style>

<h1 class="page-h">Estudio de arte 🖼️</h1>
<p class="subline">Convierte tus fotos en posts profesionales — la imagen va acorde a tu copy. Tú controlas todo.</p>
<?php if (!empty($_GET['ok'])): ?><div class="ok-banner">✓ Listo.</div><?php endif; ?>
<?php if ($err): ?><div class="err-banner">⚠️ <?= $h($err) ?></div><?php endif; ?>

<!-- 1. SUBIR -->
<div class="sec">
  <h2>1. Tus fotos</h2>
  <div class="d">Sube fotos reales de tus productos (la IA nunca inventa tu producto).</div>
  <form class="card2" method="post" enctype="multipart/form-data">
    <input type="hidden" name="accion" value="subir">
    <div class="uprow">📷 <input type="file" name="foto" accept="image/png,image/jpeg,image/webp" required>
      <button class="btnp" type="submit">Subir</button></div>
  </form>
</div>

<!-- 2. ESTUDIO -->
<div class="sec">
  <h2>2. Crea el arte de tu post</h2>
  <form class="card2" method="post" onsubmit="var b=this.querySelector('.genbtn');b.textContent='✨ Creando… (~15s)';b.disabled=true;">
    <input type="hidden" name="accion" value="arte">

    <label class="fl">Escoge la foto base</label>
    <div class="picker">
      <?php foreach ($fotos as $i=>$fn): ?>
        <label class="pk"><input type="radio" name="foto" value="<?= $h($fn) ?>" <?= $i===0?'checked':'' ?>><img src="<?= $h($url_fotos.'/'.$fn) ?>" alt=""></label>
      <?php endforeach; ?>
      <label class="pk"><input type="radio" name="foto" value="" <?= !$fotos?'checked':'' ?>><span class="none">Sin foto<br>(generar)</span></label>
    </div>

    <label class="fl">El copy del post <span style="color:var(--muted);font-weight:500">(la imagen irá acorde a esto)</span></label>
    <?php if ($posts): ?>
      <select onchange="if(this.value)document.getElementById('copy').value=this.value" style="margin-bottom:8px">
        <option value="">— jala el copy de un post del calendario, o escribe abajo —</option>
        <?php foreach ($posts as $p): ?><option value="<?= $h($p['caption']) ?>"><?= $h(mb_substr($p['caption'],0,55)) ?>…</option><?php endforeach; ?>
      </select>
    <?php endif; ?>
    <textarea id="copy" name="copy" rows="2" placeholder="Ej. ¡Mi gente! El bizcocho de guayaba fresquecito, ordena por WhatsApp 🇵🇷"></textarea>

    <label class="fl">¿Texto sobre la imagen?</label>
    <div class="chips">
      <label class="chip-opt"><input type="radio" name="con_texto" value="0" checked><span>Solo mejorar la foto</span></label>
      <label class="chip-opt"><input type="radio" name="con_texto" value="1"><span>Con texto (gancho del copy)</span></label>
    </div>

    <label class="fl">Estilo</label>
    <div class="chips">
      <?php foreach (['Auto'=>'', 'Boricua'=>'boricua, alegre', 'Elegante'=>'elegante y premium', 'Minimalista'=>'minimalista y limpio', 'Vibrante'=>'colores vibrantes', 'Apetitoso'=>'apetitoso, food photography'] as $lb=>$val): ?>
        <label class="chip-opt"><input type="radio" name="estilo" value="<?= $h($val) ?>" <?= $lb==='Auto'?'checked':'' ?>><span><?= $h($lb) ?></span></label>
      <?php endforeach; ?>
    </div>

    <label class="fl">Extras</label>
    <div class="row2">
      <label class="ck"><input type="checkbox" name="con_logo" value="1" <?= $tiene_logo?'':'disabled' ?>> Incluir mi logo <?= $tiene_logo?'':'<span style="color:var(--muted);font-weight:500">(crea tu logo primero)</span>' ?></label>
    </div>

    <button class="genbtn" type="submit">✨ Crear el arte</button>
    <div class="costnote">Sin texto: imagen limpia (más barato). Con texto: usamos el modelo Pro para que las letras salgan perfectas.</div>
  </form>
</div>

<!-- 3. RESULTADOS -->
<div class="sec" style="max-width:none">
  <h2>3. Tus posts listos</h2>
  <?php if (!$graficas): ?>
    <p class="empty">Aquí aparecerán los posts que cree la IA. Toca uno para verlo grande.</p>
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
