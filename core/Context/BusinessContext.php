<?php

class BusinessContext
{
    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function get(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    public function toArray(): array
    {
        return $this->data;
    }
}
