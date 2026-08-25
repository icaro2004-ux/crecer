<?php
// ============================================================
//  CRECER — EL WIZARD DE LA META  ·  la capa 0 de Tu Meta
//  panel/_meta_wizard.php
//
//  Es la unica pantalla de Tu Meta donde el dueño no mira: DECIDE. Y decide
//  algo que gobierna todo lo demas —el enfoque, el planificador y el CTA de
//  cada pieza—, asi que la regla de la casa aqui es una sola:
//
//      NADA SE ESCRIBE HASTA QUE EL DUEÑO VE, EN CONCRETO, QUE VA A CREAR.
//
//  De ahi los cuatro pasos, UNA DECISION CADA UNO:
//
//      1 · que quieres lograr      2 · cuanto
//      3 · para cuando             4 · tu material  ← y aqui se confirma
//
//  Antes la cantidad y la fecha compartian pantalla y habia un quinto paso de
//  repaso. Se separaron porque son dos decisiones distintas —una es tu
//  ambicion, la otra tu calendario— y el repaso se fue porque el paso del
//  material ya enseña, justo encima del boton, lo que va a pasar al pulsarlo.
//
//  EL PRESUPUESTO Y EL CONTEXTO BAJARON A UNA CAPA. Son utiles y son
//  opcionales: ocupando un paso del camino obligaban a todo el mundo a pasar
//  por una decision que la mayoria no tiene que tomar.
//
//  Y LAS RESPUESTAS SOBREVIVEN AL VIAJE. Vivian solo en memoria: salir a la
//  Biblioteca a subir una foto —que es justo lo que este recorrido invita a
//  hacer— las borraba todas. Ahora se guardan en sessionStorage: en el
//  navegador, en esta pestaña, sin tocar la base. Salir antes del ultimo boton
//  sigue sin dejar rastro en el servidor.
//
//  CUATRO COSAS QUE NO SE TOCAN
//
//  1. El progreso del wizard NO se parece al progreso de la meta. La meta usa
//     una linea teal finita, y solo cuando el compositor certifica que se puede
//     afirmar. Esto es un tren de cuatro tramos en tinta con su «Paso 2 de 4»
//     escrito al lado: no hay forma de leerlo como «vas por la mitad de tus
//     pedidos».
//
//  2. El error se queda DENTRO. Un alert() del navegador se lleva la pantalla,
//     borra el contexto y no dice que hacer. Aqui el fallo sale donde paso, con
//     su boton de reintentar y sin perder una sola respuesta.
//
//  3. No se afirma que el plan existe antes de que exista. Confirmar crea la
//     meta y ENCARGA el plan; si la Estratega no lo logra, la meta queda igual
//     y se reintenta. Eso se dice antes de pulsar, no despues.
//
//  4. Misma piel que Ahora y que el Plan: mismos tokens, mismo radio, misma
//     escala, 14px de suelo, 44px de objetivo. Es la misma casa.
//
//  Lo que este archivo NO hace: decidir nada del negocio. El catalogo de
//  objetivos, la sugerencia del numero y la creacion viven en meta_negocio.php
//  y en el manejador POST de meta.php.
// ============================================================
?>
<?php
//  CUANTO MATERIAL SUYO HAY YA. Se dice solo si lo hay: «0 fotos» es ruido, y
//  ademas suena a reproche. La consulta va aqui, no en el guion, para que la
//  primera pintada ya lleve la verdad.
$wiz_mat_fotos = 0; $wiz_mat_videos = 0;
try {
    $q = $pdo->prepare("SELECT tipo, COUNT(*) n FROM crecer_activos
                         WHERE marca_id=? AND estado='activo' GROUP BY tipo");
    $q->execute([$marca_id]);
    foreach ($q as $r) {
        if ((string)$r['tipo'] === 'imagen') $wiz_mat_fotos  = (int)$r['n'];
        if ((string)$r['tipo'] === 'video')  $wiz_mat_videos = (int)$r['n'];
    }
} catch (Throwable $e) { /* sin tabla, sin frase */ }
$wiz_mat_txt = '';
if ($wiz_mat_fotos || $wiz_mat_videos) {
    $p1 = $wiz_mat_fotos  ? $wiz_mat_fotos  . ($wiz_mat_fotos  === 1 ? ' foto' : ' fotos')   : '';
    $p2 = $wiz_mat_videos ? $wiz_mat_videos . ($wiz_mat_videos === 1 ? ' video' : ' videos') : '';
    $wiz_mat_txt = 'Ya tienes ' . trim($p1 . ($p1 && $p2 ? ' y ' : '') . $p2) . ' en tu Biblioteca.';
}
?>
<?php require_once __DIR__ . '/_meta_wizard_piel.php'; ?>

<div class="wz" id="wz">

  <?php /*  La salida dice QUE pasa si se pulsa. «Salir» a secas deja al dueño
            preguntandose si perdio algo o si creo algo a medias.  */ ?>
  <a href="<?= $BASE ?>/meta.php?marca=<?= $marca_id ?>" class="wz-salir" id="wzSalir">
    <?= ico('chev-der') ?>Salir sin crear nada</a>

  <ol class="wz-tren" id="wzTren" aria-hidden="true">
    <li data-t="1"><i></i><span>Qué quieres</span></li>
    <li data-t="2"><i></i><span>Cuánto</span></li>
    <li data-t="3"><i></i><span>Para cuándo</span></li>
    <li data-t="4"><i></i><span>Tu material</span></li>
  </ol>

  <span class="wz-et" id="wzEt">Paso 1 de 4 · Qué quieres lograr</span>
  <h1 class="wz-q" id="wzQ">¿Qué quieres lograr?</h1>
  <p class="wz-ayuda" id="wzAyuda">Escoge una sola. El corillo va a trabajar para eso — no para llenar
     el calendario. Nada se guarda hasta el último paso.</p>

  <!-- ══ PASO 1 · el norte ══════════════════════════════════════════ -->
  <section class="wz-p on" data-p="1">
    <div class="wz-objs">
      <?php foreach ($objetivos as $k => $o):
        $def = meta_objetivo_def($k); ?>
        <button type="button" class="wz-obj" data-obj="<?= $h($k) ?>"
                data-titulo="<?= $h($o['titulo']) ?>"
                data-pregunta="<?= $h($o['pregunta']) ?>"
                data-unidad="<?= $h($o['unidad'] === 'dolares' ? 'dólares' : $o['unidad']) ?>"
                data-medible="<?= !empty($def['medible']) ? '1' : '0' ?>"
                data-medir="<?= $h(!empty($def['medible']) ? $o['explicacion'] : ($def['como_medir'] ?? '')) ?>">
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

  <!-- ══ PASO 2 · cuanto ════════════════════════════════════════════ -->
  <section class="wz-p" data-p="2">
    <div class="wz-num">
      <input type="number" id="cantidad" min="1" step="1" placeholder="25" inputmode="numeric"
             aria-label="Cuánto quieres lograr">
      <span class="wz-unidad" id="wzUnidad">pedidos</span>
      <button type="button" class="wz-nose" id="nose">No sé — dime tú</button>
    </div>
    <div class="wz-tip" id="wzTip" role="status"></div>
    <?php /*  Es una META, no una promesa. Decirlo aqui —donde se escribe el
              numero— y no en letra pequeña al final.  */ ?>
    <p class="wz-ayuda" id="wzMedirNota" style="margin-top:16px">—</p>
  </section>

  <!-- ══ PASO 3 · para cuando ═══════════════════════════════════════ -->
  <section class="wz-p" data-p="3">
    <div class="wz-chips" id="wzFecha">
      <button type="button" class="wz-chip" data-dias="14">En 2 semanas<small>4 semanas de plan cortas</small></button>
      <button type="button" class="wz-chip sel" data-dias="30">En un mes<small>Lo más común</small></button>
      <button type="button" class="wz-chip" data-dias="60">En 2 meses<small>Da margen para corregir</small></button>
      <button type="button" class="wz-chip" data-dias="90">En 3 meses<small>Para metas grandes</small></button>
    </div>
    <?php /*  LA FECHA CONCRETA, no solo «en 2 meses». Un plazo abstracto no se
              puede comprobar contra el calendario de nadie.  */ ?>
    <p class="wz-ayuda" style="margin-top:18px">Sería
      <b id="wzFechaClara">—</b>. <span id="wzFechaNota"></span></p>
  </section>

  <!-- ══ PASO 4 · tu material ═══════════════════════════════════════
       No es un requisito ni una puerta: es una invitacion. El plan sale igual
       sin subir nada, y el material se puede añadir cualquier semana. -->
  <section class="wz-p" data-p="4">
    <div class="wz-bloque wz-medir">
      <b>Lo que pasa con tus fotos y videos</b>
      <ul>
        <li>Puedes dejarlos en tu Biblioteca ahora o <b>más adelante</b>, cualquier semana.</li>
        <li>Los reviso y te propongo usarlos <b>cuando encajen</b> con tu meta —
            no te prometo usarlos todos.</li>
        <li>Si la semana que viene grabas un video, lo subes y ya: <b>sin empezar de nuevo</b>.</li>
        <li>Subir es <b>opcional</b>. Sin material tuyo también preparo las publicaciones.</li>
      </ul>
    </div>

    <div class="wz-mat">
      <span class="wz-mat-est" id="wzMatEstado"><?= $h($wiz_mat_txt) ?></span>
      <input type="file" id="wzMatFile" accept="image/*,video/*" multiple hidden>
      <button type="button" class="wz-mat-bt" id="wzMatAnadir">
        <?= ico('upload') ?>Añadir fotos o videos</button>
      <a class="wz-mat-bt linea" id="wzMatBiblio"
         href="<?= $BASE ?>/biblioteca.php?marca=<?= $marca_id ?>&amp;volver=wizard">
        <?= ico('image') ?>Ver mi Biblioteca</a>
      <p class="wz-mat-err" id="wzMatErr" role="alert" hidden></p>
    </div>

    <?php /*  LO AVANZADO, EN SU CAPA. Presupuesto de anuncios y contexto son
              utiles y opcionales: en el camino obligaban a todos a decidir algo
              que la mayoria no tiene que decidir.  */ ?>
    <button type="button" class="wz-ajustes" id="wzAjustes">
      <?= ico('settings') ?>
      <span class="tx"><b>Anuncios y detalles de tu negocio</b>
        <span id="wzAjustesRes">Opcional — sin anuncios y sin notas</span></span>
      <?= ico('chev-der') ?></button>

    <?php /*  SUS TRES RESPUESTAS, JUNTAS Y ANTES DE PULSAR.
              Al quitar la pantalla de repaso se fue tambien esto, y no debia:
              una cosa es ahorrarle una pantalla y otra pedirle que confirme
              sin ver lo que va a crear. Cabe en tres renglones y cada uno
              vuelve a su paso.  */ ?>
    <div class="wz-res" style="margin-top:16px">
      <div class="wz-fila">
        <span class="wz-fila-tx"><span>Qué quieres lograr</span><b id="rObj">—</b></span>
        <button type="button" class="wz-cambiar" data-ir="1">Cambiar</button>
      </div>
      <div class="wz-fila">
        <span class="wz-fila-tx"><span>Cuánto</span><b id="rCant">—</b></span>
        <button type="button" class="wz-cambiar" data-ir="2">Cambiar</button>
      </div>
      <div class="wz-fila">
        <span class="wz-fila-tx"><span>Para cuándo</span><b id="rFecha">—</b></span>
        <button type="button" class="wz-cambiar" data-ir="3">Cambiar</button>
      </div>
    </div>

    <div class="wz-bloque wz-luego">
      <b>Cuando pulses</b>
      <ol>
        <li>Se crea tu meta con estos números.</li>
        <li>Miro tu negocio, tu Biblioteca y tu calendario, y armo el plan.</li>
        <li>Te llevo directo a revisar tu primera semana.</li>
      </ol>
      <p class="nota">Si no logro armarlo ahora mismo, tu meta queda creada igual y lo reintento
         desde Tu Meta. Nada se pierde.</p>
    </div>
  </section>

  <?php /*  LA CAPA · se abre sobre el paso, y el paso se queda detras. */ ?>
  <div class="wz-hoja-velo" id="wzHojaVelo" role="dialog" aria-modal="true" aria-labelledby="wzHojaT">
    <div class="wz-hoja">
      <div class="cab">
        <h3 id="wzHojaT">Anuncios y detalles</h3>
        <button type="button" id="wzHojaCerrar" aria-label="Cerrar"><?= ico('x') ?></button>
      </div>
      <div class="cuerpo">
        <span class="wz-sub" style="margin-top:0">¿Puedes invertir algo en anuncios?</span>
        <p class="wz-ayuda" style="margin:0 0 12px">Pagarle a Instagram o Facebook para que le enseñen
          tu post a más gente del área. Si ahora no puedes, no hay problema: trabajo sin anuncios y no
          te los recomiendo.</p>
        <div class="wz-chips" id="wzPauta">
          <button type="button" class="wz-chip sel" data-pauta="0">Nada por ahora<small>Todo sin pagar anuncios</small></button>
          <button type="button" class="wz-chip" data-pauta="20">$20 al mes<small>Para empujar 1 o 2 posts</small></button>
          <button type="button" class="wz-chip" data-pauta="50">$50 al mes<small>Alcance serio en tu área</small></button>
          <button type="button" class="wz-chip" data-pauta="100">$100 o más<small>Campaña de verdad</small></button>
        </div>

        <span class="wz-sub">¿Con qué cuentas?</span>
        <p class="wz-ayuda" style="margin:0 0 10px">Una oferta, un producto que quieras empujar, una
          fecha especial. Mientras más me digas, menos genérico sale el plan.</p>
        <textarea class="wz-libre" id="contexto" maxlength="600"
          placeholder="Ej: Tengo el combo de brazo gitano a $18 y en agosto son las fiestas del pueblo."></textarea>

        <div class="pie2">
          <button type="button" class="tm-btn" id="wzHojaListo">Listo</button>
        </div>
      </div>
    </div>
  </div>

  <!-- El fallo se queda dentro de la pantalla, con su salida. -->
  <div class="wz-err" id="wzErr" role="alert" tabindex="-1">
    <?= ico('bolt') ?>
    <div class="wz-err-tx">
      <b id="wzErrT">No se pudo crear la meta</b>
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
    <b>Creando tu meta</b>
    <span>Miro tu negocio, tu Biblioteca y tu calendario<br>para armar el plan. Dale unos segundos.</span>
  </div>

  <details class="wz-glos">
    <summary>¿Qué significan las palabras raras del mercadeo?<?= ico('chev-der') ?></summary>
    <dl>
      <?php foreach ($glosario as $t => $d): ?>
        <dt><?= $h(ucfirst($t)) ?></dt><dd><?= $h($d) ?></dd>
      <?php endforeach; ?>
    </dl>
  </details>
</div>

<script>
(function(){
  var CSRF = <?= json_encode(csrf_token()) ?>, MARCA = <?= (int)$marca_id ?>;
  var VOLVER = <?= json_encode($BASE . '/meta.php?marca=' . $marca_id) ?>;

  //  TODA la respuesta del dueño vive aqui. Los pasos se esconden, no se
  //  destruyen, asi que el DOM tambien la conserva — pero quien manda es este
  //  objeto: es lo que se enseña en el repaso y lo que viaja al servidor.
  var d = { obj:'', titulo:'', unidad:'pedidos', medible:1, medir:'',
            cant:'', dias:30, pauta:0, ctx:'' };
  var paso = 1, enviando = false;

  // ── LAS RESPUESTAS SOBREVIVEN AL VIAJE ───────────────────────────
  //
  //  Vivian solo en este objeto, en memoria. Bastaba con salir a la Biblioteca
  //  a subir una foto —que es justo lo que el ultimo paso invita a hacer— para
  //  perderlo todo y tener que empezar de cero. Abandonar un formulario a la
  //  cuarta pregunta es lo mas facil del mundo.
  //
  //  sessionStorage y NO la base: esto son respuestas a medias, no una meta.
  //  Vive en esta pestaña, muere con ella, y el servidor no se entera de nada
  //  hasta el ultimo boton. La llave lleva la marca dentro: dos negocios en la
  //  misma cuenta no pueden heredarse las respuestas del otro.
  var LLAVE = 'crecer.wizard.meta.' + MARCA;

  function guardar(){
    try { sessionStorage.setItem(LLAVE, JSON.stringify({ d: d, paso: paso })); }
    catch (e) { /* sin almacen: se sigue, solo que sin memoria */ }
  }
  function olvidar(){ try { sessionStorage.removeItem(LLAVE); } catch (e) {} }

  /** Devuelve el paso al que hay que volver, o 0 si no habia nada guardado. */
  function recordar(){
    var crudo;
    try { crudo = sessionStorage.getItem(LLAVE); } catch (e) { return 0; }
    if (!crudo) return 0;
    var g;
    try { g = JSON.parse(crudo); } catch (e) { olvidar(); return 0; }
    if (!g || !g.d || !g.d.obj) return 0;          // sin objetivo no hay nada que restaurar
    d = g.d;

    //  Y SE REPINTA LA PANTALLA CON ELLO. Restaurar el objeto y dejar los
    //  botones sin marcar seria peor que no restaurar: el dueño veria sus
    //  respuestas en el resumen y los controles vacios.
    var b = document.querySelector('.wz-obj[data-obj="' + d.obj + '"]');
    if (b) {
      [].forEach.call(document.querySelectorAll('.wz-obj'), function(x){ x.classList.remove('sel'); });
      b.classList.add('sel');
      if ($('wzUnidad')) $('wzUnidad').textContent = d.unidad;
    }
    if ($('cantidad')) $('cantidad').value = d.cant || '';
    [].forEach.call(document.querySelectorAll('#wzFecha .wz-chip'), function(c){
      c.classList.toggle('sel', +c.dataset.dias === +d.dias);
    });
    [].forEach.call(document.querySelectorAll('#wzPauta .wz-chip'), function(c){
      c.classList.toggle('sel', +c.dataset.pauta === +d.pauta);
    });
    if ($('contexto')) $('contexto').value = d.ctx || '';
    return Math.min(4, Math.max(1, +g.paso || 1));
  }

  var $  = function(i){ return document.getElementById(i); };
  var et = $('wzEt'), q = $('wzQ'), ayuda = $('wzAyuda'), tren = $('wzTren');
  var sigue = $('sigue'), atras = $('atras'), nav = $('wzNav');
  var err = $('wzErr'), load = $('wzLoad');

  var TITULO = [
    null,
    { et:'Qué quieres lograr',  q:'¿Qué quieres lograr?',
      ay:'Escoge una sola. Voy a trabajar para eso — no para llenar el calendario. '
       + 'Nada se crea hasta el último paso.' },
    { et:'Cuánto',              q:'¿Cuánto quieres lograr?',
      ay:'Un número te deja saber si vas bien o si hay que apretar. Si no sabes cuál poner, te lo '
       + 'digo mirando tus propios números.' },
    { et:'Para cuándo',         q:'¿Para cuándo quieres lograrlo?',
      ay:'El plazo decide cuántas semanas de trabajo hay y qué tan seguido publico.' },
    { et:'Tu material',         q:'Tu contenido también puede ser parte del plan',
      ay:'Tus fotos y videos hacen el plan menos genérico. No hace falta subir nada ahora.' }
  ];

  var MESES = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto',
               'septiembre','octubre','noviembre','diciembre'];

  /** La fecha limite en hora LOCAL. toISOString() se lleva un dia por delante:
   *  en Puerto Rico (UTC-4), de las 8 de la noche en adelante ya devuelve el
   *  dia siguiente, y la meta nacia con un dia de mas. */
  function fechaLimite(){
    var f = new Date(); f.setDate(f.getDate() + d.dias);
    return { iso: f.getFullYear() + '-' + String(f.getMonth()+1).padStart(2,'0')
                  + '-' + String(f.getDate()).padStart(2,'0'),
             txt: 'el ' + f.getDate() + ' de ' + MESES[f.getMonth()] };
  }

  // ── PINTAR EL PASO ───────────────────────────────────────────────
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
    et.textContent = 'Paso ' + n + ' de 4 · ' + TITULO[n].et;
    q.textContent  = (n === 2 && d.obj) ? (document.querySelector('.wz-obj.sel').dataset.pregunta)
                                        : TITULO[n].q;
    ayuda.innerHTML = TITULO[n].ay;
    atras.style.display = n > 1 ? '' : 'none';
    sigue.textContent = n === 4 ? 'Continuar con lo que tengo' : 'Siguiente';
    ocultarError();
    if (n === 2) pintarMedir();
    if (n === 3) pintarFecha();
    if (n === 4) { pintarResumen(); pintarAjustes(); }
    guardar();                      // cada paso deja su rastro en la pestaña
    revisar();
    window.scrollTo({ top:0, behavior:'smooth' });
    if (window.crecerMetaRecalcular) setTimeout(window.crecerMetaRecalcular, 60);
  }

  /** Solo se avanza con lo imprescindible de ESE paso. Ni antes ni de mas. */
  function revisar(){
    if (paso === 1)      sigue.disabled = !d.obj;
    else if (paso === 2) sigue.disabled = !(d.cant && +d.cant > 0);
    else if (paso === 3) sigue.disabled = !(d.dias > 0);
    else                 sigue.disabled = enviando;   // el material es opcional
  }

  // ── LO QUE CADA PASO TIENE QUE DECIR ─────────────────────────────
  /**  Es una META, no una promesa. Se dice donde se escribe el numero, y con
   *   las palabras del propio objetivo — que las trae el dominio.  */
  function pintarMedir(){
    var e = $('wzMedirNota');
    if (!e) return;
    e.textContent = d.medir
      ? ('Es una meta, no una garantía: ' + d.medir.charAt(0).toLowerCase() + d.medir.slice(1))
      : 'Es una meta, no una garantía. Sirve para saber si vas bien.';
  }

  /**  La fecha CONCRETA. «En 2 meses» no se puede cruzar con el calendario de
   *   nadie; «el 23 de octubre» si.  */
  function pintarFecha(){
    var f = fechaLimite();
    if ($('wzFechaClara')) $('wzFechaClara').textContent = f.txt.replace(/^el /, 'el ');
    var nota = $('wzFechaNota');
    if (!nota) return;
    //  La restriccion real, al lado de la opcion y no en letra pequeña.
    nota.textContent = d.dias <= 14
      ? 'Con dos semanas el plan sale corto: menos publicaciones y menos margen para corregir.'
      : (d.dias >= 90 ? 'Con tres meses hay sitio para probar y ajustar sobre la marcha.' : '');
  }

  /**  El resumen de la capa, para no obligar a abrirla para saber que hay.  */
  /**  Las tres respuestas, en concreto, encima del boton que las crea.  */
  function pintarResumen(){
    var f = fechaLimite();
    if ($('rObj'))   $('rObj').textContent   = d.titulo || '—';
    if ($('rCant'))  $('rCant').textContent  = d.cant ? (d.cant + ' ' + d.unidad) : '—';
    if ($('rFecha')) $('rFecha').textContent = f.txt + ' · dentro de ' + d.dias + ' días';
  }

  function pintarAjustes(){
    var e = $('wzAjustesRes');
    if (!e) return;
    var ctx = (d.ctx || '').trim();
    var a = d.pauta > 0 ? ('$' + d.pauta + ' al mes en anuncios') : 'Sin anuncios';
    e.textContent = a + ' · ' + (ctx !== '' ? 'con tus notas' : 'sin notas');
  }

  // ── PASO 1 ───────────────────────────────────────────────────────
  [].forEach.call(document.querySelectorAll('.wz-obj'), function(b){
    b.addEventListener('click', function(){
      [].forEach.call(document.querySelectorAll('.wz-obj'), function(x){ x.classList.remove('sel'); });
      b.classList.add('sel');
      d.obj = b.dataset.obj; d.titulo = b.dataset.titulo; d.unidad = b.dataset.unidad;
      guardar();
      d.medible = +b.dataset.medible; d.medir = b.dataset.medir;
      $('wzUnidad').textContent = d.unidad;
      $('wzTip').classList.remove('on');
      revisar();
      //  Que se vea que ya se puede seguir: el boton entra en pantalla solo.
      nav.scrollIntoView({ block:'nearest',
        behavior: matchMedia('(prefers-reduced-motion:reduce)').matches ? 'auto' : 'smooth' });
    });
  });

  // ── PASO 2 ───────────────────────────────────────────────────────
  var cant = $('cantidad');
  cant.addEventListener('input', function(){ d.cant = cant.value; guardar(); revisar(); });

  $('wzFecha').addEventListener('click', function(e){
    var c = e.target.closest('.wz-chip'); if (!c) return;
    [].forEach.call(this.querySelectorAll('.wz-chip'), function(x){ x.classList.remove('sel'); });
    c.classList.add('sel'); d.dias = +c.dataset.dias; pintarFecha(); guardar();
  });

  //  «No sé — dime tú» LEE sus numeros; no escribe nada y no llama a ningun
  //  modelo. Por eso puede vivir a mitad del wizard sin romper la regla de que
  //  aqui solo escribe la confirmacion final.
  $('nose').addEventListener('click', function(){
    var tip = $('wzTip');
    tip.textContent = 'Mirando tus números…'; tip.classList.add('on');
    var fd = new FormData();
    fd.append('csrf', CSRF); fd.append('accion','sugerir');
    fd.append('objetivo', d.obj); fd.append('dias', d.dias);
    fetch(location.pathname + '?marca=' + MARCA, { method:'POST', body:fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (j.ok && j.sugerido) {
          cant.value = j.sugerido; d.cant = String(j.sugerido);
          tip.textContent = j.razon; revisar();
        } else {
          tip.textContent = (j && j.razon) ? j.razon
            : 'Todavía no tengo con qué compararte. Pon el número que te haga sentido.';
        }
      })
      .catch(function(){ tip.textContent = 'No pude mirar tus números ahora. Pon el que te haga sentido.'; });
  });

  // ── PASO 3 ───────────────────────────────────────────────────────
  $('wzPauta').addEventListener('click', function(e){
    var c = e.target.closest('.wz-chip'); if (!c) return;
    [].forEach.call(this.querySelectorAll('.wz-chip'), function(x){ x.classList.remove('sel'); });
    c.classList.add('sel'); d.pauta = +c.dataset.pauta; pintarAjustes(); guardar();
  });
  $('contexto').addEventListener('input', function(){ d.ctx = this.value; pintarAjustes(); guardar(); });

  // ── PASO 4 · las puertas de vuelta de cada linea ─────────────────
  [].forEach.call(document.querySelectorAll('.wz-cambiar'), function(b){
    b.addEventListener('click', function(){ ver(+b.dataset.ir); });
  });

  // ── EL FALLO ─────────────────────────────────────────────────────
  function mostrarError(txt){
    $('wzErrP').textContent = txt;
    err.classList.add('on');
    load.classList.remove('on');
    nav.style.display = '';
    enviando = false; revisar();
    err.focus();
  }
  function ocultarError(){ err.classList.remove('on'); }
  $('wzReintentar').addEventListener('click', function(){ ocultarError(); crear(); });

  // ── CREAR · el unico sitio de todo el wizard que escribe ──────────
  function crear(){
    if (enviando) return;              // doble clic: el segundo no sale de aqui
    enviando = true; sigue.disabled = true;
    d.ctx = $('contexto').value;

    [].forEach.call(document.querySelectorAll('.wz-p'), function(s){ s.classList.remove('on'); });
    nav.style.display = 'none';
    ocultarError();
    load.classList.add('on');
    [].forEach.call(tren.children, function(li){ li.classList.add('ya'); });

    var fd = new FormData();
    fd.append('csrf', CSRF);      fd.append('accion','crear');
    fd.append('objetivo', d.obj); fd.append('cantidad', d.cant);
    fd.append('fecha_limite', fechaLimite().iso);
    fd.append('presupuesto', d.pauta); fd.append('contexto', d.ctx);

    fetch(location.pathname + '?marca=' + MARCA, { method:'POST', body:fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        //  Se vuelve a Tu Meta SIEMPRE que la meta quedo escrita, salga o no el
        //  plan: el compositor recalcula alli y dice la verdad de lo que hay.
        if (j && j.ok) { olvidar(); location.href = VOLVER; return; }
        [].forEach.call(document.querySelectorAll('.wz-p'), function(s){
          s.classList.toggle('on', +s.dataset.p === 4); });
        [].forEach.call(tren.children, function(li){ li.classList.toggle('ya', +li.dataset.t < 4); });
        mostrarError((j && j.err) ? j.err
          : 'No pude crearla ahora mismo. Tus respuestas siguen aquí — dale otra vez.');
      })
      .catch(function(){
        [].forEach.call(document.querySelectorAll('.wz-p'), function(s){
          s.classList.toggle('on', +s.dataset.p === 4); });
        [].forEach.call(tren.children, function(li){ li.classList.toggle('ya', +li.dataset.t < 4); });
        mostrarError('Se cayó la conexión antes de guardar. No se creó nada: dale otra vez cuando vuelva.');
      });
  }

  // ── LA CAPA DE AJUSTES · opcional y con salida ────────────────────
  var velo = $('wzHojaVelo');
  function abrirHoja(){
    velo.classList.add('on');
    var f = velo.querySelector('button, textarea');
    if (f) f.focus();
  }
  function cerrarHoja(){
    d.ctx = $('contexto') ? $('contexto').value : d.ctx;
    pintarAjustes(); guardar();
    velo.classList.remove('on');
    var b = $('wzAjustes'); if (b) b.focus();
  }
  if ($('wzAjustes'))     $('wzAjustes').addEventListener('click', abrirHoja);
  if ($('wzHojaCerrar'))  $('wzHojaCerrar').addEventListener('click', cerrarHoja);
  if ($('wzHojaListo'))   $('wzHojaListo').addEventListener('click', cerrarHoja);
  if (velo) velo.addEventListener('click', function(e){ if (e.target === velo) cerrarHoja(); });
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && velo && velo.classList.contains('on')) cerrarHoja();
  });

  // ── AÑADIR MATERIAL SIN SALIR DEL PASO ───────────────────────────
  //
  //  Se sube por el MISMO handler que usa Biblioteca (`accion=subir`), con un
  //  fetch desde aqui. Asi no se navega, y si no se navega no hay nada que
  //  perder. Ir a Biblioteca sigue estando —para quien quiera verla entera—,
  //  y de ahi se vuelve por su enlace de retorno.
  var matFile = $('wzMatFile'), matBt = $('wzMatAnadir'),
      matEst  = $('wzMatEstado'), matErr = $('wzMatErr');

  if (matBt && matFile) {
    matBt.addEventListener('click', function(){ matFile.click(); });
    matFile.addEventListener('change', function(){
      if (!matFile.files || !matFile.files.length) return;
      matErr.hidden = true;
      var antes = matBt.textContent;
      matBt.disabled = true; matBt.textContent = 'Subiendo…';

      var fd = new FormData();
      fd.append('csrf', CSRF); fd.append('accion', 'subir');
      for (var i = 0; i < matFile.files.length; i++) fd.append('archivos[]', matFile.files[i]);

      fetch(<?= json_encode($BASE . '/biblioteca.php?marca=' . (int)$marca_id) ?>,
            { method:'POST', body:fd, credentials:'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(j){
          matBt.disabled = false; matBt.textContent = antes;
          matFile.value = '';
          if (j && j.ok) {
            //  Se dice CUANTO entro, no «listo»: el dueño acaba de darnos algo
            //  suyo y quiere ver que llego.
            var n = +j.guardados || 0;
            matEst.textContent = n === 1 ? 'Añadiste 1 archivo a tu Biblioteca.'
                                         : ('Añadiste ' + n + ' archivos a tu Biblioteca.');
            if (j.errores && j.errores.length) {
              matErr.hidden = false; matErr.textContent = j.errores.join(' ');
            }
          } else {
            matErr.hidden = false;
            matErr.textContent = (j && j.err) ? j.err
              : 'No pude subirlo. Tus respuestas siguen aquí — inténtalo otra vez.';
          }
        })
        .catch(function(){
          matBt.disabled = false; matBt.textContent = antes; matFile.value = '';
          matErr.hidden = false;
          matErr.textContent = 'Se cayó la conexión. Tus respuestas siguen aquí.';
        });
    });
  }

  // ── LA SALIDA · no deja rastro en el servidor, y lo dice ──────────
  var salir = $('wzSalir');
  if (salir) salir.addEventListener('click', function(){ olvidar(); });

  [].forEach.call(document.querySelectorAll('.wz-cambiar'), function(b){
    b.addEventListener('click', function(){ ver(+b.dataset.ir); });
  });

  atras.addEventListener('click', function(){ if (paso > 1) ver(paso - 1); });
  sigue.addEventListener('click', function(){
    if (paso < 4) { ver(paso + 1); return; }
    crear();
  });

  //  EL SUELO DE LA NAVEGACION ANCLADA. La barra de abajo es fija y tapa lo
  //  que quede debajo; en escritorio no existe y el suelo es 0. Se mide una
  //  vez al cargar y en cada resize — nunca observando lo que uno mismo
  //  escribe, que es como se cuelga una pestaña.
  function suelo(){
    var bn = document.querySelector('.botnav');
    var alto = 0;
    if (bn) { var r = bn.getBoundingClientRect(); if (r.height > 0) alto = Math.round(r.height); }
    document.getElementById('wz').style.setProperty('--wz-suelo', alto + 'px');
  }
  if (document.readyState === 'complete') suelo();
  else window.addEventListener('load', suelo);
  window.addEventListener('resize', suelo);

  //  Si el dueño venia de la Biblioteca, se le devuelve donde estaba.
  ver(recordar() || 1);
})();
</script>

<?php require __DIR__ . '/_meta_zona.php'; ?>
