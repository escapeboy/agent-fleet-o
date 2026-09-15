<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Tool;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentPolicy;
use App\Domain\Agent\Models\AgentPolicyVersion;
use App\Domain\Approval\Actions\ApproveActionProposalAction;
use App\Domain\Approval\Actions\CreateActionProposalAction;
use App\Domain\Approval\Actions\ExpireStaleApprovalsAction;
use App\Domain\Approval\Enums\ActionProposalStatus;
use App\Domain\Approval\Events\ActionProposalExecuted;
use App\Domain\Approval\Jobs\ExecuteActionProposalJob;
use App\Domain\Approval\Models\ActionProposal;
use App\Domain\Approval\Services\ActionProposalExecutor;
use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Actions\ResolveAgentToolsAction;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Services\ToolApprovalGate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Prism\Prism\Facades\Tool as PrismTool;
use Prism\Prism\Tool as PrismToolObject;
use RuntimeException;
use Tests\TestCase;

/**
 * agent_tool.approval_mode = ask: a call is held as an ActionProposal, runs only
 * after approval (replayed through the agent's own tools), and is settled by
 * the tool's timeout action when nobody decides.
 */
class ToolApprovalGateTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Team $team;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent.planning_tool.enabled' => true, 'decision_rubric.enabled' => false]);

        $this->owner = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Approval Team',
            'slug' => 'approval-'.Str::lower(Str::random(6)),
            'owner_id' => $this->owner->id,
            'settings' => [],
        ]);
        $this->owner->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->owner, ['role' => 'owner']);

        $this->agent = Agent::create([
            'id' => (string) Str::uuid7(),
            'team_id' => $this->team->id,
            'name' => 'Approval Agent',
            'slug' => 'approval-agent',
            'role' => 'assistant',
            'goal' => 'help',
            'backstory' => 'test',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet',
            'status' => AgentStatus::Active,
            'config' => [],
        ]);
    }

    private function attachPlanTool(string $mode = 'ask', ?int $timeoutMinutes = null, string $timeoutAction = 'deny'): Tool
    {
        $tool = Tool::factory()->create([
            'team_id' => $this->team->id,
            'type' => ToolType::BuiltIn,
            'status' => ToolStatus::Active,
            'transport_config' => ['kind' => 'plan'],
        ]);

        $pivot = ['priority' => 0, 'approval_mode' => $mode, 'approval_timeout_action' => $timeoutAction];
        if ($timeoutMinutes !== null) {
            $pivot['approval_timeout_minutes'] = $timeoutMinutes;
        }
        $this->agent->tools()->attach($tool->id, $pivot);

        return $tool;
    }

    private function updatePlanTool(): PrismToolObject
    {
        $tool = collect(app(ResolveAgentToolsAction::class)->execute($this->agent->fresh()))
            ->first(fn ($t) => $t->name() === 'update_plan');

        $this->assertNotNull($tool, 'update_plan must be resolved');

        return $tool;
    }

    private function todos(): string
    {
        return json_encode([['content' => 'ship it', 'status' => 'pending']]);
    }

    private function approveAndRun(ActionProposal $proposal): ActionProposal
    {
        Queue::fake();
        app(ApproveActionProposalAction::class)->execute($proposal, $this->owner->fresh());
        Queue::assertPushed(ExecuteActionProposalJob::class);

        (new ExecuteActionProposalJob($proposal->id))->handle(app(ActionProposalExecutor::class));

        return $proposal->refresh();
    }

    public function test_ask_tool_call_creates_a_pending_proposal_instead_of_running(): void
    {
        $this->attachPlanTool(timeoutMinutes: 45);

        $result = $this->updatePlanTool()->handle(todos: $this->todos());

        $proposal = ActionProposal::where('team_id', $this->team->id)->sole();
        $this->assertIsString($result);
        $this->assertStringContainsString('waiting for human approval', $result);
        $this->assertStringContainsString($proposal->id, $result);
        $this->assertStringNotContainsString('[ ] ship it', $result, 'the plan tool must not have run');

        $this->assertSame(ToolApprovalGate::TARGET_TYPE, $proposal->target_type);
        $this->assertSame(ActionProposalStatus::Pending, $proposal->status);
        $this->assertSame($this->agent->id, $proposal->actor_agent_id);
        $this->assertSame('update_plan', $proposal->payload['tool']);
        $this->assertSame(['todos' => $this->todos()], $proposal->payload['arguments']);
        $this->assertSame($this->agent->id, $proposal->payload['agent_id']);
        $this->assertSame('deny', $proposal->payload['timeout_action']);
        $this->assertTrue($proposal->expires_at->between(now()->addMinutes(44), now()->addMinutes(46)));
    }

    public function test_tool_description_tells_the_model_approval_is_required(): void
    {
        $this->attachPlanTool();

        $this->assertStringContainsString('Requires human approval', $this->updatePlanTool()->description());
    }

    public function test_auto_mode_tool_is_not_gated(): void
    {
        $this->attachPlanTool(mode: 'auto');

        $result = $this->updatePlanTool()->handle(todos: $this->todos());

        $this->assertStringContainsString('[ ] ship it', (string) $result);
        $this->assertSame(0, ActionProposal::count());
    }

    public function test_approved_call_is_replayed_once_and_the_result_stored(): void
    {
        $this->attachPlanTool();
        $this->updatePlanTool()->handle(todos: $this->todos());

        $proposal = $this->approveAndRun(ActionProposal::sole());

        $this->assertSame(ActionProposalStatus::Executed, $proposal->status, (string) $proposal->execution_error);
        $this->assertStringContainsString('[ ] ship it', json_encode($proposal->execution_result));
        $this->assertSame(1, ActionProposal::count(), 'the replay must not raise a second proposal');
        $this->assertFalse(app()->bound(ToolApprovalGate::BYPASS_BINDING));
    }

    public function test_replay_fails_when_the_tool_was_denied_after_the_request(): void
    {
        $tool = $this->attachPlanTool();
        $this->updatePlanTool()->handle(todos: $this->todos());
        $this->agent->tools()->updateExistingPivot($tool->id, ['approval_mode' => 'deny']);

        $proposal = $this->approveAndRun(ActionProposal::sole());

        $this->assertSame(ActionProposalStatus::ExecutionFailed, $proposal->status);
        $this->assertStringContainsString('no longer available', (string) $proposal->execution_error);
    }

    public function test_replay_fails_for_a_disabled_agent(): void
    {
        $this->attachPlanTool();
        $this->updatePlanTool()->handle(todos: $this->todos());
        $this->agent->update(['status' => AgentStatus::Disabled]);

        $proposal = $this->approveAndRun(ActionProposal::sole());

        $this->assertSame(ActionProposalStatus::ExecutionFailed, $proposal->status);
        $this->assertStringContainsString('disabled', (string) $proposal->execution_error);
    }

    public function test_replay_rejects_positional_arguments_payload(): void
    {
        $proposal = ActionProposal::create([
            'team_id' => $this->team->id,
            'actor_agent_id' => $this->agent->id,
            'target_type' => ToolApprovalGate::TARGET_TYPE,
            'summary' => 'x',
            'payload' => ['tool' => 'update_plan', 'arguments' => ['a', 'b'], 'agent_id' => $this->agent->id],
            'risk_level' => 'high',
            'status' => 'pending',
        ]);

        $proposal = $this->approveAndRun($proposal);

        $this->assertSame(ActionProposalStatus::ExecutionFailed, $proposal->status);
        $this->assertStringContainsString('must be an object', (string) $proposal->execution_error);
    }

    public function test_failure_to_create_the_request_refuses_the_call(): void
    {
        $this->attachPlanTool();
        $create = Mockery::mock(CreateActionProposalAction::class);
        $create->shouldReceive('execute')->andThrow(new RuntimeException('database unavailable'));
        $this->app->instance(CreateActionProposalAction::class, $create);

        $result = (string) $this->updatePlanTool()->handle(todos: $this->todos());

        $this->assertStringContainsString('could not be created', $result);
        $this->assertStringNotContainsString('[ ] ship it', $result);
        $this->assertStringNotContainsString('database unavailable', $result);
    }

    public function test_positional_call_is_stored_by_parameter_name_and_runs_only_with_bypass(): void
    {
        $runs = 0;
        $prismTool = PrismTool::as('pair_tool')
            ->for('takes two values')
            ->withStringParameter('first', 'first')
            ->withStringParameter('second', 'second')
            ->using(function (string $first, string $second) use (&$runs): string {
                $runs++;

                return "ran {$first} {$second}";
            });
        $toolModel = Tool::factory()->create(['team_id' => $this->team->id]);

        [$gated] = app(ToolApprovalGate::class)->wrap([$prismTool], $this->agent, $toolModel);

        $gated->handle('a', 'b');
        $this->assertSame(0, $runs);

        $proposal = ActionProposal::sole();
        $this->assertSame(['first' => 'a', 'second' => 'b'], $proposal->payload['arguments']);
        $this->assertSame((string) $toolModel->id, $proposal->target_id);
        $this->assertTrue($proposal->expires_at->between(now()->addMinutes(29), now()->addMinutes(31)), 'default window is 30 minutes');

        $out = ToolApprovalGate::withBypass('pair_tool', $this->agent->id, fn () => $gated->handle(first: 'a', second: 'b'));
        $this->assertSame('ran a b', $out);
        $this->assertSame(1, $runs);
    }

    public function test_bypass_is_one_shot_and_scoped_to_tool_and_agent(): void
    {
        $seen = ToolApprovalGate::withBypass('t', 'agent-1', fn () => [
            ToolApprovalGate::consumeBypass('t', ToolApprovalGate::CONSUMER_ASK, 'agent-2'),
            ToolApprovalGate::consumeBypass('other', ToolApprovalGate::CONSUMER_ASK, 'agent-1'),
            ToolApprovalGate::consumeBypass('t', ToolApprovalGate::CONSUMER_ASK, 'agent-1'),
            ToolApprovalGate::consumeBypass('t', ToolApprovalGate::CONSUMER_ASK, 'agent-1'),
            ToolApprovalGate::consumeBypass('t', ToolApprovalGate::CONSUMER_PREDICATE, 'agent-1'),
        ]);

        $this->assertSame([false, false, true, false, true], $seen);
        $this->assertFalse(app()->bound(ToolApprovalGate::BYPASS_BINDING));
        $this->assertFalse(ToolApprovalGate::consumeBypass('t', ToolApprovalGate::CONSUMER_ASK, 'agent-1'));
    }

    public function test_bypass_is_removed_when_the_callback_throws(): void
    {
        try {
            ToolApprovalGate::withBypass('t', 'agent-1', function (): void {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }

        $this->assertFalse(app()->bound(ToolApprovalGate::BYPASS_BINDING));
    }

    public function test_replay_runs_only_the_approved_tool_row(): void
    {
        $approvedRow = $this->attachPlanTool();
        $this->updatePlanTool()->handle(todos: $this->todos());

        // Swap in a different row that produces the same tool name.
        $this->agent->tools()->detach($approvedRow->id);
        $this->attachPlanTool(mode: 'auto');

        $proposal = $this->approveAndRun(ActionProposal::sole());

        $this->assertSame(ActionProposalStatus::ExecutionFailed, $proposal->status);
        $this->assertStringContainsString('no longer available', (string) $proposal->execution_error);
        $this->assertNull($proposal->execution_result);
    }

    public function test_request_records_the_execution_context(): void
    {
        $tool = $this->attachPlanTool();

        $gated = collect(app(ResolveAgentToolsAction::class)->execute(
            $this->agent->fresh(), null, 'exec-ctx-1', null, 0, null, null, [$tool->id],
        ))->first(fn ($t) => $t->name() === 'update_plan');
        $gated->handle(todos: $this->todos());

        $payload = ActionProposal::sole()->payload;
        $this->assertSame((string) $tool->id, $payload['tool_id']);
        $this->assertSame([
            'project_id' => null,
            'allowed_tool_ids' => [(string) $tool->id],
            'execution_id' => 'exec-ctx-1',
            'sidecar_session_id' => null,
        ], $payload['context']);
    }

    public function test_replay_reapplies_the_recorded_allowlist(): void
    {
        $this->attachPlanTool();
        $this->updatePlanTool()->handle(todos: $this->todos());
        $proposal = ActionProposal::sole();
        $payload = $proposal->payload;
        $payload['context']['allowed_tool_ids'] = [(string) Str::uuid7()];
        $proposal->update(['payload' => $payload]);

        $proposal = $this->approveAndRun($proposal);

        $this->assertSame(ActionProposalStatus::ExecutionFailed, $proposal->status);
        $this->assertStringContainsString('outside the allowlist', (string) $proposal->execution_error);
    }

    public function test_replay_fails_when_the_recorded_project_is_gone(): void
    {
        $this->attachPlanTool();
        $this->updatePlanTool()->handle(todos: $this->todos());
        $proposal = ActionProposal::sole();
        $payload = $proposal->payload;
        $payload['context']['project_id'] = (string) Str::uuid7();
        $proposal->update(['payload' => $payload]);

        $proposal = $this->approveAndRun($proposal);

        $this->assertSame(ActionProposalStatus::ExecutionFailed, $proposal->status);
        $this->assertStringContainsString('no longer exists', (string) $proposal->execution_error);
    }

    public function test_replay_without_a_recorded_tool_row_is_refused(): void
    {
        $this->attachPlanTool();
        $proposal = $this->heldProposal('deny', ['expires_at' => now()->addMinutes(10)]);

        $proposal = $this->approveAndRun($proposal);

        $this->assertSame(ActionProposalStatus::ExecutionFailed, $proposal->status);
        $this->assertStringContainsString('cannot be replayed safely', (string) $proposal->execution_error);
    }

    public function test_a_second_approval_of_the_same_proposal_is_refused(): void
    {
        Queue::fake();
        $this->heldProposal('deny', ['expires_at' => now()->addMinutes(10)]);
        $first = ActionProposal::sole();
        $stale = ActionProposal::sole();

        app(ApproveActionProposalAction::class)->execute($first, $this->owner->fresh());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no longer pending');
        app(ApproveActionProposalAction::class)->execute($stale, $this->owner->fresh());
    }

    public function test_execution_job_runs_an_approved_proposal_only_once(): void
    {
        $this->attachPlanTool();
        $this->updatePlanTool()->handle(todos: $this->todos());
        $proposal = ActionProposal::sole();

        Queue::fake();
        app(ApproveActionProposalAction::class)->execute($proposal, $this->owner->fresh());
        Event::fake([ActionProposalExecuted::class]);

        (new ExecuteActionProposalJob($proposal->id))->handle(app(ActionProposalExecutor::class));
        (new ExecuteActionProposalJob($proposal->id))->handle(app(ActionProposalExecutor::class));

        Event::assertDispatchedTimes(ActionProposalExecuted::class, 1);
        $this->assertSame(ActionProposalStatus::Executed, $proposal->refresh()->status);
    }

    public function test_a_job_that_dies_mid_execution_marks_the_proposal_failed(): void
    {
        $proposal = $this->heldProposal('deny', ['status' => 'approved', 'expires_at' => null, 'executed_at' => now()]);

        (new ExecuteActionProposalJob($proposal->id))->failed(new RuntimeException('worker killed'));

        $proposal->refresh();
        $this->assertSame(ActionProposalStatus::ExecutionFailed, $proposal->status);
        $this->assertStringContainsString('did not finish: RuntimeException', (string) $proposal->execution_error);
    }

    public function test_job_failure_hook_leaves_settled_proposals_alone(): void
    {
        $proposal = $this->heldProposal('deny', ['status' => 'executed', 'expires_at' => null, 'executed_at' => now()]);

        (new ExecuteActionProposalJob($proposal->id))->failed(new RuntimeException('late'));

        $this->assertSame(ActionProposalStatus::Executed, $proposal->refresh()->status);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function heldProposal(string $timeoutAction, array $overrides = []): ActionProposal
    {
        return ActionProposal::create(array_merge([
            'team_id' => $this->team->id,
            'actor_agent_id' => $this->agent->id,
            'target_type' => ToolApprovalGate::TARGET_TYPE,
            'summary' => 'held',
            'payload' => [
                'tool' => 'update_plan',
                'arguments' => ['todos' => '[]'],
                'agent_id' => $this->agent->id,
                'timeout_action' => $timeoutAction,
            ],
            'risk_level' => 'high',
            'status' => 'pending',
            'expires_at' => now()->subMinute(),
        ], $overrides));
    }

    public function test_timeout_deny_and_skip_expire_the_call(): void
    {
        Queue::fake();
        $deny = $this->heldProposal('deny');
        $skip = $this->heldProposal('skip');

        app(ExpireStaleApprovalsAction::class)->execute();

        foreach ([$deny->refresh(), $skip->refresh()] as $proposal) {
            $this->assertSame(ActionProposalStatus::Expired, $proposal->status);
            $this->assertStringContainsString('did not run', (string) $proposal->decision_reason);
        }
        Queue::assertNothingPushed();
    }

    public function test_timeout_allow_approves_and_queues_the_replay(): void
    {
        Queue::fake();
        $proposal = $this->heldProposal('allow');

        app(ExpireStaleApprovalsAction::class)->execute();

        $proposal->refresh();
        $this->assertSame(ActionProposalStatus::Approved, $proposal->status);
        $this->assertNull($proposal->expires_at, 'the job refuses proposals past expires_at');
        Queue::assertPushed(ExecuteActionProposalJob::class, fn ($job) => $job->proposalId === $proposal->id);
    }

    public function test_timeout_allow_does_not_override_an_agent_policy(): void
    {
        Queue::fake();
        $policy = AgentPolicy::create([
            'team_id' => $this->team->id,
            'agent_id' => $this->agent->id,
            'name' => 'Needs a human',
            'status' => 'active',
            'enabled' => true,
        ]);
        $version = AgentPolicyVersion::create([
            'team_id' => $this->team->id,
            'agent_policy_id' => $policy->id,
            'version' => 1,
            'rules' => [],
            'created_at' => now(),
        ]);
        $proposal = $this->heldProposal('allow', ['agent_policy_version_id' => $version->id]);

        app(ExpireStaleApprovalsAction::class)->execute();

        $this->assertSame(ActionProposalStatus::Expired, $proposal->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_settlement_leaves_open_windows_decided_proposals_and_other_types_alone(): void
    {
        Queue::fake();
        $open = $this->heldProposal('allow', ['expires_at' => now()->addMinutes(10)]);
        $decided = $this->heldProposal('allow', ['status' => 'rejected']);
        $assistant = $this->heldProposal('allow', ['target_type' => 'tool_call']);

        app(ExpireStaleApprovalsAction::class)->execute();

        $this->assertSame(ActionProposalStatus::Pending, $open->refresh()->status);
        $this->assertSame(ActionProposalStatus::Rejected, $decided->refresh()->status);
        $this->assertSame(ActionProposalStatus::Pending, $assistant->refresh()->status);
        Queue::assertNothingPushed();
    }
}
