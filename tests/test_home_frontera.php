<?php
// ============================================================
//  CRECER — LA FRONTERA DE HOME SE CRUZA UNA SOLA VEZ  (Fase 5)
//  tests/test_home_frontera.php
//
//  La paridad prueba que Home y Tu Meta DICEN lo mismo hoy. Esto prueba que
//  puedan seguir diciéndolo mañana.
//
//  POR QUÉ HACE FALTA UNA PRUEBA QUE LEA CÓDIGO
//
//  La primera versión de la Fase 5 calculaba `$__E->resumen()`, lo dejaba
//  muerto, y después renderizaba la tarjeta leyendo el estado completo, la
//  evidencia y el snapshot — igual que antes. Las dos pantallas coincidían, sí,
//  pero por casualidad: la frontera existía en el comentario y no en el código.
//
//  Y esa clase de defecto NO la caza una prueba de comportamiento. Todo estaba
//  verde. Basta con que alguien añada un `if ($__E->evidencia[...])` dentro de
//  la tarjeta para volver a tener dos pantallas decidiendo por su cuenta, y la
//  paridad seguiría verde hasta el día en que divergieran.
//
//  Por eso esto mira el FUENTE: es lo único que puede vigilar una frontera.
//
//  LO QUE SE EXIGE
//
//    · La frontera existe y está marcada: se cruza en un solo sitio.
//    · Debajo de ella, la tarjeta NO nombra el estado, la evidencia ni el
//      snapshot. No porque esté prohibido: porque no los tiene.
//    · El DTO trae exactamente lo acordado, ni más ni menos. Uno de más es
//      volver a filtrar el detalle; uno de menos obliga a Home a buscarlo.
//    · Tu Meta SÍ puede seguir usando el estado completo: es la superficie
//      operativa, no un resumen.
//
//  CERO PROVEEDORES y cero base de datos: esto lee archivos.
// ============================================================

require_once __DIR__ . '/../core/Meta/MetaState.php';
require_once __DIR__ . '/../core/Meta/MetaPresentador.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nLA FRONTERA DE HOME · leída en el fuente\n" . str_repeat('=', 58) . "\n";

$home = (string)file_get_contents(dirname(__DIR__) . '/panel/index.php');
$pres = (string)file_get_contents(dirname(__DIR__) . '/core/Meta/MetaPresentador.php');

// ══════════════════════════════════════════════════════════════
//  1 · LA FRONTERA EXISTE Y SE CRUZA UNA VEZ
// ══════════════════════════════════════════════════════════════
echo "\n  — 1 · se cruza una sola vez —\n";
ok('Home construye el DTO', substr_count($home, 'MetaPresentador::paraHome') === 1,
   'aparece ' . substr_count($home, 'MetaPresentador::paraHome') . ' veces · dos llamadas son dos verdades');
ok('y suelta el estado y el snapshot al cruzar',
   preg_match('~unset\(\s*\$__E\s*,\s*\$__snap~', $home) === 1,
   'sin soltarlos siguen a mano, y lo que está a mano se acaba usando');

$corte = strpos($home, 'unset($__E, $__snap');
ok('la frontera está donde se puede señalar', $corte !== false);

// ══════════════════════════════════════════════════════════════
//  2 · DEBAJO, LA TARJETA NO VE EL DETALLE
// ══════════════════════════════════════════════════════════════
echo "\n  — 2 · debajo de la frontera, solo el DTO —\n";
//  La tarjeta va desde la frontera hasta la primera tarjeta que NO es suya.
//  Ese límite cambió en la Fase 5 —la tarjeta del «próximo post» se convirtió
//  en el adelanto del calendario—, así que el ancla es ahora el comentario que
//  abre los bloques del centro de mando. La regla no cambia: de la frontera
//  hacia abajo, la tarjeta de la Meta solo puede ver su DTO.
$finTarjeta = strpos($home, 'EL RESTO DEL CENTRO DE MANDO');
ok('se encuentra dónde acaba la tarjeta', $finTarjeta !== false && $finTarjeta > (int)$corte);

$region = substr($home, (int)$corte, max(0, (int)$finTarjeta - (int)$corte));
//  Se quita la propia línea del unset y los comentarios: nombrar algo para
//  explicar que ya no se usa es justo lo que hay que conservar.
$region = preg_replace('~^.*?unset\([^)]*\);~s', '', $region);
$regionViva = preg_replace('~^\s*//.*$~m', '',
              preg_replace('~/\*.*?\*/~s', '', $region));

$prohibido = [
    '$__E'        => 'el estado completo',
    '$__snap'     => 'el snapshot',
    '$__res'      => 'el resumen suelto (murió con la frontera)',
    'evidencia'   => 'la evidencia del estado',
    'MetaState::' => 'las constantes del estado',
    '$__prog'     => 'el progreso crudo del snapshot',
    '$__meta['    => 'la meta cruda del snapshot',
    'meta_fmt'    => 'el formateador del dominio (el DTO ya trae las cifras hechas)',
    'meta_objetivo_def' => 'el catálogo de objetivos',
    'MetaSnapshotReader' => 'el lector',
    'MetaStateComposer'  => 'el compositor',
];
$colados = [];
foreach ($prohibido as $t => $que) {
    if (strpos($regionViva, $t) !== false) $colados[] = $t . ' (' . $que . ')';
}
ok('la tarjeta no nombra nada del detalle', $colados === [],
   implode(' · ', $colados) . "\n"
 . '         si vuelve a estar a mano, vuelve a decidir — y ahí nace la contradicción');

ok('y sí consume el DTO', substr_count($regionViva, '$__hm') >= 6,
   'lo usa ' . substr_count($regionViva, '$__hm') . ' veces');

// ══════════════════════════════════════════════════════════════
//  3 · EL DTO TRAE EXACTAMENTE LO ACORDADO
// ══════════════════════════════════════════════════════════════
echo "\n  — 3 · ni una clave de más, ni una de menos —\n";
//  Se construye uno de verdad, con un estado hecho a mano: sin base y sin red.
$E = new MetaState(
    MetaState::F_APROBACION,
    'Tengo algo listo para tu OK',
    'Una pieza espera tu visto bueno.',
    ['etiqueta' => 'Revisar y aprobar', 'destino' => '/crecer/panel/aprobar2.php?marca=7&ver=1',
     'consecuencia' => 'Sale a su hora.', 'tipo' => 'aprobacion'],
    ['contenido_id' => 1, 'objeto' => ['titulo' => 'El combo del viernes', 'red' => 'Instagram']],
    [], 'parcial', 'pieza_espera_aprobacion'
);
$dto = MetaPresentador::paraHome($E, [], ['meta' => null, 'progreso' => []], '/crecer/panel', 7);

$esperadas = ['estado', 'sin_meta', 'cerrada', 'titulo', 'turno', 'accion',
              'objeto', 'cifra', 'dias', 'puede', 'barra'];
sort($esperadas);
$llegan = array_keys($dto); sort($llegan);
ok('las claves son exactamente las acordadas', $llegan === $esperadas,
   'sobran: ' . implode(', ', array_diff($llegan, $esperadas))
 . ' · faltan: ' . implode(', ', array_diff($esperadas, $llegan)));

ok('el objeto viene RESUMIDO, no entero',
   array_keys($dto['objeto']) === ['titulo'],
   json_encode(array_keys($dto['objeto']))
 . ' · con el objeto entero, Home volvería a poder decidir con el detalle');
ok('no viaja la evidencia', !isset($dto['evidencia']));
ok('ni la cobertura en crudo', !isset($dto['cobertura']),
   'se resuelve en un booleano: si viajara, Home tendría que interpretarla otra vez');
ok('sin cobertura completa, la barra NO existe', $dto['barra'] === null,
   json_encode($dto['barra']) . ' · null no es «barra vacía»: es que no hay barra');
ok('el destino sale del compositor',
   $dto['accion']['destino'] === '/crecer/panel/aprobar2.php?marca=7&ver=1',
   $dto['accion']['destino'] . ' · la acción abre el objeto exacto, no siempre Tu Meta');
ok('y el título es el del estado', $dto['titulo'] === 'Tengo algo listo para tu OK',
   $dto['titulo']);

//  El caso sin acción: el DTO tiene que traer una salida igualmente.
$E2 = new MetaState(MetaState::E_CRECER_TRABAJA, 'Estoy trabajando', 'El corillo produce.',
                    [], [], [], 'parcial', 'produccion_en_curso');
$dto2 = MetaPresentador::paraHome($E2, [], [], '/crecer/panel', 7);
ok('sin acción del estado, el destino cae a Tu Meta',
   strpos($dto2['accion']['destino'], '/meta.php?marca=7') !== false,
   $dto2['accion']['destino'] . ' · una tarjeta que no lleva a ningún sitio es decorativa');
ok('y conserva la marca', strpos($dto2['accion']['destino'], 'marca=7') !== false);

// ══════════════════════════════════════════════════════════════
//  4 · TU META SIGUE SIENDO LA SUPERFICIE OPERATIVA
// ══════════════════════════════════════════════════════════════
echo "\n  — 4 · Tu Meta sí puede con el estado entero —\n";
$meta = (string)file_get_contents(dirname(__DIR__) . '/panel/meta.php');
ok('Tu Meta usa el estado completo', strpos($meta, '$E->evidencia') !== false,
   'no es una incoherencia: ahí se opera sobre el objeto, no se le nombra');
ok('y no construye el DTO de Home', strpos($meta, 'paraHome') === false,
   'el resumen es para quien resume; Tu Meta no resume');
ok('las dos pasan por el mismo presentador',
   strpos($meta, 'MetaPresentador::') !== false && strpos($home, 'MetaPresentador::') !== false,
   'es lo que garantiza que digan la misma frase');

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  FRONTERA LIMPIA · {$n} afirmaciones\n\n"
    : "  {$fallos} de {$n} · la frontera se está cruzando\n\n";
exit($fallos === 0 ? 0 : 1);
