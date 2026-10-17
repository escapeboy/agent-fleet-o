<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Domain\Budget\Services\CostCalculator;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Gateways\PrismAiGateway;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Prism\Prism\Facades\Tool;
use Tests\TestCase;

/**
 * Fable 5.1, Mythos 5.1, Opus 5.5 and Sonnet 5.5 answer HTTP 400 to tool_choice
 * "any" or "tool". The gateway must send "auto" for those, on both request paths
 * (complete() and stream() with tools), and leave every other model alone.
 */
class PrismAiGatewayToolChoiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['prism.providers.anthropic.api_key' => 'test-key']);

        Http::fake(['*' => Http::response([
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn', 'stop_sequence' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 2],
        ])]);
    }

    /** @return array<string, mixed> */
    private function send(string $path, string $model, string $toolChoice, string $provider = 'anthropic'): array
    {
        $tool = Tool::as('ping')->for('Ping')->using(fn (): string => 'pong');

        $request = new AiRequestDTO(
            provider: $provider,
            model: $model,
            systemPrompt: 'sys',
            userPrompt: 'user',
            maxTokens: 1024,
            tools: [$tool],
            toolChoice: $toolChoice,
        );

        $gateway = new PrismAiGateway(new CostCalculator);
        $path === 'complete' ? $gateway->complete($request) : $gateway->stream($request);

        $body = null;
        Http::assertSent(function (Request $sent) use (&$body): bool {
            $body = json_decode($sent->body(), true);

            return true;
        });

        return $body['tool_choice'] ?? [];
    }

    /** @return array<string, array{string, string}> */
    public static function restrictedModels(): array
    {
        $out = [];
        foreach (['claude-fable-5-1', 'claude-mythos-5-1', 'claude-opus-5-5', 'claude-sonnet-5-5', 'claude-sonnet-5-5-20261001'] as $model) {
            foreach (['complete', 'stream'] as $path) {
                $out["$path $model"] = [$path, $model];
            }
        }

        return $out;
    }

    #[DataProvider('restrictedModels')]
    public function test_any_is_downgraded_to_auto(string $path, string $model): void
    {
        $this->assertSame('auto', $this->send($path, $model, 'any')['type'] ?? null);
    }

    #[DataProvider('restrictedModels')]
    public function test_specific_tool_name_is_downgraded_to_auto(string $path, string $model): void
    {
        $choice = $this->send($path, $model, 'ping');

        $this->assertSame('auto', $choice['type'] ?? null);
        $this->assertArrayNotHasKey('name', $choice);
    }

    #[DataProvider('restrictedModels')]
    public function test_auto_and_none_are_unchanged(string $path, string $model): void
    {
        $this->assertSame('auto', $this->send($path, $model, 'auto')['type'] ?? null);

        Http::fake(['*' => Http::response([
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => $model,
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn', 'stop_sequence' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 2],
        ])]);
        $this->assertSame('none', $this->send($path, $model, 'none')['type'] ?? null);
    }

    /** @return array<string, array{string, string}> */
    public static function unrestrictedModels(): array
    {
        $out = [];
        foreach (['claude-opus-5', 'claude-sonnet-5', 'claude-haiku-5-5', 'claude-sonnet-4-6'] as $model) {
            foreach (['complete', 'stream'] as $path) {
                $out["$path $model"] = [$path, $model];
            }
        }

        return $out;
    }

    #[DataProvider('unrestrictedModels')]
    public function test_other_models_keep_forced_tool_choice(string $path, string $model): void
    {
        $this->assertSame('any', $this->send($path, $model, 'any')['type'] ?? null);
    }

    #[DataProvider('unrestrictedModels')]
    public function test_other_models_keep_a_specific_tool_name(string $path, string $model): void
    {
        $this->assertSame(['type' => 'tool', 'name' => 'ping'], $this->send($path, $model, 'ping'));
    }

    /** @return array<string, array{string, string, string}> */
    public static function openRouterModels(): array
    {
        $out = [];
        foreach (['anthropic/claude-sonnet-5.5' => 'auto', 'anthropic/claude-opus-5.5' => 'auto', 'anthropic/claude-fable-5.1' => 'auto', 'anthropic/claude-sonnet-5-5' => 'auto',
            'anthropic/claude-opus-5' => 'keep', 'anthropic/claude-haiku-5.5' => 'keep', 'openai/gpt-4.1' => 'keep'] as $model => $expected) {
            foreach (['complete', 'stream'] as $path) {
                $out["$path $model"] = [$path, $model, $expected];
            }
        }

        return $out;
    }

    #[DataProvider('openRouterModels')]
    public function test_openrouter_downgrades_only_the_restricted_anthropic_models(string $path, string $model, string $expected): void
    {
        config(['prism.providers.openrouter.api_key' => 'sk-or-test']);
        Http::swap(new Factory);
        Http::fake(['*' => Http::response([
            'id' => 'gen-1', 'object' => 'chat.completion', 'created' => 1, 'model' => $model,
            'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'ok']]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12],
        ])]);

        $tool = Tool::as('ping')->for('Ping')->using(fn (): string => 'pong');
        $request = new AiRequestDTO(provider: 'openrouter', model: $model, systemPrompt: 's', userPrompt: 'u', maxTokens: 256, tools: [$tool], toolChoice: $expected === 'auto' ? 'any' : 'ping');
        $gateway = new PrismAiGateway(new CostCalculator);
        $path === 'complete' ? $gateway->complete($request) : $gateway->stream($request);

        $body = null;
        Http::assertSent(function (Request $sent) use (&$body): bool {
            $body = json_decode($sent->body(), true);

            return true;
        });

        // Prism's OpenRouter map has no 'required': 'any' would throw, so unrestricted models are probed with a tool name.
        $expected === 'auto'
            ? $this->assertSame('auto', $body['tool_choice'] ?? null)
            : $this->assertSame(['type' => 'function', 'function' => ['name' => 'ping']], $body['tool_choice'] ?? null);
    }
}
