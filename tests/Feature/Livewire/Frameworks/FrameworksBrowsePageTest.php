<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Frameworks;

use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Enums\Framework;
use App\Domain\Skill\Models\Skill;
use App\Livewire\Frameworks\FrameworksBrowsePage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

final class FrameworksBrowsePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_all_twenty_frameworks_by_default(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->users()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user);

        Livewire::test(FrameworksBrowsePage::class)
            ->assertStatus(200)
            ->assertSee('RICE Scoring')
            ->assertSee('SPIN Selling')
            ->assertSee('OKRs')
            ->assertSee('Unit Economics');
    }

    public function test_category_filter_narrows_to_single_category(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->users()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user);

        Livewire::test(FrameworksBrowsePage::class, ['category' => 'sales'])
            ->assertSee('SPIN Selling')
            ->assertSee('BANT')
            ->assertSee('MEDDIC')
            ->assertDontSee('RICE Scoring');
    }

    public function test_skill_counts_are_team_scoped(): void
    {
        Cache::flush();

        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();

        $userA = User::factory()->create(['current_team_id' => $teamA->id]);
        $teamA->users()->attach($userA->id, ['role' => 'owner']);

        Skill::factory()->count(2)->create(['team_id' => $teamA->id, 'framework' => Framework::RICE]);
        Skill::factory()->count(5)->create(['team_id' => $teamB->id, 'framework' => Framework::RICE]);

        $this->actingAs($userA);

        $response = Livewire::test(FrameworksBrowsePage::class);

        // User A should see "2 skills" for RICE, not 7.
        $response->assertSeeInOrder(['RICE Scoring', '2 skills']);
    }

    public function test_invalid_category_falls_back_to_all(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->users()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user);

        Livewire::test(FrameworksBrowsePage::class)
            ->set('category', 'nonsense')
            ->assertSee('RICE Scoring')
            ->assertSee('SPIN Selling');
    }
}
