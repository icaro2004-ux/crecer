<?php
// ============================================================
//  CRECER — PARIDAD DEL WIZARD DE LA META
//  tests/test_meta_wizard_paridad.php
//
//  Escrita ANTES de rediseñar, y verde contra el wizard viejo. Igual que con
//  el plan: si no pasa ahora, no protege nada.
//
//  Lo que hay que no perder no es el aspecto — es lo que el wizard SABE
//  RECOGER y lo que de verdad ESCRIBE:
//
//    · los cinco campos: objetivo, cantidad, plazo, inversión y contexto;
//    · la ayuda «No sé — dime tú», que le pide el número al corillo;
//    · la creación real: al confirmar aparece una meta con esos valores;
//    · y su plan, encargado a la Estratega.
//
//  Las afirmaciones miran el CONTRATO —el nombre del campo que viaja en el
//  POST, el valor que queda en la base— y no la clase CSS, que es lo que el
//  rediseño va a cambiar entero.
//
//  CERO PROVEEDORES DE IMAGEN: crear la meta llama a la Estratega (texto). La
//  prueba tolera que el plan falle —lo importante es que la meta se escriba—,
//  así que no depende de que haya credenciales.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nPARIDAD DEL WIZARD · lo que recoge y lo que escribe\n" . str_repeat('=', 58) . "\n";

$fx = Fixture::crear($pdo, 'wizpar', true, 'admin');
$M  = (int)$fx['marca_id'];

$sid  = 'wz' . bin2hex(random_bytes(8));
$ruta = session_save_path() ?: sys_get_temp_dir();
file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
    'usuario_id|i:' . (int)$fx['usuario_id'] . ';');

/** Pide la página como la pediría el navegador. */
$pedir = function (string $query) use ($sid): string {
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 30,
        'header' => "Cookie: PHPSESSID={$sid}\r\n"]]);
    $html = @file_get_contents('http://localhost/crecer/panel/meta.php?' . $query, false, $ctx);
    return is_string($html) ? $html : '';
};

/** El POST de verdad, con su csrf, como lo manda la pantalla. */
$postear = function (array $campos) use ($sid, $M): array {
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 30,
        'header' => "Cookie: PHPSESSID={$sid}\r\n"]]);
    $html = @file_get_contents('http://localhost/crecer/panel/meta.php?marca=' . $M, false, $ctx);
    if (!preg_match('~CSRF\s*=\s*"([a-f0-9]+)"~i', (string)$html, $m)
     && !preg_match("~CSRF\s*=\s*'([a-f0-9]+)'~i", (string)$html, $m)) {
        return ['ok' => false, 'err' => 'no encontré el csrf en la página'];
    }
    $cuerpo = http_build_query(['csrf' => $m[1], 'ajax' => '1'] + $campos);
    $ctx2 = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 120,
        'header' => "Cookie: PHPSESSID={$sid}\r\n"
                  . "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $cuerpo, 'ignore_errors' => true]]);
    $r = @file_get_contents('http://localhost/crecer/panel/meta.php?marca=' . $M, false, $ctx2);
    $j = json_decode((string)$r, true);
    return is_array($j) ? $j : ['ok' => false, 'err' => 'respuesta no-JSON: ' . substr((string)$r, 0, 160)];
};

try {
    //  El wizard solo sale SIN meta: la fixture trae una, así que se quita.
    //  Eso se lleva el plan por delante, y da igual — aquí se prueba crear.
    $pdo->prepare("DELETE FROM crecer_meta WHERE marca_id=?")->execute([$M]);

    $html = $pedir('marca=' . $M . '&vista=wizard');
    ok('el wizard responde', trim($html) !== '' && strpos($html, '<html') !== false,
       '¿está Apache arriba?');
    if (trim($html) === '') { throw new RuntimeException('sin html'); }

    // ══════════════════════════════════════════════════════════
    //  LOS CINCO CAMPOS
    // ══════════════════════════════════════════════════════════
    echo "\n  — 1 · el objetivo —\n";
    require_once __DIR__ . '/../includes/meta_negocio.php';   // aqui vive el catalogo
    $objs = function_exists('meta_objetivos') ? meta_objetivos() : [];
    ok('hay objetivos que escoger', count($objs) >= 3, 'el catálogo trae ' . count($objs));
    $faltan = [];
    foreach ($objs as $k => $o) {
        if (strpos($html, (string)($o['titulo'] ?? '')) === false) $faltan[] = $k;
    }
    ok('y todos se ofrecen en la pantalla', $faltan === [],
       'no salen: ' . implode(', ', $faltan) . ' · esconder un objetivo es quitarle una meta al dueño');

    echo "\n  — 2 · cuánto —\n";
    ok('hay dónde escribir la cantidad', strpos($html, 'id="cantidad"') !== false
        || preg_match('~name="cantidad"~', $html) === 1);
    ok('y la ayuda «no sé, dime tú»', stripos($html, 'No sé') !== false,
       'es la que le pide el número al corillo en vez de dejar al dueño solo');
    //  El wizard lo manda con FormData, no con un objeto: se busca el nombre
    //  de la accion, que es lo que de verdad viaja.
    ok('que llega a la acción sugerir', strpos($html, "'sugerir'") !== false,
       'sin eso el boton no le pregunta nada al corillo');

    echo "\n  — 3 · para cuándo —\n";
    $plazos = 0;
    foreach ([14, 30, 60, 90] as $d) if (strpos($html, 'data-dias="' . $d . '"') !== false) $plazos++;
    ok('se ofrecen los cuatro plazos', $plazos === 4, "salieron {$plazos} de 4");

    echo "\n  — 4 · la inversión —\n";
    $pautas = 0;
    foreach ([0, 20, 50, 100] as $p) if (strpos($html, 'data-pauta="' . $p . '"') !== false) $pautas++;
    ok('se ofrecen los cuatro tramos', $pautas === 4, "salieron {$pautas} de 4");
    ok('incluido «sin invertir»', strpos($html, 'data-pauta="0"') !== false,
       'sin esa opción, el wizard estaría obligando a pagar anuncios');

    echo "\n  — 5 · el contexto —\n";
    ok('hay dónde contarlo', strpos($html, 'id="contexto"') !== false);
    ok('y es opcional', stripos($html, 'Opcional') !== false || stripos($html, '(opcional)') !== false);

    // ══════════════════════════════════════════════════════════
    //  LA CREACIÓN DE VERDAD
    // ══════════════════════════════════════════════════════════
    echo "\n  — 6 · crear la meta escribe de verdad —\n";
    $limite = date('Y-m-d', strtotime('+30 days'));
    $r = $postear([
        'accion'      => 'crear',
        'objetivo'    => 'pedidos',
        'cantidad'    => '25',
        'fecha_limite'=> $limite,
        'presupuesto' => '20',
        'contexto'    => '[prueba] Tengo el combo de brazo gitano a $18.',
    ]);
    ok('el servidor dice que sí', !empty($r['ok']), json_encode($r, JSON_UNESCAPED_UNICODE));
    ok('y devuelve el id de la meta', (int)($r['meta_id'] ?? 0) > 0, json_encode($r));

    $q = $pdo->prepare("SELECT * FROM crecer_meta WHERE marca_id=? ORDER BY id DESC LIMIT 1");
    $q->execute([$M]);
    $meta = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    ok('la meta existe en la base', !empty($meta));
    ok('con su objetivo',  (string)($meta['objetivo'] ?? '') === 'pedidos', (string)($meta['objetivo'] ?? '—'));
    ok('con su cantidad',  (float)($meta['cantidad'] ?? 0) === 25.0,       (string)($meta['cantidad'] ?? '—'));
    ok('con su fecha',     strpos((string)($meta['fecha_limite'] ?? ''), $limite) === 0,
       (string)($meta['fecha_limite'] ?? '—'));
    ok('con su inversión', (float)($meta['presupuesto_pauta'] ?? -1) === 20.0,
       (string)($meta['presupuesto_pauta'] ?? '—') . ' · si se pierde, el plan no sabe si puede pautar');
    ok('y con el contexto que dio el dueño',
       strpos((string)($meta['contexto'] ?? ''), 'brazo gitano') !== false,
       (string)($meta['contexto'] ?? '—') . ' · es lo que hace que el plan no sea genérico');
    ok('la meta nace activa', (string)($meta['estado'] ?? '') === 'activa', (string)($meta['estado'] ?? '—'));

    echo "\n  — 7 · y se le encarga el plan a la Estratega —\n";
    //  Que el plan SALGA depende de credenciales; que se ENCARGUE, no. La
    //  respuesta trae el veredicto, y eso es lo que se protege.
    ok('la respuesta dice si el plan salió o no', array_key_exists('plan_ok', $r),
       'sin ese dato la pantalla no puede distinguir «meta creada, plan pendiente» de «todo listo»');

    echo "\n  — 8 · salida visible hacia Tu Meta —\n";
    ok('el wizard tiene una puerta de vuelta',
       stripos($html, 'Volver') !== false || stripos($html, 'Cancelar') !== false,
       'una capa sin salida deja al dueño encerrado con el botón del navegador');

} finally {
    @unlink($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid);
    Fixture::limpiar($pdo, $M);
    echo "\n  (fixture limpiada)\n";
}

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  PARIDAD COMPLETA · {$n} afirmaciones\n\n"
    : "  {$fallos} de {$n} · el wizard perdió algo por el camino\n\n";
exit($fallos === 0 ? 0 : 1);
