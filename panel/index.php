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
$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }
$marca_id = (int)$marca['id'];

$BASE = '/crecer/panel';
$mid  = "marca={$marca_id}";

// Hechos verificables: contenido por estado.
$cuenta = ['borrador'=>0,'aprobado'=>0,'programado'=>0,'publicando'=>0,'publicado'=>0,'fallido'=>0,'rechazado'=>0];
$cq = $pdo->prepare("SELECT estado, COUNT(*) n FROM crecer_contenido WHERE marca_id=? GROUP BY estado");
$cq->execute([$marca_id]);
foreach ($cq->fetchAll() as $r) {
    if (isset($cuenta[$r['estado']])) $cuenta[$r['estado']] = (int)$r['n'];
}

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
        "SELECT id, estado, caption, plataforma, tipo, fecha_programada, grafica_path
         FROM crecer_contenido
         WHERE marca_id=? AND estado IN ('fallido','borrador','aprobado','programado','publicado')
         ORDER BY FIELD(estado,'fallido','borrador','aprobado','programado','publicado'),
                  COALESCE(fecha_programada, created_at) ASC, id DESC
         LIMIT 4");
    $tq->execute([$marca_id]);
    $trabajo = $tq->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $trabajo = []; }

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
         WHERE marca_id=? AND estado IN ('fallido','borrador','aprobado')
         ORDER BY FIELD(estado,'fallido','borrador','aprobado'), id DESC
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
            'acciones'=>[['t'=>'Reintentar','cls'=>'pri','href'=>"{$BASE}/aprobar2.php?tab=aprobados&{$mid}"]]];
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
                'acciones'=>[['t'=>'Publicar','cls'=>'pri','href'=>"{$BASE}/aprobar2.php?tab=aprobados&{$mid}"]]];
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
                $decisiones = array_map(function ($d) use ($df_ico, $df_kick) {
                    $t = $d['type'] ?? 'decision';
                    $acc = [];
                    $pa = $d['primary_action'] ?? [];
                    if (!empty($pa['label'])) $acc[] = ['t'=>$pa['label'], 'cls'=>'pri', 'href'=>$pa['href'] ?? '#'];
                    foreach (($d['secondary_actions'] ?? []) as $sa) {
                        if (!empty($sa['label'])) $acc[] = ['t'=>$sa['label'], 'cls'=>'gho', 'href'=>$sa['href'] ?? '#'];
                    }
                    return [
                        'tipo'=>$t, 'id'=>(int)($d['evidence']['content_id'] ?? 0),
                        'kick'=>$df_kick[$t] ?? 'Para ti', 'ico'=>$df_ico[$t] ?? 'sparkles',
                        'titulo'=>$d['headline'] ?? 'Próxima decisión',
                        'preview'=>$d['explanation'] ?? '', 'plataforma'=>'', 'grafica'=>'',
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
  .tcard-nomedia{position:absolute;inset:0;display:grid;place-items:center;color:rgba(255,255,255,.35);
    background:linear-gradient(150deg,#2a2733,#141019)}
  .tcard-nomedia svg{width:44px;height:44px}
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
            <div class="tcard-nomedia"><?= ico('image') ?></div>
          <?php endif; ?>
        </div>
        <div class="tcard-scrim"></div>
        <?php if ($card['plat']): ?><span class="tcard-tag"><?= $h($card['plat']) ?></span><?php endif; ?>
        <div class="tcard-body">
          <p class="tcard-cap"><?= $h($card['cap'] !== '' ? $card['cap'] : 'Sin texto todavía') ?></p>
          <button type="button" class="tn-ok" data-act="aprobar">Vamos con este</button>
          <div class="tn-sub">
            <a class="tn-adj" href="<?= $BASE ?>/aprobar2.php?edit=<?= $card['id'] ?>&<?= $mid ?>#cap-<?= $card['id'] ?>">Ajustar</a>
            <button type="button" class="tn-no" data-act="rechazar">No es esto</button>
          </div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <div class="tn-end" id="tnEnd">
      <p class="tn-end-t">Al día.</p>
      <p class="tn-end-s">El corillo sigue trabajando.</p>
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

  <?php else: ?>
  <div class="tn-solo">
    <p class="tn-solo-t">Al día.</p>
    <p class="tn-solo-s">El corillo sigue trabajando. No necesitas hacer nada.</p>
  </div>
  <?php endif; ?>
</main>

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
    if (e.target.closest('[data-act],a')) return;
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

<?php require __DIR__ . '/_shell_foot.php'; ?>
