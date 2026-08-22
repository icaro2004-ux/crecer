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

require_once dirname(__DIR__) . '/core/I18n/Locale.php';
require_once dirname(__DIR__) . '/core/I18n/Catalogo.php';

// ── El idioma de este request ───────────────────────────────
//  YA NO SE DECIDE AQUÍ. Locale es la fuente única, y esto es su puerta para
//  el código que ya la llamaba por este nombre.
//
//  Antes vivía aquí una precedencia propia (?lang → sesión → cookie → es) y
//  la preferencia moría en el navegador: no cruzaba de un teléfono a otro ni
//  sobrevivía a limpiar cookies. Ahora manda lo que el usuario guardó, y la
//  cookie pasa a ser lo que siempre debió ser: el recuerdo de quien todavía
//  no ha iniciado sesión.
function i18n_idioma(): string { return Locale::interfaz(); }

/** ¿Hay que traducir algo en este request? En español: no. */
function i18n_activo(): bool { return Locale::traduciendo(); }

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

function i18n_diccionario(?string $lang = null): array {
    static $cache = [];
    $lang = $lang ?? i18n_idioma();
    if (isset($cache[$lang])) return $cache[$lang];

    $ruta = dirname(__DIR__) . '/lang/' . preg_replace('/[^a-z]/', '', $lang) . '.php';
    if ($lang === 'es' || !is_file($ruta)) return $cache[$lang] = ['exactas' => [], 'patrones' => []];

    $cargado = require $ruta;
    return $cache[$lang] = i18n_compilar(is_array($cargado) ? $cargado : []);
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
function t(string $es): string { return i18n_a(Locale::interfaz(), $es); }

/**
 * Igual, pero en el idioma de CONTENIDO de una marca — no en el del usuario.
 *
 * Para lo poco que va fijo hacia el público de un negocio y no lo escribe la
 * IA. La diferencia con t() no es de estilo: si esto usara el idioma de la
 * interfaz, un admin revisando en inglés la cuenta de una repostería de
 * Bayamón haría que el texto que ve su clientela saliera en inglés.
 */
function tc(?int $marca_id, string $es): string { return i18n_a(Locale::contenido($marca_id), $es); }

/**
 * El diccionario de una pantalla, serializado para su JavaScript.
 *
 *   <script>window.T = <?= tj(['guardando' => 'Guardando…']) ?>;</script>
 *   boton.textContent = T.guardando;
 *
 * POR QUÉ ASÍ Y NO TRADUCIENDO EN EL NAVEGADOR: hoy hay 609 cadenas dentro de
 * <script> que el filtro de salida no puede tocar —salta <script> a propósito,
 * porque traducir código lo rompe—. La respuesta NO es un segundo diccionario
 * en el cliente ni reemplazo de texto en el DOM: es que el JS deje de tener
 * texto. El PHP traduce y el JS recibe.
 *
 * La clave corta es solo el nombre de la propiedad en JS; la clave de catálogo
 * sigue siendo el español, igual que en t().
 */
function tj(array $pares): string {
    $out = [];
    foreach ($pares as $js => $es) $out[(string)$js] = t((string)$es);
    //  JSON_HEX_TAG: un '<' dentro de una traducción no puede cerrar el
    //  <script> que la contiene.
    return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?: '{}';
}

/**
 * El motor de las tres. Catálogo primero, diccionario viejo después.
 *
 * Los dos conviven a propósito mientras dura la migración: lo ya migrado vive
 * en lang/es|en/<dominio>.php, y lo que todavía no —el 95% de la app— sigue
 * apoyado en las 749 entradas del diccionario plano. Quitarlo hoy dejaría en
 * español lo poco que hoy sí se traduce.
 */
function i18n_a(string $lang, string $es): string {
    $clave = i18n_clave($es);

    $tr = Catalogo::buscar($lang, $clave);
    if ($tr === null && $lang !== 'es') {
        //  El puente al diccionario plano. Solo tiene inglés: en español no
        //  hay nada que buscar, la clave YA es el español.
        $tr = i18n_buscar($clave, i18n_diccionario($lang));
    }
    if ($tr === null) return $es;

    // Se respeta el espacio original de los bordes (importante dentro de <p>).
    preg_match('/^(\s*)/u', $es, $a);
    preg_match('/(\s*)$/u', $es, $b);
    return ($a[1] ?? '') . $tr . ($b[1] ?? '');
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
function i18n_url(string $lang): string { return Locale::url($lang); }

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
function i18n_arrancar(?PDO $pdo = null): void {
    static $ya = false;
    if ($ya || PHP_SAPI === 'cli') return;
    $ya = true;
    Locale::montar($pdo);

    //  OJO CON EL ORDEN: aquí NO se puede preguntar el idioma. La sesión
    //  todavía no está abierta (session_start() vive en auth.php, que se
    //  incluye después de db.php), así que resolver ahora dejaría memorizado
    //  un idioma decidido sin ver al usuario — y quien tuviera inglés guardado
    //  leería el contenido en español con el menú en inglés. Justo la
    //  incoherencia que se está corrigiendo.
    //
    //  Se pregunta lo único que se puede saber ya: si existe ALGUNA
    //  posibilidad de que este request no sea español. Si la hay, se abre el
    //  buffer y el idioma se decide al vaciarlo, al final del request, con la
    //  sesión ya abierta. Un buffer abierto de más no traduce nada: el filtro
    //  se sale en la primera línea.
    if (!Locale::puedeNoSerDefecto()) return;
    ob_start('i18n_filtro');
}
