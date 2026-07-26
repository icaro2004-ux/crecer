<?php
// ============================================================
//  CRECER — Barra de navegación de Operaciones (compartida)
//  Antes de incluir: $op_active = 'clientes'|'soporte'|'equipo'|
//  'salud'|'evidencia'. Usa $usuario si está disponible (saludo).
//  Autocontenida (su propio CSS namespaced .optop) → sirve en
//  cualquier página de admin sin depender de sus estilos.
// ============================================================
$op_active = $op_active ?? '';
$_opl = fn($k) => 'opa' . ($k === $op_active ? ' on' : '');
$_nom = isset($usuario['nombre']) ? (string)$usuario['nombre'] : '';
$op_prob = 0;
if (isset($pdo)) { try { $op_prob = (int)$pdo->query("SELECT COUNT(DISTINCT marca_id) FROM crecer_contenido WHERE estado='fallido'")->fetchColumn(); } catch (Throwable $e) {} }
?>
<style>
  .optop{display:flex;align-items:center;flex-wrap:wrap;gap:8px 6px;padding:12px 18px;background:#140a16;color:#fff;position:sticky;top:0;z-index:50}
  .optop .obr{font-family:'Poppins',sans-serif;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#ffcaa8;border:1px solid rgba(255,255,255,.18);padding:5px 11px;border-radius:99px;font-size:12px;white-space:nowrap}
  .optop .ohi{font-size:12.5px;color:#9f96b0;white-space:nowrap}
  .optop nav{display:flex;align-items:center;flex-wrap:wrap;gap:4px;margin-left:auto}
  .optop .opa{color:#cdc5d6;text-decoration:none;font-weight:700;font-size:13px;padding:7px 12px;border-radius:9px;white-space:nowrap}
  .optop .opa:hover{color:#fff;background:rgba(255,255,255,.08)}
  .optop .opa.on{color:#fff;background:linear-gradient(135deg,var(--coral,#ff5c39),var(--magenta,#c0395f))}
  .optop .opa.salir{color:#fff;background:rgba(255,255,255,.12);font-weight:800}
  .optop .opbadge{display:inline-block;background:#ff2b85;color:#fff;font-size:10.5px;font-weight:900;border-radius:99px;padding:0 6px;margin-left:3px;vertical-align:1px}
  @media(max-width:680px){.optop .ohi{display:none}.optop nav{width:100%;margin-left:0;padding-top:8px;border-top:1px solid rgba(255,255,255,.1)}}
</style>
<div class="optop">
  <span class="obr">Operaciones</span>
  <?php if ($_nom !== ''): ?><span class="ohi">Hola, <?= htmlspecialchars($_nom, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
  <nav>
    <a class="<?= $_opl('analitica') ?>" href="/crecer/panel/admin.php">Analítica</a>
    <a class="<?= $_opl('problemas') ?>" href="/crecer/panel/admin_alertas.php">Problemas<?php if ($op_prob>0): ?> <span class="opbadge"><?= $op_prob ?></span><?php endif; ?></a>
    <a class="<?= $_opl('soporte') ?>"   href="/crecer/panel/admin_soporte.php">Soporte</a>
    <a class="<?= $_opl('equipo') ?>"    href="/crecer/panel/admin_equipo.php">Equipo</a>
    <a class="<?= $_opl('salud') ?>"     href="/crecer/panel/admin_salud.php">Salud</a>
    <a class="<?= $_opl('evidencia') ?>" href="/crecer/panel/admin_evidencia.php">Evidencia</a>
    <a class="opa salir" href="/crecer/logout.php">Salir</a>
  </nav>
</div>
