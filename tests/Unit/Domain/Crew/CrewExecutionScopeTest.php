<?php

namespace Tests\Unit\Domain\Crew;

use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Crew\Services\CrewExecutionCancelledException;
use App\Domain\Crew\Services\CrewExecutionScope;
use Tests\TestCase;

class CrewExecutionScopeTest extends TestCase
{
    private function makeExecution(CrewExecutionStatus $status, string $id = 'test-id'): CrewExecution
    {
        $execution = \Mockery::mock(CrewExecution::class)->makePartial()->shouldIgnoreMissing();
        $execution->shouldReceive('refresh')->andReturnSelf();
        $execution->shouldReceive('getAttribute')->with('status')->andReturn($status);
        $execution->shouldReceive('getAttribute')->with('id')->andReturn($id);

        return $execution;
    }

    public function test_is_cancelled_returns_false_for_executing_status(): void
    {
        $scope = new CrewExecutionScope($this->makeExecution(CrewExecutionStatus::Executing));

        $this->assertFalse($scope->isCancelled());
    }

    public function test_is_cancelled_returns_false_for_planning_status(): void
    {
        $scope = new CrewExecutionScope($this->makeExecution(CrewExecutionStatus::Planning));

        $this->assertFalse($scope->isCancelled());
    }

    public function test_is_cancelled_returns_true_for_failed_status(): void
    {
        $scope = new CrewExecutionScope($this->makeExecution(CrewExecutionStatus::Failed));

        $this->assertTrue($scope->isCancelled());
    }

    public function test_is_cancelled_returns_true_for_terminated_status(): void
    {
        $scope = new CrewExecutionScope($this->makeExecution(CrewExecutionStatus::Terminated));

        $this->assertTrue($scope->isCancelled());
    }

    public function test_assert_not_cancelled_throws_on_failed_execution(): void
    {
        $scope = new CrewExecutionScope($this->makeExecution(CrewExecutionStatus::Failed));

        $this->expectException(CrewExecutionCancelledException::class);
        $scope->assertNotCancelled();
    }

    public function test_assert_not_cancelled_passes_for_active_execution(): void
    {
        $scope = new CrewExecutionScope($this->makeExecution(CrewExecutionStatus::Executing));

        $scope->assertNotCancelled();
        $this->assertTrue(true);
    }

    public function test_on_dispose_callbacks_run_in_reverse_order(): void
    {
        $scope = new CrewExecutionScope($this->makeExecution(CrewExecutionStatus::Executing));

        $order = [];
        $scope->onDispose(function () use (&$order) {
            $order[] = 1;
        });
        $scope->onDispose(function () use (&$order) {
            $order[] = 2;
        });
        $scope->onDispose(function () use (&$order) {
            $order[] = 3;
        });

        $scope->dispose();

        $this->assertSame([3, 2, 1], $order);
    }

    public function test_dispose_runs_all_callbacks_even_if_one_throws(): void
    {
        $scope = new CrewExecutionScope($this->makeExecution(CrewExecutionStatus::Executing));

        $ran = [];
        $scope->onDispose(function () use (&$ran) {
            $ran[] = 'a';
        });
        $scope->onDispose(function () {
            throw new \RuntimeException('cleanup error');
        });
        $scope->onDispose(function () use (&$ran) {
            $ran[] = 'b';
        });

        $scope->dispose();

        $this->assertContains('a', $ran);
        $this->assertContains('b', $ran);
    }

    public function test_dispose_clears_callbacks_so_second_call_is_noop(): void
    {
        $scope = new CrewExecutionScope($this->makeExecution(CrewExecutionStatus::Executing));

        $count = 0;
        $scope->onDispose(function () use (&$count) {
            $count++;
        });

        $scope->dispose();
        $scope->dispose();

        $this->assertSame(1, $count);
    }
}
