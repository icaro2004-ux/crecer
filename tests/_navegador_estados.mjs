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
//    node tests/_navegador_estados.mjs <sid> <marca> <ancho> <alto> [abrir] [captura] [vista] [paso]
//
//  `paso` solo aplica al wizard (vista=wizard). Para medir el paso 3 hay que
//  HABER CONTESTADO los dos anteriores, asi que la sonda los contesta con
//  gestos de verdad —pulsar el objetivo, escribir el numero, pulsar Siguiente—
//  y no encendiendo clases a mano. Si el camino se rompe, se rompe aqui.
//
//  Imprime UNA linea de JSON. Quien asierta es la prueba en PHP.
// ============================================================

import fs from "node:fs";
import path from "node:path";
import { abrirChrome, cerrarRecibimiento, dormir } from "./_chrome.mjs";

const [sid, marca, aS, hS, abrir, captura, vista, pasoS] = process.argv.slice(2);
const pasoPedido = parseInt(pasoS || "1", 10) || 1;
//  Un texto largo de los que no caben, para que el repaso se mida con lo que
//  de verdad escribe alguien y no con dos palabras.
const CTX_LARGO = "Tengo el combo de brazo gitano a $18 y en agosto son las fiestas "
  + "del pueblo, que me cae encima el fin de semana entero y ese sabado hay parranda "
  + "en la plaza hasta tarde; tambien vendo bizcochos de boda por encargo.";
const ancho = parseInt(aS, 10), alto = parseInt(hS, 10);
const URL_PAGINA = `http://localhost/crecer/panel/meta.php?marca=${marca}`
                 + (vista ? `&vista=${vista}` : "");

const salir = (obj) => { console.log(JSON.stringify(obj)); };

//  Arrancar Chrome y el cableado del CDP viven en _chrome.mjs — los comparte
//  con la sonda del recorrido del wizard.
const sesion = await abrirChrome({ sid, url: URL_PAGINA, ancho, alto });
if (sesion.error) { salir(sesion); process.exit(1); }
const { ev, cmd, cerrar } = sesion;

try {
  await cerrarRecibimiento(ev);
  const contenedor = await ev(
    `document.querySelector('.ah') ? '.ah'
       : (document.querySelector('.plan') ? '.plan'
       : (document.querySelector('.wz') ? '.wz' : ''))`);
  if (!contenedor) {
    const donde = await ev('location.href');
    salir({ error: 'la pagina no trae ni .ah ni .plan ni .wz', url_pedida: URL_PAGINA, url_final: donde,
            viewport: `${ancho}x${alto}`,
            titulo: await ev('document.title'),
            pista: 'si la url final es otra, algo redirigio — candado de suscripcion o sesion caida' });
    cerrar();
    process.exit(1);
  }

  //  ── LLEGAR AL PASO PEDIDO, CONTESTANDO ──────────────────────────────
  //  Contestar es la unica forma honesta de llegar: si el wizard pierde una
  //  respuesta al avanzar, o el boton no se habilita, la sonda se queda corta
  //  y la prueba lo ve en `paso`. Encender la clase `.on` a mano habria dado
  //  un verde bonito sobre un camino roto.
  if (contenedor === '.wz' && pasoPedido > 1) {
    const escribir = (id, val) => ev(`(function(){var e=document.getElementById('${id}');
      if(!e) return; e.value=${JSON.stringify(val)}; e.dispatchEvent(new Event('input',{bubbles:true}));})()`);
    const pulsa = async (sel, ms) => {
      await ev(`(function(){var e=document.querySelector(${JSON.stringify(sel)}); if(e) e.click();})()`);
      await dormir(ms || 320);
    };
    //  Cada wizard contesta lo suyo. El flujo lo dice el propio contenedor, no
    //  la URL: asi la sonda no tiene que saberse las rutas de memoria.
    const flujo = await ev(`(document.querySelector('.wz').dataset.flujo || 'crear')`);

    if (flujo === 'crear') {
      await pulsa('.wz-obj', 260);
      await pulsa('#sigue');
      if (pasoPedido > 2) { await escribir('cantidad', '25'); await dormir(140); await pulsa('#sigue'); }
      if (pasoPedido > 3) {
        await pulsa('#wzPauta .wz-chip[data-pauta="20"]', 120);
        await escribir('contexto', CTX_LARGO); await dormir(140);
        await pulsa('#sigue', 420);
      }
    } else {
      //  Los dos delicados empiezan igual: el motivo. Despues, cambiar pide
      //  meta nueva y numeros; plan-nuevo solo enseña lo que se mueve.
      await pulsa('#opMotivo .wz-chip', 200);
      await escribir('opDetalle', CTX_LARGO);
      await dormir(120);
      await pulsa('#sigue');
      if (pasoPedido > 2) {
        if (flujo === 'cambiar') { await pulsa('.wz-obj', 260); }
        await pulsa('#sigue');
      }
      if (pasoPedido > 3) {
        await escribir('cantidad', '30'); await dormir(140);
        await pulsa('#wzPauta .wz-chip[data-pauta="20"]', 120);
        await escribir('contexto', CTX_LARGO); await dormir(140);
        await pulsa('#sigue', 420);
      }
    }
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
      //  El wizard: en que paso quedo de verdad, que dice la etiqueta y que
      //  respuestas conserva. La prueba no tiene que creerse el recorrido.
      paso: (function(){ var s=ah.querySelector('.wz-p.on'); return s ? +s.dataset.p : 0; })(),
      paso_et: (function(){ var e=document.getElementById('wzEt');
                            return e ? (e.textContent||'').trim() : ''; })(),
      flujo: (function(){ var w=ah.closest('.wz')||ah; return w.dataset ? (w.dataset.flujo||'crear') : 'crear'; })(),
      motivo: (function(){ var m=document.querySelector('#opMotivo .wz-chip.sel');
                           return m ? m.dataset.motivo : ''; })(),
      guardado: (function(){
        var c=document.getElementById('cantidad'), t=document.getElementById('contexto');
        var s=document.querySelector('.wz-obj.sel');
        var fe=document.querySelector('#wzFecha .wz-chip.sel'), pa=document.querySelector('#wzPauta .wz-chip.sel');
        return c ? { cant:c.value, ctx:(t?t.value:'').slice(0,40),
                     obj:s?s.dataset.obj:'', dias:fe?fe.dataset.dias:'', pauta:pa?pa.dataset.pauta:'' } : null;
      })(),
      repaso: (function(){
        var r={}; ['rObj','rCant','rFecha','rPauta','rCtx','rMedir','rMotivo','rVieja'].forEach(function(i){
          var e=document.getElementById(i); if(e) r[i]=(e.textContent||'').trim(); });
        return r;
      })(),
      //  Los destinos de verdad, tal como los lee Chrome tras resolver el href.
      destinos: [].slice.call(ah.querySelectorAll('a[href]')).map(function(a){return a.href;})
                  .filter(function(u){return /aprobar2|reels|carrusel|propuestas|meta\\.php/.test(u);})
    };
  })())`));
  r.tapados = tapados;

  if (captura) {
    //  La regla de Ayuda corre en un IntersectionObserver y su transicion dura
    //  .2s: fotografiar a los 280ms pillaba el boton a medio apartar y la
    //  captura enseñaba un solape que en la pantalla de verdad no existe.
    await ev('window.scrollTo(0,0)'); await dormir(900);
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
