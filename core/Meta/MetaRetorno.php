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

    /** Lo que se le pega al destino en la IDA para que sepa volver. */
    public static function marcador(): string
    {
        return '&' . self::MARCADOR . '=' . self::DESDE;
    }

    /**
     * La VUELTA. Siempre conserva la marca — sin ella Tu Meta no sabe de que
     * negocio hablamos y una cuenta con dos negocios aterriza en el que no era.
     *
     * @param string $hecho Llave de HECHOS. Vacia o desconocida = vuelve sin confirmar.
     */
    public static function url(int $marca_id, string $hecho = ''): string
    {
        $q = ['marca' => $marca_id];
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
