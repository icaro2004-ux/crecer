// ============================================================
//  CRECER — LA MISMA HISTORIA EN TRES PANTALLAS
//  tests/_coherente_probe.mjs
//
//  El recorrido entero con el ratón: Semana con la última tarea → «Ya lo hice»
//  → cierre semanal → Tu Meta → Inicio → Tu Meta → recargar → plan explicado.
//  Lo que se comprueba no es que cada pantalla esté bien por separado: es que
//  las tres digan lo MISMO del mismo plan.
//
//    node tests/_coherente_probe.mjs <carpeta|-> <sid> <marca> <pos>
// ============================================================

import { abrirChrome, dormir, cerrarRecibimiento } from './_chrome.mjs';
import fs from 'node:fs';

const [shotsArg, sid, marca, pos] = process.argv.slice(2);
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

//  LO QUE DICE LA TARJETA QUE MANDA, no la página entera. Leer `main` traía el
//  menú lateral entero y el texto de la tarjeta se quedaba fuera del recorte:
//  la prueba medía la navegación, no la respuesta.
//    Tu Meta  → .ah        (lo que toca ahora)
//    Inicio   → .norte     (la tarjeta de la meta)
//    Llegada  → .pr
const TEXTO = `(function () {
  //  «.plan» ES LA CAPA 2, y faltaba. Sin ella se caia a document.body, cuyo
  //  innerText empieza por el cajon lateral entero: los primeros 700
  //  caracteres eran el menu y la afirmacion decia que el plan estaba vacio
  //  cuando lo que pasaba es que no se habia llegado a leer.
  var c = document.querySelector('.ah') || document.querySelector('.norte')
       || document.querySelector('.pr') || document.querySelector('.plan')
       || document.body;
  return (c.innerText || '').replace(/\\s+/g, ' ').trim();
})()`;

//  La medida de la tarjeta que manda en cada pantalla.
const MEDIR = (sel) => `(function () {
  var vis = function (el) { if (!el) return false; var r = el.getBoundingClientRect();
    var cs = getComputedStyle(el);
    return r.width > 0 && r.height > 0 && cs.display !== 'none' && cs.visibility !== 'hidden'; };
  var caja = document.querySelector('${sel}');
  var out = { hay: !!caja, horiz: 0, chicos: [], finos: [], primarias: 0 };
  out.horiz = Math.max(0, document.documentElement.scrollWidth - innerWidth);
  if (!caja) return out;
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
  [].forEach.call(caja.querySelectorAll('p, b, i, span, button, a, h1, h2'), function (e) {
    if (!vis(e)) return;
    var propio = [].slice.call(e.childNodes)
      .some(function (n) { return n.nodeType === 3 && n.textContent.trim().length > 1; });
    if (!propio) return;
    var fz = parseFloat(getComputedStyle(e).fontSize);
    if (fz < 14) out.finos.push((e.textContent || '').trim().slice(0, 22) + ' @' + fz);
  });
  //  La primaria de cada casa: .pr-bt.pri en la llegada, .tm-btn en Tu Meta y
  //  en Home. Se cuentan juntas porque la regla es la misma: una sola.
  var pri = [].filter.call(caja.querySelectorAll('.pr-bt.pri, .tm-btn:not(.linea)'), vis);
  out.primarias = pri.length;
  if (pri.length) {
    var r = pri[0].getBoundingClientRect();
    out.priTx = (pri[0].textContent || '').trim().slice(0, 40);
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
  return out;
})()`;

const s = await abrirChrome({ sid: 'x', url: 'about:blank', ancho: 360, alto: 800 });
if (s.error) { di('OK', 0); di('ERROR', s.error); process.exit(1); }
const { ev, cmd, cerrar } = s;

const listo = async () => {
  for (let i = 0; i < 180; i++) {
    if (await ev('document.readyState === "complete"')) { await dormir(380); return; }
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

const TUMETA  = `${BASE}/meta.php?marca=${marca}`;
const HOME    = `${BASE}/index.php?marca=${marca}`;
const LLEGADA = `${BASE}/meta.php?marca=${marca}&vista=preparando`;

try {
  await cmd('Page.addScriptToEvaluateOnNewDocument', { source: SONDA });
  await cmd('Network.setCookie', { name: 'PHPSESSID', value: sid, domain: 'localhost', path: '/' });

  // ── 1 · SEMANA · marcar la última tarea ──────────────────────
  await tam(360, 800);
  await ir(`${BASE}/meta.php?marca=${marca}&vista=semana&pos=${pos}`);
  di('SEMANA.HAY_TAREA', await ev(`!!document.querySelector('.sm-p.on [data-tarea-hecha]')`));
  await clic('.sm-p.on [data-tarea-hecha]');
  await dormir(2800);
  di('SEMANA.CIERRE', await ev(`JSON.stringify({
    esFin: !!(document.querySelector('.sm-p.on') && document.querySelector('.sm-p.on').dataset.fin),
    titulo: (document.getElementById('smFinT')||{}).textContent || ''
  })`));

  // ── 2 · TU META ──────────────────────────────────────────────
  await ir(TUMETA);
  di('TUMETA.TEXTO', await ev(TEXTO).then((t) => t.slice(0, 700)));
  di('TUMETA.MED_360', await ev(MEDIR('.ah')).then(JSON.stringify));
  await tirar('coh_tumeta_360');

  // ── 3 · INICIO ───────────────────────────────────────────────
  await ir(HOME);
  di('HOME.TEXTO', await ev(TEXTO).then((t) => t.slice(0, 700)));
  //  Se mide la TARJETA de la meta. Medir Home entero sacaba a relucir media
  //  docena de bloques que este arreglo no toca —el calendario semanal, la
  //  analitica, la idea del dia— y habria convertido esta prueba en una
  //  auditoria de Home disfrazada.
  di('HOME.MED_360', await ev(MEDIR('.norte')).then(JSON.stringify));
  await tirar('coh_home_360');

  // ── 4 · VOLVER Y RECARGAR ────────────────────────────────────
  await ir(TUMETA);
  await ir(TUMETA);
  di('TUMETA.RECARGA', await ev(TEXTO).then((t) => t.slice(0, 700)));

  // ── 5 · LA LLEGADA Y SU CAPA ─────────────────────────────────
  await ir(LLEGADA);
  di('LLEGADA.TEXTO', await ev(TEXTO).then((t) => t.slice(0, 500)));
  await ev('window.scrollTo(0, 80)');
  const scrollAntes = await ev('Math.round(scrollY)');
  await clic('#prExplicar');
  await dormir(420);
  di('HOJA', await ev(`JSON.stringify({
    visible: !!document.querySelector('.pr-velo.on'),
    focoDentro: !!(document.getElementById('prHoja') &&
                   document.getElementById('prHoja').contains(document.activeElement)),
    titulo: (document.getElementById('prHojaT')||{}).textContent || '',
    texto: (document.querySelector('.pr-hoja .cuerpo')||{}).innerText
             ? document.querySelector('.pr-hoja .cuerpo').innerText.replace(/\\s+/g,' ').trim().slice(0,900) : ''
  })`));
  await tecla('Escape', 'Escape', 27);
  await dormir(320);
  di('HOJA.CERRADA', await ev(`JSON.stringify({
    visible: !!document.querySelector('.pr-velo.on'),
    focoEnBoton: document.activeElement === document.getElementById('prExplicar'),
    scroll: Math.round(scrollY)
  })`));
  di('HOJA.SCROLL_ANTES', scrollAntes);

  // ── 6 · LA CAPA 2 · a donde lleva «Ver el plan completado» ───
  await ir(TUMETA + '&vista=plan');
  di('CAPA2.TEXTO', await ev(TEXTO).then((t) => t.slice(0, 700)));

  // ── 7 · LOS OTROS ANCHOS ─────────────────────────────────────
  for (const [n, w, h, e] of [['414', 414, 896, 2], ['1440', 1440, 900, 1]]) {
    await tam(w, h, e);
    await ir(TUMETA);
    di('TUMETA.MED_' + n, await ev(MEDIR('.ah')).then(JSON.stringify));
    await tirar('coh_tumeta_' + n);
    await ir(HOME);
    di('HOME.MED_' + n, await ev(MEDIR('.norte')).then(JSON.stringify));
    if (n === '1440') await tirar('coh_home_1440');
  }

  di('ALERTAS', await ev('window.__alertas || 0'));
  di('ERRORES', await ev('JSON.stringify(window.__errs || [])'));
  di('OK', 1);
} catch (e) {
  di('OK', 0); di('ERROR', e.message);
} finally {
  cerrar();
}
