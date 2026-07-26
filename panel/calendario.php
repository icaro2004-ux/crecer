<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Calendario unificado
//  panel/calendario.php  ·  contenido + órdenes/eventos.
//  Vistas: Día · Semana · Mes. Filtros, preview al tocar,
//  navegación, arrastrar para mover, export .ics.
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
requiere_login();
$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }
$marca_id = (int)$marca['id'];

// ---- Vista (dia | semana | mes) --------------------------------------------
$vista = $_GET['vista'] ?? 'mes';
if (!in_array($vista, ['dia','semana','mes'], true)) $vista = 'mes';

// ---- Fecha de referencia ----------------------------------------------------
if ($vista === 'mes') {
    // Mes por defecto: el del PRÓXIMO post programado; si no hay, el mes actual.
    $prox = $pdo->prepare("SELECT MIN(fecha_programada) FROM crecer_contenido WHERE marca_id=? AND fecha_programada IS NOT NULL AND fecha_programada >= CURDATE()");
    $prox->execute([$marca_id]); $prox = $prox->fetchColumn();
    $def_anio = $prox ? (int)date('Y', strtotime($prox)) : (int)date('Y');
    $def_mes  = $prox ? (int)date('n', strtotime($prox)) : (int)date('n');
    $anio = (int)($_GET['anio'] ?? $def_anio);
    $mes  = (int)($_GET['mes']  ?? $def_mes);
    if ($mes < 1) { $mes = 12; $anio--; } if ($mes > 12) { $mes = 1; $anio++; }
    $refTs = mktime(0,0,0,$mes,1,$anio);
} else {
    $fechaStr = $_GET['fecha'] ?? date('Y-m-d');
    $refTs = strtotime($fechaStr) ?: time();
    $anio = (int)date('Y',$refTs); $mes = (int)date('n',$refTs);
}
$refDate = date('Y-m-d', $refTs);

// Semana que contiene la referencia (Lun→Dom)
$dow = (int)date('N', $refTs);                        // 1=Lun … 7=Dom
$weekStartTs = strtotime('-'.($dow-1).' day', $refTs);
$weekEndTs   = strtotime('+6 day', $weekStartTs);

// ---- POST: mover / crear / borrar ------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'mover') {
        $id = (int)($_POST['id'] ?? 0); $tipo = $_POST['tipo'] ?? '';
        $fecha = $_POST['fecha'] ?? '';
        if ($id && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            if ($tipo === 'orden')
                $pdo->prepare("UPDATE crecer_ordenes SET fecha_entrega=CONCAT(?,' ',COALESCE(TIME(fecha_entrega),'10:00:00')), updated_at=NOW() WHERE id=? AND marca_id=?")->execute([$fecha,$id,$marca_id]);
            elseif ($tipo === 'evento')
                $pdo->prepare("UPDATE crecer_eventos SET fecha=CONCAT(?,' ',COALESCE(TIME(fecha),'10:00:00')) WHERE id=? AND marca_id=?")->execute([$fecha,$id,$marca_id]);
            else
                $pdo->prepare("UPDATE crecer_contenido SET fecha_programada=CONCAT(?,' ',COALESCE(TIME(fecha_programada),'10:00:00')), updated_at=NOW() WHERE id=? AND marca_id=?")->execute([$fecha,$id,$marca_id]);
        }
    } elseif ($accion === 'crear_evento' && csrf_ok()) {
        $titulo = trim($_POST['titulo'] ?? ''); $f = $_POST['fecha'] ?? ''; $hora = ($_POST['hora'] ?? '') ?: '10:00';
        if ($titulo !== '' && $f !== '') {
            $dt = $f . ' ' . $hora . ':00';
            $pdo->prepare("INSERT INTO crecer_eventos (marca_id, titulo, nota, fecha) VALUES (?,?,?,?)")
                ->execute([$marca_id, $titulo, (trim($_POST['nota'] ?? '') ?: null), $dt]);
            $evd = date('Y-m-d', strtotime($dt));
            if ($vista === 'mes') {
                $ea = (int)date('Y', strtotime($dt)); $em = (int)date('n', strtotime($dt));
                header("Location: /crecer/panel/calendario.php?marca={$marca_id}&vista=mes&anio={$ea}&mes={$em}"); exit;
            }
            header("Location: /crecer/panel/calendario.php?marca={$marca_id}&vista={$vista}&fecha={$evd}"); exit;
        }
    } elseif ($accion === 'borrar_evento' && csrf_ok()) {
        $pdo->prepare("DELETE FROM crecer_eventos WHERE id=? AND marca_id=?")->execute([(int)($_POST['id'] ?? 0), $marca_id]);
        if ($vista === 'mes') { header("Location: /crecer/panel/calendario.php?marca={$marca_id}&vista=mes&anio={$anio}&mes={$mes}"); exit; }
        header("Location: /crecer/panel/calendario.php?marca={$marca_id}&vista={$vista}&fecha={$refDate}"); exit;
    }
    if (!empty($_POST['ajax'])) { header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit; }
    header('Location: '.$_SERVER['REQUEST_URI']); exit;
}

// ---- Rango de datos según la vista -----------------------------------------
if ($vista === 'mes') {
    $rangoIni = sprintf('%04d-%02d-01', $anio, $mes);
    $rangoFin = date('Y-m-t', $refTs);
} elseif ($vista === 'semana') {
    $rangoIni = date('Y-m-d', $weekStartTs);
    $rangoFin = date('Y-m-d', $weekEndTs);
} else {
    $rangoIni = $rangoFin = $refDate;
}

// Eventos del rango, agrupados por fecha 'Y-m-d'
$eventos = [];
$c = $pdo->prepare("SELECT id, plataforma, tipo, estado, caption, grafica_path, DATE_FORMAT(fecha_programada,'%Y-%m-%d') fk, TIME_FORMAT(fecha_programada,'%H:%i') hora FROM crecer_contenido WHERE marca_id=? AND DATE(fecha_programada) BETWEEN ? AND ?");
$c->execute([$marca_id,$rangoIni,$rangoFin]);
foreach ($c->fetchAll() as $p) $eventos[$p['fk']][] = ['tipo'=>'contenido']+$p;
$o = $pdo->prepare("SELECT id, cliente_nombre, descripcion, monto, estado, DATE_FORMAT(fecha_entrega,'%Y-%m-%d') fk, TIME_FORMAT(fecha_entrega,'%H:%i') hora FROM crecer_ordenes WHERE marca_id=? AND fecha_entrega IS NOT NULL AND DATE(fecha_entrega) BETWEEN ? AND ?");
$o->execute([$marca_id,$rangoIni,$rangoFin]);
foreach ($o->fetchAll() as $r) $eventos[$r['fk']][] = ['tipo'=>'orden']+$r;
$e = $pdo->prepare("SELECT id, titulo, nota, DATE_FORMAT(fecha,'%Y-%m-%d') fk, TIME_FORMAT(fecha,'%H:%i') hora FROM crecer_eventos WHERE marca_id=? AND DATE(fecha) BETWEEN ? AND ?");
$e->execute([$marca_id,$rangoIni,$rangoFin]);
foreach ($e->fetchAll() as $r) $eventos[$r['fk']][] = ['tipo'=>'evento']+$r;

$meses = [1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$sm    = [1=>'Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
$diasSem = [1=>'Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'];
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$est_col =['borrador'=>'#9A6A0E','aprobado'=>'#0F7A45','rechazado'=>'#C23A2E','publicado'=>'#0A7886',
            'recibida'=>'#9A6A0E','en_proceso'=>'#0A7886','completada'=>'#0F7A45','cancelada'=>'#C23A2E'];

// Chip de evento reutilizable (todas las vistas)
$chip = function($ev, $showHora=false) use ($h,$est_col) {
    if ($ev['tipo']==='contenido') {
        $col=$est_col[$ev['estado']]??'#888';
        $titulo=mb_substr($ev['caption'] ?: 'Contenido',0,22);
        $data='data-tipo="contenido" data-cap="'.$h($ev['caption']).'" data-img="'.$h($ev['grafica_path']??'').'" data-meta="'.$h(ucfirst($ev['plataforma']).' · '.$ev['estado']).'"';
        $tt=$ev['caption']??'';
    } elseif ($ev['tipo']==='orden') {
        $col=$est_col[$ev['estado']]??'#0A7886';
        $titulo=mb_substr($ev['cliente_nombre'],0,22);
        $data='data-tipo="orden" data-cliente="'.$h($ev['cliente_nombre']).'" data-desc="'.$h($ev['descripcion']??'').'" data-monto="'.$h($ev['monto']??'').'" data-estado="'.$h($ev['estado']).'" data-hora="'.$h($ev['hora']??'').'"';
        $tt=$ev['cliente_nombre'];
    } else { // evento propio
        $col='#7A4FB5';
        $titulo=mb_substr($ev['titulo'],0,22);
        $data='data-tipo="evento" data-titulo="'.$h($ev['titulo']).'" data-nota="'.$h($ev['nota']??'').'" data-hora="'.$h($ev['hora']??'').'"';
        $tt=$ev['titulo'];
    }
    $pref = ($showHora && !empty($ev['hora'])) ? '<span class="evh">'.$h($ev['hora']).'</span> ' : '';
    return '<div class="ev ev-'.$ev['tipo'].'" draggable="true" data-id="'.$ev['id'].'" '.$data.' style="background:'.$col.'" title="'.$h($tt).'">'.$pref.$h($titulo).'</div>';
};

// ---- Navegación / título / sub-toggle --------------------------------------
if ($vista === 'mes') {
    $prevM=$mes-1; $prevA=$anio; if($prevM<1){$prevM=12;$prevA--;}
    $nextM=$mes+1; $nextA=$anio; if($nextM>12){$nextM=1;$nextA++;}
    $prevHref="?marca={$marca_id}&vista=mes&anio={$prevA}&mes={$prevM}";
    $nextHref="?marca={$marca_id}&vista=mes&anio={$nextA}&mes={$nextM}";
    $hoyHref ="?marca={$marca_id}&vista=mes&anio=".date('Y')."&mes=".date('n');
    $titulo  = $meses[$mes].' '.$anio;
} elseif ($vista === 'semana') {
    $prevHref="?marca={$marca_id}&vista=semana&fecha=".date('Y-m-d', strtotime('-7 day',$weekStartTs));
    $nextHref="?marca={$marca_id}&vista=semana&fecha=".date('Y-m-d', strtotime('+7 day',$weekStartTs));
    $hoyHref ="?marca={$marca_id}&vista=semana&fecha=".date('Y-m-d');
    $wsD=(int)date('j',$weekStartTs); $wsM=(int)date('n',$weekStartTs); $wsY=(int)date('Y',$weekStartTs);
    $weD=(int)date('j',$weekEndTs);   $weM=(int)date('n',$weekEndTs);   $weY=(int)date('Y',$weekEndTs);
    if ($wsM===$weM)      $titulo="$wsD – $weD {$sm[$wsM]} $wsY";
    elseif ($wsY===$weY)  $titulo="$wsD {$sm[$wsM]} – $weD {$sm[$weM]} $wsY";
    else                  $titulo="$wsD {$sm[$wsM]} $wsY – $weD {$sm[$weM]} $weY";
} else { // dia
    $prevHref="?marca={$marca_id}&vista=dia&fecha=".date('Y-m-d', strtotime('-1 day',$refTs));
    $nextHref="?marca={$marca_id}&vista=dia&fecha=".date('Y-m-d', strtotime('+1 day',$refTs));
    $hoyHref ="?marca={$marca_id}&vista=dia&fecha=".date('Y-m-d');
    $titulo  = $diasSem[(int)date('N',$refTs)].' '.(int)date('j',$refTs).' de '.mb_strtolower($meses[(int)date('n',$refTs)],'UTF-8');
}
// Sub-toggle: conserva el punto de referencia al cambiar de vista
$sv_mes="?marca={$marca_id}&vista=mes&anio=".date('Y',$refTs)."&mes=".date('n',$refTs);
$sv_sem="?marca={$marca_id}&vista=semana&fecha={$refDate}";
$sv_dia="?marca={$marca_id}&vista=dia&fecha={$refDate}";

// Icono base (para add / abrirAdd)
$primerDia = (int)date('N', strtotime("$anio-$mes-01"));
$diasMes   = (int)date('t', strtotime("$anio-$mes-01"));
$hoyStr    = date('Y-m-d');

$active = 'calendario';
$page_title = 'Calendario';
require __DIR__ . '/_shell.php';

$icoDia='<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="4" y="4" width="16" height="16" rx="2.5"/></svg>';
$icoSem='<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="4" y="4" width="16" height="16" rx="2.5"/><line x1="9.3" y1="4" x2="9.3" y2="20"/><line x1="14.7" y1="4" x2="14.7" y2="20"/></svg>';
$icoMes='<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="4" y="4" width="16" height="16" rx="2.5"/><line x1="4" y1="9.5" x2="20" y2="9.5"/><line x1="9.3" y1="9.5" x2="9.3" y2="20"/><line x1="14.7" y1="9.5" x2="14.7" y2="20"/></svg>';
?>
<style>
  .viewtoggle{display:flex;gap:6px;margin:6px 0 12px}
  .vt{font-weight:700;font-size:13.5px;text-decoration:none;color:var(--muted);padding:8px 16px;border-radius:99px;border:1.5px solid var(--line)}
  .vt.on{color:#fff;background:linear-gradient(135deg,var(--coral),var(--magenta));border-color:transparent}

  .subviews{display:flex;gap:6px;margin-bottom:12px}
  .sv{font-weight:800;font-size:13px;text-decoration:none;color:var(--muted);padding:7px 15px;border-radius:99px;border:1.5px solid var(--line);background:#fff;display:inline-flex;align-items:center;gap:6px}
  .sv svg{opacity:.75}
  .sv.on{color:#fff;background:linear-gradient(135deg,var(--coral),var(--magenta));border-color:transparent}
  .sv.on svg{opacity:1}

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
  .ev .evh{font-weight:800;opacity:.9}
  .ev[draggable=true]:active{cursor:grabbing}
  .hint{font-size:12.5px;color:var(--muted);margin-top:12px}

  /* Vista semana */
  .cal.week .cell{min-height:340px;overflow:auto}
  .cal.week .dhead{display:flex;flex-direction:column;line-height:1.05;margin-bottom:8px}
  .cal.week .dhead .wd{font-size:10.5px;font-weight:800;color:var(--muted);text-transform:uppercase}
  .cal.week .dhead .dn{font-size:20px;font-weight:800;font-family:var(--font-display);color:var(--tinta)}
  .cal.week .cell.hoy .dhead .dn{color:var(--terracota)}
  .cal.week .ev{white-space:normal;font-size:11.5px;padding:6px 8px}

  /* Vista día */
  .dayagenda{max-width:640px}
  .dayagenda .row{display:flex;gap:14px;align-items:flex-start;padding:12px 2px;border-bottom:1px solid var(--line)}
  .dayagenda .row:last-child{border-bottom:0}
  .dayagenda .tcol{width:58px;flex:0 0 58px;font-weight:800;color:var(--muted);font-size:13px;padding-top:9px;text-align:right}
  .dayagenda .ev{margin-top:0;flex:1;white-space:normal;font-size:14.5px;padding:11px 15px;border-radius:12px}
  .dayagenda .empty-day{color:var(--muted);font-size:15px;padding:40px 0;text-align:center}
  .dayagenda .empty-day .addbtn{margin-top:14px}

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
  .ev-box .del{margin-top:14px;border:1.5px solid var(--noo-bg);background:#fff;color:var(--noo-ink);font-family:inherit;font-weight:700;font-size:13px;cursor:pointer;border-radius:99px;padding:8px 16px}
  .addbtn{border:0;cursor:pointer;font-family:inherit;font-weight:800;font-size:12.5px;color:#fff;background:linear-gradient(135deg,var(--coral),var(--magenta));padding:7px 14px;border-radius:99px}
  .add-ov{display:none;position:fixed;inset:0;background:rgba(20,12,8,.7);z-index:95;align-items:flex-start;justify-content:center;padding:40px 16px;overflow:auto}
  .add-ov.show{display:flex}
  .add-box{background:var(--card);border-radius:var(--r-xl);max-width:400px;width:100%;padding:24px;position:relative}
  .add-box h3{font-family:var(--font-display);font-weight:800;font-size:20px;margin-bottom:4px}
  .add-box label{display:block;font-weight:700;font-size:13px;margin:13px 0 6px}
  .add-box input,.add-box textarea{width:100%;font-family:inherit;font-size:15px;border:1.5px solid var(--line);border-radius:12px;padding:11px 13px}
  .add-box .r2{display:flex;gap:12px}.add-box .r2>div{flex:1}
  .add-box .save{margin-top:18px;width:100%;border:0;cursor:pointer;background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff;font-weight:800;font-size:15px;padding:13px;border-radius:99px}
  .add-box .x{position:absolute;top:12px;right:14px;border:0;background:none;font-size:20px;cursor:pointer;color:var(--muted)}
</style>

<h1 class="page-h">Tus Posts</h1>
<div class="viewtoggle">
  <a class="vt" href="/crecer/panel/aprobar2.php?marca=<?= $marca_id ?>"><?= ico('list') ?> Lista</a>
  <a class="vt on" href="/crecer/panel/calendario.php?marca=<?= $marca_id ?>"><?= ico('calendar') ?> Calendario</a>
</div>

<div class="subviews">
  <a class="sv <?= $vista==='dia'?'on':'' ?>" href="<?= $sv_dia ?>"><?= $icoDia ?> Día</a>
  <a class="sv <?= $vista==='semana'?'on':'' ?>" href="<?= $sv_sem ?>"><?= $icoSem ?> Semana</a>
  <a class="sv <?= $vista==='mes'?'on':'' ?>" href="<?= $sv_mes ?>"><?= $icoMes ?> Mes</a>
</div>

<div class="calbar">
  <div class="nav">
    <a href="<?= $prevHref ?>">‹</a>
    <a href="<?= $nextHref ?>">›</a>
  </div>
  <div class="mtitle"><?= $h($titulo) ?></div>
  <a class="today" href="<?= $hoyHref ?>">Hoy</a>
  <div class="filtros">
    <span class="ft on" data-f="todo">Todo</span>
    <span class="ft" data-f="contenido"><?= ico('camera') ?> Contenido</span>
    <span class="ft" data-f="orden"><?= ico('package') ?> Órdenes</span>
    <span class="ft" data-f="evento"><?= ico('pin') ?> Eventos</span>
    <button type="button" class="addbtn" onclick="abrirAdd('')"><?= ico('plus') ?> Evento</button>
    <a class="ics" href="/crecer/panel/calendario_ics.php?marca=<?= $marca_id ?>">⤵ Exportar (.ics)</a>
  </div>
</div>

<?php if ($vista === 'mes'): ?>
<div class="calwrap">
  <div class="cal">
    <?php foreach (['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'] as $d): ?><div class="dow"><?= $d ?></div><?php endforeach; ?>
    <?php for ($i=1; $i<$primerDia; $i++): ?><div class="cell empty"></div><?php endfor; ?>
    <?php for ($d=1; $d<=$diasMes; $d++):
      $fk = sprintf('%04d-%02d-%02d', $anio, $mes, $d); ?>
      <div class="cell <?= $fk===$hoyStr?'hoy':'' ?>" data-fecha="<?= $fk ?>">
        <div class="dnum"><?= $d ?></div>
        <?php foreach ($eventos[$fk] ?? [] as $ev) echo $chip($ev); ?>
      </div>
    <?php endfor; ?>
  </div>
</div>
<p class="hint">Arrastra un evento a otro día para reprogramarlo. Tócalo para ver el detalle. Filtra arriba por tipo.</p>

<?php elseif ($vista === 'semana'): ?>
<div class="calwrap">
  <div class="cal week">
    <?php for ($i=0; $i<7; $i++):
      $ts = strtotime("+$i day", $weekStartTs); $fk = date('Y-m-d', $ts);
      $lista = $eventos[$fk] ?? [];
      usort($lista, fn($a,$b)=>strcmp($a['hora']??'',$b['hora']??'')); ?>
      <div class="cell <?= $fk===$hoyStr?'hoy':'' ?>" data-fecha="<?= $fk ?>">
        <div class="dhead"><span class="wd"><?= ['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'][$i] ?></span><span class="dn"><?= (int)date('j',$ts) ?></span></div>
        <?php foreach ($lista as $ev) echo $chip($ev, true); ?>
      </div>
    <?php endfor; ?>
  </div>
</div>
<p class="hint">Arrastra entre días para reprogramar. Toca un evento para el detalle, o un día vacío para agendar.</p>

<?php else: // dia
  $lista = $eventos[$refDate] ?? [];
  usort($lista, fn($a,$b)=>strcmp($a['hora']??'',$b['hora']??'')); ?>
<div class="dayagenda" data-fecha="<?= $refDate ?>">
  <?php if (!$lista): ?>
    <div class="empty-day">
      Nada agendado para este día.<br>
      <button type="button" class="addbtn" onclick="abrirAdd('<?= $refDate ?>')"><?= ico('plus') ?> Agendar algo</button>
    </div>
  <?php else: foreach ($lista as $ev): ?>
    <div class="row">
      <div class="tcol"><?= $ev['hora'] ? $h($ev['hora']) : '—' ?></div>
      <?= $chip($ev) ?>
    </div>
  <?php endforeach; endif; ?>
</div>
<p class="hint">Toca un evento para ver el detalle. Usa “+ Evento” para agendar en este día.</p>
<?php endif; ?>

<div class="ev-ov" id="evov"><div class="ev-box" id="evbox"></div></div>

<div class="add-ov" id="addov">
  <form class="add-box" method="post" action="">
    <button type="button" class="x" onclick="document.getElementById('addov').classList.remove('show')">✕</button>
    <h3><?= ico('pin') ?> Nuevo evento</h3>
    <div style="font-size:13px;color:var(--muted)">Tu agenda de trabajo — citas, recados, reuniones. Se sincroniza con Outlook.</div>
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="crear_evento">
    <label>¿Qué vas a hacer?</label>
    <input type="text" name="titulo" placeholder="Ej: Cita con el contable" required maxlength="160">
    <div class="r2">
      <div><label>Fecha</label><input type="date" name="fecha" id="ev-fecha" required></div>
      <div><label>Hora</label><input type="time" name="hora" id="ev-hora" value="10:00"></div>
    </div>
    <label>Nota (opcional)</label>
    <textarea name="nota" rows="2" placeholder="Llevar facturas del mes, recibos…"></textarea>
    <button type="submit" class="save">Guardar en mi agenda</button>
  </form>
</div>

<script>
  var REFDATE = '<?= $refDate ?>';
  function abrirAdd(fecha){
    var f=document.getElementById('ev-fecha');
    f.value = fecha || REFDATE;
    document.getElementById('addov').classList.add('show');
  }
  document.getElementById('addov').addEventListener('click', function(e){ if(e.target===this) this.classList.remove('show'); });
  // Filtros
  document.querySelectorAll('.ft').forEach(function(b){
    b.addEventListener('click', function(){
      document.querySelectorAll('.ft').forEach(function(x){x.classList.remove('on');}); b.classList.add('on');
      var f=b.dataset.f;
      document.querySelectorAll('.ev').forEach(function(e){
        var row = e.closest('.dayagenda .row');
        var show = (f==='todo' || e.classList.contains('ev-'+f));
        (row||e).style.display = show ? '' : 'none';
      });
    });
  });
  // Drag para mover
  var dragId=null, dragTipo=null;
  document.querySelectorAll('.ev').forEach(function(c){
    c.addEventListener('dragstart', function(e){ dragId=c.dataset.id; dragTipo=c.dataset.tipo; e.dataTransfer.effectAllowed='move'; });
  });
  document.querySelectorAll('.cell[data-fecha]').forEach(function(cell){
    cell.addEventListener('dragover', function(e){ e.preventDefault(); cell.classList.add('over'); });
    cell.addEventListener('dragleave', function(){ cell.classList.remove('over'); });
    cell.addEventListener('drop', function(e){
      e.preventDefault(); cell.classList.remove('over'); if(!dragId) return;
      var chip=document.querySelector('.ev[data-id="'+dragId+'"][data-tipo="'+dragTipo+'"]'); if(chip) cell.appendChild(chip);
      var fd=new FormData(); fd.append('ajax','1'); fd.append('accion','mover'); fd.append('id',dragId); fd.append('tipo',dragTipo); fd.append('fecha',cell.dataset.fecha);
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
      } else if(c.dataset.tipo==='orden'){
        html+='<h3>'+c.dataset.cliente+'</h3>';
        if(c.dataset.desc) html+='<div class="cap">'+c.dataset.desc+'</div>';
        if(c.dataset.monto) html+='<div class="kv"><b>Monto:</b> $'+c.dataset.monto+'</div>';
        html+='<div class="kv"><b>Estado:</b> '+c.dataset.estado+'</div>';
        if(c.dataset.hora) html+='<div class="kv"><b>Hora:</b> '+c.dataset.hora+'</div>';
        html+='<a class="go" href="/crecer/panel/ordenes.php?marca=<?= $marca_id ?>">Abrir en Órdenes →</a>';
      } else { // evento propio
        html+='<h3>'+c.dataset.titulo+'</h3>';
        if(c.dataset.hora) html+='<div class="kv"><b>Hora:</b> '+c.dataset.hora+'</div>';
        if(c.dataset.nota) html+='<div class="cap">'+c.dataset.nota+'</div>';
        html+='<form method="post" action="" onsubmit="return confirm(\'¿Borrar este evento?\')">'
            + '<?= csrf_field() ?>'
            + '<input type="hidden" name="accion" value="borrar_evento">'
            + '<input type="hidden" name="id" value="'+c.dataset.id+'">'
            + '<button type="submit" class="del">Borrar evento</button></form>';
      }
      box.innerHTML=html; ov.classList.add('show');
    });
  });
  ov.addEventListener('click', function(e){ if(e.target===ov) ov.classList.remove('show'); });
  // Tocar día vacío → crear evento ese día (mes/semana)
  document.querySelectorAll('.cell[data-fecha]').forEach(function(cell){
    cell.addEventListener('click', function(e){
      var t=e.target;
      if(t===cell || t.classList.contains('dnum') || t.closest('.dhead')) abrirAdd(cell.dataset.fecha);
    });
  });
</script>

<?php require __DIR__ . '/_shell_foot.php'; ?>
