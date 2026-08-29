// ============================================================
//  CRECER — LA OPORTUNIDAD, EN UN ANDROID DE 360
//  tests/_sala_op_probe.mjs
//
//  El contrato en PHP ya dice que la propuesta se valida, que se anade en la
//  semana correcta y que dos clics no crean dos jugadas. Esto dice lo otro, que
//  en PHP no se puede mirar:
//
//    · que el dueno NUNCA vea el JSON —ni una llave, ni la palabra clave— en
//      la conversacion;
//    · que la eleccion quepa en el telefono, con botones de 44px y sin scroll
//      lateral;
//    · que la repercusion este DONDE esta el boton de confirmar y no al final
//      de la pagina: se mide la distancia en pixeles, no la presencia del texto;
//    · y que crear aparte llegue a Crear con el tema ya escrito, sin que la
//      idea haya viajado por la URL.
//
//  CERO MODELO. El turno de conversacion ya esta sembrado en la base como
//  `done` con su propuesta; lo unico que se finge es el POST que ENVIA el
//  mensaje —devuelve el numero del job sembrado—. A partir de ahi todo es de
//  verdad: el sondeo, el «ver», el «meta» y el «crear» hablan con el servidor.
//
//    node tests/_sala_op_probe.mjs <carpeta|-> <sid> <marca> <job> <escena>
// ============================================================

import { abrirChrome, dormir, cerrarRecibimiento } from './_chrome.mjs';
import fs from 'node:fs';

const [shotsArg, sid, marca, jobS, escena] = process.argv.slice(2);
const shots = (shotsArg && shotsArg !== '-') ? shotsArg : '';
const JOB   = parseInt(jobS, 10);
//  La sonda mira el arbol que la invoco, no el que Apache sirva por
//  costumbre: con dos worktrees a la vez, la prueba de una rama validaba
//  en silencio los archivos de la OTRA. Sin CRECER_BASE, todo igual.
const BASE  = process.env.CRECER_BASE || 'http://localhost/crecer/panel';
const di = (k, v) => console.log(k + '=' + String(v).replace(/\r?\n/g, ' '));

//  EL UNICO EMBUSTE, Y LO MAS PEQUENO POSIBLE: el POST que manda el mensaje
//  contesta con el job ya sembrado. Cualquier otra peticion pasa de largo — el
//  sondeo, la consecuencia y la escritura hablan con el servidor de verdad.
const SONDA = `
  window.__errs = [];
  window.__fake = 0;
  window.__temas = 0;
  //  LA GUIA DEL CORILLO se abre sola a los 650ms de cargar y vive en
  //  localStorage, no en la base: marcar crecer_tour_visto no la calla. Salia
  //  encima justo de la pantalla que se venia a mirar. Se da por vista, que es
  //  lo que ve un dueno que ya ha entrado antes.
  try { ['propuestas','crear','sala','meta','inicio','calendario','resultados']
          .forEach(function (k) { localStorage.setItem('guia_' + k, '1'); }); } catch (e) {}
  addEventListener('error', function (e) { window.__errs.push(String(e.message)); });
  (function (o) { console.error = function () {
      window.__errs.push([].slice.call(arguments).join(' ')); o.apply(console, arguments); }; })(console.error);
  (function (real) {
    window.fetch = function (u, o) {
      try {
        var b = o && o.body;
        //  Y SE CUENTA LO QUE CUESTA: sugerir_temas es una llamada al
        //  modelo por cada apertura del wizard. Quien llega con la idea ya
        //  conversada no la necesita — y pagarla es pagar dos veces.
        if (b && typeof b.get === 'function' && b.get('accion') === 'sugerir_temas') window.__temas++;
        if (b && typeof b.get === 'function' && b.get('mensaje') !== null) {
          window.__fake++;
          return Promise.resolve(new Response(
            JSON.stringify({ ok: true, job: ${JOB} }),
            { headers: { 'Content-Type': 'application/json' } }));
        }
      } catch (e) {}
      return real.apply(this, arguments);
    };
  })(window.fetch);
`;

//  QUE SE VE EN LA CONVERSACION. Lo importante es lo que NO se ve.
const LEER_SALA = `(function () {
  var txt = function (el) { return el ? (el.textContent || '').replace(/\\s+/g, ' ').trim() : ''; };
  var burbujas = [].map.call(document.querySelectorAll('#sc-msgs .sc-row.ia .sc-bubble'), txt);
  var todo = document.body.innerText || '';
  return JSON.stringify({
    burbujas: burbujas,
    ultima: burbujas[burbujas.length - 1] || '',
    json: /OPORTUNIDAD|"formato"|"que_hacer"/.test(todo) || todo.indexOf('{"') >= 0,
    avisos: /Undefined variable|Warning:|Notice:|Deprecated:/.test(todo) ? todo.slice(0, 140) : '',
    horiz: Math.max(0, document.documentElement.scrollWidth - innerWidth),
    //  EL BOTON DE AYUDA FLOTA SOBRE EL DOCK — que aqui es donde vive la caja
    //  de escribir. Se montaba encima de «Enviar»: se mide el solape real.
    solape: (function () {
      var a = document.querySelector('.ay-fab'), e = document.getElementById('sc-send');
      if (!a || !e) return 0;
      var x = a.getBoundingClientRect(), y = e.getBoundingClientRect();
      return (x.right > y.left && x.left < y.right && x.bottom > y.top && x.top < y.bottom) ? 1 : 0;
    })(),
    finge: window.__fake || 0
  });
})()`;

//  LA TARJETA DE ELECCION, MEDIDA.
const LEER_OP = `(function () {
  var txt = function (el) { return el ? (el.textContent || '').replace(/\\s+/g, ' ').trim() : ''; };
  var c = document.querySelector('.sc-op');
  if (!c) return JSON.stringify({ hay: false });
  var r = c.getBoundingClientRect();
  return JSON.stringify({
    hay: true,
    confirmar: c.classList.contains('confirmar'),
    titulo: txt(c.querySelector('b')),
    nota: txt(c.querySelector('.sc-op-nota')),
    lineas: [].map.call(c.querySelectorAll('.sc-op-cons li'), txt),
    ancho: Math.round(r.width),
    fuera: Math.round(Math.max(0, r.right - innerWidth)),
    //  LO QUE TAPA EL COMPOSITOR. Se mide contra su borde superior, no contra
    //  el de la pantalla: esta pegado al fondo y en el telefono va encima de
    //  la barra de navegacion, asi que todo lo que caiga por debajo de su
    //  techo no se puede ni leer ni tocar. La tercera salida quedaba ahi.
    tapadas: (function () {
      var c = document.querySelector('.sc-composer');
      var techo = c ? c.getBoundingClientRect().top : innerHeight;
      return [].filter.call(c ? document.querySelectorAll('.sc-op .sc-op-b') : [], function (b) {
        return b.getBoundingClientRect().bottom > techo;
      }).length;
    })(),
    opciones: [].map.call(c.querySelectorAll('.sc-op-b'), function (b) {
      var q = b.getBoundingClientRect();
      return { clave: b.getAttribute('data-q'), titulo: txt(b.querySelector('b')),
               sub: txt(b.querySelector('i')), alto: Math.round(q.height),
               pri: b.classList.contains('pri'), top: Math.round(q.top) };
    }),
    ultimaCons: (function () {
      var ls = c.querySelectorAll('.sc-op-cons li');
      return ls.length ? Math.round(ls[ls.length - 1].getBoundingClientRect().bottom) : null;
    })(),
    finos: [].filter.call(c.querySelectorAll('b,i,span,li,p'), function (el) {
      var propio = [].slice.call(el.childNodes).some(function (n) {
        return n.nodeType === 3 && n.textContent.trim().length > 1; });
      return propio && parseFloat(getComputedStyle(el).fontSize) < 14;
    }).map(function (el) { return (el.textContent || '').trim().slice(0, 24)
             + ' @' + getComputedStyle(el).fontSize; })
  });
})()`;

const s = await abrirChrome({ sid, url: 'about:blank', ancho: 360, alto: 800 });
if (s.error) { di('OK', 0); di('ERROR', s.error); process.exit(1); }
const { ev, cmd, cerrar } = s;

const listo = async () => {
  for (let i = 0; i < 160; i++) {
    if (await ev('document.readyState === "complete"')) { await dormir(320); return; }
    await dormir(110);
  }
};
const ir = async (u) => { await cmd('Page.navigate', { url: u }); await listo(); await cerrarRecibimiento(ev); };
const esperar = async (expr, ms = 16000) => {
  for (let i = 0; i < ms / 200; i++) { if (await ev(expr)) return true; await dormir(200); }
  return false;
};
async function tirar(nombre, espera) {
  if (!shots) return;
  await dormir(espera === undefined ? 420 : espera);
  const png = await cmd('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
  fs.writeFileSync(`${shots}/${nombre}.png`, Buffer.from(png.data, 'base64'));
}

try {
  await cmd('Page.addScriptToEvaluateOnNewDocument', { source: SONDA });
  await cmd('Network.setCookie', { name: 'PHPSESSID', value: sid, domain: 'localhost', path: '/' });

  await ir(`${BASE}/sala.php?marca=${marca}`);
  di('CARGA', await ev(`!!document.getElementById('sc-msgs')`));

  //  EL DUENO ESCRIBE LO QUE VIO. Se manda por el formulario, como el lo haria.
  await ev(`(function(){ var i=document.getElementById('sc-input');
    i.value='Vi que la gente esta pidiendo el proceso del bizcocho';
    document.getElementById('sc-form').dispatchEvent(new Event('submit',{cancelable:true,bubbles:true})); })()`);

  //  El sondeo real tarda un ciclo de 2s en preguntar por primera vez. Se
  //  espera al TEXTO, no a que aparezca una burbuja mas: la del «el corillo lo
  //  esta viendo…» tambien es una burbuja, y mirar entonces era mirar el
  //  cargando.
  const llego = await esperar(`/reel corto del proceso/.test(document.getElementById('sc-msgs').innerText || '')`);
  di('RESPUESTA', llego ? 1 : 0);
  di('SALA', await ev(LEER_SALA));
  //  ESTA VA SIN ESPERA, y por eso: la tarjeta de eleccion se pide al servidor
  //  en cuanto llega la respuesta y aparece a los pocos milisegundos. Con la
  //  espera de siempre, la foto de «la propuesta» y la de «la eleccion» salian
  //  identicas byte a byte — dos veces la misma prueba.
  //  NADA DE MOVER EL SCROLL AQUI. Se probo encuadrar la respuesta con un
  //  scrollIntoView y salio caro: la tarjeta de eleccion se coloca ella sola
  //  con un scroll suave —esa es justo la conducta que esta prueba vigila— y
  //  el encuadre lo cancelaba a media animacion. La sonda medía entonces dos
  //  salidas debajo del compositor que el dueño no llega a ver nunca, y la
  //  culpa era de la sonda. Se dispara sin esperar y sin tocar nada: a los 0ms
  //  la tarjeta todavia no ha llegado del servidor, que es la foto que se
  //  quiere.
  await tirar('sala_propuesta_360', 0);

  //  Y ENTONCES SE LE PREGUNTA COMO QUIERE TRABAJARLA.
  const hayOp = await esperar(`!!document.querySelector('.sc-op .sc-op-b')`);
  di('ELECCION', hayOp ? 1 : 0);
  await tirar('sala_eleccion_360');
  di('OP', await ev(LEER_OP));

  if (escena === 'crear') {
    await ev(`document.querySelector('.sc-op-b[data-q="crear"]').click()`);
    //  Crear aparte no pregunta dos veces: lleva directo al estudio.
    await esperar(`location.pathname.indexOf('propuestas.php') >= 0`);
    await listo();
    await cerrarRecibimiento(ev);
    di('CREAR_URL', await ev(`location.href`));
    di('CREAR', await ev(`(function(){
      var t = document.getElementById('wiz-tema');
      var ov = document.getElementById('wizov');
      return JSON.stringify({
        abierto: !!(ov && ov.classList.contains('show')),
        tema: t ? t.value : '',
        enUrl: /titulo|bizcocho|proceso/i.test(location.search),
        temas: window.__temas || 0,
        avisos: /Undefined variable|Warning:|Notice:/.test(document.body.innerText || '')
      });
    })()`));
    await tirar('crear_contexto_360');

  } else {
    //  LA REPERCUSION, ANTES DE ESCRIBIR.
    await ev(`document.querySelector('.sc-op-b[data-q="meta"]').click()`);
    const conf = await esperar(`document.querySelector('.sc-op.confirmar')`);
    di('CONFIRMA', conf ? 1 : 0);
    await tirar('sala_repercusion_360');
    di('CONS', await ev(LEER_OP));

    //  Y AHORA SI SE ESCRIBE. Dos toques seguidos: el segundo no debe salir.
    await ev(`(function(){ var b=document.querySelector('.sc-op-b[data-q="meta-ok"]');
      b.click(); b.click(); })()`);
    const fin = await esperar(`!document.querySelector('.sc-op') && document.querySelector('.sc-cta')`);
    di('ESCRITO', fin ? 1 : 0);
    di('CIERRE', await ev(`(function(){
      var b = [].slice.call(document.querySelectorAll('#sc-msgs .sc-row.ia .sc-bubble'));
      var a = document.querySelector('.sc-cta a');
      return JSON.stringify({
        texto: b.length ? (b[b.length-1].textContent||'').replace(/\\s+/g,' ').trim() : '',
        cta: a ? (a.textContent||'').trim() : '', href: a ? a.getAttribute('href') : ''
      });
    })()`));

    //  Y SE VE EN TU META — por la puerta que el propio producto ofrece, no
    //  por una URL escrita aqui: si el boton lleva a la pantalla equivocada,
    //  esta prueba tiene que enterarse.
    const destino = await ev(`(function(){ var a=document.querySelector('.sc-cta a');
      return a ? a.getAttribute('href') : ''; })()`);
    await ir('http://localhost' + destino);
    di('META', await ev(`(function(){
      var t = (document.body.innerText || '');
      //  QUE ESTE EN EL DOM NO ES QUE SE VEA. Un plan de seis jugadas es media
      //  pantalla de scroll: se busca la tarjeta y se mide si cae DENTRO del
      //  primer viewport, que es lo unico que el dueno mira al aterrizar.
      var jg = null;
      [].forEach.call(document.querySelectorAll('.jg'), function (d) {
        if (/proceso detr/i.test(d.textContent || '')) jg = d;
      });
      var r = jg ? jg.getBoundingClientRect() : null;
      return JSON.stringify({
        idea: /proceso detr/i.test(t),
        enPantalla: !!(r && r.top >= 0 && r.top < innerHeight - 60),
        top: r ? Math.round(r.top) : null,
        ancla: location.hash,
        avisos: /Undefined variable|Warning:|Notice:/.test(t) ? t.slice(0,140) : '',
        horiz: Math.max(0, document.documentElement.scrollWidth - innerWidth)
      });
    })()`));
    await tirar('meta_oportunidad_360');
  }

  di('ERRORES', await ev('JSON.stringify(window.__errs || [])'));
  di('OK', 1);
} catch (e) {
  di('OK', 0); di('ERROR', e.message);
} finally {
  cerrar();
}
