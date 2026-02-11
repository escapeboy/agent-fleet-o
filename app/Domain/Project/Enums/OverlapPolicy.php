<?php

namespace App\Domain\Project\Enums;

enum OverlapPolicy: string
{
    case Skip = 'skip';
    case Queue = 'queue';
    case Allow = 'allow';

    public function label(): string
    {
        return match ($this) {
            self::Skip => 'Skip if running',
            self::Queue => 'Queue until done',
            self::Allow => 'Allow concurrent',
        };
    }
}
