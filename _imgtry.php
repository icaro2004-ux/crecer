<?php
// ============================================================
//  CRECER — Probar un prompt suelto → gpt-image-1 (directo)
//  _imgtry.php?k=crecer   ·  pega un prompt, genera, ve la imagen.
//  Sin V3, sin director, sin rulebook: tu texto va TAL CUAL al modelo.
//  Borrable cuando quieras.
// ============================================================
require __DIR__ . '/includes/db.php';   // config + constantes
require __DIR__ . '/includes/ia.php';   // openai_imagen

if (($_GET['k'] ?? '') !== 'crecer') { http_response_code(403); exit('Añade ?k=crecer'); }

$prompt = trim((string)($_POST['prompt'] ?? ''));
$aspect = (string)($_POST['aspect'] ?? '1:1');
$img_url = ''; $info = ''; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $prompt !== '') {
    @set_time_limit(0);
    try {
        $t0 = microtime(true);
        $r  = openai_imagen($prompt, ['aspect' => $aspect]);
        $seg = round(microtime(true) - $t0, 1);
        $rel = 'pruebas/try_' . substr(md5((string)microtime(true)), 0, 8) . '.png';
        $abs = rtrim(UPLOADS_PATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        @mkdir(dirname($abs), 0775, true);
        @file_put_contents($abs, $r['data']);
        $img_url = rtrim(UPLOADS_URL, '/') . '/' . $rel;
        $info = 'modelo ' . ($r['modelo'] ?? '?') . ' · ' . $seg . 's · ' . strlen($r['data']) . ' bytes';
    } catch (Throwable $e) { $err = $e->getMessage(); }
}
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="es"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Probar prompt → gpt-image-1</title>
<style>
  *{box-sizing:border-box}
  body{font-family:system-ui,-apple-system,sans-serif;max-width:720px;margin:0 auto;padding:22px 18px 60px;background:#faf9f8;color:#231F20}
  h2{margin:0 0 4px}
  p.sub{color:#6E6A67;font-size:14px;margin:0 0 18px}
  textarea{width:100%;min-height:170px;font:15px/1.55 system-ui;padding:13px;border:1.5px solid #E9E7E4;border-radius:14px;background:#fff}
  textarea:focus{outline:0;border-color:#EF4375}
  .row{display:flex;align-items:center;gap:10px;margin-top:12px;flex-wrap:wrap}
  select{padding:10px;border-radius:10px;border:1.5px solid #E9E7E4;background:#fff;font:14px system-ui}
  button{background:linear-gradient(135deg,#FF6B3D,#EF4375);color:#fff;border:0;padding:14px 26px;border-radius:14px;font-weight:800;font-size:16px;cursor:pointer}
  button:active{transform:scale(.98)}
  img{width:100%;border-radius:16px;margin-top:16px;border:1px solid #eee;display:block}
  .err{background:#fdeaea;color:#b42318;padding:13px 15px;border-radius:12px;margin-top:14px;font-size:14px;line-height:1.5;white-space:pre-wrap}
  .info{color:#00827e;font-weight:700;font-size:13px;margin-top:14px}
  .load{display:none;color:#6E6A67;margin-top:14px;font-weight:600}
</style></head>
<body>
<h2>Probar un prompt → gpt-image-1</h2>
<p class="sub">Pega cualquier prompt (mejor en inglés). Va <b>directo al modelo</b>, sin V3 ni director. Tarda ~40s.</p>

<form method="post" onsubmit="document.getElementById('l').style.display='block'">
  <textarea name="prompt" placeholder="A cinematic advertising scene of..." autofocus><?= $h($prompt) ?></textarea>
  <div class="row">
    <label>Formato:
      <select name="aspect">
        <option value="1:1"  <?= $aspect==='1:1'?'selected':'' ?>>Cuadrado 1:1</option>
        <option value="4:5"  <?= $aspect==='4:5'?'selected':'' ?>>Vertical 4:5</option>
        <option value="16:9" <?= $aspect==='16:9'?'selected':'' ?>>Horizontal 16:9</option>
      </select>
    </label>
    <button type="submit">Generar imagen</button>
  </div>
  <div class="load" id="l">🎨 Generando… (no cierres, ~40s)</div>
</form>

<?php if ($err): ?><div class="err">❌ <?= $h($err) ?></div><?php endif; ?>
<?php if ($img_url): ?>
  <div class="info"><?= $h($info) ?></div>
  <img src="<?= $h($img_url) ?>" alt="">
<?php endif; ?>
</body></html>
