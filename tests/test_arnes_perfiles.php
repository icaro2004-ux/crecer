<?php
// ============================================================
//  CRECER — EL ARNES NO DEJA BASURA EN EL DISCO
//  tests/test_arnes_perfiles.php
//
//  POR QUE EXISTE. Cada Chrome de prueba necesita su propio --user-data-dir.
//  `tests/_chrome.mjs` lo creaba con mkdtempSync y no lo borraba nunca:
//  cerrar() mataba el proceso y se olvidaba del directorio. Seis arneses usan
//  ese ayudante. Se acumularon 2.441 perfiles y llenaron el disco entero —475
//  GB al 100%— y la suite se rompio por quedarse sin espacio, no por ningun
//  fallo del producto. Un `git add` llego a fallar con «No space left».
//
//  Y los otros dos NO estaban bien, aunque a primera vista lo pareciera. Tenian
//  su rmSync en el finally, si — pero mataban solo el proceso PADRE de Chrome.
//  Sus renderers seguian vivos con el perfil abierto, el borrado fallaba por
//  EPERM y el fallo se lo tragaba un `catch {}`. Se midio corriendo la suite
//  entera: 35 perfiles `navp-` en una sola pasada. Tener la llamada no es
//  limpiar, y por eso esta prueba mira el DISCO y no el fuente.
//
//  LO QUE SE AFIRMA, y se afirma MIRANDO EL DISCO:
//    · una corrida que termina bien borra SU perfil;
//    · una que lanza sin cerrar, tambien (lo hace el gancho de salida);
//    · una que llama a process.exit(), tambien;
//    · dos corridas a la vez borran cada una la SUYA;
//    · un perfil que NO es de este proceso se queda intacto, aunque tenga el
//      mismo prefijo — porque barrer por patron es como dos suites en paralelo
//      se roban el perfil la una a la otra;
//    · un directorio testigo con otro nombre ni se roza.
//
//  Perfiles minusculos y procesos cortos: aqui no se llena nada para probarlo.
// ============================================================

require_once __DIR__ . '/../includes/db.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nEL ARNES NO DEJA BASURA\n" . str_repeat('=', 58) . "\n";

$CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if (!is_file($CHROME)) { echo "\n  SALTADO · no hay Chrome en esta máquina\n\n"; exit(0); }

//  El temporal que ve NODE, no el que ve PHP: pueden diferir, y lo que importa
//  es dónde escribe el arnés.
$TMP = trim((string)shell_exec('node -e "process.stdout.write(require(\'os\').tmpdir())"'));
if ($TMP === '' || !is_dir($TMP)) { echo "\n  SALTADO · no se pudo leer el temporal de Node\n\n"; exit(0); }

$perfiles = function () use ($TMP) {
    $out = [];
    foreach ((array)glob($TMP . DIRECTORY_SEPARATOR . 'tm-*', GLOB_ONLYDIR) as $d) $out[] = $d;
    return $out;
};
$correr = function (string $modo) {
    $cmd = 'node ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . '_perfil_probe.mjs')
         . ' ' . escapeshellarg($modo) . ' 2>&1';
    $sal = []; $cod = 0; exec($cmd, $sal, $cod);
    $r = ['codigo' => $cod, 'perfil' => '', 'crudo' => implode(' | ', $sal)];
    foreach ($sal as $l) {
        if (strpos(trim($l), 'PERFIL=') === 0) $r['perfil'] = substr(trim($l), 7);
        if (strpos(trim($l), 'SIN_CHROME=') === 0) $r['sin_chrome'] = true;
    }
    return $r;
};

$antes = $perfiles();
echo "\n  (perfiles «tm-» antes de empezar: " . count($antes) . ")\n";

// ── LOS TESTIGOS ─────────────────────────────────────────────
//  Uno con NUESTRO patrón pero que no es de ningún proceso vivo, y otro con
//  nombre distinto. Ninguno de los dos puede desaparecer.
$testigo_patron = $TMP . DIRECTORY_SEPARATOR . 'tm-zzzzzz';
$testigo_otro   = $TMP . DIRECTORY_SEPARATOR . 'testigo-arnes-' . bin2hex(random_bytes(3));
@mkdir($testigo_patron, 0777, true);
@mkdir($testigo_otro, 0777, true);
file_put_contents($testigo_patron . DIRECTORY_SEPARATOR . 'no-me-borres.txt', 'testigo');
file_put_contents($testigo_otro   . DIRECTORY_SEPARATOR . 'no-me-borres.txt', 'testigo');

try {
    // ══════════════════════════════════════════════════════════════
    //  1 · UNA CORRIDA QUE TERMINA BIEN
    // ══════════════════════════════════════════════════════════════
    echo "\n  — la corrida normal borra su perfil —\n";
    $r = $correr('ok');
    if (!empty($r['sin_chrome'])) { echo "\n  SALTADO · Chrome no arrancó en esta máquina\n\n"; exit(0); }
    ok('la sonda dijo qué perfil le tocó', $r['perfil'] !== '', $r['crudo']);
    ok('y terminó bien', $r['codigo'] === 0, 'código ' . $r['codigo'] . ' · ' . $r['crudo']);
    ok('su perfil YA NO está en el disco', $r['perfil'] !== '' && !is_dir($r['perfil']),
       $r['perfil'] . ' — cerrar() tiene que borrarlo, no solo matar Chrome');

    // ══════════════════════════════════════════════════════════════
    //  2 · UNA QUE LANZA SIN LLAMAR A cerrar()
    // ══════════════════════════════════════════════════════════════
    echo "\n  — la que revienta, también —\n";
    $r = $correr('falla');
    ok('la sonda falló como se le pidió', $r['codigo'] !== 0, 'código ' . $r['codigo']);
    ok('y aun así su perfil desapareció', $r['perfil'] !== '' && !is_dir($r['perfil']),
       $r['perfil'] . ' — sin el gancho de salida, este se quedaba para siempre');

    // ══════════════════════════════════════════════════════════════
    //  3 · UNA QUE LLAMA A process.exit()
    // ══════════════════════════════════════════════════════════════
    echo "\n  — y la que se va por la puerta de atrás —\n";
    $r = $correr('salida');
    ok('salió con su propio código', $r['codigo'] === 3, 'código ' . $r['codigo']);
    ok('su perfil tampoco quedó', $r['perfil'] !== '' && !is_dir($r['perfil']), $r['perfil']);

    // ══════════════════════════════════════════════════════════════
    //  4 · DOS A LA VEZ · cada una borra la SUYA
    // ══════════════════════════════════════════════════════════════
    echo "\n  — dos corridas simultáneas no se pisan —\n";
    $base = __DIR__ . DIRECTORY_SEPARATOR . '_perfil_probe.mjs';
    $sal1 = tempnam(sys_get_temp_dir(), 'p1'); $sal2 = tempnam(sys_get_temp_dir(), 'p2');
    //  Se lanzan de verdad en paralelo: dos procesos, no dos llamadas seguidas.
    $p1 = proc_open('node ' . escapeshellarg($base) . ' ok', [1 => ['file', $sal1, 'w'], 2 => ['file', $sal1, 'a']], $t1);
    $p2 = proc_open('node ' . escapeshellarg($base) . ' ok', [1 => ['file', $sal2, 'w'], 2 => ['file', $sal2, 'a']], $t2);
    $c1 = is_resource($p1) ? proc_close($p1) : 1;
    $c2 = is_resource($p2) ? proc_close($p2) : 1;
    $leer = function (string $f) {
        foreach (explode("\n", (string)file_get_contents($f)) as $l) {
            if (strpos(trim($l), 'PERFIL=') === 0) return substr(trim($l), 7);
        }
        return '';
    };
    $pf1 = $leer($sal1); $pf2 = $leer($sal2);
    @unlink($sal1); @unlink($sal2);

    ok('las dos corridas terminaron bien', $c1 === 0 && $c2 === 0, "{$c1} / {$c2}");
    ok('cada una tuvo un perfil distinto', $pf1 !== '' && $pf2 !== '' && $pf1 !== $pf2,
       "{$pf1} vs {$pf2}");
    ok('la primera borró la suya', $pf1 !== '' && !is_dir($pf1), $pf1);
    ok('la segunda borró la suya',  $pf2 !== '' && !is_dir($pf2), $pf2);

    // ══════════════════════════════════════════════════════════════
    //  5 · LO AJENO NO SE TOCA
    // ══════════════════════════════════════════════════════════════
    echo "\n  — barrer por patrón sería robarle el perfil a otra suite —\n";
    ok('un «tm-» que no es suyo sigue intacto', is_dir($testigo_patron),
       'el arnés borra lo que ÉL creó, no lo que se le parece');
    ok('con su contenido dentro',
       is_file($testigo_patron . DIRECTORY_SEPARATOR . 'no-me-borres.txt'));
    ok('un directorio con otro nombre ni se roza', is_dir($testigo_otro));
    ok('y también con su contenido',
       is_file($testigo_otro . DIRECTORY_SEPARATOR . 'no-me-borres.txt'));

    // ══════════════════════════════════════════════════════════════
    //  6 · EL SALDO · la suite no deja perfiles nuevos
    // ══════════════════════════════════════════════════════════════
    echo "\n  — el saldo del disco después de todo esto —\n";
    $ahora = $perfiles();
    $nuevos = array_values(array_diff($ahora, $antes, [$testigo_patron]));
    ok('no queda ningún perfil nuevo', count($nuevos) === 0,
       count($nuevos) . ' de más: ' . implode(', ', array_slice($nuevos, 0, 3)));

    // ══════════════════════════════════════════════════════════════
    //  7 · EL CONTRATO, EN LOS TRES ARNESES
    // ══════════════════════════════════════════════════════════════
    echo "\n  — quien crea el directorio es quien lo borra —\n";
    //  CADA PREFIJO, POR SEPARADO. La guarda que decide «este perfil es mío»
    //  llegó a mirar un solo prefijo mientras el arnés limpiaba tres: rechazaba
    //  los `navp-` en silencio —devolvía null, sin error ninguno— y la limpieza
    //  ni se intentaba. Las aserciones de fuente no lo vieron; esto sí.
    $r = $correr('prefijos');
    ok('la sonda de prefijos corrió', strpos($r['crudo'], 'PREFIJOS=') !== false, $r['crudo']);
    foreach (['tm-', 'nav-', 'navp-'] as $pre) {
        ok("«{$pre}» se reconoce como propio y se borra",
           strpos($r['crudo'], $pre . ':anotado:borrado:ido') !== false,
           $r['crudo'] . " — si dice RECHAZADO, la guarda no conoce ese prefijo");
    }

    //  LA PRIMERA VERSIÓN DE ESTA COMPROBACIÓN ERA DEMASIADO BLANDA: pedía que
    //  el fuente contuviera «rmSync» y se daba por satisfecha. Los dos arneses
    //  de fuera lo contenían —y aun así dejaban 35 perfiles por corrida—,
    //  porque mataban solo al proceso padre y el fallo del borrado se lo tragaba
    //  un `catch {}`. Tener la llamada no es limpiar.
    //
    //  Ahora se exige lo que sí importa: que NINGUNO borre por su cuenta y que
    //  los tres pasen por la misma puerta, que es la única cuyo comportamiento
    //  está probado de verdad más arriba.
    foreach (['_navegador.mjs', '_navegador_presentacion.mjs'] as $arnes) {
        $src = (string)file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . $arnes);
        ok("{$arnes} crea su perfil", strpos($src, 'mkdtempSync') !== false);
        ok("{$arnes} lo anota en el ayudante compartido",
           strpos($src, 'registrarPerfil(perfil, ch)') !== false,
           'sin anotarlo, el respaldo no sabe que ese directorio es suyo');
        ok("{$arnes} delega el borrado", strpos($src, 'borrarPerfil(perfil)') !== false);
        ok("{$arnes} ya no borra por su cuenta", strpos($src, 'fs.rmSync') === false,
           'su versión propia mataba solo al padre y escondía el fallo');
    }
    $ch = (string)file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . '_chrome.mjs');
    ok('el ayudante compartido solo borra lo que anotó como suyo',
       strpos($ch, 'MIOS.has(real)') !== false,
       'sin el conjunto, dos suites en paralelo se borrarían el perfil');
    ok('valida que la ruta cuelga del temporal del sistema',
       strpos($ch, "path.dirname(real) !== raiz") !== false);
    ok('y se niega a tocar el temporal padre',
       strpos($ch, 'real === raiz') !== false,
       'un rm -rf sobre una ruta calculada sin mirar es como se borra un disco');
    ok('tiene respaldo para la salida y las señales',
       strpos($ch, "process.on('exit'") !== false && strpos($ch, 'SIGINT') !== false);
    ok('y reintenta el borrado, que en Windows falla la primera vez',
       strpos($ch, 'maxRetries') !== false,
       'matar Chrome no suelta sus ficheros al instante');

} finally {
    //  Los testigos son míos: los quito yo, por ruta exacta y comprobando que
    //  son los que creé.
    foreach ([$testigo_patron, $testigo_otro] as $t) {
        if (is_dir($t) && strpos($t, $TMP . DIRECTORY_SEPARATOR) === 0) {
            @unlink($t . DIRECTORY_SEPARATOR . 'no-me-borres.txt');
            @rmdir($t);
        }
    }
    echo "\n  (testigos retirados)\n";
}

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0 ? "  EL ARNES LIMPIA LO SUYO · {$n} afirmaciones\n\n"
                   : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
