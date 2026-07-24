<?php
// ============================================================
//  CRECER — Laboratorio de referencias visuales (panel/lab_referencias.php)
//  Herramienta INTERNA (admin). Sube referencias → analiza (visión) →
//  aprueba → consolida en el Creative Playbook que orienta a V3.
//  NO visible para clientes.
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/suscripcion.php';
require __DIR__ . '/../includes/ia.php';
require __DIR__ . '/../includes/ref_lab.php';
requiere_login();
$usuario = usuario_actual($pdo);
$es_admin = ($usuario['rol'] ?? '') === 'admin' || activacion_de_prueba($usuario['email'] ?? null);
if (!$es_admin) { http_response_code(403); exit('Solo administradores.'); }

$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { $err = 'Sesión expiró, recarga.'; }
    else {
        $accion = $_POST['accion'] ?? '';
        $id = (int)($_POST['id'] ?? 0);
        try {
            if ($accion === 'subir' && !empty($_FILES['imgs'])) {
                $dir = rtrim(UPLOADS_PATH, '/\\') . '/ref_lab';
                @mkdir($dir, 0775, true);
                $n = 0;
                $files = $_FILES['imgs'];
                for ($i = 0; $i < count((array)$files['name']); $i++) {
                    if (($files['error'][$i] ?? 1) !== UPLOAD_ERR_OK) continue;
                    $info = @getimagesize($files['tmp_name'][$i]);
                    $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$info['mime'] ?? ''] ?? null;
                    if (!$ext || $files['size'][$i] > 15*1024*1024) continue;
                    $fn = 'ref_' . substr(md5($files['name'][$i] . microtime(true)), 0, 10) . '.' . $ext;
                    if (move_uploaded_file($files['tmp_name'][$i], $dir . '/' . $fn)) {
                        $url = rtrim(UPLOADS_URL, '/') . '/ref_lab/' . $fn;
                        $pdo->prepare("INSERT INTO crecer_ref_imagenes (archivo, estado) VALUES (?, 'pending')")->execute([$url]);
                        $n++;
                    }
                }
                $msg = "{$n} referencia(s) subida(s).";
            } elseif ($accion === 'analizar' && $id) {
                @set_time_limit(0); ref_analizar($pdo, $id); $msg = "Referencia #{$id} analizada.";
            } elseif ($accion === 'aprobar' && $id) {
                $pdo->prepare("UPDATE crecer_ref_imagenes SET estado='approved' WHERE id=?")->execute([$id]); $msg = "Aprobada.";
            } elseif ($accion === 'rechazar' && $id) {
                $pdo->prepare("UPDATE crecer_ref_imagenes SET estado='rejected' WHERE id=?")->execute([$id]); $msg = "Rechazada.";
            } elseif ($accion === 'borrar_ref' && $id) {
                $pdo->prepare("DELETE FROM crecer_ref_imagenes WHERE id=?")->execute([$id]); $msg = "Referencia borrada.";
            } elseif ($accion === 'consolidar') {
                @set_time_limit(0); $f = ref_consolidar($pdo); $msg = count($f) . " principios consolidados en el Playbook.";
            } elseif ($accion === 'pb_toggle' && $id) {
                $pdo->prepare("UPDATE crecer_playbook SET activo = 1 - activo WHERE id=?")->execute([$id]); $msg = "Principio actualizado.";
            } elseif ($accion === 'pb_editar' && $id) {
                $t = trim((string)($_POST['texto'] ?? '')); if ($t !== '') $pdo->prepare("UPDATE crecer_playbook SET principio=? WHERE id=?")->execute([$t, $id]); $msg = "Principio editado.";
            } elseif ($accion === 'pb_borrar' && $id) {
                $pdo->prepare("DELETE FROM crecer_playbook WHERE id=?")->execute([$id]); $msg = "Principio borrado.";
            } elseif ($accion === 'pb_add') {
                $t = trim((string)($_POST['texto'] ?? '')); if ($t !== '') { $pdo->prepare("INSERT INTO crecer_playbook (principio, activo, origen) VALUES (?,1,'manual')")->execute([$t]); $msg = "Principio añadido."; }
            }
        } catch (Throwable $e) { $err = $e->getMessage(); }
    }
    // PRG: redirige para no re-enviar el POST.
    $_SESSION['lab_flash'] = ['msg'=>$msg, 'err'=>$err];
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit;
}
if (!empty($_SESSION['lab_flash'])) { $msg = $_SESSION['lab_flash']['msg'] ?? ''; $err = $_SESSION['lab_flash']['err'] ?? ''; unset($_SESSION['lab_flash']); }

$refs = $pdo->query("SELECT * FROM crecer_ref_imagenes ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$pbs  = $pdo->query("SELECT * FROM crecer_playbook ORDER BY origen, id")->fetchAll(PDO::FETCH_ASSOC);
$n_aprob = 0; foreach ($refs as $r) if ($r['estado']==='approved') $n_aprob++;
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Laboratorio de referencias · Crecer</title>
<style>
  *{box-sizing:border-box}
  body{font-family:system-ui,-apple-system,sans-serif;max-width:900px;margin:0 auto;padding:22px 18px 70px;background:#faf9f8;color:#231F20}
  h1{font-size:24px;margin:0 0 2px}
  h2{font-size:17px;margin:28px 0 12px;border-bottom:2px solid #EF4375;padding-bottom:6px;display:inline-block}
  p.sub{color:#6E6A67;font-size:14px;margin:0 0 18px}
  .flash{padding:11px 14px;border-radius:11px;font-weight:600;font-size:14px;margin:0 0 14px}
  .ok{background:#e6f6ee;color:#0d7a44}.bad{background:#fdeaea;color:#b42318}
  .card{background:#fff;border:1px solid #E9E7E4;border-radius:14px;padding:16px;margin-bottom:12px}
  .refs{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px}
  .ref img{width:100%;height:150px;object-fit:cover;border-radius:10px;background:#eee}
  .ref .st{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.03em;padding:3px 8px;border-radius:99px;display:inline-block;margin:8px 0 6px}
  .st.pending{background:#fff4d6;color:#8a5a00}.st.analyzed{background:#e7ecff;color:#3a4bb0}.st.approved{background:#e6f6ee;color:#0d7a44}.st.rejected{background:#f3f3f3;color:#888}
  .ref ul{margin:6px 0 10px;padding-left:18px;font-size:12.5px;color:#4A434F;line-height:1.5}
  .ref ul li{margin-bottom:3px}
  button,input[type=submit]{border:0;cursor:pointer;font-family:inherit;font-weight:700;font-size:13px;padding:8px 13px;border-radius:9px;background:#EF4375;color:#fff}
  button.gho{background:#fff;border:1.5px solid #E9E7E4;color:#333}
  button.ok{background:#00A49F}button.no{background:#f3f3f3;color:#b42318}
  .btns{display:flex;gap:6px;flex-wrap:wrap}
  .up{border:1.5px dashed #EF4375;background:#fff;border-radius:14px;padding:18px;text-align:center}
  input[type=file]{margin:8px 0}
  input[type=text]{width:100%;padding:10px 12px;border:1.5px solid #E9E7E4;border-radius:10px;font:14px system-ui}
  .pb{display:flex;align-items:flex-start;gap:10px;padding:11px 0;border-bottom:1px solid #eee}
  .pb.off{opacity:.45}
  .pb .tx{flex:1;font-size:14px;line-height:1.45}
  .pb .tag{font-size:10px;font-weight:800;color:#888;text-transform:uppercase}
  .pill{display:inline-block;font-size:12px;font-weight:700;color:#0d7a44;background:#e6f6ee;padding:4px 10px;border-radius:99px}
  form.inline{display:inline}
</style></head>
<body>
<h1>🧪 Laboratorio de referencias visuales</h1>
<p class="sub">Interno / admin. Enseña al Director Creativo <b>criterio publicitario general</b> — no copia composiciones. Las imágenes NO se envían a las generaciones de clientes; solo el Playbook.</p>
<?php if ($msg): ?><div class="flash ok"><?= $h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="flash bad">⚠️ <?= $h($err) ?></div><?php endif; ?>

<h2>Subir referencias</h2>
<div class="up">
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="accion" value="subir">
    <div>Sube imágenes publicitarias de alta calidad (jpg/png/webp, varias a la vez):</div>
    <input type="file" name="imgs[]" accept="image/*" multiple required>
    <div><input type="submit" value="Subir"></div>
  </form>
</div>

<h2>Referencias (<?= count($refs) ?>) · <span class="pill"><?= $n_aprob ?> aprobadas</span></h2>
<div class="refs">
  <?php foreach ($refs as $r): $an = json_decode((string)$r['analisis_json'], true); $prin = $an['principios'] ?? []; ?>
  <div class="card ref">
    <img src="<?= $h($r['archivo']) ?>" alt="">
    <span class="st <?= $h($r['estado']) ?>"><?= $h($r['estado']) ?></span>
    <?php if ($prin): ?><ul><?php foreach (array_slice($prin,0,6) as $p): ?><li><?= $h($p) ?></li><?php endforeach; ?></ul><?php endif; ?>
    <div class="btns">
      <?php $F = fn($a) => '<form class="inline" method="post">' . csrf_field() . '<input type="hidden" name="id" value="'.$r['id'].'"><input type="hidden" name="accion" value="'.$a.'">'; ?>
      <?= $F('analizar') ?><button type="submit" class="gho"><?= $prin ? 'Re-analizar' : 'Analizar' ?></button></form>
      <?php if ($r['estado'] !== 'approved'): ?><?= $F('aprobar') ?><button type="submit" class="ok">Aprobar</button></form><?php endif; ?>
      <?php if ($r['estado'] !== 'rejected'): ?><?= $F('rechazar') ?><button type="submit" class="no">Rechazar</button></form><?php endif; ?>
      <?= $F('borrar_ref') ?><button type="submit" class="no" onclick="return confirm('¿Borrar?')">🗑</button></form>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (!$refs): ?><p style="color:#888">Aún no hay referencias. Sube algunas arriba.</p><?php endif; ?>
</div>

<h2>Creative Playbook</h2>
<form class="inline" method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="consolidar">
  <button type="submit" <?= $n_aprob ? '' : 'disabled title="Aprueba referencias primero"' ?>>⚙️ Consolidar desde <?= $n_aprob ?> aprobadas</button>
</form>
<p class="sub" style="margin-top:10px">Esto es lo ÚNICO que recibe el Director Creativo (V3) como estándar de calidad.</p>
<div class="card">
  <?php foreach ($pbs as $p): ?>
  <div class="pb <?= $p['activo'] ? '' : 'off' ?>">
    <form class="inline" method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $p['id'] ?>"><input type="hidden" name="accion" value="pb_toggle"><button type="submit" class="gho" title="Activar/Desactivar"><?= $p['activo'] ? '✓' : '○' ?></button></form>
    <div class="tx"><?= $h($p['principio']) ?><br><span class="tag"><?= $h($p['origen']) ?></span></div>
    <form class="inline" method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $p['id'] ?>"><input type="hidden" name="accion" value="pb_borrar"><button type="submit" class="no" onclick="return confirm('¿Borrar?')">🗑</button></form>
  </div>
  <?php endforeach; ?>
  <?php if (!$pbs): ?><p style="color:#888;margin:0">Playbook vacío. Consolida desde las referencias aprobadas, o añade un principio a mano.</p><?php endif; ?>
  <form method="post" style="margin-top:14px;display:flex;gap:8px">
    <?= csrf_field() ?><input type="hidden" name="accion" value="pb_add">
    <input type="text" name="texto" placeholder="Añadir un principio a mano (positivo, general)…">
    <button type="submit">Añadir</button>
  </form>
</div>
</body></html>
