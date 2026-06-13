<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Página PÚBLICA de órdenes
//  ordenar.php?n=<slug>  ·  el cliente ordena, sin cuenta
// ============================================================
require __DIR__ . '/includes/db.php';

// Buscar negocio por slug (?n=) o id (?m=)
$marca = null;
if (!empty($_GET['n'])) {
    $st = $pdo->prepare("SELECT * FROM crecer_marca WHERE slug = ?");
    $st->execute([$_GET['n']]);
    $marca = $st->fetch();
} elseif (!empty($_GET['m'])) {
    $st = $pdo->prepare("SELECT * FROM crecer_marca WHERE id = ?");
    $st->execute([(int)$_GET['m']]);
    $marca = $st->fetch();
}
if (!$marca) { http_response_code(404); exit('Negocio no encontrado.'); }
$marca_id = (int)$marca['id'];

// POST: crear la orden
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['cliente_nombre'] ?? '');
    $cont   = trim($_POST['cliente_contacto'] ?? '');
    $desc   = trim($_POST['descripcion'] ?? '');
    if ($nombre !== '' && $desc !== '') {
        $ins = $pdo->prepare("INSERT INTO crecer_ordenes
            (marca_id, cliente_nombre, cliente_contacto, descripcion, fecha_entrega, estado)
            VALUES (?,?,?,?,?, 'recibida')");
        $ins->execute([
            $marca_id, $nombre, $cont ?: null, $desc,
            ($_POST['fecha_entrega'] ?? '') !== '' ? str_replace('T',' ',$_POST['fecha_entrega']).':00' : null,
        ]);
        header('Location: /crecer/ordenar.php?n=' . urlencode($marca['slug']) . '&ok=1');
        exit;
    }
    $err = 'Pon tu nombre y qué quieres ordenar.';
}

$productos = $marca['productos'] ? json_decode($marca['productos'], true) : [];
$ok = !empty($_GET['ok']);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Ordena en <?= $h($marca['nombre_negocio']) ?></title>
<link rel="icon" type="image/svg+xml" href="/crecer/assets/brand/encuentralo-pin.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="/crecer/assets/encuentralo-ui.css" rel="stylesheet">
<style>
  .wrap{max-width:480px;margin:0 auto;padding:0 20px 60px}
  .hero{text-align:center;padding:34px 0 8px}
  .hero .pin{height:54px;filter:drop-shadow(0 6px 14px rgba(239,67,117,.3))}
  .hero h1{font-family:var(--font-display);font-weight:800;font-size:30px;letter-spacing:-.025em;margin-top:12px}
  .hero p{color:var(--muted);font-size:15px;margin-top:6px}
  .hero .from{font-size:12px;color:var(--muted);margin-top:14px}
  .hero .from b{color:var(--terracota)}
  .card{background:var(--card);border:1px solid var(--line);border-radius:var(--r-xl);padding:24px;box-shadow:var(--shadow);margin-top:18px}
  label{display:block;font-weight:700;font-size:14px;margin:15px 0 7px}
  input,textarea{width:100%;font-family:inherit;font-size:16px;color:var(--tinta);background:#fff;
    border:1.5px solid var(--line);border-radius:14px;padding:13px 15px}
  input:focus,textarea:focus{outline:none;border-color:var(--terracota);box-shadow:0 0 0 4px rgba(239,67,117,.12)}
  .chips{display:flex;flex-wrap:wrap;gap:8px;margin-top:4px}
  .chip{cursor:pointer;font-size:13px;font-weight:600;padding:8px 13px;border-radius:99px;border:1.5px solid var(--line);background:#fff}
  .chip:hover{border-color:var(--terracota);color:var(--terracota-700)}
  .send{margin-top:20px;width:100%;background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff;
    border:0;cursor:pointer;font-weight:800;font-size:16px;padding:15px;border-radius:99px;box-shadow:0 12px 28px rgba(255,43,133,.3)}
  .err{background:var(--noo-bg);color:var(--noo-ink);font-weight:700;font-size:14px;padding:11px 14px;border-radius:12px;margin-top:8px}
  .foot{text-align:center;color:var(--muted);font-size:12px;margin-top:22px}
  .foot b{color:var(--terracota)}
  /* éxito */
  .done{text-align:center;padding:50px 10px}
  .done .big{font-size:54px}
  .done h1{font-family:var(--font-display);font-weight:800;font-size:28px;letter-spacing:-.02em;margin-top:10px}
  .done p{color:var(--muted);font-size:16px;margin-top:10px}
  .done-cta{display:inline-block;margin-top:24px;background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff;font-weight:800;padding:14px 26px;border-radius:99px;text-decoration:none;box-shadow:0 12px 28px rgba(255,43,133,.3)}
  .done-links{margin-top:16px;font-size:14px}
  .done-links a{color:var(--muted);font-weight:700;text-decoration:none}
  .done-links a:hover{color:var(--terracota)}
</style>
</head>
<body>
<div class="wrap">

<?php if ($ok):
  $wn = preg_replace('/\D/','',(string)$marca['whatsapp']);
  if (strlen($wn)===10) $wn='1'.$wn;
  $wa = strlen($wn)>=11 ? "https://wa.me/$wn" : null;
?>
  <div class="done">
    <div class="big">🎉</div>
    <h1>¡Tu orden llegó!</h1>
    <p><b><?= $h($marca['nombre_negocio']) ?></b> ya la recibió y te va a contactar pronto<?= $marca['whatsapp']?' por WhatsApp':'' ?>. ¡Gracias! 🇵🇷</p>
    <a class="done-cta" href="/crecer/buscar.php">Explorar más negocios boricuas →</a>
    <div class="done-links">
      <?php if ($wa): ?><a href="<?= $h($wa) ?>" target="_blank">💬 Escríbele por WhatsApp</a> · <?php endif; ?>
      <a href="/crecer/ordenar.php?n=<?= $h($marca['slug']) ?>">Hacer otra orden</a>
    </div>
  </div>
<?php else: ?>

  <div class="hero">
    <a href="/crecer/buscar.php" title="Ver más negocios"><img class="pin" src="/crecer/assets/brand/encuentralo-pin.svg" alt="Encuéntralo"></a>
    <h1><?= $h($marca['nombre_negocio']) ?></h1>
    <?php if ($marca['descripcion']): ?><p><?= $h($marca['descripcion']) ?></p><?php endif; ?>
    <div class="from">Ordena directo · powered by <b>Encuéntralo</b></div>
  </div>

  <form method="post" class="card">
    <?php if (!empty($err)): ?><div class="err"><?= $h($err) ?></div><?php endif; ?>

    <label>Tu nombre *</label>
    <input name="cliente_nombre" required placeholder="¿Cómo te llamas?">

    <label>Tu WhatsApp / teléfono</label>
    <input name="cliente_contacto" placeholder="787-555-0000" inputmode="tel">

    <label>¿Qué quieres ordenar? *</label>
    <textarea name="descripcion" id="desc" rows="3" required placeholder="Escribe tu pedido…"></textarea>
    <?php if ($productos): ?>
      <div class="chips">
        <?php foreach (array_slice($productos,0,8) as $p): $nm = is_array($p)?($p['nombre']??''):$p; if(!$nm) continue; ?>
          <span class="chip" onclick="addProd(<?= $h(json_encode($nm)) ?>)">+ <?= $h($nm) ?></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <label>¿Para cuándo? (opcional)</label>
    <input name="fecha_entrega" type="datetime-local">

    <button class="send" type="submit">Enviar mi orden 🌱</button>
  </form>

  <p class="foot">Tu orden va directo a <b><?= $h($marca['nombre_negocio']) ?></b>. Sin apps, sin cuentas.</p>

  <script>
    function addProd(n){ var t=document.getElementById('desc'); t.value=(t.value?t.value+'\n':'')+n; t.focus(); }
  </script>
<?php endif; ?>
</div>
</body>
</html>
