<?php
// ============================================================
//  CRECER — AJUSTAR UNA META EN MARCHA
//  panel/_meta_ajustar.php  ·  ?vista=ajustar
//
//  Es el wizard que menos parece peligroso y mas lo es: aqui no se crea nada
//  nuevo, se CAMBIA lo que ya esta corriendo. Por eso la pantalla se pasa dos
//  pasos enteros diciendo que NO se toca.
//
//  TRES COSAS QUE ESTA PANTALLA TIENE QUE DEJAR CLARAS
//
//  1. QUE SE QUEDA. Lo primero que piensa quien va a subir su meta de 25 a 40
//     es «¿pierdo lo que llevo?». La respuesta —con numeros suyos, no con una
//     frase amable— va en el repaso, al lado de lo que cambia.
//
//  2. QUE EL PUNTO DE PARTIDA NO SE MUEVE. `base_inicial` es de donde venia.
//     Si cambiara al ajustar, «venias de 10» diria otra cosa mañana y el
//     historial dejaria de significar nada.
//
//  3. QUE CAMBIAR DE OBJETIVO NO ES UN AJUSTE. Se dice en el paso 1 y se
//     enseña la puerta: cambiar de objetivo cambia como se mide, asi que es
//     OTRA META y va por ?vista=cambiar.
//
//  Y una que no se ve pero manda: el token. Se recoge al abrir y viaja con el
//  POST. Si la meta cambio mientras el dueño decidia —otra pestaña, o el cron
//  que la vence— no se escribe NADA y se le enseña como esta ahora. Ni un
//  campo a medias: un ajuste parcial es peor que uno rechazado, porque el
//  dueño cree que guardo lo que veia.
//
//  Comparte piel con los demas wizards (_meta_wizard_piel.php).
// ============================================================

$aj_def   = meta_objetivo_def((string)$meta['objetivo']);
$aj_plan  = meta_plan_activo($pdo, (int)$meta['id']);
$aj_token = meta_token($meta);
$aj_volver = $BASE . '/meta.php?marca=' . $marca_id . '&vista=plan';

//  LO QUE SE QUEDA, EN NUMEROS SUYOS. Si sale 0 se dice 0: una frase
//  tranquilizadora con cifras inventadas es peor que el susto.
$aj_hechas = 0; $aj_piezas = 0; $aj_publicadas = 0; $aj_pendientes = 0;
try {
    $q = $pdo->prepare("SELECT SUM(estado='hecha') h, SUM(estado='pendiente') p
                          FROM crecer_meta_tactica WHERE meta_id=? AND plan_id=?");
    $q->execute([(int)$meta['id'], (int)($aj_plan['id'] ?? 0)]);
    $f = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    $aj_hechas = (int)($f['h'] ?? 0); $aj_pendientes = (int)($f['p'] ?? 0);

    $q2 = $pdo->prepare("SELECT COUNT(*) t, SUM(estado='publicado') p
                           FROM crecer_contenido WHERE marca_id=? AND meta_id=?");
    $q2->execute([$marca_id, (int)$meta['id']]);
    $f2 = $q2->fetch(PDO::FETCH_ASSOC) ?: [];
    $aj_piezas = (int)($f2['t'] ?? 0); $aj_publicadas = (int)($f2['p'] ?? 0);
} catch (Throwable $e) { error_log('_meta_ajustar cuentas: ' . $e->getMessage()); }

//  Las pautas vivas deciden si se puede bajar el presupuesto a cero. Se leen
//  aqui para poder AVISAR antes, en vez de dejar que el servidor diga que no.
$aj_pautas = meta_pautas_vivas($pdo, $marca_id, (int)$meta['id']);

$aj_unidad = (string)($aj_def['unidad'] ?? 'pedidos');
if ($aj_unidad === 'dolares') $aj_unidad = 'dólares';
$aj_cant  = $meta['cantidad'] !== null ? rtrim(rtrim(number_format((float)$meta['cantidad'], 2, '.', ''), '0'), '.') : '';
$aj_pauta = $meta['presupuesto_pauta'] !== null ? (float)$meta['presupuesto_pauta'] : 0;
$aj_base  = $meta['base_inicial'] !== null ? meta_fmt((float)$meta['base_inicial'], (string)$meta['objetivo']) : '';
?>
<?php require_once __DIR__ . '/_meta_wizard_piel.php'; ?>
<style>
  /* — LOS CUATRO CAMPOS, CON SU VALOR DE AHORA A LA VISTA —
     Un ajuste se decide comparando: sin el valor actual delante, «40» no
     significa nada. Por eso la tarjeta enseña el numero grande y el campo
     debajo, no un formulario pelado. */
  .aj-campos{display:grid;gap:10px}
  .aj-campo{display:flex;gap:12px;align-items:center;width:100%;text-align:left;
    border:1px solid var(--line);border-radius:var(--tm-r);background:var(--card,#fff);
    padding:14px;cursor:pointer;font-family:inherit;
    transition:border-color .14s ease, background .14s ease, transform .1s ease}
  .aj-campo:hover{border-color:var(--tm-rosa);background:var(--crema,#FAF7F4)}
  .aj-campo:active{transform:translateY(1px)}
  .aj-campo:focus-visible{outline:2px solid var(--tinta);outline-offset:2px}
  .aj-campo.sel{border-color:var(--tm-rosa);box-shadow:0 0 0 1px var(--tm-rosa);
    background:var(--tm-rosa-piel)}
  .aj-campo-ic{width:44px;height:44px;border-radius:9px;flex:none;background:#F1ECE6;
    display:flex;align-items:center;justify-content:center;color:#A79C90}
  .aj-campo.sel .aj-campo-ic{background:#fff;color:var(--tm-rosa-tx)}
  .aj-campo-ic .ic{width:20px;height:20px;stroke-width:1.8}
  .aj-campo-tx{flex:1;min-width:0}
  .aj-campo-tx span{display:block;font-size:14px;font-weight:600;letter-spacing:.06em;
    text-transform:uppercase;color:var(--muted)}
  .aj-campo-tx b{display:block;font-size:19px;font-weight:700;color:var(--tinta);
    line-height:1.25;margin-top:3px;overflow-wrap:anywhere}
  .aj-campo-ok{flex:none;width:22px;height:22px;color:var(--tm-rosa-tx);opacity:0}
  .aj-campo.sel .aj-campo-ok{opacity:1}
  .aj-campo-ok .ic{width:22px;height:22px;stroke-width:2}

  /* — LA PUERTA DE «ESO ES OTRA META» —
     Sin esto, quien quiere cambiar de objetivo prueba aqui, no lo encuentra y
     se queda sin saber que existe el otro camino. */
  .aj-otro{display:flex;gap:11px;align-items:flex-start;background:var(--crema,#FAF7F4);
    border-radius:var(--tm-r);padding:13px 15px;margin-top:16px;
    font-size:15px;line-height:1.55;color:var(--ink,#4A434F)}
  .aj-otro .ic{width:18px;height:18px;flex:none;margin-top:2px;color:var(--muted);stroke-width:2}
  .aj-otro b{display:block;color:var(--tinta);margin-bottom:2px}
  .aj-otro a{display:inline-flex;align-items:center;min-height:44px;font-weight:600;
    color:var(--tm-rosa-tx);text-decoration:none}
  .aj-otro a:hover{text-decoration:underline}

  /* — EL PASO DE EDITAR · un campo a la vez — */
  .aj-antes{font-size:15px;line-height:1.5;color:var(--muted);margin:0 0 14px}
  .aj-antes b{color:var(--tinta);font-weight:600}
  .aj-aviso{display:flex;gap:10px;align-items:flex-start;background:var(--tm-aviso-piel);
    color:var(--tm-aviso);border-radius:var(--tm-r);padding:13px 15px;margin-top:14px;
    font-size:15px;line-height:1.5}
  .aj-aviso .ic{width:18px;height:18px;flex:none;margin-top:1px;stroke-width:2}
  .aj-aviso b{display:block;margin-bottom:3px}
  .aj-aviso a{color:inherit;font-weight:700}

  /* — EL REPASO · lo que cambia, lo que se queda, lo que hace falta — */
  .aj-bloque{border-radius:var(--tm-r);padding:14px 15px;margin-top:12px}
  .aj-bloque b.et{display:flex;align-items:center;gap:8px;font-size:14px;font-weight:600;
    letter-spacing:.06em;text-transform:uppercase;margin-bottom:9px}
  .aj-bloque b.et .ic{width:16px;height:16px;stroke-width:2}
  .aj-bloque ul{margin:0;padding-left:19px}
  .aj-bloque li{font-size:15px;line-height:1.55;margin-bottom:5px}
  .aj-bloque li:last-child{margin-bottom:0}
  .aj-cambia{background:var(--tm-rosa-piel);color:var(--tm-rosa-tx)}
  .aj-queda{background:var(--tm-teal-piel);color:var(--tm-teal-tx)}
  .aj-falta{background:var(--tm-aviso-piel);color:var(--tm-aviso)}
  .aj-fila{display:flex;gap:10px;align-items:baseline;font-size:16px;line-height:1.5;
    margin-bottom:6px;flex-wrap:wrap}
  .aj-fila:last-child{margin-bottom:0}
  .aj-fila span{font-size:14px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;
    opacity:.75;min-width:84px}
  .aj-fila b{font-weight:600}
  .aj-fila i{font-style:normal;opacity:.6}

  /*  LA CASILLA NACE APAGADA, Y ESO NO ES UN DETALLE: rehacer el plan cuesta
      una llamada a la Estratega y se lleva por delante las jugadas que el dueño
      todavia no ha hecho. Se pide, no se asume.  */
  .aj-replan{display:flex;gap:11px;align-items:flex-start;border:1px solid var(--line);
    border-radius:var(--tm-r);padding:13px 15px;margin-top:12px;cursor:pointer;
    background:var(--card,#fff)}
  .aj-replan:hover{border-color:var(--tm-rosa)}
  .aj-replan input{width:22px;height:22px;flex:none;margin-top:1px;accent-color:var(--tm-rosa-bt)}
  .aj-replan span{font-size:15px;line-height:1.5;color:var(--ink,#4A434F)}
  .aj-replan span b{display:block;color:var(--tinta);font-size:16px;margin-bottom:2px}

  @media (min-width:1000px){
    .aj-campos{grid-template-columns:1fr 1fr}
    .aj-repaso2{display:grid;grid-template-columns:1fr 1fr;gap:12px;align-items:start}
    .aj-repaso2 .aj-bloque{margin-top:0}
  }
</style>

<div class="wz" id="wz" data-flujo="ajustar" data-token="<?= $h($aj_token) ?>">

  <a href="<?= $h($aj_volver) ?>" class="wz-salir" id="wzSalir">
    <?= ico('chev-der') ?>Salir sin cambiar nada</a>

  <ol class="wz-tren" id="wzTren" aria-hidden="true">
    <li data-t="1"><i></i><span>Qué ajustas</span></li>
    <li data-t="2"><i></i><span>El valor nuevo</span></li>
    <li data-t="3"><i></i><span>Repasar</span></li>
  </ol>

  <span class="wz-et" id="wzEt">Paso 1 de 3</span>
  <h1 class="wz-q" id="wzQ">—</h1>
  <p class="wz-ayuda" id="wzAyuda">—</p>

  <!-- ══ PASO 1 · que se ajusta ═════════════════════════════════════ -->
  <section class="wz-p on" data-p="1">
    <div class="aj-campos" id="ajCampos">
      <button type="button" class="aj-campo" data-campo="cantidad"
              data-et="Cuánto quieres lograr" data-actual="<?= $h($aj_cant) ?>">
        <span class="aj-campo-ic"><?= ico('target') ?></span>
        <span class="aj-campo-tx"><span>El número</span>
          <b><?= $aj_cant !== '' ? $h($aj_cant . ' ' . $aj_unidad) : 'Sin número' ?></b></span>
        <span class="aj-campo-ok"><?= ico('check-circle') ?></span>
      </button>
      <button type="button" class="aj-campo" data-campo="fecha_limite"
              data-et="Para cuándo" data-actual="<?= $h((string)$meta['fecha_limite']) ?>">
        <span class="aj-campo-ic"><?= ico('calendar') ?></span>
        <span class="aj-campo-tx"><span>La fecha</span>
          <b id="ajFechaHoy"><?= $h((string)$meta['fecha_limite'] ?: 'Sin fecha') ?></b></span>
        <span class="aj-campo-ok"><?= ico('check-circle') ?></span>
      </button>
      <button type="button" class="aj-campo" data-campo="presupuesto_pauta"
              data-et="Inversión en anuncios" data-actual="<?= (int)$aj_pauta ?>">
        <span class="aj-campo-ic"><?= ico('dollar') ?></span>
        <span class="aj-campo-tx"><span>La inversión</span>
          <b><?= $aj_pauta > 0 ? '$' . $h((string)(int)$aj_pauta) . ' al mes' : 'Nada por ahora' ?></b></span>
        <span class="aj-campo-ok"><?= ico('check-circle') ?></span>
      </button>
      <button type="button" class="aj-campo" data-campo="contexto"
              data-et="Lo que me contaste" data-actual="<?= $h((string)$meta['contexto']) ?>">
        <span class="aj-campo-ic"><?= ico('pen') ?></span>
        <span class="aj-campo-tx"><span>Lo que me contaste</span>
          <b><?= (string)$meta['contexto'] !== ''
                ? $h(mb_strimwidth((string)$meta['contexto'], 0, 60, '…')) : 'No me contaste nada' ?></b></span>
        <span class="aj-campo-ok"><?= ico('check-circle') ?></span>
      </button>
    </div>

    <?php /*  LA PUERTA AL OTRO CAMINO. Cambiar de objetivo cambia COMO se mide
              —unidad, si es medible, contra que se compara—, asi que lo que
              llevas medido dejaria de ser comparable. Eso es otra meta.  */ ?>
    <div class="aj-otro">
      <?= ico('compass') ?>
      <div><b>¿Quieres perseguir otra cosa?</b>
        Cambiar de objetivo cambia cómo se mide, así que lo que llevas dejaría de
        poder compararse. Eso es empezar otra meta, y tiene su propio camino.
        <a href="<?= $BASE ?>/meta.php?marca=<?= $marca_id ?>&amp;vista=cambiar">Cambiar de meta<?= ico('chev-der') ?></a>
      </div>
    </div>
  </section>

  <!-- ══ PASO 2 · el valor nuevo ════════════════════════════════════ -->
  <section class="wz-p" data-p="2">
    <p class="aj-antes" id="ajAntes">—</p>

    <div id="ajEdCantidad" class="aj-ed" hidden>
      <div class="wz-num">
        <input type="number" id="cantidad" min="1" step="1" inputmode="numeric"
               aria-label="Cuánto quieres lograr">
        <span class="wz-unidad"><?= $h($aj_unidad) ?></span>
      </div>
    </div>

    <div id="ajEdFecha" class="aj-ed" hidden>
      <div class="wz-chips" id="ajFecha">
        <button type="button" class="wz-chip" data-dias="14">En 2 semanas</button>
        <button type="button" class="wz-chip" data-dias="30">En un mes</button>
        <button type="button" class="wz-chip" data-dias="60">En 2 meses</button>
        <button type="button" class="wz-chip" data-dias="90">En 3 meses</button>
      </div>
    </div>

    <div id="ajEdPauta" class="aj-ed" hidden>
      <div class="wz-chips" id="ajPauta">
        <button type="button" class="wz-chip" data-pauta="0">Nada por ahora<small>Sin pagar anuncios</small></button>
        <button type="button" class="wz-chip" data-pauta="20">$20 al mes<small>1 o 2 posts</small></button>
        <button type="button" class="wz-chip" data-pauta="50">$50 al mes<small>Alcance serio</small></button>
        <button type="button" class="wz-chip" data-pauta="100">$100 o más<small>Campaña de verdad</small></button>
      </div>
      <?php if ($aj_pautas): ?>
        <?php /*  SE AVISA ANTES, no cuando el servidor diga que no. El motor ya
                  prohibe recomendar pauta sin presupuesto: dejar vivas las que
                  hay seria pedirle al dueño un dinero que acaba de decir que no
                  tiene.  */ ?>
        <div class="aj-aviso" id="ajAvisoPauta" hidden>
          <?= ico('bolt') ?>
          <div><b>Tienes <?= count($aj_pautas) ?> jugada<?= count($aj_pautas) === 1 ? '' : 's' ?> que todavía pide anuncios</b>
            Para quitar el presupuesto hay que cambiar<?= count($aj_pautas) === 1 ? 'la' : 'las' ?> primero:
            <?= $h(implode(' · ', array_map(fn($p) => mb_strimwidth((string)$p['titulo'], 0, 40, '…'), $aj_pautas))) ?>.
            <a href="<?= $BASE ?>/meta.php?marca=<?= $marca_id ?>&amp;vista=sustituir&amp;jugada=<?= (int)$aj_pautas[0]['id'] ?>">Cambiar esa jugada<?= ico('chev-der') ?></a>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <div id="ajEdContexto" class="aj-ed" hidden>
      <textarea class="wz-libre" id="contexto" maxlength="600"
        placeholder="Ej: Tengo el combo de brazo gitano a $18 y en agosto son las fiestas del pueblo."></textarea>
    </div>

    <span class="wz-sub">¿Por qué lo cambias? (Opcional)</span>
    <p class="wz-ayuda" style="margin:0 0 10px">Queda escrito en el historial de tu meta, para que
      dentro de un mes se entienda por qué el número es otro.</p>
    <textarea class="wz-libre" id="ajMotivo" maxlength="190" style="min-height:80px"
      placeholder="Ej: me está entrando más de lo que pensaba."></textarea>
  </section>

  <!-- ══ PASO 3 · el repaso ═════════════════════════════════════════ -->
  <section class="wz-p" data-p="3">
    <div class="aj-repaso2">
      <div class="aj-bloque aj-cambia">
        <b class="et"><?= ico('edit') ?>Lo que cambia</b>
        <div id="ajCambia"></div>
      </div>

      <?php /*  LO QUE SE QUEDA, CON SUS NUMEROS. Es la pregunta que de verdad
                tiene quien va a tocar una meta en marcha.  */ ?>
      <div class="aj-bloque aj-queda">
        <b class="et"><?= ico('check') ?>Lo que se queda</b>
        <ul>
          <li><?= $aj_hechas ?> <?= $aj_hechas === 1 ? 'jugada hecha' : 'jugadas hechas' ?> — siguen hechas.</li>
          <li><?= $aj_piezas ?> <?= $aj_piezas === 1 ? 'pieza' : 'piezas' ?><?php
              if ($aj_publicadas > 0): ?>, <?= $aj_publicadas ?> ya <?= $aj_publicadas === 1 ? 'publicada' : 'publicadas' ?><?php endif; ?>.
            Todo sigue en Tus Posts.</li>
          <?php if ($aj_base !== ''): ?>
            <li>Tu punto de partida no se mueve: seguías en <b><?= $h($aj_base) ?></b>.</li>
          <?php endif; ?>
          <li>Tu marca y tu Genoma.</li>
        </ul>
      </div>
    </div>

    <div class="aj-bloque aj-falta" id="ajFalta" hidden>
      <b class="et"><?= ico('bolt') ?>Lo que puede hacer falta</b>
      <ul><li id="ajFaltaTx"></li></ul>
    </div>

    <label class="aj-replan">
      <input type="checkbox" id="ajReplan">
      <span><b>Pídele a la Estratega un plan nuevo con estos números</b>
        Opcional. Las <?= $aj_pendientes ?> jugada<?= $aj_pendientes === 1 ? '' : 's' ?> que aún no has hecho
        se cambiarían por otras; lo hecho y lo publicado no se toca.</span>
    </label>
  </section>

  <div class="wz-err" id="wzErr" role="alert" tabindex="-1">
    <?= ico('bolt') ?>
    <div class="wz-err-tx">
      <b id="wzErrT">No se pudo ajustar</b>
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
    <b>Guardando el ajuste</b>
    <span>Anoto el valor de antes y el de ahora<br>para que quede en tu historial.</span>
  </div>
</div>

<script>
(function(){
  var CSRF  = <?= json_encode(csrf_token()) ?>, MARCA = <?= (int)$marca_id ?>;
  var VOLVER = <?= json_encode($aj_volver) ?>;
  //  EL TOKEN. Se recoge al pintar y viaja con el POST: si la meta cambio
  //  mientras el dueño decidia, el servidor no escribe NADA y lo dice.
  var TOKEN  = document.getElementById('wz').dataset.token || '';
  var HAY_PAUTAS = <?= $aj_pautas ? 'true' : 'false' ?>;
  var UNIDAD = <?= json_encode($aj_unidad) ?>;

  var ACTUAL = {
    cantidad: <?= json_encode($aj_cant) ?>,
    fecha_limite: <?= json_encode((string)$meta['fecha_limite']) ?>,
    presupuesto_pauta: <?= json_encode((string)(int)$aj_pauta) ?>,
    contexto: <?= json_encode((string)$meta['contexto']) ?>
  };
  var ETIQUETA = { cantidad:'Cuánto', fecha_limite:'Para cuándo',
                   presupuesto_pauta:'Inversión', contexto:'Lo que contaste' };

  var d = { campo:'', valor:'', motivo:'', replan:false, dias:0 };
  var paso = 1, enviando = false;

  var $ = function(i){ return document.getElementById(i); };
  var et = $('wzEt'), q = $('wzQ'), ayuda = $('wzAyuda'), tren = $('wzTren');
  var sigue = $('sigue'), atras = $('atras'), nav = $('wzNav');
  var err = $('wzErr'), load = $('wzLoad');

  var TITULO = [ null,
    { et:'Qué ajustas', q:'¿Qué quieres ajustar?',
      ay:'Tu meta sigue siendo la misma y lo que llevas hecho no se toca. Nada cambia hasta el último paso.' },
    { et:'El valor nuevo', q:'—',
      ay:'Te enseño lo que dice ahora para que compares. Todavía no he guardado nada.' },
    { et:'Repasar', q:'Repasa antes de ajustar',
      ay:'Esto es lo que voy a cambiar y lo que se queda igual. Todavía no he tocado nada.' } ];

  var MESES = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto',
               'septiembre','octubre','noviembre','diciembre'];
  function fechaDe(dias){
    var f = new Date(); f.setDate(f.getDate() + dias);
    return { iso: f.getFullYear() + '-' + String(f.getMonth()+1).padStart(2,'0')
                  + '-' + String(f.getDate()).padStart(2,'0'),
             txt: 'el ' + f.getDate() + ' de ' + MESES[f.getMonth()] };
  }
  function fechaTxt(iso){
    if (!iso) return 'sin fecha';
    var p = String(iso).slice(0,10).split('-');
    if (p.length !== 3) return String(iso);
    return 'el ' + (+p[2]) + ' de ' + MESES[(+p[1]) - 1];
  }
  function comoSeVe(campo, v){
    if (campo === 'cantidad')          return (v === '' ? 'sin número' : v + ' ' + UNIDAD);
    if (campo === 'fecha_limite')      return fechaTxt(v);
    if (campo === 'presupuesto_pauta') return (+v > 0 ? '$' + (+v) + ' al mes' : 'nada por ahora');
    return (String(v).trim() === '' ? 'nada' : '«' + String(v).trim() + '»');
  }

  function ver(n){
    paso = n;
    [].forEach.call(document.querySelectorAll('.wz-p'), function(s){
      s.classList.toggle('on', +s.dataset.p === n); });
    [].forEach.call(tren.children, function(li){
      var i = +li.dataset.t;
      li.classList.toggle('ya', i < n); li.classList.toggle('on', i === n);
    });
    et.textContent = 'Paso ' + n + ' de 3 · ' + TITULO[n].et;
    q.textContent  = (n === 2 && d.campo) ? ('¿' + ETIQUETA[d.campo] + '?') : TITULO[n].q;
    ayuda.textContent = TITULO[n].ay;
    atras.style.display = n > 1 ? '' : 'none';
    sigue.textContent = n === 3 ? 'Ajustar mi meta' : 'Siguiente';
    ocultarError();
    if (n === 2) pintarEditor();
    if (n === 3) repasar();
    revisar();
    window.scrollTo({ top:0, behavior:'smooth' });
    if (window.crecerMetaRecalcular) setTimeout(window.crecerMetaRecalcular, 60);
  }

  function revisar(){
    if (paso === 1) { sigue.disabled = !d.campo; return; }
    if (paso === 2) {
      //  No se avanza con el MISMO valor: eso no es un ajuste, es un viaje.
      var igual = String(d.valor) === String(ACTUAL[d.campo] || '');
      var vacio = (d.campo === 'cantidad' && !(+d.valor > 0));
      //  Y no se deja quitar el presupuesto con pautas vivas: se avisa aqui,
      //  no despues de que el servidor diga que no.
      var choca = (d.campo === 'presupuesto_pauta' && +d.valor === 0 && HAY_PAUTAS);
      var av = $('ajAvisoPauta'); if (av) av.hidden = !choca;
      sigue.disabled = igual || vacio || choca;
      return;
    }
    sigue.disabled = enviando;
  }

  function pintarEditor(){
    [].forEach.call(document.querySelectorAll('.aj-ed'), function(e){ e.hidden = true; });
    $('ajAntes').innerHTML = 'Ahora dice <b>' + comoSeVe(d.campo, ACTUAL[d.campo]) + '</b>.';
    if (d.campo === 'cantidad') {
      $('ajEdCantidad').hidden = false;
      $('cantidad').value = d.valor || ACTUAL.cantidad; $('cantidad').focus();
    } else if (d.campo === 'fecha_limite') {
      $('ajEdFecha').hidden = false;
    } else if (d.campo === 'presupuesto_pauta') {
      $('ajEdPauta').hidden = false;
    } else {
      $('ajEdContexto').hidden = false;
      $('contexto').value = d.valor !== '' ? d.valor : ACTUAL.contexto;
    }
  }

  function repasar(){
    $('ajCambia').innerHTML =
      '<div class="aj-fila"><span>' + ETIQUETA[d.campo] + '</span>'
      + '<b>' + comoSeVe(d.campo, ACTUAL[d.campo]) + '</b><i>→</i>'
      + '<b>' + comoSeVe(d.campo, d.valor) + '</b></div>';

    //  «LO QUE PUEDE HACER FALTA» solo sale cuando de verdad hace falta. Un
    //  aviso que sale siempre deja de leerse.
    var falta = '';
    if (d.campo === 'cantidad' && +d.valor > +(ACTUAL.cantidad || 0)) {
      falta = 'Le subiste el listón. El plan de ahora se armó para '
            + comoSeVe('cantidad', ACTUAL.cantidad) + '.';
    } else if (d.campo === 'fecha_limite' && d.dias > 0 && d.dias <= 14) {
      falta = 'Te queda poco tiempo: las jugadas de las últimas semanas puede que ya no den.';
    } else if (d.campo === 'presupuesto_pauta' && +d.valor > +(ACTUAL.presupuesto_pauta || 0)) {
      falta = 'Ahora puedes pautar. El plan de ahora se armó sin ese dinero.';
    }
    $('ajFalta').hidden = (falta === '');
    $('ajFaltaTx').textContent = falta;
  }

  // ── PASO 1 ───────────────────────────────────────────────────────
  $('ajCampos').addEventListener('click', function(e){
    var b = e.target.closest('.aj-campo'); if (!b) return;
    [].forEach.call(this.querySelectorAll('.aj-campo'), function(x){ x.classList.remove('sel'); });
    b.classList.add('sel');
    if (d.campo !== b.dataset.campo) { d.valor = ''; d.dias = 0; }
    d.campo = b.dataset.campo;
    revisar();
    nav.scrollIntoView({ block:'nearest',
      behavior: matchMedia('(prefers-reduced-motion:reduce)').matches ? 'auto' : 'smooth' });
  });

  // ── PASO 2 ───────────────────────────────────────────────────────
  $('cantidad').addEventListener('input', function(){ d.valor = this.value; revisar(); });
  $('contexto').addEventListener('input', function(){ d.valor = this.value; revisar(); });
  $('ajMotivo').addEventListener('input', function(){ d.motivo = this.value; });
  $('ajFecha').addEventListener('click', function(e){
    var c = e.target.closest('.wz-chip'); if (!c) return;
    [].forEach.call(this.querySelectorAll('.wz-chip'), function(x){ x.classList.remove('sel'); });
    c.classList.add('sel');
    d.dias = +c.dataset.dias; d.valor = fechaDe(d.dias).iso; revisar();
  });
  $('ajPauta').addEventListener('click', function(e){
    var c = e.target.closest('.wz-chip'); if (!c) return;
    [].forEach.call(this.querySelectorAll('.wz-chip'), function(x){ x.classList.remove('sel'); });
    c.classList.add('sel'); d.valor = c.dataset.pauta; revisar();
  });
  $('ajReplan').addEventListener('change', function(){ d.replan = this.checked; });

  // ── EL FALLO, DENTRO ─────────────────────────────────────────────
  function mostrarError(txt, titulo){
    $('wzErrT').textContent = titulo || 'No se pudo ajustar';
    $('wzErrP').textContent = txt;
    err.classList.add('on'); load.classList.remove('on'); nav.style.display = '';
    [].forEach.call(document.querySelectorAll('.wz-p'), function(s){
      s.classList.toggle('on', +s.dataset.p === 3); });
    [].forEach.call(tren.children, function(li){ li.classList.toggle('ya', +li.dataset.t < 3); });
    enviando = false; revisar(); err.focus();
  }
  function ocultarError(){ err.classList.remove('on'); }
  $('wzReintentar').addEventListener('click', function(){ ocultarError(); guardar(); });

  // ── LA UNICA ESCRITURA ───────────────────────────────────────────
  function guardar(){
    if (enviando) return;
    enviando = true; sigue.disabled = true;
    [].forEach.call(document.querySelectorAll('.wz-p'), function(s){ s.classList.remove('on'); });
    nav.style.display = 'none'; ocultarError(); load.classList.add('on');
    [].forEach.call(tren.children, function(li){ li.classList.add('ya'); });

    var fd = new FormData();
    fd.append('csrf', CSRF); fd.append('accion','ajustar');
    fd.append('token', TOKEN);
    fd.append(d.campo, d.valor);
    fd.append('motivo', d.motivo);
    if (d.replan) fd.append('plan_nuevo', '1');

    fetch(location.pathname + '?marca=' + MARCA, { method:'POST', body:fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (j && j.ok) { location.href = VOLVER; return; }
        //  «Cambió mientras decidías» NO es un error: es información, y se dice
        //  distinto. Confundirlos deja al dueño creyendo que se rompió algo.
        if (j && j.motivo === 'concurrencia') {
          mostrarError((j.err || 'Tu meta cambió mientras decidías y no toqué nada.')
            + ' Vuelve a abrirla para verla como está ahora.',
            'Esto cambió mientras decidías');
          return;
        }
        mostrarError((j && j.err) ? j.err : 'No pude guardarlo. Nada cambió — dale otra vez.');
      })
      .catch(function(){
        mostrarError('Se cayó la conexión antes de guardar. No cambió nada.');
      });
  }

  atras.addEventListener('click', function(){ if (paso > 1) ver(paso - 1); });
  sigue.addEventListener('click', function(){
    if (paso < 3) { ver(paso + 1); return; }
    guardar();
  });

  ver(1);
})();
</script>

<?php require __DIR__ . '/_meta_zona.php'; ?>
