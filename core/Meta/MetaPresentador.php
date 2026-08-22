<?php
// ============================================================
//  CRECER — CÓMO SE DICE UN ESTADO DE LA META
//  core/Meta/MetaPresentador.php
//
//  El compositor decide QUÉ pasa. Esto decide CÓMO se dice. Son dos cosas
//  distintas y hasta ahora la segunda vivía dentro de la vista de Tu Meta
//  (panel/meta.php), donde Home no podía alcanzarla.
//
//  POR QUÉ ESTO TIENE QUE EXISTIR
//
//  El compositor devuelve títulos que se explican solos —«Para seguir,
//  necesito tu video»— porque los pensó para leerse sueltos. Tu Meta, que ya
//  enseña el turno encima, les quita el prefijo. Además reescribe el título
//  entero en dos casos: cuando manda el límite de imágenes y cuando la meta
//  cerró.
//
//  Con esas tres transformaciones dentro de la vista, Home solo podía hacer una
//  de dos cosas: enseñar un título distinto al de Tu Meta, o copiar el código.
//  Las dos son la misma enfermedad — la regla en dos sitios— y ya sabemos cómo
//  acaba: un día divergen y el dueño ve dos pantallas que se contradicen sobre
//  su propio negocio.
//
//  AQUÍ NO SE DECIDE NADA DEL NEGOCIO. No se lee la base, no se llama a ningún
//  modelo, no se mira el reloj más que para el nombre del mes. Entra un estado
//  y sale una frase.
// ============================================================

require_once __DIR__ . '/MetaState.php';
require_once __DIR__ . '/MetaLimiteImagen.php';

final class MetaPresentador
{
    /** Cómo se nombra lo que se cuenta, y cómo se dice que Crecer lo contó. */
    private const UNIDAD = [
        'pedidos'        => ['pedidos',       'registrados'],
        'ventas'         => ['en ventas',     'registradas'],
        'conversaciones' => ['mensajes',      'recibidos'],
        'alcance'        => ['personas',      'alcanzadas'],
        'comunidad'      => ['interacciones', 'contadas'],
    ];

    private const MESES = ['enero','febrero','marzo','abril','mayo','junio','julio',
                           'agosto','septiembre','octubre','noviembre','diciembre'];

    /** @return array{0:string,1:string} */
    public static function unidad(string $objetivo): array
    {
        return self::UNIDAD[$objetivo] ?? ['resultados', 'registrados'];
    }

    /** El mes de hoy, en castellano. La cuota se cuenta por mes natural. */
    public static function mes(?int $n = null): string
    {
        return self::MESES[($n ?? (int)date('n')) - 1] ?? '';
    }

    /**
     * El título con el que se anuncia un estado.
     *
     * @param bool  $conTurno  true cuando la pantalla YA enseña de quién es el
     *                         turno (Tu Meta). Entonces el prefijo del título
     *                         sobra: con la pastilla delante, «Para seguir,
     *                         necesito tu video» dice dos veces lo mismo.
     *                         Home lo pinta suelto, así que lo conserva.
     * @param array $cuota     el estado del cubo de imágenes, o [] si no aplica.
     * @param array $snap      el snapshot, solo para el cierre (M).
     */
    public static function titulo(MetaState $E, bool $conTurno = false,
                                  array $cuota = [], array $snap = []): string
    {
        $titulo = trim($E->titulo);

        //  1 · EL PREFIJO SOBRA CUANDO EL TURNO YA ESTÁ DELANTE.
        if ($conTurno) {
            foreach (['Para seguir, necesito', 'Nada pendiente de ti'] as $pref) {
                if (stripos($titulo, $pref) !== 0) continue;
                $resto = trim(mb_substr($titulo, mb_strlen($pref)));
                if ($resto !== '') {
                    $titulo = mb_strtoupper(mb_substr($resto, 0, 1)) . mb_substr($resto, 1);
                }
                break;
            }
        }

        //  2 · CON EL LÍMITE MANDANDO, SE DICE ENTERO.
        //  Cambiar solo la pastilla y dejar el título del estado normal era
        //  medio aviso: la dueña leía «Me toca a mí» con una pastilla ámbar al
        //  lado y tenía que atar cabos.
        if (MetaLimiteImagen::manda($E, $cuota ?: null)) {
            $lim = (int)($cuota['limite'] ?? 0);
            return $lim > 0
                ? 'Usaste las ' . $lim . ' imágenes de ' . self::mes()
                : 'Se acabaron las imágenes de ' . self::mes();
        }

        //  3 · EL CIERRE DICE CON CUÁNTO, Y SIEMPRE «REGISTRADOS».
        //  Sin esa palabra, «18 pedidos» se lee como los pedidos del negocio, y
        //  Crecer solo cuenta los que pasaron por aquí.
        if ($E->estado === MetaState::M_CERRADA
            && !empty($snap['meta'])
            && ($snap['progreso']['actual'] ?? null) !== null) {
            [$sust, $part] = self::unidad((string)($snap['meta']['objetivo'] ?? ''));
            $n = rtrim(rtrim(number_format((float)$snap['progreso']['actual'], 2), '0'), '.');
            return 'Cerraste con ' . $n . ' ' . $sust . ' ' . $part;
        }

        return $titulo;
    }

    /**
     * El objeto del que habla la pantalla — la pieza, el reel, el carrusel.
     *
     * Cuando manda el límite, el objeto es el que quedó EN PAUSA, no el del
     * estado normal: enseñar el otro sería hablar de algo que hoy no se puede
     * tocar. Si no hay ninguno, se devuelve vacío y la pantalla no se inventa
     * uno.
     */
    public static function objeto(MetaState $E, array $cuota = []): array
    {
        if (MetaLimiteImagen::manda($E, $cuota ?: null)) {
            $pausado = MetaLimiteImagen::objetoPausado($E);
            if ($pausado) return $pausado;
        }
        return (array)($E->evidencia['objeto'] ?? []);
    }

    /**
     * A dónde lleva la acción, y con qué palabras se ofrece.
     *
     * Sale del compositor tal cual: aquí no se inventa ningún destino. Lo único
     * que se añade es el caso del límite, donde la acción normal no se puede
     * completar y lo honesto es llevar a ver lo que YA está listo.
     *
     * @return array{etiqueta:string,destino:string,tipo:string}
     */
    public static function accion(MetaState $E, array $cuota = [], string $base = '/crecer/panel',
                                  int $marca_id = 0): array
    {
        $a = (array)$E->accion;
        if (MetaLimiteImagen::manda($E, $cuota ?: null)) {
            return [
                'etiqueta' => 'Ver lo que ya está listo',
                'destino'  => $base . '/propuestas.php?marca=' . $marca_id,
                'tipo'     => 'limite',
            ];
        }
        return [
            'etiqueta' => (string)($a['etiqueta'] ?? ''),
            'destino'  => (string)($a['destino'] ?? ''),
            'tipo'     => (string)($a['tipo'] ?? ''),
        ];
    }

    // ══════════════════════════════════════════════════════════════
    //  LA FRONTERA DE HOME
    // ══════════════════════════════════════════════════════════════
    /**
     * Todo lo que Home puede pintar de la meta, y NADA mas.
     *
     * POR QUE ESTO ES UN DTO Y NO UNA LISTA DE AYUDANTES
     *
     * La primera version de la Fase 5 calculaba $E->resumen(), lo dejaba sin
     * usar, y despues renderizaba leyendo el estado completo, la evidencia y el
     * snapshot. La frontera existia en el papel y no en el codigo: Home seguia
     * sabiendo del detalle, y bastaba con que alguien añadiera un `if` sobre
     * $E->evidencia para volver a tener dos pantallas decidiendo por su cuenta.
     *
     * Aqui sale un array cerrado. Lo que no este en el, Home no lo puede pintar
     * — no porque este prohibido, sino porque no lo tiene. Esa es la unica
     * clase de frontera que aguanta.
     *
     * LO QUE NO SALE, Y POR QUE
     *
     *   · la evidencia    Home no abre objetos: los nombra. Con el objeto
     *                     entero volveria a poder decidir con el detalle.
     *   · la cobertura    se resuelve AQUI en un booleano. Si viajara, Home
     *                     tendria que volver a interpretarla.
     *   · el snapshot     no es de Home. Es la materia prima del compositor.
     *
     * `barra` es null cuando no se puede afirmar progreso. No es «pinta la
     * barra vacia»: es que no hay barra. Asi Home no puede enseñarla ni por
     * descuido, que es como estaba antes de esta fase.
     *
     * @return array{estado:string,sin_meta:bool,cerrada:bool,titulo:string,
     *               turno:array{txt:string,cls:string},
     *               accion:array{etiqueta:string,destino:string},
     *               objeto:array{titulo:string},
     *               cifra:array{grande:string,pie:string,cuenta:?int},
     *               dias:?int, puede:bool,
     *               barra:?array{pct:int,ritmo:string}}
     */
    public static function paraHome(MetaState $E, array $cuota, array $snap,
                                    string $base, int $marca_id): array
    {
        //  El formateador canonico vive en el dominio. Duplicarlo aqui seria
        //  exactamente la enfermedad que este archivo existe para curar; es la
        //  misma dependencia perezosa que ya usa MetaSnapshotReader.
        if (!function_exists('meta_fmt')) {
            require_once dirname(__DIR__, 2) . '/includes/meta_negocio.php';
        }

        $res   = $E->resumen();                     // estado · titulo · accion · razon
        $meta  = (array)($snap['meta'] ?? []);
        $prog  = (array)($snap['progreso'] ?? []);
        $puede = $E->puedeAfirmarProgreso();
        $obj   = (string)($meta['objetivo'] ?? '');

        $accion = self::accion($E, $cuota, $base, $marca_id);
        if (($accion['destino'] ?? '') === '') {
            $accion['destino'] = $base . '/meta.php?marca=' . $marca_id;
        }

        //  LA CIFRA. Con cobertura completa se enseña lo que lleva; sin ella, la
        //  meta a secas. «0 de 25» con cobertura parcial se lee como «no has
        //  vendido nada», y lo unico cierto es que Crecer no lo vio.
        $objeto  = self::objeto($E, $cuota);
        $cifra   = ['grande' => '', 'pie' => '', 'cuenta' => null];
        if ($meta) {
            $cantidad = $meta['cantidad'] !== null ? (float)$meta['cantidad'] : null;
            if ($puede && ($prog['actual'] ?? null) !== null) {
                $cifra = [
                    'grande' => (string)(int)$prog['actual'],
                    'pie'    => 'de ' . meta_fmt($cantidad, $obj),
                    'cuenta' => (int)$prog['actual'],
                ];
            } else {
                $def = function_exists('meta_objetivo_def') ? meta_objetivo_def($obj) : [];
                $cifra = [
                    'grande' => (string)meta_fmt($cantidad, $obj),
                    'pie'    => (string)($def['verbo'] ?? ''),
                    'cuenta' => null,
                ];
            }
        }

        $cerrada = $E->estado === MetaState::M_CERRADA;
        $dias    = (int)($prog['dias_rest'] ?? 0);

        return [
            'estado'   => (string)$res['estado'],
            'sin_meta' => $E->estado === MetaState::A_SIN_META,
            'cerrada'  => $cerrada,
            'titulo'   => self::titulo($E, false, $cuota, $snap),
            'turno'    => self::turno($E, $cuota),
            'accion'   => ['etiqueta' => (string)$accion['etiqueta'],
                           'destino'  => (string)$accion['destino']],
            //  El objeto, RESUMIDO: su nombre y ya. Home lo nombra, no lo abre.
            'objeto'   => ['titulo' => mb_strimwidth((string)($objeto['titulo'] ?? ''), 0, 130, '…')],
            'cifra'    => $cifra,
            'dias'     => ($dias > 0 && !$cerrada) ? $dias : null,
            'puede'    => $puede,
            'barra'    => ($puede && $meta && ($meta['cantidad'] ?? null) !== null)
                ? ['pct'   => max(2, min(100, (int)($prog['pct'] ?? 0))),
                   'ritmo' => ($prog['al_dia'] ?? null) === false ? 'mal'
                            : ((($prog['al_dia'] ?? null) === true) ? 'bien' : '')]
                : null,
        ];
    }
    /**
     * De quién es el turno, en una palabra.
     *
     * Las tres pantallas que lo enseñan tienen que decir lo mismo, así que la
     * decisión vive aquí y no en cada una.
     *
     * @return array{txt:string,cls:string}
     */
    public static function turno(MetaState $E, array $cuota = []): array
    {
        if (MetaLimiteImagen::manda($E, $cuota ?: null)) {
            return ['txt' => 'En pausa hasta el mes que viene', 'cls' => 'limite'];
        }
        $tipo = (string)($E->accion['tipo'] ?? '');
        $tuyas = ['material', 'aprobacion', 'inversion', 'fisica', 'decision',
                  'reintento', 'reintento_job'];
        if (in_array($tipo, $tuyas, true))  return ['txt' => 'Te toca a ti',  'cls' => 'tuyo'];
        if ($tipo === '')                    return ['txt' => 'Me toca a mí',  'cls' => 'mio'];
        return ['txt' => 'Ya está listo', 'cls' => 'mio'];
    }
}
