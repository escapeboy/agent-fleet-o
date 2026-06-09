<?php

namespace Tests\Feature\Livewire;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\TeamRole;
use App\Domain\Shared\Models\Team;
use App\Domain\Testing\Enums\TestStatus;
use App\Domain\Testing\Enums\TestStrategy;
use App\Domain\Testing\Models\TestRun;
use App\Domain\Testing\Models\TestSuite;
use App\Livewire\Testing\TestSuiteDetailPage;
use App\Livewire\Testing\TestSuitesPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class TestSuitesPageTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::factory()->create(['owner_id' => $this->user->id]);
        $this->team->users()->attach($this->user, ['role' => TeamRole::Owner->value]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->actingAs($this->user);
    }

    private function makeSuite(Team $team, string $name): TestSuite
    {
        $project = Project::factory()->create(['team_id' => $team->id]);

        return TestSuite::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'project_id' => $project->id,
            'name' => $name,
            'test_strategy' => TestStrategy::Regression,
        ]);
    }

    public function test_list_only_shows_current_team_suites(): void
    {
        $mineName = 'Mine '.Str::random(8);
        $theirsName = 'Theirs '.Str::random(8);

        $this->makeSuite($this->team, $mineName);

        $otherUser = User::factory()->create();
        $otherTeam = Team::factory()->create(['owner_id' => $otherUser->id]);
        $this->makeSuite($otherTeam, $theirsName);

        Livewire::test(TestSuitesPage::class)
            ->assertSee($mineName)
            ->assertDontSee($theirsName);
    }

    public function test_detail_page_blocks_cross_tenant_suite(): void
    {
        $otherUser = User::factory()->create();
        $otherTeam = Team::factory()->create(['owner_id' => $otherUser->id]);
        $foreignSuite = $this->makeSuite($otherTeam, 'Foreign '.Str::random(8));

        Livewire::test(TestSuiteDetailPage::class, ['suite' => $foreignSuite->id])
            ->assertStatus(404);
    }

    public function test_detail_page_shows_runs_for_owned_suite(): void
    {
        $suite = $this->makeSuite($this->team, 'Owned '.Str::random(8));

        $experiment = Experiment::factory()->create(['team_id' => $this->team->id]);

        $run = TestRun::create([
            'test_suite_id' => $suite->id,
            'experiment_id' => $experiment->id,
            'status' => TestStatus::Passed,
            'score' => 0.91,
            'started_at' => now(),
        ]);

        Livewire::test(TestSuiteDetailPage::class, ['suite' => $suite->id])
            ->assertOk()
            ->assertSee('Passed');

        $this->assertSame($suite->id, $run->fresh()->test_suite_id);
    }
}
