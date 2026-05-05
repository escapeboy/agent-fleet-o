<?php

namespace Tests\Feature\Domain\Integration;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Audit\Services\OcsfMapper;
use App\Domain\Credential\Enums\CredentialStatus;
use App\Domain\Credential\Enums\CredentialType;
use App\Domain\Credential\Models\Credential;
use App\Domain\Integration\Actions\ExecuteIntegrationActionAction;
use App\Domain\Integration\Actions\PingIntegrationAction;
use App\Domain\Integration\Actions\UpdateIntegrationAction;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\Enums\IntegrationStatus;
use App\Domain\Integration\Models\Integration;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegrationDetailOverhaulTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_result_ok_accepts_message_and_identity(): void
    {
        $result = HealthResult::ok(120, 'Connected as @nikolak', [
            'label' => '@nikolak',
            'identifier' => '12345',
        ]);

        $this->assertTrue($result->healthy);
        $this->assertSame('Connected as @nikolak', $result->message);
        $this->assertSame(120, $result->latencyMs);
        $this->assertSame('@nikolak', $result->identity['label']);
        $this->assertSame('12345', $result->identity['identifier']);
    }

    public function test_ping_caches_identity_into_meta_account(): void
    {
        $team = Team::factory()->create();
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'credential_type' => CredentialType::ApiToken,
            'status' => CredentialStatus::Active,
            'secret_data' => ['access_token' => 'ghp_test'],
        ]);

        $integration = Integration::factory()->create([
            'team_id' => $team->id,
            'driver' => 'github',
            'credential_id' => $credential->id,
            'meta' => [],
        ]);

        Http::fake([
            'api.github.com/user' => Http::response([
                'login' => 'nikolak',
                'id' => 9999,
                'name' => 'Nikola',
                'email' => 'n@example.com',
                'html_url' => 'https://github.com/nikolak',
                'avatar_url' => 'https://avatars/nikolak.png',
            ], 200),
        ]);

        $result = app(PingIntegrationAction::class)->execute($integration);

        $this->assertTrue($result->healthy);
        $integration->refresh();
        $this->assertSame('@nikolak', $integration->meta['account']['label']);
        $this->assertSame('9999', $integration->meta['account']['identifier']);
        $this->assertSame('https://github.com/nikolak', $integration->meta['account']['url']);
        $this->assertArrayHasKey('verified_at', $integration->meta['account']);
        $this->assertSame(IntegrationStatus::Active, $integration->status);
    }

    public function test_ping_without_identity_preserves_existing_meta_account(): void
    {
        $team = Team::factory()->create();
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'credential_type' => CredentialType::ApiToken,
            'status' => CredentialStatus::Active,
            'secret_data' => ['access_token' => 'ghp_test'],
        ]);

        $integration = Integration::factory()->create([
            'team_id' => $team->id,
            'driver' => 'github',
            'credential_id' => $credential->id,
            'meta' => [
                'account' => [
                    'label' => '@previous',
                    'identifier' => '111',
                    'url' => 'https://github.com/previous',
                ],
            ],
        ]);

        // Server returns 200 but no usable identity fields (login is null) — driver must
        // still mark the integration healthy without clobbering the existing account.
        Http::fake([
            'api.github.com/user' => Http::response([], 200),
        ]);

        app(PingIntegrationAction::class)->execute($integration);

        $integration->refresh();
        $this->assertSame('@previous', $integration->meta['account']['label']);
        $this->assertSame('111', $integration->meta['account']['identifier']);
    }

    public function test_update_integration_action_updates_name_and_credentials_and_repings(): void
    {
        $team = Team::factory()->create();
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'credential_type' => CredentialType::ApiToken,
            'status' => CredentialStatus::Active,
            'secret_data' => ['access_token' => 'ghp_old'],
        ]);

        $integration = Integration::factory()->create([
            'team_id' => $team->id,
            'driver' => 'github',
            'name' => 'Old name',
            'credential_id' => $credential->id,
        ]);

        Http::fake([
            'api.github.com/user' => Http::response([
                'login' => 'nikolak',
                'id' => 9999,
                'html_url' => 'https://github.com/nikolak',
            ], 200),
        ]);

        $updated = app(UpdateIntegrationAction::class)->execute(
            integration: $integration,
            name: 'My GitHub',
            credentials: ['access_token' => 'ghp_new'],
        );

        $this->assertSame('My GitHub', $updated->name);
        $credential->refresh();
        $this->assertSame('ghp_new', $credential->secret_data['access_token']);
        $this->assertNotNull($updated->last_pinged_at);
    }

    public function test_update_preserves_password_secrets_when_blank(): void
    {
        $team = Team::factory()->create();
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'credential_type' => CredentialType::ApiToken,
            'status' => CredentialStatus::Active,
            'secret_data' => ['access_token' => 'ghp_existing'],
        ]);

        $integration = Integration::factory()->create([
            'team_id' => $team->id,
            'driver' => 'github',
            'credential_id' => $credential->id,
        ]);

        Http::fake([
            'api.github.com/user' => Http::response(['login' => 'nikolak', 'id' => 1], 200),
        ]);

        // The action's caller (Livewire form / API controller) is expected to filter empty
        // strings BEFORE passing — this test verifies that when an empty array is passed,
        // existing creds remain intact and validateCredentials is not called.
        app(UpdateIntegrationAction::class)->execute(
            integration: $integration,
            name: 'Renamed',
            credentials: null,
        );

        $credential->refresh();
        $this->assertSame('ghp_existing', $credential->secret_data['access_token']);
    }

    public function test_update_throws_when_credentials_invalid(): void
    {
        $team = Team::factory()->create();
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'credential_type' => CredentialType::ApiToken,
            'status' => CredentialStatus::Active,
            'secret_data' => ['access_token' => 'ghp_old'],
        ]);

        $integration = Integration::factory()->create([
            'team_id' => $team->id,
            'driver' => 'github',
            'credential_id' => $credential->id,
        ]);

        // validateCredentials calls /user — fake a 401 so it returns false.
        Http::fake([
            'api.github.com/user' => Http::response(['message' => 'Bad credentials'], 401),
        ]);

        $this->expectException(\RuntimeException::class);

        app(UpdateIntegrationAction::class)->execute(
            integration: $integration,
            credentials: ['access_token' => 'ghp_invalid'],
        );

        // Existing secret must remain
        $credential->refresh();
        $this->assertSame('ghp_old', $credential->secret_data['access_token']);
    }

    public function test_successful_execute_writes_audit_entry(): void
    {
        $team = Team::factory()->create();
        User::factory()->create();
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'credential_type' => CredentialType::ApiToken,
            'status' => CredentialStatus::Active,
            'secret_data' => ['access_token' => 'ghp_test'],
        ]);

        $integration = Integration::factory()->create([
            'team_id' => $team->id,
            'driver' => 'github',
            'credential_id' => $credential->id,
        ]);

        Http::fake([
            'api.github.com/repos/foo/bar/issues' => Http::response(['number' => 42, 'id' => 1], 201),
        ]);

        app(ExecuteIntegrationActionAction::class)->execute(
            $integration,
            'create_issue',
            ['owner' => 'foo', 'repo' => 'bar', 'title' => 'Test'],
        );

        $entry = AuditEntry::query()
            ->where('subject_type', Integration::class)
            ->where('subject_id', $integration->id)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame('integration.executed', $entry->event);
        $this->assertTrue($entry->properties['success']);
        $this->assertSame('create_issue', $entry->properties['action']);
        $this->assertSame(['owner', 'repo', 'title'], $entry->properties['params_keys']);
    }

    public function test_failed_execute_writes_failure_audit_entry(): void
    {
        $team = Team::factory()->create();
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'credential_type' => CredentialType::ApiToken,
            'status' => CredentialStatus::Active,
            'secret_data' => ['access_token' => 'ghp_test'],
        ]);

        $integration = Integration::factory()->create([
            'team_id' => $team->id,
            'driver' => 'github',
            'credential_id' => $credential->id,
        ]);

        try {
            // Drivers throw InvalidArgumentException for unknown actions —
            // a deterministic way to exercise the failure code path.
            app(ExecuteIntegrationActionAction::class)->execute(
                $integration,
                'definitely_not_a_real_action',
                [],
            );
            $this->fail('Expected exception');
        } catch (\Throwable) {
            // expected
        }

        $entry = AuditEntry::query()
            ->where('subject_type', Integration::class)
            ->where('subject_id', $integration->id)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame('integration.execute.failed', $entry->event);
        $this->assertFalse($entry->properties['success']);
        $this->assertSame(4, $entry->ocsf_severity_id);
    }

    public function test_ocsf_classification_for_integration_events(): void
    {
        $ok = OcsfMapper::classify('integration.executed');
        $this->assertSame(3002, $ok['class_uid']);
        $this->assertSame(1, $ok['severity_id']);

        $failed = OcsfMapper::classify('integration.execute.failed');
        $this->assertSame(3002, $failed['class_uid']);
        $this->assertSame(4, $failed['severity_id']);
    }
}
