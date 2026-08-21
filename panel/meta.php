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
            $plan = meta_plan_generar($pdo, $marca_id, (int)$meta['id']);
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
            if ($meta) meta_ajustar($pdo, (int)$meta['id'], $marca_id, ['estado'=>'cancelada']);
            echo json_encode(['ok'=>true]);
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
//   ?vista=wizard el wizard de escoger meta (estado A)
$vista = $_GET['vista'] ?? '';
if (!in_array($vista, ['plan', 'wizard'], true)) $vista = 'ahora';
if (!$meta && $vista === 'ahora' && !empty($_GET['nueva'])) $vista = 'wizard';

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
  /* ══ LA META ══ el norte del negocio.
     Desktop: el número grande a la izquierda respirando, las jugadas a la
     derecha. Móvil: el número y cómo va caben antes del primer scroll. */
  .mt-h1{font-family:var(--font-display,'Oswald',sans-serif);font-weight:700;font-size:24px;letter-spacing:.4px;color:var(--tinta);margin:0;line-height:1.05}
  .mt-sub{font-size:13.5px;color:var(--muted);margin:5px 0 0;max-width:620px;line-height:1.5}

  /* ── El wizard ── */
  .wz{max-width:860px}
  .wz-bar{height:5px;border-radius:99px;background:var(--line);overflow:hidden;margin:16px 0 22px}
  .wz-bar i{display:block;height:100%;background:linear-gradient(90deg,var(--teal,#00A49F),var(--magenta,#EF4375));border-radius:99px;transition:width .35s cubic-bezier(.4,0,.2,1)}
  .wz-paso{display:none;animation:wzin .28s ease both}
  .wz-paso.on{display:block}
  @keyframes wzin{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
  .wz-q{font-family:var(--font-display,'Oswald',sans-serif);font-weight:700;font-size:26px;line-height:1.15;color:var(--tinta);margin:0 0 6px;letter-spacing:.3px}
  .wz-ayuda{font-size:13.5px;color:var(--muted);line-height:1.5;margin:0 0 18px;max-width:560px}

  /* Tarjetas de objetivo: el deseo grande, la jerga chiquita abajo */
  .obj-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
  .obj{position:relative;text-align:left;background:var(--card,#fff);border:1.5px solid var(--line);border-radius:16px;padding:16px 16px 14px;cursor:pointer;font-family:inherit;transition:transform .13s,border-color .15s,box-shadow .15s;box-shadow:var(--shadow-sm)}
  .obj:hover{border-color:var(--teal,#00A49F);transform:translateY(-2px);box-shadow:0 10px 22px -14px rgba(0,0,0,.35)}
  .obj:active{transform:scale(.985)}
  .obj.sel{border-color:var(--magenta,#EF4375);box-shadow:0 0 0 3px color-mix(in srgb,var(--magenta,#EF4375) 16%,transparent)}
  .obj .ic{width:34px;height:34px;border-radius:11px;display:grid;place-items:center;background:color-mix(in srgb,var(--teal,#00A49F) 12%,#fff);margin-bottom:9px}
  .obj .ic svg{width:18px;height:18px;color:var(--teal,#00A49F)}
  .obj b{display:block;font-size:15.5px;color:var(--tinta);line-height:1.25;margin-bottom:5px}
  .obj p{font-size:12.5px;color:var(--muted);line-height:1.45;margin:0 0 9px}
  .obj .jerga{display:block;font-size:11px;color:var(--muted);line-height:1.4;padding-top:8px;border-top:1px dashed var(--line);opacity:.85}
  .obj .jerga b{display:inline;font-size:11px;color:var(--tinta)}

  /* Cantidad + fecha */
  .mt-num{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
  .mt-num input{font-family:var(--font-display,'Oswald',sans-serif);font-size:38px;font-weight:700;width:190px;border:2px solid var(--line);border-radius:16px;padding:10px 16px;color:var(--tinta);background:var(--card,#fff);text-align:center}
  .mt-num input:focus{outline:0;border-color:var(--magenta,#EF4375)}
  .mt-unidad{font-size:15px;font-weight:700;color:var(--muted)}
  .mt-nose{border:1.5px dashed var(--line);background:transparent;color:var(--tinta);font-family:inherit;font-weight:700;font-size:13px;padding:11px 15px;border-radius:13px;cursor:pointer}
  .mt-nose:hover{border-color:var(--teal,#00A49F);color:var(--teal,#00A49F)}
  .mt-tip{margin-top:14px;background:color-mix(in srgb,var(--teal,#00A49F) 9%,#fff);border:1px solid color-mix(in srgb,var(--teal,#00A49F) 28%,#fff);color:#0a6a5f;border-radius:13px;padding:11px 14px;font-size:13px;line-height:1.5;font-weight:600;display:none}
  .mt-tip.on{display:block}

  .chips{display:flex;gap:9px;flex-wrap:wrap;margin-top:6px}
  .chip{border:1.5px solid var(--line);background:var(--card,#fff);color:var(--tinta);font-family:inherit;font-weight:700;font-size:13.5px;padding:11px 16px;border-radius:99px;cursor:pointer;transition:transform .12s,border-color .15s}
  .chip:hover{border-color:var(--teal,#00A49F)}
  .chip:active{transform:scale(.96)}
  .chip.sel{border-color:var(--magenta,#EF4375);background:color-mix(in srgb,var(--magenta,#EF4375) 8%,#fff);color:var(--magenta,#EF4375)}
  .chip small{display:block;font-weight:600;font-size:11px;color:var(--muted);margin-top:1px}
  .chip.sel small{color:var(--magenta,#EF4375);opacity:.8}

  .mt-libre{width:100%;font-family:inherit;font-size:15px;border:1.5px solid var(--line);border-radius:14px;padding:13px 15px;background:var(--card,#fff);color:var(--tinta);resize:vertical;min-height:96px;line-height:1.5}
  .mt-libre:focus{outline:0;border-color:var(--magenta,#EF4375)}

  .wz-nav{display:flex;gap:10px;align-items:center;margin-top:24px;flex-wrap:wrap}
  .btn-p{border:0;cursor:pointer;background:linear-gradient(135deg,var(--coral,#FF6B3D),var(--magenta,#EF4375));color:#fff;font-weight:800;font-size:15px;padding:14px 24px;border-radius:14px;font-family:inherit;display:inline-flex;align-items:center;gap:8px;transition:transform .12s}
  .btn-p:active{transform:scale(.97)}
  .btn-p:disabled{opacity:.5;cursor:default}
  .btn-s{border:1.5px solid var(--line);cursor:pointer;background:var(--card,#fff);color:var(--muted);font-weight:700;font-size:14px;padding:13px 18px;border-radius:14px;font-family:inherit}
  .btn-s:hover{color:var(--tinta);border-color:var(--tinta)}

  /* ── La meta viva ── */
  .mv{display:grid;grid-template-columns:minmax(0,340px) minmax(0,1fr);gap:22px;align-items:start}
  .card{background:var(--card,#fff);border:1px solid var(--line);border-radius:18px;padding:20px;box-shadow:var(--shadow-sm)}
  .mv-num{font-family:var(--font-display,'Oswald',sans-serif);font-weight:700;font-size:54px;line-height:.95;color:var(--tinta);letter-spacing:-.5px}
  .mv-de{font-size:14px;color:var(--muted);font-weight:600;margin-top:4px}
  .mv-barra{height:11px;border-radius:99px;background:var(--crema-2,#f2efe9);overflow:hidden;margin:16px 0 8px;border:1px solid var(--line)}
  .mv-barra i{display:block;height:100%;background:linear-gradient(90deg,var(--teal,#00A49F),var(--magenta,#EF4375));border-radius:99px;transition:width .6s cubic-bezier(.4,0,.2,1)}
  .mv-pie{display:flex;justify-content:space-between;font-size:12.5px;color:var(--muted);font-weight:600}
  .mv-est{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:800;padding:6px 11px;border-radius:99px;margin-top:14px}
  .mv-est.bien{background:color-mix(in srgb,var(--teal,#00A49F) 14%,#fff);color:#0a6a5f}
  .mv-est.mal{background:#fdeeee;color:#b4232b}
  .mv-est svg{width:13px;height:13px}
  .mv-nomed{background:#fff8e6;border:1px solid #f2dfae;color:#7a5b12;border-radius:13px;padding:12px 14px;font-size:12.5px;line-height:1.5;margin-top:14px}

  .diag{background:linear-gradient(135deg,color-mix(in srgb,var(--teal,#00A49F) 10%,#fff),var(--card,#fff));border:1px solid color-mix(in srgb,var(--teal,#00A49F) 25%,#fff);border-radius:16px;padding:17px 18px;margin-bottom:16px}
  .diag .qui{display:flex;align-items:center;gap:9px;font-size:12px;font-weight:800;color:var(--teal,#00A49F);letter-spacing:.4px;text-transform:uppercase;margin-bottom:7px}
  .diag .qui svg{width:15px;height:15px}
  .diag p{margin:0;font-size:14.5px;line-height:1.6;color:var(--tinta)}
  .vered{display:inline-block;font-size:11.5px;font-weight:800;padding:4px 10px;border-radius:99px;margin-top:11px}
  .vered.alcanzable{background:#e6f7f0;color:#0a6a4a}
  .vered.ambiciosa{background:#fff4e0;color:#8a5a10}
  .vered.fuera_de_alcance{background:#fdeeee;color:#b4232b}

  .jug{display:flex;flex-direction:column;gap:11px}
  .jg{background:var(--card,#fff);border:1px solid var(--line);border-radius:15px;padding:0;box-shadow:var(--shadow-sm);transition:border-color .15s}
  .jg:hover{border-color:var(--teal,#00A49F)}
  .jg.hecha{opacity:.62}
  .jg.hecha .jg-t{text-decoration:line-through}
  /* Plegadas por defecto: la de turno abre sola. Seis jugadas abiertas en un
     teléfono eran 8,000px sin jerarquía — no se sabía por dónde empezar. */
  /* Dos líneas, siempre: arriba el tipo y el estado, abajo el título a ancho
     completo. En una sola fila, el chip y el estado ahogaban el título y en
     360px caía en cuatro líneas de dos palabras. */
  .jg > summary{list-style:none;cursor:pointer;padding:13px 16px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
  .jg > summary::-webkit-details-marker{display:none}
  .jg > summary:hover{background:var(--crema-2,#faf8f5);border-radius:14px}
  .jg[open] > summary{border-bottom:1px dashed var(--line);border-radius:14px 14px 0 0}
  .jg > summary .jg-tipo{order:1}
  .jg-mini{order:2;margin-left:auto;font-size:11.5px;font-weight:800;color:var(--muted);white-space:nowrap}
  .jg > summary .jg-t{order:3;flex:0 0 100%;margin-top:3px;font-size:15px;line-height:1.3}
  .jg.turno{border-color:var(--magenta,#EF4375);box-shadow:0 0 0 3px color-mix(in srgb,var(--magenta,#EF4375) 12%,transparent)}
  .jg-ahora{background:var(--magenta,#EF4375);color:#fff;font-size:10.5px;font-weight:800;letter-spacing:.5px;
    text-transform:uppercase;padding:5px 16px}
  /* Todo lo que va dentro del pliegue respira igual que antes.
     OJO: los hijos llevan margen lateral, así que los que iban a 100% de ancho
     sumaban 32px de más y SE SALÍAN del card por la derecha (el botón aparecía
     cortado). Con width:auto ocupan lo que queda, que es lo correcto. */
  .jg > *:not(summary):not(.jg-ahora){margin-left:16px;margin-right:16px}
  .jg > .jg-hacer, .jg > .jg-ver, .jg > .jg-ok2{width:calc(100% - 32px)}
  .jg > .jg-meta:last-of-type,.jg > .jg-live{margin-bottom:14px}
  .jg-top{display:flex;align-items:flex-start;gap:11px}
  .jg-tipo{flex:none;font-size:10.5px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;padding:5px 9px;border-radius:8px;background:var(--crema-2,#f2efe9);color:var(--muted)}
  .jg-tipo.pauta{background:#fff2e0;color:#a05a10}
  .jg-tipo.contenido{background:color-mix(in srgb,var(--magenta,#EF4375) 11%,#fff);color:var(--magenta,#EF4375)}
  .jg-tipo.oferta{background:#e9f6ee;color:#12734a}
  .jg-t{font-size:15px;font-weight:800;color:var(--tinta);line-height:1.3;flex:1}
  .jg-q{font-size:13.5px;color:var(--tinta);line-height:1.55;margin:9px 0 0}
  .jg-p{font-size:12.5px;color:var(--muted);line-height:1.5;margin:7px 0 0;font-style:italic}
  .jg-meta{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:11px;padding-top:11px;border-top:1px dashed var(--line)}
  .jg-tag{font-size:11.5px;font-weight:700;color:var(--muted);background:var(--crema-2,#f2efe9);padding:5px 9px;border-radius:8px;display:inline-flex;align-items:center;gap:5px}
  .jg-tag svg{width:12px;height:12px}
  .jg-tag.corillo{background:color-mix(in srgb,var(--teal,#00A49F) 12%,#fff);color:#0a6a5f}
  .jg-tag.dueno{background:#fff2e0;color:#a05a10}
  .jg-cta{font-size:12.5px;color:var(--tinta);background:color-mix(in srgb,var(--magenta,#EF4375) 7%,#fff);border-left:3px solid var(--magenta,#EF4375);padding:8px 11px;border-radius:0 9px 9px 0;margin-top:10px;line-height:1.45}
  .jg-ok{margin-left:auto;border:1.5px solid var(--line);background:transparent;color:var(--muted);font-family:inherit;font-weight:700;font-size:12px;padding:7px 12px;border-radius:9px;cursor:pointer;flex:none}
  .jg-ok:hover{border-color:var(--teal,#00A49F);color:var(--teal,#00A49F)}
  .jg.regla{background:var(--crema-2,#faf8f5);border-style:dashed}
  .jg-tag.regla{background:#eef2ff;color:#4338ca}

  /* El TRABAJO de la jugada: un punto por pieza. Vacío = por hacer,
     relleno claro = lista esperando OK, relleno fuerte = publicada. */
  .jg-trabajo{display:flex;align-items:center;gap:10px;margin-top:11px;flex-wrap:wrap}
  .jg-puntos{display:flex;gap:5px}
  .jg-puntos i{width:11px;height:11px;border-radius:50%;border:1.5px solid var(--line);background:transparent;display:block;transition:background .3s}
  .jg-puntos i.lista{background:color-mix(in srgb,var(--teal,#00A49F) 35%,#fff);border-color:color-mix(in srgb,var(--teal,#00A49F) 45%,#fff)}
  .jg-puntos i.pub{background:var(--teal,#00A49F);border-color:var(--teal,#00A49F)}
  .jg-est{font-size:12px;color:var(--muted);font-weight:700}

  /* La acción del card — nunca decorativo */
  .jg-hacer{width:100%;margin-top:12px;border:0;cursor:pointer;background:linear-gradient(135deg,var(--coral,#FF6B3D),var(--magenta,#EF4375));color:#fff;font-family:inherit;font-weight:800;font-size:14px;padding:12px 16px;border-radius:12px;display:flex;align-items:center;justify-content:center;gap:8px;transition:transform .12s}
  .jg-hacer:active{transform:scale(.98)}
  .jg-hacer:disabled{opacity:.6;cursor:default}
  .jg-hacer svg{width:16px;height:16px}
  .jg-hacer.sec{background:transparent;border:1.5px solid var(--line);color:var(--muted);font-size:13px;margin-top:8px}
  .jg-hacer.sec:hover{border-color:var(--magenta,#EF4375);color:var(--magenta,#EF4375)}
  .jg-ver{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;margin-top:12px;border:1.5px solid var(--line);background:var(--card,#fff);color:var(--tinta);text-decoration:none;font-weight:800;font-size:13.5px;padding:11px 16px;border-radius:12px}
  .jg-ver:hover{border-color:var(--teal,#00A49F);color:var(--teal,#00A49F)}
  .jg-ver svg{width:15px;height:15px}
  .jg-ok2{width:100%;margin-top:12px;border:1.5px solid var(--teal,#00A49F);background:transparent;color:var(--teal,#00A49F);font-family:inherit;font-weight:800;font-size:13.5px;padding:11px 16px;border-radius:12px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px}
  .jg-ok2:hover{background:color-mix(in srgb,var(--teal,#00A49F) 8%,#fff)}
  .jg-ok2 svg{width:15px;height:15px}
  .jg-live{font-size:12.5px;line-height:1.5;color:var(--muted);margin-top:9px;display:none}
  .jg-live.on{display:block}
  .jg-live b{color:var(--tinta)}
  .jg-live .pts span{display:inline-block;width:5px;height:5px;border-radius:50%;background:var(--magenta,#EF4375);margin:0 1px;animation:jgb 1s infinite}
  .jg-live .pts span:nth-child(2){animation-delay:.15s}.jg-live .pts span:nth-child(3){animation-delay:.3s}
  @keyframes jgb{0%,60%,100%{opacity:.3}30%{opacity:1}}
  .jg-live.ok{color:#0a6a5f;background:color-mix(in srgb,var(--teal,#00A49F) 10%,#fff);border:1px solid color-mix(in srgb,var(--teal,#00A49F) 28%,#fff);border-radius:11px;padding:10px 12px}

  /* Lo que solo el dueño puede dar: sus videos */
  .jg-video{margin-top:11px;background:#fff8e6;border:1px solid #f2dfae;border-radius:12px;padding:12px 14px;font-size:12.5px;line-height:1.55;color:#7a5b12}
  .jg-video b{display:block;color:#5c4409;font-size:13.5px;margin-bottom:3px}
  .jg-video a{display:inline-flex;align-items:center;gap:7px;margin-top:10px;background:#5c4409;color:#fff;text-decoration:none;font-weight:800;font-size:13px;padding:9px 14px;border-radius:10px}
  .jg-video a svg{width:14px;height:14px}

  /* ── LAS PUERTAS DE LA JUGADA ──────────────────────────────────────────
     Móvil: una fila por pieza, gorda, de borde a borde, pulgar abajo.
     Desktop: las mismas filas pero con aire y el estado a la derecha, para
     leer la secuencia completa de un vistazo. Misma pieza, dos densidades. */
  .jg-puertas{margin-top:12px;display:flex;flex-direction:column;gap:8px}
  .jg-puertas .pu{display:flex;align-items:center;gap:12px;text-decoration:none;
    background:#fff;border:1.5px solid #e9e4dc;border-radius:14px;padding:13px 14px;color:inherit;
    transition:transform .16s cubic-bezier(.22,1,.36,1),box-shadow .16s,border-color .16s}
  .jg-puertas .pu:active{transform:scale(.985)}
  .jg-puertas .pu-n{flex:none;min-width:44px;height:30px;display:inline-flex;align-items:center;justify-content:center;
    background:#f4f1ec;color:#6b6560;border-radius:8px;font-size:11.5px;font-weight:800;letter-spacing:.02em}
  .jg-puertas .pu-n svg{width:15px;height:15px}
  .jg-puertas .pu-t{flex:1;min-width:0}
  .jg-puertas .pu-t b{display:block;font-size:14.5px;line-height:1.25}
  .jg-puertas .pu-t small{display:block;color:#6b6560;font-size:12px;line-height:1.45;margin-top:2px}
  .jg-puertas .pu-go{flex:none;color:#b9b2a9}
  .jg-puertas .pu-go svg{width:16px;height:16px;display:block}
  /* La activa es la que manda: la única con color. */
  .jg-puertas .pu.on{border-color:var(--magenta,#EF4375);box-shadow:0 10px 24px -18px rgba(239,67,117,.75)}
  .jg-puertas .pu.on .pu-n{background:linear-gradient(135deg,#FF6B3D,#EF4375);color:#fff}
  .jg-puertas .pu.on .pu-go{color:var(--magenta,#EF4375)}
  .jg-puertas .pu.on:hover{transform:translateY(-1px);box-shadow:0 14px 30px -18px rgba(239,67,117,.85)}
  /* Las que esperan turno no gritan. */
  .jg-puertas .pu.esp{opacity:.62}
  .jg-puertas .pu.ok{background:#f7fbf8;border-color:#d8ece0}
  .jg-puertas .pu.ok .pu-n{background:#e6f7f0;color:#0a6a4a}
  @media (min-width:820px){
    .jg-puertas{gap:10px}
    .jg-puertas .pu{padding:15px 18px;gap:14px}
    .jg-puertas .pu-n{min-width:58px;height:34px;font-size:12px}
    .jg-puertas .pu-t b{font-size:15.5px}
    .jg-puertas .pu-t small{font-size:12.5px}
  }

  /* Encabezado del plan vigente + cumplimiento */
  .plan-cab{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;margin:0 0 12px;flex-wrap:wrap}
  .plan-cab h2{font-family:var(--font-display,'Oswald',sans-serif);font-size:17px;letter-spacing:.4px;color:var(--tinta);margin:0}
  .plan-v{font-size:12px;color:var(--muted);font-weight:600}
  .plan-prog{text-align:right;font-size:12.5px;color:var(--muted);font-weight:600;min-width:132px}
  .plan-prog b{color:var(--tinta)}
  .plan-barra{height:6px;border-radius:99px;background:var(--crema-2,#f2efe9);border:1px solid var(--line);overflow:hidden;margin-top:5px}
  .plan-barra i{display:block;height:100%;background:linear-gradient(90deg,var(--teal,#00A49F),var(--magenta,#EF4375));border-radius:99px;transition:width .5s}
  .plan-obs{background:color-mix(in srgb,var(--teal,#00A49F) 10%,#fff);border:1px solid color-mix(in srgb,var(--teal,#00A49F) 30%,#fff);color:#0a6a5f;border-radius:13px;padding:12px 14px;font-size:13px;line-height:1.55;margin:0 0 14px}

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
  .mt-volver{display:inline-block;font-size:14px;font-weight:700;color:var(--muted);
    text-decoration:none;padding:10px 0;margin-bottom:6px;min-height:44px;line-height:24px}

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

  /* ── PLAN CONTRA PLAN ──────────────────────────────────────────────────
     Móvil: una tarjeta por plan, en columna, se lee deslizando el pulgar.
     Desktop: lado a lado de verdad — que es el único sitio donde comparar
     dos columnas con la vista funciona. */
  .cmp{display:flex;flex-direction:column;gap:10px}
  .cmp-p{background:var(--card);border:1.5px solid var(--line);border-radius:16px;padding:14px 15px}
  .cmp-p.on{border-color:var(--magenta,#EF4375);box-shadow:0 12px 28px -22px rgba(239,67,117,.8)}
  .cmp-h{display:flex;align-items:baseline;gap:8px;flex-wrap:wrap;margin-bottom:10px}
  .cmp-h b{font-size:16px}
  .cmp-est{font-size:10.5px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;
    color:#6b6560;background:#f4f1ec;border-radius:6px;padding:3px 7px}
  .cmp-p.on .cmp-est{background:linear-gradient(135deg,#FF6B3D,#EF4375);color:#fff}
  .cmp-d{font-size:12px;color:var(--muted);margin-left:auto}
  .cmp-corto{margin:0 0 10px;font-size:12px;line-height:1.45;color:#8a6d1f;
    background:#fff8e6;border:1px solid #f2dfae;border-radius:9px;padding:8px 10px}
  .cmp-nums{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}
  .cmp-nums>div{background:#faf8f5;border-radius:11px;padding:10px 11px;position:relative}
  .cmp-nums b{display:block;font-size:19px;line-height:1.15;font-variant-numeric:tabular-nums}
  .cmp-nums span{display:block;font-size:11.5px;color:var(--muted);margin-top:2px}
  .cmp-nums i{position:absolute;top:9px;right:10px;font-style:normal;font-size:11px;font-weight:800;
    padding:2px 6px;border-radius:6px}
  .cmp-nums i.up{background:#e6f7f0;color:#0a6a4a}
  .cmp-nums i.dn{background:#fdeeee;color:#b4232b}
  .cmp-ritmo{margin:10px 0 0;font-size:12.5px;color:var(--tinta);line-height:1.5}
  .cmp-lec{margin:9px 0 0;font-size:12.5px;line-height:1.5;color:#4A434F;
    border-left:3px solid var(--teal,#00A49F);padding-left:9px}
  .cmp-nota{font-size:11.5px;color:var(--muted);line-height:1.5;margin:9px 2px 0}
  @media (min-width:860px){
    .cmp{flex-direction:row;align-items:flex-start}
    .cmp-p{flex:1;min-width:0}
    .cmp-nums{grid-template-columns:repeat(2,1fr)}
  }

  /* Historial de planes */
  .hplan{background:var(--card,#fff);border:1px solid var(--line);border-radius:14px;margin-bottom:9px;overflow:hidden}
  .hplan[open]{border-color:var(--teal,#00A49F)}
  .hplan summary{cursor:pointer;list-style:none;padding:13px 15px;display:flex;align-items:center;gap:9px;flex-wrap:wrap}
  .hplan summary::-webkit-details-marker{display:none}
  .hplan summary:hover{background:var(--crema-2,#faf8f5)}
  .hp-v{font-family:var(--font-display,'Oswald',sans-serif);font-size:15px;font-weight:700;color:var(--tinta);letter-spacing:.3px}
  .hp-f{font-size:12px;color:var(--muted);font-weight:600}
  .hp-est{margin-left:auto;font-size:11px;font-weight:800;padding:4px 9px;border-radius:99px;background:var(--crema-2,#f2efe9);color:var(--muted)}
  .hp-est.ok{background:color-mix(in srgb,var(--teal,#00A49F) 14%,#fff);color:#0a6a5f}
  .hp-vale{font-size:11px;font-weight:800;padding:4px 9px;border-radius:99px}
  .hp-vale.si{background:#e6f7f0;color:#0a6a4a}
  .hp-vale.no{background:#fdeeee;color:#b4232b}
  .hp-vale.nd{background:#fff4e0;color:#8a5a10}
  .hp-body{padding:0 15px 15px;border-top:1px dashed var(--line)}
  .hp-nums{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin:13px 0}
  .hp-nums div{background:var(--crema-2,#faf8f5);border:1px solid var(--line);border-radius:11px;padding:9px 8px;text-align:center}
  .hp-nums b{display:block;font-family:var(--font-display,'Oswald',sans-serif);font-size:19px;color:var(--tinta);line-height:1.1}
  .hp-nums span{display:block;font-size:10.5px;color:var(--muted);line-height:1.25;margin-top:3px}
  .hp-movio{font-size:13px;color:var(--tinta);line-height:1.5;margin:0 0 11px}
  .hp-lec{background:color-mix(in srgb,var(--magenta,#EF4375) 7%,#fff);border-left:3px solid var(--magenta,#EF4375);border-radius:0 10px 10px 0;padding:11px 13px;font-size:13px;line-height:1.55;color:var(--tinta)}
  .hp-lec.pend{background:var(--crema-2,#faf8f5);border-left-color:var(--line);color:var(--muted)}
  .hp-ev{display:block;margin-top:9px;border:1.5px solid var(--line);background:#fff;color:var(--tinta);font-family:inherit;font-weight:700;font-size:12.5px;padding:8px 13px;border-radius:10px;cursor:pointer}
  .hp-ev:hover{border-color:var(--teal,#00A49F);color:var(--teal,#00A49F)}
  .hp-lista{margin-top:13px}
  .hp-lista>b{display:block;font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:7px}
  .hp-t{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--muted);padding:4px 0;line-height:1.4}
  .hp-t.ok{color:var(--tinta)}
  .hp-t svg{width:14px;height:14px;flex:none;color:var(--line)}
  .hp-t.ok svg{color:var(--teal,#00A49F)}
  .hp-p{display:flex;gap:10px;align-items:baseline;padding:5px 0;border-bottom:1px dashed var(--line);font-size:12.5px}
  .hp-p:last-child{border-bottom:0}
  .hp-cap{flex:1;color:var(--tinta);line-height:1.4}
  .hp-m{flex:none;color:var(--muted);font-weight:700;font-size:11.5px}

  .glos{margin-top:22px;border-top:1px solid var(--line);padding-top:16px}
  .glos summary{cursor:pointer;font-size:13.5px;font-weight:700;color:var(--muted);list-style:none}
  .glos summary::-webkit-details-marker{display:none}
  .glos summary:hover{color:var(--tinta)}
  .glos dl{margin:13px 0 0;display:grid;gap:10px}
  .glos dt{font-size:13px;font-weight:800;color:var(--tinta)}
  .glos dd{margin:2px 0 0;font-size:12.5px;color:var(--muted);line-height:1.5}

  .mt-load{display:none;text-align:center;padding:44px 20px}
  .mt-load.on{display:block}
  .mt-load .sp{width:38px;height:38px;border:3px solid var(--line);border-top-color:var(--magenta,#EF4375);border-radius:50%;margin:0 auto 16px;animation:sp 1s linear infinite}
  @keyframes sp{to{transform:rotate(360deg)}}
  .mt-load b{display:block;font-size:16px;color:var(--tinta);margin-bottom:5px}
  .mt-load span{font-size:13.5px;color:var(--muted);line-height:1.5}

  /* ── DESKTOP: otra experiencia, no la misma estirada ──
     En el teléfono el plegado es la solución correcta (poco scroll, una cosa a
     la vez). Con pantalla grande sobra espacio: las jugadas se ven abiertas
     para poder COMPARARLAS, y el número se queda pegado mientras se lee el
     plan — así el "voy 0 de 25" nunca se pierde de vista. */
  @media(min-width:901px){
    .mv > div:first-child{position:sticky;top:18px}
    .jg{margin-bottom:2px}
  }
  @media(max-width:900px){ .mv{grid-template-columns:1fr} }
  @media(max-width:680px){
    .obj-grid{grid-template-columns:1fr}
    /* Los 4 números del récord no caben en una fila de 360px: 2x2 y se leen. */
    .hp-nums{grid-template-columns:repeat(2,minmax(0,1fr))}
    .plan-cab{align-items:flex-start}
    .plan-prog{text-align:left;min-width:100%}
    .hplan summary{padding:12px 13px}
    .hp-est{margin-left:0}
    .wz-q{font-size:22px}
    .mv-num{font-size:46px}
    .mt-num input{width:150px;font-size:32px}
    .wz-nav .btn-p{flex:1;justify-content:center}
  }
</style>

<?php if (!$meta && $vista === 'wizard'): /* ══════════ WIZARD ══════════ */ ?>

<div class="wz">
  <h1 class="mt-h1">¿Qué quieres lograr?</h1>
  <p class="mt-sub">Ponle un norte a tu negocio y el corillo trabaja para eso — no para llenar el calendario.
     Son tres preguntas cortas.</p>

  <div class="wz-bar"><i id="wz-bar" style="width:25%"></i></div>

  <!-- PASO 1 · el deseo, en sus palabras -->
  <section class="wz-paso on" data-paso="1">
    <h2 class="wz-q">Dime qué te haría feliz este mes</h2>
    <p class="wz-ayuda">Escoge lo que más falta te hace ahora mismo. Después lo puedes cambiar.</p>
    <div class="obj-grid">
      <?php foreach ($objetivos as $k => $o): ?>
        <button type="button" class="obj" data-obj="<?= $h($k) ?>" data-unidad="<?= $h($o['unidad']) ?>"
                data-pregunta="<?= $h($o['pregunta']) ?>" data-etiqueta="<?= $h($o['unidad']==='dolares' ? 'dólares' : $o['unidad']) ?>">
          <span class="ic"><?= ico($o['ico']) ?></span>
          <b><?= $h($o['titulo']) ?></b>
          <p><?= $h($o['explicacion']) ?></p>
          <span class="jerga"><?= $h($o['jerga']) ?></span>
        </button>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- PASO 2 · cuánto y para cuándo -->
  <section class="wz-paso" data-paso="2">
    <h2 class="wz-q" id="q2">¿Cuánto quieres lograr?</h2>
    <p class="wz-ayuda">Un número te deja saber si vas bien o si hay que apretar. Si no sabes cuál poner,
       yo te lo digo mirando tus propios números.</p>
    <div class="mt-num">
      <input type="number" id="cantidad" min="1" step="1" placeholder="25" inputmode="numeric">
      <span class="mt-unidad" id="unidad">pedidos</span>
      <button type="button" class="mt-nose" id="nose">No sé — dime tú</button>
    </div>
    <div class="mt-tip" id="tip-num"></div>

    <p class="wz-ayuda" style="margin:22px 0 8px"><b style="color:var(--tinta)">¿Para cuándo?</b></p>
    <div class="chips" id="chips-fecha">
      <button type="button" class="chip" data-dias="14">En 2 semanas</button>
      <button type="button" class="chip sel" data-dias="30">En un mes</button>
      <button type="button" class="chip" data-dias="60">En 2 meses</button>
      <button type="button" class="chip" data-dias="90">En 3 meses</button>
    </div>
  </section>

  <!-- PASO 3 · presupuesto + contexto -->
  <section class="wz-paso" data-paso="3">
    <h2 class="wz-q">¿Puedes invertir algo en anuncios?</h2>
    <p class="wz-ayuda">Pagarle a Instagram o Facebook para que le enseñen tu post a más gente del área
       — a eso le dicen <b>boost</b> o <b>pauta</b>. Con $10 o $20 ya se nota. Si ahora no puedes, no hay
       problema: el corillo trabaja sin pagar anuncios y no te lo va a recomendar.</p>
    <div class="chips" id="chips-pauta">
      <button type="button" class="chip sel" data-pauta="0">Nada por ahora<small>Todo sin pagar anuncios</small></button>
      <button type="button" class="chip" data-pauta="20">$20 al mes<small>Para empujar 1 o 2 posts</small></button>
      <button type="button" class="chip" data-pauta="50">$50 al mes<small>Alcance serio en tu área</small></button>
      <button type="button" class="chip" data-pauta="100">$100 o más<small>Campaña de verdad</small></button>
    </div>

    <p class="wz-ayuda" style="margin:24px 0 8px"><b style="color:var(--tinta)">¿Con qué cuentas?</b>
       Cuéntame si tienes una oferta, un producto que quieres empujar, una fecha especial o un evento.
       Mientras más me digas, mejor el plan. (Opcional)</p>
    <textarea class="mt-libre" id="contexto" maxlength="600"
      placeholder="Ej: Tengo el combo de brazo gitano a $18 y en agosto son las fiestas del pueblo."></textarea>
  </section>

  <div class="wz-nav" id="wz-nav">
    <button type="button" class="btn-s" id="atras" style="display:none">Atrás</button>
    <button type="button" class="btn-p" id="sigue" disabled>Siguiente</button>
  </div>

  <div class="mt-load" id="cargando">
    <div class="sp"></div>
    <b>La Estratega está armando tu plan</b>
    <span>Está mirando tu negocio, tus números y el calendario para decidir las jugadas.<br>Dale unos segundos.</span>
  </div>

  <details class="glos">
    <summary>¿Qué significan las palabras raras del mercadeo?</summary>
    <dl>
      <?php foreach ($glosario as $t => $d): ?>
        <dt><?= $h(ucfirst($t)) ?></dt><dd><?= $h($d) ?></dd>
      <?php endforeach; ?>
    </dl>
  </details>
</div>

<script>
(function(){
  var CSRF=<?= json_encode(csrf_token()) ?>, MARCA=<?= (int)$marca_id ?>;
  var paso=1, datos={objetivo:'',cantidad:'',dias:30,pauta:0,contexto:''};
  var bar=document.getElementById('wz-bar'), sigue=document.getElementById('sigue'), atras=document.getElementById('atras');

  function ver(n){
    paso=n;
    document.querySelectorAll('.wz-paso').forEach(function(s){ s.classList.toggle('on', +s.dataset.paso===n); });
    bar.style.width=(n*25+ (n===3?25:0))+'%';
    atras.style.display = n>1 ? '' : 'none';
    sigue.textContent = n===3 ? 'Armar mi plan' : 'Siguiente';
    revisar();
    window.scrollTo({top:0,behavior:'smooth'});
  }
  function revisar(){
    if(paso===1) sigue.disabled = !datos.objetivo;
    else if(paso===2) sigue.disabled = !(datos.cantidad && +datos.cantidad>0);
    else sigue.disabled=false;
  }

  // Paso 1 — escoger objetivo
  document.querySelectorAll('.obj').forEach(function(b){
    b.addEventListener('click', function(){
      document.querySelectorAll('.obj').forEach(function(x){x.classList.remove('sel');});
      b.classList.add('sel');
      datos.objetivo=b.dataset.obj;
      // La pregunta viene ESCRITA por objetivo (antes se armaba pegando la unidad
      // y salía "¿Cuántos interacciones quieres?" — mal dicho y mal visto).
      document.getElementById('unidad').textContent = b.dataset.etiqueta;
      document.getElementById('q2').textContent = b.dataset.pregunta;
      document.getElementById('tip-num').classList.remove('on');
      revisar();
    });
  });

  // Paso 2 — cantidad y fecha
  var cant=document.getElementById('cantidad');
  cant.addEventListener('input', function(){ datos.cantidad=cant.value; revisar(); });
  document.getElementById('chips-fecha').addEventListener('click', function(e){
    var c=e.target.closest('.chip'); if(!c) return;
    this.querySelectorAll('.chip').forEach(function(x){x.classList.remove('sel');});
    c.classList.add('sel'); datos.dias=+c.dataset.dias;
  });
  document.getElementById('nose').addEventListener('click', function(){
    var tip=document.getElementById('tip-num');
    tip.textContent='Mirando tus números…'; tip.classList.add('on');
    var fd=new FormData(); fd.append('csrf',CSRF); fd.append('accion','sugerir');
    fd.append('objetivo',datos.objetivo); fd.append('dias',datos.dias);
    fetch(location.pathname+'?marca='+MARCA,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
      if(d.ok && d.sugerido){ cant.value=d.sugerido; datos.cantidad=String(d.sugerido); tip.textContent=d.razon; revisar(); }
      else { tip.textContent = (d && d.razon) ? d.razon : 'Todavía no tengo con qué compararte. Pon el número que te haga sentido.'; }
    }).catch(function(){ tip.textContent='No pude mirar tus números ahora. Pon el que te haga sentido.'; });
  });

  // Paso 3 — pauta
  document.getElementById('chips-pauta').addEventListener('click', function(e){
    var c=e.target.closest('.chip'); if(!c) return;
    this.querySelectorAll('.chip').forEach(function(x){x.classList.remove('sel');});
    c.classList.add('sel'); datos.pauta=+c.dataset.pauta;
  });

  atras.addEventListener('click', function(){ if(paso>1) ver(paso-1); });
  sigue.addEventListener('click', function(){
    if(paso<3){ ver(paso+1); return; }
    datos.contexto=document.getElementById('contexto').value;
    // Armar el plan
    document.querySelectorAll('.wz-paso').forEach(function(s){s.classList.remove('on');});
    document.getElementById('wz-nav').style.display='none';
    document.getElementById('cargando').classList.add('on');
    bar.style.width='100%';
    var f=new Date(); f.setDate(f.getDate()+datos.dias);
    // La fecha se arma con la hora LOCAL, no con toISOString(): en Puerto Rico
    // (UTC-4) a partir de las 8 de la noche toISOString ya devuelve el día
    // siguiente, así que la meta salía con un día de más.
    var fLocal = f.getFullYear() + '-' +
                 String(f.getMonth()+1).padStart(2,'0') + '-' +
                 String(f.getDate()).padStart(2,'0');
    var fd=new FormData();
    fd.append('csrf',CSRF); fd.append('accion','crear');
    fd.append('objetivo',datos.objetivo); fd.append('cantidad',datos.cantidad);
    fd.append('fecha_limite', fLocal);
    fd.append('presupuesto',datos.pauta); fd.append('contexto',datos.contexto);
    fetch(location.pathname+'?marca='+MARCA,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
      if(d.ok){ location.href=location.pathname+'?marca='+MARCA; return; }
      document.getElementById('cargando').classList.remove('on');
      document.getElementById('wz-nav').style.display='';
      alert(d.err||'No pude armar el plan. Intenta otra vez.');
      ver(3);
    }).catch(function(){
      document.getElementById('cargando').classList.remove('on');
      document.getElementById('wz-nav').style.display='';
      alert('Se cayó la conexión. Intenta otra vez.'); ver(3);
    });
  });
  ver(1);
})();
</script>

<?php elseif ($meta && $vista === 'plan'): /* ══════ SEGUNDA CAPA · EL PLAN COMPLETO ══════ */
  $def = meta_objetivo_def((string)$meta['objetivo']);
  $pct = $prog['pct'] !== null ? (int)$prog['pct'] : 0;
?>
<a href="<?= $BASE ?>/meta.php?marca=<?= $marca_id ?>" class="mt-volver">&larr; Volver a lo que toca ahora</a>

<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;margin-bottom:18px">
  <div>
    <h1 class="mt-h1">Tu meta</h1>
    <p class="mt-sub"><?= $h($def['titulo']) ?><?php if (!empty($meta['fecha_limite'])): ?>
      · para el <?= $h(date('j/n/Y', strtotime((string)$meta['fecha_limite']))) ?><?php endif; ?></p>
  </div>
  <div style="display:flex;gap:9px">
    <a href="<?= $BASE ?>/sala.php?marca=<?= $marca_id ?>" class="btn-s" style="text-decoration:none;display:inline-flex;align-items:center;gap:7px"><?= ico('chat') ?> Discutirla con el corillo</a>
  </div>
</div>

<div class="mv">
  <!-- Columna izquierda: el número -->
  <div>
    <div class="card">
      <?php if ($prog['medible'] && $prog['actual'] !== null): ?>
        <div class="mv-num"><?= $h(number_format((float)$prog['actual'], (string)$meta['objetivo']==='ventas' ? 0 : 0)) ?></div>
        <div class="mv-de">de <?= $h(meta_fmt($meta['cantidad'] !== null ? (float)$meta['cantidad'] : null, (string)$meta['objetivo'])) ?> · <?= $h($def['verbo']) ?></div>
        <div class="mv-barra"><i style="width:<?= max(2, min(100, $pct)) ?>%"></i></div>
        <div class="mv-pie">
          <span><?= $pct ?>% logrado</span>
          <?php if ($prog['dias_rest'] !== null): ?>
            <span><?= $prog['dias_rest'] > 0 ? 'quedan ' . (int)$prog['dias_rest'] . ' días' : 'se venció' ?></span>
          <?php endif; ?>
        </div>
        <?php if ($prog['al_dia'] === true): ?>
          <div class="mv-est bien"><?= ico('check-circle') ?> Vas en ritmo</div>
        <?php elseif ($prog['al_dia'] === false): ?>
          <div class="mv-est mal"><?= ico('bolt') ?> Vas atrasado — hay que apretar</div>
        <?php endif; ?>
        <?php if (!empty($prog['ritmo_dia'])): ?>
          <p style="font-size:12.5px;color:var(--muted);line-height:1.5;margin:12px 0 0">
            Para llegar necesitas como <b style="color:var(--tinta)"><?= $h(number_format((float)$prog['ritmo_dia'], 1)) ?></b>
            <?= $h($def['unidad']) ?> al día de aquí a la fecha.</p>
        <?php endif; ?>
      <?php else: ?>
        <div class="mv-num"><?= $h(meta_fmt($meta['cantidad'] !== null ? (float)$meta['cantidad'] : null, (string)$meta['objetivo'])) ?></div>
        <div class="mv-de"><?= $h($def['verbo']) ?></div>
        <div class="mv-nomed">
          <b>Todavía no puedo contarte esto solo.</b><br>
          <?= $h($prog['como_medir'] !== '' ? $prog['como_medir'] : 'Cuando haya datos reales, aquí te muestro cómo vas. No te voy a inventar un número.') ?>
        </div>
      <?php endif; ?>

      <?php if (trim((string)$meta['contexto']) !== ''): ?>
        <p style="font-size:12.5px;color:var(--muted);line-height:1.5;margin:14px 0 0;padding-top:13px;border-top:1px dashed var(--line)">
          <b style="color:var(--tinta)">Lo que me contaste:</b><br><?= $h($meta['contexto']) ?></p>
      <?php endif; ?>

      <?php /* "Rehacer el plan" no decía qué hacía: el dueño no sabía si perdía
               lo trabajado. Ahora dice lo que es —empezar un plan nuevo— y antes
               de arrancar explica exactamente qué pasa con lo viejo. */ ?>
      <div style="display:flex;gap:8px;margin-top:16px;flex-wrap:wrap">
        <button type="button" class="btn-s" id="replan" style="flex:1"><?= ico('refresh') ?> Empezar un plan nuevo</button>
        <button type="button" class="btn-s" id="cerrar" style="flex:1">Cambiar de meta</button>
      </div>
      <p style="margin:9px 2px 0;font-size:12px;line-height:1.5;color:var(--muted)">
        Un plan nuevo son jugadas nuevas para esta misma meta. El plan de ahora pasa a
        historial y lo que el corillo ya te hizo se queda en Tus Posts.</p>
    </div>
  </div>

  <!-- Columna derecha: diagnóstico + jugadas -->
  <div>
    <?php if (trim((string)$meta['diagnostico']) !== ''): ?>
      <div class="diag">
        <div class="qui"><?= ico('sparkles') ?> Lo que dice la Estratega</div>
        <p><?= $h($meta['diagnostico']) ?></p>
        <?php if (!empty($meta['veredicto'])): ?>
          <span class="vered <?= $h($meta['veredicto']) ?>">
            <?= $meta['veredicto']==='alcanzable' ? 'Se puede' : ($meta['veredicto']==='ambiciosa' ? 'Es ambiciosa, pero se pelea' : 'Muy cuesta arriba — mira lo que propongo') ?>
          </span>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($tacticas): ?>
      <div class="plan-cab">
        <div>
          <h2>Las jugadas para lograrlo</h2>
          <?php if ($plan_act): ?>
            <span class="plan-v">Plan #<?= (int)$plan_act['version'] ?> · desde el <?= $h(date('j/n', strtotime((string)$plan_act['inicio_at']))) ?></span>
          <?php endif; ?>
        </div>
        <?php if ($prog_plan && $prog_plan['total'] > 0): ?>
          <div class="plan-prog">
            <b><?= (int)$prog_plan['hechas'] ?> de <?= (int)$prog_plan['total'] ?></b> hechas
            <div class="plan-barra"><i style="width:<?= max(3, (int)$prog_plan['pct']) ?>%"></i></div>
          </div>
        <?php endif; ?>
      </div>
      <?php if ($prog_plan && $prog_plan['completo']): ?>
        <div class="plan-obs">
          <b>Cumpliste el plan completo.</b> Ahora el corillo está pendiente de los números de esos posts.
          Cuando Instagram y Facebook reporten, el Analista te dice si funcionó y qué cambiar — no antes,
          para no juzgar con datos que todavía no existen.
        </div>
      <?php endif; ?>
      <div class="jug">
        <?php
        // La jugada de turno va ABIERTA y marcada; las demás plegadas. Seis
        // jugadas abiertas eran 8,000px de scroll en un teléfono, todas con el
        // mismo peso y sin señal de por dónde empezar.
        $__turno = meta_tactica_de_turno($pdo, $meta);
        $__turno_id = $__turno ? (int)$__turno['id'] : 0;
        foreach ($tacticas as $t):
          $tipo_lbl = ['contenido'=>'Contenido','distribucion'=>'Difusión','pauta'=>'Anuncio pagado',
                       'oferta'=>'Oferta','alianza'=>'Alianza','operacion'=>'Cómo operar'][$t['tipo']] ?? $t['tipo'];
          $clase = (string)($t['clase'] ?? 'produccion');
          $jp    = jugada_progreso($pdo, $t);
          $hecha = $t['estado'] === 'hecha';
          $es_turno = ((int)$t['id'] === $__turno_id) && !$hecha;
          // Resumen corto para cuando está plegada: que se entienda sin abrir.
          if ($hecha)                       $mini = 'Hecha';
          elseif ($clase === 'regla')       $mini = 'Siempre';
          elseif ($clase === 'accion_dueno')$mini = 'La haces tú';
          elseif ((int)$jp['espera_video'] > 0) $mini = 'Falta tu video';
          elseif ((int)$jp['creadas'] === 0)   $mini = (int)$jp['meta'] . ($jp['meta'] == 1 ? ' pieza' : ' piezas');
          else                              $mini = (int)$jp['publicadas'] . '/' . (int)$jp['meta'] . ' publicadas';
        ?>
          <details class="jg <?= $hecha?'hecha':'' ?> <?= $clase==='regla'?'regla':'' ?> <?= $es_turno?'turno':'' ?>"
                   data-id="<?= (int)$t['id'] ?>" <?= $es_turno ? 'open' : '' ?>>
            <summary class="jg-sum">
              <span class="jg-tipo <?= $h($t['tipo']) ?>"><?= $h($tipo_lbl) ?></span>
              <span class="jg-t"><?= $h($t['titulo']) ?></span>
              <span class="jg-mini"><?= $h($mini) ?></span>
            </summary>
            <?php if ($es_turno): ?><div class="jg-ahora">Por aquí seguimos</div><?php endif; ?>
            <?php if (trim((string)$t['que_hacer']) !== ''): ?>
              <p class="jg-q"><?= $h($t['que_hacer']) ?></p><?php endif; ?>
            <?php if (trim((string)$t['por_que']) !== ''): ?>
              <p class="jg-p">Por qué: <?= $h($t['por_que']) ?></p><?php endif; ?>
            <?php if (trim((string)$t['cta']) !== ''): ?>
              <div class="jg-cta"><b>Lo que le pedimos a la gente:</b> <?= $h($t['cta']) ?></div><?php endif; ?>

            <?php if ($clase === 'produccion' && (int)$jp['meta'] > 0): ?>
              <?php /* EL TRABAJO REAL de la jugada: puntos que se van llenando
                       según las piezas se crean y se publican. Nadie marca esto. */ ?>
              <div class="jg-trabajo">
                <div class="jg-puntos">
                  <?php for ($i = 0; $i < (int)$jp['meta']; $i++): ?>
                    <i class="<?= $i < (int)$jp['publicadas'] ? 'pub' : ($i < (int)$jp['creadas'] ? 'lista' : '') ?>"></i>
                  <?php endfor; ?>
                </div>
                <span class="jg-est">
                  <?php if ((int)$jp['creadas'] === 0): ?>
                    <?= (int)$jp['meta'] ?> <?= (int)$jp['meta'] === 1 ? 'pieza' : 'piezas' ?> por hacer
                  <?php elseif ((int)$jp['publicadas'] >= (int)$jp['meta']): ?>
                    <?= (int)$jp['publicadas'] ?> publicadas — cumplida
                  <?php else: ?>
                    <?= (int)$jp['publicadas'] ?> publicadas · <?= (int)$jp['creadas'] - (int)$jp['publicadas'] ?> esperando tu OK
                  <?php endif; ?>
                </span>
              </div>

              <?php /* LAS PUERTAS — una cosa a la vez, y cada una abre DONDE se
                       hace: el carrusel en su constructor, el reel en el estudio
                       con su guion, el post en su preview. Nada de listas. */ ?>
              <?php $puertas = jugada_puertas($pdo, $t, $marca_id, $BASE); ?>
              <?php if ($puertas): ?>
                <div class="jg-puertas">
                  <?php foreach ($puertas as $pu): ?>
                    <a class="pu<?= $pu['listo'] ? ' ok' : ($pu['activa'] ? ' on' : ' esp') ?>"
                       href="<?= $h($pu['href']) ?>">
                      <span class="pu-n"><?= $pu['listo'] ? ico('check-circle') : $pu['n'] . ' de ' . $pu['total'] ?></span>
                      <span class="pu-t">
                        <b><?= $h($pu['titulo']) ?></b>
                        <small>
                          <?php if ($pu['listo']): ?>
                            <?= $pu['estado'] === 'publicado' ? 'Publicado' : 'Listo' ?><?= $pu['cuando'] !== '' ? ' · sale ' . $h($pu['cuando']) : '' ?>
                          <?php elseif ($pu['tipo'] === 'reel'): ?>
                            El guion está escrito — falta tu video
                          <?php elseif ($pu['tipo'] === 'carrusel'): ?>
                            La historia está escrita — faltan las imágenes
                          <?php else: ?>
                            Míralo y dale tu OK<?= $pu['cuando'] !== '' ? ' · sale ' . $h($pu['cuando']) : '' ?>
                          <?php endif; ?>
                        </small>
                      </span>
                      <span class="pu-go"><?= ico('send') ?></span>
                    </a>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

              <?php if ((int)$jp['espera_video'] > 0 && !$puertas): ?>
                <?php /* Lo único que el corillo NO puede hacer solo: el video.
                         Se dice claro y se le da el camino, en vez de fingir
                         que la pieza está lista. */ ?>
                <div class="jg-video">
                  <b><?= (int)$jp['espera_video'] === 1 ? 'Te falta grabar 1 video' : 'Te faltan ' . (int)$jp['espera_video'] . ' videos' ?></b>
                  Ya te escribí el guion — dice exactamente qué grabar, clip por clip, con el celular.
                  Súbelos y yo los monto con música, textos y tu marca.
                  <a href="<?= $BASE ?>/reels.php?marca=<?= $marca_id ?><?= !empty($jp['espera_video_id']) ? '&pieza=' . (int)$jp['espera_video_id'] : '' ?>"><?= ico('camera') ?> Subir mis videos</a>
                </div>
              <?php endif; ?>
            <?php endif; ?>

            <div class="jg-meta">
              <?php if ($clase === 'regla'): ?>
                <span class="jg-tag regla"><?= ico('bookmark') ?> Regla del negocio</span>
              <?php else: ?>
                <span class="jg-tag <?= $clase==='accion_dueno'?'dueno':'corillo' ?>">
                  <?= $clase==='accion_dueno' ? ico('users') . ' Lo haces tú' : ico('sparkles') . ' Lo hace el corillo' ?>
                </span>
              <?php endif; ?>
              <?php if ($t['inversion'] !== null): ?>
                <span class="jg-tag"><?= ico('dollar') ?> $<?= $h(number_format((float)$t['inversion'], 0)) ?></span>
              <?php endif; ?>
              <span class="jg-tag"><?= ico('clock') ?> Semana <?= (int)$t['semana'] ?></span>
            </div>

            <?php /* LA ACCIÓN — el card nunca es decorativo: siempre hace algo */ ?>
            <?php if (!$hecha && $clase === 'produccion'): ?>
              <?php if ((int)$jp['creadas'] === 0): ?>
                <button type="button" class="jg-hacer" data-id="<?= (int)$t['id'] ?>">
                  <?= ico('sparkles') ?> Que lo haga el corillo</button>
              <?php else: ?>
                <a class="jg-ver" href="<?= $BASE ?>/propuestas.php?marca=<?= $marca_id ?>&jugada=<?= (int)$t['id'] ?>">
                  <?= ico('list') ?> Ver <?= (int)$jp['creadas'] === 1 ? 'la pieza' : 'las ' . (int)$jp['creadas'] . ' piezas' ?> de esta jugada</a>
                <?php if ((int)$jp['creadas'] < (int)$jp['meta']): ?>
                  <button type="button" class="jg-hacer sec" data-id="<?= (int)$t['id'] ?>">
                    Que haga <?= (int)$jp['meta'] - (int)$jp['creadas'] ?> más</button>
                <?php endif; ?>
              <?php endif; ?>
            <?php elseif (!$hecha && $clase === 'accion_dueno'): ?>
              <button type="button" class="jg-ok2" data-id="<?= (int)$t['id'] ?>">
                <?= ico('check-circle') ?> Ya lo hice</button>
            <?php endif; ?>
            <div class="jg-live" data-for="<?= (int)$t['id'] ?>"></div>
          </details>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="card">
        <p style="margin:0;font-size:14px;color:var(--muted);line-height:1.55">
          La Estratega todavía no dejó las jugadas. Dale a <b>Rehacer el plan</b> y lo arma de nuevo.</p>
      </div>
    <?php endif; ?>

    <?php /* ── COMPARAR PLANES ────────────────────────────────────────────
         El historial ya guardaba el récord de cada plan, pero uno debajo del
         otro. Aquí se ven juntos y con su delta, que es lo que contesta la
         pregunta de verdad: ¿este plan lo está haciendo mejor que el anterior? */ ?>
    <?php $comp = $meta ? meta_planes_comparar($pdo, (int)$meta['id']) : []; ?>
    <?php if (count($comp) >= 2): ?>
      <h2 style="font-family:var(--font-display,'Oswald',sans-serif);font-size:17px;letter-spacing:.4px;color:var(--tinta);margin:26px 0 4px">
        Plan contra plan</h2>
      <p style="font-size:12.5px;color:var(--muted);line-height:1.5;margin:0 0 12px">
        Cada plan medido en SU ventana, y el ritmo por semana para que ventanas
        distintas se puedan comparar sin trampa.</p>

      <div class="cmp">
        <?php foreach ($comp as $c): $ps = $c['por_semana']; ?>
          <div class="cmp-p<?= $c['activo'] ? ' on' : '' ?>">
            <div class="cmp-h">
              <b>Plan #<?= $c['version'] ?></b>
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
                  <i class="<?= $c['delta']['publicadas'] >= 0 ? 'up':'dn' ?>"><?= ($c['delta']['publicadas']>=0?'+':'') . $c['delta']['publicadas'] ?>%</i>
                <?php endif; ?>
              </div>
              <div><b><?= $c['alcance'] !== null ? number_format((float)$c['alcance']) : '—' ?></b><span>alcance</span>
                <?php if (isset($c['delta']['alcance'])): ?>
                  <i class="<?= $c['delta']['alcance'] >= 0 ? 'up':'dn' ?>"><?= ($c['delta']['alcance']>=0?'+':'') . $c['delta']['alcance'] ?>%</i>
                <?php endif; ?>
              </div>
              <div><b><?= $c['movio'] !== null ? $h(meta_fmt((float)$c['movio'], (string)$c['objetivo'])) : '—' ?></b><span>movió la meta</span>
                <?php if (isset($c['delta']['movio'])): ?>
                  <i class="<?= $c['delta']['movio'] >= 0 ? 'up':'dn' ?>"><?= ($c['delta']['movio']>=0?'+':'') . $c['delta']['movio'] ?>%</i>
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
      <p class="cmp-nota">Una raya (—) quiere decir <b>sin dato todavía</b>, no cero: Instagram y Facebook
        reportan con retraso. Los porcentajes comparan contra el plan anterior, por semana.</p>
    <?php endif; ?>

    <?php if ($historial): ?>
      <h2 id="aprendizaje" style="font-family:var(--font-display,'Oswald',sans-serif);font-size:17px;letter-spacing:.4px;color:var(--tinta);margin:26px 0 4px;scroll-margin-top:16px">
        Planes anteriores</h2>
      <p style="font-size:12.5px;color:var(--muted);line-height:1.5;margin:0 0 12px">
        Cada plan guarda su propio récord: qué se hizo, qué se publicó y qué dejó. Ábrelos para ver los resultados.</p>

      <?php foreach ($historial as $hh):
        $p = $hh['plan']; $pr = $hh['prog']; $rs = $hh['res'];
        $vale = $p['funciono'] === null ? null : ((int)$p['funciono'] === 1);
      ?>
        <details class="hplan">
          <summary>
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
    <?php endif; ?>

    <details class="glos">
      <summary>¿Qué significan las palabras raras del mercadeo?</summary>
      <dl>
        <?php foreach ($glosario as $t => $d): ?>
          <dt><?= $h(ucfirst($t)) ?></dt><dd><?= $h($d) ?></dd>
        <?php endforeach; ?>
      </dl>
    </details>
  </div>
</div>

<script>
(function(){
  var CSRF=<?= json_encode(csrf_token()) ?>, MARCA=<?= (int)$marca_id ?>, URL=location.pathname+'?marca='+MARCA;
  function post(d){ var fd=new FormData(); fd.append('csrf',CSRF); for(var k in d) fd.append(k,d[k]);
    return fetch(URL,{method:'POST',body:fd}).then(function(r){return r.json();}); }

  // DESKTOP: las jugadas se abren todas. Plegarlas es la respuesta al scroll del
  // teléfono; con pantalla grande, esconder información que cabe es quitarle al
  // dueño la posibilidad de comparar su plan de un vistazo.
  // (El dueño puede cerrarlas a mano si quiere; solo cambia el estado inicial.)
  if (window.matchMedia('(min-width:901px)').matches) {
    document.querySelectorAll('details.jg').forEach(function(d){ d.open = true; });
  }

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

  document.getElementById('replan').addEventListener('click', function(){
    if(!confirm('¿Empezar un plan nuevo?\n\nLa Estratega arma jugadas nuevas para esta misma meta.\n\n· El plan de ahora pasa a historial.\n· Lo que el corillo ya te hizo se queda en Tus Posts.\n· Tu meta, tu marca y tu Genoma no se tocan.')) return;
    var b=this, orig=b.innerHTML;
    b.disabled=true; b.textContent='La Estratega está pensando…';
    post({accion:'replan'}).then(function(d){
      if(d.ok) location.reload();
      else { b.disabled=false; b.innerHTML=orig; alert(d.err||'No pude armar el plan nuevo.'); }
    }).catch(function(){ b.disabled=false; b.innerHTML=orig; });
  });

  document.getElementById('cerrar').addEventListener('click', function(){
    if(!confirm('¿Cambiar de meta? El corillo dejará de perseguir esta.')) return;
    post({accion:'cerrar'}).then(function(){ location.reload(); });
  });
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
            <p class="ah-monto">Presupuesto de esta jugada: <b><?= $h('$' . rtrim(rtrim(number_format((float)($E->evidencia['inversion'] ?? 0), 2), '0'), '.')) ?></b></p>
            <ol>
              <li>Abre la publicación en Instagram o Facebook desde tu teléfono.</li>
              <li>Toca <b>Promocionar</b> (o <b>Impulsar publicación</b>).</li>
              <li>Escoge el público de tu zona y pon el presupuesto de arriba.</li>
              <li>Confirma el pago en la app de Meta — eso lo haces tú, no yo.</li>
            </ol>
            <p class="ah-aviso">Yo no puedo promocionarlo por ti ni ver si el pago salió.
              Cuando lo hayas hecho, dímelo aquí y lo doy por hecho.</p>
            <button type="button" class="tm-btn linea" id="ahConfirmar"
                    data-jugada="<?= (int)($E->evidencia['tactica_id'] ?? 0) ?>"><?= ico('check') ?>Confirmar que ya lo promocioné</button>
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

  //  OJO AL MOMENTO. La barra de abajo y el boton de Ayuda los pinta
  //  _shell_foot.php, DESPUES de este bloque: preguntarlos ahora devuelve null
  //  y las dos rutinas de abajo salen en vacio sin quejarse -que es justo lo
  //  que pasaba: se apartaba Ayuda en el papel y nunca en la pantalla-.
  var alCargar = function(fn){
    if (document.readyState === 'complete') { fn(); return; }
    window.addEventListener('load', fn);
  };

  //  LA ZONA SEGURA ES EL FALTANTE, Y SE MIDE.
  //
  //  Antes aqui habia 300px fijos. El numero salio de leer mal una medicion y
  //  creo una pantalla de vacio en TODAS las vistas de Tu Meta. La cuenta de
  //  verdad es corta: con la pagina al final del scroll, el ultimo control
  //  tiene que quedar por encima de la barra fija.
  //
  //      doc >= ultimo_en_pagina + alto_de_lo_fijo + margen
  //
  //  Lo que falte para eso —y solo eso— es la zona segura. Como .content ya
  //  reserva 104px para la barra, casi siempre sale 0: reservarlo otra vez
  //  aqui seria contarlo dos veces, que es justo el error anterior.
  (function(){
    var ah = document.querySelector('.ah'); if (!ah) return;
    var MARGEN = 20;
    var ajustar = function(){
      ah.style.setProperty('--ah-zona', '0px');          // medir sin lo puesto
      var vp  = window.innerHeight;
      var doc = document.documentElement.scrollHeight;

      //  EL TECHO son las DOS capas de abajo, no solo la barra: Ayuda flota
      //  POR ENCIMA del hueco que reserva .content, y es la que de verdad
      //  tapaba el ultimo renglon. Se toma la mas alta de las dos, medida.
      var techo = vp;
      [].forEach.call(document.querySelectorAll('.botnav, .ay-fab'), function(c){
        if (getComputedStyle(c).display === 'none') return;
        var t = c.getBoundingClientRect().top;
        if (t > vp * 0.5 && t < techo) techo = t;
      });
      var fijo = Math.round(vp - techo);

      var ultimo = 0;
      [].forEach.call(ah.querySelectorAll('a[href],button,summary'), function(e){
        var r = e.getBoundingClientRect();
        if (r.height < 4) return;
        ultimo = Math.max(ultimo, Math.round(r.bottom + window.scrollY));
      });
      //  Con la pagina al final del scroll, lo ultimo tiene que caer por encima
      //  del techo:  doc >= ultimo + fijo + margen.  Lo que falte, y solo eso.
      var falta = Math.max(0, ultimo + fijo + MARGEN - doc);
      ah.style.setProperty('--ah-zona', (falta || MARGEN) + 'px');
    };
    alCargar(ajustar); window.addEventListener('resize', ajustar);
    //  Y cada vez que una capa se abre o se cierra: la pagina cambia de alto
    //  y con ella cambia lo que queda debajo de la barra fija.
    document.addEventListener('toggle', function(e){
      if (e.target && e.target.tagName === 'DETAILS') setTimeout(ajustar, 30);
    }, true);
  })();

  //  AYUDA SE APARTA DE CUALQUIER CONTROL PRINCIPAL, no solo de la cola.
  //
  //  La primera version solo vigilaba los enlaces del final. Con eso, el boton
  //  primario del bloque Ahora podia quedar debajo de Ayuda y la regla no se
  //  enteraba — y decir «se alcanza haciendo scroll» no vale para el boton mas
  //  importante de la pantalla: es el que la duena va a tocar sin pensar.
  //
  //  Ahora se observan TODOS los controles principales (el primario, el
  //  secundario, el desplegable del como y la cola). Si CUALQUIERA cae en la
  //  franja del boton, Ayuda se aparta. Vuelve sola en cuanto deja de coincidir.
  //
  //  La banda es la franja del propio boton con 16px de aviso a cada lado: se
  //  quita ANTES de rozar, no cuando ya tapa.
  (function(){
    var SEL = '.tm-btn, .ah-como > summary, .tm-ac > summary, .cq-btn, .tm-mas a';
    if (!('IntersectionObserver' in window)) return;
    var ob = null, dentro = null;
    var montar = function(){
      if (ob) { ob.disconnect(); ob = null; }
      dentro = new Set();
      document.body.classList.remove('ah-cola');         // medir sin el efecto puesto
      var fab = document.querySelector('.ay-fab');
      var objetivos = document.querySelectorAll(SEL);
      if (!fab || !objetivos.length) return;

      var AVISO = 16, H = window.innerHeight;
      var r = fab.getBoundingClientRect();
      var arriba = Math.round(r.top - AVISO), abajo = Math.round(H - r.bottom - AVISO);
      if (!(arriba > 0 && arriba < H)) return;           // sin FAB a la vista

      ob = new IntersectionObserver(function(es){
        es.forEach(function(e){
          if (e.isIntersecting) dentro.add(e.target); else dentro.delete(e.target);
        });
        document.body.classList.toggle('ah-cola', dentro.size > 0);
      }, { rootMargin: '-' + arriba + 'px 0px ' + (-abajo) + 'px 0px', threshold: 0 });
      [].forEach.call(objetivos, function(o){ ob.observe(o); });
    };
    alCargar(montar);
    window.addEventListener('resize', montar);
    //  Al abrir una capa aparecen controles que antes no existian: si no se
    //  vuelve a montar, Ayuda no los vigila y puede quedarse encima.
    document.addEventListener('toggle', function(e){
      if (e.target && e.target.tagName === 'DETAILS') setTimeout(montar, 60);
    }, true);
  })();

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
