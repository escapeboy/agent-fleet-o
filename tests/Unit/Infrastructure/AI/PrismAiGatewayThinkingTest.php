<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Domain\Budget\Services\CostCalculator;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Enums\ReasoningEffort;
use App\Infrastructure\AI\Gateways\PrismAiGateway;
use App\Support\LlmDefaults;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Manual thinking (`{type: enabled, budget_tokens}`) returns a 400 on Opus 4.7+,
 * Sonnet 5.x, Haiku 5.5, Fable and Mythos. Prism can only send that shape, so
 * the gateway must leave `thinking` off for those models. The `openai/gpt-4o`
 * fallback chain now lands on the platform Anthropic default (Sonnet 5.5).
 */
class PrismAiGatewayThinkingTest extends TestCase
{
    private function sentThinking(string $model, ?int $budget = null, ?ReasoningEffort $effort = null): mixed
    {
        config(['prism.providers.anthropic.api_key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => $model,
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);

        $request = new AiRequestDTO(
            provider: 'anthropic',
            model: $model,
            systemPrompt: 'sys',
            userPrompt: 'hi',
            maxTokens: 40_000,
            thinkingBudget: $budget,
            effort: $effort,
        );

        $method = (new \ReflectionClass(PrismAiGateway::class))->getMethod('executeRequest');
        try {
            $method->invoke(new PrismAiGateway(new CostCalculator), $request);
        } catch (\Throwable) {
            // Only the outgoing request body matters here.
        }

        $thinking = 'unsent';
        Http::assertSent(function (Request $sent) use (&$thinking): bool {
            $thinking = $sent->data()['thinking'] ?? null;

            return true;
        });

        return $thinking;
    }

    /** @return array<string, array{string}> */
    public static function modelsRejectingBudgetTokens(): array
    {
        return [
            'sonnet 5.5' => ['claude-sonnet-5-5'],
            'sonnet 5' => ['claude-sonnet-5'],
            'haiku 5.5' => ['claude-haiku-5-5'],
            'opus 5.5' => ['claude-opus-5-5'],
            'opus 4.8' => ['claude-opus-4-8'],
            'opus 4.7' => ['claude-opus-4-7'],
            'fable 5.1' => ['claude-fable-5-1'],
        ];
    }

    #[DataProvider('modelsRejectingBudgetTokens')]
    public function test_no_manual_thinking_for_models_that_reject_budget_tokens(string $model): void
    {
        $this->assertNull($this->sentThinking($model, budget: 8_000));
        $this->assertNull($this->sentThinking($model, effort: ReasoningEffort::High));
    }

    public function test_gpt4o_fallback_target_gets_no_manual_thinking(): void
    {
        $this->assertNull($this->sentThinking(LlmDefaults::platformModel('anthropic'), effort: ReasoningEffort::Medium));
    }

    /** @return array<string, array{string}> */
    public static function modelsAcceptingBudgetTokens(): array
    {
        return [
            'sonnet 4.6' => ['claude-sonnet-4-6'],
            'opus 4.6' => ['claude-opus-4-6'],
            'haiku 4.5' => ['claude-haiku-4-5-20251001'],
        ];
    }

    #[DataProvider('modelsAcceptingBudgetTokens')]
    public function test_older_models_keep_the_budget(string $model): void
    {
        $this->assertSame(['type' => 'enabled', 'budget_tokens' => 8_000], $this->sentThinking($model, budget: 8_000));
    }
}
