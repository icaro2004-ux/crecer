<?php
// ============================================================
//  CRECER — Operaciones · Diagnóstico de UN cliente
//  panel/admin_cliente.php?marca=<id>   (solo admin)
//
//  Para soporte: ver de un vistazo por qué un cliente "no postea"
//  o "la IA hizo algo raro". Conexión Meta (con verificación EN
//  VIVO del token), últimos intentos de publicación con su error,
//  actividad reciente de IA, y reintentar una publicación fallida.
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/meta.php';
require_once __DIR__ . '/../includes/iconos.php';
requiere_login();
$usuario = usuario_actual($pdo);
if (($usuario['rol'] ?? '') !== 'admin') { http_response_code(403); exit('Acceso solo para administradores.'); }

$h   = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$mid = (int)($_GET['marca'] ?? 0);
$m   = $pdo->prepare("SELECT * FROM crecer_marca WHERE id=?"); $m->execute([$mid]); $marca = $m->fetch(PDO::FETCH_ASSOC);
if (!$marca) { http_response_code(404); exit('Negocio no encontrado.'); }

// ── Acción: reintentar publicar un post (por el cliente) ──
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'retry_pub' && csrf_ok()) {
    require_once __DIR__ . '/../includes/publicador.php';
    @set_time_limit(120);
    $cid = (int)($_POST['contenido_id'] ?? 0);
    try {
        $r = publicar_pieza($pdo, $cid);
        $flash = !empty($r['ok']) ? ['ok', '✓ Reintento OK — salió a las redes.'] : ['err', 'No salió: ' . ($r['motivo'] ?: 'sin detalle')];
    } catch (Throwable $e) { $flash = ['err', 'Error: ' . substr($e->getMessage(), 0, 160)]; }
}
// ── Acción: editar la marca del cliente (por él) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'editar_marca' && csrf_ok()) {
    $prods = array_values(array_filter(array_map('trim', explode("\n", (string)($_POST['productos'] ?? '')))));
    $pref  = in_array($_POST['contacto_preferencia'] ?? '', ['whatsapp','dm','ambas','todas'], true) ? $_POST['contacto_preferencia'] : null;
    $pdo->prepare("UPDATE crecer_marca SET descripcion=?, voz=?, productos=?, whatsapp=?, contacto_preferencia=?, updated_at=NOW() WHERE id=?")
        ->execute([trim($_POST['descripcion'] ?? '') ?: null, trim($_POST['voz'] ?? '') ?: null,
                   $prods ? json_encode($prods, JSON_UNESCAPED_UNICODE) : null,
                   trim($_POST['whatsapp'] ?? '') ?: null, $pref, $mid]);
    header("Location: ?marca={$mid}&ok=marca"); exit;
}
// ── Acción: forzar email verificado (desbloquea login) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'verificar' && csrf_ok()) {
    $pdo->prepare("UPDATE usuarios SET verificado=1, verif_token=NULL WHERE id=(SELECT usuario_id FROM crecer_marca WHERE id=?)")->execute([$mid]);
    header("Location: ?marca={$mid}&ok=verif"); exit;
}
if (isset($_GET['ok'])) {
    $flash = ['ok', $_GET['ok']==='marca' ? '✓ Marca actualizada.' : ($_GET['ok']==='verif' ? '✓ Cuenta marcada como verificada (ya puede entrar).' : '✓ Listo.')];
}
$owner = $pdo->prepare("SELECT id, nombre, email, verificado FROM usuarios WHERE id=?"); $owner->execute([(int)$marca['usuario_id']]); $owner = $owner->fetch(PDO::FETCH_ASSOC);

// ── Conexión Meta + verificación EN VIVO del token ──
$conx = $pdo->prepare("SELECT * FROM crecer_conexiones WHERE marca_id=?"); $conx->execute([$mid]); $conx = $conx->fetch(PDO::FETCH_ASSOC);
$tok_estado = 'sin_conexion'; $tok_msg = '';
if ($conx && !empty($conx['page_access_token'])) {
    @set_time_limit(60);
    try {
        if (!empty($conx['fb_page_id']))       meta_api('GET', (string)$conx['fb_page_id'], ['fields'=>'name','access_token'=>$conx['page_access_token']]);
        elseif (!empty($conx['ig_user_id']))   meta_api('GET', (string)$conx['ig_user_id'], ['fields'=>'username','access_token'=>$conx['page_access_token']]);
        else throw new RuntimeException('Conexión sin Página de FB ni cuenta de IG.');
        $tok_estado = 'vivo';
    } catch (Throwable $e) { $tok_estado = 'malo'; $tok_msg = substr($e->getMessage(), 0, 200); }
}

// ── Datos para las tarjetas ──
$fallidos = $pdo->prepare("SELECT id, caption, plataforma, plataformas, pub_error FROM crecer_contenido WHERE marca_id=? AND estado='fallido' ORDER BY id DESC LIMIT 12");
$fallidos->execute([$mid]); $fallidos = $fallidos->fetchAll(PDO::FETCH_ASSOC);
$pubs = $pdo->prepare("SELECT p.plataforma, p.estado, p.error_msg, p.permalink, p.created_at, p.contenido_id FROM crecer_publicaciones p WHERE p.marca_id=? ORDER BY p.id DESC LIMIT 12");
$pubs->execute([$mid]); $pubs = $pubs->fetchAll(PDO::FETCH_ASSOC);
$logs = $pdo->prepare("SELECT agente, accion, modelo, estado, error_msg, created_at FROM crecer_ia_log WHERE marca_id=? ORDER BY id DESC LIMIT 14");
$logs->execute([$mid]); $logs = $logs->fetchAll(PDO::FETCH_ASSOC);

// ── Costo, uso y margen (mes en curso / semana) ──
$mes_ini   = date('Y-m-01 00:00:00');
$q = $pdo->prepare("SELECT COALESCE(SUM(costo_usd),0) FROM crecer_ia_log WHERE marca_id=? AND created_at>=?"); $q->execute([$mid,$mes_ini]);
$costo_mes = (float)$q->fetchColumn();
$q = $pdo->prepare("SELECT COALESCE(SUM(costo_usd),0) FROM crecer_ia_log WHERE marca_id=? AND created_at>=? AND accion LIKE 'Crear arte%'"); $q->execute([$mid,$mes_ini]);
$costo_img = (float)$q->fetchColumn();
$costo_txt = max(0, $costo_mes - $costo_img);
// Ingreso = MRR del plan activo (trial cuenta como potencial, se marca aparte).
$sub = $pdo->prepare("SELECT s.estado, p.precio_mensual, p.nombre FROM crecer_suscripciones s LEFT JOIN crecer_planes p ON p.id=s.plan_id WHERE s.marca_id=?");
$sub->execute([$mid]); $sub = $sub->fetch(PDO::FETCH_ASSOC);
$rev_activo = ($sub && ($sub['estado'] ?? '')==='activa') ? (float)$sub['precio_mensual'] : 0;
$en_trial   = ($sub && ($sub['estado'] ?? '')==='trial');
$margen     = $rev_activo - $costo_mes;
// Uso esta semana (posts publicados que consumen cupo + imágenes generadas).
$posts_sem = 0; try { $q=$pdo->prepare("SELECT COUNT(*) FROM crecer_publicacion_cupo WHERE marca_id=? AND created_at>=(NOW()-INTERVAL 7 DAY)"); $q->execute([$mid]); $posts_sem=(int)$q->fetchColumn(); } catch (Throwable $e) {}
$img_sem = 0;  try { $q=$pdo->prepare("SELECT COUNT(*) FROM crecer_graficas WHERE marca_id=? AND created_at>=(NOW()-INTERVAL 7 DAY)"); $q->execute([$mid]); $img_sem=(int)$q->fetchColumn(); } catch (Throwable $e) {}
$cupo_sem = defined('CRECER_POSTS_SEMANA') ? (int)CRECER_POSTS_SEMANA : 5;

$csrf = csrf_token();
$reconectar = "/crecer/panel/conectar.php?marca={$mid}";
?>
<!DOCTYPE html>
<html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Diagnóstico · <?= $h($marca['nombre_negocio']) ?> — Operaciones</title>
<link href="/crecer/assets/encuentralo-ui.css?v=20" rel="stylesheet">
<style>
  *{box-sizing:border-box} body{background:var(--crema,#fbf6ee);color:var(--tinta,#1b1622);font-family:'Plus Jakarta Sans',system-ui,sans-serif;margin:0}
  .top{display:flex;align-items:center;flex-wrap:wrap;gap:10px 14px;padding:14px 20px;background:#140a16;color:#fff}
  .top a{color:#cdc5d6;text-decoration:none;font-weight:700;font-size:13.5px}
  .top b{font-family:'Poppins',sans-serif;text-transform:uppercase;letter-spacing:.03em;font-size:16px}
  .wrap{max-width:900px;margin:0 auto;padding:20px 18px 70px}
  h1{font-family:'Poppins',sans-serif;text-transform:uppercase;font-size:26px;margin:8px 0 2px}
  .sub{color:var(--muted);font-size:13.5px;margin:0 0 16px}
  .card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:16px 18px;margin-bottom:14px;box-shadow:var(--shadow-sm)}
  .card h2{font-family:'Poppins',sans-serif;text-transform:uppercase;font-size:15px;letter-spacing:.03em;margin:0 0 12px;display:flex;align-items:center;gap:8px}
  .st{display:inline-flex;align-items:center;gap:6px;font-weight:800;font-size:12.5px;border-radius:99px;padding:4px 11px}
  .st.ok{background:#e6f6ee;color:#0d7a44}.st.bad{background:#fdeaea;color:#b42318}.st.warn{background:#fff4d6;color:#8a5a00}.st.none{background:#f1edf5;color:#7a7088}
  .row{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border-bottom:1px solid var(--line);font-size:13.5px}.row:last-child{border-bottom:0}
  .row .k{color:var(--muted)} .mono{font-family:ui-monospace,monospace;font-size:12px;color:#b42318;word-break:break-word}
  .item{padding:10px 0;border-bottom:1px solid var(--line);font-size:13px}.item:last-child{border-bottom:0}
  .item .cap{color:#473b46;line-height:1.35;display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden}
  .err{color:#b42318;font-size:12px;margin-top:3px;word-break:break-word}
  .btn{border:0;cursor:pointer;font-family:inherit;font-weight:800;font-size:12.5px;color:#fff;background:var(--palma,#16b86a);padding:7px 13px;border-radius:9px}
  .btn.ghost{background:#fff;color:var(--tinta);border:1.5px solid var(--line)}
  .flash{border-radius:12px;padding:11px 15px;font-weight:700;font-size:13.5px;margin-bottom:14px}
  .flash.ok{background:#e6f6ee;border:1px solid #b9eccf;color:#0d7a44}.flash.err{background:#fdeaea;border:1px solid #f5c2c0;color:#b42318}
  .hint{background:#fff4d6;border:1px solid #f2d488;color:#8a5a00;border-radius:10px;padding:10px 13px;font-size:12.5px;line-height:1.5;margin-top:10px}
  .tag{font-size:11px;font-weight:800;color:#7a7088;background:#f1edf5;border-radius:99px;padding:2px 8px}
  .tag.e{color:#b42318;background:#fdeaea}
</style></head>
<body>
<?php $op_active='analitica'; require __DIR__.'/_ops_top.php'; ?>
<div class="wrap">
  <div style="display:flex;align-items:baseline;gap:12px;flex-wrap:wrap;margin-top:8px">
    <h1 style="margin:0"><?= $h($marca['nombre_negocio']) ?></h1>
    <a href="/crecer/panel/index.php?marca=<?= $mid ?>" style="color:var(--terracota);font-weight:800;font-size:13px;text-decoration:none">Abrir su panel →</a>
  </div>
  <p class="sub">Diagnóstico — estado real para resolver "no postea" o "la IA hizo algo raro".</p>
  <?php if ($flash): ?><div class="flash <?= $flash[0] ?>"><?= $h($flash[1]) ?></div><?php endif; ?>

  <!-- COSTO, USO Y MARGEN -->
  <?php $money = fn($n)=>'$'.number_format((float)$n,2); ?>
  <div class="card">
    <h2><?= ico('chart') ?> Costo, uso y margen · este mes</h2>
    <div class="row"><span class="k">Ingreso (plan)</span><span><?php
      if ($rev_activo>0) echo '<b>'.$money($rev_activo).'</b>/mes';
      elseif ($en_trial) echo '<span class="st warn">en prueba</span> '.($sub['precio_mensual']?('($'.number_format((float)$sub['precio_mensual'],0).' al cobrar)'):'');
      else echo '<span class="st none">sin plan pago</span>';
    ?></span></div>
    <div class="row"><span class="k">Costo IA del mes</span><span><b><?= $money($costo_mes) ?></b> <span style="color:var(--muted);font-size:12px">(imagen <?= $money($costo_img) ?> · texto <?= $money($costo_txt) ?>)</span></span></div>
    <div class="row"><span class="k">Margen</span><span><?php
      if ($rev_activo>0) { $cls=$margen>=0?'ok':'bad'; echo '<span class="st '.$cls.'">'.($margen>=0?'✓ ':'✕ ').$money($margen).'</span>'; }
      else echo '<span class="st none">— (sin cobro este mes)</span>';
    ?></span></div>
    <div class="row"><span class="k">Uso esta semana</span><span><b><?= $posts_sem ?>/<?= $cupo_sem ?></b> posts · <b><?= $img_sem ?></b> imágenes IA</span></div>
    <?php if ($rev_activo>0 && $margen<0): ?><div class="hint"><?= ico('bolt') ?> Este cliente está <b>en pérdida</b> este mes (gasta más en IA de lo que paga). Revisa si está generando imágenes de más.</div><?php endif; ?>
  </div>

  <!-- CONEXIÓN META -->
  <div class="card">
    <h2><?= ico('bolt') ?> Conexión de redes (Meta)</h2>
    <?php if (!$conx): ?>
      <span class="st none">Sin conexión</span>
      <div class="hint">Este cliente no ha conectado redes. Solo él puede hacerlo: pídele que entre a <b>Configuración → Conectar redes</b>.</div>
    <?php else: ?>
      <div class="row"><span class="k">Instagram</span><span><?= !empty($conx['ig_user_id']) ? '@'.$h($conx['ig_username'] ?: $conx['ig_user_id']) : '<span class="st none">no</span>' ?></span></div>
      <div class="row"><span class="k">Página de Facebook</span><span><?= !empty($conx['fb_page_id']) ? $h($conx['fb_page_nombre'] ?: $conx['fb_page_id']) : '<span class="st bad">falta</span>' ?></span></div>
      <div class="row"><span class="k">Token (verificado en vivo)</span><span>
        <?php if ($tok_estado==='vivo'): ?><span class="st ok">✓ vivo</span>
        <?php elseif ($tok_estado==='malo'): ?><span class="st bad">✕ con problema</span>
        <?php else: ?><span class="st none">sin token</span><?php endif; ?>
      </span></div>
      <div class="row"><span class="k">Vence</span><span><?= $conx['token_expira'] ? $h(date('d/m/Y', strtotime($conx['token_expira']))) : 'sin fecha' ?></span></div>
      <?php if (!empty($conx['ultimo_error'])): ?><div class="row"><span class="k">Último error guardado</span><span class="mono"><?= $h(mb_substr($conx['ultimo_error'],0,140)) ?></span></div><?php endif; ?>
      <?php if ($tok_estado==='malo' || empty($conx['fb_page_id'])): ?>
        <div class="hint">
          <?php if (empty($conx['fb_page_id'])): ?><b>Falta la Página de Facebook</b> → por eso publica solo en IG. <?php endif; ?>
          <?php if ($tok_estado==='malo'): ?><b>El token falló:</b> <span class="mono"><?= $h($tok_msg) ?></span><br><?php endif; ?>
          Esto <b>solo lo arregla el cliente</b>: dile que vaya a <b>Configuración → Conectar redes</b>, acepte TODOS los permisos y elija su Página.
          (Su link: <a href="<?= $reconectar ?>" style="color:#8a5a00;font-weight:800"><?= $h($reconectar) ?></a> — desde su propia sesión.)
        </div>
      <?php endif; ?>
    <?php endif; ?>
    <div style="margin-top:12px"><a class="btn ghost" href="?marca=<?= $mid ?>"><?= ico('refresh') ?> Verificar de nuevo</a></div>
  </div>

  <!-- POSTS FALLIDOS + REINTENTAR -->
  <div class="card">
    <h2><?= ico('bolt') ?> Posts fallidos (<?= count($fallidos) ?>)</h2>
    <?php if (!$fallidos): ?><p class="sub" style="margin:0">Ninguno fallido ahora mismo.</p>
    <?php else: foreach ($fallidos as $f): ?>
      <div class="item" style="display:flex;align-items:center;gap:10px;justify-content:space-between">
        <div style="min-width:0">
          <div class="cap"><?= $h(mb_strimwidth((string)($f['caption'] ?: '(sin texto)'),0,70,'…')) ?></div>
          <?php if (!empty($f['pub_error'])): ?><div class="err"><?= $h(mb_substr($f['pub_error'],0,160)) ?></div><?php endif; ?>
        </div>
        <form method="post" style="flex:none" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Publicando…'">
          <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="accion" value="retry_pub"><input type="hidden" name="contenido_id" value="<?= (int)$f['id'] ?>">
          <button type="submit" class="btn">Reintentar</button>
        </form>
      </div>
    <?php endforeach; endif; ?>
    <div class="hint">Reintentar re-publica <b>solo</b> lo que faltó (no duplica lo que ya salió). Si falla por token/permisos, el cliente debe reconectar.</div>
  </div>

  <!-- ÚLTIMOS INTENTOS -->
  <div class="card">
    <h2><?= ico('calendar') ?> Últimos intentos de publicación</h2>
    <?php if (!$pubs): ?><p class="sub" style="margin:0">Todavía no hay intentos registrados.</p>
    <?php else: foreach ($pubs as $p): ?>
      <div class="item">
        <span class="tag <?= $p['estado']==='error'?'e':'' ?>"><?= $h($p['plataforma']) ?> · <?= $h($p['estado']) ?></span>
        <span style="color:var(--muted);font-size:11.5px;margin-left:6px"><?= $h(date('d/m H:i', strtotime($p['created_at']))) ?></span>
        <?php if ($p['estado']==='error' && !empty($p['error_msg'])): ?><div class="err"><?= $h(mb_substr($p['error_msg'],0,180)) ?></div><?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- IA RECIENTE -->
  <div class="card">
    <h2><?= ico('sparkles') ?> Actividad de IA reciente</h2>
    <?php if (!$logs): ?><p class="sub" style="margin:0">Sin actividad de IA.</p>
    <?php else: foreach ($logs as $l): ?>
      <div class="item">
        <span class="tag <?= $l['estado']==='error'?'e':'' ?>"><?= $h($l['agente']) ?></span>
        <span style="font-size:12.5px;color:var(--tinta)"> <?= $h(mb_strimwidth((string)$l['accion'],0,64,'…')) ?></span>
        <span style="color:var(--muted);font-size:11.5px;margin-left:6px"><?= $h(date('d/m H:i', strtotime($l['created_at']))) ?></span>
        <?php if ($l['estado']==='error' && !empty($l['error_msg'])): ?><div class="err"><?= $h(mb_substr($l['error_msg'],0,180)) ?></div><?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- EDITAR MARCA + CUENTA -->
  <?php
    $prods_txt = '';
    $pj = json_decode((string)$marca['productos'], true);
    if (is_array($pj)) $prods_txt = implode("\n", array_map(fn($x)=>is_array($x)?($x['nombre'] ?? '') : (string)$x, $pj));
    $pref_act = (string)($marca['contacto_preferencia'] ?? '');
  ?>
  <div class="card">
    <h2><?= ico('palette') ?> Editar su marca (por él)</h2>
    <form method="post" style="display:flex;flex-direction:column;gap:10px">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="accion" value="editar_marca">
      <label style="font-size:12.5px;font-weight:800;color:var(--muted)">Descripción<textarea name="descripcion" rows="2" style="width:100%;font-family:inherit;font-size:13.5px;border:1.5px solid var(--line);border-radius:10px;padding:9px 11px;margin-top:4px"><?= $h($marca['descripcion']) ?></textarea></label>
      <label style="font-size:12.5px;font-weight:800;color:var(--muted)">Voz / tono<textarea name="voz" rows="2" style="width:100%;font-family:inherit;font-size:13.5px;border:1.5px solid var(--line);border-radius:10px;padding:9px 11px;margin-top:4px"><?= $h($marca['voz']) ?></textarea></label>
      <label style="font-size:12.5px;font-weight:800;color:var(--muted)">Productos (uno por línea)<textarea name="productos" rows="3" style="width:100%;font-family:inherit;font-size:13.5px;border:1.5px solid var(--line);border-radius:10px;padding:9px 11px;margin-top:4px"><?= $h($prods_txt) ?></textarea></label>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <label style="font-size:12.5px;font-weight:800;color:var(--muted);flex:1;min-width:150px">WhatsApp<input type="tel" name="whatsapp" value="<?= $h($marca['whatsapp']) ?>" placeholder="787-000-0000" style="width:100%;font-family:inherit;font-size:13.5px;border:1.5px solid var(--line);border-radius:10px;padding:9px 11px;margin-top:4px"></label>
        <label style="font-size:12.5px;font-weight:800;color:var(--muted);flex:1;min-width:150px">Contacto preferido<select name="contacto_preferencia" style="width:100%;font-family:inherit;font-size:13.5px;border:1.5px solid var(--line);border-radius:10px;padding:9px 11px;margin-top:4px">
          <option value="" <?= $pref_act===''?'selected':'' ?>>— sin definir</option>
          <?php foreach (['dm'=>'DM','whatsapp'=>'WhatsApp','ambas'=>'Ambas','todas'=>'Cualquiera'] as $v=>$lb): ?>
            <option value="<?= $v ?>" <?= $pref_act===$v?'selected':'' ?>><?= $lb ?></option>
          <?php endforeach; ?>
        </select></label>
      </div>
      <button type="submit" class="btn" style="align-self:flex-start">Guardar marca</button>
    </form>
  </div>

  <!-- CUENTA -->
  <div class="card">
    <h2><?= ico('users') ?> Cuenta del dueño</h2>
    <div class="row"><span class="k"><?= $h($owner['nombre'] ?? '') ?></span><span style="color:var(--muted)"><?= $h($owner['email'] ?? '') ?></span></div>
    <div class="row"><span class="k">Email</span><span>
      <?php if (!empty($owner['verificado'])): ?><span class="st ok">✓ verificado</span>
      <?php else: ?><span class="st warn">sin verificar</span>
        <form method="post" style="display:inline;margin-left:8px" onsubmit="return confirm('¿Marcar como verificado? Le desbloquea el login.')">
          <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="accion" value="verificar">
          <button type="submit" class="btn ghost">Marcar verificado</button>
        </form>
      <?php endif; ?>
    </span></div>
    <div class="hint">¿No puede entrar por contraseña? Dile que use <b>"¿Olvidaste tu contraseña?"</b> en el login (<a href="/crecer/recuperar.php" style="color:#8a5a00;font-weight:800">recuperar.php</a>).</div>
  </div>
</div>
</body></html>
