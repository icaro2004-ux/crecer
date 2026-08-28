// ============================================================
//  CRECER — HOME EN INGLES, MEDIDA EN UN NAVEGADOR DE VERDAD
//  tests/_navegador_home_en.mjs
//
//  Recorre la portada con ?lang=en y devuelve TODO EL CASTELLANO VISIBLE que
//  queda, con el sitio exacto donde esta.
//
//  EL PROBLEMA QUE HAY QUE RESOLVER PARA QUE ESTO SIRVA
//
//  En Home hay español que TIENE que salir en español aunque la interfaz este
//  en ingles, y marcarlo seria ruido — y una prueba con ruido se desactiva:
//
//    · el nombre del negocio del dueño
//    · el proximo post (es contenido publico de la marca: sigue
//      idioma_contenido, no la interfaz)
//    · lo que escribio la Analista (lo produce la IA, es el producto)
//    · los nombres propios de los agentes
//
//  No se puede saber la procedencia mirando el pixel. Asi que se declaran las
//  REGIONES DE CONTENIDO por selector, con su motivo, y fuera de ellas todo
//  castellano visible es un hallazgo. La lista es corta y explicita: un
//  comodin aqui taparia media pantalla sin que nadie lo decidiera.
//
//    node tests/_navegador_home_en.mjs <sid> <marca> <ancho> <alto> [captura]
//
//  Imprime UNA linea de JSON. Quien asierta es la prueba en PHP.
// ============================================================

import { abrirChrome, cerrarRecibimiento, dormir } from './_chrome.mjs';

const [sid, marca, aS, hS, captura] = process.argv.slice(2);
const ancho = parseInt(aS, 10), alto = parseInt(hS, 10);
const URL_PAGINA = `http://localhost/crecer/panel/index.php?marca=${marca}&lang=en`;

const salir = (o) => { console.log(JSON.stringify(o)); };

const sesion = await abrirChrome({ sid, url: URL_PAGINA, ancho, alto });
if (sesion.error) { salir(sesion); process.exit(1); }
const { ev, cmd, cerrar } = sesion;

try {
  const consola = [];
  await ev(`window.__errs = [];
    window.addEventListener('error', function(e){
      window.__errs.push(String(e.message || 'error').slice(0, 160));
    });
    true;`);

  await cerrarRecibimiento(ev);
  await dormir(700);

  //  ── EL BARRIDO ────────────────────────────────────────────
  //  Se hace DENTRO de la pagina: hay que preguntarle al navegador que es
  //  visible de verdad (getClientRects), no adivinarlo desde el HTML. Un
  //  elemento con display:none tiene texto y no lo lee nadie.
  const barrido = await ev(`(function(){
    //  REGIONES DE CONTENIDO. Cada una con su motivo, y por selector — no por
    //  comodin. Lo que caiga dentro NO se mira.
    var CONTENIDO = [
      ['.hz-next',      'el proximo post: es contenido publico de la marca, sigue idioma_contenido'],
      //  FASE 5 · el adelanto del calendario y los pendientes enseñan titulos
      //  de piezas y de jugadas: los escribio la IA en el idioma de CONTENIDO
      //  del negocio, y no cambian porque el dueño mire la interfaz en ingles.
      ['.in-tit',       'el titulo de la pieza programada: contenido de la marca'],
      ['.in-pend .tx b','lo que falta, dicho con el titulo de su pieza o jugada'],
      ['.hz-nx-cap',    'el caption de esa pieza'],
      ['.an-msg',       'lo que escribio la Analista: lo produce la IA'],
      ['.hz-reco',      'la recomendacion de la Analista: idem'],
      ['#hzIdeaTxt',    'la idea del dia: la escribe el corillo'],
      ['.who .nm',      'el nombre del negocio del dueño'],
      ['.who .av',      'la inicial del negocio'],
      ['.ptop .av',     'la inicial del negocio, en movil'],
      ['.who select',   'los nombres de los negocios del dueño'],
      ['.n-jugada p',   'el titulo de la pieza o la jugada: lo escribio la IA']
    ];
    //  El saludo NO esta en esa lista a proposito: «¡Hola, Ana!» es interfaz
    //  con un dato dentro, y se traduce con %s. Excluirlo entero habria dejado
    //  «¡Hola,» sin traducir para siempre, tapado por el nombre.

    //  NOMBRES PROPIOS. No se traducen y no son un hallazgo — pero van por
    //  lista explicita, no por region: «Encuéntralo» lleva tilde y si no se
    //  declara, el detector lo canta en cada barrido y acaba tapando lo demas.
    var PROPIOS = ['Crecer', 'Encuéntralo', 'by Encuéntralo', '— Encuéntralo',
                   '© Encuéntralo · Crecer', 'Instagram', 'Facebook', 'WhatsApp', 'Reels'];
    var esPropio = function(t){
      var s = (t||'').trim();
      for (var i=0;i<PROPIOS.length;i++) if (s === PROPIOS[i]) return true;
      return false;
    };
    var dentroDeContenido = function(el){
      for (var i=0;i<CONTENIDO.length;i++){ if (el.closest(CONTENIDO[i][0])) return CONTENIDO[i][1]; }
      return null;
    };

    //  ¿Parece castellano escrito para una persona? Marcas duras primero
    //  (letras y signos que el ingles no tiene) y despues palabras funcionales
    //  aisladas. No basta «tener letras»: el ingles tambien las tiene.
    var RX_DURA  = /[áéíóúüñ¿¡]/i;
    var RX_PAL   = new RegExp('(?:^|[\\\\s,.;:!¡¿?()«»"\\'\\\\-])(' +
      'el|la|los|las|un|una|unos|unas|de|del|al|que|qué|tu|tus|su|sus|mi|mis|no|se|si|sí|ya|' +
      'para|con|por|como|cuando|donde|más|menos|está|están|estás|es|son|hay|fue|será|tiene|' +
      'tienes|puede|puedes|vas|voy|aquí|ahora|todo|toda|todos|todas|nada|algo|otro|otra|pero|' +
      'porque|sin|sobre|hoy|día|días|semana|mes|listo|lista|ver|todo' +
      ')(?:$|[\\\\s,.;:!¡¿?()«»"\\'\\\\-])', 'i');
    var esCastellano = function(t){
      var s = (t||'').trim();
      if (s.replace(/[^A-Za-zÀ-ÿ]/g,'').length < 3) return false;
      if (RX_DURA.test(s)) return true;
      return RX_PAL.test(' ' + s + ' ');
    };

    var camino = function(el){
      var p = [];
      for (var e = el; e && e.nodeType === 1 && p.length < 4; e = e.parentElement) {
        var s = e.tagName.toLowerCase();
        if (e.id) { s += '#' + e.id; p.unshift(s); break; }
        if (e.className && typeof e.className === 'string') {
          var c = e.className.trim().split(/\\s+/)[0];
          if (c) s += '.' + c;
        }
        p.unshift(s);
      }
      return p.join(' > ');
    };

    var visible = function(el){
      if (!el || !el.getClientRects) return false;
      if (!el.getClientRects().length) return false;
      var cs = getComputedStyle(el);
      return cs.visibility !== 'hidden' && cs.opacity !== '0';
    };

    var hallazgos = [], contenido = [];
    //  Nodos de TEXTO, no elementos: asi cada frase se reporta donde esta.
    var w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null);
    var nd;
    while ((nd = w.nextNode())) {
      var txt = (nd.nodeValue || '').replace(/\\s+/g,' ').trim();
      if (!txt) continue;
      var el = nd.parentElement;
      if (!el) continue;
      var tag = el.tagName.toLowerCase();
      if (tag === 'script' || tag === 'style' || tag === 'noscript') continue;
      if (!visible(el)) continue;

      var razon = dentroDeContenido(el);
      if (razon) { if (esCastellano(txt)) contenido.push({texto: txt.slice(0,90), razon: razon}); continue; }
      if (esPropio(txt)) continue;
      if (esCastellano(txt)) hallazgos.push({texto: txt.slice(0,90), donde: camino(el), tipo:'texto'});
    }

    //  Atributos que el usuario LEE. Un aria-label en español es tan visible
    //  para quien usa lector de pantalla como el texto para quien ve.
    var ATTRS = ['placeholder','title','alt','aria-label'];
    var els = document.querySelectorAll('[placeholder],[title],[alt],[aria-label]');
    for (var i=0;i<els.length;i++){
      var e2 = els[i];
      if (dentroDeContenido(e2)) continue;
      for (var k=0;k<ATTRS.length;k++){
        var v = e2.getAttribute(ATTRS[k]);
        if (v && !esPropio(v) && esCastellano(v)) {
          hallazgos.push({texto: v.slice(0,90), donde: camino(e2)+' ['+ATTRS[k]+']', tipo:'attr'});
        }
      }
    }

    //  LO QUE EL JAVASCRIPT TIENE GUARDADO PARA ESCRIBIR DESPUES.
    //  Un texto que solo aparece al fallar algo no se ve en este barrido, y es
    //  justo el que mas se olvida. window.T es lo que el PHP le entrego.
    var deJs = [];
    try {
      if (window.T) {
        for (var kk in window.T) {
          if (Object.prototype.hasOwnProperty.call(window.T, kk) && esCastellano(String(window.T[kk]))) {
            deJs.push({clave: kk, texto: String(window.T[kk]).slice(0,90)});
          }
        }
      }
    } catch(e){}

    return JSON.stringify({hallazgos: hallazgos, contenido: contenido, deJs: deJs,
                           lang: document.documentElement.lang || ''});
  })()`);

  const datos = JSON.parse(barrido || '{}');

  //  ── LOS MODALES Y LOS ERRORES, SIN RED ────────────────────
  //  El panel de Ayuda y sus mensajes solo existen tras un clic. Se abren a
  //  mano y se vuelve a barrer: un texto que solo aparece al pedir ayuda es
  //  tan visible como el resto.
  const modales = await ev(`(function(){
    var out = [];
    var RX_DURA = /[áéíóúüñ¿¡]/i;
    var esp = function(t){ var s=(t||'').trim();
      return s.replace(/[^A-Za-zÀ-ÿ]/g,'').length>=3 && (RX_DURA.test(s) ||
        /(^|\\s)(el|la|los|las|un|una|de|que|tu|no|se|para|con|por|está|es|hay|ver|todo)(\\s|$)/i.test(s)); };
    var mirar = function(raiz, etq){
      if (!raiz) return;
      var w = document.createTreeWalker(raiz, NodeFilter.SHOW_TEXT, null), nd;
      while ((nd = w.nextNode())) {
        var t = (nd.nodeValue||'').replace(/\\s+/g,' ').trim();
        if (t && esp(t)) out.push({texto: t.slice(0,90), donde: etq, tipo:'modal'});
      }
      var els = raiz.querySelectorAll('[placeholder],[aria-label],[title]');
      for (var i=0;i<els.length;i++){
        ['placeholder','aria-label','title'].forEach(function(a){
          var v = els[i].getAttribute(a);
          if (v && esp(v)) out.push({texto: v.slice(0,90), donde: etq+' ['+a+']', tipo:'modal'});
        });
      }
    };
    //  El Ayudante: se abre su panel sin pedir nada a la red.
    var fab = document.querySelector('.ay-fab, #ayFab, [class*="ay-"][class*="fab"]');
    if (fab) { try { fab.click(); } catch(e){} }
    mirar(document.querySelector('.ay-panel, #ayPanel, [class*="ay-panel"]'), 'ayudante');
    //  La guia del corillo, si esta.
    var gb = document.getElementById('guiaBtn');
    if (gb) { try { gb.click(); } catch(e){} }
    mirar(document.getElementById('guiaOv'), 'guia');
    return JSON.stringify(out);
  })()`);

  await dormir(250);
  const delModal = JSON.parse(modales || '[]');

  //  El proximo post TIENE que seguir en español: es contenido de la marca.
  const nextPost = await ev(`(function(){
    //  Antes esto era la tarjeta del «proximo post»; en la Fase 5 esa tarjeta
    //  se convirtio en el adelanto del calendario. La regla es la misma: lo
    //  que se enseña ahi es contenido de la marca y sigue en su idioma.
    var n = document.querySelector('.hz-next') || document.querySelector('.in-list .in-tit');
    if (!n) return JSON.stringify({hay:false});
    return JSON.stringify({hay:true, texto: (n.innerText||'').replace(/\\s+/g,' ').trim().slice(0,200)});
  })()`);

  const errs = JSON.parse(await ev('JSON.stringify(window.__errs || [])') || '[]');

  if (captura) {
    const png = await cmd('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
    if (png?.data) (await import('node:fs')).writeFileSync(captura, Buffer.from(png.data, 'base64'));
  }

  salir({
    ok: true, ancho, alto, lang: datos.lang || '',
    hallazgos: (datos.hallazgos || []).concat(delModal),
    contenido: datos.contenido || [],
    deJs: datos.deJs || [],
    nextPost: JSON.parse(nextPost || '{}'),
    consola: errs,
  });
} catch (e) {
  salir({ error: String(e && e.message || e) });
} finally {
  await cerrar();
}
