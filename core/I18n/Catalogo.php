<?php
namespace Crecer\I18n;

//  ══════════════════════════════════════════════════════════════
//   POR QUE ESTE ARCHIVO VIVE EN UN NAMESPACE, Y NO ES ESTILO
//
//   La extension `intl` de PHP declara una clase GLOBAL llamada Locale.
//   Hostinger la tiene cargada; el XAMPP de desarrollo no. Con una clase
//   Locale global sin namespace, incluir este archivo en produccion daba:
//
//     Fatal error: Cannot declare class Locale, because the name is
//     already in use in core/I18n/Locale.php
//
//   Y ESE FATAL NO SE PUEDE ATRAPAR: no es un Throwable, es un E_ERROR de
//   declaracion. Un try/catch alrededor del require no sirve de nada — la
//   pagina muere ahi, entera. Es lo que le paso al diagnostico, que se
//   cortaba justo al llegar a esta linea despues de cargar bien toda la
//   fundacion Meta.
//
//   Un namespace propio lo resuelve de raiz, y ademas es lo correcto:
//   `Locale` y `Catalogo` son nombres que cualquier extension o libreria
//   puede querer. Renombrar a algo como `CrecerLocale` habria esquivado
//   este choque y dejado el siguiente al azar.
//  ══════════════════════════════════════════════════════════════
// ============================================================
//  CRECER — LOS CATÁLOGOS  ·  core/I18n/Catalogo.php
//
//  lang/es/<dominio>.php   y   lang/en/<dominio>.php
//
//  POR QUÉ EXISTE UN CATÁLOGO ESPAÑOL, SI EL PRODUCTO YA ESTÁ EN ESPAÑOL
//
//  Hoy el español es «lo que quede si no hay traducción». Suena eficiente y es
//  justo lo que impide medir: sin un catálogo español no hay contra qué
//  comparar, así que una cadena que falta en inglés es indistinguible de una
//  cadena que nunca se declaró. Con los dos lados escritos, la clave que no
//  esté en ambos ES UN FALLO, y se puede detectar sola.
//
//  LA CLAVE ES EL TEXTO ESPAÑOL, NO UN IDENTIFICADOR
//
//  Nada de 'panel.home.titulo'. Se conserva la mejor propiedad del sistema que
//  ya existía: si falta una traducción, en pantalla sale español legible y
//  nunca una clave cruda. Un identificador roto se ve como 'panel.home.titulo'
//  delante de un cliente; un español sin traducir se ve como español.
//
//  POR DOMINIOS, NO UN ARCHIVO GIGANTE
//
//  El diccionario plano de hoy son 749 entradas en un archivo. Nadie lo revisa
//  entero, así que nadie ve lo que falta. Partido por dominio, revisar «los
//  errores» o «el menú» es una tarea que se puede terminar.
// ============================================================

final class Catalogo
{
    /** Los dominios que existen. Añadir uno es crear los DOS archivos. */
    public const DOMINIOS = ['navegacion', 'comun', 'errores', 'home'];

    private static array $mapas = [];
    private static ?string $raiz = null;

    public static function raiz(): string
    {
        return self::$raiz ?? (self::$raiz = dirname(__DIR__, 2) . '/lang');
    }

    /** Para las pruebas: apunta a otro sitio y olvida lo cargado. */
    public static function usarRaiz(?string $r): void { self::$raiz = $r; self::$mapas = []; }
    public static function olvidar(): void { self::$mapas = []; }

    /** El mapa completo de un idioma: todos sus dominios en uno. */
    public static function mapa(string $lang): array
    {
        if (isset(self::$mapas[$lang])) return self::$mapas[$lang];
        $todo = [];
        foreach (self::DOMINIOS as $d) {
            $ruta = self::raiz() . '/' . preg_replace('/[^a-z]/', '', $lang) . '/' . $d . '.php';
            if (!is_file($ruta)) continue;
            $cargado = require $ruta;
            if (is_array($cargado)) $todo += $cargado;
        }
        return self::$mapas[$lang] = $todo;
    }

    /** ¿Está declarada esta cadena en este idioma? */
    public static function tiene(string $lang, string $clave): bool
    {
        $m = self::mapa($lang);
        return isset($m[$clave]) && $m[$clave] !== '';
    }

    /**
     * La traducción, o null si no está declarada.
     * null significa «déjalo como está», nunca «pon un hueco».
     */
    public static function buscar(string $lang, string $clave): ?string
    {
        $m = self::mapa($lang);
        $v = $m[$clave] ?? null;
        return ($v === null || $v === '') ? null : (string)$v;
    }

    /** Las claves de un dominio concreto, para poder revisarlo entero. */
    public static function deDominio(string $lang, string $dominio): array
    {
        $ruta = self::raiz() . '/' . preg_replace('/[^a-z]/', '', $lang)
              . '/' . preg_replace('/[^a-z_]/', '', $dominio) . '.php';
        if (!is_file($ruta)) return [];
        $c = require $ruta;
        return is_array($c) ? $c : [];
    }
}
