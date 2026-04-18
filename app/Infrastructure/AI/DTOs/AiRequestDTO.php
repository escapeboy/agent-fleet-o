<?php

namespace App\Infrastructure\AI\DTOs;

use App\Infrastructure\AI\Enums\BudgetPressureLevel;
use App\Infrastructure\AI\Enums\ReasoningEffort;
use App\Infrastructure\AI\Enums\RequestComplexity;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Tool;

final readonly class AiRequestDTO
{
    public function __construct(
        public string $provider,
        public string $model,
        public string $systemPrompt,
        public string $userPrompt,
        public int $maxTokens = 4096,
        public ?ObjectSchema $outputSchema = null,
        public ?string $userId = null,
        public ?string $teamId = null,
        public ?string $experimentId = null,
        public ?string $experimentStageId = null,
        public ?string $agentId = null,
        public ?string $purpose = null,
        public ?string $idempotencyKey = null,
        public float $temperature = 0.7,
        public ?array $fallbackChain = null,
        /** @var array<Tool>|null */
        public ?array $tools = null,
        public int $maxSteps = 1,
        public ?string $toolChoice = null,
        /** Custom endpoint credential name (only when provider='custom_endpoint') */
        public ?string $providerName = null,
        /** Anthropic extended thinking budget in tokens (null = disabled, only used when provider='anthropic') */
        public ?int $thinkingBudget = null,
        /** High-level reasoning effort hint — Auto resolves at runtime via BudgetPressureRouting, others map to fixed token budgets */
        public ?ReasoningEffort $effort = null,
        /** Per-call working directory override for local agent execution (e.g. a specific repo path) */
        public ?string $workingDirectory = null,
        /** Enable Anthropic prompt caching — marks system prompt and tool definitions with cache_control: ephemeral */
        public bool $enablePromptCaching = false,
        /** Explicit complexity hint — overrides heuristic classification when set */
        public ?RequestComplexity $complexity = null,
        /** Computed complexity after classification (set by BudgetPressureRouting middleware) */
        public ?RequestComplexity $classifiedComplexity = null,
        /** Budget pressure level at time of request (set by BudgetPressureRouting middleware) */
        public ?BudgetPressureLevel $budgetPressureLevel = null,
        /** Number of model-tier escalation attempts made (set by FallbackAiGateway) */
        public int $escalationAttempts = 0,
    ) {}

    public function isStructured(): bool
    {
        return $this->outputSchema !== null;
    }

    public function hasTools(): bool
    {
        return ! empty($this->tools);
    }

    public function generateIdempotencyKey(): string
    {
        return $this->idempotencyKey ?? hash('xxh128', implode('|', [
            $this->provider,
            $this->model,
            $this->systemPrompt,
            $this->userPrompt,
            $this->experimentId ?? '',
            $this->experimentStageId ?? '',
            $this->purpose ?? '',
            (string) ($this->thinkingBudget ?? ''),
            $this->effort?->value ?? '',
        ]));
    }
}
