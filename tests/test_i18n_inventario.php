<?php
// ============================================================
//  CRECER — EL INVENTARIO EJECUTABLE DE IDIOMAS
//  tests/test_i18n_inventario.php
//
//  Esta es la regla que mide. Sin ella, «ya está traducido» es una opinión, y
//  la última vez que se dio por bueno el toggle la cobertura real era del 14%.
//
//  LO QUE HACE, EN ORDEN DE IMPORTANCIA:
//
//   1. TRINQUETE. Cada familia todavía sin migrar tiene un tope. Puede bajar;
//      no puede subir. Es lo que impide que mientras se limpia una pantalla,
//      otra estrene tres cadenas nuevas a mano. Sin esto la migración no
//      converge: se limpia por delante y se ensucia por detrás.
//
//   2. FAMILIAS EXIGIDAS. Una superficie ya migrada no admite NI UNA cadena
//      visible fuera del catálogo. Sin filtro de idioma: da igual en qué
//      lengua esté escrita, si se ve y no viene del catálogo, falla.
//
//   3. HIGIENE DE LAS EXCEPCIONES. Excluir tiene que costar. Una exclusión
//      muerta —que apunta a un archivo que ya no existe— pone en verde lo que
//      venga después con ese nombre, y por eso también falla.
//
//   4. CONTROL POSITIVO. Se planta una cadena a mano y se comprueba que el
//      detector la encuentra; y se planta la misma pasada por t() y se
//      comprueba que la deja en paz. Una prueba que no se sabe romper no
//      prueba nada — es la lección de la zona segura del commit 4.
//
//  CERO base de datos, cero red, cero proveedores.
// ============================================================

require_once __DIR__ . '/_i18n_escaner.php';
require_once __DIR__ . '/i18n_superficies.php';

$RAIZ = str_replace('\\', '/', dirname(__DIR__));
$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nINVENTARIO DE IDIOMAS · procedencia, no palabras\n" . str_repeat('=', 62) . "\n";

// ══════════════════════════════════════════════════════════════
//  0 · EL CENSO
// ══════════════════════════════════════════════════════════════
echo "\n  — 0 · el censo —\n\n";
printf("    %-14s %-10s %8s %10s %9s\n", 'familia', 'estado', 'archivos', 'sin cat.', 'catálogo');
echo '    ' . str_repeat('-', 54) . "\n";

$censo = [];
foreach (I18N_FAMILIAS as $clave => $fam) {
    $archivos = i18n_archivos_de($fam, $RAIZ);
    $modo = ($fam['estado'] === 'exigida') ? 'exigido' : 'censo';
    $sin = []; $con = 0;
    foreach ($archivos as $a) {
        foreach (esc_archivo($a, $modo) as $c) {
            if ($c['via'] === 'catalogo') { $con++; continue; }
            //  Los nombres propios declarados no cuentan: no es que falte
            //  traducirlos, es que traducirlos sería el error.
            if (isset(I18N_NOMBRES_PROPIOS[$c['texto']])) continue;
            $c['archivo'] = i18n_rel($a, $RAIZ);
            $sin[] = $c;
        }
    }
    $censo[$clave] = ['fam' => $fam, 'archivos' => $archivos, 'sin' => $sin, 'con' => $con];
    printf("    %-14s %-10s %8d %10d %9d\n",
           $clave, $fam['estado'], count($archivos), count($sin), $con);
}

// ══════════════════════════════════════════════════════════════
//  1 · EL TRINQUETE
// ══════════════════════════════════════════════════════════════
echo "\n  — 1 · el trinquete: la deuda puede bajar, nunca subir —\n";
foreach ($censo as $clave => $c) {
    if ($c['fam']['estado'] !== 'pendiente') continue;
    $tope  = I18N_TOPES[$clave] ?? null;
    $real  = count($c['sin']);
    ok("«{$clave}» tiene tope declarado", $tope !== null,
       'una familia pendiente sin tope es una familia que puede crecer sin que nadie lo note');
    if ($tope === null) continue;
    ok("«{$clave}» no ha crecido: $real ≤ $tope", $real <= $tope,
       ($real - $tope) . ' cadenas nuevas a mano desde el último tope · '
     . 'si es trabajo legítimo, migra esas pantallas; no subas el número');
    if ($real < $tope) echo "         (bajó $tope → $real · actualiza I18N_TOPES para fijar la ganancia)\n";
}

// ══════════════════════════════════════════════════════════════
//  2 · LAS FAMILIAS EXIGIDAS
// ══════════════════════════════════════════════════════════════
echo "\n  — 2 · lo ya migrado no admite ni una —\n";
foreach ($censo as $clave => $c) {
    if ($c['fam']['estado'] !== 'exigida') continue;
    $sin = $c['sin'];
    $muestra = '';
    foreach (array_slice($sin, 0, 12) as $x) {
        $muestra .= "\n           " . $x['archivo'] . ':' . $x['linea']
                  . ' [' . $x['donde'] . '] ' . mb_substr($x['texto'], 0, 52);
    }
    if (count($sin) > 12) $muestra .= "\n           … y " . (count($sin) - 12) . ' más';
    ok("«{$clave}» sin cadenas fuera del catálogo", $sin === [],
       count($sin) . ' cadenas visibles escritas a mano:' . $muestra);
    ok("«{$clave}» sí usa el catálogo", $c['con'] > 0,
       'cero llamadas a t(): o no se migró, o se migró sin catálogo');
}

// ══════════════════════════════════════════════════════════════
//  3 · HIGIENE DE LAS EXCEPCIONES
// ══════════════════════════════════════════════════════════════
echo "\n  — 3 · excluir cuesta mantenimiento —\n";
foreach (I18N_FAMILIAS as $clave => $fam) {
    ok("«{$clave}» declara por qué", trim($fam['porque'] ?? '') !== '' && mb_strlen($fam['porque']) > 40,
       'una exclusión sin motivo escrito es una exclusión que nadie vuelve a revisar');
    foreach ($fam['globs'] as $g) {
        ok("«{$clave}» → $g apunta a algo", glob($RAIZ . '/' . $g) !== [],
           'glob muerto: pone en verde cualquier archivo futuro que se llame así');
    }
    foreach ($fam['excepto'] ?? [] as $g) {
        ok("«{$clave}» → excepto $g apunta a algo", glob($RAIZ . '/' . $g) !== [],
           'excepción muerta: la próxima pantalla con ese nombre nace excluida sin que nadie lo decida');
    }
}

//  Un nombre propio que ya no aparece en el repo sobra, y sobrando tapa.
$fuente_todo = '';
foreach (array_merge(glob($RAIZ . '/*.php'), glob($RAIZ . '/panel/*.php'), glob($RAIZ . '/includes/*.php')) as $f) {
    $fuente_todo .= (string)file_get_contents($f);
}
$muertos = [];
foreach (I18N_NOMBRES_PROPIOS as $nombre => $razon) {
    if (trim($razon) === '') { $muertos[] = "$nombre (sin motivo)"; continue; }
    if (strpos($fuente_todo, $nombre) === false) $muertos[] = "$nombre (ya no aparece)";
}
ok('los nombres propios declarados siguen vivos y con motivo', $muertos === [],
   implode(' · ', $muertos) . "\n         un nombre propio de más silencia una cadena que sí habría que traducir");

// ══════════════════════════════════════════════════════════════
//  4 · EL AVISO DE LOS DOCUMENTOS LEGALES
// ══════════════════════════════════════════════════════════════
echo "\n  — 4 · lo legal se declara, no se disimula —\n";
$legal = I18N_FAMILIAS['legal'];
ok('la excepción legal declara su aviso en inglés', !empty($legal['aviso_en']));
foreach (i18n_archivos_de($legal, $RAIZ) as $a) {
    $src = (string)file_get_contents($a);
    ok(i18n_rel($a, $RAIZ) . ' lleva el aviso', strpos($src, $legal['aviso_en']) !== false,
       'sin el aviso, un lector en inglés cree que este documento es su versión — y no lo es');
}

// ══════════════════════════════════════════════════════════════
//  5 · CONTROL POSITIVO: ¿SE SABE ROMPER ESTO?
// ══════════════════════════════════════════════════════════════
echo "\n  — 5 · el detector, puesto a prueba —\n";
$tmp = sys_get_temp_dir() . '/i18n_control_' . getmypid() . '.php';

//  a) Una cadena a mano en la plantilla: tiene que aparecer.
file_put_contents($tmp, "<?php \$x=1; ?>\n<p>Tienes algo esperando tu OK</p>\n");
$r = esc_archivo($tmp, 'exigido');
ok('caza una cadena escrita a mano en la plantilla',
   count(array_filter($r, fn($c) => $c['texto'] === 'Tienes algo esperando tu OK')) === 1,
   'si esto no aparece, el resto del inventario está midiendo humo');

//  b) La misma, pasada por t(): tiene que quedar clasificada como catálogo.
file_put_contents($tmp, "<?php echo t('Tienes algo esperando tu OK');\n");
$r = esc_archivo($tmp, 'exigido');
$c = array_values(array_filter($r, fn($c) => $c['texto'] === 'Tienes algo esperando tu OK'));
ok('reconoce que pasó por t()', count($c) === 1 && $c[0]['via'] === 'catalogo',
   'si no, migrar una pantalla no la pondría nunca en verde');

//  c) Una etiqueta de UNA palabra sin marca de idioma: el caso que se escapaba.
file_put_contents($tmp, "<?php \$nav=[['ic'=>'home','lb'=>'Finanzas']];\n");
$r = esc_archivo($tmp, 'exigido');
ok('caza una etiqueta de una palabra («Finanzas»)',
   count(array_filter($r, fn($c) => $c['texto'] === 'Finanzas')) === 1,
   'sin esto, medio menú pasaría por nombre propio: no lleva tilde ni artículo');
ok('y no confunde el nombre del icono con texto',
   count(array_filter($r, fn($c) => $c['texto'] === 'home')) === 0,
   'un valor de clave no visible no es una cadena de interfaz');

//  d) Lo que NUNCA se puede tocar: contenido, no interfaz.
file_put_contents($tmp, "<?php \$m=['nombre'=>'x']; ?>\n<h1><?= \$m['nombre'] ?></h1>\n<p><?= \$caption ?></p>\n");
$r = esc_archivo($tmp, 'exigido');
ok('no toca lo que sale de un dato (el negocio, la IA, el dueño)', $r === [],
   count($r) . ' · marcar contenido como «falta traducir» obligaría a traducir '
 . 'el nombre del negocio de un cliente, que es justo lo que nunca se hace');

//  e) SQL y CSS no son interfaz.
file_put_contents($tmp, "<?php \$q='SELECT id, nombre_negocio FROM crecer_marca WHERE id = ?';\n\$s='display:flex;gap:8px';\n");
$r = esc_archivo($tmp, 'censo');
ok('no confunde SQL ni CSS con copy', $r === [], count($r) . ' falsos positivos');

@unlink($tmp);

// ══════════════════════════════════════════════════════════════
echo "\n" . str_repeat('=', 62) . "\n";
if ($fallos === 0) {
    echo "  INVENTARIO EN VERDE · {$n} afirmaciones\n\n";
} else {
    echo "  {$fallos} de {$n}\n\n";
    //  El detalle completo solo cuando algo falla: en verde no hace falta ruido.
    foreach ($censo as $clave => $c) {
        if ($c['fam']['estado'] !== 'exigida' || $c['sin'] === []) continue;
        echo "  Lo que falta en «{$clave}»:\n";
        $porArch = [];
        foreach ($c['sin'] as $x) $porArch[$x['archivo']][] = $x;
        foreach ($porArch as $arch => $xs) {
            echo "    $arch (" . count($xs) . ")\n";
            foreach ($xs as $x) printf("      %5d  %-5s %s\n", $x['linea'], $x['donde'], mb_substr($x['texto'], 0, 60));
        }
        echo "\n";
    }
}
exit($fallos === 0 ? 0 : 1);
