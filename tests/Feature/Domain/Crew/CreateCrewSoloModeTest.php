<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Crew;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Actions\CreateCrewAction;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * P26 closeout: solo-mode crews where qa_agent is omitted should
 * collapse to coordinator-as-QA without throwing the "must be
 * different" guard. Existing two-agent crew creation must continue
 * to work and still enforce the diff-check.
 */
class CreateCrewSoloModeTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Crew Solo Test',
            'slug' => 'crew-solo-test',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->actingAs($this->user);
    }

    public function test_solo_mode_crew_collapses_qa_to_coordinator(): void
    {
        $coordinator = Agent::factory()->for($this->team)->create();

        $crew = app(CreateCrewAction::class)->execute(
            userId: $this->user->id,
            name: 'Solo Crew',
            coordinatorAgentId: $coordinator->id,
            qaAgentId: null,
            teamId: $this->team->id,
        );

        $this->assertSame($coordinator->id, $crew->coordinator_agent_id);
        $this->assertSame($coordinator->id, $crew->qa_agent_id);
    }

    public function test_explicit_two_agent_crew_still_enforces_diff_check(): void
    {
        $coordinator = Agent::factory()->for($this->team)->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Coordinator and QA agents must be different');

        app(CreateCrewAction::class)->execute(
            userId: $this->user->id,
            name: 'Bad Crew',
            coordinatorAgentId: $coordinator->id,
            qaAgentId: $coordinator->id,  // explicit duplicate
            teamId: $this->team->id,
        );
    }

    public function test_explicit_two_agent_crew_works_with_distinct_agents(): void
    {
        $coordinator = Agent::factory()->for($this->team)->create();
        $qa = Agent::factory()->for($this->team)->create();

        $crew = app(CreateCrewAction::class)->execute(
            userId: $this->user->id,
            name: 'Two-Agent Crew',
            coordinatorAgentId: $coordinator->id,
            qaAgentId: $qa->id,
            teamId: $this->team->id,
        );

        $this->assertSame($coordinator->id, $crew->coordinator_agent_id);
        $this->assertSame($qa->id, $crew->qa_agent_id);
    }

    public function test_empty_string_qa_treated_as_null(): void
    {
        $coordinator = Agent::factory()->for($this->team)->create();

        $crew = app(CreateCrewAction::class)->execute(
            userId: $this->user->id,
            name: 'Solo (empty qa)',
            coordinatorAgentId: $coordinator->id,
            qaAgentId: '',
            teamId: $this->team->id,
        );

        $this->assertSame($coordinator->id, $crew->qa_agent_id);
    }
}
