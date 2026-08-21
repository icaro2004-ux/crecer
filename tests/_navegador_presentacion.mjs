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
//  etapa: '' = el recorrido de la presentacion · 'cuota' = solo el limite.
//  Son dos corridas distintas a proposito: para ver el aviso hay que agotar el
//  mes, y hacerlo antes del recorrido normal cambiaria lo que ese recorrido
//  mide. Mezclarlas daria numeros que no corresponden a ninguna pantalla real.
const [sid, marca, shots, etapa] = process.argv.slice(2);
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
  //  EL SUELO: la barra de abajo, definida con precision y no "lo primero fijo
  //  que este en la mitad de abajo".
  //
  //  Esa definicion vaga fue un error caro: elegia un enlace del CAJON LATERAL
  //  (fijo, y a media altura) como si fuera una barra inferior, y devolvia
  //  -269px de deficit inventado. Ese numero se creyo, y se "arreglo" metiendo
  //  300px de vacio en la pantalla de todos. Un numero sin nombre no se puede
  //  discutir: solo obedecer.
  //
  //  Una barra de abajo es: contenedor fijo, PEGADO al borde inferior, ancho de
  //  media pantalla para arriba, y que no sea una capa a pantalla completa.
  var suelo = H, suelo_que = null;
  vis.forEach(function(e){
    var c = flotante(e); if (!c) return;
    var b = c.getBoundingClientRect();
    if (b.bottom < H - 4)     return;   // no toca el borde de abajo
    if (b.right <= 0 || b.left >= W) return;   // fuera de pantalla (cajon cerrado)
    if (b.width  < W * 0.5)   return;   // no es una barra, es un boton
    if (b.height > H * 0.4)   return;   // es una capa entera, no una barra
    if (b.top >= suelo) return;
    suelo = Math.round(b.top);
    suelo_que = (c.className || c.tagName).toString().trim().slice(0,20) + ' @' + suelo;
  });

  //  Y EL FINAL DE LA PAGINA: con el scroll al tope, lo ultimo del CONTENIDO
  //  tiene que quedar por encima de esa barra.
  window.scrollTo(0, document.documentElement.scrollHeight);
  var ultimo = 0, ultimo_que = null;
  normales.forEach(function(e){
    var b = Math.round(e.getBoundingClientRect().bottom);
    if (b <= ultimo) return;
    ultimo = b;
    ultimo_que = ((e.className || e.tagName) + ' · ' + (e.textContent||'').trim().slice(0,20)) + ' @' + b;
  });
  return {
    ancho_doc: document.documentElement.scrollWidth,
    ancho_vp: W,
    desborde: Math.max(0, document.documentElement.scrollWidth - W),
    controles: vis.length,
    suelo: suelo,
    suelo_que: suelo_que,
    hueco_final: suelo - ultimo,
    ultimo_que: ultimo_que,
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

  if (etapa !== 'cuota') {
  // ── 1 · EL TRATO, tal como lo ve un Android de 360 ────────────────────
  await ir(`${BASE}/panel/meta.php?marca=${marca}`);
  await despejarBien();
  di('C_URL', await url());
  di('C_TITULO', await evaluar(`(document.querySelector('.tm-frase')||{}).textContent || ''`));
  di('C_BOTON', await evaluar(`(document.querySelector('#ahEmpezar')||{}).textContent || ''`));
  di('C_TRATO', await evaluar(`document.querySelector('.tm-trato') !== null`));
  di('C_REPARTO', await evaluar(
    `[].map.call(document.querySelectorAll('.tm-reparto div'),function(d){return d.textContent.trim().replace(/\\s+/g,' ');}).join(' | ')`));
  //  Criterio 3 del contrato: nunca dos primarios compitiendo.
  di('C_PRIMARIAS', await evaluar(`document.querySelectorAll('.tm-btn').length`));
  //  Criterio 7: ningun texto de uso normal por debajo de 14px.
  di('C_MIN_PX', await evaluar(`(function(){
    var min = 99;
    [].forEach.call(document.querySelectorAll('.tm-trato, .tm-trato *, .tm-frase, .tm-cons'), function(e){
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

  //  LA GEOMETRIA DE LO FIJO, en numeros. Sin esto, el hueco de abajo se
  //  dimensiona a ojo — y a ojo se puso una vez 300px de vacio.
  di('C_GEOMETRIA', await evaluar(`JSON.stringify((function(){
    var r = function(s){ var e=document.querySelector(s); if(!e) return null;
      var b=e.getBoundingClientRect(); return {alto:Math.round(b.height), top:Math.round(b.top)}; };
    window.scrollTo(0, document.documentElement.scrollHeight);
    var ult = document.querySelector('.tm-mas a:last-child');
    return {
      doc: document.documentElement.scrollHeight,
      vp: window.innerHeight,
      scroll_max: document.documentElement.scrollHeight - window.innerHeight,
      botnav: r('.botnav'),
      fab: r('.ay-fab'),
      ultimo_enlace: ult ? Math.round(ult.getBoundingClientRect().bottom) : null,
      zona: getComputedStyle(document.querySelector('.ah')).paddingBottom
    };
  })())`));

  //  AYUDA, EN LOS DOS ESTADOS QUE IMPORTAN.
  //
  //  1) El NORMAL: con la zona segura bien medida, la cola de la pantalla cae
  //     por encima del boton y no hay colision — asi que Ayuda sigue ahi. Un
  //     Ayuda escondido "por si acaso" seria perder una capacidad del producto.
  //  2) El FORZADO: se le quita la zona a mano para que la cola se le eche
  //     encima. Solo asi se puede demostrar que la regla existe y funciona; si
  //     no, se estaria afirmando sobre algo que nunca llega a dispararse.
  await evaluar("window.scrollTo(0, document.documentElement.scrollHeight)");
  await dormir(500);
  const donde = `(function(){
    var f = document.querySelector('.ay-fab'), c = document.querySelector('.tm-mas');
    return [document.body.classList.contains('ah-cola'),
            f ? Math.round(f.getBoundingClientRect().top) : '?',
            c ? Math.round(c.getBoundingClientRect().bottom) : '?',
            getComputedStyle(document.querySelector('.ah')).paddingBottom].join('/');
  })()`;
  di('C_AYUDA_NORMAL', await evaluar(donde));

  //  Se le quita la zona: la cola baja hasta la franja del boton.
  await evaluar(`document.querySelector('.ah').style.setProperty('--ah-zona','0px')`);
  await evaluar("window.scrollTo(0, document.documentElement.scrollHeight)");
  await dormir(600);
  di('C_AYUDA_FORZADA', await evaluar(donde));

  //  Y se le devuelve: Ayuda tiene que volver sola.
  await evaluar(`window.dispatchEvent(new Event('resize'))`);
  await evaluar("window.scrollTo(0, document.documentElement.scrollHeight)");
  await dormir(600);
  di('C_AYUDA_VUELTA', await evaluar(donde));

  //  ── LAS INVARIANTES DE LA ZONA ──────────────────────────────────────
  //  El contrato no es «141px» ni «91px»: un numero de implementacion se
  //  queda viejo en cuanto la barra cambia de alto. El contrato es que TODO
  //  SIGA ALCANZABLE y que NADA ACUMULE ESPACIO.
  await evaluar(`window.dispatchEvent(new Event('resize'))`);
  await dormir(400);

  //  1 · Al fondo del scroll, el ultimo control termina 20px por encima de
  //      la barra. Es la razon de ser de la zona, dicha como se comprueba.
  di('Z_HOLGURA', await evaluar(`(function(){
    window.scrollTo(0, document.documentElement.scrollHeight);
    var ah = document.querySelector('.ah, .plan'); if (!ah) return 'sin-caja';
    var nav = document.querySelector('.botnav');
    if (!nav || getComputedStyle(nav).display === 'none') return 'sin-barra';
    var techo = nav.getBoundingClientRect().top, peor = 99999, quien = '';
    [].forEach.call(ah.querySelectorAll('a[href],button,summary'), function(e){
      var d = e.closest('details');
      if (d && !d.open && e.tagName !== 'SUMMARY') return;   // no esta en pantalla
      var r = e.getBoundingClientRect(); if (r.height < 4) return;
      var h = Math.round(techo - r.bottom);
      if (h < peor) { peor = h; quien = (e.textContent||'').trim().slice(0,28); }
    });
    return peor + '/' + quien;
  })()`));

  //  2 · Cinco ciclos de abrir y cerrar dejan la pagina EXACTAMENTE igual.
  //      Aqui se caza el crecimiento acumulativo: cada recalculo sumando un
  //      poco mas hasta dejar una pantalla de vacio al final.
  di('Z_CICLOS', await evaluar(`(async function(){
    var ah = document.querySelector('.ah, .plan'); if (!ah) return 'sin-caja';
    var esperar = function(ms){ return new Promise(function(r){ setTimeout(r, ms); }); };
    var leer = function(){ return document.documentElement.scrollHeight + ':' +
                                  getComputedStyle(ah).paddingBottom; };
    var ds = [].slice.call(document.querySelectorAll('details'));
    if (!ds.length) return 'sin-capas';
    var estado0 = ds.map(function(d){ return d.open; });
    var primera = null, iguales = true, visto = [];
    for (var c = 0; c < 5; c++) {
      ds.forEach(function(d){ d.open = true; });  await esperar(160);
      ds.forEach(function(d, i){ d.open = estado0[i]; }); await esperar(160);
      var v = leer(); visto.push(v);
      if (primera === null) primera = v; else if (v !== primera) iguales = false;
    }
    return (iguales ? 'estable' : 'CRECE') + ' · ' + visto.join(' | ');
  })()`));

  //  3 · Cambiar de ancho tampoco acumula: 360 → 414 → escritorio → 360.
  //      Se vuelve al de partida y tiene que dar lo mismo que la primera vez.
  di('Z_RESIZE', await evaluar(`(async function(){
    var ah = document.querySelector('.ah, .plan'); if (!ah) return 'sin-caja';
    return getComputedStyle(ah).paddingBottom;
  })()`));
  di('AY_CHOQUES_C', await evaluar(`(async function(){
    var SEL = '.tm-btn, .ah-como > summary, .tm-ac > summary, .cq-btn';
    var H = window.innerHeight, alto = document.documentElement.scrollHeight, choques = [];
    var esperar = function(ms){ return new Promise(function(r){ setTimeout(r, ms); }); };
    for (var y = 0; y <= alto; y += 80) {
      window.scrollTo(0, y); await esperar(90);
      var f = document.querySelector('.ay-fab'); if (!f) continue;
      var cs = getComputedStyle(f);
      if (cs.display === 'none' || parseFloat(cs.opacity) < 0.05) continue;
      var b = f.getBoundingClientRect();
      if (b.bottom <= 0 || b.top >= H) continue;
      [].forEach.call(document.querySelectorAll(SEL), function(e){
        var a = e.getBoundingClientRect();
        if (a.width < 8 || a.height < 8 || a.bottom <= 0 || a.top >= H) return;
        if (a.left < b.right && b.left < a.right && a.top < b.bottom && b.top < a.bottom)
          choques.push(Math.round(y) + ':' + (e.className || e.tagName).toString().slice(0,18));
      });
    }
    window.scrollTo(0,0); return JSON.stringify(choques);
  })()`));

  const mC = await medir();
  di('C_ANCHO', mC.ancho_doc + '/' + mC.ancho_vp);
  di('C_DESBORDE', mC.desborde);
  di('C_CONTROLES', mC.controles);
  di('C_HUECO_FINAL', mC.hueco_final);
  di('C_ULTIMO', mC.ultimo_que);
  di('C_TECHO', mC.suelo_que);
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
  di('POST_TITULO', await evaluar(`(document.querySelector('.tm-frase')||{}).textContent || ''`));
  di('POST_SIGUE_C', await evaluar(`document.querySelector('#ahEmpezar') !== null`));
  di('POST_SIGUE_TRATO', await evaluar(`document.querySelector('.tm-trato') !== null`));
  di('POST_HAY_ACCION', await evaluar(
    `document.querySelectorAll('.tm-btn, .ah-como > summary').length`));

  const mD = await medir();
  di('POST_DESBORDE', mD.desborde);
  di('POST_TAPADOS', mD.tapados.length);
  di('POST_TAPADOS_DET', JSON.stringify(mD.tapados));
  await captura('meta_plan_presentado', '.tm-btn');

  // ── 3 · VOLVER ATRAS NO RESUCITA EL TRATO ─────────────────────────────
  //  El sello vive en la base, no en la URL: recargar o volver atras tiene
  //  que ensenar lo mismo. Un estado guardado en el historial se le repetiria
  //  al dueno cada vez que pulsara atras.
  await ir(`${BASE}/panel/meta.php?marca=${marca}`);
  await despejarBien();
  di('RECARGA_SIGUE_C', await evaluar(`document.querySelector('#ahEmpezar') !== null`));
  di('RECARGA_TITULO', await evaluar(`(document.querySelector('.tm-frase')||{}).textContent || ''`));
  }

  if (etapa === 'cuota') {
  // ── 4 · SIN CUOTA · el limite, visto a 360x800 ────────────────────────
  //  Se agota el mes a proposito -llenando el cubo, no gastando- y se mira lo
  //  que ve la dueña: que no parezca una averia, que se lea sin scroll y que no
  //  haya dos primarios peleandose.
  await ir(`${BASE}/panel/meta.php?marca=${marca}`);
  await despejarBien();
  di('CQ_HAY', await evaluar("document.querySelector('.tm-lim, .tm-turno.limite') !== null"));
  di('CQ_TITULO', await evaluar("(document.querySelector('.tm-lim, .tm-turno.limite')||{}).textContent || ''"));
  //  Que la accion normal SIGA en pie es la mitad del contrato: el limite que
  //  no bloquea no puede quitarle el boton a lo que si se puede hacer hoy.
  di('CQ_ACCION', await evaluar("(document.querySelector('.tm-btn')||{}).textContent || ''"));
  //  Lo que el corillo SIGUE haciendo vive en el acordeon, abierto de nacimiento
  //  justo porque esta es una de las dos pantallas que no pueden pedir la accion
  //  normal del plan.
  di('CQ_SIGUE', await evaluar("(document.querySelector('.tm-capas')||{}).textContent || ''"));
  //  Con el limite mandando NO hay primaria solida: la accion normal no se
  //  puede completar y ofrecerla mandaria a la dueña a un callejon. Queda una
  //  sola accion, y es de linea porque mirar no es decidir.
  di('CQ_PRIMARIOS', await evaluar(
    "document.querySelectorAll('.cq-btn, .tm-btn').length"));
  di('CQ_SIN_SCROLL', await evaluar(`(function(){
    var c = document.querySelector('.tm-lim, .tm-turno.limite'); if (!c) return 'sin-aviso';
    window.scrollTo(0,0);
    var r = c.getBoundingClientRect();
    return (r.top >= 0 && r.bottom <= window.innerHeight) ? 'si' : Math.round(r.bottom) + '/' + window.innerHeight;
  })()`));
  di('CQ_MIN_PX', await evaluar(`(function(){
    var min = 99;
    [].forEach.call(document.querySelectorAll('.ah p, .ah a, .ah span, .ah b'), function(e){
      if (e.children.length) return;
      if (!e.textContent.trim()) return;
      var s = parseFloat(getComputedStyle(e).fontSize);
      if (s && s < min) min = s;
    });
    return min;
  })()`));
  //  Ni rojo ni icono de alarma: el color es lo primero que dice si algo se rompio.
  //  La pastilla va en ambar, que es la familia de AVISO — ni el rosa de «te
  //  toca a ti» ni el teal de «corre solo», porque no es ninguna de las dos.
  di('CQ_FONDO', await evaluar("(function(){var e=document.querySelector('.tm-lim, .tm-turno.limite');"
    + "return e ? getComputedStyle(e).backgroundColor : 'sin-aviso';})()"));
  //  AYUDA CONTRA EL PRIMARIO, EN CADA POSICION DE SCROLL.
  //
  //  «Se alcanza haciendo scroll» NO vale para el boton mas importante de la
  //  pantalla: es el que la dueña toca sin pensar, y si a veces esta debajo de
  //  Ayuda, a veces le toca a Ayuda. Asi que se recorre la pagina de arriba
  //  abajo y en CADA parada se comprueba si algun control principal se solapa
  //  con el boton flotante. Un solo solape en un solo scroll ya es un defecto.
  di('AY_CHOQUES', await evaluar(`(async function(){
    var SEL = '.tm-btn, .ah-como > summary, .tm-ac > summary, .cq-btn';
    var H = window.innerHeight;
    var alto = document.documentElement.scrollHeight;
    var choques = [];
    var esperar = function(ms){ return new Promise(function(r){ setTimeout(r, ms); }); };
    for (var y = 0; y <= alto; y += 80) {
      window.scrollTo(0, y);
      await esperar(90);                       // que el observador reaccione
      var f = document.querySelector('.ay-fab');
      if (!f) continue;
      var cs = getComputedStyle(f);
      if (cs.display === 'none' || parseFloat(cs.opacity) < 0.05) continue;   // apartada
      var b = f.getBoundingClientRect();
      if (b.bottom <= 0 || b.top >= H) continue;                              // fuera de pantalla
      [].forEach.call(document.querySelectorAll(SEL), function(e){
        var a = e.getBoundingClientRect();
        if (a.width < 8 || a.height < 8) return;
        if (a.bottom <= 0 || a.top >= H) return;
        if (a.left < b.right && b.left < a.right && a.top < b.bottom && b.top < a.bottom) {
          choques.push(Math.round(y) + ':' + (e.className || e.tagName).toString().slice(0,18));
        }
      });
    }
    window.scrollTo(0, 0);
    return JSON.stringify(choques);
  })()`));

  const mQ = await medir();
  di('CQ_DESBORDE', mQ.desborde);
  di('CQ_TAPADOS', mQ.tapados.length);
  di('CQ_TAPADOS_DET', JSON.stringify(mQ.tapados));
  await captura('meta_sin_cuota', '.tm-turno.limite');
  }

  di('OK', 1);
} catch (e) {
  di('ERROR', e.message);
  di('OK', 0);
} finally {
  ch.kill();
  await dormir(400);
  try { fs.rmSync(perfil, { recursive: true, force: true }); } catch { /* Windows */ }
}
