<?php
// ============================================================
//  EL ESTUDIO — la sala de edición del negocio del cliente.
//  panel/propuestas.php  ·  (antes "Contenido")
//
//  Una propuesta a la vez, a pantalla completa. El dueño no
//  administra contenido — le da su bendición al trabajo de su
//  corillo y avanza. Reusa los handlers AJAX de aprobar2.php
//  (aprobar / rechazar / editar / regenerar). CERO backend nuevo.
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/suscripcion.php';
//  EL DOMINIO DEL MATERIAL, ARRIBA Y A LA VISTA. Estaba incluido dentro
//  de los handlers, justo antes de cada llamada, y basto que UNO se
//  quedara sin su require para que la entrega de arte muriera con un
//  fatal en la ruta que mas se usa. Cargarlo aqui quita la clase entera
//  de fallo: no depende de que rama se ejecute ni de que otra pagina lo
//  haya cargado antes.
require_once __DIR__ . '/../includes/material.php';
require __DIR__ . '/../includes/iconos.php';
require_once __DIR__ . '/../includes/meta_ejecutar.php';  // hora_atribucion(): de dónde salió la hora
require_once __DIR__ . '/../includes/baraja.php';   // La Baraja: el gesto de decidir (solo móvil, flag CRECER_BARAJA)
requiere_login();
require_once __DIR__ . '/../includes/panel_guard.php';
requiere_suscripcion($pdo, isset($_GET['marca']) ? (int)$_GET['marca'] : null);
$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }
$marca_id = (int)$marca['id'];
$BASE = '/crecer/panel';
$mid  = "marca={$marca_id}";
// CREAR unificado (flag): ON → el wizard se abre AQUÍ mismo (El Estudio);
// OFF → los enlaces van a aprobar2 como siempre. Reversa = quitar el define.
$CREAR_UNIFICADO = defined('CRECER_CREAR_UNIFICADO') && CRECER_CREAR_UNIFICADO;
$CREAR_URL = $CREAR_UNIFICADO ? "{$BASE}/propuestas.php?{$mid}&crear=1" : "{$BASE}/aprobar2.php?{$mid}&crear=1";
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$negocio = $marca['nombre_negocio'] ?? 'tu negocio';
// Recoge imágenes que terminaron en background + notifica (el worker muere en Hostinger).
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try { require_once __DIR__ . '/../includes/img_responses.php'; img_sweep_pendientes($pdo, $marca_id); } catch (Throwable $e) {}
    try { require_once __DIR__ . '/../includes/carrusel.php'; if (function_exists('carrusel_sweep_pendientes')) carrusel_sweep_pendientes($pdo, $marca_id); } catch (Throwable $e) {}
}

// ── El corillo mira PRIMERO la Biblioteca: atar una foto que ya existe a una
//    propuesta (en vez de generar). Sin IA, sin match inteligente — solo pone la
//    foto que el dueño escogió. ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'usar_activo') {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_ok()) { echo json_encode(['ok'=>false,'err'=>'Sesión expiró. Recarga.']); exit; }
    //  ESTA ES LA MISMA CAPACIDAD QUE LA SELECCION DE BIBLIOTECA, desde otra
    //  pantalla — y tenia sus propias guardas: miraba tipo='imagen' a mano, no
    //  comprobaba si la pieza ya habia salido, y no guardaba de donde venia la
    //  foto. Dos puertas a lo mismo con dos reglas distintas es como se acaba
    //  teniendo una que se olvido de una.
    $pieza = (int)($_POST['pieza'] ?? 0); $activo = (int)($_POST['activo'] ?? 0);
    $r = material_aplicar($pdo, (int)$marca_id, $pieza, $activo);
    if (!empty($r['ok'])) {
        echo json_encode(['ok'=>true, 'url'=>$r['archivo'],
                          'activo_id'=>(int)$r['activo_id']], JSON_UNESCAPED_UNICODE); exit;
    }
    echo json_encode(['ok'=>false, 'err'=>$r['err'] ?? 'No pude usar esa foto.'],
                     JSON_UNESCAPED_UNICODE); exit;
    echo json_encode(['ok'=>false,'err'=>'No se pudo poner la foto.']); exit;
}

// Fotos que ya tiene el negocio (para ofrecerlas ANTES de generar).
$biblioteca = [];
try {
    $bq = $pdo->prepare("SELECT id, archivo FROM crecer_activos WHERE marca_id=? AND tipo='imagen' AND estado='activo' ORDER BY id DESC LIMIT 8");
    $bq->execute([$marca_id]); $biblioteca = $bq->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $biblioteca = []; }
$UP_URL_BIB = (defined('UPLOADS_URL') ? UPLOADS_URL : '/crecer/uploads');

// ── El mazo. Normal: borradores que esperan tu veredicto. Con ?apartadas=1:
//    la SEGUNDA VUELTA — las que dijiste "ahora no" (swipe izq o botón No),
//    por si alguna merece otra oportunidad. Nada se pierde nunca. ──
$deck_apartadas = (($_GET['apartadas'] ?? '') === '1');
// ?jugada=<id> — llega desde Tu Meta ("ver las piezas de esta jugada"): el mazo
// se limita a lo que produjo ESA jugada del plan. Sin el filtro, el dueño caía
// en la lista completa y perdía el hilo de lo que venía a ver.
$jugada_id = isset($_GET['jugada']) ? (int)$_GET['jugada'] : 0;
$jugada_tit = '';
if ($jugada_id > 0) {
    try {
        $qj = $pdo->prepare("SELECT titulo FROM crecer_meta_tactica WHERE id=? AND marca_id=?");
        $qj->execute([$jugada_id, $marca_id]);
        $jugada_tit = (string)($qj->fetchColumn() ?: '');
        if ($jugada_tit === '') $jugada_id = 0;   // ajena o inexistente: se ignora
    } catch (Throwable $e) { $jugada_id = 0; }
}
// ¿Existe ya la columna de material pendiente? (migración 2026-08-12). Se
// pregunta en vez de asumir: si no está, el Estudio funciona igual que antes
// — romperlo entero por una columna nueva sería mucho peor que no mostrarla.
$col_material = false;
try { $pdo->query("SELECT necesita_material FROM crecer_contenido LIMIT 1"); $col_material = true; }
catch (Throwable $e) { $col_material = false; }

$props = [];
try {
    //  `marca_id`, `tactica_id` y `calendario_id` viajan porque los necesita
    //  `hora_atribucion()`: sin ellos toda pieza parecia de origen desconocido
    //  y el Estudio caia siempre al texto neutral, tambien cuando el plan si
    //  habia sugerido la hora y podia explicarlo.
    $sql = "SELECT id, caption, plataforma, tipo, fecha_programada, grafica_path,
                   marca_id, tactica_id, calendario_id"
         . ($col_material ? ", necesita_material, guion" : ", NULL AS necesita_material, NULL AS guion")
         . " FROM crecer_contenido
             WHERE marca_id=? AND estado=? AND tipo<>'carrusel'";
    $par = [$marca_id, $deck_apartadas ? 'rechazado' : 'borrador'];
    if ($jugada_id > 0) { $sql .= " AND tactica_id=?"; $par[] = $jugada_id; }
    $sql .= " ORDER BY COALESCE(fecha_programada, created_at) ASC, id ASC";
    $q = $pdo->prepare($sql);
    $q->execute($par);
    $props = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $props = []; }
// Cuántas apartadas hay en total (para invitar a la segunda vuelta al cerrar el mazo)
$n_apartadas = 0;
try { $n_apartadas = (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$marca_id} AND estado='rechazado' AND tipo<>'carrusel'")->fetchColumn(); } catch (Throwable $e) {}

// Los que YA aprobaste (esperan publicación) — para que no "desaparezcan": banner con enlace.
$n_listos = 0;
try { $n_listos = (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$marca_id} AND estado IN ('aprobado','programado','fallido')")->fetchColumn(); } catch (Throwable $e) {}

// ¿Qué agentes trabajaron de verdad? (para los créditos del corillo, como el lobby)
$ags = [];
try {
    $fq = $pdo->prepare("SELECT DISTINCT agente FROM crecer_ia_log WHERE marca_id=? AND estado='ok' AND agente<>'kernel' ORDER BY id DESC LIMIT 12");
    $fq->execute([$marca_id]); $ags = $fq->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}

if (!function_exists('_fecha_humana')) {
    function _fecha_humana($f) {
        $ts = strtotime((string)$f); if (!$ts) return '';
        $dias = ['Sunday'=>'domingo','Monday'=>'lunes','Tuesday'=>'martes','Wednesday'=>'miércoles','Thursday'=>'jueves','Friday'=>'viernes','Saturday'=>'sábado'];
        $d = $dias[date('l', $ts)] ?? ''; $hora = date('g:i A', $ts); $fd = date('Y-m-d', $ts);
        if ($fd === date('Y-m-d')) return "hoy, {$hora}";
        if ($fd === date('Y-m-d', strtotime('+1 day'))) return "mañana, {$hora}";
        return "el {$d}, {$hora}";
    }
}
// Créditos reales del corillo para una pieza (unidad primero, individuos como prueba).
$creditos = function(array $p) use ($ags, $pdo) {
    $cap = trim((string)$p['caption']);
    $arte = !empty($p['grafica_path']);
    $video = $arte && preg_match('#\.(mp4|mov|m4v)$#i', (string)$p['grafica_path']);
    $c = [];
    if ($cap !== '' || array_intersect(['creador','editor','aprendiz'], $ags)) $c[] = 'La Creativa escribió el caption';
    if ($arte || in_array('diseñador', $ags, true)) $c[] = ($video ? 'El Diseñador preparó el video' : 'El Diseñador montó el arte');
    //  LA HORA: LO QUE LA BASE PUEDE DEMOSTRAR, PIEZA A PIEZA.
    //
    //  Esto miraba `$ags` —los agentes que trabajaron para la MARCA en las
    //  ultimas doce llamadas— y con eso acreditaba a la Estratega la hora de
    //  CUALQUIER pieza con fecha, tambien la que el dueño puso a mano. La
    //  clasificacion vive ahora en el dominio, se decide con datos de la
    //  propia pieza, y cuando no hay con que demostrarlo dice el hecho y
    //  calla el autor.
    $__at = hora_atribucion($pdo, $p);
    if ($__at['caso'] === 'sin_hora') {
        //  Sin hora no se enseña una hora. El dia si, que es cierto.
        if ($__at['cuando'] !== '') $c[] = 'Sale ' . $__at['cuando'];
    } else {
        $c[] = $__at['frase'] !== ''
             ? $__at['frase'] . ' — ' . $__at['cuando']
             : 'Sale ' . $__at['cuando'];
    }
    //  Y si no hay fecha ninguna, la Estratega si dejo huella de haber
    //  cuadrado el plan: eso lo dice el registro de agentes, no la pieza.
    if ($__at['caso'] === 'sin_hora' && $__at['cuando'] === ''
        && array_intersect(['planificador','intake','estratega'], $ags))
        $c[] = 'La Estratega lo cuadró en el plan';
    return array_slice($c, 0, 3);
};

$active = 'contenido';
$page_title = 'Tus Posts';
$guia = ['key'=>'propuestas','agente'=>'sparkles','titulo'=>'El estudio',
  'intro'=>'Aquí revisas lo que tu corillo preparó — una propuesta a la vez.',
  'pasos'=>[
    ['check-circle','Mira la propuesta. Si te gusta: Vamos con este.'],
    ['image','¿Un detalle? Ajústalo, sin salir.'],
    ['sparkles','Cuando decides, entra la siguiente sola.'],
  ]];
require __DIR__ . '/_shell.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
  /* ══ EL ESTUDIO ══ una propuesta a la vez. Hereda la calma del lobby. */
  .content{max-width:600px}
  .asis-fab{display:none}   /* el Copiloto se calla en la sala — el veredicto no compite con nada */
  .est{max-width:560px;margin:0 auto;padding:5vh 6px 90px;font-family:'Poppins',var(--font-body)}
  @media(min-width:761px){.est{padding:7vh 6px 70px}}
  .est-owner{font-size:12px;font-weight:600;letter-spacing:.02em;color:var(--muted);text-transform:none;margin:0 0 22px}
  .est-owner b{color:var(--tinta);font-weight:600}

  .est-prop{opacity:0}
  .est-prop.show{opacity:1}

  /* ══ DESKTOP: la propuesta se ve de UN GOLPE ══
     En el teléfono el orden vertical es correcto: se mira el arte, se lee, se
     desliza. En una pantalla grande esa misma columna medía 1,950px de alto —
     había que hacer scroll para decidir sobre UNA pieza, con 880px de ancho
     vacíos a los lados. Aquí el arte va a un lado y el texto con la decisión
     al otro: se ve entera y se decide sin mover nada. */
  @media(min-width:901px){
    /* el contenedor del panel está limitado a 600px para la lectura en una
       columna; en desktop hay que soltarlo o las dos columnas salen ahogadas */
    .content{max-width:1120px}
    .est{max-width:1040px;padding:5vh 10px 70px}
    .est-prop.show{display:grid;grid-template-columns:minmax(0,46%) minmax(0,1fr);
      gap:12px 34px;align-items:start;align-content:start}
    /* columna izquierda: lo visual y lo que hay que grabar */
    .est-art,.est-guion,.est-bib{grid-column:1;margin-bottom:0}
    .est-art{position:sticky;top:24px}
    /* con 469px de ancho, el 4/5 del recuadro de video daba 586px de alto y
       empujaba todo fuera de pantalla: en desktop se acota */
    .est-art.sube{aspect-ratio:auto;min-height:300px;max-height:380px}
    .est-art img{max-height:62vh}
    /* columna derecha: contexto, copy, créditos y la decisión */
    .est-ctx,.est-cap,.est-cred,.est-verdict,.est-listos,.est-mini{grid-column:2}
    .est-ctx{margin-bottom:6px}
    .est-cap{margin-bottom:14px}
    /* sin arte, el copy manda: ocupa las dos columnas y respira */
    .est-prop.show:not(:has(.est-art)) .est-cap,
    .est-prop.show:not(:has(.est-art)) .est-verdict{grid-column:1/-1}
  }
  .est-ctx{font-size:12.5px;font-weight:600;color:var(--muted);text-transform:capitalize;margin:0 0 14px}
  .est-art{border-radius:22px;overflow:hidden;background:var(--crema-2);border:1px solid var(--line);
    display:grid;place-items:center;margin:0 0 20px;
    box-shadow:0 2px 6px rgba(40,22,28,.05), 0 30px 62px -26px rgba(40,22,28,.3)}
  .est-art img{width:100%;max-height:72vh;object-fit:cover;display:block}
  .est-art.txt{aspect-ratio:16/10;color:var(--teal)}
  .est-art.txt svg{width:38px;height:38px;opacity:.6}

  /* "Sube tu video aquí" — el hueco honesto donde iría el reel.
     No es un error ni un vacío: es una invitación con el camino claro. */
  .est-art.sube{aspect-ratio:4/5;cursor:pointer;background:
    linear-gradient(135deg,color-mix(in srgb,var(--teal) 7%,#fff),color-mix(in srgb,var(--magenta) 6%,#fff));
    border:2px dashed color-mix(in srgb,var(--teal) 40%,#fff);box-shadow:none;transition:border-color .18s,transform .12s}
  .est-art.sube:hover{border-color:var(--teal);transform:translateY(-2px)}
  .est-art.sube:active{transform:scale(.99)}
  .sube-in{text-align:center;padding:26px 22px;color:var(--teal-700,#00827e)}
  .sube-in svg{width:40px;height:40px;margin-bottom:12px;opacity:.85}
  .sube-in b{display:block;font-family:'Oswald',var(--font-display,sans-serif);font-size:21px;letter-spacing:.4px;color:var(--tinta);margin-bottom:6px}
  .sube-in span{display:block;font-size:13.5px;line-height:1.5;color:var(--muted);max-width:240px;margin:0 auto}
  .est-guion{background:#fff;border:1px solid var(--line);border-radius:16px;padding:15px 17px;margin:0 0 20px}
  .est-guion .eg-t{font-size:13px;font-weight:700;color:var(--tinta);margin:0 0 9px}
  .est-guion pre{margin:0;font-family:inherit;font-size:13.5px;line-height:1.65;color:var(--muted);white-space:pre-wrap}
  .est-guion .eg-b{display:inline-flex;align-items:center;gap:7px;margin-top:12px;background:var(--tinta);color:#fff;text-decoration:none;font-weight:800;font-size:13px;padding:10px 15px;border-radius:11px}
  .est-guion .eg-b svg{width:14px;height:14px}
  .est-vtag{display:flex;flex-direction:column;align-items:center;gap:7px;color:var(--teal-700);font-size:12px;font-weight:700;padding:38px 0}
  .est-vtag svg{width:30px;height:30px}
  .est-cap{font-size:16.5px;line-height:1.6;color:var(--tinta);white-space:pre-wrap;margin:0 0 18px;font-weight:400}
  .est-cap.hero{font-size:19px;line-height:1.7;margin-top:6px}   /* propuesta solo-texto: el caption es la obra */

  /* El corillo mira primero la biblioteca — natural, no un módulo */
  .est-bib{margin:0 0 20px}
  .est-bib-say{font-size:14.5px;color:var(--tinta);font-weight:500;line-height:1.5;margin:0 0 13px}
  .est-bib-row{display:flex;gap:9px;overflow-x:auto;padding-bottom:5px;-webkit-overflow-scrolling:touch}
  .est-bib-t{flex:none;width:74px;height:74px;border-radius:12px;border:1px solid var(--line);cursor:pointer;padding:0;
    background-size:cover;background-position:center;background-color:var(--crema-2);transition:transform .15s,box-shadow .15s}
  .est-bib-t:hover{transform:translateY(-2px);box-shadow:var(--shadow)}
  .est-bib-alt{margin-top:13px;font-size:13px;color:var(--muted)}
  .est-bib-alt a{color:var(--teal-700);text-decoration:none;font-weight:500}
  .est-bib-alt a:hover{text-decoration:underline}
  .est-cred{list-style:none;margin:0 0 26px;padding:14px 0 0;border-top:1px solid var(--line);display:flex;flex-direction:column;gap:9px}
  .est-cred li{display:flex;align-items:flex-start;gap:10px;font-size:13.5px;color:var(--muted);line-height:1.35}
  .est-cred .ck{flex:none;margin-top:1px;color:var(--palma);display:inline-flex}
  .est-cred .ck svg{width:17px;height:17px}

  /* El veredicto */
  .est-verdict{display:flex;flex-direction:column;align-items:center;gap:14px}
  .est-go{border:0;cursor:pointer;font-family:'Poppins',sans-serif;font-weight:600;font-size:17px;color:#fff;
    padding:17px 46px;border-radius:16px;background:var(--btn-grad);box-shadow:var(--btn-glow);
    transition:transform var(--dur) var(--ease),box-shadow var(--dur) var(--ease),opacity .2s;width:100%;max-width:340px}
  .est-go:hover{transform:translateY(-2px);box-shadow:var(--btn-glow-hover)}
  .est-go:active{transform:translateY(0);box-shadow:var(--btn-glow-active)}
  .est-go:disabled{opacity:.65;cursor:default;transform:none;box-shadow:var(--btn-glow)}
  .est-minor{display:flex;gap:26px;align-items:center}
  .est-minor button{background:0;border:0;cursor:pointer;font-family:'Poppins',sans-serif;font-size:14px;font-weight:500;color:var(--muted)}
  .est-minor button:hover{color:var(--tinta)}

  /* Puertas cerradas (divulgación progresiva) */
  .est-panel{margin-top:18px;background:var(--card);border:1px solid var(--line);border-radius:16px;padding:16px;box-shadow:var(--shadow-sm)}
  .est-panel[hidden]{display:none}
  .est-panel h4{font-family:'Poppins',sans-serif;font-weight:600;font-size:13px;color:var(--muted);margin:0 0 10px;text-transform:none}
  .est-ta{width:100%;box-sizing:border-box;font-family:var(--font-body);font-size:15px;line-height:1.55;color:var(--tinta);
    border:1.5px solid var(--line);border-radius:12px;padding:12px;resize:vertical;min-height:120px;background:#fff}
  .est-ta:focus{outline:2px solid color-mix(in srgb,var(--magenta) 40%,transparent);outline-offset:1px;border-color:transparent}
  .est-panel-row{display:flex;gap:9px;flex-wrap:wrap;margin-top:12px}
  .est-b{border:0;cursor:pointer;font-family:'Poppins',sans-serif;font-weight:600;font-size:13.5px;padding:11px 16px;border-radius:11px}
  .est-b.pri{color:#fff;background:var(--palma)}
  .est-b.gho{background:#fff;border:1.5px solid var(--line);color:var(--tinta)}
  .est-b.lnk{background:0;color:var(--teal-700);padding:11px 4px}
  .est-b:disabled{opacity:.6;cursor:default}
  .est-reason{display:flex;gap:8px;flex-wrap:wrap}
  .est-reason button{background:#fff;border:1.5px solid var(--line);border-radius:99px;padding:9px 15px;font-family:'Poppins',sans-serif;font-size:13.5px;font-weight:500;color:var(--tinta);cursor:pointer}
  .est-reason button:hover{border-color:var(--noo-ink);color:var(--noo-ink)}
  .est-note{font-size:12.5px;color:var(--muted);margin:10px 0 0}

  /* El cierre en calma */
  .est-done{text-align:center;padding:12vh 10px}
  .est-done .mk{width:56px;height:56px;border-radius:50%;display:grid;place-items:center;margin:0 auto 20px;
    background:color-mix(in srgb,var(--palma) 12%,#fff);color:var(--palma-600)}
  .est-done .mk svg{width:28px;height:28px}
  .est-done h2{font-family:'Poppins',sans-serif;font-weight:600;font-size:clamp(22px,4.6vw,30px);line-height:1.2;color:var(--ink-soft);margin:0 0 10px;text-wrap:balance}
  .est-done p{font-size:15px;color:var(--muted);margin:0 0 26px;line-height:1.5}
  .est-done .acts{display:flex;gap:22px;justify-content:center;flex-wrap:wrap}
  .est-done .acts a{color:var(--teal-700);font-weight:600;font-size:14.5px;text-decoration:none}
  .est-done[hidden]{display:none}
  .est-listos{display:flex;align-items:center;gap:12px;background:linear-gradient(135deg,var(--coral),var(--magenta));color:#fff;text-decoration:none;border-radius:16px;padding:14px 18px;margin:2px 0 18px;box-shadow:0 12px 30px -12px rgba(239,67,117,.5)}
  .est-listos .ico{display:grid;place-items:center;flex:none}
  .est-listos .ico svg{width:24px;height:24px;color:#fff}
  .est-listos b{font-weight:800}
  .est-listos .sub{font-size:12.5px;font-weight:500;opacity:.92}
  .est-listos .arr{margin-left:auto;font-size:20px;font-weight:800}
  /* Crear desde cero — los disparadores del área CREAR (post · reel · carrusel) */
  .est-crear-row{display:flex;gap:9px;margin:0 0 20px;flex-wrap:wrap}
  .est-crear{flex:1 1 30%;min-width:96px;display:flex;align-items:center;justify-content:center;gap:7px;background:var(--btn-grad);color:#fff;
    text-decoration:none;font-family:'Poppins',sans-serif;font-weight:700;font-size:14px;padding:15px 10px;border-radius:14px;
    box-shadow:var(--btn-glow);transition:transform var(--dur) var(--ease),box-shadow var(--dur) var(--ease)}
  .est-crear svg{width:16px;height:16px}
  .est-crear.alt{background:linear-gradient(135deg,var(--teal),#0a7d76);box-shadow:0 12px 30px -14px rgba(0,164,159,.6)}
  .est-crear.car{background:linear-gradient(135deg,#8b5cf6,#6d28d9);box-shadow:0 12px 30px -14px rgba(124,58,237,.6)}
  .est-crear:hover{transform:translateY(-2px);box-shadow:var(--btn-glow-hover)}
  .est-crear.alt:hover{box-shadow:0 16px 34px -14px rgba(0,164,159,.65)}
  .est-crear.car:hover{box-shadow:0 16px 34px -14px rgba(124,58,237,.7)}
  .est-crear:active{transform:translateY(0)}
</style>

<main class="est" id="est">
  <?php if ($deck_apartadas): ?>
  <p class="est-owner">Segunda vuelta — las que apartaste en <b><?= $h($negocio) ?></b></p>
  <a class="est-b lnk" href="<?= $BASE ?>/propuestas.php?<?= $mid ?>" style="display:inline-block;padding:0 0 18px">← Volver a lo nuevo</a>
  <?php elseif ($jugada_id > 0): ?>
  <?php /* Llegó desde Tu Meta: el mazo trae SOLO lo que produjo esa jugada,
           y se dice claro con la salida a la vista para no dejarlo encerrado. */ ?>
  <p class="est-owner">Lo que el corillo hizo para <b><?= $h($jugada_tit) ?></b></p>
  <a class="est-b lnk" href="<?= $BASE ?>/propuestas.php?<?= $mid ?>" style="display:inline-block;padding:0 0 18px">← Ver todas las propuestas</a>
  <?php else: ?>
  <p class="est-owner">El estudio de <b><?= $h($negocio) ?></b></p>
  <div class="est-crear-row">
    <a class="est-crear" href="<?= $CREAR_URL ?>"><?= ico('plus') ?> Post</a>
    <a class="est-crear alt" href="<?= $BASE ?>/reels.php?marca=<?= $marca_id ?>"><?= ico('camera') ?> Reel</a>
    <a class="est-crear car" href="<?= $BASE ?>/carrusel.php?marca=<?= $marca_id ?>"><?= ico('list') ?> Carrusel</a>
  </div>

  <?php if ($n_listos > 0): ?>
  <a class="est-listos" href="<?= $BASE ?>/aprobar2.php?marca=<?= $marca_id ?>&tab=listos">
    <span class="ico"><?= ico('check-circle') ?></span>
    <span><b><?= $n_listos ?></b> post<?= $n_listos === 1 ? '' : 's' ?> <b>listo<?= $n_listos === 1 ? '' : 's' ?> para publicar</b><br><span class="sub">Los que aprobaste están aquí — tócalos para publicarlos</span></span>
    <span class="arr">→</span>
  </a>
  <?php endif; ?>
  <?php endif; ?>

  <?php if (!$props): ?>
    <div class="est-done">
      <div class="mk"><?= ico('check-circle') ?></div>
      <?php if ($deck_apartadas): ?>
      <h2>No tienes nada apartado.</h2>
      <p>Todo lo que el corillo propuso está decidido o esperando tu veredicto.</p>
      <div class="acts">
        <a href="<?= $BASE ?>/propuestas.php?<?= $mid ?>">← Volver al estudio</a>
      </div>
      <?php else: ?>
      <h2>Nada que revisar por ahora.</h2>
      <p>Tu equipo está preparando lo próximo. Vuelve en un rato.</p>
      <div class="acts">
        <a href="<?= $CREAR_URL ?>">Crear un post nuevo</a>
        <a href="<?= $BASE ?>/resultados.php?marca=<?= $marca_id ?>">Ver lo publicado</a>
        <?php if ($n_apartadas > 0): ?><a href="<?= $BASE ?>/propuestas.php?<?= $mid ?>&apartadas=1">Revisar las <?= $n_apartadas ?> apartada<?= $n_apartadas === 1 ? '' : 's' ?></a><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <?php /* La pista del gesto: nace escondida; el motor la enciende solo en teléfono */ ?>
    <p class="bj-pista" id="estPista"><i></i>Desliza: derecha aprueba · izquierda aparta<i></i></p>
    <?php foreach ($props as $i => $p):
      $cap  = trim((string)$p['caption']);
      $arte = !empty($p['grafica_path']);
      $video = $arte && preg_match('#\.(mp4|mov|m4v)$#i', (string)$p['grafica_path']);
      $img  = $arte && !$video;
      //  LA CABECERA USA LA MISMA CLASIFICACION que los creditos de abajo. Si
      //  no, se contradicen en la misma pantalla: aqui salia «sale el lunes,
      //  12:00 AM» mientras tres lineas mas abajo decia «sale el lunes», que es
      //  lo correcto. Una pieza sin hora no tiene hora en ningun sitio.
      $__ctx_at = hora_atribucion($pdo, $p);
      $ctx  = ucfirst((string)($p['plataforma'] ?? ''))
            . ($__ctx_at['cuando'] !== '' ? ' · sale ' . $__ctx_at['cuando'] : '');
      $cred = $creditos($p);
    ?>
      <article class="est-prop<?= $i===0?' show':'' ?>" id="prop-<?= (int)$p['id'] ?>" data-id="<?= (int)$p['id'] ?>" <?= $i===0?'':'style="display:none"' ?>>
        <p class="est-ctx"><?= $h($ctx) ?></p>

        <?php if (!empty($p['necesita_material'])): /* EL REEL ESPERA SU VIDEO ── */ ?>
        <?php /* El corillo escribe el guion, pero el video solo lo puede grabar
                 el dueño. En vez de fingir una imagen y llamarla reel, se le
                 pide con el guion delante y un camino de un toque. */ ?>
        <div class="est-art sube" onclick="location.href='<?= $BASE ?>/reels.php?<?= $mid ?>&pieza=<?= (int)$p['id'] ?>'">
          <div class="sube-in">
            <?= ico('camera') ?>
            <b>Sube tu video aquí</b>
            <span>Yo le pongo la música, los textos y tu marca.</span>
          </div>
        </div>
        <?php if (trim((string)($p['guion'] ?? '')) !== ''): ?>
          <div class="est-guion">
            <p class="eg-t">Te escribí el guion — solo sigue esto con el celular:</p>
            <pre><?= $h($p['guion']) ?></pre>
            <a class="eg-b" href="<?= $BASE ?>/reels.php?<?= $mid ?>&pieza=<?= (int)$p['id'] ?>"><?= ico('camera') ?> Subir mis clips</a>
          </div>
        <?php endif; ?>
        <?php elseif ($img || $video): ?>
        <div class="est-art">
          <?php if ($img): ?><img src="<?= $h($p['grafica_path']) ?>" alt="">
          <?php else: ?><span class="est-vtag"><?= ico('image') ?>Video</span><?php endif; ?>
        </div>
        <?php elseif ($biblioteca): /* el corillo mira PRIMERO lo que el negocio ya tiene */ ?>
        <div class="est-bib" data-piezabib="<?= (int)$p['id'] ?>">
          <p class="est-bib-say">El corillo miró tu biblioteca primero. ¿Alguna de estas va con este post?</p>
          <div class="est-bib-row">
            <?php foreach ($biblioteca as $bx): $burl = $UP_URL_BIB . '/' . $bx['archivo']; ?>
              <button type="button" class="est-bib-t" data-activo="<?= (int)$bx['id'] ?>" style="background-image:url('<?= $h($burl) ?>')" aria-label="Usar esta foto"></button>
            <?php endforeach; ?>
          </div>
          <div class="est-bib-alt"><a href="<?= $BASE ?>/biblioteca.php?<?= $mid ?>">Ver toda la biblioteca</a> · <a href="<?= $BASE ?>/aprobar2.php?edit=<?= (int)$p['id'] ?>&<?= $mid ?>#cap-<?= (int)$p['id'] ?>">o que genere una nueva</a></div>
        </div>
        <?php endif; ?>

        <p class="est-cap<?= ($img || $video || $biblioteca) ? '' : ' hero' ?>" data-cap><?= $h($cap !== '' ? $cap : '(sin texto todavía)') ?></p>

        <?php if ($cred): ?>
        <ul class="est-cred">
          <?php foreach ($cred as $c): ?><li><span class="ck"><?= ico('check-circle') ?></span><?= $h($c) ?></li><?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <div class="est-verdict">
          <button type="button" class="est-go" data-go>Vamos con este</button>
          <div class="est-minor">
            <button type="button" data-adjust>Ajústalo</button>
            <button type="button" data-reject>No</button>
          </div>
        </div>

        <!-- Puerta: Ajústalo -->
        <div class="est-panel" data-panel="adjust" hidden>
          <h4>Ajústale el texto — el corillo aprende de tu cambio.</h4>
          <textarea class="est-ta" data-editor><?= $h($cap) ?></textarea>
          <div class="est-panel-row">
            <button type="button" class="est-b pri" data-save>Guardar</button>
            <button type="button" class="est-b gho" data-regen>Otra versión</button>
            <a class="est-b lnk" href="<?= $BASE ?>/aprobar2.php?edit=<?= (int)$p['id'] ?>&<?= $mid ?>#cap-<?= (int)$p['id'] ?>">Arte, fecha y más →</a>
            <button type="button" class="est-b lnk" data-cancel style="margin-left:auto">Cerrar</button>
          </div>
          <p class="est-note" data-panelnote></p>
        </div>

        <!-- Puerta: No (razón que enseña al Cerebro) -->
        <div class="est-panel" data-panel="reject" hidden>
          <h4>¿Qué no cuadró? Así el corillo lo hace mejor la próxima.</h4>
          <div class="est-reason">
            <button type="button" data-razon="formal">Muy formal</button>
            <button type="button" data-razon="largo">Muy largo</button>
            <button type="button" data-razon="voz">No es mi voz</button>
            <button type="button" data-razon="">Solo no</button>
          </div>
          <button type="button" class="est-b lnk" data-cancel style="margin-top:12px">Cerrar</button>
        </div>
      </article>
    <?php endforeach; ?>

    <!-- El cierre en calma -->
    <div class="est-done" id="estDone" hidden>
      <div class="mk"><?= ico('check-circle') ?></div>
      <?php if ($deck_apartadas): ?>
      <h2>Segunda vuelta completa.</h2>
      <p>Lo que rescataste ya está con lo aprobado; lo demás queda apartado, sin perderse.</p>
      <div class="acts">
        <a href="<?= $BASE ?>/propuestas.php?<?= $mid ?>">← Volver al estudio</a>
        <a href="<?= $BASE ?>/resultados.php?marca=<?= $marca_id ?>">Ver lo publicado</a>
      </div>
      <?php else: ?>
      <h2>Ya revisaste todo lo que el corillo preparó.</h2>
      <p class="bj-resumen" id="bjResumen" hidden style="font-weight:600;color:var(--tinta)"></p>
      <p>El corillo sigue trabajando por <?= $h($negocio) ?>.</p>
      <div class="acts">
        <a href="<?= $BASE ?>/propuestas.php?<?= $mid ?>&apartadas=1" id="bjOtraVuelta" <?= $n_apartadas > 0 ? '' : 'hidden' ?>>¿Les damos otra vuelta a las apartadas?</a>
        <a href="<?= $CREAR_URL ?>">Crear un post nuevo</a>
        <a href="<?= $BASE ?>/resultados.php?marca=<?= $marca_id ?>">Ver lo publicado</a>
      </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</main>

<?php
// El wizard de CREAR (compartido con aprobar2): con ?crear=1 se abre solo.
// Montarlo aquí = crear un post sin salir del Estudio (una sola superficie).
//  El wizard de crear postea a aprobar2.php desde AQUI, asi que el token tiene
//  que ponerse solo tambien en esta pagina: sin esto, sus 16 llamadas llegarian
//  sin token y el candado nuevo las rechazaria — la ruta vieja se rompe.
  /*  LA OPORTUNIDAD QUE VIENE DE LA SALA. Llega por `?sala=<id>` — solo el
      número de la conversación, nunca la idea entera en la URL— y se lee de
      la base comprobando que esa conversación es de ESTA marca.

      Se precarga como texto editable: el dueño puede cambiarlo todo. Y lo que
      salga de aquí NO lleva `meta_id` ni `tactica_id`: decir que cumple una
      jugada del plan cuando no la cumple es contarle un avance que no existe. */
  $sala_idea = '';
  $sala_job  = isset($_GET['sala']) ? (int)$_GET['sala'] : 0;
  if ($sala_job > 0) {
      try {
          require_once __DIR__ . '/../includes/sala_oportunidad.php';
          $op_sala = sala_op_leer($pdo, $sala_job, $marca_id);
          if ($op_sala) {
              $sala_idea = trim((string)($op_sala['titulo'] ?? ''));
              if (trim((string)($op_sala['que_hacer'] ?? '')) !== '') {
                  $sala_idea .= ' — ' . $op_sala['que_hacer'];
              }
          }
      } catch (Throwable $e) { $sala_idea = ''; }
  }
?>
<?php if ($sala_idea !== ''): ?>
  <script>window.CRECER_SALA_IDEA = <?= json_encode($sala_idea, JSON_UNESCAPED_UNICODE) ?>;</script>
<?php endif; ?>
<?php
//  LA ZONA SEGURA Y LA REGLA DE AYUDA. La misma geometría que protege a Tu
//  Meta: aparta el botón flotante cuando un control entra en su franja. Aquí
//  el control es «Vamos con este», que es la decisión del producto.
require __DIR__ . '/_meta_zona.php';
require __DIR__ . '/../includes/csrf_js.php';
include __DIR__ . '/_crear_wizard.php';
?>

<?= baraja_assets() /* La Baraja (motor). Con el flag OFF esto es '' y nada cambia. */ ?>

<script>
(function () {
  var CSRF = <?= json_encode(csrf_token()) ?>, MARCA = <?= (int)$marca_id ?>, BASE = <?= json_encode($BASE) ?>;
  var props = [].slice.call(document.querySelectorAll('.est-prop'));
  var done = document.getElementById('estDone');
  var idx = 0, busy = false;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function post(accion, id, extra) {
    var fd = new FormData(); fd.append('csrf', CSRF); fd.append('accion', accion); fd.append('id', id); fd.append('ajax', '1');
    if (extra) for (var k in extra) fd.append(k, extra[k]);
    return fetch(BASE + '/aprobar2.php?marca=' + MARCA, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
  }

  // ── El marcador de la sesión (para el cierre y el Deshacer de La Baraja) ──
  var nAprob = 0, nApart = 0;
  var DECK = <?= $deck_apartadas ? 'true' : 'false' ?>;                 // ¿estamos en la segunda vuelta?
  var APARTADAS_PREV = <?= (int)$n_apartadas ?>;                        // apartadas que ya existían al cargar
  function cierre() {
    done.hidden = false;
    var res = document.getElementById('bjResumen');
    if (res) {
      var partes = [];
      if (nAprob) partes.push('Aprobaste ' + nAprob);
      if (nApart) partes.push('apartaste ' + nApart);
      if (partes.length) { res.textContent = partes.join(' · ') + '.'; res.hidden = false; }
    }
    var ov = document.getElementById('bjOtraVuelta');
    if (ov) {
      var total = APARTADAS_PREV + nApart;
      if (total > 0) {
        ov.hidden = false;
        ov.textContent = total === 1 ? '¿Le damos otra vuelta a la apartada?' : '¿Les damos otra vuelta a las ' + total + ' apartadas?';
      } else ov.hidden = true;
    }
  }

  // El pase: la propuesta sale, la siguiente sube al mismo lugar. El único movimiento.
  // yaSalio = la card ya voló con el gesto (La Baraja): no se re-anima la salida.
  function pase(yaSalio) {
    var cur = props[idx];
    idx++;
    var next = props[idx];
    if (!next) {
      if (reduce || yaSalio) { cur.style.display = 'none'; cierre(); return; }
      cur.style.transition = 'opacity .34s ease, transform .34s ease';
      cur.style.opacity = '0'; cur.style.transform = 'translateY(-8px)';
      setTimeout(function () { cur.style.display = 'none'; cierre(); }, 320);
      return;
    }
    if (reduce) {
      cur.style.display = 'none';
      next.style.display = ''; next.classList.add('show');
      return;
    }
    if (yaSalio) {
      cur.style.display = 'none';
      next.style.display = '';
      next.style.opacity = '0'; next.style.transform = 'translateY(10px)';
      next.classList.add('show');
      requestAnimationFrame(function () {
        next.style.transition = 'opacity .38s cubic-bezier(.22,1,.36,1), transform .38s cubic-bezier(.22,1,.36,1)';
        next.style.opacity = '1'; next.style.transform = 'none';
      });
      return;
    }
    cur.style.transition = 'opacity .30s ease, transform .30s ease';
    cur.style.opacity = '0'; cur.style.transform = 'translateY(-8px)';
    setTimeout(function () {
      cur.style.display = 'none';
      next.style.display = '';
      next.style.opacity = '0'; next.style.transform = 'translateY(10px)';
      next.classList.add('show');
      requestAnimationFrame(function () {
        next.style.transition = 'opacity .38s cubic-bezier(.22,1,.36,1), transform .38s cubic-bezier(.22,1,.36,1)';
        next.style.opacity = '1'; next.style.transform = 'none';
      });
    }, 300);
  }

  function toast(card, msg) { var n = card.querySelector('[data-panelnote]'); if (n) n.textContent = msg; }

  props.forEach(function (card) {
    var id = card.getAttribute('data-id');
    var go = card.querySelector('[data-go]');
    var panels = { adjust: card.querySelector('[data-panel="adjust"]'), reject: card.querySelector('[data-panel="reject"]') };
    function closePanels() { panels.adjust.hidden = true; panels.reject.hidden = true; }

    // Vamos con este
    go.addEventListener('click', function () {
      if (busy) return; busy = true; go.disabled = true; var old = go.textContent; go.textContent = 'Un momento…';
      post('aprobar', id).then(function (d) {
        if (d && d.ok) { nAprob++; pase(); }
        else { go.disabled = false; go.textContent = old; alert((d && d.err) || 'No se pudo. Intenta otra vez.'); }
      }).catch(function () { go.disabled = false; go.textContent = old; alert('Se cayó la conexión.'); })
        .finally(function () { busy = false; });
    });

    // Ajústalo / No (abrir puertas)
    card.querySelector('[data-adjust]').addEventListener('click', function () { var open = panels.adjust.hidden; closePanels(); panels.adjust.hidden = !open; });
    card.querySelector('[data-reject]').addEventListener('click', function () { var open = panels.reject.hidden; closePanels(); panels.reject.hidden = !open; });
    card.querySelectorAll('[data-cancel]').forEach(function (b) { b.addEventListener('click', closePanels); });

    // Guardar texto
    card.querySelector('[data-save]').addEventListener('click', function () {
      var ta = card.querySelector('[data-editor]');
      var cap = ta.value.trim(); if (!cap) return;
      var btn = this; btn.disabled = true; btn.textContent = 'Guardando…';
      post('editar', id, { caption: cap }).then(function (d) {
        if (d && d.ok) { card.querySelector('[data-cap]').textContent = d.caption || cap; closePanels(); }
        else toast(card, 'No se pudo guardar.');
      }).catch(function () { toast(card, 'Se cayó la conexión.'); })
        .finally(function () { btn.disabled = false; btn.textContent = 'Guardar'; });
    });

    // Otra versión (regenerar)
    card.querySelector('[data-regen]').addEventListener('click', function () {
      var btn = this; btn.disabled = true; btn.textContent = 'Escribiendo…'; toast(card, 'La Creativa está escribiendo otra versión…');
      post('regenerar', id).then(function (d) {
        if (d && d.ok && d.caption) {
          card.querySelector('[data-cap]').textContent = d.caption;
          card.querySelector('[data-editor]').value = d.caption;
          toast(card, 'Lista. Si te gusta, Guardar o Vamos con este.');
        } else if (d && d.paywall) { toast(card, 'Reescribir con IA es del plan pago. El texto actual queda igual.'); }
        else { toast(card, 'No pude reescribir ahora. Intenta de nuevo.'); }
      }).catch(function () { toast(card, 'Se cayó la conexión.'); })
        .finally(function () { btn.disabled = false; btn.textContent = 'Otra versión'; });
    });

    // No, con razón (enseña al Cerebro)
    card.querySelectorAll('[data-razon]').forEach(function (b) {
      b.addEventListener('click', function () {
        if (busy) return; busy = true;
        post('rechazar', id, { razon: b.getAttribute('data-razon') }).then(function (d) {
          if (d && d.ok) { closePanels(); nApart++; pase(); }
          else alert((d && d.err) || 'No se pudo.');
        }).catch(function () { alert('Se cayó la conexión.'); })
          .finally(function () { busy = false; });
      });
    });
  });

  // ── El corillo mira la Biblioteca: usar una foto que YA existe (antes de generar) ──
  var SELF = location.pathname + '?<?= $h($mid) ?>';
  document.querySelectorAll('.est-bib').forEach(function (bib) {
    var pieza = bib.getAttribute('data-piezabib');
    bib.querySelectorAll('.est-bib-t').forEach(function (t) {
      t.addEventListener('click', function () {
        if (t.dataset.busy) return; t.dataset.busy = '1'; t.style.opacity = '.5';
        var fd = new FormData(); fd.append('csrf', CSRF); fd.append('accion', 'usar_activo'); fd.append('pieza', pieza); fd.append('activo', t.getAttribute('data-activo'));
        fetch(SELF, { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (d) {
          if (d && d.ok && d.url) {
            var art = document.createElement('div'); art.className = 'est-art';
            art.innerHTML = '<img src="' + d.url + '" alt="">';
            bib.replaceWith(art);   // "ya tenemos la foto" — la propuesta ahora la lleva
          } else { t.style.opacity = ''; t.dataset.busy = ''; alert((d && d.err) || 'No se pudo poner la foto.'); }
        }).catch(function () { t.style.opacity = ''; t.dataset.busy = ''; alert('Se cayó la conexión.'); });
      });
    });
  });

  // ── LA BARAJA: el gesto de decidir (solo teléfono; los botones se quedan). ──
  // Derecha = aprobar · izquierda = apartar (persiste; nada se recicla) ·
  // Deshacer 5s en ambas. En la segunda vuelta (?apartadas=1): derecha rescata,
  // izquierda la deja apartada (solo pasa la card, sin tocar el server).
  if (window.Baraja) Baraja.montar({
    cartas: '.est-prop',   // touch-action pan-y: el dedo horizontal es de La Baraja
    pista: document.getElementById('estPista'),
    ignorar: '.est-verdict,.est-panel,.est-bib,.est-crear-row,.est-listos',
    activa: function () { return props[idx] || null; },
    aprobar: function (card, hecho) {
      if (busy) { hecho(false); return; }
      busy = true;
      post('aprobar', card.getAttribute('data-id')).then(function (d) {
        var ok = !!(d && d.ok);
        if (ok) { nAprob++; pase(true); }
        hecho(ok);
        if (!ok) alert((d && d.err) || 'No se pudo. Intenta otra vez.');
      }).catch(function () { hecho(false); alert('Se cayó la conexión.'); })
        .finally(function () { busy = false; });
    },
    apartar: function (card, hecho) {
      if (busy) { hecho(false); return; }
      if (DECK) { nApart++; pase(true); hecho(true); return; }   // ya estaba apartada: puro orden en pantalla
      busy = true;
      post('rechazar', card.getAttribute('data-id'), { razon: '' }).then(function (d) {
        var ok = !!(d && d.ok);
        if (ok) { nApart++; pase(true); }
        hecho(ok);
        if (!ok) alert((d && d.err) || 'No se pudo. Intenta otra vez.');
      }).catch(function () { hecho(false); alert('Se cayó la conexión.'); })
        .finally(function () { busy = false; });
    },
    deshacer: function (card, dir, hecho) {
      // Devolver la card al frente del mazo (y al estado en que estaba).
      var devolver = function () {
        var i = props.indexOf(card);
        if (i >= 0) { props.splice(i, 1); if (i < idx) idx--; }
        var visible = props[idx];
        if (visible) { visible.style.display = 'none'; visible.classList.remove('show'); }
        props.splice(idx, 0, card);
        done.hidden = true;
        card.style.display = ''; card.classList.add('show');
        if (dir > 0) nAprob = Math.max(0, nAprob - 1); else nApart = Math.max(0, nApart - 1);
        hecho(true);
      };
      // Segunda vuelta: deshacer un rescate = re-apartar; deshacer un "sigue
      // apartada" no tocó el server, así que tampoco al deshacer.
      var accion = DECK ? (dir > 0 ? 'rechazar' : null) : 'reabrir';
      if (!accion) { devolver(); return; }
      post(accion, card.getAttribute('data-id'), accion === 'rechazar' ? { razon: '' } : undefined)
        .then(function (d) { if (d && d.ok) devolver(); else hecho(false); })
        .catch(function () { hecho(false); });
    }
  });
})();
</script>

<?php
// EL RECIBIMIENTO — la primera vez que esta cuenta entra a esta pantalla.
// Su JS espera al 'load', así el botón Ayuda ya existe cuando lo ilumine.
require_once __DIR__ . '/../includes/tour.php';
tour_montar($pdo, $marca_id, 'crear');
require __DIR__ . '/_shell_foot.php'; ?>
