// ============================================================
//  CRECER — EL CENTRO DE MANDO, EN UN NAVEGADOR
//  tests/_inicio_probe.mjs
//
//  Lo que aquí se mira no se puede mirar en PHP: que el dueño ENTIENDA su
//  negocio de un vistazo. Que la acción de su Meta esté en el primer viewport
//  de un Android de 360, que abra la vista exacta y no una lista genérica, que
//  el menú no le pierda la marca, y que nada se solape con el pulgar encima.
//
//    node tests/_inicio_probe.mjs <carpeta|-> <sid> <marca>
// ============================================================

import { abrirChrome, dormir, cerrarRecibimiento } from './_chrome.mjs';
import fs from 'node:fs';

const [shotsArg, sid, marca, modo] = process.argv.slice(2);
const shots = (shotsArg && shotsArg !== '-') ? shotsArg : '';
const BASE  = 'http://localhost/crecer/panel';
const di = (k, v) => console.log(k + '=' + String(v).replace(/\r?\n/g, ' '));

const SONDA = `
  window.__errs = []; window.__alertas = 0;
  addEventListener('error', function (e) { window.__errs.push(String(e.message)); });
  (function (o) { console.error = function () {
      window.__errs.push([].slice.call(arguments).join(' ')); o.apply(console, arguments); }; })(console.error);
  window.alert = function () { window.__alertas++; };
`;

//  QUÉ CUENTA LA PANTALLA. Se lee del DOM: es lo que el dueño ve, no lo que
//  el servidor cree que mandó.
const LEER = `(function () {
  var vis = function (el) { if (!el) return false; var r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0 && getComputedStyle(el).visibility !== 'hidden'
           && getComputedStyle(el).display !== 'none'; };
  var txt = function (el) { return el ? (el.textContent || '').replace(/\\s+/g, ' ').trim() : ''; };
  var norte = document.querySelector('.norte');
  var bloques = [].map.call(document.querySelectorAll('.in-blk'), function (b) {
    var h = b.querySelector('.in-h b');
    var a = b.querySelector('.in-h a');
    return { titulo: txt(h), enlace: a ? a.getAttribute('href') : '' };
  });
  return JSON.stringify({
    saludo:   txt(document.querySelector('.hz-hello')),
    hayNorte: !!norte,
    norteTxt: txt(norte).slice(0, 220),
    norteHref: norte ? (norte.getAttribute('href') || '') : '',
    //  La acción de la Meta: su etiqueta y si se ve SIN bajar.
    accion:   txt(document.querySelector('.norte .n-ir, .norte .n-cta')),
    bloques:  bloques,
    //  Lo que el dueño puede tocar sin abrir el menú.
    botnav:   [].map.call(document.querySelectorAll('.botnav a'), function (a) {
      return { t: txt(a), href: a.getAttribute('href'), on: a.className.indexOf('on') >= 0 };
    }),
    menu:     [].map.call(document.querySelectorAll('.side nav a'), function (a) {
      return { t: txt(a), href: a.getAttribute('href'),
               dup: a.className.indexOf('dup') >= 0, on: a.className.indexOf('on') >= 0 };
    }),
    grupos:   [].map.call(document.querySelectorAll('.side .side-gt'), txt),
    texto:    (document.body.innerText || '').replace(/\\s+/g, ' ').trim().slice(0, 900)
  });
})()`;

//  LA MEDIDA. Lo que no cabe en el primer viewport, no existe para el pulgar.
const MEDIR = `(function () {
  var vis = function (el) { if (!el) return false; var r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0 && getComputedStyle(el).visibility !== 'hidden'
           && getComputedStyle(el).display !== 'none'; };
  var out = { horiz: 0, chicos: [], finos: [], solapes: [] };
  out.horiz = Math.max(0, document.documentElement.scrollWidth - innerWidth);

  var bn = document.querySelector('.botnav');
  var techo = innerHeight - (bn && vis(bn) ? bn.getBoundingClientRect().height : 0);
  out.techo = Math.round(techo);

  var main = document.querySelector('main') || document.body;
  [].forEach.call(main.querySelectorAll('a, button'), function (el) {
    if (!vis(el)) return;
    var r = el.getBoundingClientRect();
    //  44px es el mínimo del pulgar. Se mide la caja real, no la intención.
    if (r.height < 44 && (el.textContent || '').trim() !== '')
      out.chicos.push(((el.className || el.tagName) + '').slice(0, 30) + ' h=' + Math.round(r.height));
  });
  [].forEach.call(main.querySelectorAll('p, b, span, a, i, li, div'), function (el) {
    if (!vis(el)) return;
    var propio = [].slice.call(el.childNodes)
      .some(function (n) { return n.nodeType === 3 && n.textContent.trim().length > 1; });
    if (!propio) return;
    var fz = parseFloat(getComputedStyle(el).fontSize);
    if (fz < 14) out.finos.push((el.textContent || '').trim().slice(0, 22) + ' @' + fz);
  });

  //  EL PRIMER VIEWPORT: saludo, la Meta entera y una pista de lo que sigue.
  var saludo = document.querySelector('.hz-hi');
  var norte  = document.querySelector('.norte');
  if (saludo) { var s = saludo.getBoundingClientRect(); out.saludoDentro = s.bottom <= techo; }
  if (norte) {
    var r = norte.getBoundingClientRect();
    out.norteRect = Math.round(r.top) + '..' + Math.round(r.bottom);
    out.norteEntero = r.top >= 0 && r.bottom <= techo;
    //  Y que nada la tape: el botón flotante de Ayuda es el sospechoso de siempre.
    var fab = document.querySelector('.ay-fab');
    out.norteBajoAyuda = false;
    if (fab && vis(fab) && getComputedStyle(fab).opacity !== '0') {
      var f = fab.getBoundingClientRect();
      out.norteBajoAyuda = !(r.right < f.left || r.left > f.right || r.bottom < f.top || r.top > f.bottom);
    }
  }
  //  LA PISTA DE LO QUE SIGUE: el primer bloque tiene que asomar.
  var b1 = document.querySelector('.in-blk');
  if (b1) { var rb = b1.getBoundingClientRect(); out.pistaAsoma = rb.top < techo; }

  //  DOS ACTIVOS A LA VEZ EN LA NAVEGACIÓN es decirle que está en dos sitios.
  out.activosBar  = document.querySelectorAll('.botnav a.on').length;
  out.activosMenu = document.querySelectorAll('.side nav a.on').length;
  return out;
})()`;

const s = await abrirChrome({ sid, url: 'about:blank', ancho: 360, alto: 800 });
if (s.error) { di('OK', 0); di('ERROR', s.error); process.exit(1); }
const { ev, cmd, cerrar } = s;

const listo = async () => {
  for (let i = 0; i < 160; i++) {
    if (await ev('document.readyState === "complete"')) { await dormir(360); return; }
    await dormir(110);
  }
};
const ir = async (u) => { await cmd('Page.navigate', { url: u }); await listo(); await cerrarRecibimiento(ev); };
const tam = (w, h, esc = 2) => cmd('Emulation.setDeviceMetricsOverride',
  { width: w, height: h, deviceScaleFactor: esc, mobile: w < 900 });
async function tirar(nombre) {
  if (!shots) return;
  await ev('window.scrollTo(0, 0)');
  await dormir(400);
  const png = await cmd('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
  fs.writeFileSync(`${shots}/${nombre}.png`, Buffer.from(png.data, 'base64'));
}

try {
  await cmd('Page.addScriptToEvaluateOnNewDocument', { source: SONDA });
  await cmd('Network.setCookie', { name: 'PHPSESSID', value: sid, domain: 'localhost', path: '/' });
  const URL = `${BASE}/index.php?marca=${marca}`;

  //  MODO «NAV» (Fase 6). El menú del móvil, «Mi negocio» y el lateral de
  //  escritorio: las tres cosas que hay que MIRAR, porque un menú se juzga
  //  viéndolo, no leyendo su array.
  if (modo === 'nav') {
    await tam(360, 800);
    await ir(URL);
    //  Se abre como lo abre el dueño: tocando.
    await ev(`(function(){var b=document.getElementById('burger'); if(b) b.click();})()`);
    await dormir(420);
    di('MENU', await ev(`JSON.stringify((function(){
      var side = document.getElementById('side');
      var abierto = !!(side && side.className.indexOf('open') >= 0);
      var vis = function(el){ var r = el.getBoundingClientRect();
        return r.width > 0 && r.height > 0 && getComputedStyle(el).display !== 'none'; };
      var links = [].filter.call(side.querySelectorAll('nav a'), vis).map(function(a){
        var r = a.getBoundingClientRect();
        return { t: (a.textContent||'').replace(/\s+/g,' ').trim(),
                 h: a.getAttribute('href'), alto: Math.round(r.height),
                 //  ¿se corta el rótulo? Es el defecto clásico a 360.
                 cortado: a.scrollWidth > a.clientWidth + 1 };
      });
      var grupos = [].filter.call(side.querySelectorAll('.side-gt'), vis)
                     .map(function(g){ return (g.textContent||'').trim(); });
      return { abierto: abierto, links: links, grupos: grupos,
               horiz: Math.max(0, document.documentElement.scrollWidth - innerWidth) };
    })())`));
    await dormir(200);
    if (shots) {
      const png = await cmd('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
      fs.writeFileSync(`${shots}/menu_movil_360.png`, Buffer.from(png.data, 'base64'));
    }

    //  MI NEGOCIO, en el teléfono.
    await ir(`${BASE}/genoma.php?marca=${marca}`);
    di('NEGOCIO', await ev(`JSON.stringify((function(){
      var f = [].map.call(document.querySelectorAll('.ng-fila'), function(x){
        var r = x.getBoundingClientRect();
        var b = x.querySelector('b'), i = x.querySelector('i');
        return { t: b ? b.textContent.trim() : '', v: i ? i.textContent.trim() : '',
                 h: x.getAttribute('href'), alto: Math.round(r.height) };
      });
      return { filas: f, horiz: Math.max(0, document.documentElement.scrollWidth - innerWidth),
               texto: (document.body.innerText||'').replace(/\s+/g,' ').trim().slice(0,300) };
    })())`));
    await tirar('mi_negocio_360');

    //  Y EL LATERAL DE ESCRITORIO, que es donde se ve la jerarquía entera.
    await tam(1440, 900, 1);
    await ir(URL);
    await tirar('menu_escritorio_1440');

    di('ALERTAS', await ev('window.__alertas || 0'));
    di('ERRORES', await ev('JSON.stringify(window.__errs || [])'));
    di('OK', 1);
    cerrar();
    process.exit(0);
  }

  for (const [n, w, h, e] of [['360', 360, 800, 2], ['414', 414, 896, 2], ['1440', 1440, 900, 1]]) {
    await tam(w, h, e);
    await ir(URL);
    di('LEIDO_' + n, await ev(LEER));
    di('MED_' + n, await ev(MEDIR).then(JSON.stringify));
    if (n === '360' || n === '1440') await tirar('inicio_' + n);
  }

  //  LA ACCIÓN DE LA META ABRE LA VISTA EXACTA, no una lista genérica.
  await tam(360, 800);
  await ir(URL);
  const href = await ev(`(function(){var n=document.querySelector('.norte');
    return n ? (n.getAttribute('href') || '') : '';})()`);
  if (href) {
    await ir(href.startsWith('http') ? href : 'http://localhost' + href);
    di('DESTINO', await ev(`JSON.stringify({ url: location.href,
      //  «Abre» = la página respondió y no reventó. No se exige un <main>:
      //  la vista exacta de una publicación vive en aprobar2.php, que no lo usa.
      hay: !/Fatal error/.test(document.body.innerText || ''),
      texto: (document.body.innerText||'').replace(/\\s+/g,' ').trim().slice(0,200) })`));
  } else { di('DESTINO', '{}'); }

  //  Y LOS DOS DESTINOS DE LA BARRA que esta fase promete.
  for (const [k, u] of [['CAL', `${BASE}/calendario.php?marca=${marca}`],
                        ['RES', `${BASE}/resultados.php?marca=${marca}`]]) {
    await ir(u);
    di(k, await ev(`JSON.stringify({ url: location.href,
      ok: !/Fatal error/.test(document.body.innerText||''),
      activos: document.querySelectorAll('.botnav a.on').length,
      texto: (document.body.innerText||'').replace(/\\s+/g,' ').trim().slice(0,140) })`));
  }

  di('ALERTAS', await ev('window.__alertas || 0'));
  di('ERRORES', await ev('JSON.stringify(window.__errs || [])'));
  di('OK', 1);
} catch (e) {
  di('OK', 0); di('ERROR', e.message);
} finally {
  cerrar();
}
