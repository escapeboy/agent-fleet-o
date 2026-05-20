<?php

namespace Tests\Feature\Domain\Credential;

use App\Domain\Credential\Actions\LogCredentialAccessAction;
use App\Domain\Credential\Enums\CredentialStatus;
use App\Domain\Credential\Enums\CredentialType;
use App\Domain\Credential\Models\Credential;
use App\Domain\Credential\Models\CredentialAccessLog;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CredentialDomainAllowlistTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Credential $credential;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);

        $this->credential = Credential::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'name' => 'Test API Key',
            'slug' => 'test-api-key',
            'credential_type' => CredentialType::ApiToken,
            'status' => CredentialStatus::Active,
            'secret_data' => ['token' => 'secret'],
        ]);
    }

    // ─── isDomainAllowed ──────────────────────────────────────────────────────

    public function test_empty_allowlist_permits_any_domain(): void
    {
        $this->credential->update(['allowed_domains' => null]);

        $this->assertTrue($this->credential->isDomainAllowed('evil.example.com'));
    }

    public function test_exact_domain_match_is_allowed(): void
    {
        $this->credential->update(['allowed_domains' => ['api.example.com']]);

        $this->assertTrue($this->credential->isDomainAllowed('api.example.com'));
    }

    public function test_non_matching_domain_is_denied(): void
    {
        $this->credential->update(['allowed_domains' => ['api.example.com']]);

        $this->assertFalse($this->credential->isDomainAllowed('evil.com'));
    }

    public function test_wildcard_matches_subdomain_but_not_unrelated_domain(): void
    {
        $this->credential->update(['allowed_domains' => ['*.example.com']]);

        $this->assertTrue($this->credential->isDomainAllowed('api.example.com'));
        $this->assertTrue($this->credential->isDomainAllowed('example.com'));
        $this->assertFalse($this->credential->isDomainAllowed('evil.com'));
        $this->assertFalse($this->credential->isDomainAllowed('notexample.com'));
    }

    public function test_bare_hostname_with_port_is_matched_correctly(): void
    {
        $this->credential->update(['allowed_domains' => ['api.example.com']]);

        // Bare hostname with port must strip the port before matching
        $this->assertTrue($this->credential->isDomainAllowed('api.example.com:8443'));
    }

    public function test_full_url_input_extracts_host_for_matching(): void
    {
        $this->credential->update(['allowed_domains' => ['api.example.com']]);

        $this->assertTrue($this->credential->isDomainAllowed('https://api.example.com/v2/endpoint'));
        $this->assertFalse($this->credential->isDomainAllowed('https://evil.com/api'));
    }

    // ─── LogCredentialAccessAction ────────────────────────────────────────────

    public function test_access_log_records_allowed_access(): void
    {
        $this->credential->update(['allowed_domains' => ['api.example.com']]);

        app(LogCredentialAccessAction::class)->execute(
            credential: $this->credential,
            resolvedFor: 'project-123',
            targetDomain: 'api.example.com',
        );

        $log = CredentialAccessLog::withoutGlobalScopes()
            ->where('credential_id', $this->credential->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertTrue($log->allowed);
        $this->assertEquals('api.example.com', $log->target_domain);
    }

    public function test_access_log_records_denied_access(): void
    {
        $this->credential->update(['allowed_domains' => ['api.example.com']]);

        $allowed = $this->credential->isDomainAllowed('evil.com');

        app(LogCredentialAccessAction::class)->execute(
            credential: $this->credential,
            resolvedFor: 'project-123',
            targetDomain: 'evil.com',
            allowed: $allowed,
        );

        $log = CredentialAccessLog::withoutGlobalScopes()
            ->where('credential_id', $this->credential->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertFalse($log->allowed);
    }
}
