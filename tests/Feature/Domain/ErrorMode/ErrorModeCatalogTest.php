<?php

namespace Tests\Feature\Domain\ErrorMode;

use App\Domain\ErrorMode\Actions\AssignErrorModeLeverAction;
use App\Domain\ErrorMode\Actions\RecordErrorModeOccurrenceAction;
use App\Domain\ErrorMode\Enums\ErrorModeLever;
use App\Domain\ErrorMode\Enums\ErrorModeStatus;
use App\Domain\ErrorMode\Models\ErrorMode;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErrorModeCatalogTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->team = Team::create(['name' => 'EM', 'slug' => 'em-'.uniqid(), 'owner_id' => $user->id, 'settings' => []]);
    }

    public function test_record_creates_then_increments_same_mode(): void
    {
        $action = app(RecordErrorModeOccurrenceAction::class);

        $first = $action->execute($this->team->id, 'Hallucinated citation', 'trace-1');
        $this->assertSame(1, $first->occurrence_count);
        $this->assertNotNull($first->first_seen_at);

        $second = $action->execute($this->team->id, 'Hallucinated citation', 'trace-2');
        $this->assertSame($first->id, $second->id);
        $this->assertSame(2, $second->occurrence_count);
        $this->assertSame(1, ErrorMode::where('team_id', $this->team->id)->count());
    }

    public function test_trace_ids_dedup_and_cap_at_50(): void
    {
        $action = app(RecordErrorModeOccurrenceAction::class);
        // Same trace twice → stored once.
        $action->execute($this->team->id, 'Wrong tool', 'dup');
        $mode = $action->execute($this->team->id, 'Wrong tool', 'dup');
        $this->assertSame(['dup'], $mode->example_trace_ids);

        for ($i = 0; $i < 60; $i++) {
            $mode = $action->execute($this->team->id, 'Wrong tool', "t{$i}");
        }
        $this->assertLessThanOrEqual(50, count($mode->example_trace_ids));
    }

    public function test_assign_lever_and_status(): void
    {
        $mode = app(RecordErrorModeOccurrenceAction::class)->execute($this->team->id, 'Missed retrieval');

        $updated = app(AssignErrorModeLeverAction::class)->execute(
            teamId: $this->team->id,
            errorModeId: $mode->id,
            lever: ErrorModeLever::DataPrep,
            status: ErrorModeStatus::Mitigated,
        );

        $this->assertSame(ErrorModeLever::DataPrep, $updated->lever);
        $this->assertSame(ErrorModeStatus::Mitigated, $updated->status);
    }

    public function test_assign_lever_rejects_cross_team(): void
    {
        $otherUser = User::factory()->create();
        $otherTeam = Team::create(['name' => 'O', 'slug' => 'o-'.uniqid(), 'owner_id' => $otherUser->id, 'settings' => []]);
        $mode = app(RecordErrorModeOccurrenceAction::class)->execute($otherTeam->id, 'X');

        $this->expectException(ModelNotFoundException::class);
        app(AssignErrorModeLeverAction::class)->execute($this->team->id, $mode->id, ErrorModeLever::Prompt);
    }

    public function test_team_isolation(): void
    {
        $otherUser = User::factory()->create();
        $otherTeam = Team::create(['name' => 'O2', 'slug' => 'o2-'.uniqid(), 'owner_id' => $otherUser->id, 'settings' => []]);

        app(RecordErrorModeOccurrenceAction::class)->execute($this->team->id, 'Mine');
        app(RecordErrorModeOccurrenceAction::class)->execute($otherTeam->id, 'Theirs');

        $this->assertSame(1, ErrorMode::where('team_id', $this->team->id)->count());
        $this->assertSame('Mine', ErrorMode::where('team_id', $this->team->id)->first()->name);
    }
}
