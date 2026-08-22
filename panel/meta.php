<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — LA META DEL NEGOCIO
//  panel/meta.php?marca=<id>
//
//  El hueco que cierra: el corillo producía contenido sin saber para qué
//  número trabajaba. Aquí el dueño declara lo que quiere lograr y la
//  Estratega arma el plan — y esa meta pasa a gobernar el motor entero
//  (enfoque de la semana, planificador, CTA de cada pieza).
//
//  DOS ESTADOS:
//   · Sin meta  → WIZARD: una pregunta por pantalla (regla de la casa).
//   · Con meta  → LA META VIVA: cómo va (medido), el diagnóstico de la
//     Estratega y las jugadas — con quién ejecuta cada una.
//
//  LENGUAJE (regla permanente): hablamos con un comerciante, no con un
//  mercadólogo. Primero lo que quiere en sus palabras; la palabra técnica
//  después, chiquita y explicada. Cero emojis en la UI (SVG de ico()).
//
//  NATIVE DESIGN — desktop: dos columnas, el número grande respira al
//  lado de las jugadas. Móvil: una columna, la meta y el progreso caben
//  antes del primer scroll; las jugadas se deslizan debajo.
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/suscripcion.php';
require_once __DIR__ . '/../includes/iconos.php';
require_once __DIR__ . '/../includes/meta_negocio.php';
require_once __DIR__ . '/../includes/meta_cambio.php';
require_once __DIR__ . '/../includes/meta_oportunidad.php';
requiere_login();
require_once __DIR__ . '/../includes/panel_guard.php';
requiere_suscripcion($pdo, isset($_GET['marca']) ? (int)$_GET['marca'] : null);

$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }
$marca_id = (int)$marca['id'];
$BASE = '/crecer/panel';

// ── AJAX ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_ok()) { echo json_encode(['ok'=>false,'err'=>'La sesión expiró. Recarga la página.']); exit; }
    $accion = (string)($_POST['accion'] ?? '');

    try {
        // (a) "No sé cuánto pedir" → la sugerencia sale de SUS números, no de un modelo.
        if ($accion === 'sugerir') {
            $obj  = (string)($_POST['objetivo'] ?? 'pedidos');
            $dias = max(7, min(180, (int)($_POST['dias'] ?? 30)));
            echo json_encode(['ok'=>true] + meta_sugerir_numero($pdo, $marca_id, $obj, $dias), JSON_UNESCAPED_UNICODE);
            exit;
        }

        // (b) Crear la meta y que la Estratega arme el plan.
        if ($accion === 'crear') {
            $obj = (string)($_POST['objetivo'] ?? '');
            if (!isset(meta_objetivos()[$obj])) { echo json_encode(['ok'=>false,'err'=>'Escoge qué quieres lograr.']); exit; }
            $def = meta_objetivo_def($obj);
            //  DOBLE CLIC · el candado que impide una meta de mas.
            //  El dedo nervioso y la conexion lenta mandan el mismo formulario
            //  dos veces. Sin candado salen DOS metas: meta_crear() pausa la
            //  anterior, asi que la primera se queda en el historial del dueño
            //  sin que la pidiera — y la Estratega se llama (y se cobra) dos
            //  veces. Se mira si acaba de entrar una igual.
            //
            //  created_at lo pone MySQL y NOW() tambien: los dos lados del
            //  reloj son el mismo, asi que aqui no hay el desfase de 4h que sale
            //  cuando una fecha nace en PHP y se compara con NOW().
            //
            //  La ventana es corta y el objetivo tiene que coincidir: crear otra
            //  meta a proposito —tras cerrar la anterior— no cae aqui, porque la
            //  cerrada ya no esta en estado activa.
            $rep = $pdo->prepare(
                "SELECT id FROM crecer_meta
                   WHERE marca_id=? AND objetivo=? AND estado='activa'
                     AND created_at >= (NOW() - INTERVAL 3 MINUTE)
                   ORDER BY id DESC LIMIT 1");
            $rep->execute([$marca_id, $obj]);
            $ya = (int)$rep->fetchColumn();
            if ($ya > 0) {
                //  Se contesta con la verdad de lo que hay, no con un ok a ciegas:
                //  si el plan de esa meta no llego a existir, plan_ok es false.
                $hay = $pdo->prepare("SELECT COUNT(*) FROM crecer_meta_tactica WHERE meta_id=?");
                $hay->execute([$ya]);
                echo json_encode(['ok'=>true, 'meta_id'=>$ya,
                                  'plan_ok'=>((int)$hay->fetchColumn() > 0), 'repetido'=>true]);
                exit;
            }

            $meta_id = meta_crear($pdo, $marca_id, [
                'objetivo'          => $obj,
                'titulo'            => (string)($_POST['titulo'] ?? $def['titulo']),
                'cantidad'          => (string)($_POST['cantidad'] ?? ''),
                'fecha_limite'      => (string)($_POST['fecha_limite'] ?? ''),
                'presupuesto_pauta' => (string)($_POST['presupuesto'] ?? ''),
                'contexto'          => (string)($_POST['contexto'] ?? ''),
            ]);
            $plan = meta_plan_generar($pdo, $marca_id, $meta_id);
            // Si la Estratega falló, la meta igual queda creada (se puede reintentar).
            echo json_encode(['ok'=>true, 'meta_id'=>$meta_id, 'plan_ok'=>!empty($plan['ok']),
                              'err'=>$plan['err'] ?? null], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // (c) Reintentar el plan.
        if ($accion === 'replan') {
            $meta = meta_activa($pdo, $marca_id);
            if (!$meta) { echo json_encode(['ok'=>false,'err'=>'No tienes una meta activa.']); exit; }

            //  DOBLE CLIC, SIN RELOJ. El wizard manda el id del plan que CREE
            //  estar reemplazando. Si ya no es el vigente, es que el primer
            //  clic entro: se contesta con el que hay y NO se llama otra vez a
            //  la Estratega —que cuesta dinero y deja un plan de mas en el
            //  historial—. Es un compare-and-swap: no depende de cuantos
            //  segundos pasaron ni de en que zona horaria esta el reloj.
            $vigente = meta_plan_activo($pdo, (int)$meta['id']);
            $pedido  = (int)($_POST['plan_actual'] ?? 0);
            if ($pedido > 0 && $vigente && (int)$vigente['id'] !== $pedido) {
                echo json_encode(['ok'=>true, 'repetido'=>true, 'plan'=>(int)$vigente['id']]);
                exit;
            }

            $plan = meta_plan_generar($pdo, $marca_id, (int)$meta['id'],
                                      (string)($_POST['motivo'] ?? ''));
            echo json_encode(['ok'=>!empty($plan['ok']), 'err'=>$plan['err'] ?? null], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // (c2) EJECUTAR LA JUGADA — el corillo produce TODO su contenido.
        //      Va por cola: escribir + arte de varias piezas tarda minutos y no
        //      puede colgar la pantalla del dueño.
        if ($accion === 'ejecutar') {
            require_once __DIR__ . '/../includes/meta_async.php';
            require_once __DIR__ . '/../includes/meta_ejecutar.php';
            $tid = (int)($_POST['id'] ?? 0);
            $t = jugada_por_id($pdo, $tid, $marca_id);
            if (!$t) { echo json_encode(['ok'=>false,'err'=>'No encuentro esa jugada.']); exit; }
            if (($t['clase'] ?? 'produccion') !== 'produccion') {
                echo json_encode(['ok'=>false,'err'=>'Esta jugada la tienes que hacer tú — no es de producir contenido.']); exit;
            }
            $ya = meta_job_en_curso($pdo, $tid);
            if ($ya) { echo json_encode(['ok'=>true, 'job'=>$ya, 'ya'=>true]); exit; }
            $job = meta_job_encolar($pdo, $marca_id, $tid);
            meta_job_disparar($job);
            echo json_encode(['ok'=>true, 'job'=>$job]);
            exit;
        }

        // (c3) Polling del trabajo del corillo.
        if ($accion === 'job') {
            require_once __DIR__ . '/../includes/meta_async.php';
            $st = meta_job_estado($pdo, (int)($_POST['job'] ?? 0), $marca_id);
            if (!$st) { echo json_encode(['ok'=>false,'err'=>'No encuentro ese trabajo.']); exit; }
            echo json_encode(['ok'=>true] + $st, JSON_UNESCAPED_UNICODE);
            exit;
        }

        // (d) Confirmar una jugada. OJO: esto SOLO aplica a las de 'accion_dueno'
        //     (lo que pasa fuera de Crecer). Las de producción se cierran solas
        //     con la evidencia de publicación — el dueño no declara nuestro trabajo.
        if ($accion === 'tactica') {
            $ok = meta_tactica_estado($pdo, (int)($_POST['id'] ?? 0), $marca_id, (string)($_POST['estado'] ?? 'hecha'));
            $completo = false;
            if ($ok) {
                $mt = meta_activa($pdo, $marca_id);
                $pl = $mt ? meta_plan_activo($pdo, (int)$mt['id']) : null;
                if ($pl) {
                    $pg = meta_plan_progreso($pdo, (int)$pl['id']);
                    if ($pg['completo']) $completo = meta_plan_cerrar($pdo, (int)$pl['id'], 'completado');
                }
            }
            echo json_encode(['ok'=>$ok, 'plan_completo'=>$completo]);
            exit;
        }

        // (e) Evaluar un plan cerrado a pedido (el dueño no quiere esperar al relevo).
        if ($accion === 'evaluar') {
            $ev = meta_plan_evaluar($pdo, (int)($_POST['plan'] ?? 0), $marca_id);
            echo json_encode($ev, JSON_UNESCAPED_UNICODE);
            exit;
        }

        // (f) Cerrar / cambiar la meta.
        if ($accion === 'cerrar') {
            $meta = meta_activa($pdo, $marca_id);
            //  Sin meta activa no hay nada que cerrar — y ahi cae el segundo
            //  clic: se contesta que si, sin volver a escribir.
            if (!$meta) { echo json_encode(['ok'=>true, 'repetido'=>true]); exit; }
            $pedida = (int)($_POST['meta_actual'] ?? 0);
            if ($pedida > 0 && $pedida !== (int)$meta['id']) {
                echo json_encode(['ok'=>true, 'repetido'=>true, 'meta'=>(int)$meta['id']]); exit;
            }
            //  LA DIFERENCIA IMPORTA: no es lo mismo darla por lograda que
            //  abandonarla. Es el record del negocio, y de ahi aprende el
            //  Optimizador. Y se cierra tambien su plan, que antes se quedaba
            //  'activo' colgando de una meta que ya no existe.
            $cierre = (string)($_POST['cierre'] ?? 'cancelada');
            if (!in_array($cierre, ['lograda','cancelada'], true)) $cierre = 'cancelada';
            meta_cerrar_meta($pdo, (int)$meta['id'], $marca_id, $cierre);
            echo json_encode(['ok'=>true, 'cierre'=>$cierre]);
            exit;
        }

        // (f2) CAMBIAR DE META — cerrar la de ahora y estrenar la proxima en
        //      UNA operacion. Antes eran dos pantallas: se cerraba primero y
        //      despues se mandaba al wizard vacio, asi que quien se arrepentia
        //      a mitad se quedaba SIN META. Ahora la proxima se recoge entera
        //      antes de escribir nada, y el cambio va en transaccion.
        if ($accion === 'cambiar') {
            $meta = meta_activa($pdo, $marca_id);
            if (!$meta) { echo json_encode(['ok'=>false,'err'=>'No tienes una meta activa que cambiar.']); exit; }
            $pedida = (int)($_POST['meta_actual'] ?? 0);
            if ($pedida > 0 && $pedida !== (int)$meta['id']) {
                //  El primer clic ya cambio la meta: la activa es otra.
                echo json_encode(['ok'=>true, 'repetido'=>true, 'meta_id'=>(int)$meta['id']]); exit;
            }
            $obj = (string)($_POST['objetivo'] ?? '');
            if (!isset(meta_objetivos()[$obj])) { echo json_encode(['ok'=>false,'err'=>'Escoge qué quieres lograr.']); exit; }
            $cierre = (string)($_POST['cierre'] ?? 'cancelada');
            if (!in_array($cierre, ['lograda','cancelada'], true)) $cierre = 'cancelada';
            $def = meta_objetivo_def($obj);

            $nueva = meta_cambiar_meta($pdo, $marca_id, (int)$meta['id'], $cierre, [
                'objetivo'          => $obj,
                'titulo'            => (string)($_POST['titulo'] ?? $def['titulo']),
                'cantidad'          => (string)($_POST['cantidad'] ?? ''),
                'fecha_limite'      => (string)($_POST['fecha_limite'] ?? ''),
                'presupuesto_pauta' => (string)($_POST['presupuesto'] ?? ''),
                'contexto'          => (string)($_POST['contexto'] ?? ''),
            ]);
            if (!$nueva) {
                //  La transaccion se deshizo entera: la meta de antes sigue
                //  activa. El negocio NO se queda sin norte.
                echo json_encode(['ok'=>false,
                                  'err'=>'No pude cambiar la meta. La de ahora sigue en pie — dale otra vez.']); exit;
            }
            $plan = meta_plan_generar($pdo, $marca_id, $nueva);
            echo json_encode(['ok'=>true, 'meta_id'=>$nueva, 'plan_ok'=>!empty($plan['ok']),
                              'err'=>$plan['err'] ?? null], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // (h) AJUSTAR LA META. El token viaja en el POST y el candado esta
        //     dentro de meta_ajustar_trazado(): aqui no se vuelve a mirar.
        if ($accion === 'ajustar') {
            $meta = meta_activa($pdo, $marca_id);
            if (!$meta) { echo json_encode(['ok'=>false,'err'=>'No tienes una meta activa.']); exit; }
            $campos = [];
            foreach (META_AJUSTABLES as $c) {
                //  Solo lo que de verdad vino. Mandar todos los campos siempre
                //  llenaria el historial de «cambios» que no cambian nada.
                if (array_key_exists($c, $_POST)) $campos[$c] = (string)$_POST[$c];
            }
            //  POR LA WEB, EL TOKEN ES OBLIGATORIO. meta_ajustar_trazado() deja
            //  pasar el token vacio a proposito —los llamadores internos no
            //  tienen pantalla de la que sacarlo—, pero por aqui entra el
            //  navegador: sin token, un POST viejo pisaria un cambio nuevo sin
            //  que nadie se entere.
            $tk = (string)($_POST['token'] ?? '');
            if ($tk === '') {
                echo json_encode(['ok'=>false, 'motivo'=>'sin_token',
                    'err'=>'Recarga la pantalla y vuelve a intentarlo.']); exit;
            }
            $r = meta_ajustar_trazado($pdo, $marca_id, (int)$meta['id'], (int)$usuario['id'],
                $campos, $tk, (string)($_POST['motivo'] ?? ''),
                !empty($_POST['plan_nuevo']));
            //  El token nuevo viaja de vuelta: si el dueño sigue en la pantalla
            //  y ajusta otra cosa, no le rebota su propio cambio.
            if (!empty($r['meta'])) $r['token'] = meta_token($r['meta']);
            unset($r['meta']);
            echo json_encode($r, JSON_UNESCAPED_UNICODE);
            exit;
        }

        // (h2) PEDIRLE A LA ESTRATEGA UNA ALTERNATIVA. NO escribe nada: la
        //      propuesta se enseña y el dueño decide. La escritura es (h3).
        if ($accion === 'alternativa') {
            $t = $pdo->prepare("SELECT * FROM crecer_meta_tactica WHERE id=? AND marca_id=?");
            $t->execute([(int)($_POST['jugada'] ?? 0), $marca_id]);
            $orig = $t->fetch(PDO::FETCH_ASSOC);
            if (!$orig) { echo json_encode(['ok'=>false,'err'=>'No encuentro esa jugada.']); exit; }
            $mot = (string)($_POST['motivo'] ?? '');
            if (!in_array($mot, META_MOTIVOS_SUST, true)) {
                echo json_encode(['ok'=>false,'err'=>'Dime primero qué te frena.']); exit;
            }
            $r = meta_alternativa_jugada($pdo, $marca_id, $orig, $mot, (string)($_POST['nota'] ?? ''));
            echo json_encode($r, JSON_UNESCAPED_UNICODE);
            exit;
        }

        // (h3) SUSTITUIR DE VERDAD. La alternativa llega ya aprobada por el
        //      dueño; la Estratega ya se llamo en (h2), fuera de transaccion.
        if ($accion === 'sustituir') {
            $alt = json_decode((string)($_POST['alt'] ?? ''), true);
            if (!is_array($alt)) { echo json_encode(['ok'=>false,'err'=>'Se perdió la alternativa. Vuelve a pedirla.']); exit; }
            $r = meta_sustituir_jugada($pdo, $marca_id, (int)($_POST['jugada'] ?? 0),
                (int)$usuario['id'], (string)($_POST['motivo'] ?? ''),
                (string)($_POST['nota'] ?? ''), $alt, (string)($_POST['token'] ?? ''));
            echo json_encode($r, JSON_UNESCAPED_UNICODE);
            exit;
        }

        // (i) LAS FECHAS DEL CALENDARIO. Tres respuestas y ninguna toca la
        //     meta, el plan ni el progreso — esa es la garantia de que son
        //     sugerencias. Añadir inserta UNA pieza en borrador y nada mas.
        if (in_array($accion, ['oport_add', 'oport_no', 'oport_luego'], true)) {
            $org = (string)($_POST['origen'] ?? '');
            $oid = (int)($_POST['oport'] ?? 0);
            $ocu = (string)($_POST['fecha'] ?? '');
            if ($accion === 'oport_add') {
                $r = efem_anadir($pdo, $marca_id, (int)$usuario['id'], $org, $oid, $ocu);
            } elseif ($accion === 'oport_no') {
                $r = efem_descartar($pdo, $marca_id, (int)$usuario['id'], $org, $oid, $ocu,
                                    (string)($_POST['motivo'] ?? ''));
            } else {
                $r = efem_posponer($pdo, $marca_id, (int)$usuario['id'], $org, $oid, $ocu);
            }
            echo json_encode($r, JSON_UNESCAPED_UNICODE);
            exit;
        }

        // (g) EMPEZAR: el dueño vio el trato y dijo que sí. El sello va en un
        //     solo UPDATE que ya comprueba dueño, vigencia y que no estuviera
        //     puesto — ver meta_plan_presentar(). Aquí no se vuelve a mirar.
        if ($accion === 'presentar') {
            $cambio = meta_plan_presentar($pdo, (int)($_POST['plan'] ?? 0), $marca_id);
            //  false NO es un fallo: es "ya estaba presentado" (doble clic),
            //  "ese plan no es tuyo" o "ya no es el vigente". En los tres casos
            //  la respuesta correcta es la misma —recargar y dejar que el estado
            //  se recomponga— y en ninguno hay nada que contarle al de afuera.
            echo json_encode(['ok'=>true, 'cambio'=>$cambio]);
            exit;
        }

        echo json_encode(['ok'=>false,'err'=>'Acción desconocida.']);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'err'=>substr($e->getMessage(), 0, 180)], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$meta      = meta_activa($pdo, $marca_id);
$objetivos = meta_objetivos();
$glosario  = meta_glosario();
$active    = 'meta';
$page_title = 'Tu Meta';
require __DIR__ . '/_shell.php';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// CIERRE AUTOMÁTICO: antes de pintar nada, las jugadas cuyas piezas ya se
// publicaron se dan por hechas SOLAS. La verdad la da la evidencia, no un
// checkbox del dueño.
require_once __DIR__ . '/../includes/meta_ejecutar.php';
if ($meta) jugadas_sincronizar_marca($pdo, $marca_id);

$prog = $meta ? meta_progreso($pdo, $meta) : null;
// EL PLAN VIGENTE y su cumplimiento; las jugadas mostradas son las SUYAS.
$plan_act  = $meta ? meta_plan_activo($pdo, (int)$meta['id']) : null;
$prog_plan = $plan_act ? meta_plan_progreso($pdo, (int)$plan_act['id']) : null;
$tacticas  = $meta ? meta_tacticas($pdo, (int)$meta['id']) : [];
// EL HISTORIAL: cada plan cerrado con su récord medido (se abre para ver el detalle).
$historial = [];
if ($meta) {
    foreach (meta_planes($pdo, (int)$meta['id']) as $p) {
        if ($plan_act && (int)$p['id'] === (int)$plan_act['id']) continue;   // el vigente va arriba
        $historial[] = ['plan' => $p, 'prog' => meta_plan_progreso($pdo, (int)$p['id']),
                        'res'  => meta_plan_resultados($pdo, $p),
                        'tac'  => meta_tacticas($pdo, (int)$meta['id'], null, (int)$p['id'])];
        if (count($historial) >= 6) break;
    }
}

// ── EL ESTADO DOMINANTE ─────────────────────────────────────────────────────
//  Fase 2: la pantalla ya no interpreta el modelo por su cuenta. Le pregunta al
//  compositor QUÉ pasa y QUÉ hace falta del dueño, y pinta eso. El compositor
//  es puro: leer no cambia nada.
require_once __DIR__ . '/../core/Meta/MetaStateComposer.php';
require_once __DIR__ . '/../core/Meta/MetaSnapshotReader.php';
require_once __DIR__ . '/../core/Meta/MetaRetorno.php';
$mt_snap   = MetaSnapshotReader::leer($pdo, $marca_id);
$mt_estado = MetaStateComposer::componer($mt_snap);

// TRES CAPAS, no tres pantallas apiladas:
//   (por defecto) Meta · Ahora · Camino      — lo que toca hoy
//   ?vista=plan   plan completo, diagnóstico, comparación e historial
//   ?vista=wizard      el wizard de escoger meta (estado A)
//   ?vista=plan-nuevo  pedirle otro plan a la Estratega, para esta misma meta
//   ?vista=cambiar     cerrar esta meta y estrenar la proxima, de una
$vista = $_GET['vista'] ?? '';
if (!in_array($vista, ['plan', 'wizard', 'plan-nuevo', 'cambiar', 'ajustar', 'sustituir'], true)) $vista = 'ahora';
if (!$meta && $vista === 'ahora' && !empty($_GET['nueva'])) $vista = 'wizard';
//  Las dos delicadas piden una meta viva. Sin ella no hay nada que rehacer ni
//  que cambiar, y mandar a un wizard vacio es como se llegaba antes a que el
//  negocio se quedara sin norte: se vuelve a lo que toca ahora.
if (!$meta && in_array($vista, ['plan-nuevo', 'cambiar', 'ajustar', 'sustituir'], true)) $vista = 'ahora';
//  Y las dos de 7a solo salen si su esquema esta. Sin la migracion no se
//  degrada la pantalla: la capacidad no existe y punto. Ver la matriz de
//  compatibilidad en tests/test_meta_compatibilidad.php.
if ($vista === 'ajustar'   && !meta_ajuste_disponible($pdo))      $vista = 'plan';
if ($vista === 'sustituir' && !meta_sustitucion_disponible($pdo)) $vista = 'plan';

/** Volver aquí desde donde sea que mande la acción dominante. */
$mt_volver = MetaRetorno::marcador();

/**
 * El número de la meta, dicho como se puede defender.
 * Con cobertura parcial NO se enseñan porcentaje, ritmo ni "faltan N": el dato
 * que tenemos no cubre lo que el dueño llamaría un resultado. Esto no es copy,
 * es la salvaguarda del compositor aplicada a la pantalla.
 */
//  DOS palabras distintas, y no es un detalle: una nombra el OBJETIVO y la otra
//  lo YA CONSEGUIDO. Mezclarlas ("25 pedidos registrados" arriba y "0 hasta hoy"
//  debajo) presenta la meta como si ya estuviera cumplida.
$mt_unidad = function (string $objetivo): array {
    switch ($objetivo) {
        case 'pedidos':        return ['pedidos',      'registrados'];
        case 'ventas':         return ['en ventas',    'registradas'];
        case 'conversaciones': return ['mensajes',     'recibidos'];
        case 'alcance':        return ['personas',     'alcanzadas'];
        case 'comunidad':      return ['interacciones','contadas'];
        default:               return ['resultados',   'registrados'];
    }
};

/** El mes de hoy, en castellano. La cuota se cuenta por mes natural. */
$mt_mes = function (): string {
    $m = ['enero','febrero','marzo','abril','mayo','junio','julio',
          'agosto','septiembre','octubre','noviembre','diciembre'];
    return $m[(int)date('n') - 1] ?? '';
};

/** Cuando vuelven las imagenes. La cuota lo trae en dd/mm; si viene vacio
 *  -sin libro todavia- se calcula el dia 1 del mes que entra.            */
$mt_reset = function (?array $cuota): string {
    $r = trim((string)($cuota['reset'] ?? ''));
    return $r !== '' ? $r : date('d/m', strtotime('first day of next month'));
};

/**  De donde sale el numero, en cristiano. La cifra NUNCA va sola: si no
 *   dice de donde sale, el dueño entiende que Crecer cuenta todo lo suyo.  */
$mt_fuente = function (string $objetivo, string $participio): string {
    switch ($objetivo) {
        case 'pedidos': case 'ventas':  return $participio . ' en Crecer';
        case 'conversaciones':          return $participio . ' por los canales conectados';
        default:                        return $participio . ' por Instagram y Facebook';
    }
};

/**  LA FECHA, EN CRISTIANO. Vive aqui y no en el compositor porque dar
 *   formato depende del reloj y de la zona, y el compositor tiene que dar
 *   el mismo resultado en cualquier maquina con el mismo retrato.         */
$mt_cuando = function (string $fecha): string {
    $fecha = trim($fecha);
    if ($fecha === '') return '';
    $ts = strtotime($fecha);
    if (!$ts) return '';
    $dias = ['Sun'=>'domingo','Mon'=>'lunes','Tue'=>'martes','Wed'=>'miércoles',
             'Thu'=>'jueves','Fri'=>'viernes','Sat'=>'sábado'];
    $dia  = $dias[date('D', $ts)] ?? date('d/m', $ts);
    $hora = ltrim(date('g:i', $ts), '0') . ' ' . date('a', $ts);
    return $dia . ' ' . $hora;
};

/** El subtitulo del objeto: la red y cuando sale. */
$mt_sub_obj = function (array $o) use (&$mt_cuando): string {
    $partes = [];
    if (trim((string)($o['red'] ?? '')) !== '')   $partes[] = trim((string)$o['red']);
    $c = $mt_cuando((string)($o['fecha'] ?? ''));
    if ($c !== '') $partes[] = $c;
    return implode(' · ', $partes);
};

/** El icono del objeto, por tipo de pieza. */
$mt_ico_obj = function (string $tipo): string {
    switch ($tipo) {
        case 'reel':     return 'bolt';
        case 'carrusel': return 'list';
        case 'historia': return 'camera';
        default:         return 'image';
    }
};

/** El icono de la accion, por lo que la accion hace. */
$mt_ico_act = function (string $tipo): string {
    switch ($tipo) {
        case 'aprobacion': return 'check';
        case 'material':   return 'upload';
        case 'decision':   return 'target';
        case 'informativa': return 'eye';
        default:           return 'chev-der';
    }
};

/**  «COMO VOY» SIN AFIRMAR LO QUE NO SE SABE.
 *
 *   Decia «vas en ritmo» y un porcentaje. Con cobertura parcial eso es una
 *   afirmacion sin respaldo: Crecer cuenta lo que pasa por dentro, no lo que
 *   el negocio cierra por WhatsApp. Asi que se dice el numero, de cuantos, y
 *   de donde sale. El ritmo solo se menciona cuando el compositor certifica
 *   que la cobertura es completa — y esa decision es suya, no de aqui.       */
$mt_como_voy = function ($E, array $snap, array $uni, string $obj) use (&$mt_fuente): string {
    $num = fn($v) => rtrim(rtrim(number_format((float)$v, 2), '0'), '.');
    [$sust, $part] = $uni;
    $a = $snap['progreso']['actual'];
    $c = $snap['meta']['cantidad'] ?? null;
    $frases = [];
    if ($a !== null && $c !== null) {
        $frases[] = 'Llevas ' . $num($a) . ' ' . $sust . ' ' . $mt_fuente($obj, $part)
                  . ', de los ' . $num($c) . ' de tu meta.';
    } elseif ($a !== null) {
        $frases[] = 'Llevas ' . $num($a) . ' ' . $sust . ' ' . $mt_fuente($obj, $part) . '.';
    } else {
        $frases[] = 'Todavía no tengo señal de esta meta.';
    }
    if ($E->puedeAfirmarProgreso() && !empty($snap['progreso']['ritmo_dia'])) {
        $frases[] = 'Para llegar hacen falta como '
                  . rtrim(rtrim(number_format((float)$snap['progreso']['ritmo_dia'], 1), '0'), '.')
                  . ' al día de aquí a la fecha.';
    } else {
        $frases[] = $obj === 'pedidos' || $obj === 'ventas'
            ? 'Solo cuento los que pasan por aquí: lo que cierras por WhatsApp no entra solo — dímelo y lo sumo.'
            : ($obj === 'conversaciones'
               ? 'Cuento los mensajes que llegaron por los canales conectados.'
               : 'Instagram y Facebook reportan con retraso, así que este número va un día por detrás.');
    }
    return implode(' ', $frases);
};
?>
<style>
  /* ══ CAPA 2 · EL PLAN COMPLETO ════════════════════════════════════════
     Mismo sistema que la capa 1 — mismos tokens, mismo radio, misma escala.
     Lo que cambia es la arquitectura de informacion:

         resumen honesto  →  Ahora  →  Hecho  →  Despues
                          →  diagnostico, comparacion, historial, opciones
                             (todo plegado, y en ese orden)

     Los nombres de clase se conservan a proposito. El guion de esta pantalla
     engancha por .jg-hacer, .jg-ok2, .hp-ev, #replan y #cerrar; renombrarlos
     seria mover el riesgo a otro sitio sin ganar nada. Lo que se sustituye
     son los ESTILOS, que es lo que hacia de esto un reguero.

     Y nada de contenido baja de 14px. */
  .plan{
    --tm-rosa:#EF4375; --tm-rosa-tx:#C81E52; --tm-rosa-bt:#D42A5C; --tm-rosa-bt-h:#B81F4C;
    --tm-rosa-piel:#FDF0F4;
    --tm-teal:#00A49F; --tm-teal-tx:#00726F; --tm-teal-piel:#EDF7F6;
    --tm-aviso:#8A5310; --tm-aviso-piel:#FBF3E7;
    --tm-r:12px; --tm-r-bt:10px;
    max-width:680px;margin:0 auto;padding-bottom:var(--ah-zona,20px);
  }
  .plan h1{font-family:var(--font-display,'Poppins',sans-serif);font-weight:700;
    font-size:26px;line-height:1.2;letter-spacing:-.022em;color:var(--tinta);margin:0}
  .plan h2{font-family:var(--font-display,'Poppins',sans-serif);font-weight:700;
    font-size:17px;line-height:1.3;color:var(--tinta);margin:0}

  /* — la salida, siempre visible y siempre primero — */
  .plan-volver{display:inline-flex;align-items:center;gap:8px;min-height:44px;
    font-size:16px;font-weight:600;color:var(--tinta);text-decoration:none;margin-bottom:6px}
  .plan-volver .ic{width:17px;height:17px;stroke-width:2;transform:rotate(180deg)}
  .plan-volver:hover{color:var(--tm-rosa-tx)}
  .plan-volver:focus-visible{outline:2px solid var(--tinta);outline-offset:2px;border-radius:8px}

  /* — RESUMEN HONESTO — el mismo contrato de cobertura que la capa 1 — */
  .plan-res{border-bottom:1px solid var(--line);padding-bottom:16px;margin-bottom:22px}
  .plan-res .fila{display:flex;align-items:center;gap:7px;margin-top:12px}
  .plan-res .fila .ic{width:15px;height:15px;flex:none;color:var(--muted);stroke-width:1.9}
  .plan-res .obj{font-size:14px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--muted)}
  .plan-res .dias{margin-left:auto;font-size:14px;color:var(--muted)}
  .plan-res .cifra{display:flex;align-items:baseline;gap:6px;margin-top:7px;flex-wrap:wrap}
  .plan-res .cifra b{font-size:26px;font-weight:700;color:var(--tinta);line-height:1.1;
    font-variant-numeric:tabular-nums;letter-spacing:-.02em}
  .plan-res .cifra .et{font-size:14px;color:var(--ink,#4A434F);font-weight:500}
  .plan-res .cifra .de{font-size:14px;color:var(--muted)}
  .plan-res .nomed{font-size:14px;line-height:1.5;color:var(--muted);margin:10px 0 0}
  .plan-res .nomed b{color:var(--tinta)}
  /*  Barra, porcentaje y ritmo SOLO cuando el compositor certifica que se
      puede afirmar. Es el MISMO puedeAfirmarProgreso() de la capa 1: no hay
      una segunda interpretacion, hay un solo contrato. */
  .plan-barra{display:block;height:4px;border-radius:99px;background:var(--line);
    margin-top:10px;overflow:hidden}
  .plan-barra i{display:block;height:100%;border-radius:99px;background:var(--tm-teal)}
  .plan-ritmo{font-size:14px;line-height:1.5;color:var(--muted);margin:10px 0 0}
  .plan-ritmo b{color:var(--tinta)}

  /* — los tres grupos — */
  .plan-grupo{margin-top:22px}
  .plan-gt{display:flex;align-items:center;gap:9px;margin-bottom:11px}
  .plan-gt span{font-size:14px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;
    color:var(--muted)}
  .plan-gt u{flex:1;height:1px;background:var(--line);display:block}
  .plan-gt .n{font-size:14px;color:var(--muted);font-weight:500;letter-spacing:0;text-transform:none}

  /* — una jugada — */
  .jg{border:1px solid var(--line);border-radius:var(--tm-r);background:var(--card,#fff);
    margin-bottom:10px;overflow:hidden}
  .jg[open]{border-color:var(--raya-firme,#D8D3CC)}
  .jg.turno{border-color:var(--tm-rosa);box-shadow:0 0 0 1px var(--tm-rosa)}
  .jg.hecha{background:var(--crema,#FAF7F4)}
  .jg-sum{list-style:none;display:flex;align-items:flex-start;gap:11px;
    min-height:56px;padding:13px 15px;cursor:pointer}
  .jg-sum::-webkit-details-marker{display:none}
  .jg-sum:focus-visible{outline:2px solid var(--tinta);outline-offset:-2px}
  .jg-tipo{display:none}                      /* el tipo ya lo dice el cuerpo */
  .jg-t{flex:1;min-width:0;font-size:16px;font-weight:600;line-height:1.35;color:var(--tinta)}
  .jg.hecha .jg-t{color:var(--muted)}
  .jg-mini{font-size:14px;color:var(--muted);white-space:nowrap;padding-top:1px}
  .jg-ahora{display:inline-flex;align-items:center;gap:7px;font-size:14px;font-weight:600;
    color:var(--tm-rosa-tx);background:var(--tm-rosa-piel);border-radius:99px;
    padding:5px 12px;margin:0 15px 12px}
  .jg > *:not(.jg-sum):not(.jg-ahora){margin-left:15px;margin-right:15px}
  .jg > *:last-child{margin-bottom:15px}
  .jg-q{font-size:16px;line-height:1.5;color:var(--ink,#4A434F);margin:0 0 10px}
  .jg-p, .jg-cta{font-size:14px;line-height:1.5;color:var(--muted);margin:0 0 10px}
  .jg-p b, .jg-cta b{color:var(--tinta)}

  /* — el trabajo real de la jugada — */
  .jg-trabajo{display:flex;align-items:center;gap:11px;margin:0 0 12px;flex-wrap:wrap}
  .jg-puntos{display:flex;gap:5px}
  .jg-puntos i{width:9px;height:9px;border-radius:50%;background:var(--line);display:block}
  .jg-puntos i.lista{background:var(--tm-teal)}
  .jg-puntos i.pub{background:var(--tm-teal-tx)}
  .jg-est{font-size:14px;color:var(--muted)}

  /* — las puertas: cada pieza a SU pantalla — */
  .jg-puertas{display:flex;flex-direction:column;gap:8px;margin:0 0 12px}
  .pu{display:flex;align-items:center;gap:11px;min-height:56px;padding:9px 12px;
    border:1px solid var(--line);border-radius:var(--tm-r-bt);text-decoration:none;
    background:var(--card,#fff)}
  .pu:hover{border-color:var(--tm-rosa);background:var(--crema,#FAF7F4)}
  .pu:focus-visible{outline:2px solid var(--tinta);outline-offset:2px}
  .pu.ok{background:var(--tm-teal-piel);border-color:transparent}
  .pu-n{flex:none;min-width:44px;height:44px;border-radius:9px;display:flex;align-items:center;
    justify-content:center;background:var(--crema,#FAF7F4);font-size:14px;font-weight:600;
    color:var(--muted)}
  .pu.ok .pu-n{background:transparent;color:var(--tm-teal-tx)}
  .pu-n .ic{width:20px;height:20px;stroke-width:2}
  .pu-t{flex:1;min-width:0}
  .pu-t b{display:block;font-size:15px;font-weight:600;color:var(--tinta);line-height:1.3}
  .pu-t small{display:block;font-size:14px;color:var(--muted);line-height:1.4;margin-top:2px}
  .pu-go{flex:none;color:var(--muted)}
  .pu-go .ic{width:16px;height:16px;stroke-width:2}

  /* — la salida de la jugada imposible, y la marca de la que ya se fue — */
  .jg-nopuedo{display:flex;align-items:center;justify-content:center;gap:8px;min-height:48px;
    border:1px solid var(--line);border-radius:var(--tm-r-bt);background:transparent;
    font-size:15px;font-weight:600;color:var(--muted);text-decoration:none;margin:0 0 12px}
  .jg-nopuedo:hover{border-color:var(--tm-aviso);color:var(--tm-aviso);background:var(--tm-aviso-piel)}
  .jg-nopuedo:focus-visible{outline:2px solid var(--tinta);outline-offset:2px}
  .jg-nopuedo .ic{width:16px;height:16px;stroke-width:2}
  .jg-sust{display:flex;align-items:center;gap:7px;font-size:14px;font-weight:600;
    color:var(--tm-aviso);background:var(--tm-aviso-piel);border-radius:99px;
    padding:5px 12px;margin:0 15px 12px}
  .jg-sust .ic{width:15px;height:15px;flex:none;stroke-width:2}
  .jg-sust span{min-width:0}

  .jg-video{font-size:14px;line-height:1.5;color:var(--ink,#4A434F);background:var(--tm-aviso-piel);
    border-radius:var(--tm-r-bt);padding:12px 14px;margin:0 0 12px}
  .jg-video b{display:block;color:var(--tm-aviso);font-size:15px;margin-bottom:3px}
  .jg-video a{display:inline-flex;align-items:center;gap:7px;min-height:44px;margin-top:6px;
    font-size:15px;font-weight:600;color:var(--tm-aviso);text-decoration:none}
  .jg-video a .ic{width:17px;height:17px;stroke-width:2}

  /* — quien hace que, en una fila de etiquetas — */
  .jg-meta{display:flex;gap:7px;flex-wrap:wrap;margin:0 0 12px}
  .jg-tag{display:inline-flex;align-items:center;gap:6px;font-size:14px;color:var(--muted);
    background:var(--crema,#FAF7F4);border-radius:99px;padding:5px 11px}
  .jg-tag .ic{width:15px;height:15px;stroke-width:1.9}
  .jg-tag.corillo{color:var(--tm-teal-tx);background:var(--tm-teal-piel)}
  .jg-tag.dueno{color:var(--tm-rosa-tx);background:var(--tm-rosa-piel)}
  .jg-tag.regla{color:var(--tm-aviso);background:var(--tm-aviso-piel)}

  /* — la accion de la jugada — */
  .jg-hacer, .jg-ok2, .jg-ver{display:flex;align-items:center;justify-content:center;gap:9px;
    width:auto;min-height:52px;border-radius:var(--tm-r-bt);font-family:inherit;font-size:16px;
    font-weight:700;cursor:pointer;text-decoration:none;border:0;
    transition:background .14s ease, transform .1s ease}
  .jg-hacer, .jg-ok2{background:var(--tm-rosa-bt);color:#fff}
  .jg-hacer:hover, .jg-ok2:hover{background:var(--tm-rosa-bt-h)}
  .jg-hacer:active, .jg-ok2:active, .jg-ver:active{transform:translateY(1px)}
  .jg-hacer:disabled{opacity:.55;cursor:default;transform:none}
  .jg-hacer.sec{background:transparent;color:var(--tinta);border:1px solid var(--line)}
  .jg-hacer.sec:hover{background:var(--crema,#FAF7F4)}
  .jg-ver{background:transparent;color:var(--tinta);border:1px solid var(--line)}
  .jg-ver:hover{background:var(--crema,#FAF7F4)}
  .jg-hacer:focus-visible, .jg-ok2:focus-visible, .jg-ver:focus-visible{
    outline:2px solid var(--tinta);outline-offset:2px}
  .jg-hacer .ic, .jg-ok2 .ic, .jg-ver .ic{width:18px;height:18px;stroke-width:2}
  .jg-live{font-size:14px;line-height:1.5;color:var(--muted);margin-top:9px;display:none}
  .jg-live.on{display:block}

  /* — las capas plegadas: diagnostico, comparacion, historial, opciones — */
  .plan-capas{margin-top:26px;border-top:1px solid var(--line)}
  .plan-ac{border-bottom:1px solid var(--line)}
  .plan-ac > summary{list-style:none;display:flex;align-items:center;gap:10px;min-height:56px;
    padding:0 2px;cursor:pointer;font-size:16px;font-weight:600;color:var(--tinta)}
  .plan-ac > summary::-webkit-details-marker{display:none}
  .plan-ac > summary:hover{color:var(--tm-rosa-tx)}
  .plan-ac > summary:focus-visible{outline:2px solid var(--tinta);outline-offset:2px;border-radius:8px}
  .plan-ac .cta{margin-left:auto;font-size:14px;color:var(--muted);font-weight:500}
  .plan-ac .chev{width:16px;height:16px;flex:none;color:var(--muted);stroke-width:2;
    transition:transform .18s ease}
  .plan-ac[open] > summary .chev{transform:rotate(180deg)}
  .plan-ac .dentro{padding:2px 2px 20px;animation:tmAbre .18s ease}
  .plan-ac p{font-size:15px;line-height:1.55;color:var(--ink,#4A434F);margin:0 0 10px}
  .plan-ac p:last-child{margin-bottom:0}

  .diag .qui{display:inline-flex;align-items:center;gap:7px;font-size:14px;font-weight:600;
    color:var(--tm-teal-tx);margin-bottom:8px}
  .diag .qui .ic{width:16px;height:16px;stroke-width:1.9}
  .vered{display:inline-flex;align-items:center;font-size:14px;font-weight:600;
    border-radius:99px;padding:5px 12px;margin-top:8px;
    color:var(--tm-teal-tx);background:var(--tm-teal-piel)}
  .vered.ambiciosa{color:var(--tm-aviso);background:var(--tm-aviso-piel)}
  .vered.dificil{color:var(--tm-rosa-tx);background:var(--tm-rosa-piel)}

  /* — comparacion — */
  .cmp{display:flex;flex-direction:column;gap:10px}
  .cmp-p{border:1px solid var(--line);border-radius:var(--tm-r);padding:14px 15px}
  .cmp-p.on{border-color:var(--tm-rosa)}
  .cmp-h{display:flex;align-items:baseline;gap:8px;flex-wrap:wrap;margin-bottom:10px}
  .cmp-h b{font-size:16px;color:var(--tinta)}
  .cmp-est{font-size:14px;font-weight:600;color:var(--muted);background:var(--crema,#FAF7F4);
    border-radius:99px;padding:4px 10px}
  .cmp-p.on .cmp-est{background:var(--tm-rosa-piel);color:var(--tm-rosa-tx)}
  .cmp-d{font-size:14px;color:var(--muted);margin-left:auto}
  .cmp-corto{margin:0 0 10px;font-size:14px;line-height:1.5;color:var(--tm-aviso);
    background:var(--tm-aviso-piel);border-radius:9px;padding:9px 11px}
  .cmp-nums{display:grid;grid-template-columns:repeat(2,1fr);gap:9px}
  .cmp-nums>div{background:var(--crema,#FAF7F4);border-radius:9px;padding:10px 11px;position:relative}
  .cmp-nums b{display:block;font-size:19px;line-height:1.15;color:var(--tinta);
    font-variant-numeric:tabular-nums;font-weight:700}
  .cmp-nums span{display:block;font-size:14px;color:var(--muted);margin-top:2px}
  .cmp-nums i{position:absolute;top:9px;right:10px;font-style:normal;font-size:14px;font-weight:600;
    padding:2px 7px;border-radius:7px}
  .cmp-nums i.up{background:var(--tm-teal-piel);color:var(--tm-teal-tx)}
  .cmp-nums i.dn{background:var(--tm-rosa-piel);color:var(--tm-rosa-tx)}
  .cmp-ritmo{margin:10px 0 0;font-size:14px;color:var(--ink,#4A434F);line-height:1.5}
  .cmp-lec{margin:9px 0 0;font-size:14px;line-height:1.5;color:var(--ink,#4A434F);
    border-left:2px solid var(--tm-teal);padding-left:11px}
  .cmp-nota{font-size:14px;color:var(--muted);line-height:1.5;margin:10px 0 0}

  /* — historial — */
  .hplan{border:1px solid var(--line);border-radius:var(--tm-r);margin-bottom:9px;overflow:hidden}
  .hp-s{list-style:none;display:flex;align-items:center;gap:10px;min-height:56px;
    padding:12px 15px;cursor:pointer}
  .hp-s::-webkit-details-marker{display:none}
  .hp-v{font-size:15px;font-weight:600;color:var(--tinta)}
  .hp-f, .hp-m, .hp-est{font-size:14px;color:var(--muted)}
  .hp-est{margin-left:auto}
  .hplan > *:not(.hp-s){margin-left:15px;margin-right:15px}
  .hplan > *:last-child{margin-bottom:15px}
  .hp-t{font-size:15px;font-weight:600;color:var(--tinta);margin:0 0 6px}
  .hp-p, .hp-lec, .hp-movio, .hp-vale, .hp-ev{font-size:14px;line-height:1.5;color:var(--ink,#4A434F)}
  .hp-lec{border-left:2px solid var(--tm-teal);padding-left:11px;margin:8px 0 0}
  .hp-ev{display:inline-flex;align-items:center;justify-content:center;min-height:44px;
    border:1px solid var(--line);background:transparent;color:var(--tinta);font-family:inherit;
    font-weight:600;padding:0 15px;border-radius:var(--tm-r-bt);cursor:pointer;margin-top:10px}
  .hp-ev:hover{border-color:var(--tm-rosa);color:var(--tm-rosa-tx)}
  .hp-ev:focus-visible{outline:2px solid var(--tinta);outline-offset:2px}

  /* — opciones delicadas, al final y plegadas — */
  .plan-op{display:flex;flex-direction:column;gap:9px}
  .plan-op a{display:flex;text-decoration:none;align-items:center;justify-content:center;gap:9px;min-height:52px;
    border:1px solid var(--line);border-radius:var(--tm-r-bt);background:transparent;
    color:var(--tinta);font-family:inherit;font-size:16px;font-weight:600;cursor:pointer}
  .plan-op a:hover{border-color:var(--tm-rosa);color:var(--tm-rosa-tx)}
  .plan-op a:focus-visible{outline:2px solid var(--tinta);outline-offset:2px}
  .plan-op a .ic{width:18px;height:18px;stroke-width:2}
  .plan-op p{font-size:14px;line-height:1.5;color:var(--muted);margin:2px 0 0}

  .plan-obs{font-size:15px;line-height:1.55;color:var(--tm-teal-tx);background:var(--tm-teal-piel);
    border-radius:var(--tm-r);padding:13px 15px;margin:0 0 14px}
  .plan-obs b{color:var(--tm-teal-tx)}

  @media (min-width:1000px){
    .plan{max-width:760px}
    .plan h1{font-size:34px}
    .cmp{flex-direction:row;align-items:flex-start}
    .cmp-p{flex:1;min-width:0}
  }

  /* ══ CAPA 1 · TU META ═════════════════════════════════════════════════
     El sistema de la lámina aprobada. Tres reglas lo gobiernan:

       1. UNA sola voz grande por pantalla. La meta es un dato, no un titular.
       2. El color contesta «¿me toca a mí?» antes de leer — pero NUNCA solo:
          la pastilla lleva siempre la palabra, porque hay quien no distingue
          el color y porque una pastilla muda no se puede leer en voz alta.
       3. Nada de contenido por debajo de 14px.

     Los tonos de marca se separan por CONTRASTE MEDIDO. Blanco sobre el rosa
     #EF4375 da 3.66:1 y no llega al 4.5 de AA; el mismo rosa hondo da 4.90.
     Asi que el rosa y el teal de marca se quedan para lo que NO lleva texto
     -el punto, el icono, la barra- y para texto y botones entran sus versiones
     hondas. Sigue siendo el color de Crecer, y se lee. */
  .ah{
    --tm-rosa:#EF4375;        /* marca · nunca detras de texto chico */
    --tm-rosa-tx:#C81E52;     /* 5.50:1 sobre crema */
    --tm-rosa-bt:#D42A5C;     /* 4.90:1 con texto blanco */
    --tm-rosa-bt-h:#B81F4C;
    --tm-rosa-piel:#FDF0F4;
    --tm-teal:#00A49F;
    --tm-teal-tx:#00726F;     /* 5.69:1 sobre crema */
    --tm-teal-piel:#EDF7F6;
    --tm-aviso:#8A5310;
    --tm-aviso-piel:#FBF3E7;
    --tm-r:12px;
    --tm-r-bt:10px;
    max-width:560px;margin:0 auto;padding-bottom:var(--ah-zona, 20px);
  }

  /*  AYUDA SE APARTA CUANDO ASOMA LA COLA.
      El boton flota en la franja de 78-121px sobre el borde inferior, que es
      justo donde cae la ultima fila de enlaces cuando la pagina termina. A
      360x800 los tapaba y ya no quedaba scroll para moverlos: no habia forma
      de pulsarlos. Se aparta quien esta de mas, y vuelve solo al subir. */
  body .ay-fab{transition:transform .2s ease, opacity .2s ease}
  body.ah-cola .ay-fab{transform:translateY(calc(100% + 96px));opacity:0;pointer-events:none}
  /*  Aqui vivia `.mt-volver`, la salida de la capa 1 antes del rediseño. La
      sustituyeron `.plan-volver` y `.wz-salir`, que ademas DICEN si lo escrito
      se guarda. Se quita para que nadie la reviva creyendo que sigue viva. */

  /* — LA BARRA DE CONTEXTO · un dato, no un titular — */
  .tm-meta{border-bottom:1px solid var(--line);padding-bottom:14px;margin-bottom:20px}
  .tm-meta-fila{display:flex;align-items:center;gap:7px}
  .tm-meta-fila .ic{width:15px;height:15px;flex:none;color:var(--muted);stroke-width:1.9}
  .tm-obj-nom{font-size:14px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;
    color:var(--muted)}
  .tm-dias{margin-left:auto;font-size:14px;color:var(--muted)}
  /*  «REGISTRADOS» VIAJA PEGADO AL NUMERO.
      Vivia tres renglones mas abajo, en un parrafo que nadie leia, y quien no
      lo leia entendia que Crecer contaba TODOS sus pedidos. La palabra va aqui,
      donde no se puede saltar. */
  .tm-cifra{display:flex;align-items:baseline;gap:6px;margin-top:7px;flex-wrap:wrap}
  .tm-cifra b{font-size:23px;font-weight:700;color:var(--tinta);line-height:1.1;
    font-variant-numeric:tabular-nums;letter-spacing:-.02em}
  .tm-cifra .et{font-size:14px;color:var(--ink,#4A434F);font-weight:500}
  .tm-cifra .de{font-size:14px;color:var(--muted)}
  /*  LA BARRA SE QUEDA DEFINIDA Y CASI NUNCA SE PINTA, A PROPOSITO.
      Una barra AFIRMA de un vistazo «vas por aqui de un total que conozco», y
      con cobertura parcial Crecer no conoce el total: solo cuenta lo que pasa
      por dentro. Quien decide si se puede afirmar es el compositor
      (puedeAfirmarProgreso), no esta hoja. El dia que la cobertura sea
      completa, la barra ya esta aqui. */
  .tm-barra{display:block;height:4px;border-radius:99px;background:var(--line);
    margin-top:10px;overflow:hidden}
  .tm-barra b{display:block;height:100%;border-radius:99px;background:var(--tm-teal)}
  .tm-lim{display:flex;gap:8px;align-items:center;font-size:14px;line-height:1.4;
    color:var(--tm-aviso);background:var(--tm-aviso-piel);border-radius:9px;
    padding:8px 10px;margin-top:10px}
  .tm-lim .ic{width:15px;height:15px;flex:none;stroke-width:2}

  /* — LA VUELTA · que paso con lo anterior — */
  .tm-vuelta{display:flex;gap:9px;align-items:flex-start;background:var(--tm-teal-piel);
    border-radius:var(--tm-r);padding:12px 14px;font-size:15px;line-height:1.45;
    color:var(--tm-teal-tx);margin:0 0 18px}
  .tm-vuelta .ic{width:18px;height:18px;flex:none;margin-top:1px;stroke-width:2}
  .tm-vuelta b{color:var(--tm-teal-tx);font-weight:700}

  /* — EL TURNO · se dice, no se grita —
     Versalitas espaciadas es el tic de la interfaz corporativa. Esto va en caja
     normal, como habla el corillo, dentro de una pastilla de su color. */
  .tm-turno{display:inline-flex;align-items:center;gap:7px;font-size:14px;font-weight:600;
    color:var(--tm-rosa-tx);background:var(--tm-rosa-piel);border-radius:99px;
    padding:6px 13px 6px 11px;margin-bottom:12px}
  .tm-turno u{width:7px;height:7px;border-radius:50%;background:var(--tm-rosa);
    display:block;flex:none}
  .tm-turno.mio{color:var(--tm-teal-tx);background:var(--tm-teal-piel)}
  .tm-turno.mio u{background:var(--tm-teal)}
  .tm-turno.limite{color:var(--tm-aviso);background:var(--tm-aviso-piel)}
  .tm-turno.limite .ic{width:16px;height:16px;flex:none;stroke-width:2}

  /* — LA FRASE · la unica voz grande — */
  .tm-frase{font-family:var(--font-display,'Poppins',sans-serif);font-weight:700;
    font-size:26px;line-height:1.22;letter-spacing:-.022em;color:var(--tinta);
    margin:0;text-wrap:balance}
  /*  Cuando el texto se alarga, la voz cede tamaño ANTES que empujar el boton
      fuera de la pantalla. Lo pone el guion del final, medido. */
  .ah.tm-largo .tm-frase{font-size:22px}

  /* — EL OBJETO · la unica superficie enmarcada del centro — */
  .tm-obj{margin-top:18px;border:1px solid var(--line);border-radius:var(--tm-r);
    display:flex;gap:12px;align-items:center;padding:10px;background:var(--card,#fff)}
  .tm-obj-ic{width:56px;height:56px;border-radius:9px;flex:none;background:#F1ECE6;
    display:flex;align-items:center;justify-content:center;color:#A79C90}
  .tm-obj-ic .ic{width:22px;height:22px;stroke-width:1.7}
  .tm-obj-tx{min-width:0}
  .tm-obj-tx b{display:block;font-size:15px;line-height:1.3;color:var(--tinta);font-weight:600;
    overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
  .tm-obj-tx span{display:block;font-size:14px;line-height:1.4;color:var(--muted);margin-top:3px}

  /* — LA ACCION · una sola, y se ve que se puede pulsar — */
  .tm-btn{display:flex;align-items:center;justify-content:center;gap:9px;width:100%;
    min-height:52px;border:0;border-radius:var(--tm-r-bt);background:var(--tm-rosa-bt);
    color:#fff;font-family:inherit;font-size:17px;font-weight:700;letter-spacing:-.01em;
    margin-top:18px;cursor:pointer;text-decoration:none;
    transition:background .14s ease, transform .1s ease}
  .tm-btn:hover{background:var(--tm-rosa-bt-h)}
  .tm-btn:active{transform:translateY(1px)}
  .tm-btn:focus-visible{outline:2px solid var(--tinta);outline-offset:2px}
  .tm-btn:disabled{opacity:.55;cursor:default;transform:none}
  .tm-btn .ic{width:18px;height:18px;flex:none;stroke-width:2}
  .tm-btn.teal{background:var(--tm-teal-tx)}
  .tm-btn.teal:hover{background:#005E5B}
  /*  DE LINEA CUANDO NO HAY NADA QUE DECIDIR. No es una primaria mas floja:
      es que mirar no es decidir, y el peso del boton lo tiene que decir. */
  .tm-btn.linea{background:transparent;color:var(--tinta);border:1px solid var(--line)}
  .tm-btn.linea:hover{background:var(--crema,#faf7f4)}
  .tm-cons{font-size:15px;line-height:1.5;color:var(--muted);margin:11px 0 0}

  /* — LAS CAPAS · siempre en el mismo sitio, siempre pulsables — */
  .tm-capas{margin-top:24px;border-top:1px solid var(--line)}
  .tm-ac{border-bottom:1px solid var(--line)}
  .tm-ac:last-child{border-bottom:0}
  .tm-ac > summary{list-style:none;display:flex;align-items:center;gap:10px;
    min-height:56px;padding:0 2px;cursor:pointer;
    font-size:16px;font-weight:600;color:var(--tinta)}
  .tm-ac > summary::-webkit-details-marker{display:none}
  .tm-ac > summary:hover{color:var(--tm-rosa-tx)}
  .tm-ac > summary:focus-visible{outline:2px solid var(--tinta);outline-offset:2px;border-radius:8px}
  .tm-ac .cta{margin-left:auto;font-size:14px;color:var(--muted);font-weight:500}
  .tm-ac .chev{width:16px;height:16px;flex:none;color:var(--muted);stroke-width:2;
    transition:transform .18s ease}
  .tm-ac[open] > summary .chev{transform:rotate(180deg)}
  .tm-ac .dentro{padding:2px 2px 18px;animation:tmAbre .18s ease}
  @keyframes tmAbre{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:none}}
  .tm-ac p{font-size:15px;line-height:1.55;color:var(--muted);margin:0}
  .tm-pasos{display:flex;flex-direction:column;gap:13px}
  .tm-paso{display:flex;gap:10px;align-items:flex-start}
  .tm-paso .ic{width:18px;height:18px;flex:none;margin-top:2px;stroke-width:1.9;
    color:var(--tm-teal-tx)}
  .tm-paso.tuyo .ic{color:var(--tm-rosa-tx)}
  .tm-paso div{min-width:0}
  .tm-paso b{display:block;font-size:15px;line-height:1.35;color:var(--tinta);font-weight:600}
  .tm-paso span{display:block;font-size:14px;line-height:1.4;color:var(--muted);margin-top:2px}

  /* — pasos de la inversion y confirmaciones (estados excepcionales) — */
  .ah-cual{border:1px solid var(--line);border-radius:var(--tm-r-bt);padding:11px 13px;
    margin:0 0 12px;background:var(--card,#fff)}
  .ah-cual .et{display:block;font-size:14px;font-weight:600;letter-spacing:.06em;
    text-transform:uppercase;color:var(--muted);margin-bottom:5px}
  .ah-cual b{display:block;font-size:16px;line-height:1.4;color:var(--tinta);font-weight:600}
  .ah-cual small{display:block;font-size:14px;line-height:1.45;color:var(--muted);margin-top:4px}
  .ah-cual.sin{background:var(--tm-aviso-piel);border-color:transparent}
  .ah-cual.sin b{color:var(--tm-aviso);font-weight:600}
  .ah-cual.sin small{color:var(--tm-aviso)}
  .ah-monto span{display:block;font-size:14px;line-height:1.45;color:var(--muted);
    font-weight:400;margin-top:3px}
  .ah-cierre{display:flex;flex-direction:column;gap:8px;margin-top:12px}
  .ah-nomarca{display:flex;align-items:center;justify-content:center;gap:8px;min-height:48px;
    border:1px solid var(--line);border-radius:var(--tm-r-bt);background:transparent;
    font-family:inherit;font-size:15px;font-weight:600;color:var(--muted);cursor:pointer}
  .ah-nomarca:hover{border-color:var(--raya-firme,#D8D3CC);color:var(--tinta)}
  .ah-nomarca:focus-visible{outline:2px solid var(--tinta);outline-offset:2px}
  .ah-nomarca .ic{width:16px;height:16px;stroke-width:2}
  .ah-pasos{margin-top:16px;border-top:1px solid var(--line);padding-top:14px}
  .ah-monto{font-size:15px;margin:0 0 10px;color:var(--tinta)}
  .ah-pasos ol{margin:0 0 12px;padding-left:20px}
  .ah-pasos li{font-size:15px;line-height:1.5;color:var(--ink,#4A434F);margin-bottom:7px}
  .ah-aviso{font-size:14px;line-height:1.45;color:var(--muted);margin:0 0 14px}

  /* — el trato del plan nuevo — */
  .tm-trato{margin:18px 0 0;padding:14px 15px;border-radius:var(--tm-r);
    background:var(--crema,#FAF7F4);border:1px solid var(--line)}
  .tm-estr{font-size:16px;line-height:1.5;color:var(--tinta);margin:0 0 12px;font-weight:600}
  .tm-reparto{display:flex;gap:10px}
  .tm-reparto div{flex:1;min-width:0;background:var(--card,#fff);border:1px solid var(--line);
    border-radius:9px;padding:10px 11px}
  .tm-reparto b{display:block;font-size:23px;line-height:1.1;color:var(--tinta);font-weight:700;
    font-variant-numeric:tabular-nums}
  .tm-reparto span{display:block;font-size:14px;line-height:1.35;color:var(--muted);margin-top:2px}
  .tm-reparto .mia b{color:var(--tm-teal-tx)}
  .tm-reparto .tuya b{color:var(--tm-rosa-tx)}
  .tm-pide{font-size:14px;line-height:1.5;color:var(--ink,#4A434F);margin:11px 0 0}
  .tm-pide b{color:var(--tinta)}

  /* — CAPA 3 · filas, no texto en negrita —
     Eran dos lineas sin recuadro, sin subrayado y sin color de enlace: las
     puertas al plan y al corillo no invitaban a nada. Ahora son filas
     pulsables de 56px con su flecha, como los acordeones. */
  .tm-mas{display:flex;flex-direction:column;margin-top:2px;border-top:1px solid var(--line)}
  .tm-mas a{display:flex;align-items:center;gap:11px;min-height:56px;
    text-decoration:none;color:var(--tinta);font-size:16px;font-weight:600;
    border-bottom:1px solid var(--line);padding:0 2px}
  .tm-mas a:last-child{border-bottom:0}
  .tm-mas a:hover{color:var(--tm-rosa-tx)}
  .tm-mas a:focus-visible{outline:2px solid var(--tinta);outline-offset:2px;border-radius:8px}
  .tm-mas a .ic{width:18px;height:18px;flex:none;color:var(--muted);stroke-width:1.9}
  .tm-mas a .ic:last-child{margin-left:auto;width:16px;height:16px}

  /* — la salida cuando la jugada que manda es imposible para el dueño — */
  .tm-nopuedo{display:flex;align-items:center;justify-content:center;gap:8px;min-height:48px;
    margin-top:14px;border:1px solid var(--line);border-radius:var(--tm-r-bt);
    background:transparent;font-size:15px;font-weight:600;color:var(--muted);
    text-decoration:none}
  .tm-nopuedo:hover{border-color:var(--tm-aviso);color:var(--tm-aviso);
    background:var(--tm-aviso-piel)}
  .tm-nopuedo:focus-visible{outline:2px solid var(--tinta);outline-offset:2px}
  .tm-nopuedo .ic{width:16px;height:16px;stroke-width:2}

  .ah-toast{position:fixed;left:50%;bottom:26px;transform:translate(-50%,20px);opacity:0;
    background:var(--tinta);color:#fff;font-size:14px;font-weight:700;padding:12px 18px;
    border-radius:12px;pointer-events:none;transition:.2s;z-index:80;max-width:92vw}
  .ah-toast.on{opacity:1;transform:translate(-50%,0)}

  /* ══ ESCRITORIO ══════════════════════════════════════════════════════
     No es el movil estirado ni el movil pegado a la izquierda con un vacio
     al lado: es la MISMA columna, CENTRADA en el espacio que deja la barra
     lateral, y con la capa 2 abierta porque aqui hay alto para ella. */
  @media (min-width:1000px){
    .ah{max-width:680px}
    .tm-frase{font-size:36px}
    .tm-meta{border:1px solid var(--line);border-radius:var(--tm-r);
      padding:14px 18px;margin-bottom:26px}
    .tm-obj{padding:12px;gap:15px}
    .tm-obj-ic{width:96px;height:96px}
    .tm-obj-ic .ic{width:30px;height:30px}
    .tm-obj-tx b{font-size:17px}
    .tm-btn{max-width:340px;min-height:54px}
    .tm-capas{margin-top:28px}
  }

  /*  El bloque viejo de PLAN CONTRA PLAN y del HISTORIAL vivia aqui, DESPUES
      del de la capa 1. En CSS gana el ultimo, asi que pisaba entero el
      sistema nuevo. Sus estilos estan ahora arriba, con el resto del plan.
      Los nombres de clase se conservan porque el guion engancha por ellos.
   */
  @media(max-width:680px){
    /* Los 4 números del récord no caben en una fila de 360px: 2x2 y se leen. */
    .hp-nums{grid-template-columns:repeat(2,minmax(0,1fr))}
    .hplan summary{padding:12px 13px}
    .hp-est{margin-left:0}
    /*  Aqui vivian cuatro reglas sueltas del wizard (.obj-grid, .wz-q, .mt-num
        y .wz-nav .btn-p). Su hoja se fue con el rediseno de la capa 2 y quedaron
        huerfanas: dos apuntaban a clases que ya no existen y .wz-q peleaba con la
        nueva. El wizard entero —marca, estilos y guion— vive en _meta_wizard.php. */
  }
</style>

<?php if ($meta && $vista === 'ajustar'): /* ══════ AJUSTAR LA META ══════ */ ?>

<?php require __DIR__ . '/_meta_ajustar.php'; ?>

<?php elseif ($meta && $vista === 'sustituir'): /* ══════ SUSTITUIR UNA JUGADA ══════ */ ?>

<?php require __DIR__ . '/_meta_sustituir.php'; ?>

<?php elseif ($meta && in_array($vista, ['plan-nuevo', 'cambiar'], true)):
        /* ══════ LAS DOS DELICADAS · wizards propios, no un confirm() ══════ */ ?>

<?php require __DIR__ . '/_meta_opciones.php'; ?>

<?php elseif (!$meta && $vista === 'wizard'): /* ══════════ WIZARD ══════════ */ ?>

<?php require __DIR__ . '/_meta_wizard.php'; ?>

<?php elseif ($meta && $vista === 'plan'): /* ══════ CAPA 2 · EL PLAN COMPLETO ══════ */
  $def = meta_objetivo_def((string)$meta['objetivo']);
  //  EL MISMO CONTRATO DE COBERTURA QUE LA CAPA 1, y literalmente el mismo
  //  objeto: $mt_estado ya está compuesto arriba, antes de las vistas. Aquí
  //  no hay una segunda interpretación de si se puede afirmar el progreso —
  //  hay una sola, y vive en el compositor.
  //
  //  Antes esta vista pintaba barra, «% logrado», «Vas en ritmo», «Vas
  //  atrasado» y el ritmo diario SIN preguntar nada. La capa 1 respetaba el
  //  contrato y la capa 2 lo rompía en la misma pantalla, a un toque.
  $puede_afirmar = $mt_estado->puedeAfirmarProgreso();
  [$sust_p, $part_p] = $mt_unidad((string)$meta['objetivo']);
  $numf = fn($v) => rtrim(rtrim(number_format((float)$v, 2), '0'), '.');
?>
<div class="plan">

  <?php /* LA SALIDA, PRIMERO Y VISIBLE. Una capa sin puerta de vuelta es una
           capa donde el dueño se queda encerrado con el botón del navegador.
           Y conserva la marca: volver no puede dejarte en otro negocio. */ ?>
  <a href="<?= $BASE ?>/meta.php?marca=<?= $marca_id ?>" class="plan-volver">
    <?= ico('chev-der') ?>Volver a lo que toca ahora</a>

  <h1>Tu plan</h1>

  <?php /* ── RESUMEN HONESTO ─────────────────────────────────────────── */ ?>
  <section class="plan-res">
    <div class="fila">
      <?= ico('compass') ?>
      <span class="obj"><?= $h($sust_p) ?></span>
      <?php if ($prog['dias_rest'] !== null): ?>
        <span class="dias"><?= (int)$prog['dias_rest'] > 0
            ? 'quedan ' . (int)$prog['dias_rest'] . ' días' : 'sin días por delante' ?></span>
      <?php endif; ?>
    </div>
    <div class="cifra">
      <?php if ($prog['medible'] && $prog['actual'] !== null): ?>
        <b><?= $h($numf($prog['actual'])) ?></b>
        <span class="et"><?= $h($mt_fuente((string)$meta['objetivo'], $part_p)) ?></span>
      <?php else: ?>
        <span class="et">Sin señal todavía</span>
      <?php endif; ?>
      <?php if ($meta['cantidad'] !== null): ?>
        <span class="de">· de <?= $h($numf($meta['cantidad'])) ?></span>
      <?php endif; ?>
    </div>

    <?php /* Barra, porcentaje y ritmo SOLO si el compositor certifica que se
             puede afirmar. Con cobertura parcial no se pintan — y no es que se
             borren los textos: es que no se han ganado. */ ?>
    <?php if ($puede_afirmar && $prog['pct'] !== null): ?>
      <i class="plan-barra"><i style="width:<?= max(2, min(100, (int)$prog['pct'])) ?>%"></i></i>
      <p class="plan-ritmo"><b><?= (int)$prog['pct'] ?>% logrado</b><?php
        if ($prog['al_dia'] === true): ?> · vas en ritmo<?php
        elseif ($prog['al_dia'] === false): ?> · vas atrasado<?php endif; ?><?php
        if (!empty($prog['ritmo_dia'])): ?> · hacen falta como
          <b><?= $h(number_format((float)$prog['ritmo_dia'], 1)) ?></b> al día<?php endif; ?></p>
    <?php elseif (!$prog['medible']): ?>
      <p class="nomed"><b>Todavía no puedo contarte esto solo.</b>
        <?= $h($prog['como_medir'] !== '' ? $prog['como_medir']
              : 'Cuando haya datos reales, aquí te muestro cómo vas. No te voy a inventar un número.') ?></p>
    <?php else: ?>
      <p class="nomed"><?= $h($mt_como_voy($mt_estado, $mt_snap, $mt_unidad((string)$meta['objetivo']), (string)$meta['objetivo'])) ?></p>
    <?php endif; ?>
  </section>

  <!-- Columna derecha: diagnóstico + jugadas -->
  <div>
    <?php if ($tacticas): ?>
      <div class="plan-gt" style="margin-bottom:0">
        <h2>Las jugadas para lograrlo</h2>
        <?php if ($prog_plan && $prog_plan['total'] > 0): ?>
          <?php /*  Este SI se puede afirmar y no lleva barra a proposito: las
                    jugadas hechas las contamos nosotros enteras, no dependen de
                    lo que reporte nadie. Pero una barra aqui, tres dedos debajo
                    de la de la meta, se leeria como si fuera la misma cosa. */ ?>
          <span class="n"><?= (int)$prog_plan['hechas'] ?> de <?= (int)$prog_plan['total'] ?> hechas</span>
        <?php endif; ?>
        <u></u>
      </div>
      <?php if ($prog_plan && $prog_plan['completo']): ?>
        <div class="plan-obs">
          <b>Cumpliste el plan completo.</b> Ahora el corillo está pendiente de los números de esos posts.
          Cuando Instagram y Facebook reporten, el Analista te dice si funcionó y qué cambiar — no antes,
          para no juzgar con datos que todavía no existen.
        </div>
      <?php endif; ?>

      <?php
      /*  ── HECHO · AHORA · DESPUÉS ──────────────────────────────────────
          Seis jugadas abiertas eran 8.000px de scroll en un teléfono, todas
          con el mismo peso y sin señal de por dónde empezar. Ahora son tres
          grupos y UNA sola abierta: la de turno.

          Los tres salen de datos reales, no de un orden inventado:
            hecha        → estado 'hecha'
            ahora        → la que meta_tactica_de_turno() señala
            después      → el resto de las abiertas, en su orden

          Si no hay jugada de turno —todo hecho, o el plan recién nacido— el
          grupo «Ahora» no se pinta. No se asciende una cualquiera a «ahora»
          solo para que el hueco no se vea. */
      $__turno = meta_tactica_de_turno($pdo, $meta);
      $__turno_id = $__turno ? (int)$__turno['id'] : 0;

      $g_ahora = []; $g_hecho = []; $g_despues = [];
      foreach ($tacticas as $t) {
          if ((string)$t['estado'] === 'hecha')            { $g_hecho[] = $t; continue; }
          if ((int)$t['id'] === $__turno_id)               { $g_ahora[] = $t; continue; }
          $g_despues[] = $t;
      }

      /*  Pinta un grupo entero. La tarjeta es la misma en los tres —vive en
          _meta_jugada.php— para que no se despeguen con el tiempo. */
      $pintar = function (array $lista, bool $abierta) use ($pdo, $marca_id, $BASE, $h, $__turno_id) {
          foreach ($lista as $t) {
              $tipo_lbl = ['contenido'=>'Contenido','distribucion'=>'Difusión','pauta'=>'Anuncio pagado',
                           'oferta'=>'Oferta','alianza'=>'Alianza','operacion'=>'Cómo operar'][$t['tipo']] ?? $t['tipo'];
              $clase = (string)($t['clase'] ?? 'produccion');
              $jp    = jugada_progreso($pdo, $t);
              $hecha = $t['estado'] === 'hecha';
              $es_turno = $abierta && ((int)$t['id'] === $__turno_id) && !$hecha;
              //  Resumen corto para cuando está plegada: que se entienda sin abrir.
              if ($hecha)                          $mini = 'Hecha';
              elseif ($clase === 'regla')          $mini = 'Siempre';
              elseif ($clase === 'accion_dueno')   $mini = 'La haces tú';
              elseif ((int)$jp['espera_video'] > 0) $mini = 'Falta tu video';
              elseif ((int)$jp['creadas'] === 0)   $mini = (int)$jp['meta'] . ($jp['meta'] == 1 ? ' pieza' : ' piezas');
              else                                 $mini = (int)$jp['publicadas'] . '/' . (int)$jp['meta'] . ' publicadas';
              require __DIR__ . '/_meta_jugada.php';
          }
      };
      ?>

      <?php if ($g_ahora): ?>
        <section class="plan-grupo">
          <div class="plan-gt"><span>Ahora</span><u></u></div>
          <?php $pintar($g_ahora, true); ?>
        </section>
      <?php endif; ?>

      <?php if ($g_hecho): ?>
        <section class="plan-grupo">
          <div class="plan-gt"><span>Hecho</span><span class="n"><?= count($g_hecho) ?></span><u></u></div>
          <?php $pintar($g_hecho, false); ?>
        </section>
      <?php endif; ?>

      <?php if ($g_despues): ?>
        <section class="plan-grupo">
          <div class="plan-gt"><span>Después</span><span class="n"><?= count($g_despues) ?></span><u></u></div>
          <?php $pintar($g_despues, false); ?>
        </section>
      <?php endif; ?>
    <?php else: ?>
      <div class="card">
        <p style="margin:0;font-size:14px;color:var(--muted);line-height:1.55">
          La Estratega todavía no dejó las jugadas. Dale a <b>Rehacer el plan</b> y lo arma de nuevo.</p>
      </div>
    <?php endif; ?>

    <?php /* ── LAS CAPAS PLEGADAS ─────────────────────────────────────────
             Diagnóstico, comparación, aprendizaje, historial y las opciones
             delicadas. Todo lo que NO es «qué toca en este plan» vive aquí,
             plegado y en este orden. Nada de esto puede competir con la
             jugada de ahora, y nada de esto se ha eliminado: se ha bajado
             una capa.

             Las opciones van LAS ÚLTIMAS y a propósito: «Empezar un plan
             nuevo» y «Cambiar de meta» estaban pegadas al progreso, que es
             justo donde el dedo va a mirar cómo va el mes. */ ?>
    <div class="plan-capas">

      <?php if (trim((string)$meta['diagnostico']) !== ''): ?>
        <details class="plan-ac">
          <summary>Lo que dice la Estratega<?= ico('chev-abajo') ?></summary>
          <div class="dentro diag">
            <div class="qui"><?= ico('sparkles') ?> Su lectura de tu negocio</div>
            <p><?= $h($meta['diagnostico']) ?></p>
            <?php if (!empty($meta['veredicto'])): ?>
              <span class="vered <?= $h($meta['veredicto']) ?>">
                <?= $meta['veredicto']==='alcanzable' ? 'Se puede'
                    : ($meta['veredicto']==='ambiciosa' ? 'Es ambiciosa, pero se pelea'
                    : 'Muy cuesta arriba — mira lo que propongo') ?>
              </span>
            <?php endif; ?>
          </div>
        </details>
      <?php endif; ?>

      <?php if (trim((string)$meta['contexto']) !== ''): ?>
        <details class="plan-ac">
          <summary>Lo que me contaste<?= ico('chev-abajo') ?></summary>
          <div class="dentro"><p><?= $h($meta['contexto']) ?></p></div>
        </details>
      <?php endif; ?>

      <?php /* ── COMPARAR PLANES ──────────────────────────────────────────
               El historial ya guardaba el récord de cada plan, pero uno debajo
               del otro. Aquí se ven juntos y con su delta, que es lo que
               contesta la pregunta de verdad: ¿este plan lo está haciendo
               mejor que el anterior? Solo aparece con dos o más. */ ?>
      <?php $comp = $meta ? meta_planes_comparar($pdo, (int)$meta['id']) : []; ?>
      <?php if (count($comp) >= 2): ?>
        <details class="plan-ac">
          <summary>Plan contra plan <span class="cta"><?= count($comp) ?></span><?= ico('chev-abajo') ?></summary>
          <div class="dentro">
            <div class="cmp">
              <?php foreach ($comp as $c): $ps = $c['por_semana'] ?? null; ?>
                <div class="cmp-p<?= $c['activo'] ? ' on' : '' ?>">
                  <div class="cmp-h">
                    <b>Plan #<?= (int)$c['version'] ?></b>
                    <span class="cmp-est"><?= $c['activo'] ? 'en curso' : ($c['estado']==='completado'?'cumplido':'reemplazado') ?></span>
                    <span class="cmp-d"><?= $c['dias'] < 1 ? 'menos de un día' : (rtrim(rtrim(number_format($c['dias'],1),'0'),'.') . ($c['dias']==1?' día':' días')) ?></span>
                  </div>
                  <?php if ($c['corto']): ?>
                    <p class="cmp-corto">Ventana muy corta para juzgarla — no se compara.</p>
                  <?php endif; ?>
                  <div class="cmp-nums">
                    <div><b><?= $c['hechas'] ?>/<?= $c['jugadas'] ?></b><span>jugadas</span></div>
                    <div><b><?= $c['publicadas'] ?></b><span>publicadas</span>
                      <?php if (isset($c['delta']['publicadas'])): ?>
                        <i class="<?= $c['delta']['publicadas'] >= 0 ? 'up' : 'dn' ?>"><?= $c['delta']['publicadas'] >= 0 ? '+' : '' ?><?= $c['delta']['publicadas'] ?></i>
                      <?php endif; ?>
                    </div>
                    <div><b><?= $c['alcance'] !== null ? number_format((float)$c['alcance']) : '—' ?></b><span>alcance</span>
                      <?php if (isset($c['delta']['alcance'])): ?>
                        <i class="<?= $c['delta']['alcance'] >= 0 ? 'up' : 'dn' ?>"><?= $c['delta']['alcance'] >= 0 ? '+' : '' ?><?= number_format((float)$c['delta']['alcance']) ?></i>
                      <?php endif; ?>
                    </div>
                    <div><b><?= $c['movio'] !== null ? $h(meta_fmt((float)$c['movio'], (string)$c['objetivo'])) : '—' ?></b><span>movió la meta</span>
                      <?php if (isset($c['delta']['movio'])): ?>
                        <i class="<?= $c['delta']['movio'] >= 0 ? 'up' : 'dn' ?>"><?= $c['delta']['movio'] >= 0 ? '+' : '' ?><?= $h(meta_fmt((float)$c['delta']['movio'], (string)$c['objetivo'])) ?></i>
                      <?php endif; ?>
                    </div>
                  </div>
                  <?php if ($ps && !$c['corto']): ?>
                    <p class="cmp-ritmo">Ritmo: <b><?= $ps['publicadas'] ?></b> publicadas por semana<?php
                      if ($ps['movio'] !== null): ?> · <b><?= $h(meta_fmt((float)$ps['movio'], (string)$c['objetivo'])) ?></b> por semana<?php endif; ?></p>
                  <?php endif; ?>
                  <?php if ($c['leccion'] !== ''): ?>
                    <p class="cmp-lec"><?= $h($c['leccion']) ?></p>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
            <p class="cmp-nota">Una raya (—) quiere decir <b>sin dato todavía</b>, no cero: Instagram y
              Facebook reportan con retraso y no se juzga con números que aún no existen.</p>
          </div>
        </details>
      <?php endif; ?>

      <?php /*  EL ANCLA SE CONSERVA. El estado L enlaza a
                meta.php?vista=plan#aprendizaje; si el ancla desaparece, ese
                enlace cae en el vacio. Y se abre sola cuando se llega por el
                salto: un acordeon cerrado al final de un ancla es una pantalla
                muda. Lo hace el guion del final, mirando location.hash. */ ?>
      <?php if ($historial): ?>
        <details class="plan-ac" id="aprendizaje">
          <summary>Planes anteriores <span class="cta"><?= count($historial) ?></span><?= ico('chev-abajo') ?></summary>
          <div class="dentro">
            <p>Cada plan guarda su propio récord: qué se hizo, qué se publicó y qué dejó.</p>

      <?php foreach ($historial as $hh):
        $p = $hh['plan']; $pr = $hh['prog']; $rs = $hh['res'];
        $vale = $p['funciono'] === null ? null : ((int)$p['funciono'] === 1);
      ?>
        <details class="hplan">
          <?php /*  La clase NO es decorativa: `.hp-s` es lo que le quita el
                    triangulo del navegador, le da los 56px de objetivo y lo
                    saca de los margenes de `.hplan > *:not(.hp-s)`. Sin ella
                    el sumario del historial era el unico acordeon de Tu Meta
                    que se pintaba distinto — y sin altura garantizada.  */ ?>
          <summary class="hp-s">
            <span class="hp-v">Plan #<?= (int)$p['version'] ?></span>
            <span class="hp-f"><?= $h(date('j/n', strtotime((string)$p['inicio_at']))) ?>
              — <?= $h(!empty($p['cierre_at']) ? date('j/n', strtotime((string)$p['cierre_at'])) : 'abierto') ?></span>
            <span class="hp-est <?= $p['estado']==='completado'?'ok':'' ?>">
              <?= $p['estado']==='completado' ? 'Cumplido' : ($p['estado']==='reemplazado' ? 'Reemplazado' : 'Cerrado') ?></span>
            <?php if ($vale === true): ?><span class="hp-vale si">Funcionó</span>
            <?php elseif ($vale === false): ?><span class="hp-vale no">No movió nada</span>
            <?php elseif (!empty($p['leccion'])): ?><span class="hp-vale nd">Sin evidencia</span><?php endif; ?>
          </summary>

          <div class="hp-body">
            <div class="hp-nums">
              <div><b><?= (int)$pr['hechas'] ?>/<?= (int)$pr['total'] ?></b><span>jugadas hechas</span></div>
              <div><b><?= (int)$rs['publicadas'] ?></b><span>posts publicados</span></div>
              <div><b><?= $rs['alcance'] !== null ? number_format((float)$rs['alcance']) : '—' ?></b><span>personas alcanzadas</span></div>
              <div><b><?= $rs['interacciones'] !== null ? number_format((float)$rs['interacciones']) : '—' ?></b><span>reacciones</span></div>
            </div>
            <?php if ($rs['movio'] !== null && !empty($rs['objetivo'])): ?>
              <p class="hp-movio">Mientras este plan estuvo activo entraron
                <b><?= $h(meta_fmt((float)$rs['movio'], (string)$rs['objetivo'])) ?></b>.</p>
            <?php endif; ?>

            <?php if (!empty($p['leccion'])): ?>
              <div class="hp-lec"><b>Lo que aprendió el corillo:</b> <?= $h($p['leccion']) ?></div>
            <?php else: ?>
              <div class="hp-lec pend">
                Todavía sin lección: <?= (int)$rs['publicadas'] === 0
                  ? 'este plan no llegó a publicarse, así que no hay nada que juzgar.'
                  : 'esperando que Instagram y Facebook reporten los números de sus posts.' ?>
                <?php if ((int)$rs['publicadas'] > 0): ?>
                  <button type="button" class="hp-ev" data-plan="<?= (int)$p['id'] ?>">Evaluarlo ahora</button>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <?php if ($hh['tac']): ?>
              <div class="hp-lista">
                <b>Las jugadas de este plan</b>
                <?php foreach ($hh['tac'] as $t): ?>
                  <div class="hp-t <?= $t['estado']==='hecha'?'ok':'' ?>">
                    <?= $t['estado']==='hecha' ? ico('check-circle') : ico('circle') ?>
                    <span><?= $h($t['titulo']) ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <?php if ($rs['posts']): ?>
              <div class="hp-lista">
                <b>Lo que se publicó</b>
                <?php foreach (array_slice($rs['posts'], 0, 8) as $po): ?>
                  <div class="hp-p">
                    <span class="hp-cap"><?= $h(mb_substr(trim((string)$po['caption']), 0, 74)) ?><?= mb_strlen((string)$po['caption']) > 74 ? '…' : '' ?></span>
                    <span class="hp-m">
                      <?= $po['estado']==='publicado'
                          ? ($po['alcance'] !== null ? number_format((float)$po['alcance']) . ' personas' : 'publicado')
                          : $h($po['estado']) ?>
                    </span>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </details>
      <?php endforeach; ?>
          </div>
        </details>
      <?php endif; ?>

      <details class="plan-ac">
        <summary>¿Qué significan las palabras raras?<?= ico('chev-abajo') ?></summary>
        <div class="dentro">
          <?php foreach ($glosario as $gt => $gd): ?>
            <p><b><?= $h(ucfirst($gt)) ?>:</b> <?= $h($gd) ?></p>
          <?php endforeach; ?>
        </div>
      </details>

      <?php /*  LA FECHA QUE PUEDE SERVIR.
                Va DESPUES de las jugadas y ANTES de las opciones delicadas: es
                una sugerencia, no una tarea, y ponerla arriba competiria con
                lo que de verdad toca hoy. Se pinta sola solo si hay alguna y
                si su esquema esta.  */ ?>
      <?php if (efem_disponible($pdo)) require __DIR__ . '/_meta_oportunidad.php'; ?>

      <?php /*  LAS OPCIONES DELICADAS, LAS ULTIMAS Y PLEGADAS.
                Estaban pegadas al progreso —justo donde el dedo va a mirar como
                va el mes— y son las dos cosas que mas asustan: rehacer el plan
                y cambiar de meta.

                Eran un confirm() del navegador. Ahora cada una abre SU wizard,
                que ensena lo que se mueve antes de moverlo. Los ids se
                conservan (#replan, #cerrar) porque la red de paridad engancha
                por ellos, pero ya no disparan nada: son puertas. */ ?>
      <details class="plan-ac">
        <summary>Opciones del plan<?= ico('chev-abajo') ?></summary>
        <div class="dentro">
          <div class="plan-op">
            <a id="replan" href="<?= $BASE ?>/meta.php?marca=<?= $marca_id ?>&amp;vista=plan-nuevo"><?= ico('refresh') ?> Empezar un plan nuevo</a>
            <p>Jugadas nuevas para esta misma meta. Antes de cambiar nada te enseno que
              se mueve y que se queda.</p>
            <?php if (meta_ajuste_disponible($pdo)): ?>
              <a id="ajustar" href="<?= $BASE ?>/meta.php?marca=<?= $marca_id ?>&amp;vista=ajustar"><?= ico('edit') ?> Ajustar esta meta</a>
              <p>Cambiar el número, la fecha o la inversión sin empezar de cero. Lo hecho no se toca.</p>
            <?php endif; ?>
            <a id="cerrar" href="<?= $BASE ?>/meta.php?marca=<?= $marca_id ?>&amp;vista=cambiar"><?= ico('target') ?> Cambiar de meta</a>
            <p>Escoges la proxima primero y despues cierro esta. Lo publicado no se toca.</p>
          </div>
        </div>
      </details>
    </div>
  </div>
</div>
<?php require __DIR__ . '/_meta_zona.php'; ?>

<script>
(function(){
  var CSRF=<?= json_encode(csrf_token()) ?>, MARCA=<?= (int)$marca_id ?>, URL=location.pathname+'?marca='+MARCA;
  function post(d){ var fd=new FormData(); fd.append('csrf',CSRF); for(var k in d) fd.append(k,d[k]);
    return fetch(URL,{method:'POST',body:fd}).then(function(r){return r.json();}); }

  //  UNA SOLA JUGADA ABIERTA, TAMBIEN EN ESCRITORIO.
  //  Aqui se abrian las SEIS con pantalla grande. Tenia sentido cuando el plan
  //  era una lista plana: esconder lo que cabe parecia quitarle al dueño la
  //  vista de conjunto. Ya no lo es — la vista de conjunto la dan los tres
  //  grupos (Hecho, Ahora, Despues) y el resumen de cada jugada plegada.
  //  Abrirlas todas devolvia el reguero, y ademas hacia de movil y escritorio
  //  dos productos distintos: la misma jerarquia en los dos, dice la regla.

  // ── "Que lo haga el corillo": produce TODO el contenido de la jugada ──
  //  Va por cola (tarda minutos) y se sondea. El dueño puede irse: cuando
  //  termine le llega la notificación.
  document.querySelectorAll('.jg-hacer').forEach(function(b){
    b.addEventListener('click', function(){
      var card=b.closest('.jg'), live=card.querySelector('.jg-live');
      card.querySelectorAll('.jg-hacer').forEach(function(x){ x.disabled=true; });
      live.className='jg-live on';
      live.innerHTML='<span class="pts"><span></span><span></span><span></span></span> '+
                     'El corillo está mirando lo que ya tienes y poniéndose a escribir…';
      post({accion:'ejecutar', id:b.dataset.id}).then(function(d){
        if(!d.ok || !d.job){
          live.className='jg-live on';
          live.textContent=d.err||'No pude arrancar. Intenta otra vez.';
          card.querySelectorAll('.jg-hacer').forEach(function(x){ x.disabled=false; });
          return;
        }
        sondear(d.job, card, live);
      }).catch(function(){
        live.className='jg-live on'; live.textContent='Se cayó la conexión. Intenta otra vez.';
        card.querySelectorAll('.jg-hacer').forEach(function(x){ x.disabled=false; });
      });
    });
  });

  function sondear(job, card, live){
    var n=0, MAX=120, fallos=0;      // 120 * 3s = 6 min de margen
    var frases=['El corillo está mirando lo que ya tienes y poniéndose a escribir…',
                'Escribiendo con la voz de tu negocio…',
                'Haciendo el arte de cada pieza…',
                'Casi listo: programando las fechas…'];
    var iv=setInterval(function(){
      n++;
      if(n%6===0){ var f=frases[Math.min(Math.floor(n/6), frases.length-1)];
        live.innerHTML='<span class="pts"><span></span><span></span><span></span></span> '+f; }
      post({accion:'job', job:job}).then(function(d){
        fallos=0;
        if(!d.ok || d.estado==='failed'){
          clearInterval(iv);
          live.className='jg-live on';
          live.textContent=(d.error_msg||d.err||'Se me trabó a mitad')+'. Puedes intentarlo otra vez.';
          card.querySelectorAll('.jg-hacer').forEach(function(x){ x.disabled=false; });
          return;
        }
        if(d.estado==='done'){
          clearInterval(iv);
          live.className='jg-live on ok';
          live.innerHTML='<b>Listo.</b> '+ (d.resultado||'Te dejé el contenido en Tus Posts.');
          setTimeout(function(){ location.reload(); }, 2600);   // refresca los puntos y el botón
          return;
        }
        if(n>=MAX){ clearInterval(iv);
          live.className='jg-live on';
          live.textContent='Está tardando más de lo normal. El corillo sigue en eso — revisa Tus Posts en un ratito.'; }
      }).catch(function(){ if(++fallos>=8){ clearInterval(iv);
        live.className='jg-live on';
        live.textContent='Se cayó la conexión, pero el corillo sigue trabajando. Revisa Tus Posts en un rato.'; } });
    }, 3000);
  }

  // "Ya lo hice" — SOLO para lo que pasa fuera de Crecer (boost, alianza, foto).
  // Lo que produce el corillo se cierra solo con la evidencia de publicación.
  document.querySelectorAll('.jg-ok2').forEach(function(b){
    b.addEventListener('click', function(){
      b.disabled=true; b.textContent='…';
      post({accion:'tactica', id:b.dataset.id, estado:'hecha'}).then(function(d){
        if(d.ok){ location.reload(); }
        else { b.disabled=false; b.textContent='Ya lo hice'; }
      }).catch(function(){ b.disabled=false; b.textContent='Ya lo hice'; });
    });
  });

  // Evaluar un plan viejo a pedido (sin esperar al relevo del corillo)
  document.querySelectorAll('.hp-ev').forEach(function(b){
    b.addEventListener('click', function(){
      b.disabled=true; b.textContent='El Analista está mirando los números…';
      post({accion:'evaluar', plan:b.dataset.plan}).then(function(d){
        if(d.ok) location.reload();
        else { b.disabled=false; b.textContent='Evaluarlo ahora'; alert(d.err||'No pude evaluarlo ahora.'); }
      }).catch(function(){ b.disabled=false; b.textContent='Evaluarlo ahora'; });
    });
  });

  //  AQUI VIVIAN LOS DOS confirm(). Ya no hay guion que enganchar: #replan y
  //  #cerrar son enlaces a ?vista=plan-nuevo y ?vista=cambiar.
  //
  //  Un cuadro del navegador es la peor forma de pedir permiso para algo
  //  grande: no cabe lo que va a pasar, no se lee con calma y no tiene vuelta
  //  atras. Y el de cambiar de meta ademas MENTIA — decia «el corillo dejara
  //  de perseguir esta» y lo que hacia era cerrarla y dejar al negocio sin
  //  meta, esperando que el dueño llenara despues un wizard en blanco.

  //  EL ANCLA #aprendizaje ES AHORA UN ACORDEON. Llegando por el salto
  //  -el estado L enlaza a meta.php?vista=plan#aprendizaje- tiene que abrirse
  //  solo, o el dueño aterriza en una fila cerrada preguntandose que vino a
  //  ver. Y se desplaza despues de abrir, que si no el salto cae corto.
  (function(){
    if (location.hash !== '#aprendizaje') return;
    var d = document.getElementById('aprendizaje');
    if (!d) return;
    d.open = true;
    setTimeout(function(){ d.scrollIntoView({block:'start'}); }, 60);
  })();
})();
</script>

<?php else: /* ══════════ CAPA 1 · META · AHORA · CAMINO ══════════ */
  $E   = $mt_estado;
  $cob = $E->cobertura;
  $obj = (string)($mt_snap['meta']['objetivo'] ?? '');
  $uni = $mt_unidad($obj);
  $act = $E->accion;

  //  ¿SE ACABARON LAS IMAGENES DEL MES? Se pregunta AQUI, junto al estado, para
  //  poder decidir una sola cosa: quien se queda con el boton primario.
  //
  //  Si lo que toca hoy NECESITA pintar (produccion o material), el aviso se
  //  lleva el primario, porque la accion normal no va a poder completarse. Si
  //  lo que toca es aprobar, publicar o confirmar algo, el aviso va SIN boton y
  //  la accion normal se queda con el suyo: dos primarios compitiendo es el
  //  criterio 3 del contrato, y en esa pantalla el dueño ya no sabe que tocar.
  //  QUIEN MANDA NO LO DECIDE LA LETRA DEL ESTADO.
  //  Estaba escrito como «si es E o G, manda la cuota», y eso le quitaba a la
  //  dueña la unica accion que SI podia hacer con el mes agotado: G le pide
  //  material suyo -una foto, un video- y subirlo no gasta ni una imagen. Y E
  //  cubre tanto un trabajo ya en marcha, que no se para, como uno que ni ha
  //  empezado, que si. La regla vive ahora en MetaLimiteImagen y pregunta lo
  //  que hay que preguntar: ¿lo proximo necesita PINTAR algo nuevo?
  require_once __DIR__ . '/../includes/cuota_aviso.php';
  require_once __DIR__ . '/../core/Meta/MetaLimiteImagen.php';
  $mt_cuota = null; $mt_cuota_manda = false;
  try {
      $mt_cuota = img_cuota_estado($pdo, $marca_id, ($usuario['rol'] ?? '') === 'admin');
      $mt_cuota_manda = MetaLimiteImagen::manda($E, $mt_cuota);
  } catch (Throwable $e) { $mt_cuota = null; }
  // El destino de la acción vuelve aquí cuando termine.
  // El destino sale del compositor; aquí solo se le añade el regreso y, para
  // el estado A, la capa del wizard: sin eso su acción recargaba esta misma
  // pantalla y el dueño se quedaba dando vueltas.
  $destino = '';
  if ($act) {
      $destino = $act['destino'];
      if (strpos($destino, 'meta.php') === false)          $destino .= $mt_volver;
      elseif ($E->estado === MetaState::A_SIN_META)         $destino .= '&vista=wizard';
      elseif ($E->estado === MetaState::M_CERRADA)          $destino .= '&vista=wizard&nueva=1';
  }
?>

<div class="ah">

  <?php /* ── BARRA DE CONTEXTO · un DATO, no un titular ────────────────
           Era un encabezado de 21px que competia con el titulo del estado:
           dos voces grandes, una encima de otra, y el ojo sin saber cual era
           el asunto. Ahora es una tira: objetivo, cifra y dias.

           Y la cifra dice DE DONDE SALE en la misma linea. Antes «7 de 25» y,
           tres renglones mas abajo, un parrafo explicando que lo de WhatsApp
           no entraba. Quien no leia el parrafo entendia otra cosa. */ ?>
  <?php if ($mt_snap['meta']): ?>
    <?php
      [$sust, $part] = $uni;
      $num = fn($v) => rtrim(rtrim(number_format((float)$v, 2), '0'), '.');
      $dias = '';
      if ($mt_snap['progreso']['dias_rest'] !== null) {
          $d = (int)$mt_snap['progreso']['dias_rest'];
          $dias = $d <= 0 ? 'sin días por delante'
                          : ($d === 1 ? 'queda 1 día' : "quedan {$d} días");
      }
      $cerrada = $E->estado === MetaState::M_CERRADA;
      $pct = $mt_snap['progreso']['pct'];
    ?>
    <section class="tm-meta">
      <div class="tm-meta-fila">
        <?= ico($cerrada ? 'check-circle' : 'compass') ?>
        <span class="tm-obj-nom"><?= $h($cerrada ? $sust . ' · cerrada' : $sust) ?></span>
        <?php if ($dias !== ''): ?><span class="tm-dias"><?= $h($dias) ?></span><?php endif; ?>
      </div>
      <div class="tm-cifra">
        <?php if ($mt_snap['progreso']['actual'] !== null): ?>
          <b><?= $h($num($mt_snap['progreso']['actual'])) ?></b>
          <span class="et"><?= $h($mt_fuente($obj, $part)) ?></span>
        <?php else: ?>
          <span class="et">Sin señal todavía</span>
        <?php endif; ?>
        <?php if ($mt_snap['meta']['cantidad'] !== null): ?>
          <span class="de">· de <?= $h($num($mt_snap['meta']['cantidad'])) ?></span>
        <?php endif; ?>
      </div>
      <?php /* La barra solo cuando el COMPOSITOR dice que se puede afirmar el
               progreso. Con cobertura parcial no se pinta: una barra afirma
               «vas por aqui de un total que conozco», y no lo conocemos. */ ?>
      <?php if ($E->puedeAfirmarProgreso() && $pct !== null): ?>
        <i class="tm-barra"><b style="width:<?= max(0, min(100, (int)$pct)) ?>%"></b></i>
      <?php endif; ?>
      <?php /* El limite de imagenes, cuando NO bloquea lo de hoy: un renglon
               mas de contexto. Cuando bloquea se lleva la pantalla entera, y
               eso lo decide el aviso de arriba, no esta tira. */ ?>
      <?php if ($mt_cuota && !empty($mt_cuota['lleno']) && !$mt_cuota_manda): ?>
        <div class="tm-lim"><?= ico('image') ?>
          <span>Sin imágenes nuevas hasta el <?= $h($mt_reset($mt_cuota)) ?></span>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php /* ── LA VUELTA · qué pasó con lo anterior ───────────────────────
           Va DESPUES de la meta y antes del turno: es el acuse de recibo de
           lo que el dueño acaba de hacer, y lo primero que busca al volver.
           El texto NO sale de la URL: la URL trae una llave y el texto vive
           en MetaRetorno. Llave que no reconozca, no pinta nada. */ ?>
  <?php if ($mt_confirma = MetaRetorno::confirmacion($_GET['hecho'] ?? null)): ?>
    <div class="tm-vuelta" role="status">
      <?= ico('check-circle') ?>
      <span><b><?= $h($mt_confirma[0]) ?></b> <?= $h($mt_confirma[1]) ?></span>
    </div>
  <?php endif; ?>

  <?php /* ── ZONA AHORA · el turno, una frase y UNA acción ─────────────
           Quien decide el estado sigue siendo el compositor: aqui solo se
           pregunta lo que el estado ya sabe de si mismo -si pide algo al
           dueño, si tiene accion- y se pinta. Ninguna regla nueva. */ ?>
  <?php
    //  EL TURNO. Tres posibles, y siempre CON PALABRA: la regla de
    //  accesibilidad dice que la informacion no puede viajar solo en el
    //  color, porque hay quien no lo distingue y porque una pastilla muda
    //  no se puede leer en voz alta.
    //  Rosa = solo tu puedes hacerlo. Teal = ya esta hecho o corre solo.
    $TUYAS = ['material', 'aprobacion', 'inversion', 'fisica', 'decision',
              'reintento', 'reintento_job'];
    $turno_cls = ''; $turno_txt = ''; $turno_ico = '';
    if ($mt_cuota_manda) {
        $turno_cls = 'limite'; $turno_txt = 'Sin imágenes este mes'; $turno_ico = 'image';
    } elseif ($act !== null && in_array((string)($act['tipo'] ?? ''), $TUYAS, true)) {
        $turno_cls = ''; $turno_txt = 'Te toca a ti';
    } elseif ($act === null) {
        $turno_cls = 'mio'; $turno_txt = 'Me toca a mí';
    } else {
        $turno_cls = 'mio'; $turno_txt = 'Ya está listo';
    }

    //  EL TITULO YA NO REPITE EL ROTULO. El compositor devuelve titulos que
    //  se explican solos ("Para seguir, necesito tu video") porque Home los
    //  usa sueltos; aqui, con el turno delante, sobra el prefijo.
    $titulo = trim($E->titulo);
    foreach (['Para seguir, necesito', 'Nada pendiente de ti'] as $pref) {
        if (stripos($titulo, $pref) === 0) {
            $resto = trim(mb_substr($titulo, mb_strlen($pref)));
            if ($resto !== '') $titulo = mb_strtoupper(mb_substr($resto, 0, 1)) . mb_substr($resto, 1);
            break;
        }
    }
    //  CON EL LIMITE MANDANDO, LA PANTALLA DICE QUE PASA.
    //  Cambiar solo la pastilla y dejar el titulo del estado normal era medio
    //  aviso: la dueña leia «Me toca a mi» con una pastilla ambar al lado y
    //  tenia que atar cabos. Se dice entero, con el numero y el mes.
    if ($mt_cuota_manda) {
        $lim = (int)($mt_cuota['limite'] ?? 0);
        $titulo = $lim > 0
            ? 'Usaste las ' . $lim . ' imágenes de ' . $mt_mes()
            : 'Se acabaron las imágenes de ' . $mt_mes();
    }
    //  M · el cierre dice CON CUANTO, y siempre «registrados»: sin esa
    //  palabra «18 pedidos» se lee como los pedidos del negocio, y Crecer
    //  solo cuenta los que pasaron por aqui.
    if ($E->estado === MetaState::M_CERRADA && $mt_snap['meta']
        && $mt_snap['progreso']['actual'] !== null) {
        [$sust_m, $part_m] = $uni;
        $n_m = rtrim(rtrim(number_format((float)$mt_snap['progreso']['actual'], 2), '0'), '.');
        $titulo = 'Cerraste con ' . $n_m . ' ' . $sust_m . ' ' . $part_m;
    }
    $objeto = (array)($E->evidencia['objeto'] ?? []);
    //  El objeto pausado es el que el compositor eligio, no un ejemplo. Si el
    //  estado no trae ninguno, la pantalla no se inventa uno.
    $pausado = $mt_cuota_manda ? MetaLimiteImagen::objetoPausado($E) : [];
    if ($pausado) $objeto = $pausado;
  ?>
  <section class="tm-ahora">
    <span class="tm-turno <?= $h($turno_cls) ?>">
      <?php if ($turno_ico !== ''): ?><?= ico($turno_ico) ?><?php else: ?><u></u><?php endif; ?>
      <?= $h($turno_txt) ?>
    </span>

    <h1 class="tm-frase"><?= $h($titulo) ?></h1>

    <?php /* EL OBJETO. La pantalla enseña de qué habla ANTES de que el dueño
             pulse: hasta ahora habia que entrar al post para saber cual era.
             Solo cuando el estado trae uno — si lo que falta es material, lo
             que se pide es justo lo que NO hay, y enmarcar un hueco no dice
             nada. */ ?>
    <?php if ($objeto && trim((string)($objeto['titulo'] ?? '')) !== ''): ?>
      <div class="tm-obj">
        <span class="tm-obj-ic"><?= ico($mt_ico_obj((string)($objeto['tipo'] ?? 'post'))) ?></span>
        <span class="tm-obj-tx">
          <b><?= $h($objeto['titulo']) ?></b>
          <?php $sub_obj = $mt_cuota_manda
              ? 'en pausa · le falta la imagen'
              : $mt_sub_obj($objeto); ?>
          <?php if ($sub_obj !== ''): ?>
            <span><?= $h($sub_obj) ?></span>
          <?php endif; ?>
        </span>
      </div>
    <?php endif; ?>

    <?php if ($act): $tipo = (string)($act['tipo'] ?? ''); ?>
      <?php if ($tipo === 'inversion'): ?>
        <?php /* NO decimos "Autorizar $15": Crecer no entra a tu gestor de
                 anuncios ni puede comprobar que el dinero salió. La acción
                 primaria ENSEÑA cómo hacerlo; confirmarlo es un paso aparte, y
                 solo esa confirmación cierra la jugada. */ ?>
        <details class="ah-como" id="ahComo">
          <summary class="tm-btn"><?= ico('dollar') ?><?= $h($act['etiqueta']) ?></summary>
          <div class="ah-pasos">
            <?php
              /*  CUAL PUBLICACION. Antes ponia «abre la publicación» a secas y el
                  dueño tenia que adivinar cual de todas — con lo cual o promocionaba
                  la equivocada o no promocionaba nada. El objeto sale de sus piezas
                  PUBLICADAS de verdad (el compositor lo decide); si no hay ninguna,
                  aqui se dice, no se inventa un post.  */
              $inv_obj = $E->evidencia['objeto'] ?? null;
              $inv_pub = (int)($E->evidencia['publicadas'] ?? 0);
            ?>
            <?php if (is_array($inv_obj)): ?>
              <div class="ah-cual">
                <span class="et">La publicación que se promociona</span>
                <b><?= $h((string)($inv_obj['titulo'] ?? '')) ?></b>
                <?php if (($inv_obj['red'] ?? '') !== ''): ?>
                  <small><?= $h((string)$inv_obj['red']) ?><?= $inv_pub > 1 ? ' · es la última que publiqué de este plan, de ' . $inv_pub : '' ?></small>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <div class="ah-cual sin">
                <span class="et">Todavía no hay qué promocionar</span>
                <b>De este plan no hay ninguna publicación tuya publicada todavía.</b>
                <small>Promocionar empuja un post que ya está en la red. En cuanto salga el
                  primero, esta jugada te dice cuál.</small>
              </div>
            <?php endif; ?>

            <p class="ah-monto">Presupuesto de esta jugada: <b><?= $h('$' . rtrim(rtrim(number_format((float)($E->evidencia['inversion'] ?? 0), 2), '0'), '.')) ?></b>
              <span>Lo pones tú en la app de Meta. Ese dinero no pasa por Crecer.</span></p>
            <ol>
              <li>Abre <?= is_array($inv_obj) ? 'esa publicación' : 'la publicación' ?> en
                <?= is_array($inv_obj) && ($inv_obj['red'] ?? '') !== '' ? $h((string)$inv_obj['red']) : 'Instagram o Facebook' ?>
                desde tu teléfono.</li>
              <li>Toca <b>Promocionar</b> (o <b>Impulsar publicación</b>).</li>
              <li>Escoge el público de tu zona y pon el presupuesto de arriba.</li>
              <li>Confirma el pago en la app de Meta — eso lo haces tú, no yo.</li>
            </ol>
            <p class="ah-aviso">Yo no puedo promocionarlo por ti, no pago el anuncio y no tengo
              forma de ver si el cobro salió. Mirar estos pasos no marca nada: la jugada solo
              se da por hecha cuando tú me lo confirmas.</p>
            <div class="ah-cierre">
              <button type="button" class="tm-btn linea" id="ahConfirmar"
                      data-jugada="<?= (int)($E->evidencia['tactica_id'] ?? 0) ?>"><?= ico('check') ?>Confirmar que ya lo promocioné</button>
              <?php /*  CANCELAR TAMPOCO MARCA. Cerrar el desplegable ya era la
                        salida, pero solo lo sabia quien lo adivinaba: sin un
                        control que lo diga, «abrir para mirar» da miedo.  */ ?>
              <button type="button" class="ah-nomarca" id="ahLuego"><?= ico('x') ?>Todavía no — cerrar sin marcar</button>
            </div>
          </div>
        </details>
      <?php elseif ($tipo === 'fisica'): ?>
        <button type="button" class="tm-btn" id="ahConfirmar"
                data-jugada="<?= (int)($E->evidencia['tactica_id'] ?? 0) ?>"><?= ico('check') ?><?= $h($act['etiqueta']) ?></button>
      <?php elseif ($tipo === 'presentacion'): ?>
        <?php /* El contrato pide RESUMEN, no el plan entero (§C): la meta ya
                 está arriba, aquí va la estrategia en una frase y el reparto
                 del trabajo. Lo que te van a pedir es lo que de verdad decide:
                 nadie acepta un plan sin saber qué le toca. */ ?>
        <?php
          $ev_est  = trim((string)($E->evidencia['estrategia'] ?? ''));
          $ev_mio  = (int)($E->evidencia['hago_yo'] ?? 0);
          $ev_tuyo = (int)($E->evidencia['te_pido'] ?? 0);
          $ev_pide = (array)($E->evidencia['pide'] ?? []);
        ?>
        <div class="tm-trato">
          <?php if ($ev_est !== ''): ?>
            <p class="tm-estr"><?= $h($ev_est) ?></p>
          <?php endif; ?>
          <div class="tm-reparto">
            <div class="mia"><b><?= $ev_mio ?></b><span><?= $ev_mio === 1 ? 'cosa la hago yo' : 'cosas las hago yo' ?></span></div>
            <div class="tuya"><b><?= $ev_tuyo ?></b><span><?= $ev_tuyo === 1 ? 'cosa te toca a ti' : 'cosas te tocan a ti' ?></span></div>
          </div>
          <?php if ($ev_pide): ?>
            <p class="tm-pide"><b>Lo que te voy a pedir:</b>
              <?= $h(implode(' · ', $ev_pide)) ?><?= $ev_tuyo > count($ev_pide) ? '…' : '' ?></p>
          <?php elseif ($ev_tuyo === 0): ?>
            <p class="tm-pide">De este plan me encargo yo entero. Tú apruebas y ya.</p>
          <?php endif; ?>
        </div>
        <?php /* Botón, no enlace: esto ESCRIBE. Un <a> lo repetiría el
                 prefetch del navegador y lo guardaría el historial. */ ?>
        <button type="button" class="tm-btn teal" id="ahEmpezar"
                data-plan="<?= (int)($E->evidencia['plan_id'] ?? 0) ?>"><?= ico('eye') ?><?= $h($act['etiqueta']) ?></button>
      <?php elseif ($tipo === 'reintento_job'): ?>
        <?php /* Reencola la jugada de verdad con la acción `ejecutar` que ya
                 existe. Un enlace aquí recargaría la pantalla dejando el fallo
                 igual de trabado. */ ?>
        <button type="button" class="tm-btn" id="ahReintentar"
                data-jugada="<?= (int)($E->evidencia['tactica_id'] ?? 0) ?>"><?= ico('refresh') ?><?= $h($act['etiqueta']) ?></button>
      <?php elseif ($mt_cuota_manda): ?>
        <?php /* SIN CUOTA Y LO DE HOY NECESITA PINTAR.
                 La accion normal no puede completarse, asi que NO se enseña:
                 dejarla ahi mandaria al dueño a un callejon. Lo unico cierto
                 hoy es mirar lo que ya existe, y eso no es una decision — va
                 en boton de linea. */ ?>
        <a class="tm-btn linea" href="<?= $BASE ?>/propuestas.php?marca=<?= $marca_id ?>"><?= ico('eye') ?>Ver lo que ya está listo</a>
      <?php else: ?>
        <a class="tm-btn<?= $turno_cls === 'mio' ? ' teal' : '' ?>" href="<?= $h($destino) ?>"><?= ico($mt_ico_act((string)$tipo)) ?><?= $h($act['etiqueta']) ?></a>
      <?php endif; ?>

      <?php /* La consecuencia de pulsar. Cuando el limite manda, la de la
               accion normal ya no es verdad: se dice lo que SI pasa. */ ?>
      <?php if ($mt_cuota_manda): ?>
        <p class="tm-cons">El resto del plan continúa. Lo que necesita imagen queda en pausa.</p>
      <?php elseif (trim((string)$act['consecuencia']) !== ''): ?>
        <p class="tm-cons"><?= $h($act['consecuencia']) ?></p>
      <?php endif; ?>
    <?php else: ?>
      <?php /* Sin accion del dueño: hay algo que MIRAR, nada que decidir, y el
               peso del boton lo tiene que decir. */ ?>
      <a class="tm-btn linea" href="<?= $BASE ?>/calendario.php?marca=<?= $marca_id ?>"><?= ico('calendar') ?>Ver el mes completo</a>
      <p class="tm-cons">Te aviso en cuanto haya algo para tu OK.</p>
    <?php endif; ?>

    <?php if ($E->estado === MetaState::G_MATERIAL && trim((string)($E->evidencia['guion'] ?? '')) !== ''): ?>
      <details class="tm-ac" style="border-top:1px solid var(--line);margin-top:18px">
        <summary>Ver qué grabar<?= ico('chev-abajo') ?></summary>
        <div class="dentro"><p><?= nl2br($h($E->evidencia['guion'])) ?></p></div>
      </details>
    <?php endif; ?>
  </section>

  <?php /* ── CAPA 2 · el camino y la meta, plegados ─────────────────────
           Nacen ABIERTOS solo cuando la pantalla no puede pedir la accion
           normal del plan -no hay nada que decidir, o el limite lo impide-.
           En el resto, cerrados: primero la decision, y quien quiera mas la
           abre. */ ?>
  <?php
    $cm = $E->camino;
    $abre = ($act === null) || $mt_cuota_manda;
    $prox = (array)($cm['proximos'] ?? []);
    //  EN LA PRESENTACION DEL PLAN, «Lo que sigue» SOBRA.
    //  Esa pantalla ya ES el plan: el trato de arriba dice cuantas cosas hago
    //  yo, cuantas te pido y cuales. Repetirlas debajo nombra casi todas las
    //  jugadas, que es justo lo que el contrato §C no quiere en la capa 1.
    if ($E->estado === MetaState::C_PLAN_POR_VER) $prox = [];
  ?>
  <div class="tm-capas">
    <?php /*  LO QUE SIGUE PASANDO SALE DEL RETRATO, NO DE UNA LISTA FIJA.
              Habia cuatro renglones iguales para todo el mundo: escribo,
              publico, contesto mensajes y la fecha. Tres eran afirmaciones
              sobre ESTA marca que nadie habia comprobado — si no tiene nada
              aprobado no va a publicar, y si no tiene canales conectados no va
              a contestar—. Prometerlo aqui es peor que callarlo: es el momento
              en que la dueña decide si esto le sirve. */ ?>
    <?php $sigue = $mt_cuota_manda
        ? MetaLimiteImagen::sigueHaciendo($mt_snap, $mt_reset($mt_cuota)) : []; ?>
    <?php if ($mt_cuota_manda && $sigue): ?>
      <details class="tm-ac" open>
        <summary>Qué sigue pasando <span class="cta"><?= count($sigue) ?></span><?= ico('chev-abajo') ?></summary>
        <div class="dentro"><div class="tm-pasos">
          <?php foreach ($sigue as $sg): ?>
            <div class="tm-paso"><?= ico($sg['ico']) ?><div><b><?= $h($sg['titulo']) ?></b><span><?= $h($sg['pie']) ?></span></div></div>
          <?php endforeach; ?>
        </div></div>
      </details>
    <?php elseif ($prox): ?>
      <details class="tm-ac"<?= $abre ? ' open' : '' ?>>
        <summary>Lo que sigue <span class="cta"><?= count($prox) ?></span><?= ico('chev-abajo') ?></summary>
        <div class="dentro"><div class="tm-pasos">
          <?php foreach ($prox as $p): ?>
            <div class="tm-paso<?= !empty($p['tuyo']) ? ' tuyo' : '' ?>">
              <?= ico(!empty($p['tuyo']) ? 'camera' : 'bolt') ?>
              <div><b><?= $h($p['titulo']) ?></b>
                <span><?= $h((!empty($p['semana']) ? 'semana ' . (int)$p['semana'] . ' · ' : '')
                         . (!empty($p['tuyo']) ? 'te la pido' : 'la hago yo')) ?></span></div>
            </div>
          <?php endforeach; ?>
        </div></div>
      </details>
    <?php endif; ?>

    <?php if ($mt_snap['meta']): ?>
      <details class="tm-ac">
        <summary>Cómo voy con la meta<?= ico('chev-abajo') ?></summary>
        <div class="dentro"><p><?= $h($mt_como_voy($E, $mt_snap, $uni, $obj)) ?></p></div>
      </details>
    <?php endif; ?>
  </div>

  <?php /* ── CAPA 3 · otra pantalla, detras de un enlace ── */ ?>
  <?php /*  LA SALIDA DE LA JUGADA IMPOSIBLE, TAMBIEN DESDE AQUI.
            Cuando lo que manda la pantalla es material (G), inversion (H) o una
            accion suya (I), el atasco esta justo delante. Mandar al dueño al
            plan a buscar la jugada seria pedirle que se acuerde de donde
            estaba. Va como enlace secundario: la primaria no se toca.  */ ?>
  <?php
    $mt_jug_atasco = (int)($E->evidencia['tactica_id'] ?? 0);
    $mt_puede_sust = $mt_jug_atasco > 0 && meta_sustitucion_disponible($pdo)
        && in_array((string)($act['tipo'] ?? ''), ['material', 'inversion', 'fisica'], true);
  ?>
  <?php if ($mt_puede_sust): ?>
    <a class="tm-nopuedo" href="<?= $BASE ?>/meta.php?marca=<?= $marca_id ?>&amp;vista=sustituir&amp;jugada=<?= $mt_jug_atasco ?>&amp;desde=ahora">
      <?= ico('refresh') ?>No puedo con esta — cámbiala por otra</a>
  <?php endif; ?>

  <nav class="tm-mas">
    <?php if ($meta): ?>
      <a href="<?= $BASE ?>/meta.php?marca=<?= $marca_id ?>&vista=plan"><?= ico('list') ?>Ver el plan completo<?= ico('chev-der') ?></a>
      <a href="<?= $BASE ?>/sala.php?marca=<?= $marca_id ?>"><?= ico('chat') ?>Discutirla con el corillo<?= ico('chev-der') ?></a>
    <?php else: ?>
      <a href="<?= $BASE ?>/sala.php?marca=<?= $marca_id ?>"><?= ico('chat') ?>Preguntarle al corillo<?= ico('chev-der') ?></a>
    <?php endif; ?>
  </nav>
</div>

<div class="ah-toast" id="ahToast"></div>
<?php require __DIR__ . '/_meta_zona.php'; ?>

<script>
(function(){
  var CSRF = <?= json_encode(csrf_token()) ?>, MARCA = <?= (int)$marca_id ?>;
  var t = document.getElementById('ahToast');
  function say(m){ t.textContent = m; t.classList.add('on');
    setTimeout(function(){ t.classList.remove('on'); }, 2600); }

  // Confirmar lo que ocurre FUERA de Crecer (gasto o recado). Se marca la
  // jugada y se recompone el estado: la pantalla no se queda mintiendo.
  function enviar(btn, campos, cargando, alFallar) {
    var antes = btn.textContent;
    btn.disabled = true; btn.textContent = cargando;
    var fd = new FormData();
    fd.append('csrf', CSRF); fd.append('ajax', '1');
    Object.keys(campos).forEach(function(k){ fd.append(k, campos[k]); });
    return fetch(location.pathname + '?marca=' + MARCA, { method: 'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(d){
        // Se recarga para que el ESTADO se recomponga: la pantalla no puede
        // quedarse enseñando lo que ya dejó de ser verdad.
        if (d && d.ok) { location.href = location.pathname + '?marca=' + MARCA; return true; }
        btn.disabled = false; btn.textContent = antes; say((d && d.err) || alFallar);
        return false;
      })
      .catch(function(){ btn.disabled = false; btn.textContent = antes; say('Error de conexión.'); return false; });
  }

  // Confirmar lo que ocurrió FUERA de Crecer. Solo esto cierra la jugada.
  var b = document.getElementById('ahConfirmar');
  if (b) b.addEventListener('click', function(){
    if (!b.dataset.jugada) return;
    enviar(b, {accion:'tactica', id:b.dataset.jugada, estado:'hecha'},
           'Un momento…', 'No se pudo marcar.');
  });

  //  «TODAVIA NO» cierra el desplegable y NO manda nada. Es la mitad que
  //  faltaba del contrato de la inversion: abrir para mirar tiene que ser
  //  gratis, y para que lo sea hay que DECIRLO con un control — no esperar a
  //  que el dueño adivine que cerrar la capa no marca nada.
  var noAun = document.getElementById('ahLuego');
  if (noAun) noAun.addEventListener('click', function(){
    var caja = document.getElementById('ahComo');
    if (!caja) return;
    caja.open = false;
    var s = caja.querySelector('summary'); if (s) s.focus();
  });

  // Aceptar el plan. Se sella una vez y la pantalla se recompone sola: al
  // recargar, el estado dominante ya es la primera tarea de verdad.
  var e = document.getElementById('ahEmpezar');
  if (e) e.addEventListener('click', function(){
    enviar(e, {accion:'presentar', plan:e.dataset.plan || 0},
           'Vamos…', 'No se pudo empezar.');
  });

  // Reencolar la jugada que se trabó.
  var r = document.getElementById('ahReintentar');
  if (r) r.addEventListener('click', function(){
    if (!r.dataset.jugada) return;
    enviar(r, {accion:'ejecutar', id:r.dataset.jugada},
           'Reintentando…', 'No se pudo reintentar.');
  });
})();
</script>

<?php endif; ?>

<?php
require_once __DIR__ . '/../includes/tour.php';
tour_montar($pdo, $marca_id, 'meta');
require __DIR__ . '/_shell_foot.php'; ?>
