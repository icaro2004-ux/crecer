<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Tu equipo del corillo
//  panel/equipo.php  ·  el dueño le pone nombre a cada agente (sentido de equipo)
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/agentes.php';
requiere_login();

$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }
$marca_id = (int)$marca['id'];
$roster = equipo_roster();
$guardado = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $nombres = [];
    foreach ($roster as $key => $_) {
        $v = trim((string)($_POST['nombre'][$key] ?? ''));
        $v = preg_replace('/\s+/', ' ', $v);
        if ($v !== '') $nombres[$key] = mb_substr($v, 0, 24);
    }
    $json = $nombres ? json_encode($nombres, JSON_UNESCAPED_UNICODE) : null;
    try {
        $pdo->prepare("UPDATE crecer_marca SET equipo_nombres = ? WHERE id = ?")->execute([$json, $marca_id]);
        $marca['equipo_nombres'] = $json;   // refleja lo guardado
        $guardado = true;
    } catch (Throwable $e) {
        // Falta la migración equipo_nombres en esta base → no reventar con 500.
        $guardar_err = 'No se pudo guardar (falta correr la migración de equipo en la base de datos).';
    }
}

$nombres = equipo_nombres($marca);
$active = 'equipo';
$page_title = 'Tu equipo';
require __DIR__ . '/_shell.php';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<style>
  .eq-head{margin-bottom:6px}
  .eq-h1{font-family:'Oswald',sans-serif;font-weight:700;font-size:24px;letter-spacing:.4px;color:var(--tinta);margin:0;line-height:1.1}
  .eq-sub{font-size:13.5px;color:var(--muted);margin:6px 0 18px;max-width:640px;line-height:1.5}
  .eq-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
  @media(max-width:640px){.eq-grid{grid-template-columns:1fr}}
  .eq-card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:15px 16px;box-shadow:var(--shadow-sm);display:flex;gap:13px;align-items:flex-start;transition:border-color .15s,transform .1s}
  .eq-card:focus-within{border-color:var(--magenta,#EF4375)}
  .eq-emoji{flex:none;width:46px;height:46px;display:grid;place-items:center;background:var(--crema,#F7F5F1);border-radius:13px}
  .eq-emoji svg{width:23px;height:23px;color:var(--magenta,#EF4375)}
  .eq-body{flex:1;min-width:0}
  .eq-rol{font-size:11.5px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;color:var(--muted)}
  .eq-name{width:100%;font-family:'Oswald',sans-serif;font-weight:700;font-size:19px;color:var(--tinta);border:0;border-bottom:2px dashed var(--line);background:transparent;padding:2px 0 4px;margin:1px 0 5px}
  .eq-name:focus{outline:0;border-bottom-color:var(--magenta,#EF4375)}
  .eq-name::placeholder{color:var(--tinta);opacity:.55;font-weight:600}
  .eq-hace{font-size:12.5px;color:var(--muted);line-height:1.4}
  .eq-save{position:sticky;bottom:0;background:linear-gradient(to top,var(--crema) 72%,transparent);padding:14px 0 6px;margin-top:16px;display:flex;gap:12px;align-items:center;flex-wrap:wrap}
  .eq-save button{border:0;cursor:pointer;background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff;font-weight:800;font-size:15px;padding:13px 26px;border-radius:14px;font-family:inherit}
  .eq-ok{color:var(--teal,#00A49F);font-weight:800;font-size:14px}
</style>

<div class="eq-head">
  <h1 class="eq-h1">Tu equipo del corillo</h1>
  <div class="eq-sub">Estos son los que trabajan para ti. Ponles el nombre que quieras — así se sienten tuyos. Los verás con ese nombre cuando armen tus posts. (Déjalo en blanco para usar el nombre por defecto.)</div>
</div>

<?php if (!empty($guardar_err)): ?>
  <div style="background:#ffe0d6;border:1px solid #f2b3a0;color:#8a2a1a;border-radius:12px;padding:11px 15px;margin-bottom:16px;font-weight:700;font-size:13.5px"><?= ico('bolt') ?> <?= $h($guardar_err) ?></div>
<?php endif; ?>
<?php if ($guardado): ?>
  <div style="background:#e6f7f4;border:1px solid #9ad9d0;color:#0a6a5f;border-radius:12px;padding:11px 15px;margin-bottom:16px;font-weight:700;font-size:13.5px">✓ Listo — tu equipo quedó bautizado.</div>
<?php endif; ?>

<form method="post">
  <input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>">
  <div class="eq-grid">
    <?php foreach ($roster as $key => $ag): ?>
      <label class="eq-card">
        <div class="eq-emoji"><?= ico($ag['ico']) ?></div>
        <div class="eq-body">
          <div class="eq-rol"><?= $h($ag['rol']) ?></div>
          <input class="eq-name" type="text" name="nombre[<?= $h($key) ?>]" maxlength="24"
                 value="<?= $h($nombres[$key] ?? '') ?>" placeholder="<?= $h($ag['rol']) ?>"
                 autocomplete="off" spellcheck="false">
          <div class="eq-hace"><?= $h($ag['hace']) ?></div>
        </div>
      </label>
    <?php endforeach; ?>
  </div>
  <div class="eq-save">
    <button type="submit">Guardar mi equipo</button>
    <?php if (!empty($nombres)): ?><span class="eq-ok">Tu corillo ya tiene nombres</span><?php endif; ?>
  </div>
</form>

<?php require __DIR__ . '/_shell_foot.php'; ?>
