// ============================================================
//  CRECER — LA PRESENTACION DEL PLAN, EN UN NAVEGADOR DE VERDAD
//  tests/_navegador_presentacion.mjs
//
//  El estado C es la PRIMERA pantalla que ve un dueno con plan nuevo, y la
//  unica cuya accion escribe antes de llevarlo a ningun sitio. Conducirlo en
//  Chrome es lo unico que demuestra tres cosas que el arnes de CLI no puede:
//  que el boton se puede PULSAR a 360x800 (no tapado por Ayuda ni por la barra
//  fija), que al pulsarlo la pantalla se recompone, y que volviendo atras no
//  reaparece el trato ya aceptado.
//
//  La sesion se inyecta por cookie: la fixture la escribe en C:\xampp\tmp, el
//  mismo save_path que usa Apache.
//
//  Imprime lineas CLAVE=valor; quien asierta es la prueba en PHP.
//
//    node tests/_navegador_presentacion.mjs <sid> <marca> <carpeta>
// ============================================================

import { spawn } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const [sid, marca, shots] = process.argv.slice(2);
const BASE = 'http://localhost/crecer';
const perfil = fs.mkdtempSync(path.join(os.tmpdir(), 'navp-'));
const puerto = 9800 + (process.pid % 150);
const dormir = (ms) => new Promise((r) => setTimeout(r, ms));
const di = (k, v) => console.log(k + '=' + String(v).replace(/\r?\n/g, ' '));

const ch = spawn(CHROME, [
  '--headless=new', '--disable-gpu', '--hide-scrollbars', '--no-first-run',
  '--force-device-scale-factor=2', '--window-size=360,800',
  '--font-render-hinting=none', '--disable-lcd-text',
  `--user-data-dir=${perfil}`, `--remote-debugging-port=${puerto}`, 'about:blank',
], { stdio: 'ignore' });

let cdp = null, id = 0;
const pend = new Map();
const cmd = (m, p = {}) => {
  const i = ++id; cdp.send(JSON.stringify({ id: i, method: m, params: p }));
  return new Promise((r, j) => pend.set(i, { r, j, m }));
};
const evaluar = async (expr) => {
  const r = await cmd('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
  if (r.exceptionDetails) throw new Error('JS: ' + (r.exceptionDetails.exception?.description || ''));
  return r.result.value;
};
const url = () => evaluar('location.href');
const ir = async (u) => { await cmd('Page.navigate', { url: u }); await listo(); };
const listo = async () => {
  for (let i = 0; i < 120; i++) {
    if (await evaluar('document.readyState === "complete"')) { await dormir(220); return; }
    await dormir(120);
  }
};
//  Las cuentas de fixture son nuevas: el Recibimiento sale y tapa la pantalla.
//  Se cierra como lo cerraria el dueno, pulsando su propio boton.
const despejar = async () => {
  await evaluar(`(function(){
    var t=['Entendido','¡ENTENDIDO!','Saltar','Cerrar','Listo, ya sé'];
    for (var k=0;k<3;k++){
      [].forEach.call(document.querySelectorAll('button,a'),function(b){
        var s=(b.textContent||'').trim();
        if(t.some(function(x){return s.toLowerCase()===x.toLowerCase();}) && b.offsetParent!==null) b.click();
      });
    }
  })()`);
};
const despejarBien = async () => { await despejar(); await dormir(700); await despejar(); };

//  DOS capturas: la larga se lee, el viewport se juzga. En la de pagina
//  completa nada queda nunca cortado ni tapado, porque el lienzo crece.
const captura = async (nombre, foco) => {
  await despejarBien();
  if (foco) {
    await evaluar("(function(){var e=document.querySelector('" + foco + "');"
                + "if(e) e.scrollIntoView({block:'center'});})()");
    await dormir(450);
  }
  const larga = await cmd('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
  fs.writeFileSync(path.join(shots, nombre + '_completa.png'), Buffer.from(larga.data, 'base64'));
  const vp = await cmd('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
  fs.writeFileSync(path.join(shots, nombre + '.png'), Buffer.from(vp.data, 'base64'));
};

//  La misma medida que el recorrido de aprobacion: numeros, no contadores.
//  Cada control se lleva al centro de la ventana y ahi se comprueba si algo
//  fijo lo tapa; lo que sigue tapado en el centro no hay forma de pulsarlo.
const medir = async () => JSON.parse(await evaluar(`JSON.stringify((function(){
  var W = window.innerWidth, H = window.innerHeight;
  var sel = 'a[href],button,input,select,textarea,[role="button"],[onclick]';
  var vis = [].slice.call(document.querySelectorAll(sel)).filter(function(e){
    if (e.offsetParent === null && getComputedStyle(e).position !== 'fixed') return false;
    var r = e.getBoundingClientRect();
    return r.width > 8 && r.height > 8 && getComputedStyle(e).visibility !== 'hidden';
  });
  function flotante(e){
    for (var p = e; p && p !== document.body; p = p.parentElement) {
      var po = getComputedStyle(p).position;
      if (po === 'fixed' || po === 'sticky') return p;
    }
    return null;
  }
  var capas = vis.filter(function(e){ return flotante(e); });
  var normales = vis.filter(function(e){ return !flotante(e); });
  var tapados = [], fuera = [];
  normales.forEach(function(e){
    e.scrollIntoView({ block: 'center', inline: 'nearest' });
    var a = e.getBoundingClientRect();
    if (a.right > W + 1 || a.left < -1) {
      fuera.push({ t:(e.textContent||'').trim().slice(0,24),
                   l:Math.round(a.left), r:Math.round(a.right) });
    }
    capas.forEach(function(c){
      if (c === e || c.contains(e) || e.contains(c)) return;
      var b = c.getBoundingClientRect();
      if (a.left < b.right-1 && b.left < a.right-1 && a.top < b.bottom-1 && b.top < a.bottom-1) {
        tapados.push({ t:(e.textContent||'').trim().slice(0,24),
                       por:(c.className||c.tagName).toString().trim().slice(0,20),
                       y:Math.round(a.top) });
      }
    });
  });
  window.scrollTo(0, document.documentElement.scrollHeight);
  var barra = capas.map(function(c){ return c.getBoundingClientRect().top; })
                   .filter(function(t){ return t > H * 0.5; });
  var techoFijo = barra.length ? Math.min.apply(null, barra) : H;
  var ultimo = normales.length
    ? Math.max.apply(null, normales.map(function(e){ return e.getBoundingClientRect().bottom; }))
    : 0;
  return {
    ancho_doc: document.documentElement.scrollWidth,
    ancho_vp: W,
    desborde: Math.max(0, document.documentElement.scrollWidth - W),
    controles: vis.length,
    hueco_final: Math.round(techoFijo - ultimo),
    tapados: tapados,
    fuera: fuera
  };
})())`));

try {
  let ws = null;
  for (let i = 0; i < 100; i++) {
    try {
      const r = await fetch(`http://127.0.0.1:${puerto}/json/list`);
      const l = await r.json();
      const p = l.find((t) => t.type === 'page' && t.webSocketDebuggerUrl);
      if (p) { ws = p.webSocketDebuggerUrl; break; }
    } catch { /* aún no */ }
    await dormir(250);
  }
  if (!ws) throw new Error('Chrome no abrió el puerto');
  const sock = new WebSocket(ws);
  await new Promise((r, j) => { sock.addEventListener('open', r); sock.addEventListener('error', j); });
  cdp = sock;
  sock.addEventListener('message', (ev) => {
    const m = JSON.parse(ev.data);
    if (m.id == null) return;
    const p = pend.get(m.id); if (!p) return;
    pend.delete(m.id);
    m.error ? p.j(new Error(m.error.message + ' @ ' + p.m)) : p.r(m.result);
  });

  await cmd('Page.enable');
  await cmd('Runtime.enable');
  await cmd('Network.enable');
  await cmd('Emulation.setDeviceMetricsOverride',
            { width: 360, height: 800, deviceScaleFactor: 2, mobile: true });
  await cmd('Network.setCookie',
            { name: 'PHPSESSID', value: sid, domain: 'localhost', path: '/' });

  // ── 1 · EL TRATO, tal como lo ve un Android de 360 ────────────────────
  await ir(`${BASE}/panel/meta.php?marca=${marca}`);
  await despejarBien();
  di('C_URL', await url());
  di('C_TITULO', await evaluar(`(document.querySelector('.ah-tit')||{}).textContent || ''`));
  di('C_BOTON', await evaluar(`(document.querySelector('#ahEmpezar')||{}).textContent || ''`));
  di('C_TRATO', await evaluar(`document.querySelector('.ah-trato') !== null`));
  di('C_REPARTO', await evaluar(
    `[].map.call(document.querySelectorAll('.ah-reparto div'),function(d){return d.textContent.trim().replace(/\\s+/g,' ');}).join(' | ')`));
  //  Criterio 3 del contrato: nunca dos primarios compitiendo.
  di('C_PRIMARIAS', await evaluar(`document.querySelectorAll('.ah-btn').length`));
  //  Criterio 7: ningun texto de uso normal por debajo de 14px.
  di('C_MIN_PX', await evaluar(`(function(){
    var min = 99;
    [].forEach.call(document.querySelectorAll('.ah-trato, .ah-trato *, .ah-tit, .ah-ins'), function(e){
      if (!e.textContent.trim()) return;
      if (e.children.length && e.tagName !== 'DIV') return;
      var s = parseFloat(getComputedStyle(e).fontSize);
      if (s && s < min) min = s;
    });
    return min;
  })()`));
  //  Criterio 1: la accion dominante se ve sin hacer scroll.
  di('C_ACCION_SIN_SCROLL', await evaluar(`(function(){
    var b = document.querySelector('#ahEmpezar'); if (!b) return 'sin-boton';
    window.scrollTo(0,0);
    var r = b.getBoundingClientRect();
    return (r.top >= 0 && r.bottom <= window.innerHeight) ? 'si' : Math.round(r.bottom) + '/' + window.innerHeight;
  })()`));
  di('C_TECLADO', await evaluar(`(function(){
    var b = document.querySelector('#ahEmpezar'); if (!b) return false;
    b.focus(); return document.activeElement === b;
  })()`));

  const mC = await medir();
  di('C_ANCHO', mC.ancho_doc + '/' + mC.ancho_vp);
  di('C_DESBORDE', mC.desborde);
  di('C_CONTROLES', mC.controles);
  di('C_HUECO_FINAL', mC.hueco_final);
  di('C_TAPADOS', mC.tapados.length);
  di('C_TAPADOS_DET', JSON.stringify(mC.tapados));
  di('C_FUERA', mC.fuera.length);
  di('C_FUERA_DET', JSON.stringify(mC.fuera));
  await captura('meta_plan_por_presentar', '#ahEmpezar');

  // ── 2 · PULSAR EMPEZAR ────────────────────────────────────────────────
  //  Se pulsa el boton de verdad. Si la escritura no ocurriera, la recarga
  //  volveria a ensenar el mismo trato y el dueno entraria en bucle.
  await evaluar(`document.querySelector('#ahEmpezar').click()`);
  await dormir(1800);
  await listo();
  await despejarBien();
  di('POST_URL', await url());
  di('POST_TITULO', await evaluar(`(document.querySelector('.ah-tit')||{}).textContent || ''`));
  di('POST_SIGUE_C', await evaluar(`document.querySelector('#ahEmpezar') !== null`));
  di('POST_SIGUE_TRATO', await evaluar(`document.querySelector('.ah-trato') !== null`));
  di('POST_HAY_ACCION', await evaluar(
    `document.querySelectorAll('.ah-btn, .ah-como > summary').length`));

  const mD = await medir();
  di('POST_DESBORDE', mD.desborde);
  di('POST_TAPADOS', mD.tapados.length);
  di('POST_TAPADOS_DET', JSON.stringify(mD.tapados));
  await captura('meta_plan_presentado', '.ah-btn');

  // ── 3 · VOLVER ATRAS NO RESUCITA EL TRATO ─────────────────────────────
  //  El sello vive en la base, no en la URL: recargar o volver atras tiene
  //  que ensenar lo mismo. Un estado guardado en el historial se le repetiria
  //  al dueno cada vez que pulsara atras.
  await ir(`${BASE}/panel/meta.php?marca=${marca}`);
  await despejarBien();
  di('RECARGA_SIGUE_C', await evaluar(`document.querySelector('#ahEmpezar') !== null`));
  di('RECARGA_TITULO', await evaluar(`(document.querySelector('.ah-tit')||{}).textContent || ''`));

  di('OK', 1);
} catch (e) {
  di('ERROR', e.message);
  di('OK', 0);
} finally {
  ch.kill();
  await dormir(400);
  try { fs.rmSync(perfil, { recursive: true, force: true }); } catch { /* Windows */ }
}
