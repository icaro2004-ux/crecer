<?php
// ============================================================
//  CRECER — PARTIR UN ARCHIVO SQL EN SENTENCIAS, SIN ROMPERLAS
//  includes/migrador.php
//
//  POR QUE EXISTE. panel/admin_migrar.php partia por `;` a secas:
//
//      explode(';', preg_replace('/^\s*--.*$/m', '', $sql))
//
//  El 21 de agosto eso tumbo una migracion en produccion. La columna llevaba
//  COMMENT 'cuando se le enseño el plan al dueño; NULL = todavia no' y el punto
//  y coma de dentro del texto partio el ALTER por la mitad: la primera mitad
//  fallo y la segunda —«NULL = todavia no'»— entro como sentencia suelta.
//
//  Y habia un segundo agujero de la misma familia: los comentarios `--` solo se
//  quitaban al PRINCIPIO de linea, asi que un comentario al final de una linea
//  de codigo sobrevivia, y un `;` dentro de EL partia igual.
//
//  El parche facil es prohibir el `;` en los comentarios de las migraciones.
//  Eso es pedirle a quien escriba SQL que recuerde una regla que no existe en
//  ningun otro sitio — y se olvidara, porque yo mismo lo olvide un dia despues
//  de arreglarlo en otro archivo. La solucion es que el separador SEPA leer:
//  dentro de comillas, de acentos graves o de un comentario, un `;` es texto.
//
//  Deliberadamente simple: no es un parser de SQL, solo distingue en que
//  contexto esta cada caracter. Es todo lo que hace falta para no partir mal.
// ============================================================

/**
 * Las sentencias reales de un archivo .sql, en orden y ya recortadas.
 *
 * Respeta 'comillas simples', "dobles", `acentos graves`, comentarios -- de
 * linea (esten donde esten) y bloques /* … *\/. El escape de comilla se maneja
 * en sus dos formas: '' doblada y \' con barra.
 *
 * @return string[] sin sentencias vacias
 */
function migracion_sentencias(string $sql): array
{
    $fuera = [];
    $act   = '';
    $n     = strlen($sql);
    $modo  = 'sql';        // sql | comilla | doble | grave | linea | bloque
    $cierre = '';

    for ($i = 0; $i < $n; $i++) {
        $c  = $sql[$i];
        $c2 = $i + 1 < $n ? $sql[$i + 1] : '';

        switch ($modo) {
            case 'linea':
                //  Se traga hasta el fin de linea. El salto SI se conserva:
                //  quitarlo pegaria dos lineas de codigo en una.
                if ($c === "\n") { $modo = 'sql'; $act .= $c; }
                continue 2;

            case 'bloque':
                if ($c === '*' && $c2 === '/') { $modo = 'sql'; $i++; }
                continue 2;

            case 'comilla':
            case 'doble':
                $act .= $c;
                //  \' y \" — la barra escapa al siguiente, sea cual sea.
                if ($c === '\\' && $c2 !== '') { $act .= $c2; $i++; continue 2; }
                if ($c === $cierre) {
                    //  '' dentro de una cadena es una comilla, no el cierre.
                    if ($c2 === $cierre) { $act .= $c2; $i++; continue 2; }
                    $modo = 'sql';
                }
                continue 2;

            case 'grave':
                $act .= $c;
                if ($c === '`') $modo = 'sql';
                continue 2;
        }

        // ── modo sql ──────────────────────────────────────────────────
        if ($c === '-' && $c2 === '-') { $modo = 'linea'; $i++; continue; }
        if ($c === '#')                { $modo = 'linea'; continue; }
        if ($c === '/' && $c2 === '*') { $modo = 'bloque'; $i++; continue; }
        if ($c === "'")  { $modo = 'comilla'; $cierre = "'"; $act .= $c; continue; }
        if ($c === '"')  { $modo = 'doble';   $cierre = '"'; $act .= $c; continue; }
        if ($c === '`')  { $modo = 'grave';   $act .= $c; continue; }

        if ($c === ';') {
            $t = trim($act);
            if ($t !== '') $fuera[] = $t;
            $act = '';
            continue;
        }
        $act .= $c;
    }

    $t = trim($act);
    if ($t !== '') $fuera[] = $t;
    return $fuera;
}

/**
 * ¿Este archivo tiene un `;` en un sitio donde el separador VIEJO lo habria
 * partido mal? Sirve para vigilar las migraciones que todavia no se corrieron.
 * Devuelve los fragmentos problematicos, o [] si esta limpio.
 */
function migracion_puntos_traicioneros(string $sql): array
{
    $malos = [];
    foreach (explode("\n", $sql) as $nl => $linea) {
        //  Un `;` dentro de comillas o despues de un `--` en la misma linea.
        if (preg_match("/'[^'\n]*;[^'\n]*'/", $linea, $m)
            || preg_match('/(--|#)[^\n]*;/', $linea, $m)) {
            $malos[] = ($nl + 1) . ': ' . trim(mb_substr($m[0], 0, 70));
        }
    }
    return $malos;
}
