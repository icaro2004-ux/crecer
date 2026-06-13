<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Intake / "Crear mi negocio"
//  intake.php  ·  wizard que aprende del negocio → crecer_marca
//
//  MVP: usuario_id por defecto = 7 (auth real = TODO).
// ============================================================

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/agentes.php';

$USUARIO_ID = 7; // TODO: sesión real de Encuéntralo

// ── POST: guardar la marca ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo      = trim($_POST['tipo'] ?? '');         // segmento (de cero / ya tengo)
    $rubro     = trim($_POST['rubro'] ?? '');        // tipo de negocio
    $nombre    = trim($_POST['nombre_negocio'] ?? '');
    $desc      = trim($_POST['descripcion'] ?? '');
    $productos = array_values(array_filter(array_map('trim', explode("\n", $_POST['productos'] ?? ''))));
    $full_desc = trim(($rubro ? "$rubro. " : '') . $desc);

    if ($nombre === '') { $err = 'Ponle nombre a tu negocio.'; }
    else {
        $marca_id = crear_marca($pdo, [
            'usuario_id'       => $USUARIO_ID,
            'municipio_id'     => ($_POST['municipio_id'] ?? '') !== '' ? (int)$_POST['municipio_id'] : null,
            'nombre_negocio'   => $nombre,
            'descripcion'      => $full_desc,
            'voz'              => trim($_POST['voz'] ?? ''),
            'productos'        => array_map(fn($p) => ['nombre' => $p], $productos),
            'publico_objetivo' => trim($_POST['publico'] ?? ''),
            'ofertas'          => trim($_POST['ofertas'] ?? ''),
            'instagram'        => trim($_POST['instagram'] ?? ''),
            'whatsapp'         => trim($_POST['whatsapp'] ?? ''),
            'facebook'         => trim($_POST['facebook'] ?? ''),
        ]);
        header('Location: /crecer/intake.php?ok=' . $marca_id);
        exit;
    }
}

// ── Pantalla de éxito ────────────────────────────────────────
$ok_id = isset($_GET['ok']) ? (int)$_GET['ok'] : 0;
$ok_marca = null;
if ($ok_id) {
    $s = $pdo->prepare("SELECT nombre_negocio FROM crecer_marca WHERE id = ?");
    $s->execute([$ok_id]);
    $ok_marca = $s->fetch();
}

$municipios = $pdo->query("SELECT id, nombre FROM municipios ORDER BY nombre")->fetchAll();
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Crear mi negocio · Encuéntralo</title>
<link rel="icon" type="image/svg+xml" href="/crecer/assets/brand/encuentralo-pin.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="/crecer/assets/encuentralo-ui.css" rel="stylesheet">
<style>
  .nav{display:flex;align-items:center;gap:10px;padding:18px 24px;max-width:680px;margin:0 auto}
  .nav .mark{height:30px}
  .nav .bn{font-family:var(--font-display);font-weight:800;font-size:19px;letter-spacing:-.03em;text-transform:lowercase}
  .shell{max-width:680px;margin:0 auto;padding:8px 22px 60px}

  .prog{display:flex;gap:6px;margin:6px 0 22px}
  .prog i{height:6px;flex:1;background:var(--crema-2);border-radius:99px;transition:background .3s}
  .prog i.on{background:linear-gradient(90deg,var(--coral),var(--magenta))}

  .step{display:none;animation:fade .35s ease}
  .step.on{display:block}
  @keyframes fade{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}

  .kicker{font-weight:700;font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:var(--terracota)}
  .step h2{font-family:var(--font-display);font-weight:800;font-size:clamp(24px,4.6vw,32px);
    letter-spacing:-.025em;margin:6px 0 4px}
  .step .hint{color:var(--muted);font-size:15px;margin-bottom:20px}

  label.f{display:block;font-weight:700;font-size:14px;margin:16px 0 7px}
  .inp,.sel,.ta{width:100%;font-family:inherit;font-size:16px;color:var(--tinta);
    background:var(--card);border:1.5px solid var(--line);border-radius:14px;padding:13px 15px}
  .inp:focus,.sel:focus,.ta:focus{outline:none;border-color:var(--terracota);box-shadow:0 0 0 4px rgba(239,67,117,.12)}
  .ta{min-height:96px;resize:vertical}
  .sub{font-size:12.5px;color:var(--muted);margin-top:6px}

  .chips{display:flex;flex-wrap:wrap;gap:9px;margin-top:4px}
  .chip-opt{cursor:pointer}
  .chip-opt input{position:absolute;opacity:0;pointer-events:none}
  .chip-opt span{display:inline-block;padding:9px 15px;border-radius:99px;border:1.5px solid var(--line);
    background:var(--card);font-weight:700;font-size:14px;transition:all .15s}
  .chip-opt input:checked + span{border-color:transparent;color:#fff;
    background:linear-gradient(135deg,var(--coral),var(--magenta))}

  .cards2{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:6px}
  .seg{cursor:pointer}
  .seg input{position:absolute;opacity:0}
  .seg .box{border:1.5px solid var(--line);border-radius:18px;padding:20px 18px;background:var(--card);transition:all .15s;height:100%}
  .seg .box .e{font-size:28px}
  .seg .box b{display:block;font-family:var(--font-display);font-weight:800;font-size:18px;margin:8px 0 3px}
  .seg .box p{font-size:13.5px;color:var(--muted)}
  .seg input:checked + .box{border-color:var(--terracota);box-shadow:0 0 0 4px rgba(239,67,117,.12)}

  .row{display:flex;gap:14px;justify-content:space-between;margin-top:28px;align-items:center}
  .btn-n{border:0;font-family:inherit;font-weight:800;font-size:15px;cursor:pointer;border-radius:99px;padding:14px 26px}
  .btn-next{background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff;
    box-shadow:0 10px 24px rgba(255,43,133,.28);margin-left:auto}
  .btn-next:hover{filter:brightness(1.05)}
  .btn-back{background:none;color:var(--muted)}
  .err{background:var(--noo-bg);color:var(--noo-ink);font-weight:700;font-size:14px;padding:12px 15px;border-radius:12px;margin-bottom:14px}

  /* éxito */
  .done{text-align:center;max-width:540px;margin:40px auto;padding:0 24px}
  .done .pin{height:64px;margin-bottom:10px;filter:drop-shadow(0 6px 14px rgba(239,67,117,.3))}
  .done h1{font-family:var(--font-display);font-weight:800;font-size:clamp(28px,5vw,40px);letter-spacing:-.03em}
  .done p{color:var(--muted);font-size:16px;margin-top:12px}
  .done .cta{display:inline-block;margin-top:24px;background:linear-gradient(135deg,var(--coral),var(--magenta));
    color:#fff;font-weight:800;padding:15px 30px;border-radius:99px;text-decoration:none;box-shadow:0 12px 30px rgba(255,43,133,.3)}
</style>
</head>
<body>

<?php if ($ok_marca): ?>
  <!-- ÉXITO -->
  <div class="done">
    <img class="pin" src="/crecer/assets/brand/encuentralo-pin.svg" alt="">
    <h1>¡<?= $h($ok_marca['nombre_negocio']) ?> está en Encuéntralo! 🎉</h1>
    <p>La IA ya está aprendiendo de tu negocio. En un ratito te prepara tu primer
       mes de contenido — y tú solo lo apruebas desde el celular.</p>
    <a class="cta" href="/crecer/panel/aprobar2.php?marca=<?= $ok_id ?>">Ir a mi panel →</a>
  </div>

<?php else: ?>

  <nav class="nav">
    <img class="mark" src="/crecer/assets/brand/encuentralo-pin.svg" alt="">
    <span class="bn">encuéntralo</span>
  </nav>

  <div class="shell">
    <div class="prog"><i class="on"></i><i></i><i></i><i></i></div>
    <?php if (!empty($err)): ?><div class="err"><?= $h($err) ?></div><?php endif; ?>

    <form method="post" id="wiz">
      <!-- PASO 1 -->
      <section class="step on" data-step="1">
        <div class="kicker">Paso 1 de 4</div>
        <h2>Cuéntame de tu negocio</h2>
        <p class="hint">Lo básico para arrancar. Tranqui, esto toma 2 minutos.</p>

        <label class="f">¿Cuál es tu caso?</label>
        <div class="cards2">
          <label class="seg"><input type="radio" name="tipo" value="de cero" checked>
            <div class="box"><div class="e">🌱</div><b>Tengo una idea</b><p>Quiero montar mi negocio de cero.</p></div></label>
          <label class="seg"><input type="radio" name="tipo" value="ya tengo">
            <div class="box"><div class="e">🚀</div><b>Ya tengo negocio</b><p>Ya opero y quiero crecer.</p></div></label>
        </div>

        <label class="f">¿Cómo se llama? <span style="color:var(--terracota)">*</span></label>
        <input class="inp" name="nombre_negocio" placeholder="Ej. Dulce Coquí" required>
        <p class="sub">¿Aún no tienes nombre? Ponle uno de trabajo, la IA te ayuda después.</p>

        <label class="f">¿De qué pueblo eres?</label>
        <select class="sel" name="municipio_id">
          <option value="">Escoge tu municipio…</option>
          <?php foreach ($municipios as $m): ?>
            <option value="<?= $m['id'] ?>"><?= $h($m['nombre']) ?></option>
          <?php endforeach; ?>
        </select>

        <div class="row"><button type="button" class="btn-n btn-next" data-go="2">Siguiente →</button></div>
      </section>

      <!-- PASO 2 -->
      <section class="step" data-step="2">
        <div class="kicker">Paso 2 de 4</div>
        <h2>¿Qué ofreces?</h2>
        <p class="hint">Mientras más me cuentes, mejor te queda el contenido.</p>

        <label class="f">¿Qué tipo de negocio es?</label>
        <input class="inp" name="rubro" placeholder="Ej. Repostería casera, barbería, comida criolla…">

        <label class="f">Descríbelo en una o dos líneas</label>
        <textarea class="ta" name="descripcion" placeholder="Ej. Bizcochos y postres boricuas por encargo, hechos en casa con amor."></textarea>

        <label class="f">Tus productos o servicios</label>
        <textarea class="ta" name="productos" placeholder="Uno por línea:&#10;Bizcocho de guayaba&#10;Quesitos&#10;Tembleque"></textarea>
        <p class="sub">Uno por línea. Los que se te ocurran ahora; se editan luego.</p>

        <div class="row">
          <button type="button" class="btn-n btn-back" data-go="1">← Atrás</button>
          <button type="button" class="btn-n btn-next" data-go="3">Siguiente →</button>
        </div>
      </section>

      <!-- PASO 3 -->
      <section class="step" data-step="3">
        <div class="kicker">Paso 3 de 4</div>
        <h2>Tu estilo y tu gente</h2>
        <p class="hint">Así la IA habla como tú, no como un robot.</p>

        <label class="f">¿Cómo le hablas a tus clientes?</label>
        <div class="chips">
          <?php foreach (['Cálido y cariñoso','De barrio, relajao','Divertido','Profesional','Elegante'] as $i => $v): ?>
            <label class="chip-opt"><input type="radio" name="voz" value="<?= $h($v) ?>" <?= $i===0?'checked':'' ?>><span><?= $h($v) ?></span></label>
          <?php endforeach; ?>
        </div>

        <label class="f">¿Quiénes son tus clientes?</label>
        <input class="inp" name="publico" placeholder="Ej. Familias de Caguas que celebran cumpleaños y fiestas.">

        <label class="f">¿Tienes alguna oferta o promoción?</label>
        <input class="inp" name="ofertas" placeholder="Ej. Descuento por orden anticipada de 3 días. (Opcional)">

        <div class="row">
          <button type="button" class="btn-n btn-back" data-go="2">← Atrás</button>
          <button type="button" class="btn-n btn-next" data-go="4">Siguiente →</button>
        </div>
      </section>

      <!-- PASO 4 -->
      <section class="step" data-step="4">
        <div class="kicker">Paso 4 de 4</div>
        <h2>¿Cómo te contactan?</h2>
        <p class="hint">Por aquí te llegan las órdenes y los mensajes.</p>

        <label class="f">WhatsApp</label>
        <input class="inp" name="whatsapp" placeholder="787-555-0000">

        <label class="f">Instagram</label>
        <input class="inp" name="instagram" placeholder="@tunegocio">

        <label class="f">Facebook</label>
        <input class="inp" name="facebook" placeholder="facebook.com/tunegocio (opcional)">

        <p class="sub" style="margin-top:18px">📸 Las fotos de tu negocio las subimos en el próximo paso, ya dentro de tu panel.</p>

        <div class="row">
          <button type="button" class="btn-n btn-back" data-go="3">← Atrás</button>
          <button type="submit" class="btn-n btn-next">Crear mi negocio 🌱</button>
        </div>
      </section>
    </form>
  </div>

  <script>
    const steps = [...document.querySelectorAll('.step')];
    const bars  = [...document.querySelectorAll('.prog i')];
    function go(n){
      const cur = steps.find(s => s.classList.contains('on'));
      // validación mínima al avanzar
      if (cur && +cur.dataset.step < n){
        const req = cur.querySelector('[required]');
        if (req && !req.value.trim()){ req.focus(); req.style.borderColor = 'var(--noo-ink)'; return; }
      }
      steps.forEach(s => s.classList.toggle('on', +s.dataset.step === n));
      bars.forEach((b,i) => b.classList.toggle('on', i < n));
      window.scrollTo({top:0, behavior:'smooth'});
    }
    document.querySelectorAll('[data-go]').forEach(b =>
      b.addEventListener('click', () => go(+b.dataset.go)));
  </script>

<?php endif; ?>
</body>
</html>
