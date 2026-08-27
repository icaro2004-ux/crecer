<?php
// ============================================================
//  CRECER — QUE SON LOS ASIENTOS VIVOS QUE LLEVAN AHI MESES
//  includes/cuota_historica.php
//
//  POR QUE EXISTE. Un asiento en 'reservado' retiene una unidad del mes del
//  dueño. Si la imagen llego y nadie cerro la unidad, el dueño esta pagando dos
//  veces por la misma pieza cuando la vuelva a intentar; si NO llego y nadie la
//  devolvio, esta pagando por algo que no recibio. Las dos cosas se ven igual
//  desde fuera: `estado='reservado'`. Hay que mirar la evidencia para saber cual
//  es cual — y hasta que se sepa, no se toca nada.
//
//  ESTO NO ESCRIBE. Ni una fila, ni un archivo, ni una llamada a nadie. Lee,
//  clasifica y dice lo que HARIA. La reconciliacion es otra decision, de otro
//  dia, y con esta salida delante.
//
//  LA REGLA QUE ORDENA TODO: no se infiere en silencio. Una pieza con
//  `grafica_path` lleno NO demuestra que ESE asiento la pago —el arte pudo ser
//  anterior—. Solo cuenta la correlacion estructurada: el job del asiento es el
//  job de la pieza, o el nombre del archivo lleva ese job dentro. Sin eso, la
//  clase es «incierta», que es lo unico honesto.
//
//  Y NO SE INVENTA NINGUN UMBRAL. La caducidad es la que usa de verdad
//  CuotaImg::barrerCaducadas(): CADUCA_MIN minutos y solo sin job.
// ============================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cuota_imagenes.php';

/** El umbral REAL de caducidad, leido del dominio. Nunca uno propio. */
function cuota_hist_umbral_min(): int
{
    return class_exists('CuotaImg') && defined('CuotaImg::CADUCA_MIN')
        ? (int)CuotaImg::CADUCA_MIN : 45;
}

/** Los estados que retienen una unidad. Es la misma lista del dominio. */
function cuota_hist_estados_vivos(): array { return ['reservado', 'riesgo']; }

/**
 * Clases de error del dominio que significan «esto ya no va a llegar».
 *
 * Se leen del prefijo que escribe img_responses.php. `enc:` es un ENCOLADO que
 * no se pudo confirmar —no es terminal: el job pudo crearse igual— y por eso NO
 * esta aqui: tratarlo como terminal seria devolver una unidad de una imagen que
 * puede aparecer.
 */
function cuota_hist_clases_terminales(): array
{
    return [
        'sin_credito'            => 'el proveedor dijo que no queda crédito',
        'job_no_existe'          => 'el proveedor no reconoce ese trabajo',
        'rechazado_confirmado'   => 'el proveedor rechazó la petición',
        'fbx:respaldo_fallo'     => 'falló también el respaldo',
        'tope_fallos_consulta'   => 'se agotaron los intentos de consulta',
    ];
}

/**
 * CLASIFICA UN ASIENTO. Funcion PURA: no toca la base ni el disco ni la red.
 *
 * Recibe la fila del asiento y un `$ev` con la evidencia YA LEIDA por quien
 * llama. Que sea pura no es pulcritud: es lo que permite sembrar los quince
 * escenarios en una base de usar y tirar y comprobar que clasifica bien, sin
 * que la prueba tenga que fabricar medio sistema.
 *
 * @param array $a  fila de crecer_img_cuota_asiento
 * @param array $ev evidencia: [
 *      'ahora'        => 'Y-m-d H:i:s'   (hora de MySQL, no de PHP)
 *      'pieza'        => ?array  (id, estado, grafica_path, img_estado, img_job,
 *                                 img_error_clase, img_job_at, img_next_poll_at, marca_id)
 *      'slide'        => ?array  (id, contenido_id, grafica_path, img_estado, contenido_estado)
 *      'gemelos'      => int     asientos vivos con la MISMA llave canonica
 *      'cubo_existe'  => bool
 *   ]
 * @return array{clase:string, evidencia:array, accion:string, nivel:string}
 */
function cuota_hist_clasificar(array $a, array $ev): array
{
    $estado  = (string)($a['estado'] ?? '');
    $vivo    = in_array($estado, cuota_hist_estados_vivos(), true);
    $op      = (string)($a['operacion'] ?? '');
    $otipo   = (string)($a['origen_tipo'] ?? '');
    $oid     = (int)($a['origen_id'] ?? 0);
    $job     = trim((string)($a['provider_job_id'] ?? ''));
    $unid    = (int)($a['unidades'] ?? 0);
    $creado  = (string)($a['created_at'] ?? '');
    $ahora   = (string)($ev['ahora'] ?? '');
    $edad    = ($creado !== '' && $ahora !== '')
             ? (int)floor((strtotime($ahora) - strtotime($creado)) / 60) : -1;

    $E = [];   // la evidencia, dicha
    //  POR REFERENCIA, y no es un detalle: una flecha `fn()` captura por VALOR
    //  en el momento de crearse, asi que $E salia SIEMPRE vacio y el
    //  diagnostico daba una clase sin decir en que se apoyaba. Un veredicto sin
    //  evidencia es justo lo que esta herramienta existe para no producir.
    $out = function (string $c, string $acc, string $niv) use (&$E): array {
        return ['clase' => $c, 'evidencia' => $E, 'accion' => $acc, 'nivel' => $niv];
    };

    // ── INCONSISTENCIAS PRIMERO. Lo que no puede ser, no se clasifica como si
    //    pudiera: se marca y se deja quieto.
    if ($unid <= 0 || $unid > 10) {
        $E[] = "unidades imposibles: {$unid}";
        return $out('inconsistente', 'no tocar · revisar a mano', 'no tocar');
    }
    if ($vivo && !empty($ev['pieza']) && (int)($ev['pieza']['marca_id'] ?? 0) > 0
        && (int)$ev['pieza']['marca_id'] !== (int)($a['marca_id'] ?? 0)) {
        $E[] = 'la pieza atribuida es de otra marca';
        return $out('inconsistente', 'no tocar · revisar a mano', 'no tocar');
    }
    if ($vivo && (int)($ev['gemelos'] ?? 0) > 1) {
        $E[] = 'hay ' . (int)$ev['gemelos'] . ' asientos vivos con la misma llave canónica';
        return $out('inconsistente', 'no tocar · revisar a mano', 'no tocar');
    }
    //  Una operacion POR PIEZA sin pieza, creada DESPUES del arreglo, es una
    //  regresion: el arreglo consiste justo en que eso no vuelva a pasar.
    $POR_PIEZA = ['arte_post', 'realce', 'slide'];
    if (in_array($op, $POR_PIEZA, true) && $oid <= 0
        && $creado !== '' && strtotime($creado) >= strtotime(CUOTA_HIST_ARREGLO)) {
        $E[] = 'operación por pieza con origen 0 creada DESPUÉS del arreglo (' . CUOTA_HIST_ARREGLO . ')';
        $E[] = 'ruta: ' . (string)($a['ruta'] ?? '?') . ' · punto: ' . (string)($a['punto'] ?? '?');
        return $out('inconsistente', 'REGRESIÓN · avisar antes de tocar nada', 'no tocar');
    }

    if (!$vivo) {
        //  Los cerrados no se reclasifican: son historia y sirven de evidencia.
        $E[] = "cerrado como «{$estado}»";
        return $out('cerrado_' . ($estado ?: 'desconocido'), 'conservar como evidencia', 'no tocar');
    }

    // ── SIN ATRIBUCION. Ni pieza ni slide que mirar.
    $pieza = $ev['pieza'] ?? null;
    $slide = $ev['slide'] ?? null;
    if (in_array($op, $POR_PIEZA, true) && $oid <= 0) {
        $E[] = 'sin atribución estructurada (origen_id = 0)';
        $E[] = $job !== '' ? 'tiene job del proveedor' : 'sin job del proveedor';
        $E[] = 'creado ' . $creado . ' · antes del arreglo';
        return $out('sin_atribucion', 'no tocar sin revisión manual', 'requiere revisión');
    }
    if ($pieza === null && $slide === null && $oid > 0) {
        $E[] = "el origen {$otipo}#{$oid} ya no existe (relación rota)";
        return $out('sin_atribucion', 'no tocar sin revisión manual', 'requiere revisión');
    }

    // ── ¿SE ENTREGO? Y sobre todo: ¿se puede DEMOSTRAR que lo entregó ESTE?
    $entregado = null;   // ruta entregada, si la hay
    $correl    = '';     // como se demostro
    if ($slide !== null) {
        $ruta = trim((string)($slide['grafica_path'] ?? ''));
        if ($ruta !== '') {
            $entregado = $ruta;
            //  En los slides no hay job por slide en la fila: la correlacion
            //  fuerte que queda es el nombre del archivo con el job dentro.
            if ($job !== '' && str_contains($ruta, substr(md5($job), 0, 8))) {
                $correl = 'el nombre del archivo lleva este job dentro';
            }
        }
    } elseif ($pieza !== null) {
        $ruta = trim((string)($pieza['grafica_path'] ?? ''));
        if ($ruta !== '') {
            $entregado = $ruta;
            $pjob = trim((string)($pieza['img_job'] ?? ''));
            if ($job !== '' && $pjob !== '' && $pjob === $job) {
                $correl = 'la pieza sigue apuntando a este mismo job';
            } elseif ($job !== '' && str_contains($ruta, substr(md5($job), 0, 8))) {
                //  img_responses nombra el archivo resp_<pieza>_<md5(job) 8>.png:
                //  eso ATA el archivo a este job, no a otro cualquiera.
                $correl = 'el nombre del archivo lleva este job dentro';
            }
        }
    }

    if ($entregado !== null && $correl !== '') {
        $E[] = 'hay imagen entregada y se puede atar a este asiento: ' . $correl;
        $E[] = 'ruta: ' . $entregado;
        return $out('entregada_sin_confirmar',
                    'CuotaImg::confirmar() — la unidad ya se gastó de verdad',
                    'automático seguro');
    }

    // ── ERROR TERMINAL DEMOSTRADO, y sin nada entregado.
    $clase_err = trim((string)($pieza['img_error_clase'] ?? ''));
    $terminales = cuota_hist_clases_terminales();
    if ($entregado === null && $clase_err !== '') {
        foreach ($terminales as $k => $porque) {
            if ($clase_err === $k || str_starts_with($clase_err, $k)) {
                $E[] = "fallo terminal demostrado: {$porque} ({$clase_err})";
                $E[] = 'y no hay imagen entregada';
                return $out('fallo_terminal_sin_entrega',
                            'CuotaImg::liberar() — el dueño no recibió nada',
                            'automático seguro');
            }
        }
        $E[] = "la pieza marca «{$clase_err}», que NO está en la lista de terminales";
    }

    // ── CADUCADA SIN JOB. El umbral es el del dominio, no uno inventado.
    $umbral = cuota_hist_umbral_min();
    if ($job === '' && $entregado === null) {
        $trabajo_vivo = $pieza !== null
            && in_array((string)($pieza['img_estado'] ?? ''), ['queued', 'working'], true);
        if ($edad >= 0 && $edad > $umbral && !$trabajo_vivo) {
            $E[] = "sin job, sin entrega, {$edad} min de edad (umbral del dominio: {$umbral})";
            $E[] = 'y la pieza no tiene trabajo vivo';
            return $out('caducada_sin_job',
                        'CuotaImg::liberar() por el mismo camino que barrerCaducadas()',
                        'automático seguro');
        }
        if ($trabajo_vivo) {
            $E[] = 'sin job, pero la pieza dice que sigue trabajando (' . (string)$pieza['img_estado'] . ')';
            return $out('job_posiblemente_vivo', 'no tocar', 'no tocar');
        }
    }

    // ── RESERVA RECIENTE. Dentro del umbral: no es un resto, es trabajo.
    if ($edad >= 0 && $edad <= $umbral) {
        $E[] = "{$edad} min de edad · dentro del umbral vigente ({$umbral})";
        return $out('reserva_reciente', 'no tocar', 'no tocar');
    }

    // ── CON JOB Y SIN TERMINAL DEMOSTRADO. No se consulta al proveedor.
    if ($job !== '') {
        $E[] = 'tiene job del proveedor y no hay estado terminal demostrado';
        if ($entregado !== null) {
            $E[] = 'hay imagen en la pieza, pero NO se puede atar a este asiento: pudo ser anterior';
        }
        return $out('job_posiblemente_vivo',
                    'no tocar · no se consulta al proveedor desde un diagnóstico',
                    'no tocar');
    }

    // ── LO QUE QUEDA: hay algo entregado que no se puede atar, o falta contexto.
    if ($entregado !== null) {
        $E[] = 'hay imagen en la pieza, pero sin forma estructurada de atarla a este asiento';
        $E[] = 'ruta: ' . $entregado;
        return $out('sin_atribucion', 'no tocar sin revisión manual', 'requiere revisión');
    }
    $E[] = 'sin job, sin entrega y sin error terminal: evidencia insuficiente';
    return $out('sin_atribucion', 'no tocar sin revisión manual', 'requiere revisión');
}

/**
 * La fecha del arreglo del origen. Un asiento por pieza con origen 0 creado
 * DESPUES de esto no es historia: es una regresion.
 */
const CUOTA_HIST_ARREGLO = '2026-08-26 00:00:00';

/**
 * LEE Y CLASIFICA. Solo SELECT — ni un INSERT, UPDATE, DELETE ni DDL.
 *
 * @return array{ahora:string, base:string, resumen:array, filas:array,
 *                cubos:array, huecos:array}
 */
function cuota_hist_leer(PDO $pdo, array $opts = []): array
{
    $solo_marca = isset($opts['marca_id']) ? (int)$opts['marca_id'] : null;
    $tope       = max(1, min(500, (int)($opts['tope'] ?? 200)));
    $huecos     = [];

    $ahora = (string)$pdo->query('SELECT NOW()')->fetchColumn();
    $base  = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();

    //  ¿Existe el libro? Sin el, no hay nada que diagnosticar, y se dice.
    try {
        $pdo->query('SELECT 1 FROM crecer_img_cuota_asiento LIMIT 1');
    } catch (Throwable $e) {
        return ['ahora' => $ahora, 'base' => $base, 'resumen' => [], 'filas' => [],
                'cubos' => [], 'huecos' => ['falta la tabla crecer_img_cuota_asiento']];
    }

    // ── RESUMEN POR ESTADO ────────────────────────────────────────────────
    $resumen = ['por_estado' => [], 'vivos' => 0, 'unidades_vivas' => 0,
                'origen_cero' => ['total' => 0, 'vivos' => 0, 'cerrados' => 0,
                                  'antes' => 0, 'despues' => 0]];
    $w = $solo_marca !== null ? ' WHERE marca_id = ' . $solo_marca : '';
    foreach ($pdo->query("SELECT estado, COUNT(*) n, COALESCE(SUM(unidades),0) u
                            FROM crecer_img_cuota_asiento{$w} GROUP BY estado") as $r) {
        $resumen['por_estado'][(string)$r['estado']] = ['asientos' => (int)$r['n'], 'unidades' => (int)$r['u']];
    }
    foreach (cuota_hist_estados_vivos() as $e) {
        $resumen['vivos']          += (int)($resumen['por_estado'][$e]['asientos'] ?? 0);
        $resumen['unidades_vivas'] += (int)($resumen['por_estado'][$e]['unidades'] ?? 0);
    }

    //  Edades de los vivos.
    $vivos_sql = "estado IN ('" . implode("','", cuota_hist_estados_vivos()) . "')"
               . ($solo_marca !== null ? " AND marca_id = {$solo_marca}" : '');
    $e = $pdo->query("SELECT MIN(TIMESTAMPDIFF(MINUTE, created_at, NOW())) mn,
                             MAX(TIMESTAMPDIFF(MINUTE, created_at, NOW())) mx,
                             ROUND(AVG(TIMESTAMPDIFF(MINUTE, created_at, NOW()))) av
                        FROM crecer_img_cuota_asiento WHERE {$vivos_sql}")->fetch(PDO::FETCH_ASSOC);
    $resumen['edad_min_min'] = $e['mn'] === null ? null : (int)$e['mn'];
    $resumen['edad_max_min'] = $e['mx'] === null ? null : (int)$e['mx'];
    $resumen['edad_med_min'] = $e['av'] === null ? null : (int)$e['av'];

    //  Los de origen 0, separados como pide el informe.
    $q0 = $pdo->query("SELECT estado, created_at FROM crecer_img_cuota_asiento
                        WHERE operacion IN ('arte_post','realce','slide')
                          AND (origen_id IS NULL OR origen_id = 0)"
                      . ($solo_marca !== null ? " AND marca_id = {$solo_marca}" : ''));
    foreach ($q0 as $r) {
        $resumen['origen_cero']['total']++;
        in_array((string)$r['estado'], cuota_hist_estados_vivos(), true)
            ? $resumen['origen_cero']['vivos']++ : $resumen['origen_cero']['cerrados']++;
        strtotime((string)$r['created_at']) >= strtotime(CUOTA_HIST_ARREGLO)
            ? $resumen['origen_cero']['despues']++ : $resumen['origen_cero']['antes']++;
    }

    // ── LOS VIVOS, UNO A UNO ──────────────────────────────────────────────
    $q = $pdo->query("SELECT * FROM crecer_img_cuota_asiento
                       WHERE {$vivos_sql} ORDER BY created_at ASC LIMIT {$tope}");
    $asientos = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];

    //  Los gemelos: cuantos vivos comparten llave canonica. Una sola consulta.
    $gemelos = [];
    foreach ($pdo->query("SELECT idem, COUNT(*) n FROM crecer_img_cuota_asiento
                           WHERE {$vivos_sql} GROUP BY idem HAVING n > 1") as $r) {
        $gemelos[(string)$r['idem']] = (int)$r['n'];
    }

    //  La evidencia de las piezas y los slides, en dos consultas y no en 57.
    $ids_pieza = []; $ids_slide = [];
    foreach ($asientos as $a) {
        $oid = (int)($a['origen_id'] ?? 0); if ($oid <= 0) continue;
        if ((string)$a['origen_tipo'] === 'slide') $ids_slide[$oid] = true;
        else                                        $ids_pieza[$oid] = true;
    }
    $piezas = [];
    if ($ids_pieza) {
        $in = implode(',', array_map('intval', array_keys($ids_pieza)));
        $cols = 'id, marca_id, estado, grafica_path, tactica_id, plan_id';
        foreach (['img_estado', 'img_job', 'img_error_clase', 'img_job_at', 'img_next_poll_at'] as $c) {
            try { $pdo->query("SELECT {$c} FROM crecer_contenido LIMIT 1"); $cols .= ", {$c}"; }
            catch (Throwable $ex) { $huecos[] = "crecer_contenido.{$c} no existe en esta base"; }
        }
        foreach ($pdo->query("SELECT {$cols} FROM crecer_contenido WHERE id IN ({$in})") as $r)
            $piezas[(int)$r['id']] = $r;
    }
    $slides = [];
    if ($ids_slide) {
        $in = implode(',', array_map('intval', array_keys($ids_slide)));
        try {
            foreach ($pdo->query("SELECT s.id, s.contenido_id, s.grafica_path, s.img_estado,
                                         c.estado AS contenido_estado, c.marca_id
                                    FROM crecer_carrusel s
                               LEFT JOIN crecer_contenido c ON c.id = s.contenido_id
                                   WHERE s.id IN ({$in})") as $r) $slides[(int)$r['id']] = $r;
        } catch (Throwable $ex) { $huecos[] = 'crecer_carrusel no se pudo leer en esta base'; }
    }

    $filas = [];
    foreach ($asientos as $a) {
        $oid  = (int)($a['origen_id'] ?? 0);
        $es_s = (string)($a['origen_tipo'] ?? '') === 'slide';
        $ev = [
            'ahora'   => $ahora,
            'pieza'   => (!$es_s && $oid > 0) ? ($piezas[$oid] ?? null) : null,
            'slide'   => ($es_s && $oid > 0)  ? ($slides[$oid] ?? null) : null,
            'gemelos' => $gemelos[(string)($a['idem'] ?? '')] ?? 1,
        ];
        //  Un slide cuelga de su pieza: se lleva su estado para poder decirlo.
        $c = cuota_hist_clasificar($a, $ev);
        $filas[] = ['asiento' => $a, 'ev' => $ev] + $c;
    }

    // ── EL CUBO, POR MARCA ────────────────────────────────────────────────
    $cubos = [];
    try {
        $wc = $solo_marca !== null ? " WHERE marca_id = {$solo_marca}" : '';
        foreach ($pdo->query("SELECT marca_id, cubo, limite, usadas
                                FROM crecer_img_cuota_cubo{$wc}") as $r) {
            $k = $r['marca_id'] . '|' . $r['cubo'];
            $cubos[$k] = ['marca_id' => (int)$r['marca_id'], 'cubo' => (string)$r['cubo'],
                          'limite' => (int)$r['limite'], 'usadas' => (int)$r['usadas'],
                          'confirmadas' => 0, 'vivas' => 0, 'liberadas' => 0,
                          'bajarian' => 0, 'quedarian' => 0];
        }
        foreach ($pdo->query("SELECT marca_id, cubo, estado, COALESCE(SUM(unidades),0) u
                                FROM crecer_img_cuota_asiento"
                             . ($solo_marca !== null ? " WHERE marca_id = {$solo_marca}" : '')
                             . " GROUP BY marca_id, cubo, estado") as $r) {
            $k = $r['marca_id'] . '|' . $r['cubo'];
            if (!isset($cubos[$k])) continue;
            if ((string)$r['estado'] === 'confirmado')     $cubos[$k]['confirmadas'] += (int)$r['u'];
            elseif ((string)$r['estado'] === 'liberado')   $cubos[$k]['liberadas']   += (int)$r['u'];
            elseif (in_array((string)$r['estado'], cuota_hist_estados_vivos(), true))
                                                          $cubos[$k]['vivas']       += (int)$r['u'];
        }
    } catch (Throwable $ex) { $huecos[] = 'crecer_img_cuota_cubo no se pudo leer'; }

    //  EL IMPACTO HIPOTETICO. Cuanto bajaria el cubo si SOLO se soltaran los
    //  casos seguros de devolucion, y cuanto quedaria firme al confirmar los
    //  entregados. No se escribe nada: es una resta.
    foreach ($filas as $f) {
        $k = ($f['asiento']['marca_id'] ?? 0) . '|' . ($f['asiento']['cubo'] ?? '');
        if (!isset($cubos[$k])) continue;
        $u = (int)($f['asiento']['unidades'] ?? 0);
        if (in_array($f['clase'], ['fallo_terminal_sin_entrega', 'caducada_sin_job'], true)
            && $f['nivel'] === 'automático seguro') {
            $cubos[$k]['bajarian'] += $u;
        } elseif ($f['clase'] === 'entregada_sin_confirmar') {
            $cubos[$k]['quedarian'] += $u;
        }
    }
    foreach ($cubos as $k => $c) {
        $cubos[$k]['diferencia']   = $c['usadas'] - ($c['confirmadas'] + $c['vivas']);
        $cubos[$k]['usadas_despues'] = max(0, $c['usadas'] - $c['bajarian']);
    }

    return ['ahora' => $ahora, 'base' => $base, 'resumen' => $resumen,
            'filas' => $filas, 'cubos' => $cubos, 'huecos' => $huecos];
}

/** El recuento por clase, para la tabla del informe. */
function cuota_hist_por_clase(array $filas): array
{
    $t = [];
    foreach ($filas as $f) {
        $c = (string)$f['clase'];
        if (!isset($t[$c])) $t[$c] = ['asientos' => 0, 'unidades' => 0,
                                      'accion' => $f['accion'], 'nivel' => $f['nivel']];
        $t[$c]['asientos']++;
        $t[$c]['unidades'] += (int)($f['asiento']['unidades'] ?? 0);
    }
    ksort($t);
    return $t;
}
