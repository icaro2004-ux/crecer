<?php
// ============================================================
//  CRECER — Operaciones · CASOS del Ayudante  (solo admin)
//  panel/admin_incidencias.php
//
//  Todo lo que el Ayudante no pudo arreglar solo cae aquí, con el
//  diagnóstico, lo técnico, si el aviso (email/SMS) salió, y el botón
//  para volver a intentar el arreglo o cerrar el caso.
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/iconos.php';
require_once __DIR__ . '/../includes/ayudante.php';
requiere_login();
$usuario = usuario_actual($pdo);
if (($usuario['rol'] ?? '') !== 'admin') { http_response_code(403); exit('Acceso solo para administradores.'); }
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $id = (int)($_POST['id'] ?? 0);
    $acc = (string)($_POST['accion'] ?? '');
    $s = $pdo->prepare("SELECT * FROM crecer_incidencias WHERE id=?");
    $s->execute([$id]);
    $inc = $s->fetch(PDO::FETCH_ASSOC);
    if ($inc) {
        if ($acc === 'cerrar') {
            $pdo->prepare("UPDATE crecer_incidencias SET estado='cerrada', cerrada_at=NOW() WHERE id=?")->execute([$id]);
            $flash = ['ok', 'Caso #' . $id . ' cerrado.'];
        } elseif ($acc === 'reintentar' && $inc['accion'] && $inc['marca_id']) {
            $r = ayudante_arreglar($pdo, (int)$inc['marca_id'], (string)$inc['accion'],
                                   $inc['ref_id'] !== null ? (int)$inc['ref_id'] : null);
            $pdo->prepare("UPDATE crecer_incidencias SET intentos=intentos+1, resultado=?,
                           estado=IF(?, 'resuelta_auto', estado), cerrada_at=IF(?, NOW(), cerrada_at) WHERE id=?")
                ->execute([$r['tecnico'], $r['ok'] ? 1 : 0, $r['ok'] ? 1 : 0, $id]);
            $flash = [$r['ok'] ? 'ok' : 'bad', 'Caso #' . $id . ': ' . $r['msg'] . ' (' . $r['tecnico'] . ')'];
        }
    }
}
$csrf = csrf_token();

$ver = (string)($_GET['ver'] ?? 'abiertas');
$where = $ver === 'todas' ? '1' : "i.estado IN ('abierta','escalada')";
$rows = $pdo->query(
    "SELECT i.*, m.nombre_negocio
       FROM crecer_incidencias i
  LEFT JOIN crecer_marca m ON m.id = i.marca_id
      WHERE {$where}
   ORDER BY i.id DESC LIMIT 120")->fetchAll(PDO::FETCH_ASSOC);
$abiertas = (int)$pdo->query("SELECT COUNT(*) FROM crecer_incidencias WHERE estado IN ('abierta','escalada')")->fetchColumn();
$cont = ayudante_contacto_fundador();
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Casos del Ayudante — Operaciones</title>
<link href="/crecer/assets/encuentralo-ui.css?v=<?= ASSET_VER ?>" rel="stylesheet">
<style>
  *{box-sizing:border-box} body{background:var(--crema,#fbf6ee);color:var(--tinta,#1b1622);font-family:'Plus Jakarta Sans',system-ui,sans-serif;margin:0}
  .wrap{max-width:960px;margin:0 auto;padding:20px 18px 70px}
  h1{font-family:'Poppins',sans-serif;text-transform:uppercase;font-size:26px;margin:8px 0 4px}
  .sub{color:var(--muted);font-size:13.5px;margin:0 0 16px;max-width:62ch;line-height:1.5}
  .tabs{display:flex;gap:8px;margin-bottom:14px}
  .tabs a{text-decoration:none;font-weight:800;font-size:12.5px;padding:8px 14px;border-radius:99px;border:1px solid var(--line);background:#fff;color:var(--muted)}
  .tabs a.on{background:var(--tinta,#1b1622);color:#fff;border-color:var(--tinta,#1b1622)}
  .card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:16px 18px;margin-bottom:12px;box-shadow:var(--shadow-sm)}
  .ch{display:flex;align-items:flex-start;gap:10px;flex-wrap:wrap}
  .ch .t{font-family:'Poppins',sans-serif;font-size:15.5px;font-weight:600;margin:0;flex:1;min-width:220px}
  .pill{display:inline-block;font-size:11px;font-weight:900;border-radius:99px;padding:3px 9px;white-space:nowrap}
  .pill.alta{background:#fdeaea;color:#b42318}.pill.media{background:#fff4d6;color:#8a5a00}.pill.baja{background:#eef1f6;color:#5b6472}
  .pill.esc{background:#f1edf5;color:#6b4a86}.pill.res{background:#e6f6ee;color:#0d7a44}.pill.cer{background:#eef1f6;color:#5b6472}
  .meta{color:var(--muted);font-size:12px;margin:6px 0 10px}
  .diag{font-size:13.5px;line-height:1.55;margin:0 0 10px}
  pre{margin:0 0 10px;padding:11px;background:#f6f4f1;border-radius:10px;font-size:11.5px;white-space:pre-wrap;word-break:break-word;color:#3f3a4a}
  .av{font-size:11.5px;color:var(--muted);margin-bottom:10px}
  .av b.si{color:#0d7a44}.av b.no{color:#b42318}
  .acts{display:flex;gap:8px;flex-wrap:wrap}
  .acts button{border:0;cursor:pointer;font-family:inherit;font-weight:800;font-size:12.5px;padding:9px 14px;border-radius:10px}
  .acts .re{background:var(--teal-700,#00A49F);color:#fff}
  .acts .ce{background:#fff;border:1px solid var(--line);color:var(--muted)}
  .fl{border-radius:12px;padding:11px 15px;margin-bottom:14px;font-weight:700;font-size:13.5px}
  .fl.ok{background:#e6f6ee;border:1px solid #b9eccf;color:#0d7a44}
  .fl.bad{background:#fdeaea;border:1px solid #f3c9c9;color:#b42318}
  .vacio{text-align:center;color:var(--muted);font-size:14px;padding:40px 20px}
</style></head><body>
<?php $op_active='incidencias'; require __DIR__.'/_ops_top.php'; ?>
<div class="wrap">
  <h1>Casos del Ayudante</h1>
  <p class="sub">Lo que el Ayudante no pudo arreglar solo. Cada caso salió con aviso por email y texto.
     Avisos configurados: <b><?= $h($cont['email'] ?: 'FALTA CRECER_FUNDADOR_EMAIL') ?></b> ·
     <b><?= $h($cont['sms'] ?: 'FALTA CRECER_FUNDADOR_SMS') ?></b></p>

  <?php if ($flash): ?><div class="fl <?= $h($flash[0]) ?>"><?= $h($flash[1]) ?></div><?php endif; ?>

  <div class="tabs">
    <a class="<?= $ver!=='todas'?'on':'' ?>" href="?ver=abiertas">Sin resolver (<?= $abiertas ?>)</a>
    <a class="<?= $ver==='todas'?'on':'' ?>" href="?ver=todas">Todas</a>
  </div>

  <?php if (!$rows): ?>
    <div class="card vacio">Ningún caso pendiente. El Ayudante viene resolviendo lo que aparece.</div>
  <?php else: foreach ($rows as $r):
      $est = (string)$r['estado'];
      $pe = $est==='resuelta_auto' ? 'res' : ($est==='cerrada' ? 'cer' : 'esc');
  ?>
    <div class="card">
      <div class="ch">
        <p class="t">#<?= (int)$r['id'] ?> · <?= $h($r['titulo']) ?></p>
        <span class="pill <?= $h($r['severidad']) ?>"><?= $h(strtoupper((string)$r['severidad'])) ?></span>
        <span class="pill <?= $pe ?>"><?= $h($est) ?></span>
      </div>
      <div class="meta">
        <?= $h($r['nombre_negocio'] ?: 'Plataforma') ?> ·
        <?= $h($r['codigo']) ?><?= $r['ref_id'] ? ' #'.(int)$r['ref_id'] : '' ?> ·
        origen <?= $h($r['origen']) ?> ·
        <?= $h(date('d/m/Y H:i', strtotime((string)$r['created_at']))) ?> ·
        <?= (int)$r['intentos'] ?> intento(s)
      </div>
      <?php if ($r['diagnostico']): ?><p class="diag"><?= nl2br($h($r['diagnostico'])) ?></p><?php endif; ?>
      <?php if ($r['detalle']): ?><pre><?= $h($r['detalle']) ?></pre><?php endif; ?>
      <?php if ($r['resultado']): ?><pre>intento: <?= $h($r['resultado']) ?></pre><?php endif; ?>
      <div class="av">
        Aviso · email <b class="<?= $r['aviso_email'] ? 'si':'no' ?>"><?= $r['aviso_email'] ? 'salió':'no salió' ?></b>
        · SMS <b class="<?= $r['aviso_sms'] ? 'si':'no' ?>"><?= $r['aviso_sms'] ? 'salió':'no salió' ?></b>
        <?= $r['aviso_error'] ? ' — '.$h($r['aviso_error']) : '' ?>
      </div>
      <?php if ($est !== 'cerrada'): ?>
      <div class="acts">
        <?php if ($r['accion'] && $r['marca_id']): ?>
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <input type="hidden" name="accion" value="reintentar">
          <button class="re" type="submit">Volver a intentar (<?= $h($r['accion']) ?>)</button>
        </form>
        <?php endif; ?>
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <input type="hidden" name="accion" value="cerrar">
          <button class="ce" type="submit">Cerrar caso</button>
        </form>
        <?php if ($r['marca_id']): ?>
          <a class="acts" style="align-self:center;font-size:12.5px;font-weight:800;color:var(--teal-700,#00A49F)"
             href="/crecer/panel/admin_soporte.php">Contestarle al cliente &rsaquo;</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  <?php endforeach; endif; ?>
</div>
</body></html>
