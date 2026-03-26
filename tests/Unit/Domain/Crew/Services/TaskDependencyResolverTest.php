<?php

namespace Tests\Unit\Domain\Crew\Services;

use App\Domain\Crew\Enums\CrewTaskStatus;
use App\Domain\Crew\Models\CrewTaskExecution;
use App\Domain\Crew\Services\TaskDependencyResolver;
use Illuminate\Support\Str;
use Tests\TestCase;

class TaskDependencyResolverTest extends TestCase
{
    private TaskDependencyResolver $resolver;

    /** @var array<int, string> */
    private array $uuids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new TaskDependencyResolver;
        // Pre-generate UUIDs for each sort_order slot used in tests
        for ($i = 0; $i < 5; $i++) {
            $this->uuids[$i] = Str::uuid()->toString();
        }
    }

    private function makeTask(int $sortOrder, CrewTaskStatus $status, array $dependsOnSortOrders = []): CrewTaskExecution
    {
        $task = new CrewTaskExecution;
        $task->id = $this->uuids[$sortOrder];
        $task->sort_order = $sortOrder;
        $task->status = $status;
        // Convert sort_order integers to UUID strings
        $task->depends_on = array_map(fn (int $i) => $this->uuids[$i], $dependsOnSortOrders);

        return $task;
    }

    public function test_resolve_ready_treats_skipped_dependencies_as_satisfied(): void
    {
        $tasks = collect([
            $this->makeTask(0, CrewTaskStatus::Skipped),
            $this->makeTask(1, CrewTaskStatus::Pending, [0]),
        ]);

        $ready = $this->resolver->resolveReady($tasks);

        $this->assertCount(1, $ready);
        $this->assertEquals(1, $ready->first()->sort_order);
    }

    public function test_resolve_ready_includes_validated_and_skipped_as_satisfied(): void
    {
        $tasks = collect([
            $this->makeTask(0, CrewTaskStatus::Validated),
            $this->makeTask(1, CrewTaskStatus::Skipped),
            $this->makeTask(2, CrewTaskStatus::Pending, [0, 1]),
        ]);

        $ready = $this->resolver->resolveReady($tasks);

        $this->assertCount(1, $ready);
        $this->assertEquals(2, $ready->first()->sort_order);
    }

    public function test_resolve_ready_blocks_when_dependency_still_running(): void
    {
        $tasks = collect([
            $this->makeTask(0, CrewTaskStatus::Running),
            $this->makeTask(1, CrewTaskStatus::Pending, [0]),
        ]);

        $ready = $this->resolver->resolveReady($tasks);

        $this->assertCount(0, $ready);
    }

    public function test_has_deadlock_returns_false_when_dependency_is_skipped(): void
    {
        $tasks = collect([
            $this->makeTask(0, CrewTaskStatus::Skipped),
            $this->makeTask(1, CrewTaskStatus::Pending, [0]),
        ]);

        $this->assertFalse($this->resolver->hasDeadlock($tasks));
    }

    public function test_has_deadlock_returns_true_when_dependency_is_qa_failed(): void
    {
        $tasks = collect([
            $this->makeTask(0, CrewTaskStatus::QaFailed),
            $this->makeTask(1, CrewTaskStatus::Pending, [0]),
        ]);

        $this->assertTrue($this->resolver->hasDeadlock($tasks));
    }

    public function test_has_deadlock_returns_true_when_dependency_is_failed(): void
    {
        $tasks = collect([
            $this->makeTask(0, CrewTaskStatus::Failed),
            $this->makeTask(1, CrewTaskStatus::Pending, [0]),
        ]);

        $this->assertTrue($this->resolver->hasDeadlock($tasks));
    }

    public function test_has_deadlock_returns_false_when_no_failed_dependencies(): void
    {
        $tasks = collect([
            $this->makeTask(0, CrewTaskStatus::Validated),
            $this->makeTask(1, CrewTaskStatus::Skipped),
            $this->makeTask(2, CrewTaskStatus::Pending, [0, 1]),
        ]);

        $this->assertFalse($this->resolver->hasDeadlock($tasks));
    }

    public function test_gather_dependency_outputs_skips_skipped_tasks_without_output(): void
    {
        $skippedTask = $this->makeTask(0, CrewTaskStatus::Skipped);
        $skippedTask->output = null;
        $skippedTask->title = 'Skipped Task';

        $task = $this->makeTask(1, CrewTaskStatus::Pending, [0]);

        $outputs = $this->resolver->gatherDependencyOutputs($task, collect([$skippedTask, $task]));

        $this->assertEmpty($outputs);
    }

    public function test_gather_dependency_outputs_includes_validated_task_output(): void
    {
        $validatedTask = $this->makeTask(0, CrewTaskStatus::Validated);
        $validatedTask->output = ['result' => 'data'];
        $validatedTask->title = 'Research';

        $task = $this->makeTask(1, CrewTaskStatus::Pending, [0]);

        $outputs = $this->resolver->gatherDependencyOutputs($task, collect([$validatedTask, $task]));

        $this->assertEquals(['Research' => ['result' => 'data']], $outputs);
    }
}
