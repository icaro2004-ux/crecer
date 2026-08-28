// ============================================================
//  CRECER — EL RECORRIDO, EN UN ANDROID DE 360
//  tests/_recorrido_probe.mjs
//
//  Nueve pantallas seguidas, en el orden en que las vive el dueño, y en cada
//  una lo que no se puede mirar en PHP:
//
//    · que la barra de abajo marque DONDE ESTA y conserve la marca;
//    · que no haya callejones —de cada pantalla se sale— ni scroll lateral;
//    · que Ayuda no se siente encima de la accion principal;
//    · que atras y adelante del navegador no borren el contexto;
//    · y que Crear y Mi negocio se alcancen desde el menu.
//
//  Se dispara una captura por parada: son las nueve del entregable.
//
//    node tests/_recorrido_probe.mjs <carpeta|-> <sid> <marca> <pieza>
// ============================================================

import { abrirChrome, dormir, cerrarRecibimiento } from './_chrome.mjs';
import fs from 'node:fs';

const [shotsArg, sid, marca, piezaS] = process.argv.slice(2);
const shots = (shotsArg && shotsArg !== '-') ? shotsArg : '';
const PIEZA = parseInt(piezaS || '0', 10);
const BASE  = 'http://localhost/crecer/panel';
const di = (k, v) => console.log(k + '=' + String(v).replace(/\r?\n/g, ' '));

const SONDA = `
  window.__errs = [];
  addEventListener('error', function (e) { window.__errs.push(String(e.message)); });
  (function (o) { console.error = function () {
      window.__errs.push([].slice.call(arguments).join(' ')); o.apply(console, arguments); }; })(console.error);
  //  La guia del corillo se abre sola a los 650ms y vive en localStorage: se da
  //  por vista, que es lo que ve un dueño que ya ha entrado antes.
  try { ['propuestas','crear','sala','meta','inicio','calendario','resultados']
          .forEach(function (k) { localStorage.setItem('guia_' + k, '1'); }); } catch (e) {}
`;

//  LO QUE SE MIDE EN CADA PARADA. Nada de esto se puede afirmar leyendo PHP.
const MIRAR = `(function () {
  var txt = function (el) { return el ? (el.textContent || '').replace(/\\s+/g, ' ').trim() : ''; };
  var vis = function (el) { if (!el) return false; var r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0 && getComputedStyle(el).visibility !== 'hidden'; };
  var bn = document.querySelector('.botnav');
  var enlaces = bn ? [].filter.call(bn.querySelectorAll('a'), vis) : [];
  //  EL ACTIVO SE MARCA CON aria-current, que es lo que tambien oye un lector
  //  de pantalla; el orden visual lo decide «order» de CSS, no el DOM.
  var activo = enlaces.filter(function (x) { return x.getAttribute('aria-current') === 'page'; })
                      .map(txt);
  var orden = enlaces.slice().sort(function (a, b) {
    return a.getBoundingClientRect().left - b.getBoundingClientRect().left; });
  var solapa = 0;
  for (var i = 0; i < orden.length - 1; i++) {
    if (orden[i].getBoundingClientRect().right > orden[i+1].getBoundingClientRect().left + 1) solapa++;
  }
  //  LA MARCA VIAJA. Un enlace de la barra que la pierda deja al dueño en otro
  //  negocio —o en ninguno— con un solo toque.
  var sinMarca = enlaces.filter(function (x) {
    var h = x.getAttribute('href') || '';
    return h.indexOf('marca=') < 0 && h.charAt(0) !== '#';
  }).map(txt);
  //  ¿HAY SALIDA? Una pantalla sin ningun enlace a otra es un callejon.
  var salidas = [].filter.call(document.querySelectorAll('a[href]'), function (x) {
    var h = x.getAttribute('href') || '';
    return vis(x) && h.charAt(0) !== '#' && h.indexOf('javascript:') !== 0;
  }).length;
  //  AYUDA NO PUEDE SENTARSE ENCIMA DE LO QUE HAY QUE PULSAR.
  var fab = document.querySelector('.ay-fab');
  //  LA ACCION PRINCIPAL DE CADA PANTALLA, con los nombres que de verdad usa
  //  el producto. La primera version se dejo fuera «.est-go» —el «Vamos con
  //  este» del Estudio, que es el boton mas importante que hay— y por eso dijo
  //  que Ayuda no tapaba nada mientras la captura enseñaba lo contrario.
  var pri = document.querySelector('.est-go, .sm-bt.pri, .btn-primario, .ej-prox, .sc-send, .in-act a, button[type=submit]');
  var tapa = false, tapaQue = '', tapaFija = false;
  if (fab && vis(fab) && pri && vis(pri)) {
    var a = fab.getBoundingClientRect(), b = pri.getBoundingClientRect();
    tapa = a.right > b.left && a.left < b.right && a.bottom > b.top && a.top < b.bottom;
    if (tapa) tapaQue = (pri.className || pri.tagName) + ' · «'
                      + (pri.textContent || '').trim().slice(0, 28) + '»';
    //  Y SI LO QUE TAPA ESTA CLAVADO —fixed o sticky— el dueño no puede
    //  librarse bajando un dedo: eso ya no es un roce, es una accion que no
    //  se puede pulsar.
    if (tapa) {
      for (var e = pri; e && e !== document.body; e = e.parentElement) {
        var pos = getComputedStyle(e).position;
        if (pos === 'fixed' || pos === 'sticky') { tapaFija = true; break; }
      }
    }
  }
  var t = document.body.innerText || '';
  return JSON.stringify({
    url: location.pathname + location.search,
    activo: activo,
    etiquetas: enlaces.map(txt),
    solapa: solapa,
    sinMarca: sinMarca,
    salidas: salidas,
    tapa: tapa,
    tapaQue: tapaQue,
    tapaFija: tapaFija,
    horiz: Math.max(0, document.documentElement.scrollWidth - innerWidth),
    avisos: /Undefined variable|Warning:|Notice:|Fatal error|Deprecated:/.test(t) ? t.slice(0, 140) : '',
    //  Y NADA POR DEBAJO DE 14px en lo que hay que leer para decidir.
    finos: [].filter.call(document.querySelectorAll('.content b, .content p, .content span, .content a'),
      function (el) {
        if (!vis(el)) return false;
        var propio = [].slice.call(el.childNodes).some(function (nn) {
          return nn.nodeType === 3 && nn.textContent.trim().length > 1; });
        return propio && parseFloat(getComputedStyle(el).fontSize) < 14;
      }).length,
    //  Y NINGUN HUECO SIN LLENAR A LA VISTA. El marcador del clima («--°»)
    //  se quedaba puesto para siempre cuando la API no contestaba: la caja
    //  nace con «hidden» pero el CSS la pintaba igual.
    placeholder: /--°|__°|\{\{|undefined|NaN/.test(
      (document.querySelector('.content, main') || document.body).innerText || ''),
    titulo: txt(document.querySelector('h1')) || document.title
  });
})()`;

const s = await abrirChrome({ sid, url: 'about:blank', ancho: 360, alto: 800 });
if (s.error) { di('OK', 0); di('ERROR', s.error); process.exit(1); }
const { ev, cmd, cerrar } = s;

const listo = async () => {
  for (let i = 0; i < 200; i++) {
    if (await ev('document.readyState === "complete"')) { await dormir(340); return; }
    await dormir(110);
  }
};
const ir = async (u) => { await cmd('Page.navigate', { url: u }); await listo(); await cerrarRecibimiento(ev); };
async function tirar(nombre) {
  if (!shots) return;
  await dormir(420);
  const png = await cmd('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
  fs.writeFileSync(`${shots}/${nombre}.png`, Buffer.from(png.data, 'base64'));
}

//  LAS NUEVE PARADAS, en el orden en que las vive el dueño.
const PARADAS = [
  ['1_mi_negocio',        `genoma.php?marca=${marca}`],
  ['2_plan_listo',        `meta.php?marca=${marca}&vista=plan`],
  ['3_revision_semanal',  `meta.php?marca=${marca}&vista=semana`],
  ['4_calendario',        `calendario.php?marca=${marca}`],
  ['5_publicacion',       `propuestas.php?marca=${marca}&id=${PIEZA}`],
  ['6_resultados',        `resultados.php?marca=${marca}`],
  ['7_proxima_semana',    `meta.php?marca=${marca}`],
  ['8_sala',              `sala.php?marca=${marca}`],
  ['9_inicio',            `index.php?marca=${marca}`],
];

try {
  await cmd('Page.addScriptToEvaluateOnNewDocument', { source: SONDA });
  await cmd('Network.setCookie', { name: 'PHPSESSID', value: sid, domain: 'localhost', path: '/' });

  for (const [nombre, url] of PARADAS) {
    await ir(`${BASE}/${url}`);
    di('P_' + nombre, await ev(MIRAR));
    await tirar(nombre + '_360');
  }

  //  ATRAS Y ADELANTE. El dueño usa el boton del navegador todo el rato: si al
  //  volver pierde la marca o aterriza en una pantalla vacia, el recorrido se
  //  le rompe justo cuando esta comparando dos cosas.
  await cmd('Page.navigate', { url: `${BASE}/calendario.php?marca=${marca}` }); await listo();
  await cmd('Page.navigate', { url: `${BASE}/resultados.php?marca=${marca}` }); await listo();
  await ev('history.back()'); await dormir(1400); await listo();
  di('ATRAS', await ev(MIRAR));
  await ev('history.forward()'); await dormir(1400); await listo();
  di('ADELANTE', await ev(MIRAR));

  //  EL CAJON: Crear y Mi negocio se alcanzan desde el menu, sin escribir una
  //  URL a mano.
  await ir(`${BASE}/index.php?marca=${marca}`);
  di('CAJON', await ev(`(function(){
    var b = document.getElementById('hamb') || document.querySelector('.hamb, [aria-controls="side"]');
    if (b) b.click();
    var vs = [].filter.call(document.querySelectorAll('.side a'), function (x) {
      var r = x.getBoundingClientRect(); return r.width > 0 && r.height > 0; });
    var tx = vs.map(function (x) { return (x.textContent||'').trim(); });
    return JSON.stringify({
      abre: vs.length > 0,
      crear:   vs.some(function (x) { return /crear=1/.test(x.getAttribute('href')||'')
                                          || /^crear$/i.test((x.textContent||'').trim()); }),
      negocio: vs.some(function (x) { return /genoma\\.php/.test(x.getAttribute('href')||''); }),
      //  Y TODO LO DEL CAJON CONSERVA LA MARCA.
      sinMarca: vs.filter(function (x) {
        var h = x.getAttribute('href')||'';
        return h && h.charAt(0) !== '#' && h.indexOf('marca=') < 0
            && /\\/panel\\//.test(h); }).map(function (x) { return (x.textContent||'').trim(); }),
      etiquetas: tx.slice(0, 30)
    });
  })()`));

  di('ERRORES', await ev('JSON.stringify(window.__errs || [])'));
  di('OK', 1);
} catch (e) {
  di('OK', 0); di('ERROR', e.message);
} finally {
  cerrar();
}
