<?php

class CrecerKernel
{
    private PDO $pdo;
    private ContextBuilder $contextBuilder;
    private BusinessGenome $genome;
    private CognitiveEngine $cognition;
    private ReasoningEngine $reasoning;
    private WorkforceRouter $workforce;
    private LearningEngine $learning;
    private MissionControlAdapter $adapter;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->contextBuilder = new ContextBuilder($pdo);
        $this->genome = new BusinessGenome();
        $this->cognition = new CognitiveEngine();
        $this->reasoning = new ReasoningEngine($pdo);
        $this->workforce = new WorkforceRouter($pdo);
        $this->learning = new LearningEngine($pdo);
        $this->adapter = new MissionControlAdapter();
    }

    public static function dispatch(BusinessEvent $event, PDO $pdo): KernelResponse
    {
        return (new self($pdo))->handle($event);
    }

    public function handle(BusinessEvent $event): KernelResponse
    {
        $started = microtime(true);
        $errors = [];
        $workerResults = [];
        $learningSignals = [];
        $reasoningOut = null;

        try {
            $event->validate();
            $context = $this->contextBuilder->build($event);
            $genome = $this->genome->build($context);
            $decisions = $this->cognition->decide($event, $context, $genome);

            foreach ($decisions as $decision) {
                if (!$this->shouldRunWorker($event, $decision)) continue;
                $task = new WorkerTask((string)$decision->requiredWorker, $event->businessId, $event->payload + $decision->data);
                $workerResults[] = $this->workforce->route($task)->toArray();
            }

            $needsReasoning = false;
            foreach ($decisions as $decision) {
                if ($decision->requiresReasoning) { $needsReasoning = true; break; }
            }
            // El reasoning con LLM NO corre en el hot-path del login (cada carga
            // del Home dispararia una llamada a Gemini: latencia + costo por
            // visita). Solo corre cuando una regla lo exige, en hitos unicos
            // (onboarding/activacion), o bajo demanda (payload run_reasoning).
            // En login el briefing usa la explicacion deterministica del adapter.
            if ($needsReasoning || in_array($event->type, ['onboarding_completed','plan_activated'], true) || !empty($event->payload['run_reasoning'])) {
                $reasoningRequest = new ReasoningRequest(
                    'mission_control_briefing',
                    $context->toArray(),
                    $genome->toArray(),
                    ['no_fake_metrics'=>true, 'use_existing_evidence_only'=>true],
                    [
                        'decisions' => array_map(fn($d) => $d->toArray(), $decisions),
                        'prepared_work' => array_slice($context->get('prepared_work', []), 0, 5),
                        'memory' => array_slice($context->get('memoria', []), 0, 3),
                    ],
                    ['recommendation','reason','facts','inferences','limitations']
                );
                $reasoningMarcaId = $context->get('marca', []) ? $event->businessId : null;
                $reasoningOut = $this->reasoning->reason($reasoningRequest, $reasoningMarcaId)->toArray();
            }

            $learningSignals = $this->learning->capture($event, $context, $workerResults);
            $briefing = $this->adapter->adapt($event, $context, $genome, $decisions, $reasoningOut, $workerResults, $learningSignals);

            $this->logTrace($event, [
                'rules_activated' => array_map(fn($d) => $d->reasonCode, $decisions),
                'reasoning' => $reasoningOut ? true : false,
                'workers' => array_map(fn($r) => $r['task'] ?? '', $workerResults),
                'learning_signals' => count($learningSignals),
                'duration_ms' => (int)round((microtime(true) - $started) * 1000),
            ]);

            return new KernelResponse(
                $event,
                $context->toArray(),
                $genome->toArray(),
                array_map(fn($d) => $d->toArray(), $decisions),
                $reasoningOut,
                $workerResults,
                $learningSignals,
                $briefing,
                $briefing['debug_evidence'] ?? [],
                (int)round((microtime(true) - $started) * 1000),
                $errors
            );
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
            error_log('CrecerKernel error: ' . $e->getMessage());
            return new KernelResponse(
                $event,
                [],
                [],
                [],
                null,
                $workerResults,
                $learningSignals,
                [
                    'greeting' => '',
                    'headline' => 'Kernel no pudo procesar el evento.',
                    'priority' => null,
                    'prepared_work' => [],
                    'recent_learning' => [],
                    'autopilot_status' => [],
                    'results_summary' => [],
                    'next_action' => null,
                    'explanation' => '',
                    'debug_evidence' => ['error'=>$e->getMessage()],
                ],
                ['error'=>$e->getMessage()],
                (int)round((microtime(true) - $started) * 1000),
                $errors
            );
        }
    }

    private function shouldRunWorker(BusinessEvent $event, CognitiveDecision $decision): bool
    {
        if (!$decision->requiredWorker) return false;
        if ($decision->requiresUserApproval && empty($event->payload['confirmed'])) return false;
        if ($event->type === 'autopilot_tick' && $decision->requiredWorker === 'autonomous_work') return true;
        return !empty($event->payload['run_worker']);
    }

    private function logTrace(BusinessEvent $event, array $trace): void
    {
        if (PHP_SAPI === 'cli') return;
        $line = json_encode(['kernel_event'=>$event->toArray(), 'trace'=>$trace], JSON_UNESCAPED_UNICODE);
        error_log('CRECER_KERNEL_V1 ' . $line);
    }
}
