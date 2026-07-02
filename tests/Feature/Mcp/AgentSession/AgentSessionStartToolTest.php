<?php

namespace Tests\Feature\Mcp\AgentSession;

use App\Domain\Agent\Models\Agent;
use App\Domain\AgentSession\Enums\AgentSessionStatus;
use App\Domain\AgentSession\Models\AgentSession;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\ExperimentTrack;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\AgentSession\AgentSessionStartTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class AgentSessionStartToolTest extends TestCase
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

    private function text(Response $response): string
    {
        return (string) $response->content();
    }

    public function test_starts_active_session_with_no_args(): void
    {
        $tool = app(AgentSessionStartTool::class);
        $response = $tool->handle(new Request([]));

        $payload = json_decode($this->text($response), true);
        $this->assertSame('active', $payload['status']);
        $this->assertNotNull($payload['id']);

        $session = AgentSession::withoutGlobalScopes()->find($payload['id']);
        $this->assertNotNull($session);
        $this->assertSame($this->team->id, $session->team_id);
        $this->assertSame(AgentSessionStatus::Active, $session->status);
        $this->assertNotNull($session->started_at);
    }

    public function test_persists_agent_and_metadata(): void
    {
        $agent = Agent::factory()->create(['team_id' => $this->team->id]);

        $tool = app(AgentSessionStartTool::class);
        $response = $tool->handle(new Request([
            'agent_id' => $agent->id,
            'metadata' => ['purpose' => 'standalone'],
        ]));

        $payload = json_decode($this->text($response), true);
        $session = AgentSession::withoutGlobalScopes()->find($payload['id']);
        $this->assertSame($agent->id, $session->agent_id);
        $this->assertSame('standalone', $session->metadata['purpose'] ?? null);
        $this->assertSame($this->user->id, $session->user_id);
    }

    public function test_rejects_cross_tenant_experiment_id(): void
    {
        $other = Team::create([
            'name' => 'Other',
            'slug' => 'other-'.bin2hex(random_bytes(3)),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $foreignExperiment = Experiment::factory()->create([
            'team_id' => $other->id,
            'track' => ExperimentTrack::Workflow,
            'status' => ExperimentStatus::Draft,
            'constraints' => [],
            'title' => 'x',
        ]);

        $tool = app(AgentSessionStartTool::class);
        $response = $tool->handle(new Request(['experiment_id' => $foreignExperiment->id]));

        $payload = json_decode($this->text($response), true);
        $this->assertSame('NOT_FOUND', $payload['error']['code']);
        $this->assertCount(0, AgentSession::withoutGlobalScopes()->where('team_id', $this->team->id)->get());
    }

    public function test_permission_denied_without_team_context(): void
    {
        app()->forgetInstance('mcp.team_id');
        app()->instance('mcp.team_id', null);
        $this->user->update(['current_team_id' => null]);

        $tool = app(AgentSessionStartTool::class);
        $response = $tool->handle(new Request([]));

        $payload = json_decode($this->text($response), true);
        $this->assertArrayHasKey('error', $payload);
    }
}
