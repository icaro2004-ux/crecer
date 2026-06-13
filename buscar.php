<?php
// ============================================================
//  ENCUÉNTRALO — Directorio público (buscar negocios)
//  buscar.php
// ============================================================
require __DIR__ . '/includes/db.php';

$negocios = $pdo->query(
    "SELECT m.id, m.nombre_negocio, m.slug, m.descripcion, m.whatsapp, mu.nombre AS municipio
       FROM crecer_marca m
       LEFT JOIN municipios mu ON mu.id = m.municipio_id
      WHERE m.estado IN ('activo','intake')
      ORDER BY m.created_at DESC"
)->fetchAll();
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Directorio · Encuéntralo</title>
<link rel="icon" type="image/svg+xml" href="/crecer/assets/brand/encuentralo-pin.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="/crecer/assets/encuentralo-ui.css" rel="stylesheet">
<style>
  .nav{display:flex;align-items:center;gap:10px;padding:18px 24px;max-width:1040px;margin:0 auto}
  .nav .mark{height:30px}
  .nav .bn{font-family:var(--font-display);font-weight:800;font-size:19px;letter-spacing:-.03em;text-transform:lowercase}
  .nav .back{margin-left:auto;font-weight:700;font-size:14px;color:var(--muted);text-decoration:none}
  .wrap{max-width:1040px;margin:0 auto;padding:14px 24px 60px}
  .head h1{font-family:var(--font-display);font-weight:800;font-size:clamp(28px,5vw,42px);letter-spacing:-.03em}
  .head p{color:var(--muted);font-size:16px;margin-top:6px}
  .search{margin:20px 0 8px;width:100%;font-family:inherit;font-size:16px;border:1.5px solid var(--line);border-radius:14px;padding:14px 16px;background:#fff}
  .search:focus{outline:none;border-color:var(--terracota);box-shadow:0 0 0 4px rgba(239,67,117,.12)}
  .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;margin-top:14px}
  .biz{background:var(--card);border:1px solid var(--line);border-radius:var(--r-lg);padding:20px;box-shadow:var(--shadow-sm);display:flex;flex-direction:column}
  .biz .mun{font-size:12px;font-weight:700;color:var(--teal);text-transform:uppercase;letter-spacing:.04em}
  .biz h3{font-family:var(--font-display);font-weight:800;font-size:20px;letter-spacing:-.02em;margin:4px 0 6px}
  .biz p{font-size:14px;color:var(--muted);flex:1}
  .biz a{margin-top:14px;text-align:center;background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff;font-weight:800;font-size:14px;padding:11px;border-radius:99px;text-decoration:none}
  .empty{text-align:center;color:var(--muted);padding:60px 20px}
  .foot{text-align:center;color:var(--muted);font-size:13px;padding:24px}
  .foot b{color:var(--terracota)}
</style>
</head>
<body>
  <nav class="nav">
    <img class="mark" src="/crecer/assets/brand/encuentralo-pin.svg" alt="">
    <span class="bn">encuéntralo</span>
    <a class="back" href="/crecer/index.php">← Inicio</a>
  </nav>

  <div class="wrap">
    <div class="head">
      <h1>Negocios boricuas 🇵🇷</h1>
      <p>Encuentra y ordena directo de negocios locales. <?= count($negocios) ?> y creciendo.</p>
    </div>
    <input class="search" id="q" placeholder="🔎 Busca por nombre o pueblo…" oninput="filtra()">

    <div class="grid" id="grid">
      <?php if (!$negocios): ?>
        <div class="empty">Todavía no hay negocios. ¡Sé el primero! <a href="/crecer/crecer.php">Crear mi negocio →</a></div>
      <?php endif; ?>
      <?php foreach ($negocios as $n): ?>
        <div class="biz" data-s="<?= $h(mb_strtolower($n['nombre_negocio'].' '.($n['municipio']??''))) ?>">
          <span class="mun">📍 <?= $h($n['municipio'] ?: 'Puerto Rico') ?></span>
          <h3><?= $h($n['nombre_negocio']) ?></h3>
          <p><?= $h($n['descripcion'] ?: 'Negocio boricua en Encuéntralo.') ?></p>
          <a href="/crecer/ordenar.php?n=<?= $h($n['slug']) ?>">Ver y ordenar →</a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <p class="foot">¿Tienes un negocio? <a href="/crecer/crecer.php" style="color:var(--terracota);font-weight:700;text-decoration:none">Crece con Encuéntralo →</a></p>

  <script>
    function filtra(){ var q=document.getElementById('q').value.toLowerCase();
      document.querySelectorAll('.biz').forEach(function(b){ b.style.display = b.dataset.s.includes(q)?'':'none'; }); }
  </script>
</body>
</html>
