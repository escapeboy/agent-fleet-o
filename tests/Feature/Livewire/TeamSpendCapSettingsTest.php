<?php

namespace Tests\Feature\Livewire;

use App\Domain\Shared\Enums\TeamRole;
use App\Domain\Shared\Models\Team;
use App\Livewire\Teams\TeamSettingsPage;
// Cloud extends base; use cloud when present so the rendered view resolves.
use App\Models\User;
use Cloud\Livewire\Teams\CloudTeamSettingsPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TeamSpendCapSettingsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $settings
     */
    private function loggedInOwner(array $settings = []): User
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'SpendCapTeam',
            'slug' => 'spend-cap-team',
            'owner_id' => $user->id,
            'settings' => $settings,
        ]);
        $team->users()->attach($user, ['role' => TeamRole::Owner->value]);
        $user->update(['current_team_id' => $team->id]);
        $this->actingAs($user);

        return $user;
    }

    private function pageClass(): string
    {
        return class_exists(CloudTeamSettingsPage::class) ? CloudTeamSettingsPage::class : TeamSettingsPage::class;
    }

    public function test_owner_can_save_daily_and_monthly_limits(): void
    {
        $user = $this->loggedInOwner();

        Livewire::test($this->pageClass())
            ->set('teamSpendCapDaily', '500')
            ->set('teamSpendCapMonthly', '10000')
            ->call('saveTeamSpendCap')
            ->assertHasNoErrors();

        $settings = $user->currentTeam->fresh()->settings;
        $this->assertSame(500, $settings['spend_cap_daily_credits']);
        $this->assertSame(10000, $settings['spend_cap_monthly_credits']);
    }

    public function test_empty_values_clear_the_limits(): void
    {
        $user = $this->loggedInOwner([
            'spend_cap_daily_credits' => 500,
            'spend_cap_monthly_credits' => 10000,
        ]);

        Livewire::test($this->pageClass())
            ->assertSet('teamSpendCapDaily', '500')
            ->assertSet('teamSpendCapMonthly', '10000')
            ->set('teamSpendCapDaily', '')
            ->set('teamSpendCapMonthly', '  ')
            ->call('saveTeamSpendCap')
            ->assertHasNoErrors();

        $settings = $user->currentTeam->fresh()->settings;
        $this->assertNull($settings['spend_cap_daily_credits']);
        $this->assertNull($settings['spend_cap_monthly_credits']);
    }

    public function test_owner_can_allow_unpriced_models(): void
    {
        $user = $this->loggedInOwner();

        Livewire::test($this->pageClass())
            ->assertSet('teamSpendCapAllowUnpriced', false)
            ->set('teamSpendCapAllowUnpriced', true)
            ->call('saveTeamSpendCap')
            ->assertHasNoErrors();

        $settings = $user->currentTeam->fresh()->settings;
        $this->assertTrue($settings['spend_cap_allow_unpriced_models']);

        Livewire::test($this->pageClass())
            ->assertSet('teamSpendCapAllowUnpriced', true);
    }

    public function test_invalid_values_are_rejected(): void
    {
        $user = $this->loggedInOwner();

        Livewire::test($this->pageClass())
            ->set('teamSpendCapDaily', '-5')
            ->call('saveTeamSpendCap')
            ->assertHasErrors('teamSpendCapDaily');

        Livewire::test($this->pageClass())
            ->set('teamSpendCapMonthly', 'abc')
            ->call('saveTeamSpendCap')
            ->assertHasErrors('teamSpendCapMonthly');

        Livewire::test($this->pageClass())
            ->set('teamSpendCapDaily', '10000001')
            ->call('saveTeamSpendCap')
            ->assertHasErrors('teamSpendCapDaily');

        $settings = $user->currentTeam->fresh()->settings ?? [];
        $this->assertArrayNotHasKey('spend_cap_daily_credits', $settings);
        $this->assertArrayNotHasKey('spend_cap_monthly_credits', $settings);
    }
}
