<?php

namespace App\Domain\Project\Enums;

enum ProjectExecutionMode: string
{
    case Autonomous = 'autonomous';
    case Watcher = 'watcher';
    case Yolo = 'yolo';

    public function label(): string
    {
        return match ($this) {
            self::Autonomous => 'Autonomous',
            self::Watcher => 'Watcher',
            self::Yolo => 'YOLO',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Autonomous => '🤖',
            self::Watcher => '👁',
            self::Yolo => '⚡',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Autonomous => 'bg-purple-100 text-purple-800',
            self::Watcher => 'bg-cyan-100 text-cyan-800',
            self::Yolo => 'bg-amber-100 text-amber-800',
        };
    }

    public function skipsTesting(): bool
    {
        return $this === self::Yolo;
    }
}
