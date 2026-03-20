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
    case TimeGate = 'time_gate';
    case Merge = 'merge';
    case SubWorkflow = 'sub_workflow';
    case BorunaStep = 'boruna_step';

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
            self::TimeGate => 'Time Gate',
            self::Merge => 'Merge',
            self::SubWorkflow => 'Sub-Workflow',
            self::BorunaStep => 'Boruna Script',
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
            self::TimeGate => 'clock',
            self::Merge => 'funnel',
            self::SubWorkflow => 'rectangle-stack',
            self::BorunaStep => 'shield-check',
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
    /**
     * Return the typed port schema (inputs and outputs) for this node type.
     *
     * @return array{inputs: array<array{name: string, type: string}>, outputs: array<array{name: string, type: string}>}
     */
    public function portSchema(): array
    {
        return match ($this) {
            self::Start => ['inputs' => [], 'outputs' => [['name' => 'trigger_data', 'type' => 'any']]],
            self::End => ['inputs' => [['name' => 'result', 'type' => 'any']], 'outputs' => []],
            self::Agent => [
                'inputs' => [['name' => 'context', 'type' => 'text|structured']],
                'outputs' => [['name' => 'result', 'type' => 'text'], ['name' => 'artifacts', 'type' => 'artifact[]']],
            ],
            self::Conditional => [
                'inputs' => [['name' => 'value', 'type' => 'any']],
                'outputs' => [['name' => 'pass', 'type' => 'passthrough']],
            ],
            self::Crew => [
                'inputs' => [['name' => 'context', 'type' => 'text|structured']],
                'outputs' => [['name' => 'result', 'type' => 'text'], ['name' => 'artifacts', 'type' => 'artifact[]']],
            ],
            self::HumanTask => [
                'inputs' => [['name' => 'context', 'type' => 'text|structured']],
                'outputs' => [['name' => 'response', 'type' => 'structured']],
            ],
            self::Switch => [
                'inputs' => [['name' => 'expression', 'type' => 'any']],
                'outputs' => [['name' => 'case', 'type' => 'passthrough']],
            ],
            self::DynamicFork => [
                'inputs' => [['name' => 'items', 'type' => 'array']],
                'outputs' => [['name' => 'item_result', 'type' => 'text']],
            ],
            self::DoWhile => [
                'inputs' => [['name' => 'iteration_data', 'type' => 'any']],
                'outputs' => [['name' => 'result', 'type' => 'passthrough']],
            ],
            self::TimeGate => [
                'inputs' => [['name' => 'data', 'type' => 'any']],
                'outputs' => [['name' => 'data', 'type' => 'passthrough']],
            ],
            self::Merge => [
                'inputs' => [['name' => 'data', 'type' => 'any']],
                'outputs' => [['name' => 'merged', 'type' => 'structured']],
            ],
            self::SubWorkflow => [
                'inputs' => [['name' => 'context', 'type' => 'text|structured']],
                'outputs' => [['name' => 'result', 'type' => 'text|structured']],
            ],
            self::BorunaStep => [
                'inputs' => [['name' => 'context', 'type' => 'text|structured']],
                'outputs' => [['name' => 'result', 'type' => 'structured']],
            ],
            default => [
                'inputs' => [['name' => 'data', 'type' => 'any']],
                'outputs' => [['name' => 'data', 'type' => 'any']],
            ],
        };
    }

    public function isControlFlow(): bool
    {
        return in_array($this, [
            self::Start,
            self::End,
            self::Conditional,
            self::Switch,
            self::DynamicFork,
            self::DoWhile,
            self::Merge,
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
            self::TimeGate,
            self::SubWorkflow,
            self::BorunaStep,
        ]);
    }
}
