<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Enums\BudgetPressureLevel;
use App\Infrastructure\AI\Enums\ReasoningEffort;
use App\Infrastructure\AI\Enums\RequestComplexity;
use ReflectionClass;
use Tests\TestCase;

/**
 * Drift guard: withModel() must clone every constructor field except `model`.
 * If a new field is added to AiRequestDTO and not copied in withModel(), this
 * test fails — preventing silent loss of request state (e.g. the per-request
 * BYOK override or trace context) during model translation.
 */
class AiRequestDtoWithModelTest extends TestCase
{
    public function test_with_model_changes_only_the_model_and_preserves_all_other_fields(): void
    {
        $original = new AiRequestDTO(
            provider: 'openrouter',
            model: 'claude-sonnet-4-5',
            systemPrompt: 'sys',
            userPrompt: 'user',
            maxTokens: 1234,
            outputSchema: null,
            userId: 'user-1',
            teamId: 'team-1',
            experimentId: 'exp-1',
            experimentStageId: 'stage-1',
            agentId: 'agent-1',
            purpose: 'unit-test',
            idempotencyKey: 'idem-1',
            temperature: 0.42,
            fallbackChain: ['a', 'b'],
            tools: null,
            maxSteps: 7,
            toolChoice: 'auto',
            providerName: 'proxy-1',
            thinkingBudget: 999,
            effort: ReasoningEffort::High,
            workingDirectory: '/tmp/repo',
            enablePromptCaching: true,
            complexity: RequestComplexity::Heavy,
            classifiedComplexity: RequestComplexity::Light,
            budgetPressureLevel: BudgetPressureLevel::High,
            escalationAttempts: 3,
            fastMode: true,
            providerCredentialOverride: 'sk-secret',
            gatewaySort: 'cost',
            parentTraceId: str_repeat('a', 32),
            parentSpanId: str_repeat('b', 16),
            maxCostCredits: 555,
        );

        $clone = $original->withModel('anthropic/claude-sonnet-4.5');

        $this->assertSame('anthropic/claude-sonnet-4.5', $clone->model);

        foreach ((new ReflectionClass(AiRequestDTO::class))->getConstructor()->getParameters() as $param) {
            $name = $param->getName();
            if ($name === 'model') {
                continue;
            }

            $this->assertSame(
                $original->{$name},
                $clone->{$name},
                "withModel() dropped or altered field '{$name}' — add it to AiRequestDTO::withModel().",
            );
        }
    }
}
