<?php

class ReasoningRequest
{
    public string $task;
    public array $businessContext;
    public array $genomeSnapshot;
    public array $constraints;
    public array $availableEvidence;
    public array $expectedSchema;

    public function __construct(string $task, array $businessContext, array $genomeSnapshot, array $constraints = [], array $availableEvidence = [], array $expectedSchema = [])
    {
        $this->task = $task;
        $this->businessContext = $businessContext;
        $this->genomeSnapshot = $genomeSnapshot;
        $this->constraints = $constraints;
        $this->availableEvidence = $availableEvidence;
        $this->expectedSchema = $expectedSchema;
    }
}
