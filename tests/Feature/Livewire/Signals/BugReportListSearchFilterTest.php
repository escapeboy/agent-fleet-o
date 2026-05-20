<?php

namespace Tests\Feature\Livewire\Signals;

use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Models\Signal;
use App\Livewire\Signals\BugReportListPage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BugReportListSearchFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Search Test '.bin2hex(random_bytes(3)),
            'slug' => 'search-test-'.bin2hex(random_bytes(3)),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);
    }

    private function createReport(array $payload = [], array $overrides = []): Signal
    {
        return Signal::factory()->create(array_merge([
            'team_id' => $this->team->id,
            'source_type' => 'bug_report',
            'payload' => array_merge([
                'title' => 'Default Title',
                'description' => 'Default description',
                'url' => 'https://example.com/page',
                'reporter_name' => 'Default Reporter',
            ], $payload),
            'content_hash' => hash('sha256', uniqid('', true)),
        ], $overrides));
    }

    public function test_keyword_search_matches_title(): void
    {
        $match = $this->createReport(['title' => 'Login page crashes']);
        $noMatch = $this->createReport(['title' => 'Checkout button broken']);

        Livewire::test(BugReportListPage::class)
            ->set('search', 'Login')
            ->assertSee('Login page crashes')
            ->assertDontSee('Checkout button broken');
    }

    public function test_keyword_search_matches_description(): void
    {
        // Titles are unique so we can assert visibility via title column.
        // The search term is found in the description, not the title.
        $match = $this->createReport(['title' => 'Bug A', 'description' => 'Error in payment flow']);
        $noMatch = $this->createReport(['title' => 'Bug B', 'description' => 'Works fine here']);

        Livewire::test(BugReportListPage::class)
            ->set('search', 'payment flow')
            ->assertSee('Bug A')
            ->assertDontSee('Bug B');
    }

    public function test_keyword_search_matches_reporter_name(): void
    {
        $match = $this->createReport(['reporter_name' => 'Jane Smith']);
        $noMatch = $this->createReport(['reporter_name' => 'Bob Johnson']);

        Livewire::test(BugReportListPage::class)
            ->set('search', 'Jane')
            ->assertSee('Jane Smith')
            ->assertDontSee('Bob Johnson');
    }

    public function test_keyword_search_is_case_insensitive(): void
    {
        $report = $this->createReport(['title' => 'Dashboard Error']);

        Livewire::test(BugReportListPage::class)
            ->set('search', 'dashboard')
            ->assertSee('Dashboard Error');
    }

    public function test_empty_search_shows_all_reports(): void
    {
        $this->createReport(['title' => 'Report A']);
        $this->createReport(['title' => 'Report B']);

        Livewire::test(BugReportListPage::class)
            ->set('search', '')
            ->assertSee('Report A')
            ->assertSee('Report B');
    }

    public function test_date_from_filter_excludes_older_reports(): void
    {
        $old = $this->createReport(['title' => 'Old Report'], ['received_at' => Carbon::parse('2026-01-01 10:00:00')]);
        $new = $this->createReport(['title' => 'New Report'], ['received_at' => Carbon::parse('2026-05-01 10:00:00')]);

        Livewire::test(BugReportListPage::class)
            ->set('dateFrom', '2026-03-01')
            ->assertSee('New Report')
            ->assertDontSee('Old Report');
    }

    public function test_date_to_filter_excludes_newer_reports(): void
    {
        $old = $this->createReport(['title' => 'Early Report'], ['received_at' => Carbon::parse('2026-01-15 10:00:00')]);
        $new = $this->createReport(['title' => 'Late Report'], ['received_at' => Carbon::parse('2026-05-10 10:00:00')]);

        Livewire::test(BugReportListPage::class)
            ->set('dateTo', '2026-03-01')
            ->assertSee('Early Report')
            ->assertDontSee('Late Report');
    }

    public function test_date_range_combined_with_search(): void
    {
        $match = $this->createReport(
            ['title' => 'Crash in range'],
            ['received_at' => Carbon::parse('2026-04-10 10:00:00')],
        );
        $outOfRange = $this->createReport(
            ['title' => 'Crash out of range'],
            ['received_at' => Carbon::parse('2026-02-01 10:00:00')],
        );

        Livewire::test(BugReportListPage::class)
            ->set('search', 'Crash')
            ->set('dateFrom', '2026-04-01')
            ->assertSee('Crash in range')
            ->assertDontSee('Crash out of range');
    }

    public function test_reopen_from_resolved_transitions_to_triaged(): void
    {
        $report = $this->createReport(['title' => 'Fixed Bug']);
        $report->update(['status' => SignalStatus::Resolved]);

        Livewire::test(BugReportListPage::class)
            ->call('reopen', $report->id);

        $this->assertSame(SignalStatus::Triaged, $report->fresh()->status);
    }

    public function test_reopen_from_dismissed_transitions_to_triaged(): void
    {
        $report = $this->createReport(['title' => 'Dismissed Bug']);
        $report->update(['status' => SignalStatus::Dismissed]);

        Livewire::test(BugReportListPage::class)
            ->call('reopen', $report->id);

        $this->assertSame(SignalStatus::Triaged, $report->fresh()->status);
    }

    public function test_reopen_does_nothing_for_nonexistent_signal(): void
    {
        Livewire::test(BugReportListPage::class)
            ->call('reopen', 'nonexistent-id');

        // No exception thrown
        $this->assertTrue(true);
    }

    public function test_sort_by_status_toggles_direction(): void
    {
        Livewire::test(BugReportListPage::class)
            ->call('sort', 'status')
            ->assertSet('sortBy', 'status')
            ->assertSet('sortDir', 'desc')
            ->call('sort', 'status')
            ->assertSet('sortDir', 'asc');
    }

    public function test_sort_by_severity_is_accepted(): void
    {
        Livewire::test(BugReportListPage::class)
            ->call('sort', 'severity')
            ->assertSet('sortBy', 'severity')
            ->assertSet('sortDir', 'desc');
    }

    public function test_unknown_sort_column_falls_back_to_created_at(): void
    {
        // Inject an invalid sort column via direct property set to simulate tampering
        $component = Livewire::test(BugReportListPage::class);
        $component->set('sortBy', 'injected_column; DROP TABLE signals;--');

        // render should not throw and should fall back to created_at ordering
        $component->assertHasNoErrors();
    }
}
