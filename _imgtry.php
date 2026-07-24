<?php
// ============================================================
//  CRECER — LABORATORIO de imágenes (interno)  _imgtry.php?k=crecer
//  Banco de pruebas para entrenar/afinar al agente creador:
//   1) EL AGENTE V3: metes un copy → ves la ESCENA que escribe gpt-5.5.
//   2) PROMPT DIRECTO: cualquier prompt → gpt-image-1 (sin agente).
//  Cada paso corre por separado (bajo el timeout de nginx). Borrable.
// ============================================================
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/ia.php';
require __DIR__ . '/includes/agentes.php';
require __DIR__ . '/includes/image_messenger.php';

if (($_GET['k'] ?? '') !== 'crecer') { http_response_code(403); exit('Añade ?k=crecer'); }

$modo   = $_POST['modo'] ?? '';
$prompt = trim((string)($_POST['prompt'] ?? ''));
$copy_in = trim((string)($_POST['copy'] ?? ''));
$marca_id = (int)($_POST['marca'] ?? 0);
$aspect = (string)($_POST['aspect'] ?? '1:1');

$escena = ''; $ag_info = ''; $ag_err = '';
$img_url = ''; $img_info = ''; $img_err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    @set_time_limit(0);
    // MODO 1 — el AGENTE V3: copy → escena (rápido, solo texto).
    if ($modo === 'escena' && $copy_in !== '' && $marca_id) {
        try {
            $m = leer_marca($pdo, $marca_id);
            $b = image_messenger_build($pdo, $marca_id, $m, $copy_in);
            $cfg = defined('IMAGE_CREATIVE_MODEL') ? IMAGE_CREATIVE_MODEL : 'openai:creative';
            $d = director_creativo_llm($pdo, $marca_id, $b['sistema'], $b['mensaje'], $cfg, ['strict'=>true]);
            $escena = trim((string)($d['texto'] ?? ''));
            $ag_info = 'director: ' . ($d['modelo'] ?? '?') . ' · ' . round(((int)($d['dur_ms'] ?? 0))/1000, 1) . 's';
            $prompt = $escena;   // pre-carga la escena en el generador de abajo
        } catch (Throwable $e) { $ag_err = $e->getMessage(); }
    }
    // MODO 2 — PROMPT DIRECTO → gpt-image-1.
    if ($modo === 'imagen' && $prompt !== '') {
        try {
            $t0 = microtime(true);
            $r  = openai_imagen($prompt, ['aspect' => $aspect]);
            $seg = round(microtime(true) - $t0, 1);
            $rel = 'pruebas/lab_' . substr(md5((string)microtime(true)), 0, 8) . '.png';
            $abs = rtrim(UPLOADS_PATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            @mkdir(dirname($abs), 0775, true);
            @file_put_contents($abs, $r['data']);
            $img_url = rtrim(UPLOADS_URL, '/') . '/' . $rel;
            $img_info = 'modelo ' . ($r['modelo'] ?? '?') . ' · ' . $seg . 's · ' . strlen($r['data']) . ' bytes';
        } catch (Throwable $e) { $img_err = $e->getMessage(); }
    }
}

$marcas = $pdo->query("SELECT id, nombre_negocio FROM crecer_marca ORDER BY id DESC LIMIT 60")->fetchAll(PDO::FETCH_ASSOC);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Laboratorio de imágenes · Crecer</title>
<style>
  *{box-sizing:border-box}
  body{font-family:system-ui,-apple-system,sans-serif;max-width:760px;margin:0 auto;padding:22px 18px 70px;background:#faf9f8;color:#231F20}
  h1{font-size:23px;margin:0 0 2px}
  h2{font-size:16px;margin:26px 0 10px;color:#EF4375}
  p.sub{color:#6E6A67;font-size:14px;margin:0 0 16px}
  .box{background:#fff;border:1px solid #E9E7E4;border-radius:16px;padding:18px;margin-bottom:16px}
  textarea{width:100%;min-height:120px;font:15px/1.55 system-ui;padding:12px;border:1.5px solid #E9E7E4;border-radius:12px;background:#fff}
  textarea:focus,select:focus,input:focus{outline:0;border-color:#EF4375}
  select{padding:11px;border-radius:10px;border:1.5px solid #E9E7E4;background:#fff;font:15px system-ui;width:100%;margin-bottom:10px}
  .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px}
  button{background:linear-gradient(135deg,#FF6B3D,#EF4375);color:#fff;border:0;padding:13px 24px;border-radius:13px;font-weight:800;font-size:15px;cursor:pointer}
  button.gho{background:#fff;border:1.5px solid #E9E7E4;color:#333}
  img{width:100%;border-radius:14px;margin-top:14px;border:1px solid #eee;display:block}
  .err{background:#fdeaea;color:#b42318;padding:12px 14px;border-radius:11px;margin-top:12px;font-size:13.5px;white-space:pre-wrap;line-height:1.5}
  .info{color:#00827e;font-weight:700;font-size:12.5px;margin-top:10px}
  .escena{background:#f4f7ff;border:1px solid #dbe4ff;border-radius:12px;padding:13px 15px;margin-top:12px;font-size:14px;line-height:1.55;white-space:pre-wrap}
  .load{display:none;color:#6E6A67;margin-top:12px;font-weight:600}
  small{color:#888}
</style></head>
<body>
<h1>🧪 Laboratorio de imágenes</h1>
<p class="sub">Banco de pruebas interno. Prueba QUÉ escena escribe el agente, y genera imágenes directo con gpt-image-1.</p>

<h2>1 · El agente V3 — copy → escena</h2>
<div class="box">
  <form method="post" onsubmit="document.getElementById('l1').style.display='block'">
    <input type="hidden" name="modo" value="escena">
    <select name="marca" required>
      <option value="">— Elige el negocio —</option>
      <?php foreach ($marcas as $mm): ?>
        <option value="<?= (int)$mm['id'] ?>" <?= $marca_id===(int)$mm['id']?'selected':'' ?>>#<?= (int)$mm['id'] ?> · <?= $h($mm['nombre_negocio']) ?></option>
      <?php endforeach; ?>
    </select>
    <textarea name="copy" placeholder="Pega el copy del post…"><?= $h($copy_in) ?></textarea>
    <div class="row"><button type="submit">Ver la escena que escribe el agente</button> <small>~10s</small></div>
    <div class="load" id="l1">🧠 El agente está pensando…</div>
  </form>
  <?php if ($ag_err): ?><div class="err">❌ <?= $h($ag_err) ?></div><?php endif; ?>
  <?php if ($escena): ?><div class="info"><?= $h($ag_info) ?></div><div class="escena"><?= $h($escena) ?></div>
    <p class="sub" style="margin:10px 0 0">↓ Ya cargué esa escena abajo — dale "Generar imagen" para verla.</p><?php endif; ?>
</div>

<h2>2 · Prompt → gpt-image-1 (directo)</h2>
<div class="box">
  <form method="post" onsubmit="document.getElementById('l2').style.display='block'">
    <input type="hidden" name="modo" value="imagen">
    <textarea name="prompt" placeholder="La escena de arriba, o cualquier prompt (mejor en inglés)…"><?= $h($prompt) ?></textarea>
    <div class="row">
      <select name="aspect" style="width:auto">
        <option value="1:1"  <?= $aspect==='1:1'?'selected':'' ?>>Cuadrado 1:1</option>
        <option value="4:5"  <?= $aspect==='4:5'?'selected':'' ?>>Vertical 4:5</option>
        <option value="16:9" <?= $aspect==='16:9'?'selected':'' ?>>Horizontal 16:9</option>
      </select>
      <button type="submit">Generar imagen</button> <small>~48s</small>
    </div>
    <div class="load" id="l2">🎨 Generando… (no cierres, ~48s)</div>
  </form>
  <?php if ($img_err): ?><div class="err">❌ <?= $h($img_err) ?></div><?php endif; ?>
  <?php if ($img_url): ?><div class="info"><?= $h($img_info) ?></div><img src="<?= $h($img_url) ?>" alt=""><?php endif; ?>
</div>
</body></html>
