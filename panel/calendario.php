<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Calendario de posts (vista mes)
//  panel/calendario.php  ·  arrastra posts entre días
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
requiere_login();
$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/intake.php'); exit; }
$marca_id = (int)$marca['id'];

// Calendario más reciente
$cal = $pdo->prepare("SELECT * FROM crecer_calendario WHERE marca_id=? ORDER BY anio DESC, mes DESC LIMIT 1");
$cal->execute([$marca_id]); $cal = $cal->fetch();

// POST: mover un post a otro día
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'mover') {
    $id = (int)($_POST['id'] ?? 0); $dia = (int)($_POST['dia'] ?? 0);
    if ($id && $dia >= 1 && $dia <= 31 && $cal) {
        $nueva = sprintf('%04d-%02d-%02d', $cal['anio'], $cal['mes'], $dia);
        $pdo->prepare("UPDATE crecer_contenido SET fecha_programada = CONCAT(?, ' ', COALESCE(TIME(fecha_programada),'10:00:00')), updated_at=NOW() WHERE id=? AND marca_id=?")
            ->execute([$nueva, $id, $marca_id]);
    }
    if (!empty($_POST['ajax'])) { header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit; }
    header('Location: ' . $_SERVER['REQUEST_URI']); exit;
}

$porDia = [];
if ($cal) {
    $ps = $pdo->prepare("SELECT id, plataforma, tipo, estado, caption, grafica_path, DAY(fecha_programada) dia FROM crecer_contenido WHERE calendario_id=? ORDER BY fecha_programada");
    $ps->execute([$cal['id']]);
    foreach ($ps->fetchAll() as $p) { $porDia[(int)$p['dia']][] = $p; }
}
$meses = [1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$emoji_plat = ['instagram'=>'📸','facebook'=>'👍','whatsapp'=>'💬'];
$estado_col = ['borrador'=>'#9A6A0E','aprobado'=>'#0F7A45','rechazado'=>'#C23A2E','publicado'=>'#0A7886'];

$primerDia = $cal ? (int)date('N', strtotime("{$cal['anio']}-{$cal['mes']}-01")) : 1; // 1=Lun
$diasMes   = $cal ? (int)date('t', strtotime("{$cal['anio']}-{$cal['mes']}-01")) : 30;

$active = 'contenido';
$page_title = 'Calendario';
require __DIR__ . '/_shell.php';
?>
<style>
  .viewtoggle{display:flex;gap:6px;margin:6px 0 4px}
  .vt{font-weight:700;font-size:13.5px;text-decoration:none;color:var(--muted);padding:8px 16px;border-radius:99px;border:1.5px solid var(--line)}
  .vt.on{color:#fff;background:linear-gradient(135deg,var(--coral),var(--magenta));border-color:transparent}
  .calhead{font-family:var(--font-display);font-weight:800;font-size:20px;margin:16px 0 10px}
  .cal{display:grid;grid-template-columns:repeat(7,1fr);gap:6px;min-width:680px}
  .dow{font-size:11px;font-weight:800;color:var(--muted);text-transform:uppercase;text-align:center;padding:4px}
  .cell{background:var(--card);border:1px solid var(--line);border-radius:12px;min-height:96px;padding:6px;transition:background .12s}
  .cell.empty{background:transparent;border:0}
  .cell.over{background:var(--okk-bg);border-color:var(--palma)}
  .cell .dnum{font-size:11px;font-weight:700;color:var(--muted)}
  .pchip{margin-top:4px;border-radius:8px;padding:4px 6px;font-size:11px;font-weight:600;cursor:grab;
    color:#fff;display:flex;gap:4px;align-items:center;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
  .pchip:active{cursor:grabbing}
  .calwrap{overflow-x:auto;padding-bottom:8px}
  .hint{font-size:12.5px;color:var(--muted);margin-top:12px}
  .empty-c{color:var(--muted);font-size:15px;margin-top:20px}
</style>

<h1 class="page-h">Contenido</h1>
<div class="viewtoggle">
  <a class="vt" href="/crecer/panel/aprobar2.php?marca=<?= $marca_id ?>">📋 Lista</a>
  <a class="vt on" href="/crecer/panel/calendario.php?marca=<?= $marca_id ?>">📅 Calendario</a>
</div>

<?php if (!$cal): ?>
  <p class="empty-c">Aún no hay un calendario. Genera tu contenido en la vista Lista.</p>
<?php else: ?>
  <div class="calhead"><?= $meses[(int)$cal['mes']] ?> <?= (int)$cal['anio'] ?></div>
  <div class="calwrap">
    <div class="cal">
      <?php foreach (['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'] as $d): ?><div class="dow"><?= $d ?></div><?php endforeach; ?>
      <?php for ($i = 1; $i < $primerDia; $i++): ?><div class="cell empty"></div><?php endfor; ?>
      <?php for ($d = 1; $d <= $diasMes; $d++): ?>
        <div class="cell" data-dia="<?= $d ?>">
          <div class="dnum"><?= $d ?></div>
          <?php foreach ($porDia[$d] ?? [] as $p): ?>
            <div class="pchip" draggable="true" data-id="<?= $p['id'] ?>"
                 style="background:<?= $estado_col[$p['estado']] ?? '#888' ?>"
                 title="<?= $h($p['caption']) ?>">
              <?= $emoji_plat[$p['plataforma']] ?? '•' ?> <?= $h(mb_substr($p['caption'] ?: $p['tipo'], 0, 14)) ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endfor; ?>
    </div>
  </div>
  <p class="hint">🖱️ Arrastra un post a otro día para reprogramarlo. Los colores = estado (gris/ámbar pendiente, verde aprobado, rojo rechazado).</p>
<?php endif; ?>

<script>
  var dragId = null;
  document.querySelectorAll('.pchip').forEach(function(c){
    c.addEventListener('dragstart', function(e){ dragId = c.dataset.id; e.dataTransfer.effectAllowed='move'; });
  });
  document.querySelectorAll('.cell[data-dia]').forEach(function(cell){
    cell.addEventListener('dragover', function(e){ e.preventDefault(); cell.classList.add('over'); });
    cell.addEventListener('dragleave', function(){ cell.classList.remove('over'); });
    cell.addEventListener('drop', function(e){
      e.preventDefault(); cell.classList.remove('over');
      if(!dragId) return;
      var chip = document.querySelector('.pchip[data-id="'+dragId+'"]');
      if(chip) cell.appendChild(chip);            // mover visualmente
      var fd = new FormData(); fd.append('ajax','1'); fd.append('accion','mover'); fd.append('id',dragId); fd.append('dia',cell.dataset.dia);
      fetch(location.pathname+location.search,{method:'POST',body:fd});
      dragId = null;
    });
  });
</script>

<?php require __DIR__ . '/_shell_foot.php'; ?>
