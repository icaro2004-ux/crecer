// ============================================================
//  CRECER — EL DOCK, MEDIDO EN UN NAVEGADOR
//  tests/_dock_probe.mjs
//
//  Un dock se juzga midiéndolo. Aquí se recorre Inicio → Calendario → Tu Meta
//  → Resultados → Inicio y en cada parada se comprueba lo que no se puede
//  comprobar leyendo el CSS: que el activo esté DE VERDAD en el centro, que
//  nadie se solape, que el rótulo no se corte, que Ayuda no tape la barra y
//  que solo uno diga `aria-current`.
//
//    node tests/_dock_probe.mjs <carpeta|-> <sid> <marca> [modo]
//      modo: recorrido (por defecto) · flash · hover · sinjs
// ============================================================

import { abrirChrome, dormir, cerrarRecibimiento } from './_chrome.mjs';
import fs from 'node:fs';

const [shotsArg, sid, marca, modo = 'recorrido'] = process.argv.slice(2);
const shots = (shotsArg && shotsArg !== '-') ? shotsArg : '';
const BASE  = 'http://localhost/crecer/panel';
const di = (k, v) => console.log(k + '=' + String(v).replace(/\r?\n/g, ' '));

const SONDA = `
  window.__errs = [];
  addEventListener('error', function (e) { window.__errs.push(String(e.message)); });
  (function (o) { console.error = function () {
      window.__errs.push([].slice.call(arguments).join(' ')); o.apply(console, arguments); }; })(console.error);
`;

//  LA MEDIDA DEL DOCK. Todo sale de rectángulos reales, no de clases.
const MEDIR = `(function () {
  var dock = document.getElementById('dock');
  if (!dock) return JSON.stringify({ hay: false });
  var cs = getComputedStyle(dock);
  if (cs.display === 'none') return JSON.stringify({ hay: true, visible: false });

  var rd = dock.getBoundingClientRect();
  var items = [].map.call(dock.querySelectorAll('.dk-i'), function (a) {
    var r = a.getBoundingClientRect();
    var b = a.querySelector('.dk-b'), l = a.querySelector('.dk-l');
    var rb = b ? b.getBoundingClientRect() : null;
    return {
      k: a.getAttribute('data-k'),
      act: a.classList.contains('act'),
      cur: a.getAttribute('aria-current') === 'page',
      href: a.getAttribute('href'),
      x: r.left + r.width / 2, w: r.width, h: r.height,
      top: r.top, bottom: r.bottom, left: r.left, right: r.right,
      burbuja: rb ? Math.round(rb.width) : 0,
      //  ¿Se corta el rótulo? Es el defecto clásico de una barra apretada.
      cortado: l ? (l.scrollWidth > l.clientWidth + 1) : false,
      etiqueta: l ? l.textContent.trim() : ''
    };
  });

  //  SOLAPES: se comparan todos contra todos, con los rectángulos de verdad.
  var solapes = [];
  for (var i = 0; i < items.length; i++) {
    for (var j = i + 1; j < items.length; j++) {
      var a = items[i], b = items[j];
      if (a.left < b.right - 1 && b.left < a.right - 1 &&
          a.top < b.bottom - 1 && b.top < a.bottom - 1) solapes.push(a.k + '×' + b.k);
    }
  }

  //  AYUDA no puede caer encima de la barra.
  var fab = document.querySelector('.ay-fab');
  var fabSobreDock = false, fabRect = null, fabSobreMeta = false, fabSobrePri = false;
  if (fab && getComputedStyle(fab).display !== 'none') {
    var rf = fab.getBoundingClientRect();
    fabRect = { top: Math.round(rf.top), bottom: Math.round(rf.bottom),
                left: Math.round(rf.left), right: Math.round(rf.right) };
    fabSobreDock = !(rf.right < rd.left || rf.left > rd.right ||
                     rf.bottom < rd.top || rf.top > rd.bottom);
    //  Y TAMPOCO PUEDE TAPAR LO QUE EL DUEÑO VINO A DECIDIR: la tarjeta de la
    //  Meta ni el botón principal de la pantalla.
    var choca = function (sel) {
      var el = document.querySelector(sel);
      if (!el) return false;
      var r = el.getBoundingClientRect();
      if (r.width <= 0 || r.height <= 0) return false;
      return !(rf.right < r.left || rf.left > r.right || rf.bottom < r.top || rf.top > r.bottom);
    };
    fabSobreMeta = choca('.norte');
    fabSobrePri  = choca('.cz-bt.pri') || choca('.sm-bt.pri') || choca('.in-go');
  }

  var act = items.filter(function (i) { return i.act; })[0] || null;
  return JSON.stringify({
    hay: true, visible: true, medido: dock.classList.contains('medido'),
    dockCentro: rd.left + rd.width / 2, dockTop: rd.top, dockAlto: Math.round(rd.height),
    items: items, solapes: solapes,
    activos: items.filter(function (i) { return i.act; }).length,
    current: items.filter(function (i) { return i.cur; }).length,
    activoK: act ? act.k : '',
    //  LA CIFRA QUE IMPORTA: cuánto se desvía el activo del centro exacto.
    desvio: act ? Math.abs(act.x - (rd.left + rd.width / 2)) : -1,
    horiz: Math.max(0, document.documentElement.scrollWidth - innerWidth),
    //  QUE QUEPAN EN LA PANTALLA. Un elemento fijo que se sale por el borde no
    //  siempre crea scroll horizontal —así que «horiz» decía que todo iba bien
    //  mientras el último rótulo se cortaba contra el canto—. Se mira el
    //  rectángulo de cada uno contra el ancho real.
    //  Y TAMPOCO POR ABAJO: la etiqueta del activo se salía del canto de la
    //  pantalla y se leía a medias. Se mira contra la barra y contra el alto.
    desborda: items.filter(function (i) { return i.bottom > innerHeight + 0.5 || i.top < rd.top - 0.5; })
                   .map(function (i) { return i.k + ' ' + Math.round(i.top) + '..' + Math.round(i.bottom); }),
    fuera: items.filter(function (i) { return i.left < -0.5 || i.right > innerWidth + 0.5; })
                .map(function (i) { return i.k + ' ' + Math.round(i.left) + '..' + Math.round(i.right); }),
    fabSobreDock: fabSobreDock, fabSobreMeta: fabSobreMeta, fabSobrePri: fabSobrePri, fabRect: fabRect,
    altoPagina: Math.round(document.documentElement.scrollHeight)
  });
})()`;

const s = await abrirChrome({ sid, url: 'about:blank', ancho: 360, alto: 800 });
if (s.error) { di('OK', 0); di('ERROR', s.error); process.exit(1); }
const { ev, cmd, cerrar } = s;

const listo = async () => {
  for (let i = 0; i < 160; i++) {
    if (await ev('document.readyState === "complete"')) { await dormir(340); return; }
    await dormir(110);
  }
};
const ir = async (u) => { await cmd('Page.navigate', { url: u }); await listo(); await cerrarRecibimiento(ev); };
const tam = (w, h, esc = 2, movil = true) => cmd('Emulation.setDeviceMetricsOverride',
  { width: w, height: h, deviceScaleFactor: esc, mobile: movil });
async function tirar(nombre) {
  if (!shots) return;
  await dormir(380);
  const png = await cmd('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
  fs.writeFileSync(`${shots}/${nombre}.png`, Buffer.from(png.data, 'base64'));
}

const RUTAS = [
  ['inicio',     'index.php'],
  ['calendario', 'calendario.php'],
  ['meta',       'meta.php'],
  ['resultados', 'resultados.php'],
];

try {
  await cmd('Page.addScriptToEvaluateOnNewDocument', { source: SONDA });
  await cmd('Network.setCookie', { name: 'PHPSESSID', value: sid, domain: 'localhost', path: '/' });

  if (modo === 'flash') {
    //  LOS PRIMEROS CUADROS, QUE ES DONDE VIVÍA EL DEFECTO.
    //
    //  Mirar el estado final no servía de nada: el dock acababa bien SIEMPRE.
    //  Lo que el dueño veía era el camino — la barra desordenada y luego el
    //  activo deslizándose desde la izquierda— y eso solo se caza muestreando
    //  mientras la página se pinta.
    const MUESTRA = `(function () {
      var d = document.getElementById('dock');
      if (!d) return null;
      var cs = getComputedStyle(d);
      if (cs.display === 'none') return null;
      var rd = d.getBoundingClientRect();
      if (rd.height <= 0) return null;
      var its = [].map.call(d.querySelectorAll('.dk-i'), function (a) {
        var r = a.getBoundingClientRect();
        return { k: a.getAttribute('data-k'), act: a.classList.contains('act'),
                 x: r.left + r.width / 2, y: r.top + r.height / 2,
                 l: r.left, r2: r.right };
      });
      var act = its.filter(function (i) { return i.act; })[0] || null;
      return { t: Math.round(performance.now()),
               n: its.length,
               alto: Math.round(rd.height), top: Math.round(rd.top),
               desvio: act ? +(act.x - (rd.left + rd.width / 2)).toFixed(1) : null,
               actY: act ? +act.y.toFixed(1) : null,
               actK: act ? act.k : '',
               current: d.querySelectorAll('[aria-current="page"]').length,
               fuera: its.filter(function (i) { return i.l < -0.5 || i.r2 > innerWidth + 0.5; }).length,
               horiz: Math.max(0, document.documentElement.scrollWidth - innerWidth) };
    })()`;

    //  Se muestrea CRUDO, sin esperar a `load`: en cuanto haya algo pintado.
    const muestrear = async (ms = 500) => {
      const out = [];
      const t0 = Date.now();
      while (Date.now() - t0 < ms) {
        try {
          const r = await cmd('Runtime.evaluate', { expression: MUESTRA, returnByValue: true });
          const v = r?.result?.value;
          if (v) out.push(v);
        } catch (e) { /* el documento todavía no existe: no es una muestra */ }
        await dormir(25);
      }
      return out;
    };

    //  UNA VUELTA DE CALENTAMIENTO ANTES DE MEDIR. La primera carga del
    //  proceso arranca PHP en frío y puede pasar de medio segundo: las
    //  muestras salen vacías por eso y no por el dock. Lo que se quiere
    //  medir es una recarga de verdad, no el primer arranque del servidor.
    for (const [, arch] of RUTAS) {
      await ir(`${BASE}/${arch}?marca=${marca}`);
    }

    for (const [n, w, h] of [['360', 360, 800], ['414', 414, 896]]) {
      await tam(w, h);
      for (const [k, arch] of RUTAS) {
        //  RECARGA DURA: se navega y se empieza a mirar de inmediato.
        await cmd('Page.navigate', { url: `${BASE}/${arch}?marca=${marca}` });
        di(`F_${n}_${k}`, JSON.stringify(await muestrear(500)));
        await listo();
        await cerrarRecibimiento(ev);
      }

      //  ATRÁS Y ADELANTE: el navegador devuelve la página tal cual, y tiene
      //  que aparecer quieta — no terminando una animación de hace dos
      //  páginas.
      await ev('history.back()');
      di(`F_${n}_atras`, JSON.stringify(await muestrear(400)));
      await ev('history.forward()');
      di(`F_${n}_adelante`, JSON.stringify(await muestrear(400)));
    }

    di('ERRORES', await ev('JSON.stringify(window.__errs || [])'));
    di('OK', 1);
    cerrar();
    process.exit(0);
  }

  if (modo === 'sinjs') {
    //  SIN JAVASCRIPT los enlaces tienen que seguir navegando. Es la prueba de
    //  que el dock es navegación y no una animación con enlaces dentro.
    await cmd('Emulation.setScriptExecutionDisabled', { value: true });
    await tam(360, 800);
    await cmd('Page.navigate', { url: `${BASE}/index.php?marca=${marca}` });
    await dormir(1400);
    di('SINJS_INICIO', await ev(`document.querySelectorAll('#dock .dk-i').length`)
       .catch(() => 0));
    //  Se navega por el href, como haría el navegador sin JS.
    const href = `${BASE}/calendario.php?marca=${marca}`;
    await cmd('Page.navigate', { url: href });
    await dormir(1400);
    const url = await cmd('Runtime.evaluate', { expression: 'location.href' })
      .then(r => r?.result?.value || '').catch(() => '');
    di('SINJS_URL', url);
    //  Y SIN JS TAMBIÉN SALE ASENTADO: la geometría la pinta el servidor, así
    //  que aquí no hay nada que esperar.
    const cen = await cmd('Runtime.evaluate', { expression: `(function(){
      var d = document.getElementById('dock'); if (!d) return null;
      var rd = d.getBoundingClientRect();
      var a = d.querySelector('.dk-i.act'); if (!a) return null;
      var r = a.getBoundingClientRect();
      return JSON.stringify({ desvio: +(r.left + r.width/2 - (rd.left + rd.width/2)).toFixed(1),
                              k: a.getAttribute('data-k'), alto: Math.round(rd.height) });
    })()`, returnByValue: true }).then(r => r?.result?.value || '{}').catch(() => '{}');
    di('SINJS_CENTRO', cen);
    await cmd('Emulation.setScriptExecutionDisabled', { value: false });
    di('OK', 1);
    cerrar();
    process.exit(0);
  }

  if (modo === 'hover') {
    //  HOVER. El dock vive donde el menú lateral no está: por encima de 860px
    //  manda el lateral y el dock se esconde a propósito. Así que el hover se
    //  mide donde el dock EXISTE y hay puntero fino — no en un 1440 donde no
    //  hay nada que señalar.
    await tam(820, 900, 1, false);
    await ir(`${BASE}/calendario.php?marca=${marca}`);
    const antes = await ev(`(function(){
      var a = document.querySelector('#dock .dk-i[data-k="resultados"]');
      return a ? a.getBoundingClientRect().width * 0 + parseFloat(getComputedStyle(a.querySelector('.dk-b')).width) : 0;
    })()`);
    //  Se mueve el puntero de verdad: un `mouseenter` sintético no prueba CSS.
    const caja = await ev(`(function(){
      var a = document.querySelector('#dock .dk-i[data-k="resultados"]');
      var r = a.getBoundingClientRect();
      return JSON.stringify({ x: Math.round(r.left + r.width/2), y: Math.round(r.top + r.height/2) });
    })()`).then(t => JSON.parse(t));
    await cmd('Input.dispatchMouseEvent', { type: 'mouseMoved', x: caja.x, y: caja.y });
    await dormir(420);
    di('HOVER', await ev(`JSON.stringify((function(){
      var a = document.querySelector('#dock .dk-i[data-k="resultados"]');
      var v = document.querySelectorAll('#dock .dk-i.vec').length;
      var t = getComputedStyle(a).transform;
      var esc = 1;
      if (t && t !== 'none') { var m = t.match(/matrix\\(([^,]+)/); if (m) esc = parseFloat(m[1]); }
      return { escala: esc, vecinos: v,
               alto: Math.round(document.documentElement.scrollHeight) };
    })())`));
    await tirar('dock_hover_escritorio');

    //  Y a 1440 el dock NO se pinta: la navegación es el lateral.
    await tam(1440, 900, 1, false);
    await ir(`${BASE}/index.php?marca=${marca}`);
    di('D1440', await ev(`JSON.stringify((function(){
      var d = document.getElementById('dock');
      return { oculto: !d || getComputedStyle(d).display === 'none',
               lateral: !!document.querySelector('.side nav a'),
               horiz: Math.max(0, document.documentElement.scrollWidth - innerWidth) };
    })())`));
    di('ERRORES', await ev('JSON.stringify(window.__errs || [])'));
    di('OK', 1);
    cerrar();
    process.exit(0);
  }

  //  ── EL RECORRIDO: Inicio → Calendario → Tu Meta → Resultados → Inicio ──
  for (const [n, w, h] of [['360', 360, 800], ['414', 414, 896]]) {
    await tam(w, h);
    let alturas = [];
    for (const [k, arch] of RUTAS) {
      await ir(`${BASE}/${arch}?marca=${marca}`);
      const med = await ev(MEDIR);
      di(`M_${n}_${k}`, med);
      try { alturas.push(JSON.parse(med).altoPagina); } catch (e) {}
      if (n === '360') await tirar(`dock_${k}_360`);
    }
    //  Y de vuelta a Inicio: el círculo se cierra.
    await ir(`${BASE}/index.php?marca=${marca}`);
    di(`M_${n}_vuelta`, await ev(MEDIR));
    di(`ALTURAS_${n}`, JSON.stringify(alturas));
  }

  //  TOCAR OTRO DESTINO: se anima y navega, sin tardar.
  await tam(360, 800);
  await ir(`${BASE}/index.php?marca=${marca}`);
  //  SE MIDE LO QUE TARDA EN ARRANCAR, no lo que tarda en cargar la página
  //  siguiente. Lo segundo depende del servidor y no dice nada del dock; lo
  //  primero es la promesa: la animación no puede hacerle esperar.
  const t0 = Date.now();
  await ev(`(function(){var a=document.querySelector('#dock .dk-i[data-k="calendario"]');
    if(a) a.click(); return true;})()`);
  let arranco = -1, url = '';
  for (let i = 0; i < 80; i++) {
    url = String(await ev('location.href').catch(() => ''));
    if (url.indexOf('calendario.php') >= 0) { arranco = Date.now() - t0; break; }
    await dormir(25);
  }
  di('TOQUE', JSON.stringify({ ms: arranco, url: url }));

  di('ERRORES', await ev('JSON.stringify(window.__errs || [])'));
  di('OK', 1);
} catch (e) {
  di('OK', 0); di('ERROR', e.message);
} finally {
  cerrar();
}
