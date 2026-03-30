<?php

namespace App\Domain\Skill\Enums;

enum IterationOutcome: string
{
    case Keep = 'keep';
    case Discard = 'discard';
    case Crash = 'crash';
    case Timeout = 'timeout';

    public function label(): string
    {
        return match ($this) {
            self::Keep => 'Keep',
            self::Discard => 'Discard',
            self::Crash => 'Crash',
            self::Timeout => 'Timeout',
        };
    }

    public function isSuccess(): bool
    {
        return $this === self::Keep;
    }
}
