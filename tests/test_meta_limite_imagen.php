<?php
// ============================================================
//  CRECER — EL LÍMITE DE IMÁGENES NO PUEDE QUITARLE AL DUEÑO
//  LO QUE SÍ PUEDE HACER
//  tests/test_meta_limite_imagen.php
//
//  La primera versión decidía por la LETRA del estado:
//
//      $manda = in_array($E->estado, [E_CRECER_TRABAJA, G_MATERIAL]);
//
//  Con eso, una dueña con el mes agotado a la que Crecer le pedía una foto
//  se encontraba la pantalla del límite y SIN el botón de subirla — cuando
//  subir su propia foto no gasta ni una imagen de la cuota. Le quitaba justo
//  lo único que podía hacer.
//
//  La pregunta correcta es otra: ¿lo próximo necesita PINTAR algo nuevo?
//  Estos son los casos que lo fijan. Ni base de datos ni navegador: la
//  decisión es una función pura y se ejerce como tal.
// ============================================================

require_once __DIR__ . '/../core/Meta/MetaStateComposer.php';
require_once __DIR__ . '/../core/Meta/MetaLimiteImagen.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nEL LÍMITE DE IMÁGENES · ¿cuándo manda de verdad?\n" . str_repeat('=', 56) . "\n";

/** Retrato mínimo: meta activa, plan, y lo que cada caso añada. */
function snap(array $extra = []): array {
    return array_replace_recursive([
        'marca_id' => 501,
        'hoy'      => '2026-08-21 12:00:00',
        'meta'     => ['id' => 7, 'objetivo' => 'pedidos', 'cantidad' => 25.0,
                       'fecha_inicio' => '2026-08-01', 'fecha_limite' => '2026-08-31',
                       'estado' => 'activa'],
        'progreso' => ['actual' => 9.0, 'pct' => 36, 'dias_rest' => 10,
                       'ritmo_dia' => 1.6, 'al_dia' => true, 'vencida' => false],
        'plan'     => ['id' => 3, 'version' => 1, 'inicio_at' => '2026-08-01 09:00:00',
                       'presentado_at' => '2026-08-01 09:05:00'],
        'jugadas'  => [], 'piezas' => [], 'jobs' => [], 'plan_cerrado' => null,
        'semana_actual' => 3, 'plan_generandose' => false,
    ], $extra);
}
function jugada(array $x = []): array {
    return array_replace(['id' => 41, 'orden' => 1, 'semana' => 1, 'clase' => 'produccion',
                          'formato' => 'post', 'piezas_meta' => 1, 'estado' => 'pendiente',
                          'inversion' => null, 'titulo' => 'La historia del bizcocho'], $x);
}
function pieza(array $x = []): array {
    return array_replace(['id' => 900, 'tactica_id' => 41, 'tipo' => 'post', 'estado' => 'borrador',
                          'necesita_material' => null, 'guion' => null, 'fecha_programada' => null,
                          'publicado_at' => null, 'tiene_metricas' => false,
                          'plataforma' => 'instagram', 'caption' => 'Tres leches de verdad.'], $x);
}

/** El libro de la cuota, agotado. */
$LLENA  = ['usadas' => 40, 'limite' => 40, 'restantes' => 0, 'lleno' => true,
           'exento' => false, 'reset' => '01/09'];
$QUEDAN = ['usadas' => 12, 'limite' => 40, 'restantes' => 28, 'lleno' => false,
           'exento' => false, 'reset' => '01/09'];

// ══════════════════════════════════════════════════════════════
//  1 · G · VIDEO — el dueño graba con su celular
// ══════════════════════════════════════════════════════════════
echo "\n  — cuota llena · te piden un video —\n";
$e = MetaStateComposer::componer(snap([
    'jugadas' => [jugada()],
    'piezas'  => [pieza(['necesita_material' => 'video', 'tipo' => 'reel'])],
]));
ok('el estado es G', $e->estado === MetaState::G_MATERIAL, "salió {$e->estado} · {$e->razon}");
ok('«Grabar ahora» sigue en pie', !MetaLimiteImagen::manda($e, $LLENA),
   'grabar con el celular no gasta ni una imagen de la cuota: esconderlo le '
 . 'quitaría a la dueña lo único que puede hacer hoy');
ok('y la acción es la suya', ($e->accion['etiqueta'] ?? '') === 'Grabar ahora',
   'salió: ' . ($e->accion['etiqueta'] ?? '—'));

// ══════════════════════════════════════════════════════════════
//  2 · G · FOTO — el dueño sube una foto suya
// ══════════════════════════════════════════════════════════════
echo "\n  — cuota llena · te piden una foto —\n";
$e = MetaStateComposer::componer(snap([
    'jugadas' => [jugada()],
    'piezas'  => [pieza(['necesita_material' => 'foto'])],
]));
ok('el estado es G', $e->estado === MetaState::G_MATERIAL, "salió {$e->estado}");
ok('«Subirlo» sigue en pie', !MetaLimiteImagen::manda($e, $LLENA));
ok('y la acción es la suya', ($e->accion['etiqueta'] ?? '') === 'Subirlo',
   'salió: ' . ($e->accion['etiqueta'] ?? '—'));

// ══════════════════════════════════════════════════════════════
//  3 · F · APROBAR — la imagen ya existe
// ══════════════════════════════════════════════════════════════
echo "\n  — cuota llena · hay algo que aprobar —\n";
$e = MetaStateComposer::componer(snap([
    'jugadas' => [jugada()],
    'piezas'  => [pieza(['estado' => 'borrador'])],
]));
ok('el estado es F', $e->estado === MetaState::F_APROBACION, "salió {$e->estado}");
ok('aprobar sigue en pie', !MetaLimiteImagen::manda($e, $LLENA),
   'la imagen de esa pieza ya está hecha y pagada: aprobarla no pinta nada');

// ══════════════════════════════════════════════════════════════
//  4 · E · JOB VIVO — ya está corriendo
// ══════════════════════════════════════════════════════════════
echo "\n  — cuota llena · un trabajo ya en marcha —\n";
$e = MetaStateComposer::componer(snap([
    'jugadas' => [jugada(['estado' => 'en_proceso'])],
    'jobs'    => [['id' => 12, 'tactica_id' => 41, 'estado' => 'working']],
]));
ok('el estado es E', $e->estado === MetaState::E_CRECER_TRABAJA, "salió {$e->estado} · {$e->razon}");
ok('NO se declara pausado', !MetaLimiteImagen::manda($e, $LLENA),
   'su unidad ya está reservada: que se acabe el mes no para un trabajo que ya arrancó');
ok('y no se inventa un objeto en pausa', MetaLimiteImagen::objetoPausado($e) === []);

// ══════════════════════════════════════════════════════════════
//  5 · E · PRODUCCIÓN SIN EMPEZAR — aquí SÍ manda
// ══════════════════════════════════════════════════════════════
echo "\n  — cuota llena · queda un POST por producir —\n";
$e = MetaStateComposer::componer(snap([
    //  OJO AL FORMATO: la primera version usaba un REEL para decir «produccion
    //  que si necesita imagen», y el reel es justo el que NO pinta. La prueba
    //  de mas abajo lo cazo. Aqui va un POST, que si cae a generar_grafica().
    'jugadas' => [jugada(['id' => 55, 'titulo' => 'La historia del flan de coco', 'formato' => 'post'])],
]));
ok('el estado es E', $e->estado === MetaState::E_CRECER_TRABAJA, "salió {$e->estado}");
ok('la razón es producción pendiente', $e->razon === 'produccion_pendiente_sin_piezas',
   "salió {$e->razon}");
ok('el estado declara que va a pintar', MetaLimiteImagen::necesitaImagen($e),
   'sin esa declaración la pantalla tendría que adivinarlo por la letra del estado');
ok('AQUÍ SÍ manda el límite', MetaLimiteImagen::manda($e, $LLENA));
//  Y el control que hace falsable lo anterior: con cuota, la misma pantalla
//  no manda. Si esto saliera verde con cuota, la regla sería «siempre manda».
ok('pero con cuota NO manda', !MetaLimiteImagen::manda($e, $QUEDAN),
   'si mandara también con cuota, la regla no estaría mirando el libro');

// ══════════════════════════════════════════════════════════════
//  5b · FORMATO POR FORMATO — no vale «es producción, luego pinta»
//
//  Declararlo para toda jugada de producción era generalizar por categoría,
//  el mismo error que se acababa de corregir un piso más arriba. Lo que
//  manda es lo que hace includes/meta_ejecutar.php con CADA formato:
//
//    post/historia/mixto → cae a generar_grafica(): llega al proveedor.
//    reel                → escribe el guion, pide video y hace `continue`
//                          ANTES del arte. No pinta.
//    carrusel            → carrusel_generar() escribe caption y slides en
//                          crecer_carrusel y devuelve. Las imágenes se piden
//                          después, desde la pantalla del carrusel.
// ══════════════════════════════════════════════════════════════
echo "
  — por formato, siguiendo al ejecutor —
";
$porFormato = [
    ['post',     true,  'cae a generar_grafica()'],
    ['historia', true,  'misma caída que el post'],
    ['mixto',    true,  'puede producir posts'],
    ['reel',     false, 'escribe el guion y pide video: `continue` antes del arte'],
    ['carrusel', false, 'escribe los slides; las imágenes se piden después'],
];
foreach ($porFormato as [$fmt, $pinta, $porque]) {
    $x = MetaStateComposer::componer(snap([
        'jugadas' => [jugada(['id' => 60, 'formato' => $fmt,
                              'titulo' => 'Jugada de ' . $fmt])],
    ]));
    ok("{$fmt} · compone producción pendiente",
       $x->razon === 'produccion_pendiente_sin_piezas', "salió {$x->razon}");
    ok("{$fmt} · " . ($pinta ? 'SÍ pinta' : 'NO pinta') . " — {$porque}",
       MetaLimiteImagen::necesitaImagen($x) === $pinta,
       'declaró consume=' . json_encode($x->evidencia['consume'] ?? null));
    ok("{$fmt} · y el límite " . ($pinta ? 'manda' : 'NO manda') . ' con el mes agotado',
       MetaLimiteImagen::manda($x, $LLENA) === $pinta,
       $pinta ? '' : 'bloquear un reel o un carrusel es pararle al corillo un '
                   . 'trabajo que puede hacer perfectamente sin cuota');
}
//  Un formato que nadie ha declarado se trata como los que pintan: es lo que
//  hace el ejecutor (cae a 'post') y es el lado seguro para el tope del mes.
$raro = MetaStateComposer::componer(snap([
    'jugadas' => [jugada(['id' => 61, 'formato' => 'formato_que_no_existe'])],
]));
ok('un formato desconocido se trata como los que pintan',
   MetaLimiteImagen::necesitaImagen($raro));

// ══════════════════════════════════════════════════════════════
//  6 · EL OBJETO PAUSADO ES LA JUGADA DE VERDAD
// ══════════════════════════════════════════════════════════════
echo "\n  — lo que se enseña en pausa —\n";
$o = MetaLimiteImagen::objetoPausado($e);
ok('trae un objeto', $o !== []);
ok('y es la jugada que el compositor eligió',
   ($o['titulo'] ?? '') === 'La historia del flan de coco',
   'salió: «' . ($o['titulo'] ?? '—') . '» · tiene que ser la pieza real, no un ejemplo');
ok('con su formato', ($o['tipo'] ?? '') === 'post', 'salió: ' . ($o['tipo'] ?? '—'));

// ══════════════════════════════════════════════════════════════
//  7 · LO QUE SIGUE PASANDO SE DEMUESTRA, NO SE PROMETE
// ══════════════════════════════════════════════════════════════
echo "\n  — «qué sigue pasando» sale del retrato —\n";
$vacio = MetaLimiteImagen::sigueHaciendo(snap(), '01/09');
$titulos = implode(' | ', array_column($vacio, 'titulo'));
ok('sin nada aprobado, no promete publicar',
   strpos($titulos, 'Publico') === false,
   "salió: {$titulos} · esta marca no tiene ni una pieza aprobada");
ok('ni promete contestar mensajes',
   stripos($titulos, 'mensaje') === false,
   'no hay señal de canales conectados en el retrato: afirmarlo sería inventarlo');
ok('pero sí dice cuándo vuelve la cuota', strpos($titulos, '01/09') !== false,
   "salió: {$titulos} · esa es del plan y vale para cualquiera");

$con = MetaLimiteImagen::sigueHaciendo(snap([
    'piezas' => [
        pieza(['id' => 901, 'estado' => 'aprobado', 'fecha_programada' => '2026-08-25 18:00:00']),
        pieza(['id' => 902, 'estado' => 'aprobado', 'fecha_programada' => '2026-08-27 18:00:00']),
        pieza(['id' => 903, 'estado' => 'borrador']),
    ],
]), '01/09');
$t2 = implode(' | ', array_column($con, 'titulo'));
ok('con dos aprobadas, lo dice con su número',
   strpos($t2, 'Publico las 2 que ya aprobaste') !== false, "salió: {$t2}");
ok('y cuenta la que espera OK',
   strpos($t2, '1 pieza esperando tu OK') !== false, "salió: {$t2}");

// ══════════════════════════════════════════════════════════════
//  8 · LOS DEMÁS ESTADOS NO SE BLOQUEAN NUNCA POR CUOTA
// ══════════════════════════════════════════════════════════════
echo "\n  — ningún otro estado se queda sin su acción —\n";
$otros = [
    'A · sin meta'      => snap(['meta' => null]),
    'C · plan por ver'  => snap(['plan' => ['presentado_at' => null], 'jugadas' => [jugada()]]),
    'M · cerrada'       => (function () { $s = snap(); $s['meta']['estado'] = 'lograda'; return $s; })(),
    'H · inversión'     => snap(['jugadas' => [jugada(['clase' => 'accion_dueno', 'inversion' => 15.0])]]),
];
foreach ($otros as $etq => $s) {
    $x = MetaStateComposer::componer($s);
    ok("{$etq} → el límite no manda", !MetaLimiteImagen::manda($x, $LLENA),
       "estado {$x->estado} · razón {$x->razon}");
}

echo "\n" . str_repeat('=', 56) . "\n";
echo $fallos === 0
    ? "  TODO OK · {$n} afirmaciones\n\n"
    : "  {$fallos} de {$n} · el límite todavía se lleva algo que no le toca\n\n";
exit($fallos === 0 ? 0 : 1);
