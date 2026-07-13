<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Estudio de arte para posts
//  panel/graficas.php  ·  foto + copy → arte coherente (texto/logo/estilo)
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/agentes.php';
require __DIR__ . '/../includes/suscripcion.php';
requiere_login();
$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }
$marca_id = (int)$marca['id'];
$pagado = marca_es_pagada($pdo, $marca_id);  // no pagado = 1 imagen de muestra

$dir_fotos = rtrim(UPLOADS_PATH, '/\\') . "/marca_{$marca_id}/fotos";
$dir_graf  = rtrim(UPLOADS_PATH, '/\\') . "/marca_{$marca_id}/graficas";
$url_fotos = rtrim(UPLOADS_URL, '/') . "/marca_{$marca_id}/fotos";
$url_graf  = rtrim(UPLOADS_URL, '/') . "/marca_{$marca_id}/graficas";

// ¿Venimos a crear el arte de un post del calendario?
$post_id = (int)($_GET['post'] ?? $_POST['post'] ?? 0);
$post_caption = '';
if ($post_id) {
    $ps = $pdo->prepare("SELECT caption FROM crecer_contenido WHERE id=? AND marca_id=?");
    $ps->execute([$post_id, $marca_id]);
    $row = $ps->fetch();
    if ($row) $post_caption = (string)$row['caption']; else $post_id = 0;
}

// Límite: 5 imágenes por semana (ventana de 7 días). Se recargan a los 7 días.
$LIMITE_SEM = CRECER_IMG_SEMANA;
$wk = $pdo->prepare("SELECT COUNT(*) c, MIN(created_at) oldest FROM crecer_graficas WHERE marca_id=? AND created_at >= (NOW() - INTERVAL 7 DAY)");
$wk->execute([$marca_id]); $w = $wk->fetch();
$usados_sem = (int)$w['c'];
$restantes_sem = max(0, $LIMITE_SEM - $usados_sem);
$reset_fecha = $w['oldest'] ? date('d/m', strtotime($w['oldest'] . ' +7 days')) : null;
// Cuota: pagado usa el límite semanal; no pagado tiene 1 imagen de muestra.
$perm_img   = puede_generar_tipo($pdo, $marca_id, 'imagen');
$puede_arte = $pagado ? ($restantes_sem > 0) : $perm_img['ok'];

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
        $perm = puede_generar_tipo($pdo, $marca_id, 'imagen');
        if (!$perm['ok']) {
            $err = 'Ya usaste tu imagen de muestra gratis. Activa un plan para crear más arte con IA.';
        } elseif ($perm['pagado'] && $restantes_sem <= 0) {
            $err = "Usaste tus {$LIMITE_SEM} imágenes de la semana. Se recargan el {$reset_fecha}.";
        } else {
        $nombre = basename($_POST['foto'] ?? '');
        $src = ($nombre && strpos($nombre,'..')===false && is_file($dir_fotos.'/'.$nombre)) ? $dir_fotos.'/'.$nombre : null;
        @set_time_limit(0);
        try {
            $r = generar_grafica($pdo, $marca_id, $src, [
                'copy'         => trim($_POST['copy'] ?? ''),
                'con_texto'    => ($_POST['con_texto'] ?? '') === '1',
                'con_logo'     => !empty($_POST['con_logo']),
                'logo_estilo'  => $_POST['logo_estilo'] ?? 'esquina',
                'estilo'       => $_POST['estilo'] ?? '',
                'instrucciones'=> trim($_POST['instrucciones'] ?? ''),
            ]);
            // Si vino de un post del calendario, le pegamos el arte
            if ($post_id) {
                $pdo->prepare("UPDATE crecer_contenido SET grafica_path=?, updated_at=NOW() WHERE id=? AND marca_id=?")
                    ->execute([$r['archivo'], $post_id, $marca_id]);
                header("Location: /crecer/panel/aprobar2.php?marca={$marca_id}"); exit;
            }
        } catch (Throwable $e) { $err = 'No se pudo crear el arte: ' . substr($e->getMessage(), 0, 120); }
        }
    } elseif ($accion === 'publicar') {
        $gid = (int)($_POST['gid'] ?? 0);
        $pdo->prepare("UPDATE crecer_graficas SET publicado=1 WHERE id=? AND marca_id=?")->execute([$gid, $marca_id]);
    }
    if (!$err) { header("Location: /crecer/panel/graficas.php?marca={$marca_id}&ok=1"); exit; }
}

$fotos = is_dir($dir_fotos) ? array_values(array_filter(scandir($dir_fotos), fn($x)=>$x[0]!=='.')) : [];
$posts_g = $pdo->prepare("SELECT * FROM crecer_graficas WHERE marca_id=? ORDER BY id DESC");
$posts_g->execute([$marca_id]); $posts_g = $posts_g->fetchAll();
$tiene_logo = !empty($marca['logo_path']);
$handle = $marca['instagram'] ?: ('@' . preg_replace('/[^a-z0-9]/', '', mb_strtolower($marca['nombre_negocio'])));
$avatar = $marca['logo_path'] ?: '/crecer/assets/brand/encuentralo-pin.svg';
// posts del calendario (para atar el copy)
$posts = $pdo->prepare("SELECT caption FROM crecer_contenido WHERE marca_id=? AND caption<>'' ORDER BY fecha_programada DESC LIMIT 12");
$posts->execute([$marca_id]); $posts = $posts->fetchAll();
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$active = 'graficas';
$page_title = 'Gráficas';
$guia = ['key'=>'graficas','agente'=>'image','titulo'=>'Estudio de arte',
  'intro'=>'El Diseñador convierte tus fotos reales en arte de post profesional.',
  'pasos'=>[
    ['camera','Sube una foto real de tu producto (la IA nunca lo inventa).'],
    ['sparkles','Escoge estilo y dale "Crear el arte" — el Diseñador lo monta.'],
    ['eye','"Ver en redes" te muestra cómo queda en Instagram y Facebook.'],
  ]];
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
  .prevbtn{width:100%;margin-top:8px;border:1.5px solid var(--line);background:#fff;color:var(--tinta);font-family:inherit;font-weight:700;font-size:12.5px;cursor:pointer;border-radius:99px;padding:8px}
  .prevbtn:hover{border-color:var(--terracota);color:var(--terracota-700)}
  .prev-ov{display:none;position:fixed;inset:0;background:rgba(20,12,8,.7);z-index:90;align-items:flex-start;justify-content:center;padding:28px 16px;overflow:auto}
  .prev-ov.show{display:flex}
  .prev-box{background:var(--crema);border-radius:var(--r-xl);padding:18px;max-width:420px;width:100%;position:relative}
  .prev-x{position:absolute;top:12px;right:14px;border:0;background:none;font-size:20px;cursor:pointer;color:var(--muted)}
  .prev-tabs{display:flex;gap:8px;justify-content:center;margin-bottom:14px}
  .ptab{border:1.5px solid var(--line);background:#fff;font-family:inherit;font-weight:700;font-size:13px;cursor:pointer;border-radius:99px;padding:7px 14px}
  .ptab.on{border-color:transparent;color:#fff;background:linear-gradient(135deg,var(--coral),var(--magenta))}
  .mock{background:#fff;border:1px solid var(--line);border-radius:14px;overflow:hidden;max-width:360px;margin:0 auto;box-shadow:var(--shadow);color:#111}
  .mock .av{width:34px;height:34px;border-radius:50%;object-fit:cover;background:#eee}
  .mock .post-img{width:100%;display:block}
  .ig-head{display:flex;align-items:center;gap:9px;padding:10px 12px;font-size:14px}
  .ig-head .dots{margin-left:auto;color:#888}
  .ig-acts{display:flex;gap:14px;padding:10px 12px 4px;font-size:20px}
  .ig-acts .sp{flex:1}
  .ig-likes{padding:0 12px;font-size:13px;font-weight:700}
  .ig-cap{padding:4px 12px 14px;font-size:13.5px;line-height:1.4}
  .fb-head{display:flex;align-items:center;gap:9px;padding:12px}
  .fb-meta{font-size:12px;color:#888}
  .fb-text{padding:0 12px 10px;font-size:14px;line-height:1.4;white-space:pre-wrap}
  .fb-bar{display:flex;justify-content:space-around;padding:10px;border-top:1px solid #eee;font-size:13px;color:#555}
  .prev-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:center;margin-top:16px}
  .pa{border:1.5px solid var(--line);background:#fff;color:var(--tinta);font-family:inherit;font-weight:700;font-size:13px;cursor:pointer;border-radius:99px;padding:9px 14px;text-decoration:none}
  .pa.pub{background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff;border-color:transparent}
  .prev-note{font-size:11.5px;color:var(--muted);text-align:center;margin-top:12px}
</style>

<h1 class="page-h">Estudio de arte</h1>
<p class="subline">Convierte tus fotos en posts profesionales — la imagen va acorde a tu copy. Tú controlas todo.</p>
<?php if (!empty($_GET['ok'])): ?><div class="ok-banner">✓ Listo.</div><?php endif; ?>
<?php if ($err): ?><div class="err-banner">⚠️ <?= $h($err) ?></div><?php endif; ?>

<!-- 1. SUBIR -->
<div class="sec">
  <h2>1. Tus fotos</h2>
  <div class="d">Sube fotos reales de tus productos (la IA nunca inventa tu producto).</div>
  <form class="card2" method="post" enctype="multipart/form-data">
    <input type="hidden" name="accion" value="subir">
    <div class="uprow" style="display:flex;align-items:center;gap:8px"><?= ico('camera') ?> <input type="file" name="foto" accept="image/png,image/jpeg,image/webp" required>
      <button class="btnp" type="submit">Subir</button></div>
  </form>
</div>

<!-- 2. ESTUDIO -->
<div class="sec">
  <h2>2. Crea el arte de tu post</h2>
  <form class="card2" method="post" onsubmit="var b=this.querySelector('.genbtn');b.textContent='✨ Creando… (~15s)';b.disabled=true;">
    <input type="hidden" name="accion" value="arte">
    <input type="hidden" name="post" value="<?= $post_id ?>">
    <?php if ($post_id): ?>
      <div style="background:var(--okk-bg);color:var(--okk-ink);font-weight:700;font-size:13.5px;padding:11px 14px;border-radius:12px;margin-bottom:14px">🎯 Creando el arte para tu post — al terminar se adjunta solo. <a href="/crecer/panel/aprobar2.php?marca=<?= $marca_id ?>" style="color:var(--okk-ink);font-weight:800">← volver al post</a></div>
    <?php endif; ?>

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
    <textarea id="copy" name="copy" rows="2" placeholder="Ej. ¡Mi gente! El bizcocho de guayaba fresquecito, ordena por WhatsApp 🇵🇷"><?= $h($post_caption) ?></textarea>

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

    <label class="fl">Dile a la IA cómo editar la foto <span style="color:var(--muted);font-weight:500">(opcional)</span></label>
    <textarea name="instrucciones" rows="2" placeholder="Ej. &quot;quítale el fondo y ponlo en una mesa de madera&quot;, &quot;añade confeti&quot;, &quot;que se vea de noche&quot;, &quot;estilo navideño&quot;…"></textarea>

    <label class="fl">Extras</label>
    <div class="row2">
      <label class="ck"><input type="checkbox" name="con_logo" value="1" id="cklogo" <?= $tiene_logo?'':'disabled' ?>> Incluir mi logo <?= $tiene_logo?'':'<span style="color:var(--muted);font-weight:500">(crea tu logo primero)</span>' ?></label>
    </div>
    <?php if ($tiene_logo): ?>
    <div id="logoest" style="margin-top:10px">
      <label class="fl" style="margin-top:0">¿Cómo poner el logo? <span style="color:var(--muted);font-weight:500">(la IA lo adapta, sin fondo blanco)</span></label>
      <div class="chips">
        <label class="chip-opt"><input type="radio" name="logo_estilo" value="watermark" checked><span>Marca de agua</span></label>
        <label class="chip-opt"><input type="radio" name="logo_estilo" value="esquina"><span>En la esquina</span></label>
        <label class="chip-opt"><input type="radio" name="logo_estilo" value="integrado"><span>Integrado al diseño</span></label>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($puede_arte): ?>
      <button class="genbtn" type="submit">Crear el arte</button>
      <?php if ($pagado): ?>
        <div class="costnote">Te quedan <b style="color:var(--terracota)"><?= $restantes_sem ?> de <?= $LIMITE_SEM ?></b> imágenes esta semana. Sin texto = más barato; con texto = modelo Pro (letras perfectas).</div>
      <?php else: ?>
        <div class="costnote" style="display:flex;align-items:center;gap:7px"><?= ico('gift') ?> <span><b style="color:var(--terracota)">1 imagen de muestra gratis.</b> Actívate para crear todas las que necesites.</span></div>
      <?php endif; ?>
    <?php elseif ($pagado): ?>
      <div class="costnote" style="background:var(--amber-bg);color:var(--amber-ink);padding:12px;border-radius:12px;font-weight:600">
        Usaste tus <?= $LIMITE_SEM ?> imágenes de esta semana. Se recargan el <?= $h($reset_fecha) ?>.
      </div>
    <?php else: ?>
      <div class="costnote" style="background:var(--amber-bg);color:var(--amber-ink);padding:12px;border-radius:12px;font-weight:600">
        Ya usaste tu imagen de muestra gratis.<br>
        <a href="/crecer/panel/precios.php?marca=<?= $marca_id ?>" style="color:var(--terracota-700);font-weight:800">⚡ Activa un plan para crear más arte →</a>
      </div>
    <?php endif; ?>
  </form>
</div>

<!-- 3. RESULTADOS -->
<div class="sec" style="max-width:none">
  <h2>3. Tus posts listos</h2>
  <?php if (!$posts_g): ?>
    <p class="empty">Aquí aparecerán los posts que cree la IA. Toca "Vista previa" para verlo como saldría en redes.</p>
  <?php else: ?>
    <div class="grid">
      <?php foreach ($posts_g as $g): ?>
        <div class="gphoto">
          <img class="zoomable" src="<?= $h($g['archivo']) ?>" alt="post">
          <?php if ($g['publicado']): ?><div style="text-align:center;margin-top:6px"><span style="font-size:11px;font-weight:800;color:var(--okk-ink);background:var(--okk-bg);padding:3px 9px;border-radius:99px">✓ PUBLICADO</span></div><?php endif; ?>
          <button class="prevbtn" type="button"
            onclick="openPrev(<?= (int)$g['id'] ?>, '<?= $h($g['archivo']) ?>', this.dataset.copy)"
            data-copy="<?= $h($g['copy_text'] ?? '') ?>"><?= ico('eye') ?> Ver en redes (IG/FB)</button>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- MODAL PREVIEW REDES -->
<div class="prev-ov" id="prevov">
  <div class="prev-box">
    <button class="prev-x" onclick="document.getElementById('prevov').classList.remove('show')">✕</button>
    <div class="prev-tabs">
      <button class="ptab on" data-net="ig" onclick="setNet('ig')"><?= ico('instagram') ?> Instagram</button>
      <button class="ptab" data-net="fb" onclick="setNet('fb')"><?= ico('facebook') ?> Facebook</button>
    </div>

    <!-- Instagram -->
    <div class="mock ig" id="m-ig">
      <div class="ig-head"><img class="av" src="<?= $h($avatar) ?>"><b><?= $h($handle) ?></b><span class="dots">•••</span></div>
      <img class="post-img" id="ig-img" src="">
      <div class="ig-acts"><span>♡</span><span>💬</span><span>➤</span><span class="sp"></span><span>🔖</span></div>
      <div class="ig-likes">A 47 personas les gusta esto</div>
      <div class="ig-cap"><b><?= $h($handle) ?></b> <span id="ig-cap"></span></div>
    </div>

    <!-- Facebook -->
    <div class="mock fb" id="m-fb" style="display:none">
      <div class="fb-head"><img class="av" src="<?= $h($avatar) ?>"><div><b><?= $h($marca['nombre_negocio']) ?></b><div class="fb-meta">Justo ahora · 🌐</div></div></div>
      <div class="fb-text" id="fb-cap"></div>
      <img class="post-img" id="fb-img" src="">
      <div class="fb-bar"><span>👍 Me gusta</span><span>💬 Comentar</span><span>➤ Compartir</span></div>
    </div>

    <div class="prev-actions">
      <button type="button" class="pa" onclick="copiarCopy()"><?= ico('copy') ?> Copiar copy</button>
      <a class="pa" id="pa-dl" href="" download>⬇ Descargar imagen</a>
      <form method="post" style="display:inline" id="pubform"><input type="hidden" name="accion" value="publicar"><input type="hidden" name="gid" id="pub-gid"><button class="pa pub" type="submit">Publicar</button></form>
    </div>
    <div class="prev-note">Por ahora "Publicar" lo marca como publicado y te da el copy + la imagen para subirla. La publicación automática a IG/FB (conexión con Meta) viene pronto.</div>
  </div>
</div>
<textarea id="copybuffer" style="position:absolute;left:-9999px"></textarea>

<script>
  function openPrev(gid, img, copy){
    document.getElementById('ig-img').src = img;
    document.getElementById('fb-img').src = img;
    document.getElementById('ig-cap').textContent = copy || '';
    document.getElementById('fb-cap').textContent = copy || '';
    document.getElementById('pa-dl').href = img;
    document.getElementById('pub-gid').value = gid;
    document.getElementById('copybuffer').value = copy || '';
    setNet('ig');
    document.getElementById('prevov').classList.add('show');
  }
  function setNet(n){
    document.getElementById('m-ig').style.display = n==='ig' ? '' : 'none';
    document.getElementById('m-fb').style.display = n==='fb' ? '' : 'none';
    document.querySelectorAll('.ptab').forEach(function(t){ t.classList.toggle('on', t.dataset.net===n); });
  }
  function copiarCopy(){
    var t = document.getElementById('copybuffer');
    if (navigator.clipboard) navigator.clipboard.writeText(t.value);
    else { t.select(); document.execCommand('copy'); }
    event.target.textContent = '✓ Copiado';
  }
  document.getElementById('prevov').addEventListener('click', function(e){ if(e.target===this) this.classList.remove('show'); });
</script>

<?php require __DIR__ . '/_shell_foot.php'; ?>
