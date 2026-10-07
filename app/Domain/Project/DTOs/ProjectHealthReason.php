<?php

namespace App\Domain\Project\DTOs;

use App\Domain\Project\Enums\ProjectHealthState;

final readonly class ProjectHealthReason
{
    public function __construct(
        public string $code,
        public ProjectHealthState $severity,
        public string $message,
        public ?string $nextStep = null,
    ) {}

    /**
     * @return array{code: string, severity: string, message: string, next_step: string|null}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity->value,
            'message' => $this->message,
            'next_step' => $this->nextStep,
        ];
    }
}
