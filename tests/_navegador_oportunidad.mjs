// ============================================================
//  CRECER — LA TARJETA DE OPORTUNIDAD, USADA DE VERDAD
//  tests/_navegador_oportunidad.mjs
//
//  Tres escenas, una por respuesta, mas una que solo mira. Lo que se mide aqui
//  no se puede leer en el fuente:
//
//    · que el aviso «tu fecha no se borra» este DONDE estan los botones y no
//      al final de la pagina — se mide la distancia en pixeles, no la
//      presencia del texto;
//    · que descartar conteste EN SITIO, sin recargar;
//    · y que tres clics manden UN solo POST.
//
//  window.alert se cambia por un contador antes de tocar nada, como en las
//  demas sondas.
//
//    node tests/_navegador_oportunidad.mjs <sid> <marca> <escena> [ancho] [alto]
// ============================================================

import fs from 'node:fs';
import path from 'node:path';
import { abrirChrome, cerrarRecibimiento, dormir } from './_chrome.mjs';

const [sid, marca, escena, aS, hS, captura] = process.argv.slice(2);
const ancho = parseInt(aS || '360', 10), alto = parseInt(hS || '800', 10);
const URL_PAGINA = `http://localhost/crecer/panel/meta.php?marca=${marca}&vista=plan`;

const salir = (o) => { console.log(JSON.stringify(o)); };

const ch = await abrirChrome({ sid, url: URL_PAGINA, ancho, alto });
if (ch.error) { salir(ch); process.exit(1); }
const { ev, cmd, cerrar } = ch;

//  La tarjeta vive DESPUES de las jugadas, asi que una foto a scroll 0 no la
//  enseña. Se la lleva a la vista antes de disparar: una captura que no
//  contiene lo que dice enseñar no es una prueba, es un adorno.
const fotografiar = async (nombre) => {
  if (!nombre) return;
  await ev(`(function(){var c=document.getElementById('opCard');
    if(c) c.scrollIntoView({block:'center'});})()`);
  await dormir(900);
  const s = await cmd('Page.captureScreenshot', { format: 'png' });
  fs.writeFileSync(path.join(process.cwd(), 'tests', '_capturas', nombre + '.png'),
                   Buffer.from(s.data, 'base64'));
};

try {
  await cerrarRecibimiento(ev);

  await ev(`sessionStorage.setItem('opAlertas','0');
    window.alert = function(){
      sessionStorage.setItem('opAlertas', String(+sessionStorage.getItem('opAlertas') + 1));
    };
    sessionStorage.setItem('opPosts','0');
    var real = window.fetch;
    window.fetch = function(u, o){
      var b = o && o.body, acc = (b && b.get) ? b.get('accion') : '';
      if (acc && acc.indexOf('oport_') === 0)
        sessionStorage.setItem('opPosts', String(+sessionStorage.getItem('opPosts') + 1));
      return real.apply(window, arguments);
    };`);
  const contar = (k) => ev(`+(sessionStorage.getItem('${k}') || 0)`);

  const hay = await ev(`!!document.getElementById('opCard')`);
  if (!hay) { salir({ escena, hay: false }); cerrar(); process.exit(0); }

  //  LA DISTANCIA DEL AVISO A LOS BOTONES. Que el texto exista no sirve de
  //  nada si vive a media pagina: se mide en pixeles.
  const leer = () => ev(`JSON.stringify((function(){
    var c = document.getElementById('opCard');
    var pie = c.querySelector('.op-pie');
    var bts = c.querySelectorAll('.op-acc button');
    var ult = bts.length ? bts[bts.length - 1].getBoundingClientRect() : null;
    var pr  = pie ? pie.getBoundingClientRect() : null;
    var dist = (ult && pr) ? Math.round(pr.top - ult.bottom) : -1;
    var hecho = document.getElementById('opHecho');
    return {
      hay: true,
      origen: c.dataset.origen, id: +c.dataset.id, fecha_iso: c.dataset.fecha,
      titulo: (c.querySelector('.op-tit b').textContent || '').trim(),
      tuya: !!c.querySelector('.op-tuya'),
      fecha: (c.querySelector('.op-fecha') || {}).textContent
             ? c.querySelector('.op-fecha').textContent.trim() : '',
      botones: bts.length,
      pie: pie ? pie.textContent.trim() : '',
      pie_dist: dist,
      //  «Cerca» = pegado al grupo de botones, no a media pagina de distancia.
      pie_cerca: dist >= 0 && dist < 60,
      hecho_visible: hecho ? hecho.classList.contains('on') : false,
      hecho: hecho ? (document.getElementById('opHechoTx').textContent || '').trim() : '',
      hecho_html: hecho ? (document.getElementById('opHechoTx').innerHTML || '') : '',
      alertas: +(sessionStorage.getItem('opAlertas') || 0)
    };
  })())`).then(JSON.parse);

  if (escena === 'mirar') {
    const r = await leer();
    await fotografiar(captura);
    salir({ escena, ...r });
    cerrar(); process.exit(0);
  }

  const boton = { descartar: '#opNo', anadir: '#opAdd', luego: '#opLuego' }[escena];
  if (!boton) { salir({ error: 'escena desconocida: ' + escena }); cerrar(); process.exit(1); }

  const antes = await leer();
  //  Marca en la pagina: si sobrevive, no hubo recarga. Recargar por decir
  //  «esta no» devolveria al dueño arriba de una pagina larga.
  await ev(`window.__sigoAqui = true;`);

  //  Tres clics en el mismo suspiro, como el dedo de quien no sabe si le hizo caso.
  await ev(`(function(){var b=document.querySelector('${boton}'); b.click(); b.click(); b.click();})()`);
  await dormir(1800);

  const tras = await leer();
  salir({ escena, origen: antes.origen, titulo: antes.titulo,
          recargo: !(await ev('window.__sigoAqui === true')),
          hecho_visible: tras.hecho_visible, hecho: tras.hecho, hecho_html: tras.hecho_html,
          posts: await contar('opPosts'), alertas: await contar('opAlertas'),
          url: await ev('location.href') });
  cerrar(); process.exit(0);

} catch (e) {
  salir({ error: e.message, escena, url: URL_PAGINA, viewport: `${ancho}x${alto}`,
          pista: 'error del arnes, no necesariamente de la pantalla' });
  cerrar(); process.exit(1);
}
