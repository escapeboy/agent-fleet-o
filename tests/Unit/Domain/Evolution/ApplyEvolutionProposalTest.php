<?php

namespace Tests\Unit\Domain\Evolution;

use App\Domain\Agent\Actions\CreateAgentAction;
use App\Domain\Agent\Models\Agent;
use App\Domain\Evolution\Actions\ApplyEvolutionProposalAction;
use App\Domain\Evolution\Enums\EvolutionProposalStatus;
use App\Domain\Evolution\Models\EvolutionProposal;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplyEvolutionProposalTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);

        $this->agent = app(CreateAgentAction::class)->execute(
            name: 'Evolution Test Agent',
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            teamId: $this->team->id,
            role: 'Test Role',
            goal: 'Original goal',
        );
    }

    public function test_applies_goal_change(): void
    {
        $proposal = EvolutionProposal::create([
            'team_id' => $this->team->id,
            'agent_id' => $this->agent->id,
            'status' => EvolutionProposalStatus::Approved,
            'analysis' => 'Goal needs refinement',
            'proposed_changes' => ['goal' => 'Improved goal statement'],
            'reasoning' => 'Better clarity',
            'confidence_score' => 0.8,
        ]);

        $action = new ApplyEvolutionProposalAction;
        $updatedAgent = $action->execute($proposal, $this->user->id);

        $this->assertEquals('Improved goal statement', $updatedAgent->goal);
        $this->assertEquals(EvolutionProposalStatus::Applied, $proposal->fresh()->status);
    }

    public function test_applies_personality_changes(): void
    {
        $proposal = EvolutionProposal::create([
            'team_id' => $this->team->id,
            'agent_id' => $this->agent->id,
            'status' => EvolutionProposalStatus::Approved,
            'analysis' => 'Needs personality adjustment',
            'proposed_changes' => [
                'personality' => ['tone' => 'assertive', 'traits' => ['decisive']],
            ],
            'confidence_score' => 0.7,
        ]);

        $action = new ApplyEvolutionProposalAction;
        $updatedAgent = $action->execute($proposal, $this->user->id);

        $this->assertEquals('assertive', $updatedAgent->personality['tone']);
        $this->assertEquals(['decisive'], $updatedAgent->personality['traits']);
    }

    public function test_rejects_non_approved_proposal(): void
    {
        $proposal = EvolutionProposal::create([
            'team_id' => $this->team->id,
            'agent_id' => $this->agent->id,
            'status' => EvolutionProposalStatus::Pending,
            'analysis' => 'Test',
            'proposed_changes' => ['goal' => 'New goal'],
            'confidence_score' => 0.5,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only approved proposals can be applied');

        $action = new ApplyEvolutionProposalAction;
        $action->execute($proposal, $this->user->id);
    }

    public function test_records_reviewer_info(): void
    {
        $proposal = EvolutionProposal::create([
            'team_id' => $this->team->id,
            'agent_id' => $this->agent->id,
            'status' => EvolutionProposalStatus::Approved,
            'analysis' => 'Test',
            'proposed_changes' => ['goal' => 'Updated goal'],
            'confidence_score' => 0.6,
        ]);

        $action = new ApplyEvolutionProposalAction;
        $action->execute($proposal, $this->user->id);

        $fresh = $proposal->fresh();
        $this->assertEquals($this->user->id, $fresh->reviewed_by);
        $this->assertNotNull($fresh->reviewed_at);
    }
}
