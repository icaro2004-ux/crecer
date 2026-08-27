<?php
// ============================================================
//  CRECER — EL DIAGNOSTICO DE LA CUOTA HISTORICA CLASIFICA BIEN Y NO ESCRIBE
//  tests/test_cuota_historica.php
//
//  QUE SE JUEGA AQUI. La salida de este diagnostico va a decidir si se devuelven
//  o se confirman unidades del mes de gente que paga. Un veredicto equivocado no
//  es un rojo en una pantalla: es cobrarle a alguien una imagen que no recibio,
//  o regalarle para siempre las que si.
//
//  Por eso se prueban DOS cosas distintas:
//
//    1 · QUE CLASIFICA BIEN. Quince escenarios sembrados en una base de usar y
//        tirar —entregada, terminal, caducada, con job, reciente, origen 0,
//        relacion rota, slide, realce, confirmada, liberada, riesgo, exenta, dos
//        marcas, dos cubos— y cada uno tiene que caer en su clase.
//
//    2 · QUE ES FALSABLE. Si se le quita la ruta entregada, la clase CAMBIA. Si
//        se le quita el error terminal, CAMBIA. Si se le pone un job, la
//        caducada deja de ser segura. Y origen 0 NUNCA se vuelve «seguro» solo.
//        Una clasificacion que da lo mismo pase lo que pase no clasifica nada.
//
//  Y QUE NO ESCRIBE: se toma un retrato de las tres tablas antes y despues, y
//  tienen que ser identicas byte a byte.
// ============================================================

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/cuota_historica.php';
require_once __DIR__ . '/_esquema_desechable.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nEL DIAGNOSTICO DE LA CUOTA HISTORICA\n" . str_repeat('=', 58) . "\n";

$cnt = fn(string $t, string $w = '1') => (int)$GLOBALS['pdo']->query(
    "SELECT COUNT(*) FROM {$t} WHERE {$w}")->fetchColumn();
$g = ['ia' => $cnt('crecer_ia_log'), 'as' => $cnt('crecer_img_cuota_asiento')];

// ══════════════════════════════════════════════════════════════
//  1 · EL SQL QUE SE PEGA EN PRODUCCION ES SOLO LECTURA
// ══════════════════════════════════════════════════════════════
//
//  Esta afirmacion es la que permite mandarle el archivo a alguien y decirle
//  «pegalo entero». Si alguien mete aqui una sola escritura, esto se pone rojo
//  antes de que llegue a produccion.
echo "\n  — el SQL que se pega en producción no escribe —\n";
$sql = (string)@file_get_contents(dirname(__DIR__) . '/_DIAGNOSTICO-CUOTA-HISTORICA.sql');
ok('el archivo existe', $sql !== '');

//  Los comentarios NO cuentan: la cabecera nombra las palabras prohibidas justo
//  para explicar que no estan. Se mira lo que se ejecuta.
$sql_codigo = (string)preg_replace(['~/\*[\s\S]*?\*/~', '~^\s*--[^\n]*$~m'], ' ', $sql);
$PROHIBIDO = ['INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'ALTER',
              'DROP', 'CREATE', 'TRUNCATE', 'CALL', 'GRANT', 'LOAD DATA'];
$encontrado = [];
foreach ($PROHIBIDO as $v) {
    if (preg_match('~\b' . preg_quote($v, '~') . '\b~i', $sql_codigo)) $encontrado[] = $v;
}
ok('no contiene ni una sentencia de escritura', $encontrado === [],
   implode(', ', $encontrado) . ' · este archivo se pega entero en producción');
ok('y sí contiene SELECT', preg_match('~\bSELECT\b~i', $sql_codigo) === 1);
ok('sus variables son SET de sesión', preg_match('~\bSET\s+@~i', $sql_codigo) === 1);

//  Y el mismo candado sobre la herramienta PHP.
echo "\n  — y la herramienta PHP tampoco —\n";
//  DOS LECTURAS DEL MISMO ARCHIVO, Y NO ES CAPRICHO:
//
//    · el SQL de escritura VIVE en cadenas, asi que para buscarlo hay que
//      conservarlas;
//    · una LLAMADA no puede vivir en una cadena, asi que para buscarla hay
//      que quitarlas — o el texto «CuotaImg::confirmar() — la unidad ya se
//      gasto» acusa a la herramienta de hacer justo lo que dice que HARIA.
//
//  Una regla que se pone roja por algo inofensivo enseña a ignorar el rojo.
function _codigo_php(string $abs, bool $con_cadenas): string {
    $php = (string)@file_get_contents($abs);
    $out = '';
    foreach (token_get_all($php) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) { $out .= ' '; continue; }
            if (!$con_cadenas && ($t[0] === T_CONSTANT_ENCAPSED_STRING
                                  || $t[0] === T_ENCAPSED_AND_WHITESPACE)) { $out .= " '' "; continue; }
            $out .= $t[1];
        } else { $out .= $t; }
    }
    return $out;
}
foreach (['includes/cuota_historica.php'] as $rel) {
    $abs = dirname(__DIR__) . '/' . $rel;
    $mal = [];
    //  1 · SQL de escritura, con las cadenas puestas.
    $con = _codigo_php($abs, true);
    foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM', 'ALTER TABLE', 'DROP ',
              'TRUNCATE', 'REPLACE INTO'] as $v) {
        if (stripos($con, $v) !== false) $mal[] = 'SQL: ' . trim($v);
    }
    //  2 · llamadas que escriben o salen a la red, con las cadenas quitadas.
    $sin = _codigo_php($abs, false);
    foreach (['CuotaImg::confirmar', 'CuotaImg::liberar', 'CuotaImg::reservar',
              'barrerCaducadas', 'ia_imagen', 'ia_ejecutar', 'file_put_contents',
              'unlink', 'curl_exec', 'curl_init'] as $v) {
        if (preg_match('~' . preg_quote($v, '~') . '\s*\(~i', $sin)) $mal[] = $v . '()';
    }
    if (preg_match('~->\s*exec\s*\(~i', $sin)) $mal[] = '->exec()';
    ok("{$rel} no escribe ni llama a nadie", $mal === [], implode(' · ', $mal));
    //  Y que la prueba sirva: si buscara la llamada CON las cadenas puestas,
    //  la encontraria — eso es lo que demuestra que quitarlas era necesario.
    ok('(y la herramienta sí NOMBRA lo que haría)',
       preg_match('~CuotaImg::confirmar~i', $con) === 1,
       'tiene que decir la acción sugerida, y decirla no es hacerla');
}

// ══════════════════════════════════════════════════════════════
//  2 · LOS QUINCE ESCENARIOS, EN UNA BASE DE USAR Y TIRAR
// ══════════════════════════════════════════════════════════════
echo "\n  — quince escenarios, cada uno en su clase —\n";
$copia = EsquemaDesechable::crear($pdo, ['crecer_img_cuota_asiento', 'crecer_img_cuota_cubo',
                                         'crecer_contenido', 'crecer_carrusel']);
if ($copia === null) {
    echo "  (sin privilegios para crear la base de copia · se salta)\n";
} else {
  try {
    $v = $copia->pdo();
    $ahora = (string)$v->query('SELECT NOW()')->fetchColumn();
    $hace = fn(int $min) => date('Y-m-d H:i:s', strtotime($ahora) - $min * 60);
    $UM = cuota_hist_umbral_min();

    //  Las piezas y los slides sobre los que se apoya la evidencia.
    $ip = $v->prepare("INSERT INTO crecer_contenido
            (id, marca_id, plataforma, tipo, caption, estado, grafica_path,
             img_estado, img_job, img_error_clase)
          VALUES (?,?, 'instagram','post','[prueba]',?,?,?,?,?)");
    $JOB = 'resp_de_prueba_0001';
    //  El nombre que escribe img_responses: resp_<pieza>_<md5(job) 8>.png
    $ruta_atada = '/crecer/uploads/marca_7/graficas/resp_101_' . substr(md5($JOB), 0, 8) . '.png';

    $ip->execute([101, 7, 'borrador', $ruta_atada,        'ok',     $JOB, null]);          // entregada, atada
    $ip->execute([102, 7, 'borrador', '',                 'error',  null, 'sin_credito']); // terminal
    $ip->execute([103, 7, 'borrador', '',                 null,     null, null]);          // caducada
    $ip->execute([104, 7, 'borrador', '',                 'queued', 'resp_vivo_9', null]); // job vivo
    $ip->execute([105, 7, 'borrador', '',                 null,     null, null]);          // reciente
    $ip->execute([106, 7, 'borrador', '/crecer/uploads/marca_7/vieja.png', 'ok', null, null]); // entregada NO atada
    $ip->execute([107, 9, 'borrador', '',                 null,     null, null]);          // de OTRA marca
    $ip->execute([108, 7, 'borrador', $ruta_atada,        'ok',     $JOB, null]);          // realce entregado

    $v->prepare("INSERT INTO crecer_carrusel (id, contenido_id, marca_id, orden, idea, grafica_path, img_estado)
                 VALUES (?,?,?,?, '[prueba]', ?, 'ok')")
      ->execute([501, 101, 7, 1, '/crecer/uploads/marca_7/slides/s_' . substr(md5($JOB), 0, 8) . '.png']);

    $ia = $v->prepare("INSERT INTO crecer_img_cuota_asiento
            (id, marca_id, cubo, idem, operacion, ruta, punto, exencion, unidades,
             estado, origen_tipo, origen_id, provider_job_id, llamadas, costo_usd,
             overage, motivo, created_at, updated_at)
          VALUES (?,?,?,?,?, 'prueba','prueba',?,?,?,?,?,?, 1, 0, 0, '', ?, ?)");
    $sembrar = function (int $id, string $cubo, string $idem, string $op, string $ex, int $u,
                         string $est, ?string $otipo, int $oid, ?string $job, int $edad_min)
                        use ($ia, $hace) {
        $ia->execute([$id, $id === 14 ? 9 : 7, $cubo, $idem, $op, $ex, $u, $est,
                      $otipo, $oid, $job, $hace($edad_min), $hace($edad_min)]);
    };

    $M = '2026-08';
    //  Minutos de edad que caen ANTES de la fecha del arreglo, contados desde
    //  la hora de MySQL: asi la prueba no depende del dia en que se corra.
    $edad_vieja = max(600, (int)floor((strtotime($ahora) - strtotime(CUOTA_HIST_ARREGLO)) / 60) + 120);
    //  1 entregada sin confirmar   2 terminal   3 caducada   4 job vivo   5 reciente
    $sembrar(1,  $M, 'k01', 'arte_post', '', 1, 'reservado', 'contenido', 101, $JOB, 600);
    $sembrar(2,  $M, 'k02', 'arte_post', '', 1, 'reservado', 'contenido', 102, null, 600);
    $sembrar(3,  $M, 'k03', 'arte_post', '', 1, 'reservado', 'contenido', 103, null, $UM + 120);
    $sembrar(4,  $M, 'k04', 'arte_post', '', 1, 'reservado', 'contenido', 104, 'resp_vivo_9', 600);
    $sembrar(5,  $M, 'k05', 'arte_post', '', 1, 'reservado', 'contenido', 105, null, max(1, $UM - 10));
    //  6 origen 0   7 relacion rota   8 slide entregado   9 realce entregado
    //  ANTES del arreglo: es historia, no regresion. Sembrarlo con la edad de
    //  las demas lo dejaba «hoy», y hoy ya es despues — el clasificador lo
    //  marcaba REGRESION, con razon, y la prueba culpaba al clasificador.
    $sembrar(6,  $M, 'k06', 'arte_post', '', 1, 'reservado', 'contenido', 0,   null, $edad_vieja);
    $sembrar(7,  $M, 'k07', 'arte_post', '', 1, 'reservado', 'contenido', 999, null, 600);
    $sembrar(8,  $M, 'k08', 'slide',     '', 1, 'reservado', 'slide',     501, $JOB, 600);
    $sembrar(9,  $M, 'k09', 'realce',    '', 1, 'reservado', 'contenido', 108, $JOB, 600);
    //  10 confirmada   11 liberada   12 riesgo   13 exenta
    $sembrar(10, $M, 'k10', 'arte_post', '', 1, 'confirmado','contenido', 101, $JOB, 600);
    $sembrar(11, $M, 'k11', 'arte_post', '', 1, 'liberado',  'contenido', 102, null, 600);
    $sembrar(12, $M, 'k12', 'arte_post', '', 1, 'riesgo',    'contenido', 106, null, 600);
    $sembrar(13, $M, 'k13', 'arte_post', 'fundador', 1, 'reservado', 'contenido', 103, null, $UM + 200);
    //  14 otra marca   15 otro cubo
    $sembrar(14, $M, 'k14', 'arte_post', '', 1, 'reservado', 'contenido', 107, null, $UM + 300);
    $sembrar(15, '2026-07', 'k15', 'arte_post', '', 1, 'reservado', 'contenido', 103, null, 9000);

    $v->prepare("INSERT INTO crecer_img_cuota_cubo (marca_id, cubo, limite, usadas, created_at, updated_at)
                 VALUES (7,?,40,12,NOW(),NOW()), (9,?,40,3,NOW(),NOW()), (7,'2026-07',40,5,NOW(),NOW())")
      ->execute([$M, $M]);

    $R = cuota_hist_leer($v, ['tope' => 100]);
    $por_id = [];
    foreach ($R['filas'] as $f) $por_id[(int)$f['asiento']['id']] = $f;

    $esperado = [
        1  => 'entregada_sin_confirmar',
        2  => 'fallo_terminal_sin_entrega',
        3  => 'caducada_sin_job',
        4  => 'job_posiblemente_vivo',
        5  => 'reserva_reciente',
        6  => 'sin_atribucion',
        7  => 'sin_atribucion',
        8  => 'entregada_sin_confirmar',
        9  => 'entregada_sin_confirmar',
        13 => 'caducada_sin_job',
        14 => 'caducada_sin_job',
        15 => 'caducada_sin_job',
    ];
    foreach ($esperado as $id => $cl) {
        $got = $por_id[$id]['clase'] ?? '(no clasificado)';
        ok("#{$id} → {$cl}", $got === $cl,
           "salió «{$got}» · evidencia: " . implode(' | ', $por_id[$id]['evidencia'] ?? []));
    }
    ok('los cerrados no entran en la lista de vivos',
       !isset($por_id[10]) && !isset($por_id[11]),
       'confirmado y liberado no retienen unidad: no son trabajo pendiente');
    ok('pero «riesgo» SÍ entra',   isset($por_id[12]),
       'riesgo retiene unidad igual que reservado');
    ok('la exención se conserva en la salida',
       (string)($por_id[13]['asiento']['exencion'] ?? '') === 'fundador');

    //  DOS MARCAS Y DOS CUBOS, contados aparte.
    ok('cuenta las dos marcas',
       count(array_unique(array_map(fn($f) => (int)$f['asiento']['marca_id'], $R['filas']))) === 2);
    ok('y los dos cubos',
       count(array_unique(array_map(fn($f) => (string)$f['asiento']['cubo'], $R['filas']))) === 2);

    //  EL IMPACTO EN EL CUBO, calculado y no escrito.
    echo "\n  — el impacto se calcula, no se aplica —\n";
    $c7 = $R['cubos']['7|' . $M] ?? null;
    ok('el cubo de la marca 7 aparece', $c7 !== null, json_encode(array_keys($R['cubos'])));
    if ($c7) {
        ok('cuenta las confirmadas',  $c7['confirmadas'] === 1, json_encode($c7));
        ok('cuenta las liberadas',    $c7['liberadas'] === 1);
        ok('y las vivas',             $c7['vivas'] >= 10, (string)$c7['vivas']);
        ok('dice cuánto bajaría con solo lo seguro', $c7['bajarian'] > 0, (string)$c7['bajarian']);
        ok('y las usadas NO cambiaron',
           (int)$v->query("SELECT usadas FROM crecer_img_cuota_cubo
                            WHERE marca_id=7 AND cubo='{$M}'")->fetchColumn() === 12,
           'el impacto es una resta en pantalla, no una escritura');
    }

    // ══════════════════════════════════════════════════════════════
    //  3 · FALSABILIDAD · si cambias la evidencia, cambia la clase
    // ══════════════════════════════════════════════════════════════
    echo "\n  — y si le quitas la evidencia, cambia el veredicto —\n";
    $base_ent = $por_id[1]['asiento'];
    $ev_ent   = $por_id[1]['ev'];

    //  a · sin ruta entregada, deja de ser «entregada»
    $sin_ruta = $ev_ent; $sin_ruta['pieza']['grafica_path'] = '';
    $c = cuota_hist_clasificar($base_ent, $sin_ruta);
    ok('sin ruta entregada ya no es «entregada»', $c['clase'] !== 'entregada_sin_confirmar',
       $c['clase']);

    //  b · sin error terminal, deja de ser «terminal»
    $ev_term = $por_id[2]['ev']; $ev_term['pieza']['img_error_clase'] = '';
    $c = cuota_hist_clasificar($por_id[2]['asiento'], $ev_term);
    ok('sin error terminal ya no es «terminal»', $c['clase'] !== 'fallo_terminal_sin_entrega',
       $c['clase']);

    //  c · con job, la caducada deja de ser segura
    $cad = $por_id[3]['asiento']; $cad['provider_job_id'] = 'resp_aparecio';
    $c = cuota_hist_clasificar($cad, $por_id[3]['ev']);
    ok('con job, la caducada deja de ser segura', $c['clase'] === 'job_posiblemente_vivo',
       $c['clase']);
    ok('y su nivel pasa a «no tocar»', $c['nivel'] === 'no tocar', $c['nivel']);

    //  d · ORIGEN 0 NUNCA SE VUELVE SEGURO SOLO. Ni con ruta, ni con job.
    $o0 = $por_id[6]['asiento'];
    $ev0 = $por_id[6]['ev'];
    $ev0['pieza'] = ['id' => 101, 'marca_id' => 7, 'estado' => 'borrador',
                     'grafica_path' => $ruta_atada, 'img_job' => $JOB, 'img_error_clase' => null];
    $o0['provider_job_id'] = $JOB;
    $c = cuota_hist_clasificar($o0, $ev0);
    //  LO QUE IMPORTA ES EL NIVEL. Da igual si cae en «sin atribucion» o en
    //  «inconsistente»: lo que NO puede pasar nunca es que un origen 0 salga
    //  marcado como seguro de tocar automaticamente.
    ok('origen 0 nunca se vuelve «seguro» solo', $c['nivel'] !== 'automático seguro',
       $c['clase'] . ' · ' . $c['nivel']);

    //  e · una operacion por pieza con origen 0 creada DESPUES del arreglo es regresión
    $reg = $o0; $reg['created_at'] = date('Y-m-d H:i:s', strtotime(CUOTA_HIST_ARREGLO) + 3600);
    $c = cuota_hist_clasificar($reg, ['ahora' => $ahora] + $ev0);
    ok('origen 0 posterior al arreglo se marca REGRESIÓN', $c['clase'] === 'inconsistente',
       $c['clase']);

    //  f · una pieza de otra marca es inconsistencia, no una entrega
    $aj = $base_ent; $ev_aj = $ev_ent; $ev_aj['pieza']['marca_id'] = 99;
    $c = cuota_hist_clasificar($aj, $ev_aj);
    ok('una pieza de otra marca es inconsistencia', $c['clase'] === 'inconsistente', $c['clase']);

    //  g · dos vivos con la misma llave, tampoco se tocan
    $ev_gem = $ev_ent; $ev_gem['gemelos'] = 2;
    $c = cuota_hist_clasificar($base_ent, $ev_gem);
    ok('dos vivos con la misma llave es inconsistencia', $c['clase'] === 'inconsistente',
       $c['clase']);

    // ══════════════════════════════════════════════════════════════
    //  4 · Y NO ESCRIBIO NADA · retrato antes y después
    // ══════════════════════════════════════════════════════════════
    echo "\n  — y el diagnóstico no dejó una huella —\n";
    $retrato = function (PDO $p): string {
        $h = '';
        //  El cubo no tiene `id`: se ordena por lo que cada tabla si tiene.
        $orden = ['crecer_img_cuota_asiento' => 'id', 'crecer_img_cuota_cubo' => 'marca_id, cubo',
                  'crecer_contenido' => 'id', 'crecer_carrusel' => 'id'];
        foreach ($orden as $t => $por) {
            try {
                foreach ($p->query("SELECT * FROM {$t} ORDER BY {$por}") as $r) $h .= json_encode($r);
            } catch (Throwable $e) { $h .= 'tabla-ausente:' . $t; }
        }
        return sha1($h);
    };
    $antes = $retrato($v);
    cuota_hist_leer($v, ['tope' => 100]);
    cuota_hist_leer($v, ['marca_id' => 7]);
    $despues = $retrato($v);
    ok('las cuatro tablas quedan idénticas', $antes === $despues,
       "{$antes} ≠ {$despues}");

    // ══════════════════════════════════════════════════════════════
    //  5 · UN ESQUEMA VIEJO DEGRADA, no revienta
    // ══════════════════════════════════════════════════════════════
    echo "\n  — y con una columna de menos, lo dice en vez de reventar —\n";
    $copia->ejecutar("ALTER TABLE crecer_contenido DROP COLUMN img_error_clase");
    $R2 = cuota_hist_leer($v, ['tope' => 100]);
    ok('sigue devolviendo filas',   count($R2['filas']) > 0);
    ok('y declara el hueco',        $R2['huecos'] !== [], json_encode($R2['huecos']));
    ok('sin fatal',                 true);

  } finally {
    $copia->soltar($pdo);
  }
}

echo "\n  — el costo —\n";
ok('cero llamadas al modelo', $cnt('crecer_ia_log') === $g['ia']);
ok('cero asientos nuevos en la base compartida',
   $cnt('crecer_img_cuota_asiento') === $g['as'],
   'el diagnóstico solo lee: si esto se mueve, escribió');

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  DIAGNOSTICA SIN TOCAR · $n afirmaciones\n\n"
    : "  $fallos FALLAS de $n\n\n";
exit($fallos === 0 ? 0 : 1);
