<?php
// ============================================================
//  CRECER — PERFILADOR DEL PRIMER POST GRATIS
//  tests/_perfil_post_gratis.php
//
//  Recorre la cadena REAL que vive el dueño desde que cierra la
//  entrevista hasta que tiene su post, y cuenta lo unico que
//  explica la espera: cuantas idas y vueltas al proveedor van
//  UNA DETRAS DE OTRA en el camino critico.
//
//  Cero gasto: el transporte se sustituye por un doble que cobra
//  el tiempo en la cuenta (no duerme) y devuelve una respuesta
//  con la forma correcta. Lo que se mide es la FORMA de la
//  cadena, que es lo que el hotfix cambia.
//
//    php tests/_perfil_post_gratis.php
// ============================================================

define('GEMINI_API_KEY', 'llave-falsa-de-prueba-no-autentica');
define('CRECER_TEST_RED_FALSA', true);

//  Latencias MEDIDAS en crecer_ia_log (promedios reales de este
//  proyecto), no inventadas. Se usan para pesar cada eslabon.
const MS_TEXTO   = 2200;    // llamada de texto promedio
const MS_PERFIL  = 3000;    // extraer perfil
const MS_IMAGEN  = 16000;   // "Crear arte de post" (avg real; max medido 52s)

$GLOBALS['TR'] = ['n' => 0, 'ms' => 0, 'llamadas' => []];

/** Doble del transporte: cuenta la llamada, le pone su peso y contesta bien. */
function ia_http_post_retry(string $url, array $headers, string $body,
                            int $max_reintentos = 4, int $timeout = 60): string {
    $peso = (stripos($body, 'perfil') !== false) ? MS_PERFIL : MS_TEXTO;
    $GLOBALS['TR']['n']++;
    $GLOBALS['TR']['ms'] += $peso;
    $t = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8);
    $quien = '';
    foreach ($t as $f) {
        $fn = $f['function'] ?? '';
        if (in_array($fn, ['ia_http_post_retry', 'gemini_generar', 'ia_ejecutar', 'ia_llamar'], true)) continue;
        $quien = $fn; break;
    }
    $GLOBALS['TR']['llamadas'][] = ['quien' => $quien, 'ms' => $peso];
    //  Un JSON que sirve para todos los llamadores: los que piden
    //  JSON encuentran sus llaves, los que piden texto leen texto.
    $j = json_encode([
        'descripcion' => 'Negocio de prueba', 'voz' => 'cercana', 'publico_objetivo' => 'vecinos',
        'ofertas' => '', 'productos' => ['algo'], 'identidad' => 'x', 'reglas_imagen' => 'x',
        'reglas_voz' => 'x', 'reglas_estrategia' => 'x', 'personalidad' => 'x',
        'caption' => 'Texto de prueba del post.', 'texto' => 'Texto de prueba del post.',
        'angulos' => [['tactica'=>'a','gancho'=>'b','porque_pega'=>'c','visual'=>'d'],
                      ['tactica'=>'e','gancho'=>'f','porque_pega'=>'g','visual'=>'h'],
                      ['tactica'=>'i','gancho'=>'j','porque_pega'=>'k','visual'=>'l']],
        'elegido' => 1, 'razon' => 'x', 'brief' => 'x', 'visual' => 'x',
        'tono_boricua' => 70, 'tono_formal' => 30, 'tono_venta' => 50, 'tono_ingenio' => 50,
        'ejes' => ['formalidad' => 40], 'pregunta' => '¿Y que mas?', 'done' => true,
    ], JSON_UNESCAPED_UNICODE);
    return json_encode(['candidates' => [['content' => ['parts' => [['text' => $j]]]]],
                        'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 100]]);
}

/** Doble del borde de OpenAI: encolar es barato; la IMAGEN es la que pesa. */
function openai_responses_crear_bg(string $brief, array $opts = []): array {
    $GLOBALS['TR']['n']++;
    $GLOBALS['TR']['ms'] += 900;
    $GLOBALS['TR']['llamadas'][] = ['quien' => 'openai_responses_crear_bg (encolar)', 'ms' => 900];
    return ['id' => 'resp_perf_' . bin2hex(random_bytes(4)), 'modelo' => 'simulado', 'status' => 'queued'];
}

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/agentes.php';
require_once __DIR__ . '/../includes/img_responses.php';
require_once __DIR__ . '/_fixture.php';

function etapa(string $nombre, callable $fn): array {
    $antes = ['n' => $GLOBALS['TR']['n'], 'ms' => $GLOBALS['TR']['ms']];
    $GLOBALS['TR']['llamadas'] = [];
    $fn();
    $n  = $GLOBALS['TR']['n']  - $antes['n'];
    $ms = $GLOBALS['TR']['ms'] - $antes['ms'];
    printf("  %-26s %2d llamadas   %6.1f s\n", $nombre, $n, $ms / 1000);
    foreach ($GLOBALS['TR']['llamadas'] as $c) printf("       · %-42s %5.1f s\n", $c['quien'], $c['ms'] / 1000);
    return ['n' => $n, 'ms' => $ms];
}

echo "\nPRIMER POST GRATIS · DONDE SE VA EL TIEMPO\n" . str_repeat('=', 62) . "\n";
echo "  motor de imagen: " . (img_resp_activo() ? 'responses (async)' : 'actual (sincrono)') . "\n";

$f = Fixture::crear($pdo, 'perfil-post-gratis', false);
$marca_id = (int)$f['marca_id'];
echo "  marca de prueba: {$marca_id}\n\n";

try {
    $hist = [['q' => '¿Que vendes?', 'a' => 'Bizcochos por encargo en Caguas.']];

    $t = [];
    $t[] = etapa('entrevista (1 turno)',  function () use ($pdo, $marca_id, $hist) { entrevista_siguiente($pdo, $marca_id, $hist); });
    $t[] = etapa('finalizar (perfil+RX)', function () use ($pdo, $marca_id, $hist) { entrevista_finalizar($pdo, $marca_id, $hist); });

    //  LO QUE EL DUEÑO ESPERA AHORA antes de ver la pantalla: crear la fila.
    //  Cero llamadas a proveedor — es un INSERT.
    $espera = etapa('post_muestra (AHORA)', function () use ($pdo, $marca_id) { muestra_fila($pdo, $marca_id); });

    //  Y lo que ANTES corria en ese mismo request, con el dueño mirando un
    //  spinner: las cinco llamadas de texto y el encolado del arte. Hoy esto
    //  vive en el worker y el dueño lo ve ocurrir por etapas.
    $t[] = $espera;
    $fondo = etapa('  (ahora en el worker)', function () use ($pdo, $marca_id) { crear_post_muestra($pdo, $marca_id); });

    $pieza = $pdo->query("SELECT id, img_estado, img_job, grafica_path FROM crecer_contenido WHERE marca_id={$marca_id} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    echo "\n  pieza #{$pieza['id']}  img_estado=" . var_export($pieza['img_estado'], true)
       . "  job=" . ($pieza['img_job'] ? 'si' : 'NO') . "  arte=" . ($pieza['grafica_path'] ? 'si' : 'NO') . "\n";

    $espera_ms = array_sum(array_column($t, 'ms'));            // hasta ver la PANTALLA
    $antes_ms  = $espera_ms + $fondo['ms'];                     // lo que se esperaba mudo antes
    echo "\n" . str_repeat('-', 62) . "\n";
    printf("  ANTES · spinner mudo hasta ver algo:      %5.1f s  (%d llamadas en serie)\n",
           $antes_ms / 1000, array_sum(array_column($t, 'n')) + $fondo['n']);
    printf("  AHORA · hasta la pantalla con etapas:     %5.1f s  (%d llamadas en serie)\n",
           $espera_ms / 1000, array_sum(array_column($t, 'n')));
    printf("  el resto (%4.1f s de texto + ~%d s de imagen) el dueño lo VE ocurrir.\n",
           $fondo['ms'] / 1000, (int)(MS_IMAGEN / 1000));
    echo str_repeat('-', 62) . "\n";
} finally {
    Fixture::limpiar($pdo, $marca_id);
    echo "\n  fixture limpiada.\n";
}
