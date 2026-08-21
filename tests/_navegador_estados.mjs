// ============================================================
//  CRECER — TU META, MEDIDA EN UN NAVEGADOR DE VERDAD
//  tests/_navegador_estados.mjs
//
//  Mide lo que no se puede afirmar leyendo el fuente: si un control queda
//  TAPADO por algo fijo, si hay mas de un titular, si algo baja de 14px, si
//  un objetivo no llega a 44x44.
//
//  DOS REGLAS DE LA CASA, aprendidas a base de tragarme falsos verdes:
//
//  1. NADA SE DESCARTA COMO «cosa de la sonda» SIN PRUEBA. Cuando algo sale
//     tapado se devuelven los DOS rectangulos, el texto de los dos elementos,
//     su href, el viewport y el scroll. Con eso se puede decidir si el que
//     estorba es el HTML o la medicion. Un `false` pelado no deja decidir nada.
//
//  2. LA SEGUNDA MIRADA. La regla que aparta Ayuda corre en un
//     IntersectionObserver, que avisa de forma asincrona. Preguntar por el
//     solape en el mismo instante en que se desplaza el control es preguntar
//     antes de que la pantalla conteste. Cada candidato se lleva al centro, se
//     le da un respiro y se vuelve a mirar. Lo que siga tapado, lo esta.
//
//  No llama a ningun proveedor: abre la pagina y la mide.
//
//    node tests/_navegador_estados.mjs <sid> <marca> <ancho> <alto> [abrir] [captura] [vista]
//
//  Imprime UNA linea de JSON. Quien asierta es la prueba en PHP.
// ============================================================

import { spawn } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const [sid, marca, aS, hS, abrir, captura, vista] = process.argv.slice(2);
const ancho = parseInt(aS, 10), alto = parseInt(hS, 10);
const perfil = fs.mkdtempSync(path.join(os.tmpdir(), 'tm-')).split(path.sep).join('/');
//  Puerto con parte aleatoria: corriendo la suite entera hay varios Chrome a
//  la vez y dos con el mismo puerto se pisan.
const puerto = 9000 + Math.floor(Math.random() * 900);
const dormir = (ms) => new Promise((r) => setTimeout(r, ms));
const URL_PAGINA = `http://localhost/crecer/panel/meta.php?marca=${marca}`
                 + (vista ? `&vista=${vista}` : '');

const salir = (obj) => { console.log(JSON.stringify(obj)); };

const ch = spawn(CHROME, [
  '--headless=new', '--disable-gpu', '--hide-scrollbars', '--no-first-run',
  `--window-size=${ancho},${alto}`, `--user-data-dir=${perfil}`,
  `--remote-debugging-port=${puerto}`, 'about:blank',
], { stdio: 'ignore' });

let cdp = null, id = 0;
const pend = new Map();
const cmd = (m, p = {}) => {
  const i = ++id; cdp.send(JSON.stringify({ id: i, method: m, params: p }));
  return new Promise((r, j) => pend.set(i, { r, j }));
};
const ev = async (e) => {
  const r = await cmd('Runtime.evaluate', { expression: e, returnByValue: true });
  if (r.exceptionDetails) {
    throw new Error('JS: ' + (r.exceptionDetails.exception?.description || r.exceptionDetails.text));
  }
  return r.result.value;
};

(async () => {
  //  CONECTAR, Y SI NO SE PUEDE, DECIRLO CON NOMBRE Y APELLIDO.
  //  Antes esto acababa en «TypeError: Invalid URL», que es lo que pasa al
  //  construir un WebSocket con null. El error real es otro: Chrome no llego a
  //  levantar su puerto de depuracion. Son cosas distintas y se arreglan
  //  distinto.
  let ws = null, ultimoFallo = '';
  for (let i = 0; i < 80 && !ws; i++) {
    try {
      const r = await fetch(`http://127.0.0.1:${puerto}/json/list`);
      const j = await r.json();
      const p = j.find((x) => x.type === 'page');
      if (p) ws = p.webSocketDebuggerUrl;
    } catch (e) { ultimoFallo = e.message; }
    if (!ws) await dormir(200);
  }
  if (!ws) {
    salir({ error: `Chrome no levanto el puerto ${puerto} tras 16s`,
            detalle: ultimoFallo, url: URL_PAGINA, viewport: `${ancho}x${alto}` });
    try { ch.kill(); } catch (e) {}
    process.exit(1);
  }

  cdp = new (globalThis.WebSocket)(ws);
  await new Promise((r, j) => { cdp.onopen = r; cdp.onerror = () => j(new Error('el socket de Chrome se cayo')); });
  cdp.onmessage = (e) => {
    const m = JSON.parse(e.data);
    if (m.id && pend.has(m.id)) {
      const { r, j } = pend.get(m.id); pend.delete(m.id);
      m.error ? j(new Error(m.error.message)) : r(m.result);
    }
  };

  await cmd('Page.enable'); await cmd('Runtime.enable'); await cmd('Network.enable');
  await cmd('Emulation.setDeviceMetricsOverride',
            { width: ancho, height: alto, deviceScaleFactor: 1, mobile: ancho < 900 });
  await cmd('Network.setCookie', { name: 'PHPSESSID', value: sid, domain: 'localhost', path: '/' });
  await cmd('Page.navigate', { url: URL_PAGINA });
  for (let i = 0; i < 200; i++) {
    if (await ev('document.readyState === "complete"')) break;
    await dormir(120);
  }
  await dormir(1100);

  //  El Recibimiento sale en cuentas nuevas y tapa la pantalla. Se cierra como
  //  lo cerraria la dueña, pulsando su propio boton.
  await ev(`(function(){var t=['Entendido','¡ENTENDIDO!','Saltar','Cerrar','Listo, ya sé'];
    for(var k=0;k<3;k++){[].forEach.call(document.querySelectorAll('button,a'),function(b){
      var s=(b.textContent||'').trim();
      if(t.some(function(x){return s.toLowerCase()===x.toLowerCase();})&&b.offsetParent!==null)b.click();});}})()`);
  await dormir(800);

  const contenedor = await ev(
    `document.querySelector('.ah') ? '.ah' : (document.querySelector('.plan') ? '.plan' : '')`);
  if (!contenedor) {
    const donde = await ev('location.href');
    salir({ error: 'la pagina no trae ni .ah ni .plan', url_pedida: URL_PAGINA, url_final: donde,
            viewport: `${ancho}x${alto}`,
            titulo: await ev('document.title'),
            pista: 'si la url final es otra, algo redirigio — candado de suscripcion o sesion caida' });
    try { ch.kill(); } catch (e) {}
    process.exit(1);
  }

  if (abrir === 'abrir') {
    await ev("document.querySelectorAll('details').forEach(function(d){d.open=true;})");
    await dormir(700);
  }

  //  ── SOLAPES, CON PRUEBAS ────────────────────────────────────────────
  //  Cada control se marca, se lleva al centro, se le da un respiro y se
  //  compara con las capas fijas ALCANZABLES. Si hay solape se devuelve todo
  //  lo que hace falta para juzgarlo sin volver a correr nada.
  //  SOLO LO QUE ESTA EN PANTALLA AHORA MISMO.
  //  Un control dentro de un acordeon CERRADO no lo puede tapar nada, porque
  //  no se ve. Y hay mas: Chrome abre solo un <details> cuando le pides
  //  scrollIntoView sobre algo de dentro, asi que medirlo lo saca a la
  //  pantalla y de paso cambia el alto del documento. Se marco «Empezar un
  //  plan nuevo» como tapado por la barra estando dentro de «Opciones del
  //  plan», que nace plegado. El summary del acordeon SI se mide — esa es la
  //  puerta que la dueña ve.
  await ev(`(function(){
    var c = document.querySelector('${contenedor}');
    var n = 0;
    [].forEach.call(c.querySelectorAll('a[href],button,summary'), function(e){
      var d = e.closest('details');
      var dentroDePlegado = d && !d.open && e.tagName !== 'SUMMARY';
      if (dentroDePlegado) return;
      e.setAttribute('data-sonda', n++);
    });
  })()`);
  const cuantos = await ev(`document.querySelectorAll('[data-sonda]').length`);

  const tapados = [];
  for (let i = 0; i < cuantos; i++) {
    const sel = `[data-sonda="${i}"]`;
    //  DOS POSICIONES, NO UNA. Primero al centro, que es donde el dedo lo
    //  busca. Si ahi lo tapa algo, se prueba tambien con la pagina AL FONDO:
    //  esa es la posicion para la que existe la zona segura, y es la que
    //  decide. Un control tapado al fondo del scroll no hay forma de pulsarlo;
    //  uno tapado a media altura se destapa siguiendo hacia abajo.
    await ev(`(function(){var e=document.querySelector('${sel}');
      if(e) e.scrollIntoView({block:'center',inline:'nearest'});})()`);
    await dormir(70);
    const t = JSON.parse(await ev(`JSON.stringify((function(){
      var e = document.querySelector('${sel}'); if (!e) return null;
      var a = e.getBoundingClientRect();
      if (a.width < 7 || a.height < 7) return null;
      function flot(x){ for(var p=x;p&&p!==document.body;p=p.parentElement){
        var po=getComputedStyle(p).position; if(po==='fixed'||po==='sticky') return p; } return null; }
      function alcanzable(x){ for(var p=x;p&&p!==document.documentElement;p=p.parentElement){
        var cs=getComputedStyle(p);
        if(parseFloat(cs.opacity)<0.05||cs.pointerEvents==='none'
           ||cs.visibility==='hidden'||cs.display==='none') return false; } return true; }
      var capas=[].slice.call(document.querySelectorAll('a[href],button,summary,input,select,nav,div'))
        .filter(flot).filter(alcanzable);
      for (var k=0;k<capas.length;k++){
        var c=capas[k]; if(c===e||c.contains(e)||e.contains(c)) continue;
        var b=c.getBoundingClientRect();
        if(b.width<7||b.height<7) continue;
        if(a.left<b.right-1&&b.left<a.right-1&&a.top<b.bottom-1&&b.top<a.bottom-1){
          return {
            control:{ sel:'${sel}', txt:(e.textContent||'').trim().slice(0,40),
                      tag:e.tagName, cls:(e.className||'').toString().slice(0,40),
                      href:e.getAttribute('href')||'',
                      rect:{x:Math.round(a.left),y:Math.round(a.top),
                            w:Math.round(a.width),h:Math.round(a.height)} },
            capa:{ tag:c.tagName, cls:(c.className||'').toString().slice(0,40),
                   txt:(c.textContent||'').trim().slice(0,40),
                   pos:getComputedStyle(c).position,
                   rect:{x:Math.round(b.left),y:Math.round(b.top),
                         w:Math.round(b.width),h:Math.round(b.height)} },
            viewport:{w:innerWidth,h:innerHeight},
            scroll:Math.round(window.scrollY),
            doc:Math.round(document.documentElement.scrollHeight),
            zona:getComputedStyle(document.querySelector('${contenedor}')).paddingBottom,
            cola:document.body.classList.contains('ah-cola'),
            url:location.href
          };
        }
      }
      return null;
    })())`));
    if (t) {
      //  Segunda posicion: la pagina al fondo. Si ahi queda libre, el control
      //  es alcanzable — y lo que fallo fue el intento de centrarlo, no la
      //  pantalla. Se anota igual en `t.al_fondo` para poder discutirlo.
      await ev('window.scrollTo(0, document.documentElement.scrollHeight)');
      await dormir(120);
      const t2 = JSON.parse(await ev(`JSON.stringify((function(){
        var e=document.querySelector('${sel}'); if(!e) return null;
        var a=e.getBoundingClientRect();
        function flot(x){for(var p=x;p&&p!==document.body;p=p.parentElement){
          var po=getComputedStyle(p).position; if(po==='fixed'||po==='sticky') return p;} return null;}
        function alcanzable(x){for(var p=x;p&&p!==document.documentElement;p=p.parentElement){
          var cs=getComputedStyle(p);
          if(parseFloat(cs.opacity)<0.05||cs.pointerEvents==='none'
             ||cs.visibility==='hidden'||cs.display==='none') return false;} return true;}
        var capas=[].slice.call(document.querySelectorAll('a[href],button,summary,input,select,nav,div'))
          .filter(flot).filter(alcanzable);
        for(var k=0;k<capas.length;k++){ var c=capas[k];
          if(c===e||c.contains(e)||e.contains(c)) continue;
          var b=c.getBoundingClientRect(); if(b.width<7||b.height<7) continue;
          if(a.left<b.right-1&&b.left<a.right-1&&a.top<b.bottom-1&&b.top<a.bottom-1)
            return {cls:(c.className||c.tagName).toString().slice(0,40),
                    rect:{x:Math.round(a.left),y:Math.round(a.top),
                          w:Math.round(a.width),h:Math.round(a.height)},
                    scroll:Math.round(window.scrollY)};
        }
        return null;
      })())`));
      if (t2) { t.al_fondo = t2; tapados.push(t); }
    }
  }

  await ev('window.scrollTo(0,0)'); await dormir(220);

  const r = JSON.parse(await ev(`JSON.stringify((function(){
    var W=innerWidth,H=innerHeight;
    var ah=document.querySelector('${contenedor}');
    var vis=[].slice.call(ah.querySelectorAll('a[href],button,summary'))
      .filter(function(e){var r=e.getBoundingClientRect();
        return r.width>6&&r.height>6&&getComputedStyle(e).visibility!=='hidden';});
    var chicos=vis.filter(function(e){var r=e.getBoundingClientRect();
        return r.height<44||r.width<44;})
      .map(function(e){var r=e.getBoundingClientRect();
        return {t:(e.textContent||'').trim().slice(0,26),
                w:Math.round(r.width),h:Math.round(r.height)};});
    var bajo14=[];
    [].forEach.call(ah.querySelectorAll('*'),function(e){
      if(e.children.length>0) return;
      var tx=(e.textContent||'').trim(); if(!tx) return;
      var fs=parseFloat(getComputedStyle(e).fontSize);
      if(fs<14) bajo14.push({t:tx.slice(0,24),px:fs});
    });
    var nav=[];
    [].forEach.call(document.querySelectorAll('.botnav a'),function(e){
      nav.push(parseFloat(getComputedStyle(e).fontSize)); });
    var prim = ah.querySelector('.tm-btn')
            || ah.querySelector('.jg.turno .jg-hacer, .jg.turno .jg-ok2, .jg.turno .jg-ver');
    var pr = prim ? prim.getBoundingClientRect() : null;
    return {
      contenedor:'${contenedor}', url:location.href, viewport:{w:W,h:H},
      doc:Math.round(document.documentElement.scrollHeight),
      zona:getComputedStyle(ah).paddingBottom,
      chicos:chicos, bajo14:bajo14,
      titulares:[].slice.call(ah.querySelectorAll('h1,.tm-frase')).map(function(e){
        return {t:(e.textContent||'').trim().slice(0,40),
                px:parseFloat(getComputedStyle(e).fontSize)};}),
      nav_px: nav.length ? Math.min.apply(null, nav) : 0,
      primarias: ah.querySelectorAll('.tm-btn:not(.linea)').length
               + ah.querySelectorAll('.jg[open] .jg-hacer:not(.sec), .jg[open] .jg-ok2').length,
      abiertas: ah.querySelectorAll('.jg[open]').length,
      prim: pr ? {t:(prim.textContent||'').trim().slice(0,26), top:Math.round(pr.top),
                  visible: pr.bottom<=H && pr.top>=0} : null,
      scroll_h: document.documentElement.scrollWidth > W+1,
      //  Los destinos de verdad, tal como los lee Chrome tras resolver el href.
      destinos: [].slice.call(ah.querySelectorAll('a[href]')).map(function(a){return a.href;})
                  .filter(function(u){return /aprobar2|reels|carrusel|propuestas|meta\\.php/.test(u);})
    };
  })())`));
  r.tapados = tapados;

  if (captura) {
    await ev('window.scrollTo(0,0)'); await dormir(280);
    const s = await cmd('Page.captureScreenshot', { format: 'png' });
    fs.writeFileSync(path.join(process.cwd(), 'tests', '_capturas', captura + '.png'),
                     Buffer.from(s.data, 'base64'));
  }

  salir(r);
  ch.kill(); process.exit(0);
})().catch((e) => {
  salir({ error: e.message, url: URL_PAGINA, viewport: `${ancho}x${alto}`,
          pista: 'error del arnes, no necesariamente de la pantalla' });
  try { ch.kill(); } catch (x) {}
  process.exit(1);
});
