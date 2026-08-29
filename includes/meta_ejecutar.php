<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — LA JUGADA SE EJECUTA
//  includes/meta_ejecutar.php
//
//  "Si vendemos automatización, hay que dar automatización." (Manuel, 2026-08-12)
//
//  Antes: la Estratega dejaba jugadas escritas y el dueño tenía que hacerlas
//  Y ADEMÁS marcar un checkbox declarando que las hizo. Dos trabajos que
//  nosotros le estamos cobrando por quitarle.
//
//  Ahora una jugada de producción se toca y EL CORILLO LA HACE ENTERA:
//    1. INVENTARIO — antes de gastar, mira lo que YA existe:
//       · piezas listas en la gaveta que nadie publicó,
//       · fotos reales del negocio en su Biblioteca,
//       · posts pasados que el Optimizador midió como ganadores.
//    2. IDEAS — ángulos DISTINTOS entre sí para las piezas que falten.
//    3. PRODUCE — escribe, hace el arte, programa y amarra cada pieza a la
//       jugada, al plan y a la meta.
//
//  Y NADIE MARCA NADA: la jugada se cierra sola cuando sus piezas están
//  publicadas de verdad (evidencia real, no declaración). Lo único que el
//  dueño confirma es lo que ocurre FUERA de Crecer: el boost, la llamada al
//  vecino, la foto que solo él puede tomar.
// ============================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/meta_negocio.php';
//  EL DOMINIO DEL MATERIAL, ARRIBA Y A LA VISTA. Estaba incluido dentro
//  de los handlers, justo antes de cada llamada, y basto que UNO se
//  quedara sin su require para que la entrega de arte muriera con un
//  fatal en la ruta que mas se usa. Cargarlo aqui quita la clase entera
//  de fallo: no depende de que rama se ejecute ni de que otra pagina lo
//  haya cargado antes.
require_once __DIR__ . '/material.php';

/** Una jugada por id, verificando dueño. */
function jugada_por_id(PDO $pdo, int $tactica_id, int $marca_id): ?array {
    try {
        $q = $pdo->prepare("SELECT * FROM crecer_meta_tactica WHERE id=? AND marca_id=? LIMIT 1");
        $q->execute([$tactica_id, $marca_id]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { return null; }
}

/**
 * EL PROGRESO REAL DE UNA JUGADA — no lo que alguien declaró, lo que existe.
 * @return array{meta:int, creadas:int, publicadas:int, listas:int, pct:int, cumplida:bool}
 */
function jugada_progreso(PDO $pdo, array $t): array {
    $meta = (int)($t['piezas_meta'] ?? 0);
    $out = ['meta'=>$meta, 'creadas'=>0, 'publicadas'=>0, 'listas'=>0, 'espera_video'=>0, 'pct'=>0, 'cumplida'=>false];
    try {
        $q = $pdo->prepare("SELECT estado, COUNT(*) c FROM crecer_contenido WHERE tactica_id=? GROUP BY estado");
        $q->execute([(int)$t['id']]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $c = (int)$r['c'];
            $out['creadas'] += $c;
            if ($r['estado'] === 'publicado') $out['publicadas'] += $c;
            elseif (in_array($r['estado'], ['aprobado','programado'], true)) $out['listas'] += $c;
        }
    } catch (Throwable $e) {}
    // Piezas trancadas esperando algo que solo el dueño puede dar (sus clips).
    // Se guarda el id de la primera para poder llevarlo DIRECTO a grabar esa
    // pieza (con su guion delante), en vez de soltarlo en el Estudio de Reels.
    $out['espera_video_id'] = 0;
    try {
        $q2 = $pdo->prepare("SELECT id FROM crecer_contenido
                              WHERE tactica_id=? AND necesita_material IS NOT NULL AND estado<>'publicado'
                              ORDER BY id ASC");
        $q2->execute([(int)$t['id']]);
        $pend = $q2->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $out['espera_video']    = count($pend);
        $out['espera_video_id'] = $pend ? (int)$pend[0] : 0;
    } catch (Throwable $e) { /* sin la migración de material: se ignora */ }
    if ($meta > 0) $out['pct'] = min(100, (int)round($out['publicadas'] / $meta * 100));
    $out['cumplida'] = $meta > 0 && $out['publicadas'] >= $meta;
    return $out;
}

/**
 * CIERRE AUTOMÁTICO. Si las piezas de la jugada ya se publicaron, la jugada
 * se da por hecha SOLA. El dueño nunca tuvo que decirlo.
 * Las de clase 'regla' no cierran nunca (no son tareas) y las 'accion_dueno'
 * solo las cierra él.
 * @return bool  true si acaba de cerrarse en esta llamada
 */
function jugada_sincronizar(PDO $pdo, array $t): bool {
    if (($t['clase'] ?? 'produccion') !== 'produccion') return false;
    if (($t['estado'] ?? '') !== 'pendiente' && ($t['estado'] ?? '') !== 'en_curso') return false;
    $p = jugada_progreso($pdo, $t);
    if (!$p['cumplida']) {
        // Con trabajo empezado pero sin publicar todo: queda 'en_curso'.
        if ($p['creadas'] > 0 && ($t['estado'] ?? '') === 'pendiente') {
            try { $pdo->prepare("UPDATE crecer_meta_tactica SET estado='en_curso', updated_at=NOW() WHERE id=?")
                      ->execute([(int)$t['id']]); } catch (Throwable $e) {}
        }
        return false;
    }
    try {
        $pdo->prepare("UPDATE crecer_meta_tactica SET estado='hecha', updated_at=NOW() WHERE id=?")->execute([(int)$t['id']]);
        return true;
    } catch (Throwable $e) { return false; }
}

/** Sincroniza TODAS las jugadas de una marca (lo corre el relevo y la pantalla). */
function jugadas_sincronizar_marca(PDO $pdo, int $marca_id): int {
    $n = 0;
    try {
        $q = $pdo->prepare("SELECT * FROM crecer_meta_tactica WHERE marca_id=? AND estado IN ('pendiente','en_curso')");
        $q->execute([$marca_id]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $t) { if (jugada_sincronizar($pdo, $t)) $n++; }
    } catch (Throwable $e) {}
    return $n;
}

/**
 * LA FECHA QUE PROPONE LA IA — una sola fuente, para todo el producto.
 *
 * La usan la jugada al producir y el wizard al calendarizar, para que no se
 * contradigan: si el negocio va atrasado, la pieza sale HOY (o mañana temprano
 * si la hora buena ya pasó), no el sábado. Nunca después de la fecha límite.
 *
 * @return array{fecha:string, porque:string, apura:bool}
 */
/**
 * EL SIGUIENTE HUECO LIBRE a partir de una fecha, mirando el calendario REAL.
 *
 * QUE CUENTA COMO CHOQUE: otra publicacion de la misma marca —suya o del
 * plan, da igual— dentro de las mismas 4 horas. No se mira solo la hora
 * exacta: dos posts con veinte minutos de diferencia compiten entre ellos, y
 * el segundo se lo come el primero.
 *
 * LO QUE YA ESTA PROGRAMADO NO SE TOCA. Mover contenido que el dueño puso —o
 * que ya aprobo— sin preguntarle seria decidir por el. Se corre lo nuevo.
 *
 * @throws Throwable si no se puede leer el calendario. A proposito: quien
 *         llama tiene que poder distinguir «no habia choque» de «no pude
 *         mirar», porque solo en el primer caso se le puede decir al dueño
 *         que le dejamos espacio.
 */
function meta_fecha_libre(PDO $pdo, int $marca_id, string $fecha, string $limite = '',
                          int $horas = 4, int $intentos = 14): string
{
    $q = $pdo->prepare(
        "SELECT fecha_programada FROM crecer_contenido
          WHERE marca_id=? AND estado NOT IN ('rechazado','fallido')
            AND fecha_programada IS NOT NULL
            AND fecha_programada BETWEEN ? AND ?");
    $q->execute([$marca_id,
                 date('Y-m-d H:i:s', strtotime($fecha . ' -1 day')),
                 date('Y-m-d H:i:s', strtotime($fecha . ' +' . ($intentos + 2) . ' day'))]);
    $ocupadas = [];
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $f) {
        $ts = strtotime((string)$f);
        if ($ts) $ocupadas[] = $ts;
    }
    if (!$ocupadas) return $fecha;

    $margen = max(1, $horas) * 3600;
    $cand   = strtotime($fecha);
    $tope   = $limite !== '' ? strtotime($limite . ' 23:59:59') : null;
    for ($i = 0; $i <= $intentos; $i++) {
        $choca = false;
        foreach ($ocupadas as $o) { if (abs($o - $cand) < $margen) { $choca = true; break; } }
        if (!$choca) {
            //  Nunca despues de su fecha limite: mejor apretado que tarde.
            if ($tope !== null && $cand > $tope) return $fecha;
            return date('Y-m-d H:i:s', $cand);
        }
        $cand = strtotime('+1 day', $cand);
    }
    //  Catorce dias seguidos ocupados es una agenda llena de verdad: se
    //  devuelve la fecha pedida y que el dueño decida. Inventar un hueco
    //  donde no lo hay seria peor.
    return $fecha;
}

function meta_fecha_sugerida(PDO $pdo, int $marca_id, int $orden = 1, int $semana = 1): array {
    //  10:00 ES EL ARRANQUE, NO «LA HORA QUE MEJOR TE FUNCIONA». Se guarda si
    //  el Optimizador la respaldo o no, porque el texto de abajo lo afirmaba
    //  siempre — tambien con un negocio recien nacido que no ha publicado
    //  nada y del que no hay un solo dato.
    $hora = 10;
    $respaldada = false;
    try {
        require_once __DIR__ . '/optimizador.php';
        $mom = optimizador_mejor_momento($pdo, $marca_id);
        if (($mom['hora'] ?? null) !== null) { $hora = (int)$mom['hora']; $respaldada = true; }
    } catch (Throwable $e) {}

    $meta = meta_activa($pdo, $marca_id);
    $prog = null;
    if ($meta) { try { $prog = meta_progreso($pdo, $meta); } catch (Throwable $e) {} }
    $apura = $prog && ($prog['al_dia'] === false
                       || ($prog['dias_rest'] !== null && $prog['dias_rest'] <= 7));

    $sem      = max(1, $semana);
    $arranque = $apura ? min(($sem - 1) * 2, 3) : ($sem - 1) * 7;
    $paso     = $apura ? 1 : 2;
    $hoy_cabe = ((int)date('G') + 1) <= $hora;
    $limite   = !empty($meta['fecha_limite']) ? (string)$meta['fecha_limite'] : '';

    // Si hoy ya se hizo tarde, TODO se corre un dia: si solo se moviera la
    // primera pieza, se pegaria con la segunda y se perderia la separacion.
    $tarde = ($arranque === 0 && !$hoy_cabe);
    $d = $arranque + (max(1, $orden) - 1) * $paso + ($tarde ? 1 : 0);
    $fecha = date('Y-m-d', strtotime('+' . $d . ' day')) . ' ' . sprintf('%02d', $hora) . ':00:00';
    $topado = false;
    if ($limite !== '' && substr($fecha, 0, 10) > $limite) {
        $fecha = $limite . ' ' . sprintf('%02d', $hora) . ':00:00';
        $topado = true;
    }

    //  ── QUE NO CHOQUE CON LO QUE YA TIENE ───────────────────────────────
    //  Hasta aqui la fecha salia de una cuenta —semana, orden, hora buena— sin
    //  mirar el calendario. Dos piezas del plan podian caer sobre el post que
    //  el dueño ya habia programado a mano para esa hora, y el que quedaba mal
    //  con su gente era el.
    //
    //  NO SE MUEVE NADA SUYO: se busca el siguiente hueco. Y si el calendario
    //  no se puede leer, no se afirma que este coordinada — se devuelve la
    //  fecha calculada y `coordinada` en false.
    $coordinada = false; $movida = false;
    try {
        $libre = meta_fecha_libre($pdo, $marca_id, $fecha, $limite);
        $coordinada = true;
        if ($libre !== $fecha) { $fecha = $libre; $movida = true; }
    } catch (Throwable $e) { $coordinada = false; }

    if ($movida)      $porque = 'La corrí un poco: a esa hora ya tenías algo programado.';
    elseif ($topado)  $porque = 'Es lo más tarde que puede salir sin pasarse de tu fecha límite.';
    elseif ($apura && $d === 0 && !$tarde) $porque = 'Vas atrasado para tu meta: esto sale HOY, a la hora que mejor te funciona.';
    elseif ($apura && $tarde)   $porque = 'Vas atrasado, pero hoy ya se hizo tarde: sale mañana a primera hora buena.';
    elseif ($apura)   $porque = 'Vas atrasado para tu meta: las piezas salen un día tras otro.';
    else              $porque = $respaldada
                              ? 'Vas en ritmo: esta es la hora que mejor te ha funcionado.'
                              : 'Vas en ritmo: esta hora es para arrancar, y la ajustamos con tus resultados.';

    return ['fecha' => $fecha, 'porque' => $porque, 'apura' => (bool)$apura, 'hora' => $hora,
            'coordinada' => $coordinada, 'movida' => $movida, 'respaldada' => $respaldada];
}

/** "hoy 10:00 AM" · "mañana 10:00 AM" · "el sábado 10:00 AM" — como lo diría una persona. */
function fecha_humana_es(string $fecha): string {
    $ts = strtotime($fecha);
    if (!$ts) return $fecha;
    $dias = ['Sun'=>'domingo','Mon'=>'lunes','Tue'=>'martes','Wed'=>'miércoles',
             'Thu'=>'jueves','Fri'=>'viernes','Sat'=>'sábado'];
    $dia = date('Y-m-d', $ts);
    if ($dia === date('Y-m-d'))                        $cual = 'hoy';
    elseif ($dia === date('Y-m-d', strtotime('+1 day'))) $cual = 'mañana';
    elseif ($ts < strtotime('+7 days'))                $cual = 'el ' . ($dias[date('D', $ts)] ?? '');
    else                                               $cual = 'el ' . date('j/n', $ts);
    return trim($cual . ' a las ' . date('g:i A', $ts));
}

/**
 *  ¿HAY PRUEBA DE QUE EL DUEÑO ESCOGIO ESTA HORA?
 *
 *  HOY NO, PARA NINGUNA PIEZA, Y ESE ES EL PUNTO. Se buscó y no existe:
 *
 *    · `accion=fecha` (panel/aprobar2.php) escribe `fecha_programada` y
 *      `updated_at`, y nada más. `updated_at` se mueve por el arte, el caption
 *      y el estado, así que no distingue una edición de hora de ninguna otra.
 *    · `calendario_id` NO es esa prueba. Es el id del MES en
 *      `crecer_calendario`, la huella de haber nacido por la ruta manual — y
 *      en esa ruta la hora la estampa el servidor con `date('Y-m-d H:i:s')`.
 *      El dueño escribió un caption; la hora se la pusimos nosotros.
 *    · `audit_log` solo recibe eco de ajustes de meta y sustituciones de
 *      jugada (`meta_eco_seguridad`), nunca de la fecha de una pieza.
 *    · `crecer_meta_cambio` es la historia de la META, no de las piezas.
 *
 *  Así que esto devuelve false SIEMPRE, y no es un descuido: es el sitio
 *  exacto donde enchufar la prueba el día que exista. Haría falta una columna
 *  —quién tocó la hora y cuándo— y este tramo no toca el esquema. Mientras
 *  tanto, «Elegiste esta hora» no se dice, porque no consta.
 */
function hora_evidencia_dueno(PDO $pdo, array $pieza): bool
{
    return false;
}

/**
 *  LA MEJOR FRANJA, PERO SOLO SI HAY CON QUE SOSTENERLA.
 *
 *  `optimizador_mejor_momento()` no sirve para afirmar nada delante del dueño:
 *  contesta también desde una lección GUARDADA en `crecer_memoria`, que puede
 *  ser vieja o haber entrado a mano, y no trae cuántos posts la respaldan.
 *
 *  El análisis en vivo sí trae su cobertura, y la trae exigida: no devuelve
 *  ninguna lección con menos de OPT_MIN_POSTS publicaciones medidas, ni un
 *  patrón con menos de OPT_MIN_BUCKET dentro, ni una diferencia por debajo de
 *  OPT_MIN_DELTA. Si no hay lección, no hay cobertura, y no se dice nada.
 *
 *  Se memoriza por marca: la misma pantalla clasifica varias piezas seguidas y
 *  esto son cuentas sobre los mismos posts, no un dato que cambie entre una y
 *  otra.
 *
 *  @return ?int la hora (0-23) respaldada, o null si no hay cobertura
 */
function hora_mejor_con_cobertura(PDO $pdo, int $marca_id): ?int
{
    static $cache = [];
    if (array_key_exists($marca_id, $cache)) return $cache[$marca_id];
    $h = null;
    try {
        require_once __DIR__ . '/optimizador.php';
        foreach (optimizador_analizar($pdo, $marca_id) as $l) {
            if (($l['clave'] ?? '') === 'mejor_franja' && isset($l['hora'])) $h = (int)$l['hora'];
        }
    } catch (Throwable $e) { $h = null; }
    return $cache[$marca_id] = $h;
}

/**
 *  DE DONDE SALIO LA HORA DE UNA PIEZA — con lo que la base puede DEMOSTRAR.
 *
 *  EL DEFECTO QUE CIERRA. El Estudio acreditaba «La Estratega escogió la hora»
 *  mirando `$ags`, la lista de agentes que trabajaron para la MARCA en las
 *  últimas doce llamadas. No dice nada de ESA pieza: con que la Estratega
 *  hubiera hecho cualquier cosa para el negocio, cualquier pieza con fecha
 *  quedaba acreditada a ella.
 *
 *  Y LA PRIMERA CORRECCION SE PASO DE LISTA en sentido contrario: daba por
 *  «la escogió el dueño» toda pieza con `calendario_id`. Eso identifica el
 *  ORIGEN de la pieza, no quién puso la hora — y en esa misma ruta la hora la
 *  estampa el servidor. Inferir no es demostrar.
 *
 *  LOS CINCO CASOS, y qué hace falta para cada uno:
 *
 *    sin_hora   `fecha_programada` vacía o a las 00:00. Una fecha sin hora se
 *               guarda a medianoche; enseñar «12:00 AM» es inventar una
 *               decisión que nadie tomó y prometer una publicación de
 *               madrugada. Se dice el día, que sí es cierto, y nada más.
 *
 *    dueno      SOLO con evidencia persistida de una acción suya sobre la
 *               hora. Hoy no existe ninguna —ver `hora_evidencia_dueno()`—,
 *               así que este caso no sale nunca. No se dice lo que no consta.
 *
 *    coincide   La hora coincide con la mejor franja del negocio Y esa franja
 *               tiene cobertura. Se afirma la COINCIDENCIA, que es comprobable
 *               hoy, y NO el motivo: nadie puede demostrar que se escogiera
 *               por eso.
 *
 *    arranque   La preparó el plan (`tactica_id`) y no hay esa cobertura. Es
 *               una hora de salida, y así se dice.
 *
 *    neutral    Todo lo demás. Se dice cuándo sale y no se firma: es cierto lo
 *               haya puesto quien lo haya puesto.
 *
 *  @return array{caso:string, frase:string, cuando:string, hora:?int}
 *          `frase` es la línea COMPLETA, ya redactada: quien la pinta no
 *          vuelve a componerla a su manera.
 */
function hora_atribucion(PDO $pdo, array $pieza): array
{
    $f  = trim((string)($pieza['fecha_programada'] ?? ''));
    $ts = ($f !== '' && $f !== '0000-00-00 00:00:00') ? strtotime($f) : false;
    if ($ts === false) {
        return ['caso' => 'sin_hora', 'frase' => '', 'cuando' => '', 'hora' => null];
    }
    $hora   = (int)date('G', $ts);
    $cuando = fecha_humana_es($f);

    //  SIN HORA · a medianoche no se programa nada a propósito.
    if (substr($f, 11, 5) === '00:00') {
        //  Se corta también el «a las»: sin hora, «el martes a las» es una
        //  frase partida por la mitad.
        $dia = trim((string)preg_replace('~\s*(a las)?\s*,?\s*\d{1,2}:\d{2}\s*[AP]M$~u', '', $cuando));
        return ['caso' => 'sin_hora', 'frase' => '', 'cuando' => $dia, 'hora' => null];
    }

    //  DEL DUEÑO · solo si consta. Hoy nunca.
    if (hora_evidencia_dueno($pdo, $pieza)) {
        return ['caso' => 'dueno', 'frase' => t('Elegiste esta hora.'),
                'cuando' => $cuando, 'hora' => $hora];
    }

    //  COINCIDE · el hecho comprobable, sin atribuirle la intención a nadie.
    $mejor = hora_mejor_con_cobertura($pdo, (int)($pieza['marca_id'] ?? 0));
    if ($mejor !== null && $mejor === $hora) {
        return ['caso' => 'coincide',
                'frase' => t('Se publicará %s. Coincide con una de tus mejores horas.', $cuando),
                'cuando' => $cuando, 'hora' => $hora];
    }

    //  ARRANQUE · la preparó el plan y todavía no hay con qué respaldarla.
    if ((int)($pieza['tactica_id'] ?? 0) > 0) {
        return ['caso' => 'arranque',
                'frase' => t('Se publicará %s. Te sugerimos esta hora para comenzar.', $cuando),
                'cuando' => $cuando, 'hora' => $hora];
    }

    //  NEUTRAL · el hecho, y nadie firma.
    return ['caso' => 'neutral', 'frase' => t('Se publicará %s.', $cuando),
            'cuando' => $cuando, 'hora' => $hora];
}


/**
 * LAS PUERTAS DE UNA JUGADA — una cosa a la vez, y cada una abre DONDE se hace.
 *
 * Antes la jugada solo ofrecía "Ver las N piezas", que soltaba al dueño en una
 * lista filtrada: el carrusel no se podía abrir como carrusel y al reel no se
 * llegaba nunca. Aquí cada pieza sabe su puerta:
 *   carrusel → carrusel.php?id=   ·   reel → reels.php?pieza=   ·   post → aprobar2.php?ver=
 * La primera sin cerrar es la ACTIVA; las demás esperan su turno.
 *
 * @return array<int,array{n:int,total:int,tipo:string,titulo:string,href:string,listo:bool,activa:bool,cuando:string}>
 */
function jugada_puertas(PDO $pdo, array $t, int $marca_id, string $BASE): array {
    $filas = [];
    try {
        $q = $pdo->prepare("SELECT id, tipo, estado, fecha_programada, necesita_material
                              FROM crecer_contenido
                             WHERE tactica_id=? AND marca_id=?
                             ORDER BY fecha_programada IS NULL, fecha_programada ASC, id ASC");
        $q->execute([(int)$t['id'], $marca_id]);
        $filas = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
    if (!$filas) return [];

    $verbo = ['carrusel'=>'Hagamos el carrusel', 'reel'=>'Grabemos el reel',
              'story'=>'Veamos la story',        'post'=>'Veamos el post'];
    $dias  = ['Sun'=>'domingo','Mon'=>'lunes','Tue'=>'martes','Wed'=>'miércoles',
              'Thu'=>'jueves','Fri'=>'viernes','Sat'=>'sábado'];
    $total = count($filas);
    $out = []; $n = 0; $activa_dada = false;
    foreach ($filas as $f) {
        $n++;
        $tipo  = (string)$f['tipo'];
        $cid   = (int)$f['id'];
        $listo = in_array((string)$f['estado'], ['aprobado','programado','publicado'], true);
        // El reel no está listo mientras el dueño no suba sus clips.
        if ($tipo === 'reel' && !empty($f['necesita_material']) && (string)$f['estado'] !== 'publicado') $listo = false;
        if ($tipo === 'carrusel')   $href = "{$BASE}/carrusel.php?marca={$marca_id}&id={$cid}";
        elseif ($tipo === 'reel')   $href = "{$BASE}/reels.php?marca={$marca_id}&pieza={$cid}";
        else                        $href = "{$BASE}/aprobar2.php?marca={$marca_id}&ver={$cid}";
        $cuando = '';
        if (!empty($f['fecha_programada'])) {
            $ts = strtotime((string)$f['fecha_programada']);
            if ($ts) {
                $hoy = date('Y-m-d'); $dia = date('Y-m-d', $ts);
                $cual = $dia === $hoy ? 'hoy'
                      : ($dia === date('Y-m-d', strtotime('+1 day')) ? 'mañana'
                      : ($dias[date('D', $ts)] ?? date('d/m', $ts)));
                $cuando = $cual . ' ' . date('g:i A', $ts);
            }
        }
        $activa = false;
        if (!$listo && !$activa_dada) { $activa = true; $activa_dada = true; }
        $out[] = ['n'=>$n, 'total'=>$total, 'id'=>$cid, 'tipo'=>$tipo,
                  'titulo'=>$verbo[$tipo] ?? 'Veamos la pieza', 'href'=>$href,
                  'listo'=>$listo, 'activa'=>$activa, 'cuando'=>$cuando,
                  'estado'=>(string)$f['estado']];
    }
    return $out;
}

// ── EL INVENTARIO: mirar antes de gastar ─────────────────────
/**
 * ¿Con qué contamos YA para esta jugada? Devuelve material reusable:
 *  · 'gaveta'    piezas que el corillo dejó listas y nadie publicó (sin jugada asignada)
 *  · 'fotos'     fotos reales del negocio en la Biblioteca que no se han usado hace poco
 *  · 'ganadores' posts pasados que midieron mejor (para copiarles el ángulo, no el texto)
 *
 * Lo real siempre gana: una foto del negocio vale más que cualquier arte
 * generado, y no consume cuota de imágenes.
 */
function jugada_inventario(PDO $pdo, int $marca_id, array $t, int $faltan): array {
    $out = ['gaveta' => [], 'fotos' => [], 'ganadores' => []];

    // 1. La gaveta: borradores hechos por la IA, sin publicar y sin jugada.
    //
    //    OJO — la gaveta SOLO aporta piezas DEL FORMATO QUE LA JUGADA PIDE.
    //    Sin este filtro, una cuenta con borradores viejos se comía el plan
    //    entero: la jugada decía "1 carrusel y 1 reel", la gaveta le metía
    //    tres posts viejos, la jugada se daba por servida y el carrusel y el
    //    reel nunca se producían. Reusar es bueno; reusar cualquier cosa es
    //    exactamente el humo del que se queja el dueño.
    $formato = (string)($t['formato'] ?? 'post');
    $tipos = ['post'=>['post'], 'carrusel'=>['carrusel'], 'reel'=>['reel'],
              'historia'=>['story','historia'], 'mixto'=>['post','carrusel','reel','story']];
    $permitidos = $tipos[$formato] ?? ['post'];
    try {
        $marcas = implode(',', array_fill(0, count($permitidos), '?'));
        $q = $pdo->prepare(
            "SELECT id, caption, grafica_path, plataforma, tipo
               FROM crecer_contenido
              WHERE marca_id=? AND estado='borrador' AND tactica_id IS NULL
                AND caption IS NOT NULL AND CHAR_LENGTH(caption) > 40
                AND tipo IN ($marcas)
              ORDER BY id DESC LIMIT " . max(1, (int)$faltan));
        $q->execute(array_merge([$marca_id], $permitidos));
        $out['gaveta'] = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    // 2. Fotos reales del negocio (Biblioteca). Se excluyen las usadas hace
    //    poco: la huella visual guarda cuál se usó (lente 'foto:<id>').
    try {
        $usadas = [];
        try {
            $qu = $pdo->prepare("SELECT lente FROM crecer_visual_huella WHERE marca_id=? AND lente LIKE 'foto:%' ORDER BY id DESC LIMIT 12");
            $qu->execute([$marca_id]);
            foreach ($qu->fetchAll(PDO::FETCH_COLUMN) as $l) $usadas[] = (int)substr((string)$l, 5);
        } catch (Throwable $e) {}
        $sql = "SELECT id, archivo, nombre, nota FROM crecer_activos
                 WHERE marca_id=? AND estado='activo' AND tipo IN ('imagen','foto')";
        if ($usadas) $sql .= " AND id NOT IN (" . implode(',', array_map('intval', $usadas)) . ")";
        $sql .= " ORDER BY id DESC LIMIT 6";
        $q = $pdo->prepare($sql);
        $q->execute([$marca_id]);
        $out['fotos'] = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    // 3. Los que ya funcionaron (para el ángulo, no para copiar el texto).
    try {
        $q = $pdo->prepare(
            "SELECT c.caption, mt.alcance, mt.interacciones
               FROM crecer_contenido c JOIN crecer_metricas mt ON mt.contenido_id=c.id
              WHERE c.marca_id=? AND c.estado='publicado' AND mt.alcance IS NOT NULL
              ORDER BY mt.alcance DESC LIMIT 3");
        $q->execute([$marca_id]);
        $out['ganadores'] = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    return $out;
}

/**
 * EL GUION DEL REEL — lo único honesto que el corillo puede producir solo
 * cuando la jugada pide video.
 *
 * Crecer SÍ monta reels (Gemini lee los clips y Shotstack los arma), pero no
 * inventa video: el material crudo solo lo puede grabar el dueño. Antes el
 * motor resolvía esto generando una IMAGEN y llamándola "reel" — mentira que
 * el dueño descubría al ir a publicar.
 *
 * Ahora el corillo hace su parte: escribe QUÉ grabar, clip por clip, en
 * lenguaje de alguien que va a usar el celular con una mano.
 *
 * @return string el guion (o '' si falló)
 */
function jugada_guion_reel(PDO $pdo, int $marca_id, array $t, string $idea): string {
    require_once __DIR__ . '/agentes.php';
    require_once __DIR__ . '/ia.php';
    try {
        $m   = leer_marca($pdo, $marca_id);
        $ctx = cerebro_negocio($pdo, $marca_id, $m);
        $sistema = "Eres el GUIONISTA de reels de Crecer. El dueño de un micronegocio boricua va a grabar con su "
            . "CELULAR, con una mano, entre clientes. Escríbele qué grabar en 3 o 4 clips CORTOS (4-6 segundos "
            . "cada uno), en orden.\n"
            . "REGLAS:\n"
            . "- Cada clip: qué se ve y cómo sostener el teléfono. Concreto y filmable HOY, con lo que ya tiene "
            . "en su negocio. Nada de drones, trípodes, iluminación ni actores.\n"
            . "- Nada de jerga de cine (ni 'plano detalle', ni 'travelling'): dilo como se lo dirías a un amigo.\n"
            . "- Empieza por el clip que engancha en el primer segundo.\n"
            . "- Máximo 90 palabras en total.\n"
            . "Devuelve SOLO el guion, en líneas que empiecen con 'Clip 1:', 'Clip 2:'...";
        $prompt = "Negocio:\n{$ctx}\n\nLa jugada: {$t['titulo']} — {$t['que_hacer']}\n"
                . "La idea de este reel: {$idea}\n"
                . (trim((string)$t['cta']) !== '' ? "Al final se le pide a la gente: {$t['cta']}\n" : '')
                . (($__mp = meta_para_prompt($pdo, $marca_id)) !== '' ? "\n{$__mp}\n" : '')
                . "\nEscribe el guion de los clips.";
        $r = ia_ejecutar($pdo, 'guionista', 'Guion de reel para la jugada', $prompt, [
            'marca_id' => $marca_id, 'sistema' => $sistema,
            'temperatura' => 0.85, 'max_tokens' => 320, 'thinking_budget' => 0,
            'mock_texto' => "Clip 1: El horno abriéndose con el bizcocho adentro.\nClip 2: Tus manos sacándolo y el vapor subiendo.\nClip 3: Un pedazo cortado, bien de cerca.",
        ]);
        return trim((string)($r['texto'] ?? ''));
    } catch (Throwable $e) { error_log('jugada_guion_reel: ' . $e->getMessage()); return ''; }
}

/**
 * LAS IDEAS DE LA JUGADA — N ángulos DISTINTOS entre sí para cumplirla.
 * No es "planificar el mes": es desmenuzar ESTA jugada en piezas concretas.
 */
function jugada_ideas(PDO $pdo, int $marca_id, array $t, int $n, array $inv): array {
    require_once __DIR__ . '/agentes.php';
    require_once __DIR__ . '/ia.php';
    $m   = leer_marca($pdo, $marca_id);
    $ctx = cerebro_negocio($pdo, $marca_id, $m);

    $ganadores = '';
    if (!empty($inv['ganadores'])) {
        $ganadores = "LO QUE YA LE FUNCIONÓ A ESTE NEGOCIO (fíjate en el ÁNGULO que usaron, no copies el texto):\n";
        foreach ($inv['ganadores'] as $g) {
            $ganadores .= '- "' . mb_substr(trim((string)$g['caption']), 0, 90) . '…" → ' . (int)$g['alcance'] . " personas\n";
        }
    }
    $fotos = '';
    if (!empty($inv['fotos'])) {
        $lista = [];
        foreach ($inv['fotos'] as $f) {
            $et = trim((string)($f['nombre'] ?? '')) ?: 'foto del negocio';
            if (trim((string)($f['nota'] ?? '')) !== '') $et .= ' (' . trim((string)$f['nota']) . ')';
            $lista[] = $et;
        }
        $fotos = "FOTOS REALES QUE EL DUEÑO YA SUBIÓ (úsalas: lo real gana siempre):\n- " . implode("\n- ", $lista) . "\n";
    }

    $sistema = "Eres el ESTRATEGA de contenido de Crecer. Recibes UNA jugada del plan de marketing y la "
        . "desmenuzas en {$n} pieza(s) CONCRETA(S) y DISTINTAS entre sí — distinto ángulo, distinto gancho, "
        . "distinto momento. Nada de la misma idea repetida con otras palabras.\n"
        . "Cada idea es de ESTE negocio y sirve para cumplir ESA jugada, no otra cosa.\n"
        // El contrato de formatos: SOLO lo que el motor sabe producir de verdad.
        // Si aquí se abre un formato, abajo tiene que existir quien lo haga.
        . "Responde SOLO JSON válido: {\"piezas\":[{\"plataforma\":\"instagram|facebook\",\"tipo\":\"post|carrusel|reel|story\","
        . "\"tema\":\"2-4 palabras\",\"idea\":\"1-2 oraciones: qué se ve y por qué engancha\",\"usa_foto\":true|false}]}\n"
        . "- `usa_foto`: true si esta pieza debería ir con una FOTO REAL del negocio (si te di fotos disponibles).\n"
        . "QUÉ ES CADA FORMATO (respeta el que pide la jugada):\n"
        . "- post: una imagen y su texto.\n"
        . "- carrusel: UNA historia en 5 slides que la gente desliza (el corillo la escribe y hace las imágenes).\n"
        . "- reel: video. El dueño lo graba con su celular; nosotros escribimos el guion y lo montamos.\n"
        . "- story: pieza de 24 horas, informal.\n"
        . "Si la jugada pide un formato, ESA pieza va en ese formato — no lo cambies por uno más fácil.";

    $prompt = "Negocio:\n{$ctx}\n\n"
        . "LA JUGADA A CUMPLIR:\n"
        . "- {$t['titulo']}\n"
        . "- Qué hay que hacer: {$t['que_hacer']}\n"
        . ($t['por_que'] !== '' ? "- Por qué: {$t['por_que']}\n" : '')
        . ($t['cta'] !== '' ? "- CTA OBLIGADO en todas las piezas: \"{$t['cta']}\"\n" : '')
        . "- Canal: {$t['canal']} · Formato pedido: {$t['formato']}\n\n"
        . ($fotos !== '' ? $fotos . "\n" : '')
        . ($ganadores !== '' ? $ganadores . "\n" : '')
        // El mismo parte de situación que reciben los demás agentes: sin él, el
        // que desmenuza la jugada trabajaba a ciegas — no sabía la meta, ni los
        // días que quedan, ni si el negocio va atrasado.
        . (($__mp = meta_para_prompt($pdo, $marca_id)) !== '' ? "\n{$__mp}\n" : '')
        . "\nDame {$n} pieza(s) distintas para cumplir esta jugada.";

    try {
        $r = ia_ejecutar($pdo, 'planificador', "Desmenuzar jugada: {$t['titulo']}", $prompt, [
            'marca_id' => $marca_id, 'sistema' => $sistema, 'json' => true,
            'temperatura' => 0.85, 'max_tokens' => 1600, 'thinking_budget' => 0,
            'mock_texto' => '{"piezas":[{"plataforma":"instagram","tipo":"post","tema":"Combo estrella","idea":"El combo con el precio visible y el CTA a WhatsApp.","usa_foto":true}]}',
        ]);
        $j = json_decode((string)($r['texto'] ?? ''), true);
        $pz = is_array($j['piezas'] ?? null) ? $j['piezas'] : [];
        return array_slice($pz, 0, $n);
    } catch (Throwable $e) {
        error_log('jugada_ideas: ' . $e->getMessage());
        return [];
    }
}

/**
 * EL CORILLO EJECUTA EL PLAN SOLO (lo corre el relevo semanal).
 *
 * Este es el que hace que la automatización sea de verdad: sin esto, las
 * jugadas solo se ejecutaban si el dueño tocaba el botón — o sea, seguía
 * dependiendo de él. Ahora los lunes el corillo agarra las jugadas
 * pendientes de SU plan y las produce, con el mismo inventario (gaveta,
 * fotos reales, ganadores) que ahorra cuota.
 *
 * Respeta un TOPE de piezas por relevo (el `autopilot_n` de la marca): un
 * plan de 3 jugadas x 3 piezas no se le vacía encima al dueño de un golpe
 * ni le quema la cuota del mes. Lo que no entre, entra el relevo siguiente.
 *
 * @return array{piezas:int, jugadas:int, recicladas:int, detalle:array}
 */
function plan_ejecutar_pendientes(PDO $pdo, int $marca_id, int $tope_piezas): array {
    $out = ['piezas' => 0, 'jugadas' => 0, 'recicladas' => 0, 'detalle' => []];
    if ($tope_piezas <= 0) return $out;

    $meta = meta_activa($pdo, $marca_id);
    if (!$meta) return $out;
    $plan = meta_plan_activo($pdo, (int)$meta['id']);
    if (!$plan) return $out;

    // Las jugadas del plan vigente que el corillo puede hacer y aún no cumplió.
    $tac = meta_tacticas($pdo, (int)$meta['id'], null, (int)$plan['id']);
    foreach ($tac as $t) {
        if ($out['piezas'] >= $tope_piezas) break;
        if (($t['clase'] ?? 'produccion') !== 'produccion') continue;
        if (!in_array($t['estado'], ['pendiente','en_curso'], true)) continue;

        // ¿Le falta trabajo a esta jugada?
        $p = jugada_progreso($pdo, $t);
        if ($p['creadas'] >= (int)$t['piezas_meta']) continue;

        try {
            $r = jugada_ejecutar($pdo, $marca_id, (int)$t['id']);
            if (!empty($r['ok']) && (int)$r['creadas'] > 0) {
                $out['piezas']     += (int)$r['creadas'];
                $out['recicladas'] += (int)$r['recicladas'];
                $out['jugadas']++;
                $out['detalle'][] = $t['titulo'] . ': ' . (int)$r['creadas'] . ' pieza(s)';
            }
        } catch (Throwable $e) { error_log('plan_ejecutar_pendientes: ' . $e->getMessage()); }
    }
    return $out;
}

/**
 * EJECUTAR LA JUGADA — el corillo produce TODO el trabajo de una.
 * Lento a propósito (escribe + hace arte): va por la cola, nunca en la
 * pantalla del dueño.
 *
 * @return array{ok:bool, creadas:int, recicladas:int, resumen:string, ids:array, err?:string}
 */
function jugada_ejecutar(PDO $pdo, int $marca_id, int $tactica_id): array {
    @set_time_limit(0);
    require_once __DIR__ . '/agentes.php';

    $t = jugada_por_id($pdo, $tactica_id, $marca_id);
    if (!$t) return ['ok'=>false, 'err'=>'No encuentro esa jugada.', 'creadas'=>0, 'recicladas'=>0, 'resumen'=>'', 'ids'=>[]];
    if (($t['clase'] ?? 'produccion') !== 'produccion') {
        return ['ok'=>false, 'err'=>'Esta jugada no es de producir contenido.', 'creadas'=>0, 'recicladas'=>0, 'resumen'=>'', 'ids'=>[]];
    }

    $prog   = jugada_progreso($pdo, $t);
    $objetivo = max(1, (int)$t['piezas_meta']);
    $faltan = max(0, $objetivo - $prog['creadas']);
    if ($faltan === 0) {
        return ['ok'=>true, 'creadas'=>0, 'recicladas'=>0, 'ids'=>[],
                'resumen'=>'Esta jugada ya tiene todo su contenido listo. Míralo en Tus Posts.'];
    }

    $meta    = meta_activa($pdo, $marca_id);
    $meta_id = $meta ? (int)$meta['id'] : null;
    $plan_id = null;
    if (!empty($t['plan_id'])) $plan_id = (int)$t['plan_id'];

    // Hora medida por el Optimizador (o 10 AM si aún no hay patrón).
    $hora = 10;
    try {
        require_once __DIR__ . '/optimizador.php';
        $mom = optimizador_mejor_momento($pdo, $marca_id);
        if (($mom['hora'] ?? null) !== null) $hora = (int)$mom['hora'];
    } catch (Throwable $e) {}

    // La fecha sale de meta_fecha_sugerida(): una sola fuente para el motor y
    // para el wizard, para que no se contradigan.
    $__sem = max(1, (int)($t["semana"] ?? 1));
    $fecha_de = function (int $orden) use ($pdo, $marca_id, $__sem): string {
        return meta_fecha_sugerida($pdo, $marca_id, $orden, $__sem)["fecha"];
    };
    $__f1   = meta_fecha_sugerida($pdo, $marca_id, 1, $__sem);
    $apura  = (bool)$__f1["apura"];

    // ── 1. INVENTARIO: qué hay ya ──
    $inv = jugada_inventario($pdo, $marca_id, $t, $faltan);
    $ids = []; $recicladas = 0; $notas = []; $pide_video = 0; $usadas_suyas = 0;
    if ($apura) { $notas[] = $__f1["porque"]; }

    // ── 2. LA GAVETA: piezas listas que nadie publicó, puestas a trabajar ──
    //  No se les toca el texto (pueden ser del dueño): se amarran a la jugada
    //  y se les pone fecha. Sale gratis y arranca el plan HOY.
    $dia = 1;
    foreach ($inv['gaveta'] as $g) {
        if ($faltan <= 0) break;
        try {
            $fecha = $fecha_de($dia);
            $pdo->prepare("UPDATE crecer_contenido SET tactica_id=?, meta_id=?, plan_id=?, fecha_programada=?, updated_at=NOW()
                            WHERE id=? AND marca_id=?")
                ->execute([$tactica_id, $meta_id, $plan_id, $fecha, (int)$g['id'], $marca_id]);
            $ids[] = (int)$g['id']; $recicladas++; $faltan--; $dia++;
        } catch (Throwable $e) { error_log('jugada gaveta: ' . $e->getMessage()); }
    }
    if ($recicladas > 0) {
        $notas[] = $recicladas === 1
            ? 'Encontré 1 post que ya estaba listo en tu gaveta y lo puse a trabajar para esta jugada.'
            : "Encontré {$recicladas} posts que ya estaban listos en tu gaveta y los puse a trabajar para esta jugada.";
    }

    // ── 3. LO QUE FALTE: se produce nuevo ──
    if ($faltan > 0) {
        $ideas = jugada_ideas($pdo, $marca_id, $t, $faltan, $inv);
        if (!$ideas) {   // sin ideas del modelo: una pieza con el brief crudo
            $ideas = [['plataforma'=>($t['canal']==='facebook'?'facebook':'instagram'), 'tipo'=>'post',
                       'tema'=>mb_substr((string)$t['titulo'], 0, 40), 'idea'=>(string)$t['que_hacer'], 'usa_foto'=>true]];
        }
        $fotos_disp = $inv['fotos'];
        foreach ($ideas as $idea) {
            if ($faltan <= 0) break;
            $plat = in_array($idea['plataforma'] ?? '', ['instagram','facebook'], true) ? $idea['plataforma']
                  : ($t['canal'] === 'facebook' ? 'facebook' : 'instagram');
            $tipo = in_array($idea['tipo'] ?? '', ['post','story','reel','carrusel'], true) ? $idea['tipo'] : 'post';
            //  EL FORMATO QUE PROMETIO LA JUGADA MANDA. El plan dijo «un reel»
            //  y el modelo de ideas devolvia «post»: salia un post, y el dueño
            //  leia en su plan un reel que nunca existio. Si la jugada declaro
            //  un formato concreto, ese es el que se produce; `mixto` es la
            //  unica que deja elegir, porque para eso se declaro mixta.
            $fmt_jugada = (string)($t['formato'] ?? '');
            if (in_array($fmt_jugada, ['post','reel','carrusel','historia'], true)) {
                $tipo = $fmt_jugada === 'historia' ? 'story' : $fmt_jugada;
            }
            $txt  = trim((string)($idea['tema'] ?? '') . ' — ' . (string)($idea['idea'] ?? ''));
            if ($txt === '—') $txt = (string)$t['que_hacer'];
            $fecha = $fecha_de($dia);

            // ── CARRUSEL: lo arma el Guionista, slide por slide ─────────────
            //  Antes 'carrusel' ni existía en la lista de arriba: la jugada
            //  decía "haz un carrusel", el tipo se degradaba a 'post' EN
            //  SILENCIO y salía una sola foto. El motor prometía un formato
            //  que no sabía producir.
            if ($tipo === 'carrusel') {
                try {
                    require_once __DIR__ . '/carrusel.php';
                    $cr = carrusel_generar($pdo, $marca_id, $txt, 5);
                    if (empty($cr['ok']) || empty($cr['contenido_id'])) {
                        $notas[] = 'El carrusel no se pudo armar: ' . (string)($cr['err'] ?? 'sin motivo');
                        continue;
                    }
                    $cid = (int)$cr['contenido_id'];
                    // Amarrarlo a la jugada, al plan y a la meta, con SU fecha.
                    $pdo->prepare("UPDATE crecer_contenido
                                      SET plataforma=?, fecha_programada=?, meta_id=?, tactica_id=?, plan_id=?, updated_at=NOW()
                                    WHERE id=? AND marca_id=?")
                        ->execute([$plat, $fecha, $meta_id, $tactica_id, $plan_id, $cid, $marca_id]);
                    $ids[] = $cid; $faltan--; $dia++;
                    $notas[] = 'Carrusel de ' . count($cr['slides']) . ' slides: la historia está escrita, faltan las imágenes.';
                } catch (Throwable $e) {
                    error_log('jugada carrusel: ' . $e->getMessage());
                    $notas[] = 'El carrusel no se pudo armar: ' . $e->getMessage();
                }
                continue;
            }

            try {
                $pdo->prepare(
                    "INSERT INTO crecer_contenido (marca_id, plataforma, tipo, caption, fecha_programada, estado, meta_id, tactica_id, plan_id)
                     VALUES (?,?,?,?,?, 'borrador', ?,?,?)")
                    ->execute([$marca_id, $plat, $tipo, $txt, $fecha, $meta_id, $tactica_id, $plan_id]);
                $cid = (int)$pdo->lastInsertId();
            } catch (Throwable $e) { error_log('jugada insert: ' . $e->getMessage()); continue; }

            // El Creador escribe (ya recibe la meta y el CTA por meta_para_prompt)
            $cap = ''; $visual = '';
            try {
                $rr = redactar_pieza($pdo, $cid, [], $marca_id);
                $cap = (string)($rr['caption'] ?? '');
                $visual = (string)($rr['debate']['visual'] ?? '');
            } catch (Throwable $e) { error_log('jugada redactar: ' . $e->getMessage()); }

            // Si no se pudo escribir, la pieza NO se cuenta ni se deja a medias.
            // Antes se contaba igual: el reporte decía "escribí 3 piezas", el
            // dueño encontraba una vacía, y como la jugada ya tenía sus piezas
            // "creadas" nadie volvía a intentarlo — se quedaba trancada.
            if (trim($cap) === '') {
                try { $pdo->prepare("DELETE FROM crecer_contenido WHERE id=? AND marca_id=?")->execute([$cid, $marca_id]); }
                catch (Throwable $e) {}
                continue;
            }

            // ── REEL: el corillo hace SU parte y pide la del dueño ──
            //  No inventamos video. Se escribe el guion (qué grabar, clip por
            //  clip) y la pieza queda marcada como "falta material". Antes esto
            //  se resolvía generando una imagen y llamándola reel — mentira que
            //  el dueño descubría al ir a publicar.
            if ($tipo === 'reel') {
                //  SI YA TIENE EL VIDEO, no se le pide que grabe otro. La
                //  Estratega pudo elegir un clip suyo de la Biblioteca: se
                //  enlaza y la pieza no nace pidiendo material.
                $aid_reel = (int)($t['activo_id'] ?? 0);
                if ($aid_reel > 0) {
                    try {
                        require_once __DIR__ . '/material.php';
                        $apv = material_aplicar($pdo, $marca_id, (int)$cid, $aid_reel);
                        if (!empty($apv['ok']) && (string)($apv['tipo'] ?? '') === 'video') {
                            $ids[] = $cid; $faltan--; $dia++;
                            $notas[] = 'Para el reel usé un video que ya tenías en tu Biblioteca.';
                            continue;
                        }
                    } catch (Throwable $e) { error_log('jugada reel material: ' . $e->getMessage()); }
                }
                $guion = jugada_guion_reel($pdo, $marca_id, $t, $txt);
                try {
                    $pdo->prepare("UPDATE crecer_contenido SET necesita_material='video', guion=?, updated_at=NOW()
                                    WHERE id=? AND marca_id=?")
                        ->execute([$guion !== '' ? $guion : null, $cid, $marca_id]);
                } catch (Throwable $e) {
                    // Sin la migración de material, no se finge un reel: se deja
                    // como post para no prometer un video que no existe.
                    try { $pdo->prepare("UPDATE crecer_contenido SET tipo='post' WHERE id=? AND marca_id=?")->execute([$cid, $marca_id]); }
                    catch (Throwable $e2) {}
                }
                $ids[] = $cid; $faltan--; $dia++; $pide_video++;
                continue;   // NO se le genera arte: lo que falta es el video del dueño
            }

            // ── EL MATERIAL QUE LA ESTRATEGA ELIGIÓ ──────────────────────
            //  Si la jugada trae un activo concreto, ESE se usa: se enlaza tal
            //  cual, sin pasar por el modelo de imagen. Antes «usar tus fotos»
            //  significaba mandarla a Gemini como base —costaba una unidad de
            //  cuota y encima se soltaba la trazabilidad, así que la pieza
            //  acababa diciendo «arte del corillo»—. Lo real gana y sale gratis.
            $usado_suyo = false;
            $aid_jugada = (int)($t['activo_id'] ?? 0);
            if ($cap !== '' && $aid_jugada > 0) {
                try {
                    require_once __DIR__ . '/material.php';
                    $ap = material_aplicar($pdo, $marca_id, (int)$cid, $aid_jugada);
                    if (!empty($ap['ok'])) {
                        $usado_suyo = true;
                        $usadas_suyas++;
                        //  Queda en la memoria visual: el material propio también
                        //  es un antecedente, y repetir la misma foto tres semanas
                        //  seguidas es repetirse igual.
                        try {
                            require_once __DIR__ . '/variedad_visual.php';
                            variedad_registrar($pdo, $marca_id, 'foto:' . $aid_jugada,
                                ['primary_subject' => 'material real del negocio',
                                 'concepto'        => mb_substr((string)$t['titulo'], 0, 180),
                                 'composition'     => mb_substr($cap, 0, 80)], (int)$cid);
                        } catch (Throwable $e) {}
                    } else {
                        //  NO SE PONE OTRA COSA EN SILENCIO. Si el activo ya no
                        //  está, es de otra marca o no le sirve a este formato, la
                        //  pieza queda pidiendo material suyo: es la verdad, y él
                        //  puede resolverla en un toque desde su Biblioteca.
                        try {
                            $pdo->prepare("UPDATE crecer_contenido SET necesita_material='foto', updated_at=NOW()
                                            WHERE id=? AND marca_id=?")->execute([(int)$cid, $marca_id]);
                        } catch (Throwable $e) {}
                        $notas[] = 'La foto que tenía pensada ya no estaba disponible: te dejé la publicación esperando una tuya.';
                        $ids[] = $cid; $faltan--; $dia++;
                        continue;
                    }
                } catch (Throwable $e) { error_log('jugada material: ' . $e->getMessage()); }
            }

            // El arte: FOTO REAL del negocio si la hay (gana siempre y no gasta
            // cuota de imágenes); si no, se genera con la memoria anti-repetición.
            if ($cap !== '' && !$usado_suyo) {
                $foto_abs = null; $foto_id = null;
                if (!empty($idea['usa_foto']) && $fotos_disp) {
                    $f = array_shift($fotos_disp);
                    $cand = (defined('UPLOADS_PATH') ? UPLOADS_PATH : dirname(__DIR__) . '/uploads') . '/' . (string)$f['archivo'];
                    if (is_file($cand)) { $foto_abs = $cand; $foto_id = (int)$f['id']; }
                }
                try {
                    $g = generar_grafica($pdo, $marca_id, $foto_abs, [
                        'copy' => $cap, 'con_texto' => false, 'con_logo' => true, 'instrucciones' => $visual,
                        'contenido_id' => (int)$cid,
                    ]);
                    if (!empty($g['archivo'])) {
                        //  Lo que pinta el ejecutor nace generado: sin material
                        //  del dueño detras, y por tanto sin id que conservar.
                        $pdo->prepare("UPDATE crecer_contenido SET grafica_path=?, updated_at=NOW() WHERE id=? AND marca_id=?")
                            ->execute([$g['archivo'], $cid, $marca_id]);
                        //  LA PIEZA DEJA DE DECIR QUE LLEVA MATERIAL SUYO. Esto pinta desde
                        //  cero, asi que si la pieza venia con una foto del dueño aplicada, la
                        //  referencia que la trazaba ya no es cierta: se muestra arte generado
                        //  y el origen seguiria diciendo «tu foto». Soltarla es barato — un
                        //  UPDATE que no hace nada si no habia nada — y evita la unica mentira
                        //  que esta columna puede contar.
                        material_soltar($pdo, (int)$marca_id, (int)$cid);
                    }
                    // Se anota qué foto real se usó, para no repetirla en la próxima tanda.
                    if ($foto_id !== null) {
                        try {
                            require_once __DIR__ . '/variedad_visual.php';
                            variedad_registrar($pdo, $marca_id, 'foto:' . $foto_id,
                                ['primary_subject' => 'foto real del negocio', 'composition' => mb_substr($cap, 0, 80)], $cid);
                        } catch (Throwable $e) {}
                    }
                } catch (Throwable $e) { error_log('jugada arte: ' . $e->getMessage()); }
            }

            $ids[] = $cid; $faltan--; $dia++;
        }
    }

    $nuevas = count($ids) - $recicladas;
    if ($nuevas > 0) {
        //  LO QUE SE DICE ES LO QUE PASO. Antes bastaba con que la Biblioteca
        //  tuviera fotos para escribir «usando tus propias fotos» — aunque
        //  ninguna se hubiera usado. Ahora se cuenta lo que de verdad se enlazó.
        $escritas = $nuevas - $pide_video;
        if ($escritas > 0) {
            $notas[] = $escritas === 1 ? 'Escribí 1 pieza nueva.' : "Escribí {$escritas} piezas nuevas.";
        }
        if ($usadas_suyas > 0) {
            $notas[] = $usadas_suyas === 1
                ? 'Usé una foto que dejaste en tu Biblioteca.'
                : "Usé {$usadas_suyas} fotos que dejaste en tu Biblioteca.";
        }
        // Los reels se dicen aparte: ahí falta algo que solo él puede dar.
        if ($pide_video > 0) {
            $notas[] = $pide_video === 1
                ? 'Para el reel te dejé el guion escrito — solo faltan tus clips y yo lo monto.'
                : "Para los {$pide_video} reels te dejé los guiones escritos — solo faltan tus clips y yo los monto.";
        }
    }
    if (!$ids) {
        return ['ok'=>false, 'err'=>'No pude producir el contenido de esta jugada. Intenta otra vez.',
                'creadas'=>0, 'recicladas'=>0, 'resumen'=>'', 'ids'=>[]];
    }

    // La jugada pasa a 'en_curso'; se cerrará SOLA cuando sus piezas se publiquen.
    try {
        $pdo->prepare("UPDATE crecer_meta_tactica SET estado='en_curso', ejecutado_at=NOW(), updated_at=NOW() WHERE id=?")
            ->execute([$tactica_id]);
    } catch (Throwable $e) {}

    $total = count($ids);
    $listas = $total - $pide_video;
    $cola = '';
    if ($listas > 0) $cola = ($listas === 1 ? 'Está esperando tu OK en Tus Posts.' : "Las {$listas} están esperando tu OK en Tus Posts.");
    $resumen = trim(implode(' ', $notas) . ' ' . $cola);
    return ['ok'=>true, 'creadas'=>$total, 'recicladas'=>$recicladas, 'pide_video'=>$pide_video,
            'ids'=>$ids, 'resumen'=>$resumen];
}
