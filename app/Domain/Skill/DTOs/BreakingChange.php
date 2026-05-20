<?php

namespace App\Domain\Skill\DTOs;

readonly class BreakingChange
{
    public function __construct(
        public string $kind,
        public string $field,
        public string $message,
        public ?string $oldValue = null,
        public ?string $newValue = null,
    ) {}

    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'field' => $this->field,
            'message' => $this->message,
            'old_value' => $this->oldValue,
            'new_value' => $this->newValue,
        ];
    }
}
