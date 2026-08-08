<?php
// ============================================================
//  CRECER — EL PROSPECTOR · radar de oportunidades (solo admin)
//  panel/admin_prospector.php
//
//  NO es un catálogo: es una COLA DE TRABAJO. El trabajo real no es
//  "mirar 500 negocios", es llegar a "hoy llamo a estos 5". Por eso:
//  tabla densa (desktop) / tarjetas (móvil), filtros de verdad,
//  selección por bloques, Mi lista (a quién llamo hoy) y paginación.
//
//  No manda nada. No publica nada. Solo prepara y organiza.
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/iconos.php';
require_once __DIR__ . '/../includes/prospector.php';
requiere_login();
$usuario = usuario_actual($pdo);
if (($usuario['rol'] ?? '') !== 'admin') { http_response_code(403); exit('Acceso solo para administradores.'); }

$h     = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$aviso = '';
$error = '';
$POR_PAGINA = 50;

// La tabla puede no existir todavía (migración pendiente).
$instalado = true;
try { $pdo->query("SELECT 1 FROM prospector_negocios LIMIT 1"); }
catch (Throwable $e) { $instalado = false; }
// "Mi lista" vive en una columna nueva: si la migración no corrió, la
// función simplemente no aparece (nada revienta).
$hay_guardado = false;
if ($instalado) {
    try { $pdo->query("SELECT guardado FROM prospector_negocios LIMIT 1"); $hay_guardado = true; }
    catch (Throwable $e) { $hay_guardado = false; }
}

/** Los ids marcados con checkbox, limpios. */
$ids_marcados = function (): array {
    $ids = (array)($_POST['ids'] ?? []);
    $ids = array_values(array_filter(array_map('intval', $ids)));
    return array_slice($ids, 0, 500);
};

// ── Acciones ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $instalado && csrf_ok()) {
    // Acción de UNA fila: viene como "verbo:id" o "verbo:id:extra" (un solo
    // <form> en toda la página — nada de forms anidados).
    $one = (string)($_POST['one'] ?? '');
    $accion = (string)($_POST['accion'] ?? '');
    $id = 0; $extra = '';
    if ($one !== '') {
        $p = explode(':', $one);
        $accion = $p[0] ?? ''; $id = (int)($p[1] ?? 0); $extra = (string)($p[2] ?? '');
    }
    $ids = $ids_marcados();
    $inList = fn(array $a) => implode(',', array_map('intval', $a));

    try {
        // ── Una fila ──
        if ($accion === 'estado' && $id) {
            if (in_array($extra, ['nuevo','contactado','interesado','cliente','descartado'], true)) {
                $pdo->prepare("UPDATE prospector_negocios SET estado=?, contactado_at=IF(?='contactado' AND contactado_at IS NULL, NOW(), contactado_at) WHERE id=?")
                    ->execute([$extra, $extra, $id]);
                $aviso = 'Marcado como ' . $extra . '.';
            }
        } elseif ($accion === 'estrella' && $id && $hay_guardado) {
            $pdo->prepare("UPDATE prospector_negocios SET guardado = 1 - guardado WHERE id=?")->execute([$id]);
            $aviso = 'Mi lista actualizada.';
        } elseif ($accion === 'nota' && $id) {
            $pdo->prepare("UPDATE prospector_negocios SET notas=? WHERE id=?")
                ->execute([mb_substr(trim((string)$_POST['notas']), 0, 2000), $id]);
            $aviso = 'Nota guardada.';
        } elseif ($accion === 'consejo' && $id) {
            $txt = prospector_aconsejar($pdo, $id);
            $aviso = $txt !== '' ? 'El Prospector opinó.' : 'No se pudo generar el consejo.';
        } elseif ($accion === 'contacto' && $id) {
            @set_time_limit(60);
            $c = prospector_contacto($pdo, $id);
            $aviso = $c['email'] ? 'Contacto hallado: ' . $c['email'] : ($c['nota'] ?: 'No se le encontró email público.');

        // ── Por bloques (lo marcado con checkbox) ──
        } elseif (str_starts_with($accion, 'bulk_')) {
            if (!$ids) {
                $error = 'No marcaste ningún negocio.';
            } elseif ($accion === 'bulk_guardar' && $hay_guardado) {
                $n = $pdo->exec("UPDATE prospector_negocios SET guardado=1 WHERE id IN (" . $inList($ids) . ")");
                $aviso = "$n a Mi lista.";
            } elseif ($accion === 'bulk_quitar' && $hay_guardado) {
                $n = $pdo->exec("UPDATE prospector_negocios SET guardado=0 WHERE id IN (" . $inList($ids) . ")");
                $aviso = "$n fuera de Mi lista.";
            } elseif ($accion === 'bulk_contactado') {
                $n = $pdo->exec("UPDATE prospector_negocios SET estado='contactado', contactado_at=COALESCE(contactado_at, NOW()) WHERE id IN (" . $inList($ids) . ")");
                $aviso = "$n marcados como contactados.";
            } elseif ($accion === 'bulk_descartar') {
                $n = $pdo->exec("UPDATE prospector_negocios SET estado='descartado' WHERE id IN (" . $inList($ids) . ")");
                $aviso = "$n descartados (siguen guardados, por si acaso).";
            } elseif ($accion === 'bulk_borrar') {
                $n = $pdo->exec("DELETE FROM prospector_negocios WHERE id IN (" . $inList($ids) . ")");
                $aviso = "$n borrados para siempre.";
            }
        } elseif ($accion === 'vaciar_descartados') {
            $n = $pdo->exec("DELETE FROM prospector_negocios WHERE estado='descartado'");
            $aviso = "$n descartados borrados para siempre.";

        // ── Barrido y ejemplos ──
        } elseif ($accion === 'buscar') {
            @set_time_limit(0);
            $r = prospector_correr($pdo, [
                'disparo'   => 'manual',
                'categoria' => trim((string)($_POST['categoria'] ?? '')) ?: null,
                'municipio' => trim((string)($_POST['municipio'] ?? '')) ?: null,
                'isla'      => !empty($_POST['isla']),
                'aconsejar' => 3,
                'rastrear'  => 10,
            ]);
            $aviso = "Barrido de {$r['categoria']} en " . count($r['municipios']) . " pueblo(s): "
                   . "{$r['encontrados']} encontrados, {$r['nuevos']} nuevos, "
                   . "{$r['con_email']} con email, {$r['aconsejados']} con consejo (" . round($r['ms'] / 1000, 1) . " s).";
            if ($r['errores']) $error = implode(' | ', $r['errores']);
        } elseif ($accion === 'demo') {
            $n = prospector_demo($pdo);
            $aviso = "$n negocios de EJEMPLO añadidos (marcados como demo).";
        } elseif ($accion === 'limpiar_demo') {
            $n = $pdo->exec("DELETE FROM prospector_negocios WHERE origen='demo'");
            $aviso = "$n ejemplos borrados.";
        }
    } catch (Throwable $e) { $error = $e->getMessage(); }

    // PRG: recargar limpio conservando TODOS los filtros de la vista.
    $keep = [];
    foreach (['estado','muni','cat','q','smin','tiene','orden','pag','vista','guardados'] as $k) {
        if (isset($_POST['f_' . $k]) && $_POST['f_' . $k] !== '') $keep[$k] = (string)$_POST['f_' . $k];
    }
    if ($aviso) $keep['ok']  = $aviso;
    if ($error) $keep['err'] = $error;
    header('Location: ?' . http_build_query($keep)); exit;
}
$aviso = (string)($_GET['ok'] ?? '');
$error = (string)($_GET['err'] ?? '');

// ── Filtros de la vista ─────────────────────────────────────
$f = [
  'estado'    => (string)($_GET['estado'] ?? 'nuevo'),
  'muni'      => (string)($_GET['muni'] ?? ''),
  'cat'       => (string)($_GET['cat'] ?? ''),
  'q'         => trim((string)($_GET['q'] ?? '')),
  'smin'      => (string)($_GET['smin'] ?? ''),
  'tiene'     => (string)($_GET['tiene'] ?? ''),
  'orden'     => (string)($_GET['orden'] ?? 'score'),
  'guardados' => (string)($_GET['guardados'] ?? ''),
  'pag'       => max(1, (int)($_GET['pag'] ?? 1)),
];
/** URL de esta misma vista cambiando lo que le pases. */
$url = function (array $cambios = []) use ($f) {
    $q = array_filter(array_merge($f, $cambios), fn($v) => $v !== '' && $v !== null);
    if (($q['pag'] ?? 1) == 1) unset($q['pag']);
    return '?' . http_build_query($q);
};

$kpi = ['total'=>0,'nuevos'=>0,'contactados'=>0,'clientes'=>0,'lista'=>0];
$negocios = []; $municipios = []; $cats = []; $ultima = null; $runs = []; $total_filtrado = 0; $n_descartados = 0;

if ($instalado) {
    $kpi['total']       = (int)$pdo->query("SELECT COUNT(*) FROM prospector_negocios")->fetchColumn();
    $kpi['nuevos']      = (int)$pdo->query("SELECT COUNT(*) FROM prospector_negocios WHERE estado='nuevo'")->fetchColumn();
    $kpi['contactados'] = (int)$pdo->query("SELECT COUNT(*) FROM prospector_negocios WHERE estado IN ('contactado','interesado')")->fetchColumn();
    $kpi['clientes']    = (int)$pdo->query("SELECT COUNT(*) FROM prospector_negocios WHERE estado='cliente'")->fetchColumn();
    $n_descartados      = (int)$pdo->query("SELECT COUNT(*) FROM prospector_negocios WHERE estado='descartado'")->fetchColumn();
    if ($hay_guardado) $kpi['lista'] = (int)$pdo->query("SELECT COUNT(*) FROM prospector_negocios WHERE guardado=1")->fetchColumn();
    $municipios = $pdo->query("SELECT DISTINCT municipio FROM prospector_negocios WHERE municipio<>'' ORDER BY municipio")->fetchAll(PDO::FETCH_COLUMN);
    $cats       = $pdo->query("SELECT DISTINCT categoria FROM prospector_negocios WHERE categoria<>'' ORDER BY categoria")->fetchAll(PDO::FETCH_COLUMN);
    $ultima     = $pdo->query("SELECT * FROM prospector_runs ORDER BY id DESC LIMIT 1")->fetch() ?: null;
    $runs       = $pdo->query("SELECT * FROM prospector_runs ORDER BY id DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);

    // WHERE de los filtros
    $w = []; $p = [];
    if ($f['guardados'] === '1' && $hay_guardado) { $w[] = 'guardado=1'; }
    elseif ($f['estado'] !== 'todos')             { $w[] = 'estado=?'; $p[] = $f['estado']; }
    if ($f['muni'] !== '') { $w[] = 'municipio=?'; $p[] = $f['muni']; }
    if ($f['cat']  !== '') { $w[] = 'categoria=?'; $p[] = $f['cat']; }
    if ($f['q']    !== '') { $w[] = 'nombre LIKE ?'; $p[] = '%' . $f['q'] . '%'; }
    if ($f['smin'] !== '') { $w[] = 'score >= ?'; $p[] = (int)$f['smin']; }
    if ($f['tiene'] === 'email')    $w[] = "email IS NOT NULL AND email<>''";
    if ($f['tiene'] === 'sinemail') $w[] = "(email IS NULL OR email='')";
    if ($f['tiene'] === 'sinweb')   $w[] = "(website IS NULL OR website='')";
    if ($f['tiene'] === 'redes')    $w[] = "((instagram IS NOT NULL AND instagram<>'') OR (facebook IS NOT NULL AND facebook<>''))";
    $where = $w ? ' WHERE ' . implode(' AND ', $w) : '';

    $ord = [
      'score'    => 'score DESC, reviews DESC',
      'reviews'  => 'reviews DESC, score DESC',
      'reciente' => 'id DESC',
      'nombre'   => 'nombre ASC',
    ][$f['orden']] ?? 'score DESC, reviews DESC';

    $c = $pdo->prepare("SELECT COUNT(*) FROM prospector_negocios{$where}");
    $c->execute($p); $total_filtrado = (int)$c->fetchColumn();

    $paginas = max(1, (int)ceil($total_filtrado / $POR_PAGINA));
    if ($f['pag'] > $paginas) $f['pag'] = $paginas;
    $off = ($f['pag'] - 1) * $POR_PAGINA;

    $q = $pdo->prepare("SELECT * FROM prospector_negocios{$where} ORDER BY {$ord} LIMIT {$POR_PAGINA} OFFSET {$off}");
    $q->execute($p);
    $negocios = $q->fetchAll(PDO::FETCH_ASSOC);
} else { $paginas = 1; }

$plan = prospector_plan_default();
$sug_mun = []; $sug_cat = [];
try { $sug_mun = $pdo->query("SELECT nombre FROM municipios ORDER BY nombre")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
try { $sug_cat = $pdo->query("SELECT nombre FROM categorias WHERE activa=1 ORDER BY nombre")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
$sug_cat = array_values(array_unique(array_merge($plan['categorias'], $sug_cat)));
if (!$sug_mun) $sug_mun = $plan['municipios'];
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>El Prospector — Operaciones</title>
<link href="/crecer/assets/encuentralo-ui.css?v=<?= ASSET_VER ?>" rel="stylesheet">
<style>
  *{box-sizing:border-box}
  body{background:var(--crema,#fbf6ee);color:var(--tinta,#1b1622);font-family:'Plus Jakarta Sans',system-ui,sans-serif;margin:0}
  .wrap{max-width:1240px;margin:0 auto;padding:20px 18px 110px}
  h1{font-family:'Poppins',sans-serif;text-transform:uppercase;font-size:26px;margin:8px 0 4px}
  .sub{color:var(--muted);font-size:13.5px;margin:0 0 18px}
  .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:16px}
  .kpi{background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px 16px;box-shadow:var(--shadow-sm);text-decoration:none;color:inherit;display:block}
  .kpi .l{font-size:11.5px;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.03em}
  .kpi .v{font-family:'Poppins',sans-serif;font-size:30px;line-height:1;margin-top:5px}
  .kpi.hot{background:linear-gradient(135deg,#241633,#0e0a16);color:#fff}.kpi.hot .l{color:#bdb4c9}
  .kpi.act{outline:2px solid var(--tinta);outline-offset:1px}
  .card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:16px 18px;margin-bottom:14px;box-shadow:var(--shadow-sm)}
  .card h2{font-family:'Poppins',sans-serif;text-transform:uppercase;font-size:15px;letter-spacing:.03em;margin:0 0 12px}
  .btn{display:inline-block;border:0;cursor:pointer;font-family:inherit;font-weight:800;font-size:13.5px;color:#fff;background:var(--tinta);padding:10px 16px;border-radius:10px;text-decoration:none}
  .btn.g{background:#fff;color:var(--tinta);border:1px solid var(--line)}
  .btn.sm{padding:7px 12px;font-size:12.5px;border-radius:9px}
  .btn.peligro{background:#fff;color:#8c1d1d;border:1px solid #f3c2c2}
  .aviso{background:#e8f7ef;border:1px solid #b7e3c9;color:#155e35;padding:11px 14px;border-radius:11px;margin-bottom:14px;font-size:13.5px}
  .malo{background:#fdecec;border:1px solid #f3c2c2;color:#8c1d1d;padding:11px 14px;border-radius:11px;margin-bottom:14px;font-size:13.5px}
  .chip{display:inline-block;padding:7px 13px;border-radius:99px;border:1px solid var(--line);background:#fff;color:var(--tinta);text-decoration:none;font-size:13px;font-weight:700}
  .chip.on{background:var(--tinta);color:#fff;border-color:var(--tinta)}
  select,input[type=text],input[type=search]{font-family:inherit;font-size:13.5px;padding:9px 11px;border:1px solid var(--line);border-radius:10px;background:#fff;max-width:100%}

  /* ── La barra de trabajo: filtros que se usan de verdad ── */
  .barra{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:10px}
  .barra2{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:12px}
  .cuenta{font-size:12.5px;color:var(--muted);margin-left:auto;font-weight:700}

  /* ── La cola: fila densa en desktop, tarjeta en móvil ── */
  .cola{background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden;box-shadow:var(--shadow-sm)}
  .fila{display:grid;grid-template-columns:34px 52px minmax(190px,1.6fr) 110px minmax(150px,1fr) minmax(160px,1fr) 96px 62px;
        gap:10px;align-items:center;padding:9px 14px;border-top:1px solid var(--line);font-size:13.5px}
  .fila:hover{background:#fffdfb}
  .fin{display:flex;align-items:center;justify-content:flex-end;gap:2px}
  .fila:first-child{border-top:0}
  .fila.head{background:#faf8f6;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);position:sticky;top:0;z-index:2}
  .fila.sel{background:#fff7f3}
  .fila input[type=checkbox]{width:17px;height:17px;accent-color:#c0395f;cursor:pointer}
  .sc2{font-family:'Poppins',sans-serif;font-weight:800;font-size:19px;line-height:1;text-align:center;border-radius:9px;padding:6px 0;color:#fff}
  .nm{min-width:0}
  .nm b{font-weight:800;font-size:14px}
  .nm small{display:block;color:var(--muted);font-size:11.5px;margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .sig{display:flex;flex-wrap:wrap;gap:4px}
  .tag{font-size:10.5px;font-weight:800;border-radius:6px;padding:3px 7px;background:#f2efec;color:#5b5350;white-space:nowrap}
  .tag.mal{background:#fdecec;color:#8c1d1d}
  .tag.bien{background:#e8f7ef;color:#155e35}
  .ct{display:flex;flex-wrap:wrap;gap:5px;min-width:0}
  .ct a,.ct span{font-size:11.5px;background:#f6f3f0;border-radius:7px;padding:4px 7px;color:var(--tinta);text-decoration:none;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .ct a.mail{background:#e8f7ef;color:#0a6b4f;font-weight:800}
  .est{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.03em;color:var(--muted)}
  .star{border:0;background:none;cursor:pointer;font-size:19px;line-height:1;color:#d8d2cc;padding:4px}
  .star.on{color:#e8a51c}
  .mas{border:0;background:none;cursor:pointer;color:#b3aca6;font-size:15px;font-weight:800;padding:4px 6px;border-radius:7px}
  .mas:hover{background:#f2efec;color:var(--tinta)}
  .nm b{cursor:pointer}
  .nm b:hover{text-decoration:underline}
  .detalle{padding:0 14px 14px 100px;border-top:1px dashed var(--line);background:#fdfcfb}
  .mot{list-style:none;padding:0;margin:10px 0;font-size:13px}
  .mot li{padding:2px 0}.mot li::before{content:"·";color:var(--magenta,#c0395f);font-weight:900;margin-right:7px}
  .consejo{background:#fff8f2;border-left:3px solid var(--coral,#ff5c39);padding:10px 13px;border-radius:0 10px 10px 0;font-size:13.5px;line-height:1.5;margin:8px 0}
  .acc{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
  .badge{font-size:10.5px;font-weight:900;text-transform:uppercase;letter-spacing:.04em;border-radius:99px;padding:3px 9px;margin-left:6px}
  .b-demo{background:#fff0c2;color:#7a5b00}
  .vacio{text-align:center;color:var(--muted);padding:34px 10px;font-size:14px}
  .pag{display:flex;gap:8px;align-items:center;justify-content:center;margin:16px 0 0;font-size:13px;color:var(--muted)}

  /* ── Barra flotante de acciones por bloque ── */
  .bulk{position:fixed;left:50%;bottom:18px;transform:translate(-50%,140%);display:flex;flex-wrap:wrap;gap:8px;align-items:center;
        background:var(--tinta,#1b1622);color:#fff;border-radius:16px;padding:11px 14px;z-index:60;box-shadow:0 16px 40px -12px rgba(0,0,0,.5);
        transition:transform .26s cubic-bezier(.22,1,.36,1),opacity .2s;max-width:min(96vw,980px);
        opacity:0;visibility:hidden;pointer-events:none}
  .bulk.on{transform:translate(-50%,0);opacity:1;visibility:visible;pointer-events:auto}
  .bulk b{font-size:13.5px;margin-right:4px;white-space:nowrap}
  .bulk button{border:0;cursor:pointer;font-family:inherit;font-weight:800;font-size:12.5px;border-radius:9px;padding:9px 13px;background:rgba(255,255,255,.14);color:#fff}
  .bulk button.rojo{background:#c0392b}
  .bulk button.claro{background:#fff;color:var(--tinta)}

  /* Plegables (solo móvil): la cola empieza antes del primer scroll. */
  .plega{display:none}
  /* ── MÓVIL: la fila se vuelve tarjeta (una decisión a la vez) ── */
  @media(max-width:900px){
    .plega{display:inline-flex;align-items:center;gap:6px;width:100%;justify-content:space-between;
           border:1px solid var(--line);background:#fff;color:var(--tinta);font-family:inherit;font-weight:800;
           font-size:13.5px;padding:12px 14px;border-radius:12px;cursor:pointer;margin-bottom:10px}
    .oculto-m{display:none}
    .kpis{grid-template-columns:repeat(2,1fr);gap:8px}
    .kpi .v{font-size:24px}
    .fila.head{display:none}
    .fila{grid-template-columns:30px 46px 1fr 62px;grid-template-areas:"chk sc nm fin" ". . sig sig" ". . ct ct" ". . est est";
          gap:6px 10px;padding:12px 14px}
    .fila>*:nth-child(1){grid-area:chk}.sc2{grid-area:sc}.nm{grid-area:nm}
    .mu{display:none}.sig{grid-area:sig}.ct{grid-area:ct}.est{grid-area:est}
    .fin{grid-area:fin;align-self:start}
    .detalle{padding:0 14px 14px}
  }
</style></head><body>
<?php $op_active='prospector'; require __DIR__.'/_ops_top.php'; ?>
<div class="wrap">
  <h1>El Prospector</h1>
  <p class="sub">Radar de oportunidades. Negocios públicos que podrían necesitar Crecer, puntuados con datos reales de Google.
     <b>No contacta a nadie</b> — prepara el trabajo y tú decides a quién llamar.</p>

  <?php if ($aviso): ?><div class="aviso"><?= $h($aviso) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="malo"><?= $h($error) ?></div><?php endif; ?>

  <?php if (!$instalado): ?>
    <div class="card">
      <h2>Falta la migración</h2>
      <p style="font-size:14px;line-height:1.6;margin:0 0 10px">
        Las tablas del Prospector todavía no existen. Corre esto una vez en phpMyAdmin:</p>
      <p style="margin:0"><code style="background:#f6f3f0;padding:8px 12px;border-radius:8px;display:inline-block;font-size:13px">
        migrations/2026-08-05_prospector.sql</code></p>
    </div>
  <?php else: ?>

  <div class="kpis">
    <a class="kpi hot" href="<?= $h($url(['estado'=>'todos','guardados'=>'','pag'=>1])) ?>"><div class="l">En el radar</div><div class="v"><?= number_format($kpi['total']) ?></div></a>
    <a class="kpi <?= $f['guardados']!=='1' && $f['estado']==='nuevo' ? 'act':'' ?>" href="<?= $h($url(['estado'=>'nuevo','guardados'=>'','pag'=>1])) ?>"><div class="l">Sin tocar</div><div class="v"><?= number_format($kpi['nuevos']) ?></div></a>
    <?php if ($hay_guardado): ?>
    <a class="kpi <?= $f['guardados']==='1' ? 'act':'' ?>" href="<?= $h($url(['guardados'=>'1','pag'=>1])) ?>"><div class="l">Mi lista (llamar)</div><div class="v"><?= number_format($kpi['lista']) ?></div></a>
    <?php endif; ?>
    <a class="kpi <?= $f['estado']==='contactado' ? 'act':'' ?>" href="<?= $h($url(['estado'=>'contactado','guardados'=>'','pag'=>1])) ?>"><div class="l">Contactados</div><div class="v"><?= number_format($kpi['contactados']) ?></div></a>
    <a class="kpi <?= $f['estado']==='cliente' ? 'act':'' ?>" href="<?= $h($url(['estado'=>'cliente','guardados'=>'','pag'=>1])) ?>"><div class="l">Se hicieron clientes</div><div class="v"><?= number_format($kpi['clientes']) ?></div></a>
  </div>

  <?php if (!$hay_guardado): ?>
    <div class="malo" style="background:#fff8e8;border-color:#f0dca8;color:#7a5b00">
      Falta correr <code>migrations/2026-08-08_prospector_contacto.sql</code> en phpMyAdmin —
      sin eso no hay <b>email/redes</b> ni <b>Mi lista</b> (lo demás funciona igual).
    </div>
  <?php endif; ?>

  <!-- ── Barrido ─────────────────────────────────────────── -->
  <?php /* En móvil nace plegado: primero la cola de trabajo, el barrido a un toque. */ ?>
  <button type="button" class="plega" data-plega="cBarrido">Salir a buscar negocios <span>▾</span></button>
  <form method="post" class="card oculto-m" id="cBarrido"><?= csrf_field() ?>
    <h2><?= ico('search') ?> Salir a buscar</h2>
    <?php if (!prospector_configurado()): ?>
      <p style="font-size:13.5px;line-height:1.6;color:#8c1d1d;margin:0 0 12px">
        Falta <b>PLACES_API_KEY</b> en <code>includes/config.local.php</code> (necesita billing de Google Cloud activo).
        Mientras tanto puedes meter negocios de ejemplo para ver cómo se comporta la fórmula.</p>
      <button class="btn g" name="accion" value="demo">Meter 3 ejemplos</button>
      <button class="btn g" name="accion" value="limpiar_demo">Borrar ejemplos</button>
    <?php else: ?>
      <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
        <input type="text" name="categoria" list="l-cat" autocomplete="off" style="min-width:230px"
               placeholder="Rubro — vacío usa el de esta semana (<?= $h($plan['categorias'][(int)date('W') % count($plan['categorias'])]) ?>)">
        <datalist id="l-cat"><?php foreach ($sug_cat as $c): ?><option value="<?= $h($c) ?>"><?php endforeach; ?></datalist>
        <input type="text" name="municipio" list="l-mun" autocomplete="off" style="min-width:200px"
               id="p-mun" placeholder="Pueblo — vacío barre los <?= count($plan['municipios']) ?> del plan">
        <datalist id="l-mun"><?php foreach ($sug_mun as $m): ?><option value="<?= $h($m) ?>"><?php endforeach; ?></datalist>
        <label style="display:inline-flex;align-items:center;gap:7px;font-size:13.5px;font-weight:700;
                      background:#fff4ef;border:1px solid #ffd9c9;border-radius:10px;padding:9px 13px;cursor:pointer">
          <input type="checkbox" name="isla" value="1" onchange="document.getElementById('p-mun').disabled=this.checked">
          Toda la isla (<?= count($sug_mun) ?> pueblos)
        </label>
        <button class="btn" name="accion" value="buscar"><?= ico('bolt') ?> Barrer ahora</button>
      </div>
      <p style="font-size:12px;color:var(--muted);margin:9px 0 0">
        El rubro lo escribes tú — las sugerencias (<?= count($sug_cat) ?>) son un atajo, no una lista cerrada.
        <b>Toda la isla</b> son <?= count($sug_mun) ?> llamadas a Google y tarda unos
        <?= max(1, (int)round(count($sug_mun) * 1.8 / 60)) ?> minutos; si se corta a medias, lo encontrado
        ya quedó guardado y puedes repetirlo sin duplicar nada.</p>
    <?php endif; ?>
    <?php if ($ultima): ?>
      <p style="font-size:12.5px;color:var(--muted);margin:12px 0 0">
        Última corrida: <b><?= $h($ultima['consulta']) ?></b> ·
        <?= (int)$ultima['encontrados'] ?> encontrados, <?= (int)$ultima['nuevos'] ?> nuevos ·
        <?= $h($ultima['created_at']) ?> · disparo <b><?= $h($ultima['disparo']) ?></b><?= $ultima['estado']==='error' ? ' · <b style="color:#8c1d1d">error</b>' : '' ?>
      </p>
    <?php endif; ?>
  </form>

  <!-- ── Filtros ─────────────────────────────────────────── -->
  <div class="barra">
    <?php foreach (['nuevo'=>'Sin tocar','contactado'=>'Contactados','interesado'=>'Interesados','cliente'=>'Clientes','descartado'=>'Descartados','todos'=>'Todos'] as $k=>$v): ?>
      <a class="chip <?= ($f['guardados']!=='1' && $f['estado']===$k)?'on':'' ?>" href="<?= $h($url(['estado'=>$k,'guardados'=>'','pag'=>1])) ?>"><?= $v ?></a>
    <?php endforeach; ?>
    <?php if ($hay_guardado): ?>
      <a class="chip <?= $f['guardados']==='1'?'on':'' ?>" href="<?= $h($url(['guardados'=>'1','pag'=>1])) ?>">★ Mi lista</a>
    <?php endif; ?>
  </div>

  <button type="button" class="plega" data-plega="cFiltros">Filtrar y buscar <span>▾</span></button>
  <form method="get" class="barra2 oculto-m" id="cFiltros">
    <?php foreach (['estado','guardados'] as $k): if ($f[$k]!=='') : ?>
      <input type="hidden" name="<?= $k ?>" value="<?= $h($f[$k]) ?>">
    <?php endif; endforeach; ?>
    <input type="search" name="q" value="<?= $h($f['q']) ?>" placeholder="Buscar por nombre…" style="min-width:190px">
    <select name="muni"><option value="">Todos los pueblos</option>
      <?php foreach ($municipios as $m): ?><option value="<?= $h($m) ?>" <?= $f['muni']===$m?'selected':'' ?>><?= $h($m) ?></option><?php endforeach; ?>
    </select>
    <select name="cat"><option value="">Todos los rubros</option>
      <?php foreach ($cats as $c): ?><option value="<?= $h($c) ?>" <?= $f['cat']===$c?'selected':'' ?>><?= $h($c) ?></option><?php endforeach; ?>
    </select>
    <select name="smin"><option value="">Cualquier score</option>
      <?php foreach ([80,60,40] as $s): ?><option value="<?= $s ?>" <?= $f['smin']==="$s"?'selected':'' ?>><?= $s ?>+ puntos</option><?php endforeach; ?>
    </select>
    <select name="tiene"><option value="">Con o sin contacto</option>
      <option value="email"    <?= $f['tiene']==='email'?'selected':'' ?>>Con email</option>
      <option value="sinemail" <?= $f['tiene']==='sinemail'?'selected':'' ?>>Sin email</option>
      <option value="redes"    <?= $f['tiene']==='redes'?'selected':'' ?>>Con IG/FB</option>
      <option value="sinweb"   <?= $f['tiene']==='sinweb'?'selected':'' ?>>Sin web (los mejores)</option>
    </select>
    <select name="orden">
      <option value="score"    <?= $f['orden']==='score'?'selected':'' ?>>Mejor score</option>
      <option value="reviews"  <?= $f['orden']==='reviews'?'selected':'' ?>>Más reseñas</option>
      <option value="reciente" <?= $f['orden']==='reciente'?'selected':'' ?>>Más recientes</option>
      <option value="nombre"   <?= $f['orden']==='nombre'?'selected':'' ?>>Nombre A-Z</option>
    </select>
    <button class="btn g sm">Filtrar</button>
    <?php if ($f['q']||$f['muni']||$f['cat']||$f['smin']||$f['tiene']): ?>
      <a class="btn g sm" href="<?= $h($url(['q'=>'','muni'=>'','cat'=>'','smin'=>'','tiene'=>'','pag'=>1])) ?>">Limpiar</a>
    <?php endif; ?>
    <span class="cuenta"><?= number_format($total_filtrado) ?> negocio<?= $total_filtrado===1?'':'s' ?><?= $total_filtrado > $POR_PAGINA ? ' · página '.$f['pag'].' de '.$paginas : '' ?></span>
  </form>

  <!-- ── La cola de trabajo (un solo form: filas + bloques) ── -->
  <form method="post" id="cola"><?= csrf_field() ?>
    <?php foreach ($f as $k=>$v): ?><input type="hidden" name="f_<?= $k ?>" value="<?= $h($v) ?>"><?php endforeach; ?>

    <?php if (!$negocios): ?>
      <div class="card vacio">
        No hay negocios con estos filtros.<br>
        <?= prospector_configurado() ? 'Dale a <b>Barrer ahora</b> o limpia los filtros.' : 'Mete unos ejemplos o configura la clave de Places.' ?>
      </div>
    <?php else: ?>
    <div class="cola">
      <div class="fila head">
        <div><input type="checkbox" id="todos" title="Marcar todos"></div>
        <div>Score</div><div>Negocio</div><div class="mu">Pueblo</div><div>Señales</div><div>Contacto</div><div>Estado</div><div></div>
      </div>

      <?php foreach ($negocios as $n):
          $sc  = (int)$n['score'];
          $col = $sc >= 80 ? '#c0395f' : ($sc >= 60 ? '#ff5c39' : ($sc >= 40 ? '#c9a227' : '#9b93a6'));
          $motivos = json_decode((string)$n['motivos'], true) ?: [];
          $id = (int)$n['id'];
      ?>
        <div class="fila" id="f-<?= $id ?>">
          <div><input type="checkbox" class="pick" name="ids[]" value="<?= $id ?>"></div>
          <div class="sc2" style="background:<?= $col ?>"><?= $sc ?></div>
          <div class="nm">
            <b data-ver="<?= $id ?>" title="Ver el detalle"><?= $h($n['nombre']) ?></b>
            <?php if ($n['origen']==='demo'): ?><span class="badge b-demo">ejemplo</span><?php endif; ?>
            <small><?= $h($n['tipo_google'] ?: $n['categoria']) ?><?= $n['rating'] ? ' · '.$h($n['rating']).'★' : '' ?></small>
          </div>
          <div class="mu" style="font-size:12.5px;color:var(--muted)"><?= $h($n['municipio']) ?></div>
          <div class="sig">
            <?php if (!$n['website']): ?><span class="tag mal">sin web</span>
            <?php elseif (!empty($n['web_es_social'])): ?><span class="tag">web = su red</span><?php endif; ?>
            <?php if ((int)$n['reviews'] >= 50): ?><span class="tag bien"><?= (int)$n['reviews'] ?> reseñas</span>
            <?php elseif ((int)$n['reviews'] > 0): ?><span class="tag"><?= (int)$n['reviews'] ?> reseñas</span><?php endif; ?>
            <?php if (!empty($n['contacto_at']) && empty($n['email'])): ?><span class="tag">sin email público</span><?php endif; ?>
          </div>
          <div class="ct">
            <?php if (!empty($n['email'])): ?><a class="mail" href="mailto:<?= $h($n['email']) ?>"><?= $h($n['email']) ?></a><?php endif; ?>
            <?php if ($n['telefono']): ?><a href="tel:<?= $h(preg_replace('/[^0-9+]/','',$n['telefono'])) ?>"><?= $h($n['telefono']) ?></a><?php endif; ?>
            <?php if (!empty($n['instagram'])): ?><a href="https://instagram.com/<?= $h($n['instagram']) ?>" target="_blank" rel="noopener">@<?= $h($n['instagram']) ?></a><?php endif; ?>
            <?php if (!empty($n['facebook'])): ?><a href="https://facebook.com/<?= $h($n['facebook']) ?>" target="_blank" rel="noopener">FB</a><?php endif; ?>
          </div>
          <div class="est"><?= $h($n['estado']) ?></div>
          <div class="fin">
            <?php if ($hay_guardado): ?>
              <button class="star <?= !empty($n['guardado'])?'on':'' ?>" name="one" value="estrella:<?= $id ?>"
                      title="<?= !empty($n['guardado']) ? 'Quitar de Mi lista' : 'Guardar en Mi lista' ?>">★</button>
            <?php endif; ?>
            <button type="button" class="mas" data-ver="<?= $id ?>" title="Ver el detalle">▾</button>
          </div>
        </div>
        <div class="detalle" id="d-<?= $id ?>" hidden>
          <?php if ($motivos): ?><ul class="mot"><?php foreach ($motivos as $m): ?><li><?= $h($m) ?></li><?php endforeach; ?></ul><?php endif; ?>
          <?php if ($n['direccion']): ?><div style="font-size:12.5px;color:var(--muted);margin-bottom:6px"><?= $h($n['direccion']) ?></div><?php endif; ?>
          <?php if ($n['consejo']): ?><div class="consejo"><?= $h($n['consejo']) ?></div><?php endif; ?>
          <div class="acc">
            <?php if ($n['website']): ?><a class="btn g sm" href="<?= $h($n['website']) ?>" target="_blank" rel="noopener">Su web</a><?php endif; ?>
            <?php if ($n['maps_url']): ?><a class="btn g sm" href="<?= $h($n['maps_url']) ?>" target="_blank" rel="noopener">Google Maps</a><?php endif; ?>
            <?php if (empty($n['contacto_at'])): ?>
              <button class="btn g sm" name="one" value="contacto:<?= $id ?>">Buscarle el contacto</button>
            <?php endif; ?>
            <?php if (!$n['consejo']): ?>
              <button class="btn g sm" name="one" value="consejo:<?= $id ?>">Que opine el Prospector</button>
            <?php endif; ?>
            <?php foreach (['contactado'=>'Contactado','interesado'=>'Interesado','cliente'=>'Es cliente','descartado'=>'Descartar'] as $k=>$v):
                  if ($n['estado']===$k) continue; ?>
              <button class="btn g sm" name="one" value="estado:<?= $id ?>:<?= $k ?>"><?= $v ?></button>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($paginas > 1): ?>
      <div class="pag">
        <?php if ($f['pag'] > 1): ?><a class="btn g sm" href="<?= $h($url(['pag'=>$f['pag']-1])) ?>">← Anterior</a><?php endif; ?>
        <span>Página <?= $f['pag'] ?> de <?= $paginas ?></span>
        <?php if ($f['pag'] < $paginas): ?><a class="btn g sm" href="<?= $h($url(['pag'=>$f['pag']+1])) ?>">Siguiente →</a><?php endif; ?>
      </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- Acciones por bloque: aparece sola cuando marcas algo -->
    <div class="bulk" id="bulk">
      <b><span id="bulkN">0</span> marcados</b>
      <?php if ($hay_guardado): ?>
        <button class="claro" name="accion" value="bulk_guardar">★ A Mi lista</button>
        <button name="accion" value="bulk_quitar">Quitar de la lista</button>
      <?php endif; ?>
      <button name="accion" value="bulk_contactado">Contactados</button>
      <button name="accion" value="bulk_descartar">Descartar</button>
      <button class="rojo" name="accion" value="bulk_borrar"
              onclick="return confirm('¿Borrar para siempre los marcados? Esto no se puede deshacer.')">Borrar</button>
    </div>
  </form>

  <?php if ($n_descartados > 0): ?>
    <form method="post" style="margin-top:14px"><?= csrf_field() ?>
      <?php foreach ($f as $k=>$v): ?><input type="hidden" name="f_<?= $k ?>" value="<?= $h($v) ?>"><?php endforeach; ?>
      <button class="btn peligro sm" name="accion" value="vaciar_descartados"
              onclick="return confirm('¿Borrar para siempre los <?= $n_descartados ?> descartados?')">
        Vaciar los <?= $n_descartados ?> descartados
      </button>
    </form>
  <?php endif; ?>

  <!-- ── Evidencia: las corridas ─────────────────────────── -->
  <?php if ($runs): ?>
    <div class="card" style="margin-top:20px">
      <h2><?= ico('bolt') ?> Corridas del radar</h2>
      <p style="font-size:12.5px;color:var(--muted);margin:-6px 0 10px">
        La prueba de que sale a trabajar solo: cada fila es una corrida con su hora y su resultado.</p>
      <?php foreach ($runs as $r): ?>
        <div style="display:flex;justify-content:space-between;gap:10px;padding:7px 0;border-bottom:1px solid var(--line);font-size:13px">
          <span><b><?= $h($r['disparo']) ?></b> · <?= $h($r['consulta']) ?></span>
          <span style="color:var(--muted)">
            <?= (int)$r['encontrados'] ?> enc · <?= (int)$r['nuevos'] ?> nuevos · <?= (int)$r['ms'] ?> ms · <?= $h($r['created_at']) ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php endif; /* instalado */ ?>
</div>

<script>
(function(){
  // Plegables de móvil (el botón solo existe ahí, por CSS).
  document.querySelectorAll('[data-plega]').forEach(function(b){
    b.addEventListener('click', function(){
      var c = document.getElementById(b.dataset.plega); if(!c) return;
      var plegado = c.classList.toggle('oculto-m');
      var f = b.querySelector('span'); if(f) f.textContent = plegado ? '▾' : '▴';
    });
  });
  // Detalle por fila (divulgación progresiva: la cola se lee de un vistazo).
  // El nombre y el chevron lo abren, en la misma fila (sin duplicar altura).
  document.querySelectorAll('[data-ver]').forEach(function(b){
    b.addEventListener('click', function(){
      var d = document.getElementById('d-' + b.dataset.ver); if(!d) return;
      d.hidden = !d.hidden;
      var ch = document.querySelector('.mas[data-ver="' + b.dataset.ver + '"]');
      if(ch) ch.textContent = d.hidden ? '▾' : '▴';
    });
  });
  // Selección por bloques.
  var picks = [].slice.call(document.querySelectorAll('.pick'));
  var bulk = document.getElementById('bulk'), bn = document.getElementById('bulkN'), todos = document.getElementById('todos');
  function pinta(){
    var n = picks.filter(function(c){ return c.checked; }).length;
    if(bn) bn.textContent = n;
    if(bulk) bulk.classList.toggle('on', n > 0);
    picks.forEach(function(c){ var fila = c.closest('.fila'); if(fila) fila.classList.toggle('sel', c.checked); });
    if(todos) todos.checked = n > 0 && n === picks.length;
  }
  picks.forEach(function(c){ c.addEventListener('change', pinta); });
  if(todos) todos.addEventListener('change', function(){ picks.forEach(function(c){ c.checked = todos.checked; }); pinta(); });
  // Shift+click = rango (marcar 20 de un tirón sin 20 clics).
  var ultimo = null;
  picks.forEach(function(c, i){
    c.addEventListener('click', function(e){
      if(e.shiftKey && ultimo !== null){
        var a = Math.min(ultimo, i), b2 = Math.max(ultimo, i);
        for(var k = a; k <= b2; k++) picks[k].checked = c.checked;
        pinta();
      }
      ultimo = i;
    });
  });
  pinta();
})();
</script>
</body></html>
