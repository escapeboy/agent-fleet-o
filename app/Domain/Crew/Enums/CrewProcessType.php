<?php

namespace App\Domain\Crew\Enums;

enum CrewProcessType: string
{
    case Sequential = 'sequential';
    case Parallel = 'parallel';
    case Hierarchical = 'hierarchical';

    public function label(): string
    {
        return match ($this) {
            self::Sequential => 'Sequential',
            self::Parallel => 'Parallel',
            self::Hierarchical => 'Hierarchical',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Sequential => 'Tasks execute one after another, each receiving the previous output',
            self::Parallel => 'Independent tasks execute concurrently, results gathered at the end',
            self::Hierarchical => 'Coordinator dynamically decides what to do next at each iteration',
        };
    }
}
