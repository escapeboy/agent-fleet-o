<?php

namespace Tests\Unit\Domain\Agent;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Pipeline\AgentExecutionContext;
use App\Domain\Agent\Pipeline\Middleware\InjectTeamContext;
use App\Domain\Crew\Enums\CrewMemberRole;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewMember;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InjectTeamContextTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test team '.bin2hex(random_bytes(3)),
            'slug' => 'test-'.bin2hex(random_bytes(3)),
            'owner_id' => $this->owner->id,
            'settings' => [],
        ]);
    }

    public function test_no_op_when_agent_has_no_crew_memberships(): void
    {
        $agent = Agent::factory()->create(['team_id' => $this->team->id]);
        $ctx = $this->buildContext($agent);

        $result = (new InjectTeamContext)->handle($ctx, fn ($c) => $c);

        $this->assertSame([], $result->systemPromptParts);
    }

    public function test_injects_team_context_with_peers(): void
    {
        $agent = Agent::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Tech Lead',
            'role' => 'Engineering Lead',
        ]);
        $peer1 = Agent::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Backend Dev',
            'role' => 'Senior Engineer',
        ]);
        $peer2 = Agent::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'QA Bot',
            'role' => null,
        ]);

        $crew = Crew::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->owner->id,
            'coordinator_agent_id' => $agent->id,
            'qa_agent_id' => $peer2->id,
            'name' => 'Engineering Crew',
            'description' => 'Ship the next release.',
        ]);

        CrewMember::factory()->create([
            'crew_id' => $crew->id,
            'agent_id' => $agent->id,
            'role' => CrewMemberRole::Coordinator,
        ]);
        CrewMember::factory()->create([
            'crew_id' => $crew->id,
            'agent_id' => $peer1->id,
            'role' => CrewMemberRole::Worker,
        ]);
        CrewMember::factory()->qa()->create([
            'crew_id' => $crew->id,
            'agent_id' => $peer2->id,
        ]);

        $ctx = $this->buildContext($agent);

        $result = (new InjectTeamContext)->handle($ctx, fn ($c) => $c);

        $this->assertCount(1, $result->systemPromptParts);
        $section = $result->systemPromptParts[0];

        $this->assertStringContainsString('## Team Context: Engineering Crew', $section);
        $this->assertStringContainsString('Your role on this team: **coordinator**', $section);
        $this->assertStringContainsString('Team goal: Ship the next release.', $section);
        $this->assertStringContainsString('- Backend Dev — worker (Senior Engineer)', $section);
        $this->assertStringContainsString('- QA Bot — qa', $section);
        $this->assertStringNotContainsString('Tech Lead', $section, 'Self should be excluded from peer list');
    }

    public function test_injects_separate_section_per_crew(): void
    {
        $agent = Agent::factory()->create(['team_id' => $this->team->id, 'name' => 'Polyglot']);
        $peer = Agent::factory()->create(['team_id' => $this->team->id, 'name' => 'Helper']);

        $crewA = Crew::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->owner->id,
            'coordinator_agent_id' => $agent->id,
            'qa_agent_id' => $peer->id,
            'name' => 'Crew A',
            'description' => null,
        ]);
        $crewB = Crew::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->owner->id,
            'coordinator_agent_id' => $agent->id,
            'qa_agent_id' => $peer->id,
            'name' => 'Crew B',
            'description' => 'Beta team.',
        ]);

        foreach ([$crewA, $crewB] as $crew) {
            CrewMember::factory()->create([
                'crew_id' => $crew->id,
                'agent_id' => $agent->id,
                'role' => CrewMemberRole::Worker,
            ]);
            CrewMember::factory()->create([
                'crew_id' => $crew->id,
                'agent_id' => $peer->id,
                'role' => CrewMemberRole::Worker,
            ]);
        }

        $ctx = $this->buildContext($agent);

        $result = (new InjectTeamContext)->handle($ctx, fn ($c) => $c);

        $this->assertCount(1, $result->systemPromptParts);
        $combined = $result->systemPromptParts[0];

        $this->assertStringContainsString('## Team Context: Crew A', $combined);
        $this->assertStringContainsString('## Team Context: Crew B', $combined);
        $this->assertStringNotContainsString('Team goal:', explode('## Team Context: Crew B', $combined)[0],
            'Crew A had null description — should not render Team goal line');
        $this->assertStringContainsString('Team goal: Beta team.', $combined);
    }

    public function test_skips_crew_when_only_member_is_self(): void
    {
        $agent = Agent::factory()->create(['team_id' => $this->team->id]);
        $crew = Crew::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->owner->id,
            'coordinator_agent_id' => $agent->id,
            'qa_agent_id' => $agent->id,
        ]);
        CrewMember::factory()->create([
            'crew_id' => $crew->id,
            'agent_id' => $agent->id,
        ]);

        $ctx = $this->buildContext($agent);

        $result = (new InjectTeamContext)->handle($ctx, fn ($c) => $c);

        $this->assertSame([], $result->systemPromptParts);
    }

    public function test_calls_next_exactly_once_with_same_context(): void
    {
        $agent = Agent::factory()->create(['team_id' => $this->team->id]);
        $ctx = $this->buildContext($agent);
        $callCount = 0;
        $received = null;

        (new InjectTeamContext)->handle($ctx, function ($c) use (&$callCount, &$received) {
            $callCount++;
            $received = $c;

            return $c;
        });

        $this->assertSame(1, $callCount);
        $this->assertSame($ctx, $received);
    }

    private function buildContext(Agent $agent): AgentExecutionContext
    {
        return new AgentExecutionContext(
            agent: $agent,
            teamId: $this->team->id,
            userId: $this->owner->id,
            experimentId: null,
            project: null,
            input: ['task' => 'do work'],
        );
    }
}
