<?php
// ============================================================
//  CRECER — IDA Y VUELTA DE TU META (Fase 3)
//  tests/test_meta_retorno.php
//
//  El contrato: cada accion abre el objeto exacto y, al completarla o
//  cancelarla, se vuelve a Tu Meta conservando la marca, con el estado
//  recalculado y una confirmacion breve.
//
//  Lo que se afirma aqui es lo que no se ve mirando la pantalla: que la
//  confirmacion NUNCA sale de la URL, que la marca no se pierde por el camino,
//  y que ningun destino de ida se queda sin su marcador de vuelta.
// ============================================================

require_once __DIR__ . '/../core/Meta/MetaRetorno.php';
require_once __DIR__ . '/../core/Meta/MetaState.php';
require_once __DIR__ . '/../core/Meta/MetaStateComposer.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nTU META · IDA Y VUELTA\n" . str_repeat('=', 56) . "\n";

// ══════════════════════════════════════════════════════════════
//  1 · LA CONFIRMACION NO PUEDE VENIR DE FUERA
// ══════════════════════════════════════════════════════════════
echo "\n  — el texto que lee el dueño vive en el codigo, no en la URL —\n";
foreach (MetaRetorno::hechos() as $k) {
    $c = MetaRetorno::confirmacion($k);
    ok("«{$k}» tiene confirmacion y consecuencia",
       is_array($c) && trim($c[0]) !== '' && trim($c[1]) !== '');
}
foreach (['', 'inventado', '<b>ojo</b>', 'aprobado ', 'APROBADO', '../../etc'] as $malo) {
    ok('llave no reconocida no confirma nada: ' . var_export($malo, true),
       MetaRetorno::confirmacion($malo) === null,
       'una confirmacion armada con texto de la URL es texto ajeno con la cara de Crecer');
}
ok('null no revienta', MetaRetorno::confirmacion(null) === null);

// ══════════════════════════════════════════════════════════════
//  2 · LA VUELTA CONSERVA LA MARCA
// ══════════════════════════════════════════════════════════════
echo "\n  — sin la marca, una cuenta con dos negocios aterriza en el que no era —\n";
$u = MetaRetorno::url(7, 'aprobado');
ok('lleva la marca', strpos($u, 'marca=7') !== false, $u);
ok('lleva el hecho', strpos($u, 'hecho=aprobado') !== false, $u);
ok('apunta a Tu Meta', strpos($u, '/crecer/panel/meta.php?') === 0, $u);

$u2 = MetaRetorno::url(7, 'inventado');
ok('un hecho desconocido NO viaja en la URL', strpos($u2, 'hecho=') === false, $u2);
ok('pero la marca se conserva igual', strpos($u2, 'marca=7') !== false, $u2);
ok('sin hecho tambien vuelve bien', MetaRetorno::url(3) === '/crecer/panel/meta.php?marca=3');

// El regreso se construye aqui: si se aceptara una URL por parametro, seria un
// redirect abierto. Esta prueba fija esa decision.
$r = new ReflectionClass('MetaRetorno');
$acepta_url = false;
foreach ($r->getMethods() as $m) {
    foreach ($m->getParameters() as $p) {
        if (in_array(strtolower($p->getName()), ['url', 'destino', 'return_to', 'next'], true)) $acepta_url = true;
    }
}
ok('ningun metodo acepta una URL de vuelta por parametro', !$acepta_url,
   'el regreso se arma con la marca, nunca con lo que traiga la peticion');

// ══════════════════════════════════════════════════════════════
//  3 · LA IDA: TODO DESTINO FUERA DE TU META LLEVA SU MARCADOR
//      Es una prueba de CONTRATO: no mira los casos de hoy, mira el
//      archivo. Un destino nuevo que se olvide del marcador la rompe.
// ══════════════════════════════════════════════════════════════
echo "\n  — quien sale de Tu Meta tiene que saber volver —\n";
ok('el marcador es el que esperan las pantallas destino',
   MetaRetorno::marcador() === '&volver=meta');
ok('vieneDeMeta reconoce la ida', MetaRetorno::vieneDeMeta(['volver' => 'meta']));
ok('y no se deja confundir', !MetaRetorno::vieneDeMeta(['volver' => 'otra'])
    && !MetaRetorno::vieneDeMeta([]));

$pantallas = ['aprobar2.php', 'reels.php', 'carrusel.php'];
$src = (string)file_get_contents(__DIR__ . '/../panel/meta.php');
foreach ($pantallas as $p) {
    // El compositor arma los destinos; meta.php les pega el marcador a todos los
    // que no son ella misma. Aqui se afirma esa regla, que es la que hace que
    // cualquier destino futuro herede el regreso sin acordarse de nada.
    ok("meta.php le pone el regreso a lo que salga hacia {$p}",
       strpos($src, '$destino .= $mt_volver') !== false,
       'sin esa linea, cada destino nuevo nace siendo un viaje de ida');
    break;   // la regla es una sola; no hace falta repetirla por pantalla
}

foreach ($pantallas as $p) {
    $s = (string)file_get_contents(__DIR__ . '/../panel/' . $p);
    ok("{$p} usa el contrato y no una URL escrita a mano",
       strpos($s, 'MetaRetorno::') !== false,
       'el enlace suelto no distingue haber terminado de haberse arrepentido');
    ok("{$p} ya no arma la vuelta con un literal",
       !preg_match('#href="/crecer/panel/meta\.php\?marca=#', $s)
       && !preg_match('#\$BASE \?>/meta\.php\?marca=#', $s));
}

// ══════════════════════════════════════════════════════════════
//  4 · LA VUELTA AL COMPLETAR, NO SOLO AL ARREPENTIRSE
// ══════════════════════════════════════════════════════════════
echo "\n  — al terminar se vuelve solo —\n";
$ap = (string)file_get_contents(__DIR__ . '/../panel/aprobar2.php');
ok('aprobar2 vuelve a Tu Meta al aprobar',
   strpos($ap, "accion === 'aprobar' && volverATuMeta()") !== false,
   'sin esto el regreso era una salida manual que casi nadie ve');
ok('el regreso vive en enviarAccion, que es por donde pasan los cuatro caminos',
   strpos($ap, 'function enviarAccion') !== false
   && strpos($ap, 'var META_VUELTA') !== false);
ok('y solo se arma si de verdad se vino de Tu Meta',
   strpos($ap, 'MetaRetorno::vieneDeMeta($_GET)') !== false);
ok('la vuelta por completar dice «aprobado»', strpos($ap, "'aprobado'") !== false);
ok('la salida manual dice «cancelado», que no es lo mismo',
   strpos($ap, "'cancelado'") !== false,
   'confirmar algo que el dueño no hizo es peor que no confirmar nada');

// ══════════════════════════════════════════════════════════════
//  5 · TU META PINTA LA CONFIRMACION, Y SOLO DESDE EL CONTRATO
// ══════════════════════════════════════════════════════════════
echo "\n  — Tu Meta acusa recibo —\n";
ok('meta.php lee el hecho por el contrato',
   strpos($src, "MetaRetorno::confirmacion(\$_GET['hecho'] ?? null)") !== false);
ok('y lo escapa antes de pintarlo', strpos($src, '$h($mt_confirma[0])') !== false);
ok('el marcador de ida tambien sale del contrato',
   strpos($src, '$mt_volver = MetaRetorno::marcador();') !== false,
   'tenerlo escrito a mano en dos sitios es como se desincronizan');

echo "\n" . str_repeat('=', 56) . "\n";
echo $fallos === 0 ? "  TODO OK · {$n} pruebas\n\n" : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
