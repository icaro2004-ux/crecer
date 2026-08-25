// ============================================================
//  CRECER — LA PANTALLA DE PREPARACION, EN UN NAVEGADOR
//  tests/_preparando_probe.mjs
//
//  Lo que se mira aqui no se puede mirar en PHP: que el dueño ENTIENDA en que
//  punto esta lo suyo, que no se le ofrezca una puerta hacia una pantalla sin
//  decisiones, que recargar no le cambie la historia, y que la salida se vea
//  SIEMPRE — tambien cuando algo falla.
//
//    node tests/_preparando_probe.mjs <carpeta|-> etq:sid:marca [etq:sid:marca ...]
//
//  Un solo Chrome para todos los estados: la cookie de sesion se cambia entre
//  uno y otro. Abrir un navegador por estado cuesta minutos y deja perfiles.
// ============================================================

import { abrirChrome, dormir, cerrarRecibimiento } from './_chrome.mjs';
import fs from 'node:fs';

const [shotsArg, ...estados] = process.argv.slice(2);
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

//  LA MEDIDA. Se hace en el navegador porque es la unica forma honesta de
//  saber si algo se ve: el HTML no sabe de viewport, de barras fijas ni del
//  boton flotante de Ayuda.
const MEDIR = `(function () {
  var vis = function (el) { if (!el) return false; var r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0 && getComputedStyle(el).visibility !== 'hidden'
           && getComputedStyle(el).display !== 'none'; };
  var caja = document.querySelector('.pr');
  var out = { hay: !!caja, horiz: 0, chicos: [], finos: [], primarias: 0 };
  if (!caja) return out;

  out.horiz = Math.max(0, document.documentElement.scrollWidth - innerWidth);
  var bn = document.querySelector('.botnav');
  var techo = innerHeight - (bn && vis(bn) ? bn.getBoundingClientRect().height : 0);
  out.techo = Math.round(techo);

  [].forEach.call(caja.querySelectorAll('button, a'), function (el) {
    if (!vis(el)) return;
    var r = el.getBoundingClientRect();
    if (r.width < 44 || r.height < 44)
      out.chicos.push((el.id || el.className || el.tagName) + ' ' + Math.round(r.width) + 'x' + Math.round(r.height));
    if (r.right > innerWidth + 1 || r.left < -1) out.chicos.push('FUERA ' + (el.id || el.className));
  });
  [].forEach.call(caja.querySelectorAll('p, b, span, button, a, h1, div'), function (el) {
    if (!vis(el)) return;
    var propio = [].slice.call(el.childNodes)
      .some(function (n) { return n.nodeType === 3 && n.textContent.trim().length > 1; });
    if (!propio) return;
    var t = (el.textContent || '').trim();
    var fz = parseFloat(getComputedStyle(el).fontSize);
    if (fz < 14) out.finos.push(t.slice(0, 24) + ' @' + fz);
  });

  //  UNA SOLA ACCION PRINCIPAL, y visible sin scroll.
  var pri = [].filter.call(caja.querySelectorAll('.pr-bt.pri'), vis);
  out.primarias = pri.length;
  if (pri.length) {
    var r = pri[0].getBoundingClientRect();
    out.priId = pri[0].id;
    out.priVisible = r.top >= 0 && r.bottom <= techo && scrollY === 0;
    out.priRect = Math.round(r.top) + '..' + Math.round(r.bottom);
    var c = document.elementFromPoint(r.left + r.width / 2, Math.min(r.top + r.height / 2, techo - 2));
    out.priTapada = !(c && (c === pri[0] || pri[0].contains(c)));
    var fab = document.querySelector('.ay-fab');
    out.priBajoAyuda = false;
    if (fab && vis(fab) && getComputedStyle(fab).opacity !== '0') {
      var f = fab.getBoundingClientRect();
      out.priBajoAyuda = !(r.right < f.left || r.left > f.right || r.bottom < f.top || r.top > f.bottom);
    }
  }
  //  LA SALIDA SIEMPRE TIENE QUE VERSE: nadie se queda encerrado esperando.
  var sal = caja.querySelector('#prVolver');
  out.salidaVisible = vis(sal);
  if (sal) { var s = sal.getBoundingClientRect(); out.salidaSinScroll = s.bottom <= techo; }
  return out;
})()`;

const LEER = `(function () {
  var caja = document.querySelector('.pr');
  if (!caja) return JSON.stringify({ hay: false });
  var vis = function (el) { if (!el) return false; var r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0; };
  var el = function (id) { return document.getElementById(id); };
  return JSON.stringify({
    hay: true,
    estado: caja.getAttribute('data-estado') || '',
    titulo: (el('prQ') || {}).textContent || '',
    ayuda:  (el('prAy') || {}).textContent || '',
    pasos: [].map.call(caja.querySelectorAll('.pr-paso'), function (p) {
      return p.getAttribute('data-t') + ':' +
             (p.className.indexOf('ok') >= 0 ? 'ok' : (p.className.indexOf('ahora') >= 0 ? 'ahora' : '-'));
    }),
    ir: vis(el('prIr')),
    irHref: el('prIr') ? el('prIr').getAttribute('href') : '',
    reintentar: vis(el('prReintentar')),
    volver: vis(el('prVolver')),
    nota: vis(el('prNota')) ? (el('prNota').textContent || '').replace(/\\s+/g, ' ').trim() : '',
    texto: (caja.innerText || '').replace(/\\s+/g, ' ').trim().slice(0, 460)
  });
})()`;

const s = await abrirChrome({ sid: 'x', url: 'about:blank', ancho: 360, alto: 800 });
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
  await dormir(400);
  const png = await cmd('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
  fs.writeFileSync(`${shots}/${nombre}.png`, Buffer.from(png.data, 'base64'));
}

try {
  await cmd('Page.addScriptToEvaluateOnNewDocument', { source: SONDA });

  for (const trio of estados) {
    const [etq, sid, marca] = trio.split(':');
    const URL = `${BASE}/meta.php?marca=${marca}&vista=preparando`;
    await cmd('Network.setCookie',
      { name: 'PHPSESSID', value: sid, domain: 'localhost', path: '/' });

    await tam(360, 800);
    await ir(URL);
    di(etq + '.LEIDO', await ev(LEER));
    di(etq + '.MED_360', await ev(MEDIR).then(JSON.stringify));
    await tirar('prep_' + etq + '_360');

    //  RECARGAR NO CAMBIA LA HISTORIA: el estado vive en la base, no en la
    //  sesion ni en un POST perdido.
    await ir(URL);
    di(etq + '.RECARGA', await ev(LEER));

    for (const [n, w, h, e] of [['414', 414, 896, 2], ['1440', 1440, 900, 1]]) {
      await tam(w, h, e);
      await ir(URL);
      di(etq + '.MED_' + n, await ev(MEDIR).then(JSON.stringify));
      await tirar('prep_' + etq + '_' + n);
    }
  }

  di('ALERTAS', await ev('window.__alertas || 0'));
  di('ERRORES', await ev('JSON.stringify(window.__errs || [])'));
  di('OK', 1);
} catch (e) {
  di('OK', 0); di('ERROR', e.message);
} finally {
  cerrar();
}
