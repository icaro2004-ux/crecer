<?php
// ============================================================
//  CRECER — Gate de verificación por SMS del POST GRATIS
//  panel/verificar_sms.php   (AJAX, JSON)
//
//  Desbloquea el uso (descargar/publicar) del post gratis tras verificar
//  el celular. 1 desbloqueo por número (dedup atómico vía PRIMARY KEY).
//    accion=enviar    → { telefono }        → manda código por SMS
//    accion=verificar → { telefono, codigo }→ valida + reclama el número
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/agentes.php';       // marca_del_usuario / leer_marca
require __DIR__ . '/../includes/suscripcion.php';
require __DIR__ . '/../includes/twilio.php';

header('Content-Type: application/json; charset=utf-8');
requiere_login($pdo);
$usuario = usuario_actual($pdo);
if (!$usuario) { echo json_encode(['ok'=>false,'err'=>'Sesión vencida.']); exit; }
$USUARIO_ID = (int)$usuario['id'];

$marca = marca_del_usuario($pdo, $USUARIO_ID, isset($_GET['marca']) ? (int)$_GET['marca'] : (int)($_POST['marca'] ?? 0));
if (!$marca) { echo json_encode(['ok'=>false,'err'=>'Negocio no encontrado.']); exit; }
$marca_id = (int)$marca['id'];

if (!csrf_ok()) { echo json_encode(['ok'=>false,'err'=>'Sesión vencida, recarga la página.']); exit; }
if (!twilio_configurado()) { echo json_encode(['ok'=>false,'err'=>'Verificación por SMS no disponible ahora.']); exit; }

$accion   = $_POST['accion'] ?? '';
$telefono = trim((string)($_POST['telefono'] ?? ''));
$e164     = tel_e164($telefono);
if ($e164 === '') { echo json_encode(['ok'=>false,'err'=>'Ese número no se ve válido. Usa 10 dígitos, ej. 787-555-1234.']); exit; }

// Dedup: ¿este número ya reclamó su post gratis en OTRA marca? (los pagados no pasan por aquí)
$chk = $pdo->prepare("SELECT marca_id FROM crecer_telefono_gratis WHERE telefono = ?");
$chk->execute([$e164]);
$reclamado_por = $chk->fetchColumn();
if ($reclamado_por !== false && (int)$reclamado_por !== $marca_id) {
    echo json_encode(['ok'=>false, 'err'=>'Ese número ya usó su post gratis. Para más posts, activa un plan.', 'ya'=>true]); exit;
}

if ($accion === 'enviar') {
    $r = sms_enviar_codigo($e164);
    echo json_encode(['ok'=>$r['ok'], 'err'=>$r['err']]); exit;
}

if ($accion === 'verificar') {
    $codigo = trim((string)($_POST['codigo'] ?? ''));
    $r = sms_verificar_codigo($e164, $codigo);
    if (!$r['ok']) { echo json_encode(['ok'=>false, 'err'=>$r['err']]); exit; }

    // Verificado. Reclama el número para ESTA marca (atómico: el PRIMARY KEY corta el abuso).
    try {
        $pdo->prepare("INSERT INTO crecer_telefono_gratis (telefono, marca_id, usuario_id) VALUES (?,?,?)")
            ->execute([$e164, $marca_id, $USUARIO_ID]);
    } catch (PDOException $e) {
        // Choque de PK: el número ya reclamó. Si NO fue esta marca, se corta (carrera con otra cuenta).
        $d = $pdo->prepare("SELECT marca_id FROM crecer_telefono_gratis WHERE telefono=?");
        $d->execute([$e164]);
        if ((int)$d->fetchColumn() !== $marca_id) {
            echo json_encode(['ok'=>false, 'err'=>'Ese número ya usó su post gratis en otra cuenta.', 'ya'=>true]); exit;
        }
        // mismo marca (re-verificación) → sigue
    }
    // Marca la marca como verificada + guarda el tel en el usuario (para recuperación de contraseña).
    $pdo->prepare("UPDATE crecer_marca SET telefono_verificado = ? WHERE id = ?")->execute([$e164, $marca_id]);
    $pdo->prepare("UPDATE usuarios SET telefono = ? WHERE id = ? AND (telefono IS NULL OR telefono = '')")->execute([$e164, $USUARIO_ID]);

    echo json_encode(['ok'=>true]); exit;
}

echo json_encode(['ok'=>false, 'err'=>'Acción inválida.']);
