<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Configuración (núcleo)
//  panel/configuracion.php?marca=<id>&tab=negocio|redes|plan|cuenta
//
//  4 secciones:
//   - negocio: editar el perfil de la marca (alimenta a la IA).
//   - redes:   conectar IG/FB (enlaza a conectar.php).
//   - plan:    ver plan y gestionar/cancelar (Stripe portal).
//   - cuenta:  datos del usuario + cambiar contraseña.
//
//  Formularios PRG (post → redirect → get) con CSRF.
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/agentes.php';      // leer_marca()
require __DIR__ . '/../includes/suscripcion.php';
require __DIR__ . '/../includes/meta.php';          // conexion_de_marca()
requiere_login();

$usuario = usuario_actual($pdo);
$USUARIO_ID = (int)$usuario['id'];
$marca = marca_del_usuario($pdo, $USUARIO_ID, isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }
$marca_id = (int)$marca['id'];
$BASE = '/crecer/panel';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$tab = $_GET['tab'] ?? 'negocio';
if (!in_array($tab, ['negocio','redes','plan','cuenta'], true)) $tab = 'negocio';

// ── POST (PRG) ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $volver = "$BASE/configuracion.php?marca=$marca_id&tab=";
    if (!csrf_ok()) { header('Location: ' . $volver . 'negocio&error=' . urlencode('Token vencido. Recarga e intenta.')); exit; }

    if ($accion === 'negocio') {
        $prods_raw = trim($_POST['productos'] ?? '');
        $prods = [];
        foreach (preg_split('/\r\n|\r|\n/', $prods_raw) as $linea) {
            $linea = trim($linea);
            if ($linea !== '') $prods[] = ['nombre' => $linea];
        }
        $mun = ($_POST['municipio_id'] ?? '') !== '' ? (int)$_POST['municipio_id'] : null;
        $cat = ($_POST['categoria_id'] ?? '') !== '' ? (int)$_POST['categoria_id'] : null;
        $pref = $_POST['contacto_preferencia'] ?? '';
        if (!in_array($pref, ['whatsapp','dm','ambas','todas'], true)) $pref = null;
        $pdo->prepare(
            "UPDATE crecer_marca SET
                nombre_negocio=?, descripcion=?, voz=?, productos=?, publico_objetivo=?,
                ofertas=?, municipio_id=?, categoria_id=?, instagram=?, facebook=?, whatsapp=?,
                contacto_preferencia=?, updated_at=NOW()
             WHERE id=? AND usuario_id=?"
        )->execute([
            trim($_POST['nombre_negocio'] ?? '') ?: $marca['nombre_negocio'],
            trim($_POST['descripcion'] ?? '') ?: null,
            trim($_POST['voz'] ?? '') ?: null,
            $prods ? json_encode($prods, JSON_UNESCAPED_UNICODE) : null,
            trim($_POST['publico_objetivo'] ?? '') ?: null,
            trim($_POST['ofertas'] ?? '') ?: null,
            $mun, $cat,
            trim($_POST['instagram'] ?? '') ?: null,
            trim($_POST['facebook'] ?? '') ?: null,
            trim($_POST['whatsapp'] ?? '') ?: null,
            $pref,
            $marca_id, $USUARIO_ID,
        ]);
        header('Location: ' . $volver . 'negocio&ok=1'); exit;
    }

    if ($accion === 'cuenta_datos') {
        $nombre = trim($_POST['nombre'] ?? '');
        $email  = trim($_POST['email'] ?? '');
        if ($nombre === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            header('Location: ' . $volver . 'cuenta&error=' . urlencode('Nombre o email no válido.')); exit;
        }
        // Email único (excepto el propio).
        $dup = $pdo->prepare("SELECT id FROM usuarios WHERE email=? AND id<>?");
        $dup->execute([$email, $USUARIO_ID]);
        if ($dup->fetchColumn()) {
            header('Location: ' . $volver . 'cuenta&error=' . urlencode('Ese email ya está en uso.')); exit;
        }
        $pdo->prepare("UPDATE usuarios SET nombre=?, email=?, updated_at=NOW() WHERE id=?")
            ->execute([$nombre, $email, $USUARIO_ID]);
        header('Location: ' . $volver . 'cuenta&ok=1'); exit;
    }

    if ($accion === 'cuenta_pass') {
        $actual = (string)($_POST['actual'] ?? '');
        $nueva  = (string)($_POST['nueva'] ?? '');
        $conf   = (string)($_POST['confirmar'] ?? '');
        if (!password_verify($actual, $usuario['password'])) {
            header('Location: ' . $volver . 'cuenta&error=' . urlencode('La contraseña actual no es correcta.')); exit;
        }
        if (strlen($nueva) < 8 || $nueva !== $conf) {
            header('Location: ' . $volver . 'cuenta&error=' . urlencode('La nueva contraseña debe tener 8+ caracteres y coincidir.')); exit;
        }
        $pdo->prepare("UPDATE usuarios SET password=?, updated_at=NOW() WHERE id=?")
            ->execute([password_hash($nueva, PASSWORD_DEFAULT), $USUARIO_ID]);
        header('Location: ' . $volver . 'cuenta&okpass=1'); exit;
    }

    if ($accion === 'autopilot') {
        $on = !empty($_POST['autopilot']) ? 1 : 0;
        $n  = max(1, min(7, (int)($_POST['autopilot_n'] ?? 3)));
        $pdo->prepare("UPDATE crecer_marca SET autopilot=?, autopilot_n=?, updated_at=NOW() WHERE id=? AND usuario_id=?")
            ->execute([$on, $n, $marca_id, $USUARIO_ID]);
        header('Location: ' . $volver . 'negocio&ok=1'); exit;
    }

    // "Corre el corillo AHORA" — dispara el trabajo autónomo a demanda (no espera al cron semanal).
    if ($accion === 'corre_ahora') {
        $elegible = (function_exists('activacion_de_prueba') && activacion_de_prueba($usuario['email'] ?? null));
        if (!$elegible) { try { $elegible = plan_de_marca($pdo, $marca_id) !== null; } catch (Throwable $e) {} }
        if (!$elegible) { header('Location: ' . $volver . 'negocio&error=' . urlencode('Activa tu plan para que el corillo trabaje solo.')); exit; }
        try {
            $res = relevo_del_corillo($pdo, $marca_id);   // corre el EQUIPO completo, no solo el creador
            $nn  = (int)($res['creadas'] ?? 0);
            $msg = $nn > 0
                ? "El corillo te dejó {$nn} post" . ($nn === 1 ? '' : 's') . " en Propuestas → Revisar."
                : "El corillo revisó — ya tienes suficientes borradores por ahora.";
            header('Location: ' . $volver . 'negocio&corillo=' . urlencode($msg)); exit;
        } catch (Throwable $e) {
            header('Location: ' . $volver . 'negocio&error=' . urlencode('El corillo no pudo ahora: ' . substr($e->getMessage(), 0, 90))); exit;
        }
    }

    header('Location: ' . $volver . $h($tab)); exit;
}

// ── Datos para render ────────────────────────────────────────
$m = leer_marca($pdo, $marca_id);   // productos ya decodificado
$prods_txt = '';
foreach ((array)$m['productos'] as $p) {
    $prods_txt .= (is_array($p) ? ($p['nombre'] ?? '') : $p) . "\n";
}
$prods_txt = trim($prods_txt);
$municipios = $pdo->query("SELECT id, nombre FROM municipios ORDER BY nombre")->fetchAll();
$categorias = $pdo->query("SELECT id, nombre FROM categorias WHERE activa=1 ORDER BY nombre")->fetchAll();
$susc = suscripcion_de_marca($pdo, $marca_id);
$plan_etq = suscripcion_etiqueta($susc);
$conx = conexion_de_marca($pdo, $marca_id);

$ok    = $_GET['ok'] ?? '';
$okpass= $_GET['okpass'] ?? '';
$error = $_GET['error'] ?? '';

$active = 'config';
$page_title = 'Configuración';
require __DIR__ . '/_shell.php';

$tabs = [
  'negocio' => [ico('home'),'Mi negocio'],
  'redes'   => [ico('pin'),'Redes'],
  'plan'    => [ico('wallet'),'Mi plan'],
  'cuenta'  => [ico('users'),'Mi cuenta'],
];
$turl = fn($t) => "$BASE/configuracion.php?marca=$marca_id&tab=$t";
?>
<style>
  /* ══ CONFIGURACIÓN ══ ajustes, en el mismo lenguaje: Poppins, ink-soft, pill signature. */
  .content{max-width:720px;font-family:'Poppins',var(--font-body)}
  .asis-fab{display:none}
  .cfg-wrap{max-width:680px;margin:0 auto}
  .cfg-top{padding:0 2px;margin-bottom:14px}
  .cfg-neg{font-family:var(--font-display);font-size:15px;font-weight:600;color:var(--ink-soft);letter-spacing:-.01em}

  .cfg-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px}
  .cfg-tab{display:inline-flex;align-items:center;gap:7px;cursor:pointer;font-family:'Poppins',sans-serif;font-weight:600;font-size:13.5px;
    color:var(--muted);padding:9px 15px;border-radius:12px;border:1.5px solid var(--line);background:#fff;transition:.18s var(--ease)}
  .cfg-tab svg{width:16px;height:16px}
  .cfg-tab:hover{border-color:color-mix(in srgb,var(--teal) 40%,var(--line))}
  .cfg-tab.on{border-color:transparent;background:linear-gradient(135deg,var(--teal),var(--teal-700));color:#fff;box-shadow:0 8px 20px -10px rgba(0,164,159,.45)}
  .cfg-tab.on svg{color:#fff}

  /* ── MOLDE: ventana limpia + slide lateral ── una sección por pantalla, se desliza al lado (sin recargar, con swipe). */
  .cfg-view{overflow:hidden;transition:height .38s var(--ease)}
  .cfg-track{display:flex;align-items:flex-start;transition:transform .38s var(--ease);will-change:transform}
  .cfg-sec{flex:0 0 100%;min-width:0;padding:2px}     /* cada sección ocupa el ancho completo del viewport */
  @media (prefers-reduced-motion: reduce){.cfg-view,.cfg-track{transition:none}}

  .cfg-card{background:var(--card);border:1px solid var(--line);border-radius:20px;padding:22px;margin-bottom:16px;box-shadow:var(--shadow-sm)}
  .cfg-card h2{font-family:var(--font-display);font-size:18px;font-weight:600;color:var(--ink-soft);letter-spacing:-.01em;margin:0 0 4px;display:flex;align-items:center;gap:9px}
  .cfg-card h2 svg{width:19px;height:19px;color:var(--muted)}
  .cfg-card .sub{font-size:13.5px;color:var(--muted);margin:0 0 16px;line-height:1.5}
  .cfg-card label{display:block;font-weight:600;font-size:13.5px;color:var(--ink-soft);margin:15px 0 6px}
  .cfg-card label:first-of-type{margin-top:0}
  .cfg-card input[type=text],.cfg-card input[type=email],.cfg-card input[type=password],
  .cfg-card input[type=tel],.cfg-card textarea,.cfg-card select{width:100%;font-family:var(--font-body);font-size:14.5px;border:1.5px solid var(--line);
    border-radius:12px;padding:11px 13px;background:#fff;box-sizing:border-box;transition:border-color .15s}
  .cfg-card input:focus,.cfg-card textarea:focus,.cfg-card select:focus{outline:0;border-color:color-mix(in srgb,var(--magenta) 45%,var(--line))}
  .cfg-card textarea{resize:vertical;min-height:64px}
  .cfg-row{display:flex;gap:12px;flex-wrap:wrap}
  .cfg-row>div{flex:1;min-width:180px}
  .cfg-save{margin-top:20px;border:0;cursor:pointer;font-family:'Poppins',sans-serif;font-weight:600;font-size:15px;color:#fff;
    background:var(--btn-grad);padding:14px 26px;border-radius:15px;box-shadow:var(--btn-glow);transition:transform var(--dur) var(--ease),box-shadow var(--dur) var(--ease)}
  .cfg-save:active{transform:translateY(1px);box-shadow:var(--btn-glow-active)}
  .cfg-msg{padding:11px 15px;border-radius:12px;font-weight:600;font-size:14px;margin-bottom:16px;display:flex;align-items:center;gap:8px}
  .cfg-msg svg{width:17px;height:17px}
  .cfg-msg.ok{background:color-mix(in srgb,var(--teal) 10%,#fff);color:var(--ink-soft);border:1px solid color-mix(in srgb,var(--teal) 25%,#fff)}
  .cfg-msg.err{background:#fdeaea;color:#9b1c1c;border:1px solid #f5c2c0}
  .cfg-hint{font-size:12.5px;color:var(--muted);margin-top:6px;line-height:1.5}
  .cfg-link{display:inline-flex;align-items:center;gap:8px;text-decoration:none;font-family:'Poppins',sans-serif;font-weight:600;font-size:15px;
    color:#fff;padding:13px 22px;border-radius:14px}
  .cfg-pill{display:inline-flex;align-items:center;gap:6px;font-weight:600;font-size:13px;padding:6px 13px;border-radius:99px;background:var(--crema-2);color:var(--ink-soft);border:1px solid var(--line)}
</style>

<div class="cfg-wrap">
  <div class="cfg-top"><span class="cfg-neg">Configuración</span></div>

  <div class="cfg-tabs" role="tablist">
    <?php foreach ($tabs as $k=>$t): ?>
      <button type="button" class="cfg-tab <?= $tab===$k?'on':'' ?>" data-sec="<?= $k ?>" role="tab"><?= $t[0] ?> <span><?= $h($t[1]) ?></span></button>
    <?php endforeach; ?>
  </div>

  <?php if ($ok): ?><div class="cfg-msg ok"><?= ico('check-circle') ?>Guardado.</div><?php endif; ?>
  <?php if ($okpass): ?><div class="cfg-msg ok"><?= ico('check-circle') ?>Contraseña actualizada.</div><?php endif; ?>
  <?php if (($corillo = $_GET['corillo'] ?? '') !== ''): ?><div class="cfg-msg ok"><?= ico('sparkles') ?><?= $h($corillo) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="cfg-msg err"><?= ico('bolt') ?><?= $h($error) ?></div><?php endif; ?>

  <div class="cfg-view" id="cfgView"><div class="cfg-track" id="cfgTrack">
  <section class="cfg-sec" data-sec="negocio">
    <form method="post" action="<?= $turl('negocio') ?>">
      <?= csrf_field() ?><input type="hidden" name="accion" value="negocio">
      <div class="cfg-card">
        <h2>El cerebro de tu corillo <?= ico('lightbulb') ?></h2>
        <p class="sub">Mientras mejor describas tu negocio aquí, mejor sale TODO lo que la IA escribe y diseña para ti. Edítalo cuando quieras.</p>

        <label>Nombre del negocio</label>
        <input type="text" name="nombre_negocio" value="<?= $h($m['nombre_negocio']) ?>" maxlength="150">

        <label>¿Qué es tu negocio? (descripción)</label>
        <textarea name="descripcion" placeholder="Ej. Repostería casera en Caguas, bizcochos y quesitos por encargo."><?= $h($m['descripcion']) ?></textarea>

        <label>Tu voz / cómo hablas</label>
        <textarea name="voz" placeholder="Ej. cercano y alegre, uso nene/nena, hablo claro y sin pretensión."><?= $h($m['voz']) ?></textarea>
        <div class="cfg-hint">Así la IA imita tu forma de hablar en los posts.</div>

        <label>Productos / servicios (uno por línea)</label>
        <textarea name="productos" rows="4" placeholder="Bizcocho de guayaba&#10;Quesitos&#10;Tembleque"><?= $h($prods_txt) ?></textarea>

        <label>¿A quién le vendes? (público)</label>
        <textarea name="publico_objetivo" placeholder="Ej. familias del pueblo, oficinas para cumpleaños."><?= $h($m['publico_objetivo']) ?></textarea>

        <label>Ofertas o algo especial (opcional)</label>
        <textarea name="ofertas" placeholder="Ej. 10% los lunes, combos de docena."><?= $h($m['ofertas']) ?></textarea>

        <div class="cfg-row">
          <div>
            <label>Pueblo</label>
            <select name="municipio_id">
              <option value="">— Escoge —</option>
              <?php foreach ($municipios as $mu): ?>
                <option value="<?= $mu['id'] ?>" <?= (int)$m['municipio_id']===(int)$mu['id']?'selected':'' ?>><?= $h($mu['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>Categoría</label>
            <select name="categoria_id">
              <option value="">— Escoge —</option>
              <?php foreach ($categorias as $ca): ?>
                <option value="<?= $ca['id'] ?>" <?= (int)$m['categoria_id']===(int)$ca['id']?'selected':'' ?>><?= $h($ca['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="cfg-row">
          <div><label>Instagram</label><input type="text" name="instagram" value="<?= $h($m['instagram']) ?>" placeholder="@tunegocio"></div>
          <div><label>Facebook</label><input type="text" name="facebook" value="<?= $h($m['facebook']) ?>" placeholder="Tu página"></div>
          <div><label>WhatsApp</label><input type="tel" id="waInput" name="whatsapp" value="<?= $h($m['whatsapp']) ?>" placeholder="787-000-0000" inputmode="numeric" maxlength="12" autocomplete="tel"></div>
        </div>
        <script>
          (function(){
            var wa=document.getElementById('waInput'); if(!wa) return;
            function fmt(v){
              var d=(v||'').replace(/\D/g,'').slice(0,10), o=d.slice(0,3);
              if(d.length>3) o+='-'+d.slice(3,6);
              if(d.length>6) o+='-'+d.slice(6,10);
              return o;
            }
            wa.value=fmt(wa.value);
            wa.addEventListener('input',function(){ wa.value=fmt(wa.value); });
          })();
        </script>

        <div style="margin-top:18px">
          <label style="display:block;font-weight:800;font-size:13.5px;color:var(--tinta);margin-bottom:3px">¿Cómo quieres que la gente te contacte?</label>
          <p style="font-size:12px;color:var(--muted);margin:0 0 10px;line-height:1.45">Esto define el cierre de tus posts. Si <b>no</b> pones un WhatsApp arriba, la IA <b>nunca</b> lo menciona ni inventa un número — usa el mensaje directo (DM) de la red.</p>
          <style>
            .cfg-pref{display:grid;grid-template-columns:1fr 1fr;gap:8px}
            .cfg-pref label{display:flex;flex-direction:column;gap:2px;border:1.5px solid var(--line);background:#fff;border-radius:12px;padding:11px 13px;cursor:pointer;transition:.12s}
            .cfg-pref label:has(input:checked){border-color:var(--terracota);background:#fff5f7}
            .cfg-pref .t{display:flex;align-items:center;gap:8px;font-weight:800;font-size:13.5px;color:var(--tinta)}
            .cfg-pref .d{font-size:11.5px;color:var(--muted);line-height:1.35;padding-left:25px}
            @media(max-width:520px){.cfg-pref{grid-template-columns:1fr}}
          </style>
          <div class="cfg-pref">
            <?php
              $pref_actual = (string)($m['contacto_preferencia'] ?? '');
              $pref_opts = [
                'dm'       => ['Mensaje directo (DM)', 'Que me escriban por la red donde publico'],
                'whatsapp' => ['WhatsApp',             'Que me busquen directo por WhatsApp'],
                'ambas'    => ['Ambas',                'DM o WhatsApp, como prefiera el cliente'],
                'todas'    => ['Cualquier vía',        'La que le quede más fácil'],
              ];
              foreach ($pref_opts as $val => $o):
            ?>
              <label>
                <span class="t"><input type="radio" name="contacto_preferencia" value="<?= $val ?>" <?= $pref_actual===$val?'checked':'' ?>> <?= $h($o[0]) ?></span>
                <span class="d"><?= $h($o[1]) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <button class="cfg-save" type="submit">Guardar cambios</button>
      </div>
    </form>

    <form method="post" action="<?= $turl('negocio') ?>">
      <?= csrf_field() ?><input type="hidden" name="accion" value="autopilot">
      <div class="cfg-card" style="border-color:color-mix(in srgb,var(--terracota) 30%,#fff)">
        <h2><?= ico('refresh') ?> Piloto automático</h2>
        <p class="sub">Deja que el corillo te prepare posts <b>solo</b>, cada semana, sin que tengas que pedírselo. Te los deja como borradores listos para que apruebes — tú siempre tienes la última palabra.</p>
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin:0">
          <input type="checkbox" name="autopilot" value="1" <?= !empty($m['autopilot'])?'checked':'' ?> style="width:20px;height:20px">
          <span>Prender el piloto automático</span>
        </label>
        <label style="margin-top:16px">¿Cuántos posts me dejas listos por semana?</label>
        <select name="autopilot_n" style="max-width:220px">
          <?php foreach ([2,3,5,7] as $opt): ?>
            <option value="<?= $opt ?>" <?= (int)($m['autopilot_n']??3)===$opt?'selected':'' ?>><?= $opt ?> posts por semana</option>
          <?php endforeach; ?>
        </select>
        <div class="cfg-hint">Solo escribe los textos automático; el arte lo creas tú al aprobar (así controlas el costo). <?php if(!empty($m['autopilot_ultimo'])): ?><br>Última corrida: <?= $h(date('d/m/Y H:i', strtotime($m['autopilot_ultimo']))) ?>.<?php endif; ?></div>
        <button class="cfg-save" type="submit">Guardar</button>
      </div>
    </form>

    <form method="post" action="<?= $turl('negocio') ?>" onsubmit="var b=this.querySelector('button');b.textContent='Tu equipo está trabajando…';b.disabled=true;">
      <?= csrf_field() ?><input type="hidden" name="accion" value="corre_ahora">
      <div class="cfg-card">
        <h2><?= ico('sparkles') ?> ¿No quieres esperar?</h2>
        <p class="sub">Dispara el corillo ahora mismo: te prepara los posts que falten y los deja como borradores en Propuestas → Revisar. (Sin esperar al piloto semanal.)</p>
        <button class="cfg-save" type="submit">Corre el corillo ahora</button>
      </div>
    </form>

  </section>
  <section class="cfg-sec" data-sec="redes">
    <div class="cfg-card">
      <h2>Tus redes <?= ico('pin') ?></h2>
      <p class="sub">Conecta Instagram y Facebook para que el corillo publique solo los posts que tú aprobaste.</p>
      <?php if ($conx && $conx['estado']==='activa'): ?>
        <p><span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:var(--palma);vertical-align:middle;margin-right:5px"></span> Conectado:
          <?php if ($conx['ig_username']): ?><span class="cfg-pill"><?= ico('instagram') ?> @<?= $h($conx['ig_username']) ?></span><?php endif; ?>
          <?php if ($conx['fb_page_nombre']): ?><span class="cfg-pill"><?= ico('facebook') ?> <?= $h($conx['fb_page_nombre']) ?></span><?php endif; ?>
        </p>
        <a class="cfg-link" style="background:#1877F2;margin-top:14px" href="<?= $BASE ?>/conectar.php?marca=<?= $marca_id ?>">Gestionar redes →</a>
      <?php else: ?>
        <p>Todavía no has conectado tus redes.</p>
        <a class="cfg-link" style="background:#1877F2;margin-top:6px" href="<?= $BASE ?>/conectar.php?marca=<?= $marca_id ?>">Conectar Instagram y Facebook →</a>
      <?php endif; ?>
    </div>

  </section>
  <section class="cfg-sec" data-sec="plan">
    <div class="cfg-card">
      <h2>Tu plan <?= ico('wallet') ?></h2>
      <p class="sub">Tu suscripción y facturación.</p>
      <p style="margin:0 0 16px"><span class="cfg-pill"><?= ico('leaf') ?> <?= $h($plan_etq) ?></span></p>
      <?php if ($susc && !empty($susc['stripe_customer_id'])): ?>
        <a class="cfg-link" style="background:var(--tinta)" href="<?= $BASE ?>/portal.php?marca=<?= $marca_id ?>">Gestionar o cancelar →</a>
        <div class="cfg-hint" style="margin-top:10px">Te lleva al portal seguro de Stripe (cambiar tarjeta, ver facturas, cancelar).</div>
      <?php else: ?>
        <a class="cfg-link" style="background:var(--palma,#16b86a)" href="<?= $BASE ?>/precios.php?marca=<?= $marca_id ?>">Ver planes →</a>
      <?php endif; ?>
    </div>

  </section>
  <section class="cfg-sec" data-sec="cuenta">
    <form method="post" action="<?= $turl('cuenta') ?>">
      <?= csrf_field() ?><input type="hidden" name="accion" value="cuenta_datos">
      <div class="cfg-card">
        <h2>Tus datos <?= ico('users') ?></h2>
        <p class="sub">Tu nombre y correo de acceso.</p>
        <label>Nombre</label>
        <input type="text" name="nombre" value="<?= $h($usuario['nombre']) ?>" maxlength="100">
        <label>Email</label>
        <input type="email" name="email" value="<?= $h($usuario['email']) ?>" maxlength="150">
        <button class="cfg-save" type="submit">Guardar datos</button>
      </div>
    </form>

    <form method="post" action="<?= $turl('cuenta') ?>">
      <?= csrf_field() ?><input type="hidden" name="accion" value="cuenta_pass">
      <div class="cfg-card">
        <h2>Cambiar contraseña <?= ico('lock') ?></h2>
        <label>Contraseña actual</label>
        <input type="password" name="actual" autocomplete="current-password">
        <div class="cfg-row">
          <div><label>Nueva contraseña</label><input type="password" name="nueva" autocomplete="new-password"></div>
          <div><label>Confirmar</label><input type="password" name="confirmar" autocomplete="new-password"></div>
        </div>
        <div class="cfg-hint">Mínimo 8 caracteres.</div>
        <button class="cfg-save" type="submit">Cambiar contraseña</button>
      </div>
    </form>
  </section>
  </div></div><!-- /cfg-track /cfg-view -->
</div>

<script>
(function(){
  var order=['negocio','redes','plan','cuenta'];
  var tabs=[].slice.call(document.querySelectorAll('.cfg-tab'));
  var view=document.getElementById('cfgView'), track=document.getElementById('cfgTrack');
  var secs=[].slice.call(document.querySelectorAll('.cfg-sec'));
  if(!view||!track||!secs.length) return;
  var cur=Math.max(0, order.indexOf(<?= json_encode($tab) ?>));

  function fitHeight(){ view.style.height = secs[cur].offsetHeight + 'px'; }
  function go(i, push){
    i=Math.max(0,Math.min(order.length-1,i)); cur=i;
    track.style.transform='translateX(-'+(i*100)+'%)';
    tabs.forEach(function(t){ t.classList.toggle('on', t.getAttribute('data-sec')===order[i]); });
    fitHeight();
    var url=location.pathname+'?marca=<?= $marca_id ?>&tab='+order[i];
    if(push!==false) history.replaceState(null,'',url);
    window.scrollTo({top:0,behavior:'smooth'});
  }
  tabs.forEach(function(t){ t.addEventListener('click', function(){ go(order.indexOf(t.getAttribute('data-sec'))); }); });

  // Posición inicial sin animación
  track.style.transition='none'; go(cur,false); requestAnimationFrame(function(){ track.style.transition=''; });
  // Recalcular alto cuando cambie el contenido/tamaño
  window.addEventListener('resize', fitHeight);
  window.addEventListener('load', fitHeight);
  if(window.ResizeObserver){ new ResizeObserver(fitHeight).observe(secs[0].parentNode); }

  // Swipe lateral (móvil)
  var x0=null, y0=null, lock=null;
  view.addEventListener('touchstart', function(e){ var t=e.touches[0]; x0=t.clientX; y0=t.clientY; lock=null; }, {passive:true});
  view.addEventListener('touchmove', function(e){
    if(x0===null) return; var t=e.touches[0], dx=t.clientX-x0, dy=t.clientY-y0;
    if(lock===null && (Math.abs(dx)>8||Math.abs(dy)>8)) lock = Math.abs(dx)>Math.abs(dy) ? 'x':'y';
  }, {passive:true});
  view.addEventListener('touchend', function(e){
    if(x0===null||lock!=='x'){ x0=null; return; }
    var dx=e.changedTouches[0].clientX-x0;
    if(dx<-45) go(cur+1); else if(dx>45) go(cur-1);
    x0=null;
  }, {passive:true});
})();
</script>

<?php require __DIR__ . '/_shell_foot.php'; ?>
