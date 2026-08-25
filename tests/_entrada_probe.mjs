// ============================================================
//  CRECER — ¿SE VE LA PUERTA A LA REVISION SEMANAL?
//  tests/_entrada_probe.mjs
//
//  El HTML puede contener el enlace y el dueño no verlo nunca: en un telefono,
//  lo que esta a tres pantallas de scroll no existe. Aqui se mide DONDE cae la
//  accion de revisar la semana respecto al primer viewport, y si compite con
//  otra accion primaria.
//
//    node tests/_entrada_probe.mjs <sid> <marca> <carpeta>
// ============================================================

import { abrirChrome, dormir, cerrarRecibimiento } from './_chrome.mjs';
import fs from 'node:fs';

const [sid, marca, shots] = process.argv.slice(2);
const BASE = 'http://localhost/crecer/panel';
const di = (k, v) => console.log(k + '=' + String(v).replace(/\r?\n/g, ' '));

const SONDA = `
  window.__errs = [];
  addEventListener('error', function (e) { window.__errs.push(String(e.message)); });
  (function (o) { console.error = function () {
      window.__errs.push([].slice.call(arguments).join(' ')); o.apply(console, arguments); }; })(console.error);
`;

//  DONDE CAE LA PUERTA, y si se ve sin desplazar.
const MEDIR = `(function () {
  var vis = function (el) { var r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0 && getComputedStyle(el).visibility !== 'hidden'; };

  //  La puerta: cualquier enlace o boton que lleve a la revision semanal.
  var puerta = null;
  [].forEach.call(document.querySelectorAll('a[href*="vista=semana"], [data-ir-semana]'), function (a) {
    if (!puerta && vis(a)) puerta = a;
  });

  var out = { hay: !!puerta, w: innerWidth, h: innerHeight, horiz: 0, errs: (window.__errs||[]).length };
  out.horiz = Math.max(0, document.documentElement.scrollWidth - innerWidth);
  out.altoDoc = document.documentElement.scrollHeight;

  var bn = document.querySelector('.botnav');
  var techo = innerHeight - (bn && vis(bn) ? bn.getBoundingClientRect().height : 0);
  out.techo = Math.round(techo);

  if (puerta) {
    var r = puerta.getBoundingClientRect();
    out.top = Math.round(r.top + scrollY);
    out.alto = Math.round(r.height);
    out.ancho = Math.round(r.width);
    out.texto = (puerta.textContent || '').replace(/\\s+/g, ' ').trim().slice(0, 60);
    out.href = puerta.getAttribute('href') || '';
    //  SIN DESPLAZAR: con la pagina arriba del todo, ¿entra entera por encima
    //  de la barra de abajo?
    out.visibleSinScroll = (r.top + scrollY) >= 0 && (r.top + scrollY + r.height) <= techo;
    //  Y a cuantas pantallas de scroll esta.
    out.pantallas = +((r.top + scrollY) / Math.max(1, techo)).toFixed(2);
    //  Que nada la tape.
    var c = document.elementFromPoint(r.left + r.width / 2, Math.min(r.top + r.height / 2, techo - 2));
    out.tapada = !(c && (c === puerta || puerta.contains(c) || puerta.contains(c.parentNode)));
  }

  //  Cuantas acciones primarias compiten arriba.
  out.primarias = [].filter.call(document.querySelectorAll('.tm-btn, .ah-cta, .sm-bt.pri'), vis).length;

  //  AYUDA NO PUEDE SENTARSE ENCIMA. La fila .tm-semana se dejo FUERA de la
  //  lista blanca de _meta_zona.php a proposito: meterla ahi rompia la
  //  geometria que mide la suite de la presentacion (Ayuda se apartaba y no
  //  volvia). Asi que se comprueba el solape directamente: si algun dia se
  //  tocan, se vera aqui y se resolvera sin tocar la geometria compartida.
  //  (Ojo: nada de acentos graves en estos comentarios — van DENTRO de un
  //  template literal y lo cerrarian.)
  out.tapadaPorAyuda = false;
  var fab = document.querySelector('.ay-fab');
  if (puerta && fab && vis(fab) && getComputedStyle(fab).opacity !== '0') {
    var f = fab.getBoundingClientRect(), q = puerta.getBoundingClientRect();
    out.tapadaPorAyuda = !(q.right < f.left || q.left > f.right || q.bottom < f.top || q.top > f.bottom);
  }
  return out;
})()`;

const s = await abrirChrome({ sid, url: 'about:blank', ancho: 360, alto: 800 });
if (s.error) { di('OK', 0); di('ERROR', s.error); process.exit(1); }
const { ev, cmd, cerrar } = s;

const listo = async () => {
  for (let i = 0; i < 140; i++) {
    if (await ev('document.readyState === "complete"')) { await dormir(320); return; }
    await dormir(110);
  }
};
const ir = async (u) => { await cmd('Page.navigate', { url: u }); await listo(); };

async function tirar(nombre, w, h) {
  if (!shots) return;
  await cmd('Emulation.setDeviceMetricsOverride',
            { width: w, height: h, deviceScaleFactor: 2, mobile: w < 900 });
  await dormir(420);
  const png = await cmd('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
  fs.writeFileSync(`${shots}/${nombre}.png`, Buffer.from(png.data, 'base64'));
}

try {
  await cmd('Page.addScriptToEvaluateOnNewDocument', { source: SONDA });

  for (const [etq, w, h] of [['360', 360, 800], ['414', 414, 896], ['1440', 1440, 900]]) {
    await cmd('Emulation.setDeviceMetricsOverride',
              { width: w, height: h, deviceScaleFactor: 1, mobile: w < 900 });
    await ir(`${BASE}/meta.php?marca=${marca}`);
    await cerrarRecibimiento(ev);
    await ev('window.scrollTo(0,0)');
    await dormir(300);
    di('MED_' + etq, await ev(MEDIR).then(JSON.stringify));
    await tirar('entrada_' + etq, w, h);
  }

  //  Y QUE LLEVE DONDE DICE: se pulsa, no se lee el href.
  await cmd('Emulation.setDeviceMetricsOverride', { width: 360, height: 800, deviceScaleFactor: 1, mobile: true });
  await ir(`${BASE}/meta.php?marca=${marca}`);
  await cerrarRecibimiento(ev);
  const hay = await ev('!!document.querySelector(\'a[href*="vista=semana"]\')');
  di('PULSABLE', hay);
  if (hay) {
    await ev('document.querySelector(\'a[href*="vista=semana"]\').click()');
    await listo();
    di('LLEVA_A', await ev('location.href'));
    di('PASO', (await ev('(document.querySelector("#smPaso")||{}).textContent || ""')).trim());
  }

  di('ERRORES', await ev('JSON.stringify(window.__errs || [])'));
  di('OK', 1);
} catch (e) {
  di('OK', 0); di('ERROR', e.message);
} finally {
  cerrar();
}
