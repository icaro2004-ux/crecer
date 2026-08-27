<?php
// ============================================================
//  CRECER — UN PNG DE VERDAD, SIN GD
//  tests/_png.php
//
//  POR QUE EXISTE. La prueba del visor tiene que demostrar que la imagen se ve
//  ENTERA y sin recortar. Con el PNG de 1x1 que usa el resto de las suites eso
//  no se puede demostrar: cualquier recorte pasaria desapercibido, y una prueba
//  que no puede fallar no prueba nada. Y esta maquina no tiene GD.
//
//  Asi que se escribe el PNG a mano: firma, IHDR, IDAT con las filas en bruto y
//  IEND. Sin dependencias y con el tamaño que la prueba pida — apaisado o
//  vertical, que es justo lo que hace visible un recorte.
//
//  LA FIRMA VA CON chr() Y NO CON UNA CADENA CON ESCAPES: escrita como texto,
//  el 0x89 acababa codificado en UTF-8 (C2 89) y el archivo no era un PNG.
// ============================================================

/** Un PNG solido de $w x $h. Devuelve los bytes, listos para escribir. */
function png_solido(int $w, int $h, int $r = 40, int $g = 90, int $b = 140): string
{
    $w = max(1, $w); $h = max(1, $h);

    $bloque = function (string $tipo, string $datos): string {
        //  Longitud, tipo, datos y el CRC de tipo+datos. Ese orden y no otro.
        return pack('N', strlen($datos)) . $tipo . $datos
             . pack('N', crc32($tipo . $datos));
    };

    //  IHDR: ancho, alto, 8 bits por canal, color 2 (RGB), sin compresion
    //  especial, filtro estandar, sin entrelazado.
    $ihdr = pack('NN', $w, $h) . chr(8) . chr(2) . chr(0) . chr(0) . chr(0);

    //  Cada fila lleva delante su byte de filtro (0 = ninguno).
    $fila  = chr(0) . str_repeat(chr($r) . chr($g) . chr($b), $w);
    $bruto = str_repeat($fila, $h);

    $firma = chr(137) . 'PNG' . chr(13) . chr(10) . chr(26) . chr(10);
    return $firma
         . $bloque('IHDR', $ihdr)
         . $bloque('IDAT', gzcompress($bruto, 6))
         . $bloque('IEND', '');
}
