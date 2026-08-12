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
  .jg{background:var(--card,#fff);border:1px solid var(--line);border-radius:15px;padding:15px 16px;box-shadow:var(--shadow-sm);transition:border-color .15s,transform .12s}
  .jg:hover{border-color:var(--teal,#00A49F);transform:translateY(-1px)}
  .jg.hecha{opacity:.55}
  .jg.hecha .jg-t{text-decoration:line-through}
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

  /* Encabezado del plan vigente + cumplimiento */
  .plan-cab{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;margin:0 0 12px;flex-wrap:wrap}
  .plan-cab h2{font-family:var(--font-display,'Oswald',sans-serif);font-size:17px;letter-spacing:.4px;color:var(--tinta);margin:0}
  .plan-v{font-size:12px;color:var(--muted);font-weight:600}
  .plan-prog{text-align:right;font-size:12.5px;color:var(--muted);font-weight:600;min-width:132px}
  .plan-prog b{color:var(--tinta)}
  .plan-barra{height:6px;border-radius:99px;background:var(--crema-2,#f2efe9);border:1px solid var(--line);overflow:hidden;margin-top:5px}
  .plan-barra i{display:block;height:100%;background:linear-gradient(90deg,var(--teal,#00A49F),var(--magenta,#EF4375));border-radius:99px;transition:width .5s}
  .plan-obs{background:color-mix(in srgb,var(--teal,#00A49F) 10%,#fff);border:1px solid color-mix(in srgb,var(--teal,#00A49F) 30%,#fff);color:#0a6a5f;border-radius:13px;padding:12px 14px;font-size:13px;line-height:1.55;margin:0 0 14px}

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

<?php if (!$meta): /* ══════════ WIZARD ══════════ */ ?>

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
    var fd=new FormData();
    fd.append('csrf',CSRF); fd.append('accion','crear');
    fd.append('objetivo',datos.objetivo); fd.append('cantidad',datos.cantidad);
    fd.append('fecha_limite', f.toISOString().slice(0,10));
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

<?php else: /* ══════════ LA META VIVA ══════════ */
  $def = meta_objetivo_def((string)$meta['objetivo']);
  $pct = $prog['pct'] !== null ? (int)$prog['pct'] : 0;
?>

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

      <div style="display:flex;gap:8px;margin-top:16px;flex-wrap:wrap">
        <button type="button" class="btn-s" id="replan" style="flex:1">Rehacer el plan</button>
        <button type="button" class="btn-s" id="cerrar" style="flex:1">Cambiar de meta</button>
      </div>
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
        <?php foreach ($tacticas as $t):
          $tipo_lbl = ['contenido'=>'Contenido','distribucion'=>'Difusión','pauta'=>'Anuncio pagado',
                       'oferta'=>'Oferta','alianza'=>'Alianza','operacion'=>'Cómo operar'][$t['tipo']] ?? $t['tipo'];
          $clase = (string)($t['clase'] ?? 'produccion');
          $jp    = jugada_progreso($pdo, $t);
          $hecha = $t['estado'] === 'hecha';
        ?>
          <div class="jg <?= $hecha?'hecha':'' ?> <?= $clase==='regla'?'regla':'' ?>" data-id="<?= (int)$t['id'] ?>">
            <div class="jg-top">
              <span class="jg-tipo <?= $h($t['tipo']) ?>"><?= $h($tipo_lbl) ?></span>
              <span class="jg-t"><?= $h($t['titulo']) ?></span>
            </div>
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

              <?php if ((int)$jp['espera_video'] > 0): ?>
                <?php /* Lo único que el corillo NO puede hacer solo: el video.
                         Se dice claro y se le da el camino, en vez de fingir
                         que la pieza está lista. */ ?>
                <div class="jg-video">
                  <b><?= (int)$jp['espera_video'] === 1 ? 'Te falta grabar 1 video' : 'Te faltan ' . (int)$jp['espera_video'] . ' videos' ?></b>
                  Ya te escribí el guion — dice exactamente qué grabar, clip por clip, con el celular.
                  Súbelos y yo los monto con música, textos y tu marca.
                  <a href="<?= $BASE ?>/reels.php?marca=<?= $marca_id ?>"><?= ico('camera') ?> Subir mis videos</a>
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
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="card">
        <p style="margin:0;font-size:14px;color:var(--muted);line-height:1.55">
          La Estratega todavía no dejó las jugadas. Dale a <b>Rehacer el plan</b> y lo arma de nuevo.</p>
      </div>
    <?php endif; ?>

    <?php if ($historial): ?>
      <h2 style="font-family:var(--font-display,'Oswald',sans-serif);font-size:17px;letter-spacing:.4px;color:var(--tinta);margin:26px 0 4px">
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
    var b=this; b.disabled=true; b.textContent='La Estratega está pensando…';
    post({accion:'replan'}).then(function(d){
      if(d.ok) location.reload();
      else { b.disabled=false; b.textContent='Rehacer el plan'; alert(d.err||'No pude rehacer el plan.'); }
    }).catch(function(){ b.disabled=false; b.textContent='Rehacer el plan'; });
  });

  document.getElementById('cerrar').addEventListener('click', function(){
    if(!confirm('¿Cambiar de meta? El corillo dejará de perseguir esta.')) return;
    post({accion:'cerrar'}).then(function(){ location.reload(); });
  });
})();
</script>

<?php endif; ?>

<?php
require_once __DIR__ . '/../includes/tour.php';
tour_montar($pdo, $marca_id, 'meta');
require __DIR__ . '/_shell_foot.php'; ?>
