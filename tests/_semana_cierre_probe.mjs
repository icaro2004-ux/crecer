// ============================================================
//  CRECER — CERRAR UNA SEMANA Y ABRIR LA SIGUIENTE, CON EL DEDO
//  tests/_semana_cierre_probe.mjs
//
//  EL RECORRIDO ENTERO en un Android de 360px, tocando de verdad:
//    1 · la semana terminada ofrece cerrarla (y no antes)
//    2 · el cierre enseña números reales y una valoración OPCIONAL
//    3 · se pulsa «Preparar la próxima semana»
//    4 · se RECARGA mientras prepara: la historia no cambia
//    5 · la semana nueva está lista
//    6 · y desde ahí se entra a su primera decisión
//
//  Lo que se mira aquí no se puede mirar en PHP: que el dueño pueda TOCARLO
//  —44px, sin desbordes, con una sola acción principal a la vista— y que
//  recargar en mitad del trabajo no le invente otra historia.
//
//    node tests/_semana_cierre_probe.mjs <carpeta|-> <sid> <marca>
// ============================================================

import { abrirChrome, dormir, cerrarRecibimiento } from './_chrome.mjs';
import fs from 'node:fs';

const [shotsArg, sid, marca, modo] = process.argv.slice(2);
const shots = (shotsArg && shotsArg !== '-') ? shotsArg : '';
const BASE  = 'http://localhost/crecer/panel';
const URLM  = `${BASE}/meta.php?marca=${marca}`;
const di = (k, v) => console.log(k + '=' + String(v).replace(/\r?\n/g, ' '));

const SONDA = `
  window.__errs = []; window.__alertas = 0;
  addEventListener('error', function (e) { window.__errs.push(String(e.message)); });
  (function (o) { console.error = function () {
      window.__errs.push([].slice.call(arguments).join(' ')); o.apply(console, arguments); }; })(console.error);
  window.alert = function () { window.__alertas++; };
`;

//  LO QUE DICE LA PANTALLA DEL CIERRE. Se lee del DOM, no del servidor.
const LEER = `(function () {
  var caja = document.querySelector('.cz');
  if (!caja) return JSON.stringify({ hay: false });
  var vis = function (el) { if (!el) return false; var r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0 && getComputedStyle(el).visibility !== 'hidden'; };
  var el = function (id) { return document.getElementById(id); };
  var num = [].map.call(caja.querySelectorAll('.cz-num b'), function (b) { return b.textContent.trim(); });
  return JSON.stringify({
    hay: true,
    estado: caja.getAttribute('data-estado') || '',
    paso:   (caja.querySelector('.cz-paso') || {}).textContent || '',
    titulo: (caja.querySelector('h1, .cz-espera h2') || {}).textContent || '',
    numeros: num,
    opciones: [].map.call(caja.querySelectorAll('#czOpts .cz-opt'), function (b) {
      return (b.dataset.v || '') + (b.classList.contains('on') ? ':on' : '');
    }),
    comentario: !!el('czCom'),
    aux: (caja.querySelector('.cz-aux') || {}).textContent || '',
    prep: vis(el('czPrep')),
    prepTexto: el('czPrep') ? (el('czPrep').textContent || '').trim() : '',
    atras: vis(caja.querySelector('.cz-atras')),
    ir: !!caja.querySelector('.cz-pie a.pri'),
    irHref: caja.querySelector('.cz-pie a.pri') ? caja.querySelector('.cz-pie a.pri').getAttribute('href') : '',
    errVisible: !!(el('czErr') && el('czErr').classList.contains('on')),
    texto: (caja.innerText || '').replace(/\\s+/g, ' ').trim().slice(0, 500)
  });
})()`;

//  LA MEDIDA. En el navegador, porque el HTML no sabe de viewport ni de la
//  barra de abajo ni del botón flotante de Ayuda.
const MEDIR = `(function () {
  var vis = function (el) { if (!el) return false; var r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0 && getComputedStyle(el).visibility !== 'hidden'
           && getComputedStyle(el).display !== 'none'; };
  var caja = document.querySelector('.cz');
  var out = { hay: !!caja, horiz: 0, chicos: [], finos: [], primarias: 0 };
  if (!caja) return out;

  out.horiz = Math.max(0, document.documentElement.scrollWidth - innerWidth);
  var bn = document.querySelector('.botnav');
  var techo = innerHeight - (bn && vis(bn) ? bn.getBoundingClientRect().height : 0);
  out.techo = Math.round(techo);

  [].forEach.call(caja.querySelectorAll('button, a, textarea'), function (el) {
    if (!vis(el)) return;
    var r = el.getBoundingClientRect();
    if (r.width < 44 || r.height < 44)
      out.chicos.push((el.id || el.className || el.tagName) + ' ' + Math.round(r.width) + 'x' + Math.round(r.height));
    if (r.right > innerWidth + 1 || r.left < -1) out.chicos.push('FUERA ' + (el.id || el.className));
  });
  [].forEach.call(caja.querySelectorAll('p, b, span, button, a, h1, h2, label, div'), function (el) {
    if (!vis(el)) return;
    var propio = [].slice.call(el.childNodes)
      .some(function (n) { return n.nodeType === 3 && n.textContent.trim().length > 1; });
    if (!propio) return;
    var fz = parseFloat(getComputedStyle(el).fontSize);
    if (fz < 14) out.finos.push((el.textContent || '').trim().slice(0, 24) + ' @' + fz);
  });

  //  UNA SOLA ACCIÓN PRINCIPAL, y que se pueda tocar sin que nada la tape.
  //  EL BOTON SE MIDE DONDE SE TOCA. Un formulario con tres opciones y un
  //  cuadro de texto no cabe en 800px de alto, y no tiene por que: lo que no
  //  puede pasar es que al llegar a el algo lo tape —la barra de abajo, el
  //  boton flotante de Ayuda—. Asi que primero se baja hasta el.
  var pri = [].filter.call(caja.querySelectorAll('.cz-bt.pri'), vis);
  out.primarias = pri.length;
  if (pri.length) {
    pri[0].scrollIntoView({ block: 'center' });
    var r = pri[0].getBoundingClientRect();
    out.priId = pri[0].id || pri[0].className;
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
  //  LO QUE SI TIENE QUE VERSE SIN BAJAR es la primera pregunta: si el dueño
  //  abre y solo ve texto, no sabe que se espera de el.
  var q1 = caja.querySelector('#czOpts .cz-opt');
  if (q1) { var rq = q1.getBoundingClientRect();
            out.preguntaSinBajar = rq.top >= 0 && rq.bottom <= techo; }

  //  LA SALIDA SIEMPRE: nadie se queda encerrado en el cierre.
  out.salida = vis(caja.querySelector('.cz-atras'));
  return out;
})()`;

//  LA PUERTA en la revisión semanal: ¿se ofrece cerrar, y a dónde lleva?
//  «LO QUE TOMÉ EN CUENTA» — la sección breve de la llegada, y su detalle.
const CTA = `(function () {
  var c = document.querySelector('.cz-cta');
  if (!c) return JSON.stringify({ hay: false });
  var vis = function (el) { if (!el) return false; var r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0; };
  var b = document.getElementById('czPorque');
  var h = document.getElementById('czHoja');
  return JSON.stringify({
    hay: true,
    titulo: (c.querySelector('h3') || {}).textContent || '',
    lineas: [].map.call(c.querySelectorAll('li span'), function (x) { return (x.textContent || '').trim(); }),
    porque: vis(b),
    porqueTexto: b ? (b.textContent || '').trim() : '',
    hojaAbierta: !!(h && !h.hidden),
    hojaTexto: h ? (h.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 400) : ''
  });
})()`;

//  LA PUERTA vive en la ULTIMA carta del mazo de la semana: el dueño llega a
//  ella deslizando. Para medirla hay que ponerla delante, que es lo que hace
//  el mazo al llegar al final; lo que se prueba aqui es la puerta, no el mazo
//  —eso ya tiene su prueba— asi que se muestra la carta y se mide.
const PUERTA = `(function () {
  var fin = document.querySelector('.sm-p[data-fin]');
  if (fin) {
    [].forEach.call(document.querySelectorAll('.sm-p'), function (s) { s.classList.remove('on'); });
    fin.classList.add('on');
  }
  var a = document.querySelector('a[href*="vista=cerrar"]');
  var vis = function (el) { if (!el) return false; var r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0; };
  return JSON.stringify({
    hay: !!a, visible: vis(a),
    texto: a ? (a.textContent || '').replace(/\\s+/g, ' ').trim() : '',
    href: a ? a.getAttribute('href') : ''
  });
})()`;

const s = await abrirChrome({ sid, url: 'about:blank', ancho: 360, alto: 800 });
if (s.error) { di('OK', 0); di('ERROR', s.error); process.exit(1); }
const { ev, cmd, cerrar } = s;

const listo = async () => {
  for (let i = 0; i < 160; i++) {
    if (await ev('document.readyState === "complete"')) { await dormir(360); return; }
    await dormir(110);
  }
};
const ir = async (u) => { await cmd('Page.navigate', { url: u }); await listo(); await cerrarRecibimiento(ev); };
const tam = (w, h, esc = 2) => cmd('Emulation.setDeviceMetricsOverride',
  { width: w, height: h, deviceScaleFactor: esc, mobile: w < 900 });
async function tirar(nombre) {
  if (!shots) return;
  //  Arriba del todo: la captura es lo que el dueno ve al abrir, no donde
  //  la medida dejo la pagina.
  await ev('window.scrollTo(0, 0)');
  await dormir(400);
  const png = await cmd('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
  fs.writeFileSync(`${shots}/${nombre}.png`, Buffer.from(png.data, 'base64'));
}
//  Tocar de verdad, no llamar a click() a ciegas: se comprueba que exista.
const tocar = async (sel) => ev(`(function(){var e=document.querySelector(${JSON.stringify(sel)});
  if(!e) return false; e.click(); return true;})()`);

try {
  await cmd('Page.addScriptToEvaluateOnNewDocument', { source: SONDA });
  await cmd('Network.setCookie', { name: 'PHPSESSID', value: sid, domain: 'localhost', path: '/' });
  await tam(360, 800);

  //  MODO «CTA». La llegada de la semana nueva con su resumen breve: se lee,
  //  se abre el detalle y se comprueba que lo aprobado aparece en el Calendario.
  //  Va aparte porque exige que la semana ya este producida, y eso lo siembra
  //  la prueba de PHP —no el navegador—.
  if (modo === 'cta') {
    await ir(`${URLM}&vista=cerrar`);
    di('CTA', await ev(CTA));
    di('CTA_MED', await ev(MEDIR).then(JSON.stringify));
    await tirar('08_lo_que_tome_en_cuenta_360');
    await tocar('#czPorque');
    await dormir(200);
    di('CTA_ABIERTA', await ev(CTA));
    await tirar('09_ver_por_que_360');

    //  Y EL CALENDARIO: lo aprobado tiene que estar donde el dueño lo busca.
    await ir(`${BASE}/calendario.php?marca=${marca}`);
    di('CALENDARIO', await ev(`JSON.stringify((function () {
      var t = (document.body.innerText || '').replace(/\s+/g, ' ');
      return { url: location.href, tieneAlgo: t.length > 80, texto: t.slice(0, 400) };
    })())`));
    await tirar('10_calendario_360');

    di('ALERTAS', await ev('window.__alertas || 0'));
    di('ERRORES', await ev('JSON.stringify(window.__errs || [])'));
    di('OK', 1);
    cerrar();
    process.exit(0);
  }

  //  MODO «ESPERA». El rato en que el corillo trabaja dura milisegundos con
  //  el modelo simulado, asi que pillarlo pulsando el boton es una carrera
  //  perdida. Se siembra el estado en la base y se mira: eso SI es
  //  determinista, y es lo que el dueño se encuentra si recarga.
  if (modo === 'espera') {
    await ir(`${URLM}&vista=cerrar`);
    di('ESPERA', await ev(LEER));
    di('ESPERA_MED', await ev(MEDIR).then(JSON.stringify));
    await tirar('07_preparando_360');
    //  Y RECARGAR NO LA CAMBIA: el estado vive en la base.
    await ir(`${URLM}&vista=cerrar`);
    di('ESPERA_RECARGA', await ev(LEER));
    di('ALERTAS', await ev('window.__alertas || 0'));
    di('ERRORES', await ev('JSON.stringify(window.__errs || [])'));
    di('OK', 1);
    cerrar();
    process.exit(0);
  }

  // ── 1 · LA PUERTA, EN LA REVISIÓN SEMANAL ──────────────────────
  await ir(`${URLM}&vista=semana`);
  di('PUERTA', await ev(PUERTA));
  await tirar('01_semana_360');

  // ── 2 · EL CIERRE ──────────────────────────────────────────────
  await ir(`${URLM}&vista=cerrar`);
  di('CIERRE', await ev(LEER));
  di('CIERRE_MED', await ev(MEDIR).then(JSON.stringify));
  await tirar('02_cierre_360');

  //  LA VALORACIÓN ES OPCIONAL Y SE PUEDE DESHACER: tocarla dos veces la
  //  quita. Nadie tiene que quedarse atrapado en una respuesta que ya no
  //  piensa.
  await tocar('#czOpts .cz-opt[data-v="peor"]');
  await dormir(120);
  di('TRAS_TOCAR', await ev(LEER));
  await tocar('#czOpts .cz-opt[data-v="peor"]');
  await dormir(120);
  di('TRAS_DESTOCAR', await ev(LEER));

  //  Se elige una de verdad y se escribe: es lo que la Estratega va a leer.
  await tocar('#czOpts .cz-opt[data-v="mejor"]');
  await ev(`(function(){var t=document.getElementById('czCom');
    if(!t) return false; t.value='El jueves se dañó el horno.'; return true;})()`);
  await tirar('03_cierre_valorado_360');

  // ── 3 · SE PULSA PREPARAR ──────────────────────────────────────
  di('PULSA', await tocar('#czPrep'));
  //  El botón se apaga al instante: sin eso, un doble toque son dos peticiones.
  await dormir(180);
  di('BOTON_TRAS_PULSAR', await ev(`JSON.stringify((function(){
    var b=document.getElementById('czPrep');
    return b ? { hay:true, disabled:b.disabled, texto:(b.textContent||'').trim() } : { hay:false };
  })())`));

  // ── 4 · RECARGAR EN MITAD DEL TRABAJO ──────────────────────────
  //  Se recarga sin esperar al final: la historia sale de la base, así que
  //  tiene que ser la misma antes y después.
  await ir(`${URLM}&vista=cerrar`);
  di('RECARGA', await ev(LEER));
  await tirar('04_recarga_360');

  // ── 5 · LA SEMANA NUEVA ────────────────────────────────────────
  //  Se espera a que el estado deje de ser «preparando»: hasta ~40 s, que es
  //  más de lo que tarda el modelo simulado.
  let fin = {};
  for (let i = 0; i < 40; i++) {
    await ir(`${URLM}&vista=cerrar`);
    fin = JSON.parse(await ev(LEER));
    if ((fin.estado || '') !== 'preparando') break;
    await dormir(1000);
  }
  di('LISTA', JSON.stringify(fin));
  di('LISTA_MED', await ev(MEDIR).then(JSON.stringify));
  await tirar('05_semana_lista_360');

  // ── 6 · Y SE ENTRA A LA PRIMERA DECISIÓN ───────────────────────
  const href = String(fin.irHref || '');
  if (href) {
    await ir(href.startsWith('http') ? href : 'http://localhost' + href);
    di('DECISION', await ev(`JSON.stringify((function(){
      var c = document.querySelector('.sm, .sem, main');
      var t = (document.body.innerText || '').replace(/\\s+/g, ' ').trim();
      return { url: location.href, hay: !!c, texto: t.slice(0, 300) };
    })())`));
    await tirar('06_primera_decision_360');
  } else {
    di('DECISION', '{}');
  }

  di('ALERTAS', await ev('window.__alertas || 0'));
  di('ERRORES', await ev('JSON.stringify(window.__errs || [])'));
  di('OK', 1);
} catch (e) {
  di('OK', 0); di('ERROR', e.message);
} finally {
  cerrar();
}
