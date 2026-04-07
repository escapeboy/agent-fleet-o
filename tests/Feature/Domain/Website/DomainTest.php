<?php

namespace Tests\Feature\Domain\Website;

use App\Domain\Credential\Enums\CredentialStatus;
use App\Domain\Credential\Enums\CredentialType;
use App\Domain\Credential\Models\Credential;
use App\Domain\Shared\Models\Team;
use App\Domain\Website\Actions\Domain\CheckDomainAvailabilityAction;
use App\Domain\Website\Actions\Domain\ConfigureDnsAction;
use App\Domain\Website\Actions\Domain\PurchaseDomainAction;
use App\Domain\Website\Models\Website;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class DomainTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Website $website;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team-domain',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($user, ['role' => 'owner']);

        $this->website = Website::create([
            'team_id' => $this->team->id,
            'name' => 'Test Website',
            'slug' => 'test-website-domain',
            'status' => 'draft',
            'settings' => [],
        ]);
    }

    private function createNamecheapCredential(array $secretOverrides = []): Credential
    {
        return Credential::create([
            'team_id' => $this->team->id,
            'name' => 'namecheap',
            'slug' => 'namecheap',
            'credential_type' => CredentialType::CustomKeyValue,
            'status' => CredentialStatus::Active,
            'secret_data' => array_merge([
                'api_key' => 'test-api-key',
                'api_user' => 'testuser',
                'username' => 'testuser',
                'client_ip' => '127.0.0.1',
                'sandbox' => true,
            ], $secretOverrides),
            'metadata' => [],
        ]);
    }

    public function test_check_domain_returns_availability(): void
    {
        $this->createNamecheapCredential();

        Http::fake([
            'api.sandbox.namecheap.com/*' => Http::response(
                '<?xml version="1.0" encoding="utf-8"?>
                <ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response">
                    <CommandResponse Type="namecheap.domains.check">
                        <DomainCheckResult Domain="example.com" Available="true" />
                    </CommandResponse>
                </ApiResponse>',
                200,
            ),
        ]);

        $result = (new CheckDomainAvailabilityAction)->execute($this->team, 'example.com');

        $this->assertEquals('example.com', $result['domain']);
        $this->assertTrue($result['available']);
    }

    public function test_check_domain_returns_unavailable(): void
    {
        $this->createNamecheapCredential();

        Http::fake([
            'api.sandbox.namecheap.com/*' => Http::response(
                '<?xml version="1.0" encoding="utf-8"?>
                <ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response">
                    <CommandResponse Type="namecheap.domains.check">
                        <DomainCheckResult Domain="taken.com" Available="false" />
                    </CommandResponse>
                </ApiResponse>',
                200,
            ),
        ]);

        $result = (new CheckDomainAvailabilityAction)->execute($this->team, 'taken.com');

        $this->assertFalse($result['available']);
    }

    public function test_purchase_requires_namecheap_credential(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No Namecheap credential found');

        (new PurchaseDomainAction)->execute($this->team, $this->website, 'example.com', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'address1' => '123 Main St',
            'city' => 'New York',
            'state_province' => 'NY',
            'postal_code' => '10001',
            'country' => 'US',
            'phone' => '+1.2125551234',
            'email_address' => 'john@example.com',
            'years' => 1,
        ]);
    }

    public function test_purchase_domain_success_updates_website(): void
    {
        $this->createNamecheapCredential();

        Http::fake([
            'api.sandbox.namecheap.com/*' => Http::response(
                '<?xml version="1.0" encoding="utf-8"?>
                <ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response">
                    <CommandResponse Type="namecheap.domains.create">
                        <DomainCreateResult Domain="mynewsite.com" Registered="true" TransactionID="12345" />
                    </CommandResponse>
                </ApiResponse>',
                200,
            ),
        ]);

        $result = (new PurchaseDomainAction)->execute($this->team, $this->website, 'mynewsite.com', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'address1' => '123 Main St',
            'city' => 'New York',
            'state_province' => 'NY',
            'postal_code' => '10001',
            'country' => 'US',
            'phone' => '+1.2125551234',
            'email_address' => 'john@example.com',
            'years' => 1,
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('mynewsite.com', $result['domain']);
        $this->assertEquals('mynewsite.com', $this->website->fresh()->custom_domain);
    }

    public function test_configure_dns_sets_a_records(): void
    {
        $this->createNamecheapCredential();

        $this->website->update(['custom_domain' => 'mynewsite.com']);

        Http::fake([
            'api.sandbox.namecheap.com/*' => Http::response(
                '<?xml version="1.0" encoding="utf-8"?>
                <ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response">
                    <CommandResponse Type="namecheap.domains.dns.setHosts">
                        <DomainDNSSetHostsResult Domain="mynewsite.com" IsSuccess="true" />
                    </CommandResponse>
                </ApiResponse>',
                200,
            ),
        ]);

        $success = (new ConfigureDnsAction)->execute($this->team, $this->website, '1.2.3.4');

        $this->assertTrue($success);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'namecheap.domains.dns.setHosts')
                && str_contains($request->url(), 'SLD=mynewsite')
                && str_contains($request->url(), 'TLD=com')
                && str_contains($request->url(), 'Address1=1.2.3.4');
        });
    }

    public function test_configure_dns_returns_false_when_no_custom_domain(): void
    {
        $this->createNamecheapCredential();

        $success = (new ConfigureDnsAction)->execute($this->team, $this->website, '1.2.3.4');

        $this->assertFalse($success);
    }
}
