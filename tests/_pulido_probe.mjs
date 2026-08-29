// ============================================================
//  CRECER — EL PULIDO, MEDIDO EN LA PANTALLA
//  tests/_pulido_probe.mjs
//
//  Cuatro cosas que solo se pueden comprobar mirando lo que se pinta:
//
//    · «AQUI ESTAS» — exactamente una entrada marcada, y la correcta. Se mide
//      lo VISIBLE: la barra de abajo desaparece en escritorio y los cuatro
//      destinos que el menu repite desaparecen en movil, asi que contar el DOM
//      daria dos y no seria verdad ninguna de las dos veces.
//    · AYUDA NO TAPA LA DECISION — solape real de rectangulos contra el boton
//      primario de cada pantalla, no presencia de una clase.
//    · TEXTO OPERATIVO ≥14px y controles ≥44px, en el calendario.
//    · CERO scroll lateral, cero avisos de PHP, cero errores de consola.
//
//    node tests/_pulido_probe.mjs <carpeta|-> <sid> <marca> <pieza>
// ============================================================

import { abrirChrome, dormir, cerrarRecibimiento } from './_chrome.mjs';
import fs from 'node:fs';

const [shotsArg, sid, marca, piezaS] = process.argv.slice(2);
const shots = (shotsArg && shotsArg !== '-') ? shotsArg : '';
const PIEZA = parseInt(piezaS || '0', 10);
const SINHORA = parseInt(process.argv[6] || '0', 10);
//  El Estudio enseña el primer borrador del mazo, no la pieza del `?id=`.
//  `?jugada=` SI filtra el mazo, y es como se llega a mirar una concreta.
const JUG_HORA = parseInt(process.argv[7] || '0', 10);
const JUG_SIN  = parseInt(process.argv[8] || '0', 10);
const BASE  = 'http://localhost/crecer/panel';
const di = (k, v) => console.log(k + '=' + String(v).replace(/\r?\n/g, ' '));

const SONDA = `
  window.__errs = [];
  addEventListener('error', function (e) { window.__errs.push(String(e.message)); });
  (function (o) { console.error = function () {
      window.__errs.push([].slice.call(arguments).join(' ')); o.apply(console, arguments); }; })(console.error);
  try { ['propuestas','crear','sala','meta','inicio','calendario','resultados']
          .forEach(function (k) { localStorage.setItem('guia_' + k, '1'); }); } catch (e) {}
`;

//  UN ELEMENTO CUENTA SI SE VE. `display:none` en un ancestro es la diferencia
//  entre «la barra dice donde estoy» y «hay una barra escondida que lo dice».
const COMUN = `
  var vis = function (el) { if (!el) return false;
    var r = el.getBoundingClientRect();
    if (!(r.width > 0 && r.height > 0)) return false;
    var cs = getComputedStyle(el);
    return cs.visibility !== 'hidden' && cs.opacity !== '0';
  };
  var txt = function (el) { return el ? (el.textContent || '').replace(/\\s+/g, ' ').trim() : ''; };
`;

const MIRAR = `(function () {
  ${COMUN}
  //  MARCADOS Y VISIBLES, vengan de la barra o del menu.
  var marcados = [].filter.call(document.querySelectorAll('[aria-current="page"]'), vis);
  var dock = [].filter.call(document.querySelectorAll('.botnav a'), vis);
  var menu = [].filter.call(document.querySelectorAll('.side a'), vis);
  //  AYUDA CONTRA LA DECISION DE ESTA PANTALLA.
  var fab = document.querySelector('.ay-fab');
  //  «Ajustalo» y «No» tambien son botones: taparlos es taparle una salida.
  var PRI = '.est-go, .est-minor button, .est-b, .sm-bt.pri, .tm-btn, .sc-op-b,'
          + ' .sc-send, .wiz-go, .btn-primario, button[type=submit]';
  var choques = [];
  if (fab && vis(fab)) {
    var a = fab.getBoundingClientRect();
    [].forEach.call(document.querySelectorAll(PRI), function (b) {
      if (!vis(b)) return;
      var r = b.getBoundingClientRect();
      if (a.right > r.left && a.left < r.right && a.bottom > r.top && a.top < r.bottom)
        choques.push((b.className || b.tagName) + ' «' + txt(b).slice(0, 24) + '»');
    });
  }
  var t = document.body.innerText || '';
  return JSON.stringify({
    url: location.pathname + location.search,
    marcados: marcados.map(function (x) { return txt(x) + '@' + (x.closest('.botnav') ? 'barra' : 'menu'); }),
    dock: dock.length, menu: menu.length,
    choques: choques,
    horiz: Math.max(0, document.documentElement.scrollWidth - innerWidth),
    avisos: /Undefined variable|Warning:|Notice:|Fatal error|Deprecated:/.test(t) ? t.slice(0, 140) : '',
    h1: txt(document.querySelector('h1')),
    titulo: document.title
  });
})()`;

//  EL CALENDARIO, MEDIDO. Se listan los textos y controles que incumplen, con
//  su tamaño: una cifra suelta no dice qué hay que arreglar.
const CAL = `(function () {
  ${COMUN}
  var zona = document.querySelector('.content') || document.body;
  //  Lo OPERATIVO: lo que comunica fecha, hora, red, estado, origen o accion.
  //  «.mcount» queda fuera a proposito y esta declarado en el fuente: es la
  //  cuenta del dia, ya la dicen los puntos, y a 14px la celda cuadrada revienta.
  var finos = [];
  [].forEach.call(zona.querySelectorAll('.ev, .dn, .dow, .tcol, .today, .sv, .add-box label, .ev-box .del, .cap, .go'),
    function (el) {
      if (!vis(el)) return;
      var propio = [].slice.call(el.childNodes).some(function (n) {
        return n.nodeType === 3 && n.textContent.trim().length > 0; });
      if (!propio) return;
      var fs = parseFloat(getComputedStyle(el).fontSize);
      if (fs < 14) finos.push((el.className || el.tagName) + ' ' + fs + 'px «' + txt(el).slice(0, 20) + '»');
    });
  var chicos = [];
  [].forEach.call(zona.querySelectorAll('a, button, input, select'), function (el) {
    if (!vis(el)) return;
    var r = el.getBoundingClientRect();
    if (r.height < 44 || r.width < 44)
      chicos.push((el.className || el.tagName) + ' ' + Math.round(r.width) + 'x' + Math.round(r.height));
  });
  //  Y NADA CORTADO: un texto que se sale de su caja es un dato perdido.
  var cortados = [];
  [].forEach.call(zona.querySelectorAll('.ev, .dn, .dow, .tcol'), function (el) {
    if (!vis(el)) return;
    if (el.scrollWidth > el.clientWidth + 2 || el.scrollHeight > el.clientHeight + 2)
      cortados.push((el.className || el.tagName) + ' «' + txt(el).slice(0, 18) + '»');
  });
  return JSON.stringify({ finos: finos, chicos: chicos, cortados: cortados,
    horiz: Math.max(0, document.documentElement.scrollWidth - innerWidth) });
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
const tam = (w, h) => cmd('Emulation.setDeviceMetricsOverride',
  { width: w, height: h, deviceScaleFactor: 1, mobile: w < 900 });
async function tirar(nombre) {
  if (!shots) return;
  await dormir(420);
  const png = await cmd('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
  fs.writeFileSync(`${shots}/${nombre}.png`, Buffer.from(png.data, 'base64'));
}

try {
  await cmd('Page.addScriptToEvaluateOnNewDocument', { source: SONDA });
  await cmd('Network.setCookie', { name: 'PHPSESSID', value: sid, domain: 'localhost', path: '/' });

  // ── 1 · «AQUI ESTAS», en los dos anchos que cambian la navegacion ──
  const PANTALLAS = [
    ['negocio',    `genoma.php?marca=${marca}`],
    ['posts',      `propuestas.php?marca=${marca}`],
    ['sala',       `sala.php?marca=${marca}`],
    ['calendario', `calendario.php?marca=${marca}`],
    ['meta',       `meta.php?marca=${marca}`],
  ];
  for (const [ancho, w, h] of [['360', 360, 800], ['1440', 1440, 900]]) {
    await tam(w, h);
    for (const [nombre, url] of PANTALLAS) {
      await ir(`${BASE}/${url}`);
      di(`N_${ancho}_${nombre}`, await ev(MIRAR));
      if (ancho === '360' && nombre === 'negocio') await tirar('1_mi_negocio_360');
    }
  }

  // ── 2 · AYUDA Y LA DECISION, en el Estudio ──
  await tam(360, 800);
  await ir(`${BASE}/propuestas.php?marca=${marca}&id=${PIEZA}`);
  //  Se baja hasta el veredicto: es donde el boton flotante lo alcanzaba.
  await ev(`(function(){ var b=document.querySelector('.est-go');
    if (b) b.scrollIntoView({block:'center'}); })()`);
  await dormir(900);
  di('AYUDA_ESTUDIO', await ev(MIRAR));
  await tirar('2_estudio_vamos_con_este_360');

  //  Y la hoja de Crear: con la capa abierta, Ayuda se aparta.
  await ir(`${BASE}/propuestas.php?marca=${marca}&crear=1`);
  await dormir(900);
  di('AYUDA_CREAR', await ev(MIRAR));

  //  LA SALA, con su tarjeta de decision: es la otra superficie del recorrido
  //  donde el boton flotante y una accion primaria comparten banda.
  await ir(`${BASE}/sala.php?marca=${marca}`);
  di('AYUDA_SALA', await ev(MIRAR));
  await tirar('4_sala_360');

  //  LAS DOS PIEZAS DE LA HORA, una al lado de la otra: la que el plan
  //  sugirio y la que no tiene hora ninguna.
  await ir(`${BASE}/propuestas.php?marca=${marca}&jugada=${JUG_HORA}`);
  di('HORA_SUGERIDA', await ev(`(function(){ ${COMUN}
    return JSON.stringify({ creditos: [].map.call(document.querySelectorAll('.est-cred li, .est-creds li,'
      + ' .est-cred div, .est-por li'), txt).slice(0, 6),
      texto: (document.querySelector('.est') || document.body).innerText.replace(/\\s+/g,' ').slice(0, 1600) });
  })()`));
  //  Y SIN HABER BAJADO NI UN DEDO: es como llega el dueño a la pantalla.
  di('AYUDA_ESTUDIO_TOP', await ev(MIRAR));
  await tirar('5_pieza_hora_sugerida_360');
  if (SINHORA > 0) {
    await ir(`${BASE}/propuestas.php?marca=${marca}&jugada=${JUG_SIN}`);
    di('HORA_SIN', await ev(`(function(){ ${COMUN}
      return JSON.stringify({ texto: (document.querySelector('.est') || document.body)
        .innerText.replace(/\\s+/g,' ').slice(0, 1600) });
    })()`));
    await tirar('6_pieza_sin_hora_360');
  }

  // ── 3 · EL CALENDARIO, EN TRES ANCHOS ──
  for (const [n, w, h] of [['360', 360, 800], ['414', 414, 896], ['1440', 1440, 900]]) {
    await tam(w, h);
    await ir(`${BASE}/calendario.php?marca=${marca}`);
    di(`CAL_${n}`, await ev(CAL));
    di(`CALNAV_${n}`, await ev(MIRAR));
    await tirar(`3_calendario_${n}`);
  }

  di('ERRORES', await ev('JSON.stringify(window.__errs || [])'));
  di('OK', 1);
} catch (e) {
  di('OK', 0); di('ERROR', e.message);
} finally {
  cerrar();
}
