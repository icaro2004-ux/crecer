<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Mi Marca (identidad / logo con IA)
//  panel/marca.php
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/agentes.php';
requiere_login();
$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/intake.php'); exit; }
$marca_id = (int)$marca['id'];

// Límite de pruebas de logo (cada imagen cuesta; no puede ser infinito).
$LIMITE_LOGO = 10;
$cnt = $pdo->prepare("SELECT COUNT(*) FROM crecer_ia_log WHERE marca_id=? AND accion LIKE 'Generar logo%' AND estado='ok'");
$cnt->execute([$marca_id]);
$usados = (int)$cnt->fetchColumn();

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'logo') {
    if ($usados >= $LIMITE_LOGO) {
        $err = "Llegaste a tus {$LIMITE_LOGO} pruebas de logo. Para más, sube de plan o usa tu propia llave de IA.";
    } else {
        @set_time_limit(0);
        try { generar_logo($pdo, $marca_id, trim($_POST['instrucciones'] ?? '')); }
        catch (Throwable $e) { $err = 'No se pudo generar el logo: ' . substr($e->getMessage(), 0, 120); }
        if (!$err) { header('Location: /crecer/panel/marca.php?marca=' . $marca_id . '&ok=1'); exit; }
    }
}
$restantes = max(0, $LIMITE_LOGO - $usados);

// recargar marca (por si se actualizó el logo)
$marca = marca_del_usuario($pdo, (int)$usuario['id'], $marca_id);
$productos = $marca['productos'] ? json_decode($marca['productos'], true) : [];
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$active = 'marca';
$page_title = 'Mi Marca';
require __DIR__ . '/_shell.php';
?>
<style>
  .mk{display:grid;grid-template-columns:300px 1fr;gap:24px;margin-top:18px;align-items:start}
  .logobox{background:var(--card);border:1px solid var(--line);border-radius:var(--r-xl);padding:24px;text-align:center;box-shadow:var(--shadow-sm)}
  .logobox img{width:100%;max-width:240px;border-radius:16px}
  .logobox .ph{width:100%;aspect-ratio:1;border:2px dashed var(--line);border-radius:16px;display:grid;place-items:center;color:var(--muted);font-size:46px}
  .logobox form{margin-top:16px}
  .genbtn{width:100%;border:0;cursor:pointer;font-family:inherit;font-weight:800;font-size:15px;color:#fff;background:linear-gradient(135deg,var(--coral),var(--magenta));padding:14px;border-radius:99px;box-shadow:0 10px 24px rgba(255,43,133,.28)}
  .genbtn.alt{background:none;color:var(--muted);box-shadow:none;border:1.5px solid var(--line);margin-top:8px}
  .hint{font-size:12.5px;color:var(--muted);margin-top:10px}
  .info .pcard{margin-bottom:14px}
  .info h3{font-family:var(--font-display);font-weight:800;font-size:15px;margin-bottom:8px}
  .kv{font-size:14px;margin:5px 0}.kv b{color:var(--muted);font-weight:700}
  .tags{display:flex;flex-wrap:wrap;gap:7px;margin-top:6px}
  .tag2{font-size:13px;font-weight:600;background:var(--crema);border:1px solid var(--line);border-radius:99px;padding:6px 12px}
  .ok-banner{background:var(--okk-bg);color:var(--okk-ink);font-weight:700;font-size:14px;padding:11px 14px;border-radius:12px;margin-top:14px}
  .err-banner{background:var(--noo-bg);color:var(--noo-ink);font-weight:700;font-size:14px;padding:11px 14px;border-radius:12px;margin-top:14px}
  .dl{margin:14px 0 4px;text-align:center}
  .dl-t{font-size:12px;font-weight:700;color:var(--muted);margin-bottom:7px}
  .dl button{font-family:inherit;font-weight:700;font-size:12.5px;cursor:pointer;border:1.5px solid var(--line);
    background:#fff;color:var(--tinta);border-radius:99px;padding:7px 12px;margin:3px}
  .dl button:hover{border-color:var(--terracota);color:var(--terracota-700)}
  @media(max-width:760px){.mk{grid-template-columns:1fr}}
</style>
<?php if ($marca['logo_path']): ?>
<script>
  function dlLogo(fmt, size){
    var src = document.getElementById('logoimg').src;
    var img = new Image();
    img.onload = function(){
      var s = size || img.naturalWidth || 1024;
      var c = document.createElement('canvas'); c.width = s; c.height = s;
      var ctx = c.getContext('2d');
      if (fmt === 'jpeg'){ ctx.fillStyle = '#ffffff'; ctx.fillRect(0,0,s,s); }
      ctx.drawImage(img, 0, 0, s, s);
      var mime = fmt==='jpeg' ? 'image/jpeg' : (fmt==='webp' ? 'image/webp' : 'image/png');
      c.toBlob(function(b){
        var a = document.createElement('a');
        a.href = URL.createObjectURL(b);
        a.download = 'logo-<?= $h($marca['slug']) ?>' + (size?('-'+size):'') + '.' + (fmt==='jpeg'?'jpg':fmt);
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(function(){ URL.revokeObjectURL(a.href); }, 2000);
      }, mime, 0.95);
    };
    img.src = src;
  }
</script>
<?php endif; ?>

<h1 class="page-h">Mi Marca 🎨</h1>
<p class="page-sub">Tu identidad visual, hecha por IA. Tu logo aparece en tu página y tus posts.</p>
<?php if (!empty($_GET['ok'])): ?><div class="ok-banner">✓ ¡Tu logo está listo!</div><?php endif; ?>
<?php if ($err): ?><div class="err-banner">⚠️ <?= $h($err) ?></div><?php endif; ?>

<div class="mk">
  <div class="logobox">
    <?php if ($marca['logo_path']): ?>
      <img id="logoimg" src="<?= $h($marca['logo_path']) ?>?v=<?= time() ?>" alt="Logo de <?= $h($marca['nombre_negocio']) ?>">
      <div class="dl">
        <div class="dl-t">⬇ Descargar:</div>
        <button type="button" onclick="dlLogo('png')">PNG</button>
        <button type="button" onclick="dlLogo('jpeg')">JPG</button>
        <button type="button" onclick="dlLogo('webp')">WebP</button>
        <button type="button" onclick="dlLogo('png',400)">Perfil 400px</button>
      </div>
    <?php else: ?>
      <div class="ph">🎨</div>
    <?php endif; ?>
    <?php if ($restantes > 0): ?>
      <form method="post" onsubmit="this.querySelector('button').textContent='✨ Creando… (~15s)';this.querySelector('button').disabled=true;">
        <input type="hidden" name="accion" value="logo">
        <textarea name="instrucciones" rows="2" placeholder="Dile a la IA cómo lo quieres (opcional): &quot;ponle un coquí&quot;, &quot;más moderno&quot;, &quot;colores azules&quot;…"
          style="width:100%;font-family:inherit;font-size:14px;border:1.5px solid var(--line);border-radius:12px;padding:10px 12px;margin-bottom:10px;resize:vertical"></textarea>
        <button class="genbtn <?= $marca['logo_path']?'alt':'' ?>" type="submit"><?= $marca['logo_path'] ? '↻ Generar otra versión' : '✨ Generar mi logo con IA' ?></button>
      </form>
      <div class="hint">Escríbele lo que quieras y la IA lo ajusta. Te quedan <b style="color:var(--terracota)"><?= $restantes ?> de <?= $LIMITE_LOGO ?></b> pruebas.</div>
    <?php else: ?>
      <div class="hint" style="background:var(--amber-bg);color:var(--amber-ink);border-radius:12px;padding:12px;font-weight:600">
        Usaste tus <?= $LIMITE_LOGO ?> pruebas de logo 🎨<br>
        ¿Necesitas más? <a href="/crecer/crecer.php" style="color:var(--terracota);font-weight:800">Sube de plan</a> o trae tu propia llave de IA.
      </div>
    <?php endif; ?>
  </div>

  <div class="info">
    <div class="pcard">
      <h3>De lo que la IA aprende</h3>
      <div class="kv"><b>Negocio:</b> <?= $h($marca['nombre_negocio']) ?></div>
      <div class="kv"><b>Descripción:</b> <?= $h($marca['descripcion'] ?: '—') ?></div>
      <div class="kv"><b>Voz:</b> <?= $h($marca['voz'] ?: '—') ?></div>
      <?php if ($productos): ?>
        <div class="kv"><b>Productos/servicios:</b></div>
        <div class="tags"><?php foreach ($productos as $p): $n = is_array($p)?($p['nombre']??''):$p; if($n): ?><span class="tag2"><?= $h($n) ?></span><?php endif; endforeach; ?></div>
      <?php endif; ?>
    </div>
    <div class="pcard">
      <h3>¿Algo cambió?</h3>
      <div class="kv" style="color:var(--muted)">El logo se genera de esta info. Si actualizas tu descripción o productos, genera una versión nueva y la IA la adapta.</div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/_shell_foot.php'; ?>
