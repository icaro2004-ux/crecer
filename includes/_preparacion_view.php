<?php
// ============================================================
//  CRECER - PANTALLA DE PREPARACION DEL PRIMER POST
//  includes/_preparacion_view.php
//
//  La incluye panel/gateway_post.php mientras muestra_estado() no diga 'listo'.
//  Espera en el ambito: $prep (muestra_estado), $marca, $marca_id, $nombre?,
//  $gwq, y csrf_token().
//
//  LO QUE ESTA PANTALLA PROMETE Y CUMPLE:
//   · barra + porcentaje ESTIMADO (etiquetado como tal, nunca presentado como
//     medida del trabajo del proveedor);
//   · la etapa actual, sacada de columnas;
//   · el tiempo transcurrido, que sale del servidor (created_at) y por eso
//     sobrevive a la recarga y coincide en dos pestañas;
//   · los agentes que YA corrieron, leidos de crecer_ia_log;
//   · sondeo persistente del MISMO job, sin crear otro;
//   · una salida con nombre para cada desenlace: nunca un spinner eterno.
//
//  NO hay aqui ningun array de frases rotando: cada texto que cambia lo cambia
//  un dato. Lo unico que se mueve solo es la respiracion del anillo, y eso es
//  decoracion declarada — no dice nada sobre el progreso.
// ============================================================
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$nom = trim((string)($marca['nombre_negocio'] ?? 'tu negocio'));
?><!doctype html>
<html lang="es"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Montando tu primer post — <?= $h($nom) ?></title>
<link rel="icon" type="image/png" href="/crecer/assets/brand/crecer-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="/crecer/assets/encuentralo-ui.css?v=<?= ASSET_VER ?>" rel="stylesheet">
<style>
  *{box-sizing:border-box}
  body{background:#fbfaf9;color:var(--tinta,#231F20);min-height:100dvh;margin:0}
  body::before{content:"";position:fixed;inset:0;z-index:-1;pointer-events:none;
    background:radial-gradient(58% 40% at 90% -4%, color-mix(in srgb,var(--magenta,#EF4375) 13%,transparent), transparent 70%),
      radial-gradient(52% 40% at -6% 104%, color-mix(in srgb,var(--teal,#00A49F) 12%,transparent), transparent 72%)}
  .bar-top{display:flex;align-items:center;gap:9px;padding:14px 18px}
  .bar-top img{height:24px}
  .bar-top b{font-weight:800;font-size:16px;letter-spacing:-.01em}
  .bar-top .t{color:var(--teal,#00A49F)}
  .wrap{max-width:560px;margin:0 auto;padding:8px 20px 64px}

  /* El anillo respira. Es decoracion y esta declarado como tal: no representa avance. */
  .ring{width:96px;height:96px;margin:14px auto 4px;position:relative}
  .ring i{position:absolute;inset:0;border-radius:50%;border:3px solid color-mix(in srgb,var(--teal,#00A49F) 22%,transparent)}
  .ring i:nth-child(1){animation:resp 3.2s ease-in-out infinite}
  .ring i:nth-child(2){animation:resp 3.2s ease-in-out infinite .8s}
  .ring i:nth-child(3){animation:resp 3.2s ease-in-out infinite 1.6s}
  @keyframes resp{0%{transform:scale(.72);opacity:.15}50%{transform:scale(1);opacity:.55}100%{transform:scale(.72);opacity:.15}}
  .ring b{position:absolute;inset:0;display:grid;place-items:center;font-size:23px;font-weight:800;color:var(--teal,#00A49F)}
  @media (prefers-reduced-motion:reduce){.ring i{animation:none;opacity:.3}}

  h1{font-weight:700;font-size:clamp(21px,5vw,27px);letter-spacing:-.02em;text-align:center;margin:6px 0 4px;line-height:1.25}
  .sub{text-align:center;color:var(--muted,#6b6560);font-size:14px;margin-bottom:18px}
  .sub b{color:var(--tinta,#231F20);font-weight:600}

  .track{height:9px;border-radius:99px;background:color-mix(in srgb,var(--tinta,#231F20) 8%,transparent);overflow:hidden}
  .track i{display:block;height:100%;border-radius:99px;width:0;
    background:linear-gradient(90deg,var(--teal,#00A49F),var(--magenta,#EF4375));
    transition:width .9s cubic-bezier(.4,0,.2,1)}
  .meta{display:flex;justify-content:space-between;align-items:center;margin-top:9px;font-size:12.5px;color:var(--muted,#6b6560)}
  .meta .est{font-variant-numeric:tabular-nums}

  .pasos{list-style:none;margin:22px 0 0;padding:0;display:grid;gap:2px}
  .pasos li{display:flex;align-items:center;gap:11px;padding:9px 12px;border-radius:11px;font-size:14px;color:var(--muted,#6b6560)}
  .pasos li .dot{width:19px;height:19px;border-radius:50%;flex:0 0 19px;display:grid;place-items:center;
    border:2px solid color-mix(in srgb,var(--tinta,#231F20) 14%,transparent);font-size:11px;font-weight:800}
  .pasos li .pc{margin-left:auto;font-size:11.5px;opacity:.6;font-variant-numeric:tabular-nums}
  .pasos li.hecho{color:var(--tinta,#231F20)}
  .pasos li.hecho .dot{background:var(--teal,#00A49F);border-color:var(--teal,#00A49F);color:#fff}
  .pasos li.ahora{color:var(--tinta,#231F20);font-weight:600;background:color-mix(in srgb,var(--teal,#00A49F) 7%,transparent)}
  .pasos li.ahora .dot{border-color:var(--teal,#00A49F);animation:lat 1.6s ease-in-out infinite}
  @keyframes lat{0%,100%{box-shadow:0 0 0 0 color-mix(in srgb,var(--teal,#00A49F) 45%,transparent)}60%{box-shadow:0 0 0 7px transparent}}

  .equipo{margin-top:20px;border-top:1px solid var(--line,#E9E7E4);padding-top:14px}
  .equipo .tt{font-size:11.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--muted,#6b6560);margin-bottom:9px}
  .equipo ul{list-style:none;margin:0;padding:0;display:grid;gap:6px}
  .equipo li{font-size:13.2px;color:var(--muted,#6b6560);display:flex;gap:8px;align-items:baseline}
  .equipo li b{color:var(--tinta,#231F20);font-weight:600;flex:0 0 auto}

  .aviso{margin-top:18px;padding:13px 15px;border-radius:12px;font-size:13.5px;line-height:1.5;
    background:color-mix(in srgb,var(--magenta,#EF4375) 7%,#fff);border:1px solid color-mix(in srgb,var(--magenta,#EF4375) 18%,transparent)}
  .acc{margin-top:16px;display:grid;gap:9px}
  .acc button{width:100%;padding:14px;border-radius:12px;border:0;font:inherit;font-weight:700;font-size:15px;cursor:pointer;
    background:var(--teal,#00A49F);color:#fff}
  .acc button.gho{background:#fff;color:var(--tinta,#231F20);border:1px solid var(--line,#E9E7E4)}
  .acc button[disabled]{opacity:.5;cursor:default}
  .pie{margin-top:20px;text-align:center;font-size:12.5px;color:var(--muted,#6b6560);line-height:1.6}

  .salvado{margin-top:16px;padding:15px 16px;border-radius:12px;background:#fff;border:1px solid var(--line,#E9E7E4)}
  .salvado .tt{font-size:11.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--muted,#6b6560);margin-bottom:8px}
  .salvado p{margin:0 0 11px;font-size:14.5px;line-height:1.6;white-space:pre-wrap}
  .salvado .cop{padding:9px 14px;border-radius:9px;border:1px solid var(--line,#E9E7E4);background:#fff;
    font:inherit;font-size:13.5px;font-weight:600;cursor:pointer}
</style>
</head>
<body>
<div class="bar-top">
  <img src="/crecer/assets/brand/crecer-icon.png" alt="">
  <b><span class="t">Crecer</span></b>
</div>

<div class="wrap">
  <div class="ring" aria-hidden="true"><i></i><i></i><i></i><b id="pcRing">0%</b></div>

  <h1 id="titulo"><?= $h($prep['titulo']) ?></h1>
  <div class="sub" id="sub">Tu primer post para <b><?= $h($nom) ?></b></div>

  <div class="track" role="progressbar" aria-valuemin="0" aria-valuemax="100"
       aria-valuenow="<?= (int)$prep['pct_estimado'] ?>" id="track"><i id="fill"></i></div>
  <div class="meta">
    <span id="etiqueta"><?= $prep['estimando'] ? 'Progreso estimado' : 'Progreso' ?></span>
    <span class="est"><span id="reloj">0:00</span></span>
  </div>

  <ul class="pasos" id="pasos">
    <?php foreach ($prep['etapas'] as $e): ?>
      <li class="<?= $h($e['estado']) ?>" data-clave="<?= $h($e['clave']) ?>">
        <span class="dot"><?= $e['estado'] === 'hecho' ? '&#10003;' : '' ?></span>
        <span class="tx"><?= $h($e['texto']) ?></span>
        <span class="pc"><?= (int)$e['pct'] ?>%</span>
      </li>
    <?php endforeach; ?>
  </ul>

  <div class="equipo" id="equipo" style="<?= empty($prep['agentes']) ? 'display:none' : '' ?>">
    <div class="tt">Tu corillo, trabajando</div>
    <ul id="equipoLista">
      <?php foreach ($prep['agentes'] as $a): ?>
        <li><b><?= $h($a['quien']) ?></b><span><?= $h($a['que']) ?></span></li>
      <?php endforeach; ?>
    </ul>
  </div>

  <div class="aviso" id="aviso" style="display:none"></div>

  <!-- El copy a salvo: solo se puebla en el fallo definitivo (ver mensajes()). -->
  <div class="salvado" id="salvado" style="display:none">
    <div class="tt">Tu post, guardado</div>
    <p id="salvadoTx"></p>
    <button type="button" class="cop" id="btnCopiar">Copiar el texto</button>
  </div>

  <div class="acc" id="acc"></div>

  <div class="pie">
    Puedes cerrar esto y volver cuando quieras — el trabajo sigue y lo retomamos donde iba.
  </div>
</div>

<script>
(function(){
  var URL_    = '/crecer/panel/gateway_post.php?marca=<?= (int)$marca_id ?><?= $gwq ?>';
  var CSRF    = '<?= $h(csrf_token()) ?>';
  //  EL ESTADO INICIAL VIENE DEL SERVIDOR, YA RECONSTRUIDO DESDE LA BASE. Por
  //  eso una recarga no arranca en cero: el porcentaje y el reloj entran
  //  pintados con lo que dicen las columnas.
  var S = <?= json_encode([
      'pct' => (int)$prep['pct'], 'pct_estimado' => (int)$prep['pct_estimado'],
      'estimando' => (bool)$prep['estimando'], 'etapa' => $prep['etapa'],
      'titulo' => $prep['titulo'], 'degradado' => $prep['degradado'],
      'segundos' => (int)$prep['segundos'], 'tarde' => (bool)$prep['tarde'],
      'listo' => (bool)$prep['listo'], 'etapas' => $prep['etapas'],
      'copy_a_salvo' => $prep['copy_a_salvo'] ?? null,
  ], JSON_UNESCAPED_UNICODE) ?>;

  var fill=document.getElementById('fill'), pcRing=document.getElementById('pcRing'),
      track=document.getElementById('track'), titulo=document.getElementById('titulo'),
      etiqueta=document.getElementById('etiqueta'), reloj=document.getElementById('reloj'),
      aviso=document.getElementById('aviso'), acc=document.getElementById('acc'),
      salvado=document.getElementById('salvado'), salvadoTx=document.getElementById('salvadoTx'),
      pasos=document.getElementById('pasos'), equipo=document.getElementById('equipo'),
      equipoLista=document.getElementById('equipoLista'), sub=document.getElementById('sub');

  //  EL RELOJ ES DEL SERVIDOR. El cliente solo lo hace correr entre sondeos; en
  //  cada respuesta se vuelve a cuadrar con `segundos`, que sale de created_at.
  //  Asi dos pestañas marcan lo mismo y una recarga no reinicia la cuenta.
  var seg = S.segundos;
  function pintaReloj(){
    var m=Math.floor(seg/60), s=seg%60;
    reloj.textContent = m + ':' + (s<10?'0':'') + s;
  }
  setInterval(function(){ seg++; pintaReloj(); }, 1000);

  //  EL TECHO DE 89 VIVE TAMBIEN AQUI, no solo en el servidor. Es el numero que
  //  el dueño ve, y no puede pasar de ahi sin evidencia de que la imagen llego.
  var TECHO_ESTIMADO = 89;
  var visto = S.pct_estimado;
  function pinta(st){
    var pc = st.pct_estimado;
    if (st.estimando && pc > TECHO_ESTIMADO) pc = TECHO_ESTIMADO;
    //  Nunca retrocede: un sondeo que llega tarde no debe hacer bajar la barra.
    if (pc < visto) pc = visto; else visto = pc;
    fill.style.width = pc + '%';
    pcRing.textContent = pc + '%';
    track.setAttribute('aria-valuenow', pc);
    titulo.textContent = st.titulo;
    etiqueta.textContent = st.estimando ? 'Progreso estimado' : 'Progreso';

    for (var i=0;i<st.etapas.length;i++){
      var e=st.etapas[i], li=pasos.querySelector('[data-clave="'+e.clave+'"]');
      if(!li) continue;
      li.className = e.estado;
      li.querySelector('.dot').innerHTML = (e.estado==='hecho') ? '&#10003;' : '';
    }
    if (st.agentes && st.agentes.length){
      equipo.style.display='';
      equipoLista.innerHTML = st.agentes.map(function(a){
        return '<li><b>'+esc(a.quien)+'</b><span>'+esc(a.que)+'</span></li>'; }).join('');
    }
    mensajes(st);
  }
  function esc(s){ var d=document.createElement('div'); d.textContent=s==null?'':s; return d.innerHTML; }

  //  CADA DESENLACE TIENE NOMBRE Y SALIDA. Ninguno deja la pantalla esperando
  //  sin decir que pasa ni que se puede hacer.
  function mensajes(st){
    var txt='', botones='';
    if (st.degradado === 'incierto'){
      txt = 'Estamos verificando tu imagen con el proveedor. No vamos a pedir otra — si aquella salió, es la que te toca.';
    } else if (st.degradado === 'recuperable'){
      txt = 'El primer intento no cuajó. Ya hay un respaldo trabajando en tu imagen.';
    } else if (st.degradado === 'rechazo'){
      txt = 'No pudimos crear la imagen esta vez. Tu texto está a salvo — podemos intentarlo de nuevo.';
      botones = '<button id="btnRe">Intentar la imagen otra vez</button>';
    } else if (st.degradado === 'definitivo'){
      //  «Conserva el copy» se cumple ENSEÑANDOLO, no prometiendolo: aqui abajo
      //  aparece el texto tal cual quedo guardado, y se puede copiar.
      txt = 'No logramos terminar la imagen. Tu post escrito está guardado — aquí lo tienes.';
      botones = '<button id="btnRe">Intentar la imagen otra vez</button>';
    } else if (st.tarde){
      //  El umbral cambia el MENSAJE, no el estado. Y nunca se le pide al dueño
      //  que recargue ni que vuelva a pedir la imagen: el trabajo es el mismo.
      txt = 'Está tomando más de lo normal, pero tu imagen sigue en proceso.';
    }
    if (txt){ aviso.textContent = txt; aviso.style.display=''; } else { aviso.style.display='none'; }

    //  EL TEXTO QUE SI SE ESCRIBIO. Solo aparece en el fallo definitivo: no es
    //  revelar el post a medias, es no perder lo unico que quedo en pie.
    if (st.copy_a_salvo){
      salvado.style.display='';
      salvadoTx.textContent = st.copy_a_salvo;
    } else { salvado.style.display='none'; }

    if (acc.innerHTML !== botones){
      acc.innerHTML = botones;
      var re=document.getElementById('btnRe');
      if(re) re.addEventListener('click', function(){ re.disabled=true; re.textContent='Arrancando…'; reintentar(); });
    }
  }

  function post(datos){
    var fd=new FormData(); fd.append('csrf', CSRF);
    for (var k in datos) fd.append(k, datos[k]);
    return fetch(URL_, {method:'POST', body:fd, credentials:'same-origin'})
      .then(function(r){ return r.json(); });
  }

  //  EL REVELADO. 100% -> el titulo lo dice -> y solo entonces se pasa al post
  //  completo, donde copy e imagen aparecen juntos y se habilita publicar.
  function revelar(){
    visto = 100;
    fill.style.width='100%'; pcRing.textContent='100%';
    track.setAttribute('aria-valuenow',100);
    titulo.textContent='Tu primer post está listo';
    etiqueta.textContent='Progreso';
    sub.textContent='Te lo enseño completo…';
    aviso.style.display='none'; acc.innerHTML='';
    var ps=pasos.querySelectorAll('li');
    for(var i=0;i<ps.length;i++){ ps[i].className='hecho'; ps[i].querySelector('.dot').innerHTML='&#10003;'; }
    setTimeout(function(){ location.href = URL_; }, 1100);
  }

  var fallos=0;
  function sondear(){
    post({accion:'preparacion'}).then(function(st){
      fallos=0;
      if(!st) return;
      if (typeof st.segundos === 'number') seg = st.segundos;   // el reloj se cuadra con el servidor
      if (st.listo){ pinta(st); revelar(); return; }
      pinta(st);
      setTimeout(sondear, 3000);
    }).catch(function(){
      //  Un tropiezo de red no es un fallo del trabajo: se sigue sondeando. Solo
      //  tras varios seguidos se dice, y aun asi sin mandar a recargar.
      if (++fallos >= 6){
        aviso.textContent='Perdimos la conexión un momento. Seguimos intentando — tu trabajo no se detuvo.';
        aviso.style.display='';
      }
      setTimeout(sondear, 5000);
    });
  }

  function reintentar(){
    post({accion:'reintentar_muestra'}).then(function(st){
      if (st) { if (typeof st.segundos === 'number') seg = st.segundos; pinta(st); }
      setTimeout(sondear, 1500);
    }).catch(function(){ setTimeout(sondear, 3000); });
  }

  //  Copiar el texto salvado. El boton se pinta una vez y vive fuera de acc,
  //  asi que se engancha aqui y no en cada repintado.
  var btnCopiar=document.getElementById('btnCopiar');
  if(btnCopiar) btnCopiar.addEventListener('click', function(){
    var t = salvadoTx.textContent || '';
    var listo = function(){ btnCopiar.textContent='Copiado'; setTimeout(function(){ btnCopiar.textContent='Copiar el texto'; }, 1800); };
    if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(t).then(listo, function(){}); return; }
    //  Sin Clipboard API (http, navegadores viejos): seleccion manual, que
    //  siempre funciona. Peor fracaso posible: el texto queda seleccionado.
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
