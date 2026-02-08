<?php

namespace App\Domain\Workflow\Enums;

enum WorkflowNodeType: string
{
    case Start = 'start';
    case End = 'end';
    case Agent = 'agent';
    case Conditional = 'conditional';

    public function label(): string
    {
        return match ($this) {
            self::Start => 'Start',
            self::End => 'End',
            self::Agent => 'Agent',
            self::Conditional => 'Condition',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Start => 'play-circle',
            self::End => 'stop-circle',
            self::Agent => 'cpu-chip',
            self::Conditional => 'arrows-right-left',
        };
    }

    public function requiresAgent(): bool
    {
        return $this === self::Agent;
    }
}
