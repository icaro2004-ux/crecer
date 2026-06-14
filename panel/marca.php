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

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'logo') {
    @set_time_limit(0);
    try { generar_logo($pdo, $marca_id); }
    catch (Throwable $e) { $err = 'No se pudo generar el logo: ' . substr($e->getMessage(), 0, 120); }
    if (!$err) { header('Location: /crecer/panel/marca.php?marca=' . $marca_id . '&ok=1'); exit; }
}

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
  @media(max-width:760px){.mk{grid-template-columns:1fr}}
</style>

<h1 class="page-h">Mi Marca 🎨</h1>
<p class="page-sub">Tu identidad visual, hecha por IA. Tu logo aparece en tu página y tus posts.</p>
<?php if (!empty($_GET['ok'])): ?><div class="ok-banner">✓ ¡Tu logo está listo!</div><?php endif; ?>
<?php if ($err): ?><div class="err-banner">⚠️ <?= $h($err) ?></div><?php endif; ?>

<div class="mk">
  <div class="logobox">
    <?php if ($marca['logo_path']): ?>
      <img src="<?= $h($marca['logo_path']) ?>?v=<?= time() ?>" alt="Logo de <?= $h($marca['nombre_negocio']) ?>">
      <form method="post" onsubmit="this.querySelector('button').textContent='✨ Creando…';this.querySelector('button').disabled=true;">
        <input type="hidden" name="accion" value="logo">
        <button class="genbtn alt" type="submit">↻ Generar otra versión</button>
      </form>
      <div class="hint">¿No te encanta? Genera otra (cada una es distinta).</div>
    <?php else: ?>
      <div class="ph">🎨</div>
      <form method="post" onsubmit="this.querySelector('button').textContent='✨ Creando tu logo…';this.querySelector('button').disabled=true;">
        <input type="hidden" name="accion" value="logo">
        <button class="genbtn" type="submit">✨ Generar mi logo con IA</button>
      </form>
      <div class="hint">La IA lo crea de tu descripción en ~10 segundos.</div>
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
