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
$guia = ['key'=>'inicio','agente'=>'sparkles','titulo'=>'El relevo del corillo',
  'intro'=>'Cada mañana, tu equipo te entrega el turno: lo que dejaron listo y tu única decisión.',
  'pasos'=>[
    ['check-circle','Las tres líneas son la prueba de que tu equipo ya trabajó.'],
    ['image','Debajo ves el post que dejaron listo. Solo falta tu OK.'],
    ['sparkles','Todo lo demás (resultados, piloto, aprendizajes) vive en "Ver todo".'],
  ]];
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
$ags = array_column($feed, 'agente');
$w_creativa  = ($fe_cap !== '') || array_intersect(['creador', 'editor', 'aprendiz'], $ags);
$w_disenador = $fe_arte || $fe_video || in_array('diseñador', $ags, true);
$w_estratega = !empty($featured['fecha_programada']) || array_intersect(['planificador', 'intake', 'estratega'], $ags);

$evidencia = [];
if ($w_creativa)  $evidencia[] = 'La Creativa terminó el post de hoy.';
if ($w_disenador) $evidencia[] = ($fe_video && !$fe_arte) ? 'El Diseñador preparó el video.' : 'El Diseñador dejó listo el arte.';
if ($w_estratega) $evidencia[] = !empty($featured['fecha_programada'])
    ? 'La Estratega escogió la mejor hora — ' . _fecha_humana($featured['fecha_programada']) . '.'
    : 'La Estratega cuadró el plan de la semana.';

// Rellenar con más pruebas REALES si faltan (nunca texto inventado).
$otras = (int)($cuenta['borrador'] ?? 0) + (int)($cuenta['aprobado'] ?? 0) + (int)($cuenta['programado'] ?? 0) - ($featured ? 1 : 0);
if (count($evidencia) < 3 && $otras > 0)
    $evidencia[] = 'El corillo adelantó ' . $otras . ' pieza' . ($otras == 1 ? '' : 's') . ' más para la semana.';
if (count($evidencia) < 3 && (int)($tot_ins['n'] ?? 0) > 0 && (int)($tot_ins['alcance'] ?? 0) > 0)
    $evidencia[] = 'El Analista revisó los números — ' . number_format((int)$tot_ins['alcance']) . ' personas alcanzadas este mes.';
if (count($evidencia) < 3 && !empty($memorias))
    $evidencia[] = 'La Estratega aprendió algo nuevo de tu voz.';
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
?>
<script>
/* ── EL PRIMER MINUTO · el relevo ─────────────────────────────────────────────
   El corillo entrega el turno UNA vez al día. Decisión SÍNCRONA antes de pintar
   → sin flash, sin actuación repetida. Sin JS, o en visitas repetidas, o con
   movimiento reducido, el contenido queda visible al instante (progresivo). */
(function () {
  try {
    var mq = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)');
    if (mq && mq.matches) return;                       // respeta reduced-motion: sin ritual
    var hoy = new Date().toLocaleDateString('en-CA', { timeZone: 'America/Puerto_Rico' });
    var k = 'relevo_' + hoy;                            // una vez por día natural (hora PR)
    if (!localStorage.getItem(k)) {
      document.documentElement.classList.add('rlv-play');
      localStorage.setItem(k, '1');
    }
  } catch (e) {}
})();
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
  /* ══ C2 · EL RELEVO DEL CORILLO ══ calma, espacio, evidencia. Hereda tokens. */
  .content{max-width:620px;margin-inline:auto}
  .rlv{max-width:560px;margin:0 auto;padding:9vh 6px 70px;font-family:'Poppins',var(--font-body)}
  @media(max-width:760px){.rlv{padding:5vh 4px 100px}}

  .rlv-top{margin-bottom:40px}
  .rlv-hero{font-family:var(--font-display);font-weight:600;font-size:clamp(27px,5.4vw,42px);
    line-height:1.15;letter-spacing:-.02em;color:var(--ink-soft);margin:0;text-wrap:balance}
  .rlv-meta{margin:14px 0 0;font-size:13px;color:var(--muted);font-weight:400;letter-spacing:.01em}

  /* Evidencia: el corazón. */
  .rlv-ev{list-style:none;margin:0 0 36px;padding:0;display:flex;flex-direction:column;gap:17px}
  .rlv-ev li{display:flex;align-items:flex-start;gap:13px;font-size:16.5px;line-height:1.4;color:var(--tinta);font-weight:400}
  .rlv-ck{flex:none;margin-top:1px;color:var(--palma);display:inline-flex}
  .rlv-ck svg{width:21px;height:21px}

  /* El trabajo, presente sin dominar. */
  .rlv-work{background:var(--card);border:1px solid var(--line);border-radius:22px;overflow:hidden;
    box-shadow:0 2px 6px rgba(40,22,28,.05), 0 26px 56px -24px rgba(40,22,28,.28);
    margin-bottom:30px;transition:box-shadow var(--dur) var(--ease),transform var(--dur) var(--ease)}
  .rlv-work:hover{box-shadow:0 6px 16px rgba(40,22,28,.07), 0 46px 88px -28px rgba(40,22,28,.36);transform:translateY(-3px)}
  .rlv-thumb{width:100%;aspect-ratio:4/3;overflow:hidden;background:var(--crema-2);
    display:grid;place-items:center;color:var(--teal)}
  .rlv-thumb img{width:100%;height:100%;object-fit:cover}
  .rlv-thumb svg{width:30px;height:30px}
  .rlv-thumb.txt{background:linear-gradient(135deg,#fff,var(--crema-2))}
  .rlv-vtag{font-size:11px;font-weight:700;color:var(--teal-700);display:flex;flex-direction:column;align-items:center;gap:5px}
  .rlv-vtag svg{width:24px;height:24px}
  .rlv-wtext{min-width:0;padding:15px 18px 17px}
  .rlv-wplat{font-size:12px;font-weight:600;color:var(--muted);text-transform:capitalize;margin-bottom:6px}
  .rlv-wcap{margin:0;font-size:14.5px;line-height:1.5;color:#3a3340}

  /* La decisión: una frase, un botón. */
  .rlv-decide{text-align:center;margin-bottom:24px}
  .rlv-frase{font-size:18px;color:var(--tinta);font-weight:400;margin:0 0 18px}
  .rlv-go{display:inline-block;border:0;cursor:pointer;font-family:'Poppins',sans-serif;font-weight:600;
    font-size:16.5px;color:#fff;text-decoration:none;padding:16px 46px;border-radius:15px;
    background:var(--btn-grad);box-shadow:var(--btn-glow);
    transition:transform var(--dur) var(--ease),box-shadow var(--dur) var(--ease)}
  .rlv-go:hover{transform:translateY(-2px);box-shadow:var(--btn-glow-hover)}
  .rlv-go:active{transform:translateY(0);box-shadow:var(--btn-glow-active)}
  .rlv-go:disabled{opacity:.65;cursor:default;transform:none;box-shadow:var(--btn-glow)}
  .rlv-alt{display:inline-block;margin-top:15px;color:var(--muted);font-size:14px;font-weight:500;text-decoration:none}
  .rlv-alt:hover{color:var(--tinta)}
  .rlv-done{font-size:18px;color:var(--palma-600);font-weight:600;text-align:center;margin:6px 0;line-height:1.4}
  .rlv-calma{font-size:16px;color:var(--muted);line-height:1.55;margin:0 0 6px}

  .rlv-firma{font-family:var(--font-display);font-size:15.5px;color:var(--muted);font-weight:400;margin:34px 0 0;font-style:italic}

  .rlv-plain{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:26px;
    box-shadow:var(--shadow-sm);text-align:center}
  .rlv-say{font-size:16px;color:var(--tinta);line-height:1.55;margin:0 0 20px}

  /* Ver todo — todo lo demás vive aquí, sin competir. */
  .rlv-more{margin-top:48px;border-top:1px solid var(--line);padding-top:22px}
  .rlv-vertodo{background:0;border:0;cursor:pointer;font-family:'Poppins',sans-serif;color:var(--muted);
    font-size:14px;font-weight:500;display:inline-flex;align-items:center;gap:8px;padding:0}
  .rlv-vertodo span{transition:transform .22s;display:inline-block}
  .rlv-vertodo.on span{transform:rotate(90deg)}
  .rlv-vertodo:hover{color:var(--tinta)}
  .rlv-drawer{margin-top:16px;display:flex;flex-direction:column;gap:2px}
  .rlv-drawer[hidden]{display:none}
  .rlv-link{display:flex;flex-direction:column;gap:3px;padding:14px;border-radius:12px;text-decoration:none;transition:background .12s}
  .rlv-link:hover{background:var(--crema-2)}
  .rlv-link b{font-size:14.5px;color:var(--tinta);font-weight:600}
  .rlv-link span{font-size:12.5px;color:var(--muted)}

  /* ══ EL PRIMER MINUTO ══ el relevo se despliega una vez; luego, quietud absoluta.
     Solo opacity/transform → compositado, 60fps, sin reflow, sin CLS. El contenido
     es VISIBLE por defecto; el ritual solo corre bajo <html class="rlv-play">
     (una vez al día, movimiento permitido). Cada elemento ya ocupa su posición
     final; solo se revela. El silencio DESPUÉS del relevo es la función. */
  @keyframes rlvRise{from{opacity:0;transform:translateY(var(--ry,10px))}to{opacity:1;transform:none}}
  @keyframes rlvFade{from{opacity:0}to{opacity:1}}
  html.rlv-play .rlv-anim{opacity:0;animation:rlvRise var(--rdur,460ms) cubic-bezier(.22,1,.36,1) both;animation-delay:var(--rd,0ms)}
  html.rlv-play .rlv-anim.op{animation-name:rlvFade}
  @media(prefers-reduced-motion:reduce){
    html.rlv-play .rlv-anim{animation:none!important;opacity:1!important;transform:none!important}
  }
</style>

<main class="rlv">
  <header class="rlv-top">
    <h1 class="rlv-hero rlv-anim" style="--rd:100ms;--rdur:500ms;--ry:12px"><?= $h($hero) ?></h1>
    <p class="rlv-meta"><?= $h($negocio) ?><?= $relevo_label ? ' · ' . $h($relevo_label) : '' ?></p>
  </header>

  <?php if ($modo === 'sin_plan' || $modo === 'primerdia'): ?>
    <section class="rlv-plain rlv-anim" style="--rd:620ms;--rdur:480ms;--ry:10px">
      <?php if ($modo === 'primerdia'): ?>
        <p class="rlv-say">Dale la señal y el corillo prepara tu primera semana de contenido, en tu propia voz.</p>
        <a class="rlv-go" href="<?= $h($st['href']) ?>"><?= $h($st['cta']) ?></a>
      <?php else: ?>
        <p class="rlv-say">Enciende Crecer y el corillo empieza a preparar tu contenido cada semana, en tu propia voz.</p>
        <a class="rlv-go" href="<?= $BASE ?>/precios.php?<?= $mid ?>">Activar Crecer</a>
      <?php endif; ?>
    </section>

  <?php else: ?>
    <?php if ($evidencia): ?>
    <ul class="rlv-ev">
      <?php foreach ($evidencia as $i => $e): ?>
        <li class="rlv-anim" style="--rd:<?= 620 + $i * 140 ?>ms;--rdur:420ms;--ry:8px"><span class="rlv-ck"><?= ico('check-circle') ?></span><?= $h($e) ?></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <?php if ($modo === 'decision' && $featured): ?>
      <div class="rlv-work rlv-anim" id="rlvWork" style="--rd:1080ms;--rdur:460ms;--ry:10px">
        <div class="rlv-thumb<?= ($fe_arte || $fe_video) ? '' : ' txt' ?>">
          <?php if ($fe_arte): ?><img src="<?= $h($featured['grafica_path']) ?>" alt="">
          <?php elseif ($fe_video): ?><span class="rlv-vtag"><?= ico('image') ?>Video</span>
          <?php else: ?><?= ico('image') ?><?php endif; ?>
        </div>
        <div class="rlv-wtext">
          <div class="rlv-wplat"><?= $h(ucfirst((string)($featured['plataforma'] ?? ''))) ?><?= !empty($featured['fecha_programada']) ? ' · ' . $h(_fecha_humana($featured['fecha_programada'])) : '' ?></div>
          <p class="rlv-wcap"><?= $h(mb_strimwidth($fe_cap !== '' ? $fe_cap : '(sin texto)', 0, 190, '…')) ?></p>
        </div>
      </div>

      <div class="rlv-decide" id="rlvDecide">
        <p class="rlv-frase rlv-anim op" style="--rd:1240ms;--rdur:340ms"><?= $h($frase) ?></p>
        <button type="button" class="rlv-go rlv-anim" id="rlvOk" data-id="<?= (int)$featured['id'] ?>" style="--rd:1380ms;--rdur:400ms;--ry:6px"><?= $h($cta) ?></button>
        <br><a class="rlv-alt" href="<?= $BASE ?>/aprobar2.php?edit=<?= (int)$featured['id'] ?>&<?= $mid ?>#cap-<?= (int)$featured['id'] ?>">Cámbiale algo</a>
      </div>

    <?php else: ?>
      <p class="rlv-calma rlv-anim op" style="--rd:1080ms;--rdur:420ms">Hoy no necesitas hacer nada. Nosotros seguimos.</p>
    <?php endif; ?>

    <p class="rlv-firma rlv-anim op" style="--rd:1560ms;--rdur:560ms"><?= $h($firma) ?></p>
  <?php endif; ?>

  <div class="rlv-more">
    <button type="button" class="rlv-vertodo" id="rlvVerTodo">Ver todo <span>→</span></button>
    <div class="rlv-drawer" id="rlvDrawer" hidden>
      <a class="rlv-link" href="<?= $BASE ?>/resultados.php?marca=<?= $marca_id ?>">
        <b>Resultados</b><span><?= (int)($prod['publicados_mes'] ?? 0) ?> publicados este mes<?= (int)($tot_ins['n'] ?? 0) > 0 ? ' · ' . number_format((int)$tot_ins['alcance']) . ' alcanzadas' : '' ?></span>
      </a>
      <a class="rlv-link" href="<?= $BASE ?>/configuracion.php?<?= $mid ?>">
        <b>Piloto automático</b><span><?= $autopilot_on ? 'Encendido · ~' . (int)$autopilot_n . ' posts por semana' : 'Apagado' ?></span>
      </a>
      <a class="rlv-link" href="<?= $BASE ?>/marca.php?marca=<?= $marca_id ?>#aprendido">
        <b>Lo aprendido</b><span><?= !empty($memorias) ? count($memorias) . (count($memorias) === 1 ? ' cosa' : ' cosas') . ' de tu negocio' : 'Aprendiendo tu voz' ?></span>
      </a>
      <a class="rlv-link" href="<?= $BASE ?>/actividad.php?marca=<?= $marca_id ?>">
        <b>Actividad del corillo</b><span>Todo lo que hemos hecho por ti</span>
      </a>
    </div>
  </div>
</main>

<script>
(function () {
  var CSRF = <?= json_encode(csrf_token()) ?>, MARCA = <?= (int)$marca_id ?>, BASE = <?= json_encode($BASE) ?>;
  var ok = document.getElementById('rlvOk');
  if (ok) ok.addEventListener('click', function () {
    var id = ok.getAttribute('data-id'); ok.disabled = true; var old = ok.textContent; ok.textContent = 'Un momento…';
    var fd = new FormData(); fd.append('csrf', CSRF); fd.append('accion', 'aprobar'); fd.append('id', id); fd.append('ajax', '1');
    fetch(BASE + '/aprobar2.php?marca=' + MARCA, { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.ok) {
          // El equipo retoma, callado: el trabajo se desvanece, la confirmación llega.
          var w = document.getElementById('rlvWork'), dc = document.getElementById('rlvDecide');
          if (w) { w.style.transition = 'opacity .36s ease, transform .36s ease'; w.style.opacity = '0'; w.style.transform = 'translateY(-4px)'; }
          if (dc) { dc.style.transition = 'opacity .28s ease'; dc.style.opacity = '0'; }
          setTimeout(function () {
            if (w) w.style.display = 'none';
            if (dc) {
              dc.innerHTML = '<p class="rlv-done">✓ Va pa’ arriba. El corillo se encarga del resto.</p>';
              dc.style.opacity = '0';
              requestAnimationFrame(function () { dc.style.transition = 'opacity .34s ease'; dc.style.opacity = '1'; });
            }
            setTimeout(function () { location.reload(); }, 1500);
          }, 360);
        } else { ok.disabled = false; ok.textContent = old; alert((d && d.err) || 'No se pudo. Intenta otra vez.'); }
      })
      .catch(function () { ok.disabled = false; ok.textContent = old; alert('Se cayó la conexión. Intenta otra vez.'); });
  });
  var vt = document.getElementById('rlvVerTodo'), dr = document.getElementById('rlvDrawer');
  if (vt && dr) vt.addEventListener('click', function () { var open = dr.hidden; dr.hidden = !open; vt.classList.toggle('on', open); });
})();
</script>

<?php require __DIR__ . '/_shell_foot.php'; ?>
