// ============================================================
//  CRECER — ABRIR UN CHROME DE VERDAD Y HABLARLE
//  tests/_chrome.mjs
//
//  El arranque de Chrome y el cableado del CDP vivian dentro de
//  _navegador_estados.mjs. Al aparecer la segunda sonda —la que recorre el
//  wizard— habia dos caminos: copiar sesenta lineas o sacarlas aqui. Copiadas,
//  la proxima leccion que se pague se arregla en un sitio y se queda rota en el
//  otro; y esas lineas ya guardan una leccion cara:
//
//      «Chrome no levanto su puerto» NO es «Invalid URL». Son cosas distintas
//      y se arreglan distinto. Cuando falla el arranque, se dice con nombre y
//      apellido — no con el TypeError que sale al construir un WebSocket con
//      null.
//
//  No mide nada ni afirma nada: abre, conecta y devuelve con que preguntar.
// ============================================================

import { spawn, spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

export const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
export const dormir = (ms) => new Promise((r) => setTimeout(r, ms));

// ══════════════════════════════════════════════════════════════════════
//  LOS PERFILES TEMPORALES SE BORRAN. TODOS. SIEMPRE.
//
//  Cada Chrome de prueba necesita su propio --user-data-dir, y aqui se creaba
//  con mkdtempSync y no se borraba NUNCA: cerrar() mataba el proceso y se
//  olvidaba del directorio. Seis arneses usan este ayudante, asi que cada
//  corrida de la suite dejaba varios. Se acumularon 2.441 y llenaron el disco
//  entero (475 GB al 100%) — la suite se rompio por quedarse sin espacio, no
//  por un fallo de producto.
//
//  Y los otros dos arneses TAMPOCO estaban bien, aunque lo pareciera: tenian su
//  rmSync en el finally, pero mataban solo el proceso PADRE de Chrome. Sus
//  renderers seguian vivos con el perfil abierto, el borrado fallaba por EPERM
//  y el fallo se lo tragaba un `catch {}`. Medido: 35 perfiles `navp-` en una
//  sola pasada de la suite. Por eso los tres borran ahora por AQUI, y por eso
//  la prueba del arnes mira el disco y no el fuente — «tener la llamada» no es
//  limpiar.
//
//  La leccion no es «acordarse»: es que quien CREA el directorio sea quien lo
//  borre, que haya UNA sola forma de hacerlo, y que una prueba lo compruebe.
//
//  LAS DOS REGLAS DEL BORRADO, y no son ceremonia:
//
//    1. SOLO LO PROPIO. Se borra unicamente lo que ESTE proceso creo y anoto
//       en `MIOS`. Un perfil ajeno -de otra prueba corriendo a la vez- no esta
//       en el conjunto y no se toca. Nada de barrer por patron: dos suites en
//       paralelo se robarian el perfil la una a la otra.
//
//    2. SOLO DONDE TOCA. Antes de borrar se comprueba que la ruta es hija
//       DIRECTA del temporal del sistema y que su nombre es el que produce
//       mkdtemp con nuestro prefijo. Un `rm -rf` sobre una ruta calculada sin
//       mirar es como se borra el disco de alguien.
// ══════════════════════════════════════════════════════════════════════

/**
 * Los prefijos de perfil de TODO el arnes. mkdtemp le pega 6 caracteres.
 *
 *   tm-    este ayudante (seis suites)
 *   nav-   _navegador.mjs
 *   navp-  _navegador_presentacion.mjs
 *
 * Los tres estan aqui porque los tres borran por la MISMA puerta. Cada arnes
 * tenia su propio borrado a mano y los dos de fuera se quedaban cortos: mataban
 * el proceso padre, esperaban 400 ms y hacian un rmSync sin reintentos cuyo
 * fallo se tragaba un `catch {}`. Parecia limpieza y no lo era — la suite
 * entera dejaba 35 perfiles `navp-` por corrida.
 */
const PREFIJO  = 'tm-';                       // el que crea ESTE ayudante
const PREFIJOS = ['tm-', 'nav-', 'navp-'];    // los que sabe limpiar
const NOMBRE   = /^(tm|nav|navp)-[A-Za-z0-9]{6}$/;

/**
 * Lo que ESTE proceso creo: ruta -> el Chrome que la tiene abierta.
 *
 * NO basta con la ruta. Un directorio de perfil no se puede borrar mientras
 * Chrome lo tenga abierto —en Windows salta EBUSY o EPERM y `force:true` no
 * ayuda: no es que falte el fichero, es que esta en uso—. Asi que el registro
 * guarda tambien el proceso, para poder matarlo ANTES de borrar.
 *
 * La primera version guardaba solo la ruta y la prueba la puso roja: la
 * corrida que revienta sin llamar a cerrar() dejaba su Chrome vivo, el
 * respaldo intentaba borrar con el proceso todavia sujetando los ficheros, y
 * el perfil sobrevivia. Justo el caso que mas importa.
 */
const MIOS = new Map();

/**
 * ¿Es esta ruta un perfil nuestro, en el sitio donde deberia estar?
 * Se responde con la ruta ABSOLUTA resuelta, no con la que llego.
 */
function esPerfilNuestro(ruta) {
  if (typeof ruta !== 'string' || ruta === '') return null;
  let real, raiz;
  try {
    real = path.resolve(ruta);
    raiz = path.resolve(os.tmpdir());
  } catch { return null; }
  if (real === raiz) return null;                       // jamas el temporal padre
  if (path.dirname(real) !== raiz) return null;         // solo hijos DIRECTOS
  const base = path.basename(real);
  //  OJO: contra la LISTA, no contra el prefijo de este ayudante. Al
  //  generalizar el patron se me quedo aqui un startsWith('tm-') y rechazaba en
  //  silencio todos los `navp-`: registrarPerfil() devolvia null, el perfil
  //  nunca entraba en MIOS y el borrado no encontraba nada que borrar. Parecia
  //  que la limpieza corria —no daba error ninguno— y dejaba 35 por pasada.
  if (!PREFIJOS.some((p) => base.startsWith(p)) || !NOMBRE.test(base)) return null;
  return real;
}

/**
 * Borra UN perfil, y solo si es nuestro. Devuelve true si ya no esta.
 *
 * En Windows, matar Chrome no suelta sus ficheros al instante: el borrado
 * inmediato falla con EBUSY o EPERM. `maxRetries` reintenta sin bloquear el
 * hilo con esperas a ojo.
 */
export function borrarPerfil(ruta) {
  const real = esPerfilNuestro(ruta);
  if (!real || !MIOS.has(real)) return false;   // ajeno o ya borrado: no se toca
  const ch = MIOS.get(real);
  MIOS.delete(real);                            // se descuenta ya: nadie lo borra dos veces

  //  1 · QUE SUELTE LOS FICHEROS — Y NO SOLO EL PADRE.
  //
  //      `ch.kill()` mata el proceso que lanzamos, y ya esta. Chrome levanta
  //      ademas su renderer, su GPU y sus utilidades: esos siguen vivos y
  //      siguen con el perfil abierto, asi que el borrado falla por EPERM
  //      aunque el padre este muerto. Se midio: con ch.kill() a secas el
  //      directorio sobrevivia a los 20 reintentos.
  //
  //      Por eso se mata el ARBOL. `taskkill /T /F` es sincrono, que es lo
  //      unico que sirve dentro de un manejador de 'exit'.
  matarArbol(ch);

  //  2 · BORRAR, REINTENTANDO. Matar no es instantaneo: el sistema tarda en
  //      cerrar los descriptores. `maxRetries` espera de forma SINCRONA, que es
  //      lo unico que se puede hacer dentro de un manejador de 'exit'.
  //  3 · SI NO SE PUDO, NO SE PIERDE LA PISTA. Se vuelve a anotar para que el
  //      respaldo de salida lo intente otra vez: para entonces Chrome ha tenido
  //      mas tiempo de soltar los ficheros. Descontarlo y rendirse era dejar el
  //      directorio huerfano sin que nadie se enterara.
  try {
    fs.rmSync(real, { recursive: true, force: true, maxRetries: 20, retryDelay: 100 });
    if (!fs.existsSync(real)) return true;
  } catch { /* se decide abajo */ }
  MIOS.set(real, null);                         // el proceso ya murio; la ruta sigue pendiente
  return false;
}

/**
 * ANOTA un perfil creado fuera de este ayudante, para que lo limpie igual.
 *
 * Lo usan los dos arneses que lanzan su propio Chrome. Asi hay UNA sola
 * implementacion de «matar el arbol y borrar reintentando», y no tres que se
 * parecen — de las cuales dos estaban mal.
 */
export function registrarPerfil(ruta, ch = null) {
  const real = esPerfilNuestro(ruta);
  if (!real) return null;
  engancharSalida();
  MIOS.set(real, ch);
  return real;
}

/**
 * Mata el Chrome Y todo lo que haya colgado de el.
 *
 * Sincrono a proposito: se llama desde el manejador de 'exit', donde no hay
 * segunda oportunidad ni forma de esperar a una promesa.
 */
function matarArbol(ch) {
  if (!ch || !ch.pid) return;
  if (process.platform === 'win32') {
    //  /T se lleva los hijos; /F no pide permiso. Sin /T, los renderers
    //  sobreviven al padre y el perfil se queda bloqueado.
    try { spawnSync('taskkill', ['/PID', String(ch.pid), '/T', '/F'], { stdio: 'ignore' }); } catch {}
  } else {
    try { process.kill(-ch.pid, 'SIGKILL'); } catch {}
  }
  try { if (!ch.killed) ch.kill('SIGKILL'); } catch {}
}

/** Cuantos perfiles propios siguen vivos. Lo usa la prueba del arnes. */
export function perfilesPendientes() { return MIOS.size; }

//  EL RESPALDO. Si una prueba lanza, o llama a process.exit(), o la matan con
//  Ctrl-C, cerrar() no llega a correr. `exit` cubre el final normal, el
//  process.exit() y la excepcion no capturada -Node emite 'exit' tambien ahi-;
//  las señales hay que atarlas aparte porque no lo emiten.
//
//  Lo que NO se promete: una muerte que el proceso no puede interceptar
//  (SIGKILL, corte de luz). Ahi queda el directorio, y es honesto decirlo.
let enganchado = false;
function engancharSalida() {
  if (enganchado) return;
  enganchado = true;
  //  `.keys()` NO es adorno: MIOS es un Map, y `[...MIOS]` da pares
  //  [ruta, proceso] en vez de rutas. Con el spread a secas, borrarPerfil()
  //  recibia un array, no lo reconocia como ruta suya y devolvia false sin
  //  hacer nada — el respaldo parecia correr y no borraba. Lo destapo la
  //  prueba del arnes, no la lectura.
  const barrer = () => { for (const ruta of [...MIOS.keys()]) borrarPerfil(ruta); };
  process.on('exit', barrer);
  for (const senal of ['SIGINT', 'SIGTERM', 'SIGHUP']) {
    process.on(senal, () => { barrer(); process.exit(130); });
  }
}

/**
 * Abre Chrome sin cabeza, conecta por CDP y navega.
 *
 * Devuelve { ev, cmd, cerrar } o { error, detalle, url, viewport } si no se
 * pudo ni arrancar — nunca lanza por eso, para que quien llama pueda imprimir
 * el motivo real en su linea de JSON.
 */
export async function abrirChrome({ sid, url, ancho, alto }) {
  //  La ruta NATIVA es la que se borra; la de barras es la que entiende el
  //  flag de Chrome. Guardar solo la segunda era parte del problema: no
  //  coincidia con lo que fs espera en Windows.
  engancharSalida();
  const perfilReal = path.resolve(fs.mkdtempSync(path.join(os.tmpdir(), PREFIJO)));
  //  Se anota ANTES de lanzar Chrome: si el spawn revienta, el respaldo ya sabe
  //  que este directorio hay que quitarlo.
  MIOS.set(perfilReal, null);
  const perfil = perfilReal.split(path.sep).join('/');
  //  Puerto con parte aleatoria: corriendo la suite entera hay varios Chrome a
  //  la vez y dos con el mismo puerto se pisan.
  const puerto = 9000 + Math.floor(Math.random() * 900);

  const ch = spawn(CHROME, [
    '--headless=new', '--disable-gpu', '--hide-scrollbars', '--no-first-run',
    `--window-size=${ancho},${alto}`, `--user-data-dir=${perfil}`,
    `--remote-debugging-port=${puerto}`, 'about:blank',
  ], { stdio: 'ignore' });
  //  Ya hay a quien matar: el respaldo puede hacer su trabajo aunque la prueba
  //  no llegue nunca a cerrar().
  MIOS.set(perfilReal, ch);

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
    //  Ni siquiera arranco, pero el directorio YA existe: se creo antes de
    //  lanzarlo. Este era el camino que mas basura dejaba cuando Chrome fallaba
    //  en cadena.
    borrarPerfil(perfilReal);            // mata y borra, por la misma puerta
    return { error: `Chrome no levanto el puerto ${puerto} tras 16s`,
             detalle: ultimoFallo, url, viewport: `${ancho}x${alto}` };
  }

  const cdp = new (globalThis.WebSocket)(ws);
  await new Promise((r, j) => {
    cdp.onopen = r;
    cdp.onerror = () => j(new Error('el socket de Chrome se cayo'));
  });

  let id = 0;
  const pend = new Map();
  cdp.onmessage = (e) => {
    const m = JSON.parse(e.data);
    if (m.id && pend.has(m.id)) {
      const { r, j } = pend.get(m.id); pend.delete(m.id);
      m.error ? j(new Error(m.error.message)) : r(m.result);
    }
  };
  const cmd = (m, p = {}) => {
    const i = ++id; cdp.send(JSON.stringify({ id: i, method: m, params: p }));
    return new Promise((r, j) => pend.set(i, { r, j }));
  };
  const ev = async (e) => {
    const r = await cmd('Runtime.evaluate', { expression: e, returnByValue: true, awaitPromise: true });
    if (r.exceptionDetails) {
      throw new Error('JS: ' + (r.exceptionDetails.exception?.description || r.exceptionDetails.text));
    }
    return r.result.value;
  };

  await cmd('Page.enable'); await cmd('Runtime.enable'); await cmd('Network.enable');
  await cmd('Emulation.setDeviceMetricsOverride',
            { width: ancho, height: alto, deviceScaleFactor: 1, mobile: ancho < 900 });
  await cmd('Network.setCookie', { name: 'PHPSESSID', value: sid, domain: 'localhost', path: '/' });
  await cmd('Page.navigate', { url });
  for (let i = 0; i < 200; i++) {
    if (await ev('document.readyState === "complete"')) break;
    await dormir(120);
  }
  await dormir(1100);

  //  cerrar() NO mata por su cuenta: lo hace borrarPerfil(), que es tambien lo
  //  que corre el respaldo. Una sola forma de cerrar significa que el camino
  //  normal y el camino del desastre no pueden divergir.
  return {
    ev, cmd,
    perfil: perfilReal,                       // la prueba del arnes lo necesita
    cerrar: () => borrarPerfil(perfilReal),
  };
}

/** El Recibimiento sale en cuentas nuevas y tapa la pantalla. Se cierra como lo
 *  cerraria la dueña: pulsando su propio boton. */
export async function cerrarRecibimiento(ev) {
  await ev(`(function(){var t=['Entendido','¡ENTENDIDO!','Saltar','Cerrar','Listo, ya sé'];
    for(var k=0;k<3;k++){[].forEach.call(document.querySelectorAll('button,a'),function(b){
      var s=(b.textContent||'').trim();
      if(t.some(function(x){return s.toLowerCase()===x.toLowerCase();})&&b.offsetParent!==null)b.click();});}})()`);
  await dormir(800);
}
