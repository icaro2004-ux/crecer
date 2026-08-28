<?php
// ============================================================
//  CRECER — LA EJECUCIÓN, HECHA VISIBLE
//  includes/ejecucion.php
//
//  EL HUECO QUE CIERRA. Escoger la Meta funciona y el plan se arma bien. Lo
//  que el dueño no veía era lo de después: si el corillo está trabajando, qué
//  terminó, qué espera por él, qué va a salir y cuándo, y qué pasa luego. El
//  producto hacía el trabajo y no lo contaba.
//
//  NO ES OTRO MOTOR DEL PLAN. La máquina de estados sigue siendo la de
//  siempre —`MetaStateComposer`— y aquí solo se TRADUCE su letra a una etapa
//  que una persona entiende. Si algún día el compositor cambia, cambia sola.
//
//  Y LAS CIFRAS SALEN DE LAS PIEZAS, no de un contador aparte que haya que
//  mantener sincronizado: un contador que se desincroniza le miente al dueño
//  con toda la confianza del mundo.
//
//  CERO MODELOS Y CERO ESCRITURA: esto lee y presenta. Se abre en cada carga
//  de Inicio y de Tu Meta.
// ============================================================

require_once __DIR__ . '/i18n.php';

/**
 * LA ETAPA, a partir de la letra del compositor.
 *
 * Es una traducción, no una decisión: quien decide en qué punto está el
 * negocio es `MetaStateComposer`, y hay una sola máquina de estados en el
 * producto. Aquí se le pone nombre humano y se dice de quién es el turno.
 *
 * El fallo NO es una etapa de la línea a propósito: no es un paso del camino,
 * es algo que se atravesó. Meterlo entre «programado» y «publicando» diría que
 * fallar es parte del proceso normal.
 *
 * @param string $letra  estado del compositor (A, E, F, J, K, M, N…)
 * @param array  $ops    lo que devuelve `ejec_operacion()`
 * @return array{etapa:string, idx:int, titulo:string, sub:string, turno:string}
 */
function ejec_etapa(string $letra, array $ops = []): array
{
    //  UN FALLO MANDA SOBRE TODO — mientras la meta esté viva. Si ya se cerró
    //  o no hay ninguna, sacar «una publicación necesita atención» encima es
    //  ruido: no hay plan al que devolverla.
    $viva = !in_array($letra, ['A', 'M'], true);
    if ($viva && (int)($ops['fallidas'] ?? 0) > 0) {
        $n = (int)$ops['fallidas'];
        return ['etapa' => 'fallo', 'idx' => -1,
                'titulo' => $n === 1 ? t('Una publicación necesita atención.')
                                     : t('%s publicaciones necesitan atención.', $n),
                'sub'    => t('Tu contenido sigue guardado.'),
                'turno'  => t('Te toca a ti')];
    }
    if ((int)($ops['publicando'] ?? 0) > 0) {
        return ['etapa' => 'publicando', 'idx' => 3,
                'titulo' => t('Estoy publicando.'),
                'sub'    => t('En cuanto la red confirme, te lo digo.'),
                'turno'  => t('Esperando a la red')];
    }

    switch ($letra) {
        case 'A':   // sin meta
            return ['etapa' => 'sin_meta', 'idx' => -1,
                    'titulo' => t('¿Qué quieres lograr?'), 'sub' => '',
                    'turno'  => t('Te toca a ti')];
        case 'M':   // meta cerrada
            return ['etapa' => 'cerrada', 'idx' => 4,
                    'titulo' => t('Esta meta quedó cerrada.'), 'sub' => '',
                    'turno'  => ''];
        case 'N':   // el plan terminó, la meta sigue
            return ['etapa' => 'completado', 'idx' => 4,
                    'titulo' => t('Completaste este plan.'),
                    //  EL PLAN TERMINA, LA META NO. Son dos hechos distintos y
                    //  confundirlos es prometerle un logro que nadie ha medido.
                    'sub'    => t('Tu Meta sigue activa hasta confirmar el resultado.'),
                    'turno'  => ''];
        case 'B': case 'C': case 'E':   // el corillo trabajando
            return ['etapa' => 'preparando', 'idx' => 0,
                    'titulo' => t('El corillo está preparando tu semana.'),
                    'sub'    => t('Puedes cerrar; seguimos trabajando.'),
                    'turno'  => t('Le toca al corillo')];
        case 'F': case 'G': case 'H': case 'I': case 'D':   // espera por él
            $p = (int)($ops['revisar'] ?? 0); $a = (int)($ops['acciones'] ?? 0);
            $t = $p > 0 && $a > 0
                ? t('Tienes %s publicaciones y %s acciones para decidir.', $p, $a)
                : ($p > 0 ? ($p === 1 ? t('Tienes una publicación para decidir.')
                                      : t('Tienes %s publicaciones para decidir.', $p))
                          : t('Tienes algo esperando tu decisión.'));
            return ['etapa' => 'revisando', 'idx' => 1, 'titulo' => $t,
                    'sub'   => '', 'turno' => t('Te toca a ti')];
        case 'J':   // programado
            return ['etapa' => 'programado', 'idx' => 2,
                    'titulo' => t('Tu semana está programada.'),
                    'sub'    => $ops['proxima']['cuando'] ?? '',
                    'turno'  => t('Le toca al corillo')];
        case 'K': case 'L':   // midiendo / aprendiendo
            return ['etapa' => 'midiendo', 'idx' => 4,
                    'titulo' => t('Ya salió. Estoy recogiendo los resultados.'),
                    'sub'    => '', 'turno' => t('Midiendo resultados')];
    }
    //  Un estado que no se reconoce no se disfraza de otra cosa.
    return ['etapa' => '', 'idx' => -1, 'titulo' => '', 'sub' => '', 'turno' => ''];
}

/** Las cinco etapas de la línea, en orden. La del fallo no está: no es un paso. */
function ejec_pasos(): array
{
    return [t('Preparando'), t('Revisando contigo'), t('Programado'),
            t('Publicando'), t('Midiendo')];
}

/**
 * LO QUE ESTÁ PASANDO DE VERDAD, contado de las piezas.
 *
 * Solo del plan vigente: mezclar piezas de planes anteriores infla los números
 * y el dueño no puede saber a qué corresponden.
 *
 * @return array{revisar:int, programadas:int, publicadas:int, fallidas:int,
 *               acciones:int, material:int, publicando:int, proxima:?array}
 */
function ejec_operacion(PDO $pdo, int $marca_id, ?int $plan_id = null): array
{
    $out = ['revisar' => 0, 'programadas' => 0, 'publicadas' => 0, 'fallidas' => 0,
            'acciones' => 0, 'material' => 0, 'publicando' => 0, 'proxima' => null];

    $filtro = $plan_id ? ' AND plan_id = ' . (int)$plan_id : '';
    try {
        $q = $pdo->prepare("SELECT estado, COUNT(*) n,
                                   SUM(CASE WHEN necesita_material IS NOT NULL
                                             AND necesita_material <> '' THEN 1 ELSE 0 END) mat
                              FROM crecer_contenido
                             WHERE marca_id=?{$filtro}
                          GROUP BY estado");
        $q->execute([$marca_id]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $n = (int)$r['n'];
            switch ((string)$r['estado']) {
                case 'borrador':   $out['revisar']     += $n; break;
                case 'aprobado':
                case 'programado': $out['programadas'] += $n; break;
                case 'publicado':  $out['publicadas']  += $n; break;
                case 'fallido':    $out['fallidas']    += $n; break;
                case 'publicando': $out['publicando']  += $n; break;
            }
            $out['material'] += (int)$r['mat'];
        }
    } catch (Throwable $e) { /* sin contenido, todo a cero: es la verdad */ }

    //  LAS TAREAS SUYAS del plan vigente. Las que solo puede hacer él.
    try {
        $sql = "SELECT COUNT(*) FROM crecer_meta_tactica t
                  JOIN crecer_meta_plan p ON p.id = t.plan_id AND p.estado='activo'
                 WHERE t.marca_id=? AND t.clase='accion_dueno'
                   AND t.estado IN ('pendiente','en_curso') AND t.sustituida_at IS NULL";
        $par = [$marca_id];
        if ($plan_id) { $sql .= " AND t.plan_id=?"; $par[] = $plan_id; }
        $q = $pdo->prepare($sql); $q->execute($par);
        $out['acciones'] = (int)$q->fetchColumn();
    } catch (Throwable $e) {}

    $out['proxima'] = ejec_proxima($pdo, $marca_id);
    return $out;
}

/**
 * LA PRÓXIMA QUE SALE. Con su hora, su red y de dónde vino.
 *
 * La hora se formatea con el reloj de Puerto Rico —el mismo que usa el
 * publicador para decidir— así que la que se enseña aquí es la que va a
 * ocurrir. En Semana, en Tu Meta, en Inicio y en Calendario tiene que leerse
 * igual: una hora distinta en cada pantalla es peor que no ponerla.
 *
 * @return array|null
 */
function ejec_proxima(PDO $pdo, int $marca_id): ?array
{
    try {
        $q = $pdo->prepare(
            "SELECT id, plataforma, tipo, caption, estado, fecha_programada,
                    grafica_path, meta_id, tactica_id
               FROM crecer_contenido
              WHERE marca_id=? AND estado IN ('aprobado','programado')
                AND fecha_programada IS NOT NULL
           ORDER BY fecha_programada ASC LIMIT 1");
        $q->execute([$marca_id]);
        $f = $q->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return null; }
    if (!$f) return null;

    $tac = (int)($f['tactica_id'] ?? 0); $met = (int)($f['meta_id'] ?? 0);
    $origen = $tac > 0 ? t('De tu Meta')
            : ($met > 0 ? t('Creada por ti · considerada por Tu Meta') : t('Creada por ti'));

    return [
        'id'      => (int)$f['id'],
        'red'     => (string)$f['plataforma'],
        'formato' => (string)$f['tipo'],
        //  El caption entero NO sube a la capa principal: es una fila, no la
        //  publicación. Quien quiera leerlo, la abre.
        'titulo'  => mb_strimwidth(trim((string)$f['caption']), 0, 60, '…'),
        'estado'  => (string)$f['estado'],
        'imagen'  => (string)($f['grafica_path'] ?? ''),
        'origen'  => $origen,
        'cuando'  => ejec_cuando((string)$f['fecha_programada']),
        'fecha'   => (string)$f['fecha_programada'],
    ];
}

/** «mañana · 10:00 AM» · «el jueves · 9:00 AM». La misma frase en todas partes. */
function ejec_cuando(?string $fecha): string
{
    $ts = $fecha ? strtotime($fecha) : 0;
    if (!$ts) return '';
    $dias = ['Sun' => 'domingo', 'Mon' => 'lunes', 'Tue' => 'martes', 'Wed' => 'miércoles',
             'Thu' => 'jueves', 'Fri' => 'viernes', 'Sat' => 'sábado'];
    $d = date('Y-m-d', $ts);
    if ($d === date('Y-m-d'))                          $cual = t('hoy');
    elseif ($d === date('Y-m-d', strtotime('+1 day'))) $cual = t('mañana');
    elseif ($ts < strtotime('+7 days'))                $cual = t($dias[date('D', $ts)] ?? '');
    else                                               $cual = date('j/n', $ts);
    return trim($cual . ' · ' . date('g:i A', $ts));
}

/**
 * LA CONSECUENCIA DE UNA PIEZA, reconstruida de su estado.
 *
 * POR QUÉ SE RECONSTRUYE Y NO SE GUARDA. Un mensaje que solo vive en un aviso
 * que desaparece se pierde al recargar, y entonces el dueño ya no sabe qué
 * pasó con lo que acaba de decidir. Esto sale del estado guardado, así que
 * sigue ahí mañana y dice lo mismo.
 *
 * Y NUNCA DICE «Listo»: si hay una consecuencia concreta —una fecha, una red,
 * una cuota que no se gastó— se dice cuál.
 */
function ejec_consecuencia(array $p): string
{
    $estado = (string)($p['estado'] ?? '');
    $cuando = ejec_cuando((string)($p['fecha_programada'] ?? ''));
    $red    = (string)($p['plataforma'] ?? '') === 'facebook' ? 'Facebook' : 'Instagram';

    switch ($estado) {
        case 'publicado':
            $h = !empty($p['publicado_at']) ? date('g:i A', strtotime((string)$p['publicado_at'])) : '';
            return $h !== ''
                ? t('Salió en %s a las %s. Ahora esperamos sus resultados.', $red, $h)
                : t('Ya salió en %s. Ahora esperamos sus resultados.', $red);
        case 'publicando':
            return t('Está saliendo ahora mismo. En cuanto la red confirme, te lo digo.');
        case 'fallido':
            //  Sin tripas: la clase vive entre corchetes en `pub_error` y ahí se
            //  queda. Al dueño se le dice qué hacer.
            $clase = '';
            if (preg_match('/^\[(\w+)\]/', (string)($p['pub_error'] ?? ''), $m)) $clase = $m[1];
            return $clase === 'credenciales'
                ? t('No pudo salir. Tu contenido sigue guardado y necesita que revises la conexión.')
                : t('No pudo salir. Tu contenido sigue guardado.');
        case 'programado':
        case 'aprobado':
            return $cuando !== ''
                ? t('La aprobaste. Se publicará %s.', $cuando)
                : t('La aprobaste. Queda lista para salir.');
        case 'rechazado':
            return t('La descartaste. No se publicará.');
        case 'borrador':
            $mat = trim((string)($p['necesita_material'] ?? ''));
            if ($mat !== '') {
                return $mat === 'video'
                    ? t('No se publicará hasta que subas tu video.')
                    : t('No se publicará hasta que subas tu foto.');
            }
            return t('No se publicará hasta que decidas. Seguirá esperándote en esta semana.');
    }
    return '';
}

/**
 * LOS MENSAJES DEL CORILLO PARA INICIO — por consecuencia, no por orden de
 * llegada.
 *
 * EL ORDEN ES EL PRODUCTO. Primero lo que no sale si él no hace nada (un fallo,
 * una conexión, su tarea), después lo que está listo para decidir, y al final
 * lo que ya está en marcha. Un tablero que enseña seis cosas a la vez no tiene
 * prioridad: la tiene el que mira, y entonces no sirve de nada.
 *
 * TRES COMO MÁXIMO, y cada uno con qué pasó, qué significa y adónde ir.
 * Ninguno se genera con un modelo: todos salen de filas que existen.
 *
 * @return array{nombre:string, mensajes:array}
 */
function ejec_mensajes(PDO $pdo, int $marca_id, array $marca, string $base,
                       array $ops, array $etapa): array
{
    $mid = 'marca=' . $marca_id;
    $m = [];

    //  1 · LO QUE NO SALE SI ÉL NO HACE NADA.
    if ((int)$ops['fallidas'] > 0) {
        $n = (int)$ops['fallidas'];
        $m[] = ['ico' => 'bolt', 'urgente' => true,
                'txt' => $n === 1 ? t('Una publicación no pudo salir. Tu contenido sigue guardado.')
                                  : t('%s publicaciones no pudieron salir. Tu contenido sigue guardado.', $n),
                'accion' => t('Revisar el problema'),
                'href'   => "{$base}/aprobar2.php?tab=listos&{$mid}"];
    }
    if ((int)$ops['material'] > 0) {
        $n = (int)$ops['material'];
        $m[] = ['ico' => 'image', 'urgente' => true,
                'txt' => $n === 1 ? t('Necesito material tuyo para terminar una pieza.')
                                  : t('Necesito material tuyo para terminar %s piezas.', $n),
                'accion' => t('Subir material'), 'href' => "{$base}/biblioteca.php?{$mid}"];
    }
    if ((int)$ops['acciones'] > 0) {
        $n = (int)$ops['acciones'];
        $m[] = ['ico' => 'check-circle', 'urgente' => false,
                'txt' => $n === 1 ? t('Hay una acción del plan que solo puedes hacer tú.')
                                  : t('Hay %s acciones del plan que solo puedes hacer tú.', $n),
                'accion' => t('Ver mi semana'), 'href' => "{$base}/meta.php?{$mid}&vista=semana"];
    }

    //  2 · LO QUE ESTÁ LISTO PARA DECIDIR.
    if ((int)$ops['revisar'] > 0) {
        $n = (int)$ops['revisar'];
        $m[] = ['ico' => 'pen', 'urgente' => false,
                'txt' => $n === 1 ? t('Tengo una publicación lista para que la revises.')
                                  : t('Tengo %s publicaciones listas para que las revises.', $n),
                'accion' => t('Revisar mi semana'), 'href' => "{$base}/meta.php?{$mid}&vista=semana"];
    }

    //  3 · LO QUE YA ESTÁ EN MARCHA.
    if (!empty($ops['proxima'])) {
        $m[] = ['ico' => 'calendar', 'urgente' => false,
                'txt' => t('La próxima publicación sale %s.', (string)$ops['proxima']['cuando']),
                'accion' => t('Ver Calendario'), 'href' => "{$base}/calendario.php?{$mid}"];
    }
    if ((int)$ops['publicadas'] > 0 && ($etapa['etapa'] ?? '') === 'midiendo') {
        $m[] = ['ico' => 'chart', 'urgente' => false,
                'txt' => t('Ya salió una publicación. Estoy recogiendo sus resultados.'),
                'accion' => t('Ver Resultados'), 'href' => "{$base}/resultados.php?{$mid}"];
    }
    if (($etapa['etapa'] ?? '') === 'preparando') {
        $m[] = ['ico' => 'sparkles', 'urgente' => false,
                'txt' => t('Estoy preparando tu próxima semana.'),
                'accion' => '', 'href' => ''];
    }

    return ['nombre' => ejec_nombre($marca), 'mensajes' => array_slice($m, 0, 3)];
}

/**
 * CÓMO SE LLAMA SU CORILLO. Si el dueño lo bautizó, su nombre; si no, el rol.
 * Nunca el nombre propio de otra cuenta: eso es inventarle un empleado que no
 * contrató.
 */
function ejec_nombre(array $marca): string
{
    if (function_exists('equipo_nombre')) {
        $n = trim((string)equipo_nombre($marca, 'gerente'));
        $roster = function_exists('equipo_roster') ? equipo_roster() : [];
        $def = (string)($roster['gerente']['rol'] ?? 'El Gerente');
        if ($n !== '' && $n !== $def) return $n;
    }
    return t('Tu corillo');
}
