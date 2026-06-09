<?php

namespace Tests\Feature\Livewire;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ReasoningBankEntry;
use App\Domain\Shared\Enums\TeamRole;
use App\Domain\Shared\Models\Team;
use App\Livewire\Experiments\ReasoningBankPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReasoningBankPageTest extends TestCase
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

    private function makeEntry(Team $team, string $goal, string $outcome): ReasoningBankEntry
    {
        $experiment = Experiment::factory()->create(['team_id' => $team->id]);

        return ReasoningBankEntry::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'experiment_id' => $experiment->id,
            'goal_text' => $goal,
            'tool_sequence' => ['bash', 'browser'],
            'outcome_summary' => $outcome,
        ]);
    }

    public function test_renders_current_team_entries(): void
    {
        $this->makeEntry($this->team, 'mine-goal-UNIQUE-7f3a', 'mine-outcome-UNIQUE-7f3a');

        Livewire::test(ReasoningBankPage::class)
            ->assertSee('mine-goal-UNIQUE-7f3a');
    }

    public function test_does_not_render_other_teams_entries(): void
    {
        $this->makeEntry($this->team, 'mine-goal-UNIQUE-aa11', 'mine-outcome-UNIQUE-aa11');

        $otherUser = User::factory()->create();
        $otherTeam = Team::factory()->create(['owner_id' => $otherUser->id]);
        $this->makeEntry($otherTeam, 'their-goal-UNIQUE-bb22', 'their-outcome-UNIQUE-bb22');

        Livewire::test(ReasoningBankPage::class)
            ->assertSee('mine-goal-UNIQUE-aa11')
            ->assertDontSee('their-goal-UNIQUE-bb22');
    }

    public function test_search_filters_by_goal_text(): void
    {
        $this->makeEntry($this->team, 'alpha-goal-UNIQUE-cc33', 'alpha-outcome');
        $this->makeEntry($this->team, 'beta-goal-UNIQUE-dd44', 'beta-outcome');

        Livewire::test(ReasoningBankPage::class)
            ->set('search', 'alpha-goal-UNIQUE-cc33')
            ->assertSee('alpha-goal-UNIQUE-cc33')
            ->assertDontSee('beta-goal-UNIQUE-dd44');
    }
}
