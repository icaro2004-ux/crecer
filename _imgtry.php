<?php
// ============================================================
//  CRECER — LABORATORIO de imágenes (interno)  _imgtry.php?k=crecer
//  Banco de investigación para afinar al agente creador.
//   FLUJO (intacto):  copy → agente V3 → escena → prompt → gpt-image-1 → imagen
//   Capa de investigación (añadida): historial persistente, calificación 1-10,
//   observaciones, "Analizar imagen" (crítica publicitaria por visión),
//   hipótesis por experimento y búsqueda. NADA se borra.
// ============================================================
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/ia.php';
require __DIR__ . '/includes/agentes.php';
require __DIR__ . '/includes/image_messenger.php';

if (($_GET['k'] ?? '') !== 'crecer') { http_response_code(403); exit('Añade ?k=crecer'); }

/** Carga un experimento por id (o null). */
function lab_exp(PDO $pdo, int $id): ?array {
    try { $s = $pdo->prepare("SELECT * FROM crecer_lab_experimentos WHERE id=?"); $s->execute([$id]);
          $r = $s->fetch(PDO::FETCH_ASSOC); return $r ?: null; } catch (Throwable $e) { return null; }
}
/** URL de uploads → ruta absoluta en disco. */
function lab_abs(string $url): string {
    $rel = ltrim(str_replace(rtrim(UPLOADS_URL, '/'), '', $url), '/');
    return rtrim(UPLOADS_PATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
}

$modo    = $_POST['modo']   ?? '';
$accion  = $_POST['accion'] ?? '';
$prompt  = trim((string)($_POST['prompt'] ?? ''));
$copy_in = trim((string)($_POST['copy'] ?? ''));
$marca_id = (int)($_POST['marca'] ?? 0);
$aspect  = (string)($_POST['aspect'] ?? '1:1');
$hipotesis = trim((string)($_POST['hipotesis'] ?? ''));

$escena = ''; $ag_info = ''; $ag_err = '';
$img_url = ''; $img_info = ''; $img_err = '';
$exp = null; $flash = ''; $save_warn = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    @set_time_limit(0);

    // ---- MODO 1 — el AGENTE V3: copy → escena (INTACTO) ----
    if ($modo === 'escena' && $copy_in !== '' && $marca_id) {
        try {
            $m = leer_marca($pdo, $marca_id);
            $b = image_messenger_build($pdo, $marca_id, $m, $copy_in);
            $cfg = defined('IMAGE_CREATIVE_MODEL') ? IMAGE_CREATIVE_MODEL : 'openai:creative';
            $d = director_creativo_llm($pdo, $marca_id, $b['sistema'], $b['mensaje'], $cfg, ['strict'=>true]);
            $escena = trim((string)($d['texto'] ?? ''));
            $ag_info = 'director: ' . ($d['modelo'] ?? '?') . ' · ' . round(((int)($d['dur_ms'] ?? 0))/1000, 1) . 's';
            $prompt = $escena;   // pre-carga la escena en el generador de abajo
        } catch (Throwable $e) { $ag_err = $e->getMessage(); }
    }

    // ---- MODO 2 — PROMPT DIRECTO → gpt-image-1 (INTACTO) ----
    if ($modo === 'imagen' && $prompt !== '') {
        try {
            $t0 = microtime(true);
            $r  = openai_imagen($prompt, ['aspect' => $aspect]);
            $seg = round(microtime(true) - $t0, 1);
            $rel = 'pruebas/lab_' . substr(md5((string)microtime(true)), 0, 8) . '.png';
            $abs = rtrim(UPLOADS_PATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            @mkdir(dirname($abs), 0775, true);
            @file_put_contents($abs, $r['data']);
            $img_url  = rtrim(UPLOADS_URL, '/') . '/' . $rel;
            $img_info = 'modelo ' . ($r['modelo'] ?? '?') . ' · ' . $seg . 's · ' . strlen($r['data']) . ' bytes';

            // ---- (AÑADIDO) guardar el experimento — nada del flujo cambió arriba ----
            $s_copy   = trim((string)($_POST['copy_txt'] ?? ''));
            $s_escena = trim((string)($_POST['escena_h'] ?? ''));
            $s_marca  = (int)($_POST['marca'] ?? 0);
            $neg = '';
            if ($s_marca) { $q = $pdo->prepare("SELECT nombre_negocio FROM crecer_marca WHERE id=?"); $q->execute([$s_marca]); $neg = (string)$q->fetchColumn(); }
            try {
                $ins = $pdo->prepare("INSERT INTO crecer_lab_experimentos
                    (marca_id,negocio,hipotesis,copy_txt,escena,prompt,imagen,bytes,modelo,segundos,estado)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                $ins->execute([$s_marca ?: null, $neg ?: null, $hipotesis ?: null, $s_copy ?: null, $s_escena ?: null,
                               $prompt, $img_url, strlen($r['data']), $r['modelo'] ?? null, $seg, 'ok']);
                $exp = lab_exp($pdo, (int)$pdo->lastInsertId());
            } catch (Throwable $e) { $save_warn = 'No se guardó en el historial (¿corriste la migración?): ' . $e->getMessage(); }
        } catch (Throwable $e) { $img_err = $e->getMessage(); }
    }

    // ---- (AÑADIDO) calificar / observaciones ----
    if ($accion === 'calificar') {
        $id = (int)($_POST['exp_id'] ?? 0);
        $pu = ($_POST['puntuacion'] ?? '') === '' ? null : max(1, min(10, (int)$_POST['puntuacion']));
        $ob = trim((string)($_POST['observaciones'] ?? ''));
        try { $pdo->prepare("UPDATE crecer_lab_experimentos SET puntuacion=?, observaciones=? WHERE id=?")->execute([$pu, $ob ?: null, $id]);
              $flash = 'Calificación guardada.'; } catch (Throwable $e) { $flash = 'Error: ' . $e->getMessage(); }
        $exp = lab_exp($pdo, $id);
    }

    // ---- (AÑADIDO) analizar imagen: crítica publicitaria por VISIÓN (solo la imagen) ----
    if ($accion === 'analizar') {
        $id = (int)($_POST['exp_id'] ?? 0);
        $e0 = lab_exp($pdo, $id);
        if ($e0 && !empty($e0['imagen'])) {
            try {
                $abs = lab_abs((string)$e0['imagen']);
                if (!is_file($abs)) throw new RuntimeException('Imagen no encontrada en disco.');
                $bin = (string)file_get_contents($abs);
                $mime = (function_exists('mime_content_type') ? mime_content_type($abs) : '') ?: 'image/png';
                $sys = "Eres un Director Creativo de publicidad de clase mundial evaluando una pieza para redes (Facebook/Instagram) "
                     . "de un micronegocio. NO describas la imagen literalmente. Haz una CRÍTICA PUBLICITARIA honesta y crítica, sin "
                     . "floritura. Responde EXACTAMENTE en este formato y nada más:\n"
                     . "Calificación general: X/10\n\nFortalezas\n- ...\n\nDebilidades\n- ...\n\nEmoción\n...\n\n"
                     . "Mensaje percibido\n...\n\n¿Detiene el scroll?\nSí / No — por qué en una línea\n\n"
                     . "¿Se siente como una campaña profesional?\nSí / Parcialmente / No\n\nMejoras sugeridas\n- ...";
                $cfg = resolver_modelo_ia(defined('IMAGE_CREATIVE_MODEL') ? IMAGE_CREATIVE_MODEL : 'openai:creative');
                $mdl = strpos($cfg, ':') !== false ? explode(':', $cfg, 2)[1] : $cfg;
                $out = openai_chat($sys, 'Evalúa esta pieza publicitaria como Director Creativo.', $mdl, [
                    'imagenes' => [['data' => base64_encode($bin), 'mime' => $mime]],
                    'max_tokens' => 900, 'max_reintentos' => 0,
                ]);
                $txt = trim((string)($out['texto'] ?? ''));
                $pdo->prepare("UPDATE crecer_lab_experimentos SET analisis=? WHERE id=?")->execute([$txt, $id]);
                $flash = 'Análisis listo.';
            } catch (Throwable $e) { $flash = 'Error al analizar: ' . $e->getMessage(); }
        }
        $exp = lab_exp($pdo, $id);
    }
}

// Ver un experimento del historial (?exp=ID)
if (!$exp && isset($_GET['exp'])) $exp = lab_exp($pdo, (int)$_GET['exp']);

// ---- Búsqueda del historial (GET) ----
$fq     = trim((string)($_GET['q'] ?? ''));
$fmarca = (int)($_GET['fmarca'] ?? 0);
$fpunt  = (int)($_GET['fpunt'] ?? 0);
$fdesde = trim((string)($_GET['fdesde'] ?? ''));
$hist = []; $hist_err = '';
try {
    $w = []; $a = [];
    if ($fq !== '')   { $w[] = '(prompt LIKE ? OR copy_txt LIKE ? OR negocio LIKE ? OR observaciones LIKE ?)'; $like = '%' . $fq . '%'; array_push($a, $like, $like, $like, $like); }
    if ($fmarca)      { $w[] = 'marca_id = ?'; $a[] = $fmarca; }
    if ($fpunt)       { $w[] = 'puntuacion >= ?'; $a[] = $fpunt; }
    if ($fdesde !== ''){ $w[] = 'creado >= ?'; $a[] = $fdesde . ' 00:00:00'; }
    $sql = "SELECT id,creado,negocio,imagen,prompt,puntuacion,estado,observaciones FROM crecer_lab_experimentos"
         . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY id DESC LIMIT 120";
    $st = $pdo->prepare($sql); $st->execute($a); $hist = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $hist_err = $e->getMessage(); }

$marcas = $pdo->query("SELECT id, nombre_negocio FROM crecer_marca ORDER BY id DESC LIMIT 60")->fetchAll(PDO::FETCH_ASSOC);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Laboratorio de imágenes · Crecer</title>
<style>
  *{box-sizing:border-box}
  body{font-family:system-ui,-apple-system,sans-serif;max-width:820px;margin:0 auto;padding:22px 18px 80px;background:#faf9f8;color:#231F20}
  h1{font-size:23px;margin:0 0 2px}
  h2{font-size:16px;margin:26px 0 10px;color:#EF4375}
  p.sub{color:#6E6A67;font-size:14px;margin:0 0 16px}
  .box{background:#fff;border:1px solid #E9E7E4;border-radius:16px;padding:18px;margin-bottom:16px}
  textarea{width:100%;min-height:120px;font:15px/1.55 system-ui;padding:12px;border:1.5px solid #E9E7E4;border-radius:12px;background:#fff}
  textarea.sm{min-height:64px}
  textarea:focus,select:focus,input:focus{outline:0;border-color:#EF4375}
  select,input[type=text],input[type=date]{padding:11px;border-radius:10px;border:1.5px solid #E9E7E4;background:#fff;font:15px system-ui;width:100%;margin-bottom:10px}
  label.f{display:block;font-weight:700;font-size:12.5px;color:#6E6A67;margin:2px 0 4px}
  .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px}
  button{background:linear-gradient(135deg,#FF6B3D,#EF4375);color:#fff;border:0;padding:13px 24px;border-radius:13px;font-weight:800;font-size:15px;cursor:pointer}
  button.gho{background:#fff;border:1.5px solid #E9E7E4;color:#333}
  button.teal{background:#00A49F}
  img.res{width:100%;border-radius:14px;margin-top:6px;border:1px solid #eee;display:block}
  .err{background:#fdeaea;color:#b42318;padding:12px 14px;border-radius:11px;margin-top:12px;font-size:13.5px;white-space:pre-wrap;line-height:1.5}
  .warn{background:#fff4d6;color:#8a5a00;padding:10px 13px;border-radius:10px;margin-top:10px;font-size:13px}
  .ok{background:#e6f6ee;color:#0d7a44;padding:10px 13px;border-radius:10px;margin-bottom:12px;font-size:13.5px;font-weight:600}
  .info{color:#00827e;font-weight:700;font-size:12.5px;margin:8px 0}
  .escena{background:#f4f7ff;border:1px solid #dbe4ff;border-radius:12px;padding:13px 15px;margin-top:12px;font-size:14px;line-height:1.55;white-space:pre-wrap}
  .load{display:none;color:#6E6A67;margin-top:12px;font-weight:600}
  small{color:#888}
  details{margin-top:10px}details summary{cursor:pointer;font-weight:700;font-size:13px;color:#EF4375}
  details .escena{margin-top:8px}
  .stars{display:inline-flex;flex-direction:row-reverse;justify-content:flex-end}
  .stars input{display:none}
  .stars label{font-size:27px;color:#dcdcdc;cursor:pointer;padding:0 1px;line-height:1;transition:color .08s}
  .stars label:hover,.stars label:hover ~ label,.stars input:checked ~ label{color:#FFB400}
  .crit{background:#231F20;color:#f4f2f0;border-radius:12px;padding:15px 17px;margin-top:12px;font-size:13.5px;line-height:1.6;white-space:pre-wrap}
  .hist{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px}
  .hcard{background:#fff;border:1px solid #E9E7E4;border-radius:12px;overflow:hidden;text-decoration:none;color:inherit;display:block}
  .hcard img{width:100%;height:110px;object-fit:cover;display:block;background:#eee}
  .hcard .m{padding:8px 10px}
  .hcard .p{font-size:11.5px;color:#4A434F;line-height:1.35;max-height:48px;overflow:hidden}
  .hcard .t{font-size:11px;color:#999;margin-top:4px}
  .badge{display:inline-block;background:#FFF3D0;color:#8a5a00;font-weight:800;font-size:11px;padding:2px 7px;border-radius:99px}
  .filtros{display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:8px;align-items:end}
  @media(max-width:640px){.filtros{grid-template-columns:1fr 1fr}}
</style></head>
<body>
<h1>🧪 Laboratorio de imágenes</h1>
<p class="sub">Banco de investigación. Prueba qué escena escribe el agente, genera, califica y analiza — para construir evidencia de qué produce mejores campañas.</p>
<?php if ($flash): ?><div class="ok"><?= $h($flash) ?></div><?php endif; ?>

<h2>1 · El agente V3 — copy → escena</h2>
<div class="box">
  <form method="post" onsubmit="document.getElementById('l1').style.display='block'">
    <input type="hidden" name="modo" value="escena">
    <label class="f">Negocio</label>
    <select name="marca" required>
      <option value="">— Elige el negocio —</option>
      <?php foreach ($marcas as $mm): ?>
        <option value="<?= (int)$mm['id'] ?>" <?= $marca_id===(int)$mm['id']?'selected':'' ?>>#<?= (int)$mm['id'] ?> · <?= $h($mm['nombre_negocio']) ?></option>
      <?php endforeach; ?>
    </select>
    <label class="f">Copy del post</label>
    <textarea name="copy" placeholder="Pega el copy del post…"><?= $h($copy_in) ?></textarea>
    <div class="row"><button type="submit">Ver la escena que escribe el agente</button> <small>~10s</small></div>
    <div class="load" id="l1">🧠 El agente está pensando…</div>
  </form>
  <?php if ($ag_err): ?><div class="err">❌ <?= $h($ag_err) ?></div><?php endif; ?>
  <?php if ($escena): ?><div class="info"><?= $h($ag_info) ?></div><div class="escena"><?= $h($escena) ?></div>
    <p class="sub" style="margin:10px 0 0">↓ Ya cargué esa escena abajo — dale "Generar imagen" para verla.</p><?php endif; ?>
</div>

<h2>2 · Prompt → gpt-image-1 (directo)</h2>
<div class="box">
  <form method="post" onsubmit="document.getElementById('l2').style.display='block'">
    <input type="hidden" name="modo" value="imagen">
    <input type="hidden" name="marca" value="<?= (int)$marca_id ?>">
    <input type="hidden" name="copy_txt" value="<?= $h($copy_in) ?>">
    <input type="hidden" name="escena_h" value="<?= $h($escena) ?>">
    <label class="f">Hipótesis <small>(opcional — qué quieres comprobar)</small></label>
    <input type="text" name="hipotesis" value="<?= $h($hipotesis) ?>" placeholder="Ej.: comprobar si la frase 'world-class advertising agency' mejora el resultado">
    <label class="f">Prompt</label>
    <textarea name="prompt" placeholder="La escena de arriba, o cualquier prompt (mejor en inglés)…"><?= $h($prompt) ?></textarea>
    <div class="row">
      <select name="aspect" style="width:auto;margin:0">
        <option value="1:1"  <?= $aspect==='1:1'?'selected':'' ?>>Cuadrado 1:1</option>
        <option value="4:5"  <?= $aspect==='4:5'?'selected':'' ?>>Vertical 4:5</option>
        <option value="16:9" <?= $aspect==='16:9'?'selected':'' ?>>Horizontal 16:9</option>
      </select>
      <button type="submit">Generar imagen</button> <small>~48s</small>
    </div>
    <div class="load" id="l2">🎨 Generando… (no cierres, ~48s)</div>
  </form>
  <?php if ($img_err): ?><div class="err">❌ <?= $h($img_err) ?></div><?php endif; ?>
  <?php if ($save_warn): ?><div class="warn">⚠️ <?= $h($save_warn) ?></div><?php endif; ?>
  <?php // Fallback: si generó pero no se pudo guardar (sin migración), muestra la imagen igual.
  if (!$exp && $img_url): ?><div class="info"><?= $h($img_info) ?></div><img class="res" src="<?= $h($img_url) ?>" alt=""><?php endif; ?>
</div>

<?php if ($exp): ?>
<h2>Resultado del experimento #<?= (int)$exp['id'] ?></h2>
<div class="box">
  <div class="info"><?= $h(($exp['negocio'] ? $exp['negocio'] . ' · ' : '') . $exp['creado'] . ' · ' . $exp['modelo'] . ' · ' . $exp['segundos'] . 's · ' . number_format((int)$exp['bytes']) . ' bytes · ' . $exp['estado']) ?></div>
  <?php if (!empty($exp['imagen'])): ?><img class="res" src="<?= $h($exp['imagen']) ?>" alt=""><?php endif; ?>
  <?php if (!empty($exp['hipotesis'])): ?><p style="margin:12px 0 0;font-size:13.5px"><b>Hipótesis:</b> <?= $h($exp['hipotesis']) ?></p><?php endif; ?>
  <?php if (!empty($exp['escena'])): ?><details><summary>Ver escena del agente</summary><div class="escena"><?= $h($exp['escena']) ?></div></details><?php endif; ?>
  <?php if (!empty($exp['prompt'])): ?><details><summary>Ver prompt enviado a gpt-image-1</summary><div class="escena"><?= $h($exp['prompt']) ?></div></details><?php endif; ?>

  <!-- Calificación 1–10 + observaciones -->
  <form method="post" style="margin-top:16px">
    <input type="hidden" name="accion" value="calificar">
    <input type="hidden" name="exp_id" value="<?= (int)$exp['id'] ?>">
    <label class="f">Calificación (1–10)</label>
    <div class="stars">
      <?php for ($i=10;$i>=1;$i--): ?>
        <input type="radio" name="puntuacion" id="st<?= $i ?>" value="<?= $i ?>" <?= ((int)$exp['puntuacion']===$i)?'checked':'' ?>>
        <label for="st<?= $i ?>" title="<?= $i ?>/10">★</label>
      <?php endfor; ?>
      <span style="margin-left:10px;font-weight:800;color:#8a5a00"><?= $exp['puntuacion'] ? (int)$exp['puntuacion'].'/10' : '' ?></span>
    </div>
    <label class="f" style="margin-top:12px">Observaciones</label>
    <textarea class="sm" name="observaciones" placeholder="demasiado stock · excelente composición · mucho texto · no comunica el beneficio · parece plantilla…"><?= $h($exp['observaciones']) ?></textarea>
    <div class="row"><button type="submit" class="gho">Guardar calificación</button></div>
  </form>

  <!-- Analizar imagen (crítica publicitaria por visión) -->
  <form method="post" style="margin-top:8px" onsubmit="document.getElementById('l3').style.display='block'">
    <input type="hidden" name="accion" value="analizar">
    <input type="hidden" name="exp_id" value="<?= (int)$exp['id'] ?>">
    <button type="submit" class="teal">🔍 Analizar imagen (crítica del Director)</button> <small>~10s</small>
    <div class="load" id="l3">🧠 Evaluando la pieza…</div>
  </form>
  <?php if (!empty($exp['analisis'])): ?><div class="crit"><?= $h($exp['analisis']) ?></div><?php endif; ?>
</div>
<?php endif; ?>

<h2>Historial de experimentos</h2>
<div class="box">
  <form method="get" class="filtros">
    <input type="hidden" name="k" value="crecer">
    <div><label class="f">Buscar (prompt / copy / negocio / obs.)</label><input type="text" name="q" value="<?= $h($fq) ?>" style="margin:0"></div>
    <div><label class="f">Negocio</label><select name="fmarca" style="margin:0"><option value="0">Todos</option><?php foreach ($marcas as $mm): ?><option value="<?= (int)$mm['id'] ?>" <?= $fmarca===(int)$mm['id']?'selected':'' ?>><?= $h($mm['nombre_negocio']) ?></option><?php endforeach; ?></select></div>
    <div><label class="f">Puntuación ≥</label><select name="fpunt" style="margin:0"><option value="0">Cualquiera</option><?php for($i=10;$i>=1;$i--): ?><option value="<?= $i ?>" <?= $fpunt===$i?'selected':'' ?>><?= $i ?></option><?php endfor; ?></select></div>
    <div><label class="f">Desde</label><input type="date" name="fdesde" value="<?= $h($fdesde) ?>" style="margin:0"></div>
    <div style="grid-column:1/-1"><button type="submit" class="gho">Filtrar</button> <a href="?k=crecer" style="margin-left:8px;color:#888">limpiar</a></div>
  </form>
  <?php if ($hist_err): ?><div class="warn">No pude leer el historial (¿corriste <code>migrations/2026-07-25_lab_experimentos.sql</code>?): <?= $h($hist_err) ?></div><?php endif; ?>
  <div class="hist" style="margin-top:14px">
    <?php foreach ($hist as $x): ?>
      <a class="hcard" href="?k=crecer&exp=<?= (int)$x['id'] ?>">
        <?php if (!empty($x['imagen'])): ?><img src="<?= $h($x['imagen']) ?>" alt=""><?php endif; ?>
        <div class="m">
          <?php if ($x['puntuacion']): ?><span class="badge">★ <?= (int)$x['puntuacion'] ?>/10</span> <?php endif; ?>
          <?php if ($x['estado']!=='ok'): ?><span class="badge" style="background:#fdeaea;color:#b42318"><?= $h($x['estado']) ?></span><?php endif; ?>
          <div class="p"><?= $h(mb_strimwidth((string)($x['negocio'] ?: $x['prompt']), 0, 90, '…')) ?></div>
          <div class="t">#<?= (int)$x['id'] ?> · <?= $h(substr((string)$x['creado'],0,16)) ?></div>
        </div>
      </a>
    <?php endforeach; ?>
    <?php if (!$hist && !$hist_err): ?><p style="color:#888">Aún no hay experimentos. Genera el primero arriba.</p><?php endif; ?>
  </div>
</div>
</body></html>
