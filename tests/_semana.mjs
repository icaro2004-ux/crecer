// ============================================================
//  CRECER — LA REVISION SEMANAL, EN UN NAVEGADOR DE VERDAD
//  tests/_semana.mjs
//
//  Lo que se prueba aqui NO se puede probar leyendo el fuente: que el dueno
//  pueda RECORRER su semana. Aprobar y que avance. Salir a ajustar y volver a
//  la MISMA publicacion, no al principio. Y que a 360x800 la accion se vea sin
//  desplazar, porque una decision que hay que ir a buscar no se toma.
//
//  Comprobar el href no basta y por eso no se hace: un enlace bien escrito
//  hacia una pantalla que redirige deja al dueno en otro sitio igual. Aqui se
//  pulsa y se mira donde se acaba.
//
//    node tests/_semana.mjs <sid> <marca> <tactica_comprometida> <carpeta>
//
//  Imprime CLAVE=valor. Quien asierta es la prueba en PHP.
// ============================================================

import { abrirChrome, dormir, cerrarRecibimiento } from './_chrome.mjs';
import fs from 'node:fs';

const [sid, marca, tacComp, shots, piezaShim] = process.argv.slice(2);
const BASE = 'http://localhost/crecer/panel';
const SEM  = `${BASE}/meta.php?marca=${marca}&vista=semana`;
const di = (k, v) => console.log(k + '=' + String(v).replace(/\r?\n/g, ' '));

//  LOS ERRORES DE CONSOLA SE RECOGEN DESDE ANTES DE QUE LA PAGINA CORRA.
//  Engancharse despues de cargar solo ve los errores que lleguen tarde, que
//  son justo los que no importan.
const SONDA = `
  window.__errs = [];
  addEventListener('error', function (e) { window.__errs.push(String(e.message)); });
  addEventListener('unhandledrejection', function (e) { window.__errs.push('promesa: ' + e.reason); });
  (function (o) { console.error = function () {
      window.__errs.push([].slice.call(arguments).join(' ')); o.apply(console, arguments); }; })(console.error);
`;

//  LA MEDIDA. Es la misma vara que la maqueta: si algo aqui sale rojo, la
//  pantalla no esta lista aunque las pruebas de datos esten verdes.
const MEDIR = `(function () {
  var vis = function (el) { var r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0 && getComputedStyle(el).visibility !== 'hidden'; };
  var caja = document.querySelector('.sm, .wz');
  var out = { w: innerWidth, h: innerHeight, horiz: 0, fuera: [], chicos: [], finos: [],
              primarias: 0, primVisible: null, primTapada: null, emo: [], hay: !!caja };
  if (!caja) return out;

  //  1 · NADA SE SALE A LO ANCHO. Un scroll horizontal en un movil es una
  //      pantalla rota, no una pantalla apretada.
  out.horiz = Math.max(0, document.documentElement.scrollWidth - innerWidth);
  [].forEach.call(caja.querySelectorAll('*'), function (el) {
    if (!vis(el)) return;
    var r = el.getBoundingClientRect();
    if (r.right > innerWidth + 1 || r.left < -1) out.fuera.push(el.className || el.tagName);
  });

  //  2 · LO QUE SE TOCA, 44x44. Debajo de eso se falla el toque y se pulsa lo
  //      de al lado — que aqui puede ser «aprobar» en vez de «ajustar».
  //  CUANDO HAY UNA CAPA ABIERTA, LA CAPA ES LA PANTALLA. Medir el boton de
  //  la pieza que quedo detras del velo daba «esta tapado» — y claro que lo
  //  esta: eso es lo que hace un velo. Lo que hay que mirar es la hoja.
  var velo = document.querySelector('.sm-velo.on');
  var visible = velo || caja.querySelector('.sm-p.on') || caja.querySelector('.wz-p.on') || caja;
  var zonas = velo ? [velo] : [visible, caja.querySelector('.sm-top'), caja.querySelector('.wz-salir')];
  zonas.forEach(function (z) {
    if (!z) return;
    [].forEach.call(z.querySelectorAll('button, a, input, textarea, select'), function (el) {
      if (!vis(el)) return;
      var r = el.getBoundingClientRect();
      if (r.width < 44 || r.height < 44)
        out.chicos.push((el.className || el.tagName) + ' ' + Math.round(r.width) + 'x' + Math.round(r.height));
    });
  });

  //  3 · EL TEXTO QUE SE LEE PARA DECIDIR, 14px o mas.
  var suelo = zonas.filter(Boolean);
  suelo.forEach(function (z) {
    [].forEach.call(z.querySelectorAll('p, b, span, button, a, h1, h2, h3, li, small'), function (el) {
      if (!vis(el)) return;
      var t = (el.textContent || '').trim();
      if (t.length < 2) return;
      var propio = [].slice.call(el.childNodes)
        .some(function (n) { return n.nodeType === 3 && n.textContent.trim().length > 1; });
      if (!propio) return;
      var fs = parseFloat(getComputedStyle(el).fontSize);
      if (fs < 14) out.finos.push(t.slice(0, 26) + ' @' + fs);
    });
  });

  //  4 · UNA SOLA PRIMARIA, Y VISIBLE SIN DESPLAZAR.
  //  «Primaria» es la clase que la pantalla usa para su accion principal, y no
  //  es la misma en las dos capas: .sm-bt en la revision, .su-opt/.tm-btn en la
  //  puerta de sustituir. Mirar solo una dejaba la otra sin medir.
  var pri = visible.querySelectorAll('.sm-bt.pri, .sm-bt.rosa, .su-opt.pri, .wz-nav .tm-btn');
  out.primarias = pri.length;
  if (pri.length) {
    var r = pri[0].getBoundingClientRect();
    //  La barra de abajo del panel tapa; lo que quede debajo de ella no se ve.
    var bn = document.querySelector('.botnav');
    var techo = innerHeight - (bn && vis(bn) ? bn.getBoundingClientRect().height : 0);
    out.primVisible = r.top >= 0 && r.bottom <= techo + 1;
    out.primRect = Math.round(r.top) + ',' + Math.round(r.bottom) + ' de ' + Math.round(techo);
    //  5 · Y QUE NADA LA TAPE. Un boton visible debajo de un velo no es un
    //      boton: es un dibujo.
    var c = document.elementFromPoint(r.left + r.width / 2, Math.min(r.top + r.height / 2, techo - 2));
    out.primTapada = !(c && (c === pri[0] || pri[0].contains(c)));
  }

  //  6 · AYUDA NO SE SIENTA ENCIMA DE NINGUN CONTROL.
  //      El boton flotante de Ayuda se aparta solo si la pantalla esta en su
  //      lista blanca (_meta_zona.php). Si no lo esta, se queda encima de
  //      «Dejar pendiente» y ese control deja de existir. No se ve leyendo el
  //      codigo; se ve midiendo.
  out.tapadosPorAyuda = [];
  var fab = document.querySelector('.ay-fab');
  if (fab && vis(fab) && getComputedStyle(fab).opacity !== '0') {
    var f = fab.getBoundingClientRect();
    zonas.filter(Boolean).forEach(function (z) {
      [].forEach.call(z.querySelectorAll('button, a'), function (el) {
        if (!vis(el)) return;
        var r = el.getBoundingClientRect();
        var solapa = !(r.right < f.left || r.left > f.right || r.bottom < f.top || r.top > f.bottom);
        if (solapa) out.tapadosPorAyuda.push((el.className || el.tagName) + ' :: ' +
          (el.textContent || '').trim().slice(0, 20));
      });
    });
  }

  //  7 · CERO EMOJI EN LA INTERFAZ (regla permanente de la casa).
  var tx = caja.innerText || '';
  var m = tx.match(/[\\u{1F300}-\\u{1FAFF}\\u{2600}-\\u{27BF}]/gu);
  if (m) out.emo = m.slice(0, 5);
  return out;
})()`;

const s = await abrirChrome({ sid, url: 'about:blank', ancho: 360, alto: 800 });
if (s.error) { di('OK', 0); di('ERROR', s.error + ' :: ' + (s.detalle || '')); process.exit(1); }
const { ev, cmd, cerrar } = s;

const listo = async () => {
  for (let i = 0; i < 140; i++) {
    if (await ev('document.readyState === "complete"')) { await dormir(320); return; }
    await dormir(110);
  }
};
const ir = async (u) => { await cmd('Page.navigate', { url: u }); await listo(); };
const url = () => ev('location.href');
const txt = (sel) => ev(`(document.querySelector(${JSON.stringify(sel)})||{}).textContent || ''`);
const clic = async (expr) => { await ev(expr); await dormir(420); };
const errs = () => ev('JSON.stringify(window.__errs || [])');

//  ESPERAR A QUE ALGO EXISTA, no dormir un rato y cruzar los dedos. Un
//  `dormir(650)` fijo pasa casi siempre y falla el dia que la maquina va
//  cargada — y entonces la prueba miente en la direccion peor: dice que la
//  pantalla esta rota cuando lo que iba lento era el reloj.
const esperar = async (sel, ms = 6000) => {
  const t0 = Date.now();
  while (Date.now() - t0 < ms) {
    if (await ev(`!!document.querySelector(${JSON.stringify(sel)})`)) return true;
    await dormir(120);
  }
  return false;
};
//  Pulsar SOLO cuando ya esta ahi. Devuelve false en vez de reventar, para que
//  la linea de diagnostico diga que fallo y no un TypeError sin contexto.
const clicSel = async (sel, ms = 6000) => {
  if (!await esperar(sel, ms)) return false;
  await ev(`document.querySelector(${JSON.stringify(sel)}).click()`);
  await dormir(420);
  return true;
};

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

  // ══ 0 · LA PUERTA · sin enlace, la pantalla existe y nadie llega ═
  await ir(`${BASE}/meta.php?marca=${marca}`);
  await cerrarRecibimiento(ev);
  di('ENTRADA_HAY', await ev(`!!document.querySelector('.tm-mas a[href*="vista=semana"]')`));
  di('ENTRADA_TX', (await ev(`(function(){var a=document.querySelector('.tm-mas a[href*="vista=semana"]');
      return a?a.textContent.replace(/\s+/g,' ').trim():'';})()`)));
  if (await ev(`!!document.querySelector('.tm-mas a[href*="vista=semana"]')`)) {
    await ev(`document.querySelector('.tm-mas a[href*="vista=semana"]').click()`);
    await listo();
    di('ENTRADA_LLEVA_A', await url());
  }

  // ══ 1 · SE LLEGA, Y DICE DONDE ESTOY ═══════════════════════════
  await ir(SEM);
  await cerrarRecibimiento(ev);
  di('URL_1', await url());
  di('PASO_1', (await txt('#smPaso')).trim());
  di('BARRA_N', await ev('document.querySelectorAll("#smBarra i").length'));
  di('MED_360', await ev(MEDIR).then(JSON.stringify));
  await tirar('01_semana_360', 360, 800);

  // ══ 2 · APROBAR · avanza sola y lo dice ════════════════════════
  const hayApr = await ev('!!document.querySelector(".sm-p.on [data-aprobar]")');
  di('POS1_TIENE_APROBAR', hayApr);
  if (hayApr) {
    await clicSel('.sm-p.on [data-aprobar]');
    //  Aprobar escribe por la red: se espera a que la pieza 1 ensene su acuse,
    //  que es la senal de que la respuesta llego.
    await esperar('.sm-p[data-n="1"] [data-hecho]');
    await dormir(400);
    di('TRAS_APROBAR_PASO', (await txt('#smPaso')).trim());
    di('TRAS_APROBAR_URL', await url());
    //  La frase de despues sale de la FECHA de la pieza, no de una plantilla.
    di('TRAS_APROBAR_HECHO',
       (await ev(`(function(){var e=document.querySelector('.sm-p[data-n="1"] [data-hecho] span');
                   return e?e.textContent.trim():'';})()`)));
  }
  await tirar('02_tras_aprobar_360', 360, 800);

  // ══ 3 · AJUSTAR · la hoja, y la pieza detras ═══════════════════
  await ir(SEM + '&pos=2');
  await cerrarRecibimiento(ev);
  di('URL_POS2', await url());
  di('AJUSTAR_HAY', await clicSel('.sm-p.on [data-ajustar]'));
  await esperar('#smHojaC .sm-fila');
  di('HOJA_ABIERTA', await ev('document.querySelector("#smVelo").classList.contains("on")'));
  di('HOJA_TIT', (await txt('#smHojaT')).trim());
  di('HOJA_FILAS', await ev(`JSON.stringify([].map.call(
      document.querySelectorAll('#smHojaC .sm-fila'), function(f){return f.dataset.a;}))`));
  di('HOJA_CUOTA', (await ev(`(function(){var p=document.querySelector('#smHojaC .sm-nota');
      return p?p.textContent.replace(/\\s+/g,' ').trim():'';})()`)));
  di('MED_HOJA_360', await ev(MEDIR).then(JSON.stringify));
  await tirar('03_ajustar_360', 360, 800);

  // ── 3a · TEXTO: se guarda de verdad y se vuelve a la MISMA pieza ──
  await clicSel('#smHojaC .sm-fila[data-a="texto"]');
  const hayTx = await esperar('#smTx');
  di('TEXTO_ABRE', hayTx);
  const NUEVO = 'Texto de relleno editado desde la revision semanal.';
  if (hayTx) {
    await ev(`document.getElementById('smTx').value = ${JSON.stringify(NUEVO)}`);
    await clicSel('#smTxG');
    //  La hoja se cierra sola cuando el guardado vuelve OK. Esa es la senal.
    for (let i = 0; i < 50; i++) {
      if (!await ev('document.querySelector("#smVelo").classList.contains("on")')) break;
      await dormir(150);
    }
  }
  di('TEXTO_HOJA_CERRADA', await ev('!document.querySelector("#smVelo").classList.contains("on")'));
  di('TEXTO_EN_PANTALLA', (await txt('.sm-p.on .sm-cap')).trim());
  di('TEXTO_SIGO_EN_POS', (await txt('#smPaso')).trim());
  di('TEXTO_URL', await url());

  // ── 3b · FECHA Y HORA ──
  await clicSel('.sm-p.on [data-ajustar]');
  await clicSel('#smHojaC .sm-fila[data-a="fecha"]');
  const hayFe = await esperar('#smFe');
  di('FECHA_ABRE', hayFe);
  const F = new Date(Date.now() + 5 * 864e5);
  const iso = F.getFullYear() + '-' + String(F.getMonth() + 1).padStart(2, '0') + '-' +
              String(F.getDate()).padStart(2, '0') + 'T09:30';
  if (hayFe) {
    await ev(`document.getElementById('smFe').value = ${JSON.stringify(iso)}`);
    await clicSel('#smFeG');
    for (let i = 0; i < 50; i++) {
      if (!await ev('document.querySelector("#smVelo").classList.contains("on")')) break;
      await dormir(150);
    }
  }
  di('FECHA_HOJA_CERRADA', await ev('!document.querySelector("#smVelo").classList.contains("on")'));
  di('FECHA_LINEA', (await txt('.sm-p.on .sm-linea')).replace(/\s+/g, ' ').trim());
  di('FECHA_SIGO_EN_POS', (await txt('#smPaso')).trim());

  // ══ 4 · SALIR A LA IMAGEN Y VOLVER A LA MISMA PUBLICACION ══════
  await clicSel('.sm-p.on [data-ajustar]');
  await clicSel('#smHojaC .sm-fila[data-a="arte"]');
  await listo();
  const uArte = await url();
  di('ARTE_URL', uArte);
  di('ARTE_LLEVA_POS', /volver=meta/.test(uArte) && /pos=2/.test(uArte));
  await cerrarRecibimiento(ev);
  //  La salida de vuelta que pinta aprobar2 cuando se vino de Tu Meta.
  const hayVuelta = await ev(`!!document.querySelector('a[href*="vista=semana"]')`);
  di('ARTE_TIENE_VUELTA', hayVuelta);
  if (hayVuelta) {
    await ev(`document.querySelector('a[href*="vista=semana"]').click()`);
    await listo();
    di('ARTE_VUELVE_A', await url());
    di('ARTE_VUELVE_PASO', (await txt('#smPaso')).trim());
  }

  // ══ 5 · «NO PUEDO CON ESTA» · ida y vuelta sin cambiar nada ════
  await ir(SEM + '&pos=2');
  await cerrarRecibimiento(ev);
  const haySust = await ev('!!document.querySelector(".sm-p.on .sm-nopuedo")');
  di('POS2_TIENE_NOPUEDO', haySust);
  if (haySust) {
    await ev('document.querySelector(".sm-p.on .sm-nopuedo").click()');
    await listo();
    const uS = await url();
    di('SUST_URL', uS);
    di('SUST_LLEVA_DESDE', /desde=semana/.test(uS) && /pos=2/.test(uS));
    di('SUST_ES_WIZARD', await ev('!!document.getElementById("wzTren")'));
    await tirar('04_sustituir_360', 360, 800);
    await ev('document.getElementById("wzSalir").click()');
    await listo();
    di('SUST_VUELVE_A', await url());
    di('SUST_VUELVE_PASO', (await txt('#smPaso')).trim());
  }

  // ══ 6 · LA PIEZA COMPROMETIDA · se pregunta ANTES ══════════════
  //  La jugada 3 tiene una pieza programada con fecha futura: va a salir sola.
  //  Sustituir sin preguntar la dejaria viva detras de la nueva.
  await ir(`${BASE}/meta.php?marca=${marca}&vista=sustituir&jugada=${tacComp}&desde=semana&pos=3`);
  await cerrarRecibimiento(ev);
  di('GATE_URL', await url());
  di('GATE_ES_PUERTA', await ev('!document.getElementById("wzTren") && !!document.querySelector(".wz-q")'));
  di('GATE_TIT', (await txt('.wz-q')).trim());
  di('GATE_OPCIONES', await ev(`JSON.stringify([].map.call(
      document.querySelectorAll('.su-opts a'), function(a){return a.querySelector('b').textContent.trim();}))`));
  di('GATE_CUOTA', (await ev(`(function(){var p=document.querySelector('.su-opt.pri small');
      return p?p.textContent.replace(/\\s+/g,' ').trim():'';})()`)));
  di('GATE_CONSERVAR_TX', await ev(`(function(){var a=document.querySelectorAll('.su-opts a');
      return a.length>1?a[1].textContent.replace(/\s+/g,' ').trim():'';})()`));
  di('MED_GATE_360', await ev(MEDIR).then(JSON.stringify));
  await tirar('05_puerta_comprometida_360', 360, 800);

  //  «Conservar» devuelve exactamente donde estaba y no toca nada.
  //  La segunda tarjeta es «Conservar». La primera es la que el dueno vino a
  //  buscar (quitarla), asi que va delante.
  await ev(`document.querySelectorAll('.su-opts a')[1].click()`);
  await listo();
  di('GATE_CONSERVAR_VUELVE', await url());
  di('GATE_CONSERVAR_PASO', (await txt('#smPaso')).trim());

  //  «Quitarla y cambiarla» abre el wizard de siempre, ya con la decision tomada.
  await ir(`${BASE}/meta.php?marca=${marca}&vista=sustituir&jugada=${tacComp}&desde=semana&pos=3`);
  await ev(`document.querySelectorAll('.su-opts a')[0].click()`);
  await listo();
  di('GATE_QUITAR_URL', await url());
  di('GATE_QUITAR_ES_WIZARD', await ev('!!document.getElementById("wzTren")'));
  di('GATE_QUITAR_AVISA', (await ev(`(function(){var e=document.querySelector('.wz-luego');
      return e?e.textContent.replace(/\\s+/g,' ').trim():'';})()`)));

  // ══ 7 · EL CALENDARIO DICE EL ORIGEN ═══════════════════════════
  await ir(`${BASE}/calendario.php?marca=${marca}&vista=mes`);
  await cerrarRecibimiento(ev);
  di('CAL_ORIGENES', await ev(`JSON.stringify([].map.call(
      document.querySelectorAll('.ev-contenido'), function(e){return e.dataset.meta;}))`));

  // ══ 7b · EL AYUDANTE DEL TOKEN, EN EL NAVEGADOR ════════════════
  //  `_crear_wizard.php` hace 16 llamadas a aprobar2.php y solo UNA pone el
  //  token a mano. Con el candado nuevo, las otras 15 moririan en 403 si el
  //  ayudante no existiera — y el wizard se incrusta en DOS paginas, asi que
  //  hay que comprobarlo en las dos. Se hace lo mismo que hace el wizard: un
  //  fetch con FormData SIN csrf desde esa pagina.
  for (const [nombre, pagina] of [['ESTUDIO', 'propuestas.php'], ['APROBAR2', 'aprobar2.php']]) {
    await ir(`${BASE}/${pagina}?marca=${marca}`);
    await cerrarRecibimiento(ev);
    const texto = 'Texto puesto sin token desde ' + nombre + '.';
    const j = await ev(`(function(){
      var fd = new FormData();
      fd.append('accion','editar'); fd.append('id', ${piezaShim}); fd.append('ajax','1');
      fd.append('caption', ${JSON.stringify(texto)});
      return fetch('/crecer/panel/aprobar2.php?marca=${marca}', {method:'POST', body:fd})
        .then(function(r){ return r.text(); });
    })()`);
    di('SHIM_' + nombre, String(j).slice(0, 120));
    di('SHIM_' + nombre + '_TX', texto);
  }

  // ══ 8 · LAS OTRAS DOS PANTALLAS ════════════════════════════════
  await ir(SEM);
  await cerrarRecibimiento(ev);
  await cmd('Emulation.setDeviceMetricsOverride', { width: 414, height: 896, deviceScaleFactor: 1, mobile: true });
  await dormir(600);
  di('MED_414', await ev(MEDIR).then(JSON.stringify));
  await tirar('06_semana_414', 414, 896);

  await cmd('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
  await dormir(600);
  di('MED_1440', await ev(MEDIR).then(JSON.stringify));
  di('ESCRITORIO_LISTA', await ev('document.querySelectorAll("#smLista .sm-li").length'));
  await tirar('07_semana_1440', 1440, 900);

  di('ERRORES', await errs());
  di('OK', 1);
} catch (e) {
  di('OK', 0);
  di('ERROR', e.message);
} finally {
  cerrar();
}
