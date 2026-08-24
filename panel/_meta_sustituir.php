<?php
// ============================================================
//  CRECER — SUSTITUIR UNA JUGADA QUE EL DUEÑO NO PUEDE HACER
//  panel/_meta_sustituir.php  ·  ?vista=sustituir&jugada=N
//
//  Hasta aqui, cuando el plan pedia algo que el dueño no tenia —un video, una
//  foto, dinero, tiempo— la unica salida era no hacerlo. La jugada se quedaba
//  pendiente para siempre, mandando la pantalla, y el mes entero se atascaba
//  detras de una tarea imposible. Esta pantalla es la puerta que faltaba.
//
//  LO QUE NO PUEDE PASAR AQUI, Y POR QUE
//
//  1. NO SE BORRA LA ORIGINAL. Se llevaria por delante la razon y las piezas
//     que ya salieron de ella.
//  2. NO SE MARCA HECHA. Seria contarle al dueño un trabajo que nunca ocurrio,
//     e inflar el unico numero que mira.
//     Queda `descartada` con su sello, y en pantalla se llama SUSTITUIDA.
//  3. LA ALTERNATIVA NO PUEDE VOLVER A PEDIRLE LO MISMO. Quien dice «no tengo
//     video» no puede recibir otro reel. El formato se valida contra el motivo
//     —en el servidor, no aqui— y si el modelo insiste, se rechaza.
//
//  EL ORDEN IMPORTA: la Estratega se llama en el paso 2, ANTES de que exista
//  ninguna transaccion, y su propuesta se enseña. Lo que escribe es el boton
//  del paso 3. Entre medias no hay nada abierto.
//
//  Comparte piel con los demas wizards (_meta_wizard_piel.php).
// ============================================================

$su_id = isset($_GET['jugada']) ? (int)$_GET['jugada'] : 0;
$su_q  = $pdo->prepare("SELECT * FROM crecer_meta_tactica WHERE id=? AND marca_id=?");
$su_q->execute([$su_id, $marca_id]);
$su_jug = $su_q->fetch(PDO::FETCH_ASSOC) ?: [];

//  El plan vigente manda: sustituir en uno cerrado meteria una jugada viva en
//  un historial que ya se midio.
$su_plan  = meta_plan_activo($pdo, (int)$meta['id']);
$su_vale  = $su_jug
    && in_array((string)$su_jug['estado'], ['pendiente', 'en_curso'], true)
    && !meta_fue_sustituida($su_jug)
    && $su_plan && (int)$su_plan['id'] === (int)$su_jug['plan_id'];

//  Se vuelve A DONDE VINO. Desde el plan, al plan; desde la tarjeta de «lo que
//  toca ahora» (estados G y H), a lo que toca ahora — que es donde el dueño
//  estaba mirando. Devolverlo siempre al plan lo dejaria perdido.
//
//  Y DESDE LA REVISION DE LA SEMANA, a la MISMA publicacion. Volver a la 1 de
//  3 despues de cambiar la 2 le hace repasar dos veces lo que ya decidio; a la
//  tercera vez abandona. La posicion la valida MetaRetorno —es un entero
//  pequeño y nada mas, nunca un destino—, asi que un `pos` inventado no puede
//  mandar a ningun sitio: como mucho, a una posicion que la vista recorta.
$su_desde  = (string)($_GET['desde'] ?? '');
$su_pos    = MetaRetorno::posicion($_GET);
if ($su_desde === 'semana' && $su_pos !== null) {
    $su_volver = $BASE . '/meta.php?marca=' . $marca_id . '&vista=semana&pos=' . $su_pos;
} else {
    $su_volver = $BASE . '/meta.php?marca=' . $marca_id
               . ($su_desde === 'ahora' ? '' : '&vista=plan');
}
$su_token  = $su_vale ? meta_token_jugada($su_jug) : '';

// ── LA PIEZA QUE YA ESTA COMPROMETIDA ────────────────────────
/*  EL AGUJERO QUE ESTO TAPA. Sustituir la jugada NO tocaba sus piezas. Si una
    estaba aprobada o programada con fecha, seguia siendo publicable: el dueño
    decia «no puedo grabar ese reel», la cambiaba por otra cosa, y el martes le
    salia publicado el reel de todas formas — o su version vieja compitiendo
    con la nueva.

    «Comprometida» NO es «fecha vencida»: el publicador toma `aprobado` igual
    que `programado` en cuanto la fecha llega, asi que una fecha FUTURA
    compromete tambien — solo que todavia no llego.

    Y no se decide por el: quitar del calendario algo que el aprobo es suyo.
    Se le pregunta ANTES, en su propia pantalla, con la consecuencia delante.  */
require_once __DIR__ . '/../includes/meta_semana.php';
$su_comp   = $su_vale ? semana_compromiso($pdo, $marca_id, $su_id)
                      : ['clase' => 'ninguna', 'pieza' => null];
$su_exige  = $su_vale && semana_exige_decision((string)$su_comp['clase']);
//  `quitar=1` solo significa algo si de verdad hay algo que quitar. Sin
//  compromiso vivo se ignora y el recorrido es el de siempre.
$su_quitar = $su_exige && !empty($_GET['quitar']);
$su_puerta = $su_exige && !$su_quitar;          // hay que preguntar primero
$su_pza    = $su_comp['pieza'] ?? null;
$su_cuando = semana_cuando($su_pza['fecha_programada'] ?? null);
//  El aviso de cuota SOLO si el asiento demuestra que la imagen se entrego:
//  reservado, liberado, exento o cero unidades no gastaron nada, y decir que si
//  seria cobrarle de palabra algo que no pago.
$su_cuota  = $su_pza ? semana_aviso_cuota($pdo, $marca_id, (int)$su_pza['id']) : false;

//  Las piezas que ya salieron de esta jugada. Se cuentan para poder DECIRLO en
//  el repaso: es la duda de quien ya vio trabajo hecho colgando de ella.
$su_piezas = 0; $su_publicadas = 0;
if ($su_vale) {
    try {
        $q2 = $pdo->prepare("SELECT COUNT(*) t, SUM(estado='publicado') p
                               FROM crecer_contenido WHERE tactica_id=? AND marca_id=?");
        $q2->execute([$su_id, $marca_id]);
        $f2 = $q2->fetch(PDO::FETCH_ASSOC) ?: [];
        $su_piezas = (int)($f2['t'] ?? 0); $su_publicadas = (int)($f2['p'] ?? 0);
    } catch (Throwable $e) {}
}

$SU_FORMATOS = ['post' => 'Post', 'carrusel' => 'Carrusel', 'reel' => 'Reel',
                'historia' => 'Historia', 'mixto' => 'Varias piezas'];
?>
<?php require_once __DIR__ . '/_meta_wizard_piel.php'; ?>
<style>
  /* — LA JUGADA DE LA QUE HABLAMOS, SIEMPRE DELANTE —
     Sin ella, «¿qué no puedes con esta?» es una pregunta sin sujeto. */
  .su-jug{border:1px solid var(--line);border-radius:var(--tm-r);background:var(--crema,#FAF7F4);
    padding:13px 15px;margin-bottom:16px}
  .su-jug span.et{display:block;font-size:14px;font-weight:600;letter-spacing:.06em;
    text-transform:uppercase;color:var(--muted);margin-bottom:5px}
  .su-jug b{display:block;font-size:17px;line-height:1.35;color:var(--tinta);font-weight:600}
  .su-jug p{font-size:15px;line-height:1.5;color:var(--ink,#4A434F);margin:6px 0 0}
  .su-jug small{display:block;font-size:14px;color:var(--muted);margin-top:6px}

  /* — LOS MOTIVOS · cerrados a proposito —
     Cada uno decide QUE alternativa vale. Un campo libre obligaria a
     preguntarle al modelo que quiso decir el dueño. */
  .su-mot{display:grid;gap:9px}
  .su-mot button{display:flex;align-items:center;gap:12px;width:100%;text-align:left;
    min-height:56px;padding:12px 14px;border:1px solid var(--line);border-radius:var(--tm-r);
    background:var(--card,#fff);font-family:inherit;font-size:16px;font-weight:600;
    color:var(--tinta);cursor:pointer;transition:border-color .14s ease, background .14s ease}
  .su-mot button:hover{border-color:var(--tm-rosa);background:var(--crema,#FAF7F4)}
  .su-mot button:focus-visible{outline:2px solid var(--tinta);outline-offset:2px}
  .su-mot button.sel{border-color:var(--tm-rosa);box-shadow:0 0 0 1px var(--tm-rosa);
    background:var(--tm-rosa-piel);color:var(--tm-rosa-tx)}
  .su-mot .pt{width:20px;height:20px;border-radius:50%;border:2px solid var(--line);flex:none}
  .su-mot button.sel .pt{border-color:var(--tm-rosa);background:var(--tm-rosa);
    box-shadow:inset 0 0 0 3px #fff}

  /* — EL CAMBIO, DE UNA OJEADA: lo que se va y lo que viene — */
  .su-cambio{border:1px solid var(--line);border-radius:var(--tm-r);overflow:hidden;
    background:var(--card,#fff)}
  .su-lado{padding:14px 15px}
  .su-lado + .su-lado{border-top:1px solid var(--line)}
  .su-lado span.et{display:flex;align-items:center;gap:7px;font-size:14px;font-weight:600;
    letter-spacing:.06em;text-transform:uppercase;margin-bottom:7px}
  .su-lado span.et .ic{width:16px;height:16px;stroke-width:2}
  .su-lado b{display:block;font-size:17px;line-height:1.35;font-weight:600}
  .su-lado p{font-size:15px;line-height:1.5;margin:6px 0 0}
  .su-va{background:var(--crema,#FAF7F4)}
  .su-va span.et{color:var(--muted)} .su-va b{color:var(--muted)} .su-va p{color:var(--muted)}
  .su-viene span.et{color:var(--tm-teal-tx)} .su-viene b{color:var(--tinta)}
  .su-viene p{color:var(--ink,#4A434F)}
  .su-tags{display:flex;gap:7px;flex-wrap:wrap;margin-top:10px}
  .su-tag{display:inline-flex;align-items:center;gap:6px;font-size:14px;color:var(--tm-teal-tx);
    background:var(--tm-teal-piel);border-radius:99px;padding:5px 11px}
  .su-tag .ic{width:15px;height:15px;stroke-width:1.9}

  .su-pensando{display:flex;gap:12px;align-items:center;border:1px solid var(--line);
    border-radius:var(--tm-r);padding:16px;background:var(--card,#fff)}
  .su-pensando .sp{width:26px;height:26px;flex:none;border-radius:50%;
    border:3px solid var(--line);border-top-color:var(--tm-rosa);animation:wzGira .8s linear infinite}
  .su-pensando b{display:block;font-size:16px;color:var(--tinta)}
  .su-pensando span{display:block;font-size:14px;color:var(--muted);margin-top:2px}

  .su-otra{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:48px;
    width:100%;margin-top:12px;padding:0 16px;border:1px solid var(--line);
    border-radius:var(--tm-r-bt);background:transparent;font-family:inherit;font-size:15px;
    font-weight:600;color:var(--tinta);cursor:pointer}
  .su-otra:hover{border-color:var(--tm-rosa);color:var(--tm-rosa-tx)}
  .su-otra:focus-visible{outline:2px solid var(--tinta);outline-offset:2px}
  .su-otra .ic{width:17px;height:17px;stroke-width:2}

  .su-nada{border:1px solid var(--line);border-radius:var(--tm-r);padding:18px;
    background:var(--tm-aviso-piel);color:var(--tm-aviso)}
  .su-nada b{display:block;font-size:17px;margin-bottom:6px}
  .su-nada p{font-size:15px;line-height:1.55;margin:0}
  .su-nada a{display:inline-flex;align-items:center;gap:7px;min-height:44px;margin-top:8px;
    font-weight:700;color:inherit;text-decoration:none}

  /* — LAS DOS SALIDAS DE LA PUERTA —
     Cada una lleva DENTRO lo que pasa si se pulsa. Separar la consecuencia del
     control obligaba a recordarla mientras se baja a buscar el boton, y a
     360x800 el boton ni siquiera se veia. */
  .su-opts{display:grid;gap:10px;margin-top:4px}
  .su-opt{display:block;padding:14px 15px;border:1px solid var(--line);
    border-radius:var(--tm-r);background:var(--card,#fff);text-decoration:none;
    color:var(--tinta);transition:border-color .14s ease, background .14s ease}
  .su-opt:hover{border-color:var(--tm-rosa);background:var(--crema,#FAF7F4)}
  .su-opt:focus-visible{outline:2px solid var(--tinta);outline-offset:2px}
  .su-opt b{display:block;font-size:17px;font-weight:600;line-height:1.3}
  .su-opt span{display:block;font-size:15px;line-height:1.5;color:var(--ink,#4A434F);margin-top:6px}
  .su-opt small{display:block;font-size:14px;line-height:1.45;color:var(--muted);margin-top:7px}
  .su-opt.pri{border-color:var(--tm-rosa);box-shadow:0 0 0 1px var(--tm-rosa);
    background:var(--tm-rosa-piel)}
  .su-opt.pri b{color:var(--tm-rosa-tx)}
  .su-opt.pri:hover{background:#FCE7EE}

  @media (min-width:1000px){
    .su-opts{grid-template-columns:1fr 1fr;align-items:start}
    .su-mot{grid-template-columns:1fr 1fr}
    .su-cambio{display:grid;grid-template-columns:1fr 1fr}
    .su-lado + .su-lado{border-top:0;border-left:1px solid var(--line)}
  }
</style>

<div class="wz" id="wz" data-flujo="sustituir" data-token="<?= $h($su_token) ?>">

  <a href="<?= $h($su_volver) ?>" class="wz-salir" id="wzSalir">
    <?= ico('chev-der') ?>Volver sin cambiar nada</a>

<?php if (!$su_vale): ?>
  <?php /*  NO SE FINGE UNA PANTALLA QUE NO PUEDE HACER NADA. Si la jugada ya
            se hizo, ya se sustituyo o es de un plan viejo, se dice cual de las
            tres y se enseña la salida.  */ ?>
  <span class="wz-et">Esta jugada</span>
  <h1 class="wz-q">Esta ya no se puede cambiar</h1>
  <div class="su-nada" style="margin-top:18px">
    <b><?php
      if (!$su_jug)                              echo 'No encuentro esa jugada.';
      elseif (meta_fue_sustituida($su_jug))      echo 'Ya la cambiaste por otra.';
      elseif ((string)$su_jug['estado'] === 'hecha') echo 'Esa jugada ya está hecha.';
      elseif ($su_plan && (int)$su_plan['id'] !== (int)$su_jug['plan_id'])
                                                 echo 'Esa jugada es de un plan anterior.';
      else                                       echo 'Esa jugada ya no está en marcha.';
    ?></b>
    <p>Cambiar algo ya hecho borraría trabajo tuyo, así que no lo toco.
      <a href="<?= $h($su_volver) ?>">Volver al plan<?= ico('chev-der') ?></a></p>
  </div>
<?php elseif ($su_puerta): ?>

  <?php /*  UNA DECISION, UNA PANTALLA. Antes de hablar de alternativas hay que
            resolver que pasa con lo que YA va a salir: mezclarlo con «que te
            frena» seria pedirle dos cosas a la vez y que se lleve por delante
            la que no leyo.

            Y NO SE OFRECE «conservarla y ademas crear otra»: la sustituta
            hereda semana y orden de la original, asi que las dos ocuparian el
            mismo sitio del plan y el dueño acabaria con trabajo duplicado
            saliendo el mismo dia.  */ ?>
  <span class="wz-et">Antes de cambiarla</span>
  <h1 class="wz-q">Esta ya está en tu calendario</h1>
  <p class="wz-ayuda"><?= (string)$su_comp['clase'] === 'comprometida_vencida'
      ? 'Su fecha ya pasó, así que sale en cuanto haga el próximo barrido. Todavía no he tocado nada.'
      : 'Va a salir sola en su fecha. Todavía no he tocado nada.' ?></p>

  <section class="wz-p on">
    <div class="su-jug">
      <span class="et">La publicación</span>
      <b><?= $h(mb_substr(trim((string)($su_pza['caption'] ?? '')), 0, 120)
              ?: (string)$su_jug['titulo']) ?></b>
      <small><?= $h(ucfirst((string)($su_pza['plataforma'] ?? ''))) ?><?php
        if ($su_cuando['hay']): ?> · <?= $h($su_cuando['dia']) ?> · <?= $h($su_cuando['hora']) ?><?php endif; ?>
        · <?= $h(semana_estado_pieza($su_pza ?: [])['etiqueta']) ?></small>
    </div>

    <div class="su-opts">
      <?php /*  La que el dueño vino a buscar va primera y en rosa: pulsó «no
                puedo con esta». Lo destructivo no se esconde — se explica.  */ ?>
      <a class="su-opt pri" href="<?= $h($BASE . '/meta.php?marca=' . $marca_id
              . '&vista=sustituir&jugada=' . $su_id . '&desde=' . rawurlencode($su_desde)
              . ($su_pos !== null ? '&pos=' . $su_pos : '') . '&quitar=1') ?>">
        <b>Quitarla del calendario y cambiarla</b>
        <span>No sale. Queda descartada en Tus Posts —no se borra— y te propongo
          otra jugada en su mismo sitio del plan.</span>
        <small><?= $su_cuota
          ? 'Su imagen ya está hecha y ya contó en tu mes: quitarla no te la devuelve.'
          : 'Quitarla no gasta imágenes.' ?></small>
      </a>

      <a class="su-opt" href="<?= $h($su_volver) ?>">
        <b>Conservar esta publicación</b>
        <span><?= $h(semana_punto('Sale tal como está' . ($su_cuando['hay']
            ? ', el ' . mb_strtolower($su_cuando['dia']) . ' a las ' . $su_cuando['hora']
            : ', en cuanto le llegue la fecha'))) ?> La jugada no cambia.</span>
      </a>
    </div>
  </section>

<?php else: ?>

  <ol class="wz-tren" id="wzTren" aria-hidden="true">
    <li data-t="1"><i></i><span>Qué te frena</span></li>
    <li data-t="2"><i></i><span>La alternativa</span></li>
    <li data-t="3"><i></i><span>Repasar</span></li>
  </ol>

  <span class="wz-et" id="wzEt">Paso 1 de 3</span>
  <h1 class="wz-q" id="wzQ">—</h1>
  <p class="wz-ayuda" id="wzAyuda">—</p>

  <!-- ══ PASO 1 · que te frena ══════════════════════════════════════ -->
  <section class="wz-p on" data-p="1">
    <div class="su-jug">
      <span class="et">La jugada</span>
      <b><?= $h((string)$su_jug['titulo']) ?></b>
      <?php if (trim((string)$su_jug['que_hacer']) !== ''): ?>
        <p><?= $h((string)$su_jug['que_hacer']) ?></p>
      <?php endif; ?>
      <small><?= $h($SU_FORMATOS[(string)$su_jug['formato']] ?? ucfirst((string)$su_jug['formato'])) ?><?php
        if ((float)($su_jug['inversion'] ?? 0) > 0): ?> · pide $<?= (int)$su_jug['inversion'] ?><?php endif; ?><?php
        if ($su_piezas > 0): ?> · <?= $su_piezas ?> <?= $su_piezas === 1 ? 'pieza tuya' : 'piezas tuyas' ?><?php endif; ?></small>
    </div>

    <div class="su-mot" id="suMot">
      <?php foreach ([
        'sin_video'       => 'No tengo video',
        'sin_foto'        => 'No tengo fotos',
        'sin_presupuesto' => 'No tengo presupuesto',
        'sin_tiempo'      => 'No tengo tiempo',
        'otro'            => 'Otra cosa',
      ] as $k => $t): ?>
        <button type="button" data-motivo="<?= $h($k) ?>" data-txt="<?= $h($t) ?>">
          <span class="pt"></span><?= $h($t) ?></button>
      <?php endforeach; ?>
    </div>

    <span class="wz-sub">¿Quieres contarme más? (Opcional)</span>
    <textarea class="wz-libre" id="suNota" maxlength="190" style="min-height:80px"
      placeholder="Ej: la plaza está en obra y no puedo grabar ahí."></textarea>
  </section>

  <!-- ══ PASO 2 · la alternativa ════════════════════════════════════ -->
  <section class="wz-p" data-p="2">
    <div id="suPensando" class="su-pensando">
      <div class="sp"></div>
      <div><b>Buscándote una alternativa</b>
        <span>Algo que consiga lo mismo y que yo pueda hacer entero.</span></div>
    </div>

    <div id="suCambio" hidden>
      <div class="su-cambio">
        <div class="su-lado su-va">
          <span class="et"><?= ico('x') ?>En vez de</span>
          <b><?= $h((string)$su_jug['titulo']) ?></b>
          <p id="suVaPor"></p>
        </div>
        <div class="su-lado su-viene">
          <span class="et"><?= ico('check') ?>Hago esto</span>
          <b id="suNuevoT">—</b>
          <p id="suNuevoQ"></p>
          <div class="su-tags">
            <span class="su-tag"><?= ico('image') ?><b id="suNuevoF">—</b></span>
            <span class="su-tag"><?= ico('users') ?>Lo hago yo entero</span>
            <span class="su-tag" id="suNuevoCuota"><?= ico('sparkles') ?>—</span>
          </div>
        </div>
      </div>
      <button type="button" class="su-otra" id="suOtra"><?= ico('refresh') ?>Proponme otra</button>
    </div>

    <div id="suSinAlt" class="su-nada" hidden>
      <b>No se me ocurrió una que te sirva</b>
      <p id="suSinAltTx">Prueba otra vez en un rato, o dime con otras palabras qué te frena.</p>
    </div>
  </section>

  <!-- ══ PASO 3 · el repaso ═════════════════════════════════════════ -->
  <section class="wz-p" data-p="3">
    <div class="wz-res">
      <div class="wz-fila">
        <span class="wz-fila-tx"><span>Qué te frena</span><b id="rMotivo">—</b></span>
        <button type="button" class="wz-cambiar" data-ir="1">Cambiar</button>
      </div>
      <div class="wz-fila">
        <span class="wz-fila-tx"><span>La que se va</span><b class="suave"><?= $h((string)$su_jug['titulo']) ?></b></span>
      </div>
      <div class="wz-fila">
        <span class="wz-fila-tx"><span>La que entra</span><b id="rNueva">—</b></span>
        <button type="button" class="wz-cambiar" data-ir="2">Cambiar</button>
      </div>
    </div>

    <?php /*  LAS TRES DUDAS DE QUIEN VA A PULSAR: que pasa con la vieja, que
              pasa con lo que ya salio de ella, y si esto le cuesta algo.  */ ?>
    <div class="wz-bloque wz-guarda">
      <b>Qué pasa con la de ahora</b>
      <ul>
        <li>Queda guardada como <b>sustituida</b>, con tu razón. <b>No se borra</b> y
          <b>no cuenta como hecha</b> — sería contarte un trabajo que nunca pasó.</li>
        <?php if ($su_piezas > 0): ?>
          <li>Sus <?= $su_piezas ?> <?= $su_piezas === 1 ? 'pieza' : 'piezas' ?><?php
            if ($su_publicadas > 0): ?> —<?= $su_publicadas ?> ya <?= $su_publicadas === 1 ? 'publicada' : 'publicadas' ?>—<?php endif; ?>
            se quedan contigo en Tus Posts.<?php if ($su_publicadas > 0): ?>
            Lo publicado sigue contando en tus resultados.<?php endif; ?></li>
        <?php else: ?>
          <li>Todavía no había salido ninguna pieza de ella, así que no se pierde nada.</li>
        <?php endif; ?>
        <li>Tu meta, tu plan y lo demás siguen igual.</li>
      </ul>
    </div>

    <div class="wz-bloque wz-luego">
      <b>Qué pasa cuando confirmes</b>
      <ol>
        <?php if ($su_quitar): ?>
          <?php /*  Lo primero que pasa es lo que el dueño ya decidio en la
                    puerta. Se repite aqui porque es lo unico irreversible del
                    lote y porque entre una pantalla y otra se olvida.  */ ?>
          <li><b>Saco del calendario</b> la publicación que ya estaba lista. No sale.</li>
        <?php endif; ?>
        <li>La nueva entra en el mismo sitio del plan, para esta misma semana.</li>
        <li>Te llevo de vuelta y ahí te digo qué toca.</li>
      </ol>
      <p class="nota">Cambiarla no gasta imágenes. Se gastan cuando me digas que la produzca,
        y solo si lleva arte hecho por mí.</p>
    </div>
  </section>

  <div class="wz-err" id="wzErr" role="alert" tabindex="-1">
    <?= ico('bolt') ?>
    <div class="wz-err-tx">
      <b id="wzErrT">No se pudo cambiar</b>
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
    <b>Cambiando la jugada</b>
    <span>Guardo la de ahora con tu razón<br>y pongo la nueva en su sitio.</span>
  </div>
<?php endif; ?>
</div>

<?php if ($su_vale && !$su_puerta): ?>
<script>
(function(){
  var CSRF  = <?= json_encode(csrf_token()) ?>, MARCA = <?= (int)$marca_id ?>;
  var JUGADA = <?= (int)$su_id ?>;
  //  El token de la jugada: si otro clic la sustituyo mientras esta pantalla
  //  estaba abierta, el servidor lo ve y no crea una segunda.
  var TOKEN  = document.getElementById('wz').dataset.token || '';
  var VOLVER = <?= json_encode($su_volver) ?>;
  //  Lo que el dueño decidio en la puerta. El servidor NO se fia de esto: con
  //  quitar=1 vuelve a leer el compromiso bajo pertenencia y rechaza la pieza
  //  con su estado en el WHERE. Si cambio mientras decidia, no sustituye nada.
  var QUITAR = <?= $su_quitar ? '1' : '0' ?>;
  //  Los formatos que NO pintan imagen. Sale del mismo sitio que la cuota
  //  (consumeDe, commit 3): decirlo aqui a ojo seria prometer por prometer.
  var SIN_ARTE = ['reel','carrusel'];

  var d = { motivo:'', motivoTxt:'', nota:'', alt:null };
  var paso = 1, enviando = false, pidiendo = false;
  //  Cada peticion lleva numero. Si el dueño pulsa «proponme otra» y la
  //  primera contesta despues, la respuesta vieja NO pinta encima de la
  //  nueva. Sin esto se veian las dos cosas a la vez —el «buscandote» encima
  //  de una alternativa ya puesta—, que es lo que salio en la captura.
  var vuelta = 0;

  var $ = function(i){ return document.getElementById(i); };
  var et = $('wzEt'), q = $('wzQ'), ayuda = $('wzAyuda'), tren = $('wzTren');
  var sigue = $('sigue'), atras = $('atras'), nav = $('wzNav');
  var err = $('wzErr'), load = $('wzLoad');

  var TITULO = [ null,
    { et:'Qué te frena', q:'¿Qué no puedes con esta?',
      ay:'Dímelo y te busco otra cosa que consiga lo mismo. La de ahora no se borra.' },
    { et:'La alternativa', q:'Te propongo esto',
      ay:'Es lo que haría en su lugar. Si no te convence, pídeme otra — todavía no he cambiado nada.' },
    { et:'Repasar', q:'Repasa antes de cambiar',
      ay:'Esto es lo que voy a hacer. Todavía no he tocado nada.' } ];

  function ver(n){
    paso = n;
    [].forEach.call(document.querySelectorAll('.wz-p'), function(s){
      s.classList.toggle('on', +s.dataset.p === n); });
    [].forEach.call(tren.children, function(li){
      var i = +li.dataset.t;
      li.classList.toggle('ya', i < n); li.classList.toggle('on', i === n);
    });
    et.textContent = 'Paso ' + n + ' de 3 · ' + TITULO[n].et;
    q.textContent = TITULO[n].q;
    ayuda.textContent = TITULO[n].ay;
    atras.style.display = n > 1 ? '' : 'none';
    sigue.textContent = n === 3 ? 'Cambiar la jugada' : 'Siguiente';
    ocultarError();
    if (n === 2 && !d.alt) pedirAlternativa();
    if (n === 3) repasar();
    revisar();
    window.scrollTo({ top:0, behavior:'smooth' });
    if (window.crecerMetaRecalcular) setTimeout(window.crecerMetaRecalcular, 60);
  }

  function revisar(){
    if (paso === 1)      sigue.disabled = !d.motivo;
    else if (paso === 2) sigue.disabled = !d.alt || pidiendo;
    else                 sigue.disabled = enviando;
  }

  // ── PASO 1 ───────────────────────────────────────────────────────
  $('suMot').addEventListener('click', function(e){
    var b = e.target.closest('button'); if (!b) return;
    [].forEach.call(this.querySelectorAll('button'), function(x){ x.classList.remove('sel'); });
    b.classList.add('sel');
    //  Cambiar de motivo INVALIDA la propuesta: la alternativa depende de el.
    //  Guardarla seria enseñar una respuesta a otra pregunta.
    if (d.motivo !== b.dataset.motivo) d.alt = null;
    d.motivo = b.dataset.motivo; d.motivoTxt = b.dataset.txt;
    revisar();
    nav.scrollIntoView({ block:'nearest',
      behavior: matchMedia('(prefers-reduced-motion:reduce)').matches ? 'auto' : 'smooth' });
  });
  $('suNota').addEventListener('input', function(){
    if (d.nota !== this.value) d.alt = null;   // la nota tambien alimenta la propuesta
    d.nota = this.value;
  });

  // ── PASO 2 · la Estratega, FUERA de cualquier transaccion ────────
  /**  Lo que se ve en el paso 2 sale del ESTADO, no del orden de los eventos:
   *   pensando, con propuesta, o sin ella. Tres cosas y solo una a la vez.  */
  function pintarPaso2(){
    $('suPensando').hidden = !pidiendo;
    $('suCambio').hidden   = !(!pidiendo && d.alt);
    $('suSinAlt').hidden   = !(!pidiendo && !d.alt);
    $('suOtra').hidden     = pidiendo;
  }

  function pedirAlternativa(){
    if (pidiendo) return;
    var mia = ++vuelta;
    pidiendo = true; d.alt = null;
    pintarPaso2();
    revisar();

    var fd = new FormData();
    fd.append('csrf', CSRF); fd.append('accion','alternativa');
    fd.append('jugada', JUGADA); fd.append('motivo', d.motivo); fd.append('nota', d.nota);

    fetch(location.pathname + '?marca=' + MARCA, { method:'POST', body:fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (mia !== vuelta) return;              // llego tarde: manda la otra
        pidiendo = false;
        if (j && j.ok && j.alt) { d.alt = j.alt; pintarAlt(); }
        else {
          $('suSinAltTx').textContent = (j && j.err) ? j.err
            : 'Prueba otra vez en un rato, o dime con otras palabras qué te frena.';
        }
        pintarPaso2();
        revisar();
      })
      .catch(function(){
        if (mia !== vuelta) return;
        pidiendo = false;
        $('suSinAltTx').textContent = 'Se cayó la conexión. Nada ha cambiado — dale otra vez.';
        pintarPaso2();
        revisar();
      });
  }

  var NOMBRE_F = { post:'Post', carrusel:'Carrusel', reel:'Reel',
                   historia:'Historia', mixto:'Varias piezas' };
  var POR = { sin_video:'necesita un video tuyo', sin_foto:'necesita fotos tuyas',
              sin_presupuesto:'pide dinero de anuncios', sin_tiempo:'te pide tiempo a ti',
              otro:'no te encaja' };

  function pintarAlt(){
    $('suVaPor').textContent = 'Porque ' + (POR[d.motivo] || 'no te encaja') + '.';
    $('suNuevoT').textContent = d.alt.titulo || '';
    $('suNuevoQ').textContent = d.alt.que_hacer || '';
    $('suNuevoF').textContent = NOMBRE_F[d.alt.formato] || d.alt.formato || '';
    //  Se dice si gasta imagenes o no, y se dice AQUI: enterarse despues de
    //  confirmar es enterarse tarde.
    $('suNuevoCuota').lastChild.nodeValue =
      SIN_ARTE.indexOf(d.alt.formato) >= 0 ? 'No gasta imágenes' : 'Usa 1 imagen al producirla';
  }

  $('suOtra').addEventListener('click', pedirAlternativa);

  function repasar(){
    $('rMotivo').textContent = d.motivoTxt + (d.nota.trim() !== '' ? ' — «' + d.nota.trim() + '»' : '');
    $('rNueva').textContent = d.alt ? (d.alt.titulo + ' · ' + (NOMBRE_F[d.alt.formato] || d.alt.formato)) : '—';
  }
  [].forEach.call(document.querySelectorAll('.wz-cambiar'), function(b){
    b.addEventListener('click', function(){ ver(+b.dataset.ir); });
  });

  // ── EL FALLO, DENTRO ─────────────────────────────────────────────
  function mostrarError(txt, titulo){
    $('wzErrT').textContent = titulo || 'No se pudo cambiar';
    $('wzErrP').textContent = txt;
    err.classList.add('on'); load.classList.remove('on'); nav.style.display = '';
    [].forEach.call(document.querySelectorAll('.wz-p'), function(s){
      s.classList.toggle('on', +s.dataset.p === 3); });
    [].forEach.call(tren.children, function(li){ li.classList.toggle('ya', +li.dataset.t < 3); });
    enviando = false; revisar(); err.focus();
  }
  function ocultarError(){ err.classList.remove('on'); }
  $('wzReintentar').addEventListener('click', function(){ ocultarError(); confirmar(); });

  // ── LA UNICA ESCRITURA ───────────────────────────────────────────
  function confirmar(){
    if (enviando || !d.alt) return;
    enviando = true; sigue.disabled = true;
    [].forEach.call(document.querySelectorAll('.wz-p'), function(s){ s.classList.remove('on'); });
    nav.style.display = 'none'; ocultarError(); load.classList.add('on');
    [].forEach.call(tren.children, function(li){ li.classList.add('ya'); });

    var fd = new FormData();
    fd.append('csrf', CSRF); fd.append('accion','sustituir');
    fd.append('jugada', JUGADA); fd.append('token', TOKEN);
    if (QUITAR) fd.append('quitar', '1');
    fd.append('motivo', d.motivo); fd.append('nota', d.nota);
    fd.append('alt', JSON.stringify(d.alt));

    fetch(location.pathname + '?marca=' + MARCA, { method:'POST', body:fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        //  `repetido` tambien es un si: el primer clic ya entro.
        if (j && j.ok) { location.href = VOLVER; return; }
        if (j && j.motivo === 'concurrencia') {
          mostrarError('Esa jugada cambió mientras decidías. No toqué nada — vuelve a abrirla.',
                       'Esto cambió mientras decidías');
          return;
        }
        //  LO QUE YA SALIO NO SE FINGE DETENIDO. Si entre la puerta y el boton
        //  el publicador se llevo la pieza, se dice: nada se sustituyo.
        if (j && (j.motivo === 'ya_salio' || j.motivo === 'ya_publicada' || j.motivo === 'ya_tomada')) {
          mostrarError((j && j.err) ? j.err : 'Esa publicación cambió mientras decidías.',
                       'Llegó tarde');
          return;
        }
        mostrarError((j && j.err) ? j.err : 'No pude cambiarla. Todo sigue como estaba.');
      })
      .catch(function(){
        mostrarError('Se cayó la conexión antes de guardar. Todo sigue como estaba.');
      });
  }

  atras.addEventListener('click', function(){ if (paso > 1) ver(paso - 1); });
  sigue.addEventListener('click', function(){
    if (paso < 3) { ver(paso + 1); return; }
    confirmar();
  });

  ver(1);
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/_meta_zona.php'; ?>
