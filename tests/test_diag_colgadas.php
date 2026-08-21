<?php
// ============================================================
//  CRECER — EL DIAGNOSTICO NO PUEDE CONVERTIRSE EN UNA PUERTA
//  tests/test_diag_colgadas.php
//
//  &test=colgadas es solo lectura, pero tiene UNA cosa que sale al mundo: la
//  consulta de estado al proveedor. Eso obliga a dos garantias que aqui se
//  fijan por escrito:
//
//   1 · PERTENENCIA. Para preguntar por un job hacen falta la pieza Y su marca,
//       y la fila tiene que existir con las dos. Con el id suelto, cambiar un
//       numero en la URL consultaria el trabajo de otro negocio.
//
//   2 · LO QUE SE IMPRIME. Solo http, status, error.type y error.code. El
//       cuerpo entero puede traer el prompt revisado y el nombre del negocio;
//       el prompt, lo que el dueño escribio de lo suyo. Una vez impreso en un
//       diagnostico ya no se recoge.
//
//  Y ademas: que el diagnostico no escriba, no genere y no llame a nadie salvo
//  cuando se le pide a proposito con &preguntar=1.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/_fixture.php';
require_once __DIR__ . '/../includes/diag_colgadas.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nEL DIAGNOSTICO DE PIEZAS COLGADAS\n" . str_repeat('=', 56) . "\n";

$A = null; $B = null;
try {
    $fa = Fixture::crear($pdo, 'diag-a');  $A = (int)$fa['marca_id']; $PA = (int)$fa['piezas'][0];
    $fb = Fixture::crear($pdo, 'diag-b');  $B = (int)$fb['marca_id']; $PB = (int)$fb['piezas'][0];

    $pdo->prepare("UPDATE crecer_contenido SET img_job='resp_de_A' WHERE id=? AND marca_id=?")
        ->execute([$PA, $A]);
    $pdo->prepare("UPDATE crecer_contenido SET img_job='resp_de_B' WHERE id=? AND marca_id=?")
        ->execute([$PB, $B]);

    // ══════════════════════════════════════════════════════════
    //  1 · PERTENENCIA
    // ══════════════════════════════════════════════════════════
    echo "\n  — la pieza tiene que ser de esa marca —\n";
    ok('con su marca, devuelve el job', diag_job_de_pieza($pdo, $PA, $A) === 'resp_de_A');
    ok('con la marca de OTRO, no devuelve nada', diag_job_de_pieza($pdo, $PA, $B) === null,
       'sin esto, cambiar un número en la URL consultaría el trabajo de otro negocio');
    ok('y al revés tampoco', diag_job_de_pieza($pdo, $PB, $A) === null);
    ok('cada una con la suya', diag_job_de_pieza($pdo, $PB, $B) === 'resp_de_B');

    echo "\n  — sin las dos, no se pregunta —\n";
    ok('sin marca, nada', diag_job_de_pieza($pdo, $PA, 0) === null);
    ok('sin pieza, nada', diag_job_de_pieza($pdo, 0, $A) === null);
    ok('negativos, nada', diag_job_de_pieza($pdo, -1, -1) === null);
    ok('una pieza que no existe, nada', diag_job_de_pieza($pdo, 999999999, $A) === null);

    echo "\n  — y sin job no hay nada que consultar —\n";
    $pdo->prepare("UPDATE crecer_contenido SET img_job=NULL WHERE id=? AND marca_id=?")
        ->execute([$PA, $A]);
    ok('job NULL, nada', diag_job_de_pieza($pdo, $PA, $A) === null);
    $pdo->prepare("UPDATE crecer_contenido SET img_job='   ' WHERE id=? AND marca_id=?")
        ->execute([$PA, $A]);
    ok('job en blanco, tampoco', diag_job_de_pieza($pdo, $PA, $A) === null,
       'llamar al proveedor con una cadena vacía es una llamada tirada');

    // ══════════════════════════════════════════════════════════
    //  2 · LO QUE SE IMPRIME
    // ══════════════════════════════════════════════════════════
    echo "\n  — de la respuesta solo salen cuatro campos —\n";
    $cuerpo = [
        'id' => 'resp_secreto', 'status' => 'failed',
        'output' => [['type' => 'image_generation_call',
                      'revised_prompt' => 'BIZCOCHO DE DOÑA FINA EN BAYAMÓN']],
        'error' => ['message' => 'algo con el nombre del negocio dentro',
                    'type' => 'invalid_request_error', 'code' => 'not_found'],
    ];
    $c = diag_campos_seguros(404, $cuerpo);
    ok('devuelve exactamente cuatro claves', count($c) === 4,
       'salieron: ' . implode(', ', array_keys($c)));
    ok('http', $c['http'] === 404);
    ok('status', $c['status'] === 'failed');
    ok('error.type', $c['error_type'] === 'invalid_request_error');
    ok('error.code', $c['error_code'] === 'not_found');

    $plano = json_encode($c, JSON_UNESCAPED_UNICODE);
    ok('NO sale el revised_prompt', strpos($plano, 'BIZCOCHO') === false,
       'el prompt revisado lleva lo que el dueño escribió de su negocio');
    ok('NO sale el mensaje del proveedor', strpos($plano, 'nombre del negocio') === false,
       'el message es prosa libre: puede traer cualquier cosa');
    ok('NI el id del job', strpos($plano, 'resp_secreto') === false);

    echo "\n  — y aguanta una respuesta rota —\n";
    $v = diag_campos_seguros(500, null);
    ok('sin cuerpo no revienta', $v['http'] === 500 && $v['status'] === ''
       && $v['error_type'] === '' && $v['error_code'] === '');

    // ══════════════════════════════════════════════════════════
    //  3 · EL DIAGNOSTICO, EN EL FUENTE
    // ══════════════════════════════════════════════════════════
    echo "\n  — la página está cerrada y no escribe —\n";
    $src = (string)file_get_contents(dirname(__DIR__) . '/_cache.php');
    ok('_cache.php exige rol admin antes de nada',
       strpos($src, "(\$__usuario['rol'] ?? '') !== 'admin'") !== false
       && strpos($src, 'http_response_code(403)') !== false);

    $ini = strpos($src, "\$__test === 'colgadas'");
    $fin = strpos($src, "\$__test === 'sondeo'", (int)$ini);
    $bloque = $ini === false ? '' : substr($src, (int)$ini, (int)$fin - (int)$ini);
    ok('el bloque existe', $bloque !== '');

    //  Se busca la LLAMADA -con su parentesis-, no el nombre suelto: el propio
    //  diagnostico lleva esos nombres entre comillas como MARCADORES para mirar
    //  si estan en el fuente desplegado. Acusarlo por eso seria el mismo falso
    //  positivo que dio la disciplina de fixtures con la palabra TRUNCATE.
    foreach (['UPDATE ' => 'escribiría', 'INSERT ' => 'escribiría', 'DELETE ' => 'borraría',
              'openai_responses_crear_bg(' => 'generaría', 'img_gemini_fallback(' => 'generaría',
              'CuotaImg::liberar(' => 'movería la cuota', 'CuotaImg::confirmar(' => 'movería la cuota',
              'arte_disparar(' => 'encolaría'] as $mal => $porque) {
        ok("no {$porque} ({$mal})", strpos($bloque, $mal) === false);
    }

    echo "\n  — y solo pregunta si se lo piden —\n";
    ok('la consulta va detrás de &preguntar=1',
       strpos($bloque, "empty(\$_GET['preguntar'])") !== false);
    ok('exige pieza Y marca', strpos($bloque, "\$_GET['pieza']") !== false
       && strpos($bloque, "\$_GET['marca']") !== false);
    ok('y la pertenencia la decide diag_job_de_pieza()',
       strpos($bloque, 'diag_job_de_pieza(') !== false,
       'la regla vive en un archivo aparte justamente para poder probarla');
    ok('imprime solo los campos seguros',
       strpos($bloque, 'diag_campos_seguros(') !== false);
    ok('nunca imprime el cuerpo', strpos($bloque, "\$r['body']") === false
       || strpos($bloque, "printf") !== false && strpos($bloque, "print.*body") === false);
    //  Una sola INVOCACION. La otra aparicion del nombre esta dentro de un
    //  mensaje para el admin -«ia_http_get_res() NO esta cargada»-, que no
    //  llama a nadie. Se cuentan las lineas que de verdad invocan.
    $invoca = 0;
    foreach (explode("
", $bloque) as $l) {
        if (preg_match('/(^|[=(,.\s])ia_http_get_res\s*\(/', $l)
            && strpos($l, 'echo') === false) $invoca++;
    }
    ok('la única llamada de red es un GET de estado',
       $invoca === 1 && strpos($bloque, 'ia_http_post') === false,
       "invocaciones={$invoca} · un GET al job que ya existe no genera imagen ni crea trabajo");

    echo "\n  — compara el disco con lo cargado —\n";
    ok('mira el fuente desplegado', strpos($bloque, 'file_get_contents') !== false);
    ok('y lo que de verdad está cargado',
       strpos($bloque, 'function_exists(') !== false && strpos($bloque, 'class_exists(') !== false,
       'un Redeploy con OPcache caliente deja el disco nuevo y el proceso viejo');
    ok('y lo dice cuando no coinciden', strpos($bloque, 'OPcache') !== false);

} finally {
    foreach ([$A, $B] as $m) { if ($m) Fixture::limpiar($pdo, $m); }
    echo "\n  (fixtures limpiadas)\n";
}

echo "\n" . str_repeat('=', 56) . "\n";
echo $fallos === 0 ? "  TODO OK · {$n} pruebas\n\n" : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
