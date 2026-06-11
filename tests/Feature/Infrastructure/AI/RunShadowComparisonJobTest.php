<?php

namespace Tests\Feature\Infrastructure\AI;

use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Jobs\RunShadowComparisonJob;
use App\Infrastructure\AI\Models\ShadowComparison;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class RunShadowComparisonJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeJob(): RunShadowComparisonJob
    {
        return new RunShadowComparisonJob(
            systemPrompt: 'sys',
            userPrompt: 'hello',
            maxTokens: 1024,
            temperature: 0.7,
            teamId: null,
            purpose: 'unit-test',
            primaryProvider: 'anthropic',
            primaryModel: 'claude-sonnet-4-5',
            primaryLatencyMs: 120,
            primaryCostCredits: 10,
            primaryContent: 'shared output',
            shadowProvider: 'openai',
            shadowModel: 'gpt-4o',
            storeSnippets: false,
            snippetChars: 2000,
        );
    }

    private function fakeGateway(string $content, ?int $cost = 4, ?int $latency = 90): AiGatewayInterface
    {
        return new class($content, $cost, $latency) implements AiGatewayInterface
        {
            public function __construct(private string $content, private int $cost, private int $latency) {}

            public function complete(AiRequestDTO $request): AiResponseDTO
            {
                return new AiResponseDTO(
                    content: $this->content,
                    parsedOutput: null,
                    usage: new AiUsageDTO(promptTokens: 10, completionTokens: 5, costCredits: $this->cost),
                    provider: $request->provider,
                    model: $request->model,
                    latencyMs: $this->latency,
                );
            }

            public function stream(AiRequestDTO $request, ?callable $onChunk = null): AiResponseDTO
            {
                return $this->complete($request);
            }

            public function estimateCost(AiRequestDTO $request): int
            {
                return 0;
            }
        };
    }

    public function test_records_matching_outputs(): void
    {
        $this->app->instance(AiGatewayInterface::class, $this->fakeGateway('shared output'));

        $this->makeJob()->handle($this->app->make(AiGatewayInterface::class));

        $row = ShadowComparison::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('completed', $row->shadow_status);
        $this->assertSame('openai', $row->shadow_provider);
        $this->assertTrue($row->outputs_match);
        $this->assertSame(4, $row->shadow_cost_credits);
        $this->assertSame(90, $row->shadow_latency_ms);
        $this->assertSame(10, $row->primary_cost_credits);
    }

    public function test_records_divergent_outputs(): void
    {
        $this->app->instance(AiGatewayInterface::class, $this->fakeGateway('different output'));

        $this->makeJob()->handle($this->app->make(AiGatewayInterface::class));

        $row = ShadowComparison::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('completed', $row->shadow_status);
        $this->assertFalse($row->outputs_match);
    }

    public function test_records_failed_shadow_leg(): void
    {
        $throwing = new class implements AiGatewayInterface
        {
            public function complete(AiRequestDTO $request): AiResponseDTO
            {
                throw new RuntimeException('shadow provider exploded');
            }

            public function stream(AiRequestDTO $request, ?callable $onChunk = null): AiResponseDTO
            {
                throw new RuntimeException('n/a');
            }

            public function estimateCost(AiRequestDTO $request): int
            {
                return 0;
            }
        };

        $this->makeJob()->handle($throwing);

        $row = ShadowComparison::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('failed', $row->shadow_status);
        $this->assertStringContainsString('exploded', (string) $row->shadow_error);
        $this->assertNull($row->outputs_match);
    }
}
