<?php
// ============================================================
//  CRECER — Plantilla de configuración local
//  includes/config.local.example.php
//
//  Copiar a `config.local.php` y rellenar con credenciales reales.
//  config.local.php NO se versiona (.gitignore) ni se sube por FTP
//  a producción desde local — cada ambiente tiene su propio archivo.
// ============================================================

// 'local' o 'prod'. Controla error reporting y comportamiento de BASE_URL.
define('APP_ENV', 'local');

// Si comentas esta línea, db.php detecta BASE_URL desde HTTP_HOST.
define('BASE_URL', 'http://localhost/crecer');

// ── DB (compartida con Encuéntralo) ──
define('DB_HOST', 'localhost');
define('DB_NAME', 'encuentralo_db');
define('DB_USER', 'root');
define('DB_PASS', '');

// ── Uploads (opcional; db.php los deriva si no los defines) ──
// LOCAL: define('UPLOADS_PATH', 'C:/xampp/htdocs/crecer/uploads');
//        define('UPLOADS_URL',  '/crecer/uploads');

// ── Gemini / Vertex AI ──
// Opción rápida: GEMINI_API_KEY de AI Studio (un solo valor).
define('GEMINI_API_KEY', '');
// Opción Vertex: ruta al JSON del service account (NO versionar).
define('GOOGLE_APPLICATION_CREDENTIALS', '');
define('GCP_PROJECT_ID', '');
define('GCP_LOCATION',   'us-central1');
define('GEMINI_MODEL',   'gemini-2.5-flash'); // 2.0-flash no tiene free tier; 2.5 sí

// ── Stripe ──
define('STRIPE_SECRET_KEY',      '');
define('STRIPE_PUBLISHABLE_KEY',  '');
define('STRIPE_WEBHOOK_SECRET',   '');

// ── Meta (Instagram + Facebook) — publicación automática ──
// De tu app en developers.facebook.com. Publicar a nombre de clientes
// requiere App Review con Advanced Access de los permisos en META_SCOPES.
define('META_APP_ID',       '');
define('META_APP_SECRET',    '');
// A dónde regresa Meta tras el login (debe coincidir EXACTO con el que
// registres en la app de Meta). En prod usa https://tu-dominio/...
define('META_REDIRECT_URI', BASE_URL . '/panel/conectar.php');
define('META_GRAPH_VERSION', 'v21.0');

// ── Cron del publicador ──
// Si corres el cron por URL (no CLI), protégelo con este token:
//   https://tu-dominio/crecer/scripts/cron_publicar.php?key=XXXX
define('CRON_TOKEN', '');

// ── MODO PRUEBA (sandbox) — activar "Activar Crecer" SIN Stripe ──────────────
// Para probar el flujo completo con cuentas de prueba, sin cobrar.
//
//  · LOCAL (tu XAMPP): activa TODAS las cuentas. NO usar en producción.
//      define('CRECER_DEV_ACTIVAR', true);
//
//  · PRODUCCIÓN (probar en el celular): activa SOLO estos emails. Seguro —
//    los usuarios reales siguen pagando por Stripe. Registra cuentas de prueba
//    con estos emails y actívalas gratis desde el teléfono.
//      define('CRECER_TEST_EMAILS', 'tucorreo+prueba1@gmail.com, tucorreo+prueba2@gmail.com');
