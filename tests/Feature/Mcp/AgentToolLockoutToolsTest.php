<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\Agent\Actions\LockToolResourceAction;
use App\Domain\Agent\Actions\ReleaseToolLockoutAction;
use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentToolLockout;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\Agent\AgentToolLockoutListTool;
use App\Mcp\Tools\Agent\AgentToolLockoutReleaseTool;
use App\Mcp\Tools\Agent\AgentToolLockoutSetTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class AgentToolLockoutToolsTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Lockout Team',
            'slug' => 'lockout-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->actingAs($user);
        app()->instance('mcp.team_id', $this->team->id);
    }

    private function decode(Response $response): array
    {
        return json_decode((string) $response->content(), true);
    }

    public function test_set_creates_a_team_wide_lockout(): void
    {
        $response = (new AgentToolLockoutSetTool)->handle(
            new Request(['resource' => 'src/auth.php', 'reason' => 'Review needed']),
            app(LockToolResourceAction::class),
        );

        $payload = $this->decode($response);
        $this->assertTrue($payload['success']);
        $this->assertSame('team_wide', $payload['scope']);
        $this->assertDatabaseHas('agent_tool_lockouts', [
            'id' => $payload['lockout_id'],
            'team_id' => $this->team->id,
            'resource' => 'src/auth.php',
        ]);
    }

    public function test_list_returns_active_only_by_default(): void
    {
        app(LockToolResourceAction::class)->execute(teamId: $this->team->id, resource: 'a.php');
        $released = app(LockToolResourceAction::class)->execute(teamId: $this->team->id, resource: 'b.php');
        app(ReleaseToolLockoutAction::class)->execute($released);

        $payload = $this->decode((new AgentToolLockoutListTool)->handle(new Request([])));

        $this->assertSame(1, $payload['count']);
        $this->assertSame('a.php', $payload['lockouts'][0]['resource']);
    }

    public function test_release_marks_lockout_released(): void
    {
        $lock = app(LockToolResourceAction::class)->execute(teamId: $this->team->id, resource: 'a.php');

        $payload = $this->decode((new AgentToolLockoutReleaseTool)->handle(
            new Request(['lockout_id' => $lock->id]),
            app(ReleaseToolLockoutAction::class),
        ));

        $this->assertTrue($payload['success']);
        $this->assertNotNull($payload['released_at']);
        $this->assertNotNull(AgentToolLockout::withoutGlobalScopes()->find($lock->id)->released_at);
    }

    public function test_release_is_tenant_isolated(): void
    {
        $otherTeam = Team::factory()->create();
        $foreign = app(LockToolResourceAction::class)->execute(teamId: $otherTeam->id, resource: 'a.php');

        $response = (new AgentToolLockoutReleaseTool)->handle(
            new Request(['lockout_id' => $foreign->id]),
            app(ReleaseToolLockoutAction::class),
        );

        $this->assertTrue($response->isError());
        // The other team's lockout is untouched.
        $this->assertNull(AgentToolLockout::withoutGlobalScopes()->find($foreign->id)->released_at);
    }

    public function test_set_rejects_agent_from_another_team(): void
    {
        $otherTeam = Team::factory()->create();
        $foreignAgent = Agent::factory()->create(['team_id' => $otherTeam->id]);

        $response = (new AgentToolLockoutSetTool)->handle(
            new Request(['resource' => 'x.php', 'agent_id' => $foreignAgent->id]),
            app(LockToolResourceAction::class),
        );

        $this->assertTrue($response->isError());
        $this->assertSame(0, AgentToolLockout::withoutGlobalScopes()->where('team_id', $this->team->id)->count());
    }
}
