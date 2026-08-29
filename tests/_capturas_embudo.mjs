// ============================================================
//  CRECER - CAPTURAS DEL EMBUDO, SIN SALIRSE DE ESTE ARBOL
//  tests/_capturas_embudo.mjs
//
//  Toma una captura por URL a 360x800 y nada mas. El estado que se retrata lo
//  deja el PHP que invoca: cada captura sale de una fila distinta en la base,
//  no de un `?paso=` que finja el momento.
//
//  LA REESCRITURA DE RED ES LO QUE HACE ESTO SEGURO.
//  Las paginas piden sus AJAX a rutas ABSOLUTAS `/crecer/...`. Servido desde un
//  worktree paralelo, eso manda al navegador al arbol de la OTRA rama -donde no
//  esta el centinela `_SIN_CREDENCIALES` y las llamadas se pagan de verdad-.
//  Con `Fetch` interceptando, toda peticion a `/crecer/` se reescribe a este
//  arbol antes de salir. Sin esto, sacar capturas costaria dinero.
//
//    node _capturas_embudo.mjs <shots> <sid> <base> <nombre=url> [nombre=url...]
// ============================================================
import fs from 'node:fs';
import { abrirChrome, dormir, cerrarRecibimiento } from './_chrome.mjs';

const shots = process.argv[2];
const sid   = process.argv[3];
const BASE  = process.argv[4];                      // ej: http://localhost/crecer-hotfix-post-gratis
const PASOS = process.argv.slice(5).map((s) => {
  const i = s.indexOf('=');
  return [s.slice(0, i), s.slice(i + 1)];
});
const di = (k, v) => console.log(k + '=' + String(v).replace(/\r?\n/g, ' '));

//  El prefijo de ESTE arbol, sacado de la base. Todo `/crecer/...` se reescribe
//  aqui dentro.
const PREFIJO = new URL(BASE).pathname.replace(/\/$/, '');

//  ── LA VALLA, INYECTADA ANTES QUE EL JS DE LA PAGINA ────────────────
//  Se penso hacerlo con `Fetch.enable`, que es lo canonico, pero el helper de
//  Chrome de este repo no expone la suscripcion a eventos: sin manejador de
//  `Fetch.requestPaused` toda peticion se queda colgada y la pagina no carga.
//  Un shim sobre fetch/XHR/submit hace el mismo trabajo para lo que importa -las
//  llamadas que podrian gastar- y no depende de nada del transporte.
//  Los assets sí siguen saliendo a /crecer/assets: son los mismos bytes en las
//  dos ramas y no llaman a ningun modelo.
const SONDA = `
  window.__errs = [];
  window.__fuera = [];
  addEventListener('error', function (e) { window.__errs.push(String(e.message)); });
  try { ['propuestas','crear','sala','meta','inicio','calendario','resultados']
          .forEach(function (k) { localStorage.setItem('guia_' + k, '1'); }); } catch (e) {}
  (function (PREF) {
    function fix(u) {
      try {
        var s = String(u);
        if (s.indexOf('/crecer/') === 0) { window.__fuera.push(s); return PREF + s.slice(7); }
        return s;
      } catch (e) { return u; }
    }
    var of = window.fetch;
    if (of) window.fetch = function (a, b) { return of.call(this, (typeof a === 'string' ? fix(a) : a), b); };
    var oo = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function (m, u) {
      var args = [].slice.call(arguments); args[1] = fix(u);
      return oo.apply(this, args);
    };
    addEventListener('submit', function (e) {
      var f = e.target; if (f && f.action) { var n = fix(f.getAttribute('action') || ''); if (n) f.setAttribute('action', n); }
    }, true);
  })(${JSON.stringify(PREFIJO)});
`;

let ch;
try {
  ch = await abrirChrome({ sid, url: 'about:blank', ancho: 360, alto: 800 });
  const { ev, cmd } = ch;

  await cmd('Page.addScriptToEvaluateOnNewDocument', { source: SONDA });
  await cmd('Network.setCookie', { name: 'PHPSESSID', value: sid, domain: 'localhost', path: '/' });
  await cmd('Emulation.setDeviceMetricsOverride',
            { width: 360, height: 800, deviceScaleFactor: 1, mobile: true });

  let n = 0;
  for (const [nombre, url] of PASOS) {
    //  `@js:` no navega: actua sobre la pagina que ya esta. Es lo que permite
    //  retratar una puerta que solo existe cuando el dueño pulsa (el gate del
    //  SMS es un modal, no una URL).
    if (url.startsWith('@js:')) {
      await ev(url.slice(4));
      //  Margen ancho a proposito: estos pasos encadenan clics con sus propias
      //  esperas (aprobar va por AJAX antes de que aparezca el panel siguiente).
      await dormir(2600);
    } else {
      await cmd('Page.navigate', { url: BASE + url });
      for (let i = 0; i < 120; i++) {
        if (await ev('document.readyState === "complete"')) break;
        await dormir(120);
      }
      await dormir(900);
      try { await cerrarRecibimiento(ev); } catch (e) {}
      await dormir(300);
    }
    const png = await cmd('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
    fs.writeFileSync(`${shots}/${nombre}.png`, Buffer.from(png.data, 'base64'));
    di('SHOT_' + nombre, (await ev('document.title')) || '?');
    n++;
  }
  di('N', n);
  di('OK', 1);
} catch (e) {
  di('ERR', e && e.message ? e.message : String(e));
  di('OK', 0);
} finally {
  try { ch?.cerrar(); } catch (e) {}
}
