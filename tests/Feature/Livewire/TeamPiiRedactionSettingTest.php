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

class TeamPiiRedactionSettingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $settings
     */
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

    private function pageClass(): string
    {
        return class_exists(CloudTeamSettingsPage::class) ? CloudTeamSettingsPage::class : TeamSettingsPage::class;
    }

    public function test_page_renders_unchecked_checkbox_when_key_missing(): void
    {
        $this->loggedInOwner(['chatbot_enabled' => true]);

        Livewire::test($this->pageClass())
            ->assertSee('Mask personal data before sending to cloud AI providers')
            ->assertSet('piiRedactionEnabled', false);
    }

    public function test_owner_can_enable_and_disable_pii_redaction(): void
    {
        $user = $this->loggedInOwner(['chatbot_enabled' => true]);

        Livewire::test($this->pageClass())
            ->set('piiRedactionEnabled', true)
            ->call('savePrivacySettings')
            ->assertHasNoErrors();

        $settings = $user->currentTeam->fresh()->settings;
        $this->assertTrue($settings['pii_redaction_enabled']);
        $this->assertTrue($settings['chatbot_enabled'], 'other settings are kept');

        Livewire::test($this->pageClass())
            ->assertSet('piiRedactionEnabled', true)
            ->set('piiRedactionEnabled', false)
            ->call('savePrivacySettings');

        $this->assertFalse($user->currentTeam->fresh()->settings['pii_redaction_enabled']);
    }
}
