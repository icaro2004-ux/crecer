<?php
// ============================================================
//  _cache.php — Limpiar OPcache + diagnóstico rápido del generador.
//  Abre:  https://TU-DOMINIO/crecer/_cache.php?k=crecer
//  No exige CRON_TOKEN. Borrable cuando quieras.
// ============================================================
header('Content-Type: text/plain; charset=utf-8');

if (($_GET['k'] ?? '') !== 'crecer') {
    http_response_code(403);
    echo "Añade  ?k=crecer  al final de la URL.\n";
    echo "Ej:  /crecer/_cache.php?k=crecer\n";
    exit;
}

echo "CRECER · limpiar caché + diagnóstico\n";
echo str_repeat('=', 44) . "\n\n";

// 1) Limpiar OPcache (la causa de "redeployo y no cambia").
if (function_exists('opcache_reset')) {
    echo "OPcache: " . (opcache_reset() ? "limpiado ✅\n" : "no se pudo (permisos)\n");
} else {
    echo "OPcache: no está activo (no hacía falta)\n";
}

// 2) Diagnóstico del generador de imágenes (envuelto: si algo falla, no rompe el reset).
echo "\n--- Generador de imágenes ---\n";
try {
    require __DIR__ . '/includes/db.php';    // define las constantes de config
    require __DIR__ . '/includes/ia.php';    // motor de imagen
    require_once __DIR__ . '/includes/agentes.php';

    // ── ¿ESTÁ VIVO EL CÓDIGO NUEVO DE LA ENTREVISTA? (lo que se acaba de subir) ──
    echo "ENTREVISTA adaptativa (nueva)  : " . (function_exists('entrevista_siguiente') ? "SÍ ✅  (código NUEVO)\n" : "NO ❌  (código VIEJO — OPcache no se limpió)\n");
    echo "Radiografía por capítulos      : " . (function_exists('genoma_radiografia') ? "SÍ ✅\n" : "NO ❌\n");
    echo "Post de muestra (helper nuevo) : " . (function_exists('crear_post_muestra') ? "SÍ ✅\n" : "NO ❌\n");

    // ¿Está el CÓDIGO NUEVO vivo? (la función edits solo existe en el código nuevo)
    echo "\nCódigo nuevo (gpt-image-1 edits) : "
       . (function_exists('openai_imagen_edit') ? "SÍ ✅\n" : "NO ❌  (falta Redeploy)\n");

    // ¿Está el KEY de OpenAI en el config de PROD?
    $tiene_key = function_exists('openai_configurado') && openai_configurado();
    echo "OPENAI_API_KEY en config       : " . ($tiene_key ? "SÍ ✅\n" : "NO ❌  (falta en config.local.php de prod)\n");
    echo "Modelo de imagen configurado   : " . (defined('OPENAI_IMG_MODEL') ? OPENAI_IMG_MODEL : '(default)') . "\n";
    echo "Calidad configurada            : " . (defined('OPENAI_IMG_QUALITY') ? OPENAI_IMG_QUALITY : '(default)') . "\n";

    // Veredicto: ¿qué motor usaría el arte desde cero (sin foto real)?
    echo "\nVeredicto para ARTE DESDE CERO : ";
    if (function_exists('motor_imagen_elegir')) {
        $dec = motor_imagen_elegir(['foto_real' => false]);
        echo strtoupper($dec['motor']) . "  (" . $dec['razon'] . ")\n";
    } else {
        echo "(código viejo, no se puede evaluar)\n";
    }
    // 3) Prueba EN VIVO contra OpenAI (opcional): añade  &test=img  a la URL.
    //    Hace 1 llamada real y muestra el resultado o el ERROR EXACTO (ej. "org
    //    no verificada"). Cuesta ~$0.17 la prueba.
    // Los tests EN VIVO gastan dinero (llaman a OpenAI/Gemini) → exigen el CRON_TOKEN real,
    // no el 'crecer' público. Evita que alguien te queme el balance con &test=img/arte en loop.
    $__test = $_GET['test'] ?? '';
    $__tok  = defined('CRON_TOKEN') ? CRON_TOKEN : '';
    // La prueba de SMS NO depende del CRON_TOKEN (que en prod puede estar en otro valor):
    // usa su propia llave fija de abajo. Solo img/arte pasan por este candado de dinero.
    if (in_array($__test, ['img','arte'], true) && ($__tok === '' || !hash_equals($__tok, (string)($_GET['t'] ?? '')))) {
        echo "\n(Para las pruebas en vivo &test=img/arte añade  &t=TU_CRON_TOKEN  — gastan dinero, por eso van protegidas.)\n";
        $__test = '';
    }
    if ($__test === 'img') {
        echo "\n--- Prueba EN VIVO a OpenAI (gpt-image-1) ---\n";
        try {
            $r = openai_imagen('Un café boricua humeante sobre madera, luz cálida, foto premium', ['aspect' => '1:1']);
            echo "RESULTADO: ✅ OpenAI generó la imagen (" . strlen($r['data']) . " bytes, modelo " . $r['modelo'] . ").\n";
            echo "→ gpt-image-1 FUNCIONA. Genera un post y saldrá con este motor.\n";
        } catch (Throwable $e) {
            echo "RESULTADO: ❌ OpenAI falló.\n";
            echo "ERROR EXACTO: " . $e->getMessage() . "\n";
            echo "→ Si menciona 'organization/verified', verifica tu org en OpenAI,\n";
            echo "  o cambia OPENAI_IMG_MODEL a 'dall-e-3' en el config (no exige verificación).\n";
        }
    } else {
        echo "\n(Para probar OpenAI, abre esta URL con  &test=img  al final.)\n";
    }

    // Prueba REAL del SMS: manda un código de verdad y muestra el ERROR CRUDO de Twilio.
    //    Añade  &test=sms&to=7875551234&s=crsms_7k2x  . Cuesta unos centavos.
    //    Llave FIJA (no el CRON_TOKEN) para no depender del config. Bórrala/rota luego.
    $__sms_ok = ($_GET['test'] ?? '') === 'sms' && hash_equals('crsms_7k2x', (string)($_GET['s'] ?? ''));
    if (($_GET['test'] ?? '') === 'sms' && !$__sms_ok) {
        echo "\n(Para la prueba de SMS añade  &s=crsms_7k2x  al final.)\n";
    }
    if ($__sms_ok) {
        echo "\n--- Prueba EN VIVO del SMS (Twilio Verify) ---\n";
        require_once __DIR__ . '/includes/twilio.php';
        echo "twilio_configurado()          : " . (twilio_configurado() ? "SÍ ✅\n" : "NO ❌ (faltan constantes en config)\n");
        echo "TWILIO_ACCOUNT_SID definido    : " . (defined('TWILIO_ACCOUNT_SID') && TWILIO_ACCOUNT_SID !== '' ? ("SÍ (empieza " . substr(TWILIO_ACCOUNT_SID,0,4) . "…)\n") : "NO ❌\n");
        echo "TWILIO_AUTH_TOKEN definido     : " . (defined('TWILIO_AUTH_TOKEN')  && TWILIO_AUTH_TOKEN  !== '' ? ("SÍ (len " . strlen(TWILIO_AUTH_TOKEN) . ")\n") : "NO ❌\n");
        echo "TWILIO_VERIFY_SID definido     : " . (defined('TWILIO_VERIFY_SID')  && TWILIO_VERIFY_SID  !== '' ? ("SÍ (empieza " . substr(TWILIO_VERIFY_SID,0,4) . "…)\n") : "NO ❌\n");
        $to = tel_e164((string)($_GET['to'] ?? ''));
        if ($to === '') {
            echo "\n→ Añade  &to=7875551234  (tu celular) para el envío real.\n";
        } else {
            echo "\nEnviando a {$to} …\n";
            try {
                $r = twilio_api('POST', 'v2/Services/' . TWILIO_VERIFY_SID . '/Verifications', ['To' => $to, 'Channel' => 'sms']);
                echo "RESULTADO: ✅ Twilio aceptó (status=" . ($r['status'] ?? '?') . "). Revisa tu celular.\n";
            } catch (Throwable $e) {
                echo "RESULTADO: ❌ Twilio RECHAZÓ.\n";
                echo "ERROR CRUDO: " . $e->getMessage() . "\n";
                echo "→ Busca el número de error de Twilio en ese mensaje (ej. 60410=geo bloqueada, 20003=auth, 20404=Verify SID malo).\n";
            }
        }
    }

    // Prueba REAL del arte de los posts: corre generar_grafica() end-to-end.
    //    Añade  &test=arte  a la URL. Dice si genera, con qué modelo y si guarda el archivo.
    if ($__test === 'arte') {
        echo "\n--- Prueba REAL de generar_grafica (el arte de los posts) ---\n";
        try {
            require_once __DIR__ . '/includes/agentes.php';
            $mid = (int)$pdo->query("SELECT id FROM crecer_marca ORDER BY id DESC LIMIT 1")->fetchColumn();
            echo "marca de prueba: #{$mid}\n";
            $t0 = microtime(true);
            $r = generar_grafica($pdo, $mid, null, ['copy' => 'Café boricua recién colado, ven a probarlo hoy', 'con_texto' => false, 'con_logo' => false]);
            $seg = round(microtime(true) - $t0, 1);
            echo "RESULTADO: ✅ generó arte en {$seg}s\n";
            echo "  archivo (url) : " . ($r['archivo'] ?? '(?)') . "\n";
            echo "  modelo        : " . ($r['modelo'] ?? '(?)') . "\n";
            $url = (string)($r['archivo'] ?? '');
            $rel = ltrim(str_replace(rtrim(UPLOADS_URL, '/'), '', $url), '/');
            $abs = rtrim(UPLOADS_PATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            echo "  archivo en disco: " . (is_file($abs) ? ('SÍ ✅ (' . filesize($abs) . ' bytes)') : "NO ❌  (ruta: {$abs})") . "\n";
        } catch (Throwable $e) {
            echo "RESULTADO: ❌ FALLÓ\n";
            echo "  ERROR EXACTO: " . $e->getMessage() . "\n";
        }
    }
} catch (Throwable $e) {
    echo "No pude cargar el diagnóstico: " . $e->getMessage() . "\n";
}

echo "\nAhora haz Ctrl+Shift+R en el navegador y genera un post nuevo.\n";
