// ============================================================
//  CRECER — LA EJECUCIÓN, VISTA EN UN TELÉFONO
//  tests/_ejecucion_probe.mjs
//
//  Tu Meta en sus tres momentos —revisando, programada, midiendo— y el Inicio
//  con los mensajes operativos. Lo que aquí se mira no se puede mirar en PHP:
//  que el dueño entienda de un vistazo dónde está su plan, y que la acción
//  quepa en el primer viewport de un Android de 360.
//
//    node tests/_ejecucion_probe.mjs <carpeta|-> <sid> <marca>
// ============================================================

import { abrirChrome, dormir, cerrarRecibimiento } from './_chrome.mjs';
import fs from 'node:fs';

const [shotsArg, sid, marca] = process.argv.slice(2);
const shots = (shotsArg && shotsArg !== '-') ? shotsArg : '';
const BASE  = 'http://localhost/crecer/panel';
const di = (k, v) => console.log(k + '=' + String(v).replace(/\r?\n/g, ' '));

const SONDA = `
  window.__errs = [];
  addEventListener('error', function (e) { window.__errs.push(String(e.message)); });
  (function (o) { console.error = function () {
      window.__errs.push([].slice.call(arguments).join(' ')); o.apply(console, arguments); }; })(console.error);
`;

//  QUÉ CUENTA TU META. La etapa, la línea, la próxima y las cifras.
const LEER = `(function () {
  var vis = function (el) { if (!el) return false; var r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0 && getComputedStyle(el).display !== 'none'; };
  var txt = function (el) { return el ? (el.textContent || '').replace(/\\s+/g, ' ').trim() : ''; };
  var ej = document.querySelector('.ej');
  if (!ej) return JSON.stringify({ hay: false });
  var pasos = [].map.call(ej.querySelectorAll('.ej-linea li'), function (li) {
    return { t: txt(li.querySelector('span')), on: li.classList.contains('on'),
             ya: li.classList.contains('ya') };
  });
  var prox = ej.querySelector('.ej-prox');
  return JSON.stringify({
    hay: true,
    etapa:  ej.getAttribute('data-etapa') || '',
    turno:  txt(ej.querySelector('.ej-turno')),
    titulo: txt(ej.querySelector('.ej-ahora b')),
    sub:    txt(ej.querySelector('.ej-ahora p')),
    pasos: pasos,
    activos: pasos.filter(function (p) { return p.on; }).length,
    prox: prox ? { txt: txt(prox), href: prox.getAttribute('href') } : null,
    cifras: [].map.call(ej.querySelectorAll('.ej-cifras a'), function (a) {
      return { n: txt(a.querySelector('b')), et: txt(a.querySelector('span')),
               href: a.getAttribute('href'),
               alto: Math.round(a.getBoundingClientRect().height) };
    }),
    //  ¿Cabe la acción principal sin bajar? Es lo que decide si el dueño
    //  decide hoy o mañana.
    horiz: Math.max(0, document.documentElement.scrollWidth - innerWidth),
    finos: [].filter.call(ej.querySelectorAll('b,span,p,i,em,a'), function (el) {
      if (!vis(el)) return false;
      var propio = [].slice.call(el.childNodes).some(function (n) {
        return n.nodeType === 3 && n.textContent.trim().length > 1; });
      return propio && parseFloat(getComputedStyle(el).fontSize) < 14;
    }).map(function (el) { return (el.textContent || '').trim().slice(0, 20)
             + ' @' + getComputedStyle(el).fontSize; })
  });
})()`;

//  Y QUÉ CUENTA INICIO.
const LEER_INICIO = `(function () {
  var txt = function (el) { return el ? (el.textContent || '').replace(/\\s+/g, ' ').trim() : ''; };
  var blk = null;
  [].forEach.call(document.querySelectorAll('.in-blk'), function (b) {
    if (b.querySelector('.in-act')) blk = b;
  });
  if (!blk) return JSON.stringify({ hay: false });
  return JSON.stringify({
    hay: true,
    titulo: txt(blk.querySelector('.in-h b')),
    mensajes: [].map.call(blk.querySelectorAll('.in-act li'), function (li) {
      var a = li.querySelector('a');
      return { txt: txt(li.querySelector('span')), urge: li.classList.contains('urge'),
               accion: a ? txt(a) : '', href: a ? a.getAttribute('href') : '',
               alto: a ? Math.round(a.getBoundingClientRect().height) : 0 };
    }),
    //  Lo que NO puede haber: una recomendación suelta que no conoce la Meta.
    idea: !!document.getElementById('hzIdea')
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
const tam = (w, h, esc = 2) => cmd('Emulation.setDeviceMetricsOverride',
  { width: w, height: h, deviceScaleFactor: esc, mobile: w < 900 });
async function tirar(nombre) {
  if (!shots) return;
  await ev('window.scrollTo(0, 0)');
  await dormir(380);
  const png = await cmd('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
  fs.writeFileSync(`${shots}/${nombre}.png`, Buffer.from(png.data, 'base64'));
}

try {
  await cmd('Page.addScriptToEvaluateOnNewDocument', { source: SONDA });
  await cmd('Network.setCookie', { name: 'PHPSESSID', value: sid, domain: 'localhost', path: '/' });

  //  Los tres momentos los prepara el driver en la base entre pasada y pasada;
  //  aquí solo se mira. El nombre del momento llega por la URL.
  const momento = process.env.CRECER_MOMENTO || 'revisando';

  for (const [n, w, h] of [['360', 360, 800], ['414', 414, 896]]) {
    await tam(w, h);
    await ir(`${BASE}/meta.php?marca=${marca}`);
    di(`META_${n}`, await ev(LEER));
    //  NI UN AVISO DE PHP ENCIMA. Un «Undefined variable» sobre el título es
    //  lo primero que ve el dueño y le dice que el sitio está roto aunque
    //  funcione. Pasó de verdad en esta pantalla: se veía en la captura y
    //  ninguna afirmación lo miraba.
    di(`AVISOS_${n}`, await ev(`(function(){
      var t = document.body.innerText || '';
      return /Undefined variable|Warning:|Notice:|Deprecated:/.test(t) ? t.slice(0, 140) : '';
    })()`));
    if (n === '360') await tirar('meta_' + momento + '_360');

    await ir(`${BASE}/index.php?marca=${marca}`);
    di(`INICIO_${n}`, await ev(LEER_INICIO));
    if (n === '360') await tirar('inicio_' + momento + '_360');
  }

  di('ERRORES', await ev('JSON.stringify(window.__errs || [])'));
  di('OK', 1);
} catch (e) {
  di('OK', 0); di('ERROR', e.message);
} finally {
  cerrar();
}
