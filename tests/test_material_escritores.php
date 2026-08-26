<?php
// ============================================================
//  CRECER — TODO EL MATERIAL DEL DUEÑO ENTRA POR LA MISMA PUERTA
//  tests/test_material_escritores.php
//
//  EL RIESGO QUE CIERRA. Cinco sitios distintos ponian una foto o un video del
//  dueño en una publicación, y cada uno con sus reglas: uno miraba
//  `getimagesize()`, otro `finfo`, otro comprobaba `tipo='imagen'` a mano y
//  ninguno se acordaba de mirar si la pieza ya habia salido. Cinco puertas a la
//  misma capacidad con cinco reglas — la que se olvide una es la que alguien
//  encuentra.
//
//  Esta suite no comprueba una funcion: comprueba que NO QUEDE OTRA. Recorre el
//  codigo, encuentra CADA sitio que le pone imagen o video a una publicación, y
//  exige que cada uno se declare: o entra por el dominio (material del dueño), o
//  suelta la referencia (arte generado). Lo que no se pueda clasificar, falla.
//
//  Es estructural a propósito. Una prueba que solo ejercitara las rutas que hoy
//  conozco se quedaria igual de verde el dia que alguien añada la sexta.
// ============================================================

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/material.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

/**
 * El código sin comentarios: lo que se ejecuta, no lo que se explica.
 *
 * Con el tokenizador de PHP, no a golpe de expresión regular. Mi primer intento
 * borraba desde `/*` hasta el cierre más cercano, y en un archivo de 180 KB con
 * CSS y JS dentro eso se comió handlers enteros: la prueba llegó a afirmar que
 * `editar` no existía. Una prueba estructural que lee mal el código miente en
 * las dos direcciones, y la cara es la otra — dar por bueno lo que no está.
 */
function codigo(string $rel): string {
    $s = (string)@file_get_contents(dirname(__DIR__) . '/' . $rel);
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

/** El cuerpo de un handler `$accion === 'x'`, para mirarlo por dentro. */
function bloque_accion(string $cod, string $acc, int $largo = 3000): string {
    $i = mb_strpos($cod, "\$accion === '" . $acc . "'");
    return $i === false ? '' : mb_substr($cod, (int)$i, $largo);
}

echo "\nUNA SOLA PUERTA PARA EL MATERIAL DEL DUEÑO\n" . str_repeat('=', 58) . "\n";

$cnt = fn(string $t, string $w = '1') => (int)$GLOBALS['pdo']->query(
    "SELECT COUNT(*) FROM {$t} WHERE {$w}")->fetchColumn();
$g = ['ia' => $cnt('crecer_ia_log'), 'cuota' => $cnt('crecer_img_cuota_asiento')];

$limpiar = [];
try {
    // ══════════════════════════════════════════════════════════════
    //  1 · EL CENSO · ni un escritor sin clasificar
    // ══════════════════════════════════════════════════════════════
    echo "\n  — quién le pone imagen a una publicación —\n";

    //  Se recorre el árbol entero: si mañana aparece un escritor nuevo en un
    //  archivo que hoy no existe, esta prueba lo ve igual.
    $raiz = dirname(__DIR__);
    $php  = [];
    foreach (['includes', 'panel', 'core', 'scripts', 'api'] as $dir) {
        if (!is_dir("{$raiz}/{$dir}")) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$raiz}/{$dir}"));
        foreach ($it as $f) if ($f->isFile() && $f->getExtension() === 'php') $php[] = $f->getPathname();
    }
    sort($php);
    ok('hay árbol que recorrer', count($php) > 40, count($php) . ' archivos');

    $sin_clasificar = []; $censo = ['dominio' => 0, 'generado' => 0];
    foreach ($php as $abs) {
        $rel = str_replace('\\', '/', substr($abs, strlen($raiz) + 1));
        $cod = codigo($rel);
        //  Solo la media de la PUBLICACIÓN. Los slides (`crecer_carrusel`) son
        //  otra tabla y otra vida: no llevan `material_activo_id`.
        if (!preg_match_all('~UPDATE\s+crecer_contenido\s+SET[^"\']*grafica_path\s*=~i',
                            $cod, $m, PREG_OFFSET_CAPTURE)) continue;
        foreach ($m[0] as $hit) {
            if ($rel === 'includes/material.php') { $censo['dominio']++; continue; }
            //  La ventana: lo que rodea al write. Quien escribe media tiene que
            //  decir de qué clase es, y lo dice ahí mismo.
            $ini = max(0, (int)$hit[1] - 1400);
            $ven = substr($cod, $ini, 2800);
            if (str_contains($ven, 'material_aplicar')) { $censo['dominio']++;  continue; }
            if (str_contains($ven, 'material_soltar'))  { $censo['generado']++; continue; }
            $ln = substr_count(substr($cod, 0, (int)$hit[1]), "\n") + 1;
            $sin_clasificar[] = "{$rel} (≈línea {$ln} sin comentarios)";
        }
    }
    ok('ningún escritor de media queda sin clasificar', $sin_clasificar === [],
       count($sin_clasificar) . ' sin declarar · ' . implode(' · ', $sin_clasificar)
       . "\n         → o pasa por material_aplicar() (material del dueño)"
       . " o suelta con material_soltar() (arte generado)");
    ok('y los hay de las dos clases',
       $censo['dominio'] > 0 && $censo['generado'] >= 6,
       'dominio=' . $censo['dominio'] . ' generado=' . $censo['generado']);

    //  Y QUIEN LLAMA AL DOMINIO, LO CARGA. Esto no es pulcritud: `material.php`
    //  se incluye a mano, archivo por archivo, y una llamada sin su
    //  `require_once` delante no es un aviso — es un fatal. Paso de verdad: el
    //  soltar de la entrega async quedo sin require y la imagen dejo de
    //  guardarse en la ruta que MAS se usa. `php -l` no lo ve, y el censo de
    //  arriba tampoco: la palabra estaba ahi, escrita, y no se podia ejecutar.
    echo "\n  — y quien lo llama, lo carga —\n";
    $LLAMADAS = '~material_(soltar|aplicar|registrar_\w+|origen|compatible|abs_de_pieza|hay_columna|rel_de_url)\s*\(~';
    $sin_cargar = [];
    foreach ($php as $abs) {
        $rel = str_replace('\\', '/', substr($abs, strlen($raiz) + 1));
        if ($rel === 'includes/material.php') continue;
        $cod = codigo($rel);
        if (!preg_match($LLAMADAS, $cod)) continue;
        if (preg_match("~require(_once)?[^;\n]*material\.php~", $cod)) continue;
        $sin_cargar[] = $rel;
    }
    ok('ningún archivo llama al dominio sin cargarlo', $sin_cargar === [],
       implode(' · ', $sin_cargar) . "\n         → un material_*() sin su require_once delante es un fatal, no un aviso");

    //  LAS RUTAS DE MATERIAL PROPIO, por su nombre. El censo prueba que nadie
    //  escribe por libre; esto prueba que las que conocemos siguen enchufadas.
    echo "\n  — y las puertas conocidas siguen en su sitio —\n";
    $PROPIO = [
        'panel/aprobar2.php'   => 'foto_directa y video_directo',
        'panel/biblioteca.php' => 'la selección desde una publicación',
        'panel/propuestas.php' => 'usar un activo en una propuesta',
        'includes/reels.php'   => 'el reel montado con sus clips',
    ];
    foreach ($PROPIO as $arch => $como) {
        ok("{$como} pasa por el dominio",
           str_contains(codigo($arch), 'material_aplicar'),
           'material propio escrito a mano es una regla que se desvía');
    }

    //  Y NADIE SE HACE SUS PROPIAS GUARDAS. Un handler de material propio que
    //  vuelva a mirar el MIME por su cuenta es un handler con su propia regla.
    echo "\n  — nadie se hace sus propias guardas —\n";
    $ap = codigo('panel/aprobar2.php');
    $MIME_A_MANO = "['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp']";
    foreach (['foto_directa', 'video_directo'] as $acc) {
        $b = bloque_accion($ap, $acc);
        ok("«{$acc}» no revalida el tipo por su cuenta",
           $b !== '' && !str_contains($b, $MIME_A_MANO) && !str_contains($b, 'getimagesize'),
           'el tipo sale del contenido del archivo, y de eso se encarga el dominio');
        ok("«{$acc}» registra la subida en la Biblioteca",
           str_contains($b, 'material_registrar_subida'),
           'lo que sube tiene que quedar suyo, no solo pegado a una pieza');
    }
    //  El MIME a mano que SIGUE existiendo es legítimo, y se deja dicho: son las
    //  rutas donde la foto es MATERIA PRIMA de una generación (entra al Creador),
    //  no el material final de la pieza. Ahí el dominio no pinta nada.
    ok('lo que queda mirando el MIME a mano son rutas de generación',
       substr_count($ap, $MIME_A_MANO) === 2
       && !str_contains(bloque_accion($ap, 'foto_directa'), $MIME_A_MANO),
       substr_count($ap, $MIME_A_MANO) . ' sitios · «arte» y «post_desde_foto»');

    ok('propuestas.php ya no escribe la media por su cuenta',
       !preg_match('~UPDATE\s+crecer_contenido\s+SET[^"\']*grafica_path~i',
                   codigo('panel/propuestas.php')),
       'esa capacidad es la misma que la de Biblioteca, y vive en material_aplicar()');

    //  UN SOLO HANDLER POR ACCIÓN. Había DOS bloques `foto_directa`: el primero
    //  siempre salía por `exit`, así que el segundo llevaba tiempo muerto — quien
    //  mandaba el campo que ESE entendía recibía «no llegó el archivo».
    echo "\n  — un handler por acción, no dos —\n";
    foreach (['foto_directa', 'video_directo', 'reusar_arte', 'editar', 'fecha'] as $acc) {
        $veces = preg_match_all("~\\\$accion === '" . preg_quote($acc, '~') . "'~", $ap);
        ok("«{$acc}» se maneja en un solo sitio", $veces === 1,
           $veces . ' bloques · dos handlers con el mismo nombre = uno muerto');
    }

    // ══════════════════════════════════════════════════════════════
    //  2 · EL REEL · registrado como suyo, aplicado por el dominio
    // ══════════════════════════════════════════════════════════════
    echo "\n  — el reel es material suyo, y se anota como tal —\n";
    ok('el dominio sabe registrar un archivo que ya está en disco',
       function_exists('material_registrar_archivo'));
    ok('y traducir una URL pública a su ruta',
       function_exists('material_rel_de_url'));

    $fx = Fixture::crear($pdo, 'esc', false, 'admin');
    $limpiar[] = $M = (int)$fx['marca_id'];

    $pub = rtrim(defined('UPLOADS_URL') ? UPLOADS_URL : '/crecer/uploads', '/');
    ok('una URL de uploads se traduce',
       material_rel_de_url($pub . '/marca_1/reels/x.mp4') === 'marca_1/reels/x.mp4');
    ok('y una de fuera, no',
       material_rel_de_url('https://otro.example.com/x.mp4') === '',
       'lo que no cuelga de uploads no es material nuestro');

    $r1 = material_registrar_archivo($pdo, $M, "marca_{$M}/reels/prueba.mp4", 'video',
                                     'Reel de prueba', 'reel');
    ok('registra el reel',          !empty($r1['ok']), json_encode($r1));
    ok('con su origen',
       (string)$pdo->query("SELECT origen FROM crecer_activos WHERE id="
                           . (int)$r1['activo_id'])->fetchColumn() === 'reel',
       'que lo montamos nosotros con sus clips es la verdad, y se dice');

    //  IDEMPOTENTE POR RUTA: cerrar el mismo reel dos veces no deja dos filas.
    $r2 = material_registrar_archivo($pdo, $M, "marca_{$M}/reels/prueba.mp4", 'video',
                                     'Reel de prueba', 'reel');
    ok('registrarlo otra vez devuelve el mismo',
       (int)$r2['activo_id'] === (int)$r1['activo_id'] && !empty($r2['repetido']),
       json_encode($r2));
    ok('y no creó una segunda fila',
       $cnt('crecer_activos', "marca_id={$M} AND archivo='marca_{$M}/reels/prueba.mp4'") === 1);

    //  NINGUNA RUTA CON `..`
    $mal = material_registrar_archivo($pdo, $M, "../../etc/passwd", 'video', 'X', 'reel');
    ok('una ruta que sube de nivel se rechaza', empty($mal['ok']), json_encode($mal));

    // ══════════════════════════════════════════════════════════════
    //  3 · reels_cerrar_pieza YA NO ESCRIBE MEDIA POR SU CUENTA
    // ══════════════════════════════════════════════════════════════
    echo "\n  — y el reel entra por la puerta —\n";
    $rl = codigo('includes/reels.php');
    $i  = mb_strpos($rl, 'function reels_cerrar_pieza');
    $cuerpo = $i !== false ? mb_substr($rl, (int)$i, 4000) : '';
    ok('reels_cerrar_pieza llama al dominio',
       str_contains($cuerpo, 'material_aplicar'), 'era el último escritor directo');
    ok('y registra el video como material suyo',
       str_contains($cuerpo, 'material_registrar_archivo'));
    ok('pone el tipo ANTES de aplicar',
       mb_strpos($cuerpo, "tipo='reel'") < mb_strpos($cuerpo, 'material_aplicar'),
       'al revés, material_aplicar rechazaría su propio video por incompatible');

    // ══════════════════════════════════════════════════════════════
    //  4 · Y SIGUE FUNCIONANDO · la puerta de verdad
    // ══════════════════════════════════════════════════════════════
    echo "\n  — con todo enrutado, aplicar sigue igual de barato —\n";
    $pdo->prepare("INSERT INTO crecer_contenido
            (marca_id,plataforma,tipo,caption,estado,fecha_programada,grafica_path)
          VALUES (?, 'instagram','reel',?, 'borrador', DATE_ADD(NOW(), INTERVAL 2 DAY), ?)")
        ->execute([$M, '[prueba] Texto intacto.', '/crecer/uploads/marca_x/viejo.png']);
    $C = (int)$pdo->lastInsertId();
    $ia0 = $cnt('crecer_ia_log'); $cu0 = $cnt('crecer_img_cuota_asiento');

    $apl = material_aplicar($pdo, $M, $C, (int)$r1['activo_id']);
    ok('el reel se aplica',       !empty($apl['ok']), json_encode($apl));
    ok('y queda trazado',
       (int)$pdo->query("SELECT material_activo_id FROM crecer_contenido WHERE id={$C}")
                ->fetchColumn() === (int)$r1['activo_id']);
    ok('sin tocar el texto',
       (string)$pdo->query("SELECT caption FROM crecer_contenido WHERE id={$C}")
                   ->fetchColumn() === '[prueba] Texto intacto.');

    //  Y SOLTAR DICE LA VERDAD AL REVÉS: en cuanto encima va arte generado, la
    //  pieza deja de decir que lleva material suyo.
    material_soltar($pdo, $M, $C);
    ok('y soltar la borra',
       $pdo->query("SELECT material_activo_id FROM crecer_contenido WHERE id={$C}")
           ->fetchColumn() === null,
       'si no, el origen seguiría diciendo «tu foto» sobre arte generado');

    ok('cero llamadas al modelo', $cnt('crecer_ia_log') === $ia0);
    ok('cero cuota',              $cnt('crecer_img_cuota_asiento') === $cu0,
       'montar el reel ya se pagó donde tocaba; pegarlo a la pieza no cuesta');

} catch (Throwable $e) {
    $fallos++; echo "\n  EXCEPCION · " . get_class($e) . ': ' . $e->getMessage()
                  . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    foreach ($limpiar as $mid) { try { Fixture::limpiar($pdo, $mid); } catch (Throwable $e) {} }
    echo "\n  (fixtures limpiadas)\n";
}

echo "\n  — el costo —\n";
ok('cero llamadas al modelo', $cnt('crecer_ia_log') === $g['ia'],
   'antes ' . $g['ia'] . ' · ahora ' . $cnt('crecer_ia_log'));
ok('cero asientos de cuota',  $cnt('crecer_img_cuota_asiento') === $g['cuota']);

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  NO QUEDA OTRA PUERTA · $n afirmaciones\n\n"
    : "  $fallos FALLAS de $n\n\n";
exit($fallos === 0 ? 0 : 1);
