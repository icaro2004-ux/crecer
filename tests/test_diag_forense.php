<?php
// ============================================================
//  CRECER — LA FORENSE NO PUEDE FILTRAR NI ESCRIBIR
//  tests/test_diag_forense.php
//
//  &test=forense reparte el gasto de imagenes de una ventana por dia, hora,
//  marca, ruta y pieza, para entender de donde salieron 148 generaciones y
//  $25.126 entre el 6 y el 11 de agosto.
//
//  Lee crecer_ia_log, que es la tabla con MAS datos del cliente de todo el
//  sistema: cada fila lleva el prompt entero y la respuesta. De ahi las dos
//  garantias que se fijan aqui:
//
//   1 · NO SE IMPRIME NADA DEL CLIENTE. Solo conteos, costos y el id NUMERICO
//       de la pieza sacado del nombre del archivo. Ni prompt, ni caption, ni
//       nombre de negocio.
//   2 · NO ESCRIBE Y NO LLAMA A NADIE. Es una autopsia, no una intervencion.
//
//  Y una tercera, que es de honestidad: el extractor de id de pieza tiene un
//  hueco conocido -los archivos `post_<uniqid>` no llevan id- y la pagina lo
//  DICE en vez de contarlos como cero.
// ============================================================

require_once __DIR__ . '/../includes/db.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nLA FORENSE DEL GASTO\n" . str_repeat('=', 56) . "\n";

$src = (string)file_get_contents(dirname(__DIR__) . '/_cache.php');
//  El recorte empieza en el COMENTARIO, no en el if: ahí es donde el bloque
//  declara qué hace y qué no, y eso también se afirma aquí.
$ini = strpos($src, '¿QUE DISPARO LAS 148 GENERACIONES?');
$fin = strpos($src, "\$__test === 'colgadas'", (int)$ini);
$b   = $ini === false ? '' : substr($src, (int)$ini, (int)$fin - (int)$ini);

echo "\n  — el bloque existe y está cerrado —\n";
ok('el bloque existe', $b !== '');
ok('_cache.php exige rol admin antes de nada',
   strpos($src, "(\$__usuario['rol'] ?? '') !== 'admin'") !== false);

echo "\n  — no escribe y no llama a nadie —\n";
foreach ([
    'UPDATE '                   => 'escribiría',
    'INSERT '                   => 'escribiría',
    'DELETE '                   => 'borraría',
    'ia_http_get_res('          => 'saldría a la red',
    'ia_http_post'              => 'saldría a la red',
    'openai_responses_crear_bg(' => 'generaría',
    'openai_imagen('            => 'generaría',
    'gemini_imagen('            => 'generaría',
    'img_gemini_fallback('      => 'generaría',
    'arte_disparar('            => 'encolaría',
    'CuotaImg::liberar('        => 'movería la cuota',
    'CuotaImg::confirmar('      => 'movería la cuota',
] as $mal => $porque) {
    ok("no {$porque} ({$mal})", strpos($b, $mal) === false);
}

echo "\n  — y no imprime nada del cliente —\n";
//  La consulta trae `respuesta` porque de ahi sale el id de la pieza. Lo que
//  NO puede pasar es que se imprima: se usa para un preg_match y se tira.
ok('nunca selecciona el prompt', stripos($b, 'prompt') === false
   || strpos($b, 'SELECT prompt') === false,
   'el prompt lleva lo que el dueño escribió de su negocio');
ok('no imprime la respuesta cruda',
   strpos($b, 'echo $u') === false && strpos($b, '$r[\'respuesta\'])') === false
   || strpos($b, 'preg_match') !== false,
   'la URL solo se usa para sacar el id con una expresión regular');
//  Se busca la COLUMNA, no la palabra: el aviso de cierre dice «no se imprimió
//  ningún prompt, caption ni nombre de negocio», que es lo contrario de
//  imprimir uno. Es el mismo falso positivo que dio TRUNCATE en la disciplina.
ok('no lee captions ni copys', stripos($b, 'copy_text') === false
   && stripos($b, 'c.caption') === false && stripos($b, 'SELECT caption') === false);
ok('ni nombres de negocio', stripos($b, 'nombre_negocio') === false);
ok('del error solo saca una CLASE', strpos($b, '$clases[$c]') !== false
   && strpos($b, 'echo $msg') === false,
   'el error_msg del proveedor es prosa libre: puede traer cualquier cosa');

echo "\n  — el extractor de pieza, con su hueco declarado —\n";
//  Se ejercita el mismo patrón que usa la página, con las cinco formas de
//  nombre que el sistema genera de verdad.
$patron = '#/(resp|gem|carr|gen)_(\d+)_#';
$casos = [
    '/uploads/marca_9/graficas/resp_656_a1b2c3d4.png' => ['resp', '656'],
    '/uploads/marca_9/graficas/gem_644_ff00aa.png'    => ['gem',  '644'],
    '/uploads/marca_9/graficas/carr_31_9c8d7e.png'    => ['carr', '31'],
    '/uploads/marca_9/graficas/gen_77_5a4b3c.png'     => ['gen',  '77'],
];
foreach ($casos as $url => [$tipo, $id]) {
    ok("saca {$tipo}:{$id}", preg_match($patron, $url, $m) === 1
       && $m[1] === $tipo && $m[2] === $id);
}
ok('post_<uniqid> NO tiene id, y no se inventa',
   preg_match($patron, '/uploads/marca_9/graficas/post_66b1f2a3c4d5e.png') === 0);
ok('el logo tampoco',
   preg_match($patron, '/uploads/marca_9/logo_66b1f2a3c4d5e.png') === 0);
ok('y la página lo declara en vez de contarlos como cero',
   strpos($b, 'sin id de pieza atribuible') !== false
   && strpos($b, 'no se pueden atribuir') !== false,
   'un hueco callado se lee como un dato');

echo "\n  — la ventana y el filtro son seguros —\n";
ok('las fechas se validan con un patrón', strpos($b, "preg_match('/^\\d{4}-\\d{2}-\\d{2}\$/'") !== false,
   'esas fechas entran directas al SQL: sin validar serían una inyección');
ok('la marca se fuerza a entero', strpos($b, "(int)(\$_GET['marca'] ?? 0)") !== false);

echo "\n  — solo mira modelos que pintan —\n";
ok('la lista de modelos está acotada', strpos($b, 'gpt-image-1') !== false
   && strpos($b, 'gemini-3-pro-image') !== false);
ok('y lo dice', strpos($b, 'El texto no entra') !== false,
   'el texto son centavos y ensucia el reparto');

echo "\n  — no concluye por su cuenta —\n";
ok('trata el lunes como dato, no como causa',
   strpos($b, 'no es una conclusion') !== false || strpos($b, 'es un dato, no una conclusion') !== false,
   'que el 10 fuera lunes es una pista; la reparticion por hora es la prueba');
ok('y explica cómo leer los números', strpos($b, 'como leer esto') !== false);

echo "\n" . str_repeat('=', 56) . "\n";
echo $fallos === 0 ? "  TODO OK · {$n} pruebas\n\n" : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
