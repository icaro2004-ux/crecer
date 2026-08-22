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

// ── AJAX: Idea del día (async; el front la pide al cargar) ──
// Recoge imágenes que terminaron en background + notifica (el worker muere en Hostinger).
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try { require_once __DIR__ . '/../includes/img_responses.php'; img_sweep_pendientes($pdo, $marca_id); } catch (Throwable $e) {}
    try { require_once __DIR__ . '/../includes/carrusel.php'; if (function_exists('carrusel_sweep_pendientes')) carrusel_sweep_pendientes($pdo, $marca_id); } catch (Throwable $e) {}
    try { require_once __DIR__ . '/../includes/analista.php'; analista_vigilar($pdo, $marca_id); } catch (Throwable $e) {}   // ADR-0004: el Analista vigila los KPIs y detecta señales
}

// Marcar una señal del Analista (aceptada al ir a la acción · descartada al "Ahora no").
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'analista_marcar') {
    require_once __DIR__ . '/../includes/analista.php';
    header('Content-Type: application/json; charset=utf-8');
    if (function_exists('csrf_ok') && !csrf_ok()) { echo json_encode(['ok'=>false]); exit; }
    analista_marcar($pdo, (int)($_POST['id'] ?? 0), $marca_id, (string)($_POST['estado'] ?? ''));
    echo json_encode(['ok'=>true]); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'idea') {
    require_once __DIR__ . '/../includes/agentes.php';
    header('Content-Type: application/json; charset=utf-8');
    if (function_exists('csrf_ok') && !csrf_ok()) { echo json_encode(['ok'=>false]); exit; }
    try { echo json_encode(['ok'=>true, 'idea'=>idea_del_dia($pdo, $marca_id)], JSON_UNESCAPED_UNICODE); }
    catch (Throwable $e) { echo json_encode(['ok'=>false]); }
    exit;
}

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
$hz_status = $hz_pend > 0 ? "Tienes {$hz_pend} post" . ($hz_pend==1?'':'s') . " esperando tu OK" : 'Todo listo para hoy';

// Semana actual (Lun→Dom) con lo que hay cada día
$hz_week = [];
try {
    // Lo rechazado NO cuenta en la semana: dijiste que no, no va a salir.
    $ws = $pdo->prepare("SELECT DAYOFWEEK(fecha_programada) dw, plataforma
                         FROM crecer_contenido
                         WHERE marca_id=? AND estado<>'rechazado' AND fecha_programada IS NOT NULL
                           AND YEARWEEK(fecha_programada,3)=YEARWEEK(CURDATE(),3)");
    $ws->execute([$marca_id]);
    foreach ($ws->fetchAll(PDO::FETCH_ASSOC) as $r) { $hz_week[(int)$r['dw']][] = $r; }
} catch (Throwable $e) {}
$hz_mon = strtotime('monday this week'); $hz_lbl = ['LUN','MAR','MIÉ','JUE','VIE','SÁB','DOM']; $hz_dias = [];
for ($i=0;$i<7;$i++){ $ts=$hz_mon + $i*86400; $dw=(int)date('w',$ts)+1;
    $hz_dias[] = ['lbl'=>$hz_lbl[$i],'num'=>date('j',$ts),'hoy'=>date('Y-m-d',$ts)===date('Y-m-d'),'items'=>$hz_week[$dw] ?? []]; }

// Rendimiento: actividad de publicación (8 semanas) para el sparkline
$hz_serie = [];
try {
    $sq = $pdo->prepare("SELECT YEARWEEK(publicado_at,3) wk, COUNT(*) n FROM crecer_contenido
                         WHERE marca_id=? AND estado='publicado' AND publicado_at>=(NOW()-INTERVAL 8 WEEK) GROUP BY wk");
    $sq->execute([$marca_id]); $wm=[];
    foreach ($sq->fetchAll(PDO::FETCH_ASSOC) as $r) $wm[(int)$r['wk']]=(int)$r['n'];
    for($i=7;$i>=0;$i--){ $ts=time()-$i*7*86400; $hz_serie[]=($wm[(int)date('oW',$ts)] ?? 0); }
} catch (Throwable $e) { $hz_serie=[0,0,0,0,0,0,0,0]; }
$hz_hay_serie = array_sum($hz_serie) > 0;
$hz_creciendo = $racha >= 2 || ($hz_hay_serie && end($hz_serie) >= reset($hz_serie));

require_once __DIR__ . '/../includes/analista.php';  // ADR-0004: señal proactiva para la tarjeta del Analista
$an_top     = analista_senal_top($pdo, $marca_id);
$an_nombre  = analista_nombre($marca);
$an_racha   = $racha ?? 0;
require_once __DIR__ . '/../includes/agentes.php';   // para crecer_sin_emoji()
$_noemo = function_exists('crecer_sin_emoji') ? 'crecer_sin_emoji' : function ($x) { return $x; };
// Tip del Analista (lee el CACHE de Resultados — no llama a Gemini)
$hz_analista = null;
try { $j = json_decode((string)$pdo->query("SELECT datos FROM crecer_analisis_kpi WHERE marca_id={$marca_id}")->fetchColumn(), true);
  if (is_array($j) && !empty($j['resumen']) && is_array($j['resumen'])) $hz_analista = $_noemo($j['resumen']); } catch (Throwable $e) {}
// Tip financiero (lee el CACHE de Finanzas)
$hz_fin = null;
try { $j = json_decode((string)$pdo->query("SELECT datos FROM crecer_finanzas_consejos WHERE marca_id={$marca_id}")->fetchColumn(), true);
  if (is_array($j) && !empty($j['consejo_mes'])) $hz_fin = $_noemo((string)$j['consejo_mes']); } catch (Throwable $e) {}
// Notificaciones recientes
require_once __DIR__ . '/../includes/notif.php';
$hz_notifs = function_exists('notif_listar') ? array_slice(notif_listar($pdo, $marca_id, 3), 0, 3) : [];
// Forecast: proyección de posts del mes al ritmo actual
$hz_mespub  = (int)($prod['publicados_mes'] ?? 0);
$hz_diames  = (int)date('j'); $hz_totdias = (int)date('t');
$hz_proj    = ($hz_diames > 0 && $hz_mespub > 0) ? (int)round($hz_mespub / $hz_diames * $hz_totdias) : 0;

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
  .hz-eyebrow{color:var(--muted);font-weight:700;font-size:13px}
  .hz-hello{font-family:var(--font-display);font-weight:800;font-size:clamp(28px,7.5vw,36px);letter-spacing:-.03em;color:var(--tinta);line-height:1.05;margin-top:4px}
  .hz-status{display:inline-flex;align-items:center;gap:7px;margin-top:11px;color:var(--teal-700,#00827e);font-weight:700;font-size:13.5px;background:color-mix(in srgb,var(--teal) 10%,#fff);border:1px solid color-mix(in srgb,var(--teal) 22%,#fff);border-radius:999px;padding:5px 13px}
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
  #hzIdea.go{cursor:pointer;transition:transform .15s ease,box-shadow .2s ease}
  #hzIdea.go:hover{transform:translateY(-2px);box-shadow:0 16px 34px -18px rgba(0,0,0,.28)}
  #hzIdea.go:active{transform:translateY(0)}
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
        <b><?= $h($__hm['titulo']) ?></b>
        <?php if ($__hm['objeto']['titulo'] !== ''): ?>
          <p><?= $h($__hm['objeto']['titulo']) ?></p>
        <?php endif; ?>
      </div>

      <span class="n-ir"><?= $h($__hm['accion']['etiqueta'] !== '' ? $__hm['accion']['etiqueta'] : 'Ver el plan completo') ?> <i>→</i></span>
    </a>
  <?php endif; ?>
  <?php if ($hz_post):
    $hz_g = (string)($hz_post['grafica_path'] ?? '');
    $hz_vid = $hz_g !== '' && preg_match('#\.(mp4|mov|m4v)$#i', $hz_g);
    $hz_cap = trim((string)($hz_post['caption'] ?? ''));
  ?>
  <?php
  // ¿Esta pieza nació de una jugada del plan? Entonces se dice, porque cambia
  // el sentido de aprobarla: no es "un post más", es un paso hacia SU número.
  $hz_de_meta = '';
  if (!empty($hz_post['tactica_id']) && $__meta_activa && $__meta) {
      try {
          $qd = $pdo->prepare("SELECT titulo FROM crecer_meta_tactica WHERE id=? AND marca_id=?");
          $qd->execute([(int)$hz_post['tactica_id'], $marca_id]);
          $tt = (string)($qd->fetchColumn() ?: '');
          if ($tt !== '') $hz_de_meta = $tt;
      } catch (Throwable $e) {}
  }
  ?>
  <section class="hz-card">
    <div class="hz-next">
      <div class="l">
        <span class="eb">Próximo post<?php if ($hz_de_meta !== ''): ?><i class="eb-meta"> · de tu meta</i><?php endif; ?></span>
        <?php if ($hz_de_meta !== ''): ?>
          <span class="hz-dejugada"><?= ico('compass') ?> <?= $h($hz_de_meta) ?></span>
        <?php endif; ?>
        <p class="cap"><?= $h($hz_cap !== '' ? $hz_cap : 'Tu próximo post — el corillo lo está afinando.') ?></p>
        <?php if ($hz_post['modo']==='aprobar'): ?>
          <a class="hz-approve" href="<?= $BASE ?>/propuestas.php?<?= $mid ?>"><?= ico('check') ?> Aprobar post</a>
        <?php elseif ($hz_post['modo']==='programado'): ?>
          <div class="hz-when">Sale <?= $h(_fecha_humana($hz_post['fecha_programada'] ?? '')) ?><a href="<?= $BASE ?>/calendario.php?<?= $mid ?>">Ver →</a></div>
        <?php else: ?>
          <div class="hz-when">Listo para publicar<a href="<?= $BASE ?>/aprobar2.php?marca=<?= $marca_id ?>&tab=listos">Ver →</a></div>
        <?php endif; ?>
      </div>
      <div class="im">
        <?php if ($hz_vid): ?><video src="<?= $h($hz_g) ?>" muted playsinline></video>
        <?php elseif ($hz_g !== ''): ?><img src="<?= $h($hz_g) ?>" alt="">
        <?php else: ?><?= ico('image') ?><?php endif; ?>
      </div>
    </div>
  </section>
  <?php else: ?>
  <section class="hz-card"><p class="hz-empty">Todo al día. El corillo está preparando lo próximo — te aviso cuando haya algo para tu OK.</p></section>
  <?php endif; ?>

  <?php
  // ══════════════════════════════════════════════════════════════════════
  //  CÓMO VAMOS — UNA SOLA VOZ (2026-08-12)
  //
  //  Antes esto eran CUATRO tarjetas seguidas contestando la misma pregunta
  //  con voces distintas: el Analista proactivo, "Cómo vas", "Rendimiento" y
  //  "Proyección del mes". El dueño no leía un equipo poniéndolo al día:
  //  leía un tablero de aeropuerto.
  //
  //  Ahora el Analista habla UNA vez, y sus números (la gráfica, el ritmo del
  //  mes) viven dentro de su tarjeta como respaldo de lo que dice — no como
  //  bloques sueltos compitiendo por atención.
  // ══════════════════════════════════════════════════════════════════════
  ?>
  <section class="hz-card an-card">
    <div class="an-head">
      <span class="an-av"><?= ico('chart') ?></span>
      <span class="an-who"><b><?= $h($an_nombre) ?></b><span class="live"><span class="an-dot"></span> Vigilando tus números</span></span>
      <?php if ($hz_creciendo): ?><span class="hz-up" style="margin-left:auto"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17l6-6 4 4 8-8"/></svg> En crecimiento</span><?php endif; ?>
    </div>

    <?php if ($an_top): /* tiene algo que decir y qué hacer al respecto */ ?>
      <p class="an-title"><?= $h($an_top['titulo']) ?></p>
      <p class="an-msg"><?= $h($an_top['mensaje']) ?></p>
      <div class="an-acts" id="anActs" data-id="<?= (int)$an_top['id'] ?>">
        <a class="an-go" id="anGo" href="<?= $h($an_top['accion_url']) ?>"><?= $h($an_top['accion_label']) ?> &rarr;</a>
        <button class="an-skip" id="anSkip" type="button">Ahora no</button>
      </div>
    <?php elseif ($hz_analista && (!empty($hz_analista['lectura']) || !empty($hz_analista['reco']))): ?>
      <?php /* su lectura de la semana (lo que antes era la tarjeta "Cómo vas") */ ?>
      <p class="an-msg"><?= $h($hz_analista['lectura'] ?? $hz_analista['reco']) ?></p>
      <?php if (!empty($hz_analista['reco']) && !empty($hz_analista['lectura'])): ?>
        <div class="hz-reco"><?= ico('bolt') ?><span><?= $h($hz_analista['reco']) ?></span></div>
      <?php endif; ?>
    <?php elseif ($__meta_activa && $__prog):
      /* HABLA CONTRA LA META, NO AL AIRE. Decir "no cambiaría nada, sigue así"
         mientras arriba se lee "0 de 25 pedidos" es lo que hacía sentir el Home
         como piezas sueltas. Todo lo de abajo sale de $__prog, que ya está
         medido — nada inventado. */
      $__falta = $__prog['falta'] ?? null;
      $__dias  = $__prog['dias_rest'] ?? null;
      $__unid  = meta_objetivo_def((string)$__meta['objetivo'])['unidad'];
      /*  EL MISMO CONTRATO QUE LA TARJETA. Este bloque afirmaba «vamos cortos»
          y «llevas X» sin preguntar si se puede afirmar. Con la tarjeta ya
          honesta, dejarlo asi era mover la contradiccion una seccion mas abajo:
          arriba «25 pedidos» sin barra, y aqui «llevas 0, vamos cortos». La
          respuesta sale del MISMO booleano que gobierna la tarjeta.  */
      $__afirmar = $__hm && $__hm['puede'];
    ?>
      <?php if (!$__prog['medible']): ?>
        <div class="an-ok"><?= ico('check-circle') ?> Estoy pendiente de tu meta.</div>
        <p class="an-msg an-sub">Este objetivo todavía no lo puedo medir solo, así que te aviso por lo que sí veo:
           cuánta gente alcanzan tus posts y cuántos te escriben.</p>
      <?php elseif ($__prog['actual'] === null || (float)$__prog['actual'] <= 0): ?>
        <?php /* `actual` en null = todavía no hay señal (Meta aún no reporta).
                 Antes caía al último caso y decía "Vamos en ritmo. Llevas 0
                 personas" debajo de un card que correctamente no enseña número. */ ?>
        <div class="an-ok"><?= ico('clock') ?> Todavía no hay nada que medir de tu meta.</div>
        <p class="an-msg an-sub">Cuando salgan los primeros posts del plan y la gente empiece a moverse,
           aquí te digo qué está funcionando y qué hay que cambiar.</p>
      <?php elseif ($__afirmar && $__prog['al_dia'] === false): ?>
        <div class="an-ok" style="color:#b4232b"><?= ico('bolt') ?> Vamos cortos para la meta.</div>
        <p class="an-msg an-sub">Llevas <?= $h(meta_fmt((float)$__prog['actual'], (string)$__meta['objetivo'])) ?><?php
          if ($__falta !== null && $__dias !== null && $__dias > 0): ?> y faltan
          <?= $h(meta_fmt((float)$__falta, (string)$__meta['objetivo'])) ?> en <?= (int)$__dias ?> días<?php endif; ?>.
          Estoy mirando qué formatos y horarios te rinden más para apretar por ahí.</p>
      <?php elseif ($__afirmar && $__prog['al_dia'] === true): ?>
        <div class="an-ok"><?= ico('check-circle') ?> Vamos en ritmo para tu meta.</div>
        <p class="an-msg an-sub">Llevas <?= $h(meta_fmt((float)$__prog['actual'], (string)$__meta['objetivo'])) ?>
           <?php if ($__dias !== null && $__dias > 0): ?>con <?= (int)$__dias ?> días por delante<?php endif; ?>.
           Sigo pendiente de tu alcance y tus horarios por si aparece una oportunidad.</p>
      <?php elseif ($__afirmar): ?>
        <?php /* Hay avance pero todavía no se puede juzgar el ritmo (meta recién
                 puesta, o sin fecha límite): se dice lo que hay y nada más. */ ?>
        <div class="an-ok"><?= ico('check-circle') ?> Ya se está moviendo.</div>
        <p class="an-msg an-sub">Llevas <?= $h(meta_fmt((float)$__prog['actual'], (string)$__meta['objetivo'])) ?>.
           Déjame unos días más de datos y te digo si vamos al ritmo que hace falta.</p>
      <?php else: ?>
        <?php /* SIN COBERTURA COMPLETA no se dice cuánto lleva ni si va bien:
                 Crecer solo cuenta lo que pasa por dentro, y presentarlo como
                 el total del negocio seria inventarselo. Se dice lo que SI se
                 sabe.  */ ?>
        <div class="an-ok"><?= ico('check-circle') ?> Estoy pendiente de tu meta.</div>
        <p class="an-msg an-sub">Solo cuento lo que pasa por Crecer, así que el número de tu meta
           lo llevas tú. Lo que sí te puedo decir es a cuánta gente llegan tus posts y cuántos te escriben.</p>
      <?php endif; ?>
    <?php else: ?>
      <div class="an-ok"><?= ico('check-circle') ?> No cambiaría nada esta semana. Sigue así.</div>
      <p class="an-msg an-sub">Estoy pendiente de tu alcance, tus formatos y tus horarios. Apenas vea una oportunidad, te la traigo aquí.</p>
    <?php endif; ?>

    <?php if ($hz_hay_serie):
      $n=count($hz_serie); $mx=max(1,max($hz_serie)); $pts=[];
      foreach($hz_serie as $i=>$v){ $x=$n>1?round(($i/($n-1))*300,1):0; $y=round(64-($v/$mx)*54,1); $pts[]="$x,$y"; }
      $poly=implode(' ',$pts); $lastp=explode(',',end($pts));
    ?>
      <?php /* los números que respaldan lo que acaba de decir, no un bloque aparte */ ?>
      <svg class="hz-spark" viewBox="0 0 300 72" preserveAspectRatio="none" style="margin-top:14px">
        <defs><linearGradient id="hzg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="var(--teal)" stop-opacity=".28"/><stop offset="1" stop-color="var(--teal)" stop-opacity="0"/></linearGradient></defs>
        <polygon points="0,72 <?= $poly ?> 300,72" fill="url(#hzg)"/>
        <polyline points="<?= $poly ?>" fill="none" stroke="var(--teal)" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"/>
        <circle cx="<?= $lastp[0] ?>" cy="<?= $lastp[1] ?>" r="4.5" fill="var(--teal)"/>
      </svg>
      <div class="hz-cap">Posts publicados por semana · últimas 8<?= $hay_insights ? '' : ' · conecta tus redes para ver alcance' ?><?php
        // La proyección deja de ser una tarjeta propia: es una frase más de su lectura.
        if ($hz_proj > 0) echo ' · a este ritmo cierras el mes con ' . (int)$hz_proj . ' posts';
      ?></div>
    <?php endif; ?>

    <a class="an-ver" href="<?= $BASE ?>/resultados.php?<?= $mid ?>">Ver todos los resultados →</a>
  </section>
  <script>
  (function(){
    var acts=document.getElementById('anActs'); if(!acts) return;
    var id=acts.getAttribute('data-id'), CSRF=<?= json_encode(csrf_token()) ?>;
    function mark(estado){ var fd=new FormData(); fd.append('accion','analista_marcar'); fd.append('id',id); fd.append('estado',estado); fd.append('csrf',CSRF); return fetch(location.pathname+location.search,{method:'POST',body:fd}).catch(function(){}); }
    var go=document.getElementById('anGo');
    if(go) go.addEventListener('click',function(e){ e.preventDefault(); var href=go.getAttribute('href'); mark('aceptada').finally(function(){ location.href=href; }); });
    var skip=document.getElementById('anSkip');
    if(skip) skip.addEventListener('click',function(){ mark('descartada'); var card=acts.closest('.an-card'); if(card) card.style.display='none'; });
  })();
  </script>

  <section class="hz-card">
    <div class="hz-ch"><b>Calendario</b><a href="<?= $BASE ?>/calendario.php?<?= $mid ?>">Ver todo →</a></div>
    <div class="hz-week">
      <?php foreach ($hz_dias as $d): ?>
      <div class="hz-day<?= $d['hoy']?' on':'' ?>">
        <span class="d"><?= $d['lbl'] ?></span>
        <span class="n"><?= $d['num'] ?></span>
        <span class="dots"><?php foreach (array_slice($d['items'],0,3) as $it){ $pl=$it['plataforma']??''; $col=$pl==='facebook'?'var(--teal)':($pl==='instagram'?'var(--magenta)':'var(--amber,#c78a16)'); echo '<i style="background:'.($d['hoy']?'#fff':$col).'"></i>'; } ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </section>

  <?php
  // ── Lo que se fue de aquí y por qué (2026-08-12) ──
  //  · "Rendimiento", "Cómo vas" y "Proyección del mes" → ahora viven DENTRO de
  //    la tarjeta del Analista, arriba. Eran cuatro voces para una sola pregunta.
  //  · "Lo último" → la campanita del top-bar ya trae las notificaciones con su
  //    contador. Enseñarlas dos veces no las hace más visibles, hace más ruido.
  //  · "Tip de finanzas" → vive en Finanzas, que está en el menú. Este es el
  //    Home de marketing; mezclar dominios es lo que hacía sentir el Home como
  //    cosas sueltas sin relación.
  //  Nada se borró del sistema: solo dejaron de competir en esta pantalla.
  ?>

  <section class="hz-card" id="hzIdea">
    <div class="hz-ch"><b>Idea del día</b></div>
    <div class="hz-tip"><span class="ic pur"><?= ico('sparkles') ?></span><p id="hzIdeaTxt" style="color:var(--muted);font-style:italic">El corillo está pensando una idea para hoy…</p></div>
    <a id="hzIdeaGo" class="hz-idea-go" hidden href="#"><?= ico('plus') ?> Crear este post</a>
  </section>
</main>

<script>
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
  // ── Idea del día (async) ──
  var it=document.getElementById('hzIdeaTxt');
  if(it){
    var fdi=new FormData(); fdi.append('accion','idea'); fdi.append('csrf',<?= json_encode(csrf_token()) ?>);
    fetch(location.pathname+location.search,{method:'POST',body:fdi}).then(function(r){return r.json();}).then(function(d){
      if(d&&d.ok&&d.idea){
        it.textContent=d.idea; it.style.color=''; it.style.fontStyle='';
        // La idea YA está generada: tocar el card (o el botón) va directo a crearla.
        <?php // CREAR unificado (flag): la Idea del día abre el wizard en El Estudio (una sola superficie) ?>
        var url=<?= json_encode($BASE.'/'.((defined('CRECER_CREAR_UNIFICADO') && CRECER_CREAR_UNIFICADO) ? 'propuestas.php' : 'aprobar2.php').'?marca='.(int)$marca_id.'&crear=1&idea=') ?>+encodeURIComponent(d.idea);
        var go=document.getElementById('hzIdeaGo');
        if(go){ go.href=url; go.hidden=false; }
        var card=document.getElementById('hzIdea');
        if(card){ card.classList.add('go'); card.addEventListener('click', function(e){ if(e.target.closest('#hzIdeaGo')) return; location.href=url; }); }
      }
      else { var c=document.getElementById('hzIdea'); if(c)c.remove(); }
    }).catch(function(){ var c=document.getElementById('hzIdea'); if(c)c.remove(); });
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
        else { busy = false; card.classList.remove('fly-up', 'fly-down'); alert((d && d.err) || 'No se pudo. Intenta otra vez.'); }
      })
      .catch(function () { busy = false; card.classList.remove('fly-up', 'fly-down'); alert('Se cayó la conexión. Intenta otra vez.'); });
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
      save.disabled=false; save.textContent='Guardar';
      if(d && d.ok){ var el=capEl(card); if(el){ el.textContent=d.caption||txt; el.dataset.full=d.caption||txt; } cerrar(); }
      else { alert((d&&d.err)||'No se pudo guardar.'); }
    }).catch(function(){ save.disabled=false; save.textContent='Guardar'; alert('Se cayó la conexión.'); });
  });
  regen.addEventListener('click', function(){
    if(!card) return; regen.disabled=true; var old=regen.innerHTML; regen.textContent='La IA está reescribiendo…';
    post('regenerar',{}).then(function(d){
      regen.disabled=false; regen.innerHTML=old;
      if(d && d.ok && d.caption){ ta.value=d.caption; }
      else if(d && d.paywall){ alert('Regenerar es parte del plan. Actívalo para que la IA reescriba.'); }
      else { alert('No se pudo reescribir. Intenta otra vez.'); }
    }).catch(function(){ regen.disabled=false; regen.innerHTML=old; alert('Se cayó la conexión.'); });
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
