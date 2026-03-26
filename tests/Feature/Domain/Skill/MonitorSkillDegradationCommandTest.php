<?php

namespace Tests\Feature\Domain\Skill;

use App\Domain\Agent\Models\Agent;
use App\Domain\Evolution\Enums\EvolutionProposalStatus;
use App\Domain\Evolution\Models\EvolutionProposal;
use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorSkillDegradationCommandTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($user, ['role' => 'owner']);

        // The command resolves first agent for the team — create one
        $this->agent = Agent::factory()->create(['team_id' => $this->team->id]);
    }

    private function makeDegradedSkill(array $overrides = []): Skill
    {
        return Skill::create(array_merge([
            'team_id' => $this->team->id,
            'name' => 'Degraded Skill',
            'slug' => 'degraded-skill-'.uniqid(),
            'type' => 'llm',
            'status' => 'active',
            'configuration' => [],
            // reliability = 3/10 = 0.3 → below threshold 0.6
            'applied_count' => 10,
            'completed_count' => 3,
            'effective_count' => 3,
            'fallback_count' => 0,
        ], $overrides));
    }

    private function makeHealthySkill(array $overrides = []): Skill
    {
        return Skill::create(array_merge([
            'team_id' => $this->team->id,
            'name' => 'Healthy Skill',
            'slug' => 'healthy-skill-'.uniqid(),
            'type' => 'llm',
            'status' => 'active',
            'configuration' => [],
            // reliability = 9/10 = 0.9, quality = 9/9 = 1.0 → both above threshold
            'applied_count' => 10,
            'completed_count' => 9,
            'effective_count' => 9,
            'fallback_count' => 0,
        ], $overrides));
    }

    public function test_command_creates_evolution_proposal_for_degraded_skill(): void
    {
        $skill = $this->makeDegradedSkill();

        $this->artisan('skills:monitor-degradation')
            ->assertExitCode(0);

        $this->assertDatabaseHas('evolution_proposals', [
            'team_id' => $this->team->id,
            'skill_id' => $skill->id,
            'agent_id' => $this->agent->id,
            'trigger' => 'degradation_monitor',
            'status' => EvolutionProposalStatus::Pending->value,
        ]);
    }

    public function test_command_skips_skills_below_min_sample_size(): void
    {
        // applied_count = 5 < min_sample_size (default 10)
        Skill::create([
            'team_id' => $this->team->id,
            'name' => 'Low Sample Skill',
            'slug' => 'low-sample-'.uniqid(),
            'type' => 'llm',
            'status' => 'active',
            'configuration' => [],
            'applied_count' => 5,
            'completed_count' => 1, // would be degraded if sample large enough
            'effective_count' => 0,
            'fallback_count' => 0,
        ]);

        $this->artisan('skills:monitor-degradation')
            ->assertExitCode(0);

        $this->assertDatabaseCount('evolution_proposals', 0);
    }

    public function test_command_does_not_create_duplicate_proposal_when_pending_exists(): void
    {
        $skill = $this->makeDegradedSkill();

        // Create a pre-existing pending proposal
        EvolutionProposal::create([
            'team_id' => $this->team->id,
            'agent_id' => $this->agent->id,
            'skill_id' => $skill->id,
            'trigger' => 'degradation_monitor',
            'status' => EvolutionProposalStatus::Pending,
            'analysis' => 'Existing proposal.',
            'proposed_changes' => [],
            'reasoning' => 'Pre-existing.',
            'confidence_score' => 0.5,
        ]);

        $this->artisan('skills:monitor-degradation')
            ->assertExitCode(0);

        // Still only the one pre-existing proposal, no duplicate created
        $this->assertDatabaseCount('evolution_proposals', 1);
    }

    public function test_command_does_not_create_proposal_for_healthy_skill(): void
    {
        $this->makeHealthySkill();

        $this->artisan('skills:monitor-degradation')
            ->assertExitCode(0);

        $this->assertDatabaseCount('evolution_proposals', 0);
    }

    public function test_dry_run_shows_degraded_skills_but_does_not_create_proposals(): void
    {
        $this->makeDegradedSkill(['name' => 'Dry Run Skill']);

        $this->artisan('skills:monitor-degradation', ['--dry-run' => true])
            ->expectsOutputToContain('Dry-run')
            ->assertExitCode(0);

        $this->assertDatabaseCount('evolution_proposals', 0);
    }

    public function test_command_outputs_count_of_degraded_skills(): void
    {
        $this->makeDegradedSkill();
        $this->makeDegradedSkill(['name' => 'Second Degraded Skill', 'slug' => 'second-degraded-'.uniqid()]);
        $this->makeHealthySkill();

        $this->artisan('skills:monitor-degradation')
            ->expectsOutputToContain('2')
            ->assertExitCode(0);
    }
}
