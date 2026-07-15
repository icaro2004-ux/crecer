<?php

class LearningSignal
{
    public string $signalType;
    public string $subject;
    public $value;
    public array $evidence;
    public float $weight;
    public string $source;

    public function __construct(string $signalType, string $subject, $value, array $evidence = [], float $weight = 0.4, string $source = 'kernel')
    {
        $this->signalType = $signalType;
        $this->subject = $subject;
        $this->value = $value;
        $this->evidence = $evidence;
        $this->weight = $weight;
        $this->source = $source;
    }

    public function toArray(): array
    {
        return [
            'signal_type' => $this->signalType,
            'subject' => $this->subject,
            'value' => $this->value,
            'evidence' => $this->evidence,
            'weight' => $this->weight,
            'source' => $this->source,
        ];
    }
}
