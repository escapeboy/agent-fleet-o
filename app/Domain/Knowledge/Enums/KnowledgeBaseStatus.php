<?php

namespace App\Domain\Knowledge\Enums;

enum KnowledgeBaseStatus: string
{
    case Idle = 'idle';
    case Ingesting = 'ingesting';
    case Ready = 'ready';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Idle => 'Idle',
            self::Ingesting => 'Ingesting',
            self::Ready => 'Ready',
            self::Error => 'Error',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Idle => 'gray',
            self::Ingesting => 'blue',
            self::Ready => 'green',
            self::Error => 'red',
        };
    }
}
