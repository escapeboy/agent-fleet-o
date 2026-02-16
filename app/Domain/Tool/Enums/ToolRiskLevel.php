<?php

namespace App\Domain\Tool\Enums;

enum ToolRiskLevel: string
{
    case Safe = 'safe';
    case Read = 'read';
    case Write = 'write';
    case Destructive = 'destructive';

    public function label(): string
    {
        return match ($this) {
            self::Safe => 'Safe',
            self::Read => 'Read-only',
            self::Write => 'Write',
            self::Destructive => 'Destructive',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Safe => 'bg-green-100 text-green-800',
            self::Read => 'bg-blue-100 text-blue-800',
            self::Write => 'bg-yellow-100 text-yellow-800',
            self::Destructive => 'bg-red-100 text-red-800',
        };
    }

    public function isReadSafe(): bool
    {
        return in_array($this, [self::Safe, self::Read]);
    }
}
