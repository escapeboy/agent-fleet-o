<?php

namespace Tests\Feature\Livewire;

use App\Domain\Shared\Enums\TeamRole;
use App\Domain\Shared\Models\Team;
use App\Livewire\Teams\TeamSettingsPage;
// Cloud extends base; use cloud when present so the rendered view resolves joinRequests.
use App\Models\User;
use Cloud\Livewire\Teams\CloudTeamSettingsPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TeamPiiRedactionSettingTest extends TestCase
{
    use RefreshDatabase;

    private function loggedInOwner(array $settings = []): User
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'PiiTeam',
            'slug' => 'pii-team',
            'owner_id' => $user->id,
            'settings' => $settings,
        ]);
        $team->users()->attach($user, ['role' => TeamRole::Owner->value]);
        $user->update(['current_team_id' => $team->id]);
        $this->actingAs($user);

        return $user;
    }

    private function settingsPageClass(): string
    {
        return class_exists(CloudTeamSettingsPage::class) ? CloudTeamSettingsPage::class : TeamSettingsPage::class;
    }

    public function test_renders_checkbox_unchecked_by_default(): void
    {
        $this->loggedInOwner();

        Livewire::test($this->settingsPageClass())
            ->assertSee('Mask personal data before sending to cloud AI providers')
            ->assertSet('piiRedactionEnabled', false);
    }

    public function test_loads_existing_setting(): void
    {
        $this->loggedInOwner(['pii_redaction_enabled' => true]);

        Livewire::test($this->settingsPageClass())
            ->assertSet('piiRedactionEnabled', true);
    }

    public function test_owner_can_enable_and_other_settings_are_kept(): void
    {
        $user = $this->loggedInOwner(['format_guide' => 'Keep me']);

        Livewire::test($this->settingsPageClass())
            ->set('piiRedactionEnabled', true)
            ->call('savePrivacySettings')
            ->assertHasNoErrors();

        $settings = $user->currentTeam->fresh()->settings;
        $this->assertTrue($settings['pii_redaction_enabled']);
        $this->assertSame('Keep me', $settings['format_guide']);
    }

    public function test_owner_can_disable_again(): void
    {
        $user = $this->loggedInOwner(['pii_redaction_enabled' => true]);

        Livewire::test($this->settingsPageClass())
            ->set('piiRedactionEnabled', false)
            ->call('savePrivacySettings')
            ->assertHasNoErrors();

        $this->assertFalse($user->currentTeam->fresh()->settings['pii_redaction_enabled']);
    }
}
