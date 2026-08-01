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
    // Llave FIJA propia (NO el CRON_TOKEN de prod, que no cuadra — mismo lío del SMS).
    // Estas pruebas gastan dinero → protegidas con esta llave. Rota/borra luego.
    $__imgkey = (defined('CRECER_WORKER_KEY') && CRECER_WORKER_KEY !== '') ? CRECER_WORKER_KEY : 'crimg_7k2x';
    if (in_array($__test, ['img','arte','imgmanual','compare','v3async','checkout'], true) && !hash_equals($__imgkey, (string)($_GET['t'] ?? ''))) {
        echo "\n(Para las pruebas en vivo añade  &t={$__imgkey}  al final.)\n";
        $__test = '';
    }
    // AUDIT de la BD (read-only): la huella de Crecer para limpiar cuentas de prueba.
    //   &test=dbaudit&t=WORKERKEY  (&keep=correo para marcar cuál dejar)
    if ($__test === 'dbaudit' && hash_equals($__imgkey, (string)($_GET['t'] ?? ''))) {
        echo "\n--- AUDIT BD: huella de Crecer (NO borra nada) ---\n";
        $keep = strtolower(trim((string)($_GET['keep'] ?? 'jmp.arch.eng@gmail.com')));
        $cnt = function($sql) use ($pdo){ try { return (int)$pdo->query($sql)->fetchColumn(); } catch (Throwable $e){ return "ERR:".$e->getMessage(); } };
        echo "usuarios (TABLA COMPARTIDA con Encuéntralo): " . $cnt("SELECT COUNT(*) FROM usuarios") . "\n";
        echo "  · con marca de Crecer (usuarios de Crecer): " . $cnt("SELECT COUNT(DISTINCT usuario_id) FROM crecer_marca") . "\n";
        echo "\nTotales tablas crecer_*:\n";
        foreach (['crecer_marca','crecer_contenido','crecer_suscripciones','crecer_graficas','crecer_publicaciones','crecer_conexiones','crecer_ia_log','crecer_carrusel','crecer_notificaciones','crecer_metricas','crecer_generaciones','crecer_mensajes','crecer_logos','crecer_soporte','crecer_telefono_gratis'] as $t) {
            echo "  " . str_pad($t,26) . " " . $cnt("SELECT COUNT(*) FROM {$t}") . "\n";
        }
        echo "\nMARCAS (id · dueño · creada · #posts · #subs):\n";
        try {
            $rows = $pdo->query(
                "SELECT m.id, m.nombre_negocio, m.usuario_id, u.email, m.created_at,
                        (SELECT COUNT(*) FROM crecer_contenido c WHERE c.marca_id=m.id) posts,
                        (SELECT COUNT(*) FROM crecer_suscripciones s WHERE s.marca_id=m.id) subs
                 FROM crecer_marca m LEFT JOIN usuarios u ON u.id=m.usuario_id
                 ORDER BY m.id")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $marca_keep = (strtolower((string)$r['email']) === $keep);
                echo "  " . ($marca_keep ? "KEEP→ " : "      ")
                   . "#{$r['id']} '{$r['nombre_negocio']}' · " . ($r['email'] ?: '(sin dueño)')
                   . " · {$r['created_at']} · posts={$r['posts']} subs={$r['subs']}\n";
            }
        } catch (Throwable $e) { echo "  ERR: ".$e->getMessage()."\n"; }
        echo "\nUsuario a CONSERVAR: {$keep}\n";
        try {
            $q=$pdo->prepare("SELECT id,rol,verificado,created_at FROM usuarios WHERE email=?"); $q->execute([$keep]);
            if ($u=$q->fetch(PDO::FETCH_ASSOC)) echo "  → existe: user #{$u['id']} rol={$u['rol']} verificado={$u['verificado']} creado={$u['created_at']}\n";
            else echo "  → OJO: ese email NO existe en usuarios.\n";
        } catch (Throwable $e) { echo "  ERR: ".$e->getMessage()."\n"; }
    }

    // DIAGNÓSTICO DE PUBLICACIÓN: ¿de verdad salió a las redes o falló calladito?
    //   &test=pub&marca=ID&t=WORKERKEY   (o &email=X para buscar su marca)
    if ($__test === 'pub' && hash_equals($__imgkey, (string)($_GET['t'] ?? ''))) {
        require_once __DIR__ . '/includes/auth.php';
        echo "\n--- Diagnóstico de PUBLICACIÓN a redes ---\n";
        $mid = (int)($_GET['marca'] ?? 0);
        $em = strtolower(trim((string)($_GET['email'] ?? '')));
        if (!$mid && $em !== '') {
            $q=$pdo->prepare("SELECT m.id FROM crecer_marca m JOIN usuarios u ON u.id=m.usuario_id WHERE u.email=? ORDER BY m.id DESC LIMIT 1");
            $q->execute([$em]); $mid=(int)$q->fetchColumn();
        }
        if (!$mid) { echo "Pasa &marca=ID (o &email=correo).\n"; }
        else {
            echo "marca #{$mid}\n";
            // Conexión de Meta
            try {
                $cx = $pdo->prepare("SELECT plataforma,estado,ig_user_id,fb_page_id, (page_access_token IS NOT NULL AND page_access_token<>'') tiene_token FROM crecer_conexiones WHERE marca_id=?");
                $cx->execute([$mid]); $rows=$cx->fetchAll(PDO::FETCH_ASSOC);
                echo "\nConexiones:\n";
                if (!$rows) echo "  (ninguna — NO hay redes conectadas → nada puede salir)\n";
                foreach ($rows as $r) echo "  - {$r['plataforma']} estado={$r['estado']} ig_user=" . ($r['ig_user_id']?'sí':'no') . " fb_page=" . ($r['fb_page_id']?'sí':'no') . " token=" . ($r['tiene_token']?'sí':'NO') . "\n";
            } catch (Throwable $e) { echo "  (no pude leer crecer_conexiones: ".$e->getMessage().")\n"; }
            // Últimos intentos de publicación
            try {
                $pq = $pdo->prepare("SELECT contenido_id,plataforma,estado,external_id,permalink,error_msg,created_at FROM crecer_publicaciones WHERE marca_id=? ORDER BY id DESC LIMIT 12");
                $pq->execute([$mid]); $pr=$pq->fetchAll(PDO::FETCH_ASSOC);
                echo "\nÚltimos intentos de publicación:\n";
                if (!$pr) echo "  (ninguno registrado — el post NUNCA intentó salir a Meta)\n";
                foreach ($pr as $r) {
                    echo "  #{$r['contenido_id']} {$r['plataforma']} [{$r['estado']}] {$r['created_at']}\n";
                    if (!empty($r['external_id'])) echo "     external_id={$r['external_id']}" . (!empty($r['permalink'])?"  link={$r['permalink']}":"") . "\n";
                    if (!empty($r['error_msg']))  echo "     ERROR: " . substr((string)$r['error_msg'],0,240) . "\n";
                }
                echo "\nLECTURA: estado='ok' con external_id = SÍ salió a la red. estado='error' con ERROR = Meta lo rechazó (ahí está el porqué).\n";
            } catch (Throwable $e) { echo "  (no pude leer crecer_publicaciones: ".$e->getMessage().")\n"; }
        }
    }

    // DIAGNÓSTICO DE ACCESO/PAYWALL: por qué un email entra (o no) al app.
    //   &test=gate&email=X&t=WORKERKEY
    if ($__test === 'gate' && hash_equals($__imgkey, (string)($_GET['t'] ?? ''))) {
        require_once __DIR__ . '/includes/suscripcion.php';
        require_once __DIR__ . '/includes/auth.php';
        require_once __DIR__ . '/includes/gateway.php';
        $em = strtolower(trim((string)($_GET['email'] ?? '')));
        echo "\n--- Diagnóstico de ACCESO (paywall) ---\n";
        echo "APP_ENV               : " . (defined('APP_ENV') ? APP_ENV : '(no def)') . "\n";
        echo "crecer_entorno_local(): " . (crecer_entorno_local() ? "SÍ ⚠️ (baja defensas)" : "NO ✅ (producción)") . "\n";
        echo "CRECER_DEV_ACTIVAR    : " . (defined('CRECER_DEV_ACTIVAR') && CRECER_DEV_ACTIVAR ? "true" . (crecer_entorno_local()?" y APLICA ⚠️":" pero IGNORADO en prod ✅") : "off ✅") . "\n";
        echo "CRECER_TEST_EMAILS    : " . (defined('CRECER_TEST_EMAILS') && CRECER_TEST_EMAILS!=='' ? "definido (" . count(explode(',',CRECER_TEST_EMAILS)) . " emails)" : "vacío") . "\n";
        if (defined('CRECER_TEST_EMAILS') && CRECER_TEST_EMAILS!=='') {
            echo "  LISTA (borra estos del config para cerrar el bypass):\n";
            foreach (array_map('trim', explode(',', CRECER_TEST_EMAILS)) as $__e) if ($__e!=='') echo "    - {$__e}\n";
        }
        if ($em !== '') {
            echo "\nemail probado: {$em}\n";
            echo "  activacion_de_prueba(): " . (activacion_de_prueba($em) ? "SÍ ⚠️ (entra gratis SIN Stripe — es cuenta de prueba)" : "NO ✅ (va a pagar por Stripe)") . "\n";
            $u = $pdo->prepare("SELECT id, rol, verificado FROM usuarios WHERE email=? AND deleted_at IS NULL");
            $u->execute([$em]); $usr = $u->fetch(PDO::FETCH_ASSOC);
            if (!$usr) { echo "  usuario: NO existe\n"; }
            else {
                echo "  usuario #{$usr['id']} rol={$usr['rol']} verificado={$usr['verificado']}\n";
                $mk = marca_del_usuario($pdo, (int)$usr['id']);
                if (!$mk) { echo "  marca: ninguna\n"; }
                else {
                    $mid = (int)$mk['id'];
                    $su = suscripcion_de_marca($pdo, $mid);
                    echo "  marca #{$mid} '{$mk['nombre_negocio']}'\n";
                    echo "  suscripción estado: " . ($su['estado'] ?? '(ninguna)') . "\n";
                    echo "  marca_es_pagada()  : " . (marca_es_pagada($pdo,$mid) ? "SÍ (tiene acceso al app)" : "NO") . "\n";
                    echo "  gateway_estado()   : " . gateway_estado($pdo, $usr, $mk) . "  (app=paga/admin · venta/post/entrevista=aún no)\n";
                }
            }
        } else {
            echo "\n(Añade &email=elcorreo para ver por qué entra o no.)\n";
        }
    }

    // DIAGNÓSTICO DE CORREO. Reporta config + transporte (solo sí/no, sin secretos).
    // Con &to=email&t=WORKERKEY hace un ENVÍO REAL por SMTP y muestra el ERROR EXACTO
    // (sin el fallback a mail() que se traga el error en crecer_enviar_email).
    if ($__test === 'mail') {
        echo "\n--- Diagnóstico de CORREO (SMTP) ---\n";
        $ver = fn($n) => (defined($n) && constant($n) !== '')
            ? ("SÍ ✅ (empieza " . substr((string)constant($n),0,4) . "…, len " . strlen((string)constant($n)) . ")") : "NO ❌";
        echo "SMTP_HOST   : " . (defined('SMTP_HOST') && SMTP_HOST!=='' ? SMTP_HOST : "VACÍO ❌ (→ usaría mail(), poco fiable)") . "\n";
        echo "SMTP_USER   : " . $ver('SMTP_USER') . "\n";
        echo "SMTP_PASS   : " . (defined('SMTP_PASS') && SMTP_PASS!=='' ? ("SÍ ✅ (len " . strlen((string)SMTP_PASS) . ")") : "NO ❌") . "\n";
        echo "SMTP_PORT   : " . (defined('SMTP_PORT') ? SMTP_PORT : "(default 465)") . "\n";
        echo "SMTP_FROM   : " . (defined('SMTP_FROM') && SMTP_FROM!=='' ? SMTP_FROM : "(default admin@encuentraloahora.com)") . "\n";
        require_once __DIR__ . '/includes/notificaciones.php';
        $tiene_pm = function_exists('crecer_cargar_phpmailer') && crecer_cargar_phpmailer();
        echo "PHPMailer   : " . ($tiene_pm ? "SÍ ✅" : "NO ❌") . "\n";
        $usa_smtp = defined('SMTP_HOST') && SMTP_HOST!=='' && $tiene_pm;
        echo "TRANSPORTE  : " . ($usa_smtp ? "SMTP autenticado ✅" : "mail() ⚠️ (Hostinger lo bota seguido)") . "\n";

        $to = trim((string)($_GET['to'] ?? ''));
        if ($to !== '' && hash_equals($__imgkey, (string)($_GET['t'] ?? ''))) {
            echo "\n--- ENVÍO REAL a {$to} (por SMTP, mostrando error crudo) ---\n";
            if (!$usa_smtp) {
                echo "No hay SMTP → probando con mail() directo…\n";
                $ok = @mail($to, '=?UTF-8?B?'.base64_encode('Prueba Crecer (mail)').'?=', 'Prueba de correo por mail().',
                    "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nFrom: " . (defined('SMTP_FROM')&&SMTP_FROM?SMTP_FROM:'admin@encuentraloahora.com') . "\r\n");
                echo "mail() devolvió: " . ($ok ? "true (pero Hostinger igual lo puede botar)" : "false ❌") . "\n";
            } else {
                $dbg = '';
                try {
                    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                    $mail->SMTPDebug = 2;
                    $mail->Debugoutput = function($str,$lvl) use (&$dbg){ $dbg .= $str . "\n"; };
                    $mail->isSMTP();
                    $mail->Host       = SMTP_HOST;
                    $mail->SMTPAuth   = true;
                    $mail->Username   = SMTP_USER;
                    $mail->Password   = SMTP_PASS;
                    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                    $mail->Port       = defined('SMTP_PORT') ? (int)SMTP_PORT : 465;
                    $mail->CharSet    = 'UTF-8';
                    $from = (defined('SMTP_FROM') && SMTP_FROM) ? SMTP_FROM : 'admin@encuentraloahora.com';
                    $mail->setFrom($from, 'Crecer');
                    $mail->addAddress($to);
                    $mail->Subject = 'Prueba SMTP · Crecer';
                    $mail->isHTML(true);
                    $mail->Body = 'Si ves esto, el SMTP de Crecer funciona.';
                    $mail->send();
                    echo "RESULTADO: ✅ SMTP aceptó el correo. Revisa la bandeja (y spam).\n";
                } catch (Throwable $e) {
                    echo "RESULTADO: ❌ SMTP FALLÓ.\n";
                    echo "ERROR EXACTO: " . $e->getMessage() . "\n";
                    echo "\n--- Conversación SMTP (últimas líneas) ---\n" . substr($dbg, -1200) . "\n";
                }
            }
        } else {
            echo "\n(Para envío real: &test=mail&to=TUCORREO&t={$__imgkey})\n";
        }
    }

    // DIAGNÓSTICO STRIPE (solo sí/no + qué config se cargó; NUNCA los valores). No gasta.
    if ($__test === 'stripe') {
        echo "\n--- Diagnóstico STRIPE (sin exponer secretos) ---\n";
        // ¿Cuál archivo de config existe y se cargaría PRIMERO? (misma lista que db.php)
        $cands = [
            getenv('CRECER_CONFIG') ?: null,
            dirname(__DIR__) . '/crecer-config.local.php',          // ROOT prod (aquí __DIR__ = /crecer)
            dirname(__DIR__, 2) . '/crecer-config.local.php',       // respaldo prod
            __DIR__ . '/includes/config.local.php',                 // dev local
        ];
        echo "Config candidates (el PRIMERO que exista, gana):\n";
        $cargado = null;
        foreach ($cands as $c) {
            if (!$c) continue;
            $existe = is_file($c);
            if ($existe && $cargado === null) $cargado = $c;
            echo "  " . ($existe ? "EXISTE  " : "no      ") . $c . ($existe && $cargado === $c ? "   <== SE CARGA ESTE\n" : "\n");
        }
        $ver = fn($n) => (defined($n) && constant($n) !== '') ? ("SÍ ✅ (len " . strlen((string)constant($n)) . ", empieza " . substr((string)constant($n),0,8) . "…)") : "NO ❌";
        echo "\nSTRIPE_SECRET_KEY      : " . $ver('STRIPE_SECRET_KEY') . "\n";
        echo "STRIPE_PUBLISHABLE_KEY : " . $ver('STRIPE_PUBLISHABLE_KEY') . "\n";
        echo "STRIPE_WEBHOOK_SECRET  : " . $ver('STRIPE_WEBHOOK_SECRET') . "\n";
        // ¿En qué modo está la secret? (test vs live) — el prefijo NO es secreto.
        if (defined('STRIPE_SECRET_KEY') && STRIPE_SECRET_KEY !== '') {
            $pref = substr(STRIPE_SECRET_KEY, 0, 7);
            echo "Modo de la secret      : " . (strpos($pref,'sk_live')===0 ? "LIVE ✅ (cobro real)" : (strpos($pref,'sk_test')===0 ? "TEST ⚠️ (aún sandbox)" : "??? ($pref)")) . "\n";
        }
        // ¿Los planes ya tienen price_id?
        try {
            echo "\nPlanes en la BD:\n";
            foreach ($pdo->query("SELECT slug, precio_mensual, stripe_price_id FROM crecer_planes ORDER BY orden") as $r) {
                echo "  " . str_pad($r['slug'],10) . " \$" . $r['precio_mensual'] . "  price_id=" . ($r['stripe_price_id'] ?: "(VACÍO ❌)") . "\n";
            }
        } catch (Throwable $e) { echo "  (no pude leer crecer_planes: " . $e->getMessage() . ")\n"; }
    }

    // PRUEBA DEFINITIVA: crea una sesión de Checkout REAL y reporta cs_live_ vs cs_test_.
    // NO cobra nada (solo abre la sesión). Protegida con la llave de pruebas.
    if ($__test === 'checkout') {
        echo "\n--- Prueba REAL de Checkout (crea sesión, NO cobra) ---\n";
        require_once __DIR__ . '/includes/stripe.php';
        if (!stripe_configurado()) { echo "❌ Stripe no configurado.\n"; }
        else {
            try {
                $slug = (string)($_GET['plan'] ?? 'crecer');
                $ps = $pdo->prepare("SELECT * FROM crecer_planes WHERE slug=?");
                $ps->execute([$slug]); $plan = $ps->fetch(PDO::FETCH_ASSOC);
                if (!$plan) { echo "❌ plan '{$slug}' no existe.\n"; }
                elseif (empty($plan['stripe_price_id'])) { echo "❌ plan '{$slug}' sin price_id.\n"; }
                else {
                    $ses = stripe_crear_checkout(
                        $plan['stripe_price_id'], (int)($plan['trial_dias'] ?? 0),
                        'https://encuentraloahora.com/crecer/panel/checkout_ok.php?ok=1',
                        'https://encuentraloahora.com/crecer/panel/precios.php?cancelado=1',
                        ['marca_id'=>0,'usuario_id'=>0,'plan_slug'=>$slug,'plan_id'=>(int)$plan['id'],'probe'=>'1'],
                        null, 'probe@encuentraloahora.com'
                    );
                    $id = (string)($ses['id'] ?? '');
                    $pref = substr($id, 0, 8);
                    echo "plan={$slug}  price={$plan['stripe_price_id']}\n";
                    echo "session id: {$pref}…\n";
                    echo "VEREDICTO : " . (strpos($id,'cs_live_')===0 ? "LIVE ✅✅ (cobro real activo)"
                                        : (strpos($id,'cs_test_')===0 ? "TEST ⚠️ (todavía sandbox)" : "??? ({$pref})")) . "\n";
                    if (!empty($ses['url'])) echo "URL checkout: " . $ses['url'] . "\n";
                }
            } catch (Throwable $e) { echo "❌ " . $e->getMessage() . "\n"; }
        }
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

    // Prueba CONTROLADA (paso E): el prompt manual EXACTO, sin pipeline ni director.
    if ($__test === 'imgmanual') {
        echo "\n--- Prueba CONTROLADA: prompt manual (SIN pipeline) a gpt-image-1 ---\n";
        $pm = 'Premium commercial bakery campaign for social media. An open bakery box filled with a generous assortment of '
            . 'freshly made artisan donuts with different glazes and toppings, accompanied by a steaming cup of Puerto Rican '
            . 'coffee. Warm morning bakery atmosphere, professional food styling, balanced editorial composition, realistic '
            . 'textures, inviting depth, subtle nostalgia, modern brand presentation, photorealistic commercial photography. '
            . 'No people, no hands, no text, no watermark, no macro shot, no isolated single donut.';
        echo "LEN prompt: " . mb_strlen($pm) . "\n";
        try {
            $r = openai_imagen($pm, ['aspect' => '1:1']);
            $fn  = 'gpt_manual_' . substr(md5((string)microtime(true)), 0, 8) . '.png';
            $abs = rtrim(UPLOADS_PATH, '/\\') . DIRECTORY_SEPARATOR . $fn;
            @file_put_contents($abs, $r['data']);
            echo "RESULTADO: ✅ modelo " . $r['modelo'] . " (" . strlen($r['data']) . " bytes)\n";
            echo "  VER LA IMAGEN: " . rtrim(UPLOADS_URL, '/') . '/' . $fn . "\n";
            echo "→ Compara ESTA con la del pipeline. Si esta sale brutal y el pipeline no, era el prompt/estilo (ya arreglado).\n";
        } catch (Throwable $e) {
            echo "RESULTADO: ❌ " . $e->getMessage() . "\n";
        }
    }

    // COMPARE: UNA imagen por request (3 juntas = 504 timeout de nginx). Se corre 3 veces
    // con &one=v1 | v2openai | v2gemini.
    if ($__test === 'compare') {
        @set_time_limit(0);
        $variantes = [
            'v1'       => ['pipeline'=>'v1'],
            'v2openai' => ['pipeline'=>'v2','creative_model'=>'openai:creative'],
            'v2gemini' => ['pipeline'=>'v2','creative_model'=>'gemini:creative'],
        ];
        $one = (string)($_GET['one'] ?? '');
        echo "\n--- COMPARE (una a la vez para no chocar con el timeout) ---\n";
        if (!isset($variantes[$one])) {
            echo "Corre estas 3 URLs, UNA por UNA (cada una tarda ~40s):\n";
            foreach (array_keys($variantes) as $k)
                echo "  https://" . ($_SERVER['HTTP_HOST'] ?? 'encuentraloahora.com') . "/crecer/_cache.php?k=crecer&test=compare&t={$__imgkey}&one={$k}\n";
        } else {
            try {
                require_once __DIR__ . '/includes/agentes.php';
                $mid = (int)$pdo->query("SELECT id FROM crecer_marca ORDER BY id DESC LIMIT 1")->fetchColumn();
                $cap = (string)$pdo->query("SELECT caption FROM crecer_contenido WHERE marca_id={$mid} AND caption<>'' ORDER BY id DESC LIMIT 1")->fetchColumn();
                if ($cap === '') $cap = 'Donas artesanales recién hechas — por docena o para tus eventos. Ven a Rica Dona Express.';
                echo "variante: {$one}  ·  marca #{$mid}\n";
                $t0 = microtime(true);
                $r = generar_grafica($pdo, $mid, null, array_merge(['copy'=>$cap,'con_texto'=>false,'con_logo'=>false], $variantes[$one]));
                $seg = round(microtime(true) - $t0, 1);
                $arch = (string)($r['archivo'] ?? '');
                if ($arch !== '' && stripos($arch, 'http') !== 0) $arch = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'encuentraloahora.com') . '/' . ltrim($arch, '/');
                echo "✅ motor=" . ($r['modelo'] ?? '?') . " ({$seg}s)\n  VER: " . ($arch !== '' ? $arch : '(sin archivo)') . "\n";
            } catch (Throwable $e) { echo "❌ " . $e->getMessage() . "\n"; }
        }
    }

    // V3 ASYNC — encola N generaciones (default 10) y dispara los workers. Responde ya.
    if ($__test === 'v3async') {
        require_once __DIR__ . '/includes/agentes.php';
        require_once __DIR__ . '/includes/gen_async.php';
        $mid = (int)$pdo->query("SELECT id FROM crecer_marca ORDER BY id DESC LIMIT 1")->fetchColumn();
        $cap = (string)$pdo->query("SELECT caption FROM crecer_contenido WHERE marca_id={$mid} AND caption<>'' ORDER BY id DESC LIMIT 1")->fetchColumn();
        if ($cap === '') $cap = 'Donas artesanales recién hechas, por docena o para tus eventos. Rica Dona Express.';
        $n = max(1, min(10, (int)($_GET['n'] ?? 10)));
        echo "\n--- V3 ASYNC: encolando {$n} generaciones (marca #{$mid}) ---\n";
        $ids = [];
        for ($i = 0; $i < $n; $i++) { $gid = gen_encolar($pdo, $mid, $cap); gen_disparar($gid); $ids[] = $gid; usleep(150000); }
        echo "encoladas + disparadas: " . implode(', ', $ids) . "\n";
        echo "→ Corren por detrás (~1 min c/u, en paralelo). Espera ~2 min y abre:\n";
        echo "   " . 'https://' . ($_SERVER['HTTP_HOST'] ?? 'encuentraloahora.com') . "/crecer/_cache.php?k=crecer&test=v3report\n";
    }

    // V3 ASYNC — reporte (lee crecer_generaciones). No cuesta, no requiere llave.
    if (($_GET['test'] ?? '') === 'v3report') {
        echo "\n--- V3 ASYNC · REPORTE (últimas 10) ---\n";
        try {
            $rows = $pdo->query("SELECT id,estado,modelo_texto,modelo_imagen,dur_texto_ms,dur_imagen_ms,dur_total_ms,http_status,fallback,error_msg,archivo FROM crecer_generaciones ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
            $ok=0; $fail=0; $fb=0; $pend=0;
            foreach ($rows as $r) {
                if ($r['estado']==='completed') $ok++; elseif ($r['estado']==='failed') $fail++; else $pend++;
                if ($r['fallback']) $fb++;
                echo "\n#{$r['id']} [{$r['estado']}] texto={$r['modelo_texto']} ({$r['dur_texto_ms']}ms) · imagen={$r['modelo_imagen']} ({$r['dur_imagen_ms']}ms) · total=" . round(((int)$r['dur_total_ms'])/1000,1) . "s";
                if ($r['estado']==='failed') echo "  ❌ http={$r['http_status']} err=" . substr((string)$r['error_msg'],0,140);
                if (!empty($r['archivo'])) echo "\n   VER: https://" . ($_SERVER['HTTP_HOST'] ?? 'encuentraloahora.com') . '/' . ltrim((string)$r['archivo'],'/');
            }
            echo "\n\nRESUMEN: completadas={$ok} · fallidas={$fail} · pendientes={$pend} · con_fallback={$fb} (DEBE ser 0)\n";
            echo "(Si hay pendientes, refresca en 30s — siguen corriendo por detrás.)\n";
        } catch (Throwable $e) { echo "REPORTE falló (¿corriste la migración crecer_generaciones?): " . $e->getMessage() . "\n"; }
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
            // VEREDICTO que zanja el misterio "no cambia nada":
            $mdl = (string)($r['modelo'] ?? '');
            if (stripos($mdl, 'gemini') !== false) {
                echo "\n  ⚠️⚠️ LA IMAGEN LA HIZO GEMINI (Nano Banana), NO gpt-image-1.\n";
                echo "     Por eso NADA cambia con el prompt: la genera OTRO modelo. gpt-image-1\n";
                echo "     está fallando y cae al respaldo. Probando el ERROR EXACTO de OpenAI…\n";
                try {
                    $rr = openai_imagen('Un cafe boricua humeante sobre madera, luz calida, foto premium', ['aspect'=>'1:1']);
                    echo "     OpenAI directo: ✅ funciono (" . strlen($rr['data']) . " bytes). Raro — revisar el ruteo.\n";
                } catch (Throwable $e2) {
                    echo "     OpenAI ERROR EXACTO: " . $e2->getMessage() . "\n";
                    echo "     → ESE es el bug raiz (org sin verificar / sin creditos / key). Arreglalo y las imagenes cambian.\n";
                }
            } else {
                echo "\n  ✅ La imagen SI la hizo gpt-image-1. Si aun no te gusta, es el PROMPT/composicion (se ajusta).\n";
            }
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
