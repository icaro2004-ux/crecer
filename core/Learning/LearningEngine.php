<?php

class LearningEngine
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function capture(BusinessEvent $event, BusinessContext $context, array $workerResults = []): array
    {
        $signals = [];
        if ($event->type === 'content_approved') {
            $signals[] = new LearningSignal('content_approved', 'content_quality', 'approved_by_owner', $event->payload, 0.35, 'user_decision');
        }
        if ($event->type === 'content_rejected') {
            $signals[] = new LearningSignal('content_rejected', 'preference', $event->payload['reason'] ?? 'rejected', $event->payload, 0.55, 'user_decision');
            if (function_exists('memoria_escribir') && !empty($event->payload['reason'])) {
                memoria_escribir($this->pdo, $event->businessId, [
                    'tipo' => 'preferencia',
                    'titulo' => 'Contenido rechazado',
                    'detalle' => 'Evitar propuestas con este problema: ' . substr((string)$event->payload['reason'], 0, 120),
                    'porque' => 'Lo aprendi de un rechazo del dueno.',
                    'fuente' => 'rechazo',
                    'confianza' => 55,
                    'peso' => 65,
                ]);
            }
        }
        if ($event->type === 'autopilot_tick') {
            foreach ($workerResults as $wr) {
                if (!empty($wr['ok'])) {
                    $signals[] = new LearningSignal('autopilot_tick', 'operations', $wr['result']['razon'] ?? 'processed', $wr, 0.2, 'system_event');
                }
            }
        }
        return array_map(fn($s) => $s->toArray(), $signals);
    }
}
