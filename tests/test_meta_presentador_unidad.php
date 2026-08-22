<?php
// ============================================================
//  CRECER — EL PRESENTADOR, A SOLAS  ·  tests/test_meta_presentador_unidad.php
//
//  POR QUE ESTE ARCHIVO EXISTE, Y POR QUE ESTA SEPARADO
//
//  El 22 de agosto un despliegue tumbo produccion entera: includes/i18n.php
//  hacia un `require_once` duro de un archivo NUEVO, y ese require lo ejecuta
//  db.php — el unico que hace toda pagina del producto. El archivo no llego al
//  servidor y murio el sitio, publico y panel, en todos los idiomas.
//
//  core/Meta/MetaPresentador.php repite la forma del problema: panel/index.php
//  y panel/meta.php van a requerirlo. Si no llega, Home muere. Asi que primero
//  se despliega el archivo SOLO —inerte, sin que ninguna pagina lo llame— se
//  comprueba que esta y carga, y solo entonces se conecta.
//
//  Esta prueba es la mitad medible de ese primer paso: demuestra que la clase
//  carga, que su contrato es el acordado y que hace lo que dice, SIN renderizar
//  una sola pantalla. Si esto pasa y el archivo esta en el servidor, conectarlo
//  despues no puede fallar por ausencia.
//
//  CERO base de datos, cero red, cero paginas. Solo objetos.
// ============================================================

require_once dirname(__DIR__) . '/core/Meta/MetaState.php';
require_once dirname(__DIR__) . '/core/Meta/MetaPresentador.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

/** Un estado a mano, sin base y sin snapshot: es lo que recibe el presentador. */
function est(string $estado, string $titulo = 'T', array $accion = [], array $ev = [],
             string $cob = 'completa', string $razon = 'x'): MetaState {
    return new MetaState($estado, $titulo, 'sub', $accion, $ev, [], $cob, $razon);
}

echo "\nEL PRESENTADOR, A SOLAS · fundacion inerte\n" . str_repeat('=', 58) . "\n";

// ══════════════════════════════════════════════════════════════
//  1 · CARGA, Y ESO ES LA MITAD DEL PUNTO
// ══════════════════════════════════════════════════════════════
echo "\n  — 1 · el archivo esta y carga —\n";
$ruta = dirname(__DIR__) . '/core/Meta/MetaPresentador.php';
ok('el archivo existe', is_file($ruta), $ruta);
ok('la clase se declara', class_exists('MetaPresentador', false),
   'si no carga aqui, no cargara en el servidor');
foreach (['unidad', 'mes', 'titulo', 'objeto', 'accion', 'turno', 'paraHome'] as $m) {
    ok("expone {$m}()", method_exists('MetaPresentador', $m));
}

//  NO PUEDE ARRASTRAR MEDIA APLICACION AL CARGARSE. Un presentador que en el
//  `require` ya toca base de datos o sesion deja de ser inerte, y este paso
//  entero pierde sentido.
$src = (string)file_get_contents($ruta);
$cabecera = substr($src, 0, (int)(strpos($src, 'final class MetaPresentador') ?: 2000));
ok('no abre conexiones al cargarse', strpos($cabecera, 'new PDO') === false);
ok('ni arranca sesion', strpos($cabecera, 'session_start') === false);
//  LO QUE SÍ REQUIERE, TIENE QUE ESTAR YA EN PRODUCCIÓN.
//  Esta es la pregunta que importa para desplegar, y la primera versión de esta
//  prueba la formuló mal: exigía CERO requires, y se puso roja con el archivo
//  correcto delante. El presentador depende de dos hermanos de core/Meta/, y eso
//  no es un defecto — es lo normal. Lo que sería un defecto es depender de algo
//  que llega en el mismo despliegue: ahí un envío parcial vuelve a tumbar la
//  página, que es el patrón entero que estamos cortando.
preg_match_all('/require(?:_once)?\s+__DIR__\s*\.\s*[\'"]([^\'"]+)[\'"]/', $cabecera, $mm);
$deps = $mm[1] ?? [];
ok('declara sus dependencias por nombre', $deps !== [],
   'si no requiere nada, este chequeo no prueba nada — revísalo');
foreach ($deps as $d) {
    $p = dirname($ruta) . $d;
    ok('depende de ' . ltrim($d, '/') . ', y ya existe', is_file($p),
       $p . ' · una dependencia que viaja en el MISMO despliegue puede no llegar');
}
//  Y son hermanos suyos, no medio producto: un presentador que al incluirse
//  arrastra el dominio entero deja de ser inerte.
$fuera = array_filter($deps, fn($d) => strpos($d, '..') !== false);
ok('y ninguna sale de core/Meta/', $fuera === [], implode(' · ', $fuera));

// ══════════════════════════════════════════════════════════════
//  2 · LO QUE NOMBRA
// ══════════════════════════════════════════════════════════════
echo "\n  — 2 · como nombra lo que se cuenta —\n";
ok('pedidos', MetaPresentador::unidad('pedidos') === ['pedidos', 'registrados']);
ok('ventas',  MetaPresentador::unidad('ventas')  === ['en ventas', 'registradas']);
//  Un objetivo que no conoce NO puede reventar: el catalogo crece y la
//  pantalla no se cae por una palabra nueva.
ok('un objetivo desconocido cae a algo decible',
   MetaPresentador::unidad('lo_que_sea') === ['resultados', 'registrados'],
   'una clave nueva en el catalogo no puede tumbar la pantalla');
ok('el mes se dice en castellano', MetaPresentador::mes(8) === 'agosto');
ok('y aguanta un mes fuera de rango', MetaPresentador::mes(13) === '');

// ══════════════════════════════════════════════════════════════
//  3 · EL DTO DE HOME: NI UNA CLAVE DE MAS, NI UNA DE MENOS
// ══════════════════════════════════════════════════════════════
//  Una de mas es volver a filtrar el detalle a la pantalla; una de menos
//  obliga a Home a ir a buscarlo, que es de donde venimos.
echo "\n  — 3 · el contrato con Home —\n";
$E = est(MetaState::F_APROBACION, 'Tengo algo listo para tu OK',
    ['etiqueta' => 'Revisar y aprobar', 'destino' => '/crecer/panel/aprobar2.php?marca=7&ver=1',
     'consecuencia' => 'Sale a su hora.', 'tipo' => 'aprobacion'],
    ['contenido_id' => 1, 'objeto' => ['titulo' => 'El combo del viernes', 'red' => 'Instagram']]);
$dto = MetaPresentador::paraHome($E, [], ['meta' => null, 'progreso' => []], '/crecer/panel', 7);

$esperadas = ['estado','sin_meta','cerrada','titulo','turno','accion','objeto','cifra','dias','puede','barra'];
sort($esperadas);
$llegan = array_keys($dto); sort($llegan);
ok('las claves son exactamente las acordadas', $llegan === $esperadas,
   'sobran: ' . implode(', ', array_diff($llegan, $esperadas))
 . ' · faltan: ' . implode(', ', array_diff($esperadas, $llegan)));
ok('el objeto viaja RESUMIDO', array_keys($dto['objeto']) === ['titulo'],
   json_encode(array_keys($dto['objeto'])) . ' · con el objeto entero, Home podria volver a decidir');
ok('no viaja la evidencia', !isset($dto['evidencia']));
ok('ni la cobertura en crudo', !isset($dto['cobertura']),
   'se resuelve en un booleano: si viajara, Home tendria que interpretarla otra vez');
ok('el destino sale del estado', $dto['accion']['destino'] === '/crecer/panel/aprobar2.php?marca=7&ver=1',
   $dto['accion']['destino'] . ' · la accion abre el objeto exacto, no siempre Tu Meta');
ok('y el titulo es el del estado', $dto['titulo'] === 'Tengo algo listo para tu OK', $dto['titulo']);

//  Sin accion propia, el DTO tiene que traer una salida igual: una tarjeta que
//  no lleva a ningun sitio es decoracion.
$E2 = est(MetaState::E_CRECER_TRABAJA, 'Estoy trabajando');
$d2 = MetaPresentador::paraHome($E2, [], [], '/crecer/panel', 7);
ok('sin accion, el destino cae a Tu Meta',
   strpos($d2['accion']['destino'], '/meta.php?marca=7') !== false, $d2['accion']['destino']);
ok('y conserva la marca', strpos($d2['accion']['destino'], 'marca=7') !== false,
   'volver no puede dejarte en otro negocio');

// ══════════════════════════════════════════════════════════════
//  4 · LA BARRA SOLO EXISTE SI SE PUEDE AFIRMAR EL PROGRESO
// ══════════════════════════════════════════════════════════════
//  Es el contrato de cobertura, y es el que de verdad importa: una barra
//  afirma «vas por aqui de un total que conozco». Con cobertura parcial Crecer
//  no conoce el total, y pintarla es mentir con un grafico.
echo "\n  — 4 · sin cobertura completa no hay barra —\n";
//  OJO: el ritmo sale de `al_dia` (booleano), no de una clave 'ritmo'. La
//  primera versión de esta prueba inventó esa clave y salió roja con el código
//  correcto delante — midiendo algo que el snapshot nunca trae.
$snap = ['meta' => ['objetivo' => 'pedidos', 'cantidad' => 25.0],
         'progreso' => ['actual' => 7.0, 'pct' => 28, 'dias_rest' => 9, 'al_dia' => true]];

$parcial = est(MetaState::J_PROGRAMADO, 'Todo listo', [], [], 'parcial');
$dp = MetaPresentador::paraHome($parcial, [], $snap, '/crecer/panel', 7);
ok('con cobertura parcial, barra = null', $dp['barra'] === null,
   json_encode($dp['barra']) . ' · null no es «barra vacia»: es que no hay barra');
ok('y puede = false', $dp['puede'] === false);
ok('la cifra NO afirma un recuento', $dp['cifra']['cuenta'] === null,
   '«0 de 25» con cobertura parcial se lee como «no has vendido nada», y lo '
 . 'unico cierto es que Crecer no lo vio');

$completa = est(MetaState::J_PROGRAMADO, 'Todo listo', [], [], 'completa');
$dc = MetaPresentador::paraHome($completa, [], $snap, '/crecer/panel', 7);
ok('con cobertura completa SI hay barra', is_array($dc['barra']), json_encode($dc['barra']));
ok('con su porcentaje', (int)($dc['barra']['pct'] ?? -1) === 28);
ok('y su ritmo', ($dc['barra']['ritmo'] ?? '') === 'bien');

//  Los tres estados del ritmo, y el tercero es el que importa: «no sé» no es
//  «mal». Si `al_dia` viene null —no hay con qué compararlo— la barra existe
//  pero no dice nada del ritmo, en vez de acusar de atraso sin datos.
$mal = MetaPresentador::paraHome($completa, [],
    ['meta' => $snap['meta'], 'progreso' => ['actual' => 2.0, 'pct' => 8, 'al_dia' => false]],
    '/crecer/panel', 7);
ok('al_dia=false dice «mal»', ($mal['barra']['ritmo'] ?? '') === 'mal');
$nose = MetaPresentador::paraHome($completa, [],
    ['meta' => $snap['meta'], 'progreso' => ['actual' => 2.0, 'pct' => 8, 'al_dia' => null]],
    '/crecer/panel', 7);
ok('y sin saberlo NO acusa de atraso', ($nose['barra']['ritmo'] ?? 'x') === '',
   'ritmo=' . json_encode($nose['barra']['ritmo'] ?? null) . ' · «no sé» no es «mal»');
ok('y ahora la cifra si cuenta', $dc['cifra']['cuenta'] === 7, json_encode($dc['cifra']));
ok('los dias vienen del progreso', $dc['dias'] === 9, json_encode($dc['dias']));

// ══════════════════════════════════════════════════════════════
//  5 · LOS DOS EXTREMOS
// ══════════════════════════════════════════════════════════════
echo "\n  — 5 · sin meta y meta cerrada —\n";
$sin = MetaPresentador::paraHome(est(MetaState::A_SIN_META, 'Sin meta'), [], [], '/crecer/panel', 7);
ok('sin meta se declara', $sin['sin_meta'] === true);
ok('y no se marca cerrada a la vez', $sin['cerrada'] === false,
   'sin meta y cerrada son cosas distintas: una nunca empezo, la otra termino');

$cer = MetaPresentador::paraHome(est(MetaState::M_CERRADA, 'Cerrada'), [], $snap, '/crecer/panel', 7);
ok('la cerrada se declara', $cer['cerrada'] === true);
ok('y no se marca «sin meta»', $cer['sin_meta'] === false);

// ══════════════════════════════════════════════════════════════
//  6 · NINGUNA PAGINA LO LLAMA TODAVIA
// ══════════════════════════════════════════════════════════════
//  ESTA ES LA AFIRMACION QUE DEFINE EL LOTE. Mientras sea inerte, desplegarlo
//  no puede romper nada: si el archivo no llega, no lo echa de menos nadie.
//  Cuando 1B lo conecte, esta prueba se pondra roja — y esa transicion es
//  deliberada, no un descuido.
echo "\n  — 6 · inerte: nadie lo llama —\n";
$raiz = dirname(__DIR__);
//  UNA sola excepción, y con motivo: _cache.php es el panel de diagnóstico
//  interno, y NOMBRA la clase justamente para comprobar si el archivo llegó al
//  servidor (&test=fundacion). Es la herramienta que hace verificable este
//  lote; sin ella, «desplegar primero e inerte» sería un acto de fe.
//  No renderiza ninguna pantalla de cliente y no conecta nada.
//  La exclusión es por NOMBRE, no por carpeta: un comodín aquí dejaría entrar
//  cualquier archivo futuro sin que nadie lo decidiera.
$permitido = ['MetaPresentador.php', '_cache.php'];
$usos = [];
foreach (array_merge(glob($raiz . '/panel/*.php'), glob($raiz . '/includes/*.php'),
                     glob($raiz . '/core/Meta/*.php'), glob($raiz . '/*.php')) as $f) {
    if (in_array(basename($f), $permitido, true)) continue;
    if (strpos((string)file_get_contents($f), 'MetaPresentador') !== false) {
        $usos[] = str_replace($raiz . DIRECTORY_SEPARATOR, '', $f);
    }
}
//  Y la excepción se comprueba: si _cache.php deja de nombrarla, este bloque
//  está tapando algo que ya no existe y hay que quitarlo.
ok('el diagnóstico sí la nombra (por eso está exceptuado)',
   strpos((string)file_get_contents($raiz . '/_cache.php'), 'MetaPresentador') !== false,
   'la excepción de _cache.php sobra: quítala en vez de dejarla tapando');
ok('ninguna pagina del producto lo usa', $usos === [],
   implode(' · ', $usos) . "\n         si algo lo usa, este lote ya NO es inerte y "
 . 'desplegarlo puede tumbar esa pagina — que es justo lo que estamos evitando');

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  FUNDACION LISTA · {$n} afirmaciones\n\n"
    : "  {$fallos} de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
