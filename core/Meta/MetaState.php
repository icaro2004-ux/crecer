<?php
// ============================================================
//  CRECER — EL ESTADO DE LA META (valor)
//  core/Meta/MetaState.php
//
//  INMUTABLE DE VERDAD, y sin depender de `readonly`: el repositorio solo usa
//  rasgos de PHP 8.0 (match, str_contains) y no hay confirmación de que
//  producción corra 8.1+, que es donde `readonly` existe. Así que la garantía
//  se implementa a mano: propiedades privadas, lectura por __get y ESCRITURA
//  QUE LANZA. Funciona igual en 8.0 y en 8.3, y no es una promesa escrita en
//  un comentario: tests/test_meta_state_inmutable.php la ejerce.
//
//  Se lee igual que antes ($estado->titulo): quien lo consuma no nota nada.
//
//  `razon` es la parte que hace esto auditable: cada estado dice POR QUÉ ganó,
//  y las pruebas se afirman contra esa razón, no contra el texto visible.
// ============================================================

class MetaState
{
    /** Los trece del contrato + el de última instancia. */
    public const A_SIN_META        = 'A';
    public const B_PREPARANDO_PLAN = 'B';   // no observable hoy — ver MetaStateComposer
    public const C_PLAN_POR_VER    = 'C';   // requiere presentado_at (fase posterior)
    public const D_ERROR           = 'D';
    public const E_CRECER_TRABAJA  = 'E';
    public const F_APROBACION      = 'F';
    public const G_MATERIAL        = 'G';
    public const H_INVERSION       = 'H';
    public const I_ACCION_FISICA   = 'I';
    public const J_PROGRAMADO      = 'J';
    public const K_MIDIENDO        = 'K';
    public const L_APRENDIZAJE     = 'L';
    public const M_CERRADA         = 'M';
    public const N_PLAN_COMPLETADO = 'N';   // el plan termino; la meta sigue viva
    public const FALLBACK          = 'Z';   // nunca debería salir; si sale, se ve

    /** @var array<string,mixed> Todo el valor vive aquí, fuera del alcance de nadie. */
    private array $v;

    public function __construct(string $estado, string $titulo, string $instruccion,
                                ?array $accion, array $evidencia, array $camino,
                                string $cobertura, string $razon)
    {
        $this->v = [
            'estado'      => $estado,
            'titulo'      => $titulo,
            'instruccion' => $instruccion,
            'accion'      => $accion,
            'evidencia'   => $evidencia,
            'camino'      => $camino,
            'cobertura'   => $cobertura,
            'razon'       => $razon,
        ];
    }

    public function __get(string $campo)
    {
        if (!array_key_exists($campo, $this->v)) {
            throw new InvalidArgumentException("MetaState no tiene el campo «{$campo}».");
        }
        // Los arreglos salen por copia (PHP los pasa por valor): quien reciba
        // `evidencia` puede trastearla sin tocar el estado.
        return $this->v[$campo];
    }

    public function __isset(string $campo): bool
    {
        return isset($this->v[$campo]);
    }

    /** Escribir en un estado ya compuesto es un error de programación, no un caso. */
    public function __set(string $campo, $valor): void
    {
        throw new LogicException(
            "MetaState es inmutable: no se puede escribir «{$campo}». " .
            'Si hace falta otro estado, se compone otro.'
        );
    }

    public function __unset(string $campo): void
    {
        throw new LogicException("MetaState es inmutable: no se puede borrar «{$campo}».");
    }

    /** ¿Hay algo que solo el dueño puede hacer? */
    public function pideAlgoAlDueno(): bool
    {
        $a = $this->v['accion'];
        return $a !== null
            && in_array($a['tipo'], ['material', 'aprobacion', 'inversion', 'fisica'], true);
    }

    /**
     * Con cobertura incompleta no se puede afirmar ritmo ni porcentaje: el
     * número que tenemos no cubre todo lo que el dueño llamaría un resultado.
     * Esta es la salvaguarda del criterio 8 del contrato, y vive en el dominio
     * para que la pantalla no pueda saltársela.
     */
    public function puedeAfirmarProgreso(): bool
    {
        return $this->v['cobertura'] === 'completa';
    }

    public function toArray(): array
    {
        return $this->v;
    }

    /** Lo único que ve Home. El Kernel lo mezcla con sus reglas ajenas a la meta. */
    public function resumen(): array
    {
        return [
            'estado' => $this->v['estado'],
            'titulo' => $this->v['titulo'],
            'accion' => $this->v['accion'],
            'razon'  => $this->v['razon'],
        ];
    }
}
