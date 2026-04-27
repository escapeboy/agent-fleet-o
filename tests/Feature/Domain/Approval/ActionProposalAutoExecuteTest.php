<?php

namespace Tests\Feature\Domain\Approval;

use App\Domain\Approval\Actions\ApproveActionProposalAction;
use App\Domain\Approval\Actions\CreateActionProposalAction;
use App\Domain\Approval\Enums\ActionProposalStatus;
use App\Domain\Approval\Events\ActionProposalApproved;
use App\Domain\Approval\Jobs\ExecuteActionProposalJob;
use App\Domain\Approval\Services\ActionProposalExecutor;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ActionProposalAutoExecuteTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'T '.bin2hex(random_bytes(3)),
            'slug' => 't-'.bin2hex(random_bytes(3)),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
    }

    public function test_approve_emits_event_and_dispatches_execution_job(): void
    {
        Event::fake([ActionProposalApproved::class]);
        Queue::fake();

        $proposal = $this->makeProposal();

        app(ApproveActionProposalAction::class)->execute($proposal, $this->user);

        Event::assertDispatched(ActionProposalApproved::class, fn ($e) => $e->proposal->id === $proposal->id);
    }

    public function test_listener_dispatches_execute_job(): void
    {
        Queue::fake();

        $proposal = $this->makeProposal();

        // Don't fake the event — let the real listener run.
        app(ApproveActionProposalAction::class)->execute($proposal, $this->user);

        Queue::assertPushed(ExecuteActionProposalJob::class, fn ($job) => $job->proposalId === $proposal->id);
    }

    public function test_job_marks_proposal_executed_when_executor_succeeds(): void
    {
        $proposal = $this->makeProposal();
        $proposal->update(['status' => ActionProposalStatus::Approved]);

        $executor = $this->mock(ActionProposalExecutor::class, function ($mock) {
            $mock->shouldReceive('execute')->once()->andReturn(['ok' => true, 'value' => 42]);
        });

        $job = new ExecuteActionProposalJob($proposal->id);
        $job->handle($executor);

        $proposal->refresh();
        $this->assertSame(ActionProposalStatus::Executed, $proposal->status);
        $this->assertNotNull($proposal->executed_at);
        $this->assertSame(['ok' => true, 'value' => 42], $proposal->execution_result);
        $this->assertNull($proposal->execution_error);
    }

    public function test_job_marks_proposal_execution_failed_on_executor_throw(): void
    {
        $proposal = $this->makeProposal();
        $proposal->update(['status' => ActionProposalStatus::Approved]);

        $executor = $this->mock(ActionProposalExecutor::class, function ($mock) {
            $mock->shouldReceive('execute')->once()->andThrow(new \RuntimeException('boom'));
        });

        $job = new ExecuteActionProposalJob($proposal->id);
        $job->handle($executor);

        $proposal->refresh();
        $this->assertSame(ActionProposalStatus::ExecutionFailed, $proposal->status);
        $this->assertSame('boom', $proposal->execution_error);
    }

    public function test_job_skips_when_proposal_not_in_approved_state(): void
    {
        $proposal = $this->makeProposal();
        // Stays Pending.

        $executor = $this->mock(ActionProposalExecutor::class, function ($mock) {
            $mock->shouldNotReceive('execute');
        });

        $job = new ExecuteActionProposalJob($proposal->id);
        $job->handle($executor);

        $proposal->refresh();
        $this->assertSame(ActionProposalStatus::Pending, $proposal->status);
        $this->assertNull($proposal->executed_at);
    }

    public function test_job_skips_when_already_executed(): void
    {
        $proposal = $this->makeProposal();
        $proposal->update([
            'status' => ActionProposalStatus::Executed,
            'executed_at' => now()->subMinute(),
            'execution_result' => ['done' => true],
        ]);

        $executor = $this->mock(ActionProposalExecutor::class, function ($mock) {
            $mock->shouldNotReceive('execute');
        });

        $job = new ExecuteActionProposalJob($proposal->id);
        $job->handle($executor);

        $this->assertSame(ActionProposalStatus::Executed, $proposal->fresh()->status);
    }

    public function test_executor_throws_unsupported_target_type(): void
    {
        $proposal = $this->makeProposal();
        $proposal->update(['target_type' => 'integration_action']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unsupported target_type');

        app(ActionProposalExecutor::class)->execute($proposal->fresh(), $this->user);
    }

    public function test_executor_validates_payload_shape(): void
    {
        $proposal = $this->makeProposal();
        $proposal->update(['payload' => ['tool' => 'something']]); // missing positional_args

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('positional_args');

        app(ActionProposalExecutor::class)->execute($proposal->fresh(), $this->user);
    }

    private function makeProposal(): \App\Domain\Approval\Models\ActionProposal
    {
        return app(CreateActionProposalAction::class)->execute(
            teamId: $this->team->id,
            targetType: 'tool_call',
            targetId: null,
            summary: 'Test exec',
            payload: ['tool' => 'noop_test_tool', 'positional_args' => ['arg1']],
            userId: $this->user->id,
        );
    }
}
