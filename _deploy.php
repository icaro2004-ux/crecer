<?php
// ============================================================
//  _deploy.php — Limpiar OPcache + verificar qué código está VIVO en prod.
//  Úsalo DESPUÉS de cada Redeploy: abre  tudominio.com/crecer/_deploy.php
//  Limpia el bytecode viejo que PHP tenía en memoria y te dice, sin adivinar,
//  si el código nuevo (los fixes del creador/preview) ya está corriendo.
//  Borrable cuando quieras — no afecta la app.
// ============================================================
header('Content-Type: text/plain; charset=utf-8');

$out = [];

// 1) Limpiar TODO el caché de opcode (esto hace que el próximo request compile
//    los archivos nuevos desde el disco). Es la causa de "redeployo y no cambia".
if (function_exists('opcache_reset')) {
    $out['opcache_reset'] = opcache_reset() ? 'OK — caché limpiado' : 'no se pudo (permisos)';
} else {
    $out['opcache_reset'] = 'OPcache no está activo (no era el problema)';
}

// 2) Cargar el código real desde disco y verificar marcadores del código NUEVO.
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/genoma.php';

$out['---'] = '--- ¿Qué código está vivo? ---';
$out['preview_lo_escribe_la_IA'] = function_exists('genoma_captions_preview')
    ? 'SÍ  ✅  (código NUEVO — el molde ya no sale)'
    : 'NO  ❌  (código VIEJO — todavía el molde)';
$out['kill_cta_roto']       = function_exists('_limpiar_cta_rota') ? 'SÍ ✅' : 'NO ❌';
$out['sesion_fantasma_fix'] = 'requiere_login verifica usuario';
$out['APP_ENV']             = defined('APP_ENV') ? APP_ENV : '(no definido)';
$out['flag_primer_minuto']  = (defined('VOICE_DNA_ONBOARDING_ENABLED') && VOICE_DNA_ONBOARDING_ENABLED) ? 'ON' : 'OFF';

echo "CRECER · verificación de deploy\n";
echo str_repeat('=', 44) . "\n";
foreach ($out as $k => $v) echo str_pad($k, 26) . ' : ' . $v . "\n";
echo "\nSi 'preview_lo_escribe_la_IA' dice SÍ, ya estás en el código nuevo.\n";
echo "Ahora crea un negocio NUEVO desde cero para ver el preview de la IA.\n";
