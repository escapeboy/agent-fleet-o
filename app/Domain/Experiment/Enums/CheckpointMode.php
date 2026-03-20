<?php

namespace App\Domain\Experiment\Enums;

enum CheckpointMode: string
{
    case Exit = 'exit';
    case Async = 'async';
    case Sync = 'sync';

    public function label(): string
    {
        return match ($this) {
            self::Exit => 'Fast (on exit only)',
            self::Async => 'Balanced (async writes)',
            self::Sync => 'Safe (synchronous)',
        };
    }
}
