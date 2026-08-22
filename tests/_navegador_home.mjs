// ============================================================
//  CRECER — LA PORTADA, MEDIDA EN UN NAVEGADOR DE VERDAD
//  tests/_navegador_home.mjs
//
//  Mismo oficio que _navegador_estados.mjs, sobre otra pantalla: solapes con
//  las capas fijas, texto bajo 14px, objetivos bajo 44x44, scroll horizontal.
//
//  Y una cosa que Tu Meta no necesitaba: LOS ERRORES DE CONSOLA. La tarjeta del
//  norte anima su numero con un guion; si ese guion revienta, el numero se
//  queda en cero para siempre y la pantalla parece decir «no has vendido nada».
//  Eso no se ve en el HTML — hay que escuchar la consola.
//
//    node tests/_navegador_home.mjs <sid> <marca> <ancho> <alto> [captura]
//
//  Imprime UNA linea de JSON. Quien asierta es la prueba en PHP.
// ============================================================

import fs from 'node:fs';
import path from 'node:path';
import { abrirChrome, cerrarRecibimiento, dormir } from './_chrome.mjs';

const [sid, marca, aS, hS, captura] = process.argv.slice(2);
const ancho = parseInt(aS, 10), alto = parseInt(hS, 10);
const URL_PAGINA = `http://localhost/crecer/panel/index.php?marca=${marca}`;

const salir = (o) => { console.log(JSON.stringify(o)); };

const sesion = await abrirChrome({ sid, url: URL_PAGINA, ancho, alto });
if (sesion.error) { salir(sesion); process.exit(1); }
const { ev, cmd, cerrar } = sesion;

try {
  //  LA CONSOLA SE ESCUCHA DESDE EL PRINCIPIO. Engancharse despues de cargar
  //  seria perderse justo los errores del arranque, que son los que dejan la
  //  tarjeta muerta.
  const consola = [];
  await ev(`window.__errs = [];
    window.addEventListener('error', function(e){
      window.__errs.push(String(e.message || 'error').slice(0, 160));
    });
    window.addEventListener('unhandledrejection', function(e){
      window.__errs.push('promesa: ' + String(e.reason || '').slice(0, 140));
    });`);
  //  Y se recarga con el oyente ya puesto.
  await cmd('Page.reload', { ignoreCache: false });
  for (let i = 0; i < 200; i++) {
    if (await ev('document.readyState === "complete"')) break;
    await dormir(120);
  }
  await dormir(1200);
  await cerrarRecibimiento(ev);

  const hayHome = await ev(`!!document.querySelector('main.hz')`);
  if (!hayHome) {
    salir({ error: 'la pagina no trae la portada', url_final: await ev('location.href'),
            titulo: await ev('document.title'),
            pista: 'si la url final es otra, algo redirigio — candado de suscripcion o sesion caida' });
    cerrar(); process.exit(1);
  }

  //  ── SOLAPES, con la misma disciplina que en Tu Meta ──────────────
  await ev(`(function(){
    var c = document.querySelector('main.hz'); var n = 0;
    [].forEach.call(c.querySelectorAll('a[href],button,summary'), function(e){
      var d = e.closest('details');
      if (d && !d.open && e.tagName !== 'SUMMARY') return;
      e.setAttribute('data-sonda', n++);
    });
  })()`);
  const cuantos = await ev(`document.querySelectorAll('[data-sonda]').length`);

  const tapados = [];
  for (let i = 0; i < cuantos; i++) {
    const sel = `[data-sonda="${i}"]`;
    await ev(`(function(){var e=document.querySelector('${sel}');
      if(e) e.scrollIntoView({block:'center',inline:'nearest'});})()`);
    await dormir(70);
    const t = JSON.parse(await ev(`JSON.stringify((function(){
      var e=document.querySelector('${sel}'); if(!e) return null;
      var a=e.getBoundingClientRect();
      if (a.width < 7 || a.height < 7) return null;
      function flot(x){ for(var p=x;p&&p!==document.body;p=p.parentElement){
        var po=getComputedStyle(p).position; if(po==='fixed'||po==='sticky') return p; } return null; }
      function alcanzable(x){ for(var p=x;p&&p!==document.documentElement;p=p.parentElement){
        var cs=getComputedStyle(p);
        if(parseFloat(cs.opacity)<0.05||cs.pointerEvents==='none'
           ||cs.visibility==='hidden'||cs.display==='none') return false; } return true; }
      var capas=[].slice.call(document.querySelectorAll('a[href],button,nav,div'))
        .filter(flot).filter(alcanzable);
      for (var k=0;k<capas.length;k++){
        var c=capas[k]; if(c===e||c.contains(e)||e.contains(c)) continue;
        var b=c.getBoundingClientRect(); if(b.width<7||b.height<7) continue;
        if(a.left<b.right-1&&b.left<a.right-1&&a.top<b.bottom-1&&b.top<a.bottom-1){
          return { control:{txt:(e.textContent||'').trim().slice(0,40),
                            cls:(e.className||'').toString().slice(0,40),
                            rect:{x:Math.round(a.left),y:Math.round(a.top),
                                  w:Math.round(a.width),h:Math.round(a.height)}},
                   capa:{cls:(c.className||c.tagName).toString().slice(0,40),
                         rect:{x:Math.round(b.left),y:Math.round(b.top),
                               w:Math.round(b.width),h:Math.round(b.height)}},
                   scroll:Math.round(window.scrollY), url:location.href };
        }
      }
      return null;
    })())`));
    if (t) {
      await ev('window.scrollTo(0, document.documentElement.scrollHeight)');
      await dormir(120);
      const sigue = await ev(`(function(){
        var e=document.querySelector('${sel}'); if(!e) return false;
        var a=e.getBoundingClientRect();
        function flot(x){for(var p=x;p&&p!==document.body;p=p.parentElement){
          var po=getComputedStyle(p).position; if(po==='fixed'||po==='sticky') return p;} return null;}
        function alcanzable(x){for(var p=x;p&&p!==document.documentElement;p=p.parentElement){
          var cs=getComputedStyle(p);
          if(parseFloat(cs.opacity)<0.05||cs.pointerEvents==='none') return false;} return true;}
        var capas=[].slice.call(document.querySelectorAll('a[href],button,nav,div'))
          .filter(flot).filter(alcanzable);
        for(var k=0;k<capas.length;k++){var c=capas[k];
          if(c===e||c.contains(e)||e.contains(c)) continue;
          var b=c.getBoundingClientRect(); if(b.width<7||b.height<7) continue;
          if(a.left<b.right-1&&b.left<a.right-1&&a.top<b.bottom-1&&b.top<a.bottom-1) return true;}
        return false;
      })()`);
      if (sigue) tapados.push(t);
    }
  }

  await ev('window.scrollTo(0,0)'); await dormir(300);

  const r = JSON.parse(await ev(`JSON.stringify((function(){
    var W=innerWidth, hz=document.querySelector('main.hz');
    var norte=document.querySelector('.norte');
    var vis=[].slice.call(hz.querySelectorAll('a[href],button,summary'))
      .filter(function(e){var r=e.getBoundingClientRect();
        return r.width>6&&r.height>6&&getComputedStyle(e).visibility!=='hidden';});
    var chicos=vis.filter(function(e){var r=e.getBoundingClientRect();
        return r.height<44||r.width<44;})
      .map(function(e){var r=e.getBoundingClientRect();
        return {t:(e.textContent||'').trim().slice(0,26),cls:(e.className||'').toString().slice(0,28),
                w:Math.round(r.width),h:Math.round(r.height)};});
    var bajo14=[];
    [].forEach.call(hz.querySelectorAll('*'),function(e){
      if(e.children.length>0) return;
      var tx=(e.textContent||'').trim(); if(!tx) return;
      var cs=getComputedStyle(e);
      if(cs.display==='none'||cs.visibility==='hidden') return;
      var fs=parseFloat(cs.fontSize);
      if(fs<14) bajo14.push({t:tx.slice(0,24),cls:(e.className||'').toString().slice(0,24),px:fs});
    });
    //  «Primaria» en la tarjeta: la tarjeta entera puede ser un enlace, y eso
    //  YA es la accion. Un boton dentro competiria con ella.
    var prim = norte ? norte.querySelectorAll('a[href],button').length : 0;
    if (norte && norte.tagName === 'A') prim += 1;
    return {
      hay_home:true, url:location.href,
      hay_norte: !!norte,
      hay_saludo: !!document.querySelector('.hz-hello'),
      tarjetas: document.querySelectorAll('main.hz .hz-card').length,
      href_norte: norte ? (norte.getAttribute('href') || (norte.querySelector('a[href]')
                    ? norte.querySelector('a[href]').getAttribute('href') : '')) : '',
      primarias_norte: prim,
      chicos:chicos, bajo14:bajo14,
      doc:Math.round(document.documentElement.scrollHeight),
      scroll_h: document.documentElement.scrollWidth > W+1,
      consola: window.__errs || []
    };
  })())`));
  r.tapados = tapados;

  if (captura) {
    await ev('window.scrollTo(0,0)'); await dormir(700);
    const s = await cmd('Page.captureScreenshot', { format: 'png' });
    fs.writeFileSync(path.join(process.cwd(), 'tests', '_capturas', captura + '.png'),
                     Buffer.from(s.data, 'base64'));
  }

  salir(r);
  cerrar(); process.exit(0);

} catch (e) {
  salir({ error: e.message, url: URL_PAGINA, viewport: `${ancho}x${alto}`,
          pista: 'error del arnes, no necesariamente de la pantalla' });
  cerrar(); process.exit(1);
}
