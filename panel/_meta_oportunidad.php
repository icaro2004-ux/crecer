<?php
// ============================================================
//  CRECER — UNA FECHA QUE TE PUEDE SERVIR
//  panel/_meta_oportunidad.php  ·  dentro de ?vista=plan
//
//  NO ES UN WIZARD, Y ES A PROPOSITO. Un wizard es para una decision compleja
//  con consecuencias; esto es una sugerencia que se acepta, se descarta o se
//  deja para luego. Meterla en cuatro pasos le daria un peso que no tiene y
//  haria que pareciera obligatoria.
//
//  LAS FECHAS SON SUGERENCIAS. Lo dice la pantalla, con esas palabras, debajo
//  de los botones — y lo cumple el codigo: descartar no toca la meta, ni el
//  plan, ni el progreso.
//
//  TRES CUIDADOS QUE NO SE VEN PERO MANDAN
//
//  1. LAS SUYAS PRIMERO, Y ETIQUETADAS. La fecha que el dueño apunto vale mas
//     que una del catalogo general, y se le nota: va delante y lleva «Tuya».
//
//  2. DESCARTAR UNA FECHA SUYA NO LE BORRA NADA. Se descarta LA OPORTUNIDAD DE
//     CONTENIDO para esa vez; el evento sigue en su calendario. El copy lo dice
//     con todas las letras, porque «Esta no me sirve» a secas se puede leer
//     como «borra la fecha».
//
//  3. LA CUOTA SE AVISA ANTES. Añadir no gasta imagenes —la pieza nace en
//     borrador y sin arte—, y eso se dice aqui para que nadie se entere
//     despues.
// ============================================================

$op_lista = efem_oportunidades($pdo, $marca_id, $meta ?: null);
if (!$op_lista) return;
$op_uno = $op_lista[0];                  // una a la vez: dos ya es una lista de deberes
$op_mas = count($op_lista) - 1;

$OP_MESES = [1 => 'enero','febrero','marzo','abril','mayo','junio','julio','agosto',
             'septiembre','octubre','noviembre','diciembre'];
$OP_DIAS  = ['Sun' => 'domingo','Mon' => 'lunes','Tue' => 'martes','Wed' => 'miércoles',
             'Thu' => 'jueves','Fri' => 'viernes','Sat' => 'sábado'];
$op_ts    = strtotime((string)$op_uno['fecha']);
$op_cuando = ($OP_DIAS[date('D', $op_ts)] ?? '') . ' ' . (int)date('j', $op_ts)
           . ' de ' . ($OP_MESES[(int)date('n', $op_ts)] ?? '');
?>
<style>
  /* — LA TARJETA · un aviso amable, no una tarea más —
     Va en teal (lo que hace el corillo) y no en rosa (lo que TE toca): una
     sugerencia no es una tarea pendiente, y el color no puede decir lo
     contrario de lo que dice el texto. */
  .op-card{border:1px solid var(--line);border-radius:var(--tm-r);background:var(--card,#fff);
    margin-top:22px;overflow:hidden}
  .op-card > .et{display:flex;align-items:center;gap:8px;font-size:14px;font-weight:600;
    letter-spacing:.06em;text-transform:uppercase;color:var(--tm-teal-tx);
    background:var(--tm-teal-piel);padding:10px 15px}
  .op-card > .et .ic{width:16px;height:16px;stroke-width:2}
  .op-cuerpo{padding:14px 15px}
  .op-tit{display:flex;align-items:flex-start;gap:9px;flex-wrap:wrap}
  .op-tit b{font-family:var(--font-display,'Poppins',sans-serif);font-size:19px;font-weight:700;
    line-height:1.25;color:var(--tinta)}
  .op-tuya{display:inline-flex;align-items:center;gap:6px;font-size:14px;font-weight:600;
    color:var(--tm-rosa-tx);background:var(--tm-rosa-piel);border-radius:99px;padding:4px 11px}
  .op-tuya .ic{width:14px;height:14px;stroke-width:2}
  .op-fecha{font-size:15px;line-height:1.5;color:var(--muted);margin:6px 0 0}
  .op-fecha b{color:var(--tinta);font-weight:600}
  .op-nota{font-size:15px;line-height:1.55;color:var(--ink,#4A434F);margin:10px 0 0}
  .op-acc{display:flex;flex-direction:column;gap:8px;margin-top:14px}
  .op-acc .tm-btn{margin-top:0}
  .op-sec{display:flex;gap:8px}
  .op-sec button{flex:1;min-height:48px;border:1px solid var(--line);border-radius:var(--tm-r-bt);
    background:transparent;font-family:inherit;font-size:15px;font-weight:600;color:var(--muted);
    cursor:pointer}
  .op-sec button:hover{border-color:var(--raya-firme,#D8D3CC);color:var(--tinta)}
  .op-sec button:focus-visible{outline:2px solid var(--tinta);outline-offset:2px}
  /*  EL AVISO QUE DESARMA EL MIEDO. Va pegado a los botones, no al final de la
      pagina donde nadie lo lee. */
  .op-pie{font-size:14px;line-height:1.5;color:var(--muted);margin:12px 0 0}
  .op-pie b{color:var(--tinta)}
  .op-mas{font-size:14px;color:var(--muted);margin:8px 0 0}
  .op-hecho{display:none;align-items:center;gap:9px;font-size:15px;line-height:1.5;
    color:var(--tm-teal-tx);background:var(--tm-teal-piel);border-radius:var(--tm-r-bt);
    padding:12px 14px;margin-top:12px}
  .op-hecho.on{display:flex}
  .op-hecho .ic{width:18px;height:18px;flex:none;stroke-width:2}
  .op-hecho a{color:inherit;font-weight:700}

  @media (min-width:1000px){ .op-acc{flex-direction:row;align-items:center}
    .op-acc .tm-btn{flex:1;max-width:320px} .op-sec{flex:1} }
</style>

<div class="op-card" id="opCard"
     data-origen="<?= $h((string)$op_uno['origen']) ?>"
     data-id="<?= (int)$op_uno['id'] ?>"
     data-fecha="<?= $h((string)$op_uno['fecha']) ?>">
  <span class="et"><?= ico('calendar') ?>Una fecha que te puede servir</span>
  <div class="op-cuerpo" id="opCuerpo">
    <div class="op-tit">
      <b><?= $h((string)$op_uno['titulo']) ?></b>
      <?php if (!empty($op_uno['propia'])): ?>
        <span class="op-tuya"><?= ico('pin') ?>Tuya</span>
      <?php endif; ?>
    </div>
    <p class="op-fecha"><b><?= $h($op_cuando) ?></b> · en <?= (int)$op_uno['dias'] ?>
      <?= (int)$op_uno['dias'] === 1 ? 'día' : 'días' ?><?php
      if ($meta): ?> · cae dentro de tu meta<?php endif; ?></p>
    <?php if (trim((string)$op_uno['nota']) !== ''): ?>
      <p class="op-nota"><?= $h((string)$op_uno['nota']) ?></p>
    <?php endif; ?>

    <div class="op-acc">
      <button type="button" class="tm-btn" id="opAdd"><?= ico('plus') ?>Añadir una publicación</button>
      <div class="op-sec">
        <button type="button" id="opNo">Esta no me sirve</button>
        <button type="button" id="opLuego">Ahora no</button>
      </div>
    </div>

    <?php /*  LO QUE DESARMA EL MIEDO, pegado a los botones. «Esta no me sirve»
              a secas se puede leer como «borra la fecha» — y si la fecha es
              suya, hay que decirle que no.  */ ?>
    <p class="op-pie">Las fechas son <b>sugerencias</b>. Si la descartas, tu meta y tu plan siguen
      exactamente igual<?php if (!empty($op_uno['propia'])): ?>, y tu fecha <b>no se borra</b>:
      sigue en tu calendario<?php endif; ?>. Añadirla <b>no gasta imágenes</b> — la pieza nace en
      borrador y tú decides si la publicas.</p>
    <?php if ($op_mas > 0): ?>
      <p class="op-mas">Tengo <?= $op_mas ?> fecha<?= $op_mas === 1 ? '' : 's' ?> más para más
        adelante. Te las voy enseñando de una en una.</p>
    <?php endif; ?>
  </div>

  <div class="op-hecho" id="opHecho"><?= ico('check-circle') ?><span id="opHechoTx"></span></div>
</div>

<script>
(function(){
  var card = document.getElementById('opCard'); if (!card) return;
  var CSRF = <?= json_encode(csrf_token()) ?>, MARCA = <?= (int)$marca_id ?>;
  var yendo = false;

  function contestar(accion, boton, dilo){
    if (yendo) return;
    yendo = true;
    [].forEach.call(card.querySelectorAll('button'), function(b){ b.disabled = true; });
    if (boton) boton.textContent = 'Un momento…';

    var fd = new FormData();
    fd.append('csrf', CSRF); fd.append('accion', accion);
    fd.append('origen', card.dataset.origen);
    fd.append('oport',  card.dataset.id);
    fd.append('fecha',  card.dataset.fecha);

    fetch(location.pathname + '?marca=' + MARCA, { method:'POST', body:fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        yendo = false;
        if (j && j.ok) {
          //  Se contesta EN SITIO, sin recargar: la pantalla del plan es larga
          //  y devolver al dueño arriba del todo por decir «esta no» seria
          //  cobrarle un scroll por una respuesta de un segundo.
          document.getElementById('opCuerpo').style.display = 'none';
          document.getElementById('opHechoTx').innerHTML = dilo(j);
          document.getElementById('opHecho').classList.add('on');
          if (window.crecerMetaRecalcular) setTimeout(window.crecerMetaRecalcular, 60);
          return;
        }
        [].forEach.call(card.querySelectorAll('button'), function(b){ b.disabled = false; });
        if (boton) boton.textContent = 'Intenta otra vez';
      })
      .catch(function(){
        yendo = false;
        [].forEach.call(card.querySelectorAll('button'), function(b){ b.disabled = false; });
        if (boton) boton.textContent = 'Se cayó la conexión — otra vez';
      });
  }

  document.getElementById('opAdd').addEventListener('click', function(){
    contestar('oport_add', this, function(j){
      return 'Listo, te la puse en borrador para ese día. '
           + (j.contenido_id
              ? '<a href="<?= $BASE ?>/propuestas.php?marca=' + MARCA + '">Verla en Tus Posts</a>'
              : '');
    });
  });
  document.getElementById('opNo').addEventListener('click', function(){
    contestar('oport_no', this, function(){
      return 'Entendido, no te la vuelvo a sacar.'
        + (card.dataset.origen === 'evento' ? ' Tu fecha sigue en tu calendario.' : '');
    });
  });
  document.getElementById('opLuego').addEventListener('click', function(){
    contestar('oport_luego', this, function(){ return 'Vale, te la recuerdo más cerca.'; });
  });
})();
</script>
