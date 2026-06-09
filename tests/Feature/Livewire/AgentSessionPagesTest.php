<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Domain\AgentSession\Enums\AgentSessionStatus;
use App\Domain\AgentSession\Models\AgentSession;
use App\Domain\Shared\Models\Team;
use App\Livewire\AgentSessions\AgentSessionDetailPage;
use App\Livewire\AgentSessions\AgentSessionListPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class AgentSessionPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Agent Session Test',
            'slug' => 'agent-session-test',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);
    }

    private function makeSession(Team $team, AgentSessionStatus $status = AgentSessionStatus::Active): AgentSession
    {
        return AgentSession::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'status' => $status,
            'started_at' => now(),
            'last_heartbeat_at' => now(),
        ]);
    }

    public function test_list_shows_only_current_team_sessions(): void
    {
        $mine = $this->makeSession($this->team);

        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other Team',
            'slug' => 'other-agent-session-team',
            'owner_id' => $otherUser->id,
            'settings' => [],
        ]);
        $theirs = $this->makeSession($otherTeam);

        Livewire::test(AgentSessionListPage::class)
            ->assertSee($mine->id)
            ->assertDontSee($theirs->id);
    }

    public function test_unauthorized_wake_aborts(): void
    {
        $session = $this->makeSession($this->team, AgentSessionStatus::Sleeping);

        // Deny the per-action gate to prove wake() authorizes on the action,
        // not only on mount(). Livewire renders the AuthorizationException as a 403.
        Gate::define('edit-content', fn () => false);

        Livewire::test(AgentSessionDetailPage::class, ['agentSession' => $session->id])
            ->call('wake')
            ->assertForbidden();

        $session->refresh();
        $this->assertSame(AgentSessionStatus::Sleeping, $session->status);
    }
}
