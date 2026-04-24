<?php

namespace Tests\Feature\Livewire;

use App\Domain\Shared\Enums\TeamRole;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\Telemetry\TenantTracerProviderFactory;
use App\Livewire\Teams\TeamSettingsPage;
// Cloud extends base; use cloud when present so the rendered view resolves joinRequests.
use Cloud\Livewire\Teams\CloudTeamSettingsPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Tests\TestCase;

class TeamObservabilitySettingsTest extends TestCase
{
    use RefreshDatabase;

    private function loggedInOwner(): User
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'ObservTeam',
            'slug' => 'observ-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $team->users()->attach($user, ['role' => TeamRole::Owner->value]);
        $user->update(['current_team_id' => $team->id]);
        $this->actingAs($user);

        return $user;
    }

    public function test_owner_can_enable_observability_with_token(): void
    {
        $user = $this->loggedInOwner();

        Livewire::test(class_exists(CloudTeamSettingsPage::class) ? CloudTeamSettingsPage::class : TeamSettingsPage::class)
            ->set('observabilityEnabled', true)
            ->set('observabilityEndpoint', 'https://logfire-api.pydantic.dev')
            ->set('observabilityToken', 'secret-token-xyz')
            ->set('observabilitySampleRate', 0.5)
            ->set('observabilityServiceName', 'team-alpha')
            ->call('saveObservability')
            ->assertHasNoErrors();

        $team = $user->currentTeam->fresh();
        $observ = $team->settings['observability'];
        $this->assertTrue($observ['enabled']);
        $this->assertSame('https://logfire-api.pydantic.dev', $observ['endpoint']);
        $this->assertSame(0.5, $observ['sample_rate']);
        $this->assertSame('team-alpha', $observ['service_name']);
        $this->assertNotEmpty($observ['otlp_token_encrypted']);
        $this->assertSame('secret-token-xyz', Crypt::decryptString($observ['otlp_token_encrypted']));
    }

    public function test_blank_token_preserves_existing_encrypted_value(): void
    {
        $user = $this->loggedInOwner();
        $team = $user->currentTeam;
        $existingCt = Crypt::encryptString('existing-token');
        $team->update(['settings' => ['observability' => [
            'enabled' => true,
            'endpoint' => 'https://a.example',
            'otlp_token_encrypted' => $existingCt,
        ]]]);

        Livewire::test(class_exists(CloudTeamSettingsPage::class) ? CloudTeamSettingsPage::class : TeamSettingsPage::class)
            ->set('observabilityEndpoint', 'https://b.example')
            ->set('observabilityToken', '') // blank: keep existing
            ->call('saveObservability')
            ->assertHasNoErrors();

        $fresh = $team->fresh()->settings['observability'];
        $this->assertSame($existingCt, $fresh['otlp_token_encrypted']);
        $this->assertSame('https://b.example', $fresh['endpoint']);
    }

    public function test_clear_observability_token_removes_secret_only(): void
    {
        $user = $this->loggedInOwner();
        $team = $user->currentTeam;
        $team->update(['settings' => ['observability' => [
            'enabled' => true,
            'endpoint' => 'https://x.example',
            'otlp_token_encrypted' => Crypt::encryptString('old'),
            'sample_rate' => 0.3,
        ]]]);

        Livewire::test(class_exists(CloudTeamSettingsPage::class) ? CloudTeamSettingsPage::class : TeamSettingsPage::class)
            ->call('clearObservabilityToken')
            ->assertHasNoErrors()
            ->assertSet('observabilityTokenIsSet', false);

        $fresh = $team->fresh()->settings['observability'];
        $this->assertSame('', $fresh['otlp_token_encrypted']);
        $this->assertSame('https://x.example', $fresh['endpoint']);
        $this->assertSame(0.3, $fresh['sample_rate']);
    }

    public function test_invalid_endpoint_url_is_rejected(): void
    {
        $this->loggedInOwner();

        Livewire::test(class_exists(CloudTeamSettingsPage::class) ? CloudTeamSettingsPage::class : TeamSettingsPage::class)
            ->set('observabilityEnabled', true)
            ->set('observabilityEndpoint', 'not a url')
            ->call('saveObservability')
            ->assertHasErrors(['observabilityEndpoint']);
    }

    public function test_sample_rate_out_of_range_rejected(): void
    {
        $this->loggedInOwner();

        Livewire::test(class_exists(CloudTeamSettingsPage::class) ? CloudTeamSettingsPage::class : TeamSettingsPage::class)
            ->set('observabilityEnabled', true)
            ->set('observabilityEndpoint', 'https://a.example')
            ->set('observabilitySampleRate', 2.0)
            ->call('saveObservability')
            ->assertHasErrors(['observabilitySampleRate']);
    }

    public function test_save_invalidates_factory_cache(): void
    {
        $user = $this->loggedInOwner();
        $factory = app(TenantTracerProviderFactory::class);
        // Warm the cache.
        $factory->forTeam($user->current_team_id);

        Livewire::test(class_exists(CloudTeamSettingsPage::class) ? CloudTeamSettingsPage::class : TeamSettingsPage::class)
            ->set('observabilityEnabled', true)
            ->set('observabilityEndpoint', 'https://new.example')
            ->call('saveObservability')
            ->assertHasNoErrors();

        // After save the factory should re-resolve — fetch now returns a provider with overrides.
        $provider = $factory->forTeam($user->current_team_id);
        $ref = new \ReflectionClass($provider);
        $prop = $ref->getProperty('overrides');
        $prop->setAccessible(true);
        $this->assertSame('https://new.example', $prop->getValue($provider)['endpoint']);
    }
}
