<?php
// ============================================================
//  CRECER — "El Primer Minuto" (PÁGINA REAL)  ·  primer_minuto.php
//
//  La primera reunión con el depto de marketing. Entra DESPUÉS del
//  onboarding, usa los datos REALES del negocio, deja escoger la
//  primera dirección estratégica, guarda la DECISIÓN (no solo un ID)
//  y asigna el borrador curado. Aparece UNA sola vez.
//
//  Feature flag OFF: NO llama a Gemini, Voice DNA ni Director Editorial.
//  Contenido curado por el catálogo (includes/primer_minuto.php).
//  Referencia visual congelada: primer_minuto_demo.php
// ============================================================
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/primer_minuto.php';
requiere_login();
$usuario = usuario_actual($pdo);
$UID = (int)$usuario['id'];
$marca = marca_del_usuario($pdo, $UID, isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }
$marca_id = (int)$marca['id'];
$HOME = '/crecer/panel/index.php?marca=' . $marca_id;

// ¿Ya decidió su arranque? (una decisión por negocio)
$q = $pdo->prepare("SELECT id FROM crecer_estrategia_arranque WHERE marca_id=?");
$q->execute([$marca_id]);
$decidido = (bool)$q->fetchColumn();

// ── POST (foto): subir una foto real → se ve en la propuesta (sin duplicar contenido) ──
// Reusa el mecanismo de fotos de la marca (UPLOADS_PATH/marca_X/fotos), igual que el onboarding.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'foto') {
    header('Content-Type: application/json');
    if (!csrf_ok()) { echo json_encode(['ok' => false, 'err' => 'Sesión vencida, recarga la página.']); exit; }
    if (empty($_FILES['foto']['tmp_name']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) { echo json_encode(['ok' => false, 'err' => 'No se recibió la imagen.']); exit; }
    $info = @getimagesize($_FILES['foto']['tmp_name']);
    $ext  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
    if (!$ext) { echo json_encode(['ok' => false, 'err' => 'Usa una imagen JPG, PNG o WebP.']); exit; }
    if ($_FILES['foto']['size'] > 12 * 1024 * 1024) { echo json_encode(['ok' => false, 'err' => 'La imagen es muy grande (máx 12 MB).']); exit; }
    $dir = rtrim(UPLOADS_PATH, '/\\') . "/marca_{$marca_id}/fotos";
    @mkdir($dir, 0775, true);
    $name = 'foto_' . uniqid() . '.' . $ext;
    if (!move_uploaded_file($_FILES['foto']['tmp_name'], $dir . '/' . $name)) { echo json_encode(['ok' => false, 'err' => 'No se pudo guardar. Intenta otra vez.']); exit; }
    $url = (defined('UPLOADS_URL') ? rtrim(UPLOADS_URL, '/') : '/crecer/uploads') . "/marca_{$marca_id}/fotos/{$name}";
    // Actualiza la MISMA fila del post de muestra (no crea contenido nuevo).
    $cid = (int)$pdo->query("SELECT id FROM crecer_contenido WHERE marca_id={$marca_id} ORDER BY id DESC LIMIT 1")->fetchColumn();
    if ($cid) { $pdo->prepare("UPDATE crecer_contenido SET grafica_path=?, updated_at=NOW() WHERE id=?")->execute([$url, $cid]); }
    echo json_encode(['ok' => true, 'url' => $url]); exit;
}

// ── POST: confirmar la estrategia (idempotente) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!csrf_ok()) { echo json_encode(['ok' => false, 'err' => 'csrf']); exit; }
    $clave = trim($_POST['angulo'] ?? '');
    $ang = pm_angulo($clave);
    if (!$ang) { echo json_encode(['ok' => false, 'err' => 'ángulo inválido']); exit; }
    // Si ya había decisión, no dupliques nada: éxito idempotente.
    if ($decidido) { echo json_encode(['ok' => true, 'ya' => true]); exit; }

    $m       = pm_marca_a_m($pdo, $marca);
    $caption = pm_fill($ang['caption'], $m);
    $nombre  = pm_fill($ang['titulo'], $m);
    $motivo  = pm_motivo($clave, $m);

    // Asigna el borrador al contenido de muestra EXISTENTE (no crea filas nuevas → no duplica).
    $cid = (int)$pdo->query("SELECT id FROM crecer_contenido WHERE marca_id={$marca_id} ORDER BY id DESC LIMIT 1")->fetchColumn();
    if ($cid) {
        $pdo->prepare("UPDATE crecer_contenido SET caption=?, updated_at=NOW() WHERE id=?")->execute([$caption, $cid]);
    } else {
        // Defensivo: si por algún motivo no existe el post de muestra, créalo una vez.
        $ca = (int)date('Y'); $cm = (int)date('n');
        $pdo->prepare("INSERT INTO crecer_calendario (marca_id,anio,mes,estado,generado_por_ia) VALUES (?,?,?, 'borrador',0) ON DUPLICATE KEY UPDATE updated_at=NOW()")->execute([$marca_id, $ca, $cm]);
        $calid = (int)$pdo->query("SELECT id FROM crecer_calendario WHERE marca_id={$marca_id} AND anio={$ca} AND mes={$cm}")->fetchColumn();
        $pdo->prepare("INSERT INTO crecer_contenido (calendario_id,marca_id,plataforma,tipo,caption,fecha_programada,estado) VALUES (?,?, 'instagram','post',?,?, 'borrador')")
            ->execute([$calid, $marca_id, $caption, date('Y-m-d 10:00:00')]);
        $cid = (int)$pdo->lastInsertId();
    }

    // Guarda la DECISIÓN estratégica (idempotente por UNIQUE(marca_id)).
    $pdo->prepare(
        "INSERT INTO crecer_estrategia_arranque (marca_id,angulo_clave,angulo_nombre,motivo,catalogo_version,fuente,contenido_id,created_at)
         VALUES (?,?,?,?,?, 'curated_c1', ?, NOW())
         ON DUPLICATE KEY UPDATE angulo_clave=VALUES(angulo_clave), angulo_nombre=VALUES(angulo_nombre),
                                 motivo=VALUES(motivo), catalogo_version=VALUES(catalogo_version), contenido_id=VALUES(contenido_id)")
        ->execute([$marca_id, $clave, $nombre, $motivo, PM_CATALOGO_VERSION, $cid ?: null]);

    echo json_encode(['ok' => true]); exit;
}

// ── GET: si ya decidió, el momento NO se repite → al Home ──
if ($decidido) { header('Location: ' . $HOME); exit; }

// Datos reales → señales → tres estrategias
$m = pm_marca_a_m($pdo, $marca);
$props = pm_proponer($m, 3);
$V = [
    'mode'         => 'real',
    'negocio'      => $m['nombre_negocio'],
    'pueblo'       => $m['pueblo'],
    'ini'          => mb_strtoupper(mb_substr($m['nombre_negocio'], 0, 1)),
    'grad'         => 'linear-gradient(135deg,#ffe3ec,#fff2e8 52%,#e7f5f2)',  // superficie neutra premium
    'props'        => $props,
    'reveal_photo' => $m['tiene_foto'] ? $m['foto_path'] : null,
    'confirm_url'  => '/crecer/primer_minuto.php?marca=' . $marca_id,
    'home_url'     => $HOME,
    'csrf'         => csrf_token(),
    'devswitch'    => false,
];
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Empecemos · <?= $h($m['nombre_negocio']) ?></title>
<link rel="icon" type="image/svg+xml" href="/crecer/assets/brand/crecer-mark.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="/crecer/assets/encuentralo-ui.css?v=18" rel="stylesheet">
</head>
<body>
<?php require __DIR__ . '/includes/_primer_minuto_view.php'; ?>
</body>
</html>
