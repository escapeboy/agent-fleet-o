<?php

namespace App\Domain\Project\Enums;

enum ProjectExecutionMode: string
{
    case Autonomous = 'autonomous';
    case Watcher = 'watcher';

    public function label(): string
    {
        return match ($this) {
            self::Autonomous => 'Autonomous',
            self::Watcher => 'Watcher',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Autonomous => '🤖',
            self::Watcher => '👁',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Autonomous => 'bg-purple-100 text-purple-800',
            self::Watcher => 'bg-cyan-100 text-cyan-800',
        };
    }
}
