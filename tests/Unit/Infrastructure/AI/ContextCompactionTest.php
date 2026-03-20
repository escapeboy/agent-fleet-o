<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Middleware\ContextCompaction;
use App\Infrastructure\AI\Services\ContextCompactor;
use App\Infrastructure\AI\Services\TokenEstimator;
use Tests\TestCase;

class ContextCompactionTest extends TestCase
{
    private TokenEstimator $estimator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->estimator = new TokenEstimator;
    }

    private function makeRequest(
        string $systemPrompt = 'You are an assistant.',
        string $userPrompt = 'Hello',
        string $provider = 'anthropic',
        string $model = 'claude-sonnet-4-5',
    ): AiRequestDTO {
        return new AiRequestDTO(
            provider: $provider,
            model: $model,
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            maxTokens: 4096,
            userId: 'user-1',
            teamId: 'team-1',
            purpose: 'test',
        );
    }

    private function makeResponse(): AiResponseDTO
    {
        return new AiResponseDTO(
            content: 'test response',
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 100, completionTokens: 50, costCredits: 10),
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            latencyMs: 100,
        );
    }

    public function test_green_zone_passes_through_unchanged(): void
    {
        $compactor = $this->createMock(ContextCompactor::class);
        $compactor->expects($this->never())->method('compact');

        $middleware = new ContextCompaction($this->estimator, $compactor);
        $request = $this->makeRequest();
        $expectedResponse = $this->makeResponse();

        $response = $middleware->handle($request, fn ($r) => $expectedResponse);

        $this->assertSame($expectedResponse, $response);
    }

    public function test_disabled_config_passes_through(): void
    {
        config(['context_compaction.enabled' => false]);

        $compactor = $this->createMock(ContextCompactor::class);
        $compactor->expects($this->never())->method('compact');

        $middleware = new ContextCompaction($this->estimator, $compactor);

        // Even a huge prompt should pass through when disabled
        $request = $this->makeRequest(userPrompt: str_repeat('x', 800_000));

        $response = $middleware->handle($request, fn ($r) => $this->makeResponse());
        $this->assertNotNull($response);
    }

    public function test_local_provider_skipped(): void
    {
        $compactor = $this->createMock(ContextCompactor::class);
        $compactor->expects($this->never())->method('compact');

        $middleware = new ContextCompaction($this->estimator, $compactor);
        $request = $this->makeRequest(provider: 'local/claude-code');

        $response = $middleware->handle($request, fn ($r) => $this->makeResponse());
        $this->assertNotNull($response);
    }

    public function test_bridge_provider_skipped(): void
    {
        $compactor = $this->createMock(ContextCompactor::class);
        $compactor->expects($this->never())->method('compact');

        $middleware = new ContextCompaction($this->estimator, $compactor);
        $request = $this->makeRequest(provider: 'bridge_agent');

        $response = $middleware->handle($request, fn ($r) => $this->makeResponse());
        $this->assertNotNull($response);
    }

    public function test_compaction_triggers_above_threshold(): void
    {
        config([
            'context_compaction.enabled' => true,
            'context_compaction.summarize_threshold' => 0.70,
            'context_compaction.target_utilization' => 0.55,
        ]);

        // Create prompt that exceeds 70% of 200K context (>140K tokens = >560K chars)
        $largeUserPrompt = str_repeat('x', 600_000);

        $compactor = $this->createMock(ContextCompactor::class);
        $compactor->expects($this->once())
            ->method('compact')
            ->willReturn(['compacted prompt', ContextCompactor::STAGE_TOOL_OUTPUT]);

        $middleware = new ContextCompaction($this->estimator, $compactor);
        $request = $this->makeRequest(userPrompt: $largeUserPrompt);

        $passedRequest = null;
        $middleware->handle($request, function (AiRequestDTO $r) use (&$passedRequest) {
            $passedRequest = $r;

            return $this->makeResponse();
        });

        $this->assertNotNull($passedRequest);
        $this->assertSame('compacted prompt', $passedRequest->userPrompt);
        // System prompt should be unchanged
        $this->assertSame('You are an assistant.', $passedRequest->systemPrompt);
    }

    public function test_compaction_preserves_all_request_fields(): void
    {
        config([
            'context_compaction.enabled' => true,
            'context_compaction.summarize_threshold' => 0.70,
        ]);

        $largeUserPrompt = str_repeat('x', 600_000);

        $compactor = $this->createMock(ContextCompactor::class);
        $compactor->method('compact')
            ->willReturn(['compacted', ContextCompactor::STAGE_TOOL_OUTPUT]);

        $middleware = new ContextCompaction($this->estimator, $compactor);

        $original = new AiRequestDTO(
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            systemPrompt: 'system',
            userPrompt: $largeUserPrompt,
            maxTokens: 8192,
            userId: 'u-1',
            teamId: 't-1',
            experimentId: 'exp-1',
            purpose: 'testing',
            temperature: 0.5,
            toolChoice: 'auto',
        );

        $passedRequest = null;
        $middleware->handle($original, function (AiRequestDTO $r) use (&$passedRequest) {
            $passedRequest = $r;

            return $this->makeResponse();
        });

        // Verify all fields are preserved except userPrompt
        $this->assertSame('compacted', $passedRequest->userPrompt);
        $this->assertSame('system', $passedRequest->systemPrompt);
        $this->assertSame('anthropic', $passedRequest->provider);
        $this->assertSame('claude-sonnet-4-5', $passedRequest->model);
        $this->assertSame(8192, $passedRequest->maxTokens);
        $this->assertSame('u-1', $passedRequest->userId);
        $this->assertSame('t-1', $passedRequest->teamId);
        $this->assertSame('exp-1', $passedRequest->experimentId);
        $this->assertSame('testing', $passedRequest->purpose);
        $this->assertSame(0.5, $passedRequest->temperature);
        $this->assertSame('auto', $passedRequest->toolChoice);
    }
}
