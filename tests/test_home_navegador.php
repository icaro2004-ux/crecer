<?php
// ============================================================
//  CRECER — HOME, MEDIDO EN UN NAVEGADOR DE VERDAD  (Fase 5)
//  tests/test_home_navegador.php
//
//  La paridad prueba que Home y Tu Meta DICEN lo mismo. Esto prueba lo otro:
//  que la tarjeta se pueda usar, y que la coherencia no se haya pagado
//  rompiendo la portada.
//
//  LO QUE SOLO SE VE AQUI
//
//    · Una sola accion primaria en la tarjeta. Si la tarjeta entera es un
//      enlace y ademas lleva botones dentro, el dedo no sabe que pulsa.
//    · Que la tarjeta no se coma la pantalla: lo demas de Home —el turno, el
//      relevo, la idea del dia— tiene que seguir alcanzable.
//    · 14px de suelo y 44x44 de objetivo, en movil y en escritorio.
//    · Cero solapamientos con las capas fijas y cero scroll horizontal.
//    · Cero errores de consola: la tarjeta anima el numero con un guion, y un
//      error ahi la dejaria en cero para siempre.
//
//  CERO PROVEEDORES: monta filas y abre la portada.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/meta_negocio.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nHOME · medido en Chrome\n" . str_repeat('=', 58) . "\n";

if (!is_file('C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe')) {
    echo "\n  SALTADA: no hay Chrome en esta maquina\n\n"; exit(2);
}
@mkdir(__DIR__ . '/_capturas', 0775, true);

$fx = Fixture::crear($pdo, 'homenav', true, 'admin');
$M = (int)$fx['marca_id']; $META = (int)$fx['meta_id'];
$sid  = 'hn' . bin2hex(random_bytes(8));
$ruta = session_save_path() ?: sys_get_temp_dir();
file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
    'usuario_id|i:' . (int)$fx['usuario_id'] . ';');

$sonda = function (array $args, array &$crudo = null) {
    $cmd = 'node ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . '_navegador_home.mjs');
    foreach ($args as $a) $cmd .= ' ' . escapeshellarg((string)$a);
    for ($k = 0; $k < 2; $k++) {
        if ($k > 0) usleep(1500000);
        $sal = []; exec($cmd . ' 2>&1', $sal);
        $crudo = $sal;
        $j = json_decode((string)end($sal), true);
        if (is_array($j) && !isset($j['error'])) return $j;
    }
    return null;
};

try {
    $pdo->prepare("UPDATE crecer_meta_plan SET presentado_at=NOW() WHERE meta_id=? AND estado='activo'")
        ->execute([$META]);

    //  Tres formas del Home: con meta y algo que aprobar, con la meta cerrada,
    //  y sin meta ninguna. Son las tres caras que el dueño ve de verdad.
    $formas = [
        'con meta' => function () use ($pdo, $M, $META) {
            $pdo->prepare("UPDATE crecer_meta SET estado='activa' WHERE id=?")->execute([$META]);
            $pdo->prepare("UPDATE crecer_contenido SET estado='borrador', necesita_material=NULL
                            WHERE marca_id=? LIMIT 1")->execute([$M]);
        },
        'meta cerrada' => function () use ($pdo, $META) {
            $pdo->prepare("UPDATE crecer_meta SET estado='lograda' WHERE id=?")->execute([$META]);
        },
        'sin meta' => function () use ($pdo, $M) {
            $pdo->prepare("DELETE FROM crecer_meta WHERE marca_id=?")->execute([$M]);
        },
    ];

    $capturas = ['con meta-360' => 'home_con-meta_movil', 'con meta-1440' => 'home_con-meta_escritorio'];

    foreach ($formas as $forma => $montar) {
        $montar();
        foreach ([[360, 800], [414, 896], [1440, 900]] as [$w, $hgt]) {
            $etq = "{$forma} @{$w}";
            $cap = $capturas["{$forma}-{$w}"] ?? '';
            $crudo = null;
            $j = $sonda([$sid, $M, $w, $hgt, $cap], $crudo);
            if (!is_array($j)) {
                ok("{$etq} · el navegador midio", false, implode(' | ', array_slice((array)$crudo, -2)));
                continue;
            }
            ok("{$etq} · la portada responde", !empty($j['hay_home']),
               'url=' . ($j['url'] ?? '?'));
            ok("{$etq} · hay tarjeta de meta", !empty($j['hay_norte']),
               'la meta es el motor del producto: su tarjeta no puede faltar');
            ok("{$etq} · ningun control bajo una capa fija", count($j['tapados']) === 0,
               json_encode($j['tapados'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            //  EN LA TARJETA, el suelo se exige. Fuera de ella se inventaria.
            $chicosN = array_values(array_filter($j['chicos'],
                fn($c) => strpos((string)($c['cls'] ?? ''), 'n-') === 0));
            $bajoN   = array_values(array_filter($j['bajo14'],
                fn($c) => strpos((string)($c['cls'] ?? ''), 'n-') === 0));
            ok("{$etq} · en la tarjeta, ningun objetivo bajo 44x44", count($chicosN) === 0,
               json_encode($chicosN, JSON_UNESCAPED_UNICODE));
            ok("{$etq} · en la tarjeta, ningun texto bajo 14px", count($bajoN) === 0,
               json_encode($bajoN, JSON_UNESCAPED_UNICODE)
             . ' · esta tarjeta dice lo MISMO que Tu Meta, y Tu Meta lo dice a 14px');

            //  Y lo de fuera se APUNTA, con nombre. No es un rojo de esta fase
            //  —se pidio no refactorizar lo que no la afecta— pero tampoco se
            //  puede perder: queda escrito en cada corrida.
            $fuera = array_merge(
                array_map(fn($c) => ($c['cls'] ?: '?') . ' ' . $c['w'] . 'x' . $c['h'],
                          array_filter($j['chicos'], fn($c) => strpos((string)($c['cls'] ?? ''), 'n-') !== 0)),
                array_map(fn($c) => ($c['cls'] ?: '?') . ' ' . $c['px'] . 'px',
                          array_filter($j['bajo14'], fn($c) => strpos((string)($c['cls'] ?? ''), 'n-') !== 0))
            );
            if ($fuera && $w === 360) {
                echo "  ·    fuera del norte (deuda previa, no de esta fase): "
                   . implode(' · ', array_slice($fuera, 0, 8))
                   . (count($fuera) > 8 ? ' …y ' . (count($fuera) - 8) . ' mas' : '') . "
";
            }
            ok("{$etq} · sin scroll horizontal", empty($j['scroll_h']), 'doc ' . ($j['doc'] ?? '?'));
            ok("{$etq} · una sola accion primaria en la tarjeta",
               (int)($j['primarias_norte'] ?? 0) <= 1,
               'hay ' . ($j['primarias_norte'] ?? '?') . ' · con dos, el dedo no sabe cual pulsa');
            ok("{$etq} · cero errores de consola", count($j['consola']) === 0,
               json_encode($j['consola'], JSON_UNESCAPED_UNICODE));
            ok("{$etq} · y la tarjeta conserva la marca",
               $j['href_norte'] === '' || strpos((string)$j['href_norte'], 'marca=' . $M) !== false,
               (string)($j['href_norte'] ?? '—'));

            if ($forma === 'con meta' && $w === 360) {
                //  LO DEMAS DE HOME NO DESAPARECE. Coherencia no es vaciar la
                //  portada: el turno, el relevo y la idea del dia siguen.
                ok('lo demas de Home sigue en pie', (int)($j['tarjetas'] ?? 0) >= 1,
                   'tarjetas ademas del norte: ' . ($j['tarjetas'] ?? '?'));
                ok('y el saludo tambien', !empty($j['hay_saludo']));
            }
        }
    }

} finally {
    @unlink($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid);
    Fixture::limpiar($pdo, $M);
    echo "\n  (fixture limpiada)\n";
}

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  TODO OK · {$n} afirmaciones\n\n"
    : "  {$fallos} de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
