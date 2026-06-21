<?php
// ============================================================
//  CRECER — Plantilla de config de PRODUCCIÓN
//  includes/config.prod.template.php
//
//  EN HOSTINGER: copia este archivo como  includes/config.local.php
//  y rellena los valores reales. NO subas el config.local.php de tu
//  XAMPP local (tiene rutas de Windows y llaves de prueba).
//
//  NOTA: no definimos UPLOADS_PATH/URL aquí — db.php los deriva solos
//  correctamente cuando Crecer vive en /crecer/ (Opción A).
// ============================================================

// Producción: oculta errores al público.
define('APP_ENV', 'prod');

// Dominio real donde vive Crecer (Opción A = dominio + /crecer).
//   Ej: https://encuentralo.com/crecer
define('BASE_URL', 'https://TU-DOMINIO/crecer');

// ── Base de datos de PRODUCCIÓN (Hostinger) ──
// Las consigues en el panel de Hostinger → Bases de datos MySQL.
define('DB_HOST', 'localhost');
define('DB_NAME', 'PON_AQUI_LA_BD');     // normalmente algo como u1234_encuentralo
define('DB_USER', 'PON_AQUI_EL_USER');
define('DB_PASS', 'PON_AQUI_EL_PASS');

// ── Gemini (REQUERIDO para que el corillo funcione) ──
// Copia la MISMA key que tienes en tu config.local.php local.
define('GEMINI_API_KEY', 'PEGA_AQUI_TU_GEMINI_API_KEY');
define('GEMINI_MODEL',   'gemini-2.5-flash');
define('GOOGLE_APPLICATION_CREDENTIALS', '');
define('GCP_PROJECT_ID', '');
define('GCP_LOCATION',   'us-central1');

// ── Stripe ──
// Para soft-launch puedes empezar con las llaves de PRUEBA (test).
// Cuando actives cobro real, pon las llaves LIVE y registra el webhook
// de producción apuntando a  BASE_URL/webhook_stripe.php
define('STRIPE_SECRET_KEY',      '');
define('STRIPE_PUBLISHABLE_KEY',  '');
define('STRIPE_WEBHOOK_SECRET',   '');

// ── Meta (Instagram + Facebook) ──
// Se llena cuando tengas la app de Meta (ver META_SETUP.md).
// El redirect debe coincidir EXACTO con el registrado en la app de Meta.
define('META_APP_ID',       '');
define('META_APP_SECRET',    '');
define('META_REDIRECT_URI', BASE_URL . '/panel/conectar.php');
define('META_GRAPH_VERSION', 'v21.0');

// ── Cron (publicador + corillo autónomo) por URL ──
// Pon una clave larga al azar; úsala en las URLs del cron.
define('CRON_TOKEN', 'PON_UNA_CLAVE_LARGA_AL_AZAR');
