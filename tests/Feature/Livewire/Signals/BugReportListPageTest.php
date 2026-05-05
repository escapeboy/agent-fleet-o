<?php

namespace Tests\Feature\Livewire\Signals;

use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Models\Signal;
use App\Livewire\Signals\BugReportListPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BugReportListPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test '.bin2hex(random_bytes(3)),
            'slug' => 'test-'.bin2hex(random_bytes(3)),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);

        $this->actingAs($this->user);
    }

    public function test_delete_removes_bug_report(): void
    {
        $report = Signal::factory()->create([
            'team_id' => $this->team->id,
            'source_type' => 'bug_report',
            'payload' => ['title' => 'Test Bug'],
        ]);

        Livewire::test(BugReportListPage::class)
            ->call('delete', $report->id);

        $this->assertDatabaseMissing('signals', ['id' => $report->id]);
    }

    public function test_delete_cannot_delete_other_teams_report(): void
    {
        $otherTeam = Team::create([
            'name' => 'Other '.bin2hex(random_bytes(3)),
            'slug' => 'other-'.bin2hex(random_bytes(3)),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);

        $report = Signal::factory()->create([
            'team_id' => $otherTeam->id,
            'source_type' => 'bug_report',
            'payload' => ['title' => 'Other Team Bug'],
        ]);

        Livewire::test(BugReportListPage::class)
            ->call('delete', $report->id);

        $this->assertDatabaseHas('signals', ['id' => $report->id]);
    }

    public function test_delete_cannot_delete_non_bug_report_signals(): void
    {
        $signal = Signal::factory()->create([
            'team_id' => $this->team->id,
            'source_type' => 'webhook',
            'payload' => ['data' => 'something'],
        ]);

        Livewire::test(BugReportListPage::class)
            ->call('delete', $signal->id);

        $this->assertDatabaseHas('signals', ['id' => $signal->id]);
    }
}
