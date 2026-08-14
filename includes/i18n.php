<?php
// ============================================================
//  CRECER — EL TRADUCTOR DE LA SALIDA  ·  includes/i18n.php
//
//  Para qué existe: el jurado del XPRIZE no habla español y el producto vive
//  entero en español boricua. Hacía falta que el juez pudiera NAVEGAR la app
//  sin que el producto dejara de ser lo que es.
//
//  POR QUÉ NO SE ENVOLVIÓ CADA CADENA A MANO:
//  el recorrido del juez solo son ~1,000 cadenas repartidas en archivos de
//  hasta 2,400 líneas de HTML y PHP mezclados. Editarlos uno por uno, a cuatro
//  días de entregar, es la forma más segura de romper producción. Esto
//  intercepta el HTML YA RENDERIZADO y traduce los nodos de texto contra un
//  diccionario. Las páginas no se tocan.
//
//  LAS TRES GARANTÍAS (en este orden de importancia):
//   1. En español —el default— este archivo NO HACE NADA. Ni siquiera abre el
//      buffer. La ruta que usan los clientes reales queda byte por byte igual
//      que antes de que esto existiera.
//   2. Cadena sin traducir = se queda en español. Nunca un hueco, nunca un
//      error, nunca una clave cruda tipo "panel.home.title" en pantalla.
//   3. NO se traduce lo que escribió la IA. Los captions, los planes y las
//      respuestas del corillo son el producto y son la evidencia: salen en
//      boricua siempre. No están en el diccionario, así que pasan intactos.
//
//  LO QUE NUNCA TOCA: el contenido de <script> y <style>, los comentarios
//  HTML, y cualquier respuesta que no sea text/html (los endpoints JSON de los
//  workers y del panel siguen devolviendo exactamente lo mismo).
// ============================================================

if (!defined('I18N_IDIOMAS')) define('I18N_IDIOMAS', 'es,en');

// LETRAS propias mínimas que necesita una clave con %s para valer como patrón.
// Se cuentan letras, no caracteres: los espacios, los puntos y los separadores
// no hacen específico a un patrón. «de %s · %s» tiene 10 caracteres pero solo
// dos letras suyas —"de"— y se tragaría cualquier frase que empiece así,
// incluida la que escribió la IA. Ver el candado en i18n_diccionario().
if (!defined('I18N_PATRON_MIN')) define('I18N_PATRON_MIN', 4);

/** Las letras que la clave aporta por su cuenta, sin los %s ni la puntuación. */
function i18n_letras_propias(string $clave): int {
    $sin = str_replace('%s', '', $clave);
    return mb_strlen(preg_replace('/[^\p{L}\p{N}]/u', '', $sin) ?? '');
}

// ── El idioma de este request ───────────────────────────────
//  Orden: ?lang= (y se recuerda) → sesión → cookie → español.
//  Se recuerda en cookie de un año para que el juez no tenga que arrastrar el
//  ?lang=en por toda la app.
function i18n_idioma(): string {
    static $lang = null;
    if ($lang !== null) return $lang;

    $validos = explode(',', I18N_IDIOMAS);
    $pedido  = strtolower(trim((string)($_GET['lang'] ?? '')));

    if ($pedido !== '' && in_array($pedido, $validos, true)) {
        $lang = $pedido;
        if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['crecer_lang'] = $lang;
        if (!headers_sent()) {
            setcookie('crecer_lang', $lang, [
                'expires'  => time() + 31536000,
                'path'     => '/',
                'secure'   => (($_SERVER['HTTPS'] ?? '') !== ''),
                'httponly' => false,          // el front puede leerlo para el toggle
                'samesite' => 'Lax',
            ]);
        }
        return $lang;
    }

    $sesion = (session_status() === PHP_SESSION_ACTIVE) ? ($_SESSION['crecer_lang'] ?? '') : '';
    if ($sesion !== '' && in_array($sesion, $validos, true)) return $lang = $sesion;

    $cookie = strtolower(trim((string)($_COOKIE['crecer_lang'] ?? '')));
    if ($cookie !== '' && in_array($cookie, $validos, true)) return $lang = $cookie;

    return $lang = 'es';
}

/** ¿Hay que traducir algo en este request? En español: no. */
function i18n_activo(): bool { return i18n_idioma() !== 'es'; }

// ── La normalización ────────────────────────────────────────
//  EL EXTRACTOR Y EL RUNTIME TIENEN QUE USAR ESTA MISMA FUNCIÓN, o las claves
//  del diccionario no pegan nunca con el texto real. Por eso vive aquí y no en
//  el script que genera el diccionario.
//  Colapsa los espacios (el HTML indentado mete saltos de línea dentro del
//  texto) y recorta. Nada más: los acentos y la puntuación son parte de la clave.
function i18n_clave(string $t): string {
    return trim(preg_replace('/\s+/u', ' ', $t) ?? '');
}

// ── El diccionario ──────────────────────────────────────────
//  Se parte en dos: las claves EXACTAS (la mayoría) y los PATRONES.
//
//  Los patrones existen por un problema real del método: en el fuente, una
//  frase con un echo incrustado en medio —«Hola», el nombre, «¿cómo estás?»—
//  son dos pedazos de texto, pero al RENDERIZAR es UN SOLO nodo («Hola Manuel,
//  ¿cómo estás?») que no pega con ninguna clave estática. Una clave con %s sí
//  lo captura y le devuelve el dato a su sitio: «Hello Manuel, how are you?».
//
//  %s = un pedazo dinámico (nombre, número, fecha). En el inglés se reusan en
//  el mismo orden en que aparecen.
/**
 * Convierte los pares del archivo de idioma en la estructura que usa el motor.
 * Vive aparte de i18n_diccionario() para que las pruebas compilen con ESTE
 * código y no con una copia: un diccionario de mentira que se compila distinto
 * prueba otra cosa que la que corre en producción.
 */
function i18n_compilar(array $pares): array {
    $exactas = []; $patrones = [];
    // Se re-normaliza la clave al cargar: así una entrada escrita a mano con un
    // espacio de más sigue pegando.
    foreach ($pares as $es => $en) {
        $k = i18n_clave((string)$es);
        if ($k === '' || $en === '') continue;
        if (strpos($k, '%s') === false && strpos($k, '%_') === false) {
            $exactas[$k] = (string)$en; continue;
        }

        // CANDADO CONTRA PATRONES DEMASIADO ANCHOS.
        // «de %s» matchearía CUALQUIER nodo que empiece con "de " — incluido un
        // caption escrito por la IA, que es justo lo que nunca se puede tocar.
        // Un patrón necesita letras propias suficientes para ser reconocible; si
        // no las tiene, se ignora y esa cadena sale en español. Callar es más
        // barato que mutilar.
        if (i18n_letras_propias(str_replace('%_', '', $k)) < I18N_PATRON_MIN) continue;

        // Se escapa TODA la clave (los signos de interrogación, los acentos y
        // los paréntesis del copy romperían la regex) y solo después se
        // devuelven los marcadores a su forma de grupo. Se guarda el ORDEN en
        // que aparecen: la sustitución tiene que saber cuál capturado va al
        // inglés y cuál se tira.
        preg_match_all('/%[s_]/', $k, $mm);
        $rx = preg_quote($k, '/');
        $rx = str_replace(preg_quote('%s', '/'), '(.+?)', $rx);   // dato: 1 o más
        $rx = str_replace(preg_quote('%_', '/'), '(.*?)', $rx);   // descarte: puede ser vacío
        $patrones[] = ['rx' => '/^' . $rx . '$/u', 'en' => (string)$en, 'tipos' => $mm[0]];
    }
    return ['exactas' => $exactas, 'patrones' => $patrones];
}

function i18n_diccionario(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $lang = i18n_idioma();
    $ruta = dirname(__DIR__) . '/lang/' . preg_replace('/[^a-z]/', '', $lang) . '.php';
    if ($lang === 'es' || !is_file($ruta)) return $cache = ['exactas' => [], 'patrones' => []];

    $cargado = require $ruta;
    return $cache = i18n_compilar(is_array($cargado) ? $cargado : []);
}

/**
 * Busca la traducción de un texto ya normalizado. Primero exacta (barato),
 * después patrones. Devuelve null si no hay — y null significa «déjalo en
 * español», nunca «pon un hueco».
 */
function i18n_buscar(string $clave, array $dic): ?string {
    if ($clave === '') return null;
    if (isset($dic['exactas'][$clave])) return $dic['exactas'][$clave];

    foreach ($dic['patrones'] as $p) {
        if (!preg_match($p['rx'], $clave, $m)) continue;
        $en = $p['en'];
        // Los capturados vuelven a su sitio, en orden. Los de tipo %_ se tiran:
        // existen para tragarse algo que el inglés no necesita (la 's' del
        // plural español, que no tiene dónde ir en «ready to publish»).
        for ($i = 1; $i < count($m); $i++) {
            if (($p['tipos'][$i - 1] ?? '%s') === '%_') continue;
            $pos = strpos($en, '%s');
            if ($pos === false) break;
            $en = substr_replace($en, $m[$i], $pos, 2);
        }
        return $en;
    }
    return null;
}

/**
 * Traduce UNA cadena. Para código nuevo y para texto que se arma en PHP
 * (mensajes de error, correos, títulos calculados).
 * Sin traducción → devuelve el español tal cual. Nunca falla.
 */
function t(string $es): string {
    if (!i18n_activo()) return $es;
    $en = i18n_buscar(i18n_clave($es), i18n_diccionario());
    if ($en === null) return $es;
    // Se respeta el espacio original de los bordes (importante dentro de <p>).
    preg_match('/^(\s*)/u', $es, $a);
    preg_match('/(\s*)$/u', $es, $b);
    return ($a[1] ?? '') . $en . ($b[1] ?? '');
}

// ── El filtro de salida ─────────────────────────────────────
//  Atributos que el usuario LEE (y por tanto hay que traducir). `value` no
//  entra a ciegas: traducir el value de un input de datos cambiaría lo que se
//  envía al servidor. Solo se traduce en botones.
const I18N_ATTRS = ['placeholder', 'title', 'alt', 'aria-label'];

function i18n_filtro(string $html): string {
    if (!i18n_activo())        return $html;
    if (trim($html) === '')    return $html;

    // Solo HTML. Si alguien fijó un Content-Type que no es text/html (JSON de
    // los workers, CSV del empaquetador, texto plano de _cache.php), se sale.
    // Sin cabecera explícita PHP sirve text/html, así que ausencia = seguir.
    foreach (headers_list() as $hh) {
        if (stripos($hh, 'content-type:') === 0 && stripos($hh, 'text/html') === false) return $html;
    }
    // Un JSON servido sin cabecera igual se respeta.
    $ini = ltrim($html);
    if ($ini !== '' && ($ini[0] === '{' || $ini[0] === '[')) return $html;

    $dic = i18n_diccionario();
    // Sin diccionario no se inventa nada: sale el español intacto.
    if (!$dic['exactas'] && !$dic['patrones']) return $html;

    // 1) Apartar lo intocable: script, style y comentarios. Se procesa solo lo
    //    de afuera y luego se vuelve a coser en orden.
    $partes = preg_split(
        '#(<script\b[^>]*>.*?</script>|<style\b[^>]*>.*?</style>|<!--.*?-->)#is',
        $html, -1, PREG_SPLIT_DELIM_CAPTURE
    );
    if ($partes === false) return $html;

    $salida = '';
    foreach ($partes as $i => $parte) {
        // Los impares son los bloques capturados (script/style/comentario): intactos.
        $salida .= ($i % 2) ? $parte : i18n_traducir_bloque($parte, $dic);
    }
    return $salida;
}

/** Traduce nodos de texto y atributos legibles dentro de un bloque de HTML. */
function i18n_traducir_bloque(string $frag, array $dic): string {
    if ($frag === '') return '';

    $trozos = preg_split('/(<[^>]*>)/s', $frag, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($trozos === false) return $frag;

    $out = '';
    foreach ($trozos as $j => $tz) {
        if ($tz === '') continue;

        // Los impares son etiquetas → solo se miran los atributos legibles.
        if ($j % 2) { $out .= i18n_traducir_tag($tz, $dic); continue; }

        // Nodo de texto.
        $en = i18n_buscar(i18n_clave($tz), $dic);
        if ($en === null) { $out .= $tz; continue; }

        // Se conserva el espacio de los bordes: en HTML "Hola <b>tú</b>" el
        // espacio final es significativo y comérselo pega las palabras.
        preg_match('/^(\s*)/u', $tz, $a);
        preg_match('/(\s*)$/u', $tz, $b);
        $out .= ($a[1] ?? '') . $en . ($b[1] ?? '');
    }
    return $out;
}

/** Atributos legibles + el idioma declarado del documento. */
function i18n_traducir_tag(string $tag, array $dic): string {
    // <html lang="es"> → el idioma real, para lectores de pantalla y traductores.
    if (stripos($tag, '<html') === 0) {
        $tag = preg_replace('/\blang\s*=\s*(["\'])[^"\']*\1/i', 'lang="' . i18n_idioma() . '"', $tag, 1) ?? $tag;
    }

    if (strpos($tag, '=') === false) return $tag;

    $attrs = implode('|', I18N_ATTRS);
    $tag = preg_replace_callback(
        '/\b(' . $attrs . ')\s*=\s*(["\'])(.*?)\2/is',
        function ($m) use ($dic) {
            $en = i18n_buscar(i18n_clave($m[3]), $dic);
            return $en === null ? $m[0] : $m[1] . '=' . $m[2] . $en . $m[2];
        },
        $tag
    ) ?? $tag;

    // El texto de un botón sí es texto, aunque viaje en value=.
    if (preg_match('/<input\b/i', $tag) && preg_match('/type\s*=\s*(["\'])(submit|button|reset)\1/i', $tag)) {
        $tag = preg_replace_callback(
            '/\bvalue\s*=\s*(["\'])(.*?)\1/is',
            function ($m) use ($dic) {
                $en = i18n_buscar(i18n_clave($m[2]), $dic);
                return $en === null ? $m[0] : 'value=' . $m[1] . $en . $m[1];
            },
            $tag
        ) ?? $tag;
    }
    return $tag;
}

// ── El interruptor ──────────────────────────────────────────
/** La URL de ahora mismo con otro idioma (conserva el resto del query). */
function i18n_url(string $lang): string {
    $uri   = (string)($_SERVER['REQUEST_URI'] ?? '/crecer/');
    $parte = explode('?', $uri, 2);
    $qs    = [];
    if (isset($parte[1])) parse_str($parte[1], $qs);
    $qs['lang'] = $lang;
    return $parte[0] . '?' . http_build_query($qs);
}

/**
 * El interruptor ES | EN. Sin emojis y sin banderas —una bandera dice país, no
 * idioma, y aquí el país es justamente lo que no queremos confundir.
 * El estilo va inline: así funciona en las páginas públicas y en el panel sin
 * depender de que una hoja de estilo llegue primero.
 */
function i18n_toggle_html(): string {
    $lang = i18n_idioma();
    $btn  = function (string $code, string $etq) use ($lang) {
        $on = ($lang === $code);
        $st = 'display:inline-block;padding:3px 9px;border-radius:999px;font:700 11px/1.5 system-ui,sans-serif;'
            . 'letter-spacing:.04em;text-decoration:none;transition:background .15s,color .15s;'
            . ($on ? 'background:#231F20;color:#fff;' : 'background:transparent;color:#6b7280;');
        return '<a href="' . htmlspecialchars(i18n_url($code), ENT_QUOTES, 'UTF-8') . '"'
             . ' style="' . $st . '"'
             . ($on ? ' aria-current="true"' : '')
             . ' hreflang="' . $code . '" rel="nofollow">' . $etq . '</a>';
    };
    return '<span class="i18n-toggle" style="display:inline-flex;gap:2px;padding:2px;'
         . 'border:1px solid rgba(0,0,0,.12);border-radius:999px;background:#fff;vertical-align:middle">'
         . $btn('es', 'ES') . $btn('en', 'EN') . '</span>';
}

// ── El arranque ─────────────────────────────────────────────
/**
 * Abre el buffer de salida SOLO si hay que traducir. El callback que se le pasa
 * a ob_start lo corre PHP al terminar el request — también cuando la página
 * hace exit() a mitad, que es como salen medio panel y todos los workers.
 *
 * En español no se abre buffer ninguno: cero sobrecarga, cero riesgo, la ruta
 * del cliente real intacta.
 */
function i18n_arrancar(): void {
    static $ya = false;
    if ($ya || PHP_SAPI === 'cli') return;
    $ya = true;
    if (!i18n_activo()) return;
    ob_start('i18n_filtro');
}
