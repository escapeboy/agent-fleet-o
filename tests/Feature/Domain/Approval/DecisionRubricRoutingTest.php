<?php

namespace Tests\Feature\Domain\Approval;

use App\Domain\Approval\Actions\CreateActionProposalAction;
use App\Domain\Approval\Enums\ActionProposalStatus;
use App\Domain\Approval\Jobs\ExecuteActionProposalJob;
use App\Domain\Approval\Models\ActionProposal;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DecisionRubricRoutingTest extends TestCase
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

    private function createProposal(string $riskLevel, array $payload): ActionProposal
    {
        return app(CreateActionProposalAction::class)->execute(
            teamId: $this->team->id,
            targetType: 'tool_call',
            targetId: null,
            summary: 'Rubric routing test',
            payload: array_merge(['tool' => 'noop'], $payload),
            userId: $this->user->id,
            riskLevel: $riskLevel,
        );
    }

    public function test_scoring_is_recorded_and_proposal_stays_pending_with_auto_routing_off(): void
    {
        config(['decision_rubric.auto_execute.enabled' => false]);
        config(['decision_rubric.auto_reject.enabled' => false]);

        $proposal = $this->createProposal('low', ['estimated_credits' => 1, 'impact' => 5, 'urgency' => 5]);

        $this->assertSame(ActionProposalStatus::Pending, $proposal->status);
        $this->assertSame(24, $proposal->rubric_score);
        $this->assertSame('human_review', $proposal->rubric_breakdown['recommendation']);
        $this->assertSame(5, $proposal->rubric_breakdown['risk']);
    }

    public function test_high_score_auto_approves_and_dispatches_execution_when_enabled(): void
    {
        Queue::fake();
        config(['decision_rubric.auto_execute.enabled' => true]);

        $proposal = $this->createProposal('low', ['estimated_credits' => 1, 'impact' => 5, 'urgency' => 5]);

        $this->assertSame(ActionProposalStatus::Approved, $proposal->status);
        $this->assertNotNull($proposal->decided_at);
        $this->assertStringContainsString('decision rubric', (string) $proposal->decision_reason);
        Queue::assertPushed(ExecuteActionProposalJob::class);
    }

    public function test_low_score_auto_rejects_when_enabled(): void
    {
        config(['decision_rubric.auto_reject.enabled' => true]);

        $proposal = $this->createProposal('high', ['estimated_credits' => 5000, 'impact' => 1, 'urgency' => 1]);

        $this->assertSame(ActionProposalStatus::Rejected, $proposal->status);
        $this->assertStringContainsString('decision rubric', (string) $proposal->decision_reason);
    }

    public function test_critical_risk_stays_pending_even_with_a_high_score(): void
    {
        Queue::fake();
        config(['decision_rubric.auto_execute.enabled' => true]);

        $proposal = $this->createProposal('critical', ['estimated_credits' => 1, 'impact' => 5, 'urgency' => 5]);

        $this->assertSame(ActionProposalStatus::Pending, $proposal->status);
        Queue::assertNotPushed(ExecuteActionProposalJob::class);
    }
}
