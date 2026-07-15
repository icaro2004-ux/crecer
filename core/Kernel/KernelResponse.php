<?php

class KernelResponse
{
    public BusinessEvent $event;
    public array $context;
    public array $genome;
    public array $decisions;
    public ?array $reasoning;
    public array $workerResults;
    public array $learningSignals;
    public array $briefing;
    public array $debugEvidence;
    public int $durationMs;
    public array $errors;

    public function __construct(
        BusinessEvent $event,
        array $context,
        array $genome,
        array $decisions,
        ?array $reasoning,
        array $workerResults,
        array $learningSignals,
        array $briefing,
        array $debugEvidence,
        int $durationMs,
        array $errors = []
    ) {
        $this->event = $event;
        $this->context = $context;
        $this->genome = $genome;
        $this->decisions = $decisions;
        $this->reasoning = $reasoning;
        $this->workerResults = $workerResults;
        $this->learningSignals = $learningSignals;
        $this->briefing = $briefing;
        $this->debugEvidence = $debugEvidence;
        $this->durationMs = $durationMs;
        $this->errors = $errors;
    }

    public function toArray(bool $includeDebug = false): array
    {
        $out = [
            'event' => $this->event->toArray(),
            'priority' => $this->briefing['priority'] ?? null,
            'prepared_work' => $this->briefing['prepared_work'] ?? [],
            'learning' => $this->briefing['recent_learning'] ?? [],
            'autopilot' => $this->briefing['autopilot_status'] ?? [],
            'results' => $this->briefing['results_summary'] ?? [],
            'next_action' => $this->briefing['next_action'] ?? null,
            'briefing' => $this->briefing,
            'decisions' => $this->decisions,
            'reasoning' => $this->reasoning,
            'worker_results' => $this->workerResults,
            'learning_signals' => $this->learningSignals,
            'duration_ms' => $this->durationMs,
            'errors' => $this->errors,
        ];
        if ($includeDebug) {
            $out['context'] = $this->context;
            $out['genome'] = $this->genome;
            $out['debug_evidence'] = $this->debugEvidence;
        }
        return $out;
    }
}
