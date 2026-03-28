<?php

namespace App\Domain\Workflow\DTOs;

use PHPUnit\Framework\Assert;

class SimulationResult
{
    /**
     * @param  array<int, string>  $executedPath  Ordered node IDs visited
     * @param  array<string, mixed>  $stepOutputs  Node ID => stub output
     * @param  string  $terminationNodeId  The end/terminal node ID
     * @param  string  $terminationStatus  'completed' | 'loop_limit' | 'error'
     */
    public function __construct(
        public readonly array $executedPath,
        public readonly array $stepOutputs,
        public readonly string $terminationNodeId,
        public readonly string $terminationStatus,
    ) {}

    public function assertReached(string $nodeId): void
    {
        Assert::assertContains(
            $nodeId,
            $this->executedPath,
            "Expected node '{$nodeId}' to be reached, but it was not. Executed path: ["
                .implode(', ', $this->executedPath).']',
        );
    }

    public function assertNotReached(string $nodeId): void
    {
        Assert::assertNotContains(
            $nodeId,
            $this->executedPath,
            "Expected node '{$nodeId}' NOT to be reached, but it was.",
        );
    }

    /** @param array<int, string> $nodeIds */
    public function assertExecutionOrder(array $nodeIds): void
    {
        $positions = [];
        foreach ($nodeIds as $nodeId) {
            $pos = array_search($nodeId, $this->executedPath, true);
            Assert::assertNotFalse(
                $pos,
                "Node '{$nodeId}' was not in the executed path.",
            );
            $positions[] = $pos;
        }

        $sorted = $positions;
        sort($sorted);
        Assert::assertSame(
            $sorted,
            $positions,
            'Nodes were not executed in the expected order. Executed path: ['
                .implode(', ', $this->executedPath).']',
        );
    }

    public function assertOutputEquals(string $nodeId, mixed $expected): void
    {
        Assert::assertArrayHasKey($nodeId, $this->stepOutputs, "Node '{$nodeId}' has no output recorded.");
        Assert::assertEquals($expected, $this->stepOutputs[$nodeId]);
    }

    public function assertCompleted(): void
    {
        Assert::assertSame('completed', $this->terminationStatus, "Expected termination status 'completed', got '{$this->terminationStatus}'.");
    }

    public function assertTerminatedWithStatus(string $status): void
    {
        Assert::assertSame($status, $this->terminationStatus, "Expected termination status '{$status}', got '{$this->terminationStatus}'.");
    }
}
