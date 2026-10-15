<?php

namespace Tests\Unit\Infrastructure\AI\Middleware;

use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Middleware\IdempotencyCheck;
use App\Infrastructure\AI\Models\LlmRequestLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Prism\Prism\Facades\Tool as PrismTool;
use Prism\Prism\Tool;
use Tests\TestCase;

/**
 * The derived idempotency key ignores tools. Replaying a stored answer for a
 * tool-using request returned the old final text with zero tool calls, so a
 * repeated task (e.g. bank transfers) reported "done" while nothing happened.
 */
class IdempotencyCheckToolRequestTest extends TestCase
{
    use RefreshDatabase;

    private function request(?array $tools, ?string $idempotencyKey = null): AiRequestDTO
    {
        return new AiRequestDTO(
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            systemPrompt: 'You are a payments agent.',
            userPrompt: '{"task":"pay 12 invoices"}',
            purpose: 'agent.execute_with_tools',
            idempotencyKey: $idempotencyKey,
            tools: $tools,
            maxSteps: 10,
        );
    }

    /**
     * @return array<Tool>
     */
    private function tools(): array
    {
        return [PrismTool::as('transfer')->for('Send money')->using(fn () => 'ok')];
    }

    /**
     * @return array{0: \Closure, 1: \ArrayObject<int, int>}
     */
    private function countingProvider(): array
    {
        $calls = new \ArrayObject([0]);

        $next = function (AiRequestDTO $request) use ($calls): AiResponseDTO {
            $calls[0]++;

            return new AiResponseDTO(
                content: 'All payments done.',
                parsedOutput: null,
                usage: new AiUsageDTO(promptTokens: 10, completionTokens: 5, costCredits: 3),
                provider: 'anthropic',
                model: 'claude-sonnet-4-5',
                latencyMs: 12,
                toolCallsCount: 12,
                stepsCount: 3,
            );
        };

        return [$next, $calls];
    }

    public function test_repeated_tool_request_reaches_the_provider_every_time(): void
    {
        $middleware = app(IdempotencyCheck::class);
        [$next, $calls] = $this->countingProvider();

        $first = $middleware->handle($this->request($this->tools()), $next);
        $second = $middleware->handle($this->request($this->tools()), $next);

        $this->assertSame(2, $calls[0]);
        $this->assertFalse($first->cached);
        $this->assertFalse($second->cached);
        $this->assertSame(12, $second->toolCallsCount);
        // Each call still gets its own ledger row for cost/telemetry readers.
        $this->assertSame(2, LlmRequestLog::withoutGlobalScopes()->where('status', 'completed')->count());
    }

    public function test_repeated_tool_less_request_is_still_served_from_the_ledger(): void
    {
        $middleware = app(IdempotencyCheck::class);
        [$next, $calls] = $this->countingProvider();

        $middleware->handle($this->request(null), $next);
        $second = $middleware->handle($this->request(null), $next);

        $this->assertSame(1, $calls[0]);
        $this->assertTrue($second->cached);
    }

    public function test_caller_supplied_key_keeps_dedup_for_tool_requests(): void
    {
        $middleware = app(IdempotencyCheck::class);
        [$next, $calls] = $this->countingProvider();

        $middleware->handle($this->request($this->tools(), 'retry-of-op-42'), $next);
        $second = $middleware->handle($this->request($this->tools(), 'retry-of-op-42'), $next);

        $this->assertSame(1, $calls[0]);
        $this->assertTrue($second->cached);
    }
}
