<?php

class CognitiveRule
{
    public string $code;
    public string $description;
    public int $priority;
    private $callback;

    public function __construct(string $code, string $description, int $priority, callable $callback)
    {
        $this->code = $code;
        $this->description = $description;
        $this->priority = $priority;
        $this->callback = $callback;
    }

    public function evaluate(BusinessEvent $event, BusinessContext $context, GenomeSnapshot $genome): ?CognitiveDecision
    {
        $cb = $this->callback;
        return $cb($event, $context, $genome, $this);
    }
}
