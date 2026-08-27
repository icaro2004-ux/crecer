<?php
// ============================================================
//  CRECER — QUIEN LLAMA AL DOMINIO, LO CARGA (Y SE COMPRUEBA CARGANDOLO)
//  tests/test_material_dependencias.php
//
//  EL DEFECTO QUE ESTA PRUEBA EXISTE PARA NO REPETIR. `material_soltar()` en la
//  entrega asincrona quedo sin su `require_once` delante. No es un aviso: es un
//  fatal, y justo en la ruta que MAS se usa para el arte — la imagen dejaba de
//  guardarse. `php -l` no lo ve porque no es sintaxis. Un grep tampoco lo veia:
//  la palabra estaba escrita, y no se podia ejecutar.
//
//  POR QUE EN PROCESO APARTE. `material.php` se incluye a mano, archivo por
//  archivo. En una suite normal ya esta cargado —lo cargo la propia prueba— asi
//  que TODO parece funcionar: la comprobacion se hace trampa a si misma. Aqui
//  cada archivo se carga en un PHP nuevo, con nada dentro, y se pregunta si las
//  funciones existen. Eso es lo que ve la primera peticion que entra por esa
//  pagina en produccion.
//
//  Y SE PRUEBA QUE LA PRUEBA SIRVE: se le quita el require a una copia y la
//  comprobacion tiene que ponerse roja. Una vigilancia que no se cae cuando la
//  rompes no esta vigilando nada.
// ============================================================

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nEL DOMINIO SE CARGA DONDE SE LLAMA\n" . str_repeat('=', 58) . "\n";

$raiz = dirname(__DIR__);
$PHP  = PHP_BINARY;
ok('hay un php con el que abrir procesos', is_file($PHP) || $PHP !== '', $PHP);

/** El código sin comentarios: lo que se ejecuta, no lo que se explica. */
function codigo_de(string $abs): string {
    $s = (string)@file_get_contents($abs);
    if ($s === '') return '';
    $out = '';
    foreach (token_get_all($s) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) { $out .= ' '; continue; }
            $out .= $t[1];
        } else { $out .= $t; }
    }
    return $out;
}

/** Los helpers del dominio, sacados del propio archivo de dominio. */
$DOM = [];
foreach (preg_split('~\R~', (string)file_get_contents("{$raiz}/includes/material.php")) as $l) {
    if (preg_match('~^function\s+(material_\w+)\s*\(~', $l, $m)) $DOM[] = $m[1];
}
sort($DOM);
ok('el dominio declara sus helpers', count($DOM) >= 6, implode(', ', $DOM));

//  QUIEN LLAMA. Se busca en TODO el arbol, no en una lista escrita a mano: un
//  archivo nuevo que llame al dominio entra en la vigilancia solo.
$llamadores = [];
foreach (['includes', 'panel', 'core', 'scripts'] as $dir) {
    if (!is_dir("{$raiz}/{$dir}")) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$raiz}/{$dir}"));
    foreach ($it as $f) {
        if (!$f->isFile() || $f->getExtension() !== 'php') continue;
        $abs = $f->getPathname();
        $rel = str_replace('\\', '/', substr($abs, strlen($raiz) + 1));
        if ($rel === 'includes/material.php') continue;
        $cod = codigo_de($abs);
        $usa = [];
        foreach ($DOM as $fn) {
            if (preg_match('~\b' . preg_quote($fn, '~') . '\s*\(~', $cod)) $usa[] = $fn;
        }
        if ($usa) $llamadores[$rel] = $usa;
    }
}
ksort($llamadores);
ok('hay llamadores que vigilar', count($llamadores) >= 8, count($llamadores) . ' archivos');

/**
 * ¿Cargar ESE archivo, en un PHP nuevo, deja el dominio disponible?
 *
 * Se carga tal cual, sin nada delante. Las paginas del panel abortan por
 * sesion/permisos antes de pintar nada —eso es correcto y no molesta aqui—: lo
 * que se mira es si, en el punto en que se detienen, las funciones que ese
 * archivo NOMBRA ya existen. Si el `require_once` esta arriba, existen.
 */
function dominio_disponible(string $php, string $raiz, string $rel, array $fns): array {
    $script = 'tests/_dep_probe.php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($raiz . '/' . $script)
         . ' ' . escapeshellarg($rel) . ' ' . escapeshellarg(implode(',', $fns)) . ' 2>&1';
    $out = (string)@shell_exec($cmd);
    //  LA RESPUESTA ES LA ULTIMA LINEA CON ALGO. La pagina puede imprimir
    //  avisos antes, y el proceso termina con salto de linea: coger «la
    //  ultima linea» a secas devolvia la vacia y todo salia rojo con el
    //  JSON correcto justo encima — un defecto de la prueba, no del producto.
    $j = null;
    foreach (array_reverse(preg_split('~\R~', $out) ?: []) as $l) {
        $l = trim($l);
        if ($l === '') continue;
        $j = json_decode($l, true);
        break;
    }
    return is_array($j) ? $j : ['ok' => false, 'faltan' => $fns, 'crudo' => mb_substr($out, -300)];
}

echo "\n  — cada archivo, en un PHP nuevo y vacío —\n";
$rotos = [];
foreach ($llamadores as $rel => $fns) {
    $r = dominio_disponible($PHP, $raiz, $rel, $fns);
    if (empty($r['ok'])) $rotos[] = $rel . ' → ' . implode(', ', (array)($r['faltan'] ?? []))
                                  . (isset($r['crudo']) ? ' · ' . $r['crudo'] : '');
    ok("{$rel} deja el dominio cargado", !empty($r['ok']),
       implode(', ', (array)($r['faltan'] ?? [])) . ' · ' . (string)($r['crudo'] ?? ''));
}

// ══════════════════════════════════════════════════════════════
//  Y LA VIGILANCIA SE CAE SI LE QUITAS EL require
// ══════════════════════════════════════════════════════════════
echo "\n  — y la vigilancia se cae si le quitas el require —\n";
$victima = 'includes/img_responses.php';
$origen  = "{$raiz}/{$victima}";
$copia   = "{$raiz}/tests/_dep_sin_require.php";
$src     = (string)@file_get_contents($origen);
ok('la víctima existe', $src !== '', $victima);

if ($src !== '') {
    //  Se le quitan TODOS sus require de material.php y se guarda al lado, con
    //  las rutas relativas apuntando a donde apuntaban.
    //  EL PUNTO Y COMA VA DESPUES DE LA COMILLA: '/material.php';
    //  Un patron que pedia «material.php;» pegado no casaba con ninguna
    //  linea real: la copia salia intacta y la prueba de la prueba se daba
    //  por buena sin haber roto nada. Una vigilancia que no se cae cuando la
    //  rompes no esta vigilando.
    $sin = (string)preg_replace("~require(_once)?[^;\n]*material\\.php['\"]?\\s*;~",
                                '/* quitado a proposito */', $src);
    $sin = str_replace("__DIR__ . '/", "dirname(__DIR__) . '/includes/", $sin);
    file_put_contents($copia, $sin);
    ok('la copia perdió el require',
       !preg_match("~require(_once)?[^;\n]*material\.php~", $sin)
       && substr_count($src, "material.php") > substr_count($sin, "material.php"));

    $r = dominio_disponible($PHP, $raiz, 'tests/_dep_sin_require.php', ['material_soltar']);
    ok('y la vigilancia la marca ROJA', empty($r['ok']),
       'si esto sale verde, la prueba no está probando nada: ' . json_encode($r));
    @unlink($copia);
    ok('la copia se borra', !is_file($copia));
}

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  NADIE LLAMA A LO QUE NO CARGA · $n afirmaciones\n\n"
    : "  $fallos FALLAS de $n\n\n";
exit($fallos === 0 ? 0 : 1);
