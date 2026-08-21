<?php
// ============================================================
//  CRECER — ¿MANDA EL LÍMITE DE IMÁGENES EN ESTA PANTALLA?
//  core/Meta/MetaLimiteImagen.php
//
//  Esto decidía la vista con una lista de letras de estado:
//
//      $manda = in_array($E->estado, [E_CRECER_TRABAJA, G_MATERIAL]);
//
//  Y estaba mal por los dos lados:
//
//    · G pide MATERIAL DEL DUEÑO — una foto, un video. Eso lo sube él y no
//      gasta ni una imagen de la cuota. Esconder esa acción le quitaba a la
//      dueña justo lo único que sí podía hacer con el mes agotado.
//    · E cubre dos cosas distintas con la misma letra: un trabajo YA EN
//      MARCHA (que no se para porque se acabe la cuota — su unidad ya está
//      reservada) y trabajo que ni ha empezado (que sí se queda parado).
//
//  La pregunta correcta no es «¿qué letra es?» sino «¿la próxima operación
//  verificable necesita GENERAR una imagen nueva?». Y esa la contesta el
//  estado, que declara lo que consume su siguiente paso. Aquí solo se cruza
//  esa declaración con el libro de la cuota.
//
//  Función pura: ni base de datos, ni reloj, ni red. Por eso se puede probar
//  cada caso sin levantar nada.
// ============================================================

final class MetaLimiteImagen
{
    /**
     * ¿El siguiente paso de este estado necesita pintar una imagen nueva?
     *
     * Lo dice el propio estado en su evidencia. Si no dice nada, la respuesta
     * es NO — y eso es deliberado: ante la duda, no se le quita al dueño una
     * acción que a lo mejor sí puede completar. Equivocarse hacia «no bloquea»
     * deja una pantalla que quizá falle al final; equivocarse hacia «bloquea»
     * esconde una acción que funcionaba.
     */
    public static function necesitaImagen(MetaState $E): bool
    {
        $consume = $E->evidencia['consume'] ?? [];
        return is_array($consume) && in_array('imagen', $consume, true);
    }

    /**
     * ¿La pantalla la manda el límite?
     *
     * Solo si se dan LAS DOS: no quedan imágenes y lo que toca ahora necesita
     * una. Con cuota, o con una acción que no pinta, la pantalla es la normal.
     */
    public static function manda(MetaState $E, ?array $cuota): bool
    {
        if (!is_array($cuota) || empty($cuota['lleno'])) return false;
        return self::necesitaImagen($E);
    }

    /**
     * Lo que se quedó parado, para poder enseñarlo con su nombre.
     *
     * Sale de la evidencia del estado — la jugada o la pieza que el compositor
     * ya eligió—, no de un texto de ejemplo. Si el estado no trae objeto se
     * devuelve vacío y la pantalla no inventa ninguno.
     */
    public static function objetoPausado(MetaState $E): array
    {
        $o = $E->evidencia['objeto'] ?? [];
        if (!is_array($o) || trim((string)($o['titulo'] ?? '')) === '') return [];
        return $o;
    }

    /**
     * QUÉ SIGUE PASANDO, PERO SOLO LO QUE SE PUEDE DEMOSTRAR.
     *
     * Aquí había cuatro renglones fijos —escribo, publico, contesto mensajes,
     * y la fecha— que se pintaban igual para todo el mundo. Tres de los cuatro
     * eran afirmaciones sobre ESTA marca que nadie había comprobado: si no
     * tiene nada aprobado, no va a publicar nada; si no tiene canales
     * conectados, no va a contestar ningún mensaje. Prometerlo en la pantalla
     * del límite es peor que callarlo, porque es justo el momento en que la
     * dueña está decidiendo si esto le sirve.
     *
     * Así que cada renglón sale del retrato o no sale. Lo único que se afirma
     * sin mirar nada es la fecha de renovación, que es del plan y es cierta
     * para cualquiera.
     *
     * @param array $snap  el retrato del negocio (piezas, jugadas…)
     * @param string $reset  cuándo vuelven las imágenes, en dd/mm
     * @return array<int,array{titulo:string,pie:string,ico:string}>
     */
    public static function sigueHaciendo(array $snap, string $reset, string $ahora = ''): array
    {
        $fuera = [];

        //  1 · LO QUE YA ESTÁ APROBADO SÍ VA A SALIR. Publicar no pinta nada:
        //      la imagen de esas piezas ya existe.
        $listas = 0;
        foreach (($snap['piezas'] ?? []) as $p) {
            if (!empty($p['publicado_at'])) continue;
            if (!in_array((string)($p['estado'] ?? ''), ['aprobado', 'programado'], true)) continue;
            $listas++;
        }
        if ($listas > 0) {
            $fuera[] = [
                'titulo' => $listas === 1 ? 'Publico la que ya aprobaste'
                                          : "Publico las {$listas} que ya aprobaste",
                'pie'    => 'su imagen ya está hecha',
                'ico'    => 'calendar',
            ];
        }

        //  2 · LO QUE ESTÁ ESCRITO Y ESPERA TU OK TAMPOCO NECESITA PINTAR.
        $borradores = 0;
        foreach (($snap['piezas'] ?? []) as $p) {
            if ((string)($p['estado'] ?? '') !== 'borrador') continue;
            if (!empty($p['necesita_material'])) continue;
            $borradores++;
        }
        if ($borradores > 0) {
            $fuera[] = [
                'titulo' => $borradores === 1 ? 'Tienes 1 pieza esperando tu OK'
                                              : "Tienes {$borradores} piezas esperando tu OK",
                'pie'    => 'aprobarlas no gasta imágenes',
                'ico'    => 'check-circle',
            ];
        }

        //  3 · LA FECHA. Lo único que se afirma sin mirar nada: es del plan y
        //      vale para cualquier marca.
        if (trim($reset) !== '') {
            $fuera[] = [
                'titulo' => "El {$reset} vuelve la cuota",
                'pie'    => 'podrás retomar lo que quedó en pausa',
                'ico'    => 'clock',
            ];
        }

        return $fuera;
    }
}
