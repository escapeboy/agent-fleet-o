<?php

namespace Tests\Feature\Livewire;

use App\Domain\ErrorMode\Enums\ErrorModeLever;
use App\Domain\ErrorMode\Models\ErrorMode;
use App\Domain\Shared\Enums\TeamRole;
use App\Domain\Shared\Models\Team;
use App\Livewire\ErrorModes\ErrorModeCatalogPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class ErrorModeCatalogPageTest extends TestCase
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

    private function makeMode(string $teamId, string $name, array $attributes = []): ErrorMode
    {
        return ErrorMode::create(array_merge([
            'team_id' => $teamId,
            'slug' => Str::slug($name),
            'name' => $name,
            'lever' => ErrorModeLever::Unassigned->value,
            'status' => 'open',
            'occurrence_count' => 3,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'example_trace_ids' => [],
        ], $attributes));
    }

    public function test_lists_error_modes_for_current_team(): void
    {
        $this->makeMode($this->team->id, 'Retrieval miss on long docs');

        Livewire::test(ErrorModeCatalogPage::class)
            ->assertSee('Retrieval miss on long docs');
    }

    public function test_does_not_show_other_teams_error_modes(): void
    {
        $this->makeMode($this->team->id, 'Mine only error mode');

        $otherTeam = Team::factory()->create();
        $this->makeMode($otherTeam->id, 'Their secret error mode');

        Livewire::test(ErrorModeCatalogPage::class)
            ->assertSee('Mine only error mode')
            ->assertDontSee('Their secret error mode');
    }

    public function test_lever_filter_narrows_results(): void
    {
        $this->makeMode($this->team->id, 'Prompt lever mode', ['lever' => ErrorModeLever::Prompt->value]);
        $this->makeMode($this->team->id, 'Retrieval lever mode', ['lever' => ErrorModeLever::Retrieval->value]);

        Livewire::test(ErrorModeCatalogPage::class)
            ->set('leverFilter', ErrorModeLever::Prompt->value)
            ->assertSee('Prompt lever mode')
            ->assertDontSee('Retrieval lever mode');
    }

    public function test_owner_can_assign_lever(): void
    {
        $mode = $this->makeMode($this->team->id, 'Assignable mode');

        Livewire::test(ErrorModeCatalogPage::class)
            ->call('assignLever', $mode->id, ErrorModeLever::Guardrails->value);

        $this->assertSame(ErrorModeLever::Guardrails, $mode->fresh()->lever);
    }

    public function test_viewer_cannot_assign_lever(): void
    {
        // base 'edit-content' gate is permissive; deny it to prove assignLever
        // authorizes on the action (cloud gates it by role).
        \Illuminate\Support\Facades\Gate::define('edit-content', fn () => false);

        $mode = $this->makeMode($this->team->id, 'Protected mode');

        Livewire::test(ErrorModeCatalogPage::class)
            ->call('assignLever', $mode->id, ErrorModeLever::Guardrails->value)
            ->assertForbidden();

        $this->assertSame(ErrorModeLever::Unassigned, $mode->fresh()->lever);
    }
}
