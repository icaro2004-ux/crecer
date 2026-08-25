// ============================================================
//  CRECER — EL RECORRIDO DE CREAR LA META, EN UN NAVEGADOR
//  tests/_wizard_probe.mjs
//
//  Lo que aqui se comprueba no se puede comprobar leyendo el fuente: que el
//  dueño pueda ir y volver por el wizard SIN perder lo que ya contesto, que
//  pueda salir a su Biblioteca y regresar al mismo sitio, y que hasta el
//  ultimo boton no se escriba nada.
//
//  Y se mide la pantalla. A 360x800, en la mano, una decision que hay que ir a
//  buscar desplazando no se toma.
//
//    node tests/_wizard_probe.mjs <sid> <marca> <carpeta>
//
//  Imprime CLAVE=valor. Quien asierta es tests/test_meta_wizard_recorrido.php.
// ============================================================

import { abrirChrome, dormir, cerrarRecibimiento } from './_chrome.mjs';
import fs from 'node:fs';

const [sid, marca, shots] = process.argv.slice(2);
const BASE = 'http://localhost/crecer/panel';
const WIZ  = `${BASE}/meta.php?marca=${marca}&vista=wizard`;
const di = (k, v) => console.log(k + '=' + String(v).replace(/\r?\n/g, ' '));

const SONDA = `
  window.__errs = [];
  window.__alertas = 0;
  addEventListener('error', function (e) { window.__errs.push(String(e.message)); });
  addEventListener('unhandledrejection', function (e) { window.__errs.push('promesa: ' + e.reason); });
  (function (o) { console.error = function () {
      window.__errs.push([].slice.call(arguments).join(' ')); o.apply(console, arguments); }; })(console.error);
  window.alert = function () { window.__alertas++; };
`;

//  LA MEDIDA de un paso del wizard.
const MEDIR = `(function () {
  var vis = function (el) { var r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0 && getComputedStyle(el).visibility !== 'hidden'; };
  var caja = document.querySelector('.wz');
  var out = { hay: !!caja, w: innerWidth, h: innerHeight, horiz: 0,
              chicos: [], finos: [], primarias: 0 };
  if (!caja) return out;

  out.horiz = Math.max(0, document.documentElement.scrollWidth - innerWidth);

  //  La capa abierta ES la pantalla; si no, el paso visible.
  var hoja = document.querySelector('.wz-hoja-velo.on');
  var zona = hoja || caja.querySelector('.wz-p.on') || caja;

  var bn = document.querySelector('.botnav');
  var techo = innerHeight - (bn && vis(bn) ? bn.getBoundingClientRect().height : 0);
  out.techo = Math.round(techo);

  [].forEach.call(zona.querySelectorAll('button, a, input, textarea, select'), function (el) {
    if (!vis(el)) return;
    var r = el.getBoundingClientRect();
    if (r.width < 44 || r.height < 44)
      out.chicos.push((el.className || el.tagName) + ' ' + Math.round(r.width) + 'x' + Math.round(r.height));
    if (r.right > innerWidth + 1 || r.left < -1)
      out.chicos.push('FUERA ' + (el.className || el.tagName));
  });

  [].forEach.call(zona.querySelectorAll('p, b, span, button, a, li, small, h1'), function (el) {
    if (!vis(el)) return;
    var t = (el.textContent || '').trim();
    if (t.length < 2) return;
    var propio = [].slice.call(el.childNodes)
      .some(function (n) { return n.nodeType === 3 && n.textContent.trim().length > 1; });
    if (!propio) return;
    var fs = parseFloat(getComputedStyle(el).fontSize);
    if (fs < 14) out.finos.push(t.slice(0, 24) + ' @' + fs);
  });

  //  UNA sola primaria, y visible sin desplazar.
  //  Con la capa abierta, la del paso queda DETRAS del velo: contarla como una
  //  segunda primaria era contar un boton que nadie puede pulsar.
  var pri = [].filter.call(
    (hoja ? hoja : document).querySelectorAll(hoja ? '.tm-btn' : '.wz-nav .tm-btn'), vis);
  out.primarias = pri.length;
  if (pri.length) {
    var r = pri[0].getBoundingClientRect();
    //  Un elemento dentro de una capa FIJA ya viene en coordenadas de
    //  viewport: sumarle el scroll de la pagina lo mandaba a una posicion
    //  imaginaria muy por debajo del techo. Solo se suma cuando la pantalla
    //  que se mide es la pagina.
    var off = hoja ? 0 : scrollY;
    out.priVisible = (r.top + off) >= 0 && (r.top + off + r.height) <= techo;
    out.priRect = Math.round(r.top + off) + '..' + Math.round(r.top + off + r.height);
    var c = document.elementFromPoint(r.left + r.width / 2, Math.min(r.top + r.height / 2, techo - 2));
    out.priTapada = !(c && (c === pri[0] || pri[0].contains(c)));
    //  Ayuda no puede sentarse encima de la decision.
    var fab = document.querySelector('.ay-fab');
    out.priBajoAyuda = false;
    if (fab && vis(fab) && getComputedStyle(fab).opacity !== '0') {
      var f = fab.getBoundingClientRect();
      out.priBajoAyuda = !(r.right < f.left || r.left > f.right || r.bottom < f.top || r.top > f.bottom);
    }
  }
  //  NADA QUEDA ATRAPADO DEBAJO DE LA BARRA FIJA.
  //
  //  La primera version de esto contaba todo lo que cayera bajo el pliegue, y
  //  eso no es un defecto: una pagina se desplaza. Lo que si es un defecto es
  //  que un control quede DETRAS de la barra de abajo cuando ya no se puede
  //  desplazar mas — entonces no se alcanza nunca. Se mide asi: con la pagina
  //  al final del scroll, el ultimo control tiene que quedar por encima.
  //  QUE SE DESPLAZA, Y HASTA DONDE. En la pagina, el documento; dentro de una
  //  capa, el cuerpo de la hoja — que tiene su propio scroll. Medir el de la
  //  pagina con la capa abierta daba «inalcanzable» a un boton que solo habia
  //  que bajar dos dedos dentro de la hoja.
  var suelo, restante;
  if (hoja) {
    var cuerpo = hoja.querySelector('.cuerpo');
    var caja2  = hoja.querySelector('.wz-hoja');
    suelo    = caja2 ? caja2.getBoundingClientRect().bottom : techo;
    restante = cuerpo ? (cuerpo.scrollHeight - cuerpo.clientHeight - cuerpo.scrollTop) : 0;
  } else {
    suelo    = techo;
    restante = document.documentElement.scrollHeight - innerHeight - scrollY;
  }
  out.atrapados = [].filter.call(zona.querySelectorAll('button, a, input, textarea'), function (el) {
    if (!vis(el)) return false;
    var r = el.getBoundingClientRect();
    //  Lo que ya se ve por encima del suelo, no esta atrapado.
    if (r.bottom <= suelo + 1) return false;
    //  Y lo que se destapa desplazando, tampoco.
    return (r.bottom - suelo) > restante + 1;
  }).length;
  return out;
})()`;

//  Lo que el wizard tiene contestado AHORA, leido de la pantalla.
const ESTADO = `(function () {
  var sel = document.querySelector('.wz-obj.sel');
  var fch = document.querySelector('#wzFecha .wz-chip.sel');
  return JSON.stringify({
    paso: (document.querySelector('#wzEt') || {}).textContent || '',
    objetivo: sel ? sel.dataset.obj : '',
    cantidad: (document.getElementById('cantidad') || {}).value || '',
    dias: fch ? fch.dataset.dias : '',
    material: (document.getElementById('wzMatEstado') || {}).textContent || ''
  });
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
const esperar = async (sel, ms = 6000) => {
  const t0 = Date.now();
  while (Date.now() - t0 < ms) {
    if (await ev(`!!document.querySelector(${JSON.stringify(sel)})`)) return true;
    await dormir(120);
  }
  return false;
};
const clic = async (sel) => {
  if (!await esperar(sel)) return false;
  await ev(`document.querySelector(${JSON.stringify(sel)}).click()`);
  await dormir(380);
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

  // ══ 1 · IDA · objetivo → cantidad → fecha → material ═══════════
  await ir(WIZ);
  await cerrarRecibimiento(ev);
  di('P1_PASO', (await ev(`(document.querySelector('#wzEt')||{}).textContent||''`)).trim());
  di('P1_OBJETIVOS', await ev(`document.querySelectorAll('.wz-obj').length`));
  di('MED_P1', await ev(MEDIR).then(JSON.stringify));
  await tirar('wiz_1_objetivo_360', 360, 800);

  await clic('.wz-obj[data-obj="pedidos"]');
  await clic('#sigue');
  di('P2_PASO', (await ev(`(document.querySelector('#wzEt')||{}).textContent||''`)).trim());
  //  La pregunta cambia con el objetivo: es del dominio, no un texto fijo.
  di('P2_PREGUNTA', (await ev(`(document.querySelector('#wzQ')||{}).textContent||''`)).trim());
  di('P2_UNIDAD', (await ev(`(document.querySelector('#wzUnidad')||{}).textContent||''`)).trim());
  await ev(`(function(){var c=document.getElementById('cantidad');
      c.value='25'; c.dispatchEvent(new Event('input',{bubbles:true}));})()`);
  await dormir(250);
  di('MED_P2', await ev(MEDIR).then(JSON.stringify));
  await tirar('wiz_2_cantidad_360', 360, 800);

  await clic('#sigue');
  di('P3_PASO', (await ev(`(document.querySelector('#wzEt')||{}).textContent||''`)).trim());
  di('P3_HAY_FECHA', await ev(`!!document.querySelector('#wzFecha .wz-chip')`));
  di('P3_FECHA_CLARA', (await ev(`(document.querySelector('#wzFechaClara')||{}).textContent||''`)).trim());
  await clic('#wzFecha .wz-chip[data-dias="60"]');
  di('MED_P3', await ev(MEDIR).then(JSON.stringify));
  await tirar('wiz_3_fecha_360', 360, 800);

  await clic('#sigue');
  di('P4_PASO', (await ev(`(document.querySelector('#wzEt')||{}).textContent||''`)).trim());
  di('P4_PRIMARIA', (await ev(`(document.querySelector('#sigue')||{}).textContent||''`)).trim());
  di('P4_SECUNDARIA', await ev(`!!document.querySelector('#wzMatAnadir')`));
  di('P4_BIBLIO', await ev(`!!document.querySelector('#wzMatBiblio')`));
  di('P4_TEXTO', (await ev(`(document.querySelector('.wz-p.on')||{}).innerText||''`)).replace(/\s+/g, ' ').trim().slice(0, 400));
  di('MED_P4', await ev(MEDIR).then(JSON.stringify));
  await tirar('wiz_4_material_360', 360, 800);

  di('ESTADO_IDA', await ev(ESTADO));

  // ══ 2 · ATRAS · nada se pierde ════════════════════════════════
  await clic('#atras');   // → fecha
  await clic('#atras');   // → cantidad
  await clic('#atras');   // → objetivo
  di('ESTADO_VUELTA', await ev(ESTADO));
  di('ATRAS_OBJ_SEL', await ev(`!!document.querySelector('.wz-obj[data-obj="pedidos"].sel')`));

  //  Y hacia delante otra vez: sigue todo puesto.
  await clic('#sigue'); await clic('#sigue'); await clic('#sigue');
  di('ESTADO_REIDA', await ev(ESTADO));

  // ══ 3 · LA CAPA DE AJUSTES · opcional, con salida ══════════════
  const hayAj = await ev(`!!document.querySelector('#wzAjustes')`);
  di('AJUSTES_HAY', hayAj);
  if (hayAj) {
    await clic('#wzAjustes');
    di('AJUSTES_ABIERTA', await ev(`!!document.querySelector('.wz-hoja-velo.on')`));
    di('MED_AJUSTES', await ev(MEDIR).then(JSON.stringify));
    await tirar('wiz_5_ajustes_360', 360, 800);
    await clic('#wzHojaCerrar');
    di('AJUSTES_CERRADA', await ev(`!document.querySelector('.wz-hoja-velo.on')`));
    di('ESTADO_TRAS_AJUSTES', await ev(ESTADO));
  }

  // ══ 4 · SUBIR MATERIAL SIN SALIR DE LA PANTALLA ═══════════════
  //  Se sube un PNG diminuto de verdad, por el mismo handler que usa
  //  Biblioteca. No se navega: por eso no hay nada que perder.
  const subio = await ev(`(function(){
    var i = document.getElementById('wzMatFile');
    if (!i) return 'sin-input';
    var b = atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    var u = new Uint8Array(b.length); for (var k=0;k<b.length;k++) u[k]=b.charCodeAt(k);
    var dt = new DataTransfer();
    dt.items.add(new File([u], 'prueba-wizard.png', {type:'image/png'}));
    i.files = dt.files;
    i.dispatchEvent(new Event('change', {bubbles:true}));
    return 'lanzado';
  })()`);
  di('SUBIDA_LANZADA', subio);
  for (let i = 0; i < 60; i++) {
    const t = await ev(`(document.getElementById('wzMatEstado')||{}).textContent||''`);
    if (/1|foto/i.test(t)) break;
    await dormir(250);
  }
  di('TRAS_SUBIR_ESTADO', (await ev(`(document.getElementById('wzMatEstado')||{}).textContent||''`)).trim());
  di('TRAS_SUBIR_PASO', (await ev(`(document.querySelector('#wzEt')||{}).textContent||''`)).trim());
  di('TRAS_SUBIR_RESPUESTAS', await ev(ESTADO));
  await tirar('wiz_6_material_con_foto_360', 360, 800);

  // ══ 5 · IR A BIBLIOTECA Y VOLVER · el estado aguanta ══════════
  const hrefBib = await ev(`(document.querySelector('#wzMatBiblio')||{}).href||''`);
  di('BIBLIO_HREF', hrefBib);
  if (hrefBib) {
    await ev(`document.querySelector('#wzMatBiblio').click()`);
    await listo();
    di('BIBLIO_URL', await ev('location.href'));
    di('BIBLIO_TIENE_VUELTA', await ev(`!!document.querySelector('a[href*="vista=wizard"]')`));
    await tirar('wiz_7_biblioteca_360', 360, 800);
    //  Se vuelve como volveria el dueño: por el enlace que la pantalla ofrece.
    const volvio = await clic('a[href*="vista=wizard"]');
    await listo();
    di('BIBLIO_VUELVE_A', await ev('location.href'));
    di('BIBLIO_VUELVE_PASO', (await ev(`(document.querySelector('#wzEt')||{}).textContent||''`)).trim());
    di('BIBLIO_VUELVE_ESTADO', await ev(ESTADO));
    di('BIBLIO_VOLVIO', volvio);
  }

  // ══ 6 · SALIR SIN CONFIRMAR ══════════════════════════════════
  //  El enlace de salida tiene que decir la verdad sobre lo que se guardo.
  di('SALIR_TX', (await ev(`(document.querySelector('#wzSalir')||{}).textContent||''`)).trim());
  await ev(`document.querySelector('#wzSalir').click()`);
  await listo();
  di('SALIR_URL', await ev('location.href'));
  di('SALIR_BORRO_BORRADOR', await ev(
    `(function(){try{return !sessionStorage.getItem('crecer.wizard.meta.${marca}');}catch(e){return true;}})()`));

  // ══ 7 · LAS OTRAS DOS PANTALLAS ══════════════════════════════
  for (const [etq, w, h] of [['414', 414, 896], ['1440', 1440, 900]]) {
    await cmd('Emulation.setDeviceMetricsOverride',
              { width: w, height: h, deviceScaleFactor: 1, mobile: w < 900 });
    await ir(WIZ);
    await cerrarRecibimiento(ev);
    //  SE EMPIEZA LIMPIO. El wizard recuerda por donde ibas —eso es lo que se
    //  quiere— y en la pasada anterior quedo guardado el paso 4. Al reentrar
    //  volvia alli, y entonces el primer clic a ciegas de esta sonda no era
    //  «siguiente»: era CREAR LA META. Asi se colo una meta y una llamada al
    //  modelo en una prueba que afirma que no crea nada.
    await ev(`try{ sessionStorage.clear(); }catch(e){}`);
    await ir(WIZ);
    await cerrarRecibimiento(ev);
    di('MED_P1_' + etq, await ev(MEDIR).then(JSON.stringify));
    await clic('.wz-obj[data-obj="pedidos"]');
    await clic('#sigue');
    await ev(`(function(){var c=document.getElementById('cantidad');
        c.value='25'; c.dispatchEvent(new Event('input',{bubbles:true}));})()`);
    await clic('#sigue');
    await clic('#sigue');
    di('MED_P4_' + etq, await ev(MEDIR).then(JSON.stringify));
    await tirar('wiz_4_material_' + etq, w, h);
  }

  di('ALERTAS', await ev('window.__alertas || 0'));
  di('ERRORES', await ev('JSON.stringify(window.__errs || [])'));
  di('OK', 1);
} catch (e) {
  di('OK', 0); di('ERROR', e.message);
} finally {
  cerrar();
}
