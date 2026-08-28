// ============================================================
//  CRECER — VER LA IMAGEN COMPLETA, ENCIMA DE TODO
//  tests/_visor.mjs
//
//  EL DEFECTO QUE CIERRA. El dueño tenia que decidir —aprobar, publicar,
//  quedarse con la suya o usar la nueva— viendo media imagen: el menu, Ayuda y
//  los propios botones quedaban delante. Se le pedia un juicio sobre algo que no
//  podia ver entero.
//
//  Lo que se comprueba no es que exista un visor, sino que sirve: que esta POR
//  ENCIMA de todos los fijos, que la imagen CABE entera, que se sale por donde
//  uno espera salir, y que al volver la decision sigue ahi.
//
//    node tests/_visor.mjs <sid> <marca> <pieza> <shots>
// ============================================================

import fs from 'node:fs';
import { abrirChrome, dormir, cerrarRecibimiento } from './_chrome.mjs';

const [sid, marcaRaw, piezaRaw, SHOTS] = process.argv.slice(2);
const marca = Number(marcaRaw);
const pieza = Number(piezaRaw);
const BASE = 'http://localhost/crecer/panel';
const SEM = `${BASE}/meta.php?marca=${marca}&vista=semana`;
const di = (k, v) => console.log(k + '=' + String(v).replace(/\r?\n/g, ' '));

const SONDA = `
  window.__errs = [];
  addEventListener('error', function (e) { window.__errs.push(String(e.message)); });
  addEventListener('unhandledrejection', function (e) { window.__errs.push('promesa: ' + e.reason); });
  (function (o) { console.error = function () {
      window.__errs.push([].slice.call(arguments).join(' ')); o.apply(console, arguments); }; })(console.error);
`;

//  LA MEDIDA DEL VISOR. Tres preguntas, y las tres son la misma: ¿puede ver la
//  imagen entera y sin nada delante?
const MIRAR = `(function () {
  var lb = document.getElementById('lightbox');
  var out = { abierto: false };
  if (!lb || !lb.classList.contains('show')) return out;
  out.abierto = true;
  var im = lb.querySelector('img');
  var r  = im.getBoundingClientRect();
  out.src = im.getAttribute('src') || '';
  //  1 · CABE ENTERA. Nada de la imagen puede quedar fuera del viewport.
  out.cabe = r.top >= -1 && r.left >= -1 &&
             r.bottom <= innerHeight + 1 && r.right <= innerWidth + 1 &&
             r.width > 1 && r.height > 1;
  out.rect = [Math.round(r.left), Math.round(r.top), Math.round(r.width), Math.round(r.height)];
  out.vp   = [innerWidth, innerHeight];
  //  Y NO SE RECORTA: el alto natural cabe en el que se le da.
  out.contain = getComputedStyle(im).objectFit;
  out.natural = im.naturalWidth > 0
    ? Math.abs((r.width / r.height) - (im.naturalWidth / im.naturalHeight)) < 0.08 : null;

  //  2 · ESTA ENCIMA DE TODO. Se pregunta al navegador quien hay en varios
  //  puntos: el centro de la imagen y las cuatro esquinas del viewport, que es
  //  donde viven el menu, Ayuda y la barra de abajo.
  var puntos = [
    [innerWidth / 2, innerHeight / 2],
    [12, 12], [innerWidth - 12, 12],
    [12, innerHeight - 12], [innerWidth - 12, innerHeight - 12],
    [innerWidth / 2, innerHeight - 8]
  ];
  out.intrusos = [];
  puntos.forEach(function (p) {
    var el = document.elementFromPoint(p[0], p[1]);
    if (!el) return;
    //  Vale el propio visor, su X o su imagen. Cualquier otra cosa es algo que
    //  se quedo delante.
    if (el === lb || lb.contains(el)) return;
    out.intrusos.push((el.className || el.tagName) + ' @' + Math.round(p[0]) + ',' + Math.round(p[1]));
  });

  //  3 · LAS ACCIONES NO SE VEN. Aprobar, publicar, usar o conservar no pueden
  //  estar delante mientras se mira: se decide despues, no encima.
  var vis = function (el) { var q = el.getBoundingClientRect();
    return q.width > 0 && q.height > 0 && getComputedStyle(el).visibility !== 'hidden'; };
  out.accionesEncima = [];
  [].forEach.call(document.querySelectorAll('.sm-bt.pri, .sm-bt.sec, .botnav a, .ay-fab'), function (b) {
    if (!vis(b)) return;
    var q = b.getBoundingClientRect();
    var cx = q.left + q.width / 2, cy = q.top + q.height / 2;
    if (cx < 0 || cy < 0 || cx > innerWidth || cy > innerHeight) return;
    var top = document.elementFromPoint(cx, cy);
    if (top && (top === b || b.contains(top))) out.accionesEncima.push(b.className || b.tagName);
  });

  //  4 · LA X SE PUEDE TOCAR.
  var x = document.getElementById('lightboxX');
  if (x) { var q = x.getBoundingClientRect(); out.x = [Math.round(q.width), Math.round(q.height)]; }
  out.horiz = Math.max(0, document.documentElement.scrollWidth - innerWidth);
  return out;
})()`;

let s;
try { s = await abrirChrome({ sid, url: 'about:blank', ancho: 360, alto: 800 }); }
catch (e) { di('ERROR', 'Chrome: ' + e.message); di('OK', 0); process.exit(0); }
const { ev, cmd, cerrar } = s;

const listo = async () => {
  for (let i = 0; i < 60; i++) {
    if (await ev('document.readyState === "complete"')) { await dormir(320); return; }
    await dormir(110);
  }
};
const ir = async (u) => { await cmd('Page.navigate', { url: u }); await listo(); };
const txt = (sel) => ev(`(document.querySelector(${JSON.stringify(sel)})||{}).textContent || ''`);
const clic = async (sel) => {
  await ev(`(function(){var e=document.querySelector(${JSON.stringify(sel)}); if(e) e.click();})()`);
  await dormir(420);
};
const tirar = async (nombre) => {
  if (!SHOTS) return;
  try {
    const png = await cmd('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
    fs.writeFileSync(`${SHOTS}/${nombre}.png`, Buffer.from(png.data, 'base64'));
  } catch (e) { /* la captura no invalida la medida */ }
};
const anchura = async (w, h) => {
  await cmd('Emulation.setDeviceMetricsOverride',
            { width: w, height: h, deviceScaleFactor: 1, mobile: w < 900 });
  await dormir(400);
};

try {
  await cmd('Page.addScriptToEvaluateOnNewDocument', { source: SONDA });
  await ir(SEM);
  await cerrarRecibimiento(ev);

  //  Se abre LA TARJETA de esa pieza, por id.
  await ev(`(function(){
    var t = document.querySelector('.sm-p[data-id="${pieza}"]');
    if (!t) return;
    [].forEach.call(document.querySelectorAll('.sm-p.on'), function(o){ o.classList.remove('on'); });
    t.classList.add('on');
  })()`);
  await dormir(250);
  di('POS_ANTES', (await txt('#smPaso')).trim());

  // ══ 1 · LA IMAGEN DE LA PUBLICACION, ANTES DE APROBARLA ══════════
  di('TARJETA_SE_TOCA', await ev(`!!document.querySelector('.sm-p[data-id="${pieza}"] .sm-media[data-zoom]')`));
  di('TARJETA_LO_DICE', await ev(
    `(document.querySelector('.sm-p[data-id="${pieza}"] .sm-media[data-zoom]')||{}).getAttribute
      ? document.querySelector('.sm-p[data-id="${pieza}"] .sm-media[data-zoom]').getAttribute('aria-label') : ''`));
  di('TARJETA_TIENE_PISTA', await ev(`!!document.querySelector('.sm-p[data-id="${pieza}"] .zoom-hint')`));

  for (const [w, h, etq] of [[360, 800, '360'], [414, 896, '414'], [1440, 900, '1440']]) {
    await anchura(w, h);
    await clic(`.sm-p[data-id="${pieza}"] .sm-media[data-zoom]`);
    di('ACTUAL_' + etq, JSON.stringify(await ev(MIRAR)));
    if (etq === '360') await tirar('visor_actual_360');
    //  Se cierra tocando el fondo.
    await ev(`(function(){var lb=document.getElementById('lightbox');
      if(lb) lb.dispatchEvent(new MouseEvent('click',{bubbles:true}));})()`);
    await dormir(320);
    di('ACTUAL_CIERRA_' + etq, await ev(
      '!document.getElementById("lightbox").classList.contains("show")'));
  }
  await anchura(360, 800);
  di('POS_TRAS_ACTUAL', (await txt('#smPaso')).trim());
  di('SIGO_EN_SEMANA', await ev('/vista=semana/.test(location.search)'));

  // ══ 2 · LA CANDIDATA, DESDE LA COMPARACION ══════════════════════
  await clic(`.sm-p[data-id="${pieza}"] [data-ajustar]`);
  await clic('#smHojaC .sm-fila[data-a="arte"]');
  await dormir(250);
  await clic('#smHojaC .sm-fila[data-m="cand"]');
  await dormir(600);
  di('COMPARACION_ABRE', (await txt('#smHojaT')).trim());
  di('COMP_DOS_SE_TOCAN', await ev('document.querySelectorAll("#smHojaC .sm-comp figure[data-zoom]").length'));

  //  La NUEVA, encima de la hoja abierta — que es el caso que de verdad fallaba.
  await clic('#smHojaC .sm-comp figure.nueva, #smHojaC .sm-comp figure:last-of-type');
  di('NUEVA_360', JSON.stringify(await ev(MIRAR)));
  await tirar('visor_candidata_360');
  //  Y se sale con Escape.
  await ev(`document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}))`);
  await dormir(400);
  di('NUEVA_CIERRA_ESC', await ev(
    '!document.getElementById("lightbox").classList.contains("show")'));

  //  LA DECISION SIGUE AHI. Es lo que hace que cerrar el visor no sea perderlo.
  di('SIGUE_LA_COMPARACION', await ev(
    '(document.getElementById("smHojaT")||{}).textContent || ""'));
  di('SIGUE_USAR_NUEVA', await ev('!!document.getElementById("smCmU")'));
  di('SIGUE_QUEDARME', await ev('!!document.getElementById("smCmQ")'));
  di('POS_FINAL', (await txt('#smPaso')).trim());

  //  Y la actual, tambien desde dentro de la hoja.
  await clic('#smHojaC .sm-comp figure:first-of-type');
  di('ACTUAL_DESDE_HOJA', JSON.stringify(await ev(MIRAR)));
  await clic('#lightboxX');
  di('CIERRA_CON_X', await ev(
    '!document.getElementById("lightbox").classList.contains("show")'));
  di('Y_SIGUE_LA_DECISION', await ev('!!document.getElementById("smCmU")'));

  // ══ 3 · LA BARRA DE ABAJO ═══════════════════════════════════════
  await ir(SEM);
  await cerrarRecibimiento(ev);
  for (const [w, h, etq] of [[360, 800, '360'], [414, 896, '414']]) {
    await anchura(w, h);
    di('NAV_' + etq, JSON.stringify(await ev(`(function(){
      var bn = document.querySelector('.botnav');
      if (!bn) return { hay: false };
      var vis = function (el) { var r = el.getBoundingClientRect();
        return r.width > 0 && r.height > 0; };
      var a = [].filter.call(bn.querySelectorAll('a'), vis);
      var chicos = a.filter(function (x) { var r = x.getBoundingClientRect();
        return r.width < 44 || r.height < 44; })
        .map(function (x) { var r = x.getBoundingClientRect();
          return (x.textContent||'').trim() + ' ' + Math.round(r.width) + 'x' + Math.round(r.height); });
      //  ¿Se solapan entre ellos? EN ORDEN VISUAL, no en el del DOM. El dock
      //  centra el activo con «order» de CSS —el DOM se queda quieto para el
      //  tabulador y el lector de pantalla—, asi que comparar vecinos del DOM
      //  daba solapes que no existen: los dos que se comparaban estaban en
      //  extremos opuestos de la barra.
      var orden = a.slice().sort(function (x, y) {
        return x.getBoundingClientRect().left - y.getBoundingClientRect().left; });
      var solapa = [];
      for (var i = 0; i < orden.length - 1; i++) {
        var p = orden[i].getBoundingClientRect(), q = orden[i+1].getBoundingClientRect();
        if (p.right > q.left + 1) solapa.push((orden[i].textContent||'').trim());
      }
      return {
        hay: true,
        etiquetas: a.map(function (x) { return (x.textContent||'').trim(); }),
        //  EL ACTIVO SE MARCA CON «aria-current», que es lo que tambien oye
        //  un lector de pantalla. La clase «on» es de la barra vieja.
        activa: a.filter(function (x) { return x.getAttribute('aria-current') === 'page'
                                            || x.classList.contains('act'); })
                 .map(function (x) { return (x.textContent||'').trim(); }),
        chicos: chicos, solapa: solapa,
        horiz: Math.max(0, document.documentElement.scrollWidth - innerWidth),
        //  Y en el cajon lateral no puede repetirse Tu Meta.
        meta_en_cajon: [].filter.call(document.querySelectorAll('.side nav a'), function (x) {
          return /Tu Meta/i.test(x.textContent || '') && getComputedStyle(x).display !== 'none';
        }).length,
        //  Y CREAR SIGUE EN EL CAJON. Se busca por lo que hace —el enlace que
        //  abre el wizard— y no por una clase: la Fase 6 reagrupo el menu en
        //  cinco conceptos y «.side-crear» dejo de existir, con lo que esta
        //  vigilancia llevaba desde entonces diciendo que Crear no estaba.
        crear_en_cajon: [].some.call(document.querySelectorAll('.side a'), function (x) {
          return /crear=1/.test(x.getAttribute('href') || '')
              || /^crear$/i.test((x.textContent || '').trim()); })
      };
    })()`)));
  }

  di('CONSOLA', await ev('JSON.stringify(window.__errs || [])'));
  di('OK', 1);
} catch (e) {
  di('ERROR', e.message); di('OK', 0);
} finally {
  try { await cerrar(); } catch (e) { /* ya estaba */ }
}
