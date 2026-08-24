<?php
// ============================================================
//  CRECER — EL CONTRATO DE IDA Y VUELTA DE TU META
//  core/Meta/MetaRetorno.php
//
//  Fase 3 del contrato de producto: «Cada accion abre el objeto exacto. Al
//  completarla, regresar a Tu Meta con el estado recalculado y confirmacion
//  breve.»
//
//  Antes habia un enlace suelto —«Volver a tu meta»— repetido a mano en tres
//  pantallas. Servia para escapar, no para volver: no distinguia haber
//  terminado de haberse arrepentido, y Tu Meta no se enteraba de nada, asi que
//  no podia confirmar lo que acababa de pasar.
//
//  EL VOCABULARIO ES CERRADO Y VIVE AQUI. La URL solo lleva una LLAVE; el texto
//  que el dueño lee sale de esta tabla. Eso no es pulcritud: una confirmacion
//  armada con texto de la URL es texto ajeno pintado como si fuera de Crecer.
//  Llave que no este en la tabla, no se confirma nada.
//
//  Tampoco se acepta jamas una URL de vuelta por parametro. El regreso se
//  construye aqui con la marca, y punto: un `?volver=<url>` es un redirect
//  abierto esperando a que alguien lo encuentre.
// ============================================================

final class MetaRetorno
{
    /** La marca de ida: dice «vengo de Tu Meta», nada mas. */
    public const MARCADOR = 'volver';
    public const DESDE    = 'meta';

    /**
     * EL SITIO EXACTO de la revision semanal. «Volver a Tu Meta» no basta
     * cuando el dueño estaba en la publicacion 2 de 3: devolverlo al principio
     * le hace repasar dos veces lo que ya decidio, y a la tercera abandona.
     *
     * Es un ENTERO PEQUEÑO y nada mas. No es un destino ni un trozo de URL:
     * la vuelta se sigue armando aqui con la marca. Un `pos` inventado no
     * puede mandar a ningun sitio — como mucho, a una posicion que la vista
     * recorta contra el total real que lee de la base.
     */
    public const POS     = 'pos';
    public const POS_MAX = 200;

    /**
     * Que paso, dicho como se le dice al dueño.
     *   llave => [confirmacion, que sigue]
     *
     * El segundo renglon existe porque el contrato pide consecuencia, no solo
     * acuse de recibo: «Aprobado» a secas deja al dueño preguntandose si ya
     * salio publicado.
     */
    private const HECHOS = [
        'aprobado'   => ['Aprobado.',            'Queda en cola y sale en su fecha.'],
        'material'   => ['Recibí tu material.',  'Ya tienes tu video listo.'],
        'publicado'  => ['Publicado.',           'Ahora toca esperar los números.'],
        'programado' => ['Quedó programado.',    'Sale solo en la fecha que escogiste.'],
        // LA SALIDA MANUAL NO PUEDE AFIRMAR NADA. Antes decía «No cambié nada»,
        // y eso es mentira en cuanto el dueño edita un texto o sube una foto y
        // luego se va por el enlace de arriba: cambió cosas, solo que no
        // termino. Lo unico que consta es que volvio y que aquello sigue
        // pendiente — y eso es lo que se dice.
        'pendiente'  => ['Volviste a tu meta.',  'Esta acción sigue pendiente.'],
    ];

    /** ¿Se llegó a esta pantalla desde Tu Meta? */
    public static function vieneDeMeta(array $get): bool
    {
        return (string)($get[self::MARCADOR] ?? '') === self::DESDE;
    }

    /**
     * Lo que se le pega al destino en la IDA para que sepa volver.
     *
     * Sin `$pos` es exactamente lo de siempre —lo que esperan las pantallas
     * que ya existen—. Con `$pos`, ademas dice de que publicacion salio.
     */
    public static function marcador(?int $pos = null): string
    {
        $s = '&' . self::MARCADOR . '=' . self::DESDE;
        if (self::posValida($pos)) $s .= '&' . self::POS . '=' . (int)$pos;
        return $s;
    }

    /**
     * La posicion que trae la peticion, ya saneada. null = no venia, o venia
     * algo que no es una posicion.
     *
     * Se valida AQUI y no en cada pantalla: son cinco destinos y el que se
     * olvide de comprobarlo pintaria en la URL de vuelta lo que le mandaran.
     */
    public static function posicion(array $get): ?int
    {
        $v = $get[self::POS] ?? null;
        if (!is_scalar($v)) return null;
        $s = trim((string)$v);
        if ($s === '' || !ctype_digit($s)) return null;
        $n = (int)$s;
        return self::posValida($n) ? $n : null;
    }

    /** Un entero pequeño y positivo. Ni cero, ni negativo, ni una novela. */
    private static function posValida(?int $pos): bool
    {
        return $pos !== null && $pos >= 1 && $pos <= self::POS_MAX;
    }

    /**
     * La VUELTA. Siempre conserva la marca — sin ella Tu Meta no sabe de que
     * negocio hablamos y una cuenta con dos negocios aterriza en el que no era.
     *
     * @param string $hecho Llave de HECHOS. Vacia o desconocida = vuelve sin confirmar.
     * @param int|null $pos Posicion de la revision semanal. Con ella, la vuelta
     *        aterriza en la MISMA publicacion; sin ella, donde siempre.
     */
    public static function url(int $marca_id, string $hecho = '', ?int $pos = null): string
    {
        $q = ['marca' => $marca_id];
        if (self::posValida($pos)) { $q['vista'] = 'semana'; $q['pos'] = (int)$pos; }
        if ($hecho !== '' && isset(self::HECHOS[$hecho])) $q['hecho'] = $hecho;
        return '/crecer/panel/meta.php?' . http_build_query($q);
    }

    /**
     * El texto de la confirmacion, o null si la llave no es de las nuestras.
     * Devolver null y no pintar nada es lo correcto: mejor sin confirmacion que
     * con una inventada.
     *
     * @return array{0:string,1:string}|null
     */
    public static function confirmacion(?string $hecho): ?array
    {
        $k = (string)$hecho;
        return self::HECHOS[$k] ?? null;
    }

    /** Las llaves validas — para las pruebas y para no escribirlas a mano. */
    public static function hechos(): array
    {
        return array_keys(self::HECHOS);
    }
}
