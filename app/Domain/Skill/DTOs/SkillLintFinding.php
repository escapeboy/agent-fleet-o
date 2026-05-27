<?php

namespace App\Domain\Skill\DTOs;

use App\Domain\Skill\Enums\SkillLintMode;

final readonly class SkillLintFinding
{
    public function __construct(
        public SkillLintMode $mode,
        public string $severity, // 'warning' | 'info'
        public string $message,
        public ?string $detail = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode->value,
            'label' => $this->mode->label(),
            'severity' => $this->severity,
            'message' => $this->message,
            'detail' => $this->detail,
        ];
    }
}
