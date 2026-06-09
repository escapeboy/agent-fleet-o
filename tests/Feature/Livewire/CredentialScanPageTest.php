<?php

namespace Tests\Feature\Livewire;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Shared\Enums\TeamRole;
use App\Domain\Shared\Models\Team;
use App\Livewire\Credentials\CredentialScanPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class CredentialScanPageTest extends TestCase
{
    use RefreshDatabase;

    private function loggedInOwner(): User
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'Scan',
            'slug' => 'scan-'.uniqid(),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $team->users()->attach($user, ['role' => TeamRole::Owner->value]);
        $user->update(['current_team_id' => $team->id]);
        $this->actingAs($user);

        return $user;
    }

    private function finding(string $teamId, array $overrides = []): AuditEntry
    {
        return AuditEntry::withoutGlobalScopes()->create(array_merge([
            'team_id' => $teamId,
            'user_id' => null,
            'event' => 'secret_detected',
            'ocsf_class_uid' => 6003,
            'ocsf_severity_id' => 3,
            'subject_type' => 'agent',
            'subject_id' => (string) Str::uuid(),
            'properties' => [
                'pattern_id' => 'OPENAI_KEY',
                'pattern_name' => 'OpenAI API key',
                'field' => 'goal',
                'content_hash' => sha1('x'),
            ],
            'triggered_by' => 'secret_scanner',
            'created_at' => now(),
        ], $overrides));
    }

    public function test_route_renders_for_authed_user(): void
    {
        $this->loggedInOwner();
        $this->get('/credentials/scan')->assertStatus(200);
    }

    public function test_lists_only_findings_for_current_team(): void
    {
        $user = $this->loggedInOwner();

        $mine = $this->finding($user->current_team_id, [
            'properties' => ['pattern_id' => 'MINE_KEY', 'pattern_name' => 'My Finding', 'field' => 'goal'],
        ]);

        $otherTeamId = (string) Str::uuid();
        $theirs = $this->finding($otherTeamId, [
            'properties' => ['pattern_id' => 'THEIR_KEY', 'pattern_name' => 'Their Finding', 'field' => 'goal'],
        ]);

        Livewire::test(CredentialScanPage::class)
            ->assertSee('My Finding')
            ->assertDontSee('Their Finding');
    }

    public function test_acknowledged_findings_hidden_by_default_and_shown_when_toggled(): void
    {
        $user = $this->loggedInOwner();

        $this->finding($user->current_team_id, [
            'properties' => [
                'pattern_id' => 'ACK_KEY',
                'pattern_name' => 'Acked Finding',
                'field' => 'goal',
                'acknowledged_at' => now()->toIso8601String(),
            ],
        ]);

        Livewire::test(CredentialScanPage::class)
            ->assertDontSee('Acked Finding')
            ->set('showAcknowledged', true)
            ->assertSee('Acked Finding');
    }

    public function test_acknowledge_stamps_properties(): void
    {
        $user = $this->loggedInOwner();
        $finding = $this->finding($user->current_team_id);

        Livewire::test(CredentialScanPage::class)
            ->call('acknowledge', $finding->id)
            ->assertDispatched('scan-finding-acknowledged');

        $finding->refresh();
        $this->assertNotEmpty($finding->properties['acknowledged_at']);
        $this->assertSame($user->id, $finding->properties['acknowledged_by']);
    }

    public function test_rescan_is_forbidden_when_edit_content_denied(): void
    {
        $user = $this->loggedInOwner();
        $finding = $this->finding($user->current_team_id);

        Gate::define('edit-content', fn () => false);

        Livewire::test(CredentialScanPage::class)
            ->call('rescan', $finding->id)
            ->assertForbidden();
    }

    public function test_acknowledge_is_forbidden_when_edit_content_denied(): void
    {
        $user = $this->loggedInOwner();
        $finding = $this->finding($user->current_team_id);

        Gate::define('edit-content', fn () => false);

        Livewire::test(CredentialScanPage::class)
            ->call('acknowledge', $finding->id)
            ->assertForbidden();
    }
}
