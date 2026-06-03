<?php

namespace App\Domain\Agent\Enums;

/**
 * How a reviewer-lockout's `resource` is matched against a tool call's target
 * (a file path, a command, or the tool name).
 */
enum ToolLockoutMatchMode: string
{
    case Equals = 'equals';
    case Contains = 'contains';
    case Prefix = 'prefix';

    public function matches(string $candidate, string $resource): bool
    {
        return match ($this) {
            self::Equals => $candidate === $resource,
            self::Contains => $resource !== '' && str_contains($candidate, $resource),
            self::Prefix => $resource !== '' && str_starts_with($candidate, $resource),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Equals => 'Exact match',
            self::Contains => 'Contains',
            self::Prefix => 'Starts with',
        };
    }
}
