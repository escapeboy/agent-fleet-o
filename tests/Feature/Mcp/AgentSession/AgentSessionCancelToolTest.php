<?php

namespace Tests\Feature\Mcp\AgentSession;

use App\Domain\AgentSession\Actions\CreateAgentSessionAction;
use App\Domain\AgentSession\Enums\AgentSessionStatus;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\AgentSession\AgentSessionCancelTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class AgentSessionCancelToolTest extends TestCase
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

    public function test_cancels_active_session(): void
    {
        $session = app(CreateAgentSessionAction::class)->execute(teamId: $this->team->id);
        $session->update(['status' => AgentSessionStatus::Active]);

        $tool = app(AgentSessionCancelTool::class);
        $response = $tool->handle(new Request(['session_id' => $session->id]));

        $payload = json_decode($this->responseText($response), true);
        $this->assertSame('cancelled', $payload['status']);
        $session->refresh();
        $this->assertSame(AgentSessionStatus::Cancelled, $session->status);
    }

    public function test_idempotent_on_already_cancelled(): void
    {
        $session = app(CreateAgentSessionAction::class)->execute(teamId: $this->team->id);
        $session->update(['status' => AgentSessionStatus::Cancelled, 'ended_at' => now()]);

        $tool = app(AgentSessionCancelTool::class);
        $response = $tool->handle(new Request(['session_id' => $session->id]));

        $payload = json_decode($this->responseText($response), true);
        $this->assertSame('cancelled', $payload['status']);
    }

    public function test_cross_team_session_not_found(): void
    {
        $other = Team::create([
            'name' => 'Other',
            'slug' => 'other-'.bin2hex(random_bytes(3)),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $foreign = app(CreateAgentSessionAction::class)->execute(teamId: $other->id);

        $tool = app(AgentSessionCancelTool::class);
        $response = $tool->handle(new Request(['session_id' => $foreign->id]));

        $payload = json_decode($this->responseText($response), true);
        $this->assertSame('NOT_FOUND', $payload['error']['code']);
    }

    private function responseText(Response $response): string
    {
        return (string) $response->content();
    }
}
