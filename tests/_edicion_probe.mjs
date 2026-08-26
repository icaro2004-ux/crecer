// ============================================================
//  CRECER — AJUSTAR TEXTO Y FECHA, CON EL DEDO (Fase 2A)
//  tests/_edicion_probe.mjs
//
//  Se pulsa «Ajustar», se escribe, se guarda y se comprueba que la publicación
//  quedó actualizada DELANTE del dueño y en su misma posición. Y lo contrario:
//  que cancelar no deja rastro y que Escape cierra sin escribir.
//
//    node tests/_edicion_probe.mjs <carpeta|-> <sid> <marca> <pos>
// ============================================================

import { abrirChrome, dormir, cerrarRecibimiento } from './_chrome.mjs';
import fs from 'node:fs';

const [shotsArg, sid, marca, pos] = process.argv.slice(2);
const shots = (shotsArg && shotsArg !== '-') ? shotsArg : '';
const BASE  = 'http://localhost/crecer/panel';
const di = (k, v) => console.log(k + '=' + String(v).replace(/\r?\n/g, ' '));

const SONDA = `
  window.__errs = []; window.__alertas = 0; window.__posts = 0;
  addEventListener('error', function (e) { window.__errs.push(String(e.message)); });
  (function (o) { console.error = function () {
      window.__errs.push([].slice.call(arguments).join(' ')); o.apply(console, arguments); }; })(console.error);
  window.alert = function () { window.__alertas++; };
  (function (f) { window.fetch = function (u, o) {
      if (o && String(o.method || '').toUpperCase() === 'POST') window.__posts++;
      return f.apply(this, arguments); }; })(window.fetch);
`;

const LEER = `(function () {
  var on = document.querySelector('.sm-p.on');
  if (!on) return JSON.stringify({ hay: false });
  var vis = function (el) { if (!el) return false; var r = el.getBoundingClientRect();
    var cs = getComputedStyle(el);
    return r.width > 0 && r.height > 0 && cs.display !== 'none' && cs.visibility !== 'hidden'; };
  var cap = on.querySelector('.sm-cap');
  var linea = on.querySelector('.sm-linea');
  return JSON.stringify({
    hay: true,
    n:       on.dataset.n,
    cuenta:  (document.getElementById('smPaso') || {}).textContent || '',
    caption: cap ? cap.textContent.trim() : '',
    linea:   linea ? linea.textContent.replace(/\\s+/g, ' ').trim() : '',
    fecha:   on.dataset.fecha || '',
    cuandoTx: on.dataset.cuandoTx || '',
    hoja:    vis(document.querySelector('.sm-velo.on')),
    posts:   window.__posts || 0
  });
})()`;

const HOJA = `(function () {
  var velo = document.querySelector('.sm-velo');
  var abierta = !!(velo && velo.classList.contains('on'));
  var hoja = document.querySelector('.sm-hoja');
  var vis = function (el) { if (!el) return false; var r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0; };
  return JSON.stringify({
    abierta: abierta,
    dialogo: velo ? velo.getAttribute('role') : '',
    modal:   velo ? velo.getAttribute('aria-modal') : '',
    titulo:  (document.getElementById('smHojaT') || {}).textContent || '',
    focoDentro: !!(hoja && hoja.contains(document.activeElement)),
    //  innerText NO trae lo que hay dentro de un <textarea> ni de un <input>:
    //  la hoja del texto parecia vacia mirandola asi. Se leen sus valores.
    texto:   hoja ? ((hoja.innerText || '') + ' ' +
               [].map.call(hoja.querySelectorAll('textarea, input'),
                 function (e) { return e.value || ''; }).join(' ')
             ).replace(/\\s+/g, ' ').trim().slice(0, 420) : '',
    filas:   [].map.call(document.querySelectorAll('.sm-hoja .sm-fila'),
               function (f) { return (f.textContent || '').replace(/\\s+/g, ' ').trim().slice(0, 40); }),
    horiz:   Math.max(0, document.documentElement.scrollWidth - innerWidth),
    chicos:  [].filter.call(document.querySelectorAll('.sm-hoja button, .sm-hoja a, .sm-hoja .sm-fila'),
               vis).filter(function (e) { var r = e.getBoundingClientRect();
                 return r.width < 44 || r.height < 44; })
               .map(function (e) { var r = e.getBoundingClientRect();
                 return (e.className || e.tagName) + ' ' + Math.round(r.width) + 'x' + Math.round(r.height); }),
    finos:   [].filter.call(document.querySelectorAll('.sm-hoja p, .sm-hoja b, .sm-hoja span, .sm-hoja button'),
               function (e) { if (!vis(e)) return false;
                 var propio = [].slice.call(e.childNodes).some(function (nn) {
                   return nn.nodeType === 3 && nn.textContent.trim().length > 1; });
                 return propio && parseFloat(getComputedStyle(e).fontSize) < 14; })
               .map(function (e) { return (e.textContent||'').trim().slice(0,20) + ' @' +
                 parseFloat(getComputedStyle(e).fontSize); }),
    primarias: [].filter.call(document.querySelectorAll('.sm-hoja .sm-bt.pri'), vis).length
  });
})()`;

const s = await abrirChrome({ sid, url: 'about:blank', ancho: 360, alto: 800 });
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
/** Escribe en un campo: se enfoca y se pone el valor con eventos reales. */
const escribir = (sel, val) => ev(`(function(){
  var e = document.querySelector('${sel}'); if (!e) return false;
  e.focus(); e.value = ${JSON.stringify(val)};
  e.dispatchEvent(new Event('input', {bubbles:true}));
  e.dispatchEvent(new Event('change', {bubbles:true}));
  return true;
})()`);
const tecla = (key, code, keyCode) => cmd('Input.dispatchKeyEvent',
  { type: 'keyDown', key, code, windowsVirtualKeyCode: keyCode }).then(() =>
  cmd('Input.dispatchKeyEvent', { type: 'keyUp', key, code, windowsVirtualKeyCode: keyCode }));

const SEMANA = `${BASE}/meta.php?marca=${marca}&vista=semana&pos=${pos}`;
const NUEVO_TX = '[prueba] Texto escrito por el dueño con su dedo.';

try {
  await cmd('Page.addScriptToEvaluateOnNewDocument', { source: SONDA });

  // ── 1 · EL MENÚ DE AJUSTAR ───────────────────────────────────
  await tam(360, 800);
  await ir(SEMANA);
  di('INICIO', await ev(LEER));
  await clic('.sm-p.on [data-ajustar]');
  await dormir(420);
  di('MENU', await ev(HOJA));
  await tirar('edi_menu_360');

  // ── 2 · CANCELAR CON ESCAPE NO ESCRIBE ───────────────────────
  const postsAntes = await ev('window.__posts || 0');
  await tecla('Escape', 'Escape', 27);
  await dormir(300);
  di('TRAS_ESCAPE', await ev(`JSON.stringify({
    abierta: !!document.querySelector('.sm-velo.on'),
    posts: window.__posts || 0, antes: ${postsAntes}
  })`));

  // ── 3 · EL TEXTO ─────────────────────────────────────────────
  await clic('.sm-p.on [data-ajustar]');
  await dormir(380);
  await clic('.sm-hoja .sm-fila[data-a="texto"]');
  await dormir(380);
  di('TEXTO.HOJA', await ev(HOJA));
  await tirar('edi_texto_360');

  //  Cancelar: ni un POST, ni un cambio.
  const p0 = await ev('window.__posts || 0');
  await escribir('#smTx', '[prueba] ESTO NO SE DEBE GUARDAR');
  await clic('#smTxC');
  await dormir(320);
  di('TEXTO.CANCELADO', await ev(`JSON.stringify({
    abierta: !!document.querySelector('.sm-velo.on'),
    posts: window.__posts || 0, antes: ${p0},
    caption: (document.querySelector('.sm-p.on .sm-cap')||{}).textContent || ''
  })`));

  //  Guardar de verdad.
  await clic('.sm-p.on [data-ajustar]');
  await dormir(360);
  await clic('.sm-hoja .sm-fila[data-a="texto"]');
  await dormir(360);
  await escribir('#smTx', NUEVO_TX);
  await clic('#smTxG');
  await dormir(2200);
  di('TEXTO.GUARDADO', await ev(LEER));
  await tirar('edi_texto_guardado_360');

  //  Y al recargar sigue ahí, en la misma posición.
  await ir(SEMANA);
  di('TEXTO.TRAS_RECARGA', await ev(LEER));

  // ── 4 · LA FECHA ─────────────────────────────────────────────
  await clic('.sm-p.on [data-ajustar]');
  await dormir(360);
  await clic('.sm-hoja .sm-fila[data-a="fecha"]');
  await dormir(380);
  di('FECHA.HOJA', await ev(HOJA));
  await tirar('edi_fecha_360');

  //  Una fecha que ya pasó: el servidor la rechaza y se dice DENTRO de la capa.
  await escribir('#smFe', '2020-01-05T10:00');
  await clic('#smFeG');
  await dormir(1800);
  di('FECHA.PASADA', await ev(`JSON.stringify({
    abierta: !!document.querySelector('.sm-velo.on'),
    err: (function(){ var e = document.querySelector('#smFeE');
            return e && e.classList.contains('on') ? (e.textContent||'').trim() : ''; })(),
    fecha: (document.querySelector('.sm-p.on')||{dataset:{}}).dataset.fecha || ''
  })`));
  await tirar('edi_fecha_error_360');

  //  Y una buena.
  const d = new Date(Date.now() + 6 * 864e5);
  const iso = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' +
              String(d.getDate()).padStart(2, '0') + 'T10:00';
  await escribir('#smFe', iso);
  await clic('#smFeG');
  await dormir(2200);
  di('FECHA.GUARDADA', await ev(LEER));
  di('FECHA.ESPERADA', iso);

  await ir(SEMANA);
  di('FECHA.TRAS_RECARGA', await ev(LEER));

  // ── 5 · LOS OTROS ANCHOS ─────────────────────────────────────
  for (const [n, w, h, e] of [['414', 414, 896, 2], ['1440', 1440, 900, 1]]) {
    await tam(w, h, e);
    await ir(SEMANA);
    await clic('.sm-p.on [data-ajustar]');
    await dormir(420);
    di('MENU.MED_' + n, await ev(HOJA));
    if (n === '1440') await tirar('edi_menu_1440');
    await tecla('Escape', 'Escape', 27);
    await dormir(220);
  }

  di('ALERTAS', await ev('window.__alertas || 0'));
  di('ERRORES', await ev('JSON.stringify(window.__errs || [])'));
  di('OK', 1);
} catch (e) {
  di('OK', 0); di('ERROR', e.message);
} finally {
  cerrar();
}
