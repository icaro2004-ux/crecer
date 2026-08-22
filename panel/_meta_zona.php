<?php
// ============================================================
//  CRECER — LA ZONA SEGURA Y LA REGLA DE AYUDA
//  panel/_meta_zona.php
//
//  Dos comportamientos visuales, compartidos por las TRES capas de Tu Meta
//  (.ah = lo que toca ahora, .plan = el plan completo, .wz = el wizard). Aquí no hay ninguna
//  regla del producto: no se decide nada del negocio, no se llama a ninguna
//  acción, no se lee ningún estado. Solo geometría.
//
//  Y son DOS COSAS SEPARADAS, a propósito:
//
//    ajustarZonaFija()  reserva sitio al final de la página para que el
//                       último control quede por encima de .botnav — la
//                       barra que NO se puede apartar.
//
//    montarAyuda()      aparta el botón flotante cuando un control entra en
//                       su franja, y lo devuelve cuando sale. Ayuda SÍ se
//                       puede apartar, así que no participa en el padding.
//
//  Mezclarlas costó caro: contando a Ayuda en el padding, la cuenta se mordía
//  la cola —la zona encogía porque Ayuda estaba apartada, y al encoger empujaba
//  controles a su franja, que la volvía a apartar—. Y «arreglarlo» observando
//  la misma clase que se cambiaba colgó la página en un bucle infinito.
//
//  POR ESO: la geometría se recalcula SOLO en momentos conocidos —load, resize
//  y el `toggle` de un <details>—. Nunca observando el atributo que uno mismo
//  escribe.
// ============================================================
?>
<style>
  /*  Solo mientras se mide. Sin esto, el rectangulo del FAB se lee a medio
      camino de su propia animacion y la regla se apaga sola. */
  body.ah-midiendo .ay-fab{transition:none !important}
</style>
<script>
(function () {
  //  OJO AL MOMENTO. La barra de abajo y el botón de Ayuda los pinta
  //  _shell_foot.php, DESPUÉS de este bloque: preguntarlos ahora devuelve null
  //  y las dos rutinas salen en vacío sin quejarse — que es justo lo que
  //  pasaba: se apartaba Ayuda en el papel y nunca en la pantalla.
  var alCargar = function (fn) {
    if (document.readyState === 'complete') { fn(); return; }
    window.addEventListener('load', fn);
  };
  var caja = function () { return document.querySelector('.ah, .plan, .wz'); };

  // ══════════════════════════════════════════════════════════════
  //  1 · LA ZONA SEGURA — solo contra .botnav
  //
  //  Antes aquí había 300px fijos. El número salió de leer mal una medición y
  //  creó una pantalla de vacío en todas las vistas de Tu Meta. La cuenta de
  //  verdad es corta: con la página al final del scroll, el último control
  //  tiene que quedar por encima de la barra.
  //
  //      doc >= ultimo + alto_de_la_barra + margen
  //
  //  Lo que falte para eso —y solo eso— es la zona. Como .content ya reserva
  //  104px para la barra, muchas veces sale poco: reservarlo otra vez sería
  //  contarlo dos veces, que fue el error del 300.
  //
  //  AYUDA NO ENTRA EN ESTA CUENTA. Es una capa móvil: cuando estorba se
  //  aparta sola. Meterla aquí obligaba a reservar 121px extra de vacío
  //  permanente para algo que dura un instante.
  // ══════════════════════════════════════════════════════════════
  var MARGEN = 20;

  function ajustarZonaFija() {
    var ah = caja(); if (!ah) return;
    ah.style.setProperty('--ah-zona', '0px');     // medir sin lo puesto

    var vp = window.innerHeight;
    var doc = document.documentElement.scrollHeight;

    //  El alto de la barra fija, y solo de ella. Se mide donde de verdad está:
    //  la barra no se mueve nunca, así que su rectángulo no engaña.
    var alto = 0;
    var nav = document.querySelector('.botnav');
    if (nav && getComputedStyle(nav).display !== 'none') {
      var r = nav.getBoundingClientRect();
      if (r.height > 0 && r.bottom >= vp - 2) alto = Math.round(r.height);
    }

    //  El último control de la página, en coordenadas de documento.
    //
    //  OJO CON LOS ACORDEONES CERRADOS. Este Chrome le da RECTÁNGULO DE VERDAD
    //  al contenido de un <details> cerrado —medido: 627px de alto, con un
    //  enlace en y=1608 y el acordeón sin abrir—, así que contarlos reservaba
    //  sitio para controles que nadie puede tocar. Al añadir una opción más al
    //  plan, eso infló la zona hasta 285px: una pantalla de vacío, que es
    //  exactamente el error histórico que este cálculo existe para no repetir.
    //
    //  Lo que no se ve no necesita hueco. El summary SÍ se mide: esa es la
    //  puerta que la dueña ve. Es la misma regla que ya aplicaba la sonda al
    //  juzgar solapes; aquí faltaba.
    var ultimo = 0;
    [].forEach.call(ah.querySelectorAll('a[href],button,summary'), function (e) {
      var d = e.closest('details');
      if (d && !d.open && e.tagName !== 'SUMMARY') return;
      var b = e.getBoundingClientRect();
      if (b.height < 4) return;
      ultimo = Math.max(ultimo, Math.round(b.bottom + window.scrollY));
    });

    var falta = Math.max(0, ultimo + alto + MARGEN - doc);
    ah.style.setProperty('--ah-zona', (falta || MARGEN) + 'px');
  }

  // ══════════════════════════════════════════════════════════════
  //  2 · AYUDA SE APARTA DE CUALQUIER CONTROL PRINCIPAL
  //
  //  La primera versión solo vigilaba los enlaces del final. Con eso, el botón
  //  primario podía quedar debajo de Ayuda y la regla no se enteraba — y decir
  //  «se alcanza haciendo scroll» no vale para el botón más importante de la
  //  pantalla: es el que la dueña va a tocar sin pensar.
  //
  //  La franja es la del propio botón con 16px de aviso a cada lado: se quita
  //  ANTES de rozar, no cuando ya tapa. Y vuelve sola en cuanto deja de
  //  coincidir.
  // ══════════════════════════════════════════════════════════════
  var SEL = [
    //  capa 1 · lo que toca ahora
    '.tm-btn', '.ah-como > summary', '.tm-ac > summary', '.cq-btn', '.tm-mas a',
    //  capa 2 · el plan. Con la lista corta salieron OCHO controles tapados a
    //  360: los de abrir las piezas de una jugada y las opciones del final.
    '.plan-volver', '.jg-sum', '.jg-hacer', '.jg-ok2', '.jg-ver', '.pu',
    '.jg-video a', '.plan-ac > summary', '.plan-op button', '.hp-ev', '.hp-s',
    //  capa 0 · el wizard. Su ultimo control es el boton que CREA la meta:
    //  dejarlo debajo de Ayuda seria tapar justo lo unico que esta pantalla
    //  existe para pulsar.
    '.wz-salir', '.wz-obj', '.wz-chip', '.wz-nose', '.wz-cambiar',
    '.wz-atras', '.wz-err button', '.wz-glos > summary'
  ].join(', ');

  var ob = null, dentro = null;

  /** Devuelve la transicion al FAB, ya con el observador montado. */
  function destapar() {
    requestAnimationFrame(function () { requestAnimationFrame(function () {
      document.body.classList.remove('ah-midiendo');
    }); });
  }

  function montarAyuda() {
    if (!('IntersectionObserver' in window)) return;
    if (ob) { ob.disconnect(); ob = null; }
    dentro = new Set();

    var fab = document.querySelector('.ay-fab');
    var objetivos = document.querySelectorAll(SEL);
    if (!fab || !objetivos.length) { document.body.classList.remove('ah-cola'); return; }

    //  MEDIR CON LA TRANSICION APAGADA — la trampa que se llevo la regla.
    //  Apartar el FAB es una transicion de .2s. Al recalcular con el FAB YA
    //  apartado (abrir un <details> mientras algo estaba en su franja), se le
    //  quitaba la clase y se le pedia el rectangulo en el mismo suspiro: el
    //  navegador devolvia la posicion de MEDIO CAMINO, con el boton todavia
    //  fuera de la pantalla. Entonces `arriba` salia mayor que el alto del
    //  viewport, se tomaba por «no hay FAB a la vista» y se volvia sin montar
    //  el observador. La regla no fallaba: DEJABA DE EXISTIR hasta recargar.
    //
    //  Con la transicion apagada y un reflujo forzado, el rectangulo es el de
    //  reposo. Se vuelve a encender dos fotogramas despues, ya con el
    //  observador puesto, para que no pegue un parpadeo.
    document.body.classList.add('ah-midiendo');
    document.body.classList.remove('ah-cola');
    void fab.offsetWidth;

    var AVISO = 16, H = window.innerHeight;
    var r = fab.getBoundingClientRect();    var arriba = Math.round(r.top - AVISO), abajo = Math.round(H - r.bottom - AVISO);
    if (!(arriba > 0 && arriba < H)) { destapar(); return; }   // sin FAB a la vista

    ob = new IntersectionObserver(function (es) {
      es.forEach(function (e) {
        if (e.isIntersecting) dentro.add(e.target); else dentro.delete(e.target);
      });
      document.body.classList.toggle('ah-cola', dentro.size > 0);
    }, { rootMargin: '-' + arriba + 'px 0px ' + (-abajo) + 'px 0px', threshold: 0 });

    [].forEach.call(objetivos, function (o) { ob.observe(o); });
    destapar();
  }

  // ══════════════════════════════════════════════════════════════
  //  CUÁNDO SE RECALCULA — y solo entonces
  //
  //  load, resize, y el toggle de un <details>. Nada de observar atributos:
  //  ajustarZonaFija cambia el alto de la página y montarAyuda escribe
  //  `ah-cola`; escuchar esos cambios para volver a correr es cómo se llega a
  //  un bucle que cuelga la pestaña.
  // ══════════════════════════════════════════════════════════════
  var recalcular = function () { ajustarZonaFija(); montarAyuda(); };

  alCargar(recalcular);
  window.addEventListener('resize', recalcular);

  //  Al abrir o cerrar una capa aparecen y desaparecen controles: cambia el
  //  alto de la página y cambia lo que Ayuda tiene que vigilar.
  document.addEventListener('toggle', function (e) {
    if (e.target && e.target.tagName === 'DETAILS') setTimeout(recalcular, 40);
  }, true);

  //  Para que las pruebas puedan pedir un recálculo sin simular gestos, y para
  //  que cualquier pantalla que cambie su contenido a mano pueda avisar.
  window.crecerMetaRecalcular = recalcular;
})();
</script>
