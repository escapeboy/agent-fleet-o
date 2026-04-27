<?php

namespace Tests\Feature\Livewire\TeamGraph;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Enums\CrewMemberRole;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewMember;
use App\Domain\Shared\Models\Team;
use App\Livewire\TeamGraph\TeamGraphPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TeamGraphPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test '.bin2hex(random_bytes(3)),
            'slug' => 'test-'.bin2hex(random_bytes(3)),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);

        $this->actingAs($this->user);
    }

    public function test_mount_builds_graph_for_team_with_agents_and_crew(): void
    {
        $coordinator = Agent::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Coordinator Bot',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
        ]);
        $worker = Agent::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Worker Bot',
            'provider' => 'openai',
            'model' => 'gpt-4o',
        ]);
        $crew = Crew::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'coordinator_agent_id' => $coordinator->id,
            'qa_agent_id' => $worker->id,
            'name' => 'Engineering Crew',
        ]);
        CrewMember::factory()->create([
            'crew_id' => $crew->id,
            'agent_id' => $coordinator->id,
            'role' => CrewMemberRole::Coordinator,
        ]);
        CrewMember::factory()->create([
            'crew_id' => $crew->id,
            'agent_id' => $worker->id,
            'role' => CrewMemberRole::Worker,
        ]);

        $component = Livewire::test(TeamGraphPage::class);
        $graph = $component->get('graph');

        $agentNodes = collect($graph['nodes'])->where('type', 'agent');
        $crewNodes = collect($graph['nodes'])->where('type', 'crew');
        $humanNodes = collect($graph['nodes'])->where('type', 'human');

        $this->assertCount(2, $agentNodes);
        $this->assertCount(1, $crewNodes);
        $this->assertGreaterThanOrEqual(1, $humanNodes->count(), 'Owner should appear as human node');

        $this->assertSame('anthropic', $agentNodes->firstWhere('label', 'Coordinator Bot')['vendor']);
        $this->assertSame('openai', $agentNodes->firstWhere('label', 'Worker Bot')['vendor']);

        $this->assertCount(2, $graph['edges']);
        $crewEdges = collect($graph['edges'])->where('kind', 'member');
        $this->assertSame('coordinator', $crewEdges->firstWhere('source', 'agent:'.$coordinator->id)['role']);
    }

    public function test_other_team_data_does_not_leak(): void
    {
        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other '.bin2hex(random_bytes(3)),
            'slug' => 'other-'.bin2hex(random_bytes(3)),
            'owner_id' => $otherUser->id,
            'settings' => [],
        ]);
        Agent::factory()->create([
            'team_id' => $otherTeam->id,
            'name' => 'Foreign Agent',
        ]);

        $component = Livewire::test(TeamGraphPage::class);
        $graph = $component->get('graph');

        $labels = collect($graph['nodes'])->pluck('label')->all();
        $this->assertNotContains('Foreign Agent', $labels);
    }

    public function test_open_drawer_returns_recent_activity_for_agent(): void
    {
        $agent = Agent::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Active Agent',
        ]);

        Livewire::test(TeamGraphPage::class)
            ->call('openDrawer', 'agent:'.$agent->id)
            ->assertSet('selectedNodeId', 'agent:'.$agent->id)
            ->assertSet('drawerLabel', 'Active Agent');
    }

    public function test_close_drawer_clears_state(): void
    {
        Livewire::test(TeamGraphPage::class)
            ->set('selectedNodeId', 'human:abc')
            ->set('drawerLabel', 'Someone')
            ->call('closeDrawer')
            ->assertSet('selectedNodeId', null)
            ->assertSet('drawerLabel', null)
            ->assertSet('drawerActivity', []);
    }

    public function test_route_renders_for_authenticated_user(): void
    {
        $this->get(route('team-graph'))->assertOk();
    }
}
