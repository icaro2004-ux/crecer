<?php
// ============================================================
//  CRECER — ¿CHOCA LA FUNDACION CON UNA CLASE GLOBAL?
//  tests/_colision_runner.php
//
//  Declara clases GLOBALES llamadas Locale y Catalogo —exactamente lo que hace
//  la extension `intl` de PHP, que Hostinger tiene cargada y el XAMPP de
//  desarrollo no— y despues intenta cargar la fundacion.
//
//  VA EN SU PROPIO PROCESO POR NECESIDAD, no por limpieza: «Cannot declare
//  class X, because the name is already in use» es un E_ERROR de declaracion,
//  NO un Throwable. Ningun try/catch lo atrapa y el proceso muere ahi. La
//  unica forma de observarlo sin llevarse la suite por delante es correrlo
//  aparte y mirar como termina.
//
//    php tests/_colision_runner.php <raiz>
//
//  Imprime OK|... o MURIO|... y nada mas.
// ============================================================

$raiz = rtrim((string)($argv[1] ?? ''), '/');
if ($raiz === '' || !is_dir($raiz . '/core/I18n')) { echo "MURIO|no encuentro core/I18n"; exit(1); }

//  El impostor, con la firma de intl.
class Locale {
    const DEFAULT_LOCALE = 0;
    public static function getDefault(): string { return 'es_PR'; }
}
class Catalogo { public static function loQueSea() { return 1; } }

//  Si la fundacion no tuviera namespace, el proceso muere en esta linea y el
//  `echo` de abajo no llega a ejecutarse nunca.
require_once $raiz . '/core/I18n/Locale.php';
require_once $raiz . '/core/I18n/Catalogo.php';

$ok  = class_exists('Crecer\\I18n\\Locale', false)
    && class_exists('Crecer\\I18n\\Catalogo', false);
//  Y las globales tienen que seguir siendo las de fuera: si nuestra clase
//  hubiera ocupado el nombre global, intl dejaria de funcionar en el resto de
//  la aplicacion — romper a la extension es tan malo como que ella nos rompa.
$intacta = (Locale::getDefault() === 'es_PR') && (Catalogo::loQueSea() === 1);

//  Y que de verdad funcionan, no solo que existen.
$vive = false;
try {
    \Crecer\I18n\Locale::olvidar();
    \Crecer\I18n\Locale::montar(null);
    $_GET = []; $_COOKIE = [];
    $vive = (\Crecer\I18n\Locale::interfaz() === 'es');
    \Crecer\I18n\Catalogo::usarRaiz($raiz . '/lang');
    $vive = $vive && (count(\Crecer\I18n\Catalogo::mapa('es')) > 0);
} catch (Throwable $e) { $vive = false; }

echo ($ok && $intacta && $vive ? 'OK' : 'MURIO')
   . '|declaradas=' . ($ok ? 'si' : 'no')
   . ' globales_intactas=' . ($intacta ? 'si' : 'no')
   . ' funcionan=' . ($vive ? 'si' : 'no');
