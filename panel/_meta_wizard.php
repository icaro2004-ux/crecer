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
//  De ahi los cuatro pasos: una decision cada uno, y el cuarto no pide nada
//  nuevo — enseña lo escogido, lo que va a pasar despues y lo que queda
//  guardado. Salir antes de ese boton no deja rastro en la base.
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
<style>
  /* ══ CAPA 0 · EL WIZARD ═══════════════════════════════════════════════
     Va al final de la hoja a proposito: en CSS gana el ultimo y asi nadie
     tiene que adivinar el orden. Solo toca .wz — las otras dos capas no
     usan ninguna de estas clases. */
  .wz{
    --tm-rosa:#EF4375; --tm-rosa-tx:#C81E52; --tm-rosa-bt:#D42A5C; --tm-rosa-bt-h:#B81F4C;
    --tm-rosa-piel:#FDF0F4;
    --tm-teal:#00A49F; --tm-teal-tx:#00726F; --tm-teal-piel:#EDF7F6;
    --tm-aviso:#8A5310; --tm-aviso-piel:#FBF3E7;
    --tm-r:12px; --tm-r-bt:10px;
    max-width:560px;margin:0 auto;padding-bottom:var(--ah-zona,20px);
  }

  /* — LA SALIDA · primero y siempre visible —
     Una capa sin puerta deja al dueño encerrado con el boton del navegador, y
     el boton del navegador no le dice si lo que escribio se guarda o no. Este
     lo dice. */
  .wz-salir{display:inline-flex;align-items:center;gap:8px;min-height:44px;
    font-size:16px;font-weight:600;color:var(--tinta);text-decoration:none;margin-bottom:2px}
  .wz-salir .ic{width:17px;height:17px;stroke-width:2;transform:rotate(180deg)}
  .wz-salir:hover{color:var(--tm-rosa-tx)}
  .wz-salir:focus-visible{outline:2px solid var(--tinta);outline-offset:2px;border-radius:8px}

  /* — EL TREN · cuatro tramos, y ninguno es teal —
     Sin numeros escritos: el «Paso 2 de 4» de al lado ya lo dice, y meter
     cifras de 11px aqui era romper el suelo de 14px por decoracion. */
  .wz-tren{display:flex;gap:6px;list-style:none;margin:14px 0 0;padding:0}
  .wz-tren li{flex:1;min-width:0}
  .wz-tren li i{display:block;height:4px;border-radius:99px;background:var(--line)}
  .wz-tren li.ya i{background:var(--tinta)}
  .wz-tren li.on i{background:var(--tinta)}
  .wz-tren li.on span{color:var(--tinta);font-weight:600}
  .wz-tren li span{display:none;font-size:14px;line-height:1.4;color:var(--muted);margin-top:7px}

  .wz-et{display:block;font-size:14px;font-weight:600;letter-spacing:.06em;
    text-transform:uppercase;color:var(--muted);margin:18px 0 8px}
  .wz-q{font-family:var(--font-display,'Poppins',sans-serif);font-weight:700;
    font-size:26px;line-height:1.22;letter-spacing:-.022em;color:var(--tinta);
    margin:0;text-wrap:balance}
  .wz-ayuda{font-size:15px;line-height:1.55;color:var(--ink,#4A434F);margin:10px 0 0}
  .wz-ayuda b{color:var(--tinta)}

  .wz-p{display:none;margin-top:20px}
  .wz-p.on{display:block;animation:wzEntra .18s ease}
  @keyframes wzEntra{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
  @media (prefers-reduced-motion:reduce){.wz-p.on{animation:none}}

  /* — PASO 1 · escoger el norte —
     La explicacion completa se queda. Es lo que separa «mas pedidos» de «mas
     dinero», y quien no la lee escoge a ciegas. Se puede desplazar; no se puede
     adivinar. */
  .wz-objs{display:grid;gap:10px}
  .wz-obj{display:flex;gap:12px;align-items:flex-start;width:100%;text-align:left;
    border:1px solid var(--line);border-radius:var(--tm-r);background:var(--card,#fff);
    padding:14px;cursor:pointer;font-family:inherit;
    transition:border-color .14s ease, background .14s ease, transform .1s ease}
  .wz-obj:hover{border-color:var(--tm-rosa);background:var(--crema,#FAF7F4)}
  .wz-obj:active{transform:translateY(1px)}
  .wz-obj:focus-visible{outline:2px solid var(--tinta);outline-offset:2px}
  .wz-obj.sel{border-color:var(--tm-rosa);box-shadow:0 0 0 1px var(--tm-rosa);
    background:var(--tm-rosa-piel)}
  .wz-obj-ic{width:44px;height:44px;border-radius:9px;flex:none;background:#F1ECE6;
    display:flex;align-items:center;justify-content:center;color:#A79C90}
  .wz-obj.sel .wz-obj-ic{background:#fff;color:var(--tm-rosa-tx)}
  .wz-obj-ic .ic{width:20px;height:20px;stroke-width:1.8}
  .wz-obj-tx{flex:1;min-width:0}
  .wz-obj-tx b{display:block;font-size:16px;font-weight:600;line-height:1.3;color:var(--tinta)}
  .wz-obj-tx p{font-size:14px;line-height:1.5;color:var(--ink,#4A434F);margin:5px 0 0}
  .wz-obj-tx small{display:block;font-size:14px;line-height:1.45;color:var(--muted);margin-top:6px}
  .wz-obj-ok{flex:none;width:22px;height:22px;color:var(--tm-rosa-tx);opacity:0}
  .wz-obj.sel .wz-obj-ok{opacity:1}
  .wz-obj-ok .ic{width:22px;height:22px;stroke-width:2}

  /* — PASO 2 · el numero y el plazo — */
  .wz-num{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:4px}
  .wz-num input{width:132px;font-family:var(--font-display,'Poppins',sans-serif);
    font-size:34px;font-weight:700;color:var(--tinta);border:1px solid var(--line);
    border-radius:var(--tm-r);padding:8px 12px;min-height:60px;background:var(--card,#fff);
    -moz-appearance:textfield}
  .wz-num input::-webkit-outer-spin-button,
  .wz-num input::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}
  .wz-num input:focus{outline:2px solid var(--tm-rosa);outline-offset:1px;border-color:transparent}
  .wz-unidad{font-size:16px;font-weight:600;color:var(--ink,#4A434F)}
  .wz-nose{min-height:44px;padding:0 14px;border:1px solid var(--line);border-radius:99px;
    background:var(--card,#fff);font-family:inherit;font-size:14px;font-weight:600;
    color:var(--tinta);cursor:pointer;margin-left:auto}
  .wz-nose:hover{border-color:var(--tm-teal);color:var(--tm-teal-tx)}
  .wz-nose:focus-visible{outline:2px solid var(--tinta);outline-offset:2px}
  .wz-tip{display:none;font-size:14px;line-height:1.5;color:var(--tm-teal-tx);
    background:var(--tm-teal-piel);border-radius:var(--tm-r-bt);padding:11px 13px;margin-top:12px}
  .wz-tip.on{display:block}

  .wz-sub{display:block;font-size:16px;font-weight:600;color:var(--tinta);margin:26px 0 10px}
  .wz-chips{display:flex;gap:9px;flex-wrap:wrap}
  .wz-chip{display:flex;flex-direction:column;align-items:flex-start;gap:2px;
    min-height:48px;padding:9px 15px;border:1px solid var(--line);border-radius:var(--tm-r-bt);
    background:var(--card,#fff);font-family:inherit;font-size:15px;font-weight:600;
    color:var(--tinta);cursor:pointer;transition:border-color .14s ease, background .14s ease}
  .wz-chip small{font-size:14px;font-weight:500;color:var(--muted);line-height:1.35}
  .wz-chip:hover{border-color:var(--tm-rosa)}
  .wz-chip:focus-visible{outline:2px solid var(--tinta);outline-offset:2px}
  .wz-chip.sel{border-color:var(--tm-rosa);box-shadow:0 0 0 1px var(--tm-rosa);
    background:var(--tm-rosa-piel);color:var(--tm-rosa-tx)}
  .wz-chip.sel small{color:var(--tm-rosa-tx)}

  /* — PASO 3 · con que cuenta — */
  .wz-libre{width:100%;min-height:120px;border:1px solid var(--line);border-radius:var(--tm-r);
    padding:13px 14px;font-family:inherit;font-size:16px;line-height:1.5;color:var(--tinta);
    background:var(--card,#fff);resize:vertical}
  .wz-libre:focus{outline:2px solid var(--tm-rosa);outline-offset:1px;border-color:transparent}

  /* — PASO 4 · el repaso —
     Aqui no se pide nada nuevo. Se enseña lo escogido en las palabras del
     dueño, cada linea con su puerta de vuelta al paso que la decidio. */
  .wz-res{border:1px solid var(--line);border-radius:var(--tm-r);background:var(--card,#fff);
    overflow:hidden}
  .wz-fila{display:flex;align-items:flex-start;gap:12px;padding:13px 15px;
    border-bottom:1px solid var(--line)}
  .wz-fila:last-child{border-bottom:0}
  .wz-fila-tx{flex:1;min-width:0}
  .wz-fila-tx span{display:block;font-size:14px;font-weight:600;letter-spacing:.06em;
    text-transform:uppercase;color:var(--muted)}
  .wz-fila-tx b{display:block;font-size:16px;font-weight:600;line-height:1.4;
    color:var(--tinta);margin-top:4px;overflow-wrap:anywhere}
  .wz-fila-tx b.suave{font-weight:500;color:var(--ink,#4A434F)}
  .wz-cambiar{flex:none;display:inline-flex;align-items:center;justify-content:center;
    min-width:44px;min-height:44px;padding:0 10px;border:0;background:none;font-family:inherit;
    font-size:14px;font-weight:600;color:var(--tm-rosa-tx);cursor:pointer;border-radius:8px}
  .wz-cambiar:hover{background:var(--tm-rosa-piel)}
  .wz-cambiar:focus-visible{outline:2px solid var(--tinta);outline-offset:2px}

  .wz-bloque{border-radius:var(--tm-r);padding:14px 15px;margin-top:14px}
  .wz-bloque b{display:block;font-size:14px;font-weight:600;letter-spacing:.06em;
    text-transform:uppercase;margin-bottom:8px}
  .wz-bloque p{font-size:15px;line-height:1.55;margin:0}
  .wz-bloque ol,.wz-bloque ul{margin:0;padding-left:19px}
  .wz-bloque li{font-size:15px;line-height:1.55;margin-bottom:5px}
  .wz-bloque li:last-child{margin-bottom:0}
  .wz-bloque .nota{font-size:14px;line-height:1.5;margin-top:9px;opacity:.9}
  .wz-medir{background:var(--crema,#FAF7F4);color:var(--ink,#4A434F)}
  .wz-medir b{color:var(--muted)}
  .wz-medir.parcial{background:var(--tm-aviso-piel);color:var(--tm-aviso)}
  .wz-medir.parcial b{color:var(--tm-aviso)}
  .wz-luego{background:var(--tm-teal-piel);color:var(--tm-teal-tx)}
  .wz-luego b{color:var(--tm-teal-tx)}
  .wz-guarda{background:var(--crema,#FAF7F4);color:var(--ink,#4A434F)}
  .wz-guarda b{color:var(--muted)}

  /* — LA NAVEGACION — */
  .wz-nav{display:flex;gap:10px;align-items:center;margin-top:22px}
  .wz-atras{min-height:52px;padding:0 20px;border:1px solid var(--line);border-radius:var(--tm-r-bt);
    background:var(--card,#fff);font-family:inherit;font-size:16px;font-weight:600;
    color:var(--tinta);cursor:pointer}
  .wz-atras:hover{border-color:var(--raya-firme,#D8D3CC);background:var(--crema,#FAF7F4)}
  .wz-atras:focus-visible{outline:2px solid var(--tinta);outline-offset:2px}
  .wz-nav .tm-btn{flex:1;margin-top:0}
  .wz-nav .tm-btn:disabled{background:#E4DED7;color:#8D857C;cursor:not-allowed}

  /* — EL FALLO, DONDE PASO —
     Con role=alert y el foco encima: quien no ve la pantalla tambien se entera.
     Y con boton propio, porque «intenta otra vez» sin donde pulsar no es una
     salida, es una queja. */
  .wz-err{display:none;gap:11px;align-items:flex-start;background:var(--tm-aviso-piel);
    border-radius:var(--tm-r);padding:14px 15px;margin-top:18px;color:var(--tm-aviso)}
  .wz-err.on{display:flex}
  .wz-err .ic{width:19px;height:19px;flex:none;margin-top:2px;stroke-width:2}
  .wz-err-tx{flex:1;min-width:0}
  .wz-err-tx b{display:block;font-size:16px;font-weight:700;line-height:1.3}
  .wz-err-tx p{font-size:15px;line-height:1.5;margin:5px 0 0;overflow-wrap:anywhere}
  .wz-err button{min-height:44px;margin-top:8px;padding:0 15px;border:1px solid currentColor;
    border-radius:var(--tm-r-bt);background:none;font-family:inherit;font-size:15px;
    font-weight:600;color:inherit;cursor:pointer}
  .wz-err button:focus-visible{outline:2px solid var(--tinta);outline-offset:2px}
  .wz-err:focus-visible{outline:2px solid var(--tm-aviso);outline-offset:2px}

  /* — MIENTRAS TRABAJA —
     No dice «tu plan ya esta». Dice lo que esta pasando, que es otra cosa. */
  .wz-load{display:none;text-align:center;padding:36px 10px 10px}
  .wz-load.on{display:block}
  .wz-load .sp{width:34px;height:34px;margin:0 auto 16px;border-radius:50%;
    border:3px solid var(--line);border-top-color:var(--tm-rosa);animation:wzGira .8s linear infinite}
  @keyframes wzGira{to{transform:rotate(360deg)}}
  @media (prefers-reduced-motion:reduce){.wz-load .sp{animation-duration:2.4s}}
  .wz-load b{display:block;font-family:var(--font-display,'Poppins',sans-serif);
    font-size:19px;font-weight:700;color:var(--tinta)}
  .wz-load span{display:block;font-size:15px;line-height:1.55;color:var(--muted);margin-top:7px}

  /* — EL GLOSARIO · las palabras raras, a mano y plegadas — */
  .wz-glos{margin-top:26px;border-top:1px solid var(--line);padding-top:6px}
  .wz-glos summary{list-style:none;display:flex;align-items:center;gap:9px;min-height:52px;
    font-size:15px;font-weight:600;color:var(--tinta);cursor:pointer}
  .wz-glos summary::-webkit-details-marker{display:none}
  .wz-glos summary .ic{width:17px;height:17px;color:var(--muted);stroke-width:2;
    margin-left:auto;transition:transform .16s ease}
  .wz-glos[open] summary .ic{transform:rotate(90deg)}
  .wz-glos dl{margin:0 0 14px}
  .wz-glos dt{font-size:15px;font-weight:600;color:var(--tinta);margin-top:11px}
  .wz-glos dd{font-size:14px;line-height:1.55;color:var(--ink,#4A434F);margin:3px 0 0}

  /* ══ ESCRITORIO ═══════════════════════════════════════════════════════
     No es el movil estirado. Aqui hay sitio para COMPARAR —los seis objetivos
     de dos en dos, sin desplazarse— y para llevar el mapa entero a la vista:
     los cuatro tramos del tren con su nombre. El movil no puede con eso, y no
     lo necesita: alli manda ir a lo siguiente con una mano. */
  @media (min-width:1000px){
    .wz{max-width:680px}
    .wz-q{font-size:32px}
    .wz-tren li span{display:block}
    .wz-objs{grid-template-columns:1fr 1fr}
    .wz-nav{margin-top:26px}
    .wz-nav .tm-btn{flex:none;min-width:260px;margin-left:auto}
    .wz-atras{margin-right:auto}
  }
</style>

<div class="wz" id="wz">

  <a href="<?= $BASE ?>/meta.php?marca=<?= $marca_id ?>" class="wz-salir" id="wzSalir">
    <?= ico('chev-der') ?>Salir sin guardar</a>

  <ol class="wz-tren" id="wzTren" aria-hidden="true">
    <li data-t="1"><i></i><span>Qué quieres</span></li>
    <li data-t="2"><i></i><span>Cuánto</span></li>
    <li data-t="3"><i></i><span>Con qué cuentas</span></li>
    <li data-t="4"><i></i><span>Repasar</span></li>
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

  <!-- ══ PASO 2 · cuanto y para cuando ══════════════════════════════ -->
  <section class="wz-p" data-p="2">
    <div class="wz-num">
      <input type="number" id="cantidad" min="1" step="1" placeholder="25" inputmode="numeric"
             aria-label="Cuánto quieres lograr">
      <span class="wz-unidad" id="wzUnidad">pedidos</span>
      <button type="button" class="wz-nose" id="nose">No sé — dime tú</button>
    </div>
    <div class="wz-tip" id="wzTip" role="status"></div>

    <span class="wz-sub">¿Para cuándo?</span>
    <div class="wz-chips" id="wzFecha">
      <button type="button" class="wz-chip" data-dias="14">En 2 semanas</button>
      <button type="button" class="wz-chip sel" data-dias="30">En un mes</button>
      <button type="button" class="wz-chip" data-dias="60">En 2 meses</button>
      <button type="button" class="wz-chip" data-dias="90">En 3 meses</button>
    </div>
  </section>

  <!-- ══ PASO 3 · con que cuenta ════════════════════════════════════ -->
  <section class="wz-p" data-p="3">
    <div class="wz-chips" id="wzPauta">
      <button type="button" class="wz-chip sel" data-pauta="0">Nada por ahora<small>Todo sin pagar anuncios</small></button>
      <button type="button" class="wz-chip" data-pauta="20">$20 al mes<small>Para empujar 1 o 2 posts</small></button>
      <button type="button" class="wz-chip" data-pauta="50">$50 al mes<small>Alcance serio en tu área</small></button>
      <button type="button" class="wz-chip" data-pauta="100">$100 o más<small>Campaña de verdad</small></button>
    </div>

    <span class="wz-sub">¿Con qué cuentas? (Opcional)</span>
    <p class="wz-ayuda" style="margin:0 0 10px">Cuéntame si tienes una oferta, un producto que quieres
       empujar, una fecha especial o un evento. Mientras más me digas, menos genérico sale el plan.</p>
    <textarea class="wz-libre" id="contexto" maxlength="600"
      placeholder="Ej: Tengo el combo de brazo gitano a $18 y en agosto son las fiestas del pueblo."></textarea>
  </section>

  <!-- ══ PASO 4 · el repaso ═════════════════════════════════════════ -->
  <section class="wz-p" data-p="4">
    <div class="wz-res">
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
        <button type="button" class="wz-cambiar" data-ir="2">Cambiar</button>
      </div>
      <div class="wz-fila">
        <span class="wz-fila-tx"><span>Inversión en anuncios</span><b id="rPauta">—</b></span>
        <button type="button" class="wz-cambiar" data-ir="3">Cambiar</button>
      </div>
      <div class="wz-fila">
        <span class="wz-fila-tx"><span>Lo que me contaste</span><b id="rCtx" class="suave">—</b></span>
        <button type="button" class="wz-cambiar" data-ir="3">Cambiar</button>
      </div>
    </div>

    <div class="wz-bloque wz-medir" id="rMedirCaja">
      <b>Cómo lo voy a medir</b>
      <p id="rMedir">—</p>
    </div>

    <div class="wz-bloque wz-luego">
      <b>Qué pasa cuando confirmes</b>
      <ol>
        <li>Se crea tu meta con estos números.</li>
        <li>La Estratega mira tu negocio y arma las jugadas para llegar.</li>
        <li>Te llevo a Tu Meta y ahí te digo qué toca primero.</li>
      </ol>
      <p class="nota">Si la Estratega no logra armarlo ahora mismo, tu meta queda creada igual y el plan
         se reintenta desde Tu Meta.</p>
    </div>

    <div class="wz-bloque wz-guarda">
      <b>Qué me queda guardado</b>
      <ul>
        <li>Lo que escogiste: la meta, el número, la fecha y la inversión.</li>
        <li>Lo que me contaste, en tus palabras — es lo que hace que el plan no sea genérico.</li>
        <li>Cómo venías antes: mido tus últimos <b id="rDias">30</b> días para poder decirte después
            si mejoraste.</li>
        <li>Si más adelante cambias de meta, esta no se borra: queda en tu historial.</li>
      </ul>
    </div>
  </section>

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
    <span>La Estratega está mirando tu negocio, tus números y el calendario<br>para decidir las jugadas.
      Dale unos segundos.</span>
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

  var $  = function(i){ return document.getElementById(i); };
  var et = $('wzEt'), q = $('wzQ'), ayuda = $('wzAyuda'), tren = $('wzTren');
  var sigue = $('sigue'), atras = $('atras'), nav = $('wzNav');
  var err = $('wzErr'), load = $('wzLoad');

  var TITULO = [
    null,
    { et:'Qué quieres lograr',  q:'¿Qué quieres lograr?',
      ay:'Escoge una sola. El corillo va a trabajar para eso — no para llenar el calendario. '
       + 'Nada se guarda hasta el último paso.' },
    { et:'Cuánto y para cuándo', q:'¿Cuánto quieres lograr?',
      ay:'Un número te deja saber si vas bien o si hay que apretar. Si no sabes cuál poner, yo te lo '
       + 'digo mirando tus propios números.' },
    { et:'Con qué cuentas',      q:'¿Puedes invertir algo en anuncios?',
      ay:'Pagarle a Instagram o Facebook para que le enseñen tu post a más gente del área — a eso le '
       + 'dicen <b>boost</b> o <b>pauta</b>. Si ahora no puedes, no hay problema: el corillo trabaja '
       + 'sin pagar anuncios y no te lo va a recomendar.' },
    { et:'Repasar',              q:'Repasa antes de crear',
      ay:'Esto es lo que voy a crear. Todavía no se ha guardado nada — si algo no cuadra, cámbialo '
       + 'desde aquí mismo.' }
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
    sigue.textContent = n === 4 ? 'Crear mi meta' : 'Siguiente';
    ocultarError();
    if (n === 4) repasar();
    revisar();
    window.scrollTo({ top:0, behavior:'smooth' });
    if (window.crecerMetaRecalcular) setTimeout(window.crecerMetaRecalcular, 60);
  }

  /** Solo se avanza con lo imprescindible de ESE paso. Ni antes ni de mas. */
  function revisar(){
    if (paso === 1)      sigue.disabled = !d.obj;
    else if (paso === 2) sigue.disabled = !(d.cant && +d.cant > 0);
    else                 sigue.disabled = enviando;
  }

  // ── EL REPASO · lo escogido, en concreto ─────────────────────────
  function repasar(){
    var f = fechaLimite();
    $('rObj').textContent   = d.titulo || '—';
    $('rCant').textContent  = d.cant ? (d.cant + ' ' + d.unidad) : '—';
    $('rFecha').textContent = f.txt + ' · dentro de ' + d.dias + ' días';
    $('rPauta').textContent = d.pauta > 0 ? ('$' + d.pauta + ' al mes en anuncios')
                                          : 'Nada por ahora — sin pagar anuncios';
    var ctx = (d.ctx || '').trim();
    $('rCtx').textContent = ctx !== '' ? ctx
      : 'Nada todavía — el plan sale igual, solo que más general.';
    $('rCtx').classList.toggle('suave', ctx === '');
    $('rMedir').textContent = d.medir || '—';
    $('rMedirCaja').classList.toggle('parcial', d.medible !== 1);
    $('rDias').textContent = d.dias;
  }

  // ── PASO 1 ───────────────────────────────────────────────────────
  [].forEach.call(document.querySelectorAll('.wz-obj'), function(b){
    b.addEventListener('click', function(){
      [].forEach.call(document.querySelectorAll('.wz-obj'), function(x){ x.classList.remove('sel'); });
      b.classList.add('sel');
      d.obj = b.dataset.obj; d.titulo = b.dataset.titulo; d.unidad = b.dataset.unidad;
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
  cant.addEventListener('input', function(){ d.cant = cant.value; revisar(); });

  $('wzFecha').addEventListener('click', function(e){
    var c = e.target.closest('.wz-chip'); if (!c) return;
    [].forEach.call(this.querySelectorAll('.wz-chip'), function(x){ x.classList.remove('sel'); });
    c.classList.add('sel'); d.dias = +c.dataset.dias;
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
    c.classList.add('sel'); d.pauta = +c.dataset.pauta;
  });
  $('contexto').addEventListener('input', function(){ d.ctx = this.value; });

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
        if (j && j.ok) { location.href = VOLVER; return; }
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

  atras.addEventListener('click', function(){ if (paso > 1) ver(paso - 1); });
  sigue.addEventListener('click', function(){
    if (paso < 4) { ver(paso + 1); return; }
    crear();
  });

  ver(1);
})();
</script>

<?php require __DIR__ . '/_meta_zona.php'; ?>
