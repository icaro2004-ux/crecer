<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Contenido / Aprobar (dentro del shell)
//  panel/aprobar2.php
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/agentes.php';
requiere_login();
$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/intake.php'); exit; }
$marca_id = (int)$marca['id'];

// ── Acción POST (PRG) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int)($_POST['id'] ?? 0);
    $accion = $_POST['accion'] ?? '';

    // ── Editar caption (+ el bot aprende) ──
    if ($accion === 'editar') {
        $nuevo_cap = trim($_POST['caption'] ?? '');
        $o = $pdo->prepare("SELECT caption FROM crecer_contenido WHERE id=? AND marca_id=?");
        $o->execute([$id, $marca_id]); $orig = (string)$o->fetchColumn();
        $leccion = null;
        if ($id && $nuevo_cap !== '') {
            $pdo->prepare("UPDATE crecer_contenido SET caption=?, updated_at=NOW() WHERE id=? AND marca_id=?")->execute([$nuevo_cap, $id, $marca_id]);
            $leccion = aprender_de_edicion($pdo, $marca_id, $orig, $nuevo_cap);
        }
        if (!empty($_POST['ajax'])) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'id'=>$id,'caption'=>$nuevo_cap,'leccion'=>$leccion], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
        header('Location: ' . $_SERVER['REQUEST_URI']); exit;
    }
    // ── Regenerar caption con la IA ──
    if ($accion === 'regenerar') {
        @set_time_limit(0);
        try { $r = redactar_pieza($pdo, $id); $cap = $r['caption']; }
        catch (Throwable $e) { $cap = null; }
        if (!empty($_POST['ajax'])) { header('Content-Type: application/json'); echo json_encode(['ok'=>(bool)$cap,'id'=>$id,'caption'=>$cap], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
        header('Location: ' . $_SERVER['REQUEST_URI']); exit;
    }

    $nuevo  = ['aprobar'=>'aprobado','rechazar'=>'rechazado','reabrir'=>'borrador'][$accion] ?? null;
    if ($id && $nuevo) {
        $pdo->prepare("UPDATE crecer_contenido SET estado=?, updated_at=NOW() WHERE id=? AND marca_id=?")
            ->execute([$nuevo, $id, $marca_id]);
    }
    if (!empty($_POST['ajax'])) {
        $cal = (int)$pdo->query("SELECT id FROM crecer_calendario WHERE marca_id={$marca_id} ORDER BY anio DESC, mes DESC LIMIT 1")->fetchColumn();
        $c = ['borrador'=>0,'aprobado'=>0,'rechazado'=>0,'publicado'=>0];
        foreach ($pdo->query("SELECT estado, COUNT(*) n FROM crecer_contenido WHERE calendario_id={$cal} GROUP BY estado") as $r) $c[$r['estado']] = (int)$r['n'];
        $tot = array_sum($c); $list = $c['aprobado'] + $c['publicado'];
        header('Content-Type: application/json');
        echo json_encode(['ok'=>true, 'id'=>$id, 'estado'=>$nuevo, 'listos'=>$list, 'total'=>$tot, 'pend'=>$c['borrador'], 'pct'=>$tot?round($list/$tot*100):0]);
        exit;
    }
    header('Location: ' . $_SERVER['REQUEST_URI']); exit;
}

$piezas = $pdo->prepare(
    "SELECT c.* FROM crecer_contenido c
       JOIN crecer_calendario cal ON cal.id = c.calendario_id
      WHERE c.marca_id = ?
        AND cal.id = (SELECT id FROM crecer_calendario WHERE marca_id = ? ORDER BY anio DESC, mes DESC LIMIT 1)
      ORDER BY c.fecha_programada");
$piezas->execute([$marca_id, $marca_id]);
$piezas = $piezas->fetchAll();

$cuenta = ['borrador'=>0,'aprobado'=>0,'rechazado'=>0,'publicado'=>0];
foreach ($piezas as $p) { $cuenta[$p['estado']] = ($cuenta[$p['estado']] ?? 0) + 1; }
$total  = count($piezas);
$listos = $cuenta['aprobado'] + $cuenta['publicado'];
$pct    = $total ? round($listos / $total * 100) : 0;

$plat = ['instagram'=>['Instagram',''], 'facebook'=>['Facebook','fb'], 'whatsapp'=>['WhatsApp','']];
$pill = ['borrador'=>['Pendiente','wait'],'aprobado'=>['Aprobado','ok'],'rechazado'=>['Rechazado','no'],'publicado'=>['Publicado','pub']];
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$active = 'contenido';
$page_title = 'Contenido';
require __DIR__ . '/_shell.php';
?>
<style>
  .feedwrap{max-width:600px}
  .cprogress{max-width:600px;margin-top:16px}
  .cprogress .row{display:flex;align-items:baseline;justify-content:space-between;margin-bottom:8px}
  .cprogress .count{font-family:var(--font-display);font-weight:700;font-size:15px}
  .cprogress .count b{color:var(--terracota)}
  .cprogress .pending{font-size:13px;color:var(--muted)}
  .feedwrap .post{margin-top:14px}
  .viewtoggle{display:flex;gap:6px;margin:6px 0 10px}
  .vt{font-weight:700;font-size:13.5px;text-decoration:none;color:var(--muted);padding:8px 16px;border-radius:99px;border:1.5px solid var(--line)}
  .vt.on{color:#fff;background:linear-gradient(135deg,var(--coral),var(--magenta));border-color:transparent}
</style>

<h1 class="page-h">Contenido</h1>
<div class="viewtoggle">
  <a class="vt on" href="/crecer/panel/aprobar2.php?marca=<?= $marca_id ?>">📋 Lista</a>
  <a class="vt" href="/crecer/panel/calendario.php?marca=<?= $marca_id ?>">📅 Calendario</a>
</div>
<p class="page-sub">La IA lo preparó. Aprueba lo que te guste — tú tienes la última palabra. ✋</p>
<p style="font-size:12.5px;color:var(--muted);margin-top:8px;max-width:600px"><b style="color:var(--amber-ink)">Pendiente</b> = esperando tu OK · <b style="color:var(--okk-ink)">Aprobado</b> = listo para publicar · <b style="color:var(--noo-ink)">Rechazado</b> = descartado. ✏️ Edita un post y la IA <b>aprende tu vocabulario</b> para los próximos.</p>

<?php if ($total): ?>
  <div class="cprogress">
    <div class="row">
      <span class="count"><b><?= $listos ?></b> de <?= $total ?> listos para publicar</span>
      <span class="pending"><?= $cuenta['borrador'] ?> por revisar</span>
    </div>
    <div class="track"><i style="width:<?= $pct ?>%"></i></div>
  </div>
<?php endif; ?>

<div class="feedwrap">
  <?php if (!$total): ?>
    <div class="empty">
      <div class="big">🌱</div>
      <p style="margin-bottom:18px">Todavía no hay contenido para este negocio.</p>
      <?php if (!empty($_GET['err'])): ?>
        <p style="color:var(--noo-ink);font-size:13px;margin-bottom:14px">No se pudo generar ahora (<?= $h($_GET['err']) ?>). Intenta de nuevo en un minuto.</p>
      <?php endif; ?>
      <form method="post" action="/crecer/panel/generar.php"
            onsubmit="var b=this.querySelector('button');b.textContent='✨ Creando tu mes…';b.disabled=true;">
        <input type="hidden" name="marca" value="<?= $marca_id ?>">
        <button type="submit" style="border:0;cursor:pointer;font-family:inherit;font-weight:800;font-size:15px;color:#fff;background:linear-gradient(135deg,var(--coral),var(--magenta));padding:15px 26px;border-radius:99px;box-shadow:0 12px 28px rgba(255,43,133,.3)">✨ Que la IA prepare mi primer mes</button>
      </form>
      <p style="color:var(--muted);font-size:12.5px;margin-top:12px">Tarda un minutito — la IA está creando tu contenido.</p>
    </div>
  <?php endif; ?>

  <?php foreach ($piezas as $p):
    [$pl_label,$pl_cls] = $plat[$p['plataforma']] ?? [ucfirst($p['plataforma']),''];
    [$pi_label,$pi_cls] = $pill[$p['estado']] ?? ['—','wait'];
    $done = in_array($p['estado'],['aprobado','rechazado','publicado'],true);
    $fecha = date('d/m', strtotime($p['fecha_programada'] ?: 'now'));
  ?>
    <article class="post <?= $done?'done':'' ?>">
      <div class="post-head">
        <span class="chip <?= $pl_cls ?>"><span class="ico"></span><?= $h($pl_label) ?></span>
        <span class="chip"><?= $h($p['tipo']) ?></span>
        <span class="pill <?= $pi_cls ?>"><?= $pi_label ?></span>
        <span class="date"><?= $fecha ?></span>
      </div>
      <?php if (!empty($p['grafica_path'])): ?>
        <img class="zoomable" src="<?= $h($p['grafica_path']) ?>" alt="arte" style="width:100%;display:block">
      <?php endif; ?>
      <div class="caption" id="cap-<?= $p['id'] ?>"><?= $h($p['caption']) ?></div>
      <div class="toolrow" id="tools-<?= $p['id'] ?>" style="padding:0 17px 12px;display:flex;gap:16px;flex-wrap:wrap;font-size:13px">
        <a href="#" class="editlink" data-id="<?= $p['id'] ?>" style="font-weight:700;color:var(--terracota);text-decoration:none">✏️ Editar</a>
        <a href="/crecer/panel/graficas.php?marca=<?= $marca_id ?>&post=<?= $p['id'] ?>" style="font-weight:700;color:var(--terracota);text-decoration:none">🖼️ <?= !empty($p['grafica_path']) ? 'Cambiar arte' : 'Crear arte' ?></a>
        <a href="#" class="regenlink" data-id="<?= $p['id'] ?>" style="font-weight:700;color:var(--muted);text-decoration:none">🔄 Regenerar</a>
      </div>
      <form class="editform" data-id="<?= $p['id'] ?>" style="display:none;padding:0 17px 14px">
        <textarea name="caption" style="width:100%;font-family:inherit;font-size:14px;color:var(--tinta);border:1.5px solid var(--line);border-radius:12px;padding:11px 13px;min-height:96px"><?= $h($p['caption']) ?></textarea>
        <div style="font-size:11.5px;color:var(--muted);margin:6px 0">💡 Corrige el vocabulario y la IA aprende para los próximos posts.</div>
        <div style="display:flex;gap:8px">
          <button type="submit" style="border:0;cursor:pointer;font-family:inherit;font-weight:800;font-size:13px;color:#fff;background:var(--palma);padding:9px 18px;border-radius:99px">Guardar</button>
          <button type="button" class="cancel" style="border:1.5px solid var(--line);cursor:pointer;font-family:inherit;font-weight:700;font-size:13px;background:#fff;color:var(--muted);padding:9px 16px;border-radius:99px">Cancelar</button>
        </div>
      </form>
      <div class="post-actions">
        <?php if ($p['estado']==='borrador'): ?>
          <form method="post"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="btn btn-ok" name="accion" value="aprobar">✓ Aprobar</button></form>
          <form method="post"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="btn btn-no" name="accion" value="rechazar">Rechazar</button></form>
        <?php else: ?>
          <form method="post"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="btn btn-ghost" name="accion" value="reabrir">↺ Volver a revisar</button></form>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
</div>

<script>
  var PILL = {borrador:['Pendiente','wait'], aprobado:['Aprobado','ok'], rechazado:['Rechazado','no'], publicado:['Publicado','pub']};
  function actionsHTML(id, estado){
    if (estado === 'borrador')
      return '<form method="post"><input type="hidden" name="id" value="'+id+'"><button class="btn btn-ok" name="accion" value="aprobar">✓ Aprobar</button></form>'
           + '<form method="post"><input type="hidden" name="id" value="'+id+'"><button class="btn btn-no" name="accion" value="rechazar">Rechazar</button></form>';
    return '<form method="post"><input type="hidden" name="id" value="'+id+'"><button class="btn btn-ghost" name="accion" value="reabrir">↺ Volver a revisar</button></form>';
  }
  var feed = document.querySelector('.feedwrap');
  if (feed) feed.addEventListener('submit', function(e){
    var f = e.target.closest('form');
    if (!f || !f.closest('.post-actions')) return;
    e.preventDefault();
    var card = f.closest('.post');
    var fd = new FormData(f); fd.append('ajax','1');
    // el botón apretado (aprobar/rechazar/reabrir) NO entra en FormData solo: añadirlo
    var btn = e.submitter || f.querySelector('button[name="accion"]');
    if (btn && btn.name) fd.append(btn.name, btn.value);
    f.querySelectorAll('button').forEach(function(b){b.disabled=true;});
    fetch(location.pathname + location.search, {method:'POST', body:fd})
      .then(function(r){return r.json();})
      .then(function(d){
        if(!d.ok) return;
        // pill
        var pill = card.querySelector('.pill');
        if(pill){ pill.textContent = PILL[d.estado][0]; pill.className = 'pill '+PILL[d.estado][1]; }
        // done state
        card.classList.toggle('done', d.estado !== 'borrador');
        // acciones
        card.querySelector('.post-actions').innerHTML = actionsHTML(d.id, d.estado);
        // progreso
        var cnt=document.querySelector('.cprogress .count'), pen=document.querySelector('.cprogress .pending'), bar=document.querySelector('.track > i');
        if(cnt) cnt.innerHTML='<b>'+d.listos+'</b> de '+d.total+' listos para publicar';
        if(pen) pen.textContent=d.pend+' por revisar';
        if(bar) bar.style.width=d.pct+'%';
      })
      .catch(function(){ f.querySelectorAll('button').forEach(function(b){b.disabled=false;}); });
  });

  function toast(msg){
    var t=document.createElement('div');
    t.textContent=msg;
    t.style.cssText='position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:var(--tinta);color:#fff;padding:12px 20px;border-radius:99px;font-weight:700;font-size:14px;z-index:200;box-shadow:0 10px 30px rgba(0,0,0,.3);max-width:90vw;text-align:center';
    document.body.appendChild(t);
    setTimeout(function(){t.style.opacity='0';t.style.transition='opacity .4s';},2800);
    setTimeout(function(){t.remove();},3300);
  }
  // Editar / regenerar / cancelar
  if(feed) feed.addEventListener('click', function(e){
    var el=e.target.closest('.editlink,.regenlink,.cancel'); if(!el) return; e.preventDefault();
    var card=el.closest('.post');
    if(el.classList.contains('editlink')){
      card.querySelector('.editform').style.display='block';
      card.querySelector('.caption').style.display='none';
      card.querySelector('.toolrow').style.display='none';
      card.querySelector('.editform textarea').focus();
    } else if(el.classList.contains('cancel')){
      card.querySelector('.editform').style.display='none';
      card.querySelector('.caption').style.display='';
      card.querySelector('.toolrow').style.display='flex';
    } else if(el.classList.contains('regenlink')){
      el.textContent='🔄 Regenerando…';
      var fd=new FormData(); fd.append('ajax','1'); fd.append('accion','regenerar'); fd.append('id',el.dataset.id);
      fetch(location.pathname+location.search,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
        el.textContent='🔄 Regenerar';
        if(d.ok){ card.querySelector('.caption').textContent=d.caption; var ta=card.querySelector('.editform textarea'); if(ta)ta.value=d.caption; toast('✨ Caption regenerado'); }
        else toast('No se pudo regenerar (¿límite de IA?)');
      }).catch(function(){ el.textContent='🔄 Regenerar'; });
    }
  });
  // Guardar edición (el bot aprende)
  if(feed) feed.addEventListener('submit', function(e){
    var f=e.target.closest('.editform'); if(!f) return; e.preventDefault();
    var card=f.closest('.post');
    var fd=new FormData(f); fd.append('ajax','1'); fd.append('accion','editar'); fd.append('id',f.dataset.id);
    var b=f.querySelector('button[type=submit]'); b.disabled=true; b.textContent='Guardando…';
    fetch(location.pathname+location.search,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
      b.disabled=false; b.textContent='Guardar';
      if(d.ok){
        card.querySelector('.caption').textContent=d.caption;
        f.style.display='none';
        card.querySelector('.caption').style.display='';
        card.querySelector('.toolrow').style.display='flex';
        if(d.leccion) toast('🧠 La IA aprendió: '+d.leccion.replace(/\n/g,' · ').slice(0,90));
        else toast('✓ Guardado');
      }
    }).catch(function(){ b.disabled=false; b.textContent='Guardar'; });
  });
</script>

<?php require __DIR__ . '/_shell_foot.php'; ?>
