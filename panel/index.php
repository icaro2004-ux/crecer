<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Dashboard (home del panel)
//  panel/index.php  ·  shell responsive (sidebar PC / drawer móvil)
//  MVP: marca por ?marca= (auth real = TODO)
// ============================================================

require __DIR__ . '/../includes/db.php';

$marca_id = (int)($_GET['marca'] ?? 1);
$m = $pdo->prepare("SELECT * FROM crecer_marca WHERE id = ?");
$m->execute([$marca_id]);
$marca = $m->fetch();
if (!$marca) { http_response_code(404); exit('Negocio no encontrado.'); }

// Calendario más reciente + métricas
$cal = $pdo->prepare("SELECT id FROM crecer_calendario WHERE marca_id=? ORDER BY anio DESC, mes DESC LIMIT 1");
$cal->execute([$marca_id]);
$cal_id = (int)$cal->fetchColumn();

$pend = 0; $aprob = 0; $proximas = [];
if ($cal_id) {
    $c = $pdo->prepare("SELECT estado, COUNT(*) n FROM crecer_contenido WHERE calendario_id=? GROUP BY estado");
    $c->execute([$cal_id]);
    foreach ($c->fetchAll() as $r) { if ($r['estado']==='borrador') $pend=(int)$r['n']; if ($r['estado']==='aprobado') $aprob=(int)$r['n']; }
    $p = $pdo->prepare("SELECT plataforma, tipo, fecha_programada, caption FROM crecer_contenido
        WHERE calendario_id=? AND estado IN ('aprobado','publicado') ORDER BY fecha_programada LIMIT 3");
    $p->execute([$cal_id]);
    $proximas = $p->fetchAll();
}
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$emoji_plat = ['instagram'=>'📸','facebook'=>'👍','whatsapp'=>'💬'];

// Nav: [icono, label, href, estado]  estado: ''|'pronto'|'despegar'
$BASE = '/crecer/panel';
$nav = [
  ['🏠','Inicio',           "$BASE/index.php?marca=$marca_id",   'activo'],
  ['📅','Contenido',        "$BASE/aprobar2.php?marca=$marca_id",''],
  ['🎨','Marca',            '#',                                  'pronto'],
  ['📦','Órdenes & Agenda', '#',                                  'pronto'],
  ['👥','Clientela',        '#',                                  'pronto'],
  ['💵','Cuentas',          '#',                                  'despegar'],
  ['📊','Analítica',        '#',                                  'despegar'],
  ['⚙️','Configuración',     '#',                                  'pronto'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= $h($marca['nombre_negocio']) ?> · Panel — Encuéntralo</title>
<link rel="icon" type="image/svg+xml" href="/crecer/assets/brand/encuentralo-pin.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="/crecer/assets/encuentralo-ui.css" rel="stylesheet">
<style>
  body{background:var(--crema)}
  .layout{display:flex;min-height:100vh}

  /* ── Sidebar ── */
  .side{width:248px;flex:none;background:var(--card);border-right:1px solid var(--line);
    display:flex;flex-direction:column;padding:20px 16px;position:sticky;top:0;height:100vh}
  .side .brand{display:flex;align-items:center;gap:9px;padding:4px 8px 18px}
  .side .brand img{height:30px}
  .side .brand b{font-family:var(--font-display);font-weight:800;font-size:19px;letter-spacing:-.03em;text-transform:lowercase}
  .side nav{display:flex;flex-direction:column;gap:3px}
  .side a{display:flex;align-items:center;gap:12px;padding:11px 12px;border-radius:12px;
    text-decoration:none;color:var(--tinta);font-weight:600;font-size:14.5px;transition:background .15s}
  .side a .ic{font-size:17px;width:22px;text-align:center}
  .side a:hover{background:var(--crema)}
  .side a.on{background:linear-gradient(135deg,rgba(255,107,61,.14),rgba(255,43,133,.14));color:var(--terracota-700)}
  .side a .badge{margin-left:auto;font-size:10px;font-weight:800;text-transform:uppercase;
    letter-spacing:.03em;color:var(--muted);background:var(--crema-2);padding:3px 7px;border-radius:99px}
  .side a.locked{color:var(--muted)}
  .side .who{margin-top:auto;border-top:1px solid var(--line);padding-top:14px;display:flex;align-items:center;gap:10px}
  .side .who .av{width:38px;height:38px;border-radius:11px;background:linear-gradient(135deg,var(--coral),var(--magenta));
    color:#fff;display:grid;place-items:center;font-family:var(--font-display);font-weight:800}
  .side .who .nm{font-weight:700;font-size:13.5px;line-height:1.2}
  .side .who .tag{font-size:11px;color:var(--palma);font-weight:700}

  /* ── Main ── */
  .main{flex:1;min-width:0}
  .topbar{display:none;align-items:center;gap:12px;padding:14px 18px;background:var(--card);border-bottom:1px solid var(--line);position:sticky;top:0;z-index:30}
  .topbar img{height:28px}
  .topbar b{font-family:var(--font-display);font-weight:800;font-size:17px;text-transform:lowercase;letter-spacing:-.03em}
  .burger{margin-left:auto;border:0;background:none;font-size:22px;cursor:pointer;color:var(--tinta)}
  .content{padding:30px 32px 60px;max-width:980px}

  .hello{font-family:var(--font-display);font-weight:800;font-size:clamp(26px,4vw,36px);letter-spacing:-.025em}
  .hello .wave{margin-left:4px}
  .subhi{color:var(--muted);font-size:15px;margin-top:4px}
  .lvltag{display:inline-block;margin-top:12px;font-size:12.5px;font-weight:700;color:var(--palma);background:var(--okk-bg);padding:6px 13px;border-radius:99px}

  .grid{display:grid;grid-template-columns:repeat(2,1fr);gap:18px;margin-top:26px}
  .card{background:var(--card);border:1px solid var(--line);border-radius:var(--r-lg);padding:22px;box-shadow:var(--shadow-sm)}
  .card.feature{grid-column:span 2;background:linear-gradient(135deg,rgba(255,107,61,.07),rgba(255,43,133,.07))}
  .card .ct{font-size:13px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
  .card .big{font-family:var(--font-display);font-weight:800;font-size:42px;line-height:1;margin:8px 0 2px;letter-spacing:-.03em}
  .card .big.pink{color:var(--terracota)}
  .card .lk{display:inline-block;margin-top:14px;font-weight:800;font-size:14px;color:var(--terracota);text-decoration:none}
  .card .soon{font-size:13px;color:var(--muted);margin-top:6px}
  .card .cta{display:inline-block;margin-top:14px;background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff;
    font-weight:800;font-size:14px;padding:11px 20px;border-radius:99px;text-decoration:none}

  .prox{display:flex;flex-direction:column;gap:10px;margin-top:14px}
  .prox .it{display:flex;gap:10px;align-items:center;font-size:14px;padding:10px 12px;background:var(--crema);border-radius:12px}
  .prox .it .d{font-weight:800;color:var(--terracota);font-size:13px;flex:none}
  .prox .it .tx{color:var(--tinta);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

  .backdrop{display:none;position:fixed;inset:0;background:rgba(20,12,8,.4);z-index:40}

  @media (max-width:860px){
    .side{position:fixed;left:0;top:0;z-index:50;transform:translateX(-100%);transition:transform .25s ease;box-shadow:0 0 40px rgba(0,0,0,.2)}
    .side.open{transform:none}
    .topbar{display:flex}
    .content{padding:20px 18px 80px}
    .grid{grid-template-columns:1fr}
    .card.feature{grid-column:span 1}
    .backdrop.show{display:block}
  }
</style>
</head>
<body>
<div class="layout">

  <aside class="side" id="side">
    <div class="brand">
      <img src="/crecer/assets/brand/encuentralo-pin.svg" alt=""><b>encuéntralo</b>
    </div>
    <nav>
      <?php foreach ($nav as [$ic,$lb,$hr,$st]): ?>
        <a href="<?= $hr ?>" class="<?= $st==='activo'?'on':'' ?> <?= in_array($st,['pronto','despegar'])?'locked':'' ?>">
          <span class="ic"><?= $ic ?></span><?= $lb ?>
          <?php if ($st==='pronto'): ?><span class="badge">pronto</span>
          <?php elseif ($st==='despegar'): ?><span class="badge">despegar</span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="who">
      <div class="av"><?= $h(mb_strtoupper(mb_substr($marca['nombre_negocio'],0,1))) ?></div>
      <div><div class="nm"><?= $h($marca['nombre_negocio']) ?></div><div class="tag">🌿 Crecer · Intermedio</div></div>
    </div>
  </aside>
  <div class="backdrop" id="bd"></div>

  <div class="main">
    <div class="topbar">
      <img src="/crecer/assets/brand/encuentralo-pin.svg" alt=""><b>encuéntralo</b>
      <button class="burger" id="burger" aria-label="Menú">☰</button>
    </div>

    <div class="content">
      <h1 class="hello">¡Hola, <?= $h($marca['nombre_negocio']) ?>!<span class="wave">👋</span></h1>
      <p class="subhi">Este es tu centro de mando. Aquí la IA trabaja y tú decides.</p>
      <div class="lvltag">🌿 Plan Crecer · Intermedio</div>

      <div class="grid">
        <!-- Por aprobar -->
        <div class="card">
          <div class="ct">Por aprobar</div>
          <div class="big pink"><?= $pend ?></div>
          <div class="ct" style="text-transform:none;color:var(--muted);font-weight:600">pieza<?= $pend==1?'':'s' ?> esperando tu OK</div>
          <?php if ($pend > 0): ?>
            <a class="lk" href="<?= $BASE ?>/aprobar2.php?marca=<?= $marca_id ?>">Revisar ahora →</a>
          <?php elseif (!$cal_id): ?>
            <div style="margin-top:12px"><a class="cta" href="<?= $BASE ?>/aprobar2.php?marca=<?= $marca_id ?>">✨ Generar mi primer mes</a></div>
          <?php else: ?>
            <div class="soon">✅ Todo al día</div>
          <?php endif; ?>
        </div>

        <!-- Listas para publicar -->
        <div class="card">
          <div class="ct">Listas para publicar</div>
          <div class="big"><?= $aprob ?></div>
          <div class="ct" style="text-transform:none;color:var(--muted);font-weight:600">aprobadas este mes</div>
          <?php if ($proximas): ?>
            <div class="prox">
              <?php foreach ($proximas as $px): ?>
                <div class="it"><span class="d"><?= date('d/m', strtotime($px['fecha_programada'])) ?></span>
                  <span><?= $emoji_plat[$px['plataforma']] ?? '' ?></span>
                  <span class="tx"><?= $h(mb_substr($px['caption'],0,40)) ?>…</span></div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="soon">Aprueba contenido y aquí verás tu calendario.</div>
          <?php endif; ?>
        </div>

        <!-- Órdenes (pronto) -->
        <div class="card">
          <div class="ct">Órdenes abiertas</div>
          <div class="big" style="color:var(--muted)">—</div>
          <div class="soon">📦 Próximamente: recibe y maneja tus órdenes aquí.</div>
        </div>

        <!-- Ingresos (despegar) -->
        <div class="card">
          <div class="ct">Ingresos del mes</div>
          <div class="big" style="color:var(--muted)">—</div>
          <div class="soon">💵 Con el plan <b>Despegar</b>: ingresos, gastos y ganancia.</div>
        </div>

        <!-- Feature banner -->
        <div class="card feature">
          <div class="ct">🎨 Próximo wow</div>
          <div style="font-family:var(--font-display);font-weight:800;font-size:21px;margin:6px 0 4px;letter-spacing:-.02em">Gráficas con tus fotos</div>
          <div class="soon">La IA va a convertir las fotos de tu negocio en posts profesionales. Sube tus fotos para activarlo.</div>
          <a class="cta" href="#">Subir mis fotos (pronto)</a>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  const side=document.getElementById('side'), bd=document.getElementById('bd'), bg=document.getElementById('burger');
  function open(o){ side.classList.toggle('open',o); bd.classList.toggle('show',o); }
  bg.addEventListener('click',()=>open(true));
  bd.addEventListener('click',()=>open(false));
</script>
</body>
</html>
