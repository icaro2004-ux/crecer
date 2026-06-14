<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Calendario unificado (vista mes)
//  panel/calendario.php  ·  contenido + órdenes/citas, filtros,
//  preview al tocar, navegación por mes, arrastrar para mover.
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
requiere_login();
$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/intake.php'); exit; }
$marca_id = (int)$marca['id'];

// Mes a mostrar (default: mes del calendario más reciente, o el actual)
$calrec = $pdo->prepare("SELECT anio, mes FROM crecer_calendario WHERE marca_id=? ORDER BY anio DESC, mes DESC LIMIT 1");
$calrec->execute([$marca_id]); $calrec = $calrec->fetch();
$anio = (int)($_GET['anio'] ?? ($calrec['anio'] ?? date('Y')));
$mes  = (int)($_GET['mes']  ?? ($calrec['mes']  ?? date('n')));
if ($mes < 1) { $mes = 12; $anio--; } if ($mes > 12) { $mes = 1; $anio++; }

// POST: mover un evento a otro día
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'mover') {
    $id = (int)($_POST['id'] ?? 0); $dia = (int)($_POST['dia'] ?? 0); $tipo = $_POST['tipo'] ?? '';
    $nueva = sprintf('%04d-%02d-%02d', $anio, $mes, max(1,min(31,$dia)));
    if ($id && $dia) {
        if ($tipo === 'orden')
            $pdo->prepare("UPDATE crecer_ordenes SET fecha_entrega=CONCAT(?,' ',COALESCE(TIME(fecha_entrega),'10:00:00')), updated_at=NOW() WHERE id=? AND marca_id=?")->execute([$nueva,$id,$marca_id]);
        else
            $pdo->prepare("UPDATE crecer_contenido SET fecha_programada=CONCAT(?,' ',COALESCE(TIME(fecha_programada),'10:00:00')), updated_at=NOW() WHERE id=? AND marca_id=?")->execute([$nueva,$id,$marca_id]);
    }
    if (!empty($_POST['ajax'])) { header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit; }
    header('Location: '.$_SERVER['REQUEST_URI']); exit;
}

// Eventos del mes: contenido + órdenes
$eventos = [];
$c = $pdo->prepare("SELECT id, plataforma, tipo, estado, caption, grafica_path, DAY(fecha_programada) d FROM crecer_contenido WHERE marca_id=? AND YEAR(fecha_programada)=? AND MONTH(fecha_programada)=?");
$c->execute([$marca_id,$anio,$mes]);
foreach ($c->fetchAll() as $p) $eventos[(int)$p['d']][] = ['tipo'=>'contenido']+$p;
$o = $pdo->prepare("SELECT id, cliente_nombre, descripcion, monto, estado, DAY(fecha_entrega) d, TIME_FORMAT(fecha_entrega,'%H:%i') hora FROM crecer_ordenes WHERE marca_id=? AND fecha_entrega IS NOT NULL AND YEAR(fecha_entrega)=? AND MONTH(fecha_entrega)=?");
$o->execute([$marca_id,$anio,$mes]);
foreach ($o->fetchAll() as $r) $eventos[(int)$r['d']][] = ['tipo'=>'orden']+$r;

$meses = [1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$emoji_plat = ['instagram'=>'📸','facebook'=>'👍','whatsapp'=>'💬'];
$est_col = ['borrador'=>'#9A6A0E','aprobado'=>'#0F7A45','rechazado'=>'#C23A2E','publicado'=>'#0A7886',
            'recibida'=>'#9A6A0E','en_proceso'=>'#0A7886','completada'=>'#0F7A45','cancelada'=>'#C23A2E'];
$primerDia = (int)date('N', strtotime("$anio-$mes-01"));
$diasMes   = (int)date('t', strtotime("$anio-$mes-01"));
$prevM = $mes-1; $prevA=$anio; if($prevM<1){$prevM=12;$prevA--;}
$nextM = $mes+1; $nextA=$anio; if($nextM>12){$nextM=1;$nextA++;}
$hoy_d = ((int)date('Y')===$anio && (int)date('n')===$mes) ? (int)date('j') : 0;

$active = 'contenido';
$page_title = 'Calendario';
require __DIR__ . '/_shell.php';
?>
<style>
  .viewtoggle{display:flex;gap:6px;margin:6px 0 12px}
  .vt{font-weight:700;font-size:13.5px;text-decoration:none;color:var(--muted);padding:8px 16px;border-radius:99px;border:1.5px solid var(--line)}
  .vt.on{color:#fff;background:linear-gradient(135deg,var(--coral),var(--magenta));border-color:transparent}
  .calbar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px}
  .calbar .nav{display:flex;align-items:center;gap:6px}
  .calbar .nav a{width:34px;height:34px;display:grid;place-items:center;border:1.5px solid var(--line);border-radius:10px;text-decoration:none;color:var(--tinta);font-weight:800}
  .calbar .mtitle{font-family:var(--font-display);font-weight:800;font-size:20px;min-width:170px}
  .calbar .today{font-size:13px;font-weight:700;color:var(--terracota);text-decoration:none}
  .filtros{display:flex;gap:6px;margin-left:auto;flex-wrap:wrap}
  .ft{font-weight:700;font-size:12.5px;cursor:pointer;color:var(--muted);padding:6px 13px;border-radius:99px;border:1.5px solid var(--line);background:#fff}
  .ft.on{color:#fff;background:var(--tinta);border-color:transparent}
  .ics{font-size:12.5px;font-weight:700;color:var(--teal);text-decoration:none;border:1.5px solid var(--line);padding:6px 13px;border-radius:99px}

  .calwrap{overflow-x:auto;padding-bottom:8px}
  .cal{display:grid;grid-template-columns:repeat(7,1fr);gap:6px;min-width:720px}
  .dow{font-size:11px;font-weight:800;color:var(--muted);text-transform:uppercase;text-align:center;padding:4px}
  .cell{background:var(--card);border:1px solid var(--line);border-radius:12px;min-height:104px;padding:6px;transition:background .12s}
  .cell.empty{background:transparent;border:0}
  .cell.over{background:var(--okk-bg);border-color:var(--palma)}
  .cell.hoy{border-color:var(--terracota);box-shadow:0 0 0 1.5px var(--terracota) inset}
  .dnum{font-size:11px;font-weight:800;color:var(--muted)}
  .ev{margin-top:4px;border-radius:8px;padding:4px 6px;font-size:11px;font-weight:600;cursor:pointer;color:#fff;
    display:flex;gap:4px;align-items:center;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
  .ev[draggable=true]:active{cursor:grabbing}
  .hint{font-size:12.5px;color:var(--muted);margin-top:12px}
  .empty-c{color:var(--muted);font-size:15px;margin-top:20px}

  /* modal preview */
  .ev-ov{display:none;position:fixed;inset:0;background:rgba(20,12,8,.7);z-index:90;align-items:flex-start;justify-content:center;padding:30px 16px;overflow:auto}
  .ev-ov.show{display:flex}
  .ev-box{background:var(--card);border-radius:var(--r-xl);max-width:400px;width:100%;padding:20px;position:relative}
  .ev-box .x{position:absolute;top:12px;right:14px;border:0;background:none;font-size:20px;cursor:pointer;color:var(--muted)}
  .ev-box img{width:100%;border-radius:14px;display:block;margin-bottom:12px}
  .ev-box h3{font-family:var(--font-display);font-weight:800;font-size:18px;margin-bottom:6px}
  .ev-box .cap{font-size:14px;line-height:1.5;white-space:pre-wrap;color:#3a2f26}
  .ev-box .kv{font-size:14px;margin:4px 0}.ev-box .kv b{color:var(--muted)}
  .ev-box .go{display:inline-block;margin-top:14px;font-weight:800;color:var(--terracota);text-decoration:none}
</style>

<h1 class="page-h">Contenido</h1>
<div class="viewtoggle">
  <a class="vt" href="/crecer/panel/aprobar2.php?marca=<?= $marca_id ?>">📋 Lista</a>
  <a class="vt on" href="/crecer/panel/calendario.php?marca=<?= $marca_id ?>">📅 Calendario</a>
</div>

<div class="calbar">
  <div class="nav">
    <a href="?marca=<?= $marca_id ?>&anio=<?= $prevA ?>&mes=<?= $prevM ?>">‹</a>
    <a href="?marca=<?= $marca_id ?>&anio=<?= $nextA ?>&mes=<?= $nextM ?>">›</a>
  </div>
  <div class="mtitle"><?= $meses[$mes] ?> <?= $anio ?></div>
  <a class="today" href="?marca=<?= $marca_id ?>&anio=<?= date('Y') ?>&mes=<?= date('n') ?>">Hoy</a>
  <div class="filtros">
    <span class="ft on" data-f="todo">Todo</span>
    <span class="ft" data-f="contenido">📸 Contenido</span>
    <span class="ft" data-f="orden">📦 Órdenes</span>
    <a class="ics" href="/crecer/panel/calendario_ics.php?marca=<?= $marca_id ?>">⤵ Exportar (.ics)</a>
  </div>
</div>

<div class="calwrap">
  <div class="cal">
    <?php foreach (['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'] as $d): ?><div class="dow"><?= $d ?></div><?php endforeach; ?>
    <?php for ($i=1; $i<$primerDia; $i++): ?><div class="cell empty"></div><?php endfor; ?>
    <?php for ($d=1; $d<=$diasMes; $d++): ?>
      <div class="cell <?= $d===$hoy_d?'hoy':'' ?>" data-dia="<?= $d ?>">
        <div class="dnum"><?= $d ?></div>
        <?php foreach ($eventos[$d] ?? [] as $ev):
          if ($ev['tipo']==='contenido') {
            $col=$est_col[$ev['estado']]??'#888'; $ic=$emoji_plat[$ev['plataforma']]??'•';
            $titulo=mb_substr($ev['caption'] ?: $ev['tipo'],0,16);
            $data='data-tipo="contenido" data-cap="'.$h($ev['caption']).'" data-img="'.$h($ev['grafica_path']??'').'" data-meta="'.$h(ucfirst($ev['plataforma']).' · '.$ev['estado']).'"';
          } else {
            $col=$est_col[$ev['estado']]??'#0A7886'; $ic='📦';
            $titulo=mb_substr($ev['cliente_nombre'],0,16);
            $data='data-tipo="orden" data-cliente="'.$h($ev['cliente_nombre']).'" data-desc="'.$h($ev['descripcion']??'').'" data-monto="'.$h($ev['monto']??'').'" data-estado="'.$h($ev['estado']).'" data-hora="'.$h($ev['hora']??'').'"';
          }
        ?>
          <div class="ev ev-<?= $ev['tipo'] ?>" draggable="true" data-id="<?= $ev['id'] ?>" <?= $data ?>
               style="background:<?= $col ?>" title="<?= $h($ev['tipo']==='contenido'?($ev['caption']??''):$ev['cliente_nombre']) ?>">
            <?= $ic ?> <?= $h($titulo) ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endfor; ?>
  </div>
</div>
<p class="hint">🖱️ Arrastra un evento a otro día para reprogramarlo. Tócalo para ver el detalle. Filtra arriba por tipo.</p>

<div class="ev-ov" id="evov"><div class="ev-box" id="evbox"></div></div>

<script>
  // Filtros
  document.querySelectorAll('.ft').forEach(function(b){
    b.addEventListener('click', function(){
      document.querySelectorAll('.ft').forEach(function(x){x.classList.remove('on');}); b.classList.add('on');
      var f=b.dataset.f;
      document.querySelectorAll('.ev').forEach(function(e){
        e.style.display = (f==='todo' || e.classList.contains('ev-'+f)) ? '' : 'none';
      });
    });
  });
  // Drag para mover
  var dragId=null, dragTipo=null;
  document.querySelectorAll('.ev').forEach(function(c){
    c.addEventListener('dragstart', function(e){ dragId=c.dataset.id; dragTipo=c.dataset.tipo; e.dataTransfer.effectAllowed='move'; });
  });
  document.querySelectorAll('.cell[data-dia]').forEach(function(cell){
    cell.addEventListener('dragover', function(e){ e.preventDefault(); cell.classList.add('over'); });
    cell.addEventListener('dragleave', function(){ cell.classList.remove('over'); });
    cell.addEventListener('drop', function(e){
      e.preventDefault(); cell.classList.remove('over'); if(!dragId) return;
      var chip=document.querySelector('.ev[data-id="'+dragId+'"][data-tipo="'+dragTipo+'"]'); if(chip) cell.appendChild(chip);
      var fd=new FormData(); fd.append('ajax','1'); fd.append('accion','mover'); fd.append('id',dragId); fd.append('tipo',dragTipo); fd.append('dia',cell.dataset.dia);
      fetch(location.pathname+location.search,{method:'POST',body:fd}); dragId=null;
    });
  });
  // Preview al tocar
  var ov=document.getElementById('evov'), box=document.getElementById('evbox');
  document.querySelectorAll('.ev').forEach(function(c){
    c.addEventListener('click', function(){
      var html='<button class="x" onclick="document.getElementById(\'evov\').classList.remove(\'show\')">✕</button>';
      if(c.dataset.tipo==='contenido'){
        if(c.dataset.img) html+='<img src="'+c.dataset.img+'">';
        html+='<div style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase">'+c.dataset.meta+'</div>';
        html+='<div class="cap">'+ (c.dataset.cap||'(sin caption)') +'</div>';
        html+='<a class="go" href="/crecer/panel/aprobar2.php?marca=<?= $marca_id ?>">Abrir en Lista →</a>';
      } else {
        html+='<h3>📦 '+c.dataset.cliente+'</h3>';
        if(c.dataset.desc) html+='<div class="cap">'+c.dataset.desc+'</div>';
        if(c.dataset.monto) html+='<div class="kv"><b>Monto:</b> $'+c.dataset.monto+'</div>';
        html+='<div class="kv"><b>Estado:</b> '+c.dataset.estado+'</div>';
        if(c.dataset.hora) html+='<div class="kv"><b>Hora:</b> '+c.dataset.hora+'</div>';
        html+='<a class="go" href="/crecer/panel/ordenes.php?marca=<?= $marca_id ?>">Abrir en Órdenes →</a>';
      }
      box.innerHTML=html; ov.classList.add('show');
    });
  });
  ov.addEventListener('click', function(e){ if(e.target===ov) ov.classList.remove('show'); });
</script>

<?php require __DIR__ . '/_shell_foot.php'; ?>
