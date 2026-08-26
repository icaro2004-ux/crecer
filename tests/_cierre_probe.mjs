// ============================================================
//  CRECER — EL CIERRE DEL PLAN, EN UN NAVEGADOR
//  tests/_cierre_probe.mjs
//
//  El recorrido entero, con el ratón: abrir la semana con una única tarea,
//  pulsar «Ya lo hice», ver el cierre semanal, volver a Tu Meta y encontrar
//  «Completaste este plan» —no un fallo—, recargar y que siga igual, abrir la
//  capa del plan terminado y cerrarla volviendo al mismo punto.
//
//    node tests/_cierre_probe.mjs <carpeta|-> <sid> <marca> <pos> [sidSinPlan:marcaSinPlan]
// ============================================================

import { abrirChrome, dormir, cerrarRecibimiento } from './_chrome.mjs';
import fs from 'node:fs';

const [shotsArg, sid, marca, pos, sinPlan] = process.argv.slice(2);
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
  var caja = document.querySelector('.pr');
  if (!caja) return JSON.stringify({ hay: false });
  var vis = function (el) { if (!el) return false; var r = el.getBoundingClientRect();
    var cs = getComputedStyle(el);
    return r.width > 0 && r.height > 0 && cs.display !== 'none' && cs.visibility !== 'hidden'; };
  var el = function (i) { return document.getElementById(i); };
  var tx = function (i) { var e = el(i); return e ? (e.textContent || '').trim() : ''; };
  return JSON.stringify({
    hay: true,
    estado:  caja.getAttribute('data-estado') || '',
    titulo:  tx('prQ'),
    ayuda:   tx('prAy'),
    meta:    vis(el('prMeta')) ? tx('prMeta') : '',
    semana:  vis(el('prSemana')) ? tx('prSemana') : '',
    resumen: vis(el('prRes')),
    pasos:   vis(el('prPasos')),
    ir:         vis(el('prIr')),
    ver:        vis(el('prVer')),
    reintentar: vis(el('prReintentar')),
    explica:    vis(el('prExplicar')),
    explicaTx:  tx('prExplicarTx'),
    explicaPri: !!(el('prExplicar') && el('prExplicar').className.indexOf('pri') >= 0),
    volver:     vis(el('prVolver')),
    texto:   (caja.innerText || '').replace(/\\s+/g, ' ').trim().slice(0, 600)
  });
})()`;

const MEDIR = `(function () {
  var vis = function (el) { if (!el) return false; var r = el.getBoundingClientRect();
    var cs = getComputedStyle(el);
    return r.width > 0 && r.height > 0 && cs.display !== 'none' && cs.visibility !== 'hidden'; };
  var caja = document.querySelector('.pr');
  var out = { hay: !!caja, horiz: 0, chicos: [], finos: [], primarias: 0 };
  if (!caja) return out;
  out.horiz = Math.max(0, document.documentElement.scrollWidth - innerWidth);
  var bn = document.querySelector('.botnav');
  var techo = innerHeight - (bn && vis(bn) ? bn.getBoundingClientRect().height : 0);
  out.techo = Math.round(techo);
  [].forEach.call(caja.querySelectorAll('button, a'), function (e) {
    if (!vis(e)) return;
    var r = e.getBoundingClientRect();
    if (r.width < 44 || r.height < 44)
      out.chicos.push((e.id || e.className) + ' ' + Math.round(r.width) + 'x' + Math.round(r.height));
    if (r.right > innerWidth + 1 || r.left < -1) out.chicos.push('FUERA ' + (e.id || e.className));
  });
  [].forEach.call(caja.querySelectorAll('p, b, i, span, button, a, h1'), function (e) {
    if (!vis(e)) return;
    var propio = [].slice.call(e.childNodes)
      .some(function (n) { return n.nodeType === 3 && n.textContent.trim().length > 1; });
    if (!propio) return;
    var fz = parseFloat(getComputedStyle(e).fontSize);
    if (fz < 14) out.finos.push((e.textContent || '').trim().slice(0, 22) + ' @' + fz);
  });
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
  var sal = document.getElementById('prVolver');
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
const tecla = (key, code, keyCode) => cmd('Input.dispatchKeyEvent',
  { type: 'keyDown', key, code, windowsVirtualKeyCode: keyCode }).then(() =>
  cmd('Input.dispatchKeyEvent', { type: 'keyUp', key, code, windowsVirtualKeyCode: keyCode }));

const LLEGADA = `${BASE}/meta.php?marca=${marca}&vista=preparando`;

try {
  await cmd('Page.addScriptToEvaluateOnNewDocument', { source: SONDA });
  await cmd('Network.setCookie', { name: 'PHPSESSID', value: sid, domain: 'localhost', path: '/' });

  // ── 1 · LA SEMANA, CON UNA ÚNICA TAREA ───────────────────────
  await tam(360, 800);
  await ir(`${BASE}/meta.php?marca=${marca}&vista=semana&pos=${pos}`);
  di('SEMANA.ANTES', await ev(`JSON.stringify({
    hayTarea: !!document.querySelector('.sm-p.on .sm-tarea'),
    yaLoHice: !!document.querySelector('.sm-p.on [data-tarea-hecha]')
  })`));

  // ── 2 · «YA LO HICE» ─────────────────────────────────────────
  await clic('.sm-p.on [data-tarea-hecha]');
  await dormir(2800);
  di('SEMANA.CIERRE', await ev(`JSON.stringify({
    esFin: !!(document.querySelector('.sm-p.on') && document.querySelector('.sm-p.on').dataset.fin),
    titulo: (document.getElementById('smFinT')||{}).textContent || '',
    sinDecidir: (document.getElementById('smFinB')||{}).textContent || '',
    err: (function(){var e=document.querySelector('.sm-p [data-err]');
           return e && e.classList.contains('on') ? e.textContent.trim() : '';})()
  })`));
  await tirar('cierre_semana_360');

  // ── 3 · VOLVER A TU META · aquí estaba la mentira ────────────
  await ir(LLEGADA);
  di('LLEGADA', await ev(LEER));
  di('LLEGADA.MED_360', await ev(MEDIR).then(JSON.stringify));
  await tirar('cierre_llegada_360');

  // ── 4 · RECARGAR ─────────────────────────────────────────────
  await ir(LLEGADA);
  di('LLEGADA.RECARGA', await ev(LEER));

  // ── 5 · LA CAPA DEL PLAN TERMINADO ───────────────────────────
  await ev('window.scrollTo(0, 90)');
  const scrollAntes = await ev('Math.round(scrollY)');
  await clic('#prExplicar');
  await dormir(420);
  di('HOJA', await ev(`JSON.stringify({
    visible: !!document.querySelector('.pr-velo.on'),
    focoDentro: !!(document.getElementById('prHoja') &&
                   document.getElementById('prHoja').contains(document.activeElement)),
    titulo: (document.getElementById('prHojaT')||{}).textContent || '',
    texto: (document.querySelector('.pr-hoja .cuerpo')||{}).innerText
             ? document.querySelector('.pr-hoja .cuerpo').innerText.replace(/\\s+/g,' ').trim().slice(0,1400) : '',
    horiz: Math.max(0, document.documentElement.scrollWidth - innerWidth)
  })`));
  await tirar('cierre_hoja_360');
  await tecla('Escape', 'Escape', 27);
  await dormir(320);
  di('HOJA.CERRADA', await ev(`JSON.stringify({
    visible: !!document.querySelector('.pr-velo.on'),
    focoEnBoton: document.activeElement === document.getElementById('prExplicar'),
    scroll: Math.round(scrollY)
  })`));
  di('HOJA.SCROLL_ANTES', scrollAntes);

  // ── 6 · LOS OTROS ANCHOS ─────────────────────────────────────
  for (const [n, w, h, e] of [['414', 414, 896, 2], ['1440', 1440, 900, 1]]) {
    await tam(w, h, e);
    await ir(LLEGADA);
    di('LLEGADA.MED_' + n, await ev(MEDIR).then(JSON.stringify));
    await tirar('cierre_llegada_' + n);
  }

  // ── 7 · Y EL QUE DE VERDAD FALLÓ SIGUE PUDIENDO REINTENTAR ───
  if (sinPlan) {
    const [sid2, marca2] = sinPlan.split(':');
    await cmd('Network.setCookie',
      { name: 'PHPSESSID', value: sid2, domain: 'localhost', path: '/' });
    await tam(360, 800);
    await ir(`${BASE}/meta.php?marca=${marca2}&vista=preparando`);
    di('SINPLAN', await ev(LEER));
    di('SINPLAN.MED_360', await ev(MEDIR).then(JSON.stringify));
    await tirar('cierre_sinplan_360');
  }

  di('ALERTAS', await ev('window.__alertas || 0'));
  di('ERRORES', await ev('JSON.stringify(window.__errs || [])'));
  di('OK', 1);
} catch (e) {
  di('OK', 0); di('ERROR', e.message);
} finally {
  cerrar();
}
