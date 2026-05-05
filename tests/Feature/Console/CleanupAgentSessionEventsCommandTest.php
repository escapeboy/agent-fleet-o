<?php

namespace Tests\Feature\Console;

use App\Domain\AgentSession\Actions\AppendSessionEventAction;
use App\Domain\AgentSession\Actions\CreateAgentSessionAction;
use App\Domain\AgentSession\Enums\AgentSessionEventKind;
use App\Domain\AgentSession\Enums\AgentSessionStatus;
use App\Domain\AgentSession\Models\AgentSession;
use App\Domain\AgentSession\Models\AgentSessionEvent;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CleanupAgentSessionEventsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_events_older_than_retention(): void
    {
        $team = Team::factory()->create();
        $session = app(CreateAgentSessionAction::class)->execute(teamId: $team->id);
        $append = app(AppendSessionEventAction::class);

        $stale = $append->execute($session, AgentSessionEventKind::Note, ['old' => true]);
        $stale->forceFill(['created_at' => now()->subDays(120)])->save();

        $append->execute($session, AgentSessionEventKind::Note, ['recent' => true]);

        $this->artisan('agent-session-events:cleanup', ['--days' => 90])->assertSuccessful();

        $remaining = AgentSessionEvent::all();
        $this->assertCount(1, $remaining);
        $this->assertNotSame($stale->id, $remaining->first()->id);
    }

    public function test_dry_run_does_not_delete(): void
    {
        $team = Team::factory()->create();
        $session = app(CreateAgentSessionAction::class)->execute(teamId: $team->id);
        $event = app(AppendSessionEventAction::class)
            ->execute($session, AgentSessionEventKind::Note, ['stale' => true]);
        $event->forceFill(['created_at' => now()->subDays(200)])->save();

        $this->artisan('agent-session-events:cleanup', ['--days' => 90, '--dry-run' => true])
            ->expectsOutputToContain('[dry-run]')
            ->assertSuccessful();

        $this->assertSame(1, AgentSessionEvent::count());
    }

    public function test_orphan_terminal_sessions_are_pruned(): void
    {
        $team = Team::factory()->create();
        $session = app(CreateAgentSessionAction::class)->execute(teamId: $team->id);
        $session->update([
            'status' => AgentSessionStatus::Cancelled,
            'ended_at' => now()->subDays(120),
        ]);

        $this->assertSame(1, AgentSession::count());
        $this->artisan('agent-session-events:cleanup', ['--days' => 90])->assertSuccessful();
        $this->assertSame(0, AgentSession::count());
    }

    public function test_active_session_is_preserved(): void
    {
        $team = Team::factory()->create();
        $session = app(CreateAgentSessionAction::class)->execute(teamId: $team->id);
        $session->update(['status' => AgentSessionStatus::Active]);

        $this->artisan('agent-session-events:cleanup', ['--days' => 90])->assertSuccessful();
        $this->assertSame(1, AgentSession::count());
    }

    public function test_invalid_days_flag_fails(): void
    {
        $this->artisan('agent-session-events:cleanup', ['--days' => 0])->assertFailed();
    }
}
