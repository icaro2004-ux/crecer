<?php
// ============================================================
//  CRECER - LA SONDA MIRA ESTE ARBOL, Y NO GASTA
//  tests/_arbol_servido.php
//
//  Prologo comun de las pruebas que abren un navegador. Resuelve de una vez el
//  fallo que costo dinero descubrirlo:
//
//  El centinela `_SIN_CREDENCIALES` se escribe en el arbol de la prueba, pero
//  `ia.php` lo lee desde el arbol que Apache esta sirviendo. Mientras son el
//  mismo (el caso normal, /crecer) todo cuadra. Con dos worktrees a la vez -lo
//  normal cuando dos ramas se preparan en paralelo- NO cuadra, y pasan dos
//  cosas a la vez, las dos malas:
//
//    1. la prueba valida EN SILENCIO los archivos de la otra rama;
//    2. la sonda llama al proveedor DE VERDAD, porque alli no hay centinela.
//       Medido el 2026-08-29: 0.001393 USD en una sola corrida.
//
//  Aqui se arreglan las dos: se apunta la sonda a ESTE arbol (CRECER_BASE) y se
//  le pone al navegador una valla que reescribe las llamadas absolutas
//  `/crecer/...` para que no se salgan (CRECER_PREFIJO, la aplica _chrome.mjs).
//  El centinela se escribe donde toca y se borra en el shutdown, pase lo que
//  pase — incluido un fatal a mitad de la prueba.
//
//  Uso:
//      $SRV = arbol_servido();
//      if (!$SRV['ok']) { echo $SRV['motivo']; }   // saltar la parte de pantalla
//
//  @return array{ok:bool, base:string, prefijo:string, motivo:string}
// ============================================================

/** Prepara el entorno de sonda para el arbol donde vive este archivo. */
function arbol_servido(int $timeout = 8): array {
    $raiz    = dirname(__DIR__);
    $prefijo = '/' . rawurlencode(basename($raiz));
    $base    = 'http://localhost' . $prefijo;

    if (!is_file('C:/Program Files/Google/Chrome/Application/chrome.exe')) {
        return ['ok' => false, 'base' => $base, 'prefijo' => $prefijo,
                'motivo' => "\n  (sin Chrome: la parte de pantalla queda sin correr)\n"];
    }
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true]]);
    if (@file_get_contents($base . '/login.php', false, $ctx) === false) {
        return ['ok' => false, 'base' => $base, 'prefijo' => $prefijo,
                'motivo' => "\n  (Apache no sirve {$prefijo}: la parte de pantalla queda sin correr)\n"];
    }

    //  EL CENTINELA VA EN ESTE ARBOL — que es el que la sonda va a mirar — y se
    //  quita solo. register_shutdown_function corre tambien tras un fatal, que
    //  es justo cuando mas falta hace: un centinela olvidado deja el arbol en
    //  modo mock y la siguiente prueba mediria humo.
    $cent = $raiz . '/includes/_SIN_CREDENCIALES';
    file_put_contents($cent, "sonda · " . date('c') . "\n");
    register_shutdown_function(function () use ($cent) { @unlink($cent); });

    putenv('CRECER_BASE=' . $base . '/panel');
    putenv('CRECER_PREFIJO=' . $prefijo);

    return ['ok' => true, 'base' => $base, 'prefijo' => $prefijo, 'motivo' => ''];
}

/**
 * ¿Se escapo alguna peticion al otro arbol pese a la valla? La sonda puede
 * reportar `window.__fuera`; si trae algo, la valla dejo pasar un camino nuevo
 * y hay que mirarlo antes de fiarse del resultado.
 */
function fuga_reportada(array $R): array {
    $j = json_decode((string)($R['FUERA'] ?? '[]'), true);
    return is_array($j) ? $j : [];
}
