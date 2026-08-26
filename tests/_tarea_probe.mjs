// ============================================================
//  CRECER — LA TAREA DEL DUEÑO, EN UN NAVEGADOR
//  tests/_tarea_probe.mjs
//
//  Aquí no se comprueba que el HTML diga las cosas: se PULSAN los controles.
//  Que «Ya lo hice» guarde de verdad, que la revisión avance sola a lo
//  siguiente, que al volver los conteos hayan cambiado, y que una tarea no se
//  vea nunca como una publicación con la foto vacía.
//
//    node tests/_tarea_probe.mjs <carpeta|-> etq:sid:marca:pos [...]
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

const LEER = `(function () {
  var caja = document.querySelector('.sm');
  if (!caja) return JSON.stringify({ hay: false });
  var on = caja.querySelector('.sm-p.on');
  if (!on) return JSON.stringify({ hay: true, on: false });
  var vis = function (el) { if (!el) return false; var r = el.getBoundingClientRect();
    var cs = getComputedStyle(el);
    return r.width > 0 && r.height > 0 && cs.display !== 'none' && cs.visibility !== 'hidden'; };
  var q = function (s) { return on.querySelector(s); };
  return JSON.stringify({
    hay: true, on: true,
    n:       on.dataset.n,
    clave:   on.dataset.clave,
    cuenta:  (document.getElementById('smPaso') || {}).textContent || '',
    esTarea: !!q('.sm-tarea'),
    hayFoto: vis(q('.sm-media')),
    etiqueta: q('.sm-tarea .et') ? q('.sm-tarea .et').textContent.trim() : '',
    titulo:  q('.sm-tarea h2') ? q('.sm-tarea h2').textContent.trim() : '',
    estado:  q('.sm-tarea .est') ? q('.sm-tarea .est').textContent.trim() : '',
    yaLoHice: vis(q('[data-tarea-hecha]')),
    noPuedo:  !!(function () {
      var a = [].filter.call(on.querySelectorAll('.sm-pie a[href]'), vis)
        .filter(function (e) { return /no puedo/i.test(e.textContent); });
      return a.length ? a[0] : null; })(),
    noPuedoHref: (function () {
      var a = [].filter.call(on.querySelectorAll('.sm-pie a[href]'), function (e) { return /no puedo/i.test(e.textContent); });
      return a.length ? a[0].getAttribute('href') : ''; })(),
    salida:  (function () { var b = q('[data-siguiente]'); return b ? b.textContent.trim() : ''; })(),
    aprobar: vis(q('[data-aprobar]')),
    hecho:   vis(q('[data-hecho]')),
    texto:   (on.innerText || '').replace(/\\s+/g, ' ').trim().slice(0, 460)
  });
})()`;

const MEDIR = `(function () {
  var vis = function (el) { if (!el) return false; var r = el.getBoundingClientRect();
    var cs = getComputedStyle(el);
    return r.width > 0 && r.height > 0 && cs.display !== 'none' && cs.visibility !== 'hidden'; };
  var on = document.querySelector('.sm-p.on');
  var out = { hay: !!on, horiz: 0, chicos: [], finos: [], primarias: 0 };
  if (!on) return out;
  out.horiz = Math.max(0, document.documentElement.scrollWidth - innerWidth);
  var bn = document.querySelector('.botnav');
  var techo = innerHeight - (bn && vis(bn) ? bn.getBoundingClientRect().height : 0);
  out.techo = Math.round(techo);

  [].forEach.call(on.querySelectorAll('button, a'), function (el) {
    if (!vis(el)) return;
    var r = el.getBoundingClientRect();
    if (r.width < 44 || r.height < 44)
      out.chicos.push((el.className || el.tagName) + ' ' + Math.round(r.width) + 'x' + Math.round(r.height));
    if (r.right > innerWidth + 1 || r.left < -1) out.chicos.push('FUERA ' + el.className);
  });
  [].forEach.call(on.querySelectorAll('p, b, span, button, a, h2'), function (el) {
    if (!vis(el)) return;
    var propio = [].slice.call(el.childNodes)
      .some(function (n) { return n.nodeType === 3 && n.textContent.trim().length > 1; });
    if (!propio) return;
    var fz = parseFloat(getComputedStyle(el).fontSize);
    if (fz < 14) out.finos.push((el.textContent || '').trim().slice(0, 22) + ' @' + fz);
  });

  var pri = [].filter.call(on.querySelectorAll('.sm-bt.pri'), vis);
  out.primarias = pri.length;
  if (pri.length) {
    var r = pri[0].getBoundingClientRect();
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
  var sal = on.querySelector('[data-siguiente]');
  out.salidaSinScroll = vis(sal) ? sal.getBoundingClientRect().bottom <= techo : null;
  return out;
})()`;

const s = await abrirChrome({ sid: 'x', url: 'about:blank', ancho: 360, alto: 800 });
if (s.error) { di('OK', 0); di('ERROR', s.error); process.exit(1); }
const { ev, cmd, cerrar } = s;

const listo = async () => {
  for (let i = 0; i < 180; i++) {
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
async function clic(sel) {
  const r = await ev(`(function(){var e=document.querySelector('${sel}');if(!e)return null;
    e.scrollIntoView({block:'center'});var b=e.getBoundingClientRect();
    return JSON.stringify({x:b.left+b.width/2,y:b.top+b.height/2});})()`);
  if (!r) return false;
  const { x, y } = JSON.parse(r);
  for (const type of ['mousePressed', 'mouseReleased']) {
    await cmd('Input.dispatchMouseEvent',
      { type, x, y, button: 'left', clickCount: 1, buttons: type === 'mousePressed' ? 1 : 0 });
  }
  return true;
}

try {
  await cmd('Page.addScriptToEvaluateOnNewDocument', { source: SONDA });

  for (const cuatro of estados) {
    const [etq, sid, marca, pos] = cuatro.split(':');
    const URL = `${BASE}/meta.php?marca=${marca}&vista=semana&pos=${pos}`;
    await cmd('Network.setCookie',
      { name: 'PHPSESSID', value: sid, domain: 'localhost', path: '/' });

    await tam(360, 800);
    await ir(URL);
    di(etq + '.LEIDO', await ev(LEER));
    di(etq + '.MED_360', await ev(MEDIR).then(JSON.stringify));
    await tirar('tarea_' + etq + '_360');

    for (const [n, w, h, e] of [['414', 414, 896, 2], ['1440', 1440, 900, 1]]) {
      await tam(w, h, e);
      await ir(URL);
      di(etq + '.MED_' + n, await ev(MEDIR).then(JSON.stringify));
      if (n === '1440') await tirar('tarea_' + etq + '_1440');
    }
  }

  // ══════════════════════════════════════════════════════════════
  //  PULSAR «YA LO HICE» DE VERDAD, y ver qué pasa después
  // ══════════════════════════════════════════════════════════════
  const solo = estados.find((e) => e.startsWith('solo:'));
  if (solo) {
    const [, sid, marca, pos] = solo.split(':');
    await cmd('Network.setCookie',
      { name: 'PHPSESSID', value: sid, domain: 'localhost', path: '/' });
    await tam(360, 800);
    await ir(`${BASE}/meta.php?marca=${marca}&vista=semana&pos=${pos}`);

    di('CLIC.ANTES', await ev(LEER));
    await clic('.sm-p.on [data-tarea-hecha]');
    await dormir(2800);
    //  LO QUE PASA JUSTO DESPUES: la revision AVANZA. No se queda mirando la
    //  que acaba de resolver ni salta al final si todavia queda algo.
    di('CLIC.DESPUES', await ev(`JSON.stringify({
      on: (document.querySelector('.sm-p.on')||{}).dataset ? document.querySelector('.sm-p.on').dataset.n : '',
      esFin: !!(document.querySelector('.sm-p.on') && document.querySelector('.sm-p.on').dataset.fin),
      err: (function(){var e=document.querySelector('.sm-p [data-err]');
             return e && e.classList.contains('on') ? e.textContent.trim() : '';})()
    })`));

    //  Y SE SIGUE HASTA EL FINAL, para leer el cierre con sus numeros de
    //  verdad —recontar() solo corre cuando de verdad se llega alli—.
    for (let i = 0; i < 6; i++) {
      const fin = await ev(`!!(document.querySelector('.sm-p.on') && document.querySelector('.sm-p.on').dataset.fin)`);
      if (fin) break;
      if (!await clic('.sm-p.on [data-siguiente]')) break;
      await dormir(700);
    }
    di('CIERRE', await ev(`JSON.stringify({
      esFin: !!(document.querySelector('.sm-p.on') && document.querySelector('.sm-p.on').dataset.fin),
      cierreT: (document.getElementById('smFinT')||{}).textContent || '',
      cierreP: (document.getElementById('smFinP')||{}).textContent || '',
      listas: (document.getElementById('smFinA')||{}).textContent || '',
      sinDecidir: (document.getElementById('smFinB')||{}).textContent || '',
      hechas: (document.getElementById('smFinD')||{}).textContent || ''
    })`));
    await tirar('tarea_cierre_360');

    //  RECARGAR: lo guardado tiene que seguir guardado.
    await ir(`${BASE}/meta.php?marca=${marca}&vista=semana&pos=${pos}`);
    di('CLIC.TRAS_RECARGA', await ev(LEER));

    //  Y LA LLEGADA DE 2C, que lee del mismo dominio.
    await ir(`${BASE}/meta.php?marca=${marca}&vista=preparando`);
    di('CLIC.LLEGADA', await ev(`JSON.stringify({
      estado: (document.querySelector('.pr')||{}).getAttribute
                ? document.querySelector('.pr').getAttribute('data-estado') : '',
      semana: (document.getElementById('prSemana')||{}).textContent || '',
      titulo: (document.getElementById('prQ')||{}).textContent || ''
    })`));
  }

  // ══════════════════════════════════════════════════════════════
  //  «NO PUEDO CON ESTA» · lleva a la sustitución que ya existe
  // ══════════════════════════════════════════════════════════════
  const mixta = estados.find((e) => e.startsWith('mixta:'));
  if (mixta) {
    const [, sid, marca, pos] = mixta.split(':');
    await cmd('Network.setCookie',
      { name: 'PHPSESSID', value: sid, domain: 'localhost', path: '/' });
    await tam(360, 800);
    await ir(`${BASE}/meta.php?marca=${marca}&vista=semana&pos=${pos}`);
    const fue = await clic('.sm-p.on .sm-pie a[href*="vista=sustituir"]');
    if (fue) { await listo(); await cerrarRecibimiento(ev); }
    di('SUST.DESTINO', await ev(`JSON.stringify({
      url: location.href,
      hayWizard: !!document.querySelector('.su, .wz, [id^="su"]'),
      texto: ((document.querySelector('main, body')||{}).innerText||'')
               .replace(/\\s+/g,' ').trim().slice(0, 260)
    })`));
  }

  di('ALERTAS', await ev('window.__alertas || 0'));
  di('ERRORES', await ev('JSON.stringify(window.__errs || [])'));
  di('OK', 1);
} catch (e) {
  di('OK', 0); di('ERROR', e.message);
} finally {
  cerrar();
}
