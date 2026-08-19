<?php
// ============================================================
//  CRECER — EL ESTADO DE LA META (valor)
//  core/Meta/MetaState.php
//
//  Un valor inmutable: qué está pasando con la meta, qué necesita Crecer del
//  dueño (si algo) y con qué evidencia se decidió. No sabe pintar ni consultar.
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
    public const FALLBACK          = 'Z';   // nunca debería salir; si sale, se ve

    public string $estado;
    public string $titulo;
    public string $instruccion;
    /** @var array{etiqueta:string,destino:string,consecuencia:string,tipo:string}|null */
    public ?array $accion;
    public array  $evidencia;
    /** @var array{hecho:int,ahora:?string,despues:int} */
    public array  $camino;
    public string $cobertura;   // completa | parcial | sin_senal
    public string $razon;

    public function __construct(string $estado, string $titulo, string $instruccion,
                                ?array $accion, array $evidencia, array $camino,
                                string $cobertura, string $razon)
    {
        $this->estado      = $estado;
        $this->titulo      = $titulo;
        $this->instruccion = $instruccion;
        $this->accion      = $accion;
        $this->evidencia   = $evidencia;
        $this->camino      = $camino;
        $this->cobertura   = $cobertura;
        $this->razon       = $razon;
    }

    /** ¿Hay algo que solo el dueño puede hacer? */
    public function pideAlgoAlDueno(): bool
    {
        return $this->accion !== null
            && in_array($this->accion['tipo'], ['material', 'aprobacion', 'inversion', 'fisica'], true);
    }

    /**
     * Con cobertura incompleta no se puede afirmar ritmo ni porcentaje: el
     * número que tenemos no cubre todo lo que el dueño llamaría un resultado.
     * Esta es la salvaguarda del criterio 8 del contrato, y vive en el dominio
     * para que la pantalla no pueda saltársela.
     */
    public function puedeAfirmarProgreso(): bool
    {
        return $this->cobertura === 'completa';
    }

    public function toArray(): array
    {
        return [
            'estado'      => $this->estado,
            'titulo'      => $this->titulo,
            'instruccion' => $this->instruccion,
            'accion'      => $this->accion,
            'evidencia'   => $this->evidencia,
            'camino'      => $this->camino,
            'cobertura'   => $this->cobertura,
            'razon'       => $this->razon,
        ];
    }

    /** Lo único que ve Home. El Kernel lo mezcla con sus reglas ajenas a la meta. */
    public function resumen(): array
    {
        return [
            'estado' => $this->estado,
            'titulo' => $this->titulo,
            'accion' => $this->accion,
            'razon'  => $this->razon,
        ];
    }
}
