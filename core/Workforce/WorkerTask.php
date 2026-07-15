<?php

class WorkerTask
{
    public string $type;
    public int $businessId;
    public array $payload;

    public function __construct(string $type, int $businessId, array $payload = [])
    {
        $this->type = $type;
        $this->businessId = $businessId;
        $this->payload = $payload;
    }
}
