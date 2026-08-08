<?php
// ============================================================
//  CRECER — EL PROSPECTOR · radar de oportunidades (solo admin)
//  panel/admin_prospector.php
//
//  Pantalla interna de adquisición. Cada negocio es una tarjeta con
//  su puntuación, los motivos (datos reales de Google) y el consejo
//  del agente. Desde aquí el humano decide a quién llamar.
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

$h    = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$aviso = '';
$error = '';

// La tabla puede no existir todavía (migración pendiente).
$instalado = true;
try { $pdo->query("SELECT 1 FROM prospector_negocios LIMIT 1"); }
catch (Throwable $e) { $instalado = false; }

// ── Acciones ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $instalado && csrf_ok()) {
    $accion = (string)($_POST['accion'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);
    try {
        if ($accion === 'estado' && $id) {
            $nuevo = (string)($_POST['estado'] ?? 'nuevo');
            if (in_array($nuevo, ['nuevo','contactado','interesado','cliente','descartado'], true)) {
                $pdo->prepare("UPDATE prospector_negocios SET estado=?, contactado_at=IF(?='contactado' AND contactado_at IS NULL, NOW(), contactado_at) WHERE id=?")
                    ->execute([$nuevo, $nuevo, $id]);
                $aviso = 'Marcado como ' . $nuevo . '.';
            }
        } elseif ($accion === 'nota' && $id) {
            $pdo->prepare("UPDATE prospector_negocios SET notas=? WHERE id=?")
                ->execute([mb_substr(trim((string)$_POST['notas']), 0, 2000), $id]);
            $aviso = 'Nota guardada.';
        } elseif ($accion === 'consejo' && $id) {
            $txt = prospector_aconsejar($pdo, $id);
            $aviso = $txt !== '' ? 'El Prospector opinó.' : 'No se pudo generar el consejo.';
        } elseif ($accion === 'contacto' && $id) {
            // El Rastreador: email/redes desde el sitio del propio negocio.
            @set_time_limit(60);
            $c = prospector_contacto($pdo, $id);
            $aviso = $c['email']
                ? 'Contacto hallado: ' . $c['email']
                : ($c['nota'] ?: 'No se le encontró email público.');
        } elseif ($accion === 'buscar') {
            // Toda la isla son 78 llamadas seguidas: sin esto el barrido se corta
            // a la mitad por el límite de ejecución de PHP.
            @set_time_limit(0);
            $r = prospector_correr($pdo, [
                'disparo'   => 'manual',
                'categoria' => trim((string)($_POST['categoria'] ?? '')) ?: null,
                'municipio' => trim((string)($_POST['municipio'] ?? '')) ?: null,
                'isla'      => !empty($_POST['isla']),
                'aconsejar' => 3,
                'rastrear'  => 10,   // a los 10 mejores se les busca email/redes
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
    // PRG: recargar limpio conservando filtros
    $qs = http_build_query(array_filter([
        'estado' => $_GET['estado'] ?? null, 'muni' => $_GET['muni'] ?? null,
        'ok' => $aviso ?: null, 'err' => $error ?: null,
    ]));
    header('Location: ?' . $qs); exit;
}
$aviso = (string)($_GET['ok'] ?? '');
$error = (string)($_GET['err'] ?? '');

// ── Datos ───────────────────────────────────────────────────
$f_estado = (string)($_GET['estado'] ?? 'nuevo');
$f_muni   = (string)($_GET['muni'] ?? '');
$kpi = ['total'=>0,'nuevos'=>0,'contactados'=>0,'clientes'=>0];
$negocios = []; $municipios = []; $ultima = null; $runs = [];

if ($instalado) {
    $kpi['total']       = (int)$pdo->query("SELECT COUNT(*) FROM prospector_negocios")->fetchColumn();
    $kpi['nuevos']      = (int)$pdo->query("SELECT COUNT(*) FROM prospector_negocios WHERE estado='nuevo'")->fetchColumn();
    $kpi['contactados'] = (int)$pdo->query("SELECT COUNT(*) FROM prospector_negocios WHERE estado IN ('contactado','interesado')")->fetchColumn();
    $kpi['clientes']    = (int)$pdo->query("SELECT COUNT(*) FROM prospector_negocios WHERE estado='cliente'")->fetchColumn();
    $municipios = $pdo->query("SELECT DISTINCT municipio FROM prospector_negocios WHERE municipio<>'' ORDER BY municipio")->fetchAll(PDO::FETCH_COLUMN);
    $ultima = $pdo->query("SELECT * FROM prospector_runs ORDER BY id DESC LIMIT 1")->fetch() ?: null;
    $runs   = $pdo->query("SELECT * FROM prospector_runs ORDER BY id DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);

    $w = []; $p = [];
    if ($f_estado !== 'todos') { $w[] = 'estado=?'; $p[] = $f_estado; }
    if ($f_muni !== '')        { $w[] = 'municipio=?'; $p[] = $f_muni; }
    $sql = "SELECT * FROM prospector_negocios"
         . ($w ? ' WHERE ' . implode(' AND ', $w) : '')
         . " ORDER BY score DESC, reviews DESC LIMIT 120";
    $q = $pdo->prepare($sql); $q->execute($p);
    $negocios = $q->fetchAll(PDO::FETCH_ASSOC);
}
$plan = prospector_plan_default();

// Sugerencias para el buscador: los 78 pueblos y las categorías que YA viven en
// la base (heredadas de Encuéntralo). El campo sigue siendo libre — esto es un
// atajo para no escribir "Bayamón" con acento a mano, no una lista cerrada.
$sug_mun = []; $sug_cat = [];
try { $sug_mun = $pdo->query("SELECT nombre FROM municipios ORDER BY nombre")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
try { $sug_cat = $pdo->query("SELECT nombre FROM categorias WHERE activa=1 ORDER BY nombre")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
// Los rubros del plan van primero, y sin repetir.
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
  .wrap{max-width:1040px;margin:0 auto;padding:20px 18px 80px}
  h1{font-family:'Poppins',sans-serif;text-transform:uppercase;font-size:26px;margin:8px 0 4px}
  .sub{color:var(--muted);font-size:13.5px;margin:0 0 18px}
  .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px}
  .kpi{background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px 16px;box-shadow:var(--shadow-sm)}
  .kpi .l{font-size:11.5px;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.03em}
  .kpi .v{font-family:'Poppins',sans-serif;font-size:30px;line-height:1;margin-top:5px}
  .kpi.hot{background:linear-gradient(135deg,#241633,#0e0a16);color:#fff}.kpi.hot .l{color:#bdb4c9}
  .card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:16px 18px;margin-bottom:14px;box-shadow:var(--shadow-sm)}
  .card h2{font-family:'Poppins',sans-serif;text-transform:uppercase;font-size:15px;letter-spacing:.03em;margin:0 0 12px}
  .btn{display:inline-block;border:0;cursor:pointer;font-family:inherit;font-weight:800;font-size:13.5px;color:#fff;background:var(--tinta);padding:10px 16px;border-radius:10px;text-decoration:none}
  .btn.g{background:#fff;color:var(--tinta);border:1px solid var(--line)}
  .btn.sm{padding:7px 12px;font-size:12.5px;border-radius:9px}
  .aviso{background:#e8f7ef;border:1px solid #b7e3c9;color:#155e35;padding:11px 14px;border-radius:11px;margin-bottom:14px;font-size:13.5px}
  .malo{background:#fdecec;border:1px solid #f3c2c2;color:#8c1d1d;padding:11px 14px;border-radius:11px;margin-bottom:14px;font-size:13.5px}
  .filtros{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px;align-items:center}
  .chip{display:inline-block;padding:7px 13px;border-radius:99px;border:1px solid var(--line);background:#fff;color:var(--tinta);text-decoration:none;font-size:13px;font-weight:700}
  .chip.on{background:var(--tinta);color:#fff;border-color:var(--tinta)}
  select,input[type=text]{font-family:inherit;font-size:13.5px;padding:9px 11px;border:1px solid var(--line);border-radius:10px;background:#fff}
  /* ── tarjeta de negocio ── */
  .neg{display:grid;grid-template-columns:96px 1fr;gap:16px;background:#fff;border:1px solid var(--line);border-radius:16px;padding:16px 18px;margin-bottom:12px;box-shadow:var(--shadow-sm)}
  .sc{display:flex;flex-direction:column;align-items:center;justify-content:flex-start;gap:4px}
  .sc .n{font-family:'Poppins',sans-serif;font-weight:800;font-size:34px;line-height:1}
  .sc .b{width:100%;height:6px;border-radius:99px;background:#eee;overflow:hidden}
  .sc .b i{display:block;height:100%;border-radius:99px}
  .sc small{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);text-align:center;line-height:1.25}
  .neg h3{font-family:'Poppins',sans-serif;font-size:19px;margin:0 0 2px}
  .meta{font-size:12.5px;color:var(--muted);margin-bottom:9px}
  .mot{list-style:none;padding:0;margin:0 0 10px;font-size:13.5px}
  .mot li{padding:2px 0}
  .mot li::before{content:"·";color:var(--magenta,#c0395f);font-weight:900;margin-right:7px}
  .cont{display:flex;flex-wrap:wrap;gap:8px;font-size:13px;margin-bottom:10px}
  .cont span{background:#f6f3f0;border-radius:8px;padding:6px 10px}
  .cont a{color:var(--tinta);text-decoration:none;background:#f6f3f0;border-radius:8px;padding:6px 10px}
  .consejo{background:#fff8f2;border-left:3px solid var(--coral,#ff5c39);padding:10px 13px;border-radius:0 10px 10px 0;font-size:13.5px;line-height:1.5;margin-bottom:10px}
  .acc{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
  .badge{font-size:10.5px;font-weight:900;text-transform:uppercase;letter-spacing:.04em;border-radius:99px;padding:3px 9px;margin-left:6px}
  .b-demo{background:#fff0c2;color:#7a5b00}
  .b-est{background:#eee9f3;color:#4a3b5c}
  .vacio{text-align:center;color:var(--muted);padding:34px 10px;font-size:14px}
  @media(max-width:620px){.neg{grid-template-columns:1fr}.sc{flex-direction:row;align-items:center;gap:10px}.sc .b{width:80px}}
</style></head><body>
<?php $op_active='prospector'; require __DIR__.'/_ops_top.php'; ?>
<div class="wrap">
  <h1>El Prospector</h1>
  <p class="sub">Radar de oportunidades. Negocios públicos que podrían necesitar Crecer, puntuados con datos reales de Google.
     <b>No contacta a nadie</b> — prepara el trabajo y tú decides.</p>

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
    <div class="kpi hot"><div class="l">En el radar</div><div class="v"><?= number_format($kpi['total']) ?></div></div>
    <div class="kpi"><div class="l">Sin tocar</div><div class="v"><?= number_format($kpi['nuevos']) ?></div></div>
    <div class="kpi"><div class="l">Contactados</div><div class="v"><?= number_format($kpi['contactados']) ?></div></div>
    <div class="kpi"><div class="l">Se hicieron clientes</div><div class="v"><?= number_format($kpi['clientes']) ?></div></div>
  </div>

  <!-- ── Barrido ─────────────────────────────────────────── -->
  <div class="card">
    <h2><?= ico('search') ?> Salir a buscar</h2>
    <?php if (!prospector_configurado()): ?>
      <p style="font-size:13.5px;line-height:1.6;color:#8c1d1d;margin:0 0 12px">
        Falta <b>PLACES_API_KEY</b> en <code>includes/config.local.php</code> (necesita billing de Google Cloud activo).
        Mientras tanto puedes meter negocios de ejemplo para ver cómo se comporta la fórmula.</p>
      <form method="post" style="display:inline"><?= csrf_field() ?>
        <input type="hidden" name="accion" value="demo">
        <button class="btn g">Meter 3 ejemplos</button>
      </form>
      <form method="post" style="display:inline"><?= csrf_field() ?>
        <input type="hidden" name="accion" value="limpiar_demo">
        <button class="btn g">Borrar ejemplos</button>
      </form>
    <?php else: ?>
      <!-- Texto libre con sugerencias: la lista del plan es un atajo, no una jaula.
           Puedes escribir cualquier rubro y cualquier pueblo de Puerto Rico. -->
      <form method="post" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center"><?= csrf_field() ?>
        <input type="hidden" name="accion" value="buscar">
        <input type="text" name="categoria" list="l-cat" autocomplete="off" style="min-width:230px"
               placeholder="Rubro — vacío usa el de esta semana (<?= $h($plan['categorias'][(int)date('W') % count($plan['categorias'])]) ?>)">
        <datalist id="l-cat">
          <?php foreach ($sug_cat as $c): ?><option value="<?= $h($c) ?>"><?php endforeach; ?>
        </datalist>
        <input type="text" name="municipio" list="l-mun" autocomplete="off" style="min-width:200px"
               id="p-mun" placeholder="Pueblo — vacío barre los <?= count($plan['municipios']) ?> del plan">
        <datalist id="l-mun">
          <?php foreach ($sug_mun as $m): ?><option value="<?= $h($m) ?>"><?php endforeach; ?>
        </datalist>
        <label style="display:inline-flex;align-items:center;gap:7px;font-size:13.5px;font-weight:700;
                      background:#fff4ef;border:1px solid #ffd9c9;border-radius:10px;padding:9px 13px;cursor:pointer">
          <input type="checkbox" name="isla" value="1" id="p-isla"
                 onchange="document.getElementById('p-mun').disabled=this.checked">
          Toda la isla (<?= count($sug_mun) ?> pueblos)
        </label>
        <button class="btn"><?= ico('bolt') ?> Barrer ahora</button>
      </form>
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
        <?= $h($ultima['created_at']) ?> ·
        disparo <b><?= $h($ultima['disparo']) ?></b><?= $ultima['estado']==='error' ? ' · <b style="color:#8c1d1d">error</b>' : '' ?>
      </p>
    <?php endif; ?>
  </div>

  <!-- ── Filtros ─────────────────────────────────────────── -->
  <div class="filtros">
    <?php foreach (['nuevo'=>'Sin tocar','contactado'=>'Contactados','interesado'=>'Interesados','cliente'=>'Clientes','descartado'=>'Descartados','todos'=>'Todos'] as $k=>$v): ?>
      <a class="chip <?= $f_estado===$k?'on':'' ?>" href="?estado=<?= $k ?><?= $f_muni?'&muni='.urlencode($f_muni):'' ?>"><?= $v ?></a>
    <?php endforeach; ?>
    <?php if ($municipios): ?>
      <form method="get" style="margin-left:auto">
        <input type="hidden" name="estado" value="<?= $h($f_estado) ?>">
        <select name="muni" onchange="this.form.submit()">
          <option value="">Todos los pueblos</option>
          <?php foreach ($municipios as $m): ?>
            <option value="<?= $h($m) ?>" <?= $f_muni===$m?'selected':'' ?>><?= $h($m) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    <?php endif; ?>
  </div>

  <!-- ── Tarjetas ────────────────────────────────────────── -->
  <?php if (!$negocios): ?>
    <div class="card vacio">
      Todavía no hay negocios en este filtro.<br>
      <?= prospector_configurado() ? 'Dale a <b>Barrer ahora</b> para salir a buscar.' : 'Mete unos ejemplos o configura la clave de Places.' ?>
    </div>
  <?php endif; ?>

  <?php foreach ($negocios as $n):
      $motivos = json_decode((string)$n['motivos'], true) ?: [];
      $sc = (int)$n['score'];
      $col = $sc >= 80 ? '#c0395f' : ($sc >= 60 ? '#ff5c39' : ($sc >= 40 ? '#c9a227' : '#9b93a6'));
  ?>
    <div class="neg">
      <div class="sc">
        <div class="n" style="color:<?= $col ?>"><?= $sc ?></div>
        <div class="b"><i style="width:<?= $sc ?>%;background:<?= $col ?>"></i></div>
        <small><?= $h(prospector_etiqueta($sc)) ?></small>
      </div>
      <div>
        <h3><?= $h($n['nombre']) ?>
          <?php if ($n['origen']==='demo'): ?><span class="badge b-demo">ejemplo</span><?php endif; ?>
          <?php if ($n['estado']!=='nuevo'): ?><span class="badge b-est"><?= $h($n['estado']) ?></span><?php endif; ?>
        </h3>
        <div class="meta">
          <?= $h($n['tipo_google'] ?: $n['categoria']) ?><?= $n['municipio'] ? ' · ' . $h($n['municipio']) : '' ?>
          <?php if ($n['rating']): ?> · <?= $h($n['rating']) ?> ★ (<?= (int)$n['reviews'] ?> reseñas)<?php endif; ?>
        </div>

        <?php if ($motivos): ?><ul class="mot"><?php foreach ($motivos as $m): ?><li><?= $h($m) ?></li><?php endforeach; ?></ul><?php endif; ?>

        <div class="cont">
          <?php if ($n['telefono']): ?><a href="tel:<?= $h(preg_replace('/[^0-9+]/','',$n['telefono'])) ?>"><?= $h($n['telefono']) ?></a><?php endif; ?>
          <?php if (!empty($n['email'])): ?><a href="mailto:<?= $h($n['email']) ?>" style="font-weight:800;color:#0a6b4f"><?= $h($n['email']) ?></a><?php endif; ?>
          <?php if (!empty($n['instagram'])): ?><a href="https://instagram.com/<?= $h($n['instagram']) ?>" target="_blank" rel="noopener">@<?= $h($n['instagram']) ?></a><?php endif; ?>
          <?php if (!empty($n['facebook'])): ?><a href="https://facebook.com/<?= $h($n['facebook']) ?>" target="_blank" rel="noopener">Facebook</a><?php endif; ?>
          <?php if ($n['direccion']): ?><span><?= $h($n['direccion']) ?></span><?php endif; ?>
          <?php if ($n['website']): ?><a href="<?= $h($n['website']) ?>" target="_blank" rel="noopener"><?= !empty($n['web_es_social']) ? 'su “web” = su red' : 'web' ?></a><?php else: ?><span style="color:#8c1d1d;font-weight:700">sin web</span><?php endif; ?>
          <?php if ($n['maps_url']): ?><a href="<?= $h($n['maps_url']) ?>" target="_blank" rel="noopener">Google Maps</a><?php endif; ?>
        </div>
        <?php if (!empty($n['contacto_at']) && empty($n['email'])): ?>
          <div class="meta" style="margin-top:4px;color:#8c1d1d">Rastreado y sin email público — entra por teléfono<?= !empty($n['instagram']) ? ' o Instagram' : '' ?>.</div>
        <?php endif; ?>

        <?php if ($n['consejo']): ?>
          <div class="consejo"><?= $h($n['consejo']) ?></div>
        <?php endif; ?>

        <div class="acc">
          <?php if (empty($n['contacto_at'])): ?>
            <form method="post" style="display:inline"><?= csrf_field() ?>
              <input type="hidden" name="accion" value="contacto"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
              <button class="btn g sm">Buscarle el contacto</button>
            </form>
          <?php endif; ?>
          <?php if (!$n['consejo']): ?>
            <form method="post" style="display:inline"><?= csrf_field() ?>
              <input type="hidden" name="accion" value="consejo"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
              <button class="btn g sm">Que opine el Prospector</button>
            </form>
          <?php endif; ?>
          <?php foreach (['contactado'=>'Contactado','interesado'=>'Interesado','cliente'=>'Es cliente','descartado'=>'Descartar'] as $k=>$v):
                if ($n['estado']===$k) continue; ?>
            <form method="post" style="display:inline"><?= csrf_field() ?>
              <input type="hidden" name="accion" value="estado"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
              <input type="hidden" name="estado" value="<?= $k ?>">
              <button class="btn g sm"><?= $v ?></button>
            </form>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>

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
</body></html>
