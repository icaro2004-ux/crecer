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

import { spawn } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

export const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
export const dormir = (ms) => new Promise((r) => setTimeout(r, ms));

/**
 * Abre Chrome sin cabeza, conecta por CDP y navega.
 *
 * Devuelve { ev, cmd, cerrar } o { error, detalle, url, viewport } si no se
 * pudo ni arrancar — nunca lanza por eso, para que quien llama pueda imprimir
 * el motivo real en su linea de JSON.
 */
export async function abrirChrome({ sid, url, ancho, alto }) {
  const perfil = fs.mkdtempSync(path.join(os.tmpdir(), 'tm-')).split(path.sep).join('/');
  //  Puerto con parte aleatoria: corriendo la suite entera hay varios Chrome a
  //  la vez y dos con el mismo puerto se pisan.
  const puerto = 9000 + Math.floor(Math.random() * 900);

  const ch = spawn(CHROME, [
    '--headless=new', '--disable-gpu', '--hide-scrollbars', '--no-first-run',
    `--window-size=${ancho},${alto}`, `--user-data-dir=${perfil}`,
    `--remote-debugging-port=${puerto}`, 'about:blank',
  ], { stdio: 'ignore' });

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
    try { ch.kill(); } catch (e) {}
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

  return { ev, cmd, cerrar: () => { try { ch.kill(); } catch (e) {} } };
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
