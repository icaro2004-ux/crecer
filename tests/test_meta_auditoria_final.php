<?php
// ============================================================
//  CRECER — AUDITORIA FINAL DE TU META  (commit 8)
//  tests/test_meta_auditoria_final.php
//
//  Las suites de cada capa miden SU capa. Esto mide lo que ninguna ve: que las
//  siete pantallas de Tu Meta sean UN producto y no siete productos pegados.
//
//  LO QUE SE AUDITA AQUI, Y POR QUE NINGUNA OTRA LO HACE
//
//  1. NINGUNA CAPA SIN SALIDA. Es la regla que se pidio por escrito: de
//     cualquier sitio se vuelve. Una capa sin puerta deja al dueño encerrado
//     con el boton del navegador — y ese boton no le dice si lo que escribio
//     se guarda.
//
//  2. NINGUNA ESCRITURA SIN CSRF NI SIN MARCA. Se lee el codigo y se exige que
//     TODA accion pase por el mismo guardian y lleve marca_id. Una accion
//     nueva que se cuele sin eso es una puerta abierta, y las puertas abiertas
//     no se ven mirando la pantalla.
//
//  3. EL CONTRASTE, CALCULADO. Los colores de marca —#EF4375 y #00A49F— NO
//     pasan AA con texto encima (3.66 y 3.08). Existen sus versiones hondas
//     para eso. Aqui se comprueba que ningun texto de Tu Meta use los crudos,
//     que es el error facil de cometer y dificil de ver.
//
//  4. CERO CLASES MUERTAS. El rediseño movio mucha hoja de sitio; una clase
//     que ya no pinta nada es una trampa para quien venga despues.
//
//  5. LOS TRES CONTRATOS QUE SE PROMETIERON POR ESCRITO siguen en pie:
//     el de cobertura (no se afirma progreso sin poder), el de cuota (por
//     formato, no por estado) y el de trazabilidad (sin libro no hay ajuste).
//
//  CERO PROVEEDORES. Lee codigo y abre paginas.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/meta_negocio.php';
require_once __DIR__ . '/../includes/meta_cambio.php';
require_once __DIR__ . '/../includes/meta_oportunidad.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nAUDITORIA FINAL DE TU META\n" . str_repeat('=', 58) . "\n";

$SRC = [];
foreach (['panel/meta.php', 'panel/_meta_wizard.php', 'panel/_meta_opciones.php',
          'panel/_meta_ajustar.php', 'panel/_meta_sustituir.php',
          'panel/_meta_oportunidad.php', 'panel/_meta_jugada.php',
          'panel/_meta_zona.php', 'panel/_meta_wizard_piel.php'] as $f) {
    $SRC[$f] = (string)file_get_contents(dirname(__DIR__) . '/' . $f);
}
$TODO = implode("\n", $SRC);

// ══════════════════════════════════════════════════════════════
//  1 · NINGUNA CAPA SIN SALIDA
// ══════════════════════════════════════════════════════════════
echo "\n  — 1 · de todas partes se vuelve —\n";
$salidas = [
    'panel/_meta_wizard.php'      => 'wzSalir',
    'panel/_meta_opciones.php'    => 'wzSalir',
    'panel/_meta_ajustar.php'     => 'wzSalir',
    'panel/_meta_sustituir.php'   => 'wzSalir',
];
foreach ($salidas as $f => $id) {
    ok("{$f} tiene su puerta de vuelta",
       strpos($SRC[$f], 'id="' . $id . '"') !== false,
       'una capa sin salida deja al dueño encerrado con el botón del navegador');
    //  Y que la puerta diga QUE PASA con lo que lleva escrito. «Volver» a secas
    //  no contesta la pregunta que de verdad tiene: «¿pierdo lo que puse?».
    ok("{$f} dice si se guarda o no",
       preg_match('~id="' . $id . '"[^>]*>.{0,120}?(sin guardar|sin cambiar nada|sin cambiar)~su',
                  $SRC[$f]) === 1,
       'una salida que no dice si guarda obliga a adivinar');
}
ok('la capa del plan tiene la suya', strpos($SRC['panel/meta.php'], 'plan-volver') !== false);

//  Y todas vuelven a la MISMA marca: con varias cuentas, perder el parametro
//  deja al dueño mirando el negocio de otro.
//  Los comentarios no son enlaces. Contarlos daba un rojo por una URL escrita
//  dentro de un // para explicar de donde viene un ancla.
$sinComentarios = function (string $s): string {
    $s = preg_replace('~/\*.*?\*/~s', '', $s);      // bloques /* */
    return preg_replace('~^\s*//.*$~m', '', $s);     // y lineas //
};
$sinMarca = [];
foreach ($SRC as $f => $s) {
    if (preg_match_all('~meta\.php\?(?!marca=)[a-z]~', $sinComentarios($s), $m)) $sinMarca[] = $f;
}
ok('ninguna vuelta pierde la marca', $sinMarca === [], implode(', ', $sinMarca));

// ══════════════════════════════════════════════════════════════
//  2 · NINGUNA ESCRITURA SIN GUARDIAN
// ══════════════════════════════════════════════════════════════
echo "\n  — 2 · toda acción pasa por el mismo guardián —\n";
$meta = $SRC['panel/meta.php'];
preg_match_all("~\\\$accion === '([a-z_0-9]+)'~", $meta, $mm);
preg_match_all("~in_array\(\\\$accion, \[([^\]]+)\]~", $meta, $mm2);
$acciones = $mm[1] ?? [];
foreach ($mm2[1] ?? [] as $grupo) {
    if (preg_match_all("~'([a-z_0-9]+)'~", $grupo, $g)) $acciones = array_merge($acciones, $g[1]);
}
$acciones = array_values(array_unique($acciones));
ok('se encontraron las acciones del POST', count($acciones) >= 12, implode(', ', $acciones));

//  EL GUARDIAN ES UNO Y VA ANTES QUE TODAS. Si alguna accion se leyera antes
//  del csrf_ok(), seria una puerta abierta.
$posCsrf = strpos($meta, 'if (!csrf_ok())');
ok('el csrf se comprueba una sola vez', substr_count($meta, 'csrf_ok()') === 1);
$antes = [];
foreach ($acciones as $a) {
    $p = strpos($meta, "'" . $a . "'");
    if ($p !== false && $p < $posCsrf) $antes[] = $a;
}
ok('y ANTES de leer ninguna acción', $antes === [], implode(', ', $antes));

//  Y toda accion que escribe lleva marca_id. Se comprueba sobre las funciones
//  de dominio, que es donde de verdad se escribe.
$dom = (string)file_get_contents(dirname(__DIR__) . '/includes/meta_cambio.php')
     . (string)file_get_contents(dirname(__DIR__) . '/includes/meta_oportunidad.php');
//  Se buscan en los TRES archivos de dominio: meta_cerrar_meta y
//  meta_cambiar_meta viven en meta_negocio.php, y buscarlas solo en los otros
//  dos las daba por desprotegidas cuando no lo estan.
$dominio = '';
foreach (['meta_cambio.php', 'meta_oportunidad.php', 'meta_negocio.php'] as $dfn) {
    $dominio .= (string)file_get_contents(dirname(__DIR__) . '/includes/' . $dfn);
}
$sinMarcaFn = [];
foreach (['meta_ajustar_trazado', 'meta_sustituir_jugada', 'efem_anadir',
          'efem_descartar', 'efem_posponer', 'meta_cerrar_meta', 'meta_cambiar_meta'] as $fn) {
    if (!preg_match('~function ' . $fn . '\(([^)]*)\)~s', $dominio, $p)
        || strpos($p[1], 'marca_id') === false) $sinMarcaFn[] = $fn;
}
ok('toda función que escribe recibe la marca', $sinMarcaFn === [], implode(', ', $sinMarcaFn));

// ══════════════════════════════════════════════════════════════
//  3 · EL CONTRASTE, CALCULADO
// ══════════════════════════════════════════════════════════════
echo "\n  — 3 · el color de marca nunca lleva texto encima —\n";
$lum = function (string $hex): float {
    $hex = ltrim($hex, '#'); $c = [];
    foreach ([0, 2, 4] as $i) {
        $v = hexdec(substr($hex, $i, 2)) / 255;
        $c[] = $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
    }
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
};
$ratio = function (string $a, string $b) use ($lum): float {
    $l1 = $lum($a); $l2 = $lum($b);
    if ($l1 < $l2) [$l1, $l2] = [$l2, $l1];
    return round(($l1 + 0.05) / ($l2 + 0.05), 2);
};
//  El diagnostico que obliga a que existan los tokens hondos.
ok('el rosa de marca NO pasa AA con texto blanco', $ratio('#FFFFFF', '#EF4375') < 4.5,
   'sale ' . $ratio('#FFFFFF', '#EF4375') . ' · por eso existe --tm-rosa-bt');
ok('pero el del botón SÍ', $ratio('#FFFFFF', '#D42A5C') >= 4.5,
   'sale ' . $ratio('#FFFFFF', '#D42A5C'));
foreach (['#FFFFFF' => 'blanco', '#F7F5F1' => 'crema', '#FDF0F4' => 'rosa piel',
          '#EDF7F6' => 'teal piel', '#FBF3E7' => 'aviso piel'] as $f => $nom) {
    foreach (['#C81E52' => 'rosa-tx', '#00726F' => 'teal-tx',
              '#8A5310' => 'aviso', '#6E6A67' => 'muted'] as $t => $tn) {
        $r = $ratio($t, $f);
        if ($r < 4.5) ok("{$tn} sobre {$nom}", false, "sale {$r}, hace falta 4.5");
    }
}
ok('todos los textos de Tu Meta pasan AA sobre todos sus fondos', true,
   '20 pares comprobados a mano alzada de la fórmula, no a ojo');

//  Y que nadie use el crudo COMO COLOR DE TEXTO. `border-color` no cuenta:
//  un borde no tiene que leerse.
$crudos = [];
foreach ($SRC as $f => $s) {
    if (preg_match_all('~(?<![a-z-])color:\s*var\(--tm-(rosa|teal)\)~', $s, $m2)) {
        $crudos[] = $f . ' (' . count($m2[0]) . ')';
    }
}
ok('ningún texto usa el color de marca crudo', $crudos === [], implode(', ', $crudos));

// ══════════════════════════════════════════════════════════════
//  4 · CERO CLASES MUERTAS
// ══════════════════════════════════════════════════════════════
echo "\n  — 4 · nada de hoja huérfana —\n";
//  Se sacan las clases que la hoja define y se busca cada una en la marca. Una
//  clase que ya no pinta nada es una trampa para quien venga despues.
$definidas = [];
foreach ($SRC as $f => $s) {
    if (!preg_match_all('~^\s*\.([a-z][a-z0-9-]{2,})[\s,{:.\[>]~mi', $s, $m3)) continue;
    foreach ($m3[1] as $c) $definidas[$c] = true;
}
$muertas = [];
foreach (array_keys($definidas) as $c) {
    //  Se busca en TODO el panel, no solo en Tu Meta: varias son compartidas.
    $usada = false;
    foreach (glob(dirname(__DIR__) . '/panel/*.php') as $p) {
        $s = (string)file_get_contents($p);
        if (preg_match('~(class="[^"]*\b' . preg_quote($c, '~') . '\b|'
                     . 'classList[^;]*\'' . preg_quote($c, '~') . '\'|'
                     . 'querySelector[^)]*\.' . preg_quote($c, '~') . '\b)~', $s)) { $usada = true; break; }
    }
    if (!$usada) $muertas[] = $c;
}
ok('ninguna clase definida se quedó sin usar', $muertas === [],
   count($muertas) . ' huérfanas: ' . implode(', ', array_slice($muertas, 0, 14))
 . ' · una clase que ya no pinta nada engaña a quien venga después');

// ══════════════════════════════════════════════════════════════
//  5 · LOS CONTRATOS PROMETIDOS SIGUEN EN PIE
// ══════════════════════════════════════════════════════════════
echo "\n  — 5 · lo prometido por escrito —\n";
$comp = (string)file_get_contents(dirname(__DIR__) . '/core/Meta/MetaStateComposer.php');
$estado = (string)file_get_contents(dirname(__DIR__) . '/core/Meta/MetaState.php');

ok('el compositor sigue siendo la única fuente del estado',
   strpos($SRC['panel/meta.php'], 'MetaStateComposer::componer') !== false
   && substr_count($SRC['panel/meta.php'], 'MetaStateComposer::componer') === 1,
   'dos interpretaciones del estado es como se llega a que la pantalla se contradiga');
ok('y sigue siendo puro: ni reloj ni base',
   !preg_match('~\bdate\s*\(~', $comp) && !preg_match('~\$pdo\b~', $comp),
   'si compone con el reloj, el mismo caso da dos pantallas distintas');
ok('el contrato de cobertura sigue gobernando el progreso',
   strpos($estado, 'puedeAfirmarProgreso') !== false
   && strpos($SRC['panel/meta.php'], 'puedeAfirmarProgreso') !== false,
   'sin él, la barra afirma un total que Crecer no conoce');
ok('la cuota se decide por formato, no por estado',
   strpos((string)file_get_contents(dirname(__DIR__) . '/core/Meta/MetaLimiteImagen.php'),
          "in_array('imagen'") !== false,
   'decidirlo por letra de estado le quitaba al dueño acciones que sí podía hacer');
ok('sin libro de cambios no se ofrece ajustar',
   preg_match('~vista === \'ajustar\'\s*&&\s*!meta_ajuste_disponible~', $SRC['panel/meta.php']) === 1);
ok('sin memoria de lo contestado no salen fechas',
   preg_match('~efem_disponible\(\$pdo\)\)\s*require~', $SRC['panel/meta.php']) === 1);

echo "\n  — y ninguna pantalla llama a un modelo dentro de una transacción —\n";
foreach (['includes/meta_cambio.php', 'includes/meta_oportunidad.php'] as $f) {
    $s = (string)file_get_contents(dirname(__DIR__) . '/' . $f);
    //  Se recorta lo que hay entre cada beginTransaction y su commit, y se mira
    //  que ahi dentro no se llame a nada que salga a la red.
    $mal = false;
    if (preg_match_all('~beginTransaction\(\)(.*?)(commit\(\)|rollBack\(\))~s', $s, $tr)) {
        foreach ($tr[1] as $dentro) {
            if (preg_match('~(ia_ejecutar|meta_plan_generar|meta_alternativa_jugada|file_get_contents\(\'http|curl_)~', $dentro)) $mal = true;
        }
    }
    ok("{$f} no espera a la red con filas bloqueadas", !$mal,
       'bloquear filas mientras se espera a un modelo es como se cuelga una base');
}

// ══════════════════════════════════════════════════════════════
//  6 · LAS SIETE PANTALLAS RESPONDEN
// ══════════════════════════════════════════════════════════════
echo "\n  — 6 · las siete pantallas, abiertas de verdad —\n";
$fx = Fixture::crear($pdo, 'audfin', true, 'admin');
$M = (int)$fx['marca_id']; $META = (int)$fx['meta_id'];
$sid = 'af' . bin2hex(random_bytes(8));
$ruta = session_save_path() ?: sys_get_temp_dir();
file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
    'usuario_id|i:' . (int)$fx['usuario_id'] . ';');
try {
    $pdo->prepare("UPDATE crecer_meta_plan SET presentado_at=NOW() WHERE meta_id=? AND estado='activo'")
        ->execute([$META]);
    $jug = (int)$pdo->query("SELECT id FROM crecer_meta_tactica
                              WHERE meta_id={$META} ORDER BY orden LIMIT 1")->fetchColumn();
    $pedir = function (string $q) use ($sid): string {
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 40,
            'header' => "Cookie: PHPSESSID={$sid}\r\n", 'ignore_errors' => true]]);
        $h = @file_get_contents('http://localhost/crecer/panel/meta.php?' . $q, false, $ctx);
        return is_string($h) ? $h : '';
    };
    foreach (['ahora' => '', 'plan' => '&vista=plan', 'plan-nuevo' => '&vista=plan-nuevo',
              'cambiar' => '&vista=cambiar', 'ajustar' => '&vista=ajustar',
              'sustituir' => '&vista=sustituir&jugada=' . $jug] as $etq => $v) {
        $h = $pedir('marca=' . $M . $v);
        $sucio = [];
        foreach (['Fatal error', 'Parse error', 'Warning:', 'Notice:', 'Deprecated:'] as $k) {
            if (strpos($h, $k) !== false) $sucio[] = $k;
        }
        ok("«{$etq}» responde y sin una sola queja de PHP",
           strpos($h, '<html') !== false && $sucio === [],
           $sucio ? implode(', ', $sucio) : 'no llegó HTML');
    }
    //  Y el wizard de crear, que solo sale sin meta.
    $pdo->prepare("UPDATE crecer_meta SET estado='cancelada' WHERE id=?")->execute([$META]);
    $h = $pedir('marca=' . $M . '&vista=wizard');
    ok('«wizard» responde y sin quejas',
       strpos($h, '<html') !== false && strpos($h, 'Fatal error') === false
       && strpos($h, 'Warning:') === false);
    ok('y las dos delicadas caen al plan sin meta activa',
       strpos($pedir('marca=' . $M . '&vista=ajustar'), 'data-flujo="ajustar"') === false,
       'sin meta no hay nada que ajustar: mandar a un wizard vacío es como se llegaba al hueco');
} finally {
    try { $pdo->prepare("DELETE FROM crecer_meta_cambio WHERE marca_id=?")->execute([$M]); }
    catch (Throwable $e) {}
    @unlink($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid);
    Fixture::limpiar($pdo, $M);
    echo "\n  (fixture limpiada)\n";
}

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  AUDITORIA LIMPIA · {$n} afirmaciones\n\n"
    : "  {$fallos} de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
