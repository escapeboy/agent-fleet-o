<?php

namespace Tests\Feature\Domain\AgentSession;

use App\Domain\Agent\Models\Agent;
use App\Domain\AgentSession\Actions\AppendSessionEventAction;
use App\Domain\AgentSession\Actions\CreateAgentSessionAction;
use App\Domain\AgentSession\Actions\ForkAgentSessionAction;
use App\Domain\AgentSession\Enums\AgentSessionEventKind;
use App\Domain\AgentSession\Enums\AgentSessionStatus;
use App\Domain\AgentSession\Models\AgentSession;
use App\Domain\AgentSession\Models\AgentSessionEvent;
use App\Domain\Shared\Models\Team;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ForkAgentSessionActionTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
        $this->agent = Agent::factory()->for($this->team)->create();
    }

    private function sessionWithEvents(int $count, ?array $snapshot = null): AgentSession
    {
        $session = app(CreateAgentSessionAction::class)->execute(
            teamId: $this->team->id,
            agentId: $this->agent->id,
        );
        $session->update([
            'status' => AgentSessionStatus::Active,
            'workspace_contract_snapshot' => $snapshot,
        ]);
        $session->refresh();

        $append = app(AppendSessionEventAction::class);
        for ($i = 1; $i <= $count; $i++) {
            $append->execute(
                session: $session,
                kind: AgentSessionEventKind::Note,
                payload: ['step' => $i],
            );
        }

        return $session->refresh();
    }

    public function test_fork_copies_the_slice_preserving_seq_kind_and_payload(): void
    {
        $source = $this->sessionWithEvents(10);

        $result = app(ForkAgentSessionAction::class)->execute($source, atSeq: 4);

        $this->assertSame(4, $result['forked_at_seq']);
        $this->assertSame(4, $result['copied_events']);

        $childEvents = AgentSessionEvent::where('session_id', $result['child']->id)
            ->orderBy('seq')->get();

        $this->assertCount(4, $childEvents);
        $this->assertSame([1, 2, 3, 4], $childEvents->pluck('seq')->all());
        $this->assertSame(
            [['step' => 1], ['step' => 2], ['step' => 3], ['step' => 4]],
            $childEvents->pluck('payload')->all(),
        );
        $this->assertTrue($childEvents->every(fn ($e) => $e->kind === AgentSessionEventKind::Note));
    }

    public function test_fork_without_at_seq_defaults_to_the_last_event(): void
    {
        $source = $this->sessionWithEvents(6);

        $result = app(ForkAgentSessionAction::class)->execute($source);

        $this->assertSame(6, $result['forked_at_seq']);
        $this->assertSame(6, AgentSessionEvent::where('session_id', $result['child']->id)->count());
    }

    public function test_source_keeps_its_status_and_gains_exactly_one_fork_event(): void
    {
        $source = $this->sessionWithEvents(5);

        $result = app(ForkAgentSessionAction::class)->execute($source, atSeq: 3);

        $this->assertSame(AgentSessionStatus::Active, $result['source']->status);
        $this->assertNull($result['source']->ended_at);

        $forkEvents = AgentSessionEvent::where('session_id', $source->id)
            ->where('kind', AgentSessionEventKind::Fork->value)->get();

        $this->assertCount(1, $forkEvents);
        // Appended after the copy, so it sits past the original 5 events and is
        // never inside the child's slice.
        $this->assertSame(6, $forkEvents->first()->seq);
        $this->assertSame($result['child']->id, $forkEvents->first()->payload['child_session_id']);
        $this->assertSame(3, $forkEvents->first()->payload['forked_at_seq']);
    }

    public function test_child_records_lineage_and_starts_pending(): void
    {
        $source = $this->sessionWithEvents(3, ['agents_md' => '# Agents']);

        $result = app(ForkAgentSessionAction::class)->execute($source, atSeq: 2, note: 'try plan B');
        $child = $result['child'];

        $this->assertSame($source->id, $child->parent_session_id);
        $this->assertSame(2, $child->forked_at_seq);
        $this->assertSame(AgentSessionStatus::Pending, $child->status);
        $this->assertSame($this->agent->id, $child->agent_id);
        $this->assertSame($this->team->id, $child->team_id);
        $this->assertSame(['agents_md' => '# Agents'], $child->workspace_contract_snapshot);
        $this->assertSame('try plan B', $child->metadata['fork']['note']);
    }

    public function test_copied_events_carry_the_team_id(): void
    {
        // The copy uses a raw insert, which fires no model events and so never
        // reaches TeamScope. Guard that team_id is set explicitly.
        $source = $this->sessionWithEvents(3);

        $result = app(ForkAgentSessionAction::class)->execute($source, atSeq: 3);

        $rows = DB::table('agent_session_events')
            ->where('session_id', $result['child']->id)->get();

        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame($this->team->id, $row->team_id);
        }
    }

    public function test_copies_are_stamped_now_not_with_the_parents_timestamps(): void
    {
        $source = $this->sessionWithEvents(3);
        DB::table('agent_session_events')
            ->where('session_id', $source->id)
            ->update(['created_at' => now()->subDays(200)]);

        $result = app(ForkAgentSessionAction::class)->execute($source->refresh(), atSeq: 3);

        $stamps = DB::table('agent_session_events')
            ->where('session_id', $result['child']->id)
            ->pluck('created_at');

        $this->assertCount(3, $stamps);
        foreach ($stamps as $stamp) {
            $this->assertTrue(
                Carbon::parse($stamp)->greaterThan(now()->subMinute()),
                'Copied events must carry the fork\'s own clock, not the parent\'s.',
            );
        }
    }

    public function test_retention_cleanup_does_not_empty_a_fresh_fork_of_an_old_session(): void
    {
        // Regression guard. CleanupAgentSessionEvents deletes every event row
        // older than the retention window, globally and by created_at. Copying
        // the parent's timestamps handed the child an already-expired history,
        // so the next nightly run emptied it — destroying the very thing the
        // copy-instead-of-pointer design exists to protect.
        $source = $this->sessionWithEvents(4);
        DB::table('agent_session_events')
            ->where('session_id', $source->id)
            ->update(['created_at' => now()->subDays(200)]);

        $child = app(ForkAgentSessionAction::class)->execute($source->refresh(), atSeq: 4)['child'];

        $this->artisan('agent-session-events:cleanup', ['--days' => 90])->assertSuccessful();

        // Only the fork marker survives on the parent: it was written now, the
        // four original events were aged past the window.
        $remaining = AgentSessionEvent::where('session_id', $source->id)->get();
        $this->assertCount(1, $remaining);
        $this->assertSame(AgentSessionEventKind::Fork, $remaining->first()->kind);
        $this->assertSame(4, AgentSessionEvent::where('session_id', $child->id)->count(),
            'The fork\'s copied history must survive the cleanup that prunes its parent.');
    }

    public function test_a_terminal_session_can_be_forked(): void
    {
        $source = $this->sessionWithEvents(4);
        $source->update(['status' => AgentSessionStatus::Completed, 'ended_at' => now()]);

        $result = app(ForkAgentSessionAction::class)->execute($source->refresh(), atSeq: 4);

        $this->assertSame(AgentSessionStatus::Completed, $result['source']->status);
        $this->assertSame(4, $result['copied_events']);
    }

    public function test_a_fork_can_itself_be_forked(): void
    {
        $source = $this->sessionWithEvents(5);
        $child = app(ForkAgentSessionAction::class)->execute($source, atSeq: 5)['child'];

        $grandchild = app(ForkAgentSessionAction::class)->execute($child, atSeq: 2)['child'];

        $this->assertSame($child->id, $grandchild->parent_session_id);
        $this->assertSame(2, $grandchild->forked_at_seq);
        $this->assertSame(2, AgentSessionEvent::where('session_id', $grandchild->id)->count());
    }

    public function test_child_survives_deletion_of_its_parent(): void
    {
        $source = $this->sessionWithEvents(4);
        $child = app(ForkAgentSessionAction::class)->execute($source, atSeq: 4)['child'];

        $source->delete();

        $child->refresh();
        $this->assertNull($child->parent_session_id);
        $this->assertSame(4, $child->forked_at_seq);
        $this->assertSame(4, AgentSessionEvent::where('session_id', $child->id)->count());
    }

    public function test_forking_a_session_with_no_events_fails(): void
    {
        $session = app(CreateAgentSessionAction::class)->execute(
            teamId: $this->team->id,
            agentId: $this->agent->id,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no events');

        app(ForkAgentSessionAction::class)->execute($session);
    }

    public function test_at_seq_below_one_is_rejected(): void
    {
        $source = $this->sessionWithEvents(3);

        $this->expectException(\InvalidArgumentException::class);

        app(ForkAgentSessionAction::class)->execute($source, atSeq: 0);
    }

    public function test_at_seq_beyond_the_last_event_is_rejected(): void
    {
        $source = $this->sessionWithEvents(3);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('beyond');

        app(ForkAgentSessionAction::class)->execute($source, atSeq: 99);
    }

    public function test_a_slice_over_the_cap_is_refused(): void
    {
        $source = $this->sessionWithEvents(1);

        $over = ForkAgentSessionAction::MAX_FORK_EVENTS + 1;
        $rows = [];
        $prototype = new AgentSessionEvent;
        for ($seq = 2; $seq <= $over; $seq++) {
            $rows[] = [
                'id' => $prototype->newUniqueId(),
                'team_id' => $this->team->id,
                'session_id' => $source->id,
                'seq' => $seq,
                'kind' => AgentSessionEventKind::Note->value,
                'payload' => null,
                'created_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('agent_session_events')->insert($chunk);
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage((string) ForkAgentSessionAction::MAX_FORK_EVENTS);

        app(ForkAgentSessionAction::class)->execute($source->refresh());
    }
}
