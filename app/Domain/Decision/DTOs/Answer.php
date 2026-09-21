<?php

namespace App\Domain\Decision\DTOs;

interface Answer
{
    public function type(): string;

    public function value(): string|float;

    /**
     * @return array<string, float>|null
     */
    public function probabilities(): ?array;

    public function confidence(): ?float;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
