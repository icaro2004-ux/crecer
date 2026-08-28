<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — La Sala del Corillo (War Room)
//  panel/sala.php  ·  brainstorm + órdenes + fechas/ofertas + staff meeting semanal.
//  Un solo espacio para conversar con tu equipo. Con voz (habla y te contesta).
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/agentes.php';
require_once __DIR__ . '/../includes/iconos.php';   // ico() se usa antes de _shell.php
requiere_login();

$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }
$marca_id = (int)$marca['id'];

// ── AJAX: el dueño habla en la sala ──
//  ASÍNCRONO: el mensaje se ENCOLA y respondemos al instante con un job id.
//  El worker corre la cadena de agentes por detrás; el front hace polling.
//  (Antes era síncrono → producir una campaña tardaba 1-3 min → 504 / "se cayó
//   la conexión". Ver includes/sala_async.php.)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_ok()) { echo json_encode(['ok'=>false,'err'=>'Sesión expiró. Recarga la página.']); exit; }
    require_once __DIR__ . '/../includes/sala_async.php';
    require_once __DIR__ . '/../includes/i18n.php';
    $BASE = '/crecer/panel';

    // (a) POLLING: el front pregunta por el estado de un job.
    //
    //  EL `!isset($_POST['oportunidad'])` NO SOBRA. Las decisiones sobre una
    //  oportunidad tambien mandan `job` —es la conversacion de la que salieron—,
    //  y sin esta condicion caian aqui: el servidor contestaba «done» a lo que
    //  era «anadela a mi Meta», el front no veia opciones y la tarjeta de
    //  eleccion no aparecia nunca. En PHP no se notaba; se vio en el navegador.
    if (isset($_POST['job']) && !isset($_POST['oportunidad'])) {
        $st = sala_job_estado($pdo, (int)$_POST['job'], $marca_id);
        if (!$st) { echo json_encode(['ok'=>false,'err'=>'No encuentro ese mensaje.']); exit; }
        if ($st['estado'] === 'done') {
            echo json_encode([
                'ok'=>true, 'estado'=>'done',
                'respuesta'=>(string)$st['respuesta'],
                'accion'=>(string)($st['accion'] ?? 'conversar'),
                'aprendido'=>json_decode((string)($st['aprendido'] ?? 'null'), true),
                //  ¿ESE TURNO TRAJO UNA OPORTUNIDAD EJECUTABLE? Solo se dice que
                //  sí o que no: los datos se leen después, cuando el dueño
                //  pregunta qué pasaría — y se leen del servidor, no de aquí.
                'oportunidad'=>(trim((string)($st['oportunidad'] ?? '')) !== ''),
                'job'=>(int)$_POST['job'],
            ], JSON_UNESCAPED_UNICODE);
        } elseif ($st['estado'] === 'failed') {
            echo json_encode(['ok'=>true, 'estado'=>'failed', 'err'=>(string)($st['error_msg'] ?? 'Se trabó el equipo.')], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['ok'=>true, 'estado'=>'working']);
        }
        exit;
    }

    // ── LA OPORTUNIDAD: DE LA CONVERSACIÓN AL TRABAJO ────────────────────
    //
    //  Aquí NO se llama a ningún modelo. La propuesta ya vino estructurada en
    //  el mismo turno que la produjo; esto solo escribe lo que ya se decidió.
    //
    //  Y NADA DE ESTO CONFÍA EN LO QUE MANDE EL NAVEGADOR: el `job` se lee con
    //  `marca_id` en el WHERE, así que adivinar un número no sirve para
    //  añadirle una jugada al plan de otro negocio.
    if (isset($_POST['oportunidad'])) {
        require_once __DIR__ . '/../includes/sala_oportunidad.php';
        $job = (int)($_POST['job'] ?? 0);
        $que = (string)$_POST['oportunidad'];
        $op  = sala_op_leer($pdo, $job, $marca_id);
        if (!$op) { echo json_encode(['ok'=>false, 'err'=>'No encuentro esa idea.']); exit; }
        $ev = sala_op_evaluar($pdo, $marca_id, $op);

        //  (a) VER LA REPERCUSIÓN. Se pregunta ANTES de escribir: el dueño tiene
        //      derecho a saber en qué semana entra y qué NO se mueve.
        if ($que === 'ver') {
            $c = sala_op_consecuencia($pdo, $marca_id, $op, $ev);
            $o = sala_op_opciones($pdo, $marca_id, $op, $ev, $BASE);
            echo json_encode([
                'ok' => true, 'puede' => $ev['puede'], 'motivo' => $ev['motivo'],
                'titulo' => $op['titulo'], 'formato' => $op['formato'],
                'lineas' => $c['lineas'], 'opciones' => $o['opciones'], 'nota' => $o['nota'],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        //  (b) AÑADIRLA A LA META. Idempotente por conversación: dos clics
        //      devuelven la misma jugada.
        if ($que === 'meta') {
            $r = sala_op_a_meta($pdo, $marca_id, $job);
            if (empty($r['ok'])) { echo json_encode(['ok'=>false, 'err'=>$r['err']], JSON_UNESCAPED_UNICODE); exit; }
            echo json_encode([
                'ok' => true, 'ya' => $r['ya'],
                'texto' => $r['ya']
                    ? t('Esta idea ya estaba añadida.')
                    : t('La añadí a tu Meta. Estoy preparando la publicación.'),
                'accion' => t('Ver en Tu Meta'),
                //  AL PLAN, NO A «revisar mi semana». Lo que acaba de nacer es
                //  una JUGADA, y `vista=semana` repasa PIEZAS una por una: el
                //  dueño confirmaba, pulsaba «Ver en Tu Meta» y aterrizaba en
                //  una pantalla donde lo que acababa de añadir no estaba por
                //  ningún lado. En el plan sí se ve, con su semana.
                //  Y AL SITIO EXACTO. Un plan de seis jugadas es media
                //  pantalla de scroll: sin el ancla, el dueño aterrizaba en la
                //  jugada de turno y la suya quedaba más abajo, sin señal.
                'href'   => $BASE . '/meta.php?marca=' . $marca_id . '&vista=plan'
                            . ((int)($r['tactica_id'] ?? 0) > 0 ? '#j' . (int)$r['tactica_id'] : ''),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        //  (c) CREARLA APARTE. No escribe nada: dice a dónde ir. La idea NO
        //      viaja por la URL — Crear la lee de la conversación, que ya está
        //      guardada y es de esta marca.
        if ($que === 'crear') {
            echo json_encode([
                'ok' => true, 'href' => sala_op_url_crear($marca_id, $job, $BASE),
                'texto' => t('Se creará fuera del plan. No cambiará tu semana ni las tareas de la Meta.'),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        //  (d) SEGUIR CONVERSANDO no escribe nada, y por eso no tiene rama: la
        //      conversación ya está guardada y el dueño puede decidir después.
        echo json_encode(['ok'=>true, 'texto'=>'']); exit;
    }

    // (b) ENVIAR: encolar el mensaje y disparar el worker.
    $mensaje = trim((string)($_POST['mensaje'] ?? ''));
    if ($mensaje === '') { echo json_encode(['ok'=>false,'err'=>'Escribe o di algo.']); exit; }
    $historial = json_decode((string)($_POST['historial'] ?? '[]'), true);
    if (!is_array($historial)) $historial = [];
    require_once __DIR__ . '/../includes/suscripcion.php';
    $puede_producir = (function_exists('activacion_de_prueba') && activacion_de_prueba($usuario['email'] ?? null))
        || (function_exists('plan_de_marca') && plan_de_marca($pdo, $marca_id) !== null);
    // El límite del copiloto solo aplica a cuentas FREE (protege costo). Plan/prueba: libre.
    if (!$puede_producir) {
        $limite = copiloto_limite_uso($pdo, $marca_id);
        if (empty($limite['ok'])) { echo json_encode(['ok'=>false, 'err'=>$limite['err']], JSON_UNESCAPED_UNICODE); exit; }
    }
    try {
        $job = sala_encolar($pdo, $marca_id, mb_substr($mensaje, 0, 1000), $historial, $puede_producir);
        sala_disparar($job);
        echo json_encode(['ok'=>true, 'job'=>$job], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false, 'err'=>substr($e->getMessage(), 0, 160)], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$saludo = sala_saludo($pdo, $marca_id);
$roster = equipo_roster();
$gerente = ['svg' => ico($roster['gerente']['ico']), 'nombre' => equipo_nombre($marca, 'gerente')];
$active = 'sala';
$page_title = 'La Sala';
require __DIR__ . '/_shell.php';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<style>
  /* ══ LA SALA DEL CORILLO ══ war room: el equipo presente + hilo + voz.
     Móvil = chat a pantalla, voz al frente. Desktop = columna que respira.
     Hereda tokens de Crecer (crema, rosa/teal, Poppins/Oswald). */
  .content{max-width:760px}
  .sc-top{margin-bottom:2px}
  .sc-top h1{font-family:var(--font-display);font-weight:700;font-size:23px;letter-spacing:.4px;color:var(--tinta);margin:0;line-height:1.1}
  .sc-top p{font-size:13px;color:var(--muted);margin:4px 0 0;max-width:560px;line-height:1.45}

  /* Equipo presente: caras con nombre, "en la sala" */
  .sc-team{display:flex;gap:14px;overflow-x:auto;padding:14px 2px 4px;margin:0 -2px;scrollbar-width:none}
  .sc-team::-webkit-scrollbar{display:none}
  .sc-mem{flex:none;display:flex;flex-direction:column;align-items:center;gap:5px;width:58px;text-align:center}
  .sc-mem .face{width:46px;height:46px;border-radius:50%;display:grid;place-items:center;background:var(--card,#fff);border:1.5px solid var(--line);box-shadow:var(--shadow-sm);position:relative}
  .sc-mem .face svg{width:22px;height:22px;color:var(--magenta,#EF4375)}
  .sc-mem .face::after{content:'';position:absolute;right:1px;bottom:1px;width:11px;height:11px;border-radius:50%;background:var(--teal,#00A49F);border:2px solid var(--crema,#F7F5F1)}
  .sc-mem .nm{font-size:11px;font-weight:700;color:var(--tinta);line-height:1.15;max-width:58px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

  /* Hilo de conversación */
  .sc-thread{display:flex;flex-direction:column;gap:14px;padding:8px 0 8px}
  .sc-row{display:flex;gap:9px;max-width:92%;align-items:flex-end}
  .sc-row.ia{align-self:flex-start}
  .sc-row.me{align-self:flex-end;flex-direction:row-reverse}
  .sc-face{width:32px;height:32px;border-radius:50%;flex:none;display:grid;place-items:center;background:var(--card,#fff);border:1.5px solid var(--line);box-shadow:var(--shadow-sm)}
  .sc-face svg{width:17px;height:17px;color:var(--magenta,#EF4375)}
  .sc-bubble{padding:11px 15px;border-radius:18px;font-size:14.5px;line-height:1.55;white-space:pre-wrap;word-wrap:break-word}
  .sc-row.ia .sc-bubble{background:var(--card,#fff);border:1px solid var(--line);color:var(--tinta);border-bottom-left-radius:6px;box-shadow:var(--shadow-sm)}
  .sc-row.me .sc-bubble{background:linear-gradient(135deg,var(--coral,#FF6B3D),var(--magenta,#EF4375));color:#fff;border-bottom-right-radius:6px}
  .sc-who{font-size:11px;font-weight:800;color:var(--magenta,#EF4375);margin-bottom:2px;letter-spacing:.2px}
  .sc-row.ia .sc-bubble.load{color:var(--muted);font-style:italic}
  .sc-dots span{display:inline-block;width:6px;height:6px;border-radius:50%;background:var(--muted);margin:0 1px;animation:scb 1s infinite}
  .sc-dots span:nth-child(2){animation-delay:.15s}.sc-dots span:nth-child(3){animation-delay:.3s}
  @keyframes scb{0%,60%,100%{opacity:.3;transform:translateY(0)}30%{opacity:1;transform:translateY(-3px)}}

  .sc-learn{align-self:flex-start;max-width:92%;background:color-mix(in srgb,var(--teal,#00A49F) 12%,#fff);border:1px solid color-mix(in srgb,var(--teal,#00A49F) 35%,#fff);color:#0a6a5f;border-radius:13px;padding:9px 13px;font-size:12.5px;font-weight:600;line-height:1.4}
  .sc-cta{align-self:flex-start}
  .sc-cta a{display:inline-flex;align-items:center;gap:6px;background:var(--tinta);color:#fff;text-decoration:none;font-weight:800;font-size:13px;padding:10px 16px;border-radius:13px}

  /* Composer: voz al frente */
  .sc-composer{display:flex;gap:9px;align-items:center;position:sticky;bottom:0;z-index:46;background:linear-gradient(to top,var(--crema,#F7F5F1) 74%,transparent);padding:12px 0 6px}
  .sc-input{flex:1;font-family:inherit;font-size:15px;border:1.5px solid var(--line);border-radius:16px;padding:14px 16px;background:var(--card,#fff);color:var(--tinta)}
  .sc-input:focus{outline:0;border-color:var(--magenta,#EF4375)}
  .sc-mic{border:1.5px solid var(--line);background:var(--card,#fff);cursor:pointer;width:52px;height:52px;border-radius:16px;flex:none;display:grid;place-items:center;transition:transform .12s,background .15s,border-color .15s}
  .sc-mic svg{width:23px;height:23px;color:var(--tinta)}
  .sc-mic:active{transform:scale(.94)}
  .sc-mic.rec{background:#e0245e;border-color:transparent;animation:scpulse 1.1s infinite}
  .sc-mic.rec svg{color:#fff}
  @keyframes scpulse{0%,100%{box-shadow:0 0 0 0 rgba(224,36,94,.45)}70%{box-shadow:0 0 0 12px rgba(224,36,94,0)}}
  .sc-send{border:0;cursor:pointer;background:linear-gradient(135deg,var(--coral,#FF6B3D),var(--magenta,#EF4375));color:#fff;font-weight:800;height:52px;padding:0 20px;border-radius:16px;font-family:inherit;font-size:15px;flex:none}
  .sc-send:disabled{opacity:.5;cursor:default}
  .sc-speak{border:1.5px solid var(--line);background:var(--card,#fff);cursor:pointer;width:38px;height:38px;border-radius:11px;display:grid;place-items:center;flex:none}
  .sc-speak svg{width:18px;height:18px;color:var(--tinta)}
  .sc-speak.on{background:linear-gradient(135deg,var(--coral,#FF6B3D),var(--magenta,#EF4375));border-color:transparent}
  .sc-speak.on svg{color:#fff}
  .sc-tools{display:flex;align-items:center;gap:8px}
  .sc-listen{font-size:12px;color:var(--muted);text-align:center;margin:2px 0 0;min-height:16px;font-weight:600}

  @media(max-width:760px){
    .content{padding:16px 14px 8px}
    .sc-thread{padding-bottom:4px}
  }
  /* El bottom-nav (≤860px) es fijo abajo: subimos el compositor para que no lo tape */
  @media(max-width:860px){
    .sc-composer{bottom:calc(60px + env(safe-area-inset-bottom))}
    /*  Y AYUDA, POR ENCIMA DEL COMPOSITOR. El botón flotante se coloca sobre
        el dock, que aquí es exactamente donde vive la caja de escribir: en un
        teléfono de 360 se montaba encima de «Enviar». Es la única pantalla con
        algo fijo en esa banda, así que la corrección vive aquí y no en el
        widget.
        La clase va repetida a propósito: el estilo del widget se imprime al
        final de la página, así que a igual especificidad ganaba él. */
    .ay-fab.ay-fab{bottom:calc(var(--dock-alto, 80px) + 76px + env(safe-area-inset-bottom))}
    /*  Y EL HILO TIENE QUE ACABAR ANTES. Subir el compositor 60px no crea
        sitio: su hueco en el flujo sigue al final del documento, detras de la
        barra, asi que al llegar abajo del todo tapa los ultimos ~60px del
        hilo. Con burbujas daba igual —se leian igual dos lineas mas arriba—,
        pero la tarjeta de eleccion acaba en BOTONES: la tercera salida,
        «Seguir conversando», quedaba entera debajo del compositor y no habia
        forma de llegar a ella. Ningun scroll lo arregla: hay que devolver el
        sitio que la barra se lleva. */
    .sc-thread{padding-bottom:calc(150px + env(safe-area-inset-bottom))}
  }
  /* — LA ELECCIÓN. Botones grandes con la consecuencia dentro: el dueño
       decide sin abrir nada. — */
  /*  `scroll-margin-bottom` es la pieza que hace que esto funcione. El
      compositor esta pegado al fondo, asi que `scrollIntoView({block:'end'})`
      alineaba el final de la tarjeta con el borde de la pantalla y lo dejaba
      DEBAJO de el: la tercera salida —«Seguir conversando»— no se veia, y en
      la capa de confirmacion se perdian los dos botones. Con este margen el
      navegador para antes, justo encima del compositor. */
  .sc-op{align-self:flex-start;max-width:92%;margin:6px 0 2px;padding:13px 14px;
    scroll-margin-bottom:110px;
    background:var(--card,#fff);border:1px solid var(--line,#E9E7E4);border-radius:12px}
  .sc-op > b{display:block;font-size:15px;color:var(--tinta,#231F20);margin-bottom:10px}
  .sc-op-nota{font-size:14px;line-height:1.5;color:var(--muted,#6E6A67);margin:0 0 10px}
  .sc-op-cons{margin:0 0 12px;padding-left:18px}
  .sc-op-cons li{font-size:14px;line-height:1.55;color:var(--muted,#6E6A67);margin-bottom:3px}
  .sc-op-b{display:block;width:100%;text-align:left;min-height:44px;margin-bottom:8px;
    padding:11px 13px;border:1px solid var(--line,#E9E7E4);border-radius:10px;
    background:#fff;cursor:pointer;font:inherit}
  .sc-op-b:last-child{margin-bottom:0}
  .sc-op-b:hover{border-color:var(--teal,#00A49F)}
  .sc-op-b:disabled{opacity:.5;cursor:default}
  .sc-op-b b{display:block;font-size:15px;color:var(--tinta,#231F20)}
  .sc-op-b i{display:block;font-style:normal;font-size:14px;line-height:1.45;
    color:var(--muted,#6E6A67);margin-top:2px}
  .sc-op-b.pri{background:var(--teal,#00A49F);border-color:var(--teal,#00A49F)}
  .sc-op-b.pri b{color:#fff}
  /*  DESPUES de la regla base a proposito: los bloques `@media` de arriba
      estan antes en el fichero y, a igual especificidad, gana el ultimo. Un
      override alli se perdia en silencio y la tarjeta paraba 40px demasiado
      abajo. Aqui el compositor va encima de la barra de navegacion, asi que
      tapa mas que en escritorio. */
  @media(max-width:860px){
    .sc-op{scroll-margin-bottom:calc(150px + env(safe-area-inset-bottom))}
  }
</style>

<div class="sc-top" style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px">
  <div>
    <h1>La Sala del Corillo</h1>
    <p>Tu war room con el equipo. Tira ideas, da órdenes (“hazme 3 posts de X”), fija fechas y ofertas — el corillo aprende y ejecuta.</p>
  </div>
  <button type="button" class="sc-speak" id="sc-speak" title="Que el corillo te conteste en voz"><?= ico('volume') ?></button>
</div>

<div class="sc-team">
  <?php foreach ($roster as $key => $ag): ?>
    <div class="sc-mem" title="<?= $h($ag['hace']) ?>">
      <div class="face"><?= ico($ag['ico']) ?></div>
      <div class="nm"><?= $h(equipo_nombre($marca, $key)) ?></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="sc-thread" id="sc-msgs">
  <div class="sc-row ia">
    <div class="sc-face"><?= $gerente['svg'] ?></div>
    <div class="sc-bubble"><div class="sc-who"><?= $h($gerente['nombre']) ?></div><?= $h($saludo['respuesta']) ?></div>
  </div>
</div>

<form class="sc-composer" id="sc-form" autocomplete="off">
  <button type="button" class="sc-mic" id="sc-mic" title="Hablar"><?= ico('mic') ?></button>
  <input type="text" class="sc-input" id="sc-input" placeholder="Escribe o toca el micrófono para hablar…" maxlength="1000">
  <button type="submit" class="sc-send" id="sc-send">Enviar</button>
</form>
<div class="sc-listen" id="sc-listen"></div>

<script>
(function(){
  var CSRF=<?= json_encode(csrf_token()) ?>, SALUDO=<?= json_encode($saludo['respuesta'], JSON_UNESCAPED_UNICODE) ?>,
      MARCA=<?= (int)$marca_id ?>, GTE=<?= json_encode($gerente, JSON_UNESCAPED_UNICODE) ?>;
  var msgs=document.getElementById('sc-msgs'), form=document.getElementById('sc-form'),
      input=document.getElementById('sc-input'), send=document.getElementById('sc-send'), listen=document.getElementById('sc-listen');
  var hist=[{rol:'ia',texto:SALUDO}];
  function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}
  function scroll(el){el.scrollIntoView({behavior:'smooth',block:'end'});}

  function meBubble(t){
    var row=document.createElement('div'); row.className='sc-row me';
    row.innerHTML='<div class="sc-bubble">'+esc(t)+'</div>';
    msgs.appendChild(row); scroll(row); return row;
  }
  function iaBubble(t){
    var row=document.createElement('div'); row.className='sc-row ia';
    row.innerHTML='<div class="sc-face">'+GTE.svg+'</div><div class="sc-bubble"><div class="sc-who">'+esc(GTE.nombre)+'</div>'+esc(t)+'</div>';
    msgs.appendChild(row); scroll(row); return row;
  }
  function loadBubble(){
    var row=document.createElement('div'); row.className='sc-row ia';
    row.innerHTML='<div class="sc-face">'+GTE.svg+'</div><div class="sc-bubble load"><span class="sc-dots"><span></span><span></span><span></span></span> el corillo lo está viendo…</div>';
    msgs.appendChild(row); scroll(row); return row;
  }
  function learn(items){ if(!items||!items.length) return;
    var d=document.createElement('div');d.className='sc-learn';
    d.innerHTML='<b>El corillo aprendió:</b> '+items.map(esc).join(' · ');
    msgs.appendChild(d); scroll(d);
  }
  function cta(){
    var d=document.createElement('div');d.className='sc-cta';
    d.innerHTML='<a href="/crecer/panel/propuestas.php?marca='+MARCA+'">Ver lo que dejó el corillo</a>';
    msgs.appendChild(d); scroll(d);
  }

  //  ── CÓMO QUIERES TRABAJAR ESTA IDEA ──────────────────────────────────
  //
  //  Una decisión por pantalla y la consecuencia DENTRO de cada opción: el
  //  dueño tiene que poder elegir sin abrir nada ni leer un formulario.
  //
  //  Aquí no se llama a ningún modelo. La propuesta ya vino en el turno que la
  //  produjo; esto pregunta al servidor qué pasaría y después escribe.
  function elegir(job){
    var fd=new FormData(); fd.append('csrf',CSRF); fd.append('oportunidad','ver'); fd.append('job',job);
    fetch(URL,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
      if(!d || !d.ok || !d.opciones || !d.opciones.length) return;
      var caja=document.createElement('div'); caja.className='sc-op'; caja.setAttribute('data-job',job);
      var h='<b>¿Cómo quieres trabajar esta idea?</b>';
      if(d.nota) h+='<p class="sc-op-nota">'+esc(d.nota)+'</p>';
      d.opciones.forEach(function(o){
        h+='<button type="button" class="sc-op-b" data-q="'+esc(o.clave)+'"'
         + (o.href?' data-href="'+esc(o.href)+'"':'')+'>'
         + '<b>'+esc(o.titulo)+'</b><i>'+esc(o.sub)+'</i></button>';
      });
      caja.innerHTML=h;
      msgs.appendChild(caja); scroll(caja);

      caja.addEventListener('click',function(e){
        var b=e.target.closest('.sc-op-b'); if(!b) return;
        var q=b.getAttribute('data-q');
        //  SEGUIR CONVERSANDO no escribe nada: se quita la pregunta y ya.
        if(q==='seguir'){ caja.remove(); input.focus(); return; }
        //  Establecer una Meta es irse a otra pantalla, no una decisión aquí.
        if(q==='meta_nueva'){ location.href=b.getAttribute('data-href'); return; }

        //  LA REPERCUSIÓN, ANTES DE ESCRIBIR. El dueño ve en qué semana entra
        //  y qué NO se mueve; solo entonces confirma.
        if(q==='meta' && !caja.classList.contains('confirmar')){
          caja.classList.add('confirmar');
          var l=(d.lineas||[]).map(function(x){return '<li>'+esc(x)+'</li>';}).join('');
          caja.innerHTML='<b>'+esc(d.titulo||'Esta idea')+'</b><ul class="sc-op-cons">'+l+'</ul>'
            + '<button type="button" class="sc-op-b pri" data-q="meta-ok"><b>Añadirla a mi Meta</b></button>'
            + '<button type="button" class="sc-op-b" data-q="seguir"><b>Ahora no</b></button>';
          scroll(caja); return;
        }

        //  Y AL CONFIRMAR SE ESCRIBE. El botón se apaga —sin esto un doble
        //  toque son dos peticiones— aunque la base ya arbitra por conversación.
        var accion = (q==='meta-ok') ? 'meta' : q;
        [].forEach.call(caja.querySelectorAll('button'),function(x){ x.disabled=true; });
        var f2=new FormData(); f2.append('csrf',CSRF); f2.append('oportunidad',accion); f2.append('job',job);
        fetch(URL,{method:'POST',body:f2}).then(function(r){return r.json();}).then(function(r2){
          if(!r2 || !r2.ok){ caja.remove(); iaBubble((r2&&r2.err)||'No pude con eso. Intenta otra vez.'); return; }
          if(accion==='crear'){ location.href=r2.href; return; }
          caja.remove();
          iaBubble(r2.texto);
          if(r2.href){
            var c=document.createElement('div'); c.className='sc-cta';
            c.innerHTML='<a href="'+esc(r2.href)+'">'+esc(r2.accion||'Ver')+'</a>';
            msgs.appendChild(c); scroll(c);
          }
        }).catch(function(){ caja.remove(); iaBubble('Se cayó la conexión. Nada cambió.'); });
      });
    }).catch(function(){});
  }

  // ── VOZ: que el corillo lea su respuesta (se activa con el 🔈) ──
  var speakOn=false, spk=document.getElementById('sc-speak');
  spk.addEventListener('click',function(){ speakOn=!speakOn; spk.classList.toggle('on',speakOn); if(!speakOn&&window.speechSynthesis)speechSynthesis.cancel(); });
  function decir(t){ if(!speakOn||!('speechSynthesis' in window)) return;
    try{ speechSynthesis.cancel(); var u=new SpeechSynthesisUtterance(t); u.lang='es-US'; u.rate=1.03;
      var vs=speechSynthesis.getVoices(); var v=vs.filter(function(x){return /es(-|_)/i.test(x.lang);})[0]; if(v)u.voice=v;
      speechSynthesis.speak(u);
    }catch(e){}
  }

  var URL=location.pathname+location.search;
  function reactivar(){ input.disabled=false; send.disabled=false; input.focus(); }

  function enviar(t){
    t=(t||'').trim(); if(!t) return;
    meBubble(t); hist.push({rol:'user',texto:t}); input.value=''; input.disabled=true; send.disabled=true;
    var load=loadBubble();
    var fd=new FormData(); fd.append('csrf',CSRF); fd.append('mensaje',t); fd.append('historial',JSON.stringify(hist));
    fetch(URL,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
      if(!d.ok || !d.job){ load.remove(); reactivar(); iaBubble('No pude seguir ahora: '+(d.err||'intenta otra vez')); return; }
      sondear(d.job, load);   // el corillo ya está en eso; seguimos preguntando por el estado
    }).catch(function(){ load.remove(); reactivar(); iaBubble('No pude enviar el mensaje. Intenta otra vez.'); });
  }

  // Polling del job: cada 2s hasta 'done'/'failed'. Aguanta cortes puntuales de red.
  function sondear(job, load){
    var intentos=0, MAX=150, fallosRed=0;   // 150 * 2s = 5 min de margen
    var iv=setInterval(function(){
      intentos++;
      var fd=new FormData(); fd.append('csrf',CSRF); fd.append('job',job);
      fetch(URL,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
        fallosRed=0;
        if(!d.ok || d.estado==='failed'){ clearInterval(iv); load.remove(); reactivar();
          iaBubble('Se me trabó el equipo a mitad. Dame un momento y pídemelo otra vez.'); return; }
        if(d.estado==='done'){ clearInterval(iv); load.remove(); reactivar();
          learn(d.aprendido); iaBubble(d.respuesta); hist.push({rol:'ia',texto:d.respuesta}); decir(d.respuesta);
          //  Y SI LO QUE TRAJO ES UNA OPORTUNIDAD, se le pregunta cómo
          //  quiere trabajarla. Una decisión por pantalla, con la
          //  consecuencia dentro de cada opción — no un formulario.
          if(d.oportunidad) elegir(d.job);
          if(d.accion==='campana') cta(); return; }
        // sigue trabajando: a los ~10s subimos el aviso para producciones largas
        if(intentos===5){ var b=load.querySelector('.sc-bubble'); if(b) b.lastChild.textContent=' el corillo está armando tu contenido… (esto puede tomar un momento)'; }
        if(intentos>=MAX){ clearInterval(iv); load.remove(); reactivar();
          iaBubble('Esto está tardando más de lo normal. El corillo sigue en eso — si pediste contenido, revisa Tus Posts en un ratito.'); }
      }).catch(function(){ if(++fallosRed>=8){ clearInterval(iv); load.remove(); reactivar();
          iaBubble('Se cayó la conexión. El corillo pudo haber seguido — revisa Tus Posts.'); } });
    }, 2000);
  }
  form.addEventListener('submit',function(e){ e.preventDefault(); enviar(input.value); });

  // ── VOZ: dictado del dueño (Web Speech API — Chrome/Android). Habla y se manda solo. ──
  var SR=window.SpeechRecognition||window.webkitSpeechRecognition, mic=document.getElementById('sc-mic'), rec=null, grabando=false;
  if(!SR){ mic.style.display='none'; }
  else {
    rec=new SR(); rec.lang='es-US'; rec.interimResults=true; rec.continuous=false; rec.maxAlternatives=1;
    rec.onresult=function(e){ var fin='',intr='';
      for(var i=0;i<e.results.length;i++){ var t=e.results[i][0].transcript; if(e.results[i].isFinal) fin+=t; else intr+=t; }
      input.value=(fin||intr); };
    rec.onerror=function(){ parar(); };
    rec.onend=function(){ parar(); if((input.value||'').trim()) enviar(input.value); };
    function arrancar(){ try{ input.value=''; rec.start(); grabando=true; mic.classList.add('rec'); listen.textContent='Escuchando… habla y para cuando termines.'; }catch(e){} }
    function parar(){ grabando=false; mic.classList.remove('rec'); listen.textContent=''; }
    mic.addEventListener('click',function(){ if(grabando){ try{rec.stop();}catch(e){} } else { arrancar(); } });
  }
})();
</script>

<?php
// EL RECIBIMIENTO — la primera vez que esta cuenta entra a esta pantalla.
// Su JS espera al 'load', así el botón Ayuda ya existe cuando lo ilumine.
require_once __DIR__ . '/../includes/tour.php';
tour_montar($pdo, $marca_id, 'sala');
require __DIR__ . '/_shell_foot.php'; ?>
