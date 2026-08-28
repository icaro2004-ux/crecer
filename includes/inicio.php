<?php
// ============================================================
//  CRECER — LO QUE INICIO NECESITA SABER, Y NADA MAS
//  includes/inicio.php
//
//  QUE ES ESTO. Cuatro lectores pequeños para el centro de mando: el adelanto
//  del calendario, lo que hizo el corillo, la señal de resultado y lo que le
//  toca al dueño. Nada mas.
//
//  QUE NO ES. No es otro compositor de estado. La tarjeta de la Meta —la que
//  manda en la pantalla— sigue saliendo de `MetaPresentador::paraHome()`, que
//  es una frontera cerrada: Inicio no vuelve a leer evidencia, tacticas, jobs
//  ni progreso para decidir que dice. Aqui solo esta lo que esa frontera no
//  cubre, y cada lector devuelve lo justo para pintar su bloque.
//
//  POR QUE EN PEDACITOS Y NO UN OBJETO GRANDE. Un «tablero» con todo dentro
//  invita a que la vista se invente reglas: si la plantilla tiene el snapshot
//  entero a mano, tarde o temprano alguien mete un `if` y aparece la segunda
//  pantalla que decide por su cuenta. Cada bloque recibe su arreglo cerrado.
//
//  CERO RED Y CERO ESCRITURA. Ninguno de estos lectores llama a un modelo,
//  genera nada, encola nada ni escribe una fila. Abrir el panel no puede
//  costar dinero ni cambiar el negocio del dueño.
// ============================================================

require_once __DIR__ . '/contexto.php';

/**
 * EL ADELANTO DEL CALENDARIO. Lo de hoy y lo que viene — no el calendario.
 *
 * Se apoya en `ctx_calendario()`, que es el mismo lector que usa la Estratega
 * para no programar encima de lo que el dueño ya tiene. Una sola verdad sobre
 * lo que esta ocupado: si Inicio contara una cosa y la Estratega otra, el
 * dueño descubriria la contradiccion en el peor momento.
 *
 * @return array{estado:string, filas:array, hay:bool}
 */
function inicio_calendario(PDO $pdo, int $marca_id, int $tope = 3): array
{
    $sec = ctx_calendario($pdo, $marca_id);
    $out = ['estado' => (string)$sec['estado'], 'filas' => [], 'hay' => false];
    if ($sec['estado'] !== CTX_DISPONIBLE) return $out;

    $etq = [
        'de_meta'  => 'De tu Meta',
        //  Adoptada: la escribio el, y despues se amarro a la Meta. Las dos
        //  cosas son verdad y las dos le importan.
        'adoptada' => 'Creado por ti · aporta a tu Meta',
        'manual'   => 'Creado por ti',
    ];
    foreach (array_slice($sec['ocupados'], 0, max(1, $tope)) as $o) {
        $ts = strtotime($o['fecha'] . ' ' . $o['hora']);
        $out['filas'][] = [
            'id'      => (int)$o['id'],
            'cuando'  => inicio_cuando($ts),
            'hoy'     => $ts ? (date('Y-m-d', $ts) === date('Y-m-d')) : false,
            'red'     => (string)$o['red'],
            'formato' => (string)$o['tipo'],
            'titulo'  => mb_strimwidth(trim((string)$o['idea']), 0, 64, '…'),
            'origen'  => $etq[$o['origen']] ?? 'Creado por ti',
        ];
    }
    $out['hay'] = count($out['filas']) > 0;
    return $out;
}

/** «hoy 10:00 AM» · «mañana 3:00 PM» · «el sábado 9:00 AM» — como lo diría una persona. */
function inicio_cuando(?int $ts): string
{
    if (!$ts) return '';
    $dias = ['Sun' => 'domingo', 'Mon' => 'lunes', 'Tue' => 'martes', 'Wed' => 'miércoles',
             'Thu' => 'jueves', 'Fri' => 'viernes', 'Sat' => 'sábado'];
    $d = date('Y-m-d', $ts);
    if ($d === date('Y-m-d'))                          $cual = t('hoy');
    elseif ($d === date('Y-m-d', strtotime('+1 day'))) $cual = t('mañana');
    elseif ($ts < strtotime('+7 days'))                $cual = ($dias[date('D', $ts)] ?? '');
    else                                               $cual = date('j/n', $ts);
    return trim($cual . ' · ' . date('g:i A', $ts));
}

/**
 * LO QUE HIZO EL CORILLO. Hechos persistidos, no un relato.
 *
 * EL RIESGO DE ESTE BLOQUE es justo el contrario del resto del producto: aqui
 * es facilisimo escribir tres frases bonitas que suenen a equipo trabajando y
 * que no correspondan a nada. Por eso cada linea sale de una fila que existe:
 * una semana preparada, unas piezas creadas, un material propio enlazado, una
 * publicacion programada. Si no hay filas, no hay lineas.
 *
 * @return array{eventos:array, hay:bool}
 */
function inicio_actividad(PDO $pdo, int $marca_id, array $marca = [], int $tope = 3): array
{
    $ev = [];

    //  1 · LA SEMANA. Lo mas reciente y lo que mas le importa.
    try {
        $q = $pdo->prepare("SELECT semana, estado, creadas, preparada_at, cerrada_at
                              FROM crecer_meta_semana
                             WHERE marca_id=? ORDER BY id DESC LIMIT 1");
        $q->execute([$marca_id]);
        if ($f = $q->fetch(PDO::FETCH_ASSOC)) {
            if ((string)$f['estado'] === 'preparando') {
                $ev[] = ['ico' => 'sparkles', 'txt' => t('Está preparando tu próxima semana.'),
                         'cuando' => ''];
            } elseif ((string)$f['estado'] === 'preparada') {
                $ev[] = ['ico' => 'check-circle', 'txt' => t('Preparó tu próxima semana.'),
                         'cuando' => inicio_hace($f['preparada_at'] ?? null)];
            }
        }
    } catch (Throwable $e) { /* sin el libro de semanas, esta linea no existe */ }

    //  2 · PIEZAS CREADAS ESTA SEMANA, y cuantas llevan material suyo. Lo
    //      segundo se cuenta aparte porque es lo que mas le gusta oir y lo mas
    //      facil de exagerar.
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM crecer_contenido
                             WHERE marca_id=? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $q->execute([$marca_id]);
        $n = (int)$q->fetchColumn();
        if ($n > 0) {
            $ev[] = ['ico' => 'pen', 'cuando' => '',
                     'txt' => $n === 1 ? t('Escribió una publicación nueva.')
                                       : t('Escribió %s publicaciones nuevas.', $n)];
        }
    } catch (Throwable $e) {}

    try {
        if (ctx_hay_columna($pdo, 'crecer_contenido', 'material_activo_id')) {
            $q = $pdo->prepare("SELECT COUNT(*) FROM crecer_contenido
                                 WHERE marca_id=? AND material_activo_id IS NOT NULL
                                   AND updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
            $q->execute([$marca_id]);
            $n = (int)$q->fetchColumn();
            if ($n > 0) {
                $ev[] = ['ico' => 'image', 'cuando' => '',
                         'txt' => $n === 1 ? t('Usó una foto de tu Biblioteca.')
                                           : t('Usó %s cosas de tu Biblioteca.', $n)];
            }
        }
    } catch (Throwable $e) {}

    //  3 · PROGRAMADO. Se dice cuando sale, no «se programó algo».
    try {
        $q = $pdo->prepare("SELECT fecha_programada FROM crecer_contenido
                             WHERE marca_id=? AND estado IN ('programado','aprobado')
                               AND fecha_programada IS NOT NULL AND fecha_programada >= NOW()
                          ORDER BY fecha_programada ASC LIMIT 1");
        $q->execute([$marca_id]);
        if ($f = $q->fetchColumn()) {
            $ev[] = ['ico' => 'calendar', 'cuando' => '',
                     'txt' => t('Dejó una publicación lista para %s.', inicio_cuando(strtotime((string)$f)))];
        }
    } catch (Throwable $e) {}

    return ['eventos' => array_slice($ev, 0, max(1, $tope)), 'hay' => count($ev) > 0,
            'nombre'  => inicio_nombre_corillo($marca)];
}

/**
 * COMO SE LLAMA SU CORILLO.
 *
 * Si el dueño bautizó a su equipo, se usa SU nombre. Si no, el rol. Lo que no
 * puede pasar nunca es que a todas las cuentas se les llame igual con un
 * nombre propio: «Tito» es de una cuenta concreta, y ponerselo a otra es
 * inventarle un empleado que no contrató.
 */
function inicio_nombre_corillo(array $marca): string
{
    if (function_exists('equipo_nombre')) {
        $n = trim((string)equipo_nombre($marca, 'gerente'));
        //  `equipo_nombre()` cae al rol por defecto cuando no hay nombre puesto:
        //  ese rol es generico y sirve, pero aqui se prefiere «tu corillo».
        $roster = function_exists('equipo_roster') ? equipo_roster() : [];
        $porDefecto = (string)($roster['gerente']['rol'] ?? 'El Gerente');
        if ($n !== '' && $n !== $porDefecto) return $n;
    }
    return t('Tu corillo');
}

/** «hace 2 horas» · «ayer» · «el 12 de agosto». Sin precisión falsa. */
function inicio_hace(?string $fecha): string
{
    $ts = $fecha ? strtotime($fecha) : 0;
    if (!$ts) return '';
    $seg = time() - $ts;
    if ($seg < 3600)  return t('hace un rato');
    if ($seg < 86400) { $h = (int)floor($seg / 3600); return $h === 1 ? t('hace una hora') : t('hace %s horas', $h); }
    if ($seg < 172800) return t('ayer');
    $d = (int)floor($seg / 86400);
    return t('hace %s días', $d);
}

/**
 * LA SEÑAL DE RESULTADO. Una sola, y solo si se puede afirmar.
 *
 * REGLA DURA: sin cobertura no hay juicio. Ni «vamos en ritmo», ni «creciendo»,
 * ni una flecha verde. Se enseña la cifra que haya —o se dice que todavia no
 * hay suficiente— y se manda a Resultados. Un numero con una interpretacion
 * inventada detras es peor que ningun numero: el dueño toma decisiones con el.
 *
 * Y NUNCA se usa «cuantos posts publicaste» como si fuera el resultado del
 * negocio: publicar mucho no es vender mas, y confundirlos es exactamente lo
 * que hace que un dueño pague tres meses sin saber si le sirvio.
 *
 * @return array{hay:bool, confiable:bool, cifra:string, pie:string, nota:string, serie:array}
 */
function inicio_senal(PDO $pdo, int $marca_id, ?array $plan = null): array
{
    $out = ['hay' => false, 'confiable' => false, 'cifra' => '', 'pie' => '',
            'nota' => '', 'serie' => []];
    $r = ctx_resultados($pdo, $marca_id, $plan);

    if ($r['estado'] === CTX_NO_DISP) {
        //  No se pudo mirar. No es «no tienes resultados»: es que no lo sé.
        return $out;
    }
    if ($r['estado'] === CTX_VACIA) {
        $out['hay']  = true;
        $out['nota'] = ((int)($r['publicadas'] ?? 0) > 0)
            ? t('Todavía no hay suficiente información.')
            : t('Cuando empieces a publicar, aquí verás cómo te va.');
        return $out;
    }

    $out['hay']       = true;
    $out['confiable'] = !empty($r['confiable']);
    $inter = 0;
    foreach (($r['por_formato'] ?? []) as $d) $inter += (int)$d['interacciones'];

    if ($out['confiable']) {
        $out['cifra'] = (string)$inter;
        $out['pie']   = t('interacciones en %s días', (int)$r['dias']);
        //  LO QUE PASA FUERA DE CRECER NO LO VEMOS. Decirlo aqui cuesta una
        //  linea y evita que lea estos numeros como si fueran sus ventas.
        $out['nota']  = t('Las redes no cuentan lo que pasa por WhatsApp o en el local.');
    } else {
        $out['cifra'] = (string)(int)$r['con_metrica'];
        $out['pie']   = t('publicaciones con números');
        $out['nota']  = t('Todavía no hay suficiente información.');
    }
    return $out;
}

/**
 * LO QUE LE TOCA AL DUEÑO. Tres como maximo, y solo si es de verdad suyo.
 *
 * NO ENTRAN LOS PENDIENTES DEL SISTEMA. Que un job este en cola o que una
 * imagen se este generando no es trabajo suyo: es trabajo nuestro, y ponerlo
 * en su lista le hace sentir que le devolvimos el trabajo que nos paga por
 * hacer.
 *
 * Cada uno dice QUE falta, POR QUE importa y CUANDO. Sin las tres, es ruido.
 *
 * @return array{items:array, hay:bool}
 */
function inicio_pendientes(PDO $pdo, int $marca_id, string $base, array $opts = []): array
{
    $mid   = 'marca=' . $marca_id;
    $items = [];

    //  1 · PUBLICACIONES QUE NO SALIERON. Lo primero: es lo unico que ya
    //      costó algo y no llegó a nadie. Solo las de los ultimos 7 dias —
    //      una alerta que lleva semanas gritando se aprende a ignorar.
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM crecer_contenido
                             WHERE marca_id=? AND estado='fallido'
                               AND COALESCE(updated_at, created_at) > DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $q->execute([$marca_id]);
        $n = (int)$q->fetchColumn();
        if ($n > 0) {
            $items[] = [
                'ico'    => 'bolt', 'urgente' => true,
                'que'    => $n === 1 ? t('Una publicación no pudo salir.')
                                     : t('%s publicaciones no pudieron salir.', $n),
                'porque' => t('Ya estaban hechas: nadie las vio.'),
                'cuando' => t('Esta semana'),
                'accion' => t('Ver qué pasó'),
                'href'   => "{$base}/aprobar2.php?tab=listos&{$mid}",
            ];
        }
    } catch (Throwable $e) {}

    //  2 · MATERIAL SUYO. Una pieza esperando su foto o su video no avanza
    //      sola, y es lo unico que el corillo no puede resolver por su cuenta.
    try {
        $q = $pdo->prepare("SELECT necesita_material tipo, COUNT(*) n, MIN(fecha_programada) prox
                              FROM crecer_contenido
                             WHERE marca_id=? AND estado IN ('borrador','aprobado')
                               AND necesita_material IS NOT NULL AND necesita_material <> ''
                          GROUP BY necesita_material ORDER BY n DESC LIMIT 1");
        $q->execute([$marca_id]);
        if ($f = $q->fetch(PDO::FETCH_ASSOC)) {
            $n = (int)$f['n'];
            $es_video = (string)$f['tipo'] === 'video';
            $items[] = [
                'ico'    => $es_video ? 'camera' : 'image', 'urgente' => false,
                'que'    => $es_video
                    ? ($n === 1 ? t('Un reel espera tu video.') : t('%s reels esperan tus videos.', $n))
                    : ($n === 1 ? t('Una publicación espera una foto tuya.')
                                : t('%s publicaciones esperan fotos tuyas.', $n)),
                'porque' => t('Con material tuyo se ve real, y no gasta de tu cuota.'),
                'cuando' => $f['prox'] ? inicio_cuando(strtotime((string)$f['prox'])) : '',
                'accion' => t('Subir material'),
                'href'   => "{$base}/biblioteca.php?{$mid}",
            ];
        }
    } catch (Throwable $e) {}

    //  3 · SU ACCION DEL PLAN. La que solo puede hacer el, con sus manos o su
    //      bolsillo. Se lee la jugada viva mas antigua.
    try {
        $q = $pdo->prepare("SELECT t.id, t.titulo FROM crecer_meta_tactica t
                              JOIN crecer_meta_plan p ON p.id = t.plan_id AND p.estado='activo'
                             WHERE t.marca_id=? AND t.clase='accion_dueno'
                               AND t.estado IN ('pendiente','en_curso') AND t.sustituida_at IS NULL
                          ORDER BY t.semana ASC, t.orden ASC LIMIT 1");
        $q->execute([$marca_id]);
        if ($f = $q->fetch(PDO::FETCH_ASSOC)) {
            $items[] = [
                'ico'    => 'check-circle', 'urgente' => false,
                'que'    => mb_strimwidth(trim((string)$f['titulo']), 0, 70, '…'),
                'porque' => t('Es parte de tu plan y solo la puedes hacer tú.'),
                'cuando' => '',
                'accion' => t('Ver mi semana'),
                'href'   => "{$base}/meta.php?{$mid}&vista=semana",
            ];
        }
    } catch (Throwable $e) {}

    //  4 · LAS REDES SIN CONECTAR, y solo si ya hay algo que publicar: sin
    //      piezas listas, pedirle que conecte es un deber sin motivo.
    if (!empty($opts['sin_redes'])) {
        try {
            $q = $pdo->prepare("SELECT COUNT(*) FROM crecer_contenido
                                 WHERE marca_id=? AND estado='aprobado'");
            $q->execute([$marca_id]);
            if ((int)$q->fetchColumn() > 0) {
                $items[] = [
                    'ico'    => 'bolt', 'urgente' => true,
                    'que'    => t('Falta conectar tus redes.'),
                    'porque' => t('Tienes publicaciones listas que no pueden salir solas.'),
                    'cuando' => '',
                    'accion' => t('Conectar'),
                    'href'   => "{$base}/conectar.php?{$mid}",
                ];
            }
        } catch (Throwable $e) {}
    }

    //  ORDEN: lo que tiene consecuencia primero. Dentro de eso, el orden en que
    //  se leyeron, que ya va de mas grave a menos.
    usort($items, fn($a, $b) => ((int)$b['urgente']) <=> ((int)$a['urgente']));
    return ['items' => array_slice($items, 0, 3), 'hay' => count($items) > 0];
}
