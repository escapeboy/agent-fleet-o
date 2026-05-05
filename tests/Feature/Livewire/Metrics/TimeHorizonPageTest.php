<?php

namespace Tests\Feature\Livewire\Metrics;

use App\Domain\AgentSession\Actions\AppendSessionEventAction;
use App\Domain\AgentSession\Actions\CreateAgentSessionAction;
use App\Domain\AgentSession\Enums\AgentSessionEventKind;
use App\Domain\AgentSession\Enums\AgentSessionStatus;
use App\Domain\Shared\Models\Team;
use App\Livewire\Metrics\TimeHorizonPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TimeHorizonPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->team = Team::factory()->create(['owner_id' => $this->user->id]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);
    }

    public function test_renders_with_no_data(): void
    {
        Livewire::test(TimeHorizonPage::class)
            ->assertSee('No agent sessions in this window');
    }

    public function test_aggregates_sessions_and_events(): void
    {
        $session = app(CreateAgentSessionAction::class)->execute(teamId: $this->team->id);
        $session->update([
            'status' => AgentSessionStatus::Completed,
            'started_at' => now()->subMinutes(2),
            'ended_at' => now(),
        ]);
        app(AppendSessionEventAction::class)
            ->execute($session, AgentSessionEventKind::LlmCall, ['tokens_total' => 100, 'cost_usd' => 0.001]);
        app(AppendSessionEventAction::class)
            ->execute($session, AgentSessionEventKind::ToolCall, ['tool' => 'bash']);

        Livewire::test(TimeHorizonPage::class)
            ->assertSee('Total sessions')
            ->assertSeeText('1');
    }

    public function test_window_change_persists(): void
    {
        Livewire::test(TimeHorizonPage::class)
            ->call('setWindow', '30d')
            ->assertSet('window', '30d');
    }

    public function test_invalid_window_falls_back_to_default(): void
    {
        Livewire::test(TimeHorizonPage::class)
            ->call('setWindow', 'foo')
            ->assertSet('window', '7d');
    }
}
