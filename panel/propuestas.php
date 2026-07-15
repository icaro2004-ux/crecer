<?php
// ============================================================
//  EL ESTUDIO — la sala de edición del negocio del cliente.
//  panel/propuestas.php  ·  (antes "Contenido")
//
//  Una propuesta a la vez, a pantalla completa. El dueño no
//  administra contenido — le da su bendición al trabajo de su
//  corillo y avanza. Reusa los handlers AJAX de aprobar2.php
//  (aprobar / rechazar / editar / regenerar). CERO backend nuevo.
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/suscripcion.php';
require __DIR__ . '/../includes/iconos.php';
requiere_login();
$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }
$marca_id = (int)$marca['id'];
$BASE = '/crecer/panel';
$mid  = "marca={$marca_id}";
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$negocio = $marca['nombre_negocio'] ?? 'tu negocio';

// ── El corillo mira PRIMERO la Biblioteca: atar una foto que ya existe a una
//    propuesta (en vez de generar). Sin IA, sin match inteligente — solo pone la
//    foto que el dueño escogió. ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'usar_activo') {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_ok()) { echo json_encode(['ok'=>false,'err'=>'Sesión expiró. Recarga.']); exit; }
    $pieza = (int)($_POST['pieza'] ?? 0); $activo = (int)($_POST['activo'] ?? 0);
    $a = $pdo->prepare("SELECT archivo FROM crecer_activos WHERE id=? AND marca_id=? AND tipo='imagen' AND estado='activo'");
    $a->execute([$activo, $marca_id]); $arch = $a->fetchColumn();
    $ok_pieza = false;
    if ($arch) { $c = $pdo->prepare("SELECT 1 FROM crecer_contenido WHERE id=? AND marca_id=?"); $c->execute([$pieza, $marca_id]); $ok_pieza = (bool)$c->fetchColumn(); }
    if ($arch && $ok_pieza) {
        $url = (defined('UPLOADS_URL') ? UPLOADS_URL : '/crecer/uploads') . '/' . $arch;
        $pdo->prepare("UPDATE crecer_contenido SET grafica_path=?, updated_at=NOW() WHERE id=? AND marca_id=?")->execute([$url, $pieza, $marca_id]);
        echo json_encode(['ok'=>true,'url'=>$url]); exit;
    }
    echo json_encode(['ok'=>false,'err'=>'No se pudo poner la foto.']); exit;
}

// Fotos que ya tiene el negocio (para ofrecerlas ANTES de generar).
$biblioteca = [];
try {
    $bq = $pdo->prepare("SELECT id, archivo FROM crecer_activos WHERE marca_id=? AND tipo='imagen' AND estado='activo' ORDER BY id DESC LIMIT 8");
    $bq->execute([$marca_id]); $biblioteca = $bq->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $biblioteca = []; }
$UP_URL_BIB = (defined('UPLOADS_URL') ? UPLOADS_URL : '/crecer/uploads');

// ── Las propuestas que esperan tu veredicto (borradores). Nada más. ──
$props = [];
try {
    $q = $pdo->prepare(
        "SELECT id, caption, plataforma, tipo, fecha_programada, grafica_path
         FROM crecer_contenido
         WHERE marca_id=? AND estado='borrador'
         ORDER BY COALESCE(fecha_programada, created_at) ASC, id ASC");
    $q->execute([$marca_id]);
    $props = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $props = []; }

// ¿Qué agentes trabajaron de verdad? (para los créditos del corillo, como el lobby)
$ags = [];
try {
    $fq = $pdo->prepare("SELECT DISTINCT agente FROM crecer_ia_log WHERE marca_id=? AND estado='ok' AND agente<>'kernel' ORDER BY id DESC LIMIT 12");
    $fq->execute([$marca_id]); $ags = $fq->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}

if (!function_exists('_fecha_humana')) {
    function _fecha_humana($f) {
        $ts = strtotime((string)$f); if (!$ts) return '';
        $dias = ['Sunday'=>'domingo','Monday'=>'lunes','Tuesday'=>'martes','Wednesday'=>'miércoles','Thursday'=>'jueves','Friday'=>'viernes','Saturday'=>'sábado'];
        $d = $dias[date('l', $ts)] ?? ''; $hora = date('g:i A', $ts); $fd = date('Y-m-d', $ts);
        if ($fd === date('Y-m-d')) return "hoy, {$hora}";
        if ($fd === date('Y-m-d', strtotime('+1 day'))) return "mañana, {$hora}";
        return "el {$d}, {$hora}";
    }
}
// Créditos reales del corillo para una pieza (unidad primero, individuos como prueba).
$creditos = function(array $p) use ($ags) {
    $cap = trim((string)$p['caption']);
    $arte = !empty($p['grafica_path']);
    $video = $arte && preg_match('#\.(mp4|mov|m4v)$#i', (string)$p['grafica_path']);
    $c = [];
    if ($cap !== '' || array_intersect(['creador','editor','aprendiz'], $ags)) $c[] = 'La Creativa escribió el caption';
    if ($arte || in_array('diseñador', $ags, true)) $c[] = ($video ? 'El Diseñador preparó el video' : 'El Diseñador montó el arte');
    if (!empty($p['fecha_programada'])) $c[] = 'La Estratega escogió la hora — ' . _fecha_humana($p['fecha_programada']);
    elseif (array_intersect(['planificador','intake','estratega'], $ags)) $c[] = 'La Estratega lo cuadró en el plan';
    return array_slice($c, 0, 3);
};

$active = 'contenido';
$page_title = 'Propuestas';
$guia = ['key'=>'propuestas','agente'=>'sparkles','titulo'=>'El estudio',
  'intro'=>'Aquí revisas lo que tu corillo preparó — una propuesta a la vez.',
  'pasos'=>[
    ['check-circle','Mira la propuesta. Si te gusta: Vamos con este.'],
    ['image','¿Un detalle? Ajústalo, sin salir.'],
    ['sparkles','Cuando decides, entra la siguiente sola.'],
  ]];
require __DIR__ . '/_shell.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
  /* ══ EL ESTUDIO ══ una propuesta a la vez. Hereda la calma del lobby. */
  .content{max-width:600px}
  .asis-fab{display:none}   /* el Copiloto se calla en la sala — el veredicto no compite con nada */
  .est{max-width:560px;margin:0 auto;padding:5vh 6px 90px;font-family:'Poppins',var(--font-body)}
  @media(min-width:761px){.est{padding:7vh 6px 70px}}
  .est-owner{font-size:12px;font-weight:600;letter-spacing:.02em;color:var(--muted);text-transform:none;margin:0 0 22px}
  .est-owner b{color:var(--tinta);font-weight:600}

  .est-prop{opacity:0}
  .est-prop.show{opacity:1}
  .est-ctx{font-size:12.5px;font-weight:600;color:var(--muted);text-transform:capitalize;margin:0 0 14px}
  .est-art{border-radius:18px;overflow:hidden;background:var(--crema-2);border:1px solid var(--line);
    display:grid;place-items:center;margin:0 0 18px;box-shadow:var(--shadow-sm)}
  .est-art img{width:100%;display:block}
  .est-art.txt{aspect-ratio:16/10;color:var(--teal)}
  .est-art.txt svg{width:38px;height:38px;opacity:.6}
  .est-vtag{display:flex;flex-direction:column;align-items:center;gap:7px;color:var(--teal-700);font-size:12px;font-weight:700;padding:38px 0}
  .est-vtag svg{width:30px;height:30px}
  .est-cap{font-size:16.5px;line-height:1.6;color:var(--tinta);white-space:pre-wrap;margin:0 0 18px;font-weight:400}
  .est-cap.hero{font-size:19px;line-height:1.7;margin-top:6px}   /* propuesta solo-texto: el caption es la obra */

  /* El corillo mira primero la biblioteca — natural, no un módulo */
  .est-bib{margin:0 0 20px}
  .est-bib-say{font-size:14.5px;color:var(--tinta);font-weight:500;line-height:1.5;margin:0 0 13px}
  .est-bib-row{display:flex;gap:9px;overflow-x:auto;padding-bottom:5px;-webkit-overflow-scrolling:touch}
  .est-bib-t{flex:none;width:74px;height:74px;border-radius:12px;border:1px solid var(--line);cursor:pointer;padding:0;
    background-size:cover;background-position:center;background-color:var(--crema-2);transition:transform .15s,box-shadow .15s}
  .est-bib-t:hover{transform:translateY(-2px);box-shadow:var(--shadow)}
  .est-bib-alt{margin-top:13px;font-size:13px;color:var(--muted)}
  .est-bib-alt a{color:var(--teal-700);text-decoration:none;font-weight:500}
  .est-bib-alt a:hover{text-decoration:underline}
  .est-cred{list-style:none;margin:0 0 26px;padding:14px 0 0;border-top:1px solid var(--line);display:flex;flex-direction:column;gap:9px}
  .est-cred li{display:flex;align-items:flex-start;gap:10px;font-size:13.5px;color:var(--muted);line-height:1.35}
  .est-cred .ck{flex:none;margin-top:1px;color:var(--palma);display:inline-flex}
  .est-cred .ck svg{width:17px;height:17px}

  /* El veredicto */
  .est-verdict{display:flex;flex-direction:column;align-items:center;gap:14px}
  .est-go{border:0;cursor:pointer;font-family:'Poppins',sans-serif;font-weight:600;font-size:17px;color:#fff;
    padding:16px 46px;border-radius:15px;background:linear-gradient(135deg,var(--coral),var(--magenta));
    box-shadow:0 16px 36px -14px rgba(255,43,133,.55);transition:transform .16s,box-shadow .16s,opacity .2s;width:100%;max-width:340px}
  .est-go:hover{transform:translateY(-2px);box-shadow:0 22px 44px -14px rgba(255,43,133,.6)}
  .est-go:disabled{opacity:.65;cursor:default;transform:none}
  .est-minor{display:flex;gap:26px;align-items:center}
  .est-minor button{background:0;border:0;cursor:pointer;font-family:'Poppins',sans-serif;font-size:14px;font-weight:500;color:var(--muted)}
  .est-minor button:hover{color:var(--tinta)}

  /* Puertas cerradas (divulgación progresiva) */
  .est-panel{margin-top:18px;background:var(--card);border:1px solid var(--line);border-radius:16px;padding:16px;box-shadow:var(--shadow-sm)}
  .est-panel[hidden]{display:none}
  .est-panel h4{font-family:'Poppins',sans-serif;font-weight:600;font-size:13px;color:var(--muted);margin:0 0 10px;text-transform:none}
  .est-ta{width:100%;box-sizing:border-box;font-family:var(--font-body);font-size:15px;line-height:1.55;color:var(--tinta);
    border:1.5px solid var(--line);border-radius:12px;padding:12px;resize:vertical;min-height:120px;background:#fff}
  .est-ta:focus{outline:2px solid color-mix(in srgb,var(--magenta) 40%,transparent);outline-offset:1px;border-color:transparent}
  .est-panel-row{display:flex;gap:9px;flex-wrap:wrap;margin-top:12px}
  .est-b{border:0;cursor:pointer;font-family:'Poppins',sans-serif;font-weight:600;font-size:13.5px;padding:11px 16px;border-radius:11px}
  .est-b.pri{color:#fff;background:var(--palma)}
  .est-b.gho{background:#fff;border:1.5px solid var(--line);color:var(--tinta)}
  .est-b.lnk{background:0;color:var(--teal-700);padding:11px 4px}
  .est-b:disabled{opacity:.6;cursor:default}
  .est-reason{display:flex;gap:8px;flex-wrap:wrap}
  .est-reason button{background:#fff;border:1.5px solid var(--line);border-radius:99px;padding:9px 15px;font-family:'Poppins',sans-serif;font-size:13.5px;font-weight:500;color:var(--tinta);cursor:pointer}
  .est-reason button:hover{border-color:var(--noo-ink);color:var(--noo-ink)}
  .est-note{font-size:12.5px;color:var(--muted);margin:10px 0 0}

  /* El cierre en calma */
  .est-done{text-align:center;padding:12vh 10px}
  .est-done .mk{width:56px;height:56px;border-radius:50%;display:grid;place-items:center;margin:0 auto 20px;
    background:color-mix(in srgb,var(--palma) 12%,#fff);color:var(--palma-600)}
  .est-done .mk svg{width:28px;height:28px}
  .est-done h2{font-family:'Poppins',sans-serif;font-weight:500;font-size:clamp(22px,4.6vw,30px);line-height:1.2;color:var(--tinta);margin:0 0 10px;text-wrap:balance}
  .est-done p{font-size:15px;color:var(--muted);margin:0 0 26px;line-height:1.5}
  .est-done .acts{display:flex;gap:22px;justify-content:center;flex-wrap:wrap}
  .est-done .acts a{color:var(--teal-700);font-weight:600;font-size:14.5px;text-decoration:none}
  .est-done[hidden]{display:none}
</style>

<main class="est" id="est">
  <p class="est-owner">El estudio de <b><?= $h($negocio) ?></b></p>

  <?php if (!$props): ?>
    <div class="est-done">
      <div class="mk"><?= ico('check-circle') ?></div>
      <h2>Nada que revisar por ahora.</h2>
      <p>El corillo está preparando lo próximo. Vuelve en un rato.</p>
      <div class="acts">
        <a href="<?= $BASE ?>/aprobar2.php?<?= $mid ?>">Pídeles algo nuevo</a>
        <a href="<?= $BASE ?>/resultados.php?marca=<?= $marca_id ?>">Ver lo publicado</a>
      </div>
    </div>
  <?php else: ?>
    <?php foreach ($props as $i => $p):
      $cap  = trim((string)$p['caption']);
      $arte = !empty($p['grafica_path']);
      $video = $arte && preg_match('#\.(mp4|mov|m4v)$#i', (string)$p['grafica_path']);
      $img  = $arte && !$video;
      $ctx  = ucfirst((string)($p['plataforma'] ?? '')) . (!empty($p['fecha_programada']) ? ' · sale ' . _fecha_humana($p['fecha_programada']) : '');
      $cred = $creditos($p);
    ?>
      <article class="est-prop<?= $i===0?' show':'' ?>" id="prop-<?= (int)$p['id'] ?>" data-id="<?= (int)$p['id'] ?>" <?= $i===0?'':'style="display:none"' ?>>
        <p class="est-ctx"><?= $h($ctx) ?></p>

        <?php if ($img || $video): ?>
        <div class="est-art">
          <?php if ($img): ?><img src="<?= $h($p['grafica_path']) ?>" alt="">
          <?php else: ?><span class="est-vtag"><?= ico('image') ?>Video</span><?php endif; ?>
        </div>
        <?php elseif ($biblioteca): /* el corillo mira PRIMERO lo que el negocio ya tiene */ ?>
        <div class="est-bib" data-piezabib="<?= (int)$p['id'] ?>">
          <p class="est-bib-say">El corillo miró tu biblioteca primero. ¿Alguna de estas va con este post?</p>
          <div class="est-bib-row">
            <?php foreach ($biblioteca as $bx): $burl = $UP_URL_BIB . '/' . $bx['archivo']; ?>
              <button type="button" class="est-bib-t" data-activo="<?= (int)$bx['id'] ?>" style="background-image:url('<?= $h($burl) ?>')" aria-label="Usar esta foto"></button>
            <?php endforeach; ?>
          </div>
          <div class="est-bib-alt"><a href="<?= $BASE ?>/biblioteca.php?<?= $mid ?>">Ver toda la biblioteca</a> · <a href="<?= $BASE ?>/aprobar2.php?edit=<?= (int)$p['id'] ?>&<?= $mid ?>#cap-<?= (int)$p['id'] ?>">o que genere una nueva</a></div>
        </div>
        <?php endif; ?>

        <p class="est-cap<?= ($img || $video || $biblioteca) ? '' : ' hero' ?>" data-cap><?= $h($cap !== '' ? $cap : '(sin texto todavía)') ?></p>

        <?php if ($cred): ?>
        <ul class="est-cred">
          <?php foreach ($cred as $c): ?><li><span class="ck"><?= ico('check-circle') ?></span><?= $h($c) ?></li><?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <div class="est-verdict">
          <button type="button" class="est-go" data-go>Vamos con este</button>
          <div class="est-minor">
            <button type="button" data-adjust>Ajústalo</button>
            <button type="button" data-reject>No</button>
          </div>
        </div>

        <!-- Puerta: Ajústalo -->
        <div class="est-panel" data-panel="adjust" hidden>
          <h4>Ajústale el texto — el corillo aprende de tu cambio.</h4>
          <textarea class="est-ta" data-editor><?= $h($cap) ?></textarea>
          <div class="est-panel-row">
            <button type="button" class="est-b pri" data-save>Guardar</button>
            <button type="button" class="est-b gho" data-regen>Otra versión</button>
            <a class="est-b lnk" href="<?= $BASE ?>/aprobar2.php?edit=<?= (int)$p['id'] ?>&<?= $mid ?>#cap-<?= (int)$p['id'] ?>">Arte, fecha y más →</a>
            <button type="button" class="est-b lnk" data-cancel style="margin-left:auto">Cerrar</button>
          </div>
          <p class="est-note" data-panelnote></p>
        </div>

        <!-- Puerta: No (razón que enseña al Cerebro) -->
        <div class="est-panel" data-panel="reject" hidden>
          <h4>¿Qué no cuadró? Así el corillo lo hace mejor la próxima.</h4>
          <div class="est-reason">
            <button type="button" data-razon="formal">Muy formal</button>
            <button type="button" data-razon="largo">Muy largo</button>
            <button type="button" data-razon="voz">No es mi voz</button>
            <button type="button" data-razon="">Solo no</button>
          </div>
          <button type="button" class="est-b lnk" data-cancel style="margin-top:12px">Cerrar</button>
        </div>
      </article>
    <?php endforeach; ?>

    <!-- El cierre en calma -->
    <div class="est-done" id="estDone" hidden>
      <div class="mk"><?= ico('check-circle') ?></div>
      <h2>Ya revisaste todo lo que el corillo preparó.</h2>
      <p>El corillo sigue trabajando por <?= $h($negocio) ?>.</p>
      <div class="acts">
        <a href="<?= $BASE ?>/aprobar2.php?<?= $mid ?>">Pídeles algo nuevo</a>
        <a href="<?= $BASE ?>/resultados.php?marca=<?= $marca_id ?>">Ver lo publicado</a>
      </div>
    </div>
  <?php endif; ?>
</main>

<script>
(function () {
  var CSRF = <?= json_encode(csrf_token()) ?>, MARCA = <?= (int)$marca_id ?>, BASE = <?= json_encode($BASE) ?>;
  var props = [].slice.call(document.querySelectorAll('.est-prop'));
  var done = document.getElementById('estDone');
  var idx = 0, busy = false;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function post(accion, id, extra) {
    var fd = new FormData(); fd.append('csrf', CSRF); fd.append('accion', accion); fd.append('id', id); fd.append('ajax', '1');
    if (extra) for (var k in extra) fd.append(k, extra[k]);
    return fetch(BASE + '/aprobar2.php?marca=' + MARCA, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
  }

  // El pase: la propuesta sale, la siguiente sube al mismo lugar. El único movimiento.
  function pase() {
    var cur = props[idx];
    idx++;
    var next = props[idx];
    if (!next) {
      if (reduce) { cur.style.display = 'none'; done.hidden = false; return; }
      cur.style.transition = 'opacity .34s ease, transform .34s ease';
      cur.style.opacity = '0'; cur.style.transform = 'translateY(-8px)';
      setTimeout(function () { cur.style.display = 'none'; done.hidden = false; }, 320);
      return;
    }
    if (reduce) {
      cur.style.display = 'none';
      next.style.display = ''; next.classList.add('show');
      return;
    }
    cur.style.transition = 'opacity .30s ease, transform .30s ease';
    cur.style.opacity = '0'; cur.style.transform = 'translateY(-8px)';
    setTimeout(function () {
      cur.style.display = 'none';
      next.style.display = '';
      next.style.opacity = '0'; next.style.transform = 'translateY(10px)';
      next.classList.add('show');
      requestAnimationFrame(function () {
        next.style.transition = 'opacity .38s cubic-bezier(.22,1,.36,1), transform .38s cubic-bezier(.22,1,.36,1)';
        next.style.opacity = '1'; next.style.transform = 'none';
      });
    }, 300);
  }

  function toast(card, msg) { var n = card.querySelector('[data-panelnote]'); if (n) n.textContent = msg; }

  props.forEach(function (card) {
    var id = card.getAttribute('data-id');
    var go = card.querySelector('[data-go]');
    var panels = { adjust: card.querySelector('[data-panel="adjust"]'), reject: card.querySelector('[data-panel="reject"]') };
    function closePanels() { panels.adjust.hidden = true; panels.reject.hidden = true; }

    // Vamos con este
    go.addEventListener('click', function () {
      if (busy) return; busy = true; go.disabled = true; var old = go.textContent; go.textContent = 'Un momento…';
      post('aprobar', id).then(function (d) {
        if (d && d.ok) { pase(); }
        else { go.disabled = false; go.textContent = old; alert((d && d.err) || 'No se pudo. Intenta otra vez.'); }
      }).catch(function () { go.disabled = false; go.textContent = old; alert('Se cayó la conexión.'); })
        .finally(function () { busy = false; });
    });

    // Ajústalo / No (abrir puertas)
    card.querySelector('[data-adjust]').addEventListener('click', function () { var open = panels.adjust.hidden; closePanels(); panels.adjust.hidden = !open; });
    card.querySelector('[data-reject]').addEventListener('click', function () { var open = panels.reject.hidden; closePanels(); panels.reject.hidden = !open; });
    card.querySelectorAll('[data-cancel]').forEach(function (b) { b.addEventListener('click', closePanels); });

    // Guardar texto
    card.querySelector('[data-save]').addEventListener('click', function () {
      var ta = card.querySelector('[data-editor]');
      var cap = ta.value.trim(); if (!cap) return;
      var btn = this; btn.disabled = true; btn.textContent = 'Guardando…';
      post('editar', id, { caption: cap }).then(function (d) {
        if (d && d.ok) { card.querySelector('[data-cap]').textContent = d.caption || cap; closePanels(); }
        else toast(card, 'No se pudo guardar.');
      }).catch(function () { toast(card, 'Se cayó la conexión.'); })
        .finally(function () { btn.disabled = false; btn.textContent = 'Guardar'; });
    });

    // Otra versión (regenerar)
    card.querySelector('[data-regen]').addEventListener('click', function () {
      var btn = this; btn.disabled = true; btn.textContent = 'Escribiendo…'; toast(card, 'La Creativa está escribiendo otra versión…');
      post('regenerar', id).then(function (d) {
        if (d && d.ok && d.caption) {
          card.querySelector('[data-cap]').textContent = d.caption;
          card.querySelector('[data-editor]').value = d.caption;
          toast(card, 'Lista. Si te gusta, Guardar o Vamos con este.');
        } else if (d && d.paywall) { toast(card, 'Reescribir con IA es del plan pago. El texto actual queda igual.'); }
        else { toast(card, 'No pude reescribir ahora. Intenta de nuevo.'); }
      }).catch(function () { toast(card, 'Se cayó la conexión.'); })
        .finally(function () { btn.disabled = false; btn.textContent = 'Otra versión'; });
    });

    // No, con razón (enseña al Cerebro)
    card.querySelectorAll('[data-razon]').forEach(function (b) {
      b.addEventListener('click', function () {
        if (busy) return; busy = true;
        post('rechazar', id, { razon: b.getAttribute('data-razon') }).then(function (d) {
          if (d && d.ok) { closePanels(); pase(); }
          else alert((d && d.err) || 'No se pudo.');
        }).catch(function () { alert('Se cayó la conexión.'); })
          .finally(function () { busy = false; });
      });
    });
  });

  // ── El corillo mira la Biblioteca: usar una foto que YA existe (antes de generar) ──
  var SELF = location.pathname + '?<?= $h($mid) ?>';
  document.querySelectorAll('.est-bib').forEach(function (bib) {
    var pieza = bib.getAttribute('data-piezabib');
    bib.querySelectorAll('.est-bib-t').forEach(function (t) {
      t.addEventListener('click', function () {
        if (t.dataset.busy) return; t.dataset.busy = '1'; t.style.opacity = '.5';
        var fd = new FormData(); fd.append('csrf', CSRF); fd.append('accion', 'usar_activo'); fd.append('pieza', pieza); fd.append('activo', t.getAttribute('data-activo'));
        fetch(SELF, { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (d) {
          if (d && d.ok && d.url) {
            var art = document.createElement('div'); art.className = 'est-art';
            art.innerHTML = '<img src="' + d.url + '" alt="">';
            bib.replaceWith(art);   // "ya tenemos la foto" — la propuesta ahora la lleva
          } else { t.style.opacity = ''; t.dataset.busy = ''; alert((d && d.err) || 'No se pudo poner la foto.'); }
        }).catch(function () { t.style.opacity = ''; t.dataset.busy = ''; alert('Se cayó la conexión.'); });
      });
    });
  });
})();
</script>

<?php require __DIR__ . '/_shell_foot.php'; ?>
