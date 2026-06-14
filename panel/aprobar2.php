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

    // ── Crear el ARTE del post SIN salir (fábrica de posts) ──
    if ($accion === 'arte') {
        @set_time_limit(0);
        $dir_fotos = rtrim(UPLOADS_PATH, '/\\') . "/marca_{$marca_id}/fotos";
        $wk = $pdo->prepare("SELECT COUNT(*) FROM crecer_graficas WHERE marca_id=? AND created_at >= (NOW() - INTERVAL 7 DAY)");
        $wk->execute([$marca_id]); $usados = (int)$wk->fetchColumn();
        if ($usados >= 5) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'err'=>'limite']); exit; }
        // Foto: subida nueva (inline) o escogida del picker
        $src = null;
        if (!empty($_FILES['foto_nueva']['tmp_name']) && $_FILES['foto_nueva']['error'] === UPLOAD_ERR_OK) {
            $info = @getimagesize($_FILES['foto_nueva']['tmp_name']);
            $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$info['mime'] ?? ''] ?? null;
            if ($ext) { @mkdir($dir_fotos, 0775, true); $dest = $dir_fotos.'/foto_'.uniqid().'.'.$ext;
                if (move_uploaded_file($_FILES['foto_nueva']['tmp_name'], $dest)) $src = $dest; }
        } elseif (!empty($_POST['foto'])) {
            $nombre = basename($_POST['foto']);
            if (strpos($nombre,'..')===false && is_file($dir_fotos.'/'.$nombre)) $src = $dir_fotos.'/'.$nombre;
        }
        $capr = $pdo->prepare("SELECT caption FROM crecer_contenido WHERE id=? AND marca_id=?");
        $capr->execute([$id, $marca_id]); $copy = (string)$capr->fetchColumn();
        try {
            $r = generar_grafica($pdo, $marca_id, $src, [
                'copy'         => $copy,
                'con_texto'    => ($_POST['con_texto'] ?? '') === '1',
                'con_logo'     => !empty($_POST['con_logo']),
                'logo_estilo'  => $_POST['logo_estilo'] ?? 'esquina',
                'estilo'       => $_POST['estilo'] ?? '',
                'instrucciones'=> trim($_POST['instrucciones'] ?? ''),
            ]);
            $pdo->prepare("UPDATE crecer_contenido SET grafica_path=?, updated_at=NOW() WHERE id=? AND marca_id=?")
                ->execute([$r['archivo'], $id, $marca_id]);
            header('Content-Type: application/json');
            echo json_encode(['ok'=>true,'id'=>$id,'img'=>$r['archivo'],'restantes'=>max(0,5-($usados+1))], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Throwable $e) {
            header('Content-Type: application/json'); echo json_encode(['ok'=>false,'err'=>substr($e->getMessage(),0,120)]); exit;
        }
    }
    // ── Pedir un post a la IA (tema sugerido / borrador a pulir / random) ──
    if ($accion === 'pedir_post') {
        @set_time_limit(0);
        $tema     = trim($_POST['tema'] ?? '');
        $borrador = trim($_POST['borrador'] ?? '');
        $plat = in_array($_POST['plataforma'] ?? '', ['instagram','facebook','whatsapp'], true) ? $_POST['plataforma'] : 'instagram';
        $fecha = $_POST['fecha'] ?? '';
        $fecha_dt = ($fecha && strtotime($fecha)) ? (date('Y-m-d', strtotime($fecha)) . ' 10:00:00') : date('Y-m-d 10:00:00');

        if ($tema !== '' || $borrador !== '') {
            // ── Post guiado por el dueño (1 pieza) ──
            $fa = (int)date('Y', strtotime($fecha_dt)); $fm = (int)date('n', strtotime($fecha_dt));
            $pdo->prepare("INSERT INTO crecer_calendario (marca_id, anio, mes, estado, generado_por_ia) VALUES (?,?,?, 'borrador', 1) ON DUPLICATE KEY UPDATE updated_at=NOW()")->execute([$marca_id,$fa,$fm]);
            $calid = (int)$pdo->query("SELECT id FROM crecer_calendario WHERE marca_id={$marca_id} AND anio={$fa} AND mes={$fm}")->fetchColumn();
            $idea = $tema !== '' ? $tema : 'Pulir borrador del dueño';
            $pdo->prepare("INSERT INTO crecer_contenido (calendario_id, marca_id, plataforma, tipo, caption, fecha_programada, estado) VALUES (?,?,?,?,?,?, 'borrador')")
                ->execute([$calid, $marca_id, $plat, 'post', $idea, $fecha_dt]);
            $nid = (int)$pdo->lastInsertId();
            try { redactar_sugerido($pdo, $nid, $tema, $borrador); }
            catch (Throwable $e) { /* queda el borrador con la idea para editar */ }
            header("Location: /crecer/panel/aprobar2.php?marca={$marca_id}&generados=1#cap-{$nid}"); exit;
        }
        // ── Sin tema: la IA inventa N (planificador) ──
        $n = max(1, min(6, (int)($_POST['n'] ?? 3)));
        $cal = $pdo->prepare("SELECT anio, mes FROM crecer_calendario WHERE marca_id=? ORDER BY anio DESC, mes DESC LIMIT 1");
        $cal->execute([$marca_id]); $cal = $cal->fetch();
        $ca = $cal ? (int)$cal['anio'] : (int)date('Y');
        $cm = $cal ? (int)$cal['mes']  : (int)date('n');
        try {
            $plan = planificar_mes($pdo, $marca_id, $ca, $cm, $n);
            foreach ($plan['piezas'] as $pz) { try { redactar_pieza($pdo, (int)$pz['id']); } catch (Throwable $e) {} }
        } catch (Throwable $e) {
            header("Location: /crecer/panel/aprobar2.php?marca={$marca_id}&err=".urlencode(substr($e->getMessage(),0,100))); exit;
        }
        header("Location: /crecer/panel/aprobar2.php?marca={$marca_id}&generados={$n}"); exit;
    }
    // ── Escribir un post yo mismo (borrador vacío para editar) ──
    if ($accion === 'nuevo_manual') {
        $cal = (int)$pdo->query("SELECT id FROM crecer_calendario WHERE marca_id={$marca_id} ORDER BY anio DESC, mes DESC LIMIT 1")->fetchColumn();
        if (!$cal) {
            $ca = (int)date('Y'); $cm = (int)date('n');
            $pdo->prepare("INSERT INTO crecer_calendario (marca_id, anio, mes, estado, generado_por_ia) VALUES (?,?,?, 'borrador', 0) ON DUPLICATE KEY UPDATE updated_at=NOW()")->execute([$marca_id,$ca,$cm]);
            $cal = (int)$pdo->query("SELECT id FROM crecer_calendario WHERE marca_id={$marca_id} AND anio={$ca} AND mes={$cm}")->fetchColumn();
        }
        $plat = in_array($_POST['plataforma'] ?? '', ['instagram','facebook','whatsapp'], true) ? $_POST['plataforma'] : 'instagram';
        $pdo->prepare("INSERT INTO crecer_contenido (calendario_id, marca_id, plataforma, tipo, caption, fecha_programada, estado) VALUES (?,?,?,?,?,?, 'borrador')")
            ->execute([$cal, $marca_id, $plat, 'post', '', date('Y-m-d 10:00:00')]);
        $nid = (int)$pdo->lastInsertId();
        header("Location: /crecer/panel/aprobar2.php?marca={$marca_id}&edit={$nid}#cap-{$nid}"); exit;
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

// Recursos para el estudio de arte inline (fábrica de posts)
$dir_fotos = rtrim(UPLOADS_PATH, '/\\') . "/marca_{$marca_id}/fotos";
$url_fotos = rtrim(UPLOADS_URL, '/') . "/marca_{$marca_id}/fotos";
$fotos = is_dir($dir_fotos) ? array_values(array_filter(scandir($dir_fotos), fn($x)=>$x[0]!=='.')) : [];
$tiene_logo = !empty($marca['logo_path']);
$wk = $pdo->prepare("SELECT COUNT(*) FROM crecer_graficas WHERE marca_id=? AND created_at >= (NOW() - INTERVAL 7 DAY)");
$wk->execute([$marca_id]); $restantes_sem = max(0, 5 - (int)$wk->fetchColumn());

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
  .okbar{max-width:600px;background:var(--okk-bg);color:var(--okk-ink);font-weight:700;font-size:14px;padding:11px 14px;border-radius:12px;margin-top:14px}
  .errbar{max-width:600px;background:var(--noo-bg);color:var(--noo-ink);font-weight:700;font-size:14px;padding:11px 14px;border-radius:12px;margin-top:14px}
  .factorybar{max-width:600px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:16px 0 4px}
  .factorybar .fbform{display:flex;gap:8px;align-items:center}
  .factorybar select{font-family:inherit;font-size:13.5px;font-weight:700;border:1.5px solid var(--line);border-radius:99px;padding:9px 12px;background:#fff}
  .fbgen{border:0;cursor:pointer;font-family:inherit;font-weight:800;font-size:13.5px;color:#fff;background:linear-gradient(135deg,var(--coral),var(--magenta));padding:10px 18px;border-radius:99px}
  .fbnew{border:1.5px solid var(--line);cursor:pointer;font-family:inherit;font-weight:700;font-size:13.5px;color:var(--tinta);background:#fff;padding:10px 18px;border-radius:99px}
  .artwrap{position:relative}
  .artph{width:100%;border:0;border-top:1px dashed var(--line);border-bottom:1px dashed var(--line);background:repeating-linear-gradient(45deg,var(--crema),var(--crema) 10px,#fff 10px,#fff 20px);cursor:pointer;font-family:inherit;font-weight:800;font-size:14px;color:var(--terracota);padding:26px 12px;display:flex;flex-direction:column;align-items:center;gap:6px}
  .artph:hover{color:var(--terracota-700)}
  .checklist{display:flex;gap:8px;flex-wrap:wrap;padding:0 17px 10px}
  .ck-item{font-size:11.5px;font-weight:800;color:var(--muted);background:var(--crema);border:1px solid var(--line);padding:4px 10px;border-radius:99px;opacity:.6}
  .ck-item.on{color:var(--okk-ink);background:var(--okk-bg);border-color:transparent;opacity:1}
  .ck-item.on::before{content:"✓ "}
  /* Modal estudio de arte */
  .art-ov{display:none;position:fixed;inset:0;background:rgba(20,12,8,.72);z-index:95;align-items:flex-start;justify-content:center;padding:30px 16px;overflow:auto}
  .art-ov.show{display:flex}
  .art-box{background:var(--card);border-radius:var(--r-xl);max-width:480px;width:100%;padding:22px;position:relative}
  .art-box h3{font-family:var(--font-display);font-weight:800;font-size:20px;margin-bottom:2px}
  .art-box .sub{font-size:13px;color:var(--muted);margin-bottom:6px}
  .art-box .x{position:absolute;top:12px;right:14px;border:0;background:none;font-size:20px;cursor:pointer;color:var(--muted)}
  .art-box .fl{display:block;font-weight:700;font-size:13px;margin:14px 0 7px}
  .art-box .picker{display:flex;gap:8px;flex-wrap:wrap}
  .art-box .pk{cursor:pointer}.art-box .pk input{position:absolute;opacity:0}
  .art-box .pk img,.art-box .pk .none{width:64px;height:64px;border-radius:12px;object-fit:cover;border:2.5px solid var(--line);display:block}
  .art-box .pk .none{display:grid;place-items:center;font-size:10.5px;color:var(--muted);text-align:center;background:var(--crema);line-height:1.1}
  .art-box .pk input:checked + img,.art-box .pk input:checked + .none{border-color:var(--terracota)}
  .art-box .chips{display:flex;flex-wrap:wrap;gap:7px}
  .art-box .chip-opt{cursor:pointer}.art-box .chip-opt input{position:absolute;opacity:0}
  .art-box .chip-opt span{display:inline-block;padding:6px 12px;border-radius:99px;border:1.5px solid var(--line);background:#fff;font-weight:700;font-size:12.5px}
  .art-box .chip-opt input:checked + span{border-color:transparent;color:#fff;background:linear-gradient(135deg,var(--coral),var(--magenta))}
  .art-box textarea,.art-box input[type=file]{width:100%;font-family:inherit;font-size:13.5px;border:1.5px solid var(--line);border-radius:12px;padding:9px 11px}
  .art-box .ck{display:flex;align-items:center;gap:7px;font-weight:700;font-size:13.5px}
  .art-go{width:100%;border:0;cursor:pointer;font-family:inherit;font-weight:800;font-size:15px;color:#fff;background:linear-gradient(135deg,var(--coral),var(--magenta));padding:13px;border-radius:99px;margin-top:18px}
  .art-go:disabled{opacity:.6;cursor:default}
  .art-skip{display:block;text-align:center;margin-top:12px;font-size:13px;font-weight:700;color:var(--muted);text-decoration:none}
  .art-note{font-size:11.5px;color:var(--muted);margin-top:10px;text-align:center}
</style>

<h1 class="page-h">Contenido</h1>
<div class="viewtoggle">
  <a class="vt on" href="/crecer/panel/aprobar2.php?marca=<?= $marca_id ?>">📋 Lista</a>
  <a class="vt" href="/crecer/panel/calendario.php?marca=<?= $marca_id ?>">📅 Calendario</a>
</div>
<p class="page-sub">La IA lo preparó. Aprueba lo que te guste — tú tienes la última palabra. ✋</p>
<p style="font-size:12.5px;color:var(--muted);margin-top:8px;max-width:600px"><b style="color:var(--amber-ink)">Pendiente</b> = esperando tu OK · <b style="color:var(--okk-ink)">Aprobado</b> = listo para publicar · <b style="color:var(--noo-ink)">Rechazado</b> = descartado. ✏️ Edita un post y la IA <b>aprende tu vocabulario</b> para los próximos.</p>

<?php if (!empty($_GET['generados'])): ?><div class="okbar">✨ La IA redactó <?= (int)$_GET['generados'] ?> post(s) nuevo(s). Revísalos abajo.</div><?php endif; ?>
<?php if (!empty($_GET['err'])): ?><div class="errbar">⚠️ No se pudo generar (<?= $h($_GET['err']) ?>). Intenta de nuevo en un minuto.</div><?php endif; ?>

<?php if ($total): ?>
  <div class="cprogress">
    <div class="row">
      <span class="count"><b><?= $listos ?></b> de <?= $total ?> listos para publicar</span>
      <span class="pending"><?= $cuenta['borrador'] ?> por revisar</span>
    </div>
    <div class="track"><i style="width:<?= $pct ?>%"></i></div>
  </div>

  <div class="factorybar">
    <button type="button" class="fbgen" onclick="abrirBrief()">✏️ Pedir un post a la IA</button>
    <form method="post" onsubmit="var b=this.querySelector('button');b.disabled=true;">
      <input type="hidden" name="accion" value="nuevo_manual">
      <button type="submit" class="fbnew">➕ Escribir uno yo (sin IA)</button>
    </form>
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
    <?php $has_cap = trim($p['caption'])!==''; $has_art = !empty($p['grafica_path']); $is_ok = in_array($p['estado'],['aprobado','publicado'],true); ?>
    <article class="post <?= $done?'done':'' ?>" data-id="<?= $p['id'] ?>" data-img="<?= $has_art?'1':'' ?>">
      <div class="post-head">
        <span class="chip <?= $pl_cls ?>"><span class="ico"></span><?= $h($pl_label) ?></span>
        <span class="chip"><?= $h($p['tipo']) ?></span>
        <span class="pill <?= $pi_cls ?>"><?= $pi_label ?></span>
        <span class="date"><?= $fecha ?></span>
      </div>
      <div class="artwrap" id="art-<?= $p['id'] ?>">
        <?php if ($has_art): ?>
          <img class="zoomable" src="<?= $h($p['grafica_path']) ?>" alt="arte" style="width:100%;display:block">
        <?php else: ?>
          <button type="button" class="artph artbtn" data-id="<?= $p['id'] ?>">
            <span style="font-size:30px">🎨</span><span>Crear el arte de este post</span>
          </button>
        <?php endif; ?>
      </div>
      <div class="caption" id="cap-<?= $p['id'] ?>"><?= $h($p['caption']) ?: '<span style="color:var(--muted)">Sin texto todavía — toca ✏️ Editar o 🔄 que la IA lo escriba.</span>' ?></div>
      <div class="checklist" id="chk-<?= $p['id'] ?>">
        <span class="ck-item <?= $has_cap?'on':'' ?>" data-k="cap">✍️ Copy</span>
        <span class="ck-item <?= $has_art?'on':'' ?>" data-k="art">🎨 Arte</span>
        <span class="ck-item <?= $is_ok?'on':'' ?>" data-k="ok">✋ Aprobado</span>
      </div>
      <div class="toolrow" id="tools-<?= $p['id'] ?>" style="padding:0 17px 12px;display:flex;gap:16px;flex-wrap:wrap;font-size:13px">
        <a href="#" class="editlink" data-id="<?= $p['id'] ?>" style="font-weight:700;color:var(--terracota);text-decoration:none">✏️ Editar</a>
        <a href="#" class="artbtn" data-id="<?= $p['id'] ?>" style="font-weight:700;color:var(--terracota);text-decoration:none">🖼️ <?= $has_art ? 'Cambiar arte' : 'Crear arte' ?></a>
        <a href="#" class="regenlink" data-id="<?= $p['id'] ?>" style="font-weight:700;color:var(--muted);text-decoration:none">🔄 Regenerar texto</a>
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

<!-- MODAL: PEDIR UN POST A LA IA (brief del dueño) -->
<div class="art-ov" id="briefov">
  <form class="art-box" method="post" id="briefform" onsubmit="var b=this.querySelector('.art-go');b.textContent='✨ Redactando… (~10s)';b.disabled=true;">
    <button type="button" class="x" onclick="document.getElementById('briefov').classList.remove('show')">✕</button>
    <h3>✏️ Pedir un post a la IA</h3>
    <div class="sub">Sugiere el tema, o escribe un borrador y la IA lo pule respetando tu intención. Déjalo todo en blanco y la IA inventa.</div>
    <input type="hidden" name="accion" value="pedir_post">

    <label class="fl">¿De qué quieres el post? <span style="color:var(--muted);font-weight:500">(opcional)</span></label>
    <textarea name="tema" rows="2" placeholder="Ej: promo del bizcocho de guayaba para el Día de las Madres"></textarea>

    <label class="fl">¿Tienes un borrador? La IA lo mejora <span style="color:var(--muted);font-weight:500">(opcional)</span></label>
    <textarea name="borrador" rows="3" placeholder="Escríbelo como te salga; la IA lo pule manteniendo tu intención y tus datos (precios, fechas)."></textarea>

    <label class="fl">Plataforma</label>
    <div class="chips">
      <label class="chip-opt"><input type="radio" name="plataforma" value="instagram" checked><span>📸 Instagram</span></label>
      <label class="chip-opt"><input type="radio" name="plataforma" value="facebook"><span>👍 Facebook</span></label>
      <label class="chip-opt"><input type="radio" name="plataforma" value="whatsapp"><span>💬 WhatsApp</span></label>
    </div>

    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:4px">
      <div style="flex:1;min-width:140px">
        <label class="fl">Fecha del post</label>
        <input type="date" name="fecha" value="<?= date('Y-m-d') ?>" style="width:100%;font-family:inherit;font-size:13.5px;border:1.5px solid var(--line);border-radius:12px;padding:9px 11px">
      </div>
      <div style="flex:1;min-width:140px">
        <label class="fl">Si dejas todo vacío…</label>
        <select name="n" style="width:100%;font-family:inherit;font-size:13.5px;font-weight:700;border:1.5px solid var(--line);border-radius:12px;padding:9px 11px;background:#fff">
          <option value="1">la IA inventa 1</option>
          <option value="3" selected>la IA inventa 3</option>
          <option value="6">la IA inventa 6</option>
        </select>
      </div>
    </div>

    <button type="submit" class="art-go">✨ Redactar</button>
    <div class="art-note">La IA usa el perfil de tu negocio y el vocabulario que le has enseñado.</div>
  </form>
</div>

<!-- MODAL: ESTUDIO DE ARTE (fábrica de posts) -->
<div class="art-ov" id="artov">
  <form class="art-box" id="artform" enctype="multipart/form-data">
    <button type="button" class="x" onclick="cerrarArte()">✕</button>
    <h3>🎨 Arte del post</h3>
    <div class="sub" id="art-copyprev">La imagen irá acorde a tu copy.</div>
    <input type="hidden" name="accion" value="arte">
    <input type="hidden" name="id" id="art-id" value="">

    <label class="fl">Foto base <span style="color:var(--muted);font-weight:500">(real de tu negocio)</span></label>
    <div class="picker">
      <?php foreach ($fotos as $i=>$fn): ?>
        <label class="pk"><input type="radio" name="foto" value="<?= $h($fn) ?>" <?= $i===0?'checked':'' ?>><img src="<?= $h($url_fotos.'/'.$fn) ?>" alt=""></label>
      <?php endforeach; ?>
      <label class="pk"><input type="radio" name="foto" value="" <?= !$fotos?'checked':'' ?>><span class="none">Sin foto<br>(generar)</span></label>
    </div>
    <label class="fl">…o sube una foto nueva ahora</label>
    <input type="file" name="foto_nueva" accept="image/png,image/jpeg,image/webp">

    <label class="fl">¿Texto sobre la imagen?</label>
    <div class="chips">
      <label class="chip-opt"><input type="radio" name="con_texto" value="0" checked><span>Solo mejorar la foto</span></label>
      <label class="chip-opt"><input type="radio" name="con_texto" value="1"><span>Con texto (gancho)</span></label>
    </div>

    <label class="fl">Estilo</label>
    <div class="chips">
      <?php foreach (['Auto'=>'', 'Boricua'=>'boricua, alegre', 'Elegante'=>'elegante y premium', 'Minimalista'=>'minimalista y limpio', 'Vibrante'=>'colores vibrantes', 'Apetitoso'=>'apetitoso, food photography'] as $lb=>$val): ?>
        <label class="chip-opt"><input type="radio" name="estilo" value="<?= $h($val) ?>" <?= $lb==='Auto'?'checked':'' ?>><span><?= $h($lb) ?></span></label>
      <?php endforeach; ?>
    </div>

    <label class="fl">Instrucciones a la IA <span style="color:var(--muted);font-weight:500">(opcional)</span></label>
    <textarea name="instrucciones" rows="2" placeholder='Ej. "ponlo sobre mesa de madera", "añade confeti", "estilo navideño"…'></textarea>

    <?php if ($tiene_logo): ?>
    <label class="ck" style="margin-top:14px"><input type="checkbox" name="con_logo" value="1" id="art-logo"> Incluir mi logo</label>
    <div id="art-logoest" style="display:none;margin-top:8px">
      <div class="chips">
        <label class="chip-opt"><input type="radio" name="logo_estilo" value="watermark" checked><span>💧 Marca de agua</span></label>
        <label class="chip-opt"><input type="radio" name="logo_estilo" value="esquina"><span>📍 Esquina</span></label>
        <label class="chip-opt"><input type="radio" name="logo_estilo" value="integrado"><span>🎨 Integrado</span></label>
      </div>
    </div>
    <?php endif; ?>

    <button type="submit" class="art-go" id="art-go">✨ Crear el arte (~15s)</button>
    <a href="#" class="art-skip" id="art-skip" style="display:none">Aprobar solo con el texto (sin imagen) →</a>
    <div class="art-note">Te quedan <b id="art-rest" style="color:var(--terracota)"><?= $restantes_sem ?></b> de 5 imágenes esta semana. Con texto = modelo Pro (letras perfectas).</div>
  </form>
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
  function setChk(card, k, on){
    var item = card.querySelector('.checklist .ck-item[data-k="'+k+'"]');
    if(item) item.classList.toggle('on', !!on);
  }
  function enviarAccion(card, accion){
    var fd = new FormData(); fd.append('ajax','1'); fd.append('id', card.dataset.id); fd.append('accion', accion);
    card.querySelectorAll('.post-actions button').forEach(function(b){b.disabled=true;});
    return fetch(location.pathname + location.search, {method:'POST', body:fd})
      .then(function(r){return r.json();})
      .then(function(d){
        if(!d.ok) return;
        var pill = card.querySelector('.pill');
        if(pill){ pill.textContent = PILL[d.estado][0]; pill.className = 'pill '+PILL[d.estado][1]; }
        card.classList.toggle('done', d.estado !== 'borrador');
        card.querySelector('.post-actions').innerHTML = actionsHTML(d.id, d.estado);
        setChk(card,'ok', d.estado==='aprobado' || d.estado==='publicado');
        var cnt=document.querySelector('.cprogress .count'), pen=document.querySelector('.cprogress .pending'), bar=document.querySelector('.track > i');
        if(cnt) cnt.innerHTML='<b>'+d.listos+'</b> de '+d.total+' listos para publicar';
        if(pen) pen.textContent=d.pend+' por revisar';
        if(bar) bar.style.width=d.pct+'%';
      })
      .catch(function(){ card.querySelectorAll('.post-actions button').forEach(function(b){b.disabled=false;}); });
  }
  if (feed) feed.addEventListener('submit', function(e){
    var f = e.target.closest('form');
    if (!f || !f.closest('.post-actions')) return;
    e.preventDefault();
    var card = f.closest('.post');
    var btn = e.submitter || f.querySelector('button[name="accion"]');
    var accion = btn ? btn.value : '';
    // Aprobar inteligente: sin arte → abrir el estudio (modo "crear y aprobar")
    if (accion === 'aprobar' && !card.dataset.img) { abrirArte(card, true); return; }
    enviarAccion(card, accion);
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
        el.textContent='🔄 Regenerar texto';
        if(d.ok){ card.querySelector('.caption').textContent=d.caption; var ta=card.querySelector('.editform textarea'); if(ta)ta.value=d.caption; setChk(card,'cap',d.caption.trim()!==''); toast('✨ Caption regenerado'); }
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
        setChk(card,'cap',d.caption.trim()!=='');
        f.style.display='none';
        card.querySelector('.caption').style.display='';
        card.querySelector('.toolrow').style.display='flex';
        if(d.leccion) toast('🧠 La IA aprendió: '+d.leccion.replace(/\n/g,' · ').slice(0,90));
        else toast('✓ Guardado');
      }
    }).catch(function(){ b.disabled=false; b.textContent='Guardar'; });
  });

  // ===== Pedir un post a la IA (brief) =====
  function abrirBrief(){ document.getElementById('briefov').classList.add('show'); }
  document.getElementById('briefov').addEventListener('click', function(e){ if(e.target===this) this.classList.remove('show'); });

  // ===== Estudio de arte (modal) — fábrica de posts =====
  var artov=document.getElementById('artov'), artform=document.getElementById('artform');
  var artCard=null, artThenApprove=false;
  function abrirArte(card, thenApprove){
    artCard=card; artThenApprove=!!thenApprove;
    document.getElementById('art-id').value=card.dataset.id;
    var cap=card.querySelector('.caption'); var txt=cap?cap.textContent.trim():'';
    document.getElementById('art-copyprev').textContent = txt ? ('"'+txt.slice(0,90)+(txt.length>90?'…':'')+'"') : 'La imagen irá acorde a tu copy.';
    document.getElementById('art-skip').style.display = thenApprove ? 'block' : 'none';
    var go=document.getElementById('art-go'); go.disabled=false; go.textContent='✨ Crear el arte (~15s)';
    artov.classList.add('show');
  }
  function cerrarArte(){ artov.classList.remove('show'); artCard=null; artThenApprove=false; }
  artov.addEventListener('click', function(e){ if(e.target===artov) cerrarArte(); });
  if(feed) feed.addEventListener('click', function(e){
    var b=e.target.closest('.artbtn'); if(!b) return; e.preventDefault();
    abrirArte(b.closest('.post'), false);
  });
  var artLogo=document.getElementById('art-logo');
  if(artLogo) artLogo.addEventListener('change', function(){ document.getElementById('art-logoest').style.display=this.checked?'block':'none'; });
  artform.addEventListener('submit', function(e){
    e.preventDefault(); if(!artCard) return;
    var go=document.getElementById('art-go'); go.disabled=true; go.textContent='✨ Creando… (~15s)';
    var card=artCard, thenApprove=artThenApprove;
    var fd=new FormData(artform); fd.append('ajax','1');
    fetch(location.pathname+location.search,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
      if(!d.ok){
        go.disabled=false; go.textContent='✨ Crear el arte (~15s)';
        toast(d.err==='limite'?'🗓️ Usaste tus 5 imágenes de la semana.':'No se pudo crear el arte. Intenta de nuevo.');
        return;
      }
      var wrap=card.querySelector('.artwrap');
      if(wrap) wrap.innerHTML='<img class="zoomable" src="'+d.img+'?t='+Date.now()+'" alt="arte" style="width:100%;display:block">';
      card.dataset.img='1'; setChk(card,'art',true);
      var tl=card.querySelector('.toolrow .artbtn'); if(tl) tl.innerHTML='🖼️ Cambiar arte';
      document.getElementById('art-rest').textContent=d.restantes;
      cerrarArte();
      if(thenApprove){ enviarAccion(card,'aprobar').then(function(){ toast('✅ Post completo y aprobado'); }); }
      else toast('🎨 Arte listo y pegado al post');
    }).catch(function(){ go.disabled=false; go.textContent='✨ Crear el arte (~15s)'; toast('Error de conexión.'); });
  });
  document.getElementById('art-skip').addEventListener('click', function(e){
    e.preventDefault(); var card=artCard; cerrarArte(); if(card) enviarAccion(card,'aprobar').then(function(){ toast('✓ Aprobado (solo texto)'); });
  });
  // Auto-abrir el editor si venimos de "escribir uno yo"
  (function(){
    var m=location.search.match(/[?&]edit=(\d+)/); if(!m) return;
    var card=document.querySelector('.post[data-id="'+m[1]+'"]'); if(!card) return;
    var el=card.querySelector('.editlink'); if(el) el.click();
    card.scrollIntoView({behavior:'smooth',block:'center'});
  })();
</script>

<?php require __DIR__ . '/_shell_foot.php'; ?>
