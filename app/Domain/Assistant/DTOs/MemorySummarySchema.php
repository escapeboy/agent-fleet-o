<?php

namespace App\Domain\Assistant\DTOs;

/**
 * Structured schema for conversation memory compression.
 *
 * Inspired by AgentScope's SummarySchema pattern — constrains each field
 * to a max length so the compressed snapshot stays predictably small.
 */
final readonly class MemorySummarySchema
{
    public function __construct(
        public string $taskOverview,
        public string $currentState,
        public array $keyDiscoveries,
        public array $nextSteps,
        public string $contextToPreserve,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            taskOverview: mb_substr($data['task_overview'] ?? '', 0, 500),
            currentState: mb_substr($data['current_state'] ?? '', 0, 300),
            keyDiscoveries: array_slice(array_map(
                fn ($d) => mb_substr((string) $d, 0, 200),
                $data['key_discoveries'] ?? [],
            ), 0, 10),
            nextSteps: array_slice(array_map(
                fn ($s) => mb_substr((string) $s, 0, 200),
                $data['next_steps'] ?? [],
            ), 0, 5),
            contextToPreserve: mb_substr($data['context_to_preserve'] ?? '', 0, 500),
        );
    }

    public function toArray(): array
    {
        return [
            'task_overview' => $this->taskOverview,
            'current_state' => $this->currentState,
            'key_discoveries' => $this->keyDiscoveries,
            'next_steps' => $this->nextSteps,
            'context_to_preserve' => $this->contextToPreserve,
        ];
    }

    public function toContextString(): string
    {
        $discoveries = implode("\n- ", $this->keyDiscoveries);
        $steps = implode("\n- ", $this->nextSteps);

        return <<<CONTEXT
        <memory_summary>
        <task_overview>{$this->taskOverview}</task_overview>
        <current_state>{$this->currentState}</current_state>
        <key_discoveries>
        - {$discoveries}
        </key_discoveries>
        <next_steps>
        - {$steps}
        </next_steps>
        <context_to_preserve>{$this->contextToPreserve}</context_to_preserve>
        </memory_summary>
        CONTEXT;
    }

    /**
     * JSON schema for LLM structured output.
     */
    public static function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['task_overview', 'current_state', 'key_discoveries', 'next_steps', 'context_to_preserve'],
            'properties' => [
                'task_overview' => [
                    'type' => 'string',
                    'description' => 'What the user is trying to accomplish (max 500 chars)',
                ],
                'current_state' => [
                    'type' => 'string',
                    'description' => 'Where things stand right now — what has been done, what is pending (max 300 chars)',
                ],
                'key_discoveries' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Important facts, decisions, or findings from the conversation (max 10 items, 200 chars each)',
                ],
                'next_steps' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Pending actions or open questions (max 5 items, 200 chars each)',
                ],
                'context_to_preserve' => [
                    'type' => 'string',
                    'description' => 'Entity names, IDs, config values, or other specifics that must survive compression (max 500 chars)',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function estimateTokens(): int
    {
        $text = $this->toContextString();

        return (int) ceil(mb_strlen($text) / 4);
    }
}
