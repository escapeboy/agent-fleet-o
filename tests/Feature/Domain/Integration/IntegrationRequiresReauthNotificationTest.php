<?php

namespace Tests\Feature\Domain\Integration;

use App\Domain\Credential\Enums\CredentialStatus;
use App\Domain\Credential\Enums\CredentialType;
use App\Domain\Credential\Models\Credential;
use App\Domain\Integration\Actions\RefreshIntegrationCredentialAction;
use App\Domain\Integration\Enums\IntegrationStatus;
use App\Domain\Integration\Models\Integration;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Notifications\IntegrationRequiresReauthNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class IntegrationRequiresReauthNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_is_sent_when_refresh_token_is_permanently_invalid(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);

        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'credential_type' => CredentialType::OAuth2,
            'status' => CredentialStatus::Active,
            'secret_data' => [
                'access_token' => 'old-token',
                'refresh_token' => 'invalid-refresh-token',
                'token_expires_at' => now()->subMinutes(5)->toIso8601String(),
            ],
        ]);

        $integration = Integration::factory()->create([
            'team_id' => $team->id,
            'driver' => 'github',
            'credential_id' => $credential->id,
            'status' => IntegrationStatus::Active,
        ]);

        config(['integrations.oauth_urls.github.token' => 'https://github.test/token']);
        config(['integrations.oauth.github' => ['client_id' => 'id', 'client_secret' => 'secret']]);

        Http::fake([
            'https://github.test/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        app(RefreshIntegrationCredentialAction::class)->execute($integration->refresh());

        Notification::assertSentTo($owner, IntegrationRequiresReauthNotification::class);
    }

    public function test_notification_is_not_sent_on_transient_failure(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);

        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'credential_type' => CredentialType::OAuth2,
            'status' => CredentialStatus::Active,
            'secret_data' => [
                'access_token' => 'old-token',
                'refresh_token' => 'valid-refresh-token',
                'token_expires_at' => now()->subMinutes(5)->toIso8601String(),
            ],
        ]);

        $integration = Integration::factory()->create([
            'team_id' => $team->id,
            'driver' => 'github',
            'credential_id' => $credential->id,
            'status' => IntegrationStatus::Active,
        ]);

        config(['integrations.oauth_urls.github.token' => 'https://github.test/token']);
        config(['integrations.oauth.github' => ['client_id' => 'id', 'client_secret' => 'secret']]);

        // 500 = transient, should not trigger re-auth
        Http::fake([
            'https://github.test/token' => Http::response(['error' => 'server_error'], 500),
        ]);

        app(RefreshIntegrationCredentialAction::class)->execute($integration->refresh());

        Notification::assertNothingSent();
        $this->assertSame(IntegrationStatus::Active->value, $integration->refresh()->status->value);
    }

    public function test_notification_mail_contains_integration_name_and_driver(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $integration = Integration::factory()->create([
            'team_id' => $team->id,
            'driver' => 'salesforce',
            'name' => 'My Salesforce',
        ]);

        $notification = new IntegrationRequiresReauthNotification($integration);
        $mail = $notification->toMail($owner);

        $rendered = $mail->render();

        $this->assertStringContainsString('My Salesforce', $rendered);
        $this->assertStringContainsString('salesforce', $rendered);
    }
}
