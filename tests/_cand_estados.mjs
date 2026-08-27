// ============================================================
//  CRECER — LOS ESTADOS DE «OTRA IMAGEN», UNO POR UNO
//  tests/_cand_estados.mjs
//
//  POR QUE UNA SONDA APARTE. Cada estado —elegir, preparando, comparar, fallo,
//  cuota agotada— nace de datos DISTINTOS en la base. No se pueden ver los cinco
//  en un mismo recorrido sin tocar la base a mitad, y tocarla a mitad desde el
//  navegador seria fabricar la pantalla en vez de encontrarla.
//
//  Asi que la suite siembra UN estado, lanza esta sonda, y la sonda abre la
//  pantalla que ese estado produce. Eso es lo que demuestra que recargar
//  reconstruye la pantalla desde lo persistido: aqui no hay nada guardado en el
//  navegador — se entra de cero cada vez.
//
//    node tests/_cand_estados.mjs <sid> <marca> <shots> <estado> <pos>
//
//  estados: eleccion | preparando | comparacion | fallo | cuota
//
//  Imprime lineas CLAVE=valor.
// ============================================================

import fs from 'node:fs';
import { abrirChrome, dormir, cerrarRecibimiento } from './_chrome.mjs';

//  LOS ERRORES DE CONSOLA SE RECOGEN DESDE ANTES DE QUE LA PAGINA CORRA.
//  Engancharse despues de cargar solo ve los que llegan tarde, que son justo
//  los que no importan.
const SONDA = `
  window.__errs = [];
  addEventListener('error', function (e) { window.__errs.push(String(e.message)); });
  addEventListener('unhandledrejection', function (e) { window.__errs.push('promesa: ' + e.reason); });
  (function (o) { console.error = function () {
      window.__errs.push([].slice.call(arguments).join(' ')); o.apply(console, arguments); }; })(console.error);
`;

const [sid, marcaRaw, SHOTS, estado, piezaRaw] = process.argv.slice(2);
//  SE BUSCA LA TARJETA POR SU ID, no por la posicion. La posicion depende del
//  orden de las jugadas del plan y cambia con la fixture; el id de la pieza no.
//  Atarse a `pos` hacia que la sonda abriera la publicacion equivocada y las
//  afirmaciones culparan a la pantalla.
const marca = Number(marcaRaw);
const pieza = Number(piezaRaw || 0);
const BASE = 'http://localhost/crecer/panel';
const SEM = `${BASE}/meta.php?marca=${marca}&vista=semana`;

const di = (k, v) => console.log(k + '=' + String(v).replace(/\n/g, ' '));

//  LA MISMA REGLA DE MEDIDA QUE EL RESTO: cuando hay una capa abierta, la capa
//  ES la pantalla. Medir lo que quedo detras del velo da «esta tapado», y claro
//  que lo esta: eso es lo que hace un velo.
const MEDIR = `(function () {
  var vis = function (el) { var r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0 && getComputedStyle(el).visibility !== 'hidden'; };
  var caja = document.querySelector('.sm, .wz');
  var out = { w: innerWidth, horiz: 0, fuera: [], chicos: [], finos: [],
              primarias: 0, primVisible: null, primTapada: null, emo: [], hay: !!caja };
  if (!caja) return out;
  out.horiz = Math.max(0, document.documentElement.scrollWidth - innerWidth);
  var velo = document.querySelector('.sm-velo.on');
  var zona = velo || caja;
  [].forEach.call(zona.querySelectorAll('*'), function (el) {
    if (!vis(el)) return;
    var r = el.getBoundingClientRect();
    if (r.right > innerWidth + 1 || r.left < -1) out.fuera.push(el.className || el.tagName);
  });
  [].forEach.call(zona.querySelectorAll('button, a, input, textarea, select'), function (el) {
    if (!vis(el)) return;
    var r = el.getBoundingClientRect();
    if (r.width < 44 || r.height < 44)
      out.chicos.push((el.className || el.tagName) + ' ' + Math.round(r.width) + 'x' + Math.round(r.height));
  });
  [].forEach.call(zona.querySelectorAll('p, b, span, button, a, h1, h2, h3, li, small, label'), function (el) {
    if (!vis(el)) return;
    var t = (el.textContent || '').trim();
    if (t.length < 2) return;
    var propio = [].slice.call(el.childNodes)
      .some(function (n) { return n.nodeType === 3 && n.textContent.trim().length > 1; });
    if (!propio) return;
    var fs = parseFloat(getComputedStyle(el).fontSize);
    if (fs < 14) out.finos.push(t.slice(0, 26) + ' @' + fs);
  });
  //  UNA SOLA DECISION PRINCIPAL POR MOMENTO.
  var pri = zona.querySelectorAll('.sm-bt.pri');
  out.primarias = pri.length;
  if (pri.length) {
    var r = pri[0].getBoundingClientRect();
    var bn = document.querySelector('.botnav');
    var techo = innerHeight - (bn && vis(bn) ? bn.getBoundingClientRect().height : 0);
    out.primVisible = r.top >= 0 && r.bottom <= techo + 1;
    var c = document.elementFromPoint(r.left + r.width / 2, Math.min(r.top + r.height / 2, techo - 2));
    out.primTapada = !(c && (c === pri[0] || pri[0].contains(c)));
  }
  //  CERO EMOJI: la estetica va en SVG, nunca en caracteres.
  var tx = zona.textContent || '';
  var em = tx.match(/[\\u{1F300}-\\u{1FAFF}\\u{2600}-\\u{27BF}]/gu);
  if (em) out.emo = em.slice(0, 6);
  //  Y AYUDA NO SE SIENTA ENCIMA DE NADA.
  var fab = document.querySelector('.ay-fab');
  if (fab && vis(fab) && pri.length) {
    var a = fab.getBoundingClientRect(), b = pri[0].getBoundingClientRect();
    out.ayudaTapa = !(a.right < b.left || a.left > b.right || a.bottom < b.top || a.top > b.bottom);
  } else { out.ayudaTapa = false; }
  return out;
})()`;

let s;
try {
  s = await abrirChrome({ sid, url: 'about:blank', ancho: 360, alto: 800 });
} catch (e) {
  di('ERROR', 'no pude abrir Chrome: ' + e.message); di('OK', 0); process.exit(0);
}
const { ev, cmd, cerrar } = s;

//  LA CONSOLA TIENE QUE QUEDAR LIMPIA: un error de JavaScript en la pantalla
//  del dueño es una pantalla que a lo mejor no hace lo que dice.
await cmd('Page.addScriptToEvaluateOnNewDocument', { source: SONDA });
const errs = () => ev('JSON.stringify(window.__errs || [])');

const ir = async (u) => { await cmd('Page.navigate', { url: u }); await listo(); };
const listo = async () => {
  for (let i = 0; i < 60; i++) {
    if (await ev('document.readyState === "complete"')) { await dormir(320); return; }
    await dormir(110);
  }
};
const txt = (sel) => ev(`(document.querySelector(${JSON.stringify(sel)})||{}).textContent || ''`);
const clicSel = async (sel) => {
  await ev(`(function(){var e=document.querySelector(${JSON.stringify(sel)}); if(e) e.click();})()`);
  await dormir(420);
};
const tirar = async (nombre, w, h) => {
  if (!SHOTS) return;
  try {
    const png = await cmd('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
    fs.writeFileSync(`${SHOTS}/${nombre}.png`, Buffer.from(png.data, 'base64'));
  } catch (e) { /* una captura que no sale no invalida la medida */ }
};

try {
  await ir(SEM);
  await cerrarRecibimiento(ev);
  di('LLEGO_A_SEMANA', await ev('/vista=semana/.test(location.search)'));

  //  Se abre la tarjeta de ESA pieza, por id. La vista marca `.on` la que toca
  //  y aqui se le pide que sea esta.
  di('TARJETA_EXISTE', await ev(`!!document.querySelector('.sm-p[data-id="${pieza}"]')`));
  await ev(`(function(){
    var t = document.querySelector('.sm-p[data-id="${pieza}"]');
    if (!t) return;
    [].forEach.call(document.querySelectorAll('.sm-p.on'), function(o){ o.classList.remove('on'); });
    t.classList.add('on');
  })()`);
  await dormir(250);
  di('POS_ABIERTA', (await txt('#smPaso')).trim());
  di('TIENE_AJUSTAR', await ev(`!!document.querySelector('.sm-p[data-id="${pieza}"] [data-ajustar]')`));

  //  Se abre la hoja de material, que es de donde cuelga todo esto.
  await clicSel(`.sm-p[data-id="${pieza}"] [data-ajustar]`);
  await clicSel('#smHojaC .sm-fila[data-a="arte"]');
  await dormir(250);

  //  Y LA FILA QUE TOCA SEGUN EL ESTADO SEMBRADO. Que sea distinta en cada caso
  //  es la prueba de que la pantalla sale de los datos y no de un parametro.
  const cual = estado === 'cuota'  ? 'sincuota'
             : estado === 'fallo'  ? 'fallo'
             : estado === 'eleccion' ? 'otra'
             : 'cand';
  di('FILA_ESPERADA', cual);
  di('FILA_EXISTE', await ev(
    `!!document.querySelector('#smHojaC .sm-fila[data-m="${cual}"]')`));
  await clicSel(`#smHojaC .sm-fila[data-m="${cual}"]`);
  await dormir(500);

  di('TITULO', (await txt('#smHojaT')).trim());
  di('CUERPO', (await ev('(document.getElementById("smHojaC")||{}).textContent || ""'))
                 .replace(/\s+/g, ' ').trim().slice(0, 260));
  di('PRIMARIAS', await ev('document.querySelectorAll("#smHojaC .sm-bt.pri").length'));
  di('SIGO_EN_SEMANA', await ev('/vista=semana/.test(location.search)'));
  di('IMAGEN_ACTUAL_VISIBLE', await ev(
    '!!document.querySelector(".sm-p.on .sm-media img, #smHojaC .sm-prev img, #smHojaC .sm-comp img")'));

  //  LAS TRES ANCHURAS. La hoja ES la pantalla mientras esta abierta.
  for (const [w, h, etq] of [[360, 800, '360'], [414, 896, '414'], [1440, 900, '1440']]) {
    await cmd('Emulation.setDeviceMetricsOverride',
              { width: w, height: h, deviceScaleFactor: 1, mobile: w < 900 });
    await dormir(450);
    di('MED_' + etq, JSON.stringify(await ev(MEDIR)));
    await tirar(`2c_${estado}_${etq}`, w, h);
  }

  //  ESCAPE NO ESCRIBE NADA: cierra y punto.
  await cmd('Emulation.setDeviceMetricsOverride',
            { width: 360, height: 800, deviceScaleFactor: 1, mobile: true });
  await dormir(250);
  await ev(`document.dispatchEvent(new KeyboardEvent('keydown', {key:'Escape'}))`);
  await dormir(350);
  di('ESCAPE_CIERRA', await ev(
    '!document.querySelector("#smVelo").classList.contains("on")'));
  di('ESCAPE_SIGO_EN_POS', (await txt('#smPaso')).trim());

  di('CONSOLA', await errs());
  di('ALERTS', await ev('String(window.alert).indexOf("[native code]") >= 0 ? "nativo" : "reemplazado"'));
  di('OK', 1);
} catch (e) {
  di('ERROR', e.message);
  di('OK', 0);
} finally {
  try { await cerrar(); } catch (e) { /* ya estaba cerrado */ }
}
