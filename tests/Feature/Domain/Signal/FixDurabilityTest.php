<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Models\Signal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FixDurabilityTest extends TestCase
{
    use RefreshDatabase;

    private function ingest(Team $team, string $nativeId, string $title): ?Signal
    {
        return app(IngestSignalAction::class)->execute(
            sourceType: 'sentry',
            sourceIdentifier: 'fleetq',
            payload: ['title' => $title],
            sourceNativeId: $nativeId,
            teamId: $team->id,
        );
    }

    public function test_recurrence_of_resolved_signal_reopens_and_records_durability_failure(): void
    {
        Queue::fake();
        $team = Team::factory()->create();

        $signal = $this->ingest($team, 'ISSUE-1', 'NPE in checkout');
        $signal->update(['status' => SignalStatus::Resolved]); // fix shipped

        // Same upstream issue fires again — the fix did not survive.
        $again = $this->ingest($team, 'ISSUE-1', 'NPE in checkout (again)');

        $this->assertSame($signal->id, $again->id, 'recurrence should merge into the existing signal');

        $signal->refresh();
        $this->assertSame(1, $signal->recurrence_count);
        $this->assertSame(SignalStatus::Triaged, $signal->status, 'signal should be reopened');
        $this->assertFalse($signal->metadata['fix_durability']['durable']);
        $this->assertEqualsWithDelta(0.5, $signal->metadata['remediation_confidence'], 1e-9);
    }

    public function test_duplicate_of_unresolved_signal_does_not_trigger_recurrence(): void
    {
        Queue::fake();
        $team = Team::factory()->create();

        $signal = $this->ingest($team, 'ISSUE-2', 'Timeout calling API');
        // Still Received (no fix yet) — a duplicate is just noise, not a recurrence.
        $this->ingest($team, 'ISSUE-2', 'Timeout calling API again');

        $signal->refresh();
        $this->assertSame(0, $signal->recurrence_count);
        $this->assertSame(SignalStatus::Received, $signal->status);
        $this->assertArrayNotHasKey('fix_durability', $signal->metadata ?? []);
        $this->assertSame(1, $signal->duplicate_count ?? 0);
    }

    public function test_repeated_recurrence_compounds_and_lowers_confidence(): void
    {
        Queue::fake();
        $team = Team::factory()->create();

        $signal = $this->ingest($team, 'ISSUE-3', 'Deadlock');
        $signal->update(['status' => SignalStatus::Resolved]);
        $this->ingest($team, 'ISSUE-3', 'Deadlock again'); // recurrence #1

        $signal->refresh();
        $signal->update(['status' => SignalStatus::Resolved]); // re-fixed
        $this->ingest($team, 'ISSUE-3', 'Deadlock yet again'); // recurrence #2

        $signal->refresh();
        $this->assertSame(2, $signal->recurrence_count);
        $this->assertEqualsWithDelta(0.25, $signal->metadata['remediation_confidence'], 1e-9);
    }
}
