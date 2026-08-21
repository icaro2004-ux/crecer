// ============================================================
//  CRECER — LA PANTALLA DE TU META, MEDIDA EN UN NAVEGADOR DE VERDAD
//  tests/_navegador_estados.mjs
//
//  Mide lo que no se puede afirmar leyendo el fuente: si un control queda
//  TAPADO por algo fijo, si hay mas de un titular, si algo baja de 14px.
//  Cada control se lleva al CENTRO de la ventana antes de mirarlo: lo que
//  sigue tapado en el centro no hay forma de pulsarlo, y decir «se alcanza
//  haciendo scroll» no vale para el boton mas importante de la pantalla.
//
//  No llama a ningun proveedor: solo abre la pagina y la mide.
//
//    node tests/_navegador_estados.mjs <sid> <marca> <ancho> <alto> [abrir]
//
//  Imprime UNA linea de JSON. Quien asierta es la prueba en PHP.
// ============================================================
import { spawn } from 'node:child_process';
import fs from 'node:fs'; import os from 'node:os'; import path from 'node:path';
const CHROME='C:/Program Files/Google/Chrome/Application/chrome.exe';
const [sid, marca, aS, hS, abrir, nombre] = process.argv.slice(2);
const ancho=parseInt(aS,10), alto=parseInt(hS,10);
const perfil=fs.mkdtempSync(path.join(os.tmpdir(),'sol-')).split(path.sep).join('/');
const puerto=9050+(process.pid%180);
const dormir=(ms)=>new Promise(r=>setTimeout(r,ms));
const ch=spawn(CHROME,['--headless=new','--disable-gpu','--hide-scrollbars','--no-first-run',
 `--window-size=${ancho},${alto}`,`--user-data-dir=${perfil}`,
 `--remote-debugging-port=${puerto}`,'about:blank'],{stdio:'ignore'});
let cdp=null,id=0; const pend=new Map();
const cmd=(m,p={})=>{const i=++id;cdp.send(JSON.stringify({id:i,method:m,params:p}));
  return new Promise((r,j)=>pend.set(i,{r,j}));};
const ev=async(e)=>{const r=await cmd('Runtime.evaluate',{expression:e,returnByValue:true});
  if(r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description||'js');
  return r.result.value;};
(async()=>{
  let ws=null;
  for(let i=0;i<60&&!ws;i++){try{const r=await fetch(`http://127.0.0.1:${puerto}/json/list`);
    const j=await r.json();const p=j.find(x=>x.type==='page');if(p)ws=p.webSocketDebuggerUrl;}catch(e){}
    if(!ws)await dormir(200);}
  cdp=new (globalThis.WebSocket)(ws); await new Promise(r=>{cdp.onopen=r;});
  cdp.onmessage=(e)=>{const m=JSON.parse(e.data);if(m.id&&pend.has(m.id)){const{r,j}=pend.get(m.id);
    pend.delete(m.id);m.error?j(new Error(m.error.message)):r(m.result);}};
  await cmd('Page.enable');await cmd('Runtime.enable');await cmd('Network.enable');
  await cmd('Emulation.setDeviceMetricsOverride',{width:ancho,height:alto,deviceScaleFactor:1,mobile:ancho<900});
  await cmd('Network.setCookie',{name:'PHPSESSID',value:sid,domain:'localhost',path:'/'});
  await cmd('Page.navigate',{url:`http://localhost/crecer/panel/meta.php?marca=${marca}`});
  for(let i=0;i<200;i++){if(await ev('document.readyState==="complete"'))break;await dormir(120);}
  await dormir(1200);
  await ev(`(function(){var t=['Entendido','¡ENTENDIDO!','Saltar','Cerrar','Listo, ya sé'];
    for(var k=0;k<3;k++){[].forEach.call(document.querySelectorAll('button,a'),function(b){
      var s=(b.textContent||'').trim();
      if(t.some(function(x){return s.toLowerCase()===x.toLowerCase();})&&b.offsetParent!==null)b.click();});}})()`);
  await dormir(900);
  //  CON LAS CAPAS ABIERTAS. Medir solo con todo plegado deja fuera lo que
  //  aparece al abrir: asi se escapo que el boton de confirmar la inversion
  //  quedaba debajo de la barra fija, sin forma de pulsarlo.
  if (abrir === 'abrir') {
    await ev("document.querySelectorAll('details').forEach(function(d){d.open=true;})");
    await dormir(700);
  }
  const r = JSON.parse(await ev(`JSON.stringify((function(){
    var W=innerWidth,H=innerHeight;
    var ah=document.querySelector('.ah'); if(!ah) return {error:'sin .ah'};
    function flot(e){for(var p=e;p&&p!==document.body;p=p.parentElement){
      var po=getComputedStyle(p).position; if(po==='fixed'||po==='sticky') return p;} return null;}
    var todos=[].slice.call(document.querySelectorAll('a[href],button,summary,input,select'))
      .filter(function(e){var r=e.getBoundingClientRect(); return r.width>6&&r.height>6
        && getComputedStyle(e).visibility!=='hidden';});
    //  LO QUE NO PUEDE RECIBIR EL TOQUE NO PUEDE TAPAR NADA.
    //  El panel de Ayuda vive en el DOM con opacity:0 y pointer-events:none;
    //  contarlo como capa daba solapamientos que en la pantalla no existen.
    //  Ojo: el FAB si esta visible, asi que la regla no lo indulta.
    function alcanzable(e){
      for(var p=e;p&&p!==document.documentElement;p=p.parentElement){
        var cs=getComputedStyle(p);
        if(parseFloat(cs.opacity)<0.05) return false;
        if(cs.pointerEvents==='none') return false;
        if(cs.visibility==='hidden'||cs.display==='none') return false;
      }
      return true;
    }
    var capas=todos.filter(flot).filter(alcanzable);
    var mios=[].slice.call(ah.querySelectorAll('a[href],button,summary'))
      .filter(function(e){var r=e.getBoundingClientRect(); return r.width>6&&r.height>6;});
    var tapados=[], chicos=[];
    mios.forEach(function(e){
      e.scrollIntoView({block:'center',inline:'nearest'});
      var a=e.getBoundingClientRect();
      if(a.height<44||a.width<44) chicos.push({t:(e.textContent||'').trim().slice(0,26),
        w:Math.round(a.width),h:Math.round(a.height)});
      capas.forEach(function(c){
        if(c===e||c.contains(e)||e.contains(c)) return;
        var b=c.getBoundingClientRect();
        if(a.left<b.right-1&&b.left<a.right-1&&a.top<b.bottom-1&&b.top<a.bottom-1)
          tapados.push({t:(e.textContent||'').trim().slice(0,26),
                        por:(c.className||c.tagName).toString().trim().slice(0,26)});
      });
    });
    window.scrollTo(0,0);
    var prim=ah.querySelector('.tm-btn');
    var pr=prim?prim.getBoundingClientRect():null;
    var titulares=[].slice.call(ah.querySelectorAll('h1,h2,.tm-frase'))
      .map(function(e){return {t:(e.textContent||'').trim().slice(0,40),
        px:parseFloat(getComputedStyle(e).fontSize)};});
    var bajo14=[];
    [].forEach.call(ah.querySelectorAll('*'),function(e){
      if(e.children.length>0) return;
      var tx=(e.textContent||'').trim(); if(!tx) return;
      var fs=parseFloat(getComputedStyle(e).fontSize);
      if(fs<14) bajo14.push({t:tx.slice(0,24),px:fs});
    });
    var nav=[];
    [].forEach.call(document.querySelectorAll('.botnav a'),function(e){
      var sp=e.querySelector('span:last-child')||e;
      nav.push(parseFloat(getComputedStyle(e).fontSize));
    });
    return {W:W,H:H,doc:Math.round(document.documentElement.scrollHeight),
      tapados:tapados, chicos:chicos, bajo14:bajo14, titulares:titulares,
      nav_px: nav.length? Math.min.apply(null,nav):0,
      primarias: ah.querySelectorAll('.tm-btn:not(.linea)').length,
      prim: pr?{t:(prim.textContent||'').trim().slice(0,26),top:Math.round(pr.top),
                visible: pr.bottom<=H && pr.top>=0}:null,
      scroll_h: document.documentElement.scrollWidth>W+1};
  })())`));
  //  La captura es del ENTREGABLE, no de la medicion: se toma tal como se ve
  //  al entrar, sin scroll, que es lo que la dueña se encuentra.
  if (nombre) {
    await ev('window.scrollTo(0,0)'); await dormir(320);
    const s = await cmd('Page.captureScreenshot', {format:'png'});
    fs.writeFileSync(path.join(process.cwd(), 'tests', '_capturas', nombre + '.png'),
                     Buffer.from(s.data, 'base64'));
  }
  console.log(JSON.stringify(r));
  ch.kill(); process.exit(0);
})().catch(e=>{console.log(JSON.stringify({error:e.message}));try{ch.kill()}catch(x){};process.exit(1)});
