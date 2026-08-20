<?php
// ============================================================
//  CRECER — LANZADOR DE crear_post_muestra() PARA PRUEBAS
//  tests/_agentes_runner.php
//
//  Corre la función de verdad de includes/agentes.php, que es la que
//  crea el primer post del negocio. Ahí estaba el otro camino que
//  podía generar dos imágenes: al no recibir id, caía al motor viejo
//  sin preguntarse si el trabajo se había creado igual.
//
//  Imprime el id de la pieza creada, para que la prueba la mire y la
//  limpie después.
//
//    php tests/_agentes_runner.php <marca> <sim>
// ============================================================

$mid = (int)($argv[1] ?? 0);
$GLOBALS['AW_SIM'] = (string)($argv[2] ?? '');
if (!$mid) { fwrite(STDERR, "uso: agentes_runner <marca> <sim>\n"); exit(2); }

require __DIR__ . '/_sin_gasto.php';
require dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/agentes.php';

$cid = crear_post_muestra($pdo, $mid);
echo "PIEZA={$cid}\n";
