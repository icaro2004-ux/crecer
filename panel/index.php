<?php
// ============================================================
//  ENCUENTRALO · CRECER — Mission Control
//  panel/index.php
//
//  Home narrativo. Sin datos inventados, sin tablas nuevas, sin IA nueva:
//  solo hechos reales que ya existen en la BD y helpers actuales.
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/suscripcion.php';
require __DIR__ . '/../includes/metricas.php';
require_once __DIR__ . '/../includes/memoria.php';

requiere_login();
require_once __DIR__ . '/../includes/panel_guard.php';
requiere_suscripcion($pdo, isset($_GET['marca']) ? (int)$_GET['marca'] : null);
$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }
$marca_id = (int)$marca['id'];

$BASE = '/crecer/panel';
$mid  = "marca={$marca_id}";

//  LOS TRES BARRIDOS SE FUERON AL CRON (Fase 6). Estaban aqui por una razon
//  historica que ya no se sostiene: el worker se muere en Hostinger, asi que
//  recoger el trabajo terminado dependia de que alguien abriera la portada.
//
//  Ya no. `cron_ayudante` recoge imagenes y carruseles de TODAS las marcas cada
//  vuelta —incluida la cuenta que nadie visita, que era justo la que se quedaba
//  con el trabajo pagado sin recoger— y `cron_corillo` corre al Analista. Nada
//  deja de avanzar: se avanza en mas cuentas que antes.
//
//  Y donde el dueño SI esta esperando su imagen —`aprobar2.php`, `carrusel.php`,
//  el gateway— el barrido sigue en su sitio. Lo que se quita es escribir en la
//  base por el mero hecho de mirar la portada.

// Marcar una señal del Analista (aceptada al ir a la acción · descartada al "Ahora no").
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'analista_marcar') {
    require_once __DIR__ . '/../includes/analista.php';
    header('Content-Type: application/json; charset=utf-8');
    if (function_exists('csrf_ok') && !csrf_ok()) { echo json_encode(['ok'=>false]); exit; }
    analista_marcar($pdo, (int)($_POST['id'] ?? 0), $marca_id, (string)($_POST['estado'] ?? ''));
    echo json_encode(['ok'=>true]); exit;
}

//  LA IDEA DEL DÍA SE FUE (Fase 5). Este endpoint llamaba a Gemini CADA VEZ
//  que alguien abría la portada: refrescar el dashboard costaba dinero, y el
//  dueño que entra cinco veces al día pagaba cinco ideas que nadie leyó.
//  Abrir el panel es mirar, y mirar no puede cobrar.
//
//  No se pierde nada que él use: crear una publicación a mano sigue en Crear,
//  y lo que el corillo propone vive en su semana, que ya está pensada con su
//  Meta delante en vez de improvisada al vuelo.

// Hechos verificables: contenido por estado.
$cuenta = ['borrador'=>0,'aprobado'=>0,'programado'=>0,'publicando'=>0,'publicado'=>0,'fallido'=>0,'rechazado'=>0];
$cq = $pdo->prepare("SELECT estado, COUNT(*) n FROM crecer_contenido WHERE marca_id=? GROUP BY estado");
$cq->execute([$marca_id]);
foreach ($cq->fetchAll() as $r) {
    if (isset($cuenta[$r['estado']])) $cuenta[$r['estado']] = (int)$r['n'];
}

// LOS FALLOS CADUCAN (2026-08-14). El aviso "un post no se pudo publicar" salía
// del conteo de arriba, que no mira la fecha: una pieza que falló UNA VEZ hace
// semanas seguía gritando en el Home todos los días, sin forma de callarla. El
// dueño aprendía a ignorar la alerta — que es justo lo contrario de lo que una
// alerta tiene que conseguir.
// Para AVISAR solo cuentan los fallos de los últimos 7 días. Lo viejo no
// desaparece: sigue en la biblioteca con su estado y se puede reintentar; lo
// que deja de hacer es dar la matraca en la pantalla de inicio.
try {
    $fq = $pdo->prepare("SELECT COUNT(*) FROM crecer_contenido
                          WHERE marca_id=? AND estado='fallido'
                            AND COALESCE(updated_at, created_at) > (NOW() - INTERVAL 7 DAY)");
    $fq->execute([$marca_id]);
    $cuenta['fallido'] = (int)$fq->fetchColumn();
} catch (Throwable $e) { /* si falla, se queda el conteo de arriba */ }

// Metricas internas reales.
$prod     = metricas_produccion($pdo, $marca_id);
$racha    = metricas_racha($pdo, $marca_id);
$obs      = metrica_observacion($prod, $racha);

// Plan activo.
$susc = suscripcion_de_marca($pdo, $marca_id);
$plan = suscripcion_activa($susc) ? ($susc['plan_slug'] ?? null) : null;

// Meta conectado.
$meta_ok = false;
try {
    $meta_ok = (bool)$pdo->query("SELECT 1 FROM crecer_conexiones WHERE marca_id={$marca_id} AND estado='activa' LIMIT 1")->fetchColumn();
} catch (Throwable $e) { $meta_ok = false; }

// Proximo post programado.
$prox_fecha = null;
try {
    $pq = $pdo->prepare("SELECT fecha_programada FROM crecer_contenido WHERE marca_id=? AND estado='programado' AND fecha_programada IS NOT NULL ORDER BY fecha_programada ASC LIMIT 1");
    $pq->execute([$marca_id]);
    $prox_fecha = $pq->fetchColumn() ?: null;
} catch (Throwable $e) {}

if (!function_exists('_fecha_humana')) {
    function _fecha_humana($f) {
        $ts = strtotime((string)$f);
        if (!$ts) return '';
        $dias = ['Sunday'=>'domingo','Monday'=>'lunes','Tuesday'=>'martes','Wednesday'=>'miercoles','Thursday'=>'jueves','Friday'=>'viernes','Saturday'=>'sabado'];
        $d = $dias[date('l', $ts)] ?? '';
        $hora = date('g:i A', $ts);
        $fd = date('Y-m-d', $ts);
        if ($fd === date('Y-m-d')) return "hoy a las {$hora}";
        if ($fd === date('Y-m-d', strtotime('+1 day'))) return "manana a las {$hora}";
        return "el {$d} a las {$hora}";
    }
}

// 1. Prioridad del dia: misma maquina de estados, nueva presentacion.
if (!$plan) {
    $st = ['k'=>'A','tono'=>'sell','ico'=>'sparkles',
        'titulo'=>'Tu primer post esta listo.',
        'sub'=>'Activalo y Crecer te prepara contenido nuevo cada semana, en tu propia voz.',
        'cta'=>'Activar Crecer','href'=>"{$BASE}/precios.php?{$mid}"];
} elseif ($cuenta['fallido'] > 0) {
    $n = $cuenta['fallido'];
    $st = ['k'=>'E','tono'=>'warn','ico'=>'bolt',
        'titulo'=> $n==1 ? 'Un post no se pudo publicar.' : "{$n} posts no se pudieron publicar.",
        'sub'=>'Hay trabajo trabado. Revisa que paso y vuelve a intentarlo.',
        'cta'=>'Resolver','href'=>"{$BASE}/aprobar2.php?tab=aprobados&{$mid}"];
} elseif ($cuenta['borrador'] > 0) {
    $n = $cuenta['borrador'];
    $st = ['k'=>'B','tono'=>'hot','ico'=>'check-circle',
        'titulo'=>"Tienes {$n} post".($n==1?'':'s')." listo".($n==1?'':'s')." para tu OK.",
        'sub'=>'Crecer dejo contenido preparado. Tu decision ahora es aprobar, ajustar o rechazar.',
        'cta'=>'Revisar y aprobar','href'=>"{$BASE}/aprobar2.php?tab=pendientes&{$mid}"];
} elseif ($cuenta['aprobado'] > 0) {
    $st = ['k'=>'C','tono'=>'ok','ico'=>'image',
        'titulo'=>'Tus posts estan listos para salir.',
        'sub'=> $meta_ok ? 'Ya puedes publicarlos en tus redes.' : 'Conecta tus redes para publicarlos sin hacerlo a mano.',
        'cta'=> $meta_ok ? 'Ver aprobados' : 'Conectar redes',
        'href'=> $meta_ok ? "{$BASE}/aprobar2.php?tab=aprobados&{$mid}" : "{$BASE}/conectar.php?{$mid}"];
} elseif ($cuenta['programado'] > 0) {
    $cuando = $prox_fecha ? _fecha_humana($prox_fecha) : '';
    $st = ['k'=>'D','tono'=>'ok','ico'=>'calendar',
        'titulo'=>'Todo esta programado.',
        'sub'=> $cuando ? "El proximo post sale {$cuando}." : 'Hay posts programados.',
        'cta'=>'Ver lo programado','href'=>"{$BASE}/calendario.php?{$mid}"];
} else {
    $st = ['k'=>'F','tono'=>'ok','ico'=>'sparkles',
        'titulo'=>'No hay trabajo pendiente.',
        'sub'=>'Crecer no tiene piezas esperando ahora mismo. Puedes pedir contenido nuevo para mantener el ritmo.',
        'cta'=>'Pedir contenido','href'=>"{$BASE}/aprobar2.php?{$mid}"];
}

// 2. Trabajo preparado: piezas reales, priorizadas por decision.
$trabajo = [];
try {
    $tq = $pdo->prepare(
        // tactica_id viene para poder decir DE QUÉ META es cada pieza: sin eso,
        // el Home enseña "3 posts esperando tu OK" y el dueño no sabe si tienen
        // algo que ver con el número que está persiguiendo.
        "SELECT id, estado, caption, plataforma, tipo, fecha_programada, grafica_path, tactica_id
         FROM crecer_contenido
         WHERE marca_id=? AND estado IN ('fallido','publicando','borrador','aprobado','programado','publicado')
         ORDER BY FIELD(estado,'fallido','publicando','borrador','aprobado','programado','publicado'),
                  COALESCE(fecha_programada, created_at) ASC, id DESC
         LIMIT 4");
    $tq->execute([$marca_id]);
    $trabajo = $tq->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Sin la columna tactica_id (migración pendiente) el Home NO se queda mudo:
    // repite la consulta sin ella. Perder el "próximo post" por una columna
    // nueva sería peor que no poder decir a qué meta pertenece.
    try {
        $tq = $pdo->prepare(
            "SELECT id, estado, caption, plataforma, tipo, fecha_programada, grafica_path
             FROM crecer_contenido
             WHERE marca_id=? AND estado IN ('fallido','publicando','borrador','aprobado','programado','publicado')
             ORDER BY FIELD(estado,'fallido','publicando','borrador','aprobado','programado','publicado'),
                      COALESCE(fecha_programada, created_at) ASC, id DESC
             LIMIT 4");
        $tq->execute([$marca_id]);
        $trabajo = $tq->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e2) { $trabajo = []; }
}

// Actividad real de IA para complementar el trabajo preparado.
$feed_map = [
  'planificador'=>['estratega','La Estratega','cuadro el plan'],
  'creador'     =>['creativa','La Creativa','escribio contenido'],
  'diseñador'   =>['disenador','El Disenador','preparo un arte'],
  'analitica'   =>['analista','El Analista','reviso numeros'],
  'intake'      =>['estratega','La Estratega','aprendio del negocio'],
  'aprendiz'    =>['creativa','La Creativa','aprendio vocabulario'],
  'editor'      =>['creativa','La Creativa','pulio un texto'],
  'estratega'   =>['estratega','La Estratega','te dio una recomendacion'],
  'asistente'   =>['bolt','El Copiloto','respondio tus dudas'],
  'retencion'   =>['estratega','La Estratega','preparo un mensaje para un cliente'],
];
$feed = [];
try {
    // agente='kernel' = decisiones internas del orquestador (auditoría/admin),
    // NO trabajo visible del corillo → se excluye del feed del cliente.
    $fq = $pdo->prepare("SELECT agente, created_at FROM crecer_ia_log WHERE marca_id=? AND estado='ok' AND agente<>'kernel' ORDER BY id DESC LIMIT 3");
    $fq->execute([$marca_id]);
    $feed = $fq->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $feed = []; }

// 3. Que aprendimos: memoria visible real.
if (function_exists('memoria_consolidar')) memoria_consolidar($pdo, $marca_id);
$memorias = function_exists('memoria_listar') ? array_slice(memoria_listar($pdo, $marca_id), 0, 3) : [];

// 4. Piloto automatico: columnas existentes.
$autopilot_on = !empty($marca['autopilot']);
$autopilot_n = max(1, (int)($marca['autopilot_n'] ?? 3));
$autopilot_ultimo = !empty($marca['autopilot_ultimo']) ? $marca['autopilot_ultimo'] : null;

// 5. Resultados relevantes.
$pubs_recientes = metricas_publicaciones($pdo, $marca_id, 3);
$tot_ins = function_exists('metricas_totales_insights') ? metricas_totales_insights($pdo, $marca_id) : ['alcance'=>0,'interacciones'=>0,'n'=>0];
$hay_insights = (int)($tot_ins['n'] ?? 0) > 0;

// ── DECISION FEED: cola priorizada de decisiones REALES (sin inventar) ──
// Solo piezas/estados que requieren una decisión tuya, en orden de urgencia.
$dec_pieces = [];
try {
    $dq = $pdo->prepare(
        "SELECT id, estado, caption, plataforma, grafica_path
         FROM crecer_contenido
         WHERE marca_id=? AND estado IN ('fallido','publicando','borrador','aprobado')
         ORDER BY FIELD(estado,'fallido','publicando','borrador','aprobado'), id DESC
         LIMIT 8");
    $dq->execute([$marca_id]);
    $dec_pieces = $dq->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $dec_pieces = []; }

$decisiones = [];
if (!$plan) {
    $decisiones[] = ['tipo'=>'activar','kick'=>'Empieza aquí','ico'=>'sparkles','id'=>0,
        'titulo'=>'Activa Crecer','preview'=>'','plataforma'=>'','grafica'=>'',
        'acciones'=>[['t'=>'Activar Crecer','cls'=>'pri','href'=>"{$BASE}/precios.php?{$mid}"]]];
}
$conectar_listo = false;
foreach ($dec_pieces as $p) {
    $cap  = trim((string)$p['caption']) ?: '(sin texto todavía)';
    $base = ['id'=>(int)$p['id'],'preview'=>$cap,'plataforma'=>(string)$p['plataforma'],'grafica'=>(string)$p['grafica_path']];
    if ($p['estado'] === 'fallido') {
        $decisiones[] = $base + ['tipo'=>'reintentar','kick'=>'No se pudo publicar','ico'=>'bolt',
            'titulo'=>'Reintentar este post',
            'acciones'=>[['t'=>'Reintentar','cls'=>'pri','href'=>"{$BASE}/aprobar2.php?ver={$p['id']}&tab=listos&{$mid}"]]];
    } elseif ($p['estado'] === 'publicando') {
        // Quedó a mitad de camino (ej. la conexión se cayó publicando un video).
        // El lock se libera solo a los 10 min; reintentar antes es inofensivo
        // (el publicador dice "ya tomado" y no duplica).
        $decisiones[] = $base + ['tipo'=>'publicando','kick'=>'Saliendo a tus redes','ico'=>'clock',
            'titulo'=>'Este post se está publicando…',
            'acciones'=>[['t'=>'Ver / Reintentar','cls'=>'pri','href'=>"{$BASE}/aprobar2.php?ver={$p['id']}&tab=listos&{$mid}"]]];
    } elseif ($p['estado'] === 'borrador') {
        $decisiones[] = $base + ['tipo'=>'aprobar','kick'=>'Listo para tu OK','ico'=>'check-circle',
            'titulo'=>'¿Apruebas este post?',
            'acciones'=>[
                ['t'=>'Aprobar','cls'=>'ok','accion'=>'aprobar'],
                ['t'=>'Editar','cls'=>'gho','href'=>"{$BASE}/aprobar2.php?edit={$p['id']}&{$mid}#cap-{$p['id']}"],
                ['t'=>'No','cls'=>'gho','accion'=>'rechazar'],
            ]];
    } elseif ($p['estado'] === 'aprobado') {
        if ($meta_ok) {
            $decisiones[] = $base + ['tipo'=>'publicar','kick'=>'Aprobado','ico'=>'image',
                'titulo'=>'Publicar este post',
                'acciones'=>[['t'=>'Publicar','cls'=>'pri','href'=>"{$BASE}/aprobar2.php?ver={$p['id']}&tab=listos&{$mid}"]]];
        } elseif (!$conectar_listo) {
            $conectar_listo = true;
            $decisiones[] = ['tipo'=>'conectar','kick'=>'Falta un paso','ico'=>'bolt','id'=>0,
                'titulo'=>'Conecta tus redes para publicar','preview'=>'','plataforma'=>'','grafica'=>'',
                'acciones'=>[['t'=>'Conectar Instagram y Facebook','cls'=>'pri','href'=>"{$BASE}/conectar.php?{$mid}"]]];
        }
    }
}
if ($plan && !$autopilot_on) {
    $decisiones[] = ['tipo'=>'piloto','kick'=>'Sugerencia','ico'=>'refresh','id'=>0,
        'titulo'=>'¿Enciendo el piloto automático?','plataforma'=>'','grafica'=>'',
        'preview'=>'Cada semana te dejo posts listos para tu OK, sin que tengas que pedirlos.',
        'acciones'=>[['t'=>'Encender','cls'=>'pri','href'=>"{$BASE}/configuracion.php?{$mid}"]]];
}
$dec_total = count($decisiones);

$_meses_ab = [1=>'ene',2=>'feb',3=>'mar',4=>'abr',5=>'may',6=>'jun',7=>'jul',8=>'ago',9=>'sep',10=>'oct',11=>'nov',12=>'dic'];
$dia_rel = function ($ts) use ($_meses_ab) {
    $d = strtotime((string)$ts);
    if (!$d) return '';
    if ($d >= strtotime('today')) return 'Hoy';
    if ($d >= strtotime('yesterday')) return 'Ayer';
    if (date('Y', $d) === date('Y')) return (int)date('j', $d) . ' ' . $_meses_ab[(int)date('n', $d)];
    return date('d/m/y', $d);
};
$fecha_corta = function ($f) {
    $ts = strtotime((string)$f);
    if (!$ts) return '';
    if (date('Y-m-d', $ts) === date('Y-m-d')) return 'hoy';
    if (date('Y-m-d', $ts) === date('Y-m-d', strtotime('+1 day'))) return 'manana';
    return date('d/m', $ts);
};

$estado_lbl = [
  'borrador'   => 'espera tu OK',
  'aprobado'   => 'listo para publicar',
  'programado' => 'programado',
  'publicando' => 'publicándose…',
  'fallido'    => 'requiere atencion',
  'publicado'  => 'publicado',
];

// Kernel v1: opcional. Si el flag esta apagado, todo queda como arriba.
$kernel_response = null;
$kernel_debug = null;
if (defined('CRECER_KERNEL_V1_ENABLED') && CRECER_KERNEL_V1_ENABLED) {
    try {
        require_once __DIR__ . '/../core/bootstrap.php';
        $kernel_response = CrecerKernel::dispatch(new BusinessEvent('user_login', $marca_id, []), $pdo)->toArray(false);
        $kb = $kernel_response['briefing'] ?? [];
        if ($kb) {
            $priority = $kb['priority'] ?? [];
            $next = $kb['next_action'] ?? [];
            $st = [
                'k' => 'K',
                'tono' => 'ok',
                'ico' => ($priority['type'] ?? '') === 'resolve_failed_content' ? 'bolt' : (($priority['type'] ?? '') === 'review_content' ? 'check-circle' : 'sparkles'),
                'titulo' => $kb['headline'] ?? ($priority['explanation'] ?? $st['titulo']),
                'sub' => $kb['explanation'] ?? ($priority['explanation'] ?? $st['sub']),
                'cta' => $next['label'] ?? $st['cta'],
                'href' => $next['href'] ?? $st['href'],
            ];
            $trabajo = array_map(function ($p) {
                return [
                    'id' => $p['id'] ?? 0,
                    'estado' => $p['status'] ?? '',
                    'caption' => $p['caption'] ?? '',
                    'plataforma' => $p['platform'] ?? '',
                    'tipo' => $p['type'] ?? '',
                    'fecha_programada' => $p['scheduled_at'] ?? null,
                    'grafica_path' => $p['image'] ?? null,
                ];
            }, $kb['prepared_work'] ?? []);
            $memorias = array_map(function ($m) {
                return [
                    'id' => $m['id'] ?? 0,
                    'tipo' => $m['type'] ?? '',
                    'titulo' => $m['title'] ?? 'Aprendizaje',
                    'detalle' => $m['detail'] ?? '',
                    'confianza' => $m['confidence'] ?? 0,
                    'updated_at' => $m['updated_at'] ?? null,
                ];
            }, $kb['recent_learning'] ?? []);
            $auto = $kb['autopilot_status'] ?? [];
            $autopilot_on = !empty($auto['enabled']);
            $autopilot_n = max(1, (int)($auto['target_posts'] ?? $autopilot_n));
            $autopilot_ultimo = $auto['last_run'] ?? $autopilot_ultimo;
            $res = $kb['results_summary'] ?? [];
            if ($res) {
                $prod['publicados_mes'] = (int)($res['published_this_month'] ?? $prod['publicados_mes']);
                $prod['esperando_ok'] = (int)($res['waiting_approval'] ?? $prod['esperando_ok']);
                $prod['listos'] = (int)($res['ready'] ?? $prod['listos']);
                $racha = (int)($res['streak_weeks'] ?? $racha);
                $tot_ins = $res['meta_insights'] ?? $tot_ins;
                $hay_insights = (int)($tot_ins['n'] ?? 0) > 0;
                $pubs_recientes = $res['recent_publications'] ?? $pubs_recientes;
            }
            // Decision Feed alimentado por el Kernel: consumir las decisiones ya
            // normalizadas y priorizadas (NO reconstruir reglas ni consultar la BD
            // para priorizar). Si el Kernel no trae feed, se conserva el $decisiones
            // inline de arriba como fallback. [Lógica inline per-pieza → KERNEL V2]
            if (isset($kb['decision_feed'])) {
                $df_ico  = ['resolve_failed_content'=>'bolt','review_content'=>'check-circle','publish_ready_content'=>'image','connect_networks'=>'bolt','prepare_first_week'=>'sparkles','suggest_autopilot'=>'refresh','complete_business_profile'=>'palette','complete_profile'=>'palette','show_sample_post'=>'image'];
                $df_kick = ['resolve_failed_content'=>'No se pudo publicar','review_content'=>'Listo para tu OK','publish_ready_content'=>'Aprobado','connect_networks'=>'Falta un paso','prepare_first_week'=>'Empieza aquí','suggest_autopilot'=>'Sugerencia','complete_business_profile'=>'Completa tu marca','complete_profile'=>'Completa tu marca','show_sample_post'=>'Tu primer post'];
                // La pieza REAL detrás de cada decisión. El feed del Kernel solo
                // trae el content_id: sin esto la tarjeta salía sin arte y con la
                // explicación del agente en vez del caption — se aprobaba y se
                // publicaba a ciegas. Nada se decide sin la pieza delante.
                $df_ids = [];
                foreach ($kb['decision_feed'] as $__d) {
                    $__i = (int)($__d['evidence']['content_id'] ?? 0);
                    if ($__i > 0) $df_ids[$__i] = true;
                }
                $df_pieza = [];
                if ($df_ids) {
                    $__in = implode(',', array_fill(0, count($df_ids), '?'));
                    try {
                        $__q = $pdo->prepare("SELECT id, caption, plataforma, grafica_path
                                                FROM crecer_contenido
                                               WHERE marca_id=? AND id IN ($__in)");
                        $__q->execute(array_merge([$marca_id], array_keys($df_ids)));
                        foreach ($__q->fetchAll(PDO::FETCH_ASSOC) as $__r) $df_pieza[(int)$__r['id']] = $__r;
                    } catch (Throwable $e) { error_log('decision_feed piezas: ' . $e->getMessage()); }
                }
                // Decisiones que tocan una pieza: la acción principal SIEMPRE la abre.
                $df_verla = ['review_content'=>1, 'publish_ready_content'=>1, 'resolve_failed_content'=>1];
                $decisiones = array_map(function ($d) use ($df_ico, $df_kick, $df_pieza, $df_verla, $BASE, $mid) {
                    $t = $d['type'] ?? 'decision';
                    $id = (int)($d['evidence']['content_id'] ?? 0);
                    $pz = $df_pieza[$id] ?? null;
                    $acc = [];
                    $pa = $d['primary_action'] ?? [];
                    if (!empty($pa['label'])) {
                        $href = $pa['href'] ?? '#';
                        if ($id > 0 && isset($df_verla[$t])) {
                            $href = "{$BASE}/aprobar2.php?ver={$id}&{$mid}";
                        }
                        $acc[] = ['t'=>$pa['label'], 'cls'=>'pri', 'href'=>$href];
                    }
                    foreach (($d['secondary_actions'] ?? []) as $sa) {
                        if (!empty($sa['label'])) $acc[] = ['t'=>$sa['label'], 'cls'=>'gho', 'href'=>$sa['href'] ?? '#'];
                    }
                    $cap = $pz ? trim((string)$pz['caption']) : '';
                    return [
                        'tipo'=>$t, 'id'=>$id,
                        'kick'=>$df_kick[$t] ?? 'Para ti', 'ico'=>$df_ico[$t] ?? 'sparkles',
                        'titulo'=>$d['headline'] ?? 'Próxima decisión',
                        'preview'=>($cap !== '' ? $cap : ($d['explanation'] ?? '')),
                        'plataforma'=>$pz ? (string)$pz['plataforma'] : '',
                        'grafica'=>$pz ? (string)$pz['grafica_path'] : '',
                        'acciones'=>$acc,
                    ];
                }, $kb['decision_feed']);
                $dec_total = count($decisiones);
            }
        }
    } catch (Throwable $e) {
        $kernel_debug = 'Kernel v1 fallback: ' . $e->getMessage();
        error_log($kernel_debug);
    }
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$ICON = '/crecer/assets/icons';

// ── Home tipo briefing (snapshot): saludo + próximo post + semana + rendimiento ──
$hz_nombre = trim((string)($usuario['nombre'] ?? ''));
$hz_nombre = $hz_nombre !== '' ? ucfirst(mb_strtolower(explode(' ', $hz_nombre)[0])) : (string)($marca['nombre_negocio'] ?? '');
$hh = (int)date('G');
$hz_saludo = $hh < 12 ? 'Buenos días' : ($hh < 19 ? 'Buenas tardes' : 'Buenas noches');
$hz_pueblo = trim((string)($marca['pueblo'] ?? ''));

$hz_post = null;
foreach ($trabajo as $t) { if (($t['estado'] ?? '') === 'borrador')  { $hz_post = $t; $hz_post['modo'] = 'aprobar';    break; } }
if (!$hz_post) foreach ($trabajo as $t) { if (($t['estado'] ?? '') === 'programado') { $hz_post = $t; $hz_post['modo'] = 'programado'; break; } }
if (!$hz_post && !empty($trabajo)) { $hz_post = $trabajo[0]; $hz_post['modo'] = 'listo'; }

$hz_pend = (int)($cuenta['borrador'] ?? 0);
//  EL PLURAL ES UNA FRASE ENTERA, NO UNA «s» PEGADA. Concatenar la marca del
//  plural funciona en español y se rompe en cuanto el idioma la pone en otro
//  sitio; ademas deja tres pedazos que ningun catalogo puede traducir.
$hz_status = $hz_pend > 0
    ? t($hz_pend == 1 ? 'Tienes %s post esperando tu OK' : 'Tienes %s posts esperando tu OK', $hz_pend)
    : t('Todo listo para hoy');

//  LA SEMANA DE SIETE COLUMNAS Y EL SPARKLINE SE FUERON (Fase 5). Eran dos
//  consultas por carga para pintar dos cosas que no ayudaban a decidir: una
//  rejilla de puntos y una curva de CUÁNTOS POSTS publicó — que no es el
//  resultado de su negocio y se leía como si lo fuera. El adelanto del
//  calendario y la señal de resultado los sustituyen, y esos sí dicen algo.
require_once __DIR__ . '/../includes/analista.php';  // ADR-0004: señal proactiva para la tarjeta del Analista
//  CUATRO LECTURAS QUE YA NO PINTA NADIE (Fase 5): la señal del Analista, el
//  tip de Resultados, el consejo financiero y las notificaciones. Eran cuatro
//  consultas por carga para cuatro tarjetas que competían entre sí — el dueño
//  no leía un equipo poniéndolo al día, leía un tablero de aeropuerto. Lo que
//  de verdad hay que decirle vive ahora en «Cómo va» y en «Te toca a ti», y
//  cada uno abre su sección de verdad.
//
//  Ninguna se borra del producto: Resultados, Finanzas y la campanita siguen
//  donde estaban, con sus datos intactos.
$active = 'inicio';
$page_title = 'Inicio';
$guia = null; // El Home no se explica: se siente. (Overlay-guía eliminado a propósito.)
require __DIR__ . '/_shell.php';
?>
<?php
// ══════════════════════════════════════════════════════════════════════
//  C2 · EL RELEVO DEL CORILLO — capa de experiencia sobre datos del Kernel.
//  Sin nuevas consultas ni lógica: reusa $trabajo, $prod, $tot_ins, $memorias,
//  $feed, $autopilot_*, $meta_ok, $plan, $cuenta ya calculados arriba. Cada
//  línea de evidencia proviene de un hecho REAL; nunca se inventa texto.
// ══════════════════════════════════════════════════════════════════════
$negocio = $marca['nombre_negocio'] ?? 'tu negocio';

// Hora del relevo = última vez real que el corillo trabajó.
$relevo_ts    = $feed[0]['created_at'] ?? ($autopilot_ultimo ?: null);
$relevo_label = $relevo_ts ? ($dia_rel($relevo_ts) . ' · ' . date('g:i A', strtotime($relevo_ts))) : '';

// Pieza a entregar hoy: primer borrador (necesita OK); si no, primer aprobado.
$featured = null;
foreach ($trabajo as $p) { if (($p['estado'] ?? '') === 'borrador') { $featured = $p; break; } }
if (!$featured) { foreach ($trabajo as $p) { if (($p['estado'] ?? '') === 'aprobado') { $featured = $p; break; } } }

$fe_video = $featured && !empty($featured['grafica_path']) && preg_match('#\.(mp4|mov|m4v)$#i', (string)$featured['grafica_path']);
$fe_arte  = $featured && !empty($featured['grafica_path']) && !$fe_video;
$fe_cap   = $featured ? trim((string)$featured['caption']) : '';

// Quién trabajó de verdad (evidencia real: atributos de la pieza + actividad reciente).
// Los nombres salen del equipo que el dueño bautizó (equipo_nombre); si no, el rol.
$ags = array_column($feed, 'agente');
$NM = fn($k) => function_exists('equipo_nombre') ? equipo_nombre($marca, $k) : $k;
$w_creativa  = ($fe_cap !== '') || array_intersect(['creador', 'editor', 'aprendiz'], $ags);
$w_disenador = $fe_arte || $fe_video || in_array('diseñador', $ags, true);
$w_estratega = !empty($featured['fecha_programada']) || array_intersect(['planificador', 'intake', 'estratega'], $ags);

$evidencia = [];
if ($w_creativa)  $evidencia[] = $NM('escritor') . ' terminó el post de hoy.';
if ($w_disenador) $evidencia[] = ($fe_video && !$fe_arte) ? $NM('disenador') . ' preparó el video.' : $NM('disenador') . ' dejó listo el arte.';
if ($w_estratega) $evidencia[] = !empty($featured['fecha_programada'])
    ? $NM('estratega') . ' escogió la mejor hora — ' . _fecha_humana($featured['fecha_programada']) . '.'
    : $NM('estratega') . ' cuadró el plan de la semana.';

// Rellenar con más pruebas REALES si faltan (nunca texto inventado).
$otras = (int)($cuenta['borrador'] ?? 0) + (int)($cuenta['aprobado'] ?? 0) + (int)($cuenta['programado'] ?? 0) - ($featured ? 1 : 0);
if (count($evidencia) < 3 && $otras > 0)
    $evidencia[] = 'El corillo adelantó ' . $otras . ' pieza' . ($otras == 1 ? '' : 's') . ' más para la semana.';
if (count($evidencia) < 3 && (int)($tot_ins['n'] ?? 0) > 0 && (int)($tot_ins['alcance'] ?? 0) > 0)
    $evidencia[] = $NM('analista') . ' revisó los números — ' . number_format((int)$tot_ins['alcance']) . ' personas alcanzadas este mes.';
if (count($evidencia) < 3 && in_array('provocador', $ags, true))
    $evidencia[] = $NM('provocador') . ' lanzó ángulos atrevidos para tus posts.';
if (count($evidencia) < 3 && in_array('analitica', $ags, true))
    $evidencia[] = $NM('analista') . ' revisó cómo va tu presencia.';
if (count($evidencia) < 3 && !empty($memorias))
    $evidencia[] = $NM('estratega') . ' aprendió algo nuevo de tu voz.';
$evidencia = array_slice($evidencia, 0, 3);

// Modo del relevo.
$sin_contenido = array_sum(array_map('intval', $cuenta)) === 0;
if (!$plan)                                                      $modo = 'sin_plan';
elseif ($sin_contenido)                                          $modo = 'primerdia';
elseif ($featured && ($featured['estado'] ?? '') === 'borrador') $modo = 'decision';
else                                                             $modo = 'tranquilo';

$hero = [
    'decision'  => 'Tu corillo ya adelantó trabajo por ti.',
    'tranquilo' => 'Tu corillo mantuvo la tienda al día.',
    'sin_plan'  => 'Tu corillo está listo para arrancar.',
    'primerdia' => 'Tu corillo está listo para empezar.',
][$modo];
$frase = 'Queda en tus manos.';
$cta   = 'Vamos con este';
$firma = 'El corillo sigue trabajando.';

// ── EL TURNO · deck a pantalla completa de las decisiones que esperan tu OK ──
$deck = [];
foreach ($dec_pieces as $p) {
    if (($p['estado'] ?? '') !== 'borrador') continue;
    $deck[] = [
        'id'   => (int)$p['id'],
        'cap'  => trim((string)$p['caption']),
        'plat' => (string)$p['plataforma'],
        'img'  => (string)$p['grafica_path'],
        'vid'  => (bool)preg_match('#\.(mp4|mov|m4v)$#i', (string)$p['grafica_path']),
    ];
}
$has_deck = count($deck) > 0;
$credito  = $has_deck
    ? (count($deck) === 1 ? 'Tu corillo dejó esto listo' : 'Tu corillo dejó ' . count($deck) . ' listos')
    : '';
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
  /* ══ EL TURNO ══ un espacio inmersivo, no un documento. El post que el corillo
     dejó listo ocupa la pantalla; tu veredicto vive encima; swipe para el próximo.
     Sin caja, sin checks, sin firma, sin "ver todo". Hereda tokens. */
  .content{max-width:none;padding:0;display:flex;flex-direction:column;flex:1}
  .turno{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;
    width:100%;gap:13px;padding:16px 14px 20px;min-height:calc(100dvh - 132px)}
  @media(max-width:760px){.turno{padding:14px 10px 8px;min-height:calc(100dvh - 116px);justify-content:flex-start}}

  /* whisper superior: negocio + un solo crédito */
  .tn-top{width:100%;max-width:460px;display:flex;align-items:baseline;justify-content:space-between;gap:12px;padding:0 4px}
  .tn-neg{font-family:var(--font-display);font-size:15px;font-weight:600;color:var(--ink-soft);letter-spacing:-.01em}
  .tn-cred{font-size:12.5px;color:var(--muted);font-weight:400;white-space:nowrap}

  /* stage + deck a pantalla completa */
  .tn-stage{position:relative;width:100%;max-width:440px;aspect-ratio:4/5;height:auto;max-height:min(72vh,640px);margin:4px 0}
  @media(max-width:760px){.tn-stage{max-height:calc(100dvh - 202px)}}
  .deck{position:absolute;inset:0}
  .tcard{position:absolute;inset:0;border-radius:28px;overflow:hidden;background:#14121c;
    box-shadow:0 34px 80px -26px rgba(24,12,20,.55);will-change:transform,opacity;
    transform:translateY(30px) scale(.9);opacity:0;pointer-events:none;z-index:1;
    transition:transform .44s var(--ease),opacity .44s var(--ease),box-shadow .44s var(--ease)}
  .tcard.is-next{transform:translateY(16px) scale(.945);opacity:.6;z-index:2}
  .tcard.is-active{transform:none;opacity:1;pointer-events:auto;z-index:3}
  .tcard.is-gone{transform:translateY(-44px) scale(.94);opacity:0;z-index:0}
  .tcard.is-hidden{opacity:0;z-index:0}
  .tcard.fly-up{transform:translateY(-56px) scale(.92)!important;opacity:0!important}
  .tcard.fly-down{transform:translateY(56px) scale(.92)!important;opacity:0!important}

  .tcard-media{position:absolute;inset:0}
  .tcard-media img,.tcard-media video{width:100%;height:100%;object-fit:cover;display:block}
  .tcard-nomedia{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;text-align:center;padding:26px;
    background:linear-gradient(150deg,#2a2733,#141019)}
  .tcard-nomedia::before{content:'';position:absolute;inset:0;background:radial-gradient(120% 85% at 50% 38%,rgba(239,67,117,.24),transparent 62%);pointer-events:none}
  .tcard-nm-badge{width:62px;height:62px;border-radius:19px;display:grid;place-items:center;position:relative;z-index:1;
    background:linear-gradient(135deg,#FF6B3D,#EF4375);box-shadow:0 14px 34px -12px rgba(239,67,117,.7);animation:nmfloat 2.4s ease-in-out infinite}
  .tcard-nm-badge svg{width:30px;height:30px;color:#fff}
  @keyframes nmfloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-5px)}}
  .tcard-nm-txt{position:relative;z-index:1;color:rgba(255,255,255,.9);font-weight:800;font-size:15px;line-height:1.35;max-width:230px}
  .tcard-nm-sub{position:relative;z-index:1;color:rgba(255,255,255,.5);font-size:12.5px;max-width:230px;line-height:1.4}
  .tcard-scrim{position:absolute;inset:0;pointer-events:none;
    background:linear-gradient(to top,rgba(8,5,10,.9) 2%,rgba(8,5,10,.5) 26%,rgba(8,5,10,0) 52%)}
  .tcard-tag{position:absolute;top:15px;left:15px;font-size:11px;font-weight:700;color:#fff;text-transform:capitalize;
    letter-spacing:.03em;background:rgba(255,255,255,.16);backdrop-filter:blur(7px);padding:6px 12px;border-radius:999px}

  .tcard-body{position:absolute;left:0;right:0;bottom:0;padding:18px 18px 20px;color:#fff}
  .tcard-cap{font-size:15.5px;line-height:1.45;font-weight:400;margin:0 0 15px;text-shadow:0 1px 16px rgba(0,0,0,.45);
    display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
  .tn-ok{width:100%;border:0;cursor:pointer;font-family:'Poppins',sans-serif;font-weight:600;font-size:16px;color:#fff;
    padding:16px 20px;border-radius:16px;background:var(--btn-grad);box-shadow:var(--btn-glow);
    transition:transform var(--dur) var(--ease),box-shadow var(--dur) var(--ease)}
  .tn-ok:active{transform:translateY(1px);box-shadow:var(--btn-glow-active)}
  .tn-sub{display:flex;align-items:center;justify-content:center;gap:22px;margin-top:12px}
  .tn-adj,.tn-no{background:0;border:0;cursor:pointer;font-family:'Poppins',sans-serif;font-size:13.5px;font-weight:500;
    color:rgba(255,255,255,.82);text-decoration:none;padding:6px 4px}
  .tn-adj:hover,.tn-no:hover{color:#fff}

  /* fin del turno / estados sin deck */
  .tn-end{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;
    text-align:center;opacity:0;pointer-events:none;transition:opacity .55s var(--ease)}
  .tn-end.show{opacity:1}
  .tn-end-t,.tn-solo-t{font-family:var(--font-display);font-weight:600;color:var(--ink-soft);margin:0;line-height:1.15}
  .tn-end-t{font-size:27px}
  .tn-end-s,.tn-solo-s{font-size:14px;color:var(--muted);margin:8px 0 0}
  .tn-solo{margin:auto;max-width:380px;text-align:center;padding:24px}
  .tn-solo-t{font-size:clamp(25px,5.4vw,34px);margin-bottom:22px}
  .tn-solo .tn-ok{display:inline-block;width:auto;padding:16px 42px;text-decoration:none}

  /* progreso: puntos, no números */
  .tn-dots{display:flex;gap:6px;justify-content:center;align-items:center;height:8px}
  .tn-dot{width:6px;height:6px;border-radius:50%;background:var(--line);transition:width .3s var(--ease),background .3s}
  .tn-dot.on{background:var(--magenta);width:22px;border-radius:3px}

  /* entrada: el turno sube a su sitio una vez, al abrir */
  @keyframes tnRise{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}
  .tn-top,.tn-dots{animation:tnRise .5s var(--ease) both}
  .tn-dots{animation-delay:.08s}
  @media(prefers-reduced-motion:reduce){.tn-top,.tn-dots,.tcard{animation:none!important;transition-duration:.001ms!important}}

  /* Superficie de activación — solo cuentas sin plan. No tapa el contenido, no es modal. */
  .tn-activar{width:100%;max-width:460px;background:var(--card);border:1px solid var(--line);border-radius:20px;
    padding:17px 18px 16px;position:relative;overflow:hidden;
    box-shadow:0 2px 6px rgba(40,22,28,.05),0 22px 50px -34px rgba(40,22,28,.3);animation:tnRise .5s var(--ease) both}
  .tn-activar::before{content:"";position:absolute;left:0;right:0;top:0;height:3px;background:var(--btn-grad)}
  .ta-badge{display:inline-flex;align-items:center;gap:7px;font-family:var(--font-display);font-weight:600;font-size:11px;
    letter-spacing:.04em;text-transform:uppercase;color:var(--teal-dark,#00827e);
    background:color-mix(in srgb,var(--teal) 12%,#fff);padding:6px 12px;border-radius:999px}
  .ta-badge svg{width:14px;height:14px}
  .ta-t{font-family:var(--font-display);font-weight:600;font-size:16.5px;line-height:1.3;color:var(--ink-soft);margin:11px 0 12px;letter-spacing:-.01em}
  .ta-list{list-style:none;margin:0 0 15px;padding:0;display:grid;grid-template-columns:1fr 1fr;gap:8px 14px}
  .ta-list li{display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--tinta)}
  .ta-list li svg{width:15px;height:15px;color:var(--palma);flex:none}
  .ta-cta{display:block;text-align:center;text-decoration:none;font-family:var(--font-display);font-weight:600;font-size:16px;color:#fff;
    background:var(--btn-grad);box-shadow:var(--btn-glow);padding:15px;border-radius:14px;transition:transform .18s var(--ease),box-shadow .18s var(--ease)}
  .ta-cta:active{transform:translateY(1px);box-shadow:var(--btn-glow-active)}
  @media(max-width:420px){.ta-list{grid-template-columns:1fr}}
</style>

<style>
  .hz{max-width:560px;margin:0 auto;width:100%;font-family:var(--font-body);padding:8px 16px 28px;box-sizing:border-box}
  @media(max-width:860px){ .hz{padding-bottom:calc(120px + env(safe-area-inset-bottom))} }
  .hz-hi{padding:6px 2px 2px}
  /*  14px es el suelo del producto, no una preferencia: por debajo, la
      repostera de 50 años con el teléfono a un brazo de distancia no lo lee,
      lo adivina. Estos dos estaban en 13 y 13.5. */
  .hz-eyebrow{color:var(--muted);font-weight:700;font-size:14px}
  .hz-hello{font-family:var(--font-display);font-weight:800;font-size:clamp(28px,7.5vw,36px);letter-spacing:-.03em;color:var(--tinta);line-height:1.05;margin-top:4px}
  .hz-status{display:inline-flex;align-items:center;gap:7px;margin-top:11px;color:var(--teal-700,#00827e);font-weight:700;font-size:14px;background:color-mix(in srgb,var(--teal) 10%,#fff);border:1px solid color-mix(in srgb,var(--teal) 22%,#fff);border-radius:999px;padding:5px 13px}
  .hz-status svg{width:15px;height:15px}
  /* ══ EL NORTE ══ la meta manda: es lo primero que se ve al entrar.
     Sin meta, la pregunta ocupa el lugar de honor y nada compite con ella. */
  .norte{background:linear-gradient(135deg,color-mix(in srgb,var(--teal) 12%,#fff),var(--card));
    border:1px solid color-mix(in srgb,var(--teal) 30%,#fff);border-radius:20px;padding:18px 19px;margin-top:16px;
    box-shadow:0 10px 30px -18px rgba(0,120,115,.5)}
  /*  14px es el suelo, tambien aqui. Esta tarjeta dice lo MISMO que Tu Meta,
      y Tu Meta lo dice a 14: decirlo a 11 seria la misma frase con menos
      derecho a leerse. Las versalitas se quedan — lo que sube es el cuerpo. */
  .n-eb{display:block;font-size:14px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--teal-700,#00827e);margin-bottom:7px}
  .n-top{display:flex;justify-content:space-between;align-items:flex-start;gap:14px}
  .n-num b{font-family:'Oswald',var(--font-display,sans-serif);font-size:40px;line-height:.95;color:var(--tinta);letter-spacing:-.5px}
  .n-num span{display:block;font-size:14px;color:var(--muted);font-weight:600;margin-top:3px}
  .n-dias{text-align:center;background:#fff;border:1px solid var(--line);border-radius:13px;padding:8px 13px;flex:none}
  .n-dias b{display:block;font-family:'Oswald',var(--font-display,sans-serif);font-size:22px;line-height:1;color:var(--tinta)}
  .n-dias span{font-size:14px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.04em}
  .n-barra{height:9px;border-radius:99px;background:#fff;border:1px solid var(--line);overflow:hidden;margin:14px 0 8px}
  .n-barra i{display:block;height:100%;background:linear-gradient(90deg,var(--teal),var(--magenta));border-radius:99px;transition:width .6s cubic-bezier(.4,0,.2,1)}
  .n-ritmo{font-size:14px;font-weight:600;margin:0}
  .n-ritmo.bien{color:#0a6a5f} .n-ritmo.mal{color:#b4232b}
  /* La victoria corta: lo que SÍ se movió esta semana, mientras el número
     grande madura. Va discreta — informa, no compite con la meta. */
  .n-semana{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:12px;
    background:#fff;border:1px solid var(--line);border-radius:11px;padding:9px 12px}
  .ns-t{font-size:11.5px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;color:var(--muted);white-space:nowrap;flex:none}
  .ns-v{font-size:12.5px;font-weight:700;color:var(--teal-dark,#00827e);text-align:right}
  .n-jugada{background:#fff;border:1px solid var(--line);border-radius:14px;padding:12px 14px;margin-top:14px}
  .n-jl{display:block;font-size:14px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);margin-bottom:5px}
  .n-jugada b{display:block;font-size:16px;color:var(--tinta);line-height:1.35;margin-bottom:4px}
  .n-jugada p{margin:0;font-size:14px;color:var(--muted);line-height:1.5}
  .n-cta{display:inline-flex;align-items:center;justify-content:center;gap:8px;margin-top:15px;
    background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff;text-decoration:none;
    font-weight:800;font-size:14.5px;padding:13px 20px;border-radius:13px;width:100%}
  .n-cta svg{width:16px;height:16px}
  .n-cta.ghost{background:transparent;color:var(--teal-700,#00827e);border:1.5px solid color-mix(in srgb,var(--teal) 40%,#fff);
    font-size:13.5px;padding:11px 16px;margin-top:13px}
  /* Sin meta: la pregunta es la pantalla, no una tarjeta más */
  .norte-vacio{text-align:center;padding:30px 22px}
  .norte-vacio h2{font-family:'Oswald',var(--font-display,sans-serif);font-size:26px;line-height:1.15;color:var(--tinta);margin:0 0 9px;letter-spacing:.3px}
  .norte-vacio p{font-size:14px;color:var(--muted);line-height:1.6;margin:0 auto;max-width:400px}
  .norte-cerrada{text-align:center;padding:26px 22px}
  .norte-cerrada h2{font-family:'Oswald',var(--font-display,sans-serif);font-size:24px;color:var(--tinta);margin:0 0 8px;letter-spacing:.3px}
  .norte-cerrada p{font-size:13.5px;color:var(--muted);line-height:1.55;margin:0 auto;max-width:400px}

  /* El card ENTERO es tocable: es el objeto más importante de la pantalla,
     no puede pedirle al pulgar que cace un link chiquito. */
  a.norte.viva{display:block;text-decoration:none;color:inherit;transition:transform .14s cubic-bezier(.4,0,.2,1),box-shadow .18s}
  a.norte.viva:hover{transform:translateY(-2px);box-shadow:0 16px 38px -18px rgba(0,120,115,.55)}
  a.norte.viva:active{transform:scale(.995)}
  .n-ir{display:flex;align-items:center;justify-content:center;gap:7px;margin-top:14px;
    font-size:15px;font-weight:700;color:var(--teal-700,#00827e);min-height:44px;
    border-top:1px dashed color-mix(in srgb,var(--teal) 28%,#fff);padding-top:12px}
  .n-ir i{font-style:normal;transition:transform .18s}
  a.norte.viva:hover .n-ir i{transform:translateX(4px)}

  /* ATRASADO: el card cambia de temperatura. El color es información —
     de un vistazo, desde lejos, sabes si hay que apretar. */
  .norte.atrasado{background:linear-gradient(135deg,color-mix(in srgb,var(--coral) 14%,#fff),var(--card));
    border-color:color-mix(in srgb,var(--coral) 38%,#fff);box-shadow:0 10px 30px -18px rgba(255,107,61,.55)}
  .norte.atrasado .n-eb{color:#c2410c}
  .norte.atrasado .n-barra i{background:linear-gradient(90deg,var(--coral),var(--magenta))}
  .norte.atrasado .n-ir{color:#c2410c;border-top-color:color-mix(in srgb,var(--coral) 32%,#fff)}
  a.norte.viva.atrasado:hover{box-shadow:0 16px 38px -18px rgba(255,107,61,.6)}

  @media (prefers-reduced-motion:reduce){
    a.norte.viva,a.norte.viva:hover,.n-ir i{transition:none;transform:none}
  }

  .hz-card{background:var(--card);border:1px solid var(--line);border-radius:20px;padding:16px;box-shadow:var(--shadow-sm);margin-top:16px}
  /* ══ LOS BLOQUES DEL CENTRO DE MANDO ══════════════════════════════════
     Compactos a propósito: una tarjeta no explica una sección entera, la
     anuncia y abre la de verdad. Nada de tarjetas dentro de tarjetas, nada
     de párrafos, y el radio del sistema — no uno nuevo para lucirse. */
  .in-blk{padding:14px 16px}
  .in-h{display:flex;align-items:baseline;justify-content:space-between;gap:10px;margin-bottom:10px}
  .in-h b{font-size:14px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:var(--muted)}
  /*  El enlace de cada bloque es un destino real, no un adorno: 44px de alto
      para que se pueda tocar con el pulgar sin apuntar. */
  .in-h a{font-size:14px;font-weight:700;color:var(--teal);text-decoration:none;
    display:inline-flex;align-items:center;gap:4px;min-height:44px;padding:0 2px}
  .in-h a i{font-style:normal;transition:transform .15s ease}
  .in-h a:hover i{transform:translateX(3px)}
  .in-vacio,.in-nota{font-size:14px;line-height:1.55;color:var(--muted);margin:0}

  /* — Hoy y lo próximo — */
  .in-list{list-style:none;margin:0;padding:0;display:grid;gap:2px}
  .in-list a{display:grid;grid-template-columns:auto 1fr;grid-template-areas:'w t' 'w m';
    gap:1px 12px;align-items:center;min-height:56px;padding:8px 4px;border-radius:8px;
    text-decoration:none;color:inherit}
  .in-list a:hover{background:color-mix(in srgb,var(--teal) 6%,transparent)}
  .in-when{grid-area:w;font-size:14px;font-weight:700;color:var(--muted);white-space:nowrap}
  .in-when.hoy{color:var(--teal)}
  .in-tit{grid-area:t;font-size:15px;line-height:1.35;color:var(--tinta);
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .in-meta{grid-area:m;font-size:14px;color:var(--muted);
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

  /* — Lo que hizo el corillo — */
  .in-act{list-style:none;margin:0;padding:0;display:grid;gap:9px}
  .in-act li{display:flex;align-items:flex-start;gap:9px;font-size:15px;line-height:1.5;color:var(--tinta)}
  .in-act li .ic{width:18px;height:18px;flex:none;margin-top:3px;color:var(--teal)}
  .in-act li a{margin-left:auto;flex:none;min-height:44px;display:inline-flex;align-items:center;
    padding:0 10px;border-radius:8px;font-size:14px;font-weight:700;color:var(--teal);
    text-decoration:none;white-space:nowrap}
  .in-act li a:hover{background:color-mix(in srgb,var(--teal) 9%,transparent)}
  .in-act li.urge .ic{color:#c2410c}
  .in-act li.urge a{color:#c2410c}
  .in-act li i{font-style:normal;font-size:14px;color:var(--muted);margin-left:auto;
    white-space:nowrap;padding-left:8px}

  /* — Cómo va — */
  .in-cifra{display:flex;align-items:baseline;gap:9px;margin:0 0 6px}
  .in-cifra b{font-family:'Oswald',var(--font-display,sans-serif);font-size:34px;
    line-height:1;color:var(--tinta);letter-spacing:.5px}
  .in-cifra span{font-size:14px;color:var(--muted)}

  /* — Te toca a ti — */
  .in-pend{list-style:none;margin:0;padding:0;display:grid;gap:10px}
  .in-pend li{display:flex;align-items:center;gap:10px}
  .in-pend .ic{width:34px;height:34px;flex:none;border-radius:8px;display:grid;place-items:center;
    background:color-mix(in srgb,var(--teal) 10%,transparent);color:var(--teal)}
  .in-pend .ic .ic{width:18px;height:18px;background:none;border-radius:0}
  .in-pend li.urge .ic{background:color-mix(in srgb,var(--coral) 14%,transparent);color:#c2410c}
  .in-pend .tx{min-width:0;flex:1}
  .in-pend .tx b{display:block;font-size:15px;line-height:1.35;color:var(--tinta);font-weight:600}
  .in-pend .tx i{display:block;font-style:normal;font-size:14px;line-height:1.4;color:var(--muted)}
  .in-go{flex:none;min-height:44px;display:inline-flex;align-items:center;padding:0 12px;
    border-radius:8px;background:var(--tinta);color:#fff;font-size:14px;font-weight:700;
    text-decoration:none}
  .in-go:hover{opacity:.9}
  .in-pend li.urge .in-go{background:#c2410c}
  /*  «No tienes nada pendiente» NO ocupa media pantalla: es una línea. */
  .in-nada{display:flex;align-items:center;gap:8px;margin:16px 2px 0;
    font-size:14px;color:var(--muted)}
  .in-nada .ic{width:18px;height:18px;color:var(--teal)}

  @media(min-width:900px){
    /*  ESCRITORIO CUENTA LA MISMA HISTORIA, no una distinta: los mismos
        bloques en el mismo orden, en dos columnas para que quepan sin bajar. */
    .in-blk{margin-top:0}
  }

  .hz-ch{display:flex;align-items:center;justify-content:space-between;margin-bottom:13px}
  .hz-ch b{font-family:var(--font-display);font-weight:700;font-size:16px;color:var(--ink-soft,#4a444c)}
  .hz-ch a{color:var(--magenta);font-weight:700;font-size:12.5px;text-decoration:none}
  .hz-up{color:#1f9d63;font-weight:800;font-size:12.5px;display:inline-flex;align-items:center;gap:5px}
  .hz-up svg{width:14px;height:14px}
  .hz-next{display:flex;gap:15px;align-items:stretch}
  .hz-next .l{flex:1;min-width:0;display:flex;flex-direction:column}
  .hz-next .eb{font-size:11.5px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:var(--magenta)}
  .hz-next .cap{font-size:15px;line-height:1.45;color:var(--tinta);margin:7px 0 13px;font-weight:600;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
  .hz-approve{margin-top:auto;align-self:flex-start;background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff;text-decoration:none;font-weight:700;font-size:14px;padding:11px 18px;border-radius:12px;display:inline-flex;align-items:center;gap:8px;box-shadow:0 10px 22px -10px rgba(239,67,117,.5)}
  .hz-approve svg{width:16px;height:16px}
  .hz-idea-go{margin-top:14px;align-self:flex-start;background:linear-gradient(135deg,var(--teal),#0a7d76);color:#fff;text-decoration:none;font-weight:700;font-size:13.5px;padding:10px 16px;border-radius:12px;display:inline-flex;align-items:center;gap:7px;box-shadow:0 10px 22px -12px rgba(0,164,159,.55);transition:transform .15s ease,box-shadow .15s ease}
  .hz-idea-go svg{width:15px;height:15px}
  .hz-idea-go:hover{transform:translateY(-2px);box-shadow:0 16px 30px -12px rgba(0,164,159,.6)}
  .hz-idea-go:active{transform:translateY(0)}
  .hz-when{margin-top:auto;font-size:13px;color:var(--muted);font-weight:600}
  .hz-when a{color:var(--teal-700,#00827e);font-weight:700;text-decoration:none;margin-left:6px}
  .hz-next .im{width:110px;flex:none;border-radius:14px;overflow:hidden;background:var(--crema-2);border:1px solid var(--line);display:grid;place-items:center;color:var(--muted);aspect-ratio:4/5}
  .hz-next .im img,.hz-next .im video{width:100%;height:100%;object-fit:cover;display:block}
  .hz-next .im svg{width:30px;height:30px}
  .hz-week{display:grid;grid-template-columns:repeat(7,1fr);gap:6px}
  .hz-day{display:flex;flex-direction:column;align-items:center;gap:3px;padding:9px 0 7px;border-radius:13px}
  .hz-day .d{font-size:10px;font-weight:800;color:var(--muted);letter-spacing:.02em}
  .hz-day .n{font-size:15px;font-weight:700;color:var(--ink-soft,#4a444c);font-variant-numeric:tabular-nums}
  .hz-day .dots{display:flex;gap:3px;height:7px;margin-top:2px}
  .hz-day .dots i{width:5px;height:5px;border-radius:50%}
  .hz-day.on{background:var(--teal)}
  .hz-day.on .d,.hz-day.on .n{color:#fff}
  .hz-spark{width:100%;height:78px;display:block}
  .hz-empty{color:var(--muted);font-size:14px;line-height:1.45;padding:4px 2px}
  .hz-tip{display:flex;gap:12px;align-items:flex-start}
  .hz-tip .ic{width:38px;height:38px;border-radius:11px;flex:none;display:grid;place-items:center}
  .hz-tip .ic.teal{background:color-mix(in srgb,var(--teal) 12%,#fff);color:var(--teal)}
  .hz-tip .ic.amber{background:color-mix(in srgb,var(--amber,#c78a16) 15%,#fff);color:var(--amber-ink,#9a6b00)}
  .hz-tip .ic svg{width:20px;height:20px}
  .hz-tip p{margin:0;font-size:14px;line-height:1.5;color:var(--tinta)}
  .hz-reco{margin-top:11px;background:color-mix(in srgb,var(--teal) 8%,#fff);border:1px solid color-mix(in srgb,var(--teal) 22%,#fff);border-radius:12px;padding:10px 12px;font-size:13px;color:var(--ink-soft,#4a444c);line-height:1.45;display:flex;gap:8px;align-items:flex-start}
  .hz-reco svg{width:15px;height:15px;color:var(--teal);flex:none;margin-top:2px}
  /* ADR-0004 · Tarjeta del Analista (proactivo) */
  .an-card{background:linear-gradient(180deg,color-mix(in srgb,var(--teal) 6%,#fff),#fff)}
  .an-head{display:flex;align-items:center;gap:10px;margin-bottom:12px}
  .an-av{width:36px;height:36px;border-radius:11px;flex:none;display:grid;place-items:center;background:linear-gradient(135deg,var(--teal),var(--teal-700,#00827e));color:#fff}
  .an-av svg{width:19px;height:19px}
  .an-who{display:flex;flex-direction:column;line-height:1.15;min-width:0}
  .an-who b{font-family:var(--font-display);font-weight:700;font-size:15px;color:var(--ink-soft,#4a444c)}
  .an-who .live{font-size:11px;font-weight:700;color:var(--teal);display:inline-flex;align-items:center;gap:6px}
  .an-dot{width:7px;height:7px;border-radius:50%;background:var(--teal);box-shadow:0 0 0 0 color-mix(in srgb,var(--teal) 55%,transparent);animation:anbeat 2s infinite;flex:none}
  @keyframes anbeat{0%{box-shadow:0 0 0 0 color-mix(in srgb,var(--teal) 55%,transparent)}70%{box-shadow:0 0 0 7px transparent}100%{box-shadow:0 0 0 0 transparent}}
  .an-title{font-family:var(--font-display);font-weight:800;font-size:16.5px;color:var(--tinta);letter-spacing:-.01em;margin:0 0 5px}
  .an-msg{margin:0;font-size:14px;line-height:1.5;color:var(--ink-soft,#4a444c)}
  .an-acts{display:flex;gap:8px;margin-top:14px;align-items:center}
  .an-go{flex:1;display:inline-flex;align-items:center;justify-content:center;gap:7px;background:var(--btn-grad,linear-gradient(135deg,#FF6B3D,#EF4375));color:#fff;font-family:var(--font-display);font-weight:700;font-size:14.5px;padding:12px 14px;border-radius:13px;text-decoration:none;box-shadow:var(--btn-glow)}
  .an-skip{background:0;border:0;color:var(--muted);font-weight:600;font-size:13px;padding:11px 8px;cursor:pointer;flex:none}
  .an-ok{color:var(--teal-dark,#00827e);font-weight:700;font-size:14.5px;display:inline-flex;align-items:center;gap:8px;margin-bottom:5px}
  .an-ok svg{width:18px;height:18px;flex:none}
  .an-sub{color:var(--muted);font-size:13px}
  /* El Analista ahora carga con sus propios números, así que necesita su salida
     al detalle en vez de una tarjeta "Cómo vas" aparte. */
  .an-ver{display:inline-block;margin-top:13px;font-size:13px;font-weight:700;color:var(--teal-dark,#00827e);text-decoration:none}
  .an-ver:hover{text-decoration:underline}
  /* El hilo entre una pieza y la meta de la que salió */
  .eb-meta{font-style:normal;color:var(--magenta);font-weight:800}
  .hz-dejugada{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:var(--teal-dark,#00827e);
    background:color-mix(in srgb,var(--teal) 10%,#fff);border:1px solid color-mix(in srgb,var(--teal) 26%,#fff);
    border-radius:99px;padding:4px 10px;margin:6px 0 2px;max-width:100%}
  .hz-dejugada svg{width:12px;height:12px;flex:none}
  .hz-notlist{display:flex;flex-direction:column}
  .hz-not{display:flex;gap:11px;align-items:center;padding:10px 2px;text-decoration:none;color:inherit;border-top:1px solid var(--line)}
  .hz-not:first-child{border-top:0}
  .hz-not .ni{width:34px;height:34px;border-radius:10px;flex:none;display:grid;place-items:center;background:var(--crema-2);color:var(--magenta)}
  .hz-not .ni svg{width:17px;height:17px}
  .hz-not .nt{min-width:0}
  .hz-not .nt b{display:block;font-size:13.5px;font-weight:700;color:var(--tinta);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .hz-not .nt small{display:block;font-size:12px;color:var(--muted)}
  .hz-fc{display:flex;align-items:center;gap:16px}
  .hz-fc .fc-n{font-family:var(--font-display);font-weight:800;font-size:46px;letter-spacing:-.03em;color:var(--teal-700,#00827e);line-height:1}
  .hz-fc .fc-l{font-size:13.5px;color:var(--ink-soft,#4a444c);line-height:1.4}
  .hz-fc .fc-l small{color:var(--muted)}
  /* DESKTOP: aprovecha el ancho — grid de 2 columnas, no una tira vertical */
  @media(min-width:861px){
    .hz{max-width:1040px;display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start;padding:26px 26px 48px}
    .hz-hi{grid-column:1 / -1;padding:2px 2px 2px}
    .hz-hello{font-size:34px}
    .hz-card{margin-top:0}
    .hz{grid-auto-flow:row dense}
    .hz-card:has(.hz-next){grid-column:1 / -1}       /* Próximo post = hero ancho */
    .hz-card:has(.hz-next) .hz-next .im{width:200px}
  }
  /* Saludo + clima a la derecha */
  .hz-hi{display:flex;justify-content:space-between;align-items:flex-start;gap:14px}
  .hz-hi-l{min-width:0}
  .hz-wx{flex:none;display:flex;flex-direction:column;align-items:center;text-align:center;padding-top:6px;margin-right:clamp(12px,9vw,64px)}
  /*  EL `hidden` NO ESTABA HACIENDO NADA. La caja nace con el atributo puesto
      y el JS se lo quita cuando el clima contesta — pero `display:flex` de la
      linea de arriba gana al atributo, asi que el marcador de posicion «--°»
      se veia SIEMPRE hasta que llegara el dato. Y si no llega —el cliente sin
      datos, la API caida, una red que bloquea el dominio— se queda ahi para
      siempre: un hueco roto en lo primero que el dueño mira cada mañana. */
  .hz-wx[hidden]{display:none}
  .hz-wx .wx-ic svg{width:30px;height:30px}
  .hz-wx .wx-t{font-family:var(--font-display);font-weight:800;font-size:24px;letter-spacing:-.02em;color:var(--tinta);line-height:1.1}
  .hz-wx .wx-c{font-size:11.5px;font-weight:700;color:var(--muted)}
  .hz-tip .ic.pur{background:#efeaff;color:#7c58e8}
  .hz-cap{font-size:12px;color:var(--muted);margin-top:8px;font-weight:600}
  /* Entrada: fade-in desde abajo, escalonada */
  @keyframes hzIn{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}
  .hz-hi,.hz-card{opacity:0;animation:hzIn .5s cubic-bezier(.2,.85,.25,1) both}
  .hz-hi{animation-delay:.03s}
  .hz-card:nth-of-type(1){animation-delay:.10s}
  .hz-card:nth-of-type(2){animation-delay:.16s}
  .hz-card:nth-of-type(3){animation-delay:.22s}
  .hz-card:nth-of-type(4){animation-delay:.28s}
  .hz-card:nth-of-type(5){animation-delay:.34s}
  .hz-card:nth-of-type(6){animation-delay:.40s}
  .hz-card:nth-of-type(7){animation-delay:.46s}
  .hz-card:nth-of-type(n+8){animation-delay:.52s}
  @media(prefers-reduced-motion:reduce){.hz-hi,.hz-card{animation:none;opacity:1}}
  /* Spark: micro-interacciones */
  .hz-card{transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease}
  @media(hover:hover){
    .hz-card:hover{transform:translateY(-3px);box-shadow:0 18px 42px -20px rgba(40,32,45,.24);border-color:#e5dfd8}
    .hz-next .im img,.hz-next .im video{transition:transform .3s ease}
    .hz-card:hover .hz-next .im img,.hz-card:hover .hz-next .im video{transform:scale(1.06)}
  }
  .hz-approve{transition:transform .15s ease,box-shadow .15s ease}
  .hz-approve:hover{transform:translateY(-2px);box-shadow:0 16px 30px -10px rgba(239,67,117,.6)}
  .hz-approve:active{transform:translateY(0)}
  .hz-status{transition:transform .15s ease}
  .hz-status:hover{transform:scale(1.02)}
  /* La gráfica se dibuja sola */
  .hz-spark polyline{stroke-dasharray:520;stroke-dashoffset:520;animation:hzdraw 1.1s .35s cubic-bezier(.4,0,.2,1) forwards}
  .hz-spark circle{opacity:0;animation:hzdot .3s 1.3s forwards}
  @keyframes hzdraw{to{stroke-dashoffset:0}}
  @keyframes hzdot{to{opacity:1}}
  @media(prefers-reduced-motion:reduce){
    .hz-spark polyline{stroke-dasharray:none;stroke-dashoffset:0;animation:none}
    .hz-spark circle{opacity:1;animation:none}
    .hz-card,.hz-approve{transition:none}
  }
</style>
<main class="hz">
  <div class="hz-hi">
    <div class="hz-hi-l">
      <div class="hz-eyebrow"><?= $hz_saludo ?></div>
      <div class="hz-hello">¡Hola, <?= $h($hz_nombre) ?>!</div>
      <div class="hz-status"><?= ico('check-circle') ?> <?= $h($hz_status) ?></div>
    </div>
    <div class="hz-wx" id="hzWx" hidden>
      <span class="wx-ic" id="hzWxIc"></span>
      <span class="wx-t" id="hzWxT">--°</span>
      <span class="wx-c" id="hzWxC"></span>
    </div>
  </div>

  <?php
  // ══════════════════════════════════════════════════════════════════════
  //  EL NORTE — lo primero que se ve al entrar.
  //
  //  HASTA LA FASE 5, ESTA PANTALLA DECIDIA POR SU CUENTA. Leia meta_activa,
  //  meta_progreso y meta_tactica_de_turno, y colapsaba los TRECE estados de Tu
  //  Meta en tres suyos: ninguna / cerrada / activa. El resultado eran dos
  //  pantallas contradiciendose sobre el mismo negocio en el mismo instante:
  //
  //    Tu Meta: «Tengo algo listo para tu OK»  → abre el post
  //    Home:    «Tenemos que apretar un poco»  → abre Tu Meta
  //
  //  Y era peor de lo que parece: Home pintaba barra, porcentaje y ritmo SIN el
  //  contrato de cobertura. Afirmaba «vamos en ritmo» contando solo lo que pasa
  //  por Crecer — justo lo que se prohibio en Tu Meta, vivo en la primera
  //  pantalla que ve el dueño. Y no sabia nada de la cuota: con el cubo lleno
  //  seguia mandando a producir.
  //
  //  AHORA: el lector lee, el compositor decide, y Home consume su RESUMEN. La
  //  regla vive en un sitio. Si un dia cambia, cambia para las dos.
  // ══════════════════════════════════════════════════════════════════════
  $__E = null; $__res = []; $__cuota = []; $__snap = [];
  //  La bandera que el resto de Home todavia usa. Ya NO la decide Home: se
  //  traduce del estado del compositor, en un solo sitio.
  $__meta_activa = false;
  try {
      require_once __DIR__ . '/../includes/meta_negocio.php';
      require_once __DIR__ . '/../core/Meta/MetaSnapshotReader.php';
      require_once __DIR__ . '/../core/Meta/MetaStateComposer.php';
      require_once __DIR__ . '/../core/Meta/MetaLimiteImagen.php';
      require_once __DIR__ . '/../core/Meta/MetaPresentador.php';

      $__snap = MetaSnapshotReader::leer($pdo, $marca_id);
      $__E    = MetaStateComposer::componer($__snap);
      $__res  = $__E->resumen();

      //  LA MISMA REGLA DE CUOTA QUE USA TU META. No una parecida: la misma
      //  funcion, con el mismo estado de entrada.
      try {
          require_once __DIR__ . '/../includes/suscripcion.php';
          $__cuota = img_cuota_estado($pdo, $marca_id);
      } catch (Throwable $e) { $__cuota = []; }
  } catch (Throwable $e) {
      //  Sin las tablas de la meta, Home sigue siendo Home: se calla el norte y
      //  pinta el resto. Una migracion que falta no puede tumbar la portada.
      $__E = null;
  }

  // ══════════════════════════════════════════════════════════════════════
  //  LA FRONTERA. Se cruza AQUI, una sola vez, y de aqui para abajo Home no
  //  vuelve a ver el estado, la evidencia ni el snapshot: solo $__hm.
  //
  //  La primera version de esta fase calculaba el resumen y lo dejaba muerto,
  //  y despues renderizaba leyendo $__E y $__snap igual que antes. Eso no es
  //  una frontera: es un comentario. Con el estado completo a mano, basta que
  //  alguien añada un `if` sobre la evidencia para volver a tener dos pantallas
  //  decidiendo por su cuenta — que es de donde venimos.
  //
  //  Ahora lo que no esta en $__hm, Home no lo puede pintar. No porque este
  //  prohibido: porque no lo tiene. Hay una prueba estructural que se pone roja
  //  si esta frontera se vuelve a cruzar hacia abajo.
  // ══════════════════════════════════════════════════════════════════════
  $__hm = $__E
      ? MetaPresentador::paraHome($__E, $__cuota ?: [], $__snap, $BASE, $marca_id)
      : null;

  //  La bandera que el resto de Home usa sale tambien del DTO, no del estado.
  $__meta_activa = $__hm && !$__hm['sin_meta'] && !$__hm['cerrada'];
  //  DOS COSAS DE FUERA DE LA TARJETA siguen necesitando datos del snapshot: el
  //  aviso de «esta pieza nace de una jugada de tu plan» y el bloque de
  //  analitica. Se sacan AQUI, con nombre y arriba de la frontera, para que no
  //  parezca que la tarjeta los usa — la tarjeta no los ve.
  $__meta = (array)($__snap['meta'] ?? []) ?: null;
  $__prog = (array)($__snap['progreso'] ?? []);
  unset($__E, $__snap, $__res);
  ?>

  <?php if (!$__hm || $__hm['sin_meta']): ?>
    <?php /*  SIN META, LA PREGUNTA ES LA PANTALLA. Nada compite con ella: el
              producto entero cuelga de que haya un norte.  */ ?>
    <section class="norte norte-vacio">
      <span class="n-eb">Empecemos por aquí</span>
      <h2>¿Qué quieres lograr este mes?</h2>
      <p>Dime el número que te haría feliz y el corillo arma el plan para llegar —
         y se pone a trabajar en él. Sin un norte, publicar es dar vueltas.</p>
      <a class="n-cta" href="<?= $BASE ?>/meta.php?<?= $mid ?>&amp;vista=wizard"><?= ico('compass') ?> Ponerle meta a mi mes</a>
    </section>

  <?php else: ?>
    <?php /*  LA MISMA DECISIÓN QUE TU META, PALABRA POR PALABRA.
              Todo lo que se pinta aquí sale de $__hm. Si esta tarjeta dijera
              algo distinto de Tu Meta, sería porque alguien volvió a cruzar la
              frontera de arriba.  */ ?>
    <a class="norte viva<?= $__hm['cerrada'] ? ' norte-cerrada' : '' ?>" href="<?= $h($__hm['accion']['destino']) ?>">
      <div class="n-top">
        <div>
          <span class="n-eb"><?= $h($__hm['turno']['txt'] !== '' ? $__hm['turno']['txt'] : 'Tu meta de este mes') ?></span>
          <?php if ($__hm['cifra']['grande'] !== ''): ?>
            <div class="n-num">
              <?php if ($__hm['cifra']['cuenta'] !== null): ?>
                <b data-cuenta="<?= (int)$__hm['cifra']['cuenta'] ?>">0</b>
              <?php else: ?>
                <b><?= $h($__hm['cifra']['grande']) ?></b>
              <?php endif; ?>
              <span><?= $h($__hm['cifra']['pie']) ?></span>
            </div>
          <?php endif; ?>
        </div>
        <?php if ($__hm['dias'] !== null): ?>
          <div class="n-dias"><b><?= (int)$__hm['dias'] ?></b><span>días</span></div>
        <?php endif; ?>
      </div>

      <?php /*  LA BARRA Y EL RITMO SOLO EXISTEN SI EL DTO LOS TRAE, y el DTO
                solo los trae con cobertura completa. Antes se pintaban siempre:
                una barra afirma «vas por aquí de un total que conozco», y con
                cobertura parcial Crecer no conoce el total.  */ ?>
      <?php if ($__hm['barra']): ?>
        <div class="n-barra"><i data-ancho="<?= (int)$__hm['barra']['pct'] ?>" style="width:0"></i></div>
        <?php if ($__hm['barra']['ritmo'] === 'mal'): ?>
          <p class="n-ritmo mal">Tenemos que apretar un poco.</p>
        <?php elseif ($__hm['barra']['ritmo'] === 'bien'): ?>
          <p class="n-ritmo bien">Vamos en ritmo.</p>
        <?php endif; ?>
      <?php endif; ?>

      <?php /*  LO QUE TOCA AHORA: el título del compositor, ya presentado.
                Antes aquí salía la «jugada de turno», que era la regla de Tu
                Meta reimplementada en esta pantalla con otro resultado.  */ ?>
      <div class="n-jugada">
        <span class="n-jl">Lo que toca ahora</span>
        <?php /*  EL TITULO SE TRADUCE AQUI, EN LA PANTALLA, no en el compositor.
                  MetaStateComposer es puro y tiene que seguir siendolo: mete
                  t() ahi y el estado deja de ser el mismo objeto segun quien
                  mire. Ademas Tu Meta comparte ese compositor y todavia no
                  entra en la fase de idiomas — traducir en el origen la habria
                  arrastrado sin querer.  */ ?>
        <b><?= $h(t($__hm['titulo'])) ?></b>
        <?php if ($__hm['objeto']['titulo'] !== ''): ?>
          <p><?= $h($__hm['objeto']['titulo']) ?></p>
        <?php endif; ?>
      </div>

      <span class="n-ir"><?= $h($__hm['accion']['etiqueta'] !== '' ? $__hm['accion']['etiqueta'] : 'Ver el plan completo') ?> <i>→</i></span>
    </a>
  <?php endif; ?>
  <?php
  // ══════════════════════════════════════════════════════════════════════
  //  EL RESTO DEL CENTRO DE MANDO — por orden de urgencia, no de tamaño.
  //
  //  Lo que había aquí eran seis tarjetas grandes contestando preguntas
  //  distintas con voces distintas: el próximo post, la semana de siete
  //  columnas, la gráfica de «posts por semana», el tip del Analista, el tip
  //  financiero y la Idea del día. El dueño no leía un equipo poniéndolo al
  //  día: leía un tablero de aeropuerto. Y dos de esas tarjetas mentían por
  //  construcción — la gráfica usaba CUÁNTOS POSTS publicó como si fuera el
  //  resultado de su negocio, y la Idea del día llamaba a Gemini en CADA
  //  carga de la portada.
  //
  //  Ahora son cuatro bloques compactos, cada uno con su lector pequeño en
  //  includes/inicio.php, y cada uno abre su sección de verdad. Ninguno
  //  intenta explicar una sección entera aquí.
  require_once __DIR__ . '/../includes/inicio.php';
  $in_cal  = inicio_calendario($pdo, $marca_id, 3);
  //  LO QUE DICE EL CORILLO, POR CONSECUENCIA. Antes era una lista de lo que
  //  había hecho, en orden de llegada. Ahora manda lo que NO sale si el dueño
  //  no hace nada —un fallo, su material, su tarea—, después lo que está
  //  listo para decidir, y al final lo que ya va solo. Un bloque que enseña
  //  seis cosas a la vez no tiene prioridad: se la deja al que mira.
  require_once __DIR__ . '/../includes/ejecucion.php';
  $ej_ops   = ejec_operacion($pdo, $marca_id, null);
  //  La etapa sale de la LETRA del compositor, que ya viene en el DTO. Home
  //  no vuelve a mirar el estado ni el snapshot: la frontera sigue en pie.
  $ej_etapa = ejec_etapa((string)($__hm['estado'] ?? ''), $ej_ops);
  $in_act   = ejec_mensajes($pdo, $marca_id, $marca, $BASE, $ej_ops, $ej_etapa);
  $in_sen  = inicio_senal($pdo, $marca_id, null);
  $in_pend = inicio_pendientes($pdo, $marca_id, $BASE, ['sin_redes' => !$meta_ok]);
  ?>

  <?php /*  1 · HOY Y LO PRÓXIMO. Un adelanto, no el calendario. Cada fila dice
            cuándo, dónde y DE DÓNDE SALIÓ: que una pieza sea suya o del plan
            cambia lo que significa verla ahí.  */ ?>
  <section class="hz-card in-blk">
    <div class="in-h">
      <b><?= $h(t('Hoy y lo próximo')) ?></b>
      <a href="<?= $BASE ?>/calendario.php?<?= $mid ?>"><?= $h(t('Ver Calendario')) ?> <i>→</i></a>
    </div>
    <?php if ($in_cal['hay']): ?>
      <ul class="in-list">
        <?php foreach ($in_cal['filas'] as $f): ?>
          <li>
            <a href="<?= $BASE ?>/calendario.php?<?= $mid ?>">
              <span class="in-when<?= $f['hoy'] ? ' hoy' : '' ?>"><?= $h($f['cuando']) ?></span>
              <span class="in-tit"><?= $h($f['titulo'] !== '' ? $f['titulo'] : t('Publicación')) ?></span>
              <span class="in-meta"><?= $h($f['red']) ?> · <?= $h($f['formato']) ?> · <?= $h(t($f['origen'])) ?></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php elseif ($in_cal['estado'] === CTX_NO_DISP): ?>
      <?php /*  No se pudo leer. No es «no tienes nada»: es que no lo sé, y
                decir lo otro sería inventarle un calendario vacío.  */ ?>
      <p class="in-vacio"><?= $h(t('No pude leer tu calendario ahora mismo.')) ?></p>
    <?php else: ?>
      <p class="in-vacio"><?= $h(t('Todavía no hay publicaciones programadas.')) ?></p>
    <?php endif; ?>
  </section>

  <?php /*  2 · EL CORILLO. Cada línea sale de una fila que existe: una semana
            preparada, unas piezas escritas, una foto suya enlazada. Si no hay
            filas, no hay bloque — un equipo que dice que trabajó sin haber
            trabajado se descubre a la primera.  */ ?>
  <?php if ($in_act['mensajes']): ?>
  <section class="hz-card in-blk">
    <div class="in-h"><b><?= $h(t('%s está trabajando', $in_act['nombre'])) ?></b></div>
    <ul class="in-act">
      <?php foreach ($in_act['mensajes'] as $e): ?>
        <li class="<?= !empty($e['urgente']) ? 'urge' : '' ?>">
          <?= ico($e['ico']) ?>
          <span><?= $h($e['txt']) ?></span>
          <?php /*  Cada mensaje lleva a donde se resuelve. Uno que solo
                    informa y no abre nada obliga al dueño a buscarlo. */ ?>
          <?php if ($e['href'] !== ''): ?>
            <a href="<?= $h($e['href']) ?>"><?= $h($e['accion']) ?></a>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <?php /*  3 · EL RESULTADO. UNA señal, y solo con cobertura. Sin ella se dice
            que no hay suficiente y punto: ni flecha, ni «vamos en ritmo», ni
            la cuenta de posts publicados haciéndose pasar por resultado del
            negocio.  */ ?>
  <?php if ($in_sen['hay']): ?>
  <section class="hz-card in-blk">
    <div class="in-h">
      <b><?= $h(t('Cómo va')) ?></b>
      <a href="<?= $BASE ?>/resultados.php?<?= $mid ?>"><?= $h(t('Ver todos los resultados')) ?> <i>→</i></a>
    </div>
    <?php if ($in_sen['cifra'] !== ''): ?>
      <p class="in-cifra"><b><?= $h($in_sen['cifra']) ?></b><span><?= $h($in_sen['pie']) ?></span></p>
    <?php endif; ?>
    <?php if ($in_sen['nota'] !== ''): ?>
      <p class="in-nota"><?= $h($in_sen['nota']) ?></p>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <?php /*  4 · LO QUE LE TOCA A ÉL. Solo si de verdad hay algo suyo. Los
            pendientes del sistema —un job en cola, una imagen generándose— no
            entran: eso es trabajo nuestro, y ponerlo en su lista es devolverle
            el trabajo que nos paga por hacer.  */ ?>
  <?php if ($in_pend['hay']): ?>
  <section class="hz-card in-blk">
    <div class="in-h"><b><?= $h(t('Te toca a ti')) ?></b></div>
    <ul class="in-pend">
      <?php foreach ($in_pend['items'] as $x): ?>
        <li class="<?= !empty($x['urgente']) ? 'urge' : '' ?>">
          <span class="ic"><?= ico($x['ico']) ?></span>
          <span class="tx">
            <b><?= $h($x['que']) ?></b>
            <i><?= $h($x['porque']) ?><?php if ($x['cuando'] !== ''): ?> · <?= $h($x['cuando']) ?><?php endif; ?></i>
          </span>
          <a class="in-go" href="<?= $h($x['href']) ?>"><?= $h($x['accion']) ?></a>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php else: ?>
    <p class="in-nada"><?= ico('check-circle') ?><?= $h(t('No tienes nada pendiente ahora.')) ?></p>
  <?php endif; ?>

</main>

<script>
//  EL JAVASCRIPT NO TRADUCE: RECIBE.
//  Estos textos solo salen si algo falla —una petición que se cae, un guardado
//  que no entra— y por eso son los que más se olvidan: un barrido de la
//  pantalla no los ve nunca. El PHP los traduce y los entrega ya hechos, que
//  es lo contrario de reemplazar texto en el DOM desde el cliente.
window.T = Object.assign(window.T || {}, <?= tj([
  'no_pudo'       => 'No se pudo. Intenta otra vez.',
  'sin_conexion'  => 'Se cayó la conexión. Intenta otra vez.',
  'cayo'          => 'Se cayó la conexión.',
  'no_guardo'     => 'No se pudo guardar.',
  'guardar'       => 'Guardar',
  'reescribiendo' => 'La IA está reescribiendo…',
  'regen_plan'    => 'Regenerar es parte del plan. Actívalo para que la IA reescriba.',
  'no_reescribio' => 'No se pudo reescribir. Intenta otra vez.',
]) ?>);
(function(){
  // ── Clima (Open-Meteo, sin key; cache 1h por pueblo) ──
  var box=document.getElementById('hzWx');
  if(box){
    var PUEBLO=<?= json_encode($hz_pueblo) ?>;
    var SVG={
      sol:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5.6 5.6 4.2 4.2M19.8 19.8l-1.4-1.4M18.4 5.6l1.4-1.4M4.2 19.8l1.4-1.4"/></svg>',
      nube:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 18h9a4 4 0 0 0 .4-8 6 6 0 0 0-11.6 1.4A3.5 3.5 0 0 0 7 18z"/></svg>',
      lluvia:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 15h9a4 4 0 0 0 .4-8 6 6 0 0 0-11.6 1.4A3.5 3.5 0 0 0 7 15z"/><path d="M8 19v2M12 19v2M16 19v2"/></svg>',
      tormenta:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 15h9a4 4 0 0 0 .4-8 6 6 0 0 0-11.6 1.4A3.5 3.5 0 0 0 7 15z"/><path d="M12 15l-2 4h3l-2 4"/></svg>'
    };
    var COL={sol:'#e0a83a',nube:'#8b93a1',lluvia:'#4a9cc7',tormenta:'#7c58e8'};
    function cond(c){ if(c===0)return['Despejado','sol']; if(c<=3)return['Parcial nublado','nube']; if(c===45||c===48)return['Neblina','nube']; if(c>=51&&c<=67)return['Lluvia','lluvia']; if(c>=71&&c<=77)return['Nieve','nube']; if(c>=80&&c<=82)return['Aguaceros','lluvia']; if(c>=95)return['Tormenta','tormenta']; return['—','nube']; }
    function show(t,c){ var k=cond(c); var ic=document.getElementById('hzWxIc'); ic.innerHTML=SVG[k[1]]||SVG.nube; ic.style.color=COL[k[1]]||'#8b93a1'; document.getElementById('hzWxT').textContent=Math.round(t)+'°'; document.getElementById('hzWxC').textContent=k[0]; box.hidden=false; }
    var fresh=false;
    try{ var cx=JSON.parse(localStorage.getItem('hz_wx')||'{}'); if(cx.t!=null && (Date.now()-cx.at<3600000) && cx.p===PUEBLO){ show(cx.t,cx.c); fresh=true; } }catch(e){}
    if(!fresh){
      var wx=function(la,lo){ fetch('https://api.open-meteo.com/v1/forecast?latitude='+la+'&longitude='+lo+'&current=temperature_2m,weather_code&temperature_unit=fahrenheit').then(function(r){return r.json();}).then(function(d){ var cu=d&&d.current; if(!cu)return; show(cu.temperature_2m,cu.weather_code); try{localStorage.setItem('hz_wx',JSON.stringify({t:cu.temperature_2m,c:cu.weather_code,p:PUEBLO,at:Date.now()}));}catch(x){} }).catch(function(){}); };
      if(PUEBLO){ fetch('https://geocoding-api.open-meteo.com/v1/search?name='+encodeURIComponent(PUEBLO)+'&count=1&country=PR&language=es').then(function(r){return r.json();}).then(function(d){ var g=d&&d.results&&d.results[0]; if(g)wx(g.latitude,g.longitude); else wx(18.4655,-66.1057); }).catch(function(){ wx(18.4655,-66.1057); }); }
      else wx(18.4655,-66.1057);
    }
  }
})();
</script>

<?php if (false): /* ── Home viejo (deck/launcher) DESACTIVADO — reversible ── */ ?>
<main class="turno" id="turno">
  <div class="tn-top">
    <span class="tn-neg"><?= $h($negocio) ?></span>
    <?php if ($credito): ?><span class="tn-cred"><?= $h($credito) ?></span><?php endif; ?>
  </div>

  <?php if (!$plan && $has_deck): ?>
  <div class="tn-activar">
    <span class="ta-badge"><?= ico('sparkles') ?> Tu primera propuesta está lista</span>
    <p class="ta-t">Activa Crecer para que el Corillo siga trabajando contigo.</p>
    <ul class="ta-list">
      <li><?= ico('sparkles') ?> Propuestas nuevas cada semana</li>
      <li><?= ico('calendar') ?> Tu calendario de contenido</li>
      <li><?= ico('image') ?> Publicación en tus redes</li>
      <li><?= ico('check-circle') ?> Seguimiento de resultados</li>
    </ul>
    <a class="ta-cta" href="<?= $BASE ?>/precios.php?<?= $mid ?>">Activar mi Corillo</a>
  </div>
  <?php endif; ?>

  <?php if ($has_deck): ?>
  <div class="tn-stage">
    <div class="deck" id="deck">
      <?php foreach ($deck as $card): ?>
      <article class="tcard" data-id="<?= $card['id'] ?>">
        <div class="tcard-media">
          <?php if ($card['img'] && $card['vid']): ?>
            <video src="<?= $h($card['img']) ?>" muted loop autoplay playsinline></video>
          <?php elseif ($card['img']): ?>
            <img src="<?= $h($card['img']) ?>" alt="">
          <?php else: ?>
            <div class="tcard-nomedia">
              <div class="tcard-nm-badge"><?= ico('image') ?></div>
              <div class="tcard-nm-txt">El arte está en camino</div>
              <div class="tcard-nm-sub"><?= $h($NM('disenador')) ?> lo está montando</div>
            </div>
          <?php endif; ?>
        </div>
        <div class="tcard-scrim"></div>
        <?php if ($card['plat']): ?><span class="tcard-tag"><?= $h($card['plat']) ?></span><?php endif; ?>
        <div class="tcard-body">
          <p class="tcard-cap"><?= $h($card['cap'] !== '' ? $card['cap'] : 'Sin texto todavía') ?></p>
          <button type="button" class="tn-ok" data-act="aprobar">Vamos con este</button>
          <div class="tn-sub">
            <button type="button" class="tn-adj" data-edit>Ajustar</button>
            <button type="button" class="tn-no" data-act="rechazar">No es esto</button>
          </div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <div class="tn-end" id="tnEnd">
      <p class="tn-end-t"><?= ico('check') ?> Listo.</p>
      <p class="tn-end-s">Los que aprobaste están guardados en <b>Listos para publicar</b>.</p>
      <a class="tn-ok" href="<?= $BASE ?>/aprobar2.php?marca=<?= $marca_id ?>&tab=listos" style="margin-top:18px">Ver y publicar →</a>
    </div>
  </div>
  <?php if (count($deck) > 1): ?>
  <div class="tn-dots" id="tnDots">
    <?php foreach ($deck as $i => $_c): ?><span class="tn-dot<?= $i === 0 ? ' on' : '' ?>"></span><?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php elseif ($modo === 'primerdia'): ?>
  <div class="tn-solo">
    <p class="tn-solo-t">Tu corillo está listo para empezar.</p>
    <a class="tn-ok" href="<?= $h($st['href']) ?>"><?= $h($st['cta']) ?></a>
  </div>

  <?php elseif (!$plan): ?>
  <div class="tn-solo">
    <p class="tn-solo-t">Tu primera propuesta está lista.</p>
    <p class="tn-solo-s">Activa Crecer para que el Corillo siga trabajando contigo.</p>
    <a class="tn-ok" href="<?= $BASE ?>/precios.php?<?= $mid ?>" style="margin-top:22px">Activar mi Corillo</a>
  </div>

  <?php else:
    $arrow = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
    $chev  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>';
    $cards = [
      ['reels.php',      'camera', '#ef4375', '#fff0f5', 'Haz un reel',    'Sube clips, yo lo armo'],
      ['propuestas.php', 'list',   '#00a49f', '#eafaf8', 'Tus Posts', 'Mira lo que preparé'],
      ['sala.php',       'chat',   '#7c58e8', '#f2edff', 'La Sala',        'Habla con tu corillo'],
      ['biblioteca.php', 'image',  '#c78a16', '#fff7df', 'Sube fotos',     'Alimenta al corillo'],
    ];
    $hh = (int)date('G');
    $saludo = $hh < 12 ? 'Buenos días' : ($hh < 19 ? 'Buenas tardes' : 'Buenas noches');
  ?>
  <div class="hm">
    <div class="hm-hero">
      <div class="hm-eyebrow"><?= $saludo ?></div>
      <h1>¿Qué creamos hoy?</h1>
      <p>Tu corillo dejó todo al día. Escoge por dónde seguimos.</p>
    </div>
    <div class="hm-grid">
      <?php foreach ($cards as $i => $c): ?>
      <a class="hm-card" href="<?= $BASE ?>/<?= $c[0] ?>?<?= $mid ?>" style="--acc:<?= $c[2] ?>;--soft:<?= $c[3] ?>;--d:<?= $i*70 ?>ms">
        <span class="hm-ic"><?= ico($c[1]) ?></span>
        <h3><?= $h($c[4]) ?></h3>
        <p><?= $h($c[5]) ?></p>
        <span class="hm-arw"><?= $arrow ?></span>
      </a>
      <?php endforeach; ?>
    </div>
    <a class="hm-wide" href="<?= $BASE ?>/calendario.php?<?= $mid ?>">
      <span class="hm-wic"><?= ico('calendar') ?></span>
      <span class="hm-wc"><strong>Tu calendario</strong><span><?= !empty($prox_fecha) ? 'Próximo post '.$h(_fecha_humana($prox_fecha)) : 'Todo organizado, sin enredos' ?></span></span>
      <span class="hm-wchev"><?= $chev ?></span>
    </a>
  </div>
  <style>
    .turno:has(.hm){justify-content:flex-start;overflow-y:auto;overflow-x:hidden;padding:0;background:#fff}
    .turno:has(.hm) .tn-top{display:none}
    .hm{width:100%;max-width:520px;margin:0 auto;padding:20px 16px 110px}
    .hm-hero{position:relative;padding:16px 4px 4px}
    .hm-eyebrow{color:#57545c;font-weight:700;font-size:13.5px;margin-bottom:10px}
    .hm-hero h1{margin:0;font-family:'Poppins',sans-serif;font-weight:700;font-size:36px;line-height:1.03;letter-spacing:-.04em;color:#25232b;max-width:290px}
    .hm-hero p{margin:13px 0 0;color:var(--muted,#7b7880);font-size:15.5px;line-height:1.5;max-width:320px}
    .hm-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:24px}
    .hm-card{position:relative;overflow:hidden;min-height:152px;display:flex;flex-direction:column;
      background:#fff;border:1px solid #ece8e3;border-radius:22px;padding:17px;text-decoration:none;color:inherit;
      box-shadow:0 8px 24px rgba(38,32,45,.05);
      opacity:0;transform:translateY(14px);animation:hmIn .5s cubic-bezier(.2,.85,.25,1) forwards;animation-delay:var(--d);
      transition:transform .2s,box-shadow .2s,border-color .2s}
    @keyframes hmIn{to{opacity:1;transform:none}}
    .hm-card:hover{transform:translateY(-4px);box-shadow:0 18px 40px rgba(38,32,45,.10);border-color:#ddd6d0}
    .hm-card::after{content:"";position:absolute;right:-38px;bottom:-44px;width:92px;height:92px;border-radius:50%;background:var(--soft);opacity:.7}
    .hm-ic{width:47px;height:47px;border-radius:15px;background:var(--soft);color:var(--acc);display:flex;align-items:center;justify-content:center;margin-bottom:15px}
    .hm-ic svg{width:24px;height:24px;stroke-width:2}
    .hm-card h3{margin:0;position:relative;z-index:1;font-family:'Poppins',sans-serif;font-weight:600;font-size:16.5px;letter-spacing:-.02em}
    .hm-card p{margin:6px 0 0;position:relative;z-index:1;color:var(--muted,#7b7880);font-size:13.5px;line-height:1.35;padding-right:36px;padding-bottom:6px}
    .hm-arw{position:absolute;right:14px;bottom:14px;z-index:2;width:31px;height:31px;border-radius:50%;background:#fff;color:var(--acc);
      display:flex;align-items:center;justify-content:center;box-shadow:0 4px 13px rgba(40,35,45,.10);transition:transform .2s,background .2s,color .2s}
    .hm-arw svg{width:16px;height:16px}
    .hm-card:hover .hm-arw{transform:translateX(3px);background:var(--acc);color:#fff}
    .hm-wide{display:flex;align-items:center;gap:14px;margin-top:14px;background:#fff;border:1px solid #ece8e3;border-radius:22px;padding:15px;
      text-decoration:none;color:inherit;box-shadow:0 8px 24px rgba(38,32,45,.05);transition:transform .2s,box-shadow .2s}
    .hm-wide:hover{transform:translateY(-2px);box-shadow:0 16px 36px rgba(38,32,45,.09)}
    .hm-wic{width:52px;height:52px;flex-shrink:0;border-radius:16px;background:#eafaf8;color:#00a49f;display:flex;align-items:center;justify-content:center}
    .hm-wic svg{width:26px;height:26px}
    .hm-wc{flex:1;min-width:0}
    .hm-wc strong{display:block;font-family:'Poppins',sans-serif;font-weight:600;font-size:16px;letter-spacing:-.02em}
    .hm-wc span{display:block;margin-top:3px;color:var(--muted,#7b7880);font-size:13px}
    .hm-wchev{color:#b7b2ba}.hm-wchev svg{width:19px;height:19px}
  </style>
  <?php endif; ?>
</main>
<?php endif; /* fin Home viejo (deck/launcher) desactivado */ ?>

<script>
(function () {
  var CSRF = <?= json_encode(csrf_token()) ?>, MARCA = <?= (int)$marca_id ?>, BASE = <?= json_encode($BASE) ?>;
  var deck = document.getElementById('deck'); if (!deck) return;
  var cards = [].slice.call(deck.querySelectorAll('.tcard'));
  var dots  = [].slice.call(document.querySelectorAll('#tnDots .tn-dot'));
  var end   = document.getElementById('tnEnd');
  var idx = 0, busy = false;

  function paint() {
    cards.forEach(function (c, i) {
      c.className = 'tcard ' + (i < idx ? 'is-gone' : i === idx ? 'is-active' : i === idx + 1 ? 'is-next' : 'is-hidden');
      c.style.transform = ''; c.style.opacity = '';
    });
    dots.forEach(function (d, i) { d.classList.toggle('on', i === idx); });
    if (idx >= cards.length && end) end.classList.add('show');
  }
  function advance() { idx++; paint(); }

  function act(card, accion) {
    if (busy) return; busy = true;
    var id = card.getAttribute('data-id');
    card.classList.add(accion === 'aprobar' ? 'fly-up' : 'fly-down');
    var fd = new FormData(); fd.append('csrf', CSRF); fd.append('accion', accion); fd.append('id', id); fd.append('ajax', '1');
    fetch(BASE + '/aprobar2.php?marca=' + MARCA, { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.ok) { setTimeout(function () { busy = false; advance(); }, 300); }
        else { busy = false; card.classList.remove('fly-up', 'fly-down'); alert((d && d.err) || T.no_pudo); }
      })
      .catch(function () { busy = false; card.classList.remove('fly-up', 'fly-down'); alert(T.sin_conexion); });
  }

  deck.addEventListener('click', function (e) {
    var b = e.target.closest('[data-act]'); if (!b) return;
    var card = b.closest('.tcard'); if (!card || !card.classList.contains('is-active')) return;
    act(card, b.getAttribute('data-act'));
  });

  // swipe horizontal → siguiente / anterior (mirar sin decidir)
  var sx = 0, dragging = false, active = null;
  deck.addEventListener('pointerdown', function (e) {
    if (e.target.closest('[data-act],a,button')) return;
    active = cards[idx]; if (!active) return;
    dragging = true; sx = e.clientX; active.style.transition = 'none';
    try { active.setPointerCapture(e.pointerId); } catch (x) {}
  });
  deck.addEventListener('pointermove', function (e) {
    if (!dragging || !active) return;
    var dx = e.clientX - sx;
    active.style.transform = 'translateX(' + dx + 'px) rotate(' + (dx * 0.025) + 'deg)';
    active.style.opacity = String(Math.max(.35, 1 - Math.abs(dx) / 520));
  });
  function endDrag(e) {
    if (!dragging || !active) return; dragging = false;
    var dx = ((e.clientX || sx) - sx); active.style.transition = '';
    if (dx < -70 && idx < cards.length - 1) { active.style.transform = 'translateX(-125%) rotate(-7deg)'; active.style.opacity = '0'; setTimeout(advance, 170); }
    else if (dx > 70 && idx > 0) { idx--; paint(); }
    else { active.style.transform = ''; active.style.opacity = ''; }
    active = null;
  }
  deck.addEventListener('pointerup', endDrag);
  deck.addEventListener('pointercancel', endDrag);

  requestAnimationFrame(paint);
})();
</script>

<!-- ── HOJA DE EDICIÓN INLINE (Opción A): editar el post SIN salir del home ── -->
<div class="edsheet-back" id="edBack" aria-hidden="true">
  <div class="edsheet" role="dialog" aria-modal="true" aria-label="Editar el texto del post">
    <div class="edsheet-grip"></div>
    <div class="edsheet-h">El texto de tu post</div>
    <textarea id="edText" rows="6" placeholder="El texto del post…"></textarea>
    <div class="edsheet-note">Corrige lo que quieras — la IA aprende tu voz para los próximos.</div>
    <button type="button" class="edsheet-regen" id="edRegen"><?= ico('refresh') ?> Que la IA lo reescriba</button>
    <div class="edsheet-row">
      <button type="button" class="edsheet-cancel" id="edCancel">Cancelar</button>
      <button type="button" class="edsheet-save" id="edSave">Guardar</button>
    </div>
  </div>
</div>
<style>
  .edsheet-back{position:fixed;inset:0;z-index:200;background:rgba(20,12,20,.5);display:none;align-items:flex-end;justify-content:center}
  .edsheet-back.on{display:flex}
  .edsheet{background:var(--card,#fff);width:100%;max-width:520px;border-radius:22px 22px 0 0;padding:10px 20px calc(20px + env(safe-area-inset-bottom));box-shadow:0 -20px 60px -20px rgba(20,12,20,.5);transform:translateY(100%);transition:transform .28s cubic-bezier(.16,1,.3,1)}
  .edsheet-back.on .edsheet{transform:none}
  @media(min-width:560px){.edsheet-back{align-items:center}.edsheet{border-radius:22px;transform:translateY(14px) scale(.98)}.edsheet-back.on .edsheet{transform:none}}
  .edsheet-grip{width:38px;height:4px;border-radius:99px;background:var(--line);margin:2px auto 12px}
  .edsheet-h{font-family:'Oswald',sans-serif;font-weight:700;font-size:18px;color:var(--tinta);margin-bottom:10px}
  .edsheet textarea{width:100%;font-family:inherit;font-size:15px;line-height:1.5;color:var(--tinta);border:1.5px solid var(--line);border-radius:14px;padding:13px 14px;min-height:130px;resize:vertical;box-sizing:border-box}
  .edsheet textarea:focus{outline:0;border-color:var(--magenta,#EF4375)}
  .edsheet-note{font-size:12px;color:var(--muted);margin:8px 2px}
  .edsheet-regen{width:100%;border:1.5px solid var(--line);background:#fff;color:var(--tinta);font-family:inherit;font-weight:700;font-size:14px;padding:11px;border-radius:13px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px;margin-bottom:12px}
  .edsheet-regen svg{width:17px;height:17px}
  .edsheet-regen:disabled{opacity:.6;cursor:default}
  .edsheet-row{display:flex;gap:10px}
  .edsheet-cancel{flex:0 0 auto;border:1.5px solid var(--line);background:#fff;color:var(--muted);font-family:inherit;font-weight:700;font-size:15px;padding:14px 20px;border-radius:14px;cursor:pointer}
  .edsheet-save{flex:1;border:0;background:var(--btn-grad,linear-gradient(135deg,#FF6B3D,#EF4375));color:#fff;font-family:inherit;font-weight:800;font-size:15px;padding:14px;border-radius:14px;cursor:pointer}
  .edsheet-save:disabled{opacity:.6;cursor:default}
</style>
<script>
(function(){
  var CSRF=<?= json_encode(csrf_token()) ?>, MARCA=<?= (int)$marca_id ?>, BASE=<?= json_encode($BASE) ?>;
  var back=document.getElementById('edBack'), ta=document.getElementById('edText'),
      save=document.getElementById('edSave'), cancel=document.getElementById('edCancel'), regen=document.getElementById('edRegen');
  var card=null;
  function capEl(c){ return c ? c.querySelector('.tcard-cap') : null; }
  function abrir(c){ card=c; var el=capEl(c); ta.value=(el && el.dataset.full)? el.dataset.full : (el? el.textContent.trim():''); back.classList.add('on'); setTimeout(function(){ta.focus();},250); }
  function cerrar(){ back.classList.remove('on'); card=null; }
  // "Ajustar" en el deck abre la hoja (sin salir de la página)
  var deck=document.getElementById('deck');
  if(deck) deck.addEventListener('click', function(e){
    var b=e.target.closest('[data-edit]'); if(!b) return;
    e.preventDefault(); e.stopPropagation();
    abrir(b.closest('.tcard'));
  });
  cancel.addEventListener('click', cerrar);
  back.addEventListener('click', function(e){ if(e.target===back) cerrar(); });

  function post(accion, extra){
    var fd=new FormData(); fd.append('csrf',CSRF); fd.append('accion',accion); fd.append('id', card.getAttribute('data-id')); fd.append('ajax','1');
    for(var k in (extra||{})) fd.append(k, extra[k]);
    return fetch(BASE+'/aprobar2.php?marca='+MARCA,{method:'POST',body:fd}).then(function(r){return r.json();});
  }
  save.addEventListener('click', function(){
    if(!card) return; var txt=ta.value.trim(); if(!txt){ return; }
    save.disabled=true; save.textContent='Guardando…';
    post('editar',{caption:txt}).then(function(d){
      save.disabled=false; save.textContent=T.guardar;
      if(d && d.ok){ var el=capEl(card); if(el){ el.textContent=d.caption||txt; el.dataset.full=d.caption||txt; } cerrar(); }
      else { alert((d&&d.err)||T.no_guardo); }
    }).catch(function(){ save.disabled=false; save.textContent=T.guardar; alert(T.cayo); });
  });
  regen.addEventListener('click', function(){
    if(!card) return; regen.disabled=true; var old=regen.innerHTML; regen.textContent=T.reescribiendo;
    post('regenerar',{}).then(function(d){
      regen.disabled=false; regen.innerHTML=old;
      if(d && d.ok && d.caption){ ta.value=d.caption; }
      else if(d && d.paywall){ alert(T.regen_plan); }
      else { alert(T.no_reescribio); }
    }).catch(function(){ regen.disabled=false; regen.innerHTML=old; alert(T.cayo); });
  });
})();
</script>

<script>
/* EL NORTE, vivo. Dos gestos chiquitos que hacen que el número se sienta ganado
   en vez de impreso: cuenta hacia arriba y la barra se llena al entrar.
   Respeta prefers-reduced-motion: si el usuario pidió menos movimiento, los
   valores aparecen finales de una vez. */
(function(){
  var quieto = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  var num = document.querySelector('.norte .n-num b[data-cuenta]');
  var bar = document.querySelector('.norte .n-barra i[data-ancho]');

  if (bar) {
    var w = Math.max(2, Math.min(100, parseInt(bar.dataset.ancho, 10) || 0));
    if (quieto) { bar.style.width = w + '%'; }
    else { requestAnimationFrame(function(){ setTimeout(function(){ bar.style.width = w + '%'; }, 180); }); }
  }
  if (num) {
    var fin = parseInt(num.dataset.cuenta, 10) || 0;
    if (quieto || fin === 0) { num.textContent = fin.toLocaleString('es-PR'); return; }
    var dur = Math.min(900, 260 + fin * 22), t0 = null;
    function paso(ts){
      if (t0 === null) t0 = ts;
      var p = Math.min(1, (ts - t0) / dur);
      // easing suave al final: el número "aterriza" en vez de frenar en seco
      var v = Math.round(fin * (1 - Math.pow(1 - p, 3)));
      num.textContent = v.toLocaleString('es-PR');
      if (p < 1) requestAnimationFrame(paso);
    }
    requestAnimationFrame(paso);
  }
})();
</script>
<?php
// EL RECIBIMIENTO — la primera vez que esta cuenta entra a esta pantalla.
// Su JS espera al 'load', así el botón Ayuda ya existe cuando lo ilumine.
require_once __DIR__ . '/../includes/tour.php';
// El tour cambia según lo que el dueño tenga delante: si ya hay meta, la explica;
// si no, le dice por qué conviene ponerla.
tour_montar($pdo, $marca_id, 'inicio', [
    'hay_post' => !empty($hz_post),
    'hay_meta' => $__meta_activa,
]);
require __DIR__ . '/_shell_foot.php'; ?>
