<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Mi Marca (galería de logos + escoger)
//  panel/marca.php
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/agentes.php';
require __DIR__ . '/../includes/suscripcion.php';
requiere_login();
$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }
$marca_id = (int)$marca['id'];
$pagado = marca_es_pagada($pdo, $marca_id);  // el LOGO solo se desbloquea pagando

$LIMITE_LOGO = 5;
$cuenta = $pdo->prepare("SELECT COUNT(*) FROM crecer_logos WHERE marca_id=?");
$cuenta->execute([$marca_id]); $usados = (int)$cuenta->fetchColumn();
$restantes = max(0, $LIMITE_LOGO - $usados);
$final = (int)$marca['logo_final'] === 1;

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    // CSRF: estas acciones mutan la marca / gastan IA. Sin token válido, no corren.
    if (!function_exists('csrf_ok') || !csrf_ok()) {
        if (in_array($accion, ['sugerir_estilo','tono_preview','bloquear'], true) || !empty($_POST['ajax'])) {
            header('Content-Type: application/json'); echo json_encode(['ok'=>false,'err'=>'Sesión expiró, recarga la página.']); exit;
        }
        header("Location: /crecer/panel/marca.php?marca={$marca_id}"); exit;
    }

    // 🔁 Polling del logo que se genera en background (gpt-image-2 / Responses).
    if ($accion === 'poll_logo') {
        require_once __DIR__ . '/../includes/img_responses.php';
        header('Content-Type: application/json');
        echo json_encode(['ok'=>true] + logo_resp_completar($pdo, $marca_id)); exit;
    }

    // El Cerebro: el dueño controla su conocimiento (corregir / descartar).
    if ($accion === 'memoria_descartar' && function_exists('memoria_descartar')) {
        memoria_descartar($pdo, $marca_id, (int)($_POST['mid'] ?? 0));
        header("Location: /crecer/panel/marca.php?marca={$marca_id}#cerebro"); exit;
    }
    if ($accion === 'memoria_editar' && function_exists('memoria_editar')) {
        memoria_editar($pdo, $marca_id, (int)($_POST['mid'] ?? 0), (string)($_POST['detalle'] ?? ''));
        header("Location: /crecer/panel/marca.php?marca={$marca_id}#cerebro"); exit;
    }

    if ($accion === 'tono') {
        $vals = [];
        foreach (['boricua','formal','venta','ingenio'] as $k) $vals[$k] = max(0, min(100, (int)($_POST['t_'.$k] ?? 50)));
        $preset = substr(trim((string)($_POST['preset'] ?? '')), 0, 20);
        $pdo->prepare("UPDATE crecer_marca SET tono_boricua=?, tono_formal=?, tono_venta=?, tono_ingenio=?, tono_preset=? WHERE id=?")
            ->execute([$vals['boricua'], $vals['formal'], $vals['venta'], $vals['ingenio'], $preset ?: null, $marca_id]);
        header("Location: /crecer/panel/marca.php?marca={$marca_id}&tono=1"); exit;
    }

    if ($accion === 'tono_preview') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $m = leer_marca($pdo, $marca_id);
            foreach (['boricua','formal','venta','ingenio'] as $k)
                $m['tono_'.$k] = max(0, min(100, (int)($_POST['t_'.$k] ?? ($m['tono_'.$k] ?? 50))));
            $ctx = marca_contexto($m);
            $sistema = "Eres el CREADOR de contenido de Crecer. Escribes captions cortos para redes sociales de microempresas boricuas.\n" . reglas_idioma($m) . "\nMáximo 45 palabras por caption.\n" . contacto_instruccion($m) . tono_instruccion($m);
            $prompt = "Perfil del negocio:\n{$ctx}\n\nEscribe TRES (3) captions DISTINTOS para un post que promociona el negocio o su producto principal, los tres en EXACTAMENTE el mismo tono indicado arriba. Sepáralos con una línea que diga solo: ===\nNo los numeres, no pongas títulos, no expliques nada.";
            $r = ia_ejecutar($pdo, 'creador', 'Vista previa de tono', $prompt, [
                'marca_id' => $marca_id, 'sistema' => $sistema,
                'temperatura' => 1.0, 'max_tokens' => 600, 'thinking_budget' => 0,
                'mock_texto' => "¡Wepa! Llegó bizcocho fresco hoy 🔥 Escríbenos por WhatsApp 📲\n===\nDate el gusto: tres leches cremosito como te gusta 😋\n===\nOrdena hoy por WhatsApp y te lo apartamos, mi gente 💛",
            ]);
            $parts = preg_split('/\n?\s*={2,}\s*\n?/', trim((string)$r['texto']));
            $parts = array_values(array_filter(array_map('trim', $parts), fn($x) => $x !== ''));
            echo json_encode(['ok' => true, 'variaciones' => array_slice($parts, 0, 3)], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => substr($e->getMessage(), 0, 160)], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($accion === 'sugerir_estilo') {   // la IA propone la línea de diseño de ESTE cliente
        header('Content-Type: application/json; charset=utf-8');
        try {
            $ajuste = trim((string)($_POST['ajuste'] ?? ''));
            echo json_encode(['ok' => true, 'estilo' => sugerir_estilo_visual($pdo, $marca_id, $ajuste)], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => substr($e->getMessage(), 0, 160)], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($accion === 'guardar_estilo') {   // guarda la línea de diseño de la marca
        $ev = trim((string)($_POST['estilo_visual'] ?? ''));
        $pdo->prepare("UPDATE crecer_marca SET estilo_visual=? WHERE id=?")->execute([$ev !== '' ? $ev : null, $marca_id]);
        header("Location: /crecer/panel/marca.php?marca={$marca_id}&estilo=1#identidad"); exit;
    }

    if ($accion === 'logo') {
        if (!$pagado)           $err = 'El logo se desbloquea cuando te suscribes a un plan.';
        elseif ($final)         $err = 'Tu logo ya está finalizado.';
        elseif ($usados >= $LIMITE_LOGO) $err = "Llegaste a tus {$LIMITE_LOGO} pruebas.";
        else {
            @set_time_limit(0);
            $desc = trim($_POST['descripcion'] ?? '');
            if ($desc !== '' && $desc !== (string)$marca['descripcion']) {
                $pdo->prepare("UPDATE crecer_marca SET descripcion=? WHERE id=?")->execute([$desc, $marca_id]);
            }
            $opts = [
                'descripcion'   => $desc,
                'estilo'        => $_POST['estilo'] ?? '',
                'tipografia'    => $_POST['tipografia'] ?? '',
                'tono'          => (int)($_POST['tono'] ?? 50),
                'epoca'         => (int)($_POST['epoca'] ?? 50),
                'detalle'       => (int)($_POST['detalle'] ?? 50),
                'instrucciones' => $_POST['instrucciones'] ?? '',
            ];
            $_glog = null;
            try { $_glog = generar_logo($pdo, $marca_id, $opts); }
            catch (Throwable $e) { $err = 'No se pudo generar: ' . substr($e->getMessage(), 0, 120); }
            // Motor async (gpt-image-2): se está generando en background → estado de espera.
            if (!$err) { $qs = (!empty($_glog['job'])) ? 'logo_gen=1' : 'ok=1'; header("Location: /crecer/panel/marca.php?marca={$marca_id}&{$qs}"); exit; }
        }
    } elseif ($accion === 'subir_logo') {
        // Subir un logo propio (principal o secundario). No es premium: es tu asset.
        if (!empty($_FILES['logo_file']['tmp_name']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
            $info = @getimagesize($_FILES['logo_file']['tmp_name']);
            $ext  = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$info['mime'] ?? ''] ?? null;
            if (!$ext) { $err = 'Formato no válido (usa PNG, JPG o WebP).'; }
            else {
                $fname = "marca_{$marca_id}/logo_sub_" . uniqid() . ".{$ext}";
                $abs   = rtrim(UPLOADS_PATH, '/\\') . '/' . $fname;
                @mkdir(dirname($abs), 0775, true);
                if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $abs)) {
                    $url = rtrim(UPLOADS_URL, '/') . '/' . $fname;
                    $pdo->prepare("INSERT INTO crecer_logos (marca_id, archivo) VALUES (?,?)")->execute([$marca_id, $url]);
                    $nid = (int)$pdo->lastInsertId();
                    // Principal si el dueño lo marcó, o si aún no hay uno elegido.
                    $hayMain = (int)$pdo->query("SELECT COUNT(*) FROM crecer_logos WHERE marca_id={$marca_id} AND elegido=1")->fetchColumn();
                    if (!empty($_POST['principal']) || !$hayMain) {
                        $pdo->prepare("UPDATE crecer_logos SET elegido=0 WHERE marca_id=?")->execute([$marca_id]);
                        $pdo->prepare("UPDATE crecer_logos SET elegido=1 WHERE id=?")->execute([$nid]);
                        $pdo->prepare("UPDATE crecer_marca SET logo_path=? WHERE id=?")->execute([$url, $marca_id]);
                    }
                } else { $err = 'No se pudo guardar el logo.'; }
            }
        } else { $err = 'No se pudo subir el archivo (máx del servidor).'; }
        if (!$err) { header("Location: /crecer/panel/marca.php?marca={$marca_id}&ok=logo#identidad"); exit; }
    } elseif ($accion === 'elegir' && !$final) {
        $lid = (int)($_POST['logo_id'] ?? 0);
        $own = $pdo->prepare("SELECT archivo FROM crecer_logos WHERE id=? AND marca_id=?");
        $own->execute([$lid, $marca_id]);
        if ($arch = $own->fetchColumn()) {
            $pdo->prepare("UPDATE crecer_logos SET elegido=0 WHERE marca_id=?")->execute([$marca_id]);
            $pdo->prepare("UPDATE crecer_logos SET elegido=1 WHERE id=?")->execute([$lid]);
            $pdo->prepare("UPDATE crecer_marca SET logo_path=? WHERE id=?")->execute([$arch, $marca_id]);
        }
        header("Location: /crecer/panel/marca.php?marca={$marca_id}"); exit;
    } elseif ($accion === 'bloquear' && !$final) {
        $pdo->prepare("UPDATE crecer_marca SET logo_final=1 WHERE id=?")->execute([$marca_id]);
        http_response_code(204); exit; // para el fetch del download
    } elseif ($accion === 'eliminar_logo') {
        // Borrar un logo que NO se va a usar. Nunca borra el ELEGIDO (para no
        // romper el logo activo/final). Best-effort: quita archivo + fila.
        $lid = (int)($_POST['logo_id'] ?? 0);
        $row = $pdo->prepare("SELECT archivo, elegido FROM crecer_logos WHERE id=? AND marca_id=?");
        $row->execute([$lid, $marca_id]); $row = $row->fetch(PDO::FETCH_ASSOC);
        if ($row && (int)$row['elegido'] !== 1) {
            $arch = (string)$row['archivo'];
            if (strpos($arch, '/uploads/') !== false && defined('UPLOADS_PATH')) {
                $rel = substr($arch, strpos($arch, '/uploads/') + 9);
                $abs = rtrim(UPLOADS_PATH, '/\\') . '/' . ltrim($rel, '/');
                if (is_file($abs)) @unlink($abs);
            }
            $pdo->prepare("DELETE FROM crecer_logos WHERE id=? AND marca_id=?")->execute([$lid, $marca_id]);
        }
        header("Location: /crecer/panel/marca.php?marca={$marca_id}#identidad"); exit;
    }
}

$logos = $pdo->prepare("SELECT * FROM crecer_logos WHERE marca_id=? AND archivo IS NOT NULL ORDER BY id");
$logos->execute([$marca_id]); $logos = $logos->fetchAll();
$elegido = null; foreach ($logos as $l) if ($l['elegido']) $elegido = $l;
require_once __DIR__ . '/../includes/img_responses.php';
$logo_pendiente = function_exists('logo_resp_pendiente') && logo_resp_pendiente($pdo, $marca_id);   // ¿hay uno generándose en background?
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// Valores actuales del tono (para precargar los sliders)
$T = [
  'boricua' => (int)($marca['tono_boricua'] ?? 80),
  'formal'  => (int)($marca['tono_formal']  ?? 30),
  'venta'   => (int)($marca['tono_venta']   ?? 55),
  'ingenio' => (int)($marca['tono_ingenio'] ?? 60),
];
$Tpreset = (string)($marca['tono_preset'] ?? '');

$active = 'marca';
$page_title = 'Mi Marca';
$guia = null; // El ADN se muestra, no se explica.
require __DIR__ . '/_shell.php';
?>
<style>
  /* ══ MI MARCA ══ el ADN visual del negocio. Mismo director: Poppins, ink-soft. */
  .content{max-width:820px;font-family:'Poppins',var(--font-body)}
  .asis-fab{display:none}

  /* Hero ADN: el logo y el nombre lideran (el negocio es el protagonista) */
  .mk-hero{display:flex;align-items:center;gap:16px;padding:6px 2px 2px}
  .mk-av{width:64px;height:64px;border-radius:18px;overflow:hidden;flex:none;background:var(--crema-2);display:grid;place-items:center;box-shadow:0 8px 22px -10px rgba(40,22,28,.3)}
  .mk-av img{width:100%;height:100%;object-fit:contain}
  .mk-av .ini{font-family:var(--font-display);font-weight:600;font-size:28px;color:var(--magenta)}
  .mk-name{font-family:var(--font-display);font-weight:600;font-size:clamp(22px,4.4vw,30px);line-height:1.1;letter-spacing:-.02em;color:var(--ink-soft)}
  .mk-tagline{font-size:13.5px;color:var(--muted);margin-top:3px}

  .subline{color:var(--muted);font-size:14.5px;margin-top:4px;line-height:1.5}
  .subline b{color:var(--ink-soft)}
  .sec-h{font-family:var(--font-display);font-weight:600;font-size:19px;color:var(--ink-soft);letter-spacing:-.01em;margin:30px 0 4px;display:flex;align-items:center;gap:9px}
  .sec-h svg{width:20px;height:20px;color:var(--muted)}
  .ok-banner{background:color-mix(in srgb,var(--teal) 10%,#fff);color:var(--ink-soft);font-weight:600;font-size:14px;padding:11px 15px;border-radius:12px;margin-top:14px;border:1px solid color-mix(in srgb,var(--teal) 25%,#fff)}
  .err-banner{background:#fdeaea;color:#b42318;font-weight:600;font-size:14px;padding:11px 15px;border-radius:12px;margin-top:14px;border:1px solid #f5c2c0}

  .genbox{background:var(--card);border:1px solid var(--line);border-radius:20px;padding:20px;box-shadow:var(--shadow-sm);margin-top:16px;max-width:640px}
  .genbox textarea{width:100%;box-sizing:border-box;font-family:var(--font-body);font-size:14px;border:1.5px solid var(--line);border-radius:12px;padding:11px 13px;resize:vertical;margin-bottom:10px}
  .genbox textarea:focus{outline:0;border-color:color-mix(in srgb,var(--magenta) 45%,var(--line))}
  .genbtn{border:0;cursor:pointer;font-family:'Poppins',sans-serif;font-weight:600;font-size:15px;color:#fff;background:var(--btn-grad);padding:14px 26px;border-radius:15px;box-shadow:var(--btn-glow);transition:transform var(--dur) var(--ease),box-shadow var(--dur) var(--ease)}
  .genbtn:active{transform:translateY(1px);box-shadow:var(--btn-glow-active)}
  .genline{font-size:12.5px;color:var(--muted);margin-top:10px}
  .policy{font-size:11.5px;color:var(--muted);margin-top:4px}
  .fl{display:block;font-weight:600;font-size:13px;margin:14px 0 7px;color:var(--ink-soft)}
  .chips{display:flex;flex-wrap:wrap;gap:7px}
  .chip-opt{cursor:pointer}
  .chip-opt input{position:absolute;opacity:0;pointer-events:none}
  .chip-opt span{display:inline-block;padding:8px 14px;border-radius:99px;border:1.5px solid var(--line);background:#fff;font-weight:600;font-size:13px;transition:all .15s}
  .chip-opt input:checked + span{border-color:transparent;color:#fff;background:var(--btn-grad)}
  .slider{display:flex;align-items:center;gap:10px;margin:9px 0}
  .slider span{font-size:12px;color:var(--muted);font-weight:600;width:64px}
  .slider span:last-child{text-align:right}
  .slider input[type=range]{flex:1;accent-color:var(--magenta)}
  .fbnew{border:1.5px solid var(--line);background:#fff;color:var(--ink-soft);font-family:'Poppins',sans-serif;font-weight:600;font-size:14px;border-radius:12px;padding:12px 18px;cursor:pointer}
  .fbnew:hover{border-color:var(--teal)}

  .gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:14px;margin-top:20px}
  .tile{background:var(--card);border:1.5px solid var(--line);border-radius:18px;padding:12px;text-align:center;transition:border-color .15s,box-shadow var(--dur) var(--ease),opacity .15s}
  .tile.sel{border-color:var(--magenta);box-shadow:0 14px 32px -14px rgba(239,67,117,.28)}
  .tile.locked{opacity:.45}
  .tile img{width:100%;border-radius:12px;display:block}
  .tile .badge{display:inline-block;margin-top:9px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:var(--teal-dark);background:color-mix(in srgb,var(--teal) 12%,#fff);padding:4px 11px;border-radius:99px}
  .tile .pick{margin-top:9px;width:100%;border:1.5px solid var(--line);background:#fff;color:var(--ink-soft);font-family:'Poppins',sans-serif;font-weight:600;font-size:13px;cursor:pointer;border-radius:99px;padding:9px}
  .tile .pick:hover{border-color:var(--magenta);color:var(--magenta)}

  .chosen{background:var(--card);border:1px solid var(--line);border-radius:20px;padding:20px;margin-top:22px;max-width:640px;box-shadow:var(--shadow-sm)}
  .chosen h3{font-family:var(--font-display);font-weight:600;font-size:16px;margin-bottom:4px;color:var(--ink-soft);display:flex;align-items:center;gap:8px}
  .dl{margin-top:12px}
  .dl button{font-family:'Poppins',sans-serif;font-weight:600;font-size:13px;cursor:pointer;border:1.5px solid var(--line);background:#fff;color:var(--ink-soft);border-radius:99px;padding:9px 16px;margin:3px}
  .dl button:hover{border-color:var(--magenta);color:var(--magenta)}
  .warn{font-size:12px;color:var(--muted);margin-top:10px}
  .empty-g{color:var(--muted);font-size:15px;margin-top:20px;line-height:1.5;max-width:44ch}

  .cer-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;max-width:920px;margin-top:16px}
  .cer-card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:16px;box-shadow:var(--shadow-sm)}
  .cer-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
  .cer-tag{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--teal-700);background:color-mix(in srgb,var(--teal) 11%,#fff);border-radius:99px;padding:3px 10px}
  .cer-conf{font-size:11px;color:var(--muted);font-weight:600}
  .cer-det{margin:0;font-size:14px;color:var(--tinta);line-height:1.45}
  .cer-why{margin:7px 0 0;font-size:12px;color:var(--muted);font-style:italic}
  .cer-acts{display:flex;gap:16px;margin-top:11px}
  .cer-link{background:none;border:0;cursor:pointer;font-family:'Poppins',sans-serif;font-weight:600;font-size:12.5px;color:var(--teal-700);padding:0}
  .cer-link.danger{color:var(--muted)}
  .cer-editform textarea{width:100%;box-sizing:border-box;font-family:var(--font-body);font-size:13.5px;border:1.5px solid var(--line);border-radius:10px;padding:9px;margin:8px 0}
  .cer-editform .btn-save{background:var(--btn-grad);color:#fff;border:0;border-radius:11px;padding:8px 16px;font-weight:600;cursor:pointer;font-size:12.5px}

  .mk-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:20px 0 4px;max-width:680px}
  .mk-tab{border:1.5px solid var(--line);background:#fff;border-radius:12px;padding:10px 16px;cursor:pointer;font-family:'Poppins',sans-serif;font-weight:600;font-size:13.5px;color:var(--muted);transition:.15s;display:inline-flex;align-items:center;gap:7px}
  .mk-tab svg{width:16px;height:16px}
  .mk-tab.on{border-color:transparent;background:linear-gradient(135deg,var(--teal),var(--teal-700));color:#fff;box-shadow:0 8px 20px -10px rgba(0,164,159,.45)}
  .mk-tab.on svg{color:#fff}
  /* MOLDE ventana limpia + slide lateral (igual que Configuración) */
  .mk-view{overflow:hidden;transition:height .38s var(--ease)}
  .mk-track{display:flex;align-items:flex-start;transition:transform .38s var(--ease);will-change:transform}
  .mk-pane{flex:0 0 100%;min-width:0;padding:2px}
  #mk-voz{order:0}#mk-identidad{order:1}#mk-aprendido{order:2}   /* orden visual = orden de los tabs (aunque el DOM difiera) */
  @media (prefers-reduced-motion: reduce){.mk-view,.mk-track{transition:none}}
  .mk-pane .sec-h:first-child{margin-top:14px}

  /* Wizard shell (compartido con Voz; se define aquí también por si acaso) */
  .vz-view{overflow:hidden;transition:height .35s var(--ease)}
  .vz-track{display:flex;align-items:flex-start;transition:transform .38s var(--ease);will-change:transform}
  .vz-card{flex:0 0 100%;min-width:0;box-sizing:border-box}
  /* Hub de logo: dos elecciones grandes */
  .idz-hub{display:grid;grid-template-columns:1fr 1fr;gap:11px;margin-top:6px}
  .idz-choice{border:1.5px solid var(--line);background:#fff;border-radius:16px;padding:18px 14px;cursor:pointer;text-decoration:none;text-align:center;
    display:flex;flex-direction:column;align-items:center;gap:8px;font-family:inherit;transition:border-color .15s,transform .12s,box-shadow .15s}
  .idz-choice:hover{transform:translateY(-2px);box-shadow:var(--shadow-sm);border-color:color-mix(in srgb,var(--magenta) 40%,var(--line))}
  .idz-choice .chip{width:46px;height:46px;border-radius:14px;display:grid;place-items:center;background:color-mix(in srgb,var(--magenta) 10%,#fff);color:var(--magenta)}
  .idz-choice .chip svg{width:23px;height:23px}
  .idz-choice b{font-size:14.5px;color:var(--tinta)}
  .idz-choice small{color:var(--muted);font-size:11.5px;line-height:1.3}
  .idz-choice.locked .chip{background:color-mix(in srgb,var(--terracota) 12%,#fff);color:var(--terracota)}
  @media(max-width:400px){.idz-hub{grid-template-columns:1fr}}

  /* ── MÓVIL: pantalla compacta, todo lo que sea lista = carrusel horizontal ── */
  @media (max-width:760px){
    .content{padding:16px 14px 90px}
    /* Banner de entrevista compacto (una línea, sin párrafo largo) */
    .mk-intro{padding:12px 14px;margin-bottom:12px}
    .mk-intro-sub{display:none}
    /* Hero más chico */
    .mk-hero{gap:13px;padding:2px}
    .mk-av{width:54px;height:54px;border-radius:15px}
    .mk-tagline{display:none}
    /* Tabs = control segmentado full-width */
    .mk-tabs{gap:6px;margin:14px 0 2px}
    .mk-tab{flex:1;justify-content:center;padding:10px 4px;font-size:12.5px;border-radius:13px}
    .mk-tab svg{width:15px;height:15px}
    .sec-h{font-size:16.5px;margin:22px 0 4px}
    .subline{font-size:13.5px}
    .genbox{padding:16px;border-radius:16px}

    /* GALERÍA de logos → carrusel horizontal con peek */
    .gallery{display:flex;grid-template-columns:none;gap:11px;overflow-x:auto;scroll-snap-type:x proximity;
      -webkit-overflow-scrolling:touch;padding:2px 2px 10px;margin-top:16px}
    .gallery::-webkit-scrollbar{height:0}
    .tile{flex:0 0 58%;max-width:220px;scroll-snap-align:start}

    /* LO APRENDIDO → carrusel horizontal con peek */
    .cer-grid{display:flex;grid-template-columns:none;gap:11px;overflow-x:auto;scroll-snap-type:x proximity;
      -webkit-overflow-scrolling:touch;padding:2px 2px 10px;margin-top:14px}
    .cer-grid::-webkit-scrollbar{height:0}
    .cer-card{flex:0 0 84%;scroll-snap-align:start}

    /* CHIPS (estilo / tipografía) → fila deslizable, no envuelven en varias líneas */
    .chips{flex-wrap:nowrap;overflow-x:auto;-webkit-overflow-scrolling:touch;scroll-snap-type:x proximity;padding-bottom:6px}
    .chips::-webkit-scrollbar{height:0}
    .chip-opt{flex:0 0 auto;scroll-snap-align:start}
  }
</style>

<?php $logo_url = (string)($marca['logo_path'] ?? '') ?: (string)($elegido['archivo'] ?? ''); ?>
<a class="mk-intro" href="/crecer/panel/entrevista.php?marca=<?= $marca_id ?>" style="display:flex;align-items:center;gap:12px;text-decoration:none;background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff;border-radius:16px;padding:14px 18px;margin-bottom:16px;box-shadow:0 12px 30px -12px rgba(239,67,117,.5)">
  <span style="font-size:22px"><?= ico('chat') ?></span>
  <span style="flex:1"><b style="font-size:15px">Cuéntame de tu negocio</b><br><span class="mk-intro-sub" style="font-size:12.5px;opacity:.9">Una conversación — mientras mejor te conozca el corillo, mejor sale todo (copy, arte, estrategia).</span></span>
  <span style="font-size:18px;opacity:.9">→</span>
</a>
<div class="mk-hero">
  <div class="mk-av"><?php if ($logo_url): ?><img src="<?= $h($logo_url) ?>" alt=""><?php else: ?><span class="ini"><?= $h(mb_strtoupper(mb_substr($marca['nombre_negocio'],0,1))) ?></span><?php endif; ?></div>
  <div>
    <div class="mk-name"><?= $h($marca['nombre_negocio']) ?></div>
    <div class="mk-tagline">El ADN de tu negocio — voz, identidad, memoria.</div>
  </div>
</div>
<?php if (!empty($_GET['tono'])): ?><div class="ok-banner" style="max-width:680px">✓ Tu tono quedó guardado. La Creativa escribirá así de ahora en adelante.</div><?php endif; ?>

<div class="mk-tabs" role="tablist">
  <button type="button" class="mk-tab on" data-pane="voz" role="tab"><?= ico('mic') ?> Voz</button>
  <button type="button" class="mk-tab" data-pane="identidad" role="tab"><?= ico('palette') ?> Identidad</button>
  <button type="button" class="mk-tab" data-pane="aprendido" role="tab"><?= ico('lightbulb') ?> Lo aprendido</button>
</div>

<div class="mk-view" id="mkView"><div class="mk-track" id="mkTrack">
<section class="mk-pane on" id="mk-voz">
<h2 class="sec-h"><?= ico('mic') ?> Tu tono de voz</h2>
<p class="subline">Así suena <b>todo tu contenido</b>. Tres pasos y listo.</p>
<?php include __DIR__ . '/_tono_panel.php'; ?>
</section>

<?php
if (function_exists('memoria_consolidar')) memoria_consolidar($pdo, $marca_id);
$memorias = function_exists('memoria_listar') ? memoria_listar($pdo, $marca_id) : [];
$tipo_lbl = ['patron'=>'Patrón detectado','preferencia'=>'Preferencia','decision'=>'Decisión','tono'=>'Voz de marca','marca'=>'Identidad','conversacion'=>'De una conversación','hito'=>'Hito'];
?>
<section class="mk-pane" id="mk-aprendido">
<h2 class="sec-h" id="cerebro"><?= ico('lightbulb') ?> Lo que he aprendido de tu negocio</h2>
<p class="subline">El corillo aprende de lo que apruebas, editas y rechazas, y lo usa para escribir mejor. Esto es tuyo: <b>corrígelo o descártalo</b> cuando quieras.</p>
<?php if (!$memorias): ?>
  <div class="empty-g" style="max-width:680px">Todavía no he aprendido nada específico. Aprueba, edita o rechaza unos posts y aquí verás lo que voy captando de tu negocio.</div>
<?php else: ?>
  <div class="cer-grid">
    <?php foreach ($memorias as $mm): ?>
      <div class="cer-card">
        <div class="cer-top"><span class="cer-tag"><?= $h($tipo_lbl[$mm['tipo']] ?? 'Aprendizaje') ?></span><span class="cer-conf">confianza <?= (int)$mm['confianza'] ?>%</span></div>
        <p class="cer-det"><?= $h($mm['detalle']) ?></p>
        <?php if (!empty($mm['porque'])): ?><p class="cer-why"><?= $h($mm['porque']) ?></p><?php endif; ?>
        <div class="cer-acts">
          <button type="button" class="cer-link" onclick="cerEdit(<?= (int)$mm['id'] ?>)">Corregir</button>
          <form method="post" onsubmit="return confirm('¿Descartar este aprendizaje? El corillo dejará de usarlo.')" style="display:inline">
            <input type="hidden" name="accion" value="memoria_descartar"><input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES) ?>"><input type="hidden" name="mid" value="<?= (int)$mm['id'] ?>">
            <button type="submit" class="cer-link danger">Descartar</button>
          </form>
        </div>
        <form method="post" class="cer-editform" id="cerf-<?= (int)$mm['id'] ?>" style="display:none">
          <input type="hidden" name="accion" value="memoria_editar"><input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES) ?>"><input type="hidden" name="mid" value="<?= (int)$mm['id'] ?>">
          <textarea name="detalle" rows="2"><?= $h($mm['detalle']) ?></textarea>
          <div><button type="submit" class="btn-save">Guardar</button> &nbsp;<button type="button" class="cer-link" onclick="cerCancel(<?= (int)$mm['id'] ?>)">Cancelar</button></div>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<script>
function cerEdit(id){var f=document.getElementById('cerf-'+id);if(f)f.style.display='block';}
function cerCancel(id){var f=document.getElementById('cerf-'+id);if(f)f.style.display='none';}
</script>
</section>

<section class="mk-pane" id="mk-identidad">
<?php $ev_actual = (string)$pdo->query("SELECT estilo_visual FROM crecer_marca WHERE id={$marca_id}")->fetchColumn(); ?>
<div class="vz-dots" id="idzDots"></div>
<div class="vz-view" id="idzView"><div class="vz-track" id="idzTrack">

<!-- ── Identidad · Card 1: Línea de diseño ── -->
<section class="vz-card" data-card="linea">
<h2 class="sec-h" style="margin-top:2px"><?= ico('palette') ?>Tu línea de diseño</h2>
<p class="subline">El estilo visual de <b>TU</b> negocio (colores, vibra, tipo de foto). La IA lo aplica a <b>todas</b> tus imágenes para que el feed se vea de la misma familia.</p>
<?php if (!empty($_GET['estilo'])): ?><div class="ok-banner">✓ Línea de diseño guardada.</div><?php endif; ?>
<div class="genbox" style="margin-bottom:0">
  <form method="post" onsubmit="var b=this.querySelector('.genbtn');b.disabled=true;b.textContent='Guardando…';">
    <input type="hidden" name="accion" value="guardar_estilo"><input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES) ?>">
    <textarea name="estilo_visual" id="estilo-ta" rows="4" placeholder="Ej: paleta cálida (terracota y crema), luz natural de tarde, fotos reales sobre madera, composición limpia con aire para texto, vibra artesanal y acogedora…"><?= $h($ev_actual) ?></textarea>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
      <button class="genbtn" type="submit" style="margin:0">Guardar línea de diseño</button>
      <button type="button" id="estilo-sug" class="fbnew" style="font-weight:800"><?= ico('sparkles') ?> Sugerir con IA</button>
    </div>
    <div class="genline">La IA la propone leyendo tu negocio; ajústala a tu gusto. Déjala en blanco y la IA decide cada vez.</div>
  </form>
</div>
<script>
(function(){
  var b=document.getElementById('estilo-sug'); if(!b) return;
  b.addEventListener('click', function(){
    var ta=document.getElementById('estilo-ta'), old=b.textContent;
    b.disabled=true; b.textContent='Pensando tu estilo…';
    var fd=new FormData(); fd.append('accion','sugerir_estilo'); fd.append('ajuste', ta.value.trim()); fd.append('csrf', <?= json_encode(csrf_token()) ?>);
    fetch(location.pathname+location.search,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
      b.disabled=false; b.textContent=old;
      if(d && d.ok && d.estilo){ ta.value=d.estilo; ta.focus(); }
      else { alert('No pude sugerir ahora. Intenta otra vez.'); }
    }).catch(function(){ b.disabled=false; b.textContent=old; });
  });
})();
</script>
<div class="vz-nav"><span></span><button type="button" class="vz-next" data-goto="hub">Siguiente: tu logo <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg></button></div>
</section>

<!-- ── Identidad · Card 2: Tu logo (hub) ── -->
<section class="vz-card" data-card="hub">
<h2 class="sec-h" style="margin-top:2px"><?= ico('palette') ?>Tu logo</h2>
<p class="subline"><?= $pagado ? 'Súbelo si ya lo tienes, o deja que el Diseñador te lo cree.' : 'Súbelo si ya lo tienes. Crearlo con IA se desbloquea con un plan.' ?></p>
<?php if (!empty($_GET['ok'])): ?><div class="ok-banner">✓ ¡Logo guardado! Míralo en tu catálogo.</div><?php endif; ?>
<?php if ($err): ?><div class="err-banner"><?= ico('bolt') ?> <?= $h($err) ?></div><?php endif; ?>
<?php if ($logo_pendiente): ?>
<div class="ok-banner" id="logoWait" style="display:flex;align-items:center;gap:12px">
  <span class="lspin"></span>
  <span><?= ico('palette') ?> El Diseñador está creando tu logo con IA de alta precisión… <b>un par de minutos.</b> Quédate aquí; aparece solo.</span>
</div>
<style>.lspin{width:22px;height:22px;border-radius:50%;border:3px solid rgba(0,0,0,.15);border-top-color:#EF4375;animation:lspin .8s linear infinite;flex:0 0 auto}@keyframes lspin{to{transform:rotate(360deg)}}</style>
<script>
(function(){var t=setInterval(function(){
  var fd=new FormData(); fd.append('csrf',<?= json_encode(csrf_token()) ?>); fd.append('accion','poll_logo'); fd.append('ajax','1');
  fetch(location.pathname+location.search,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
    if(d&&d.listo){ clearInterval(t); location.href='/crecer/panel/marca.php?marca=<?= (int)$marca_id ?>&ok=1'; }
    else if(d&&d.pendiente===false){ clearInterval(t); location.reload(); }
  }).catch(function(){});
},5000);})();
</script>
<?php endif; ?>

<div class="idz-hub">
  <button type="button" class="idz-choice" data-goto="subir">
    <span class="chip"><?= ico('upload') ?></span>
    <b>Ya tengo mi logo</b><small>Súbelo desde tu teléfono</small>
  </button>
  <?php if ($pagado): ?>
    <button type="button" class="idz-choice" data-goto="crear">
      <span class="chip"><?= ico('sparkles') ?></span>
      <b>Crear con IA</b><small><?= $final ? 'Tu logo ya está listo' : "Te quedan {$restantes} de {$LIMITE_LOGO}" ?></small>
    </button>
  <?php else: ?>
    <a class="idz-choice locked" href="/crecer/panel/precios.php?marca=<?= $marca_id ?>">
      <span class="chip"><?= ico('lock') ?></span>
      <b>Crear con IA</b><small>Se desbloquea con un plan</small>
    </a>
  <?php endif; ?>
</div>
<?php if ($logos): ?><button type="button" class="vz-ex" data-goto="catalogo" style="margin-top:14px"><?= ico('image') ?> Ver mi catálogo (<?= count($logos) ?>)</button><?php endif; ?>
<div class="vz-nav"><button type="button" class="vz-back" data-goto="linea"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6"/></svg>Atrás</button><span></span></div>
</section>

<!-- ── Identidad · Card 3: Subir tu logo ── -->
<section class="vz-card" data-card="subir">
<!-- SUBIR LOGO PROPIO (principal o secundarios) — no es premium -->
<div class="genbox" style="margin-bottom:0">
  <h3 style="font-size:15px;margin:0 0 4px"><?= ico('upload') ?> Sube tu logo</h3>
  <p class="subline" style="margin-bottom:10px">¿Ya tienes logo? Súbelo (principal y/o secundarios). La IA lo usará como referencia en tus posts. Lo ideal: <b>PNG con fondo transparente</b>.</p>
  <form method="post" enctype="multipart/form-data" onsubmit="var b=this.querySelector('.genbtn');b.textContent='Subiendo…';b.disabled=true;">
    <input type="hidden" name="accion" value="subir_logo"><input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES) ?>">
    <input type="file" id="logoFile" name="logo_file" class="fp-in" accept="image/png,image/jpeg,image/webp" required>
    <label for="logoFile" class="filepick" style="margin-bottom:10px">
      <?= ico('upload') ?><span class="fp-tx" data-default="Escoge tu logo (PNG, JPG o WEBP)">Escoge tu logo (PNG, JPG o WEBP)</span>
    </label>
    <label class="chip-opt" style="display:inline-flex;margin-bottom:10px"><input type="checkbox" name="principal" value="1" checked><span>Usar como logo principal</span></label>
    <button class="genbtn" type="submit">Subir logo</button>
  </form>
</div>
<div class="vz-nav"><button type="button" class="vz-back" data-goto="hub"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6"/></svg>Atrás</button><span></span></div>
</section>

<!-- ── Identidad · Card 4: Crear con IA ── -->
<section class="vz-card" data-card="crear">
<?php if (!$pagado): ?>
  <div class="genbox" style="text-align:center;background:linear-gradient(135deg,rgba(255,107,61,.07),rgba(255,43,133,.07))">
    <div style="color:var(--terracota)"><?= ico('lock','ic-xl') ?></div>
    <div style="font-family:var(--font-display);font-weight:600;font-size:22px;color:var(--ink-soft);margin:6px 0">El logo es premium</div>
    <p style="color:var(--muted);font-size:14px;max-width:430px;margin:0 auto 16px">Tu logo profesional con IA, en los formatos que necesites, se desbloquea con un plan. (Tu post de muestra sí es gratis)</p>
    <a class="genbtn" href="/crecer/panel/precios.php?marca=<?= $marca_id ?>" style="text-decoration:none;display:inline-block">Desbloquear mi logo →</a>
  </div>
<?php elseif (!$final && $restantes > 0): ?>
  <div class="genbox">
    <form method="post" onsubmit="var b=this.querySelector('.genbtn');b.textContent='Creando… (~15s)';b.disabled=true;">
      <input type="hidden" name="accion" value="logo"><input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES) ?>">

      <label class="fl">Descripción del negocio <span style="color:var(--muted);font-weight:500">(edítala a tu gusto)</span></label>
      <textarea name="descripcion" rows="2"><?= $h($marca['descripcion']) ?></textarea>

      <label class="fl">Estilo</label>
      <div class="chips">
        <?php
        $estilos = ['Auto'=>'', 'Boricua'=>'boricua, con orgullo puertorriqueño', 'Moderno'=>'moderno y minimalista', 'Clásico'=>'clásico y atemporal', 'Elegante'=>'elegante y premium', 'Divertido'=>'divertido y colorido', 'Retro'=>'retro vintage'];
        foreach ($estilos as $lb=>$val): ?>
          <label class="chip-opt"><input type="radio" name="estilo" value="<?= $h($val) ?>" <?= $lb==='Auto'?'checked':'' ?>><span><?= $h($lb) ?></span></label>
        <?php endforeach; ?>
      </div>

      <label class="fl">Ajusta el feeling</label>
      <div class="slider"><span>Serio</span><input type="range" name="tono" min="0" max="100" value="50"><span>Alegre</span></div>
      <div class="slider"><span>Clásico</span><input type="range" name="epoca" min="0" max="100" value="50"><span>Moderno</span></div>
      <div class="slider"><span>Simple</span><input type="range" name="detalle" min="0" max="100" value="50"><span>Detallado</span></div>

      <label class="fl">Tipografía</label>
      <div class="chips">
        <?php
        $tipos = ['Auto'=>'', 'Moderna'=>'sans-serif moderna', 'Clásica'=>'serif clásica', 'Redonda'=>'redondeada y amigable', 'Manuscrita'=>'manuscrita / script', 'Negrita'=>'bold fuerte e impactante'];
        foreach ($tipos as $lb=>$val): ?>
          <label class="chip-opt"><input type="radio" name="tipografia" value="<?= $h($val) ?>" <?= $lb==='Auto'?'checked':'' ?>><span><?= $h($lb) ?></span></label>
        <?php endforeach; ?>
      </div>

      <label class="fl">Algo más (opcional)</label>
      <textarea name="instrucciones" rows="2" placeholder="&quot;ponle un coquí&quot;, &quot;colores azul y dorado&quot;, &quot;que parezca sello&quot;…"></textarea>

      <button class="genbtn" type="submit" style="margin-top:6px"><?= $usados ? 'Generar otro logo' : 'Generar mi primer logo' ?></button>
    </form>
    <div class="genline">Te quedan <b style="color:var(--terracota)"><?= $restantes ?> de <?= $LIMITE_LOGO ?></b> pruebas incluidas.</div>
    <div class="policy">Después de las <?= $LIMITE_LOGO ?>: intentos adicionales tienen costo, o pide un logo personalizado por un artista gráfico.</div>
  </div>
<?php elseif (!$final && $restantes <= 0): ?>
  <div class="genbox" style="background:var(--amber-bg)">
    <b style="color:var(--amber-ink)">Usaste tus <?= $LIMITE_LOGO ?> pruebas incluidas.</b>
    <div class="genline">Escoge tu favorito abajo. ¿Quieres más opciones? Compra intentos adicionales o pide un logo personalizado por un artista gráfico.</div>
  </div>
<?php endif; ?>
<div class="vz-nav"><button type="button" class="vz-back" data-goto="hub"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6"/></svg>Atrás</button><span></span></div>
</section>

<!-- ── Identidad · Card 5: Tu catálogo ── -->
<section class="vz-card" data-card="catalogo">
<h2 class="sec-h" style="margin-top:2px"><?= ico('image') ?>Tus logos</h2>
<?php if ($final): ?>
  <div class="ok-banner" style="max-width:620px">Tu logo final está elegido. Descárgalo cuando quieras en los formatos que necesites.</div>
<?php endif; ?>

<!-- GALERÍA -->
<?php if (!$logos): ?>
  <p class="empty-g">El Diseñador todavía no te ha montado un logo. Dale a "Generar mi primer logo" arriba y mete mano.</p>
<?php else: ?>
  <div class="gallery">
    <?php foreach ($logos as $l):
      $es = $l['elegido'];
      $oculto = $final && !$es; // si ya finalizó, atenúa los no elegidos
    ?>
      <div class="tile <?= $es?'sel':'' ?> <?= $oculto?'locked':'' ?>" style="position:relative">
        <img class="zoomable" src="<?= $h($l['archivo']) ?>" alt="logo">
        <?php if (!$es): ?>
          <form method="post" style="position:absolute;top:6px;right:6px;margin:0;z-index:3" onsubmit="return confirm('¿Eliminar este logo? No se puede deshacer.')">
            <input type="hidden" name="accion" value="eliminar_logo">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES) ?>">
            <input type="hidden" name="logo_id" value="<?= $l['id'] ?>">
            <button type="submit" aria-label="Eliminar logo" title="Eliminar" style="width:30px;height:30px;border-radius:50%;border:0;cursor:pointer;background:rgba(20,10,22,.55);color:#fff;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(2px)">
              <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
            </button>
          </form>
        <?php endif; ?>
        <?php if ($es): ?>
          <div class="badge">✓ Tu logo</div>
        <?php elseif (!$final): ?>
          <form method="post"><input type="hidden" name="accion" value="elegir"><input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES) ?>"><input type="hidden" name="logo_id" value="<?= $l['id'] ?>">
            <button class="pick" type="submit">Elegir este</button></form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- DESCARGA DEL ELEGIDO -->
<?php if ($elegido): ?>
  <div class="chosen">
    <h3><?= ico('download') ?> Descargar tu logo<?= $final?' final':'' ?></h3>
    <img id="logoimg" src="<?= $h($elegido['archivo']) ?>" style="display:none">
    <div class="dl">
      <button type="button" onclick="dlLogo('png')">PNG</button>
      <button type="button" onclick="dlLogo('jpeg')">JPG</button>
      <button type="button" onclick="dlLogo('webp')">WebP</button>
      <button type="button" onclick="dlLogo('png',400)">Perfil 400px</button>
    </div>
    <?php if (!$final): ?>
      <div class="warn"><?= ico('bolt') ?> Al descargar, <b>este será tu logo final</b> y ya no podrás escoger otro. Descárgalo en todos los formatos que quieras.</div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<script>
  var _locked = <?= $final ? 'true' : 'false' ?>;
  function dlLogo(fmt, size){
    var img = new Image();
    img.onload = function(){
      var w = img.naturalWidth || 1024, h = img.naturalHeight || 1024;
      if (size){ var sc = size / Math.max(w,h); w = Math.round(w*sc); h = Math.round(h*sc); }
      var c = document.createElement('canvas'); c.width = w; c.height = h;
      var ctx = c.getContext('2d');
      if (fmt === 'jpeg'){ ctx.fillStyle = '#fff'; ctx.fillRect(0,0,w,h); }
      ctx.drawImage(img, 0, 0, w, h);
      var mime = fmt==='jpeg'?'image/jpeg':(fmt==='webp'?'image/webp':'image/png');
      c.toBlob(function(b){
        var a = document.createElement('a'); a.href = URL.createObjectURL(b);
        a.download = 'logo-<?= $h($marca['slug']) ?>' + (size?('-'+size):'') + '.' + (fmt==='jpeg'?'jpg':fmt);
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(function(){ URL.revokeObjectURL(a.href); }, 2000);
      }, mime, 0.95);
      // Primera descarga = finaliza la elección (bloquea los demás)
      if (!_locked){
        _locked = true;
        fetch('?marca=<?= $marca_id ?>', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'accion=bloquear&csrf='+encodeURIComponent(<?= json_encode(csrf_token()) ?>)});
        document.querySelectorAll('.tile .pick').forEach(function(b){b.style.display='none';});
        document.querySelectorAll('.genbox').forEach(function(b){b.style.display='none';});
        document.querySelectorAll('.tile:not(.sel)').forEach(function(t){t.classList.add('locked');});
        var w2=document.querySelector('.warn'); if(w2) w2.textContent='Logo finalizado. Puedes seguir descargándolo en otros formatos.';
      }
    };
    img.src = document.getElementById('logoimg').src;
  }
</script>
<div class="vz-nav"><button type="button" class="vz-back" data-goto="hub"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6"/></svg>Atrás</button><span></span></div>
</section><!-- /catalogo -->

</div></div><!-- /idz-track /idz-view -->

<script>
// Identidad — wizard de swipe-cards (línea → hub → subir → crear → catálogo).
(function(){
  var view=document.getElementById('idzView'), track=document.getElementById('idzTrack');
  if(!view||!track) return;
  var cards=[].slice.call(track.querySelectorAll('.vz-card'));
  var keys=cards.map(function(c){return c.dataset.card;});
  var dotsWrap=document.getElementById('idzDots'), dots=[];
  cards.forEach(function(_,i){ var d=document.createElement('i'); if(i===0)d.className='on'; dotsWrap.appendChild(d); dots.push(d); });
  var cur=0;
  function fit(){ var c=cards[cur]; if(c) view.style.height=c.offsetHeight+'px'; window.dispatchEvent(new Event('resize')); }
  function go(i){ i=Math.max(0,Math.min(cards.length-1,i)); cur=i;
    track.style.transform='translateX(-'+(i*100)+'%)';
    dots.forEach(function(d,x){ d.classList.toggle('on',x===i); }); fit(); }
  function goKey(k){ var i=keys.indexOf(k); if(i>=0) go(i); }
  track.querySelectorAll('[data-goto]').forEach(function(b){ if(b.tagName==='A')return; b.addEventListener('click',function(){ goKey(b.dataset.goto); }); });
  // swipe (no interfiere con inputs/sliders/galería)
  var x0=null,y0=null,lock=null;
  view.addEventListener('touchstart',function(e){ if(e.target.closest('input,textarea,.gallery,button,a')){x0=null;return;} var t=e.touches[0];x0=t.clientX;y0=t.clientY;lock=null; },{passive:true});
  view.addEventListener('touchmove',function(e){ if(x0===null)return; var t=e.touches[0],dx=t.clientX-x0,dy=t.clientY-y0; if(lock===null&&(Math.abs(dx)>8||Math.abs(dy)>8)) lock=Math.abs(dx)>Math.abs(dy)?'x':'y'; },{passive:true});
  view.addEventListener('touchend',function(e){ if(x0===null||lock!=='x'){x0=null;return;} var dx=e.changedTouches[0].clientX-x0; if(dx<-45)go(cur+1); else if(dx>45)go(cur-1); x0=null; },{passive:true});
  window.addEventListener('resize',function(){ var c=cards[cur]; if(c) view.style.height=c.offsetHeight+'px'; });
  // deep-link: dónde arranca según el estado
  var init='linea';
  <?php if ($err): ?>init='crear';<?php elseif ($logo_pendiente): ?>init='catalogo';<?php endif; ?>
  if(/[?&](ok|logo_gen)=/.test(location.search)) init='catalogo';
  track.style.transition='none'; goKey(init); requestAnimationFrame(function(){ track.style.transition=''; });
})();
</script>
</section><!-- /mk-identidad -->
</div></div><!-- /mk-track /mk-view -->

<script>
// Mi marca — molde ventana limpia + slide lateral (Voz / Identidad / Lo aprendido).
(function(){
  var order=['voz','identidad','aprendido'];
  var tabs=[].slice.call(document.querySelectorAll('.mk-tab'));
  var view=document.getElementById('mkView'), track=document.getElementById('mkTrack');
  var secBy={voz:document.getElementById('mk-voz'),identidad:document.getElementById('mk-identidad'),aprendido:document.getElementById('mk-aprendido')};
  if(!view||!track) return;
  var cur=0;
  function fitHeight(){ var s=secBy[order[cur]]; if(s) view.style.height=s.offsetHeight+'px'; }
  function go(i){
    i=Math.max(0,Math.min(order.length-1,i)); cur=i;
    track.style.transform='translateX(-'+(i*100)+'%)';
    tabs.forEach(function(t){ t.classList.toggle('on', t.dataset.pane===order[i]); });
    fitHeight(); window.scrollTo({top:0,behavior:'smooth'});
  }
  tabs.forEach(function(t){ t.addEventListener('click', function(){ go(order.indexOf(t.dataset.pane)); }); });

  var init=0;
  if(location.hash==='#cerebro') init=2;                         // volver de corregir/descartar memoria
  else if(location.hash==='#identidad' || /[?&](ok|estilo|logo_gen)=/.test(location.search)) init=1;  // línea de diseño / logo
  track.style.transition='none'; go(init); requestAnimationFrame(function(){ track.style.transition=''; });
  window.addEventListener('resize', fitHeight);
  window.addEventListener('load', fitHeight);
  if(window.ResizeObserver){ new ResizeObserver(fitHeight).observe(track); }

  var x0=null,y0=null,lock=null;
  view.addEventListener('touchstart',function(e){ if(e.target.closest('.gallery,.cer-grid,.chips,.vz-view')){x0=null;return;} var t=e.touches[0];x0=t.clientX;y0=t.clientY;lock=null;},{passive:true});
  view.addEventListener('touchmove',function(e){ if(x0===null)return; var t=e.touches[0],dx=t.clientX-x0,dy=t.clientY-y0; if(lock===null&&(Math.abs(dx)>8||Math.abs(dy)>8)) lock=Math.abs(dx)>Math.abs(dy)?'x':'y'; },{passive:true});
  view.addEventListener('touchend',function(e){ if(x0===null||lock!=='x'){x0=null;return;} var dx=e.changedTouches[0].clientX-x0; if(dx<-45)go(cur+1); else if(dx>45)go(cur-1); x0=null; },{passive:true});
})();
</script>

<?php require __DIR__ . '/_shell_foot.php'; ?>
