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

    // ¿Está el CÓDIGO NUEVO vivo? (la función edits solo existe en el código nuevo)
    echo "Código nuevo (gpt-image-1 edits) : "
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
} catch (Throwable $e) {
    echo "No pude cargar el diagnóstico: " . $e->getMessage() . "\n";
}

echo "\nAhora haz Ctrl+Shift+R en el navegador y genera un post nuevo.\n";
