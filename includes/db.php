<?php
// ============================================================
//  CRECER — Conexión a la base de datos
//  includes/db.php
//  (Reusado de Encuéntralo — ver REUSE.md)
//
//  BD COMPARTIDA: apunta a `encuentralo_db`. Las tablas crecer_*
//  conviven con las pre-existentes de Encuéntralo.
//
//  Credenciales: viven en includes/config.local.php (no versionado)
//  o en variables de entorno. NUNCA hardcodear aquí.
// ============================================================

// ── 1) Cargar config ─────────────────────────────────────────
//  Orden de búsqueda (la PRIMERA que exista, gana):
//    a) Ruta explícita en la variable de entorno CRECER_CONFIG.
//    b) includes/config.local.php  → desarrollo local (XAMPP). Gitignored.
//    c) Rutas FUERA del repo → producción. Sobreviven al git deploy porque el
//       deploy solo limpia la carpeta del repo (public_html/crecer). Colocar el
//       archivo en la carpeta `home` (sobre public_html) lo deja además fuera
//       del alcance web. Crear una sola vez por el File Manager de hPanel.
//       En Hostinger __DIR__ = /home/USER/public_html/crecer/includes, así que
//       dirname(__DIR__, 3) = /home/USER  (la carpeta home).
$config_candidates = [
    getenv('CRECER_CONFIG') ?: null,
    __DIR__ . '/config.local.php',
    dirname(__DIR__, 3) . '/crecer-config.local.php',   // /home/USER/crecer-config.local.php
    dirname(__DIR__, 2) . '/crecer-config.local.php',   // un nivel sobre public_html como respaldo
];
foreach ($config_candidates as $cfg) {
    if ($cfg && is_file($cfg)) { require_once $cfg; break; }
}

// ── 2) Fallback a variables de entorno ───────────────────────
if (!defined('APP_ENV')) define('APP_ENV', getenv('APP_ENV') ?: 'prod');
if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: '');
if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: '');
if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

// ── 3) Validar antes de intentar conectar ────────────────────
if (DB_NAME === '' || DB_USER === '') {
    error_log('db.php: faltan credenciales DB. Crea includes/config.local.php o define DB_NAME / DB_USER como variables de entorno.');
    http_response_code(500);
    if (APP_ENV === 'local') {
        die('Error de configuración: falta includes/config.local.php (copiar de config.local.example.php).');
    }
    die('Servicio no disponible. Vuelve a intentar en unos minutos.');
}

// ── 4) BASE_URL (si no la definió config.local.php) ──────────
if (!defined('BASE_URL')) {
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    define('BASE_URL', "$proto://$host");
}

// ── 4.5) UPLOADS_PATH / UPLOADS_URL (defaults sensatos) ──────
if (!defined('UPLOADS_PATH')) {
    define('UPLOADS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads');
}
if (!defined('UPLOADS_URL')) {
    $p = parse_url(BASE_URL);
    $base_path = $p['path'] ?? '';
    define('UPLOADS_URL', rtrim($base_path, '/') . '/uploads');
}

// ── 4.6) Headers de seguridad ────────────────────────────────
require_once __DIR__ . '/security-headers.php';

// ── 5) Conexión PDO ──────────────────────────────────────────
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    // Mantener la conexión viva durante operaciones LARGAS: generar una imagen con
    // IA deja la conexión inactiva ~15-30s (o más si son varias), y Hostinger la
    // cierra rápido (wait_timeout bajo) → "MySQL server has gone away" al guardar.
    // Subir el timeout de la sesión a 10 min evita que se caiga a mitad.
    try { $pdo->exec("SET SESSION wait_timeout=600, interactive_timeout=600"); } catch (Throwable $e) {}
} catch (PDOException $e) {
    error_log('db.php — conexión PDO falló: ' . $e->getMessage());
    http_response_code(500);
    if (APP_ENV === 'local') {
        die('Error de conexión a la base de datos: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
    }
    die('Error de conexión a la base de datos. Intenta de nuevo en un momento.');
}
