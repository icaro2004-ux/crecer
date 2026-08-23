<?php
// ============================================================
//  CRECER — HOME EN INGLES, SIN CASTELLANO SUELTO
//  tests/test_home_bilingue.php
//
//  Se escribe ANTES de traducir nada, y NACE ROJA a proposito: su primer
//  trabajo es enumerar, una por una y con su sitio exacto, todas las frases
//  españolas que siguen saliendo en Home con ?lang=en. Sin esa lista, «ya esta
//  bilingue» es una opinion — y la captura de produccion ya demostro que la
//  opinion se equivocaba.
//
//  EL CONTRATO QUE VIGILA
//
//    Lo que Crecer le dice AL DUEÑO   -> idioma_interfaz  (cambia con ES/EN)
//    Lo que se publica PARA SU CLIENTELA -> idioma_contenido (NO cambia)
//
//  Por eso no basta con «no queda español»: hay cosas que TIENEN que seguir en
//  español aunque la interfaz este en ingles, y confundirlas seria peor que no
//  traducir. El proximo post es contenido publico de la marca; el nombre del
//  negocio es suyo; lo que escribio la IA es el producto. Esta prueba lo
//  comprueba en los dos sentidos: que la interfaz cambie Y que el contenido no.
//
//  MOVIL Y ESCRITORIO, y no por simetria: a 360px hay elementos que solo
//  existen ahi —la barra de abajo, el drawer— y su texto no aparece en el
//  barrido de 1440.
//
//  CERO RED: el clima se pide a un servicio externo y aqui no se llama; los
//  modales se abren con un clic y se leen tal cual. Sin proveedores, sin gasto.
// ============================================================

require_once __DIR__ . '/_sin_gasto.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once __DIR__ . '/_fixture.php';
require_once __DIR__ . '/../core/I18n/Catalogo.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nHOME EN INGLES · sin castellano suelto\n" . str_repeat('=', 62) . "\n";

if (!is_file('C:/Program Files/Google/Chrome/Application/chrome.exe')) {
    echo "\n  SALTADA: no hay Chrome en esta maquina\n\n"; exit(2);
}
@mkdir(__DIR__ . '/_capturas', 0775, true);

$fx = Fixture::crear($pdo, 'homeen', true, 'admin');
$M = (int)$fx['marca_id']; $META = (int)$fx['meta_id'];
$sid  = 'he' . bin2hex(random_bytes(8));
$ruta = session_save_path() ?: sys_get_temp_dir();
file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
    'usuario_id|i:' . (int)$fx['usuario_id'] . ';');

$sonda = function (string $mjs, array $args, array &$crudo = null) {
    $cmd = 'node ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . $mjs);
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

$todos = [];   // el inventario acumulado, para el informe final

try {
    //  El plan ya presentado: si no, Home manda a la presentacion y no se ve
    //  la portada de verdad.
    $pdo->prepare("UPDATE crecer_meta_plan SET presentado_at=NOW() WHERE meta_id=? AND estado='activo'")
        ->execute([$META]);
    //  Algo esperando OK, para que salga el aviso de posts pendientes — que es
    //  una de las superficies que hay que traducir.
    $pdo->prepare("UPDATE crecer_contenido SET estado='borrador', necesita_material=NULL
                    WHERE marca_id=? LIMIT 1")->execute([$M]);

    foreach ([[360, 800, 'movil'], [1440, 900, 'escritorio']] as [$w, $hgt, $nombre]) {
        echo "\n  — {$nombre} ({$w}px) —\n";
        $cap = __DIR__ . '/_capturas/home_en_' . $nombre . '.png';
        //  Y la misma pantalla en español, para poder poner las dos al lado.
        //  Una captura sola no demuestra que cambie: demuestra que existe.
        //  OJO: _navegador_home.mjs recibe un NOMBRE y arma la ruta el mismo;
        //  el de ingles recibe la ruta entera. Pasarle una ruta al primero deja
        //  la captura sin escribir y en silencio.
        $sonda('_navegador_home.mjs', [$sid, $M, $w, $hgt, 'home_es_' . $nombre]);
        $crudo = null;
        $j = $sonda('_navegador_home_en.mjs', [$sid, $M, $w, $hgt, $cap], $crudo);
        if (!is_array($j)) {
            ok("{$nombre} · el navegador midio", false, implode(' | ', array_slice((array)$crudo, -3)));
            continue;
        }

        ok("{$nombre} · la pagina declara lang=en", ($j['lang'] ?? '') === 'en',
           'lang="' . ($j['lang'] ?? '') . '" · si dice es, el filtro ni se encendio');
        ok("{$nombre} · sin errores de consola", ($j['consola'] ?? []) === [],
           implode(' · ', (array)($j['consola'] ?? [])));

        // ── LO QUE FALTA ──────────────────────────────────────
        $hall = (array)($j['hallazgos'] ?? []);
        foreach ($hall as $x) $todos[$nombre][] = $x;
        $muestra = '';
        foreach (array_slice($hall, 0, 14) as $x) {
            $muestra .= "\n           «" . $x['texto'] . "»  ·  " . $x['donde'];
        }
        if (count($hall) > 14) $muestra .= "\n           … y " . (count($hall) - 14) . ' mas';
        ok("{$nombre} · ni una frase española en la interfaz", $hall === [],
           count($hall) . ' frases sin traducir:' . $muestra);

        //  Y el JavaScript: lo que tiene guardado para escribir DESPUES.
        //  Un mensaje que solo sale al fallar algo no aparece en el barrido, y
        //  es justo el que se olvida.
        $js = (array)($j['deJs'] ?? []);
        $mj = '';
        foreach ($js as $x) $mj .= "\n           T." . $x['clave'] . ' = «' . $x['texto'] . '»';
        ok("{$nombre} · el diccionario del JS tambien viene en ingles", $js === [],
           count($js) . ' cadenas:' . $mj);

        // ── Y LO QUE NO SE PUEDE TOCAR ────────────────────────
        //  La otra mitad del contrato. Sin esto, «traducir Home» podria
        //  cumplirse traduciendo el post del cliente, que es lo peor que
        //  podria pasar.
        $np = (array)($j['nextPost'] ?? []);
        if (!empty($np['hay'])) {
            $txt = (string)($np['texto'] ?? '');
            ok("{$nombre} · el proximo post SIGUE en español",
               preg_match('/[áéíóúñ¿¡]|(?:^|\s)(el|la|los|las|de|que|tu|para|con|por)(?:\s|$)/iu', $txt) === 1,
               '«' . mb_substr($txt, 0, 120) . '» · es contenido publico de la marca: '
             . 'sigue idioma_contenido, no la interfaz');
        } else {
            ok("{$nombre} · hay proximo post que comprobar", false,
               'sin el, la mitad del contrato se queda sin probar');
        }

        //  Lo que quedo dentro de las regiones de contenido se REPORTA, no se
        //  marca: es la lista de lo que deliberadamente sigue en español.
        $cont = (array)($j['contenido'] ?? []);
        if ($cont) {
            echo "         (en español a proposito: " . count($cont) . " textos)\n";
            foreach (array_slice($cont, 0, 4) as $c) {
                echo "            «" . mb_substr($c['texto'], 0, 56) . "» — " . $c['razon'] . "\n";
            }
        }
    }

    // ══════════════════════════════════════════════════════════
    //  EL INVENTARIO, AGRUPADO POR SUPERFICIE
    // ══════════════════════════════════════════════════════════
    //  No es decoracion: es la lista de trabajo. Mientras tenga filas, L-5 no
    //  esta hecho — y cada fila dice donde esta la frase.
    $plano = [];
    foreach ($todos as $vp => $l) foreach ($l as $x) {
        $plano[$x['texto']] = $x['donde'];
    }
    if ($plano) {
        echo "\n  ── LO QUE FALTA POR MIGRAR (" . count($plano) . " frases unicas) ──\n";
        ksort($plano);
        foreach ($plano as $t => $d) printf("     %-58s %s\n", mb_substr($t, 0, 56), mb_substr($d, 0, 44));
    }

    // ══════════════════════════════════════════════════════════
    //  Y QUE LO MIGRADO ESTE DE VERDAD EN LOS DOS CATALOGOS
    // ══════════════════════════════════════════════════════════
    echo "\n  — los catalogos —\n";
    \Crecer\I18n\Catalogo::usarRaiz(dirname(__DIR__) . '/lang');
    $es = \Crecer\I18n\Catalogo::mapa('es');
    $en = \Crecer\I18n\Catalogo::mapa('en');
    ok('paridad es/en', array_keys($es) === array_keys($en),
       count($es) . ' vs ' . count($en));
    $vac = array_keys(array_filter($en, fn($v) => trim((string)$v) === ''));
    ok('ninguna traduccion vacia', $vac === [], implode(' · ', array_slice($vac, 0, 5)));

} finally {
    @unlink($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid);
    Fixture::limpiar($pdo, (int)$fx['marca_id']);
}

echo "\n" . str_repeat('=', 62) . "\n";
echo $fallos === 0
    ? "  HOME HABLA LOS DOS IDIOMAS · {$n} afirmaciones\n\n"
    : "  {$fallos} de {$n} · Home sigue mezclando idiomas\n\n";
exit($fallos === 0 ? 0 : 1);
