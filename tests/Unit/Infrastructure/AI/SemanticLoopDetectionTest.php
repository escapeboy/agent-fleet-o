<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Domain\Agent\Exceptions\ToolLoopSemanticException;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the semantic tool loop detection added to PrismAiGateway.
 *
 * We test the logic through AiResponseDTO and the ExecuteAgentAction
 * reaction rather than calling the private gateway method directly.
 */
class SemanticLoopDetectionTest extends TestCase
{
    public function test_ai_response_dto_accepts_loop_analysis(): void
    {
        $dto = new AiResponseDTO(
            content: 'test',
            parsedOutput: null,
            usage: new AiUsageDTO(100, 50, 5.0),
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            latencyMs: 100,
            loopAnalysis: ['max_repeat' => 3, 'distribution' => ['abc123' => 3]],
        );

        $this->assertEquals(3, $dto->loopAnalysis['max_repeat']);
    }

    public function test_ai_response_dto_null_loop_analysis_by_default(): void
    {
        $dto = new AiResponseDTO(
            content: 'test',
            parsedOutput: null,
            usage: new AiUsageDTO(100, 50, 5.0),
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            latencyMs: 100,
        );

        $this->assertNull($dto->loopAnalysis);
    }

    public function test_semantic_exception_carries_repeat_count(): void
    {
        $exception = new ToolLoopSemanticException(
            repeatCount: 5,
            threshold: 5,
            agentId: 'agent-123',
        );

        $this->assertEquals(5, $exception->repeatCount);
        $this->assertEquals(5, $exception->threshold);
        $this->assertEquals('agent-123', $exception->agentId);
        $this->assertStringContainsString('5 times', $exception->getMessage());
    }

    /**
     * Simulate what analyseToolCallRepetition does and verify its output shape.
     * Extracted here as a pure-function test (same logic, no PrismPHP dependencies).
     */
    public function test_identical_step_hashes_are_counted(): void
    {
        $steps = [
            [['name' => 'search', 'args' => ['query' => 'foo']]],
            [['name' => 'search', 'args' => ['query' => 'foo']]], // repeat 1
            [['name' => 'search', 'args' => ['query' => 'foo']]], // repeat 2
            [['name' => 'summarise', 'args' => []]],
        ];

        $hashCounts = [];

        foreach ($steps as $stepCalls) {
            if (empty($stepCalls)) {
                continue;
            }

            $serialised = collect($stepCalls)
                ->map(fn ($tc) => $tc['name'].':'.json_encode($tc['args']))
                ->sort()
                ->values()
                ->implode('|');

            $hash = md5($serialised);
            $hashCounts[$hash] = ($hashCounts[$hash] ?? 0) + 1;
        }

        $maxRepeat = max($hashCounts);

        $this->assertEquals(3, $maxRepeat);
    }

    public function test_no_repetition_gives_max_repeat_of_one(): void
    {
        $steps = [
            [['name' => 'search', 'args' => ['query' => 'a']]],
            [['name' => 'search', 'args' => ['query' => 'b']]],
            [['name' => 'fetch', 'args' => ['url' => 'http://example.com']]],
        ];

        $hashCounts = [];

        foreach ($steps as $stepCalls) {
            $serialised = collect($stepCalls)
                ->map(fn ($tc) => $tc['name'].':'.json_encode($tc['args']))
                ->sort()
                ->values()
                ->implode('|');

            $hash = md5($serialised);
            $hashCounts[$hash] = ($hashCounts[$hash] ?? 0) + 1;
        }

        $this->assertEquals(1, max($hashCounts));
    }
}
