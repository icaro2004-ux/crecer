<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Contenido / Aprobar (dentro del shell)
//  panel/aprobar2.php
// ============================================================
// DEBUG temporal: añade &debug=1 a la URL para ver errores en pantalla.
if (isset($_GET['debug'])) { ini_set('display_errors','1'); error_reporting(E_ALL); }
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/agentes.php';
require __DIR__ . '/../includes/suscripcion.php';
requiere_login();
$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/intake.php'); exit; }
$marca_id = (int)$marca['id'];
$pagado = marca_es_pagada($pdo, $marca_id);  // no pagado = 1 post de muestra (1 caption + 1 imagen)

// ── Acción POST (PRG) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int)($_POST['id'] ?? 0);
    $accion = $_POST['accion'] ?? '';

    // ── Editar caption (+ el bot aprende) ──
    if ($accion === 'editar') {
        $nuevo_cap = trim($_POST['caption'] ?? '');
        $o = $pdo->prepare("SELECT caption FROM crecer_contenido WHERE id=? AND marca_id=?");
        $o->execute([$id, $marca_id]); $orig = (string)$o->fetchColumn();
        $leccion = null;
        if ($id && $nuevo_cap !== '') {
            $pdo->prepare("UPDATE crecer_contenido SET caption=?, updated_at=NOW() WHERE id=? AND marca_id=?")->execute([$nuevo_cap, $id, $marca_id]);
            if ($pagado) $leccion = aprender_de_edicion($pdo, $marca_id, $orig, $nuevo_cap); // aprendizaje = premium
        }
        if (!empty($_POST['ajax'])) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'id'=>$id,'caption'=>$nuevo_cap,'leccion'=>$leccion], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
        header('Location: ' . $_SERVER['REQUEST_URI']); exit;
    }
    // ── Regenerar caption con la IA ──
    if ($accion === 'regenerar') {
        if (!$pagado) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'paywall'=>true]); exit; }
        @set_time_limit(0);
        try { $r = redactar_pieza($pdo, $id); $cap = $r['caption']; }
        catch (Throwable $e) { $cap = null; }
        if (!empty($_POST['ajax'])) { header('Content-Type: application/json'); echo json_encode(['ok'=>(bool)$cap,'id'=>$id,'caption'=>$cap], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
        header('Location: ' . $_SERVER['REQUEST_URI']); exit;
    }
    // ── Reprogramar (el dueño escoge el día) ──
    if ($accion === 'fecha') {
        $f = $_POST['fecha'] ?? '';
        if ($id && strtotime($f)) {
            $pdo->prepare("UPDATE crecer_contenido SET fecha_programada=?, updated_at=NOW() WHERE id=? AND marca_id=?")
                ->execute([date('Y-m-d 10:00:00', strtotime($f)), $id, $marca_id]);
        }
        if (!empty($_POST['ajax'])) { header('Content-Type: application/json'); echo json_encode(['ok'=>(bool)strtotime($f)]); exit; }
        header('Location: ' . $_SERVER['REQUEST_URI']); exit;
    }

    // ── Crear el ARTE del post SIN salir (fábrica de posts) ──
    if ($accion === 'arte') {
        @set_time_limit(0);
        if (!$pagado && generaciones_usadas($pdo, $marca_id, 'imagen') >= CRECER_FREE['imagen']) {
            header('Content-Type: application/json'); echo json_encode(['ok'=>false,'err'=>'paywall']); exit;
        }
        $dir_fotos = rtrim(UPLOADS_PATH, '/\\') . "/marca_{$marca_id}/fotos";
        // Tope por post (2 generaciones IA)
        $ai = $pdo->prepare("SELECT arte_intentos FROM crecer_contenido WHERE id=? AND marca_id=?");
        $ai->execute([$id, $marca_id]); $intentos = (int)$ai->fetchColumn();
        if ($intentos >= CRECER_IMG_POST) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'err'=>'post_limite']); exit; }
        // Tope semanal (10 imágenes)
        $wk = $pdo->prepare("SELECT COUNT(*) c, MIN(created_at) oldest FROM crecer_graficas WHERE marca_id=? AND created_at >= (NOW() - INTERVAL 7 DAY)");
        $wk->execute([$marca_id]); $w = $wk->fetch(); $usados = (int)$w['c'];
        $reset = $w['oldest'] ? date('d/m', strtotime($w['oldest'].' +7 days')) : null;
        if ($usados >= CRECER_IMG_SEMANA) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'err'=>'limite','reset'=>$reset]); exit; }
        // Foto: subida nueva (inline) o escogida del picker
        $src = null;
        if (!empty($_FILES['foto_nueva']['tmp_name']) && $_FILES['foto_nueva']['error'] === UPLOAD_ERR_OK) {
            $info = @getimagesize($_FILES['foto_nueva']['tmp_name']);
            $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$info['mime'] ?? ''] ?? null;
            if ($ext) { @mkdir($dir_fotos, 0775, true); $dest = $dir_fotos.'/foto_'.uniqid().'.'.$ext;
                if (move_uploaded_file($_FILES['foto_nueva']['tmp_name'], $dest)) $src = $dest; }
        } elseif (!empty($_POST['foto'])) {
            $nombre = basename($_POST['foto']);
            if (strpos($nombre,'..')===false && is_file($dir_fotos.'/'.$nombre)) $src = $dir_fotos.'/'.$nombre;
        }
        $capr = $pdo->prepare("SELECT caption FROM crecer_contenido WHERE id=? AND marca_id=?");
        $capr->execute([$id, $marca_id]); $copy = (string)$capr->fetchColumn();
        try {
            $r = generar_grafica($pdo, $marca_id, $src, [
                'copy'         => $copy,
                'con_texto'    => ($_POST['con_texto'] ?? '') === '1',
                'con_logo'     => !empty($_POST['con_logo']),
                'logo_estilo'  => $_POST['logo_estilo'] ?? 'esquina',
                'estilo'       => $_POST['estilo'] ?? '',
                'instrucciones'=> trim($_POST['instrucciones'] ?? ''),
            ]);
            $pdo->prepare("UPDATE crecer_contenido SET grafica_path=?, arte_intentos=arte_intentos+1, updated_at=NOW() WHERE id=? AND marca_id=?")
                ->execute([$r['archivo'], $id, $marca_id]);
            header('Content-Type: application/json');
            echo json_encode([
                'ok'=>true, 'id'=>$id, 'img'=>$r['archivo'],
                'restantes'=>max(0, CRECER_IMG_SEMANA - ($usados+1)),
                'restantes_post'=>max(0, CRECER_IMG_POST - ($intentos+1)),
                'reset'=>$reset,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Throwable $e) {
            header('Content-Type: application/json'); echo json_encode(['ok'=>false,'err'=>substr($e->getMessage(),0,120)]); exit;
        }
    }
    // ── Reusar un arte ya creado (sin regenerar, sin gastar del límite) ──
    if ($accion === 'reusar_arte') {
        $gid = (int)($_POST['gid'] ?? 0);
        $g = $pdo->prepare("SELECT archivo FROM crecer_graficas WHERE id=? AND marca_id=?");
        $g->execute([$gid, $marca_id]); $arch = $g->fetchColumn();
        if ($arch && $id) {
            $pdo->prepare("UPDATE crecer_contenido SET grafica_path=?, updated_at=NOW() WHERE id=? AND marca_id=?")->execute([$arch, $id, $marca_id]);
            header('Content-Type: application/json'); echo json_encode(['ok'=>true,'id'=>$id,'img'=>$arch], JSON_UNESCAPED_UNICODE); exit;
        }
        header('Content-Type: application/json'); echo json_encode(['ok'=>false]); exit;
    }

    // ── Usar foto propia TAL CUAL (sin IA, sin límite) ──
    if ($accion === 'foto_directa') {
        $dir_fotos = rtrim(UPLOADS_PATH, '/\\') . "/marca_{$marca_id}/fotos";
        if (!empty($_FILES['imagen']['tmp_name']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK && $_FILES['imagen']['size'] <= 12*1024*1024) {
            $info = @getimagesize($_FILES['imagen']['tmp_name']);
            $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$info['mime'] ?? ''] ?? null;
            if ($ext) {
                @mkdir($dir_fotos, 0775, true); $fn = 'foto_'.uniqid().'.'.$ext;
                if (move_uploaded_file($_FILES['imagen']['tmp_name'], $dir_fotos.'/'.$fn)) {
                    $url = rtrim(UPLOADS_URL, '/') . "/marca_{$marca_id}/fotos/" . $fn;
                    $pdo->prepare("INSERT INTO crecer_graficas (marca_id, archivo, copy_text) VALUES (?,?,?)")->execute([$marca_id, $url, '(imagen propia)']);
                    $pdo->prepare("UPDATE crecer_contenido SET grafica_path=?, updated_at=NOW() WHERE id=? AND marca_id=?")->execute([$url, $id, $marca_id]);
                    header('Content-Type: application/json'); echo json_encode(['ok'=>true,'id'=>$id,'img'=>$url], JSON_UNESCAPED_UNICODE); exit;
                }
            }
        }
        header('Content-Type: application/json'); echo json_encode(['ok'=>false,'err'=>'No se pudo subir (usa JPG/PNG/WebP, máx 12MB).']); exit;
    }

    // ── Pedir un post a la IA (tema sugerido / borrador a pulir / random) ──
    if ($accion === 'pedir_post') {
        @set_time_limit(0);
        // No pagado: 1 post de muestra. Si ya lo usó → al paywall; si no, forzar 1 sola pieza.
        if (!$pagado) {
            if (generaciones_usadas($pdo, $marca_id, 'caption') >= CRECER_FREE['caption']) {
                header("Location: /crecer/panel/precios.php?marca={$marca_id}&motivo=muestra"); exit;
            }
            $pl0 = $_POST['plataformas'] ?? 'instagram';
            if (is_array($pl0)) $pl0 = $pl0[0] ?? 'instagram';
            $_POST['plataformas'] = [$pl0];
            $_POST['n'] = 1;
        }
        $tema     = trim($_POST['tema'] ?? '');
        $borrador = trim($_POST['borrador'] ?? '');
        // Una o varias plataformas (un post por cada una, adaptado a esa red)
        $plats = $_POST['plataformas'] ?? ['instagram'];
        if (!is_array($plats)) $plats = [$plats];
        $plats = array_values(array_intersect(['instagram','facebook','whatsapp'], $plats));
        if (!$plats) $plats = ['instagram'];
        $fecha = $_POST['fecha'] ?? '';
        $fecha_dt = ($fecha && strtotime($fecha)) ? (date('Y-m-d', strtotime($fecha)) . ' 10:00:00') : date('Y-m-d 10:00:00');

        if ($tema !== '' || $borrador !== '') {
            // ── Post guiado por el dueño: 1 pieza por plataforma ──
            $fa = (int)date('Y', strtotime($fecha_dt)); $fm = (int)date('n', strtotime($fecha_dt));
            $pdo->prepare("INSERT INTO crecer_calendario (marca_id, anio, mes, estado, generado_por_ia) VALUES (?,?,?, 'borrador', 1) ON DUPLICATE KEY UPDATE updated_at=NOW()")->execute([$marca_id,$fa,$fm]);
            $calid = (int)$pdo->query("SELECT id FROM crecer_calendario WHERE marca_id={$marca_id} AND anio={$fa} AND mes={$fm}")->fetchColumn();
            $idea = $tema !== '' ? $tema : 'Pulir borrador del dueño';
            $ins = $pdo->prepare("INSERT INTO crecer_contenido (calendario_id, marca_id, plataforma, tipo, caption, fecha_programada, estado) VALUES (?,?,?,?,?,?, 'borrador')");
            $first = 0;
            foreach ($plats as $pl) {
                $tipo = $pl === 'whatsapp' ? 'story' : 'post'; // WhatsApp = Estado (≈ story)
                $ins->execute([$calid, $marca_id, $pl, $tipo, $idea, $fecha_dt]);
                $nid = (int)$pdo->lastInsertId(); if (!$first) $first = $nid;
                try { redactar_sugerido($pdo, $nid, $tema, $borrador); }
                catch (Throwable $e) { /* queda el borrador con la idea para editar */ }
            }
            header("Location: /crecer/panel/aprobar2.php?marca={$marca_id}&tab=pendientes&generados=".count($plats)."#cap-{$first}"); exit;
        }
        // ── Sin tema: la IA inventa N (planificador) ──
        $n = max(1, min(6, (int)($_POST['n'] ?? 3)));
        $cal = $pdo->prepare("SELECT anio, mes FROM crecer_calendario WHERE marca_id=? ORDER BY anio DESC, mes DESC LIMIT 1");
        $cal->execute([$marca_id]); $cal = $cal->fetch();
        $ca = $cal ? (int)$cal['anio'] : (int)date('Y');
        $cm = $cal ? (int)$cal['mes']  : (int)date('n');
        try {
            $plan = planificar_mes($pdo, $marca_id, $ca, $cm, $n);
            foreach ($plan['piezas'] as $pz) { try { redactar_pieza($pdo, (int)$pz['id']); } catch (Throwable $e) {} }
        } catch (Throwable $e) {
            header("Location: /crecer/panel/aprobar2.php?marca={$marca_id}&err=".urlencode(substr($e->getMessage(),0,100))); exit;
        }
        header("Location: /crecer/panel/aprobar2.php?marca={$marca_id}&tab=pendientes&generados={$n}"); exit;
    }
    // ── Escribir un post yo mismo (borrador vacío para editar) ──
    if ($accion === 'nuevo_manual') {
        $cal = (int)$pdo->query("SELECT id FROM crecer_calendario WHERE marca_id={$marca_id} ORDER BY anio DESC, mes DESC LIMIT 1")->fetchColumn();
        if (!$cal) {
            $ca = (int)date('Y'); $cm = (int)date('n');
            $pdo->prepare("INSERT INTO crecer_calendario (marca_id, anio, mes, estado, generado_por_ia) VALUES (?,?,?, 'borrador', 0) ON DUPLICATE KEY UPDATE updated_at=NOW()")->execute([$marca_id,$ca,$cm]);
            $cal = (int)$pdo->query("SELECT id FROM crecer_calendario WHERE marca_id={$marca_id} AND anio={$ca} AND mes={$cm}")->fetchColumn();
        }
        $plat = in_array($_POST['plataforma'] ?? '', ['instagram','facebook','whatsapp'], true) ? $_POST['plataforma'] : 'instagram';
        $pdo->prepare("INSERT INTO crecer_contenido (calendario_id, marca_id, plataforma, tipo, caption, fecha_programada, estado) VALUES (?,?,?,?,?,?, 'borrador')")
            ->execute([$cal, $marca_id, $plat, 'post', '', date('Y-m-d 10:00:00')]);
        $nid = (int)$pdo->lastInsertId();
        header("Location: /crecer/panel/aprobar2.php?marca={$marca_id}&edit={$nid}#cap-{$nid}"); exit;
    }

    $nuevo  = ['aprobar'=>'aprobado','rechazar'=>'rechazado','reabrir'=>'borrador','marcar_publicado'=>'publicado'][$accion] ?? null;
    if ($id && $nuevo) {
        $pdo->prepare("UPDATE crecer_contenido SET estado=?, updated_at=NOW() WHERE id=? AND marca_id=?")
            ->execute([$nuevo, $id, $marca_id]);
    }
    if (!empty($_POST['ajax'])) {
        $c = ['borrador'=>0,'aprobado'=>0,'rechazado'=>0,'publicado'=>0];
        foreach ($pdo->query("SELECT estado, COUNT(*) n FROM crecer_contenido WHERE marca_id={$marca_id} GROUP BY estado") as $r) $c[$r['estado']] = (int)$r['n'];
        header('Content-Type: application/json');
        echo json_encode(['ok'=>true, 'id'=>$id, 'estado'=>$nuevo, 'pend'=>$c['borrador'], 'aprob'=>$c['aprobado']+$c['publicado']]);
        exit;
    }
    header('Location: ' . $_SERVER['REQUEST_URI']); exit;
}

// ── Vista: '' = portada (hub) · pendientes · aprobados (fábrica) ──
$tab = $_GET['tab'] ?? '';
if ($tab !== 'aprobados' && $tab !== '') $tab = 'pendientes';
$es_hub = ($tab === '');

// Conteos globales para los tabs
$cnt = ['borrador'=>0,'aprobado'=>0,'rechazado'=>0,'publicado'=>0];
foreach ($pdo->query("SELECT estado, COUNT(*) n FROM crecer_contenido WHERE marca_id={$marca_id} GROUP BY estado") as $r) $cnt[$r['estado']] = (int)$r['n'];
$n_pend  = $cnt['borrador'];
$n_aprob = $cnt['aprobado'] + $cnt['publicado'];

// ── Datos de la PORTADA (hub) ──
$publicados_mes = (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$marca_id} AND estado='publicado' AND YEAR(COALESCE(publicado_at,updated_at))=YEAR(NOW()) AND MONTH(COALESCE(publicado_at,updated_at))=MONTH(NOW())")->fetchColumn();
$feed_map = [
  'planificador'=>['estratega','El Estratega','cuadró el plan'],
  'creador'     =>['creativa','La Creativa','escribió contenido'],
  'diseñador'   =>['disenador','El Diseñador','preparó un arte'],
  'analitica'   =>['analista','El Analista','revisó tus números'],
  'retencion'   =>['vendedor','El Vendedor','le escribió a un cliente'],
  'intake'      =>['estratega','El Estratega','aprendió de tu negocio'],
  'asistente'   =>['creativa','El Asistente','resolvió una duda'],
  'aprendiz'    =>['creativa','La Creativa','aprendió tu vocabulario'],
  'editor'      =>['creativa','La Creativa','pulió un texto'],
];
$tl = $pdo->prepare("SELECT agente, created_at FROM crecer_ia_log WHERE marca_id=? AND estado='ok' ORDER BY id DESC LIMIT 5");
$tl->execute([$marca_id]); $timeline = $tl->fetchAll();
$idq = $pdo->prepare("SELECT caption FROM crecer_contenido WHERE marca_id=? AND estado='borrador' ORDER BY id DESC LIMIT 4");
$idq->execute([$marca_id]); $ideas = $idq->fetchAll();

$meses_aprob = []; $mes_sel = '';
if ($tab === 'aprobados') {
    // Meses que tienen posts aprobados (para cargar uno a la vez)
    $mq = $pdo->prepare("SELECT DATE_FORMAT(fecha_programada,'%Y-%m') ym, COUNT(*) n FROM crecer_contenido
                          WHERE marca_id=? AND estado IN ('aprobado','publicado') AND fecha_programada IS NOT NULL
                          GROUP BY ym ORDER BY ym DESC");
    $mq->execute([$marca_id]); $meses_aprob = $mq->fetchAll();
    $mes_sel = $_GET['mes'] ?? ($meses_aprob[0]['ym'] ?? date('Y-m'));
    [$yy,$mm] = array_map('intval', array_pad(explode('-', (string)$mes_sel), 2, 0));
    $pq = $pdo->prepare("SELECT * FROM crecer_contenido
                          WHERE marca_id=? AND estado IN ('aprobado','publicado')
                            AND YEAR(fecha_programada)=? AND MONTH(fecha_programada)=?
                          ORDER BY fecha_programada");
    $pq->execute([$marca_id, $yy, $mm]); $piezas = $pq->fetchAll();
} else {
    $pq = $pdo->prepare("SELECT * FROM crecer_contenido WHERE marca_id=? AND estado='borrador' ORDER BY fecha_programada");
    $pq->execute([$marca_id]); $piezas = $pq->fetchAll();
}

// Recursos para el estudio de arte inline (fábrica de posts)
$dir_fotos = rtrim(UPLOADS_PATH, '/\\') . "/marca_{$marca_id}/fotos";
$url_fotos = rtrim(UPLOADS_URL, '/') . "/marca_{$marca_id}/fotos";
$fotos = is_dir($dir_fotos) ? array_values(array_filter(scandir($dir_fotos), fn($x)=>$x[0]!=='.')) : [];
$tiene_logo = !empty($marca['logo_path']);
$wk = $pdo->prepare("SELECT COUNT(*) c, MIN(created_at) oldest FROM crecer_graficas WHERE marca_id=? AND created_at >= (NOW() - INTERVAL 7 DAY)");
$wk->execute([$marca_id]); $w = $wk->fetch();
$restantes_sem = max(0, CRECER_IMG_SEMANA - (int)$w['c']);
$reset_fecha = $w['oldest'] ? date('d/m', strtotime($w['oldest'].' +7 days')) : null;
// Artes ya creados (para reciclar sin gastar del límite)
$gq = $pdo->prepare("SELECT id, archivo FROM crecer_graficas WHERE marca_id=? ORDER BY id DESC LIMIT 30");
$gq->execute([$marca_id]); $graficas = $gq->fetchAll();

$total = count($piezas);
$meses_es = [1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$nombre_mes = function($ym) use ($meses_es) { $p=explode('-',$ym); return ($meses_es[(int)($p[1]??1)] ?? '') . ' ' . ($p[0] ?? ''); };

$plat = ['instagram'=>['Instagram',''], 'facebook'=>['Facebook','fb'], 'whatsapp'=>['WhatsApp','']];
$pill = ['borrador'=>['Pendiente','wait'],'aprobado'=>['Aprobado','ok'],'rechazado'=>['Rechazado','no'],'publicado'=>['Publicado','pub']];
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$handle = $marca['instagram'] ?: ('@' . preg_replace('/[^a-z0-9]/', '', mb_strtolower($marca['nombre_negocio'])));
$avatar = $marca['logo_path'] ?: '/crecer/assets/brand/encuentralo-pin.svg';

$active = 'contenido';
$page_title = 'Contenido';
$guia = ['key'=>'contenido','agente'=>'pen','titulo'=>'Tu fábrica de posts',
  'intro'=>'Aquí La Creativa te prepara los posts. Tú apruebas lo que te guste.',
  'pasos'=>[
    ['sparkles','Dale a "Pedir un post a la IA": dile un tema o déjala inventar.'],
    ['eye','En cada post, "Ver en redes" te muestra cómo se vería en IG/FB.'],
    ['calendar','Cambia la fecha si quieres publicarlo otro día.'],
    ['check','Cuando te guste, dale "Aprobar". Editar un post le enseña tu voz a la IA.'],
  ]];
require __DIR__ . '/_shell.php';
?>
<style>
  .feedwrap{max-width:600px}
  .feedwrap .post{margin-top:14px}
  .viewtoggle{display:flex;gap:6px;margin:6px 0 10px}
  .vt{font-weight:700;font-size:13.5px;text-decoration:none;color:var(--muted);padding:8px 16px;border-radius:99px;border:1.5px solid var(--line)}
  .vt.on{color:#fff;background:linear-gradient(135deg,var(--coral),var(--magenta));border-color:transparent}
  .subtabs{display:flex;gap:8px;max-width:600px;margin:14px 0 4px;border-bottom:1.5px solid var(--line);padding-bottom:0}
  .st{display:flex;align-items:center;gap:7px;font-weight:800;font-size:14px;text-decoration:none;color:var(--muted);padding:10px 14px;border-bottom:3px solid transparent;margin-bottom:-1.5px}
  .st.on{color:var(--terracota);border-bottom-color:var(--terracota)}
  .st .b{font-size:11.5px;font-weight:800;background:var(--crema);color:var(--muted);border-radius:99px;padding:1px 9px;min-width:20px;text-align:center}
  .st.on .b{background:var(--terracota);color:#fff}
  .mesnav{max-width:600px;display:flex;align-items:center;gap:10px;margin:14px 0 4px}
  .mesnav select{font-family:inherit;font-size:13.5px;font-weight:700;border:1.5px solid var(--line);border-radius:99px;padding:9px 14px;background:#fff}
  .okbar{max-width:600px;background:var(--okk-bg);color:var(--okk-ink);font-weight:700;font-size:14px;padding:11px 14px;border-radius:12px;margin-top:14px}
  .errbar{max-width:600px;background:var(--noo-bg);color:var(--noo-ink);font-weight:700;font-size:14px;padding:11px 14px;border-radius:12px;margin-top:14px}
  .factorybar{max-width:600px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:16px 0 4px}
  .factorybar .fbform{display:flex;gap:8px;align-items:center}
  .factorybar select{font-family:inherit;font-size:13.5px;font-weight:700;border:1.5px solid var(--line);border-radius:99px;padding:9px 12px;background:#fff}
  .fbgen{border:0;cursor:pointer;font-family:inherit;font-weight:800;font-size:13.5px;color:#fff;background:linear-gradient(135deg,var(--coral),var(--magenta));padding:10px 18px;border-radius:99px}
  .fbnew{border:1.5px solid var(--line);cursor:pointer;font-family:inherit;font-weight:700;font-size:13.5px;color:var(--tinta);background:#fff;padding:10px 18px;border-radius:99px}
  .artwrap{position:relative}
  .artph{width:100%;border:0;border-top:1px dashed var(--line);border-bottom:1px dashed var(--line);background:repeating-linear-gradient(45deg,var(--crema),var(--crema) 10px,#fff 10px,#fff 20px);cursor:pointer;font-family:inherit;font-weight:800;font-size:14px;color:var(--terracota);padding:26px 12px;display:flex;flex-direction:column;align-items:center;gap:6px}
  .artph:hover{color:var(--terracota-700)}
  .checklist{display:flex;gap:8px;flex-wrap:wrap;padding:0 17px 10px}
  .schedrow{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:0 17px 12px;font-size:12.5px;color:var(--muted)}
  .schedrow .ic{width:15px;height:15px;color:var(--terracota);flex:none}
  .schedrow .lab{font-weight:700;color:var(--tinta)}
  .schedrow input[type=date]{font-family:inherit;font-size:12.5px;font-weight:700;border:1.5px solid var(--line);border-radius:8px;padding:5px 9px;background:#fff;color:var(--tinta)}
  .schedrow .hint{font-size:11px}
  .ck-item{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:800;color:var(--muted);background:var(--crema);border:1px solid var(--line);padding:4px 10px;border-radius:99px;opacity:.6}
  .ck-item.on{color:var(--okk-ink);background:var(--okk-bg);border-color:transparent;opacity:1}
  .ckic{width:13px;height:13px;flex:none}
  /* Modal estudio de arte */
  .art-ov{display:none;position:fixed;inset:0;background:rgba(20,12,8,.72);z-index:95;align-items:flex-start;justify-content:center;padding:30px 16px;overflow:auto}
  .art-ov.show{display:flex}
  .art-box{background:var(--card);border-radius:var(--r-xl);max-width:480px;width:100%;padding:22px;position:relative}
  .art-box h3{font-family:var(--font-display);font-weight:800;font-size:20px;margin-bottom:2px}
  .art-box .sub{font-size:13px;color:var(--muted);margin-bottom:6px}
  .art-box .x{position:absolute;top:12px;right:14px;border:0;background:none;font-size:20px;cursor:pointer;color:var(--muted)}
  .art-box .fl{display:block;font-weight:700;font-size:13px;margin:14px 0 7px}
  .art-box .reuse-strip{display:flex;gap:8px;overflow-x:auto;padding:4px 0 8px}
  .art-box .reuse-thumb{width:72px;height:72px;border-radius:12px;object-fit:cover;border:2.5px solid var(--line);cursor:pointer;flex:0 0 auto;transition:border-color .12s,transform .12s}
  .art-box .reuse-thumb:hover{border-color:var(--terracota);transform:scale(1.04)}
  .art-box .picker{display:flex;gap:8px;flex-wrap:wrap}
  .art-box .pk{cursor:pointer}.art-box .pk input{position:absolute;opacity:0}
  .art-box .pk img,.art-box .pk .none{width:64px;height:64px;border-radius:12px;object-fit:cover;border:2.5px solid var(--line);display:block}
  .art-box .pk .none{display:grid;place-items:center;font-size:10.5px;color:var(--muted);text-align:center;background:var(--crema);line-height:1.1}
  .art-box .pk input:checked + img,.art-box .pk input:checked + .none{border-color:var(--terracota)}
  .art-box .chips{display:flex;flex-wrap:wrap;gap:7px}
  .art-box .chip-opt{cursor:pointer}.art-box .chip-opt input{position:absolute;opacity:0}
  .art-box .chip-opt span{display:inline-block;padding:6px 12px;border-radius:99px;border:1.5px solid var(--line);background:#fff;font-weight:700;font-size:12.5px}
  .art-box .chip-opt input:checked + span{border-color:transparent;color:#fff;background:linear-gradient(135deg,var(--coral),var(--magenta))}
  .art-box textarea,.art-box input[type=file]{width:100%;font-family:inherit;font-size:13.5px;border:1.5px solid var(--line);border-radius:12px;padding:9px 11px}
  .art-box .ck{display:flex;align-items:center;gap:7px;font-weight:700;font-size:13.5px}
  .art-go{width:100%;border:0;cursor:pointer;font-family:inherit;font-weight:800;font-size:15px;color:#fff;background:linear-gradient(135deg,var(--coral),var(--magenta));padding:13px;border-radius:99px;margin-top:18px}
  .art-go:disabled{opacity:.6;cursor:default}
  .art-skip{display:block;text-align:center;margin-top:12px;font-size:13px;font-weight:700;color:var(--muted);text-decoration:none}
  .art-note{font-size:11.5px;color:var(--muted);margin-top:10px;text-align:center}
  .art-divider{display:flex;align-items:center;gap:10px;margin:18px 0 4px;color:var(--muted);font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
  .art-divider::before,.art-divider::after{content:"";flex:1;height:1px;background:var(--line)}
  /* Encabezado liderado por La Creativa (look del dashboard) */
  .cfhead{display:flex;gap:16px;align-items:center;max-width:600px;border-radius:var(--r-lg);padding:18px 20px;
    background:linear-gradient(180deg,color-mix(in srgb,#ff2d6f 9%,#fff),var(--card));
    border:1.5px solid color-mix(in srgb,#ff2d6f 26%,#fff);box-shadow:0 16px 34px -22px rgba(255,45,111,.55)}
  .cf-orb{width:72px;height:72px;border-radius:50%;flex:none;display:grid;place-items:center;
    background:radial-gradient(circle,#fff 0 42%,color-mix(in srgb,#ff2d6f 14%,#fff));
    box-shadow:inset 0 0 0 2px color-mix(in srgb,#ff2d6f 12%,#fff),0 0 0 1px color-mix(in srgb,#ff2d6f 20%,#fff)}
  .cf-orb img{width:48px;height:48px}
  .cf-top{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
  .cf-top h1{font-family:var(--font-impact);text-transform:uppercase;font-size:clamp(26px,5vw,36px);margin:0;line-height:1;letter-spacing:.02em}
  .cf-pill{font-weight:900;font-size:11px;letter-spacing:.04em;text-transform:uppercase;padding:6px 12px;border-radius:999px;
    background:color-mix(in srgb,#ff2d6f 16%,#fff);color:color-mix(in srgb,#ff2d6f 80%,#000);border:1px solid color-mix(in srgb,#ff2d6f 30%,#fff)}
  .cfhead p{margin:6px 0 0;color:var(--muted);font-size:14.5px}
  @media(max-width:520px){.cf-orb{width:56px;height:56px}.cf-orb img{width:36px;height:36px}}
  /* Preview "cómo se ve en redes" (igual que Gráficas) */
  .prev-ov{display:none;position:fixed;inset:0;background:rgba(20,12,8,.7);z-index:96;align-items:flex-start;justify-content:center;padding:28px 16px;overflow:auto}
  .prev-ov.show{display:flex}
  .prev-box{background:var(--crema);border-radius:var(--r-xl);padding:18px;max-width:420px;width:100%;position:relative}
  .prev-x{position:absolute;top:12px;right:14px;border:0;background:none;font-size:20px;cursor:pointer;color:var(--muted)}
  .prev-tabs{display:flex;gap:8px;justify-content:center;margin-bottom:14px}
  .ptab{display:inline-flex;align-items:center;gap:6px;border:1.5px solid var(--line);background:#fff;font-family:inherit;font-weight:700;font-size:13px;cursor:pointer;border-radius:99px;padding:7px 14px}
  .ptab.on{border-color:transparent;color:#fff;background:linear-gradient(135deg,var(--coral),var(--magenta))}
  .mock{background:#fff;border:1px solid var(--line);border-radius:14px;overflow:hidden;max-width:360px;margin:0 auto;box-shadow:var(--shadow);color:#111}
  .mock .av{width:34px;height:34px;border-radius:50%;object-fit:cover;background:#eee}
  .mock .post-img{width:100%;display:block}
  .ig-head{display:flex;align-items:center;gap:9px;padding:10px 12px;font-size:14px}
  .ig-head .dots{margin-left:auto;color:#888}
  .ig-acts{display:flex;gap:14px;padding:10px 12px 4px;font-size:20px}
  .ig-acts .sp{flex:1}
  .ig-likes{padding:0 12px;font-size:13px;font-weight:700}
  .ig-cap{padding:4px 12px 14px;font-size:13.5px;line-height:1.4}
  .fb-head{display:flex;align-items:center;gap:9px;padding:12px}
  .fb-meta{font-size:12px;color:#888}
  .fb-text{padding:0 12px 10px;font-size:14px;line-height:1.4;white-space:pre-wrap}
  .fb-bar{display:flex;justify-content:space-around;padding:10px;border-top:1px solid #eee;font-size:13px;color:#555}
  .prev-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:center;margin-top:16px}
  .pa{border:1.5px solid var(--line);background:#fff;color:var(--tinta);font-family:inherit;font-weight:700;font-size:13px;cursor:pointer;border-radius:99px;padding:9px 14px;text-decoration:none}
  .prev-note{font-size:11.5px;color:var(--muted);text-align:center;margin-top:12px}
</style>

<?php if ($es_hub):
  $CX = '/crecer/assets/crecer-contenido';
  $url = fn($t) => "/crecer/panel/aprobar2.php?marca={$marca_id}".($t?"&tab={$t}":"");
?>
<style>
  .cux{max-width:1080px}
  .cux-hero{position:relative;display:flex;align-items:center;gap:18px;flex-wrap:wrap;margin:4px 0 0;padding:8px 0 0}
  .cux-hero-copy{flex:1 1 320px;min-width:0;position:relative;z-index:3}
  .cux-agent{text-transform:uppercase;color:var(--terracota);font-weight:900;font-size:15px;letter-spacing:.06em;margin-bottom:8px}
  .cux-h1{font-family:var(--font-impact);text-transform:uppercase;font-size:clamp(38px,5.4vw,62px);line-height:.9;letter-spacing:.01em;margin:0;color:var(--tinta)}
  .cux-h1 .g{background:linear-gradient(120deg,var(--coral),var(--magenta));-webkit-background-clip:text;background-clip:text;color:transparent}
  .cux-hb{display:block;width:min(380px,82%);margin:2px 0 0;filter:drop-shadow(0 8px 14px rgba(192,57,95,.18))}
  .cux-hero-copy p{font-size:16px;line-height:1.5;color:var(--muted);max-width:44ch;margin-top:14px}
  .cux-vis{flex:0 0 auto;position:relative;width:230px;align-self:flex-end}
  .cux-vis .bbg{position:absolute;left:-26px;right:-26px;bottom:-10px;top:-26px;width:auto;height:auto;z-index:1;opacity:.5;object-fit:contain;pointer-events:none}
  .cux-vis .crea{position:relative;z-index:2;width:100%;display:block;pointer-events:none;filter:drop-shadow(0 20px 26px rgba(40,20,20,.16))}
  .cux-vis .crown{position:absolute;top:-8px;left:-8px;width:58px;z-index:3;opacity:.85;pointer-events:none}
  .cux-need{flex:0 0 280px;position:relative;z-index:3;background:var(--card);border:1px solid var(--line);border-radius:24px;box-shadow:var(--shadow);padding:24px}
  .cux-need h3{font-family:var(--font-impact);text-transform:uppercase;font-size:23px;margin:0 0 9px;color:var(--tinta);letter-spacing:.01em}
  .cux-need p{font-size:14px;color:var(--muted);margin:0 0 18px}
  .cux-need button{width:100%;border:0;border-radius:14px;background:linear-gradient(120deg,var(--coral),var(--magenta));color:#fff;font-weight:800;font-size:15px;padding:14px;cursor:pointer;font-family:inherit;box-shadow:0 12px 24px -10px rgba(192,57,95,.5)}

  .cux-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-top:26px}
  .cux-stat{background:var(--card);border:1px solid var(--line);border-radius:18px;box-shadow:var(--shadow-sm);padding:20px 22px;text-decoration:none;color:inherit;transition:transform .18s,box-shadow .18s}
  .cux-stat:hover{transform:translateY(-4px);box-shadow:0 18px 36px -18px rgba(40,28,12,.3)}
  .cux-si{width:48px;height:48px;border-radius:50%;display:grid;place-items:center;font-size:22px;font-weight:900;margin-bottom:12px}
  .cux-si.yel{background:#fff0bd;color:#c98a00}.cux-si.pnk{background:color-mix(in srgb,var(--terracota) 16%,#fff);color:var(--terracota)}.cux-si.cya{background:#d9fbfd;color:var(--teal)}
  .cux-stat strong{font-family:var(--font-impact);font-size:44px;line-height:1;color:var(--tinta)}
  .cux-stat h4{text-transform:uppercase;font-size:13px;letter-spacing:.03em;margin:6px 0 4px;color:var(--tinta)}
  .cux-stat .sub{color:var(--muted);font-size:13px;margin:0 0 12px}
  .cux-stat .lk{color:var(--terracota);font-weight:800;font-size:13.5px}
  .cux-stat .lk.cya{color:var(--teal)}

  .cux-quick{margin-top:24px;background:var(--card);border:1px solid var(--line);border-radius:22px;box-shadow:var(--shadow-sm);padding:22px 24px}
  .cux-quick h3{font-family:var(--font-impact);text-transform:uppercase;font-size:18px;letter-spacing:.02em;margin:0 0 16px;color:var(--tinta)}
  .cux-qgrid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
  .cux-qgrid button{display:flex;align-items:center;gap:12px;text-align:left;border:1px solid var(--line);background:#fff;border-radius:14px;padding:14px 16px;font-family:inherit;font-weight:800;font-size:14px;color:var(--tinta);cursor:pointer;box-shadow:0 8px 22px -14px rgba(40,25,12,.35)}
  .cux-qgrid button:hover{border-color:var(--terracota)}
  .cux-qgrid .q{width:42px;height:42px;border-radius:50%;flex:none;display:grid;place-items:center;color:#fff}
  .cux-qgrid .q svg{width:20px;height:20px}
  .q.blue{background:#3478f6}.q.insta{background:linear-gradient(135deg,#7952ff,#c0395f,#ffc44d)}.q.pnk{background:var(--magenta)}.q.cya{background:var(--teal)}.q.yel{background:#ffc44d;color:#8a5d00}.q.tea{background:var(--palma)}

  .cux-lower{margin-top:24px;display:grid;grid-template-columns:1.4fr 1.2fr .95fr;gap:18px}
  .cux-card{background:var(--card);border:1px solid var(--line);border-radius:22px;box-shadow:var(--shadow-sm);padding:22px 24px}
  .cux-card h3{font-family:var(--font-impact);text-transform:uppercase;font-size:17px;letter-spacing:.02em;margin:0 0 16px;color:var(--tinta)}
  .cux-soft{color:var(--muted);font-size:14px;line-height:1.5}
  .cux-tl{position:relative}
  .cux-it{display:flex;gap:12px;align-items:flex-start;padding:9px 0;border-bottom:1px solid var(--line)}
  .cux-it:last-child{border-bottom:0}
  .cux-it img{width:38px;height:38px;border-radius:50%;flex:none;background:var(--crema);border:2px solid #fff;box-shadow:0 6px 14px rgba(0,0,0,.07);padding:5px}
  .cux-it p{margin:0;font-size:14px;color:#3d374b;line-height:1.4}
  .cux-it small{display:block;color:var(--muted);margin-top:2px;font-size:11.5px}
  .cux-more{display:inline-block;margin-top:14px;color:var(--terracota);font-weight:800;font-size:13.5px;text-decoration:none}
  .cux-done{background:linear-gradient(135deg,var(--terracota),var(--magenta));color:#fff;position:relative;overflow:hidden}
  .cux-done h2{font-family:var(--font-impact);text-transform:uppercase;font-size:30px;margin:0 0 12px;letter-spacing:.01em}
  .cux-done p{font-size:15.5px;line-height:1.45;opacity:.95;margin:0;max-width:34ch}
  .cux-badge{position:absolute;right:-10px;top:-6px;width:120px;opacity:.9;pointer-events:none}
  .cux-done-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:22px}
  .cux-done-actions a,.cux-done-actions button{border:0;cursor:pointer;font-family:inherit;background:#fff;color:var(--terracota);font-weight:800;font-size:13.5px;border-radius:12px;padding:11px 16px;text-decoration:none}
  .cux-idea{background:#fff;border:1px solid var(--line);border-radius:14px;padding:13px 15px;margin-bottom:10px}
  .cux-idea span{display:block;font-weight:700;color:var(--tinta);font-size:13.5px;line-height:1.35}
  .cux-idea em{display:inline-block;margin-top:8px;font-style:normal;background:#fff0bd;color:#c18400;border-radius:999px;padding:3px 10px;font-size:11px;font-weight:800}
  .cux-ideas a{color:var(--terracota);font-weight:800;font-size:13.5px;text-decoration:none}
  @media(max-width:1100px){.cux-lower{grid-template-columns:1fr}.cux-qgrid{grid-template-columns:repeat(2,1fr)}}
  @media(max-width:720px){.cux-stats{grid-template-columns:1fr}.cux-qgrid{grid-template-columns:1fr}.cux-vis{order:-1;width:200px;margin:0 auto}.cux-need{flex:1 1 100%}}
</style>

<div class="cux">
  <section class="cux-hero">
    <div class="cux-hero-copy">
      <div class="cux-agent">La Creativa</div>
      <h1 class="cux-h1">Tu contenido,<br><span class="g">en buenas manos</span></h1>
      <img class="cux-hb" src="<?= $CX ?>/headline_brush.png" alt="">
      <p>La Creativa está ideando, escribiendo y preparando contenido pa' hacer crecer tu negocio.</p>
    </div>
    <div class="cux-vis">
      <img class="bbg" src="<?= $CX ?>/hero_pink_brush.png" alt="">
      <img class="crown" src="<?= $CX ?>/tiny_crown.png" alt="">
      <img class="crea" src="<?= $CX ?>/creativa_character.png" alt="La Creativa">
    </div>
    <div class="cux-need">
      <h3>¿Qué necesitas hoy?</h3>
      <p>Pídele a La Creativa lo que tu negocio necesita.</p>
      <button type="button" onclick="abrirBrief()">＋ Pedir contenido</button>
    </div>
  </section>

  <section class="cux-stats">
    <a class="cux-stat" href="<?= $url('pendientes') ?>"><div class="cux-si yel"><?= ico('clock') ?></div><strong><?= $n_pend ?></strong><h4>Esperando tu OK</h4><p class="sub">Pendientes de aprobar</p><span class="lk">Ver por aprobar →</span></a>
    <a class="cux-stat" href="<?= $url('aprobados') ?>"><div class="cux-si pnk"><?= ico('check-circle') ?></div><strong><?= (int)$cnt['aprobado'] ?></strong><h4>Listos para publicar</h4><p class="sub">Aprobados</p><span class="lk">Ver aprobados →</span></a>
    <a class="cux-stat" href="/crecer/panel/analitica.php?marca=<?= $marca_id ?>"><div class="cux-si cya"><?= ico('chart') ?></div><strong><?= $publicados_mes ?></strong><h4>Publicados</h4><p class="sub">Este mes</p><span class="lk cya">Ver analítica →</span></a>
  </section>

  <section class="cux-quick">
    <h3>¿Qué quieres que haga La Creativa hoy?</h3>
    <div class="cux-qgrid">
      <button type="button" onclick="abrirBriefPreset('facebook')"><span class="q blue"><?= ico('facebook') ?></span>Post de Facebook</button>
      <button type="button" onclick="abrirBriefPreset('instagram')"><span class="q insta"><?= ico('instagram') ?></span>Historia de Instagram</button>
      <button type="button" onclick="abrirBriefPreset('promo')"><span class="q pnk"><?= ico('gift') ?></span>Promoción u oferta</button>
      <button type="button" onclick="abrirBriefPreset('anuncio')"><span class="q cya"><?= ico('bolt') ?></span>Anuncio pagado</button>
      <button type="button" onclick="abrirBriefPreset('idea')"><span class="q yel"><?= ico('lightbulb') ?></span>Idea para mi negocio</button>
      <button type="button" onclick="abrirBriefPreset('otro')"><span class="q tea"><?= ico('sparkles') ?></span>Otro contenido</button>
    </div>
  </section>

  <section class="cux-lower">
    <article class="cux-card">
      <h3>Actividad reciente del corillo</h3>
      <?php if (!$timeline): ?>
        <p class="cux-soft">Todavía no hay actividad. Pídele algo a La Creativa y aquí verás lo que hace el corillo. 👇</p>
      <?php else: ?>
        <div class="cux-tl">
          <?php foreach ($timeline as $tlx): [$tic,$tnm,$tmsg] = $feed_map[$tlx['agente']] ?? ['bolt','El Corillo','metió mano']; ?>
            <div class="cux-it"><img src="/crecer/assets/icons/<?= $h($tic) ?>.svg" alt=""><p><strong><?= $h($tnm) ?></strong> <?= $h($tmsg) ?><small><?= date('g:i A', strtotime($tlx['created_at'])) ?></small></p></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <a class="cux-more" href="/crecer/panel/evidencia.php?marca=<?= $marca_id ?>">Ver toda la actividad →</a>
    </article>

    <article class="cux-card cux-done">
      <img class="cux-badge" src="<?= $CX ?>/todo_al_dia_badge.png" alt="">
      <?php if ($n_pend == 0): ?>
        <h2>¡Todo al día! 🔥</h2>
        <p>No tienes nada pendiente de aprobar. La Creativa y el corillo están trabajando en lo próximo.</p>
        <div class="cux-done-actions"><a href="<?= $url('aprobados') ?>">↗ Ver aprobados</a><button type="button" onclick="abrirBrief()">＋ Pedir más</button></div>
      <?php else: ?>
        <h2><?= $n_pend ?> esperan tu OK</h2>
        <p>La Creativa te dejó contenido listo. Apruébalo cuando quieras desde el celular.</p>
        <div class="cux-done-actions"><a href="<?= $url('pendientes') ?>">Revisar y aprobar →</a></div>
      <?php endif; ?>
    </article>

    <article class="cux-card cux-ideas">
      <h3>Ideas en proceso</h3>
      <?php if (!$ideas): ?>
        <p class="cux-soft">Aún no hay ideas en borrador. Pídele a La Creativa y empiezan a aparecer.</p>
      <?php else: foreach ($ideas as $idx): $txt = trim((string)$idx['caption']); ?>
        <div class="cux-idea"><span><?= $h($txt !== '' ? mb_strimwidth($txt,0,54,'…') : 'Borrador sin título') ?></span><em>Borrador</em></div>
      <?php endforeach; endif; ?>
      <a href="<?= $url('pendientes') ?>">Ver todas →</a>
    </article>
  </section>
</div>

<script>
  function abrirBriefPreset(tipo){
    var f=document.getElementById('briefform'); if(!f){ if(window.abrirBrief)abrirBrief(); return; }
    var chks=f.querySelectorAll('input[name="plataformas[]"]'), tema=f.querySelector('textarea[name="tema"]');
    if(tipo==='facebook'||tipo==='instagram'){ chks.forEach(function(c){c.checked=(c.value===tipo);}); }
    else { chks.forEach(function(c){c.checked=true;}); }
    if(tema){ var t={promo:'Una promoción u oferta especial',anuncio:'Un anuncio para atraer clientes nuevos'}[tipo]; tema.value=t||''; }
    abrirBrief();
  }
</script>
<?php else: ?>
<div class="cfhead">
  <div class="cf-orb"><img src="/crecer/assets/icons/creativa.svg" alt="La Creativa"></div>
  <div class="cf-body">
    <div class="cf-top"><h1>Contenido</h1><span class="cf-pill">La Creativa · Ideando</span></div>
    <p>Te preparé estos posts en tu voz. Aprueba lo que te guste — tú tienes la última palabra.</p>
  </div>
</div>
<div class="viewtoggle">
  <a class="vt on" href="/crecer/panel/aprobar2.php?marca=<?= $marca_id ?>"><?= ico('list') ?> Lista</a>
  <a class="vt" href="/crecer/panel/calendario.php?marca=<?= $marca_id ?>"><?= ico('calendar') ?> Calendario</a>
</div>
<p style="font-size:12.5px;color:var(--muted);margin-top:6px;max-width:600px"><b style="color:var(--amber-ink)">Pendiente</b> = esperando tu OK · <b style="color:var(--okk-ink)">Aprobado</b> = listo. Edita un post y la IA <b>aprende tu vocabulario</b>.</p>

<?php if (!empty($_GET['generados'])): ?><div class="okbar">✨ La IA redactó <?= (int)$_GET['generados'] ?> post(s) — ya quedaron programados en tu calendario. <a href="/crecer/panel/calendario.php?marca=<?= $marca_id ?>" style="color:var(--okk-ink);font-weight:800;text-decoration:underline">Ver en el calendario →</a></div><?php endif; ?>
<?php if (!empty($_GET['err'])): ?><div class="errbar">⚠️ No se pudo generar (<?= $h($_GET['err']) ?>). Intenta de nuevo en un minuto.</div><?php endif; ?>

<div class="subtabs">
  <a class="st <?= $tab==='pendientes'?'on':'' ?>" href="/crecer/panel/aprobar2.php?marca=<?= $marca_id ?>&tab=pendientes"><?= ico('clock') ?> Por aprobar <span class="b" id="cnt-pend"><?= $n_pend ?></span></a>
  <a class="st <?= $tab==='aprobados'?'on':'' ?>" href="/crecer/panel/aprobar2.php?marca=<?= $marca_id ?>&tab=aprobados"><?= ico('check-circle') ?> Aprobados <span class="b" id="cnt-aprob"><?= $n_aprob ?></span></a>
</div>

<?php if ($tab === 'pendientes'): ?>
  <div class="factorybar">
    <button type="button" class="fbgen" onclick="abrirBrief()"><?= ico('sparkles') ?> Pedir un post a la IA</button>
    <form method="post" onsubmit="var b=this.querySelector('button');b.disabled=true;">
      <input type="hidden" name="accion" value="nuevo_manual">
      <button type="submit" class="fbnew"><?= ico('plus') ?> Escribir uno yo (sin IA)</button>
    </form>
  </div>
<?php elseif ($meses_aprob): ?>
  <form method="get" class="mesnav">
    <input type="hidden" name="marca" value="<?= $marca_id ?>">
    <input type="hidden" name="tab" value="aprobados">
    <label style="font-weight:700;font-size:13.5px;color:var(--muted);display:inline-flex;align-items:center;gap:6px"><?= ico('calendar') ?> Archivo por mes:</label>
    <select name="mes" onchange="this.form.submit()">
      <?php foreach ($meses_aprob as $m): ?>
        <option value="<?= $h($m['ym']) ?>" <?= $m['ym']===$mes_sel?'selected':'' ?>><?= $h($nombre_mes($m['ym'])) ?> (<?= (int)$m['n'] ?>)</option>
      <?php endforeach; ?>
    </select>
  </form>
<?php endif; ?>

<div class="feedwrap">
  <?php if (!$total && $tab==='pendientes' && $n_aprob==0): ?>
    <div class="empty">
      <div class="big"><img src="/crecer/assets/icons/corillo-listo.svg" alt="" style="width:58px;height:58px"></div>
      <p style="margin-bottom:18px">Todavía no le has dado trabajo al corillo. Dale abajo y metemos mano.</p>
      <form method="post" action="/crecer/panel/generar.php"
            onsubmit="var b=this.querySelector('button');b.textContent='✨ Creando tu mes…';b.disabled=true;">
        <input type="hidden" name="marca" value="<?= $marca_id ?>">
        <button type="submit" style="border:0;cursor:pointer;font-family:inherit;font-weight:800;font-size:15px;color:#fff;background:linear-gradient(135deg,var(--coral),var(--magenta));padding:15px 26px;border-radius:99px;box-shadow:0 12px 28px rgba(255,43,133,.3)">Que la IA prepare mi primer mes</button>
      </form>
      <p style="color:var(--muted);font-size:12.5px;margin-top:12px">Tarda un minutito — la IA está creando tu contenido.</p>
    </div>
  <?php elseif (!$total && $tab==='pendientes'): ?>
    <div class="empty"><div class="big"><img src="/crecer/assets/icons/aprobar.svg" alt="" style="width:54px;height:54px"></div><p>¡Todo al día! No tienes posts pendientes por aprobar.<br><a href="/crecer/panel/aprobar2.php?marca=<?= $marca_id ?>&tab=aprobados" style="color:var(--terracota);font-weight:800">Ver tus <?= $n_aprob ?> aprobados →</a></p></div>
  <?php elseif (!$total): ?>
    <div class="empty"><div class="big"><?= ico('inbox-empty') ?></div><p>Aquí caen los posts que apruebes<?= $meses_aprob ? ' este mes' : '' ?>. Aprueba lo que te guste y aparecen aquí.</p></div>
  <?php endif; ?>

  <?php foreach ($piezas as $p):
    [$pl_label,$pl_cls] = $plat[$p['plataforma']] ?? [ucfirst($p['plataforma']),''];
    [$pi_label,$pi_cls] = $pill[$p['estado']] ?? ['—','wait'];
    $done = in_array($p['estado'],['aprobado','rechazado','publicado'],true);
    $fecha = date('d/m', strtotime($p['fecha_programada'] ?: 'now'));
  ?>
    <?php $has_cap = trim($p['caption'])!==''; $has_art = !empty($p['grafica_path']); $is_ok = in_array($p['estado'],['aprobado','publicado'],true); ?>
    <article class="post" data-id="<?= $p['id'] ?>" data-img="<?= $has_art?'1':'' ?>" data-intentos="<?= max(0, CRECER_IMG_POST - (int)($p['arte_intentos'] ?? 0)) ?>">
      <div class="post-head">
        <span class="chip <?= $pl_cls ?>"><span class="ico"></span><?= $h($pl_label) ?></span>
        <span class="chip"><?= $h($p['tipo']) ?></span>
        <span class="pill <?= $pi_cls ?>"><?= $pi_label ?></span>
        <span class="date"><?= $fecha ?></span>
      </div>
      <div class="artwrap" id="art-<?= $p['id'] ?>">
        <?php if ($has_art): ?>
          <img class="zoomable" src="<?= $h($p['grafica_path']) ?>" alt="arte" style="width:100%;display:block">
        <?php else: ?>
          <button type="button" class="artph artbtn" data-id="<?= $p['id'] ?>">
            <span><?= ico('image','ic-lg') ?></span><span>Crear el arte de este post</span>
          </button>
        <?php endif; ?>
      </div>
      <div class="caption" id="cap-<?= $p['id'] ?>"><?= $h($p['caption']) ?: '<span style="color:var(--muted)">Sin texto todavía — toca «Editar» o pídele a la IA que lo escriba.</span>' ?></div>
      <div class="checklist" id="chk-<?= $p['id'] ?>">
        <span class="ck-item <?= $has_cap?'on':'' ?>" data-k="cap"><?= ico('pen','ckic') ?> Copy</span>
        <span class="ck-item <?= $has_art?'on':'' ?>" data-k="art"><?= ico('image','ckic') ?> Arte</span>
        <span class="ck-item <?= $is_ok?'on':'' ?>" data-k="ok"><?= ico('check','ckic') ?> Aprobado</span>
      </div>
      <div class="schedrow">
        <?= ico('calendar') ?>
        <span class="lab">El corillo lo programó para</span>
        <input type="date" class="fecha-in" data-id="<?= $p['id'] ?>" value="<?= date('Y-m-d', strtotime($p['fecha_programada'] ?: 'now')) ?>">
        <span class="hint">cámbialo si quieres (ej. un día especial)</span>
      </div>
      <div class="toolrow" id="tools-<?= $p['id'] ?>" style="padding:0 17px 12px;display:flex;gap:16px;flex-wrap:wrap;font-size:13px">
        <a href="#" class="editlink" data-id="<?= $p['id'] ?>" style="font-weight:700;color:var(--terracota);text-decoration:none"><?= ico('edit') ?> Editar</a>
        <a href="#" class="artbtn" data-id="<?= $p['id'] ?>" style="font-weight:700;color:var(--terracota);text-decoration:none"><?= ico('image') ?> <?= $has_art ? 'Cambiar arte' : 'Crear arte' ?></a>
        <a href="#" class="regenlink" data-id="<?= $p['id'] ?>" style="font-weight:700;color:var(--muted);text-decoration:none"><?= ico('refresh') ?> Regenerar texto</a>
        <?php if ($has_art): ?><a href="#" class="prevlink" data-img="<?= $h($p['grafica_path']) ?>" data-copy="<?= $h($p['caption']) ?>" style="font-weight:700;color:var(--teal);text-decoration:none"><?= ico('eye') ?> Ver en redes</a><?php endif; ?>
      </div>
      <form class="editform" data-id="<?= $p['id'] ?>" style="display:none;padding:0 17px 14px">
        <textarea name="caption" style="width:100%;font-family:inherit;font-size:14px;color:var(--tinta);border:1.5px solid var(--line);border-radius:12px;padding:11px 13px;min-height:96px"><?= $h($p['caption']) ?></textarea>
        <div style="font-size:11.5px;color:var(--muted);margin:6px 0;display:flex;align-items:center;gap:6px"><?= ico('lightbulb') ?> Corrige el vocabulario y la IA aprende para los próximos posts.</div>
        <div style="display:flex;gap:8px">
          <button type="submit" style="border:0;cursor:pointer;font-family:inherit;font-weight:800;font-size:13px;color:#fff;background:var(--palma);padding:9px 18px;border-radius:99px">Guardar</button>
          <button type="button" class="cancel" style="border:1.5px solid var(--line);cursor:pointer;font-family:inherit;font-weight:700;font-size:13px;background:#fff;color:var(--muted);padding:9px 16px;border-radius:99px">Cancelar</button>
        </div>
      </form>
      <div class="post-actions">
        <?php if ($p['estado']==='borrador'): ?>
          <form method="post"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="btn btn-ok" name="accion" value="aprobar">✓ Aprobar</button></form>
          <form method="post"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="btn btn-no" name="accion" value="rechazar">Rechazar</button></form>
        <?php else: ?>
          <button type="button" class="btn btn-ok publicarbtn">📲 Publicar</button>
          <form method="post"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="btn btn-ghost" name="accion" value="reabrir">↺ Volver a revisar</button></form>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
</div>
<?php endif; /* fin vistas: hub | fábrica */ ?>

<!-- MODAL: PEDIR UN POST A LA IA (brief del dueño) -->
<div class="art-ov" id="briefov">
  <form class="art-box" method="post" id="briefform" onsubmit="var b=this.querySelector('.art-go');b.textContent='✨ Redactando… (~10s)';b.disabled=true;">
    <button type="button" class="x" onclick="document.getElementById('briefov').classList.remove('show')">✕</button>
    <h3><?= ico('sparkles') ?> Pedir un post a la IA</h3>
    <div class="sub">Sugiere el tema, o escribe un borrador y la IA lo pule respetando tu intención. Déjalo todo en blanco y la IA inventa.</div>
    <input type="hidden" name="accion" value="pedir_post">

    <label class="fl">¿De qué quieres el post? <span style="color:var(--muted);font-weight:500">(opcional)</span></label>
    <textarea name="tema" rows="2" placeholder="Ej: promo del bizcocho de guayaba para el Día de las Madres"></textarea>

    <label class="fl">¿Tienes un borrador? La IA lo mejora <span style="color:var(--muted);font-weight:500">(opcional)</span></label>
    <textarea name="borrador" rows="3" placeholder="Escríbelo como te salga; la IA lo pule manteniendo tu intención y tus datos (precios, fechas)."></textarea>

    <label class="fl">Plataformas <span style="color:var(--muted);font-weight:500">(elige todas las que quieras — se crea un post adaptado a cada una)</span></label>
    <div class="chips">
      <label class="chip-opt"><input type="checkbox" name="plataformas[]" value="instagram" checked><span><?= ico('instagram') ?> Instagram</span></label>
      <label class="chip-opt"><input type="checkbox" name="plataformas[]" value="facebook" checked><span><?= ico('facebook') ?> Facebook</span></label>
      <label class="chip-opt"><input type="checkbox" name="plataformas[]" value="whatsapp" checked><span><img src="/crecer/assets/icons/whatsapp.svg" alt="" style="width:18px;height:18px;vertical-align:-.25em"> WhatsApp (Estado)</span></label>
    </div>

    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:4px">
      <div style="flex:1;min-width:140px">
        <label class="fl">Fecha del post</label>
        <input type="date" name="fecha" value="<?= date('Y-m-d') ?>" style="width:100%;font-family:inherit;font-size:13.5px;border:1.5px solid var(--line);border-radius:12px;padding:9px 11px">
      </div>
      <div style="flex:1;min-width:140px">
        <label class="fl">Si dejas todo vacío…</label>
        <select name="n" style="width:100%;font-family:inherit;font-size:13.5px;font-weight:700;border:1.5px solid var(--line);border-radius:12px;padding:9px 11px;background:#fff">
          <option value="1">la IA inventa 1</option>
          <option value="3" selected>la IA inventa 3</option>
          <option value="6">la IA inventa 6</option>
        </select>
      </div>
    </div>

    <button type="submit" class="art-go">Redactar</button>
    <div class="art-note">La IA usa el perfil de tu negocio y el vocabulario que le has enseñado.</div>
  </form>
</div>

<!-- MODAL: ESTUDIO DE ARTE (fábrica de posts) -->
<div class="art-ov" id="artov">
  <form class="art-box" id="artform" enctype="multipart/form-data">
    <button type="button" class="x" onclick="cerrarArte()">✕</button>
    <h3><?= ico('image') ?> Arte del post</h3>
    <div class="sub" id="art-copyprev">La imagen irá acorde a tu copy.</div>
    <input type="hidden" name="accion" value="arte">
    <input type="hidden" name="id" id="art-id" value="">

    <?php if ($graficas): ?>
    <label class="fl" style="margin-top:8px;display:inline-flex;align-items:center;gap:6px"><?= ico('refresh') ?> Reusar un arte que ya creaste <span style="color:var(--muted);font-weight:500">(tócalo para usarlo — no gasta del límite)</span></label>
    <div class="reuse-strip">
      <?php foreach ($graficas as $g): ?>
        <img class="reuse-thumb" src="<?= $h($g['archivo']) ?>" data-gid="<?= (int)$g['id'] ?>" alt="arte previo" title="Usar este arte">
      <?php endforeach; ?>
    </div>
    <div style="text-align:center;font-size:12px;color:var(--muted);margin:2px 0 4px">— o crea uno nuevo abajo —</div>
    <?php endif; ?>

    <label class="fl">Foto base <span style="color:var(--muted);font-weight:500">(real de tu negocio)</span></label>
    <div class="picker">
      <?php foreach ($fotos as $i=>$fn): ?>
        <label class="pk"><input type="radio" name="foto" value="<?= $h($fn) ?>" <?= $i===0?'checked':'' ?>><img src="<?= $h($url_fotos.'/'.$fn) ?>" alt=""></label>
      <?php endforeach; ?>
      <label class="pk"><input type="radio" name="foto" value="" <?= !$fotos?'checked':'' ?>><span class="none">Sin foto<br>(generar)</span></label>
    </div>
    <label class="fl">…o sube una foto nueva ahora</label>
    <input type="file" name="foto_nueva" accept="image/png,image/jpeg,image/webp">

    <label class="fl">¿Texto sobre la imagen?</label>
    <div class="chips">
      <label class="chip-opt"><input type="radio" name="con_texto" value="0" checked><span>Solo mejorar la foto</span></label>
      <label class="chip-opt"><input type="radio" name="con_texto" value="1"><span>Con texto (gancho)</span></label>
    </div>

    <label class="fl">Estilo</label>
    <div class="chips">
      <?php foreach (['Auto'=>'', 'Boricua'=>'boricua, alegre', 'Elegante'=>'elegante y premium', 'Minimalista'=>'minimalista y limpio', 'Vibrante'=>'colores vibrantes', 'Apetitoso'=>'apetitoso, food photography'] as $lb=>$val): ?>
        <label class="chip-opt"><input type="radio" name="estilo" value="<?= $h($val) ?>" <?= $lb==='Auto'?'checked':'' ?>><span><?= $h($lb) ?></span></label>
      <?php endforeach; ?>
    </div>

    <label class="fl">Instrucciones a la IA <span style="color:var(--muted);font-weight:500">(opcional)</span></label>
    <textarea name="instrucciones" rows="2" placeholder='Ej. "ponlo sobre mesa de madera", "añade confeti", "estilo navideño"…'></textarea>

    <?php if ($tiene_logo): ?>
    <label class="ck" style="margin-top:14px"><input type="checkbox" name="con_logo" value="1" id="art-logo"> Incluir mi logo</label>
    <div id="art-logoest" style="display:none;margin-top:8px">
      <div class="chips">
        <label class="chip-opt"><input type="radio" name="logo_estilo" value="watermark" checked><span>Marca de agua</span></label>
        <label class="chip-opt"><input type="radio" name="logo_estilo" value="esquina"><span>En la esquina</span></label>
        <label class="chip-opt"><input type="radio" name="logo_estilo" value="integrado"><span>Integrado</span></label>
      </div>
    </div>
    <?php endif; ?>

    <div id="art-postnote" style="font-size:12px;font-weight:700;margin-top:14px;text-align:center"></div>
    <button type="submit" class="art-go" id="art-go">Crear el arte (~15s)</button>
    <a href="#" class="art-skip" id="art-skip" style="display:none">Aprobar solo con el texto (sin imagen) →</a>
    <div class="art-note">📅 Te quedan <b id="art-rest" style="color:var(--terracota)"><?= $restantes_sem ?></b> de <?= CRECER_IMG_SEMANA ?> generaciones esta semana<?php if($reset_fecha): ?> · se recargan el <span id="art-reset"><?= $h($reset_fecha) ?></span><?php endif; ?>. Con texto = modelo Pro.</div>

    <div class="art-divider"><span>o usa lo tuyo</span></div>
    <label class="fl" style="margin-top:0;display:inline-flex;align-items:center;gap:6px"><?= ico('paperclip') ?> Subir mi propia imagen tal cual <span style="color:var(--muted);font-weight:500">(sin IA, sin gastar límite)</span></label>
    <div style="display:flex;gap:8px;align-items:center">
      <input type="file" id="art-directa-file" accept="image/png,image/jpeg,image/webp" style="flex:1">
      <button type="button" class="fbnew" id="art-directa-btn" style="white-space:nowrap">Usar esta</button>
    </div>
  </form>
</div>

<!-- MODAL PREVIEW REDES (cómo se ve en IG/FB) -->
<div class="prev-ov" id="prevov">
  <div class="prev-box">
    <button class="prev-x" onclick="document.getElementById('prevov').classList.remove('show')">✕</button>
    <div class="prev-tabs">
      <button class="ptab on" data-net="ig" onclick="setNet('ig')"><?= ico('instagram') ?> Instagram</button>
      <button class="ptab" data-net="fb" onclick="setNet('fb')"><?= ico('facebook') ?> Facebook</button>
    </div>
    <div class="mock ig" id="m-ig">
      <div class="ig-head"><img class="av" src="<?= $h($avatar) ?>"><b><?= $h($handle) ?></b><span class="dots">•••</span></div>
      <img class="post-img" id="ig-img" src="">
      <div class="ig-acts"><span>♡</span><span>💬</span><span>➤</span><span class="sp"></span><span>🔖</span></div>
      <div class="ig-likes">A 47 personas les gusta esto</div>
      <div class="ig-cap"><b><?= $h($handle) ?></b> <span id="ig-cap"></span></div>
    </div>
    <div class="mock fb" id="m-fb" style="display:none">
      <div class="fb-head"><img class="av" src="<?= $h($avatar) ?>"><div><b><?= $h($marca['nombre_negocio']) ?></b><div class="fb-meta">Justo ahora · 🌐</div></div></div>
      <div class="fb-text" id="fb-cap"></div>
      <img class="post-img" id="fb-img" src="">
      <div class="fb-bar"><span>👍 Me gusta</span><span>💬 Comentar</span><span>➤ Compartir</span></div>
    </div>
    <div class="prev-actions">
      <button type="button" class="pa" onclick="copiarCopy()"><?= ico('copy') ?> Copiar copy</button>
      <a class="pa" id="pa-dl" href="" download>⬇ Descargar imagen</a>
    </div>
    <div class="prev-note">Así se vería publicado. Copia el texto y descarga la imagen para subirlo, o déjalo programado en tu calendario.</div>
  </div>
</div>
<textarea id="copybuffer" style="position:absolute;left:-9999px"></textarea>

<!-- MODAL: PUBLICAR (device-aware: móvil = un toque · PC = paso a paso) -->
<div class="art-ov" id="pubov">
  <div class="art-box" style="max-width:440px">
    <button type="button" class="x" onclick="document.getElementById('pubov').classList.remove('show')">✕</button>
    <h3>📲 Publicar tu post</h3>
    <div class="sub" id="pub-modo-sub">Pásalo a tus redes.</div>
    <img id="pub-img" src="" alt="" style="width:100%;border-radius:14px;margin:12px 0;display:none">
    <div id="pub-cap" style="font-size:13.5px;line-height:1.45;white-space:pre-wrap;background:var(--crema);border:1px solid var(--line);border-radius:12px;padding:11px 13px;max-height:140px;overflow:auto"></div>

    <!-- MÓVIL: un toque -->
    <div id="pub-movil" style="display:none">
      <button type="button" id="pub-share" class="art-go" style="margin-top:14px">📲 Compartir a mis redes</button>
      <div class="art-note">Un toque y escoges Facebook, Instagram o WhatsApp.</div>
    </div>

    <div id="pub-divider" style="display:none;align-items:center;gap:10px;margin:16px 0 4px;color:var(--muted);font-size:11px;font-weight:800;letter-spacing:.05em">
      <span style="flex:1;height:1px;background:var(--line)"></span>O PÁSALO A MANO<span style="flex:1;height:1px;background:var(--line)"></span>
    </div>

    <!-- PASO A PASO (siempre disponible; en PC es el camino principal) -->
    <div id="pub-steps" style="margin-top:14px">
      <ol style="margin:0;padding-left:22px;font-size:13.5px;line-height:1.5;color:var(--tinta)">
        <li style="margin-bottom:12px">
          <b>Descarga la imagen</b><br>
          <a id="pub-dl" href="" download class="fbnew" style="display:inline-block;margin-top:6px;text-decoration:none">⬇ Descargar imagen</a>
        </li>
        <li style="margin-bottom:12px">
          <b>El texto ya está copiado</b> — pégalo cuando subas el post.<br>
          <button type="button" id="pub-copy" class="fbnew" style="margin-top:6px">📋 Copiar texto otra vez</button>
        </li>
        <li style="margin-bottom:4px">
          <b>Abre tu red, crea una publicación, sube la imagen y pega el texto:</b><br>
          <span style="display:inline-flex;gap:8px;margin-top:6px;flex-wrap:wrap">
            <a href="https://www.facebook.com/" target="_blank" rel="noopener" class="fbnew" style="text-decoration:none"><?= ico('facebook') ?> Facebook</a>
            <a href="https://www.instagram.com/" target="_blank" rel="noopener" class="fbnew" style="text-decoration:none"><?= ico('instagram') ?> Instagram</a>
          </span>
        </li>
      </ol>
      <div class="art-note" style="margin-top:12px">💡 En Instagram, publicar desde computadora se hace con el botón <b>＋</b> arriba; en celular es más fácil con "Compartir".</div>
    </div>

    <button type="button" id="pub-done" class="art-go" style="background:var(--palma);margin-top:16px">✓ Ya lo publiqué</button>
  </div>
</div>

<script>
  var ICO_IMG = <?= json_encode(ico('image'), JSON_UNESCAPED_SLASHES) ?>;
  var PILL = {borrador:['Pendiente','wait'], aprobado:['Aprobado','ok'], rechazado:['Rechazado','no'], publicado:['Publicado','pub']};
  function actionsHTML(id, estado){
    if (estado === 'borrador')
      return '<form method="post"><input type="hidden" name="id" value="'+id+'"><button class="btn btn-ok" name="accion" value="aprobar">✓ Aprobar</button></form>'
           + '<form method="post"><input type="hidden" name="id" value="'+id+'"><button class="btn btn-no" name="accion" value="rechazar">Rechazar</button></form>';
    return '<button type="button" class="btn btn-ok publicarbtn">📲 Publicar</button>'
         + '<form method="post"><input type="hidden" name="id" value="'+id+'"><button class="btn btn-ghost" name="accion" value="reabrir">↺ Volver a revisar</button></form>';
  }
  var feed = document.querySelector('.feedwrap');
  function setChk(card, k, on){
    var item = card.querySelector('.checklist .ck-item[data-k="'+k+'"]');
    if(item) item.classList.toggle('on', !!on);
  }
  function enviarAccion(card, accion){
    var fd = new FormData(); fd.append('ajax','1'); fd.append('id', card.dataset.id); fd.append('accion', accion);
    card.querySelectorAll('.post-actions button').forEach(function(b){b.disabled=true;});
    return fetch(location.pathname + location.search, {method:'POST', body:fd})
      .then(function(r){return r.json();})
      .then(function(d){
        if(!d.ok) return;
        var cp=document.getElementById('cnt-pend'), ca=document.getElementById('cnt-aprob');
        if(cp) cp.textContent=d.pend; if(ca) ca.textContent=d.aprob;
        var TAB='<?= $tab ?>';
        var enTab = (TAB==='pendientes' && d.estado==='borrador') || (TAB==='aprobados' && (d.estado==='aprobado'||d.estado==='publicado'));
        if(!enTab){
          card.style.transition='opacity .3s, transform .3s'; card.style.opacity='0'; card.style.transform='translateX(24px)';
          setTimeout(function(){ card.remove(); if(!document.querySelector('.feedwrap .post')) location.reload(); }, 320);
          return;
        }
        var pill = card.querySelector('.pill');
        if(pill){ pill.textContent = PILL[d.estado][0]; pill.className = 'pill '+PILL[d.estado][1]; }
        card.classList.toggle('done', d.estado !== 'borrador');
        card.querySelector('.post-actions').innerHTML = actionsHTML(d.id, d.estado);
        setChk(card,'ok', d.estado==='aprobado' || d.estado==='publicado');
      })
      .catch(function(){ card.querySelectorAll('.post-actions button').forEach(function(b){b.disabled=false;}); });
  }
  if (feed) feed.addEventListener('submit', function(e){
    var f = e.target.closest('form');
    if (!f || !f.closest('.post-actions')) return;
    e.preventDefault();
    var card = f.closest('.post');
    var btn = e.submitter || f.querySelector('button[name="accion"]');
    var accion = btn ? btn.value : '';
    // Aprobar inteligente: sin arte → abrir el estudio (modo "crear y aprobar")
    if (accion === 'aprobar' && !card.dataset.img) { abrirArte(card, true); return; }
    enviarAccion(card, accion);
  });

  function toast(msg){
    var t=document.createElement('div');
    t.textContent=msg;
    t.style.cssText='position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:var(--tinta);color:#fff;padding:12px 20px;border-radius:99px;font-weight:700;font-size:14px;z-index:200;box-shadow:0 10px 30px rgba(0,0,0,.3);max-width:90vw;text-align:center';
    document.body.appendChild(t);
    setTimeout(function(){t.style.opacity='0';t.style.transition='opacity .4s';},2800);
    setTimeout(function(){t.remove();},3300);
  }
  // Editar / regenerar / cancelar
  if(feed) feed.addEventListener('click', function(e){
    var el=e.target.closest('.editlink,.regenlink,.cancel'); if(!el) return; e.preventDefault();
    var card=el.closest('.post');
    if(el.classList.contains('editlink')){
      card.querySelector('.editform').style.display='block';
      card.querySelector('.caption').style.display='none';
      card.querySelector('.toolrow').style.display='none';
      card.querySelector('.editform textarea').focus();
    } else if(el.classList.contains('cancel')){
      card.querySelector('.editform').style.display='none';
      card.querySelector('.caption').style.display='';
      card.querySelector('.toolrow').style.display='flex';
    } else if(el.classList.contains('regenlink')){
      el.textContent='🔄 Regenerando…';
      var fd=new FormData(); fd.append('ajax','1'); fd.append('accion','regenerar'); fd.append('id',el.dataset.id);
      fetch(location.pathname+location.search,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
        el.textContent='🔄 Regenerar texto';
        if(d.ok){ card.querySelector('.caption').textContent=d.caption; var ta=card.querySelector('.editform textarea'); if(ta)ta.value=d.caption; setChk(card,'cap',d.caption.trim()!==''); toast('✨ Caption regenerado'); }
        else if(d.paywall) toast('🔒 Regenerar es premium. Actívate para usarlo.');
        else toast('No se pudo regenerar (¿límite de IA?)');
      }).catch(function(){ el.textContent='🔄 Regenerar'; });
    }
  });
  // Guardar edición (el bot aprende)
  if(feed) feed.addEventListener('submit', function(e){
    var f=e.target.closest('.editform'); if(!f) return; e.preventDefault();
    var card=f.closest('.post');
    var fd=new FormData(f); fd.append('ajax','1'); fd.append('accion','editar'); fd.append('id',f.dataset.id);
    var b=f.querySelector('button[type=submit]'); b.disabled=true; b.textContent='Guardando…';
    fetch(location.pathname+location.search,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
      b.disabled=false; b.textContent='Guardar';
      if(d.ok){
        card.querySelector('.caption').textContent=d.caption;
        setChk(card,'cap',d.caption.trim()!=='');
        f.style.display='none';
        card.querySelector('.caption').style.display='';
        card.querySelector('.toolrow').style.display='flex';
        if(d.leccion) toast('🧠 La IA aprendió: '+d.leccion.replace(/\n/g,' · ').slice(0,90));
        else toast('✓ Guardado');
      }
    }).catch(function(){ b.disabled=false; b.textContent='Guardar'; });
  });

  // ===== Pedir un post a la IA (brief) =====
  function abrirBrief(){ document.getElementById('briefov').classList.add('show'); }
  document.getElementById('briefov').addEventListener('click', function(e){ if(e.target===this) this.classList.remove('show'); });

  // ===== Estudio de arte (modal) — fábrica de posts =====
  var artov=document.getElementById('artov'), artform=document.getElementById('artform');
  var artCard=null, artThenApprove=false;
  function abrirArte(card, thenApprove){
    artCard=card; artThenApprove=!!thenApprove;
    document.getElementById('art-id').value=card.dataset.id;
    var cap=card.querySelector('.caption'); var txt=cap?cap.textContent.trim():'';
    document.getElementById('art-copyprev').textContent = txt ? ('"'+txt.slice(0,90)+(txt.length>90?'…':'')+'"') : 'La imagen irá acorde a tu copy.';
    document.getElementById('art-skip').style.display = thenApprove ? 'block' : 'none';
    actualizarLimitePost(card);
    artov.classList.add('show');
  }
  function actualizarLimitePost(card){
    var rest=parseInt(card.dataset.intentos||'2',10);
    var go=document.getElementById('art-go'), note=document.getElementById('art-postnote');
    if(rest<=0){
      go.disabled=true; go.textContent='Se acabaron las generaciones de este post';
      note.style.color='var(--noo-ink)';
      note.innerHTML='⚠️ Usaste tus <?= CRECER_IMG_POST ?> generaciones IA de este post. Recicla un arte de arriba ♻️ o sube tu propia imagen abajo 📎.';
    } else {
      go.disabled=false; go.textContent='✨ Crear el arte (~15s)';
      note.style.color='var(--muted)';
      note.innerHTML='Te quedan <b style="color:var(--terracota)">'+rest+' de <?= CRECER_IMG_POST ?></b> generaciones IA en este post.';
    }
  }
  function cerrarArte(){ artov.classList.remove('show'); artCard=null; artThenApprove=false; }
  artov.addEventListener('click', function(e){ if(e.target===artov) cerrarArte(); });
  if(feed) feed.addEventListener('click', function(e){
    var b=e.target.closest('.artbtn'); if(!b) return; e.preventDefault();
    abrirArte(b.closest('.post'), false);
  });
  var artLogo=document.getElementById('art-logo');
  if(artLogo) artLogo.addEventListener('change', function(){ document.getElementById('art-logoest').style.display=this.checked?'block':'none'; });
  artform.addEventListener('submit', function(e){
    e.preventDefault(); if(!artCard) return;
    var go=document.getElementById('art-go'); go.disabled=true; go.textContent='✨ Creando… (~15s)';
    var card=artCard, thenApprove=artThenApprove;
    var fd=new FormData(artform); fd.append('ajax','1');
    fetch(location.pathname+location.search,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
      if(!d.ok){
        go.disabled=false; go.textContent='✨ Crear el arte (~15s)';
        if(d.err==='post_limite') toast('⚠️ Ya usaste las <?= CRECER_IMG_POST ?> generaciones de este post. Recicla o sube tu foto.');
        else if(d.err==='limite') toast('🗓️ Usaste tus <?= CRECER_IMG_SEMANA ?> imágenes de la semana'+(d.reset?' (vuelven el '+d.reset+')':'')+'.');
        else if(d.err==='paywall'){ toast('🔒 Usaste tu imagen de muestra. Actívate para crear más.'); setTimeout(function(){location.href='/crecer/panel/precios.php?marca=<?= $marca_id ?>&motivo=muestra';},1400); }
        else toast('No se pudo crear el arte. Intenta de nuevo.');
        return;
      }
      var wrap=card.querySelector('.artwrap');
      if(wrap) wrap.innerHTML='<img class="zoomable" src="'+d.img+'?t='+Date.now()+'" alt="arte" style="width:100%;display:block">';
      card.dataset.img='1'; setChk(card,'art',true);
      card.dataset.intentos=d.restantes_post;
      var tl=card.querySelector('.toolrow .artbtn'); if(tl) tl.innerHTML=ICO_IMG+' Cambiar arte';
      document.getElementById('art-rest').textContent=d.restantes;
      cerrarArte();
      if(thenApprove){ enviarAccion(card,'aprobar').then(function(){ toast('✅ Post completo y aprobado'); }); }
      else toast('🎨 Arte listo y pegado al post');
    }).catch(function(){ go.disabled=false; go.textContent='✨ Crear el arte (~15s)'; toast('Error de conexión.'); });
  });
  // Reusar un arte ya creado (clic en miniatura)
  artform.addEventListener('click', function(e){
    var t=e.target.closest('.reuse-thumb'); if(!t || !artCard) return; e.preventDefault();
    var card=artCard, thenApprove=artThenApprove;
    var fd=new FormData(); fd.append('ajax','1'); fd.append('accion','reusar_arte'); fd.append('id',card.dataset.id); fd.append('gid',t.dataset.gid);
    fetch(location.pathname+location.search,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
      if(!d.ok){ toast('No se pudo usar ese arte.'); return; }
      var wrap=card.querySelector('.artwrap');
      if(wrap) wrap.innerHTML='<img class="zoomable" src="'+d.img+'?t='+Date.now()+'" alt="arte" style="width:100%;display:block">';
      card.dataset.img='1'; setChk(card,'art',true);
      var tl=card.querySelector('.toolrow .artbtn'); if(tl) tl.innerHTML=ICO_IMG+' Cambiar arte';
      cerrarArte();
      if(thenApprove){ enviarAccion(card,'aprobar').then(function(){ toast('✅ Post completo y aprobado'); }); }
      else toast('♻️ Arte reutilizado');
    }).catch(function(){ toast('Error de conexión.'); });
  });
  // Subir foto propia tal cual (sin IA)
  document.getElementById('art-directa-btn').addEventListener('click', function(){
    if(!artCard) return;
    var fileEl=document.getElementById('art-directa-file');
    if(!fileEl.files.length){ toast('Escoge una imagen primero.'); return; }
    var btn=this; btn.disabled=true; btn.textContent='Subiendo…';
    var card=artCard, thenApprove=artThenApprove;
    var fd=new FormData(); fd.append('ajax','1'); fd.append('accion','foto_directa'); fd.append('id',card.dataset.id); fd.append('imagen',fileEl.files[0]);
    fetch(location.pathname+location.search,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
      btn.disabled=false; btn.textContent='Usar esta';
      if(!d.ok){ toast(d.err||'No se pudo subir.'); return; }
      var wrap=card.querySelector('.artwrap');
      if(wrap) wrap.innerHTML='<img class="zoomable" src="'+d.img+'?t='+Date.now()+'" alt="arte" style="width:100%;display:block">';
      card.dataset.img='1'; setChk(card,'art',true);
      var tl=card.querySelector('.toolrow .artbtn'); if(tl) tl.innerHTML=ICO_IMG+' Cambiar arte';
      fileEl.value=''; cerrarArte();
      if(thenApprove){ enviarAccion(card,'aprobar').then(function(){ toast('✅ Post completo y aprobado'); }); }
      else toast('📎 Imagen propia añadida');
    }).catch(function(){ btn.disabled=false; btn.textContent='Usar esta'; toast('Error de conexión.'); });
  });
  document.getElementById('art-skip').addEventListener('click', function(e){
    e.preventDefault(); var card=artCard; cerrarArte(); if(card) enviarAccion(card,'aprobar').then(function(){ toast('✓ Aprobado (solo texto)'); });
  });
  // Auto-abrir el editor si venimos de "escribir uno yo"
  (function(){
    var m=location.search.match(/[?&]edit=(\d+)/); if(!m) return;
    var card=document.querySelector('.post[data-id="'+m[1]+'"]'); if(!card) return;
    var el=card.querySelector('.editlink'); if(el) el.click();
    card.scrollIntoView({behavior:'smooth',block:'center'});
  })();

  // Preview "cómo se ve en redes"
  function openPrev(img, copy){
    document.getElementById('ig-img').src = img;
    document.getElementById('fb-img').src = img;
    document.getElementById('ig-cap').textContent = copy || '';
    document.getElementById('fb-cap').textContent = copy || '';
    document.getElementById('pa-dl').href = img;
    document.getElementById('copybuffer').value = copy || '';
    setNet('ig');
    document.getElementById('prevov').classList.add('show');
  }
  function setNet(n){
    document.getElementById('m-ig').style.display = n==='ig' ? '' : 'none';
    document.getElementById('m-fb').style.display = n==='fb' ? '' : 'none';
    document.querySelectorAll('.ptab').forEach(function(t){ t.classList.toggle('on', t.dataset.net===n); });
  }
  function copiarCopy(){
    var t=document.getElementById('copybuffer');
    if(navigator.clipboard) navigator.clipboard.writeText(t.value); else { t.select(); document.execCommand('copy'); }
    event.target.textContent='✓ Copiado';
  }
  document.getElementById('prevov').addEventListener('click', function(e){ if(e.target===this) this.classList.remove('show'); });
  document.querySelectorAll('.prevlink').forEach(function(a){
    a.addEventListener('click', function(e){ e.preventDefault(); openPrev(a.dataset.img, a.dataset.copy); });
  });

  // Reprogramar un post (el dueño escoge el día)
  document.querySelectorAll('.fecha-in').forEach(function(inp){
    inp.addEventListener('change', function(){
      var fd=new FormData(); fd.append('accion','fecha'); fd.append('id',inp.dataset.id);
      fd.append('fecha',inp.value); fd.append('ajax','1');
      fetch(location.href,{method:'POST',body:fd}).then(function(r){return r.json();})
        .then(function(d){ if(d.ok){ var lab=inp.closest('.schedrow').querySelector('.lab'); if(lab)lab.textContent='Tú lo programaste para'; toast('📅 Reprogramado'); } });
    });
  });

  // ===== Publicar: pasar el post a las redes (compartir nativo o manual) =====
  var pubov=document.getElementById('pubov'), pubCard=null;
  pubov.addEventListener('click', function(e){ if(e.target===pubov) pubov.classList.remove('show'); });
  function pubData(card){
    var img=card.querySelector('.artwrap img');
    var imgUrl=img?img.getAttribute('src').split('?')[0]:'';
    var capEl=card.querySelector('.caption');
    var cap=capEl?capEl.innerText.trim():'';
    if(cap.indexOf('Sin texto todavía')===0) cap='';
    return {imgUrl:imgUrl, cap:cap};
  }
  function compartirNativo(imgUrl, cap){
    if(imgUrl && navigator.canShare){
      fetch(imgUrl).then(function(r){return r.blob();}).then(function(b){
        var file=new File([b],'post.png',{type:b.type||'image/png'});
        var data={files:[file], text:cap};
        if(navigator.canShare(data)) return navigator.share(data);
        return navigator.share({text:cap});
      }).catch(function(){});
    } else if(navigator.share){
      navigator.share({text:cap}).catch(function(){});
    } else {
      toast('Tu navegador no comparte directo — usa Copiar y Descargar.');
    }
  }
  // ¿El dispositivo puede compartir un ARCHIVO (imagen) nativo? = celular moderno.
  function puedeCompartirArchivo(){
    try { return !!(navigator.canShare && navigator.canShare({files:[new File([new Blob([''],{type:'image/png'})],'x.png',{type:'image/png'})]})); }
    catch(e){ return false; }
  }
  function abrirPublicar(card){
    pubCard=card;
    var d=pubData(card);
    var ip=document.getElementById('pub-img'), dl=document.getElementById('pub-dl');
    if(d.imgUrl){ ip.src=d.imgUrl; ip.style.display='block'; dl.href=d.imgUrl; dl.style.display=''; }
    else { ip.style.display='none'; if(dl) dl.style.display='none'; }
    document.getElementById('pub-cap').textContent=d.cap||'(este post no tiene texto)';
    if(navigator.clipboard && d.cap) navigator.clipboard.writeText(d.cap).catch(function(){});
    // Móvil (puede compartir archivo) → un toque + paso a paso como respaldo.
    // PC → directo al paso a paso, sin prometer "un toque".
    var movil = puedeCompartirArchivo();
    document.getElementById('pub-movil').style.display   = movil ? 'block' : 'none';
    document.getElementById('pub-divider').style.display = movil ? 'flex'  : 'none';
    document.getElementById('pub-modo-sub').textContent  = movil
      ? 'Un toque para compartir — o pásalo a mano.'
      : 'Desde la computadora se hace en 3 pasitos:';
    var sb=document.getElementById('pub-share');
    sb.onclick=function(){ compartirNativo(d.imgUrl, d.cap); };
    document.getElementById('pub-copy').onclick=function(){ if(navigator.clipboard) navigator.clipboard.writeText(d.cap); toast('✓ Texto copiado'); };
    document.getElementById('pub-done').onclick=function(){
      pubov.classList.remove('show');
      if(pubCard) enviarAccion(pubCard,'marcar_publicado').then(function(){ toast('🎉 ¡Publicado! Lo marcamos como publicado.'); });
    };
    pubov.classList.add('show');
  }
  if(feed) feed.addEventListener('click', function(e){
    var b=e.target.closest('.publicarbtn'); if(!b) return; e.preventDefault();
    abrirPublicar(b.closest('.post'));
  });
</script>

<?php require __DIR__ . '/_shell_foot.php'; ?>
