<?php
// ============================================================
//  DIAGNÓSTICO TEMPORAL — ¿por qué IG rechaza la imagen?
//  scripts/_diag_jpeg.php   (BORRAR después de usar)
//
//  Lista los últimos posts con gráfica: su estado, la ruta de la
//  imagen, el ERROR COMPLETO que guardó al fallar, y corre el fix
//  (asegurar_jpeg_publicable) sobre cada uno para ver si convierte.
//  Protegido con CRON_TOKEN.
//
//  Uso:  https://TU-DOMINIO/crecer/scripts/_diag_jpeg.php?key=CRON_TOKEN
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/publicador.php';

$token = defined('CRON_TOKEN') ? CRON_TOKEN : '';
$key   = $_GET['key'] ?? '';
if ($token === '' || !hash_equals($token, (string)$key)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "403 — no autorizado (revisa el ?key=).\n";
    exit;
}
header('Content-Type: application/json; charset=utf-8');

$entorno = [
    'php_version'        => PHP_VERSION,
    'gd_cargado'         => extension_loaded('gd'),
    'uploads_path'       => defined('UPLOADS_PATH') ? UPLOADS_PATH : '(no def)',
    'uploads_escribible' => defined('UPLOADS_PATH') ? is_writable(UPLOADS_PATH) : null,
    'CRECER_TEST_EMAILS' => defined('CRECER_TEST_EMAILS') ? CRECER_TEST_EMAILS : '(no definido)',
    'CRECER_DEV_ACTIVAR' => defined('CRECER_DEV_ACTIVAR') ? CRECER_DEV_ACTIVAR : '(no definido)',
];

// ¿Quién es admin en PROD? (la BD de prod es distinta a la local)
$cuentas = $pdo->query("SELECT id, email, rol FROM usuarios ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
// Marcas y su dueño (para saber con qué cuenta se generan/publican)
$marcas = $pdo->query(
    "SELECT m.id, m.nombre_negocio, m.usuario_id, u.email AS dueno_email, u.rol AS dueno_rol
       FROM crecer_marca m LEFT JOIN usuarios u ON u.id = m.usuario_id ORDER BY m.id"
)->fetchAll(PDO::FETCH_ASSOC);

$sep = DIRECTORY_SEPARATOR;
$url_pref = rtrim(UPLOADS_URL, '/');

$rows = $pdo->query(
    "SELECT id, marca_id, plataforma, estado, grafica_path, pub_error, pub_intentos
       FROM crecer_contenido
      WHERE grafica_path IS NOT NULL AND grafica_path <> ''
      ORDER BY id DESC LIMIT 8"
)->fetchAll(PDO::FETCH_ASSOC);

$piezas = [];
foreach ($rows as $r) {
    $in = (string)$r['grafica_path'];
    $rel = (strpos($in, $url_pref) === 0) ? substr($in, strlen($url_pref)) : $in;
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    $src_abs = rtrim(UPLOADS_PATH, '/\\') . $sep . str_replace('/', $sep, $rel);

    $conv = asegurar_jpeg_publicable($in);
    $es_jpg = ($conv !== $in && preg_match('#\.jpe?g$#i', (string)$conv));
    $jpg_ok = null;
    if ($es_jpg) {
        $rel2 = ltrim(str_replace($url_pref, '', (string)$conv), '/');
        $jpg_abs = rtrim(UPLOADS_PATH, '/\\') . $sep . str_replace('/', $sep, $rel2);
        $jpg_ok = is_file($jpg_abs);
    }

    $piezas[] = [
        'id'             => (int)$r['id'],
        'marca_id'       => (int)$r['marca_id'],
        'plataforma'     => $r['plataforma'],
        'estado'         => $r['estado'],
        'intentos'       => (int)$r['pub_intentos'],
        'grafica_path'   => $in,
        'fuente_existe'  => is_file($src_abs),
        'conversion'     => $conv,
        'convirtio_a_jpg'=> (bool)$es_jpg,
        'jpg_en_disco'   => $jpg_ok,
        'url_publica'    => imagen_url_publica($conv),
        'pub_error'      => $r['pub_error'],   // ← el error COMPLETO que guardó al fallar
    ];
}

echo json_encode(['entorno' => $entorno, 'cuentas' => $cuentas, 'marcas' => $marcas, 'piezas' => $piezas],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
