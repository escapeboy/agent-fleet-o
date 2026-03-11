<?php

namespace App\Domain\Credential\Enums;

enum CredentialSource: string
{
    case Human = 'human';
    case Agent = 'agent';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Human => 'Human',
            self::Agent => 'Agent',
            self::System => 'System',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Human => 'bg-gray-100 text-gray-800',
            self::Agent => 'bg-blue-100 text-blue-800',
            self::System => 'bg-amber-100 text-amber-800',
        };
    }

    public function isAutomated(): bool
    {
        return $this !== self::Human;
    }
}
