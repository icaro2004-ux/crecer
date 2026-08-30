<?php
// ============================================================
//  CRECER - PANTALLA DE PREPARACION DEL PRIMER POST
//  includes/_preparacion_view.php
//
//  La incluye panel/gateway_post.php mientras muestra_estado() no diga 'listo'.
//  Espera en el ambito: $prep (muestra_estado), $marca, $marca_id, $nombre?,
//  $gwq, y csrf_token().
//
//  ESTO ERA UN PANEL DE DIAGNOSTICO Y AHORA ES UNA ESPERA.
//  La version anterior enseñaba un 82% gigante, las siete etapas con su
//  porcentaje cada una, y los agentes que habian corrido con su tarea. Todo
//  cierto y todo verificable — y aun asi, mal: al dueño de una reposteria no le
//  vendemos una tuberia de agentes, le vendemos que alguien le esta haciendo el
//  trabajo. Un tablero de progreso interno dice «esto es complicado»; lo que
//  tiene que decir es «tranquilo, esto va».
//
//  QUE SE FUE, A PROPOSITO:
//   · el porcentaje como protagonista (queda uno diminuto, rotulado «estimado»);
//   · la lista de siete etapas con sus porcentajes;
//   · los nombres de los agentes y sus tareas;
//   · el modelo, el id de la pieza y cualquier identificador;
//   · el parrafo alarmista de la tardanza.
//
//  QUE SE QUEDA, PORQUE ES LO QUE SOSTIENE LA CONFIANZA:
//   · el estado sale de las COLUMNAS, no de un temporizador. La pantalla no
//     inventa avance: si nada cambio en la base, nada cambia aqui.
//   · el reloj lo da el servidor (created_at), asi que sobrevive a la recarga y
//     coincide en dos pestañas;
//   · el sondeo sigue al MISMO job y no crea otro;
//   · cada desenlace tiene una salida con nombre; ninguno deja esto colgado.
//
//  El mensaje cambia SOLO cuando cambia un hecho. No hay frases rotando.
// ============================================================
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$nom = trim((string)($marca['nombre_negocio'] ?? 'tu negocio'));
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
  /* El fondo de Crecer, el mismo de siempre. No se diseña una interfaz paralela. */
  body::before{content:"";position:fixed;inset:0;z-index:-1;pointer-events:none;
    background:radial-gradient(58% 40% at 90% -4%, color-mix(in srgb,var(--magenta,#EF4375) 11%,transparent), transparent 70%),
      radial-gradient(52% 40% at -6% 104%, color-mix(in srgb,var(--teal,#00A49F) 10%,transparent), transparent 72%)}

  .marca{display:flex;align-items:center;gap:8px;padding:18px 20px calc(env(safe-area-inset-top,0px) + 0px)}
  .marca img{height:20px;width:auto}
  .marca b{font-weight:800;font-size:14.5px;letter-spacing:-.01em}
  .marca .t{color:var(--teal,#00A49F)}

  /* UNA SOLA IDEA VISIBLE, centrada en lo que queda de pantalla. */
  .centro{flex:1;display:flex;flex-direction:column;justify-content:center;
          max-width:460px;width:100%;margin:0 auto;
          padding:0 24px calc(env(safe-area-inset-bottom,0px) + 28px)}

  /* La animacion de creacion: tres trazos que respiran. Es decoracion declarada
     — no representa avance, y con reduccion de movimiento se queda quieta. */
  .crea{width:78px;height:78px;margin:0 auto 26px;position:relative}
  .crea i{position:absolute;inset:0;border-radius:50%;
    border:2px solid color-mix(in srgb,var(--teal,#00A49F) 30%,transparent)}
  .crea i:nth-child(1){animation:resp 3.4s ease-in-out infinite}
  .crea i:nth-child(2){animation:resp 3.4s ease-in-out infinite .9s}
  .crea i:nth-child(3){animation:resp 3.4s ease-in-out infinite 1.8s}
  .crea u{position:absolute;inset:34%;border-radius:50%;background:var(--teal,#00A49F);opacity:.85;
    animation:pulso 3.4s ease-in-out infinite}
  @keyframes resp{0%{transform:scale(.62);opacity:.1}50%{transform:scale(1);opacity:.5}100%{transform:scale(.62);opacity:.1}}
  @keyframes pulso{0%,100%{transform:scale(.82)}50%{transform:scale(1.06)}}
  @media (prefers-reduced-motion:reduce){
    .crea i,.crea u{animation:none}
    .crea i:nth-child(1){opacity:.32}.crea i:nth-child(2),.crea i:nth-child(3){opacity:0}
  }

  h1{font-weight:700;font-size:clamp(23px,6vw,29px);letter-spacing:-.025em;
     text-align:center;margin:0 0 14px;line-height:1.2}

  /* EL MENSAJE. Cambia solo cuando cambia un hecho. */
  .dice{text-align:center;font-size:16px;line-height:1.55;color:var(--tinta,#231F20);
        margin:0 auto 26px;max-width:24em;min-height:2.9em}

  /* La barra, discreta. Sin numero encima. */
  .barra{height:5px;border-radius:99px;background:rgba(0,0,0,.07);overflow:hidden}
  .barra i{display:block;height:100%;width:0;border-radius:99px;
    background:linear-gradient(90deg,var(--teal,#00A49F),var(--magenta,#EF4375));
    transition:width .9s cubic-bezier(.4,0,.2,1)}
  .bajo{display:flex;justify-content:space-between;align-items:baseline;
        margin-top:9px;font-size:11.5px;color:var(--muted,#6b6560);letter-spacing:.01em}
  .bajo .pc{font-variant-numeric:tabular-nums;opacity:.75}

  .humana{text-align:center;font-size:13px;line-height:1.6;color:var(--muted,#6b6560);
          margin-top:30px}

  /* El texto salvado, solo cuando el arte no va a existir. */
  .salvado{margin-top:24px;padding:16px 17px;border-radius:14px;background:#fff;
           border:1px solid var(--line,#E9E7E4)}
  .salvado .tt{font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;
               color:var(--muted,#6b6560);margin-bottom:9px}
  .salvado p{margin:0 0 12px;font-size:14.5px;line-height:1.6;white-space:pre-wrap}

  .acc{display:flex;flex-direction:column;gap:10px;margin-top:22px}
  .acc button{width:100%;min-height:48px;padding:13px 18px;border-radius:12px;border:0;
    font:inherit;font-size:15px;font-weight:700;cursor:pointer;
    background:var(--tinta,#231F20);color:#fff}
  .acc button.gho{background:#fff;color:var(--tinta,#231F20);border:1px solid var(--line,#E9E7E4);font-weight:600}
  .acc button[disabled]{opacity:.55;cursor:default}

  /* 360x800: lo esencial entra sin scroll. */
  @media (max-height:720px){
    .crea{width:62px;height:62px;margin-bottom:20px}
    h1{font-size:22px;margin-bottom:11px}
    .dice{font-size:15px;margin-bottom:20px}
    .humana{margin-top:22px}
  }
</style>
</head>
<body>

<div class="marca">
  <img src="/crecer/assets/brand/crecer-icon.png" alt="">
  <b>Crecer<span class="t">.</span></b>
</div>

<main class="centro">
  <div class="crea" aria-hidden="true"><i></i><i></i><i></i><u></u></div>

  <h1 id="titulo">Estamos creando tu primer post</h1>

  <!-- aria-live: quien use lector de pantalla se entera del cambio de estado,
       que es justo lo unico que cambia aqui. -->
  <p class="dice" id="dice" aria-live="polite"></p>

  <div class="barra" id="track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-label="Preparando tu primer post">
    <i id="fill"></i>
  </div>
  <div class="bajo">
    <span id="reloj">0:00</span>
    <span class="pc" id="pc"></span>
  </div>

  <div class="salvado" id="salvado" style="display:none">
    <div class="tt">Tu post, guardado</div>
    <p id="salvadoTx"></p>
    <button type="button" class="gho" id="btnCopiar" style="min-height:44px;padding:10px 15px;border-radius:10px;border:1px solid var(--line,#E9E7E4);background:#fff;font:inherit;font-size:13.5px;font-weight:600;cursor:pointer">Copiar el texto</button>
  </div>

  <div class="acc" id="acc"></div>

  <p class="humana">Puedes dejar esta pantalla abierta.<br>No tienes que empezar de nuevo.</p>
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
  ], JSON_UNESCAPED_UNICODE) ?>;

  var fill=document.getElementById('fill'), track=document.getElementById('track'),
      dice=document.getElementById('dice'), reloj=document.getElementById('reloj'),
      pc=document.getElementById('pc'), acc=document.getElementById('acc'),
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

  //  EL TECHO DE 89 VIVE TAMBIEN AQUI. Es el numero que el dueño ve, y no puede
  //  pasar de ahi sin evidencia de que la imagen llego.
  var TECHO = 89, visto = S.pct_estimado;

  //  LOS CUATRO ESTADOS, Y NADA MAS. Cada uno responde a un HECHO de la base:
  //  no hay quinto mensaje ni frases alternandose para simular vida.
  function queDice(st){
    if (st.degradado === 'definitivo') return 'No logramos terminar la imagen. Tu texto está guardado — aquí lo tienes.';
    if (st.degradado === 'rechazo')    return 'No pudimos crear la imagen esta vez. Tu texto está a salvo.';
    //  'arranque': el trabajo no llegó a empezar. Es el estado que antes no
    //  existía y dejaba la barra quieta sin decir nada.
    if (st.degradado === 'arranque')   return 'No pudimos empezar tu post. No es culpa tuya — inténtalo otra vez.';
    if (st.tarde)                      return 'La imagen está tardando un poco más, pero seguimos trabajando.';
    //  «enviada» es la etapa en que el arte ya salio: el texto, por tanto, existe.
    if (st.etapa === 'enviada' || st.etapa === 'recibida') return 'Tu texto está listo. Ahora estamos creando la imagen.';
    return 'El corillo está preparando tu idea.';
  }

  function pinta(st){
    var p = st.pct_estimado;
    if (st.estimando && p > TECHO) p = TECHO;
    if (p < visto) p = visto; else visto = p;    // un sondeo tardio no hace bajar la barra
    fill.style.width = p + '%';
    track.setAttribute('aria-valuenow', p);
    //  El porcentaje existe, pero es secundario y va rotulado. Nunca es el
    //  elemento principal, y desaparece en cuanto deja de ser una estimacion.
    pc.textContent = st.estimando ? (p + '% estimado') : '';

    var t = queDice(st);
    if (dice.textContent !== t) dice.textContent = t;    // solo se toca si cambio de verdad

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

  function aplica(st){
    seg = st.segundos;                 // recuadre con el servidor
    pinta(st);
    if (st.listo) { location.href = URL_; return true; }
    return false;
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
  pinta(S);
  setTimeout(sondear, 1200);
})();
</script>
</body></html>
