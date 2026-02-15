<?php

namespace App\Domain\Workflow\Enums;

enum WorkflowNodeType: string
{
    case Start = 'start';
    case End = 'end';
    case Agent = 'agent';
    case Conditional = 'conditional';
    case Crew = 'crew';
    case HumanTask = 'human_task';
    case Switch = 'switch';
    case DynamicFork = 'dynamic_fork';
    case DoWhile = 'do_while';

    public function label(): string
    {
        return match ($this) {
            self::Start => 'Start',
            self::End => 'End',
            self::Agent => 'Agent',
            self::Conditional => 'Condition',
            self::Crew => 'Crew',
            self::HumanTask => 'Human Task',
            self::Switch => 'Switch',
            self::DynamicFork => 'Dynamic Fork',
            self::DoWhile => 'Do While',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Start => 'play-circle',
            self::End => 'stop-circle',
            self::Agent => 'cpu-chip',
            self::Conditional => 'arrows-right-left',
            self::Crew => 'users',
            self::HumanTask => 'hand-raised',
            self::Switch => 'arrows-pointing-out',
            self::DynamicFork => 'queue-list',
            self::DoWhile => 'arrow-path',
        };
    }

    public function requiresAgent(): bool
    {
        return $this === self::Agent;
    }

    public function requiresCrew(): bool
    {
        return $this === self::Crew;
    }

    /**
     * Whether this node type requires a form_schema in config.
     */
    public function requiresFormSchema(): bool
    {
        return $this === self::HumanTask;
    }

    /**
     * Whether this node type is a control-flow node (no agent execution).
     */
    public function isControlFlow(): bool
    {
        return in_array($this, [
            self::Start,
            self::End,
            self::Conditional,
            self::Switch,
            self::DynamicFork,
            self::DoWhile,
        ]);
    }

    /**
     * Whether this node type creates a PlaybookStep during materialization.
     */
    public function createsStep(): bool
    {
        return in_array($this, [
            self::Agent,
            self::Crew,
            self::HumanTask,
        ]);
    }
}
