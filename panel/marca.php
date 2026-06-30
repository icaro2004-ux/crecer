<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Mi Marca (galería de logos + escoger)
//  panel/marca.php
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/agentes.php';
require __DIR__ . '/../includes/suscripcion.php';
requiere_login();
$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/intake.php'); exit; }
$marca_id = (int)$marca['id'];
$pagado = marca_es_pagada($pdo, $marca_id);  // el LOGO solo se desbloquea pagando

$LIMITE_LOGO = 5;
$cuenta = $pdo->prepare("SELECT COUNT(*) FROM crecer_logos WHERE marca_id=?");
$cuenta->execute([$marca_id]); $usados = (int)$cuenta->fetchColumn();
$restantes = max(0, $LIMITE_LOGO - $usados);
$final = (int)$marca['logo_final'] === 1;

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    // El Cerebro: el dueño controla su conocimiento (corregir / descartar).
    if ($accion === 'memoria_descartar' && function_exists('memoria_descartar')) {
        memoria_descartar($pdo, $marca_id, (int)($_POST['mid'] ?? 0));
        header("Location: /crecer/panel/marca.php?marca={$marca_id}#cerebro"); exit;
    }
    if ($accion === 'memoria_editar' && function_exists('memoria_editar')) {
        memoria_editar($pdo, $marca_id, (int)($_POST['mid'] ?? 0), (string)($_POST['detalle'] ?? ''));
        header("Location: /crecer/panel/marca.php?marca={$marca_id}#cerebro"); exit;
    }

    if ($accion === 'tono') {
        $vals = [];
        foreach (['boricua','formal','venta','ingenio'] as $k) $vals[$k] = max(0, min(100, (int)($_POST['t_'.$k] ?? 50)));
        $preset = substr(trim((string)($_POST['preset'] ?? '')), 0, 20);
        $pdo->prepare("UPDATE crecer_marca SET tono_boricua=?, tono_formal=?, tono_venta=?, tono_ingenio=?, tono_preset=? WHERE id=?")
            ->execute([$vals['boricua'], $vals['formal'], $vals['venta'], $vals['ingenio'], $preset ?: null, $marca_id]);
        header("Location: /crecer/panel/marca.php?marca={$marca_id}&tono=1"); exit;
    }

    if ($accion === 'tono_preview') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $m = leer_marca($pdo, $marca_id);
            foreach (['boricua','formal','venta','ingenio'] as $k)
                $m['tono_'.$k] = max(0, min(100, (int)($_POST['t_'.$k] ?? ($m['tono_'.$k] ?? 50))));
            $ctx = marca_contexto($m);
            $sistema = "Eres el CREADOR de contenido de Crecer. Escribes captions cortos para redes sociales de microempresas boricuas. Español puertorriqueño AUTÉNTICO, nunca traducido ni \"AI slop\". Vocabulario local. Llamado a la acción por WhatsApp. Máximo 45 palabras por caption." . tono_instruccion($m);
            $prompt = "Perfil del negocio:\n{$ctx}\n\nEscribe TRES (3) captions DISTINTOS para un post que promociona el negocio o su producto principal, los tres en EXACTAMENTE el mismo tono indicado arriba. Sepáralos con una línea que diga solo: ===\nNo los numeres, no pongas títulos, no expliques nada.";
            $r = ia_ejecutar($pdo, 'creador', 'Vista previa de tono', $prompt, [
                'marca_id' => $marca_id, 'sistema' => $sistema,
                'temperatura' => 1.0, 'max_tokens' => 600, 'thinking_budget' => 0,
                'mock_texto' => "¡Wepa! Llegó bizcocho fresco hoy 🔥 Escríbenos por WhatsApp 📲\n===\nDate el gusto: tres leches cremosito como te gusta 😋\n===\nOrdena hoy por WhatsApp y te lo apartamos, mi gente 💛",
            ]);
            $parts = preg_split('/\n?\s*={2,}\s*\n?/', trim((string)$r['texto']));
            $parts = array_values(array_filter(array_map('trim', $parts), fn($x) => $x !== ''));
            echo json_encode(['ok' => true, 'variaciones' => array_slice($parts, 0, 3)], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => substr($e->getMessage(), 0, 160)], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($accion === 'logo') {
        if (!$pagado)           $err = 'El logo se desbloquea cuando te suscribes a un plan.';
        elseif ($final)         $err = 'Tu logo ya está finalizado.';
        elseif ($usados >= $LIMITE_LOGO) $err = "Llegaste a tus {$LIMITE_LOGO} pruebas.";
        else {
            @set_time_limit(0);
            $desc = trim($_POST['descripcion'] ?? '');
            if ($desc !== '' && $desc !== (string)$marca['descripcion']) {
                $pdo->prepare("UPDATE crecer_marca SET descripcion=? WHERE id=?")->execute([$desc, $marca_id]);
            }
            $opts = [
                'descripcion'   => $desc,
                'estilo'        => $_POST['estilo'] ?? '',
                'tipografia'    => $_POST['tipografia'] ?? '',
                'tono'          => (int)($_POST['tono'] ?? 50),
                'epoca'         => (int)($_POST['epoca'] ?? 50),
                'detalle'       => (int)($_POST['detalle'] ?? 50),
                'instrucciones' => $_POST['instrucciones'] ?? '',
            ];
            try { generar_logo($pdo, $marca_id, $opts); }
            catch (Throwable $e) { $err = 'No se pudo generar: ' . substr($e->getMessage(), 0, 120); }
            if (!$err) { header("Location: /crecer/panel/marca.php?marca={$marca_id}&ok=1"); exit; }
        }
    } elseif ($accion === 'elegir' && !$final) {
        $lid = (int)($_POST['logo_id'] ?? 0);
        $own = $pdo->prepare("SELECT archivo FROM crecer_logos WHERE id=? AND marca_id=?");
        $own->execute([$lid, $marca_id]);
        if ($arch = $own->fetchColumn()) {
            $pdo->prepare("UPDATE crecer_logos SET elegido=0 WHERE marca_id=?")->execute([$marca_id]);
            $pdo->prepare("UPDATE crecer_logos SET elegido=1 WHERE id=?")->execute([$lid]);
            $pdo->prepare("UPDATE crecer_marca SET logo_path=? WHERE id=?")->execute([$arch, $marca_id]);
        }
        header("Location: /crecer/panel/marca.php?marca={$marca_id}"); exit;
    } elseif ($accion === 'bloquear' && !$final) {
        $pdo->prepare("UPDATE crecer_marca SET logo_final=1 WHERE id=?")->execute([$marca_id]);
        http_response_code(204); exit; // para el fetch del download
    }
}

$logos = $pdo->prepare("SELECT * FROM crecer_logos WHERE marca_id=? ORDER BY id");
$logos->execute([$marca_id]); $logos = $logos->fetchAll();
$elegido = null; foreach ($logos as $l) if ($l['elegido']) $elegido = $l;
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// Valores actuales del tono (para precargar los sliders)
$T = [
  'boricua' => (int)($marca['tono_boricua'] ?? 80),
  'formal'  => (int)($marca['tono_formal']  ?? 30),
  'venta'   => (int)($marca['tono_venta']   ?? 55),
  'ingenio' => (int)($marca['tono_ingenio'] ?? 60),
];
$Tpreset = (string)($marca['tono_preset'] ?? '');

$active = 'marca';
$page_title = 'Mi Marca';
$guia = ['key'=>'marca','agente'=>'palette','titulo'=>'Tu marca y logo',
  'intro'=>'El Diseñador te crea tu logo profesional con IA.',
  'pasos'=>[
    ['sparkles','Describe tu negocio y dale "Generar mi primer logo".'],
    ['image','Genera varios, compáralos y escoge el que más te guste.'],
    ['download','Descárgalo en todos los formatos que necesites.'],
  ]];
require __DIR__ . '/_shell.php';
?>
<style>
  .subline{color:var(--muted);font-size:15px;margin-top:4px}
  .subline b{color:var(--terracota)}
  .ok-banner{background:var(--okk-bg);color:var(--okk-ink);font-weight:700;font-size:14px;padding:11px 14px;border-radius:12px;margin-top:14px}
  .err-banner{background:var(--noo-bg);color:var(--noo-ink);font-weight:700;font-size:14px;padding:11px 14px;border-radius:12px;margin-top:14px}

  .genbox{background:var(--card);border:1px solid var(--line);border-radius:var(--r-lg);padding:18px;box-shadow:var(--shadow-sm);margin-top:16px;max-width:620px}
  .genbox textarea{width:100%;font-family:inherit;font-size:14px;border:1.5px solid var(--line);border-radius:12px;padding:10px 12px;resize:vertical;margin-bottom:10px}
  .genbtn{border:0;cursor:pointer;font-family:inherit;font-weight:800;font-size:15px;color:#fff;background:linear-gradient(135deg,var(--coral),var(--magenta));padding:13px 22px;border-radius:99px;box-shadow:0 10px 24px rgba(255,43,133,.28)}
  .genline{font-size:12.5px;color:var(--muted);margin-top:10px}
  .policy{font-size:11.5px;color:var(--muted);margin-top:4px}
  .genbox textarea{width:100%}
  .fl{display:block;font-weight:700;font-size:13px;margin:14px 0 7px}
  .chips{display:flex;flex-wrap:wrap;gap:7px}
  .chip-opt{cursor:pointer}
  .chip-opt input{position:absolute;opacity:0;pointer-events:none}
  .chip-opt span{display:inline-block;padding:7px 13px;border-radius:99px;border:1.5px solid var(--line);background:#fff;font-weight:700;font-size:13px;transition:all .15s}
  .chip-opt input:checked + span{border-color:transparent;color:#fff;background:linear-gradient(135deg,var(--coral),var(--magenta))}
  .slider{display:flex;align-items:center;gap:10px;margin:8px 0}
  .slider span{font-size:12px;color:var(--muted);font-weight:700;width:64px}
  .slider span:last-child{text-align:right}
  .slider input[type=range]{flex:1;accent-color:var(--terracota)}

  .gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px;margin-top:22px}
  .tile{background:var(--card);border:2px solid var(--line);border-radius:18px;padding:12px;text-align:center;transition:border-color .15s,opacity .15s}
  .tile.sel{border-color:var(--terracota);box-shadow:0 10px 26px rgba(239,67,117,.16)}
  .tile.locked{opacity:.45}
  .tile img{width:100%;border-radius:12px;display:block}
  .tile .badge{display:inline-block;margin-top:9px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.03em;color:var(--okk-ink);background:var(--okk-bg);padding:4px 10px;border-radius:99px}
  .tile .pick{margin-top:9px;width:100%;border:1.5px solid var(--line);background:#fff;color:var(--tinta);font-family:inherit;font-weight:700;font-size:13px;cursor:pointer;border-radius:99px;padding:8px}
  .tile .pick:hover{border-color:var(--terracota);color:var(--terracota-700)}

  .chosen{background:linear-gradient(135deg,rgba(255,107,61,.06),rgba(255,43,133,.06));border:1px solid var(--line);border-radius:var(--r-lg);padding:18px;margin-top:22px;max-width:620px}
  .chosen h3{font-family:var(--font-display);font-weight:800;font-size:16px;margin-bottom:4px}
  .dl{margin-top:10px}
  .dl button{font-family:inherit;font-weight:700;font-size:13px;cursor:pointer;border:1.5px solid var(--line);background:#fff;color:var(--tinta);border-radius:99px;padding:8px 14px;margin:3px}
  .dl button:hover{border-color:var(--terracota);color:var(--terracota-700)}
  .warn{font-size:12px;color:var(--muted);margin-top:8px}
  .empty-g{color:var(--muted);font-size:15px;margin-top:22px}
  /* El Cerebro — tarjetas de aprendizaje */
  .cer-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:14px;max-width:920px;margin-top:14px}
  .cer-card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:15px 16px;box-shadow:var(--shadow-sm)}
  .cer-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
  .cer-tag{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:var(--terracota);background:color-mix(in srgb,var(--terracota) 10%,#fff);border-radius:99px;padding:3px 10px}
  .cer-conf{font-size:11px;color:var(--muted);font-weight:700}
  .cer-det{margin:0;font-size:14px;color:var(--tinta);line-height:1.45}
  .cer-why{margin:7px 0 0;font-size:12px;color:var(--muted);font-style:italic}
  .cer-acts{display:flex;gap:14px;margin-top:11px}
  .cer-link{background:none;border:0;cursor:pointer;font-family:inherit;font-weight:700;font-size:12.5px;color:var(--terracota);padding:0}
  .cer-link.danger{color:var(--muted)}
  .cer-editform textarea{width:100%;font-family:inherit;font-size:13.5px;border:1.5px solid var(--line);border-radius:10px;padding:8px;margin:8px 0}
  .cer-editform .btn-save{background:var(--terracota);color:#fff;border:0;border-radius:9px;padding:7px 14px;font-weight:800;cursor:pointer;font-size:12.5px}
</style>

<h1 class="page-h">Mi Marca</h1>
<?php if (!empty($_GET['tono'])): ?><div class="ok-banner" style="max-width:680px">✓ Tu tono quedó guardado. La Creativa escribirá así de ahora en adelante.</div><?php endif; ?>

<h2 class="sec-h">🎙️ Tu tono de voz</h2>
<p class="subline">Define cómo te escribe La Creativa: mueve los controles, genera ejemplos y guárdalo. Así suena <b>todo tu contenido</b>.</p>
<?php include __DIR__ . '/_tono_panel.php'; ?>

<?php
if (function_exists('memoria_consolidar')) memoria_consolidar($pdo, $marca_id);
$memorias = function_exists('memoria_listar') ? memoria_listar($pdo, $marca_id) : [];
$tipo_lbl = ['patron'=>'Patrón detectado','preferencia'=>'Preferencia','decision'=>'Decisión','tono'=>'Voz de marca','marca'=>'Identidad','conversacion'=>'De una conversación','hito'=>'Hito'];
?>
<h2 class="sec-h" style="margin-top:44px" id="cerebro">🧠 Lo que he aprendido de tu negocio</h2>
<p class="subline">El corillo aprende de lo que apruebas, editas y rechazas, y lo usa para escribir mejor. Esto es tuyo: <b>corrígelo o descártalo</b> cuando quieras.</p>
<?php if (!$memorias): ?>
  <div class="empty-g" style="max-width:680px">Todavía no he aprendido nada específico. Aprueba, edita o rechaza unos posts y aquí verás lo que voy captando de tu negocio.</div>
<?php else: ?>
  <div class="cer-grid">
    <?php foreach ($memorias as $mm): ?>
      <div class="cer-card">
        <div class="cer-top"><span class="cer-tag"><?= $h($tipo_lbl[$mm['tipo']] ?? 'Aprendizaje') ?></span><span class="cer-conf">confianza <?= (int)$mm['confianza'] ?>%</span></div>
        <p class="cer-det"><?= $h($mm['detalle']) ?></p>
        <?php if (!empty($mm['porque'])): ?><p class="cer-why"><?= $h($mm['porque']) ?></p><?php endif; ?>
        <div class="cer-acts">
          <button type="button" class="cer-link" onclick="cerEdit(<?= (int)$mm['id'] ?>)">Corregir</button>
          <form method="post" onsubmit="return confirm('¿Descartar este aprendizaje? El corillo dejará de usarlo.')" style="display:inline">
            <input type="hidden" name="accion" value="memoria_descartar"><input type="hidden" name="mid" value="<?= (int)$mm['id'] ?>">
            <button type="submit" class="cer-link danger">Descartar</button>
          </form>
        </div>
        <form method="post" class="cer-editform" id="cerf-<?= (int)$mm['id'] ?>" style="display:none">
          <input type="hidden" name="accion" value="memoria_editar"><input type="hidden" name="mid" value="<?= (int)$mm['id'] ?>">
          <textarea name="detalle" rows="2"><?= $h($mm['detalle']) ?></textarea>
          <div><button type="submit" class="btn-save">Guardar</button> &nbsp;<button type="button" class="cer-link" onclick="cerCancel(<?= (int)$mm['id'] ?>)">Cancelar</button></div>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<script>
function cerEdit(id){var f=document.getElementById('cerf-'+id);if(f)f.style.display='block';}
function cerCancel(id){var f=document.getElementById('cerf-'+id);if(f)f.style.display='none';}
</script>

<h2 class="sec-h" style="margin-top:44px">🎨 Tu logo</h2>
<?php if ($pagado): ?>
  <p class="subline">Tienes <b><?= $LIMITE_LOGO ?> oportunidades</b> para crear tu logo con IA. Genéralos, compáralos y escoge el que más te guste.</p>
<?php else: ?>
  <p class="subline">Tu logo profesional con IA — <b>se desbloquea con un plan</b>.</p>
<?php endif; ?>
<?php if (!empty($_GET['ok'])): ?><div class="ok-banner">✓ ¡Nuevo logo listo! Mira la galería abajo.</div><?php endif; ?>
<?php if ($err): ?><div class="err-banner">⚠️ <?= $h($err) ?></div><?php endif; ?>

<?php if (!$pagado): ?>
  <div class="genbox" style="text-align:center;background:linear-gradient(135deg,rgba(255,107,61,.07),rgba(255,43,133,.07))">
    <div style="color:var(--terracota)"><?= ico('lock','ic-xl') ?></div>
    <div style="font-family:var(--font-impact);text-transform:uppercase;font-size:22px;margin:6px 0">El logo es premium</div>
    <p style="color:var(--muted);font-size:14px;max-width:430px;margin:0 auto 16px">Tu logo profesional con IA, en los formatos que necesites, se desbloquea con un plan. (Tu post de muestra sí es gratis)</p>
    <a class="genbtn" href="/crecer/panel/precios.php?marca=<?= $marca_id ?>" style="text-decoration:none;display:inline-block">⚡ Desbloquear mi logo →</a>
  </div>
<?php elseif (!$final && $restantes > 0): ?>
  <div class="genbox">
    <form method="post" onsubmit="var b=this.querySelector('.genbtn');b.textContent='✨ Creando… (~15s)';b.disabled=true;">
      <input type="hidden" name="accion" value="logo">

      <label class="fl">Descripción del negocio <span style="color:var(--muted);font-weight:500">(edítala a tu gusto)</span></label>
      <textarea name="descripcion" rows="2"><?= $h($marca['descripcion']) ?></textarea>

      <label class="fl">Estilo</label>
      <div class="chips">
        <?php
        $estilos = ['Auto'=>'', 'Boricua'=>'boricua, con orgullo puertorriqueño', 'Moderno'=>'moderno y minimalista', 'Clásico'=>'clásico y atemporal', 'Elegante'=>'elegante y premium', 'Divertido'=>'divertido y colorido', 'Retro'=>'retro vintage'];
        foreach ($estilos as $lb=>$val): ?>
          <label class="chip-opt"><input type="radio" name="estilo" value="<?= $h($val) ?>" <?= $lb==='Auto'?'checked':'' ?>><span><?= $h($lb) ?></span></label>
        <?php endforeach; ?>
      </div>

      <label class="fl">Ajusta el feeling</label>
      <div class="slider"><span>Serio</span><input type="range" name="tono" min="0" max="100" value="50"><span>Alegre</span></div>
      <div class="slider"><span>Clásico</span><input type="range" name="epoca" min="0" max="100" value="50"><span>Moderno</span></div>
      <div class="slider"><span>Simple</span><input type="range" name="detalle" min="0" max="100" value="50"><span>Detallado</span></div>

      <label class="fl">Tipografía</label>
      <div class="chips">
        <?php
        $tipos = ['Auto'=>'', 'Moderna'=>'sans-serif moderna', 'Clásica'=>'serif clásica', 'Redonda'=>'redondeada y amigable', 'Manuscrita'=>'manuscrita / script', 'Negrita'=>'bold fuerte e impactante'];
        foreach ($tipos as $lb=>$val): ?>
          <label class="chip-opt"><input type="radio" name="tipografia" value="<?= $h($val) ?>" <?= $lb==='Auto'?'checked':'' ?>><span><?= $h($lb) ?></span></label>
        <?php endforeach; ?>
      </div>

      <label class="fl">Algo más (opcional)</label>
      <textarea name="instrucciones" rows="2" placeholder="&quot;ponle un coquí&quot;, &quot;colores azul y dorado&quot;, &quot;que parezca sello&quot;…"></textarea>

      <button class="genbtn" type="submit" style="margin-top:6px"><?= $usados ? 'Generar otro logo' : 'Generar mi primer logo' ?></button>
    </form>
    <div class="genline">Te quedan <b style="color:var(--terracota)"><?= $restantes ?> de <?= $LIMITE_LOGO ?></b> pruebas incluidas.</div>
    <div class="policy">Después de las <?= $LIMITE_LOGO ?>: intentos adicionales tienen costo, o pide un logo personalizado por un artista gráfico.</div>
  </div>
<?php elseif (!$final && $restantes <= 0): ?>
  <div class="genbox" style="background:var(--amber-bg)">
    <b style="color:var(--amber-ink)">Usaste tus <?= $LIMITE_LOGO ?> pruebas incluidas.</b>
    <div class="genline">Escoge tu favorito abajo. ¿Quieres más opciones? Compra intentos adicionales o pide un logo personalizado por un artista gráfico.</div>
  </div>
<?php endif; ?>

<?php if ($final): ?>
  <div class="ok-banner" style="max-width:620px">Tu logo final está elegido. Descárgalo cuando quieras en los formatos que necesites.</div>
<?php endif; ?>

<!-- GALERÍA -->
<?php if (!$logos): ?>
  <p class="empty-g">El Diseñador todavía no te ha montado un logo. Dale a "Generar mi primer logo" arriba y mete mano.</p>
<?php else: ?>
  <div class="gallery">
    <?php foreach ($logos as $l):
      $es = $l['elegido'];
      $oculto = $final && !$es; // si ya finalizó, atenúa los no elegidos
    ?>
      <div class="tile <?= $es?'sel':'' ?> <?= $oculto?'locked':'' ?>">
        <img class="zoomable" src="<?= $h($l['archivo']) ?>" alt="logo">
        <?php if ($es): ?>
          <div class="badge">✓ Tu logo</div>
        <?php elseif (!$final): ?>
          <form method="post"><input type="hidden" name="accion" value="elegir"><input type="hidden" name="logo_id" value="<?= $l['id'] ?>">
            <button class="pick" type="submit">Elegir este</button></form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- DESCARGA DEL ELEGIDO -->
<?php if ($elegido): ?>
  <div class="chosen">
    <h3>⬇ Descargar tu logo<?= $final?' final':'' ?></h3>
    <img id="logoimg" src="<?= $h($elegido['archivo']) ?>" style="display:none">
    <div class="dl">
      <button type="button" onclick="dlLogo('png')">PNG</button>
      <button type="button" onclick="dlLogo('jpeg')">JPG</button>
      <button type="button" onclick="dlLogo('webp')">WebP</button>
      <button type="button" onclick="dlLogo('png',400)">Perfil 400px</button>
    </div>
    <?php if (!$final): ?>
      <div class="warn">⚠️ Al descargar, <b>este será tu logo final</b> y ya no podrás escoger otro. Descárgalo en todos los formatos que quieras.</div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<script>
  var _locked = <?= $final ? 'true' : 'false' ?>;
  function dlLogo(fmt, size){
    var img = new Image();
    img.onload = function(){
      var w = img.naturalWidth || 1024, h = img.naturalHeight || 1024;
      if (size){ var sc = size / Math.max(w,h); w = Math.round(w*sc); h = Math.round(h*sc); }
      var c = document.createElement('canvas'); c.width = w; c.height = h;
      var ctx = c.getContext('2d');
      if (fmt === 'jpeg'){ ctx.fillStyle = '#fff'; ctx.fillRect(0,0,w,h); }
      ctx.drawImage(img, 0, 0, w, h);
      var mime = fmt==='jpeg'?'image/jpeg':(fmt==='webp'?'image/webp':'image/png');
      c.toBlob(function(b){
        var a = document.createElement('a'); a.href = URL.createObjectURL(b);
        a.download = 'logo-<?= $h($marca['slug']) ?>' + (size?('-'+size):'') + '.' + (fmt==='jpeg'?'jpg':fmt);
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(function(){ URL.revokeObjectURL(a.href); }, 2000);
      }, mime, 0.95);
      // Primera descarga = finaliza la elección (bloquea los demás)
      if (!_locked){
        _locked = true;
        fetch('?marca=<?= $marca_id ?>', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'accion=bloquear'});
        document.querySelectorAll('.tile .pick').forEach(function(b){b.style.display='none';});
        document.querySelectorAll('.genbox').forEach(function(b){b.style.display='none';});
        document.querySelectorAll('.tile:not(.sel)').forEach(function(t){t.classList.add('locked');});
        var w2=document.querySelector('.warn'); if(w2) w2.textContent='🔒 Logo finalizado. Puedes seguir descargándolo en otros formatos.';
      }
    };
    img.src = document.getElementById('logoimg').src;
  }
</script>

<?php require __DIR__ . '/_shell_foot.php'; ?>
