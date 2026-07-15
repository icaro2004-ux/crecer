<?php
// CRECER Kernel v1 bootstrap. No Composer/autoload required.
if (!defined('CRECER_KERNEL_V1_ENABLED')) {
    define('CRECER_KERNEL_V1_ENABLED', false);
}

require_once dirname(__DIR__) . '/includes/suscripcion.php';
require_once dirname(__DIR__) . '/includes/metricas.php';
require_once dirname(__DIR__) . '/includes/memoria.php';
require_once dirname(__DIR__) . '/includes/agentes.php';

$kernel_files = [
    __DIR__ . '/Kernel/BusinessEvent.php',
    __DIR__ . '/Kernel/KernelResponse.php',
    __DIR__ . '/Context/BusinessContext.php',
    __DIR__ . '/Genome/GenomeSnapshot.php',
    __DIR__ . '/Genome/BusinessGenome.php',
    __DIR__ . '/Cognition/CognitiveRule.php',
    __DIR__ . '/Cognition/CognitiveDecision.php',
    __DIR__ . '/Cognition/CognitiveEngine.php',
    __DIR__ . '/Reasoning/ReasoningRequest.php',
    __DIR__ . '/Reasoning/ReasoningResult.php',
    __DIR__ . '/Reasoning/ReasoningEngine.php',
    __DIR__ . '/Workforce/WorkerTask.php',
    __DIR__ . '/Workforce/WorkerResult.php',
    __DIR__ . '/Workforce/WorkforceRouter.php',
    __DIR__ . '/Learning/LearningSignal.php',
    __DIR__ . '/Learning/LearningEngine.php',
    __DIR__ . '/Context/ContextBuilder.php',
    __DIR__ . '/MissionControl/MissionControlAdapter.php',
    __DIR__ . '/Kernel/CrecerKernel.php',
];

foreach ($kernel_files as $file) {
    require_once $file;
}
