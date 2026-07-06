<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — Reenviar el correo de activación
//  reenviar.php  (POST desde la pantalla "revisa tu correo")
// ============================================================
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/notificaciones.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $email = trim($_POST['email'] ?? '');
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $q = $pdo->prepare("SELECT id, nombre, email, verif_token FROM usuarios WHERE email = ? AND verificado = 0 AND deleted_at IS NULL LIMIT 1");
        $q->execute([$email]);
        $u = $q->fetch();
        if ($u) {
            $tok = $u['verif_token'] ?: bin2hex(random_bytes(32));
            if (!$u['verif_token']) $pdo->prepare("UPDATE usuarios SET verif_token=? WHERE id=?")->execute([$tok, (int)$u['id']]);
            $base = defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'http://localhost/crecer';
            crecer_email_activacion($u['email'], $u['nombre'], $base . '/activar.php?token=' . $tok);
        }
    }
    // No revelamos si el correo existe o no: siempre "enviado".
    header('Location: /crecer/registro.php?enviado=' . urlencode($email)); exit;
}
header('Location: /crecer/registro.php'); exit;
