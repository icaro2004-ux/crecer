<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Mi Marca (galería de logos + escoger)
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

$LIMITE_LOGO = 5;
$cuenta = $pdo->prepare("SELECT COUNT(*) FROM crecer_logos WHERE marca_id=?");
$cuenta->execute([$marca_id]); $usados = (int)$cuenta->fetchColumn();
$restantes = max(0, $LIMITE_LOGO - $usados);
$final = (int)$marca['logo_final'] === 1;

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'logo') {
        if ($final)             $err = 'Tu logo ya está finalizado.';
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

$active = 'marca';
$page_title = 'Mi Marca';
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
</style>

<h1 class="page-h">Mi Marca 🎨</h1>
<p class="subline">Tienes <b><?= $LIMITE_LOGO ?> oportunidades</b> para crear tu logo con IA. Genéralos, compáralos y escoge el que más te guste.</p>
<?php if (!empty($_GET['ok'])): ?><div class="ok-banner">✓ ¡Nuevo logo listo! Mira la galería abajo.</div><?php endif; ?>
<?php if ($err): ?><div class="err-banner">⚠️ <?= $h($err) ?></div><?php endif; ?>

<?php if (!$final && $restantes > 0): ?>
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

      <button class="genbtn" type="submit" style="margin-top:6px"><?= $usados ? '✨ Generar otro logo' : '✨ Generar mi primer logo' ?></button>
    </form>
    <div class="genline">Te quedan <b style="color:var(--terracota)"><?= $restantes ?> de <?= $LIMITE_LOGO ?></b> pruebas incluidas.</div>
    <div class="policy">Después de las <?= $LIMITE_LOGO ?>: intentos adicionales tienen costo, o pide un logo personalizado por un artista gráfico.</div>
  </div>
<?php elseif (!$final && $restantes <= 0): ?>
  <div class="genbox" style="background:var(--amber-bg)">
    <b style="color:var(--amber-ink)">🎨 Usaste tus <?= $LIMITE_LOGO ?> pruebas incluidas.</b>
    <div class="genline">Escoge tu favorito abajo. ¿Quieres más opciones? Compra intentos adicionales o pide un logo personalizado por un artista gráfico.</div>
  </div>
<?php endif; ?>

<?php if ($final): ?>
  <div class="ok-banner" style="max-width:620px">🔒 Tu logo final está elegido. Descárgalo cuando quieras en los formatos que necesites.</div>
<?php endif; ?>

<!-- GALERÍA -->
<?php if (!$logos): ?>
  <p class="empty-g">Aún no has generado ningún logo. Dale a "Generar mi primer logo" arriba. 🎨</p>
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
