// ============================================================
//  CRECER — EL RECORRIDO DE APROBACION, EN UN NAVEGADOR DE VERDAD
//  tests/_navegador.mjs
//
//  aprobar2 es el camino principal de aprobacion y no habia forma honesta de
//  probarlo: bajo el arnes de CLI no emite cuerpo, y una busqueda en el fuente
//  solo demuestra que alguien escribio una linea. Aqui se conduce Chrome
//  contra el servidor local: se entra a Tu Meta, se pulsa la accion dominante,
//  se aprueba la pieza y se comprueba a donde se vuelve.
//
//  La sesion se inyecta por cookie: la fixture la escribe en C:\xampp\tmp, que
//  es el mismo save_path que usa Apache. Nada de teclear contraseñas.
//
//  Imprime lineas CLAVE=valor; quien asierta es la prueba en PHP.
//
//    node tests/_navegador.mjs <sid> <marca> <pieza> <pieza_carrusel> <carpeta>
// ============================================================

import { spawn } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const [sid, marca, pieza, carr, shots] = process.argv.slice(2);
const BASE = 'http://localhost/crecer';
const perfil = fs.mkdtempSync(path.join(os.tmpdir(), 'nav-'));
const puerto = 9500 + (process.pid % 300);
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
//  Las cuentas de fixture son nuevas, asi que salta el Recibimiento y algun
//  modal se queda abierto. Sin despedirlos, la captura documenta el tour y no
//  la jerarquia que se quiere revisar. Se cierran como los cerraria el dueño:
//  pulsando su propio boton.
const despejar = async () => {
  await evaluar(`(function(){
    var t=['Entendido','¡ENTENDIDO!','Saltar','Cerrar','Listo, ya sé'];
    for (var k=0;k<3;k++){
      [].forEach.call(document.querySelectorAll('button,a'),function(b){
        var s=(b.textContent||'').trim();
        if(t.some(function(x){return s.toLowerCase()===x.toLowerCase();}) && b.offsetParent!==null) b.click();
      });
      // Los modales de vista previa cierran con una × sin texto: hay que
      // pulsarla por su aria-label o su clase, no por su contenido.
      [].forEach.call(document.querySelectorAll('[aria-label*="errar"],.cerrar,.close,.pw-x,.x,.prev-x'),
        function(b){ if(b.offsetParent!==null) b.click(); });
      [].forEach.call(document.querySelectorAll('.tour,.tour-ov,#crOv,.modal,.ov,.prev-ov,[class*="spotlight"],[class*="overlay"],.ayuda-fab,#ayudaFab'),
        function(e){ e.classList.remove('on'); e.style.display='none'; });
    }
  })()`);
  await dormir(350);
};
//  Dos pasadas con espera: al cerrar el modal aparece el Recibimiento detras, y
//  una sola ronda documentaba el tour en vez de la pantalla.
const despejarBien = async () => { await despejar(); await dormir(700); await despejar(); };
//  DOS capturas por pantalla. La de pagina completa sirve para leer el
//  contenido, pero MIENTE sobre lo que se ve: en ella nada queda cortado ni
//  tapado por lo fijo, porque el lienzo crece. Lo que hay que revisar es el
//  viewport de 360x800 tal cual, con la barra y el boton de Ayuda encima.
//  La captura se encuadra DONDE ESTA LA ACCION. Disparada con la pagina
//  arriba documentaba lo de abajo tapado por la barra, que es normal en
//  cualquier pagina larga y no dice nada de si se puede pulsar.
const captura = async (nombre, foco) => {
  await despejarBien();
  if (foco) {
    await evaluar("(function(){var e=document.querySelector('" + foco + "');"
                + "if(e) e.scrollIntoView({block:'center'});})()");
    await dormir(450);
  }
  const larga = await cmd('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
  fs.writeFileSync(path.join(shots, nombre + '_completa.png'), Buffer.from(larga.data, 'base64'));
  // Sin clip: sus coordenadas son de PAGINA, no de viewport, asi que tras
  // hacer scroll capturaba una franja vacia. Sin el, sale el viewport tal cual.
  const vp = await cmd('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
  fs.writeFileSync(path.join(shots, nombre + '.png'), Buffer.from(vp.data, 'base64'));
};

//  LA MEDIDA, no un contador. Devuelve numeros comprobables: cuanto desborda,
//  que controles quedan bajo las capas fijas y cuales se salen del viewport.
//  El detector anterior solo comparaba .btn entre si — por eso daba verde
//  mientras Ayuda y la barra tapaban botones de verdad.
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

  //  UNA BARRA FIJA SIEMPRE TAPA LO QUE HAYA A SU ALTURA: medir en una sola
  //  posicion de scroll acusaria a media pantalla. Lo que de verdad importa es
  //  si el control se puede ALCANZAR — asi que cada uno se lleva al centro de
  //  la ventana y ahi se comprueba. Lo que sigue tapado en el centro, con el
  //  FAB abajo a la derecha y la barra abajo del todo, no hay forma de pulsarlo.
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

  //  Y EL FINAL DE LA PAGINA: con el scroll al tope, lo ultimo tiene que quedar
  //  POR ENCIMA de la barra. Es lo que comprueba que el hueco inferior existe.
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
    hueco_final: Math.round(techoFijo - ultimo),   // >=0 = lo ultimo queda libre
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

  // ── 1 · TU META: la acción dominante ──────────────────────────────────
  await ir(`${BASE}/panel/meta.php?marca=${marca}`);
  di('META_URL', await url());
  di('AHORA_ANTES', await evaluar(`(document.querySelector('.ah-tit')||{}).textContent || ''`));
  const destino = await evaluar(`(document.querySelector('.ah-btn[href]')||{}).href || ''`);
  di('ACCION_HREF', destino);
  di('PRIMARIAS_META', await evaluar(`document.querySelectorAll('.ah-btn').length`));

  // ── 2 · Abre la pieza EXACTA ──────────────────────────────────────────
  if (destino) { await ir(destino); }
  di('APROBAR_URL', await url());
  di('PIEZA_EN_PANTALLA', await evaluar(
    `!!document.querySelector('form button[value="aprobar"]')`));
  di('PIEZA_ID_EN_FORM', await evaluar(
    `(function(){var b=document.querySelector('form button[value="aprobar"]');
       return b? (b.form.querySelector('input[name="id"]')||{}).value : ''; })()`));
  //  El modal de ?ver= tiene que abrirse UNA vez. Se cierra como lo cerraria
  //  el dueño y se espera: si vuelve, es que ?ver= sigue en la URL.
  di('PREV_ABIERTO_AL_LLEGAR', await evaluar("document.querySelector('.prev-ov.show')!==null"));
  di('AYUDA_CON_MODAL', await evaluar("(function(){var f=document.querySelector('.ay-fab');"
     + "return f? getComputedStyle(f).display : 'sin-fab';})()"));
  await evaluar("(function(){var x=document.querySelector('.prev-x'); if(x) x.click();})()");
  await dormir(1400);
  di('PREV_CERRADO', await evaluar("document.querySelector('.prev-ov.show')===null"));
  di('URL_SIN_VER', await evaluar("!/[?&]ver=/.test(location.search)"));
  di('AYUDA_TRAS_CERRAR', await evaluar("(function(){var f=document.querySelector('.ay-fab');"
     + "return f? getComputedStyle(f).display : 'sin-fab';})()"));
  //  LOS DOS CAMINOS QUE UN REEMPLAZO DEMASIADO ANCHO ME ROMPIO.
  //  a) El fondo del brief cerraba la VISTA PREVIA en vez del brief.
  await evaluar("(function(){ if(typeof abrirBrief==='function') abrirBrief(); })()");
  await dormir(400);
  di('BRIEF_ABIERTO', await evaluar("document.querySelector('#briefov.show')!==null"));
  di('BRIEF_AYUDA_OCULTA', await evaluar(
    "(function(){var f=document.querySelector('.ay-fab');return f?getComputedStyle(f).display:'sin-fab';})()"));
  await evaluar("(function(){var o=document.getElementById('briefov');"
              + "if(o) o.dispatchEvent(new MouseEvent('click',{bubbles:true}));})()");
  await dormir(500);
  di('BRIEF_CERRADO', await evaluar("document.querySelector('#briefov.show')===null"));
  di('BRIEF_NO_TOCO_PREV', await evaluar("document.querySelector('#prevov.show')===null"));
  di('BRIEF_AYUDA_VUELVE', await evaluar(
    "(function(){var f=document.querySelector('.ay-fab');return f?getComputedStyle(f).display:'sin-fab';})()"));

  //  b) Publicar desde la vista previa cerraba a mano y dejaba body.modal-abierto
  //     puesto: Ayuda se quedaba escondida para siempre. Aqui se abre la vista
  //     previa, se publica y se mira el ESTADO — con confirm y fetch neutralizados,
  //     que ni se pregunta ni se publica nada de verdad.
  await evaluar("(function(){ window.confirm=function(){return true;};"
              + "window.fetch=function(){return new Promise(function(){});}; })()");
  await evaluar("(function(){var a=document.querySelector('.prevlink');"
              + "if(a){var c=a.closest('.post');openPrev(a.dataset.img,a.dataset.copy,c?c.dataset.id:null);}})()");
  await dormir(400);
  di('PUB_PREV_ABIERTO', await evaluar("document.querySelector('#prevov.show')!==null"));
  await evaluar("(function(){ if(typeof publicarPrev==='function') publicarPrev('instagram'); })()");
  await dormir(600);
  di('PUB_PREV_CERRADO', await evaluar("document.querySelector('#prevov.show')===null"));
  di('PUB_BODY_LIMPIO', await evaluar("!document.body.classList.contains('modal-abierto')"));
  di('PUB_AYUDA_VUELVE', await evaluar(
    "(function(){var f=document.querySelector('.ay-fab');return f?getComputedStyle(f).display:'sin-fab';})()"));

  //  Recargar deja la pagina limpia para la captura (se toco fetch y confirm).
  await ir(BASE + '/panel/aprobar2.php?marca=' + marca + '&tab=revisar&volver=meta');
  await captura('aprobacion', 'form button[value=\"aprobar\"]');
  var mAp = await medir();
  di('APROB_DESBORDE', mAp.desborde);
  di('APROB_TAPADOS', mAp.tapados.length);
  di('APROB_TAPADOS_DET', JSON.stringify(mAp.tapados));
  di('APROB_FUERA', mAp.fuera.length);
  di('PRIMARIAS_APROBAR', await evaluar(`document.querySelectorAll('.btn-ok').length`));

  // ── 3 · Aprobar de verdad ─────────────────────────────────────────────
  await evaluar(`(function(){
    var b=document.querySelector('form button[value="aprobar"]'); if(b) b.click(); })()`);
  for (let i = 0; i < 60; i++) {          // el retorno lo hace el JS tras el fetch
    const u = await url();
    if (u.indexOf('meta.php') !== -1) break;
    await dormir(300);
  }
  await listo();
  di('VUELTA_URL', await url());
  di('ACUSE', await evaluar(`(document.querySelector('.ah-hecho')||{}).textContent || ''`));
  di('AHORA_DESPUES', await evaluar(`(document.querySelector('.ah-tit')||{}).textContent || ''`));
  // «Se recalculo» no es que cambie el titulo: con dos piezas esperando, el
  // estado sigue siendo F y apunta a la SIGUIENTE. Lo que prueba el recalculo
  // es que la accion ya no lleva a la pieza que se acaba de aprobar.
  di('ACCION_HREF_DESPUES', await evaluar(`(document.querySelector('.ah-btn[href]')||{}).href || ''`));

  // ── 4 · La salida manual, sin afirmar nada ────────────────────────────
  await ir(`${BASE}/panel/aprobar2.php?marca=${marca}&volver=meta`);
  const manual = await evaluar(
    `(function(){var a=[].find.call(document.querySelectorAll('a'),
       x=>/Volver a tu meta/i.test(x.textContent||'')); return a? a.href : ''; })()`);
  di('SALIDA_MANUAL_HREF', manual);
  if (manual) { await ir(manual); di('SALIDA_MANUAL_URL', await url());
                di('SALIDA_MANUAL_ACUSE', await evaluar(`(document.querySelector('.ah-hecho')||{}).textContent || ''`)); }

  // ── 5 · JERARQUIA: una sola accion primaria por pantalla ──────────────
  //  El reel y el carrusel no se terminan de verdad aqui — eso es Shotstack y
  //  las redes del cliente. Lo que se comprueba es LO NUESTRO: que al llegar al
  //  resultado no compitan dos botones principales. El paso final se fuerza por
  //  JS con datos de relleno; la interfaz es exactamente la misma.
  await ir(BASE + '/panel/reels.php?marca=' + marca + '&volver=meta');
  await evaluar("(function(){try{showDone({video_url:'',hook:'Relleno',resumen:'Relleno',duracion:12,guardado:true,copy:'Texto de relleno'});}catch(e){}})()");
  await dormir(600);
  await captura('reel_terminado', '#rVolverMeta');
  di('REEL_VUELTA_PRIMARIA', await evaluar(
    "/btn-go/.test((document.getElementById('rVolverMeta')||{}).className||'')"));
  di('REEL_PUB_SECUNDARIA', await evaluar(
    "/btn-ghost/.test((document.getElementById('rpub')||{}).className||'')"));
  di('REEL_PRIMARIAS', await evaluar(
    "[].slice.call(document.querySelectorAll('.btn-go')).filter(function(x){return x.offsetParent!==null;}).length"));
  var mReel = await medir();
  di('REEL_DESBORDE', mReel.desborde);
  di('REEL_ANCHO', mReel.ancho_doc + '/' + mReel.ancho_vp);
  di('REEL_CONTROLES', mReel.controles);
  di('REEL_HUECO_FINAL', mReel.hueco_final);
  di('REEL_TAPADOS', mReel.tapados.length);
  di('REEL_TAPADOS_DET', JSON.stringify(mReel.tapados));
  di('REEL_FUERA', mReel.fuera.length);
  di('REEL_FUERA_DET', JSON.stringify(mReel.fuera));
  di('REEL_SOLAPES', await evaluar(
    "(function(){var b=[].slice.call(document.querySelectorAll('.btn')).filter(function(x){return x.offsetParent!==null;});var s=0;for(var i=0;i<b.length;i++){for(var j=i+1;j<b.length;j++){var A=b[i].getBoundingClientRect(),B=b[j].getBoundingClientRect();if(A.left<B.right-1&&B.left<A.right-1&&A.top<B.bottom-1&&B.top<A.bottom-1)s++;}}return s;})()"));

  await ir(BASE + '/panel/carrusel.php?marca=' + marca + '&id=' + carr + '&volver=meta');
  await evaluar("(function(){var w=document.getElementById('wz');if(!w)return;[].forEach.call(w.querySelectorAll('.wz-p'),function(p){p.classList.remove('on');p.style.display='none';});var f=w.querySelector('.wz-fin');if(f){f.classList.add('on');f.style.display='';}})()");
  await dormir(500);
  await captura('carrusel_programado', '.wz-fin .wz-go');
  di('CARR_PRIMARIAS', await evaluar(
    "[].slice.call(document.querySelectorAll('.wz-fin .wz-go')).filter(function(x){return x.offsetParent!==null;}).length"));
  di('CARR_VUELTA', await evaluar("(document.querySelector('.wz-fin .wz-go')||{}).href || ''"));
  var mCarr = await medir();
  di('CARR_DESBORDE', mCarr.desborde);
  di('CARR_TAPADOS', mCarr.tapados.length);
  di('CARR_TAPADOS_DET', JSON.stringify(mCarr.tapados));
  di('CARR_FUERA', mCarr.fuera.length);
  di('CARR_SOLAPES', await evaluar(
    "(function(){var b=[].slice.call(document.querySelectorAll('.wz-fin .wz-go, .wz-fin .btn')).filter(function(x){return x.offsetParent!==null;});var s=0;for(var i=0;i<b.length;i++){for(var j=i+1;j<b.length;j++){var A=b[i].getBoundingClientRect(),B=b[j].getBoundingClientRect();if(A.left<B.right-1&&B.left<A.right-1&&A.top<B.bottom-1&&B.top<A.bottom-1)s++;}}return s;})()"));

  di('OK', 1);
} catch (e) {
  di('ERROR', e.message);
  di('OK', 0);
} finally {
  ch.kill();
  await dormir(400);
  try { fs.rmSync(perfil, { recursive: true, force: true }); } catch { /* Windows */ }
}
