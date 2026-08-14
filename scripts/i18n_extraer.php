<?php
// ============================================================
//  CRECER — EXTRACTOR DE CADENAS PARA TRADUCIR
//  scripts/i18n_extraer.php   (herramienta de construcción, no corre en prod)
//
//  Saca de las páginas el texto que un usuario LEE, ya normalizado con la misma
//  i18n_clave() que usa el runtime — si no fuera la misma función, las claves
//  del diccionario no pegarían nunca con el HTML renderizado.
//
//  Cómo lee el fuente (se describen sin escribir el tag de cierre: un `?` con
//  `>` dentro de un comentario // corta el PHP y vuelca el archivo entero):
//    un echo corto incrustado   → %s   (un dato en medio de una frase)
//    un bloque php de lógica    → corte (parte el nodo de texto en dos)
//    'texto' / "texto"          → candidato suelto, si tiene pinta de español
//
//  Lo que DESCARTA a propósito: lo que no es para el usuario (clases CSS,
//  rutas, SQL), lo larguísimo (los prompts que se le mandan a Gemini viven en
//  español y NO se traducen: son internos), y lo que no tiene una sola palabra
//  en español.
//
//  Uso:  php scripts/i18n_extraer.php  [--faltantes]  archivo...
//        --faltantes = solo lo que todavía no está en lang/en.php
// ============================================================

require_once __DIR__ . '/../includes/i18n.php';

$args = array_slice($argv, 1);
$solo_faltantes = in_array('--faltantes', $args, true);
$files = array_values(array_filter($args, fn($a) => $a !== '--faltantes'));
if (!$files) { fwrite(STDERR, "uso: php scripts/i18n_extraer.php [--faltantes] archivo...\n"); exit(1); }

// Diccionario actual (para --faltantes). Se lee crudo: aquí no hay request.
$ya = [];
$dic_path = dirname(__DIR__) . '/lang/en.php';
if (is_file($dic_path)) {
    $tmp = require $dic_path;
    if (is_array($tmp)) foreach ($tmp as $es => $en) $ya[i18n_clave((string)$es)] = 1;
}

/**
 * ¿Esto es texto que un humano lee?
 *
 * Se prefiere pasarse de inclusivo: una cadena de más solo cuesta una línea que
 * yo descarto al traducir. Una cadena de MENOS es una etiqueta que se le queda
 * en español al juez sin que nadie se entere. La primera versión exigía una
 * palabra-señal española y se comía media navegación —«Inicio», «Reels»,
 * «Guardar»— que no lleva acento ni artículo.
 */
function parece_es(string $t): bool {
    if (mb_strlen($t) < 2 || mb_strlen($t) > 220) return false;
    if (preg_match('/^[\s\d\W]+$/u', $t)) return false;          // solo símbolos/números
    // slug, clase CSS, ruta. SIN /i a propósito: con insensibilidad esto
    // rechazaba «Inicio», «Reels» y «Biblioteca» —media navegación— por parecer
    // slugs. Un slug de verdad va en minúscula.
    if (preg_match('/^[a-z0-9_\-\.\/#]+$/', $t)) return false;
    if (preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|FROM|WHERE|NULL)\b/', $t)) return false;
    if (str_contains($t, '://') || str_contains($t, '{{')) return false;
    if (preg_match('/^[A-Z0-9_]{3,}$/', $t)) return false;       // CONSTANTE_ASI

    // a) Español inequívoco: acento, signo de apertura, o palabra-señal.
    if (preg_match(
        '/[áéíóúñü¡¿]|\b(el|la|los|las|un|una|de|del|que|para|con|por|sin|tu|tus|su|sus|no|sí|más|ya|qué|cómo|cuándo|dónde|desde|hasta|como|esto|este|esta|esos|todo|todos|cada|hacer|ver|dice|está|están|son|fue|puede|vamos|aquí|ahora|hoy|nuevo|nueva|y|o|al|le|lo)\b/iu',
        $t
    )) return true;

    // b) Etiqueta corta de interfaz: empieza en mayúscula («Inicio», «Guardar»,
    //    «Tus Posts»). Aquí entra algún falso positivo en inglés; se descarta a
    //    mano al escribir el diccionario, que es barato.
    return (bool)preg_match('/^[A-ZÁÉÍÓÚÑ¿¡]/u', $t) && mb_strlen($t) <= 60;
}

$hits = [];   // clave => ['n'=>veces, 'files'=>[...]]
function anota(string $bruto, string $file): void {
    global $hits;
    $k = i18n_clave($bruto);
    if ($k === '' || $k === '%s') return;
    if (!parece_es(preg_replace('/%s/', ' ', $k))) return;
    if (!isset($hits[$k])) $hits[$k] = ['n' => 0, 'files' => []];
    $hits[$k]['n']++;
    $hits[$k]['files'][$file] = 1;
}

foreach ($files as $file) {
    $src = @file_get_contents($file);
    if ($src === false) { fwrite(STDERR, "no se pudo leer: $file\n"); continue; }
    $corto = preg_replace('#^.*/(?=[^/]+/[^/]+$)#', '', str_replace('\\', '/', $file));

    // ── 1) Las cadenas dentro del PHP (mensajes que se imprimen) ──
    if (preg_match_all('/(?<![\w$])[\'"]((?:[^\'"\\\\\n]|\\\\.){3,220})[\'"]/u', $src, $m)) {
        foreach ($m[1] as $lit) {
            $lit = stripcslashes($lit);
            // Una cadena PHP con variables dentro ("Tienes {$n} posts") se
            // imprime resuelta. La clave tiene que ser el PATRÓN, no el fuente,
            // o no pega jamás con el HTML renderizado.
            $lit = preg_replace('/\{\$[^}]+\}|\$[A-Za-z_]\w*(?:->\w+|\[[^\]]*\])*/u', '%s', $lit) ?? $lit;
            // Con espacio es frase; sin espacio solo pasa si va en mayúscula
            // (etiqueta tipo 'Inicio'). Así no entran las claves de array
            // ('marca_id'), los slugs ni los flags, pero sí el menú.
            if (!preg_match('/\s/u', $lit) && !preg_match('/^[A-ZÁÉÍÓÚÑ]/u', $lit)) continue;
            anota($lit, $corto);
        }
    }

    // ── 2) El texto de la plantilla HTML ──
    //  Un echo incrustado es un dato → %s. Un bloque de lógica → corta.
    $tpl = preg_replace('/<\?(?:=|php\s+echo)\s.*?\?>/su', '%s', $src);
    $tpl = preg_replace('/<\?php\b.*?\?>/su', "\x01", $tpl ?? '');
    $tpl = preg_replace('/<\?php\b.*$/su', "\x01", $tpl ?? '');            // bloque sin cerrar al final
    $tpl = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', "\x01", $tpl ?? '');
    $tpl = preg_replace('/<!--.*?-->/s', "\x01", $tpl ?? '');

    $trozos = preg_split('/(<[^>]*>)/s', (string)$tpl, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($trozos as $j => $tz) {
        if ($tz === '') continue;
        if ($j % 2) {   // etiqueta → atributos legibles
            if (preg_match_all('/\b(placeholder|title|alt|aria-label)\s*=\s*(["\'])(.*?)\2/is', $tz, $ma)) {
                foreach ($ma[3] as $v) anota($v, $corto);
            }
            if (preg_match('/<input\b/i', $tz) && preg_match('/type\s*=\s*(["\'])(submit|button|reset)\1/i', $tz)
                && preg_match('/\bvalue\s*=\s*(["\'])(.*?)\1/is', $tz, $mv)) {
                anota($mv[2], $corto);
            }
            continue;
        }
        // nodo de texto: cada pedazo entre cortes de lógica es una frase aparte
        foreach (explode("\x01", $tz) as $frase) anota($frase, $corto);
    }
}

// ── Salida: lo más repetido primero (traducir eso rinde más) ──
uasort($hits, fn($a, $b) => $b['n'] <=> $a['n']);
$n = 0;
foreach ($hits as $k => $info) {
    if ($solo_faltantes && isset($ya[$k])) continue;
    $n++;
    // Formato listo para pegar en lang/en.php
    printf("  %-90s => '',   // x%d · %s\n",
        "'" . str_replace("'", "\\'", $k) . "'",
        $info['n'], implode(' ', array_keys($info['files'])));
}
fwrite(STDERR, "\n-- $n cadenas" . ($solo_faltantes ? ' SIN traducir' : '') . " --\n");
