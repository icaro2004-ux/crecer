<?php
// ============================================================
//  CRECER — EL RETRATO DE ANTES Y DESPUES
//  tests/_contadores.php
//
//  Lo que hay que poder afirmar al cerrar una tanda de pruebas: que no se llamo
//  a ningun proveedor, que no se gasto una sola imagen del mes de nadie, y que
//  no quedo basura viva en la base compartida ni en el disco.
//
//  Se imprime en JSON de una linea para poder restarlo:
//
//      php tests/_contadores.php > antes.json
//      ... la tanda ...
//      php tests/_contadores.php > despues.json
//      php tests/_contadores.php --diff antes.json despues.json
//
//  NO ESCRIBE NADA. Solo cuenta.
// ============================================================

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';

/** Cuenta una tabla sin reventar si no existe. */
function _c(PDO $pdo, string $sql): int {
    try { return (int)$pdo->query($sql)->fetchColumn(); }
    catch (Throwable $e) { return -1; }
}

if (($argv[1] ?? '') === '--diff') {
    $a = json_decode((string)@file_get_contents($argv[2] ?? ''), true) ?: [];
    $b = json_decode((string)@file_get_contents($argv[3] ?? ''), true) ?: [];
    $claves = array_unique(array_merge(array_keys($a), array_keys($b)));
    sort($claves);
    $sucio = 0;
    echo "\nCONTADORES · antes → después\n" . str_repeat('=', 58) . "\n";
    foreach ($claves as $k) {
        $x = $a[$k] ?? 0; $y = $b[$k] ?? 0;
        if (!is_numeric($x) || !is_numeric($y)) continue;
        $d = $y - $x;
        if ($d !== 0) $sucio++;
        printf("  %-34s %8s → %-8s %s\n", $k, (string)$x, (string)$y,
               $d === 0 ? '' : ($d > 0 ? "+{$d}" : (string)$d));
    }
    echo str_repeat('=', 58) . "\n";
    echo $sucio === 0 ? "  NADA SE MOVIO\n\n" : "  {$sucio} contadores cambiaron\n\n";
    exit($sucio === 0 ? 0 : 1);
}

$hoy = date('Y-m-01');
$r = [
    //  PROVEEDOR. Lo que de verdad significa «se llamo a alguien».
    'ia_log_total'        => _c($pdo, "SELECT COUNT(*) FROM crecer_ia_log"),
    'ia_log_hoy'          => _c($pdo, "SELECT COUNT(*) FROM crecer_ia_log
                                        WHERE created_at >= CURDATE()"),
    'ia_log_con_costo'    => _c($pdo, "SELECT COUNT(*) FROM crecer_ia_log WHERE costo_usd > 0"),
    'ia_log_openai'       => _c($pdo, "SELECT COUNT(*) FROM crecer_ia_log
                                        WHERE modelo LIKE 'gpt-%' OR modelo LIKE 'dall-e%'"),
    'ia_log_gemini'       => _c($pdo, "SELECT COUNT(*) FROM crecer_ia_log WHERE modelo LIKE 'gemini%'"),

    //  CUOTA. El libro y el cubo.
    'asientos'            => _c($pdo, "SELECT COUNT(*) FROM crecer_img_cuota_asiento"),
    'asientos_vivos'      => _c($pdo, "SELECT COUNT(*) FROM crecer_img_cuota_asiento
                                        WHERE estado IN ('reservado','riesgo')"),
    'asientos_origen_0'   => _c($pdo, "SELECT COUNT(*) FROM crecer_img_cuota_asiento
                                        WHERE operacion IN ('arte_post','realce','slide')
                                          AND (origen_id IS NULL OR origen_id = 0)"),
    'cubo_usadas_mes'     => _c($pdo, "SELECT COALESCE(SUM(usadas),0) FROM crecer_img_cuota_cubo"),

    //  TRABAJO EN VUELO. Un job vivo que nadie cierra es una unidad retenida.
    'jobs_abiertos'       => _c($pdo, "SELECT COUNT(*) FROM crecer_contenido
                                        WHERE img_job IS NOT NULL AND img_job <> ''"),

    //  DATOS. Que la tanda no deje filas vivas de mas.
    'contenido'           => _c($pdo, "SELECT COUNT(*) FROM crecer_contenido"),
    'activos'             => _c($pdo, "SELECT COUNT(*) FROM crecer_activos"),
    'marcas'              => _c($pdo, "SELECT COUNT(*) FROM crecer_marca"),
    'planes'              => _c($pdo, "SELECT COUNT(*) FROM crecer_meta_plan"),
    'metas'               => _c($pdo, "SELECT COUNT(*) FROM crecer_meta"),
    'memoria'             => _c($pdo, "SELECT COUNT(*) FROM crecer_memoria"),

    //  RASTROS DE PRUEBA QUE NO DEBERIAN SOBREVIVIR.
    'fixtures_vivas'      => _c($pdo, "SELECT COUNT(*) FROM crecer_marca
                                        WHERE nombre_negocio LIKE '%prueba%'"),
    'contenido_de_prueba' => _c($pdo, "SELECT COUNT(*) FROM crecer_contenido
                                        WHERE caption LIKE '[prueba]%'"),
    'bases_desechables'   => _c($pdo, "SELECT COUNT(*) FROM information_schema.SCHEMATA
                                        WHERE SCHEMA_NAME LIKE 'crecer_prueba_%'"),
];

//  DISCO. Los perfiles de Chrome y los archivos de uploads.
$tmp = sys_get_temp_dir();
$perfiles = 0;
foreach ((array)@glob($tmp . DIRECTORY_SEPARATOR . 'crecer_chrome*') as $d) $perfiles++;
foreach ((array)@glob($tmp . DIRECTORY_SEPARATOR . 'chrome_prueba*') as $d) $perfiles++;
$r['perfiles_chrome'] = $perfiles;

$subidas = 0;
$base = defined('UPLOADS_PATH') ? UPLOADS_PATH : dirname(__DIR__) . '/uploads';
if (is_dir($base)) {
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,
                  FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($it as $f) if ($f->isFile()) $subidas++;
    } catch (Throwable $e) { $subidas = -1; }
}
$r['archivos_uploads'] = $subidas;

echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
