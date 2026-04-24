<?php

namespace Tests\Feature\Livewire;

use App\Domain\Shared\Enums\TeamRole;
use App\Domain\Shared\Models\Team;
use App\Livewire\Teams\TeamSettingsPage;
use App\Models\User;
use Cloud\Livewire\Teams\CloudTeamSettingsPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ObservabilityLastProbeTest extends TestCase
{
    use RefreshDatabase;

    private function loggedInOwner(): User
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'Probe',
            'slug' => 'probe-'.uniqid(),
            'owner_id' => $user->id,
            'settings' => [
                'observability' => [
                    'enabled' => true,
                    'endpoint' => 'https://example.com',
                    'otlp_token_encrypted' => Crypt::encryptString('tok'),
                ],
            ],
        ]);
        $team->users()->attach($user, ['role' => TeamRole::Owner->value]);
        $user->update(['current_team_id' => $team->id]);
        $this->actingAs($user);

        return $user;
    }

    private function componentClass(): string
    {
        return class_exists(CloudTeamSettingsPage::class) ? CloudTeamSettingsPage::class : TeamSettingsPage::class;
    }

    public function test_successful_probe_persists_ok_status(): void
    {
        $user = $this->loggedInOwner();
        Http::fake(['example.com/v1/traces' => Http::response('', 202)]);

        Livewire::test($this->componentClass())
            ->call('testObservability')
            ->assertHasNoErrors();

        $observ = $user->currentTeam->fresh()->settings['observability'];
        $this->assertNotNull($observ['last_probe_at']);
        $this->assertTrue($observ['last_probe_ok']);
        $this->assertSame('ok', $observ['last_probe_status']);
        $this->assertIsInt($observ['last_probe_latency_ms']);
        $this->assertStringContainsString('accepted', $observ['last_probe_message']);
    }

    public function test_failing_probe_persists_failure_status(): void
    {
        $user = $this->loggedInOwner();
        Http::fake(['example.com/v1/traces' => Http::response('', 401)]);

        Livewire::test($this->componentClass())
            ->call('testObservability');

        $observ = $user->currentTeam->fresh()->settings['observability'];
        $this->assertFalse($observ['last_probe_ok']);
        $this->assertSame('auth_failed', $observ['last_probe_status']);
    }

    public function test_mount_loads_persisted_probe_into_livewire_props(): void
    {
        $user = $this->loggedInOwner();
        // Seed a prior probe result on the team so mount() picks it up.
        $settings = $user->currentTeam->settings;
        $settings['observability']['last_probe_at'] = '2026-04-24T15:00:00+00:00';
        $settings['observability']['last_probe_ok'] = true;
        $settings['observability']['last_probe_status'] = 'ok';
        $settings['observability']['last_probe_message'] = 'Collector accepted (HTTP 200, 42 ms).';
        $user->currentTeam->update(['settings' => $settings]);

        $component = Livewire::test($this->componentClass());
        $this->assertSame('2026-04-24T15:00:00+00:00', $component->get('lastProbeAt'));
        $this->assertTrue($component->get('lastProbeOk'));
        $this->assertSame('ok', $component->get('lastProbeStatus'));
    }

    public function test_probe_persistence_preserves_token_and_other_observability_fields(): void
    {
        $user = $this->loggedInOwner();
        Http::fake(['example.com/v1/traces' => Http::response('', 200)]);

        Livewire::test($this->componentClass())->call('testObservability');

        $observ = $user->currentTeam->fresh()->settings['observability'];
        $this->assertTrue($observ['enabled']);
        $this->assertSame('https://example.com', $observ['endpoint']);
        $this->assertNotEmpty($observ['otlp_token_encrypted']);
        // Probe fields merged in alongside, not replacing.
        $this->assertArrayHasKey('last_probe_at', $observ);
    }
}
