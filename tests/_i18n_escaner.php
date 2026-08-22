<?php
// ============================================================
//  CRECER — EL ESCÁNER DE PROCEDENCIA  ·  tests/_i18n_escaner.php
//
//  QUÉ PROBLEMA RESUELVE, Y POR QUÉ NO ES «BUSCAR PALABRAS EN ESPAÑOL»
//
//  Una prueba que solo busque castellano en la página renderizada NO PUEDE
//  funcionar aquí, y no por falta de esfuerzo: en Crecer hay español que es
//  CORRECTO que salga en español aunque la interfaz esté en inglés.
//
//    · el nombre del negocio del dueño     («Repostería La Bendición»)
//    · lo que escribió el dueño            (su voz, sus notas, su contexto)
//    · lo que escribió la IA               (los captions SON el producto)
//    · los nombres propios                 (Crecer, Encuéntralo)
//    · los municipios                      (Bayamón, Caguas)
//
//  Marcar eso como «falta traducir» sería ruido, y una prueba con ruido se
//  acaba desactivando. Peor: obligaría a traducir el nombre del negocio de un
//  cliente, que es exactamente lo que nunca se puede hacer.
//
//  LO QUE SÍ SE PUEDE DECIDIR ES LA PROCEDENCIA. Una cadena visible viene de
//  uno de tres sitios, y el fuente lo dice sin ambigüedad:
//
//    1. CATÁLOGO   — pasó por t() / tc(). Es interfaz y está declarada.
//                    Se comprueba que exista en `lang/es` Y en `lang/en`.
//    2. DATO       — es una interpolación (un echo de PHP). No es una cadena de
//                    interfaz: es contenido. NO SE MIRA NUNCA.
//    3. LITERAL    — está escrita a mano en el archivo, en sitio visible.
//                    Esto es lo que hay que arreglar.
//
//  Por eso esto lee el FUENTE con el tokenizador de PHP y no el HTML servido.
//  El tokenizador distingue `T_INLINE_HTML` (plantilla) de una interpolación
//  con total precisión — una regex sobre el render no puede: ahí ya se perdió
//  de dónde vino cada letra.
//
//  El idioma solo entra DESPUÉS, y como segundo filtro sobre los literales:
//  un literal en sitio visible que además parece prosa castellana. Las dos
//  condiciones, no una. Un `class="btn"` es literal pero no es prosa; el
//  nombre de un negocio es prosa pero no es literal.
//
//  CERO base de datos, cero red, cero proveedores: esto lee archivos.
// ============================================================

/** Atributos que el usuario LEE. Misma lista que el runtime, a propósito. */
const ESC_ATTRS = ['placeholder', 'title', 'alt', 'aria-label'];

/** Marcador de «aquí había PHP». Un byte que no aparece en fuente de verdad. */
const ESC_DATO = "\x00";

// ════════════════════════════════════════════════════════════
//  1 · ¿ESTO ES PROSA DE INTERFAZ EN CASTELLANO?
// ════════════════════════════════════════════════════════════
//  Cada guardia de aquí abajo existe por un falso positivo concreto. Se
//  documentan uno a uno: una guardia sin motivo escrito es una guardia que
//  nadie sabe si puede borrar.

/** Cosas que parecen texto pero son código, ruta o dato de máquina. */
function esc_es_codigo(string $v, bool $incluir_identificador = true): bool {
    $t = trim($v);
    if ($t === '') return true;
    // SQL: 'SELECT id, nombre_negocio FROM ...' tiene comas y palabras, pero
    // no lo lee nadie.
    if (preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|SHOW|SET)\b/i', $t)) return true;
    if (preg_match('/\b(FROM|WHERE|VALUES|INNER JOIN|LEFT JOIN|ORDER BY|GROUP BY)\b/i', $t)) return true;
    // Rutas, URLs, tipos MIME, selectores, formatos de fecha.
    if (preg_match('#^(/|\.\./|\./|https?://|data:|mailto:)#i', $t)) return true;
    if (preg_match('#^[a-z0-9_\-/]+\.(php|css|js|png|jpg|jpeg|svg|webp|mp4|json|sql|md)$#i', $t)) return true;
    if (preg_match('#^[a-z]+/[a-z0-9.+\-]+$#i', $t)) return true;               // text/html
    if (preg_match('/^[#.][A-Za-z][\w\-]*$/', $t)) return true;                 // #id .clase
    //  Formatos de fecha: 'Y-m-d', 'd/m/Y', 'H:i'. Se EXIGE un separador de
    //  fecha, porque las letras de format() son tantas que sin ese requisito
    //  «Inicio» y «Tus Posts» pasan por formato de fecha — y son dos etiquetas
    //  del menú. Un falso positivo así esconde una pantalla entera.
    if (preg_match('/^[YmdHisjnDlNwzWtLoAaGghuveIOPTZcrU\\\\\s:\/\-\.,]+$/', $t)
        && preg_match('#[:/\-\\\\]#', $t) && strlen($t) <= 24) return true;
    // CSS suelto que viaja en un literal: 'display:flex;gap:8px'.
    if (preg_match('/^[a-z\-]+\s*:\s*[^;]+;/i', $t) && !preg_match('/[¿¡]/u', $t)) return true;
    // Un identificador solo: 'nombre_negocio', 'meta-cambio', 'aprobado'.
    // Esta guardia SOLO vale cuando no se sabe si la cadena se ve. Si la clave
    // ya dijo que se pinta —'lb' => 'Reels'— escondería justo lo que hay que
    // encontrar: la etiqueta de una sola palabra.
    if ($incluir_identificador && preg_match('/^[a-z0-9_\-]+$/i', $t)) return true;
    return false;
}

/**
 * ¿Parece castellano escrito PARA UNA PERSONA?
 * Pide una marca inequívoca del idioma. No basta con «tener letras»: eso lo
 * cumple el inglés, y traducir inglés a inglés no es un fallo.
 */
function esc_es_castellano(string $v): bool {
    $t = trim($v);
    if (mb_strlen(preg_replace('/[^\p{L}]/u', '', $t) ?? '') < 3) return false;
    if (esc_es_codigo($t)) return false;
    // Marcas duras: letras que el inglés no tiene, o signos que solo usa el español.
    if (preg_match('/[áéíóúüñÁÉÍÓÚÜÑ¿¡]/u', $t)) return true;
    // Marcas blandas: palabra funcional castellana, aislada como palabra.
    return (bool)preg_match(
        '/(?:^|[\s,.;:!¡¿?()«»"\'\-])(el|la|los|las|un|una|unos|unas|de|del|al|que|qué|'
      . 'tu|tus|su|sus|mi|mis|no|se|si|sí|ya|para|con|por|como|cuando|donde|más|menos|'
      . 'está|están|estás|es|son|hay|fue|será|tiene|tienes|puede|puedes|vas|voy|'
      . 'aquí|ahora|todo|toda|todos|todas|nada|algo|otro|otra|pero|porque|sin|sobre)'
      . '(?:$|[\s,.;:!¡¿?()«»"\'\-])/iu', ' ' . $t . ' ');
}

// ════════════════════════════════════════════════════════════
//  2 · EL FUENTE, PARTIDO EN PLANTILLA Y CÓDIGO
// ════════════════════════════════════════════════════════════

/**
 * Junta todo el HTML de plantilla de un archivo en UN solo flujo, sustituyendo
 * cada tramo de PHP por un marcador.
 *
 * Hace falta juntarlo porque una etiqueta se puede abrir en un tramo y cerrar
 * en otro:  <a href="<?= $u ?>" title="Volver">  son TRES tramos de plantilla
 * con PHP en medio. Mirarlos por separado parte la etiqueta y el atributo
 * `title` se pierde — que es justo un atributo que el usuario lee.
 *
 * Devuelve [$flujo, $mapa] donde $mapa es [[offset, linea], ...] creciente.
 */
function esc_flujo(string $fuente): array {
    $tokens = @token_get_all($fuente);
    $flujo  = '';
    $mapa   = [];
    $enPhp  = false;

    foreach ($tokens as $tk) {
        if (is_array($tk) && $tk[0] === T_INLINE_HTML) {
            $mapa[] = [strlen($flujo), $tk[2]];
            $flujo .= $tk[1];
            $enPhp  = false;
            continue;
        }
        // Un tramo de PHP entero cuenta como UN dato, no como uno por token.
        if (!$enPhp) { $flujo .= ESC_DATO; $enPhp = true; }
    }
    return [$flujo, $mapa];
}

/** La línea del fuente que corresponde a un offset del flujo. */
function esc_linea(array $mapa, int $off, string $flujo): int {
    $base = 1; $baseOff = 0;
    foreach ($mapa as [$o, $l]) {
        if ($o > $off) break;
        $base = $l; $baseOff = $o;
    }
    return $base + substr_count(substr($flujo, $baseOff, max(0, $off - $baseOff)), "\n");
}

/**
 * Recorre el flujo de plantilla con una máquina de estados y saca lo VISIBLE:
 * nodos de texto y atributos legibles.
 *
 * Estados: TEXTO · ETIQUETA · SCRIPT · STYLE · COMENTARIO.
 * Se necesita la máquina —y no un preg_split— porque el contenido de <script>
 * no es texto visible (es código, y traducirlo lo rompe) pero SÍ contiene
 * cadenas que el usuario lee, y hay que sacarlas por otra puerta.
 */
function esc_visibles(string $flujo, array $mapa): array {
    $out = []; $n = strlen($flujo); $i = 0;

    while ($i < $n) {
        // ── comentario HTML ──
        if (substr($flujo, $i, 4) === '<!--') {
            $f = strpos($flujo, '-->', $i);
            $i = ($f === false) ? $n : $f + 3;
            continue;
        }
        // ── script / style ──
        if (preg_match('#^<(script|style)\b#i', substr($flujo, $i, 8), $m)) {
            $etq = strtolower($m[1]);
            $abre = strpos($flujo, '>', $i);
            if ($abre === false) break;
            $cierra = stripos($flujo, '</' . $etq, $abre);
            $cuerpo = substr($flujo, $abre + 1, ($cierra === false ? $n : $cierra) - $abre - 1);
            if ($etq === 'script') {
                foreach (esc_cadenas_js($cuerpo) as [$txt, $rel, $sink]) {
                    $out[] = ['texto' => $txt, 'donde' => 'js', 'sink' => $sink,
                              'linea' => esc_linea($mapa, $abre + 1 + $rel, $flujo)];
                }
            }
            $i = ($cierra === false) ? $n : $cierra;
            continue;
        }
        // ── etiqueta ──
        if ($flujo[$i] === '<') {
            $f = strpos($flujo, '>', $i);
            if ($f === false) break;
            $tag = substr($flujo, $i, $f - $i + 1);
            foreach (esc_attrs($tag) as [$txt, $rel]) {
                $out[] = ['texto' => $txt, 'donde' => 'attr',
                          'linea' => esc_linea($mapa, $i + $rel, $flujo)];
            }
            $i = $f + 1;
            continue;
        }
        // ── nodo de texto ──
        $f = strpos($flujo, '<', $i);
        $f = ($f === false) ? $n : $f;
        $txt = substr($flujo, $i, $f - $i);
        // El marcador de PHP corta el nodo: «Hola», el echo del nombre, «¿cómo estás?» son
        // dos literales distintos con un dato en medio. Se miran por separado.
        $off = $i;
        foreach (explode(ESC_DATO, $txt) as $pedazo) {
            $limpio = html_entity_decode($pedazo, ENT_QUOTES, 'UTF-8');
            if (trim($limpio) !== '') {
                $out[] = ['texto' => trim(preg_replace('/\s+/u', ' ', $limpio) ?? ''),
                          'donde' => 'html', 'linea' => esc_linea($mapa, $off, $flujo)];
            }
            $off += strlen($pedazo) + 1;
        }
        $i = $f;
    }
    return $out;
}

/** Los atributos legibles de una etiqueta, con su offset dentro de ella. */
function esc_attrs(string $tag): array {
    $out = [];
    $rx  = '/\b(' . implode('|', ESC_ATTRS) . ')\s*=\s*(["\'])(.*?)\2/is';
    if (preg_match_all($rx, $tag, $mm, PREG_OFFSET_CAPTURE)) {
        foreach ($mm[3] as $k => [$v, $off]) {
            foreach (explode(ESC_DATO, $v) as $pedazo) {
                if (trim($pedazo) !== '') $out[] = [trim($pedazo), $off];
            }
        }
    }
    // El texto de un botón es texto aunque viaje en value=.
    if (preg_match('/<input\b/i', $tag)
        && preg_match('/type\s*=\s*(["\'])(submit|button|reset)\1/i', $tag)
        && preg_match('/\bvalue\s*=\s*(["\'])(.*?)\1/is', $tag, $m, PREG_OFFSET_CAPTURE)) {
        if (trim($m[2][0]) !== '') $out[] = [trim($m[2][0]), $m[2][1]];
    }
    return $out;
}

//  DÓNDE VA A PARAR UNA CADENA DE JAVASCRIPT.
//
//  Con el HTML basta la posición: estar fuera de una etiqueta ya significa
//  «esto se lee». En JavaScript no: la inmensa mayoría de los literales son
//  identificadores —'click', 'show', '#burger'— y exigir que pasen por el
//  catálogo sería pedir que se traduzca el nombre de un evento.
//
//  Así que se mira A QUÉ SE LE ASIGNA. Lo que entra en textContent o en un
//  alert() lo lee una persona; lo que entra en querySelector o addEventListener
//  no lo lee nadie. Cuando el contexto no dice ni una cosa ni la otra, se cae
//  al filtro de idioma — señal más débil, pero declarada como tal y no
//  disimulada.
const ESC_JS_VISIBLE = '/(?:\.(?:textContent|innerText|innerHTML|outerHTML|placeholder|title|alt|ariaLabel|label|value|caption)\s*=\s*|(?:alert|confirm|prompt)\s*\(\s*|insertAdjacentHTML\s*\([^,]*,\s*|\+\s*)$/i';
const ESC_JS_INVISIBLE = '/(?:getElementById|querySelectorAll|querySelector|getElementsBy\w+|addEventListener|removeEventListener|classList\.\w+|setAttribute|getAttribute|removeAttribute|hasAttribute|createElement|closest|matches|localStorage\.\w+|sessionStorage\.\w+|dataset\.\w+\s*=|\.style\.\w+\s*=|typeof\s|===?\s*)\s*\(?\s*$/i';

/**
 * Literales de cadena dentro de un bloque de JavaScript, con su offset y con
 * el destino que se les adivina: 'visible' | 'invisible' | 'incierto'.
 */
function esc_cadenas_js(string $js): array {
    $out = [];
    //  Comillas simples, dobles y plantillas. Sin escapes dentro: una cadena
    //  con \' es rara en copy y prefiero perderla a inventarme un parser.
    foreach (['/\'([^\'\\\\\n]{3,})\'/', '/"([^"\\\\\n]{3,})"/', '/`([^`\\\\]{3,})`/'] as $rx) {
        if (!preg_match_all($rx, $js, $mm, PREG_OFFSET_CAPTURE)) continue;
        foreach ($mm[1] as [$v, $off]) {
            $antes = substr($js, max(0, $off - 60), min(60, $off));
            $sink  = 'incierto';
            if (preg_match(ESC_JS_INVISIBLE, $antes))    $sink = 'invisible';
            elseif (preg_match(ESC_JS_VISIBLE, $antes))  $sink = 'visible';
            $out[] = [trim($v), $off, $sink];
        }
    }
    return $out;
}

// ════════════════════════════════════════════════════════════
//  3 · LOS LITERALES DE PHP, Y SI PASARON POR EL CATÁLOGO
// ════════════════════════════════════════════════════════════

/**
 * Literales de PHP que llegan a la pantalla, marcando cuáles pasaron por t().
 *
 * No se intenta adivinar «esto se imprime»: se mira si es prosa castellana en
 * un literal. Un `'nombre_negocio'` o un `'display:flex'` no lo son. El ruido
 * que quede se declara en tests/i18n_superficies.php, con motivo escrito.
 */
function esc_literales_php(string $fuente): array {
    $tokens = @token_get_all($fuente);
    $out = [];
    $traductoras = ['t', 'tc', 'tj', 't_json'];

    //  DÓNDE EMPIEZA Y ACABA CADA tj(...).
    //  tj() no recibe la cadena como primer argumento sino dentro de un array:
    //  tj(['guardando' => 'Guardando…']). Mirar solo el argumento inmediato
    //  dejaría esas cadenas marcadas como escritas a mano, y la pantalla que
    //  hiciera bien las cosas se quedaría en rojo por hacerlas bien.
    $en_tj = [];
    $prof = null;
    $nivel = 0;
    foreach ($tokens as $k => $tk) {
        if ($tk === '(') { $nivel++; }
        elseif ($tk === ')') { $nivel--; if ($prof !== null && $nivel < $prof) $prof = null; }
        elseif ($prof === null && is_array($tk) && $tk[0] === T_STRING
                && in_array(strtolower($tk[1]), ['tj', 't_json'], true)) {
            $prof = $nivel + 1;   // se abre en el '(' que viene justo detrás
        }
        if ($prof !== null && $nivel >= $prof) $en_tj[$k] = true;
    }

    foreach ($tokens as $k => $tk) {
        if (!is_array($tk) || $tk[0] !== T_CONSTANT_ENCAPSED_STRING) continue;
        $crudo = $tk[1];
        $txt   = substr($crudo, 1, -1);
        $txt   = str_replace(["\\'", '\\"', '\\\\'], ["'", '"', '\\'], $txt);
        if (trim($txt) === '') continue;

        //  ¿ES UN MENSAJE PARA EL LOG, NO PARA UNA PERSONA?
        //  Lo que va a error_log() o dentro de una excepción lo lee quien
        //  depura, en el servidor, y nunca sale a pantalla: traducirlo no
        //  ayuda a nadie y encima ensucia el rastro cuando hay que leerlo.
        //  Va aquí y no en una exclusión por archivo porque no es una
        //  excepción de conveniencia: es que esas cadenas NO son interfaz.
        $ant = '';
        for ($j = max(0, $k - 8); $j < $k; $j++) {
            $ant .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
        }
        if (preg_match('/(?:error_log|trigger_error|new\s+\w*(?:Exception|Error)|assert)\s*\(\s*$/i', $ant)) {
            continue;
        }

        //  ¿Es el primer argumento de una función traductora?
        //  Se mira hacia atrás saltando espacios: ... T_STRING '(' AQUÍ
        $via = 'ninguna';
        $j = $k - 1;
        while ($j >= 0 && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) $j--;
        if ($j >= 0 && $tokens[$j] === '(') {
            $j--;
            while ($j >= 0 && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT], true)) $j--;
            if ($j >= 0 && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING
                && in_array(strtolower($tokens[$j][1]), $traductoras, true)) {
                $via = 'catalogo';
            }
        }
        //  ¿A qué clave se está asignando?  'lb' => 'Finanzas'
        //  Hace falta porque un literal de PHP no delata si se ve o no. Su
        //  posición no lo dice —al revés que la plantilla, donde estar fuera de
        //  una etiqueta YA significa «esto se lee». La clave sí lo dice: lo que
        //  se asigna a 'lb' o a 'err' acaba en la pantalla, en el idioma que
        //  sea. Ver ESC_CLAVES_VISIBLES.
        $clave = '';
        $j = $k - 1;
        while ($j >= 0 && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j--;
        if ($j >= 0 && is_array($tokens[$j]) && $tokens[$j][0] === T_DOUBLE_ARROW) {
            $j--;
            while ($j >= 0 && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j--;
            if ($j >= 0 && is_array($tokens[$j]) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
                $clave = strtolower(trim(substr($tokens[$j][1], 1, -1)));
            }
        }

        //  Dentro de tj(): el VALOR es la clave de catálogo; la clave del array
        //  es solo el nombre de la propiedad en JS y no la lee nadie.
        if (isset($en_tj[$k])) {
            if ($clave !== '') { $via = 'catalogo'; }
            else { continue; }
        }

        $out[] = ['texto' => trim(preg_replace('/\s+/u', ' ', $txt) ?? ''),
                  'donde' => 'php', 'linea' => $tk[2], 'via' => $via, 'clave' => $clave];
    }
    return $out;
}

// ════════════════════════════════════════════════════════════
//  4 · LA PASADA COMPLETA DE UN ARCHIVO
// ════════════════════════════════════════════════════════════
/**
 * Claves de array cuyo valor ACABA EN LA PANTALLA. Lista declarada, no
 * adivinada: cada una está aquí porque se comprobó que se pinta. Si mañana
 * alguien inventa 'rotulo', se añade aquí — y hasta entonces el censo no la ve,
 * que es mejor que fingir que la vio.
 */
const ESC_CLAVES_VISIBLES = [
    'lb', 'label', 'etiqueta', 'titulo', 'title', 'texto', 'txt', 'msg',
    'mensaje', 'err', 'error', 'desc', 'descripcion', 'placeholder', 'pie',
    'nota', 'aviso', 'cta', 'consecuencia', 'sub', 'subtitulo', 'ayuda',
];

/**
 * Todas las cadenas de INTERFAZ del archivo, ya clasificadas.
 *
 * DOS MODOS, Y LA DIFERENCIA ES DELIBERADA:
 *
 *   'censo'   — para una superficie que todavía no se ha migrado. Filtra por
 *               idioma para que el número signifique algo: sin ese filtro,
 *               contar «lo que falta» devuelve miles de `class="x"` y el
 *               backlog deja de ser legible. Es una MEDIDA, no una barrera.
 *
 *   'exigido' — para una superficie ya migrada. AQUÍ NO SE MIRA EL IDIOMA.
 *               Toda cadena visible tiene que venir del catálogo, escrita en
 *               lo que sea. Es lo único que caza «Finanzas», «Reels» o
 *               «Inicio»: son español, pero no llevan tilde ni artículo y
 *               ningún detector de idioma honesto los distinguiría de un
 *               nombre propio. Lo que legítimamente no se traduce —Crecer,
 *               Encuéntralo— se declara, con motivo, en i18n_superficies.php.
 *
 * El modo exigido es el que de verdad manda. El censo solo existe para poder
 * llegar hasta él sin quedarse ciego por el camino.
 */
function esc_archivo(string $ruta, string $modo = 'censo'): array {
    $fuente = (string)@file_get_contents($ruta);
    if ($fuente === '') return [];

    [$flujo, $mapa] = esc_flujo($fuente);
    $cadenas = esc_visibles($flujo, $mapa);
    foreach ($cadenas as &$c) { $c['via'] = 'ninguna'; $c['clave'] = ''; }
    unset($c);                                    // la plantilla nunca pasa por t()
    $cadenas = array_merge($cadenas, esc_literales_php($fuente));

    $out = [];
    foreach ($cadenas as $c) {
        if ($c['via'] === 'catalogo') { $out[] = $c; continue; }

        if ($modo === 'exigido') {
            //  JavaScript: manda el destino, no la posición. Ver ESC_JS_VISIBLE.
            if ($c['donde'] === 'js') {
                if (($c['sink'] ?? '') === 'invisible') continue;
                if (($c['sink'] ?? '') === 'visible'
                    && mb_strlen(preg_replace('/[^\p{L}]/u', '', $c['texto']) ?? '') >= 2) { $out[] = $c; continue; }
                //  'incierto' cae al filtro de idioma, abajo.
            }
            //  Plantilla: estar ahí ya significa que se lee. Sin filtro de idioma.
            elseif ($c['donde'] !== 'php') {
                if (mb_strlen(preg_replace('/[^\p{L}]/u', '', $c['texto']) ?? '') >= 2) $out[] = $c;
                continue;
            }
            //  Literal de PHP: se exige si va a una clave que se pinta, o si
            //  además parece castellano. La clave manda sobre el idioma.
            if (in_array($c['clave'], ESC_CLAVES_VISIBLES, true) && !esc_es_codigo($c['texto'], false)) { $out[] = $c; continue; }
        }

        if (esc_es_castellano($c['texto'])) $out[] = $c;
    }
    return $out;
}
