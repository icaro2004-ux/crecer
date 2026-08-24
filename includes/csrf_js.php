<?php
// ============================================================
//  CRECER — EL TOKEN VIAJA SOLO
//  includes/csrf_js.php
//
//  EL PROBLEMA QUE RESUELVE. `aprobar2.php` concentra ~20 acciones que
//  escriben —aprobar, rechazar, reabrir, editar el texto, mover la fecha,
//  generar arte, subir foto o video, crear posts, borrar— y las llama desde
//  unos treinta sitios: sus propios formularios, sus propios fetch(), el
//  wizard de crear que se incrusta en dos paginas distintas, la Baraja de
//  Inicio, El Estudio y ahora la revision semanal.
//
//  Poner el token a mano en treinta llamadas es como se pierde una: la que se
//  olvide no falla al escribirla —falla el dia que alguien la usa, en
//  produccion, con un error que parece de red. Y la que se olvide es
//  precisamente la que deja la puerta abierta o la que rompe una ruta vieja.
//
//  ASI QUE EL TOKEN SE PONE SOLO, EN DOS SITIOS Y NADA MAS:
//
//    1. fetch()  — toda peticion POST con FormData a NUESTRO PROPIO ORIGEN
//                  recibe el token si no lo lleva ya. Si lo lleva, no se toca:
//                  quien lo puso a mano sigue mandando.
//    2. <form>   — todo formulario POST del mismo origen recibe un campo
//                  oculto al enviarse. Se escucha en captura sobre el
//                  documento, asi que tambien cubre los formularios que nacen
//                  despues (aprobar2 arma varios con innerHTML).
//
//  LO QUE NO HACE, A PROPOSITO:
//   · No toca peticiones a otros origenes. Mandar el token de esta sesion a un
//     tercero seria regalarselo — exactamente el ataque que esto previene.
//   · No toca GET. Un GET no debe escribir; si alguno escribe, el arreglo es
//     que deje de hacerlo, no darle un token.
//   · No toca cuerpos que no sean FormData (JSON, blobs). Hoy no hay ninguno
//     que escriba; el dia que lo haya, tendra que poner el token a mano y el
//     servidor lo exigira igual. Esto es un ayudante, NO la defensa: la
//     defensa es `csrf_ok()` en el servidor, que rechaza aunque este guion no
//     se haya cargado.
//
//  Se incluye en las paginas que ALOJAN a los llamadores, no en cada llamador.
// ============================================================
?>
<script>
(function () {
  var TOKEN = <?= json_encode(csrf_token()) ?>;
  if (!TOKEN) return;

  var mismoOrigen = function (u) {
    try { return new URL(u || '', location.href).origin === location.origin; }
    catch (e) { return false; }
  };

  // ── 1 · fetch() ────────────────────────────────────────────────
  if (typeof window.fetch === 'function') {
    var original = window.fetch;
    window.fetch = function (entrada, opciones) {
      try {
        var o = opciones || {};
        var metodo = (o.method || (entrada && entrada.method) || 'GET').toUpperCase();
        var destino = (typeof entrada === 'string') ? entrada
                    : (entrada && entrada.url) ? entrada.url : '';
        if (metodo === 'POST' && o.body && typeof FormData !== 'undefined'
            && o.body instanceof FormData && !o.body.has('csrf')
            && mismoOrigen(destino || location.href)) {
          o.body.append('csrf', TOKEN);
        }
      } catch (e) { /* nunca romper la peticion por intentar protegerla */ }
      return original.apply(this, arguments);
    };
  }

  // ── 2 · <form method="post"> ───────────────────────────────────
  //  En CAPTURA y sobre el documento: los formularios de aprobar2 se crean con
  //  innerHTML despues de cargar, asi que engancharse a cada uno al arrancar
  //  habria cubierto solo los que ya existian.
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || f.tagName !== 'FORM') return;
    if ((f.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
    if (!mismoOrigen(f.getAttribute('action') || location.href)) return;
    if (f.querySelector('input[name="csrf"]')) return;
    var i = document.createElement('input');
    i.type = 'hidden'; i.name = 'csrf'; i.value = TOKEN;
    f.appendChild(i);
  }, true);
})();
</script>
