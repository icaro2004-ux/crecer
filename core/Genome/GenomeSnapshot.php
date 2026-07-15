<?php

class GenomeSnapshot
{
    private array $sections;

    public function __construct(array $sections)
    {
        $this->sections = $sections;
    }

    public function section(string $key): array
    {
        return $this->sections[$key] ?? [
            'value' => 'unknown',
            'confidence' => 0.0,
            'sources' => [],
            'last_updated' => null,
        ];
    }

    public function toArray(): array
    {
        return $this->sections;
    }
}
