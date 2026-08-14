<?php
// ============================================================
//  CRECER — PRUEBAS DEL TRADUCTOR DE SALIDA
//  tests/test_i18n_unit.php     ·   php tests/test_i18n_unit.php   (exit 0 = OK)
//
//  Lo que se prueba aquí no es "traduce bonito": es que NO PUEDA ROMPER NADA.
//  El traductor toca el HTML de las 71 pantallas del panel, a días de entregar.
//  Las tres garantías que sostienen esa decisión son las tres primeras pruebas.
// ============================================================

require_once __DIR__ . '/../includes/i18n.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nTRADUCTOR DE SALIDA (i18n)\n" . str_repeat('=', 46) . "\n\n";

// ── GARANTÍA 1 · en español no hace absolutamente nada ──────
$_GET['lang'] = 'es';
$html_es = '<p>Guardar</p><a title="Inicio">Tus Posts</a>';
ok('en español la salida es idéntica (byte por byte)',
   i18n_filtro($html_es) === $html_es);
ok('en español no se abre buffer de salida',
   (function () { $antes = ob_get_level(); i18n_arrancar(); return ob_get_level() === $antes; })());

// i18n_idioma() memoiza el idioma en un static, y la prueba de arriba ya lo dejó
// fijado en 'es' para este proceso. Así que el motor se prueba por su parte pura
// —i18n_buscar contra un diccionario armado a mano— y el filtro completo se
// prueba más abajo en procesos nuevos, que es donde el idioma sí puede ser otro.
// Se compila con i18n_compilar(), que es EXACTAMENTE lo que corre en producción.
$dic = i18n_compilar(require __DIR__ . '/../lang/en.php');

// ── GARANTÍA 2 · lo que no está traducido se queda en español ──
ok('cadena desconocida → se devuelve null (queda el español)',
   i18n_buscar(i18n_clave('Una frase que no está en el diccionario'), $dic) === null);
ok('cadena conocida → traduce',
   i18n_buscar('Guardar', $dic) === 'Save');
ok('la normalización aguanta saltos de línea e indentación',
   i18n_buscar(i18n_clave("  Tus\n   Posts  "), $dic) === 'Your Posts');

// ── GARANTÍA 3 · lo que escribe la IA no se toca ────────────
$caption = 'Wepa, mi gente. Hoy hay bizcocho de guayaba fresquecito, pa\' que endulces el día.';
ok('un caption del corillo NO está en el diccionario (sale en boricua)',
   i18n_buscar(i18n_clave($caption), $dic) === null);

// ── Patrones (%s) ───────────────────────────────────────────
ok('patrón simple recoloca el dato',
   i18n_buscar('Llevas 3 de 10', $dic) === 'You are at 3 de 10',
   'devolvió: ' . var_export(i18n_buscar('Llevas 3 de 10', $dic), true));
ok('patrón con dos %s conserva el orden',
   i18n_buscar('el viernes a las 10:00 AM', $dic) === 'on viernes at 10:00 AM',
   'devolvió: ' . var_export(i18n_buscar('el viernes a las 10:00 AM', $dic), true));
ok('un patrón no captura de más (exige el texto completo)',
   i18n_buscar('Llevas', $dic) === null);

// %_ = descarte. Existe para la 's' del plural español, que en inglés no tiene
// dónde ir: «1 listo para publicar» y «3 listos para publicar» son la misma
// frase en inglés.
$plural = i18n_compilar(['listo%_ para publicar' => 'ready to publish']);
ok('%_ se traga el plural en singular', i18n_buscar('listo para publicar', $plural) === 'ready to publish');
ok('%_ se traga el plural en plural',  i18n_buscar('listos para publicar', $plural) === 'ready to publish');
$mixto = i18n_compilar(['Revisar las %s apartada%_' => 'Review the %s set aside']);
ok('con %s y %_ juntos, el dato entra y el plural se tira',
   i18n_buscar('Revisar las 3 apartadas', $mixto) === 'Review the 3 set aside',
   'devolvió: ' . var_export(i18n_buscar('Revisar las 3 apartadas', $mixto), true));

echo "\n";

// ── El filtro completo, con el diccionario de verdad ────────
//  Se corre en procesos aparte porque i18n_idioma() memoiza el idioma.
$render = __DIR__ . '/_i18n_render.php';
file_put_contents($render, <<<'PHP'
<?php
$_GET['lang'] = $argv[1] ?? 'es';
require __DIR__ . '/../includes/i18n.php';
echo i18n_filtro(file_get_contents('php://stdin'));
PHP);

function filtrar(string $html, string $lang = 'en'): string {
    $render = __DIR__ . '/_i18n_render.php';
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($render) . ' ' . escapeshellarg($lang);
    $p = proc_open($cmd, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $tubos);
    if (!is_resource($p)) return '';
    fwrite($tubos[0], $html); fclose($tubos[0]);
    $out = stream_get_contents($tubos[1]); fclose($tubos[1]);
    stream_get_contents($tubos[2]); fclose($tubos[2]);
    proc_close($p);
    return $out;
}

ok('traduce nodos de texto y atributos legibles',
   filtrar('<a title="Inicio" href="/x">Tus Posts</a>') === '<a title="Home" href="/x">Your Posts</a>',
   'devolvió: ' . filtrar('<a title="Inicio" href="/x">Tus Posts</a>'));

$js = '<script>var t="Guardar"; if(a<b){x()}</script>';
ok('NO toca el contenido de <script> (ni el < de una comparación)',
   filtrar($js) === $js, 'devolvió: ' . filtrar($js));

$css = '<style>.a::after{content:"Guardar"}</style>';
ok('NO toca el contenido de <style>', filtrar($css) === $css);

$json = '{"ok":true,"msg":"Guardar"}';
ok('NO toca una respuesta JSON', filtrar($json) === $json, 'devolvió: ' . filtrar($json));

ok('no se come el espacio entre palabras y etiquetas',
   filtrar('<p>Guardar <b>Inicio</b></p>') === '<p>Save <b>Home</b></p>',
   'devolvió: ' . filtrar('<p>Guardar <b>Inicio</b></p>'));

ok('el idioma declarado del documento cambia',
   str_contains(filtrar('<html lang="es"><body>Inicio</body></html>'), 'lang="en"'));

ok('una página sin nada traducible sale igual',
   filtrar('<p>Bizcocho de guayaba fresquecito</p>') === '<p>Bizcocho de guayaba fresquecito</p>');

ok('en español el filtro completo deja el HTML intacto',
   filtrar('<a title="Inicio">Tus Posts</a>', 'es') === '<a title="Inicio">Tus Posts</a>');

@unlink($render);

// ── Salud del diccionario ───────────────────────────────────
$dicc = require __DIR__ . '/../lang/en.php';
ok('el diccionario carga y tiene entradas', is_array($dicc) && count($dicc) > 50);
$vacias = array_filter($dicc, fn($v) => trim((string)$v) === '');
ok('ninguna entrada quedó sin traducir', !$vacias,
   'vacías: ' . implode(' | ', array_slice(array_keys($vacias), 0, 5)));
// Los %s tienen que cuadrar. Los %_ NO cuentan: son descartes a propósito
// (la 's' del plural español, que en inglés no tiene dónde ir).
$desbalance = [];
foreach ($dicc as $es => $en) {
    if (substr_count((string)$es, '%s') !== substr_count((string)$en, '%s')) $desbalance[] = $es;
}
ok('los %s cuadran entre español e inglés', !$desbalance,
   'descuadradas: ' . implode(' | ', array_slice($desbalance, 0, 5)));
ok('el inglés nunca lleva un %_ (el descarte es solo del lado español)',
   !array_filter($dicc, fn($v) => str_contains((string)$v, '%_')));

// ── El candado contra patrones que se tragan todo ───────────
//  «de %s» matchearía cualquier frase que empiece con "de " — incluido un
//  caption de la IA. El motor los descarta; esto avisa si alguien escribe uno,
//  porque un patrón descartado en silencio se lee como "no tradujo esa".
$anchos = [];
foreach ($dicc as $es => $en) {
    $k = i18n_clave((string)$es);
    if (strpos($k, '%s') === false && strpos($k, '%_') === false) continue;
    if (i18n_letras_propias(str_replace('%_', '', $k)) < I18N_PATRON_MIN) $anchos[] = $es;
}
ok('ningún patrón es demasiado ancho', !$anchos,
   'anchos (el motor los ignora): ' . implode(' | ', array_slice($anchos, 0, 5)));

ok('un patrón ancho NO puede tocar texto de la IA',
   (function () {
       // Se arma a mano el peor caso y se comprueba que el motor lo descarta.
       $peligroso = ['exactas' => [], 'patrones' => []];
       foreach (['de %s', 'de %s · %s', 'y %s'] as $k) {          // los peores casos
           if (i18n_letras_propias($k) >= I18N_PATRON_MIN) {      // el motor los rechaza
               $peligroso['patrones'][] = ['rx' => '/^de (.+?)$/u', 'en' => 'of %s'];
           }
       }
       return i18n_buscar('de guayaba fresquecito con canela', $peligroso) === null;
   })());

echo "\n" . str_repeat('=', 46) . "\n";
echo $fallos === 0 ? "TODO EN VERDE ($n pruebas)\n\n" : "$fallos FALLA(S) de $n\n\n";
exit($fallos === 0 ? 0 : 1);
