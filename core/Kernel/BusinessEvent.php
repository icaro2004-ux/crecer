<?php

class BusinessEvent
{
    public string $type;
    public int $businessId;
    public array $payload;
    public DateTimeImmutable $occurredAt;

    public function __construct(string $type, int $businessId, array $payload = [], ?DateTimeImmutable $occurredAt = null)
    {
        $this->type = trim($type);
        $this->businessId = $businessId;
        $this->payload = $payload;
        $this->occurredAt = $occurredAt ?: new DateTimeImmutable('now');
    }

    public function validate(): void
    {
        if ($this->type === '') {
            throw new InvalidArgumentException('BusinessEvent.type is required.');
        }
        if ($this->businessId <= 0) {
            throw new InvalidArgumentException('BusinessEvent.businessId must be positive.');
        }
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'business_id' => $this->businessId,
            'payload' => $this->payload,
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
        ];
    }
}
