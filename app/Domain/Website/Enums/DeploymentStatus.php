<?php

namespace App\Domain\Website\Enums;

enum DeploymentStatus: string
{
    case Queued = 'queued';
    case Building = 'building';
    case Deployed = 'deployed';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Deployed, self::Failed => true,
            default => false,
        };
    }
}
