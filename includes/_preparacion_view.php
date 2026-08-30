<?php
// ============================================================
//  CRECER - PANTALLA DE PREPARACION DEL PRIMER POST
//  includes/_preparacion_view.php
//
//  La incluye panel/gateway_post.php mientras muestra_estado() no diga 'listo'.
//  Espera en el ambito: $prep (muestra_estado), $marca, $marca_id, $nombre?,
//  $gwq, y csrf_token().
//
//  ESTO FUE UN PANEL DE DIAGNOSTICO, LUEGO UNA ESPERA CORRECTA, DESPUES UNA
//  SILUETA BONITA, Y AHORA ES EL POST HACIENDOSE DELANTE DEL DUEÑO.
//
//  El diagnostico enseñaba un 82% gigante, las siete etapas y los agentes que
//  habian corrido. Se quito, y bien: no le vendemos una tuberia de agentes.
//
//  Lo que vino despues era correcto y aun asi no aguantaba: primero un anillo
//  girando, luego una silueta de post con barras grises. Las dos eran pantallas
//  de carga bien resueltas — y ese era el problema. A los veinte segundos ya no
//  hay nada que mirar, y aqui hay que aguantar DOS MINUTOS Y MEDIO.
//
//  LO QUE LO RESUELVE: el post deja de ser una silueta y tiene contenido de
//  verdad en cuanto ese contenido existe.
//
//   · EL CAPTION REAL aparece a los ~40-60s, no al final. Desde ese momento el
//     dueño no espera: LEE SU POST. Eso es lo que sostiene el minuto y medio que
//     queda, y no hay animacion que lo sustituya.
//   · LA IMAGEN QUE SE ESTA CREANDO, descrita en palabras del propio corillo.
//     Es especifica y es sobre su producto, asi que se lee con gusto en vez de
//     mirar un rectangulo gris.
//   · LA IMAGEN REAL, cuando llega, ENTRA EN LA MISMA TARJETA. Sin recarga y sin
//     salto: el hueco ya era suyo, y el post se termina de armar delante del
//     dueño antes de pasar a la pantalla siguiente.
//   · el corillo se percibe trabajando porque la tarjeta se va llenando con
//     cosas SUYAS —su voz, su escena, su texto—, no porque se enumeren agentes
//     ni etapas.
//
//  QUE NO CAMBIA, Y ES LO QUE SOSTIENE LA CONFIANZA:
//   · el estado sale de las COLUMNAS, no de un temporizador. Si nada cambio en
//     la base, nada cambia aqui;
//   · el reloj lo da el servidor (created_at): sobrevive a la recarga y coincide
//     en dos pestañas;
//   · el sondeo sigue al MISMO job y no crea otro. Mismos nombres de accion
//     ('preparacion' y 'reintentar_muestra'), misma cadencia, mismo contrato;
//   · cada desenlace tiene su salida con nombre; ninguno deja esto colgado;
//   · sin porcentaje. La barra se mueve con pct_estimado y el techo de 89 sigue
//     puesto, pero la cifra no se enseña: en una espera larga se atasca en 89 y
//     ahi se queda, que es la sensacion contraria a la que hace falta.
//
//  Y NADA DE LO QUE SE ENSEÑA ES INVENTADO. El texto y la escena salen de la
//  fila —acotada por marca_id— y vienen SANEADOS del servidor: con tope de
//  largo, y descartando cualquier cosa que parezca instruccion interna. Si no
//  hay direccion visual, no se enseña ninguna.
// ============================================================
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$nom = trim((string)($marca['nombre_negocio'] ?? 'tu negocio'));

//  La voz del negocio: lo PRIMERO que se puede enseñar, porque existe desde la
//  entrevista — antes que el texto y antes que la imagen. Dice «te escuchamos»
//  sin prometer nada que no haya pasado todavia. Si no hay voz, no se pone.
$voz = trim((string)($marca['tono_preset'] ?? ''));
if ($voz === '') $voz = trim((string)($marca['voz'] ?? ''));
$voz = mb_substr(trim(preg_replace('~\s+~u', ' ', $voz) ?? ''), 0, 48);

$prep_terminal = in_array(($prep['degradado'] ?? ''), ['definitivo', 'rechazo', 'arranque'], true);
$prep_copy     = $prep['pieza_copy']   ?? null;
$prep_visual   = (string)($prep['pieza_visual'] ?? '');
$prep_img      = $prep['pieza_img']    ?? null;
$prep_fase     = $prep_copy ? (!empty($prep['tarde']) ? 'tarde' : 'copy') : 'pensando';
?><!doctype html>
<html lang="es"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Estamos creando tu primer post — <?= $h($nom) ?></title>
<link rel="icon" type="image/png" href="/crecer/assets/brand/crecer-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="/crecer/assets/encuentralo-ui.css?v=<?= ASSET_VER ?>" rel="stylesheet">
<style>
  *{box-sizing:border-box}
  body{background:#fbfaf9;color:var(--tinta,#231F20);margin:0;
       min-height:100dvh;display:flex;flex-direction:column}
  /* El fondo de Crecer, el mismo de siempre: el cliente tiene que reconocer
     donde esta. Mas suave que en el resto del producto porque aqui se queda dos
     o tres minutos mirando. */
  body::before{content:"";position:fixed;inset:0;z-index:-1;pointer-events:none;
    background:radial-gradient(58% 42% at 92% -6%, color-mix(in srgb,var(--magenta,#EF4375) 8%,transparent), transparent 70%),
      radial-gradient(54% 42% at -8% 106%, color-mix(in srgb,var(--teal,#00A49F) 8%,transparent), transparent 72%)}

  .marca{display:flex;align-items:center;gap:9px;padding:calc(env(safe-area-inset-top,0px) + 16px) 20px 12px}
  .marca img{height:25px;width:auto;display:block}
  .marca b{font-weight:800;font-size:16px;letter-spacing:-.02em}
  .marca .t{color:var(--teal,#00A49F);display:inline-block;animation:latido 4.2s ease-in-out infinite}
  @keyframes latido{0%,100%{opacity:1}50%{opacity:.35}}

  .centro{flex:1;display:flex;flex-direction:column;min-height:0;
    padding:0 20px calc(env(safe-area-inset-bottom,0px) + 20px)}
  .dos{flex:1;display:flex;flex-direction:column;min-height:0;width:100%;gap:18px}

  /* ══ EL POST. No es una silueta: es la pieza, con lo que de ella exista.
     Sobre el papel, sin card dentro de card. ══════════════════════════════ */
  .post{background:#fff;border:1px solid var(--line,#E9E7E4);border-radius:18px;
    box-shadow:0 1px 2px rgba(35,31,32,.04), 0 16px 38px -20px rgba(35,31,32,.18);
    overflow:hidden;display:flex;flex-direction:column;width:100%;max-width:340px;margin:0 auto}

  .cab{display:flex;align-items:center;gap:9px;padding:12px 13px 11px;min-width:0}
  .cab u{width:26px;height:26px;border-radius:50%;flex:none;display:block;
    background:linear-gradient(135deg,color-mix(in srgb,var(--teal,#00A49F) 60%,#fff),color-mix(in srgb,var(--magenta,#EF4375) 50%,#fff))}
  .cab .n{min-width:0;display:flex;flex-direction:column;gap:1px}
  /* El nombre lo escribe el dueño y no lo valida nadie: UNA linea siempre, se
     recorta, y nunca empuja la caja. */
  .cab .n s{text-decoration:none;font-size:12.5px;font-weight:700;letter-spacing:-.01em;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .cab .n em{font-style:normal;font-size:10.5px;color:var(--muted,#6E6A67);letter-spacing:.01em;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

  /* ── EL AREA VISUAL. Tiene que sostener minuto y medio, asi que no es un
     rectangulo gris: es un revelado. Manchas de color muy suaves que van y
     vienen despacio, como una foto apareciendo. */
  .lienzo{position:relative;aspect-ratio:4/3;overflow:hidden;
    background:linear-gradient(160deg,#f6f4f2,#efedeb)}
  .mancha{position:absolute;border-radius:50%;filter:blur(24px);opacity:.62;
    will-change:transform;transition:opacity .8s ease}
  .m1{width:62%;aspect-ratio:1;left:-8%;top:-12%;background:color-mix(in srgb,var(--teal,#00A49F) 34%,#fff);
      animation:vaga1 26s ease-in-out infinite}
  .m2{width:56%;aspect-ratio:1;right:-10%;top:18%;background:color-mix(in srgb,var(--magenta,#EF4375) 28%,#fff);
      animation:vaga2 31s ease-in-out infinite}
  .m3{width:48%;aspect-ratio:1;left:26%;bottom:-16%;background:color-mix(in srgb,#F5A623 26%,#fff);
      animation:vaga3 36s ease-in-out infinite}
  @keyframes vaga1{0%,100%{transform:translate(0,0) scale(1)}50%{transform:translate(22%,16%) scale(1.14)}}
  @keyframes vaga2{0%,100%{transform:translate(0,0) scale(1.06)}50%{transform:translate(-18%,12%) scale(.9)}}
  @keyframes vaga3{0%,100%{transform:translate(0,0) scale(.96)}50%{transform:translate(-14%,-18%) scale(1.12)}}
  /* En la tardanza NO se acelera: acelerar seria fingir urgencia. Se ralentiza,
     que es lo que de verdad esta pasando. */
  body[data-fase="tarde"] .m1{animation-duration:36s}
  body[data-fase="tarde"] .m2{animation-duration:42s}
  body[data-fase="tarde"] .m3{animation-duration:48s}

  /* El velo que lo une: sin el, las manchas se ven como tres circulos. Su mitad
     de abajo cierra mas de lo que pediria la estetica, porque ahi va texto
     BLANCO y sobre pastel claro no se leia. Contraste primero. */
  .lienzo::after{content:"";position:absolute;inset:0;z-index:1;transition:background 1.2s ease;
    background:linear-gradient(180deg,
      rgba(255,255,255,.34) 0%, rgba(255,255,255,.05) 34%,
      rgba(35,31,32,.30) 68%, rgba(35,31,32,.62) 100%)}
  /* Sin texto encima, el velo oscuro solo es una mancha sucia en el borde. */
  body[data-fase="pensando"] .lienzo::after{
    background:linear-gradient(180deg,
      rgba(255,255,255,.34) 0%, rgba(255,255,255,.04) 40%, rgba(35,31,32,.10) 100%)}

  /* LA IMAGEN REAL. Ocupa EXACTAMENTE el mismo hueco que el revelado, asi que
     al entrar no mueve un pixel. */
  .foto{position:absolute;inset:0;z-index:2;width:100%;height:100%;object-fit:cover;
    opacity:0;transition:opacity .8s ease}
  .foto.puesta{opacity:1}
  /* Con la imagen puesta se retiran el revelado, su velo y la descripcion: ya no
     hay nada que insinuar, esta la cosa de verdad. */
  body[data-img="si"] .mancha{opacity:0}
  body[data-img="si"] .lienzo::after{background:transparent}
  body[data-img="si"] .pincel{opacity:0;transition:opacity .5s ease;pointer-events:none}

  /* LA IMAGEN QUE SE ESTA CREANDO, en palabras del corillo. Es el texto que
     sostiene esta parte de la pantalla: no es un rotulo tecnico, es la escena. */
  .pincel{position:absolute;left:0;right:0;bottom:0;z-index:3;padding:13px 14px 12px;
    color:#fff;display:flex;gap:8px;align-items:flex-start}
  .pincel .ic{width:15px;height:15px;flex:none;margin-top:2px;opacity:.9}
  /* El rotulo va FUERA del parrafo recortado: dentro contaba como una de las
     tres lineas y se comia un tercio de la descripcion. */
  .pincel p{margin:0;font-size:12px;line-height:1.45;font-weight:500;
    text-shadow:0 1px 12px rgba(35,31,32,.6);
    display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
  .pincel .et{display:block;font-size:9.5px;font-weight:700;letter-spacing:.09em;
    text-transform:uppercase;opacity:.8;margin-bottom:4px;text-shadow:0 1px 10px rgba(35,31,32,.6)}

  /* ── EL TEXTO DEL POST. El momento que lo cambia todo. ─────────────────
     La altura se reserva ANTES de que llegue: sin el minimo, la tarjeta era 90px
     mas baja mientras se escribia y pegaba un salto al aparecer el copy — justo
     en el momento que queremos que se disfrute. */
  .cuerpo{padding:13px 14px 15px;border-top:1px solid #f2f0ee;min-height:162px;
    display:flex;align-items:flex-start}
  body[data-fase="pensando"] .cuerpo{align-items:center;justify-content:center}
  .cap{margin:0;font-size:14px;line-height:1.58;white-space:pre-wrap;color:var(--tinta,#231F20);
    display:-webkit-box;-webkit-line-clamp:7;-webkit-box-orient:vertical;overflow:hidden}
  /* Se revela UNA vez, al pasar el hecho. Al recargar entra puesto y sin
     animarse: el truco no se repite cada vez que vuelve a la pestaña. */
  .cap.entra{animation:aparece .85s cubic-bezier(.22,1,.36,1) both}
  @keyframes aparece{from{opacity:0;transform:translateY(7px)}to{opacity:1;transform:none}}

  /* Mientras no hay texto: NO barras grises. Una linea honesta y un cursor que
     late — se esta escribiendo, y eso es exactamente lo que pasa. */
  .escribiendo{display:flex;align-items:center;gap:8px;color:var(--muted,#6E6A67);font-size:13px}
  .escribiendo i{width:2px;height:15px;background:var(--teal,#00A49F);display:block;
    animation:cursor 1.15s steps(1) infinite}
  @keyframes cursor{0%,49%{opacity:1}50%,100%{opacity:0}}

  /* ── EL BLOQUE DE ESTADO ─────────────────────────────────────────────── */
  .decir{text-align:center;max-width:36ch;margin:0 auto;width:100%}
  h1{font-weight:700;font-size:clamp(19px,5vw,23px);letter-spacing:-.028em;margin:0 0 7px;
     line-height:1.22;text-wrap:balance}
  .dice{font-size:14.5px;line-height:1.5;color:#4a4542;margin:0;min-height:2.9em;
    transition:opacity .45s ease}
  .dice.cambiando{opacity:0}
  .avance{max-width:300px;margin:13px auto 0}
  .barra{height:3px;border-radius:99px;background:rgba(35,31,32,.07);overflow:hidden}
  .barra i{display:block;height:100%;width:0;border-radius:99px;background:var(--teal,#00A49F);
    transition:width 1.1s cubic-bezier(.4,0,.2,1)}
  .tiempo{margin-top:6px;text-align:center;font-size:11px;color:#9a948f;font-variant-numeric:tabular-nums}
  .humana{text-align:center;font-size:12.5px;line-height:1.5;color:var(--muted,#6E6A67);
    margin:13px auto 0;max-width:30ch}

  /* El texto salvado, solo cuando el arte no va a existir. */
  .salvado{margin-top:16px;padding:15px 16px;border-radius:14px;background:#fff;
           border:1px solid var(--line,#E9E7E4);text-align:left}
  .salvado .tt{font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;
               color:var(--muted,#6E6A67);margin-bottom:9px}
  .salvado p{margin:0 0 12px;font-size:14.5px;line-height:1.6;white-space:pre-wrap}
  .acc{display:flex;flex-direction:column;gap:10px;margin-top:16px}
  .acc button{width:100%;min-height:48px;padding:13px 18px;border-radius:12px;border:0;
    font:inherit;font-size:15px;font-weight:700;cursor:pointer;
    background:var(--tinta,#231F20);color:#fff}
  .acc button.gho{background:#fff;color:var(--tinta,#231F20);border:1px solid var(--line,#E9E7E4);font-weight:600}
  .acc button[disabled]{opacity:.55;cursor:default}

  /* DESENLACE CERRADO: no hay post en camino, asi que no se enseña su silueta,
     ni barra que ya no progresa, ni «puedes dejar esta pantalla abierta» —
     seria pedirle que espere a nadie. Queda el texto salvado y la accion. */
  body[data-modo="cerrado"] .post,
  body[data-modo="cerrado"] .avance,
  body[data-modo="cerrado"] .humana{display:none}
  body[data-modo="cerrado"] .centro{justify-content:center}
  body[data-modo="cerrado"] .decir{max-width:460px}

  /* ══ ESCRITORIO: el post a un lado, la narracion al otro. Es la regla de
     Native Design del proyecto — no es movil estirado. ══════════════════ */
  @media (min-width:900px){
    .marca{max-width:980px;width:100%;margin:0 auto;padding:26px 0 0}
    .marca img{height:29px} .marca b{font-size:18px}
    .centro{padding:0 40px 36px;justify-content:center}
    .dos{flex:none;display:grid;grid-template-columns:minmax(0,1fr) minmax(0,420px);
      align-items:center;gap:clamp(32px,4vw,64px);max-width:980px;margin:0 auto}
    .post{order:2;max-width:420px}
    .decir{order:1;text-align:left;margin:0;max-width:none}
    h1{font-size:clamp(28px,2.7vw,34px);margin-bottom:12px}
    .dice{font-size:16.5px}
    .avance{margin:24px 0 0;max-width:290px}
    .tiempo{text-align:left}
    .humana{text-align:left;margin:24px 0 0;font-size:13px;max-width:32ch}
    .cap{font-size:14.5px}
    .acc{max-width:330px}
    body[data-modo="cerrado"] .dos{display:block;max-width:560px}
    body[data-modo="cerrado"] .decir{max-width:none}
  }

  /* Movimiento reducido: el revelado se queda quieto y el texto entra sin
     animarse. Todo lo que hay que saber se sigue leyendo. */
  @media (prefers-reduced-motion:reduce){
    .mancha,.marca .t,.escribiendo i{animation:none!important}
    .cap.entra{animation:none}
    .dice,.foto{transition:none}
  }

  @media (max-height:700px) and (max-width:899px){
    .post{max-width:290px}
    .lienzo{aspect-ratio:16/10}
    .cuerpo{min-height:120px}
    .cap{-webkit-line-clamp:4}
    h1{font-size:19px}
    .humana{margin-top:9px;font-size:12px}
  }
</style>
</head>
<body data-fase="<?= $h($prep_fase) ?>" data-img="no"
      data-modo="<?= $prep_terminal ? 'cerrado' : 'trabajando' ?>">

<div class="marca">
  <img src="/crecer/assets/brand/crecer-icon.png" alt="">
  <b>Crecer<span class="t">.</span></b>
</div>

<main class="centro">
 <div class="dos">

  <article class="post">
    <div class="cab">
      <u></u>
      <div class="n">
        <s><?= $h($nom) ?></s>
        <?php if ($voz !== ''): ?><em>en tu voz: <?= $h($voz) ?></em><?php endif; ?>
      </div>
    </div>

    <div class="lienzo">
      <span class="mancha m1"></span><span class="mancha m2"></span><span class="mancha m3"></span>
      <img class="foto" id="foto" alt="" aria-hidden="true">
      <div class="pincel" id="pincel" style="display:none">
        <svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
        <div style="min-width:0">
          <span class="et">La imagen que estamos creando</span>
          <p id="pincelTx"></p>
        </div>
      </div>
    </div>

    <div class="cuerpo">
      <p class="cap" id="cap" style="display:none"></p>
      <div class="escribiendo" id="escribiendo">El corillo está escribiendo tu post<i></i></div>
    </div>
  </article>

  <div class="decir">
    <h1 id="titulo">Estamos creando tu primer post</h1>
    <!-- aria-live: quien use lector de pantalla se entera del cambio de estado,
         que es justo lo unico que cambia aqui. -->
    <p class="dice" id="dice" aria-live="polite"></p>

    <div class="avance">
      <div class="barra" id="track" role="progressbar" aria-valuemin="0" aria-valuemax="100"
           aria-label="Preparando tu primer post"><i id="fill"></i></div>
      <div class="tiempo" id="reloj">0:00</div>
    </div>

    <div class="salvado" id="salvado" style="display:none">
      <div class="tt">Tu post, guardado</div>
      <p id="salvadoTx"></p>
      <button type="button" class="gho" id="btnCopiar" style="min-height:44px;padding:10px 15px;border-radius:10px;border:1px solid var(--line,#E9E7E4);background:#fff;font:inherit;font-size:13.5px;font-weight:600;cursor:pointer">Copiar el texto</button>
    </div>

    <div class="acc" id="acc"></div>

    <p class="humana">Puedes dejar esta pantalla abierta.<br>No tienes que empezar de nuevo.</p>
  </div>

 </div>
</main>

<script>
(function(){
  var URL_ = '/crecer/panel/gateway_post.php?marca=<?= (int)$marca_id ?><?= $gwq ?>';
  var CSRF = '<?= $h(csrf_token()) ?>';
  //  EL ESTADO INICIAL VIENE DEL SERVIDOR, YA RECONSTRUIDO DESDE LA BASE. Por
  //  eso una recarga no arranca en cero ni parpadea: entra pintada.
  var S = <?= json_encode([
      'pct' => (int)$prep['pct'], 'pct_estimado' => (int)$prep['pct_estimado'],
      'estimando' => (bool)$prep['estimando'], 'etapa' => $prep['etapa'],
      'degradado' => $prep['degradado'], 'segundos' => (int)$prep['segundos'],
      'tarde' => (bool)$prep['tarde'], 'listo' => (bool)$prep['listo'],
      'copy_a_salvo' => $prep['copy_a_salvo'] ?? null,
      'pieza_copy'   => $prep_copy,
      'pieza_visual' => $prep_visual,
      'pieza_img'    => $prep_img,
  ], JSON_UNESCAPED_UNICODE) ?>;

  var fill=document.getElementById('fill'), track=document.getElementById('track'),
      dice=document.getElementById('dice'), reloj=document.getElementById('reloj'),
      acc=document.getElementById('acc'), cap=document.getElementById('cap'),
      escribiendo=document.getElementById('escribiendo'), foto=document.getElementById('foto'),
      pincel=document.getElementById('pincel'), pincelTx=document.getElementById('pincelTx'),
      salvado=document.getElementById('salvado'), salvadoTx=document.getElementById('salvadoTx'),
      titulo=document.getElementById('titulo');

  //  EL RELOJ ES DEL SERVIDOR. El cliente solo lo hace correr entre sondeos; en
  //  cada respuesta se recuadra con `segundos`, que sale de created_at. Asi dos
  //  pestañas marcan lo mismo y recargar no reinicia la cuenta.
  var seg = S.segundos;
  function pintaReloj(){
    var m=Math.floor(seg/60), s=seg%60;
    reloj.textContent = m + ':' + (s<10?'0':'') + s;
  }
  setInterval(function(){ seg++; pintaReloj(); }, 1000);

  //  EL TECHO DE 89 SE QUEDA. Ya no se enseña la cifra, pero la barra tampoco
  //  puede llenarse sin evidencia de que la imagen llego: una barra llena con la
  //  pantalla todavia puesta es la misma mentira que un 100%.
  var TECHO = 89, visto = S.pct_estimado;

  function queDice(st){
    if (st.degradado === 'definitivo') return 'No logramos terminar la imagen. Tu texto está guardado — aquí lo tienes.';
    if (st.degradado === 'rechazo')    return 'No pudimos crear la imagen esta vez. Tu texto está a salvo.';
    if (st.degradado === 'arranque')   return 'No pudimos empezar tu post. No es culpa tuya — inténtalo otra vez.';
    //  LA IMAGEN YA LLEGO. Va ANTES que 'tarde' y que todo lo demas: existe un
    //  hueco real —la imagen guardada, el job todavia sin cerrar— en el que la
    //  foto ya se ve en la tarjeta. Si la frase siguiera diciendo «estamos
    //  creando la imagen» estaria contradiciendo a la propia pantalla.
    if (st.pieza_img)                  return 'Tu post está completo.';
    if (st.tarde)                      return 'La imagen está tardando un poco más, pero seguimos trabajando.';
    if (st.degradado === 'recuperable') return 'Seguimos creando tu imagen.';
    if (st.pieza_copy)                 return 'Tu texto ya está. Ahora estamos creando la imagen.';
    return 'El corillo está preparando tu idea.';
  }

  //  LA IMAGEN ENTRA SIN SALTO Y SIN RECARGA.
  //  Se precarga entera antes de enseñarla: si se pusiera el src y ya, se veria
  //  pintarse por bandas dentro de la tarjeta — peor que seguir esperando. Y
  //  como ocupa EXACTAMENTE el hueco del revelado, al aparecer no mueve nada.
  var imgPuesta = null;
  function ponerImagen(url, cb){
    cb = cb || function(){};
    if (!url || url === imgPuesta) { cb(false); return; }
    var pre = new Image();
    pre.onload = function(){
      imgPuesta = url;
      foto.src = url;
      foto.classList.add('puesta');
      document.body.dataset.img = 'si';
      cb(true);
    };
    pre.onerror = function(){ cb(false); };   // si no carga, se sigue esperando
    pre.src = url;
  }

  var teniaCopy = false;
  function pinta(st, primera){
    var p = st.pct_estimado;
    if (st.estimando && p > TECHO) p = TECHO;
    if (p < visto) p = visto; else visto = p;    // un sondeo tardio no baja la barra
    fill.style.width = p + '%';
    track.setAttribute('aria-valuenow', p);

    //  LA TRANSICION SOLO OCURRE CUANDO CAMBIA UN HECHO. Si la frase es la
    //  misma no se toca el nodo: nada parpadea porque si.
    var t = queDice(st);
    if (dice.textContent !== t) {
      if (dice.textContent === '') { dice.textContent = t; }
      else {
        dice.classList.add('cambiando');
        setTimeout(function(){ dice.textContent = t; dice.classList.remove('cambiando'); }, 450);
      }
    }

    var cerrado = (st.degradado === 'definitivo' || st.degradado === 'rechazo' || st.degradado === 'arranque');
    document.body.dataset.modo = cerrado ? 'cerrado' : 'trabajando';

    //  EL TEXTO REAL, EN CUANTO EXISTE. Se revela una sola vez: en la primera
    //  pintada (una recarga) entra puesto y sin animarse, asi que no hay salto.
    if (st.pieza_copy && !teniaCopy){
      teniaCopy = true;
      cap.textContent = st.pieza_copy;
      cap.style.display = '';
      if (!primera) cap.classList.add('entra');
      escribiendo.style.display = 'none';
    } else if (!st.pieza_copy && !teniaCopy){
      escribiendo.style.display = '';
      cap.style.display = 'none';
    }

    //  Y LA ESCENA QUE SE ESTA CREANDO. Si esa pieza no trae direccion visual
    //  —o el servidor la descarto por no ser de fiar— no se inventa ninguna.
    if (st.pieza_visual && pincelTx.textContent !== st.pieza_visual){
      pincelTx.textContent = st.pieza_visual;
      pincel.style.display = '';
    }

    if (!cerrado) document.body.dataset.fase = st.pieza_copy ? (st.tarde ? 'tarde' : 'copy') : 'pensando';

    if (st.degradado === 'definitivo' || st.degradado === 'rechazo') {
      titulo.textContent = 'Tu texto está listo';
    } else if (st.degradado === 'arranque') {
      titulo.textContent = 'No pudimos empezar';
    }

    if (st.copy_a_salvo){ salvado.style.display=''; salvadoTx.textContent = st.copy_a_salvo; }
    else { salvado.style.display='none'; }

    //  UNA SOLA ACCION, Y SOLO CUANDO HACE FALTA. Mientras el trabajo avanza no
    //  hay boton ninguno: no hay nada que el dueño tenga que decidir.
    var botones = '';
    if (st.degradado === 'rechazo' || st.degradado === 'definitivo') {
      botones = '<button id="btnRe">Reintentar imagen</button>';
    } else if (st.degradado === 'arranque') {
      //  Aquí no hay imagen que reintentar: no llegó a haber post.
      botones = '<button id="btnRe">Intentarlo otra vez</button>';
    }
    if (acc.innerHTML !== botones){
      acc.innerHTML = botones;
      var re=document.getElementById('btnRe');
      if(re) re.addEventListener('click', function(){
        re.disabled=true; re.textContent='Arrancando…'; reintentar();
      });
    }
  }

  var yendo = false;
  function aplica(st){
    seg = st.segundos;                 // recuadre con el servidor
    pinta(st, false);
    //  La imagen puede llegar ANTES de que la pieza este lista (falta cerrar el
    //  job). Se pone en cuanto esta, sin esperar a nada.
    if (!st.listo) { ponerImagen(st.pieza_img); return false; }
    if (yendo) return true;
    yendo = true;
    //  Y AL TERMINAR, EL POST SE COMPLETA DELANTE DEL DUEÑO ANTES DE IRSE.
    //  Saltar a la pantalla siguiente en el mismo instante en que llega la
    //  imagen se lleva por delante el unico momento bueno de toda la espera. Si
    //  la imagen acaba de entrar se le da un respiro para verla; si ya estaba
    //  puesta (una recarga), no hay nada que esperar y se pasa de largo.
    ponerImagen(st.pieza_img || st.img, function(entroAhora){
      setTimeout(function(){ location.href = URL_; }, entroAhora ? 1500 : 0);
    });
    return true;
  }

  //  EL SONDEO NO CREA TRABAJO: pregunta. Quien encola es el worker.
  //
  //  VA POR POST Y CON ESTE NOMBRE EXACTO, Y NO ES UN DETALLE DE ESTILO.
  //  gateway_post.php lee `$accion = $_POST['accion']` — solo POST. Al rehacer
  //  esta pantalla lo cambie a un GET `&preparacion=1`: el endpoint no lo
  //  reconocia, caia al final del archivo y devolvia la PAGINA HTML entera.
  //  `r.json()` reventaba, el .catch() reintentaba a los 3 s, y asi para
  //  siempre. La pantalla se quedaba con el estado inicial del servidor —
  //  barra quieta y «preparando tu idea»— aunque el worker hubiera terminado
  //  el post perfectamente. En produccion se vio 3:52 asi.
  //  Los nombres los fija el endpoint: 'preparacion' y 'reintentar_muestra'.
  //  tests/test_preparacion_contrato.php los compara con los del servidor.
  function pedir(accion){
    var fd = new FormData();
    fd.append('accion', accion);
    fd.append('csrf', CSRF);
    return fetch(URL_, {method:'POST', body:fd, credentials:'same-origin'})
      .then(function(r){ return r.json(); });
  }

  function sondear(){
    pedir('preparacion')
      .then(function(st){
        if (!st || typeof st.pct === 'undefined') { setTimeout(sondear, 3000); return; }
        if (aplica(st)) return;
        //  Cadencia por estado: apretado cuando la imagen esta al caer, suelto
        //  cuando aun se esta escribiendo o cuando ya sabemos que tarda.
        var espera = 3000;
        if (st.etapa === 'enviada' && !st.tarde) espera = 1500;
        else if (st.tarde) espera = 5000;
        setTimeout(sondear, espera);
      })
      .catch(function(){ setTimeout(sondear, 3000); });
  }

  //  El nombre lo fija el endpoint: 'reintentar_muestra'. Tambien lo habia
  //  cambiado ('preparacion_reintentar'), asi que el boton de Reintentar imagen
  //  no hacia nada — el dueño lo apretaba y la pantalla seguia igual.
  function reintentar(){
    pedir('reintentar_muestra')
      .then(function(st){ if (st && typeof st.pct !== 'undefined') aplica(st); setTimeout(sondear, 1200); })
      .catch(function(){ setTimeout(sondear, 2000); });
  }

  //  Copiar el texto salvado. Vive fuera de `acc`, asi que se engancha una vez.
  var btnCopiar=document.getElementById('btnCopiar');
  if(btnCopiar) btnCopiar.addEventListener('click', function(){
    var t = salvadoTx.textContent || '';
    var listo = function(){ btnCopiar.textContent='Copiado';
      setTimeout(function(){ btnCopiar.textContent='Copiar el texto'; }, 1800); };
    if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(t).then(listo, function(){}); return; }
    var r=document.createRange(); r.selectNodeContents(salvadoTx);
    var s=window.getSelection(); s.removeAllRanges(); s.addRange(r);
    try { document.execCommand('copy'); listo(); } catch(_){ }
  });

  pintaReloj();
  pinta(S, true);
  ponerImagen(S.pieza_img);       // si al cargar ya habia imagen, entra puesta
  setTimeout(sondear, 1200);
})();
</script>
</body></html>
