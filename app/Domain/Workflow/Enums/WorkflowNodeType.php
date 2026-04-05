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
    case Llm = 'llm';
    case HttpRequest = 'http_request';
    case ParameterExtractor = 'parameter_extractor';
    case VariableAggregator = 'variable_aggregator';
    case TemplateTransform = 'template_transform';
    case KnowledgeRetrieval = 'knowledge_retrieval';
    case Annotation = 'annotation';
    case Iteration = 'iteration';
    case WorkflowRef = 'workflow_ref';

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
            self::Llm => 'LLM',
            self::HttpRequest => 'HTTP Request',
            self::ParameterExtractor => 'Parameter Extractor',
            self::VariableAggregator => 'Variable Aggregator',
            self::TemplateTransform => 'Template',
            self::KnowledgeRetrieval => 'Knowledge Retrieval',
            self::Annotation => 'Annotation',
            self::Iteration => 'Iteration',
            self::WorkflowRef => 'Workflow Reference',
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
            self::Llm => 'chat-bubble-left-right',
            self::HttpRequest => 'globe-alt',
            self::ParameterExtractor => 'tag',
            self::VariableAggregator => 'squares-plus',
            self::TemplateTransform => 'document-text',
            self::KnowledgeRetrieval => 'magnifying-glass',
            self::Annotation => 'pencil-square',
            self::Iteration => 'arrows-pointing-in',
            self::WorkflowRef => 'arrow-top-right-on-square',
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
            self::Llm => [
                'inputs' => [['name' => 'context', 'type' => 'text']],
                'outputs' => [['name' => 'text', 'type' => 'text']],
            ],
            self::HttpRequest => [
                'inputs' => [['name' => 'body', 'type' => 'text|structured'], ['name' => 'url_params', 'type' => 'structured']],
                'outputs' => [['name' => 'response_body', 'type' => 'text'], ['name' => 'status_code', 'type' => 'integer']],
            ],
            self::ParameterExtractor => [
                'inputs' => [['name' => 'context', 'type' => 'text']],
                'outputs' => [['name' => 'extracted', 'type' => 'structured']],
            ],
            self::VariableAggregator => [
                'inputs' => [['name' => 'data', 'type' => 'any']],
                'outputs' => [['name' => 'aggregated_results', 'type' => 'structured']],
            ],
            self::TemplateTransform => [
                'inputs' => [['name' => 'variables', 'type' => 'structured']],
                'outputs' => [['name' => 'rendered', 'type' => 'text']],
            ],
            self::KnowledgeRetrieval => [
                'inputs' => [['name' => 'query', 'type' => 'text']],
                'outputs' => [['name' => 'chunks', 'type' => 'structured']],
            ],
            self::Annotation => ['inputs' => [], 'outputs' => []],
            self::Iteration => [
                'inputs' => [['name' => 'items', 'type' => 'array']],
                'outputs' => [['name' => 'results', 'type' => 'array']],
            ],
            self::WorkflowRef => [
                'inputs' => [['name' => 'input', 'type' => 'structured']],
                'outputs' => [['name' => 'output', 'type' => 'structured']],
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
            self::Annotation,
            self::Iteration,
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
            self::Llm,
            self::HttpRequest,
            self::ParameterExtractor,
            self::VariableAggregator,
            self::TemplateTransform,
            self::KnowledgeRetrieval,
            self::WorkflowRef,
        ]);
    }
}
