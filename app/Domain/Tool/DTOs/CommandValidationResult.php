<?php

namespace App\Domain\Tool\DTOs;

readonly class CommandValidationResult
{
    public function __construct(
        public bool $allowed,
        public string $reason,
        public string $level,
        public bool $requiresApproval = false,
    ) {}
}
