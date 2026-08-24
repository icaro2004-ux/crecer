// ============================================================
//  CRECER — UNA CORRIDA DE CHROME, PARA MIRARLE EL PERFIL
//  tests/_perfil_probe.mjs
//
//  No prueba nada por si sola: abre un Chrome con el arnes compartido, dice
//  QUE directorio de perfil le toco, y termina de la forma que se le pida.
//  Quien asierta es tests/test_arnes_perfiles.php, que mira el disco antes y
//  despues.
//
//    node tests/_perfil_probe.mjs ok      cierra bien -> cerrar() borra
//    node tests/_perfil_probe.mjs falla   lanza sin cerrar -> lo borra el gancho
//    node tests/_perfil_probe.mjs salida  process.exit(3) sin cerrar -> idem
//
//  Chrome se abre contra about:blank: no hace falta ni servidor ni red.
// ============================================================

import { abrirChrome, registrarPerfil, borrarPerfil } from './_chrome.mjs';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const modo = process.argv[2] || 'ok';

//  MODO «prefijos» · sin Chrome, deprisa y al grano.
//
//  El arnes limpia perfiles de TRES prefijos (tm-, nav-, navp-) y la guarda que
//  decide «esto es mio» estuvo mirando uno solo: rechazaba los `navp-` en
//  silencio -devolvia null, sin error- y la limpieza no llegaba a intentarse.
//  Aqui se comprueba cada prefijo por separado, creando un directorio de
//  verdad y pidiendo que lo borre.
if (modo === 'prefijos') {
  const filas = [];
  for (const pre of ['tm-', 'nav-', 'navp-']) {
    const d = fs.mkdtempSync(path.join(os.tmpdir(), pre));
    const anotado = registrarPerfil(d, null) !== null;
    const borrado = borrarPerfil(d);
    filas.push(pre + ':' + (anotado ? 'anotado' : 'RECHAZADO')
                   + ':' + (borrado ? 'borrado' : 'QUEDA')
                   + ':' + (fs.existsSync(d) ? 'EXISTE' : 'ido'));
    try { fs.rmSync(d, { recursive: true, force: true }); } catch {}
  }
  console.log('PREFIJOS=' + filas.join(' | '));
  process.exit(0);
}

const s = await abrirChrome({ sid: 'probe' + process.pid, url: 'about:blank', ancho: 320, alto: 240 });
if (s.error) {
  //  Sin Chrome no hay nada que mirar, pero el perfil ya se borro solo: eso
  //  tambien es parte del contrato y la prueba lo comprueba.
  console.log('SIN_CHROME=1');
  console.log('ERROR=' + s.error);
  process.exit(2);
}

console.log('PERFIL=' + s.perfil);

if (modo === 'falla') {
  //  A PROPOSITO: no se llama a cerrar(). Si el gancho de salida no existiera,
  //  este perfil se quedaria en el disco para siempre — que es exactamente lo
  //  que llevaba pasando.
  throw new Error('fallo a proposito, sin cerrar');
}
if (modo === 'salida') {
  process.exit(3);
}

s.cerrar();
console.log('OK=1');
