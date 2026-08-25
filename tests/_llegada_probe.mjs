// ============================================================
//  CRECER — LA LLEGADA, EN UN NAVEGADOR (TRAMO 2C)
//  tests/_llegada_probe.mjs
//
//  Lo que aquí se mira no se puede mirar en PHP: que el dueño VEA la acción sin
//  bajar, que el botón de verdad lo lleve a la publicación que le toca —no que
//  el href lo prometa—, que la explicación abra y cierre devolviéndolo al mismo
//  sitio, y que con el teclado se pueda hacer todo eso.
//
//    node tests/_llegada_probe.mjs <carpeta|-> etq:sid:marca [etq:sid:marca ...]
//
//  Un solo Chrome para todos los estados: la cookie de sesión se cambia entre
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

//  LA MEDIDA. En el navegador, porque es la única forma honesta de saber si
//  algo se ve: el HTML no sabe de viewport, de barras fijas ni del botón
//  flotante de Ayuda.
const MEDIR = `(function () {
  var vis = function (el) { if (!el) return false; var r = el.getBoundingClientRect();
    var cs = getComputedStyle(el);
    return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none'; };
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
  [].forEach.call(caja.querySelectorAll('p, b, i, span, button, a, h1, div'), function (el) {
    if (!vis(el)) return;
    var propio = [].slice.call(el.childNodes)
      .some(function (n) { return n.nodeType === 3 && n.textContent.trim().length > 1; });
    if (!propio) return;
    var fz = parseFloat(getComputedStyle(el).fontSize);
    if (fz < 14) out.finos.push((el.textContent || '').trim().slice(0, 24) + ' @' + fz);
  });

  //  UNA SOLA ACCIÓN PRINCIPAL, y visible sin bajar.
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
  //  LO QUE TIENE QUE VERSE SIN BAJAR, según el contrato de 2C.
  var enPantalla = function (sel) {
    var el = caja.querySelector(sel);
    if (!vis(el)) return null;
    var r = el.getBoundingClientRect();
    return r.top >= 0 && r.bottom <= techo;
  };
  out.sinBajar = {
    titulo:  enPantalla('#prQ'),
    meta:    enPantalla('#prMeta'),
    semana:  enPantalla('#prSemana'),
    explica: enPantalla('#prExplicar'),
    salida:  enPantalla('#prVolver')
  };
  return out;
})()`;

const LEER = `(function () {
  var caja = document.querySelector('.pr');
  if (!caja) return JSON.stringify({ hay: false });
  var vis = function (el) { if (!el) return false; var r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0; };
  var el = function (id) { return document.getElementById(id); };
  var tx = function (id) { var e = el(id); return e ? (e.textContent || '').trim() : ''; };
  return JSON.stringify({
    hay: true,
    estado:  caja.getAttribute('data-estado') || '',
    titulo:  tx('prQ'),
    meta:    vis(el('prMeta'))   ? tx('prMeta')   : '',
    semana:  vis(el('prSemana')) ? tx('prSemana') : '',
    resumen: vis(el('prRes')),
    pasos:   vis(el('prPasos')),
    ir:      vis(el('prIr')),
    irTx:    tx('prIrTx'),
    irHref:  el('prIr') ? el('prIr').getAttribute('href') : '',
    ver:     vis(el('prVer')),
    explica: vis(el('prExplicar')),
    reintentar: vis(el('prReintentar')),
    volver:  vis(el('prVolver')),
    texto:   (caja.innerText || '').replace(/\\s+/g, ' ').trim().slice(0, 520)
  });
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
/** Un clic de verdad, en el centro del elemento y con el ratón. */
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
    await tirar('lleg_' + etq + '_360');

    //  RECARGAR NO CAMBIA LA HISTORIA: el estado vive en la base.
    await ir(URL);
    di(etq + '.RECARGA', await ev(LEER));

    for (const [n, w, h, e] of [['414', 414, 896, 2], ['1440', 1440, 900, 1]]) {
      await tam(w, h, e);
      await ir(URL);
      di(etq + '.MED_' + n, await ev(MEDIR).then(JSON.stringify));
      //  De 414 solo interesa el resumen listo: los demás estados ya se ven a
      //  360 y a 1440, y una captura por estado y ancho no se mira nunca.
      if (n === '1440' || etq === 'listo') await tirar('lleg_' + etq + '_' + n);
    }
  }

  // ══════════════════════════════════════════════════════════════
  //  LA HOJA · abre, atrapa el foco, cierra con Escape y devuelve
  // ══════════════════════════════════════════════════════════════
  const [etq0, sid0, marca0] = (estados[0] || '').split(':');
  if (etq0) {
    const URL0 = `${BASE}/meta.php?marca=${marca0}&vista=preparando`;
    await cmd('Network.setCookie',
      { name: 'PHPSESSID', value: sid0, domain: 'localhost', path: '/' });
    await tam(360, 800);
    await ir(URL0);

    //  Se baja la página a propósito: al cerrar tiene que volver AQUÍ.
    await ev('window.scrollTo(0, 120)');
    const scrollAntes = await ev('Math.round(scrollY)');

    await clic('#prExplicar');
    await dormir(420);
    di('HOJA.ABIERTA', await ev(`JSON.stringify({
      visible: !!document.querySelector('.pr-velo.on'),
      dialogo: (document.getElementById('prVelo')||{}).getAttribute
                 ? document.getElementById('prVelo').getAttribute('role') : '',
      modal:   (document.getElementById('prVelo')||{}).getAttribute
                 ? document.getElementById('prVelo').getAttribute('aria-modal') : '',
      focoDentro: !!(document.getElementById('prHoja') &&
                     document.getElementById('prHoja').contains(document.activeElement)),
      titulo: (document.getElementById('prHojaT')||{}).textContent || '',
      texto: (document.querySelector('.pr-hoja .cuerpo')||{}).innerText
               ? document.querySelector('.pr-hoja .cuerpo').innerText.replace(/\\s+/g,' ').trim().slice(0,1600) : '',
      horiz: Math.max(0, document.documentElement.scrollWidth - innerWidth),
      finos: [].filter.call(document.querySelectorAll('.pr-hoja p, .pr-hoja b, .pr-hoja em, .pr-hoja u, .pr-hoja h4'),
              function (e) { return e.offsetParent !== null && parseFloat(getComputedStyle(e).fontSize) < 14; }).length,
      cerrarCaja: (function(){ var b=document.getElementById('prHojaX'); if(!b) return '';
        var r=b.getBoundingClientRect(); return Math.round(r.width)+'x'+Math.round(r.height); })()
    })`));
    await tirar('lleg_hoja_360');

    //  EL FOCO NO SE VA DETRÁS DEL MODAL: tabulando se da la vuelta dentro.
    for (let i = 0; i < 12; i++) await tecla('Tab', 'Tab', 9);
    di('HOJA.FOCO_TRAS_TABS', await ev(
      `!!(document.getElementById('prHoja') && document.getElementById('prHoja').contains(document.activeElement))`));

    await tecla('Escape', 'Escape', 27);
    await dormir(320);
    di('HOJA.TRAS_ESCAPE', await ev(`JSON.stringify({
      visible: !!document.querySelector('.pr-velo.on'),
      focoEnBoton: document.activeElement === document.getElementById('prExplicar'),
      scroll: Math.round(scrollY)
    })`));
    di('HOJA.SCROLL_ANTES', scrollAntes);

    //  Y en escritorio: misma hoja, gesto distinto.
    await tam(1440, 900, 1);
    await ir(URL0);
    await clic('#prExplicar');
    await dormir(420);
    await tirar('lleg_hoja_1440');
    await tecla('Escape', 'Escape', 27);

    // ══════════════════════════════════════════════════════════════
    //  EL BOTÓN DE VERDAD · no basta con que el href prometa
    // ══════════════════════════════════════════════════════════════
    await tam(360, 800);
    await ir(URL0);
    const href = await ev(`(document.getElementById('prIr')||{}).getAttribute
      ? document.getElementById('prIr').getAttribute('href') : ''`);
    di('CLIC.HREF', href);
    await clic('#prIr');
    await listo();
    await cerrarRecibimiento(ev);
    di('CLIC.DESTINO', await ev(`JSON.stringify({
      url: location.href,
      pos: (document.querySelector('.sm-p.on')||{}).dataset ? document.querySelector('.sm-p.on').dataset.n : '',
      total: (document.querySelector('.sm')||{}).dataset ? document.querySelector('.sm').dataset.total : '',
      cabecera: ((document.querySelector('.sm')||{}).innerText||'').replace(/\s+/g,' ').trim().slice(0,90),
      hayAprobar: !!document.querySelector('[data-aprobar]')
    })`));

    // ── APROBAR AHÍ Y VOLVER: el resumen tiene que haber cambiado ──
    const antes = await ev(`(function(){var e=document.getElementById('prSemana');return e?e.textContent:'';})()`);
    //  Se apunta QUE tarjeta se aprueba antes de tocarla: al aprobar, la
    //  revision avanza sola a la siguiente y `.sm-p.on` pasa a ser OTRA — que
    //  tiene su propio boton de aprobar. Preguntar por `.on` despues decia que
    //  no se habia aprobado nada justo cuando si.
    const cual = await ev(`(function(){var e=document.querySelector('.sm-p.on');
      return e ? e.dataset.n : '';})()`);
    if (await clic('.sm-p.on [data-aprobar]')) {
      await dormir(2600);
      di('VUELTA.APROBADA', await ev(
        `!document.querySelector('.sm-p[data-n="${cual}"] [data-aprobar]')`));
      di('VUELTA.CUAL', cual);
    }
    await ir(URL0);
    di('VUELTA.RESUMEN', await ev(LEER));
    di('VUELTA.ANTES', antes);
  }

  di('ALERTAS', await ev('window.__alertas || 0'));
  di('ERRORES', await ev('JSON.stringify(window.__errs || [])'));
  di('OK', 1);
} catch (e) {
  di('OK', 0); di('ERROR', e.message);
} finally {
  cerrar();
}
