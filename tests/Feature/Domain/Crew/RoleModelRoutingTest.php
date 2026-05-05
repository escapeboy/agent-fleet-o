<?php

namespace Tests\Feature\Domain\Crew;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Enums\CrewMemberRole;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewMember;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Services\ProviderResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class RoleModelRoutingTest extends TestCase
{
    use RefreshDatabase;

    private ProviderResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        // Use the unconditional base resolver (avoid CloudProviderResolver overrides
        // that strip local providers).
        $this->resolver = new ProviderResolver;

        Config::set('llm.default_provider', 'anthropic');
        Config::set('llm.default_model', 'claude-sonnet-4-5');
    }

    public function test_for_crew_role_falls_through_to_resolve_when_no_override(): void
    {
        $team = Team::factory()->create();
        $agent = Agent::factory()->for($team)->create([
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
        ]);
        $coordinator = Agent::factory()->for($team)->create();
        $qa = Agent::factory()->for($team)->create();
        $crew = Crew::factory()->for($team)->create([
            'coordinator_agent_id' => $coordinator->id,
            'qa_agent_id' => $qa->id,
        ]);
        $member = CrewMember::factory()->for($crew)->for($agent)->create([
            'role' => CrewMemberRole::Worker,
            'config' => [],
        ]);

        $resolved = $this->resolver->forCrewRole($member);

        $this->assertSame('anthropic', $resolved['provider']);
        $this->assertSame('claude-sonnet-4-5', $resolved['model']);
    }

    public function test_for_crew_role_honors_model_override(): void
    {
        $team = Team::factory()->create();
        $agent = Agent::factory()->for($team)->create([
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
        ]);
        $coordinator = Agent::factory()->for($team)->create();
        $qa = Agent::factory()->for($team)->create();
        $crew = Crew::factory()->for($team)->create([
            'coordinator_agent_id' => $coordinator->id,
            'qa_agent_id' => $qa->id,
        ]);
        $member = CrewMember::factory()->for($crew)->for($agent)->create([
            'role' => CrewMemberRole::Judge,
            'config' => [
                'model_override' => [
                    'provider' => 'anthropic',
                    'model' => 'claude-haiku-4-5',
                ],
            ],
        ]);

        $resolved = $this->resolver->forCrewRole($member);

        $this->assertSame('anthropic', $resolved['provider']);
        $this->assertSame('claude-haiku-4-5', $resolved['model']);
    }

    public function test_judge_role_enum_value_persists(): void
    {
        $team = Team::factory()->create();
        $agent = Agent::factory()->for($team)->create();
        $coordinator = Agent::factory()->for($team)->create();
        $qa = Agent::factory()->for($team)->create();
        $crew = Crew::factory()->for($team)->create([
            'coordinator_agent_id' => $coordinator->id,
            'qa_agent_id' => $qa->id,
        ]);
        $member = CrewMember::factory()->for($crew)->for($agent)->judge()->create();

        $reloaded = CrewMember::find($member->id);

        $this->assertSame(CrewMemberRole::Judge, $reloaded->role);
        $this->assertSame('judge', $reloaded->role->value);
        $this->assertSame('Judge', $reloaded->role->label());
    }

    public function test_for_agent_in_crew_lookup(): void
    {
        $team = Team::factory()->create();
        $agent = Agent::factory()->for($team)->create();
        $coordinator = Agent::factory()->for($team)->create();
        $qa = Agent::factory()->for($team)->create();
        $crew = Crew::factory()->for($team)->create([
            'coordinator_agent_id' => $coordinator->id,
            'qa_agent_id' => $qa->id,
        ]);
        $member = CrewMember::factory()->for($crew)->for($agent)->qa()->create();

        $found = CrewMember::forAgentInCrew($agent->id, $crew->id);
        $this->assertNotNull($found);
        $this->assertSame($member->id, $found->id);

        $missing = CrewMember::forAgentInCrew('00000000-0000-0000-0000-000000000000', $crew->id);
        $this->assertNull($missing);

        $nullArg = CrewMember::forAgentInCrew(null, $crew->id);
        $this->assertNull($nullArg);
    }
}
