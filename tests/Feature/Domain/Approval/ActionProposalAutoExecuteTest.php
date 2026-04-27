<?php

namespace Tests\Feature\Domain\Approval;

use App\Domain\Approval\Actions\ApproveActionProposalAction;
use App\Domain\Approval\Actions\CreateActionProposalAction;
use App\Domain\Approval\Enums\ActionProposalStatus;
use App\Domain\Approval\Events\ActionProposalApproved;
use App\Domain\Approval\Jobs\ExecuteActionProposalJob;
use App\Domain\Approval\Models\ActionProposal;
use App\Domain\Approval\Services\ActionProposalExecutor;
use App\Domain\GitRepository\Contracts\GitClientInterface;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\GitOperationRouter;
use App\Domain\Integration\Actions\ExecuteIntegrationActionAction;
use App\Domain\Integration\Models\Integration;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Assert;
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
        // Sprint 3d.2 added integration_action; Sprint 3d.3 added git_push.
        // 'outbound' remains unsupported in v1 (see deferred design doc).
        $proposal->update(['target_type' => 'outbound']);

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

    public function test_executor_runs_integration_action_with_gate_bypass(): void
    {
        $integration = Integration::factory()->create([
            'team_id' => $this->team->id,
            'driver' => 'github',
        ]);

        // Build a proposal as the gate would, with policy='ask'.
        $this->team->update(['settings' => ['action_proposal_policy' => ['low' => 'auto', 'medium' => 'auto', 'high' => 'ask']]]);

        $proposal = app(CreateActionProposalAction::class)->execute(
            teamId: $this->team->id,
            targetType: 'integration_action',
            targetId: $integration->id,
            summary: 'High-risk: github :: delete_branch',
            payload: [
                'integration_id' => $integration->id,
                'action' => 'delete_branch',
                'params' => ['branch' => 'feature/old'],
            ],
            userId: $this->user->id,
        );

        // Mock the underlying ExecuteIntegrationActionAction so we don't
        // hit the real GitHub driver. The point is to assert: (a) it gets
        // called, (b) the bypass flag is set during the call so the gate
        // doesn't recurse.
        $this->mock(ExecuteIntegrationActionAction::class, function ($mock) {
            $mock->shouldReceive('execute')
                ->once()
                ->andReturnUsing(function ($int, $action, $params) {
                    Assert::assertTrue(
                        app()->bound('integration_gate.bypass') && app('integration_gate.bypass'),
                        'Gate bypass must be active during executor invocation.',
                    );

                    return ['success' => true, 'deleted' => $params['branch'] ?? null];
                });
        });

        $result = app(ActionProposalExecutor::class)->execute($proposal->fresh(), $this->user);

        $this->assertTrue($result['success']);
        $this->assertSame('feature/old', $result['deleted']);
    }

    public function test_executor_rejects_integration_action_with_missing_payload(): void
    {
        $integration = Integration::factory()->create([
            'team_id' => $this->team->id,
        ]);

        $proposal = app(CreateActionProposalAction::class)->execute(
            teamId: $this->team->id,
            targetType: 'integration_action',
            targetId: $integration->id,
            summary: 'Bad',
            payload: ['integration_id' => $integration->id], // missing 'action'
            userId: $this->user->id,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('integration_id and action');

        app(ActionProposalExecutor::class)->execute($proposal->fresh(), $this->user);
    }

    public function test_executor_runs_git_push_with_gate_bypass(): void
    {
        $repo = GitRepository::create([
            'team_id' => $this->team->id,
            'name' => 'test-repo',
            'url' => 'https://github.com/example/test',
            'provider' => 'github',
            'mode' => 'api_only',
            'default_branch' => 'main',
            'config' => [],
            'status' => 'active',
        ]);

        $this->team->update(['settings' => ['action_proposal_policy' => ['low' => 'auto', 'medium' => 'auto', 'high' => 'ask']]]);

        $proposal = app(CreateActionProposalAction::class)->execute(
            teamId: $this->team->id,
            targetType: 'git_push',
            targetId: $repo->id,
            summary: 'High-risk: github :: mergePullRequest',
            payload: [
                'repository_id' => $repo->id,
                'method' => 'mergePullRequest',
                'args' => ['pr_number' => 7, 'method' => 'squash'],
            ],
            userId: $this->user->id,
        );

        // Mock the router so we don't construct a real GitHub client.
        // The fake client asserts the bypass binding is active when invoked.
        $fakeClient = \Mockery::mock(GitClientInterface::class);
        $fakeClient->shouldReceive('mergePullRequest')
            ->once()
            ->andReturnUsing(function ($prNumber, $method) {
                Assert::assertTrue(
                    app()->bound('git_gate.bypass') && app('git_gate.bypass'),
                    'Git gate bypass must be active during executor invocation.',
                );

                return ['sha' => 'abc123', 'merged' => true, 'message' => "merged #{$prNumber} via {$method}"];
            });

        $this->mock(GitOperationRouter::class, function ($mock) use ($fakeClient) {
            $mock->shouldReceive('resolve')->once()->andReturn($fakeClient);
        });

        $result = app(ActionProposalExecutor::class)->execute($proposal->fresh(), $this->user);

        $this->assertTrue($result['merged']);
        $this->assertSame('abc123', $result['sha']);
    }

    public function test_executor_rejects_git_push_with_missing_payload(): void
    {
        $repo = GitRepository::create([
            'team_id' => $this->team->id,
            'name' => 'test-repo',
            'url' => 'https://github.com/example/test',
            'provider' => 'github',
            'mode' => 'api_only',
            'default_branch' => 'main',
            'config' => [],
            'status' => 'active',
        ]);

        $proposal = app(CreateActionProposalAction::class)->execute(
            teamId: $this->team->id,
            targetType: 'git_push',
            targetId: $repo->id,
            summary: 'Bad',
            payload: ['repository_id' => $repo->id], // missing 'method'
            userId: $this->user->id,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('repository_id and method');

        app(ActionProposalExecutor::class)->execute($proposal->fresh(), $this->user);
    }

    private function makeProposal(): ActionProposal
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
