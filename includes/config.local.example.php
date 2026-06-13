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
define('GEMINI_MODEL',   'gemini-2.0-flash');

// ── Stripe ──
define('STRIPE_SECRET_KEY',      '');
define('STRIPE_PUBLISHABLE_KEY',  '');
define('STRIPE_WEBHOOK_SECRET',   '');
