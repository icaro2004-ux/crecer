<?php
// ============================================================
//  _cache.php — Limpiar OPcache tras un deploy (versión simple).
//  Abre:  https://TU-DOMINIO/crecer/_cache.php?k=crecer
//  No depende del config ni del CRON_TOKEN. Borrable cuando quieras.
// ============================================================
header('Content-Type: text/plain; charset=utf-8');

if (($_GET['k'] ?? '') !== 'crecer') {
    http_response_code(403);
    echo "Añade  ?k=crecer  al final de la URL.\n";
    echo "Ej:  /crecer/_cache.php?k=crecer\n";
    exit;
}

if (function_exists('opcache_reset')) {
    echo opcache_reset() ? "OPcache limpiado ✅\n" : "No se pudo limpiar (permisos).\n";
} else {
    echo "OPcache no está activo (no hacía falta).\n";
}
echo "\nListo. Ahora en el navegador haz Ctrl+Shift+R (recarga forzada) y prueba el sitio.\n";
