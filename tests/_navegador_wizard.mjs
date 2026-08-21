// ============================================================
//  CRECER — EL RECORRIDO DEL WIZARD, EN UN NAVEGADOR DE VERDAD
//  tests/_navegador_wizard.mjs
//
//  _navegador_estados.mjs mide COMO SE VE cada paso. Esto mide lo otro: si el
//  wizard SE COMPORTA. Son preguntas que no se contestan leyendo el fuente ni
//  mirando una captura:
//
//    · al volver atras, ¿siguen ahi las respuestas?
//    · al salir a mitad, ¿se escribio algo? (lo comprueba el PHP en la base)
//    · con dos clics seguidos, ¿cuantas veces sale el POST de crear?
//    · si el servidor dice que no, ¿sale un alert() del navegador —que se lleva
//      la pantalla y borra el contexto— o el fallo se queda dentro con su
//      boton?
//
//  window.alert se cambia por un contador ANTES de tocar nada: si alguien lo
//  vuelve a meter, esta sonda lo canta. No es una opinion de estilo — un alert
//  a mitad de un formulario de cuatro pasos es perder las cuatro respuestas.
//
//  CERO PROVEEDORES en las escenas que fingen el servidor. La escena `crear`
//  si llama de verdad, y por eso vive sola.
//
//    node tests/_navegador_wizard.mjs <sid> <marca> <escena> [ancho] [alto]
//
//  Imprime UNA linea de JSON. Quien asierta es la prueba en PHP.
// ============================================================

import { abrirChrome, cerrarRecibimiento, dormir } from './_chrome.mjs';

const [sid, marca, escena, aS, hS] = process.argv.slice(2);
const ancho = parseInt(aS || '360', 10), alto = parseInt(hS || '800', 10);
const URL_PAGINA = `http://localhost/crecer/panel/meta.php?marca=${marca}&vista=wizard`;
const CTX = '[prueba] Tengo el combo de brazo gitano a $18 y en agosto son las fiestas del pueblo.';

const salir = (o) => { console.log(JSON.stringify(o)); };

const ch = await abrirChrome({ sid, url: URL_PAGINA, ancho, alto });
if (ch.error) { salir(ch); process.exit(1); }
const { ev, cerrar } = ch;

try {
  await cerrarRecibimiento(ev);

  if (!(await ev(`!!document.querySelector('.wz')`))) {
    salir({ error: 'la pagina no trae el wizard', url_final: await ev('location.href'),
            titulo: await ev('document.title'),
            pista: 'si la url final es otra, algo redirigio — o la marca ya tiene meta activa' });
    cerrar(); process.exit(1);
  }

  //  EL CONTADOR DE ALERTS. Va primero, antes de cualquier gesto.
  //  Y se apunta en sessionStorage, que SOBREVIVE a la navegacion. Leerlo de
  //  window despues del redirect a Tu Meta devolvia undefined —el contador se
  //  va con la pagina vieja— y la sonda moria contando en vez de midiendo.
  await ev(`sessionStorage.setItem('wzAlertas','0');
    window.alert = function(m){
      sessionStorage.setItem('wzAlertas', String(+sessionStorage.getItem('wzAlertas') + 1));
    };`);
  const contar = (k) => ev(`+(sessionStorage.getItem('${k}') || 0)`);

  const escribir = (id, v) => ev(`(function(){var e=document.getElementById('${id}');
    e.value=${JSON.stringify(v)}; e.dispatchEvent(new Event('input',{bubbles:true}));})()`);
  const pulsa = async (sel, ms = 320) => { await ev(`document.querySelector(${JSON.stringify(sel)}).click()`); await dormir(ms); };
  const estado = () => ev(`JSON.stringify((function(){
    var s=document.querySelector('.wz-p.on'), o=document.querySelector('.wz-obj.sel');
    var fe=document.querySelector('#wzFecha .wz-chip.sel'), pa=document.querySelector('#wzPauta .wz-chip.sel');
    var er=document.getElementById('wzErr');
    return {
      paso: s ? +s.dataset.p : 0,
      et: (document.getElementById('wzEt').textContent||'').trim(),
      obj: o ? o.dataset.obj : '',
      cant: document.getElementById('cantidad').value,
      dias: fe ? fe.dataset.dias : '',
      pauta: pa ? pa.dataset.pauta : '',
      ctx: document.getElementById('contexto').value,
      repaso: ['rObj','rCant','rFecha','rPauta','rCtx','rMedir'].reduce(function(a,i){
        a[i]=(document.getElementById(i).textContent||'').trim(); return a; }, {}),
      err_visible: er.classList.contains('on'),
      err_txt: (document.getElementById('wzErrP').textContent||'').trim(),
      err_enfocado: document.activeElement === er,
      hay_reintentar: !!document.getElementById('wzReintentar'),
      cargando: document.getElementById('wzLoad').classList.contains('on'),
      alertas: +(sessionStorage.getItem('wzAlertas') || 0),
      url: location.href
    };
  })())`).then(JSON.parse);

  /** Contesta los tres pasos y deja el wizard en el repaso. */
  const contestar = async () => {
    await pulsa('.wz-obj', 260);
    await pulsa('#sigue');
    await escribir('cantidad', '25');
    await pulsa('#wzFecha .wz-chip[data-dias="60"]', 120);
    await pulsa('#sigue');
    await pulsa('#wzPauta .wz-chip[data-pauta="20"]', 120);
    await escribir('contexto', CTX);
    await pulsa('#sigue', 420);
  };

  // ══════════════════════════════════════════════════════════════
  //  ATRAS Y ADELANTE SIN PERDER NADA
  // ══════════════════════════════════════════════════════════════
  if (escena === 'atras') {
    await contestar();
    const enElRepaso = await estado();

    await pulsa('#atras'); await pulsa('#atras'); await pulsa('#atras');
    const alPrincipio = await estado();

    //  Y de vuelta, sin volver a contestar: si algo se perdio, el boton
    //  Siguiente estaria apagado y el paso no avanzaria.
    await pulsa('#sigue'); await pulsa('#sigue'); await pulsa('#sigue', 420);
    const deVuelta = await estado();

    //  La puerta de cada linea del repaso: «Cambiar» lleva a SU paso.
    await pulsa('.wz-cambiar[data-ir="2"]');
    const alCambiar = await estado();

    salir({ escena, enElRepaso, alPrincipio, deVuelta, alCambiar });
    cerrar(); process.exit(0);
  }

  // ══════════════════════════════════════════════════════════════
  //  LA REGLA DE AYUDA SOBREVIVE A UN <details>
  //
  //  Apartar el boton flotante es una transicion de .2s. Si la regla se
  //  recalcula MIENTRAS el boton esta apartado —abrir el glosario con una
  //  tarjeta en su franja—, medir su rectangulo en ese instante devuelve la
  //  posicion de medio camino: fuera de la pantalla. Con eso, la regla decidia
  //  «aqui no hay boton que apartar» y se iba SIN OBSERVADOR. No fallaba una
  //  vez: dejaba de existir hasta recargar la pagina.
  //
  //  Se mide en tres tiempos, y el tercero es el que importa.
  // ══════════════════════════════════════════════════════════════
  if (escena === 'ayuda') {
    //  Lleva la segunda tarjeta justo a la franja del boton y contesta si
    //  Ayuda se aparto.
    const mirar = () => ev(`JSON.stringify((function(){
      var f=document.querySelector('.ay-fab'); if(!f) return {sin_fab:true};
      var cs=getComputedStyle(f), r=f.getBoundingClientRect();
      return { cola:document.body.classList.contains('ah-cola'),
               opacidad:+cs.opacity, y:Math.round(r.top),
               apartado: +cs.opacity < 0.05 || cs.pointerEvents === 'none' };
    })())`).then(JSON.parse);

    //  La franja del FAB, con la pagina quieta: se coloca una tarjeta encima.
    const encimar = async () => {
      await ev(`(function(){
        var f=document.querySelector('.ay-fab'); var r=f.getBoundingClientRect();
        var o=document.querySelectorAll('.wz-obj')[1] || document.querySelector('.wz-obj');
        var b=o.getBoundingClientRect();
        window.scrollBy(0, Math.round(b.top - r.top) + 10);
      })()`);
      await dormir(700);
    };

    await encimar();
    const alPrincipio = await mirar();

    //  El gesto que mataba la regla: desplegar con Ayuda ya apartada.
    await ev(`document.querySelector('.wz-glos').open = true;`);
    await dormir(700);
    const trasDesplegar = await mirar();

    //  Y la prueba de vida: volver a poner algo en su franja.
    await ev('window.scrollTo(0,0)'); await dormir(500);
    await encimar();
    const sigueViva = await mirar();

    salir({ escena, alPrincipio, trasDesplegar, sigueViva });
    cerrar(); process.exit(0);
  }

  // ══════════════════════════════════════════════════════════════
  //  EL SERVIDOR DICE QUE NO
  //  Se finge la respuesta: nada sale a la red, nada se escribe.
  // ══════════════════════════════════════════════════════════════
  if (escena === 'error') {
    await ev(`sessionStorage.setItem('wzPosts','0');
      window.fetch = function(u, o){
        var b = o && o.body;
        var acc = (b && b.get) ? b.get('accion') : '';
        if (acc === 'crear') {
          sessionStorage.setItem('wzPosts', String(+sessionStorage.getItem('wzPosts') + 1));
          return Promise.resolve({ json: function(){ return Promise.resolve(
            { ok:false, err:'[prueba] La Estratega no contesto. Intenta otra vez.' }); } });
        }
        return Promise.resolve({ json: function(){ return Promise.resolve({ok:false}); } });
      };`);
    await contestar();
    await pulsa('#sigue', 700);                 // «Crear mi meta»
    const alFallar = await estado();

    //  Y el reintento desde dentro: el mismo boton de la caja del fallo.
    await pulsa('#wzReintentar', 700);
    const alReintentar = await estado();
    salir({ escena, alFallar, alReintentar, posts: await contar('wzPosts') });
    cerrar(); process.exit(0);
  }

  // ══════════════════════════════════════════════════════════════
  //  DOBLE CLIC · el POST tiene que salir UNA vez
  //  Aqui la red SI es la de verdad: la prueba en PHP cuenta las filas.
  // ══════════════════════════════════════════════════════════════
  if (escena === 'doble') {
    await ev(`sessionStorage.setItem('wzPosts','0');
      var real = window.fetch;
      window.fetch = function(u, o){
        var b = o && o.body;
        if (b && b.get && b.get('accion') === 'crear')
          sessionStorage.setItem('wzPosts', String(+sessionStorage.getItem('wzPosts') + 1));
        return real.apply(window, arguments);
      };`);
    await contestar();
    //  Dos clics en el mismo suspiro, como el dedo nervioso de quien no sabe
    //  si le hizo caso.
    await ev(`(function(){var b=document.getElementById('sigue'); b.click(); b.click(); b.click();})()`);
    await dormir(600);
    //  Se le da tiempo a que termine y navegue de vuelta a Tu Meta.
    for (let i = 0; i < 90 && (await ev(`location.href.indexOf('vista=wizard') !== -1`)); i++) await dormir(400);
    salir({ escena, posts: await contar('wzPosts'), url: await ev('location.href'),
            alertas: await contar('wzAlertas') });
    cerrar(); process.exit(0);
  }

  // ══════════════════════════════════════════════════════════════
  //  SALIR A MITAD · la prueba en PHP mira la base despues
  // ══════════════════════════════════════════════════════════════
  if (escena === 'salir') {
    await pulsa('.wz-obj', 260);
    await pulsa('#sigue');
    await escribir('cantidad', '25');
    await pulsa('#sigue');
    await escribir('contexto', CTX);
    const antes = await estado();
    await ev(`document.getElementById('wzSalir').click()`);
    for (let i = 0; i < 40 && (await ev(`location.href.indexOf('vista=wizard') !== -1`)); i++) await dormir(200);
    salir({ escena, antes, url: await ev('location.href'),
            texto_salida: await ev(`(document.title||'')`) });
    cerrar(); process.exit(0);
  }

  // ══════════════════════════════════════════════════════════════
  //  CREAR DE VERDAD · una sola vez, y se comprueba en la base
  // ══════════════════════════════════════════════════════════════
  if (escena === 'crear') {
    await contestar();
    const enElRepaso = await estado();
    await pulsa('#sigue', 500);
    const trabajando = await estado();
    for (let i = 0; i < 90 && (await ev(`location.href.indexOf('vista=wizard') !== -1`)); i++) await dormir(400);
    salir({ escena, enElRepaso, trabajando, url: await ev('location.href'),
            alertas: await contar('wzAlertas') });
    cerrar(); process.exit(0);
  }

  salir({ error: 'escena desconocida: ' + escena });
  cerrar(); process.exit(1);

} catch (e) {
  salir({ error: e.message, escena, url: URL_PAGINA, viewport: `${ancho}x${alto}`,
          pista: 'error del arnes, no necesariamente de la pantalla' });
  cerrar(); process.exit(1);
}
