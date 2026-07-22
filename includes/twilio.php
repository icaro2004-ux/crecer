<?php
// ============================================================
//  CRECER — Cliente mínimo de Twilio Verify (SMS OTP)
//  includes/twilio.php
//
//  Verificación de celular por SMS (código de 6 dígitos). Twilio
//  Verify maneja la generación/validación del código — nosotros solo
//  disparamos "enviar" y "verificar". Calca el patrón cURL de stripe.php.
//
//  Requiere en config: TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, TWILIO_VERIFY_SID.
//  Usos: anti-abuso (1 gratis por número) + recuperación de contraseña + 2FA.
// ============================================================

class TwilioError extends RuntimeException {}

/** ¿Hay credenciales de Twilio? (para degradar con gracia). */
function twilio_configurado(): bool {
    return defined('TWILIO_ACCOUNT_SID') && TWILIO_ACCOUNT_SID !== ''
        && defined('TWILIO_AUTH_TOKEN')  && TWILIO_AUTH_TOKEN  !== ''
        && defined('TWILIO_VERIFY_SID')  && TWILIO_VERIFY_SID  !== '';
}

/**
 * Normaliza un teléfono boricua/US al formato E.164 que Twilio exige (+1XXXXXXXXXX).
 * "787-555-1234", "(939) 555 1234", "7875551234", "17875551234" → "+17875551234".
 * Devuelve '' si no parece un número válido (10 u 11 dígitos).
 */
function tel_e164(string $telefono): string {
    $d = preg_replace('/\D+/', '', $telefono) ?? '';
    if (strlen($d) === 10)                       return '+1' . $d;            // PR/US local
    if (strlen($d) === 11 && $d[0] === '1')      return '+'  . $d;            // 1 + 10
    return '';                                                               // inválido
}

/**
 * Llamada cruda a la API de Twilio (Basic auth: AccountSid:AuthToken).
 * $path relativo a https://{host}/... ; $host default = verify.
 */
function twilio_api(string $metodo, string $path, array $params = [], string $host = 'verify.twilio.com'): array {
    if (!twilio_configurado()) {
        throw new TwilioError('Twilio no está configurado (faltan credenciales).');
    }
    $url = "https://{$host}/" . ltrim($path, '/');
    $ch = curl_init();
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_USERPWD        => TWILIO_ACCOUNT_SID . ':' . TWILIO_AUTH_TOKEN,   // Basic auth
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ];
    if (strtoupper($metodo) === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
    } elseif ($params) {
        $opts[CURLOPT_URL] = $url . '?' . http_build_query($params);
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    if ($resp === false) { $e = curl_error($ch); curl_close($ch); throw new TwilioError('cURL: ' . $e); }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string)$resp, true);
    if ($code < 200 || $code >= 300) {
        $msg = $data['message'] ?? substr((string)$resp, 0, 200);
        throw new TwilioError("Twilio HTTP $code: $msg");
    }
    return is_array($data) ? $data : [];
}

/**
 * Envía un código de 6 dígitos por SMS al número dado.
 * @return array{ok:bool, err:string, e164:string}
 */
function sms_enviar_codigo(string $telefono): array {
    $e164 = tel_e164($telefono);
    if ($e164 === '') return ['ok' => false, 'err' => 'Ese número no se ve válido (usa 10 dígitos, ej. 787-555-1234).', 'e164' => ''];
    try {
        $r = twilio_api('POST', 'v2/Services/' . TWILIO_VERIFY_SID . '/Verifications', [
            'To' => $e164, 'Channel' => 'sms',
        ]);
        $ok = in_array(($r['status'] ?? ''), ['pending', 'approved'], true);
        return ['ok' => $ok, 'err' => $ok ? '' : 'No se pudo enviar el código. Intenta otra vez.', 'e164' => $e164];
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        error_log('sms_enviar_codigo: ' . $msg);
        // Detecta las causas típicas para no dejar al dueño a ciegas.
        $err = 'No pudimos enviar el SMS ahora mismo. Intenta en un momento.';
        if (stripos($msg, 'unverified') !== false || stripos($msg, 'trial') !== false
            || strpos($msg, '21608') !== false || strpos($msg, '21211') !== false) {
            $err = 'Tu cuenta de Twilio está en modo prueba: solo puede enviar a números verificados en la consola de Twilio. Verifica el número o pasa la cuenta a pago.';
        } elseif (strpos($msg, '20003') !== false || stripos($msg, 'authenticate') !== false || strpos($msg, '401') !== false) {
            $err = 'Las credenciales de Twilio no cuadran (revisa Auth Token / SID en el config).';
        } elseif (strpos($msg, '20404') !== false || stripos($msg, 'not found') !== false) {
            $err = 'El Verify Service de Twilio no existe (revisa TWILIO_VERIFY_SID en el config).';
        }
        return ['ok' => false, 'err' => $err, 'e164' => $e164];
    }
}

/**
 * Verifica el código que el usuario escribió contra Twilio.
 * @return array{ok:bool, err:string, e164:string}  ok=true SOLO si Twilio lo aprueba.
 */
function sms_verificar_codigo(string $telefono, string $codigo): array {
    $e164 = tel_e164($telefono);
    $codigo = preg_replace('/\D+/', '', $codigo) ?? '';
    if ($e164 === '' || $codigo === '') return ['ok' => false, 'err' => 'Falta el número o el código.', 'e164' => $e164];
    try {
        $r = twilio_api('POST', 'v2/Services/' . TWILIO_VERIFY_SID . '/VerificationCheck', [
            'To' => $e164, 'Code' => $codigo,
        ]);
        $ok = ($r['status'] ?? '') === 'approved';
        return ['ok' => $ok, 'err' => $ok ? '' : 'El código no es correcto o venció. Pide uno nuevo.', 'e164' => $e164];
    } catch (Throwable $e) {
        error_log('sms_verificar_codigo: ' . $e->getMessage());
        return ['ok' => false, 'err' => 'No pudimos verificar el código ahora. Intenta otra vez.', 'e164' => $e164];
    }
}
