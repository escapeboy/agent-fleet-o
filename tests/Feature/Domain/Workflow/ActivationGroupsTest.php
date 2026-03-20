<?php

namespace Tests\Feature\Domain\Workflow;

use App\Domain\Workflow\Enums\ActivationMode;
use App\Domain\Workflow\Services\WorkflowGraphExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivationGroupsTest extends TestCase
{
    use RefreshDatabase;

    public function test_activation_mode_enum_values(): void
    {
        $this->assertEquals('all', ActivationMode::All->value);
        $this->assertEquals('any', ActivationMode::Any->value);
        $this->assertEquals('n_of_m', ActivationMode::NOfM->value);
    }

    public function test_filter_ready_nodes_all_mode_requires_all_predecessors(): void
    {
        $executor = app(WorkflowGraphExecutor::class);
        $method = new \ReflectionMethod($executor, 'filterReadyNodes');

        $edges = [
            ['source_node_id' => 'a', 'target_node_id' => 'merge'],
            ['source_node_id' => 'b', 'target_node_id' => 'merge'],
            ['source_node_id' => 'c', 'target_node_id' => 'merge'],
        ];

        $nodeMap = [
            'merge' => ['type' => 'merge', 'activation_mode' => 'all'],
        ];

        // Only one predecessor is complete
        $steps = collect([
            'a' => $this->makeStep('completed'),
            'b' => $this->makeStep('pending'),
            'c' => $this->makeStep('pending'),
        ]);

        $result = $method->invoke($executor, ['merge'], $edges, $steps, $nodeMap);
        $this->assertEmpty($result, 'All mode should not fire until all predecessors complete');

        // Complete all predecessors
        $steps['b']->status = 'completed';
        $steps['c']->status = 'completed';

        $result = $method->invoke($executor, ['merge'], $edges, $steps, $nodeMap);
        $this->assertContains('merge', $result, 'All mode should fire when all predecessors complete');
    }

    public function test_filter_ready_nodes_any_mode_fires_on_first_completion(): void
    {
        $executor = app(WorkflowGraphExecutor::class);
        $method = new \ReflectionMethod($executor, 'filterReadyNodes');

        $edges = [
            ['source_node_id' => 'a', 'target_node_id' => 'merge'],
            ['source_node_id' => 'b', 'target_node_id' => 'merge'],
            ['source_node_id' => 'c', 'target_node_id' => 'merge'],
        ];

        $nodeMap = [
            'merge' => ['type' => 'merge', 'activation_mode' => 'any'],
        ];

        // Only one predecessor complete
        $steps = collect([
            'a' => $this->makeStep('completed'),
            'b' => $this->makeStep('pending'),
            'c' => $this->makeStep('pending'),
        ]);

        $result = $method->invoke($executor, ['merge'], $edges, $steps, $nodeMap);
        $this->assertContains('merge', $result, 'Any mode should fire when at least one predecessor completes');
    }

    public function test_filter_ready_nodes_n_of_m_respects_threshold(): void
    {
        $executor = app(WorkflowGraphExecutor::class);
        $method = new \ReflectionMethod($executor, 'filterReadyNodes');

        $edges = [
            ['source_node_id' => 'a', 'target_node_id' => 'merge'],
            ['source_node_id' => 'b', 'target_node_id' => 'merge'],
            ['source_node_id' => 'c', 'target_node_id' => 'merge'],
        ];

        $nodeMap = [
            'merge' => ['type' => 'merge', 'activation_mode' => 'n_of_m', 'activation_threshold' => 2],
        ];

        // Only one predecessor complete — not enough for threshold of 2
        $steps = collect([
            'a' => $this->makeStep('completed'),
            'b' => $this->makeStep('pending'),
            'c' => $this->makeStep('pending'),
        ]);

        $result = $method->invoke($executor, ['merge'], $edges, $steps, $nodeMap);
        $this->assertEmpty($result, 'n_of_m with threshold=2 should not fire with only 1 complete');

        // Complete second predecessor
        $steps['b']->status = 'completed';

        $result = $method->invoke($executor, ['merge'], $edges, $steps, $nodeMap);
        $this->assertContains('merge', $result, 'n_of_m with threshold=2 should fire when 2 predecessors complete');
    }

    public function test_merge_node_defaults_to_any_for_backward_compatibility(): void
    {
        $executor = app(WorkflowGraphExecutor::class);
        $method = new \ReflectionMethod($executor, 'filterReadyNodes');

        $edges = [
            ['source_node_id' => 'a', 'target_node_id' => 'merge'],
            ['source_node_id' => 'b', 'target_node_id' => 'merge'],
        ];

        // No activation_mode set — merge type should default to 'any'
        $nodeMap = [
            'merge' => ['type' => 'merge'],
        ];

        $steps = collect([
            'a' => $this->makeStep('completed'),
            'b' => $this->makeStep('pending'),
        ]);

        $result = $method->invoke($executor, ['merge'], $edges, $steps, $nodeMap);
        $this->assertContains('merge', $result, 'Merge node without explicit activation_mode should default to any');
    }

    public function test_non_merge_node_defaults_to_all(): void
    {
        $executor = app(WorkflowGraphExecutor::class);
        $method = new \ReflectionMethod($executor, 'filterReadyNodes');

        $edges = [
            ['source_node_id' => 'a', 'target_node_id' => 'target'],
            ['source_node_id' => 'b', 'target_node_id' => 'target'],
        ];

        // Non-merge node without activation_mode should default to 'all'
        $nodeMap = [
            'target' => ['type' => 'agent'],
        ];

        $steps = collect([
            'a' => $this->makeStep('completed'),
            'b' => $this->makeStep('pending'),
        ]);

        $result = $method->invoke($executor, ['target'], $edges, $steps, $nodeMap);
        $this->assertEmpty($result, 'Non-merge node should default to all mode (require all predecessors)');
    }

    /**
     * Create a simple step-like object with isCompleted/isSkipped/isPending methods.
     */
    private function makeStep(string $status): object
    {
        return new class($status)
        {
            public function __construct(public string $status) {}

            public function isCompleted(): bool
            {
                return $this->status === 'completed';
            }

            public function isSkipped(): bool
            {
                return $this->status === 'skipped';
            }

            public function isPending(): bool
            {
                return $this->status === 'pending';
            }
        };
    }
}
