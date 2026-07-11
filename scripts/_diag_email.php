<?php
// ============================================================
//  DIAGNÓSTICO TEMPORAL — ¿por qué no llegan los correos?
//  scripts/_diag_email.php   (BORRAR después de usar)
//
//  Dice, en PROD: qué config se cargó, si las constantes SMTP_*
//  están definidas y con valor, si PHPMailer se encuentra, y —si
//  le pasas ?to=tucorreo— INTENTA un envío real y muestra el ERROR
//  EXACTO de SMTP (que normalmente se traga el fallback).
//
//  Uso:
//    https://TU-DOMINIO/crecer/scripts/_diag_email.php?key=diag-crecer-mail-7q2
//    https://TU-DOMINIO/crecer/scripts/_diag_email.php?key=diag-crecer-mail-7q2&to=tucorreo@gmail.com
// ============================================================
require __DIR__ . '/../includes/db.php';   // ← esto carga el config.local (donde sea que esté)

$KEY = 'diag-crecer-mail-7q2';
if (($_GET['key'] ?? '') !== $KEY) { http_response_code(403); exit('nope'); }
header('Content-Type: text/plain; charset=utf-8');

function si($b){ return $b ? 'SÍ' : 'NO'; }

echo "=== CONFIG ===\n";
echo "APP_ENV     : " . (defined('APP_ENV') ? APP_ENV : '(undef)') . "\n";
echo "DB_NAME     : " . (defined('DB_NAME') && DB_NAME !== '' ? DB_NAME : '(vacío/undef)') . "\n";
echo "\nRutas candidatas del config (la que exista PRIMERO se usa):\n";
$cands = [
    getenv('CRECER_CONFIG') ?: '(env CRECER_CONFIG no seteada)',
    __DIR__ . '/../includes/config.local.php',
    dirname(__DIR__, 3) . '/crecer-config.local.php',   // /home/USER/crecer-config.local.php
    dirname(__DIR__, 2) . '/crecer-config.local.php',   // sobre public_html (respaldo)
];
foreach ($cands as $c) {
    $existe = (strpos($c, '(env') === 0) ? false : is_file($c);
    echo "  [" . ($existe ? 'EXISTE' : '  no  ') . "]  $c\n";
}

echo "\n=== SMTP (¿se cargaron las credenciales?) ===\n";
echo "SMTP_HOST   : " . (defined('SMTP_HOST') ? "'".SMTP_HOST."'" : '*** UNDEFINED ***  (⇐ si sale esto, el config con SMTP NO se cargó)') . "\n";
echo "SMTP_PORT   : " . (defined('SMTP_PORT') ? SMTP_PORT : '(undef)') . "\n";
echo "SMTP_USER   : " . (defined('SMTP_USER') ? "'".SMTP_USER."'" : '(undef)') . "\n";
echo "SMTP_PASS   : " . (defined('SMTP_PASS') && SMTP_PASS !== '' ? 'seteada (' . strlen(SMTP_PASS) . ' chars)' : 'VACÍA/undef') . "\n";
echo "SMTP_FROM   : " . (defined('SMTP_FROM') ? SMTP_FROM : '(undef)') . "\n";

require __DIR__ . '/../includes/notificaciones.php';
echo "\nPHPMailer cargable: " . si(crecer_cargar_phpmailer()) . "\n";
echo "Usará SMTP (no mail()): " . si(defined('SMTP_HOST') && SMTP_HOST !== '' && crecer_cargar_phpmailer()) . "\n";

$to = $_GET['to'] ?? '';
if ($to && filter_var($to, FILTER_VALIDATE_EMAIL)) {
    echo "\n=== ENVÍO DE PRUEBA a $to ===\n";
    if (!(defined('SMTP_HOST') && SMTP_HOST !== '')) {
        echo "No hay SMTP_HOST → no puedo probar SMTP. Arregla el config primero.\n";
    } elseif (!crecer_cargar_phpmailer()) {
        echo "PHPMailer no se pudo cargar (revisa vendor/PHPMailer/).\n";
    } else {
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->SMTPDebug  = 2;                 // imprime el diálogo SMTP completo
            $mail->Debugoutput = 'echo';
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = defined('SMTP_PORT') ? (int)SMTP_PORT : 465;
            $mail->CharSet    = 'UTF-8';
            $mail->setFrom(defined('SMTP_FROM') ? SMTP_FROM : SMTP_USER, 'Crecer · Encuéntralo');
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = 'Prueba SMTP de Crecer';
            $mail->Body    = '<p>Si ves esto, el SMTP de Crecer <b>funciona</b>.</p>';
            $mail->send();
            echo "\n\nRESULTADO: ENVIADO OK ✔  (revisa la bandeja / spam)\n";
        } catch (\Throwable $e) {
            echo "\n\nRESULTADO: FALLO - " . $e->getMessage() . "\n";
        }
    }
}
echo "\n(Borra este archivo cuando termines.)\n";
