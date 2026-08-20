<?php
// ============================================================
//  CRECER — UNA CORRIDA DEL CORILLO
//  core/Meta/MetaAutoRun.php
//
//  El valor que devuelve el libro de corridas. Solo lectura: quien quiera
//  cambiar algo pasa por MetaAutoRunner, que es donde vive la disciplina del
//  candado. Un objeto con setters aqui invitaria a escribir por la espalda.
// ============================================================

final class MetaAutoRun
{
    public function __construct(
        public readonly int     $id,
        public readonly int     $marca_id,
        public readonly int     $plan_id,
        public readonly string  $ronda,
        public readonly string  $estado,      // corriendo | hecho | fallado
        public readonly int     $intentos,
        public readonly string  $origen,      // cron | worker | manual
        public readonly ?string $latido_at,
        public readonly int     $creadas,
        public readonly string  $motivo,
        public readonly ?string $created_at
    ) {}

    public static function desdeFila(array $r): self
    {
        return new self(
            (int)($r['id'] ?? 0),
            (int)($r['marca_id'] ?? 0),
            (int)($r['plan_id'] ?? 0),
            (string)($r['ronda'] ?? ''),
            (string)($r['estado'] ?? 'corriendo'),
            (int)($r['intentos'] ?? 1),
            (string)($r['origen'] ?? 'cron'),
            isset($r['latido_at']) && $r['latido_at'] !== null ? (string)$r['latido_at'] : null,
            (int)($r['creadas'] ?? 0),
            (string)($r['motivo'] ?? ''),
            isset($r['created_at']) ? (string)$r['created_at'] : null
        );
    }

    /** ¿Se quedo sin intentos? Entonces esta ronda ya no se vuelve a probar. */
    public function agotada(): bool
    {
        return $this->estado === 'fallado'
            || $this->intentos >= MetaAutoRunner::INTENTOS_MAX;
    }

    /**
     * Lo que se le puede decir al dueño. NUNCA en rojo por defecto: quedarse
     * sin cuota o no tener plan que avanzar son finales normales, no averias.
     */
    public function enCristiano(): string
    {
        if ($this->estado === 'fallado')       return 'El corillo no pudo completar esta ronda.';
        if ($this->motivo === 'sin_cuota')     return 'El corillo llegó a tu tope de imágenes del mes.';
        if ($this->motivo === 'sin_plan')      return 'El corillo revisó y no había plan que avanzar.';
        if ($this->estado === 'corriendo')     return 'El corillo está trabajando ahora mismo.';
        if ($this->creadas > 0) {
            return $this->creadas === 1
                ? 'El corillo te dejó 1 pieza nueva.'
                : "El corillo te dejó {$this->creadas} piezas nuevas.";
        }
        return 'El corillo revisó y ya tenías suficiente por ahora.';
    }
}
