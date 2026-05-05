<?php

namespace Tests\Feature\Mcp\Tools;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewMember;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\Shared\TeamGraphGetTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class TeamGraphGetToolTest extends TestCase
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

        $this->actingAs($this->user);
        app()->instance('mcp.team_id', $this->team->id);
    }

    public function test_returns_team_graph_payload(): void
    {
        $coord = Agent::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Lead',
            'provider' => 'anthropic',
        ]);
        $worker = Agent::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Hand',
            'provider' => 'openai',
        ]);
        $crew = Crew::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'coordinator_agent_id' => $coord->id,
            'qa_agent_id' => $worker->id,
            'name' => 'Sprint Crew',
        ]);
        CrewMember::factory()->create([
            'crew_id' => $crew->id,
            'agent_id' => $coord->id,
        ]);

        $tool = app(TeamGraphGetTool::class);
        $response = $tool->handle(new Request([]));

        $payload = json_decode($this->responseText($response), true);
        $this->assertSame($this->team->id, $payload['team_id']);
        $this->assertGreaterThanOrEqual(2, $payload['counts']['agents']);
        $this->assertGreaterThanOrEqual(1, $payload['counts']['crews']);
        $this->assertCount(1, collect($payload['edges'])->where('kind', 'member'));

        $agentNodes = collect($payload['nodes'])->where('type', 'agent');
        $this->assertSame('anthropic', $agentNodes->firstWhere('label', 'Lead')['vendor']);
    }

    public function test_does_not_leak_other_team_agents(): void
    {
        $other = Team::create([
            'name' => 'Other '.bin2hex(random_bytes(3)),
            'slug' => 'other-'.bin2hex(random_bytes(3)),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        Agent::factory()->create([
            'team_id' => $other->id,
            'name' => 'Stranger Bot',
        ]);
        Agent::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'My Bot',
        ]);

        $tool = app(TeamGraphGetTool::class);
        $payload = json_decode($this->responseText($tool->handle(new Request([]))), true);

        $labels = collect($payload['nodes'])->pluck('label')->all();
        $this->assertContains('My Bot', $labels);
        $this->assertNotContains('Stranger Bot', $labels);
    }

    private function responseText(Response $response): string
    {
        return (string) $response->content();
    }
}
