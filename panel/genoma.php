<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — EL GENOMA DEL NEGOCIO
//  panel/genoma.php
//
//  El cerebro ÚNICO de cada negocio, hecho visible. Aquí el dueño
//  VE lo que el corillo sabe de él: su voz, su radiografía, el
//  vocabulario aprendido de SUS correcciones, y las lecciones
//  medidas de sus resultados. Nada inventado: cada sección se
//  alimenta de datos reales, y si algo no existe todavía, se dice.
//
//  (Para el jurado/narrativa técnica: el Business Genome. Para el
//  cliente: "el Genoma de tu negocio" — nació en la entrevista y
//  no para de crecer.)
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
requiere_login();
require_once __DIR__ . '/../includes/panel_guard.php';
requiere_suscripcion($pdo, isset($_GET['marca']) ? (int)$_GET['marca'] : null);
$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }
$marca_id = (int)$marca['id'];
require_once __DIR__ . '/../includes/memoria.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// ── Los datos del cerebro (todo real, todo defensivo) ──
$radio = json_decode((string)($marca['radiografia_json'] ?? ''), true);
$radio = is_array($radio) ? array_filter(array_map('trim', array_map('strval', $radio))) : [];

$glosario = array_values(array_filter(array_map('trim',
    preg_split('/\r\n|\r|\n|;|·/', (string)($marca['glosario'] ?? '')) ?: [])));

$memorias = [];
try { $memorias = array_slice(memoria_listar($pdo, $marca_id), 0, 10); } catch (Throwable $e) {}

// Aportes al conocimiento (misma cuenta que la evidencia).
$gen = ['intake' => 0, 'aprendiz' => 0, 'genoma' => 0];
try {
    $gq = $pdo->prepare("SELECT agente, COUNT(*) n FROM crecer_ia_log
                         WHERE marca_id=? AND estado='ok' AND agente IN ('intake','aprendiz','genoma') GROUP BY agente");
    $gq->execute([$marca_id]);
    foreach ($gq as $r) $gen[$r['agente']] = (int)$r['n'];
} catch (Throwable $e) {}
$mem_act = 0; $mem_opt = 0;
try { $s = $pdo->prepare("SELECT COUNT(*) FROM crecer_memoria WHERE marca_id=? AND estado='activa'"); $s->execute([$marca_id]); $mem_act = (int)$s->fetchColumn(); } catch (Throwable $e) {}
try { $s = $pdo->prepare("SELECT COUNT(*) FROM crecer_memoria WHERE marca_id=? AND estado='activa' AND fuente='optimizador'"); $s->execute([$marca_id]); $mem_opt = (int)$s->fetchColumn(); } catch (Throwable $e) {}
$aportes = array_sum($gen) + $mem_act + count($glosario);

$nacio = !empty($marca['created_at']) ? date('d/m/Y', strtotime($marca['created_at'])) : null;
$tonos = [
    'Boricua'  => (int)($marca['tono_boricua'] ?? 0),
    'Formal'   => (int)($marca['tono_formal'] ?? 0),
    'Vendedor' => (int)($marca['tono_venta'] ?? 0),
    'Ingenio'  => (int)($marca['tono_ingenio'] ?? 0),
];
$hay_tonos = array_sum($tonos) > 0;

$CAPS = [
    'identidad'         => ['home',     'Identidad',                'qué ES este negocio'],
    'reglas_voz'        => ['pen',      'Reglas de voz',            'cómo habla — las sigue el escritor'],
    'reglas_imagen'     => ['image',    'Reglas de imagen',         'qué se muestra y qué jamás — las sigue el diseñador'],
    'reglas_estrategia' => ['compass',  'Reglas de estrategia',     'a quién y cómo se le vende — las sigue la estratega'],
    'personalidad'      => ['sparkles', 'Personalidad',             'hasta dónde ser atrevido sin salirse del negocio'],
];

$active = 'genoma';
$page_title = 'El Genoma';
require __DIR__ . '/_shell.php';
?>
<style>
  .gn{max-width:840px}
  .gn-hero{position:relative;border-radius:20px;padding:26px 24px;background:linear-gradient(135deg,color-mix(in srgb,var(--teal) 10%,#fff),color-mix(in srgb,var(--magenta) 7%,#fff));border:1px solid var(--line);overflow:hidden;margin-bottom:16px}
  .gn-hero .ic{position:absolute;right:-14px;top:-14px;opacity:.10}
  .gn-hero .ic svg{width:170px;height:170px}
  .gn-hero h1{font-family:var(--font-display);font-size:clamp(22px,4vw,30px);margin:0;color:var(--tinta);letter-spacing:-.02em}
  .gn-hero p{margin:8px 0 0;color:var(--muted);font-size:14px;line-height:1.55;max-width:56ch}
  .gn-hero .n{display:flex;align-items:baseline;gap:9px;margin-top:16px}
  .gn-hero .n b{font-family:var(--font-display);font-size:clamp(34px,7vw,46px);color:var(--teal);line-height:1}
  .gn-hero .n span{color:var(--muted);font-size:13px;font-weight:600}
  .gn-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px}
  .gn-card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:17px 18px}
  .gn-card h2{display:flex;align-items:center;gap:8px;font-family:var(--font-display);font-size:15.5px;margin:0 0 3px;color:var(--tinta)}
  .gn-card h2 svg{width:17px;height:17px;color:var(--teal)}
  .gn-card .sub{font-size:12px;color:var(--muted);margin:0 0 11px}
  .gn-card .tx{font-size:13.5px;color:var(--tinta);line-height:1.55;white-space:pre-line}
  .gn-vacio{font-size:13px;color:var(--muted);font-style:italic}
  .gn-chips{display:flex;flex-wrap:wrap;gap:7px}
  .gn-chip{font-size:12.5px;font-weight:600;color:var(--tinta);background:color-mix(in srgb,var(--teal) 9%,#fff);border:1px solid color-mix(in srgb,var(--teal) 24%,transparent);border-radius:99px;padding:5px 12px}
  .gn-tono{margin-bottom:9px}
  .gn-tono .k{display:flex;justify-content:space-between;font-size:12px;color:var(--muted);font-weight:600;margin-bottom:4px}
  .gn-tono .b{height:7px;border-radius:99px;background:var(--crema-2,#f0e7d8);overflow:hidden}
  .gn-tono .b i{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,var(--teal),var(--magenta))}
  .gn-mem{border-left:3px solid var(--teal);padding:8px 0 8px 12px;margin-bottom:8px}
  .gn-mem .t{font-size:13px;font-weight:700;color:var(--tinta)}
  .gn-mem .d{font-size:12.5px;color:var(--muted);line-height:1.45;margin-top:2px}
  .gn-mem .f{font-size:11px;color:var(--teal);font-weight:700;margin-top:3px}
  .gn-mem.opt{border-left-color:var(--magenta)}
  .gn-mem.opt .f{color:var(--magenta)}
  .gn-foot{text-align:center;color:var(--muted);font-size:12.5px;margin:22px 0 6px}
</style>

<div class="gn">
  <div class="gn-hero">
    <span class="ic"><?= ico('genoma') ?></span>
    <h1>El Genoma de <?= $h($marca['nombre_negocio']) ?></h1>
    <p>El cerebro que <b>solo existe para tu negocio</b>. Todo lo que el corillo sabe de ti vive aquí —
    y crece con cada conversación, cada corrección tuya y cada resultado medido.
    <?= $nacio ? "Nació el <b>{$nacio}</b> y no ha parado." : '' ?></p>
    <div class="n"><b><?= number_format($aportes) ?></b><span>aportes de conocimiento acumulados</span></div>
  </div>

  <div class="gn-grid">

    <div class="gn-card">
      <h2><?= ico('pen') ?> Tu voz</h2>
      <p class="sub">Cómo habla tu negocio — el corillo la imita, nunca la inventa.</p>
      <?php if (trim((string)($marca['voz'] ?? '')) !== ''): ?>
        <div class="tx"><?= $h($marca['voz']) ?></div>
      <?php else: ?>
        <p class="gn-vacio">Aún no está descrita — sale de tu entrevista.</p>
      <?php endif; ?>
      <?php if ($hay_tonos): ?>
        <div style="margin-top:13px">
        <?php foreach ($tonos as $k => $v): ?>
          <div class="gn-tono"><div class="k"><span><?= $k ?></span><span><?= $v ?></span></div><div class="b"><i style="width:<?= max(2, min(100, $v)) ?>%"></i></div></div>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="gn-card">
      <h2><?= ico('bookmark') ?> Vocabulario aprendido de TI</h2>
      <p class="sub">Cada vez que corriges un texto, el Genoma guarda la lección — para siempre.</p>
      <?php if ($glosario): ?>
        <div class="gn-chips"><?php foreach (array_slice($glosario, 0, 24) as $g): ?><span class="gn-chip"><?= $h($g) ?></span><?php endforeach; ?></div>
      <?php else: ?>
        <p class="gn-vacio">Todavía nada — corrige cualquier caption y verás la primera lección aparecer aquí.</p>
      <?php endif; ?>
    </div>

    <?php if ($radio): foreach ($CAPS as $ck => [$ci, $ct, $cs]): if (empty($radio[$ck])) continue; ?>
    <div class="gn-card">
      <h2><?= ico($ci) ?> <?= $ct ?></h2>
      <p class="sub"><?= $cs ?> — de la Radiografía que el Genoma redactó.</p>
      <div class="tx"><?= $h($radio[$ck]) ?></div>
    </div>
    <?php endforeach; else: ?>
    <div class="gn-card">
      <h2><?= ico('list') ?> La Radiografía</h2>
      <p class="sub">Las reglas del negocio, redactadas por el Genoma para alinear a todo el corillo.</p>
      <p class="gn-vacio">Se redacta sola la primera vez que el corillo la necesite — capítulos de identidad, voz, imagen, estrategia y personalidad.</p>
    </div>
    <?php endif; ?>

    <div class="gn-card" style="grid-column:1/-1">
      <h2><?= ico('star') ?> Memorias y lecciones</h2>
      <p class="sub">Hechos del negocio y lecciones <b>medidas</b> de tus resultados — nada de opinión.</p>
      <?php if ($memorias): foreach ($memorias as $m): $es_opt = (($m['fuente'] ?? '') === 'optimizador'); ?>
        <div class="gn-mem<?= $es_opt ? ' opt' : '' ?>">
          <div class="t"><?= $h($m['titulo'] ?? 'Aprendizaje') ?></div>
          <?php if (trim((string)($m['detalle'] ?? '')) !== '' && ($m['detalle'] ?? '') !== ($m['titulo'] ?? '')): ?>
            <div class="d"><?= $h(mb_strimwidth((string)$m['detalle'], 0, 220, '…')) ?></div>
          <?php endif; ?>
          <div class="f"><?= $es_opt ? 'medida de tus resultados (el Optimizador)' : (($m['fuente'] ?? '') === 'usuario' ? 'me lo enseñaste tú' : 'aprendida trabajando tu negocio') ?></div>
        </div>
      <?php endforeach; else: ?>
        <p class="gn-vacio">Las primeras memorias nacen con tu entrevista y tus primeros posts.</p>
      <?php endif; ?>
    </div>

  </div>

  <p class="gn-foot">Este Genoma es único de <b><?= $h($marca['nombre_negocio']) ?></b>. No hay otro igual — y mañana sabrá más que hoy.</p>
</div>

<?php require __DIR__ . '/_shell_foot.php'; ?>
