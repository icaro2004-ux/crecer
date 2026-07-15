<?php

class CognitiveDecision
{
    public string $type;
    public int $priority;
    public string $reasonCode;
    public string $explanation;
    public ?string $requiredWorker;
    public bool $requiresReasoning;
    public bool $requiresUserApproval;
    public array $data;

    public function __construct(
        string $type,
        int $priority,
        string $reasonCode,
        string $explanation,
        ?string $requiredWorker = null,
        bool $requiresReasoning = false,
        bool $requiresUserApproval = false,
        array $data = []
    ) {
        $this->type = $type;
        $this->priority = $priority;
        $this->reasonCode = $reasonCode;
        $this->explanation = $explanation;
        $this->requiredWorker = $requiredWorker;
        $this->requiresReasoning = $requiresReasoning;
        $this->requiresUserApproval = $requiresUserApproval;
        $this->data = $data;
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'priority' => $this->priority,
            'reason_code' => $this->reasonCode,
            'explanation' => $this->explanation,
            'required_worker' => $this->requiredWorker,
            'requires_reasoning' => $this->requiresReasoning,
            'requires_user_approval' => $this->requiresUserApproval,
            'data' => $this->data,
        ];
    }
}
