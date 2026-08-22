<?php
// ============================================================
//  CRECER — LAS DOS DELICADAS DE TU META
//  panel/_meta_opciones.php
//
//    ?vista=plan-nuevo   pedirle otro plan a la Estratega, para esta misma meta
//    ?vista=cambiar      cerrar esta meta y estrenar la proxima, de una
//
//  Eran dos confirm() del navegador. Un confirm() es la peor forma posible de
//  pedir permiso para algo grande: no cabe lo que va a pasar, no se puede leer
//  con calma, no tiene vuelta atras y —lo peor— la del cambio de meta ni
//  siquiera decia la verdad. Decia «el corillo dejara de perseguir esta» y lo
//  que hacia era CERRARLA Y DEJAR AL NEGOCIO SIN META, esperando que el dueño
//  llenara despues un wizard en blanco. Quien cerraba la pestaña ahi se quedaba
//  sin norte y sin forma de recuperar la anterior.
//
//  LAS TRES REGLAS QUE MANDAN AQUI
//
//  1. NADA SE ESCRIBE HASTA EL ULTIMO PASO. Entrar, mirar y salir no deja
//     rastro en la base. Se comprueba contando filas, no leyendo el codigo.
//
//  2. LA PROXIMA META SE RECOGE ANTES DE CERRAR LA DE AHORA. Y las dos
//     escrituras van en la misma transaccion (meta_cambiar_meta): o entran las
//     dos o no entra ninguna. Si algo falla, la meta de ahora sigue activa.
//
//  3. «SOLO CERRAR POR AHORA» EXISTE Y VA APARTE. Hay quien de verdad quiere
//     parar sin escoger otra cosa hoy. Esconderlo obligaria a inventarse una
//     meta para poder cerrar la que sobra.
//
//  El doble clic no se resuelve con relojes: cada wizard manda el id de lo que
//  CREE estar reemplazando —el plan vigente, la meta activa— y el servidor
//  compara. Si ya no cuadra, es que el primer clic entro.
//
//  Comparte piel con el wizard de crear la meta (_meta_wizard_piel.php): mismos
//  tokens, mismo radio, 14px de suelo, 44px de objetivo.
// ============================================================

$op_cambiar = ($vista === 'cambiar');
$op_plan    = meta_plan_activo($pdo, (int)$meta['id']);
$op_def     = meta_objetivo_def((string)$meta['objetivo']);

//  LO QUE HAY HOY, PARA PODER DECIR LA VERDAD DE QUE PASA CON ELLO.
//  Son cuentas reales, no promesas: si el numero sale 0, la frase lo dice.
$op_jugadas = 0; $op_hechas = 0; $op_piezas = 0; $op_publicadas = 0;
try {
    $q = $pdo->prepare("SELECT COUNT(*) t, SUM(estado='hecha') h
                          FROM crecer_meta_tactica WHERE meta_id=? AND plan_id=?");
    $q->execute([(int)$meta['id'], (int)($op_plan['id'] ?? 0)]);
    $f = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    $op_jugadas = (int)($f['t'] ?? 0); $op_hechas = (int)($f['h'] ?? 0);

    $q2 = $pdo->prepare("SELECT COUNT(*) t, SUM(estado='publicado') p
                           FROM crecer_contenido WHERE marca_id=? AND meta_id=?");
    $q2->execute([$marca_id, (int)$meta['id']]);
    $f2 = $q2->fetch(PDO::FETCH_ASSOC) ?: [];
    $op_piezas = (int)($f2['t'] ?? 0); $op_publicadas = (int)($f2['p'] ?? 0);
} catch (Throwable $e) { error_log('_meta_opciones cuentas: ' . $e->getMessage()); }

$op_volver = $BASE . '/meta.php?marca=' . $marca_id . '&vista=plan';
$op_meta_txt = $meta['cantidad'] !== null
    ? meta_fmt((float)$meta['cantidad'], (string)$meta['objetivo'])
    : (string)$meta['titulo'];
?>
<?php require_once __DIR__ . '/_meta_wizard_piel.php'; ?>
<style>
  /* — LO QUE HAY HOY, ANTES DE TOCARLO —
     Un cambio grande no se aprueba a ciegas: primero se ve lo que se va a
     mover. Esta caja es lo mismo que el repaso del wizard de crear, del reves:
     alli se ensena lo que va a nacer, aqui lo que ya existe. */
  .op-hoy{border:1px solid var(--line);border-radius:var(--tm-r);background:var(--crema,#FAF7F4);
    padding:14px 15px;margin-top:16px}
  .op-hoy b.et{display:block;font-size:14px;font-weight:600;letter-spacing:.06em;
    text-transform:uppercase;color:var(--muted);margin-bottom:7px}
  .op-hoy p{font-size:16px;line-height:1.45;color:var(--tinta);font-weight:600;margin:0}
  .op-hoy ul{margin:10px 0 0;padding-left:19px}
  .op-hoy li{font-size:15px;line-height:1.55;color:var(--ink,#4A434F);margin-bottom:4px}
  .op-hoy li:last-child{margin-bottom:0}

  /* — LAS CONSECUENCIAS, SEPARADAS POR LO QUE SE QUEDA Y LO QUE SE VA —
     Mezcladas en un parrafo, el dueño solo retiene el susto. En dos columnas
     de color distinto se lee de un vistazo que lo suyo no se pierde. */
  .op-que{display:grid;gap:10px;margin-top:16px}
  .op-caja{border-radius:var(--tm-r);padding:13px 15px}
  .op-caja b{display:block;font-size:14px;font-weight:600;letter-spacing:.06em;
    text-transform:uppercase;margin-bottom:7px}
  .op-caja ul{margin:0;padding-left:19px}
  .op-caja li{font-size:15px;line-height:1.55;margin-bottom:4px}
  .op-caja li:last-child{margin-bottom:0}
  .op-queda{background:var(--tm-teal-piel);color:var(--tm-teal-tx)}
  .op-queda b{color:var(--tm-teal-tx)}
  .op-cambia{background:var(--tm-aviso-piel);color:var(--tm-aviso)}
  .op-cambia b{color:var(--tm-aviso)}

  /* — LA SALIDA DE «SOLO CERRAR», APARTE Y SIN DISFRAZ — */
  .op-solo{margin-top:22px;border-top:1px solid var(--line);padding-top:16px}
  .op-solo > b{display:block;font-size:16px;font-weight:600;color:var(--tinta);margin-bottom:6px}
  .op-solo p{font-size:15px;line-height:1.55;color:var(--muted);margin:0 0 10px}
  .op-solo button{display:inline-flex;align-items:center;justify-content:center;gap:8px;
    min-height:48px;padding:0 18px;border:1px solid var(--line);border-radius:var(--tm-r-bt);
    background:var(--card,#fff);font-family:inherit;font-size:16px;font-weight:600;
    color:var(--tm-aviso);cursor:pointer}
  .op-solo button:hover{border-color:var(--tm-aviso);background:var(--tm-aviso-piel)}
  .op-solo button:focus-visible{outline:2px solid var(--tinta);outline-offset:2px}
  .op-solo button .ic{width:18px;height:18px;stroke-width:2}

  @media (min-width:1000px){ .op-que{grid-template-columns:1fr 1fr} }
</style>

<div class="wz" id="wz" data-flujo="<?= $op_cambiar ? 'cambiar' : 'plan-nuevo' ?>">

  <a href="<?= $h($op_volver) ?>" class="wz-salir" id="wzSalir">
    <?= ico('chev-der') ?>Salir sin cambiar nada</a>

  <ol class="wz-tren" id="wzTren" aria-hidden="true">
    <?php foreach ($op_cambiar
        ? ['Por qué', 'La próxima', 'Cuánto', 'Repasar']
        : ['Por qué', 'Qué pasa', 'Repasar'] as $i => $et): ?>
      <li data-t="<?= $i + 1 ?>"><i></i><span><?= $h($et) ?></span></li>
    <?php endforeach; ?>
  </ol>

  <span class="wz-et" id="wzEt">Paso 1 de <?= $op_cambiar ? 4 : 3 ?></span>
  <h1 class="wz-q" id="wzQ">—</h1>
  <p class="wz-ayuda" id="wzAyuda">—</p>

  <!-- ══ PASO 1 · por que ═══════════════════════════════════════════ -->
  <section class="wz-p on" data-p="1">
    <div class="wz-chips" id="opMotivo">
      <?php foreach ($op_cambiar ? [
              'lograda'   => ['Ya la logré',            'Le doy paso a la próxima'],
              'no_encaja' => ['No era lo que necesito', 'Me hace falta otra cosa'],
              'cambio'    => ['Cambió el negocio',      'Otra temporada, otra oferta'],
              'muy_dura'  => ['Se me fue la mano',      'Pedí más de lo que puedo'],
            ] : [
              'no_funciona' => ['No está funcionando',    'Llevo días y no veo movimiento'],
              'no_puedo'    => ['No puedo con las jugadas', 'Me piden más de lo que doy'],
              'no_encaja'   => ['No va con mi negocio',   'Esto no es lo que yo vendo'],
              'quiero_otro' => ['Quiero probar otra cosa', 'Sin más — a ver qué sale'],
            ] as $k => [$t, $sub]): ?>
        <button type="button" class="wz-chip" data-motivo="<?= $h($k) ?>"
                data-txt="<?= $h($t) ?>"><?= $h($t) ?><small><?= $h($sub) ?></small></button>
      <?php endforeach; ?>
    </div>

    <span class="wz-sub">¿Quieres contarme más? (Opcional)</span>
    <p class="wz-ayuda" style="margin:0 0 10px">Mientras más concreto, menos se parecerá
      <?= $op_cambiar ? 'el plan nuevo al de ahora' : 'a lo que ya probamos' ?>.</p>
    <textarea class="wz-libre" id="opDetalle" maxlength="500"
      placeholder="Ej: los posts salen bonitos pero nadie escribe; mi gente compra los viernes."></textarea>
  </section>

  <?php if (!$op_cambiar): ?>
  <!-- ══ PASO 2 · que pasa con lo de ahora (plan nuevo) ═════════════ -->
  <section class="wz-p" data-p="2">
    <div class="op-hoy">
      <b class="et">El plan que tienes ahora</b>
      <p><?= $op_jugadas ?> <?= $op_jugadas === 1 ? 'jugada' : 'jugadas' ?><?php
        if ($op_hechas > 0): ?> · <?= $op_hechas ?> ya <?= $op_hechas === 1 ? 'hecha' : 'hechas' ?><?php endif; ?></p>
    </div>
    <div class="op-que">
      <div class="op-caja op-queda">
        <b><?= ico('check') ?> Se queda como está</b>
        <ul>
          <li>Tus <?= $op_piezas ?> <?= $op_piezas === 1 ? 'pieza' : 'piezas' ?> de esta meta<?php
            if ($op_publicadas > 0): ?>, con <?= $op_publicadas ?> ya <?= $op_publicadas === 1 ? 'publicada' : 'publicadas' ?><?php endif; ?>.
            Todo sigue en Tus Posts.</li>
          <li>Tu meta, con su número y su fecha. No la toco.</li>
          <li>El plan de ahora pasa al historial con sus resultados — así se puede
            comparar después cuál sirvió.</li>
          <li>Tu marca y tu Genoma.</li>
        </ul>
      </div>
      <div class="op-caja op-cambia">
        <b><?= ico('refresh') ?> Esto sí cambia</b>
        <ul>
          <li>Las jugadas que aún no has hecho dejan de ser las de ahora.</li>
          <li>La Estratega arma unas nuevas leyendo lo que me acabas de decir.</li>
          <li>El diagnóstico de tu meta se reescribe con la lectura nueva.</li>
        </ul>
      </div>
    </div>
  </section>
  <?php else: ?>
  <!-- ══ PASO 2 · la proxima meta (cambiar) ════════════════════════ -->
  <section class="wz-p" data-p="2">
    <div class="wz-objs">
      <?php foreach ($objetivos as $k => $o):
        $d2 = meta_objetivo_def($k); ?>
        <button type="button" class="wz-obj" data-obj="<?= $h($k) ?>"
                data-titulo="<?= $h($o['titulo']) ?>"
                data-pregunta="<?= $h($o['pregunta']) ?>"
                data-unidad="<?= $h($o['unidad'] === 'dolares' ? 'dólares' : $o['unidad']) ?>"
                data-medir="<?= $h(!empty($d2['medible']) ? $o['explicacion'] : ($d2['como_medir'] ?? '')) ?>">
          <span class="wz-obj-ic"><?= ico($o['ico']) ?></span>
          <span class="wz-obj-tx">
            <b><?= $h($o['titulo']) ?></b>
            <p><?= $h($o['explicacion']) ?></p>
            <small><?= $h($o['jerga']) ?></small>
          </span>
          <span class="wz-obj-ok"><?= ico('check-circle') ?></span>
        </button>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- ══ PASO 3 · cuanto, cuando e inversion (cambiar) ═════════════ -->
  <section class="wz-p" data-p="3">
    <div class="wz-num">
      <input type="number" id="cantidad" min="1" step="1" placeholder="25" inputmode="numeric"
             aria-label="Cuánto quieres lograr">
      <span class="wz-unidad" id="wzUnidad">pedidos</span>
    </div>

    <span class="wz-sub">¿Para cuándo?</span>
    <div class="wz-chips" id="wzFecha">
      <button type="button" class="wz-chip" data-dias="14">En 2 semanas</button>
      <button type="button" class="wz-chip sel" data-dias="30">En un mes</button>
      <button type="button" class="wz-chip" data-dias="60">En 2 meses</button>
      <button type="button" class="wz-chip" data-dias="90">En 3 meses</button>
    </div>

    <span class="wz-sub">¿Puedes invertir en anuncios?</span>
    <div class="wz-chips" id="wzPauta">
      <button type="button" class="wz-chip sel" data-pauta="0">Nada por ahora<small>Sin pagar anuncios</small></button>
      <button type="button" class="wz-chip" data-pauta="20">$20 al mes<small>1 o 2 posts</small></button>
      <button type="button" class="wz-chip" data-pauta="50">$50 al mes<small>Alcance serio</small></button>
      <button type="button" class="wz-chip" data-pauta="100">$100 o más<small>Campaña de verdad</small></button>
    </div>

    <span class="wz-sub">¿Con qué cuentas? (Opcional)</span>
    <textarea class="wz-libre" id="contexto" maxlength="600"
      placeholder="Ej: Tengo el combo de brazo gitano a $18 y en agosto son las fiestas del pueblo."></textarea>
  </section>
  <?php endif; ?>

  <!-- ══ ULTIMO PASO · el repaso ════════════════════════════════════ -->
  <section class="wz-p" data-p="<?= $op_cambiar ? 4 : 3 ?>">
    <div class="wz-res">
      <div class="wz-fila">
        <span class="wz-fila-tx"><span>Por qué lo cambias</span><b id="rMotivo">—</b></span>
        <button type="button" class="wz-cambiar" data-ir="1">Cambiar</button>
      </div>
      <?php if ($op_cambiar): ?>
        <div class="wz-fila">
          <span class="wz-fila-tx"><span>La meta que cierras</span><b id="rVieja" class="suave">—</b></span>
        </div>
        <div class="wz-fila">
          <span class="wz-fila-tx"><span>La próxima meta</span><b id="rObj">—</b></span>
          <button type="button" class="wz-cambiar" data-ir="2">Cambiar</button>
        </div>
        <div class="wz-fila">
          <span class="wz-fila-tx"><span>Cuánto y para cuándo</span><b id="rCant">—</b></span>
          <button type="button" class="wz-cambiar" data-ir="3">Cambiar</button>
        </div>
        <div class="wz-fila">
          <span class="wz-fila-tx"><span>Inversión en anuncios</span><b id="rPauta">—</b></span>
          <button type="button" class="wz-cambiar" data-ir="3">Cambiar</button>
        </div>
      <?php else: ?>
        <div class="wz-fila">
          <span class="wz-fila-tx"><span>La meta no se toca</span><b id="rVieja" class="suave"><?= $h($op_meta_txt) ?></b></span>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($op_cambiar): ?>
      <div class="op-que">
        <div class="op-caja op-queda">
          <b><?= ico('check') ?> Se queda como está</b>
          <ul>
            <li>Tus <?= $op_piezas ?> <?= $op_piezas === 1 ? 'pieza' : 'piezas' ?> de la meta que cierras<?php
              if ($op_publicadas > 0): ?>, con <?= $op_publicadas ?> ya <?= $op_publicadas === 1 ? 'publicada' : 'publicadas' ?><?php endif; ?>.
              Nada se borra: siguen en Tus Posts.</li>
            <li>Los resultados que ya midió y su historial de planes.</li>
            <li>Tu marca y tu Genoma.</li>
          </ul>
        </div>
        <div class="op-caja op-cambia">
          <b><?= ico('target') ?> Esto sí cambia</b>
          <ul>
            <li>La meta de ahora se cierra y deja de perseguirse.</li>
            <li>Nace la nueva y la Estratega le arma su plan.</li>
            <li>Lo que toca hoy pasa a ser lo de la meta nueva.</li>
          </ul>
        </div>
      </div>
    <?php endif; ?>

    <div class="wz-bloque wz-luego">
      <b>Qué pasa cuando confirmes</b>
      <ol>
        <?php if ($op_cambiar): ?>
          <li>Se cierra la meta de ahora y nace la nueva — las dos cosas juntas o ninguna.</li>
          <li>La Estratega arma el plan de la meta nueva.</li>
          <li>Te llevo a Tu Meta y ahí te digo qué toca primero.</li>
        <?php else: ?>
          <li>El plan de ahora pasa al historial con sus resultados.</li>
          <li>La Estratega arma jugadas nuevas para esta misma meta.</li>
          <li>Te llevo al plan nuevo para que lo veas.</li>
        <?php endif; ?>
      </ol>
      <p class="nota">Hasta que pulses ese botón no cambia nada. Si sales ahora, todo se queda
        <?= $op_cambiar ? 'igual que está' : 'con el plan de siempre' ?>.</p>
    </div>

    <?php if ($op_cambiar): ?>
      <?php /*  LA TERCERA SALIDA, Y NO ESCONDIDA.
                Hay quien quiere parar sin escoger nada hoy. Sin esto, para
                cerrar una meta que sobra habria que inventarse otra. */ ?>
      <div class="op-solo">
        <b>¿Solo quieres cerrarla por ahora?</b>
        <p>Se cierra y te quedas sin meta activa hasta que escojas otra. El corillo deja de
          perseguir nada — tus posts, resultados e historial no se tocan, y puedes escoger
          la próxima cuando quieras.</p>
        <button type="button" id="opSoloCerrar"><?= ico('lock') ?>Solo cerrar por ahora</button>
      </div>
    <?php endif; ?>
  </section>

  <div class="wz-err" id="wzErr" role="alert" tabindex="-1">
    <?= ico('bolt') ?>
    <div class="wz-err-tx">
      <b id="wzErrT">No se pudo hacer el cambio</b>
      <p id="wzErrP"></p>
      <button type="button" id="wzReintentar">Intentar otra vez</button>
    </div>
  </div>

  <div class="wz-nav" id="wzNav">
    <button type="button" class="wz-atras" id="atras" style="display:none">Atrás</button>
    <button type="button" class="tm-btn" id="sigue" disabled>Siguiente</button>
  </div>

  <div class="wz-load" id="wzLoad">
    <div class="sp"></div>
    <b><?= $op_cambiar ? 'Cambiando tu meta' : 'Armando el plan nuevo' ?></b>
    <span>La Estratega está leyendo lo que me dijiste<br>para no repetir lo mismo. Dale unos segundos.</span>
  </div>
</div>

<script>
(function(){
  var CSRF  = <?= json_encode(csrf_token()) ?>, MARCA = <?= (int)$marca_id ?>;
  var CAMBIAR = <?= $op_cambiar ? 'true' : 'false' ?>;
  var PASOS   = CAMBIAR ? 4 : 3;
  var VOLVER  = <?= json_encode($op_volver) ?>;
  var AHORA   = <?= json_encode($BASE . '/meta.php?marca=' . $marca_id) ?>;
  //  LO QUE SE CREE ESTAR REEMPLAZANDO. El servidor compara: si ya no cuadra,
  //  el primer clic entro y el segundo no vuelve a escribir ni a pagar otra
  //  llamada a la Estratega.
  var PLAN_ACTUAL = <?= (int)($op_plan['id'] ?? 0) ?>;
  var META_ACTUAL = <?= (int)$meta['id'] ?>;

  var d = { motivo:'', motivoTxt:'', detalle:'',
            obj:'', titulo:'', unidad:'pedidos', medir:'',
            cant:'', dias:30, pauta:0, ctx:'' };
  var paso = 1, enviando = false;

  var $ = function(i){ return document.getElementById(i); };
  var et = $('wzEt'), q = $('wzQ'), ayuda = $('wzAyuda'), tren = $('wzTren');
  var sigue = $('sigue'), atras = $('atras'), nav = $('wzNav');
  var err = $('wzErr'), load = $('wzLoad');

  var TITULO = CAMBIAR ? [
    null,
    { et:'Por qué', q:'¿Por qué cambias de meta?',
      ay:'Me sirve para armarte una próxima que sí encaje. Nada cambia todavía.' },
    { et:'La próxima', q:'¿Y ahora qué quieres lograr?',
      ay:'Escoge la próxima antes de cerrar la de ahora. Así no te quedas sin norte ni un minuto.' },
    { et:'Cuánto', q:'¿Cuánto quieres lograr?',
      ay:'Un número te deja saber si vas bien o si hay que apretar.' },
    { et:'Repasar', q:'Repasa antes de cambiar',
      ay:'Esto es lo que voy a hacer. Todavía no he tocado nada.' }
  ] : [
    null,
    { et:'Por qué', q:'¿Por qué quieres otro plan?',
      ay:'Es lo único que hace que el plan nuevo no sea otra tirada de dados: la Estratega lo lee.' },
    { et:'Qué pasa', q:'Esto es lo que se mueve',
      ay:'Tu meta y tus posts no se tocan. Lo que cambia son las jugadas que aún no has hecho.' },
    { et:'Repasar', q:'Repasa antes de empezar',
      ay:'Todavía no he tocado nada. El plan de ahora sigue en pie hasta que pulses el botón.' }
  ];

  var MESES = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto',
               'septiembre','octubre','noviembre','diciembre'];
  function fechaLimite(){
    var f = new Date(); f.setDate(f.getDate() + d.dias);
    return { iso: f.getFullYear() + '-' + String(f.getMonth()+1).padStart(2,'0')
                  + '-' + String(f.getDate()).padStart(2,'0'),
             txt: 'el ' + f.getDate() + ' de ' + MESES[f.getMonth()] };
  }

  function ver(n){
    paso = n;
    [].forEach.call(document.querySelectorAll('.wz-p'), function(s){
      s.classList.toggle('on', +s.dataset.p === n);
    });
    [].forEach.call(tren.children, function(li){
      var i = +li.dataset.t;
      li.classList.toggle('ya', i < n);
      li.classList.toggle('on', i === n);
    });
    et.textContent = 'Paso ' + n + ' de ' + PASOS + ' · ' + TITULO[n].et;
    q.textContent  = (CAMBIAR && n === 3 && d.obj)
      ? document.querySelector('.wz-obj.sel').dataset.pregunta : TITULO[n].q;
    ayuda.textContent = TITULO[n].ay;
    atras.style.display = n > 1 ? '' : 'none';
    sigue.textContent = n === PASOS
      ? (CAMBIAR ? 'Cambiar mi meta' : 'Empezar el plan nuevo') : 'Siguiente';
    ocultarError();
    if (n === PASOS) repasar();
    revisar();
    window.scrollTo({ top:0, behavior:'smooth' });
    if (window.crecerMetaRecalcular) setTimeout(window.crecerMetaRecalcular, 60);
  }

  function revisar(){
    if (paso === 1)                     sigue.disabled = !d.motivo;
    else if (CAMBIAR && paso === 2)     sigue.disabled = !d.obj;
    else if (CAMBIAR && paso === 3)     sigue.disabled = !(d.cant && +d.cant > 0);
    else                                sigue.disabled = enviando;
  }

  function repasar(){
    var m = d.motivoTxt || '—';
    if ((d.detalle || '').trim() !== '') m += ' — «' + d.detalle.trim() + '»';
    $('rMotivo').textContent = m;
    if (!CAMBIAR) return;
    var f = fechaLimite();
    $('rVieja').textContent = <?= json_encode($op_meta_txt) ?> + ' · se cierra';
    $('rObj').textContent   = d.titulo || '—';
    $('rCant').textContent  = (d.cant ? d.cant + ' ' + d.unidad : '—') + ' · ' + f.txt;
    $('rPauta').textContent = d.pauta > 0 ? ('$' + d.pauta + ' al mes en anuncios')
                                          : 'Nada por ahora — sin pagar anuncios';
  }

  // ── PASO 1 · el motivo ───────────────────────────────────────────
  $('opMotivo').addEventListener('click', function(e){
    var c = e.target.closest('.wz-chip'); if (!c) return;
    [].forEach.call(this.querySelectorAll('.wz-chip'), function(x){ x.classList.remove('sel'); });
    c.classList.add('sel');
    d.motivo = c.dataset.motivo; d.motivoTxt = c.dataset.txt;
    revisar();
    nav.scrollIntoView({ block:'nearest',
      behavior: matchMedia('(prefers-reduced-motion:reduce)').matches ? 'auto' : 'smooth' });
  });
  $('opDetalle').addEventListener('input', function(){ d.detalle = this.value; });

  // ── LOS PASOS DE LA META NUEVA (solo en cambiar) ─────────────────
  if (CAMBIAR) {
    [].forEach.call(document.querySelectorAll('.wz-obj'), function(b){
      b.addEventListener('click', function(){
        [].forEach.call(document.querySelectorAll('.wz-obj'), function(x){ x.classList.remove('sel'); });
        b.classList.add('sel');
        d.obj = b.dataset.obj; d.titulo = b.dataset.titulo;
        d.unidad = b.dataset.unidad; d.medir = b.dataset.medir;
        $('wzUnidad').textContent = d.unidad;
        revisar();
        nav.scrollIntoView({ block:'nearest',
          behavior: matchMedia('(prefers-reduced-motion:reduce)').matches ? 'auto' : 'smooth' });
      });
    });
    $('cantidad').addEventListener('input', function(){ d.cant = this.value; revisar(); });
    $('contexto').addEventListener('input', function(){ d.ctx = this.value; });
    $('wzFecha').addEventListener('click', function(e){
      var c = e.target.closest('.wz-chip'); if (!c) return;
      [].forEach.call(this.querySelectorAll('.wz-chip'), function(x){ x.classList.remove('sel'); });
      c.classList.add('sel'); d.dias = +c.dataset.dias;
    });
    $('wzPauta').addEventListener('click', function(e){
      var c = e.target.closest('.wz-chip'); if (!c) return;
      [].forEach.call(this.querySelectorAll('.wz-chip'), function(x){ x.classList.remove('sel'); });
      c.classList.add('sel'); d.pauta = +c.dataset.pauta;
    });
  }

  [].forEach.call(document.querySelectorAll('.wz-cambiar'), function(b){
    b.addEventListener('click', function(){ ver(+b.dataset.ir); });
  });

  // ── EL FALLO, DENTRO ─────────────────────────────────────────────
  function mostrarError(txt){
    $('wzErrP').textContent = txt;
    err.classList.add('on');
    load.classList.remove('on');
    nav.style.display = '';
    [].forEach.call(document.querySelectorAll('.wz-p'), function(s){
      s.classList.toggle('on', +s.dataset.p === PASOS); });
    [].forEach.call(tren.children, function(li){ li.classList.toggle('ya', +li.dataset.t < PASOS); });
    enviando = false; revisar();
    err.focus();
  }
  function ocultarError(){ err.classList.remove('on'); }
  $('wzReintentar').addEventListener('click', function(){ ocultarError(); confirmar(); });

  //  El motivo que viaja: la etiqueta que escogió + lo que escribió.
  function motivoEntero(){
    var t = d.motivoTxt || '';
    if ((d.detalle || '').trim() !== '') t += '. ' + d.detalle.trim();
    return t;
  }

  function trabajando(){
    [].forEach.call(document.querySelectorAll('.wz-p'), function(s){ s.classList.remove('on'); });
    nav.style.display = 'none';
    ocultarError();
    load.classList.add('on');
    [].forEach.call(tren.children, function(li){ li.classList.add('ya'); });
  }

  function enviar(campos, alTerminar){
    var fd = new FormData();
    fd.append('csrf', CSRF);
    for (var k in campos) fd.append(k, campos[k]);
    return fetch(location.pathname + '?marca=' + MARCA, { method:'POST', body:fd })
      .then(function(r){ return r.json(); }).then(alTerminar);
  }

  // ── EL UNICO SITIO QUE ESCRIBE ───────────────────────────────────
  function confirmar(){
    if (enviando) return;              // doble clic: el segundo no sale de aqui
    enviando = true; sigue.disabled = true;
    trabajando();

    var campos = CAMBIAR
      ? { accion:'cambiar', meta_actual:META_ACTUAL,
          cierre: (d.motivo === 'lograda' ? 'lograda' : 'cancelada'),
          objetivo:d.obj, cantidad:d.cant, fecha_limite:fechaLimite().iso,
          presupuesto:d.pauta, contexto:d.ctx }
      : { accion:'replan', plan_actual:PLAN_ACTUAL, motivo:motivoEntero() };

    enviar(campos, function(j){
      //  Se vuelve SIEMPRE que la operacion entro, salga o no el plan: el
      //  compositor recalcula en Tu Meta y dice la verdad de lo que hay.
      if (j && j.ok) { location.href = CAMBIAR ? AHORA : VOLVER; return; }
      mostrarError((j && j.err) ? j.err
        : 'No pude hacerlo ahora mismo. Nada cambió — dale otra vez.');
    }).catch(function(){
      mostrarError('Se cayó la conexión antes de guardar. No cambió nada: dale otra vez cuando vuelva.');
    });
  }

  // ── «SOLO CERRAR POR AHORA» · su propia escritura, y solo la suya ──
  var solo = $('opSoloCerrar');
  if (solo) solo.addEventListener('click', function(){
    if (enviando) return;
    enviando = true; solo.disabled = true; sigue.disabled = true;
    trabajando();
    enviar({ accion:'cerrar', meta_actual:META_ACTUAL,
             cierre: (d.motivo === 'lograda' ? 'lograda' : 'cancelada') }, function(j){
      if (j && j.ok) { location.href = AHORA; return; }
      solo.disabled = false;
      mostrarError((j && j.err) ? j.err : 'No pude cerrarla. Tu meta sigue en pie.');
    }).catch(function(){
      solo.disabled = false;
      mostrarError('Se cayó la conexión. Tu meta sigue igual.');
    });
  });

  atras.addEventListener('click', function(){ if (paso > 1) ver(paso - 1); });
  sigue.addEventListener('click', function(){
    if (paso < PASOS) { ver(paso + 1); return; }
    confirmar();
  });

  ver(1);
})();
</script>

<?php require __DIR__ . '/_meta_zona.php'; ?>
