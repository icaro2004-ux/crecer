<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Órdenes & Agenda (el flywheel)
//  panel/ordenes.php
// ============================================================
require __DIR__ . '/../includes/db.php';

$marca_id = (int)($_GET['marca'] ?? 1);
$m = $pdo->prepare("SELECT * FROM crecer_marca WHERE id = ?");
$m->execute([$marca_id]);
$marca = $m->fetch();
if (!$marca) { http_response_code(404); exit('Negocio no encontrado.'); }

// ── POST (PRG) ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'crear' && trim($_POST['cliente_nombre'] ?? '') !== '') {
        $st = $pdo->prepare("INSERT INTO crecer_ordenes
            (marca_id, cliente_nombre, cliente_contacto, descripcion, monto, fecha_entrega, estado)
            VALUES (?,?,?,?,?,?, 'recibida')");
        $st->execute([
            $marca_id,
            trim($_POST['cliente_nombre']),
            trim($_POST['cliente_contacto'] ?? '') ?: null,
            trim($_POST['descripcion'] ?? '') ?: null,
            ($_POST['monto'] ?? '') !== '' ? (float)$_POST['monto'] : null,
            ($_POST['fecha_entrega'] ?? '') !== '' ? str_replace('T',' ',$_POST['fecha_entrega']).':00' : null,
        ]);
    } elseif ($accion === 'estado') {
        $id = (int)($_POST['id'] ?? 0);
        $nuevo = $_POST['estado'] ?? '';
        if (in_array($nuevo, ['recibida','en_proceso','completada','cancelada'], true)) {
            $pdo->prepare("UPDATE crecer_ordenes SET estado=? WHERE id=? AND marca_id=?")->execute([$nuevo,$id,$marca_id]);
        }
    } elseif ($accion === 'review') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE crecer_ordenes SET review_solicitada=1 WHERE id=? AND marca_id=?")->execute([$id,$marca_id]);
    }
    header('Location: ' . $_SERVER['REQUEST_URI']); exit;
}

// ── Datos ────────────────────────────────────────────────────
$all = $pdo->prepare("SELECT * FROM crecer_ordenes WHERE marca_id=? ORDER BY
    FIELD(estado,'en_proceso','recibida','completada','cancelada'), fecha_entrega IS NULL, fecha_entrega, created_at DESC");
$all->execute([$marca_id]);
$ordenes = $all->fetchAll();

$grupos = ['recibida'=>[], 'en_proceso'=>[], 'completada'=>[], 'cancelada'=>[]];
$agenda = [];
$ingresos_mes = 0.0;
foreach ($ordenes as $o) {
    $grupos[$o['estado']][] = $o;
    if (in_array($o['estado'],['recibida','en_proceso'],true) && $o['fecha_entrega']) $agenda[] = $o;
    if ($o['estado']==='completada' && $o['monto'] && date('Y-m',strtotime($o['updated_at']))===date('Y-m')) $ingresos_mes += (float)$o['monto'];
}
usort($agenda, fn($a,$b)=>strcmp($a['fecha_entrega'],$b['fecha_entrega']));

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$pill = ['recibida'=>['Recibida','wait'],'en_proceso'=>['En proceso','pub'],'completada'=>['Completada','ok'],'cancelada'=>['Cancelada','no']];
function dia_rel($f){ if(!$f) return ''; $d=floor((strtotime(date('Y-m-d',strtotime($f)))-strtotime(date('Y-m-d')))/86400);
  if($d<0) return 'atrasada'; if($d===0.0) return 'hoy'; if($d==1) return 'mañana'; return "en $d días"; }
function wa_link($c,$msg){ $n=preg_replace('/\D/','',(string)$c); if(strlen($n)<10) return null;
  if(strlen($n)==10) $n='1'.$n; return "https://wa.me/$n?text=".rawurlencode($msg); }

$active = 'ordenes';
$page_title = 'Órdenes & Agenda';
require __DIR__ . '/_shell.php';
?>
<style>
  .ohead{display:flex;align-items:flex-end;justify-content:space-between;gap:14px;flex-wrap:wrap}
  .new-btn{background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff;border:0;cursor:pointer;
    font-family:inherit;font-weight:800;font-size:14px;padding:12px 20px;border-radius:99px}
  .agenda{display:flex;gap:10px;overflow-x:auto;padding:4px 0 6px;margin-top:18px}
  .ag{flex:none;background:var(--card);border:1px solid var(--line);border-radius:14px;padding:12px 14px;min-width:150px}
  .ag .when{font-weight:800;font-size:12px;color:var(--terracota);text-transform:uppercase;letter-spacing:.03em}
  .ag .when.late{color:var(--noo-ink)}
  .ag .cl{font-weight:700;font-size:14px;margin:3px 0 1px}
  .ag .ds{font-size:12.5px;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

  .colgrid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:8px}
  .col h3{font-family:var(--font-display);font-weight:800;font-size:15px;margin:0 0 10px;display:flex;gap:8px;align-items:center}
  .col h3 .cnt{font-size:12px;color:var(--muted);background:var(--crema-2);border-radius:99px;padding:2px 9px;font-weight:700}
  .ord{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:14px;margin-bottom:12px;box-shadow:var(--shadow-sm)}
  .ord .top{display:flex;justify-content:space-between;align-items:center;gap:8px}
  .ord .cl{font-weight:800;font-size:15px}
  .ord .mo{font-family:var(--font-display);font-weight:800;color:var(--palma)}
  .ord .ds{font-size:13.5px;color:#4a3f37;margin:6px 0}
  .ord .meta{font-size:12px;color:var(--muted);display:flex;gap:10px;flex-wrap:wrap;margin-bottom:8px}
  .ord .acts{display:flex;gap:7px;flex-wrap:wrap;border-top:1px solid var(--line);padding-top:10px}
  .ord .acts button,.ord .acts a{font-family:inherit;font-weight:700;font-size:12.5px;cursor:pointer;border-radius:99px;
    padding:7px 13px;border:1.5px solid var(--line);background:var(--card);color:var(--tinta);text-decoration:none}
  .ord .acts .go{background:var(--palma);color:#fff;border-color:transparent}
  .ord .acts .rev{background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff;border-color:transparent}
  .ord .acts .mut{color:var(--muted)}
  .empty-c{font-size:13px;color:var(--muted);padding:8px;text-align:center}

  /* modal nueva orden */
  .modal{display:none;position:fixed;inset:0;background:rgba(20,12,8,.45);z-index:60;align-items:flex-start;justify-content:center;padding:40px 16px;overflow:auto}
  .modal.show{display:flex}
  .modal .box{background:var(--card);border-radius:var(--r-xl);padding:26px;max-width:460px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.25)}
  .modal h2{font-family:var(--font-display);font-weight:800;font-size:22px;letter-spacing:-.02em;margin-bottom:4px}
  .modal label{display:block;font-weight:700;font-size:13.5px;margin:14px 0 6px}
  .modal input,.modal textarea{width:100%;font-family:inherit;font-size:15px;border:1.5px solid var(--line);border-radius:12px;padding:11px 13px;background:#fff}
  .modal .r2{display:flex;gap:12px}.modal .r2>div{flex:1}
  .modal .save{margin-top:18px;width:100%;background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff;border:0;cursor:pointer;font-weight:800;font-size:15px;padding:14px;border-radius:99px}
  .modal .x{float:right;cursor:pointer;color:var(--muted);font-size:20px;border:0;background:none}
  @media (max-width:860px){.colgrid{grid-template-columns:1fr}}
</style>

<div class="ohead">
  <div>
    <h1 class="page-h">Órdenes & Agenda</h1>
    <p class="page-sub">Recibe, maneja y completa. Al terminar, pídele la reseña — así crece tu reputación. 🔁</p>
  </div>
  <button class="new-btn" onclick="document.getElementById('mod').classList.add('show')">+ Nueva orden</button>
</div>

<?php if ($agenda): ?>
  <div class="agenda">
    <?php foreach (array_slice($agenda,0,8) as $a): $rel=dia_rel($a['fecha_entrega']); ?>
      <div class="ag">
        <div class="when <?= $rel==='atrasada'?'late':'' ?>">📅 <?= $rel ?> · <?= date('d/m',strtotime($a['fecha_entrega'])) ?></div>
        <div class="cl"><?= $h($a['cliente_nombre']) ?></div>
        <div class="ds"><?= $h($a['descripcion'] ?: '—') ?></div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="colgrid">
  <?php
  $cols = ['en_proceso'=>'🔧 En proceso','recibida'=>'📥 Recibidas','completada'=>'✅ Completadas'];
  foreach ($cols as $estado=>$titulo):
  ?>
    <div class="col">
      <h3><?= $titulo ?> <span class="cnt"><?= count($grupos[$estado]) ?></span></h3>
      <?php if (!$grupos[$estado]): ?><div class="empty-c">Nada por aquí.</div><?php endif; ?>
      <?php foreach ($grupos[$estado] as $o): ?>
        <div class="ord">
          <div class="top">
            <span class="cl"><?= $h($o['cliente_nombre']) ?></span>
            <?php if ($o['monto']!==null): ?><span class="mo">$<?= number_format((float)$o['monto'],2) ?></span><?php endif; ?>
          </div>
          <?php if ($o['descripcion']): ?><div class="ds"><?= $h($o['descripcion']) ?></div><?php endif; ?>
          <div class="meta">
            <?php if ($o['fecha_entrega']): ?><span>📅 <?= date('d/m H:i',strtotime($o['fecha_entrega'])) ?></span><?php endif; ?>
            <?php if ($o['cliente_contacto']): ?><span>📱 <?= $h($o['cliente_contacto']) ?></span><?php endif; ?>
          </div>
          <div class="acts">
            <?php if ($estado==='recibida'): ?>
              <form method="post"><input type="hidden" name="accion" value="estado"><input type="hidden" name="id" value="<?= $o['id'] ?>"><input type="hidden" name="estado" value="en_proceso"><button>▶ Empezar</button></form>
              <form method="post"><input type="hidden" name="accion" value="estado"><input type="hidden" name="id" value="<?= $o['id'] ?>"><input type="hidden" name="estado" value="completada"><button class="go">✓ Completar</button></form>
            <?php elseif ($estado==='en_proceso'): ?>
              <form method="post"><input type="hidden" name="accion" value="estado"><input type="hidden" name="id" value="<?= $o['id'] ?>"><input type="hidden" name="estado" value="completada"><button class="go">✓ Completar</button></form>
            <?php elseif ($estado==='completada'): ?>
              <?php if (!$o['review_solicitada']):
                $msg = "¡Gracias por tu orden en {$marca['nombre_negocio']}! 🙌 ¿Nos regalas una reseña? Nos ayuda un montón a crecer. 🇵🇷";
                $wa = wa_link($o['cliente_contacto'], $msg); ?>
                <?php if ($wa): ?><a class="rev" href="<?= $h($wa) ?>" target="_blank" onclick="setTimeout(()=>this.closest('.ord').querySelector('.mark-rev').click(),300)">⭐ Pedir reseña (WhatsApp)</a><?php endif; ?>
                <form method="post" style="display:inline"><input type="hidden" name="accion" value="review"><input type="hidden" name="id" value="<?= $o['id'] ?>"><button class="mark-rev rev" style="<?= $wa?'display:none':'' ?>">⭐ Marcar reseña pedida</button></form>
              <?php else: ?>
                <span class="mut">⭐ Reseña pedida ✓</span>
              <?php endif; ?>
            <?php endif; ?>
            <?php if ($estado!=='completada'): ?>
              <form method="post"><input type="hidden" name="accion" value="estado"><input type="hidden" name="id" value="<?= $o['id'] ?>"><input type="hidden" name="estado" value="cancelada"><button class="mut">✕</button></form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>

<!-- Modal nueva orden -->
<div class="modal" id="mod">
  <div class="box">
    <button class="x" onclick="document.getElementById('mod').classList.remove('show')">✕</button>
    <h2>Nueva orden 📦</h2>
    <p style="color:var(--muted);font-size:14px">Apúntala aquí — entró por WhatsApp, llamada o en persona.</p>
    <form method="post">
      <input type="hidden" name="accion" value="crear">
      <label>Cliente *</label>
      <input name="cliente_nombre" required placeholder="Nombre del cliente">
      <div class="r2">
        <div><label>Contacto</label><input name="cliente_contacto" placeholder="787-555-0000"></div>
        <div><label>Monto ($)</label><input name="monto" type="number" step="0.01" placeholder="25.00"></div>
      </div>
      <label>¿Qué pidió?</label>
      <textarea name="descripcion" rows="2" placeholder="Ej. Bizcocho de guayaba para 20 personas"></textarea>
      <label>Fecha de entrega / cita</label>
      <input name="fecha_entrega" type="datetime-local">
      <button class="save" type="submit">Guardar orden</button>
    </form>
  </div>
</div>

<?php require __DIR__ . '/_shell_foot.php'; ?>
