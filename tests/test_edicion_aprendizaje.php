<?php
// ============================================================
//  CRECER — EL CICLO DE APRENDER DE UNA EDICION (cierre de 2A)
//  tests/test_edicion_aprendizaje.php
//
//  EL PROBLEMA QUE CIERRA. En 2A saqué la llamada al modelo del guardado del
//  caption —cada coma corregida costaba una llamada de red— y dejé la edición
//  cruda en `crecer_memoria` con `estado='pendiente_revision'`. Eso quitó el
//  gasto y la espera, pero dejó una COLA MUERTA: nadie leía esas filas. Una
//  cola que nadie consume no es diferido, es perdido.
//
//  EL CICLO COMPLETO que se prueba aquí:
//
//    el dueño corrige el texto
//      → se guarda YA, sin llamar a nadie
//      → queda la nota cruda, aislada por marca
//      → el Aprendiz la digiere DESPUES, fuera de la pantalla
//      → la lección entra en `crecer_memoria` como preferencia activa
//      → y `cerebro_negocio()` la inyecta en lo próximo que escriba el corillo
//
//  Lo último es lo que separa «procesada» de «memoria muerta»: una fila marcada
//  como digerida que nadie consulta sigue sin servir para nada.
//
//  ══ RED CERRADA POR CONSTRUCCION ══ `_sin_gasto.php` cierra los cuatro puntos
//  de proveedor. El Aprendiz SÍ llama al modelo —es su trabajo—, así que aquí
//  se le sustituye por un doble explícito y se cuenta cuántas veces contesta.
// ============================================================

require_once __DIR__ . '/_sin_gasto.php';

/**
 * EL DOBLE DEL APRENDIZ. `aprendiz_leccion()` esta declarada bajo
 * function_exists en agentes.php —el mismo patron que ia_http_post_retry()—,
 * asi que la de aqui gana. Por ahi sale la UNICA llamada al modelo del
 * digestor: sustituyendola se prueba el ciclo entero, incluido el fallo, sin
 * que salga un byte.
 *
 * Escribe con memoria_escribir(), que es lo que hace el Aprendiz de verdad: si
 * la leccion no acabara donde memoria_relevante() la lee, el ciclo no estaria
 * cerrado y esta prueba estaria mintiendo.
 */
function aprendiz_leccion(PDO $pdo, int $marca_id, string $original,
                          string $editado, array $opts = []): ?string {
    $GLOBALS['LLAMADAS'] = (int)($GLOBALS['LLAMADAS'] ?? 0) + 1;
    switch ($GLOBALS['APRENDIZ_SIM'] ?? 'ok') {
        case 'fallo': throw new RuntimeException('simulado: el modelo no contestó');
        case 'nada':  return null;
    }
    require_once __DIR__ . '/../includes/memoria.php';
    $leccion = 'Prefiere hablarle de tú y cerrar con una invitación directa por WhatsApp.';
    memoria_escribir($pdo, $marca_id, [
        'tipo' => 'preferencia', 'titulo' => mb_strimwidth($leccion, 0, 120, '…'),
        'detalle' => $leccion,
        'porque' => 'Lo aprendí de una edición que le hiciste a un caption.',
        'fuente' => 'edicion', 'confianza' => 70, 'peso' => 80,
    ]);
    return $leccion;
}
$GLOBALS['APRENDIZ_SIM'] = 'ok';
$GLOBALS['LLAMADAS'] = 0;

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/memoria.php';
require_once __DIR__ . '/../includes/agentes.php';
require_once __DIR__ . '/_fixture.php';

/** Cuantas veces contesto el doble desde la ultima vez que se miro. */
function llamadas_desde(int $antes): int { return (int)($GLOBALS['LLAMADAS'] ?? 0) - $antes; }

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nAPRENDER DE UNA EDICION, SIN COBRARLE LA ESPERA\n" . str_repeat('=', 58) . "\n";

echo "\n  — la red, cerrada por construcción —\n";
ok('el modo prueba está puesto', defined('CRECER_TEST_MODE') && CRECER_TEST_MODE);

$cnt = fn(string $t, string $w = '1') => (int)$GLOBALS['pdo']->query(
    "SELECT COUNT(*) FROM {$t} WHERE {$w}")->fetchColumn();
$g = ['ia' => $cnt('crecer_ia_log'), 'cuota' => $cnt('crecer_img_cuota_asiento')];

$limpiar = [];
try {
    // ══════════════════════════════════════════════════════════════
    //  1 · LA NOTA CRUDA · lo que 2A dejó escrito
    // ══════════════════════════════════════════════════════════════
    echo "\n  — el dueño corrige, y se guarda sin llamar a nadie —\n";
    $fx = Fixture::crear($pdo, 'apr', false, 'admin');
    $limpiar[] = $M = (int)$fx['marca_id'];
    $ia0 = $cnt('crecer_ia_log');

    $ORIG = 'Bizcocho de guayaba disponible. Comuníquese para adquirirlo.';
    $EDIT = 'Bizcocho de guayaba fresquecito. Escríbeme por WhatsApp y te lo separo.';
    ok('existe la anotación de 2A', function_exists('edicion_anotar'));
    ok('anota la edición', edicion_anotar($pdo, $M, 12345, $ORIG, $EDIT));
    ok('y no llamó a nadie', $cnt('crecer_ia_log') === $ia0,
       'guardar el texto del dueño no puede costar una llamada');

    $cruda = $pdo->query("SELECT * FROM crecer_memoria
                           WHERE marca_id={$M} AND tipo='edicion_cruda'")->fetch(PDO::FETCH_ASSOC);
    ok('la nota está',                 (bool)$cruda);
    ok('en estado pendiente',          (string)($cruda['estado'] ?? '') === 'pendiente_revision');
    ok('atada a la pieza',             (int)($cruda['fuente_id'] ?? 0) === 12345);
    ok('y guarda los dos textos',
       ($d = json_decode((string)($cruda['datos_json'] ?? ''), true))
       && ($d['original'] ?? '') === $ORIG && ($d['editado'] ?? '') === $EDIT,
       (string)($cruda['datos_json'] ?? ''));
    ok('sin cookies, sesión ni credenciales',
       mb_stripos((string)($cruda['datos_json'] ?? ''), 'PHPSESSID') === false
       && mb_stripos((string)($cruda['datos_json'] ?? ''), 'csrf') === false);

    //  Y NO ES VISIBLE COMO SI YA FUERA UNA LECCION.
    ok('lo crudo NO entra en el contexto del corillo',
       mb_strpos((string)memoria_para_prompt($pdo, $M), $EDIT) === false,
       'la nota cruda no es una preferencia aprendida: es materia prima');

    // ══════════════════════════════════════════════════════════════
    //  2 · EL CONSUMIDOR · alguien tiene que digerirla
    // ══════════════════════════════════════════════════════════════
    echo "\n  — el Aprendiz la digiere, fuera de la pantalla —\n";
    ok('existe el digestor', function_exists('edicion_digerir'),
       'sin consumidor, `pendiente_revision` es una cola muerta');

    if (!function_exists('edicion_digerir')) {
        throw new RuntimeException('sin digestor no hay ciclo que probar');
    }

    //  EL DOBLE DEL APRENDIZ. `ia_ejecutar` esta declarada bajo function_exists
    //  en ia.php, asi que la de aqui gana. Cuenta sus llamadas: el contrato
    //  dice UNA por edicion, no una por recarga.
    $l0 = (int)$GLOBALS['LLAMADAS'];
    $r  = edicion_digerir($pdo, $M, 5);
    ok('digiere la que había',      (int)($r['digeridas'] ?? 0) === 1, json_encode($r));
    ok('y llamó al Aprendiz UNA vez', llamadas_desde($l0) === 1,
       llamadas_desde($l0) . ' llamadas');

    $cruda2 = $pdo->query("SELECT * FROM crecer_memoria WHERE id=" . (int)$cruda['id'])
                  ->fetch(PDO::FETCH_ASSOC);
    ok('la nota cruda deja de estar pendiente',
       (string)$cruda2['estado'] !== 'pendiente_revision', (string)$cruda2['estado']);
    ok('y apunta a la lección que la reemplazó',
       (int)($cruda2['superseded_by'] ?? 0) > 0, json_encode($cruda2['superseded_by'] ?? null));

    // ══════════════════════════════════════════════════════════════
    //  3 · LA LECCION LLEGA AL CORILLO · esto es lo que la hace viva
    // ══════════════════════════════════════════════════════════════
    echo "\n  — y lo próximo que escriba el corillo ya lo sabe —\n";
    $leccion = $pdo->query("SELECT * FROM crecer_memoria WHERE id="
                           . (int)$cruda2['superseded_by'])->fetch(PDO::FETCH_ASSOC);
    ok('la lección existe',      (bool)$leccion, json_encode($leccion));
    ok('es una preferencia',     (string)($leccion['tipo'] ?? '') === 'preferencia',
       (string)($leccion['tipo'] ?? ''));
    ok('está activa',            (string)($leccion['estado'] ?? '') === 'activa');
    ok('y es del dominio que lee el corillo',
       (string)($leccion['dominio'] ?? '') === 'marketing');
    ok('dice de dónde salió',    (string)($leccion['fuente'] ?? '') === 'edicion');

    $prompt = memoria_para_prompt($pdo, $M);
    ok('el contexto del corillo la incluye',
       mb_strpos($prompt, trim((string)$leccion['detalle'])) !== false,
       mb_substr($prompt, 0, 200));
    $cerebro = cerebro_negocio($pdo, $M);
    ok('y llega hasta el cerebro del negocio',
       mb_strpos($cerebro, trim((string)$leccion['detalle'])) !== false,
       'una lección que ningún agente consulta sigue siendo memoria muerta');

    //  NO ES UNA COPIA DEL CAPTION.
    ok('la lección no es otra copia del texto',
       mb_strpos((string)$leccion['detalle'], $EDIT) === false,
       'guardar el caption otra vez no es aprender');

    // ── OTRA MARCA NO LA VE ──────────────────────────────────────
    echo "\n  — y el negocio de al lado no aprende de esto —\n";
    $fo = Fixture::crear($pdo, 'aprX', false, 'admin');
    $limpiar[] = $MX = (int)$fo['marca_id'];
    ok('otra marca no ve la lección',
       mb_strpos((string)memoria_para_prompt($pdo, $MX),
                 trim((string)$leccion['detalle'])) === false);
    ok('ni su cerebro',
       mb_strpos(cerebro_negocio($pdo, $MX), trim((string)$leccion['detalle'])) === false);

    // ══════════════════════════════════════════════════════════════
    //  4 · NO SE DIGIERE DOS VECES
    // ══════════════════════════════════════════════════════════════
    echo "\n  — pasar otra vez no vuelve a gastar —\n";
    $l1 = (int)$GLOBALS['LLAMADAS'];
    $r2 = edicion_digerir($pdo, $M, 5);
    ok('no queda nada que digerir', (int)($r2['digeridas'] ?? 9) === 0, json_encode($r2));
    ok('y no se llamó al Aprendiz', llamadas_desde($l1) === 0,
       llamadas_desde($l1) . ' llamadas');

    // ══════════════════════════════════════════════════════════════
    //  5 · DOS PROCESOS A LA VEZ · una sola llamada
    // ══════════════════════════════════════════════════════════════
    echo "\n  — dos corridas simultáneas, una sola lección —\n";
    edicion_anotar($pdo, $M, 777,
        'Tenemos servicio de repostería para eventos.',
        'Hacemos bizcochos pa\' tu party. Dime la fecha y te cotizo.');
    $pend = (int)$pdo->query("SELECT COUNT(*) FROM crecer_memoria
                               WHERE marca_id={$M} AND tipo='edicion_cruda'
                                 AND estado='pendiente_revision'")->fetchColumn();
    ok('hay una nota nueva pendiente', $pend === 1, (string)$pend);

    //  DOS PROCESOS DE VERDAD, cada uno con su conexion. Dos llamadas
    //  seguidas en el mismo proceso no prueban una carrera: prueban un orden.
    $script = __DIR__ . '/_digerir_runner.php';
    ok('existe el runner de concurrencia', is_file($script));
    if (is_file($script)) {
        //  DOS PROCESOS DE VERDAD, arrancados sin esperar al primero. `start /B`
        //  a traves de popen() no arrancaba nada aqui —se quedaba en dos
        //  arreglos vacios— y la prueba parecia roja por una carrera que en
        //  realidad nunca llego a correr. proc_open() los lanza y devuelve al
        //  instante, que es lo que hace falta para que se pisen.
        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $procs = []; $tubos = [];
        for ($k = 0; $k < 2; $k++) {
            $tubos[$k] = [];
            $procs[$k] = proc_open(
                escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . $M,
                $desc, $tubos[$k], dirname(__DIR__));
        }
        $salidas = [];
        foreach ($procs as $k => $ph) {
            if (!is_resource($ph)) { $salidas[$k] = []; continue; }
            $txt = stream_get_contents($tubos[$k][1]);
            fclose($tubos[$k][1]); fclose($tubos[$k][2]);
            proc_close($ph);
            //  La ULTIMA linea es el JSON: config.local.php redefine constantes
            //  y PHP avisa por stdout antes. Leer el volcado entero devolvia
            //  null y otra vez parecia un fallo del producto.
            $lineas = array_values(array_filter(array_map('trim', explode("\n", (string)$txt))));
            $salidas[$k] = json_decode((string)end($lineas), true) ?: [];
        }
        [$a, $b] = [$salidas[0] ?? [], $salidas[1] ?? []];

        $dig = (int)($a['digeridas'] ?? 0) + (int)($b['digeridas'] ?? 0);
        $lla = (int)($a['llamadas']  ?? 0) + (int)($b['llamadas']  ?? 0);
        ok('los dos procesos contestaron', $a !== [] && $b !== [],
           json_encode(['a' => $a, 'b' => $b]));
        ok('entre los dos la digirieron UNA vez', $dig === 1,
           json_encode(['a' => $a, 'b' => $b]));
        ok('y solo hubo UNA llamada al Aprendiz', $lla === 1,
           json_encode(['a' => $a, 'b' => $b])
           . ' — dos llamadas serían pagar dos veces la misma lección');
        ok('ninguno reventó', empty($a['error']) && empty($b['error']),
           json_encode(['a' => $a['error'] ?? null, 'b' => $b['error'] ?? null]));
        ok('y no quedó nada pendiente',
           (int)$pdo->query("SELECT COUNT(*) FROM crecer_memoria
                              WHERE marca_id={$M} AND tipo='edicion_cruda'
                                AND estado='pendiente_revision'")->fetchColumn() === 0);
    }

    // ══════════════════════════════════════════════════════════════
    //  6 · CUANDO EL MODELO FALLA · ni se pierde ni gira para siempre
    // ══════════════════════════════════════════════════════════════
    echo "\n  — si el modelo falla, la nota se queda —\n";
    edicion_anotar($pdo, $M, 888, 'Texto viejo del negocio.', 'Texto nuevo, mucho mejor.');
    $GLOBALS['APRENDIZ_SIM'] = 'fallo';
    $rf = edicion_digerir($pdo, $M, 5);
    ok('el fallo se cuenta',    (int)($rf['fallidas'] ?? 0) === 1, json_encode($rf));
    ok('y no se digirió nada',  (int)($rf['digeridas'] ?? 9) === 0);
    $f1 = $pdo->query("SELECT * FROM crecer_memoria WHERE marca_id={$M}
                        AND tipo='edicion_cruda' AND fuente_id=888")->fetch(PDO::FETCH_ASSOC);
    ok('la nota sigue ahí',     (bool)$f1);
    ok('y sigue pendiente, para reintentarla',
       (string)($f1['estado'] ?? '') === 'pendiente_revision', (string)($f1['estado'] ?? ''));
    ok('con su intento apuntado',
       (int)(json_decode((string)$f1['datos_json'], true)['intentos'] ?? 0) === 1,
       (string)$f1['datos_json']);

    //  EL REINTENTO FUNCIONA cuando el modelo vuelve.
    $GLOBALS['APRENDIZ_SIM'] = 'ok';
    $rr = edicion_digerir($pdo, $M, 5);
    ok('al volver el modelo, se digiere', (int)($rr['digeridas'] ?? 0) === 1, json_encode($rr));

    // ── EL FALLO TERMINAL NO GIRA PARA SIEMPRE ───────────────────
    echo "\n  — pero no lo intenta eternamente —\n";
    edicion_anotar($pdo, $M, 999, 'Otro texto viejo.', 'Otro texto nuevo.');
    $GLOBALS['APRENDIZ_SIM'] = 'fallo';
    $vueltas = 0;
    for ($i = 0; $i < 8; $i++) {
        $ri = edicion_digerir($pdo, $M, 5);
        if ((int)($ri['fallidas'] ?? 0) === 0) break;
        $vueltas++;
    }
    $GLOBALS['APRENDIZ_SIM'] = 'ok';
    ok('deja de intentarlo', $vueltas < 8, $vueltas . ' vueltas y seguía');
    $f2 = $pdo->query("SELECT estado, porque FROM crecer_memoria WHERE marca_id={$M}
                        AND tipo='edicion_cruda' AND fuente_id=999")->fetch(PDO::FETCH_ASSOC);
    ok('y la nota queda para diagnóstico, no borrada',
       (bool)$f2 && (string)$f2['estado'] === 'descartada', json_encode($f2));
    ok('diciendo por qué',
       mb_strlen(trim((string)($f2['porque'] ?? ''))) > 10, (string)($f2['porque'] ?? ''));
    ok('y ya no se vuelve a intentar',
       (int)($ri['fallidas'] ?? 0) === 0 && (int)($ri['digeridas'] ?? 0) === 0, json_encode($ri ?? null));

    // ══════════════════════════════════════════════════════════════
    //  7 · MIRAR PANTALLAS NO DIGIERE NADA
    // ══════════════════════════════════════════════════════════════
    echo "\n  — abrir una pantalla no dispara el Aprendiz —\n";
    foreach (['panel/index.php', 'panel/meta.php', 'panel/_meta_semana.php'] as $arch) {
        $src = (string)file_get_contents(__DIR__ . '/../' . $arch);
        $cod = (string)preg_replace(['~/\*[\s\S]*?\*/~', '~^\s*//[^\n]*$~m'], ' ', $src);
        ok("{$arch} no digiere ediciones", mb_strpos($cod, 'edicion_digerir') === false,
           'digerir es trabajo del cron, no de una carga de pantalla');
    }
    //  Y el cron SÍ lo hace.
    $cron = (string)file_get_contents(__DIR__ . '/../scripts/cron_corillo.php');
    ok('el cron del corillo sí lo hace', mb_strpos($cron, 'edicion_digerir') !== false,
       'sin engancharlo a una corrida real, la cola sigue muerta');

} catch (Throwable $e) {
    $fallos++; echo "\n  EXCEPCION · " . get_class($e) . ': ' . $e->getMessage()
                  . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    foreach ($limpiar as $mid) { try { Fixture::limpiar($pdo, $mid); } catch (Throwable $e) {} }
    echo "\n  (fixtures limpiadas)\n";
}

echo "\n  — el costo —\n";
ok('ni una fila nueva en el log del modelo', $cnt('crecer_ia_log') === $g['ia'],
   'antes ' . $g['ia'] . ' · ahora ' . $cnt('crecer_ia_log')
   . ' — el Aprendiz corrió entero contra su doble');
ok('cero asientos de cuota', $cnt('crecer_img_cuota_asiento') === $g['cuota']);

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  EL CICLO SE CIERRA · $n afirmaciones\n\n"
    : "  $fallos FALLAS de $n\n\n";
exit($fallos === 0 ? 0 : 1);
